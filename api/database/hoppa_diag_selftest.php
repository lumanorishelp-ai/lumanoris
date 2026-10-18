<?php
/**
 * Hoppa teşhis öz-testi (N-57) — çevrimdışı, ağa çıkmaz, veritabanına dokunmaz.
 *
 *   php api/database/hoppa_diag_selftest.php
 *
 * A) Bağlantı testi (ProcessQuery, var olmayan referans) sonucu sınıflanıyor.
 *    Girdiler 2026-10-18'de Hoppa TEST ortamında görülen gerçek yanıtlar.
 * B) Hoppa hata kodu → kullanıcıya Türkçe mesaj (dokümandaki "Hata Kodları"
 *    tablosu: 100, 101, 102, 103, 104, 105, 106, 201, 206, 34) + banka mesajı.
 * C) Mesajlar sızdırmıyor: kimlik/yapılandırma ayrıntısı kullanıcıya gitmez.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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

$lib = __DIR__ . '/../functions/hoppa_diagnostics.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!function_exists('hoppaProbeClassify') || !function_exists('hoppaUserMessage')) {
    check('functions/hoppa_diagnostics.php: hoppaProbeClassify, hoppaUserMessage', false, 'tanımlı değil');
    finish();
}

echo "\n=== A) Bağlantı testi sınıflaması ===\n\n";
$q = static fn (array $raw, string $state = 'unknown'): array => ['state' => $state, 'raw' => $raw, 'message' => (string) ($raw['RETURN_MESSAGE'] ?? '')];

$r = hoppaProbeClassify($q(['STATUS' => 'PROCESS_QUERY', 'RETURN_CODE' => '400', 'RETURN_MESSAGE' => 'Referans numarası bulunamadı (Not.ProcessQuery)'], 'pending'));
check('400 "Referans bulunamadı" → kimlik kabul edildi', $r['ok'] === true && $r['kind'] === 'auth_ok', json_encode($r, JSON_UNESCAPED_UNICODE));

$r = hoppaProbeClassify($q(['STATUS' => 'AUTHENTICATION_ERROR', 'RETURN_CODE' => '100', 'RETURN_MESSAGE' => 'Authentication.Error']));
check('AUTHENTICATION_ERROR (test ortamında kod 100) → kimlik reddedildi', $r['ok'] === false && $r['kind'] === 'auth', json_encode($r, JSON_UNESCAPED_UNICODE));
check('kimlik reddi açıklaması MERCHANT_KEY ve moddan söz ediyor', str_contains($r['explain'], 'MERCHANT_KEY') && str_contains($r['explain'], 'HOPPA_MODE'));

// Canlı (2026-10-18): bağlantı testi 100, ödeme başlatma 102 — ikisinde de aynı metin.
// Kimlik DOĞRU; Hoppa sunucumuzun çıkış IP'sini tanımıyor. "Anahtarı karşılaştır"
// demek yanlış yönlendiriyordu.
$ipMsg = 'Bu IP adresinden işlem yapma yetkiniz bulunmamaktadır. IP: 45.143.99.2';
$r = hoppaProbeClassify($q(['STATUS' => 'AUTHENTICATION_ERROR', 'RETURN_CODE' => '100', 'RETURN_MESSAGE' => $ipMsg]));
check('IP reddi (AUTHENTICATION_ERROR + "IP adresinden") → ip, kimlik değil', $r['ok'] === false && $r['kind'] === 'ip', json_encode($r, JSON_UNESCAPED_UNICODE));
check('IP reddi açıklaması IP\'yi ve Hoppa panelini söylüyor, anahtar karşılaştırması istemiyor',
    str_contains($r['explain'], '45.143.99.2') && str_contains($r['explain'], 'IP') && !str_contains($r['explain'], 'MERCHANT_KEY'), $r['explain']);
check('IP reddi kullanıcıya "bakımda"', str_contains(hoppaUserMessage('102', 'AUTHENTICATION_ERROR', $ipMsg), 'bakımda'));
check('IP reddi kullanıcı mesajında IP yok', !str_contains(hoppaUserMessage('102', 'AUTHENTICATION_ERROR', $ipMsg), '45.143'));

$r = hoppaProbeClassify($q(['STATUS' => 'ERROR', 'RETURN_CODE' => '102', 'RETURN_MESSAGE' => 'x']));
check('kod 102 → kimlik reddedildi', $r['kind'] === 'auth');

$r = hoppaProbeClassify($q(['STATUS' => 'ERROR', 'RETURN_CODE' => '100', 'RETURN_MESSAGE' => 'Service not authorized']));
check('kod 100 genel → servis yetkisi / genel hata', $r['ok'] === false && $r['kind'] === 'not_authorized');

$r = hoppaProbeClassify($q(['__transport_error' => 'Could not resolve host: posservice.esnekpos.com']));
check('ağ hatası → transport, curl mesajı korunuyor', $r['kind'] === 'transport' && str_contains($r['explain'], 'Could not resolve host'));

$r = hoppaProbeClassify($q(['STATUS' => 'SUCCESS', 'RETURN_CODE' => '0', 'ORDER_REF_NO' => 'X']));
check('beklenmeyen başarı (referans bulunmuş) → yine de kimlik kabul', $r['ok'] === true && $r['kind'] === 'auth_ok');

$r = hoppaProbeClassify($q(['STATUS' => 'WHATEVER', 'RETURN_CODE' => '999']));
check('tanınmayan yanıt → unknown, ok=false', $r['ok'] === false && $r['kind'] === 'unknown');

$r = hoppaProbeClassify(['state' => 'unknown', 'raw' => [], 'message' => 'Ödeme altyapısı yapılandırılmamış.']);
check('yapılandırma yok (istek atılmadı) → config', $r['kind'] === 'config');

echo "\n=== B) Kullanıcı mesajları ===\n\n";
$cases = [
    ['TRANSPORT', '', '', 'ulaşılamıyor'],
    ['100', 'AUTHENTICATION_ERROR', 'Authentication.Error', 'bakımda'],
    ['100', 'ERROR', '', 'bakımda'],
    ['102', 'AUTHENTICATION_ERROR', '', 'bakımda'],
    ['104', 'COMMISSION_ERROR', '', 'bakımda'],
    ['CONFIG_MISSING', '', '', 'bakımda'],
    ['101', 'FIELD_ERROR', 'PHONE alanı hatalı', 'profil'],
    ['103', 'LIMIT_ERROR', '', 'tutar'],
    ['105', 'INSERT_ERROR', '', 'tekrar deneyin'],
    ['106', 'BRAND_ERROR', '', 'kart türü'],
    ['201', 'PAYMENT_ERROR', '', 'tamamlanamadı'],
    ['206', 'Not3DAuthentication', '', '3D Secure'],
    ['34', 'BLOCKED_ERROR', '', 'güvenlik'],
    ['BAD_REDIRECT', '', '', 'tekrar deneyin'],
];
foreach ($cases as [$code, $status, $msg, $needle]) {
    $m = hoppaUserMessage($code, $status, $msg);
    check("kod $code" . ($status !== '' ? " / $status" : '') . " → \"…{$needle}…\"", mb_stripos($m, $needle) !== false, $m);
}
$m = hoppaUserMessage('100', 'ERROR', '51-Limit Yetersiz');
check('banka reddi (NN-mesaj) → "Kartınız reddedildi: Limit Yetersiz"', str_contains($m, 'Kartınız reddedildi') && str_contains($m, 'Limit Yetersiz') && !str_contains($m, '51-'), $m);
$m = hoppaUserMessage('', '', '');
check('bilinmeyen → genel ama boş değil', $m !== '' && str_contains($m, 'tekrar'), $m);

echo "\n=== B2) Dönüş nedeni (URL'de taşınan izinli kod) ===\n\n";
foreach ([
    ['51-Limit Yetersiz', '', 'limit'],
    ['05-Genel Red', '', 'kart'],
    ['54-Kartın süresi dolmuş', '', 'kart'],
    ['3D doğrulama başarısız', '', '3d'],
    ['', '206', '3d'],
    ['', '34', 'guvenlik'],
    ['', '106', 'kart'],
    ['', '', 'genel'],
    ['<script>alert(1)</script>', '', 'genel'],
] as [$msg, $code, $want]) {
    $got = hoppaFailureReason($msg, $code);
    check("\"$msg\" / $code → $want", $got === $want, $got);
}
check('dönen kod her zaman izinli kümede', in_array(hoppaFailureReason('99-'.str_repeat('x', 50)), ['limit', 'kart', '3d', 'guvenlik', 'genel'], true));

$s = hoppaLogSummary(['STATUS' => 'FIELD_ERROR', 'RETURN_CODE' => '101', 'RETURN_MESSAGE' => "PHONE\ninvalid", 'ERROR_CODE' => 'X']);
check('log özeti: durum, kod, tek satır mesaj', str_contains($s, 'status=FIELD_ERROR') && str_contains($s, 'return_code=101') && !str_contains($s, "\n"), $s);
check('log özeti: ağ hatası', hoppaLogSummary(['__transport_error' => 'timeout']) === 'transport=timeout');

echo "\n=== C) Sızıntı yok ===\n\n";
foreach ([['100', 'AUTHENTICATION_ERROR', 'Authentication.Error'], ['102', '', 'Merchant key invalid'], ['CONFIG_MISSING', '', '']] as [$c, $s, $msg]) {
    $m = hoppaUserMessage($c, $s, $msg);
    check("kod $c: kullanıcı mesajında MERCHANT / anahtar / Authentication yok",
        !preg_match('/merchant|anahtar|key|authentication/i', $m), $m);
}

finish();
