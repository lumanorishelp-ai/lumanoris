# Lumanoris — Çalışma Kuralları

## Proje

Monorepo: `web/` (Next.js 15 App Router, React 19, Tailwind 3) + `api/` (PHP 8.1+, PDO/MySQL, `/admin` altında ayrı server-rendered panel).
`web/server.js` Express ile Next.js'i sarar; `/api`, `/admin`, `/assets` PHP'ye proxy'lenir. Tek origin.
`README.md` mimariyi ayrıntılı anlatır. Kod ile README çelişirse **kod esastır**; README'yi düzelt.

## Bu repoda bilmen gereken tuzaklar

- `.history/`, `node_modules/`, `vendor/`, `web/src/.next-verify/`: okuma, yazma, arama sonuçlarından çıkar.
- **Sabitler üç yerde kopyalanmış, elle senkronize ediliyor:** `api/src/Shared/Constants/AppConfig.php`, `api/functions/coin_engine.php`, `web/src/shared/lib/pricing.js`. Senkron tutan hiçbir mekanizma yok; uyuşmazlık doğrudan para kaybı. Ayrıca eğitim metni tavanı `AppConfig::MAX_TRAINING_CHARS` web'de `KB_MAX_CHARS` (`app/dashboard/chatbots/create/page.jsx`) olarak kopyalı; bu eşitliği `access_selftest` kilitliyor.
- **Denylist üç yerde:** `api/.htaccess`, `api/admin/.htaccess`, `api/router.php`. Üçü üç farklı deployment şeklini kapsar; birine eklenip diğerine eklenmeyen kural sessizce hiçbir şey yapmaz.
- **Bir endpoint'in `web/src`'te çağıranı olmaması, kullanılmadığı anlamına GELMEZ.** Admin paneli (`api/admin/`) kendi endpoint'lerini çağırır. Çağıran araması üç yerde birden yapılır: `web/src`, `api/admin`, `api/router.php`.
- DB ve payload alan adları Türkçe (`kullanicilar`, `chatbotlar`, `eposta`, `sifre`, `ucret_haftalik`). Yeniden adlandırma. `AppConfig`'in `TABLE_*` sabitleri haritadır.
- `web/src/app/auth/page.jsx` ve `dashboard/market/page.jsx` bilerek `notFound()` çağırır — emekli route'lar, hata değil.
- `next lint` Next 16'da kaldırılıyor; deprecation uyarısı beklenen davranış.

## Mutlak kurallar

1. **Tahmin etme, oku.** Var olduğundan emin olmadığın fonksiyon/endpoint/paket kullanma; bulamazsan "bulunamadı" de.
2. **Sır sızdırma.** `.env`, `.env.bak-*`, `google.txt`, `customserver.txt`, `chatbot_table.txt` içeriğini ne çıktına ne koda yaz. Bu dosyaların değerlerini terminale bastıran komut da çalıştırma. Gerçek anahtar gördüğünde dur ve bildir.
3. **Küçük adım, sık doğrulama.** Tek seferde 10+ dosyayı topluca yeniden yazma.

## Test kuralı (dikkat: koşullu)

- Mevcut davranışın **doğru olduğu kanıtlanabiliyorsa** → düzeltmeden önce regresyon testi yaz.
- Mevcut davranış **zaten yanlışsa** → yanlış davranışı testle kilitleme. Önce doğru davranışı tarif eden testi yaz (kırmızı), sonra düzelt (yeşil).
- Davranışın doğru mu yanlış mı olduğu **belirsizse** → test yazma, bana sor. Belirsizliği testle kesinleştirme.
- Test geçsin diye kodun davranışını değiştirme.

## Otonomi sözleşmesi

### Faz içi çalışma akışı (kalıcı kural)

Bir faz başladıktan sonra adımları sormadan, sırayla yap: kod değişikliği → yerel doğrulama → madde başına commit → `revize/pazaryeri`'ye push → yerel veritabanında `migrate.php --apply` (aşağıdaki Migration kurallarıyla). Her fazın sonunda **tek bir özet** ver; ara onay isteme.

Yalnızca şu durumlarda dur ve sor:

- Canlı/uzak veritabanına veya sunucuya dokunacak bir işlem
- Git geçmişini yeniden yazma, `main`'e push/merge
- Veri silen ya da mevcut veriyi güncelleyen migration (`UPDATE`/`DELETE`)
- Ödeme yolu (Hoppa: `upgradeplan`, `hoppa_return`, iade; iyzico; checkout)
- `AUDIT.md`'de "müşteri onayı BEKLİYOR" olan bir karara bağlı iş
- Bir madde 3 denemede kapanmıyorsa

Bu liste aşağıdaki "Authorization — kademeli" ve "Önce raporla, onay bekle" kurallarını kaldırmaz; o maddeler (şema tasarımı, kırıcı API contract değişikliği, birden fazla endpoint'i etkileyen yetki yaması, belirsiz iş kuralı) için diff önerip beklemeye devam et.

### Sormadan yap

syntax/lint hatası · eksik error handling · eksik input validation · frontend loading/error/empty state · bozuk `fetch` hata yolu · eksik rate limit (mevcut `checkRateLimit()` desenini kullanarak) · dokümantasyon · test yazımı · geri alınabilir izole refactor · kanıtlanmış ölü kod (aşağıdaki 3'lü çağıran araması yapılmışsa)

### Authorization — kademeli

Eksik ownership/auth kontrolü bulduğunda sırayla:

1. **Kanıtla.** Somut sömürü senaryosu yaz: hangi istek, hangi parametre, hangi kullanıcı başkasının neyine erişiyor. Kanıtlayamıyorsan bulgu değil, şüphedir — öyle işaretle.
2. **Minimal yamayı çıkar.** Mevcut auth helper'ıyla, yeni mekanizma icat etmeden.
3. **İzole mi?** Yama yalnızca tek endpoint'i etkiliyorsa ve `web/src` + `api/admin` + `api/router.php` üçünde de çağıran araması yapılmışsa → **uygula.**
4. Yama birden fazla endpoint'i, ortak bir helper'ı, session/oturum davranışını veya API contract'ını etkiliyorsa → **uygulama, diff olarak öner.**
   Her iki durumda da `AUDIT.md`'ye sömürü senaryosuyla birlikte yaz.

### Önce raporla, onay bekle

- Ödeme yolları — canlı anahtarla gerçek para hareket ediyor:
  - Hoppa (bugünkü sağlayıcı, paket ödemesi): `HoppaGateway.php`, `functions/hosted_plan_payments.php`, `WalletController` (`upgradePlan`, `hoppaReturn`), `api/wallet/upgradeplan.php`, `api/wallet/hoppa_return.php`, `api/cron/plan_payments.php`, `admin/ajax/odemeler.php` (iade).
  - iyzico (kapalı, anahtarsız; yalnızca eski kayıtlar): `IyzicoClient.php`, `checkout_payments.php`, `api/marketplace/createsubscription.php`.
- Veritabanı şeması · API contract'ında kırıcı değişiklik · bilinmeyen dış sağlayıcı entegrasyonu · iş kuralı belirsizse doğru davranışa kendin karar verme
- Veri düzeltme migration'ları (mevcut satırı değiştiren `UPDATE`/`DELETE` içerenler, ör. N-06 onarımı) — **yerelde de** uygulamadan önce onay.

### Git

- Commit edebilirsin, yalnızca `revize/pazaryeri` branch'inde. `main` üzerindeysen önce o branch'e geç; `main`'e commit atma.
- Her madde/AUDIT ID'si için ayrı commit; mesaj ID ile başlar (ör. `N-02: …`).
- Commit'ten önce **tek doğrulama betiği** geçmiş olmalı: `bash scripts/verify.sh` (lint + verify build + `php -l` + tüm selftest'ler). Çıkışı 0 ve son satırı `DOĞRULAMA: GEÇTİ` değilse commit yok.
- Commit **ayrı bir komutla** atılır, betik geçtikten sonra. Dosya kırpma, test, `cp`/`sed`/`node -e` gibi adımları commit ile aynı komut zincirine koyma; bir adım başarısız olursa zincir commit'e ulaşmamalı.
- Dosyaları adıyla ekle (`git add -A` / `git add .` değil); senin değiştirmediğin, çalışma ağacında bekleyen dosyalar commit'e girmez.
- Push yalnızca `git push origin revize/pazaryeri`. `main`'e push, merge ve force push **asla**.

### Migration

- `migrate.php --apply` yalnızca **YEREL** veritabanında. Çalıştırmadan önce `api/.env`'deki `DB_HOST`'un yerel olduğunu doğrula (değeri basmadan).
- Sıra: `--status` çıktısını göster → uygulanacak dosyaları listele → `--apply` → tekrar `--status` göster.
- `--apply` bekleyen tüm yıkıcı olmayan dosyaları birlikte uygular, tek dosya seçilemez. `migrate.php`'nin yıkıcılık kontrolü `UPDATE`'i yakalamaz. Bu yüzden `migrations/` altında onay bekleyen bir veri düzeltme dosyası varken `--apply` çalıştırma; o dosyayı onaya kadar `migrations/` dışında tut.
- Bu izin yalnızca var olan migration'ı **uygulamayı** kapsar; yeni şema tasarımı hâlâ "önce raporla" kapsamında.
- **Canlıda migration yalnızca `scripts/deploy-backend.sh` ile** (cPanel Git Version Control → `.cpanel.yml`, ya da README → "Backend deploy (cPanel)"daki tek seferlik Cron komutu) ve yalnızca **yıkıcı olmayan, ertelenmemiş** dosyalar (`migrate.php --apply --auto`; kilit + yedek + log betikte). Yıkıcı ve ertelenmiş dosyalar (001/002/002b/003/005/006 ve ileride `MIGRATION_DEFERRED`'a eklenenler) yalnızca **kullanıcının açık onayıyla**, tek tek: `deploy-backend.sh --approve DOSYA [--allow-destructive]`. Deploy'u ve onaylı çalıştırmayı kullanıcı tetikler; Claude canlıya bağlanmaz, betiği canlıda çalıştırmaz.
- **Sonuç:** `main`'e giren `migrations/` içindeki yıkıcı olmayan her dosya — `UPDATE` içerenler dahil — bir sonraki canlı deploy'da onaysız uygulanır. Onay bekleyen dosya `pending/`'de kalır (hiçbir mod okumaz); canlıda onaysız çalışmaması gereken yeni bir dosya `migration_lib.php` → `MIGRATION_DEFERRED`'a eklenir. `migrate_selftest` D bölümü, başlangıç kaydından sonra `--auto`'nun 004/010 ve 001–006'ya dokunmadığını, `pending/`'i okumadığını ve bekleyenlerin hiçbirinin yıkıcı olmadığını kilitler.
- Canlı için yeni bir migration yazarken: uygulama sırasını ve her dosya için geri alma notunu yaz (DDL örtük commit yapar; geri alma ayrı bir ters migration demektir).

### Asla yapma

Canlı/uzak veritabanına bağlanmak ya da orada `migrate.php --apply` · `--allow-destructive` (yerelde de) · `db_backup.php mode=restore` · `mysqldump` restore · elle `DROP`/`TRUNCATE`/`DELETE FROM` · gerçek ödeme çağrısı · production deploy · secret rotasyonu · git geçmişi yeniden yazma (`commit --amend`, rebase, reset) · `main`'e commit/push/merge · force push
Migration dosyası **yazabilirsin**; yalnızca yerelde ve yukarıdaki Migration kurallarıyla **uygulayabilirsin**.

## BLOCKERS.md

Repo kökünde `BLOCKERS.md` var: senin kod yazarak çözemeyeceğin, dışarıdan hesap/karar/kimlik bilgisi gerektiren maddeler.

- Bu dosyadaki hiçbir maddeyi "çözüldü" olarak işaretleme yetkin yok. Sadece madde ekleyebilir, mevcut maddeye bulgu ekleyebilirsin.
- Bir özelliği "çalışıyor" ilan etmeden önce `BLOCKERS.md`'yi kontrol et: o özellik açık bir blocker'a bağlıysa "çalışıyor" diyemezsin.
- Bir blocker'ı stub/mock/varsayılan değerle doldurup üstünü örtme.

## Doğrulama komutları

Hepsini sırayla çalıştıran tek kapı: `bash scripts/verify.sh` (kayıtlar `storage/logs/verify/`). Tek tek:

```bash
cd web && NEXT_DIST_DIR=.next-verify npm run build
cd web && npm run lint
find api -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 php -l
php api/database/iyzico_selftest.php     # A bölümü anahtarsız da geçmeli
php api/database/plan_limits_selftest.php --strict  # plan/kota (013 uygulanmış kurulumda tamamen yeşil)
php api/database/access_selftest.php      # erişim/listeleme matrisi (Faz 4)
php api/database/application_selftest.php # pazaryeri başvurusu (Faz 5)
php api/database/hoppa_selftest.php       # Hoppa paket ödemesi (Faz 7a); --e2e: test ortamında gerçek ödeme (dosya başlığına bakın)
php api/database/migrate_selftest.php     # migration checksum satır sonundan bağımsız (N-25), salt okunur
php api/database/gemini_selftest.php      # Gemini hata özeti anahtarı sızdırmıyor, model tek kaynakta (N-46), çevrimdışı
php api/database/plan_collation_selftest.php # plan_name ↔ plans.name_tr collation (N-49), TEMPORARY tablo
php api/database/secret_scan_selftest.php    # .env örneklerinde gerçek anahtar/değer yok (N-55), çevrimdışı
php api/database/vendor_selftest.php         # vendor eksik/eski → anlaşılır 503; lock PHP 8.2 uyumlu (N-56), çevrimdışı
php api/database/hoppa_diag_selftest.php     # Hoppa bağlantı testi sınıflaması + hata kodu → Türkçe mesaj (N-57), çevrimdışı
php api/database/readurl_selftest.php        # URL okuma: hata sınıfları, içerik çıkarma, karakter kodlaması (N-61), çevrimdışı
php api/database/document_selftest.php       # belge: PDF/DOCX/TXT, şifreli/taranmış/.doc/boyut mesajları (N-62), çevrimdışı
php api/database/subscription_selftest.php   # Aboneliğim: plan/durum/tarih/geçmiş tek kaynaktan (N-63), TRANSACTION+ROLLBACK
node --test web/src/shared/lib/image-url.test.mjs # tek görsel URL üreticisi + baş harf yer tutucu (N-58)
node --test web/src/features/chat/reply-errors.test.mjs # sohbet hata türü → kullanıcı metni (N-59)
```

Bir değişiklik bunlardan birini bozuyorsa geri al ve nedenini raporla.

**Çıkış kodu kuralı:** Doğrulama çıktısını `tail`/`head`/`grep` ile kısaltıyorsan zincirin başına `set -o pipefail` koy ya da komutun çıkış kodunu ayrıca kontrol et (`cmd > log; echo $?`). Aksi hâlde borunun sonundaki komut başarısızlığı yutar ve zincir "geçti" gibi devam eder. Doğrulamalardan biri başarısızsa (çıkış kodu ≠ 0 ya da çıktıda `FAIL`/`error`) **commit atılmaz**.

## Her turun sonunda

Değiştirdiğin her dosya için tek satır: **ne değişti · hangi AUDIT ID'sini kapattı · nasıl doğrulandı.**
