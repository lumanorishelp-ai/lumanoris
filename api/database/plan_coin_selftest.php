<?php
/**
 * Paket alındığı günün coin hakkı (N-71) — yerel veritabanı, TRANSACTION + ROLLBACK,
 * sahte Hoppa sağlayıcısı (ağ yok).
 *
 *   php api/database/plan_coin_selftest.php
 *
 * Kural (karar 2026-10-29, müşteriye bildirilecek):
 *   - Ücretli paket ödemeyle aktifleştiği anda o günün coin bakiyesi, iki
 *     paketin günlük hakkı arasındaki fark kadar artar (kalan + fark).
 *     Ücretsiz (10) → Gümüş (50): kalan 7 ise 47 olur.
 *   - Aynı gün ikinci kez verilmez: aynı paket için bugün verilmiş (ve geri
 *     alınmamış) fark tekrar verilmez; aynı paketin yenilenmesinde fark 0.
 *   - İade edilen pakette fark geri alınır (bakiye sıfırın altına inmez;
 *     harcanmış kısım geri alınamaz, ama tekrar satın almada yeniden verilmez).
 *   - Bakiye bugün henüz yenilenmemişse fark verilmez: ilk kullanımda zaten
 *     yeni paketin tam hakkıyla yenilenir.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/plans.php';
require_once __DIR__ . '/../functions/coin_engine.php';
require_once __DIR__ . '/../functions/hosted_plan_payments.php';
require_once __DIR__ . '/fake_hosted_gateway.php';

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

$db   = Database::getInstance();
$conn = $db->getConnection();

$plans = [];
foreach ($db->selectMulti('name_tr, monthly_price, daily_message_limit, is_default FROM plans ORDER BY monthly_price') as $p) {
    $plans[] = $p;
}
$free = $plans[0] ?? null;
$mid  = $plans[1] ?? null;
$top  = $plans[2] ?? null;
$user = $db->selectSingle('id FROM kullanicilar ORDER BY id DESC LIMIT 1');
if (!$free || !$mid || !$top || !$user || (float) $mid['monthly_price'] <= 0) {
    check('en az üç plan (biri ücretsiz) ve bir kullanıcı', false);
    finish();
}
check('coin_engine: grantPlanUpgradeCoins / reclaimPlanUpgradeCoins tanımlı', function_exists('grantPlanUpgradeCoins') && function_exists('reclaimPlanUpgradeCoins'));

$uid  = (int) $user['id'];
$F    = (int) $free['daily_message_limit'];
$M    = (int) $mid['daily_message_limit'];
$T    = (int) $top['daily_message_limit'];
$planRow = fn (array $p) => $db->selectSingle('id, name_tr, monthly_price FROM plans WHERE name_tr = ?', [$p['name_tr']]);
$coins   = fn () => (int) $db->selectSingle('coins_remaining FROM ' . AppConfig::TABLE_COIN_BALANCES . ' WHERE user_id = ?', [$uid])['coins_remaining'];
$setCoins = fn (int $n, string $resetDay = 'CURDATE()') => $db->execute(
    'INSERT INTO ' . AppConfig::TABLE_COIN_BALANCES . " (user_id, coins_remaining, last_reset_date, exhausted_at) VALUES (?, ?, $resetDay, NULL)
     ON DUPLICATE KEY UPDATE coins_remaining = VALUES(coins_remaining), last_reset_date = VALUES(last_reset_date), exhausted_at = NULL",
    [$uid, $n]
);
$setPlan = fn (string $name) => $db->insert('user_plan_selection', ['user_id' => $uid, 'plan_name' => $name, 'selected_at' => planSelectionNow($db)]
    + (planSelectionHasExpiry($db) ? ['expires_at' => null] : []), true);
$payRow = fn (string $o) => $db->selectSingle('* FROM param_marketplace_payments WHERE order_id = ?', [$o]);
$fake = new FakeHostedGateway();
$buy = function (array $p) use ($db, $fake, $uid, $planRow): string {
    $plan = $planRow($p);
    $s = WalletController::startHostedPlanPayment($db, $fake, $uid, $plan, 'http://localhost:3000');
    $o = (string) ($s['order_id'] ?? '');
    $price = round((float) $plan['monthly_price'], 2);
    $fake->next = ['state' => 'paid', 'amount' => $price + 2.25, 'commission' => 2.25, 'transaction_id' => '1', 'message' => '', 'raw' => ['STATUS' => 'SUCCESS']];
    WalletController::finalizeHostedPlanPayment($db, $fake, $o);
    return $o;
};
$refund = function (string $o) use ($db, $fake, $payRow) {
    $p = $payRow($o);
    $q = fn (string $st) => ['state' => $st, 'amount' => (float) $p['amount'] + 2.25, 'commission' => 2.25, 'transaction_id' => '1', 'message' => '', 'raw' => []];
    $fake->queue = [$q('paid'), $q('cancelled')];
    return hostedPlanRefund($db, $fake, $p, 'plan_coin_selftest', null, 'plan_coin_selftest', '127.0.0.1');
};

// Önceki siparişler sınırı bozmasın: bu kullanıcının bugünkü siparişleri testte görünmesin diye
// her bölüm kendi siparişlerini oluşturuyor; ölçüm farkla yapılıyor.
$conn->beginTransaction();
try {
    // Bugün başka sipariş varsa (yerel denemeler) "aynı gün" kuralı ölçümü bozar.
    $db->execute("UPDATE param_marketplace_payments SET created_at = DATE_SUB(created_at, INTERVAL 2 DAY) WHERE user_id = ? AND created_at >= CURDATE()", [$uid]);

    echo "\n=== A) Aktifleşme anında fark ===\n\n";
    $setPlan($free['name_tr']);
    $setCoins($F - 3);
    $o1 = $buy($mid);
    check("ücretsiz → {$mid['name_tr']}: kalan + fark (" . ($F - 3) . ' + ' . ($M - $F) . ')', $coins() === $F - 3 + ($M - $F), (string) $coins());
    check('tekrar kesinleştirme farkı ikinci kez vermiyor', (function () use ($db, $fake, $o1, $coins, $F, $M) {
        WalletController::finalizeHostedPlanPayment($db, $fake, $o1);
        return $coins() === $F - 3 + ($M - $F);
    })());
    $c = $coins();
    $o2 = $buy($mid);
    check("aynı gün aynı paket (yenileme): fark yok", $coins() === $c, $coins() . ' / ' . $c);
    $c = $coins();
    $o3 = $buy($top);
    check("{$mid['name_tr']} → {$top['name_tr']}: yalnızca iki paketin farkı (+" . ($T - $M) . ')', $coins() === $c + ($T - $M), $coins() . ' / ' . $c);

    echo "\n=== B) İade ===\n\n";
    $c = $coins();
    $r = $refund($o3);
    check('iade: paket geri alındı', ($r['success'] ?? false) && ($r['data']['plan']['revoked'] ?? false), json_encode($r, JSON_UNESCAPED_UNICODE));
    check("iade: {$top['name_tr']} farkı geri alındı (−" . ($T - $M) . ')', $coins() === $c - ($T - $M), $coins() . ' / ' . $c);

    // Altın iade edilince paket ücretsize döndü (mevcut iade kuralı), ama A'daki
    // Gümüş farkı hâlâ kullanıcıda: aynı gün Gümüş yeniden alınırsa fark verilmez.
    $c = $coins();
    $buy($mid);
    check("aynı gün {$mid['name_tr']} yeniden: bugün verilmiş fark tekrar verilmiyor", $coins() === $c, $coins() . ' / ' . $c);

    echo "\n=== C) Harcanmış fark, iade, yeniden satın alma ===\n\n";
    // Önceki bölümlerin farklarını geçmişe al: bu bölüm bağımsız ölçülsün.
    $db->execute("UPDATE param_marketplace_payments SET param_response_json = JSON_SET(param_response_json, '$.coin_bonus.date', '2000-01-01')
                   WHERE user_id = ? AND JSON_EXTRACT(param_response_json, '$.coin_bonus') IS NOT NULL", [$uid]);
    $setPlan($free['name_tr']);
    $db->execute("UPDATE param_marketplace_payments SET created_at = DATE_SUB(created_at, INTERVAL 2 DAY) WHERE user_id = ? AND created_at >= CURDATE()", [$uid]);
    $setCoins($F);
    $o4 = $buy($mid);
    check('fark verildi', $coins() === $M, (string) $coins());
    $setCoins(5);                       // kullanıcı farkın çoğunu harcadı
    $refund($o4);
    check('iade: bakiye sıfırın altına inmiyor', $coins() === 0, (string) $coins());
    $o5 = $buy($mid);
    check('aynı gün yeniden satın alma: harcanmış fark tekrar verilmiyor (yalnızca geri alınan 5)', $coins() === 5, (string) $coins());

    echo "\n=== D) Bakiye bugün yenilenmemiş ===\n\n";
    $setPlan($free['name_tr']);
    $db->execute("UPDATE param_marketplace_payments SET created_at = DATE_SUB(created_at, INTERVAL 2 DAY) WHERE user_id = ? AND created_at >= CURDATE()", [$uid]);
    $setCoins(2, 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)');
    $buy($mid);
    check('dünkü bakiyeye fark eklenmiyor', $coins() === 2, (string) $coins());
    $b = getOrInitCoinBalance($db, $uid);
    check("ilk kullanımda yeni paketin tam hakkı ($M)", (int) $b['coins_remaining'] === $M, json_encode($b));
} catch (Throwable $e) {
    check('istisnasız', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->rollBack();
}

finish();
