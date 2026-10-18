<?php
/**
 * URL okuma öz-testi (N-61) — çevrimdışı, ağa çıkmaz, veritabanına dokunmaz.
 *
 *   php api/database/readurl_selftest.php
 *
 * Madde 3: bazı siteler "Sayfaya ulaşılamadı" veriyordu ve hata türleri
 * ayırt edilmiyordu. Sınıflama ve içerik çıkarma saf fonksiyonlarda
 * (WebPageText); burada sabit HTML/başlık örnekleriyle sınanıyor.
 *
 * A) Hata sınıfları: DNS, zaman aşımı, bağlantı, SSL, bot koruması (403/503 +
 *    Cloudflare), giriş gerekli, 404, 5xx, PDF, desteklenmeyen tür.
 * B) İçerik: menü/altbilgi/çerez bandı atılıyor, <article>/<main> öncelikli,
 *    windows-1254 / ISO-8859-9 Türkçe doğru çevriliyor, JS ile oluşan sayfa tanınıyor.
 * C) Yönlendirme adresi çözümü (göreli, protokolsüz, mutlak).
 * D) TrainingController::readUrl yönlendirmeyi izliyor, tarayıcı başlıkları gönderiyor.
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

$lib = __DIR__ . '/../src/Shared/Utilities/WebPageText.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!class_exists('WebPageText')) {
    check('src/Shared/Utilities/WebPageText.php: WebPageText', false, 'tanımlı değil');
    finish();
}

echo "\n=== A) Hata sınıfları ===\n\n";
$c = fn (int $errno, string $err) => WebPageText::classifyCurlError($errno, $err, 15)['reason'];
check('cURL 6 (çözülemeyen alan adı) → dns', $c(6, 'Could not resolve host: yok.example') === 'dns');
check('cURL 28 → timeout', $c(28, 'Operation timed out after 15001 milliseconds') === 'timeout');
check('cURL 7 → connect', $c(7, 'Failed to connect') === 'connect');
check('cURL 60 / 35 → ssl', $c(60, 'SSL certificate problem') === 'ssl' && $c(35, 'SSL connect error') === 'ssl');
$m = WebPageText::classifyCurlError(28, 'x', 15)['message'];
check('zaman aşımı mesajı saniyeyi söylüyor', str_contains($m, '15'), $m);

$r = fn (int $s, array $h = [], string $b = '', string $ct = 'text/html') => WebPageText::classifyResponse($s, $h, $b, $ct)['reason'] ?? 'ok';
check('200 HTML → sorun yok', $r(200) === 'ok');
check('403 + cf-ray (Cloudflare) → blocked', $r(403, ['cf-ray' => 'abc', 'server' => 'cloudflare'], '<title>Just a moment...</title>') === 'blocked');
check('503 + "Checking your browser" → blocked', $r(503, [], '<title>Attention Required! | Cloudflare</title>Checking your browser') === 'blocked');
check('200 ama Cloudflare meydan okuma sayfası → blocked', $r(200, ['server' => 'cloudflare'], '<title>Just a moment...</title><div id="challenge-running">') === 'blocked');
check('403 (düz) → blocked', $r(403) === 'blocked');
check('429 → blocked (hız sınırı)', $r(429) === 'blocked');
check('401 → auth', $r(401) === 'auth');
check('404 / 410 → not_found', $r(404) === 'not_found' && $r(410) === 'not_found');
check('500 / 502 → server_error', $r(500) === 'server_error' && $r(502) === 'server_error');
check('application/pdf → pdf', $r(200, [], '%PDF-1.4', 'application/pdf') === 'pdf');
check('image/png → unsupported_type', $r(200, [], '', 'image/png') === 'unsupported_type');
check('application/xhtml+xml kabul', $r(200, [], '<html>', 'application/xhtml+xml; charset=utf-8') === 'ok');
check('text/plain kabul', $r(200, [], 'düz metin', 'text/plain; charset=utf-8') === 'ok');
foreach (['dns', 'timeout', 'connect', 'ssl', 'blocked', 'auth', 'not_found', 'server_error', 'pdf', 'unsupported_type', 'js_rendered', 'empty', 'too_large', 'too_many_redirects', 'private'] as $reason) {
    $msg = WebPageText::message($reason);
    check("mesaj ($reason) Türkçe ve genel 'sunucu hatası' değil", $msg !== '' && !preg_match('/sunucu hatas/iu', $msg), $msg);
}

echo "\n=== B) İçerik çıkarma ===\n\n";
$page = <<<'HTML'
<!doctype html><html><head><title>Kıyas Nedir? | Felsefe Sözlüğü</title></head><body>
<header><nav><a>Anasayfa</a><a>Kategoriler</a><a>Giriş Yap</a></nav></header>
<div class="cookie-consent">Bu site çerez kullanır. Kabul Et</div>
<aside class="sidebar">Popüler yazılar: Futbolun tarihi</aside>
<main><article><h1>Kıyas</h1><p>Kıyas, Aristoteles mantığında iki öncülden bir sonuç çıkaran akıl yürütmedir.</p>
<p>Örneğin: Bütün insanlar ölümlüdür. Sokrates insandır. O hâlde Sokrates ölümlüdür.</p></article></main>
<footer>© 2026 Sözlük · İletişim · Gizlilik</footer>
<script>var tracking = "gizli";</script></body></html>
HTML;
$x = WebPageText::extract($page);
check('ana içerik var', str_contains($x['text'], 'iki öncülden bir sonuç'), $x['text']);
check('menü atıldı', !str_contains($x['text'], 'Kategoriler'));
check('altbilgi atıldı', !str_contains($x['text'], 'Gizlilik'));
check('çerez bandı atıldı', !str_contains($x['text'], 'çerez kullanır'));
check('kenar çubuğu atıldı', !str_contains($x['text'], 'Futbolun tarihi'));
check('script atıldı', !str_contains($x['text'], 'tracking'));
// N-61 — canlı ölçüm (Hürriyet): script içindeki "</div>" dizgisi libxml'de
// script'i erken kapatıyor, kalan JS metne sızıyordu.
$leaky = '<html><body><script>var t = "<div>x</div>"; if (a) { userProfile = `x`; }</script><article><p>' . str_repeat('Haber metni burada. ', 20) . '</p></article></body></html>';
$lx = WebPageText::extract($leaky)['text'];
check('script içinde HTML dizgisi olsa da JS metne sızmıyor', !str_contains($lx, 'userProfile') && str_contains($lx, 'Haber metni'), $lx);
$wpt = (string) file_get_contents($lib);
check('script/style DOM ayrıştırmasından önce siliniyor (gerçek sayfada libxml sızıntısı, küçük örnekle üretilemedi)',
    (bool) preg_match('/preg_replace\(\'#<\(script\|style\)/', $wpt) && strpos($wpt, '#<(script|style)') < strpos($wpt, 'loadHTML('));
check('paragraflar ayrı satırda', str_contains($x['text'], "yürütmedir.\n"), json_encode($x['text'], JSON_UNESCAPED_UNICODE));
check('başlık', $x['title'] === 'Kıyas Nedir? | Felsefe Sözlüğü', $x['title']);

$plain = '<html><body><div class="menu-item">x</div><div id="content"><p>' . str_repeat('Uzun içerik cümlesi. ', 40) . '</p></div></body></html>';
check('<main>/<article> yoksa gövde (içerik korunuyor)', str_contains(WebPageText::extract($plain)['text'], 'Uzun içerik cümlesi.'));

$cp1254 = "<html><head><meta charset=\"windows-1254\"></head><body><p>" . mb_convert_encoding('Şişli, Çağlayan, Ğ ı İ ö ü', 'Windows-1254', 'UTF-8') . "</p></body></html>";
$cs = WebPageText::detectCharset('text/html', $cp1254);
check('meta charset windows-1254 tespit', strtolower($cs) === 'windows-1254', $cs);
check('windows-1254 → UTF-8 Türkçe doğru', str_contains(WebPageText::extract(WebPageText::toUtf8($cp1254, $cs))['text'], 'Şişli, Çağlayan, Ğ ı İ ö ü'));
$iso = mb_convert_encoding('Gümüşhane', 'ISO-8859-9', 'UTF-8');
check('başlıktaki charset (ISO-8859-9) meta\'dan önce', strtolower(WebPageText::detectCharset('text/html; charset=ISO-8859-9', '<meta charset="utf-8">')) === 'iso-8859-9');
check('ISO-8859-9 → UTF-8', WebPageText::toUtf8($iso, 'ISO-8859-9') === 'Gümüşhane');
check('charset yok + geçerli UTF-8 → olduğu gibi', WebPageText::toUtf8('Ağaç', WebPageText::detectCharset('text/html', '<p>Ağaç</p>')) === 'Ağaç');

$spa = '<!doctype html><html><head><title>Uygulama</title><script src="/static/js/main.4f2a.js"></script></head><body><noscript>You need to enable JavaScript to run this app.</noscript><div id="root"></div></body></html>';
$sx = WebPageText::extract($spa);
check('SPA (boş #root + script) → JS ile oluşuyor', WebPageText::looksJsRendered($spa, $sx['text']));
check('normal makale JS ile oluşuyor sayılmıyor', !WebPageText::looksJsRendered($page, $x['text']));
check('Next.js __next boş kabuk → JS', WebPageText::looksJsRendered('<div id="__next"></div><script src="/_next/static/chunks/main.js"></script>', ''));

echo "\n=== C) Yönlendirme adresi ===\n\n";
check('mutlak', WebPageText::resolveLocation('https://a.com/x/y', 'https://b.com/z') === 'https://b.com/z');
check('kök göreli', WebPageText::resolveLocation('https://a.com/x/y?q=1', '/z?k=2') === 'https://a.com/z?k=2');
check('göreli', WebPageText::resolveLocation('https://a.com/x/y', 'z') === 'https://a.com/x/z');
check('protokolsüz', WebPageText::resolveLocation('https://a.com/x', '//cdn.a.com/p') === 'https://cdn.a.com/p');
check('http→https', WebPageText::resolveLocation('http://a.com/', 'https://a.com/') === 'https://a.com/');
check('port korunuyor', WebPageText::resolveLocation('http://a.com:8080/x', '/y') === 'http://a.com:8080/y');
check('javascript: reddi', WebPageText::resolveLocation('https://a.com/', 'javascript:alert(1)') === null);

echo "\n=== D) readUrl uç noktası ===\n\n";
$ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/TrainingController.php');
check('bot UA yerine tarayıcı benzeri başlıklar', !str_contains($ctl, 'LumanorisBot/1.0') && str_contains($ctl, 'Accept-Language'));
check('sıkıştırma (gzip/br) açık', str_contains($ctl, 'CURLOPT_ENCODING'));
check('yönlendirme el ile izleniyor, her adımda IP doğrulaması (SSRF)', str_contains($ctl, 'MAX_REDIRECTS') && substr_count($ctl, 'resolvePublicIps(') >= 2);
check('hata yanıtı reason taşıyor', str_contains($ctl, "'reason'"));
// N-61 — yerel ölçüm: A+AAAA birlikte pinlenince IPv6 yolu olmayan makinede
// 20 sitenin 9'u 0 ms'de "connect" hatası verdi.
check('IPv4 varsa yalnızca IPv4 pinleniyor ve zorlanıyor', str_contains($ctl, 'CURL_IPRESOLVE_V4') && str_contains($ctl, 'FILTER_FLAG_IPV4'));
check('bilgi bankası sayacı karakter sayıyor (CHAR_LENGTH, bayt değil)', str_contains($ctl, 'CHAR_LENGTH(training_prompt) as total_length'));
check('sığmayan parça sessizce kesilmiyor, sınır ve mevcut uzunlukla reddediliyor',
    !str_contains($ctl, 'mb_substr($chunk, 0, $room)') && str_contains($ctl, "'reason' => 'char_limit'"));
check('genel "Sayfaya ulasilamadi" kalmadı', !str_contains($ctl, 'Sayfaya ulasilamadi'));

finish();
