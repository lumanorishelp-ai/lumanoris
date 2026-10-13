<?php
/**
 * migrate.php'nin test edilebilir parçaları. `api/database/` web'e kapalı.
 *
 * Çalıştırıcının tüm kararları (neyin uygulanacağı, neyin atlanacağı, ne
 * zaman durulacağı) buradaki fonksiyonlarda; migrate.php yalnızca argümanları
 * okuyup bunları çağırıyor. migrate_selftest.php aynı fonksiyonları geçici
 * (TEMPORARY) tablolar ve sahte migration dizinleriyle sınıyor.
 */

/**
 * N-25 — checksum satır sonundan bağımsız: CRLF ve tek CR, LF'ye çevrilip
 * öyle hash'leniyor. LF dosyada sonuç eski `hash('sha256', $sql)` ile aynı,
 * yani mevcut schema_migrations kayıtları geçerli kalır. Windows'ta
 * core.autocrlf=true ile alınmış bir kopya artık "DOSYA DEĞİŞMİŞ" görünmez.
 */
function migrationChecksum(string $sql): string
{
    return hash('sha256', str_replace(["\r\n", "\r"], "\n", $sql));
}

/**
 * Canlı başlangıç kaydı (AÇ-03, canlı şema ölçümü 2026-10-13, phpMyAdmin):
 * canlıda `schema_migrations` yok; 004 ve 010 uygulanmış görünüyor. 001–006
 * bütünlük paketi (tip hizalama, yetim temizliği, FK, collation) ayrı ve
 * onaylı bir turda, yetim ölçümüyle uygulanacak.
 */
const MIGRATION_BOOTSTRAP_APPLIED = [
    '004_add_details_chatbot_id.sql',
    '010_notification_message_columns.sql',
];

/**
 * --auto bu dosyaları kayıtta "ertelendi" olmasalar bile ASLA uygulamaz.
 * Başlangıç kaydı yarıda kalmış ya da `schema_migrations` başka bir yoldan
 * oluşmuş olsa da deploy betiği bütünlük paketine dokunamaz. Bunlar yalnızca
 * `--approve=DOSYA` ile, tek tek uygulanır.
 */
const MIGRATION_DEFERRED = [
    '001_align_key_types.sql',
    '002_clean_orphan_rows.sql',
    '002b_clean_orphan_rows_2.sql',
    '003_add_foreign_keys.sql',
    '005_fix_table_collations.sql',
    '006_fix_fk_delete_rules.sql',
];

/** Veriyi silen/yeniden yazan ifade içeriyor mu? (UPDATE'i YAKALAMAZ.) */
function migrationIsDestructive(string $sql): bool
{
    return (bool) preg_match('/^\s*(DELETE|TRUNCATE|DROP)\b/im', $sql);
}

/** ad => yol; sıra dosya adından gelir (001_, 002_, 002b_, 003_ …). */
function migrationFiles(string $dir): array
{
    $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    $out = [];
    foreach ($files as $file) {
        $out[basename($file)] = $file;
    }
    return $out;
}

function migrationAssertIdent(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $name)) {
        throw new InvalidArgumentException("Geçersiz tablo adı: $name");
    }
    return $name;
}

/**
 * information_schema TEMPORARY tabloları görmüyor; doğrudan sorgulamak hem
 * gerçek hem geçici tabloda çalışıyor (selftest geçici tablo kullanıyor).
 */
function migrationTableExists(PDO $conn, string $table): bool
{
    $table = migrationAssertIdent($table);
    try {
        return $conn->query("SELECT 1 FROM `$table` LIMIT 0") !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Kayıt tabloları: `<tablo>` (her satır = uygulanmış) ve `<tablo>_deferred`
 * (onay bekleyen). Ertelenenler AYRI tabloda: eski bir migrate.php sürümü
 * `schema_migrations`'taki her satırı "uygulanmış" sayıyor; ertelenmiş bir
 * dosyayı orada tutmak onu sessizce "uygulanmış" gösterirdi.
 */
function migrationEnsureTables(PDO $conn, string $table, bool $temporary = false): void
{
    $table = migrationAssertIdent($table);
    $tmp   = $temporary ? 'TEMPORARY ' : '';
    $conn->exec(
        "CREATE {$tmp}TABLE IF NOT EXISTS `$table` (
            filename   VARCHAR(191) PRIMARY KEY,
            checksum   CHAR(64)     NOT NULL,
            applied_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->exec(
        "CREATE {$tmp}TABLE IF NOT EXISTS `{$table}_deferred` (
            filename    VARCHAR(191) PRIMARY KEY,
            checksum    CHAR(64)     NOT NULL,
            reason      VARCHAR(255) NOT NULL,
            deferred_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** [uygulanmış ad => satır, ertelenmiş ad => satır] */
function migrationLoadState(PDO $conn, string $table): array
{
    $table   = migrationAssertIdent($table);
    $applied = [];
    foreach ($conn->query("SELECT filename, checksum, applied_at FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $applied[$row['filename']] = $row;
    }
    $deferred = [];
    if (migrationTableExists($conn, $table . '_deferred')) {
        foreach ($conn->query("SELECT filename, checksum, reason, deferred_at FROM `{$table}_deferred`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $deferred[$row['filename']] = $row;
        }
    }
    return [$applied, $deferred];
}

/**
 * Canlı başlangıç kaydı — yalnızca kayıt tablosu YOKSA ya da BOŞSA çalışır.
 * Dolu bir tabloda hiçbir şey yapmaz (ikinci deploy). Yazmadan önce listedeki
 * her dosyanın var olduğunu doğrular; biri eksikse hiçbir şey yazmaz.
 *
 * Sıra kasıtlı: önce ertelenenler, EN SON uygulanmışlar (tek INSERT). Kayıt
 * tablosunun dolması "başlangıç kaydı bitti" demek; arada kesilirse bir
 * sonraki çalıştırma tablo boş olduğu için baştan tamamlar.
 *
 * @return int yazılan satır sayısı (0 = yapılacak bir şey yoktu)
 */
function migrationBootstrap(
    PDO $conn,
    string $dir,
    callable $log,
    string $table = 'schema_migrations',
    bool $temporary = false,
    array $appliedNames = MIGRATION_BOOTSTRAP_APPLIED,
    array $deferredNames = MIGRATION_DEFERRED
): int {
    $table = migrationAssertIdent($table);

    if (migrationTableExists($conn, $table)
        && (int) $conn->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() > 0) {
        $log("başlangıç kaydı: `$table` dolu — yapılacak bir şey yok");
        return 0;
    }

    $files = migrationFiles($dir);
    $sums  = [];
    foreach (array_merge($appliedNames, $deferredNames) as $name) {
        if (!isset($files[$name])) {
            throw new RuntimeException("başlangıç kaydı: dosya bulunamadı: $name — hiçbir şey yazılmadı");
        }
        $sums[$name] = migrationChecksum((string) file_get_contents($files[$name]));
    }

    migrationEnsureTables($conn, $table, $temporary);

    $written = 0;
    if ($deferredNames !== []) {
        $rows = implode(', ', array_fill(0, count($deferredNames), '(?, ?, ?, NOW())'));
        $args = [];
        foreach ($deferredNames as $name) {
            array_push($args, $name, $sums[$name], 'başlangıç kaydı: bütünlük paketi, onayla uygulanır');
        }
        $stmt = $conn->prepare("INSERT IGNORE INTO `{$table}_deferred` (filename, checksum, reason, deferred_at) VALUES $rows");
        $stmt->execute($args);
        $written += $stmt->rowCount();
        foreach ($deferredNames as $name) {
            $log(sprintf('  [~] %-40s ertelendi', $name));
        }
    }

    if ($appliedNames !== []) {
        $rows = implode(', ', array_fill(0, count($appliedNames), '(?, ?, NOW())'));
        $args = [];
        foreach ($appliedNames as $name) {
            array_push($args, $name, $sums[$name]);
        }
        $stmt = $conn->prepare("INSERT INTO `$table` (filename, checksum, applied_at) VALUES $rows");
        $stmt->execute($args);
        $written += $stmt->rowCount();
        foreach ($appliedNames as $name) {
            $log(sprintf('  [=] %-40s uygulanmış olarak kaydedildi', $name));
        }
    }

    $log("başlangıç kaydı: $written satır yazıldı");
    return $written;
}

/**
 * Yedek kanıtı: dosya var, boş değil, taze ve mysqldump'ın kapanış satırını
 * ("-- Dump completed") taşıyor — yarıda kesilmiş döküm yedek sayılmaz.
 * .gz ise açılıp okunur.
 *
 * @return string|null hata nedeni; null = yedek geçerli
 */
function migrationCheckBackup(?string $path, int $maxAgeSeconds = 3600, ?int $now = null): ?string
{
    if ($path === null || trim($path) === '') {
        return 'yedek dosyası verilmedi (--backup=DOSYA)';
    }
    if (!is_file($path)) {
        return "yedek dosyası yok: $path";
    }
    clearstatcache(true, $path);
    if ((int) filesize($path) === 0) {
        return "yedek dosyası boş: $path";
    }
    $age = ($now ?? time()) - (int) filemtime($path);
    if ($age > $maxAgeSeconds) {
        return "yedek çok eski ($age sn > $maxAgeSeconds sn): $path";
    }

    $isGz = substr($path, -3) === '.gz';
    $h    = $isGz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
    if ($h === false) {
        return "yedek dosyası okunamadı: $path";
    }
    $tail = '';
    while (!($isGz ? gzeof($h) : feof($h))) {
        $chunk = $isGz ? gzread($h, 65536) : fread($h, 65536);
        if ($chunk === false) {
            break;
        }
        $tail = substr($tail . $chunk, -512);
    }
    $isGz ? gzclose($h) : fclose($h);

    if (strpos($tail, '-- Dump completed') === false) {
        return "yedek eksik görünüyor (mysqldump kapanış satırı yok): $path";
    }
    return null;
}

/**
 * 008 öncesi kontrol: UNIQUE eklenecek sütunlarda yinelenen satır varsa
 * ALTER başarısız olur ve --auto zinciri durur. Bunun yerine dosya atlanır.
 *
 * @param array $specs [['tablo', ['sütun', …]], …]
 * @return string|null atlama nedeni; null = temiz
 */
function migrationDuplicatePrecheck(PDO $conn, array $specs): ?string
{
    $found = [];
    foreach ($specs as [$table, $cols]) {
        $table = migrationAssertIdent($table);
        if (!migrationTableExists($conn, $table)) {
            $found[] = "$table tablosu yok";
            continue;
        }
        $colList = implode(', ', array_map(static fn($c) => '`' . migrationAssertIdent($c) . '`', $cols));
        $n = (int) $conn->query(
            "SELECT COUNT(*) FROM (SELECT 1 FROM `$table` GROUP BY $colList HAVING COUNT(*) > 1) x"
        )->fetchColumn();
        if ($n > 0) {
            $found[] = "$table ($colList): $n yinelenen grup";
        }
    }
    return $found === [] ? null : 'yinelenen satır — ' . implode('; ', $found);
}

/** Dosya adı => fn(PDO): ?string (null = devam, metin = atlama nedeni). */
function migrationPrechecks(): array
{
    return [
        '008_missing_unique_keys.sql' => static fn(PDO $c) => migrationDuplicatePrecheck($c, [
            ['chatbot_hide', ['user_id', 'chatbot_id']],
            ['chatbot_uninterested', ['user_id', 'category_id']],
            ['chatbot_in_list', ['list_id', 'chatbot_id']],
        ]),
    ];
}

/**
 * Migration çalıştırıcı.
 *
 * Seçenekler:
 *   dir              migration dizini
 *   table            kayıt tablosu (varsayılan schema_migrations)
 *   temporary        kayıt tablolarını TEMPORARY oluştur (yalnızca selftest)
 *   apply            false = yalnızca listele (kuru)
 *   auto             deploy modu: yedek + başlangıç kaydı şart; ertelenmiş,
 *                    yıkıcı ve ön kontrolü geçemeyen dosyalar atlanır
 *   allowDestructive yıkıcı dosyaya izin (yalnızca elle; auto ile yasak)
 *   approve          yalnızca bu dosyayı uygula (ertelenmiş olsa da); yedek şart
 *   backup           yedek dosyası (auto/approve için zorunlu)
 *   backupMaxAge     saniye (varsayılan 3600)
 *   deferredNames    auto'nun asla uygulamayacağı adlar (varsayılan MIGRATION_DEFERRED)
 *   prechecks        dosya adı => fn(PDO): ?string
 *
 * @return array{applied: string[], skipped: array<string,string>, pending: string[], failed: ?string, error: ?string}
 */
function migrationRun(PDO $conn, array $o, callable $log): array
{
    $o += [
        'table'            => 'schema_migrations',
        'temporary'        => false,
        'apply'            => false,
        'auto'             => false,
        'allowDestructive' => false,
        'approve'          => null,
        'backup'           => null,
        'backupMaxAge'     => 3600,
        'deferredNames'    => MIGRATION_DEFERRED,
        'prechecks'        => migrationPrechecks(),
    ];
    $result = ['applied' => [], 'skipped' => [], 'pending' => [], 'failed' => null, 'error' => null];
    $table  = migrationAssertIdent($o['table']);

    $fail = static function (string $msg) use (&$result, $log): array {
        $result['error'] = $msg;
        $log("DURDU: $msg");
        return $result;
    };

    if ($o['auto'] && $o['approve'] !== null) {
        return $fail('--auto ve --approve birlikte kullanılamaz');
    }
    if ($o['auto'] && $o['allowDestructive']) {
        return $fail('--auto yıkıcı dosya uygulamaz; --allow-destructive yalnızca --approve ile');
    }
    if (($o['auto'] || $o['approve'] !== null) && !$o['apply']) {
        return $fail('--auto / --approve yalnızca --apply ile çalışır');
    }

    $files = migrationFiles($o['dir']);
    if ($files === []) {
        return $fail('migration bulunamadı: ' . $o['dir']);
    }

    // Yedeksiz hiçbir şey yazılmaz (kayıt tablosu dahil).
    if ($o['auto'] || $o['approve'] !== null) {
        $why = migrationCheckBackup($o['backup'], (int) $o['backupMaxAge']);
        if ($why !== null) {
            return $fail($why);
        }
        $log('yedek doğrulandı: ' . basename((string) $o['backup']));
    }

    if ($o['auto']) {
        if (!migrationTableExists($conn, $table)
            || (int) $conn->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 0) {
            return $fail("`$table` yok ya da boş — önce başlangıç kaydı (--bootstrap)");
        }
    }

    migrationEnsureTables($conn, $table, (bool) $o['temporary']);
    [$applied, $deferred] = migrationLoadState($conn, $table);

    if ($o['approve'] !== null) {
        $name = basename((string) $o['approve']);
        if (!isset($files[$name])) {
            return $fail("--approve: dosya migrations/ içinde yok: $name");
        }
        if (isset($applied[$name])) {
            $log(sprintf('  [=] %-40s zaten uygulanmış — yapılacak bir şey yok', $name));
            return $result;
        }
        if (migrationIsDestructive((string) file_get_contents($files[$name])) && !$o['allowDestructive']) {
            return $fail("$name veri siliyor — ayrıca --allow-destructive gerekli");
        }
        $files = [$name => $files[$name]];
    }

    foreach ($files as $name => $file) {
        $sql  = (string) file_get_contents($file);
        $hash = migrationChecksum($sql); // N-25: satır sonundan bağımsız

        if (isset($applied[$name])) {
            $note = $applied[$name]['checksum'] === $hash
                ? 'uygulanmış'
                : 'UYGULANMIŞ AMA DOSYA DEĞİŞMİŞ — içeriği kontrol edin';
            $log(sprintf('  [=] %-40s %s (%s)', $name, $note, $applied[$name]['applied_at']));
            continue;
        }

        $destructive = migrationIsDestructive($sql);
        $isDeferred  = isset($deferred[$name]) || ($o['auto'] && in_array($name, $o['deferredNames'], true));

        if ($isDeferred && $o['approve'] === null) {
            $result['skipped'][$name] = 'ertelendi';
            $log(sprintf('  [~] %-40s ATLANDI — ertelendi, yalnızca onayla (--approve)%s', $name, $destructive ? '  ⚠ VERİ SİLER' : ''));
            continue;
        }

        $result['pending'][] = $name;

        if (!$o['apply']) {
            $log(sprintf('  [ ] %-40s bekliyor%s', $name, $destructive ? '  ⚠ VERİ SİLER' : ''));
            continue;
        }

        if ($destructive && !$o['allowDestructive']) {
            $result['skipped'][$name] = 'yıkıcı';
            $log(sprintf('  [!] %-40s ATLANDI — veri siliyor, yalnızca onayla (--approve … --allow-destructive)', $name));
            continue;
        }

        if (isset($o['prechecks'][$name])) {
            $why = ($o['prechecks'][$name])($conn);
            if ($why !== null) {
                $result['skipped'][$name] = $why;
                $log(sprintf('  [!] %-40s ATLANDI — ön kontrol: %s', $name, $why));
                continue;
            }
        }

        $log(sprintf('  [>] %-40s uygulanıyor…', $name));

        // DDL örtük commit yapar; yine de transaction açıyoruz ki saf DML
        // migration'ları (002 gibi) atomik olsun.
        $inTransaction = false;
        try {
            $conn->beginTransaction();
            $inTransaction = true;
        } catch (Throwable $e) {
            $inTransaction = false;
        }

        try {
            $conn->exec($sql);

            if ($inTransaction && $conn->inTransaction()) {
                $conn->commit();
            } elseif ($inTransaction) {
                $log('      not: DDL örtük COMMIT yaptı, bu dosya geri alınamaz.');
            }

            $stmt = $conn->prepare("INSERT INTO `$table` (filename, checksum, applied_at) VALUES (?, ?, NOW())");
            $stmt->execute([$name, $hash]);
            $result['applied'][] = $name;
            $log('      tamam');
        } catch (Throwable $e) {
            if ($inTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $result['failed'] = $name;
            $result['error']  = $e->getMessage();
            $log('      HATA: ' . $e->getMessage());
            $log("      Sıra bozulmasın diye duruluyor — sonraki migration'lar çalıştırılmadı.");
            break;
        }
    }

    return $result;
}
