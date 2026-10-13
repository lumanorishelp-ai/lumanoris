<?php
class MarketplaceController {
    public static function addToCart(): void {
        require_method('POST');
        $userId     = AuthMiddleware::requireAuth();
        $data       = json_decode($_POST['data'] ?? '', true) ?? null;
        $chatbotId  = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        $orderWeeks = InputSanitizer::positiveInt($data['order_weeks'] ?? 0) ?: null;

        if (!$data || !$chatbotId) {
            JsonResponse::error('Eksik parametreler!', 400, AppConfig::ERR_VALIDATION);
        }

        $db          = Database::getInstance();

        // Faz 4 / E8 — ücretsiz herkese açık bot (iki fiyat da boş/0) satın
        // alınmaz: herkes zaten sohbet edebilir. Bu kapı yokken satıcısı aktif
        // bir yazarın fiyatsız botu sepete girip 0 ₺'lik abonelik/sipariş
        // üretebiliyordu. Satıcı kontrolünden ÖNCE: ücretsiz bot için doğru
        // mesaj "satıcı kaydı eksik" değil, "bu bot ücretsiz".
        $priceRow = $db->selectSingle(
            'COALESCE(ucret_haftalik, 0) AS weekly, COALESCE(ucret_aylik, 0) AS monthly
             FROM chatbotlar WHERE id = ? AND is_independent = 0',
            [$chatbotId]
        );
        if ($priceRow && (float) $priceRow['weekly'] <= 0 && (float) $priceRow['monthly'] <= 0) {
            JsonResponse::error('Bu chatbot ücretsiz; satın almadan sohbet edebilirsiniz.', 422, AppConfig::ERR_VALIDATION);
        }

        $sellerCheck = $db->selectSingle(
            "pms.status FROM param_marketplace_sellers pms
             JOIN chatbotlar c ON c.author_user_id = pms.user_id
             WHERE c.id = ? AND c.is_independent = 0",
            [$chatbotId]
        );
        if (!$sellerCheck || ($sellerCheck['status'] ?? '') !== 'active') {
            JsonResponse::error('Bu chatbot şu anda satışa kapalı. Satıcısı henüz pazaryeri kaydını tamamlamamış.', 422, AppConfig::ERR_SELLER_INACTIVE);
        }

        // COMP-005 — kendi botunu satın alma yasağı, birinci kapı.
        //
        // Bu kontrol gelmeden önce satın alma yolunda alıcı ile botun yazarını
        // karşılaştıran TEK BİR satır yoktu. Açığın parasal hâli:
        // kullanıcı bot oluşturur, MAX_WEEKLY_PRICE (5.000 ₺) fiyat verir,
        // ikinci hesabından ya da çalıntı kartla satın alır; satıcı payı
        // (SELLER_COMMISSION_WEEKLY = %85) bakiyesine yazılır ve IBAN'a
        // çekilir — yani kart nakde çevrilmiş olur. Teslim edilen "ürün"
        // dijital olduğu için işlem normal bir satıştan ayırt edilemez.
        //
        // `author_user_id` KULLANILIYOR, `owner_user_id` değil: satın alma
        // sonrası `owner_user_id` alıcıya geçebiliyor; yasak, botu üreten ve
        // satıştan pay alan kişiye bağlanmak zorunda.
        //
        // Bu kapı tek başına YETMEZ — `createSubscription()` sepeti kendi
        // okuyor ve buradan geçmiş eski sepet satırlarını da işliyor. İkinci
        // kapı orada, aynı ID ile.
        self::assertNotOwnBot($db, $chatbotId, $userId);

        try {
            $id = $db->insert('user_cart', ['user_id' => $userId, 'chatbot_id' => $chatbotId, 'order_weeks' => $orderWeeks]);
            JsonResponse::success(['message' => 'Ürün sepete eklendi', 'id' => $id]);
        } catch (Exception $e) {
            // B-08 — duplicate DIŞINDAKİ her PDO hatası ham `getMessage()` ile
            // istemciye dönüyordu: tablo/sütun adları, SQLSTATE kodları, bazı
            // kurulumlarda sorgu parçaları. Ayrıca hepsi 409 ile dönüyordu,
            // yani gerçek sunucu hatası "çakışma" gibi görünüyordu.
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                JsonResponse::error('Bu chatbot zaten sepetinizde bulunuyor.', 409, AppConfig::ERR_DUPLICATE);
            }
            error_log('[addtocart] ' . $e->getMessage());
            JsonResponse::error('Ürün sepete eklenemedi, lütfen tekrar deneyin.', 500, AppConfig::ERR_SERVER);
        }
    }

    /**
     * COMP-005 — alıcı, botun yazarı olamaz.
     *
     * `addToCart()` ve `createSubscription()` aynı kuralı uygulamak zorunda,
     * bu yüzden tek yerde: iki ayrı kopya er ya da geç ayrışır ve ayrışan
     * kopya sessizce açık bırakır.
     *
     * Bot bulunamazsa sessiz geçiyor — "yok" durumunu ele almak bu metodun
     * işi değil; iki çağıran da kendi bağlamında zaten `!$bot` dalını
     * işletiyor ve burada ikinci bir hata mesajı üretmek onların akışını
     * bozardı.
     *
     * @param callable|null $onViolation İhlalde JsonResponse'tan ÖNCE
     *        çalıştırılacak temizlik (checkout'ta açık transaction'ın
     *        rollback'i). `JsonResponse::error()` exit ettiği için sonrasında
     *        hiçbir şey çalışmaz — geri alma bu yüzden ÖNCE yapılmalı.
     */
    private static function assertNotOwnBot(
        Database $db,
        int $chatbotId,
        int $userId,
        ?callable $onViolation = null
    ): void {
        $bot = $db->selectSingle('author_user_id FROM chatbotlar WHERE id = ?', [$chatbotId]);
        if (!$bot) {
            return;
        }

        if ((int) $bot['author_user_id'] !== $userId) {
            return;
        }

        if ($onViolation !== null) {
            $onViolation();
        }

        JsonResponse::error(
            'Kendi oluşturduğunuz bir botu satın alamazsınız.',
            422,
            AppConfig::ERR_VALIDATION
        );
    }

    /**
     * Single source of truth for what one cart line actually costs. getCart()
     * (what checkout displays) and createSubscription() (what gets charged and
     * written to param_marketplace_payments) both go through this, so the two
     * can no longer drift apart — the audit measured 216,00 TL shown against
     * 135,00 TL charged for the very same cart.
     *
     * ucret_aylik already carries the monthly discount: it is generated by
     * pricing.js deriveMonthlyPrice() as round(weekly * 4 * 0.9) at the moment
     * the seller sets a price. Applying DISCOUNT_MONTHLY_FACTOR again here
     * charged the 10% twice (effective weekly * 3.24 instead of the intended
     * weekly * 3.6), so the stored monthly price is now used as-is.
     */
    /**
     * param_marketplace_payments comes from a ParamPos-oriented schema and
     * installations differ in which of its optional columns exist. An optional
     * column is therefore written only where it is actually present — a missing
     * one must never be able to break checkout.
     */
    private static function paymentsColumnExists(Database $db, string $column): bool {
        static $cache = [];
        if (!array_key_exists($column, $cache)) {
            $row = $db->selectSingle(
                "COUNT(*) AS cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'param_marketplace_payments'
                   AND column_name = ?",
                [$column]
            );
            $cache[$column] = (int) ($row['cnt'] ?? 0) > 0;
        }
        return $cache[$column];
    }

    /** PAY-004: aynı kontrolün param_marketplace_details karşılığı. */
    private static function detailsColumnExists(Database $db, string $column): bool {
        static $cache = [];
        if (!array_key_exists($column, $cache)) {
            $row = $db->selectSingle(
                "COUNT(*) AS cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'param_marketplace_details'
                   AND column_name = ?",
                [$column]
            );
            $cache[$column] = (int) ($row['cnt'] ?? 0) > 0;
        }
        return $cache[$column];
    }

    private static function linePrice(float $weekly, float $monthly, int $durationWeeks): float {
        $price = $durationWeeks >= 4 ? $monthly : $weekly * $durationWeeks;
        return InputSanitizer::price($price);
    }

    public static function getCart(): void {
        $userId = AuthMiddleware::requireAuth();

        $results = Database::getInstance()->selectMulti(
            "uc.id, uc.chatbot_id, c.isim as title, c.profil_fotografi as image,
             c.ucret_haftalik as price, c.ucret_aylik as monthlyPrice,
             uc.order_weeks, ck.kategori_adi_tr as category
             FROM user_cart uc
             JOIN chatbotlar c ON uc.chatbot_id = c.id
             LEFT JOIN chatbot_kategoriler ck ON c.kategori_id = ck.id
             WHERE uc.user_id = ?",
            [$userId]
        );

        // Hand the client finished per-line and cart totals rather than letting
        // it re-derive them from the weekly price — that independent second
        // calculation is what made checkout display a number the server never
        // charged.
        $cart  = [];
        $total = 0.0;
        foreach ($results as $row) {
            $weeks = InputSanitizer::positiveInt($row['order_weeks'] ?? 0) ?: 4;
            $row['lineTotal'] = self::linePrice(
                (float) $row['price'],
                (float) $row['monthlyPrice'],
                $weeks
            );
            $total += $row['lineTotal'];
            $cart[] = $row;
        }

        JsonResponse::success([
            'cart'  => $cart,
            'total' => InputSanitizer::price($total),
        ]);
    }

    public static function getCartCount(): void {
        $userId = AuthMiddleware::requireAuth();

        $row = Database::getInstance()->selectSingle('COUNT(*) AS total FROM user_cart WHERE user_id = ?', [$userId]);
        JsonResponse::success(['count' => (int) ($row['total'] ?? 0)]);
    }

    public static function deleteCart(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $id     = InputSanitizer::positiveInt($data['id'] ?? $_POST['id'] ?? 0);
        if (!$id) JsonResponse::error('ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // Previously had no ownership check — anyone could remove items from
        // any user's cart by guessing the cart row id.
        Database::getInstance()->delete('user_cart', 'id = ? AND user_id = ?', [$id, $userId]);
        JsonResponse::success(['message' => 'Sepetten kaldırıldı.']);
    }

    public static function updateCart(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || !isset($data['id'])) JsonResponse::error('Veri veya ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $id = InputSanitizer::positiveInt($data['id']);
        unset($data['id'], $data['user_id']);

        // SEC-014: geriye kalan her anahtar doğrudan UPDATE'e gidiyordu.
        // Sepet satırında meşru olarak değişebilecek tek şey süre; `chatbot_id`
        // istemciden yazılabilseydi kullanıcı sepete ucuz bir botu ekleyip
        // ödeme öncesi pahalısıyla değiştirebilirdi.
        [$data, $rejected] = InputSanitizer::pickAllowed($data, ['order_weeks']);
        if ($rejected !== []) {
            JsonResponse::error(
                'Bu alanlar güncellenemez: ' . implode(', ', $rejected),
                403,
                AppConfig::ERR_PERMISSION
            );
        }
        if ($data === []) JsonResponse::error('Güncellenecek alan yok.', 400, AppConfig::ERR_VALIDATION);

        $weeks = InputSanitizer::positiveInt($data['order_weeks'] ?? 0);
        if ($weeks < 1 || $weeks > 52) {
            JsonResponse::error('Süre 1-52 hafta aralığında olmalı.', 400, AppConfig::ERR_VALIDATION);
        }
        $data['order_weeks'] = $weeks;

        // Previously had no ownership check — anyone could edit any cart row by id.
        Database::getInstance()->update('user_cart', $data, 'id = ? AND user_id = ?', [$id, $userId]);
        JsonResponse::success(['message' => 'Sepet güncellendi.', 'id' => $id]);
    }

    /**
     * No frontend caller exists (the real purchase flow is createSubscription,
     * which grants time-limited access — it never transfers ownership). This
     * method used to reassign chatbotlar.owner_user_id to whoever called it
     * for any chatbot_ids, with no payment, cart, or for-sale check at all —
     * a live, session-authenticated "steal any bot for free" endpoint. There
     * being no legitimate ownership-transfer flow to preserve, the endpoint
     * is disabled outright rather than patched.
     */
    public static function buyChatbot(): void {
        JsonResponse::error('Bu işlem artık desteklenmiyor.', 410, AppConfig::ERR_VALIDATION);
    }

    /**
     * PAY-008 🟡 — idempotency anahtarı, eşzamanlılık kilidi ve rate limit
     * yoktu. Aynı sepet iki kez gönderildiğinde (çift tıklama, ağ yeniden
     * denemesi, tarayıcı yenilemesi) iki abonelik ve iki ödeme satırı
     * oluşuyordu — yani müşteri iki kez ücretlendirilirdi.
     *
     * Üç katman eklendi:
     *   1. `GET_LOCK` — aynı kullanıcının eşzamanlı checkout'ları sıraya
     *      giriyor. `withdraw()` aynı deseni zaten kullanıyor.
     *   2. Idempotency anahtarı — istemci gönderirse o kullanılıyor, yoksa
     *      sepet içeriğinden türetiliyor. Aynı anahtarla gelen ikinci istek
     *      yeni satır yazmıyor, İLK siparişin sonucunu döndürüyor.
     *   3. `checkRateLimit` — anahtarı her seferinde değiştiren bir istemci
     *      idempotency'yi atlatabilir; bu taban koruma.
     */
    /**
     * I-01 — tamamlanmış bir siparişin idempotency anahtarının ne kadar
     * süre "aynı sipariş" sayılacağı.
     *
     * Amaç çift gönderimi yakalamak: çift tıklama, ağ yeniden denemesi,
     * kullanıcının ödeme sonrası sayfayı yenilemesi. Bunların hepsi
     * saniyeler-dakikalar mertebesinde olur. Süresiz tutmak ise I-01'in ta
     * kendisiydi — aynı botu aynı süreyle YENİDEN satın almayı (abonelik
     * yenilemesini) kalıcı olarak engelliyordu.
     */
    private const IDEMPOTENCY_REPLAY_SECONDS = 900;    // 15 dakika

    /**
     * Sonucu belirsiz kalmış rezervasyonun azami ömrü. Mutabakat
     * (`reconcilePayments`) normalde bunu çok daha önce çözer; bu yalnızca
     * mutabakatın hiç çalışmadığı bir kurulumda kullanıcının kalıcı olarak
     * kilitli kalmasını önleyen son emniyet.
     */
    private const IDEMPOTENCY_HOLD_SECONDS = 172800;   // 48 saat

    /**
     * I-01 — süresi dolmuş idempotency satırlarını temizler.
     *
     * Tamamlanmış siparişler tekrar penceresinden sonra, belirsiz kalmış
     * rezervasyonlar ise tutma süresinden sonra düşer. `rate_limit.php`
     * ile aynı desen: ayrı bir cron gerekmiyor, yaklaşık her 20 checkout'ta
     * bir çalışıyor.
     */
    private static function purgeStaleIdempotency(Database $db): void {
        if (random_int(1, 20) !== 1) {
            return;
        }
        try {
            $db->execute(
                'DELETE FROM checkout_idempotency
                 WHERE (order_id IS NOT NULL AND created_at < (NOW() - INTERVAL ? SECOND))
                    OR (order_id IS NULL     AND created_at < (NOW() - INTERVAL ? SECOND))',
                [self::IDEMPOTENCY_REPLAY_SECONDS, self::IDEMPOTENCY_HOLD_SECONDS]
            );
        } catch (Throwable $e) {
            error_log('[checkout] idempotency temizliği başarısız: ' . $e->getMessage());
        }
    }

    /**
     * I-01 — tahsilat sonucu BELİRSİZ mi (para çekilmiş olabilir mi)?
     *
     * `IyzicoClient::request()` ulaşılamama ve ayrıştırılamayan yanıt
     * durumlarını ayrı kodluyor; geri kalan her şey sağlayıcının verdiği
     * KESİN bir cevaptır (kart reddi vb.), yani para çekilmemiştir.
     * Yapılandırma eksikse istek hiç gönderilmemiştir.
     */
    /**
     * D-04 — `pending` ödeme satırının sonucunu yazar.
     *
     * Yalnızca kurulumda GERÇEKTEN VAR OLAN sütunlar yazılıyor
     * (`paymentsColumnExists`): `createSubscription`'ın başarı yolu aynı
     * korumayı kullanıyor, bu yol da kullanmazsa opsiyonel sütunu olmayan
     * bir kurulumda hata dalı SQL hatası verirdi — yani asıl hatanın
     * üstüne ikinci bir hata binerdi.
     *
     * Hiçbir istisna dışarı sızmıyor: bu her zaman bir hata yolundan
     * çağrılıyor ve buradaki bir sorun asıl hatayı gölgelememeli.
     */
    private static function finalizePendingPayment(Database $db, int $paymentId, array $columns): void {
        if ($paymentId <= 0) {
            return;
        }
        try {
            $row = [];
            foreach ($columns as $column => $value) {
                if (in_array($column, ['status', 'amount'], true) || self::paymentsColumnExists($db, $column)) {
                    $row[$column] = $value;
                }
            }
            if ($row !== []) {
                $db->update('param_marketplace_payments', $row, 'id = ?', [$paymentId]);
            }
        } catch (Throwable $e) {
            error_log('[checkout] pending ödeme satırı güncellenemedi id=' . $paymentId . ': ' . $e->getMessage());
        }
    }

    private static function chargeOutcomeIsIndeterminate(array $chargeResult): bool {
        return in_array(
            (string) ($chargeResult['error_code'] ?? ''),
            ['TRANSPORT_ERROR', 'BAD_RESPONSE'],
            true
        );
    }

    public static function createSubscription(): void {
        require_method('POST');
        require_once __DIR__ . '/../../../functions/coin_engine.php';
        require_once __DIR__ . '/../../../functions/checkout_payments.php';
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || empty($data['items']) || !is_array($data['items'])) {
            JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);
        }

        $db   = Database::getInstance();
        $conn = $db->getConnection();

        // (3) Taban : dakikada 5 checkout denemesi.
        checkRateLimit($db, 'checkout:' . $userId, 5, 60);

        // (2) Idempotency anahtarı. İstemci göndermezse sepet içeriğinden
        // türetiyoruz — böylece "aynı sepet" iki kez gönderildiğinde anahtar
        // da aynı oluyor ve ikinci istek yakalanıyor.
        $idemRaw = (string) ($data['idempotency_key'] ?? '');
        if (trim($idemRaw) === '') {
            $fingerprint = [];
            foreach ($data['items'] as $it) {
                $fingerprint[] = (int) ($it['chatbot_id'] ?? 0) . ':' . (int) ($it['duration_weeks'] ?? 0);
            }
            sort($fingerprint);
            $idemRaw = 'cart:' . implode('|', $fingerprint);
        }
        $idempotencyKey = hash('sha256', $userId . '|' . $idemRaw);

        $db->ensureTable('checkout_idempotency', 'CREATE TABLE IF NOT EXISTS checkout_idempotency (
                idem_key   CHAR(64) PRIMARY KEY,
                user_id    INT NOT NULL,
                order_id   VARCHAR(32) NULL,
                created_at DATETIME NOT NULL,
                INDEX (user_id)
            )');

        // I-01 — süresi dolmuş kayıtları fırsat buldukça temizle. Tablo
        // eskiden hiç küçülmüyordu; artık her satırın bir ömrü var.
        self::purgeStaleIdempotency($db);

        // (1) Aynı kullanıcının eşzamanlı checkout'ları sıraya girsin.
        $lockName = 'checkout_user_' . $userId;
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS locked');
        $lockStmt->execute([$lockName]);
        if ((int) ($lockStmt->fetch()['locked'] ?? 0) !== 1) {
            JsonResponse::error('İşlem şu anda gerçekleştirilemiyor, lütfen tekrar deneyin.', 409, AppConfig::ERR_VALIDATION);
        }

        $releaseLock = static function () use ($conn, $lockName): void {
            try { $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) {}
        };

        // Kilidi aldıktan SONRA bakıyoruz: yarışı kaybeden istek burada
        // ilkinin yazdığı satırı görür.
        //
        // I-01 — bu blok eskiden "satır varsa reddet" diyordu ve satır
        // BAŞARIDA HİÇ SİLİNMİYORDU. Sonuç: kullanıcı 42 numaralı botu bir
        // haftalığına aldıktan sonra AYNI botu AYNI süreyle bir daha
        // alamıyordu — istek `repeated: true` ve eski `order_id` ile
        // dönüyor, yeni abonelik yazılmıyor, para da çekilmiyordu. Yani
        // abonelik yenileme pratikte kırıktı; farklı bir süre seçilince
        // anahtar değiştiği için çalışıyor olması hatayı gizliyordu.
        //
        // Satırın artık üç anlamı var ve üçü de ömürlü:
        //   • order_id DOLU  → tamamlanmış sipariş. Yalnızca kısa bir
        //     tekrar penceresi boyunca aynı yanıtı verir (çift tıklama,
        //     ağ yeniden denemesi, tarayıcı yenilemesi). Pencere geçince
        //     satır silinir ve YENİ bir satın alma serbesttir.
        //   • order_id BOŞ   → sonucu BELİRSİZ kalmış bir deneme
        //     (sağlayıcıya ulaşılamadı). Para çekilmiş olabilir, o yüzden
        //     tekrar denemeye izin YOK; mutabakat çözene kadar bekliyor.
        //   • satır yok      → serbest.
        $existing = $db->selectSingle(
            'order_id, created_at,
             (created_at > (NOW() - INTERVAL ? SECOND)) AS taze
             FROM checkout_idempotency WHERE idem_key = ?',
            [self::IDEMPOTENCY_REPLAY_SECONDS, $idempotencyKey]
        );

        if ($existing) {
            $completed = ($existing['order_id'] ?? null) !== null;

            if ($completed && (int) $existing['taze'] === 1) {
                // Aynı siparişin tekrarı — ilk siparişin sonucunu döndür.
                $releaseLock();
                JsonResponse::success([
                    'message'  => 'Bu sipariş zaten oluşturulmuş.',
                    'order_id' => $existing['order_id'],
                    'ids'      => [],
                    'repeated' => true,
                ]);
            }

            if (!$completed) {
                // Belirsiz deneme. `order_id` yazılamamış, yani tahsilatın
                // sonucunu bilmiyoruz. Kullanıcıyı ikinci kez çektirmektense
                // bekletmek doğru olan; mutabakat çalıştığında satır
                // temizlenir (bkz. releaseIndeterminateHold()).
                $releaseLock();
                JsonResponse::error(
                    'Önceki ödemenizin sonucu hâlâ doğrulanıyor. Lütfen birkaç dakika sonra '
                    . '"Ödemelerim" sayfasını kontrol edin; tahsilat gerçekleşmediyse tekrar deneyebilirsiniz.',
                    409,
                    AppConfig::ERR_PAYMENT
                );
            }

            // Tamamlanmış ama tekrar penceresi geçmiş: bu artık YENİ bir
            // satın alma (yenileme). Eski satırı bırak, aşağıdan devam et.
            $db->execute('DELETE FROM checkout_idempotency WHERE idem_key = ?', [$idempotencyKey]);
        }

        // Anahtarı ŞİMDİ rezerve ediyoruz (order_id sonra doldurulacak) —
        // böylece kilit serbest bırakıldıktan sonra gelen bir istek de
        // yakalanıyor.
        try {
            $db->execute(
                'INSERT INTO checkout_idempotency (idem_key, user_id, created_at) VALUES (?, ?, NOW())',
                [$idempotencyKey, $userId]
            );
        } catch (Throwable $e) {
            $releaseLock();
            JsonResponse::error('Bu sipariş zaten işleniyor.', 409, AppConfig::ERR_DUPLICATE);
        }

        // Bu noktadan sonraki doğrulama hataları `JsonResponse::error()` ile
        // exit ediyor — yani aşağıdaki catch bloğu ÇALIŞMIYOR ve rezervasyon
        // asılı kalıyordu. Asılı kalan rezervasyon, kullanıcının aynı sepeti
        // düzeltip tekrar göndermesini kalıcı olarak engellerdi
        // ("Bu sipariş zaten oluşturulmuş" ama ortada sipariş yok).
        //
        // Shutdown kancası, çıkış yolu ne olursa olsun tamamlanmamış
        // rezervasyonu siliyor. `$orderCompleted` başarı yolunda true olur.
        //
        // I-01 — `$keepReservation` yeni: tahsilatın sonucu BELİRSİZ
        // kaldığında (sağlayıcıya ulaşılamadı) rezervasyon BİLEREK
        // bırakılıyor. Silmek, kullanıcının "tekrar dene"ye basıp ikinci kez
        // çekilme riskini açardı; para çekilip çekilmediğini bilmiyoruz.
        $orderCompleted  = false;
        $keepReservation = false;
        register_shutdown_function(static function () use ($db, $idempotencyKey, &$orderCompleted, &$keepReservation): void {
            if ($orderCompleted || $keepReservation) {
                return;
            }
            try {
                $db->execute(
                    'DELETE FROM checkout_idempotency WHERE idem_key = ? AND order_id IS NULL',
                    [$idempotencyKey]
                );
            } catch (Throwable $e) {
                error_log('[checkout] asılı idempotency rezervasyonu silinemedi: ' . $e->getMessage());
            }
        });

        $subscriptionIds = [];
        $detailRows      = [];
        $itemRows        = [];
        $totalAmount     = 0.0;

        // Transaction'ın İÇİNDE atanıyorlar ama catch bloğunun da okuması
        // gerekiyor: tahsilat başarılı olup ardından bir şey patlarsa
        // müşterinin parasını iade edebilmek için elimizde sağlayıcı
        // kimliği olmak zorunda.
        $orderId        = '';
        $gatewayPayment = '';

        // Anchor expiry math to the DB server's clock, not PHP's — the app
        // server and DB server can run in different timezones (seen locally:
        // PHP=UTC, MySQL=UTC+3), and computing "+N days" from PHP's time()
        // then later comparing against MySQL's NOW() would skew every expiry.
        $mysqlNowRow = $db->selectSingle('NOW() AS now_time');
        $mysqlNow    = strtotime($mysqlNowRow['now_time']);

        // The loop below does several inserts/deletes per cart item with no
        // transaction — a failure partway through (e.g. item 3 of 5) used to
        // leave prior items fully purchased/removed from cart while later
        // ones silently never happened, with no way to retry cleanly.
        // D-04 — ödeme satırı tahsilattan ÖNCE, transaction'ın DIŞINDA
        // yazılıyor.
        //
        // Eskiden satır yalnızca tahsilat başarılı olduktan SONRA
        // yazılıyordu. Zaman aşımında `$conn->rollBack()` çalışıyor ve
        // HİÇBİR `param_marketplace_payments` satırı kalmıyordu —
        // `reconcilePayments()` ise yalnızca o tabloyu tarıyor. Yani
        // "belirsiz kalmış tahsilat" tam olarak mutabakatın bulamayacağı
        // yerde kayboluyordu; kodun kendi yorumu (order_id'nin tahsilattan
        // önce üretilme gerekçesi) davranışıyla çelişiyordu.
        //
        // Satır transaction'dan ÖNCE açıldığı için rollback onu silmiyor.
        // `status` ve `amount` sütunlarının şemadaki varsayılanları
        // ('pending', 0.00) zaten bu akış için tasarlanmış.
        $orderId   = 'ORD-' . strtoupper(InputSanitizer::randomToken(4));
        $paymentId = (int) $db->insert('param_marketplace_payments', [
            'order_id' => $orderId,
            'user_id'  => $userId,
            'status'   => 'pending',
            'amount'   => 0,
        ]);

        /**
         * D-04 — transaction içindeki doğrulama hatalarının ortak çıkışı.
         *
         * Bu dallar `rollBack()` + `JsonResponse::error()` (exit) yapıyordu;
         * artık ödeme satırı tahsilattan ÖNCE yazıldığı için geride
         * `pending` bir satır kalırdı ve mutabakat onu boşuna sağlayıcıya
         * sorardı. Tahsilat hiç denenmediğinden doğru durum doğrudan
         * `failed`.
         */
        $failCheckout = static function (string $message, string $errorCode) use ($conn, $db, &$paymentId, $releaseLock): void {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            self::finalizePendingPayment($db, $paymentId, ['status' => 'failed']);
            $releaseLock();
            JsonResponse::error($message, 422, $errorCode);
        };

        $conn->beginTransaction();
        try {
        foreach ($data['items'] as $item) {
            $chatbotId     = InputSanitizer::positiveInt($item['chatbot_id'] ?? 0);
            $durationWeeks = InputSanitizer::positiveInt($item['duration_weeks'] ?? 0) ?: 4;
            if (!$chatbotId) continue;

            // createSubscription is reachable directly with a hand-built items[]
            // payload, so it cannot rely on addToCart having vetted the bot —
            // every rule addToCart enforces has to be re-checked here. Skipping
            // that is how a NULL-priced independent bot bought itself: (float)
            // null === 0.0, so $price fell to 0,00, chargeCard() accepted a
            // zero amount, and the buyer still got a status=1 subscription plus
            // a "paid" payment row (observed: order ORD-2041EEC4, 0,00 TL).
            $bot = $db->selectSingle(
                "c.author_user_id, c.isim, c.ucret_haftalik, c.ucret_aylik, c.is_independent,
                 pms.status AS seller_status
                 FROM chatbotlar c
                 LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id
                 WHERE c.id = ?",
                [$chatbotId]
            );
            if (!$bot) continue;

            if ((int) $bot['is_independent'] === 1) {
                $failCheckout('Bu chatbot pazaryerinde satışa açık değil.', AppConfig::ERR_VALIDATION);
            }

            if (($bot['seller_status'] ?? '') !== 'active') {
                $failCheckout('Bu chatbot şu anda satışa kapalı. Satıcısı henüz pazaryeri kaydını tamamlamamış.', AppConfig::ERR_SELLER_INACTIVE);
            }

            // COMP-005 — kendi botunu satın alma yasağının İKİNCİ kapısı.
            //
            // `addToCart()` bunu zaten reddediyor, ama bu kontrolün burada da
            // olması şart: bu döngü sepeti veritabanından yeniden okuyor, yani
            // sepete yama öncesinde girmiş satırlar ve `user_cart`'a başka bir
            // yoldan yazılmış her kayıt buradan geçer. Yukarıdaki yorumun
            // dediği gibi "addToCart'ın uyguladığı her kural burada yeniden
            // kontrol edilmek zorunda".
            //
            // `assertNotOwnBot()` çağrılmıyor: `$bot` zaten `author_user_id`
            // taşıyor, ikinci bir sorgu gereksiz olurdu. Kural aynı kural.
            if ((int) $bot['author_user_id'] === $userId) {
                $failCheckout('Kendi oluşturduğunuz bir botu satın alamazsınız.', AppConfig::ERR_VALIDATION);
            }

            $isMonthly = $durationWeeks >= 4;
            $price     = self::linePrice(
                (float) $bot['ucret_haftalik'],
                (float) $bot['ucret_aylik'],
                $durationWeeks
            );

            // An absent price is an unpriced bot, not a free one — refuse it
            // rather than recording a 0,00 TL sale.
            //
            // N-16 (Faz 4) — bu kapı fiyatı SONRADAN kaldırılmış (ücretsiz
            // herkese açık olmuş, ör. 015 sonrası platform botu) ve eski bir
            // sepet satırında kalmış botu da zaten durduruyordu; yalnızca mesaj
            // kullanıcıya ne yapacağını söylemiyordu.
            if ($price <= 0) {
                $isFreePublic = (int) $bot['is_independent'] === 0
                    && (float) $bot['ucret_haftalik'] <= 0
                    && (float) $bot['ucret_aylik'] <= 0;
                $failCheckout(
                    $isFreePublic
                        ? sprintf('"%s" artık ücretsiz; satın almadan sohbet edebilirsiniz. Lütfen sepetinizden çıkarın.', $bot['isim'])
                        : 'Bu chatbot için geçerli bir fiyat tanımlanmamış.',
                    AppConfig::ERR_VALIDATION
                );
            }

            $days       = $isMonthly ? AppConfig::SUBSCRIPTION_MONTHLY : $durationWeeks * AppConfig::SUBSCRIPTION_WEEKLY;
            $expiryDate = date('Y-m-d H:i:s', $mysqlNow + $days * 86400);

            $subscriptionIds[] = $db->insert('user_subscriptions', [
                'user_id'        => $userId,
                'chatbot_id'     => $chatbotId,
                'duration_weeks' => $durationWeeks,
                'expiry_date'    => $expiryDate,
                'status'         => 1,
            ]);

            $commissionRate = $isMonthly ? AppConfig::SELLER_COMMISSION_MONTHLY : AppConfig::SELLER_COMMISSION_WEEKLY;
            $detailRows[] = [
                'seller_user_id' => (int) $bot['author_user_id'],
                'chatbot_id'     => $chatbotId,
                'payable_amount' => InputSanitizer::price($price * $commissionRate),
            ];
            $itemRows[] = [
                'chatbot_id'     => $chatbotId,
                'name'           => (string) $bot['isim'],
                'duration_weeks' => $durationWeeks,
                'billing_period' => $isMonthly ? 'monthly' : 'weekly',
                'unit_price'     => InputSanitizer::price($isMonthly ? (float) $bot['ucret_aylik'] : (float) $bot['ucret_haftalik']),
                'line_total'     => $price,
            ];
            $totalAmount += $price;

            // Bonus message credits for this bot, advertised in the buy popup
            // (BuyModal.jsx) but previously never actually granted.
            grantPurchaseCredit($db, $userId, $chatbotId, $price, $expiryDate);

            $db->delete('user_cart', 'chatbot_id = ? AND user_id = ?', [$chatbotId, $userId]);
        }

        if (empty($subscriptionIds)) {
            $failCheckout('Geçerli ürün bulunamadı.', AppConfig::ERR_VALIDATION);
        }

        // Root-cause fix: this used to never look at $data['card'] at all —
        // any chatbot_id/duration_weeks pair succeeded with no card data
        // whatsoever. Card is validated (and, in production, actually
        // charged) only now that the real amount is known — a failure here
        // rolls back every subscription/credit/cart-delete above, so no
        // access is ever granted without a valid card.
        // order_id, tahsilattan ÖNCE üretiliyor. iyzico'ya `conversationId`
        // olarak gidiyor ve mutabakat (reconcilePayments) belirsiz kalmış bir
        // tahsilatı sağlayıcıda YALNIZCA bu kimlikle bulabiliyor. Sonradan
        // üretilseydi, zaman aşımına uğrayan bir tahsilat için elimizde
        // sağlayıcıya sorulabilecek hiçbir referans olmazdı — yani para
        // çekilmiş olup olmadığını asla öğrenemezdik.
        //
        // D-04: artık yalnızca kimlik değil, o kimliği taşıyan `pending`
        // ödeme SATIRI da tahsilattan önce yazılmış durumda (bkz. yukarısı).

        // iyzico buyer bloğu zorunlu ve boş alan kabul etmiyor; kullanıcının
        // kendi hesap bilgisinden dolduruluyor.
        $buyerRow = $db->selectSingle(
            'id, ad_soyad, kullanici_adi, eposta, telefon FROM kullanicilar WHERE id = ?',
            [$userId]
        ) ?: ['id' => $userId];

        $card         = is_array($data['card'] ?? null) ? $data['card'] : [];
        $chargeResult = chargeCard($card, $totalAmount, [
            'order_id'      => $orderId,
            'user'          => $buyerRow,
            'user_id'       => $userId,
            'ip'            => clientIp(),
            'payment_group' => 'PRODUCT',
            'items'         => array_map(static function (array $row): array {
                return [
                    'id'       => $row['chatbot_id'],
                    'name'     => $row['name'],
                    'category' => 'Chatbot Aboneliği',
                    'price'    => $row['line_total'],
                ];
            }, $itemRows),
        ]);
        if (!$chargeResult['success']) {
            $conn->rollBack();

            // I-01 — iki hata sınıfını ayır.
            //
            // KESİN RET (kart reddedildi, kart bilgisi geçersiz, sağlayıcı
            // yapılandırılmamış): para KESİNLİKLE çekilmedi. Rezervasyon
            // silinir, kullanıcı kartını düzeltip aynı sepeti tekrar
            // gönderebilir.
            //
            // BELİRSİZ (sağlayıcıya hiç ulaşılamadı ya da anlamsız yanıt):
            // tahsilat yapılmış OLABİLİR. Rezervasyon KALIR; aynı sepetin
            // tekrar gönderilmesi yukarıdaki "doğrulanıyor" dalına düşer,
            // yani çift tahsilat olmaz. `reconcilePayments()` sağlayıcıya
            // sorup durumu kesinleştirince rezervasyon serbest bırakılır.
            $keepReservation = self::chargeOutcomeIsIndeterminate($chargeResult);

            // D-04 — rollback transaction'ı bitirdi, bundan sonraki yazmalar
            // autocommit. `pending` ödeme satırı ayakta; sonucu ona yazıyoruz.
            //   • BELİRSİZ  → `pending` KALIYOR ki `reconcilePayments()` bu
            //     order_id ile sağlayıcıya sorup gerçeği öğrenebilsin.
            //   • KESİN RET → `failed`; mutabakatın uğraşmasına gerek yok.
            self::finalizePendingPayment($db, $paymentId, [
                'status'              => $keepReservation ? 'pending' : 'failed',
                'amount'              => InputSanitizer::price($totalAmount),
                'product_amount'      => InputSanitizer::price($totalAmount),
                'param_response_json' => json_encode([
                    'error_code'    => (string) ($chargeResult['error_code'] ?? ''),
                    'error_message' => (string) ($chargeResult['message'] ?? ''),
                    'indeterminate' => $keepReservation,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if ($keepReservation) {
                error_log(sprintf(
                    '[checkout] tahsilat sonucu BELİRSİZ, idempotency tutuluyor order=%s user=%d code=%s',
                    $orderId,
                    $userId,
                    (string) ($chargeResult['error_code'] ?? '-')
                ));
                $releaseLock();
                JsonResponse::error(
                    'Ödemenizin sonucu doğrulanamadı. Kartınızdan çekim yapılmış olabilir; '
                    . 'lütfen tekrar denemeden önce "Ödemelerim" sayfasını kontrol edin.',
                    502,
                    AppConfig::ERR_PAYMENT
                );
            }

            $releaseLock();
            JsonResponse::error($chargeResult['message'] ?? 'Ödeme başarısız.', 402, AppConfig::ERR_PAYMENT);
        }

        // Tahsilat GERÇEKTEN yapıldı — ledger artık gerçek durumu yazıyor.
        // Eskiden buradaki `simulated` bayrağı her siparişi 'pending_approval'
        // olarak yazıyordu (sahte kartın çekilebilir bakiye üretmesini
        // engellemek için); gerçek entegrasyonla birlikte o koruma gereksiz.
        $paymentStatus  = 'paid';
        $detailStatus   = 'approved';
        $gatewayPayment = (string) ($chargeResult['payment_id'] ?? '');

        // Real param_marketplace_payments columns are user_id/amount, not
        // buyer_user_id/total_amount (confirmed via live DESCRIBE) — the
        // original names here were guessed without DB access and never worked.
        //
        // D-04: satır zaten var (`pending` olarak, tahsilattan önce yazıldı);
        // burada INSERT değil GÜNCELLEME yapılıyor.
        $paymentRow = [
            'amount'   => InputSanitizer::price($totalAmount),
            'status'   => $paymentStatus,
        ];

        // The payment row recorded a single total and nothing else, so neither
        // reconciliation nor a refund could tell what was bought or who was owed
        // what — ten columns of this table were never written at all.
        //
        // param_response_json artık ZORUNLU bir kayıt, süs değil: iyzico
        // iadeyi kalem başına üretilen `paymentTransactionId` üzerinden
        // yapıyor ve o kimlikler yalnızca burada saklanıyor. Yazılmazsa bu
        // sipariş bir daha otomatik iade EDİLEMEZ (processRefund bunu açıkça
        // kontrol ediyor).
        //
        // service_fee hâlâ boş bırakılıyor — SERVICE_FEE_PERCENT bir sabit
        // olarak duruyor ama hiçbir yerde hizmet bedeli alınmıyor; doldurmak
        // henüz kimsenin karar vermediği bir ücret modelini ima ederdi.
        $optionalColumns = [
            'product_amount'       => InputSanitizer::price($totalAmount),
            'items_json'           => json_encode($itemRows, JSON_UNESCAPED_UNICODE),
            'seller_splits_json'   => json_encode($detailRows, JSON_UNESCAPED_UNICODE),
            'param_transaction_id' => $gatewayPayment !== '' ? $gatewayPayment : null,
            'param_receipt_id'     => $orderId,
            'param_net_amount'     => $chargeResult['net_amount'] ?? null,
            // array_merge sırası önemli: normalize edilmiş itemTransactions
            // ham yanıttakinin ÜZERİNE yazmalı. `+` operatörü tersini yapar
            // (soldaki kazanır) ve processRefund'ın okuduğu alan sağlayıcının
            // biçim değişikliklerine açık kalırdı.
            'param_response_json'  => json_encode(
                array_merge(
                    $chargeResult['raw'] ?? [],
                    ['itemTransactions' => $chargeResult['item_transactions'] ?? []]
                ),
                JSON_UNESCAPED_UNICODE
            ),
        ];
        foreach ($optionalColumns as $column => $value) {
            if (self::paymentsColumnExists($db, $column)) {
                $paymentRow[$column] = $value;
            }
        }

        $db->update('param_marketplace_payments', $paymentRow, 'id = ?', [$paymentId]);

        // PAY-004 🟠 — burada bir `ALTER TABLE` vardı ve ödeme transaction'ının
        // İÇİNDEYDİ. MySQL'de DDL örtük COMMIT tetikler: ALTER'a ulaşan ilk
        // checkout, o ana kadar yazılan abonelik/kredi/sepet satırlarını kalıcı
        // yapıp transaction'ı bitiriyordu; sonraki bir hata artık geri
        // alınamıyordu. Şema değişikliği migration'a taşındı
        // (database/migrations/004_add_details_chatbot_id.sql); burada yalnızca
        // sütunun VARLIĞI okunuyor, hiçbir DDL çalıştırılmıyor.
        $hasChatbotIdColumn = self::detailsColumnExists($db, 'chatbot_id');

        foreach ($detailRows as $row) {
            $detail = [
                'payment_id'        => $paymentId,
                'seller_user_id'    => $row['seller_user_id'],
                'guid_altuyeisyeri' => '', // no real ParamPos sub-merchant guid outside prod
                'gross_amount'      => $row['payable_amount'],
                'payable_amount'    => $row['payable_amount'],
                'status'            => $detailStatus,
            ];
            if ($hasChatbotIdColumn) {
                $detail['chatbot_id'] = $row['chatbot_id'];
            }
            $db->insert('param_marketplace_details', $detail);
        }

        $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();

            // Gerçek tahsilatın getirdiği YENİ hata sınıfı: para çekildi ama
            // sipariş yazılamadı. Rollback yalnızca veritabanını geri alır —
            // kartta çekilen tutarı geri almaz. Telafi edilmezse müşteri
            // ödeme yapmış, hiçbir şey almamış olur.
            //
            // Aynı gün tam iptal (`/payment/cancel`) bunun doğru aracı.
            // Başarısız olursa cancelCharge() operatöre "ELLE İADE GEREKİYOR"
            // diye loglar; sessizce yutmuyoruz.
            // I-01 — telafinin SONUCU önemli. İptal başarılıysa para geri
            // döndü, yani ortada tahsilat yok ve kullanıcı tekrar
            // deneyebilir. İptal BAŞARISIZSA para müşteride değil bizde
            // kalmıştır: rezervasyonu bırakmak, kullanıcının tekrar deneyip
            // İKİNCİ kez ödemesine kapı açardı.
            $compensated = true;
            if ($gatewayPayment !== '') {
                $compensated = cancelCharge($gatewayPayment, clientIp(), $orderId);
            }

            // PAY-008: sipariş başarısız oldu — idempotency rezervasyonunu
            // bırak ki kullanıcı düzeltip tekrar deneyebilsin. Bırakılmazsa
            // aynı sepet kalıcı olarak "zaten oluşturulmuş" sayılırdı.
            if ($compensated) {
                try {
                    $db->execute('DELETE FROM checkout_idempotency WHERE idem_key = ?', [$idempotencyKey]);
                } catch (Throwable $inner) {
                    error_log('[checkout] idempotency rezervasyonu bırakılamadı: ' . $inner->getMessage());
                }
            } else {
                // Rezervasyon KALIYOR (bkz. $keepReservation): elle iade
                // gerekiyor, o çözülene kadar aynı sepet tekrar denenemez.
                $keepReservation = true;
                error_log(sprintf(
                    '[checkout] telafi başarısız, idempotency tutuluyor order=%s user=%d paymentId=%s',
                    $orderId,
                    $userId,
                    $gatewayPayment
                ));
            }

            // D-04: `pending` ödeme satırı hâlâ ayakta. Telafi başarılıysa
            // tahsilat geri alındı → `failed`. Başarısızsa para hareket etti
            // ama siparişimiz yok → `unknown`; mutabakat ve operatör bu
            // satırı görsün diye bilerek KESİNLEŞTİRİLMİYOR.
            self::finalizePendingPayment($db, $paymentId, [
                'status'               => $compensated ? 'failed' : 'unknown',
                'amount'               => InputSanitizer::price($totalAmount),
                'param_transaction_id' => $gatewayPayment !== '' ? $gatewayPayment : null,
                'param_response_json'  => json_encode([
                    'error'       => $e->getMessage(),
                    'compensated' => $compensated,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $releaseLock();
            throw $e;
        }

        // Rezervasyonu gerçek sipariş numarasıyla tamamla — tekrar gönderilen
        // istek artık ilk siparişin numarasını görecek.
        //
        // I-01 — `created_at` de ŞİMDİYE çekiliyor: tekrar penceresi
        // rezervasyonun açıldığı andan değil, siparişin TAMAMLANDIĞI andan
        // başlasın. Pencere dolduğunda satır bir sonraki denemede silinip
        // aynı bot aynı süreyle yeniden satın alınabilir hâle gelir.
        try {
            $db->execute(
                'UPDATE checkout_idempotency SET order_id = ?, created_at = NOW() WHERE idem_key = ?',
                [$orderId, $idempotencyKey]
            );
        } catch (Throwable $e) {
            error_log('[checkout] idempotency order_id yazılamadı: ' . $e->getMessage());
        }
        $orderCompleted = true;
        $releaseLock();

        JsonResponse::success(['message' => 'Abonelik oluşturuldu.', 'ids' => $subscriptionIds, 'order_id' => $orderId]);
    }

    public static function deleteSubscription(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $id     = InputSanitizer::positiveInt($data['id'] ?? $_POST['id'] ?? 0);
        if (!$id) JsonResponse::error('ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // Previously had no ownership check — anyone could cancel any user's subscription by id.
        // The ownership predicate keeps another user's row safe, but the result
        // was reported as success regardless: passing someone else's id (or a
        // nonexistent one) answered "Abonelik silindi." while nothing had been
        // deleted. Report what actually happened.
        $affected = Database::getInstance()->delete('user_subscriptions', 'id = ? AND user_id = ?', [$id, $userId]);
        if ($affected === 0) {
            JsonResponse::error('Abonelik bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        }
        JsonResponse::success(['message' => 'Abonelik silindi.']);
    }

    public static function updateSubscription(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || !isset($data['id'])) JsonResponse::error('Veri veya ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $id = InputSanitizer::positiveInt($data['id']);
        unset($data['id'], $data['user_id']);

        // SEC-002 🔴 — burası eskiden yalnızca `id` ve `user_id` alanlarını
        // çıkarıp KALANI olduğu gibi UPDATE'e veriyordu. `expiry_date`
        // istemciden yazılabildiği için kullanıcı kendi aboneliğini ücretsiz
        // olarak süresiz uzatabiliyordu; `chatbot_id` ile de ödediğinden
        // başka bir bota geçebiliyordu.
        //
        // Kullanıcının bir abonelik üzerinde meşru olarak değiştirebileceği
        // tek şey onu iptal etmek/yeniden açmaktır. Süre, bot ve fiyat
        // yalnızca sunucu tarafındaki satın alma akışında belirlenir.
        [$data, $rejected] = InputSanitizer::pickAllowed($data, ['status']);
        if ($rejected !== []) {
            JsonResponse::error(
                'Bu alanlar güncellenemez: ' . implode(', ', $rejected),
                403,
                AppConfig::ERR_VALIDATION
            );
        }
        if ($data === []) {
            JsonResponse::error('Güncellenecek alan yok.', 400, AppConfig::ERR_VALIDATION);
        }
        $data['status'] = InputSanitizer::bool($data['status']) ? 1 : 0;

        // Previously had no ownership check — anyone could edit any subscription by id.
        // As with deleteSubscription, a row that was never touched used to come
        // back as a successful update.
        $affected = Database::getInstance()->update('user_subscriptions', $data, 'id = ? AND user_id = ?', [$id, $userId]);
        if ($affected === 0) {
            // MySQL reports 0 affected rows both for "no such row" and for an
            // update that changed nothing, so confirm which one it was before
            // calling it an error.
            $exists = Database::getInstance()->selectSingle(
                'id FROM user_subscriptions WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
            if (!$exists) {
                JsonResponse::error('Abonelik bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
            }
        }
        JsonResponse::success(['message' => 'Abonelik güncellendi.', 'id' => $id]);
    }

    // S14 (2026-10-06) — "Üretici hesabı" (750 ₺) kaldırıldı: buyProducerAccount
    // ve getProducerPlanStatus uç noktaları, functions/producer_plan.php ve
    // AppConfig::PRODUCER_* sabitleri silindi. Satış zaten fail-closed'du ve
    // teklif üyelik paketleriyle çelişiyordu. producer_plans /
    // producer_self_use_credits tabloları şemada duruyor (silmek yıkıcı).
}
