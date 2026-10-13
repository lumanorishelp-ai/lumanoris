<?php
/**
 * Plan / kota tek doğruluk kaynağı.
 *
 * BIZ-002 🟠 / UX-002 🟡 — plan sistemi üç ayrı yerde, birbirinden habersiz
 * çalışıyordu:
 *
 *   1. `WalletController::getPricing()` dört planı fiyatlarıyla birlikte PHP
 *      dizisi olarak döndürüyordu (katalog kodda, `plans` tablosu 0 satır).
 *   2. `UserController::getUser()` dashboard başlığı için
 *      `user_plan_selection.plan_name` serbest metnini okuyordu.
 *   3. `chatbot_limits.php` bir stub'dı ve plana hiç bakmadan herkese
 *      `AppConfig::FREE_*` (1 bağımsız / 2 herkese açık) döndürüyordu.
 *
 * Sonuç UX-002: dashboard başlığı "Elmas" derken bot ekranı 1/2 gösteriyordu.
 * İkisi de doğruydu — sadece farklı şeylere bakıyorlardı.
 *
 * BIZ-003 — "üretici planı hiç var olamıyor" iddiasının şema karşılığı:
 * `producer_plans` tablosu VAR ve yapısal olarak bir satır tutabilir
 * (`user_id` UNIQUE + `started_at`/`expires_at`). Var olamamasının sebebi
 * şema değil, iki başka şey: (a) `buyProducerAccount()` fail-closed bir stub,
 * yani satır yazacak yol yok; (b) tabloda plan referansı yok — "hangi üretici
 * planı" sorusunun cevabı tasarım gereği saklanamıyor, tablo yalnızca
 * "şu tarihe kadar üretici planı var" diyebiliyor.
 *
 * Bu dosya (1), (2) ve (3)'ü tek kaynağa bağlıyor: `plans` tablosu.
 * Tablo boşsa ya da migration 007 uygulanmamışsa AppConfig'in bugünkü
 * değerlerine düşüyor — yani kurulum sırası ne olursa olsun davranış
 * değişmiyor.
 */

require_once __DIR__ . '/db.php';

/**
 * `plans` tablosu kullanılabilir durumda mı? (Tablo var, kota sütunları
 * eklenmiş ve en az bir satır var.)
 */
function plansTableReady(Database $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $row = $db->selectSingle(
            "COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans'
               AND COLUMN_NAME = 'daily_message_limit'"
        );
        if ((int) ($row['cnt'] ?? 0) === 0) {
            return $ready = false;
        }
        $row = $db->selectSingle('COUNT(*) AS cnt FROM plans');
        return $ready = ((int) ($row['cnt'] ?? 0) > 0);
    } catch (Throwable $e) {
        error_log('[plans] tablo kontrolü başarısız: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * D-05 — `user_plan_selection.expires_at` sütunu var mı? (migration 011)
 *
 * Sürüm farkını tolere ediyoruz: migration uygulanmamış bir kurulumda
 * sorgu SQL 1054 ile patlayıp `getUserPlan()`'ı fallback'e düşürürdü —
 * yani Elmas plandaki herkes sessizce ücretsiz kotalara inerdi. Kontrol
 * istek başına bir kez yapılıp hatırlanıyor.
 */
function planSelectionHasExpiry(Database $db): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $row = $db->selectSingle(
            "COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plan_selection'
               AND COLUMN_NAME = 'expires_at'"
        );
        return $has = ((int) ($row['cnt'] ?? 0) === 1);
    } catch (Throwable $e) {
        error_log('[plans] expires_at kontrolü başarısız: ' . $e->getMessage());
        return $has = false;
    }
}

/**
 * AppConfig'e dayanan geri düşüş planı. Migration 007 uygulanmadan önceki
 * davranışın birebir aynısı.
 *
 * @return array<string,mixed>
 */
function fallbackPlan(): array
{
    return [
        'id'                    => null,
        // E-05 — burası 'Ücretsiz Plan' idi, `plans` tablosuna seed edilen ad
        // ise 'Ücretsiz' (007_plan_limits.sql:74). Aynı planın iki adı
        // olduğu için `getPricing()`'in `is_current` karşılaştırması ve
        // frontend'in "Pro rozeti" kontrolü yanlış cevap veriyordu. Tek
        // kaynak artık AppConfig::FREE_PLAN_NAME.
        'name_tr'               => AppConfig::FREE_PLAN_NAME,
        'monthly_price'         => 0.0,
        'independent_bot_limit' => AppConfig::FREE_INDEPENDENT_BOT_LIMIT,
        'public_bot_limit'      => AppConfig::FREE_PUBLIC_BOT_LIMIT,
        'daily_message_limit'   => AppConfig::DAILY_FREE_MESSAGES,
        'privacy_right_limit'   => AppConfig::FREE_PRIVACY_RIGHT_LIMIT,
        'is_default'            => 1,
        'source'                => 'appconfig-fallback',
    ];
}

/**
 * Kullanıcının etkin planı. Plan seçmemişse (ya da seçtiği plan katalogda
 * yoksa) varsayılan plan döner.
 *
 * @return array<string,mixed>
 */
function getUserPlan(Database $db, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    if (!plansTableReady($db)) {
        return $cache[$userId] = fallbackPlan();
    }

    try {
        // `user_plan_selection.plan_name` serbest metin ve plana FK'sı yok;
        // ad üzerinden eşleştiriyoruz (007 `plans.name_tr`'ye UNIQUE koydu).
        //
        // D-05 — süre kontrolü. Bu satır eskiden KOŞULSUZ okunuyordu: bir
        // kez ödeyen kullanıcı sonsuza kadar o planda kalıyordu, oysa
        // paket "aylık" fiyatla satılıyor. Paket 30 günlük tek seferlik
        // bir satış; süresi dolunca kullanıcı aşağıdaki varsayılan
        // (ücretsiz) plana düşer ve dilerse elle yeniden satın alır.
        //
        // `expires_at IS NULL` = SÜRESİZ: sütun eklenmeden önce yazılmış
        // satırlar geriye dönük iptal edilmiyor.
        $plan = planSelectionHasExpiry($db)
            ? $db->selectSingle(
                'p.* FROM user_plan_selection ups
                 JOIN plans p ON p.name_tr = ups.plan_name
                 WHERE ups.user_id = ?
                   AND (ups.expires_at IS NULL OR ups.expires_at > NOW())',
                [$userId]
            )
            : $db->selectSingle(
                'p.* FROM user_plan_selection ups
                 JOIN plans p ON p.name_tr = ups.plan_name
                 WHERE ups.user_id = ?',
                [$userId]
            );

        if (!$plan) {
            $plan = $db->selectSingle('* FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1')
                ?: $db->selectSingle('* FROM plans ORDER BY sort_order LIMIT 1');
        }

        if (!$plan) {
            return $cache[$userId] = fallbackPlan();
        }

        $plan['source'] = 'plans-table';
        return $cache[$userId] = $plan;
    } catch (Throwable $e) {
        error_log('[plans] getUserPlan başarısız: ' . $e->getMessage());
        return $cache[$userId] = fallbackPlan();
    }
}

/** Kullanıcıya gösterilecek plan adı — başlık ve limit ekranı aynı kaynaktan. */
function getUserPlanName(Database $db, int $userId): string
{
    return (string) (getUserPlan($db, $userId)['name_tr'] ?? AppConfig::FREE_PLAN_NAME);
}

/** Tüm katalog — `getPricing()` için. */
function getPlanCatalog(Database $db): array
{
    if (!plansTableReady($db)) {
        return [];
    }

    try {
        $plans = $db->selectMulti('* FROM plans ORDER BY sort_order, id');
        foreach ($plans as &$p) {
            $p['features'] = array_column(
                $db->selectMulti('feature_tr FROM plan_icerikler WHERE plan_id = ? ORDER BY id', [$p['id']]),
                'feature_tr'
            );
        }
        return $plans;
    } catch (Throwable $e) {
        error_log('[plans] katalog okunamadı: ' . $e->getMessage());
        return [];
    }
}

/**
 * Günlük ücretsiz mesaj (coin) hakkı.
 *
 * Bilinçli olarak plans.php'de, chatbot_limits.php'de değil: coin_engine.php
 * de bunu çağırıyor ve bot limitleriyle ilgisi yok. chatbot_limits.php'ye
 * konduğunda coin motoru "Call to undefined function getDailyMessageLimit()"
 * ile düşüyordu — canlı testte yakalandı.
 */
function getDailyMessageLimit(Database $db, int $userId): int
{
    return (int) getUserPlan($db, $userId)['daily_message_limit'];
}
