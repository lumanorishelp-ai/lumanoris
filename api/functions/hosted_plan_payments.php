<?php
/**
 * Hoppa ile satılan üyelik paketleri — Faz 7a-2 (canlı öncesi tamamlayıcılar).
 *
 *   hostedPlanReconcile()      — mutabakat: dönüşü gelmemiş siparişleri ProcessQuery ile sorar
 *   planRenewalReminders()     — GK-27: bitişten 3 gün önce uygulama içi hatırlatma
 *   hostedPlanRefund()         — admin tam iadesi (OrderReturn) + paket iptali + admin_audit_log
 *   revokeRefundedPlan()       — iade edilen paketin geri alınması
 *
 * Kesinleştirmenin kendisi `WalletController::finalizeHostedPlanPayment()`'ta;
 * buradaki her yol onu çağırır ki "aynı sipariş iki kez paket tanımlamaz"
 * kuralı tek bir yerde kalsın.
 *
 * Çalıştıran: api/cron/plan_payments.php (CLI) ve admin iade ucu
 * (SellerController::refund → Hoppa satırı ise buraya yönlenir).
 */

require_once __DIR__ . '/plans.php';

/** Bildirim türü — tekrar gönderim kontrolü bu değere bakar. */
const PLAN_RENEWAL_NOTIFICATION_TYPE = 'plan_renewal_reminder';

/**
 * Mutabakat.
 *
 * 1) `hoppa_pending` ve `$minAgeMinutes`'tan eski siparişler sorulur
 *    (dönüş normalde birkaç dakikada gelir; daha yeni olanlar kullanıcı hâlâ
 *    ödeme sayfasındayken gereksiz sorgu olurdu).
 *    - ödendi → paket tanımlanır (finalize, tekrar çalıştırmaya dayanıklı)
 *    - reddedildi → `hoppa_failed`
 *    - hâlâ ödenmemiş ve `$expireHours`'tan eski → `hoppa_expired`
 * 2) `hoppa_expired` satırlar, oluşturulduktan sonraki 7 gün boyunca en
 *    fazla 6 saatte bir yeniden sorulur: Hoppa ödeme sayfasının ne kadar
 *    açık kaldığı belgelenmemiş (hoppa-sorular.md #5); geç gelen bir
 *    ödeme kaçmasın. 7 günden eski kayıt artık sorulmaz (AUDIT, Faz 7a-2).
 *
 * `unknown` (tutar uyuşmazlığı, paket yazılamadı, sorgu hatası) satıra
 * dokunmaz; satır `hoppa_pending` kalır ve her çalışmada yeniden denenir.
 *
 * @return array<string,int> sayaçlar
 */
function hostedPlanReconcile(Database $db, PaymentGatewayInterface $gateway, int $minAgeMinutes = 15, int $expireHours = 24, int $limit = 200): array
{
    $stats = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'pending' => 0, 'expired' => 0, 'unknown' => 0];
    $limit = max(1, min(1000, $limit));

    $pending = $db->selectMulti(
        "order_id, (created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)) AS stale
           FROM param_marketplace_payments
          WHERE status = ?
            AND created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
          ORDER BY id ASC
          LIMIT $limit",
        [$expireHours, WalletController::HOSTED_PENDING, $minAgeMinutes]
    );

    foreach ($pending as $row) {
        $stats['checked']++;
        $r = WalletController::finalizeHostedPlanPayment($db, $gateway, (string) $row['order_id']);
        $state = $r['state'];

        if ($state === 'pending' && (int) $row['stale'] === 1) {
            $changed = $db->execute(
                'UPDATE param_marketplace_payments SET status = ? WHERE order_id = ? AND status = ?',
                [WalletController::HOSTED_EXPIRED, $row['order_id'], WalletController::HOSTED_PENDING]
            );
            $state = $changed === 1 ? 'expired' : 'pending';
        }
        if ($state === 'unknown') {
            error_log('[hoppaReconcile] belirsiz sonuç order=' . $row['order_id'] . ' neden=' . ($r['message'] ?: '-'));
        }
        $stats[$state] = ($stats[$state] ?? 0) + 1;
    }

    $expired = $db->selectMulti(
        "order_id FROM param_marketplace_payments
          WHERE status = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND updated_at <= DATE_SUB(NOW(), INTERVAL 6 HOUR)
          ORDER BY id ASC
          LIMIT $limit",
        [WalletController::HOSTED_EXPIRED]
    );

    foreach ($expired as $row) {
        $stats['checked']++;
        $r = WalletController::finalizeHostedPlanPayment($db, $gateway, (string) $row['order_id']);
        if ($r['state'] === 'paid') {
            $stats['paid']++;
            error_log('[hoppaReconcile] süresi dolmuş sayılan sipariş ÖDENMİŞ çıktı, paket tanımlandı order=' . $row['order_id']);
            continue;
        }
        // Bir sonraki yeniden sorgu 6 saat sonra.
        $db->execute(
            'UPDATE param_marketplace_payments SET updated_at = NOW() WHERE order_id = ? AND status = ?',
            [$row['order_id'], WalletController::HOSTED_EXPIRED]
        );
        $stats['expired']++;
    }

    return $stats;
}

/**
 * GK-27 — paket bitişinden `$daysBefore` gün önce uygulama içi hatırlatma.
 *
 * "Aynı dönem için bir kez": bir dönemin hatırlatma penceresi
 * [bitiş − $daysBefore gün, bitiş). Kullanıcıya bu pencere içinde bu türde
 * bir bildirim zaten yazıldıysa ikincisi yazılmaz. Yenileme bitişi 30 gün
 * ileri taşıdığı için yeni dönemin penceresi eski bildirimden sonra açılır,
 * yani yenileyen kullanıcı bir sonraki dönemde yeniden hatırlatma alır.
 * Şema değişikliği gerektirmiyor. Eşzamanlı iki çalışmaya karşı cron
 * betiği kilit tutuyor.
 *
 * Süresiz (ücretsiz, `expires_at` NULL) planlar hatırlatma almaz.
 *
 * @return array{sent:int}
 */
function planRenewalReminders(Database $db, int $daysBefore = 3, int $limit = 500): array
{
    if (!planSelectionHasExpiry($db)) {
        return ['sent' => 0];
    }
    $daysBefore = max(1, $daysBefore);
    $limit      = max(1, min(5000, $limit));

    $rows = $db->selectMulti(
        "ups.user_id, ups.plan_name, ups.expires_at
           FROM user_plan_selection ups
          WHERE ups.expires_at IS NOT NULL
            AND ups.expires_at > NOW()
            AND ups.expires_at <= DATE_ADD(NOW(), INTERVAL $daysBefore DAY)
            AND NOT EXISTS (
                SELECT 1 FROM notifications n
                 WHERE n.user_id = ups.user_id
                   AND n.type = ?
                   AND n.created_at >= DATE_SUB(ups.expires_at, INTERVAL $daysBefore DAY)
            )
          ORDER BY ups.expires_at ASC
          LIMIT $limit",
        [PLAN_RENEWAL_NOTIFICATION_TYPE]
    );

    $sent = 0;
    foreach ($rows as $r) {
        $when = date('d.m.Y H:i', strtotime((string) $r['expires_at']));
        $plan = (string) $r['plan_name'];
        $db->execute(
            'INSERT INTO notifications (user_id, type, title_tr, title_en, message_tr, message_en, is_read)
             VALUES (?, ?, ?, ?, ?, ?, 0)',
            [
                (int) $r['user_id'],
                PLAN_RENEWAL_NOTIFICATION_TYPE,
                'Paketinizin süresi doluyor',
                'Your plan is about to expire',
                "\"$plan\" paketinizin süresi $when tarihinde doluyor. Paketler otomatik yenilenmez; "
                    . 'kesintisiz kullanmak için Paketler sayfasından yenileyebilirsiniz.',
                "Your \"$plan\" plan expires on $when. Plans do not renew automatically; "
                    . 'renew it from the Plans page to keep using it without interruption.',
            ]
        );
        $sent++;
    }

    return ['sent' => $sent];
}

/**
 * Bu ödeme satırı Hoppa ile satılmış bir paket mi? (admin iade ucunun
 * iyzico `processRefund()` ile bu yol arasında seçim yapması için)
 */
function isHostedPlanPayment(?array $payment): bool
{
    if (!$payment) {
        return false;
    }
    $meta = json_decode((string) ($payment['param_response_json'] ?? ''), true);
    $item = json_decode((string) ($payment['items_json'] ?? ''), true)[0] ?? null;
    return ($meta['provider'] ?? '') === 'hoppa' && is_array($item) && !empty($item['plan_name']);
}

/**
 * Admin tam iadesi — Hoppa OrderReturn.
 *
 * İyzico iade akışıyla (`processRefund`) aynı ilkeler:
 *   - KISMİ İADE YOK; tutar istekten okunmaz. İade edilen tutar, Hoppa'nın
 *     kayıtlı çekim tutarıdır (komisyon alıcıya yansıdıysa o da dahil).
 *   - Ödeme satırı bazında adlandırılmış kilit (iyzico yoluyla AYNI ad:
 *     `refund_payment_<id>`), kilitten sonra satır yeniden okunur.
 *   - Sağlayıcı çağrısı transaction DIŞINDA; durum geçişi ve erişimin geri
 *     alınması tek transaction'da.
 *   - Başarılı iadede paket geri alınır (iyzico'daki revokeRefundedAccess'in
 *     paket karşılığı: revokeRefundedPlan).
 * Fark: her deneme (reddedilen dahil) 017'deki `admin_audit_log`'a yazılır.
 * Tablo yoksa iade HİÇ başlatılmaz — izi tutulamayacak para hareketi yok.
 *
 * Hoppa'nın "başarılı" yanıtı tek başına yetmez: ProcessQuery ile iptal/iade
 * görülmeden satır `refunded` yapılmaz ve paket geri alınmaz.
 *
 * @return array{http:int, success:bool, message:string, code:string, data:array}
 */
function hostedPlanRefund(Database $db, PaymentGatewayInterface $gateway, array $payment, string $reason, ?int $adminId, string $adminName, ?string $ip): array
{
    $result = static fn (int $http, bool $ok, string $message, string $code = '', array $data = []): array => [
        'http' => $http, 'success' => $ok, 'message' => $message, 'code' => $code, 'data' => $data,
    ];

    $auditReady = (int) ($db->selectSingle(
        "COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_log'"
    )['c'] ?? 0) === 1;
    if (!$auditReady) {
        error_log('[hoppaRefund] admin_audit_log yok (migration 017 uygulanmamış) — iade başlatılmadı.');
        return $result(503, false, 'İade şu anda yapılamıyor: işlem kaydı tablosu hazır değil.', AppConfig::ERR_UNAVAILABLE);
    }

    $audit = static function (string $outcome, array $p, array $details) use ($db, $adminId, $adminName, $ip, $reason): void {
        $db->execute(
            'INSERT INTO admin_audit_log (admin_id, admin_name, action, target_user_id, target_type, target_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $adminId,
                mb_substr($adminName !== '' ? $adminName : 'bilinmiyor', 0, 64),
                'hoppa_plan_refund',
                (int) $p['user_id'],
                'param_marketplace_payment',
                (int) $p['id'],
                json_encode(['outcome' => $outcome, 'order_id' => $p['order_id'], 'reason' => $reason] + $details, JSON_UNESCAPED_UNICODE),
                $ip !== null ? mb_substr($ip, 0, 45) : null,
            ]
        );
    };

    $paymentId = (int) $payment['id'];
    $conn      = $db->getConnection();
    $lockName  = 'refund_payment_' . $paymentId;
    $lock      = $conn->prepare('SELECT GET_LOCK(?, 10) AS locked');
    $lock->execute([$lockName]);
    if ((int) ($lock->fetch()['locked'] ?? 0) !== 1) {
        return $result(409, false, 'Bu ödeme için başka bir iade işlemi sürüyor. Lütfen birkaç saniye sonra tekrar deneyin.', AppConfig::ERR_VALIDATION);
    }

    try {
        $payment = $db->selectSingle('* FROM param_marketplace_payments WHERE id = ?', [$paymentId]);
        $before  = (string) ($payment['status'] ?? '');

        if ($before === 'refunded') {
            $audit('reddedildi', $payment, ['detail' => 'zaten iade edilmiş', 'status_before' => $before]);
            return $result(409, false, 'Bu ödeme zaten iade edilmiş.', AppConfig::ERR_DUPLICATE);
        }
        if ($before !== 'paid') {
            $audit('reddedildi', $payment, ['detail' => 'tahsil edilmemiş ödeme', 'status_before' => $before]);
            return $result(422, false, 'Yalnızca tahsil edilmiş ödemeler iade edilebilir. Mevcut durum: ' . $before, AppConfig::ERR_VALIDATION);
        }

        $order = (string) $payment['order_id'];
        $q     = $gateway->queryPayment($order);
        $providerCall = null;

        if (in_array($q['state'], ['refunded', 'cancelled'], true)) {
            // Hoppa'da zaten iade/iptal edilmiş (ör. Hoppa panelinden).
            // Tekrar istek göndermiyoruz; yalnızca bizim tarafı eşitliyoruz.
            $amount = $q['amount'];
        } elseif ($q['state'] === 'paid' && $q['amount'] !== null && $q['amount'] > 0) {
            $amount       = $q['amount'];
            $providerCall = $gateway->refundPayment($order, $amount);
            $q            = $gateway->queryPayment($order);
        } else {
            $audit('reddedildi', $payment, ['detail' => 'Hoppa ödemeyi tahsil edilmiş göstermiyor', 'provider_state' => $q['state'], 'status_before' => $before]);
            return $result(422, false, 'Hoppa bu ödemeyi tahsil edilmiş göstermiyor (durum: ' . $q['state'] . '). İade yapılmadı.', AppConfig::ERR_PAYMENT);
        }

        $confirmed = in_array($q['state'], ['refunded', 'cancelled'], true);
        if (!$confirmed) {
            $audit($providerCall !== null && $providerCall['success'] ? 'dogrulanamadi' : 'basarisiz', $payment, [
                'amount'         => $amount,
                'provider'       => $providerCall['raw'] ?? null,
                'provider_state' => $q['state'],
                'status_before'  => $before,
            ]);
            $msg = $providerCall !== null && $providerCall['success']
                ? 'Hoppa iade isteğini kabul etti ama sorgu henüz iadeyi göstermiyor. Erişim kesilmedi; birkaç dakika sonra tekrar deneyin.'
                : 'İade yapılamadı: ' . (($providerCall['message'] ?? '') !== '' ? $providerCall['message'] : 'Hoppa hata döndü.');
            return $result(422, false, $msg, AppConfig::ERR_PAYMENT);
        }

        // Para Hoppa'da geri döndü. Durum + paket geri alma tek transaction'da.
        $ownTx = !$conn->inTransaction();
        if ($ownTx) {
            $conn->beginTransaction();
        }
        try {
            $db->execute(
                "UPDATE param_marketplace_payments SET status = 'refunded', callback_json = ? WHERE id = ? AND status = 'paid'",
                [json_encode(['refund' => ['query' => $q['raw'], 'provider' => $providerCall['raw'] ?? null]], JSON_UNESCAPED_UNICODE), $paymentId]
            );
            $revoked = revokeRefundedPlan($db, $payment);
            if ($ownTx) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('[hoppaRefund] KRİTİK: iade yapıldı ama durum yazılamadı order=' . $order . ' hata=' . $e->getMessage());
            $audit('durum_yazilamadi', $payment, ['amount' => $amount, 'provider_state' => $q['state'], 'error' => $e->getMessage()]);
            return $result(500, false, 'İade Hoppa\'da yapıldı ama kayıt güncellenemedi. Teknik ekibe bildirin.', AppConfig::ERR_SERVER);
        }

        $audit('tamamlandi', $payment, [
            'amount'         => $amount,
            'provider_state' => $q['state'],
            'provider'       => $providerCall['raw'] ?? 'zaten iade edilmişti (yalnızca eşitlendi)',
            'status_before'  => $before,
            'status_after'   => 'refunded',
            'plan'           => $revoked,
        ]);

        return $result(200, true, $revoked['revoked']
            ? 'İade tamamlandı; paket geri alındı.'
            : 'İade tamamlandı. Paket geri alınmadı: ' . $revoked['reason'] . '.', '', [
            'refunded_amount' => $amount,
            'full_refund'     => true,
            'provider_state'  => $q['state'],
            'plan'            => $revoked,
        ]);
    } finally {
        try { $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) {}
    }
}

/**
 * İade edilen paket ödemesinin verdiği paketi geri alır: kullanıcı
 * varsayılan (ücretsiz) plana döner. Oransal hesap yok (iyzico iade
 * akışındaki D-03 kuralıyla aynı: süre tamamen geri alınır).
 *
 * `user_plan_selection` hangi siparişten geldiğini tutmuyor (yalnızca
 * plan adı ve bitiş). Yanlış paketi kapatmamak için:
 *   - kullanıcının bu ödemeden SONRA ödenmiş başka bir paket ödemesi varsa
 *     paket geri alınmaz (geçerli dönem o ödemeye ait);
 *   - kullanıcının şu anki paketi iade edilen paket değilse (o arada
 *     değiştirmişse) dokunulmaz.
 * Her iki durumda da neden yanıtta ve admin_audit_log'da görünür.
 *
 * @return array{revoked:bool, reason:string, plan:string}
 */
function revokeRefundedPlan(Database $db, array $payment): array
{
    $item     = json_decode((string) $payment['items_json'], true)[0] ?? [];
    $planName = (string) ($item['plan_name'] ?? '');
    $userId   = (int) $payment['user_id'];

    $later = $db->selectSingle(
        "id FROM param_marketplace_payments
          WHERE user_id = ? AND id > ? AND status = 'paid' AND items_json LIKE ?
          LIMIT 1",
        [$userId, (int) $payment['id'], '%"plan_name"%']
    );
    if ($later) {
        return ['revoked' => false, 'reason' => 'kullanıcının sonradan ödenmiş başka bir paket ödemesi var', 'plan' => $planName];
    }

    $current = $db->selectSingle('plan_name FROM user_plan_selection WHERE user_id = ?', [$userId]);
    if (!$current || (string) $current['plan_name'] !== $planName) {
        return ['revoked' => false, 'reason' => 'kullanıcının şu anki paketi iade edilen paket değil', 'plan' => $planName];
    }

    $default  = $db->selectSingle('name_tr FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1');
    $freeName = $default ? (string) $default['name_tr'] : AppConfig::FREE_PLAN_NAME;
    $row = ['user_id' => $userId, 'plan_name' => $freeName, 'selected_at' => date('Y-m-d H:i:s')];
    if (planSelectionHasExpiry($db)) {
        $row['expires_at'] = null;
    }
    $db->insert('user_plan_selection', $row, true);

    return ['revoked' => true, 'reason' => '', 'plan' => $planName];
}
