<?php
/**
 * Thin Auth controller — request → use case → response, nothing else.
 */
class AuthController {
    public static function login(): void {
        require_method('POST');
        $data       = parse_post_data();
        $identifier = InputSanitizer::string($data['kullanici_adi'] ?? $data['eposta'] ?? '');
        $password   = $data['sifre'] ?? '';
        $rememberMe = (bool) ($data['rememberMe'] ?? false);

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

        // Two limits, because one alone leaves a hole:
        //   per IP+identifier — stops password-guessing against one account;
        //   per IP            — stops credential stuffing and user enumeration,
        //                       which simply changed the e-mail each attempt and
        //                       reset the first counter every time.
        // The per-IP budget is deliberately looser so a shared NAT/office egress
        // does not lock out legitimate users at the same rate as an attacker.
        checkRateLimit(Database::getInstance(), 'login:' . $clientIp . ':' . $identifier, 8, 300);
        checkRateLimit(Database::getInstance(), 'login-ip:' . $clientIp, 30, 300);

        try {
            $useCase = new LoginUseCase(new UserRepository());
            $result  = $useCase->execute($identifier, $password, $rememberMe);
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        // Regenerate the session ID on successful auth so a session ID an
        // attacker fixed before login (session fixation) doesn't carry over
        // into the authenticated session.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $result['user_id'];

        if ($rememberMe && isset($result['remember_selector'])) {
            RememberMeCookie::issue(
                $result['remember_selector'],
                $result['remember_validator'],
                $result['remember_expiry']
            );
        }

        JsonResponse::success([
            'user_id' => $result['user_id'],
            'email'   => $result['email'],
            'message' => 'Giriş başarılı!',
        ]);
    }

    public static function register(): void {
        require_method('POST');
        $data = parse_post_data();

        checkRateLimit(Database::getInstance(), 'register:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 600);

        try {
            $useCase = new RegisterUseCase(new UserRepository());
            $userId  = $useCase->execute($data);
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        JsonResponse::success(['message' => 'Kayıt başarılı!', 'id' => $userId]);
    }

    public static function logout(): void {
        $userId = $_SESSION['user_id'] ?? null;

        $_SESSION = [];
        session_unset();
        session_destroy();

        // F-04 — silme çerezi koşulsuz `secure: true` ile yazılıyordu ve
        // `samesite` taşımıyordu; oysa oturum çerezi bootstrap.php'de
        // `RequestContext::isHttps()` + `SameSite=Lax` ile yazılıyor.
        // Bayrakları tutmayan bir silme çerezini tarayıcı AYRI bir çerez
        // sayar: orijinal PHPSESSID silinmeden yerinde kalır, yani düz HTTP
        // sunulan kurulumda çıkış çerezi hiç temizlemiyordu. Bayraklar artık
        // yazma tarafıyla birebir aynı (RememberMeCookie ile aynı gerekçe).
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => RequestContext::isHttps(),
            'samesite' => 'Lax',
        ]);

        // Session cookie alone isn't enough — a live remember-me token would
        // let sessionCheck() silently re-authenticate the user right after
        // logout via AuthMiddleware::optionalAuth()'s remember-me fallback.
        if ($userId) {
            (new UserRepository())->clearRememberToken((int) $userId);
        }
        RememberMeCookie::clear();

        JsonResponse::success(['message' => 'Çıkış yapıldı.']);
    }

    public static function sessionCheck(): void {
        if (isset($_SESSION['user_id'])) {
            JsonResponse::success(['authenticated' => true, 'user_id' => (int) $_SESSION['user_id']]);
        }

        $userId = AuthMiddleware::optionalAuth();
        if ($userId > 0) {
            JsonResponse::success(['authenticated' => true, 'user_id' => $userId]);
        }

        echo json_encode(['authenticated' => false]);
        exit;
    }

    public static function loginGoogle(): void {
        // N-56 — autoload/sınıf eksikse ham PHP hatası yerine anlaşılır 503.
        VendorAutoload::requireOrFail(
            'login-google',
            VendorAutoload::GOOGLE_CLASSES,
            'Google ile giriş şu anda kullanılamıyor. E-posta ve şifrenizle giriş yapabilirsiniz.'
        );

        $jsonData = $_POST['data'] ?? null;
        $data     = $jsonData ? json_decode($jsonData, true) : null;
        $idToken  = $data['google_token'] ?? null;

        if (!$idToken) {
            JsonResponse::error('Token sağlanmadı.', 400, AppConfig::ERR_VALIDATION);
        }

        $clientId = AppConfig::googleClientId();
        if ($clientId === '') {
            error_log('[login-google] GOOGLE_CLIENT_ID tanımlı değil.');
            JsonResponse::error(
                'Google ile giriş şu anda yapılandırılmamış.',
                503,
                AppConfig::ERR_UNAVAILABLE
            );
        }

        // verifyIdToken() bozuk/süresi dolmuş token'da false DÖNMÜYOR, exception
        // fırlatıyor (firebase/php-jwt: "Wrong number of segments", "Expired
        // token" vb.). Yalnızca !$payload kontrol edildiği için bu durumlar
        // yakalanmadan 500'e dönüşüyordu; kullanıcı "Sunucu hatası" görüyordu.
        try {
            $client  = new Google_Client(['client_id' => $clientId]);
            $payload = $client->verifyIdToken($idToken);
        } catch (Throwable $e) {
            error_log('[login-google] token doğrulanamadı: ' . $e->getMessage());
            $payload = false;
        }

        if (!$payload) {
            JsonResponse::error(
                'Google oturumu doğrulanamadı. Lütfen tekrar deneyin.',
                401,
                AppConfig::ERR_AUTH_REQUIRED
            );
        }

        // SEC-004 🟠 — hesap ele geçirme.
        //
        // GoogleLoginUseCase, Google hesabını `google_id` VEYA `eposta` ile
        // eşleştiriyor: doğrulanmamış bir e-postaya sahip bir Google hesabı,
        // aynı e-postayla parola kullanarak açılmış mevcut bir hesaba
        // bağlanabiliyordu. Google, `email_verified: false` olan token'ları
        // pekâlâ imzalar (kurumsal/GSuite dışı bazı akışlar, yeni oluşturulmuş
        // hesaplar). İmza geçerli olduğu için `verifyIdToken` bunu kabul eder;
        // sahipliği doğrulayan tek alan `email_verified`.
        $email         = (string) ($payload['email'] ?? '');
        $emailVerified = $payload['email_verified'] ?? false;
        // Google bu alanı bazı akışlarda "true" dizesi olarak gönderir.
        $emailVerified = ($emailVerified === true || $emailVerified === 'true' || $emailVerified === 1 || $emailVerified === '1');

        if ($email === '' || !$emailVerified) {
            JsonResponse::error(
                'Google hesabınızın e-posta adresi doğrulanmamış. Google hesabınızı '
                . 'doğruladıktan sonra tekrar deneyin.',
                403,
                AppConfig::ERR_PERMISSION
            );
        }

        if (empty($payload['sub'])) {
            JsonResponse::error('Geçersiz Google token.', 401, AppConfig::ERR_AUTH_REQUIRED);
        }

        try {
            $useCase = new GoogleLoginUseCase(new UserRepository());
            $userId  = $useCase->execute($payload['sub'], $email, $payload['name'] ?? '');
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        JsonResponse::success(['user_id' => $userId, 'message' => 'Login successful']);
    }

    /**
     * SECURITY: the reset code is generated and stored (hashed) here on the
     * server — it must never be accepted from the client. A client-supplied
     * code would let anyone request a "reset" for any email and just tell
     * the server what the "correct" code is, defeating verification entirely.
     */
    public static function sendPasswordResetMail(): void {
        require_method('POST');
        require_once __DIR__ . '/../../../functions/phpmailer.php';

        $email = InputSanitizer::email($_POST['email'] ?? '');
        if (!$email) {
            JsonResponse::error('Email zorunludur!', 400, AppConfig::ERR_VALIDATION);
        }

        checkRateLimit(Database::getInstance(), 'passreset:' . ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . $email, 3, 600);

        // SEC-012 🟡 — hesap enumerasyonu. Bu uç nokta bilinmeyen bir adrese
        // 404 "Bu e-posta ile kayıtlı bir kullanıcı bulunamadı.", bilinen bir
        // adrese 200 döndürüyordu; yani kayıtsız bir saldırgan hangi
        // e-postaların sistemde olduğunu tek tek doğrulayabiliyordu.
        // Artık iki yol da AYNI yanıtı veriyor.
        $genericResponse = [
            'success' => true,
            'message' => 'Eğer bu e-posta adresi kayıtlıysa, sıfırlama kodu gönderildi. '
                . 'Gelen kutunuzu ve spam klasörünüzü kontrol edin.',
        ];

        $users = new UserRepository();
        $user  = $users->findByEmail($email);
        if (!$user) {
            error_log('[passwordreset] bilinmeyen e-posta için talep: ' . $email);
            echo json_encode($genericResponse, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $code     = (string) random_int(100000, 999999);
        $codeHash = hash('sha256', $code);

        $db   = Database::getInstance();
        $conn = $db->getConnection();
        $db->ensureTable('password_resets', 'CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                code_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                INDEX (user_id)
            )');
        // Only one active reset code per user at a time.
        $db->delete('password_resets', 'user_id = ?', [$user['id']]);
        // expires_at is computed by MySQL itself (NOW() + INTERVAL), not PHP's
        // date() — the app server and DB server can run in different
        // timezones (seen locally: PHP=UTC, MySQL=UTC+3), and comparing a
        // PHP-computed timestamp against MySQL's NOW() in a later query would
        // silently treat every code as already expired.
        $stmt = $conn->prepare(
            'INSERT INTO password_resets (user_id, code_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$user['id'], $codeHash]);

        $name    = htmlspecialchars($user['ad_soyad'] ?? '', ENT_QUOTES, 'UTF-8');
        $subject = 'Şifre Sıfırlama Kodu';
        $body    = "<p>Merhaba <strong>$name</strong>,</p>
                    <p>Şifrenizi sıfırlamak için kullanmanız gereken kod:</p>
                    <h2 style='color:#2c3e50;'>$code</h2>
                    <p>Bu kod 15 dakika geçerlidir.</p>
                    <p>Eğer bu talebi siz yapmadıysanız, lütfen bu e-postayı dikkate almayın.</p>";

        $result = sendEmail(AppConfig::noreplyEmail(), 'Sistem', $email, $subject, $body);

        // Gönderim başarısızsa bunu YUTMUYORUZ (DEP-003: eski stub sessizce
        // "gönderildi" diyordu ve hesap kurtarılamaz hâle geliyordu) — ama
        // yanıt yine de enumerasyona kapalı kalmalı. Bu yüzden hata kullanıcıya
        // "kayıtlı mı?" bilgisini vermeyen bir biçimde bildiriliyor.
        if (empty($result['success'])) {
            error_log('[passwordreset] e-posta gönderilemedi: ' . ($result['message'] ?? '-'));
            JsonResponse::error(
                'Şu anda sıfırlama e-postası gönderilemiyor. Lütfen daha sonra tekrar deneyin.',
                503,
                AppConfig::ERR_UNAVAILABLE
            );
        }

        echo json_encode($genericResponse, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * SECURITY: identity is derived from a valid, unexpired (email, code)
     * pair — never from a client-supplied user id. Previously this endpoint
     * accepted an arbitrary `id` with no verification at all, allowing
     * anyone to overwrite any account's password.
     */
    public static function updatePassword(): void {
        require_method('POST');
        $email           = InputSanitizer::email($_POST['email'] ?? '');
        $code            = InputSanitizer::string($_POST['code'] ?? '', 10);
        $password        = $_POST['password'] ?? null;
        $passwordConfirm = $_POST['password_confirm'] ?? null;

        if (!$email || !$code) {
            JsonResponse::error('E-posta ve doğrulama kodu zorunludur!', 400, AppConfig::ERR_VALIDATION);
        }
        if (!$password || !$passwordConfirm) {
            JsonResponse::error('Şifre ve doğrulama zorunludur!', 400, AppConfig::ERR_VALIDATION);
        }
        if ($password !== $passwordConfirm) {
            JsonResponse::error('Şifreler eşleşmiyor!', 400, AppConfig::ERR_VALIDATION);
        }

        // SEC-011: sıfırlama yolunda hiçbir parola politikası YOKTU — kayıt
        // olurken 8 karakter zorunluyken, sıfırlarken "1" kabul ediliyordu.
        $policyError = InputSanitizer::passwordPolicyError($password, [$email]);
        if ($policyError !== null) {
            JsonResponse::error($policyError, 400, AppConfig::ERR_VALIDATION);
        }

        // The reset code is only 6 digits (1M combinations) — without this,
        // it's brute-forceable within the 15-minute expiry window.
        $db = Database::getInstance();
        checkRateLimit($db, 'resetcode:' . ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . $email, 10, 600);

        // B-11 — yukarıdaki anahtar IP İÇERİYOR, yani farklı IP'lerden gelen
        // denemeler AYRI sayaçlara düşüyordu: botnet ya da proxy havuzu olan
        // biri her IP'den 10 deneme yaparak sayacı hiç doldurmadan 6 haneli
        // kodu (10⁶) deneyebiliyordu. Bu ikinci sayaç IP'siz, yani HESAP
        // başına toplam denemeyi sınırlıyor. Anahtar e-posta üzerinden
        // kuruluyor çünkü user_id'yi öğrenmek için önce satırı okumak gerekir
        // ve o okuma da sınırın içinde olmalı.
        checkRateLimit($db, 'resetcode-account:' . $email, 10, 900);

        $users = new UserRepository();
        $user  = $users->findByEmail($email);
        if (!$user) {
            JsonResponse::error('Kod geçersiz veya süresi dolmuş.', 400, AppConfig::ERR_VALIDATION);
        }

        $row = $db->selectSingle(
            '* FROM password_resets WHERE user_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
            [$user['id']]
        );

        if (!$row || !hash_equals($row['code_hash'], hash('sha256', $code))) {
            JsonResponse::error('Kod geçersiz veya süresi dolmuş.', 400, AppConfig::ERR_VALIDATION);
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $users->updateById($user['id'], ['sifre' => $hashed]);
        $db->delete('password_resets', 'user_id = ?', [$user['id']]);

        // SEC-010 🟡 — parola değişimi mevcut oturumları İPTAL ETMİYORDU.
        // "Hesabım ele geçirildi, şifremi değiştireyim" senaryosunun tamamı
        // buna dayanır: saldırganın açık oturumu ve remember-me token'ı
        // parola değişiminden sonra da çalışmaya devam ediyordu.
        (new UserRepository())->clearRememberToken((int) $user['id']);
        self::destroyOtherSessionsFor((int) $user['id']);

        JsonResponse::success([
            'message' => 'Şifre güncellendi. Güvenliğiniz için tüm cihazlardaki oturumlar kapatıldı.',
        ]);
    }

    /**
     * SEC-010 yardımcı: PHP'nin dosya tabanlı oturum deposunda bir kullanıcıya
     * ait tüm oturumları sonlandırır.
     *
     * Oturum verisi kullanıcıya göre indekslenmediği için depodaki dosyalar
     * taranıyor. Kurulum farklı bir save handler kullanıyorsa (redis,
     * memcached, DB) tarama sessizce atlanır — o durumda tek etkin savunma
     * remember-me token'ının silinmesi olur; bu yüzden ikisi birlikte yapılır.
     */
    private static function destroyOtherSessionsFor(int $userId): void {
        if ($userId <= 0) {
            return;
        }

        // Mevcut istek bir oturumdaysa (kullanıcı giriş yapmışken şifresini
        // değiştiriyorsa) onu koruyalım; diğerleri kapansın.
        $currentSid = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;

        if (strtolower((string) ini_get('session.save_handler')) !== 'files') {
            return;
        }

        $path = (string) ini_get('session.save_path');
        // "N;/path" ya da "N;MODE;/path" biçimleri.
        if (str_contains($path, ';')) {
            $parts = explode(';', $path);
            $path  = end($parts);
        }
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $files = glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'sess_*') ?: [];
        $needle = 'user_id|i:' . $userId . ';';

        foreach ($files as $file) {
            $sid = substr(basename($file), 5);
            if ($currentSid !== null && $sid === $currentSid) {
                continue;
            }
            $contents = @file_get_contents($file);
            if ($contents !== false && str_contains($contents, $needle)) {
                @unlink($file);
            }
        }
    }
}
