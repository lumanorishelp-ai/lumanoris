-- 018 — user_plan_selection collation'ı plans ile eşitlenir (N-49)
--
-- Bu dosya VERİ SİLMEZ, satır değiştirmez. Yalnızca tablo/sütun collation'ı.
--
-- ── Sorun ────────────────────────────────────────────────────────────────
-- Canlı php-error.log (2026-10-15'ten beri, her plan okumasında):
--     [plans] getUserPlan başarısız: SQLSTATE[HY000]: General error: 1267
--     Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and
--     (utf8mb4_unicode_ci,IMPLICIT) for operation '='
-- Sorgu: functions/plans.php getUserPlan →
--     JOIN plans p ON p.name_tr = ups.plan_name
-- MySQL işlenenleri sırayla yazar: plans.name_tr general_ci (schema.sql ve
-- 007 ile), user_plan_selection.plan_name unicode_ci (canlıda uygulama
-- dışında, sunucu varsayılanıyla oluşmuş). 007'den önce plans kataloğu
-- hazır sayılmadığı için JOIN hiç çalışmıyordu; 007 uygulanınca başladı.
--
-- Etkisi: getUserPlan hatayı yakalıyor ve AppConfig ücretsiz planına düşüyor
-- → paket satın alan kullanıcı ücretsiz plan limitleriyle (coin, bot, hak)
-- kalır. Ödeme 2026-10-17 itibarıyla canlıda kapalı; ödeme açılmadan önce
-- uygulanmalı.
--
-- Bu, ertelenmiş 005_fix_table_collations.sql'in kapsadığı 10 tablodan
-- yalnızca biri (005 kendi başlığında "007'nin ön koşulu" diyor). 005'in
-- tamamı onaylı bütünlük paketinde kalıyor; bu dosya yalnızca acil olanı
-- yapıyor. 005 sonra çalışınca bu tabloyu zaten general_ci bulup atlar.
--
-- ── Risk ─────────────────────────────────────────────────────────────────
-- Karakter kümesi zaten utf8mb4; CONVERT bayt değiştirmez, yalnızca
-- karşılaştırma kuralını değiştirir. Tablo küçük (kullanıcı başına ≤ 1
-- satır, PK user_id), UNIQUE metin sütunu yok → çakışma riski yok.
-- Tabloyu kısa süre kilitler (ALTER kopyalama).
--
-- Idempotent: tablo zaten general_ci ise hiçbir şey yapmaz.
-- Geri alma: ALTER TABLE user_plan_selection CONVERT TO CHARACTER SET
-- utf8mb4 COLLATE utf8mb4_unicode_ci (önerilmez — çakışma geri gelir).

SET @c1 := (SELECT TABLE_COLLATION FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plan_selection');
SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plan_selection'
              AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_general_ci');
SET @s1 := IF(@c1 IS NOT NULL AND (@c1 <> 'utf8mb4_general_ci' OR @c2 > 0),
    'ALTER TABLE `user_plan_selection` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

-- Doğrulama: 0 satır dönmeli
SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user_plan_selection', 'plans')
   AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_general_ci';
