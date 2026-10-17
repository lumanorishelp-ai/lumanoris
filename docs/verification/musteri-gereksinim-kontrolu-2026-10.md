# Müşteri gereksinim kontrolü — Pazaryeri revizyonu (canlı, 2026-10-17)

**Kaynak:** müşteri dokümanı `PAZARYERİ SİSTEM DÜZENLEMESİ.pdf` (3 sayfa: madde 0–11 + Genel Hedef; "Lumanoris Üyelik Paketleri"). Dosya istenen `docs/musteri/lumanoris-pazaryeri-revizyon.pdf` yolunda **bulunamadı**; yerelde `İndirilenler/PAZARYERİ SİSTEM DÜZENLEMESİ.pdf` olarak okundu (prompt 11'in kaynak gösterdiği dosya). İlk sürümde PDF olmadığı için "birebir metin" kontrolü yapılamayan maddeler bu sürümde PDF'e göre yeniden kontrol edildi ("PDF" sütunu).

**Yöntem:** (a) kod — kuralın sunucuda mı arayüzde mi zorlandığı; (b) canlı — www.lumanoris.net, `.env.e2e-prod` A (deneme110) ve B (deneme111); ödeme yapılmadı, admin'e girilmedi, başkasının verisine yazılmadı; (c) metin — PDF ile ekran. Ekran görüntüleri git'e girmez: `docs/verification/musteri-kontrol/MK-*.png` (2026-10-17) ve `docs/verification/prod-2026-10/U-*.png` (2026-10-15).

**Sürüm 2 (aynı gün):** N-51, N-52, N-53 ve madde 2'nin onay kuralı **kodda düzeltildi** (bkz. "Bu turda düzeltilenler"). `TAMAM*` = kodda düzeltildi, testli; **canlıda** `deploy/backend-2026-10-17d.zip` + ön yüz PR'ı alınana kadar eski davranış sürer (canlıda bugün: KISMEN).

## Özet

| Durum | Sayı |
|---|---|
| TAMAM | 22 |
| TAMAM* (kodda düzeltildi, canlıya alınmayı bekliyor) | 4 |
| KISMEN | 0 |
| EKSİK | 0 |
| FARKLI YORUMLANDI | 4 |
| DOĞRULANAMADI | 0 |
| **Toplam** | **30** |

Dokümanın tüm maddeleri karşılanıyor ya da kodda düzeltildi. Kalan 4 madde müşterinin kararını bekleyen **yorum farkı**: sınırsız hakkın kapsamı (herkese açık mı, tüm botlar mı), "yayına alma ve yayından kaldırma hakkı"nın sayımı, "/ Ay"ın yenileme biçimi ve paket kartlarındaki işlevsiz özellik metinleri.

## Madde madde

| Madde | Dokümanda istenen (PDF) | Durum | Kanıt | PDF ↔ ekran metni · Not |
|---|---|---|---|---|
| 0 / M0-1 | "bir önceki toplantıda belirlenen eksiklikler" — belge (PDF) ile eğitim | TAMAM | canlı `prod-2026-10/U-02-d-pdf` (PDF → 200, "164 / 60.000 karakter") | PDF eksiklik listesini içermiyor (M0-1…4 toplantı notu, prompt 11). GK-16: yalnızca PDF, OCR yok, 5 MB. |
| 0 / M0-2 | Bağımsız bota tıklayınca sohbet | TAMAM | canlı `U-03-a-liste`, `U-03-b-cevap` | — |
| 0 / M0-3 | Liste sayfası hatasız | TAMAM | canlı `U-07-c-liste`, `U-07-e-liste-ayrinti` | — |
| 0 / M0-4 | Defter pop-up'ı → botun sohbet sayfası | TAMAM | canlı `U-08-f-mevcut-kayit-popup` → `/dashboard/chat?botId=22` | GK-15: paylaşan kişi bağlantısı da duruyor. |
| 1 | "Bireysel pazaryeri kaydı kullanım dışı bırakılacak ve ilgili alanda **"Yakında"** ibaresi gösterilecektir." | TAMAM | canlı `MK-01-03-basvuru-formu`; `pazaryeri-basvurusu/page.jsx:222`; API `account_type: bireysel` → 400 (`marketplace_application.php:56`) | **Birebir:** kaynak metin "Yakında" (ekranda CSS ile büyük harf "YAKINDA"). |
| 1 / Genel Hedef | "…**bireysel pazaryeri satıcılığı devre dışı bırakılacak**, pazaryeri sistemi şirket başvuruları üzerinden yürütülecek…" | TAMAM* | N-51: eski Param uçları artık **403** — `assertCompanySellerAllowed()` (`functions/marketplace_application.php`); `WalletController::saveBankInfo`, `SellerController::register` (+`resubmit`) | Önce yalnızca Param taslağı sayesinde kapalıydı. Yerel HTTP: `save_bank_info`, `submerchant_register`, `submerchant_resubmit` → 403. |
| 2a | "Oluştur sayfasına **"Herkese Açık Chatbot Oluştur"** kartı eklenecektir." | TAMAM | canlı `MK-02-a-olustur-kartlar`; `create/page.jsx:1347-1432` | **Birebir.** |
| 2b | "Ücretsiz planda **2 adet herkese açık** … **Bağımsız** … **1 adet**" | TAMAM | canlı (A): 2. bağımsız **422**, 3. herkese açık **422** "LIMIT_REACHED"; `MK-02-b-olustur-dolu`; `ChatbotController.php:45` | Sunucuda zorlanıyor. Oluşturulan #36–38 silindi. |
| 2c | "Pazaryeri kaydı bulunan kullanıcılara **chatbot oluşturma konusunda sınırsız hak** tanınacaktır." | FARKLI YORUMLANDI | Kural (müşteri, 2026-10-17): "kaydı bulunan" = **admin onaylı başvuru** — `getPublicBotLimit()` → `hasApprovedMarketplaceApplication()` (TAMAM*, `application_selftest` G, `plan_limits_selftest` A2). **Kapsam:** sınırsızlık yalnızca **herkese açık** botlarda (GK-6); bağımsız limit plandan | PDF botu türe ayırmadan "chatbot oluşturma" diyor → bağımsız botlar da sınırsız mı? Müşteriye sorulacak. Onay geri alınırsa mevcut botlar silinmez, yalnızca yenisi limitlenir. |
| 3a | "Pazaryeri kaydı yalnızca **şirketler** için kullanılabilir olacaktır." | TAMAM | canlı: "Pazaryeri kaydı yalnızca şirketlere açıktır."; hesap türü Şahıs Şirketi / Kurumsal | GK-8: şahıs şirketleri dahil. |
| 3b | (başvuru içeriği PDF'te tanımlı değil) | TAMAM | canlı alanlar: Ticari unvan, Vergi numarası, Vergi dairesi, MERSİS numarası, Yetkili adı/soyadı/doğum tarihi, IBAN, İl, İlçe, Açık adres | GK-7 (alan listesi geliştirici kararı). |
| 3c | "Mevcut pazaryeri kayıt ekranı kaldırılarak yerine **"Pazaryeri Başvurusu"** sayfası getirilecektir." | TAMAM* | Ekran: `BankInfo.jsx`/`SellerOnboardingWizard.jsx` yok, `/dashboard/market/` 404, eski derin bağlantı başvuruya gidiyor; **API**: eski uçlar artık onaylı şirket başvurusu ister (N-51) | **Birebir:** sayfa başlığı "Pazaryeri Başvurusu". |
| 4 | "Bakiyem sayfasına erişmek isteyen ve **henüz pazaryeri başvurusu bulunmayan** kullanıcılara: **"Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir."**" | TAMAM | canlı (B) `MK-04-bakiyem`; `wallet/page.jsx:59` | **Birebir.** Kapı "başvurusu bulunan" (gönderilmiş ya da onaylı) kullanıcıya açılıyor — PDF ile aynı ölçüt (GK-5). |
| 5 | "**Satın Aldıklarım** sayfası, kullanıcı **herhangi bir chatbot satın aldığında** aktif hale gelecektir." | TAMAM | `Sidebar.jsx:102-130` → `user_subscriptions` (chatbot satın alımı, paket aboneliği değil); canlı (B): menüde yok, doğrudan URL açılıyor | GK-12: süresi dolmuş satın alım da sayılıyor. |
| 6 | "…kullanıcının **takip ettiği chatbotlar** arasından seçim…" | TAMAM | canlı `MK-06-secici`, `U-06-d`; `dashboard/page.jsx:673` | GK-13: hiç takip yoksa Lumanoris AI varsayılan. |
| 7 | "Ayarlar sayfasındaki **Banka** sekmesi, doğrudan **Pazaryeri Başvuru** sayfasına yönlendirecektir." | TAMAM | canlı: tıklayınca `/dashboard/pazaryeri-basvurusu/` (`MK-07-ayarlar`); `settings/page.jsx:645-654` | **Metin farkı:** sekmenin adı "**Ödeme Bilgileri**", PDF "Banka". Yönlendirme doğru. |
| 8 | "Her kullanıcıya günlük **10 Luma Coin** … mevcut haliyle devam edecektir." | TAMAM | canlı "Ücretsiz 10/10 LMC"; katalog `daily_messages: 10` | — |
| 9 | "Sohbet sayfasında bulunan **"Satın Al"** butonu kaldırılacaktır." | TAMAM | canlı: kendi botu (`U-03`), başkasının ücretli botu #27 (`MK-09`) — "Satın Al" yok; `chat/page.jsx` (GK-14) | Ücretli botta "Sepete Ekle" duruyor (N-24) — sorulacak. N-52 (erişimsiz ücretli botta uyarı yoktu) kodda düzeltildi. |
| 10a | "**Yayınla:** Chatbot doğrudan herkese açık olarak yayınlanacaktır." | TAMAM | canlı `U-04` (publish 200, B Keşfet'te "Ücretsiz" rozetiyle) | **Birebir** düğme adı "Yayınla". |
| 10b | "**Pazaryerine Kaydet:** Kullanıcıya pazaryeri başvurusu yapması gerektiğini belirten bir **pop-up** ekranı gösterilecektir." | TAMAM | canlı `U-04-a`, `U-04-b`; `PublishModal.jsx:109-139` | **Birebir** düğme adı "Pazaryerine Kaydet". |
| 10c | (sonuç) yayınlanan bot doğru durumda görünmeli | TAMAM* | N-53: fiyatsız herkese açık bot artık "Yayında" (`chatbots/page.jsx`), "Aktif & Çalışır" sayıyor | Canlıda bugün "Yayında Değil" (`U-05-c`). |
| 11a | "Ücretsiz planda kullanıcı, oluşturduğu **1 bağımsız chatbotu herkese açık olarak yayınladıktan sonra**, istediği bir chatbotu kendisine özel hale getirebilecektir." | TAMAM | canlı (2026-10-15): 1. özel yap 200, 2. deneme **422** + ekranda uyarı (`U-05-a…c`); `chatbot_limits.php` | Sunucuda zorlanıyor. |
| 11b | "Bu özel chatbot sınırı, üst paketlerde paket özelliklerine bağlı olarak artırılacaktır." | TAMAM | katalog `privacy_rights` 3 / 5 / 20; `getPrivacyRightLimit()` plandan | Canlıda ücretli hesap yok (ödeme kapalı); `plan_limits_selftest`. |
| P1 | Paket tablosu (PDF s. 3: Gümüş 149 ₺ · 50 coin · 3 / 5 · 3 hak; Altın 299 ₺ · 100 · 5 / 10 · 5; Elmas 849 ₺ · 200 · Sınırsız / Sınırsız · 20) ↔ veritabanı kataloğu | TAMAM | canlı `getpricing.php` — birebir | PDF'te ücretsiz paket satırı yok; ücretsiz değerler madde 2/8/11'den (0 ₺ · 10 · 1 / 2 · 1) — katalogla aynı. |
| P2 | ↔ sunucu limit kontrolleri | TAMAM | `getIndependentBotLimit` / `getPublicBotLimit` / `getPrivacyRightLimit` / coin plandan; Elmas NULL = sınırsız | Ücretli planlar canlıda denenmedi. N-49 (paket okuması) canlıya alınmış olmalı. |
| P3 | ↔ fiyat sayfası metni | TAMAM | canlı `PAY-a-yukselt` / `getpricing` | **Metin farkları:** PDF "Chatbotları yayına alma ve yayından kaldırma için 3 hak" ↔ ekran "3 yayından kaldırma (özel yapma) hakkı" (bkz. P6); PDF "3 Bağımsız Chatbot oluşturma hakkı" ↔ ekran "3 bağımsız chatbot"; PDF "Günlük 50 Luma Coin" birebir. |
| P4 | "**149 ₺ / Ay**" | FARKLI YORUMLANDI | `WalletController.php:760-766` (`NOW() + 30 gün`), `plans.php:144-151` (süresi dolan → ücretsiz), `hosted_plan_payments.php:119` (3 gün önce hatırlatma) | 30 günlük **tek seferlik** satış, otomatik yenileme yok (D-05, GK-27); ekranda "/Aylık · 30 gün geçerlidir ve otomatik yenilenmez". Müşteri kararı bekliyor — bu turda dokunulmadı. |
| P5 | "Diğer tüm özelliklerde sınırsız deneyim" (Gümüş), "Öncelikli destek", "Tüm Lumanoris deneyimlerine erişim" (Altın), "7/24 VIP destek", "Yeni özelliklere erken erişim" (Elmas) | FARKLI YORUMLANDI | Ekranda **birebir** var (`013_plan_catalog_2026_10.sql:73-87`); kodda karşılık **yok** | Yalnızca metin (N-44 sınıfı) — müşteriye sorulacak. |
| P6 | "Chatbotları **yayına alma ve yayından kaldırma için** 3 / 5 / 20 hak" | FARKLI YORUMLANDI | GK-4: yalnızca **yayından kaldırma (özel yapma)** sayılıyor (3/5/20); yayına alma sayılmıyor, herkese açık bot limitiyle (5/10/sınırsız) sınırlı. GK-17: toplam, aylık yenilenmez | PDF tek sayı veriyor. Müşteri kararı bekliyor — bu turda dokunulmadı. |
| G | Genel Hedef: "…ücretsiz/bağımsız chatbot oluşturma ile herkese açık yayınlama süreçleri birbirinden ayrıştırılacaktır." | TAMAM* | Bağımsız ve herkese açık limitler ayrı (2b), Yayınla / Pazaryerine Kaydet ayrı (10), bireysel satıcılık kapalı (N-51) | — |

**Doküman dışı not:** Ayarlar'da hâlâ "Gelişmiş özellikler, yüksek limitler ve öncelikli destek için **Pro** plana geçiş yapın." (`settings/page.jsx:806`) — "Pro" adlı paket yok. PDF'te bu ekranla ilgili ifade yok; metin düzeltmesi önerilir.

## Bu turda düzeltilenler (kodda; canlıya `backend-2026-10-17d.zip` + ön yüz PR'ı ile)

| ID | Ne | Test |
|---|---|---|
| N-51 | Eski satıcı/banka uçları onaylı şirket başvurusu ve şirket hesap türü ister (403). Uçlar: `api/wallet/save_bank_info.php`, `api/seller/submerchant_register.php`, `api/seller/submerchant_resubmit.php` (`update`/`delete`/`list` zaten admin; `marketplace_refund` 410; `marketplace_reconcile` gizli anahtarlı). | `application_selftest` F; yerel HTTP 403 ×3 |
| Madde 2 | Sınırsız herkese açık hak = admin onaylı başvuru (aktif Param satıcısı şartı kaldırıldı). Onay geri alınırsa yalnızca yeni oluşturma/yayınlama sınırlanır. | `application_selftest` G, `plan_limits_selftest` A2 |
| N-52 | `getchatbot` → `can_chat`; erişimsiz ücretli botta mesaj kutusu yerine uyarı, karşılama kaydı denenmez. | `application_selftest` H; yerel HTTP `can_chat` #27 false / #5 true |
| N-53 | Fiyatsız herkese açık bot "Yayında"; "Aktif & Çalışır" sayacı. | `application_selftest` H |

## Canlıda oluşturulan ve geri alınanlar

| Ne | Hesap | Durum |
|---|---|---|
| Bot #36 (bağımsız), #37, #38 (herkese açık) — limit denemesi | A | Silindi; sohbet açılmadı, artık kayıt yok. Herkese açık ikisi ~1 dk Keşfet'te göründü. |
| Pazaryeri başvurusu (`bireysel`, geçersiz veri) | B | Sunucu 400; kayıt oluşmadı. |
| Ücretli bot #27 sohbet sayfası ziyareti | B | Karşılama kaydı denemesi 403 (yazma yok). |

Önceki turdan (2026-10-15) kalan: B'nin yorumu #15 (yorum silme ucu yok) ve silinmiş bot #35'e ait iki öksüz sohbet.

## (1) Kalan kod işleri (henüz yapılmadı)

| # | İş | Önerilen çözüm | Büyüklük |
|---|---|---|---|
| 1 | Ayarlar'daki "Pro plan" metni | "Pro" → "Paketleri incele" / paket adları. | XS |
| 2 | N-50b karşılama kayıt sırası | Karşılamayı yalnızca geçmiş boşken, kullanıcı mesajından önce ve beklenerek kaydet (ya da sunucu kaydetsin). | S |
| 3 | Müşteri cevabına bağlı | P4 (/ay yenileme), P6 (yayına alma sayımı), 2c (sınırsızlık kapsamı), P5 (özellik metinleri) — karar gelince. | S–M |

## (2) Müşteriye sorulacak yorum farkları

1. **Sınırsız hak hangi botlar için?** Dokümanda "pazaryeri kaydı bulunan kullanıcılara chatbot oluşturma konusunda sınırsız hak" yazıyor. Şu an onaylı başvurusu olan kullanıcı **herkese açık** botları sınırsız açabiliyor; **bağımsız (özel)** botları paket limitinde kalıyor. Bağımsız botlar da sınırsız olsun mu?
2. **"Yayına alma ve yayından kaldırma için 3 hak" tek bir sayı mı?** Biz yalnızca "yayından kaldırma / özel yapma"yı sayıyoruz (3/5/20). Yayına almayı herkese açık bot sayısı sınırlıyor (5/10/sınırsız).
3. **Bu haklar her ay yenileniyor mu?** Biz toplam saydık.
4. **"149 ₺ / Ay" otomatik yenilenen bir abonelik mi?** Biz 30 günlük tek seferlik satış yaptık; süre bitince ücretsiz pakete dönülüyor, 3 gün önce hatırlatma gidiyor.
5. **Paket kartlarındaki "Öncelikli destek", "7/24 VIP destek", "Yeni özelliklere erken erişim", "Diğer tüm özelliklerde sınırsız deneyim", "Tüm Lumanoris deneyimlerine erişim" ne anlama geliyor?** Sitede karşılığı olan bir işlev yok. Tanımlayalım mı, kaldıralım mı?
6. **Başkasının ücretli botunda "Sepete Ekle" kalsın mı?** "Satın Al" kaldırıldı; satın alma yolu olarak sepet duruyor.
7. **Ayarlar'daki sekmenin adı "Ödeme Bilgileri" — dokümandaki "Banka" yerine bu ad uygun mu?**
8. **Hiç bot takip etmeyen kullanıcıda ana sayfada Lumanoris AI varsayılan olarak seçili geliyor — uygun mu?**
9. **Satın Aldıklarım, süresi dolmuş satın alımı olan kullanıcıda da görünsün mü?** (Biz gösteriyoruz.)
10. **Belge eğitimi yalnızca PDF ve en fazla 5 MB — yeterli mi?**
