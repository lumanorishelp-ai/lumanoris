-- 016 — phpMyAdmin sürümü. Asıl dosya: migrations/016_marketplace_applications.sql.
-- Tek fark: son doğrulama information_schema yerine SHOW TABLES. Tekrar
-- çalıştırmak zararsız (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `marketplace_applications` (
  `id`                    int NOT NULL AUTO_INCREMENT,
  `user_id`               int NOT NULL,
  `account_type`          enum('sahis','kurumsal') COLLATE utf8mb4_general_ci NOT NULL,
  `company_title`         varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `tax_number`            varchar(11)  COLLATE utf8mb4_general_ci NOT NULL COMMENT 'kurumsal: 10 haneli VKN; şahıs: 11 haneli TCKN ya da 10 haneli VKN',
  `tax_office`            varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `mersis_no`             char(16)     COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'kurumsal: zorunlu; şahıs: isteğe bağlı (öneri, onay bekliyor)',
  `authorized_first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `authorized_last_name`  varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `authorized_birth_date` date NOT NULL,
  `iban`                  varchar(34)  COLLATE utf8mb4_general_ci NOT NULL,
  `il`                    varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `ilce`                  varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `address`               varchar(500) COLLATE utf8mb4_general_ci NOT NULL,
  `status`                enum('submitted','reviewed','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'submitted',
  `review_note`           varchar(1000) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reviewed_by_admin_id`  int DEFAULT NULL,
  `reviewed_at`           datetime DEFAULT NULL,
  `created_at`            datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mapp_user` (`user_id`),
  KEY `idx_mapp_status` (`status`),
  CONSTRAINT `fk_mapp_user` FOREIGN KEY (`user_id`) REFERENCES `kullanicilar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doğrulama: 1 satır
SHOW TABLES LIKE 'marketplace_applications';
