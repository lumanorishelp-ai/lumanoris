<?php
/**
 * Bilgi Bankası sekmesi (RAG) — kaynak listeleme, ekleme, kaldırma, tekrar deneme.
 *
 * Metin çıkarma ve doğrulama mevcut uçlarda kalıyor (readdocument/readurl):
 * istemci önce metni alır, sonra add() ile kaynağı kuyruğa koyar. Parçalama
 * ve embedding cron işçisinde (cron/kb_worker.php) — HTTP isteği beklemez.
 *
 * Yetki: yalnızca botun sahibi (ChatbotRepository::findByIdAndOwner).
 */
class KnowledgeBaseController {
    private const MAX_NAME = 255;

    /** GET ?botId= — kaynaklar (en yeni önce) + bot toplamı. */
    public static function list(): void {
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/kb.php';
        $botId = InputSanitizer::positiveInt($_GET['botId'] ?? 0);
        if (!$botId) JsonResponse::error('Chatbot seçilmedi.', 400, AppConfig::ERR_VALIDATION);
        self::requireOwner($botId, $userId);

        $db = Database::getInstance();
        $rows = $db->selectMulti(
            'id, type, name, url, mime, size_bytes, status, error_code, char_count, chunk_count, created_at, updated_at
               FROM kb_sources WHERE chatbot_id = ? ORDER BY id DESC',
            [$botId]
        ) ?: [];
        $sources = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'type'         => $r['type'],
                'name'         => $r['name'],
                'url'          => $r['url'],
                'status'       => $r['status'],
                'status_label' => kbStatusLabel((string) $r['status']),
                'error'        => $r['status'] === 'basarisiz' ? kbErrorMessage((string) $r['error_code']) : null,
                'chars'        => (int) $r['char_count'],
                'chunks'       => (int) $r['chunk_count'],
                'created_at'   => $r['created_at'],
            ];
        }, $rows);
        $s = kbSettings();
        JsonResponse::success([
            'sources'   => $sources,
            'total'     => self::botChars($db, $botId),
            'limit'     => $s['bot_max'],
            'pending'   => count(array_filter($sources, static fn($x) => in_array($x['status'], ['beklemede', 'isleniyor'], true))),
        ]);
    }

    /** POST {chatbot_id, type: file|url|text, name, url?, mime?, size_bytes?, text} */
    public static function add(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/kb.php';
        $db = Database::getInstance();
        checkRateLimit($db, 'kbadd:' . $userId, 30, 300);

        $in    = json_decode(file_get_contents('php://input'), true) ?? [];
        $botId = InputSanitizer::positiveInt($in['chatbot_id'] ?? 0);
        $type  = (string) ($in['type'] ?? '');
        $text  = trim((string) ($in['text'] ?? ''));
        $name  = trim((string) ($in['name'] ?? ''));
        $url   = trim((string) ($in['url'] ?? ''));

        if (!$botId) JsonResponse::error('Chatbot seçilmedi.', 400, AppConfig::ERR_VALIDATION);
        if (!in_array($type, ['file', 'url', 'text'], true)) {
            JsonResponse::error('Geçersiz kaynak türü.', 400, AppConfig::ERR_VALIDATION);
        }
        self::requireOwner($botId, $userId);

        $s = kbSettings();
        $len = mb_strlen($text);
        if ($len < 20) {
            JsonResponse::error(kbErrorMessage('empty'), 422, AppConfig::ERR_VALIDATION, ['reason' => 'empty']);
        }
        if ($len > $s['source_max']) {
            JsonResponse::error(kbErrorMessage('too_large'), 413, AppConfig::ERR_VALIDATION, ['reason' => 'too_large']);
        }
        if (self::botChars($db, $botId) + $len > $s['bot_max']) {
            JsonResponse::error(kbErrorMessage('bot_full'), 413, AppConfig::ERR_LIMIT_REACHED, ['reason' => 'bot_full']);
        }
        if ($type === 'url' && !preg_match('#^https?://#i', $url)) {
            JsonResponse::error('Geçerli bir adres girin (http/https).', 400, AppConfig::ERR_VALIDATION);
        }
        if ($name === '') {
            $name = $type === 'url' ? $url : ($type === 'text' ? 'Metin — ' . date('d.m.Y H:i') : 'Belge');
        }

        $id = (int) $db->insert('kb_sources', [
            'chatbot_id' => $botId,
            'user_id'    => $userId,
            'type'       => $type,
            'name'       => mb_substr($name, 0, self::MAX_NAME),
            'url'        => $type === 'url' ? mb_substr($url, 0, 2048) : null,
            'mime'       => $type === 'file' ? mb_substr((string) ($in['mime'] ?? ''), 0, 127) : null,
            'size_bytes' => max(0, (int) ($in['size_bytes'] ?? 0)),
            'content'    => $text,
            'char_count' => $len,
            'status'     => 'beklemede',
        ]);
        JsonResponse::success(['id' => $id, 'status' => 'beklemede', 'status_label' => kbStatusLabel('beklemede')]);
    }

    /** POST {id} — kaynağı ve tüm parçalarını/embedding'lerini siler (FK CASCADE). */
    public static function delete(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $db  = Database::getInstance();
        $src = self::ownedSource($db, $userId);
        $db->delete('kb_sources', 'id = ?', [(int) $src['id']]);
        JsonResponse::success(['message' => 'Kaynak ve ona ait tüm içerik bilgi bankasından kaldırıldı.']);
    }

    /** POST {id} — başarısız kaynağı yeniden kuyruğa koyar. */
    public static function retry(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/kb.php';
        $db  = Database::getInstance();
        checkRateLimit($db, 'kbretry:' . $userId, 20, 300);
        $src = self::ownedSource($db, $userId);
        if ($src['status'] !== 'basarisiz') {
            JsonResponse::error('Bu kaynak zaten işleniyor ya da hazır.', 409, AppConfig::ERR_VALIDATION);
        }
        $db->execute(
            "UPDATE kb_sources SET status = 'beklemede', attempts = 0, error_code = NULL, next_attempt_at = NULL WHERE id = ?",
            [(int) $src['id']]
        );
        JsonResponse::success(['status' => 'beklemede', 'status_label' => kbStatusLabel('beklemede')]);
    }

    private static function requireOwner(int $botId, int $userId): void {
        if (!(new ChatbotRepository())->findByIdAndOwner($botId, $userId)) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }
    }

    private static function ownedSource($db, int $userId): array {
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = InputSanitizer::positiveInt($in['id'] ?? 0);
        if (!$id) JsonResponse::error('Kaynak seçilmedi.', 400, AppConfig::ERR_VALIDATION);
        $src = $db->selectSingle('id, chatbot_id, status FROM kb_sources WHERE id = ?', [$id]);
        if (!$src) JsonResponse::error('Kaynak bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        self::requireOwner((int) $src['chatbot_id'], $userId);
        return $src;
    }

    private static function botChars($db, int $botId): int {
        return (int) ($db->selectSingle('COALESCE(SUM(char_count), 0) AS n FROM kb_sources WHERE chatbot_id = ?', [$botId])['n'] ?? 0);
    }
}
