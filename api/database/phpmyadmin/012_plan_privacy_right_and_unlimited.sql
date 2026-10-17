-- 012 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/012_plan_privacy_right_and_unlimited.sql. 007'den SONRA, BİR KEZ.
-- Ön koşul: SHOW COLUMNS FROM `plans` LIKE 'privacy_right_limit'; → boş.
-- (MODIFY tekrar çalışsa zararsızdır; ADD COLUMN ikinci kez #1060 verir.)

ALTER TABLE `plans`
  MODIFY COLUMN `independent_bot_limit` INT NULL DEFAULT 1,
  MODIFY COLUMN `public_bot_limit` INT NULL DEFAULT 2;

ALTER TABLE `plans` ADD COLUMN `privacy_right_limit` INT NOT NULL DEFAULT 1 AFTER `daily_message_limit`;

CREATE TABLE IF NOT EXISTS `user_privacy_right_usage` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `user_id`    int NOT NULL,
  `chatbot_id` int unsigned NOT NULL,
  `used_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_privacy_usage_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doğrulama: iki limit Null = YES; privacy_right_limit var; tablo 1 satır
SHOW COLUMNS FROM `plans` WHERE Field IN ('independent_bot_limit', 'public_bot_limit', 'privacy_right_limit');
SHOW TABLES LIKE 'user_privacy_right_usage';
