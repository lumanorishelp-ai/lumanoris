<?php
class WalletController {
    /**
     * Shared by getMyBalance() (display) and withdraw() (validation) so the
     * two can never drift into disagreeing about what a seller's balance is.
     *
     * DB-003 🟠 — bu sorgu `param_marketplace_payments`'a JOIN yapıyordu ama
     * `p.status`'u hiç okumuyordu; yalnızca `d.status`'a bakıyordu. `d.status`
     * ise her zaman 'approved' yazıldığı için (PAY-001) ödeme durumu sütununun
     * para üzerinde HİÇBİR etkisi yoktu — şemada duran `idx_status` index'i de
     * kullanılmıyordu.
     *
     * Artık bir satır bakiyeye ancak İKİSİ birden onaylıysa giriyor:
     * tahsilatın kendisi ('paid') ve satıcı payı ('approved'). Filtre SQL'de,
     * yani index kullanılabiliyor; ve fail-closed: tanınmayan bir durum
     * bakiyeye eklenmiyor.
     */
    /**
     * PAY-005 🟠 — çekim geçmişini okuyan sorgu istisnayı YUTUYORDU. Okuma
     * başarısız olduğunda (tablo yok, izin hatası, geçici bir DB sorunu)
     * bakiye "hiç çekim yapılmamış gibi" hesaplanıyordu — yani şişmiş.
     * `withdraw()` bu şişmiş değeri doğrulama ölçütü olarak kullanıyordu.
     *
     * $strict = true olduğunda istisna yükseltiliyor. Gösterimde (getMyBalance)
     * tolerans kabul edilebilir; doğrulamada (withdraw) asla.
     */
    private static function computeBalanceAndTransactions(Database $db, int $userId, bool $strict = false): array {
        $incomeRows = $db->selectMulti(
            "d.payable_amount, d.status, d.created_at, p.order_id, p.status AS payment_status
             FROM param_marketplace_details d
             JOIN param_marketplace_payments p ON p.id = d.payment_id
             WHERE d.seller_user_id = ?
               AND p.status IN ('paid', 'refunded')
             ORDER BY d.created_at DESC",
            [$userId]
        );

        $withdrawRows = [];
        try {
            $withdrawRows = $db->selectMulti('* FROM para_cekme_talepleri WHERE user_id = ? ORDER BY id DESC', [$userId]);
        } catch (Exception $e) {
            error_log('[getmybalance] para_cekme_talepleri okunamadı: ' . $e->getMessage());
            // PAY-005: doğrulama yolunda yutma yok — eksik veriyle bakiye
            // hesaplamak, gerçekte olmayan parayı çekilebilir göstermek demek.
            if ($strict) {
                throw $e;
            }
        }

        $transactions = [];
        $balance      = 0.0;

        foreach ($incomeRows as $r) {
            $amount        = (float) $r['payable_amount'];
            $paymentStatus = (string) ($r['payment_status'] ?? '');

            // Tahsilat gerçekten alınmadıysa satıcı payı ne yazarsa yazsın
            // bakiyeye girmez.
            if ($r['status'] === 'approved' && $paymentStatus === 'paid') {
                $balance        += $amount;
                $transactions[] = ['amount' => $amount, 'type' => 'income', 'status' => $r['status'], 'created_at' => $r['created_at'], 'description' => 'Satışlarınızdan elde ettiğiniz gelir bakiyenize aktarıldı. #' . $r['order_id']];
            } elseif ($r['status'] === 'refunded' || $paymentStatus === 'refunded') {
                $balance        -= $amount;
                $transactions[] = ['amount' => -$amount, 'type' => 'refund', 'status' => 'refunded', 'created_at' => $r['created_at'], 'description' => 'Satış iadesi işlendi. #' . $r['order_id']];
            }
        }

        foreach ($withdrawRows as $w) {
            $amount = (float) ($w['miktar'] ?? 0);
            $durum  = (string) ($w['durum'] ?? '');
            if ($durum !== 'reddedildi' && $durum !== 'iptal') {
                $balance -= $amount;
            }
            $transactions[] = ['amount' => -$amount, 'type' => 'withdrawal', 'status' => $durum, 'created_at' => $w['created_at'] ?? null, 'description' => 'Para çekme talebi (' . ($durum !== '' ? $durum : 'beklemede') . ')'];
        }

        usort($transactions, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return ['balance' => round($balance, 2), 'transactions' => $transactions];
    }

    public static function getMyBalance(): void {
        $userId = AuthMiddleware::requireAuth();
        $result = self::computeBalanceAndTransactions(Database::getInstance(), $userId);

        echo json_encode(array_merge(['success' => true], $result));
        exit;
    }

    public static function getIban(): void {
        $userId = AuthMiddleware::requireAuth();

        $row = Database::getInstance()->selectSingle('iban FROM banka_bilgileri WHERE user_id = ?', [$userId]);
        JsonResponse::success(['iban' => $row['iban'] ?? null]);
    }

    public static function withdraw(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || !isset($data['iban'], $data['amount'])) {
            JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        }

        $amount = InputSanitizer::price($data['amount']);
        if ($amount <= 0) {
            JsonResponse::error('Geçersiz tutar.', 400, AppConfig::ERR_VALIDATION);
        }

        // COMP-004 — IBAN biçim/sağlama doğrulaması, kilidi ALMADAN ÖNCE.
        //
        // Eskiden tek işlem `InputSanitizer::string($data['iban'], 40)` idi:
        // "asdf" da geçerli bir çekim talebi açıyordu. Talep `beklemede`
        // kalıyor ve tutar bakiyeden düşülüyor, yani hatalı IBAN kullanıcının
        // parasını da kilitliyordu.
        //
        // Doğrulama kilidin ÖNÜNDE: geçersiz istek için 10 saniyelik named
        // lock'u meşgul etmenin anlamı yok.
        try {
            $iban = BankIdentity::normalizeIban($data['iban']);
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        $db = Database::getInstance();

        // COMP-005b — para YALNIZCA kullanıcının kayıtlı IBAN'ına çekilebilir.
        //
        // COMP-004 IBAN'ın geçerli bir IBAN olduğunu doğruluyor; bu blok onun
        // KULLANICIYA AİT olduğunu doğruluyor. İkisi ayrı şey: eskiden istekle
        // gelen herhangi bir geçerli IBAN kabul ediliyordu, yani kullanıcı
        // bakiyesini üçüncü bir kişinin hesabına gönderebiliyordu. Kimlik
        // doğrulanmadan üçüncü kişiye para akıtmak, ödeme kuruluşu risk
        // kriterlerinde ağır kalem (BLOCKERS B3) ve kayıtlı hesap dışına çıkan
        // her transfer aklama şüphesi doğurur.
        //
        // Karşılaştırma NORMALİZE edilmiş biçim üzerinden: `saveBankInfo()`
        // artık boşluksuz/büyük harf saklıyor ama bu yamadan ÖNCE kaydedilmiş
        // satırlar "TR12 3456 …" biçiminde duruyor. Ham string karşılaştırması
        // o kullanıcıları kendi IBAN'larından kilitlerdi.
        $bankRow    = $db->selectSingle('iban FROM banka_bilgileri WHERE user_id = ?', [$userId]);
        $storedIban = trim((string) ($bankRow['iban'] ?? ''));

        if ($storedIban === '') {
            JsonResponse::error(
                'Para çekebilmek için önce Cüzdan > Banka Bilgileri bölümünden IBAN\'ınızı kaydedin.',
                422,
                AppConfig::ERR_VALIDATION
            );
        }

        try {
            $storedIban = BankIdentity::normalizeIban($storedIban);
        } catch (AppException $e) {
            // Kayıtlı IBAN bu yamadan önce doğrulanmadan yazılmış ve geçersiz.
            // Kullanıcıyı çıplak bir doğrulama hatasıyla baş başa bırakmak
            // yerine ne yapması gerektiğini söylüyoruz.
            JsonResponse::error(
                'Kayıtlı IBAN\'ınız geçerli görünmüyor. Lütfen Cüzdan > Banka Bilgileri '
                . 'bölümünden güncelleyip tekrar deneyin.',
                422,
                AppConfig::ERR_VALIDATION
            );
        }

        if ($iban !== $storedIban) {
            JsonResponse::error(
                'Para çekme talebi yalnızca hesabınıza kayıtlı IBAN\'a yapılabilir. '
                . 'Farklı bir hesaba çekmek için önce Cüzdan > Banka Bilgileri bölümünden '
                . 'IBAN\'ınızı güncelleyin.',
                422,
                AppConfig::ERR_VALIDATION
            );
        }

        // Previously inserted a withdrawal request for any client-supplied
        // amount with no check against the seller's actual balance — a user
        // could request (and, once approved, receive) a withdrawal far larger
        // than they've ever earned.
        $conn = $db->getConnection();

        // computeBalanceAndTransactions() is a plain SELECT with no locking,
        // so two concurrent withdraw() calls for the same user could both
        // read the same "available" balance before either's insert commits,
        // both pass the check above, and together withdraw more than the
        // real balance. A MySQL named lock scoped to this user forces
        // concurrent withdraw() calls for the same account to run one at a
        // time, so the second call's balance read always sees the first
        // call's already-inserted request.
        $lockName = 'withdraw_user_' . $userId;
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS locked');
        $lockStmt->execute([$lockName]);
        if ((int) ($lockStmt->fetch()['locked'] ?? 0) !== 1) {
            JsonResponse::error('İşlem şu anda gerçekleştirilemiyor, lütfen tekrar deneyin.', 409, AppConfig::ERR_VALIDATION);
        }

        $conn->beginTransaction();
        try {
            $available = self::computeBalanceAndTransactions($db, $userId, true)['balance'];
            if ($amount > $available) {
                $conn->rollBack();
                $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
                JsonResponse::error('Talep edilen tutar mevcut bakiyenizi aşıyor.', 400, AppConfig::ERR_VALIDATION);
            }

            $id = $db->insert('para_cekme_talepleri', [
                'user_id' => $userId,
                // COMP-004: normalize edilmiş (boşluksuz, büyük harf) biçim.
                'iban'    => $iban,
                'miktar'  => $amount,
                'durum'   => 'beklemede',
            ]);
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            throw $e;
        }
        $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

        JsonResponse::success(['message' => 'Para çekme talebi oluşturuldu.', 'id' => $id]);
    }

    public static function getBankInfo(): void {
        $userId = AuthMiddleware::requireAuth();

        $row = Database::getInstance()->selectSingle('* FROM banka_bilgileri WHERE user_id = ?', [$userId]);
        JsonResponse::success(['bank_info' => $row]);
    }

    public static function saveBankInfo(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) {
            JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        }

        $db     = Database::getInstance();

        // Whitelist matches the real banka_bilgileri schema (verified via live
        // DESCRIBE — the old list referenced columns like ad_soyad/sube_kodu/
        // hesap_no that don't exist, so every save silently dropped almost
        // every field except iban).
        $allowed  = [
            'user_id', 'account_type', 'full_name', 'authorized_first_name', 'authorized_last_name',
            'company_title', 'tax_number', 'tax_office', 'id_number', 'phone', 'iban', 'address',
            'il', 'ilce', 'il_kod', 'ilce_kod', 'mahalle', 'cadde', 'sokak', 'bina_no', 'kapi_no',
            'posta_kodu', 'kisi_dogum_tarihi', 'yetkili_kisi_dogum_tarihi',
        ];
        $filtered = array_intersect_key($data, array_flip($allowed));
        $filtered['user_id'] = $userId;

        // COMP-004 — ödeme kimliği doğrulaması.
        //
        // Bu blok gelmeden önce beyaz listeden geçen HER değer doğrudan
        // tabloya yazılıyordu: "TR00", "asdf", 3 haneli bir TCKN — hepsi
        // kabul ediliyordu. Parayı elle havale eden operatör hatayı ancak
        // bankada görürdü ve yanlış hesaba giden havale geri dönmeyebilir
        // (BLOCKERS B7).
        //
        // Alanlar YALNIZCA gönderildiyse ve boş değilse doğrulanıyor:
        // `BankInfo.jsx` formu kısmi kayıt yapabiliyor (önce IBAN, sonra
        // adres), zorunlu kılmak mevcut akışı bozardı. Hesap tipine göre
        // hangi numaranın isteneceği ise ürün kararı — burada tip ne olursa
        // olsun, GÖNDERİLEN numara doğru biçimde olmak zorunda.
        try {
            if (isset($filtered['iban']) && trim((string) $filtered['iban']) !== '') {
                // Normalize edilmiş biçim yazılıyor: form "TR12 3456 …" diye
                // boşluklu gönderiyor, sütun varchar(40). Boşluklu saklamak
                // ileride iki kaydın aynı IBAN olduğunu görmeyi zorlaştırır.
                $filtered['iban'] = BankIdentity::normalizeIban($filtered['iban']);
            }
            if (isset($filtered['id_number']) && trim((string) $filtered['id_number']) !== '') {
                $filtered['id_number'] = BankIdentity::normalizeTckn($filtered['id_number']);
            }
            if (isset($filtered['tax_number']) && trim((string) $filtered['tax_number']) !== '') {
                $filtered['tax_number'] = BankIdentity::normalizeVkn($filtered['tax_number']);
            }
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        $existing = $db->selectSingle('id FROM banka_bilgileri WHERE user_id = ?', [$userId]);

        if ($existing) {
            unset($filtered['user_id']);
            $db->update('banka_bilgileri', $filtered, 'user_id = ?', [$userId]);
            JsonResponse::success(['message' => 'Banka bilgileri güncellendi.']);
        } else {
            $db->insert('banka_bilgileri', $filtered);
            JsonResponse::success(['message' => 'Banka bilgileri kaydedildi.']);
        }
    }

    public static function getMyPayments(): void {
        $userId = AuthMiddleware::requireAuth();

        // Real column names are user_id/amount, not buyer_user_id/total_amount
        // (confirmed via live DESCRIBE — see MarketplaceController::createSubscription).
        //
        // D-06 — `param_marketplace_details` INNER JOIN ile bağlıydı.
        // `upgradePlan()` bir ödeme satırı yazıyor ama HİÇ detay satırı
        // yazmıyor (üyelik paketinin kalemi yok), yani paket satın alan
        // kullanıcının ödemesi bu listede hiç görünmüyordu: para gitti, kayıt
        // yok. LEFT JOIN kalemsiz ödemeyi de getiriyor; kalem alanları NULL
        // gelir ve istemci onları zaten yokluk kontrolüyle okuyor.
        $rows = Database::getInstance()->selectMulti(
            "p.id, p.order_id, p.amount AS total_amount, p.status, p.created_at,
             d.chatbot_id, d.payable_amount AS item_amount, d.status AS item_status,
             c.isim AS chatbot_title
             FROM param_marketplace_payments p
             LEFT JOIN param_marketplace_details d ON d.payment_id = p.id
             LEFT JOIN chatbotlar c ON c.id = d.chatbot_id
             WHERE p.user_id = ?
               AND p.status IN ('paid', 'refunded', 'partial_refund')
             ORDER BY p.created_at DESC",
            [$userId]
        );

        JsonResponse::success(['payments' => $rows]);
    }

    public static function getMySubscriptions(): void {
        $userId = AuthMiddleware::requireAuth();

        // dashboard/purchased/page.jsx renders both an "Aktif" and a "Süresi
        // Doldu" state, so this must return the full purchase history, not
        // just currently-active ones (the previous `status = 1 AND
        // expiry_date > NOW()` filter made the expired state unreachable).
        // Field names match what that page reads: isim, kapak_fotografi,
        // profil_fotografi, kategori_id, is_active.
        $rows = Database::getInstance()->selectMulti(
            "us.id, us.chatbot_id, us.expiry_date, us.status,
             c.isim, c.kapak_fotografi, c.profil_fotografi, c.kategori_id,
             (us.status = 1 AND us.expiry_date > NOW()) AS is_active
             FROM user_subscriptions us
             JOIN chatbotlar c ON c.id = us.chatbot_id
             WHERE us.user_id = ?
             ORDER BY us.expiry_date DESC",
            [$userId]
        );

        JsonResponse::success(['subscriptions' => $rows]);
    }

    /**
     * 4 sabit üyelik paketi (Ücretsiz/Gümüş/Altın/Elmas). Fiyat ve özellikler
     * yer tutucu değerlerdir — iş ekibi tarafından kolayca güncellenebilir.
     */
    /**
     * BIZ-002 🟠 — katalog KODDA duruyordu: dört plan, fiyatları ve
     * özellikleriyle birlikte bir PHP dizisiydi; `plans` tablosu 0 satırdı.
     * Yani veritabanında bir plan tablosu vardı ama hiçbir şey onu okumuyor,
     * hiçbir şey ona yazmıyordu.
     *
     * Artık katalog `plans` + `plan_icerikler`'den okunuyor (migration 007).
     * Tablo hazır değilse aşağıdaki kodlanmış listeye düşüyor — böylece
     * migration uygulanmadan da sayfa çalışmaya devam ediyor.
     *
     * Kullanıcının mevcut planı da işaretleniyor: `is_current`. Eskiden
     * "Mevcut Paket" etiketi Ücretsiz plana sabitlenmişti.
     */
    public static function getPricing(): void {
        $userId = AuthMiddleware::optionalAuth();
        $db     = Database::getInstance();
        require_once __DIR__ . '/../../../functions/plans.php';

        $catalog = getPlanCatalog($db);

        if ($catalog !== []) {
            $currentPlan = $userId > 0 ? getUserPlanName($db, $userId) : 'Ücretsiz';
            $badges      = ['Altın' => 'Önerilen'];
            $output      = [];

            foreach ($catalog as $p) {
                $isCurrent = ($p['name_tr'] === $currentPlan);
                $price     = (float) ($p['monthly_price'] ?? 0);

                $output[] = [
                    'title'         => $p['name_tr'],
                    'monthly_price' => $price > 0
                        ? '₺' . number_format($price, 2, ',', '.')
                        : '₺0',
                    'yearly_price'  => $p['yearly_price'] !== null
                        ? '₺' . number_format((float) $p['yearly_price'], 2, ',', '.')
                        : null,
                    'description'   => $p['description_tr'] ?? '',
                    'features'      => $p['features'] ?? [],
                    'buttonText'    => $isCurrent ? 'Mevcut Paket' : 'Bu Paketi Seç',
                    'buttonType'    => ($p['name_tr'] === 'Altın') ? 'primary' : 'default',
                    'badge'         => $badges[$p['name_tr']] ?? null,
                    'is_current'    => $isCurrent,
                    // Pazarlama metni yerine gerçek kotalar — istemci
                    // isterse "3 bot / 50 mesaj" diye gösterebilir.
                    // Migration 012: bot limitleri NULL = sınırsız. `(int)`
                    // NULL'ı 0'a çevirirdi; sınırsız artık `null` olarak gidiyor.
                    'limits'        => [
                        'independent_bots' => $p['independent_bot_limit'] === null ? null : (int) $p['independent_bot_limit'],
                        'public_bots'      => $p['public_bot_limit'] === null ? null : (int) $p['public_bot_limit'],
                        'daily_messages'   => (int) $p['daily_message_limit'],
                        'privacy_rights'   => (int) ($p['privacy_right_limit'] ?? AppConfig::FREE_PRIVACY_RIGHT_LIMIT),
                    ],
                ];
            }

            JsonResponse::success(['all_plans' => $output]);
        }

        // E-04 — burada kodlanmış dört planlık bir "geri düşüş" kataloğu
        // vardı: `plans` tablosuyla senkron tutan hiçbir mekanizma yoktu ve
        // fiyatları (₺149/₺299/₺599) tablodakinden sessizce ayrışabiliyordu.
        // Daha kötüsü, `upgradePlan()` aynı durumda 503 dönüyor — yani
        // kullanıcı burada fiyat görüp satın almaya kalkınca "kullanılamıyor"
        // cevabı alıyordu. İki metot artık aynı davranıyor: katalog yoksa
        // fiyat da yok.
        error_log('[getPricing] plans kataloğu boş ya da hazır değil (migration 007 uygulanmamış).');
        JsonResponse::error(
            'Paket kataloğu şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin.',
            503,
            AppConfig::ERR_UNAVAILABLE
        );
    }

    /**
     * BIZ-001 🔴 — bu metot ₺149 / ₺299 / ₺599'luk üç paketi **hiçbir ödeme
     * almadan** yazıyordu: `plan_name` doğrulanmıyordu (istemci "Elmas" da
     * yazabilirdi, "Kral" da), hiçbir tahsilat çağrılmıyordu ve kullanıcıya
     * "Üyelik paketiniz güncellendi." deniyordu.
     *
     * Kaydın kendisi de karşılıksızdı (BIZ-002): yazdığı satırı yalnızca
     * dashboard başlığı okuyor; `chatbot_limits.php` ve coin motoru plan
     * satırına hiç bakmıyor, herkese ücretsiz limitleri veriyor. Yani ödeme
     * alınmış olsaydı bile kullanıcı hiçbir şey satın almamış olacaktı.
     *
     * Bunu tekrar açmak için gereken üç şeyin ÜÇÜ DE tamamlandı:
     *   1. gerçek tahsilat var — önce `chargeCard()` (iyzico, PAY-001),
     *      Faz 7a'dan beri Hoppa Ortak Ödeme Sayfası + ProcessQuery,
     *   2. plan adı VE FİYATI sunucudaki `plans` kataloğundan okunuyor —
     *      istemci ne plan adı uyduruyor ne de tutar gönderiyor,
     *   3. `functions/plans.php` üzerinden `chatbot_limits.php` ve
     *      `coin_engine.php` bu satırı gerçekten okuyor (BIZ-002).
     *
     * Yani ödeme artık karşılıksız değil: yazılan `user_plan_selection`
     * satırı doğrudan bot ve mesaj kotasına dönüşüyor.
     */
    /**
     * D-05 — `user_plan_selection` satırı.
     *
     * `expires_at` migration 011 ile geldi; uygulanmamış bir kurulumda
     * sütuna yazmak SQL hatası verip tahsilat SONRASI adımı düşürürdü
     * (yani "para çekildi, paket verilmedi"). Bu yüzden sütun varlığı
     * kontrol ediliyor — `paymentsColumnExists` ile aynı savunma.
     *
     * @param string|null $expiresAt null = süresiz (ücretsiz plan)
     */
    private static function planSelectionRow(Database $db, int $userId, string $planName, ?string $expiresAt): array {
        $row = [
            'user_id'     => $userId,
            'plan_name'   => $planName,
            'selected_at' => date('Y-m-d H:i:s'),
        ];

        require_once __DIR__ . '/../../../functions/plans.php';
        if (planSelectionHasExpiry($db)) {
            $row['expires_at'] = $expiresAt;
        } elseif ($expiresAt !== null) {
            error_log(
                '[upgradePlan] user_plan_selection.expires_at yok (migration 011 uygulanmamış) — '
                . 'paket SÜRESİZ yazılıyor. user_id=' . $userId . ' plan=' . $planName
            );
        }

        return $row;
    }

    public static function upgradePlan(): void {
        require_method('POST');
        require_once __DIR__ . '/../../../functions/checkout_payments.php';
        require_once __DIR__ . '/../../../functions/plans.php';

        $userId   = AuthMiddleware::requireAuth();
        $data     = json_decode($_POST['data'] ?? '', true) ?? null;
        $planName = InputSanitizer::string($data['plan_name'] ?? '', 30);

        if (!$planName) {
            JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        }

        $db = Database::getInstance();
        checkRateLimit($db, 'upgradeplan:' . $userId, 5, 60);

        // Katalog hazır değilse (migration 007 uygulanmamış) plan satırı
        // hiçbir limit üretmez — o durumda para almak karşılıksız tahsilat
        // olurdu. Fail-closed.
        if (!plansTableReady($db)) {
            error_log('[upgradePlan] plans kataloğu hazır değil (migration 007 uygulanmamış) — yükseltme reddedildi.');
            JsonResponse::error(
                'Paket kataloğu şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin.',
                503,
                AppConfig::ERR_UNAVAILABLE
            );
        }

        // Plan adı VE fiyatı sunucudan geliyor. İstemcinin gönderdiği tek
        // şey plan adı; tutarı asla istemci belirlemiyor.
        $plan = $db->selectSingle('id, name_tr, monthly_price FROM plans WHERE name_tr = ?', [$planName]);
        if (!$plan) {
            JsonResponse::error('Geçersiz paket.', 400, AppConfig::ERR_VALIDATION);
        }

        $price = round((float) $plan['monthly_price'], 2);

        // Ücretsiz plan (ya da fiyatı tanımlanmamış plan) için tahsilat yok —
        // ama ücretli bir planın fiyatı NULL kalmışsa bu bir yapılandırma
        // hatası; sessizce bedava vermek yerine reddediyoruz.
        if ($price <= 0) {
            // selectSingle() satır yoksa false döner; doğrudan ['id'] yazmak
            // PHP 8'de "array offset on bool" uyarısı üretir.
            $defaultPlan   = $db->selectSingle('id FROM plans WHERE is_default = 1 ORDER BY sort_order LIMIT 1');
            $defaultPlanId = $defaultPlan ? (int) $defaultPlan['id'] : 0;

            if ((int) $plan['id'] !== $defaultPlanId) {
                error_log('[upgradePlan] ücretli plan için fiyat tanımsız: ' . $planName);
                JsonResponse::error('Bu paket için geçerli bir fiyat tanımlanmamış.', 422, AppConfig::ERR_VALIDATION);
            }
            // Ücretsiz plan süresiz: bitiş tarihi yok (D-05).
            $db->insert('user_plan_selection', self::planSelectionRow($db, $userId, (string) $plan['name_tr'], null), true);
            JsonResponse::success(['message' => 'Üyelik paketiniz güncellendi.', 'plan_name' => $plan['name_tr']]);
        }

        // Faz 7a — ücretli paket Hoppa Ortak Ödeme Sayfası'ndan satılıyor.
        // Kart verisi bu uca ARTIK HİÇ gelmiyor (N-17): kullanıcı Hoppa'nın
        // sayfasına yönlendirilir, sonuç `hoppaReturn()` + ProcessQuery ile
        // kesinleşir, paket ancak o anda tanımlanır. Eski `chargeCard`
        // (iyzico, kartlı) yolu paket için kaldırıldı; pazaryeri hâlâ onu
        // kullanıyor ve dokunulmadı.
        $gateway = PaymentGatewayFactory::make();
        if ($gateway === null) {
            JsonResponse::error(
                'Ödeme altyapısı hazırlanıyor. Paket satın alma kısa süre içinde açılacak.',
                503,
                AppConfig::ERR_UNAVAILABLE
            );
        }

        $start = self::startHostedPlanPayment($db, $gateway, $userId, $plan, (string) env_get('APP_PUBLIC_URL', ''));
        if (!$start['success']) {
            JsonResponse::error($start['message'], $start['http'], $start['code']);
        }

        JsonResponse::success([
            'redirect_url' => $start['redirect_url'],
            'order_id'     => $start['order_id'],
        ]);
    }

    /**
     * Hoppa satırlarının durumları — iyzico mutabakatının taradığı
     * değerlerden (`pending`, `failed`, …) BİLİNÇLİ OLARAK ayrı; bkz.
     * startHostedPlanPayment().
     */
    public const HOSTED_PENDING = 'hoppa_pending';
    public const HOSTED_FAILED  = 'hoppa_failed';
    /**
     * Faz 7a-2: mutabakat işi 24 saati geçmiş ve Hoppa'da hâlâ ödenmemiş
     * görünen siparişi bu duruma çeker (bkz. functions/hosted_plan_payments.php).
     * Son değil: sonradan ödeme kesinleşirse (geç dönüş) paket yine tanımlanır.
     */
    public const HOSTED_EXPIRED = 'hoppa_expired';

    /** Kesinleştirilebilir (henüz paid olmayan) Hoppa durumları. */
    public const HOSTED_OPEN = [self::HOSTED_PENDING, self::HOSTED_FAILED, self::HOSTED_EXPIRED];

    /** Sipariş referansı: Hoppa sınırı 24 karakter; tahmin edilemesin diye 16 hex. */
    public const HOSTED_ORDER_PATTERN = '/^PLN-[A-F0-9]{16}$/';

    /**
     * Ödeme oturumunu açar: önce `hoppa_pending` satırı (D-04: kayıt
     * sağlayıcıdan ÖNCE), sonra Hoppa'ya istek, sonra yönlendirme adresi.
     * Uç nokta ile selftest aynı yolu kullanıyor.
     *
     * Neden `pending` değil `hoppa_pending`: `reconcilePayments()` (iyzico)
     * `pending`/`failed` satırları iyzico'ya soruyor ve "bulunamadı"
     * yanıtında `failed` yazıyor. Hoppa siparişi orada hiç yok, yani iyzico
     * mutabakatı ödenmekte olan bir paketi `failed`'a çekerdi. iyzico koduna
     * dokunmamak için Hoppa satırları ayrı durum değerleri kullanıyor.
     *
     * @return array{success: bool, redirect_url?: string, order_id?: string, message: string, http: int, code: string}
     */
    public static function startHostedPlanPayment(Database $db, PaymentGatewayInterface $gateway, int $userId, array $plan, string $publicUrl): array
    {
        $unavailable = [
            'success' => false,
            'message' => 'Ödeme altyapısı hazırlanıyor. Paket satın alma kısa süre içinde açılacak.',
            'http'    => 503,
            'code'    => AppConfig::ERR_UNAVAILABLE,
        ];

        $publicUrl = rtrim(trim($publicUrl), '/');
        $scheme    = (string) parse_url($publicUrl, PHP_URL_SCHEME);
        // BACK_URL istek başlığından (Host) türetilmiyor: başlık
        // değiştirilerek ödeme sonucu başka bir adrese yollatılabilirdi.
        if ($publicUrl === '' || !filter_var($publicUrl, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
            || (!$gateway->isTest() && $scheme !== 'https')) {
            error_log('[upgradePlan] APP_PUBLIC_URL tanımsız/geçersiz (canlıda https zorunlu) — ödeme başlatılmadı.');
            return $unavailable;
        }

        $price   = round((float) $plan['monthly_price'], 2);
        $orderId = 'PLN-' . strtoupper(InputSanitizer::randomToken(8));

        $buyer = $db->selectSingle(
            'id, ad_soyad, kullanici_adi, eposta, telefon FROM kullanicilar WHERE id = ?',
            [$userId]
        ) ?: [];
        $fullName  = trim((string) ($buyer['ad_soyad'] ?? '')) ?: trim((string) ($buyer['kullanici_adi'] ?? ''));
        $nameParts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lastName  = count($nameParts) > 1 ? (string) array_pop($nameParts) : '-';
        $firstName = $nameParts !== [] ? implode(' ', $nameParts) : '-';

        $db->insert('param_marketplace_payments', [
            'order_id'            => $orderId,
            'user_id'             => $userId,
            'status'              => self::HOSTED_PENDING,
            'amount'              => $price,
            'product_amount'      => $price,
            'param_receipt_id'    => $orderId,
            'items_json'          => json_encode([[
                'plan_id'   => (int) $plan['id'],
                'plan_name' => $plan['name_tr'],
                'price'     => $price,
            ]], JSON_UNESCAPED_UNICODE),
            'param_response_json' => json_encode(['provider' => 'hoppa', 'test' => $gateway->isTest()]),
        ]);

        $res = $gateway->startHostedPayment([
            'order_ref' => $orderId,
            'amount'    => $price,
            'back_url'  => $publicUrl . '/api/wallet/hoppa_return.php',
            // N-20: Hoppa il/ilçe/adres istiyor; kayıtta bu bilgiler yok.
            // Hoppa'nın kendi örneğindeki gibi "-" gidiyor — müşteri ve
            // Hoppa kararı bekliyor (AUDIT N-20).
            'customer'  => [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => (string) ($buyer['eposta'] ?? ''),
                'phone'      => trim((string) ($buyer['telefon'] ?? '')) ?: '-',
                'city'       => '-',
                'state'      => '-',
                'address'    => '-',
            ],
            'products'  => [[
                'id'          => 'PLAN-' . $plan['id'],
                'name'        => $plan['name_tr'] . ' Üyelik Paketi',
                'category'    => 'Üyelik',
                'description' => '30 günlük üyelik paketi',
                'amount'      => $price,
            ]],
        ]);

        $db->update('param_marketplace_payments', [
            'status'               => $res['success'] ? self::HOSTED_PENDING : self::HOSTED_FAILED,
            'param_transaction_id' => $res['provider_ref'] ?? null,
            'redirect_url'         => $res['redirect_url'] ?? null,
            'param_response_json'  => json_encode(
                ['provider' => 'hoppa', 'test' => $gateway->isTest(), 'start' => $res['raw'] ?? []],
                JSON_UNESCAPED_UNICODE
            ),
        ], 'order_id = ?', [$orderId]);

        if (!$res['success']) {
            error_log(sprintf('[upgradePlan] Hoppa ödeme başlatılamadı order=%s code=%s', $orderId, (string) ($res['error_code'] ?? '')));
            return [
                'success' => false,
                'message' => 'Ödeme başlatılamadı. Lütfen biraz sonra tekrar deneyin.',
                'http'    => 502,
                'code'    => AppConfig::ERR_PAYMENT,
            ];
        }

        error_log(sprintf('[upgradePlan] Hoppa ödeme başlatıldı user_id=%d plan=%s order=%s', $userId, $plan['name_tr'], $orderId));
        return ['success' => true, 'redirect_url' => $res['redirect_url'], 'order_id' => $orderId, 'message' => '', 'http' => 200, 'code' => ''];
    }

    /**
     * Ödemeyi sağlayıcıdan sorup kesinleştirir; ödendiyse paketi tanımlar.
     *
     * Tekrar çalıştırmaya dayanıklı: aynı sipariş geri dönüşte, yenilenen
     * sayfada ya da eşzamanlı iki istekte birden işlenebilir. Paket yalnızca
     * `hoppa_pending|hoppa_failed → paid` geçişini YAPAN (koşullu UPDATE'te
     * etkilenen satır = 1) istek tarafından, aynı transaction içinde
     * tanımlanır. Diğerleri satırı `paid` bulur ve hiçbir şey yazmaz.
     *
     * `hoppa_failed` ve `hoppa_expired` yeniden soruluyor: reddedilen ya da
     * süresi dolmuş sayılan bir siparişte sonradan başarılı ödeme gelirse
     * para çekilmiş ama paket verilmemiş olmasın.
     *
     * @return array{state: string, plan_name: ?string, message: string}
     */
    public static function finalizeHostedPlanPayment(Database $db, PaymentGatewayInterface $gateway, string $orderId): array
    {
        $out = static fn (string $state, ?string $plan = null, string $message = ''): array => [
            'state' => $state, 'plan_name' => $plan, 'message' => $message,
        ];

        $row = $db->selectSingle(
            'id, user_id, status, amount, items_json, param_response_json FROM param_marketplace_payments WHERE order_id = ?',
            [$orderId]
        );
        $meta = $row ? json_decode((string) $row['param_response_json'], true) : null;
        $item = $row ? (json_decode((string) $row['items_json'], true)[0] ?? null) : null;
        if (!$row || ($meta['provider'] ?? '') !== 'hoppa' || !is_array($item) || empty($item['plan_name'])) {
            return $out('not_found');
        }
        $planName = (string) $item['plan_name'];

        if ($row['status'] === 'paid') {
            return $out('paid', $planName);
        }
        if (!in_array($row['status'], self::HOSTED_OPEN, true)) {
            return $out('unknown', $planName);
        }

        $q = $gateway->queryPayment($orderId);

        if ($q['state'] === 'paid') {
            if (!HoppaGateway::amountCovers((float) $row['amount'], $q['amount'], $q['commission'])) {
                // Para çekilmiş ama tutar siparişle uyuşmuyor: paket vermiyoruz,
                // satırı da kapatmıyoruz — elle inceleme gerekir.
                error_log(sprintf(
                    '[hoppaFinalize] TUTAR UYUŞMUYOR order=%s beklenen=%.2f çekilen=%s komisyon=%s',
                    $orderId, (float) $row['amount'], var_export($q['amount'], true), var_export($q['commission'], true)
                ));
                return $out('unknown', $planName, 'amount_mismatch');
            }

            // Selftest bu fonksiyonu kendi transaction'ı içinde (ROLLBACK ile)
            // çağırıyor; iç içe BEGIN PDO'da hata verir. Dışarıda transaction
            // varsa ona katılıyoruz, yoksa kendimiz açıp kapatıyoruz.
            $conn = $db->getConnection();
            $ownTx = !$conn->inTransaction();
            if ($ownTx) {
                $conn->beginTransaction();
            }
            try {
                $changed = $db->execute(
                    'UPDATE param_marketplace_payments
                        SET status = \'paid\', param_net_amount = ?, callback_json = ?
                      WHERE id = ? AND status IN (?, ?, ?)',
                    [
                        $q['amount'] !== null && $q['commission'] !== null ? round($q['amount'] - $q['commission'], 2) : null,
                        json_encode(['query' => $q['raw']], JSON_UNESCAPED_UNICODE),
                        $row['id'], ...self::HOSTED_OPEN,
                    ]
                );

                if ($changed === 1) {
                    // D-05: 30 günlük tek seferlik satış; bitiş MySQL saatinden.
                    // GK-27: otomatik yenileme yok, kullanıcı her dönem elle yeniler.
                    $expiresAt = (string) $db->selectSingle(
                        'DATE_ADD(NOW(), INTERVAL ? DAY) AS bitis',
                        [AppConfig::SUBSCRIPTION_MONTHLY]
                    )['bitis'];
                    $db->insert('user_plan_selection', self::planSelectionRow($db, (int) $row['user_id'], $planName, $expiresAt), true);
                }
                if ($ownTx) {
                    $conn->commit();
                }
            } catch (Throwable $e) {
                if ($ownTx) {
                    $conn->rollBack();
                }
                // Ödeme Hoppa'da kesin; satır `hoppa_pending` kaldı, sonraki
                // sorgu (geri dönüşün yenilenmesi / mutabakat) yeniden dener.
                error_log('[hoppaFinalize] ödeme alındı ama paket yazılamadı order=' . $orderId . ': ' . $e->getMessage());
                return $out('unknown', $planName, 'activation_failed');
            }

            if ($changed === 1) {
                error_log(sprintf('[hoppaFinalize] paket tanımlandı user_id=%d plan=%s order=%s', (int) $row['user_id'], $planName, $orderId));
            }
            return $out('paid', $planName);
        }

        if (in_array($q['state'], ['failed', 'cancelled'], true)) {
            $db->execute(
                'UPDATE param_marketplace_payments SET status = ?, callback_json = ? WHERE id = ? AND status = ?',
                [self::HOSTED_FAILED, json_encode(['query' => $q['raw']], JSON_UNESCAPED_UNICODE), $row['id'], self::HOSTED_PENDING]
            );
            return $out('failed', $planName, (string) $q['message']);
        }

        // pending / unknown — satıra dokunma.
        return $out(match ($row['status']) {
            self::HOSTED_FAILED  => 'failed',
            self::HOSTED_EXPIRED => 'expired',
            default              => 'pending',
        }, $planName);
    }

    /**
     * POST /api/wallet/hoppa_return.php — Hoppa'nın BACK_URL'i.
     *
     * Kullanıcının tarayıcısı Hoppa'dan buraya form POST ile gelir. Bu
     * çapraz-site bir POST olduğu için oturum çerezi (SameSite=Lax) GELMEZ;
     * uç oturum istemiyor. POST alanlarına güvenilmez: yalnızca sipariş
     * referansı okunur, sonuç sunucudan ProcessQuery ile alınır. Birinin
     * rastgele referans göndermesi en fazla gerçekten ödenmiş bir siparişin
     * kesinleşmesini tetikler (idempotent). Sonunda tarayıcı 303 ile paket
     * sayfasına döner.
     */
    public static function hoppaReturn(): void
    {
        require_once __DIR__ . '/../../../functions/checkout_payments.php';
        require_once __DIR__ . '/../../../functions/plans.php';

        $back = static function (string $result): void {
            header('Location: /dashboard/upgrade?odeme=' . rawurlencode($result), true, 303);
            exit;
        };

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $back('pending');
        }

        $db = Database::getInstance();
        checkRateLimit($db, 'hoppareturn:' . clientIp(), 30, 60);

        $orderId = strtoupper(trim((string) ($_POST['ORDER_REF_NUMBER'] ?? '')));
        if (!preg_match(self::HOSTED_ORDER_PATTERN, $orderId)) {
            $back('failed');
        }

        $gateway = PaymentGatewayFactory::make();
        if ($gateway === null) {
            error_log('[hoppaReturn] sağlayıcı yapılandırılmamış — sonuç kesinleştirilemedi order=' . $orderId);
            $back('pending');
        }

        $result = self::finalizeHostedPlanPayment($db, $gateway, $orderId);
        $back(match ($result['state']) {
            'paid'                => 'paid',
            'failed', 'not_found' => 'failed',
            default               => 'pending',
        });
    }

    public static function getSubscription(): void {
        $userId    = AuthMiddleware::requireAuth();
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);

        if (!$chatbotId) {
            JsonResponse::error('Eksik parametre.', 400, AppConfig::ERR_VALIDATION);
        }

        // Looks at the most recent subscription regardless of whether it has
        // expired — `duration_weeks` from it is used by the frontend to
        // preselect a duration on "Tekrar Satın Al", which is exactly the
        // case where the previous term has already ended. `has_active_sub`
        // still only reflects a currently-valid (non-expired, status=1) row.
        $sub = Database::getInstance()->selectSingle(
            "id, expiry_date, duration_weeks, (status = 1 AND expiry_date > NOW()) AS is_active
             FROM user_subscriptions WHERE user_id = ? AND chatbot_id = ? ORDER BY id DESC LIMIT 1",
            [$userId, $chatbotId]
        );

        if ($sub) {
            $isActive = (bool) $sub['is_active'];
            JsonResponse::success([
                'has_active_sub' => $isActive,
                'expiry_date'    => $isActive ? $sub['expiry_date'] : null,
                'duration_weeks' => (int) $sub['duration_weeks'],
            ]);
        } else {
            JsonResponse::success(['has_active_sub' => false, 'duration_weeks' => null]);
        }
    }

    /**
     * PAY-006 🟠 — para çekme taleplerinin `durum` alanını güncelleyen
     * HİÇBİR kod yoktu.
     *
     * `withdraw()` talebi `durum='beklemede'` ile yazıyordu; ne bir admin
     * ekranı, ne bir endpoint, ne bir job o değeri değiştiriyordu. Tablo
     * legacy admin CRUD motorunun beyaz listesinde de yoktu, yani admin
     * panelinden de dokunulamıyordu. Sonuç: her talep kalıcı olarak
     * "beklemede" kalıyor ve `computeBalanceAndTransactions()` bekleyen
     * talepleri bakiyeden düştüğü için satıcının parası **süresiz olarak
     * kilitleniyordu** — ödeme yapılsa bile.
     *
     * Aşağıdaki iki uç nokta yaşam döngüsünü kapatıyor. Bilinçli olarak
     * legacy CRUD beyaz listesine eklemek yerine ayrı yazıldılar: durum
     * geçişleri serbest metin değil, ve `odendi` yazmak gerçek para hareketi
     * anlamına geldiği için kayıt izi bırakması gerekiyor.
     */
    /**
     * Para çekme talebi durumları.
     *
     * DİKKAT — bu liste veritabanındaki gerçek değerlerle birebir eşleşmek
     * zorunda. İlk yazımda ASCII'ye sadeleştirilmişti (`onaylandi`, `odendi`)
     * ama kayıtlı veri Türkçe yazımı kullanıyor (`onaylandı`). Sonuç: admin
     * bir talebi onaylayamıyor, `?status=onaylandı` filtresi de "Geçersiz
     * durum" veriyordu. Canlı veri kontrolüyle yakalandı.
     *
     * `beklemede` `withdraw()` tarafından yazılıyor; `reddedildi` ve `iptal`
     * `computeBalanceAndTransactions()` tarafından bakiyeden düşülmeyen
     * durumlar olarak okunuyor — üçü de burada aynen korunmalı.
     */
    private const WITHDRAWAL_STATUSES = ['beklemede', 'onaylandı', 'ödendi', 'reddedildi', 'iptal'];

    /**
     * İstemci ASCII yazım gönderebilir (klavye, kopyalama, eski entegrasyon).
     * Kanonik Türkçe biçime çeviriyoruz ki veritabanında tek bir yazım olsun.
     */
    private static function normalizeWithdrawalStatus(string $status): string {
        $aliases = [
            'onaylandi' => 'onaylandı',
            'odendi'    => 'ödendi',
        ];
        $status = trim($status);
        return $aliases[mb_strtolower($status)] ?? $status;
    }

    public static function listWithdrawals(): void {
        AuthMiddleware::requireAdmin();

        $db     = Database::getInstance();
        $status = self::normalizeWithdrawalStatus(InputSanitizer::string($_GET['status'] ?? '', 32));
        if ($status !== '' && !in_array($status, self::WITHDRAWAL_STATUSES, true)) {
            JsonResponse::error('Geçersiz durum filtresi.', 400, AppConfig::ERR_VALIDATION);
        }

        $rows = $status !== ''
            ? $db->selectMulti(
                'p.id, p.user_id, p.iban, p.miktar, p.durum, p.created_at, k.kullanici_adi, k.eposta
                 FROM para_cekme_talepleri p
                 JOIN kullanicilar k ON k.id = p.user_id
                 WHERE p.durum = ? ORDER BY p.id DESC',
                [$status]
            )
            : $db->selectMulti(
                'p.id, p.user_id, p.iban, p.miktar, p.durum, p.created_at, k.kullanici_adi, k.eposta
                 FROM para_cekme_talepleri p
                 JOIN kullanicilar k ON k.id = p.user_id
                 ORDER BY p.id DESC'
            );

        JsonResponse::success(['requests' => $rows]);
    }

    public static function updateWithdrawalStatus(): void {
        require_method('POST');
        $adminName = AuthMiddleware::requireAdmin();

        $data   = json_decode($_POST['data'] ?? '', true) ?? [];
        $id     = InputSanitizer::positiveInt($data['id'] ?? $_POST['id'] ?? 0);
        $status = self::normalizeWithdrawalStatus(InputSanitizer::string($data['durum'] ?? $_POST['durum'] ?? '', 32));

        if (!$id) {
            JsonResponse::error('Talep ID gerekli.', 400, AppConfig::ERR_VALIDATION);
        }
        if (!in_array($status, self::WITHDRAWAL_STATUSES, true)) {
            JsonResponse::error(
                'Geçersiz durum. İzin verilenler: ' . implode(', ', self::WITHDRAWAL_STATUSES),
                400,
                AppConfig::ERR_VALIDATION
            );
        }

        $db      = Database::getInstance();
        $request = $db->selectSingle('id, user_id, miktar, durum FROM para_cekme_talepleri WHERE id = ?', [$id]);
        if (!$request) {
            JsonResponse::error('Talep bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        }

        // Kapanmış bir talebin yeniden açılması bakiyeyi geriye doğru
        // değiştirir; kasıtlı olabilir ama sessizce olmamalı.
        //
        // E-02 — bu liste ASCII yazımla (`odendi`) yazılmıştı, oysa kanonik
        // değer `ödendi` (bkz. WITHDRAWAL_STATUSES ve normalizeWithdrawalStatus).
        // Sonuç: ÖDENMİŞ bir talep "kapalı" sayılmıyordu ve force olmadan
        // `beklemede`ye geri çevrilebiliyordu — yani ödenmiş tutar tekrar
        // bakiyeden düşülüp ikinci kez talep edilebiliyordu. Liste artık
        // kanonik kümeden türetiliyor, ikinci bir yazım kopyası kalmıyor.
        $closed = array_values(array_diff(self::WITHDRAWAL_STATUSES, ['beklemede', 'onaylandı']));
        if (in_array((string) $request['durum'], $closed, true) && empty($data['force'])) {
            JsonResponse::error(
                'Bu talep zaten kapatılmış (' . $request['durum'] . '). Değiştirmek için force gönderin.',
                409,
                AppConfig::ERR_VALIDATION
            );
        }

        $db->update('para_cekme_talepleri', ['durum' => $status], 'id = ?', [$id]);

        error_log(sprintf(
            '[withdrawal] durum güncellendi id=%d user_id=%d tutar=%s %s -> %s admin=%s',
            $id,
            (int) $request['user_id'],
            (string) $request['miktar'],
            (string) $request['durum'],
            $status,
            $adminName
        ));

        JsonResponse::success(['message' => 'Talep durumu güncellendi.', 'id' => $id, 'durum' => $status]);
    }
}
