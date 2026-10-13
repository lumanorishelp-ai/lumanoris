<?php
require_once __DIR__ . '/_guard.php';
// Gerekli dosyaları dahil et
require __DIR__ . '/../../functions/db.php'; // N-21
$database = Database::getInstance();
$conn = $database->getConnection();

header('Content-Type: application/json');

if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Yetkisiz erişim."]);
    exit;
}

// POST isteği kontrolü
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Geçersiz istek metodu."]);
    exit;
}

$updateData = [
    'offline' => $_POST['offline'] ?? '0' // Checkbox'tan gelen ('0' veya '1')
];

// updateGlobalVars fonksiyonunu çağır
$resultMessage = $database->updateGlobalVars($updateData);

// Yanıtı, fonksiyonun döndürdüğü stringe göre hazırla
if (strpos($resultMessage, 'başarılı') !== false) {
    // Bakım modu aktif ise özel mesaj
    $message = ($_POST['offline'] ?? '0') === '1' 
        ? "Bakım modu başarıyla AKTİF edildi!" 
        : "Bakım modu başarıyla PASİF edildi!";
        
    echo json_encode([
        "status" => "success",
        "message" => $message 
    ]);
} else {
    // Fonksiyonun hata mesajını döndür
    echo json_encode([
        "status" => "error",
        "message" => $resultMessage // Fonksiyondan gelen detaylı hata mesajı
    ]);
}
?>