<?php
/**
 * Sır taraması öz-testi (N-55) — çevrimdışı, veritabanına dokunmaz.
 *
 *   php api/database/secret_scan_selftest.php
 *
 * A) Dedektör: gerçek anahtar biçimleri yakalanıyor, yer tutucular geçiyor.
 * B) Repodaki tüm `.env*.example` dosyaları temiz (gerçek değer/anahtar yok).
 *
 * 2026-10-18: api/.env.example'a çalışma ağacında gerçek bir Gemini anahtarı
 * yazılmıştı (commit edilmeden fark edildi). Bu test aynı şeyin commit
 * öncesi kapıda (verify.sh) yakalanmasını sağlar. Test verisindeki
 * anahtarlar çalışma anında rastgele üretilir; dosyada gerçek değer yok.
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

$lib = __DIR__ . '/../functions/secret_scan.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!function_exists('secretScanEnvExample') || !function_exists('secretScanText')) {
    check('functions/secret_scan.php: secretScanEnvExample, secretScanText', false, 'tanımlı değil');
    finish();
}

function rnd(string $alphabet, int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $s;
}
$b64u = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
$fakeAq    = 'AQ.' . rnd($b64u, 50);
$fakeAiza  = 'AIza' . rnd($b64u, 35);
$fakeLong  = rnd('abcdef0123456789', 40);

echo "\n=== A) Dedektör ===\n\n";
check('AQ. biçimli Google anahtarı (2026-10-18 olayı)', secretScanEnvExample("API_GOOGLE_GEMINI=$fakeAq\n") !== []);
check('AIza biçimli Google anahtarı', secretScanEnvExample("X=$fakeAiza\n") !== []);
check('yoruma alınmış satırdaki anahtar da yakalanıyor', secretScanEnvExample("# API_GOOGLE_GEMINI=$fakeAiza\n") !== []);
check('tırnak içindeki anahtar', secretScanEnvExample("API_GOOGLE_GEMINI=\"$fakeAq\"\n") !== []);
check('sır adlı değişkende uzun rastgele değer', secretScanEnvExample("HOPPA_LIVE_MERCHANT_KEY=$fakeLong\n") !== []);
check('sır adlı değişkende kısa ama gerçek görünen parola', secretScanEnvExample("DB_PASS=Xk9!pq72\n") !== []);
check('boş sır değeri geçer', secretScanEnvExample("DB_PASS=\nIYZICO_API_KEY=\n") === []);
check('yer tutucu geçer (your-…, buraya-…)', secretScanEnvExample(
    "PARAM_RECONCILE_SECRET=your-secret-key\nADMIN_PASSWORD=buraya-sifrenizi-yazin\n") === []);
check('sır olmayan değer geçer (URL, mod, sayı)', secretScanEnvExample(
    "IYZICO_BASE_URL=https://sandbox-api.iyzipay.com\nHOPPA_MODE=test\nSMTP_PORT=587\nAPP_DEBUG=false\n") === []);
$hit = secretScanEnvExample("API_GOOGLE_GEMINI=$fakeAq\n");
check('bulgu değeri tekrar etmiyor (satır no + değişken adı)', $hit !== [] && !str_contains(implode(' ', $hit), $fakeAq),
    implode(' | ', $hit));
check('düz metin taraması: kaynak koddaki anahtar', secretScanText("\$k = '$fakeAiza';") !== []);
check('düz metin taraması: anahtarsız kod geçer', secretScanText("\$k = getenv('API_GOOGLE_GEMINI');") === []);

echo "\n=== B) Repodaki .env örnekleri ===\n\n";
$root = realpath(__DIR__ . '/../..');
$files = [];
$out = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files 2>&1', $out, $code);
if ($code !== 0) {
    check('git ls-files', false, implode(' ', $out));
    finish();
}
foreach ($out as $f) {
    if (preg_match('#(^|/)\.env[^/]*\.example$|(^|/)\.env\.example[^/]*$#', $f)) {
        $files[] = $f;
    }
}
check('en az bir .env örneği bulundu', $files !== [], 'yok');
foreach ($files as $f) {
    $hits = secretScanEnvExample((string) file_get_contents($root . '/' . $f));
    check("$f temiz", $hits === [], implode(' | ', $hits));
}

finish();
