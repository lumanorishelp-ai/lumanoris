<?php
class SocialController {
    // ── Likes ────────────────────────────────────────────────────────────────

    public static function likeChatbot(): void {
        require_method('POST');
        $userId    = AuthMiddleware::requireAuth();
        $data      = json_decode($_POST['data'] ?? '', true) ?? null;
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);

        if (!$data || !$chatbotId) JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();

        // C-02 deseni (önce DELETE, sonra INSERT … ON DUPLICATE) artık
        // chatbotRelationSet()'te. N-35: isteğe bağlı `action` ("like" /
        // "unlike") idempotent; verilmezse eski toggle. Yanıt adları değişmedi.
        $result = self::relationResult($db, 'chatbot_likes', 'liked_at', $userId, $chatbotId, $data['action'] ?? null);
        if ($result['action'] === 'liked') {
            $db->delete('chatbot_dislikes', 'user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
            JsonResponse::success(['action' => 'liked', 'liked' => true, 'inserted_id' => $result['id'] ?? null, 'message' => 'Like eklendi.']);
        }
        if ($result['action'] === 'unliked') {
            JsonResponse::success(['action' => 'unliked', 'liked' => false, 'deleted' => 1, 'message' => 'Like kaldırıldı.']);
        }
        JsonResponse::success(['action' => 'unchanged', 'liked' => ($data['action'] ?? '') === 'like', 'message' => 'Değişiklik yok.']);
    }

    /** N-35 — ortak giriş: eylem doğrulaması ve fonksiyon dosyasının yüklenmesi. */
    private static function relationResult(Database $db, string $table, string $tsColumn, int $userId, int $chatbotId, $action): array {
        require_once __DIR__ . '/../../../functions/social_relations.php';
        $action = $action === null ? null : (string) $action;
        try {
            return chatbotRelationSet($db, $table, $tsColumn, $userId, $chatbotId, $action);
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }
    }

    public static function dislikeChatbot(): void {
        require_method('POST');
        $userId    = AuthMiddleware::requireAuth();
        $data      = json_decode($_POST['data'] ?? '', true) ?? null;
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);

        if (!$data || !$chatbotId) JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();

        // C-02 — bkz. likeChatbot(); aynı yarış, aynı çözüm.
        if ($db->delete('chatbot_dislikes', 'user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]) > 0) {
            JsonResponse::success(['action' => 'undisliked', 'deleted' => 1, 'message' => 'Dislike kaldırıldı.']);
        }

        $id = $db->insert(
            'chatbot_dislikes',
            ['user_id' => $userId, 'chatbot_id' => $chatbotId, 'disliked_at' => date('Y-m-d H:i:s')],
            true
        );
        $db->delete('chatbot_likes', 'user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
        JsonResponse::success(['action' => 'disliked', 'inserted_id' => $id, 'message' => 'Dislike eklendi.']);
    }

    public static function didUserLike(): void {
        $userId    = AuthMiddleware::optionalAuth();
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);

        $row = Database::getInstance()->selectSingle('id FROM chatbot_likes WHERE user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
        JsonResponse::success(['didLike' => (bool) $row]);
    }

    // Bundles didUserLike/didUserDislike/didUserFollow into one round-trip —
    // ProfileCard.jsx and ChatbotCard.jsx each fired 2-3 separate GETs for
    // the same chatbot_id on mount (worse on a marketplace grid, where every
    // visible card repeats this). Same three underlying queries, same auth,
    // just returned together instead of over three requests.
    public static function getUserBotStatus(): void {
        $userId    = AuthMiddleware::optionalAuth();
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();
        $didLike    = (bool) $db->selectSingle('id FROM chatbot_likes WHERE user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
        $didDisLike = (bool) $db->selectSingle('id FROM chatbot_dislikes WHERE user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
        $didFollow  = (bool) $db->selectSingle('id FROM chatbot_follows WHERE user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);

        JsonResponse::success(['didLike' => $didLike, 'didDisLike' => $didDisLike, 'didFollow' => $didFollow]);
    }

    public static function didUserDislike(): void {
        $userId    = AuthMiddleware::optionalAuth();
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);

        $row = Database::getInstance()->selectSingle('id FROM chatbot_dislikes WHERE user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
        JsonResponse::success(['didDisLike' => (bool) $row]);
    }

    // ── Follows ──────────────────────────────────────────────────────────────

    public static function followChatbot(): void {
        require_method('POST');
        $userId    = AuthMiddleware::requireAuth();
        $data      = json_decode($_POST['data'] ?? '', true) ?? null;
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);

        if (!$data || !$chatbotId) JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();

        // N-35 — isteğe bağlı `action` ("follow" / "unfollow") idempotent;
        // Takip Edilenler sayfası "unfollow" gönderir, bayat sayfa botu yeniden
        // takip edemez. Eylem yoksa eski toggle (sohbet sayfası). Yanıt adları
        // değişmedi ("follow" / "unfollowed"); `following` bayrağı eklendi.
        $result = self::relationResult($db, 'chatbot_follows', 'followed_at', $userId, $chatbotId, $data['action'] ?? null);
        if ($result['action'] === 'followed') {
            JsonResponse::success(['action' => 'follow', 'following' => true, 'inserted_id' => $result['id'] ?? null, 'message' => 'Follow eklendi.']);
        }
        if ($result['action'] === 'unfollowed') {
            JsonResponse::success(['action' => 'unfollowed', 'following' => false, 'deleted' => 1, 'message' => 'Follow kaldırıldı.']);
        }
        JsonResponse::success(['action' => 'unchanged', 'following' => ($data['action'] ?? '') === 'follow', 'message' => 'Değişiklik yok.']);
    }

    public static function didUserFollow(): void {
        $userId    = AuthMiddleware::optionalAuth();
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);

        $row = Database::getInstance()->selectSingle('id FROM chatbot_follows WHERE user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]);
        JsonResponse::success(['didFollow' => (bool) $row]);
    }

    // ── Comments ─────────────────────────────────────────────────────────────

    public static function addComment(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // SEC-014 🟡 — istemcinin JSON'u doğrudan insert()'e gidiyordu.
        // `commented_at` sunucunun; istemciden yazılabilmesi sahte tarihli
        // yorumlar demekti, tanımsız bir anahtar ise ham SQL hatası.
        [$data, $rejected] = InputSanitizer::pickAllowed($data, ['chatbot_id', 'comment']);
        if ($rejected !== []) {
            JsonResponse::error(
                'Bu alanlar gönderilemez: ' . implode(', ', $rejected),
                403,
                AppConfig::ERR_PERMISSION
            );
        }

        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        $comment   = InputSanitizer::text($data['comment'] ?? '', 2000);
        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);
        if (trim($comment) === '') JsonResponse::error('Yorum boş olamaz.', 400, AppConfig::ERR_VALIDATION);

        $id = Database::getInstance()->insert('chatbot_comments', [
            'chatbot_id' => $chatbotId,
            'user_id'    => $userId,
            'comment'    => $comment,
        ]);
        JsonResponse::success(['message' => 'Yorum başarıyla eklendi', 'id' => $id]);
    }

    public static function getChatbotComments(): void {
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);

        $comments = Database::getInstance()->selectMulti(
            'cc.id, cc.chatbot_id, cc.user_id, cc.comment, cc.commented_at, u.kullanici_adi
             FROM chatbot_comments cc
             JOIN kullanicilar u ON u.id = cc.user_id
             WHERE cc.chatbot_id = ? ORDER BY cc.id DESC',
            [$chatbotId]
        );

        JsonResponse::success(['count' => count($comments), 'comments' => $comments]);
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    /**
     * ReportModal.jsx'in `reportOptions` slug'larıyla aynı küme. Değer buradan
     * geçmezse saklanmaz: `reported_for` admin panelinde rozet olarak render
     * ediliyor ve `FIND_IN_SET` sorgusuna giriyor (G-01).
     */
    private const REPORT_REASONS = ['sexual_content', 'legal_issue', 'terrorism', 'spam'];

    public static function addReport(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        // G-18 — sütun varchar(1000); 2000'e kırpmak sessiz veri kaybı demekti.
        $detail    = InputSanitizer::text($data['report_detail'] ?? '', 1000);

        // G-01 — bu alan hiç doğrulanmadan saklanıyor ve panelde HTML olarak
        // render ediliyordu (depolanmış XSS, admin oturumunda). Kümede olmayan
        // değer atılıyor; hiçbiri kalmazsa istek reddediliyor.
        $rawReasons  = is_array($data['reported_for'] ?? null) ? $data['reported_for'] : [];
        $reportedFor = array_values(array_unique(array_filter(
            array_map(static fn($r) => is_string($r) ? $r : '', $rawReasons),
            static fn(string $r) => in_array($r, self::REPORT_REASONS, true)
        )));

        if (!$chatbotId || $reportedFor === []) {
            JsonResponse::error('Eksik parametreler!', 400, AppConfig::ERR_VALIDATION);
        }

        $id = Database::getInstance()->insert('chatbot_reports', [
            'user_id'       => $userId,
            'chatbot_id'    => $chatbotId,
            'reported_for'  => implode(',', $reportedFor),
            'report_detail' => $detail,
        ]);

        JsonResponse::success(['message' => 'Bildirim kaydedildi', 'id' => $id]);
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    /** "Yeni Liste Olustur" modalindaki renk secenekleriyle ayni kume. */
    private const LIST_COLORS = ['violet', 'fuchsia', 'emerald', 'amber'];

    public static function addUserList(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // Istemcinin gonderdigi anahtarlar dogrudan INSERT'e gidiyordu; tablo
        // disi bir alan SQL hatasi uretiyordu. Alanlar artik beyaz listede.
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            JsonResponse::error('Liste adi gereklidir.', 400, AppConfig::ERR_VALIDATION);
        }

        $color = (string) ($data['color'] ?? 'violet');
        if (!in_array($color, self::LIST_COLORS, true)) {
            $color = 'violet';
        }

        $row = [
            'user_id'     => $userId,
            'name'        => mb_substr($name, 0, 255),
            'color'       => $color,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 500),
        ];

        $id = Database::getInstance()->insert('user_lists', $row);
        JsonResponse::success([
            'message' => 'Liste eklendi!',
            'listId'  => $id,
            'list'    => ['id' => $id] + $row,
        ]);
    }

    public static function addBotToList(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || !isset($data['list_id'])) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $listId = InputSanitizer::positiveInt($data['list_id']);
        $db     = Database::getInstance();

        // Previously anyone could add a bot to any list_id — verify it's the caller's own list.
        if (!$db->selectSingle('id FROM user_lists WHERE id = ? AND user_id = ?', [$listId, $userId])) {
            JsonResponse::error('Bu liste üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        // B-04 — ham $data doğrudan insert()'e gidiyordu (kütle atama). Aynı
        // dosyadaki addComment/addUserList deseni: yalnızca iki sütun.
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        $id = $db->insert('chatbot_in_list', ['chatbot_id' => $chatbotId, 'list_id' => $listId]);
        JsonResponse::success(['message' => 'Bot listeye eklendi.', 'id' => $id]);
    }

    public static function deleteBotFromList(): void {
        require_method('POST');
        $userId    = AuthMiddleware::requireAuth();
        $data      = json_decode($_POST['data'] ?? '', true) ?? null;
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        $listId    = InputSanitizer::positiveInt($data['list_id'] ?? 0);

        if (!$data || !$chatbotId || !$listId) {
            JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        }

        $db = Database::getInstance();
        // Previously anyone could remove bots from any list_id — verify ownership first.
        if (!$db->selectSingle('id FROM user_lists WHERE id = ? AND user_id = ?', [$listId, $userId])) {
            JsonResponse::error('Bu liste üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        $db->delete('chatbot_in_list', 'chatbot_id = ? AND list_id = ?', [$chatbotId, $listId]);
        JsonResponse::success(['message' => 'Bot listeden kaldırıldı.']);
    }

    public static function getUserLists(): void {
        $userId = AuthMiddleware::requireAuth();

        $lists = Database::getInstance()->selectMulti('id, name, color, description FROM user_lists WHERE user_id = ?', [$userId]);
        JsonResponse::success(['lists' => $lists]);
    }

    public static function getBotLists(): void {
        $userId = AuthMiddleware::requireAuth();
        $botId  = InputSanitizer::positiveInt($_GET['botId'] ?? 0);
        if (!$botId) JsonResponse::error('botId gereklidir.', 400, AppConfig::ERR_VALIDATION);

        $lists = Database::getInstance()->selectMulti(
            'ul.id, ul.name,
             (SELECT COUNT(*) FROM chatbot_in_list cil WHERE cil.list_id = ul.id AND cil.chatbot_id = ?) as is_in_list
             FROM user_lists ul WHERE ul.user_id = ?',
            [$botId, $userId]
        );

        JsonResponse::success(['lists' => $lists]);
    }

    public static function getBotsOfList(): void {
        // B-01 — bu metotta hiç kimlik doğrulama yoktu ve list_id'nin çağırana
        // ait olduğu kontrol edilmiyordu: `?list_id=1,2,3…` ile herkesin özel
        // listesinin içeriği okunabiliyordu. Kardeş üç metodun (addBotToList,
        // deleteBotFromList, deleteUserList) kontrolünün aynısı.
        $userId = AuthMiddleware::requireAuth();
        $listId = InputSanitizer::positiveInt($_GET['list_id'] ?? 0);
        if (!$listId) JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();
        if (!$db->selectSingle('id FROM user_lists WHERE id = ? AND user_id = ?', [$listId, $userId])) {
            JsonResponse::error('Bu liste üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        $bots = $db->selectMulti(
            "c.id, c.isim, c.profil_fotografi, c.ucret_haftalik,
             COUNT(DISTINCT cc.id) AS toplam_chats
             FROM chatbot_in_list cil
             JOIN chatbotlar c ON c.id = cil.chatbot_id
             LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id AND pms.status = 'active'
             LEFT JOIN chatbot_chats cc ON cc.chatbot_id = c.id
             -- Faz 4 / N-09: vitrinle aynı görünürlük (ChatbotRepository::PUBLIC_VISIBLE_SQL)
             WHERE cil.list_id = ?
               AND c.is_independent = 0
               AND (COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0 OR pms.user_id IS NOT NULL)
             GROUP BY c.id",
            [$listId]
        );

        JsonResponse::success(['count' => count($bots), 'bots' => $bots, 'total_chats' => array_sum(array_column($bots, 'toplam_chats'))]);
    }

    public static function getFollowedBots(): void {
        $userId = AuthMiddleware::requireAuth();

        // Every account implicitly follows the platform's own "Lumanoris AI"
        // bot (owned by the SYSTEM user), even without a chatbot_follows row —
        // the UNION branch adds it once, only when not already followed for real.
        $bots = Database::getInstance()->selectMulti(
            "c.id, c.id AS chatbot_id, c.isim, c.aciklama, c.profil_fotografi, c.kapak_fotografi,
                    c.ucret_haftalik, c.kategori_id, ck.kategori_adi_tr AS kategori_adi,
                    u.kullanici_adi AS gelistirici_adi,
                    (SELECT COUNT(*) FROM chatbot_follows WHERE chatbot_id = c.id) AS takipci_sayisi
             FROM chatbot_follows cf
             JOIN chatbotlar c ON c.id = cf.chatbot_id
             LEFT JOIN chatbot_kategoriler ck ON ck.id = c.kategori_id
             LEFT JOIN kullanicilar u ON u.id = c.owner_user_id
             WHERE cf.user_id = ?

             UNION

             SELECT c.id, c.id AS chatbot_id, c.isim, c.aciklama, c.profil_fotografi, c.kapak_fotografi,
                    c.ucret_haftalik, c.kategori_id, ck.kategori_adi_tr AS kategori_adi,
                    u.kullanici_adi AS gelistirici_adi,
                    (SELECT COUNT(*) FROM chatbot_follows WHERE chatbot_id = c.id) AS takipci_sayisi
             FROM chatbotlar c
             JOIN kullanicilar u ON u.id = c.owner_user_id
             LEFT JOIN chatbot_kategoriler ck ON ck.id = c.kategori_id
             WHERE u.kullanici_adi = 'SYSTEM' AND c.isim = 'Lumanoris AI'
               AND NOT EXISTS (SELECT 1 FROM chatbot_follows WHERE chatbot_id = c.id AND user_id = ?)",
            [$userId, $userId]
        );

        // N-27 — seçicinin kilidi sohbet kapısıyla aynı kuraldan (eklemeli alan).
        $canChat = array_flip((new ChatbotRepository())->chatAccessibleIds(array_column($bots, 'id'), $userId));
        foreach ($bots as &$bot) {
            $bot['can_chat'] = isset($canChat[(int) $bot['id']]);
        }
        unset($bot);

        JsonResponse::success(['bots' => $bots]);
    }

    public static function deleteUserList(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $id     = InputSanitizer::positiveInt($data['id'] ?? $_POST['id'] ?? 0);
        if (!$id) JsonResponse::error('ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();

        // Previously anyone could delete any list_id — verify ownership first.
        if (!$db->selectSingle('id FROM user_lists WHERE id = ? AND user_id = ?', [$id, $userId])) {
            JsonResponse::error('Bu liste üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        $db->delete('chatbot_in_list', 'list_id = ?', [$id]);
        $db->delete('user_lists', 'id = ?', [$id]);
        JsonResponse::success(['message' => 'Liste silindi.']);
    }

    // ── Hide / Uninterested ───────────────────────────────────────────────────

    public static function addHide(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // B-04 — ham $data doğrudan insert()'e gidiyordu (kütle atama).
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        $id = Database::getInstance()->insert('chatbot_hide', ['user_id' => $userId, 'chatbot_id' => $chatbotId]);
        JsonResponse::success(['message' => 'Chatbot gizlendi.', 'id' => $id]);
    }

    public static function getHide(): void {
        $userId = AuthMiddleware::requireAuth();

        $rows = Database::getInstance()->selectMulti('chatbot_id FROM chatbot_hide WHERE user_id = ?', [$userId]);
        JsonResponse::success(['hidden' => array_column($rows, 'chatbot_id')]);
    }

    public static function addUninterest(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // B-04 — ham $data doğrudan insert()'e gidiyordu (kütle atama).
        $categoryId = InputSanitizer::positiveInt($data['category_id'] ?? 0);
        if (!$categoryId) JsonResponse::error('category_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        $id = Database::getInstance()->insert('chatbot_uninterested', ['user_id' => $userId, 'category_id' => $categoryId]);
        JsonResponse::success(['message' => 'Kategori ilgi dışı olarak işaretlendi.', 'id' => $id]);
    }

    public static function getUninterest(): void {
        $userId = AuthMiddleware::requireAuth();

        $rows = Database::getInstance()->selectMulti('category_id FROM chatbot_uninterested WHERE user_id = ?', [$userId]);
        JsonResponse::success(['categories' => array_column($rows, 'category_id')]);
    }
}
