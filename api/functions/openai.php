<?php
/**
 * OpenAI çağrılarının tek kaynağı: sohbet (Responses API, SSE akışı),
 * embedding, hata türü, yeniden deneme planı ve kullanım kaydı.
 *
 * Model adları koda gömülü değil (.env): OPENAI_CHAT_MODEL,
 * OPENAI_EMBEDDING_MODEL. Anahtar (OPENAI_API_KEY) hiçbir çıktıya yazılmaz;
 * log metinleri openaiMask() üzerinden geçer.
 *
 * Hata türleri Gemini ile aynı sözlüğü kullanır (busy/quota/unavailable/
 * timeout/network/config/empty) — arayüzdeki Türkçe metinler
 * (web/src/features/chat/reply-errors.mjs) bu türlere bağlı.
 */

require_once __DIR__ . '/env.php';

const OPENAI_BASE_URL = 'https://api.openai.com/v1';

/**
 * İstek adresi. Varsayılan OpenAI; OPENAI_BASE_URL yalnızca staging/yerel
 * testte sahte sunucuya (mock) yönlendirmek için. Canlı .env'de tanımlanmaz.
 */
function openaiBaseUrl(): string
{
    $u = rtrim(trim((string) env_get('OPENAI_BASE_URL', '')), '/');
    return $u !== '' ? $u : OPENAI_BASE_URL;
}
const OPENAI_CONNECT_TIMEOUT = 5;
/** Sohbet: tek denemenin üst sınırı; süre zinciri gemini.php ile aynı (CHAT_PHP_TIME_LIMIT 45). */
const OPENAI_CHAT_TIMEOUT = 30;
/** Embedding (arka plan işçisi) tek istek üst sınırı. */
const OPENAI_EMBED_TIMEOUT = 60;
/** Bir embedding isteğinde en fazla bu kadar metin. */
const OPENAI_EMBED_BATCH = 64;
/** Arka plan işlerinde geçici hatada en fazla deneme (üstel geri çekilme). */
const OPENAI_BG_MAX_ATTEMPTS = 5;

/**
 * @return array{key:string,chat_model:string,embedding_model:string,embedding_dims:int,
 *               reasoning_effort:string,temperature:?float,max_output_tokens:int}
 */
function openaiConfig(): array
{
    $temp = trim((string) env_get('OPENAI_TEMPERATURE', ''));
    return [
        'key'               => trim((string) env_get('OPENAI_API_KEY', '')),
        'chat_model'        => trim((string) env_get('OPENAI_CHAT_MODEL', '')),
        'embedding_model'   => trim((string) env_get('OPENAI_EMBEDDING_MODEL', '')),
        'embedding_dims'    => max(0, (int) env_get('OPENAI_EMBEDDING_DIMS', '0')),
        'reasoning_effort'  => trim((string) env_get('OPENAI_REASONING_EFFORT', 'none')),
        'temperature'       => $temp === '' ? null : (float) $temp,
        'max_output_tokens' => max(64, (int) env_get('OPENAI_MAX_OUTPUT_TOKENS', '1200')),
    ];
}

/** OpenAI sohbet kullanılabilir mi (anahtar + model tanımlı). */
function openaiChatEnabled(): bool
{
    $c = openaiConfig();
    return $c['key'] !== '' && $c['chat_model'] !== '';
}

function openaiMask(string $text, string $apiKey): string
{
    return $apiKey === '' ? $text : str_replace($apiKey, '«gizli»', $text);
}

/** @return array{type: string, code: string, message: string} */
function openaiParseError(string $body): array
{
    $j = json_decode($body, true);
    $err = is_array($j) ? ($j['error'] ?? null) : null;
    if (!is_array($err)) {
        return ['type' => '', 'code' => '', 'message' => mb_substr(trim(strip_tags($body)), 0, 200)];
    }
    return [
        'type'    => (string) ($err['type'] ?? ''),
        'code'    => (string) ($err['code'] ?? ''),
        'message' => mb_substr((string) ($err['message'] ?? ''), 0, 300),
    ];
}

/** Tek satır, anahtarsız: `http=429 model=… code=… message=…`. */
function openaiErrorSummary(int $http, string $curlError, string $body, string $model, string $apiKey): string
{
    $e = openaiParseError($body);
    $parts = ['http=' . $http, 'model=' . $model];
    if ($curlError !== '') $parts[] = 'curl=' . $curlError;
    if ($e['type'] !== '') $parts[] = 'type=' . $e['type'];
    if ($e['code'] !== '') $parts[] = 'code=' . $e['code'];
    if ($e['message'] !== '') $parts[] = 'message=' . $e['message'];
    return openaiMask(implode(' ', $parts), $apiKey);
}

/**
 * @return 'busy'|'quota'|'unavailable'|'timeout'|'network'|'config'|'empty'
 */
function openaiFailureKind(int $http, string $curlError, string $body): string
{
    if ($curlError !== '') {
        return preg_match('/timed out|timeout/i', $curlError) ? 'timeout' : 'network';
    }
    if ($http === 429) {
        // insufficient_quota = bakiye/limit bitti; beklemekle düzelmez.
        return str_contains($body, 'insufficient_quota') ? 'quota' : 'busy';
    }
    if ($http === 503) return 'busy';
    if ($http === 408) return 'timeout';
    if ($http >= 500) return 'unavailable';
    if ($http >= 400) return 'config';
    if ($http >= 200 && $http < 300) return 'empty';
    return 'network';
}

/** Geçici (yeniden denenebilir) hata mı. */
function openaiIsTransient(string $kind): bool
{
    return in_array($kind, ['busy', 'unavailable', 'network', 'timeout'], true);
}

/**
 * Üstel geri çekilme: 1, 2, 4, 8… sn (+%0–25 rastgele), üst sınır $capSeconds.
 * Retry-After başlığı daha uzunsa o kullanılır (yine sınırla).
 */
function openaiBackoffSeconds(int $attempt, ?float $retryAfter = null, float $capSeconds = 30.0): float
{
    $base = min($capSeconds, 2 ** max(0, $attempt - 1));
    $base += $base * (mt_rand(0, 250) / 1000);
    if ($retryAfter !== null && $retryAfter > $base) {
        $base = $retryAfter;
    }
    return min($capSeconds, $base);
}

/**
 * Sohbet: akış başlamadan gelen hatada yeniden deneme planı (en fazla 3 deneme).
 * Süre bütçesi gemini.php'deki zincirle aynı: ikinci/üçüncü deneme PHP süre
 * sınırından en az 5 sn önce bitebilmeli.
 *
 * @return array{retry: bool, delay_ms: int, timeout: int, kind: string}
 */
function openaiChatRetryPlan(int $http, string $curlError, string $body, float $elapsed, bool $streamed, int $attempt): array
{
    $kind = openaiFailureKind($http, $curlError, $body);
    $no   = ['retry' => false, 'delay_ms' => 0, 'timeout' => 0, 'kind' => $kind];
    if ($streamed || $attempt >= 3 || !openaiIsTransient($kind) || $kind === 'timeout') {
        return $no;
    }
    $delay   = min(3.0, openaiBackoffSeconds($attempt) / 2);
    $timeout = (int) min(OPENAI_CHAT_TIMEOUT, floor(CHAT_PHP_TIME_LIMIT - 5 - $elapsed - $delay));
    if ($timeout < 10) {
        return $no;
    }
    return ['retry' => true, 'delay_ms' => (int) round($delay * 1000), 'timeout' => $timeout, 'kind' => $kind];
}

/**
 * Responses API istek gövdesi. Önbelleğe uygun sıra: sabit talimat
 * (`instructions`) başta; geçmiş; en sonda bilgi bankası bağlamı + soru.
 *
 * @param list<array{role:string,content:mixed}> $input
 */
function openaiChatPayload(string $instructions, array $input, string $cacheKey): array
{
    $c = openaiConfig();
    $payload = [
        'model'             => $c['chat_model'],
        'instructions'      => $instructions,
        'input'             => $input,
        'stream'            => true,
        'store'             => false,
        'max_output_tokens' => $c['max_output_tokens'],
        'prompt_cache_key'  => $cacheKey,
        // Açık mod: yalnızca chatOpenAiInput'un işaretlediği nokta (geçmiş)
        // önbelleğe yazılır; işaret yoksa (ilk tur) hiç yazma yapılmaz.
        'prompt_cache_options' => ['mode' => 'explicit'],
    ];
    if ($c['reasoning_effort'] !== '') {
        $payload['reasoning'] = ['effort' => $c['reasoning_effort']];
    }
    if ($c['temperature'] !== null) {
        $payload['temperature'] = $c['temperature'];
    }
    return $payload;
}

/**
 * Responses API SSE akışını işleyen durumlu ayrıştırıcı. Her `data:` satırı
 * tam geldiğinde çağrılır; metin parçalarını $onText ile iletir, kullanımı
 * ve hata durumunu biriktirir.
 */
final class OpenAiStreamParser
{
    private string $buffer = '';
    public string $text = '';
    public array $usage = ['input' => 0, 'cached' => 0, 'cache_write' => 0, 'output' => 0];
    public bool $completed = false;
    public string $streamError = '';

    /** @param callable(string):void $onText */
    public function __construct(private $onText) {}

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = (string) substr($this->buffer, $pos + 1);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') {
                continue;
            }
            $ev = json_decode($json, true);
            if (!is_array($ev)) {
                continue;
            }
            $this->handle($ev);
        }
    }

    private function handle(array $ev): void
    {
        $type = (string) ($ev['type'] ?? '');
        if ($type === 'response.output_text.delta') {
            $delta = (string) ($ev['delta'] ?? '');
            if ($delta !== '') {
                $this->text .= $delta;
                ($this->onText)($delta);
            }
        } elseif ($type === 'response.completed' || $type === 'response.incomplete') {
            $this->completed = $type === 'response.completed';
            $u = (array) ($ev['response']['usage'] ?? []);
            $this->usage = [
                'input'  => (int) ($u['input_tokens'] ?? 0),
                'cached' => (int) ($u['input_tokens_details']['cached_tokens'] ?? 0),
                'cache_write' => (int) ($u['input_tokens_details']['cache_write_tokens'] ?? 0),
                'output' => (int) ($u['output_tokens'] ?? 0),
            ];
            if ($type === 'response.incomplete') {
                $this->streamError = 'incomplete:' . (string) ($ev['response']['incomplete_details']['reason'] ?? '');
            }
        } elseif ($type === 'response.failed' || $type === 'error') {
            $err = (array) ($ev['response']['error'] ?? $ev['error'] ?? $ev);
            $this->streamError = 'failed:' . (string) ($err['code'] ?? '') . ' ' . mb_substr((string) ($err['message'] ?? ''), 0, 200);
        }
    }
}

/**
 * Gemini biçiminde tek SSE karesi — arayüz (chat/page.jsx) bu biçimi okuyor;
 * sağlayıcı değişse de istemcinin akış ayrıştırıcısı değişmiyor.
 */
function openaiClientFrame(string $text): string
{
    return 'data: ' . json_encode(
        ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
        JSON_UNESCAPED_UNICODE
    ) . "\n\n";
}

/**
 * Metinleri embedding'e çevirir (toplu, geçici hatada üstel geri çekilmeyle
 * en fazla OPENAI_BG_MAX_ATTEMPTS deneme). Arka plan işçisi ve sohbetteki
 * soru embedding'i bunu kullanır; sohbet için $maxAttempts düşük verilir.
 *
 * @param list<string> $texts
 * @return array{ok: bool, vectors: list<list<float>>, tokens: int, kind: string, error: string}
 */
function openaiEmbed(array $texts, int $maxAttempts = OPENAI_BG_MAX_ATTEMPTS, int $timeout = OPENAI_EMBED_TIMEOUT): array
{
    $c = openaiConfig();
    $out = ['ok' => false, 'vectors' => [], 'tokens' => 0, 'kind' => '', 'error' => ''];
    if ($c['key'] === '' || $c['embedding_model'] === '') {
        $out['kind'] = 'config';
        $out['error'] = 'OPENAI_API_KEY / OPENAI_EMBEDDING_MODEL tanımlı değil';
        return $out;
    }
    foreach (array_chunk(array_values($texts), OPENAI_EMBED_BATCH) as $batch) {
        $body = ['model' => $c['embedding_model'], 'input' => $batch, 'encoding_format' => 'float'];
        if ($c['embedding_dims'] > 0) {
            $body['dimensions'] = $c['embedding_dims'];
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            [$http, $resp, $curlError, $retryAfter] = openaiPostJson('/embeddings', $body, $c['key'], $timeout);
            if ($curlError === '' && $http === 200) {
                $j = json_decode($resp, true);
                $data = is_array($j) ? ($j['data'] ?? null) : null;
                if (!is_array($data) || count($data) !== count($batch)) {
                    $out['kind'] = 'empty';
                    $out['error'] = 'beklenmeyen embedding yanıtı';
                    return $out;
                }
                usort($data, static fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
                foreach ($data as $row) {
                    $out['vectors'][] = array_map('floatval', (array) ($row['embedding'] ?? []));
                }
                $out['tokens'] += (int) ($j['usage']['total_tokens'] ?? 0);
                break;
            }
            $kind = openaiFailureKind($http, $curlError, $resp);
            if (!openaiIsTransient($kind) || $attempt >= $maxAttempts) {
                $out['kind'] = $kind;
                $out['error'] = openaiErrorSummary($http, $curlError, $resp, $c['embedding_model'], $c['key']);
                return $out;
            }
            usleep((int) (openaiBackoffSeconds($attempt, $retryAfter) * 1e6));
        }
    }
    $out['ok'] = true;
    return $out;
}

/**
 * @return array{0:int,1:string,2:string,3:?float} http, gövde, curl hatası, Retry-After (sn)
 */
function openaiPostJson(string $path, array $body, string $apiKey, int $timeout): array
{
    $retryAfter = null;
    $ch = curl_init(openaiBaseUrl() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => OPENAI_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$retryAfter): int {
            if (stripos($line, 'retry-after:') === 0) {
                $v = trim(substr($line, 12));
                if (is_numeric($v)) $retryAfter = (float) $v;
            }
            return strlen($line);
        },
    ]);
    $resp = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = openaiMask(curl_error($ch), $apiKey);
    curl_close($ch);
    return [$http, $resp, $err, $retryAfter];
}

/** Vektör ↔ BLOB (float32, little-endian). */
function embeddingPack(array $vector): string
{
    return pack('g*', ...$vector);
}

/** @return list<float> */
function embeddingUnpack(string $blob): array
{
    return $blob === '' ? [] : array_values(unpack('g*', $blob));
}

/**
 * Kosinüs benzerliği. OpenAI embedding'leri birim uzunlukta döner, yine de
 * boyut düşürme ya da farklı model ihtimaline karşı normalize ediliyor.
 */
function embeddingCosine(array $a, array $b): float
{
    $n = min(count($a), count($b));
    if ($n === 0) return 0.0;
    $dot = $na = $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i]; $y = $b[$i];
        $dot += $x * $y; $na += $x * $x; $nb += $y * $y;
    }
    return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
}

/**
 * Her model çağrısını kaydeder (maliyet takibi, ileride paket limitleri).
 * Tablo yoksa (020 uygulanmamış) sessizce geçer — sohbeti asla düşürmez.
 */
function aiUsageLog($db, ?int $userId, ?int $chatbotId, string $kind, string $model, int $input, int $cached, int $output, int $latencyMs, string $outcome, int $cacheWrite = 0): void
{
    try {
        $db->execute(
            'INSERT INTO ai_usage_log (user_id, chatbot_id, kind, model, input_tokens, cached_tokens, cache_write_tokens, output_tokens, latency_ms, outcome)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $chatbotId, $kind, mb_substr($model, 0, 64), $input, $cached, $cacheWrite, $output, $latencyMs, mb_substr($outcome, 0, 32)]
        );
    } catch (Throwable $e) {
        error_log('[ai_usage] kayıt yazılamadı: ' . $e->getMessage());
    }
}
