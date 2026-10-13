# Hoppa'ya geçiş — keşif raporu

Tarih: 2026-10-07 · Dal: `revize/pazaryeri`. İlk sürüm salt okuma, kodsuz. §0.2 gerçek Hoppa dokümanı ve Faz 7a test sonuçlarıyla eklendi.

Ödeme sağlayıcısı iyzico yerine **Hoppa (Elekse Elektronik Para ve Ödeme Kuruluşu A.Ş.)** olacak. Hoppa'nın API dokümanı ilk sürümde yoktu; artık var (§0.2). Bu rapor bugünkü kodun sağlayıcıya nerede bağlı olduğunu, Hoppa'yı takılabilir hâle getirmek için gereken en küçük soyutlamayı ve Hoppa'dan teyit edilmesi gerekenleri listeler.

## 0. Özet

- **Tahsilat iyzico'ya doğrudan bağlı, ama dar bir boğazdan geçiyor.** Bütün para hareketi `api/functions/checkout_payments.php` içindeki dört global fonksiyondan geçiyor: `chargeCard`, `cancelCharge`, `reconcilePayments`, `processRefund`. Bunların içi iyzico'ya özel; ortada bir arayüz (interface) yok. Çağıranlar ise büyük ölçüde sağlayıcıdan bağımsız bir dönüş şekli kullanıyor. Doğal ek yeri burası.
- **İki yerde iyzico kavramı sızıyor.** Kalem bazlı iade kimlikleri (`itemTransactions` / `paymentTransactionId`) iki controller'da ham yanıta yazılıyor. Alıcı bilgileri de iyzico'nun istediği biçimde hazırlanıyor (`buildIyzicoPaymentPayload`).
- **Satıcı (alt üye işyeri) kaydı iyzico değil, Param POS için yazılmış bir stub.** `ParamPosMarketplace` her yazma çağrısında `success:false` dönüyor; gerçek entegrasyon hiç yok. Hoppa için baştan yazılacak.
- **En büyük bilinmeyen: 3D Secure ve kart verisinin nereden geçtiği.** Bugünkü akış 3DS'siz ve senkron, kart numarası ile CVV bizim sunucumuza geliyor. Hoppa 3DS ya da barındırılan ödeme sayfası (hosted page / iframe) zorunlu tutarsa tahsilat iki adımlı hâle gelir (başlat → yönlendir → geri dönüş/bildirim). Bu değişiklik `createSubscription`, `upgradePlan` ve iki ödeme ekranını etkiler.
- **iyzico kodu bugün zaten kapalı:** Anahtarlar boş olduğu için `chargeCard` `CONFIG_MISSING` ile reddediyor; sahte başarı yok. Öneri: silmeden, bir sağlayıcı anahtarının arkasında kapalı tutmak (bkz. §5).

## 0.1 Hedef mimari (kullanıcı tercihi, 2026-10-07)

> Kart bilgilerinin bizim sunucumuzdan geçmemesini istiyoruz; Hoppa'nın ödeme sayfası (hosted payment page) veya 3D Secure yönlendirmeli akışını kullanmak istiyoruz.

Bu tercih §3'teki iki seçenekten **3DS / barındırılan sayfa** yolunu hedef yapar. Sonuçları:

- **Kart verisi:**
  - Kart numarası, son kullanma tarihi ve CVV hiçbir zaman Lumanoris sunucusuna, Express proxy'sine ya da log'larına ulaşmaz.
  - Bugün ulaşıyor: N-17, AUDIT.md.
  - Hedef PCI-DSS yükümlülüğü: tam yönlendirmede en hafif öz-değerlendirme (SAQ A). Hoppa'nın teyidiyle kesinleşir (soru listesi #32).
- **Akış iki adımlı olur:**
  1. `charge` ödeme oturumu başlatır. `param_marketplace_payments` satırı `pending` yazılır; D-04'teki tasarım bunu zaten destekliyor. Yanıt yalnızca yönlendirme adresi ya da form belirtecidir.
  2. Kullanıcı Hoppa sayfasında ödemeyi yapar ve sitemize geri döner.
  3. Sonuç **Hoppa'nın imzalı bildirimiyle (webhook)** ya da geri dönüşte sunucudan yapılan **sorgulamayla (`retrieve`)** kesinleşir. Geri dönüş URL'sindeki parametrelere tek başına güvenilmez.
  4. Abonelik, satın alma kredisi, satıcı payı satırları ve paket seçimi **yalnızca kesinleşme anında** yazılır. Bugün bunlar `createSubscription` / `upgradePlan` içinde tahsilatın hemen ardından yazılıyor; bu kısım ayrı bir "ödeme tamamlandı" işleyicisine taşınır ve idempotent olmalıdır (bildirim ile geri dönüş aynı ödemeyi iki kez kesinleştirmeye çalışabilir).
  5. Kullanıcı Hoppa sayfasında vazgeçerse `pending` satır `reconcilePayments` ile `failed` olur; sepet korunur.
- **Kaldırılacak/değişecek kod (Hoppa entegrasyonunda):**
  - Kart formları: `checkout/page.jsx`, `PlanPaymentModal.jsx`, `CardFields.jsx`, `shared/lib/card.js`.
  - `chargeCard`'daki kart biçim doğrulaması (Luhn/CVV — artık Hoppa'nın işi).
  - `buildIyzicoPaymentPayload`'daki kart alanları.
  - İki ödeme ekranı "Ödemeye geç" düğmesi + yönlendirme olur.
- **Bildirim ucu canlanır:** Bugün etkisiz olan `handleParamCallback` / `parampos_callback.php` yerine (ya da onun yerinde) Hoppa bildirim ucu.
  - İmza doğrulama, tekrar oynatma (replay) koruması ve idempotency şart.
  - Ucun kimlik doğrulaması kullanıcı oturumu değil, Hoppa imzasıdır.
  - Denylist'lerin (`api/.htaccess`, `api/admin/.htaccess`, `api/router.php`) bu ucu engellemediği kontrol edilmeli.
- **Etkilenen kısım:** §3'teki `PaymentGateway` arayüzü bu akışa göre `startCheckout(context): {redirect_url | form_token}` + `confirm(providerPaymentId): result` biçimini alır. `charge(card, …)` imzası Hoppa'da kullanılmaz.

## 0.2 Gerçek dokümana göre (2026-10-07)

Kaynak: <https://developer.esnekpos.com/llms.txt>. **Hoppa sanal POS'u EsnekPOS altyapısıdır**; doküman, alan adları ve test ortamı EsnekPOS'un. Aşağıdaki "test ortamında görüldü" notları Faz 7a sırasında dokümandaki herkese açık test üye işyeriyle yapılan gerçek çağrılardan.

### Erişim

- Base URL: test `https://posservicetest.esnekpos.com`, canlı `https://posservice.esnekpos.com` ("Başlangıç" sayfası). Test ortamı sayfası "test base adresi için bizimle iletişime geçiniz" diyor, ama yukarıdaki test adresi herkese açık test üye işyeriyle çalışıyor.
- Kimlik doğrulama: her isteğin gövdesinde `MERCHANT` + `MERCHANT_KEY`. İstek imzası (HMAC vb.) yok.
- Herkese açık test üye işyeri bilgileri ve başarılı/hatalı test kartları dokümanda yayımlanmış. Biz yine de bunları yalnızca `api/.env`'de tutuyoruz.

### Kullanılacak servisler ve bizim akışımızla eşlemesi

| Servis (uç nokta) | Ne yapıyor | Kart verisi bizden geçer mi? | Bizdeki karşılığı |
|---|---|---|---|
| **CommonPaymentDealer** (`POST /api/pay/CommonPaymentDealer`) — Ortak Ödeme Sayfası | Sipariş, müşteri ve ürün bilgisiyle ödeme oturumu açar; `URL_3DS` döner. Kullanıcı Hoppa sayfasında kartı girer; 3D doğrulamadan sonra sonuç `BACK_URL`'e **form POST** ile gelir. | **Hayır** | Paket satın alma (Faz 7a). Pazaryeri için de hedef, ama bkz. sınır (a). |
| **EYV3DPay** (`POST /api/pay/EYV3DPay`) — Pazaryeri ödeme alma | 3D ödeme; isteğe `SubMerchantDetails` (mağaza başına tutar) eklenir. Kart alanları (`CreditCard`: numara, son kullanma, CVV) **isteğin içinde**. | **Evet** (N-17'yi geri getirir) | Pazaryeri satışı — yalnızca bu serviste bölüştürme belgelenmiş. |
| **SubMerchantSet** (`POST /api/services/SubMerchantSet`) — Mağaza tanımlama/güncelleme | Satıcıyı alt üye işyeri olarak kaydeder: TCKN, vergi bilgisi, IBAN listesi, `TYPE`, bizim tarafımızdan atanan `EXTERNAL_ID`. Yanıt `ResultCode`/`ResultMessage`. Onay süreci ve bildirim belgelenmemiş. | — | Pazaryeri başvurusu (`marketplace_applications`) → satıcı kaydı; `ParamPosMarketplace` stub'ının yerini alır (B1). `SubMerchantQuery` ile durum sorgulanır. |
| **PaymentConfirm** (`POST /api/services/PaymentConfirm`) — Pazaryeri ödeme onaylama | Mağazanın payının serbest bırakılması. Pazaryeri, ödemedeki tüm mağazaları onaylayınca kendi kalanını alır. | — | Bugünkü "ödeme alındı → satıcı payı defterde → elle IBAN ödemesi" (B7) yerine: teslim/iade süresi dolunca onay. |
| **OrderReturn** (`POST /api/services/OrderReturn`) — İade/İptal | Sipariş referansı + tutarla iade ya da iptal. `SYNC_WITH_POS=false` ise talep kaydedilir, operasyon tamamlar. Pazaryeri iadesinde `SubMerchantDetails` ile mağaza belirtilir. | — | `processRefund` / `cancelCharge` karşılığı. Kalem bazlı değil, **sipariş + tutar** bazlı. |
| **ProcessQuery** (`POST /api/services/ProcessQuery`) — dokümandaki adıyla PROCCESS_QUERY | Sipariş referansıyla ödeme durumu ve alt işlem hareketleri. Doküman: "ödeme durumundan emin olmak için bu sorgunun cevabını dikkate alın; server-to-server yapın." | — | Kesinleştirme: `BACK_URL` dönüşünde ve mutabakatta. Geri dönüş POST'una tek başına güvenilmez. |

**ProcessQuery'nin test ortamında görülen yanıtları:**

| Durum | Yanıt | Bizim yorumumuz |
|---|---|---|
| Ödeme başarılı | `STATUS=SUCCESS`, `RETURN_CODE=0`, `SUCCESS_TRANSACTION_ID>0`, hareket `STATUS_ID=3` ("Ödeme - Başarılı") | `paid` |
| Kart reddedildi (test kartı 5100050000006661, hata 51) | `STATUS=ERROR`, `RETURN_CODE=100`, `RETURN_MESSAGE="51-Limit Yetersiz"`, son hareket `STATUS_ID=4` | `failed` |
| Oturum açıldı ama ödenmedi (sekme kapatıldı) | `RETURN_CODE=400`, "Referans numarası bulunamadı" | `pending` — kullanıcı hâlâ ödeyebilir, **failed sayılmaz** |
| İptal / iade (dokümandan, test edilmedi) | İptal: `STATUS=ORDER_CANCEL`, `RETURN_CODE=300`; iade: `STATUS=SUCCESS` + hareket `STATUS_ID=7` | ödenmiş sayılmaz |

### Sınırlar

**(a) Pazaryeri bölüştürmesi yalnızca kart verisini sunucudan isteyen EYV3DPay'de belgelenmiş.** `SubMerchantDetails` Ortak Ödeme Sayfası (CommonPaymentDealer) isteğinde yok; dokümanda bu alanı geçiren sayfalar yalnızca pazaryeri servisleri, işlem sorgulama ve listeleme. Hedef mimari (§0.1: kart bizden geçmesin) ile pazaryeri bölüştürmesi bugünkü dokümana göre **birlikte sağlanamıyor**. Hoppa'ya ilk soru bu (`docs/hoppa-sorular.md` #1). Cevap gelene kadar pazaryeri satışı Hoppa'ya bağlanmıyor ve N-17 ara önlemi (kart formu yok) pazaryeri için sürüyor.

**(b) Tekrarlı ödeme (RecurringPayment) kart verisini sunucudan istiyor ve bölüştürme yok.** `RecurringPayment` ve `RecurringPaymentCardAdd` isteklerinde `CC_NUMBER`/`CC_CVV` var. Kart bizden geçmeyecekse otomatik yenileme kurulamaz. **Karar (GK-27, AUDIT.md):** otomatik yenileme yok. Her dönem Ortak Ödeme Sayfası ile **manuel yenileme** ve süre bitmeden hatırlatma. Bu, D-05'teki "30 günlük tek seferlik satış" kuralıyla zaten uyumlu.

### Dokümanda olmayanlar / test ortamında görülen farklar

- **Webhook yok.** Belgelenmiş tek bildirim, kullanıcının tarayıcısı üzerinden `BACK_URL`'e yapılan form POST (tekrarlı ödemede JSON POST). Kullanıcı Hoppa sayfasında sekmeyi kapatırsa bize hiçbir şey gelmez. Bu yüzden `hoppa_pending` satırları için periyodik ProcessQuery mutabakatı gerekiyor (Faz 7a'da yok; bkz. AUDIT).
- **AUTH_HASH:** `BACK_URL` POST'unda geliyor; test ortamında **ProcessQuery yanıtında yok**. Algoritma dokümanda yok ("destek@esnekpos.com'a başvurun"). Bu yüzden geri dönüş POST'una hiç güvenmiyoruz; tek kaynak sunucudan yapılan ProcessQuery.
- **Komisyon alıcıya yansıyor (test üye işyerinde):** 149,00 ₺'lik sipariş için Hoppa sayfası "151,25 TRY ÖDEME YAP" gösterdi; karttan 151,25 çekildi (`COMMISSION=2,25`, `COMMISSION_RATE=1,490`). Canlı üye işyerinde bunun ayarı Hoppa'ya soruldu (N-19, AUDIT.md).
- `ORDER_REF_NUMBER` en fazla 24 karakter.

### §0.1 ve §3'e etkisi

- §0.1 adım 3'teki "imzalı bildirim (webhook)" Hoppa'da yok. Kesinleşme `BACK_URL` dönüşünde ve mutabakatta ProcessQuery ile yapılıyor.
- §0.1 adım 5: vazgeçilen ödeme ProcessQuery'de "bulunamadı" döndüğü için hemen `failed` yazılamaz; zaman aşımıyla kapatılması gerekiyor (mutabakat işi).
- §3'teki arayüz uygulandı, ama daha dar: `api/src/Domain/Interfaces/PaymentGatewayInterface.php` (`isConfigured`, `startHostedPayment`, `queryPayment`). `charge(card, …)` yok. iyzico bu arayüze taşınmadı (kullanıcı talimatı: iyzico koduna dokunma); pazaryeri hâlâ `chargeCard` (iyzico, anahtarsız → kapalı).

## 1. Envanter — sağlayıcıya bağlı her şey

### 1.1 Tahsilat çekirdeği

| Dosya / sembol | Ne yapıyor | iyzico'ya özgü mü? |
|---|---|---|
| `api/src/Infrastructure/Payment/IyzicoClient.php` | HTTP istemcisi ve IYZWSv2 imzası (`randomKey + uri + body`, HMAC-SHA256). `createPayment`, `retrievePayment`, `cancelPayment`, `refund(paymentTransactionId, price)`. Yardımcılar: `money()`, `date()`, `balanceBasket()` (yuvarlama farkını son kaleme yazar), `redact()` (yanıttaki kart/kişisel veriyi maskeler). | Tamamen |
| `checkout_payments.php` → `chargeCard($card, $amount, $context)` | Kartı biçimsel olarak doğrular (Luhn, CVV, son kullanma tarihi), sonra iyzico'dan tahsil eder. Dönüş: `success, message, payment_id, conversation_id, net_amount, item_transactions, error_code, raw`. | Gövde iyzico'ya özgü; **dönüş şekli büyük ölçüde nötr** |
| `buildIyzicoPaymentPayload()` | Alıcı, adres ve sepet kalemlerini iyzico biçimine getirir. Alıcı TCKN'si **sabit `11111111111`** gönderiliyor (B3 notu). | Tamamen |
| `cancelCharge($paymentId, $ip, $orderId)` | Tahsilattan sonra bizim tarafta bir adım patlarsa aynı gün tam iptal yapar (telafi). | Gövde iyzico'ya özgü |
| `reconcilePayments($db, $conn)` | `pending` kalan ödemeleri sağlayıcıdan sorgular (`retrievePayment`); sonucu `paid` ya da `failed` olarak yazar. | Gövde iyzico'ya özgü |
| `processRefund($db, $conn, $data)` | İade: kilit, idempotency, kalem bazında iade ve erişimin geri alınması (`revokeRefundedAccess`), denetim (`refundAudit`). İade, iyzico'nun kalem başına ürettiği `paymentTransactionId` üzerinden yapılıyor. | Kalem bazlı iade modeli iyzico'ya özgü |
| `handleParamCallback()` | Param POS'un asenkron bildirim ucu. Bilinçli olarak hiçbir şey yapmıyor; iyzico'nun 3DS'siz akışında bildirim yok. | Hoppa'da muhtemelen **yeniden canlanacak** |
| `luhnCheck`, `clientIp`, `normalizeTurkishGsm` | Sağlayıcıdan bağımsız yardımcılar. | Hayır |
| `ensureParamMarketplaceTables()` | Eski tembel tablo oluşturma; tablolar artık migration ile geliyor. | Hayır |

### 1.2 Çağıranlar ve uç noktalar

| Uç nokta | Controller | Ne yapıyor | Kullanılan çekirdek |
|---|---|---|---|
| `POST /api/marketplace/createsubscription.php` | `MarketplaceController::createSubscription` | Sepetteki botları satın alır (`payment_group = PRODUCT`): ödeme satırı (`pending`), tahsilat, abonelik ve satın alma kredisi, satıcı payı satırları. Hata olursa telafi iptali. | `chargeCard`, `cancelCharge`; ham yanıta `itemTransactions` yazıyor (satır ~851) |
| `POST /api/wallet/upgradeplan.php` | `WalletController::upgradePlan` | Üyelik paketi satın alma (`payment_group = SUBSCRIPTION`), 30 günlük tek seferlik satış (D-05). | `chargeCard`, `cancelCharge`; ham yanıta `itemTransactions` yazıyor (satır ~585) |
| `POST /api/seller/marketplace_refund.php` | `SellerController::refund` (admin) | İade başlatır. **2026-10-07'den beri kapalı (410, N-22: CSRF yoktu);** iade yalnızca admin paneli "Paket Ödemeleri" ekranından. | `processRefund` (kodda duruyor, giriş noktası yok) |
| `GET/POST /api/seller/marketplace_reconcile.php` | `SellerController::reconcile` (paylaşılan sır `PARAM_RECONCILE_SECRET`) | Belirsiz kalan ödemelerin mutabakatı (cron için). | `reconcilePayments` |
| `POST /api/seller/parampos_callback.php` | `SellerController::paramposCallback` (`PARAM_CALLBACK_SECRET`) | Sağlayıcı bildirimi; bugün etkisiz. | `handleParamCallback` |

### 1.3 Satıcı (alt üye işyeri) — Param POS stub'ı

| Dosya / uç | Ne yapıyor |
|---|---|
| `api/functions/ParamPosMarketplace.php` | `addSubMerchant`, `listSubMerchants`, `updateSubMerchant`, `deleteSubMerchant`, `listIller`, `listIlceler` — hepsi stub; yazma çağrıları her zaman `success:false` dönüyor (fail-closed, B1). Kişisel veriyi log'da maskeliyor. |
| `/api/seller/submerchant_register.php`, `submerchant_resubmit.php` | `SellerController::register` — banka/kimlik bilgisinden satıcı kaydı dener; stub yüzünden `rejected` yazar. Web arayüzünden çağıranı artık yok (B1 için duruyor). |
| `/api/seller/submerchant_status.php` | Kullanıcının eski satıcı durumu. |
| `/api/seller/submerchant_list.php`, `submerchant_list_remote.php`, `submerchant_update.php`, `submerchant_delete.php` | Admin uçları (yerel tablo / sağlayıcı listesi / güncelleme / silme). |
| `/api/seller/list_iller.php`, `list_ilceler.php` | İl/ilçe listesi; sağlayıcı kodlarıyla (`il_kod`, `ilce_kod`). Stub boş dönüyor, 15 dk önbellekli. |

### 1.4 Tablolar ve kolonlar

Şemada `param_*` adları **bilinçli olarak korunmuş** (içlerinde veri var). Anlamları sağlayıcıdan bağımsız:

| Tablo | Kolonlar | Rolü |
|---|---|---|
| `param_marketplace_payments` | `order_id, user_id, status (pending/paid/failed/refunded/partial_refund/unknown), amount, product_amount, service_fee, param_transaction_id (= sağlayıcı ödeme kimliği), param_receipt_id (= bizim order_id / conversationId), param_net_amount (= sağlayıcının komisyon sonrası net tutarı), redirect_url, items_json, seller_splits_json, param_response_json (= maskelenmiş ham yanıt; iyzico'da itemTransactions burada), callback_json, created_at, updated_at` | Her tahsilat girişimi; tahsilattan ÖNCE `pending` yazılıyor (D-04). |
| `param_marketplace_details` | `payment_id, seller_user_id, chatbot_id, guid_altuyeisyeri, gross_amount, payable_amount, pysiparis_guid, status, param_response_json, refunded_at` | Satıcı başına kalem ve hak ediş. Bakiye hesabı bu tablodan yapılıyor. |
| `param_marketplace_refunds` | `payment_id, detail_id, pysiparis_guid, amount, reason, requested_by_user_id, status, param_response_json` | İade kayıtları. |
| `param_marketplace_alerts` | `alert_type, severity, order_id, user_id, seller_user_id, message, context_json` | Operatör uyarıları (ör. "elle iade gerekiyor"). |
| `param_marketplace_soap_log` | `order_id, method, wsdl, request_xml, response_xml, result_code, …` | Param POS SOAP log'u (kullanılmıyor). |
| `param_marketplace_sellers` | `user_id, guid_altuyeisyeri, status, tip, last_error, param_payload_json` | Eski satıcı durumu (B1). Yeni başvurular `marketplace_applications`'ta (016). |

Ödemenin sonucunu tüketen, ödemeden bağımsız tablolar: `user_subscriptions`, `chatbot_purchase_credits`, `user_plan_selection`, `user_cart`, `banka_bilgileri` (IBAN), `para_cekme_talepleri` (elle satıcı ödemesi, B7), `admin_audit_log` (017).

### 1.5 Ortam değişkenleri (yalnızca adlar)

`IYZICO_API_KEY`, `IYZICO_SECRET_KEY`, `IYZICO_BASE_URL` (varsayılan sandbox) · `PARAM_CALLBACK_SECRET`, `PARAM_RECONCILE_SECRET`.

### 1.6 Arayüz ve test

| Dosya | Not |
|---|---|
| `web/src/app/dashboard/checkout/page.jsx` | Kart formu → `createsubscription.php`. **Kart numarası ve CVV bizim sunucumuza geliyor** (PCI-DSS kapsamı). |
| `web/src/app/dashboard/upgrade/page.jsx` + `features/payment/PlanPaymentModal.jsx`, `CardFields.jsx` | Paket ödemesi → `upgradeplan.php`, aynı kart akışı. |
| `web/src/shared/lib/card.js` | Luhn, biçimlendirme, `toCardPayload`. |
| `api/database/iyzico_selftest.php` | A bölümü çevrimdışı (imza, tutar biçimi, sepet toplamı); B bölümü sandbox'ta gerçek tahsilat + iptal (anahtar varsa). |
| `api/admin/odemeentegrasyon.php` | Yer tutucu metin ("Param POS ayarları yapılandırılmadı"). |
| `api/admin/parcekme.php` | Elle satıcı ödemesi (B7) — sağlayıcı satıcıya otomatik ödeme yaparsa gereksizleşebilir. |

## 2. Bağımlılık analizi — arayüz var mı?

**Yok.** Sağlayıcı seçimi yapan bir arayüz ya da fabrika bulunmuyor; `new IyzicoClient()` dört fonksiyonun içinde doğrudan çağrılıyor. Yine de yapı soyutlamaya yakın:

- **Nötr olanlar:**
  - Çağıranların büyük kısmı yalnızca `chargeCard` dönüşündeki `success, message, payment_id, net_amount, error_code` alanlarını kullanıyor.
  - Ödeme satırı yaşam döngüsü (`pending` → `paid`/`failed`/`unknown`), idempotency, telafi iptali ve mutabakat mantığı sağlayıcıdan bağımsız.
- **iyzico'ya sızanlar:**
  1. `MarketplaceController` (~851) ve `WalletController` (~585) `itemTransactions`'ı ham yanıta yazıyor; `processRefund` iadeyi bundan yapıyor. Kalem bazlı iade kimliği iyzico kavramı.
  2. `buildIyzicoPaymentPayload` alıcı ve adres alanlarını iyzico'nun şemasına göre kuruyor; sabit TCKN gönderiyor.
  3. `payment_group` (`PRODUCT` / `SUBSCRIPTION`) iyzico parametresi.
  4. Akış senkron ve 3DS'siz varsayıyor: `chargeCard` tek çağrıda kesin sonuç bekliyor; bildirim (callback) yolu ölü.
  5. Satıcıya pay dağıtımı sağlayıcıda yapılmıyor: `seller_splits_json` ve `param_marketplace_details` yalnızca bizim defterimiz, para elle IBAN'a gönderiliyor (B1/B7).

## 3. Hoppa'yı takılabilir yapmak için en küçük soyutlama

Amaç: çağıranları (controller'lar) değiştirmeden sağlayıcıyı tek yerden seçmek.

```php
interface PaymentGateway {
    public function isConfigured(): bool;
    /** Senkron sağlayıcıda kesin sonuç; 3DS/hosted sağlayıcıda 'requires_action' + redirect_url. */
    public function charge(array $card, float $amount, array $context): array; // bugünkü chargeCard dönüş şekli + 'refund_refs'
    public function cancel(string $providerPaymentId, string $ip, string $orderId): bool;
    public function retrieve(string $providerPaymentId, string $orderId): array; // ['status' => paid|failed|pending, 'raw' => …]
    public function refund(array $payment, array $refundRefs, float $amount, string $ip): array;
    public function capabilities(): array; // ['three_ds' => bool, 'hosted_page' => bool, 'item_refund' => bool, 'sub_merchant' => bool, 'recurring' => bool]
}
```

- `IyzicoGateway`: bugünkü kodun taşınmış hâli (davranış değişmez). `HoppaGateway`: doküman gelince.
- Sağlayıcı `PAYMENT_PROVIDER` ortam değişkeniyle seçilir: `iyzico | hoppa | none`. **Varsayılan `none`**: fail-closed, bugünkü `CONFIG_MISSING` davranışıyla aynı.
- `checkout_payments.php`'deki dört fonksiyon ince yönlendiricilere dönüşür, imzaları korunur. Böylece `createSubscription`, `upgradePlan`, `refund` ve `reconcile` çağrı yerleri değişmez.
- Sızıntı 1'in giderilmesi: controller'lar `itemTransactions` yerine dönüşteki nötr `refund_refs` alanını yazar; geçiş döneminde iyzico satırları için `processRefund` eski `itemTransactions`'ı da okur.
- Sızıntı 2'nin giderilmesi: alıcı/adres eşlemesi gateway'in içine taşınır.
- Alt üye işyeri için ayrı, küçük bir arayüz (`SubMerchantGateway`: `register`, `update`, `delete`, `status`); `ParamPosMarketplace` stub'ının yerini alır.

**Bu soyutlama 3DS/hosted akışı tek başına çözmez.** Hoppa 3DS ya da barındırılan sayfa zorunlu tutarsa ayrıca şunlar gerekir:
- `charge` "başlatıldı" döner.
- Ödeme satırı `pending` kalır; mevcut D-04 tasarımı buna uygun.
- Kullanıcı Hoppa sayfasına yönlendirilir.
- Sonuç bildirim (callback) ya da geri dönüşte `retrieve` ile kesinleşir; abonelik/kredi/satıcı payı **ancak o anda** yazılır.

Bu, `createSubscription` ve `upgradePlan`'ın tahsilat sonrası kısmının ayrı bir "ödeme tamamlandı" adımına taşınması, iki ödeme ekranının da kart formu yerine yönlendirme kullanması demek. Tahmini kapsam: Hoppa API'si senkron kart API'siyse küçük, değilse orta-büyük.

## 4. Hoppa'dan teyit edilmesi gerekenler

> Dokümandan önce yazılmış liste; tarihsel olarak duruyor. Güncel ve kısaltılmış sorular: `docs/hoppa-sorular.md`.

Akışımızın ihtiyaç duyduğu her çağrı için:

**Ürün ve hukuki çerçeve**
1. Pazaryeri / alt üye işyeri ürünü var mı? Satıcı payı platform hesabına girmeden doğrudan satıcıya mı dağıtılıyor? (B1 / COMP-009: bugünkü "biz topluyoruz, elle dağıtıyoruz" modeli lisans gerektiriyor.)
2. Hak ediş (settlement) satıcının IBAN'ına otomatik mi gönderiliyor, süresi ne? Otomatikse elle ödeme süreci (B7) ve `para_cekme_talepleri` akışı gereksizleşir.
3. Komisyon nasıl tanımlanıyor (kalem bazında oran/tutar)? Bizim %85 / %80 satıcı payı kuralımız (`SELLER_COMMISSION_*`) uygulanabilir mi?

**Alt üye işyeri (satıcı) API'si**
4. Oluşturma, güncelleme, silme ve sorgulama çağrıları; zorunlu alanlar (şahıs/kurumsal ayrımı, TCKN/VKN, vergi dairesi, MERSİS, IBAN, adres, yetkili, doğum tarihi). Başvuru formumuzdaki (016) alanlar yetiyor mu?
5. KYC/onay süreci: senkron mu, asenkron mu? Onay/ret bildirimi (webhook) var mı? Satıcı durum değerleri neler?
6. İl/ilçe gibi kod listeleri Hoppa'dan mı alınıyor, serbest metin mi?

**Kartla ödeme**
7. Doğrudan kart API'si (kart verisi bizim sunucudan geçer) mi, barındırılan ödeme sayfası / iframe / tokenizasyon mu? PCI-DSS yükümlülüğümüz ne olur?
8. 3D Secure zorunlu mu? 3DS'siz (non-3DS) çekime izin var mı? 3DS akışının geri dönüş ve bildirim biçimi?
9. Sepet / kalem bazında tahsilat: tek ödemede birden çok satıcının kalemi olabilir mi? Kalem başına alt üye işyeri atanabiliyor mu?
10. Tutar biçimi, para birimi (TRY), asgari/azami tutar, taksit.
11. Alıcı için zorunlu alanlar: gerçek TCKN gerekiyor mu? (iyzico'ya sabit `11111111111` gidiyordu.) Telefon, adres zorunlu mu?
12. Sipariş kimliği (bizim `order_id`) ile idempotency: aynı sipariş iki kez gönderilirse ne olur?

**Tahsilat sonrası**
13. Aynı gün iptal (void) çağrısı — telafi akışımız (`cancelCharge`) buna dayanıyor.
14. İade: tam ve kısmi; kalem bazında mı, ödeme bazında mı? Süre sınırı? Aynı iadenin tekrar gönderilmesine karşı koruma (idempotency)?
15. Ödeme sorgulama (sipariş ya da ödeme kimliğiyle) — mutabakat (`reconcilePayments`) için.
16. Zaman aşımı ve belirsiz sonuç: istek yanıtsız kalırsa sonuç nasıl öğrenilir?
17. Bildirim (webhook): olaylar (ödeme, iade, satıcı onayı, hak ediş), imza doğrulama yöntemi, yeniden deneme politikası, beklenen yanıt.

**Abonelik**
18. Tekrarlayan ödeme (kart saklama/token + periyodik çekim) desteği var mı? Bugün paketler 30 günlük **tek seferlik** satış ve otomatik yenileme yok (D-05). Otomatik yenileme istenirse bu bir iş kararıdır ve kart saklama gerektirir.

**Teknik erişim**
19. Kimlik doğrulama / imza şeması, API anahtarları.
20. Test (sandbox) ortamı, test kartları, test alt üye işyerleri.
21. Hata kodları listesi (kullanıcıya gösterilecek Türkçe mesajlar).
22. Hız limitleri ve IP kısıtları (sunucu IP'si beyaz listeye alınacak mı?).

## 5. iyzico koduna ne olacak — öneri

Silme yok. Hoppa çalışana kadar **kapalı** kalsın:

1. **Bugün:** iyzico anahtarları boş kalsın. `chargeCard` zaten `CONFIG_MISSING` ile reddediyor, sahte başarı yok; iade ve mutabakat da anahtarsız çalışmıyor. Ek kod gerekmiyor.
2. **Hoppa kodlamasına başlanırken (ilk adım):** §3'teki arayüz ve `PAYMENT_PROVIDER` anahtarı. iyzico kodu `IyzicoGateway` olarak taşınır, varsayılan `none` olur. Böylece biri yanlışlıkla iyzico anahtarı girse bile sağlayıcı açıkça seçilmeden tahsilat yapılmaz.
3. **`iyzico_selftest.php`:** A bölümü (çevrimdışı) çalışmaya devam eder. B bölümü (sandbox tahsilatı) yalnızca `PAYMENT_PROVIDER=iyzico` ise çalışacak şekilde koşullanır; şu an anahtar varsa çalışıyor.
4. **Tablolar:** `param_*` adları ve verisi olduğu gibi kalır. Hoppa satırları aynı kolonlara yazılır (anlamlar §1.4'te nötr). Eski iyzico satırlarının iadesi için iyzico gateway'i "yalnızca iade/sorgulama" modunda tutulabilir; canlıda iyzico ile gerçek ödeme alınmadıysa (iyzico başvurusu reddedildi, B3) bu gerekmeyebilir. **Canlıda `param_marketplace_payments`'ta iyzico kaynaklı `paid` satır olup olmadığı kontrol edilmeli.**
5. **Arayüz:** Ödeme kapalıyken kullanıcı bugün kart bilgisini girip hata alıyor. Hoppa'ya kadar ödeme ekranlarında "ödeme altyapısı hazırlanıyor" gösterilmesi önerilir. Bu küçük bir arayüz değişikliği, ayrıca onay ister.
6. **Belgeler:** `IyzicoClient.php` ve `checkout_payments.php` başlıklarına "sağlayıcı Hoppa'ya geçiyor; bu kod kapalı" notu; README'nin Payments bölümü güncellenir. Kod değişikliğiyle birlikte, şimdi değil.

## 6. Açık riskler

- Hoppa 3DS ya da barındırılan sayfa zorunlu tutarsa bugünkü senkron akışın tahsilat sonrası kısmı yeniden yapılandırılır (§3); iki ödeme ekranı da değişir.
- Hoppa alt üye işyeri ürünü sunmuyorsa B1'in iş modeli sorunu devam eder. O durumda platform yalnızca kendi paketlerini satan bir model düşünülmeli (COMP-009).
- Alıcı için gerçek TCKN istenirse kayıt ya da ödeme formuna yeni alan eklenir; bu kişisel veri ve KVKK değerlendirmesi gerektirir.
- Otomatik paket yenileme istenirse kart saklama gerekir; şu anki "30 günlük tek seferlik" kuralı (D-05) değişir.
