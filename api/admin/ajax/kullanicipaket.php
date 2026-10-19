<?php
/**
 * N-64 — Admin → Kullanıcılar → "Paket".
 *
 *   GET  ?user_id=N   → etkin paket, durum, bitiş, kaynak (satın alma / admin
 *                       ataması / ödemesiz eski kayıt), katalog
 *   POST user_id, plan_name, duration=30|unlimited|date, date=YYYY-MM-DD
 *                     → paketi atar; admin_audit_log'a `plan_assign` (eski/yeni)
 *
 * Yalnızca admin oturumu; POST'ta CSRF (_guard.php). Ödeme/iade akışına
 * dokunmaz: tahsilat yok, iade yok.
 */
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../functions/plans.php';

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = (int) (($method === 'POST' ? $_POST['user_id'] : $_GET['user_id']) ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Kullanıcı seçilmedi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $planName = trim((string) ($_POST['plan_name'] ?? ''));
    $duration = (string) ($_POST['duration'] ?? '30');
    $expires  = match ($duration) {
        'unlimited' => null,
        'date'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? $_POST['date'] . ' 23:59:59' : '',
        // Bitiş MySQL saatinden (finalizeHostedPlanPayment ile aynı): PHP'nin
        // saat dilimi veritabanınınkinden farklı olabiliyor (yerelde UTC ↔ +03).
        default     => (string) $db->selectSingle('DATE_ADD(NOW(), INTERVAL 30 DAY) AS e')['e'],
    };
    if ($expires === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçerli bir bitiş tarihi seçin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Ücretsiz paket her zaman süresiz.
    $free = $db->selectSingle('name_tr FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1');
    if ($free && $planName === $free['name_tr']) {
        $expires = null;
    }

    $admin = [
        'id'   => isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
        'name' => (string) ($_SESSION['admin'] ?? ''),
        'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    $r = adminAssignPlan($db, $userId, $planName, $expires, $admin);
    error_log(sprintf('[admin/kullanicipaket] user_id=%d plan=%s bitiş=%s sonuç=%s admin=%s',
        $userId, $planName, $expires ?? 'süresiz', $r['ok'] ? 'ok' : $r['message'], $admin['name'] ?: '-'));
    if (!$r['ok']) {
        http_response_code(422);
    }
    echo json_encode(['success' => $r['ok'], 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

$sub = getUserSubscription($db, $userId, true);
echo json_encode([
    'success'  => true,
    'plan'     => $sub['plan'],
    'state'    => $sub['state'],
    'is_paid'  => $sub['is_paid'],
    'expires'  => $sub['expires_at'],
    'days_left'=> $sub['days_left'],
    'source'   => getPlanSource($db, $userId, $sub),
    'catalog'  => array_column(getPlanCatalog($db), 'name_tr'),
], JSON_UNESCAPED_UNICODE);
