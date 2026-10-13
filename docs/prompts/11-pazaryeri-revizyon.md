# 11 — Pazaryeri Sistem Düzenlemesi (müşteri revizesi)

Kaynak: proje dosyalarındaki `PAZARYERİ SİSTEM DÜZENLEMESİ.pdf`. `CLAUDE.md`'deki tüm kurallar (mutlak kurallar, otonomi sözleşmesi, test kuralı, BLOCKERS) bu görevde de geçerlidir; bu prompt onları gevşetmez.

İş fazlara bölünmüştür. **Her faz ayrı oturumdur.** Bir fazı bitirince dur, faz özeti ver, bir sonrakine geçmek için benim "devam" dememi bekle.

---

## Revize maddeleri (referans)

| #  | Madde | Özet |
| -- | ----- | ---- |
| 0  | Önceki toplantı eksikleri | Önce bunlar kapatılacak — liste aşağıda |
| 1  | Bireysel pazaryeri kaydı | Kullanım dışı, alanda "Yakında" |
| 2  | Herkese Açık Chatbot Oluştur | Oluştur sayfasına yeni kart; ücretsiz: 2 herkese açık + 1 bağımsız; pazaryeri kaydı olana sınırsız |
| 3  | Pazaryeri Başvurusu | Kayıt yalnızca şirketlere; eski kayıt ekranı yerine "Pazaryeri Başvurusu" sayfası |
| 4  | Bakiyem | Başvurusu olmayana: "Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir." |
| 5  | Satın Aldıklarım | Kullanıcı en az bir chatbot satın alınca aktif olur |
| 6  | Ana sayfa chatbot seçimi | Takip edilen chatbotlar arasından seçim |
| 7  | Ayarlar → Banka sekmesi | Doğrudan Pazaryeri Başvuru sayfasına yönlendirir |
| 8  | Günlük 10 Luma Coin | **Değişmeyecek** — yalnızca doğrula |
| 9  | Sohbet sayfası "Satın Al" | Kaldırılacak |
| 10 | Bağımsız chatbot yayınlama | İki seçenek: **Yayınla** (doğrudan herkese açık) / **Pazaryerine Kaydet** (başvuru gerektiğini söyleyen pop-up) |
| 11 | Ücretsiz plan gizlilik hakkı | 1 bağımsız botu yayınladıktan sonra bir botu kendine özel yapabilir; üst paketlerde artar |
| P  | Üyelik paketleri | Gümüş 149 ₺ / Altın 299 ₺ / Elmas 849 ₺ — coin, bağımsız, herkese açık, yayın/kaldırma hakkı limitleri |

### Madde 0 — önceki toplantı eksikleri

Müşteri geri bildirimi, 25.09.2026. M0-1, M0-2, M0-3 için ekran görüntüsü gözlemleri aşağıda; M0-4 için görüntü yok. Gözlemler **kanıt değil, ipucudur** — hatayı yeniden üret ve gerçek nedeni kanıtla.

**Ekran görüntüsü gözlemleri:**

- **M0-1** — Oluşturma sihirbazı *Adım 3/4 "Bilgi Bankası"*. Belge yüklenince kırmızı kutuda: **"Bu chatbot üzerinde yetkiniz yok."** Aynı anda kenar çubuğunda **"0 Bot"**, önizleme panelinde **"Önizleme için botu kaydedin"** yazıyor → bot henüz kaydedilmemiş. **İlk hipotez:** belge yolu, bot kaydedilmeden (chatbot id yok/boş/geçici) `update_training_chunk.php`'yi çağırıyor ve sahiplik kontrolü düşüyor; URL yolu ise metni yerel state'te tutup kayıt anında gönderiyor olabilir. İki yolun akışını yan yana karşılaştır. Ayrıca kutu **"maks. 15 MB"** diyor — bunu `MAX_UPLOAD_SIZE_BYTES` (5 MB), `readpdf` sınırı ve PHP `post_max_size` ile karşılaştır; base64 gövde ~%33 büyür. Hata mesajı da yanıltıcı: kullanıcıya yetki değil "önce kaydedin" ya da gerçek neden gösterilmeli.
- **M0-2** — "Chatbotlarım" sayfasında `Bağımsız` rozetli kart (ID #34, "Ücretsiz", butonlar: çöp kutusu, **Yayınla**, **Yönet**). Kartın gövdesine tıklamak sohbete götürmüyor; "Yönet" büyük ihtimalle düzenleme sayfasına gidiyor. Kartın hangi alanının tıklanabilir olduğunu, bağımsız/yayınlanmamış botlar için tıklamanın bilinçli olarak mı kapatıldığını bul. Sohbete gidiliyorsa sohbet sayfası yayınlanmamış bağımsız botu author dalından açabiliyor mu, kontrol et.
- **M0-3** — "Liste" sayfası: **"Sunucu hatası oluştu."** (bootstrap'ın genel 500 mesajı → gerçek exception yalnızca logda) ve **aynı anda** "Koleksiyon Bulunamadı" boş durumu, "Toplam Koleksiyon: 0". Hiç liste olmayan kullanıcıda bile 500 dönüyor → muhtemelen sorgu/şema hatası, veri hatası değil. İki ayrı düzeltme: (a) 500'ün kök nedeni, (b) hata durumunda boş durumun gösterilmemesi.
- **M0-4** — görüntü yok; yanlış açılan sayfayı kendin tespit et.

| ID   | Şikâyet | Beklenen davranış | İlk bakılacak yerler |
| ---- | ------- | ----------------- | -------------------- |
| M0-1 | Chatbot oluştururken **belge yükleyerek** eğitim çalışmıyor; hata veriyor. URL ile eğitim çalışıyor. | Belge yükleme (PDF ve görsel/OCR) URL kadar sorunsuz eğitim metni üretmeli. | `ChatbotForm.jsx` belge yolu, `/api/training/readpdf.php` (raw JSON, `base64Data`), `smalot/pdfparser`, `tesseract.js` dinamik import, `update_training_chunk.php`, `readpdf` rate limit (10/5 dk), `MAX_UPLOAD_SIZE_BYTES`, PHP `post_max_size` / `upload_max_filesize`, CSP'nin `tesseract.js` worker'ını engelleyip engellemediği |
| M0-2 | **Bağımsız** chatbotun üstüne tıklayınca sohbet sayfasına gitmiyor; bu yüzden eğitimin işe yarayıp yaramadığı test edilemiyor. | Kullanıcı kendi bağımsız botuna tıklayınca o botun sohbet sayfası açılmalı ve bot eğitim metnini kullanmalı. | Chatbot kartının tıklama/route mantığı (`entities/`, `dashboard/chatbots`), sohbet sayfasının bot erişim kontrolü (`userHasAccess()` — author dalı), `generatereply.php`'nin `training_prompt`'u gerçekten modele gönderip göndermediği |
| M0-3 | **Liste** sayfasında hâlâ "sunucu hatası". | Liste sayfası hatasız yüklenmeli; liste yoksa boş durum. | `/dashboard/list`, `getuserlists.php`, `getbotlists.php`, `getbotsoflist.php`; hata logu (`storage/logs/php-error.log`) — gerçek exception'ı bul |
| M0-4 | Diyalog defterindeki bir diyaloğun pop-up'ında **profil butonuna** tıklayınca yanlış bir sayfa açılıyor. | Bu buton, diyalog defterine eklenen chatbotun **sohbet sayfasına** yönlendirmeli. | `dashboard/notes` diyalog pop-up bileşeni, butonun href'i, diyalog kaydında `chatbot_id` olup olmadığı (`user_dialog_books`) |

Kurallar:
- **M0-2 öncelikli.** Müşteri eğitimi test edemiyor; M0-1'in doğrulanması da buna bağlı. Sırayla: M0-2 → M0-1 → M0-3 → M0-4.
- M0-2 kapandıktan sonra **eğitimin gerçekten çalıştığını kanıtla**: URL ile ve belge ile eğitilmiş birer bot için, eğitim metninde geçen bir bilgiyi soran bir mesajın modele giden isteğe dahil olduğunu göster (Gemini anahtarı yoksa — B4 — isteğin payload'ını logla/göster, cevap üretildi deme).
- Toplantıda eğitimle ilgili konuşulan noktalar bende yok; eğitimde bulduğun sınırları (chunk boyutu, toplam metin sınırı, desteklenen dosya türleri, taranmış PDF davranışı) ayrıca listele, kendi başına karar verme.

---

## Faz 0 — Keşif ve karar listesi (kod değiştirme YOK)

Bu fazda hiçbir dosyayı değiştirme. Yalnızca oku ve raporla.

1. **Etki haritası.** Her madde için dokunulacak dosyaları bul: sayfa/bileşen (`web/src`), endpoint, controller, `functions/*`, tablo. Çağıran araması her zaman üç yerde: `web/src`, `api/admin`, `api/router.php`. Bulamadığını "bulunamadı" diye yaz, tahmin etme.
2. **Terim haritası.** Müşterinin dili ile koddaki kavramları eşle ve tablo halinde ver:
   - "Bağımsız Chatbot" ↔ kodda `independent` / ilgili kolon
   - "Herkese Açık Chatbot" ↔ kodda `public` / non-independent — **bugün bu kavram pazaryeri/satıcıya bağlı mı?** (`saveChatbot`, `publishChatbot`, `ChatbotRepository::getPublished()` INNER JOIN, `userHasAccess()`)
   - "Pazaryeri kaydı / başvurusu" ↔ `param_marketplace_sellers` ve `status` değerleri
   - "Kendine özel" (madde 11) ↔ bugün böyle bir görünürlük durumu var mı?
   - "Yayına alma / yayından kaldırma hakkı" (paketler) ↔ bugün sayılan bir şey var mı?
3. **Mevcut limit ve plan durumu.** `AppConfig` (`FREE_INDEPENDENT_BOT_LIMIT`, `FREE_PUBLIC_BOT_LIMIT`, `DAILY_FREE_MESSAGES`), `functions/plans.php`, `chatbot_limits.php`, `coin_engine.php`, `web/src/shared/lib/pricing.js` ve `plans` tablosunun kolonları. Paket tablosundaki her değerin nerede tutulacağını göster; tutulamayanları (ör. yayın/kaldırma hakkı, destek seviyesi) ayrıca listele.
4. **BLOCKERS çakışması.** Her maddeyi B1–B7 ile karşılaştır. Özellikle: madde 10'daki "Yayınla" bugün B1 (aktif satıcı şartı) yüzünden kapalı olabilir; madde 3 başvurusu B1/B3 ile ilişkili. Hangi maddenin blocker'a dayandığını açıkça yaz; blocker'ı stub ile örtecek bir çözüm önerme.
5. **Karar listesi.** Aşağıdaki belirsizlikleri (ve keşfettiğin yenilerini) numaralı soru olarak bana sor. Her soruya mevcut kodun neyi ima ettiğini ve senin önerini ekle, ama **karar verme**:
   - Madde 2 / 4 / 10: "pazaryeri kaydı bulunan" = başvuru **yapmış** mı, yoksa **onaylanmış** (`status='active'`) mı? Başvurusu beklemede olan kullanıcı Bakiyem'i görür mü, sınırsız hakkı olur mu?
   - Madde 2: "sınırsız hak" hem bağımsız hem herkese açık için mi?
   - Madde 3: Şirket başvurusunda hangi alanlar istenecek (unvan, vergi no/dairesi, MERSİS, adres, yetkili, IBAN, belge yükleme)? Başvuruyu kim, nereden onaylayacak (admin panel sayfası gerekiyor mu)?
   - Madde 3: Mevcut bireysel satıcı kayıtları (varsa) ne olacak?
   - Madde 10: "Yayınla" ile yayınlanan bot ücretsiz mi kullanılır; fiyat alanı gizlenir mi? Pazaryeri kaydı olmayan kullanıcının herkese açık botu keşfet/explore'da görünür mü?
   - Madde 11: "Kendine özel" botu başkaları hiç göremez mi, link ile görebilir mi? Ücretsiz plandaki 1 hak toplam mı, aylık mı? Paketlerdeki "yayına alma/kaldırma hakkı" bu özel hakla aynı şey mi?
   - Paketler: limitler dolu kullanıcı paketten düşerse fazla botlarına ne olur? Paket satın alma ödemesi — B3 (iyzico reddi) açıkken bu turda yalnızca **limit/veri** tarafı mı yapılacak?
   - Madde 5: "Aktif hale gelir" = menüde gizli/görünür mü, yoksa görünür ama boş durum mesajlı mı? Süresi dolmuş abonelik sayılır mı?
   - Madde 6: Hiç bot takip etmeyen kullanıcıya ne gösterilecek?

Faz 0 çıktısı: etki haritası, terim haritası, plan/limit tablosu, blocker eşlemesi, numaralı soru listesi, ve aşağıdaki faz planının gerekiyorsa güncellenmiş hali. **Sonra dur.**

---

## Faz 1 — Madde 0: önceki toplantı eksikleri

Madde 0 listesindeki her kalemi sırayla kapat: root cause → en küçük güvenli değişiklik → doğrulama → AUDIT.md'ye kayıt (yeni ID ile, "kapandı + kanıt"). `06-duzeltme-p0-p1.md`'deki bütçe kuralları burada da geçerli: madde başına 3 deneme, kırılan test → geri al ve raporla.

---

## Faz 2 — Düşük riskli arayüz değişiklikleri

Faz 0'daki cevaplarıma göre, şu sırayla:

1. **Pazaryeri Başvurusu sayfası iskeleti** (madde 3'ün yalnızca frontend kabuğu): yeni route, Faz 0'da onaylanan alanlarla form, ama **gönderim henüz bir backend'e bağlanmaz** — gönder butonu devre dışı ve açıklamalı olsun, sahte başarı mesajı gösterme. Bu route diğer maddelerin hedefidir.
2. **Madde 1:** Bireysel pazaryeri kaydı girişlerini kaldır/devre dışı bırak, "Yakında" göster. Eski kayıt ekranının route'u varsa diğer emekli route'lar gibi davranmalı (`notFound()` veya başvuru sayfasına yönlendirme — Faz 0'da hangisini seçtiysem).
3. **Madde 7:** Ayarlar → Banka sekmesi → Pazaryeri Başvurusu sayfası.
4. **Madde 9:** Sohbet sayfasındaki "Satın Al" butonunu kaldır. Butonun açtığı modal/akış başka yerden kullanılmıyorsa ölü kod olarak işaretle (silme kararı `CLAUDE.md`'deki 3'lü çağıran aramasına bağlı).
5. **Madde 5:** Satın Aldıklarım görünürlüğü — mevcut abonelik verisinden (`getmysubscriptions` vb.), yeni endpoint açmadan.
6. **Madde 4:** Bakiyem kapısı — tam metin: "Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir." Bu bir UI kapısıdır; **wallet endpoint'lerinin sunucu tarafı yetkisini değiştirme**. Sunucu tarafında da kapı gerekiyorsa API contract değişikliğidir → diff öner, uygulama.
7. **Madde 6:** Ana sayfa chatbot seçimi takip edilenlerden (`/api/social/getfollowedbots.php` mevcut mu doğrula). Boş durum, yükleniyor ve hata durumları dahil.
8. **Madde 8:** Günlük 10 coin'in değişmediğini doğrula (üç kopya sabit + plan tablosu fallback'i). Kod değişikliği yok; yalnızca kanıtla raporla.

Her madde sonrası: `npm run lint` + verify build. Madde başına bir commit'lik değişiklik boyutu; tek seferde 10+ dosya değiştirme.

---

## Faz 3 — Limitler, gizlilik hakkı ve paketler (madde 2, 11, P)

Bu faz iş kuralı ve para ile ilgili; `CLAUDE.md`'deki "önce raporla" kategorisine yakın. Kurallar:

- **Test kuralı:** Ücretsiz plan limitleri (1 bağımsız / 2 herkese açık) bugün doğruysa önce regresyon testi; yanlışsa önce doğru davranışı anlatan kırmızı test. Belirsizse test yazma, sor.
- **Madde 2:** Oluştur sayfasına "Herkese Açık Chatbot Oluştur" kartı. Limit kontrolü **sunucu tarafında** (`chatbot_limits.php` / `plans.php` üzerinden), frontend yalnızca gösterir. "Pazaryeri kaydı olana sınırsız" kuralı Faz 0'daki tanımıma göre.
- **Madde 11:** "Kendine özel" durum için şema değişikliği gerekiyorsa **migration dosyasını yaz, uygulama**; `migrate.php --apply` asla. Erişim kontrolünü (`userHasAccess()`, listeleme sorguları) etkiliyorsa uygulama, diff öner.
- **Paketler:** Gümüş/Altın/Elmas limitleri `plans` tablosu verisidir → seed/migration dosyası yaz, uygulama. Fiyatlar ve coin değerleri sabitlere de yansıyorsa **üç kopyayı birlikte** güncelle (`AppConfig.php`, `coin_engine.php`, `pricing.js`) ve diff'te üçünü yan yana göster. Paket **satın alma ödeme yoluna** (`upgradeplan.php`, `IyzicoClient`, `checkout_payments.php`) dokunma; B3 açık. Destek seviyesi / erken erişim gibi kodda karşılığı olmayan özellikleri yalnızca metin olarak göster, sahte özellik uydurma.
- Yükseltme sayfası (`/dashboard/upgrade`) metinleri paket tablosuyla birebir aynı olmalı.

---

## Faz 4 — Bağımsız chatbot yayınlama (madde 10)

- "Yayınla" bugün aktif satıcı şartına (B1) bağlıysa, herkese açık yayın ile pazaryeri satışını ayırmak gerekecek. Bu `publishChatbot`, `getPublished()` INNER JOIN'i ve `userHasAccess()` gibi birden fazla endpoint'i/ortak helper'ı etkiler → **uygulama, diff olarak öner**, sömürü/erişim senaryolarını AUDIT.md'ye yaz (ör. özel bir botun yanlışlıkla listelenmesi, fiyatlı bir botun ücretsiz erişilmesi).
- Onayımdan sonra uygula. "Pazaryerine Kaydet" yalnızca pop-up açar ve Pazaryeri Başvurusu sayfasına yönlendirir; satıcı durumu yaratmaz, B1'i örtmez.
- Satıcı olmayanın botuna fiyat atanamamalı (`updatechatbotprice.php` sunucu kontrolü).

---

## Faz 5 — Pazaryeri Başvurusu backend (madde 3)

- Başvurular için yeni tablo/kolon gerekiyorsa: migration dosyasını yaz, şemayı ve API contract'ını raporla, **onay bekle**.
- Onaydan sonra: başvuru endpoint'i (auth: user, rate limit `checkRateLimit()` deseniyle, `InputSanitizer::pickAllowed()` allowlist'i, vergi no vb. validasyon), kullanıcının kendi başvuru durumunu okuyan endpoint, Faz 0'da istenmişse admin panelde başvuru listesi/onay sayfası (`_guard.php` + CSRF).
- Başvuru onayı **satıcıyı otomatik `active` yapmaz** — o hâlâ B1'e bağlı. Bu ayrımı UI'da da dürüstçe göster ("Başvurunuz alındı / inceleniyor").
- Faz 2'deki iskeleti bu backend'e bağla; madde 2/4/10'daki "kaydı var mı" kontrolleri tek bir helper'dan okunsun.
- Yeni endpoint'i README'nin API tablosuna ekle.

---

## Faz 6 — Kapanış

1. Tüm doğrulama komutları (`CLAUDE.md` → Doğrulama komutları).
2. Madde bazında durum tablosu: **tamam / diff önerildi, onay bekliyor / blocker'a bağlı (Bn) / soru bekliyor**. Blocker'a bağlı bir maddeyi "çalışıyor" ilan etme.
3. Yazılan ama uygulanmayan migration dosyalarının listesi ve hangi sırayla uygulanmaları gerektiği.
4. README'de değişen davranışlar (route tablosu, limitler, plan tablosu, yeni endpoint'ler) güncellendi mi kontrol et.
5. Değiştirilen her dosya için tek satır: **ne değişti · hangi madde/AUDIT ID'si · nasıl doğrulandı.**
