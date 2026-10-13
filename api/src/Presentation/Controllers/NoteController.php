<?php
class NoteController {
    public static function addDialogBook(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/dialog_book.php';
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!is_array($data)) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // N-30 — istemci artık soru/cevap METNİ göndermiyor: yalnızca kendi
        // geçmişindeki bir bot mesajının kimliği + başlık. Metin alanları açıkça
        // reddediliyor (sessizce yok saymak eski istemciyi "çalışıyor" sanırdı).
        // user_id eski istemcilerden gelebiliyor; oturumdan alındığı için düşürülür.
        unset($data['user_id']);
        $extra = array_diff(array_keys($data), ['message_id', 'name']);
        if ($extra !== []) {
            JsonResponse::error('Bu alanlar gönderilemez: ' . implode(', ', $extra), 400, AppConfig::ERR_VALIDATION);
        }
        $messageId = InputSanitizer::positiveInt($data['message_id'] ?? 0);
        if (!$messageId) JsonResponse::error('message_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        try {
            $id = dialogBookCreateFromMessage(
                Database::getInstance(), new ChatbotRepository(), $userId, $messageId,
                InputSanitizer::string($data['name'] ?? '', 255)
            );
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }
        JsonResponse::success(['message' => 'Diyalog Defterine eklendi.', 'id' => $id]);
    }

    /** N-30 — kimlikle erişilen defter uçlarının ortak kapısı (akışla aynı kural). */
    private static function requireVisibleDialog(int $dialogId, int $viewerId): void {
        require_once __DIR__ . '/../../../functions/dialog_book.php';
        if (!dialogBookIsVisible(Database::getInstance(), $dialogId, $viewerId)) {
            JsonResponse::error('Diyalog bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        }
    }

    /**
     * B-05 — "Sohbet Defteri herkese açık mı olmalı?" sorusunun cevabı:
     * EVET, ve bu bilinçli bir üründür.
     *
     * Denetim bunu belirsiz saymıştı; arayüz o zamandan beri netleşti ve
     * artık kullanıcıya kaydetme ANINDA açıkça söylüyor
     * (`DialogNotebookModal.jsx`):
     *   • Başlık: "Diyalog Defterine Ekle"
     *   • Alt başlık: "…Diyalog Defteri sayfasında paylaşın."
     *   • Kehribar uyarı kutusu: "Bu bir herkese açık paylaşımdır.
     *     Yayınladığınızda sorunuz ve yapay zekânın yanıtı, kullanıcı
     *     adınızla birlikte tüm kullanıcılara görünür olur."
     *   • Başarı bildirimi: "Başarıyla yayınlandı"
     * Sekmeler de aynı şeyi söylüyor: "Tüm Paylaşılanlar" / "Paylaştıklarım".
     * Yani "kaydet" ile "yayınla" arasında bir ayrım YOK — olmaması bilinçli.
     * Bu yüzden `is_public` sütunu EKLENMEDİ: arayüzde karşılığı olmayan bir
     * gizlilik bayrağı, akışı sessizce boşaltmaktan başka bir şey yapmazdı.
     *
     * Asıl kapatılan açık, `udb.*` idi: SELECT * bu tabloya ileride eklenecek
     * HER sütunu otomatik olarak yayına sokardı. Sütunlar artık açıkça
     * sayılıyor — yeni bir alan eklendiğinde varsayılan davranış "yayınla"
     * değil "yayınlama" oluyor.
     */
    public static function getDialogues(): void {
        // The dialogue book is a deliberately public feed (notes/page.jsx has a
        // "Paylaştıklarım" tab), but it was readable with no session at all, so
        // every user's input_message/output_message could be scraped from
        // outside the app entirely. The feed is only ever rendered inside the
        // authenticated dashboard, so requiring a session costs the product
        // nothing and takes it off the open internet.
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/dialog_book.php';

        // N-30 — akışta yalnızca vitrinde görünebilen (herkese açık) botların
        // kayıtları; özel/bağımsız botun kaydı yalnızca paylaşana. Böylece özel
        // botun adı ve sahibi başkasına sızmıyor. Sütun listesi ve "BOTUN sahibi
        // / paylaşan" ayrımı dialogBookFeed()'da (B-05 notu orada da geçerli).
        $results = dialogBookFeed(Database::getInstance(), $userId, 100);
        JsonResponse::success(['dialogues' => $results]);
    }

    public static function getDialogInteracts(): void {
        // Same feed as getDialogues, same reasoning: it returns other users'
        // comments and usernames, and is only rendered inside the dashboard.
        $viewerId = AuthMiddleware::requireAuth();

        $id = InputSanitizer::positiveInt($_GET['id'] ?? 0);
        if (!$id) JsonResponse::error('ID gereklidir.', 400, AppConfig::ERR_VALIDATION);
        self::requireVisibleDialog($id, $viewerId);

        $db = Database::getInstance();
        $likeDislike = $db->selectSingle(
            "udb.id,
             (SELECT COUNT(*) FROM dialog_likes WHERE dialog_id = udb.id) AS likes,
             (SELECT COUNT(*) FROM dialog_dislikes WHERE dialog_id = udb.id) AS dislikes
             FROM user_dialog_books udb WHERE udb.id = ?",
            [$id]
        );
        $comments = $db->selectMulti(
            "dc.id AS comment_id, dc.comment, dc.commented_at, k.kullanici_adi AS comment_owner
             FROM dialog_comments dc
             LEFT JOIN kullanicilar k ON dc.user_id = k.id
             WHERE dc.dialog_id = ?",
            [$id]
        );

        echo json_encode(['success' => true, 'dialog' => $likeDislike, 'comments' => $comments], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function addComment(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // B-03 — ham $data doğrudan insert()'e gidiyordu; ayrıca dialog_id'nin
        // var olduğu hiç kontrol edilmiyor ve yorum kırpılmıyordu.
        // SocialController::addComment() ile birebir aynı desen.
        $dialogId = InputSanitizer::positiveInt($data['dialog_id'] ?? 0);
        // Sütun varchar(1000).
        $comment  = InputSanitizer::text($data['comment'] ?? '', 1000);

        if (!$dialogId) JsonResponse::error('dialog_id gereklidir.', 400, AppConfig::ERR_VALIDATION);
        if (trim($comment) === '') JsonResponse::error('Yorum boş olamaz.', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();
        self::requireVisibleDialog($dialogId, $userId);

        $id = $db->insert('dialog_comments', [
            'user_id'   => $userId,
            'dialog_id' => $dialogId,
            'comment'   => $comment,
        ]);
        JsonResponse::success(['message' => 'Yorum eklendi.', 'id' => $id]);
    }

    public static function likeDialog(): void {
        require_method('POST');
        $userId   = AuthMiddleware::requireAuth();
        $data     = json_decode($_POST['data'] ?? '', true) ?? null;
        $dialogId = InputSanitizer::positiveInt($data['dialog_id'] ?? 0);
        if (!$data || !$dialogId) JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);
        self::requireVisibleDialog($dialogId, $userId);

        $db = Database::getInstance();

        // C-02 — SELECT+INSERT yarışı UNIQUE (user_id, dialog_id) ihlaliyle
        // 500 üretiyordu. DELETE'in satır sayısı atomik "var mıydı?" cevabı.
        if ($db->delete('dialog_likes', 'user_id = ? AND dialog_id = ?', [$userId, $dialogId]) > 0) {
            JsonResponse::success(['action' => 'unliked', 'message' => 'Like kaldırıldı.']);
        }

        $id = $db->insert('dialog_likes', ['user_id' => $userId, 'dialog_id' => $dialogId], true);
        $db->delete('dialog_dislikes', 'user_id = ? AND dialog_id = ?', [$userId, $dialogId]);
        JsonResponse::success(['action' => 'liked', 'inserted_id' => $id, 'message' => 'Like eklendi.']);
    }

    public static function dislikeDialog(): void {
        require_method('POST');
        $userId   = AuthMiddleware::requireAuth();
        $data     = json_decode($_POST['data'] ?? '', true) ?? null;
        $dialogId = InputSanitizer::positiveInt($data['dialog_id'] ?? 0);
        if (!$data || !$dialogId) JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);
        self::requireVisibleDialog($dialogId, $userId);

        $db = Database::getInstance();

        // C-02 — bkz. likeDialog(); aynı yarış, aynı çözüm.
        if ($db->delete('dialog_dislikes', 'user_id = ? AND dialog_id = ?', [$userId, $dialogId]) > 0) {
            JsonResponse::success(['action' => 'undisliked', 'message' => 'Dislike kaldırıldı.']);
        }

        $id = $db->insert('dialog_dislikes', ['user_id' => $userId, 'dialog_id' => $dialogId], true);
        $db->delete('dialog_likes', 'user_id = ? AND dialog_id = ?', [$userId, $dialogId]);
        JsonResponse::success(['action' => 'disliked', 'inserted_id' => $id, 'message' => 'Dislike eklendi.']);
    }

    public static function didUserLike(): void {
        $userId   = AuthMiddleware::optionalAuth();
        $dialogId = InputSanitizer::positiveInt($_GET['dialog_id'] ?? 0);
        if (!$dialogId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        self::requireVisibleDialog($dialogId, $userId);

        $row = Database::getInstance()->selectSingle('id FROM dialog_likes WHERE user_id = ? AND dialog_id = ?', [$userId, $dialogId]);
        JsonResponse::success(['didLike' => (bool) $row]);
    }

    public static function didUserDislike(): void {
        $userId   = AuthMiddleware::optionalAuth();
        $dialogId = InputSanitizer::positiveInt($_GET['dialog_id'] ?? 0);
        if (!$dialogId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        self::requireVisibleDialog($dialogId, $userId);

        $row = Database::getInstance()->selectSingle('id FROM dialog_dislikes WHERE user_id = ? AND dialog_id = ?', [$userId, $dialogId]);
        JsonResponse::success(['didDisLike' => (bool) $row]);
    }
}
