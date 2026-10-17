<?php
/**
 * Gemini teşhis öz-testi — çevrimdışı, ağa çıkmaz, veritabanına dokunmaz.
 *
 *   php api/database/gemini_selftest.php
 *
 * A) Google hata gövdesi (dizi içinde ya da düz) durum / neden / mesaja ayrışıyor.
 * B) Log ve admin çıktısında anahtar hiçbir biçimde (ham, URL-kodlu) görünmüyor.
 * C) Sohbet ucu modeli ve URL'yi tek kaynaktan (functions/gemini.php) alıyor.
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

$lib = __DIR__ . '/../functions/gemini.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!function_exists('geminiParseError') || !function_exists('geminiErrorSummary') || !defined('GEMINI_MODEL')) {
    check('functions/gemini.php: GEMINI_MODEL, geminiParseError, geminiErrorSummary', false, 'tanımlı değil');
    finish();
}

$key = 'AIzaTEST-anahtar_123/+=';

echo "\n=== A) Google hata gövdesi ayrıştırma ===\n\n";
$invalid = '[{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT",'
         . '"details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"API_KEY_INVALID","domain":"googleapis.com"}]}}]';
$e = geminiParseError($invalid);
check('dizi içindeki hata: status', $e['status'] === 'INVALID_ARGUMENT', $e['status']);
check('dizi içindeki hata: reason', $e['reason'] === 'API_KEY_INVALID', $e['reason']);
check('dizi içindeki hata: mesaj', str_starts_with($e['message'], 'API key not valid'), $e['message']);
$e = geminiParseError('{"error":{"code":404,"message":"models/x is not found","status":"NOT_FOUND"}}');
check('düz hata: NOT_FOUND (model kaldırılmış)', $e['status'] === 'NOT_FOUND' && $e['reason'] === '');
$e = geminiParseError('<html>Bad Gateway</html>');
check('JSON olmayan gövde: mesaj kırpılmış ham metin', $e['status'] === '' && str_contains($e['message'], 'Bad Gateway'));

echo "\n=== B) Anahtar sızmıyor ===\n\n";
$echoed = '{"error":{"code":403,"message":"Consumer \'api_key:' . $key . '\' has been suspended. url=?key=' . urlencode($key) . '","status":"PERMISSION_DENIED"}}';
$s = geminiErrorSummary(403, '', $echoed, $key);
check('özet HTTP kodunu içeriyor', str_contains($s, 'http=403'), $s);
check('özet Google durumunu içeriyor', str_contains($s, 'PERMISSION_DENIED'), $s);
check('ham anahtar yok', !str_contains($s, $key), $s);
check('URL-kodlu anahtar yok', !str_contains($s, urlencode($key)), $s);
check('maskelenmiş işaret var', str_contains($s, '«gizli»'), $s);
$s = geminiErrorSummary(0, 'Could not resolve host', '', $key);
check('ağ hatası: curl mesajı', str_contains($s, 'http=0') && str_contains($s, 'Could not resolve host'), $s);

echo "\n=== C) Tek kaynak ===\n\n";
$url = geminiStreamUrl($key);
check('URL kodun modelini kullanıyor', str_contains($url, '/models/' . GEMINI_MODEL . ':streamGenerateContent?alt=sse'), $url);
$ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/ChatController.php');
check('ChatController modeli sabit metin olarak tutmuyor', !preg_match('#/models/gemini-[^:\'"]+:#', $ctl));
check('ChatController geminiStreamUrl kullanıyor', str_contains($ctl, 'geminiStreamUrl('));
check('ChatController hatayı geminiErrorSummary ile logluyor (ham gövde değil)', str_contains($ctl, 'geminiErrorSummary(')
    && !str_contains($ctl, "mb_substr(\$errorBody"));

finish();
