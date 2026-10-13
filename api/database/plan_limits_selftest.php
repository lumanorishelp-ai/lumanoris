<?php
/**
 * Plan / kota öz-testi — SALT OKUNUR (veritabanına yazmaz).
 *
 *   php api/database/plan_limits_selftest.php
 *
 * Faz 3 test kuralı (docs/prompts/11-pazaryeri-revizyon.md):
 *
 *   A) REGRESYON — bugün DOĞRU olan davranış: ücretsiz plan 1 bağımsız /
 *      2 herkese açık bot / günlük 10 coin. Hem AppConfig geri düşüşü hem
 *      `plans` tablosu yolu. Bu bölüm her zaman yeşil kalmalı.
 *
 *   B) MÜŞTERİ PAKET TABLOSU (AUDIT.md, Faz 3) — bugün YANLIŞ olan
 *      davranış: `plans` seed'i (007) Altın'ı 10/15/200, Elmas'ı 599 ₺
 *      tutuyor. Bu bölüm paket verisi migration'ı uygulanana kadar
 *      KIRMIZIDIR; yanlış değerleri kilitlemiyor, doğrusunu tarif ediyor.
 *      "Sınırsız" ve yayından kaldırma hakkı şema kararına bağlı olduğu
 *      için burada henüz test edilmiyor.
 *
 * Bu dosya `api/database/` altında: üç denylist de (`api/.htaccess`,
 * `api/admin/.htaccess`, `api/router.php`) bu dizini web'den kapatıyor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/chatbot_limits.php';

$pass = 0;
$fail = 0;
// B bölümü (müşteri paket tablosu) 013 uygulanana kadar bilerek kırmızı.
// Bu başarısızlıklar ayrı sayılıyor ve çıkış kodunu BOZMUYOR — aksi hâlde
// doğrulama kuralı ("başarısız test varsa commit yok") her commit'i
// engellerdi. `--strict` verilirse B de çıkış koduna dahil.
$expectedRedFail = 0;
$inExpectedRed   = false;
$strict          = in_array('--strict', $argv ?? [], true);

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $expectedRedFail, $inExpectedRed;
    if ($ok) { $pass++; echo "  [OK]   $label\n"; return; }
    if ($inExpectedRed) {
        $expectedRedFail++;
        echo "  [RED]  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        return;
    }
    $fail++;
    echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function finish(): void {
    global $pass, $fail, $expectedRedFail, $strict;
    echo "\n=== SONUÇ: $pass geçti, $fail başarısız"
        . ($expectedRedFail > 0 ? ", $expectedRedFail beklenen kırmızı (B)" : '') . " ===\n\n";
    exit(($fail > 0 || ($strict && $expectedRedFail > 0)) ? 1 : 0);
}

echo "\n=== A) REGRESYON — ücretsiz plan 1 / 2 / 10 ===\n\n";

check('AppConfig::FREE_INDEPENDENT_BOT_LIMIT = 1', AppConfig::FREE_INDEPENDENT_BOT_LIMIT === 1);
check('AppConfig::FREE_PUBLIC_BOT_LIMIT = 2', AppConfig::FREE_PUBLIC_BOT_LIMIT === 2);
check('AppConfig::DAILY_FREE_MESSAGES = 10', AppConfig::DAILY_FREE_MESSAGES === 10);

$fb = fallbackPlan();
check('fallbackPlan adı FREE_PLAN_NAME', $fb['name_tr'] === AppConfig::FREE_PLAN_NAME);
check('fallbackPlan 1 bağımsız', (int) $fb['independent_bot_limit'] === 1);
check('fallbackPlan 2 herkese açık', (int) $fb['public_bot_limit'] === 2);
check('fallbackPlan 10 günlük mesaj', (int) $fb['daily_message_limit'] === 10);

try {
    $db = Database::getInstance();
} catch (Throwable $e) {
    echo "\n  (veritabanı yok — tablo yolu atlandı: {$e->getMessage()})\n";
    finish();
}

// Plan seçmemiş (ya da süresi dolmuş) ve onaylı satıcı OLMAYAN bir kullanıcı
// varsayılan plana düşer. Onaylı satıcının herkese açık hakkı sınırsız
// (madde 2 / GK-6) — o ayrı kontrol ediliyor (A2).
$noPlanUser = $db->selectSingle(
    "k.id FROM kullanicilar k
     LEFT JOIN user_plan_selection s ON s.user_id = k.id
     LEFT JOIN param_marketplace_sellers pms ON pms.user_id = k.id AND pms.status = 'active'
     WHERE s.user_id IS NULL AND pms.user_id IS NULL
     ORDER BY k.id LIMIT 1"
);

if (!$noPlanUser) {
    echo "  (plan seçmemiş kullanıcı bulunamadı — kullanıcı yolu atlandı)\n";
} else {
    $uid = (int) $noPlanUser['id'];
    check('plansız kullanıcı: plan adı Ücretsiz', getUserPlanName($db, $uid) === AppConfig::FREE_PLAN_NAME,
        getUserPlanName($db, $uid));
    check('plansız kullanıcı: 1 bağımsız bot', getIndependentBotLimit($db, $uid) === 1,
        (string) getIndependentBotLimit($db, $uid));
    check('plansız kullanıcı: 2 herkese açık bot', getPublicBotLimit($db, $uid) === 2,
        (string) getPublicBotLimit($db, $uid));
    check('plansız kullanıcı: günlük 10 mesaj', getDailyMessageLimit($db, $uid) === 10,
        (string) getDailyMessageLimit($db, $uid));
}

echo "\n=== A2) MADDE 2 — onaylı satıcıya sınırsız herkese açık hak (GK-5/GK-6) ===\n\n";

$activeSeller = $db->selectSingle("user_id FROM param_marketplace_sellers WHERE status = 'active' ORDER BY user_id LIMIT 1");
if (!$activeSeller) {
    echo "  (onaylı satıcı yok — B1 açıkken beklenen; kontrol atlandı)\n";
} else {
    $sid = (int) $activeSeller['user_id'];
    check('onaylı satıcı: herkese açık hak sınırsız', isUnlimitedBotLimit(getPublicBotLimit($db, $sid)));
    check('onaylı satıcı: bağımsız hak plandan (sınırsız değil)', !isUnlimitedBotLimit(getIndependentBotLimit($db, $sid)));
}
$unapproved = $db->selectSingle("user_id FROM param_marketplace_sellers WHERE status IN ('pending', 'rejected') ORDER BY user_id LIMIT 1");
if ($unapproved) {
    check('onaysız başvuru: herkese açık hak sınırsız DEĞİL',
        !isUnlimitedBotLimit(getPublicBotLimit($db, (int) $unapproved['user_id'])));
}

echo "\n=== C) MADDE 11 + SINIRSIZ — özel yapma hakkı (GK-3, GK-17) ve NULL = sınırsız ===\n\n";

// Fonksiyonlar yoksa ölümcül hata yerine okunur bir FAIL üret (kırmızı test).
$need = ['normalizeBotLimit', 'getPrivacyRightLimit', 'countPrivacyRightUsage',
         'privacyRightsRemaining', 'recordPrivacyRightUsage', 'privacyUsageTableReady'];
$missing = array_values(array_filter($need, fn($f) => !function_exists($f)));
check('madde 11 yardımcıları tanımlı', $missing === [], 'eksik: ' . implode(', ', $missing));

if ($missing === []) {
    check('NULL limit = sınırsız', isUnlimitedBotLimit(normalizeBotLimit(null)));
    check('sayısal limit korunur (3)', normalizeBotLimit(3) === 3);
    check('metin limit sayıya çevrilir ("5")', normalizeBotLimit('5') === 5);

    check('AppConfig::FREE_PRIVACY_RIGHT_LIMIT = 1',
        defined('AppConfig::FREE_PRIVACY_RIGHT_LIMIT') && AppConfig::FREE_PRIVACY_RIGHT_LIMIT === 1);
    check('fallbackPlan özel yapma hakkı 1', (int) (fallbackPlan()['privacy_right_limit'] ?? -1) === 1);

    if ($noPlanUser) {
        $uid = (int) $noPlanUser['id'];
        check('plansız kullanıcı: 1 özel yapma hakkı (toplam)', getPrivacyRightLimit($db, $uid) === 1,
            (string) getPrivacyRightLimit($db, $uid));

        if (!privacyUsageTableReady($db)) {
            echo "  (user_privacy_right_usage yok — 012 uygulanmamış; kullanım sayımı atlandı)\n";
        } else {
            // Yazma testi TRANSACTION içinde, sonunda ROLLBACK: hiçbir satır kalmaz.
            $conn   = $db->getConnection();
            $before = countPrivacyRightUsage($db, $uid);
            $bot    = $db->selectSingle('id FROM chatbotlar ORDER BY id LIMIT 1');
            $conn->beginTransaction();
            try {
                recordPrivacyRightUsage($db, $uid, (int) ($bot['id'] ?? 1));
                check('hak kullanımı sayılır (+1)', countPrivacyRightUsage($db, $uid) === $before + 1);
                check('kalan hak = limit − kullanılan',
                    privacyRightsRemaining($db, $uid) === max(0, getPrivacyRightLimit($db, $uid) - ($before + 1)));
            } finally {
                $conn->rollBack();
            }
            check('rollback sonrası kullanım değişmedi', countPrivacyRightUsage($db, $uid) === $before);
        }
    }
}

$inExpectedRed = true;
echo "\n=== B) MÜŞTERİ PAKET TABLOSU — paket migration'ı uygulanana kadar KIRMIZI ===\n\n";

$catalog = [];
foreach (getPlanCatalog($db) as $p) {
    $catalog[$p['name_tr']] = $p;
}

// name => [aylık fiyat, günlük coin, bağımsız, herkese açık, özel yapma hakkı]
// Bot kotasında null = SINIRSIZ (migration 012: kolon NULL).
$expected = [
    'Ücretsiz' => [0.0,   10,  1,    2,    1],
    'Gümüş'    => [149.0, 50,  3,    5,    3],
    'Altın'    => [299.0, 100, 5,    10,   5],
    'Elmas'    => [849.0, 200, null, null, 20],
];

$botLimitLabel = fn($v) => $v === null ? 'sınırsız' : (string) $v;

foreach ($expected as $name => [$price, $coin, $indep, $public, $privacy]) {
    if (!isset($catalog[$name])) {
        check("$name planı katalogda var", false, 'plans tablosunda yok');
        continue;
    }
    $p = $catalog[$name];
    check("$name: aylık $price ₺", abs((float) $p['monthly_price'] - $price) < 0.001, (string) $p['monthly_price']);
    check("$name: günlük $coin coin", (int) $p['daily_message_limit'] === $coin, (string) $p['daily_message_limit']);
    check("$name: " . $botLimitLabel($indep) . ' bağımsız',
        normalizeBotLimit($p['independent_bot_limit']) === normalizeBotLimit($indep),
        $botLimitLabel($p['independent_bot_limit']));
    check("$name: " . $botLimitLabel($public) . ' herkese açık',
        normalizeBotLimit($p['public_bot_limit']) === normalizeBotLimit($public),
        $botLimitLabel($p['public_bot_limit']));
    check("$name: $privacy özel yapma hakkı",
        array_key_exists('privacy_right_limit', $p) && (int) $p['privacy_right_limit'] === $privacy,
        array_key_exists('privacy_right_limit', $p) ? (string) $p['privacy_right_limit'] : 'kolon yok (012)');
}

finish();
