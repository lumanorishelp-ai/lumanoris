<?php
/**
 * Admin — Pazaryeri başvuruları (madde 3, Faz 5; GK-9, GK-21, GK-23, GK-25, GK-26).
 *
 *   GET  ?status=submitted|reviewed|rejected   → liste (tam alanlar, GK-26)
 *   POST id, status=reviewed|rejected, review_note → durum güncelle
 *
 * `_guard.php`: admin oturumu + her POST'ta CSRF (başlıktaki fetch shim'i
 * X-CSRF-Token ekliyor). Tablo genel CRUD beyaz listesine (`update.php`)
 * EKLENMEDİ — bu tek giriş noktası.
 *
 * GK-9: "incelendi" satıcıyı `active` YAPMAZ (B1).
 * GK-23: incelemede başvurudaki IBAN `banka_bilgileri`'ne aktarılır — bekleyen
 * çekim talebi varsa aktarılmaz; her değişiklik `admin_audit_log`'a (017)
 * maskeli yazılır. Kurallar `reviewMarketplaceApplication()`'da.
 * GK-25: sonuç kullanıcıya `notifications` tablosu üzerinden bildirilir.
 */
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../functions/db.php';
// Admin uçları autoload yüklemiyor; reviewMarketplaceApplication() bu istisnaları atıyor.
require_once __DIR__ . '/../../src/Shared/Exceptions/AppException.php';
require_once __DIR__ . '/../../functions/marketplace_application.php';

header('Content-Type: application/json; charset=utf-8');

$database = Database::getInstance();

function basvuru_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!marketplaceApplicationsReady($database)) {
    basvuru_json(['success' => false, 'message' => 'marketplace_applications tablosu yok — migration 016 uygulanmamış.'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $where  = '';
    $params = [];
    if (in_array($status, ['submitted', 'reviewed', 'rejected'], true)) {
        $where    = 'WHERE m.status = ?';
        $params[] = $status;
    }

    $rows = $database->selectMulti(
        "m.*, k.kullanici_adi, k.eposta,
                b.iban AS current_iban,
                (SELECT COUNT(*) FROM para_cekme_talepleri t
                  WHERE t.user_id = m.user_id AND t.durum = 'beklemede') AS pending_withdrawals
         FROM marketplace_applications m
         LEFT JOIN kullanicilar k ON k.id = m.user_id
         LEFT JOIN banka_bilgileri b ON b.user_id = m.user_id
         $where
         ORDER BY FIELD(m.status, 'submitted', 'rejected', 'reviewed'), m.updated_at DESC
         LIMIT 200",
        $params
    );

    foreach ($rows as &$r) {
        $r['pending_withdrawals'] = (int) $r['pending_withdrawals'];
        $r['iban_differs']        = ($r['current_iban'] ?? null) !== null
            && preg_replace('/\s+/', '', (string) $r['current_iban']) !== $r['iban'];
    }
    unset($r);

    basvuru_json(['success' => true, 'applications' => $rows, 'iban_transfer_enabled' => true]);
}

if ($method !== 'POST') {
    basvuru_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
$note   = trim(mb_substr(strip_tags((string) ($_POST['review_note'] ?? '')), 0, 1000));

if ($id <= 0 || !in_array($status, ['reviewed', 'rejected'], true)) {
    basvuru_json(['success' => false, 'message' => 'Geçersiz istek.'], 400);
}
$adminId   = isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
$adminName = (string) ($_SESSION['admin'] ?? '');
$conn      = $database->getConnection();

// Durum + IBAN aktarımı + işlem logu + bildirim tek transaction'da.
$conn->beginTransaction();
try {
    $result = reviewMarketplaceApplication(
        $database, $id, $status, $note, $adminId, $adminName, $_SERVER['REMOTE_ADDR'] ?? null
    );
    $conn->commit();
} catch (ValidationException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    basvuru_json(['success' => false, 'message' => $e->getMessage()], 400);
} catch (NotFoundException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    basvuru_json(['success' => false, 'message' => $e->getMessage()], 404);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[admin/basvurular] durum güncellenemedi id=' . $id . ': ' . $e->getMessage());
    basvuru_json(['success' => false, 'message' => 'Durum güncellenemedi.'], 500);
}

error_log(sprintf('[admin/basvurular] başvuru id=%d durum=%s iban=%s admin_id=%s', $id, $status, $result['iban'], $adminId ?? '-'));

$ibanMessages = [
    'updated'                    => " Başvurudaki IBAN, kullanıcının para çekme IBAN'ı olarak kaydedildi (işlem loglandı).",
    'unchanged'                  => ' IBAN zaten aynıydı; değişiklik yok.',
    'blocked_pending_withdrawal' => ' DİKKAT: kullanıcının bekleyen para çekme talebi olduğu için IBAN GÜNCELLENMEDİ. Talep sonuçlandıktan sonra başvuruyu yeniden inceleyin.',
    'not_applicable'             => '',
];

basvuru_json([
    'success' => true,
    'iban'    => $result['iban'],
    'message' => 'Başvuru güncellendi; kullanıcıya bildirim gönderildi.' . ($ibanMessages[$result['iban']] ?? ''),
]);
