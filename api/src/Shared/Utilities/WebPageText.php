<?php
/**
 * Web sayfasından bilgi bankası metni — N-61 (Madde 3).
 *
 * Saf yardımcılar (ağ yok): hata sınıflaması, karakter kodlaması, ana içerik
 * çıkarma, JavaScript ile oluşan sayfa tespiti, yönlendirme adresi çözümü.
 * Ağ isteği TrainingController::readUrl'de; bu sınıf readurl_selftest ile
 * sabit örneklerle sınanıyor.
 */
final class WebPageText
{
    /** Bundan kısa metin "içerik yok" sayılır. */
    public const MIN_TEXT_CHARS = 200;

    /** Kullanıcıya gösterilen Türkçe mesajlar (hata türü → metin). */
    private const MESSAGES = [
        'dns'                => 'Siteye ulaşılamadı: alan adı bulunamadı. Adresi kontrol edin.',
        'timeout'            => 'Siteye ulaşılamadı: site %d saniye içinde yanıt vermedi. Biraz sonra tekrar deneyin.',
        'connect'            => 'Siteye ulaşılamadı: sunucu bağlantıyı kabul etmedi.',
        'ssl'                => 'Siteye güvenli bağlantı kurulamadı (SSL sertifikası geçersiz ya da eski).',
        'private'            => 'Bu adres taranamaz (iç ağ adresleri engellidir).',
        'blocked'            => 'Site otomatik erişimi engelledi (bot koruması). Sayfadaki metni kopyalayıp "Metin ekle" ile ekleyebilirsiniz.',
        'auth'               => 'Bu sayfa giriş yapmayı gerektiriyor; içeriği okunamadı.',
        'not_found'          => 'Sayfa bulunamadı (404). Adresi kontrol edin.',
        'server_error'       => 'Site şu anda hata veriyor (%d). Biraz sonra tekrar deneyin.',
        'http_error'         => 'Site isteği kabul etmedi (HTTP %d).',
        'pdf'                => 'Bu bağlantı bir PDF dosyası. Dosyayı indirip "Belge yükle" ile ekleyin.',
        'unsupported_type'   => 'Bu bağlantı bir web sayfası değil (%s); desteklenmeyen içerik türü.',
        'js_rendered'        => 'Sayfa içeriği tarayıcıda JavaScript ile oluşturuluyor ve okunamadı. Sayfadaki metni kopyalayıp "Metin ekle" ile ekleyebilirsiniz.',
        'empty'              => 'Sayfada okunabilir metin bulunamadı.',
        'too_large'          => 'Sayfa çok büyük (en fazla %d MB).',
        'too_many_redirects' => 'Sayfa çok fazla yönlendirme yaptı; içerik okunamadı.',
    ];

    public static function message(string $reason, int|string ...$args): string
    {
        $tpl = self::MESSAGES[$reason] ?? 'Sayfa okunamadı.';
        if ($args === [] && str_contains($tpl, '%')) {
            $args = [str_contains($tpl, '%s') ? 'bilinmeyen tür' : 0];
        }
        return $args === [] ? $tpl : vsprintf($tpl, $args);
    }

    /** @return array{reason: string, message: string, http: int} */
    public static function classifyCurlError(int $errno, string $error, int $timeoutSec): array
    {
        $reason = match (true) {
            $errno === 6 || stripos($error, 'resolve host') !== false             => 'dns',
            $errno === 28 || stripos($error, 'timed out') !== false              => 'timeout',
            in_array($errno, [35, 51, 53, 54, 58, 59, 60, 77, 80, 83, 90, 91], true)
                || stripos($error, 'SSL') !== false                               => 'ssl',
            default                                                               => 'connect',
        };
        $msg = $reason === 'timeout' ? self::message('timeout', $timeoutSec) : self::message($reason);
        return ['reason' => $reason, 'message' => $msg, 'http' => 502];
    }

    /**
     * @param array<string,string> $headers küçük harfli başlık adları
     * @return array{reason: string, message: string, http: int}|null sorun yoksa null
     */
    public static function classifyResponse(int $status, array $headers, string $body, string $contentType): ?array
    {
        $out = static fn (string $r, int $http, int|string ...$a): array => ['reason' => $r, 'message' => self::message($r, ...$a), 'http' => $http];

        $head = mb_substr($body, 0, 20000);
        $challenge = (bool) preg_match('/Just a moment\.\.\.|Attention Required!|Checking your browser|challenge-running|cf-chl-|captcha-delivery|Access denied \| /i', $head);
        $cloud = isset($headers['cf-ray']) || stripos($headers['server'] ?? '', 'cloudflare') !== false;

        if ($challenge || in_array($status, [403, 429], true) || ($status === 503 && $cloud)) {
            return $out('blocked', 422);
        }
        if ($status === 401 || $status === 407) return $out('auth', 422);
        if ($status === 404 || $status === 410) return $out('not_found', 422);
        if ($status >= 500)                     return $out('server_error', 502, $status);
        if ($status >= 400)                     return $out('http_error', 422, $status);

        $ct = strtolower(trim(explode(';', $contentType)[0]));
        if ($ct === 'application/pdf' || str_starts_with($body, '%PDF-')) {
            return $out('pdf', 415);
        }
        if ($ct !== '' && !str_contains($ct, 'html') && !str_starts_with($ct, 'text/')) {
            return $out('unsupported_type', 415, $ct);
        }
        return null;
    }

    /** Content-Type başlığı > <meta charset> > <meta http-equiv> > UTF-8 geçerliyse UTF-8 > windows-1254. */
    public static function detectCharset(string $contentType, string $html): string
    {
        if (preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $contentType, $m)) {
            return strtolower($m[1]);
        }
        $head = substr($html, 0, 4096);
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', $head, $m)) {
            return strtolower($m[1]);
        }
        return mb_check_encoding($html, 'UTF-8') ? 'utf-8' : 'windows-1254';
    }

    public static function toUtf8(string $body, string $charset): string
    {
        $cs = strtolower($charset);
        if (in_array($cs, ['utf-8', 'utf8', ''], true)) {
            return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }
        $aliases = ['iso-8859-9' => 'ISO-8859-9', 'latin5' => 'ISO-8859-9', 'windows-1254' => 'WINDOWS-1254',
                    'cp1254' => 'WINDOWS-1254', 'windows-1252' => 'WINDOWS-1252', 'iso-8859-1' => 'ISO-8859-1'];
        $from = $aliases[$cs] ?? strtoupper($cs);
        $conv = @iconv($from, 'UTF-8//IGNORE', $body);
        return $conv !== false && $conv !== '' ? $conv : mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    }

    /** @return array{title: string, text: string} */
    public static function extract(string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            return ['title' => '', 'text' => self::regexText($html)];
        }
        // Metin toUtf8() ile çevrildi; sayfadaki eski <meta charset> kalırsa
        // libxml onu yeniden uygular ve gövde boş/bozuk okunur.
        $html = preg_replace('/(<meta[^>]+charset\s*=\s*["\']?)[\w.:-]+/i', '${1}utf-8', $html) ?? $html;
        // Script/style DOM'dan ÖNCE, tarayıcı kuralıyla (ilk </script>) siliniyor:
        // canlı ölçümde (Hürriyet ana sayfası) libxml HTML'li şablon dizgileri
        // içeren bir script'i yanlış kapattı ve JS kodu metne sızdı (3 yer);
        // önceden silince 0. Küçük örnekle yeniden üretilemedi — sayfa bağlamına bağlı.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $title = '';
        $og = $xp->query('//meta[@property="og:title"]/@content')->item(0);
        $t  = $xp->query('//title')->item(0);
        $title = trim($t?->textContent ?? '') ?: trim($og?->nodeValue ?? '');

        // Gürültü: etiketler, roller ve sık kullanılan sınıf/kimlik adları.
        $noise = '//script|//style|//noscript|//svg|//iframe|//template|//form|//button|//nav|//header|//footer|//aside'
            . '|//*[@role="navigation" or @role="banner" or @role="contentinfo" or @role="complementary" or @aria-hidden="true"]';
        foreach (iterator_to_array($xp->query($noise)) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $tokens = 'cookie|consent|gdpr|kvkk|navbar|nav-|menu|breadcrumb|sidebar|footer|header|share|social|related|advert|ads-|banner|popup|modal|newsletter|subscribe|comment';
        foreach (iterator_to_array($xp->query('//body//*[@class or @id]')) as $n) {
            if (!$n instanceof DOMElement || in_array(strtolower($n->tagName), ['main', 'article', 'body'], true)) {
                continue;
            }
            $attr = strtolower($n->getAttribute('class') . ' ' . $n->getAttribute('id'));
            if (preg_match('/(?:^|[\s_-])(' . $tokens . ')(?:$|[\s_-])/', $attr)) {
                $n->parentNode?->removeChild($n);
            }
        }

        $body = $xp->query('//body')->item(0) ?? $doc->documentElement;
        $main = $xp->query('//article|//main|//*[@role="main"]');
        $root = $body;
        if ($main->length > 0 && $body !== null) {
            $best = null;
            foreach ($main as $m) {
                if ($best === null || mb_strlen($m->textContent) > mb_strlen($best->textContent)) {
                    $best = $m;
                }
            }
            $bodyLen = mb_strlen(trim($body->textContent));
            $bestLen = mb_strlen(trim($best->textContent));
            if ($bestLen >= 300 || ($bodyLen > 0 && $bestLen / $bodyLen >= 0.4)) {
                $root = $best;
            }
        }
        return ['title' => $title, 'text' => $root ? self::nodeText($root) : ''];
    }

    /** Blok öğeleri satır sonuyla ayırarak düz metin. */
    private static function nodeText(DOMNode $node): string
    {
        $block = ['p', 'div', 'section', 'article', 'main', 'li', 'ul', 'ol', 'tr', 'table', 'br', 'blockquote', 'pre',
                  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dd', 'dt', 'figcaption', 'hr'];
        $out = '';
        $walk = static function (DOMNode $n) use (&$walk, &$out, $block): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof DOMText) {
                    $out .= $c->nodeValue;
                } elseif ($c instanceof DOMElement) {
                    $isBlock = in_array(strtolower($c->tagName), $block, true);
                    if ($isBlock) $out .= "\n";
                    $walk($c);
                    if ($isBlock) $out .= "\n";
                }
            }
        };
        $walk($node);
        return self::normalize($out);
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function regexText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|head|nav|header|footer|aside)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</?(p|div|li|h[1-6]|br|tr|section|article)\b[^>]*>#i', "\n", $html) ?? $html;
        return self::normalize(strip_tags($html));
    }

    /** İçerik çok kısa ve sayfa bir uygulama kabuğu (boş kök + script) ise true. */
    public static function looksJsRendered(string $html, string $text): bool
    {
        if (mb_strlen($text) >= self::MIN_TEXT_CHARS) {
            return false;
        }
        $shell = (bool) preg_match('/<div[^>]+id=["\'](root|app|__next|__nuxt|svelte|main-app)["\'][^>]*>\s*<\/div>/i', $html);
        $noscriptJs = (bool) preg_match('/<noscript[^>]*>[^<]*(enable JavaScript|JavaScript\'i etkinle)/i', $html);
        $scripts = preg_match_all('/<script\b[^>]*\bsrc=/i', $html);
        return $shell || $noscriptJs || $scripts >= 3;
    }

    /** Location başlığını mevcut adrese göre çözer; yalnızca http(s). */
    public static function resolveLocation(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') return null;
        $b = parse_url($base);
        if (!is_array($b) || empty($b['host'])) return null;
        $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            $abs = $location;
        } elseif (str_starts_with($location, '//')) {
            $abs = ($b['scheme'] ?? 'https') . ':' . $location;
        } elseif (str_starts_with($location, '/')) {
            $abs = $origin . $location;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/') ?: '/';
            $abs = $origin . $dir . $location;
        }
        $scheme = strtolower((string) parse_url($abs, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $abs : null;
    }
}
