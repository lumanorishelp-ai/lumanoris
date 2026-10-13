<?php
/**
 * Migration checksum öz-testi (N-25) — salt okunur.
 *
 *   php api/database/migrate_selftest.php
 *
 * N-25: migrate.php checksum'ı dosyanın ham baytlarından alıyordu. Windows'ta
 * `core.autocrlf=true` ile alınmış bir kopyada (CRLF) uygulanmış 17 migration'ın
 * hepsi "UYGULANMIŞ AMA DOSYA DEĞİŞMİŞ" görünüyordu; içerik aynıydı.
 *
 * A) migrationChecksum() CRLF / LF / CR farkından bağımsız.
 * B) schema_migrations'taki HER kayıt, dosyanın hem LF hem CRLF halinde yeni
 *    checksum'la eşleşiyor — yani değişiklik mevcut kayıtları "değişmiş"
 *    göstermiyor (veritabanına yazılmaz).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../functions/env.php';
require_once __DIR__ . '/../functions/db.php';

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

$lib = __DIR__ . '/migration_lib.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!function_exists('migrationChecksum')) {
    check('migrationChecksum tanımlı (migration_lib.php)', false, 'fonksiyon yok');
    finish();
}

echo "\n=== A) Satır sonu bağımsızlığı ===\n\n";
$lf = "CREATE TABLE x (\n  id int\n);\n";
check('LF ile CRLF aynı checksum', migrationChecksum($lf) === migrationChecksum(str_replace("\n", "\r\n", $lf)));
check('LF ile tek CR aynı checksum', migrationChecksum($lf) === migrationChecksum(str_replace("\n", "\r", $lf)));
check('LF metnin checksum\'ı eski (ham sha256) ile aynı', migrationChecksum($lf) === hash('sha256', $lf));
check('içerik farkı hâlâ yakalanıyor', migrationChecksum($lf) !== migrationChecksum(str_replace('int', 'bigint', $lf)));

echo "\n=== B) Uygulanmış kayıtlar (salt okunur) ===\n\n";
$conn = Database::getInstance()->getConnection();
$rows = $conn->query('SELECT filename, checksum FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) {
    echo "  (schema_migrations boş — B atlandı)\n";
}
foreach ($rows as $row) {
    $file = __DIR__ . '/migrations/' . $row['filename'];
    if (!is_file($file)) {
        echo "  (dosya yok: {$row['filename']} — atlandı)\n";
        continue;
    }
    $raw    = (string) file_get_contents($file);
    $asLf   = str_replace(["\r\n", "\r"], "\n", $raw);
    $asCrlf = str_replace("\n", "\r\n", $asLf);
    check("{$row['filename']}: LF ve CRLF hali kayıtlı checksum'la eşleşiyor",
        migrationChecksum($asLf) === $row['checksum'] && migrationChecksum($asCrlf) === $row['checksum']);
}

// ─────────────────────────────────────────────────────────────────────────
// C–I: deploy modu (--auto, --bootstrap, --approve). Kayıt tabloları ve
// sahte migration'ların tabloları TEMPORARY: yalnızca bu bağlantıda yaşar,
// süreç bitince kaybolur. Gerçek tablolara yazılmaz; DROP/DELETE çalışmaz.
// ─────────────────────────────────────────────────────────────────────────

$tmpRoot = sys_get_temp_dir() . '/lumanoris_migrate_selftest_' . getmypid();
$made    = [];
function fixture(string $name, array $files): string {
    global $tmpRoot, $made;
    $dir = "$tmpRoot/$name";
    @mkdir($dir, 0700, true);
    foreach ($files as $file => $sql) {
        file_put_contents("$dir/$file", $sql);
        $made[] = "$dir/$file";
    }
    $made[] = $dir;
    return $dir;
}
function backupFile(string $name, string $body, ?int $mtime = null, bool $gz = false): string {
    global $tmpRoot, $made;
    @mkdir($tmpRoot, 0700, true);
    $path = "$tmpRoot/$name" . ($gz ? '.sql.gz' : '.sql');
    file_put_contents($path, $gz ? gzencode($body) : $body);
    if ($mtime !== null) {
        touch($path, $mtime);
    }
    $made[] = $path;
    return $path;
}
function cleanup(): void {
    global $tmpRoot, $made;
    foreach (array_reverse($made) as $p) {
        is_dir($p) ? @rmdir($p) : @unlink($p);
    }
    @rmdir($tmpRoot);
}
register_shutdown_function('cleanup');

$lines = [];
$quiet = static function (string $l) use (&$lines): void { $lines[] = $l; };
$count = static fn(string $t): int => (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
$dump  = "-- MySQL dump\nCREATE TABLE x (id int);\n-- Dump completed on 2026-10-13 12:00:00\n";
$good  = backupFile('good', $dump);

echo "\n=== C) Yıkıcılık ve yedek kanıtı (saf) ===\n\n";
check('DELETE satır başında → yıkıcı', migrationIsDestructive("SELECT 1;\nDELETE FROM t;"));
check('UPDATE yıkıcı sayılmaz (bilinen sınır)', !migrationIsDestructive('UPDATE t SET a = 1;'));
check('yorumdaki DROP yıkıcı sayılmaz', !migrationIsDestructive("-- DROP TABLE t;\nSELECT 1;"));
check('yedek verilmedi → ret', migrationCheckBackup(null) !== null);
check('yedek dosyası yok → ret', migrationCheckBackup("$tmpRoot/yok.sql") !== null);
check('boş yedek → ret', migrationCheckBackup(backupFile('empty', '')) !== null);
check('kapanış satırı olmayan (yarıda kesilmiş) yedek → ret',
    migrationCheckBackup(backupFile('cut', "-- MySQL dump\nINSERT INTO x VALUES (1);\n")) !== null);
check('1 saatten eski yedek → ret', migrationCheckBackup(backupFile('old', $dump, time() - 7200)) !== null);
check('geçerli .sql yedek → kabul', migrationCheckBackup($good) === null);
check('geçerli .sql.gz yedek → kabul', migrationCheckBackup(backupFile('goodgz', $dump, null, true)) === null);
check('yarıda kesilmiş .gz → ret',
    migrationCheckBackup(backupFile('cutgz', "-- MySQL dump\nINSERT INTO x VALUES (1);\n", null, true)) !== null);

echo "\n=== D) Gerçek migrations/: canlı başlangıç kaydından sonra --auto ne uygular ===\n\n";
foreach (array_merge(MIGRATION_BOOTSTRAP_APPLIED, MIGRATION_DEFERRED) as $n) {
    check("$n migrations/ içinde var", is_file(__DIR__ . "/migrations/$n"));
}
check('002 ve 002b yıkıcı', migrationIsDestructive((string) file_get_contents(__DIR__ . '/migrations/002_clean_orphan_rows.sql'))
    && migrationIsDestructive((string) file_get_contents(__DIR__ . '/migrations/002b_clean_orphan_rows_2.sql')));
migrationBootstrap($conn, __DIR__ . '/migrations', $quiet, 'mst_real', true);
$r = migrationRun($conn, ['dir' => __DIR__ . '/migrations', 'table' => 'mst_real', 'temporary' => true], $quiet);
$expect = ['007_plan_limits.sql', '008_missing_unique_keys.sql', '009_user_list_color.sql', '011_user_plan_expiry.sql',
           '012_plan_privacy_right_and_unlimited.sql', '013_plan_catalog_2026_10.sql', '015_house_bot_free.sql',
           '016_marketplace_applications.sql', '017_admin_audit_log.sql'];
check('bekleyenler 007…017 (004/010 ve 001–006 hariç)', array_values(array_intersect($r['pending'], $expect)) === $expect
    && array_intersect($r['pending'], array_merge(MIGRATION_BOOTSTRAP_APPLIED, MIGRATION_DEFERRED)) === [],
    implode(',', $r['pending']));
check('pending/ dizini okunmuyor (014 yok)', !in_array('014_unlist_free_public_drafts.sql', $r['pending'], true)
    && !isset($r['skipped']['014_unlist_free_public_drafts.sql']));
check('bekleyenlerin hiçbiri yıkıcı değil', array_filter($r['pending'],
    static fn($n) => migrationIsDestructive((string) file_get_contents(__DIR__ . "/migrations/$n"))) === []);

echo "\n=== E) Başlangıç kaydı yalnızca bir kez ===\n\n";
$dirE = fixture('e', [
    '001_x.sql' => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_e1 (id INT);\n",
    '002_y.sql' => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_e2 (id INT);\n",
    '003_z.sql' => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_e3 (id INT);\n",
]);
$threw = false;
try {
    migrationBootstrap($conn, $dirE, $quiet, 'mst_e_bad', true, ['001_x.sql'], ['009_yok.sql']);
} catch (RuntimeException $e) {
    $threw = true;
}
check('listedeki dosya eksikse hiçbir şey yazılmaz', $threw && !migrationTableExists($conn, 'mst_e_bad'));
$n1 = migrationBootstrap($conn, $dirE, $quiet, 'mst_e', true, ['002_y.sql'], ['001_x.sql']);
[$ap, $df] = migrationLoadState($conn, 'mst_e');
check('ilk çalıştırma: 1 uygulanmış + 1 ertelenmiş yazıldı', $n1 === 2 && array_keys($ap) === ['002_y.sql'] && array_keys($df) === ['001_x.sql']);
check('kayıtlı checksum dosyanınkiyle aynı',
    $ap['002_y.sql']['checksum'] === migrationChecksum((string) file_get_contents("$dirE/002_y.sql"))
    && $df['001_x.sql']['checksum'] === migrationChecksum((string) file_get_contents("$dirE/001_x.sql")));
$n2 = migrationBootstrap($conn, $dirE, $quiet, 'mst_e', true, ['002_y.sql'], ['001_x.sql']);
check('ikinci çalıştırma hiçbir şey yazmıyor', $n2 === 0 && $count('mst_e') === 1 && $count('mst_e_deferred') === 1);

echo "\n=== F) --auto: ertelenmiş / yıkıcı / ön kontrol atlanır, gerisi uygulanır ===\n\n";
$conn->exec('CREATE TEMPORARY TABLE mst_f_dup (u INT, c INT)');
$conn->exec('INSERT INTO mst_f_dup VALUES (1, 1), (1, 1), (2, 1)');
$conn->exec('CREATE TEMPORARY TABLE mst_f_clean (u INT, c INT)');
$conn->exec('INSERT INTO mst_f_clean VALUES (1, 1), (2, 1)');
check('ön kontrol: yinelenen satırı buluyor', migrationDuplicatePrecheck($conn, [['mst_f_dup', ['u', 'c']]]) !== null);
check('ön kontrol: temiz tabloda geçiyor', migrationDuplicatePrecheck($conn, [['mst_f_clean', ['u', 'c']]]) === null);
check('ön kontrol: tablo yoksa atlatıyor', migrationDuplicatePrecheck($conn, [['mst_f_yok', ['u']]]) !== null);

$dirF = fixture('f', [
    '001_kayitli_ertelendi.sql' => "CREATE TEMPORARY TABLE mst_f_def1 (id INT);\n",
    '002_liste_ertelendi.sql'   => "CREATE TEMPORARY TABLE mst_f_def2 (id INT);\n",
    '003_ilk.sql'               => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_f_a (id INT);\nINSERT INTO mst_f_a VALUES (1);\n",
    '004_yikici.sql'            => "-- veri siler\nDELETE FROM mst_f_a;\n",
    '005_kopya_var.sql'         => "CREATE TEMPORARY TABLE mst_f_pre (id INT);\n",
    '006_son.sql'               => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_f_b (id INT);\n",
    '007_onceden.sql'           => "CREATE TEMPORARY TABLE mst_f_old (id INT);\n",
]);
$optsF = [
    'dir' => $dirF, 'table' => 'mst_f', 'temporary' => true, 'apply' => true, 'auto' => true,
    'backup' => $good, 'deferredNames' => ['002_liste_ertelendi.sql'],
    'prechecks' => ['005_kopya_var.sql' => static fn(PDO $c) => migrationDuplicatePrecheck($c, [['mst_f_dup', ['u', 'c']]])],
];
$r = migrationRun($conn, $optsF, $quiet);
check('--auto başlangıç kaydı olmadan çalışmıyor', $r['error'] !== null && $r['applied'] === [] && !migrationTableExists($conn, 'mst_f'));

migrationBootstrap($conn, $dirF, $quiet, 'mst_f', true, ['007_onceden.sql'], ['001_kayitli_ertelendi.sql']);
$r = migrationRun($conn, $optsF, $quiet);
check('uygulananlar yalnızca 003 ve 006', $r['applied'] === ['003_ilk.sql', '006_son.sql'], implode(',', $r['applied']));
check('kayıtlı ertelenmiş atlandı', ($r['skipped']['001_kayitli_ertelendi.sql'] ?? '') === 'ertelendi' && !migrationTableExists($conn, 'mst_f_def1'));
check('listedeki ertelenmiş (kayıtsız) atlandı', ($r['skipped']['002_liste_ertelendi.sql'] ?? '') === 'ertelendi' && !migrationTableExists($conn, 'mst_f_def2'));
check('yıkıcı atlandı, veri yerinde', ($r['skipped']['004_yikici.sql'] ?? '') === 'yıkıcı' && $count('mst_f_a') === 1);
check('ön kontrolü geçemeyen atlandı, sonrakiler devam etti', isset($r['skipped']['005_kopya_var.sql'])
    && !migrationTableExists($conn, 'mst_f_pre') && migrationTableExists($conn, 'mst_f_b'));
check('başlangıçta uygulanmış sayılan tekrar çalışmadı', !migrationTableExists($conn, 'mst_f_old'));
check('hata yok', $r['failed'] === null && $r['error'] === null);

$before = $count('mst_f');
$r = migrationRun($conn, $optsF, $quiet);
check('ikinci --auto hiçbir şey uygulamıyor', $r['applied'] === [] && $r['failed'] === null && $count('mst_f') === $before);

echo "\n=== G) Yedek yoksa durur — hiçbir şey yazılmaz ===\n\n";
foreach ([
    'yedek verilmedi'  => null,
    'yedek dosyası yok' => "$tmpRoot/yok.sql",
    'yedek eksik'       => "$tmpRoot/cut.sql",
] as $label => $bk) {
    $dirG = fixture('g' . md5($label), ['001_a.sql' => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_g_a (id INT);\n"]);
    $conn->exec('CREATE TEMPORARY TABLE IF NOT EXISTS mst_g (filename VARCHAR(191) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
    $conn->exec("INSERT IGNORE INTO mst_g VALUES ('000_x.sql', '', NOW())");
    $r = migrationRun($conn, ['dir' => $dirG, 'table' => 'mst_g', 'temporary' => true, 'apply' => true, 'auto' => true, 'backup' => $bk], $quiet);
    check("$label → durdu, uygulanan yok", $r['error'] !== null && $r['applied'] === [] && !migrationTableExists($conn, 'mst_g_a'));
}
check('--approve da yedeksiz çalışmıyor', migrationRun($conn, ['dir' => $dirF, 'table' => 'mst_f', 'temporary' => true,
    'apply' => true, 'approve' => '001_kayitli_ertelendi.sql', 'backup' => null], $quiet)['error'] !== null
    && !migrationTableExists($conn, 'mst_f_def1'));

// CLI: gerçek veritabanına karşı, ama yedek kontrolü her yazmadan önce → yazılmaz.
$php = escapeshellarg(PHP_BINARY);
$cli = escapeshellarg(__DIR__ . '/migrate.php');
exec("$php $cli --apply --auto 2>&1", $out1, $code1);
check('CLI --apply --auto yedeksiz → çıkış ≠ 0', $code1 !== 0 && strpos(implode("\n", $out1), 'DURDU') !== false);
exec("$php $cli --bootstrap 2>&1", $out2, $code2);
check('CLI --bootstrap yedeksiz → çıkış ≠ 0', $code2 !== 0 && strpos(implode("\n", $out2), 'DURDU') !== false);
exec("$php $cli --apply --auto --allow-destructive --backup=" . escapeshellarg($good) . ' 2>&1', $out3, $code3);
check('CLI --auto --allow-destructive reddediliyor', $code3 !== 0);

echo "\n=== H) Hata sonrası durur ===\n\n";
foreach ([
    'ilk ifade hatalı'    => "INSERT INTO mst_h_yok_zz VALUES (1);\n",
    'sonraki ifade hatalı' => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_h_x (id INT);\nINSERT INTO mst_h_yok_zz VALUES (1);\n",
] as $label => $badSql) {
    $k    = substr(md5($label), 0, 6);
    $dirH = fixture("h$k", [
        '001_ok.sql'    => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_h_{$k}_1 (id INT);\n",
        '002_bozuk.sql' => $badSql,
        '003_ok.sql'    => "CREATE TEMPORARY TABLE IF NOT EXISTS mst_h_{$k}_3 (id INT);\n",
    ]);
    migrationBootstrap($conn, $dirH, $quiet, "mst_h_$k", true, [], []);
    $conn->exec("INSERT INTO `mst_h_$k` VALUES ('000_x.sql', '', NOW())");
    $r = migrationRun($conn, ['dir' => $dirH, 'table' => "mst_h_$k", 'temporary' => true, 'apply' => true, 'auto' => true, 'backup' => $good], $quiet);
    [$ap] = migrationLoadState($conn, "mst_h_$k");
    check("$label: 002'de durdu, 003 çalışmadı", $r['failed'] === '002_bozuk.sql'
        && $r['applied'] === ['001_ok.sql'] && !migrationTableExists($conn, "mst_h_{$k}_3"), (string) $r['failed']);
    check("$label: 002 ve 003 kayda girmedi", !isset($ap['002_bozuk.sql']) && !isset($ap['003_ok.sql']));
}

echo "\n=== I) --approve: yalnızca tek dosya, yıkıcıya ayrıca izin ===\n\n";
$r = migrationRun($conn, ['dir' => $dirF, 'table' => 'mst_f', 'temporary' => true, 'apply' => true,
    'approve' => '004_yikici.sql', 'backup' => $good], $quiet);
check('yıkıcı dosya --allow-destructive olmadan reddedildi', $r['error'] !== null && $count('mst_f_a') === 1);
$r = migrationRun($conn, ['dir' => $dirF, 'table' => 'mst_f', 'temporary' => true, 'apply' => true,
    'approve' => '001_kayitli_ertelendi.sql', 'backup' => $good], $quiet);
check('onaylanan ertelenmiş dosya uygulandı, başka dosya çalışmadı', $r['applied'] === ['001_kayitli_ertelendi.sql']
    && migrationTableExists($conn, 'mst_f_def1') && !migrationTableExists($conn, 'mst_f_def2'));

finish();
