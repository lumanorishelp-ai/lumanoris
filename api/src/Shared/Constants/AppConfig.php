<?php
/**
 * Application-wide constants.
 * Single source of truth for all magic numbers and configuration values.
 */
final class AppConfig {
    // ── Subscription durations ─────────────────────────────────────────────
    const SUBSCRIPTION_WEEKLY  = 7;
    const SUBSCRIPTION_MONTHLY = 30;

    // ── Free-plan bot limits ───────────────────────────────────────────────
    const FREE_INDEPENDENT_BOT_LIMIT = 1;
    const FREE_PUBLIC_BOT_LIMIT      = 2;
    // Madde 11 / GK-3 / GK-17 — yayınlanmış bir botu geri özele çekme hakkı,
    // TOPLAM (aylık yenilenmez). Canlı değer `plans.privacy_right_limit`
    // (migration 012); bu sabit tablo/kolon yoksa geri düşüş. pricing.js'te
    // karşılığı yok (frontend bu değeri sunucudan okuyor).
    const FREE_PRIVACY_RIGHT_LIMIT   = 1;

    // H-10 — FREE_* bir FALLBACK'tir, ölü değil: `plans.php` canlı kotaları
    // `plans` tablosundan okuyor, tablo yoksa/plan bulunamazsa
    // `fallbackPlan()` bu sabitlere düşüyor.
    //
    // S14 (2026-10-06) — kullanılmayan PRODUCER_INDEPENDENT_LIMIT /
    // PRODUCER_PUBLIC_LIMIT sabitleri, üretici hesabı akışıyla birlikte
    // kaldırıldı.

    // ── Eğitim metni tavanı ────────────────────────────────────────────────
    // B-13 — okuma tarafı (ChatController, Gemini'ye giden bağlam) 60.000
    // karakterle sınırlıydı ama YAZMA tarafı (TrainingController, CONCAT ile
    // LONGTEXT'e ekleme) serbestti. İki taraf artık aynı sabiti okuyor.
    const MAX_TRAINING_CHARS = 60000;

    // ── Daily free messages (coin system) ─────────────────────────────────
    const DAILY_FREE_MESSAGES = 10;

    // ── Ücretsiz planın KANONİK adı ────────────────────────────────────────
    // E-05 — bu ad üç yerde birbirinden bağımsız yazılmıştı: `plans` tablosuna
    // 'Ücretsiz' seed ediliyor (007_plan_limits.sql:74), `plans.php`in geri
    // düşüşü 'Ücretsiz Plan' üretiyordu, frontend ise "Pro rozetini göster mi"
    // kararını `planName !== "Ücretsiz Plan"` karşılaştırmasıyla veriyordu.
    // Sonuç: migration uygulanmış bir kurulumda ÜCRETSİZ kullanıcıya "Pro"
    // rozeti gösteriliyordu. Sunucu tarafının tek kaynağı burası; frontend
    // karşılığı `web/src/shared/lib/pricing.js` → FREE_PLAN_NAME (elle
    // senkron, diğer sabitlerle aynı kural).
    const FREE_PLAN_NAME = 'Ücretsiz';

    // ── Auth ───────────────────────────────────────────────────────────────
    const REMEMBER_ME_DAYS = 30;

    // COMP-002 — kayıt için asgari yaş.
    //
    // `kullanicilar.dogum_tarihi` kayıt formunda TOPLANIYORDU ama sunucuda
    // hiç doğrulanmıyordu: alan zorunlu bile değildi (yalnızca istemcide bir
    // `if` vardı, doğrudan endpoint'e POST atarak atlanabiliyordu). Gizlilik
    // politikası ise bu alanı "yaş doğrulaması için" topladığını yazıyor —
    // yani beyan edilen davranış ile kod uyuşmuyordu.
    //
    // 18: platform kart ile tahsilat yapıyor ve süreli abonelik sözleşmesi
    // kuruyor; reşit olmayan kullanıcı bu sözleşmenin tarafı olamaz. Ayrıca
    // moderasyonsuz kullanıcı üretimi içerik barındıran bir platformun yaş
    // kapısı olmaması, ödeme kuruluşu risk değerlendirmesinde doğrudan
    // olumsuz kalem (bkz. BLOCKERS B3).
    //
    // Bu bir İŞ KURALI: eşiği değiştirmek isterseniz tek yer burası, ama
    // kararı ürün tarafı vermeli.
    const MIN_REGISTRATION_AGE = 18;

    // ── File upload ────────────────────────────────────────────────────────
    //
    // H-06 — bu sabitler "tek doğruluk kaynağı" olarak duruyordu ama admin
    // tarafındaki ÜÇ yükleme yolunun (`ajax/upload.php`, `ajax/updategv.php`,
    // `ajax/seo.php`) hiçbiri onları okumuyordu; her biri kendi kopyasını
    // taşıyordu ve kopyalar zaten ayrışmıştı (biri PDF'e izin veriyor, biri
    // 500 KB, biri 50 KB). Üçü de artık `admin/functions/upload_guard.php`
    // üzerinden buradan okuyor.
    const ALLOWED_IMAGE_TYPES  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const ALLOWED_IMAGE_MIMES  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const MAX_UPLOAD_SIZE_MB   = 5;
    const MAX_UPLOAD_SIZE_BYTES = 5 * 1024 * 1024;

    /** Doğrulanmış MIME → dosya uzantısı. Ad ASLA istemciden alınmaz. */
    const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Bilinçli olarak FARKLI olan sınırlar — genel tavana değil, kullanım
     * yerine bağlı oldukları için ayrı sabitler:
     *   • SEO/OG/Twitter kartı görseli: sosyal ağlar zaten yeniden
     *     boyutlandırıyor, 500 KB fazlasıyla yeterli.
     *   • Favicon: .ico, birkaç KB'lık bir dosya.
     */
    const MAX_SEO_IMAGE_BYTES = 500 * 1024;
    const MAX_FAVICON_BYTES   = 50 * 1024;
    const ALLOWED_FAVICON_MIMES = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png'];

    // ── Pagination ─────────────────────────────────────────────────────────
    const DEFAULT_PAGE_LIMIT = 20;

    // ── Marketplace ────────────────────────────────────────────────────────
    // NOT WIRED UP: no code reads these two, and no service fee is charged
    // anywhere today. They are kept as the recorded intent, but nothing
    // enforces them — do not assume a fee is applied because they exist.
    // (MIN_WEEKLY_PRICE carried the same trap until it was actually enforced.)
    const SERVICE_FEE_PERCENT  = 5;
    const SERVICE_FEE_EXEMPT_ABOVE = 1000; // ₺
    // Applied EXACTLY ONCE, at the moment a seller sets a price: pricing.js
    // deriveMonthlyPrice() stores chatbotlar.ucret_aylik as round(weekly*4*0.9).
    // Everything downstream (checkout display, MarketplaceController::linePrice)
    // must therefore use ucret_aylik as-is — re-applying this factor at purchase
    // time charged the discount twice (weekly*3.24 instead of weekly*3.6).
    // Mirrored in web/src/shared/lib/pricing.js as MONTHLY_DISCOUNT_FACTOR.
    const DISCOUNT_MONTHLY_FACTOR  = 0.9;  // 10% discount on monthly subscription
    const SELLER_COMMISSION_WEEKLY  = 0.85; // Seller keeps 85% of weekly sales
    const SELLER_COMMISSION_MONTHLY = 0.80; // Seller keeps 80% of monthly sales
    // Bounds a seller may set a bot's weekly sale price to. Both bounds are
    // enforced in ChatbotController::assertValidPrice (publishChatbot /
    // updateChatbotPrice) and mirrored in shared/lib/pricing.js validatePrice.
    // MIN_WEEKLY_PRICE was previously documented here as enforced while no PHP
    // read it and the frontend only checked `n <= 0`, so a 0,01 ₺ bot passed
    // both layers. The monthly field's floor is the same value run through the
    // monthly derivation (round(min * 4 * DISCOUNT_MONTHLY_FACTOR)).
    //
    // 2026-09-05: taban ürün kararıyla 1 ₺'den 100 ₺'ye çıkarıldı. Aylık
    // tabanı BURADAN türüyor, ayrı bir sabit değil: round(100 * 4 * 0.9) =
    // 360 ₺. İkisini bağımsız vermek haftalık planı aylıktan pahalı yapıp
    // fiyatlandırmayı tutarsızlaştırırdı.
    //
    // Bu taban yalnızca fiyatın YAZILDIĞI anda çalışıyor (publishChatbot /
    // updateChatbotPrice). Daha önce daha ucuza yayınlanmış botlar fiyatlarını
    // korur; ancak fiyatları bir daha düzenlendiğinde yeni tabana uymak
    // zorunda kalırlar.
    const MIN_WEEKLY_PRICE = 100;
    const MAX_WEEKLY_PRICE = 5000; // ₺

    // ── Database table names ───────────────────────────────────────────────
    const TABLE_USERS          = 'kullanicilar';
    const TABLE_CHATBOTS       = 'chatbotlar';
    const TABLE_CART           = 'user_cart';
    const TABLE_SUBSCRIPTIONS  = 'user_subscriptions';
    const TABLE_NOTIFICATIONS  = 'notifications';
    const TABLE_CONVERSATIONS  = 'chatbot_conversations';
    const TABLE_CHATS          = 'chatbot_chats';
    const TABLE_LIKES          = 'chatbot_likes';
    const TABLE_DISLIKES       = 'chatbot_dislikes';
    const TABLE_FOLLOWS        = 'chatbot_follows';
    const TABLE_COMMENTS       = 'chatbot_comments';
    const TABLE_LISTS          = 'user_lists';
    const TABLE_LIST_ITEMS     = 'chatbot_in_list';
    const TABLE_USER_TOKENS    = 'user_tokens';
    const TABLE_BANK_INFO      = 'banka_bilgileri';
    const TABLE_SELLERS        = 'param_marketplace_sellers';
    const TABLE_DIALOG_BOOKS   = 'user_dialog_books';
    const TABLE_COIN_BALANCES  = 'user_coin_balance';
    const TABLE_PURCHASE_CREDITS = 'chatbot_purchase_credits';
    const TABLE_PRIVACY_RIGHT_USAGE = 'user_privacy_right_usage';

    // ── Error codes ────────────────────────────────────────────────────────
    const ERR_VALIDATION       = 'VALIDATION_ERROR';
    const ERR_AUTH_REQUIRED    = 'AUTH_REQUIRED';
    const ERR_NOT_FOUND        = 'NOT_FOUND';
    const ERR_PERMISSION       = 'PERMISSION_DENIED';
    const ERR_LIMIT_REACHED    = 'LIMIT_REACHED';
    const ERR_SELLER_INACTIVE  = 'SELLER_NOT_ACTIVE';
    const ERR_DUPLICATE        = 'DUPLICATE_ENTRY';
    const ERR_SERVER           = 'SERVER_ERROR';
    const ERR_PAYMENT          = 'PAYMENT_ERROR';
    // Özellik kodda var ama arkasındaki entegrasyon henüz yok — sahte başarı
    // yerine bu kodla açıkça reddediyoruz (BIZ-001, PAY-012, DEP-001).
    const ERR_UNAVAILABLE      = 'FEATURE_UNAVAILABLE';

    // ── External config (from environment / bootstrap) ────────────────────
    const LUMANORIS_USERNAME   = 'lumanoris';

    public static function googleClientId(): string {
        return $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
    }

    public static function contactEmail(): string {
        return $_ENV['CONTACT_EMAIL'] ?? getenv('CONTACT_EMAIL') ?: 'alperkum.cs@gmail.com';
    }

    public static function noreplyEmail(): string {
        return $_ENV['NOREPLY_EMAIL'] ?? getenv('NOREPLY_EMAIL') ?: 'no-reply@seninsiten.com';
    }

    /**
     * The admin panel (admin/api.php + admin/ajax/updateenv.php) manages this
     * key in api/admin/.env, not the app-root api/.env that bootstrap.php
     * loads into $_ENV — so it has to be read from that file directly.
     */
    public static function googleGeminiApiKey(): string {
        if ($_ENV['API_GOOGLE_GEMINI'] ?? getenv('API_GOOGLE_GEMINI') ?: '') {
            return $_ENV['API_GOOGLE_GEMINI'] ?? getenv('API_GOOGLE_GEMINI');
        }

        $envFile = __DIR__ . '/../../../admin/.env';
        if (!is_file($envFile)) {
            return '';
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            if ($key === 'API_GOOGLE_GEMINI') {
                return trim(substr($line, $pos + 1), " \t\"'");
            }
        }
        return '';
    }
}
