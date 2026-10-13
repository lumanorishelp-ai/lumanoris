<?php
/**
 * Hoppa paket ödemesi öz-testi (Faz 7a) — KALICI YAZMA YOK.
 *
 *   php api/database/hoppa_selftest.php          # A + B
 *   php api/database/hoppa_selftest.php --e2e    # A + B + C (Hoppa TEST ortamı + tarayıcı)
 *
 * A) Çevrimdışı: sağlayıcı seçimi (PAYMENT_PROVIDER, varsayılan none),
 *    HoppaGateway yapılandırma kapısı (canlı anahtar boşsa kapalı), tutar
 *    ayrıştırma, yönlendirme adresi doğrulaması, ProcessQuery yanıt
 *    sınıflandırması (test ortamında kaydedilmiş GERÇEK yanıtlarla) ve
 *    N-17 kilidi (paket ödemesinde kart verisi yolu yok).
 * B) Veritabanı (transaction + ROLLBACK, sahte sağlayıcı): ödeme oturumu
 *    satırı, kesinleştirme, TEKRAR ÇALIŞTIRMAYA DAYANIKLILIK (aynı sipariş
 *    iki kez paket tanımlamaz, ikinci çağrı sağlayıcıyı sormaz), tutar
 *    uyuşmazlığı, reddedilen ödeme, reddedilen → ödenen geçişi.
 * B2) Faz 7a-2 (transaction + ROLLBACK, sahte sağlayıcı): mutabakat (15 dk,
 *    24 saat → hoppa_expired, 7 güne kadar 6 saatte bir yeniden sorgu),
 *    yenileme hatırlatması (bitişe 3 gün, dönem başına bir kez), admin tam
 *    iadesi (kilit, doğrulama, paket geri alma, admin_audit_log).
 * C) --e2e: Hoppa TEST ortamında gerçek ödeme. Dokümandaki herkese açık
 *    test kartlarıyla bir başarılı (9792100000000001) ve bir hatalı
 *    (5100050000006661, hata 51) ödeme. Kart, tarayıcıda Hoppa'nın
 *    sayfasında girilir (hoppa_e2e_driver.cjs); BACK_URL POST'u yakalanır,
 *    kesinleştirme ProcessQuery ile yapılır. Başarılı ödeme ardından
 *    OrderReturn ile GERÇEKTEN iade edilir; ödenmemiş bir siparişle gerçek
 *    mutabakat denenir. Veritabanı tarafı ROLLBACK.
 *    Gereksinim: api/.env'de HOPPA_MODE=test + test kimlik bilgileri;
 *    `playwright-core` (HOPPA_E2E_NODE_PATH = onu içeren node_modules
 *    dizini); isteğe bağlı HOPPA_E2E_CHROME. Eksikse C ATLANIR (geçti
 *    sayılmaz, "ATLANDI" yazılır).
 *
 * Bu dosya `api/database/` altında: üç denylist de bu dizini web'e kapatıyor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/plans.php';
require_once __DIR__ . '/../functions/hosted_plan_payments.php';

$pass = 0;
$fail = 0;
$e2e  = in_array('--e2e', $argv ?? [], true);

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

/** B bölümü için sahte sağlayıcı: ağ yok, yanıtı test belirliyor, çağrıları sayıyor. */
final class FakeHostedGateway implements PaymentGatewayInterface
{
    public int $queries = 0;
    public array $next  = ['state' => 'pending', 'amount' => null, 'commission' => null, 'transaction_id' => null, 'message' => '', 'raw' => []];
    /** Sipariş başına sabit yanıt (mutabakat testi). */
    public array $byOrder = [];
    /** Sırayla tüketilen yanıtlar (iade testi: önce/sonra sorgusu). Doluysa önceliklidir. */
    public array $queue = [];
    /** Sorulan siparişler, sırayla. */
    public array $asked = [];
    public int $refunds = 0;
    public ?float $lastRefundAmount = null;
    public array $refundResult = ['success' => true, 'message' => 'İŞLEM İPTAL EDİLDİ', 'error_code' => null, 'raw' => ['STATUS' => 'SUCCESS']];

    public function isConfigured(): bool { return true; }
    public function isTest(): bool { return true; }
    public function startHostedPayment(array $order): array
    {
        return ['success' => true, 'redirect_url' => 'https://postest.esnekpos.com/Pages/CommonPaymentNew.aspx?hash=fake',
                'provider_ref' => '1', 'message' => '', 'error_code' => null, 'raw' => []];
    }
    public function queryPayment(string $orderRef): array
    {
        $this->queries++;
        $this->asked[] = $orderRef;
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        return $this->byOrder[$orderRef] ?? $this->next;
    }
    public function refundPayment(string $orderRef, float $amount): array
    {
        $this->refunds++;
        $this->lastRefundAmount = $amount;
        return $this->refundResult;
    }
}

// ── Test ortamında kaydedilmiş gerçek ProcessQuery yanıtları (2026-10-07) ──
// Kişisel/kimlik verisi yok; TEST üye işyeri siparişleri.
$REAL_PAID = json_decode('{"STATUS":"SUCCESS","RETURN_CODE":"0","RETURN_MESSAGE":"SUCCESS","DATE":"7.10.2026 08:50:48","PAYMENT_DATE":"7.10.2026 08:50:31","REFNO":"403998","AMOUNT":"151,25","ORDER_REF_NO":"PRB6AB115E04889","INSTALLMENT":"1","COMMISSION":2.25,"COMMISSION_RATE":"1,490","SUCCESS_TRANSACTION_ID":783900,"TRANSACTIONS":[{"TRANSACTION_ID":783900,"STATUS_NAME":"Ödeme - Başarılı","STATUS_ID":3,"AMOUNT":"151,25","DATE":"7.10.2026 08:50:37","PLANNED_TRANSFER_DATE":null,"MERCHANT_AMOUNT_TRANSFER_DETAIL":null,"SUB_MERCHANT_DETAILS":null}]}', true);
$REAL_FAILED = json_decode('{"STATUS":"ERROR","RETURN_CODE":"100","RETURN_MESSAGE":"51-Limit Yetersiz","DATE":"7.10.2026 08:51:35","PAYMENT_DATE":null,"REFNO":"403999","AMOUNT":"151,25","ORDER_REF_NO":"PRB473855AF38A4","INSTALLMENT":"1","COMMISSION":2.25,"COMMISSION_RATE":"1,490","SUCCESS_TRANSACTION_ID":0,"TRANSACTIONS":[{"TRANSACTION_ID":783901,"STATUS_NAME":"Ödeme - Bekliyor","STATUS_ID":1,"AMOUNT":"-151,25"},{"TRANSACTION_ID":783902,"STATUS_NAME":"Ödeme - 3D Doğrulama Bekleniyor","STATUS_ID":2,"AMOUNT":"-151,25"},{"TRANSACTION_ID":783903,"STATUS_NAME":"Ödeme - Başarısız","STATUS_ID":4,"AMOUNT":"-151,25"}]}', true);
$REAL_NOT_FOUND = json_decode('{"STATUS":"PROCESS_QUERY","RETURN_CODE":"400","RETURN_MESSAGE":"Referans numarası bulunamadı (Not.ProcessQuery)","ORDER_REF_NO":null,"SUCCESS_TRANSACTION_ID":0,"TRANSACTIONS":null}', true);

// ═════════════════════════════════════════════════════════════════════════
echo "\n=== A) Çevrimdışı ===\n\n";

check('PAYMENT_PROVIDER boş → none', PaymentGatewayFactory::provider('') === 'none');
check('PAYMENT_PROVIDER none → sağlayıcı yok', PaymentGatewayFactory::make('none') === null);
check('PAYMENT_PROVIDER tanınmayan (iyzico dahil) → none', PaymentGatewayFactory::provider('iyzico') === 'none' && PaymentGatewayFactory::provider('xyz') === 'none');
check('PAYMENT_PROVIDER büyük/küçük harf duyarsız', PaymentGatewayFactory::provider(' HOPPA ') === 'hoppa');
$envExample = (string) file_get_contents(__DIR__ . '/../.env.example');
check('.env.example varsayılanı PAYMENT_PROVIDER=none', (bool) preg_match('/^PAYMENT_PROVIDER=none\s*$/m', $envExample));
check('.env.example canlı Hoppa anahtarları boş', (bool) preg_match('/^HOPPA_LIVE_MERCHANT=\s*$/m', $envExample) && (bool) preg_match('/^HOPPA_LIVE_MERCHANT_KEY=\s*$/m', $envExample));
check('.env.example test anahtarı repoda YOK', (bool) preg_match('/^HOPPA_TEST_MERCHANT_KEY=\s*$/m', $envExample));

check('canlı mod + boş anahtar → kapalı', !(new HoppaGateway('live', '', ''))->isConfigured());
check('bilinmeyen mod → kapalı', !(new HoppaGateway('prod', 'M', 'K'))->isConfigured());
$gt = new HoppaGateway('test', 'M', 'K');
check('test mod → test base URL', $gt->isConfigured() && $gt->isTest() && $gt->baseUrl() === HoppaGateway::TEST_BASE_URL);
$gl = new HoppaGateway('live', 'M', 'K');
check('canlı mod → canlı base URL', !$gl->isTest() && $gl->baseUrl() === HoppaGateway::LIVE_BASE_URL);
$off = (new HoppaGateway('live', '', ''))->startHostedPayment(['order_ref' => 'X']);
check('yapılandırılmamışken istek atılmıyor (CONFIG_MISSING)', !$off['success'] && $off['error_code'] === 'CONFIG_MISSING');

check('tutar "151,25" → 151.25', HoppaGateway::parseAmount('151,25') === 151.25);
check('tutar "1.250,00" → 1250.0', HoppaGateway::parseAmount('1.250,00') === 1250.0);
check('tutar 2.25 (sayı) → 2.25', HoppaGateway::parseAmount(2.25) === 2.25);
check('tutar boş/anlamsız → null', HoppaGateway::parseAmount('') === null && HoppaGateway::parseAmount('abc') === null && HoppaGateway::parseAmount(null) === null);
check('istek tutarı biçimi 149 → "149.00"', HoppaGateway::money(149) === '149.00');

check('tutar: komisyon alıcıda (151,25 − 2,25 = 149) kabul', HoppaGateway::amountCovers(149.0, 151.25, 2.25));
check('tutar: komisyon işyerinde (149 = 149) kabul', HoppaGateway::amountCovers(149.0, 149.0, null));
check('tutar: eksik çekim reddedilir', !HoppaGateway::amountCovers(149.0, 140.0, 0.0));
check('tutar: komisyon bilinmeden fazla çekim reddedilir', !HoppaGateway::amountCovers(149.0, 151.25, null));
check('tutar: çekim yoksa reddedilir', !HoppaGateway::amountCovers(149.0, null, 2.25));

check('yönlendirme: https test sayfası kabul', HoppaGateway::isTrustedPaymentUrl('https://postest.esnekpos.com/Pages/CommonPaymentNew.aspx?hash=x'));
check('yönlendirme: http reddedilir', !HoppaGateway::isTrustedPaymentUrl('http://pos.esnekpos.com/Pages/CommonPayment.aspx?hash=x'));
check('yönlendirme: yabancı alan adı reddedilir', !HoppaGateway::isTrustedPaymentUrl('https://esnekpos.com.evil.example/x') && !HoppaGateway::isTrustedPaymentUrl('https://evil.example/?esnekpos.com'));

$c = HoppaGateway::classifyQueryResponse($REAL_PAID, 'PRB6AB115E04889');
check('gerçek yanıt: ödendi → paid, 151.25 / komisyon 2.25', $c['state'] === 'paid' && $c['amount'] === 151.25 && $c['commission'] === 2.25 && $c['transaction_id'] === '783900', json_encode($c));
$c = HoppaGateway::classifyQueryResponse($REAL_FAILED, 'PRB473855AF38A4');
check('gerçek yanıt: hata 51 → failed', $c['state'] === 'failed', json_encode($c));
$c = HoppaGateway::classifyQueryResponse($REAL_NOT_FOUND, 'PRB79D0CB0EC694');
check('gerçek yanıt: ödenmemiş oturum (400) → pending, failed DEĞİL', $c['state'] === 'pending', json_encode($c));
$c = HoppaGateway::classifyQueryResponse($REAL_PAID, 'BASKA-SIPARIS');
check('yanıt başka siparişe aitse → unknown', $c['state'] === 'unknown');
$cancel = ['STATUS' => 'ORDER_CANCEL', 'RETURN_CODE' => '300', 'ORDER_REF_NO' => 'R1', 'TRANSACTIONS' => []];
check('dokümandan: iptal → cancelled (ödendi sayılmaz)', HoppaGateway::classifyQueryResponse($cancel, 'R1')['state'] === 'cancelled');
$refund = $REAL_PAID;
$refund['TRANSACTIONS'][] = ['TRANSACTION_ID' => 9, 'STATUS_ID' => 7];
check('dokümandan: iade hareketi → refunded (ödendi sayılmaz)', HoppaGateway::classifyQueryResponse($refund, 'PRB6AB115E04889')['state'] === 'refunded');
$odd = $REAL_PAID;
$odd['TRANSACTIONS'][0]['STATUS_ID'] = 1;
check('SUCCESS ama başarılı hareket yok → unknown', HoppaGateway::classifyQueryResponse($odd, 'PRB6AB115E04889')['state'] === 'unknown');
$red = HoppaGateway::redact(['CUSTOMER_CC_NUMBER' => '979210****0001', 'CUSTOMER_NAME' => 'X', 'STATUS' => 'SUCCESS']);
check('ham yanıttan müşteri/kart alanları çıkarılıyor', !isset($red['CUSTOMER_CC_NUMBER']) && !isset($red['CUSTOMER_NAME']) && isset($red['STATUS']));

$ref = 'PLN-' . strtoupper(InputSanitizer::randomToken(8));
check('sipariş referansı biçimi ve 24 karakter sınırı', preg_match(WalletController::HOSTED_ORDER_PATTERN, $ref) === 1 && strlen($ref) <= HoppaGateway::MAX_ORDER_REF);

// N-17 kilidi: paket ödemesi kart verisi okumaz/göndermez.
$wallet = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/WalletController.php');
check('N-17: WalletController kart okumuyor ($data[\'card\'] yok)', !str_contains($wallet, "\$data['card']"));
check('N-17: WalletController chargeCard() çağırmıyor', !preg_match('/(?<![`\w])chargeCard\(\s*\$/', $wallet));
$web = __DIR__ . '/../../web/src';
foreach (['features/payment/PlanPaymentModal.jsx', 'app/dashboard/upgrade/page.jsx', 'app/dashboard/checkout/page.jsx'] as $f) {
    $src = (string) @file_get_contents("$web/$f");
    // Yalnızca gerçek kullanım (import / JSX / çağrı); N-17 açıklama yorumlarındaki adlar sayılmaz.
    check("N-17: $f kart alanı/gönderimi içermiyor", $src !== '' && !preg_match('/import\s+CardFields|<CardFields|toCardPayload\(|cardInfo|\bcard:\s/', $src));
}

// N-31 kilidi: BACK_URL çapraz-site POST'unda oturum başlatılmaz (yeni
// PHPSESSID kullanıcının oturum çerezinin üstüne yazıyordu).
$ret  = (string) file_get_contents(__DIR__ . '/../api/wallet/hoppa_return.php');
$boot = (string) file_get_contents(__DIR__ . '/../functions/bootstrap.php');
$def  = strpos($ret, "define('LUMANORIS_STATELESS_ENDPOINT', true)");
$req  = strpos($ret, 'autoload.php');
check('N-31: hoppa_return.php autoload\'dan ÖNCE stateless işaretliyor', $def !== false && $req !== false && $def < $req);
check('N-31: bootstrap stateless uçta session_start() yapmıyor', str_contains($boot, "!defined('LUMANORIS_STATELESS_ENDPOINT') && session_status() === PHP_SESSION_NONE"));

// ═════════════════════════════════════════════════════════════════════════
echo "\n=== B) Kesinleştirme ve tekrar çalıştırma (transaction + ROLLBACK, sahte sağlayıcı) ===\n\n";

$db   = Database::getInstance();
$conn = $db->getConnection();

$plan = $db->selectSingle("id, name_tr, monthly_price FROM plans WHERE monthly_price > 0 ORDER BY monthly_price LIMIT 1");
$user = $db->selectSingle('id FROM kullanicilar ORDER BY id LIMIT 1');
if (!$plan || !$user) {
    check('B için ücretli plan ve kullanıcı var', false, 'plans/kullanicilar boş');
    finish();
}
$uid   = (int) $user['id'];
$price = round((float) $plan['monthly_price'], 2);
$row   = fn (string $o) => $db->selectSingle('status, amount, redirect_url, param_net_amount FROM param_marketplace_payments WHERE order_id = ?', [$o]);
$sel   = fn () => $db->selectSingle('plan_name, selected_at, expires_at FROM user_plan_selection WHERE user_id = ?', [$uid]) ?: ['plan_name' => null, 'expires_at' => null];
// Kullanıcının plan satırı olmayabilir: upsert (transaction içinde, ROLLBACK ile geri alınır).
$setPlan = fn (string $name) => $db->insert('user_plan_selection', ['user_id' => $uid, 'plan_name' => $name, 'selected_at' => date('Y-m-d H:i:s')]
    + (planSelectionHasExpiry($db) ? ['expires_at' => null] : []), true);

$conn->beginTransaction();
try {
    $setPlan(AppConfig::FREE_PLAN_NAME);
    $fake = new FakeHostedGateway();

    check('APP_PUBLIC_URL yoksa ödeme başlatılmıyor (503)', WalletController::startHostedPlanPayment($db, $fake, $uid, $plan, '')['http'] === 503);
    $live = new HoppaGateway('live', 'M', 'K');
    check('canlı modda http APP_PUBLIC_URL reddedilir', WalletController::startHostedPlanPayment($db, $live, $uid, $plan, 'http://ornek.com')['http'] === 503);

    $s = WalletController::startHostedPlanPayment($db, $fake, $uid, $plan, 'http://localhost:3000');
    $o = $s['order_id'] ?? '';
    $r = $o !== '' ? $row($o) : null;
    check('oturum: satır hoppa_pending, tutar sunucudan, yönlendirme kaydedildi',
        $s['success'] && $r && $r['status'] === WalletController::HOSTED_PENDING && (float) $r['amount'] === $price && str_starts_with((string) $r['redirect_url'], 'https://'),
        json_encode($r));

    $fake->next = ['state' => 'pending', 'amount' => null, 'commission' => null, 'transaction_id' => null, 'message' => '', 'raw' => []];
    $f = WalletController::finalizeHostedPlanPayment($db, $fake, $o);
    check('ödenmemiş: pending kalır, paket değişmez', $f['state'] === 'pending' && $row($o)['status'] === WalletController::HOSTED_PENDING && $sel()['plan_name'] === AppConfig::FREE_PLAN_NAME);

    $fake->next = ['state' => 'paid', 'amount' => $price + 5, 'commission' => 1.0, 'transaction_id' => '1', 'message' => '', 'raw' => []];
    $f = WalletController::finalizeHostedPlanPayment($db, $fake, $o);
    check('tutar uyuşmazlığı: paket verilmez, satır açık kalır', $f['state'] === 'unknown' && $f['message'] === 'amount_mismatch' && $row($o)['status'] === WalletController::HOSTED_PENDING && $sel()['plan_name'] === AppConfig::FREE_PLAN_NAME);

    $fake->next = ['state' => 'paid', 'amount' => $price + 2.25, 'commission' => 2.25, 'transaction_id' => '1', 'message' => '', 'raw' => ['STATUS' => 'SUCCESS']];
    $f = WalletController::finalizeHostedPlanPayment($db, $fake, $o);
    $s1 = $sel();
    check('ödendi: satır paid, paket tanımlandı, bitiş tarihi var', $f['state'] === 'paid' && $row($o)['status'] === 'paid' && $s1['plan_name'] === $plan['name_tr'] && !empty($s1['expires_at']), json_encode($s1));
    check('ödendi: net tutar = çekilen − komisyon', abs((float) $row($o)['param_net_amount'] - $price) < 0.005);

    // Tekrar çalıştırma: paketi "sabotajla" değiştirip ikinci kesinleştirmenin yeniden yazmadığını görüyoruz.
    $setPlan('__isaret__');
    $q0 = $fake->queries;
    $f2 = WalletController::finalizeHostedPlanPayment($db, $fake, $o);
    check('tekrar: ikinci çağrı paid döner', $f2['state'] === 'paid');
    check('tekrar: ikinci çağrı paketi YENİDEN tanımlamaz', $sel()['plan_name'] === '__isaret__');
    check('tekrar: ikinci çağrı sağlayıcıyı sormaz', $fake->queries === $q0);
    $setPlan(AppConfig::FREE_PLAN_NAME);

    // Reddedilen ödeme, sonra aynı siparişte başarılı ödeme.
    $s = WalletController::startHostedPlanPayment($db, $fake, $uid, $plan, 'http://localhost:3000');
    $o2 = $s['order_id'];
    $fake->next = ['state' => 'failed', 'amount' => null, 'commission' => null, 'transaction_id' => null, 'message' => '51-Limit Yetersiz', 'raw' => []];
    $f = WalletController::finalizeHostedPlanPayment($db, $fake, $o2);
    check('reddedildi: satır hoppa_failed, paket değişmez', $f['state'] === 'failed' && $row($o2)['status'] === WalletController::HOSTED_FAILED && $sel()['plan_name'] === AppConfig::FREE_PLAN_NAME);
    $fake->next = ['state' => 'paid', 'amount' => $price, 'commission' => 0.0, 'transaction_id' => '2', 'message' => '', 'raw' => []];
    $f = WalletController::finalizeHostedPlanPayment($db, $fake, $o2);
    check('reddedilen sipariş sonradan ödendiyse paket tanımlanır', $f['state'] === 'paid' && $row($o2)['status'] === 'paid' && $sel()['plan_name'] === $plan['name_tr']);

    check('Hoppa satırı olmayan sipariş → not_found', WalletController::finalizeHostedPlanPayment($db, $fake, 'PLN-0000000000000000')['state'] === 'not_found');
} finally {
    $conn->rollBack();
}
check('ROLLBACK: test siparişi kalmadı', $row($o) === false || $row($o) === null);

// ═════════════════════════════════════════════════════════════════════════
echo "\n=== B2) Faz 7a-2: mutabakat, yenileme hatırlatması, iade (transaction + ROLLBACK, sahte sağlayıcı) ===\n\n";

$qr = fn (string $state, ?float $amount = null, ?float $commission = null): array => [
    'state' => $state, 'amount' => $amount, 'commission' => $commission,
    'transaction_id' => $state === 'paid' ? '1' : null, 'message' => '', 'raw' => ['state' => $state],
];
// Siparişi geçmişe taşır (oluşturma ve son güncelleme).
$age = fn (string $o, int $minutes) => $db->execute(
    'UPDATE param_marketplace_payments SET created_at = DATE_SUB(NOW(), INTERVAL ? MINUTE), updated_at = DATE_SUB(NOW(), INTERVAL ? MINUTE) WHERE order_id = ?',
    [$minutes, $minutes, $o]
);
$payRow = fn (string $o) => $db->selectSingle('* FROM param_marketplace_payments WHERE order_id = ?', [$o]);
$outcomes = fn (string $o) => array_column($db->selectMulti(
    "JSON_UNQUOTE(JSON_EXTRACT(details, '$.outcome')) AS outcome FROM admin_audit_log
      WHERE action = 'hoppa_plan_refund' AND target_id = ? ORDER BY id",
    [(int) $payRow($o)['id']]
), 'outcome');
$notes = fn () => (int) $db->selectSingle(
    'COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = ?',
    [$uid, PLAN_RENEWAL_NOTIFICATION_TYPE]
)['c'];
$refund = fn (FakeHostedGateway $g, string $o) => hostedPlanRefund($db, $g, $payRow($o), 'selftest', null, 'hoppa_selftest', '127.0.0.1');

$conn->beginTransaction();
try {
    $setPlan(AppConfig::FREE_PLAN_NAME);
    $fake  = new FakeHostedGateway();
    $start = fn () => WalletController::startHostedPlanPayment($db, $fake, $uid, $plan, 'http://localhost:3000')['order_id'];

    // ── Mutabakat ─────────────────────────────────────────────────────────
    $oPaid = $start(); $age($oPaid, 20);      $fake->byOrder[$oPaid] = $qr('paid', $price, 0.0);
    $oNew  = $start(); $age($oNew, 5);        $fake->byOrder[$oNew]  = $qr('paid', $price, 0.0);
    $oFail = $start(); $age($oFail, 20);      $fake->byOrder[$oFail] = $qr('failed');
    $oOld  = $start(); $age($oOld, 30 * 60);  $fake->byOrder[$oOld]  = $qr('pending');
    $oWait = $start(); $age($oWait, 20);      $fake->byOrder[$oWait] = $qr('pending');

    $st = hostedPlanReconcile($db, $fake);
    check('mutabakat: 15 dk geçmiş, dönüşü gelmemiş ödenmiş sipariş → paid + paket', $row($oPaid)['status'] === 'paid' && $sel()['plan_name'] === $plan['name_tr'], json_encode($st));
    check('mutabakat: 15 dk dolmamış sipariş sorulmaz', $row($oNew)['status'] === WalletController::HOSTED_PENDING && !in_array($oNew, $fake->asked, true));
    check('mutabakat: reddedilen → hoppa_failed', $row($oFail)['status'] === WalletController::HOSTED_FAILED);
    check('mutabakat: 24 saati geçmiş, hâlâ ödenmemiş → hoppa_expired', $row($oOld)['status'] === WalletController::HOSTED_EXPIRED);
    check('mutabakat: 24 saati geçmemiş, ödenmemiş → hoppa_pending kalır', $row($oWait)['status'] === WalletController::HOSTED_PENDING);
    // Sayaçlar mutabakatın gördüğü TÜM satırları sayar. Yerel veritabanında
    // senaryo dışında yarım kalmış gerçek bir TEST siparişi varsa (ör. kart
    // girilmeden bırakılan ödeme) o da sayılır; test eskiden bunu varsaymıyor
    // ve kırmızıya düşüyordu (2026-10-10). Beklenen değer: senaryo (1/1/1/1)
    // + senaryo dışı satırların bu transaction sonundaki durumu.
    $expect = ['paid' => 1, 'failed' => 1, 'expired' => 1, 'pending' => 1];
    $statusKey = ['paid' => 'paid', WalletController::HOSTED_FAILED => 'failed', WalletController::HOSTED_EXPIRED => 'expired', WalletController::HOSTED_PENDING => 'pending'];
    $foreign = array_diff(array_unique($fake->asked), [$oPaid, $oFail, $oOld, $oWait]);
    foreach ($foreign as $fo) {
        $k = $statusKey[$row($fo)['status'] ?? ''] ?? null;
        if ($k !== null) $expect[$k]++;
    }
    if ($foreign !== []) echo '        (senaryo dışı ' . count($foreign) . " sipariş de sayıldı)\n";
    check('mutabakat sayaçları', $st['paid'] === $expect['paid'] && $st['failed'] === $expect['failed'] && $st['expired'] === $expect['expired'] && $st['pending'] === $expect['pending'], json_encode(['sayac' => $st, 'beklenen' => $expect]));

    $setPlan('__isaret__');
    $fake->asked = [];
    hostedPlanReconcile($db, $fake);
    check('mutabakat tekrar: ödenmiş sipariş yeniden işlenmez, paket yeniden tanımlanmaz', $sel()['plan_name'] === '__isaret__' && !in_array($oPaid, $fake->asked, true));
    check('mutabakat tekrar: süresi dolmuş sipariş 6 saat dolmadan yeniden sorulmaz', !in_array($oOld, $fake->asked, true));

    // Süresi dolmuş sayılan siparişin ödemesi sonradan görünürse paket tanımlanır.
    $db->execute('UPDATE param_marketplace_payments SET updated_at = DATE_SUB(NOW(), INTERVAL 7 HOUR) WHERE order_id = ?', [$oOld]);
    $fake->byOrder[$oOld] = $qr('paid', $price, 0.0);
    $setPlan(AppConfig::FREE_PLAN_NAME);
    hostedPlanReconcile($db, $fake);
    check('mutabakat: hoppa_expired sonradan ödenmiş çıkarsa → paid + paket', $row($oOld)['status'] === 'paid' && $sel()['plan_name'] === $plan['name_tr']);

    $oAncient = $start();
    $age($oAncient, 8 * 24 * 60);
    $db->execute('UPDATE param_marketplace_payments SET status = ?, updated_at = DATE_SUB(NOW(), INTERVAL 7 HOUR) WHERE order_id = ?', [WalletController::HOSTED_EXPIRED, $oAncient]);
    $fake->byOrder[$oAncient] = $qr('paid', $price, 0.0);
    $fake->asked = [];
    hostedPlanReconcile($db, $fake);
    check('mutabakat: 7 günden eski hoppa_expired artık sorulmaz', !in_array($oAncient, $fake->asked, true) && $row($oAncient)['status'] === WalletController::HOSTED_EXPIRED);

    // ── Yenileme hatırlatması (GK-27) ─────────────────────────────────────
    $expireIn = fn (int $days) => $db->insert('user_plan_selection', [
        'user_id' => $uid, 'plan_name' => $plan['name_tr'], 'selected_at' => date('Y-m-d H:i:s'),
        'expires_at' => (string) $db->selectSingle('DATE_ADD(NOW(), INTERVAL ? DAY) AS t', [$days])['t'],
    ], true);
    $n0 = $notes();
    $expireIn(10);
    planRenewalReminders($db);
    check('hatırlatma: bitişe 10 gün varken gönderilmez', $notes() === $n0);
    $expireIn(2);
    planRenewalReminders($db);
    check('hatırlatma: bitişe 3 günden az kala bir bildirim', $notes() === $n0 + 1);
    planRenewalReminders($db);
    check('hatırlatma: aynı dönem için ikinci kez gönderilmez', $notes() === $n0 + 1);
    $db->execute('UPDATE notifications SET created_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE user_id = ? AND type = ?', [$uid, PLAN_RENEWAL_NOTIFICATION_TYPE]);
    planRenewalReminders($db);
    check('hatırlatma: önceki dönemin bildirimi yeni dönemi engellemez', $notes() === $n0 + 2);
    $setPlan(AppConfig::FREE_PLAN_NAME);
    $expireIn(-1);
    $n1 = $notes();
    planRenewalReminders($db);
    check('hatırlatma: süresi geçmiş pakete gönderilmez', $notes() === $n1);

    // ── İade ──────────────────────────────────────────────────────────────
    check('isHostedPlanPayment: Hoppa paket satırı tanınır', isHostedPlanPayment($payRow($oPaid)));
    check('isHostedPlanPayment: iyzico satırı tanınmaz', !isHostedPlanPayment(['param_response_json' => '{"paymentId":"1"}', 'items_json' => '[{"plan_name":"Gümüş"}]']));

    // $oPaid'den SONRA ödenmiş başka paket ödemesi var ($oOld) → paket geri alınmaz.
    $setPlan($plan['name_tr']);
    $fake->queue = [$qr('paid', $price, 0.0), $qr('cancelled', $price, 0.0)];
    $r1 = $refund($fake, $oPaid);
    check('iade: Hoppa iptali doğrulandı → satır refunded', $r1['success'] && $row($oPaid)['status'] === 'refunded', json_encode($r1));
    check('iade: sonradan ödenmiş paket varken paket geri ALINMAZ (neden yanıtta)', $sel()['plan_name'] === $plan['name_tr'] && $r1['data']['plan']['revoked'] === false && $r1['data']['plan']['reason'] !== '');

    $refundsBefore = $fake->refunds;
    $r2 = $refund($fake, $oPaid);
    check('iade tekrar: 409, Hoppa\'ya ikinci istek gitmez', $r2['http'] === 409 && $fake->refunds === $refundsBefore);
    check('iade: her deneme admin_audit_log\'da (tamamlandi, reddedildi)', $outcomes($oPaid) === ['tamamlandi', 'reddedildi'], json_encode($outcomes($oPaid)));

    // En son ödeme; komisyon alıcıya yansımış: iade edilen = karttan çekilen.
    $fake->queue = [$qr('paid', $price + 2.25, 2.25), $qr('cancelled', $price + 2.25, 2.25)];
    $r3 = $refund($fake, $oOld);
    check('iade: tutar istekten değil Hoppa\'dan (karttan çekilen, komisyon dahil)', $r3['success'] && abs((float) $fake->lastRefundAmount - ($price + 2.25)) < 0.005);
    check('iade: paket geri alındı → varsayılan plan, süresiz', $r3['data']['plan']['revoked'] === true && $sel()['plan_name'] === AppConfig::FREE_PLAN_NAME && empty($sel()['expires_at']), json_encode($sel()));

    check('iade: ödenmemiş (hoppa_failed) sipariş → 422', $refund($fake, $oFail)['http'] === 422);

    // Hoppa "başarılı" dedi ama sorgu iadeyi göstermiyor → satır paid kalır, paket kalır.
    $fake->byOrder[$oNew] = $qr('paid', $price, 0.0);
    WalletController::finalizeHostedPlanPayment($db, $fake, $oNew);
    $fake->queue = [$qr('paid', $price, 0.0), $qr('paid', $price, 0.0)];
    $r4 = $refund($fake, $oNew);
    check('iade doğrulanamadı: 422, satır paid, paket duruyor', $r4['http'] === 422 && $row($oNew)['status'] === 'paid' && $sel()['plan_name'] === $plan['name_tr'], json_encode($r4));

    // Hoppa panelinden zaten iade edilmiş: istek gönderilmeden yalnızca eşitlenir.
    $refundsBefore = $fake->refunds;
    $fake->queue = [$qr('refunded', $price, 0.0)];
    $r5 = $refund($fake, $oNew);
    check('Hoppa\'da zaten iade edilmiş: istek gönderilmez, satır refunded', $r5['success'] && $fake->refunds === $refundsBefore && $row($oNew)['status'] === 'refunded');
    check('iade: admin_audit_log sonuçları (dogrulanamadi, tamamlandi)', $outcomes($oNew) === ['dogrulanamadi', 'tamamlandi'], json_encode($outcomes($oNew)));
} finally {
    $conn->rollBack();
}
check('ROLLBACK: B2 siparişleri kalmadı', !$row($oPaid));

// ═════════════════════════════════════════════════════════════════════════
echo "\n=== C) Hoppa TEST ortamı — uçtan uca ===\n\n";

if (!$e2e) {
    echo "  (atlandı — çalıştırmak için --e2e)\n";
    finish();
}

$gw = PaymentGatewayFactory::make('hoppa');
if ($gw === null || !$gw->isTest()) {
    echo "  ATLANDI: api/.env'de HOPPA_MODE=test ve test kimlik bilgileri yok (canlı moda ASLA uçtan uca test yapılmaz).\n";
    finish();
}
$nodePath = (string) env_get('HOPPA_E2E_NODE_PATH', '');
if ($nodePath === '' || !is_dir($nodePath . '/playwright-core')) {
    echo "  ATLANDI: HOPPA_E2E_NODE_PATH playwright-core içeren bir node_modules dizinini göstermiyor.\n";
    finish();
}

$pay = function (string $url, string $card) use ($nodePath): ?array {
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/hoppa_e2e_driver.cjs') . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($card);
    $env = array_merge(getenv(), ['NODE_PATH' => $nodePath]);
    $chrome = (string) env_get('HOPPA_E2E_CHROME', '');
    if ($chrome !== '') {
        $env['HOPPA_E2E_CHROME'] = $chrome;
    }
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        echo "  sürücü hatası: " . trim((string) $err) . "\n";
        return null;
    }
    return json_decode((string) $out, true);
};

$conn->beginTransaction();
try {
    $setPlan(AppConfig::FREE_PLAN_NAME);
    $publicUrl = (string) env_get('APP_PUBLIC_URL', 'http://localhost:3000');

    foreach ([
        ['başarılı', '9792100000000001', 'paid'],
        ['hatalı (51 limit yetersiz)', '5100050000006661', 'failed'],
    ] as [$label, $card, $expect]) {
        $s = WalletController::startHostedPlanPayment($db, $gw, $uid, $plan, $publicUrl);
        check("$label: Hoppa ödeme oturumu açıldı", $s['success'], $s['message']);
        if (!$s['success']) continue;
        $o = $s['order_id'];
        echo "        sipariş $o → " . parse_url($s['redirect_url'], PHP_URL_HOST) . "\n";

        $back = $pay($s['redirect_url'], $card);
        check("$label: BACK_URL'e form POST geldi (bu sipariş)", is_array($back) && ($back['ORDER_REF_NUMBER'] ?? '') === $o, json_encode($back));
        if (is_array($back)) {
            echo "        BACK_URL POST: STATUS={$back['STATUS']} RETURN_CODE={$back['RETURN_CODE']} AMOUNT={$back['AMOUNT']} COMMISSION={$back['COMMISSION']}\n";
            check("$label: BACK_URL kart numarasını maskeli taşıyor", !preg_match('/\d{16}/', (string) ($back['CUSTOMER_CC_NUMBER'] ?? '')));
        }

        $f = WalletController::finalizeHostedPlanPayment($db, $gw, $o);
        $r = $row($o);
        echo "        ProcessQuery ile kesinleşti: {$f['state']} · satır durumu: {$r['status']}" . ($f['message'] !== '' ? " · {$f['message']}" : '') . "\n";
        check("$label: sonuç $expect", $f['state'] === $expect);

        if ($expect === 'paid') {
            $s1 = $sel();
            check('başarılı: paket tanımlandı', $s1['plan_name'] === $plan['name_tr'] && !empty($s1['expires_at']), json_encode($s1));
            $setPlan('__isaret__');
            $f2 = WalletController::finalizeHostedPlanPayment($db, $gw, $o);
            check('başarılı: aynı ORDER_REF_NUMBER ikinci kez işlenmez', $f2['state'] === 'paid' && $sel()['plan_name'] === '__isaret__');

            // Faz 7a-2 — gerçek iade (OrderReturn), admin iade yoluyla aynı fonksiyon.
            $setPlan($plan['name_tr']);
            $rf = hostedPlanRefund($db, $gw, $payRow($o), 'hoppa_selftest --e2e', null, 'hoppa_selftest', '127.0.0.1');
            echo "        İade (OrderReturn): " . ($rf['success'] ? 'başarılı' : 'BAŞARISIZ')
                . ' · Hoppa durumu: ' . ($rf['data']['provider_state'] ?? '-')
                . ' · iade edilen: ' . ($rf['data']['refunded_amount'] ?? '-') . " · {$rf['message']}\n";
            check('iade: Hoppa\'da tam iade/iptal doğrulandı', $rf['success'] && in_array($rf['data']['provider_state'] ?? '', ['cancelled', 'refunded'], true), json_encode($rf, JSON_UNESCAPED_UNICODE));
            check('iade: satır refunded, paket geri alındı', $row($o)['status'] === 'refunded' && $sel()['plan_name'] === AppConfig::FREE_PLAN_NAME);
            check('iade: admin_audit_log\'da "tamamlandi"', in_array('tamamlandi', $outcomes($o), true));
            check('iade tekrar: 409', hostedPlanRefund($db, $gw, $payRow($o), 'tekrar', null, 'hoppa_selftest', '127.0.0.1')['http'] === 409);
            $setPlan(AppConfig::FREE_PLAN_NAME);
        } else {
            check('hatalı: paket değişmedi', $sel()['plan_name'] === AppConfig::FREE_PLAN_NAME);
        }
    }

    // Faz 7a-2 — gerçek mutabakat: oturumu açılmış ama ödenmemiş sipariş.
    $s = WalletController::startHostedPlanPayment($db, $gw, $uid, $plan, $publicUrl);
    $oAb = $s['order_id'];
    $age($oAb, 20);
    $st = hostedPlanReconcile($db, $gw);
    echo "        mutabakat (20 dk): " . json_encode($st) . " · satır: {$row($oAb)['status']}\n";
    check('mutabakat (gerçek): ödenmemiş sipariş 24 saat dolmadan hoppa_pending kalır', $row($oAb)['status'] === WalletController::HOSTED_PENDING);
    $age($oAb, 30 * 60);
    hostedPlanReconcile($db, $gw);
    check('mutabakat (gerçek): 24 saati geçince hoppa_expired', $row($oAb)['status'] === WalletController::HOSTED_EXPIRED);
} finally {
    $conn->rollBack();
}

finish();
