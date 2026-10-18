<?php
/**
 * Composer bağımlılıkları öz-testi (N-56) — çevrimdışı, veritabanına dokunmaz.
 *
 *   php api/database/vendor_selftest.php
 *
 * Canlıda belge yükleme "Failed opening required …/vendor/autoload.php" ile
 * düştü: sunucudaki vendor/ eksik/eski, deploy zip'leri vendor'suzdu ve
 * `require_once` bulunamayan dosyada \Error fırlatıp ham yola kadar her şeyi
 * kullanıcıya gösteriyordu (APP_DEBUG=true).
 *
 * A) VendorAutoload: dosya yok / sınıf yok → false döner, fırlatmaz, loga yazar.
 * B) Gerçek vendor/ (api ve admin) yerelde kurulu ve gereken sınıflar yükleniyor.
 * C) Hiçbir controller vendor/autoload.php'yi doğrudan require etmiyor.
 * D) composer.lock'taki her paket canlıdaki PHP 8.2 ile uyumlu (api + admin).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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

$api = realpath(__DIR__ . '/..');
$lib = $api . '/src/Shared/Utilities/VendorAutoload.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!class_exists('VendorAutoload')) {
    check('src/Shared/Utilities/VendorAutoload.php: VendorAutoload sınıfı', false, 'tanımlı değil');
    finish();
}

// Loga gidenleri yakala (test çıktısını kirletmesin, içeriği kontrol edilsin).
$logFile = tempnam(sys_get_temp_dir(), 'vlog');
ini_set('error_log', $logFile);
$readLog = function () use ($logFile): string {
    $s = (string) file_get_contents($logFile);
    file_put_contents($logFile, '');
    return $s;
};

echo "\n=== A) Eksik / eski vendor ===\n\n";
$missing = sys_get_temp_dir() . '/yok-' . bin2hex(random_bytes(4)) . '/vendor/autoload.php';
$threw = null;
try { $ok = VendorAutoload::load('test', [], $missing); } catch (\Throwable $e) { $threw = $e; $ok = null; }
check('autoload dosyası yok → fırlatmıyor', $threw === null, $threw ? get_class($threw) . ': ' . $threw->getMessage() : '');
check('autoload dosyası yok → false', $ok === false);
$log = $readLog();
check('log: özellik etiketi + eksik yol + çözüm', str_contains($log, '[vendor] test') && str_contains($log, $missing)
    && str_contains($log, 'composer install'), trim($log));

$stale = tempnam(sys_get_temp_dir(), 'al') . '.php';
file_put_contents($stale, "<?php // sınıf kaydetmeyen eski autoload\n");
$ok = VendorAutoload::load('test', ['Hic\\Olmayan\\Sinif'], $stale);
check('autoload var ama sınıf yok (eski vendor) → false', $ok === false);
check('log: eksik sınıf adı', str_contains($readLog(), 'Hic\\Olmayan\\Sinif'));
@unlink($stale);

$broken = tempnam(sys_get_temp_dir(), 'al') . '.php';
file_put_contents($broken, "<?php throw new \\RuntimeException('platform_check: PHP sürümü düşük');\n");
$threw = null;
try { $ok = VendorAutoload::load('test', [], $broken); } catch (\Throwable $e) { $threw = $e; }
check('autoload yüklenirken fırlatırsa → yakalanıyor, false', $threw === null && $ok === false);
check('log: fırlatılan mesaj', str_contains($readLog(), 'platform_check'));
@unlink($broken);

echo "\n=== B) Yerel vendor kurulu ===\n\n";
check('api/vendor/autoload.php varsayılan yol', VendorAutoload::path() === $api . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'
    || str_replace('\\', '/', VendorAutoload::path()) === str_replace('\\', '/', $api . '/vendor/autoload.php'), VendorAutoload::path());
check('PDF + Google sınıfları yükleniyor', VendorAutoload::load('test', VendorAutoload::PDF_CLASSES)
    && VendorAutoload::load('test', VendorAutoload::GOOGLE_CLASSES), trim($readLog()));
check('admin/vendor/autoload.php var', is_file($api . '/admin/vendor/autoload.php'));

echo "\n=== C) Doğrudan require kalmadı ===\n\n";
foreach (glob($api . '/src/Presentation/Controllers/*.php') as $f) {
    $src = (string) file_get_contents($f);
    if (preg_match('#(require|include)(_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"][./]*vendor/autoload\.php#', $src)) {
        check(basename($f) . ' vendor/autoload.php\'yi doğrudan require ediyor', false, 'VendorAutoload::requireOrFail kullanılmalı');
    }
}
check('controller taraması tamamlandı', true);

echo "\n=== D) PHP 8.2 uyumu (composer.lock) ===\n\n";
/** Basit composer kısıtı denetimi: ^, ~, >=, >, <=, <, =, x.y.*, ||, boşluk/virgül (VE). */
function satisfies(string $version, string $constraint): bool {
    foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) as $alt) {
        $all = true;
        foreach (preg_split('/\s*,\s*|\s+/', trim($alt)) as $c) {
            if ($c === '' || $c === '*') continue;
            if (!preg_match('/^(\^|~|>=|<=|>|<|==?|!=)?v?(\d+(?:\.\d+)*)(\.\*)?$/', $c, $m)) { $all = false; break; }
            [$op, $v] = [$m[1] ?? '', $m[2]];
            $parts = array_map('intval', explode('.', $v));
            if ($op === '^') {
                $upper = $parts[0] > 0 ? ($parts[0] + 1) . '.0.0' : '0.' . (($parts[1] ?? 0) + 1) . '.0';
                $ok = version_compare($version, $v, '>=') && version_compare($version, $upper, '<');
            } elseif ($op === '~') {
                // ~X.Y → <X+1.0.0 · ~X.Y.Z → <X.Y+1.0
                $upper = count($parts) >= 3 ? $parts[0] . '.' . ($parts[1] + 1) . '.0' : ($parts[0] + 1) . '.0.0';
                $ok = version_compare($version, $v, '>=') && version_compare($version, $upper, '<');
            } elseif (!empty($m[3])) {
                $ok = str_starts_with($version . '.', $v . '.');
            } elseif ($op === '' || $op === '=' || $op === '==') {
                $ok = version_compare($version, $v, '==');
            } else {
                $ok = version_compare($version, $v, $op);
            }
            if (!$ok) { $all = false; break; }
        }
        if ($all) return true;
    }
    return false;
}
check('kısıt denetleyicisi: ^8.1 ⊇ 8.2', satisfies('8.2.0', '^8.1'));
check('kısıt denetleyicisi: ^7.2.5 || ^8.0 ⊇ 8.2', satisfies('8.2.0', '^7.2.5 || ^8.0'));
check('kısıt denetleyicisi: >=8.3 ⊉ 8.2', !satisfies('8.2.0', '>=8.3'));
check('kısıt denetleyicisi: ^8.4 ⊉ 8.2', !satisfies('8.2.0', '^8.4'));

foreach (['api' => $api, 'admin' => $api . '/admin'] as $name => $dir) {
    $lock = json_decode((string) @file_get_contents($dir . '/composer.lock'), true);
    if (!is_array($lock)) {
        check("$name/composer.lock okunuyor", false);
        continue;
    }
    $bad = [];
    foreach ($lock['packages'] ?? [] as $p) {
        $req = $p['require']['php'] ?? null;
        if ($req !== null && !satisfies('8.2.0', $req)) {
            $bad[] = $p['name'] . ' ' . $p['version'] . " (php $req)";
        }
    }
    check("$name/composer.lock: " . count($lock['packages'] ?? []) . ' paket PHP 8.2 ile uyumlu', $bad === [], implode(', ', $bad));
}

echo "\n=== E) Kullanılmayan Google servisleri ayıklanıyor ===\n\n";
// apiclient-services ~36 bin dosya (vendor'un %98'i); yalnızca verifyIdToken
// kullanılıyor. Ayıklama olmadan deploy zip'i cPanel'de açılamayacak kadar büyük.
$cj = json_decode((string) file_get_contents($api . '/composer.json'), true);
check('composer.json: pre-autoload-dump → Google\\Task\\Composer::cleanup',
    ($cj['scripts']['pre-autoload-dump'] ?? '') === 'Google\\Task\\Composer::cleanup');
check('composer.json: tutulacak servis listesi boş değil (boşsa cleanup hiçbir şey yapmaz)',
    !empty($cj['extra']['google/apiclient-services']));
if (is_dir($api . '/vendor/google/apiclient-services/src')) {
    $n = count(glob($api . '/vendor/google/apiclient-services/src/*', GLOB_ONLYDIR));
    check("yerel vendor ayıklanmış ($n servis dizini)", $n <= 5, 'composer install yeniden çalıştırılmalı');
}

@unlink($logFile);
finish();
