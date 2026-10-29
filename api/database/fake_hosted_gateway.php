<?php
/**
 * Testlerin ortak sahte Hoppa sağlayıcısı (hoppa_selftest, timezone_selftest,
 * plan_coin_selftest). Ağ yok; yanıtlar testin elinde.
 * `api/database/` web'e kapalı (üç denylist).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class FakeHostedGateway implements PaymentGatewayInterface
{
    public int $queries = 0;
    public array $next  = ['state' => 'pending', 'amount' => null, 'commission' => null, 'transaction_id' => null, 'message' => '', 'raw' => []];
    /** Sipariş başına sabit yanıt (mutabakat testi). */
    public array $byOrder = [];
    /** Sırayla tüketilen yanıtlar (iade testi: önce/sonra sorgusu). Doluysa önceliklidir. */
    public array $queue = [];
    /** Sorulan siparişler, sırayla. */
    public array $asked = [];
    public int $refunds = 0;
    public ?float $lastRefundAmount = null;
    public array $refundResult = ['success' => true, 'message' => 'İŞLEM İPTAL EDİLDİ', 'error_code' => null, 'raw' => ['STATUS' => 'SUCCESS']];

    public function isConfigured(): bool { return true; }
    public function isTest(): bool { return true; }
    /** Doluysa startHostedPayment bunu döner (N-57: başarısız başlatma). */
    public ?array $startResult = null;
    public function startHostedPayment(array $order): array
    {
        if ($this->startResult !== null) {
            return $this->startResult;
        }
        return ['success' => true, 'redirect_url' => 'https://postest.esnekpos.com/Pages/CommonPaymentNew.aspx?hash=fake',
                'provider_ref' => '1', 'message' => '', 'error_code' => null, 'raw' => []];
    }
    public function queryPayment(string $orderRef): array
    {
        $this->queries++;
        $this->asked[] = $orderRef;
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        return $this->byOrder[$orderRef] ?? $this->next;
    }
    public function refundPayment(string $orderRef, float $amount): array
    {
        $this->refunds++;
        $this->lastRefundAmount = $amount;
        return $this->refundResult;
    }
}
