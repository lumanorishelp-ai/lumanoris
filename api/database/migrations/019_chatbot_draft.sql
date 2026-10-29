-- 019 — chatbotlar.is_draft: taslak bot (N-68, Madde 4 / Faz C)
--
-- Bu dosya VERİ SİLMEZ, satır değiştirmez. Yalnızca sütun + indeks EKLER.
--
-- ── Neden ────────────────────────────────────────────────────────────────
-- Bot oluşturma sihirbazında Bilgi Bankası, bot 4. adımda kaydedilene kadar
-- kilitliydi (müşteri görseli 01). Artık bot 1. adım tamamlanınca TASLAK
-- olarak kaydediliyor; bilgi bankası hemen kullanılabiliyor.
--
-- Taslak = is_independent = 1 AND is_draft = 1. Herkese açık her sorgu
-- (vitrin, Keşfet, liste, profil, defter, sohbet erişimi) zaten
-- is_independent = 0 şartı aradığı için taslak hiçbir genel yerde
-- görünemez. Bot limitleri taslakları SAYMAZ (functions/chatbot_limits.php);
-- kullanıcı başına en fazla CHATBOT_DRAFT_LIMIT taslak. "Yayınla" anında
-- seçilen türün (herkese açık / özel) limiti uygulanır ve is_draft = 0 olur.
--
-- Mevcut satırlar DEFAULT 0 alır = taslak değil; davranış değişmez.
-- Kod sütun yoksa eski davranışa düşer (chatbotHasDraftColumn) — sıra:
-- önce kod, sonra bu dosya ya da tersi, ikisi de güvenli.
--
-- Idempotent: sütun/indeks zaten varsa hiçbir şey yapmaz.
-- Geri alma (ayrı, onaylı ters migration): taslak satırlar varsa önce
-- yayınlanmalı/silinmeli; sonra
--   ALTER TABLE chatbotlar DROP INDEX idx_chatbot_author_draft, DROP COLUMN is_draft;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbotlar' AND COLUMN_NAME = 'is_draft');
SET @s1 := IF(@has_col = 0,
    'ALTER TABLE `chatbotlar` ADD COLUMN `is_draft` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=taslak (sihirbaz 1. adımda kaydedildi, henüz yayınlanmadı/özel kaydedilmedi)'' AFTER `is_independent`',
    'DO 0');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbotlar' AND INDEX_NAME = 'idx_chatbot_author_draft');
SET @s2 := IF(@has_idx = 0,
    'ALTER TABLE `chatbotlar` ADD INDEX `idx_chatbot_author_draft` (`author_user_id`, `is_draft`)',
    'DO 0');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- Doğrulama: 1 satır, Default 0
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbotlar' AND COLUMN_NAME = 'is_draft';
