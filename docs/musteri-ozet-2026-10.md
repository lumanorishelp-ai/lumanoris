# Lumanoris — Pazaryeri Revizesi Özeti (Ekim 2026)

## A) Yapılanlar

**Madde 0 — Önceki toplantıdan kalanlar**
- Belge ile eğitim: Bot henüz kaydedilmeden belge yüklenince çıkan hata giderildi. Bot ilk kaydedildiğinde aynı ekranda kalınıyor ve Bilgi Bankası hemen açılıyor. PDF yüklenebiliyor (en fazla 5 MB).
- Bağımsız bota tıklayınca artık o botun sohbet sayfası açılıyor.
- Liste sayfası: Bir hata olduğunda artık yanıltıcı "liste bulunamadı" ekranı gösterilmiyor. Hatanın asıl kaynağını canlı sunucuda kontrol edeceğiz.
- Diyalog defterindeki buton artık diyaloğun ait olduğu botun sohbet sayfasına götürüyor.

**Madde 1** — Bireysel pazaryeri kaydı kapatıldı; ilgili alanda "Yakında" yazıyor.

**Madde 2** — Oluştur sayfasına "Herkese Açık Chatbot Oluştur" kartı eklendi. Ücretsiz planda 2 herkese açık ve 1 bağımsız bot hakkı var.

**Madde 3** — Eski kayıt ekranının yerine "Pazaryeri Başvurusu" sayfası geldi; yalnızca şirketler başvurabiliyor. Başvurular yönetim panelindeki "Pazaryeri Başvuruları" ekranından inceleniyor ve sonuç kullanıcıya bildirim olarak gidiyor.

**Madde 4** — Başvurusu olmayan kullanıcı Bakiyem'de "Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir." mesajını görüyor.

**Madde 5** — "Satın Aldıklarım" menüsü yalnızca en az bir bot satın almış kullanıcıya görünüyor.

**Madde 6** — Ana sayfadaki bot seçimi artık takip edilen botlardan yapılıyor.

**Madde 7** — Ayarlar'daki banka sekmesi doğrudan Pazaryeri Başvurusu sayfasına götürüyor.

**Madde 8** — Günlük 10 Luma Coin değişmedi; kontrol edildi.

**Madde 9** — Sohbet sayfasındaki "Satın Al" butonları kaldırıldı. Mesaj hakkı bitince "Paketini yükselt" çıkıyor.

**Madde 10** — Bağımsız bot yayınlanırken iki seçenek var:
- **Yayınla:** Bot herkese açılır.
- **Pazaryerine Kaydet:** Başvuru gerektiğini anlatıp başvuru sayfasına götürür.

**Madde 11** — "Kendine özel yap" hakkı eklendi. Ücretsiz planda 1 hak var; paketlerde hak sayısı artıyor.

**Paketler**

| Paket | Fiyat | Günlük coin | Bağımsız | Herkese açık | Özel yapma hakkı |
|---|---|---|---|---|---|
| Ücretsiz | 0 ₺ | 10 | 1 | 2 | 1 |
| Gümüş | 149 ₺/ay | 50 | 3 | 5 | 3 |
| Altın | 299 ₺/ay | 100 | 5 | 10 | 5 |
| Elmas | 849 ₺/ay | 200 | Sınırsız | Sınırsız | 20 |

Ana sayfadaki fiyatlar da artık bu tabloyla aynı.

## B) Onayınızı bekleyen varsayımlarımız

Aşağıdaki konularda net bir talimat olmadığı için biz karar verdik. Farklı isterseniz söyleyin, değiştirelim.

1. Bağımsız bot yayınlandığında artık herkese açık bot hakkından sayılıyor.
2. Herkese açık botlarla herkes ücretsiz sohbet edebiliyor; mesajlar kullanıcının günlük Luma Coin'inden düşüyor.
3. Henüz yayınlanmamış bot hak harcamadan özel kalıyor. Özel yapma hakkı yalnızca yayınlanmış bir botu geri çekerken kullanılıyor.
4. Özel yapma hakları toplam sayılıyor; her ay yenilenmiyor.
5. Paketlerdeki "yayından kaldırma hakkı"nı özel yapma hakkı, "yayına alma hakkı"nı ise herkese açık bot sayısı olarak yorumladık.
6. Bakiyem, başvurusu gönderilmiş ya da incelenmiş kullanıcıya açılıyor; reddedilen başvuru açmıyor.
7. Pazaryeri kaydı onaylı şirketlerin sınırsız hakkı yalnızca herkese açık botlar için geçerli.
8. Başvuruda unvan, vergi numarası ve vergi dairesi, MERSİS numarası, yetkili bilgileri, IBAN ve adres isteniyor; belge yükleme yok.
9. Şahıs şirketleri de başvurabiliyor; onlar için MERSİS numarası isteğe bağlı.
10. Başvurunun incelenmesi, ücretli satışı tek başına açmıyor (bkz. C bölümü).
11. İncelenmiş başvuruyu kullanıcı değiştiremiyor; değişiklik için destekle iletişime geçmesi gerekiyor.
12. Başvuru incelendiğinde başvurudaki IBAN, kullanıcının para çekme IBAN'ı olarak kaydediliyor. Bekleyen bir para çekme talebi varsa bu talep sonuçlanana kadar IBAN değiştirilmiyor.
13. Daha önce bireysel olarak kayıt olmuş satıcıların bilgilerine dokunulmadı.
14. Paketi biten kullanıcının mevcut botları silinmiyor; yalnızca yenisi açılamıyor.
15. Ana sayfa seçicisinde satın alınmamış ücretli botlar kilitli görünüyor. Hiç bot takip etmeyen kullanıcıya Lumanoris AI sunuluyor.
16. Lumanoris AI herkese ücretsiz açıldı.
17. Diyalog defterinde, diyaloğu paylaşan kişinin profiline giden bağlantı da küçük olarak duruyor.
18. Belge ile eğitimde yalnızca PDF kabul ediliyor (en fazla 5 MB). Taranmış, yani fotoğraf hâlindeki belgelerden metin okunamıyor.
19. 750 ₺'lik ayrı "Üretici" paketi, yeni paketlerle çakıştığı için kaldırıldı.
20. Ana sayfadaki "yıllık ödeme / %20 indirim" seçeneği, sistemde yıllık satış bulunmadığı için kaldırıldı.

Sorularımız:

- Kullanıcılarınıza 30 gün para iade garantisi veriyor musunuz?
- Hoppa komisyonu müşteriye mi yansısın, sizin tarafınızdan mı karşılansın? Önerimiz sizin karşılamanız; aksi hâlde ekranda gösterilen fiyatla çekilen tutar farklı olur.
- Ödeme için alıcıdan adres bilgisi toplamamız gerekiyor mu?
- Ana sayfadaki "Bakiyeni Çek" tanıtımında "tek tıkla hesabınıza güvenle hızlıca aktarın" yazıyor. Bugün süreç şöyle: satıcı çekim talebini tek tıkla gönderiyor, ama parayı ekibiniz talebi panelden onaylayıp banka havalesiyle elle gönderiyor; otomatik ve anlık bir aktarım yok. Bu metin kalsın mı, yoksa süreci anlatan bir metinle değiştirelim mi (ör. "Çekim talebinizi tek tıkla oluşturun; ekibimiz onayladıktan sonra tutar banka hesabınıza gönderilir")? Siz karar verene kadar metne dokunmuyoruz.

## C) Ödeme altyapısı: Hoppa

Ödeme altyapısı **Hoppa** (Elekse Elektronik Para ve Ödeme Kuruluşu A.Ş.) ile kurulacak. Aşağıdakilerin ekranları ve kuralları hazır; Hoppa entegrasyonu tamamlandıktan sonra açılacaklar:

- **Pazaryerinde ücretli satış:** Botlara fiyat verilmesi ve satın alma.
- **Paket satın alma:** Gümüş, Altın ve Elmas paketlerinin ödemesi.
- **Satıcı ödemeleri:** Satıcıların kazançlarının kendilerine aktarılması.
- **Satın Aldıklarım:** Satın alma yapılabildiğinde bu menü kullanıcılarda görünmeye başlayacak.
- **Onaylı satıcılara sınırsız herkese açık bot hakkı:** Satıcı onayı da Hoppa üzerinden yapılacak.

Bu süre içinde gerçek ödeme alınmıyor; kimseden yanlışlıkla para çekilmiyor.

**Entegrasyona başlayabilmemiz için sizden gerekenler:**

1. **Hoppa ile işyeri sözleşmesi.** Pazaryeri yapısı için satıcı paylarının doğrudan satıcılara aktarıldığı (alt üye işyeri) bir ürün olması önemli.
2. **API bilgileri.** Hoppa'nın teknik dokümanı ve canlı ortam erişim bilgileri.
3. **Test ortamı erişimi.** Test hesabı ve test kartları; canlıya geçmeden önce tüm ödeme ve iade adımlarını burada deneyeceğiz.

Hoppa ile görüşmede iletebilmeniz için teknik soruların listesini ayrıca hazırladık; isterseniz paylaşırız.
