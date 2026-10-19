<?php
class UserController {
    public static function getUserHeader(): void {
        $userId = AuthMiddleware::requireAuth();

        $repo = new UserRepository();
        $user = $repo->findById($userId);
        if (!$user) JsonResponse::error('Kullanıcı bulunamadı!', 404, AppConfig::ERR_NOT_FOUND);

        $db    = Database::getInstance();
        $count = (int) $db->count(AppConfig::TABLE_CHATBOTS, 'author_user_id = ?', [$userId]);

        // UX-002: plan adı artık limitlerle AYNI kaynaktan geliyor
        // (functions/plans.php). Eskiden burası `user_plan_selection`'ın
        // serbest metnini okuyor, bot ekranı ise stub'dan 1/2 alıyordu;
        // ikisi arasında hiçbir bağ yoktu ve kullanıcı "Elmas" başlığıyla
        // 1/2 limitini aynı anda görüyordu.
        require_once __DIR__ . '/../../../functions/plans.php';
        $userPlan = getUserPlan($db, $userId);
        $planName = (string) $userPlan['name_tr'];
        $sub      = getUserSubscription($db, $userId);

        require_once __DIR__ . '/../../../functions/coin_engine.php';
        $coinBalance = getOrInitCoinBalance($db, $userId);

        // Same "purchased" definition ChatbotRepository::getMenuItems uses
        // for the Chatbotlarım list: owns access via an active subscription
        // to a bot someone else authored — not just anything with
        // owner_user_id set (that column isn't updated back to the author
        // when a subscription expires).
        $purchasedCount = (int) $db->selectSingle(
            "COUNT(*) AS total FROM `" . AppConfig::TABLE_CHATBOTS . "` c
             WHERE c.owner_user_id = ? AND c.author_user_id != ?
               AND EXISTS (
                    SELECT 1 FROM user_subscriptions us
                    WHERE us.user_id = ? AND us.chatbot_id = c.id
                      AND us.status = 1 AND us.expiry_date > NOW()
                 )",
            [$userId, $userId, $userId]
        )['total'];

        $sharedDialogueCount = $db->count('user_dialog_books', 'user_id = ?', [$userId]);

        JsonResponse::success([
            'id'                  => $user['id'],
            'fullname'            => $user['ad_soyad'],
            'username'            => $user['kullanici_adi'],
            'chatbotCount'        => $count,
            'purchasedCount'      => $purchasedCount,
            'sharedDialogueCount' => $sharedDialogueCount,
            'planName'            => $planName,
            // N-64 — PRO etiketi ada göre değil, fiyat temelli bayrağa ve
            // kalan süreye göre (functions/plans.php → getUserSubscription).
            'planIsPaid'          => $sub['is_paid'],
            'planSecondsLeft'     => $sub['seconds_left'],
            'dailyCoinsRemaining' => (int) $coinBalance['coins_remaining'],
            // BIZ-002: toplam da plandan gelmeli. Sabit bırakıldığında Elmas
            // planındaki kullanıcı "1000/10" gibi anlamsız bir oran görüyordu.
            'dailyCoinsTotal'     => (int) $userPlan['daily_message_limit'],
        ]);
    }

    /**
     * Başka bir kullanıcının herkese açık profili.
     *
     * getUserHeader() ÇAĞIRANIN kendi hesabını döndürür ve içinde e-posta,
     * telefon, plan, coin bakiyesi gibi yalnızca hesap sahibini ilgilendiren
     * alanlar var. Bu metot onun yerine geçmez: burada dönen her alan, zaten
     * pano içinde herkese görünen bir şey.
     *
     * BİLEREK DÖNMEYENLER: eposta, telefon, ad_soyad, dogum_tarihi, plan,
     * coin bakiyesi. Yeni bir alan eklemeden önce "bunu yabancı biri
     * görebilir mi?" sorusu sorulmalı.
     *
     * Bot sayısı `is_independent = 0` ile sınırlı: bağımsız botlar tanımı
     * gereği yalnızca sahibine açık (schema.sql'deki sütun yorumu), sayısını
     * yayınlamak da özel bilgi sızdırırdı.
     */
    public static function getPublicProfile(): void {
        // Misafire kapalı: bu veri pano içi, açık internete değil.
        AuthMiddleware::requireAuth();

        $id = InputSanitizer::positiveInt($_GET['id'] ?? 0);
        if (!$id) {
            JsonResponse::error('Kullanıcı id gereklidir.', 400, AppConfig::ERR_VALIDATION);
        }

        $user = (new UserRepository())->findById($id);
        if (!$user) {
            JsonResponse::error('Kullanıcı bulunamadı!', 404, AppConfig::ERR_NOT_FOUND);
        }

        $db = Database::getInstance();

        JsonResponse::success([
            'id'                  => (int) $user['id'],
            'username'            => $user['kullanici_adi'],
            'avatar'              => $user['avatar'] ?: null,
            'chatbotCount'        => (int) $db->count(
                AppConfig::TABLE_CHATBOTS,
                'author_user_id = ? AND is_independent = 0',
                [$id]
            ),
            'sharedDialogueCount' => (int) $db->count('user_dialog_books', 'user_id = ?', [$id]),
        ]);
    }

    public static function getUserNames(): void {
        $userId = AuthMiddleware::requireAuth();

        $repo = new UserRepository();
        $user = $repo->findById($userId);
        if (!$user) JsonResponse::error('Kullanıcı bulunamadı!', 404, AppConfig::ERR_NOT_FOUND);

        JsonResponse::success(['id' => $user['id'], 'fullname' => $user['ad_soyad'], 'username' => $user['kullanici_adi']]);
    }

    public static function updateUserNames(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();

        $adSoyad      = InputSanitizer::string($_POST['ad_soyad'] ?? '', 100);
        $kullaniciAdi = InputSanitizer::string($_POST['kullanici_adi'] ?? '', 30);

        if (!$adSoyad && !$kullaniciAdi) {
            JsonResponse::error('Güncellenecek alan bulunamadı!', 400, AppConfig::ERR_VALIDATION);
        }

        $updates = [];
        if ($adSoyad)      $updates['ad_soyad']      = $adSoyad;
        if ($kullaniciAdi) $updates['kullanici_adi']  = $kullaniciAdi;

        // F-02 — `kullanicilar.kullanici_adi` üzerinde UNIQUE var ama çakışma
        // hiç yakalanmıyordu: başkasının kullanıcı adını yazmaya çalışmak ham
        // PDO istisnasına, yani "Sunucu hatası oluştu." diyen bir 500'e
        // dönüşüyordu. Kullanıcı neyin yanlış olduğunu göremiyordu.
        if ($kullaniciAdi) {
            self::assertUniqueOrConflict('kullanici_adi', $kullaniciAdi, $userId, 'Bu kullanıcı adı zaten kullanılıyor.');
        }

        (new UserRepository())->updateById($userId, $updates);
        JsonResponse::success(['message' => 'Kullanıcı güncellendi.']);
    }

    /**
     * F-02 — UNIQUE sütuna yazmadan önce çakışma kontrolü.
     *
     * Yarış penceresi kapanmıyor (kontrol ile yazma arasında başkası aynı
     * değeri alabilir); bu yüzden çağıranlar ayrıca duplicate istisnasını da
     * yakalıyor. Buradaki kontrol yaygın durumu anlamlı bir 409'a çeviriyor,
     * DB kısıtı ise son savunma olarak yerinde duruyor.
     */
    private static function assertUniqueOrConflict(string $column, string $value, int $userId, string $message): void {
        $taken = Database::getInstance()->selectSingle(
            "id FROM " . AppConfig::TABLE_USERS . " WHERE `$column` = ? AND id <> ?",
            [$value, $userId]
        );
        if ($taken) {
            JsonResponse::error($message, 409, AppConfig::ERR_DUPLICATE);
        }
    }

    public static function getUserEmail(): void {
        $userId = AuthMiddleware::requireAuth();

        $user = (new UserRepository())->findById($userId);
        if (!$user) JsonResponse::error('Kullanıcı bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);

        JsonResponse::success(['id' => $user['id'], 'email' => $user['eposta']]);
    }

    /**
     * B-07 — e-posta değiştirme.
     *
     * Eskiden bu uç nokta yalnızca oturum istiyordu: ne mevcut parolayı
     * soruyor, ne yeni adrese doğrulama gönderiyor, ne de hız sınırı
     * uyguluyordu. Sömürü zinciri kısa ve KALICI:
     *   1. Saldırgan bir oturumu ele geçirir (XSS, ödünç alınmış cihaz,
     *      açık kalmış tarayıcı).
     *   2. Tek bir POST ile hesabın e-postasını kendi adresine çevirir.
     *   3. "Şifremi unuttum" akışını çalıştırır, sıfırlama kodu ONA gider.
     *   4. Parola değişir — gerçek sahip artık kendi hesabına giremez.
     * Oturum sonlandırmak bile yetmez, çünkü hesap artık saldırganın
     * e-postasına bağlıdır.
     *
     * Mevcut parola bu zinciri 2. adımda kesiyor: oturumu çalan kişi
     * parolayı bilmiyor.
     *
     * ⚠️ EKSİK KALAN YARIM (BLOCKERS B5): yeni adrese doğrulama kodu
     * gönderme adımı EKLENMEDİ. Gerekçe blocker: SMTP hesabı yok, yani
     * kod gönderilemez. Gönderime bağlı bir akış eklemek, bugün çalışan
     * e-posta değiştirmeyi tamamen kırardı. B5 kapandığında bu metot
     * `password_resets` desenindeki gibi iki adımlı hâle getirilmeli:
     * (a) talep + koda gönderim, (b) kod doğrulanınca yazma. O adım
     * kullanıcının SAHİP OLMADIĞI bir adresi hesabına bağlamasını
     * engeller — parola kapısı bunu kapsamıyor.
     */
    public static function updateUserEmail(): void {
        require_method('POST');
        $userId   = AuthMiddleware::requireAuth();
        $newEmail = InputSanitizer::email($_POST['email'] ?? '');
        $password = (string) ($_POST['current_password'] ?? '');

        if (!$newEmail) JsonResponse::error('Yeni e-posta adresi zorunludur!', 400, AppConfig::ERR_VALIDATION);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            JsonResponse::error('Geçerli bir e-posta adresi girin.', 400, AppConfig::ERR_VALIDATION);
        }

        $db = Database::getInstance();
        // Parola denemesi içeren her uç nokta gibi sınırlı: kaba kuvvetle
        // parola tahmini bu kapıdan da yapılabilirdi.
        checkRateLimit($db, 'changeemail:' . $userId, 5, 900);

        $user = (new UserRepository())->findById($userId);
        if (!$user) {
            JsonResponse::error('Kullanıcı bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        }

        // Google ile açılmış hesapta `sifre` NULL olabilir — o hesapların
        // doğrulayacak bir parolası yok. Sessizce geçmek kapıyı anlamsız
        // kılardı; kullanıcıya ne yapması gerektiği söyleniyor.
        $storedHash = (string) ($user['sifre'] ?? '');
        if ($storedHash === '') {
            JsonResponse::error(
                'Bu hesap Google ile açıldığı için parolası yok. E-posta adresini değiştirmeden '
                . 'önce "Şifremi unuttum" akışıyla bir parola belirleyin.',
                422,
                AppConfig::ERR_VALIDATION
            );
        }

        if ($password === '' || !password_verify($password, $storedHash)) {
            JsonResponse::error('Mevcut parolanız hatalı.', 403, AppConfig::ERR_PERMISSION);
        }

        // F-02 — `eposta` UNIQUE; çakışma yakalanmadığı için başkasının
        // e-postasını yazmaya çalışmak 500 üretiyordu.
        self::assertUniqueOrConflict('eposta', $newEmail, $userId, 'Bu e-posta adresi başka bir hesapta kayıtlı.');

        $ok = (new UserRepository())->updateById($userId, ['eposta' => $newEmail]);
        if ($ok) {
            JsonResponse::success(['message' => 'E-posta güncellendi.']);
        } else {
            JsonResponse::error('E-posta güncellenemedi veya kullanıcı bulunamadı.', 400);
        }
    }

    public static function getUserPhone(): void {
        $userId = AuthMiddleware::requireAuth();

        $user = (new UserRepository())->findById($userId);
        if (!$user) JsonResponse::error('Kullanıcı bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);

        JsonResponse::success(['id' => $user['id'], 'telefon' => $user['telefon'] ?? null]);
    }

    public static function updateUserPhone(): void {
        require_method('POST');
        $userId  = AuthMiddleware::requireAuth();
        $telefon = InputSanitizer::string($_POST['telefon'] ?? '', 20);

        if (!$telefon) JsonResponse::error('Telefon numarası zorunludur!', 400, AppConfig::ERR_VALIDATION);

        $ok = (new UserRepository())->updateById($userId, ['telefon' => $telefon]);
        if ($ok) {
            JsonResponse::success(['message' => 'Telefon numarası güncellendi.']);
        } else {
            JsonResponse::error('Telefon güncellenemedi veya kullanıcı bulunamadı.', 400);
        }
    }

    /**
     * SEC-016 🟡 — avatar değeri HİÇ doğrulanmadan saklanıyordu.
     *
     * Chatbot görselleri için titiz bir yükleme yolu var
     * (ChatbotController::handleImageUploads: boyut sınırı, magic-byte MIME
     * doğrulaması, MIME'dan türetilen uzantı, sunucunun ürettiği dosya adı).
     * Kullanıcı avatarları o yolu tamamen atlıyordu: `$data['avatar']` ne
     * gelirse `kullanicilar.avatar` (LONGTEXT) sütununa yazılıyordu.
     *
     * Pratik sonuçları: sınırsız uzunlukta veri (LONGTEXT'e istediği kadar
     * yazabilir), `javascript:` / `data:text/html` gibi şemalar (avatar bir
     * <img src> içinde render ediliyor) ve tamamen dış URL'ler (kullanıcı
     * profil resmini istediği sunucudan çektirerek görüntüleyenlerin IP'sini
     * toplayabilir).
     *
     * Kabul edilen üç biçim:
     *   • ""                       → fotoğrafı kaldır
     *   • assets/…                 → bu sunucunun yükleme yolu
     *   • data:image/…;base64,…    → küçük gömülü görsel (istemci kırpma akışı)
     */
    private const MAX_AVATAR_DATA_URI_BYTES = 512 * 1024;

    public static function uploadProfilePhoto(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $avatar = $data['avatar'] ?? null;

        // avatar === "" is a valid request meaning "remove my photo" — only a
        // truly missing key should be rejected.
        if ($avatar === null) {
            JsonResponse::error('Eksik alanlar!', 400, AppConfig::ERR_VALIDATION);
        }
        if (!is_string($avatar)) {
            JsonResponse::error('Geçersiz avatar değeri.', 400, AppConfig::ERR_VALIDATION);
        }

        $avatar = trim($avatar);

        if ($avatar !== '') {
            $isLocalPath = (bool) preg_match('#^assets/[A-Za-z0-9_\-/]+\.(png|jpe?g|gif|webp)$#i', $avatar);
            $isDataUri   = (bool) preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $avatar);

            if (!$isLocalPath && !$isDataUri) {
                JsonResponse::error(
                    'Geçersiz avatar. Yalnızca bu sunucuya yüklenmiş bir görsel yolu '
                    . 'veya gömülü bir görsel kabul edilir.',
                    400,
                    AppConfig::ERR_VALIDATION
                );
            }

            // ".." yolu assets/ dışına çıkarabilirdi.
            if (str_contains($avatar, '..')) {
                JsonResponse::error('Geçersiz avatar yolu.', 400, AppConfig::ERR_VALIDATION);
            }

            if ($isDataUri && strlen($avatar) > self::MAX_AVATAR_DATA_URI_BYTES) {
                JsonResponse::error('Görsel çok büyük (en fazla 512 KB).', 400, AppConfig::ERR_VALIDATION);
            }

            if ($isDataUri) {
                // Base64 gövdesi gerçekten bir görsel mi? Uzantı/MIME iddiası
                // tek başına yeterli değil — chatbot yolundaki magic-byte
                // kontrolünün karşılığı.
                $payload = substr($avatar, strpos($avatar, ',') + 1);
                $binary  = base64_decode($payload, true);
                if ($binary === false || @getimagesizefromstring($binary) === false) {
                    JsonResponse::error('Görsel çözümlenemedi.', 400, AppConfig::ERR_VALIDATION);
                }
            }
        }

        $ok = (new UserRepository())->updateById($userId, ['avatar' => $avatar]);
        if ($ok) {
            JsonResponse::success(['message' => 'Profil fotoğrafı güncellendi.', 'user_id' => $userId]);
        } else {
            JsonResponse::error('Güncelleme yapılamadı veya değişiklik yok.', 400);
        }
    }

    public static function getProfilePhoto(): void {
        $userId = AuthMiddleware::requireAuth();

        $user   = (new UserRepository())->findById($userId);
        $avatar = isset($user['avatar']) ? preg_replace('/\s+/', '', $user['avatar']) : null;

        echo json_encode(
            ['success' => true, 'avatar' => (!empty($avatar) ? $avatar : null)],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
