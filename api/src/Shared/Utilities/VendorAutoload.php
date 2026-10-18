<?php
/**
 * Composer bağımlılıklarını (api/vendor/) güvenle yükler — N-56.
 *
 * Canlıda belge yükleme şu hatayla düştü:
 *   "Sunucu hatası: Failed opening required '…/src/Presentation/Controllers/
 *    ../../../vendor/autoload.php'"
 * PHP 8'de bulunamayan dosyaya `require_once` bir \Error fırlatır; controller
 * içindeki "sınıf kurulu mu?" kontrolüne hiç gelinmiyor, istek global
 * yakalayıcıya düşüyordu. Bu sınıf dosyanın varlığını, yüklenmesini ve
 * gereken sınıfları tek yerde denetler; ayrıntıyı (yol, eksik sınıf, çözüm)
 * loga yazar, kullanıcıya yalnızca anlaşılır mesaj gider.
 *
 * vendor/ git'te değildir; canlıya deploy paketiyle gider
 * (scripts/build-backend-zip.sh, README → "Backend deploy (cPanel)").
 */
final class VendorAutoload {
    /** Belge (PDF) okuma — TrainingController::readPdf. */
    public const PDF_CLASSES = [\Smalot\PdfParser\Parser::class];

    /** Google ile giriş — AuthController::loginGoogle. */
    public const GOOGLE_CLASSES = ['Google_Client'];

    public static function path(): string {
        return dirname(__DIR__, 3) . '/vendor/autoload.php';
    }

    /**
     * @param string   $feature log etiketi (ör. "readpdf")
     * @param string[] $classes yüklenmesi gereken sınıflar (eski/eksik vendor tespiti)
     * @param ?string  $path    test için; varsayılan api/vendor/autoload.php
     */
    public static function load(string $feature, array $classes = [], ?string $path = null): bool {
        $path ??= self::path();
        $fix = 'api/ dizininde "composer install --no-dev --optimize-autoloader" çalıştırın '
             . 'ya da vendor/ içeren deploy paketini yükleyin (README → Backend deploy).';

        if (!is_file($path)) {
            error_log("[vendor] $feature: autoload dosyası yok: $path — $fix");
            return false;
        }
        try {
            require_once $path;
        } catch (\Throwable $e) {
            error_log("[vendor] $feature: autoload yüklenemedi ($path): " . get_class($e) . ': ' . $e->getMessage() . " — $fix");
            return false;
        }
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                error_log("[vendor] $feature: sınıf bulunamadı: $class (vendor eski ya da eksik, $path) — $fix");
                return false;
            }
        }
        return true;
    }

    /** load() başarısızsa 503 + anlaşılır mesajla yanıtı bitirir. */
    public static function requireOrFail(string $feature, array $classes, string $userMessage): void {
        if (!self::load($feature, $classes)) {
            JsonResponse::error($userMessage, 503, AppConfig::ERR_UNAVAILABLE);
        }
    }
}
