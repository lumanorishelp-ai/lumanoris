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
/**
 * N-69 — veritabanının şimdiki zamanı. Plan seçim anı `expires_at` ve sipariş
 * `created_at` ile aynı saatten yazılsın diye (PHP date() değil).
 */
function planSelectionNow(Database $db): string
{
    return (string) $db->selectSingle('NOW() AS n')['n'];
}

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
        //
        // N-49 — açık COLLATE: canlıda plan_name unicode_ci, name_tr
        // general_ci kaldı ve JOIN 1267 ile patlıyordu; getUserPlan ücretsiz
        // plana düşüyordu (paket alan kullanıcı ücretsiz görünürdü). Açık
        // COLLATE iki tarafın örtük collation'ından bağımsız çalışır (ikisi de
        // utf8mb4). Kalıcı şema düzeltmesi: migrations/018.
        $plan = planSelectionHasExpiry($db)
            ? $db->selectSingle(
                'p.* FROM user_plan_selection ups
                 JOIN plans p ON p.name_tr = ups.plan_name COLLATE utf8mb4_general_ci
                 WHERE ups.user_id = ?
                   AND (ups.expires_at IS NULL OR ups.expires_at > NOW())',
                [$userId]
            )
            : $db->selectSingle(
                'p.* FROM user_plan_selection ups
                 JOIN plans p ON p.name_tr = ups.plan_name COLLATE utf8mb4_general_ci
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

/**
 * N-71 — bir paketin günlük coin hakkı, ADINDAN ve önbelleksiz. Kesinleştirme
 * aynı istekte eski ve yeni paketin hakkını okuyor; getUserPlan() istek
 * başına önbellekli olduğu için orada eski değeri döndürürdü.
 * null / katalogda olmayan ad → varsayılan (ücretsiz) paket.
 */
function planDailyLimit(Database $db, ?string $planName): int
{
    if (plansTableReady($db)) {
        $row = $planName !== null && $planName !== ''
            ? $db->selectSingle('daily_message_limit FROM plans WHERE name_tr = ? COLLATE utf8mb4_general_ci', [$planName])
            : null;
        $row = $row ?: $db->selectSingle('daily_message_limit FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1');
        if ($row) {
            return (int) $row['daily_message_limit'];
        }
    }
    return (int) fallbackPlan()['daily_message_limit'];
}

/**
 * N-63 — "Aboneliğim": görüntüleme ve paket sayfası (getPricing) aynı
 * kaynaktan okur. Etkin plan kuralı getUserPlan() ile aynı: ücretli paket
 * `expires_at` geçmişse etkin değildir; `expires_at IS NULL` süresizdir.
 *
 * @return array{state: string, plan: string, is_paid: bool, started_at: ?string,
 *   expires_at: ?string, days_left: ?int, last_paid_plan: ?string, expired_at: ?string,
 *   history: array<int, array{order_id: string, plan: string, amount: float, status: string, status_label: string, created_at: string}>}
 */
function getUserSubscription(Database $db, int $userId, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$userId])) {
        return $cache[$userId];
    }

    $default = $db->selectSingle('name_tr FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1');
    $freeName = (string) ($default['name_tr'] ?? AppConfig::FREE_PLAN_NAME);
    $out = [
        'state' => 'free', 'plan' => $freeName, 'is_paid' => false,
        'started_at' => null, 'expires_at' => null, 'days_left' => null, 'seconds_left' => null,
        'last_paid_plan' => null, 'expired_at' => null, 'history' => [],
    ];

    $row = $db->selectSingle(
        'ups.plan_name, ups.selected_at, ' . (planSelectionHasExpiry($db) ? 'ups.expires_at' : 'NULL AS expires_at') . ',
                p.monthly_price, p.name_tr,
                ' . (planSelectionHasExpiry($db) ? '(ups.expires_at IS NULL OR ups.expires_at > NOW())' : '1') . ' AS is_live,
                ' . (planSelectionHasExpiry($db) ? 'TIMESTAMPDIFF(SECOND, NOW(), ups.expires_at)' : 'NULL') . ' AS secs_left
           FROM user_plan_selection ups
           LEFT JOIN plans p ON p.name_tr = ups.plan_name COLLATE utf8mb4_general_ci
          WHERE ups.user_id = ?',
        [$userId]
    );

    if ($row && $row['name_tr'] !== null) {
        $isPaid = (float) ($row['monthly_price'] ?? 0) > 0;
        if ((int) $row['is_live'] === 1) {
            $out['plan']       = (string) $row['name_tr'];
            $out['is_paid']    = $isPaid;
            $out['state']      = $isPaid ? 'active' : 'free';
            $out['started_at'] = $isPaid ? (string) $row['selected_at'] : null;
            $out['expires_at'] = $isPaid && $row['expires_at'] !== null ? (string) $row['expires_at'] : null;
            $out['days_left']  = $out['expires_at'] !== null ? (int) ceil(((int) $row['secs_left']) / 86400) : null;
            // N-64 — istemci PRO etiketini bitiş anında kaldırsın (saat dilimi bağımsız).
            $out['seconds_left'] = $out['expires_at'] !== null ? max(0, (int) $row['secs_left']) : null;
        } elseif ($isPaid) {
            $out['state']          = 'expired';
            $out['last_paid_plan'] = (string) $row['name_tr'];
            $out['expired_at']     = (string) $row['expires_at'];
        }
    }

    // Ödeme geçmişi: yalnızca paket siparişleri (PLN-); ham sağlayıcı yanıtı dönmez.
    $labels = ['paid' => 'Ödendi', 'refunded' => 'İade edildi', 'partial_refund' => 'Kısmi iade',
               'hoppa_failed' => 'Başarısız', 'hoppa_pending' => 'Bekliyor', 'hoppa_expired' => 'Tamamlanmadı'];
    foreach ($db->selectMulti(
        "order_id, status, amount, items_json, created_at FROM param_marketplace_payments
          WHERE user_id = ? AND order_id LIKE 'PLN-%' AND status IN ('paid', 'refunded', 'partial_refund', 'hoppa_failed')
          ORDER BY id DESC LIMIT 20",
        [$userId]
    ) ?: [] as $p) {
        $items = json_decode((string) $p['items_json'], true);
        $out['history'][] = [
            'order_id'     => (string) $p['order_id'],
            'plan'         => (string) ($items[0]['plan_name'] ?? ''),
            'amount'       => round((float) $p['amount'], 2),
            'status'       => (string) $p['status'],
            'status_label' => $labels[$p['status']] ?? (string) $p['status'],
            'created_at'   => (string) $p['created_at'],
        ];
    }

    return $cache[$userId] = $out;
}

/**
 * N-64 — paketin kaynağı (admin paneli "Paket" alanı). Şemada kaynak sütunu
 * yok; kayıtlardan çıkarılır:
 *   admin    — mevcut seçimden sonra (ya da aynı anda) `plan_assign` denetim kaydı
 *   purchase — bu paket için seçim anından ≤2 gün önce başlatılmış ödenmiş PLN siparişi
 *   legacy   — ücretli ama ikisi de yok: 2026-08-26 öncesi `upgradePlan`
 *              paketi ÖDEMESİZ yazıyordu (BIZ-001) ya da satır elle eklendi
 *   free     — ücretsiz / süresi dolmuş
 *
 * @return array{source: string, label: string}
 */
function getPlanSource(Database $db, int $userId, array $sub): array
{
    if (!$sub['is_paid']) {
        return ['source' => 'free', 'label' => $sub['state'] === 'expired'
            ? 'Ücretsiz (' . $sub['last_paid_plan'] . ' paketinin süresi doldu)' : 'Ücretsiz'];
    }
    $sel = $db->selectSingle('selected_at FROM user_plan_selection WHERE user_id = ?', [$userId]);
    $selectedAt = (string) ($sel['selected_at'] ?? '');

    $audit = $db->selectSingle(
        "admin_name, created_at FROM admin_audit_log
          WHERE target_user_id = ? AND action = 'plan_assign' AND created_at >= DATE_SUB(?, INTERVAL 5 SECOND)
          ORDER BY id DESC LIMIT 1",
        [$userId, $selectedAt]
    );
    if ($audit) {
        return ['source' => 'admin', 'label' => sprintf('Admin ataması (%s, %s)', $audit['admin_name'], date('d.m.Y H:i', strtotime((string) $audit['created_at'])))];
    }

    // N-69 — üst sınır 14 saat: düzeltmeden önce `selected_at` PHP saatiyle
    // (canlıda UTC olabilir), sipariş MySQL saatiyle yazılıyordu; eski satırda
    // seçim, siparişten saatlerce "önce" görünebilir. 14 saat = dünyadaki en
    // büyük ofset farkı. Kayıtlar yeniden yazılmıyor, okuma toleranslı.
    $pay = $db->selectSingle(
        "order_id, created_at FROM param_marketplace_payments
          WHERE user_id = ? AND order_id LIKE 'PLN-%' AND status = 'paid'
            AND items_json LIKE ? AND created_at BETWEEN DATE_SUB(?, INTERVAL 2 DAY) AND DATE_ADD(?, INTERVAL 14 HOUR)
          ORDER BY id DESC LIMIT 1",
        [$userId, '%' . json_encode($sub['plan'], JSON_UNESCAPED_UNICODE) . '%', $selectedAt, $selectedAt]
    );
    if ($pay) {
        return ['source' => 'purchase', 'label' => sprintf('Satın alma (%s, %s)', $pay['order_id'], date('d.m.Y', strtotime((string) $pay['created_at'])))];
    }

    return ['source' => 'legacy', 'label' => 'Ödemesiz kayıt — eski sistem ya da elle eklenmiş'
        . ($sub['expires_at'] === null ? ', süresiz' : '') . ' (seçim: ' . ($selectedAt !== '' ? date('d.m.Y', strtotime($selectedAt)) : '?') . ')'];
}

/**
 * N-64 — admin paket ataması. Tek transaction: seçim satırı + denetim kaydı
 * (`admin_audit_log`, action `plan_assign`, eski/yeni paket ve bitiş).
 * Ödeme ve iade akışına dokunmaz.
 *
 * @param ?string $expiresAt 'Y-m-d H:i:s' ya da null (süresiz)
 * @param array{id: ?int, name: string, ip: ?string} $admin
 * @return array{ok: bool, message: string}
 */
function adminAssignPlan(Database $db, int $userId, string $planName, ?string $expiresAt, array $admin): array
{
    $plan = $db->selectSingle('name_tr FROM plans WHERE name_tr = ?', [$planName]);
    if (!$plan) {
        return ['ok' => false, 'message' => 'Geçersiz paket.'];
    }
    // Karşılaştırma MySQL saatiyle: getUserPlan süreyi NOW() ile ölçüyor.
    if ($expiresAt !== null && (int) ($db->selectSingle('(? > NOW()) AS ok', [$expiresAt])['ok'] ?? 0) !== 1) {
        return ['ok' => false, 'message' => 'Bitiş tarihi gelecekte olmalı.'];
    }
    if (!$db->selectSingle('id FROM kullanicilar WHERE id = ?', [$userId])) {
        return ['ok' => false, 'message' => 'Kullanıcı bulunamadı.'];
    }

    $conn  = $db->getConnection();
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $old = $db->selectSingle(
            'plan_name, ' . (planSelectionHasExpiry($db) ? 'expires_at' : 'NULL AS expires_at') . ' FROM user_plan_selection WHERE user_id = ? FOR UPDATE',
            [$userId]
        );
        $row = ['user_id' => $userId, 'plan_name' => (string) $plan['name_tr'], 'selected_at' => planSelectionNow($db)];
        if (planSelectionHasExpiry($db)) {
            $row['expires_at'] = $expiresAt;
        }
        $db->insert('user_plan_selection', $row, true);
        $db->execute(
            'INSERT INTO admin_audit_log (admin_id, admin_name, action, target_user_id, target_type, target_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $admin['id'] ?? null,
                mb_substr(($admin['name'] ?? '') !== '' ? (string) $admin['name'] : 'bilinmiyor', 0, 64),
                'plan_assign',
                $userId,
                'user_plan_selection',
                $userId,
                json_encode([
                    'old_plan'       => $old['plan_name'] ?? null,
                    'old_expires_at' => $old['expires_at'] ?? null,
                    'new_plan'       => (string) $plan['name_tr'],
                    'new_expires_at' => $expiresAt,
                ], JSON_UNESCAPED_UNICODE),
                isset($admin['ip']) ? mb_substr((string) $admin['ip'], 0, 45) : null,
            ]
        );
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        error_log('[adminAssignPlan] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Paket atanamadı.'];
    }
    return ['ok' => true, 'message' => 'Paket atandı.'];
}

/**
 * N-67 (onaylı 2026-10-19) — aynı paketin yeniden satın alınması.
 * Aktif ücretli paket yeniden satın alınamaz; yalnızca bitişe
 * PLAN_RENEWAL_WINDOW_DAYS gün ya da daha az kala "yenileme" olarak alınır
 * (kesinleştirmede yeni bitiş = GREATEST(NOW(), mevcut bitiş) + 30 gün).
 * Süresiz ücretli pakette hiç alınamaz. Üst/alt paket geçişi BU KURALIN
 * DIŞINDA (müşteri kararı bekliyor) — farklı paket her zaman izinli.
 */
const PLAN_RENEWAL_WINDOW_DAYS = 7;

/** @return array{allowed: bool, renewal: bool, message: string} */
function planPurchaseCheck(array $sub, string $planName): array
{
    $same = ($sub['is_paid'] ?? false) && ($sub['state'] ?? '') === 'active' && ($sub['plan'] ?? '') === $planName;
    if (!$same) {
        return ['allowed' => true, 'renewal' => false, 'message' => ''];
    }
    if (($sub['expires_at'] ?? null) === null) {
        return ['allowed' => false, 'renewal' => false,
            'message' => "\"$planName\" paketiniz zaten etkin ve süresiz; yeniden satın almanız gerekmiyor."];
    }
    $days = (int) ($sub['days_left'] ?? 0);
    if ($days <= PLAN_RENEWAL_WINDOW_DAYS) {
        return ['allowed' => true, 'renewal' => true, 'message' => ''];
    }
    $until = date('d.m.Y', strtotime((string) $sub['expires_at']));
    return ['allowed' => false, 'renewal' => false, 'message' => sprintf(
        '"%s" paketiniz zaten etkin (bitiş: %s). Bitişe %d gün kala yenileyebilirsiniz; kalan günleriniz korunur.',
        $planName, $until, PLAN_RENEWAL_WINDOW_DAYS
    )];
}
