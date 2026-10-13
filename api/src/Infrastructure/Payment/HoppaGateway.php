<?php
/**
 * Hoppa (EsnekPOS altyapısı) — Ortak Ödeme Sayfası + İşlem Sorgulama.
 *
 * Doküman: https://developer.esnekpos.com (llms.txt). Ayrıntı ve test
 * ortamında görülen yanıtlar: docs/proposals/hoppa-gecis-kesif.md §0.2.
 *
 *   CommonPaymentDealer → ödeme oturumu; kullanıcı URL_3DS'e yönlendirilir,
 *                         kartı Hoppa'nın sayfasında girer (N-17: kart bize
 *                         gelmez). Sonuç BACK_URL'e tarayıcıdan form POST.
 *   ProcessQuery        → sipariş referansıyla durum. Doküman: "ödeme
 *                         durumundan emin olmak için bu sorgunun cevabını
 *                         dikkate alın; server-to-server yapın." BACK_URL'e
 *                         gelen POST'a güvenilmez (AUTH_HASH algoritması
 *                         belgelenmemiş — hoppa-sorular.md #2).
 *
 * Kimlik doğrulama gövdedeki MERCHANT + MERCHANT_KEY ile; istek imzası yok.
 * Gövde MERCHANT_KEY içerdiği için istek gövdesi ASLA log'lanmaz.
 *
 * Ortam (api/.env):
 *   HOPPA_MODE              test (varsayılan) | live
 *   HOPPA_TEST_MERCHANT / HOPPA_TEST_MERCHANT_KEY
 *   HOPPA_LIVE_MERCHANT / HOPPA_LIVE_MERCHANT_KEY
 * Varsayılan bilinçli olarak `test`: yanlış yapılandırma sonucu gerçek karta
 * para düşmesindense test ortamına düşmesi yeğdir. `live` ancak canlı
 * anahtarlar girildiğinde yapılandırılmış sayılır.
 */
final class HoppaGateway implements PaymentGatewayInterface
{
    public const TEST_BASE_URL = 'https://posservicetest.esnekpos.com';
    public const LIVE_BASE_URL = 'https://posservice.esnekpos.com';

    /** Doküman: ORDER_REF_NUMBER en fazla 24 karakter. */
    public const MAX_ORDER_REF = 24;

    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT         = 40;

    /** ProcessQuery hareket durumları (doküman, "Durum Kodları"). */
    private const TX_PAID            = 3;
    private const TX_FAILED          = 4;
    private const TX_CANCEL_SUCCESS  = 5;
    private const TX_REFUND_SUCCESS  = 7;

    private string $mode;
    private string $merchant;
    private string $merchantKey;

    public function __construct(?string $mode = null, ?string $merchant = null, ?string $merchantKey = null)
    {
        $this->mode = strtolower(trim($mode ?? (string) env_get('HOPPA_MODE', 'test')));
        $prefix     = $this->mode === 'live' ? 'HOPPA_LIVE_' : 'HOPPA_TEST_';
        $this->merchant    = trim($merchant    ?? (string) env_get($prefix . 'MERCHANT', ''));
        $this->merchantKey = trim($merchantKey ?? (string) env_get($prefix . 'MERCHANT_KEY', ''));
    }

    public function isConfigured(): bool
    {
        return in_array($this->mode, ['test', 'live'], true)
            && $this->merchant !== ''
            && $this->merchantKey !== '';
    }

    public function isTest(): bool
    {
        return $this->mode !== 'live';
    }

    public function baseUrl(): string
    {
        return $this->isTest() ? self::TEST_BASE_URL : self::LIVE_BASE_URL;
    }

    // ── Ödeme oturumu ─────────────────────────────────────────────────────

    public function startHostedPayment(array $order): array
    {
        $fail = static fn (string $message, ?string $code = null, array $raw = []): array => [
            'success' => false, 'redirect_url' => null, 'provider_ref' => null,
            'message' => $message, 'error_code' => $code, 'raw' => $raw,
        ];

        if (!$this->isConfigured()) {
            return $fail('Ödeme altyapısı yapılandırılmamış.', 'CONFIG_MISSING');
        }

        $ref = (string) ($order['order_ref'] ?? '');
        if ($ref === '' || strlen($ref) > self::MAX_ORDER_REF) {
            return $fail('Geçersiz sipariş referansı.', 'BAD_ORDER_REF');
        }

        $customer = (array) ($order['customer'] ?? []);
        $products = [];
        foreach ((array) ($order['products'] ?? []) as $p) {
            $products[] = [
                'PRODUCT_ID'          => (string) ($p['id'] ?? ''),
                'PRODUCT_NAME'        => (string) ($p['name'] ?? ''),
                'PRODUCT_CATEGORY'    => (string) ($p['category'] ?? ''),
                'PRODUCT_DESCRIPTION' => (string) ($p['description'] ?? ''),
                'PRODUCT_AMOUNT'      => self::money((float) ($p['amount'] ?? 0)),
            ];
        }

        $res = $this->post('/api/pay/CommonPaymentDealer', [
            'Config' => [
                'MERCHANT'         => $this->merchant,
                'MERCHANT_KEY'     => $this->merchantKey,
                'ORDER_REF_NUMBER' => $ref,
                'ORDER_AMOUNT'     => self::money((float) ($order['amount'] ?? 0)),
                'PRICES_CURRENCY'  => 'TRY',
                'BACK_URL'         => (string) ($order['back_url'] ?? ''),
                'LOCALE'           => 'tr',
            ],
            'Customer' => [
                'FIRST_NAME' => (string) ($customer['first_name'] ?? ''),
                'LAST_NAME'  => (string) ($customer['last_name'] ?? ''),
                'MAIL'       => (string) ($customer['email'] ?? ''),
                'PHONE'      => (string) ($customer['phone'] ?? ''),
                'CITY'       => (string) ($customer['city'] ?? ''),
                'STATE'      => (string) ($customer['state'] ?? ''),
                'ADDRESS'    => (string) ($customer['address'] ?? ''),
            ],
            'Product' => $products,
        ]);

        if (isset($res['__transport_error'])) {
            return $fail('Ödeme altyapısına ulaşılamadı. Lütfen tekrar deneyin.', 'TRANSPORT', $res);
        }

        $raw = self::redact($res);
        if (($res['STATUS'] ?? '') !== 'SUCCESS' || (string) ($res['RETURN_CODE'] ?? '') !== '0') {
            $msg = (string) ($res['RETURN_MESSAGE_TR'] ?? '') ?: (string) ($res['RETURN_MESSAGE'] ?? '');
            return $fail($msg !== '' ? $msg : 'Ödeme başlatılamadı.', (string) ($res['RETURN_CODE'] ?? 'UNKNOWN'), $raw);
        }

        $url = (string) ($res['URL_3DS'] ?? '');
        if (!self::isTrustedPaymentUrl($url)) {
            // Kullanıcıyı sağlayıcının dışında bir adrese yönlendirmeyiz.
            error_log('[hoppa] URL_3DS beklenen alan adında/https değil — yönlendirme reddedildi. order=' . $ref);
            return $fail('Ödeme sayfası adresi doğrulanamadı.', 'BAD_REDIRECT', $raw);
        }

        return [
            'success'      => true,
            'redirect_url' => $url,
            'provider_ref' => isset($res['REFNO']) ? (string) $res['REFNO'] : null,
            'message'      => '',
            'error_code'   => null,
            'raw'          => $raw,
        ];
    }

    // ── Durum sorgulama ───────────────────────────────────────────────────

    public function queryPayment(string $orderRef): array
    {
        if (!$this->isConfigured()) {
            return self::queryResult('unknown', 'Ödeme altyapısı yapılandırılmamış.');
        }

        $res = $this->post('/api/services/ProcessQuery', [
            'MERCHANT'         => $this->merchant,
            'MERCHANT_KEY'     => $this->merchantKey,
            'ORDER_REF_NUMBER' => $orderRef,
        ]);

        if (isset($res['__transport_error'])) {
            return self::queryResult('unknown', 'Ödeme durumu sorgulanamadı.', null, null, null, $res);
        }
        return self::classifyQueryResponse($res, $orderRef);
    }

    // ── İade / iptal ──────────────────────────────────────────────────────

    /**
     * OrderReturn — sipariş referansı + tutar. `SYNC_WITH_POS=true`: banka
     * ile eşzamanlı yapılır (false olsaydı talep kaydedilip Hoppa operasyonu
     * tarafından sonradan tamamlanırdı; sonucu bilemezdik).
     *
     * Test ortamında görülen (2026-10-07): ödemeyle aynı gün tam tutarda
     * istek → `STATUS=SUCCESS, RETURN_CODE=0, "İŞLEM İPTAL EDİLDİ"`;
     * sonrasında ProcessQuery `ORDER_CANCEL` / hareket `STATUS_ID=5`. Aynı
     * siparişe ikinci istek → `RETURN_CODE=100`, "Referans numarası
     * bulunamadı". Ertesi gün yapılan iadenin `STATUS_ID=7` döneceği
     * dokümandan; test edilmedi.
     */
    public function refundPayment(string $orderRef, float $amount): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Ödeme altyapısı yapılandırılmamış.', 'error_code' => 'CONFIG_MISSING', 'raw' => []];
        }
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Geçersiz iade tutarı.', 'error_code' => 'BAD_AMOUNT', 'raw' => []];
        }

        $res = $this->post('/api/services/OrderReturn', [
            'MERCHANT'         => $this->merchant,
            'MERCHANT_KEY'     => $this->merchantKey,
            'ORDER_REF_NUMBER' => $orderRef,
            'AMOUNT'           => round($amount, 2),
            'SYNC_WITH_POS'    => true,
        ]);

        if (isset($res['__transport_error'])) {
            return ['success' => false, 'message' => 'Ödeme altyapısına ulaşılamadı.', 'error_code' => 'TRANSPORT', 'raw' => $res];
        }

        $ok = ($res['STATUS'] ?? '') === 'SUCCESS' && (string) ($res['RETURN_CODE'] ?? '') === '0';
        return [
            'success'    => $ok,
            'message'    => (string) ($res['RETURN_MESSAGE'] ?? ''),
            'error_code' => $ok ? null : (string) ($res['RETURN_CODE'] ?? 'UNKNOWN'),
            'raw'        => self::redact($res),
        ];
    }

    /**
     * ProcessQuery yanıtını yorumlar. Saf fonksiyon — selftest A bölümü
     * test ortamında kaydedilmiş gerçek yanıtlarla çağırıyor.
     *
     * Test ortamında görülen (2026-10-07):
     *   ödendi     → STATUS=SUCCESS, RETURN_CODE=0, SUCCESS_TRANSACTION_ID>0,
     *                o hareketin STATUS_ID=3
     *   reddedildi → STATUS=ERROR, RETURN_CODE=100, son hareket STATUS_ID=4
     *   ödenmedi   → RETURN_CODE=400 "Referans numarası bulunamadı" — oturum
     *                açılmış ama kart girilmemiş; kullanıcı hâlâ ödeyebilir,
     *                bu yüzden FAILED değil PENDING.
     * Dokümandan: iptal STATUS=ORDER_CANCEL/RETURN_CODE=300; iade
     * STATUS=SUCCESS + STATUS_ID=7 hareketi. İkisi de "ödendi" sayılmaz.
     */
    public static function classifyQueryResponse(array $res, string $orderRef): array
    {
        $status = (string) ($res['STATUS'] ?? '');
        $code   = (string) ($res['RETURN_CODE'] ?? '');
        $msg    = (string) ($res['RETURN_MESSAGE'] ?? '');
        $raw    = self::redact($res);

        if ($code === '400') {
            return self::queryResult('pending', $msg, null, null, null, $raw);
        }

        // Yanıt başka bir siparişe aitse hiçbir şeye karar verme.
        $echoed = (string) ($res['ORDER_REF_NO'] ?? ($res['ORDER_REF_NUMBER'] ?? ''));
        if ($echoed !== '' && $echoed !== $orderRef) {
            return self::queryResult('unknown', 'Sorgu yanıtı farklı bir siparişe ait.', null, null, null, $raw);
        }

        $amount     = self::parseAmount($res['AMOUNT'] ?? null);
        $commission = self::parseAmount($res['COMMISSION'] ?? null);
        $txs        = is_array($res['TRANSACTIONS'] ?? null) ? $res['TRANSACTIONS'] : [];
        $txIds      = array_map(static fn ($t) => (int) ($t['STATUS_ID'] ?? 0), $txs);

        if ($status === 'ORDER_CANCEL' || in_array(self::TX_CANCEL_SUCCESS, $txIds, true)) {
            return self::queryResult('cancelled', $msg, $amount, $commission, null, $raw);
        }
        if (in_array(self::TX_REFUND_SUCCESS, $txIds, true)) {
            return self::queryResult('refunded', $msg, $amount, $commission, null, $raw);
        }

        $successTx = (int) ($res['SUCCESS_TRANSACTION_ID'] ?? 0);
        if ($status === 'SUCCESS' && $code === '0' && $successTx > 0) {
            foreach ($txs as $t) {
                if ((int) ($t['TRANSACTION_ID'] ?? 0) === $successTx && (int) ($t['STATUS_ID'] ?? 0) === self::TX_PAID) {
                    return self::queryResult('paid', $msg, $amount, $commission, (string) $successTx, $raw);
                }
            }
            return self::queryResult('unknown', 'Başarılı işlem hareketi bulunamadı.', $amount, $commission, null, $raw);
        }

        $last = $txIds !== [] ? end($txIds) : 0;
        if ($successTx === 0 && $last === self::TX_FAILED) {
            return self::queryResult('failed', $msg, $amount, $commission, null, $raw);
        }

        // Hareketler hâlâ "bekliyor / 3D doğrulama bekleniyor" ya da tanımadığımız bir durum.
        return self::queryResult($txIds !== [] ? 'pending' : 'unknown', $msg, $amount, $commission, null, $raw);
    }

    /**
     * Çekilen tutar siparişi karşılıyor mu? İki model de geçerli:
     *   - komisyonu üye işyeri karşılıyor → AMOUNT = sipariş tutarı
     *   - komisyon alıcıya yansıtılıyor (test üye işyerinde görüldü, N-19)
     *     → AMOUNT − COMMISSION = sipariş tutarı
     */
    public static function amountCovers(float $expected, ?float $amount, ?float $commission): bool
    {
        if ($amount === null) {
            return false;
        }
        if (abs($amount - $expected) < 0.005) {
            return true;
        }
        return $commission !== null && abs(($amount - $commission) - $expected) < 0.005;
    }

    // ── Yardımcılar ───────────────────────────────────────────────────────

    /** Hoppa "151,25" / "1.250,00" (Türkçe) ya da 2.25 (sayı) dönebiliyor. */
    public static function parseAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** İstek tutarı: "149.00". */
    public static function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    /** Yönlendirme yalnızca https ve esnekpos.com alt alanına. */
    public static function isTrustedPaymentUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $host === 'esnekpos.com' || str_ends_with($host, '.esnekpos.com');
    }

    /** Ham yanıttan müşteri ve kart alanlarını çıkarır (kart zaten maskeli gelir, yine de saklamıyoruz). */
    public static function redact(array $res): array
    {
        foreach (array_keys($res) as $k) {
            if (str_starts_with((string) $k, 'CUSTOMER_') || $k === 'MERCHANT_KEY') {
                unset($res[$k]);
            }
        }
        return $res;
    }

    private static function queryResult(string $state, string $message, ?float $amount = null, ?float $commission = null, ?string $txId = null, array $raw = []): array
    {
        return [
            'state'          => $state,
            'amount'         => $amount,
            'commission'     => $commission,
            'transaction_id' => $txId,
            'message'        => $message,
            'raw'            => $raw,
        ];
    }

    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['__transport_error' => 'encode'];
        }

        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            // Ödeme trafiğinde sertifika doğrulaması kapatılamaz.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            error_log(sprintf('[hoppa] %s başarısız http=%d curl=%s', $path, $httpCode, $curlErr));
            return ['__transport_error' => $curlErr !== '' ? $curlErr : 'http ' . $httpCode];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            error_log('[hoppa] ' . $path . ' JSON olmayan yanıt döndü.');
            return ['__transport_error' => 'invalid json'];
        }
        return $decoded;
    }
}
