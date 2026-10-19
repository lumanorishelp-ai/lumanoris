<?php
/**
 * Sistem durumu → "Gemini'yi test et". Yalnızca admin oturumu + CSRF
 * (_guard.php), yalnızca POST. Sohbetle aynı model/uç noktaya tek kısa
 * istek atar; yanıtta anahtar yoktur (geminiProbe maskeler). Hata log'a da
 * `[gemini] admin test` etiketiyle yazılır.
 */
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../functions/gemini.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Yalnızca POST.']);
    exit;
}

// N-57 — "Hoppa bağlantı testi": var olmayan bir referansla ProcessQuery.
// Ödeme başlatmaz, para hareketi yok; seçili HOPPA_MODE'un MERCHANT /
// MERCHANT_KEY'ini doğrular. Yanıtta anahtar yok (redact + yalnızca
// durum/kod/mesaj alanları).
if (($_POST['test'] ?? '') === 'hoppa') {
    require_once __DIR__ . '/../../functions/hoppa_diagnostics.php';
    $gateway = new HoppaGateway();
    $ref     = 'PING-' . strtoupper(bin2hex(random_bytes(6)));
    $started = microtime(true);
    $query   = $gateway->isConfigured()
        ? $gateway->queryPayment($ref)
        : ['state' => 'unknown', 'raw' => [], 'message' => ''];
    $result  = hoppaProbeClassify($query) + [
        'mode'     => $gateway->isTest() ? 'test' : 'live',
        'base_url' => $gateway->baseUrl(),
        'ms'       => (int) round((microtime(true) - $started) * 1000),
    ];
    error_log(sprintf('[hoppa] admin bağlantı testi mode=%s kind=%s status=%s code=%s message=%s ms=%d',
        $result['mode'], $result['kind'], $result['status'] ?: '-', $result['code'] ?: '-', $result['message'] ?: '-', $result['ms']));
    echo json_encode(['success' => true, 'hoppa' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

$key   = AppConfig::googleGeminiApiKey();
$probe = geminiProbe($key);

if (!$probe['ok']) {
    error_log(geminiMask(sprintf(
        '[gemini] admin test hata: http=%d model=%s curl=%s status=%s reason=%s message=%s',
        $probe['http'], $probe['model'], $probe['curl'] ?: '-', $probe['status'] ?: '-', $probe['reason'] ?: '-', $probe['message'] ?: '-'
    ), $key));
}

echo json_encode(['success' => true, 'gemini' => $probe + ['key_source' => geminiKeySource()]], JSON_UNESCAPED_UNICODE);
