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
    'account_type'          => 'kurumsal',
    'company_title'         => 'Örnek Yazılım Ltd. Şti.',
    'tax_number'            => '1234567890',
    'tax_office'            => 'Kadıköy',
    'mersis_no'             => '0123456789012345',
    'authorized_first_name' => 'Ayşe',
    'authorized_last_name'  => 'Yılmaz',
    'authorized_birth_date' => '1990-01-01',
    'iban'                  => 'TR33 0006 1005 1978 6457 8413 26',
    'il'                    => 'İstanbul',
    'ilce'                  => 'Kadıköy',
    'address'               => 'Örnek Mah. Deneme Sk. No:1',
];
$errorsOf = function (array $input): array {
    try { validateMarketplaceApplication($input); return []; }
    catch (ValidationException $e) { return $e->getErrors() ?: ['_' => $e->getMessage()]; }
};
$cleanOf = fn(array $input) => validateMarketplaceApplication($input);

echo "\n=== A) Doğrulama ===\n\n";

check('geçerli kurumsal başvuru kabul', $errorsOf($valid) === [], json_encode($errorsOf($valid), JSON_UNESCAPED_UNICODE));
$c = $cleanOf($valid);
check('IBAN normalize edilir (boşluksuz, büyük harf)', ($c['iban'] ?? '') === 'TR330006100519786457841326');
check('bilinmeyen alan reddedilir (beyaz liste)', isset($errorsOf($valid + ['status' => 'reviewed'])['_fields']));
check('bireysel hesap türü reddedilir (GK-8)', isset($errorsOf(['account_type' => 'bireysel'] + $valid)['account_type']));
check('kurumsalda 11 haneli vergi no reddedilir', isset($errorsOf(['tax_number' => '10000000146'] + $valid)['tax_number']));
check('kurumsalda MERSİS zorunlu (GK-24)', isset($errorsOf(['mersis_no' => ''] + $valid)['mersis_no']));
check('MERSİS 16 hane rakam', isset($errorsOf(['mersis_no' => '123'] + $valid)['mersis_no']));
$sahis = ['account_type' => 'sahis', 'tax_number' => '10000000146', 'mersis_no' => ''] + $valid;
check('şahısta TCKN + MERSİS boş kabul (GK-24)', $errorsOf($sahis) === [], json_encode($errorsOf($sahis), JSON_UNESCAPED_UNICODE));
check('şahısta VKN de kabul', $errorsOf(['tax_number' => '1234567890'] + $sahis) === []);
check('şahısta hatalı TCKN reddedilir', isset($errorsOf(['tax_number' => '12345678901'] + $sahis)['tax_number']));
check('geçersiz IBAN reddedilir', isset($errorsOf(['iban' => 'TR000006100519786457841326'] + $valid)['iban']));
$under = date('Y-m-d', strtotime('-17 years'));
check('18 yaş altı yetkili reddedilir', isset($errorsOf(['authorized_birth_date' => $under] + $valid)['authorized_birth_date']));
check('geçersiz tarih reddedilir', isset($errorsOf(['authorized_birth_date' => '1990-02-31'] + $valid)['authorized_birth_date']));
check('zorunlu alan boşsa reddedilir', isset($errorsOf(['company_title' => '  '] + $valid)['company_title']));
check('maskIban son 4 hane', maskIban('TR330006100519786457841326') === 'TR** **** **** **** **** **13 26'
    || str_ends_with(maskIban('TR330006100519786457841326'), '1326'), maskIban('TR330006100519786457841326'));

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
    $r = submitMarketplaceApplication($db, $uid, $cleanOf(['company_title' => 'Yeni Unvan A.Ş.'] + $valid));
    $row = getMarketplaceApplication($db, $uid);
    check('rejected → yeniden gönderim submitted olur', ($row['status'] ?? null) === 'submitted');
    check('yeniden gönderim aynı satırı günceller (GK-20)',
        (int) $db->selectSingle('COUNT(*) AS c FROM marketplace_applications WHERE user_id = ?', [$uid])['c'] === 1
        && ($row['company_title'] ?? '') === 'Yeni Unvan A.Ş.');
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

finish();
