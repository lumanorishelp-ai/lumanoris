<?php
/**
 * Ödeme sağlayıcısı seçimi — `PAYMENT_PROVIDER` ortam değişkeni.
 *
 *   none  (varsayılan) → sağlayıcı yok; paket ödemesi 503 ile kapalı.
 *   hoppa              → HoppaGateway (Ortak Ödeme Sayfası).
 *
 * Varsayılan bilinçli olarak `none`: biri yalnızca anahtar girerek
 * tahsilatı açamaz, sağlayıcının ayrıca ve açıkça seçilmesi gerekir.
 * Tanınmayan bir değer de `none` sayılır (fail-closed).
 *
 * Kapsam: yalnızca barındırılan sayfa akışı (paket satın alma, Faz 7a).
 * Pazaryeri satışı hâlâ `chargeCard()` (iyzico) üzerinden ve bu seçimden
 * etkilenmiyor.
 */
final class PaymentGatewayFactory
{
    public const PROVIDERS = ['none', 'hoppa'];

    public static function provider(?string $configured = null): string
    {
        $value = strtolower(trim($configured ?? (string) env_get('PAYMENT_PROVIDER', 'none')));
        if ($value === '') {
            return 'none';
        }
        if (!in_array($value, self::PROVIDERS, true)) {
            error_log('[payment] PAYMENT_PROVIDER tanınmıyor — ödeme kapalı (none) sayıldı.');
            return 'none';
        }
        return $value;
    }

    /** Sağlayıcı seçilmemişse null. Seçilmiş ama yapılandırılmamışsa da null. */
    public static function make(?string $configured = null): ?PaymentGatewayInterface
    {
        $gateway = match (self::provider($configured)) {
            'hoppa' => new HoppaGateway(),
            default => null,
        };

        if ($gateway !== null && !$gateway->isConfigured()) {
            error_log('[payment] sağlayıcı seçili ama kimlik bilgileri eksik — ödeme kapalı.');
            return null;
        }
        return $gateway;
    }
}
