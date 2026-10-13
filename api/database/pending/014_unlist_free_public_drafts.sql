-- 014 — (KOŞULLU) Faz 4 yamasıyla vitrine çıkacak fiyatsız herkese açık botları özele çek
--
-- DURUM: ÖNERİ, ONAY BEKLİYOR — UYGULANMADI. Yalnızca canlıda
-- `docs/proposals/faz4-kontrol-a.sql` ilk sorgusu satır döndürürse ve o
-- botların sahiplerinin taslağı olduğu kararlaştırılırsa kullanılır.
-- Yerelde (2026-10-06) o sorgu 0 satır döndü; yani yerelde bu dosyanın
-- yapacağı bir şey yok.
--
-- Neden: Faz 4 öncesi herkese açık bot oluşturmak aktif satıcı istiyordu, ama
-- satıcı durumu sonradan `active` dışına düşen yazarların (ya da B1 öncesi
-- eski akışların) fiyatsız `is_independent = 0` botları kalmış olabilir. Bunlar
-- bugün vitrinde değil; yamayla birlikte herkese açık ve ÜCRETSİZ sohbet
-- edilebilir hâle gelirler. Sahipleri bunu hiç seçmemiş olabilir.
--
-- Ne yapar: yalnızca o sorgunun bulduğu botları özele çeker. Özel yapma
-- hakkı (madde 11) DÜŞÜLMEZ — bu bir veri geçişi, kullanıcı eylemi değil.
-- Satır silmez; migrate.php yıkıcı saymaz. Idempotent.
--
-- Uygulama sırası: Faz 4 kodu deploy edilmeden HEMEN ÖNCE (ya da aynı bakım
-- penceresinde) — kod önce giderse arada bu botlar vitrine çıkar.
--
-- Geri alma: bu dosyanın değiştirdiği id'ler doğrulama SELECT'inde listelenir;
-- geri almak için o id'lerde `is_independent = 0`.

UPDATE chatbotlar c
LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id
SET c.is_independent = 1
WHERE c.is_independent = 0
  AND COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0
  AND COALESCE(pms.status, '') <> 'active'
  -- Platform botu (N-14 / GK-18, 015) bilerek ücretsiz herkese açık; hariç.
  AND UPPER(TRIM(c.isim)) <> 'LUMANORIS AI';

-- Doğrulama: aynı ölçütle kalan bot 0 olmalı.
SELECT COUNT(*) AS kalan FROM chatbotlar c
LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id
WHERE c.is_independent = 0
  AND COALESCE(c.ucret_haftalik, 0) = 0 AND COALESCE(c.ucret_aylik, 0) = 0
  AND COALESCE(pms.status, '') <> 'active'
  -- Platform botu (N-14 / GK-18, 015) bilerek ücretsiz herkese açık; hariç.
  AND UPPER(TRIM(c.isim)) <> 'LUMANORIS AI';
