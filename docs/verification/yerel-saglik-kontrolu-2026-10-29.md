# Yerel sağlık kontrolü — 2026-10-29

**Hüküm:** Yerelde akışlar çalışıyor; ancak şu maddeler sorunlu:
- Sohbet (Gemini) doğrulanamadı: Google yoğunluk ve zaman aşımı döndürüyor.
- Ödenmiş paket admin panelinde "ödemesiz kayıt" görünüyor.
- Taslak bot yeniden açılınca son adım "Özel"i seçili getiriyor.
- Paket yükseltilen gün coin bakiyesi yeni pakete göre artmıyor.
- Google ile giriş yerelde başlatılamıyor (yerel adres Google'da tanımlı değil).

Kapsam: kod, şema ve canlı ayar değiştirilmedi. Canlıya yalnızca giriş yapmadan HEAD/GET istekleri gönderildi. Ekran görüntüleri `docs/verification/2026-10/hc1029/` altında (git dışı).

## 1. Depo durumu

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Branch / son commit | GEÇTİ | `revize/pazaryeri` · `372e726` | |
| Yerel = origin | GEÇTİ | `git rev-parse` ikisi de `372e726` | |
| main'e göre | — | 5 commit önde; main'de yalnızca 3 PR merge commit'i var (merge dışı fark 0) | Önde olanlar: `444ad0b` N-58, `e642bb2` N-67, `0f91166` mesaj, `e034a92` N-68, `372e726` N-68 rapor |
| Commit edilmemiş değişiklik | — | `M .gitignore`, `M BLOCKERS.md` | Benim değil, oturum öncesinden bekliyor. |
| İzlenmeyen dosyalar | — | `docs/acik-isler.md`, `docs/musteri/*.pdf`, `docs/musteri/revizyon-2026-10-18/`, `docs/prompts/13-*.md`, `docs/prompts/14-*.md` | Benim değil; dokunulmadı. |
| PR durumu | DOĞRULANAMADI (kısmen) | `gh` kurulu değil, depo özel (API 404) | Git geçmişinden: PR #1–#3 merge edildi; #3 `f4df68e`'ye kadar (Faz B). Açık bir PR olup olmadığı görülemedi. |
| Ön yüz canlıda hangi sürüm | GEÇTİ | Canlı `chatbots/create` paketi "Yayın & Fiyat" içeriyor; "4. Yayınla" ve `finalizedraft` yok | Canlı ön yüz Faz B'de. N-58'den sonraki 5 commit canlı değil. |
| Arka uç canlıda hangi sürüm | GEÇTİ | `api.lumanoris.com/api/chatbot/finalizedraft.php` → 404 | Canlı arka uç C öncesi (B). |
| `deploy/backend-2026-10-18-C.zip` ↔ son commit | GEÇTİ | Zip `e034a92` (sha256 `e2e9ae3b…cdb194a`, değişmemiş). `git diff e034a92 HEAD -- api/` boş | `372e726` yalnızca belge; zip'te eksik arka uç değişikliği yok. |

## 2. Otomatik kontroller

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| `bash scripts/verify.sh` | GEÇTİ | çıkış 0, `DOĞRULAMA: GEÇTİ`, 23/23 adım | |
| lint | GEÇTİ | "No ESLint warnings or errors" | |
| production build (`.next-verify`) | GEÇTİ | "Compiled successfully", 26/26 statik sayfa | |
| `php -l` | GEÇTİ | 279 PHP dosyası, hata yok | |
| iyzico_selftest | GEÇTİ | 77/0 | |
| plan_limits_selftest --strict | GEÇTİ | 44/0 | |
| access_selftest | GEÇTİ | 76/0 | |
| application_selftest | GEÇTİ | 75/0 | |
| hoppa_selftest | GEÇTİ | 87/0 | |
| migrate_selftest | GEÇTİ | 75/0 | |
| gemini_selftest | GEÇTİ | 95/0 | Çevrimdışı; Google'a gitmez. |
| plan_collation_selftest | GEÇTİ | 11/0 | |
| secret_scan_selftest | GEÇTİ | 14/0 | |
| vendor_selftest | GEÇTİ | 20/0 | |
| hoppa_diag_selftest | GEÇTİ | 44/0 | |
| readurl_selftest | GEÇTİ | 67/0 | |
| document_selftest | GEÇTİ | 35/0 | |
| subscription_selftest | GEÇTİ | 33/0 | |
| draft_selftest | GEÇTİ | 20/0 | |
| node testleri | GEÇTİ | image-url 9, reply-errors 5, plan-badge 5, list-state 5, bot-stats 3 | |
| `migrate.php --status` | GEÇTİ | 001–013, 015–019 uygulanmış; "Her şey güncel" | 014 bilerek `pending/`'de (veri düzeltme, onay bekliyor). Bekleyen migration yok. |

## 3. Yerelde uçtan uca deneme

Kullanılan yerel test hesapları:
- `e2e-a` (#108, ücretsiz; onaylı pazaryeri başvurusu olduğu için herkese açık hakkı sınırsız).
- `e2e-b` (#109, ücretsiz).
- Kayıt denemesi için açılan `hc-1029@example.com` (#114). Silindi.

### Kayıt ve giriş

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Kayıt | GEÇTİ | `register.php` 200, id 114; "Hesabınız oluşturuldu. Şimdi giriş yapabilirsiniz." (`auth-1-kayit.png`) | |
| E-posta ile giriş | GEÇTİ | `/dashboard/`'a yönlendi; `sessioncheck` → `authenticated: true, user_id: 108` | |
| Yanlış parola | GEÇTİ | 401 "Kullanıcı adı/e-posta veya şifre hatalı!" | |
| Google ile giriş | DOĞRULANAMADI | Düğme çiziliyor (`auth-3-login.png`). Google: `GSI_LOGGER: The given origin is not allowed for the given client ID`, `gsi/button` 403 | Yerel adres (`localhost:3000`) Google istemcisinde izinli değil; uygulama hatası değil. Canlıda 2026-10-19'da sizin denemenizle çalışıyordu. |

### Yeni bot, taslak ve yayınlama

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| 1. adım → otomatik taslak | GEÇTİ | "Devam Et" → `savechatbot` 200 `{"message":"Taslak kaydedildi.","is_draft":true}` (#75 a, #76 b) | |
| Taslak başka hesapta görünmüyor | GEÇTİ | #76: diğer hesaptan `getchatbot` 404, vitrin listesinde yok | #75 ayrıntı denemesi test betiğindeki parametre hatasıyla (400) boşa gitti. Vitrin listesinde yokluğu doğrulandı. |
| Bilgi bankası: PDF | GEÇTİ | `kurulus.pdf` → 200, 126 karakter, listeye eklendi | |
| Bilgi bankası: DOCX | GEÇTİ | `kb.docx` → 200, 54 karakter | |
| Bilgi bankası: TXT | GEÇTİ | `kb.txt` → 200, 73 karakter | |
| Bilgi bankası: URL okuma | GEÇTİ | `tr.wikipedia.org/wiki/Pazaryeri` → 12.509 karakter eklendi | `…/Aristoteles` (65.069 karakter) 60.000 sınırını aşıyor; beklenen mesaj çıkıyor: "Karakter sınırı aşılıyor … kalan 59.550" (`kb-url-sinir.png`). |
| Bilgi bankası: metin ekleme | GEÇTİ | "Metni Ekle" → listede 53 karakter | |
| 4. Yayınla: herkese açık | GEÇTİ | `publishchatbot` 200 "herkese açık olarak yayınlandı"; DB'de `is_independent=0, is_draft=0`; "HC a-public" kartı diğer hesabın (e2e-b) ana sayfasında görüldü | Bekleme sırasında kısa bir yanlış etiket var (Sorunlar #3). |
| 4. Yayınla: özel | GEÇTİ | `finalizedraft` 200 "Bot özel olarak kaydedildi"; Chatbotlarım'a yönlendi; diğer hesaptan 404 | |

### Sohbet

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| 3 mesaj: cevap, hafıza, tanıtım tekrarı yok | DOĞRULANAMADI | 2 deneme × 3 mesaj: Google 503 "This model is currently experiencing high demand" ve 30 sn zaman aşımı. Admin "Gemini'yi test et": 15 sn zaman aşımı | Anahtar geçerli (400/429 yok); kota değil, Google tarafında yoğunluk. |
| Hata yolunda coin ve sayaç tutarlılığı | GEÇTİ | Her hatada `refunded: true`. Kenar çubuğu 10/10 LMC, sohbet çipi "Bugün kalan Lumacoin: 10". Mesajlar "Bu mesaj için LumaCoin düşülmedi" diyor (`chat-3-mesaj.png`) | Başarılı mesajda coin düşüşü bu nedenle görülemedi. |

### Görseller

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Kırık görsel yok | GEÇTİ | Kırık `<img>` 0, görsel 4xx 0. Sayfa başına yüklü görsel: ana sayfa 5, Keşfet 4, sohbet 5, takip 2, notlar 2, liste 2, geçmiş 2, Chatbotlarım 2 (`img-*.png`) | |
| Bot pop-up'ı | GEÇTİ | Avatar ve kapak yüklü; "Ücret: Ücretsiz"; "2026 Model Desteği" kutusu yok (`img-popup-anasayfa.png`) | |
| Takip seçici | GEÇTİ | Ana sayfa "Takip ettiklerim" açılıyor, kırık görsel yok | |

### Listeler

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Ekleme ve listeden çıkarma (bot silinmeden) | GEÇTİ | Liste oluşturuldu → bot eklendi → arayüzde "Listeden çıkar" + onay → `deletebotfromlist` 200; listede yok, bot 200 (`liste-*.png`) | Test listesi silindi. |

### Paketler

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Aboneliğim kartı | GEÇTİ | Ücretsizde "Ücretsiz paket kullanıyorsunuz"; Gümüş sonrası "Gümüş · 30 gün kaldı · 29.10.2026–28.11.2026" ve ödeme geçmişi (`paket-1-once.png`, `paket-5-sonra.png`) | |
| Aktif paketin düğmesi kapalı | GEÇTİ | Ücretsiz: "Mevcut Paket [kapalı]"; Gümüş: "Mevcut paketiniz [kapalı]" | |
| PRO yalnızca ücretli hesapta | GEÇTİ | Ücretsiz: menüde PRO yok; Gümüş: başlıkta ve menüde PRO var | |

### Ödeme (yalnızca Hoppa test ortamı)

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Gümüş satın alma | GEÇTİ | `postest.esnekpos.com` (test). Yayımlanmış test kartı 9792…0001 → dönüş "Ödemeniz alındı; paketiniz 30 gün için etkinleştirildi."; oturum korunuyor (N-31 tekrarlamadı) | Hoppa test sayfasında kendi dosyalarına ait 404'ler (handlebars, logo); bizden değil. |
| Paket tanımlanması | GEÇTİ | `getmysubscription` → `state: active, plan: Gümüş, days_left: 30` | Admin'deki kaynak etiketi yanlış (Sorunlar #1). |
| Admin'den iade | GEÇTİ | Paket Ödemeleri → "İade et" → 200 "İade tamamlandı; paket geri alındı." (`provider_state: cancelled`, aynı gün, 151,25 ₺). Kullanıcı tekrar Ücretsiz (`admin-5-iade.png`) | |
| Coin hakkı yükseltmede | KALDI | Gümüş sonrası kenar çubuğu **10/50 LMC** | Sorunlar #4. |

### Admin

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| Sistem Durumu: bileşenler | GEÇTİ | Model, anahtar kaynağı, son 24 saat sohbet hataları (5: yoğunluk 3, zaman aşımı 2) gösteriliyor (`admin-1-sistem-durumu.png`) | |
| Sistem Durumu: Gemini testi | DOĞRULANAMADI | "Hata · ağ: Operation timed out after 15006 ms" | Sohbetle aynı neden. |
| Sistem Durumu: Hoppa bağlantı testi | GEÇTİ | "Kimlik bilgileri kabul edildi", test · `posservicetest.esnekpos.com`, 691 ms | |
| Kullanıcılar → paket kutusu | GEÇTİ (veri hatalı) | `kullanicipaket.php?user_id=109` → plan/durum/bitiş doğru | Kaynak `legacy` "Ödemesiz kayıt" (Sorunlar #1). |
| Başvurular | GEÇTİ | Sayfa ve filtreler açılıyor (`admin-3-basvurular.png`) | |
| Paket Ödemeleri | GEÇTİ | Satır "Ödendi · TEST · PLN-673BDE8AE6AF2EB4 · İade et" | |

### Bireysel satıcı uçları

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| `submerchant_register`, `submerchant_resubmit` | GEÇTİ | Dolu istek → 403 "Bireysel satıcı kaydı kabul edilmiyor…" | Boş `{}` istek 400 "Eksik veri" (doğrulama 403'ten önce, zararsız). |
| `submerchant_update`, `_delete`, `_list`, `_list_remote` | GEÇTİ | 403 "Yönetici girişi gerekiyor." | |
| `parampos_callback`, `marketplace_reconcile` | GEÇTİ | 403 "Yetkisiz." | |
| `marketplace_refund` | GEÇTİ | 410 "İade admin panelinden yapılır." | |
| `submerchant_status` | — | GET 200, kullanıcının kendi durumu (`not_started`) | Salt okunur, kendi kaydı; sorun değil. |

## 4. Canlı, salt okunur (giriş yok, yalnızca HEAD/GET)

| Kontrol | Sonuç | Kanıt | Not |
|---|---|---|---|
| www.lumanoris.net | GEÇTİ | 200; `lumanoris.net` → 308 www | |
| www HTTPS | GEÇTİ | `*.lumanoris.net`, Let's Encrypt, doğrulama 0; bitiş **28.11.2026** | Vercel kendisi yeniler; bir ay içinde dolacağı için Kasım ortasında göz atılmalı. |
| api.lumanoris.com HTTPS | GEÇTİ | `api.lumanoris.com`, Let's Encrypt, doğrulama 0; bitiş 18.01.2027 | Kök 403 (index yok). |
| `/.env`, `/.env.example`, `/.env.bak`, `/admin/.env` | GEÇTİ | 403 | |
| `/vendor.zip` | GEÇTİ | 404 | |
| `/vendor/`, `/vendor/autoload.php` | GEÇTİ | 403 | |
| `/storage/`, `/storage/logs/php-error.log`, `/php-error.log`, `/error_log`, `/deploy/` | GEÇTİ | 404 | |
| `/.git/`, `/.git/config`, `/.git/HEAD`, `/.htaccess` | GEÇTİ | 403 | |
| `/composer.json`, `/composer.lock`, `/database/…`, `/functions/db.php`, `/assets/` | GEÇTİ | 403 | |
| `/router.php` | GEÇTİ (not) | 200, gövde 0 bayt | Kaynak sızmıyor; yalnızca çalışıyor. Sorunlar #6. |

## Canlıya alınmayı bekleyenler (sırayla)

1. **Arka uç:** `deploy/backend-2026-10-18-C.zip`.
   - Commit `e034a92`, sha256 `e2e9ae3b813c3d280cbe6c0df5b0eb16276bf1b2744ab5b041aa56975cdb194a`.
   - cPanel → `api.lumanoris.com` → Upload → Extract (üzerine yaz).
   - İçerik: N-58 (`.gitignore`), N-67, N-68.
2. **phpMyAdmin:** `api/database/phpmyadmin/019_chatbot_draft.sql` (ön kontrol → ALTER → doğrulama).
   - `schema_migrations` tablosu varsa INSERT; checksum `8b74b3c36208f36f20eb176be5421ade54a5f107adec159e8601ed4063228d51`.
   - Metin: `docs/verification/musteri-revizyon-2026-10-18.md` §3c.
3. **Ön yüz:** `revize/pazaryeri` → `main` PR'ı (§3c'deki metin) → merge → Vercel.
   - Bu raporun commit'i de PR'a girer.
   - **1 ve 2'den önce merge edilmemeli:** yeni ön yüz eski arka uçla taslak yerine normal bot kaydeder.
4. Sonra: test hesaplarıyla canlı akış (yeni bot → bilgi bankası → yayınla), ücretsiz ve ücretli.

## Sorunlar ve önerilen düzeltmeler (uygulanmadı)

1. **Ödenmiş paket admin'de "Ödemesiz kayıt" görünüyor (P2, ödeme yolu → onay gerekir).**
   - Hoppa ile yeni ödenmiş Gümüş, admin Paket kutusunda `legacy` "Ödemesiz kayıt — eski sistem ya da elle eklenmiş" olarak görünüyor.
   - Neden: `WalletController.php:499` `selected_at`'i PHP `date()` ile yazıyor (yerelde UTC). Sipariş `created_at` ve `expires_at` ise MySQL `NOW()` ile yazılıyor (yerelde UTC+3).
   - `getPlanSource` (`functions/plans.php:329-335`) siparişin seçimden en fazla 1 dakika sonra olmasını istiyor; 3 saatlik fark eşleşmeyi bozuyor.
   - Aynı kalıp `plans.php:377` (admin ataması) ve `hosted_plan_payments.php:369`'da da var.
   - Canlıda PHP ile MySQL saat dilimi farklıysa her gerçek satış admin'e ödemesiz görünür ve "Aboneliğim" başlangıç saati kayar.
   - Öneri:
     - Üç yazımı da `selected_at = NOW()` (SQL saati) yapmak.
     - Önce `subscription_selftest`'e farklı saat dilimini taklit eden kırmızı test eklemek.
     - Canlı saat dilimleri için `SELECT NOW(), @@session.time_zone;` ile PHP `date_default_timezone_get()` karşılaştırılmalı (salt okunur).
2. **Taslak yeniden açılınca "Yayınla" adımı "Özel olarak kaydet"i seçili getiriyor (P2, belirsiz iş kuralı → sorulmalı).**
   - Taslak her zaman `is_independent = 1` saklandığı için `choice` "independent" oluyor ve `publishAs` varsayılanı "private" (`create/page.jsx:345, 1493-1495`).
   - Herkese açık başlayıp sonra "Devam et" ya da "Bilgi Bankası" ile dönen kullanıcı dikkat etmezse botu özel kaydeder ve ücretsizdeki tek özel hakkını harcar.
   - Seçenekler:
     - (a) Taslakta hiçbir seçenek seçili gelmesin, kullanıcı seçmeden düğme kapalı kalsın. Yalnızca ön yüz; önerim bu.
     - (b) İlk seçimi taslakta saklamak; şema değişikliği gerektirir.
3. **Herkese açık yayından sonra yönlendirme beklenirken sayfa "Erişim Türü: Bağımsız (Özel)" gösteriyor (P3, ön yüz).**
   - `setIsDraft(false)` sonrası eski `independentMode` çiziliyor.
   - Üretimde bir anlık; geliştirme sunucusunda yaklaşık 6 sn görüldü (`bot-a-public-3-chatbotlarim.png`).
   - Öneri: başarıdan sonra `isDraft`'ı kapatmadan yönlendirmek ya da düğmeyi "Yönlendiriliyor…" durumunda tutmak.
4. **Paket alındığı gün coin hakkı yükselmiyor (iş kuralı → müşteriye sorulmalı).**
   - Gümüş (günlük 50) alan kullanıcı o gün ücretsizden kalan 10 coin ile devam ediyor; yeni limit ancak ertesi gün sıfırlamada geliyor (`coin_engine.php:91-106`).
   - Öneri (karar sonrası, ödeme yolu): kesinleştirmede o günün bakiyesini `GREATEST(coins_remaining, yeni_limit - bugün_harcanan)` ile yükseltmek.
   - Kural netleşmeden test yazılmadı.
5. **Gemini cevap vermiyor (dış servis, BLOCKERS B4).**
   - Bugün 7 çağrının hepsi Google 503 "high demand" ya da 30/15 sn zaman aşımı; anahtar ve kota hatası yok.
   - Kod tarafı doğru davranıyor: coin iadesi ve açık mesaj var.
   - Faturalandırma açıldıktan sonra `live-reply-latency` ve görsel 06 testi bekliyor.
6. **`/router.php` canlıda 200 dönüyor (P3, düşük risk).**
   - İçerik sızmıyor, ama yerel geliştirme yönlendiricisinin canlıda çalıştırılabilir olmasına gerek yok.
   - Öneri: `api/.htaccess` denylist'ine eklemek. `api/admin/.htaccess` yalnızca `admin/` altını, `router.php`'nin kendi denylist'i yalnızca yerel `php -S` kurulumunu kapsıyor; canlıdaki Apache için yeterli olan `api/.htaccess`.
7. **Google girişi yerelde denenemiyor (ortam).**
   - Google istemcisinin izinli JavaScript kaynaklarına `http://localhost:3000` eklenirse yerel uçtan uca test mümkün olur.
   - Bu bir hesap ayarı ve sizin kararınız.
8. **Hoppa test sayfasının kendi 404'leri** (handlebars, logo): bizimle ilgisi yok, bilgi için.

## Temizlik

- **Silinenler:**
  - Botlar #75, #76: sahipleri tarafından "Sil" ile. Bağlı sohbet ve bilgi bankası satırları zincirleme silindi; kalıntı 0 (kontrol edildi).
  - Hesap #114: admin paneli → Kullanıcılar → Sil.
  - "HC liste": kullanıcı tarafından silindi.
- **e2e-b paketi:** iade ile Ücretsiz'e döndü.
- **Bilerek bırakılanlar:**
  - e2e-b'nin bugünkü üç ödeme kaydı: `PLN-673BDE8AE6AF2EB4` (iade edildi) ile `PLN-58A6984A5F539379` ve `PLN-3C4F777EB107DC78` (`hoppa_pending`, ödeme sayfasına geçilmeden kalan iki deneme).
  - İade için admin denetim kaydı.
  - Bunlar ödeme ve denetim kayıtları; silmek elle `DELETE` gerektirir. Bekleyen ikisi mutabakat görevinde 24 saat sonra `hoppa_expired` olur.
- **Sohbet:** e2e-b'nin bot #75 ile sohbeti bot silinince gitti. Gemini'ye giden 7 çağrının hiçbiri cevap üretmedi; coin iade edildi.

## Not: bu denetimde bir çıktı kazası

Hoppa yapılandırma anahtarlarının **adlarını** listelerken yanlış bir filtre, `HOPPA_TEST_MERCHANT_KEY` değerinin ilk 24 karakterini terminale bastı.
- Bu, Hoppa/EsnekPOS dokümanında herkese açık yayımlanan **test** üye işyerinin anahtarı (`docs/proposals/hoppa-gecis-kesif.md` satır 50).
- Canlı anahtar değil; hiçbir dosyaya ya da commit'e yazılmadı.
- Sonraki komutlarda yalnızca anahtar adları okundu.
