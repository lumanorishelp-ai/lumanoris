<?php
/**
 * N-49 — `user_plan_selection` ↔ `plans` collation çakışması öz-testi.
 *
 *   php api/database/plan_collation_selftest.php
 *
 * Canlı (2026-10-15→): php-error.log'da sürekli
 *   [plans] getUserPlan başarısız: SQLSTATE[HY000]: 1267 Illegal mix of
 *   collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT)
 * `plans.name_tr` general_ci, canlıdaki `user_plan_selection.plan_name`
 * unicode_ci. getUserPlan hatayı yakalayıp ücretsiz plana düşüyor.
 *
 * Canlı durum, YALNIZCA bu bağlantıda yaşayan bir TEMPORARY
 * `user_plan_selection` (unicode_ci) ile üretilir: geçici tablo aynı adlı
 * gerçek tabloyu bu oturumda gölgeler, süreç bitince kaybolur (bir kez
 * oluşturulur, yeniden kullanılır). Gerçek
 * tablolara yazılmaz; DROP/DELETE çalışmaz.
 *
 * A) unicode_ci seçim satırı + general_ci plans → getUserPlan satın alınan
 *    paketi döndürüyor (ücretsiz plana DÜŞMÜYOR).
 * B) phpmyadmin/018 dönüşümü: sütun general_ci olur, satır korunur.
 * C) migrations/018 yıkıcı değil, koşullu (tekrar çalıştırılabilir).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/plans.php';
require_once __DIR__ . '/migration_lib.php';

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

$db   = Database::getInstance();
$conn = $db->getConnection();

if (!plansTableReady($db)) {
    echo "  (plans kataloğu hazır değil — test atlandı)\n";
    finish();
}
$paid = $conn->query("SELECT name_tr FROM plans WHERE monthly_price > 0 ORDER BY sort_order LIMIT 1")->fetchColumn();
$plansColl = $conn->query("SHOW FULL COLUMNS FROM plans LIKE 'name_tr'")->fetch(PDO::FETCH_ASSOC)['Collation'] ?? '';
if (!$paid || $plansColl !== 'utf8mb4_general_ci') {
    echo "  (yerel plans.name_tr general_ci değil ya da ücretli plan yok — test atlandı: $plansColl)\n";
    finish();
}

/** Canlıdaki gibi unicode_ci seçim tablosu (geçici, yalnızca bu bağlantı). */
function liveLikeSelection(PDO $conn, int $userId, string $plan): void {
    $conn->exec(
        "CREATE TEMPORARY TABLE IF NOT EXISTS user_plan_selection (
            user_id int NOT NULL,
            plan_name varchar(30) NOT NULL,
            selected_at datetime NOT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $st = $conn->prepare('INSERT INTO user_plan_selection (user_id, plan_name, selected_at, expires_at) VALUES (?, ?, NOW(), NOW() + INTERVAL 30 DAY)');
    $st->execute([$userId, $plan]);
}

echo "\n=== A) Canlı durum: plan_name unicode_ci, plans.name_tr general_ci ===\n\n";
liveLikeSelection($conn, 990001, $paid);
$coll = $conn->query("SHOW FULL COLUMNS FROM user_plan_selection LIKE 'plan_name'")->fetch(PDO::FETCH_ASSOC)['Collation'] ?? '';
check('üretildi: plan_name unicode_ci (geçici tablo)', $coll === 'utf8mb4_unicode_ci', $coll);
$p = getUserPlan($db, 990001);
check("satın alınan paket ($paid) okunuyor, ücretsize düşmüyor", ($p['name_tr'] ?? '') === $paid && ($p['source'] ?? '') === 'plans-table',
    ($p['name_tr'] ?? '?') . ' / ' . ($p['source'] ?? '?'));

echo "\n=== B) phpmyadmin/018: sütun general_ci, veri korunur ===\n\n";
$pma = __DIR__ . '/phpmyadmin/018_user_plan_selection_collation.sql';
check('phpmyadmin/018 var', is_file($pma));
if (is_file($pma)) {
    liveLikeSelection($conn, 990002, $paid);
    $sql = (string) file_get_contents($pma);
    check('phpmyadmin/018 information_schema / PREPARE kullanmıyor', !preg_match('/^\s*[^-\s].*(information_schema|PREPARE)/im', $sql));
    // Yalnızca ALTER ifadeleri — SHOW/SELECT doğrulamaları atlanır.
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
        if (stripos($stmt, 'ALTER TABLE') === 0) {
            $conn->exec($stmt);
        }
    }
    $coll = $conn->query("SHOW FULL COLUMNS FROM user_plan_selection LIKE 'plan_name'")->fetch(PDO::FETCH_ASSOC)['Collation'] ?? '';
    check('dönüşümden sonra plan_name general_ci', $coll === 'utf8mb4_general_ci', $coll);
    $row = $conn->query('SELECT plan_name, expires_at IS NOT NULL AS e FROM user_plan_selection WHERE user_id = 990002')->fetch(PDO::FETCH_ASSOC);
    check('satır ve değer korundu', ($row['plan_name'] ?? '') === $paid && (int) ($row['e'] ?? 0) === 1);
    $ok = (int) $conn->query('SELECT COUNT(*) FROM user_plan_selection ups JOIN plans p ON p.name_tr = ups.plan_name WHERE ups.user_id = 990002')->fetchColumn();
    check('COLLATE olmadan da JOIN çalışıyor', $ok === 1);
}

echo "\n=== C) migrations/018 ===\n\n";
$mig = __DIR__ . '/migrations/018_user_plan_selection_collation.sql';
check('migrations/018 var', is_file($mig));
if (is_file($mig)) {
    $m = (string) file_get_contents($mig);
    check('yıkıcı değil (DELETE/TRUNCATE/DROP yok)', !migrationIsDestructive($m));
    check('koşullu: yalnızca general_ci değilse dönüştürüyor', str_contains($m, "<> 'utf8mb4_general_ci'") && str_contains($m, 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'));
    check('MIGRATION_DEFERRED içinde değil (deploy --auto uygular)', !in_array('018_user_plan_selection_collation.sql', MIGRATION_DEFERRED, true));
}

finish();
