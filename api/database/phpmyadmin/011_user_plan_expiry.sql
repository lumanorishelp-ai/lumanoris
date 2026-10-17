-- 011 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/011_user_plan_expiry.sql. Koşulsuz: BİR KEZ çalıştırın.
-- Ön koşul: SHOW COLUMNS FROM `user_plan_selection` LIKE 'expires_at'; → boş.

ALTER TABLE `user_plan_selection` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL AFTER `selected_at`;
ALTER TABLE `user_plan_selection` ADD INDEX `idx_user_plan_expires` (`expires_at`);

-- Doğrulama: 1 sütun, 1 index satırı
SHOW COLUMNS FROM `user_plan_selection` LIKE 'expires_at';
SHOW INDEX FROM `user_plan_selection` WHERE Key_name = 'idx_user_plan_expires';
