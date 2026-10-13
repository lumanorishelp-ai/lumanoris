<?php
class ChatbotRepository extends BaseRepository implements ChatbotRepositoryInterface {
    private const T = AppConfig::TABLE_CHATBOTS;

    /**
     * Faz 4 / GK-2 — "ücretsiz herkese açık" = yayında ve iki fiyat da boş/0.
     * Fiyat yalnızca publishChatbot/updateChatbotPrice ile ve en az
     * MIN_WEEKLY_PRICE olarak yazılabildiği için fiyatlı bir bot bu koşula
     * giremez.
     */
    private const FREE_PUBLIC_SQL =
        '(c.is_independent = 0 AND COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0)';

    /**
     * Vitrinde görünür: ücretsiz herkese açık (her yazar) YA DA fiyatlı +
     * satıcısı aktif (`pms` = aktif satıcıya LEFT JOIN, B1). Özel
     * (is_independent = 1) bot hiçbir dalda yok.
     */
    private const PUBLIC_VISIBLE_SQL =
        '(c.is_independent = 0 AND (COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0 OR pms.user_id IS NOT NULL))';

    /**
     * N-30 — vitrin kuralı başka sorgularda (Diyalog Defteri akışı) AYNI
     * kaynaktan kullanılsın diye. Takma adlar: `c` = chatbotlar, `pms` = aktif
     * satıcıya LEFT JOIN (ON pms.user_id = c.author_user_id AND pms.status = 'active').
     */
    public static function publicVisibleSql(): string {
        return self::PUBLIC_VISIBLE_SQL;
    }

    public function findById(int $id): ?array {
        return self::one('SELECT * FROM `' . self::T . '` WHERE id = ?', [$id]);
    }

    public function findByIdAndOwner(int $id, int $ownerId): ?array {
        return self::one(
            'SELECT * FROM `' . self::T . '` WHERE id = ? AND author_user_id = ?',
            [$id, $ownerId]
        );
    }

    public function create(array $data): int {
        return self::insert(self::T, $data);
    }

    /**
     * Named updateById/deleteById (not update/delete) to avoid shadowing
     * BaseRepository's static update()/delete() with an incompatible
     * non-static signature — PHP forbids changing staticness when a method
     * name collides with the parent's, which previously made this whole
     * class fatal to even autoload.
     */
    public function updateById(int $id, array $data): bool {
        return self::update(self::T, $data, 'id = :_id', ['_id' => $id]) > 0;
    }

    public function deleteById(int $id): bool {
        return self::delete(self::T, 'id = ?', [$id]) > 0;
    }

    public function publish(int $id): bool {
        return self::update(self::T, ['is_independent' => 0], 'id = :_id', ['_id' => $id]) > 0;
    }

    public function unpublish(int $id): bool {
        return self::update(self::T, ['is_independent' => 1], 'id = :_id', ['_id' => $id]) > 0;
    }

    public function updatePrice(int $id, float $weekly, float $monthly): bool {
        return self::update(self::T, ['ucret_haftalik' => $weekly, 'ucret_aylik' => $monthly], 'id = :_id', ['_id' => $id]) > 0;
    }

    public function getByOwner(int $ownerId): array {
        return self::all(
            'SELECT * FROM `' . self::T . '` WHERE author_user_id = ? ORDER BY id DESC',
            [$ownerId]
        );
    }

    public function countByOwner(int $ownerId): array {
        $row = self::one(
            'SELECT
                SUM(is_independent = 1) AS independent_count,
                SUM(is_independent = 0) AS public_count
             FROM `' . self::T . '` WHERE author_user_id = ?',
            [$ownerId]
        );
        return [
            'independent' => (int) ($row['independent_count'] ?? 0),
            'public'      => (int) ($row['public_count'] ?? 0),
        ];
    }

    public function getSellerStatus(int $userId): ?string {
        $row = self::one(
            'SELECT status FROM param_marketplace_sellers WHERE user_id = ?',
            [$userId]
        );
        return $row ? $row['status'] : null;
    }

    /**
     * DB-001 🟠 / DB-009 🔵 / DB-012 — pazaryeri listesi.
     *
     * Eski hâli altı sınırsız alt tabloya `LEFT JOIN` yapıp `COUNT(DISTINCT)`
     * ile sayıyordu. Bu bir **kartezyen çarpım**: bir botun sohbet, takip,
     * liste, beğeni, beğenmeme ve yorum satırları birbiriyle çarpılıyor,
     * MySQL o ara sonucu üretip sonra `DISTINCT` ile eliyor.
     *
     * Canlı ölçüm (2026-08-26, 9 yayında bot): **2.062 ara satır — bot başına
     * 229 kat.** Küçük veriyle görünmez; gerçek veriyle çarpım çok hızlı
     * büyür, çünkü altı sayının hepsi aynı anda artar. Bu, ana sayfanın
     * sorgusu ve sayfalaması da yoktu.
     *
     * Düzeltme skaler alt sorgu: her sayı kendi indeksli aramasını yapıyor,
     * ara sonuç bot sayısı kadar satır. Aynı dosyadaki `getMenuItems()` bu
     * deseni zaten kullanıyordu.
     *
     * DB-012 — V1 ve V2 ayrı ayrı duruyordu ve aralarındaki fark tam iki
     * maddeydi (V2 "ilgilenmiyorum" filtresi ekliyor, `toplam_comments`
     * sayımını çıkarıyor). İki gövde yerine tek gövde: `getPublishedV2()`
     * artık buraya delege ediyor. `toplam_comments` ikisinde de dönüyor —
     * V2'nin onu çıkarması bir tasarım kararı değil, kopyalama farkıydı.
     *
     * @param array{search?:?string, exclude_uninterested?:bool, limit?:int, offset?:int} $filters
     * @param int $userId "ilgilenmiyorum" filtresi için; 0 = filtre yok
     */
    public function getPublished(array $filters = [], int $userId = 0): array {
        [$where, $params] = self::publishedWhere($filters, $userId);

        // DB-009: sorgunun üst sınırı yoktu. Varsayılan 100, tavan 200 —
        // istemci sayfalayabilsin diye toplam da döndürülüyor (bkz.
        // countPublished()).
        $limit  = (int) ($filters['limit'] ?? 100);
        $limit  = max(1, min($limit, 200));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return self::all(
            "SELECT c.id, c.kapak_fotografi, c.profil_fotografi, c.kategori_id, c.isim, c.aciklama,
                    c.ucret_haftalik, c.yayimlanma_tarih, 1 AS durum, u.kullanici_adi AS owner_name,
                    (SELECT COUNT(*) FROM chatbot_chats     WHERE chatbot_id = c.id) AS toplam_chats,
                    (SELECT COUNT(*) FROM chatbot_follows   WHERE chatbot_id = c.id) AS toplam_follows,
                    (SELECT COUNT(*) FROM chatbot_in_list   WHERE chatbot_id = c.id) AS toplam_lists,
                    (SELECT COUNT(*) FROM chatbot_likes     WHERE chatbot_id = c.id) AS toplam_likes,
                    (SELECT COUNT(*) FROM chatbot_dislikes  WHERE chatbot_id = c.id) AS toplam_dislikes,
                    (SELECT COUNT(*) FROM chatbot_comments  WHERE chatbot_id = c.id) AS toplam_comments,
                    -- N-26: `durum` sabit 1 (geriye dönük uyum için duruyor); \"Daha Önce
                    -- Satıldı\" rozeti yalnızca bu bayrağa bakar. Satış kaydı =
                    -- deleteChatbot'un saydığı iki tablo (süresi dolmuş abonelik dahil).
                    (EXISTS (SELECT 1 FROM user_subscriptions       WHERE chatbot_id = c.id)
                     OR EXISTS (SELECT 1 FROM chatbot_purchase_credits WHERE chatbot_id = c.id)) AS has_sales,
                    -- N-35: kartlardaki kalp başlangıç durumunu sunucudan alsın (oturumsuz → 0).
                    (? > 0 AND EXISTS (SELECT 1 FROM chatbot_likes cl WHERE cl.chatbot_id = c.id AND cl.user_id = ?)) AS liked_by_me
             FROM `" . self::T . "` c
             LEFT JOIN param_marketplace_sellers pms
                     ON pms.user_id = c.author_user_id AND pms.status = 'active'
             LEFT JOIN kullanicilar u ON u.id = c.owner_user_id
             $where
             ORDER BY c.id DESC
             LIMIT $limit OFFSET $offset",
            array_merge([$userId, $userId], $params)
        );
    }

    /** DB-009: sayfalama için toplam kayıt sayısı. */
    public function countPublished(array $filters = [], int $userId = 0): int {
        [$where, $params] = self::publishedWhere($filters, $userId);

        $row = self::one(
            "SELECT COUNT(*) AS total
             FROM `" . self::T . "` c
             LEFT JOIN param_marketplace_sellers pms
                     ON pms.user_id = c.author_user_id AND pms.status = 'active'
             $where",
            $params
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Listeleme ve sayım aynı WHERE'i kullanmak zorunda — ayrı yazılırlarsa
     * toplam ile sayfa içeriği sessizce ayrışır.
     *
     * @return array{0:string, 1:array}
     */
    private static function publishedWhere(array $filters, int $userId): array {
        $params = [];
        // Faz 4 / GK-2 — vitrinde: ücretsiz herkese açık botlar (her yazar)
        // + fiyatlı botlar yalnızca satıcısı aktifse (B1). Sorgular `pms`
        // takma adıyla aktif satıcıya LEFT JOIN yapıyor.
        $where  = ' WHERE c.id > 0 AND ' . self::PUBLIC_VISIBLE_SQL;

        if (!empty($filters['exclude_uninterested']) && $userId > 0) {
            // DB-005: chatbot_uninterested(user_id) indekssiz; alt sorgu
            // yine de tam tarama yapmasın diye NOT EXISTS kullanılıyor.
            $where   .= ' AND NOT EXISTS (SELECT 1 FROM chatbot_uninterested cu
                                          WHERE cu.user_id = ? AND cu.category_id = c.kategori_id)';
            $params[] = $userId;
        }
        if (!empty($filters['search'])) {
            $where   .= ' AND c.isim LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        return [$where, $params];
    }

    /**
     * DB-012: V1'den farkı yalnızca "ilgilenmiyorum" filtresiydi. Tek gövdeye
     * indirildi; imza korunuyor çünkü `getchatbots_v2.php` onu çağırıyor.
     */
    public function getPublishedV2(int $userId, array $filters = []): array {
        return $this->getPublished($filters + ['exclude_uninterested' => true], $userId);
    }
    /**
     * N-27 — verilen botlardan kullanıcının SOHBET edebildikleri; karar
     * generateReply'ın kapısıyla aynı: userHasAccess($id, $userId, 'chat')
     * (sahip / aktif abone / ücretsiz herkese açık). Ana sayfa bot seçicisi
     * kilidi buradan alır; eskiden getMenuItems (yalnızca sahip + abone)
     * kullanıldığı için takip edilen ücretsiz bot kilitli görünüyordu.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function chatAccessibleIds(array $ids, int $userId): array {
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0 && $this->userHasAccess($id, $userId, 'chat')) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** Returns bots the user owns or has active subscriptions for. */
    public function getMenuItems(int $userId): array {
        return self::all(
            "SELECT c.id, c.author_user_id, c.owner_user_id, c.is_independent, c.isim,
                    c.kapak_fotografi, c.profil_fotografi, c.kategori_id, c.ucret_haftalik, c.ucret_aylik,
                    COALESCE(pms.status, 'not_started') AS seller_status,
                    (SELECT COUNT(*) FROM chatbot_likes WHERE chatbot_id = c.id) AS likes,
                    (SELECT COUNT(*) FROM chatbot_dislikes WHERE chatbot_id = c.id) AS dislikes,
                    (SELECT COUNT(*) FROM chatbot_follows WHERE chatbot_id = c.id) AS follows,
                    (SELECT COUNT(*) FROM chatbot_chats WHERE chatbot_id = c.id) AS toplam_chats
             FROM `" . self::T . "` c
             LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id
             WHERE c.author_user_id = ?
                OR (c.owner_user_id = ? AND c.author_user_id != ?
                    AND EXISTS (
                        SELECT 1 FROM user_subscriptions us
                        WHERE us.user_id = ? AND us.chatbot_id = c.id
                          AND us.status = 1 AND us.expiry_date > NOW()
                    ))",
            [$userId, $userId, $userId, $userId]
        );
    }

    /**
     * Returns suggested bots in the same categories as the user's cart items.
     * All values are parameterized — fixes the SQL injection in the legacy controller.
     */
    public function getSuggested(int $userId, array $categoryIds, array $excludeIds, int $limit): array {
        if (empty($categoryIds)) return [];

        $catIn  = self::inClause(array_values($categoryIds));
        $exIn   = self::inClause(array_values($excludeIds));
        $safeLimit = max(1, min(50, $limit));

        $sql = "SELECT c.id, c.kapak_fotografi, c.profil_fotografi, c.isim, c.aciklama,
                       c.owner_user_id, u.kullanici_adi AS owner_name, c.ucret_haftalik,
                       COUNT(cc.id) AS toplam_chats
                FROM `" . self::T . "` c
                LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id AND pms.status = 'active'
                LEFT JOIN kullanicilar u ON u.id = c.owner_user_id
                LEFT JOIN chatbot_chats cc ON cc.chatbot_id = c.id
                WHERE c.kategori_id IN ({$catIn['placeholders']})
                  AND " . self::PUBLIC_VISIBLE_SQL;

        $params = $catIn['params'];

        if (!empty($exIn['params'])) {
            $sql   .= " AND c.id NOT IN ({$exIn['placeholders']})";
            $params = array_merge($params, $exIn['params']);
        }

        $sql .= " GROUP BY c.id ORDER BY RAND() LIMIT $safeLimit";

        return self::all($sql, $params);
    }

    /**
     * PAY-002 🔴 — ödeme duvarı yoktu.
     *
     * Eskiden tek bir erişim kavramı vardı ve içindeki
     * `c.is_independent = 0 AND pms.user_id IS NOT NULL` dalı, **satıştaki her
     * botu** abonelik olmadan herkese açıyordu. SEC-015 ile birleşince
     * (`generateReply` sistem talimatını istemciden alıyordu) sonuç şuydu:
     * `getchatbot` + `get_training_chunks` ile persona ve tüm eğitim metni
     * çekilip doğrudan modele verilebiliyordu — ürünün tamamı ücretsiz.
     *
     * Artık iki ayrı kavram var:
     *
     *   'preview' — pazaryeri vitrini. Yayındaki (bağımsız olmayan, satıcısı
     *               aktif) bir botun KARTINI görmek için yeter: isim,
     *               açıklama, fiyat, yorumlar, karşılama mesajı.
     *   'full'    — botun kendisini kullanmak ve özel içeriğini (style_prompt,
     *               training_prompt, sohbet) görmek. Yalnızca sahibi ya da
     *               süresi geçmemiş aktif aboneliği olan kullanıcı.
     *
     * Çağıran taraf hangisini istediğini açıkça söylemek zorunda; varsayılan
     * 'full', yani unutulan bir çağrı ücretsiz erişim değil, reddedilen
     * erişim üretir (fail-closed).
     */
    public function userHasAccess(int $chatbotId, int $userId, string $purpose = 'full'): bool {
        if ($userId <= 0) {
            return false;
        }

        $ownerOrSubscriber =
            "c.author_user_id = ?
             OR c.owner_user_id = ?
             OR EXISTS (
                  SELECT 1 FROM user_subscriptions us
                  WHERE us.user_id = ? AND us.chatbot_id = c.id
                    AND us.status = 1 AND us.expiry_date > NOW()
                )";

        if ($purpose === 'preview') {
            $row = self::one(
                "SELECT 1
                 FROM `" . self::T . "` c
                 LEFT JOIN param_marketplace_sellers pms
                        ON pms.user_id = c.author_user_id AND pms.status = 'active'
                 WHERE c.id = ?
                   AND (
                        $ownerOrSubscriber
                     OR " . self::PUBLIC_VISIBLE_SQL . "
                   )",
                [$chatbotId, $userId, $userId, $userId]
            );
            return $row !== null;
        }

        // Faz 4 / GK-2 — 'chat': ücretsiz herkese açık bot giriş yapmış herkesle
        // sohbet eder (mesaj kullanıcının günlük coin'inden düşer). 'full'
        // DEĞİŞMEDİ: persona ve eğitim metni yalnızca sahip/abone içindir.
        if ($purpose === 'chat') {
            $row = self::one(
                "SELECT 1
                 FROM `" . self::T . "` c
                 WHERE c.id = ?
                   AND ($ownerOrSubscriber OR " . self::FREE_PUBLIC_SQL . ")",
                [$chatbotId, $userId, $userId, $userId]
            );
            return $row !== null;
        }

        $row = self::one(
            "SELECT 1
             FROM `" . self::T . "` c
             WHERE c.id = ?
               AND ($ownerOrSubscriber)",
            [$chatbotId, $userId, $userId, $userId]
        );
        return $row !== null;
    }

    /**
     * PAY-002: kart verisi 'preview' erişimiyle, botun özel içeriği 'full'
     * erişimiyle döner. `style_prompt` (persona) ücretli içeriğin kendisi —
     * abonesi olmayan bir kullanıcıya gönderilirse ödeme duvarı yine delinir,
     * çünkü istemci onu doğrudan modele verebilir.
     *
     * `has_access` alanı istemcinin "abone ol" ekranını mı yoksa sohbeti mi
     * göstereceğini bilmesini sağlıyor.
     */
    public function getDetail(int $id, int $userId): ?array {
        $hasFull = $this->userHasAccess($id, $userId, 'full');
        // Faz 4 — sohbet edebilme (ücretsiz herkese açık dahil) persona
        // görmekten AYRI: ücretsiz botun personası yine yalnızca sahip/aboneye.
        $canChat = $hasFull || $this->userHasAccess($id, $userId, 'chat');

        if (!$canChat && !$this->userHasAccess($id, $userId, 'preview')) {
            return null;
        }

        $row = self::one(
            "SELECT c.id, c.isim, c.is_independent,
                    (SELECT kullanici_adi FROM kullanicilar WHERE id = c.author_user_id) AS author_username,
                    (SELECT kullanici_adi FROM kullanicilar WHERE id = c.owner_user_id)  AS owner_username,
                    c.owner_user_id, c.aciklama, c.kategori_id,
                    c.kapak_fotografi, c.profil_fotografi, c.style_prompt, c.sohbet_basi_mesaj,
                    c.ucret_haftalik, c.ucret_aylik,
                    (SELECT COUNT(*) FROM chatbot_likes   WHERE chatbot_id = c.id) AS likes,
                    (SELECT COUNT(*) FROM chatbot_dislikes WHERE chatbot_id = c.id) AS dislikes,
                    (SELECT COUNT(*) FROM chatbot_follows  WHERE chatbot_id = c.id) AS follows,
                    (SELECT COUNT(*) FROM chatbot_chats    WHERE chatbot_id = c.id) AS toplam_chats
             FROM `" . self::T . "` c
             WHERE c.id = ?",
            [$id]
        );

        if ($row === null) {
            return null;
        }

        $row['has_access'] = $canChat;
        // Faz 4 / kontrol B — persona (`style_prompt`) yalnızca botun SAHİBİNE
        // döner. Sohbet onu kullanmıyor (talimatı generateReply sunucuda
        // kuruyor); tek okuyan oluştur/düzenle sayfası. Eskiden 'full' ile
        // abonelere de gidiyordu; ücretsiz herkese açık botta da kimseye
        // gitmemeli.
        $isOwner = $userId > 0 && self::one(
            'SELECT 1 FROM `' . self::T . '` WHERE id = ? AND (author_user_id = ? OR owner_user_id = ?)',
            [$id, $userId, $userId]
        ) !== null;
        if (!$isOwner) {
            unset($row['style_prompt']);
        }
        // N-24 — istemci satın alma düğmesini sahibe göstermemek için okur.
        $row['is_owner'] = $isOwner;

        return $row;
    }

    public function getComments(int $chatbotId): array {
        return self::all(
            "SELECT cc.id, cc.chatbot_id, cc.user_id, cc.comment, cc.commented_at, u.kullanici_adi
             FROM chatbot_comments cc
             JOIN kullanicilar u ON u.id = cc.user_id
             WHERE cc.chatbot_id = ?
             ORDER BY cc.id DESC",
            [$chatbotId]
        );
    }

    public function getCartCategoryIds(int $userId): array {
        return self::all(
            'SELECT uc.chatbot_id, c.kategori_id FROM user_cart uc JOIN `' . self::T . '` c ON uc.chatbot_id = c.id WHERE uc.user_id = ?',
            [$userId]
        );
    }
}
