<?php
class TrainingController {
    public static function updateTrainingChunk(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || !isset($data['id'], $data['textChunk'])) {
            JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);
        }

        $id = InputSanitizer::positiveInt($data['id']);

        // B-13 — bu uç noktada hiç hız sınırı yoktu; kardeşleri (readUrl,
        // readPdf) checkRateLimit kullanıyor. Bilgi bankası yüklemesi parça
        // parça geldiği için sınır cömert.
        $db = Database::getInstance();
        checkRateLimit($db, 'trainchunk:' . $userId, 240, 300);

        // Previously had no ownership check — anyone who knew a chatbot's id
        // could overwrite or append to its training prompt.
        if (!(new ChatbotRepository())->findByIdAndOwner($id, $userId)) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        // B-13 — `textChunk` hiç kırpılmıyordu ve CONCAT ile LONGTEXT'e
        // sınırsız ekleniyordu: tek istekle yüzlerce MB eğitim metni
        // yazılabiliyordu, üstelik okuma tarafı zaten 60.000 karakterden
        // fazlasını kullanmıyor. Hem parça hem TOPLAM uzunluk sınırlı.
        $chunk   = mb_substr((string) $data['textChunk'], 0, AppConfig::MAX_TRAINING_CHARS);
        $isFirst = (bool) ($data['isFirst'] ?? false);
        $conn    = $db->getConnection();

        if ($isFirst) {
            $stmt = $conn->prepare('UPDATE chatbotlar SET training_prompt = :chunk WHERE id = :id');
        } else {
            $current = (int) ($db->selectSingle(
                'CHAR_LENGTH(IFNULL(training_prompt, \'\')) AS len FROM chatbotlar WHERE id = ?',
                [$id]
            )['len'] ?? 0);

            // N-61 — sığmayan parça eskiden SESSİZCE kesiliyordu: kaynağın bir
            // kısmı eklenmiş, kullanıcı fark etmemiş oluyordu. Artık hiç
            // eklenmiyor; mesaj sınırı ve mevcut uzunluğu söylüyor.
            $room = AppConfig::MAX_TRAINING_CHARS - $current;
            $len  = mb_strlen($chunk);
            if ($len > $room) {
                $fmt = static fn (int $n): string => number_format($n, 0, ',', '.');
                JsonResponse::error(
                    sprintf(
                        'Karakter sınırı aşılıyor: bilgi bankasında %s / %s karakter var, kalan %s; eklenmek istenen parça %s karakter. Kaynağı kısaltın.',
                        $fmt($current), $fmt(AppConfig::MAX_TRAINING_CHARS), $fmt(max(0, $room)), $fmt($len)
                    ),
                    413,
                    AppConfig::ERR_LIMIT_REACHED,
                    ['reason' => 'char_limit', 'limit' => AppConfig::MAX_TRAINING_CHARS, 'current' => $current]
                );
            }
            $stmt  = $conn->prepare("UPDATE chatbotlar SET training_prompt = CONCAT(IFNULL(training_prompt, ''), :chunk) WHERE id = :id");
        }
        $stmt->bindParam(':chunk', $chunk);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            JsonResponse::success(['message' => 'Parça başarıyla eklendi.']);
        } else {
            JsonResponse::error('SQL hatası oluştu.', 500, AppConfig::ERR_SERVER);
        }
    }

    public static function getTrainingChunks(): void {
        // chat/page.jsx loads a bot's full training_prompt client-side for
        // every conversation, not just the bot's own owner — a logged-in
        // buyer or anyone previewing a published marketplace bot needs read
        // access here too. Previously this only checked "is there a
        // session at all", with no check that the session's user was
        // actually entitled to *this* botId — anyone logged in could scrape
        // any bot's full training corpus (private/independent bots
        // included) by looping botId. Authorization now goes through the
        // same policy as the chat page's own bot lookup (see
        // ChatbotRepository::userHasAccess) so both endpoints agree on who
        // may see a bot's private content.
        $userId = AuthMiddleware::requireAuth();
        $botId  = InputSanitizer::positiveInt($_GET['botId'] ?? 0);
        $offset = InputSanitizer::positiveInt($_GET['offset'] ?? 0);
        $limit  = 10000;

        if (!$botId) JsonResponse::error('Bot ID eksik', 400, AppConfig::ERR_VALIDATION);

        if (!(new ChatbotRepository())->userHasAccess($botId, $userId)) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        $conn  = Database::getInstance()->getConnection();
        // N-61 — LENGTH() bayt sayıyordu (Türkçe harf 2 bayt); arayüzdeki
        // "N / 60.000 karakter" sayacı ve sınır uyarısı şişkin çıkıyordu.
        // SUBSTRING zaten karakterle çalışıyor; sayaç da karakter.
        $stmt  = $conn->prepare('SELECT SUBSTRING(training_prompt, :start, :limit) as chunk, CHAR_LENGTH(training_prompt) as total_length FROM chatbotlar WHERE id = :id');
        $start = $offset + 1;
        $stmt->bindParam(':start', $start, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':id', $botId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $totalLength = (int) $result['total_length'];
            JsonResponse::success([
                'chunk'       => $result['chunk'] ?? '',
                'totalLength' => $totalLength,
                'hasMore'     => ($offset + $limit) < $totalLength,
            ]);
        } else {
            JsonResponse::error('Bot bulunamadı', 404, AppConfig::ERR_NOT_FOUND);
        }
    }

    // Bir web sayfasindan metin cikarma tavanlari.
    private const MAX_URL_BYTES   = 3 * 1024 * 1024;
    private const URL_TIMEOUT_SEC = 15;
    private const MAX_REDIRECTS   = 5;

    /** N-61 — bot kimliği ("LumanorisBot") birçok sitede (Cloudflare vb.) doğrudan engelleniyordu. */
    private const BROWSER_HEADERS = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.7,en;q=0.6',
        'Cache-Control: no-cache',
        'Upgrade-Insecure-Requests: 1',
    ];
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    /**
     * "Bilgi Bankasi > URL Ekle" icin sunucu tarafi sayfa cekimi.
     *
     * Tarayicidan dogrudan cekmek mumkun degil: hem CORS hem de
     * next.config.mjs'teki CSP (connect-src 'self') engelliyor. Sunucudan
     * cekmek ise SSRF acar — istemcinin verdigi adres ic aga bakabilir
     * (127.0.0.1, 169.254.169.254 metadata servisi, 10.x, 192.168.x ...).
     * Bu yuzden:
     *   1. Yalnizca http/https semasi,
     *   2. Host DNS ile cozulur ve TUM cozulen IP'ler ozel/loopback/
     *      link-local araliklara karsi kontrol edilir, CURLOPT_RESOLVE ile pinlenir,
     *   3. N-61: yonlendirme ELLE izlenir (en fazla MAX_REDIRECTS) ve HER
     *      adimda 2. madde yeniden uygulanir — eskiden yonlendirme kapaliydi,
     *      http→https / www yonlendiren siteler bos sayfa donuyordu,
     *   4. Boyut ve sure tavani, kullanici basina hiz siniri.
     *
     * N-61 — her hata turu ayri Turkce mesaj + `reason` kodu (WebPageText):
     * DNS, zaman asimi, baglanti, SSL, bot korumasi, giris gerekli, 404, 5xx,
     * PDF, desteklenmeyen tur, JavaScript ile olusan sayfa, bos icerik.
     */
    public static function readUrl(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        checkRateLimit(Database::getInstance(), 'readurl:' . $userId, 15, 300);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $url   = trim((string) ($input['url'] ?? ''));

        if ($url === '') {
            JsonResponse::error('URL gerekli.', 400, AppConfig::ERR_VALIDATION);
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url; // "ornek.com/sayfa" yazılabiliyor
        }

        $fail = static function (array $f, string $logUrl): void {
            error_log(sprintf('[readurl] %s url=%s', $f['reason'], $logUrl));
            JsonResponse::error($f['message'], $f['http'], AppConfig::ERR_VALIDATION, ['reason' => $f['reason']]);
        };
        $failReason = static fn (string $reason, int $http = 422, int|string ...$a): array
            => ['reason' => $reason, 'message' => WebPageText::message($reason, ...$a), 'http' => $http];

        for ($hop = 0; ; $hop++) {
            if ($hop > self::MAX_REDIRECTS) {
                $fail($failReason('too_many_redirects'), $url);
            }
            $parts  = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host   = (string) ($parts['host'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                JsonResponse::error('Yalnızca http/https adresleri desteklenir.', 400, AppConfig::ERR_VALIDATION, ['reason' => 'invalid_url']);
            }

            // B-06 — dogrulanan IP'ler CURLOPT_RESOLVE ile pinleniyor (DNS rebinding).
            $why = '';
            $ips = self::resolvePublicIps($host, $why);
            if ($ips === []) {
                $fail($failReason($why === 'private' ? 'private' : 'dns', 400), $url);
            }
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

            $body     = '';
            $overflow = false;
            $headers  = [];
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => self::URL_TIMEOUT_SEC,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_MAXFILESIZE    => self::MAX_URL_BYTES,
                CURLOPT_USERAGENT      => self::BROWSER_UA,
                CURLOPT_HTTPHEADER     => self::BROWSER_HEADERS,
                CURLOPT_ENCODING       => '', // gzip, deflate, br — cURL'ün desteklediği hepsi
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $headers = []; // 100-continue vb. ara yanıtlar
                    } elseif (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $headers[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
                // B-06b — chunked yanıtta da boyut tavanı.
                CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$overflow): int {
                    $body .= $chunk;
                    if (strlen($body) > self::MAX_URL_BYTES) {
                        $overflow = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
            ];
            if (filter_var($ips[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $options[CURLOPT_RESOLVE] = array_map(
                    static fn(string $ip): string => $host . ':' . $port . ':'
                        . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
                    $ips
                );
            }
            curl_setopt_array($ch, $options);
            $ok     = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($overflow) {
                $fail($failReason('too_large', 413, (int) (self::MAX_URL_BYTES / 1024 / 1024)), $url);
            }
            if ($ok === false) {
                error_log('[readurl] curl ' . $errno . ': ' . $err);
                $fail(WebPageText::classifyCurlError($errno, $err, self::URL_TIMEOUT_SEC), $url);
            }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $next = WebPageText::resolveLocation($url, (string) ($headers['location'] ?? ''));
                if ($next === null) {
                    $fail($failReason('http_error', 422, $status), $url);
                }
                $url = $next;
                continue;
            }
            break;
        }

        $problem = WebPageText::classifyResponse($status, $headers, $body, $ctype);
        if ($problem !== null) {
            $fail($problem, $url);
        }

        $html   = WebPageText::toUtf8($body, WebPageText::detectCharset($ctype, $body));
        $isHtml = $ctype === '' || stripos($ctype, 'html') !== false || preg_match('/<html|<body|<!doctype/i', substr($html, 0, 2000));
        $page   = $isHtml ? WebPageText::extract($html) : ['title' => '', 'text' => trim($html)];

        if ($isHtml && WebPageText::looksJsRendered($html, $page['text'])) {
            $fail($failReason('js_rendered'), $url);
        }
        if (mb_strlen($page['text']) < 40) {
            $fail($failReason('empty'), $url);
        }

        JsonResponse::success(['text' => $page['text'], 'title' => $page['title'], 'url' => $url, 'chars' => mb_strlen($page['text'])]);
    }

    /**
     * Host'un cozuldugu TUM IP'ler halka acik mi? Oyleyse o IP'leri dondurur,
     * degilse bos dizi (fail-closed). N-61: $reason = 'dns' (cozulemedi) |
     * 'private' (ic ag) — kullaniciya ayri mesaj.
     *
     * B-06: cagiran bu IP'leri CURLOPT_RESOLVE ile pinliyor — aksi hâlde curl
     * kendi ikinci cozumlemesini yapar ve bu iki cozumleme arasindaki fark
     * SSRF korumasini tamamen atlatir.
     */
    private static function resolvePublicIps(string $host, string &$reason = ''): array {
        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip']))   $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
            if ($ips === []) {
                $resolved = gethostbyname($host);
                if ($resolved !== $host) $ips[] = $resolved;
            }
        }

        if ($ips === []) {
            $reason = 'dns';
            return []; // cozulemeyen host: fail-closed
        }

        foreach ($ips as $ip) {
            $ok = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($ok === false) {
                $reason = 'private';
                return []; // tek bir ozel IP bile yeterli sebep
            }
        }
        // N-61 — IPv4 varsa YALNIZCA IPv4 pinlenir (cagiran CURL_IPRESOLVE_V4
        // ile zorlar). Eskiden A + AAAA birlikte pinleniyordu; IPv6 yolu olmayan
        // sunucuda cURL IPv6 adresini secince baglanti 0 ms'de dusuyordu —
        // musteri sikayeti "bazi sitelere ulasilamiyor" (yerel olcumde 20 sitenin 9'u).
        // Iç ag kontrolu yukarida TUM adresler icin yapildi.
        $ips = array_values(array_unique($ips));
        $v4  = array_values(array_filter($ips, static fn(string $ip): bool => (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        return $v4 !== [] ? $v4 : $ips;
    }

    // Belge boyut tavanı: DocumentText::MAX_BYTES (5 MB). Geçmiş:
    // eskiden oturumsuz ve sınırsızdı (DoS); 2026-10-05 (S21) 15 MB → 5 MB,
    // çünkü base64 ~%33 büyütüyor ve post_max_size=8M'de fiili sınır ~6 MB'tı
    // (L-01). Arayüzdeki KB_MAX_PDF_MB bununla ELLE senkron; artırılacaksa
    // sunucu post_max_size'ı ile birlikte.

    /**
     * N-62 — PDF, DOCX ve TXT'den metin (Bilgi Bankası → Belge yükle).
     * İstek: {base64Data, filename}. Hata: Türkçe mesaj + `reason`
     * (too_large / unsupported / doc_legacy / encrypted / scanned / corrupt / empty).
     */
    public static function readDocument(): void {
        self::handleDocumentUpload('belge');
    }

    /**
     * Geriye dönük: eski istemciler (açık kalmış sekme) yalnızca PDF ve
     * filename'siz gönderiyor. Aynı işleyici; tür imzadan belirleniyor.
     */
    public static function readPdf(): void {
        self::handleDocumentUpload('belge.pdf');
    }

    private static function handleDocumentUpload(string $defaultName): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        // Belge ayrıştırma CPU/disk ağır; anahtar eski uçla ortak (aynı kota).
        checkRateLimit(Database::getInstance(), 'readpdf:' . $userId, 10, 300);
        // N-56 — vendor eksikse ham PHP hatası yerine anlaşılır 503.
        VendorAutoload::requireOrFail(
            'readpdf',
            VendorAutoload::PDF_CLASSES,
            'Belge okuma bileşeni sunucuda şu an kullanılamıyor. Sorun kayda geçti; '
            . 'lütfen daha sonra tekrar deneyin ya da içeriği "Metin ekle" ile yapıştırın.'
        );

        $raw      = file_get_contents('php://input');
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        // Gövde post_max_size'ı aşarsa PHP onu ATAR: php://input boş gelir ama
        // Content-Length yerinde durur. Mesaj sunucunun GERÇEK sınırını söylüyor.
        if ($raw === '' && $declared > 0) {
            $limit = (string) ini_get('post_max_size');
            error_log("[readdocument] istek gövdesi alınamadı: content_length={$declared}, post_max_size={$limit}");
            JsonResponse::error(
                "Dosya, sunucunun kabul ettiği istek boyutunu aşıyor (sunucu sınırı: {$limit}). "
                . 'Yükleme sırasında dosya yaklaşık üçte bir büyüdüğü için sığabilecek dosya bundan küçüktür. '
                . 'Daha küçük bir dosya deneyin.',
                413,
                AppConfig::ERR_VALIDATION,
                ['reason' => 'too_large']
            );
        }

        $input = json_decode($raw, true);
        if (!isset($input['base64Data'])) {
            error_log('[readdocument] base64Data alanı yok. gövde uzunluğu=' . strlen($raw));
            JsonResponse::error('Dosya verisi eksik.', 400, AppConfig::ERR_VALIDATION);
        }
        $bytes = base64_decode((string) $input['base64Data'], true);
        if ($bytes === false) {
            JsonResponse::error('Dosya verisi okunamadı (geçersiz kodlama).', 400, AppConfig::ERR_VALIDATION, ['reason' => 'corrupt']);
        }
        $name = basename(str_replace('\\', '/', (string) ($input['filename'] ?? ''))) ?: $defaultName;

        $r = DocumentText::read($name, $bytes);
        if (!$r['ok']) {
            error_log(sprintf('[readdocument] %s tür=%s ad=%s boyut=%d', $r['reason'], $r['type'] ?: '-', $name, strlen($bytes)));
            $status = match ($r['reason']) {
                'too_large'               => 413,
                'unsupported', 'doc_legacy' => 415,
                default                   => 422,
            };
            JsonResponse::error($r['message'], $status, AppConfig::ERR_VALIDATION, ['reason' => $r['reason']]);
        }

        JsonResponse::success(['text' => $r['text'], 'type' => $r['type'], 'chars' => mb_strlen($r['text'])]);
    }
}
