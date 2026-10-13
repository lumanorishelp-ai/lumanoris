<?php
/**
 * Barındırılan ödeme sayfası (hosted page) ile çalışan ödeme sağlayıcısı.
 *
 * Faz 7a (2026-10-07) — kart verisi Lumanoris'e HİÇ ulaşmaz (N-17): kullanıcı
 * sağlayıcının sayfasına yönlendirilir, kartı orada girer, sonuç bize
 * tarayıcı üzerinden döner. Geri dönen veriye güvenilmez; ödemenin sonucu
 * yalnızca sunucudan sağlayıcıya yapılan sorguyla (`queryPayment`) kesinleşir.
 *
 * Bilinçli olarak `charge(card, …)` YOK. iyzico'nun senkron kart akışı
 * (`chargeCard`, pazaryeri) bu arayüzün dışında ve olduğu gibi duruyor.
 *
 * Uygulamalar: HoppaGateway. Seçim: PaymentGatewayFactory (PAYMENT_PROVIDER).
 */
interface PaymentGatewayInterface
{
    /** Kimlik bilgileri eksikse hiçbir çağrı denenmemeli. */
    public function isConfigured(): bool;

    /** Test/sandbox ortamına mı bağlı? */
    public function isTest(): bool;

    /**
     * Ödeme oturumu açar.
     *
     * $order: order_ref, amount (float), back_url, customer[first_name,
     * last_name, email, phone, city, state, address], products[[id, name,
     * category, description, amount]].
     *
     * Dönüş: success (bool), redirect_url (?string), provider_ref (?string),
     * message (string), error_code (?string), raw (array — kişisel/kart
     * verisi ayıklanmış).
     */
    public function startHostedPayment(array $order): array;

    /**
     * Sipariş referansıyla ödemenin durumunu sağlayıcıdan sorar.
     *
     * Dönüş: state ('paid' | 'failed' | 'pending' | 'cancelled' | 'refunded'
     * | 'unknown'), amount (?float — karttan çekilen), commission (?float),
     * transaction_id (?string), message (string), raw (array).
     */
    public function queryPayment(string $orderRef): array;

    /**
     * Siparişin TAMAMINI iade/iptal eder (kısmi iade yok — iş kuralı).
     * $amount karttan çekilen tutardır (komisyon alıcıya yansıtıldıysa dahil).
     *
     * Dönüş: success (bool), message (string), error_code (?string),
     * raw (array). Başarı kesin kabul edilmez; çağıran `queryPayment` ile
     * 'refunded' / 'cancelled' durumunu doğrular.
     */
    public function refundPayment(string $orderRef, float $amount): array;
}
