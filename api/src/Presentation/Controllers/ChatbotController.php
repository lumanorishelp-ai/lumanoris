<?php
/**
 * Thin Chatbot controller — request → use case/repository → response.
 * No SQL, no business logic. File upload handled here only because it touches $_FILES.
 */
class ChatbotController {
    public static function saveChatbot(): void {
        require_method('POST');
        require_once __DIR__ . '/../../../functions/chatbot_limits.php';

        $data = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) {
            JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);
        }

        // ChatbotForm.jsx always sends id:-1 as a "this is a new bot" sentinel
        // (botId is unset and bot is null on the create path). chatbotlar.id
        // is an unsigned auto-increment column, so passing -1 straight into
        // the INSERT's column list failed every single create with
        // "SQLSTATE[22003]: Numeric value out of range" — id must never be
        // client-supplied on create, the DB assigns it.
        unset($data['id']);

        // Identity comes from the session, never from the client-supplied
        // author_user_id — otherwise anyone could create bots "as" another
        // user, or dodge their own free-tier limit by claiming someone else's id.
        $authorUserId  = AuthMiddleware::requireAuth();
        // Creating/updating a bot writes rows and can carry image uploads; it
        // had no throttle of any kind.
        checkRateLimit(Database::getInstance(), 'savechatbot:' . $authorUserId, 20, 300);
        $isIndependent = !empty($data['is_independent']) ? 1 : 0;
        $data['is_independent'] = $isIndependent;
        $data['author_user_id'] = $authorUserId;

        $repo = new ChatbotRepository();

        $db    = Database::getInstance();

        // C-01 — limit kontrolü COUNT ile yapılıp INSERT ayrı gidiyordu; arada
        // kilit yoktu, yani aynı kullanıcının iki eşzamanlı isteği aynı sayıyı
        // okuyup ikisi de limiti geçebiliyordu. Kilit `create()`'ten sonra
        // bırakılıyor, böylece ikinci istek güncel sayıyı görür.
        $limitLock = self::acquireBotLimitLock($db->getConnection(), $authorUserId);

        $limit = $isIndependent ? getIndependentBotLimit($db, $authorUserId) : getPublicBotLimit($db, $authorUserId);
        $planName = getUserPlanName($db, $authorUserId);
        $counts = $repo->countByOwner($authorUserId);
        $used  = $isIndependent ? $counts['independent'] : $counts['public'];

        if ($used >= $limit) {
            JsonResponse::error(
                $isIndependent
                    // BIZ-002: mesaj eskiden her planda "Ücretsiz" diyordu;
                    // Gümüş planındaki kullanıcı 3. botunda "ücretsiz hakkınızı
                    // kullandınız" görüyordu. Plan adı ve limit artık mesajda.
                    ? sprintf('%s planınızdaki %d bağımsız chatbot hakkınızı kullandınız.', $planName, $limit)
                    : sprintf('%s planınızdaki %d herkese açık chatbot hakkınızı kullandınız.', $planName, $limit),
                422, AppConfig::ERR_LIMIT_REACHED
            );
        }

        // Faz 4 / madde 2 / GK-2 — herkese açık bot oluşturmak artık satıcı
        // kaydı İSTEMİYOR. Burada fiyat yazılamıyor (pickAllowed ücret
        // alanlarını reddediyor), yani oluşturulan herkese açık bot her zaman
        // ÜCRETSİZ herkese açık bottur. Fiyatlı satış hâlâ publishChatbot /
        // updateChatbotPrice'ta aktif satıcı şartına (B1) bağlı.

        $data['owner_user_id'] = $data['author_user_id'];

        /**
         * BIZ-007 🟡 — yüklenen görsel diske yazılıyor ama kayıt başarısız
         * olursa dosya diskte kalıyordu (Tur 3'ten devredilen açık soru).
         *
         * `handleImageUploads()` `move_uploaded_file()` çağırıyor; hemen
         * ardından gelen `pickAllowed()` reddi `JsonResponse::error()` ile
         * **exit** ediyor, ya da `create()` istisna atabiliyor. Her iki
         * durumda da `assets/kapak_fotografi/…` altında hiçbir satırın
         * göstermediği bir dosya kalıyor. Transaction yok, temizlik yok:
         * dosyalar sessizce birikiyor ve hiçbir zaman silinmiyor.
         *
         * Shutdown kancası çıkış yolu ne olursa olsun çalışıyor; kayıt
         * başarıyla oluştuysa `$botCreated` true olur ve dosyalar korunur.
         */
        $uploadedBefore = self::uploadedPathsIn($data);
        $data           = self::handleImageUploads($data);
        $uploadedNow    = array_values(array_diff(self::uploadedPathsIn($data), $uploadedBefore));

        $botCreated = false;
        if ($uploadedNow !== []) {
            register_shutdown_function(static function () use ($uploadedNow, &$botCreated): void {
                if ($botCreated) {
                    return;
                }
                foreach ($uploadedNow as $relPath) {
                    $abs = __DIR__ . '/../../../' . $relPath;
                    if (is_file($abs) && @unlink($abs)) {
                        error_log('[savechatbot] yetim görsel silindi: ' . $relPath);
                    }
                }
            });
        }

        // SEC-014: kalan anahtarlar hâlâ istemciden geliyor ve doğrudan
        // INSERT'e gidiyordu — `yayimlanma_tarih`/`edit_tarih` sahteleştirilebilir,
        // `ucret_haftalik`/`ucret_aylik` ise publishChatbot'un fiyat
        // doğrulamasını atlayarak yazılabilirdi. İçerik sütunları beyaz
        // listede; kimlik ve yayın alanları yukarıda sunucu tarafından
        // belirleniyor.
        [$content, $rejected] = InputSanitizer::pickAllowed($data, array_merge(
            self::UPDATABLE_CHATBOT_COLUMNS,
            ['author_user_id', 'owner_user_id', 'is_independent']
        ));
        if ($rejected !== []) {
            JsonResponse::error(
                'Bu alanlar gönderilemez: ' . implode(', ', $rejected)
                . '. Fiyat ve yayın durumu yayınlama akışında belirlenir.',
                403,
                AppConfig::ERR_PERMISSION
            );
        }
        $data = $content;

        // COMP-003 — içerik politikası. Beyaz listeden SONRA, INSERT'ten ÖNCE:
        // yalnızca gerçekten yazılacak alanlar taransın, ve reddedilen içerik
        // hiç veritabanına girmesin.
        //
        // `assertClean()` ValidationException fırlatıyor; global exception
        // handler bunu 500 + "Sunucu hatası oluştu." diye gösterirdi, yani
        // kullanıcı NEDEN reddedildiğini göremezdi. Bu yüzden burada
        // yakalanıp `fromException()` ile 400 + gerçek mesaja çevriliyor.
        try {
            ContentPolicy::assertClean($data);
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        $id         = $repo->create($data);
        $botCreated = true;
        self::releaseBotLimitLock($db->getConnection(), $limitLock);

        JsonResponse::success(['message' => 'Chatbot başarıyla oluşturuldu!', 'id' => $id]);
    }

    /**
     * C-01 — bot limitini "COUNT sonra yaz" deseninden koruyan adlandırılmış
     * kilit. WalletController::withdraw()'daki desenin aynısı.
     *
     * Hata dallarında ayrıca serbest bırakmak gerekmiyor: `JsonResponse::error()`
     * `exit` ediyor, MySQL adlandırılmış kilidi bağlantı kapanınca düşüyor.
     */
    private static function acquireBotLimitLock(PDO $conn, int $userId): string {
        $lockName = 'botlimit_user_' . $userId;
        $stmt     = $conn->prepare('SELECT GET_LOCK(?, 10) AS locked');
        $stmt->execute([$lockName]);
        if ((int) ($stmt->fetch()['locked'] ?? 0) !== 1) {
            JsonResponse::error('İşlem şu anda gerçekleştirilemiyor, lütfen tekrar deneyin.', 409, AppConfig::ERR_VALIDATION);
        }
        return $lockName;
    }

    private static function releaseBotLimitLock(PDO $conn, string $lockName): void {
        $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }

    /** BIZ-007: $data içindeki yüklenmiş görsel yollarını toplar. */
    private static function uploadedPathsIn(array $data): array {
        $paths = [];
        foreach (['kapak_fotografi', 'profil_fotografi'] as $col) {
            $val = $data[$col] ?? null;
            if (is_string($val) && str_starts_with($val, 'assets/')) {
                $paths[] = $val;
            }
        }
        return $paths;
    }

    public static function getDefaultBot(): void {
        $id = (new UserRepository())->getDefaultFollowBotId();
        if (!$id) {
            JsonResponse::error('Varsayılan bot bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        }
        JsonResponse::success(['id' => $id]);
    }

    public static function getChatbot(): void {
        $id     = InputSanitizer::positiveInt($_GET['id'] ?? 0);
        $userId = AuthMiddleware::optionalAuth();

        if (!$id) {
            JsonResponse::error('Chatbot ID gerekli.', 400, AppConfig::ERR_VALIDATION);
        }

        $repo    = new ChatbotRepository();
        $chatbot = $repo->getDetail($id, $userId);

        if (!$chatbot) {
            JsonResponse::error('Chatbot bulunamadı veya bu bota erişim izniniz yok.', 404, AppConfig::ERR_NOT_FOUND);
        }

        // API-001 🟠 — bu uç nokta başarıda ZARFSIZ ({chatbot:…}), hatada
        // ZARFLI ({success:false,…}) yanıt veriyordu. İstemci ikisini ayırt
        // edemediği için hiç kontrol yapmıyordu: 404 tamamen sessiz kalıyor,
        // sohbet sayfası boş açılıyor, hiçbir hata mesajı görünmüyordu.
        // Artık iki yol da aynı zarfı kullanıyor; `data.chatbot` erişimi
        // değişmediği için mevcut okuma noktaları kırılmıyor.
        $comments = $repo->getComments($id);
        JsonResponse::success([
            'chatbot'  => $chatbot,
            'comments' => ['count' => count($comments), 'list' => $comments],
        ]);
    }

    /**
     * DB-009: sayfalama eklendi. `limit`/`offset` isteğe bağlı; varsayılan
     * 100, tavan 200 (repository'de zorlanıyor). Yanıt geriye dönük uyumlu —
     * `bots` anahtarı aynı yerde, `total`/`limit`/`offset` yalnızca eklendi.
     */
    private static function paginationFromQuery(): array {
        return [
            'limit'  => InputSanitizer::positiveInt($_GET['limit'] ?? 0) ?: 100,
            'offset' => max(0, InputSanitizer::int($_GET['offset'] ?? 0)),
        ];
    }

    public static function getChatbots(): void {
        $search  = InputSanitizer::string($_GET['search'] ?? '');
        $filters = self::paginationFromQuery() + ['search' => $search !== '' ? $search : null];
        $repo    = new ChatbotRepository();
        // N-35: yalnızca `liked_by_me` için; oturum yoksa 0 (uç misafire açık kalır).
        // Filtre olarak kullanılmaz — `exclude_uninterested` istenmediği sürece liste aynı.
        $userId  = AuthMiddleware::optionalAuth();

        JsonResponse::success([
            'bots'   => $repo->getPublished($filters, $userId),
            'total'  => $repo->countPublished($filters),
            'limit'  => $filters['limit'],
            'offset' => $filters['offset'],
        ]);
    }

    public static function getChatbotsV2(): void {
        $userId  = AuthMiddleware::optionalAuth();
        $search  = InputSanitizer::string($_GET['search'] ?? '');
        $filters = self::paginationFromQuery() + ['search' => $search !== '' ? $search : null];
        $repo    = new ChatbotRepository();

        // API-002: bu uç nokta çıplak dizi döndürüyor (V1 zarflı). Bugün
        // hiçbir istemci onu çağırmıyor, ama sözleşmeyi burada değiştirmek
        // ileride sessiz bir kırılma yaratır — zarf tekilleştirmesi ERR-003
        // kapsamında topluca yapılmalı. Şimdilik davranış korunuyor.
        echo json_encode($repo->getPublishedV2($userId, $filters), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * SEC-003 🟠 — mass assignment ile publishChatbot'un dört kapısı atlanıyordu.
     *
     * `publishChatbot` bir botu herkese açık yapmadan önce dört kontrol
     * yapıyor: fiyat aralığı doğrulaması, sahiplik, ücretsiz public bot limiti
     * ve satıcı KYC durumu. `updateChatbot` ise yalnızca sahipliğe bakıp
     * istemciden gelen diziyi olduğu gibi UPDATE'e veriyordu — yani
     * `{"id":N,"is_independent":0,"ucret_haftalik":0.01}` tek istekte aynı
     * sonucu üretiyordu: yayında, KYC'siz, limit dışı ve fiyatı doğrulanmamış
     * bir bot.
     *
     * Beyaz liste yalnızca "içerik" sütunlarını kapsıyor. Yayın durumu ve
     * fiyat, kendi kontrollerini taşıyan publishChatbot/unpublishChatbot
     * üzerinden değişir.
     */
    private const UPDATABLE_CHATBOT_COLUMNS = [
        'isim',
        'aciklama',
        'kapak_fotografi',
        'profil_fotografi',
        'kategori_id',
        'style_prompt',
        'sohbet_basi_mesaj',
        'training_prompt',
    ];

    public static function updateChatbot(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;

        if (!$data || !isset($data['id'])) {
            JsonResponse::error('Veri veya ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);
        }

        $id   = (int) $data['id'];
        $repo = new ChatbotRepository();

        // Previously this had no ownership check at all — anyone who knew a
        // chatbot's id could overwrite its data.
        if (!$repo->findByIdAndOwner($id, $userId)) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        unset($data['id'], $data['author_user_id'], $data['owner_user_id']);
        $data = self::handleImageUploads($data);

        [$data, $rejected] = InputSanitizer::pickAllowed($data, self::UPDATABLE_CHATBOT_COLUMNS);
        if ($rejected !== []) {
            JsonResponse::error(
                'Bu alanlar bu uç noktadan güncellenemez: ' . implode(', ', $rejected)
                . '. Yayın durumu ve fiyat için yayınlama akışını kullanın.',
                403,
                AppConfig::ERR_PERMISSION
            );
        }
        if ($data === []) {
            JsonResponse::error('Güncellenecek alan yok.', 400, AppConfig::ERR_VALIDATION);
        }

        // COMP-003 — güncelleme yolu da taranıyor. Yalnızca oluşturmayı
        // filtrelemek işe yaramazdı: temiz bir botu kaydedip hemen ardından
        // güncelleme ile ihlal eden içeriği yazmak filtreyi tümden atlatırdı.
        // `assertClean()` yalnızca GÖNDERİLEN alanlara bakıyor, yani kısmi
        // güncellemede dokunulmayan sütunlar boşuna taranmıyor.
        try {
            ContentPolicy::assertClean($data);
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        $repo->updateById($id, $data);
        JsonResponse::success(['message' => 'Chatbot başarıyla güncellendi!', 'id' => $id]);
    }

    /**
     * BIZ-006 🟠 — bu uç nokta hard delete yapıyordu ve ödenmiş abonelikleri
     * yok ediyordu.
     *
     * `ChatbotRepository::deleteById()` çıplak bir `DELETE FROM chatbotlar`;
     * hiçbir çocuk satır kodda silinmiyor. Üç dönemde üç farklı yanlış:
     *
     *   • FK'lardan önce  → abonelikler YETİM kalıyordu (002/002b'nin
     *     temizlediği 62 satırın kaynağı tam olarak burasıydı),
     *   • 003 sonrası (CASCADE) → abonelikler ZİNCİRLEME siliniyordu:
     *     müşteri ödemiş, satıcı botu silince satın alma kaydı yok oluyordu,
     *   • 006 sonrası (RESTRICT) → veritabanı doğru şekilde engelliyor ama
     *     satıcı çıplak bir `1451` görüyor.
     *
     * Doğru davranış silmeyi engellemek değil, **doğru aracı göstermek**:
     * satılmış bir bot yayından kaldırılabilir (`unpublishChatbot`), ve
     * `userHasAccess()`'in `full` dalı `is_independent`'a bakmadığı için
     * mevcut aboneler süreleri dolana kadar erişmeye devam eder — yani
     * istenen "soft delete" davranışı zaten çalışıyor.
     *
     * Buradaki kontrol FK'yı bir hata kaynağı olmaktan çıkarıp son savunma
     * hattına çeviriyor: normal yolda kullanıcı ne yapacağını anlatan bir
     * mesaj alıyor, FK yalnızca buradan kaçan bir yol kalırsa devreye giriyor.
     */
    public static function deleteChatbot(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $id     = InputSanitizer::positiveInt($data['id'] ?? $_POST['id'] ?? 0);

        if (!$id) {
            JsonResponse::error('ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);
        }

        $repo = new ChatbotRepository();

        // Previously this had no ownership check at all — anyone who knew a
        // chatbot's id could delete it.
        if (!$repo->findByIdAndOwner($id, $userId)) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        // Satın alma kaydı olan bot hard delete edilemez. Süresi dolmuş
        // abonelikler de sayılır: abonelik satırı bir SATIN ALMA kaydıdır,
        // süresinin dolması onu muhasebe kaydı olmaktan çıkarmaz.
        $db    = Database::getInstance();
        $sales = $db->selectSingle(
            '(SELECT COUNT(*) FROM user_subscriptions WHERE chatbot_id = ?) AS abonelik,
             (SELECT COUNT(*) FROM chatbot_purchase_credits WHERE chatbot_id = ?) AS kredi',
            [$id, $id]
        );
        $subscriptions = (int) ($sales['abonelik'] ?? 0);
        $credits       = (int) ($sales['kredi'] ?? 0);

        if ($subscriptions > 0 || $credits > 0) {
            $bot           = $repo->findById($id);
            $alreadyHidden = $bot && (int) $bot['is_independent'] === 1;

            JsonResponse::error(
                $alreadyHidden
                    ? 'Bu chatbot satın alınmış olduğu için silinemez. Zaten yayından '
                      . 'kaldırılmış durumda; mevcut aboneler süreleri dolana kadar '
                      . 'erişmeye devam edecek.'
                    : 'Bu chatbot satın alınmış olduğu için silinemez. Bunun yerine '
                      . 'yayından kaldırabilirsiniz: pazaryerinden düşer, mevcut aboneler '
                      . 'süreleri dolana kadar erişmeye devam eder.',
                409,
                AppConfig::ERR_PERMISSION,
                [
                    'reason'        => 'has_sales',
                    'subscriptions' => $subscriptions,
                    'credits'       => $credits,
                    'can_unpublish' => !$alreadyHidden,
                ]
            );
        }

        $repo->deleteById($id);
        JsonResponse::success(['message' => 'Chatbot silindi.']);
    }

    public static function publishChatbot(): void {
        require_method('POST');
        require_once __DIR__ . '/../../../functions/chatbot_limits.php';

        $userId  = AuthMiddleware::requireAuth();
        $data    = json_decode($_POST['data'] ?? '', true) ?? null;
        $id      = InputSanitizer::positiveInt($data['id'] ?? 0);
        $weekly  = isset($data['ucret_haftalik']) ? (float) $data['ucret_haftalik'] : 0;
        // PAY-009: aylık fiyat İSTEMCİDEN alınmıyor, sunucuda türetiliyor.
        $monthly = self::deriveMonthlyPrice($weekly);

        if (!$data || !$id) {
            JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);
        }

        // Faz 4 / madde 10 / GK-1 / GK-2 — iki yayın biçimi:
        //   • fiyatsız ("Yayınla"): ücretsiz herkese açık; satıcı ŞARTI YOK.
        //   • fiyatlı (pazaryeri satışı): eskisi gibi fiyat aralığı + aktif
        //     satıcı (B1). Arayüz bu yolu bugün açmıyor ("Pazaryerine Kaydet"
        //     yalnızca başvuru sayfasına götürür), ama sunucu sözleşmesi korunuyor.
        // Geriye uyum: fiyat gönderen eski çağıran aynı davranışı alır.
        $isPaid = $weekly > 0;
        if ($isPaid) {
            self::assertValidPrice($weekly, 'Haftalık', AppConfig::MAX_WEEKLY_PRICE, AppConfig::MIN_WEEKLY_PRICE);
            self::assertValidPrice($monthly, 'Aylık', AppConfig::MAX_WEEKLY_PRICE * 4, round(AppConfig::MIN_WEEKLY_PRICE * 4 * AppConfig::DISCOUNT_MONTHLY_FACTOR));
        }

        $repo = new ChatbotRepository();
        $bot  = $repo->findById($id);

        if (!$bot || (int) $bot['author_user_id'] !== $userId) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }
        if ((int) $bot['is_independent'] === 0) {
            JsonResponse::error('Bu chatbot zaten yayında.', 400, AppConfig::ERR_DUPLICATE);
        }

        $db = Database::getInstance();

        // C-01 — saveChatbot ile aynı yarış: iki eşzamanlı yayınlama isteği
        // aynı `public` sayısını okuyup ikisi de limiti geçebiliyordu.
        $limitLock   = self::acquireBotLimitLock($db->getConnection(), $userId);

        $publicLimit = getPublicBotLimit($db, $userId);
        $counts      = $repo->countByOwner($userId);
        if ($counts['public'] >= $publicLimit) {
            JsonResponse::error(sprintf('%s planınızdaki %d herkese açık chatbot hakkınızı kullandınız.', getUserPlanName($db, $userId), $publicLimit), 422, AppConfig::ERR_LIMIT_REACHED);
        }

        if ($isPaid) {
            $sellerStatus = $repo->getSellerStatus($userId);
            if ($sellerStatus !== 'active') {
                JsonResponse::error('Önce Pazaryeri satıcı kaydınızı tamamlayın.', 422, AppConfig::ERR_SELLER_INACTIVE);
            }
        }

        $repo->updateById($id, [
            'is_independent' => 0,
            'ucret_haftalik' => $isPaid ? $weekly : null,
            'ucret_aylik'    => $isPaid ? $monthly : null,
        ]);
        self::releaseBotLimitLock($db->getConnection(), $limitLock);
        JsonResponse::success([
            'message' => $isPaid
                ? 'Chatbot pazaryerinde satışa açıldı!'
                : 'Chatbot herkese açık olarak yayınlandı! Herkes ücretsiz sohbet edebilir.',
            'free'    => !$isPaid,
        ]);
    }

    public static function unpublishChatbot(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        $id     = InputSanitizer::positiveInt($data['id'] ?? 0);

        if (!$data || !$id) {
            JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);
        }

        $repo = new ChatbotRepository();
        $bot  = $repo->findById($id);

        if (!$bot || (int) $bot['author_user_id'] !== $userId) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        // Madde 11 / GK-3 — yalnızca YAYINLANMIŞ bir bot özele çekilir ve hak
        // düşer. Zaten özel olan bot için hak harcatılmaz (eskiden bu çağrı
        // sessizce is_independent=1'i tekrar yazıyordu).
        if ((int) $bot['is_independent'] === 1) {
            JsonResponse::error('Bu chatbot zaten özel.', 400, AppConfig::ERR_DUPLICATE);
        }

        require_once __DIR__ . '/../../../functions/chatbot_limits.php';
        $db   = Database::getInstance();
        $conn = $db->getConnection();

        // C-01 deseni: hak sayımı ile yazma arasında yarış olmasın — aynı
        // kullanıcının iki eşzamanlı isteği aynı "kalan hak"ı okuyamasın.
        $lock = self::acquireBotLimitLock($conn, $userId);

        // N-07 — özele çekme bağımsız bot limitine TAKILMAZ (GK-3); bilinçli
        // olarak burada bağımsız limiti kontrolü yok. Sınır özel yapma hakkı.
        $tableReady = privacyUsageTableReady($db);
        $limit      = getPrivacyRightLimit($db, $userId);
        if ($tableReady && countPrivacyRightUsage($db, $userId) >= $limit) {
            JsonResponse::error(
                sprintf(
                    '%s planınızdaki %d yayından kaldırma (özel yapma) hakkınızı kullandınız.',
                    getUserPlanName($db, $userId),
                    $limit
                ),
                422,
                AppConfig::ERR_LIMIT_REACHED,
                ['privacy_limit' => $limit, 'privacy_remaining' => 0]
            );
        }
        if (!$tableReady) {
            // Migration 012 uygulanmamış: hak sayılamıyor. Özele çekmeyi kırmak
            // yerine eski davranış (sınırsız) — ama görünür olsun.
            error_log('[unpublishChatbot] user_privacy_right_usage yok (migration 012); hak sayılmadan özele çekildi. bot=' . $id);
        }

        $conn->beginTransaction();
        try {
            $repo->unpublish($id);
            if ($tableReady) {
                recordPrivacyRightUsage($db, $userId, $id);
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            self::releaseBotLimitLock($conn, $lock);
            throw $e;
        }
        self::releaseBotLimitLock($conn, $lock);

        JsonResponse::success([
            'message'           => 'Chatbot özel yapıldı (yayından kaldırıldı).',
            'privacy_limit'     => $limit,
            'privacy_remaining' => $tableReady ? privacyRightsRemaining($db, $userId) : null,
        ]);
    }

    public static function getChatbotsMenu(): void {
        $userId = AuthMiddleware::requireAuth();
        $repo   = new ChatbotRepository();
        JsonResponse::success(['bots' => $repo->getMenuItems($userId)]);
    }

    public static function getChatbotLimits(): void {
        require_once __DIR__ . '/../../../functions/chatbot_limits.php';
        $userId = AuthMiddleware::requireAuth();

        $db               = Database::getInstance();
        $repo             = new ChatbotRepository();
        $counts           = $repo->countByOwner($userId);
        $independentLimit = getIndependentBotLimit($db, $userId);
        $publicLimit      = getPublicBotLimit($db, $userId);

        // Madde 2 — sınırsız kota sayısal olarak PHP_INT_MAX; istemciye bu
        // devasa sayıyı göstermek yerine `*_limit: null` + `*_unlimited: true`
        // (eklemeli alan; mevcut alanların sonlu kotalarda anlamı aynı).
        $independentUnlimited = isUnlimitedBotLimit($independentLimit);
        $publicUnlimited      = isUnlimitedBotLimit($publicLimit);

        JsonResponse::success([
            'independent_used'       => $counts['independent'],
            'independent_limit'      => $independentUnlimited ? null : $independentLimit,
            'independent_unlimited'  => $independentUnlimited,
            'public_used'            => $counts['public'],
            'public_limit'           => $publicUnlimited ? null : $publicLimit,
            'public_unlimited'       => $publicUnlimited,
            'can_create_independent' => $counts['independent'] < $independentLimit,
            'can_create_public'      => $counts['public'] < $publicLimit,
            // Madde 11 — yayından kaldırma (özel yapma) hakkı, toplam.
            // privacy_used/remaining null = sayım yok (migration 012 eksik).
            'privacy_limit'          => getPrivacyRightLimit($db, $userId),
            'privacy_used'           => privacyUsageTableReady($db) ? countPrivacyRightUsage($db, $userId) : null,
            'privacy_remaining'      => privacyUsageTableReady($db) ? privacyRightsRemaining($db, $userId) : null,
        ]);
    }

    public static function getSuggested(): void {
        $userId = AuthMiddleware::optionalAuth();
        $limit  = InputSanitizer::positiveInt($_GET['limit'] ?? 3);

        if (!$userId) {
            JsonResponse::success(['bots' => []]);
        }

        $repo     = new ChatbotRepository();
        $cartRows = $repo->getCartCategoryIds($userId);

        if (empty($cartRows)) {
            JsonResponse::success(['bots' => []]);
        }

        $categoryIds = array_filter(array_unique(array_column($cartRows, 'kategori_id')));
        $excludeIds  = array_filter(array_column($cartRows, 'chatbot_id'));

        // A-03 — burada `profil_fotografi` `data:image/jpeg;base64,` ile
        // öneklenirdi, ama sütunda base64 değil `assets/…` göreli yolu var
        // (handleImageUploads dosyayı diske yazıyor). Sonuç bozuk bir data URI
        // ve kırık görseldi. Yol olduğu gibi dönüyor; görsel URL'sini kurmak
        // frontend'de tek bir yardımcının işi (shared/lib/image.js, F-01).
        $results = $repo->getSuggested($userId, $categoryIds, $excludeIds, $limit ?: 3);

        JsonResponse::success(['bots' => $results]);
    }

    public static function updateChatbotPrice(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;

        if (!$data || !isset($data['id'])) {
            JsonResponse::error('Veri veya Chatbot ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);
        }

        $id      = InputSanitizer::positiveInt($data['id']);
        $weekly  = InputSanitizer::price($data['ucret_haftalik'] ?? 0);
        // PAY-009: aynı türetme burada da — iki uç nokta aynı formülü paylaşıyor.
        $monthly = self::deriveMonthlyPrice($weekly);

        // InputSanitizer::price() only rejects negatives, not zero or
        // absurdly large values — this endpoint previously had no range
        // check at all (unlike publishChatbot's now-shared assertValidPrice),
        // so a seller could silently zero out or wildly overprice an
        // already-published bot via "Satış Listesine Ekle".
        self::assertValidPrice($weekly, 'Haftalık', AppConfig::MAX_WEEKLY_PRICE, AppConfig::MIN_WEEKLY_PRICE);
        self::assertValidPrice($monthly, 'Aylık', AppConfig::MAX_WEEKLY_PRICE * 4, round(AppConfig::MIN_WEEKLY_PRICE * 4 * AppConfig::DISCOUNT_MONTHLY_FACTOR));

        $repo = new ChatbotRepository();

        // Previously had no ownership check at all — anyone who knew a
        // chatbot's id could change its price.
        if (!$repo->findByIdAndOwner($id, $userId)) {
            JsonResponse::error('Bu chatbot üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        // N-08 / Faz 4 — fiyat yalnızca aktif pazaryeri satıcısı tarafından
        // atanabilir. Eskiden bu uçta satıcı kontrolü yoktu: satıcı olmayan
        // kullanıcı ücretsiz herkese açık botuna fiyat yazarak onu vitrinden
        // ve ücretsiz erişimden çıkarabiliyor, publishChatbot'un satıcı
        // şartını da dolaylı yoldan atlıyordu.
        if ($repo->getSellerStatus($userId) !== 'active') {
            JsonResponse::error('Fiyat belirlemek için pazaryeri kaydınızın onaylanmış olması gerekir.', 422, AppConfig::ERR_SELLER_INACTIVE);
        }

        $ok   = $repo->updatePrice($id, $weekly, $monthly);

        if ($ok) {
            JsonResponse::success(['message' => 'Fiyatlar başarıyla güncellendi!', 'id' => $id]);
        } else {
            JsonResponse::error('Güncelleme başarısız veya değişiklik yapılmadı.', 400);
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Single price-range check shared by publishChatbot and
     * updateChatbotPrice — previously each endpoint enforced a different
     * (or no) rule, so the same "set this bot's price" action behaved
     * inconsistently depending on which screen triggered it.
     *
     * The lower bound used to be a bare `$value <= 0`, so AppConfig's
     * MIN_WEEKLY_PRICE was documented and mirrored into pricing.js but read by
     * no PHP code at all — a 0,01 ₺ bot passed both layers. $min is now
     * explicit and scales the same way $max does for the monthly field.
     */
    private static function assertValidPrice(float $value, string $label, float $max, float $min): void {
        if ($value < $min || $value > $max) {
            JsonResponse::error(
                sprintf(
                    '%s fiyat en az %s₺, en fazla %s₺ olmalıdır.',
                    $label,
                    number_format($min, 2, ',', '.'),
                    number_format($max, 0, ',', '.')
                ),
                400, AppConfig::ERR_VALIDATION
            );
        }
    }

    private static function handleImageUploads(array $data): array {
        foreach (['coverImage_file' => 'kapak_fotografi', 'profileImage_file' => 'profil_fotografi'] as $postKey => $dbCol) {
            if (!isset($_FILES[$postKey]) || $_FILES[$postKey]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $file = $_FILES[$postKey];
            if ($file['size'] > AppConfig::MAX_UPLOAD_SIZE_BYTES) {
                JsonResponse::error('Dosya boyutu 5 MB\'ı aşamaz.', 400, AppConfig::ERR_VALIDATION);
            }
            $mime = InputSanitizer::detectMime($file['tmp_name']);
            if (!in_array($mime, AppConfig::ALLOWED_IMAGE_MIMES, true)) {
                JsonResponse::error('Geçersiz dosya türü. Sadece resim yükleyebilirsiniz.', 400, AppConfig::ERR_VALIDATION);
            }

            // Extension is derived from the verified MIME type, never the
            // client-supplied filename — see InputSanitizer::extensionForMime().
            $ext       = InputSanitizer::extensionForMime($mime);
            $uploadDir = __DIR__ . '/../../../assets/' . $dbCol;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
                $data[$dbCol] = 'assets/' . $dbCol . '/' . $fileName;
            }
        }
        return $data;
    }

    /**
     * PAY-009 🟡 — aylık fiyat yalnızca İSTEMCİDE haftalıktan türetiliyordu.
     *
     * `web/src/shared/lib/pricing.js:36` şunu yapıyor:
     *     Math.round(weekly * 4 * MONTHLY_DISCOUNT_FACTOR)
     * Sunucu ise iki fiyatı bağımsız kabul ediyor, yalnızca ayrı ayrı
     * aralık kontrolü yapıyordu. Yani satıcı haftalık ₺100 / aylık ₺1
     * gönderebiliyordu: dört haftalık abonelik ₺400 yerine ₺1'e satılırdı.
     * `linePrice()` `duration_weeks >= 4` için aylık fiyatı kullandığından
     * bu doğrudan gelir kaybı.
     *
     * Aylık fiyat artık sunucuda türetiliyor ve istemcinin gönderdiği değer
     * yok sayılıyor — formül AppConfig'de, pricing.js ile aynı sabiti
     * paylaşıyor.
     */
    private static function deriveMonthlyPrice(float $weekly): float {
        return InputSanitizer::price(
            round($weekly * 4 * AppConfig::DISCOUNT_MONTHLY_FACTOR, 2)
        );
    }
}
