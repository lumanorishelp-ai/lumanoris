# Müşteri gereksinim kontrolü — Pazaryeri revizyonu (canlı, 2026-10-17)

**Kaynak notu:** İstenen `docs/musteri/lumanoris-pazaryeri-revizyon.pdf` depoda ve yerel klasörlerde **bulunamadı**. Denetim; görev metnindeki kontrol listesi + paket tablosu ve dokümandan çıkarılmış özet `docs/prompts/11-pazaryeri-revizyon.md` (kaynak: `PAZARYERİ SİSTEM DÜZENLEMESİ.pdf`) üzerinden yapıldı. "Metin birebir mi" sorusu yalnızca bu iki kaynakta tırnak içinde verilen ifadeler için cevaplanabildi; PDF'teki başka cümleler kıyaslanamadı.

**Yöntem:** (a) kod — kuralın sunucuda mı arayüzde mi zorlandığı; (b) canlı — www.lumanoris.net, `.env.e2e-prod` A (deneme110) ve B (deneme111) hesapları; ödeme yapılmadı, admin'e girilmedi, başkasının verisine yazılmadı; (c) metin. Ekran görüntüleri git'e girmez: `docs/verification/musteri-kontrol/MK-*.png` (bugün) ve `docs/verification/prod-2026-10/U-*.png` (2026-10-15, 14 numaralı canlı kontrol — o akışların kodu sonradan değişmedi).

## Özet

| Durum | Sayı |
|---|---|
| TAMAM | 22 |
| KISMEN | 4 |
| EKSİK | 0 |
| FARKLI YORUMLANDI | 4 |
| DOĞRULANAMADI | 1 |
| **Toplam** | **31** |

Dokümandaki ana kurallar canlıda karşılanıyor: bireysel başvuru kapalı ("Yakında"), herkese açık bot kartı, ücretsiz plan limitleri **sunucuda** zorlanıyor (2. bağımsız ve 3. herkese açık bot 422), Bakiyem metni birebir, ayarlardan başvuruya yönlendirme, "Satın Al" yok, paket kataloğu tabloyla birebir. Açık kalanlar: eski bireysel satıcı API'si kuralla değil yalnızca bir taslak (stub) sayesinde kapalı (N-51), birkaç arayüz hatası (N-52, N-53) ve müşteri onayı olmadan geliştiricinin verdiği yorum kararları (GK-4, GK-5, GK-12, GK-14, GK-17, GK-27).

## Madde madde

| Madde | Dokümanda istenen | Durum | Kanıt | Not |
|---|---|---|---|---|
| 0 / M0-1 | Belge (PDF) yükleyerek eğitim çalışmalı | TAMAM | canlı: `prod-2026-10/U-02-d-pdf.png` (PDF → 200, "164 / 60.000 karakter"); `create/page.jsx` Bilgi Bankası | GK-16: yalnızca PDF, OCR yok, 5 MB (müşteri onayı yok). 6 MB PDF istemcide reddediliyor (`U-02-f`). |
| 0 / M0-2 | Kendi bağımsız botuna tıklayınca sohbet açılmalı | TAMAM | canlı: `U-03-a-liste`, `U-03-b-cevap` (başlık → `/dashboard/chat/?botId=35`) | |
| 0 / M0-3 | Liste sayfası hatasız | TAMAM | canlı: `U-07-c-liste`, `U-07-e-liste-ayrinti` (500 yok, liste + bot görünüyor) | |
| 0 / M0-4 | Defter pop-up'ındaki buton botun sohbet sayfasına gitmeli | TAMAM | canlı: `U-08-f-mevcut-kayit-popup` — bağlantı `/dashboard/chat?botId=22` ("… ile sohbet et") | Paylaşan kişi bağlantısı (`/dashboard/user/?id=…`) da duruyor (GK-15). |
| 1 | Bireysel pazaryeri kaydı kullanım dışı, alanda "Yakında" | TAMAM | canlı: `MK-01-03-basvuru-formu` — "Bireysel · YAKINDA · Vergi kaydı olmayan bireysel satıcılar şu an başvuramaz."; `pazaryeri-basvurusu/page.jsx:222`; sunucu: `functions/marketplace_application.php:56` — API'ye `account_type: bireysel` → **400** "Hesap türü şahıs şirketi ya da kurumsal olmalıdır.", başvuru oluşmadı | Metin: doküman "Yakında", ekran "YAKINDA" (büyük harf stil). |
| 1 / genel hedef | Bireysel satıcılık hiçbir yoldan mümkün olmamalı | KISMEN | `WalletController.php:231` `saveBankInfo` hesap türünü serbest yazıyor ("Bireysel Hesap" dahil); `SellerController.php:3` `register` (ayrıca `:176 update`, `:200 resubmit`) yalnızca oturum istiyor ve bireysel tipi (Tip 1) Param'a gönderiyor; bugün yalnızca `ParamPosMarketplace.php:53` taslağı (her zaman başarısız) yüzünden kapalı | **N-51.** Bu uçların `web/src`, `api/admin`, `router.php`'de çağıranı yok. Canlıda çağrılmadı (dış servise istek atar). Param entegrasyonu yazıldığı gün bireysel satıcılık açılır. |
| 2a | Oluştur sayfasında "Herkese Açık Chatbot Oluştur" kartı | TAMAM | canlı: `MK-02-a-olustur-kartlar` — "Bağımsız (Özel) Chatbot" + "Herkese Açık Chatbot Oluştur", "Kapasite: 0 / 1", "0 / 2"; `create/page.jsx:1347-1432` | |
| 2b | Ücretsiz: 2 herkese açık + 1 bağımsız | TAMAM | canlı (A): 1. bağımsız 200; **2. bağımsız 422** "Ücretsiz planınızdaki 1 bağımsız chatbot hakkınızı kullandınız."; 1. ve 2. herkese açık 200; **3. herkese açık 422** "… 2 herkese açık chatbot hakkınızı kullandınız."; sonrası "Kapasite: 1 / 1", "2 / 2" (`MK-02-b-olustur-dolu`). Sunucu: `ChatbotController.php:45`, `chatbot_limits.php` | Kural **sunucuda** — istek arayüzü atlayarak doğrudan gönderildi. Oluşturulan #36, #37, #38 silindi. |
| 2c | Pazaryeri kaydı bulunan kullanıcıya sınırsız (herkese açık) hak | FARKLI YORUMLANDI | `chatbot_limits.php:58-74` — sınırsız yalnızca `param_marketplace_sellers.status = 'active'`; onaylanmış ("incelendi") başvuru sınırsız VERMİYOR (GK-5, GK-6, GK-9) | Ödeme altyapısı (B1) olmadan kimse `active` olamıyor → bugün **hiç kimse** sınırsız hak alamıyor. Canlıda onaylı test hesabı yok. Müşteriye sorulacak. |
| 3a | Pazaryeri kaydı yalnızca şirketlere | TAMAM | canlı: başlık "Pazaryeri kaydı yalnızca şirketlere açıktır."; hesap türü Şahıs Şirketi / Kurumsal; sunucu reddi (madde 1) | GK-8: şahıs şirketleri dahil. |
| 3b | Başvuru formu şirket bilgisi istiyor | TAMAM | canlı alanlar: Ticari unvan, Vergi numarası, Vergi dairesi, MERSİS numarası, Yetkili adı/soyadı/doğum tarihi, IBAN, İl, İlçe, Açık adres (`MK-01-03`); sunucu doğrulaması (14 kontrolünde: vergi no 10 hane, MERSİS 16 hane, 18 yaş, TR IBAN) | GK-7: belge yükleme yok. |
| 3c | Eski kayıt ekranı yerine "Pazaryeri Başvurusu" | KISMEN | Arayüz: `BankInfo.jsx`, `SellerOnboardingWizard.jsx` silinmiş; menüde yok; `/dashboard/settings/?tab=security` → `/dashboard/pazaryeri-basvurusu/`; `/dashboard/market/` 404. **API:** `save_bank_info.php`, `submerchant_register/update/resubmit.php` duruyor | N-51 ile aynı. |
| 4 | Başvurusu olmayana: "Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir." | TAMAM | canlı (B): metin **birebir** (`MK-04-bakiyem`); `wallet/page.jsx:59` | Arayüz kapısı; cüzdan uçlarının sunucu yetkisi değiştirilmedi (prompt 11 kararı). |
| 5 | Satın Aldıklarım: kullanıcı chatbot satın alınca aktif | TAMAM | `Sidebar.jsx:102-130` → `getmysubscriptions.php` → `user_subscriptions` (chatbot satın alımı, **paket aboneliği değil**); canlı (B, satın alımı yok): menüde yok (`MK-08-kenar-coin`), doğrudan URL açılıyor (`MK-05`) | GK-12: süresi dolmuş satın alımlar da sayılıyor. Uygulama doküman ile aynı şeye bakıyor. |
| 6 | Ana sayfa seçimi takip edilen botlar arasından | TAMAM | canlı: "TAKİP ETTİKLERİM · LUMANORIS AI · Henüz takip ettiğiniz bir bot yok … Keşfet" (`MK-06-secici`); takipli ücretsiz bot seçilebiliyor (`prod-2026-10/U-06-d`); `dashboard/page.jsx:673` | GK-13: hiç takip yoksa Lumanoris AI varsayılan; erişimsiz takipli bot kilitli + "Profili gör". |
| 7 | Ayarlar → Banka sekmesi doğrudan başvuruya | TAMAM | canlı: sekme tıklanınca `/dashboard/pazaryeri-basvurusu/` (`MK-07-ayarlar`); `settings/page.jsx:645-654` | Metin farkı: sekmenin adı **"Ödeme Bilgileri"**, doküman "Banka". |
| 8 | Günlük 10 Luma Coin (değişmeyecek) | TAMAM | canlı: kenar çubuğu "Ücretsiz 10/10 LMC"; katalog `daily_messages: 10` | |
| 9 | Sohbet sayfasında "Satın Al" kaldırılacak | TAMAM | canlı: kendi botu (`U-03`) ve başkasının ücretli botu "Fitness Koçu" (`MK-09`) — "Satın Al" yok; `chat/page.jsx:1037` (GK-14) | Başkasının **ücretli** botunda "Sepete Ekle" hapı var (`ProfileCard.jsx:351, 483`; N-24 kararı) — müşteriye sorulacak. Ayrıca bkz. N-52. |
| 10a | Bağımsız bot: "Yayınla" doğrudan herkese açık | TAMAM | canlı: `prod-2026-10/U-04` — publish 200 "Herkes ücretsiz sohbet edebilir", B Keşfet'te "Ücretsiz" rozetiyle görüyor | |
| 10b | "Pazaryerine Kaydet": başvuru gerektiğini söyleyen pop-up | TAMAM | canlı: `U-04-a-pazaryerine-kaydet` — "Pazaryerinde ücretli satış yapabilmek için pazaryeri başvurusu gerekmektedir…", buton → `/dashboard/pazaryeri-basvurusu/` (`U-04-b`); `PublishModal.jsx:109-139` | |
| 10c | Yayınlanan bot kartında durum | KISMEN | `chatbots/page.jsx:178-184` — `is_independent=0` ve satıcı `active` değilse rozet "Yayında Değil"; canlı: yayındaki ücretsiz bot "Yayında Değil" (`prod-2026-10/U-05-c-hak-bitti`) | **N-53.** Doküman maddesi değil ama madde 10'un sonucu yanlış gösteriliyor. |
| 11a | Ücretsiz: yayınladıktan sonra 1 kez özel yapabilir | TAMAM | canlı (2026-10-15): 1. özel yap 200 (`privacy_remaining: 0`), B'de kayboldu (404); 2. deneme **422** "Ücretsiz planınızdaki 1 yayından kaldırma (özel yapma) hakkınızı kullandınız." + ekranda uyarı (`U-05-a…c`); `chatbot_limits.php:89-130`, `ChatbotController.php:554` | Sunucuda zorlanıyor. |
| 11b | Üst paketlerde hak artıyor | TAMAM | katalog `privacy_rights` 3 / 5 / 20 (canlı `getpricing`); `getPrivacyRightLimit()` plandan okuyor; `plan_limits_selftest` (44/44) | Canlıda ücretli hesap yok — üst paket canlı denenmedi. N-49 (paket okuması) canlıya alınmış olmalı. |
| P1 | Paket tablosu ↔ veritabanı kataloğu | TAMAM | canlı `getpricing.php`: Ücretsiz 0/10/1/2/1 · Gümüş 149/50/3/5/3 · Altın 299/100/5/10/5 · Elmas 849/200/∞/∞/20 — tabloyla **birebir** | |
| P2 | Paket tablosu ↔ sunucu limitleri | TAMAM | `getIndependentBotLimit` / `getPublicBotLimit` / `getPrivacyRightLimit` / günlük coin plandan; Elmas NULL = sınırsız (`normalizeBotLimit`); ücretsiz canlıda denendi (2b, 11a) | Ücretli planlarda canlı deneme yok (ödeme kapalı). |
| P3 | Paket tablosu ↔ fiyat sayfası metni | TAMAM | canlı `PAY-a-yukselt` / `getpricing`: "Günlük 50 Luma Coin · 3 bağımsız chatbot · 5 herkese açık chatbot · 3 yayından kaldırma (özel yapma) hakkı …" | Fiyat "/Aylık", ek not "30 gün geçerlidir ve otomatik yenilenmez". |
| P4 | "/ay": süre 1 ay, bitince ücretsize dönüş | FARKLI YORUMLANDI | `WalletController.php:760-766` — ödeme kesinleşince `expires_at = NOW() + 30 gün`; `plans.php:144-151` süresi dolan seçimi okumuyor → ücretsiz; `hosted_plan_payments.php:119` bitişten 3 gün önce hatırlatma | **30 günlük tek seferlik satış, otomatik yenileme yok** (D-05, GK-27). "/ay" yinelenen abonelik olarak okunabilir — müşteriye sorulacak. Canlıda ücretli hesap olmadığı için bitiş denenmedi. |
| P5 | Paket kartı özellikleri (Öncelikli destek, 7/24 VIP destek, Yeni özelliklere erken erişim, Diğer tüm özelliklerde sınırsız deneyim, Tüm Lumanoris deneyimlerine erişim) | FARKLI YORUMLANDI | Ekranda var (`getpricing` / `PAY-a`); metin kaynağı `013_plan_catalog_2026_10.sql:73-87`. Kodda karşılık **yok** (`api/src`, `api/functions`, `api/admin`, `web/src` aramasında destek önceliği, VIP, erken erişim işlevi bulunamadı) | **Yalnızca metin** — işlevsiz iddia (N-44 sınıfı). Müşteriye sorulacak; karşılığı yoksa kaldırılmalı ya da tanımlanmalı. |
| P6 | "Yayına alma ve yayından kaldırma hakkı" 1/3/5/20 | FARKLI YORUMLANDI | GK-4: "yayından kaldırma hakkı" = özel yapma sayacı (1/3/5/20); "yayına alma" **sayılmıyor**, ayrıca herkese açık bot limitiyle (2/5/10/∞) sınırlı. GK-17: haklar toplam, aylık yenilenmez | Doküman tek sayı veriyor; biz yalnızca kaldırmayı sayıyoruz. Müşteriye sorulacak. |
| P7 | Ücretsiz → paket yükseltme metinleri tutarlı | DOĞRULANAMADI | Ayarlar'da hâlâ "Gelişmiş özellikler, yüksek limitler ve öncelikli destek için **Pro** plana geçiş yapın." (`settings/page.jsx:806`); "Pro" adlı paket yok | Dokümanda bu ekranla ilgili ifade bulunamadı (PDF yok); metin tutarsızlığı. |
| G | Genel hedef: bireysel satıcılık hiçbir yoldan mümkün değil | KISMEN | bkz. madde 1 / genel hedef satırı | Bugün fiilen mümkün değil; kuralla engellenmiyor. |

## Canlıda oluşturulan ve geri alınanlar

| Ne | Hesap | Durum |
|---|---|---|
| Bot #36 (bağımsız), #37, #38 (herkese açık) — limit denemesi | A | Silindi (deletechatbot 200). Sohbet açılmadı, artık kayıt kalmadı. Herkese açık ikisi ~1 dk Keşfet'te göründü. |
| Pazaryeri başvurusu (`account_type: bireysel`, geçersiz veri) | B | Sunucu 400 ile reddetti; `application_status` → `application: null` (kayıt yok). |
| Ücretli bot #27 sohbet sayfası ziyareti | B | Karşılama kaydı denemesi sunucuda 403 ile reddedildi (N-52) — yazma yok. |

Önceki turlardan kalan (14 numaralı kontrol, 2026-10-15): B'nin yorumu #15 (yorum silme ucu yok) ve silinmiş bot #35'e ait iki sohbet (öksüz).

## (1) Düzeltilmesi gereken kod işleri (henüz yapılmadı)

| # | İş | Önerilen çözüm | Büyüklük |
|---|---|---|---|
| 1 | **N-51** eski bireysel satıcı API'si | `SellerController::register/update/resubmit` yalnızca Tip 2/3 (şahıs şirketi / kurumsal) kabul etsin; `saveBankInfo` `account_type`'ı beyaz listeyle sınırlasın. Çağıranı olmadığı için alternatif: uçları `marketplace_refund` gibi **410** yapmak. Birden fazla uç + API sözleşmesi → önce diff, onay. | S (yarım gün, test dahil) |
| 2 | **N-53** yayındaki ücretsiz bot "Yayında Değil" | `chatbots/page.jsx:178-184`: `is_independent=0` ve fiyatsız → "Yayında"; satıcı durumu yalnızca ücretli bot için. Aynı mantık "Aktif & Çalışır" sayacında da. | XS (1-2 saat) |
| 3 | **N-52** erişimsiz ücretli bot sohbet sayfası | Erişim yoksa mesaj kutusu yerine "Bu bot ücretli — erişim için Sepete Ekle" kilitli durumu; karşılama kaydını erişim varken yap (403 denemesi kalksın). | S |
| 4 | N-50b karşılama kayıt sırası | Karşılamayı yalnızca geçmiş boşken ve kullanıcı mesajından önce, beklenerek kaydet (ya da sunucu kaydetsin). | S |
| 5 | Ayarlar'daki "Pro plan" metni | "Pro" → paket adları / "Paketleri incele". | XS |

## (2) Müşteriye sorulacak yorum farkları

1. **Sınırsız herkese açık bot hakkı kime?** Biz bunu yalnızca ödeme altyapısı tamamlanmış ve aktifleşmiş satıcıya verdik. Başvurusu onaylanan herkes mi almalı? (Bugün kimse alamıyor.)
2. **"Yayına alma ve yayından kaldırma hakkı" tek sayı mı?** Biz yalnızca "yayından kaldırma / özel yapma"yı sayıyoruz (1/3/5/20). Yayına almayı herkese açık bot sayısı sınırlıyor (2/5/10/sınırsız).
3. **Bu haklar her ay yenileniyor mu?** Biz toplam saydık; paket yenilense de sıfırlanmıyor.
4. **"149 ₺/ay" otomatik yenilenen bir abonelik mi?** Biz 30 günlük tek seferlik satış yaptık; süre bitince ücretsiz pakete dönülüyor, 3 gün önce hatırlatma gidiyor.
5. **Paket kartlarındaki "Öncelikli destek", "7/24 VIP destek", "Yeni özelliklere erken erişim", "Diğer tüm özelliklerde sınırsız deneyim", "Tüm Lumanoris deneyimlerine erişim" ne anlama geliyor?** Sitede bunların karşılığı olan bir işlev yok. Karşılığını tanımlayalım mı, yoksa kartlardan kaldıralım mı?
6. **Başkasının ücretli botunda "Sepete Ekle" kalsın mı?** "Satın Al" kaldırıldı; satın alma yolu olarak sepet duruyor.
7. **Ayarlar'daki sekmenin adı "Ödeme Bilgileri" — "Banka" yerine bu ad uygun mu?**
8. **Hiç bot takip etmeyen kullanıcıda ana sayfada Lumanoris AI varsayılan olarak seçili geliyor — uygun mu?**
9. **Satın Aldıklarım, süresi dolmuş satın alımı olan kullanıcıda da görünsün mü?** (Biz gösteriyoruz.)
10. **Belge eğitimi yalnızca PDF ve en fazla 5 MB — yeterli mi?** (Görsel/taranmış belge okunmuyor.)
11. Orijinal istek dokümanı (PDF) projede yok — metin karşılaştırmasını tamamlamak için paylaşabilir misiniz?
