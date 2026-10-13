<?php
class SellerController {
    public static function register(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';
        require_once __DIR__ . '/../../../functions/checkout_payments.php';

        $data = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) {
            JsonResponse::error('Eksik veri.', 400, AppConfig::ERR_VALIDATION);
        }

        $db     = Database::getInstance();
        $conn   = $db->getConnection();

        ensureParamMarketplaceTables($conn);

        $bank = $db->selectSingle('* FROM banka_bilgileri WHERE user_id = ?', [$userId]);
        if (!$bank) {
            JsonResponse::error('Önce banka ve kimlik bilgilerinizi tamamlayın.', 422, AppConfig::ERR_VALIDATION);
        }

        $typeMap     = ['Bireysel Hesap' => 1, 'Şahıs Şirketi' => 2, 'Kurumsal Hesap' => 3];
        $tip         = $typeMap[$bank['account_type'] ?? ''] ?? 1;
        $isCorporate = ($tip === 3);
        $isSahis     = ($tip === 2);

        $required = ['phone', 'iban', 'il_kod', 'ilce_kod'];
        $required = array_merge($required, $isCorporate
            ? ['authorized_first_name', 'authorized_last_name', 'company_title', 'tax_number', 'tax_office', 'yetkili_kisi_dogum_tarihi']
            : ['full_name', 'id_number', 'tax_office', 'kisi_dogum_tarihi']);

        $missing = array_values(array_filter($required, fn($f) => empty($bank[$f])));
        if (!empty($missing)) {
            JsonResponse::error('Eksik alanlar: ' . implode(', ', $missing), 422, AppConfig::ERR_VALIDATION);
        }

        $existing = $db->selectSingle('id, status, guid_altuyeisyeri FROM param_marketplace_sellers WHERE user_id = ?', [$userId]);
        if ($existing && $existing['status'] === 'active' && !empty($existing['guid_altuyeisyeri'])) {
            echo json_encode(['success' => true, 'message' => 'Zaten aktif sub-merchant kaydınız var.', 'guid_altuyeisyeri' => $existing['guid_altuyeisyeri'], 'status' => 'active', 'idempotent' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $user        = $db->selectSingle('eposta FROM kullanicilar WHERE id = ?', [$userId]);
        $email       = trim((string) ($user['eposta'] ?? ''));
        $adSoyad     = $isCorporate ? trim($bank['authorized_first_name'] . ' ' . $bank['authorized_last_name']) : trim((string) $bank['full_name']);
        $companyTitle = trim((string) ($bank['company_title'] ?? ''));
        $unvan       = $isCorporate ? $companyTitle : (($isSahis && $companyTitle !== '') ? $companyTitle : $adSoyad);
        $tcVn        = $isCorporate ? (string) $bank['tax_number'] : (string) $bank['id_number'];
        $ibanUnvan   = $isCorporate ? $companyTitle : $adSoyad;

        $addressParts = array_filter([
            $bank['mahalle'] ?? '', $bank['cadde'] ?? '', $bank['sokak'] ?? '',
            !empty($bank['bina_no'])   ? 'No: ' . $bank['bina_no'] : '',
            !empty($bank['kapi_no'])   ? 'Daire: ' . $bank['kapi_no'] : '',
            !empty($bank['posta_kodu']) ? $bank['posta_kodu'] : '',
        ], fn($p) => trim((string) $p) !== '');
        $address = trim(implode(' ', $addressParts)) ?: trim((string) ($bank['address'] ?? ''));

        $paramParams = [
            'Tip' => $tip, 'Ad_Soyad' => $adSoyad, 'Unvan' => $unvan ?: $adSoyad,
            'TC_VN'       => preg_replace('/\D+/', '', $tcVn),
            'GSM_No'      => ltrim(preg_replace('/\D+/', '', (string) $bank['phone']), '0'),
            'IBAN_No'     => preg_replace('/\s+/', '', strtoupper((string) $bank['iban'])),
            'IBAN_Unvan'  => $ibanUnvan ?: $adSoyad,
            'Adres'       => $address,
            'Il'          => (int) $bank['il_kod'],
            'Ilce'        => (int) $bank['ilce_kod'],
            'EPosta'      => $email,
            'Website'     => '',
            'MCC_Kod'     => '5815',
            'Vergi_Daire' => trim((string) ($bank['tax_office'] ?? '')),
        ];
        if (!$isCorporate) {
            $paramParams['Kisi_DogumTarihi'] = (string) ($bank['kisi_dogum_tarihi'] ?? '');
        }
        if ($isCorporate) {
            $paramParams['Yetkili_Kisi_TC']          = preg_replace('/\D+/', '', (string) $bank['id_number']);
            $paramParams['Yetkili_Kisi_DogumTarihi'] = (string) ($bank['yetkili_kisi_dogum_tarihi'] ?? '');
        }

        $param       = new ParamPosMarketplace();
        $result      = $param->addSubMerchant($paramParams);
        $now         = date('Y-m-d H:i:s');

        // DEP-002 🟠 — buraya eskiden `json_encode($paramParams)` yazılıyordu,
        // yani TC kimlik no, IBAN, doğum tarihi, telefon ve adres
        // `param_marketplace_sellers.param_payload_json` sütununda DÜZ METİN
        // olarak duruyordu. Aynı veri error_log'a da düşüyordu (orası da
        // indirilebiliyordu — SEC-001) ve bir DB dökümü bunların hepsini tek
        // dosyada veriyordu.
        //
        // Bu sütunun tek meşru amacı hata ayıklama: "başvuruda hangi alanlar
        // gönderildi, hangisi eksikti?". Bunun için değerler gerekmiyor.
        // Gateway'e giden veri zaten istekle birlikte gidiyor; burada
        // saklanan yalnızca bir kayıt izi.
        $payloadJson = json_encode(
            self::redactSellerPayload($paramParams),
            JSON_UNESCAPED_UNICODE
        );
        if (!$result['success']) {
            $errMsg = $result['message'] ?: 'Param sub-merchant başvurusu reddedildi.';
            if ($existing) {
                $db->update('param_marketplace_sellers', ['status' => 'rejected', 'tip' => $tip, 'last_error' => $errMsg, 'last_attempt_at' => $now, 'param_payload_json' => $payloadJson], 'user_id = ?', [$userId]);
            } else {
                $db->insert('param_marketplace_sellers', ['user_id' => $userId, 'guid_altuyeisyeri' => '', 'status' => 'rejected', 'tip' => $tip, 'last_error' => $errMsg, 'last_attempt_at' => $now, 'param_payload_json' => $payloadJson]);
            }

            // DEP-001 🔴 — bu akış titizlikle yazılmış (tam doğrulama,
            // idempotency, red kaydı, `active` yalnızca gateway başarısında).
            // Sorun burada değil: `ParamPosMarketplace::addSubMerchant()` bir
            // stub ve HER ZAMAN başarısız dönüyor. Sonuç zincirleme:
            //   status != 'active'  →  saveChatbot, publishChatbot, addToCart,
            //   createSubscription, getPublished (INNER JOIN) ve userHasAccess'in
            //   ikinci dalı kapanıyor.
            // Yani temiz bir kurulumda kimse satıcı olamıyor → hiçbir bot
            // yayınlanamıyor → pazaryeri boş → hiçbir satış yapılamıyor.
            //
            // Bu kilit KOD DÜZEYİNDE açılamaz: `status='active'` yazmak, KYC
            // yapılmamış bir satıcıya para akışı açmak demek olurdu. Doğru
            // çözüm gerçek Param POS entegrasyonu. Yapılabilecek olan,
            // kullanıcıyı yanıltmamak: hatanın entegrasyon eksikliğinden mi
            // yoksa başvurusundan mı kaynaklandığını açıkça söylemek.
            $stubbed = str_contains($errMsg, 'dev stub');
            if ($stubbed) {
                error_log(sprintf(
                    '[seller_register] DEP-001: Param POS entegrasyonu yok, satıcı kaydı yapılamıyor. user_id=%d tip=%d',
                    $userId,
                    $tip
                ));
                JsonResponse::error(
                    'Pazaryeri satıcı kaydı şu anda alınamıyor: ödeme sağlayıcısı '
                    . 'entegrasyonu henüz devreye alınmadı. Bilgileriniz kaydedildi, '
                    . 'entegrasyon tamamlandığında başvurunuz işleme alınacak.',
                    503,
                    AppConfig::ERR_UNAVAILABLE,
                    ['status' => 'rejected', 'reason' => 'integration_unavailable']
                );
            }

            JsonResponse::error($errMsg, 422, AppConfig::ERR_PAYMENT, ['status' => 'rejected']);
        }

        $guid = (string) $result['guid_altuyeisyeri'];
        if ($existing) {
            $db->update('param_marketplace_sellers', ['guid_altuyeisyeri' => $guid, 'status' => 'active', 'tip' => $tip, 'last_error' => null, 'last_attempt_at' => $now, 'param_payload_json' => $payloadJson], 'user_id = ?', [$userId]);
        } else {
            $db->insert('param_marketplace_sellers', ['user_id' => $userId, 'guid_altuyeisyeri' => $guid, 'status' => 'active', 'tip' => $tip, 'last_error' => null, 'last_attempt_at' => $now, 'param_payload_json' => $payloadJson]);
        }

        echo json_encode(['success' => true, 'status' => 'active', 'guid_altuyeisyeri' => $guid, 'message' => 'Pazaryeri satıcı kaydınız oluşturuldu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function list(): void {
        require_method('GET');
        AuthMiddleware::requireAdmin();
        $rows = Database::getInstance()->selectMulti(
            'pms.id, pms.user_id, pms.guid_altuyeisyeri, pms.created_at, pms.updated_at, k.kullanici_adi, k.ad_soyad, k.eposta
             FROM param_marketplace_sellers pms LEFT JOIN kullanicilar k ON k.id = pms.user_id ORDER BY pms.created_at DESC',
            []
        );
        echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function listRemote(): void {
        AuthMiddleware::requireAdmin();
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';
        $result = (new ParamPosMarketplace())->listSubMerchants();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function update(): void {
        require_method('POST');
        AuthMiddleware::requireAdmin();
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';
        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $result = (new ParamPosMarketplace())->updateSubMerchant($data);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function delete(): void {
        require_method('POST');
        AuthMiddleware::requireAdmin();
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';
        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $param  = new ParamPosMarketplace();
        $result = $param->deleteSubMerchant($data);
        if (!empty($data['user_id'])) {
            Database::getInstance()->delete('param_marketplace_sellers', 'user_id = ?', [InputSanitizer::positiveInt($data['user_id'])]);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function resubmit(): void {
        self::register();
    }

    public static function status(): void {
        require_method('GET');
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/checkout_payments.php';

        $db   = Database::getInstance();
        $conn = $db->getConnection();
        ensureParamMarketplaceTables($conn);

        $seller = $db->selectSingle('status, guid_altuyeisyeri, tip, last_error, last_attempt_at FROM param_marketplace_sellers WHERE user_id = ?', [$userId]);
        $bank   = $db->selectSingle('id, account_type, full_name, authorized_first_name, authorized_last_name, company_title, tax_number, tax_office, id_number, phone, iban, il_kod, ilce_kod FROM banka_bilgileri WHERE user_id = ?', [$userId]);

        $hasBankInfo = false;
        $missing     = [];
        if ($bank) {
            $required = ['account_type', 'phone', 'iban', 'il_kod', 'ilce_kod'];
            $typeMap  = ['Bireysel Hesap' => 1, 'Şahıs Şirketi' => 2, 'Kurumsal Hesap' => 3];
            $tip      = $typeMap[$bank['account_type'] ?? ''] ?? 1;
            if ($tip === 3)      $required = array_merge($required, ['authorized_first_name', 'authorized_last_name', 'company_title', 'tax_number', 'tax_office']);
            elseif ($tip === 2)  $required = array_merge($required, ['full_name', 'id_number', 'tax_office']);
            else                 $required = array_merge($required, ['full_name', 'id_number']);
            foreach ($required as $field) { if (empty($bank[$field])) $missing[] = $field; }
            $hasBankInfo = empty($missing);
        }

        $status = $seller['status'] ?? null;
        if ($status === null)                          $status = $hasBankInfo ? 'kyc_filled' : 'not_started';
        elseif ($status === 'not_started' && $hasBankInfo) $status = 'kyc_filled';

        echo json_encode([
            'success' => true, 'status' => $status, 'guid' => $seller['guid_altuyeisyeri'] ?? null,
            'tip' => $seller['tip'] ?? null, 'last_error' => $seller['last_error'] ?? null,
            'last_attempt_at' => $seller['last_attempt_at'] ?? null,
            'has_bank_info' => $hasBankInfo, 'missing_fields' => $missing,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function reconcile(): void {
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';
        require_once __DIR__ . '/../../../functions/checkout_payments.php';
        set_time_limit(300);

        $cronSecret = $_ENV['PARAM_RECONCILE_SECRET'] ?? $_SERVER['PARAM_RECONCILE_SECRET'] ?? getenv('PARAM_RECONCILE_SECRET') ?: '';
        $provided   = $_GET['secret'] ?? $_POST['secret'] ?? $_SERVER['HTTP_X_RECONCILE_SECRET'] ?? '';

        // Fail-closed is correct, but an unset PARAM_RECONCILE_SECRET and a
        // wrong one were indistinguishable: the reconciliation cron just got
        // "Yetkisiz." forever with nothing to diagnose from. The response stays
        // identical either way — an anonymous caller learns nothing — while the
        // operator gets the actual reason in the error log.
        if ($cronSecret === '') {
            error_log('[reconcile] PARAM_RECONCILE_SECRET is not set — the reconciliation endpoint will reject every call. Set it in api/.env (see api/.env.example).');
            JsonResponse::error('Yetkisiz.', 403, AppConfig::ERR_PERMISSION);
        }
        if (!hash_equals((string) $cronSecret, (string) $provided)) {
            JsonResponse::error('Yetkisiz.', 403, AppConfig::ERR_PERMISSION);
        }

        $db = Database::getInstance();
        reconcilePayments($db, $db->getConnection());
    }

    /**
     * N-22 — KAPATILDI (410). `POST /api/seller/marketplace_refund.php`.
     *
     * Bu uç yalnızca admin oturumuna bakıyordu, CSRF denetimi YOKTU: admin
     * paneli açıkken ziyaret edilen üçüncü taraf bir sayfa, admin'in çerezini
     * taşıyan bir POST ile herhangi bir ödemeyi iade ettirebilirdi (gerçek
     * para hareketi). Hiçbir yer çağırmıyordu (`web/src`, `api/admin`,
     * `api/router.php` araması, 2026-10-07).
     *
     * İade artık YALNIZCA admin panelinin CSRF korumalı "Paket Ödemeleri"
     * ekranından (`api/admin/ajax/odemeler.php` → `hostedPlanRefund()`).
     * İade fonksiyonları yerinde duruyor: `hostedPlanRefund()`
     * (functions/hosted_plan_payments.php) ve iyzico `processRefund()`
     * (functions/checkout_payments.php). iyzico/pazaryeri iadesi için şu an
     * bir ekran yok (iyzico zaten anahtarsız, kapalı).
     */
    public static function refund(): void {
        JsonResponse::error('Bu işlem artık desteklenmiyor. İade admin panelinden yapılır.', 410, AppConfig::ERR_VALIDATION);
    }

    /**
     * PAY-007 🟠 — bu uç nokta TAMAMEN korumasızdı.
     *
     * `$_POST`'u olduğu gibi `handleParamCallback()`'e veriyordu: imza
     * doğrulaması yok, kaynak IP kontrolü yok, replay koruması yok, hız
     * sınırı yok. Gerçek callback mantığı devreye girdiğinde (bugün stub)
     * bu, internetteki herhangi birinin "ödeme başarılı" bildirimi
     * uydurabilmesi anlamına gelir — yani ödemeden ürün.
     *
     * Buradaki üç katman, gerçek entegrasyondan BAĞIMSIZ olarak doğru:
     *   1. Paylaşılan sır (PARAM_CALLBACK_SECRET) — fail-closed. Tanımlı
     *      değilse callback hiç kabul edilmiyor; sessizce açık kalmıyor.
     *   2. Replay koruması — aynı işlem kimliği ikinci kez işlenmiyor.
     *   3. Hız sınırı — imza denemesi yapan bir saldırgan sınırsız
     *      deneyemesin.
     *
     * Param POS'un gerçek imza şeması (hash algoritması ve alan sırası)
     * entegrasyon yazılırken buraya eklenmeli; o gelene kadar paylaşılan sır
     * asgari ve gerçek bir savunma.
     */
    public static function paramposCallback(): void {
        require_method('POST');
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';
        require_once __DIR__ . '/../../../functions/checkout_payments.php';

        $db = Database::getInstance();

        checkRateLimit($db, 'parampos_cb:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60);

        $secret   = env_get('PARAM_CALLBACK_SECRET', '') ?? '';
        $provided = (string) ($_POST['secret'] ?? $_SERVER['HTTP_X_PARAM_SIGNATURE'] ?? '');

        if (trim($secret) === '') {
            error_log('[parampos_callback] PARAM_CALLBACK_SECRET tanımlı değil — callback reddediliyor. api/.env dosyasına ekleyin.');
            JsonResponse::error('Yetkisiz.', 403, AppConfig::ERR_PERMISSION);
        }
        if (!hash_equals($secret, $provided)) {
            error_log('[parampos_callback] geçersiz imza, ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
            JsonResponse::error('Yetkisiz.', 403, AppConfig::ERR_PERMISSION);
        }

        // Replay: aynı bildirim tekrar tekrar gönderilip aynı siparişi
        // birden çok kez "ödendi" yapamasın.
        $eventId = (string) ($_POST['Islem_ID'] ?? $_POST['islem_id'] ?? $_POST['order_id'] ?? '');
        if ($eventId !== '') {
            $db->ensureTable('param_callback_events', 'CREATE TABLE IF NOT EXISTS param_callback_events (
                    event_id   VARCHAR(191) PRIMARY KEY,
                    seen_at    DATETIME NOT NULL
                )');

            try {
                $inserted = $db->execute(
                    'INSERT IGNORE INTO param_callback_events (event_id, seen_at) VALUES (?, NOW())',
                    [$eventId]
                );
                if ($inserted === 0) {
                    // Zaten işlenmiş. Gateway'e 200 dönüyoruz ki tekrar
                    // denemesin, ama hiçbir şey yeniden işlenmiyor.
                    error_log('[parampos_callback] yinelenen bildirim yoksayıldı: ' . $eventId);
                    http_response_code(200);
                    echo 'OK';
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[parampos_callback] replay kontrolü başarısız: ' . $e->getMessage());
            }
        }

        handleParamCallback($db, $db->getConnection(), $_POST);
    }

    public static function listIller(): void {
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';

        // B-09 — bu iki uç nokta kimlik doğrulaması ve hız sınırı olmadan DIŞ
        // sağlayıcıya gidiyordu: anonim biri `?nocache` ile önbelleği atlayıp
        // sağlayıcıya karşı sınırsız istek ürettirebiliyordu (bizim üye iş
        // yeri kimliğimizle). İl/ilçe listesi yalnızca satıcı kayıt formunda
        // kullanılıyor, yani oturum zaten var.
        $userId = AuthMiddleware::requireAuth();
        checkRateLimit(Database::getInstance(), 'listiller:' . $userId, 30, 300);

        $cacheFile   = sys_get_temp_dir() . '/param_iller.json';
        $cacheTtl    = 900;
        // `nocache` yalnızca admin oturumunda: sıradan kullanıcı önbelleği
        // atlatıp her istekte sağlayıcıya gidemesin.
        $bypassCache = isset($_GET['nocache']) && !empty($_SESSION['admin']);

        if (!$bypassCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached  = file_get_contents($cacheFile);
            $decoded = $cached ? json_decode($cached, true) : null;
            $first   = $decoded['items'][0] ?? null;
            if (is_array($first) && (int) ($first['IL_Kodu'] ?? 0) > 0 && !empty($first['IL_Adi'])) {
                echo $cached; exit;
            }
            @unlink($cacheFile);
        }

        $result = (new ParamPosMarketplace())->listIller();
        if (!$result['success']) JsonResponse::error($result['message'] ?: 'Param il listesi alınamadı.', 502);

        $payload = json_encode(['success' => true, 'items' => $result['items']], JSON_UNESCAPED_UNICODE);
        @file_put_contents($cacheFile, $payload);
        echo $payload;
        exit;
    }

    public static function listIlceler(): void {
        require_once __DIR__ . '/../../../functions/ParamPosMarketplace.php';

        // B-09 — bkz. listIller(); aynı gerekçe.
        $userId = AuthMiddleware::requireAuth();
        checkRateLimit(Database::getInstance(), 'listilceler:' . $userId, 60, 300);

        // Frontend (BankInfo.jsx, SellerOnboardingWizard.jsx) sends `il`, not `il_kodu`.
        $ilKodu    = InputSanitizer::positiveInt($_GET['il'] ?? 0);
        $cacheFile = sys_get_temp_dir() . '/param_ilceler_' . $ilKodu . '.json';
        $cacheTtl  = 900;

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false) { echo $cached; exit; }
        }

        $result  = (new ParamPosMarketplace())->listIlceler($ilKodu);
        $payload = json_encode($result, JSON_UNESCAPED_UNICODE);
        @file_put_contents($cacheFile, $payload);
        echo $payload;
        exit;
    }

    /**
     * DEP-002: kişisel veri taşıyan alanları maskeler; anahtar adları kalır.
     * ParamPosMarketplace::redact() ile aynı listeyi paylaşır ama burada
     * SellerController'ın kendi alan adları (Yetkili_Kisi_TC vb.) da var.
     */
    private static function redactSellerPayload(array $params): array {
        $sensitive = [
            'TC_VN', 'IBAN_No', 'GSM_No', 'Adres', 'EPosta',
            'Kisi_DogumTarihi', 'Yetkili_Kisi_TC', 'Yetkili_Kisi_DogumTarihi',
            'Ad_Soyad', 'IBAN_Unvan', 'Unvan',
        ];

        $out = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $sensitive, true)) {
                $text      = (string) $value;
                $out[$key] = $text === '' ? '(boş)' : '***(' . strlen($text) . ' karakter)';
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
