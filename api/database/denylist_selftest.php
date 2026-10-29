<?php
/**
 * Denylist öz-testi (N-73) — veritabanı yazmaz; yerel `php -S` başlatır.
 *
 *   php api/database/denylist_selftest.php
 *
 * Yerel sağlık kontrolü (2026-10-29): canlıda `GET /router.php` 200 dönüyordu
 * (gövde boş; kaynak sızmıyor ama yerel geliştirme yönlendiricisinin canlıda
 * çalıştırılabilir olmasına gerek yok). Denylist üç yerde (CLAUDE.md):
 *   A) api/.htaccess  — canlı Apache (yerelde Apache yok: içerik kontrolü)
 *   B) api/router.php — yerel `php -S` (gerçek istek)
 * api/admin/.htaccess yalnızca admin/ altını kapsar; router.php orada değil.
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

echo "\n=== A) api/.htaccess ===\n\n";
$ht = (string) file_get_contents($api . '/.htaccess');
preg_match_all('/<FilesMatch "([^"]+)">/', $ht, $m);
$secretBlocks = array_values(array_filter($m[1], fn ($p) => str_contains($p, 'error_log')));
check('iki "secrets" FilesMatch bloğu var (2.4 + 2.2)', count($secretBlocks) === 2, (string) count($secretBlocks));
foreach ($secretBlocks as $i => $p) {
    check('blok ' . ($i + 1) . ': router.php reddediliyor', (bool) preg_match('~' . $p . '~', 'router.php'));
    check('blok ' . ($i + 1) . ': uygulama uçları reddedilmiyor (index.php, login.php)', !preg_match('~' . $p . '~', 'index.php') && !preg_match('~' . $p . '~', 'login.php'));
}
check('Apache tarafında hiçbir kural router.php\'ye yönlendirmiyor', !preg_match('/RewriteRule[^\n]*router\.php/i', $ht));

echo "\n=== B) api/router.php (php -S) ===\n\n";
$sock = @stream_socket_server('tcp://127.0.0.1:0');
$port = $sock ? (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1) : 0;
if ($sock) {
    fclose($sock);
}
$proc = $port ? proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'], [1 => ['file', $devnull = (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w'], 2 => ['file', $devnull, 'w']], $pipes, $api) : false;
if (!$proc) {
    check('php -S başlatılabildi', false);
    finish();
}
$get = function (string $path) use ($port): int {
    for ($i = 0; $i < 40; $i++) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        if ($body !== false && isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mm)) {
            return (int) $mm[1];
        }
        usleep(100000);
    }
    return 0;
};
try {
    check('GET /router.php → 404', ($s = $get('/router.php')) === 404, (string) $s);
    check('GET /ROUTER.PHP → 404 (büyük/küçük harf)', ($s = $get('/ROUTER.PHP')) === 404, (string) $s);
    check('normal yönlendirme bozulmadı: /api/auth/sessioncheck.php → 200', ($s = $get('/api/auth/sessioncheck.php')) === 200, (string) $s);
    check('mevcut kural hâlâ çalışıyor: /.env → 404', ($s = $get('/.env')) === 404, (string) $s);
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

finish();
