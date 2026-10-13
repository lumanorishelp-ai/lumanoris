<?php
/**
 * Chatbot limit helpers.
 *
 * BIZ-002 🟠 / UX-002 🟡 — burası bir stub'dı ve plana HİÇ bakmıyordu:
 * herkese `AppConfig::FREE_*` (1 bağımsız / 2 herkese açık) döndürüyordu.
 * "TODO: query user plan table when plans are active on prod" yorumu
 * duruyordu ama sorgulanacak bir şey yoktu — `plans` tablosunda limit
 * sütunu bile yoktu (bkz. migration 007).
 *
 * Sonuç, kullanıcıya çelişkili iki ekran olarak görünüyordu: dashboard
 * başlığı `user_plan_selection.plan_name`'i okuyup "Elmas" derken, bot
 * ekranı buradan 1/2 alıyordu.
 *
 * Artık ikisi de `functions/plans.php` üzerinden aynı satırı okuyor.
 * Migration 007 uygulanmamışsa fonksiyon AppConfig değerlerine düşüyor,
 * yani davranış eskisiyle birebir aynı kalıyor.
 */

require_once __DIR__ . '/plans.php';

/**
 * "Sınırsız" kotanın tek temsili. Sayım karşılaştırmaları (`$used >= $limit`)
 * değişmeden çalışsın diye sayı; arayüze ayrıca `*_unlimited` bayrağı gider.
 */
const BOT_LIMIT_UNLIMITED = PHP_INT_MAX;

/**
 * Plan satırındaki ham kota → sayı. Migration 012 ile `plans` bot limitleri
 * NULL alabiliyor: NULL = SINIRSIZ (Elmas). Eskiden `(int)` cast ediliyordu;
 * bu NULL'ı 0'a çevirip "sınırsız" planı HİÇ bot açamaz hâle getirirdi.
 */
function normalizeBotLimit(mixed $raw): int {
    if ($raw === null) {
        return BOT_LIMIT_UNLIMITED;
    }
    return (int) $raw;
}

/** Plan satırından bir bot kotası; kolon hiç yoksa geri düşüş değeri. */
function planBotLimit(array $plan, string $column, int $fallback): int {
    return array_key_exists($column, $plan) ? normalizeBotLimit($plan[$column]) : $fallback;
}

function getIndependentBotLimit(Database $db, int $userId): int {
    return planBotLimit(getUserPlan($db, $userId), 'independent_bot_limit', AppConfig::FREE_INDEPENDENT_BOT_LIMIT);
}

/**
 * Madde 2 / GK-5 / GK-6 — onaylanmış (`active`) pazaryeri satıcısının
 * herkese açık bot hakkı sınırsız. Yalnızca herkese açık botlar için; bağımsız
 * bot limiti plandan gelmeye devam eder. Başvuru yapmış ama onaylanmamış
 * kullanıcı (pending/rejected) bu hakkı ALMAZ.
 *
 * B1 açıkken hiçbir satıcı `active` olamıyor; yani bugün bu dal kimse için
 * çalışmıyor. Kural yine de sunucuda: B1 kapandığında ayrıca kod gerekmesin.
 */
function isActiveMarketplaceSeller(Database $db, int $userId): bool {
    try {
        $row = $db->selectSingle('status FROM ' . AppConfig::TABLE_SELLERS . ' WHERE user_id = ?', [$userId]);
    } catch (Throwable $e) {
        // Tablo henüz oluşturulmamış bir kurulumda (ensureParamMarketplaceTables
        // tembel çalışıyor) satıcı yok demektir.
        error_log('[chatbot_limits] satıcı durumu okunamadı: ' . $e->getMessage());
        return false;
    }
    return ($row['status'] ?? null) === 'active';
}

function getPublicBotLimit(Database $db, int $userId): int {
    if (isActiveMarketplaceSeller($db, $userId)) {
        return BOT_LIMIT_UNLIMITED;
    }
    return planBotLimit(getUserPlan($db, $userId), 'public_bot_limit', AppConfig::FREE_PUBLIC_BOT_LIMIT);
}

function isUnlimitedBotLimit(int $limit): bool {
    return $limit === BOT_LIMIT_UNLIMITED;
}

// ── Madde 11 — yayından kaldırma (özel yapma) hakkı ─────────────────────────
//
// GK-3: yayınlanmamış bağımsız bot hak kullanmadan sahibine özel kalır; hak
// YALNIZCA yayınlanmış (herkese açık) bir botu geri özele çekerken düşer.
// Özele çekme bağımsız bot limitine TAKILMAZ. GK-17: hak TOPLAM sayılır
// (aylık yenilenmez) — kullanılan = `user_privacy_right_usage` satır sayısı.

/** Paket başına toplam özel yapma hakkı (`plans.privacy_right_limit`). */
function getPrivacyRightLimit(Database $db, int $userId): int {
    $plan = getUserPlan($db, $userId);
    return (int) ($plan['privacy_right_limit'] ?? AppConfig::FREE_PRIVACY_RIGHT_LIMIT);
}

/**
 * Kullanım tablosu var mı? (migration 012). Yoksa hak SAYILAMAZ: çağıran
 * eski davranışa (sınırsız özele çekme) düşer ve bunu loglar — tablo eksik
 * diye özele çekmeyi tamamen kırmak yerine.
 */
function privacyUsageTableReady(Database $db): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $row = $db->selectSingle(
            "COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [AppConfig::TABLE_PRIVACY_RIGHT_USAGE]
        );
        return $ready = ((int) ($row['cnt'] ?? 0) === 1);
    } catch (Throwable $e) {
        error_log('[chatbot_limits] hak tablosu kontrolü başarısız: ' . $e->getMessage());
        return $ready = false;
    }
}

function countPrivacyRightUsage(Database $db, int $userId): int {
    if (!privacyUsageTableReady($db)) {
        return 0;
    }
    return $db->count(AppConfig::TABLE_PRIVACY_RIGHT_USAGE, 'user_id = ?', [$userId]);
}

function privacyRightsRemaining(Database $db, int $userId): int {
    return max(0, getPrivacyRightLimit($db, $userId) - countPrivacyRightUsage($db, $userId));
}

/** Hakkın kullanıldığını kaydeder. Çağıran kilit/transaction içinde olmalı. */
function recordPrivacyRightUsage(Database $db, int $userId, int $chatbotId): void {
    $db->execute(
        'INSERT INTO ' . AppConfig::TABLE_PRIVACY_RIGHT_USAGE . ' (user_id, chatbot_id, used_at) VALUES (?, ?, NOW())',
        [$userId, $chatbotId]
    );
}

function countUserChatbots(Database $db, int $userId, int $isIndependent): int {
    return $db->count(AppConfig::TABLE_CHATBOTS, 'author_user_id = ? AND is_independent = ?', [$userId, $isIndependent]);
}
