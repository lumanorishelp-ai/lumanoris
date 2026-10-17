-- 017 — phpMyAdmin sürümü. Asıl dosya: migrations/017_admin_audit_log.sql.
-- Tek fark: son doğrulama information_schema yerine SHOW TABLES. Tekrar
-- çalıştırmak zararsız (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `id`             bigint NOT NULL AUTO_INCREMENT,
  `admin_id`       int DEFAULT NULL,
  `admin_name`     varchar(64)  COLLATE utf8mb4_general_ci NOT NULL,
  `action`         varchar(64)  COLLATE utf8mb4_general_ci NOT NULL,
  `target_user_id` int DEFAULT NULL,
  `target_type`    varchar(64)  COLLATE utf8mb4_general_ci DEFAULT NULL,
  `target_id`      int DEFAULT NULL,
  `details`        json DEFAULT NULL,
  `ip`             varchar(45)  COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_target_user` (`target_user_id`),
  KEY `idx_audit_action_time` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doğrulama: 1 satır
SHOW TABLES LIKE 'admin_audit_log';
