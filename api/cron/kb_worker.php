<?php
/**
 * Bilgi bankası işçisi — kuyruktaki kaynakları parçalar ve embedding üretir. YALNIZCA CLI.
 *
 *   php api/cron/kb_worker.php
 *
 * Önerilen sıklık: her dakika. Hostingte kalıcı arka plan süreci yok; işçi
 * cron ile tetiklenir, en fazla KB_WORKER_SECONDS (varsayılan 50) sn çalışır
 * ve kuyruk boşalınca çıkar. Böylece bir sonraki dakikanın turuyla çakışmaz.
 *
 *   1) `isleniyor`da KB_LOCK_TIMEOUT_MIN dakikadan uzun kalan (yarım kalmış)
 *      işler kuyruğa geri alınır.
 *   2) Sıradaki iş atomik kilitlenir, işlenir; süre bitene ya da kuyruk
 *      boşalana kadar tekrarlanır.
 *
 * Eşzamanlı iki tur birbirini beklemez: kilit alınamazsa tur atlanır.
 * Dizin üç denylist'te de web'e kapalı (`cron`); ayrıca CLI kontrolü.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/kb.php';

$started  = date('c');
$deadline = microtime(true) + max(10, (int) (env_get('KB_WORKER_SECONDS', '50')));
$workerId = substr(gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(3)), 0, 64);

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $lock = $conn->prepare("SELECT GET_LOCK('cron_kb_worker', 0) AS locked");
    $lock->execute();
    if ((int) ($lock->fetch()['locked'] ?? 0) !== 1) {
        echo "[$started] kb_worker: başka bir tur sürüyor, atlandı\n";
        exit(0);
    }

    $reclaimed = kbReclaimStale($db);
    $counts = ['hazir' => 0, 'beklemede' => 0, 'basarisiz' => 0, 'silindi' => 0];
    while (microtime(true) < $deadline) {
        $src = kbClaimNext($db, $workerId);
        if ($src === null) {
            break;
        }
        $result = kbProcessSource($db, $src);
        $counts[$result] = ($counts[$result] ?? 0) + 1;
    }

    if ($reclaimed > 0 || array_sum($counts) > 0) {
        echo sprintf(
            "[%s] kb_worker: geri_alınan=%d hazır=%d yeniden_denenecek=%d başarısız=%d silindi=%d\n",
            $started, $reclaimed, $counts['hazir'], $counts['beklemede'], $counts['basarisiz'], $counts['silindi']
        );
    }
    $conn->prepare("SELECT RELEASE_LOCK('cron_kb_worker')")->execute();
    exit(0);
} catch (Throwable $e) {
    error_log('[kb_worker] ' . $e->getMessage());
    echo "[$started] kb_worker: HATA " . $e->getMessage() . "\n";
    exit(1);
}
