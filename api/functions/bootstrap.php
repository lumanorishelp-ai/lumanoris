<?php
/**
 * Shared bootstrap for all API endpoints.
 * Handles: JSON header, session, DB connection, error handler, response helpers.
 *
 * Usage at top of any endpoint:
 *   require __DIR__ . '/../functions/bootstrap.php';
 */

header('Content-Type: application/json');

// N-31 — oturum kullanmayan, çapraz-site çağrılan uçlar (Hoppa BACK_URL)
// LUMANORIS_STATELESS_ENDPOINT tanımlar: orada session_start() yeni bir
// PHPSESSID set edip kullanıcının gerçek oturum çerezinin üstüne yazıyordu.
if (!defined('LUMANORIS_STATELESS_ENDPOINT') && session_status() === PHP_SESSION_NONE) {
    // Lax (not Strict) because top-level GET navigations into the app must
    // still carry the cookie.
    //
    // J-02 — `secure` bayrağı `$_SERVER['HTTPS']`e bağlanamaz: bu mimaride
    // PHP her zaman proxy arkasında düz HTTP konuşuyor (web/server.js
    // Express ile 127.0.0.1'e proxy'liyor), yani `$_SERVER['HTTPS']` canlıda
    // da BOŞ. Sonuç: site https:// ile yayınlansa bile PHPSESSID hiçbir zaman
    // `Secure` almıyordu ve `SameSite=Lax` üst düzey GET gezinmelerinde
    // çerezi gönderdiği için siteye http:// ile atılan tek bir bağlantı
    // oturum kimliğini ağda açığa çıkarıyordu.
    //
    // `RequestContext` bu dosya autoload zincirinin EN BAŞINDA çalıştığı için
    // henüz kayıtlı değil; doğrudan require ediliyor.
    require_once __DIR__ . '/../src/Shared/Utilities/RequestContext.php';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => RequestContext::isHttps(),
    ]);
    session_start();
}

// Load .env from api/ root. The parser lives in functions/env.php so that
// db.php — which the admin panel requires directly, without bootstrap — sees
// the same configuration (SEC-008).
require_once __DIR__ . '/env.php';
env_load();
require_once __DIR__ . '/logging.php';
configure_error_log();

// ERR-004: bu üç kanca eskiden dosyanın SONUNDA, yani DB bağlantısından SONRA
// kayıtlıydı. Sonuç: .env eksikse ya da veritabanı erişilemezse Database::getInstance()
// henüz hiçbir handler yokken fırlatıyor, PHP display_errors=0 ile hiçbir çıktı üretmiyor
// ve istemci **boş gövdeli 500** alıyordu — canlıda teşhis edilemeyen bir hata. Kayıt
// artık bağlantıdan ÖNCE yapılıyor, böylece aynı arıza düzgün bir JSON hataya dönüşüyor.

// ─── Global exception → JSON response ────────────────────────────────────────

set_exception_handler(function (Throwable $e) {
    error_log('[uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);
    // Only leak the real exception message when APP_DEBUG=true is set (local
    // dev). Otherwise it can expose DB schema, file paths, and other internals
    // to any client that triggers a 500 — full detail is still in error_log.
    $message = env_bool('APP_DEBUG', false)
        ? ('Sunucu hatası: ' . $e->getMessage())
        : 'Sunucu hatası oluştu.';

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

/**
 * ERR-002 🟡 — istisna dışındaki iki hata sınıfı yakalanmıyordu.
 *
 * `set_exception_handler` yalnızca fırlatılan istisnaları görür. PHP uyarıları
 * ve notice'ları (undefined index, division by zero, dosya bulunamadı) hiçbir
 * yere düşmüyordu; en kötüsü de fatal error'lardı: bellek sınırı aşımı,
 * zaman aşımı ya da tanımsız fonksiyon çağrısı yanıtı YARIDA kesiyor ve
 * istemci geçersiz/boş bir JSON alıyordu — durum kodu 200 kalıyordu.
 *
 * Aşağıdaki iki kanca bu boşlukları kapatıyor: uyarılar loglanıp yanıt
 * gövdesinden uzak tutuluyor, fatal error'lar ise shutdown sırasında
 * yakalanıp düzgün bir JSON hataya dönüştürülüyor.
 */
set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    // error_reporting ile bastırılmışsa (@ operatörü dahil) karışma.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $labels = [
        E_WARNING           => 'warning',
        E_NOTICE            => 'notice',
        E_DEPRECATED        => 'deprecated',
        E_USER_WARNING      => 'user-warning',
        E_USER_NOTICE       => 'user-notice',
        E_USER_DEPRECATED   => 'user-deprecated',
        E_RECOVERABLE_ERROR => 'recoverable',
    ];
    $label = $labels[$severity] ?? ('severity-' . $severity);

    error_log(sprintf('[%s] %s in %s:%d', $label, $message, $file, $line));

    // Bilinçli olarak İSTİSNAYA ÇEVİRMİYORUZ. Uyarıyı istisnaya çevirmek
    // temiz görünür ama bugün çalışan yolları kırar: vendor/ altındaki
    // google/apiclient ve smalot/pdfparser PHP 8.1'de deprecation üretiyor;
    // bunları fırlatmak, çalışan PDF ayrıştırma ve Google girişini anında
    // 500'e döndürürdü. Buradaki amaç hatayı GÖRÜNÜR kılmak ve yanıt
    // gövdesine sızmasını engellemek.
    //
    // true dönmek PHP'nin kendi çıktı/log davranışını devre dışı bırakır —
    // display_errors yanlışlıkla açık kalsa bile uyarı JSON gövdesine
    // karışmaz (ERR-002'nin asıl şikâyeti buydu).
    return true;
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatal, true)) {
        return;
    }

    error_log(sprintf(
        '[fatal] %s in %s:%d',
        $error['message'],
        $error['file'],
        $error['line']
    ));

    // SSE gibi yanıtı çoktan başlamış uç noktalarda gövdeye JSON eklemek
    // akışı bozar; yalnızca henüz hiçbir şey gönderilmemişse yaz.
    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => env_bool('APP_DEBUG', false)
            ? ('Ölümcül hata: ' . $error['message'])
            : 'Sunucu hatası oluştu.',
    ], JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/db.php';

$database = Database::getInstance();
$conn     = $database->getConnection();

// ─── Response helpers ─────────────────────────────────────────────────────────

function json_success(array $data = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        json_error('Method not allowed', 405);
    }
}

function require_auth(): int {
    if (!isset($_SESSION['user_id'])) {
        json_error('Oturum açmanız gerekiyor.', 401);
    }
    return (int) $_SESSION['user_id'];
}

function parse_post_data(): array {
    $data = null;
    if (isset($_POST['data'])) {
        $data = json_decode($_POST['data'], true);
    }
    if (!is_array($data)) {
        json_error('Geçersiz istek verisi.', 400);
    }
    return $data;
}

function parse_get_data(): array {
    return $_GET ?? [];
}
