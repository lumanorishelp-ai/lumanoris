# AUDIT.md — Production-readiness denetimi

Tarih: 2026-09-02 · Yöntem: salt-okunur kod incelemesi (hiçbir dosya değiştirilmedi, hiçbir doğrulama komutu çalıştırılmadı — bkz. "Bakamadığım yerler" #5).

## Kapsam

Okunanlar: `api/api/**` (123 endpoint), `api/src/**` (14 controller, 2 repository, `AuthMiddleware`, `InputSanitizer`, `IyzicoClient` — kalan 16 dosya için bkz. H serisi), `api/functions/**` (19 dosya), `api/database/schema.sql` + `migrations/001–009`, `api/admin/index.php` route tablosu, `web/src/**` (96 API çağrı noktası), `web/next.config.mjs`, `web/server.js`, üç denylist (`api/.htaccess`, `api/admin/.htaccess`, `api/router.php`).

Kapsam dışı: `.history/`, `node_modules/`, `vendor/`, `web/src/.next-verify/`.

Çağıran araması her "ölü kod" iddiası için **üç yerde birden** yapıldı: `web/src`, `api/admin`, `api/router.php`.

Severity: **P0** production'a çıkmayı engeller · **P1** çıkılır ama kısa sürede patlar · **P2** teknik borç · **P3** iyileştirme.

Bu repo daha önce birkaç denetim turundan geçmiş; kolay bulgular (IDOR'lar, mass assignment'ın beş yeri, ödeme atlatma, coin yarışları) kapatılmış durumda. Aşağıdakiler o turlardan **arta kalan** ve kanıtlanabilir olanlar.

---

## Bulgu tablosu

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi | Önerilen çözüm | Blocker? | Efor |
|---|---|---|---|---|---|---|---|---|---|
| **D-01** | Broken functionality | **P0** | `api/src/Presentation/Controllers/WalletController.php:593,620` · `api/admin/index.php:31-53` | Para çekme yaşam döngüsünü kapatan iki endpoint (`list_withdrawals.php`, `update_withdrawal_status.php`) var ama **hiçbir yerden çağrılmıyor**. Admin panelinin route tablosunda para çekme sayfası yok; `para_cekme_talepleri` legacy CRUD beyaz listesinde de yok. | `grep -rl "update_withdrawal_status" web/src api/admin api/router.php` → 0 sonuç. `api/admin/index.php` `$adminRoutes` dizisinde 23 route var, hiçbiri para çekme değil. `Database::ADMIN_ALLOWED_PLAIN_TABLES` içinde `para_cekme_talepleri` yok. | Satıcı çekim talebi oluşturur, talep sonsuza kadar `beklemede` kalır. `computeBalanceAndTransactions()` bekleyen talepleri bakiyeden düştüğü için **satıcının parası kalıcı olarak kilitlenir** — hem çekemez hem bakiyesinde görünmez. | Admin paneline bir "Para Çekme Talepleri" sayfası (route + subpage) ekleyip mevcut iki endpoint'e bağlamak. Endpoint'ler hazır, eksik olan yalnızca UI. | (B7) | M |
| **D-02** | Marketplace | **P0** | `api/functions/ParamPosMarketplace.php:57-60` · `api/src/Presentation/Controllers/SellerController.php:102-143` | `addSubMerchant()` bir stub ve her zaman `success:false` dönüyor. Zincir: `status != 'active'` → `saveChatbot`, `publishChatbot`, `addToCart`, `createSubscription`, `getPublished` (INNER JOIN) ve `userHasAccess`'in preview dalı kapanıyor. | `ParamPosMarketplace::addSubMerchant()` gövdesi tek satır: `return ['success' => false, ...]`. `ChatbotRepository::getPublished()` satır 122-123 `INNER JOIN param_marketplace_sellers ... status = 'active'`. | Temiz kurulumda **kimse satıcı olamaz → hiçbir bot yayınlanamaz → pazaryeri boş → hiçbir satış yapılamaz.** Ürünün gelir üreten yarısı çalışmıyor. | Kod düzeyinde çözülemez. `status='active'` yazmak KYC'siz satıcıya para akışı açmak olurdu. Gerçek sağlayıcı entegrasyonu gerekiyor. | **B1** | — |
| **F-01** | Frontend | **P1** | `web/src/app/dashboard/chatbots/components/ChatbotCard.jsx:39,59` · `web/src/shared/lib/image.js:29-35` · `web/src/entities/user/ui/ProfileCard.jsx:125-128` | Yüklenen görseller `assets/kapak_fotografi/<ad>.jpg` gibi **göreli** yolla saklanıyor (`ChatbotController::handleImageUploads:565`). Dört sayfa bunu düzelten kendi yerel yardımcısını taşıyor ama üç render noktası taşımıyor. `ProfileCard.formatImage` ise yolu `data:image/jpeg;base64,` ile sarıyor. | Depolanan değer: `handleImageUploads:565` → `'assets/' . $dbCol . '/' . $fileName`. Düzelten yardımcılar: `create/page.jsx:279`, `following/page.jsx:61`, `list/page.jsx:44`, `purchased/page.jsx:50` — dördü de `"/" +` ekliyor. Düzeltmeyenler: `shared/lib/image.js:29-35` (`return value \|\| fallback.src`), `chatbots/components/ChatbotCard.jsx:39` (`<img src={image}>`), `ProfileCard.jsx:127` (`data:image/jpeg;base64,${img}`). | `/dashboard/chatbots/` sayfasında (kullanıcının kendi botları) tarayıcı `assets/...`'ı `/dashboard/chatbots/assets/...` diye çözer → 404 → **her bot kartının kapak ve profil görseli kırık**. ProfileCard'da avatar `data:image/jpeg;base64,assets/...` olur → garantili kırık. | Tek bir `assetUrl()` yardımcısını `shared/lib/image.js` içine taşıyıp `resolveCoverSrc`/`resolveAvatarSrc` içinde uygulamak; dört yerel kopyayı ona bağlamak; `ProfileCard.formatImage`'ı silip aynı yardımcıyı kullanmak. | — | S |
| **A-03** | API contract | **P1** | `api/src/Presentation/Controllers/ChatbotController.php:465-469` | `getSuggested()` sunucu tarafında `profil_fotografi`'yi `data:image/jpeg;base64,` ile önekliyor — ama sütunda base64 değil `assets/...` yolu var. | Satır 466-468: `if (!str_starts_with($bot['profil_fotografi'], 'data:')) { ... 'data:image/jpeg;base64,' . $bot['profil_fotografi']; }`. Depolanan değer F-01'de kanıtlandığı gibi bir yol. | Önerilen botların görselleri her zaman kırık. F-01 ile aynı kök neden (base64 döneminden kalan varsayım), farklı katman. | Öneki kaldırıp yolu olduğu gibi döndürmek; görsel URL kurmayı tek bir frontend yardımcısına bırakmak. | — | S |
| **D-04** | Payments | **P1** | `api/src/Presentation/Controllers/MarketplaceController.php:428-462,547-572` · `api/functions/checkout_payments.php:379-400` | `chargeCard()` zaman aşımına uğradığında `$conn->rollBack()` çalışıyor ve **hiçbir `param_marketplace_payments` satırı yazılmıyor**. `reconcilePayments()` ise yalnızca o tablodaki satırları tarıyor. | `createSubscription:459-462`: charge başarısızsa `rollBack()` + `JsonResponse::error()`; `$db->insert('param_marketplace_payments', ...)` satır 520'de, yani **charge'dan SONRA**. `reconcilePayments:393-400`: `SELECT ... FROM param_marketplace_payments WHERE status IN ('pending','payment_started','failed','unknown')`. `IyzicoClient::request:167-179` zaman aşımını `status:'failure'` olarak döndürüyor. | Kodun 428-433. satırlarındaki yorum "order_id tahsilattan ÖNCE üretiliyor ki mutabakat bulabilsin" diyor — ama mutabakatın bakacağı satır hiç yazılmıyor. Sonuç: iyzico tarafında para çekilmiş, bizde hiçbir iz yok, kullanıcı "ödeme başarısız" görüp tekrar deniyor → **çift çekim riski + tespit edilemeyen kayıp tahsilat.** | Charge'dan ÖNCE `status='pending'` bir ödeme satırı yazmak (ayrı transaction'da), sonra sonuca göre `paid`/`failed` yapmak. `reconcilePayments`'in sorgusu zaten bu şekli bekliyor. | — | M |
| **D-03** | Payments | **P1** | `api/functions/checkout_payments.php:589-604` | `processRefund()` yalnızca `param_marketplace_payments` ve `param_marketplace_details` durumlarını güncelliyor. `user_subscriptions` ve `chatbot_purchase_credits` satırlarına **hiç dokunmuyor**. | Satır 589-604'te iki `$db->update()` var; ikisi de `param_marketplace_*`. Dosyanın tamamında `user_subscriptions` geçmiyor (`grep -c "user_subscriptions" api/functions/checkout_payments.php` → 0). `userHasAccess()` erişimi `us.status = 1 AND us.expiry_date > NOW()` ile veriyor. | Kullanıcı satın alır → erişim kazanır → iade alır → parası geri döner ama **abonelik ve bonus mesaj kredisi aynen durur**. Ücretsiz süresiz erişim. Satıcı payı düşülür, alıcı ürünü tutar. | `processRefund` içinde tam iade halinde ilgili `user_subscriptions` satırlarını `status=0` yapmak ve `chatbot_purchase_credits.credits_remaining`'i sıfırlamak. Kalem bazlı iadede D-08 çözülmeden hangi botun iade edildiği bilinemez — ikisi birlikte ele alınmalı. | — | M |
| **D-05** | Payments | **P1** | `api/src/Presentation/Controllers/WalletController.php:493-497` · `api/database/schema.sql:648-653` | `upgradePlan()` aylık fiyatı **bir kez** tahsil edip `user_plan_selection`'a yazıyor. Tabloda süre bilgisi yok (`user_id`, `plan_name`, `selected_at`), `getUserPlan()` satırı koşulsuz okuyor. Hiçbir yerde yenileme ya da süre kontrolü yok. | `schema.sql:648-653` — üç sütun, `expires_at` yok. `plans.php:102-107` — `SELECT p.* FROM user_plan_selection ups JOIN plans p ON p.name_tr = ups.plan_name WHERE ups.user_id = ?`, tarih koşulu yok. Kod tabanında `user_plan_selection` üzerinde tarih filtreli tek bir sorgu yok. | Kullanıcı ₺599'u bir kez öder, Elmas planın 50 bot / 1000 günlük mesaj hakkını **süresiz** alır. Aylık gelir modeli fiilen tek seferlik satışa dönüşüyor. | Şema değişikliği (`expires_at`) + `getUserPlan()`'da süre kontrolü + yenileme akışı. **İş kuralı kararı gerekiyor** (bkz. Belirsizlikler #2); kendim karar vermedim. | — | L |
| **E-02** | Data integrity | **P1** | `api/src/Presentation/Controllers/WalletController.php:647` | "Kapanmış talebi force olmadan değiştirme" koruması ASCII yazımla yazılmış: `$closed = ['odendi', 'reddedildi', 'iptal']`. Kanonik değer ise `'ödendi'` — aynı dosyanın 578. ve 587. satırları bunu açıkça söylüyor. | Satır 578: `WITHDRAWAL_STATUSES = ['beklemede', 'onaylandı', 'ödendi', ...]`. Satır 587: `'odendi' => 'ödendi'` alias'ı. Satır 647: `$closed = ['odendi', ...]`. `in_array('ödendi', ['odendi',...], true)` → **false**. | `ödendi` (para gerçekten gönderilmiş) durumdaki bir talep, `force` gönderilmeden sessizce `beklemede`'ye geri çevrilebilir. `computeBalanceAndTransactions()` bunu tekrar bakiyeden düşer → satıcının bakiyesi ikinci kez azalır, ya da tersine ikinci bir ödeme yetkilendirilebilir. Aynı dosyada 578. satırda düzeltilen hatanın 647. satırda tekrarı. | `$closed`'ı `['ödendi', 'reddedildi', 'iptal']` yapmak; daha iyisi `WITHDRAWAL_STATUSES`'tan türetmek. | — | S |
| **B-01** | Authorization | **P1** | `api/src/Presentation/Controllers/SocialController.php:287-304` | `getBotsOfList()` içinde **hiçbir kimlik doğrulama çağrısı yok** ve `list_id`'nin çağırana ait olduğu kontrol edilmiyor. Aynı dosyadaki `addBotToList`, `deleteBotFromList`, `deleteUserList` üçü de bu kontrolü yapıyor. | Satır 287-289: metot `InputSanitizer::positiveInt($_GET['list_id'])` ile başlıyor, `AuthMiddleware` hiç çağrılmıyor. Karşılaştır: satır 236, 257, 350 — üçünde de `SELECT id FROM user_lists WHERE id = ? AND user_id = ?`. **Sömürü:** oturumsuz `GET /api/social/getbotsoflist.php?list_id=1,2,3…` — her yanıt o listenin sahibinin kürasyonunu döndürür. | Herhangi bir anonim ziyaretçi, herhangi bir kullanıcının özel listelerinin içeriğini id sayarak okuyabilir. Sızan içerik yayındaki botlarla sınırlı (sorgu `is_independent = 0` + aktif satıcı filtreliyor), yani gizli bot verisi değil — ama kullanıcının kürasyonu özel. | `AuthMiddleware::requireAuth()` + diğer üç metottaki `user_lists` sahiplik kontrolünün aynısı. Yama tek endpoint'i etkiliyor ve üçlü çağıran araması yapıldı (`web/src/app/dashboard/list/page.jsx` tek çağıran) → **izole, uygulanabilir.** | — | S |
| **B-06** | Security | **P1** | `api/src/Presentation/Controllers/TrainingController.php:112-139,159-191` | SSRF koruması `hostIsPublic()` ile DNS çözümlemesi yapıp IP'yi doğruluyor, **sonra `curl_init($url)` host'u ikinci kez, bağımsız olarak çözüyor.** Aradaki pencerede kısa TTL'li bir kayıt farklı cevap verebilir (DNS rebinding). | Satır 119: `if (!self::hostIsPublic($host))`. Satır 123: `curl_init($url)` — çözülmüş IP'ye pinlenmiyor, `CURLOPT_RESOLVE` yok. `hostIsPublic:165`: `dns_get_record($host, ...)`. İki çözümleme arasında hiçbir bağ yok. **Sömürü:** saldırgan `rebind.example.com` için TTL=0 A kaydı yayınlar; ilk sorguda `1.2.3.4`, ikincisinde `169.254.169.254` döner. | Kimliği doğrulanmış herhangi bir kullanıcı, "Bilgi Bankası > URL Ekle" üzerinden sunucunun iç ağına ve bulut metadata servisine istek attırabilir; yanıt gövdesi `text` alanında geri döner. | `hostIsPublic()`'in doğruladığı IP'yi `CURLOPT_RESOLVE` ile pinlemek (`host:port:ip`), böylece cURL ikinci bir çözümleme yapmaz. Yönlendirme zaten kapalı. | — | M |
| **B-02** | Security | **P1** | `api/src/Presentation/Controllers/NoteController.php:9-11` | `addDialogBook()` istemcinin JSON'unu **olduğu gibi** `Database::insert()`'e veriyor; yalnızca `user_id` zorlanıyor. `InputSanitizer::pickAllowed()` bu dosyada hiç kullanılmıyor. | Satır 9-10: `$data['user_id'] = $userId; $id = Database::getInstance()->insert('user_dialog_books', $data);`. Tablo (`schema.sql:602-612`): `id, user_id, chatbot_id, name, input_message, output_message, created_at, updated_at`. `Database::insert()` yalnızca **sütun ADINI** doğruluyor (`assertSafeColumnName`), değeri değil. **Sömürü:** `{"chatbot_id":<başkasının botu>,"created_at":"2020-01-01","name":"<255+ karakter>"}`. | (a) Not, kullanıcının hiç konuşmadığı bir bota atfedilebilir — `getDialogues()` bunu bot sahibinin adıyla birlikte yayınlıyor. (b) `name` VARCHAR(255) sınırını aşan istek SQLSTATE 22001 ile **500** üretir; kullanıcı notunu kaydedemez ve nedenini göremez. (c) Sahte tarihli kayıt. | `ChatController::assertOnlyAllowed()` deseninin aynısı: `['chatbot_id','name','input_message','output_message']` beyaz listesi + `mb_substr` ile uzunluk kırpma. | — | S |
| **B-03** | Security | **P1** | `api/src/Presentation/Controllers/NoteController.php:77-78` | `addComment()` aynı desende: ham `$data` doğrudan `dialog_comments`'a. Ayrıca `dialog_id`'nin var olduğu hiç kontrol edilmiyor ve yorum metnine uzunluk/HTML kırpması uygulanmıyor. | Satır 77-78: `$data['user_id'] = $userId; $id = ...->insert('dialog_comments', $data);`. Tablo (`schema.sql:244-251`): `comment` VARCHAR(1000), `commented_at` TIMESTAMP. Karşılaştır: `SocialController::addComment():125-137` — `pickAllowed` + `InputSanitizer::text(..., 2000)` + boşluk kontrolü. | 1000 karakteri aşan yorum → 500. `commented_at` istemciden yazılabildiği için yorum akışı sahte tarihlerle manipüle edilebilir. Var olmayan `dialog_id`'ye yorum yazılabilir (yetim satır). | `SocialController::addComment()` ile birebir aynı deseni uygulamak. | — | S |
| **B-04** | Security | **P1** | `api/src/Presentation/Controllers/SocialController.php:240,368,386` | Aynı dosyanın `addComment` ve `addUserList` metotları beyaz liste kullanıyor; `addBotToList`, `addHide` ve `addUninterest` kullanmıyor — ham `$data` doğrudan `insert()`'e gidiyor. | Satır 240: `$id = $db->insert('chatbot_in_list', $data);` — `$data` içinde `list_id` doğrulanmış ama `chatbot_id` hiç doğrulanmamış ve fazladan anahtarlar süzülmemiş. Satır 368: `insert('chatbot_hide', $data)`. Satır 386: `insert('chatbot_uninterested', $data)`. | Tanımsız bir sütun adı gönderen istemci ham SQL hatası (500) alır; tanımlı ama sunucuya ait bir sütun (`id`, zaman damgaları) istemciden yazılabilir. `chatbot_in_list.chatbot_id` doğrulanmadığı için var olmayan bot listeye eklenebilir. | Üç metotta da `InputSanitizer::pickAllowed()` — aynı dosyada zaten iki örneği var. | — | S |
| **B-05** | Security | **P1** | `api/src/Presentation/Controllers/NoteController.php:28-39` · `api/database/schema.sql:602-612` | `getDialogues()` **tüm kullanıcıların** `user_dialog_books` satırlarını `udb.*` ile döndürüyor. Tabloda gizlilik/paylaşım bayrağı yok, yani "kaydet" ile "yayınla" ayrımı şemada mevcut değil. | `schema.sql:602-612` — sütunlar: `id, user_id, chatbot_id, name, input_message, output_message, created_at, updated_at`. Gizlilik sütunu yok. Sorgu satır 28-39: `SELECT udb.* ... FROM user_dialog_books udb ... ORDER BY RAND() LIMIT 100` — `WHERE` yok. `input_message`/`output_message` kullanıcının bot ile yaptığı gerçek konuşma. | Bir kullanıcının "Sohbet Defteri"ne kaydettiği her diyalog, oturum açmış her kullanıcının akışında görünür. Kullanıcı bunu "kaydetme" sanıyorsa özel konuşması yayınlanmış olur. | **İş kararı gerekiyor** (bkz. Belirsizlikler #1). Teknik çözüm: `is_public TINYINT(1) DEFAULT 0` sütunu + akışta filtre + kaydetme akışında açık onay. | — | M |
| **C-01** | Race conditions | **P1** | `api/src/Presentation/Controllers/ChatbotController.php:38-53,383-387` | Bot limiti kontrolü SELECT (`countByOwner`) ile yapılıp ayrı bir INSERT/UPDATE ile yazılıyor; arada kilit yok, transaction yok. | `saveChatbot:40-43`: `$counts = $repo->countByOwner(...); if ($used >= $limit) {...}` → satır 117 `$repo->create($data)`. `publishChatbot:384-387` aynı şekil. Karşılaştır: `withdraw()` (`WalletController:126-131`) ve `createSubscription()` (`MarketplaceController:249-254`) ikisi de `GET_LOCK` kullanıyor. | **İki sekmeden aynı anda:** ikisi de aynı `$used` değerini okur, ikisi de kontrolü geçer, ikisi de yazar. Ücretsiz plandaki kullanıcı 1 yerine 2 bağımsız bot, 2 yerine 3 herkese açık bot oluşturabilir. Kota doğrudan gelir modeliyle bağlı (D-05). | `GET_LOCK('botlimit_user_<id>', 10)` — aynı dosya ailesinde iki örneği var. | — | S |
| **B-06b** | Security | **P2** | `api/src/Presentation/Controllers/TrainingController.php:129,151` | `CURLOPT_MAXFILESIZE` yalnızca `Content-Length` başlığı varsa devreye girer; chunked yanıtta hiçbir şey yapmaz. Boyut kontrolü (`strlen($body)`) indirme **bittikten sonra** yapılıyor. | Satır 129 `CURLOPT_MAXFILESIZE => self::MAX_URL_BYTES`, satır 151 `if (strlen($body) > self::MAX_URL_BYTES)`. `CURLOPT_WRITEFUNCTION` ile akış sırasında kesme yok. | Kötü niyetli bir adres (ya da yanlışlıkla büyük bir dosya) `Content-Length` göndermeden sınırsız veri akıtabilir; PHP süreci belleği tükenene kadar okur. Hız sınırı 5 dakikada 15 istek — tek kullanıcı yeterli. | `CURLOPT_WRITEFUNCTION` ile biriken baytı sayıp tavanı aşınca 0 döndürerek aktarımı kesmek. | — | S |
| **B-12** | Security | **P2** | `api/src/Presentation/Controllers/ChatController.php:150-158` | `updateConversation()` sahiplik kontrolünü yapıyor ama ardından ham `$data`'yı `update()`'e veriyor. Aynı dosyanın `CONVERSATION_COLUMNS` beyaz listesi ve `assertOnlyAllowed()` yardımcısı burada **kullanılmıyor**. | Satır 150: `unset($data['id'], $data['user_id']);` — sonra satır 158 `$db->update('chatbot_conversations', $data, 'id = ?', [$id]);`. Karşılaştır: satır 77 `addConversation()` → `self::assertOnlyAllowed($data, self::CONVERSATION_COLUMNS)`. | Kullanıcı kendi sohbetinin `chatbot_id`'sini başka bir bota çevirebilir (`getHistory` o botun adı/görseliyle listeler) ve `started_at`/`last_message_at` gibi sunucuya ait alanları yazabilir. Sınıf olarak SEC-014'ün kapatılmamış altıncı yeri. | `self::assertOnlyAllowed($data, self::CONVERSATION_COLUMNS)` — dosyada hazır. | — | S |
| **C-02** | Race conditions | **P2** | `SocialController.php:14-23,35-44,94-102` · `NoteController.php:90-99,110-119` | Beş beğen/beğenme/takip metodu "önce SELECT, sonra INSERT" yapıyor. İlgili beş tablonun hepsinde `UNIQUE (user_id, <hedef>)` var. | `schema.sql:159` (`chatbot_likes`), `:282` (`dialog_likes`), `:261` (`dialog_dislikes`) — üçünde de UNIQUE. Kod tarafında `insert()` çağrısı `try/catch` içinde değil. | Veri bozulmuyor (UNIQUE tutuyor) ama iki sekmeden hızlı çift tıklamada ikinci istek yakalanmamış `PDOException` → `set_exception_handler` → **boş 500**. Kullanıcı "Sunucu hatası oluştu." görür, arayüz beğeni durumunu yanlış gösterir. | Tek atomik ifadeye çevirmek (`INSERT ... ON DUPLICATE KEY UPDATE` ya da `INSERT IGNORE` dönüşünü satır sayısıyla okumak) — `coin_engine.php`'deki desenin aynısı. | — | S |
| **D-06** | Payments | **P2** | `api/src/Presentation/Controllers/WalletController.php:206-217,467-489` | `upgradePlan()` bir `param_marketplace_payments` satırı yazıyor ama hiç `param_marketplace_details` satırı yazmıyor. `getMyPayments()` ise ikisini **INNER JOIN** ediyor. | Satır 211: `JOIN param_marketplace_details d ON d.payment_id = p.id`. `upgradePlan:467-489`'da tek bir `insert` var ve hedefi `param_marketplace_payments`; `param_marketplace_details` geçmiyor. | Üyelik paketi satın alan kullanıcı ödemesini "Ödemelerim" ekranında **hiç göremez**. Fatura/dekont izi kullanıcı tarafından ulaşılamaz. | `getMyPayments()`'te `LEFT JOIN`'e çevirmek (kalemsiz ödeme = plan satın alımı), ya da plan alımı için de bir detay satırı yazmak. | — | S |
| **D-08** | Data integrity | **P2** | `api/functions/checkout_payments.php:543-568` | Çok kalemli bir siparişte her kalem iadesi **aynı** `detail_id`'ye (ilk detay satırı) bağlanıyor; hiç detay yoksa `0` yazılıyor. | Satır 543-547: `$firstDetail = ... ORDER BY id LIMIT 1; $firstDetailId = $firstDetail ? (int)$firstDetail['id'] : 0;` — döngünün DIŞINDA, satır 561'de her kalem için aynı değer kullanılıyor. `param_marketplace_refunds.detail_id` üzerinde FK yok (`schema.sql:444-458`), yani `0` sessizce kabul edilir. | İki botluk bir siparişin tek botu iade edildiğinde hangi satıcının payının geri alınacağı kayıttan **anlaşılamaz**. Satıcı mutabakatı elle yapılmak zorunda. D-03 ile birlikte: kısmi iadede hangi aboneliğin iptal edileceği de bilinemiyor. | `itemTransactions[].itemId` ile `param_marketplace_details.chatbot_id` eşleştirmesi (migration 004 o sütunu zaten ekledi). | — | M |
| **E-03** | Data integrity | **P2** | `api/src/Presentation/Controllers/NotificationController.php:25-33` · `api/database/schema.sql:343` | `notifications.message_tr` / `message_en` sütunları `schema.sql`'de VAR ama `migrations/` altında bunları ekleyen **hiçbir dosya yok**. Boşluk, istek yolunda çalışan bir `ALTER TABLE` ile kapatılmış. | `grep -rn "message_tr" api/database/` → yalnızca `schema.sql:343`. Migration klasöründe hiç eşleşme yok. `NotificationController.php:31-32`: `$conn->exec('ALTER TABLE notifications ADD COLUMN message_tr ...')`. Aynı sınıf hata `MarketplaceController.php:522-528`'de PAY-004 olarak açıkça düzeltilmiş ve migration'a taşınmış. | (a) Migration yoluyla kurulmuş bir veritabanı bu sütunlara **hiç** kavuşmaz — ilk bildirim isteğinde runtime DDL çalışır. (b) En az yetkili bir production DB kullanıcısında `ALTER` hakkı yoktur → `PDOException` → her bildirim oluşturma **500**. (c) MySQL'de DDL örtük COMMIT tetikler; PAY-004'ün kök nedeninin aynısı. | Sütunları ekleyen bir migration dosyası (`010_notification_message_columns.sql`) yazmak ve controller'daki runtime DDL'i silmek. Migration **yazılabilir, uygulanamaz.** | — | S |
| **E-04** | Data integrity | **P2** | `api/src/Presentation/Controllers/WalletController.php:306-347` · `api/database/migrations/007_plan_limits.sql:73-85` | Plan kataloğu iki yerde: `plans` tablosu (migration 007 seed'i) ve `getPricing()` içindeki kodlanmış geri düşüş dizisi. İkisini senkron tutan hiçbir mekanizma yok. | Migration 007 satır 76-85: `('Ücretsiz', 0.00), ('Gümüş', 149.00), ('Altın', 299.00), ('Elmas', 599.00)`. `WalletController:309,319,329,339`: `'₺0'`, `'₺149,00'`, `'₺299,00'`, `'₺599,00'`. **Bugün değerler eşleşiyor** — sorun eşleşmemesi değil, eşleşmeyi zorlayan hiçbir şeyin olmaması. | Fiyat `plans` tablosunda güncellenirse, migration 007 uygulanmamış bir kurulumda fiyat sayfası eski fiyatı gösterir. `upgradePlan()` tutarı her zaman tablodan aldığı için **gösterilen fiyatla çekilen tutar ayrışabilir**. CLAUDE.md'nin uyardığı "sabitler üç yerde" tuzağının dördüncü örneği. | Geri düşüş dizisini kaldırıp `plansTableReady()` false ise 503 dönmek (`upgradePlan` zaten bunu yapıyor — iki metot aynı durumda farklı davranıyor). | — | S |
| **B-07** | Authentication | **P2** | `api/src/Presentation/Controllers/UserController.php:96-112` | E-posta değiştirme ne mevcut parolayı soruyor, ne yeni adrese doğrulama gönderiyor, ne de hız sınırı uyguluyor. | Satır 96-106: yalnızca `requireAuth()` + `FILTER_VALIDATE_EMAIL` → doğrudan `updateById($userId, ['eposta' => $newEmail])`. Karşılaştır: `AuthController::updatePassword():302-309` — parola politikası + hız sınırı + kod doğrulaması. | Çalınmış bir oturum hesabın e-postasını değiştirebilir; ardından "şifremi unuttum" saldırganın adresine kod gönderir → **kalıcı hesap ele geçirme**. Meşru kullanıcı da doğrulanmamış bir adrese geçtiğinde hesabını kurtaramaz. | Mevcut parola doğrulaması + yeni adrese onay kodu (`password_resets` deseni hazır) + `checkRateLimit`. **Uygulamadım**: oturum davranışını ve e-posta akışını etkiliyor, ayrıca B5'e bağlı. | **B5** | M |
| **F-02** | Error handling | **P2** | `api/src/Presentation/Controllers/UserController.php:83,106` · `api/database/schema.sql:331-332` | `kullanicilar.eposta` ve `kullanici_adi` üzerinde UNIQUE var; iki güncelleme metodu da çakışmayı yakalamıyor. | `schema.sql:331-332`: `UNIQUE KEY eposta`, `UNIQUE KEY kullanici_adi`. `updateUserEmail:106` ve `updateUserNames:83` `try/catch` içinde değil → `set_exception_handler` (`bootstrap.php:43`) → `APP_DEBUG=false` ile **"Sunucu hatası oluştu."** | Kullanılan bir kullanıcı adı ya da e-posta girildiğinde kullanıcı "Sunucu hatası" görür; neyin yanlış olduğunu asla öğrenemez ve aynı hatayı tekrarlar. | Yazmadan önce çakışma sorgusu + `409` ile anlamlı mesaj. | — | S |
| **B-08** | Security | **P2** | `api/src/Presentation/Controllers/MarketplaceController.php:29-32` | Duplicate dışındaki her PDO hatası ham `$e->getMessage()` ile istemciye dönüyor. | Satır 30-31: `$msg = str_contains($e->getMessage(), 'Duplicate entry') ? '...' : 'Hata: ' . $e->getMessage();`. `bootstrap.php:47-52` tam olarak bunu engellemek için `APP_DEBUG` kontrolü koyuyor — bu satır o korumayı atlıyor. | Sepete ekleme sırasında oluşan herhangi bir DB hatası SQL durum kodunu, tablo/sütun adını ve bazen sorgu parçasını kullanıcıya gösterir — şema keşfi için yeterli. | Genel mesaj + `error_log`. Kod tabanında zaten uygulanan desen. | — | S |
| **B-13** | Training | **P2** | `api/src/Presentation/Controllers/TrainingController.php:19-31` | `updateTrainingChunk()` `textChunk` üzerinde **hiçbir boyut sınırı** uygulamıyor ve hız sınırı yok; `CONCAT` ile LONGTEXT'e sınırsız ekliyor. | Satır 19: `$chunk = $data['textChunk'];` — kırpma yok. Satır 26: `CONCAT(IFNULL(training_prompt,''), :chunk)`. Karşılaştır: aynı sınıfın `readUrl:103` ve `readPdf:214` metotlarında `checkRateLimit` var; `ChatController::MAX_TRAINING_CHARS` **okuma** tarafını 60.000 karakterle sınırlıyor ama yazma tarafı serbest. | Bir kullanıcı tek bota sınırsız veri yazarak veritabanını şişirebilir (LONGTEXT tavanı 4 GB). Okuma zaten 60.000 karakterde kesildiği için **fazlası hiçbir işe yaramaz** — kullanıcı yüklediğini sanıp kullanılmayan veri biriktirir. | `MAX_TRAINING_CHARS` ile aynı tavanı yazma tarafında da uygulamak + `checkRateLimit`. | — | S |
| **B-09** | Security | **P2** | `api/src/Presentation/Controllers/SellerController.php:348-390` | `listIller()` ve `listIlceler()` **kimlik doğrulaması ve hız sınırı olmadan** dış sağlayıcıya gidiyor. `listIller` ayrıca `?nocache` ile önbelleği atlatmaya izin veriyor. | Satır 348-353: metot `require_once` ile başlıyor, `AuthMiddleware` yok. Satır 353: `$bypassCache = isset($_GET['nocache']);`. Satır 364: `(new ParamPosMarketplace())->listIller()`. | Anonim bir istemci `?nocache=1` ile sınırsız sayıda dış API çağrısı tetikleyebilir — sağlayıcı kotasının tükenmesi ya da IP yasaklanması. Onboarding sihirbazı bu iki uca bağlı olduğu için satıcı kaydı tümden bloke olur. | `requireAuth()` + `checkRateLimit` + `nocache`'i yalnızca admin oturumuna açmak. | — | S |
| **B-11** | Authentication | **P2** | `api/src/Presentation/Controllers/AuthController.php:226,309` | Şifre sıfırlama kodu 6 haneli (10⁶) ve 15 dakika geçerli. Hız sınırı **yalnızca IP+e-posta** anahtarıyla; hesap başına toplam deneme sayacı yok. | Satır 226: `random_int(100000, 999999)`. Satır 309: `checkRateLimit($db, 'resetcode:' . $_SERVER['REMOTE_ADDR'] . ':' . $email, 10, 600)`. Anahtar IP içerdiği için farklı IP'lerden gelen denemeler **ayrı sayaçlara** düşer. Kod tek kullanımlık ve süreli — bu iki kontrol doğru. | Dağıtık bir saldırgan (bot ağı / proxy havuzu) 15 dakikalık pencerede hesap başına sınırsız deneme yapabilir. Tek IP'den pratik değil; çok IP'den mümkün. | Hız sınırı anahtarına IP'siz bir ikinci sayaç eklemek (`resetcode-user:<user_id>`, ör. 15 dakikada 10 deneme), ya da kodu 8 haneye çıkarmak. | — | S |
| **A-01** | Dead code | **P2** | `api/api/**` — aşağıdaki 25 dosya | 25 endpoint dosyasının `web/src`, `api/admin` ve `api/router.php` **üçünde de** çağıranı yok. | Yöntem: `find api/api -name "*.php"` çıktısı ile `web/src` içindeki `/api/...php` referanslarının farkı alındı, kalan her ad için `grep -rl <ad> api/admin api/router.php web/src` çalıştırıldı. `consumemessage` yalnızca `chat/page.jsx:455`'te bir **yorum** içinde geçiyor; `diduserlike`/`diduserdislike` eşleşmeleri `note/*2.php` dosyalarından gelen alt dize eşleşmeleriydi. | Saldırı yüzeyi: her biri canlı ve erişilebilir. `message/consumemessage.php` oturumlu kullanıcının kendi günlük hakkını 60/dk hızla yakmasına izin veriyor (kendi kendine DoS). `marketplace/updatecart.php`'nin çağıranı olmaması ayrı bir sonuç doğuruyor: **kullanıcı sepetteki abonelik süresini arayüzden değiştiremiyor.** `marketplace/buychatbot.php` 410 döndürüyor, zararsız. | **SİLİNMEDİ, silme adayı olarak işaretlendi** (bkz. Belirsizlikler #4). | — | S |
| **D-07** | Stubs | **P2** | `api/functions/producer_plan.php:32-45` | `buyProducerAccount()` bilinçli olarak kapalı; `getProducerPlanStatus()` her zaman `has_plan:false` döndüren sabit bir yanıt, `producer_plans` tablosunu hiç sorgulamıyor. | Satır 41-44: `return ['success' => false, 'message' => 'Üretici hesabı satışı şu anda kapalı...']`. Satır 36-44: tablo sorgusu yok. | Kullanıcı üretici hesabı satın alamaz. Dosyanın kendi yorumu doğru gerekçeyi veriyor: satın alınan plan hiçbir limit üretmiyor. | Açmadan önce üç şey gerekiyor (dosya başındaki yorumda listelenmiş) ve ikisi iş kararı. | **B2** | — |
| **A-02** | API contract | **P3** | `ChatbotController.php:196-208` · `ChatController.php:139,217` · `WalletController.php:86` · `SellerController.php:41,152,164,172,182,196,233,367,386` · `MarketplaceController.php:671,680` | 15 metot `JsonResponse` zarfını atlayıp doğrudan `echo json_encode(...)` yapıyor; bazıları `success` anahtarı ekliyor, bazıları çıplak dizi/nesne döndürüyor. | `getChatbotsV2:206` çıplak dizi; `getConversation:139` çıplak nesne; `getMyBalance:86` elle `array_merge(['success'=>true], ...)`. Kod içindeki ERR-003 yorumu bunun bilinen ve ertelenmiş bir borç olduğunu söylüyor. | Bugün kırık bir şey yok (istemciler mevcut şekle göre yazılmış), ama tek tip olmayan sözleşme her yeni istemcide sessiz kırılma riski taşıyor. | Tek seferde zarf tekilleştirmesi — API contract'ta kırıcı değişiklik olduğu için **önce raporla** kapsamında. | — | M |
| **E-05** | Data integrity | **P3** | `api/functions/plans.php:72` · `migrations/007_plan_limits.sql:76` | Geri düşüş planının adı `'Ücretsiz Plan'`, `plans` tablosuna seed edilen ad `'Ücretsiz'`. | `plans.php:72`: `'name_tr' => 'Ücretsiz Plan'`. Migration 007 satır 76: `('Ücretsiz', 'Free', 0.00, ...)`. | Migration 007 uygulanmamış kurulumda dashboard başlığı "Ücretsiz Plan", fiyat sayfası "Ücretsiz" der — aynı planın iki farklı adı görünür. | Geri düşüş adını `'Ücretsiz'` yapmak. | — | S |
| **B-10** | Security | **P3** | `api/src/Presentation/Controllers/ContentController.php:52-58` | `getOwner()` kimlik doğrulaması olmadan `user_id → kullanici_adi` eşlemesi veriyor. | Satır 53-57: `$_GET['id']` → `SELECT kullanici_adi FROM kullanicilar WHERE id = ?`, `AuthMiddleware` çağrısı yok. Endpoint'in üç yerde de çağıranı yok (bkz. A-01). | Anonim bir istemci id sayarak tüm kullanıcı adlarını ve toplam kullanıcı sayısını çıkarabilir. Kullanıcı adları zaten bot kartlarında görünüyor, yani etki sınırlı. | A-01 kapsamında silme adayı; tutulacaksa `requireAuth()`. | — | S |
| **F-04** | Frontend | **P3** | `AuthController.php:44,77,89` · `bootstrap.php:22` · `AuthMiddleware.php:111` | Oturum çerezi `'secure' => !empty($_SERVER['HTTPS'])` ile koşullu, `remember_me` ve `PHPSESSID` temizleme çerezleri ise koşulsuz `secure: true`. | `bootstrap.php:22`: `'secure' => !empty($_SERVER['HTTPS'])`. `AuthController.php:43`: `'secure' => true`. `AuthMiddleware.php:111` ise yine koşullu. | Düz HTTP üzerinden yerel geliştirmede "Beni hatırla" çerezi hiç yazılmaz; özellik sessizce çalışmaz ve hata da vermez. Production'da (HTTPS) sorun yok. | Üç yerde `AuthMiddleware`'deki koşullu ifadeyi kullanmak. | — | S |

**Toplam: 34 bulgu** — P0: 2 · P1: 13 · P2: 15 · P3: 4.

### A-01 — silme adayı endpoint listesi (SİLİNMEDİ)

```
chatbot/get_suggested.php            marketplace/updatecart.php          seller/submerchant_list_remote.php
chatbot/getchatbots_v2.php           marketplace/updatesubscription.php  seller/submerchant_update.php
chatbot/getdefaultbot.php            message/consumemessage.php          social/diduserdislike.php
content/getcontactinfo.php           notification/createnotification.php social/diduserfollow.php
content/getlandingimages.php         seller/marketplace_reconcile.php    social/diduserlike.php
content/getowner.php                 seller/marketplace_refund.php       wallet/list_withdrawals.php
content/getsocials.php               seller/parampos_callback.php        wallet/update_withdrawal_status.php
marketplace/buychatbot.php           seller/submerchant_delete.php
marketplace/deletesubscription.php   seller/submerchant_list.php
```

Bu listeden **`wallet/list_withdrawals.php` ve `wallet/update_withdrawal_status.php` silinmemeli** — D-01'in çözümünde kullanılacaklar. `seller/marketplace_reconcile.php` ve `seller/parampos_callback.php` dışarıdan (cron / gateway) çağrılmak üzere tasarlanmış olabilir; kod içi arama bunu ayırt edemez.

---

## Doğrulanan ama bulgu OLMAYAN noktalar

Denetim prompt'unun açıkça sorduğu, kontrol edilip **temiz çıkan** başlıklar.

### E bölümü — sabit senkronizasyon tablosu

| Sabit | `AppConfig.php` | `coin_engine.php` | `pricing.js` | Durum |
|---|---|---|---|---|
| Aylık indirim çarpanı | `DISCOUNT_MONTHLY_FACTOR = 0.9` (s.49) | — | `MONTHLY_DISCOUNT_FACTOR = 0.9` (s.13) | ✅ |
| Satıcı payı (haftalık) | `SELLER_COMMISSION_WEEKLY = 0.85` (s.50) | — | `SELLER_COMMISSION_WEEKLY = 0.85` (s.17) | ✅ |
| Satıcı payı (aylık) | `SELLER_COMMISSION_MONTHLY = 0.80` (s.51) | — | `SELLER_COMMISSION_MONTHLY = 0.80` (s.18) | ✅ |
| Min haftalık fiyat | `MIN_WEEKLY_PRICE = 1` (s.61) | — | `MIN_WEEKLY_PRICE = 1` (s.24) | ✅ |
| Maks haftalık fiyat | `MAX_WEEKLY_PRICE = 5000` (s.62) | — | `MAX_WEEKLY_PRICE = 5000` (s.25) | ✅ |
| Coin kademesi — taban | — | `COIN_TIER_BASE = 150` (s.168) | `COIN_TIER_BASE = 150` (s.30) | ✅ |
| Coin kademesi — adım | — | `COIN_TIER_STEP = 100` (s.169) | `COIN_TIER_STEP = 100` (s.31) | ✅ |
| Coin kademesi — tavan | — | `COIN_TIER_CAP = 1000` (s.170) | `COIN_TIER_CAP = 1000` (s.32) | ✅ |
| Günlük ücretsiz mesaj | `DAILY_FREE_MESSAGES = 10` (s.22) | `plans.php` üzerinden okunuyor (s.49) | — | ✅ (migration 007 seed'i de 10) |
| Hizmet bedeli | `SERVICE_FEE_PERCENT = 5` (s.41) | — | — | ⚠️ Hiçbir yerde okunmuyor; sabit kendi yorumunda bunu söylüyor. Bulgu değil, kayıtlı niyet (bkz. Belirsizlikler #5). |
| Üretici planı limitleri | `PRODUCER_*_LIMIT` (s.18-19) | — | — | ⚠️ Hiçbir yerde okunmuyor (D-07). |

**Uyuşmazlık bulunamadı.** Ancak senkronu **zorlayan hiçbir mekanizma yok** — bugünkü eşleşme elle bakımın sonucu. E-04 aynı sınıfın dördüncü kopyasını gösteriyor.

### Diğer temiz çıkan başlıklar

- **XSS / `react-markdown`.** `react-markdown@^10` HTML'i varsayılan olarak kaçırıyor, `rehype-raw` yüklü değil (`package.json`'da hiç `rehype*` bağımlılığı yok) ve URL şeması varsayılan `defaultUrlTransform` ile temizleniyor. `chat/page.jsx:794,831` başka eklenti kullanmıyor. **Asistan yanıtları üzerinden XSS yok.** Kalan 8 `dangerouslySetInnerHTML` noktası yalnızca `global_vars`'tan gelen, admin'in yazdığı HTML'i basıyor; kullanıcı girdisi bu yola hiç girmiyor.
- **SQL injection.** Tüm sorgular parametreli. Kimliklerin (tablo/sütun) SQL'e gömüldüğü üç yer (`Database::assertSafeWhereFragment`, `assertSafeColumnList`, `assertAllowedAdminTable`) blocklist değil **beyaz liste** grameriyle korunuyor; `insert()`/`update()` sütun adlarını `assertSafeColumnName` ile doğruluyor. Sömürülebilir bir yol bulunamadı.
- **Checkout çift gönderim.** `createSubscription` üç katman taşıyor (`GET_LOCK` + idempotency anahtarı + hız sınırı), asılı rezervasyonu `register_shutdown_function` temizliyor. Frontend'de de `disabled={paying || !legalAccepted}` (`checkout/page.jsx:519`) var. Temiz.
- **Sepet/abonelik/cüzdan sahiplik kontrolleri.** `deleteCart`, `updateCart`, `deleteSubscription`, `updateSubscription`, `withdraw`, `getBankInfo`, `getIban`, `getMyPayments`, `getMySubscriptions`, `getSubscription` — hepsinde `user_id = ?` predikatı mevcut ve etkilenen satır sayısı doğru raporlanıyor.
- **Ödeme duvarı.** `ChatbotRepository::userHasAccess()` `preview`/`full` ayrımı yapıyor, varsayılan `full` (fail-closed), `getDetail()` abonesi olmayana `style_prompt` göndermiyor, `generateReply` sistem talimatını sunucuda kuruyor. Atlatılabilir bir yol bulunamadı.
- **Yükleme güvenliği.** `handleImageUploads` magic-byte MIME kontrolü + MIME'dan türetilen uzantı + sunucunun ürettiği dosya adı kullanıyor; SVG kabul edilen MIME listesinde yok. `uploadProfilePhoto` üç biçimi beyaz listeliyor ve base64 gövdesini `getimagesizefromstring` ile doğruluyor. Path traversal yolu yok.
- **Denylist üçlüsü.** `api/.htaccess`, `api/admin/.htaccess` ve `api/router.php` üçü de `.env`, `.sql`, `error_log`, `db_backup`, `src`, `vendor`, `functions` dizinlerini kapatıyor. `router.php` ayrıca `urldecode` sonrası `..` normalizasyonu yapıyor. Aralarında kapsam farkı bulunamadı.
- **Session fixation.** `login`, `loginGoogle` ve `AuthMiddleware::tryRememberMe` üçü de yetki yükselmesinden önce `session_regenerate_id(true)` çağırıyor. Remember-me token'ı her kullanımda rotasyona giriyor.
- **Şifre sıfırlama token'ı.** Tek kullanımlık (`AuthController:329`'da siliniyor), süreli (`NOW() + INTERVAL 15 MINUTE`, MySQL saatinde hesaplanıyor), hash'lenmiş saklanıyor, `hash_equals` ile karşılaştırılıyor. B-11'deki dağıtık deneme sınırı dışında sorun yok.

---

# Ek tur — `api/admin/` kapsam kapatma (G serisi)

Tarih: 2026-09-02 · Yöntem: salt-okunur. Bu tur **yeni bir tam denetim değil**; yalnızca yukarıdaki "Bakamadığım yerler" #2'yi kapatıyor.

## Bu turda okunanlar

`api/admin/` altındaki **49 PHP dosyasının tamamı** (`vendor/` hariç): 17 `ajax/` dosyası, 22 sayfa, 3 `partials/`, 3 `subpages/`, 3 `functions/`, `index.php`. Doğrulama için ayrıca: `api/functions/db.php` (admin beyaz listeleri + `backup()`/`restore()`/`listBackups()`), `api/functions/util.php` (`csrf_check`), `api/admin/assets/js/login.js`, `api/assets/.htaccess`, `api/admin/uploads/.htaccess`, `api/src/Presentation/Controllers/SocialController.php`, `InputSanitizer`, `api/database/schema.sql` (`kullanicilar`, `chatbot_reports`).

`php -l` 49 dosyanın hepsinde temiz (bu turda çalıştırıldı — ana denetimin "Bakamadığım yerler" #5'ine tek istisna).

## Sorulan 7 soruya doğrudan cevap

| Soru | Cevap |
|---|---|
| `_guard.php` her ajax dosyasında çağrılıyor mu? | **Neredeyse.** 17 dosyanın 14'ü `require_once __DIR__ . '/_guard.php'` ile başlıyor. Çağırmayan üçü: `_guard.php` (kendisi), `giris.php` (giriş — kendi `csrf_check` + rate limit'i var, doğru), `cikis.php` (**hiçbir koruması yok** → G-10). |
| CSRF hangilerinde var, hangilerinde yok? | `_guard.php:34-42` GET dışı her istekte `csrf_token` POST alanı **veya** `X-CSRF-Token` başlığı istiyor; `_header.php:10-41` shim'i her same-origin non-GET `fetch`/`XHR`'a başlığı ekliyor. `giris.php:29` ve `_login.php:16` kendi `csrf_check`'ini yapıyor. **Yalnızca `cikis.php` korumasız** (G-10). `updateenv.php:21` guard'ın üstüne ikinci bir kontrol koyuyor ama yalnızca `$_POST['csrf_token']`'a bakıyor — başlıkla gelen geçerli bir isteği reddeder (zararsız ama gereksiz sıkı). |
| `create/update/delete` tabloyu istekten mi alıyor? Allowlist var mı? | **Evet istekten alıyor, ama allowlist var.** `Database::assertAllowedAdminTable()` 11 tablodan oluşan tam-eşleşmeli beyaz liste (`db.php:311-330`); `assertSafeWhereFragment()` `$where` için gramer beyaz listesi (`db.php:238-269`); `assertSafeColumnList()` `$columns` için (`db.php:285-303`). SQL enjeksiyonu bu üçüyle kapalı. **Kolon (`$data`) allowlist'i YOK** — ama tablo listesi `adminler`'i içermediği için yetki yükselmesine dönmüyor. `read.php` tarafında bu hassas sütun sızıntısına dönüyor → G-11. |
| `updateenv.php` hangi anahtarlara izin veriyor? | **Hepsine — allowlist yok.** `foreach ($_POST as $key => $value)`, her alan adı `API_<BÜYÜK_HARF>` anahtarına çevrilip yazılıyor. Değer de kaçırılmıyor → satır sonu enjeksiyonu → G-07. |
| `readenv.php` maskeleme yapıyor mu? | **Hayır.** İstenen anahtarın ham değeri JSON olarak dönüyor; allowlist ve maskeleme yok. Üstelik **hiçbir çağıranı yok** (üç yerde de arandı) → G-08. |
| `upload.php` MIME/uzantı/boyut/dizin/traversal? | **Temiz, bulgu yok.** Magic-byte doğrulaması (`finfo` yoksa `getimagesize`/`%PDF-` yedeği), uzantı doğrulanmış MIME'dan türetiliyor, 5 MB sınırı, `is_uploaded_file()`, dosya adı sunucuda üretiliyor, `subdir` segment-başına allowlist ile yeniden kuruluyor (max 3 segment) — traversal mümkün değil. Hedef `api/admin/uploads/`, orada `php_flag engine off` + `.php` reddi taşıyan `.htaccess` var. Aynı sağlamlık `updategv.php`'de de var. Üçüncü yükleme yolu `ajax/seo.php` bu düzeltmelerden **payını almamış** → G-06. |
| `db_backup.php` POST+CSRF+confirm zinciri kırılır mı? | **Kırılmıyor.** `_guard.php` GET dışı her metotta (HEAD dahil) CSRF şart koşuyor; satır 22 `mode !== 'list'` ise POST şart; `restore` ayrıca `$_POST['confirm'] === 'RESTORE'` istiyor. `?mode=restore` sorgu dizesiyle POST atmak CSRF'i atlamıyor. `Database::restore()` istemciden gelen dosya adına `basename()` + `realpath()` + dizin-içi kapsama uyguluyor → traversal yok. `backup()` boş dosya bırakmıyor. Tek kalıntı: satır 89 ham `$e->getMessage()` döndürüyor → G-12. |

---

## Bulgu tablosu (G serisi)

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi | Önerilen çözüm | Blocker? | Efor |
|---|---|---|---|---|---|---|---|---|---|
| **G-01** | Security | **P0** | `api/admin/chatbotistatistik.php:158-160,173` · `api/src/Presentation/Controllers/SocialController.php:171,181` | Sıradan bir kullanıcının gönderdiği `reported_for` alanı **hiç sanitize edilmeden** saklanıyor ve admin panelinde `insertAdjacentHTML` ile HTML olarak render ediliyor → depolanmış XSS, admin oturumunda çalışıyor. | `SocialController:171-181`: `$reportedFor = is_array(...) ? $data['reported_for'] : []` → `implode(',', array_map('strval', $reportedFor))`. Allowlist/enum/`strip_tags` **yok** — kardeş alan `report_detail` (satır 172) `InputSanitizer::text()`'ten geçiyor, bu geçmiyor. `schema.sql:182` `reported_for varchar(255)`, kısıt yok. Admin tarafı `chatbotistatistik.php:158-160`: `item.reported_for.split(',').map(tag => "<span ...>" + tag.replace('_',' ') + "</span>")` → satır 173 `insertAdjacentHTML('beforeend', detailHtml)`. Kaçış yok, panelde CSP yok (`_header.php`). | **Sömürü senaryosu:** herhangi bir kayıtlı kullanıcı `addReport`'a POST atar: `data = {"chatbot_id":1,"reported_for":["<img src=x onerror=...>"],"report_detail":"x"}` (virgül kullanmadan, ≤255 karakter). Admin `/admin/chatbotistatistik` sayfasında o botu tıkladığı anda kod **aynı origin'de, admin oturumuyla** çalışır. `_header.php:12` `CSRF_TOKEN`'ı global değişkende tutup her `fetch`'e eklediği için saldırgan CSRF'i de bedava alır: `ajax/adminler.php` ile yeni admin açar, `ajax/readenv.php` ile Gemini anahtarını çeker, `ajax/db_backup.php` ile `mode=restore` tetikler, `ajax/seo.php` ile G-06'yı zincirler. **Tam admin devralma.** | Sunucuda `reported_for` değerlerini sabit bir kümeye karşı doğrula, kümede olmayanı at. Panelde `textContent` kullan ya da kaçır. Ek olarak panele CSP ekle. | Hayır | S |
| **G-02** | Broken functionality | **P1** | `api/admin/abonelik.php:22,64,70,78,92,168,381` | `<form id="featureForm">` (satır 64) `<form id="dataForm">` (satır 22) **içine yuvalanmış**. HTML ayrıştırıcı iç `<form>` başlangıç etiketini yok sayar; satır 70'teki `</form>` dış formu kapatır. Sonuç: `getElementById("featureForm")` → `null`. | Satır 92 `const featureForm = document.getElementById("featureForm")` → `null`. Satır 381 `featureForm.addEventListener("submit", ...)` → `TypeError`; `DOMContentLoaded` handler'ı orada ölür → satır 435'teki `cancelFeatureBtn` dinleyicisi hiç bağlanmaz. Satır 168 `featureForm.reset()` de "Yeni Plan" handler'ının ortasında patlar (satır 174-177'deki liste temizliği çalışmaz). | Admin **plan içeriği (özellik) ekleyemiyor/güncelleyemiyor** — "Ekle/Güncelle" hiçbir şey yapmıyor, konsolda sessiz TypeError. Mevcut içerikler listelenir ve silinebilir (o dinleyiciler satır 381'den önce bağlanıyor). "Yeni Plan" butonu yarım çalışıyor. | `featureForm`'u `dataForm`'un dışına taşı, ya da `<form>` yerine `<div>` yapıp submit'i butonun `click`'ine bağla. | Hayır | S |
| **G-03** | Broken functionality | **P1** | `api/admin/kullanicilar.php:46` | "Kaydet" butonunun başlangıç sınıf listesinde `hidden` var ve kodun hiçbir yeri onu kaldırmıyor. | Satır 46: `<button type="submit" id="saveOrUpdate" class="flex-1 hidden bg-indigo-600 ...">`. Dosyada `saveOrUpdateBtn.classList.remove("hidden")` **hiç geçmiyor** (yalnızca `newBtn`/`deleteBtn` için var: satır 96-97, 203-204). Kardeş sayfalar `chatbotlar.php:95`, `chatbotkategoriler.php:35`, `abonelik.php:75` aynı butonu `hidden`**siz** tanımlıyor — bu tek sayfaya özgü. | Admin **Kullanıcılar sayfasından hiçbir kullanıcıyı kaydedemiyor/güncelleyemiyor**: buton görünmez. Enter'a basmak formu native submit eder (form'un `action`'ı yok) → sayfa yeniden yüklenir, hiçbir şey kaydedilmez. | `class`'tan `hidden`'ı kaldır. | Hayır | XS |
| **G-04** | Broken functionality | **P1** | `api/admin/kullanicilar.php:243` | Silme handler'ı `form.isim.value` okuyor ama formda `isim` adlı alan yok. | Formun alanları: `id` (22), `ad_soyad` (33), `kullanici_adi` (37), `eposta` (41). `isim` yok → `form.isim` `undefined` → satır 243 `TypeError` fırlatır, handler `confirm()`'a bile ulaşamaz. Aynı satır kardeş sayfada doğru: `abonelik.php:252` `dataForm.name_tr.value`. | Admin **Kullanıcılar sayfasından kullanıcı silemiyor**: "Sil" tıklanır, hiçbir şey olmaz, hata mesajı da yok. | `form.ad_soyad.value` yap (satır 169 ve 223 zaten onu kullanıyor). | Hayır | XS |
| **G-05** | Broken functionality | **P1** | `api/admin/kullanicilar.php:31-42` · `api/database/schema.sql` (`kullanicilar.sifre`) | Kullanıcı ekleme formunda **şifre alanı yok**; `create.php` `kullanicilar`'a `sifre` olmadan INSERT ediyor. Sütun `DEFAULT NULL` olduğu için insert başarılı olur. | Form yalnızca `ad_soyad`, `kullanici_adi`, `eposta` (+ gizli `id`) gönderiyor — satır 137-141 tüm `form.elements`'i topluyor. `schema.sql`: `sifre varchar(255) ... DEFAULT NULL`, `eposta` NOT NULL+UNIQUE. Girişte `password_verify($password, $hash)` `$hash = null` ile daima `false`. | Panelden eklenen kullanıcı **hiçbir zaman giriş yapamaz** — panel "başarıyla kaydedildi" der, hesap ölüdür. G-03 yüzünden bu yol bugün tetiklenemiyor; G-03 düzeltilince yüzeye çıkar. | Ya forma zorunlu şifre alanı ekle ve sunucuda `password_hash()` uygula, ya da sayfayı salt-düzenleme yap. **İş kuralı sorusu — belirsizlik #2.** | Hayır | S |
| **G-06** | File handling | **P1** | `api/admin/ajax/seo.php:37-86` (özellikle 50, 61-62) | Üçüncü ve **sertleştirilmemiş** yükleme yolu. İki ayrı sorun: (a) uzantı **istemcinin dosya adından** alınıyor, (b) `mime_content_type()` `function_exists` koruması olmadan çağrılıyor. | Satır 61-62: `$fileExt = pathinfo($file['name'], PATHINFO_EXTENSION); $uploadFile = $upload_base_dir . $custom_file_name_prefix . '.' . $fileExt;` → gerçek bir PNG `x.php` adıyla gönderilirse MIME kontrolünü geçer ve `assets/images/seo/og_image.php` olarak yazılır. Satır 50: `mime_content_type($file['tmp_name'])` — `upload.php:25` ve `updategv.php:79` ikisi de `function_exists('finfo_open')` ile koruyor, burada koruma yok; deponun kendi notları (`upload.php:22-24`, `InputSanitizer::detectMime`) denetlenen ortamda **ext-fileinfo'nun bulunmadığını** söylüyor → orada bu satır ölümcül hata. | (a) Apache'de `api/assets/.htaccess` (`php_flag engine off` + `.php` reddi) yürütmeyi engelliyor; ama `php -S ... router.php` altında `.htaccess` okunmaz ve `router.php` denylist'i `/assets/*.php`'yi kapsamıyor → **dev sunucuda kod çalıştırma**. G-01 ile zincirlenirse sıradan bir kullanıcıdan başlar. (b) fileinfo'suz ortamda **OG/Twitter/favicon görseli hiç yüklenemiyor** — 500. | Uzantıyı doğrulanmış MIME'dan türet (`upload.php:47-53` haritası); `upload_detect_mime()`'ı paylaşılan yardımcıya çıkarıp burada da kullan. | Hayır | S |
| **G-07** | Environment | **P2** | `api/admin/ajax/updateenv.php:44-66` | Yazılacak `.env` anahtarları için **allowlist yok** ve değer kaçırılmıyor → satır sonu enjeksiyonu. | Satır 44-62: `foreach ($_POST as $key => $value)` → `camelToApiCase($key)` → `$lines[] = $newKey . '=' . $value`. `$value` içinde `\n` varsa satır 66'daki `implode(PHP_EOL, $lines)` onu **ayrı bir .env satırı** olarak yazar (ör. `googleGemini=x%0AFOO=bar`). Çağıran sayfa (`api.php`) tek alan gönderiyor; endpoint sınırsız. | Tek başına: admin kendi yapılandırma dosyasını bozabilir — yetki yükselmesi değil, çünkü hedef `api/admin/.env`, `admin_login` ise `api/.env`'i okuyor ve `Dotenv::createImmutable` yüklü anahtarı ezmiyor. **G-01 ile zincirlenince** sıradan bir kullanıcı sunucudaki bir yapılandırma dosyasına satır yazdırabiliyor. | Anahtar allowlist'i (`['googleGemini']`) + değerde `\r\n` reddi + değeri tırnak içine al. | Hayır | S |
| **G-08** | Security | **P2** | `api/admin/ajax/readenv.php:21-60` | `.env` anahtar değerini **maskeleme ve allowlist olmadan** ham JSON olarak tarayıcıya döndürüyor. Üstelik endpoint'in hiçbir çağıranı yok. | Satır 21 `$key = $_POST['key']`, satır 55-60 `'value' => $envValue`. Filtre yok. Çağıran araması üç yerde de (`web/src`, `api/admin`, `api/router.php`) **sıfır** sonuç verdi — `api.php` bile değeri `$_ENV`'den okuyor, bu endpoint'ten değil. | Panelde bir XSS (G-01) bu uçla `api/admin/.env`'deki her anahtarı (bugün: Gemini API anahtarı) tek istekte dışarı çıkarır. Kullanımı olmayan saf saldırı yüzeyi. | **Silme adayı** (belirsizlik #1). Silinmeyecekse: anahtar allowlist'i + maskelenmiş değer. | Hayır | XS |
| **G-09** | Authentication | **P2** | `api/admin/functions/admin_login.php:206-210` | Oturuma `admin_login_at`, `admin_login_ip`, `admin_source`, `admin_id` yazılıyor ama **hiçbiri hiçbir yerde okunmuyor**. Satır 209'daki yorum "oturum sabitleme + oturum çalma karşısında ikinci bir bağ" diyor — böyle bir bağ yok. | `api/admin`, `api/src`, `api/functions`, `api/api` genelinde arama: bu dört anahtar yalnızca yazıldıkları satırlarda geçiyor. `_guard.php:27` sadece `empty($_SESSION['admin'])`'e bakıyor. | Admin oturumunun **ne boşta kalma ne mutlak zaman aşımı var**; çerez `lifetime => 0` ama sunucu tarafı yalnızca PHP'nin varsayılan GC'sine bağlı. Çalınan bir oturum kimliği süresiz geçerli. Aynı oturum `AuthMiddleware::requireAdmin()` üzerinden 7 `/api` ucuna da yetki veriyor. | `_guard.php`'ye `admin_login_at` üzerinden boşta-kalma + mutlak zaman aşımı ekle; ya da yorumu gerçeğe uydurup alanları sil. Ortak guard'ı ve oturum davranışını etkiliyor → **uygulanmadı, diff olarak önerilir** (otonomi sözleşmesi md. 4). | Hayır | S |
| **G-10** | Security | **P2** | `api/admin/ajax/cikis.php:1-11` | `_guard.php`'yi çağırmayan tek ajax dosyası: metot kontrolü yok, CSRF kontrolü yok. | Dosyanın tamamı 11 satır: `admin_session_start(); unset($_SESSION['admin']);`. `_guard.php`'nin kendi başlığı (satır 6-10) tam olarak bu deseni sorun olarak tarif ediyor ama bu dosya listeden düşmüş. | Cross-site logout: adminin ziyaret ettiği herhangi bir sayfa `<img src>`/form ile oturumu düşürebilir. Yıkıcı değil ama panel kullanılamaz hale gelir. Ayrıca `unset` oturumu yok etmiyor, yalnızca anahtarı siliyor (`session_destroy()` yok) → aynı oturum kimliği yeniden yetkilenebilir. | `require_once __DIR__ . '/_guard.php';` ekle (`_header.php` shim'i başlığı zaten gönderiyor) ve `session_destroy()` ile bitir. | Hayır | XS |
| **G-11** | Security | **P2** | `api/admin/kullanicilar.php:66-84` · `api/admin/ajax/read.php:15,43` | Kullanıcı düzenleme ekranı `columns` göndermiyor → `read.php` `*` kullanıyor → `kullanicilar` satırının **tamamı** (bcrypt `sifre` hash'i, `google_id`, `telefon`, `dogum_tarihi`, base64 `avatar`) tarayıcıya iniyor; satır 81 hepsini `console.log`'a da yazıyor. | `kullanicilar.php:66-68` yalnızca `table` + `where` ekliyor. `read.php:15`: `$columns = $_POST['columns'] ?? "*"`. Sayfa bu verinin sadece üç alanını kullanıyor — satır 82 `if (form[key])` gerisini atıyor. | Her kullanıcı hash'i ve PII'si admin tarayıcısının belleğine, devtools geçmişine ve araya giren her proxy/log'a düşüyor. G-01 bunu doğrudan hasat edebilir. | `formData.append("columns", "id, ad_soyad, kullanici_adi, eposta")` — `assertSafeColumnList` bu biçimi zaten kabul ediyor. `console.log`'u kaldır. | Hayır | XS |
| **G-12** | Error handling | **P2** | `api/admin/subpages/seosite.php:9-11` · `api/admin/ajax/db_backup.php:83-90` | İki yerde ham exception mesajı istemciye sızıyor; `seosite.php` ayrıca onu bir `<script>alert("...")` bloğunun **içine** yazıyor. | `seosite.php:10`: `echo '<script>alert("Veriler alınırken bir hata oluştu: ' . $e->getMessage() . '");</script>';` — kaçış yok; mesajdaki bir `"` JS'i bozar, mesajın kendisi SQL/tablo adı taşır. Kardeş dosyalar `seometa.php:6-7` ve `seotwitter.php:8-9` bunu doğru yapıyor (`error_log` + genel mesaj) — düzeltme bu dosyaya uygulanmamış. `db_backup.php:89`: `'message' => $e->getMessage()` — `mysqlBinary()` ve `backupDir()` istisnaları sunucu dosya yollarını içeriyor. | Admine sunucu iç yapısı sızıyor; `seosite.php`'de tırnak içeren bir hata mesajı sayfanın JS'ini kırar. | `seosite.php`'yi kardeşleriyle aynı desene çevir. `db_backup.php`'de `error_log` zaten var (satır 84); istemciye genel mesaj dön. | Hayır | XS |
| **G-13** | Broken functionality | **P2** | `api/admin/ajax/seo.php:95,134,192` · `subpages/seosite.php:13` · `seometa.php:50` · `seotwitter.php:55` | Yükleme hedefleri ve varlık kontrolleri **göreli yol** kullanıyor — PHP'nin çalışma dizinine göre çözülüyor, dosyanın konumuna göre değil. | `seo.php:95` `'../../assets/images/seo/'`, satır 134 `'../../assets/twitter/'`, satır 192 `'../../assets/'`. Apache'de CWD betiğin dizini olduğu için `api/assets/...`'e düşer; `php -S ... router.php` altında CWD sunucunun başlatıldığı dizindir → proje dışına yazabilir. `seosite.php:13` `$faviconPath = '../assets/favicon.ico'`, `seometa.php:50`/`seotwitter.php:55` `file_exists($ogImagePath)` aynı sorunda. Bu, `sitemap.php:14-17` ve `seo.php:170-180`'de zaten teşhis edilip düzeltilmiş **SEO-002 hata sınıfının** kalan örneği. | Dev sunucuda SEO görselleri yanlış dizine yazılır ve önizleme daima "yok" görünür. Yayında sessizce çalışıyor gibi durur. | Üç yolu da `__DIR__` tabanlı yap. | Hayır | XS |
| **G-14** | Broken functionality | **P2** | `api/admin/chatbotlar.php:212-224` | Yüklenen görselin göreli yolu `data` nesnesine, `JSON.stringify(data)` **çağrıldıktan sonra** yazılıyor → atama ölü. | `formData.append("data", JSON.stringify(data));` önce, `data[el.name] = relativePath;` sonra geliyor. Sunucuya giden JSON o alanı hiç içermiyor. Sunucu tarafında `create.php:35-47` / `update.php:43-55` `$_FILES`'ı zaten **base64'e çevirip** aynı sütuna yazıyor. | Chatbot kapak/profil görselleri panelden `assets/...` yolu yerine dev base64 blob'ları olarak DB'ye yazılıyor — F-01'in tarif ettiği yol tabanlı depolamayla çelişiyor, iki yazma yolu iki farklı biçim üretiyor. | Ölü atamayı sil; sunucu tarafındaki base64 dalını `ChatbotController::handleImageUploads` gibi dosya yazmaya çevir (F-01 ile birlikte ele alınmalı). | Hayır | M |
| **G-15** | Dead code | **P2** | `api/admin/logo.php` · `api/admin/assets/js/login.js` | İkisi de hiçbir yerden yüklenmiyor ve yüklenseler çalışmazlardı. | `logo.php`: `index.php:32-56` route tablosunda **yok**, `include` eden başka dosya da yok; satır 4 tanımsız `$logo['var_value']`'yu yazdırıyor; satır 37 `updategv.php`'ye `var_key=site_logo` gönderiyor ama `updategv.php` POST anahtarlarını **doğrudan** `var_key` olarak kullanıyor — yani `var_key` adlı bir global var yazardı. `login.js`: `/ajax/giris.php`'ye POST ediyor (doğrusu `/admin/ajax/giris.php`), ve `_login.php` kendi inline script'ini kullanıyor — hiçbir `<script src>` bu dosyayı çağırmıyor. | Yok (erişilemez). Bakım yükü ve yanıltıcı: birinin "logo yönetimi var" sanmasına yol açar. | **Silme adayı** (belirsizlik #1). Logo yönetimi gerçekten isteniyorsa route + `updategv` sözleşmesine uygun alan adı gerekir. | Hayır | XS |
| **G-16** | Frontend | **P3** | `api/admin/kullanicilar.php:174` · `api/admin/chatbotlar.php:254` | Yeni kayıt sonrası listeye eklenen `<li>`'nin metni şablon değişkeni yerine **düz metin**: `li.innerText = \`baslik1\``. | İki dosyada da bir satır üstte `const baslik1 = form.ad_soyad.value` / `form.isim.value` var; şablonda `${baslik1}` yazılması gerekirken ham `baslik1` duruyor. | Kayıt sonrası listede öğe "baslik1" diye görünür; sayfa yenilenene kadar hangi kaydın eklendiği anlaşılmaz. | `li.textContent = baslik1`. `abonelik.php:225` bunu doğru yapıyor. | Hayır | XS |
| **G-17** | Frontend | **P3** | `api/admin/index.php:76-77` | `intval()` bir diziye uygulanıyor. | `$database->getGlobalVars('theme_index')` **dizi** döndürüyor (`db.php:355-366`). `intval(['theme_index' => '3'])` boş olmayan dizi için daima `1` → `$themes[1-1]` → daima `$themes[0]`. | Tema seçimi hiçbir zaman uygulanmıyor. `themes` tablosu boşsa `$current_theme` `null` olur ve `_sidebar.php:3` `$current_theme['main_color']` uyarı üretip boş sınıf yazar → panel stilsiz görünür. | `getGlobalVars('theme_index')['theme_index'] ?? 1` (`genelayar.php:2`'deki desen) + `$themes` boşsa yedek. `genelayar.php:137` "tema seçimi kaldırıldı" diyorsa alanı tamamen sil. | Hayır | XS |
| **G-18** | Data integrity | **P3** | `api/src/Presentation/Controllers/SocialController.php:172` · `api/database/schema.sql:183` | Sanitizer 2000 karaktere kırpıyor, sütun `varchar(1000)`. | `InputSanitizer::text($data['report_detail'] ?? '', 2000)` ↔ `report_detail varchar(1000) DEFAULT NULL`. MySQL `STRICT_TRANS_TABLES` (5.7+ varsayılan) altında 1000'den uzun değer SQLSTATE 22001 ile **hata** verir. | 1000-2000 karakter arası açıklama yazan kullanıcı 500 alır; şikayeti kaydedilmez. | Sınırı 1000'e indir (ya da sütunu `TEXT` yap). G-01 düzeltilirken aynı fonksiyonda. | Hayır | XS |
| **G-19** | Documentation | **P3** | `api/admin/satiskosullari.php:6` | Mesafeli Satış Sözleşmesi sayfası "Hakkımızda Sayfası" başlığını gösteriyor (kopyala-yapıştır). | `pageTitle("Hakkımızda Sayfası", "Bu sayfada, sitenizin 'Hakkımızda' bölümünde yer alan içeriği düzenleyebilirsiniz.")` — `hakkinda.php:6` ile birebir aynı metin. Dosyanın geri kalanı `satis_kosullari` anahtarıyla doğru çalışıyor. | Admin yanlış sayfada olduğunu sanır. | Başlığı düzelt. | Hayır | XS |
| **G-20** | Security | **P3** | `api/admin/partials/_header.php:45-48` · beş içerik sayfasında `cdn.ckeditor.com` | Panel dört ayrı CDN'den SRI'siz script/CSS yüklüyor ve **hiç CSP yok**. | `_header.php:45-48`: `cdn.jsdelivr.net/npm/bootstrap@5.3.3`, `cdn.tailwindcss.com`, `cdnjs.cloudflare.com/.../bootstrap-icons`, `cdnjs.../bcryptjs`. Beş içerik sayfası ayrıca `cdn.ckeditor.com/ckeditor5/36.0.0/classic/ckeditor.js`. `integrity=`/`crossorigin=` hiçbirinde yok. `api/.htaccess:41-45` yalnızca `nosniff`/`X-Frame-Options`/`Referrer-Policy` gönderiyor. | Herhangi bir CDN'in ele geçirilmesi = en yetkili panelde kod çalıştırma. CSP'nin yokluğu ayrıca G-01'in tam etkili olmasının sebebi. | Kritik olanları yerelleştir veya SRI ekle; panele `Content-Security-Policy` ekle. `bcryptjs` (satır 48) hiçbir yerde kullanılmıyor — kaldırılabilir. | Hayır | M |

### ŞÜPHE (kanıtlanamadı, bulgu olarak sayılmadı)

- **CKEditor `editorInstance` yarışı** — `hakkinda.php:63-64`, `gizlilikpolitikasi.php:64-65`, `kullanimkosullari.php:64-65`, `satiskosullari.php:63-64`, `teslimatiade.php:65-66`. Beş sayfada da `editorInstance` `ClassicEditor.create(...).then()` içinde atanıyor ama `DOMContentLoaded` handler'ında **koşulsuz** kullanılıyor (`editorInstance.setData(...)`). Promise `DOMContentLoaded`'dan önce çözülmezse `TypeError` olur ve form submit dinleyicisi hiç bağlanmaz (→ sözleşme metinleri ne yüklenir ne kaydedilir). CKEditor 5'in init zinciri saf microtask ise sorun çıkmaz. **Statik okumayla karara bağlanamadı; tarayıcıda doğrulanması gerekiyor.** Doğruysa çözüm basit: `setData` ve form bağlamayı `.then(editor => ...)` içine taşımak.

---

## Doğrulanan ama bulgu OLMAYAN noktalar (G turu)

- **Generic CRUD'da SQL enjeksiyonu yok.** `assertAllowedAdminTable` / `assertSafeWhereFragment` / `assertSafeColumnList` üçlüsü dört ucun (`create/read/update/delete`) tamamında çağrılıyor; gramer beyaz listeleri alt sorguya, fonksiyon çağrısına ya da serbest string literaline yer bırakmıyor. Panelin gönderdiği tüm biçimler (`id = N`, `plan_id = N ORDER BY id ASC`, `chatbot_id = N AND FIND_IN_SET('spam', reported_for) > 0`, `COUNT(*) as total`, `c.*, CONCAT(...)`) beyaz listelerle uyuşuyor — düzeltme paneli kırmamış.
- **`adminler` tablosuna generic CRUD'dan erişilemiyor.** Dört uçta da `stripos($table, 'adminler')` bloğu **ve** tablo beyaz listesi var (çift kilit). `ajax/adminler.php` ayrı bir uç ve `admin_env_account() !== null` ise 409 ile fail-closed.
- **`upload.php`** — yukarıdaki soru-cevap tablosunda ayrıntılandırıldı. Bulgu yok. `subdir` parametresi bugün hiçbir çağıran tarafından gönderilmiyor.
- **`db_backup.php` zinciri** kırılmıyor; `restore()` yol geçişine kapalı; `backup()` boş dosya bırakmıyor.
- **Admin girişi.** `admin_login_attempt()` tek nokta; `session_regenerate_id(true)` yetki yükselmesinden önce; iki katmanlı rate limit (hesap 5/15dk, IP 20/15dk); `X-Forwarded-For`'a güvenmiyor; hesap yoksa da dummy hash doğrulaması (zamanlama); `hash_equals` doğru kullanılmış; `.env` modunda `adminler` tablosu hiç okunmuyor.
- **Oturum çerezi.** `admin_session_start()` `HttpOnly` + `SameSite=Lax` + koşullu `Secure` ile `functions/bootstrap.php`'yi birebir aynalıyor.
- **Admin sayfalarında sunucu tarafı XSS yok.** Listelerde DB'den gelen her metin `htmlspecialchars` ile kaçırılıyor (`kullanicilar.php:14`, `chatbotlar.php:15`, `chatbotkategoriler.php:14`, `abonelik.php:15`, `adminler.php:57`, `hit.php:33-34`, SEO `value=` alanlarının tamamı). `tailmind.php`'nin `pageTitle()`/`generalInput()` yardımcıları kaçırmıyor ama **13 çağıranın hepsi sabit metin geçiyor** — bugün sömürülebilir değil. `renderDataList()` kaçırıyor ve hiç çağrılmıyor. İstemci tarafında `innerHTML` kullanan tek **kullanıcı-verili** nokta G-01.
- **`.env` dosyaları git'te değil.** `git ls-files` yalnızca `api/.env.example` döndürüyor; `.gitignore:64-66` `.env*`'i dışlıyor. `api/.env` ve `api/admin/.env` takipsiz. (İçerikleri okunmadı.)
- **Üç denylist tutarlı.** `api/.htaccess`, `api/admin/.htaccess`, `api/router.php` üçü de `.env`, `db_backup`, `error_log`, arşiv/dump uzantılarını kapsıyor; `router.php:16-20` ayrıca `%2e%2e` çözümü sonrası traversal'ı reddediyor.
- **`sitemap.php`** bilerek 410 dönüyor, hata değil.
- **`php -l`** 49 dosyanın hepsinde temiz.

---

## Kalan boşluklar (G turu sonrası)

`api/admin/` artık kapsam dışı **değil**. Aşağıdakiler hâlâ açık:

1. **`api/admin/vendor/` (77 dosya) okunmadı** — kural gereği kapsam dışı. `vlucas/phpdotenv` ve `symfony/polyfill-*` sürümlerinin CVE durumu kontrol edilmedi. Not: `api/admin/` **kendi** `composer.json`/`vendor`'ını taşıyor, `api/`'ninkinden ayrı — iki bağımlılık ağacı farklı sürümlere sürüklenebilir.
2. **`api/admin/assets/js/admin.js` ve `Notification.js` okunmadı** — PHP olmadıkları için 49'un dışında kaldılar. `admin.js` her sayfada yükleniyor (`_header.php:49`); `Notification` sınıfı **her ajax yanıtının `message` alanını** render ediyor. `Notification.js` `innerHTML` kullanıyorsa her `result.message` bir XSS yüzeyi olur — ve `create.php:59`, `update.php:67`, `delete.php:44` ham `$e->getMessage()` döndürüyor. **Bir sonraki turda ilk okunacak şey bu.**
3. **Hiçbir bulgu çalışan panele karşı doğrulanmadı.** G-02/G-03/G-04 HTML+JS semantiğinden kesin çıkarılıyor; G-06'nın (b) şıkkı "bu ortamda fileinfo var mı?" sorusuna bağlı ve deponun kendi notlarına dayanıyor (`php -m` çalıştırılmadı); ŞÜPHE maddesi tarayıcı gerektiriyor.
4. **`api/admin/uploads/` ve `api/assets/` dizinlerinin gerçek içeriği ve izinleri incelenmedi** (ana denetimin #8'i geçerli). Şu an ikisi de boş görünüyor.
5. **Panelin yük altındaki davranışı ölçülmedi.** `kullanicilar.php:2` `SELECT * FROM kullanicilar` (tüm kullanıcılar, `avatar` longtext dahil, LIMIT'siz); `chatbotlar.php:2`, `abonelik.php:2`, `chatbotistatistik.php:2` aynı desende. Veri hacmi ölçülemediği için bulgu yazılmadı.
6. **`chatbotistatistik.php:101-147`** her tıklamada 5 + 4 + 1 = **10 ayrı `read.php` isteği** atıyor. Ölçülmediği için bulgu değil; mevcut N+1 kayıtlarıyla aynı desen.

---

## Karar vermem gereken belirsizlikler (G turu)

1. **`readenv.php` ve `logo.php` silinsin mi? (G-08, G-15)** İkisinin de üç yerde çağıranı yok. `readenv.php` ileride bir "anahtarı göster" özelliği için bilerek bırakılmış olabilir; `logo.php` yarım kalmış bir logo yönetimi ekranına benziyor. **Soru:** bunlar (a) silinecek ölü kod mu, (b) tamamlanacak yarım özellik mi? Kendim silmedim.

2. **Admin panelinden kullanıcı eklenebilmeli mi? (G-05)** Form şifre alanı taşımıyor, dolayısıyla eklenen hesap giriş yapamıyor. İki makul davranış var ve hangisinin doğru olduğu bir iş kuralı: (a) admin şifre belirleyerek kullanıcı açabilmeli, (b) kullanıcı yalnızca kayıt akışından doğmalı, panel salt-düzenleme olmalı. (b) ise "Yeni Ekle" butonu ve `create.php` çağrısı kaldırılmalı. **Mevcut davranışın doğru mu yanlış mı olduğu belirsiz olduğu için test yazmadım.**

3. **`reported_for` için kanonik küme nedir? (G-01)** `chatbotistatistik.php:84-89` dört değer sayıyor (`sexual_content`, `legal_issue`, `terrorism`, `spam`); `web/src/features/moderation/ReportModal.jsx` daha fazlasını gönderiyor olabilir; sunucuda hiç liste yok. **Soru:** izin verilen şikayet nedenlerinin tam listesi nedir? Yanlış bir liste mevcut şikayet gönderimlerini sessizce kırar. (Not: yama bu cevabı beklemek zorunda değil — panel tarafında `textContent`'e geçmek tek başına XSS'i kapatır ve hiçbir davranışı kırmaz.)

---

# Ek tur — `api/src/` kapsam kapatma (H serisi)

Tarih: 2026-09-02 · Yöntem: salt-okunur. Bu tur da yeni bir tam denetim değil; ana denetimin `api/src/**` kapsamındaki 16 dosyalık boşluğu kapatıyor.

## Okunmamış olan 16 dosya

Ana denetimin "Kapsam" satırı `api/src/**` için 19 dosya sayıyor (14 controller, 2 repository, `AuthMiddleware`, `InputSanitizer`, `IyzicoClient`). Ağaçta 35 PHP dosyası var. Aradaki 16 dosya — hepsi bu turda okundu:

| # | Dosya | Satır | Katman |
|---|---|---|---|
| 1 | `Application/UseCases/Auth/RegisterUseCase.php` | 64 | Application |
| 2 | `Application/UseCases/Auth/LoginUseCase.php` | 46 | Application |
| 3 | `Application/UseCases/Auth/GoogleLoginUseCase.php` | 36 | Application |
| 4 | `Domain/Interfaces/CartRepositoryInterface.php` | 11 | Domain |
| 5 | `Domain/Interfaces/ChatRepositoryInterface.php` | 15 | Domain |
| 6 | `Domain/Interfaces/ChatbotRepositoryInterface.php` | 24 | Domain |
| 7 | `Domain/Interfaces/NotificationRepositoryInterface.php` | 8 | Domain |
| 8 | `Domain/Interfaces/SocialRepositoryInterface.php` | 39 | Domain |
| 9 | `Domain/Interfaces/SubscriptionRepositoryInterface.php` | 10 | Domain |
| 10 | `Domain/Interfaces/UserRepositoryInterface.php` | 18 | Domain |
| 11 | `Domain/Interfaces/WalletRepositoryInterface.php` | 12 | Domain |
| 12 | `Infrastructure/Database/BaseRepository.php` | 115 | Infrastructure |
| 13 | `Presentation/Response/JsonResponse.php` | 60 | Presentation |
| 14 | `Shared/Constants/AppConfig.php` | 140 | Shared |
| 15 | `Shared/Exceptions/AppException.php` | 53 | Shared |
| 16 | `autoload.php` | 73 | — |

Doğrulama için ayrıca (yeniden) bakılanlar: `UserRepository`, `ChatbotRepository`, `AuthController` (register/login/google dalları), `InputSanitizer::pickAllowed`/`isSafeIdentifier`, `functions/bootstrap.php` (`parse_post_data`), `functions/plans.php`, `functions/chatbot_limits.php`, `functions/producer_plan.php`, `functions/coin_engine.php`, `web/src/shared/lib/pricing.js`, `migrations/007_plan_limits.sql`, `WalletController::getPricing()`.

---

## E kategorisi — sabit senkronizasyon tablosunun genişletilmiş hâli

Ana denetimin tablosu üç kaynağı karşılaştırıyordu (`AppConfig.php` · `coin_engine.php` · `pricing.js`) ve uyuşmazlık bulmamıştı. **O tablo hâlâ doğru.** Ancak `AppConfig` tam olarak okununca senkron yüzeyinin üç değil **beş** olduğu görülüyor: `plans` tablosunun satırları ve yükleme uçlarının kendi kopyaları da aynı değerleri taşıyor.

### Ana tabloya eklenen satırlar

| Sabit | `AppConfig.php` | Diğer kopyalar | Durum |
|---|---|---|---|
| Bağımsız bot limiti | `FREE_INDEPENDENT_BOT_LIMIT = 1` (s.12) | `plans.independent_bot_limit` sütunu `NOT NULL DEFAULT 1` (007:44) · `plans.php:74` fallback | ⚠️ İki kaynak. Fallback ile DB varsayılanı bugün eşit, ama H-04'e bakın. |
| Herkese açık bot limiti | `FREE_PUBLIC_BOT_LIMIT = 2` (s.13) | `plans.public_bot_limit` `DEFAULT 2` (007:44) · `plans.php:75` | ⚠️ Aynı. |
| Günlük mesaj | `DAILY_FREE_MESSAGES = 10` (s.22) | `plans.daily_message_limit` `DEFAULT 10` (007:44) · `plans.php:76` | ⚠️ Aynı. |
| Maks yükleme boyutu | `MAX_UPLOAD_SIZE_BYTES = 5 MB` (s.31) | `admin/ajax/upload.php:54` **5 MB (elde)** · `admin/ajax/updategv.php:63` **5 MB (elde)** · `admin/ajax/seo.php:96,137` **500 KB** · `:192` **50 KB** | ⚠️ H-06 — dört ayrı kopya, hiçbiri `AppConfig`'i okumuyor. |
| İzinli görsel MIME | `ALLOWED_IMAGE_MIMES` (s.29) | `admin/ajax/upload.php:47-53` (+`application/pdf`) · `admin/ajax/updategv.php:57-62` · `admin/ajax/seo.php:97,136` (yalnız png/jpeg) | ⚠️ H-06 — dört ayrı liste. |
| Abonelik süreleri | `SUBSCRIPTION_WEEKLY = 7` / `MONTHLY = 30` (s.8-9) | Yalnız `MarketplaceController:383` okuyor | ✅ Tek okuyucu. |
| Sayfalama | `DEFAULT_PAGE_LIMIT = 20` (s.34) | — | ⚠️ Hiçbir yerde okunmuyor (README:1033 de böyle diyor). |
| Üretici planı limitleri | `PRODUCER_*_LIMIT` (s.18-19) | — | ⚠️ Hiçbir yerde okunmuyor (D-07). `producer_plan.php:13` ve `chatbot_limits.php` bunu kendi yorumlarında doğruluyor. |

**Fiyat/komisyon/coin tarafında hâlâ uyuşmazlık yok.** Yeni olan: bot ve mesaj kotalarının **canlı DB satırlarında** da yaşaması ve o satırların admin panelinden düzenlenebilir olması — sabitin senkronu artık kod incelemesiyle garanti edilemiyor. H-04 bunun somut kırılmasını gösteriyor.

---

## Bulgu tablosu (H serisi)

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi | Önerilen çözüm | Blocker? | Efor |
|---|---|---|---|---|---|---|---|---|---|
| **H-01** | Security | **P1** | `api/src/Application/UseCases/Auth/RegisterUseCase.php:34-47` · `Infrastructure/Repositories/UserRepository.php:51-53` · `Infrastructure/Database/BaseRepository.php:51-71` | Kayıt akışı istemcinin gönderdiği ham diziyi **sütun beyaz listesi olmadan** `INSERT`'e geçiriyor → mass assignment. `kullanicilar` tablosunun her sütunu istemci tarafından belirlenebiliyor. | `AuthController:57` `$data = parse_post_data()` — `bootstrap.php:175-184` yalnızca `json_decode` yapıyor, anahtar filtresi yok. `RegisterUseCase:34-36` sadece `sifre`, `kullanici_adi`, `eposta`'yı **üzerine yazıyor**; diğer anahtarlar dokunulmadan kalıyor. Satır 47 `$this->users->create($data)` → `UserRepository:52` `self::insert(self::T, $data)` → `BaseRepository:62-71` sütun listesini `array_keys($data)`'dan kuruyor. Tek kontrol `assertSafeColumns` (s.51-59) ve o yalnızca adın **şeklini** doğruluyor (`isSafeIdentifier`: `^[A-Za-z_][A-Za-z0-9_]*$`), **hangi sütun** olduğunu değil. `InputSanitizer::pickAllowed` altı çağrı yerinde uygulanmış (`ChatbotController:103,257`, `ChatController:17`, `MarketplaceController:161,627`, `SocialController:125`) — **kayıt yolunda yok.** `BaseRepository:48-49`'un kendi yorumu "çağıranlar ayrıca kendi sütun beyaz listelerini uyguluyor" diyor; bu çağıran için doğru değil. **Yüzey tam olarak bir uç:** `UserRepository::create()`'in yalnızca iki çağıranı var — `RegisterUseCase` (savunmasız) ve `GoogleLoginUseCase:14-18` (üç anahtarlı sabit dizi geçiyor, etkilenmiyor). `kullanicilar`'a yazan diğer yol `UserController`'ın `updateById` çağrıları ve hepsi elle kurulmuş tek-anahtarlı dizi (`:83,106,130,213`), yani yamanın kapsamı dar ve risksiz. | **Sömürü senaryosu:** `POST` kayıt isteğine fazladan alan eklenir: `{"kullanici_adi":"x","eposta":"a@b.c","sifre":"Str0ng!pass","google_id":"<kurbanın Google sub'ı>"}`. `google_id varchar(64) DEFAULT NULL` ve `isSafeIdentifier('google_id')` geçtiği için satır yazılır. Sonra kurban **ilk kez** Google ile giriş yaptığında `UserRepository::findByGoogleId():45-48` `WHERE google_id = ? OR eposta = ?` sorgusu **saldırganın satırını** bulur, `GoogleLoginUseCase:28` onun `id`'sini döndürür ve kurban saldırganın hesabına giriş yapar — o oturumda girdiği her şey (checkout kartı, eğitim verisi, notlar) saldırganın hesabına yazılır. ⚠️ **Ön koşul:** saldırganın kurbanın Google `sub` değerini bilmesi gerekir; bu değer herkese açık değildir, kurbanın giriş yaptığı başka bir OAuth uygulamasından elde edilebilir. Ön koşulsuz ikinci etki: `avatar` (`longtext`) alanına kayıt sırasında megabaytlarca veri yazılabilir (kayıt limiti IP başına 5/10dk) ve `id` elle verilebilir. | `RegisterUseCase`'in başına `InputSanitizer::pickAllowed($data, ['kullanici_adi','eposta','sifre','ad_soyad','telefon','dogum_tarihi','dil'])` ekle — altı kardeş çağrı yerindeki desenin aynısı. | Hayır | S |
| **H-02** | Race conditions | **P1** | `api/src/Application/UseCases/Auth/GoogleLoginUseCase.php:11-25` | Klasik check-then-create: `findByGoogleId()` ile bakılıyor, sonra `create()` çağrılıyor; ikisi ayrı gidiş-dönüş ve **duplicate yakalaması yok**. | Satır 11 `$user = $this->users->findByGoogleId(...)`, satır 14 `$userId = $this->users->create([...])`. Arada hiçbir transaction/kilit yok. `kullanicilar.eposta` UNIQUE olduğu için ikinci INSERT `PDOException` fırlatır ve `AuthController:179-180`'deki `try` yalnızca `AppException` yakalıyor → **yakalanmamış PDOException, ham 500**. Kardeş dosya `RegisterUseCase:46-53` tam olarak bu senaryoyu yakalıyor ve neden yakaladığını yorumda açıklıyor; `coin_engine.php:65-80` de aynı deseni uyguluyor. Üç yerden ikisinde düzeltme var, burada yok. | **"Aynı istek iki sekmeden gelirse":** Google ile ilk kez giriş yapan bir kullanıcı butona iki kez basarsa (ya da iki sekmeden dönerse) iki istek de "kullanıcı yok" görür, ikisi de INSERT dener, biri 500 alır. Kullanıcı için görünen: giriş rastgele başarısız oluyor. Veri bozulmuyor (UNIQUE koruyor). | `create()` çağrısını `RegisterUseCase:46-53`'teki `try/catch (str_contains($e->getMessage(), 'Duplicate entry'))` bloğuyla sar; yakalayınca satırı yeniden oku ve o kullanıcıyla devam et. | Hayır | XS |
| **H-03** | Broken functionality | **P2** | `api/src/Application/UseCases/Auth/GoogleLoginUseCase.php:14-18` | Google ile açılan hesaba **`kullanici_adi` hiç yazılmıyor** ve hiçbir yerde sonradan doldurulmuyor. | Satır 14-18 `create()`'e yalnızca `google_id`, `eposta`, `ad_soyad` veriyor. Şema: `kullanici_adi varchar(30) DEFAULT NULL` + UNIQUE (MySQL çoklu NULL'a izin verir, bu yüzden hata da vermiyor). Klasik kayıt yolunda alan her zaman dolu: `web/src/app/login/page.jsx:427-428` `eposta.split("@")[0]` ile türetiyor. Google yolunda karşılığı yok. `UserController:46` ve `:65` `'username' => $user['kullanici_adi']` döndürüyor → **null**. | Google ile kaydolan her kullanıcının kullanıcı adı boş: `dashboard/chat/page.jsx:228` (`item.kullanici_adi` — mesaj yazarı), `notes/page.jsx:65,470` ve `DialogueModal.jsx:304,323` (`owner_kullanici_adi`) boş görünüyor; profil başlığında kullanıcı adı yok. Kullanıcı Ayarlar'dan (`settings/page.jsx:744`) elle doldurabiliyor, yani kalıcı değil ama varsayılan hâli bozuk. İkincil asimetri: `RegisterUseCase:54` `addEmailRecord()` çağırıyor, `GoogleLoginUseCase` çağırmıyor → Google kullanıcıları `user_emails` tablosunda yok. (Bu tabloyu bugün **hiçbir kod okumuyor**, o yüzden görünür etkisi yok.) | `create()`'e türetilmiş bir `kullanici_adi` ekle (e-postanın yerel kısmı + çakışmada sayaç), ya da ilk girişte kullanıcıya seçtir. `addEmailRecord()` çağrısını da ekleyerek iki yolu eşitle. | Hayır | S |
| **H-04** | Data integrity | **P2** | `api/database/migrations/007_plan_limits.sql:44` · `api/admin/abonelik.php:26-55` · `api/functions/plans.php:74-76` | `plans` tablosundaki üç kota sütunu (`independent_bot_limit`, `public_bot_limit`, `daily_message_limit`) **admin panelinin plan formunda yok**. Panelden açılan her yeni plan bu sütunları DB varsayılanlarıyla, yani **ücretsiz plan kotalarıyla** alıyor. | Migration 007 satır 44: `ADD COLUMN independent_bot_limit INT NOT NULL DEFAULT 1, ADD COLUMN public_bot_limit INT NOT NULL DEFAULT 2, ADD COLUMN daily_message_limit INT NOT NULL DEFAULT 10`. `abonelik.php`'nin formu yalnızca `name_tr, name_en, monthly_price, yearly_price, currency, description_tr, description_en` gönderiyor (satır 26-55; `saveOrUpdateBtn` satır 189-192 tüm `dataForm.elements`'i topluyor, kota alanı hiç yok) → `ajax/create.php` → `INSERT INTO plans (...)`. Kotalar `chatbot_limits.php:22-27` → `plans.php::getUserPlan()` üzerinden **canlı olarak** uygulanıyor, ve `WalletController::getPricing()` bunları `limits` alanında istemciye gösteriyor. | Admin panelden "Platin — ₺999/ay" planı açar; o planı satın alan kullanıcı **1 bağımsız bot, 2 herkese açık bot, günde 10 mesaj** alır — yani ücretsiz plan kotaları. Fiyatlandırma kartında da bu kotalar yazar. Ve **panelden düzeltmenin hiçbir yolu yok**, çünkü sütunlar hiçbir admin ekranında görünmüyor. Migration 007'nin tohumladığı dört plan doğru değerleri taşıyor; sorun yalnızca panelden **sonradan eklenen** planlarda. | `abonelik.php`'nin formuna üç sayısal alan ekle (`ajax/create.php`/`update.php` zaten `plans` tablosunu beyaz listesinde tutuyor, sunucu tarafında değişiklik gerekmiyor). | Hayır | S |
| **H-05** | Dead code | **P2** | `api/src/Domain/Interfaces/` (6 dosya, 95 satır) · `api/src/autoload.php:26-63` | Clean Architecture ağacının büyük kısmı iskelet: 8 `Domain` arayüzünden **6'sının ne implementasyonu ne de tek bir referansı var**, 9 katman dizini tamamen boş, autoloader'ın aradığı 31 dizinden 11'i hiç yok. | Tüm `api/src` içinde `implements` yalnızca iki yerde geçiyor: `ChatbotRepository:2` ve `UserRepository:2`. `Cart`/`Chat`/`Notification`/`Social`/`Subscription`/`WalletRepositoryInterface` adları kendi tanım dosyaları dışında **hiçbir dosyada geçmiyor**. Boş dizinler (0 dosya): `Application/UseCases/{Chatbot,User,Wallet}`, `Application/DTO`, `Application/Validators`, `Domain/Entities`, `Infrastructure/{Mail,FileStorage,Cache}`. `autoload.php`'nin listesindeki `Application/UseCases/{Marketplace,Social,Chat,Note,Content,Notification,Training,Message,Seller,Contact}` ve `Domain/Services` **dizin olarak da yok**. 17 use-case dizininden yalnızca `Auth` dolu (3 dosya); 14 controller kendi SQL'ini yazıyor. | Arayüzler yürürlükte olmayan sözleşmeler tarif ediyor: `WalletRepositoryInterface::requestWithdrawal(int, float): int` imzasına güvenip yazılacak kod, `WalletController`'ın gerçekte yaptığıyla eşleşmez. `CartRepositoryInterface` var ama `CartController` yok (sepeti `MarketplaceController` yönetiyor). Ayrıca her autoload ıskası 31'e kadar `file_exists` çağrısı yapıyor, bunların ~20'si var olmayan/boş dizinlere. | Ya arayüzleri **sil** (bugün hiçbir şey kırılmaz — sıfır referans), ya da hangilerinin yol haritası olduğunu dosya başında yaz. `autoload.php`'nin dizin listesini var olanlarla sınırla. İki implement edilen arayüz (`Chatbot`, `User`) doğrulandı: **metot kümeleri eksiksiz eşleşiyor**, fatal error yok. | Hayır | S |
| **H-06** | Data integrity | **P2** | `api/src/Shared/Constants/AppConfig.php:28-31` · `api/admin/ajax/upload.php:47-54` · `updategv.php:57-63` · `ajax/seo.php:96-97,136-137,192-193` | Yükleme sınırları ve izinli MIME listesi `AppConfig`'de "tek doğruluk kaynağı" olarak duruyor ama **admin tarafındaki üç yükleme yolunun hiçbiri onu okumuyor**; her biri kendi kopyasını taşıyor. | `MAX_UPLOAD_SIZE_BYTES` ve `ALLOWED_IMAGE_MIMES`'i okuyan tek yer `ChatbotController:547,551`. Kopyalar: `upload.php:54` `5 * 1024 * 1024` + `:47-53` kendi MIME haritası (`application/pdf` **fazladan**), `updategv.php:63` `5 * 1024 * 1024` + `:57-62` kendi haritası, `seo.php:96,137` 500 KB / `:192` 50 KB + `:97,136` yalnız `image/png, image/jpeg`. `AppConfig.php:4`'ün kendi başlığı "Single source of truth for all magic numbers" diyor. | Bugün değerler çelişmiyor (5 MB'lar eşit), ama sınırı değiştirmek dört dosyaya dokunmayı gerektiriyor ve bunu zorlayan hiçbir mekanizma yok — CLAUDE.md'nin "sabitler üç yerde kopyalanmış" tuzağının dördüncü ve beşinci örneği. `webp`/`gif` `seo.php`'de sessizce reddediliyor, kullanıcıya sebebi söylenmiyor. | Üç admin ucunu `AppConfig::MAX_UPLOAD_SIZE_BYTES` / `ALLOWED_IMAGE_MIMES` okuyacak şekilde çevir (uçlar zaten `functions/db.php` üzerinden `AppConfig`'i yüklüyor). Farklı olması gereken sınırlar (favicon 50 KB) `AppConfig`'de ayrı sabit olsun. | Hayır | S |
| **H-07** | Error handling | **P3** | `api/src/Infrastructure/Database/BaseRepository.php:75-84` | `update()` `$data` ile `$whereParams`'ı `array_merge` ile birleştiriyor; string anahtarlarda **sonraki kazanır**, yani `$where` parametresi aynı adı taşıyorsa `SET` değeri sessizce ezilir. Docblock'un kendi örneği tam olarak bu tehlikeli deseni öğretiyor. | Satır 84: `$stmt->execute(array_merge($data, $whereParams));`. Satır 76-78'deki örnek: `self::update('table', ['name' => 'x'], 'id = :id', ['id' => 5])` — burada `$data`'ya bir `id` anahtarı eklenirse `SET id = :id` ile `WHERE id = :id` **aynı** placeholder'a bağlanır ve `$whereParams`'ın değeri kazanır. Bugün canlı bir kırılma yok: her gerçek çağıran `:_id` alt çizgi önekini kullanıyor (`UserRepository:62,106,110`, `ChatbotRepository:28,36,40,44`) — yani düzeltme uygulanmış ama **docblock güncellenmemiş**. | Bugün etkisi yok. Docblock'taki deseni izleyen bir sonraki çağıran sessiz veri bozulması üretir (yanlış satır güncellenmez, yanlış değer yazılır — hata da vermez). | Docblock örneğini `:_id` önekiyle güncelle; ek olarak `array_merge` öncesi anahtar çakışmasını `array_intersect_key` ile tespit edip `InvalidArgumentException` fırlat. | Hayır | XS |
| **H-08** | Data integrity | **P3** | `api/src/Application/UseCases/Auth/LoginUseCase.php:31-42` · `Infrastructure/Repositories/UserRepository.php:78-85,92-94` | "Beni hatırla" ile her giriş `user_tokens`'a **yeni bir satır ekliyor**; eski satırlar hiç temizlenmiyor. `clearRememberToken()` var ama giriş yolundan çağrılmıyor. | `LoginUseCase:37` `setRememberToken(...)` → `UserRepository:79` düz `self::insert(self::T_TOKENS, [...])`. Dosyada `clearRememberToken` (s.92-94) tanımlı ve `AuthController::logout`'tan çağrılıyor, girişten çağrılmıyor. Her token `AppConfig::REMEMBER_ME_DAYS = 30` gün yaşıyor. Süresi geçmiş satırları silen bir temizlik işi de yok (`findByRememberToken:33` yalnızca `expiry > NOW()` ile **filtreliyor**, silmiyor). | Aynı kullanıcının her "beni hatırla" girişi kalıcı bir satır bırakıyor; tablo sınırsız büyüyor ve kullanıcının eski cihazlarındaki oturumları 30 gün boyunca geçerli kalıyor — cihaz listesi/iptal ekranı olmadığı için kullanıcının bunu görmesi ya da kapatması mümkün değil. | Girişte aynı kullanıcının süresi geçmiş token'larını sil; ya da cihaz başına tek token politikası uygula. Süresi geçmiş satırlar için bir temizlik adımı ekle. | Hayır | S |
| **H-09** | Error handling | **P3** | `api/src/Application/UseCases/Auth/LoginUseCase.php:25` | Google ile açılmış (parolasız) bir hesap parola formundan denendiğinde `password_verify()`'a `null` geçiliyor. | Satır 25: `password_verify($password, $user['sifre'])`. `findByUsernameOrEmail:26` `sifre` sütununu seçiyor; Google hesaplarında bu sütun NULL (H-03, `GoogleLoginUseCase` `sifre` yazmıyor). PHP 8.1'de dahili fonksiyona `null` geçmek deprecated; sonuç `false` olduğu için **kimlik doğrulama kararı doğru**. | `display_errors` açık bir ortamda deprecation metni JSON gövdesine karışıp `res.json()`'ı bozar. Kapalıyken (varsayılan, `bootstrap.php`) yalnızca hata günlüğünü şişirir. | `$user['sifre'] ?? ''` ya da NULL ise doğrudan `AuthException` — kullanıcıya "bu hesap Google ile açılmış" demeden, aynı genel mesajla (kullanıcı sayımını önlemek için). | Hayır | XS |
| **H-10** | Documentation | **P3** | `api/src/Shared/Constants/AppConfig.php:16-17` | Yorum güncelliğini yitirmiş: "`chatbot_limits.php` only ever applies the FREE_* limits". | `chatbot_limits.php:22-27` artık `getUserPlan($db, $userId)['independent_bot_limit']` okuyor, yani limit **`plans` tablosundan** geliyor; `FREE_*` yalnızca migration 007 uygulanmamışsa `plans.php::fallbackPlan()` üzerinden devreye giriyor. Yorumun ikinci cümlesi ("nothing consults these when a producer plan is active") ise hâlâ doğru — `producer_plan.php:13` bunu teyit ediyor. | Sabiti okuyan biri limitlerin kodda sabit olduğunu sanır; oysa canlı davranış DB satırına bağlı (bkz. H-04). | Yorumu iki cümleye ayır: `FREE_*` bir **fallback**, `PRODUCER_*` gerçekten ölü. | Hayır | XS |

---

## Doğrulanan ama bulgu OLMAYAN noktalar (H turu)

- **Google giriş akışının e-posta doğrulaması yerinde.** `GoogleLoginUseCase::execute()` imzasında `email_verified` yok, yani güvenlik tamamen çağırana bağlı — ve çağıran bunu yapıyor: `AuthController:160-161` `$payload['email_verified']`'ı okuyup satır 153-159'daki yorumda neden şart olduğunu açıklıyor (`email_verified: false` token'larla hesap ele geçirme). `verifyIdToken` istisnası da ele alınmış (s.131-137).
- **Giriş ve kayıt rate limit'li.** `AuthController:22-23` (`login:` hesap+IP 8/5dk, `login-ip:` 30/5dk), `:59` (`register:` 5/10dk), `:205` ve `:309` (parola sıfırlama). Use case'lerin kendisinde limit yok ama tek çağıran yolları korumalı.
- **Kullanıcı sayımı (enumeration) kapalı.** `LoginUseCase:24-26` bilinmeyen kimlik ile yanlış parolayı aynı mesaj ve aynı `AuthException` (401) ile döndürüyor; gerekçe satır 18-23'te yazılı.
- **`RegisterUseCase`'in yarış durumu ele alınmış.** `existsByUsernameOrEmail` + `create` iki gidiş-dönüş, ama satır 46-53 `Duplicate entry`'yi yakalayıp aynı 409'a çeviriyor; DB'deki UNIQUE kısıtı gerçek koruma. (H-02 bu düzeltmenin kardeş dosyada eksik olması.)
- **`BaseRepository`'de SQL enjeksiyonu yok.** `one/all/scalar` tamamen parametreli; `insert/update` sütun adlarını `assertSafeColumns` → `InputSanitizer::isSafeIdentifier` gramerinden geçiriyor; tablo adları yalnızca `AppConfig::TABLE_*` sabitlerinden geliyor; `inClause()` placeholder üretiyor. (H-01 bir **yetkilendirme/beyaz liste** eksiği, enjeksiyon değil.)
- **İmplement edilen iki arayüz eksiksiz.** `UserRepositoryInterface`'in 15 metodunun ve `ChatbotRepositoryInterface`'in 17 metodunun tamamı ilgili sınıflarda mevcut — hiçbirinde "abstract method not implemented" fatal error'ı yok. `ChatbotRepositoryInterface:19-23` kaldırılan `findBySlug()`'ı neden kaldırdığını da açıklıyor.
- **`JsonResponse` Content-Type gönderiyor.** Sınıfın kendisi başlık yazmıyor ama `autoload.php:10` → `functions/bootstrap.php:10` `header('Content-Type: application/json')` ile global olarak ayarlıyor; her API girişi `autoload.php` üzerinden geçiyor. Ayrıca `success()`/`error()` her ikisi de `exit` ediyor, yani "yanıttan sonra çalışan kod" sınıfı bir hata yok.
- **`AppException` hiyerarşisi ve autoload etkileşimi doğru.** Sekiz sınıf tek dosyada tanımlı ve autoloader dosya-adı-başına-tek-sınıf çalıştığı için `autoload.php:20` dosyayı koşulsuz yüklüyor; gerekçe satır 14-19'da yazılı. `JsonResponse::codeForException` sekiz alt sınıfın yedisini eşliyor, kalan her şey `ERR_SERVER`'a düşüyor.
- **`AppConfig::googleGeminiApiKey()`** `api/admin/.env`'i doğru yoldan okuyor (`__DIR__ . '/../../../admin/.env'` → `api/admin/.env`) ve `$_ENV` önceliği doğru. Not: bu dosya G-07'nin allowlist'siz yazdığı dosya — iki bulgu aynı dosyada buluşuyor.
- **Fiyat/komisyon/coin sabitleri hâlâ senkron.** Ana denetimin tablosundaki 8 satırın tamamı yeniden doğrulandı; uyuşmazlık yok.

---

## Kalan boşluklar (H turu sonrası)

`api/src/` artık tamamen okundu (35/35). Aşağıdakiler açık:

1. **`api/functions/` tam olarak okunmadı.** Dizinde 16 PHP dosyası var (ana denetimin "19 dosya" ifadesiyle uyuşmuyor — alt dizin yok, sayım fazla). Bu turda yalnızca beşine bakıldı: `bootstrap.php` (kısmen), `plans.php`, `chatbot_limits.php`, `producer_plan.php`, `coin_engine.php` (kısmen). `ParamPosMarketplace.php`, `checkout_payments.php`, `smtp_client.php`, `phpmailer.php`, `minify.php`, `validators.php`, `rate_limit.php`, `logging.php`, `env.php`, `db.php`, `util.php` doğrulanmadı — H-01/H-02'nin güvendiği `checkRateLimit()` davranışı da bunlardan birinde. **Bir sonraki turun doğal kapsamı bu.**
2. **`api/api/**` (123 endpoint) bu turda yeniden okunmadı.** H-01 için gereken çağıran araması yapıldı ve kapatıldı: `UserRepository::create()`'in `api/src` + `api/api` genelinde yalnızca iki çağıranı var (iki Auth use case'i). Ama başka repository'lerin ham `$data` alıp almadığı taranmadı.
3. **Hiçbir bulgu çalışan sisteme karşı doğrulanmadı.** H-01'in `google_id` zinciri koddan kesin çıkıyor ama ön koşulu (kurbanın Google `sub`'ının bilinmesi) saha koşuluna bağlı — sömürülebilirliğin pratik değeri ölçülmedi. H-04'ün etkisi "panelden yeni plan eklendi mi?" sorusuna bağlı; canlı `plans` tablosunun içeriği görülmedi.
4. **Migration'ların canlıda uygulanmış olup olmadığı bilinmiyor** (ana denetimin #1'i hâlâ geçerli). H-04 doğrudan "migration 007 uygulandı mı?" sorusuna bağlı: uygulanmadıysa `fallbackPlan()` devreye girer ve panelden eklenen plan zaten hiçbir şey yapmaz.
5. **`web/src/shared/lib/pricing.js` dışındaki frontend sabitleri taranmadı.** E tablosu yalnızca `pricing.js`'i kapsıyor; başka bileşenlerde kalmış kopyalar (ör. yükleme boyutu doğrulaması) aranmadı.
6. **Performans ölçülmedi.** H-05'teki autoload `file_exists` taraması ve `plans.php::getUserPlan()`'ın istek-içi `static` cache'i dışında sorgu sayısı ölçülmedi.

---

## Karar vermem gereken belirsizlikler (H turu)

1. **Boş katman dizinleri ve 6 arayüz silinsin mi? (H-05)** Sıfır referansları var, bugün silmek hiçbir şeyi kırmaz. Ama `Domain/Entities`, `Application/DTO` gibi dizinler bilinçli bir yol haritası olabilir. **Soru:** bu iskelet (a) terk edilmiş bir refactor kalıntısı mı, (b) devam eden bir planın parçası mı? Kendim silmedim.

2. **Google ile açılan hesabın kullanıcı adı ne olmalı? (H-03)** Üç makul seçenek var ve hangisinin doğru olduğu ürün kararı: (a) e-postanın yerel kısmından türet (klasik kayıt yolunun yaptığı), (b) ilk girişte kullanıcıya seçtir, (c) kullanıcı adı opsiyonel kalsın ve arayüz her yerde `ad_soyad`'a düşsün. (c) seçilirse bu bir bulgu değil, arayüz hatası olur. **Mevcut davranışın doğru mu yanlış mı olduğu belirsiz olduğu için test yazmadım.**

3. **Panelden eklenen bir planın kotaları ne olmalı? (H-04)** Formu üç alanla genişletmek teknik çözüm, ama varsayılanların ücretsiz plan değerleri olması bilinçli bir "fail-safe" olabilir (yanlış yapılandırılmış plan bedava kota dağıtmasın). **Soru:** yeni plan kotaları (a) admin tarafından zorunlu girilmeli mi, (b) mevcut bir plandan kopyalanmalı mı, (c) bugünkü gibi 1/2/10'da mı kalmalı? (c) ise bu bir bulgu değil, eksik olan yalnızca panelde bir uyarı metni.

---

# Ek tur — `api/functions/` kapsam kapatma (I serisi)

Tarih: 2026-09-02 · Yöntem: salt-okunur. "Kalan boşluklar (H turu sonrası)" #1'i kapatıyor.

## Bu turda okunanlar

`api/functions/` altındaki **16 PHP dosyasının tamamı** (alt dizin yok). `php -l` 16'sında da temiz.

Öncelik sırası kullanıcının verdiği gibi işlendi: `rate_limit.php` → `checkout_payments.php` → `ParamPosMarketplace.php` → `coin_engine.php` → kalan 12.

Doğrulama için ayrıca bakılanlar: `MarketplaceController::createSubscription` (idempotency + transaction sınırları), `SellerController::refund/reconcile` (yetki), `ChatController::generateReply` (coin tüketim/iade akışı), `AuthController` (oturum yenileme), `admin/ajax/smtp.php`, `migrations/007`, `web/src` (idempotency anahtarı gönderiliyor mu).

## Öncelikli dört soruya doğrudan cevap

| Soru | Cevap |
|---|---|
| **`checkRateLimit()` atomik mi?** (H-01/H-02 buna güveniyor) | **Evet, sayaç atomik.** `rate_limit.php:52-59` tek bir `INSERT … ON DUPLICATE KEY UPDATE` ile hem pencere devrini hem artırmayı yapıyor; `attempts` ve `window_start` aynı ifadede `IF(window_start < NOW() - INTERVAL ? SECOND, …)` ile karara bağlanıyor. Okuma-sonra-yazma yarışı yapısal olarak yok. Satır 61'deki ayrı `SELECT` bir yarışa açık ama **yalnızca fazla sayma yönünde**: iki eşzamanlı istek de daha yüksek değeri okur, ikisi de reddedilir. Kendi artışından düşük bir değer okumak mümkün değil. **H-01 ve H-02'nin bu davranışa güvenmesi yerinde.** |
| **Pencere sınırında ne oluyor?** | Sabit pencere (fixed window), yani sınırın klasik zaafı var: 5 dakikalık pencerede 8 hakkı olan giriş limiti, pencere sınırının iki yanına yayılan **16 denemeye** birkaç saniye içinde izin verir. Ayrıca satır 52-61 arasında başka bir istek pencereyi devrettirirse bu istek sıfırlanmış sayacı okur ve **tam bir istek** sızar. İkisi de I-09. |
| **Store bozulduğunda fail-open mı fail-closed mı?** | **Fail-closed.** `ensureTable`, `execute` ve `selectSingle` DB hatasında istisna fırlatıyor, `rateLimitHit` bunları yakalamıyor → istisna çağırana çıkıyor. JSON uçlarında `bootstrap.php:43`'teki global handler 500 üretiyor; istek **geçmiyor**. Tek fail-open dal satır 62: `$row` boş dönerse `attempts = 1` varsayılıyor (yalnızca araya `rateLimitReset()` girerse olur, zararsız). **Ancak** satır 22'deki "Never throws" iddiası yanlış ve admin paneli tam olarak o iddiaya güveniyor → I-08. |
| **`db_backup`/checkout idempotency var mı?** | Checkout'ta **var ve iyi kurulmuş**: `MarketplaceController:249-283` `GET_LOCK(checkout_user_<id>, 10)` + `checkout_idempotency` tablosu. İade tarafında **hiç yok** → I-02. Ve checkout idempotency'sinin kendisi başarı durumunda hiç temizlenmiyor → I-01. |

## D-02'nin tam contract'ı (`ParamPosMarketplace.php`)

Stub'ın altı metodu ve gerçek dönüş değerleri:

| Metot | Dönüş | Çağıran | Sonuç |
|---|---|---|---|
| `addSubMerchant(array)` | `success:false` **her zaman** | `SellerController:84` | Satıcı kaydı imkânsız (D-02'nin çekirdeği) |
| `listSubMerchants()` | `success:**true**, items: []` | `SellerController:171` | **Tek başarı dönen metot.** Çağıran "satıcı listesi boş" cevabını *başarılı* olarak görüyor — hata değil, "hiç satıcı yok" olarak yorumlanıyor |
| `updateSubMerchant(array)` | `success:false` | `SellerController:181` | Satıcı bilgisi güncellenemiyor |
| `deleteSubMerchant(array)` | `success:false` | `SellerController:192` | Satıcı silinemiyor |
| `listIller()` | `success:false, items: []` | `SellerController:364` | **Satıcı kayıt formundaki il açılır listesi boş** |
| `listIlceler(int)` | `success:false, items: []` | `SellerController:385` | **İlçe açılır listesi boş** |

**D-02'ye eklenecek nokta:** `addSubMerchant` düzeltilse bile satıcı kayıt formu **doldurulamaz**, çünkü il/ilçe listeleri de aynı stub'dan geliyor ve ikisi de `success:false` dönüyor. D-02'nin kilidi tek metot değil, üç metot. Ayrıca sınıfta `getSubMerchant`, ödeme/transfer ya da KYC durumu sorgulayan bir metot **hiç yok** — gerçek entegrasyon geldiğinde contract'ın genişlemesi gerekecek. Kişisel veri redaksiyonu (`redact()`, s.27-49) doğru çalışıyor, DEP-002 kapalı.

## E kategorisi — `coin_engine.php` ↔ `AppConfig` ↔ `pricing.js` (yeniden doğrulandı)

| Sabit / formül | `AppConfig.php` | `coin_engine.php` | `pricing.js` | Durum |
|---|---|---|---|---|
| Coin kademesi — taban | — | `COIN_TIER_BASE = 150` (s.168) | `COIN_TIER_BASE = 150` (s.30) | ✅ |
| Coin kademesi — adım | — | `COIN_TIER_STEP = 100` (s.169) | `COIN_TIER_STEP = 100` (s.31) | ✅ |
| Coin kademesi — tavan | — | `COIN_TIER_CAP = 1000` (s.170) | `COIN_TIER_CAP = 1000` (s.32) | ✅ |
| Kademe **formülü** | — | `if (<100) 0; tier = floor(paid/100); min(CAP, BASE + (tier-1)*STEP)` (s.172-176) | Aynısı (s.39-43) | ✅ İki uygulama satır satır eşdeğer. JS'teki fazladan `!totalPaid` kontrolü davranışı değiştirmiyor (0 zaten <100). |
| Günlük mesaj hakkı | `DAILY_FREE_MESSAGES = 10` (s.22) | Sabiti **okumuyor** — `getDailyMessageLimit()` → `plans.php` → `plans` tablosu (s.48-49) | — | ⚠️ `AppConfig` yalnızca `fallbackPlan()` yolunda devreye giriyor. Canlı değer DB satırında; bkz. H-04 ve I-03. |

**Yeni uyuşmazlık yok.** Ancak kota tarafındaki tek doğruluk kaynağı artık `plans` tablosu ve o tablo admin panelinden düzenlenebilir — I-03 bunun sessiz kırılmasını gösteriyor.

---

## Bulgu tablosu (I serisi)

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi | Önerilen çözüm | Blocker? | Efor |
|---|---|---|---|---|---|---|---|---|---|
| **I-01** | Broken functionality | **P1** | `api/src/Presentation/Controllers/MarketplaceController.php:226-238,262-265,576-580` | Checkout idempotency satırı **başarı durumunda hiç silinmiyor ve süresi dolmuyor**. Aynı sepet bir daha satın alınamıyor. | Anahtar sepet parmak izinden türetiliyor: satır 232-236 `chatbot_id:duration_weeks` çiftlerini sıralayıp `cart:` öneki ile birleştiriyor, satır 238 `hash('sha256', userId + '|' + idemRaw)`. Kontrol sorgusu (s.262-265) **zaman filtresi taşımıyor**. Başarısızlık dallarında satır siliniyor (s.304, s.566 — yorum s.564 kalıcı bloklanma riskini zaten biliyor), **başarı dalında ise** satır 578 yalnızca `order_id`'yi yazıyor, satır kalıcı oluyor. İstemci kendi anahtarını gönderebiliyor (s.230) ama `web/src` genelinde `idempotency_key` **hiç geçmiyor** (arama sonucu boş), yani parmak izi yolu her zaman devrede. | Kullanıcı 42 numaralı botu 1 haftalığına aldıktan sonra **aynı botu aynı süreyle bir daha alamıyor**: istek `repeated: true` ve eski `order_id` ile "Bu sipariş zaten oluşturulmuş." dönüyor, yeni abonelik/kredi yazılmıyor, para da çekilmiyor. Yani abonelik yenileme yine kırık — `coin_engine.php:184-189`'un "abonelik yenileme hiç mümkün değildi, düzeltildi" notu bu katman yüzünden pratikte hâlâ geçerli. Farklı süre seçilirse (2 hafta) anahtar değiştiği için çalışıyor, bu da hatayı gözden kaçırtıyor. | Kontrol sorgusuna kısa bir pencere ekle (`AND created_at > NOW() - INTERVAL 10 MINUTE`) — idempotency'nin amacı çift tıklamayı yakalamak, kalıcı bir "satın alınmış" kaydı tutmak değil. Süresi geçmiş satırlar için `rate_limit.php:34-44`'teki fırsatçı temizliğin aynısı kullanılabilir. | Hayır | S |
| **I-02** | Race conditions | **P2** | `api/functions/checkout_payments.php:487-614` (özellikle 512-521, 549-556) | `processRefund()` **transaction, kilit ve idempotency olmadan** çalışıyor. `PDO $conn` parametresini alıyor ve gövdede **hiç kullanmıyor**. | İmza satır 487: `processRefund(Database $db, PDO $conn, array $data)`. Gövdenin tamamında `$conn` yalnızca imzada geçiyor (dosya içi arama: tek eşleşme). "Zaten iade edilmiş" kontrolü (s.512-518) klasik check-then-act: `SELECT` ile durum okunuyor, `FOR UPDATE` yok, aradaki pencerede ikinci istek aynı `paid` durumunu görüyor. Döngü (s.549-556) her `paymentTransactionId` için sağlayıcıya `refund` çağrısı atıyor ve sonucu `param_marketplace_refunds`'a yazıyor. Karşılaştırma: aynı depodaki checkout yolu (`MarketplaceController:249-283`) `GET_LOCK` + idempotency tablosu kullanıyor — desen biliniyor, iade tarafına uygulanmamış. | İki eşzamanlı iade isteği (admin panelinde çift tıklama, ya da G-01'in XSS'i) aynı ödeme için sağlayıcıya **iki kez** iade çağrısı atar. iyzico ikinciyi büyük olasılıkla reddeder (o zaman `failed` durumlu çöp `param_marketplace_refunds` satırları kalır), ama bu sağlayıcının davranışına bırakılmış bir garanti — kod tarafında hiçbir koruma yok. Kısmi iadelerde tutarların üst üste binmesi mümkün. Yetki tarafı temiz: uç nokta `AuthMiddleware::requireAdmin()` arkasında (`SellerController:267-275`), yani IDOR **yok**. | `processRefund`'ı `$conn->beginTransaction()` içine al ve ödeme satırını `SELECT … FOR UPDATE` ile kilitle; ya da checkout'taki `GET_LOCK` desenini `refund_payment_<id>` adıyla tekrarla. Ödeme yollarını etkilediği için **uygulanmadı, diff olarak önerilir** (CLAUDE.md: "Önce raporla, onay bekle"). | Hayır | M |
| **I-03** | Data integrity | **P1** | `api/functions/plans.php:100-111` · `api/admin/abonelik.php:26-33` · `migrations/007_plan_limits.sql:56-58` | Kullanıcı ↔ plan bağı **serbest metin isim eşleşmesi** üzerinden kuruluyor. Admin panelinden bir planın adı değiştirilirse o plandaki **tüm kullanıcılar sessizce varsayılan (ücretsiz) plana düşüyor**. | `plans.php:102-107`: `SELECT p.* FROM user_plan_selection ups JOIN plans p ON p.name_tr = ups.plan_name WHERE ups.user_id = ?`. Yorum satır 100-101 durumu kabul ediyor: "`user_plan_selection.plan_name` serbest metin ve plana FK'sı yok". Eşleşme başarısız olursa satır 110-111 `is_default = 1` satırına düşüyor, o da migration 007'nin tohumladığı **Ücretsiz** plan. Admin paneli `name_tr`'yi düzenlenebilir bir alan olarak sunuyor (`abonelik.php:28` "Plan Adı (TR)") ve `ajax/update.php` `plans` tablosunu beyaz listesinde tutuyor, yani yeniden adlandırma başarılı oluyor (007 `name_tr`'ye UNIQUE koydu, çakışma da olmuyor). Hiçbir yerde uyarı yok. | Admin "Elmas" planının adını "Elmas Plus" yapar → o planı satın almış **her kullanıcı** bir sonraki istekte 1 bağımsız bot / 2 herkese açık bot / 10 mesaj kotasına düşer. Kullanıcı ödeme yapmış, hakkı kaybolmuş; hiçbir hata da üretilmiyor, `getUserPlan()` sessizce fallback dönüyor. Geri alma tek yol: adı harfi harfine eski hâline döndürmek. Aynı kırılganlık plan **silmede** de var. | `user_plan_selection`'a `plan_id` sütunu ekleyip FK ile bağla (isim yalnızca gösterim olsun). Kısa vadede: admin plan formunda `name_tr` alanını salt-okunur yap ya da yeniden adlandırmada `user_plan_selection`'ı da güncelle. **Şema değişikliği içeriyor → uygulanmadı** (CLAUDE.md). | Hayır | M |
| **I-04** | Payments | **P2** | `api/functions/checkout_payments.php:543-546,556,586-604` | İki ayrı muhasebe hatası: (a) kalem bazlı iade satırlarının hepsi **ilk** detay satırına bağlanıyor, (b) **kısmi iadede satıcı payları hiç dokunulmadan `approved` kalıyor**. | (a) Satır 543-546 döngüden **önce** `firstDetailId`'yi bir kez çekiyor (`ORDER BY id LIMIT 1`), satır 556 her kalem için `'detail_id' => $firstDetailId` yazıyor. Kalem ile detay satırını eşleştiren hiçbir mantık yok — satır 555'teki "eşleşme yoksa 0" yorumu gerçekleşmeyen bir eşleştirmeyi ima ediyor. (b) Satır 586-604: `param_marketplace_payments.status` `refunded`/`partial_refund` olarak güncelleniyor, ama `param_marketplace_details` güncellemesi **yalnızca `if ($fullyRefund)`** bloğunun içinde (s.594-601). Kısmi iadede hiçbir detay satırı `refunded` olmuyor. | (a) Üç botluk bir sepetin iadesinde üç iade satırı da 1. botun satıcısına bağlanıyor → satıcı bazlı iade raporu yanlış. (b) Bir kalemi iade edilen siparişte **o kalemin satıcısı parasını almaya devam ediyor**: `param_marketplace_details.status` `approved` kalıyor, `WalletController` bunu ödenebilir bakiye olarak görüyor. Platform iade ettiği parayı satıcıya da ödüyor. Bu, D-03'ün "iade `user_subscriptions`/`chatbot_purchase_credits`'e dokunmuyor" tespitinin **üçüncü ayağı**. | `itemTransactions[].itemId`'yi `param_marketplace_details.chatbot_id` ile eşleştirip doğru `detail_id`'yi yaz; kısmi iadede yalnızca iade edilen kaleme ait detay satırını `refunded` yap. **Ödeme yolu → uygulanmadı, diff olarak önerilir.** | Hayır | M |
| **I-05** | Security | **P2** | `api/admin/ajax/smtp.php:22-27` · `api/functions/phpmailer.php:96-99` · `api/functions/smtp_client.php:68,86` | SMTP ayarları hiçbir doğrulamadan geçmeden saklanıyor ve gönderim yolunda **CR/LF temizliği olmadan** SMTP komutuna ve `From:` başlığına giriyor → SMTP komut/başlık enjeksiyonu. | `admin/ajax/smtp.php:22-27` dört alanı ham `$_POST` olarak `global_vars`'a yazıyor (biçim kontrolü yok). `phpmailer.php:96-99` `mailerConfig()` bunları yalnızca `trim()` ile okuyor. `smtp_client.php:68` `MAIL FROM:<' . $from['email'] . '>` ve satır 86 `'From: ' . encodeHeaderName(...) . ' <' . $from['email'] . '>'` — `$from['email']` hiçbir yerde `encodeHeaderValue()`'dan geçmiyor; o fonksiyon (s.252-255) CR/LF'i **yalnızca** `Subject` ve görünen ad için temizliyor. Alıcı tarafı temiz: `phpmailer.php:35` `FILTER_VALIDATE_EMAIL` CR/LF'i reddediyor. Satır 87 `'To: ' . implode(', ', $to)` de kaçırılmıyor ama girdisi doğrulanmış. | `smtp_email` alanına `x@y.com\r\nRCPT TO:<kurban@...>` yazan bir admin, sitenin SMTP kimlik bilgileriyle keyfi alıcıya posta gönderebilir; `From:` başlığına satır ekleyerek `Bcc`/`Reply-To` uydurabilir. Tek başına admin yetkisi gerektirdiği için P2 — ama **G-01 ile zincirlenince** sıradan bir kullanıcı platformu spam rölesine çevirebilir (ve bu, alan adının itibarını yakar). | `smtp.php`'de `smtp_email` için `FILTER_VALIDATE_EMAIL`, `smtp_host` için ana bilgisayar adı deseni zorunlu kıl; ayrıca `smtp_client.php`'de zarf adreslerini de CR/LF'ten arındır (savunma derinliği). | Hayır | S |
| **I-06** | Security | **P2** | `api/functions/phpmailer.php:98` · `api/admin/smtp.php:49-51` · `api/admin/ajax/smtp.php:25` | SMTP parolası veritabanında **düz metin** duruyor ve admin sayfası onu HTML'e geri basıyor. | `global_vars.smtp_pass` düz metin yazılıyor (`ajax/smtp.php:25`), düz metin okunuyor (`phpmailer.php:98`). `admin/smtp.php:49-51` değeri `<input type="password" … value="<?= htmlspecialchars($global_vars['smtp_pass'] ?? '') ?>">` ile sayfaya gömüyor — `type="password"` yalnızca ekranda gizliyor, HTML kaynağında ve DOM'da düz metin. | Bir DB dökümü (G tarafındaki `db_backup.php` ya da herhangi bir yedek) SMTP kimlik bilgisini de taşıyor. Admin panelinde bir XSS (G-01) `document.getElementById('smtp_pass').value` ile parolayı tek satırda okuyabiliyor. `read.php`'nin `global_vars`'ı beyaz listede olmadığı için oradan okunamıyor, bu yüzden tek sızıntı yüzeyi sayfanın kendisi. | Parolayı `global_vars` yerine `api/.env`'e taşı (`mailerConfig()` zaten `SMTP_PASS`'i önce okuyor — s.98'deki `env_get('SMTP_PASS')` dalı hazır). Sayfada mevcut değeri **hiç basma**; boş bırakıldığında eskisini koru. | Hayır | S |
| **I-07** | Dead code | **P2** | `api/functions/validators.php` (41 satır) · `api/functions/minify.php` (8 satır) · `api/functions/db.php:465-473,97-110` | Dört ölü yardımcı; biri ayrıca bozuk. `validators.php` her API isteğinde yükleniyor. | Çağıran araması (`api/` geneli, `vendor/` hariç) yalnızca tanımları buldu: `require_fields`, `sanitize_string`, `sanitize_float`, `validate_positive_int`, `allowed_values`, `minify_html` → **sıfır çağıran**. `autoload.php:11` `validators.php`'yi koşulsuz `require_once` ediyor. `db.php:465-473` `truncate()` ve `db.php:97-110` `getParamTypes()` (private) de çağrılmıyor. **`truncate()` ayrıca çalışmaz durumda:** satır 466 `if ($this->conn->query($query) === TRUE)` — `PDO::query()` `PDOStatement` döndürür, asla `TRUE` değil, dolayısıyla **her zaman** else dalına düşer; o dal da PDO'da var olmayan `$this->conn->error` özelliğini okuyor. | Doğrudan kullanıcı etkisi yok. Ama `sanitize_string` (s.15-21) aktif bir tuzak: girdi anında `htmlspecialchars` uyguluyor (çıktı anında değil) ve `strlen`/`substr` ile **bayt** bazlı kırpıyor — Türkçe bir karakterin ortasından kesip geçersiz UTF-8 üretebilir, o da `json_encode`'u `false` döndürüp boş yanıt gövdesine yol açar. Bunu "hazır yardımcı" sanıp kullanacak bir sonraki geliştirici iki hatayı birden devralır. | `minify.php` ve `validators.php`'yi **silme adayı** olarak işaretle (`autoload.php:11`'deki require ile birlikte). Saklanacaksa `sanitize_string`'i `mb_substr` + çıktı-anında kaçış olacak şekilde düzelt. `truncate()`/`getParamTypes()` silinmeli — CLAUDE.md zaten TRUNCATE'i yasaklıyor. | Hayır | S |
| **I-08** | Error handling | **P3** | `api/functions/rate_limit.php:20-24` · `api/admin/functions/admin_login.php:129-137` | `rateLimitHit()`'in docblock'u "Never throws, never writes a response" diyor; fonksiyon **üç yerden** istisna fırlatabiliyor ve admin girişi tam olarak o iddiaya güvenerek onu `checkRateLimit` yerine doğrudan çağırıyor. | Satır 22 iddiayı yapıyor. Fırlatabilen çağrılar: `ensureTable` (s.26), `execute` (s.52), `selectSingle` (s.61) — üçü de `Database` üzerinden ve `PDO::ERRMODE_EXCEPTION` etkin (`db.php:71`). Yalnızca temizlik bloğu (s.34-44) ve `rateLimitReset` (s.84-89) `try/catch` içinde. `admin_login.php:129-130` `if (!rateLimitHit(...))` diye çağırıyor; `admin/ajax/giris.php` bir `try` taşımıyor. | DB kısa süreli erişilemez olduğunda admin girişi düzgün bir hata yerine yakalanmamış istisnaya düşüyor (fail-**closed**, yani güvenlik açığı değil — ama HTML render eden panel için docblock'un vaat ettiği "kendi işleme" imkânı yok). Yanlış yorum, bir sonraki çağıranı da yanıltır. | Ya gövdeyi `try/catch` ile sarıp `false` döndür (fail-closed'ı koru), ya da docblock'tan "Never throws" iddiasını kaldır. | Hayır | XS |
| **I-09** | Security | **P3** | `api/functions/rate_limit.php:52-64` | Sabit pencere (fixed window) sayacı: pencere sınırının iki yanında limitin iki katına izin veriyor. Ayrıca artış ile okuma arasındaki ayrı `SELECT` sınırda bir isteğin sızmasına izin veriyor. | Satır 56-57 pencereyi `window_start` tek bir zaman damgasıyla yönetiyor (kayan pencere değil). `AuthController:22` girişte 8/300 sn tanımlıyor → t=299'da 8, t=301'de 8 daha, toplam **16 deneme birkaç saniyede**. İkinci nokta: satır 52-59 artışı yapıyor, satır 61 ayrı bir `SELECT` ile okuyor; arada başka bir istek pencereyi devrettirirse (s.56 `IF(...)` dalı) bu istek `attempts = 1` okur ve `1 <= 8` ile geçer. | Parola deneme hızını pratikte ikiye katlıyor. Mutlak sayılar küçük olduğu için (16/5dk) sömürü değeri sınırlı; yine de belgelenen limit gerçek limit değil. | Kayan pencere (iki sayaç ya da zaman damgası listesi) ya da artış ifadesinin kendisinden `ROW_COUNT()`/`LAST_INSERT_ID(attempts)` ile değeri geri okuyup ayrı `SELECT`'i kaldırmak. | Hayır | S |
| **I-10** | Data integrity | **P3** | `api/functions/coin_engine.php:234-240` | Günlük havuza iade **tavansız**: `coins_remaining = coins_remaining + 1`, üst sınır kontrolü yok. Kardeş dal (satın alma kredisi, s.225) `LEAST(credits_total, credits_remaining + 1)` ile sınırlıyor. | Satır 225: `SET credits_remaining = LEAST(credits_total, credits_remaining + 1)`. Satır 236: `SET coins_remaining = coins_remaining + 1, exhausted_at = NULL` — `LEAST(daily_limit, …)` yok. Satır 231'deki yorum, süresi dolmuş bir satın alma kredisinin iadesinin **günlük havuza** yazıldığını açıkça söylüyor, yani iki havuz arasında tek yönlü bir akış var. | **Sömürü senaryosu kurulamadı** — `refundMessage` yalnızca başarılı bir `consumeMessage`'ın ardından ve yalnızca upstream hatasında çağrılıyor (`ChatController:319` ve `:400` birbirini dışlıyor, ikisi de `exit` ile bitiyor), yani tüketim/iade dengeli. Bu bir **asimetri ve latent tavan eksikliği** olarak raporlanıyor, kanıtlanmış bir açık olarak değil. | Günlük dala da tavan ekle: `LEAST(<plan limiti>, coins_remaining + 1)`. Plan limiti zaten `getDailyMessageLimit()` ile elde edilebiliyor. | Hayır | XS |
| **I-11** | Data integrity | **P3** | `api/functions/coin_engine.php:202` | `GREATEST(expires_at, VALUES(expires_at))` — MySQL'de argümanlardan biri `NULL` ise sonuç `NULL`. Tabloda `expires_at IS NULL` **desteklenen bir durum** ("süresiz"). | Satır 202 `expires_at = GREATEST(expires_at, VALUES(expires_at))`. `getActivePurchaseCredit` (s.16) `expires_at IS NULL OR expires_at > NOW()` yazıyor, yani NULL "hiç dolmaz" anlamına geliyor. Mevcut satırda `expires_at` NULL ise yeni satın alma sonrası da NULL kalır (kullanıcı lehine, satır zaten süresizdi) — ama davranış tesadüfi, `GREATEST`'in NULL yayılımından geliyor, bilinçli bir karardan değil. | Bugün zararsız: `grantPurchaseCredit`'in imzası `string $expiresAt` olduğu için NULL geçilemiyor ve tek çağıran (`MarketplaceController:412`) hesaplanmış bir tarih veriyor. İmza gevşetilirse ya da NULL'lı bir satır oluşursa süre sessizce "sonsuz"a dönüşür. | `COALESCE` ile açık ol: `expires_at = GREATEST(COALESCE(expires_at, VALUES(expires_at)), VALUES(expires_at))`, ya da NULL'ın ne demek olduğuna karar verip sütunu `NOT NULL` yap. | Hayır | XS |

---

## Mevcut bulguları etkileyen tespitler

**Aşağıdaki satırların hiçbiri AUDIT.md'de değiştirilmedi** — bu bölüm yalnızca yeni kanıtı kaydediyor.

| Mevcut ID | Durum | Ne değişti |
|---|---|---|
| **D-02** (P0) | **Doğrulandı, kapsamı genişledi** | Kilit tek metotta değil: `addSubMerchant` düzeltilse bile `listIller()` ve `listIlceler()` de `success:false` döndüğü için satıcı kayıt formundaki il/ilçe alanları doldurulamıyor. Yukarıdaki contract tablosuna bakın. Severity değişmiyor (zaten P0). |
| **D-03** (P1) | **Doğrulandı, üçüncü ayak eklendi** | `processRefund()` tam okundu: dosyanın tamamında `user_subscriptions` ve `chatbot_purchase_credits` **hiç geçmiyor** — D-03'ün iddiası birebir doğru. Ek olarak I-04(b): kısmi iadede `param_marketplace_details` de hiç güncellenmiyor, yani iade edilen kalemin satıcısı ödenmeye devam ediyor. |
| **D-04** (P1) | **Doğrulandı, mekanizma keskinleşti** | `createSubscription` `orderId`'yi tahsilattan **önce** üretiyor (s.434) ve kod yorumu (s.428-433) bunun sebebini açıkça "mutabakat zaman aşımına uğramış tahsilatı sağlayıcıda YALNIZCA bu kimlikle bulabilir" diye yazıyor. Ama tahsilat başarısız/zaman aşımı olduğunda satır 460-461 `rollBack()` yapıyor ve `param_marketplace_payments` INSERT'i (s.520) hiç çalışmıyor — yani o kimlik **hiçbir yere yazılmıyor**, yalnızca error_log'da kalıyor. `reconcilePayments` sorgusu (s.393-400) yalnızca o tabloyu tarıyor. Kodun kendi yorumu ile davranışı çelişiyor. Severity P1'de kalıyor; öneri "ödeme satırını tahsilattan ÖNCE `pending` olarak yaz" olmalı. |
| **H-01 / H-02** | **Dayandıkları varsayım doğrulandı** | `checkRateLimit()` gerçekten atomik ve fail-closed (yukarıdaki soru-cevap tablosu). H-01'in "kayıt uç noktası IP başına 5/10dk sınırlı" ve H-02'nin "eşzamanlı istek 500 üretir" iddiaları geçerli. **Severity değişmiyor.** |
| **PAY-003** (coin_engine.php:179-189'da anılan) | **Pratikte hâlâ açık** | O düzeltme `grantPurchaseCredit`'i `ON DUPLICATE KEY UPDATE`'e çevirerek "abonelik yenileme hiç mümkün değildi" sorununu DB katmanında çözmüş. Ama I-01 yüzünden aynı sepet checkout katmanında zaten reddediliyor, yani yenileme akışı `grantPurchaseCredit`'e **hiç ulaşmıyor**. Düzeltme doğru, önündeki kapı kapalı. |

---

## Doğrulanan ama bulgu OLMAYAN noktalar (I turu)

- **Coin kotası negatife düşemez.** Her iki tüketim dalı da `UPDATE … SET x = x - 1 WHERE … AND x > 0` + `rowCount()` kontrolü kullanıyor (`coin_engine.php:130-141`, `146-155`). Okuma-sonra-yazma yok. Günlük sıfırlama da tek atomik `UPDATE` (s.91-102), koşul `WHERE`'de, iki eşzamanlı istekten yalnızca biri sıfırlayabiliyor. İlk satır oluşturmada `Duplicate entry` yakalanıp satır yeniden okunuyor (s.64-82). **Kullanıcının "kota tüketimi atomik mi, negatife düşebilir mi?" sorusunun cevabı: atomik, düşemez.**
- **`consumeMessage` çift iade üretmiyor.** `ChatController:319` (API anahtarı yok) ve `:400` (upstream hatası) birbirini dışlıyor; ilki `JsonResponse::error` ile, ikincisi `exit` ile bitiyor (s.411).
- **Checkout idempotency + kilit doğru kurulmuş.** `GET_LOCK` kilidi alındıktan **sonra** kontrol yapılıyor (`MarketplaceController:260-265`), yarışı kaybeden istek kazananın satırını görüyor. Başarısızlık dallarında rezervasyon bırakılıyor. Tek kusur I-01 (süre yokluğu).
- **İade ve mutabakat uçlarının yetkisi doğru.** `refund` → `AuthMiddleware::requireAdmin()` (`SellerController:269`); `reconcile` → `hash_equals` ile cron secret, secret tanımsızsa fail-closed 403 (s.255-260). IDOR yok.
- **Oturum sabitleme kapalı.** `session_regenerate_id(true)` dört giriş yolunun **dördünde** de yetki yükselmesinden önce çağrılıyor: `AuthController:35` (parola), `:185` (Google), `AuthMiddleware:90` (beni-hatırla), `admin_login.php:202` (admin).
- **DB katmanı sağlam.** `db.php:73` `PDO::ATTR_EMULATE_PREPARES => false` (gerçek hazır ifadeler), `:71` `ERRMODE_EXCEPTION`, yapılandırma eksikse bağlanmak yerine yüksek sesle hata (s.41-57 — SEC-008 kapalı, hard-coded kimlik bilgisi yok). `insert()`/`update()` sütun adlarını `assertSafeColumnName` gramerinden geçiriyor.
- **SMTP taşıma katmanı güvenli.** `verify_peer`, `verify_peer_name` açık, `allow_self_signed` kapalı (`smtp_client.php:94-98`); STARTTLS başarısızsa istisna (s.57-58); RFC 3207 gereği TLS sonrası EHLO tekrarlanıyor (s.61); nokta-doldurma yapılıyor (s.246); `Subject` ve görünen ad CR/LF'ten arındırılıyor (s.252-255); alıcı `FILTER_VALIDATE_EMAIL`'den geçiyor. Tek boşluk zarf adresi → I-05.
- **`phpmailer.php` fail-closed.** SMTP host tanımsızsa `success:false` dönüyor (s.46-54), DEP-003'ün fail-open stub'ı kapatılmış.
- **`logging.php`** log hedefini doküman kökünün dışına sabitliyor, dizin yoksa PHP varsayılanına dokunmadan sessizce vazgeçiyor (s.34-36) ve `APP_DEBUG` kapalıyken `display_errors`'ı kapatıyor.
- **`env.php`** gerçek ortam değişkenlerini asla ezmiyor (s.42), tek çift tırnak soyuyor, `env_get` boş değeri "tanımsız"dan ayırıyor (`DB_PASS=` senaryosu).
- **`bootstrap.php`** üç hata kancasını (exception / error / shutdown) DB bağlantısından **önce** kuruyor (ERR-004), uyarıları yanıt gövdesinden uzak tutuyor (s.106 `return true`), fatal'ı JSON'a çeviriyor ve `headers_sent()` ise SSE akışını bozmuyor (s.129-131). `APP_DEBUG` kapalıyken istisna mesajı sızmıyor.
- **`chatbot_limits.php`, `producer_plan.php`** kendi yorumlarında `PRODUCER_*_LIMIT`'in okunmadığını doğruluyor — D-07 ile tutarlı.
- **`php -l`** 16 dosyanın hepsinde temiz.

---

## Kalan boşluklar (I turu sonrası)

`api/functions/` artık tamamen okundu (16/16). Açık kalanlar:

1. **`api/api/**` (123 endpoint ince sarmalayıcı) hiçbir turda tek tek okunmadı.** Ana denetim bunları "okundu" sayıyor ama A kategorisi eşleştirmesi düzeyinde; her dosyanın `require_method`/yetki çağrısını taşıyıp taşımadığı dosya bazında doğrulanmadı. **Bir sonraki turun doğal kapsamı bu** (G ve H turlarının `api/admin` ve `api/src` için yaptığının karşılığı).
2. **`api/database/` (schema.sql + 9 migration) satır satır okunmadı.** Bu turda yalnızca `007_plan_limits.sql` ve `kullanicilar`/`chatbot_reports` tanımlarına bakıldı. I-03 ve H-04 doğrudan şema kararlarına bağlı.
3. **Hiçbir bulgu çalışan sisteme karşı doğrulanmadı.** I-01 koddan kesin çıkıyor (frontend'in `idempotency_key` göndermediği arama ile teyit edildi) ama canlı `checkout_idempotency` tablosunun içeriği görülmedi. I-03'ün etkisi "panelden plan adı değiştirildi mi?" sorusuna bağlı.
4. **`IyzicoClient` bu turda yeniden okunmadı** — ana denetim okumuş sayıyor. I-02/I-04'ün önerileri `refund()` ve `cancelPayment()`'ın idempotency davranışına bağlı; sağlayıcının aynı `paymentTransactionId` için ikinci iadeyi nasıl karşıladığı **doğrulanmadı** (anahtar yok, B3).
5. **`web/src` bileşen taraması hâlâ eksik** (ana denetimin #3'ü). I-01'in kullanıcıya nasıl göründüğü (checkout ekranı `repeated: true` yanıtını nasıl render ediyor?) incelenmedi.
6. **Yük/performans ölçülmedi.** `rate_limit.php:34-44`'teki temizlik `window_start` üzerinde indeks olmadan tam tarama yapıyor; `plans.php` ve `coin_engine.php` mesaj başına 4-6 sorgu atıyor. Veri hacmi ölçülemediği için bulgu yazılmadı.

---

## Karar vermem gereken belirsizlikler (I turu)

1. **Checkout idempotency penceresi ne olmalı? (I-01)** Amaç çift tıklamayı yakalamaksa birkaç dakika yeter; amaç "aynı botu iki kez satın alma" iş kuralıysa bu bir ürün kararı ve mevcut davranış kasıtlı olabilir. **Soru:** kullanıcı aynı botu aynı süreyle (a) istediği zaman tekrar satın alabilmeli mi (yenileme), (b) yalnızca mevcut aboneliği bittikten sonra mı, (c) hiç mi? (a) ise pencere; (b) ise abonelik bitiş tarihi kontrolü gerekiyor. **Belirsiz olduğu için test yazmadım.**

2. **Kısmi iadede hangi kalem iptal edilmeli? (I-04)** Bu, AUDIT.md'nin mevcut Belirsizlikler #3'ünün (D-03/D-08) teknik karşılığı. `itemTransactions[].itemId` ile `param_marketplace_details.chatbot_id` eşleştirmesi mümkün görünüyor ama `itemId`'nin gerçekte hangi değeri taşıdığı (`chatbot_id` mi, satır id'si mi) yalnızca canlı bir iyzico yanıtıyla doğrulanabilir. **Soru:** eşleştirme anahtarı ne olacak?

3. **`validators.php` ve `minify.php` silinsin mi? (I-07)** İkisi de sıfır çağıranlı. `validators.php` `autoload.php:11` üzerinden her istekte yükleniyor; silmek o satırı da gerektirir. **Soru:** bunlar (a) silinecek ölü kod mu, (b) ileride kullanılmak üzere bilerek duran bir yardımcı seti mi? Kendim silmedim.

---

## Bakamadığım yerler

1. **Çalışan bir veritabanına karşı hiçbir sorgu doğrulanmadı.** Şema iddialarının tamamı `schema.sql` + `migrations/` okumasına dayanıyor. Canlı veritabanı bu dosyalarla aynı durumda değilse (migration'lar kısmen uygulanmışsa) bazı bulguların etkisi değişir. Özellikle E-03 ve E-04 doğrudan "hangi migration uygulanmış?" sorusuna bağlı.
2. ~~**`api/admin/` altındaki 22 sayfa ve 17 ajax dosyası okunmadı**~~ — **KAPANDI (2026-09-02).** 49 dosyanın tamamı okundu; bkz. yukarıdaki “Ek tur — `api/admin/` kapsam kapatma (G serisi)”. Kalan boşluklar o bölümün sonunda listelendi (`vendor/`, `assets/js/*.js`, canlı doğrulama).
3. **`web/src` içindeki ~200 bileşenin tamamı okunmadı.** Frontend hedefli incelendi: checkout akışı, görsel render yolları, markdown/`dangerouslySetInnerHTML` noktaları, `next.config.mjs`, `server.js`. Loading/error/empty state kapsamı bileşen bazında taranmadı.
4. **`vendor/` kapsam dışı** — `google/apiclient` ve `smalot/pdfparser` sürümlerinin bilinen CVE durumu kontrol edilmedi. `composer.lock` denylist'te olduğu için içeriği de okunmadı.
5. **Doğrulama komutları çalıştırılmadı.** Denetim salt-okunur yapıldığı için `npm run build`, `npm run lint`, `php -l` ve `iyzico_selftest.php` **hiç koşturulmadı**. "Build kırık mı?" sorusunun cevabı bu denetimde yok.
6. **iyzico'nun gerçek API davranışı doğrulanamadı** — anahtarlar yok (B3). `IyzicoClient`'ın IYZWSv2 imzası ve alan adları koddan okundu, sağlayıcıya karşı sınanmadı.
7. **Yük/performans ölçümü yapılmadı.** `getDialogues()`'un `ORDER BY RAND() LIMIT 100` sorgusu ve `getSuggested()`'in aynı deseni tablo büyüdükçe tam tarama yapar; bunu bulgu olarak yazmadım çünkü mevcut veri hacmini ölçemedim.
8. **`storage/` ve `assets/` dizinlerinin gerçek içeriği ve dosya izinleri incelenmedi.**

---

## Karar vermem gereken belirsizlikler

Bunların hiçbirinde doğru davranışa kendim karar vermedim.

1. **Sohbet Defteri herkese açık mı olmalı? (B-05)** `user_dialog_books`'ta gizlilik sütunu yok, `getDialogues()` herkesin notunu herkese gösteriyor. Kodun içindeki yorum "bilinçli olarak public bir akış" diyor ve `notes/page.jsx`'te "Paylaştıklarım" sekmesi var — ama "paylaştıklarım" adı, paylaşılmayan bir şeyin de olabileceğini ima ediyor. **Soru:** kullanıcı bir diyaloğu kaydettiğinde bu (a) her zaman yayınlanmalı mı, (b) varsayılan olarak özel olup açıkça paylaşılabilmeli mi? (b) ise şema değişikliği gerekiyor. Mevcut davranışın doğru mu yanlış mı olduğu belirsiz olduğu için **test yazmadım.**

2. **Üyelik paketi süresi ne olmalı? (D-05)** Bugün ₺599 bir kez alınıyor, hak süresiz veriliyor. "Aylık" fiyat etiketi yenilemeyi ima ediyor ama ne yenileme akışı ne de süre sütunu var. **Soru:** paket (a) aylık yenilenen bir abonelik mi, (b) tek seferlik kalıcı yükseltme mi? (a) ise süre dolunca hangi plana düşülür ve mevcut fazla botlara ne olur (Elmas'tan Ücretsiz'e düşen kullanıcının 50 botu var)? Bu, D-05'in yamasının şeklini tamamen değiştiriyor.

3. **İade abonelik erişimini iptal etmeli mi? (D-03)** Teknik olarak "evet" bariz görünüyor, ama kısmi iadede hangi kalemin iptal edileceği D-08 çözülmeden belirlenemiyor; iade edilen bir botun bonus mesaj kredisinin ne olacağı (hiç kullanılmamışsa? yarısı harcanmışsa?) bir iş kuralı. **Soru:** iade sonrası (a) erişim anında kesilir mi, (b) dönem sonuna kadar sürer mi? Harcanmış kredi geri alınır mı?

4. **Silme adayı 25 endpoint'ten hangileri gerçekten ölü? (A-01)** `marketplace_reconcile.php` ve `parampos_callback.php` dışarıdan çağrılmak üzere tasarlanmış olabilir — kod içi çağıran aramasıyla bu ayırt edilemez. `list_withdrawals.php` ve `update_withdrawal_status.php` ise D-01'in çözümünde **kullanılacak**, silinmemeli. **Soru:** hangileri gelecekteki bir entegrasyon için bilerek duruyor?

5. **`SERVICE_FEE_PERCENT` uygulanacak mı?** Sabit `AppConfig`'de duruyor, hiçbir kod okumuyor, `createSubscription` `service_fee` sütununu bilerek boş bırakıyor. **Soru:** hizmet bedeli alınacak mı? Alınacaksa satıcı komisyonuyla (%85/%80) ilişkisi ne — üstüne mi ekleniyor, içinden mi düşülüyor?

6. **`upgradePlan` neden `paymentsColumnExists()` guard'ını kullanmıyor?** `createSubscription:514-518` opsiyonel sütunları varlık kontrolüyle yazıyor; `upgradePlan:467-489` aynı sütunları koşulsuz yazıyor. İkisinden biri yanlış. **Soru:** `param_marketplace_payments`'ın opsiyonel sütunları her kurulumda var mı (o zaman `createSubscription`'daki guard gereksiz), yoksa yok mu (o zaman `upgradePlan` bazı kurulumlarda paket satışını kırıyor)? Guard'ın kendi yorumu "kurulumlar farklılık gösteriyor" dediği için bunu şemadan tek başına belirleyemedim.

---

## Bulgu tablosu (J serisi) — "Beni Hatırla" çerezi

Tarih: 2026-09-05 · Yöntem: kod okuma + çalışan sunucuya karşı canlı `Set-Cookie` ölçümü + gerçek kodla transaction'lı uçtan uca test (tüm yazmalar rollback edildi, veritabanında kalıcı değişiklik yok).

**Kök neden (ikisinde de ortak):** Bu mimaride PHP hiçbir zaman doğrudan TLS konuşmuyor — `web/server.js` Express ile `/api`, `/admin`, `/assets` yollarını düz HTTP üzerinden `127.0.0.1`'deki PHP'ye proxy'liyor. Yani **`$_SERVER['HTTPS']` canlıda da her zaman boş.** Çerezlerin `Secure` bayrağını buna bağlayan her kod canlıda sessizce yanlış karar veriyor. Kanıt: `curl -i -X POST http://localhost:3000/api/auth/logout.php` yanıtında `host: 127.0.0.1:8000`, ve CLI probu `$_SERVER['HTTPS'] === NULL`.

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi | Çözüm | Durum |
|---|---|---|---|---|---|---|---|---|
| **J-01** | Broken functionality / Security | **P1** | `api/src/Presentation/Controllers/AuthController.php:38-45,85-89` · `api/src/Presentation/Middleware/AuthMiddleware.php:107-113,127-134` | `remember_me` çerezi dört ayrı yerde elle yazılıyordu ve `secure` bayrağı **birbirini tutmuyordu**: giriş `'secure' => true` sabitliyor, rotasyon ve temizlik `'secure' => !empty($_SERVER['HTTPS'])` (yani her zaman `false`) kullanıyordu. | `AuthController::login` satır 42 `'secure' => true`; `AuthMiddleware::tryRememberMe` satır 111 `'secure' => !empty($_SERVER['HTTPS'])`. Kök nedende kanıtlandığı gibi `$_SERVER['HTTPS']` hiç dolmuyor. | İki yönlü hata: **(a)** Düz HTTP sunulan kurulumda (localhost dışı — LAN IP'si, HTTP staging) giriş, tarayıcının reddettiği bir `Secure` çerez yazıyor → **"Beni Hatırla" hiç çalışmıyor, kullanıcı her seferinde yeniden giriş yapıyor.** Aynı nedenle çıkışta silme çerezi de reddediliyor. **(b)** HTTPS'te çerez ilk rotasyonda `Secure` bayrağını **kaybediyor** → 30 günlük kimlik doğrulama sırrı bundan sonra düz HTTP isteklerinde de gönderilebilir hâle geliyor (MITM'e açık). | Dört çağrı noktası tek bir `RememberMeCookie` yardımcısına bağlandı; `secure` bayrağı, `X-Forwarded-Proto`'yu **yalnızca doğrudan bağlantı yerel bir adresten geliyorsa** okuyan `RequestContext::isHttps()` ile belirleniyor (`checkout_payments.php`'deki `clientIp()` ile aynı güven deseni). | **UYGULANDI** |
| **J-02** | Security | **P1** | `api/functions/bootstrap.php:22` | PHP oturum çerezi (`PHPSESSID`) **canlıda hiçbir zaman `Secure` bayrağı almıyor** — aynı kök neden. | Canlı ölçüm, uçta HTTPS simüle edilerek: `curl -i -X POST -H "X-Forwarded-Proto: https" http://localhost:3000/api/auth/logout.php` → `set-cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax` — `secure` yok. | Oturum kimliği, https:// ile yayınlanan sitede bile düz HTTP isteklerinde gönderilebilir. `SameSite=Lax` üst düzey GET gezinmelerinde çerezi gönderdiği için, siteye http:// ile atılan tek bir bağlantı oturum kimliğini ağda açığa çıkarır → oturum ele geçirme. | Tek satır: `'secure' => !empty($_SERVER['HTTPS'])` → `'secure' => RequestContext::isHttps()`. Yardımcı yazıldı ve yedi dağıtım senaryosunda doğrulandı (sahte `X-Forwarded-Proto` dahil). | **UYGULANMADI** — otonomi sözleşmesi "session/oturum davranışını etkileyen yama → diff olarak öner" diyor. Yanlış pozitif bir `true` **tüm kullanıcıları** dışarıda bırakır; kararı kullanıcı vermeli. |

### J-02 için önerilen yama (uygulanmadı)

`api/functions/bootstrap.php` — mevcut hâli `require_once __DIR__ . '/../src/autoload.php'` zincirinin en başında çalıştığı için `RequestContext` sınıfı henüz autoload'a kayıtlı değil; bu yüzden dosyanın **doğrudan `require_once`** etmesi gerekiyor:

```diff
 if (session_status() === PHP_SESSION_NONE) {
+    // Secure bayrağı $_SERVER['HTTPS']'e bağlanamaz: PHP bu mimaride her
+    // zaman proxy arkasında düz HTTP konuşuyor (bkz. RequestContext).
+    require_once __DIR__ . '/../src/Shared/Utilities/RequestContext.php';
     session_set_cookie_params([
         'lifetime' => 0,
         'path'     => '/',
         'httponly' => true,
         'samesite' => 'Lax',
-        'secure'   => !empty($_SERVER['HTTPS']),
+        'secure'   => RequestContext::isHttps(),
     ]);
     session_start();
 }
```

**Uygulamadan önce doğrulanması gereken:** canlıda uçtaki proxy (nginx/Cloudflare/Express) `X-Forwarded-Proto: https` gönderiyor mu? Göndermiyorsa yama bir şeyi bozmaz (bugünkü davranışın aynısı, `secure=false`) ama açığı da kapatmaz. Kontrol: canlıda `curl -i https://<alan-adı>/api/auth/logout.php -X POST` → yanıtta `PHPSESSID` çerezinde `secure` görünüyorsa yama işe yaramış demektir.

### J turunda doğrulanan ama bulgu OLMAYAN noktalar

Sunucu tarafı remember-me mantığının tamamı gerçek kodla test edildi (geçici kullanıcı + token, hepsi tek transaction içinde, sonunda rollback — `kalan_token: 0`, `kalan_test_kullanici: 0`):

- Token yazımı ve `findByRememberToken()` ile geri okunması — **çalışıyor**.
- Oturumsuz ziyaretçide `AuthMiddleware::optionalAuth()` doğru `user_id`'yi döndürüyor ve `$_SESSION`'ı kuruyor — **çalışıyor**.
- Rotasyon: eski selector siliniyor, kullanıcıda tek token kalıyor — **çalışıyor**.
- Tek kullanımlık olma: tüketilmiş çerez ikinci kez `0` döndürüyor — **çalışıyor**.
- Süresi dolmuş token kabul edilmiyor (`expiry > NOW()` sorguda var) — **çalışıyor**.
- Yanlış validator selector'ı yakıyor — **çalışıyor**.
- Frontend: `Beni Hatırla` kutusu kontrollü, değer `rememberMe` olarak payload'a giriyor, `login.php` `(bool)` okuyor — **çalışıyor**.
- Şema: `user_tokens` tablosu mevcut, `selector varchar(32)` / `hashed_validator varchar(64)` üretilen değerlere yetiyor — **uygun**.
- Express proxy birden fazla `Set-Cookie` başlığını olduğu gibi geçiriyor (tek yanıtta üç tanesi ölçüldü) — **sorun yok**.

### J turunda not edilen, düzeltilmeyen kenar durum

`AuthMiddleware::tryRememberMe()` token'ı tek kullanımlık yaptığı için (SEC-009'un bilinçli kararı), dönen kullanıcının ilk sayfa yüklemesinde **aynı çereze dayanan ikinci bir eşzamanlı istek** 401 alabilir: ilk istek `session_regenerate_id(true)` ile eski oturum dosyasını siler ve selector'ı tüketir; kilit sırasını bekleyen ikinci istek artık geçersiz olan eski çerezle gelir. Etki geçici ve kendi kendini onarıyor (tarayıcı yeni çerezi almış olur), ama bir istek boşa gider. Kalıcı çözüm bir "grace period" (eski token'ı kısa süre geçerli tutmak) olurdu — bu SEC-009'un güvenlik duruşunu değiştireceği için **kendi başıma karar vermedim.**

---

## Bulgu (K serisi) — Canlıda "Yeni Liste Oluştur" çalışmıyor

Tarih: 2026-09-05 · Yöntem: kod okuma + gerçek controller'ın CLI'da çalıştırılması + 009 öncesi şemanın GEÇİCİ (TEMPORARY) tabloyla birebir taklidi. Gerçek `user_lists` tablosuna hiçbir yazma yapılmadı (öncesi/sonrası: 7 sütun, 7 satır).

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi | Çözüm | Durum |
|---|---|---|---|---|---|---|---|---|
| **K-01** | Deployment / Broken functionality | **P1** | `api/database/schema.sql:user_lists` · `api/database/migrations/009_user_list_color.sql` · `api/src/Presentation/Controllers/SocialController.php:193-224,265-270` | `addUserList()` ve `getUserLists()`, `user_lists` tablosunun `color` ve `description` sütunlarını **koşulsuz** kullanıyor. Bu iki sütun temel kurulum dosyası `schema.sql`'de YOK; yalnızca migration 009 ekliyor. Migration uygulanmamış bir veritabanında her iki endpoint de SQL 1054 ile patlıyor. | 009 öncesi şema geçici tabloyla taklit edilip `Database::insert('user_lists', [... 'color' ...])` çağrıldı: `PDOException 42S22 — Unknown column 'color' in 'field list'`. `addUserList()` içinde try/catch yok → global handler → **HTTP 500, `{"success":false,"message":"Sunucu hatası oluştu."}`**. Aynı satır, sütunlar mevcutken sorunsuz: controller `{"success":true,"listId":...}` döndürdü. | Kullanıcı liste oluşturamaz. Sayfa yine de AÇILIR — `fetchUserLists`'in hata dalı `setListData([])` + `setFetchError(...)` yapıyor — yani semptom "sayfa duruyor ama yeni liste oluşmuyor" şeklinde görünür. `AddToListModal` üzerinden liste oluşturma da aynı nedenle bozulur. | (a) Operasyonel: canlıda `php api/database/migrate.php --status` ile doğrula, `--apply` ile 009'u uygula (009 saf `ADD COLUMN`, idempotent, veri silmiyor — `--allow-destructive` gerekmez). (b) Kod: `schema.sql`'deki `user_lists` tanımına iki sütunu eklemek, ki temiz kurulum migration'a muhtaç olmasın. | (a) **UYGULANAMAZ** — `migrate.php --apply` otonomi sözleşmesinde "asla yapma" listesinde. (b) **UYGULANMADI** — veritabanı şeması değişikliği onay gerektiriyor. |

### K-01 (b) için önerilen yama (uygulanmadı)

`api/database/schema.sql` — temel kurulumun 009'a muhtaç kalmaması için:

```diff
 CREATE TABLE `user_lists` (
   `id` int NOT NULL AUTO_INCREMENT,
   `user_id` int NOT NULL,
   `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
+  `color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'violet',
+  `description` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
```

Migration 009 idempotent olduğu için (sütun varsa hiçbir şey yapmıyor) bu değişiklik mevcut kurulumlarla çakışmaz.

### K turunda elenen alternatif nedenler

- **Denylist (üç dosya birden kontrol edildi).** `api/.htaccess` ve `api/router.php` yalnızca `.env`, dump, log, arşiv ve `src|vendor|migrations|database|functions` dizinlerini kapatıyor; `/api/social/adduserlist.php` normal bir giriş noktası, engellenmiyor.
- **Benzersizlik çakışması.** `user_lists`'te `PRIMARY (id)` ve `KEY (user_id)` dışında index yok; migration 008 bu tabloya dokunmuyor. Aynı adla ikinci liste oluşturmak hata üretmez.
- **Yetkilendirme.** `addUserList()` ve `getUserLists()` aynı `requireAuth()`'u kullanıyor; oturum sorunu olsaydı sayfa da hiç liste getiremezdi ve mesaj "Oturum açmanız gerekiyor." olurdu.
- **İstek biçimi.** Frontend `FormData` içinde `data` alanı gönderiyor, controller `json_decode($_POST['data'])` ile okuyor — eşleşiyor. `require_method('POST')` sağlanıyor.
- **Ad uzunluğu / kolasyon.** `mb_substr($name, 0, 255)` sınırı zorluyor, tablo utf8mb4.

### Ayırt edici kontrol

Sayfa canlıda **mevcut listeleri gösteriyorsa** sütunlar vardır ve neden başkadır — o hâlde canlıdaki `storage/logs/php-error.log` son satırı belirleyicidir. Sayfa **boş açılıyor ya da hata bandı gösteriyorsa** teşhis K-01'dir.

---

## Bulgu (L serisi) — Canlıda PDF yükleme

Tarih: 2026-09-05 · Yöntem: kod okuma + kütüphanenin gerçek davranışının ölçülmesi + controller'ın dört hata dalının ayrı ayrı çalıştırılması + proxy üzerinden 12 MB gövde testi.

**Teşhis edilemezlik asıl sorundu.** `readPdf()` içinde tek bir `error_log` yoktu; canlıda başarısız olduğunda geriye yalnızca istemcideki genel mesaj kalıyordu. Aşağıdaki dört kusur, "neden çalışmıyor" sorusunun cevapsız kalmasının sebebiydi.

| ID | Kategori | Sev | Dosya | Problem | Kanıt | Kullanıcı etkisi | Durum |
|---|---|---|---|---|---|---|---|
| **L-01** | Broken functionality | **P1** | `TrainingController.php:readPdf` | Gövde `post_max_size`ı aştığında PHP onu atar, `php://input` boş gelir ve kod bunu **"base64Data eksik."** diye raporluyordu. Gerçek sınır: base64 %33 şişirdiği için 15 MB PDF ≈ 20 MB istek gövdesi; `post_max_size` varsayılanı **8M**. Yani arayüzün vaat ettiği 15 MB varsayılan yapılandırmada asla geçemez, tavan ~6 MB'tır. | Yerel PHP: `post_max_size=8M`, `upload_max_filesize=2M`. Controller çalıştırıldı, `CONTENT_LENGTH=20000000` + boş gövde ile eski kod "base64Data eksik." veriyordu. | Kullanıcı arayüzde "maks. 15 MB" okuyup 8-10 MB'lık normal bir PDF yüklüyor, anlamsız bir alan hatası alıyor ve sorunu kendi dosyasında arıyor. | **DÜZELTİLDİ** — durum ayrı ayrı yakalanıyor, HTTP 413 ve sunucunun GERÇEK sınırını söyleyen mesaj dönüyor (ölçüldü: `"sunucu sınırı: 8M"`). |
| **L-02** | Broken functionality | **P1** | `TrainingController.php:readPdf` | `catch (\Exception)` `\Error`ı yakalamıyordu. `vendor/` sürüm kontrolünde değil (`.gitignore`); dağıtımda `composer install` çalışmadıysa `autoload.php` VAR ama `Smalot\PdfParser\Parser` YOK → `\Error` → yakalanmayan hata → gerekçesi hiçbir yere yazılmadan **500**. Ayrıca o dalda `unlink` atlandığı için geçici dosya diskte kalıyordu. | `JsonResponse::error()` `exit` çağırıyor, yani `finally` de çalışmaz — temizlik tek noktaya alındı. | Bileşen eksikse kullanıcı "Sunucu hatası" görür, yönetici nedenini asla öğrenemez. | **DÜZELTİLDİ** — `class_exists` ön kontrolü + net 503 mesajı + `\Throwable` yakalama + yanıttan önce tek noktada `unlink`. |
| **L-03** | Observability | **P1** | `TrainingController.php:readPdf` | Uçta hiçbir loglama yoktu. | — | Canlı arıza teşhis edilemiyordu. | **DÜZELTİLDİ** — her başarısızlık dalı `[readpdf]` önekiyle gerçek nedeni yazıyor (log çıktısı doğrulandı). |
| **L-04** | Frontend | **P1** | `create/page.jsx:handleKbPdf` | `await res.json()` doğrudan çağrılıyordu, `res.ok` kontrolü yoktu. Yanıt JSON değilse (vekil sunucunun HTML 413'ü, PHP fatal'inin boş gövdesi) atılan `SyntaxError` kullanıcıya PDF hatası gibi gösteriliyordu. Bu uçta önemli: gövde büyük olduğu için araya giren sunucunun gövdeyi reddetmesi en olası senaryolardan biri. | İstek gövdesi 12 MB ile proxy'den geçiyor (401 = PHP'ye ulaştı), yani reddetme noktası dağıtıma göre değişir. | Gerçek neden HTTP durumunda saklıyken kullanıcı alakasız bir ayrıştırma hatası görüyordu. | **DÜZELTİLDİ** — gövde metin olarak okunup ayrıştırılıyor, JSON değilse HTTP durumuna göre gerçek mesaj veriliyor (413 ayrıca ele alınıyor). |

### Canlıda kalan olasılıklar (koddan çözülemez)

Düzeltmelerden sonra canlıda hâlâ başarısız oluyorsa `storage/logs/php-error.log` artık nedeni yazıyor. `[readpdf]` satırı **hiç yoksa** istek PHP'ye ulaşmıyor demektir; sırasıyla:

1. **Vekil sunucu gövde sınırı.** nginx `client_max_body_size` varsayılanı **1M** — 750 KB'tan büyük her PDF 413 alır. Apache'de `LimitRequestBody`. Bu katman PHP'den önce reddeder, loga hiçbir şey düşmez.
2. **`post_max_size` / `memory_limit`.** Ölçülen etki L-01'de. `memory_limit=128M` iken 15 MB PDF (base64 20 MB + çözülmüş 15 MB + parser yapıları) tükenebilir; bellek tükenmesi **yakalanamayan** fatal'dir.
3. **`max_execution_time`.** Yerelde `0` (sınırsız), canlı varsayılanı genelde 30 sn. Büyük PDF'te `Smalot\PdfParser` bunu aşabilir.
4. **`composer install` çalışmamış.** Artık net bir 503 ve log satırı üretiyor (L-02).

### Doğrulanan ama bulgu OLMAYAN noktalar

- `smalot/pdfparser` `composer.json`'da erken bir commit'ten beri var, `composer.lock` sürüm kontrolünde — bağımlılık tanımı eksik değil.
- Kütüphane bozuk/boş/başlıksız girdide `\Exception` türevleri fırlatıyor (ölçüldü), yani ayrıştırma hataları düzgün ele alınıyordu.
- `.htaccess` ve `router.php` denylist'leri `/api/training/readpdf.php`'yi engellemiyor.
- Geçerli bir PDF uçtan uca 200 dönüyor.

---

## Bulgu (M serisi) — Sohbette dosya yükleme hiçbir şey yapmıyor

Tarih: 2026-09-05 · Yöntem: istemci gönderim yolunun ve sunucu tarafındaki Gemini payload'ının uçtan uca okunması + backend genelinde ek dosya araması.

| ID | Kategori | Sev | Dosya:satır | Problem | Kanıt | Kullanıcı etkisi |
|---|---|---|---|---|---|---|
| **M-01** | Broken functionality | **P1** | `web/src/app/dashboard/chat/page.jsx:534-541` · `api/src/Presentation/Controllers/ChatController.php:325-333` | Sohbete eklenen dosya base64'e çevrilip `parts` dizisine konuyor ve **o dizi hiç kullanılmıyor**. İstek gövdesi yalnızca `{chatbot_id, message}` taşıyor. Dosya ne sunucuya ne modele ulaşıyor; veritabanına da yazılmıyor. | `parts` değişkeni 534'te tanımlanıp 538'de push ediliyor, başka hiçbir yerde OKUNMUYOR (664'teki `parts` Gemini'nin YANITINA ait, ayrı şey). `generateReply` gövdesi: `JSON.stringify({ chatbot_id: botId, message: userText })`. Sunucudaki payload yalnızca iki `text` parçası içeriyor. Backend genelinde `inline_data`/`file_data` araması: sohbet tarafında **0 sonuç**. `addchat.php`'ye giden kayıt da yalnızca `message: data.text`, dosya adı bile saklanmıyor. | Kullanıcı dosya seçiyor, mesaj balonunda dosya adını görüyor — yani **yüklenmiş gibi görünüyor** — ama bot dosyayı hiç almıyor. Sessiz başarısızlık: hata yok, yalnızca bot dosyadan habersiz cevap veriyor. |

### Neden bu, "dosya başına LumaCoin" isteğini bloke ediyor

Mevcut hâlin üstüne ücretlendirme eklemek, **atılan bir dosya için gerçek para birimi tahsil etmek** olurdu. Bu yüzden kendiliğimden uygulamadım; ayrıca otonomi sözleşmesi ödeme/coin yollarını "önce raporla, onay bekle" kapsamına alıyor.

### Durum: KAPATILDI (2026-09-05)

Kullanıcı onayıyla iki adım birlikte yapıldı: dosya artık isteğe gerçekten biniyor (sunucuda MIME beyaz listesi + 4 MB tavan + Gemini payload'ına inline_data parçası) ve dosyalı mesaj mesaj 1 + dosya 1 = 2 LumaCoin düşüyor. Doğrulama aşağıda.

### Karar için gereken ek bilgiler (ölçüldü)

- **Mesaj başına yalnızca BİR dosya seçilebiliyor**: `MessageInput.jsx:63` → `e.target.files?.[0]`, `<input type="file">`'da `multiple` yok. Yani "5 dosya = 5 coin" pratikte 5 ayrı mesaj demek.
- Bugün **1 mesaj = 1 coin** (`consumeMessage`, `ChatController:306`). Dosyalı bir mesajın toplam maliyetinin 1+1 mi yoksa 1 mi olacağı ayrı bir iş kuralı.
- Dosyayı gerçekten göndermek istek gövdesini büyütür; L serisindeki `post_max_size` (8M) tavanı burada da geçerli olur.

---

## Bulgu (COMP serisi) — iyzico reddi sonrası uyum taraması

Tarih: 2026-09-05 · Tetikleyici: iyzico başvurusunun 02.09.2026'da reddedilmesi (BLOCKERS B3).
Yöntem: iş modelinin kodda izlenmesi (para akışı, kayıt, içerik, pazarlama metni) + canlı sitenin dışarıdan görünümü.

Ret gerekçesi sağlayıcı tarafından **belirtilmedi**; aşağıdakiler ret nedenlerinin *kanıtı* değil, ödeme kuruluşu risk kriterleriyle örtüşen ve kodda doğrulanmış eksiklerdir.

### Kapatılanlar

| ID | Sev | Dosya | Problem | Yapılan | Doğrulama |
|---|---|---|---|---|---|
| **COMP-001** | **P1** | `web/src/app/components/landing/LandingFooter.jsx` · `web/src/app/dashboard/checkout/page.jsx:548` · `web/src/features/payment/PlanPaymentModal.jsx:124` · `web/public/*.png` | Site, geçerli bir üye iş yeri sözleşmesi **olmadan** "iyzico ile Öde" logo bandını footer'da ve checkout'ta gösteriyordu; başvuru reddedildiği için böyle bir sözleşme yok. İzinsiz marka kullanımı. | Üç görünür referans da kaldırıldı; checkout'taki rozetin yerine sağlayıcı adı geçmeyen nötr güvenlik notu kondu. İki marka görseli `git rm` ile silindi (public/ altından servis edildikleri için adresi bilen herkes indirebiliyordu). | `grep -rn "iyzico" web/src` → yalnızca kod içi teknik referanslar (IyzicoClient, env adları) kaldı. Build + lint temiz. |
| **COMP-002** | **P0** | `api/src/Application/UseCases/Auth/RegisterUseCase.php` · `api/src/Shared/Utilities/InputSanitizer.php` · `api/src/Shared/Constants/AppConfig.php` · `web/src/shared/lib/age.js` | `kullanicilar.dogum_tarihi` kayıt formunda toplanıyor ve gizlilik politikası bunu "yaş doğrulaması için" topladığını yazıyordu — ama **sunucuda hiçbir doğrulama yoktu**. Alan zorunlu bile değildi: zorunluluğu yalnızca `login/page.jsx` içindeki bir `if` sağlıyordu, `register.php`ye doğrudan POST atan biri alanı hiç göndermeden geçebiliyordu. | `AppConfig::MIN_REGISTRATION_AGE = 18` + `InputSanitizer::birthDate()` (biçim, `checkdate()`, gelecek tarih, 130 yaş üst sınırı, yaş kapısı). İstemci tarafına aynı kontrolün uyarı kopyası (`shared/lib/age.js`) ve forma görünür "(18+)" etiketi. | `php -l` temiz; sınır durumları (takvimde olmayan tarih, gelecek tarih, 17/18 yaş) ayrı ayrı ele alınıyor. |
| **SEC-014b** | **P1** | `api/src/Application/UseCases/Auth/RegisterUseCase.php` | Kütle atama: `$data` **ham hâliyle** `UserRepository::create()` → `insert()` içine giriyordu, yani istemcinin gönderdiği her anahtar `kullanicilar` tablosuna yazılmaya çalışılıyordu. İstemci `google_id` göndererek hesabını bir Google kimliğine bağlayabiliyor, `avatar` (longtext) alanına sınırsız veri basabiliyordu. | Beyaz liste: yalnızca `kullanici_adi`, `eposta`, `sifre`, `dogum_tarihi` ve (varsa) `telefon` yazılıyor. | `php -l` temiz. COMP-002 ile aynı turda yakalandı. |
| **COMP-003** | **P0** | `api/src/Shared/Utilities/ContentPolicy.php` (yeni) · `ChatbotController::saveChatbot/updateChatbot` | Kullanıcı üretimi bot içeriğinde (isim, açıklama, stil promptu, karşılama mesajı) **hiçbir katmanda tek bir içerik kontrolü yoktu**: yasaklı kelime listesi de, insan incelemesi de, rapor üzerine askıya alma da. Bu botlar pazaryerinde ücretle satılıyor. | İlk hat filtre: cinsel/pornografik içerik, uyuşturucu, kumar/bahis, sahte ürün, reçetesiz ilaç kalemleri için sözcük kökü + çok sözcüklü kalıp eşleştirme; leet katlama; oluşturma **ve** güncelleme yollarında. Yanlış pozitif koruması: kök sözcüğün başında olmalı, ek toleransı 6 harf, bilinen çakışmalar için istisna listesi. | Ad-hoc test: 14 masum ifade (`seksen`, `esrarengiz`, `seksiyon`, `sıkışmak`, `kumaş`, `Amsterdam`, `poker yüzü` …) → 0 yanlış pozitif; 16 ihlal ifadesi (`p0rn0`, `s3ks`, `iddaa kuponu`, `replika saat satışı`, `çocuk pornosu` …) → 16/16 yakalandı. |
| **COMP-004** | **P1** | `api/src/Shared/Utilities/BankIdentity.php` (yeni) · `WalletController::saveBankInfo/withdraw` | Satıcı ödeme kimliğinde **sıfır doğrulama** vardı: `saveBankInfo()` beyaz listeden geçen ne varsa (`"TR00"`, `"asdf"`, 3 haneli TCKN) doğrudan `banka_bilgileri`'ne yazıyordu; `withdraw()` de `InputSanitizer::string($data['iban'], 40)` dışında hiçbir kontrol yapmadan çekim talebi açıyordu. Hatalı IBAN'lı talep bakiyeden düşüldüğü için kullanıcının parasını da kilitliyordu. | IBAN: TR ön eki + 26 hane + **ISO 13616 mod-97** sağlaması, normalize edilmiş biçimde saklanıyor. TCKN: 11 hane + resmî sağlama algoritması + yer tutucu (`11111111111`) reddi. Doğrulama `withdraw()`'da named lock'tan **önce**. | Bilinen geçerli örneklerle test edildi: `TR330006100519786457841326` ✓ (boşluklu yazımı da ✓), son hanesi bozuk hâli ✗, `DE…` ✗; TCKN `10000000146` ✓, `10000000147` ✗, `11111111111` ✗. |
| **COMP-006** | **P2** | `web/src/shared/config/site.js` · `HeroSection.jsx` · `HeroChatCard.jsx` · `LandingFooter.jsx` | Ana sayfa, footer ve meta açıklaması platformu öncelikle bir **kazanç fırsatı** olarak konumlandırıyordu ("gelir elde edebileceğin", "kolayca gelir modeline dönüştürün", "gelir elde etme fırsatını yakalayın"). Bu kalıp, ödeme kuruluşu risk kriterlerinde gelir vaadi / ağ pazarlaması sinyaliyle örtüşüyor. | Dört metin de olgusal ifadeye çevrildi ("dilersen pazaryerinde satışa sunabileceğin"). Pazaryeri ve komisyon modeli **gizlenmedi** — yalnızca vaat dili kaldırıldı. | Build + lint temiz. |
| **COMP-007** (= **D-01**) | **P0** | `api/admin/parcekme.php` (yeni) · `api/admin/index.php` · `api/admin/partials/_sidebar.php` | D-01'in kod yarısı: `listWithdrawals()` ve `updateWithdrawalStatus()` `/api/wallet/` altında yayındaydı ama **hiçbir arayüz onları çağırmıyordu**; her çekim talebi kalıcı olarak `beklemede` kalıyor ve tutar bakiyeden düşüldüğü için satıcının parası süresiz kilitleniyordu. | Admin paneline "Para Çekme Talepleri" sayfası: durum filtresi, satır bazlı durum güncelleme, `ödendi` için ayrı onay adımı, tüm kullanıcı verisi HTML-escape'li. Route tablosuna ve kenar çubuğuna bağlandı. | `php -l` temiz. Sayfa `$_SESSION['admin']` arkasında; çağırdığı iki uç nokta ayrıca kendi içinde `requireAdmin()` çalıştırıyor. |

> **D-01 notu:** bu satır blocker'ı KAPATMAZ. B7'nin operasyonel yarısı (talebi kim onaylar, havaleyi kim yapar, `ödendi` işaretini kim atar) hâlâ açık. Sayfa para göndermez; `ödendi` yalnızca bir kayıttır ve arayüzde bu açıkça yazıyor.

### Uygulanmadı — onay bekliyor

Aşağıdakiler otonomi sözleşmesinin "önce raporla" kapsamına giriyor (ödeme yolu / iş kuralı / kullanıcıyı parasından kilitleme riski).

#### COMP-005 — Kendi botunu satın alma engellenmiyor (kartı nakde çevirme)

`MarketplaceController::addToCart()` (satır 3-34) ve `createSubscription()`'ın kalem döngüsü (satır 344-410) satıcı durumunu, `is_independent`'i ve fiyatı kontrol ediyor — ama **alıcının botun yazarı olup olmadığını hiç kontrol etmiyor**. `grep -n "author_user_id" MarketplaceController.php` → 4 sonuç, hiçbiri karşılaştırma değil.

**Sömürü:** kullanıcı bir bot oluşturur, `MAX_WEEKLY_PRICE` (5.000 TL) fiyat verir, ikinci hesabından ya da çalıntı kartla satın alır; `param_marketplace_details.payable_amount` satıcı payı olarak bakiyeye yazılır (`SELLER_COMMISSION_WEEKLY = 0.85`), ardından IBAN'a çekilir. Teslim edilen "ürün" dijital ve doğrulanamaz olduğu için işlem normal bir satıştan ayırt edilemez. Ödeme kuruluşlarının en çok baktığı kalıp budur.

Önerilen yama (iki noktaya da gerekli — sepet kontrolü tek başına yetmez, checkout sepeti yeniden okuyor):

    // addToCart(), $sellerCheck bloğundan sonra:
    $owner = $db->selectSingle('author_user_id FROM chatbotlar WHERE id = ?', [$chatbotId]);
    if ($owner && (int) $owner['author_user_id'] === $userId) {
        JsonResponse::error('Kendi botunuzu satın alamazsınız.', 422, AppConfig::ERR_VALIDATION);
    }

    // createSubscription(), $bot okunduktan ve seller_status kontrolünden sonra:
    if ((int) $bot['author_user_id'] === $userId) {
        $conn->rollBack();
        JsonResponse::error('Kendi botunuzu satın alamazsınız.', 422, AppConfig::ERR_VALIDATION);
    }

**Neden uygulanmadı:** `createsubscription.php` otonomi sözleşmesinde adı geçen üç ödeme dosyasından biri. Ayrıca "kendi botunu önizlemek için satın alma" gibi meşru bir kullanım varsayılmışsa bu yama onu kırar — kararı ürün tarafı vermeli.

#### COMP-005b — Çekim, kayıtlı IBAN'a değil herhangi bir IBAN'a yapılabiliyor

`withdraw()` IBAN'ı **istekten** alıyor; `banka_bilgileri`'ndeki kayıtlı IBAN ile karşılaştırılmıyor. COMP-004 ile biçim doğrulandı ama sahiplik doğrulanmıyor: kullanıcı parasını üçüncü bir kişinin hesabına gönderebilir. Kimlik doğrulanmadan üçüncü kişiye para göndermek, ödeme kuruluşu risk kriterlerinde ağır kalem.

Önerilen yama: `withdraw()` içinde kayıtlı IBAN'ı okuyup eşitlik aramak; eşleşmiyorsa reddedip kullanıcıyı banka bilgilerini güncellemeye yönlendirmek.

**Neden uygulanmadı:** kayıtlı banka bilgisi olmayan mevcut kullanıcıları bakiyelerinden kilitler. Önce "kayıtlı IBAN'ı olmayan kaç kullanıcının bekleyen talebi var" ölçülmeli.

#### COMP-008 — Her ödemede sabit TCKN gönderiliyor

`checkout_payments.php:176` — `$identity = ... ?: '11111111111';`. `$context['identity_number']` hiçbir çağıran tarafından doldurulmuyor, yani **pratikte her işlem** bu yer tutucuyla gidiyor. Adres de sabit: `'Dijital teslimat - fiziksel adres yok'`, zip `34000`. Sağlayıcı bu alanları dolandırıcılık skorlamasında kullanıyor; tüm işlemlerin tek kimlikle gelmesi risk skorunu doğrudan yükseltir.

Kod tarafındaki yama küçük (COMP-004'teki `BankIdentity::normalizeTckn()` ile doğrulanmış gerçek TCKN'yi bağlamak), ama **veri yok**: kullanıcıdan TCKN hiç toplanmıyor. Bu yüzden madde önce bir ürün kararı: checkout'ta TCKN sorulacak mı, yoksa sağlayıcıyla bu alanın zorunluluğu mu görüşülecek.

**Neden uygulanmadı:** `checkout_payments.php` otonomi sözleşmesindeki üç ödeme dosyasından biri.

### Kod ile çözülemeyenler

| ID | Problem | Neden kod değil |
|---|---|---|
| **COMP-009** | Pazaryeri modeli alt üye iş yeri (sub-merchant) olmadan kurulu: tahsilat kendi üye iş yeri hesabında toplanıp elle IBAN havalesiyle dağıtılıyor. Üçüncü kişiler adına para toplayıp dağıtmak 6493 sayılı Kanun'da ödeme hizmetidir. | İş modeli kararı + sağlayıcı ürünü. **B1** ve **B7** ile aynı kök. |
| **COMP-010** | Sitede ticaret ünvanı, adres, telefon, vergi dairesi/no, MERSİS **hiçbiri yok**; iletişim yalnızca iki gmail adresi (`TeslimatIadePopup.jsx:69-72`). Mesafeli satış mevzuatının açık şartı ve onboarding'in ilk kontrol kalemi. | Bende bu veriler yok. Uydurma/yer tutucu değer yazmak blocker'ın üstünü örtmek olurdu (CLAUDE.md). Gerçek değerler verilirse tek kaynak bir config + footer + sözleşme sayfalarına bağlanabilir. |
| **COMP-011** | Pazaryeri dışarıdan görünmüyor: `/dashboard` giriş arkasında, üstelik `ChatbotRepository::getPublished()`'in `param_marketplace_sellers.status='active'` INNER JOIN'i yüzünden giriş yapılsa bile boş. İnceleyen kişi ürün göremiyor. | **B1**'e bağlı. B1 açıkken vitrin doldurulamaz. |
| **COMP-012** | Cayma hakkı tamamen kapalı, iade yalnızca üç istisnai durumda (`TeslimatIadePopup.jsx`). Müşteri iade alamayınca bankaya itiraz eder — reddin gerekçesinde açıkça sayılan "harcama itiraz riski". | Hukuki/ticari karar. Ayrıca sözleşme metinleri admin panelindeki `global_vars`'tan geliyor, koddan değil. |
| **COMP-002b** | Yaş kapısının Google ile kayıt yarısı açık: `GoogleLoginUseCase` hesabı `dogum_tarihi` NULL olarak açıyor, Google ID token doğum tarihi taşımıyor. | Çözüm oturum akışını değiştiriyor (NULL doğum tarihli kullanıcıyı ilk girişte tarih soran adıma zorlamak). Varsayılan tarih uydurmak kapıyı anlamsızlaştırırdı; yapılmadı, kod içinde de not düşüldü. |

### Onay sonrası kapatılanlar (2026-09-05, aynı tur)

Yukarıdaki "Uygulandı değil — onay bekliyor" bölümündeki üç madde kullanıcı onayıyla uygulandı. Bölüm tarihsel kayıt olarak duruyor; güncel durum aşağıdadır.

| ID | Sev | Dosya | Yapılan | Doğrulama |
|---|---|---|---|---|
| **COMP-005** | **P0** | `MarketplaceController::addToCart()` · `createSubscription()` · `assertNotOwnBot()` (yeni) | Kendi botunu satın alma **iki kapıda birden** yasaklandı. Sepet tarafında paylaşılan `assertNotOwnBot()`; checkout tarafında `$bot` zaten `author_user_id` taşıdığı için satır içi karşılaştırma (ikinci sorgu yok). Checkout kapısı şart: döngü sepeti veritabanından yeniden okuyor, yani yamadan önce sepete girmiş satırlar oradan geçer. `owner_user_id` değil `author_user_id` kullanıldı — satın alma sonrası owner değişebiliyor, satıştan pay alan ise yazar. | `php -l` temiz. Checkout ihlal dalında `$conn->rollBack()` `JsonResponse::error()`'dan ÖNCE (error exit ediyor). |
| **COMP-005b** | **P1** | `WalletController::withdraw()` | Çekim artık yalnızca `banka_bilgileri`'ndeki kayıtlı IBAN'a yapılabiliyor. Karşılaştırma normalize edilmiş biçim üzerinden — bu yamadan önce boşluklu kaydedilmiş IBAN'lar kilitlenmesin diye. Üç ayrı yol ayrı ayrı ele alındı: kayıtlı IBAN yok → kaydetmeye yönlendiriliyor; kayıtlı IBAN geçersiz (yama öncesi doğrulanmadan yazılmış) → güncellemeye yönlendiriliyor; eşleşmiyor → reddediliyor. | `php -l` temiz. Mevcut akış kırılmıyor: `WithdrawalModal.jsx:29-36` zaten `getiban.php`'den kayıtlı IBAN'ı çekip onu gönderiyor — yani yama yalnızca API'ye doğrudan istek atan yolu kapatıyor. |
| **COMP-010** | **P1** | `web/src/shared/config/company.js` (yeni) · `LandingFooter.jsx` · `LegalPage.jsx` | Yasal künye altyapısı kuruldu, **değerler boş**. `hasCompanyIdentity()` boşken künye blokları hiç render edilmiyor ve Organization JSON-LD'ye hiçbir künye alanı girmiyor. Doldurulduğu anda üç yerde birden belirir: landing footer, sözleşme sayfası alt bilgisi, JSON-LD. Yer tutucu değer YAZILMADI — sahte tüzel kişilik bilgisi eksiği kapatmaz, gizler. | Build + lint temiz. Boş konfigürasyonla site bugünküyle birebir aynı görünüyor (blok render edilmiyor). |

> **COMP-010 durumu:** altyapı hazır, **veri yok**. Ticaret ünvanı ve adres girilene kadar madde AÇIK sayılmalı — mesafeli satış mevzuatı ve ödeme kuruluşu onboarding'i açısından eksik olan şey kod değil, veridir.

---

# Kapatma turu (2026-09-06) — "AUDIT.md'deki istenilen her şeyi düzelt"

Yöntem: bulgu bulgu kod okuma + düzeltme, ardından dört doğrulama komutunun tamamı ve gerçek veritabanına karşı davranış testleri (geçici tablo + rollback; kalıcı yazma yok).

**Doğrulama komutları — dördü de temiz:**

- `find api -name "*.php" -not -path "*/vendor/*" | xargs -n1 php -l` → 0 hata
- `cd web && npm run lint` → 0 uyarı
- `cd web && NEXT_DIST_DIR=.next-verify npm run build` → başarılı (29 route)
- `php api/database/iyzico_selftest.php` → 77 geçti, 0 başarısız (canlı sandbox tahsilat + iptal dahil)

**Ek davranış testleri (gerçek MySQL'e karşı, 33 kontrol, hepsi geçti):** hız sınırı sayacı (5/7 sınırı, reset, fail-closed), `GREATEST`/`COALESCE` NULL davranışı, `DELETE` rowCount ile atomik toggle, `ON DUPLICATE KEY UPDATE` ile ikinci beğeninin 500 üretmemesi, `LEAST` coin tavanı, `CHAR_LENGTH` çok baytlı sayım, çekim durum kümesi, plan adı tek kaynak, `update()` anahtar çakışması istisnası, `getGlobalVars()` dönüş tipi, `reported_for` kümesi, Google kullanıcı adı türetme (varchar(30) sınırı dahil), SMTP CRLF reddi, uzantısı `.php` olan gerçek PNG'nin içerikten tespiti.

**Canlı şema teyidi (üç sütun kümesi de mevcut, yani aşağıdaki düzeltmeler bugünkü kurulumu kırmıyor):** `notifications.message_tr`/`message_en` ✔, `plans.independent_bot_limit`/`public_bot_limit`/`daily_message_limit` ✔, `user_lists.color`/`description` ✔.

## Kapatılanlar

| ID | Ne yapıldı |
|---|---|
| **A-03** | `getSuggested()` içindeki `data:image/jpeg;base64,` öneki kaldırıldı — sütunda base64 değil `assets/…` yolu var, önek her görseli bozuyordu. |
| **F-01** | Görsel yolu normalizasyonu tek yere alındı: `shared/lib/image.js` → `normalizeImagePath()`. Üç düzeltmeyen render noktası bağlandı (`ChatbotCard.jsx`, `ProfileCard.formatImage`, `SuggestedCard.jsx`). |
| **F-02** | `updateUserNames`/`updateUserEmail` yazmadan önce UNIQUE çakışmasını sorguluyor, 409 + anlamlı mesaj dönüyor (eskiden ham PDO istisnası → 500). Ayrıca `kullanici_adi` kırpma sınırı 60'tan sütunun gerçek genişliği olan 30'a çekildi. |
| **F-04 / J-02** | Çerez `Secure` bayrağı üç yerde `RequestContext::isHttps()`e bağlandı: `bootstrap.php` (PHPSESSID), `AuthController::logout()` (silme çerezi artık yazma çereziyle birebir aynı bayraklarda), `admin/functions/session.php` (kendi yorumundaki "bootstrap ile birebir aynı olmalı" kuralı). |
| **E-02** | Kapanmış çekim durumu kümesi ASCII `odendi` yerine `WITHDRAWAL_STATUSES`ten türetiliyor — ödenmiş talep force'suz yeniden açılamıyor. |
| **E-03** | `migrations/010_notification_message_columns.sql` yazıldı; controller'daki çalışma zamanı `ALTER TABLE` kaldırıldı, yerine varlık kontrolü + net 503 kondu. |
| **E-04** | `getPricing()`'in kodlanmış geri düşüş kataloğu kaldırıldı → `upgradePlan()` ile aynı 503. Frontend'deki DÖRDÜNCÜ kopya (`upgrade/page.jsx` → `initialPlanData`) da boşaltıldı; hata durumunda fiyat gösterilmiyor. |
| **E-05** | Ücretsiz plan adı tek kaynağa bağlandı: `AppConfig::FREE_PLAN_NAME` + `pricing.js` → `FREE_PLAN_NAME`/`isPaidPlan()`. Yan bulgu: frontend `planName !== "Ücretsiz Plan"` karşılaştırması yüzünden ücretsiz kullanıcıya **"Pro" rozeti** gösteriyordu (Sidebar, DashboardHeader). |
| **B-01** | `getBotsOfList()` `requireAuth()` + `user_lists` sahiplik kontrolü aldı. |
| **B-02 / B-03 / B-04 / B-12** | Altı yazma yolunda kütle atama kapatıldı: `addDialogBook`, `addComment` (Note), `addBotToList`, `addHide`, `addUninterest`, `updateConversation`. `addComment` ayrıca `dialog_id` varlığını doğruluyor. |
| **B-06** | `hostIsPublic()` → `resolvePublicIps()`; doğrulanan IP `CURLOPT_RESOLVE` ile pinleniyor (DNS rebinding penceresi kapandı). |
| **B-06b** | `CURLOPT_WRITEFUNCTION` ile bayt sayılıyor, tavan aşılınca aktarım kesiliyor (chunked yanıtta `CURLOPT_MAXFILESIZE` hiçbir şey yapmıyordu). |
| **B-08** | `addToCart()` duplicate dışındaki PDO hatasını artık istemciye yazmıyor: `error_log` + genel 500. |
| **B-09** | `listIller`/`listIlceler` `requireAuth()` + `checkRateLimit` aldı; `?nocache` yalnızca admin oturumunda. |
| **B-10** | `getOwner()` `requireAuth()` arkasına alındı (kullanıcı adı numaralandırma). |
| **B-11** | Şifre sıfırlamaya IP'siz ikinci sayaç eklendi (`resetcode-account:<eposta>`, 15 dk / 10 deneme) — IP havuzuyla sayaç atlatma kapandı. |
| **B-13** | `updateTrainingChunk` hız sınırı + parça ve TOPLAM uzunluk tavanı aldı; tavan `AppConfig::MAX_TRAINING_CHARS` (okuma tarafıyla aynı sabit). |
| **C-01** | Bot limiti `GET_LOCK('botlimit_user_<id>')` ile korunuyor — `saveChatbot` ve `publishChatbot` ikisinde de. |
| **C-02** | Beş beğen/beğenme/takip metodu atomik hâle geldi: `DELETE`in satır sayısı toggle kararı, `INSERT … ON DUPLICATE KEY UPDATE` ile yarışta 500 yok. |
| **D-06** | `getMyPayments()` `LEFT JOIN`e çevrildi — üyelik paketi alımı (detay satırı yok) artık Ödemelerim listesinde görünüyor; kalemsiz sipariş "Üyelik paketi" olarak etiketleniyor. |
| **G-01** | `reported_for` sunucuda sabit kümeye karşı doğrulanıyor; admin panelindeki `insertAdjacentHTML` render'ı tamamen `textContent`e çevrildi (depolanmış XSS iki taraftan kapandı). |
| **G-02** | `featureForm` iç içe `<form>` olmaktan çıkarıldı (`<div>` + click handler) — "Plan İçerikleri" hiç çalışmıyordu ve dış formun yarısı form dışında kalıyordu. |
| **G-03 / G-04 / G-11 / G-16** | `kullanicilar.php`: Kaydet butonundan `hidden` kaldırıldı; silme handler'ı `form.isim` yerine `form.ad_soyad` okuyor; `read.php` çağrılarına `columns` eklendi (bcrypt hash + `google_id` + base64 avatar artık tarayıcıya inmiyor) ve `console.log` silindi; `li.innerText` düz metin yerine değişkeni yazıyor. Aynı `baslik1` hatası `chatbotlar.php`de de düzeltildi. |
| **G-06 / H-06** | Ortak `admin/functions/upload_guard.php` yazıldı; üç yükleme yolu (`upload.php`, `updategv.php`, `seo.php`) tek doğrulamayı ve `AppConfig` sınırlarını paylaşıyor. Uzantı artık doğrulanmış MIME'dan türüyor, istemcinin dosya adından değil. |
| **G-07** | `.env` yazımı: anahtar beyaz listesi, CR/LF + tırnak reddi, tırnaklı değer. Boş gönderim mevcut anahtarı korur (`api.php` artık anahtarı HTML'e basmıyor). |
| **G-08** | `readenv.php`: anahtar beyaz listesi + maskelenmiş değer. Silme adayı olarak işaretlendi. |
| **G-09** | `admin_login_at` gerçekten okunuyor: boşta 2 saat / mutlak 12 saat zaman aşımı (`admin_session_enforce_timeout()`). `admin_login_ip` bilinçli olarak yetkilendirmede kullanılmıyor, gerekçesi kod içinde. |
| **G-10** | `ajax/cikis.php` `_guard.php` kullanıyor (metot + CSRF) ve oturumu tamamen yıkıyor. |
| **G-12** | Ham istisna mesajı sızan dört nokta kapatıldı: `seosite.php` (mesajı bir `<script>alert()` bloğunun içine yazıyordu), `db_backup.php`, `ajax/create.php`, `ajax/update.php`. |
| **G-13** | Göreli yollar `__DIR__` tabanlı yapıldı: `seo.php` yükleme hedefleri ve `require`, `seosite.php` favicon kontrolü, `seometa.php`/`seotwitter.php` görsel önizleme kontrolleri. |
| **G-14** | Ölü atama silindi; sunucudaki base64 dalı `handleImageUploads` gibi dosya yazmaya çevrildi (`admin_store_uploads()`), yani panelden yüklenen görsel artık uygulamanın beklediği `assets/…` yolunu üretiyor. `update.php`deki artık gereksiz `SELECT *` de kaldırıldı. |
| **G-15** | `logo.php` ve `assets/js/login.js` neden çalışmadıklarıyla birlikte **silme adayı** olarak işaretlendi (silme kararı kullanıcıda). |
| **G-17** | `intval($database->getGlobalVars(...))` → `(int) (...)['theme_index'] ?? 1`; `$themes` boşken kenar çubuğunu görünmez yapan null durumu için varsayılan tema eklendi. |
| **G-18** | `report_detail` kırpması 2000 → 1000 (sütun `varchar(1000)`). |
| **G-19** | "Hakkımızda Sayfası" başlığı "Mesafeli Satış Sözleşmesi" olarak düzeltildi. |
| **G-20** | Panele CSP + `X-Content-Type-Options` + `Referrer-Policy` eklendi (`object-src 'none'`, `base-uri`, `form-action`, `frame-ancestors`, `connect-src 'self'`); kullanılmayan `bcryptjs` CDN script'i kaldırıldı. SRI EKLENMEDİ — uydurulan bir `integrity` kaynağı sessizce bloke eder; kalıcı çözüm bağımlılıkları yerelleştirmek. |
| **H-01** | Zaten kapalıydı (SEC-014b beyaz listesi `RegisterUseCase`te yerinde); doğrulandı, ek değişiklik gerekmedi. |
| **H-02** | `GoogleLoginUseCase` duplicate yakalıyor ve satırı yeniden okuyor (çift tıklama / iki sekme artık 500 üretmiyor). |
| **H-03** | Google ile açılan hesaba `kullanici_adi` türetiliyor (çakışmada sayaç, varchar(30) sınırı test edildi) ve `user_emails` kaydı da yazılıyor — iki kayıt yolu eşitlendi. |
| **H-04** | Admin plan formuna üç kota alanı eklendi; panelden açılan plan artık ücretsiz kotalarla doğmuyor. |
| **H-05** | `autoload.php`in dizin listesi 31'den 11'e indi (11 dizin diskte hiç yoktu); implementasyonsuz altı `Domain` arayüzü dosya başında "yol haritası, sıfır referans" notuyla işaretlendi. |
| **H-07** | `BaseRepository::update()` `$data`/`$whereParams` anahtar çakışmasında `InvalidArgumentException` fırlatıyor; docblock örneği `_` önekiyle düzeltildi. |
| **H-08** | Girişte aynı kullanıcının süresi geçmiş `user_tokens` satırları siliniyor (`purgeExpiredRememberTokens`). |
| **H-09** | `password_verify($password, $user['sifre'] ?? '')` — Google ile açılmış parolasız hesapta deprecation uyarısı/log gürültüsü bitti, kullanıcıya giden mesaj değişmedi (hesap numaralandırma kapalı kalıyor). |
| **H-10** | `AppConfig` yorumu gerçeğe uyduruldu: `FREE_*` bir fallback, `PRODUCER_*` gerçekten ölü. |
| **I-05** | SMTP ayarları doğrulanıyor (`FILTER_VALIDATE_EMAIL`, host deseni, CR/LF reddi) ve `smtp_client.php` zarf adreslerini de CR/LF + `<>` + biçim kontrolünden geçiriyor (savunma derinliği). |
| **I-06** | SMTP parolası artık HTML'e basılmıyor; boş bırakılırsa mevcut parola korunuyor. `.env`e taşıma yolu sayfada belirtildi. |
| **I-07** | `db.php`ten iki kanıtlanmış ölü metot silindi: `getParamTypes()` (mysqli kalıntısı, PHP 8'de TypeError üretirdi) ve `truncate()` (allowlist yok, `$this->conn->error` PDO'da yok, CLAUDE.md TRUNCATE'i zaten yasaklıyor). `validators.php` + `minify.php` silme adayı olarak işaretlendi; `sanitize_string()` düzeltildi (mb_substr + girdide kaçış yok). |
| **I-08** | `rateLimitHit()` gövdesi try/catch içine alındı — docblock'un "Never throws" iddiası artık doğru, fail-closed korunuyor. Admin girişi tam olarak bu iddiaya güveniyordu. |
| **I-09** | Ayrı `SELECT attempts` kaldırıldı; sayaç `LAST_INSERT_ID()` ile artışın kendi ifadesinden okunuyor. Sabit pencere davranışı bilinçli olarak korundu, gerekçesi kod içinde. |
| **I-10** | Günlük coin iadesine `LEAST(<plan limiti>, …)` tavanı kondu. |
| **I-11** | `GREATEST(COALESCE(expires_at, VALUES(expires_at)), VALUES(expires_at))` — süresiz kredide yenileme artık süreyi yazıyor (eskiden NULL kalıyordu). |
| **K-01 (b)** | `schema.sql`deki `user_lists` tanımında `color`/`description` mevcut (bu turdan önce kapanmış); canlı şemada da var olduğu ölçüldü. |

## Açık kalanlar — kod değil, KARAR gerekiyor

Bunların hiçbirinde doğru davranışa kendim karar vermedim; hepsi CLAUDE.md'nin "önce raporla, onay bekle" ya da "iş kuralı belirsizse kendin karar verme" kapsamında.

| ID | Neden uygulanmadı |
|---|---|
| **D-01** | Kod yarısı COMP-007 ile kapandı. Operasyonel yarısı (talebi kim onaylar, havaleyi kim yapar) **BLOCKERS B7**. |
| **D-02** | `addSubMerchant()` stub. `status='active'` yazmak KYC'siz satıcıya para akışı açmak olurdu — gerçek sağlayıcı entegrasyonu gerekiyor (**B1**). |
| **D-03 / D-08 / I-04** | İade semantiği bir iş kuralı: iade erişimi anında mı keser, dönem sonuna kadar mı sürer; harcanmış kredi geri alınır mı; kısmi iadede hangi kalem iptal edilir. Üçü birbirine bağlı. Ayrıca `checkout_payments.php` otonomi sözleşmesindeki üç ödeme dosyasından biri. |
| **D-04** | Öneri net (tahsilattan ÖNCE `pending` ödeme satırı yaz), ama `createsubscription.php` + `checkout_payments.php` ödeme yolu. |
| **D-05** | "Aylık" paket süresiz veriliyor. Abonelik mi, tek seferlik yükseltme mi? Süre dolunca Elmas'tan düşen kullanıcının fazla botlarına ne olur? Şema değişikliği + iş kuralı. |
| **D-07** | Üretici planı bilinçli kapalı; açmak iki iş kararı gerektiriyor. |
| **I-01** | Checkout idempotency satırı başarıda silinmiyor → aynı botu aynı süreyle yeniden satın alma reddediliyor (abonelik yenileme pratikte kırık). Düzeltme `createSubscription` içinde, ödeme yolu. **Açık maddeler arasında en yüksek etkili olan budur.** |
| **I-02** | `processRefund()` transaction/kilit/idempotency istiyor — ödeme yolu. |
| **I-03** | `user_plan_selection` ↔ `plans` bağı serbest metin isim üzerinden; `plan_id` + FK şema değişikliği. Bu turda ad tek kaynağa bağlandı (E-05) ama YAPISAL sorun duruyor: admin panelinden bir plan yeniden adlandırılırsa o plandaki tüm kullanıcılar sessizce ücretsize düşer. |
| **B-05** | Sohbet Defteri'nin herkese açık mı olacağı ürün kararı; özel yapmak şema değişikliği. |
| **B-07** | E-posta değiştirmede parola + yeni adrese doğrulama akışı; oturum ve e-posta akışını etkiliyor, ayrıca **B5**'e bağlı. |
| **A-01** | 25 silme adayı endpoint SİLİNMEDİ: hangilerinin gelecekteki bir entegrasyon için bilerek durduğunu (`marketplace_reconcile.php`, `parampos_callback.php`) kod içi aramayla ayırt etmek mümkün değil. |
| **A-02** | 15 metodun `JsonResponse` zarfına taşınması API contract'ında kırıcı değişiklik. |
| **G-05** | Kullanıcı ekleme formunda şifre alanı yok. Sayfa salt-düzenleme mi olacak, yoksa forma zorunlu şifre mi eklenecek — iş kuralı. (Not: H-09 düzeltmesinden sonra `sifre` NULL olan hesap parolayla giriş yapamıyor, yani şu an sömürülebilir değil; yalnızca işe yaramaz hesap üretiyor.) |
| **G-15 / I-07** | `logo.php`, `assets/js/login.js`, `validators.php`, `minify.php` silme adayı olarak İŞARETLENDİ ama silinmedi. |
| **COMP-008 / COMP-009 / COMP-010 / COMP-011 / COMP-012 / COMP-002b** | Değişmedi — veri, iş modeli ya da hukuki karar gerektiriyor. |

## Bu turda dikkat edilmesi gereken tek operasyonel adım

`migrations/010_notification_message_columns.sql` **yazıldı, uygulanmadı** (CLAUDE.md `--apply` yasağı). Canlı veritabanında `notifications.message_tr`/`message_en` sütunlarının **zaten var olduğu ölçüldü**, yani bugünkü kurulum etkilenmiyor; migration sıfırdan kurulan veritabanları ve migration geçmişinin tutarlılığı için gerekli.

---

# Karar turu (2026-09-06) — kullanıcı kararlarıyla kapatılan maddeler

Bir önceki turda "karar gerekiyor" diye açık bırakılan maddeler için kullanıcı kararları alındı ve uygulandı. Her madde ayrı commit; commit mesajında bulgu numarası var.

## Uygulanmadan önce bildirilen iki çelişki

1. **I-01 talimatı kendi içinde çelişiyordu.** "Başarısız/beklemedeki denemede kayıt kalsın" ile "başarısız→tekrar deneme senaryosunu test et" aynı anda sağlanamaz: kart reddedildikten sonra kayıt kalırsa istek `$existing` dalına düşüp *"Bu sipariş zaten oluşturulmuş."* (`order_id: NULL`) döner ve o sepet kalıcı olarak kilitlenir. Mevcut kod tam da bunu önlemek için hata dalında siliyordu. **Karar:** hata sınıfı ikiye ayrıldı — kesin ret temizler, belirsiz sonuç tutar.
2. **İade sırasında sağlayıcı kaynaklı kısmi başarı**, kullanıcının "kısmi iade yok" kuralının kapsamadığı bir arıza durumu. **Karar:** erişim ancak TAM iade tamamlanınca kesilir; kısmi hâl admin'e bildirilir ve tekrar denemede iade edilmiş kalemler atlanır.

## I-03 için istenen ölçüm

`user_plan_selection` tablosunda **0 satır** var — yani bozulmuş plan bağı **yok**, FK migration'ı veri onarımı gerektirmeyecek. Katalog sağlam: Ücretsiz (#5), Gümüş (#6), Altın (#7), Elmas (#8). Migration kullanıcı talimatı gereği **yazılmadı**, karar bekliyor.

## Kapatılanlar

| ID | Ne yapıldı | Doğrulama |
|---|---|---|
| **I-01** | Idempotency kaydına yaşam döngüsü: `order_id` dolu + taze → tekrar penceresi (15 dk, çift tıklama koruması); dolu + eski → düşer, yeniden satın alma serbest; boş → tahsilat sonucu belirsiz, tekrar denemeye izin yok (48 saat). Hata dalı ikiye ayrıldı (kesin ret temizler / belirsiz tutar). `cancelCharge()` dönüşü artık dikkate alınıyor: telafi başarısızsa rezervasyon bırakılmıyor. | Gerçek MySQL'e karşı 18 kontrol; iki ardışık başarılı alım ve başarısız→tekrar deneme senaryoları ayrı ayrı. |
| **D-04** | Ödeme satırı tahsilattan ÖNCE, transaction'ın DIŞINDA `pending` olarak yazılıyor; rollback onu silmiyor. Sonuç yazımı: başarı→`paid` (UPDATE), kesin ret→`failed`, belirsiz→`pending` kalır (mutabakat çözsün), telafi başarısız→`unknown`. Transaction içindeki beş doğrulama çıkışı ortak `$failCheckout`'a alındı. | 15 kontrol: rollback'in pending satırı silmediği, mutabakat sorgusunun onu yakaladığı, kullanıcıya görünen listelere ve bakiyeye girmediği. |
| **I-02** | `processRefund()`'a `GET_LOCK('refund_payment_<id>')`, kilit sonrası yeniden okuma, kalem bazlı idempotency (tamamlanmış kalem sağlayıcıya ikinci kez gitmiyor) ve tek transaction'lık durum geçişi. Sağlayıcı çağrıları bilerek transaction dışında. Her DENEME log'lanıyor (kim, ne zaman, hangi ödeme, önceki→sonraki durum). | 14 kontrol (aşağıdaki satırla ortak koşum). |
| **D-03 / D-08 / I-04** | **D-03:** tam iadede abonelik `status=0`, kalan bonus kredi sıfırlanıyor; `credits_total` muhasebe kaydı olarak korunuyor; oransal hesap yok. **D-08:** kalem→detay eşlemesi `chatbot_id` üzerinden (eskiden hepsi ilk detaya bağlanıyordu; detay yoksa yazılan `0` aslında FK ihlaliyle patlıyordu — testte doğrulandı). **I-04:** tutar/kalem gönderen çağıran açıkça reddediliyor; sağlayıcı kaynaklı kısmi başarıda erişim kesilmiyor, admin'e bildiriliyor. | 14 kontrol; dört tablonun satır sayıları test öncesi/sonrası aynı. |
| **D-05** | Üyelik paketi 30 günlük tek seferlik satış. `migrations/011_user_plan_expiry.sql` (+`schema.sql`), `getUserPlan()`'da süre kontrolü, `upgradePlan()`'da `NOW() + SUBSCRIPTION_MONTHLY`. `expires_at IS NULL` = süresiz (geriye dönük iptal yok). Sütun yoksa iki yönlü fail-safe: yazma tarafı süresiz yazıp log'lar, okuma tarafı sorguyu değiştirmez (aksi hâlde 1054 → herkes sessizce ücretsiz kotalara inerdi). | 9 kontrol; migration sonrası şema geçici tabloyla taklit edildi. |
| **B-05** | Karar: Sohbet Defteri **herkese açık** ve bu bilinçli — arayüz kaydetme anında açıkça uyarıyor ("Bu bir herkese açık paylaşımdır…"), sekmeler "Tüm Paylaşılanlar"/"Paylaştıklarım". `is_public` sütunu EKLENMEDİ. Kapatılan asıl açık: `udb.*` → açık sütun listesi, yani tabloya eklenecek yeni bir alan otomatik olarak yayına girmiyor. | Sorgu canlıda çalıştırıldı, frontend'in okuduğu 14 alanın tamamı dönüyor. |
| **B-07** | Mevcut parola doğrulaması + hız sınırı (15 dk / 5). Oturum ele geçirme → e-posta değiştirme → şifre sıfırlama → kalıcı devralma zinciri 2. adımda kesiliyor. Parolasız (Google) hesap için ayrı mesaj. İki frontend çağıranı da güncellendi. | php -l + build + lint temiz. |
| **G-05** | Kullanıcı ekranı salt-düzenleme yapıldı ("Yeni Ekle" kaldırıldı; şifresiz, giriş yapamayan hesap üretiyordu). Forma şifre alanı EKLENMEDİ: sayfa genel CRUD ucunu kullanıyor, gönderilen `sifre` hash'lenmeden sütuna yazılırdı. `functions/crud_guard.php` (yeni) genel uçlarda `kullanicilar.{sifre,google_id,avatar}` ve `adminler.sifre` yazımını reddediyor. | php -l temiz; panel JS'i `node --check` ile ayrıştırıldı. |

## Bu turda ortaya çıkan, ŞEMA gerektiren iki sınır

1. **İade → erişim eşlemesi belirsiz.** `user_subscriptions` ve `chatbot_purchase_credits` tablolarında sipariş/ödeme bağı yok; tek eşleşme `(user_id, chatbot_id)`. I-01 düzeltmesinden sonra aynı botu iki kez satın almak mümkün olduğu için, birinci siparişin iadesi ikinciyi de kapatabilir. Sessiz bırakılmadı: durum tespit edilip log'a ve admin yanıtına `warnings` olarak yazılıyor. **Kalıcı çözüm:** `user_subscriptions`'a `payment_id` eklemek.
2. **İade denetim izinde admin kimliği.** `param_marketplace_refunds.requested_by_user_id`'nin FK'sı `kullanicilar`'a, admin ise `adminler` tablosunda — sütuna admin id'si yazılamıyor. Kimlik şimdilik `param_response_json` ve log'da. **Kalıcı çözüm:** ayrı bir `requested_by_admin` sütunu ya da ortak bir aktör tablosu.

## Ertelenenler (kullanıcı kararı)

- **A-01 / A-02** — bu turun kapsamı dışında.
- **I-03** — FK migration'ı, bozuk kayıt sayısı (0) bildirildikten sonra konuşulacak.

## Uygulanmayı bekleyen migration'lar

| Dosya | İçerik | Durum |
|---|---|---|
| `010_notification_message_columns.sql` | `notifications.message_tr/message_en` | Canlıda sütunlar zaten VAR; migration geçmiş tutarlılığı için. |
| `011_user_plan_expiry.sql` | `user_plan_selection.expires_at` + index | **Uygulanmalı.** Uygulanana kadar üyelik paketi süresiz yazılmaya devam eder (fail-safe, log'a düşüyor). |

İkisi de yazıldı, **uygulanmadı** (CLAUDE.md `--apply` yasağı; deploy sırasında elle çalıştırılacak).

---

# Pazaryeri revizyonu (2026-10-05) — `docs/prompts/11-pazaryeri-revizyon.md`

Kaynak: müşteri revizesi (`PAZARYERİ SİSTEM DÜZENLEMESİ.pdf`). Faz 0 (salt okuma) tamamlandı; bu bölüm Faz 0 kararlarını, Faz 0'da bulunan yeni maddeleri (N serisi) ve Faz 1 sonuçlarını tutar.

## Faz 0 karar kaydı

| # | Konu | Durum |
|---|---|---|
| K-1 | S1–S11 ve devamı (paket limitleri, Elmas fiyatı, Satın Aldıklarım, takip edilen bot seçimi, "Satın Al" butonları, defter profil butonu, belge türleri/sınırı) | Müşteriye soruldu (2026-10-05), cevap gelmedi. **Geliştirici kararıyla kapatıldı (müşteri onayı yok, 2026-10-05)** — bkz. GK-1…GK-16. |
| K-2 | Yayınlanan bağımsız bot **herkese açık bot hakkından** sayılır, bağımsız hakkı boşalır; herkese açık hakkı doluysa yayınlanamaz. | Bugünkü kod davranışı (`ChatbotRepository::countByOwner` anlık duruma göre sayıyor, `publishChatbot` public limitini kontrol ediyor). **Geliştirici kararı (müşteri onayı yok, 2026-10-05)** — GK-1. |
| K-3 | Yayınlanmış bir botu geri özele çekmek **bağımsız bot limitine takılmaz**, yalnızca "özel yapma hakkı"ndan düşer. Yayınlanmamış bağımsız bot hak kullanmadan sahibine özel kalır. | **Geliştirici kararı (müşteri onayı yok, 2026-10-05)** — GK-3. Artık bu kurala bağlı kod yazılabilir (N-07, Faz 3). |
| K-4 | Faz 1 (Madde 0: M0-2 → M0-1 → M0-3 → M0-4) başlatıldı. | Kullanıcı onayı (2026-10-05). |

## Faz 0'da bulunan, bu turda düzeltilmeyen maddeler

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-07** | P2 | `ChatbotController::unpublishChatbot`, `ChatbotRepository::unpublish` | Yayından kaldırma botu `is_independent=1` yapıyor ve bağımsız bot limitini hiç kontrol etmiyor: ücretsiz plandaki kullanıcı 1 bağımsız + 2 yayından kaldırılmış = 3 bağımsız bota ulaşabilir. | Açık — doğru davranış K-3'e (müşteri onayı) bağlı. |
| **N-08** | P1 | `ChatbotController::updateChatbotPrice` | Satıcı durumu kontrolü yok; aktif satıcı olmayan kullanıcı kendi botuna fiyat yazabiliyor. Bugün `publishChatbot` B1 yüzünden kapalı olduğu için fiyatlı bot pazaryerine çıkamıyor, ama kontrol tek savunma hattına bağlı. | Açık — Faz 4 kapsamında (ortak yayın akışı). |
| **N-09** | P2 | `SocialController::getBotsOfList` | `param_marketplace_sellers.status='active'` INNER JOIN'i: B1 açıkken listeye eklenen hiçbir bot listede görünmüyor. | **B1 bekliyor** — Faz 4 diff önerisine dahil edilecek. |
| **N-10** | P1 | `web/src/app/components/landing/PricingSection.jsx` | Paket fiyatları ve limitleri sabit kodlu dördüncü kopya: Gümüş 0 ₺ / Altın 750 ₺ / Elmas 1.850 ₺; `plans` seed'i 149/299/599, müşteri 149/299/849. Ana sayfa ile yükseltme sayfası farklı fiyat gösteriyor. | Açık — Faz 3 (paket değerleri müşteriden bekleniyor). |
| **N-11** | P3 | `features/chatbot-mgmt/ChatbotForm.jsx`, `features/notes/DialogueModal.jsx`, `features/wallet/BankInfo.jsx`, `entities/chatbot/ui/{ChatbotCard,BotCard,MarketplaceListCard,SuggestedCard}.jsx`, `app/dashboard/chat/page.jsx:12` (`WithdrawalModal` importu) | `web/src` içinde importu olmayan dosyalar / kullanılmayan import (JSX olduğu için `api/admin` ve `router.php` aramaları uygulanamaz). | Ölü kod adayı — silinmedi. |
| **N-12** | P3 | `ChatController::generateReply` (API anahtarı yok dalı) | Anahtar yoksa yalnızca mesajın hakkı iade ediliyor (`refundMessage($allowanceSource)`); aynı istekte belgeler için düşülen ek haklar (`$fileAllowanceSources`) iade edilmiyor. Belgeli mesajda kullanıcı cevap almadan coin kaybediyor. | Açık — Faz 1 sırasında görüldü, kapsam dışı (06 kuralı: yeni problem → yeni ID, düzeltme yok). |

## Faz 1 — Madde 0 (önceki toplantı eksikleri)

Tüm değişiklikler frontend'de. **Davranış testi yazılamadı:** `web/` altında test çalıştırıcısı yok (package.json'da `test` betiği / jest / vitest yok); bunu kurmak yeni bağımlılık kararıdır. Bu yüzden "önce kırmızı test" adımı uygulanamadı. Doğrulama: kök neden kod okumayla kanıtlandı, değişiklikler `npm run lint` + `NEXT_DIST_DIR=.next-verify npm run build` ile doğrulandı. **Tarayıcıda uçtan uca denenmedi.**

| ID | Madde | Kök neden (kanıt) | Değişiklik | Durum |
|---|---|---|---|---|
| **N-01** | M0-2 | `chatbots/components/ChatbotCard.jsx`'te kartın hiçbir alanı sohbete bağlı değildi; tek bağlantı "Yönet" (`/dashboard/chatbots/create?id=`). Bilinçli bir kapatma izi yok. Sohbet tarafı engel değil: kartı listeleyen `getMenuItems()` yalnızca sahibi olunan ya da aktif aboneliği olan botları döndürüyor, ve `userHasAccess('full')` sahibi koşulsuz kabul ediyor — yayınlanmamış bağımsız bot dahil. | Kapak ve başlık/avatar satırı `/dashboard/chat?botId=<id>` bağlantısı (diğer sayfalardaki desen). Alt düğmeler (Sil / Yayınla / Fiyat / Yönet) değişmedi. | **Kapandı (kod).** Eğitimin modele gittiği kod yoluyla izlendi: `ChatController::generateReply` `LEFT(training_prompt, 60000)` okuyup `[BİLGİ KAYNAĞI]` olarak system instruction'ın ilk parçasına koyuyor. **Çalışma zamanı kanıtı YOK** — aşağıya bakın. |
| **N-02** | M0-1 | Bilgi Bankası sekmesinde "önce botu kaydedin" kapısı zaten VARDI ama `!botId` ile kontrol ediliyordu; yeni botta `botId = -1` (truthy) olduğu için kapı hiç kapanmıyordu. Yükleme `update_training_chunk.php`'ye `id:-1` gönderiyor, `positiveInt(-1) = 0`, `findByIdAndOwner(0)` null → **"Bu chatbot üzerinde yetkiniz yok."** (ekran görüntüsündeki mesaj). **İlk hipotez kısmen yanlıştı:** URL yolu metni yerel state'te tutmuyor, aynı `appendToCorpus()` → aynı çağrıyı yapıyor; kaydedilmemiş botta URL de aynı hatayı verir. Müşterinin "URL çalışıyor" gözlemi büyük olasılıkla kayıtlı bir bot üzerindeydi. | Kapı `isSavedBot` ile (dosyada zaten tanımlı, önizleme aynı kontrolü kullanıyor); `refreshCorpus` da `-1` için istek atmıyor. Yeni botta sekme artık "önce botu kaydedin" diyor. | **Kapandı (kod)** — yanıltıcı yetki mesajı yolu kapandı. **Açık kalanlar (müşteri sorusu, K-1):** (1) Kayıttan sonra kullanıcı `/dashboard/chatbots`'a yönleniyor, bilgi bankası için "Yönet" ile geri dönmesi gerekiyor — sihirbaz içinde yükleme isteniyorsa metni kayda kadar yerel state'te tutmak gerekir (daha büyük değişiklik). (2) Yalnızca PDF destekleniyor; görsel/OCR canlı sihirbazda yok (`tesseract.js` yalnızca kullanılmayan `ChatbotForm.jsx`'te). (3) UI "maks. 15 MB" diyor, fiili sınır `post_max_size`'a bağlı (8M'de ~6 MB, bkz. L-01). Sınırlar: parça 8.000 karakter (istemci), toplam 60.000 karakter (`MAX_TRAINING_CHARS`), taranmış PDF metin vermez ve kullanıcı ayrıştırma hatası görür. |
| **N-03** | M0-3 (a) | **Kök neden kanıtlanamadı** — yerel `user_lists` tablosunda `color`/`description` var ve `getUserLists` sorgusu yerelde hatasız çalışıyor (ölçüldü). Daraltma: hiç listesi olmayan kullanıcıda frontend yalnızca `getuserlists.php`'yi çağırıyor (`getbotsoflist.php` liste başına çağrılıyor), o da tek bir `SELECT id, name, color, description FROM user_lists`. Bu sorgunun 500 vermesinin kodla uyumlu tek açıklaması şema farkı: **K-01** (migration 009 uygulanmamış → 1054) ya da tablo yok. | Yok. | **Canlı veri bekliyor.** Gerekli: canlıda `php api/database/migrate.php --status` çıktısı ya da Liste açıldığı andaki `storage/logs/php-error.log` satırı. 009 eksikse çözüm operasyonel (`--apply` — Claude için yasak). |
| **N-04** | M0-3 (b) | `dashboard/list/page.jsx`'te yükleme durumu yoktu ve hata dalı `setListData([])` yapıyordu: hata bandının ALTINDA "Koleksiyon Bulunamadı / İlk Listenizi Oluşturun" ve "Toplam Koleksiyon: 0" çiziliyordu (ekran görüntüsündeki birliktelik). | Ayrı yükleme / hata / boş durumları; hata durumunda boş durum ve sayaç ("—") gösterilmiyor, "Tekrar dene" düğmesi var; her denemede önceki hata temizleniyor. | **Kapandı (kod).** |
| **N-05** | M0-4 | Canlı popup `dashboard/notes/page.jsx` içindeki yerel `DialogueModal` (ayrı `features/notes/DialogueModal.jsx` kullanılmıyor, N-11). Düğme **bilinçli olarak** diyalogu paylaşanın profiline (`/dashboard/user/?id=<udb.user_id>`) gidiyordu ve etiketi paylaşanın kullanıcı adıydı. | Ana düğme `/dashboard/chat?botId=<udb.chatbot_id>` ("<bot adı> ile sohbet et"); bot silinmişse çizilmiyor. Paylaşanın profili ikincil metin bağlantısı olarak kaldı (müşteriye soruldu). | **Kapandı (kod)** — N-06 ile birlikte geçerli. Not: ücretli/başkasının bağımsız botuna giden kullanıcı sohbet edemez (`userHasAccess`), bu bugünkü erişim kuralı. |
| **N-06** | M0-4 | `chat/page.jsx` `DialogNotebookModal`'a `botId={conversationId}` geçiyordu; modal bunu `user_dialog_books.chatbot_id` olarak yazıyor, `NoteController::getDialogues` ise sütunu gerçek bot id'si sayıp `chatbotlar`'a JOIN'liyor. Sonuç: yeni kayıtlar yanlış bota (ya da hiçbir bota) bağlanıyor; N-05 düzeltmesi tek başına yanlış botun sohbetini açardı. Ayrıca konuşma henüz oluşmamışken (`conversationId = -1`) kayıt `chatbot_id gereklidir` ile reddediliyordu. | `botId={botId}`. | **Kapandı (kod, yeni kayıtlar için).** **Mevcut kayıtlar onarılmadı:** yerel veritabanında 3 satır var; 6 ve 7 numaralı satırların değeri BAŞKA bir kullanıcının konuşma id'siyle çakışıyor, yani "değer bir konuşma id'siyse düzelt" diyen kör bir backfill doğru satırları bozardı. Güvenli ayırt edici `cc.id = udb.chatbot_id AND cc.user_id = udb.user_id` (konuşma diyalogu kaydedenin olmalı) — yerelde 0 satır eşleşiyor. Canlıda bu SELECT ile sayılmadan migration yazılmayacak. |

### Faz 1'de yapılamayan: eğitimin çalışma zamanı kanıtı

Yerel veritabanında bilgi bankası kaynağı (`### Kaynak:` başlığı) taşıyan **hiç bot yok** (ölçüldü). Kanıt için iki test botu oluşturmak (URL ile ve PDF ile), `update_training_chunk.php` ile yazmak ve `generatereply.php`'yi çağırmak gerekiyor. Bu (a) yerel veritabanına kalıcı satır yazar — `DELETE FROM` yasağı nedeniyle temizlik yalnızca uygulamanın kendi silme uç noktasıyla yapılabilir, (b) yerelde Gemini anahtarı tanımlı olduğu için gerçek bir dış API çağrısı yapar. İkisi de kullanıcı onayı bekliyor. Alternatif: B4 dalı (anahtarsız) payload'ı oluşturmadan çıkıyor, yani "payload'ı logla" ancak geçici bir log satırı eklenerek yapılabilir.

## Faz 1 — kullanıcı kararları ve ek değişiklikler (2026-10-05)

| # | Karar | Uygulama |
|---|---|---|
| F1-1 | Eğitimin çalışma zamanı kanıtı: kullanıcı tarayıcıda elle test edecek. Gerekirse geçici log (eğitim metni loglanmadan) aynı oturumda kaldırılacak. | Gerekmedi; **log satırı eklenmedi.** Yukarıdaki "yapılamayan" bölümü bu kararla kapandı. |
| F1-2 | İlk kayıttan sonra sihirbaz yeni id ile aynı sayfada kalsın, URL `?id=N` olsun, Bilgi Bankası hemen açılsın. | **Uygulandı (N-02 devamı).** `ChatbotForm` sunucunun döndürdüğü id'yi kendi state'inde tutuyor (`createdBotId`); bileşen yeniden mount edilmiyor (form durumu korunuyor), `history.replaceState` ile URL `?id=N`, sekme `knowledge`. Sonraki kayıtlar `updatechatbot.php`'ye gidiyor ve `is_independent` göndermiyor; yüklenen görsel dosyaları ikinci kez gönderilmesin diye temizleniyor. Düzenleme yolu (mevcut bot) değişmedi: kaydedince listeye dönüyor. |
| F1-3 | Bilgi bankası PDF sınırı 5 MB (S21). | **Uygulandı.** `TrainingController::MAX_PDF_BYTES` 15 MB → 5 MB (hata mesajı sabitten türüyor); arayüzde `KB_MAX_PDF_BYTES` / "maks. 5 MB". 5 MB ≈ 6,7 MB base64 gövde, `post_max_size=8M` altında. Görsel/OCR desteği yok (müşteri sorusu açık). |
| F1-4 | N-06 bozuk kayıtları için canlıda çalıştırılacak salt okunur SELECT; migration yazılmayacak. | **Yazıldı** (aşağıda). Yerelde çalıştırıldı: 3 kaydın 3'ü `DOGRU_GORUNUYOR`, üçünde de diyalog mesajı saklanan botun sohbet geçmişinde bulundu. |
| F1-5 | N-03 canlıdan `migrate.php --status` gelene kadar açık. | Açık. |
| F1-6 | N-12 Faz 3'e kadar açık. | Açık. |

### N-06 teşhis sorguları (salt okunur)

Sınıflar:

- `DOGRU_GORUNUYOR` — saklanan değer var olan bir bot ve kaydedenin aynı id'li bir konuşması yok. Dokunulmaz.
- `ZATEN_AYNI` — saklanan değer kaydedenin bir konuşmasının id'si, ama o konuşmanın botu da aynı id. Değişiklik gerekmez.
- `GUVENLI_ESLENIR` — saklanan değer kaydedenin kendi konuşmasının id'si ve (a) bu id'de hiç bot yok, ya da (b) diyalogun `input_message`'ı konuşmanın botuyla olan sohbet geçmişinde var, saklanan botla olanda yok. Önerilen hedef `konusmanin_botu`.
- `BELIRSIZ` — kaydedenin konuşma id'si AYNI ZAMANDA var olan bir bot id'si ve mesaj kanıtı karar vermiyor. Elle bakılmalı.
- `YETIM` — saklanan değer ne bot ne kaydedenin konuşması, ya da konuşmanın botu silinmiş. Eşlenemez.

Mesaj karşılaştırması birebir eşitlik (`utf8mb4_general_ci`); sohbet geçmişi silinmiş kayıtlar kanıt üretmez ve `BELIRSIZ`e düşer — yani sınıflama ihtiyatlı tarafta hata yapar.

Detay:

```sql
-- N-06 — user_dialog_books.chatbot_id teşhisi. SALT OKUNUR: yalnızca SELECT.
SELECT t.*,
  CASE
    WHEN t.konusma_id IS NULL AND t.saklanan_bot_var = 1 THEN 'DOGRU_GORUNUYOR'
    WHEN t.konusma_id IS NULL                            THEN 'YETIM'
    WHEN t.konusmanin_botu = t.saklanan_id               THEN 'ZATEN_AYNI'
    WHEN t.konusmanin_bot_var = 0                        THEN 'YETIM'
    WHEN t.saklanan_bot_var = 0                          THEN 'GUVENLI_ESLENIR'
    WHEN t.mesaj_konusma_botunda = 1 AND t.mesaj_saklanan_botta = 0 THEN 'GUVENLI_ESLENIR'
    ELSE 'BELIRSIZ'
  END AS sinif
FROM (
  SELECT
    udb.id                     AS kayit_id,
    udb.user_id,
    udb.created_at,
    udb.chatbot_id             AS saklanan_id,
    (c_s.id IS NOT NULL)       AS saklanan_bot_var,
    c_s.isim                   AS saklanan_bot_adi,
    cc.id                      AS konusma_id,
    cc.chatbot_id              AS konusmanin_botu,
    (c_t.id IS NOT NULL)       AS konusmanin_bot_var,
    c_t.isim                   AS konusmanin_bot_adi,
    EXISTS (SELECT 1 FROM chatbot_chats ch
            WHERE ch.chatbot_id = cc.chatbot_id AND ch.user_id = udb.user_id
              AND ch.message COLLATE utf8mb4_general_ci = udb.input_message COLLATE utf8mb4_general_ci
           ) AS mesaj_konusma_botunda,
    EXISTS (SELECT 1 FROM chatbot_chats ch
            WHERE ch.chatbot_id = udb.chatbot_id AND ch.user_id = udb.user_id
              AND ch.message COLLATE utf8mb4_general_ci = udb.input_message COLLATE utf8mb4_general_ci
           ) AS mesaj_saklanan_botta
  FROM user_dialog_books udb
  LEFT JOIN chatbotlar c_s            ON c_s.id = udb.chatbot_id
  LEFT JOIN chatbot_conversations cc  ON cc.id = udb.chatbot_id AND cc.user_id = udb.user_id
  LEFT JOIN chatbotlar c_t            ON c_t.id = cc.chatbot_id
) t
ORDER BY sinif, t.kayit_id;
```

Özet (sınıf başına kayıt sayısı):

```sql
-- N-06 özet. SALT OKUNUR.
SELECT x.sinif, COUNT(*) AS kayit FROM (
SELECT t.*,
  CASE
    WHEN t.konusma_id IS NULL AND t.saklanan_bot_var = 1 THEN 'DOGRU_GORUNUYOR'
    WHEN t.konusma_id IS NULL                            THEN 'YETIM'
    WHEN t.konusmanin_botu = t.saklanan_id               THEN 'ZATEN_AYNI'
    WHEN t.konusmanin_bot_var = 0                        THEN 'YETIM'
    WHEN t.saklanan_bot_var = 0                          THEN 'GUVENLI_ESLENIR'
    WHEN t.mesaj_konusma_botunda = 1 AND t.mesaj_saklanan_botta = 0 THEN 'GUVENLI_ESLENIR'
    ELSE 'BELIRSIZ'
  END AS sinif
FROM (
  SELECT
    udb.id                     AS kayit_id,
    udb.user_id,
    udb.created_at,
    udb.chatbot_id             AS saklanan_id,
    (c_s.id IS NOT NULL)       AS saklanan_bot_var,
    c_s.isim                   AS saklanan_bot_adi,
    cc.id                      AS konusma_id,
    cc.chatbot_id              AS konusmanin_botu,
    (c_t.id IS NOT NULL)       AS konusmanin_bot_var,
    c_t.isim                   AS konusmanin_bot_adi,
    EXISTS (SELECT 1 FROM chatbot_chats ch
            WHERE ch.chatbot_id = cc.chatbot_id AND ch.user_id = udb.user_id
              AND ch.message COLLATE utf8mb4_general_ci = udb.input_message COLLATE utf8mb4_general_ci
           ) AS mesaj_konusma_botunda,
    EXISTS (SELECT 1 FROM chatbot_chats ch
            WHERE ch.chatbot_id = udb.chatbot_id AND ch.user_id = udb.user_id
              AND ch.message COLLATE utf8mb4_general_ci = udb.input_message COLLATE utf8mb4_general_ci
           ) AS mesaj_saklanan_botta
  FROM user_dialog_books udb
  LEFT JOIN chatbotlar c_s            ON c_s.id = udb.chatbot_id
  LEFT JOIN chatbot_conversations cc  ON cc.id = udb.chatbot_id AND cc.user_id = udb.user_id
  LEFT JOIN chatbotlar c_t            ON c_t.id = cc.chatbot_id
) t
) x GROUP BY x.sinif ORDER BY x.sinif;
```

## Geliştirici kararları (müşteri onayı yok, 2026-10-05)

Müşteriye gönderilen sorulara cevap gelmedi; aşağıdaki kararları geliştirici verdi. **Müşteri farklı cevap verirse** "Etkilenen iş" sütunundaki maddeler yeniden açılmalı. Bu kararlara dayanan her commit mesajında ilgili GK numarası geçer.

| # | Karar | Etkilenen iş |
|---|---|---|
| GK-1 | Bağımsız bot "Yayınla" ile herkese açılır; yayınlanınca herkese açık bot sayılır (bugünkü sayım davranışı). | Madde 10 (Faz 4), K-2 |
| GK-2 | Herkese açık botlar ücretsiz kullanılır; mesajlar kullanıcının günlük coin'inden düşer; ana sayfa/Keşfet'te "Ücretsiz" rozetiyle görünür. | Madde 10 (Faz 4: `userHasAccess`, `getPublished`), madde 6 seçicisinin erişim tanımı |
| GK-3 | Özel bot yalnızca sahibine görünür, link ile açılmaz (aboneler süreleri bitene kadar erişir). Ücretsiz plandaki 1 özel yapma hakkı toplamda bir kez. Yayınlanmamış bot hak kullanmadan özel kalır; hak yalnızca yayınlanmış botu geri özele çekerken düşer. Özele çekme bağımsız bot limitine takılmaz (K-3). | Madde 11 (Faz 3), N-07 |
| GK-4 | Paketlerdeki "yayından kaldırma hakkı" = özel yapma hakkı; "yayına alma hakkı" = herkese açık bot limiti. | Paketler (Faz 3) |
| GK-5 | Başvuru yapmış olmak Bakiyem'i açar; sınırsız hak yalnızca onaylanmış (`active`) satıcıya. | Madde 4 (Faz 2), madde 2 (Faz 3), Faz 5 helper'ı |
| GK-6 | Sınırsız hak yalnızca herkese açık botlar için. | Madde 2 (Faz 3) |
| GK-7 | Başvuru alanları: unvan, vergi no, vergi dairesi, MERSİS no, yetkili ad/soyad/doğum tarihi, IBAN, il/ilçe/adres. Belge yükleme bu turda YOK. | Madde 3 (Faz 2 iskelet, Faz 5 backend) |
| GK-8 | Şahıs şirketleri başvurabilir; vergi kaydı olmayan bireyler başvuramaz. | Madde 1, madde 3 |
| GK-9 | Onay admin panelde "Başvurular" sayfasından; onay "incelendi" yazar, `active` yazmaz. | Faz 5 |
| GK-10 | Eski bireysel satıcı kayıtlarına dokunulmaz; yeni bireysel başvuru yapılamaz. | Madde 1 (Faz 2), Faz 5 |
| GK-11 | Paketi biten kullanıcının mevcut botları korunur, yalnızca yenisi açılamaz. | Paketler (Faz 3) |
| GK-12 | Satın Aldıklarım yalnızca en az bir aboneliği (süresi dolmuşlar dahil) olana görünür. | Madde 5 (Faz 2) |
| GK-13 | Ana sayfa seçici: takip edilen ve erişimi olan botlar seçilebilir; erişimsizler kilitli ve "Profili gör" ile; hiç takip yoksa varsayılan Lumanoris AI + boş durum + Keşfet linki. | Madde 6 (Faz 2) |
| GK-14 | Sohbet sayfasındaki dört "Satın Al" da kaldırılır; limit bandında yerine "Paketini yükselt". | Madde 9 (Faz 2) |
| GK-15 | Defter popup'ında paylaşan kişi linki kalır. | N-05 (değişiklik gerekmiyor) |
| GK-16 | Belge eğitimi: yalnızca PDF, OCR yok, sınır 5 MB. | N-02 / F1-3 (değişiklik gerekmiyor) |

---

# Faz 2 — Düşük riskli arayüz değişiklikleri (2026-10-05)

Dayanak: GK-1…GK-16 (geliştirici kararı, müşteri onayı yok). Her madde ayrı commit, `revize/pazaryeri`'ye push edildi. Doğrulama her maddede: `npm run lint` + `NEXT_DIST_DIR=.next-verify npm run build`. Faz 2'de migration yazılmadı, `migrate --apply` gerekmedi. **Tarayıcıda uçtan uca denenmedi.**

| Madde | Commit | Ne yapıldı | Durum |
|---|---|---|---|
| 3 (iskelet) | `b8199c2` | `/dashboard/pazaryeri-basvurusu`: şahıs/kurumsal hesap türü, bireysel "Yakında" (seçilemez), GK-7 alanları. Gönder düğmesi devre dışı ve nedenini söylüyor; sahte başarı yok, veri saklanmıyor. | Tamam (iskelet). Backend Faz 5. |
| 1 | — | Başvuru sayfasında bireysel seçenek "Yakında" (yukarıdaki commit). **Eski kayıt sihirbazındaki (`features/seller/SellerOnboardingWizard.jsx`) "Bireysel" seçeneği DEĞİŞTİRİLMEDİ:** dosyanın hesap türü/doğrulama kısmını okuyan komut, oturumun otomatik izin sınıflandırıcısı tarafından reddedildi; dosya okunmadan düzenlenmedi. Eski kayıt ekranının route'u yok (sihirbaz bir modal; `create/page.jsx` ve `PublishModal.jsx` içinden açılıyor), yani `notFound()`/yönlendirme seçeneği uygulanamaz. | **Açık — kullanıcı izni bekliyor.** |
| 7 | `e87ca55` | Ayarlar → "Ödeme Bilgileri" sekmesi başvuru sayfasına gidiyor; `?tab=security` derin bağlantısı da yönlendiriliyor. Kullanılmayan yerel IBAN/vergi no formu kaldırıldı; para çekme modallarının "Ayarlar > Banka Bilgileri" metni güncellendi. | Tamam. **Yan etki:** Faz 5'e kadar yeni IBAN yalnızca eski satıcı sihirbazından kaydedilebilir (`save_bank_info.php`); mevcut kayıtlı IBAN'lar duruyor ve çekim onları kullanıyor. |
| 9 | `b39d267` | Sohbet sayfasındaki dört "Satın Al" kaldırıldı (`ProfileCard`: masaüstü düğme, mobil hap, Bot Hakkında dialogu; sohbet limit bandı). Limit bandı "Paketini yükselt" → `/dashboard/upgrade`. "Sepete Ekle" duruyor. `chat/page.jsx`'teki kullanılmayan `WithdrawalModal` importu kaldırıldı (N-11). | Tamam. `BuyModal`'ın tek importeri artık kullanılmayan `entities/chatbot/ui/ChatbotCard.jsx` → ölü kod adayı (N-11'e eklendi), silinmedi. |
| 5 | `d8cd024` | Kenar çubuğunda "Satın Aldıklarım" yalnızca `getmysubscriptions.php` en az bir satır (süresi dolmuşlar dahil) döndürürse görünüyor; bilinmezken gizli, istek başarısızsa görünüyor. Yeni uç nokta yok. | Tamam. B1+B3 yüzünden bugün satın alma yapılamadığı için pratikte menü kimsede görünmez. |
| 4 | `b3e8e70` | `useMarketplaceRegistration` hook'u (tek kaynak). Kaydı olmayan Bakiyem'de yalnızca "Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir." + başvuru bağlantısı görüyor; kenar çubuğu ve başlıktaki bakiye tutarı da gizli. **Sunucu yetkisi değişmedi** (wallet uç noktaları yalnızca `requireAuth`). | Tamam. **Varsayım (GK-5 uygulaması):** başvuru tablosu Faz 5'e kadar yok; bugün "başvuru yapmış" = `param_marketplace_sellers.status ∈ {pending, active, suspended, rejected}` (eski akışta başvuru denemesi yapılmış). `not_started`/`kyc_filled` sayılmaz. Müşteri "yalnızca onaylı" derse liste daraltılır. |
| 6 | `9212cbf` | Ana sayfa seçicisi takip edilen botlardan (`getfollowedbots.php`); erişim kümesi `getchatbotsmenu.php` (sahip + aktif abonelik = `userHasAccess('full')`). Erişimsiz takip kilitli + "Profili gör"; platform botu dışında takip yoksa boş durum + Keşfet; yükleniyor/hata durumları. Pazaryeri listesi boşken (B1) varsayılan bot takip listesindeki Lumanoris AI. | Tamam. GK-2 (ücretsiz herkese açık) Faz 4'te erişim kümesini sunucudan genişletecek. |
| 8 | — | Kod değişikliği yok. Kanıt: `AppConfig::DAILY_FREE_MESSAGES = 10`; `plans` tablosunda Ücretsiz plan `daily_message_limit = 10` (varsayılan plan, yerelde ölçüldü); `fallbackPlan()` 10; planı olmayan bir kullanıcı için `getDailyMessageLimit()` = 10 (yerelde ölçüldü, `user_plan_selection` 0 satır). `coin_engine.php` ve `pricing.js` bu değerin KOPYASINI tutmuyor (motor plandan okuyor) — CLAUDE.md'deki "üç kopya" uyarısı bu sabit için geçerli değil. | Doğrulandı, değişmedi. |

## Faz 2'de bulunan yeni maddeler

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-13** | P2 | `app/dashboard/wallet/page.jsx` | `payments` state'i iki UYDURMA siparişle başlıyordu ("Aura Architect Prime" 450 ₺, "Verba SEO & Content Titan" 280 ₺); yükleme sırasında gerçek kayıt gibi listeleniyor, istek başarısız olunca ekranda kalıyor ve "toplam harcama"ya ekleniyordu. | **Kapandı** (`c021e5f`) — boş başlangıç, yükleme ve hata durumu. |
| **N-14** | Şüphe | `ChatbotRepository::userHasAccess`, platform botu | Ana sayfa ve takip listesi Lumanoris AI'yı herkese varsayılan bot olarak sunuyor (sunucu onu örtük takip olarak ekliyor), ama `userHasAccess('full')` yalnızca sahip/abone kabul ediyor; platform botu `SYSTEM` kullanıcısına ait. Kullanıcıların bu bota erişimi başka bir yoldan (ör. kayıtta abonelik satırı) verilmiyorsa sohbet 403 döner. **Kanıtlanamadı:** yerelde `SYSTEM` sahipli "Lumanoris AI" yok, kayıt akışında abonelik yazan kod görülmedi. | Açık — canlı veriyle doğrulanmalı; Faz 4'te GK-2 ile birlikte ele alınabilir. |

---

# Faz 3 — Limitler, gizlilik hakkı ve paketler (2026-10-06)

## Paket tablosu (müşteri PDF'i, teyitli — Elmas 849 ₺ dahil)

| Paket | Fiyat | Günlük coin | Bağımsız | Herkese açık | Yayından kaldırma (özel yapma) hakkı | Yalnızca metin |
|---|---|---|---|---|---|---|
| Ücretsiz | 0 | 10 | 1 | 2 | 1 (toplam) | – |
| Gümüş | 149 ₺/ay | 50 | 3 | 5 | 3 | Diğer tüm özelliklerde sınırsız deneyim |
| Altın | 299 ₺/ay | 100 | 5 | 10 | 5 | Öncelikli destek, tüm Lumanoris deneyimlerine erişim |
| Elmas | 849 ₺/ay | 200 | sınırsız | sınırsız | 20 | 7/24 VIP destek, yeni özelliklere erken erişim |

Bugünkü `plans` seed'iyle (007) farklar: Altın 10/15/200 → 5/10/100, Elmas 599 ₺ 50/50/1000 → 849 ₺ sınırsız/sınırsız/200. Ücretsiz ve Gümüş fiyat/limitleri aynı (Gümüş coin 50, 3/5 — değişmiyor). "Yayından kaldırma hakkı" kolonu bugün yok.

| # | Karar | Etkilenen iş |
|---|---|---|
| GK-17 | **Geliştirici kararı (müşteri onayı yok, 2026-10-06):** paketlerdeki yayından kaldırma (özel yapma) hakları da ücretsizdeki gibi **toplam** sayılır, aylık yenilenmez. | Madde 11 sayaç tasarımı, paket metinleri |

## Faz 3 — yapılanlar ve onay bekleyenler

Doğrulama: her PHP değişikliğinde `php -l`, PHP değişikliğinde iyzico öz-testi, her web değişikliğinde lint + verify build; yeni `api/database/plan_limits_selftest.php` (salt okunur). **Tarayıcıda denenmedi.**

| İş | Commit | Durum |
|---|---|---|
| Ücretsiz plan regresyon testi + müşteri paket tablosu testi | `a1a76b3` (+ `e59b502`) | A (regresyon) ve A2 (madde 2) yeşil; B (paket tablosu) **5 kırmızı** — 013 uygulanana kadar beklenen. |
| N-12 — anahtar yokken belge haklarının iadesi | `64d1409` | **Kapandı.** Otomatik test yok: dal yalnızca anahtar yokken çalışıyor, `googleGeminiApiKey()` `admin/.env`'ye düştüğü için CLI'da tetiklenemiyor. |
| Madde 2 — onaylı satıcıya sınırsız herkese açık hak (GK-5/GK-6) | `e59b502` | **Sunucu tarafı tamam.** `getPublicBotLimit()` `active` satıcıya `BOT_LIMIT_UNLIMITED`; `getchatbotlimits.php`'ye eklemeli `*_unlimited` alanları; oluştur sayfasındaki sabit "1/2 Kullanıldı"/"0/5 Kullanıldı" metinleri gerçek değerle değişti. **"Herkese Açık Chatbot Oluştur" kartı EKLENMEDİ:** herkese açık bot oluşturmak bugün aktif satıcı istiyor (`saveChatbot`), ücretsiz herkese açık bot (GK-2) için bu şartın kalkması Faz 4'teki yayın/satış ayrıştırmasının parçası (birden fazla uç nokta + `userHasAccess` + listeleme → diff önerisi). Kart Faz 4 onayıyla birlikte eklenecek; aksi hâlde her tıklama `SELLER_NOT_ACTIVE` ile biten bir akış olurdu. |
| N-10 — ana sayfa fiyatları | `f0e168f` | **Kapandı.** `getpricing.php`'den okuyor; satılmayan yıllık %20 indirim kaldırıldı. |
| Yerel `migrate --apply` | — | `010_notification_message_columns.sql` ve `011_user_plan_expiry.sql` yerelde uygulandı (öncesi/sonrası `--status` alındı; ikisi de koşullu ALTER, veri ifadesi yok; DB_HOST yerel doğrulandı). |
| 012 — şema: sınırsız = NULL, `plans.privacy_right_limit`, `user_privacy_right_usage` | `api/database/pending/012_…` | **Yazıldı, ONAY BEKLİYOR** (şema tasarımı). migrate.php yıkıcı saymıyor. |
| 013 — paket verisi (UPDATE + `plan_icerikler` DELETE/INSERT) | `api/database/pending/013_…` | **Yazıldı, ONAY BEKLİYOR** (veri güncelleyen/silen). migrate.php yıkıcı sayıyor → `--allow-destructive` gerekir; Claude çalıştıramaz, kullanıcı uygular. Alternatif: değerler admin panelinden girilebilir (yalnızca NULL ve hak kolonu 012 ister). |
| Madde 11 + Elmas sınırsız kodu | — | **012 onayına bağlı, yazılmadı.** Plan: `AppConfig::FREE_PRIVACY_RIGHT_LIMIT = 1` (fallback); `getIndependentBotLimit`/`getPublicBotLimit` NULL → `BOT_LIMIT_UNLIMITED`; `getPrivacyRightLimit()` + kullanım sayımı; `unpublishChatbot` yayınlanmış botu özele çekerken hakkı tüketir (aynı kilit altında `user_privacy_right_usage` satırı), bağımsız limitine takılmaz (GK-3 → N-07'yi kapatır); `getchatbotlimits`/`getPricing` hak ve sınırsız bilgisi; admin Abonelik formuna hak alanı ve "sınırsız" seçeneği; oluştur/chatbotlar ekranında kalan hak. **Uygulama sırası:** NULL'a dayanıklı kod → 012 → 013. |
| Oluştur sayfasındaki 750 ₺ "Üretici Limitinizi Yükseltin" kartı (B2) | — | **Soru:** paket tablosuyla çelişen ayrı bir teklif (5 herkese açık + 2 bağımsız). Satışı zaten kapalı (`buyProducerAccount` fail-closed). Kaldırılsın mı / yükseltme sayfasına mı yönlensin? B2 iş kararı olduğu için dokunulmadı. |

### Canlı için uygulama sırası (kullanıcı çalıştırır) — ESKİ, aşağıdaki "Faz 3 — canlı uygulama sırası (güncel)" bölümü geçerli

1. Faz 3 kodunu (NULL'a dayanıklı sürüm, onaydan sonra) deploy et.
2. `010_notification_message_columns.sql`, `011_user_plan_expiry.sql` (canlıda bekliyorsa) — `--apply`.
3. 012'yi `migrations/`'a taşı → `--apply`. Geri alma: dosya başlığındaki ters ifadeler.
4. 013'ü `migrations/`'a taşı → `--apply --allow-destructive` (ya da değerleri admin panelinden gir). Geri alma: 007'deki değerler ve özellik satırları.
5. `php api/database/plan_limits_selftest.php` → B bölümü yeşil olmalı.

## Faz 3 — kararlar sonrası yapılanlar (2026-10-06)

| İş | Commit | Not |
|---|---|---|
| Doğrulama çıkış kodu kuralı (`pipefail`) CLAUDE.md'ye eklendi; plan testinin B bölümü çıkış kodunu bozmuyor (`--strict` ile dahil) | `ef88145` | |
| S14 — 750 ₺ Üretici kartı, modalı, iki uç nokta, `producer_plan.php`, `PRODUCER_*` kaldırıldı | `f4e0560` | Üç yerde çağıran araması yapıldı; README ve BLOCKERS B2 (bulgu, kapatılmadı) güncellendi |
| Madde 11 + NULL = sınırsız kodu (önce kırmızı test → uygulama → yeşil) | `cd02a62` | **N-07 kapandı** (özele çekme bağımsız limitine takılmıyor, özel yapma hakkından düşüyor). Chatbotlarım kartına "Özel Yap" düğmesi eklendi (canlı arayüzde yayından kaldırma yoktu). |
| 012 onaylandı, `migrations/`'a taşındı, **yerelde uygulandı** | `c260b8b` | Plan testi C (kullanım sayımı, rollback'li) yeşil |
| 013 DELETE'siz yeniden yazıldı | `f8e4e89` | **Hâlâ onay bekliyor** (plan değerlerini UPDATE ediyor). `pending/` altında. |

## Faz 3 — canlı uygulama sırası (güncel)

Ön koşul: canlı veritabanının yedeği alınmış olmalı (admin panel → yedek). Tüm adımları kullanıcı çalıştırır; Claude canlıya bağlanmaz.

**Adım 1 — Kod deploy** (`revize/pazaryeri`, en az `f8e4e89`). Bu kod 012 olmadan da çalışır (NULL'a dayanıklı, hak tablosu yoksa eski davranış + log).
- `php api/database/migrate.php --status` → 010 / 011 bekliyorsa ikisi koşullu ALTER'dır, adım 2 ile birlikte uygulanabilir.
- Kontrol: site ve `/dashboard` açılıyor; `/api/wallet/getpricing.php` 200 dönüyor; `/api/chatbot/getchatbotlimits.php` yanıtında `privacy_limit: 1`, `privacy_used: null` var.
- Kontrol: `php api/database/plan_limits_selftest.php` → A/A2/C **0 başarısız** (C'nin kullanım sayımı "012 uygulanmamış" diye atlanır).
- Bilinen geçici durum: 012 gelene kadar "Özel Yap" hak saymaz; `storage/logs/php-error.log`'da `[unpublishChatbot] user_privacy_right_usage yok` satırı görünür. Adım 2'yi bekletmeyin.

**Adım 2 — 012 (şema)**
- `--status` → `012_plan_privacy_right_and_unlimited.sql bekliyor` (⚠ VERİ SİLER işareti OLMAMALI) → `--apply` → `--status` "Her şey güncel".
- Kontrol: `plans.independent_bot_limit` / `public_bot_limit` `IS_NULLABLE = YES`; `plans.privacy_right_limit` var (varsayılan 1); `user_privacy_right_usage` tablosu var (dosyanın sonundaki doğrulama SELECT'leri).
- Kontrol: plan testi C bölümü artık kullanım sayımını çalıştırır ve yeşil; admin → Abonelik'te "Özel yapma hakkı (toplam)" alanı görünür; `getchatbotlimits.php` `privacy_used: 0`.
- Geri alma: dosya başlığındaki ters ifadeler (`DROP COLUMN privacy_right_limit`, `DROP TABLE user_privacy_right_usage`; NULL'ı sıkılaştırmadan önce NULL satırlar sayıya güncellenmeli). DDL örtük commit yapar.

**Adım 3 — 013 (paket verisi) — onaydan sonra**
- Önce: canlıda plan başına özellik satırı sayısını ölç (`SELECT p.name_tr, COUNT(pi.id) FROM plans p LEFT JOIN plan_icerikler pi ON pi.plan_id = p.id GROUP BY p.id`). Yeni liste 4/5/6/6; bir plan bundan fazla satıra sahipse fazlası dokunulmadan kalır ve doğrulama 2 onları listeler.
- Önce: canlıda admin panelden plan fiyat/limitleri elle değiştirildiyse 013 onların üzerine yazar — beklenen bu.
- Dosyayı `pending/` → `migrations/` taşı (commit) → `--status` → `013_plan_catalog_2026_10.sql bekliyor` (⚠ VERİ SİLER işareti OLMAMALI; `--allow-destructive` GEREKMEZ) → `--apply` → `--status`.
- Kontrol: doğrulama 1 paket tablosuyla birebir (Elmas `independent/public = NULL`, haklar 1/3/5/20, Elmas 849); doğrulama 2 **boş**.
- Kontrol: `php api/database/plan_limits_selftest.php --strict` → **çıkış 0** (B bölümü yeşil); `/dashboard/upgrade` ve ana sayfa fiyatları 0 / 149 / 299 / 849 ₺; Elmas planındaki bir kullanıcının `getchatbotlimits.php` yanıtında `*_unlimited: true`.
- Geri alma: plan değerleri için 007'deki değerlerle UPDATE (`privacy_right_limit = 1`); özellik metinleri için 007 BÖLÜM 3 metinleri ilk satırlara yazılır; 013'ün EKLEDİĞİ satırları kaldırmak silme gerektirir (yıkıcı, kullanıcı çalıştırır).

---

# Faz 4 — Bağımsız chatbot yayınlama (madde 10) — ÖNERİ, ONAY BEKLİYOR (2026-10-06)

Sunucu değişikliği **uygulanmadı**. Tam diff: `docs/proposals/faz4-erisim-listeleme.patch` (4 dosya, +94/−24; `git apply --check` temiz). Öncesinde yerelde 013 uygulandı (plan testi `--strict` 44/0) ve B2 kullanıcı talimatıyla kapatıldı.

**Tasarım (GK-1, GK-2):** "ücretsiz herkese açık bot" = `is_independent = 0` ve iki fiyat da boş/0. Fiyat yalnızca `publishChatbot`/`updateChatbotPrice` ile ve en az 100 ₺ yazılabildiği için fiyatlı bir bot bu tanıma giremez. Yeni bir erişim amacı eklenir — `userHasAccess('chat')` = sahip/abone **veya** ücretsiz herkese açık. `'full'` (persona `style_prompt`, eğitim metni `get_training_chunks`) **değişmez**: ücretsiz botların personası ve eğitim metni yalnızca sahip/aboneye kalır.

## Ölçülen önce/sonra matrisi

Kullanıcı U = sahip değil, aktif aboneliği yok. Yerelde ölçüldü (`f4_matrix.php`). Senaryo verisi transaction içinde kuruldu, ROLLBACK yapıldı; kalıcı yazma yok.

| Bot | Vitrin (getPublished) | preview | **chat** (sohbet) | full (persona/eğitim) | getDetail persona |
|---|---|---|---|---|---|
| Özel (`is_independent=1`) | hayır → hayır | hayır → hayır | hayır → hayır | hayır → hayır | yok → yok |
| Ücretsiz herkese açık, yazar satıcı DEĞİL | hayır → **EVET** | hayır → **EVET** | hayır → **EVET** | hayır → hayır | yok → **gizli** |
| Fiyatlı, satıcı aktif | EVET → EVET | EVET → EVET | hayır → hayır | hayır → hayır | gizli → gizli |
| Fiyatlı, satıcı pasif | hayır → hayır | hayır → hayır | hayır → hayır | hayır → hayır | yok → yok |
| Sahibin kendi özel botu (sahip olarak) | — | — | EVET → EVET | EVET → EVET | görünür → görünür |

## Kural bazında önce/sonra

| Kural / uç nokta | Önce | Sonra |
|---|---|---|
| `userHasAccess('preview')` | sahip/abone + (yayında **ve** satıcı aktif) | sahip/abone + yayında **ve** (ücretsiz **veya** satıcı aktif) |
| `userHasAccess('chat')` (yeni) | yok — `generateReply` `'full'` kullanıyordu | sahip/abone + ücretsiz herkese açık. `generateReply` artık bunu kullanıyor |
| `userHasAccess('full')` | sahip/abone | **değişmedi** |
| `getPublished` / `countPublished` / `getSuggested` | yalnızca satıcısı aktif yazarın yayındaki botları (INNER JOIN) | ücretsiz herkese açık (her yazar) + fiyatlı yalnızca satıcı aktifse (LEFT JOIN + koşul) |
| `getBotsOfList` (N-09) | listede yalnızca satıcısı aktif botlar | vitrinle aynı görünürlük |
| `publishChatbot` | fiyat zorunlu (≥100 ₺) + aktif satıcı | fiyatsız → ücretsiz herkese açık, satıcı şartı YOK; fiyatlı → eskisi gibi (fiyat aralığı + aktif satıcı). Herkese açık limiti ikisinde de kilit altında |
| `saveChatbot` (herkese açık oluşturma) | aktif satıcı şartı | şart yok — oluşturulan herkese açık bot her zaman ücretsiz (fiyat alanları burada zaten reddediliyor) |
| `updateChatbotPrice` (N-08) | yalnızca sahiplik | sahiplik + **aktif satıcı** |
| `getDetail` (`getchatbot.php`) | `has_access` = full | `has_access` = chat; `style_prompt` yalnızca full'de |

## Sömürü / kötüye kullanım senaryoları

| # | Senaryo | Sonuç |
|---|---|---|
| E1 | Özel botun sızması: U, özel bir botun id'siyle `getchatbot`/`generatereply` çağırır. | Engelli. Yeni dalların hepsi `is_independent = 0` şartını taşıyor; matriste özel bot her sütunda "hayır". |
| E2 | Fiyatlı botu ücretsiz kullanmak: U, fiyatlı bir botla abonelik olmadan sohbet etmeye çalışır. | Engelli. `chat` dalı iki fiyatın da 0/boş olmasını istiyor; fiyat <100 ₺ yazılamıyor (`assertValidPrice`). Matriste "Fiyatlı, satıcı aktif" → chat hayır. |
| E3 | Ücretsiz botun persona/eğitim metnini çekmek: U, ücretsiz bir botun `style_prompt`'unu `getchatbot.php`'den, eğitim metnini `get_training_chunks.php`'den ister. | Engelli. İkisi de `'full'`'e bağlı ve `'full'` değişmedi (matris: persona gizli, full hayır). Ücretsiz botu `'full'`'e eklemek bu iki sızıntıyı açardı; ayrı `'chat'` amacının nedeni bu. |
| E4 | Satıcı olmayanın fiyat ataması (N-08): ücretsiz botuna fiyat yazıp publish'in satıcı şartını dolaylı atlamak. | Kapanıyor: `updateChatbotPrice` aktif satıcı istiyor. |
| E5 | Limit aşımı: herkese açık limitini ücretsiz yayınla aşmak. | Engelli. Ücretsiz yayın da `getPublicBotLimit` + C-01 kilidinden geçiyor; `saveChatbot` da aynı. |
| E6 | Bedava sınırsız sohbet: ücretsiz bot üzerinden coin harcamadan model kullanmak. | Engelli. `generateReply`'de `consumeMessage` erişim kontrolünden sonra her mesajda çalışıyor; ücretsiz botta kullanıcının günlük coin'i düşüyor (GK-2). |
| E7 | İçerik/spam: satıcı olmayan herkes vitrine bot koyabilir. | **Yeni risk.** `ContentPolicy::assertClean` kayıtta çalışıyor, bildir/gizle var; ama ön moderasyon yok. Herkese açık limiti (ücretsiz plan 2) yığılmayı sınırlıyor. |
| E8 | Ücretsiz bota sepet/abonelik: satıcısı aktif bir yazarın fiyatsız botunu sepete ekleyip 0 ₺ abonelik oluşturmak. | **Kapsam dışı, açık** (ödeme yolu: `addToCart`/`createSubscription`). Bugün `addToCart` yalnızca satıcı aktifliğine bakıyor, fiyata bakmıyor. Öneri ayrı onayla: `addToCart`'ta `ucret_haftalik <= 0` → "Bu bot ücretsiz" reddi; arayüzde ücretsiz botta "Sepete Ekle" gizlenir. |

## Henüz yazılmayan arayüz işleri (sunucu onayından sonra)

- **Madde 2 — oluştur sayfası:** "Pazaryerinde Yayınla" kartının yerine "Herkese Açık Chatbot Oluştur" (ücretsiz, satıcı şartı yok); seller-wizard'lı "State 2" kalkar; "Pazaryerine Kaydet" küçük bağlantı → başvuru sayfası.
- **Madde 10 — `PublishModal`:** iki seçenek. **Yayınla** = fiyatsız `publishChatbot`. **Pazaryerine Kaydet** = "pazaryerinde satış için şirket başvurusu gerekiyor" açıklamalı pop-up → `/dashboard/pazaryeri-basvurusu`. Satıcı durumu yaratmaz, B1'i örtmez.
- **Madde 1 (sihirbaz):** bu iki değişiklikten sonra `SellerOnboardingWizard` hiçbir yerden çağrılmaz (bugün tek çağıranları `create/page.jsx` ve `PublishModal.jsx`). Sihirbazı düzenlemek yerine üç yerde çağıran araması yapılıp ölü kod olarak silinir. Bireysel kayıt yalnızca başvuru sayfasındaki "Yakında" kartı olarak kalır.
- **Vitrin/profil:** fiyatsız bota "Ücretsiz" rozeti; ücretsiz botta "Sepete Ekle" gizli (E8'in arayüz yarısı).

## N-14 — ölçüm

Yerelde platform botu "LUMANORIS AI" (#5) herkese açık ama **50 ₺ fiyatlı** (sahibi `lumanoris`). Matristeki "Fiyatlı, satıcı aktif" satırı bu bot: abonesi olmayan kullanıcı bugün sohbet edemiyor (**şüphe yerelde doğrulandı**) ve önerilen yamadan sonra da edemez. Ana sayfa ise onu herkese varsayılan bot olarak sunuyor. Çözüm bir veri kararı: platform botunun fiyatı kaldırılırsa (`ucret_haftalik/aylik = NULL`) yamadan sonra ücretsiz herkese açık olur ve herkes sohbet edebilir. Bu bir UPDATE, onay gerektirir; canlıdaki durum kullanıcıdan bekleniyor.

## Faz 4 — kontrol A ve B (2026-10-06)

**A — yamadan sonra vitrine yeni çıkacak botlar.** Yerelde **0 satır** (ölçüt: yayında, iki fiyat da boş/0, yazarı aktif satıcı değil). Canlı için salt okunur sorgu aşağıda. Satır dönerse ve bunlar sahiplerinin taslağıysa `api/database/pending/014_unlist_free_public_drafts.sql` (öneri, **uygulanmadı**) onları özele çeker; Faz 4 kodundan ÖNCE çalışmalı, özel yapma hakkı düşmez.

```sql
-- Faz 4 / kontrol A — yamadan sonra vitrine YENİ çıkacak botlar. SALT OKUNUR.
-- Ölçüt: yayında (is_independent = 0), iki fiyat da boş/0 ve bugün listelenmiyor
-- (yazarı aktif satıcı değil). Yazarı aktif satıcı olan fiyatsız botlar bugün de
-- listeleniyor; onlar ayrıca ikinci sorguda.
SELECT c.id, c.isim, c.author_user_id, u.kullanici_adi AS yazar,
       COALESCE(pms.status, 'kayit_yok') AS satici_durumu,
       c.yayimlanma_tarih, c.edit_tarih,
       CHAR_LENGTH(COALESCE(c.training_prompt, '')) AS egitim_karakter,
       (SELECT COUNT(*) FROM chatbot_chats ch WHERE ch.chatbot_id = c.id) AS mesaj,
       (SELECT COUNT(*) FROM user_subscriptions us WHERE us.chatbot_id = c.id) AS abonelik
FROM chatbotlar c
LEFT JOIN kullanicilar u ON u.id = c.author_user_id
LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id
WHERE c.is_independent = 0
  AND COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0
  AND COALESCE(pms.status, '') <> 'active'
ORDER BY c.id;

-- Bilgi: bugün DE listelenen fiyatsız botlar (yazar aktif satıcı) — davranışları
-- değişmez ama yamadan sonra herkes ücretsiz sohbet edebilir.
SELECT c.id, c.isim, u.kullanici_adi AS yazar
FROM chatbotlar c
JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id AND pms.status = 'active'
LEFT JOIN kullanicilar u ON u.id = c.author_user_id
WHERE c.is_independent = 0
  AND COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0
ORDER BY c.id;
```

**B — sohbet sayfasının çağırdığı uç noktalar** (sayfa + ProfileCard + DialogNotebookModal, 21 uç nokta). Ücretsiz herkese açık bot için:

| Uç nokta | Erişim kontrolü (önce) | Sonuç |
|---|---|---|
| `chatbot/getchatbot.php` | `getDetail`: full veya preview (preview satıcı aktif istiyordu) | Bugün ücretsiz bota **404** → sayfa açılmıyordu. Yamayla preview/chat. Persona artık **yalnızca sahibine** (sohbet persona kullanmıyor; tek okuyan oluştur/düzenle sayfası). |
| `chat/generatereply.php` | `userHasAccess(full)` | Sohbeti **kırıyordu** → yamada `chat`. Persona/eğitim metni yalnızca sunucuda modele gidiyor, istemciye dönmüyor. |
| `chat/getchat.php`, `getconversation.php`, `addconversation.php`, `addchat.php`, `gethistory.php` | yalnızca oturum; satırlar `user_id` ile kapsanıyor | Erişim türü istemiyor; sohbeti kırmıyor. Persona dönmüyor. |
| `message/checkmessageallowance.php` | yalnızca oturum | Coin bakiyesi; sorun yok. |
| `wallet/getsubscription.php` | yalnızca oturum, kullanıcının kendi aboneliği | Sayfadaki `hasSubscription` kapısı yorum satırında; sohbeti engellemiyor. |
| `content/getadcounts.php`, `auth/sessioncheck.php` | herkese açık / oturum | İlgisiz. |
| ProfileCard: `getcart`, `getuserlists`, `getuserbotstatus`, `like/dislike/follow/addcomment/adduninterest` | oturum, kullanıcıya kapsanmış | İlgisiz; persona dönmüyor. |
| ProfileCard: `marketplace/addtocart.php` | satıcı aktif mi | E8 → adım 4 (ücretsiz bot reddi). |
| `note/adddialogbook.php` | yalnızca oturum | İlgisiz. |

Sonuç: full isteyen ve sohbeti kıran tek uç nokta `generatereply.php` (yamada düzeldi); `getchatbot.php` ücretsiz bota 404 veriyordu (yamada düzeldi) ve personayı abonelere de döndürüyordu (sahiple sınırlandı). Yama `8ebb757` ile uygulandı; yeni `access_selftest.php` 21/0 (eski kodla 4 hata).

## GK-18 ve N-14 (2026-10-06)

| # | Karar | Etkilenen iş |
|---|---|---|
| GK-18 | **Geliştirici kararı (müşteri onayı yok, 2026-10-06):** platform botu "Lumanoris AI" ücretsiz herkese açık olur (fiyatı kaldırılır); herkes sohbet eder, mesaj kullanıcının günlük coin'inden düşer. | N-14, ana sayfa varsayılan botu (GK-13) |

**N-14 — kapandı (yerel).** `015_house_bot_free.sql` yerelde uygulandı (öncesi/sonrası `--status`; DB_HOST yerel). Önceki fiyat (geri alma için): bot #5, haftalık **50,00 ₺**, aylık **150,00 ₺**. Sonra: sahibi/abonesi olmayan kullanıcı `chat = EVET`, `full = hayır`, vitrinde. Migration yalnızca tam olarak BİR "LUMANORIS AI" herkese açık bot varsa günceller. Canlıda uygulamadan önce aynı `SELECT` ile eski fiyat not alınmalı; aktif abonelikler dokunulmadan sürer.

Koşullu 014 (öneri) platform botunu hariç tutacak şekilde güncellendi: aksi hâlde yazarı aktif satıcı olmayan bir kurulumda 015'in ücretsiz yaptığı platform botunu özele çekerdi.

Doğrulama notu: bu turda iyzico öz-testinin B bölümü (sandbox ağ çağrısı) bir kez `Could not resolve host` ile düştü (aynı anda git push da DNS hatası verdi); kural gereği commit beklendi, yeniden çalıştırmada 77/0.

## Faz 4 — yapılanlar (2026-10-06)

| İş | Commit | Not |
|---|---|---|
| Kontrol A/B | `5be4668` | A yerelde 0 satır; B: sohbeti kıran tek uç nokta `generatereply.php` |
| Erişim/listeleme yaması + persona yalnızca sahibe | `8ebb757` | **N-08, N-09 kapandı.** `access_selftest.php` 21/0 (eski kodla 4 hata) |
| N-14 — platform botu ücretsiz (GK-18, 015) | `908f452` | **N-14 kapandı (yerel).** Eski fiyat 50/150 ₺ kayıtlı |
| E8 — `addToCart` ücretsiz bot reddi | `ceedebf` | Ödeme yolu, kullanıcı talimatıyla |
| Arayüz: Herkese Açık kartı, PublishModal iki seçenek, Ücretsiz rozeti, Sepete Ekle gizli | `7c1a301` | Madde 2 kartı + madde 10 |
| Madde 1 — `SellerOnboardingWizard` silindi | `03f1a9d` | **Madde 1 tamam.** Üç yerde çağıran araması |

Doğrulama her commit'te: lint, verify build, php -l, iyzico 77/0, `plan_limits_selftest --strict` 44/0, `access_selftest` 21/0. **Tarayıcıda denenmedi** — kullanıcı tüm fazları Faz 4 sonunda tek seferde elle test edecek.

### Faz 4'te bulunan / açık kalan

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-15** | P3 | `entities/chatbot/ui/BotCard.bento.jsx:158` | Puanı olmayan her bota sabit **"4.9"** yıldız gösteriliyor (`bot.rating \|\| "4.9"`) — uydurma veri (N-13 ile aynı aile). | Açık — Faz 4'te görüldü, kapsam dışı. |
| **N-16** | P2 | `MarketplaceController::createSubscription` (ödeme yolu) | Fiyatı sonradan kaldırılan bir bot (ör. 015 sonrası platform botu) eski bir sepet satırında kalmışsa `createSubscription` onu güncel fiyattan (0 ₺) işleyebilir. `addToCart` artık ücretsiz botu reddediyor ama mevcut sepet satırlarını kapsamıyor. Yerelde ücretsiz bot içeren sepet satırı yok; canlıda olabilir (platform botu 50 ₺'yken sepete eklenmiş olanlar). | **Açık — ödeme yolu, onay gerekiyor.** Öneri: `createSubscription`'da fiyatı 0 olan kalemi sepetten düşüp reddetmek. |
| N-11 (ek) | P3 | `shared/hooks/useSellerStatus.js`, `api/api/seller/submerchant_register.php` | Sihirbaz silindikten sonra hook'un tek kullanıcısı zaten ölü `features/wallet/BankInfo.jsx`; `submerchant_register.php`'nin web'den çağıranı kalmadı. | Ölü kod adayı — silinmedi. |

### Canlı uygulama sırası (Faz 4) — Faz 3 sırasının devamı

1. **Kontrol A** (AUDIT "Faz 4 — kontrol A ve B" sorgusu) canlıda çalıştırılır. Satır dönerse ve taslaksa: `014_unlist_free_public_drafts.sql` `pending/` → `migrations/`, **kod deploy'undan ÖNCE** `--apply`. Kontrol: dosya sonundaki `kalan = 0`.
2. **015 öncesi not:** `SELECT id, ucret_haftalik, ucret_aylik FROM chatbotlar WHERE UPPER(TRIM(isim)) = 'LUMANORIS AI'` çıktısı saklanır (geri alma).
3. **Kod deploy** (en az `03f1a9d`). Kontrol: `php api/database/access_selftest.php` 0 başarısız; ücretsiz herkese açık bir bot sahibi/abonesi olmayan hesapla açılıp mesaj gönderilebiliyor ve coin düşüyor; fiyatlı bot abonelik olmadan sohbet etmiyor; `getchatbot.php` yanıtında sahibi olmayan için `style_prompt` yok.
4. **015** `--apply`. Kontrol: doğrulama SELECT'inde `eslesen = 1`, fiyat NULL; sıradan hesapla Lumanoris AI sohbeti çalışıyor (N-14).
5. **N-16 kararı** verilene kadar: canlıda `SELECT uc.* FROM user_cart uc JOIN chatbotlar c ON c.id = uc.chatbot_id WHERE c.is_independent = 0 AND COALESCE(c.ucret_haftalik,0) = 0 AND COALESCE(c.ucret_aylik,0) = 0` ile ücretsiz botu tutan sepet satırları kontrol edilmeli.

## Faz 4 kapanış düzeltmeleri (2026-10-06)

| ID | Commit | Durum |
|---|---|---|
| **N-16** | `1351119` | **Bulgu YANLIŞTI — düzeltme.** `createSubscription` kalem fiyatı `<= 0` ise ödemeyi zaten reddediyordu ("Bu chatbot için geçerli bir fiyat tanımlanmamış."); 0 ₺ işleme riski yoktu. Bulgu o satır okunmadan yazılmıştı. Yapılan: ücretsizleşmiş bot için mesaj netleştirildi ("… artık ücretsiz; … sepetinizden çıkarın"). |
| **N-15** | `d8f3ff4` | **Kapandı.** Uydurma puan beş yerdeydi: ana sayfa + Keşfet eşlemesi (`rating: 4.9`), bento kart ve Takip Edilenler (iki yer) `|| "4.9"`. Aynı ailede Takip Edilenler takipçi sayısı `|| "1.2k"` (iki yer). Hiçbir API puan döndürmüyor; puan yalnızca varsa çiziliyor, takipçi gerçek değer. Not: Takip Edilenler'deki "puana göre sırala" seçeneği veri olmadığı için etkisiz kalıyor. |
| **N-11** (kısmi) | `cfd7554` | `useSellerStatus.js` ve tek kullanıcısı ölü `features/wallet/BankInfo.jsx` silindi (üç yerde çağıran araması). `submerchant_register.php` kullanıcı kararıyla kaldı; BLOCKERS B1'e not. Kalan N-11 adayları: `ChatbotForm.jsx`, `features/notes/DialogueModal.jsx`, `entities/chatbot/ui/{ChatbotCard,BotCard,MarketplaceListCard,SuggestedCard}.jsx`, `features/purchasing/BuyModal.jsx` (tek importeri ölü `entities/chatbot/ui/ChatbotCard.jsx`). |

**Etiket:** `faz4-tamam` bu commit'ten sonra atıldı. Canlıya bu etiket alınacak; uygulama sırası Faz 3 + Faz 4 "canlı uygulama sırası" bölümlerinde (madde 5 — N-16 sepet kontrolü — artık gerekmiyor, yalnızca kullanıcıya açık mesaj için bilgi amaçlı).

---

# Faz 5 — Pazaryeri Başvurusu backend (madde 3) — ŞEMA + API SÖZLEŞMESİ ÖNERİSİ, ONAY BEKLİYOR (2026-10-06)

Kod yazılmadı. Şema: `api/database/pending/016_marketplace_applications.sql` (uygulanmadı).

## Şema özeti

`marketplace_applications`: kullanıcı başına tek satır (`UNIQUE user_id`, FK `kullanicilar` ON DELETE CASCADE). Alanlar GK-7 (unvan, vergi no, vergi dairesi, MERSİS, yetkili ad/soyad/doğum tarihi, IBAN, il/ilçe/adres) + `account_type` (`sahis` / `kurumsal`, GK-8) + `status` (`submitted` → `reviewed` | `rejected`) + `review_note`, `reviewed_by_admin_id`, `reviewed_at`. `active` bu tabloda yok — satıcı aktifliği `param_marketplace_sellers`'ta ve B1'e bağlı (GK-9).

## API sözleşmesi (yeni, geriye dönük kırıcı değişiklik yok)

| Uç nokta | Yetki | İstek | Yanıt |
|---|---|---|---|
| `POST /api/seller/application_submit.php` | kullanıcı oturumu; `checkRateLimit('mapp_submit:<uid>', 5, 3600)` | `FormData data` = JSON `{account_type, company_title, tax_number, tax_office, mersis_no?, authorized_first_name, authorized_last_name, authorized_birth_date (YYYY-AA-GG), iban, il, ilce, address}`. `InputSanitizer::pickAllowed` beyaz listesi; listede olmayan alan → 400. | `{success, application:{status:"submitted", submitted_at}}`. Hatalar: 400 doğrulama (alan bazında mesaj), 409 durum `reviewed` iken yeniden gönderim (S-3), 429 hız sınırı. |
| `GET /api/seller/application_status.php` | kullanıcı oturumu | — | `{success, application: null \| {status, account_type, company_title, iban_masked:"TR** **** … 1234", submitted_at, reviewed_at, review_note}}`. Doğum tarihi, tam IBAN, vergi no **dönmez**. |
| `/admin/basvurular` (sayfa) | `_guard.php` admin oturumu | liste (durum filtresi) + detay (tam alanlar) | — |
| `POST /admin/ajax/basvuru_durum.php` | admin + CSRF (`parcekme` deseni) | `{id, status: "reviewed" \| "rejected", review_note}` | `{success}`. Genel CRUD (`update.php`) bu tabloya açılmaz — tablo `assertAllowedAdminTable` beyaz listesine EKLENMEZ. |

**Doğrulama:** `account_type ∈ {sahis, kurumsal}`. Vergi no: kurumsal → `BankIdentity::normalizeVkn` (10 hane); şahıs → 11 haneyse `normalizeTckn`, 10 haneyse `normalizeVkn` (S-6). MERSİS: 16 hane rakam. IBAN: `BankIdentity::normalizeIban` (TR + mod97). Doğum tarihi geçerli tarih ve ≥ `AppConfig::MIN_REGISTRATION_AGE` (18). Metin alanları `InputSanitizer::text` + uzunluk sınırı. `ContentPolicy` uygulanmaz (ticari kimlik verisi). Hata log'larında alan değerleri yazılmaz.

**Tek "kaydı var mı" kaynağı:** `useMarketplaceRegistration` (Faz 2) artık `application_status.php`'yi de okur. Kayıt sayılan: başvuru durumu S-4'teki küme **veya** eski `param_marketplace_sellers` durumları (`pending/active/suspended/rejected`, geriye uyum). Sunucuda karşılığı `hasMarketplaceRegistration(Database, int): bool` (`functions/` altında), endpoint ve ileride sunucu kapıları aynı yerden okur.

**Arayüz:** Faz 2 iskeleti bu uca bağlanır: gönder düğmesi açılır, gönderim sonrası "Başvurunuz alındı / inceleniyor" (sahte başarı yok; yanıt gelmeden mesaj yok). Mevcut başvuru varsa form salt-okunur özet + durum gösterir; `rejected` ise gerekçe + yeniden gönderim. README API tablosuna iki uç nokta eklenir.

## Karar soruları

| # | Soru | Kodun ima ettiği / önerim |
|---|---|---|
| S-1 | Şema (016) ve yukarıdaki API sözleşmesi onaylı mı? | — |
| S-2 | Başvuru geçmişi tutulsun mu? | Öneri: hayır — kullanıcı başına tek satır, yeniden gönderim üzerine yazar (`updated_at`). Geçmiş gerekirse ayrı log tablosu (ek şema). |
| S-3 | `reviewed` ("incelendi") olan başvuru kullanıcı tarafından değiştirilebilir mi? | Öneri: hayır (409, "değişiklik için destekle iletişime geçin"); yalnızca `submitted` ve `rejected` yeniden gönderilebilir. |
| S-4 | GK-5 "başvuru yapmış" — `rejected` başvuru Bakiyem'i açar mı? | Öneri: `submitted` ve `reviewed` açar, `rejected` açmaz. |
| S-5 | Başvurudaki IBAN para çekmede kullanılsın mı? Bugün çekim yalnızca `banka_bilgileri.iban`'a yapılabiliyor (COMP-005b) ve M7 sonrası kullanıcının IBAN girebileceği arayüz yok. | Öneri: admin "incelendi" dediği anda başvurudaki IBAN `banka_bilgileri.iban`'a yazılsın (gönderimde değil — incelenmemiş IBAN'a para gitmesin). Bu, mevcut `banka_bilgileri` satırının üzerine yazmak demek. |
| S-6 | Şahıs şirketinde vergi no: TCKN (11) mi, VKN (10) mu? MERSİS şahısta zorunlu mu? | Öneri: şahısta ikisi de kabul; MERSİS kurumsalda zorunlu, şahısta isteğe bağlı (ticaret siciline kayıtlı olmayan şahıs işletmesi var). |
| S-7 | Başvuru sonucu kullanıcıya nasıl bildirilsin? | Öneri: uygulama içi bildirim (`notifications` tablosu mevcut); e-posta B5 (SMTP) açık olduğu için yok. |
| S-8 | Admin "Başvurular" sayfasında tam IBAN / doğum tarihi görünsün mü? | Öneri: evet (inceleme için gerekli), ama sayfa erişimi yalnızca admin oturumu; dışa aktarma yok. |

## Faz 5 kararları — geliştirici kararı (müşteri onayı yok, 2026-10-06)

| # | Karar | Etkilenen iş |
|---|---|---|
| GK-19 (S-1) | 016 şeması ve API sözleşmesi onaylandı; 016 yerelde uygulanır. | Faz 5 |
| GK-20 (S-2) | Başvuru geçmişi tutulmaz; kullanıcı başına tek satır, yeniden gönderim üzerine yazar. | 016, submit ucu |
| GK-21 (S-3) | `reviewed` başvuru kullanıcı tarafından değiştirilemez (409); yalnızca `submitted` / `rejected` yeniden gönderilir. | submit ucu |
| GK-22 (S-4) | "Kaydı var" tek kaynağı = yeni tabloda `submitted` / `reviewed` **VEYA** eski `param_marketplace_sellers`'ta `active` / `suspended`. Eski tablodaki `rejected` (B1 kaynaklı otomatik ret) ve `pending` SAYILMAZ. **Davranış değişikliği:** Faz 2'deki hook `pending` ve `rejected`'ı da sayıyordu. | `useMarketplaceRegistration`, `hasMarketplaceRegistration`, Bakiyem kapısı (madde 4) |
| GK-23 (S-5) | Admin "incelendi" dediğinde başvurudaki IBAN `banka_bilgileri.iban`'a yazılır. Şartlar: onay ekranında mevcut ve yeni IBAN yan yana; kullanıcının `beklemede` para çekme talebi varsa IBAN güncellemesi engellenir ve admin'e nedeni gösterilir; IBAN değişikliği (kim, ne zaman, eski/yeni maskeli) kalıcı kayda geçer. **Mevcut bir admin işlem logu YOK** (iade denetimi bile yalnızca `error_log`'da) → yeni tablo önerisi, **onay bekliyor**; onaya kadar IBAN aktarımı yapılmaz. | admin Başvurular, 017 (öneri) |
| GK-24 (S-6) | Şahısta vergi no TCKN (11) veya VKN (10); MERSİS kurumsalda zorunlu, şahısta isteğe bağlı. | submit doğrulaması |
| GK-25 (S-7) | Uygulama içi bildirim altyapısı VAR (`notifications` tablosu + başlıktaki bildirim kutusu): inceleme sonucu kullanıcıya oraya yazılır; ayrıca başvuru sayfasında durum gösterilir. Yeni sistem kurulmadı. | admin durum ucu, başvuru sayfası |
| GK-26 (S-8) | Admin Başvurular sayfasında tam IBAN ve doğum tarihi görünür; yalnızca admin oturumu; dışa aktarma yok. | admin Başvurular |

## Faz 5 — yapılanlar (2026-10-06)

| İş | Commit | Not |
|---|---|---|
| 016 uygulandı (yerel) | `ef2e002` | `marketplace_applications` |
| Başvuru backend: doğrulama, kayıt, "kaydı var" | `e7991b6` | `application_selftest.php` önce kırmızı (dosya yok), sonra 30/0 |
| Başvuru sayfası backend'e bağlı; hook sunucu kuralına (GK-22) | `8e50e96` | **Davranış değişikliği:** eski tablodaki `pending`/`rejected` artık "kaydı var" sayılmıyor |
| Admin Başvurular | `7fefa7a` | GK-23 IBAN aktarımı **kapalı** — 017 onay bekliyor |
| README | `429d83d` | aşağıda Faz 6 madde 4 |
| 017 admin işlem logu önerisi | bu commit | `api/database/pending/017_admin_audit_log.sql` — UYGULANMADI |

---

# Faz 6 — Kapanış raporu (2026-10-06)

## 1. Doğrulama komutları (CLAUDE.md, son çalıştırma)

`npm run lint` ✔ · `NEXT_DIST_DIR=.next-verify npm run build` ✔ · `php -l` (tüm api) ✔ · `iyzico_selftest.php` 77/0 · `plan_limits_selftest.php --strict` 44/0 · `access_selftest.php` 21/0 · `application_selftest.php` 30/0. Hepsi çıkış kodu 0. **Tarayıcıda uçtan uca test yapılmadı** — kullanıcı tüm fazları elle test edecek.

## 2. Madde bazında durum

Durumlar: **tamam** · **onay bekliyor** · **blocker'a bağlı (Bn)** · **veri bekliyor**. Blocker'a bağlı madde "çalışıyor" ilan edilmedi.

| Madde | Durum | Not |
|---|---|---|
| M0-1 belge ile eğitim | tamam | N-02, sihirbazda kalma, PDF 5 MB. Sohbetin gerçek cevabı **B4**'e (Gemini prod anahtarı) bağlı. |
| M0-2 bağımsız bota tıklayınca sohbet | tamam | N-01 |
| M0-3 Liste sayfası 500 | (b) tamam · (a) **veri bekliyor** | N-04 hata/boş durum ayrımı; 500'ün kök nedeni (N-03, büyük olasılıkla migration 009) canlı `migrate.php --status` bekliyor. |
| M0-4 defter profil butonu | tamam | N-05, N-06; mevcut bozuk kayıtlar için salt okunur teşhis SELECT'i AUDIT'te, onarım yapılmadı. |
| 1 Bireysel kayıt "Yakında" | tamam | Başvuru sayfasında "Yakında"; eski sihirbaz silindi. |
| 2 Herkese Açık Chatbot Oluştur | tamam · sınırsız hak **B1** | Kart + sunucu limiti; onaylı satıcıya sınırsız hak B1 açıkken kimseye uygulanamıyor. |
| 3 Pazaryeri Başvurusu | tamam · IBAN aktarımı **onay bekliyor** · satış **B1** | Başvuru/inceleme çalışıyor; "incelendi" satıcıyı aktif yapmaz (GK-9). GK-23 IBAN aktarımı 017'ye bağlı. |
| 4 Bakiyem kapısı | tamam | GK-22 kuralı sunucuda; yalnızca arayüz kapısı. |
| 5 Satın Aldıklarım görünürlüğü | tamam · pratikte **B1/B3** | Satın alma B1 ve B3 yüzünden yapılamadığı için menü kimsede görünmez. |
| 6 Ana sayfa seçici | tamam | Erişimsiz bot kilitli; platform botu ücretsiz (N-14, 015). |
| 7 Ayarlar → Banka → Başvuru | tamam | Yeni IBAN girişi başvuruda; çekime aktarım GK-23/017'ye bağlı. |
| 8 Günlük 10 coin | doğrulandı | Değişmedi. |
| 9 Sohbet sayfası "Satın Al" | tamam | Limit bandında "Paketini yükselt". |
| 10 Yayınla / Pazaryerine Kaydet | tamam · ücretli satış **B1** | Ücretsiz herkese açık yayın çalışıyor. |
| 11 Özel yapma hakkı | tamam | 012 + kod; N-07 kapandı. |
| P Paketler | tamam · satın alma **B3** | 013 kataloğu; paket satın alma ödeme yolu B3 (iyzico reddi). |

Açık: N-03 (veri bekliyor), N-06 mevcut kayıt onarımı (karar bekliyor), N-11 kalan ölü kod adayları, 017 (onay bekliyor). Kapanan: N-01, N-02, N-04, N-05, N-06 (yeni kayıtlar), N-07, N-08, N-09, N-10, N-12, N-13, N-14 (yerel), N-15. N-16 yanlış bulguydu.

## 3. Uygulanmamış migration'lar ve canlı sırası

Yerelde uygulananlar: 010, 011, 012, 013, 015, 016. Canlıda hiçbiri uygulanmadı (Claude canlıya bağlanmaz).

| Sıra | Dosya | Tür | Ne zaman / kontrol |
|---|---|---|---|
| 0 | yedek | — | Canlı veritabanının yedeği. |
| 1 | `pending/014_unlist_free_public_drafts.sql` | veri (UPDATE), **koşullu** | Yalnızca "Faz 4 — kontrol A" sorgusu satır döndürür ve onaylanırsa; **koddan ÖNCE**. Kontrol: `kalan = 0`. |
| 2 | 015 öncesi not | — | Platform botunun mevcut fiyatı (geri alma için). |
| 3 | **Kod deploy** | — | `faz4-tamam` etiketi Faz 5'i İÇERMEZ; Faz 5 dahil alınacaksa yeni etiket gerekir. Kontrol: dört öz-test 0 başarısız (`plan_limits --strict` 013'ten önce B'de kırmızı verir — normal). |
| 4 | `010_…`, `011_…` | şema (koşullu ALTER) | Canlıda bekliyorsa. |
| 5 | `012_plan_privacy_right_and_unlimited.sql` | şema | Koddan SONRA. Kontrol: kolonlar, `user_privacy_right_usage`. |
| 6 | `013_plan_catalog_2026_10.sql` | veri (UPDATE + INSERT; DELETE yok) | Kontrol: `plan_limits_selftest.php --strict` çıkış 0; doğrulama 2 boş. |
| 7 | `015_house_bot_free.sql` | veri (UPDATE) | Kontrol: `eslesen = 1`; sıradan hesapla Lumanoris AI sohbeti. |
| 8 | `016_marketplace_applications.sql` | şema | Kontrol: `application_selftest.php` 30/0; başvuru sayfası 503 vermiyor. |
| — | `pending/017_admin_audit_log.sql` | şema, **onay bekliyor** | Onaylanırsa 016'dan sonra + IBAN aktarım kodu. |

Geri alma notları her dosyanın başlığında. Hiçbiri `--allow-destructive` gerektirmez.

## 4. README kontrolü

Güncellendi (`429d83d`): rota tablosu (`/dashboard/pazaryeri-basvurusu`), seller API tablosu (2 yeni uç), hız sınırı (`mapp_submit`), çağıransız uçlar (`submerchant_register.php`), yetkilendirme (`full` / `chat` / `preview`, persona yalnızca sahibe), admin Başvurular, `MIN_WEEKLY_PRICE` (README 1 ₺ diyordu, kod 100 ₺), `FREE_PRIVACY_RIGHT_LIMIT`, paket kataloğu + NULL = sınırsız + özel yapma hakkı, migration aralığı 001–016 + `pending/`, yeni öz-testler, coin kademeleri atfı (`BuyModal` → `pricing.js`). Üretici paketi satırları S14'te kaldırılmıştı.

## 5. Değiştirilen dosyalar (main..revize/pazaryeri)

| Dosya | Ne değişti | Madde / ID | Doğrulama |
|---|---|---|---|
| `.claude/settings.json` | allow (git add, revize push, migrate) + deny listesi; parolalı kural silindi | kural | geçerli JSON |
| `AUDIT.md` | Faz 0–6 kayıtları, GK-1…26, N-01…N-16 | kayıt | elle |
| `BLOCKERS.md` | B1 notları, B2 kapandı (kullanıcı talimatı) | B1, B2 | elle |
| `CLAUDE.md` | git/migration/faz akışı kuralları, pipefail, öz-test listesi | kural | elle |
| `README.md` | bkz. madde 4 | Faz 6 | elle |
| `api/admin/abonelik.php` | özel yapma hakkı alanı, boş kota = sınırsız | M11 | php -l |
| `api/admin/ajax/basvurular.php` | yeni: başvuru listesi + incele/reddet + bildirim | M3, GK-21/23/25/26 | php -l; oturumsuz 403 |
| `api/admin/basvurular.php` | yeni: admin Başvurular sayfası | M3 | php -l |
| `api/admin/index.php` | `/admin/basvurular` rotası | M3 | php -l |
| `api/admin/partials/_sidebar.php` | Başvurular bağlantısı | M3 | php -l |
| `api/api/marketplace/buyproduceraccount.php` | silindi | S14 | 3'lü çağıran araması |
| `api/api/marketplace/getproducerplanstatus.php` | silindi | S14 | 3'lü çağıran araması |
| `api/api/seller/application_status.php` | yeni uç | M3 | php -l; oturumsuz 401 |
| `api/api/seller/application_submit.php` | yeni uç | M3 | php -l; oturumsuz 401, GET 405 |
| `api/database/access_selftest.php` | yeni: erişim/listeleme matrisi testi | M10 | 21/0 (eski kodla 4 hata) |
| `api/database/application_selftest.php` | yeni: başvuru testi | M3 | 30/0 (önce kırmızı) |
| `api/database/migrations/012_…` | şema: NULL = sınırsız, hak kolonu/tablosu | M11 | yerelde uygulandı |
| `api/database/migrations/013_…` | paket verisi (DELETE'siz) | P | yerelde uygulandı; plan --strict 44/0 |
| `api/database/migrations/015_house_bot_free.sql` | platform botu ücretsiz | N-14, GK-18 | yerelde uygulandı |
| `api/database/migrations/016_…` | başvuru tablosu | M3, GK-19 | yerelde uygulandı |
| `api/database/pending/014_…` | koşullu özele çekme | Faz 4 kontrol A | yazıldı, uygulanmadı |
| `api/database/pending/017_admin_audit_log.sql` | admin işlem logu önerisi | GK-23 | yazıldı, uygulanmadı |
| `api/database/plan_limits_selftest.php` | yeni: plan/kota testi | P, M2, M11 | 44/0 `--strict` |
| `api/functions/chatbot_limits.php` | onaylı satıcıya sınırsız, NULL = sınırsız, hak yardımcıları | M2, M11 | php -l; plan testi |
| `api/functions/marketplace_application.php` | yeni: doğrulama / kayıt / "kaydı var" | M3, GK-20…24 | application_selftest |
| `api/functions/plans.php` | hak geri düşüşü | M11 | plan testi |
| `api/functions/producer_plan.php` | silindi | S14 | 3'lü çağıran araması |
| `api/src/Infrastructure/Repositories/ChatbotRepository.php` | `chat` erişimi, vitrin görünürlüğü, persona yalnızca sahibe | M10, N-09 | access 21/0 |
| `api/src/Presentation/Controllers/ChatController.php` | `chat` erişimi; anahtar yokken belge hakkı iadesi | M10, N-12 | php -l; access |
| `api/src/Presentation/Controllers/ChatbotController.php` | ücretsiz yayın, oluşturmada satıcı şartı yok, fiyata satıcı şartı, özel yapma hakkı, limit yanıtı | M2, M10, M11, N-07, N-08 | php -l; plan + access |
| `api/src/Presentation/Controllers/MarketplaceApplicationController.php` | yeni: submit / status | M3 | application_selftest; 401/405 |
| `api/src/Presentation/Controllers/MarketplaceController.php` | üretici metotları silindi; ücretsiz bot sepete eklenmez; ücretsizleşmiş bot mesajı | S14, E8, N-16 | php -l; iyzico 77/0 |
| `api/src/Presentation/Controllers/SocialController.php` | listelerde vitrin görünürlüğü | N-09 | php -l |
| `api/src/Presentation/Controllers/TrainingController.php` | PDF sınırı 5 MB | F1-3 | php -l |
| `api/src/Presentation/Controllers/WalletController.php` | getPricing NULL'a dayanıklı + hak | M11 | php -l |
| `api/src/Shared/Constants/AppConfig.php` | PRODUCER_* silindi; hak ve tablo sabitleri | S14, M11 | php -l |
| `docs/prompts/11-pazaryeri-revizyon.md` | görev prompt'u | — | — |
| `docs/proposals/faz4-erisim-listeleme.patch` | Faz 4 onaylı diff (tarihçe) | M10 | git apply --check |
| `web/src/app/components/landing/PricingSection.jsx` | fiyatlar getpricing'den; yıllık indirim kaldırıldı | N-10 | lint + build |
| `web/src/app/dashboard/chat/page.jsx` | deftere bot id; "Paketini yükselt"; ölü importlar | N-06, M9 | lint + build |
| `web/src/app/dashboard/chatbots/components/ChatbotCard.jsx` | sohbete bağlantı, "Özel Yap", fiyat düzenleme yalnızca fiyatlıda | N-01, M11, M10 | lint + build |
| `web/src/app/dashboard/chatbots/create/page.jsx` | KB kapısı, sihirbazda kalma, 5 MB, kapasite, Üretici kartı ve State 2 silindi, Herkese Açık kartı | N-02, F1-2/3, M2, S14, M10 | lint + build |
| `web/src/app/dashboard/explore/page.jsx` | uydurma puan kaldırıldı | N-15 | lint + build |
| `web/src/app/dashboard/following/page.jsx` | uydurma puan/takipçi kaldırıldı | N-15 | lint + build |
| `web/src/app/dashboard/list/page.jsx` | yükleme / hata / boş durum | N-04 | lint + build |
| `web/src/app/dashboard/notes/page.jsx` | profil butonu → sohbet | N-05 | lint + build |
| `web/src/app/dashboard/page.jsx` | takip edilenlerden seçici; uydurma puan | M6, N-15 | lint + build |
| `web/src/app/dashboard/pazaryeri-basvurusu/layout.jsx` | yeni rota | M3 | build |
| `web/src/app/dashboard/pazaryeri-basvurusu/page.jsx` | başvuru formu backend'e bağlı | M1, M3 | lint + build |
| `web/src/app/dashboard/settings/page.jsx` | Ödeme Bilgileri → başvuru | M7 | lint + build |
| `web/src/app/dashboard/wallet/components/WithdrawalModal.jsx` | IBAN yönlendirme metni | M7 | lint + build |
| `web/src/app/dashboard/wallet/page.jsx` | uydurma sipariş kaldırıldı; Bakiyem kapısı | N-13, M4 | lint + build |
| `web/src/entities/chatbot/ui/BotCard.bento.jsx` | uydurma puan kaldırıldı | N-15 | lint + build |
| `web/src/entities/user/ui/ProfileCard.jsx` | "Satın Al" kaldırıldı; Ücretsiz rozeti; sepet gizli | M9, M10 | lint + build |
| `web/src/features/chatbot-mgmt/PublishModal.jsx` | Yayınla / Pazaryerine Kaydet | M10 | lint + build |
| `web/src/features/purchasing/BuyProducerAccountModal.jsx` | silindi | S14 | 3'lü çağıran araması |
| `web/src/features/seller/SellerOnboardingWizard.jsx` | silindi | M1 | 3'lü çağıran araması |
| `web/src/features/wallet/BankInfo.jsx` | silindi (ölü) | N-11 | 3'lü çağıran araması |
| `web/src/features/wallet/WithdrawalModal.jsx` | IBAN yönlendirme metni | M7 | lint + build |
| `web/src/shared/hooks/useMarketplaceRegistration.js` | yeni → sunucu kuralı (GK-22) | M4, M3 | lint + build |
| `web/src/shared/hooks/useSellerStatus.js` | silindi | N-11 | 3'lü çağıran araması |
| `web/src/widgets/DashboardHeader.jsx` | kayıtsıza bakiye gizli | M4 | lint + build |
| `web/src/widgets/Sidebar.jsx` | Satın Aldıklarım kapısı; kayıtsıza bakiye gizli | M5, M4 | lint + build |

Not: git bu dalda silinen iki üretici ucunu iki yeni seller ucuyla "yeniden adlandırma" olarak eşleştiriyor; gerçekte ayrı silme + ekleme.

Gözlem (yeni değil): admin sayfa parçaları (`/admin/parcekme.php` dahil) `php -S` geliştirme sunucusunda doğrudan çağrılınca 500 veriyor ve statik HTML'in başını basıyor (veri yok). Mevcut tüm parçalarda aynı; Apache `.htaccess` altındaki davranış ayrıca kontrol edilmedi.

---

# Canlı uygulama sırası — GÜNCEL (2026-10-10, `canli-2026-10f`)

Bu bölüm Faz 3, Faz 4 ve Faz 6'daki canlı sıralarının ve önceki `canli-2026-10` / `canli-2026-10b` / `canli-2026-10c` / `canli-2026-10d` / `canli-2026-10e` sıralarının **yerine geçer**. Tüm adımları kullanıcı çalıştırır; Claude canlıya bağlanmaz. Yerelde 010–013 ve 015–017 uygulandı.

**`canli-2026-10f` = `canli-2026-10e` + N-36 (ölü kod: Keşfet `?from=list` modu, `MarketCard`, `MarketplaceListCard`) + N-37…N-42 (arayüz taraması: sunucuya gitmeden ya da yanıta bakmadan "başarılı" görünen yerler — Geçmişim, "tümünü okundu", çıkış, sohbet başlığı takip/beğeni, paylaş/kopyala, Diyalog Defteri gizleme) + N-43 (390 px bildirim paneli taşması) + `scripts/verify.sh`; ayrıntı: aşağıda "Arayüz taraması".** **`canli-2026-10e` = `canli-2026-10d` + N-34 (bot yorumu kaydedilmiyordu) + N-35 (Takip Edilenler'de takibi bırakma, kart beğenisi ve liste kaydı sunucuya yazılmıyordu) + `hoppa_selftest` yalıtım düzeltmesi; ayrıntı: aşağıda "Canlıdan gelen hatalar — N-34, N-35".** `canli-2026-10d` = `canli-2026-10c` + N-33 (sohbetteki "Bugün kalan Lumacoin" göstergesi iade sonrası güncelleniyor; tek ön yüz değişikliği) + belgeler ("Hoppa canlıya açma", BLOCKERS B3 notu, görev metni). `canli-2026-10c` = `canli-2026-10b` + Faz 8 (12 — uçtan uca doğrulama bulguları N-23…N-32 kapatıldı; ayrıntı: aşağıda "Faz 8"). `canli-2026-10b` = `canli-2026-10` + Faz 7a / 7a-2 / 7a-3 (Hoppa ile paket ödemesi, mutabakat, iade, admin "Paket Ödemeleri" ekranı) + N-21 (admin paneli göreli yollar) + N-22 (CSRF'siz iade ucu kapatıldı, 410). Bu sürümde canlıda ödeme **AÇILMAZ**: Hoppa kodu `PAYMENT_PROVIDER=none` ile kapalı gelir.

**Doğrulananlar (2026-10-10, `canli-2026-10..canli-2026-10f`):**

- **`canli-2026-10e..canli-2026-10f` yalnızca ön yüz:** PHP kodu, API sözleşmesi, migration, `pending`, `schema.sql`, `.htaccess`/`router.php` değişmedi (api tarafında tek değişen `access_selftest.php`, kaynak kilitleri). Yalnızca web deploy'u yeterli; adım 6'daki migration listesi aynı. Production build'inde (`d1f5ac5`; N-43 için `3f0d172` ile aynı dosya) N-37…N-43 tarayıcıda (zorlanmış 500) masaüstü + 390 px doğrulandı; `scripts/verify.sh` yeşil.

- **`canli-2026-10d..canli-2026-10e`: yeni migration yok**, şema/denylist değişmedi (`api/database/migrations`, `pending`, `schema.sql`, `.htaccess`, `router.php` aynı). PHP: `SocialController` (follow/like isteğe bağlı `action`), `ChatbotController::getChatbots` + `ChatbotRepository::getPublished` (`liked_by_me`, eklemeli), yeni `functions/social_relations.php`. API sözleşmesi geriye dönük uyumlu: eylemsiz follow/like eski toggle ve eski yanıt adları; `addcomment.php` aynı (sıkı izin listesi korunuyor). Web ve API **birlikte** deploy edilmeli (eski bir sekme yorumda yine 403 alır, veri bozulmaz). `ee04666` production build'inde N-34/N-35 tarayıcıda (masaüstü + 390 px) ve tüm doğrulama komutları yeşil.

- **`canli-2026-10c..canli-2026-10d` kodda yalnızca `web/src/app/dashboard/chat/page.jsx` (+4 satır, N-33).** `api/database/` (migration, pending, şema, selftest), `.htaccess`/`router.php` denylist'leri ve tüm PHP kodu değişmedi; adım 6'daki migration listesi aynı. N-33 tarayıcıda (geliştirme sunucusu, yeniden oynatılan SSE) önce/sonra doğrulandı; lint + verify build + `php -l` yeşil. 10d için ayrı bir production yeniden koşusu yapılmadı — 10c'nin uçtan uca sonuçları geçerli, değişen tek dosya o.

- **Faz 8 yeni migration getirmedi** (`canli-2026-10b..canli-2026-10c`: `migrations/`, `pending/`, `schema.sql` değişmedi). N-25: `migrate.php` checksum'ı artık satır sonundan bağımsız; LF dosyada değer eskisiyle aynı, yani canlıdaki `schema_migrations` kayıtları geçerli kalır (migrate_selftest B: 17 kaydın LF ve CRLF hali eşleşiyor). Kopya Windows'ta alınmışsa da `--status` artık yanlışlıkla "DOSYA DEĞİŞMİŞ" demez.
- **N-30 API sözleşmesi değişti:** `adddialogbook.php` yalnızca `{message_id, name}` alıyor; `addchat.php` istemciden üretilmiş bot cevabı kabul etmiyor (sunucu kaydediyor). Web ve API **aynı anda** deploy edilmeli; deploy anında açık eski bir sekme bir defter kaydı ya da bot cevabı kaydı için 400 alır (veri bozulmaz).

- **Yeni migration yok.** `api/database/migrations/`, `pending/` ve `schema.sql`'de değişiklik yok; kodda yeni DDL ya da `ensureTable` yok. Faz 7a'nın kullandığı her şey mevcut şemada: `param_marketplace_payments` (yeni durum değerleri `hoppa_pending` / `hoppa_failed` / `hoppa_expired` → `varchar(32)`, şema değişikliği gerekmez), `user_plan_selection.expires_at` (011), `notifications`, `admin_audit_log` (017). Adım 6'daki liste bu yüzden `canli-2026-10` ile aynı.
- **`PAYMENT_PROVIDER` varsayılanı `none`** — hem kodda (`PaymentGatewayFactory`: tanımsız, boş ya da tanınmayan değer → `none`) hem `api/.env.example`'da. `none` iken: paket satın alma 503 → kullanıcıya "Ödeme altyapısı hazırlanıyor"; `hoppa_return.php` hiçbir şeyi kesinleştirmez; admin "Paket Ödemeleri" ekranı boş liste gösterir, iade 503. Canlı `.env`'in içeriğini Claude göremez → adım 5a'da kullanıcı kontrol eder.
- **Cron bu sürümde KURULMAZ** (aşağıdaki "Bu sürümde yapılmayacaklar").

| Sıra | Adım | Kontrol / durma koşulu |
|---|---|---|
| 0 | Canlı veritabanı yedeği | — |
| 1 | `php api/database/migrate.php --status` | **009 (`009_user_list_color.sql`) `bekliyor` görünüyorsa** M0-3'teki Liste sayfası 500'ünün nedeni büyük olasılıkla budur (N-03 / K-01). 009 saf `ADD COLUMN`, adım 6'da diğerleriyle uygulanır. Çıktıyı not edin. |
| 2 | **GK-22 kontrolü** (aşağıdaki sorgu) | **Satır dönerse DURUN.** Bu kullanıcılar yeni kurala göre artık "pazaryeri kaydı var" sayılmayacak (Bakiyem kapısı kapanır) ama bakiyeleri ya da bekleyen çekim talepleri var. Karar verilmeden deploy edilmemeli. |
| 2b | **iyzico ödeme kontrolü** (aşağıdaki "iyzico kaynaklı ödeme sorgusu") | Bilgi amaçlı, deploy'u durdurmaz. `kaynak = iyzico` ve durumu `paid` / `partial_refund` / `unknown` / `pending` olan satır varsa: bu ödemelerin iadesi ya da mutabakatı yalnızca iyzico ile yapılabilir → Hoppa geçişinde iyzico kodu "yalnızca iade/sorgulama" modunda tutulmalı (keşif raporu §5.4). **Not (N-22):** iyzico iadesinin artık giriş noktası yok (eski API iade ucu 410); böyle satır çıkarsa iade iyzico panelinden yapılır ya da admin ekranına iyzico iadesi eklenir. `IYZICO_BASE_URL` sandbox iken alınmış satırlar test ödemesidir (gerçek para yok). |
| 3 | Faz 4 kontrol A (AUDIT "Faz 4 — kontrol A ve B") | Satır dönerse ve taslaksa `pending/014_…` → `migrations/`, **koddan önce** `--apply`; kontrol `kalan = 0`. |
| 4 | 015 öncesi not: `SELECT id, ucret_haftalik, ucret_aylik FROM chatbotlar WHERE UPPER(TRIM(isim)) = 'LUMANORIS AI'` | Geri alma için saklayın. |
| 4b | **Diyalog Defteri ölçümü** (aşağıdaki "N-30 mevcut kayıt sorgusu", salt okunur) | Bilgi amaçlı, deploy'u durdurmaz. Yeni görünürlük kuralı özel/bağımsız botların kayıtlarını deploy anında başkalarından gizler (veri değişmez). Sayıları not edin. |
| 5 | **Kod deploy** (`canli-2026-10f` etiketi; web + api birlikte) | Kod 012/016/017 yokken de çalışır (eksik tablolar için fail-safe / 503). `api/.htaccess` ve `api/admin/.htaccess` de değişti (`cron` dizini denylist'e eklendi) — deploy'a dahil olmalı. |
| 5a | **Canlı `api/.env` kontrolü** (değerleri paylaşmadan) | `PAYMENT_PROVIDER` satırı **yok** ya da **`PAYMENT_PROVIDER=none`** olmalı. `HOPPA_TEST_*`, `HOPPA_LIVE_*`, `APP_PUBLIC_URL` **girilmez**. Yerel `.env`'den kopyalanmış bir `PAYMENT_PROVIDER=hoppa` satırı varsa **DURUN** ve silin: test anahtarı girilmiş bir kurulum canlıda kullanıcıları Hoppa TEST sayfasına yönlendirir. |
| 6 | `--status` → `--apply` → `--status` | Bekleyenler sırayla uygulanır: 009 (varsa), 010, 011, 012, 013, 015, 016, 017. **Faz 7a yeni migration getirmedi.** Hiçbiri `--allow-destructive` gerektirmez; `⚠ VERİ SİLER` işareti hiçbirinde olmamalı. |
| 7 | Öz-testler | `plan_limits_selftest.php --strict`, `access_selftest.php`, `application_selftest.php`, `hoppa_selftest.php` (A + B + B2: sahte sağlayıcı, ağ yok, transaction + ROLLBACK; 017 uygulanmış olmalı), `migrate_selftest.php` (salt okunur) → hepsi 0 başarısız. `iyzico_selftest.php` A bölümü. **`hoppa_selftest.php --e2e` canlıda ÇALIŞTIRILMAZ.** |
| 8 | Elle kontrol | Liste sayfası (009 sonrası 500 yok), Lumanoris AI sıradan hesapla sohbet (015), Başvuru gönder → admin Başvurular → İncelendi → kullanıcının IBAN'ı ve `admin_audit_log` satırı (017). **Faz 7a:** Paketler'de ücretli paket seç → pencerede "Ödeme altyapısı hazırlanıyor" (kart alanı yok, Hoppa'ya yönlendirme yok); admin menüsü Ödeme → Paket Ödemeleri açılıyor, liste boş; `POST /api/seller/marketplace_refund.php` → `410`; `/cron/plan_payments.php` web'den erişilemiyor (403/404). **10e:** sohbet sayfasında bir bota yorum yaz → hata yok, sayfa yenilenince yorum duruyor; Takip Edilenler'de kalple takibi bırak → yenile → bot geri gelmiyor; Keşfet'te kalp → yenile → basılı. **10f:** Geçmişim'de uydurma sohbet yok; çıkış → `/login`, geri gelince oturum kapalı; sohbet başlığında "Bildirimler Açık/Kapalı" düğmesi yok, takip/beğeni çalışıyor; Paylaş → Kopyala → "Kopyalandı" ve bağlantı botu açıyor. **Faz 8:** Keşfet'te yeni bir ücretsiz botta "Daha Önce Satıldı" yok, "+12% / +24%" yok; Paketler'de yıllık/%20 anahtarı yok; takip edilen ücretsiz bot ana sayfa seçicisinde seçilebiliyor; sohbette "Diyalog Defterine Ekle" → kayıt akışta görünüyor. |

**Bu sürümde yapılmayacaklar:**

- **Cron satırı kurulmaz** (`api/cron/plan_payments.php`). `PAYMENT_PROVIDER=none` iken mutabakat zaten atlanır ve Hoppa siparişi oluşamaz. Sonuç: **yenileme hatırlatması (GK-27) da çalışmaz** — canlıda süresi biten mevcut paketlere bildirim gitmez. Cron, ödeme Hoppa canlı anahtarıyla açılırken kurulur (README → *Plan purchases*).
- Hoppa canlı anahtarı ve `PAYMENT_PROVIDER=hoppa` bu sürümde girilmez. Açılmadan önce kapanması gerekenler: N-19 (komisyon), N-20 (alıcı adresi), Hoppa soruları #1, #2, #5, #17, BLOCKERS (B1/B3).

Her migration'ın geri alma notu kendi dosya başlığında.

### GK-22 kontrol sorgusu (salt okunur — deploy'dan ÖNCE)

Deploy'dan önce yeni başvuru tablosu (016) henüz yok; o anda yeni kurala göre "kayıtlı" olan yalnızca eski `param_marketplace_sellers`'ta `active`/`suspended` olan. Bakiye, `WalletController::computeBalanceAndTransactions()`'ın birebir SQL karşılığı (yerelde 53 kullanıcıda uygulamanın hesabıyla aynı sonuç verdiği doğrulandı; yerelde sonuç 0 satır). `param_marketplace_*` tabloları canlıda hiç oluşturulmamışsa sorgu "tablo yok" hatası verir — o durumda satış/bakiye de yoktur.

```sql
SELECT k.id AS kullanici_id, k.kullanici_adi,
       COALESCE(pms.status, 'kayit_yok') AS eski_satici_durumu,
       ROUND(COALESCE(inc.gelir, 0) - COALESCE(inc.iade, 0) - COALESCE(w.cekim, 0), 2) AS bakiye,
       COALESCE(w.bekleyen, 0) AS bekleyen_cekim
FROM kullanicilar k
LEFT JOIN param_marketplace_sellers pms ON pms.user_id = k.id
LEFT JOIN (
    SELECT d.seller_user_id AS uid,
           SUM(CASE WHEN d.status = 'approved' AND p.status = 'paid' THEN d.payable_amount ELSE 0 END) AS gelir,
           SUM(CASE WHEN NOT (d.status = 'approved' AND p.status = 'paid')
                     AND (d.status = 'refunded' OR p.status = 'refunded') THEN d.payable_amount ELSE 0 END) AS iade
    FROM param_marketplace_details d
    JOIN param_marketplace_payments p ON p.id = d.payment_id
    WHERE p.status IN ('paid', 'refunded')
    GROUP BY d.seller_user_id
) inc ON inc.uid = k.id
LEFT JOIN (
    SELECT user_id,
           SUM(CASE WHEN durum NOT IN ('reddedildi', 'iptal') THEN miktar ELSE 0 END) AS cekim,
           SUM(durum = 'beklemede') AS bekleyen
    FROM para_cekme_talepleri
    GROUP BY user_id
) w ON w.user_id = k.id
WHERE COALESCE(pms.status, '') NOT IN ('active', 'suspended')
HAVING bakiye <> 0 OR bekleyen_cekim > 0
ORDER BY k.id
;
```

### iyzico kaynaklı ödeme sorgusu (salt okunur — canlı sırası adım 2b)

`param_*` tabloları sağlayıcıdan bağımsız adlandırılmış; iyzico satırı, ham yanıtta (`param_response_json`) iyzico'nun `paymentId` alanının bulunmasıyla ayırt ediliyor. Sağlayıcı kimliği hiç olmayan satırlar iyzico öncesi, simüle ödeme döneminden kalma. Yerelde (2026-10-07): 1 iyzico `paid` satırı (35 ₺, 2026-09-01 — sandbox denemesi) ve 16 sağlayıcısız eski `paid` satırı. `param_marketplace_payments` canlıda hiç oluşturulmamışsa sorgu "tablo yok" hatası verir — o durumda ödeme de yoktur.

Özet:

```sql
SELECT
  CASE
    WHEN JSON_VALID(p.param_response_json)
         AND JSON_EXTRACT(p.param_response_json, '$.paymentId') IS NOT NULL THEN 'iyzico'
    WHEN p.param_transaction_id IS NULL OR p.param_transaction_id = '' THEN 'saglayicisiz (eski/simule)'
    ELSE 'diger'
  END AS kaynak,
  p.status,
  COUNT(*) AS adet,
  ROUND(SUM(p.amount), 2) AS toplam_tutar,
  MIN(p.created_at) AS ilk,
  MAX(p.created_at) AS son
FROM param_marketplace_payments p
WHERE p.status IN ('paid', 'partial_refund', 'refunded', 'unknown', 'pending')
GROUP BY kaynak, p.status
ORDER BY kaynak, p.status;
```

Ayrıntı (iyzico'da hâlâ iade/mutabakat gerektirebilecek satırlar):

```sql
SELECT p.id, p.order_id, p.user_id, p.status, p.amount, p.param_transaction_id AS iyzico_payment_id, p.created_at,
       (SELECT COUNT(*) FROM param_marketplace_details d WHERE d.payment_id = p.id) AS kalem
FROM param_marketplace_payments p
WHERE JSON_VALID(p.param_response_json)
  AND JSON_EXTRACT(p.param_response_json, '$.paymentId') IS NOT NULL
  AND p.status IN ('paid', 'partial_refund', 'unknown', 'pending')
ORDER BY p.created_at;
```

---

## Hoppa geçişi — keşif sonrası bulgu (2026-10-07)

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-17** | **P1** | `web/src/app/dashboard/checkout/page.jsx`, `web/src/features/payment/PlanPaymentModal.jsx`, `CardFields.jsx`, `web/src/shared/lib/card.js` → `api/api/marketplace/createsubscription.php` (`MarketplaceController::createSubscription`, `$data['card']`) ve `api/api/wallet/upgradeplan.php` (`WalletController::upgradePlan`) → `api/functions/checkout_payments.php::chargeCard` | **Kart verisi (numara, son kullanma tarihi, CVV) bizim sunucumuzdan geçiyor.** Tarayıcıdaki kart formu tam kart bilgisini JSON olarak Lumanoris API'sine gönderiyor; istek Express proxy'sinden (`web/server.js`, `http-proxy-middleware`) PHP'ye akıyor; PHP onu sağlayıcıya iletiyor. Bu, platformu **PCI-DSS'in tam kapsamına** sokar (kart verisini işleyen sistem; tipik olarak en ağır öz-değerlendirme). Kontrol edilenler: kart verisi veritabanına **yazılmıyor**; proxy istek gövdesini **loglamıyor** (yalnızca hata satırı); PHP tarafında kartı loglayan satır **bulunamadı**, sağlayıcı yanıtı `IyzicoClient::redact()` ile maskeleniyor. Yani sızıntı değil, **kapsam** sorunu: kart verisi bellekte bizim süreçlerimizden geçiyor. Ek risk: barındırma/TLS kararı açık (**B6**); TLS'siz bir kurulumda kart verisi ağda düz metin olurdu. | **Açık — Hoppa entegrasyonunda düzeltilecek.** Hedef mimari (keşif raporu §0.1): Hoppa ödeme sayfası / 3DS yönlendirmesi; kart verisi Lumanoris'e hiç ulaşmaz; kart formları ve `chargeCard`'daki kart doğrulaması kaldırılır. O zamana kadar ödeme zaten kapalı (iyzico anahtarı yok → `CONFIG_MISSING`), yani canlıda kart verisi gönderilse bile sağlayıcıya iletilmiyor ve tahsilat yapılmıyor — ama form hâlâ kart bilgisini sunucuya postalıyordu. **Ara önlem UYGULANDI (2026-10-07, onaylı, `canli-2026-10`):** pazaryeri ödeme sayfası ve üyelik paketi penceresinde kart formu, kart doğrulaması ve kartı gönderen kod silindi; yerine "Ödeme altyapısı hazırlanıyor" gösteriliyor. Ücretsiz pakete geçiş yalnızca `plan_name` gönderir. Sunucu tarafı (`chargeCard`) değişmedi. `CardFields.jsx` ve `shared/lib/card.js` artık hiçbir yerden çağrılmıyor (Hoppa entegrasyonunda silinecek). Bulgu, kalıcı çözüme (Hoppa) kadar açık kalıyor. |
| **N-18** | P2 | `web/src/app/dashboard/checkout/page.jsx` (sepet adımındaki alt not; ödeme adımındaki "Ödeme Dökümü" kartı) | **Pazaryeri ödeme sayfasında gerçeği yansıtmayabilecek iki taahhüt metni var.** (1) Sepet adımında "30 Gün Para İade Garantisi" yazıyor; müşterinin böyle bir iade politikası olduğuna dair kayıt yok. Ayrıca site içi "Teslimat ve İade Şartları" metni veritabanından (`teslimat_iade_sartlari`) ayrıca geliyor; iki metnin uyumlu olduğu doğrulanmadı. (2) Ödeme dökümünde "Anında Bulut Sağlama — Ödeme tamamlandığında API anahtarlarınız ve küme uç noktalarınız panelinizde hemen oluşturulacaktır." yazıyor. Pazaryerinde satılan ürün sohbet botu erişimi; satın almada API anahtarı ya da küme uç noktası oluşturan bir kod yok. Şu an ödeme kapalı olduğu için (N-17 ara önlemi) kimse bu metinlere dayanarak ödeme yapamıyor. | **Açık — Hoppa entegrasyonunda, ödeme açılmadan ÖNCE düzeltilecek.** İade metni müşterinin gerçek iade politikasına göre yeniden yazılacak; müşteriye soruldu (`docs/musteri-ozet-2026-10.md`, B bölümü). Bulut sağlama metni gerçek teslimatı anlatacak şekilde değiştirilecek ya da kaldırılacak. Kod henüz değiştirilmedi. |

## Hoppa — gerçek doküman sonrası (2026-10-07)

Kaynak ve servis eşlemesi: `docs/proposals/hoppa-gecis-kesif.md` §0.2. Hoppa sanal POS'u = EsnekPOS altyapısı.

### Geliştirici kararı

| ID | Karar | Gerekçe | Etkilediği yer |
|---|---|---|---|
| GK-27 | **Otomatik paket yenileme yok.** Her dönem kullanıcı paketi Ortak Ödeme Sayfası üzerinden elle yeniler. Süre bitmeden kullanıcıya hatırlatma yapılır. | Hoppa'nın tekrarlı ödeme servisi (`RecurringPayment`, `RecurringPaymentCardAdd`) kart numarası ve CVV'yi bizim sunucumuzdan istiyor (N-17'yi geri getirir) ve bölüştürme desteklemiyor. D-05'teki "30 günlük tek seferlik satış" kuralıyla uyumlu. | Paket satın alma (Faz 7a). **Hatırlatma henüz yazılmadı** (mevcut `notifications` altyapısıyla yapılacak; zamanlanmış iş gerekir). Erken yenilemede kalan günlerin devredilip devredilmeyeceği **iş kuralı, karar verilmedi**: bugünkü kod bitişi her satın almada `NOW() + 30 gün` yazıyor (kalan günler devretmiyor). |

### Bulgu

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-19** | P2 | Hoppa üye işyeri ayarı (kod değil); paket fiyatının gösterildiği ekranlar | **Test üye işyerinde komisyon alıcıya yansıtılıyor.** 149,00 ₺'lik siparişte Hoppa ödeme sayfası "151,25 TRY ÖDEME YAP" gösterdi ve karttan 151,25 çekildi (`COMMISSION=2,25`, `COMMISSION_RATE=1,490`). Bizim ekranımız 149 ₺ gösteriyor. Canlı hesapta da böyle olursa kullanıcı ödeme sayfasında ilk kez daha yüksek bir tutar görür. Bu, fiyat gösterimi açısından (tüketici mevzuatı: toplam fiyat) sorun olabilir. Kod tarafında kesinleştirme her iki modeli de kabul ediyor: çekilen tutar sipariş tutarına eşitse **ya da** çekilen tutar eksi komisyon sipariş tutarına eşitse ödeme geçerli. | **Açık — Hoppa'ya soruldu** (`docs/hoppa-sorular.md` #3: hesap ayarı mı); **müşteriye soruldu** (`docs/musteri-ozet-2026-10.md` B, öneri: komisyonu müşteri karşılasın). Canlı ödeme açılmadan önce komisyonun kimde kalacağına müşteri karar vermeli; alıcıya yansıyacaksa fiyat ekranları bunu göstermeli. |

## Faz 7a — Paket ödemesi Hoppa ile (yalnızca TEST ortamı, 2026-10-07)

**Akış:** "Ödemeye geç" → `upgradeplan.php` (yalnızca `plan_name`) → `hoppa_pending` satırı → Hoppa `CommonPaymentDealer` → kullanıcı Hoppa sayfasında kartı girer → `hoppa_return.php` (BACK_URL; POST alanlarına güvenilmez) → sunucudan `ProcessQuery` → ödendiyse ve tutar tutuyorsa paket aynı transaction'da tanımlanır. Koşullu `UPDATE … WHERE status IN ('hoppa_pending','hoppa_failed')` aynı `ORDER_REF_NUMBER`'ın iki kez paket tanımlamasını engeller. Ayrıntı: README → *Plan purchases — Hoppa*; servis eşlemesi: keşif raporu §0.2.

**Ayarlar:** `PAYMENT_PROVIDER` (varsayılan `none` → paket ödemesi 503, arayüzde "Ödeme altyapısı hazırlanıyor"), `HOPPA_MODE` (varsayılan `test`), `HOPPA_TEST_*` / `HOPPA_LIVE_*`, `APP_PUBLIC_URL`. Test kimlik bilgileri (dokümandaki herkese açık test üye işyeri) yalnızca yerel `api/.env`'de; `.env.example`'da boş. Canlı anahtar alanları boş.

**Test ortamında gerçek ödeme (`php api/database/hoppa_selftest.php --e2e`, 2026-10-07, ROLLBACK):**

| Kart | BACK_URL POST | ProcessQuery sonucu | Paket |
|---|---|---|---|
| 9792100000000001 (başarılı) | `STATUS=SUCCESS RETURN_CODE=0 AMOUNT=151,25 COMMISSION=2,25` | `paid` | tanımlandı; aynı sipariş ikinci kez işlenince yeniden tanımlanmadı |
| 5100050000006661 (hata 51) | `STATUS=PAYMENT_ERROR RETURN_CODE=51` | `failed` ("51-Limit Yetersiz") | değişmedi |

`hoppa_return.php` ucu ayrıca geçici `php -S` ile denendi: GET → `303 ?odeme=pending`; bozuk referans → `303 ?odeme=failed`; var olmayan siparişe `STATUS=SUCCESS` yazılmış sahte POST → `303 ?odeme=failed` (POST'a güvenilmediği görüldü).

**N-17:** paket satın alma akışı için **kapandı** — kart verisi ne toplanıyor ne sunucumuza geliyor (`upgradePlan` artık `$data['card']` okumuyor, `chargeCard` çağırmıyor; selftest A bunu kaynak düzeyinde kilitliyor). **Pazaryeri için açık kalıyor:** Hoppa'da bölüştürme yalnızca kartı sunucudan isteyen EYV3DPay'de var (keşif §0.2 sınır a, Hoppa sorusu #1); orada N-17 ara önlemi (kart formu yok) sürüyor.

### Yeni bulgu

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-20** | P2 | `WalletController::startHostedPlanPayment` | **Hoppa alıcının il, ilçe ve adresini zorunlu istiyor; kayıtta bu bilgiler yok.** Şu an Hoppa'nın kendi örneğindeki gibi `"-"` gönderiliyor; telefon yoksa o da `"-"`. Doküman "fraud önleme için tüm parametrelerin eksiksiz gönderilmesi gerekir" diyor. Test ortamı `"-"`'yi kabul etti; canlıda kabul edilip edilmeyeceği ve adres toplanıp toplanmayacağı (KVKK, kayıt formu) **müşteri kararı**. | **Açık — karar bekliyor.** Müşteriye (`musteri-ozet-2026-10.md` B) ve Hoppa'ya (`hoppa-sorular.md` #17: canlıda `"-"` kabul ediliyor mu) soruldu. Canlı ödeme açılmadan önce: ya Hoppa `"-"`'yi canlıda kabul ettiğini teyit eder, ya da ödeme öncesi adres alanı eklenir. |

### Faz 7a'da yapılmayanlar (açık)

- **Mutabakat işi yok.** Hoppa webhook belgelemiyor. Kullanıcı Hoppa sayfasında sekmeyi kapatırsa `hoppa_pending` satırı öyle kalır; ödeme Hoppa'da başarılı olup dönüş gelmezse paket **tanımlanmaz** — ta ki aynı sipariş yeniden sorgulanana kadar. Zamanlanmış bir ProcessQuery mutabakatı gerekiyor (`hoppa_pending`/`hoppa_failed`, son 7 gün). iyzico'nun `reconcilePayments()`'ı bu satırlara bilerek dokunmuyor (ayrı durum değerleri).
- **İade yok.** Hoppa `OrderReturn` yazılmadı. Admin iade ucu (`processRefund`, iyzico) Hoppa satırını reddediyor: iyzico anahtarı yoksa 503, varsa `itemTransactions` olmadığı için 422 — yanlış sağlayıcıya iade gitmez. Hoppa iadesi şimdilik Hoppa panelinden.
- **Yenileme hatırlatması yok** (GK-27). Erken yenilemede kalan günler devretmiyor (her satın alma `NOW()+30`) — iş kuralı, karar verilmedi.
- **AUTH_HASH doğrulanmıyor** (algoritma belgelenmemiş, Hoppa sorusu #2); güven yalnızca sunucudan yapılan ProcessQuery'de.
- **Komisyon alıcıya yansıyor** (N-19) — kesinleştirme iki modeli de kabul ediyor.
- `iyzico` kodu ve pazaryeri satışı değiştirilmedi.

## Faz 7a-2 — Canlı öncesi tamamlayıcılar (yalnızca TEST ortamı, 2026-10-07)

Faz 7a'nın "yapılmayanlar" listesindeki mutabakat, iade ve hatırlatma maddeleri kapandı. Kod: `api/functions/hosted_plan_payments.php`, `api/cron/plan_payments.php`. Selftest: `hoppa_selftest.php` B2 (sahte sağlayıcı, ROLLBACK) + C (`--e2e`, gerçek test ortamı).

### 1. Mutabakat işi (`php api/cron/plan_payments.php`, önerilen 5 dakikada bir)

| Sipariş durumu | Ne zaman sorulur | Sonuç |
|---|---|---|
| `hoppa_pending` | oluşturulduktan **15 dk** sonra, her turda | ödendi → paket tanımlanır (`finalizeHostedPlanPayment`; aynı sipariş iki kez işlenmez) · reddedildi → `hoppa_failed` · ödenmemiş → beklemede kalır |
| `hoppa_pending`, **24 saatten** eski ve Hoppa'da hâlâ ödenmemiş | — | `hoppa_expired` |
| `hoppa_expired` | ilk **7 gün** boyunca en fazla **6 saatte bir** | ödenmiş çıkarsa paket tanımlanır (geç dönüş kaçmasın); değilse bekler |
| `hoppa_expired`, 7 günden eski | **artık sorulmaz** | Bu noktada ödeme çıkarsa ancak Hoppa panelinden ya da destekten öğrenilir; sipariş numarasıyla `hoppa_return`/mutabakat elle tetiklenebilir. |
| `unknown` (tutar uyuşmazlığı, paket yazılamadı, sorgu hatası) | her turda | satır `hoppa_pending` kalır, `error_log`'a yazılır — insan incelemesi gerekir |

**Neden 24 saat / 7 gün:** Hoppa ödeme sayfasının ne kadar açık kaldığı belgelenmemiş (`hoppa-sorular.md` #5). 24 saat, kullanıcının sayfayı açık bırakıp sonra ödemesine yetecek kadar uzun. `hoppa_expired` son durum değil: sonradan ödeme görülürse `paid` olur. Hoppa cevap verince bu süreler gerekirse kısaltılır.

Kilit: aynı anda iki tur çalışmaz (`GET_LOCK('cron_plan_payments')`, alınamazsa tur atlanır). `PAYMENT_PROVIDER=none` iken mutabakat atlanır, hatırlatma çalışır. `api/cron/` dizini üç denylist'e de eklendi (`api/.htaccess`, `api/admin/.htaccess`, `api/router.php`), betik ayrıca CLI dışından çağrılırsa 404 döner (`php -S` ile doğrulandı). Sunucuda cron kurulumu kullanıcıda (README → *Plan purchases*).

### 2. Hoppa iadesi

- **Admin iade ekranı BULUNAMADI.** Mevcut tek iade yolu admin oturumu isteyen `POST /api/seller/marketplace_refund.php` (`SellerController::refund`); onu ne `api/admin`, ne `web/src`, ne `router.php` çağırıyor. Hoppa iadesi bu uca bağlandı: satır Hoppa paketi ise `hostedPlanRefund()`'a, değilse eskisi gibi `processRefund()`'a (iyzico, değişmedi) gider. **Admin panelinde iade düğmesi/ekranı yok** — eklenmesi ayrı iş (karar senin).
- **Tam iade, kısmi yok:** tutar istekten okunmaz; Hoppa'nın kayıtlı çekim tutarı iade edilir (komisyon alıcıya yansıdıysa o da dahil — testte 151,25). `amount`/`items`/`partial` gönderen istek 422.
- **Akış:** iyzico yoluyla aynı kilit (`refund_payment_<id>`) → satır yeniden okunur (yalnızca `paid` iade edilir; `refunded` → 409) → ProcessQuery → OrderReturn (`SYNC_WITH_POS=true`) → **ProcessQuery ile doğrulama** (iptal/iade görülmeden satır `refunded` yapılmaz, paket alınmaz) → durum + paket geri alma tek transaction'da. Hoppa'da zaten iade edilmişse (panelden) istek gönderilmez, yalnızca eşitlenir.
- **Paket iptali** (`revokeRefundedPlan`, iyzico'daki D-03 erişim geri alma kuralının paket karşılığı): kullanıcı varsayılan plana (Ücretsiz, süresiz) döner. `user_plan_selection` sipariş bağı tutmadığı için iki durumda **paket alınmaz** ve nedeni yanıtta + log'da görünür: kullanıcının bu ödemeden sonra ödenmiş başka paket ödemesi varsa, ya da şu anki paketi iade edilen paket değilse.
- **Kayıt:** her deneme (reddedilen, başarısız, doğrulanamayan, tamamlanan) `admin_audit_log`'a `action='hoppa_plan_refund'` ile yazılır: admin, sipariş, sebep, tutar, Hoppa yanıtı (müşteri alanları ayıklanmış), önceki/sonraki durum, paket sonucu. Tablo yoksa (017 uygulanmamış) iade **hiç başlatılmaz** (503). `param_marketplace_refunds`'a yazılmıyor: `detail_id` NOT NULL + FK ve paket ödemesinin detay satırı yok.
- **Test ortamında gerçek iade (2026-10-07):** başarılı test ödemesi (151,25) → OrderReturn → `İŞLEM İPTAL EDİLDİ`, ProcessQuery `ORDER_CANCEL` (aynı gün iade iptal olarak işleniyor) → satır `refunded`, paket Ücretsiz'e döndü, audit `tamamlandi`; ikinci iade 409. Ertesi gün yapılan iadenin `İade - Başarılı` (STATUS_ID 7) döneceği dokümandan; sınıflandırıcı ikisini de tanıyor ama 7 gerçek ortamda görülmedi.
- Uç yönlendirmesi CLI'dan admin oturumu taklidiyle denendi: Hoppa satırı Hoppa yoluna gitti (iyzico'ya değil).

### 3. Yenileme hatırlatması (GK-27)

Bitişe **3 gün** kala uygulama içi bildirim (`notifications`, `type='plan_renewal_reminder'`): "Paketiniz otomatik yenilenmez; Paketler sayfasından yenileyebilirsiniz." **Aynı dönem için bir kez:** dönemin penceresi `[bitiş − 3 gün, bitiş)`; pencere içinde bu türde bildirim varsa yenisi yazılmaz. Yenileme bitişi 30 gün ileri taşıdığı için yeni dönemde yeniden gönderilir. Şema değişikliği yok. Süresiz (ücretsiz) ve süresi geçmiş paketlere gönderilmez. Aynı cron'dan çalışır.

### 4. Müşteri ve Hoppa soruları

N-19 (komisyon) ve N-20 (adres) `musteri-ozet-2026-10.md` B bölümüne sade dille, `hoppa-sorular.md`'ye teknik haliyle eklendi (#3 güncellendi, #17 yeni; mevcut numaralar korunuyor çünkü belgelerde ve kodda atıf var).

### Hâlâ açık (canlıdan önce)

N-19, N-20 (müşteri + Hoppa cevabı), AUTH_HASH algoritması (#2), Hoppa ödeme sayfası geçerlilik süresi (#5 — 24 saat/7 gün varsayımı), admin iade ekranı yok, erken yenilemede kalan günlerin devri (iş kuralı, karar yok), pazaryeri Hoppa entegrasyonu (bölüştürme, #1).

## Faz 7a-3 — Admin "Paket Ödemeleri" ekranı ve iade (2026-10-07)

**Ekran:** `/admin/odemeler` (menü: Ödeme → Paket Ödemeleri). Veri ve iade `admin/ajax/odemeler.php` üzerinden; `basvurular.php` ile aynı desen (`_guard.php`: admin oturumu + her POST'ta CSRF; DOM'a yalnızca textContent).

- **Liste:** yalnızca Hoppa ile satılmış paket ödemeleri (SQL ön süzgeci + `isHostedPlanPayment()`); pazaryeri ve iyzico satırları yok. Sütunlar: kullanıcı (e-posta, ad, #id), paket, tutar, tarih, durum (Ödendi / İade edildi / Beklemede / Süresi doldu / Başarısız), sipariş; test ortamı satırlarında "TEST" etiketi. Filtre: durum + e-posta (LIKE, joker karakterler kaçışlı). En fazla 300 satır.
- **İade:** yalnızca "Ödendi" satırında "İade et" → onay penceresi (kullanıcı, paket, tutar, sipariş, tarih, neden alanı, "geri alınamaz" uyarısı) → `hostedPlanRefund()`.
- **İade ucu kararı:** ekran `/api/seller/marketplace_refund.php`'ye istek atmıyor, onun Hoppa satırları için çağırdığı **aynı fonksiyonu** (`hostedPlanRefund`) doğrudan çağırıyor. Neden: o API ucunda CSRF denetimi yok, admin panelinin kuralı her POST'ta CSRF. İade mantığı (kilit, doğrulama, paket geri alma, `admin_audit_log`) tek yerde kaldı.
- **Sonuç kutusu:** iade edilen tutar, Hoppa durumu ("iptal edildi (aynı gün)" / "iade edildi"). Paket geri alınmadıysa kırmızı vurgulu ayrı satır: `DİKKAT — "<paket>" paketi geri ALINMADI: <neden>.`

**Uçtan uca test (Hoppa TEST ortamı + geçici `php -S` + headless Chrome, 2026-10-07; KALICI yerel kayıt):**

| Sipariş | Senaryo | Ekrandaki sonuç | Hoppa | Paket |
|---|---|---|---|---|
| PLN-91CC115F1676A6D6 | tek ödeme | "İade tamamlandı; paket geri alındı." · 151,25 ₺ · iptal edildi (aynı gün) | `ORDER_CANCEL` | Gümüş → Ücretsiz |
| PLN-6EB44E85690A4EDB | sonrasında ikinci ödeme (B) var | "İade tamamlandı. Paket geri alınmadı: kullanıcının sonradan ödenmiş başka bir paket ödemesi var" + DİKKAT satırı | `ORDER_CANCEL` | Gümüş kaldı (B'ye ait) |
| PLN-7958D8D35264F716 | B | "İade tamamlandı; paket geri alındı." | `ORDER_CANCEL` | Gümüş → Ücretsiz |

Üç deneme de `admin_audit_log`'da (`tamamlandi`, admin adı, neden, paket sonucu). Ayrıca: menü bağlantısı görünüyor; iade sonrası satır "İade edildi" oldu ve düğme kalktı; e-posta filtresi çalışıyor; sayfada JS hatası yok; oturumsuz GET/POST → 403. Test kullanıcısı #6'nın önceden paket satırı yoktu (varsayılan plan); şimdi `Ücretsiz`, süresiz satırı var — davranış aynı.

### Yeni bulgu

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-21** | P3 | `api/admin/index.php:20` (ve aynı dosyadaki diğer göreli `../`/`./` yolları) | `require_once '../functions/db.php'` göreli yol; PHP `../` yollarını **çalışma dizinine** göre çözer. README'deki geliştirme komutu (`cd api && php -S 127.0.0.1:8000 router.php`, `dev:all`) ile çalışma dizini `api/` olduğundan admin girişinden sonra her sayfa **fatal error** veriyor (`Failed opening required '../functions/db.php'`). Apache'de çalışma dizini betiğin dizini olduğu için canlıyı etkilemiyor. Faz 7a-3 testinde `cd api/admin && php -S … -t .. ../router.php` ile aşıldı. | **Kapandı (2026-10-07).** `index.php`'deki `../functions/db.php`, üç `./partials/...` include'u ve rota include'u `__DIR__` ile; aynı hata sınıfındaki beş ajax ucu (`adminler`, `ayarlar`, `delete`, `read`, `smtp`: `require '../../functions/db.php'`) da düzeltildi. `api/admin` altında göreli include kalmadı. Doğrulama: README komutu (`cd api && php -S 127.0.0.1:8099 router.php`) + headless Chrome ile admin girişi açıldı, 24 admin rotasının hepsi 200, ajax'ta 5xx yok, PHP log'una yeni hata yok. |

### N-22 — CSRF'siz admin iade ucu (2026-10-07)

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-22** | P2 | `api/api/seller/marketplace_refund.php` → `SellerController::refund` | **Admin iade ucu yalnızca admin oturumuna bakıyordu, CSRF token denetimi yoktu** (admin panelinin kuralı: her POST'ta CSRF). Sömürü senaryosu: admin oturumu açıkken, admin'in tarayıcısından `POST /api/seller/marketplace_refund.php` gövdesi `data={"order_id":"PLN-…"}` olan bir istek tetiklenirse ödenmiş sipariş gerçekten iade edilir ve kullanıcının paketi geri alınır (gerçek para hareketi). **Sınır:** oturum çerezi `SameSite=Lax`; modern tarayıcılar bu çerezi başka bir siteden gelen POST'ta göndermez, yani dış bir siteden saldırı bugün büyük ölçüde engelli. Kalan risk: aynı site (same-site) kaynaklı istek (ör. alt alan adında barındırılan içerik) ya da SameSite uygulamayan tarayıcı; savunma tek bir çerez özelliğine dayanıyordu. Çağıran araması (`web/src`, `api/admin`, `api/router.php`): **çağıran yok** (`api/admin`'de yalnızca bir açıklama yorumu). | **Kapandı (2026-10-07):** uç diğer emekli uçlar gibi (`buyChatbot`) her istekte **410** döner; tek endpoint, çağıran yok → kademeli kural madde 3 gereği uygulandı. `hostedPlanRefund()` ve iyzico `processRefund()` kodda duruyor. İade artık yalnızca admin panelinin CSRF korumalı "Paket Ödemeleri" ekranından (`admin/ajax/odemeler.php`, `_guard.php`). Not: iyzico/pazaryeri satırlarının iadesi için şu an hiçbir giriş noktası yok (iyzico anahtarsız, kapalı; pazaryeri Hoppa'ya bağlanınca ekrana eklenmeli). Doğrulama: `curl -X POST` → `410`; ekran üzerinden iade yolu değişmedi. |

## 12 — Uçtan uca doğrulama, `canli-2026-10b` (2026-10-09)

Gerçek tarayıcıda (playwright-core, headless Chromium), ayrı worktree'de, yerel veritabanıyla 14 senaryo. Rapor: `docs/verification/2026-10-dogrulama.md`. Bu turda **kod değiştirilmedi**; aşağıdaki maddeler yalnızca kayıt.

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-23** | P2 | `web/src/app/dashboard/chat/page.jsx` (`generateReply`, ~612-773) | **Yeni sohbetin ilk mesajında model hatası kullanıcıya hiç gösterilmiyor.** `generatereply.php` 200 + `event: error` (UPSTREAM_ERROR) döndüğünde istemci hata balonunu yazıyor, ama yeni sohbet açılışının tetiklediği `getchat.php` yeniden yüklemesi mesaj listesini DB'den baştan kuruyor ve balon (DB'de olmadığı için) siliniyor. Kullanıcı sorusunu görür; ne cevap ne hata ne "Tekrar Dene" görür. Mevcut bir sohbette ikinci mesajda aynı hata doğru gösteriliyor. Yeniden üretim: yeni sohbette ilk mesaj + Gemini 403 (gerçek, 1 istek) ve aynı SSE gövdesinin `page.route` ile yeniden oynatılması (Gemini'ye gitmeden) — 2/2. Coin iadesi doğru (sunucu `refunded:true`). | Açık |
| **N-24** | P3 | `web/src/entities/user/ui/ProfileCard.jsx:506` | Sahibin kendi **bağımsız (özel)** botunun sohbet sayfasında "Sepete Ekle" düğmesi var (`isFreePublic` değilse çiziliyor). Tıklanınca `addtocart.php` → 422 `SELLER_NOT_ACTIVE` "Satıcısı henüz pazaryeri kaydını tamamlamamış" — kullanıcı kendi botunu sepete eklemeye çalışmış oluyor. GK-14 "Satın Al" düğmelerini kaldırdı; bu düğme kapsam dışı kalmış. | Açık |
| **N-25** | P3 | `api/database/migrate.php` (sha256 checksum), repoda `.gitattributes` yok | Checksum satır sonuna duyarlı. `core.autocrlf=true` olan bir Windows checkout'ta (bu turun worktree'si) 17 migration'ın hepsi `--status`'ta **"UYGULANMIŞ AMA DOSYA DEĞİŞMİŞ"** görünüyor; içerik CR farkı dışında birebir aynı (doğrulandı). Canlıya Windows'ta alınmış bir kopyadan yükleme yapılırsa operatör yanlış alarm görür. | Açık |
| **N-26** | P2 | `web/src/app/dashboard/explore/page.jsx:227, 347, 355`; `web/src/app/dashboard/page.jsx:914` | **Uydurma ticari beyan** (N-13/N-15 ailesi; N-15 yalnızca puanı kapattı). Keşfet ve ana sayfa kartlarında `bot.durum == 1` olan **her** bota "Daha Önce Satıldı" rozeti — `durum` yayın durumu, satış değil; bugün oluşturulmuş ücretsiz bot da (satılamaz) bu rozeti taşıyor. Keşfet istatistiklerinde sabit "+12% bu ay" ve "+24%" trend etiketleri. | Açık |
| **N-27** | P2 | `api/src/Infrastructure/Repositories/ChatbotRepository.php:200` (`getMenuItems`) ← `web/src/app/dashboard/page.jsx:946-994` | **Madde 6: takip edilen ücretsiz herkese açık bot, ana sayfa bot seçicisinde kilitli + "Profili gör".** Seçici "erişim" kümesini `getchatbotsmenu.php`'den alıyor; o yalnızca *sahip + aktif abone* botlarını döndürüyor. Sunucu (GK-2, `userHasAccess('chat')`) bu botla sohbete izin veriyor. Yorum (`page.jsx:943`) "Faz 4'te genişleyecek" diyor; genişlemedi. Yeniden üretim: e2e-b, E2E Bot A2'yi (ücretsiz, herkese açık) takip eder → seçicide kilitli. | Açık |
| **N-28** | P2 | `web/src/app/dashboard/upgrade/components/BillingCycleToggle.jsx:27-29`, `PricingCard.jsx:59` | **"Yıllık Faturalandırma · %20 İndirim" anahtarı yalnızca etiketi değiştiriyor**: fiyat aynı (149/299/849), etiket "/Yıllık (Aylık)" oluyor; yıllık ürün ve indirim yok (`upgradeplan` yalnızca `plan_name` alıyor, paket 30 gün). Landing'deki aynı anahtar daha önce kaldırılmıştı (`PricingSection.jsx:10` yorumu). Aynı sayfada "İstediğiniz zaman yükseltin veya iptal edin" metni; paket otomatik yenilenmiyor, iptal akışı yok. | Açık |
| **N-29** | P3 | `api/functions/marketplace_application.php:172`, `MarketplaceApplicationController.php:66` | Başvuru durum ucundaki `submitted_at` aslında `updated_at`. Admin "İncelendi" dediğinde kullanıcıya gönderim zamanı olarak inceleme zamanı dönüyor (14:02:06 → 14:02:18). | Açık |
| **N-30** | P2 | `api/src/Presentation/Controllers/NoteController.php:3-28` (`addDialogBook`); aynı kök `ChatController.php:28-54` (`addChat`) | **Diyalog Defteri'ne istemcinin yazdığı "asistan yanıtı" doğrulanmadan yayınlanıyor; bot erişimi de denetlenmiyor.** `input_message`/`output_message` istemciden aynen alınıyor, sohbet geçmişiyle karşılaştırılmıyor; `chatbot_id` için erişim kontrolü yok. **Sömürü senaryosu (kanıtlandı):** e2e-b (#109), erişimi olmayan e2e-a'nın **özel** botu #51 için `POST /api/note/adddialogbook.php` `data={"chatbot_id":51,"name":"…","input_message":"Soru","output_message":"Bu yanıtı bot üretmedi…"}` → 200; kayıt herkese açık akışta (`getdialogues.php`) `chatbot_isim:"E2E Bot A1"`, `owner_kullanici_adi:"e2e-a"` ile görünüyor. Sonuç: (a) herhangi bir kullanıcı, herhangi bir bota (başkasının ücretli/özel botu dahil) uydurma cevap yazıp herkese açık olarak o botun yanıtıymış gibi yayınlayabilir — itibar/içerik bütünlüğü; (b) özel botun adı ve sahibi akışta görünür. Aynı aile: `addChat` `sent_by:"bot"` ile serbest metni erişim kontrolü olmadan `chatbot_chats`'e yazıyor (kanıt: e2e-b'nin abone olmadığı ücretli bot #27 için satır #156); bu satırlar botun kartlardaki "Diyalog" sayacını (`toplam_chats`) da şişiriyor. Yamanın kapsamı iki uç + istemcinin cevap kaydetme akışı (cevap sunucuda kaydedilmeli) → **birden fazla endpoint, kademeli kural madde 4: uygulanmadı, öneri.** | Açık — karar/tasarım gerekiyor |
| **N-31** | P1 (ödeme açılmadan önce; `canli-2026-10b`'yi engellemez, orada `PAYMENT_PROVIDER=none`) | `api/api/wallet/hoppa_return.php` → `WalletController::hoppaReturn`; oturum `src/autoload.php` → `functions/bootstrap.php:34` ile her istekte `session_start()` | **Hoppa'dan dönüş kullanıcıyı oturumdan düşürüyor.** BACK_URL çapraz-site bir form POST'u; `SameSite=Lax` oturum çerezi bu istekte gelmiyor, PHP yeni bir oturum başlatıp `Set-Cookie: PHPSESSID=<yeni>` gönderiyor ve tarayıcıdaki oturum çerezinin üstüne yazıyor. 303 → `/dashboard/upgrade?odeme=…` → giriş yok → `/login/`; ödeme sonucu da kayboluyor. Ödeme (`paid`) ve paket tanımı doğru çalışıyor. Kanıt: Hoppa TEST'te gerçek ödeme `PLN-4EC28FD239F538C4` (başarılı) ve `PLN-C732473EF5F50FA2` (hata 51) — ikisinde de dönüşte `sessioncheck` → `authenticated:false`; ayrıca ödeme yapmadan deterministik yeniden üretim (127.0.0.1'den `hoppa_return.php`'ye sahte referanslı form POST). Faz 7a e2e'si BACK_URL'i `route.fulfill` ile kestiği için görülmemişti. | Açık |

Not (bulgu değil, gözlem): `user_plan_selection.selected_at` (11:34:08) ile `expires_at` (14:34:08 + 30 gün) arasında 3 saatlik fark — DB `NOW()` (UTC) ile PHP saat dilimi karışıyor olabilir; etkisi doğrulanmadı. Bir kez "Page crashed" (Listeye Ekle → Kaydet) görüldü, 3 denemede tekrarlanmadı.

## Faz 8 — 12 doğrulama bulgularının kapatılması (2026-10-09) → `canli-2026-10c`

Her madde ayrı commit; her commit'ten önce lint + verify build + `php -l` yeşil. Yeniden koşu: ayrı worktree, production build, `PAYMENT_PROVIDER=none` (S-12'de geçici `hoppa`/TEST1234), rapor `docs/verification/2026-10-dogrulama.md` → "Yeniden koşu".

| ID | Commit | Ne yapıldı | Doğrulama | Durum |
|---|---|---|---|---|
| **N-23** | `e27dcfd` | Geçmiş yükleme effect'i "mesaj var + sohbet var" kontrolünü bir sonraki effect'te güncellenen ref yerine aynı render'ın `conversationId` state'iyle yapıyor; ilk mesajdan sonra geçmiş yeniden yüklenip hata balonunu silmiyor. | Tarayıcı: yeniden oynatılan SSE ile önce kırmızı / sonra yeşil (hata yolu + başarılı yol); S-02 yeniden koşusunda gerçek Gemini 403 ile "…ulaşılamıyor" + "Tekrar Dene" ilk mesajda görünüyor. | **Kapandı** |
| **N-24** | `afa6c20` | `getDetail` yanıtına `is_owner` (eklemeli). "Sepete Ekle" yalnızca başkasının herkese açık, fiyatlı botunda. | access_selftest (`is_owner`, 3 kontrol); S-02: sahibin özel botunda düğme yok. | **Kapandı** |
| **N-25** | `bd6ba22` | `migrationChecksum()` (CRLF/CR → LF, sonra sha256); `.gitattributes`: `*.sql`, `*.php` `eol=lf`. | `migrate_selftest.php` (A: satır sonu bağımsızlığı; B: 17 kaydın LF ve CRLF hali kayıtlı checksum'la eşleşiyor, salt okunur); yeni Windows worktree'sinde `.sql`/`.php` LF geldi, `--status` 17/17 "uygulanmış". | **Kapandı** |
| **N-26** | `d41456b` | `getPublished` → `has_sales` (user_subscriptions — süresi dolmuş dahil — veya chatbot_purchase_credits). Rozet yalnızca `has_sales`; bento kartın "Doğrulanmış" yedeği kaldırıldı. `durum` sütunu geriye dönük uyum için duruyor. | access_selftest N-26 (vitrindeki 9 botta bayrak = gerçek satış; eklenen satışla olumlu durum); S-03/S-06: yeni botlarda rozet yok, gerçek satışı olan 6 seed botta var. | **Kapandı** |
| **N-27** | `423774f` | `ChatbotRepository::chatAccessibleIds()` = `userHasAccess(…, 'chat')`; `getfollowedbots` her bota `can_chat` ekliyor (eklemeli); ana sayfa seçicisi kilidi buradan alıyor (getchatbotsmenu değil). | access_selftest N-27; S-06: takip edilen ücretsiz A2 seçilebilir ve çipe geliyor, ücretli bot kilitli + "Profili gör". | **Kapandı** |
| **N-28** | `9e9e3f6`, `77eb463` | Keşfet'teki sabit "+12% bu ay / +24%" kaldırıldı; yükseltme sayfasındaki yıllık/%20 anahtarı ve `BillingCycleToggle` kaldırıldı; başlıktaki "İstediğiniz zaman … iptal edin" → "Paketler 30 gün geçerlidir ve otomatik yenilenmez". | S-03 / S-07 yeniden koşusu. | **Kapandı** |
| **N-29** | `33f124f` | Gönderim zamanı `updated_at` (ON UPDATE) yerine `created_at`; yeniden gönderim (GK-20) `created_at`'i günceller. Tek kaynak `marketplaceApplicationSubmittedAt()`. | application_selftest E (önce kırmızı); S-09: inceleme öncesi/sonrası 15:34:06 = 15:34:06. | **Kapandı** |
| **N-30** | `8861665` | Aşağıdaki tablo. | access_selftest N-30 (16 senaryo); canlı HTTP denemeleri; S-11 + S-13 yeniden koşusu. | **Kapandı** |
| **N-31** | `92c10ba` | `hoppa_return.php` `LUMANORIS_STATELESS_ENDPOINT` tanımlıyor; `bootstrap.php` o uçta `session_start()` yapmıyor → Set-Cookie yok, kullanıcının oturum çerezi korunuyor. Çerez ayarı değişmedi; uç zaten oturumsuz ProcessQuery ile kesinleştirip 303 GET yapıyordu. | hoppa_selftest A: N-31 kilidi; curl: Set-Cookie yok (diğer uçlar oturum açmaya devam ediyor); Hoppa TEST gerçek ödeme, ana ağaçta 2 + yeniden koşuda 2: başarılı → `?odeme=paid`, aynı çerez, oturum açık, paket tanımlı; hata kartı → `?odeme=failed`, oturum açık, paket değişmedi; admin iadesi → paket geri alındı. | **Kapandı** |
| **N-32** | `540486b` | **Yeni (S-06 yeniden koşusunda bulundu; `canli-2026-10b`'de de var).** Ana sayfadan `?prompt=` ile sohbet başlatınca, kullanıcının o botla geçmişi varsa ilk (id=0) sohbetin geç dönen geçmiş yüklemesi bayat `conversationId` ile mesaj listesini "yalnızca karşılama mesajı"na çeviriyor; kullanıcının mesajı ve cevap ekrandan siliniyordu. Effect yeniden çalışınca eski yükleme iptal ediliyor. | S-06: geçmişi olan kullanıcıyla önce kırmızı (yalnızca karşılama mesajı), sonra yeşil (mesaj + cevap görünür); N-23 kontrolleri yeşil kaldı. | **Kapandı** |

### N-30 — etkilenen uçlar, önce / sonra

| Uç | Önce | Sonra |
|---|---|---|
| `POST /api/note/adddialogbook.php` | `{chatbot_id, name, input_message, output_message}` aynen kaydediliyor; bot erişimi denetlenmiyor. | Yalnızca `{message_id, name}` (`user_id` yok sayılır; başka alan → 400). Mesaj, çağıranın kendi geçmişindeki bir **bot** mesajı olmalı (değilse 404); o bota sohbet erişimi yoksa 403; önünde kullanıcı sorusu yoksa 422. Soru ve cevap DB'den kopyalanır. |
| `POST /api/chat/addchat.php` | `sent_by:"bot"` ile serbest metin, erişim kontrolü yok ("Diyalog" sayacı şişirilebiliyordu). | Sohbet erişimi zorunlu (403). `bot` yalnızca botun `sohbet_basi_mesaj`'ı birebir ise; aksi 400. |
| `POST /api/chat/generatereply.php` | Cevabı istemci `addchat` ile kaydediyordu. | Sunucu akışı biriktirip cevabı kendisi kaydediyor; son karede `event: saved` `{"saved":{"message_id":N}}` (eklemeli). |
| `GET /api/chat/getchat.php` | `message, sent_by` | `id, message, sent_by` (`ORDER BY id`) — eklemeli. |
| `GET /api/note/getdialogues.php` | Tüm kayıtlar (özel bot adı/sahibi dahil). | Yalnızca botu vitrin kuralını (`ChatbotRepository::publicVisibleSql()`) geçen kayıtlar + izleyenin kendi paylaştıkları. |
| `getdialoginteracts.php`, `addcomment2.php`, `likedialog.php`, `dislikedialog.php`, `diduserlike2.php`, `diduserdislike2.php` | Kimlikle herhangi bir kayda erişim. | Aynı görünürlük kuralı; görünmeyen kayıt → 404. |
| İstemci (`chat/page.jsx`, `DialogNotebookModal.jsx`) | Bot cevabını kendisi kaydediyor; deftere metin gönderiyor. | `saved` kimliğini mesajda tutuyor; "Diyalog Defterine Ekle" yalnızca kimliği olan (sunucunun kaydettiği) cevapta; modal `{message_id, name}` gönderiyor. |

Çağıran araması (`web/src`, `api/admin`, `api/router.php`): `adddialogbook` → yalnızca `DialogNotebookModal.jsx`; `addchat` → yalnızca `chat/page.jsx` (kullanıcı mesajı + karşılama); `getdialogues` ve etkileşim uçları → `notes/page.jsx`, `DialogueModal.jsx`. Admin ve router'da çağıran yok.

**Sınır (B4):** Gemini anahtarı askıda olduğu için sunucunun gerçek bir cevabı kaydedip `saved` gönderdiği yol canlı modelle uçtan uca görülmedi. `geminiSseText` + `chatSaveBotReply` selftest'te kaydedilmiş SSE gövdesiyle, tarayıcı akışı ise aynı fonksiyonla yazılmış fixture satırıyla doğrulandı. Anahtar geldiğinde S-02/S-11 bir gerçek mesajla tekrarlanmalı.

**Mevcut kayıtlar — öneri: dokunma (veri değiştirme yok).** Yeni görünürlük kuralı özel/bağımsız botların kayıtlarını deploy anında başkalarından gizler; adı ve sahibi sızmaz. Herkese açık botlardaki eski kayıtların cevap metni istemci kaynaklıydı ve doğrulanamaz (o günkü bot cevabı satırlarını da istemci yazıyordu, yani geçmişle eşleşmesi kanıt değil). Silmek ya da gizlemek veri değiştiren bir migration olur ve onaya tabidir. Önerim: canlıda aşağıdaki salt okunur ölçümü görmek; `cevabi_gecmiste_yok > 0` ise gizleme kararını o sayıyla vermek. Yerel ölçüm (bu sorgu, 2026-10-09): `toplam 4, botu_silinmis 0, ozel_botta_gizlenecek 0, cevabi_gecmiste_yok 1`. Bu 1 kayıt doğrulamanın test kaydı #16; temizlikte sohbeti silindiği için kaynağı kalmadı. Bu, bir durumu da gösteriyor: sohbet silinince defter kaydı kalıyor; yani `cevabi_gecmiste_yok` tek başına "uydurma" demek değildir.

### N-30 mevcut kayıt sorgusu (salt okunur — canlı sırası adım 4b)

```sql
SELECT COUNT(*) AS toplam,
       SUM(c.id IS NULL) AS botu_silinmis,
       SUM(c.id IS NOT NULL AND NOT (c.is_independent = 0 AND (COALESCE(c.ucret_haftalik,0) = 0 AND COALESCE(c.ucret_aylik,0) = 0 OR pms.user_id IS NOT NULL))) AS ozel_botta_gizlenecek,
       SUM(NOT EXISTS (SELECT 1 FROM chatbot_chats b
                        WHERE b.user_id = udb.user_id AND b.chatbot_id = udb.chatbot_id
                          AND b.sent_by = 'bot' AND b.message = udb.output_message)) AS cevabi_gecmiste_yok
  FROM user_dialog_books udb
  LEFT JOIN chatbotlar c ON c.id = udb.chatbot_id
  LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id AND pms.status = 'active';
```

### N-33 — sohbet coin çipi iade sonrası güncellenmiyor (2026-10-09)

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-33** | P3 | `web/src/app/dashboard/chat/page.jsx` (`generateReply`, SSE `error` karesi) | Model hatasında sunucu hakkı iade edip `error {refunded:true}` gönderiyor, ama ilk `meta` karesinin çipe yazdığı tüketim sonrası değer (ör. 9) kalıyordu: "Bugün kalan Lumacoin: 9" ↔ kenar çubuğu 10/10. S-02 yeniden koşusunda görüldü. | **Kapandı** (`3f2959d`): `refunded:true` gelince değer sunucudan (`checkmessageallowance.php`) yeniden okunuyor. Tarayıcı (yeniden oynatılan SSE, Gemini'siz): önce çip 9 / sunucu 10 → sonra 10 / 10; N-23 kontrolleri yeşil kaldı. `canli-2026-10c`'de yok, **`canli-2026-10d`'de var**. |

## Hoppa canlıya açma (2026-10-09)

Hoppa canlı hesabı açıldı (merchant: lumanoris.net). **Bu bölümde hiçbir anahtar değeri yok;** değerleri sunucuya kullanıcı girer. Claude canlı `.env`'i görmez, canlıya bağlanmaz, ödeme komutu çalıştırmaz.

### 1. Canlı `api/.env` satırları

Kodun okuduğu adlar (`HoppaGateway.php:51-54`, `PaymentGatewayFactory.php:22`, `WalletController.php:547`). Okuma kuralı: `HOPPA_MODE=live` iken **yalnızca** `HOPPA_LIVE_*` okunur; mod `test` ise `HOPPA_TEST_*`.

| Satır | Ne | Not |
|---|---|---|
| `PAYMENT_PROVIDER=hoppa` | Paket ödemesini Hoppa'ya açan anahtar. | Bugün `none` (ya da satır yok). Ödeme açma gününün **son** değişikliği bu; geri dönüş de bu satırı `none` yapmak. |
| `HOPPA_MODE=live` | Canlı Hoppa servisi (`https://posservice.esnekpos.com`, GERÇEK PARA). | Yalnızca `test` ve `live` geçerli; başka değer → ödeme kapalı. |
| `HOPPA_LIVE_MERCHANT=` | Hoppa üye işyeri kimliği (panelde "Merchant"). | İstek gövdesinde `MERCHANT` olarak gider. |
| `HOPPA_LIVE_MERCHANT_KEY=` | Üye işyeri gizli anahtarı (panelde "Merchant Key"). | İstek gövdesinde `MERCHANT_KEY`; **log'a hiç yazılmaz** (gövde log'lanmaz, saklanan yanıttan `MERCHANT_KEY` ve `CUSTOMER_*` ayıklanır — `HoppaGateway.php:18, 335`). **Yenilenmiş anahtar girilmeli** (B3 notu). |
| `APP_PUBLIC_URL=https://…` | Sitenin dışarıdan açılan adresi, sonda `/` yok. Hoppa sonucu `APP_PUBLIC_URL/api/wallet/hoppa_return.php`'ye döndürür. | **Canlı modda `https` zorunlu** — değilse ödeme başlatılmaz (`WalletController.php:604-607`, log: "APP_PUBLIC_URL tanımsız/geçersiz"). Kullanıcıların siteyi açtığı alan adıyla **aynı** olmalı; farklıysa dönüşte oturum çerezi gelmez. |

Gerekmeyenler:
- **Canlı API base URL** — `.env`'de yok, kodda sabit (`HoppaGateway::LIVE_BASE_URL`).
- **Merchant Public Token** — kod **kullanmıyor** (`api/` ve `web/src`'de hiçbir referans yok; Ortak Ödeme Sayfası akışı yalnızca Merchant + Merchant Key ile çalışıyor). **Eklenmesine gerek yok.**
- `HOPPA_TEST_MERCHANT` / `HOPPA_TEST_MERCHANT_KEY` — canlıda boş kalır (test ortamı canlıda kullanılmaz).
- `HOPPA_E2E_*` — yalnızca yerel `--e2e` testi; canlıda yok.

Dikkat: `functions/env.php` **gerçek ortam değişkenini `.env`'e tercih eder.** Barındırma panelinde/servis tanımında `PAYMENT_PROVIDER` ya da `HOPPA_*` ortam değişkeni olarak tanımlıysa `.env` değişikliği etkisiz kalır. Değişiklikten sonra PHP sürecini (php-fpm / Apache) yeniden başlatın.

### 2. `PAYMENT_PROVIDER=hoppa`'dan önce kapanması gerekenler (tek liste)

| # | Madde | Tür | Durum (2026-10-09) |
|---|---|---|---|
| 1 | **Merchant Key yenilemesi** — anahtar sohbette paylaşıldı; Hoppa'dan yenileme, yeni anahtar canlıya girilir, eski anahtar geçersiz. | Kullanıcı / Hoppa | Bekliyor |
| 2 | **Sürüm** — canlıda `canli-2026-10f` ya da `canli-2026-10e` (asgari `canli-2026-10c`: N-31, dönüşte oturum düşmesi orada kapandı; daha eski sürümle ödeme açılmamalı). 10e, N-33/N-34/N-35'i de içerir; 10f yalnızca ön yüz düzeltmeleri ekler, ödeme yoluna dokunmaz. | Deploy | `canli-2026-10e` hazır |
| 3 | **N-19 komisyon** — test hesabında komisyon alıcıya yansıyor (149 ₺ → karttan 151,25 ₺). Canlı hesapta komisyonu kim ödeyecek? Ya Hoppa panelinde/üye işyeri ayarında "komisyon üye işyerinde" yapılır, ya da ekranlarda "+ komisyon" açıkça yazılır. | Müşteri kararı + Hoppa #3 | Açık |
| 4 | **N-20 alıcı adresi** — `CITY/STATE/ADDRESS` (ve telefon yoksa `PHONE`) `"-"` gidiyor; canlıda kabul ediliyor mu? | Hoppa #17 (+ müşteri: adres toplanacak mı) | Açık |
| 5 | **HTTPS ve alan adı** — `APP_PUBLIC_URL` https; TLS sertifikası; ters vekil `X-Forwarded-Proto: https` iletiyor (oturum çerezi `Secure` ancak öyle alır — J-02). | Altyapı (B6) | Açık |
| 6 | **Cron** — `*/5 * * * * cd /path/to/lumanoris && php api/cron/plan_payments.php >> /var/log/lumanoris-plan-payments.log 2>&1`. Hoppa webhook belgelemiyor; sekmesini kapatan alıcının ödemesi yalnızca bu mutabakatla kesinleşir. Yenileme hatırlatması (GK-27) da bu işte. | Sunucu | Kurulmadı (`canli-2026-10c`'de bilerek yok) |
| 7 | **Hoppa soruları** — #2 AUTH_HASH (bugün güven yalnızca sunucudan `ProcessQuery`'de; kabul edilebilir ama teyit iyi olur), #4 webhook (yoksa 6. madde şart), #5 ödeme sayfası geçerlilik süresi (kod 24 saat / 7 gün varsayıyor), #6 aynı ORDER_REF ile ikinci ödeme, #12 iade süre sınırı, #13 canlıya geçiş onayı, #14-16 PCI/KVKK/yasal metin koşulları. | Hoppa | Açık (`docs/hoppa-sorular.md`) |
| 8 | **Yasal metinler / şirket bilgileri** — mesafeli satış sözleşmesi, iade koşulları, ticari unvan/adres/vergi no sitede (B3 2026-09-05 notu; Hoppa #16). | Müşteri | Kontrol edilmeli |
| 9 | **Erken yenileme** — süre bitmeden ikinci paket alınırsa kalan günler devretmiyor (her ödeme `NOW()+30`). | İş kuralı | Karar yok (ekranda "30 gün, otomatik yenilenmez" yazıyor) |
| 10 | **Sohbet (B4)** — paketler günlük coin satıyor; canlıda Gemini çalışmıyorsa ödeyen kullanıcı hizmeti kullanamaz. | Hesap | Açık — ödeme açmadan önce kapanması **önerilir** |
| 11 | Kapsam notu — bu yalnızca **paket** ödemesini açar. Pazaryeri ücretli satışı (B1) kapalı kalır; iyzico kodu yalnızca eski satırlar için (canlı sırası adım 2b). | — | — |

Faz 8 bulguları (N-23…N-32) kapandı; ödeme yolunu doğrudan etkileyen N-31 `canli-2026-10c`'de.

### 3. Ödeme açma günü — sıra

Ön koşul: 2. bölümdeki 1-6 kapanmış, sürüm `canli-2026-10f` ya da `canli-2026-10e` (asgari `canli-2026-10c`), canlı veritabanı yedeği alınmış.

| Adım | Ne | Kontrol / durma koşulu |
|---|---|---|
| 1 | **Anahtar yenileme** (Hoppa) | Yeni Merchant Key elde; eski anahtarın geçersiz olduğu Hoppa'dan teyitli. |
| 2 | **Anahtarları girme** — canlı `api/.env`: `HOPPA_MODE=live`, `HOPPA_LIVE_MERCHANT`, `HOPPA_LIVE_MERCHANT_KEY`, `APP_PUBLIC_URL=https://…`. `PAYMENT_PROVIDER` **henüz `none`**. PHP sürecini yeniden başlat. | `php api/database/hoppa_selftest.php` (A + B; **`--e2e` canlıda çalıştırılmaz**) 0 başarısız. `.env` dosya izinleri yalnızca web kullanıcısı. |
| 3 | **Cron kurulumu** (2. bölüm #6) | Bir çalıştırma elle: `php api/cron/plan_payments.php` → hata yok (provider `none` iken mutabakat atlanır, normal). |
| 4 | **Açma** — `PAYMENT_PROVIDER=hoppa`, PHP yeniden başlat. | Paketler'de ücretli paket → "Ödemeye geç" görünür ("Ödeme altyapısı hazırlanıyor" gitmeli). |
| 5 | **Küçük tutarlı gerçek test ödemesi** — kendi hesabınızla **Gümüş** (en düşük tutar, 149 ₺; N-19'a göre karttan komisyonlu tutar çekilebilir). Hoppa sayfasının adresi `posservice.esnekpos.com` olmalı (`posservicetest` değil). | Dönüş: `/dashboard/upgrade?odeme=paid`, oturum açık, "Ödemeniz alındı…", kenar çubuğu Gümüş. Admin → Ödeme → Paket Ödemeleri: satır **Ödendi**, "TEST" etiketi **yok**. `?odeme=pending` görürseniz 5 dk bekleyin (cron); `failed` ise DURUN. |
| 6 | **Aynı gün iade** — **bizim** admin ekranımızdan: Paket Ödemeleri → satır → "İade et" → neden yaz → onayla. | Sonuç kutusu: "İade tamamlandı; paket geri alındı", Hoppa durumu **"iptal edildi (aynı gün)"**; kullanıcı Ücretsiz'e döndü. **Hoppa panelinden iade yapmayın:** orada yapılan iade bizim veritabanımıza yazılmaz, paket geri alınmaz ve satır "Ödendi" kalır. |
| 7 | **Kontrol** | Hoppa panelinde işlem iptal görünüyor; kartta provizyon iptali (banka ekstresi 1-2 gün); `admin_audit_log`'da iade satırı; PHP log'unda `[hoppaFinalize] paket tanımlandı` + `[admin/odemeler] iade … sonuc=ok`; cron log'unda hata yok. |
| 8 | **Geri dönüş** (herhangi bir adımda sorun) | `PAYMENT_PROVIDER=none` + PHP yeniden başlat → ödeme anında kapanır; mevcut paketler etkilenmez, admin iade ekranı `none` iken 503 verir (iade gerekirse önce sorunu çözüp tekrar açın ya da Hoppa ile görüşün). |

### 4. Yerelde canlı anahtarla deneme (isteğe bağlı)

**Bu deneme sırasında Claude `.env` okumaz, ödeme/iade komutu ya da tarayıcı ödemesi çalıştırmaz.** Gerçek para hareket eder; aynı gün iade edin.

**Önemli kısıt:** canlı modda `APP_PUBLIC_URL` **https olmak zorunda** (kod `http://localhost:3000`'u reddeder, "Ödemeye geç" hata verir). Yerel deneme için siteyi **https bir tünel** üzerinden açmanız gerekir (ör. bir HTTPS tünel aracıyla `localhost:3000`'a). Tarayıcıda da siteyi **o tünel adresinden** açın (localhost'tan değil); yoksa dönüşte oturum çerezi gelmez. Hoppa, BACK_URL'in kayıtlı alan adınızda (lumanoris.net) olmasını şart koşuyor olabilir — bilinmiyor; şart koşuyorsa yerel deneme dönüşte başarısız olur ve tek doğru deneme canlı alan adındadır (3. bölüm). Bu soruyu Hoppa'ya sormak iyi olur.

Yerel `api/.env`'de değişecek satırlar (önce mevcut hallerini not edin; bugün yerelde `PAYMENT_PROVIDER=hoppa` + `HOPPA_MODE=test` + test üye işyeri kullanılıyor):

| Satır | Deneme sırasında |
|---|---|
| `PAYMENT_PROVIDER` | `hoppa` |
| `HOPPA_MODE` | `live` |
| `HOPPA_LIVE_MERCHANT`, `HOPPA_LIVE_MERCHANT_KEY` | canlı değerler (yenilenmiş anahtar) |
| `APP_PUBLIC_URL` | tünelin `https://…` adresi (sonda `/` yok) |

Sonra `php -S` (API) sürecini yeniden başlatın. Akış 3. bölümün 5-6. adımlarıyla aynı; ödeme ve iade **yerel** veritabanına yazılır, iade yerel admin ekranından (Paket Ödemeleri) yapılır ve canlı Hoppa'da gerçekten iptal eder. Sekme kapanır ve `?odeme=pending` kalırsa: `php api/cron/plan_payments.php`'yi siz elle bir kez çalıştırın.

**Geri alma** (deneme biter bitmez):
1. `HOPPA_LIVE_MERCHANT` ve `HOPPA_LIVE_MERCHANT_KEY` satırlarını **boşaltın** (yerelde canlı anahtar kalmasın).
2. `HOPPA_MODE=test`, `APP_PUBLIC_URL=http://localhost:3000`, `PAYMENT_PROVIDER` eski değerine (ya da `none`).
3. `php -S`'i yeniden başlatın; tüneli kapatın.
4. Kontrol: Paketler → "Ödemeye geç" → açılan sayfa `posservicetest.esnekpos.com` (test) ya da "Ödeme altyapısı hazırlanıyor" (`none`) olmalı; `posservice.esnekpos.com` açılıyorsa canlı anahtar hâlâ okunuyor demektir.
5. Hoppa panelinde deneme işleminin iptal göründüğünü kontrol edin. Canlı anahtarı içeren bir `.env` kopyası (yedek, `.env.bak-*`) bırakmayın.

## Canlıdan gelen hatalar — N-34, N-35 (2026-10-10) → `canli-2026-10e`

Müşteri lumanoris.net'te bildirdi. İkisi de **`canli-2026-10d`'de var** (ayrıca `canli-2026-10` ve `canli-2026-10b`'de; N-34'ün sunucu tarafındaki sıkı izin listesi 2026-08-26'dan, Takip Edilenler'deki istek hiç yazılmamıştı). Canlıya hangi sürümün gittiğini kullanıcı kontrol edecek.

| ID | Sev | Dosya | Problem | Durum |
|---|---|---|---|---|
| **N-34** | P2 | `web/src/entities/user/ui/ProfileCard.jsx` (sohbet sayfası "Yorumlar"), `web/src/features/comments/CommentModal.jsx` | **Bot yorumu eklenemiyor ama listede görünüyor.** İstemci gövdeye `user_id` koyuyordu; `SocialController::addComment` yalnızca `chatbot_id` + `comment` kabul edip başka anahtarı 403 "Bu alanlar gönderilemez: user_id" ile reddediyor ve yazarı oturumdan alıyor (**sunucu doğru; izin listesi gevşetilmedi**). Ayrıca `CommentModal` yorumu sonuca bakmadan "Siz · Şimdi" olarak listeye ekliyordu. | **Kapandı** (`42998bb`): `features/comments/api.js` (`postBotComment`) `addcomment.php`'nin tek istemci yolu, kimlik göndermez. `CommentModal` satırı "Gönderiliyor…" olarak ekler; `onSend` `{ok:true}` dönmezse satırı geri alır, metni girişe geri koyar, hata mesajı pencerede kalır. |
| **N-35** | P2 | `web/src/app/dashboard/following/page.jsx` (`handleUnfollow`) | **Takip Edilenler'de takibi bırakma kalıcı değil.** İki sayfa karşılaştırıldı: sohbet sayfası `followchatbot.php`'ye `{chatbot_id}` (toggle) gönderiyor ve çalışıyor; Takip Edilenler **hiç istek göndermiyordu** — kartı yalnızca ekrandan siliyor ve "çıkarıldı" diyordu; sayfa yenilenince bot geri geliyordu. | **Kapandı** (`ee04666`): açık eylem `{chatbot_id, action:"unfollow"}` (toggle değil — bayat bir sayfa botu yeniden takip edemez); kart yalnızca sunucu başarı dönünce kalkar, hata olursa yerinde kalır ve mesaj gösterilir. Sunucu: `functions/social_relations.php` → `chatbotRelationSet()` (açık eylem idempotent; eylemsiz çağrı eski toggle, sohbet sayfası değişmedi; yanıt adları aynı + `following`/`liked` bayrağı + `unchanged`). |

### Tarama sonuçları

**N-34 sınıfı — sunucunun izin listesinin reddettiği alan gönderen istekler.** Sıkı izin listesi olan uçlar: `addcomment` (SocialController), `addchat`, `addconversation`, `updateconversation` (ChatController), `savechatbot`, `updatechatbot`, `updatecart`, `updatesubscription`, `adddialogbook`, pazaryeri başvurusu. Bunlardan **yalnızca `addcomment`** `user_id`'yi kontrolden önce düşürmüyordu; diğerleri düşürüyor ya da istemci izinli alan gönderiyor. Bu uca giden istemciler: `ProfileCard.jsx` (canlı) ve `entities/chatbot/ui/ChatbotCard.jsx` (hiçbir yerden import edilmiyor) — ikisi de düzeltildi. `web/src`'te `user_id` gönderen diğer ~20 istek, kimliği oturumdan alan ve `user_id`'yi yok sayan uçlara gidiyor (zararsız; sunucu kodunda gövdeden `user_id` okuyan tek yer admin'e özel `SellerController` satıcı silme). `features/notes/DialogueModal.jsx` (import edilmiyor, defter yorumu) de aynı sözleşmeye getirildi.

**N-35 sınıfı — sunucuya yazmadan "başarılı" görünen takip/beğeni/listeye ekle düğmeleri:**

| Yer | Durum (önce) | Sonuç |
|---|---|---|
| Takip Edilenler → kalp (takibi bırak) | İstek yok | Düzeltildi (N-35) |
| Keşfet + ana sayfa kartı (`BotCard.bento.jsx`) → kalp | Yalnızca yerel state; başlangıç durumu her zaman "beğenilmemiş" | Düzeltildi: `{chatbot_id, action:"like"/"unlike"}`, başlangıç `getchatbots.php` → `liked_by_me` (eklemeli, `optionalAuth`); arayüz yalnızca başarıda değişir |
| Aynı kart → yer imi ("Listeme Kaydet") | Yalnızca yerel state | Düzeltildi: gerçek "Listeye Ekle" penceresini açar |
| `AddToListModal` (sohbet sayfası + kart) | İsteklerin yanıtına bakmadan "Kaydedildi" | Düzeltildi: her yanıt kontrol edilir; hata varsa pencere açık kalır, mesaj gösterilir, durum sunucudan yeniden okunur |
| Sohbet sayfası takip/beğeni (`ProfileCard`) | Doğru çalışıyor | Değişmedi (eylemsiz toggle); regresyon kontrolü yeşil |
| Keşfet `?from=list` seçim modu | `localStorage`'a "liste" yazıyor, sunucuya değil | **Düzeltilmedi:** uygulamada bu adrese giden bağlantı yok (yalnızca adres elle yazılırsa açılıyor). Kaldırılması ya da gerçek listelere bağlanması önerilir. |
| `widgets/MarketCard.jsx` | Sabit (uydurma) yorumlar gösteriyor | **Düzeltilmedi:** hiçbir yerden import edilmiyor (ölü kod). Kaldırılması önerilir. |

### Doğrulama

- `access_selftest`: N-34 (tek çağıran + kimlik yok + sunucu izin listesi ve oturum kimliği kilitli), N-35 (10 kontrol: takip yokken "unfollow" yeniden takip etmez, idempotent follow, kalıcı unfollow, eylemsiz toggle korunuyor, like/unlike, `liked_by_me` beğenen/oturumsuz, geçersiz eylem reddi). Önce kırmızı, sonra yeşil.
- Tarayıcı, e2e-b + seed bot #27, masaüstü ve 390 px: **önce** (ana ağaç, dev): N-34 → 403 "Bu alanlar gönderilemez: user_id", yorum kayıtsız ama listede; N-35 → 0 istek, yenilemede bot geri geldi. **Sonra** (dev ve `ee04666` production build): N-34 → istek `{"chatbot_id":27,"comment":…}`, 200, kayıtlı; zorlanmış 500'de satır geri alındı, metin ve mesaj kaldı. N-35 → istek `{chatbot_id:27, action:"unfollow"}`, yenilemede gelmiyor, sunucuda takip yok. Kart kalbi yenileme sonrası basılı ve sunucuda kayıtlı; yer imi liste penceresini açıyor (detay penceresi açılmıyor); liste penceresi zorlanmış 500'de açık kalıp mesaj gösteriyor. Sohbet sayfası takip/beğeni regresyonu yeşil. 390 px: Takip Edilenler, Keşfet, ana sayfa, yorum ve liste pencereleri — yatay kaydırma/taşma yok.
- Tam doğrulama (`ee04666`): lint, verify build, `php -l`, iyzico 77/77, plan_limits --strict 44/44, access 59/59, application 52/52, hoppa 83/83, migrate 21/21; S-13 güvenlik smoke öncekiyle aynı.

### Not — kurala aykırı bir commit ve test yalıtımı

- `42998bb` (N-34) commit'i, N-35'in henüz kırmızı olan test bölümünü içeren `access_selftest.php` ile girdi: dosyayı N-34 bölümüne indirecek adım başarısız oldu ve zincir durmadı. lint/build/`php -l` yeşildi ama CLAUDE.md "doğrulamalardan biri başarısızsa commit atılmaz" diyor. Geçmiş yeniden yazılmadı; `ee04666` (N-35) ile test yeşil.
- `9b86d6b`: `hoppa_selftest`'in mutabakat sayaç kontrolü, yerel veritabanında kart girilmeden bırakılmış gerçek bir TEST siparişi (`PLN-7F32C6492C3C4534`, N-31 doğrulamasından) yüzünden kırmızıya düştü. Kod regresyonu değil, test yalıtım hatası: beklenen sayaç artık senaryo + senaryo dışı satırların son durumu. Veriye dokunulmadı.

Silme ucu olmadığı için kalan test verisi: seed bot #27'de e2e-b'nin 6 yorumu (#16–#21). Takip, beğeni ve listeler temiz.

## Arayüz taraması (→ `canli-2026-10f`) — "sunucuya gitmeden ya da yanıta bakmadan başarılı görünen" düğme/formlar (2026-10-10)

Yöntem: rotalardan (`app/**/page.jsx`, `layout.jsx`) başlayıp statik + `dynamic()` import grafiği izlendi → **151 canlı dosya**. Bunlardaki durum değiştiren **56 istek** tek tek okundu; ayrıca istek atmadan durum değiştiren yerel aç/kapa'lar ve başarı mesajı gösteren her satır (toast, "Kaydedildi/eklendi/silindi/alındı", `setShowFeedback`) çevresindeki istek ve yanıt kontrolüyle tarandı. Hiçbir rotadan erişilmeyen 36 dosya ayrıca listelendi (aşağıda).

### Bulgular (hepsi düzeltildi — bkz. Kapanış)

| ID | Dosya | Ne yapıyor | Sunucuya gidiyor mu | Yanıta bakıyor mu | Sonuç |
|---|---|---|---|---|---|
| **N-37** | `app/dashboard/history/page.jsx` — sil | Sohbeti siler | Evet | Bakıyor ama **başarısızlıkta da** satırı ekrandan siliyor ("Fallback UI deletion for preview resilience") | Yenilemede geri geliyor |
| **N-37** | aynı — yeniden adlandır | Başlığı değiştirir | Evet | **Hayır** (iyimser, geri alma yok) | Hata olursa yeni başlık yalnızca ekranda |
| **N-37** | aynı — ilk durum | Geçmiş listesi | Evet | `gethistory` başarısızsa | **Üç uydurma sohbet** ("Yapay Zeka Mimari Analiz", "Stripe Entegrasyon Kılavuzu", "Next.js App Router…") ilk durum olarak duruyor; istek başarısızsa kullanıcının geçmişi gibi gösteriliyor, başarılıysa da yüklenene kadar görünüyor |
| **N-38** | `widgets/DashboardHeader.jsx` — "tümünü okundu say" | Bildirimleri okundu yapar | Evet | **Hayır** (rozet hemen temizleniyor, istekler ateşle-unut) | Hata olursa yenilemede okunmamış geri geliyor |
| **N-39** | `widgets/DashboardHeader.jsx`, `widgets/Sidebar.jsx` — çıkış | Oturumu kapatır | Evet | **Hayır** (her durumda `/login`'e yönlendiriyor) | Çıkış başarısızsa kullanıcı çıktığını sanıyor (paylaşılan bilgisayarda risk) |
| **N-40** | `entities/user/ui/ProfileCard.jsx` (sohbet sayfası) — takip / beğen / beğenme | İlişkiyi değiştirir | Evet | Bakıyor (yalnızca başarıda değişiyor) ama **hata sessiz** | Kullanıcı neden olmadığını görmüyor |
| **N-40** | aynı — "Bildirimler Açık/Kapalı" | Bot bildirimi ayarı gibi görünüyor | **Hayır** — sunucuda karşılığı yok | — | Sahte ayar |
| **N-40** | aynı — sepet durumu | "Sepette" göstergesi | `getcart` ile sunucudan okunuyor; ayrıca **`localStorage` `cart`** anahtarından okuyan eski bir etki onu ezebiliyor (bu anahtara artık kimse yazmıyor) | — | Yanıltıcı yerel durum |
| **N-40** | aynı — `BlockModal` | "Engelle" penceresi | Hayır | — | Hiçbir yerden açılmıyor (erişilemeyen arayüz) |
| **N-41** | `features/sharing/ShareModal.jsx` — bot paylaş | Bağlantı üretip kopyalar | — | Pano işlemini **beklemeden** "Kopyalandı" | Ayrıca `urlId` verilince bağlantı `?botid=` (küçük harf) üretiyor; sohbet sayfası `botId` okuyor. **Düzeltme notu:** bu dal bugün gizli — `urlId`'yi yalnızca erişilemeyen bileşenler (`entities` `BotCard`, `ChatbotCard`, `DialogueModal`) veriyor; canlı sohbet sayfası `window.location.href` kullanıyor ve tarayıcıda `/dashboard/chat/?botId=27` üretip botu açtı. Yine de düzeltildi |
| **N-41** | `app/dashboard/notes/page.jsx` — diyalog paylaş | Bağlantıyı kopyalar | — | Pano işlemini beklemeden "Kopyalandı" | |
| **N-42** | `app/dashboard/notes/page.jsx` — "bu botu gizle" | Botun kayıtlarını gizler | Evet | Bakıyor (yalnızca başarıda) ama **hata sessiz** | |

### Kapanış (N-37…N-42)

Her madde ayrı commit; her commit `bash scripts/verify.sh` geçtikten sonra ayrı komutla atıldı. Kaynak düzeyindeki gerileme kilitleri `api/database/access_selftest.php` "Arayüz kilitleri" bölümünde (eski koda karşı kırmızı, yeniye karşı yeşil). Tarayıcı: Playwright, ilgili istek `page.route` ile 500'e zorlanarak; masaüstü + 390 px.

| ID | Commit | Değişiklik | Tarayıcı — önce | Tarayıcı — sonra |
|---|---|---|---|---|
| **N-37** | `b34d454` | Uydurma sohbetler kaldırıldı (ilk durum boş + yükleme hatası mesajı); silme yalnızca `success`'te; başlık iyimser ama başarısızlıkta eski ada döner | Başarısız silmede satır kayboldu; yeni başlık ekranda kaldı; istek hatasında üç uydurma sohbet | Satır kalıyor + hata; eski başlık geri + hata; uydurma yok, "yüklenemedi" mesajı |
| **N-38** | `9fa24be` | "Tümünü okundu" yalnızca sunucunun onayladığı bildirimleri işaretler; başarısızlıkta toast | Rozet temizlendi, sunucuda okunmamış kaldı, mesaj yok | Rozet duruyor, "Bazı bildirimler okundu olarak işaretlenemedi" |
| **N-39** | `cdac00f` | Başlık ve kenar çubuğu ortak `shared/lib/logout.js` (`logoutAndRedirect`) kullanıyor; `/login`'e yalnızca `success`'te gider | `/login`'e gitti, oturum sunucuda açık kaldı, mesaj yok | Sayfada kalıyor, hata mesajı, oturum açık (doğru: çıkış olmadı). Başarılı çıkış ayrıca denendi: `/login/`, oturum kapalı |
| **N-40** | `c863842` | Takip/beğeni/beğenmeme ortak `postRelation()`: başarısızlıkta toast. "Bildirimler Açık/Kapalı" düğmesi, `localStorage` `cart` etkisi ve açılmayan `BlockModal` kaldırıldı | Takip 500 → mesaj yok; sahte bildirim düğmesi vardı | "Takip güncellenemedi", "Beğeni güncellenemedi"; sayaç değişmiyor; düğme yok. Başarı yolu: takip/bırak ve beğen/geri al ikişer kez → sayaç ve durum doğru |
| **N-41** | `691a770` | `shared/lib/clipboard.js` (`copyText`) yazmayı bekler; "Kopyalandı" yalnızca başarıda, aksi hâlde satır içi uyarı (bot paylaş + diyalog paylaş). Instagram paylaşımında pencere tıklamayla aynı anda açılır, kopyalama sonucu sonra gösterilir. `?botid=` → `?botId=` | Pano reddedilse de "Kopyalandı" | Pano reddedildi → düğme "Kopyala", uyarı görünür; pano başarılı → "Kopyalandı", panoda doğru bağlantı (`?botId=27`, `?dialog=16`) |
| **N-42** | `d1f5ac5` | Diyalog Defteri "Bu Profili Önermeyin": başarısızlıkta toast | Mesaj yok | Kart sayısı aynı (4→4), hata görünüyor. Başarı yolu veri değiştirdiği için tarayıcıda denenmedi (kod yolu değişmedi) |

**N-43 (son doğrulamada bulundu, 10e'de de vardı):** 390 px'te bildirim paneli (`widgets/DashboardHeader.jsx`) zile göre sağa hizalı 320 px genişliğindeydi; sol kenarı ekranın dışına (−26 px) taşıp başlık ve metin kesiliyordu. 10e..10f arasında bu dosyada yerleşim değişmedi (yalnızca N-38/N-39 mantığı). Düzeltme: sarmalayıcı yalnızca `sm` üstünde `relative`; dar ekranda panel başlığa göre `inset-x-4`. Doğrulama: production build, 390 px panel 16–374 px (ekran içinde), dışarı tıklayınca kapanıyor; 390 px tarama (aşağıda) yeniden.

**390 px tarama (production, değişen ekranlar):** Geçmişim, Diyalog Defteri, Keşfet, sohbet, sohbet + Paylaş, bildirim paneli, profil menüsü — yatay kaydırma yok, taşan öğe yok (bildirim paneli N-43 sonrası). Küçük metin uyarıları (Paylaş penceresindeki 10 px uygulama adları, profil menüsü) bu turda değişmedi.

Ekran görüntüleri (yerel, `.gitignore`'da): `docs/verification/2026-10/N-37…N-43-*.png`, `S-14-10f-*.png`. `features/moderation/BlockModal.jsx` N-40 ile tek importerini kaybetti; aşağıdaki erişilemeyen dosyalar listesine eklendi (37).

### Doğru çalışanlar (yalnızca sunucu başarısında değişiyor)

Bot sil (Chatbotlarım), özel yap, yayınla (PublishModal), bot oluştur/güncelle, bilgi bankası (PDF/URL), fiyat (AddToSaleListModal), sepetten çıkar, liste oluştur/sil (Liste sayfası), listeye ekle/çıkar (AddToListModal — N-35), takibi bırak (Takip Edilenler — N-35), kart kalbi (N-35), yorum (N-34), diyalog defterine ekle (N-30), bildir (ReportModal), ilgilenmiyorum, sepete ekle, profil fotoğrafı, e-posta, telefon, ad/kullanıcı adı, iletişim formu (SMTP yoksa — B5 — hata döner, sahte başarı yok), Kurumsal Satışla Görüş, para çekme talebi, pazaryeri başvurusu, paket ödemesi, giriş/kayıt, şifre sıfırlama (iki adım).

### Uydurma / sabit veri (yalnızca liste)

| Yer | Ne | Durum |
|---|---|---|
| `app/dashboard/history/page.jsx` | Üç uydurma sohbet (ilk durum) | **N-37 ile kaldırıldı** (`b34d454`) |
| `app/dashboard/purchased/page.jsx` | "Koruma Tipi: Gelişmiş RAG" — veriden gelmiyor, sabit pazarlama metni | **N-44 ile düzeltildi** — "Bot Eğitimi: Belge ve URL metni modele verilir (en fazla 60.000 karakter)" |
| `app/components/landing/previews/*` | Landing'deki ürün önizlemeleri sabit örnek veri kullanıyor (ör. "1.000 ₺ — Hesabınıza Aktarın", "Bakiyeni Çek … tek tıkla hesabınıza güvenle hızlıca aktarın", önizleme içinde "Satın Al") | Listelendi — dekoratif önizleme, gerçek kullanıcı verisi gibi sunulmuyor; ama "tek tıkla hızlıca aktarın" iddiası bugünkü süreçle (B7: para çekme elle onaylanıyor) uyuşmuyor, metin müşteriyle gözden geçirilmeli. **Dokunulmadı:** soru `docs/musteri-ozet-2026-10.md` B) "Sorularımız"a eklendi (müşteri kararı BEKLİYOR) |

### N-44 — "RAG" iddiası (2026-10-10)

**Gerçek:** Ürün RAG ya da vektör arama yapmıyor. PDF/URL'den çıkarılan metin `chatbotlar.training_prompt`'a eklenir (`TrainingController`: `AppConfig::MAX_TRAINING_CHARS` = 60.000 karakter; aşan kısım kesilir, dolunca 413). Sohbette `ChatController` bu metnin tamamını (`LEFT(training_prompt, 60000)`) `[BİLGİ KAYNAĞI]` olarak sistem talimatına koyar; arama, parçalama, gömme (embedding) yok.

| Yer | Önce | Sonra |
|---|---|---|
| `app/dashboard/purchased/page.jsx` | Sabit kart "Koruma Tipi: Gelişmiş RAG" | "Bot Eğitimi: Belge ve URL metni modele verilir (en fazla 60.000 karakter)" |
| `app/dashboard/following/page.jsx` | Açıklaması olmayan botta "özel veri kaynakları ve gelişmiş RAG altyapısı ile donatılmıştır" | "Bu asistan için açıklama eklenmemiş." |
| `app/dashboard/chatbots/create/page.jsx` (Bilgi Bankası sekmesi) | Boyut yalnızca "N karakter"; kodda "(RAG)" yorumları | "N / 60.000 karakter" + çalışma biçimini anlatan kısa not (her mesajda olduğu gibi modele verilir, arama/vektör dizini yok, fazlası eklenmez); yorumlar düzeltildi |

Başka yerde RAG/vektör/embedding/semantik arama iddiası yok (`web/src`, `api/admin`, `README.md`, müşteri özeti tarandı). Web'deki yeni `KB_MAX_CHARS` sabiti `AppConfig::MAX_TRAINING_CHARS`'ın elle kopyası; `access_selftest` "Arayüz kilitleri" eşitliği ve RAG iddiasının geri gelmemesini kilitliyor. Landing "tek tıkla hızlıca aktarın" metnine dokunulmadı (müşteri kararı).

### N-45 — Keşfet düğmeleri (müşteri isteği, 2026-10-10)

Keşfet'e götüren düğmelerin etiketi tek kelime "Keşfet" oldu; arama kutusunun yanındaki "Keşfet" düğmesi (yalnızca masaüstünde görünüyordu) kaldırıldı. Keşfet'e kenar çubuğundan ve aramadan (Enter / büyüteç → `/dashboard/explore/?search=…`) gidiliyor.

| Dosya | Önce | Sonra |
|---|---|---|
| `widgets/DashboardHeader.jsx` | Arama kutusunun yanında "Keşfet" düğmesi | Kaldırıldı |
| `app/dashboard/following/page.jsx` | "Pazaryerinde Keşfet", boş durumda "Pazaryerine Git" | "Keşfet" |
| `app/dashboard/purchased/page.jsx` | "Pazaryerini Keşfet" (iki yer) | "Keşfet" |
| `app/dashboard/checkout/page.jsx` | Boş sepette "Pazaryerine Göz At" → `/dashboard` | "Keşfet" → `/dashboard/explore` |
| `app/dashboard/page.jsx` | Bot seçicide "Keşfet'te bot bul" | "Keşfet" |
| `app/components/landing/HeroSection.jsx` | "Hemen Keşfet" → `/dashboard/` | "Keşfet" (hedef aynı: kayıt istemeden ana sayfa) |

**Bilerek değiştirilmedi:** sepetteki "Pazaryerine Dön" (`checkout/page.jsx`) bir geri düğmesi (`history.back()`), Keşfet'e gitmiyor; "Keşfet" yazsaydı yanıltırdı. "Pazaryerine Kaydet" (PublishModal) gezinme değil, yayın seçeneği. Erişilemeyen `features/history/EmptyHistory.jsx` ("Keşfetmeye Başla") dokunulmadı. Doğrulama: `access_selftest` N-45 kilidi; tarayıcıda masaüstü + 390 px (başlıkta düğme yok, arama `/dashboard/explore/?search=Fitness`, Takip Edilenler ve Satın Aldıklarım "Keşfet" → `/dashboard/explore/`).

### Hiçbir rotadan erişilmeyen dosyalar (37, bu turda silinmedi)

`entities/chatbot/ui/BotCard.jsx`, `ChatbotCard.jsx`, `SuggestedCard.jsx` · `entities/user/ui/AccountPoints.jsx` · `features/chatbot-mgmt/ChatbotForm.jsx` · `features/contact/ContactForm.jsx` · `features/history/EmptyHistory.jsx` · `features/lists/AddToListModalEmpty.jsx` · `features/moderation/BlockModal.jsx` (N-40 sonrası) · `features/notes/DialogueModal.jsx`, `NotesEmpty.jsx` · `features/notifications/NotificationPopup.jsx` · `features/payment/CardFields.jsx` · `features/purchasing/BuyModal.jsx` · `features/settings/EditableField.jsx`, `EmailEditor.jsx`, `PhoneEditor.jsx`, `ProfileImageEdit.jsx` · `features/user-profile/ProfilePopup.jsx` · `features/wallet/WithdrawalModal.jsx` · `shared/api/client.js` · `shared/lib/card.js` · `shared/ui/Alert.jsx`, `avatar.jsx`, `separator.jsx`, `stat-card.jsx`, `switch.jsx` · `widgets/info/*` (8 dosya) · `widgets/LanguageSelector.jsx`, `MarketplaceToolbar.jsx`. Çoğu aynı hata sınıfını taşıyor (ör. `ChatbotCard`, `BuyModal`, `DialogueModal`); kullanılmadıkları için düzeltilmedi. Silinmeleri önerilir (CLAUDE.md'ye göre üçlü çağıran araması sonrası; `CardFields.jsx` / `card.js` N-17 kilidinin okuduğu dosyalar değil — kilit `checkout/page.jsx`, `upgrade/page.jsx` ve `features/payment/PlanPaymentModal.jsx`'e bakıyor).
