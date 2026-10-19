# Müşteri revizyonu 2026-10-18: doğrulama raporu

Kaynak: `docs/musteri/revizyon-2026-10-18.pdf` (7 madde) ve `docs/musteri/revizyon-2026-10-18/*.jpeg` (10 görsel).
Branch: `revize/pazaryeri`. AUDIT kayıtları: N-55 … N-66.

Durum:
- **Faz A canlıda** (2026-10-18).
- **Faz B tamamlandı, canlıya alma bekliyor.** Abonelik kurallarının ödeme yolu kısmı, iptal şeması ve paket geçişi onay bekliyor: `docs/proposals/abonelik-yonetimi-2026-10-18.md`.
- Faz C başlamadı.

---

## 1. Madde madde tablo

### Madde 1: Yanıt hızı ve yapay zekâ hataları

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| A4 · hata nedenleri | `[gemini]` log satırlarını sınıflandır | Bugünkü yerel log (canlıyla aynı anahtar): 3× 503 `UNAVAILABLE` "high demand" (Google'da geçici yoğunluk), 5× 429 "You exceeded your current quota" (ücretsiz katman kotası), 1× DNS, 1× anahtar | AUDIT N-59 | Hayır. Canlı log için Admin → Sistem Durumu → "Son 24 saat" (deploy sonrası) |
| A4 · yeniden deneme | 429/5xx'te kısa bekleme + tek otomatik deneme | `geminiRetryPlan`: akış başlamadıysa ve ilk deneme ≤10 sn sürdüyse tek tekrar. Günlük kota, 4xx, boş cevap ve zaman aşımı tekrarlanmaz. İkinci deneme PHP sınırından en az 5 sn önce biter | `gemini_selftest` H (23 kontrol); uçtan uca: DNS hatası → 0,5 sn → 2. deneme (`deneme=1/2`, `2/2`) | Hayır, deploy bekliyor |
| A4 · doğru mesaj | Kota/yoğunlukta doğru mesaj | Hata karesinde `kind`; arayüzde `chatFailureText`. Örnek: "Yapay zeka şu an yoğun… Birkaç saniye sonra Tekrar Dene" | `reply_errors_test` (5) | Hayır, ön yüz deploy bekliyor |
| A4 · coin iadesi | N-48 kuralı korunacak | İade kararı yine `chatReplyOutcome` ile ve son denemeden sonra veriliyor | Uçtan uca: coin 10 → 9 → **10** | Hayır |
| A4 · admin | Son 24 saatte hata sayısı ve türleri | Admin → Sistem Durumu → "Son 24 saat — sohbet hataları" (türe göre, kurtarılan, son hata) | CLI render: toplam 10 · yoğunluk 3 · kota 5 · ağ 1 · anahtar 1 | Hayır, zip bekliyor |
| B1 · ölçüm | İlk bayt ve toplam süre, kısa ve uzun soru | Canlı (test hesabı A, 2 mesaj): yanıt başlığı **0,14–0,19 sn**, ama iki mesaj da 429 kota (`kind:"quota"`, iade). Yerel anahtar da kotada. **İlk kelime ve toplam süre ölçülemedi.** Ölçüm betiği hazır: `node scripts/live-reply-latency.mjs .env.e2e-prod` | AUDIT N-60 | Kısmen (başlık süresi evet, model süresi hayır) |
| B1 · SSE | Token token akış | Sunucu zaten SSE akıtıyor (`alt=sse`, her parça anında `flush`). Canlıda meta karesi 0,14 sn'de geldi, yani Vercel üzerinden tamponlanmıyor. Arayüz her parçada metni güncelliyor | Kod + canlı başlık süresi | Evet (akış), ilk kelime kota açılınca |
| B1 · bağlam boyutu | Ölç, gereksizse küçült | En kötü istek ≈ 88.000 karakterdi (bilgi bankası 60k tamamı + geçmiş 16k + stil 4k + mesaj 8k). Bilgi bankası 24.000 karakteri aşınca yalnızca soruyla ilgili parçalar gidiyor (`chatSelectCorpus`), en kötü istek ≈ 52.000. Geçmiş sınırı (20 mesaj / 16k) değişmedi | `gemini_selftest` J (14); yerel uçtan uca (32k bilgi bankası) | Hayır, deploy bekliyor |
| B1 · doğruluk | "Uydurma" kuralı, model ayarı ve gerekçesi | Talimata eklendi: "Emin olmadığın bilgiyi uydurma… tarih, sayı, alıntı, isim uydurma" ve "canlandırdığın kişiye ait olmayan görüşü ona atfetme" (görsel 06: tenasüh). **Model ayarı:** `gemini-3.8-flash`'ta `temperature`/`topP`/`topK` kullanımdan kaldırıldı; Google bunları yok sayıyor ve ileride 400 verecek. Bu yüzden gönderilmiyor (testle kilitli). Düşünme seviyesi hız için `low` kaldı | `gemini_selftest` J; Google dokümanı (Gemini 3 / 3.8 Flash) | Hayır; görsel 06 sorusu kota açılınca yeniden denenecek |

### Madde 2: Profil ve kapak görselleri

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| A3 · gerçek URL | DB'de ne var, tarayıcı nereye istiyor, dosya var mı | Canlıda herkese açık 11 botun 2'si görselli; 4 dosyanın tamamı `api.lumanoris.com/assets/…`, `www.lumanoris.net/assets/…` ve `/_next/image` üzerinden **200** dönüyor. Sorun sunucuda değil, ön yüzün yolu bozmasında: `data:image/jpeg;base64,assets/…` ve `/uploads/avatars/assets/…` | AUDIT N-58 | **Evet** (salt okunur HEAD istekleri) |
| A3 · tek üretici | Tek fonksiyon, eski biçimleri okusun | `web/src/shared/lib/image-url.mjs` → `imageUrl()`: `assets/`, `data:`, ham base64, eski bozuk birleştirmeler, http(s) | `image_url_test` (9) | Hayır, ön yüz deploy bekliyor |
| A3 · yer tutucu | Kırık ikon ve "avatar" alt metni olmasın | `EntityAvatar` (baş harfler) / `EntityCover` (degrade); `onError`'da yer tutucuya düşer; `alt=""`. 11 dosyada 29 görsel noktası. Unsplash yedekleri silindi (görsel 07'deki yüz rastgele bir stok fotoğraftı) | Tarayıcı: normalde kırık 0; `/assets` 404'e zorlanınca kırık 0, "GT" baş harfleri (`docs/verification/2026-10/rev1018/N58-*.png`, git dışı) | Hayır |
| A3 · deploy uploads'u ezmez | Garanti | Zip yalnızca git'te izlenen dosyaları içerir; `assets/` ve `uploads/` altında `.htaccess` dışında dosya varsa paket üretilmez. Zip açmak dosya silmez | Faz A zip'inde `assets/.htaccess` ve `admin/uploads/.htaccess` var, başka dosya yok. Yerel test görselleri pakete girmedi | (deploy kuralı) |

### Madde 3: Bilgi bankası, belge yükleme, URL

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| A1 · vendor hatası | Yol neden yok, kalıcı çözüm | Kök neden: `vendor/` git'te yok, zip'ler vendor'suzdu, sunucudaki `vendor/` (2026-08-24) kullanılabilir `autoload.php` içermiyor. PHP 8'de eksik dosyaya `require_once` hata **fırlatıyor**; ham metin `APP_DEBUG=true` olduğu için görünüyordu | AUDIT N-56; yerelde `vendor/` kaldırılınca HTTP 500 | Hayır, zip bekliyor |
| A1 · deploy paketi | Gerektiğinde vendor dahil (PHP 8.2), betik ve README | `scripts/build-backend-zip.sh`: HEAD + temiz `composer install --no-dev --optimize-autoloader` (api + admin). Durdurma koşulları: `.env*`, kullanıcı yüklemesi, anahtar deseni, PHP 8.2 uyumsuzluğu. Kullanılmayan Google servisleri ayıklandı (vendor 36.199 → 487 dosya). README → "Manual zip package" | `vendor_selftest` (20); paketten açılan vendor'la PDF okundu | Hayır |
| A1 · sertleştirme | Anlaşılır mesaj + log ayrıntısı | `VendorAutoload::requireOrFail` → 503 "Belge okuma bileşeni sunucuda şu an kullanılamıyor…"; log'da `[vendor]` satırı (yol, eksik sınıf, çözüm). Google girişi de aynı yolla korunuyor. Admin → Sistem Durumu → "Sunucu bileşenleri" | Vendor yokken readpdf 503 ve login-google 503; vendor varken 200 | Hayır |
| A1 · dosya türleri | Desteklenen her türle dene | B2'de **PDF, DOCX, TXT** desteklendi (aşağıda) | | Kullanıcı A paketinden sonra PDF sonucunu iletmedi |
| B2 · URL okuma | Tarayıcı başlıkları, yönlendirme, sıkıştırma, kodlama, zaman aşımı, ana içerik | Kök neden bulundu: IPv6 adresleri de sabitleniyordu. IPv6 yolu olmayan makinede yerel ölçümde 20 sitenin 9'u 0 ms'de düşüyordu. Düzeltmeler: yalnızca IPv4 + zorlama, ≤5 yönlendirme her adımda iç ağ kontrolüyle, tarayıcı başlıkları, gzip/br, `meta`/başlıktan kodlama, menü/altbilgi/script atma, `<article>/<main>` önceliği | `readurl_selftest` (67); `docs/verification/url-okuma-2026-10-18.md` | Hayır, deploy bekliyor |
| B2 · hata sınıfları | Her sınıf için ayrı Türkçe mesaj | 15 sınıf: alan adı yok, zaman aşımı, bağlantı, SSL, iç ağ, bot koruması, giriş gerekli, 404, 5xx, PDF bağlantısı, desteklenmeyen tür, JavaScript ile oluşan sayfa, boş, çok büyük, çok fazla yönlendirme. Karakter sınırı mesajında sınır ve mevcut uzunluk var. Sunucu sığmayan kaynağı artık sessizce kesmiyor; sayaç bayt değil karakter sayıyor | Ölçüm tablosu | Hayır |
| B2 · 15+ gerçek site | Haber, Wikipedia, blog, e-ticaret, kurumsal, Cloudflare, SPA | **20 site:** 13 okundu, 7'si doğru sınıflandı. Ayrı bir PDF bağlantısı `pdf` olarak tanındı | `docs/verification/url-okuma-2026-10-18.md` | Canlı sunucudan tekrar edilmeli (veri merkezi IP'si daha sık engellenebilir) |
| B2 · "Metin ekle" | — | Bot korumalı ve JavaScript'le oluşan sayfalar için tek çıkış yolu. Yoktu; eklendi | Tarayıcı: kısa metin eklendi, sınırı aşan metin mesajla reddedildi | Hayır |
| B2 · belge yükleme | Boyut, desteklenmeyen tür, şifreli PDF, taranmış PDF | `DocumentText`: tür dosya imzasından. TXT (UTF-8/BOM/UTF-16/windows-1254), DOCX ve PDF okunuyor. Mesajlar: çok büyük, desteklenmeyen tür, eski .doc, şifreli, taranmış, bozuk, boş. Eski `readpdf.php` çalışmaya devam ediyor | `document_selftest` (35); yerel API 7 dosya; tarayıcı: DOCX eklendi, .doc reddedildi | Hayır |

### Madde 4: Bilgi bankası ve yayın akışı

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| C1 | Taslak kayıt, yönetimden erişim, "Yayınla" | Faz C | | |

### Madde 5: Ödeme, abonelik, paket

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| A2 · gerçek hata | Hoppa'nın döndürdüğü hatayı bul | **Kesin neden (canlı, 2026-10-18):** Hoppa sunucumuzun çıkış IP'sini tanımıyor. Canlıdaki 6 başarısız denemenin hepsinde ve bağlantı testinde aynı mesaj: "Bu IP adresinden işlem yapma yetkiniz bulunmamaktadır. IP: 45.143.99.2" (`AUTHENTICATION_ERROR`, başlatmada kod 102, sorguda 100). Kimlik bilgileri, kod, tutar ve adres doğru. Faz A'daki "en güçlü aday: anahtar yenilendi" tahmini **yanlıştı** | Sorgu 2.1 sonucu (6 satır), Sistem Durumu → Hoppa bağlantı testi | **Evet** (neden) · ödeme IP tanımından sonra denenecek |
| A2 · olasılıklar | merchant, IP/alan adı, BACK_URL, tutar, uç adres | Hoppa'nın yayımlanan dokümanında IP tanımı geçmiyor, ama canlı hesapta **IP kısıtı açık**. Faz A raporundaki "IP şartı yok" ifadesi pratikte yanlış çıktı. `APP_PUBLIC_URL` sorunu olsaydı mesaj "Ödeme altyapısı hazırlanıyor" olurdu. Tutar biçimi test ortamında çalıştı. Canlı uç adres `posservice.esnekpos.com` | Kod + doküman + canlı yanıt | Evet |
| A2 · bağlantı testi | Ödeme başlatmayan zararsız çağrı | Admin → Sistem Durumu → "Hoppa bağlantı testi": var olmayan referansla ProcessQuery. Test ortamında geçerli kimlik 400 "Referans bulunamadı", uydurma kimlik `AUTHENTICATION_ERROR` döndü, yani ikisi ayırt edilebiliyor | Yerel, gerçek `_guard` + CSRF yolundan: 546 ms "kimlik kabul edildi" | Hayır, zip bekliyor |
| A2 · Türkçe mesajlar | Kod → anlaşılır mesaj | `hoppaUserMessage`: dokümandaki 100–206 ve 34 kodları, "51-Limit Yetersiz" biçimli banka reddi. Kimlik sorunu kullanıcıya "bakımda" diye gider, ayrıntı log'a yazılır | `hoppa_diag_selftest` (40) | — |
| A2 · ödeme yoluna bağlama | | **Onaylandı ve uygulandı** (2026-10-18): başlatma hatası log'a Hoppa özetini yazıyor; kullanıcı nedene göre mesaj görüyor; dönüşte `&neden=`. Bağlantı testi IP reddini artık "anahtarı karşılaştır" diye değil, "IP'yi Hoppa'da tanımlayın" diye açıklıyor | `hoppa_selftest` B (+2), `hoppa_diag_selftest` (+4) | B paketiyle |
| B3 · aynı paketi yeniden alma | Sunucuda engelle; arayüzde "Mevcut paketiniz" + bitiş | **Arayüz:** aktif ücretli paketin düğmesi kapalı, "Mevcut paketiniz" + bitiş tarihi. **Sunucu engeli onay bekliyor** (ödeme yolu): yenileme penceresi ve erken yenilemede günlerin korunmasıyla birlikte. Bugün erken yenileme kalan günleri yakıyor | `subscription_selftest` F; tarayıcı (ücretli/ücretsiz) | Hayır |
| B3 · Aboneliğim | Paket, başlangıç/bitiş, kalan gün, ödeme geçmişi, Yenile, İptal | Paket sayfasında "Aboneliğim" kartı: paket, tarihler, kalan gün (3 günden az kalınca renk), "otomatik yenilenmez" notu, ödeme geçmişi. Süresi bitmişse "yeniden satın al". **Erken "Yenile" ve "İptal" onay bekliyor** (ödeme yolu + şema 020) | `subscription_selftest` (15); tarayıcı | Hayır |
| B3 · iptal anlamı | İki seçenek, teknik ve yasal | `docs/proposals/abonelik-yonetimi-2026-10-18.md` §4: "süre sonunda biter" (önerilen varsayılan) ve "hemen biter + iade talebi"; Mesafeli Sözleşmeler Yönetmeliği md. 15/1-ğ, h. Müşteri sorusu 3 | — | — |
| B3 · üst/alt paket | Öner, uygulama | Öneri: üst paket hemen başlar ve kalan günler yanar, ödeme penceresinde açıkça yazar. Alt paket ve Ücretsiz, ücretli paket bitene kadar seçilemez. Müşteri sorusu 4 | — | — |
| B3 · tek kaynak | Satın alma, görüntüleme, yenileme, iptal, cron | Görüntüleme, paket kartları, PRO etiketi ve admin aynı fonksiyonu kullanıyor (`plans.php getUserSubscription`). Satın alma, yenileme ve iptalin bağlanması onaylı değişiklikle | — | — |

### Madde 6: Paketler ve PRO etiketi

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| B4 · "Altın" nereden geliyor | Salt okunur SELECT, yorum | Kodda paket satırına yazan yalnızca üç yol var: ödeme kesinleştirme, ücretsiz seçim ve iade geri alma. Admin ataması özelliği yoktu. **Hipotez:** 2026-08-26'dan önce `upgradePlan` paketi ödeme almadan yazıyordu (BIZ-001); bitiş sütunu sonradan geldiği için o kayıtlar süresiz kaldı. Doğrulama sorgusu: 2.5 | Kod okuması, `git log` | Sorgu sonucu bekleniyor |
| B4 · admin alanı | Paket ve kaynağı; atamalar denetim kaydına | Admin → Kullanıcılar → "Paket": etkin paket, bitiş, kaynak (satın alma / admin ataması / ödemesiz eski kayıt). Atama 30 gün, tarihe kadar ya da süresiz olabiliyor; `admin_audit_log` `plan_assign` kaydına eski ve yeni paket yazılıyor. Bitiş MySQL saatinden hesaplanıyor (PHP UTC ile 3 saatlik kayma yakalandı) | `subscription_selftest` G (+9); admin ucu gerçek `_guard` yolundan | Hayır |
| B4 · PRO etiketi | Yalnızca aktif ücretli pakette, bitince anında kalksın | Karar artık plan adına göre değil; sunucunun fiyat temelli `planIsPaid` bayrağı ve kalan süreye göre veriliyor. Süre dolunca sayfa yenilenmeden kalkıyor. Ücretsizde, bayrak gelmese bile gösterilmiyor | `plan_badge_test` (5); tarayıcı: ücretli var, ücretsiz yok | Hayır |
| B4 · etiket metni | "PRO" mu paket adı mı | Varsayılan "PRO" kaldı. Müşteri sorusu 5 | — | — |

### Madde 7: Listeden bot kaldırma

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| B5 · çöp kutusu | Listeden çıkar, platformdan silme; onay "Bu botu listeden çıkar" | Kök neden: liste penceresi `onRemoveBot` verilmeden çiziliyordu, düğme hiçbir şey yapmıyordu. Şimdi "Bu botu listeden çıkar?" onayı var; bot yalnızca listeden çıkıyor, sayaçlar anında güncelleniyor, "bot silinmedi" bildirimi gösteriliyor | `list_state_test` (5); tarayıcı + sunucu: bot listeden çıktı, platformda duruyor | Hayır |
| B5 · tutarlılık | Liste sayfası, pop-up, sohbet sayfası | "Listeye Ekle" penceresi (kart/pop-up, sohbet, Diyalog Defteri) her açılışta durumu sunucudan okuyor. Kaydedince bir olay yayınlıyor ve Liste sayfası yenileniyor | Kod | Hayır |

### Bot pop-up'ı: sayılar ve iddialar (B6)

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| B6 · sayılar | Tek kaynak | Sunucuda bütün uçlar aynı sayıyı hesaplıyor. Pop-up ise sayfa açılışındaki eski listeyi gösteriyordu. Artık açılırken sohbet sayfasıyla aynı kaynaktan okuyor. Görsel 07 ile 05 arasındaki diyalog farkı, farklı anlarda yüklenmiş sayfalardan geliyor | `bot_stats_test`; tarayıcı: kartta beğen → pop-up "Beğeni 1" | Hayır |
| B6 · "Haftalık ücret" | Fiyat modeline uygun mu | Ücretli botlar haftalık fiyatla satılıyor, yani model uyumlu. Ancak ücretsiz botta başlık çelişkiliydi. Artık "Ücret: Ücretsiz / ₺X /hafta" (kartla aynı) | Tarayıcı | Hayır |
| B6 · "2026 Model Desteği" | Gerçek mi | "Anlık arama entegrasyonları" gerçek dışı (botlarda internet araması yok). Kutu kaldırıldı; yerine ifade müşteri sorusu 7 | Tarayıcı: kutu yok | Hayır |

### Ek: güvenlik notu (revizyon dışı)

| ID | Yapılan | Kanıt |
|---|---|---|
| N-55 | `api/.env.example`'a yazılmış gerçek Gemini anahtarı geri alındı; içinde `.env`'ler bulunan `api/vendor.zip` silindi; `api/**/*.zip` git dışı. `secret_scan_selftest` verify.sh'te | Git geçmişinde 0, zip'lerde 0; canlıda `vendor.zip` **404**. Sızmadı; anahtar yenileniyor (kullanıcı) |

---

## 2. Sizden gerekenler (phpMyAdmin, salt okunur)

### 2.1 Hoppa'nın gerçek hata mesajı (A2)

Kullanıcı kimliği ve kişisel alan seçmez.

```sql
SELECT id, order_id, status, amount, created_at,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.test'))                    AS test_modu,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.start.STATUS'))            AS hoppa_status,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.start.RETURN_CODE'))       AS hoppa_kod,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.start.ERROR_CODE'))        AS hoppa_error_code,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.start.RETURN_MESSAGE'))    AS hoppa_mesaj,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.start.RETURN_MESSAGE_TR')) AS hoppa_mesaj_tr,
       JSON_UNQUOTE(JSON_EXTRACT(param_response_json, '$.start.__transport_error')) AS ag_hatasi,
       JSON_UNQUOTE(JSON_EXTRACT(callback_json, '$.query.RETURN_MESSAGE'))         AS donus_mesaj
FROM param_marketplace_payments
WHERE order_id LIKE 'PLN-%' AND status = 'hoppa_failed'
ORDER BY id DESC
LIMIT 10;
```

Nasıl okunur:
- `hoppa_status = AUTHENTICATION_ERROR` → anahtar/merchant yanlış ya da yenilenmiş.
- `hoppa_kod = 100` (başka durumla) → üye işyeri canlıda aktif değil ya da servis yetkisi yok.
- `101` → müşteri alanı reddedildi.
- `ag_hatasi` dolu → sunucudan Hoppa'ya çıkış yok.
- `test_modu = true` → canlıda `HOPPA_MODE=live` okunmuyor.

### 2.2 Görsel kayıt biçimleri (A3, yalnızca sayım)

```sql
SELECT 'chatbotlar.profil_fotografi' AS alan,
       CASE WHEN profil_fotografi IS NULL OR profil_fotografi = '' THEN 'bos'
            WHEN profil_fotografi LIKE 'data:%' THEN 'data_uri'
            WHEN profil_fotografi LIKE 'http%' THEN 'mutlak_url'
            WHEN profil_fotografi LIKE '/%' THEN 'kok_goreli'
            WHEN profil_fotografi LIKE 'assets/%' THEN 'assets_goreli'
            WHEN profil_fotografi REGEXP '^[A-Za-z0-9+/=]{200,}$' THEN 'ham_base64'
            ELSE 'diger' END AS bicim,
       COUNT(*) AS adet
FROM chatbotlar GROUP BY alan, bicim
UNION ALL
SELECT 'chatbotlar.kapak_fotografi',
       CASE WHEN kapak_fotografi IS NULL OR kapak_fotografi = '' THEN 'bos'
            WHEN kapak_fotografi LIKE 'data:%' THEN 'data_uri'
            WHEN kapak_fotografi LIKE 'http%' THEN 'mutlak_url'
            WHEN kapak_fotografi LIKE '/%' THEN 'kok_goreli'
            WHEN kapak_fotografi LIKE 'assets/%' THEN 'assets_goreli'
            WHEN kapak_fotografi REGEXP '^[A-Za-z0-9+/=]{200,}$' THEN 'ham_base64'
            ELSE 'diger' END,
       COUNT(*)
FROM chatbotlar GROUP BY 1, 2;
```

`diger` 0'dan büyükse bana söyleyin; o biçimi de `imageUrl()`'e eklerim.

### 2.5 Ücretli paketlerin kaynağı (B4: "Altın" nereden geliyor)

Yalnızca ücretli paketi olan hesaplar. Kullanıcı adı, kendi hesaplarınızı tanıyabilmeniz için seçiliyor; e-posta ve diğer kişisel alanlar seçilmiyor.

```sql
SELECT ups.user_id, k.kullanici_adi, ups.plan_name, ups.selected_at, ups.expires_at,
       CASE WHEN ups.expires_at IS NULL THEN 'süresiz'
            WHEN ups.expires_at > NOW() THEN 'aktif'
            ELSE 'süresi dolmuş' END AS durum,
       (SELECT COUNT(*) FROM param_marketplace_payments p
         WHERE p.user_id = ups.user_id AND p.status = 'paid' AND p.items_json LIKE '%plan_name%') AS odenmis_paket_siparisi,
       (SELECT MAX(p.created_at) FROM param_marketplace_payments p
         WHERE p.user_id = ups.user_id AND p.status = 'paid' AND p.items_json LIKE '%plan_name%') AS son_paket_odemesi,
       (SELECT COUNT(*) FROM admin_audit_log a
         WHERE a.target_user_id = ups.user_id AND a.action = 'plan_assign') AS admin_atamasi
FROM user_plan_selection ups
JOIN kullanicilar k ON k.id = ups.user_id
JOIN plans pl ON pl.name_tr = ups.plan_name COLLATE utf8mb4_general_ci
WHERE pl.monthly_price > 0
ORDER BY ups.selected_at;
```

Nasıl okunur:
- `selected_at` 2026-08-26'dan önce, `expires_at` boş ve ödenmiş sipariş 0 → paket ödemesiz verilmiş (BIZ-001 döneminden kalma). Hipotez doğrulanmış olur.
- Ödenmiş sipariş > 0 → gerçek satın alma.
- Admin ataması > 0 → bu sürümden sonra panelden atanmış.

Müşteri hesaplarına dokunmadım. Bu kayıtların böyle mi kalacağı (süresiz Altın), yoksa panelden yeniden mi atanacağı müşteri kararı (soru 9).

### 2.3 Log satırları (A2, A4)

Log dosyası: `APP_LOG_FILE` tanımlı değilse `/home/lumansmu/storage/logs/php-error.log`. Aranacak satırlar:

```
[upgradePlan] Hoppa ödeme başlatılamadı order=PLN-… code=…
[hoppa] /api/pay/CommonPaymentDealer başarısız http=… curl=…
[gemini] generatereply hata: http=… status=… message=…
```

Zip yüklendikten sonra `[hoppa] admin bağlantı testi …` ve `[gemini] … tür=… deneme=…` satırları da düşer.

### 2.4 Canlı yapılandırma

- `api/.env` → `APP_DEBUG=false`. Şu an `true` (ham hata metni ve sunucu yolu kullanıcıya görünüyordu, görsel 02).
- Hoppa paneli → güncel `MERCHANT` / `MERCHANT_KEY` ile `api/.env` → `HOPPA_LIVE_*` aynı mı?
- Gemini anahtarı yenilendi mi (N-55)? `api/.env` ve `admin/.env`.

---

## 3. Canlıya alma: Faz A

**Arka uç (cPanel):** `deploy/backend-2026-10-18-A.zip`

| | |
|---|---|
| commit | `fb9b5e6` |
| dosya | 862 (654 vendor girdisi) |
| boyut | 1,9 MB |
| sha256 | `fb29677be1d659eeb6f3369e07346768f39501e42d12a2a71c76ef2a20dcc840` |
| denetim | `.env*` 0 · `assets/`+`uploads/` yalnızca `.htaccess` · `storage/` 0 · anahtar taraması 0 bulgu · PHP 8.2 uyumlu |

Adımlar:
1. cPanel Dosya Yöneticisi → `api.lumanoris.com` → Upload → Extract (üzerine yaz). Klasörü silmeyin.
2. `api/.env` → `APP_DEBUG=false`.
3. Admin → Sistem Durumu:
   - "Sunucu bileşenleri": üç vendor satırı "kurulu" ve `APP_DEBUG false` olmalı.
   - "Hoppa bağlantı testi" sonucunu bana iletin.
   - "Son 24 saat — sohbet hataları" sayılarını bana iletin.
4. Ön yüz: belge yükleme (küçük bir PDF). Hata alırsanız log'daki `[vendor]` satırını iletin.

**phpMyAdmin SQL / `schema_migrations`:** Faz A'da şema değişikliği **yok**; uygulanacak migration ve kayıt satırı yok.

**Ön yüz (Vercel): PR**
- GitHub'da `revize/pazaryeri` → `main` PR'ı açın: https://github.com/ceyhun64/lumanoris/compare/main...revize/pazaryeri
  - Bu makinede `gh` CLI kurulu değil, PR'ı ben açamadım.
- N-58 (görseller) ve N-59'un arayüz kısmı (hata metinleri) bu merge ile canlıya çıkar.
- Merge'den önce zip'in yüklenmesi önerilir. Ters sıra da çalışır: eski sunucu `kind` göndermez, arayüz koda göre metin seçer.

PR açıklaması (kopyalanabilir):

```
Müşteri revizyonu 2026-10-18 — Faz A (kritik)

- N-55 .env örneklerinde gerçek anahtar kontrolü (verify.sh), api/ altındaki zip'ler git dışı
- N-56 Belge yükleme: vendor/autoload eksikken anlaşılır 503; vendor'lu deploy paketi (scripts/build-backend-zip.sh)
- N-57 Admin → Sistem Durumu → Hoppa bağlantı testi; Hoppa hata kodu → Türkçe mesaj (ödeme yoluna bağlama onay bekliyor: docs/proposals/n57-hoppa-hata-mesajlari.patch)
- N-58 Bot görselleri tek üreticiden (image-url.mjs) + baş harfli yer tutucu; 11 dosyada 29 görsel noktası
- N-59 Gemini geçici hatalarında tek otomatik yeniden deneme, türüne göre mesaj, 24 saat sayımı

Doğrulama: bash scripts/verify.sh → DOĞRULAMA: GEÇTİ (16 adım)
Arka uç: deploy/backend-2026-10-18-A.zip (sha256 fb29677b…)
Şema değişikliği yok.
```

---

## 3b. Canlıya alma: Faz B

**Arka uç (cPanel):** `deploy/backend-2026-10-18-B.zip` (A paketinin tüm içeriğini de kapsar)

| | |
|---|---|
| commit | `a7697d7` |
| dosya | 870 (654 vendor girdisi) |
| boyut | 2,0 MB |
| sha256 | `c679e2682fbeab3b3bc8d678e3dcdd9face226543522be90bdfc0fc2331a5ab3` |
| denetim | `.env*` 0 · `assets/`+`uploads/` yalnızca `.htaccess` · anahtar taraması 0 bulgu · PHP 8.2 uyumlu |

Adımlar:
1. cPanel Dosya Yöneticisi → `api.lumanoris.com` → Upload → Extract (üzerine yaz). Klasörü silmeyin.
2. Ön yüz: `revize/pazaryeri` → `main` PR'ı (aşağıdaki metin) → merge → Vercel.
3. Kontrol listesi (ben de test hesaplarıyla canlıda bakacağım):
   - Bilgi Bankası → küçük bir PDF, bir DOCX, bir TXT.
   - "Metin ekle".
   - Bir URL (ör. tr.wikipedia.org/wiki/Aristoteles) ve bir bot korumalı site (medium.com → açık mesaj).
   - Paketler sayfası → "Aboneliğim" kartı.
   - Liste → botu çıkar.
   - Ana sayfa → bot pop-up'ı (sayılar, "Ücret", 2026 kutusu yok).
   - Admin → Kullanıcılar → bir kullanıcının "Paket" kutusu (yalnızca görüntüle; atama yapmayın).

**phpMyAdmin SQL / `schema_migrations`:** Faz B'de şema değişikliği **yok**; uygulanacak migration ve kayıt satırı yok. İptal için önerilen `020_plan_cancellation.sql` onay bekliyor ve pakette yok.

PR açıklaması (kopyalanabilir):

```
Müşteri revizyonu 2026-10-18 — Faz B (işlevler)

- N-57 (devam) Ödeme hatası kesin nedeni: Hoppa IP kısıtı; onaylı patch uygulandı (nedene göre mesaj, log özeti, dönüş nedeni)
- N-60 Bilgi bankasından yalnızca soruyla ilgili parçalar (24.000 karakter bütçe); "uydurma / yanlış atıf yapma" talimatı
- N-61 URL okuma: IPv4 pin (20 sitenin 9'u bağlanamıyordu), yönlendirme, tarayıcı başlıkları, 15 hata sınıfı; "Metin ekle"; karakter sınırı mesajı
- N-62 Belge yükleme: PDF, DOCX, TXT; şifreli / taranmış / .doc / boyut mesajları
- N-63 "Aboneliğim" kartı; aktif ücretli paketin düğmesi kapalı
- N-64 PRO etiketi fiyat temelli bayrak + bitiş; admin'de paket, kaynağı ve denetimli atama
- N-65 Listedeki botu çıkarma (onaylı, bot silinmez), görünümler arası tutarlı
- N-66 Bot pop-up sayıları tek kaynaktan; "Ücret" biçimi; "2026 Model Desteği" iddiası kaldırıldı

Doğrulama: bash scripts/verify.sh → DOĞRULAMA: GEÇTİ (22 adım)
Arka uç: deploy/backend-2026-10-18-B.zip (sha256 c679e268…)
Şema değişikliği yok. Onay bekleyenler: docs/proposals/abonelik-yonetimi-2026-10-18.md
```

---

## 4. Müşterinin bilmesi gerekenler (kodla çözülemeyenler)

**Yapay zekâ bazen "yoğun" diyor.**
- Google'ın kendi sunucuları zaman zaman aşırı yoğun oluyor ("high demand"). Bu bizim sistemimizden bağımsız ve birkaç saniye içinde geçiyor.
- Sistem artık böyle bir durumda otomatik olarak bir kez daha deniyor. Yine olmazsa kullanıcıya "kısa süreliğine yoğun" diyor ve LumaCoin'ini geri veriyor.

**Bazı saatlerde "kullanım sınırına ulaşıldı" görülüyor.**
- Kullanılan Google yapay zekâ anahtarı ücretsiz katmanda. Ücretsiz katmanın günlük istek sınırı düşük; sınır dolunca Google o gün için yeni istek kabul etmiyor.
- Bunun kalıcı çözümü Google AI Studio / Google Cloud hesabında faturalandırmanın (ücretli katman) açılması. Bu işlem hesap sahibince yapılmalı.
- Ayrıca geliştirme ortamı için ayrı bir anahtar kullanılmalı; şu an testler de aynı sınırdan harcıyor.
- **Önemli:** Google'da sınır anahtar başına değil, **proje** başına sayılıyor. Aynı projede yeni anahtar üretmek sınırı sıfırlamaz. 18 Ekim akşamı canlıdaki sohbet mesajları da "kullanım sınırına ulaşıldı" yanıtı aldı; anahtar aynı projede yenilendiyse bu sınır değişmez.

**Bazı siteler bilgi bankasına eklenemiyor.**
- Bazı siteler (ör. Medium) otomatik okumayı bilerek engelliyor.
- Bazıları (ör. bazı kurumsal siteler) içeriği ancak tarayıcıda JavaScript çalışınca oluşturuyor.
- Bu iki durumda sistem artık nedeni açıkça söylüyor. Sayfadaki metin kopyalanıp yeni "Metin ekle" alanından eklenebiliyor.

**Bot cevaplarının hızı.**
- Cevap artık geldiği anda kelime kelime ekrana akıyor.
- Uzun bilgi bankalarında her soruda yalnızca ilgili bölümler kullanılıyor.
- Cevabın tamamlanma süresi büyük ölçüde Google modelinin kendi hızına bağlı. Ücretsiz katman sınırı dolduğunda ise cevap hiç gelmiyor.

**Ödeme sayfasına geçilemiyor.**
- Kesin neden bulundu: Hoppa, sitemizin sunucusunun internet adresini (IP: 45.143.99.2) tanımıyor ve bu adresten gelen ödeme isteklerini reddediyor. Ödeme bilgileri ve yazılım doğru.
- Çözüm: Hoppa panelinde izinli IP listesine bu adresin eklenmesi. Panelde bu ayar yoksa Hoppa destekten istenmeli (destek@esnekpos.com · 0212 235 66 00).
- Ekleme yapıldıktan sonra yönetim panelindeki "Hoppa bağlantı testi" "Kimlik bilgileri kabul edildi" gösterecek.
- Hosting firması sunucu adresini değiştirirse bu tanımın yenilenmesi gerekir.

**Hata ekranında sunucu yolu görünüyordu.**
- Sunucudaki bir ayar (`APP_DEBUG`) geliştirici modunda bırakılmıştı. Kapatılması tek satırlık bir sunucu ayarı.
