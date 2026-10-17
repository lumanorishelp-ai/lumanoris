-- 009 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/009_user_list_color.sql. Koşulsuz: BİR KEZ çalıştırın.
-- Ön koşul: SHOW COLUMNS FROM `user_lists` LIKE 'color'; → boş.

ALTER TABLE `user_lists` ADD COLUMN `color` VARCHAR(20) NOT NULL DEFAULT 'violet' AFTER `name`;
ALTER TABLE `user_lists` ADD COLUMN `description` VARCHAR(500) NULL AFTER `color`;

-- Doğrulama: 2 satır
SHOW COLUMNS FROM `user_lists` WHERE Field IN ('color', 'description');
