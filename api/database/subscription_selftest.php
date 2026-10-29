<?php
/**
 * Abonelik görünümü öz-testi (N-63) — yerel veritabanı, TRANSACTION + ROLLBACK
 * (hiçbir satır kalıcı yazılmaz).
 *
 *   php api/database/subscription_selftest.php
 *
 * Madde 5: "Aboneliğim" alanı yoktu; paket sayfasında aktif paketin düğmesi
 * "Mevcut Paket" yazıp tıklanabilir kalıyordu. Görüntüleme, paket sayfası ve
 * (onaydan sonra) satın alma/yenileme aynı kaynaktan okumalı:
 * functions/plans.php → getUserSubscription().
 *
 * A) Ücretsiz kullanıcı · B) Aktif ücretli paket (tarih, kalan gün) ·
 * C) Süresi dolmuş paket · D) Süresiz (eski satır) · E) Ödeme geçmişi ·
 * F) getPricing aynı kaynaktan.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/plans.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $label\n"; }
    else     { $fail++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function finish(): void {
    global $pass, $fail;
    echo "\n=== SONUÇ: $pass geçti, $fail başarısız ===\n\n";
    exit($fail > 0 ? 1 : 0);
}

if (!function_exists('getUserSubscription')) {
    check('functions/plans.php: getUserSubscription', false, 'tanımlı değil');
    finish();
}

$db   = Database::getInstance();
$conn = $db->getConnection();
if (!plansTableReady($db) || !planSelectionHasExpiry($db)) {
    check('plans kataloğu ve expires_at (migration 007/011) hazır', false);
    finish();
}
$paid = $db->selectSingle('name_tr, monthly_price FROM plans WHERE monthly_price > 0 ORDER BY monthly_price LIMIT 1');
$free = $db->selectSingle('name_tr FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1');
$user = $db->selectSingle('id FROM kullanicilar ORDER BY id DESC LIMIT 1');
if (!$paid || !$free || !$user) {
    check('ücretli plan, varsayılan plan ve kullanıcı var', false);
    finish();
}
$uid = (int) $user['id'];
$set = function (?string $plan, ?string $expiresSql) use ($db, $uid): void {
    $db->execute('DELETE FROM user_plan_selection WHERE user_id = ?', [$uid]); // transaction içinde, ROLLBACK ile geri alınır
    if ($plan !== null) {
        $db->execute("INSERT INTO user_plan_selection (user_id, plan_name, selected_at, expires_at)
                      VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 5 DAY), " . ($expiresSql ?? 'NULL') . ')', [$uid, $plan]);
    }
};
// Statik önbellek testler arasında taşınmasın.
$fresh = fn () => getUserSubscription($db, $uid, true);

$conn->beginTransaction();
try {
    echo "\n=== A) Ücretsiz ===\n\n";
    $set(null, null);
    $s = $fresh();
    check('satır yok → state=free, ücretsiz plan', $s['state'] === 'free' && $s['plan'] === $free['name_tr'] && $s['is_paid'] === false, json_encode($s, JSON_UNESCAPED_UNICODE));
    check('ücretsizde bitiş/kalan gün yok', $s['expires_at'] === null && $s['days_left'] === null);

    echo "\n=== B) Aktif ücretli ===\n\n";
    $set($paid['name_tr'], 'DATE_ADD(NOW(), INTERVAL 25 DAY)');
    $s = $fresh();
    check('state=active, is_paid', $s['state'] === 'active' && $s['is_paid'] === true && $s['plan'] === $paid['name_tr'], json_encode($s, JSON_UNESCAPED_UNICODE));
    check('başlangıç ve bitiş dolu', $s['started_at'] !== null && $s['expires_at'] !== null);
    check('kalan gün 24–25', $s['days_left'] >= 24 && $s['days_left'] <= 25, (string) $s['days_left']);
    // getUserPlan istek başına önbellekli; burada bu kullanıcı için İLK çağrı.
    check('getUserPlan ile aynı plan (tek kaynak)', getUserPlanName($db, $uid) === $s['plan'], getUserPlanName($db, $uid));

    echo "\n=== C) Süresi dolmuş ===\n\n";
    $set($paid['name_tr'], 'DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $s = $fresh();
    check('state=expired, etkin plan ücretsiz', $s['state'] === 'expired' && $s['plan'] === $free['name_tr'] && $s['is_paid'] === false, json_encode($s, JSON_UNESCAPED_UNICODE));
    check('biten paketin adı ve bitiş tarihi gösterilebilir', $s['last_paid_plan'] === $paid['name_tr'] && $s['expired_at'] !== null);

    echo "\n=== D) Süresiz (expires_at NULL, eski satır) ===\n\n";
    $set($paid['name_tr'], null);
    $s = $fresh();
    check('ücretli + süresiz → active, bitiş yok', $s['state'] === 'active' && $s['is_paid'] && $s['expires_at'] === null && $s['days_left'] === null, json_encode($s, JSON_UNESCAPED_UNICODE));

    echo "\n=== E) Ödeme geçmişi ===\n\n";
    $order = 'PLN-SELFTEST' . strtoupper(bin2hex(random_bytes(2)));
    $db->execute("INSERT INTO param_marketplace_payments (order_id, user_id, status, amount, product_amount, param_receipt_id, items_json, param_response_json)
                  VALUES (?, ?, 'paid', ?, ?, ?, ?, '{}')",
        [$order, $uid, $paid['monthly_price'], $paid['monthly_price'], $order, json_encode([['plan_name' => $paid['name_tr'], 'price' => (float) $paid['monthly_price']]], JSON_UNESCAPED_UNICODE)]);
    $s = $fresh();
    $h = array_values(array_filter($s['history'], fn ($r) => $r['order_id'] === $order));
    check('ödenmiş paket siparişi geçmişte', count($h) === 1 && $h[0]['status'] === 'paid' && $h[0]['plan'] === $paid['name_tr']
        && abs($h[0]['amount'] - (float) $paid['monthly_price']) < 0.01, json_encode($h, JSON_UNESCAPED_UNICODE));
    check('geçmiş satırında kişisel/sağlayıcı ham verisi yok', !array_key_exists('param_response_json', $h[0] ?? []) && !array_key_exists('user_id', $h[0] ?? []));
    check('durum etiketi Türkçe', ($h[0]['status_label'] ?? '') === 'Ödendi');

    echo "\n=== F) Paket sayfası aynı kaynaktan ===\n\n";
    $ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/WalletController.php');
    check('getPricing getUserSubscription kullanıyor', (bool) preg_match('/function getPricing\(\)[\s\S]{0,1500}getUserSubscription\(/', $ctl));
    check('aktif ücretli paket: "Mevcut paketiniz" + bitiş alanı', str_contains($ctl, "'current_expires_at'") && str_contains($ctl, 'Mevcut paketiniz'));
    check('getMySubscription ucu var', str_contains($ctl, 'function getMySubscription') && is_file(__DIR__ . '/../api/wallet/getmysubscription.php'));

    echo "\n=== G) Paket kaynağı ve admin ataması (N-64) ===\n\n";
    if (!function_exists('getPlanSource') || !function_exists('adminAssignPlan')) {
        check('functions/plans.php: getPlanSource, adminAssignPlan', false, 'tanımlı değil');
    } else {
        $db->execute("DELETE FROM param_marketplace_payments WHERE user_id = ? AND order_id LIKE 'PLN-%'", [$uid]); // ROLLBACK ile geri alınır
        $db->execute("DELETE FROM admin_audit_log WHERE target_user_id = ? AND action = 'plan_assign'", [$uid]);

        $set($paid['name_tr'], null);
        $src = getPlanSource($db, $uid, getUserSubscription($db, $uid, true));
        check('ödemesiz, kayıtsız ücretli paket → legacy (eski sistem / elle)', $src['source'] === 'legacy', json_encode($src, JSON_UNESCAPED_UNICODE));
        check('legacy etiketi süresiz olduğunu söylüyor', str_contains($src['label'], 'süresiz'), $src['label']);

        $set($paid['name_tr'], 'DATE_ADD(NOW(), INTERVAL 30 DAY)');
        $o2 = 'PLN-SELFTESTSRC' . strtoupper(bin2hex(random_bytes(2)));
        $db->execute("INSERT INTO param_marketplace_payments (order_id, user_id, status, amount, product_amount, param_receipt_id, items_json, param_response_json, created_at)
                      VALUES (?, ?, 'paid', ?, ?, ?, ?, '{}', DATE_SUB(NOW(), INTERVAL 5 DAY))",
            [$o2, $uid, $paid['monthly_price'], $paid['monthly_price'], $o2, json_encode([['plan_name' => $paid['name_tr']]], JSON_UNESCAPED_UNICODE)]);
        $src = getPlanSource($db, $uid, getUserSubscription($db, $uid, true));
        check('eşleşen ödenmiş sipariş → purchase', $src['source'] === 'purchase' && str_contains($src['label'], 'Satın alma'), json_encode($src, JSON_UNESCAPED_UNICODE));

        $gold = $db->selectSingle('name_tr FROM plans WHERE monthly_price > ? ORDER BY monthly_price LIMIT 1', [$paid['monthly_price']]);
        $r = adminAssignPlan($db, $uid, (string) $gold['name_tr'], date('Y-m-d H:i:s', time() + 30 * 86400), ['id' => null, 'name' => 'selftest', 'ip' => '127.0.0.1']);
        $s = getUserSubscription($db, $uid, true);
        check('admin ataması: paket ve bitiş yazıldı', $r['ok'] && $s['plan'] === $gold['name_tr'] && $s['state'] === 'active', json_encode([$r, $s['plan']], JSON_UNESCAPED_UNICODE));
        $log = $db->selectSingle("admin_name, details FROM admin_audit_log WHERE target_user_id = ? AND action = 'plan_assign' ORDER BY id DESC LIMIT 1", [$uid]);
        $d = json_decode((string) ($log['details'] ?? ''), true);
        check('denetim kaydı: eski ve yeni paket', $log && $d['old_plan'] === $paid['name_tr'] && $d['new_plan'] === $gold['name_tr'] && array_key_exists('new_expires_at', $d), json_encode($log, JSON_UNESCAPED_UNICODE));
        $src = getPlanSource($db, $uid, $s);
        check('atamadan sonra kaynak → admin (kim, ne zaman)', $src['source'] === 'admin' && str_contains($src['label'], 'selftest'), json_encode($src, JSON_UNESCAPED_UNICODE));

        $bad = adminAssignPlan($db, $uid, 'Kral', null, ['id' => null, 'name' => 'selftest', 'ip' => null]);
        check('katalogda olmayan paket reddedilir, satır değişmez', !$bad['ok'] && getUserSubscription($db, $uid, true)['plan'] === $gold['name_tr']);
        $past = adminAssignPlan($db, $uid, (string) $gold['name_tr'], date('Y-m-d H:i:s', time() - 3600), ['id' => null, 'name' => 'selftest', 'ip' => null]);
        check('geçmiş bitiş tarihi reddedilir', !$past['ok']);

        $set(null, null);
        check('ücretsiz → free', getPlanSource($db, $uid, getUserSubscription($db, $uid, true))['source'] === 'free');
    }

    echo "\n=== H) Aynı paket engeli + yenileme penceresi (N-67, onaylı 2026-10-19) ===\n\n";
    if (!function_exists('planPurchaseCheck') || !defined('PLAN_RENEWAL_WINDOW_DAYS')) {
        check('functions/plans.php: planPurchaseCheck, PLAN_RENEWAL_WINDOW_DAYS', false, 'tanımlı değil');
    } else {
        $P = $paid['name_tr'];
        $s25 = ['plan' => $P, 'is_paid' => true, 'state' => 'active', 'expires_at' => '2026-11-12 00:00:00', 'days_left' => 25];
        $r = planPurchaseCheck($s25, $P);
        check('aktif paket, 25 gün kaldı → aynı paket reddedilir', !$r['allowed'] && !$r['renewal'], json_encode($r, JSON_UNESCAPED_UNICODE));
        check('ret mesajı paketi, bitişi ve yenileme penceresini söylüyor',
            str_contains($r['message'], $P) && str_contains($r['message'], '12.11.2026') && str_contains($r['message'], (string) PLAN_RENEWAL_WINDOW_DAYS), $r['message']);
        $s5 = ['days_left' => 5] + $s25;
        $r = planPurchaseCheck($s5, $P);
        check('aktif paket, 5 gün kaldı → yenileme olarak izinli', $r['allowed'] && $r['renewal']);
        $edge = ['days_left' => PLAN_RENEWAL_WINDOW_DAYS] + $s25;
        check('tam pencere sınırında (7 gün) → izinli', planPurchaseCheck($edge, $P)['allowed']);
        $forever = ['expires_at' => null, 'days_left' => null] + $s25;
        check('süresiz aktif paket → aynı paket her zaman reddedilir', !planPurchaseCheck($forever, $P)['allowed']);
        $other = $db->selectSingle('name_tr FROM plans WHERE monthly_price > ? ORDER BY monthly_price LIMIT 1', [$paid['monthly_price']]);
        check('farklı paket (üst/alt) → bu kural karışmıyor (müşteri kararı bekliyor)', planPurchaseCheck($s25, (string) $other['name_tr'])['allowed']);
        check('ücretsiz/süresi dolmuş → izinli', planPurchaseCheck(['plan' => $free['name_tr'], 'is_paid' => false, 'state' => 'expired', 'expires_at' => null, 'days_left' => null], $P)['allowed']);

        $ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/WalletController.php');
        check('upgradePlan sunucuda planPurchaseCheck uyguluyor (Hoppa\'ya gitmeden)',
            (bool) preg_match('/function upgradePlan\(\)[\s\S]*planPurchaseCheck\([\s\S]*startHostedPlanPayment\(/', $ctl));
        check('kesinleştirme erken yenilemede mevcut bitişe ekliyor (GREATEST)', str_contains($ctl, 'GREATEST(NOW()'));
    }
} finally {
    $conn->rollBack();
}

finish();
