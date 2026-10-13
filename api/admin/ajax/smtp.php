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

/**
 * I-05 — bu dört alan HİÇBİR doğrulamadan geçmeden `global_vars`'a yazılıyor,
 * oradan da doğrudan SMTP komutlarına ve `From:` başlığına giriyordu
 * (`smtp_client.php`: `MAIL FROM:<$from[email]>`, `From: … <$from[email]>`).
 * Değerin içindeki bir CR/LF, SMTP oturumuna KENDİ komutlarını enjekte etme
 * imkânı verir (ör. fazladan RCPT TO ile açık röle). Panel admin arkasında
 * ama tek bir XSS/CSRF ya da yetkisi fazla bir operatör bunu sunucumuzdan
 * spam gönderme yoluna çevirebilir.
 *
 * Doğrulama burada; `smtp_client.php`'de de savunma derinliği olarak CR/LF
 * reddi var (iki taraf birbirinden bağımsız).
 */
$fail = static function (string $message): void {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

$hasCrlf = static fn(string $v): bool => strpbrk($v, "\r\n") !== false;

$host  = trim((string) ($_POST['smtp_host'] ?? ''));
$email = trim((string) ($_POST['smtp_email'] ?? ''));
$pass  = (string) ($_POST['smtp_pass'] ?? '');
$name  = trim((string) ($_POST['smtp_name'] ?? ''));

foreach (['SMTP Host' => $host, 'SMTP Email' => $email, 'SMTP Şifre' => $pass, 'Gönderici Adı' => $name] as $label => $value) {
    if ($hasCrlf($value)) {
        $fail("$label alanı satır sonu karakteri içeremez.");
    }
}

// Host: ana bilgisayar adı ya da IP, isteğe bağlı ":port" ekiyle
// (mailerConfig() bu biçimi ayrıştırıyor).
if ($host !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:\d{1,5})?$/', $host)) {
    $fail('SMTP Host geçerli bir sunucu adı değil (örn. smtp.ornek.com veya smtp.ornek.com:587).');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('SMTP Email geçerli bir e-posta adresi değil.');
}

$updateData = [
    'smtp_host'  => $host,
    'smtp_email' => $email,
    'smtp_name'  => $name,
];

// I-06 — parola alanı artık forma ÖNCEDEN DOLDURULMUYOR (bkz. admin/smtp.php).
// Boş gelmesi "değiştirme" demek; aksi hâlde her kaydetme parolayı silerdi.
if ($pass !== '') {
    $updateData['smtp_pass'] = $pass;
}

// updateGlobalVars fonksiyonunu çağır
// Fonksiyonun 'Güncelleme işlemi başarılı!' veya 'Güncelleme başarısız oldu: ...' şeklinde string döndürdüğünü varsayıyoruz.
$resultMessage = $database->updateGlobalVars($updateData);

// Yanıtı, fonksiyonun döndürdüğü stringe göre hazırla
if (strpos($resultMessage, 'başarılı') !== false) {
    echo json_encode([
        "status" => "success",
        "message" => "SMTP ayarları başarıyla güncellendi!" // UI için daha temiz bir mesaj
    ]);
} else {
    // Fonksiyonun hata mesajını döndür
    echo json_encode([
        "status" => "error",
        "message" => $resultMessage // Fonksiyondan gelen detaylı hata mesajı
    ]);
}
?>