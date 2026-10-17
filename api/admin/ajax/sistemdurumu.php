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

$key   = AppConfig::googleGeminiApiKey();
$probe = geminiProbe($key);

if (!$probe['ok']) {
    error_log(geminiMask(sprintf(
        '[gemini] admin test hata: http=%d model=%s curl=%s status=%s reason=%s message=%s',
        $probe['http'], $probe['model'], $probe['curl'] ?: '-', $probe['status'] ?: '-', $probe['reason'] ?: '-', $probe['message'] ?: '-'
    ), $key));
}

echo json_encode(['success' => true, 'gemini' => $probe + ['key_source' => geminiKeySource()]], JSON_UNESCAPED_UNICODE);
