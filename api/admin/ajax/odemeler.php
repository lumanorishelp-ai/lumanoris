<?php
/**
 * Admin — Hoppa paket ödemeleri ve iade (Faz 7a-3).
 *
 *   GET  ?status=paid|refunded|hoppa_pending|hoppa_expired|hoppa_failed&email=…  → liste
 *   POST payment_id, reason                                                    → tam iade
 *
 * `_guard.php`: admin oturumu + her POST'ta CSRF (başlıktaki fetch shim'i
 * X-CSRF-Token ekliyor) — `basvurular.php` ile aynı desen.
 *
 * İade `hostedPlanRefund()` ile yapılıyor (kilit, ProcessQuery doğrulaması,
 * paket geri alma, admin_audit_log). Bu ekran iadenin TEK giriş noktası:
 * eski API iade ucu (`/api/seller/marketplace_refund.php`) CSRF denetlemediği
 * için kapatıldı (N-22, 410).
 *
 * Yalnızca Hoppa ile satılmış PAKET ödemeleri listelenir ve iade edilir;
 * pazaryeri ve iyzico satırları bu ekranda yok (isHostedPlanPayment).
 */
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../functions/hosted_plan_payments.php';

header('Content-Type: application/json; charset=utf-8');

function odeme_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$database = Database::getInstance();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Hoppa paket satırlarının SQL ön süzgeci; kesin kontrol isHostedPlanPayment().
const HOPPA_PLAN_ROW_SQL = "p.order_id LIKE 'PLN-%'
     AND p.param_response_json LIKE '%\"provider\":\"hoppa\"%'
     AND p.items_json LIKE '%\"plan_name\"%'";

if ($method === 'GET') {
    $statuses = ['paid', 'refunded', WalletController::HOSTED_PENDING, WalletController::HOSTED_EXPIRED, WalletController::HOSTED_FAILED];
    $where    = [HOPPA_PLAN_ROW_SQL];
    $params   = [];

    $status = (string) ($_GET['status'] ?? '');
    if (in_array($status, $statuses, true)) {
        $where[]  = 'p.status = ?';
        $params[] = $status;
    }
    $email = trim(mb_substr((string) ($_GET['email'] ?? ''), 0, 190));
    if ($email !== '') {
        $where[]  = 'k.eposta LIKE ?';
        $params[] = '%' . addcslashes($email, '%_\\') . '%';
    }

    $rows = $database->selectMulti(
        'p.id, p.order_id, p.user_id, p.status, p.amount, p.items_json, p.param_response_json,
                p.created_at, p.updated_at, k.kullanici_adi, k.eposta
           FROM param_marketplace_payments p
           LEFT JOIN kullanicilar k ON k.id = p.user_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY p.id DESC
          LIMIT 300',
        $params
    );

    $out = [];
    foreach ($rows as $r) {
        if (!isHostedPlanPayment($r)) {
            continue;
        }
        $meta  = json_decode((string) $r['param_response_json'], true) ?: [];
        $item  = json_decode((string) $r['items_json'], true)[0] ?? [];
        $out[] = [
            'id'            => (int) $r['id'],
            'order_id'      => $r['order_id'],
            'user_id'       => (int) $r['user_id'],
            'kullanici_adi' => $r['kullanici_adi'],
            'eposta'        => $r['eposta'],
            'plan_name'     => (string) ($item['plan_name'] ?? ''),
            'amount'        => (float) $r['amount'],
            'status'        => $r['status'],
            'test'          => (bool) ($meta['test'] ?? false),
            'created_at'    => $r['created_at'],
            'updated_at'    => $r['updated_at'],
        ];
    }

    odeme_json(['success' => true, 'payments' => $out]);
}

if ($method !== 'POST') {
    odeme_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$reason    = trim(mb_substr(strip_tags((string) ($_POST['reason'] ?? '')), 0, 500));
if ($paymentId <= 0) {
    odeme_json(['success' => false, 'message' => 'Geçersiz istek.'], 400);
}

$payment = $database->selectSingle('* FROM param_marketplace_payments WHERE id = ?', [$paymentId]);
if (!isHostedPlanPayment($payment ?: null)) {
    odeme_json(['success' => false, 'message' => 'Bu ekran yalnızca Hoppa ile satılmış paket ödemelerini iade eder.'], 400);
}

$gateway = PaymentGatewayFactory::make();
if ($gateway === null) {
    odeme_json(['success' => false, 'message' => 'İade yapılamıyor: ödeme sağlayıcısı (Hoppa) yapılandırılmamış.'], 503);
}

$adminId   = isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
$adminName = (string) ($_SESSION['admin'] ?? '');

$r = hostedPlanRefund($database, $gateway, $payment, $reason, $adminId, $adminName, $_SERVER['REMOTE_ADDR'] ?? null);

error_log(sprintf('[admin/odemeler] iade payment_id=%d sonuc=%s admin_id=%s', $paymentId, $r['success'] ? 'ok' : $r['http'], $adminId ?? '-'));

odeme_json([
    'success' => $r['success'],
    'message' => $r['message'],
    'refund'  => $r['data'],
], $r['success'] ? 200 : $r['http']);
