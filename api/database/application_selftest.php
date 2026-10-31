<?php
/**
 * Pazaryeri başvurusu öz-testi (Faz 5, madde 3) — KALICI YAZMA YOK.
 *
 *   php api/database/application_selftest.php
 *
 * A) Doğrulama (saf fonksiyon): GK-7 alanları, GK-8 hesap türleri, GK-24
 *    vergi no / MERSİS kuralı, IBAN mod97, 18 yaş, beyaz liste.
 * B) Gönderim akışı (transaction + ROLLBACK): ilk gönderim, rejected →
 *    yeniden gönderim, reviewed → 409 (GK-20, GK-21).
 * C) "Kaydı var" kuralı (GK-22): yeni tabloda submitted/reviewed VEYA eski
 *    tabloda active/suspended; eski pending/rejected ve yeni rejected SAYILMAZ.
 *
 * `api/database/` üç denylist tarafından da web'e kapalı.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $label\n"; }
    else     { $fail++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function finish(): void {
    global $pass, $fail;
    echo "\n=== SONUÇ: $pass geçti, $fail başarısız ===\n\n";
    exit($fail > 0 ? 1 : 0);
}

$helper = __DIR__ . '/../functions/marketplace_application.php';
if (!is_file($helper)) {
    check('functions/marketplace_application.php mevcut', false, 'dosya yok');
    finish();
}
require_once $helper;

$need = ['validateMarketplaceApplication', 'submitMarketplaceApplication', 'getMarketplaceApplication',
         'hasMarketplaceRegistration', 'maskIban'];
$missing = array_values(array_filter($need, fn($f) => !function_exists($f)));
check('başvuru yardımcıları tanımlı', $missing === [], 'eksik: ' . implode(', ', $missing));
if ($missing !== []) finish();

$valid = [
    'full_name'   => 'Ayşe Yılmaz',
    'phone'       => '0532 123 45 67',
    'email'       => 'Ayse@Ornek.com',
    'description' => 'Felsefe ve tarih alanında eğitilmiş chatbotlar sunmak istiyorum.',
];
$errorsOf = function (array $input): array {
    try { validateMarketplaceApplication($input); return []; }
    catch (ValidationException $e) { return $e->getErrors() ?: ['_' => $e->getMessage()]; }
};
$cleanOf = fn(array $input) => validateMarketplaceApplication($input);

echo "
=== A) Doğrulama (sade form) ===

";

check('geçerli başvuru kabul', $errorsOf($valid) === [], json_encode($errorsOf($valid), JSON_UNESCAPED_UNICODE));
$c = $cleanOf($valid);
check('telefon +90 biçimine çevrilir', ($c['phone'] ?? '') === '+905321234567', (string) ($c['phone'] ?? ''));
check('e-posta küçük harfe çevrilir', ($c['email'] ?? '') === 'ayse@ornek.com');
check('yalnızca dört alan yazılır', array_keys($c) === ['full_name', 'phone', 'email', 'description'], implode(',', array_keys($c)));
check('bilinmeyen alan reddedilir (beyaz liste)', isset($errorsOf($valid + ['status' => 'reviewed'])['_fields']));
check('eski form alanı reddedilir', isset($errorsOf($valid + ['iban' => 'TR330006100519786457841326'])['_fields']));
check('tek kelime isim reddedilir', isset($errorsOf(['full_name' => 'Ayşe'] + $valid)['full_name']));
check('rakamlı isim reddedilir', isset($errorsOf(['full_name' => 'Ayşe 123'] + $valid)['full_name']));
check('boş isim reddedilir', isset($errorsOf(['full_name' => '   '] + $valid)['full_name']));
check('isimdeki fazla boşluk tekleşir', ($cleanOf(['full_name' => '  Ayşe   Nur  Yılmaz '] + $valid)['full_name'] ?? '') === 'Ayşe Nur Yılmaz');
foreach (['05321234567', '5321234567', '+90 532 123 45 67', '0090 532 123 4567', '(0212) 555 11 22'] as $ph) {
    check("telefon kabul: $ph", !isset($errorsOf(['phone' => $ph] + $valid)['phone']));
}
check('yurt dışı telefon kabul', ($cleanOf(['phone' => '+49 151 2345 6789'] + $valid)['phone'] ?? '') === '+4915123456789');
foreach (['123', '0532 123', '1532 123 45 67', 'abc'] as $ph) {
    check("telefon red: $ph", isset($errorsOf(['phone' => $ph] + $valid)['phone']));
}
check('boş telefon reddedilir', isset($errorsOf(['phone' => ''] + $valid)['phone']));
check('geçersiz e-posta reddedilir', isset($errorsOf(['email' => 'ayse@'] + $valid)['email']));
check('boş e-posta reddedilir', isset($errorsOf(['email' => ''] + $valid)['email']));
check('kısa açıklama reddedilir', isset($errorsOf(['description' => 'Kısa'] + $valid)['description']));
check('çok uzun açıklama reddedilir', isset($errorsOf(['description' => str_repeat('a', 2001)] + $valid)['description']));
check('açıklamadaki HTML temizlenir', !str_contains($cleanOf(['description' => '<b>Merhaba</b> pazaryerinde chatbot satmak istiyorum.'] + $valid)['description'], '<b>'));
check('maskIban son 4 hane', str_ends_with((string) maskIban('TR330006100519786457841326'), '1326'));

$db   = Database::getInstance();
$conn = $db->getConnection();

echo "\n=== B) Gönderim akışı (rollback) ===\n\n";

$user = $db->selectSingle(
    "k.id FROM kullanicilar k
     LEFT JOIN marketplace_applications m ON m.user_id = k.id
     LEFT JOIN param_marketplace_sellers p ON p.user_id = k.id
     WHERE m.id IS NULL AND p.user_id IS NULL ORDER BY k.id LIMIT 1"
);
if (!$user) {
    echo "  (başvurusu ve satıcı kaydı olmayan kullanıcı yok — B/C atlandı)\n";
    finish();
}
$uid = (int) $user['id'];

$conn->beginTransaction();
try {
    check('başvuru öncesi kayıt yok', !hasMarketplaceRegistration($db, $uid));
    $r = submitMarketplaceApplication($db, $uid, $cleanOf($valid));
    check('ilk gönderim → submitted', ($r['status'] ?? null) === 'submitted', json_encode($r, JSON_UNESCAPED_UNICODE));
    check('gönderilmiş başvuru "kaydı var" (GK-22)', hasMarketplaceRegistration($db, $uid));

    $db->execute("UPDATE marketplace_applications SET status = 'rejected', review_note = 'test' WHERE user_id = ?", [$uid]);
    check('rejected başvuru "kaydı var" SAYILMAZ', !hasMarketplaceRegistration($db, $uid));
    $r = submitMarketplaceApplication($db, $uid, $cleanOf(['full_name' => 'Ayşe Demir'] + $valid));
    $row = getMarketplaceApplication($db, $uid);
    check('rejected → yeniden gönderim submitted olur', ($row['status'] ?? null) === 'submitted');
    check('yeniden gönderim aynı satırı günceller (GK-20)',
        (int) $db->selectSingle('COUNT(*) AS c FROM marketplace_applications WHERE user_id = ?', [$uid])['c'] === 1
        && ($row['full_name'] ?? '') === 'Ayşe Demir');
    check('yeniden gönderim inceleme notunu temizler', ($row['review_note'] ?? null) === null);

    $db->execute("UPDATE marketplace_applications SET status = 'reviewed' WHERE user_id = ?", [$uid]);
    check('reviewed başvuru "kaydı var"', hasMarketplaceRegistration($db, $uid));
    $threw = null;
    try { submitMarketplaceApplication($db, $uid, $cleanOf($valid)); }
    catch (AppException $e) { $threw = $e; }
    check('reviewed başvuru değiştirilemez (GK-21, 409)', $threw !== null && $threw->getCode() === 409,
        $threw ? get_class($threw) . ' ' . $threw->getCode() : 'istisna yok');

    echo "\n=== C) Eski satıcı tablosu (GK-22) ===\n\n";
    // Yeni tablodaki başvuru sayılmayan duruma çekiliyor (silme yok), böylece
    // sonuç yalnızca eski tabloya bağlı kalıyor.
    $db->execute("UPDATE marketplace_applications SET status = 'rejected' WHERE user_id = ?", [$uid]);
    foreach (['pending' => false, 'rejected' => false, 'active' => true, 'suspended' => true] as $st => $expect) {
        $db->execute(
            "INSERT INTO param_marketplace_sellers (user_id, status) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            [$uid, $st]
        );
        check("eski tablo '$st' → kaydı var = " . ($expect ? 'evet' : 'hayır'), hasMarketplaceRegistration($db, $uid) === $expect);
    }
} finally {
    $conn->rollBack();
}
check('rollback sonrası test kullanıcısında başvuru yok', getMarketplaceApplication($db, $uid) === null);

echo "\n=== D) İnceleme + IBAN aktarımı (GK-23, rollback) ===\n\n";

if (!function_exists('reviewMarketplaceApplication')) {
    check('reviewMarketplaceApplication tanımlı', false, 'fonksiyon yok');
    finish();
}
$auditReady = (int) $db->selectSingle(
    "COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_log'"
)['c'] === 1;
check('admin_audit_log tablosu var (017)', $auditReady);
if (!$auditReady) finish();

/** TR + kontrol hanesi + 22 haneli BBAN → geçerli (mod97) IBAN. */
$makeIban = function (string $bban): string {
    $num = $bban . '2927' . '00'; // T=29, R=27
    $mod = 0;
    foreach (str_split($num) as $d) { $mod = ($mod * 10 + (int) $d) % 97; }
    return 'TR' . str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT) . $bban;
};
$iban2 = $makeIban('0006100519786457841327');
check('test IBAN\'ı geçerli', (function () use ($iban2) {
    try { BankIdentity::normalizeIban($iban2); return true; } catch (Throwable $e) { return false; }
})());

$auditCount = fn(int $u) => (int) $db->selectSingle(
    "COUNT(*) AS c FROM admin_audit_log WHERE target_user_id = ? AND action = 'iban_update_from_application'", [$u]
)['c'];
$bankIban = fn(int $u) => $db->selectSingle('iban FROM banka_bilgileri WHERE user_id = ?', [$u])['iban'] ?? null;
$notifCount = fn(int $u) => (int) $db->selectSingle(
    "COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'marketplace_application'", [$u]
)['c'];

$u2 = $db->selectSingle(
    "k.id FROM kullanicilar k
     LEFT JOIN marketplace_applications m ON m.user_id = k.id
     LEFT JOIN banka_bilgileri b ON b.user_id = k.id
     LEFT JOIN para_cekme_talepleri t ON t.user_id = k.id AND t.durum = 'beklemede'
     WHERE m.id IS NULL AND b.id IS NULL AND t.id IS NULL ORDER BY k.id LIMIT 1"
);
if (!$u2) {
    echo "  (banka kaydı/başvurusu/bekleyen çekimi olmayan kullanıcı yok — D atlandı)\n";
    finish();
}
$u2 = (int) $u2['id'];

$conn->beginTransaction();
try {
    submitMarketplaceApplication($db, $u2, $cleanOf($valid));
    $appId = (int) getMarketplaceApplication($db, $u2)['id'];
    $r = reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', null);
    check('sade form onayı → IBAN uygulanmaz', ($r['iban'] ?? '') === 'not_applicable', json_encode($r));
    check('sade form onayı → banka kaydı açılmaz', $bankIban($u2) === null);
    check('sade form onayı → log yok', $auditCount($u2) === 0);
    $db->execute("UPDATE marketplace_applications SET status = 'submitted', iban = 'TR330006100519786457841326' WHERE id = ?", [$appId]);
    $notifBefore = $notifCount($u2);

    $r = reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', '127.0.0.1');
    check('incelendi → durum reviewed', (getMarketplaceApplication($db, $u2)['status'] ?? '') === 'reviewed');
    check('IBAN aktarıldı (banka kaydı yoktu)', ($r['iban'] ?? '') === 'updated', json_encode($r));
    check('banka_bilgileri.iban = başvuru IBAN\'ı', $bankIban($u2) === 'TR330006100519786457841326');
    check('IBAN değişikliği log\'a yazıldı', $auditCount($u2) === 1);
    $details = (string) $db->selectSingle(
        "details FROM admin_audit_log WHERE target_user_id = ? ORDER BY id DESC LIMIT 1", [$u2]
    )['details'];
    check('log\'da tam IBAN YOK (yalnızca maskeli)', !str_contains($details, 'TR330006100519786457841326') && str_contains($details, '1326'), $details);
    check('kullanıcıya bildirim gitti (GK-25)', $notifCount($u2) === $notifBefore + 1);

    // Aynı IBAN ile yeniden inceleme: yazım ve log YOK.
    $db->execute("UPDATE marketplace_applications SET status = 'submitted' WHERE id = ?", [$appId]);
    $r = reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', null);
    check('aynı IBAN → değişiklik yok', ($r['iban'] ?? '') === 'unchanged', json_encode($r));
    check('aynı IBAN → yeni log satırı yok', $auditCount($u2) === 1);

    // Bekleyen çekim talebi varken IBAN güncellenmez; başvuru yine incelenir.
    $db->execute("UPDATE marketplace_applications SET status = 'submitted', iban = ? WHERE id = ?", [$iban2, $appId]);
    $db->execute(
        "INSERT INTO para_cekme_talepleri (user_id, iban, miktar, durum) VALUES (?, 'TR330006100519786457841326', 10.00, 'beklemede')",
        [$u2]
    );
    $r = reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', null);
    check('bekleyen çekim → IBAN aktarılmadı', ($r['iban'] ?? '') === 'blocked_pending_withdrawal', json_encode($r));
    check('bekleyen çekim → banka IBAN\'ı değişmedi', $bankIban($u2) === 'TR330006100519786457841326');
    check('bekleyen çekim → başvuru yine incelendi', (getMarketplaceApplication($db, $u2)['status'] ?? '') === 'reviewed');
    check('bekleyen çekim → log satırı eklenmedi', $auditCount($u2) === 1);

    // Ret: IBAN'a dokunulmaz, açıklama zorunlu.
    $db->execute("UPDATE marketplace_applications SET status = 'submitted' WHERE id = ?", [$appId]);
    $threw = false;
    try { reviewMarketplaceApplication($db, $appId, 'rejected', '', null, 'selftest', null); }
    catch (ValidationException $e) { $threw = true; }
    check('açıklamasız ret reddedilir', $threw);
    $r = reviewMarketplaceApplication($db, $appId, 'rejected', 'Vergi levhası bilgisi eksik.', null, 'selftest', null);
    check('ret → IBAN uygulanmaz', ($r['iban'] ?? '') === 'not_applicable', json_encode($r));
    check('ret → durum rejected + not', (getMarketplaceApplication($db, $u2)['review_note'] ?? '') === 'Vergi levhası bilgisi eksik.');
} finally {
    $conn->rollBack();
}
check('rollback sonrası banka kaydı yok', $bankIban($u2) === null);
check('rollback sonrası log satırı yok', $auditCount($u2) === 0);

echo "\n=== E) Gönderim zamanı (N-29, rollback) ===\n\n";

// N-29: durum ucu `submitted_at` olarak updated_at dönüyordu; admin incelemesi
// (ON UPDATE CURRENT_TIMESTAMP) onu inceleme zamanına çeviriyordu.
if (!function_exists('marketplaceApplicationSubmittedAt')) {
    check('marketplaceApplicationSubmittedAt tanımlı', false, 'fonksiyon yok');
} else {
    $past = '2026-01-02 03:04:05';
    $conn->beginTransaction();
    try {
        submitMarketplaceApplication($db, $u2, $cleanOf($valid));
        $appId = (int) getMarketplaceApplication($db, $u2)['id'];
        $db->execute('UPDATE marketplace_applications SET created_at = ?, updated_at = ? WHERE id = ?', [$past, $past, $appId]);

        reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', null);
        $row = getMarketplaceApplication($db, $u2);
        check('inceleme gönderim zamanını değiştirmez', marketplaceApplicationSubmittedAt($row) === $past, marketplaceApplicationSubmittedAt($row));

        // Yeniden gönderim (GK-20) yeni bir gönderimdir: zaman güncellenir.
        $db->execute("UPDATE marketplace_applications SET status = 'rejected', review_note = 'x' WHERE id = ?", [$appId]);
        $r = submitMarketplaceApplication($db, $u2, $cleanOf($valid));
        $row = getMarketplaceApplication($db, $u2);
        check('yeniden gönderim zamanı günceller', marketplaceApplicationSubmittedAt($row) !== $past, marketplaceApplicationSubmittedAt($row));
        check('submit dönüşü aynı alanı verir', $r['submitted_at'] === marketplaceApplicationSubmittedAt($row), json_encode($r));
    } finally {
        $conn->rollBack();
    }
}


echo "\n=== F) Bireysel satıcı API kapısı (N-51, rollback) ===\n\n";

require_once __DIR__ . '/../functions/chatbot_limits.php';

$u3 = $db->selectSingle(
    "k.id FROM kullanicilar k
     LEFT JOIN marketplace_applications m ON m.user_id = k.id
     LEFT JOIN banka_bilgileri b ON b.user_id = k.id
     LEFT JOIN user_plan_selection p ON p.user_id = k.id
     WHERE m.id IS NULL AND b.id IS NULL AND p.user_id IS NULL ORDER BY k.id DESC LIMIT 1"
);
if (!$u3) {
    echo "  (başvurusu, banka kaydı ve paketi olmayan kullanıcı yok — F/G atlandı)\n";
    finish();
}
$u3 = (int) $u3['id'];

$body = function (string $src, string $fn): string {
    $p = strpos($src, "public static function $fn(");
    if ($p === false) return '';
    $q = strpos($src, 'public static function ', $p + 10);
    return substr($src, $p, ($q === false ? strlen($src) : $q) - $p);
};
if (!function_exists('assertCompanySellerAllowed') || !function_exists('hasApprovedMarketplaceApplication')) {
    check('assertCompanySellerAllowed / hasApprovedMarketplaceApplication tanımlı', false, 'yok');
} else {
    $denied = function (?string $bankType) use ($db, $u3): ?string {
        try { assertCompanySellerAllowed($db, $u3, $bankType); return null; }
        catch (PermissionException $e) { return $e->getMessage(); }
    };
    $conn->beginTransaction();
    try {
        $m = $denied('Kurumsal Hesap');
        check('başvurusu yok → reddedilir (403)', $m !== null && str_contains($m, 'onaylanmış'), (string) $m);
        submitMarketplaceApplication($db, $u3, $cleanOf($valid));
        check('başvuru gönderilmiş ama onaysız → reddedilir', $denied('Kurumsal Hesap') !== null);
        $appId = (int) getMarketplaceApplication($db, $u3)['id'];
        reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', null);
        check('onaylı + "Bireysel Hesap" → reddedilir', ($m = $denied('Bireysel Hesap')) !== null && str_contains($m, 'Bireysel'), (string) $m);
        check('onaylı + tür boş/bilinmiyor → reddedilir', $denied('') !== null && $denied('xyz') !== null);
        check('onaylı + "Şahıs Şirketi" → izinli', $denied('Şahıs Şirketi') === null);
        check('onaylı + "Kurumsal Hesap" → izinli', $denied('Kurumsal Hesap') === null);
        check('onaylı + tür sorulmadan (null) → izinli', $denied(null) === null);
        reviewMarketplaceApplication($db, $appId, 'rejected', 'Geri alındı (selftest).', null, 'selftest', null);
        check('onay geri alınınca → yine reddedilir', $denied('Kurumsal Hesap') !== null);
    } finally {
        $conn->rollBack();
    }

    $wallet = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/WalletController.php');
    $seller = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/SellerController.php');
    check('saveBankInfo kapıyı çağırıyor', str_contains($body($wallet, 'saveBankInfo'), 'assertCompanySellerAllowed('));
    check('SellerController::register kapıyı çağırıyor', str_contains($body($seller, 'register'), 'assertCompanySellerAllowed('));
    check('SellerController::resubmit kapılı register üzerinden', str_contains($body($seller, 'resubmit'), 'self::register()') && str_contains($body($seller, 'register'), 'assertCompanySellerAllowed('));
}

echo "\n=== G) Sınırsız herkese açık bot = admin onaylı başvuru (madde 2, rollback) ===\n\n";
$conn->beginTransaction();
try {
    $planLimit = getPublicBotLimit($db, $u3);
    check('başvurusuz → plan limiti (ücretsiz 2)', $planLimit === 2, (string) $planLimit);
    submitMarketplaceApplication($db, $u3, $cleanOf($valid));
    check('gönderilmiş (onaysız) başvuru → sınırsız DEĞİL', !isUnlimitedBotLimit(getPublicBotLimit($db, $u3)));
    $appId = (int) getMarketplaceApplication($db, $u3)['id'];
    reviewMarketplaceApplication($db, $appId, 'reviewed', null, null, 'selftest', null);
    check('admin onayı → herkese açık bot sınırsız', isUnlimitedBotLimit(getPublicBotLimit($db, $u3)));
    check('admin onayı → bağımsız bot limiti plandan (1)', getIndependentBotLimit($db, $u3) === 1);
    $botsBefore = countUserChatbots($db, $u3, 0);
    reviewMarketplaceApplication($db, $appId, 'rejected', 'Onay geri alındı (selftest).', null, 'selftest', null);
    check('onay geri alınınca → plan limitine döner', getPublicBotLimit($db, $u3) === 2);
    check('onay geri alınınca → mevcut botlar silinmez', countUserChatbots($db, $u3, 0) === $botsBefore);
} finally {
    $conn->rollBack();
}
$limits = (string) file_get_contents(__DIR__ . '/../functions/chatbot_limits.php');
check('getPublicBotLimit aktif satıcı şartına bakmıyor', !preg_match('/function getPublicBotLimit[\s\S]{0,200}isActiveMarketplaceSeller/', $limits));

echo "\n=== H) Arayüz: erişimsiz ücretli bot uyarısı (N-52), yayındaki bot rozeti (N-53) ===\n\n";
$web  = __DIR__ . '/../../web/src/app/dashboard';
$chat = (string) @file_get_contents("$web/chat/page.jsx");
$list = (string) @file_get_contents("$web/chatbots/page.jsx");
$cbc  = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/ChatbotController.php');
check('getchatbot yanıtı can_chat taşıyor (sunucunun erişim kararı)', str_contains($body($cbc, 'getChatbot'), "'can_chat'"));
check('sohbet sayfası can_chat=false iken uyarı gösteriyor', str_contains($chat, 'canChat === false') && str_contains($chat, 'Bu bot ücretli'));
check('sohbet sayfası erişim yokken karşılamayı kaydetmiyor', (bool) preg_match('/canChat[\s\S]{0,400}addchat\.php|addchat\.php[\s\S]{0,0}/', $chat) && str_contains($chat, 'if (canChat === false) return;'));
check('rozet: fiyatsız herkese açık bot "Yayında"', str_contains($list, '"Yayında"'));
check('"Aktif & Çalışır" sayacı yayındaki ücretsiz botu da sayıyor', (bool) preg_match('/const active = chatbots\.filter\([\s\S]{0,200}isLivePublic/', $list));
finish();
