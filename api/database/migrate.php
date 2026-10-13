<?php
/**
 * Migration çalıştırıcı — `php api/database/migrate.php`
 *
 * DB-002 🟠 / ARCH-003 🟠: `api/database/migrations/` altında yüksek kaliteli,
 * canlı veriye karşı ölçülmüş üç migration duruyordu ama (a) hiçbiri versiyon
 * kontrolünde değildi, (b) sırayı zorlayan ya da neyin uygulandığını hatırlayan
 * hiçbir araç yoktu. Sonuç: "yeni bir sunucuda güvenilir kurulabilir mi?"
 * sorusunun cevabı hayırdı.
 *
 * Bu script:
 *   • dosyaları ada göre sıralayıp (001, 002, …) sırayla uygular,
 *   • uygulananları `schema_migrations` tablosunda tutar, tekrar çalıştırmaz,
 *   • ertelenenleri `schema_migrations_deferred`'da tutar, onaysız çalıştırmaz,
 *   • her dosyayı kendi transaction'ında çalıştırır (DDL örtük commit
 *     yaptığında bunu söyler, sessizce geçmez),
 *   • ilk hatada durur; sonraki dosyalar çalışmaz, çıkış kodu 1,
 *   • VARSAYILAN OLARAK KURU ÇALIŞIR. Gerçekten uygulamak için --apply gerekir.
 *
 * Veri silen migration'lar ayrıca --allow-destructive ister; --apply tek
 * başına onları çalıştırmaz. `pending/` dizini hiçbir modda okunmaz.
 *
 * Kullanım (yerel):
 *   php api/database/migrate.php                  # ne yapılacağını listeler
 *   php api/database/migrate.php --apply          # yıkıcı olmayanları uygular
 *   php api/database/migrate.php --status
 *
 * Canlı — YALNIZCA scripts/deploy-backend.sh üzerinden (kilit + yedek + log):
 *   --bootstrap --backup=F            başlangıç kaydı; tablo doluysa hiçbir şey yapmaz
 *   --apply --auto --backup=F         yıkıcı olmayan, ertelenmemiş, ön kontrolü
 *                                     geçen dosyalar; diğerleri atlanıp yazılır
 *   --apply --approve=DOSYA --backup=F [--allow-destructive]
 *                                     tek dosya, kullanıcı onayıyla
 *   --env=DOSYA                       .env yolu (deploy betiği canlı dizindekini verir)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Bu script yalnızca komut satırından çalıştırılabilir.\n");
}

$args = array_slice($argv, 1);
$opt  = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (strpos($a, "--$name=") === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};

require_once __DIR__ . '/../functions/env.php';

// Deploy betiği repo kopyasından çalışır; .env canlı dizinde. Varsayılan
// yükleyiciden ÖNCE yüklenir (env_load ilk gelen değeri tutar).
$envFile = $opt('env');
if ($envFile !== null) {
    if (!is_file($envFile)) {
        fwrite(STDERR, "--env: dosya yok: $envFile\n");
        exit(1);
    }
    env_load($envFile);
}

require_once __DIR__ . '/../functions/db.php';
require_once __DIR__ . '/migration_lib.php';

$dir = __DIR__ . '/migrations';
$log = static function (string $line): void {
    echo $line, "\n";
};

try {
    $conn = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, 'Veritabanına bağlanılamadı: ' . $e->getMessage() . "\n");
    exit(1);
}

if (in_array('--bootstrap', $args, true)) {
    $why = migrationCheckBackup($opt('backup'));
    if ($why !== null) {
        $log("DURDU: $why");
        exit(1);
    }
    try {
        migrationBootstrap($conn, $dir, $log);
    } catch (Throwable $e) {
        $log('DURDU: ' . $e->getMessage());
        exit(1);
    }
    exit(0);
}

$apply = in_array('--apply', $args, true) && !in_array('--status', $args, true);
$auto  = in_array('--auto', $args, true);

$result = migrationRun($conn, [
    'dir'              => $dir,
    'apply'            => $apply,
    'auto'             => $auto,
    'allowDestructive' => in_array('--allow-destructive', $args, true),
    'approve'          => $opt('approve'),
    'backup'           => $opt('backup'),
], $log);

echo "\n";
foreach ($result['skipped'] as $name => $why) {
    echo "ATLANDI: $name — $why\n";
}

if ($result['failed'] !== null) {
    echo "HATA: {$result['failed']} başarısız oldu. Yukarıdaki hatayı giderip tekrar çalıştırın.\n";
    exit(1);
}
if ($result['error'] !== null) {
    exit(1);
}
if (!$apply && $result['pending'] !== []) {
    echo count($result['pending']) . " migration bekliyor. Uygulamak için: php api/database/migrate.php --apply\n";
    echo "Veri silen dosyalar için ayrıca --allow-destructive gerekir.\n";
} elseif ($apply) {
    echo count($result['applied']) . " migration uygulandı.\n";
} else {
    echo "Her şey güncel.\n";
}
