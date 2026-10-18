# Müşteri revizyonu 2026-10-18: doğrulama raporu

Kaynak: `docs/musteri/revizyon-2026-10-18.pdf` (7 madde) ve `docs/musteri/revizyon-2026-10-18/*.jpeg` (10 görsel).
Branch: `revize/pazaryeri`. AUDIT kayıtları: N-55 … N-59.

Durum: **Faz A tamamlandı** (A2'nin ödeme yoluna bağlanması onay bekliyor). Faz B ve Faz C başlamadı.

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
| B1 · hız, SSE, talimat, doğruluk | | Faz B | | |

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
| A1 · dosya türleri | Desteklenen her türle dene | Bugün desteklenen tek tür **PDF** (yerelde: 200, metin döndü). TXT/DOCX desteği yok; B2'de ele alınacak | | Hayır |
| B2 · URL okuma ve belge hata sınıfları | | Faz B | | |

### Madde 4: Bilgi bankası ve yayın akışı

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| C1 | Taslak kayıt, yönetimden erişim, "Yayınla" | Faz C | | |

### Madde 5: Ödeme, abonelik, paket

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| A2 · gerçek hata | Hoppa'nın döndürdüğü hatayı bul | Bu metin yalnızca Hoppa başarısız döndüğünde ya da Hoppa'ya ulaşılamadığında üretiliyor. Gerçek neden log'a yazılmıyordu, ama canlı DB'de duruyor (aşağıdaki sorgu 2.1). **En güçlü aday:** BLOCKERS B3. Anahtar Hoppa'da yenilendiyse ve `.env`'de eski `HOPPA_LIVE_MERCHANT_KEY` duruyorsa Hoppa `AUTHENTICATION_ERROR` döner | AUDIT N-57 | **Hayır.** Sizden sorgu 2.1 sonucu ve bağlantı testi gerekiyor |
| A2 · olasılıklar | merchant, IP/alan adı, BACK_URL, tutar, uç adres | Hoppa dokümanında IP ya da alan adı tanımı şartı **yok**. `APP_PUBLIC_URL` hatalı olsaydı mesaj "Ödeme altyapısı hazırlanıyor" olurdu, yani BACK_URL sorunu değil. Tutar biçimi `149.00` test ortamında çalıştı. Canlı uç adres `posservice.esnekpos.com` | Kod + doküman | — |
| A2 · bağlantı testi | Ödeme başlatmayan zararsız çağrı | Admin → Sistem Durumu → "Hoppa bağlantı testi": var olmayan referansla ProcessQuery. Test ortamında geçerli kimlik 400 "Referans bulunamadı", uydurma kimlik `AUTHENTICATION_ERROR` döndü, yani ikisi ayırt edilebiliyor | Yerel, gerçek `_guard` + CSRF yolundan: 546 ms "kimlik kabul edildi" | Hayır, zip bekliyor |
| A2 · Türkçe mesajlar | Kod → anlaşılır mesaj | `hoppaUserMessage`: dokümandaki 100–206 ve 34 kodları, "51-Limit Yetersiz" biçimli banka reddi. Kimlik sorunu kullanıcıya "bakımda" diye gider, ayrıntı log'a yazılır | `hoppa_diag_selftest` (40) | — |
| A2 · ödeme yoluna bağlama | | **Onay bekliyor:** `docs/proposals/n57-hoppa-hata-mesajlari.patch` (WalletController hata dalı + `hoppaReturn` neden kodu + paket sayfası) | `git apply --check` temiz | — |
| B3 · abonelik yönetimi | | Faz B | | |

### Madde 6: Paketler ve PRO etiketi

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| B4 | | Faz B | | |

### Madde 7: Listeden bot kaldırma

| Alt başlık | İstenen | Yapılan | Kanıt | Canlıda doğrulandı mı |
|---|---|---|---|---|
| B5 | | Faz B | | |

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

## 4. Müşterinin bilmesi gerekenler (kodla çözülemeyenler)

**Yapay zekâ bazen "yoğun" diyor.**
- Google'ın kendi sunucuları zaman zaman aşırı yoğun oluyor ("high demand"). Bu bizim sistemimizden bağımsız ve birkaç saniye içinde geçiyor.
- Sistem artık böyle bir durumda otomatik olarak bir kez daha deniyor. Yine olmazsa kullanıcıya "kısa süreliğine yoğun" diyor ve LumaCoin'ini geri veriyor.

**Bazı saatlerde "kullanım sınırına ulaşıldı" görülüyor.**
- Kullanılan Google yapay zekâ anahtarı ücretsiz katmanda. Ücretsiz katmanın günlük istek sınırı düşük; sınır dolunca Google o gün için yeni istek kabul etmiyor.
- Bunun kalıcı çözümü Google AI Studio / Google Cloud hesabında faturalandırmanın (ücretli katman) açılması. Bu işlem hesap sahibince yapılmalı.
- Ayrıca geliştirme ortamı için ayrı bir anahtar kullanılmalı; şu an testler de aynı sınırdan harcıyor.

**Ödeme sayfasına geçilemiyor.**
- Bunun nedeni Hoppa'nın verdiği cevapta. Kodumuz isteği doğru biçimde gönderiyor (test ortamında ödeme alındı).
- En olası neden, canlı Hoppa anahtarının yenilenmiş ve sunucuya yeni anahtarın girilmemiş olması ya da canlı üye işyeri hesabının henüz aktif edilmemiş olması.
- Yönetim panelindeki "Hoppa bağlantı testi" bunu tek tıkla gösteriyor. Hesap aktivasyonu gerekiyorsa Hoppa ile görüşülmeli (destek@esnekpos.com · 0212 235 66 00).

**Hata ekranında sunucu yolu görünüyordu.**
- Sunucudaki bir ayar (`APP_DEBUG`) geliştirici modunda bırakılmıştı. Kapatılması tek satırlık bir sunucu ayarı.
