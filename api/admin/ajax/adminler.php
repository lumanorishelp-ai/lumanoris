<?php
require_once __DIR__ . '/_guard.php';
require __DIR__ . '/../../functions/db.php'; // N-21
require_once __DIR__ . '/../functions/admin_login.php';
$database = Database::getInstance();
$conn = $database->getConnection();

header('Content-Type: application/json');

if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Yetkisiz erişim."]);
    exit;
}

// Admin hesabı api/.env'den okunuyorsa `adminler` tablosu artık girişte hiç
// kullanılmıyor. Bu ekrandan yazmaya izin vermek, hiçbir işe yaramayan ama
// "admin eklendi" diyen bir kayıt üretirdi — üstelik env yapılandırması geri
// alınırsa o kayıt aniden geçerli bir giriş hesabına dönerdi. Fail-closed.
if (admin_env_account() !== null) {
    http_response_code(409);
    echo json_encode([
        "status"  => "error",
        "message" => "Admin hesabı api/.env dosyasından yönetiliyor (ADMIN_USERNAME). "
                   . "Değiştirmek için .env dosyasını güncelleyin.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';
$id = (int) ($_POST['id'] ?? 0);
$kullanici_adi = $_POST['kullanici_adi'] ?? '';
$sifre = $_POST['sifre'] ?? '';

if ($action === 'get') {
    if ($id <= 0) {
        echo json_encode(["status" => "error", "message" => "Geçersiz admin ID."]);
        exit;
    }

    $admin = $database->selectSingle("* FROM adminler WHERE id = ?", [$id]);

    if ($admin) {
        echo json_encode([
            "status" => "success",
            "data" => [
                "id" => $admin['id'],
                "kullanici_adi" => $admin['kullanici_adi']
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Admin bulunamadı."]);
    }
    exit;
}

if ($action === 'create' || $action === 'update') {
    if (empty($kullanici_adi) || empty($sifre)) {
        echo json_encode(["status" => "error", "message" => "Tüm alanlar doldurulmalıdır."]);
        exit;
    }

    $existingAdmin = $database->selectSingle("* FROM adminler WHERE kullanici_adi = ?", [$kullanici_adi]);

    if ($existingAdmin && $existingAdmin['id'] != $id) {
        echo json_encode(["status" => "error", "message" => "Bu kullanıcı adı zaten kayıtlı!"]);
        exit;
    }

    $hashed = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
    $data = ['kullanici_adi' => $kullanici_adi, 'sifre' => $hashed];

    if ($action === 'update' && $id > 0) {
        $result = $database->update("adminler", $data, "id = $id");
        $message = $result ? "Admin başarıyla güncellendi." : "Güncelleme başarısız.";
    } else {
        $result = $database->insert("adminler", $data);
        $message = $result ? "Admin başarıyla eklendi." : "Ekleme başarısız.";
    }

    echo json_encode(["status" => $result ? "success" : "error", "message" => $message]);
    exit;
}

if ($action === 'delete') {
    if ($database->count("adminler") <= 1) {
        echo json_encode(["status" => "error", "message" => "Sitede en az bir admin olmak zorundadır!"]);
        exit;
    }

    $result = $database->delete("adminler", "id = $id");
    echo json_encode([
        "status" => $result ? "success" : "error",
        "message" => $result ? "Admin silindi." : "Silme işlemi başarısız."
    ]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Geçersiz işlem."]);