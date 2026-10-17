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

const GEMINI_MODEL = 'gemini-3-flash-preview';

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
        CURLOPT_POSTFIELDS     => json_encode(['contents' => [['role' => 'user', 'parts' => [['text' => 'Yalnızca "tamam" yaz.']]]]]),
        CURLOPT_RETURNTRANSFER => true,
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
