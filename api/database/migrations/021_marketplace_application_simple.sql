ALTER TABLE `marketplace_applications`
  ADD COLUMN IF NOT EXISTS `full_name`   varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `phone`       varchar(20)  COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `email`       varchar(190) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `description` text         COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `email`,
  MODIFY `account_type`          enum('sahis','kurumsal') COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `company_title`         varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `tax_number`            varchar(11)  COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'kurumsal: 10 haneli VKN; şahıs: 11 haneli TCKN ya da 10 haneli VKN',
  MODIFY `tax_office`            varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `authorized_first_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `authorized_last_name`  varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `authorized_birth_date` date DEFAULT NULL,
  MODIFY `iban`                  varchar(34)  COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `il`                    varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `ilce`                  varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  MODIFY `address`               varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL;

SELECT COUNT(*) AS yeni_sutun FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_applications'
   AND COLUMN_NAME IN ('full_name', 'phone', 'email', 'description');
