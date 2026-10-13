-- 012 — Paket şeması: "sınırsız" kota ve yayından kaldırma (özel yapma) hakkı
--
-- DURUM: ONAYLANDI (kullanıcı, 2026-10-06). Önce NULL'a dayanıklı kod (cd02a62),
-- sonra bu migration.
--
-- Bağlam: AUDIT.md Faz 3 paket tablosu, GK-3, GK-4, GK-17.
--
-- ── Bu migration ne yapıyor ──────────────────────────────────────────────
-- 1. `plans.independent_bot_limit` / `public_bot_limit` NULL kabul eder:
--    NULL = SINIRSIZ (Elmas). Bugün `INT NOT NULL`; "sınırsız" için ya devasa
--    bir sayı ya da NULL gerekiyordu — devasa sayı admin panelinde ve paket
--    metinlerinde "50"/"9999" gibi yanıltıcı görünürdü.
-- 2. `plans.privacy_right_limit` ekler: paket başına yayından kaldırma
--    (özel yapma) hakkı, TOPLAM (GK-17). Varsayılan 1 = ücretsiz plan.
-- 3. `user_privacy_right_usage` ekler: hakkın kullanıldığı her an bir satır.
--    Kullanılan hak = COUNT(*) WHERE user_id. Ayrı tablo, çünkü hak bot
--    silinse de "kullanılmış" kalmalı ve hangi bot için kullanıldığı
--    denetlenebilmeli.
--
-- ── Uygulama sırası ──────────────────────────────────────────────────────
-- ÖNCE NULL'a dayanıklı kod deploy edilmeli (getIndependentBotLimit /
-- getPublicBotLimit bugün `(int)` ile cast ediyor: NULL → 0 olur ve Elmas
-- kullanıcısı HİÇ bot açamaz). Bu migration tek başına davranış değiştirmez:
-- mevcut satırlar NOT NULL değerlerini korur; NULL'ı ancak 013 (veri) yazar.
--
-- Salt ekleme / gevşetme: veri silmez, mevcut değer değiştirmez.
-- Idempotent: her adım koşullu.
--
-- ── Geri alma notu ───────────────────────────────────────────────────────
-- DDL örtük COMMIT yapar. Geri almak için ters migration:
--   ALTER TABLE plans DROP COLUMN privacy_right_limit;
--   DROP TABLE user_privacy_right_usage;
--   (NULL'ı geri sıkılaştırmak için önce NULL satırlar bir sayıya
--    güncellenmeli, sonra MODIFY ... NOT NULL.)

-- 1) Sınırsız = NULL
SET @c1 := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans'
              AND COLUMN_NAME = 'independent_bot_limit');
SET @s1 := IF(@c1 = 'NO',
    'ALTER TABLE `plans` MODIFY COLUMN `independent_bot_limit` INT NULL DEFAULT 1, MODIFY COLUMN `public_bot_limit` INT NULL DEFAULT 2',
    'DO 0');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

-- 2) Yayından kaldırma (özel yapma) hakkı — toplam
SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans'
              AND COLUMN_NAME = 'privacy_right_limit');
SET @s2 := IF(@c2 = 0,
    'ALTER TABLE `plans` ADD COLUMN `privacy_right_limit` INT NOT NULL DEFAULT 1 AFTER `daily_message_limit`',
    'DO 0');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- 3) Hak kullanım kaydı
CREATE TABLE IF NOT EXISTS `user_privacy_right_usage` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `user_id`    int NOT NULL,
  `chatbot_id` int unsigned NOT NULL,
  `used_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_privacy_usage_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doğrulama
SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans'
   AND COLUMN_NAME IN ('independent_bot_limit', 'public_bot_limit', 'privacy_right_limit');
SELECT COUNT(*) AS usage_table_present FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_privacy_right_usage';
