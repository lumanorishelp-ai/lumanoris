<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../functions/uploads.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $label\n"; }
    else     { $fail++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "\n=== A) Yol doğrulama ===\n\n";
check('assets/ önekli yol kabul', uploadsNormalizeAsset('assets/kapak_fotografi/1792421536_12ded4db0ed0345f.jpg') === 'kapak_fotografi/1792421536_12ded4db0ed0345f.jpg');
check('/assets/ önekli yol kabul', uploadsNormalizeAsset('/assets/profil_fotografi/1_ab.webp') === 'profil_fotografi/1_ab.webp');
check('öneksiz yol kabul', uploadsNormalizeAsset('profil_fotografi/1_ab.png') === 'profil_fotografi/1_ab.png');
foreach (['../.env', 'kapak_fotografi/../../.env', 'kapak_fotografi/x.php', 'baska/1_ab.png', 'kapak_fotografi/1_ab.png.php', 'kapak_fotografi/1_AB.png', ''] as $bad) {
    check("reddedilir: '$bad'", uploadsNormalizeAsset($bad) === null);
}
check('MIME uzantıdan', uploadsMime('a.webp') === 'image/webp' && uploadsMime('a.JPG') === 'image/jpeg');

echo "\n=== B) Yedek ve geri yükleme ===\n\n";
$tmp = sys_get_temp_dir() . '/uploads_selftest_' . bin2hex(random_bytes(4));
putenv('UPLOADS_BACKUP_DIR=' . $tmp);
$_ENV['UPLOADS_BACKUP_DIR'] = $tmp;
check('yedek klasörü ortamdan', uploadsBackupDir() === $tmp, uploadsBackupDir());

$name  = '1000000000_' . bin2hex(random_bytes(8)) . '.png';
$rel   = 'profil_fotografi/' . $name;
$live  = uploadsAssetsDir() . '/' . $rel;
$bytes = "\x89PNG\r\n\x1a\n" . random_bytes(32);
@mkdir(dirname($live), 0755, true);
try {
    file_put_contents($live, $bytes);
    check('yükleme sonrası yedek alınır', uploadsBackupCopy('assets/' . $rel) && is_file("$tmp/$rel"));
    check('yedek içerik aynı', @file_get_contents("$tmp/$rel") === $bytes);
    unlink($live);
    $got = uploadsRestore('assets/' . $rel);
    check('silinen görsel yedekten geri gelir', $got === $live && is_file($live));
    check('geri gelen içerik aynı', @file_get_contents($live) === $bytes);
    check('dosya varken aynen döner', uploadsRestore($rel) === $live);
    check('yedekte olmayan görsel null', uploadsRestore('profil_fotografi/1000000000_00000000000000ff.png') === null);
    check('geçersiz yol null', uploadsRestore('../functions/db.php') === null);
    check('olmayan dosyanın yedeği alınmaz', uploadsBackupCopy('profil_fotografi/1000000000_00000000000000ff.png') === false);
} finally {
    @unlink($live);
    @unlink("$tmp/$rel");
    @rmdir("$tmp/profil_fotografi");
    @rmdir($tmp);
}

echo "\n=== C) Bağlantılar ===\n\n";
$root  = dirname(__DIR__);
$ht    = (string) file_get_contents("$root/.htaccess");
$aht   = (string) file_get_contents("$root/assets/.htaccess");
$cb    = (string) file_get_contents("$root/src/Presentation/Controllers/ChatbotController.php");
$guard = (string) file_get_contents("$root/admin/functions/upload_guard.php");
check('kök .htaccess eksik görseli asset_restore.php\'ye yönlendirir', str_contains($ht, 'asset_restore.php?p=$1') && str_contains($ht, '!-f'));
check('assets/.htaccess eksik görseli asset_restore.php\'ye yönlendirir', str_contains($aht, '/asset_restore.php?p=$1') && str_contains($aht, '!-f'));
check('assets/ içinde PHP hâlâ kapalı', str_contains($aht, 'php_flag engine off'));
check('kullanıcı yüklemesi yedeklenir', str_contains($cb, 'uploadsBackupCopy('));
check('admin yüklemesi yedeklenir', str_contains($guard, 'uploadsBackupCopy('));

echo "\n=== SONUÇ: $pass geçti, $fail başarısız ===\n\n";
exit($fail > 0 ? 1 : 0);
