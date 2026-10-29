<?php
/**
 * Saat dilimi öz-testi (N-69) — yerel veritabanı, TRANSACTION + ROLLBACK.
 *
 *   php api/database/timezone_selftest.php
 *
 * Sorun (yerel sağlık kontrolü 2026-10-29): PHP UTC, MySQL UTC+3 çalışıyordu.
 * `selected_at` PHP `date()` ile, sipariş `created_at` / `expires_at` MySQL
 * `NOW()` ile yazılıyordu; Hoppa ile gerçekten ödenmiş paket admin'de
 * "Ödemesiz kayıt" görünüyordu (getPlanSource eşleştiremiyordu).
 *
 * Kural: uygulamanın TEK saat dilimi var (`APP_TIMEZONE`, varsayılan
 * Europe/Istanbul). PHP buna ayarlanır; her veritabanı bağlantısında MySQL
 * oturumunun time_zone'u aynı ofsete eşitlenir. Sunucunun genel MySQL
 * saat dilimine dokunulmaz.
 *
 *   A) PHP ve MySQL oturumu aynı ofsette, aynı "şimdi"yi görüyor
 *   B) gerçek kesinleştirme yolu: ödenmiş paket admin'de "Satın alma"
 *   C) eski kayıtlar: PHP'nin eski saatiyle yazılmış seçim satırı da
 *      ödenmiş siparişle eşleşiyor (mevcut veri yeniden yazılmıyor)
 *   D) kaynak kilitleri: plan seçimini PHP saatiyle yazan yol kalmadı
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/plans.php';
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

echo "\n=== A) Tek saat dilimi ===\n\n";
check('functions/timezone.php: app_timezone() tanımlı', function_exists('app_timezone'));
$want = function_exists('app_timezone') ? app_timezone() : 'Europe/Istanbul';
check("PHP saat dilimi uygulamanınki ($want)", date_default_timezone_get() === $want, date_default_timezone_get());
$my = $db->selectSingle("TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), '%H:%i') AS ofs, NOW() AS simdi, @@session.time_zone AS tz");
$myOfs = (str_starts_with((string) $my['ofs'], '-') ? '' : '+') . $my['ofs'];
check('MySQL oturum ofseti = PHP ofseti', $myOfs === date('P'), "mysql=$myOfs php=" . date('P') . ' session=' . $my['tz']);
check('PHP date() ile MySQL NOW() aynı anı gösteriyor (±5 sn)', abs(strtotime((string) $my['simdi']) - time()) <= 5, $my['simdi'] . ' / ' . date('Y-m-d H:i:s'));

echo "\n=== B) Ödenmiş paketin kaynağı ===\n\n";
$plan = $db->selectSingle("id, name_tr, monthly_price FROM plans WHERE monthly_price > 0 ORDER BY monthly_price LIMIT 1");
$user = $db->selectSingle('id FROM kullanicilar ORDER BY id DESC LIMIT 1');
if (!$plan || !$user) {
    check('ücretli plan ve kullanıcı var', false);
    finish();
}
$uid   = (int) $user['id'];
$price = round((float) $plan['monthly_price'], 2);

$conn->beginTransaction();
try {
    $db->insert('user_plan_selection', ['user_id' => $uid, 'plan_name' => AppConfig::FREE_PLAN_NAME, 'selected_at' => $db->selectSingle('NOW() AS n')['n']]
        + (planSelectionHasExpiry($db) ? ['expires_at' => null] : []), true);
    $fake = new FakeHostedGateway();
    $s = WalletController::startHostedPlanPayment($db, $fake, $uid, $plan, 'http://localhost:3000');
    $o = (string) ($s['order_id'] ?? '');
    $fake->next = ['state' => 'paid', 'amount' => $price + 2.25, 'commission' => 2.25, 'transaction_id' => '1', 'message' => '', 'raw' => ['STATUS' => 'SUCCESS']];
    $f = WalletController::finalizeHostedPlanPayment($db, $fake, $o);
    check('kesinleştirme: paid', ($f['state'] ?? '') === 'paid', json_encode($f, JSON_UNESCAPED_UNICODE));
    $sub = getUserSubscription($db, $uid, true);
    $src = getPlanSource($db, $uid, $sub);
    check('admin Paket kutusu: kaynak "Satın alma" (eskiden "Ödemesiz kayıt")', $src['source'] === 'purchase', json_encode($src, JSON_UNESCAPED_UNICODE));
    $t = $db->selectSingle('u.selected_at, p.created_at FROM user_plan_selection u JOIN param_marketplace_payments p ON p.order_id = ? WHERE u.user_id = ?', [$o, $uid]);
    check('seçim anı ile sipariş anı aynı saatte (±60 sn)', abs(strtotime((string) $t['selected_at']) - strtotime((string) $t['created_at'])) <= 60, json_encode($t));

    echo "\n=== C) Eski kayıtlar ===\n\n";
    // Düzeltme öncesi canlıda PHP UTC iken yazılmış satır: seçim, siparişten
    // 3 saat ÖNCE görünüyor. Veri yeniden yazılmadan "Satın alma" sayılmalı.
    $db->execute('UPDATE user_plan_selection SET selected_at = DATE_SUB(?, INTERVAL 3 HOUR) WHERE user_id = ?', [$t['created_at'], $uid]);
    $src = getPlanSource($db, $uid, getUserSubscription($db, $uid, true));
    check('eski (PHP UTC) seçim satırı da "Satın alma"', $src['source'] === 'purchase', json_encode($src, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    check('B/C istisnasız', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->rollBack();
}

echo "\n=== D) Kaynak kilitleri ===\n\n";
$root = __DIR__ . '/..';
$wallet = (string) file_get_contents($root . '/src/Presentation/Controllers/WalletController.php');
$plans  = (string) file_get_contents($root . '/functions/plans.php');
$hosted = (string) file_get_contents($root . '/functions/hosted_plan_payments.php');
foreach (['WalletController' => $wallet, 'plans.php' => $plans, 'hosted_plan_payments.php' => $hosted] as $name => $src) {
    check("$name: selected_at PHP date() ile yazılmıyor", !preg_match("/'selected_at'\s*=>\s*date\(/", $src));
}
$dbphp = (string) file_get_contents($root . '/functions/db.php');
check('db.php: her bağlantıda SET time_zone', str_contains($dbphp, 'SET time_zone'));
$admin = (string) file_get_contents($root . '/admin/index.php');
check('admin/index.php: sabit Europe/Istanbul yerine app_timezone_init()', !str_contains($admin, "date_default_timezone_set('Europe/Istanbul')"));

finish();
