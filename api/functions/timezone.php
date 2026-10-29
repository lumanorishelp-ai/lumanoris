<?php
/**
 * N-69 — uygulamanın TEK saat dilimi.
 *
 * PHP ile MySQL farklı saat dilimlerinde çalışıyordu (yerelde PHP UTC, MySQL
 * UTC+3). Bazı zamanlar PHP `date()` ile, bazıları MySQL `NOW()` ile yazıldığı
 * için karşılaştırmalar saatlerce kayıyordu: Hoppa ile ödenmiş paket admin'de
 * "Ödemesiz kayıt" görünüyordu (getPlanSource), coin yenilenme saati yanlış
 * hesaplanıyordu (MessageController).
 *
 * Kural: `APP_TIMEZONE` (api/.env; yoksa Europe/Istanbul). PHP buna ayarlanır;
 * her veritabanı bağlantısında MySQL oturumunun time_zone'u aynı ofsete
 * eşitlenir (functions/db.php). Sunucunun genel MySQL ayarına dokunulmaz —
 * paylaşımlı hostingde zaten değiştirilemiyor. Ofset (+03:00) kullanılıyor,
 * ad değil: MySQL'de saat dilimi tabloları yüklü olmayabilir.
 */

require_once __DIR__ . '/env.php';

const APP_TIMEZONE_DEFAULT = 'Europe/Istanbul';

function app_timezone(): string
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }
    env_load();
    $raw = trim((string) (env_get('APP_TIMEZONE') ?? ''));
    if ($raw === '') {
        return $tz = APP_TIMEZONE_DEFAULT;
    }
    try {
        new DateTimeZone($raw);
        return $tz = $raw;
    } catch (Throwable $e) {
        error_log('[timezone] APP_TIMEZONE geçersiz ("' . $raw . '"), ' . APP_TIMEZONE_DEFAULT . ' kullanılıyor.');
        return $tz = APP_TIMEZONE_DEFAULT;
    }
}

/** PHP'yi uygulamanın saat dilimine alır. İdempotent. */
function app_timezone_init(): void
{
    if (date_default_timezone_get() !== app_timezone()) {
        date_default_timezone_set(app_timezone());
    }
}

/** Şu anki ofset, MySQL `SET time_zone` biçiminde: "+03:00". */
function app_timezone_offset(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(app_timezone())))->format('P');
}
