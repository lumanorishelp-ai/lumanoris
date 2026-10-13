# 12 — Uçtan uca doğrulama (canli-2026-10b)

Amaç: `canli-2026-10b` etiketinin, canlıya alınmadan önce **gerçek bir tarayıcıda, gerçek kullanıcı akışlarıyla** çalıştığını kanıtlamak. Şimdiye kadarki doğrulama yalnızca selftest/lint/build idi; hiçbir ekran tarayıcıda açılmadı.

`CLAUDE.md` kuralları geçerli. Bu görevde **kod düzeltmesi yapma**: bulduğun her sorunu `AUDIT.md`'ye yeni N ID'siyle kaydet, senaryoyu BAŞARISIZ işaretle ve devam et. Düzeltmeye sonra birlikte karar vereceğiz.

---

## Ortam

- `canli-2026-10b` etiketini ayrı bir worktree'de aç; ana çalışma ağacına dokunma.
- Yalnızca **yerel** veritabanı. Başlamadan `DB_HOST`'un yerel olduğunu doğrula ve `migrate.php --status` çıktısını rapora koy. Canlı/uzak hiçbir şeye bağlanma.
- `PAYMENT_PROVIDER=none` (canlıdaki gibi). Hoppa ödeme akışı yalnızca S-12'de, o senaryo için geçici olarak `hoppa` + herkese açık TEST1234 anahtarlarıyla; senaryo bitince `none`'a geri al.
- Tarayıcı: Hoppa e2e'de kullandığın `playwright-core`. Repoya bağımlılık ekleme.
- Gemini: yerel anahtar varsa S-02 için en fazla 3 gerçek mesaj gönderilebilir. Yoksa (B4) isteğin modele giden yükünü göster, "cevap üretildi" deme.
- Ekran görüntüleri: `docs/verification/2026-10/` altına, senaryo ID'siyle adlandır. Bu klasörü commit'leme (`.gitignore`'a ekle); yalnızca rapor commit'lenir.
- Her senaryoda tarayıcı konsolundaki hataları ve 4xx/5xx ağ yanıtlarını kaydet. Beklenen 4xx'ler (ör. yetkisiz erişim denemesi) senaryoda belirtilmiştir; diğerleri bulgudur.

## Test kullanıcıları ve temizlik

- Kullanıcıları **uygulamanın kendi kayıt akışıyla** oluştur: `e2e-a@…` (bot sahibi), `e2e-b@…` (ikinci kullanıcı). Admin için mevcut yerel admin hesabı.
- Oluşturduğun her kaydın (kullanıcı, bot, başvuru, liste, defter kaydı, sipariş) ID'sini not et.
- Bitince bunları uygulamanın kendi silme uçlarıyla temizle; uç yoksa silme yapma, kalanları raporda listele. `UPDATE/DELETE` içeren elle SQL yazma.

---

## Senaryolar

Her senaryo için: adımlar → beklenen → **sonuç (GEÇTİ / BAŞARISIZ / ATLANDI + neden)** → ekran görüntüsü yolu.

**S-01 · Bot oluşturma ve belge ile eğitim (M0-1, 2)**
e2e-a ile yeni bağımsız bot oluştur. Kaydetmeden Bilgi Bankası sekmesi "önce kaydedin" demeli. Kaydet → aynı ekranda kalmalı, adres `?id=N` olmalı, sekme açılmalı. İçinde ayırt edici bir cümle bulunan küçük bir PDF yükle (ör. "Şirketin kuruluş yılı 1987'dir"). 5 MB'tan büyük bir PDF reddedilmeli ve anlaşılır mesaj göstermeli. Oluştur sayfasında gerçek kullanım/limit metni görünmeli; Üretici kartı olmamalı.

**S-02 · Karttan sohbete ve eğitimin kullanılması (M0-2)**
Chatbotlarım'da kartın görseline/başlığına tıkla → sohbet açılmalı. "Şirket hangi yıl kuruldu?" sor → 1987 (veya B4 ise yükte eğitim metni). Sohbet sayfasında hiç "Satın Al" olmamalı (madde 9).

**S-03 · Yayınlama ve ücretsiz herkese açık bot (madde 10, 2)**
e2e-a: botu "Yayınla". e2e-b: vitrinde/keşfette "Ücretsiz" rozetiyle görmeli, sohbet açılmalı, "Sepete Ekle" olmamalı. e2e-b olarak `getchatbot` yanıtında persona (`style_prompt`) ve eğitim metni **bulunmamalı** (ağ yanıtını kaydet). Oluştur sayfasında "Herkese Açık Chatbot Oluştur" kartı çalışmalı.

**S-04 · Pazaryerine Kaydet (madde 10, 1)**
PublishModal'da "Pazaryerine Kaydet" → açıklama pop-up'ı → başvuru sayfası. Bireysel seçenek "Yakında". Eski satıcı sihirbazı hiçbir yerden açılmamalı.

**S-05 · Özel yap ve hak sayımı (madde 11)**
e2e-a: yayınlanmış botta "Özel Yap" → onay → bot e2e-b'den kaybolmalı; e2e-b doğrudan sohbet adresine gidince erişememeli (beklenen 403/404). İkinci bir botta tekrar "Özel Yap" → ücretsiz planda hakkın bittiği söylenmeli.

**S-06 · Ana sayfa (madde 6, N-14)**
e2e-b: Lumanoris AI ile sohbet açılmalı. Bot seçicide takip edilen botlar; erişimi olmayan bot kilitli ve "Profili gör". Hiç takip yokken boş durum + Keşfet linki. Sayfada uydurma puan/takipçi olmamalı (N-15).

**S-07 · Paketler ve fiyatlar (P, N-10)**
Ana sayfa ve yükseltme sayfası: 149 / 299 / 849 ₺, özellik metinleri AUDIT'teki paket tablosuyla aynı. Ücretli pakette "Ödeme altyapısı hazırlanıyor"; kart formu yok; hiçbir istek kart alanı içermemeli (ağ kaydı).

**S-08 · Bakiyem ve Ödeme Bilgileri (madde 4, 7)**
e2e-b (başvurusuz): Bakiyem'de müşteri metni ve başvuru linki; kenar çubuğu ve başlıkta bakiye tutarı yok. Ayarlar → Ödeme Bilgileri → başvuru sayfası.

**S-09 · Pazaryeri başvurusu ve admin onayı (madde 3, Faz 5)**
e2e-a: başvuru formunu hatalı alanlarla gönder → alan bazında hata. Geçerli test verisiyle gönder → "alındı, inceleniyor"; Bakiyem açılmalı. Admin: Başvurular sayfasında görünmeli; IBAN'lar yan yana; "İncelendi" → e2e-a'ya uygulama içi bildirim; satıcı **aktif olmamalı**; admin log'da kayıt. Durum ucunun yanıtında tam IBAN/doğum tarihi/vergi no olmamalı.

**S-10 · Satın Aldıklarım (madde 5)**
e2e-b'de menü öğesi görünmemeli.

**S-11 · Liste ve Diyalog Defteri (M0-3, M0-4)**
Liste sayfası hatasız açılmalı; liste oluştur, bot ekle, listede görünsün. Sohbette bir diyaloğu deftere ekle → defter popup'ındaki buton **doğru botun** sohbetini açmalı; paylaşan kişi küçük link olarak.

**S-12 · Hoppa paket ödemesi ve iade (Faz 7a) — yalnızca test ortamı**
Geçici olarak `PAYMENT_PROVIDER=hoppa` (TEST1234). e2e-b ile Gümüş paketi: "Ödemeye geç" → Hoppa sayfası → başarılı test kartı → dönüşte paket tanımlı. Hata kartıyla ikinci deneme → paket değişmemeli. Admin → Paket Ödemeleri → başarılı ödemeyi iade et → paket geri alınmalı. Senaryo sonunda `PAYMENT_PROVIDER=none`'a dön ve S-07'nin ödeme kısmını tekrar kontrol et.

**S-13 · Güvenlik nokta kontrolleri**
- Oturumsuz: korumalı dashboard sayfaları girişe yönlendirmeli; korumalı API uçları 401/403.
- e2e-b, e2e-a'nın özel botunun persona/eğitim uçlarına erişememeli.
- `cron` dizini web'den 404; eski iade ucu 410.
- Admin paneli oturumsuz açılmamalı; admin AJAX uçları CSRF'siz POST'u reddetmeli.

**S-14 · Mobil genişlik**
S-01, S-03, S-07, S-09'un ana ekranlarını 390 px genişlikte aç: yatay kaydırma, taşan buton veya okunamayan metin olmamalı.

---

## Rapor

`docs/verification/2026-10-dogrulama.md`:

1. Ortam özeti: etiket, commit, `--status` çıktısı, `PAYMENT_PROVIDER`, Gemini durumu.
2. Senaryo tablosu: ID · madde · sonuç · kısa not · ekran görüntüsü.
3. Yeni bulgular (N ID'leriyle): ne oldu, nasıl yeniden üretilir, önem (P0–P3), canlıya almayı engeller mi.
4. Beklenmeyen konsol hataları ve 4xx/5xx yanıtlarının listesi.
5. Temizlik: silinenler ve kalanlar.
6. Tek satırlık hüküm: **canlıya alınabilir / şu bulgular kapanmadan alınamaz.**

Raporu commit'le ve push et (ekran görüntüleri hariç). Kod değiştirme. Bitince dur.
