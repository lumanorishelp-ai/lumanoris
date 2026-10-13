<?php
/**
 * scripts/deploy-backend.sh yardımcısı — mysqldump için geçici seçenek dosyası.
 *
 *   php api/database/deploy_mysql_defaults.php --env=/yol/.env --out=/yol/dosya.cnf
 *
 * .env'i uygulamanın kendi yükleyicisiyle (env_load) okur, `[client]`
 * bölümüne kullanıcı/parola/sunucu/port yazar (0600) ve stdout'a YALNIZCA
 * veritabanı adını basar. Parola komut satırına hiç çıkmaz (`ps` ile
 * görünmez); dosyayı çağıran betik iş bitince siler.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../functions/env.php';

$opts = getopt('', ['env:', 'out:']);
$env  = $opts['env'] ?? null;
$out  = $opts['out'] ?? null;
if (!is_string($env) || !is_string($out) || $env === '' || $out === '') {
    fwrite(STDERR, "kullanım: --env=DOSYA --out=DOSYA\n");
    exit(1);
}
if (!is_file($env)) {
    fwrite(STDERR, "--env: dosya yok: $env\n");
    exit(1);
}
env_load($env);

foreach (['DB_HOST', 'DB_USER', 'DB_NAME'] as $key) {
    if (trim((string) env_get($key, '')) === '') {
        fwrite(STDERR, "$key tanımlı değil: $env\n");
        exit(1);
    }
}

[$host, $port] = array_pad(explode(':', (string) env_get('DB_HOST')), 2, '3306');
$q = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

$cnf = "[client]\n"
     . 'user=' . $q((string) env_get('DB_USER')) . "\n"
     . 'password=' . $q((string) env_get('DB_PASS', '')) . "\n"
     . 'host=' . $q($host) . "\n"
     . 'port=' . (int) $port . "\n";

$old = umask(0077);
$ok  = file_put_contents($out, $cnf) !== false;
umask($old);
if (!$ok) {
    fwrite(STDERR, "yazılamadı: $out\n");
    exit(1);
}
@chmod($out, 0600);

echo env_get('DB_NAME'), "\n";
