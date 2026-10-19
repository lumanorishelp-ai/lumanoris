<?php
/**
 * Hoppa teşhisi (N-57) — saf fonksiyonlar, ağ/veritabanı yok.
 *
 *   hoppaProbeClassify() → Admin → Sistem Durumu → "Hoppa bağlantı testi".
 *     Test, var olmayan bir sipariş referansıyla ProcessQuery atar: ödeme
 *     başlatmaz, para hareketi yoktur, ama MERCHANT/MERCHANT_KEY'i doğrular.
 *     Hoppa TEST ortamında görülen (2026-10-18):
 *       geçerli kimlik  → STATUS=PROCESS_QUERY, RETURN_CODE=400,
 *                         "Referans numarası bulunamadı (Not.ProcessQuery)"
 *       uydurma kimlik  → STATUS=AUTHENTICATION_ERROR, RETURN_CODE=100,
 *                         "Authentication.Error"
 *     Hoppa'da bundan daha zararsız, yalnızca kimlik doğrulayan bir uç
 *     yok (GetDealerBalance da var ama pazaryeri modülüne bağlı; yetkisiz
 *     üye işyerinde 100 dönebilir ve "kimlik yanlış" ile karışır).
 *
 *   hoppaUserMessage() → ödeme başlatma / dönüş hatasında kullanıcıya giden
 *     Türkçe mesaj. Kodlar: developer.esnekpos.com → Hata Kodları. Kimlik /
 *     yapılandırma sorunları kullanıcıya "bakımda" olarak gider; ayrıntı
 *     log'a yazılır (bkz. hoppaLogSummary).
 */

/**
 * @param array $query HoppaGateway::queryPayment() sonucu
 * @return array{ok: bool, kind: string, explain: string, status: string, code: string, message: string}
 */
function hoppaProbeClassify(array $query): array {
    $raw    = (array) ($query['raw'] ?? []);
    $status = (string) ($raw['STATUS'] ?? '');
    $code   = (string) ($raw['RETURN_CODE'] ?? '');
    $msg    = (string) ($raw['RETURN_MESSAGE'] ?? '');
    $out    = static fn (bool $ok, string $kind, string $explain): array => [
        'ok' => $ok, 'kind' => $kind, 'explain' => $explain, 'status' => $status, 'code' => $code, 'message' => $msg,
    ];

    if (isset($raw['__transport_error'])) {
        return $out(false, 'transport', 'Hoppa sunucusuna ulaşılamadı (ağ, DNS, SSL ya da IP engeli): '
            . (string) $raw['__transport_error'] . '. Sunucunun dışarıya HTTPS çıkışı ve Hoppa\'nın IP kısıtı kontrol edilmeli.');
    }
    if ($raw === []) {
        return $out(false, 'config', 'İstek atılmadı: seçili mod için MERCHANT / MERCHANT_KEY .env\'de boş ya da HOPPA_MODE geçersiz.');
    }
    if ($code === '400' || $status === 'PROCESS_QUERY' || ($status === 'SUCCESS' && $code === '0')) {
        return $out(true, 'auth_ok', 'Kimlik bilgileri kabul edildi; Hoppa sorgu servisi yanıt verdi. '
            . 'Ödeme yine başlatılamıyorsa nedeni php-error.log\'daki [upgradePlan] satırında.');
    }
    // Canlı 2026-10-18: "Bu IP adresinden işlem yapma yetkiniz bulunmamaktadır.
    // IP: …" (100/102, AUTHENTICATION_ERROR). Kimlik doğru; sunucunun çıkış
    // IP'si Hoppa'nın izinli listesinde değil.
    if (preg_match('/IP adres/iu', $msg)) {
        $ip = preg_match('/IP:\s*([0-9a-f.:]+)/i', $msg, $m) ? $m[1] : 'bilinmiyor';
        return $out(false, 'ip', "Hoppa bu sunucunun IP adresini ($ip) tanımıyor; kimlik bilgileri sorun değil. "
            . "Hoppa panelinde (pos.esnekpos.com) izinli IP listesine $ip eklenmeli ya da Hoppa destekten istenmeli "
            . '(destek@esnekpos.com). Hosting IP\'si değişirse tanım yenilenmeli.');
    }
    if ($status === 'AUTHENTICATION_ERROR' || $code === '102') {
        return $out(false, 'auth', 'Hoppa kimlik bilgilerini REDDETTİ. .env\'deki MERCHANT_KEY / MERCHANT seçili HOPPA_MODE ile '
            . 'eşleşmiyor ya da Hoppa anahtarı yeniledi (canlı modda HOPPA_LIVE_*). Hoppa panelindeki güncel değerlerle karşılaştırın.');
    }
    if ($code === '100') {
        return $out(false, 'not_authorized', 'Hoppa genel hata / servis yetkisi yok döndü (kod 100). Üye işyeri canlıda henüz '
            . 'aktif olmayabilir; Hoppa destek ile görüşülmeli (destek@esnekpos.com).');
    }
    return $out(false, 'unknown', 'Tanınmayan yanıt. Ham durum ve kod aşağıda; Hoppa destek ile paylaşılabilir.');
}

/**
 * Hoppa hata kodu → kullanıcıya gösterilecek Türkçe mesaj.
 *
 * @param string $code   RETURN_CODE ya da bizim kodumuz (TRANSPORT, CONFIG_MISSING, BAD_REDIRECT, BAD_ORDER_REF)
 * @param string $status STATUS
 * @param string $providerMessage RETURN_MESSAGE(_TR) — yalnızca banka reddi biçiminde ("51-Limit Yetersiz") gösterilir
 */
function hoppaUserMessage(string $code, string $status = '', string $providerMessage = ''): string {
    $maintenance = 'Ödeme sistemi şu an bakımda. Lütfen biraz sonra tekrar deneyin; sorun sürerse bizimle iletişime geçin.';

    // Banka reddi: "51-Limit Yetersiz", "05-Genel Red" … — kullanıcıya anlamlı.
    if (preg_match('/^\s*\d{1,3}\s*-\s*(\S.{1,80})$/u', $providerMessage, $m)) {
        return 'Kartınız reddedildi: ' . trim($m[1]) . '. Başka bir kartla deneyebilir ya da bankanızla görüşebilirsiniz.';
    }
    if ($status === 'AUTHENTICATION_ERROR') {
        return $maintenance;
    }
    return match ($code) {
        'TRANSPORT'                         => 'Ödeme sistemine şu an ulaşılamıyor. Birkaç dakika sonra tekrar deneyin.',
        '100', '102', '104', 'CONFIG_MISSING' => $maintenance,
        '101'                               => 'Ödeme başlatılamadı: hesap bilgilerinizden biri (ad soyad, e-posta, telefon) ödeme sistemince kabul edilmedi. Profil bilgilerinizi kontrol edip tekrar deneyin.',
        '103'                               => 'Ödeme sistemi bu tutarda işlemi şu an kabul etmiyor. Lütfen bizimle iletişime geçin.',
        '105', 'BAD_REDIRECT', 'BAD_ORDER_REF' => 'Ödeme sistemi geçici bir hata verdi. Lütfen tekrar deneyin.',
        '106'                               => 'Bu kart türü kabul edilmiyor. Başka bir kartla deneyin.',
        '201'                               => 'Ödeme tamamlanamadı. Kartınızdan çekim yapıldıysa otomatik olarak iade edilir; tekrar deneyebilirsiniz.',
        '206'                               => '3D Secure doğrulaması tamamlanamadı. Bankanızdan gelen şifreyi kontrol edip tekrar deneyin.',
        '34'                                => 'İşlem güvenlik nedeniyle reddedildi. Lütfen bankanızla iletişime geçin.',
        default                             => 'Ödeme başlatılamadı. Lütfen biraz sonra tekrar deneyin.',
    };
}

/**
 * Başarısız dönüşün nedeni → URL'de taşınacak kısa, izinli kod
 * (`/dashboard/upgrade?odeme=failed&neden=…`). Serbest metin taşınmaz:
 * herkes bu adresi elle açabilir, sayfa yalnızca bilinen kodlar için
 * kendi metnini gösterir.
 *
 * @param string $providerMessage ProcessQuery RETURN_MESSAGE (ör. "51-Limit Yetersiz")
 * @return string limit | kart | 3d | guvenlik | genel
 */
function hoppaFailureReason(string $providerMessage, string $code = ''): string {
    if ($code === '206' || preg_match('/\b3\s?D\b|3D Secure|doğrulama/iu', $providerMessage)) {
        return '3d';
    }
    if ($code === '34' || preg_match('/kara\s*liste|blacklist|fraud|güvenlik/iu', $providerMessage)) {
        return 'guvenlik';
    }
    if (preg_match('/^\s*(\d{1,3})\s*-/u', $providerMessage, $m)) {
        return $m[1] === '51' ? 'limit' : 'kart';
    }
    if ($code === '106') {
        return 'kart';
    }
    return 'genel';
}

/**
 * Log satırı için özet: durum, kod, Hoppa mesajı (kişisel alan ve anahtar içermez —
 * HoppaGateway::redact() sonrası `raw` beklenir).
 */
function hoppaLogSummary(array $raw): string {
    if (isset($raw['__transport_error'])) {
        return 'transport=' . (string) $raw['__transport_error'];
    }
    $pick = static fn (string $k): string => str_replace(["\r", "\n"], ' ', mb_substr((string) ($raw[$k] ?? '-'), 0, 160));
    return sprintf('status=%s return_code=%s error_code=%s message=%s message_tr=%s',
        $pick('STATUS'), $pick('RETURN_CODE'), $pick('ERROR_CODE'), $pick('RETURN_MESSAGE'), $pick('RETURN_MESSAGE_TR'));
}
