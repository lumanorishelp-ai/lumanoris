<?php
/**
 * Paket ödemeleri zamanlanmış işi — Faz 7a-2. YALNIZCA CLI.
 *
 *   php api/cron/plan_payments.php
 *
 * Önerilen sıklık: 5 dakikada bir (kurulum satırı README → Plan purchases).
 *
 *   1) Hoppa mutabakatı (hostedPlanReconcile): dönüşü gelmemiş paket
 *      siparişlerini ProcessQuery ile sorar; ödenmişse paketi tanımlar,
 *      reddedilmişse kapatır, 24 saati geçmiş ödenmemiş siparişi
 *      `hoppa_expired` yapar. PAYMENT_PROVIDER=none iken atlanır.
 *   2) Yenileme hatırlatması (planRenewalReminders, GK-27): bitişe 3 gün
 *      kala uygulama içi bildirim; aynı dönem için bir kez.
 *
 * Eşzamanlı iki çalışma (uzun süren bir tur + bir sonraki cron) birbirini
 * beklemez: kilit alınamazsa tur atlanır. Çıkış kodu: 0 başarı / atlandı,
 * 1 hata.
 *
 * Dizin üç denylist'te de web'e kapalı (`cron`); ayrıca aşağıdaki CLI
 * kontrolü.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/hosted_plan_payments.php';

$started = date('c');
try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $lock = $conn->prepare("SELECT GET_LOCK('cron_plan_payments', 0) AS locked");
    $lock->execute();
    if ((int) ($lock->fetch()['locked'] ?? 0) !== 1) {
        echo "[$started] plan_payments: başka bir tur sürüyor, atlandı\n";
        exit(0);
    }

    $gateway = PaymentGatewayFactory::make();
    if ($gateway === null) {
        $reconcile = 'atlandı (PAYMENT_PROVIDER=none ya da kimlik bilgisi yok)';
    } else {
        $s = hostedPlanReconcile($db, $gateway);
        $reconcile = sprintf(
            'sorgulanan=%d ödendi=%d reddedildi=%d bekliyor=%d süresi_doldu=%d belirsiz=%d%s',
            $s['checked'], $s['paid'], $s['failed'], $s['pending'], $s['expired'], $s['unknown'],
            $gateway->isTest() ? ' (TEST ortamı)' : ''
        );
    }

    $r = planRenewalReminders($db);

    echo "[$started] plan_payments: mutabakat: $reconcile · hatırlatma: gönderilen={$r['sent']}\n";
    $conn->prepare("SELECT RELEASE_LOCK('cron_plan_payments')")->execute();
    exit(0);
} catch (Throwable $e) {
    error_log('[cron plan_payments] ' . $e->getMessage());
    fwrite(STDERR, "[$started] plan_payments: HATA: " . $e->getMessage() . "\n");
    exit(1);
}
