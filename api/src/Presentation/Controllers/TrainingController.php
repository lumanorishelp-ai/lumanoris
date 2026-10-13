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

            $room = AppConfig::MAX_TRAINING_CHARS - $current;
            if ($room <= 0) {
                JsonResponse::error(
                    sprintf('Eğitim metni üst sınırına ulaşıldı (%d karakter).', AppConfig::MAX_TRAINING_CHARS),
                    413,
                    AppConfig::ERR_LIMIT_REACHED
                );
            }
            $chunk = mb_substr($chunk, 0, $room);
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
        $stmt  = $conn->prepare('SELECT SUBSTRING(training_prompt, :start, :limit) as chunk, LENGTH(training_prompt) as total_length FROM chatbotlar WHERE id = :id');
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
    private const URL_TIMEOUT_SEC = 12;

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
     *      link-local araliklara karsi kontrol edilir,
     *   3. Yonlendirme kapali (redirect ile ic aga sicramasin),
     *   4. Boyut ve sure tavani, kullanici basina hiz siniri.
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

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            JsonResponse::error('Yalnizca http/https adresleri desteklenir.', 400, AppConfig::ERR_VALIDATION);
        }
        // B-06 — dogrulanan IP'ler geri aliniyor; asagida CURLOPT_RESOLVE ile
        // pinleniyor. Eskiden hostIsPublic() yalnizca bool donuyordu ve
        // curl_init($url) host'u IKINCI KEZ, bagimsiz olarak cozuyordu:
        // aradaki pencerede kisa TTL'li bir kayit farkli (ic ag) cevap
        // verebiliyordu — klasik DNS rebinding.
        $ips = self::resolvePublicIps($host);
        if ($ips === []) {
            JsonResponse::error('Bu adres taranamaz (ic ag adresleri engellidir).', 400, AppConfig::ERR_VALIDATION);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::URL_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_MAXFILESIZE    => self::MAX_URL_BYTES,
            CURLOPT_USERAGENT      => 'LumanorisBot/1.0 (+knowledge-base fetch)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        // Host zaten bir IP ise cozumleme yok, pinlemeye de gerek yok.
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $options[CURLOPT_RESOLVE] = array_map(
                static fn(string $ip): string => $host . ':' . $port . ':'
                    . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
                $ips
            );
        }

        // B-06b — CURLOPT_MAXFILESIZE yalnizca Content-Length varsa devreye
        // giriyor; chunked yanitta hicbir sey yapmiyordu ve boyut kontrolu
        // indirme BITTIKTEN sonra yapiliyordu (yani 3 MB tavani asan bir
        // sunucu bellegi istedigi kadar doldurabiliyordu). Yazma geri
        // cagirimi tavani asinca 0 dondurup aktarimi kesiyor.
        $body     = '';
        $overflow = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$body, &$overflow): int {
            $body .= $chunk;
            if (strlen($body) > self::MAX_URL_BYTES) {
                $overflow = true;
                return 0; // curl aktarimi CURLE_WRITE_ERROR ile durdurur
            }
            return strlen($chunk);
        };

        curl_setopt_array($ch, $options);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($overflow) {
            JsonResponse::error('Sayfa cok buyuk (maks. 3MB).', 413, AppConfig::ERR_VALIDATION);
        }
        if ($ok === false) {
            error_log('[readurl] curl: ' . $err);
            JsonResponse::error('Sayfaya ulasilamadi.', 502, AppConfig::ERR_SERVER);
        }
        if ($status >= 400) {
            JsonResponse::error('Sayfa ' . $status . ' dondurdu.', 502, AppConfig::ERR_SERVER);
        }
        if ($ctype !== '' && stripos($ctype, 'html') === false && stripos($ctype, 'text/plain') === false) {
            JsonResponse::error('Bu adres bir web sayfasi degil (' . $ctype . ').', 400, AppConfig::ERR_VALIDATION);
        }
        if (strlen($body) > self::MAX_URL_BYTES) {
            JsonResponse::error('Sayfa cok buyuk (maks. 3MB).', 413, AppConfig::ERR_VALIDATION);
        }

        JsonResponse::success(['text' => self::htmlToText($body), 'url' => $url]);
    }

    /**
     * Host'un cozuldugu TUM IP'ler halka acik mi? Oyleyse o IP'leri dondurur,
     * degilse bos dizi (fail-closed).
     *
     * B-06: cagiran bu IP'leri CURLOPT_RESOLVE ile pinliyor — aksi hâlde curl
     * kendi ikinci cozumlemesini yapar ve bu iki cozumleme arasindaki fark
     * SSRF korumasini tamamen atlatir.
     */
    private static function resolvePublicIps(string $host): array {
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
            return []; // cozulemeyen host: fail-closed
        }

        foreach ($ips as $ip) {
            $ok = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($ok === false) {
                return []; // tek bir ozel IP bile yeterli sebep
            }
        }
        return array_values(array_unique($ips));
    }

    /** Kaba ama yeterli HTML -> duz metin. */
    private static function htmlToText(string $html): string {
        $html = preg_replace('#<(script|style|noscript|svg|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    // Cap on the decoded PDF size this endpoint will parse. Was previously
    // reachable with no session and no size limit at all — anyone on the
    // internet could submit arbitrarily large/malformed PDFs and burn server
    // CPU/memory in Smalot\PdfParser (DoS).
    //
    // 2026-10-05 (S21 kararı): 15 MB → 5 MB. 15 MB arayüzde vaat ediliyordu
    // ama base64 ile ~20 MB istek gövdesi demekti; varsayılan
    // post_max_size=8M'de fiili sınır ~6 MB'tı (L-01). 5 MB ≈ 6,7 MB gövde,
    // 8M'nin altında kalıyor. Arayüzdeki sınır (create/page.jsx →
    // KB_MAX_PDF_BYTES) bununla ELLE senkron. Artırılacaksa sunucu
    // post_max_size'ı ile birlikte.
    private const MAX_PDF_BYTES = 5 * 1024 * 1024;

    /**
     * PDF metnini çıkarır.
     *
     * Bu uçta HİÇBİR loglama yoktu: canlıda başarısız olduğunda geriye
     * yalnızca istemcideki genel mesaj kalıyor, nedenini öğrenmenin bir yolu
     * bulunmuyordu. Aşağıdaki her başarısızlık dalı artık gerçek nedeni
     * error log'a yazıyor ve istemciye birbirinden AYIRT EDİLEBİLİR bir mesaj
     * dönüyor — "çalışmıyor" ile "şu yüzden çalışmıyor" arasındaki fark.
     */
    public static function readPdf(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        // PDF parsing is CPU- and disk-heavy; it had no ceiling at all.
        checkRateLimit(Database::getInstance(), 'readpdf:' . $userId, 10, 300);
        require_once __DIR__ . '/../../../vendor/autoload.php';

        // Bağımlılık gerçekten kurulu mu? `vendor/` sürüm kontrolünde DEĞİL
        // (.gitignore), yani dağıtımda `composer install` çalışmadıysa ya da
        // eski bir vendor kopyası taşındıysa autoload dosyası VAR ama sınıf
        // YOKTUR. O hâlde `new Parser()` bir \Error fırlatır; aşağıdaki eski
        // `catch (\Exception)` bunu yakalamıyordu, istek gerekçesi hiçbir
        // yere yazılmadan 500'e düşüyordu.
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            error_log('[readpdf] smalot/pdfparser kurulu değil — api/ dizininde "composer install" gerekiyor.');
            JsonResponse::error(
                'PDF okuma bileşeni sunucuda kurulu değil. Lütfen yöneticiye bildirin.',
                503,
                AppConfig::ERR_UNAVAILABLE
            );
        }

        $raw      = file_get_contents('php://input');
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        // Gövde sunucunun kabul ettiğinden büyükse PHP onu ATAR: php://input
        // boş gelir ama Content-Length yerinde durur. Eskiden bu durum
        // "base64Data eksik." diye raporlanıyordu — kullanıcı arayüzdeki
        // "maks. 15 MB" yazısını okuduktan sonra bu mesajı görünce hatayı
        // kendi dosyasında arıyordu. Asıl sınır şu: base64 veriyi ~%33
        // büyüttüğü için 15 MB'lık bir PDF ~20 MB'lık istek gövdesi demek;
        // post_max_size varsayılanı 8M olan bir sunucuda bu asla geçmez.
        // Mesaj artık sunucunun GERÇEK sınırını söylüyor.
        if ($raw === '' && $declared > 0) {
            $limit = (string) ini_get('post_max_size');
            error_log("[readpdf] istek gövdesi alınamadı: content_length={$declared}, post_max_size={$limit}");
            JsonResponse::error(
                "PDF, sunucunun kabul ettiği istek boyutunu aşıyor (sunucu sınırı: {$limit}). "
                . 'Base64 kodlama dosyayı yaklaşık üçte bir büyüttüğü için sığabilecek PDF bundan '
                . 'küçüktür. Daha küçük bir PDF deneyin.',
                413,
                AppConfig::ERR_VALIDATION
            );
        }

        $input = json_decode($raw, true);
        if (!isset($input['base64Data'])) {
            error_log('[readpdf] base64Data alanı yok. gövde uzunluğu=' . strlen($raw));
            JsonResponse::error('base64Data eksik.', 400, AppConfig::ERR_VALIDATION);
        }

        $pdfBytes = base64_decode($input['base64Data'], true);
        if ($pdfBytes === false) {
            JsonResponse::error('Geçersiz base64 verisi.', 400, AppConfig::ERR_VALIDATION);
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            JsonResponse::error(
                sprintf('PDF dosyası çok büyük (maks. %d MB).', self::MAX_PDF_BYTES / 1024 / 1024),
                413,
                AppConfig::ERR_VALIDATION
            );
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'pdf');
        if ($tmpFile === false) {
            error_log('[readpdf] geçici dosya oluşturulamadı. sys_get_temp_dir=' . sys_get_temp_dir());
            JsonResponse::error('Sunucuda geçici dosya oluşturulamadı.', 500, AppConfig::ERR_UNAVAILABLE);
        }

        // JsonResponse `exit` çağırdığı için `finally` ÇALIŞMAZ; geçici dosya
        // bu yüzden yanıt üretilmeden önce, tek bir yerde siliniyor. Eski
        // kodda \Error yoluyla çıkışta dosya diskte kalıyordu.
        $text  = null;
        $error = null;
        try {
            if (file_put_contents($tmpFile, $pdfBytes) === false) {
                throw new \RuntimeException('geçici dosyaya yazılamadı: ' . $tmpFile);
            }
            $text = (new \Smalot\PdfParser\Parser())->parseFile($tmpFile)->getText();
        } catch (\Throwable $e) {
            // \Exception değil \Throwable: kütüphane bozuk girdide \Error de
            // fırlatabiliyor ve o hâlde eski kod 500'e düşüyordu.
            $error = $e;
        }
        @unlink($tmpFile);

        if ($error !== null) {
            error_log('[readpdf] ' . get_class($error) . ': ' . $error->getMessage());
            JsonResponse::error(
                'PDF ayrıştırılamadı. Dosya bozuk olabilir ya da yalnızca taranmış görüntü içeriyor olabilir.',
                400,
                AppConfig::ERR_VALIDATION
            );
        }

        JsonResponse::success(['text' => $text]);
    }
}
