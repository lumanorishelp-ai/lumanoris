# Abonelik yönetimi: onay bekleyen değişiklikler (B3, 2026-10-18)

Yapılan (N-63, onay gerektirmeyen kısım): "Aboneliğim" kartı, ödeme geçmişi, aktif ücretli paketin düğmesinin kapanması. Hepsi tek kaynaktan: `functions/plans.php → getUserSubscription`.

Aşağıdakiler **ödeme yoluna** (`upgradePlan`, `finalizeHostedPlanPayment`) ya da **şemaya** dokunuyor. CLAUDE.md gereği onay olmadan uygulanmadı.

---

## 1. Bugünkü sunucu davranışı (kanıt: `WalletController.php`)

| Durum | Bugün ne oluyor | Sorun |
|---|---|---|
| Aktif Gümüş, tekrar Gümüş | Ödeme sayfası açılır; ödenince bitiş `NOW()+30` | Kalan günler yanar (25 gün kalmışken alırsa toplam 30 olur, 55 değil). Müşteri bu durumda satın almanın tamamen engellenmesini istiyor. |
| Aktif Gümüş, Altın'a geçiş | Ödeme açılır; ödenince Altın, bitiş `NOW()+30` | Gümüş'ün kalan günleri yanar. Kural belirsiz (iş kararı). |
| Aktif Altın, Gümüş'e geçiş | Ödeme açılır; ödenince Gümüş, bitiş `NOW()+30` | Altın'ın kalan günleri yanar. |
| Aktif ücretli, "Ücretsiz" seçimi | Sunucu satırı **hemen** Ücretsiz yapar (arayüzde düğme kapalı, ama uç açık) | Kalan günler yanar; tek istekle geri alınamaz veri kaybı. |
| İptal | Yok | Saklanacak alan yok. |

## 2. Önerilen kurallar (müşteri onayına bağlı; soru listesi: `docs/musteri/sorular-2026-10-18.md` 3 ve 4)

1. **Aynı paket:** Aktif ücretli paket yeniden satın alınamaz. Yalnızca bitişe **7 gün** ya da daha az kala "Yenile" açılır. Yenilemede yeni bitiş `GREATEST(NOW(), mevcut_bitiş) + 30 gün` olur; kalan günler korunur.
2. **Üst paket (önerimiz, soru 4 seçenek 1):** Hemen başlar, 30 gün yeniden sayılır, eski paketin kalan günleri yanar. Ödeme penceresinde bu açıkça yazılır: "Gümüş paketinizin kalan 25 günü sona erecek."
3. **Alt paket ve Ücretsiz:** Aktif ücretli paket bitene kadar seçilemez. Mesaj: "Paketiniz {bitiş} tarihinde bitince seçebilirsiniz."
4. **İptal (varsayılan "süre sonunda biter"):** Paket bitişe kadar çalışır; 3 gün kala gönderilen "yenileme hatırlatması" gönderilmez; Aboneliğim'de "İptal edildi · {bitiş} tarihinde sona erecek" ve "İptali geri al" görünür.
5. **İptal ("hemen bitsin + iade talebi"), müşteri seçerse:** Bitiş `NOW()` olur ve admin'e bir iade talebi düşer. İade tutarı ve iadenin yapılması admin'in kararıdır; Hoppa iade akışı (`admin/ajax/odemeler.php`) değişmez.

## 3. Teknik taslak

### 3.1 Şema: `api/database/migrations/019_plan_cancellation.sql` (yıkıcı değil)

```sql
ALTER TABLE user_plan_selection
  ADD COLUMN cancel_requested_at DATETIME NULL DEFAULT NULL AFTER expires_at,
  ADD COLUMN cancel_mode VARCHAR(20) NULL DEFAULT NULL AFTER cancel_requested_at;
```

- `phpmyadmin/019_plan_cancellation.sql` aynı içerikle hazırlanır.
- Geri alma: `ALTER TABLE user_plan_selection DROP COLUMN cancel_mode, DROP COLUMN cancel_requested_at;` (ayrı, onaylı ters migration).
- Yeni bir paket satın alındığında bu iki alan `NULL`'a döner (`planSelectionRow`).

### 3.2 Ödeme yolu: `WalletController`

- `upgradePlan`, Hoppa'ya gitmeden önce `getUserSubscription` ile şu kontrolleri yapar. Hepsi 409 döner, ödeme oturumu açılmaz.
  - Kural 1, aynı paket: yenileme penceresi dışında reddedilir.
  - Kural 3, alt paket ve Ücretsiz: aktif ücretli paket bitene kadar reddedilir.
  - Sunucu engeli istemcideki kapalı düğmeden bağımsız çalışır.
- `finalizeHostedPlanPayment`: aynı paketse `expiresAt = GREATEST(NOW(), mevcut) + INTERVAL 30 DAY`.
  - Bu kesinleştirme transaction'ı içinde, satır kilitlenerek (`SELECT … FOR UPDATE`) yapılır.

### 3.3 Yeni uç: `POST /api/wallet/cancelsubscription.php`

- `{mode: "end_of_term" | "immediate"}`; yalnızca kendi paketi; oran sınırı.
- `undo` için `{mode: "undo"}`.
- `planRenewalReminders` sorgusuna `AND ups.cancel_requested_at IS NULL` eklenir.

### 3.4 Testler

`subscription_selftest`'e eklenecekler:
- Aynı paket reddi ve yenileme penceresi.
- Erken yenilemede gün korunması.
- Alt paket ve Ücretsiz reddi.
- İptal sonrası hatırlatma gitmemesi.
- İptali geri alma.

`hoppa_selftest` B'ye eklenecek: sahte gateway'le yenileme kesinleştirmesi.

## 4. İptalin iki anlamı: kısa yasal not

Kaynak: Mesafeli Sözleşmeler Yönetmeliği (RG 27.11.2014, 29188). Kesin karar öncesinde avukat görüşü alınması önerilir.

- **Cayma hakkı:** Tüketicinin 14 günlük cayma hakkı vardır (md. 9). Ancak "elektronik ortamda anında ifa edilen hizmetler" (md. 15/1-ğ) ve "cayma süresi bitmeden tüketicinin onayıyla ifasına başlanan hizmetler" (md. 15/1-h) için cayma hakkı kullanılamaz.
  - Bunun için tüketicinin satın almadan önce bu konuda açıkça bilgilendirilmesi ve onayının alınması gerekir. Sitedeki satış sözleşmesi ve ödeme penceresindeki onay metni buna göre olmalı.
- **Seçenek 1, süre sonunda biter:** Paket anında kullanıma açıldığı için ödenmiş dönem kullanılmaya devam eder; iade yapılmaz. Yalnızca dönem sonunda yenilenmeme ve hatırlatmanın kesilmesi söz konusudur.
  - Otomatik yenileme zaten olmadığından bu, "dönem sonunda iptal" uygulamasına en yakın ve en az tartışmalı seçenektir.
  - Bilgilendirme ve onay eksikse tüketici 14 gün içinde cayma talep edebilir; bu durumda iade gerekebilir.
- **Seçenek 2, hemen bitsin + iade talebi:** Kullanıcıya daha cömert bir seçenek, ama iade tutarı (tam ya da kalan güne göre) ve süresi şirketin taahhüdü olur. Her talep admin işlemi gerektirir.
  - İade Hoppa üzerinden aynı karta yapılır (mevcut akış).
