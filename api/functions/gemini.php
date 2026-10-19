<?php
/**
 * Gemini çağrısının tek kaynağı: model, uç nokta, anahtarın kaynağı ve
 * hata teşhisi. Sohbet ucu (ChatController::generateReply) ve admin
 * "Sistem durumu" sayfası aynı fonksiyonları kullanır; böylece panelde
 * görülen sonuç, kullanıcının sohbetinde olanla aynı istektir.
 *
 * Anahtar hiçbir çıktıya yazılmaz: Google bazı hata gövdelerinde anahtarı
 * geri yansıtıyor ("Consumer 'api_key:…' has been suspended"), bu yüzden
 * log ve panel metni geminiErrorSummary()/geminiProbe() üzerinden maskelenir.
 */

require_once __DIR__ . '/env.php';

/**
 * N-48 — model ve düşünme seviyesi (ölçüm 2026-10-17, 5 mesaj, yerel):
 *   gemini-3-flash-preview (varsayılan düşünme)  ort. 8,9 sn · en yüksek 9,6
 *   gemini-3-flash-preview + minimal              ort. 8,6 sn · en yüksek 12,4
 *   gemini-3.8-flash + low                        ort. 4,1 sn · en yüksek 4,9
 *   gemini-3.5-flash-lite + low                   ort. 1,4 sn · en yüksek 1,6
 * 3-flash-preview'da düşünmeyi kısmak süreyi değiştirmedi; gecikme modelin
 * kendisinde. Kararlı (önizleme olmayan) en yeni Flash seçildi: yarı süre,
 * tam model kalitesi. Daha da hızlı gerekirse 'gemini-3.5-flash-lite' — eğitim
 * metni uzun botlarda cevap kalitesi düşebilir.
 */
const GEMINI_MODEL = 'gemini-3.8-flash';
const GEMINI_THINKING_LEVEL = 'low';

/**
 * Süre zinciri (N-48). Her halka bir sonrakinden önce biter, böylece
 * kullanıcı anlamlı bir hata görür ve sunucu her durumda kaydeder ya da iade eder:
 *   cURL bağlantı 5 sn, toplam 30 sn
 *   → PHP set_time_limit 45 sn (ignore_user_abort açık)
 *   → arayüz CHAT_REPLY_TIMEOUT_MS 50 sn (web/src/app/dashboard/chat/page.jsx)
 *   → Vercel harici rewrite: ilk bayta kadar 120 sn (ilk SSE karesi hemen gider)
 * gemini_selftest E bölümü bu sırayı kilitler.
 */
const GEMINI_CONNECT_TIMEOUT = 5;
const GEMINI_TIMEOUT = 30;
const CHAT_PHP_TIME_LIMIT = 45;

/** İstek gövdesine eklenen üretim ayarı (sohbet için düşük düşünme). */
function geminiGenerationConfig(): array
{
    return ['thinkingConfig' => ['thinkingLevel' => GEMINI_THINKING_LEVEL]];
}

/**
 * Coin kararı (N-48): yalnızca eksiksiz ve boş olmayan bir cevap kaydedilir
 * ve coin düşmüş kalır. Ağ hatası, cURL zaman aşımı (yarım metin dahil),
 * Google 4xx/5xx ya da 200 ile boş akış → iade.
 *
 * @return 'save'|'refund'
 */
function chatReplyOutcome(int $http, string $curlError, string $replyText): string
{
    if ($curlError !== '' || $http < 200 || $http >= 300) {
        return 'refund';
    }
    return trim($replyText) === '' ? 'refund' : 'save';
}

function geminiStreamUrl(string $apiKey): string
{
    return 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL
         . ':streamGenerateContent?alt=sse&key=' . urlencode($apiKey);
}

/**
 * Anahtarın hangi dosyadan okunduğu (AppConfig::googleGeminiApiKey ile aynı
 * sıra): önce api/.env (ortam), yoksa api/admin/.env. Değer döndürmez.
 */
function geminiKeySource(): string
{
    if ((string) ($_ENV['API_GOOGLE_GEMINI'] ?? getenv('API_GOOGLE_GEMINI') ?: '') !== '') {
        return 'api/.env (API_GOOGLE_GEMINI)';
    }
    $adminEnv = __DIR__ . '/../admin/.env';
    if (is_file($adminEnv)) {
        foreach (file($adminEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'API_GOOGLE_GEMINI=') && trim(substr($line, 18), " \t\"'") !== '') {
                return 'api/admin/.env (API_GOOGLE_GEMINI)';
            }
        }
    }
    return 'yok';
}

/** @return array{status: string, reason: string, message: string} */
function geminiParseError(string $body): array
{
    $j = json_decode($body, true);
    if (is_array($j) && isset($j[0]) && is_array($j[0])) {
        $j = $j[0]; // streamGenerateContent hatayı dizi içinde döndürüyor
    }
    $err = is_array($j) ? ($j['error'] ?? null) : null;
    if (!is_array($err)) {
        return ['status' => '', 'reason' => '', 'message' => mb_substr(trim(strip_tags($body)), 0, 200)];
    }
    $reason = '';
    foreach ((array) ($err['details'] ?? []) as $d) {
        if (is_array($d) && !empty($d['reason'])) {
            $reason = (string) $d['reason'];
            break;
        }
    }
    return [
        'status'  => (string) ($err['status'] ?? ''),
        'reason'  => $reason,
        'message' => mb_substr((string) ($err['message'] ?? ''), 0, 300),
    ];
}

function geminiMask(string $text, string $apiKey): string
{
    if ($apiKey === '') {
        return $text;
    }
    return str_replace([$apiKey, urlencode($apiKey), rawurlencode($apiKey)], '«gizli»', $text);
}

/** Tek satır, anahtarsız: `http=403 status=PERMISSION_DENIED reason=… message=…`. */
function geminiErrorSummary(int $http, string $curlError, string $body, string $apiKey): string
{
    $e = geminiParseError($body);
    $parts = ['http=' . $http, 'model=' . GEMINI_MODEL];
    if ($curlError !== '') $parts[] = 'curl=' . $curlError;
    if ($e['status'] !== '') $parts[] = 'status=' . $e['status'];
    if ($e['reason'] !== '') $parts[] = 'reason=' . $e['reason'];
    if ($e['message'] !== '') $parts[] = 'message=' . $e['message'];
    return geminiMask(implode(' ', $parts), $apiKey);
}

/**
 * Sohbetle aynı model ve uç noktaya çok kısa bir test isteği.
 *
 * @return array{ok: bool, http: int, model: string, status: string, reason: string, message: string, curl: string}
 */
function geminiProbe(string $apiKey, int $timeout = 15): array
{
    $out = ['ok' => false, 'http' => 0, 'model' => GEMINI_MODEL, 'status' => '', 'reason' => '', 'message' => '', 'curl' => ''];
    if ($apiKey === '') {
        $out['message'] = 'Anahtar tanımlı değil (API_GOOGLE_GEMINI).';
        return $out;
    }
    $ch = curl_init(geminiStreamUrl($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'contents'         => [['role' => 'user', 'parts' => [['text' => 'Yalnızca "tamam" yaz.']]]],
            'generationConfig' => geminiGenerationConfig(),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => GEMINI_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = (string) curl_exec($ch);
    $out['http'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $out['curl'] = geminiMask(curl_error($ch), $apiKey);
    curl_close($ch);

    $out['ok'] = $out['curl'] === '' && $out['http'] === 200;
    if (!$out['ok']) {
        $e = geminiParseError($body);
        $out['status']  = $e['status'];
        $out['reason']  = $e['reason'];
        $out['message'] = geminiMask($e['message'], $apiKey);
    }
    return $out;
}

// ── N-59: hata türü, tek otomatik yeniden deneme, son 24 saat sayımı ─────────
//
// Canlıda (BLOCKERS B4, 2026-10-18) Google art arda 503 UNAVAILABLE ("high
// demand") ve 429 RESOURCE_EXHAUSTED döndürdü; kullanıcı "Yapay zeka servisine
// şu anda ulaşılamıyor" gördü, "Tekrar Dene" çoğu zaman düzeltti. Geçici
// hatalarda (yoğunluk, 5xx, bağlantı) akış başlamadan tek bir kısa bekleme +
// yeniden deneme yapılıyor. Günlük kota (429 + PerDay / uzun retryDelay)
// yeniden denenmiyor: aynı gün içinde düzelmez, boşa istek olur.

/** İlk deneme bu süreden uzun sürdüyse yeniden deneme bütçesi yok (sn). */
const GEMINI_RETRY_MAX_ELAPSED = 10.0;
/** Google'ın önerdiği bekleme bundan uzunsa yeniden deneme yapılmaz (sn). */
const GEMINI_RETRY_MAX_DELAY = 3.0;
/** İkinci deneme en az bu kadar süre alabilmeli, yoksa denenmez (sn). */
const GEMINI_RETRY_MIN_TIMEOUT = 10;

/** Google RetryInfo.retryDelay ("2s", "1.5s") → saniye; yoksa null. */
function geminiRetryDelaySeconds(string $body): ?float
{
    if (preg_match('/"retryDelay"\s*:\s*"(\d+(?:\.\d+)?)s"/', $body, $m)) {
        return (float) $m[1];
    }
    return null;
}

/**
 * @return 'busy'|'quota'|'unavailable'|'timeout'|'network'|'config'|'empty'
 */
function geminiFailureKind(int $http, string $curlError, string $body): string
{
    if ($curlError !== '') {
        return preg_match('/timed out|timeout/i', $curlError) ? 'timeout' : 'network';
    }
    if ($http === 429) {
        $delay = geminiRetryDelaySeconds($body);
        $daily = (bool) preg_match('/PerDay/i', $body);
        return ($daily || ($delay !== null && $delay > GEMINI_RETRY_MAX_DELAY)) ? 'quota' : 'busy';
    }
    if ($http === 503) return 'busy';
    if ($http === 408) return 'timeout';
    if ($http >= 500) return 'unavailable';
    if ($http >= 400) return 'config';
    if ($http >= 200 && $http < 300) return 'empty';
    return 'network';
}

/**
 * Akış başlamadan gelen hatada tek yeniden deneme planı.
 *
 * @param float $elapsed  ilk denemenin sürdüğü saniye
 * @param bool  $streamed istemciye cevap metni gönderilmeye başlandı mı
 * @return array{retry: bool, delay_ms: int, timeout: int, kind: string}
 */
function geminiRetryPlan(int $http, string $curlError, string $body, float $elapsed, bool $streamed): array
{
    $kind = geminiFailureKind($http, $curlError, $body);
    $no   = ['retry' => false, 'delay_ms' => 0, 'timeout' => 0, 'kind' => $kind];
    if ($streamed || $elapsed > GEMINI_RETRY_MAX_ELAPSED) {
        return $no;
    }
    $delay = match ($kind) {
        'busy'        => geminiRetryDelaySeconds($body) ?? 1.0,
        'unavailable' => 1.0,
        'network'     => 0.5,
        default       => null,
    };
    if ($delay === null || $delay > GEMINI_RETRY_MAX_DELAY) {
        return $no;
    }
    $delay   = max(0.5, $delay);
    // İkinci deneme PHP süre sınırından en az 5 sn önce bitmeli (kaydet/iade için).
    $timeout = (int) min(GEMINI_TIMEOUT, floor(CHAT_PHP_TIME_LIMIT - 5 - $elapsed - $delay));
    if ($timeout < GEMINI_RETRY_MIN_TIMEOUT) {
        return $no;
    }
    return ['retry' => true, 'delay_ms' => (int) round($delay * 1000), 'timeout' => $timeout, 'kind' => $kind];
}

/**
 * Log satırlarından son 24 saatin Gemini hata sayımı (admin Sistem Durumu).
 * Yalnızca sayı ve zaman döner; mesaj metni (anahtar yansıtabilir) dönmez.
 *
 * @param iterable<string> $lines
 * @return array{total: int, by_kind: array<string,int>, recovered: int, last: ?string}
 */
function geminiErrorStats(iterable $lines, int $now): array
{
    $out = ['total' => 0, 'by_kind' => [], 'recovered' => 0, 'last' => null];
    $lastTs = 0;
    foreach ($lines as $line) {
        if (!str_contains($line, '[gemini]') || !preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            continue;
        }
        $ts = strtotime($m[1]);
        if ($ts === false || $ts > $now || $now - $ts > 86400) {
            continue;
        }
        if (str_contains($line, 'tekrar deneme başarılı')) {
            $out['recovered']++;
            continue;
        }
        if (!str_contains($line, 'hata:')) {
            continue;
        }
        if (preg_match('/tür=(\w+)/u', $line, $k)) {
            $kind = $k[1];
        } elseif (str_contains($line, 'boş cevap')) {
            $kind = 'empty';
        } elseif (str_contains($line, 'http=429') && str_contains($line, 'exceeded your current quota')) {
            // N-59 öncesi satırlarda tür yok; bu Google metni kota/faturalandırma
            // sorunudur (canlıda 75 sn beklemeden sonra da sürdü — BLOCKERS B4).
            $kind = 'quota';
        } else {
            $http = preg_match('/http=(\d+)/', $line, $h) ? (int) $h[1] : 0;
            $curl = preg_match('/curl=(.+?)(?: status=| reason=| message=|$)/', $line, $c) ? $c[1] : '';
            $kind = geminiFailureKind($http, $curl, '');
        }
        $out['total']++;
        $out['by_kind'][$kind] = ($out['by_kind'][$kind] ?? 0) + 1;
        if ($ts >= $lastTs) {
            $lastTs      = $ts;
            $out['last'] = $m[1];
        }
    }
    return $out;
}

/** Log dosyasının son $maxBytes baytı, satır satır (dosya büyük olabilir). */
function geminiLogTail(string $file, int $maxBytes = 4194304): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }
    $size = (int) filesize($file);
    $fh   = fopen($file, 'rb');
    if ($fh === false) {
        return [];
    }
    if ($size > $maxBytes) {
        fseek($fh, -$maxBytes, SEEK_END);
        fgets($fh); // yarım satırı at
    }
    $lines = [];
    while (($l = fgets($fh)) !== false) {
        $lines[] = rtrim($l, "\r\n");
    }
    fclose($fh);
    return $lines;
}
