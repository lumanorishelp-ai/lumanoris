-- Lumanoris database schema
--
-- Generated from the live `lumanoris` database (MySQL 8.0.42).
-- The repository previously contained no .sql, migration or seed file at
-- all: every one of the 50 tables lived only inside whatever database
-- the developer happened to have, so a clean checkout could not bring the
-- application up. This file is that missing starting point.
--
-- Structure only — no rows. Apply to an empty database with:
--     mysql -u <user> -p <dbname> < api/database/schema.sql
--
-- Note: this reflects the schema as it exists today, including the issues
-- the audit recorded (no foreign keys anywhere, and one logical key stored
-- with three different integer types). Those are addressed by the separate
-- migration files alongside this one, deliberately NOT folded in here so
-- this stays a faithful snapshot of the current state.

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `adminler`;
CREATE TABLE `adminler` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kullanici_adi` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `sifre` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `banka_bilgileri`;
CREATE TABLE `banka_bilgileri` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `account_type` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `authorized_first_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `authorized_last_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `company_title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tax_number` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tax_office` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_number` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `iban` varchar(34) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `il` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ilce` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `il_kod` int DEFAULT NULL COMMENT 'Param IL kodu',
  `ilce_kod` int DEFAULT NULL COMMENT 'Param ILCE kodu',
  `mahalle` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cadde` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sokak` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bina_no` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `kapi_no` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `posta_kodu` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `kisi_dogum_tarihi` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'dd.MM.yyyy (Param)',
  `yetkili_kisi_dogum_tarihi` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'dd.MM.yyyy (Param)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_chats`;
CREATE TABLE `chatbot_chats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chatbot_id` int NOT NULL,
  `user_id` int NOT NULL,
  `sent_by` text COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `sent_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `chatbot_id` (`chatbot_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_comments`;
CREATE TABLE `chatbot_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `comment` varchar(1000) COLLATE utf8mb4_general_ci NOT NULL,
  `commented_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `chatbot_id` (`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_conversations`;
CREATE TABLE `chatbot_conversations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `chatbot_id` int NOT NULL,
  `conversation_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `chatbot_id` (`chatbot_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_dislikes`;
CREATE TABLE `chatbot_dislikes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `disliked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_follows`;
CREATE TABLE `chatbot_follows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `followed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_hide`;
CREATE TABLE `chatbot_hide` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `hidden_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_in_list`;
CREATE TABLE `chatbot_in_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chatbot_id` int NOT NULL,
  `list_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `list_id` (`list_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_kategoriler`;
CREATE TABLE `chatbot_kategoriler` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kategori_adi_tr` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `kategori_adi_en` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_likes`;
CREATE TABLE `chatbot_likes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `liked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_purchase_credits`;
CREATE TABLE `chatbot_purchase_credits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `chatbot_id` int NOT NULL,
  `credits_remaining` int NOT NULL DEFAULT '0',
  `credits_total` int NOT NULL DEFAULT '0',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_chatbot` (`user_id`,`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_reports`;
CREATE TABLE `chatbot_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `reported_for` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `report_detail` varchar(1000) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_uninterested`;
CREATE TABLE `chatbot_uninterested` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `uninterested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbot_visits`;
CREATE TABLE `chatbot_visits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `chatbot_id` bigint unsigned NOT NULL,
  `visit_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `chatbotlar`;
CREATE TABLE `chatbotlar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `author_user_id` int unsigned NOT NULL,
  `owner_user_id` int NOT NULL,
  `isim` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `aciklama` text COLLATE utf8mb4_general_ci,
  `kapak_fotografi` mediumtext COLLATE utf8mb4_general_ci,
  `profil_fotografi` mediumtext COLLATE utf8mb4_general_ci,
  `kategori_id` int unsigned DEFAULT NULL,
  `style_prompt` varchar(5000) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sohbet_basi_mesaj` text COLLATE utf8mb4_general_ci,
  `ucret_haftalik` decimal(10,2) DEFAULT NULL,
  `ucret_aylik` decimal(10,2) DEFAULT NULL,
  `training_prompt` longtext COLLATE utf8mb4_general_ci,
  `yayimlanma_tarih` datetime DEFAULT CURRENT_TIMESTAMP,
  `edit_tarih` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_independent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=Bağımsız (pazaryeri dışı, sadece sahibine açık)',
  `is_draft` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=taslak (migration 019, N-68)',
  PRIMARY KEY (`id`),
  KEY `author_user_id` (`author_user_id`),
  KEY `idx_chatbot_author_draft` (`author_user_id`, `is_draft`),
  KEY `owner_user_id` (`owner_user_id`),
  KEY `kategori_id` (`kategori_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `constant_translations`;
CREATE TABLE `constant_translations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tr` text COLLATE utf8mb4_general_ci NOT NULL,
  `en` text COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `dialog_comments`;
CREATE TABLE `dialog_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dialog_id` bigint unsigned NOT NULL,
  `comment` varchar(1000) COLLATE utf8mb4_general_ci NOT NULL,
  `commented_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `dialog_dislikes`;
CREATE TABLE `dialog_dislikes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dialog_id` bigint unsigned NOT NULL,
  `disliked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`dialog_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `dialog_hide`;
CREATE TABLE `dialog_hide` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dialog_id` bigint unsigned NOT NULL,
  `hidden_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `dialog_likes`;
CREATE TABLE `dialog_likes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dialog_id` bigint unsigned NOT NULL,
  `liked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`dialog_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `dialog_reports`;
CREATE TABLE `dialog_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dialog_id` bigint unsigned NOT NULL,
  `reported_for` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `report_detail` varchar(1000) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `dialog_uninterested`;
CREATE TABLE `dialog_uninterested` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `uninterested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `global_vars`;
CREATE TABLE `global_vars` (
  `id` int NOT NULL AUTO_INCREMENT,
  `var_key` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `var_value` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `var_key` (`var_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `kullanicilar`;
CREATE TABLE `kullanicilar` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ad_soyad` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `kullanici_adi` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `google_id` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `eposta` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `telefon` varchar(15) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dogum_tarihi` date DEFAULT NULL,
  `sifre` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `avatar` longtext COLLATE utf8mb4_general_ci,
  `dil` enum('Türkçe','İngilizce') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Türkçe',
  PRIMARY KEY (`id`),
  UNIQUE KEY `eposta` (`eposta`),
  UNIQUE KEY `kullanici_adi` (`kullanici_adi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `title_tr` text COLLATE utf8mb4_general_ci NOT NULL,
  `title_en` text COLLATE utf8mb4_general_ci NOT NULL,
  `message_tr` text COLLATE utf8mb4_general_ci,
  `message_en` text COLLATE utf8mb4_general_ci,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `page_hits`;
CREATE TABLE `page_hits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `page_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_general_ci,
  `referer` text COLLATE utf8mb4_general_ci,
  `click_target` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `para_cekme_talepleri`;
CREATE TABLE `para_cekme_talepleri` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `iban` varchar(34) COLLATE utf8mb4_general_ci NOT NULL,
  `miktar` decimal(10,2) NOT NULL,
  `durum` varchar(32) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'beklemede',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `param_marketplace_alerts`;
CREATE TABLE `param_marketplace_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(64) NOT NULL,
  `severity` varchar(16) NOT NULL DEFAULT 'warning' COMMENT 'warning, critical',
  `order_id` varchar(32) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `seller_user_id` int DEFAULT NULL,
  `message` text,
  `context_json` longtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `param_marketplace_details`;
CREATE TABLE `param_marketplace_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `seller_user_id` int NOT NULL,
  `chatbot_id` int DEFAULT NULL,
  `guid_altuyeisyeri` varchar(64) NOT NULL DEFAULT '',
  `gross_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payable_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pysiparis_guid` varchar(64) DEFAULT NULL COMMENT 'Param PYSiparis_GUID',
  `status` varchar(32) NOT NULL DEFAULT 'pending_approval' COMMENT 'pending_approval, approved, cancelled, cancel_failed, refunded',
  `param_response_json` longtext,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_pysiparis_guid` (`pysiparis_guid`),
  KEY `idx_seller_user_id` (`seller_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `param_marketplace_payments`;
CREATE TABLE `param_marketplace_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` varchar(32) NOT NULL,
  `user_id` int NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending, payment_started, paid, failed, hash_failed, refunded, partial_refund',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `product_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `service_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `param_transaction_id` varchar(32) DEFAULT NULL COMMENT 'SanalPOS Islem_ID (büyük sayı, INT taşar)',
  `param_receipt_id` varchar(32) DEFAULT NULL COMMENT 'TURKPOS Dekont_ID',
  `param_net_amount` decimal(10,2) DEFAULT NULL,
  `redirect_url` varchar(512) DEFAULT NULL,
  `items_json` longtext,
  `seller_splits_json` longtext,
  `param_response_json` longtext,
  `callback_json` longtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `param_marketplace_refunds`;
CREATE TABLE `param_marketplace_refunds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `detail_id` int NOT NULL,
  `pysiparis_guid` varchar(64) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `reason` text,
  `requested_by_user_id` int DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'completed, failed',
  `param_response_json` longtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_detail_id` (`detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `param_marketplace_sellers`;
CREATE TABLE `param_marketplace_sellers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `guid_altuyeisyeri` varchar(64) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` enum('not_started','pending','active','rejected','suspended') NOT NULL DEFAULT 'not_started',
  `tip` tinyint NOT NULL DEFAULT '1' COMMENT '1=Bireysel, 2=Sahis, 3=Kurumsal',
  `last_error` text,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `param_payload_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `param_marketplace_soap_log`;
CREATE TABLE `param_marketplace_soap_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `order_id` varchar(32) DEFAULT NULL,
  `method` varchar(64) DEFAULT NULL,
  `wsdl` varchar(255) DEFAULT NULL,
  `request_xml` longtext,
  `response_xml` longtext,
  `result_code` varchar(8) DEFAULT NULL,
  `result_message` varchar(255) DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `error_message` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_method` (`method`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `code_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `plan_icerikler`;
CREATE TABLE `plan_icerikler` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_id` int NOT NULL,
  `feature_tr` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `feature_en` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_id` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `plans`;
CREATE TABLE `plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name_tr` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `monthly_price` decimal(10,2) DEFAULT NULL,
  `yearly_price` decimal(10,2) DEFAULT NULL,
  `currency` tinyint NOT NULL DEFAULT '1',
  `description_tr` text COLLATE utf8mb4_general_ci,
  `description_en` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `producer_plans`;
CREATE TABLE `producer_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `started_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `producer_self_use_credits`;
CREATE TABLE `producer_self_use_credits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `chatbot_id` int NOT NULL,
  `credits_remaining` int NOT NULL DEFAULT '0',
  `credits_total` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_chatbot` (`user_id`,`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `rate_limits`;
CREATE TABLE `rate_limits` (
  `rkey` varchar(191) NOT NULL,
  `attempts` int NOT NULL,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`rkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `themes`;
CREATE TABLE `themes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `theme_name` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `main_color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `sub_color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `hover_color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `active_color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `text_color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_cart`;
CREATE TABLE `user_cart` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `chatbot_id` int NOT NULL,
  `order_weeks` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_chatbot` (`user_id`,`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_coin_balance`;
CREATE TABLE `user_coin_balance` (
  `user_id` int NOT NULL,
  `coins_remaining` int NOT NULL DEFAULT '10',
  `last_reset_date` date NOT NULL,
  `exhausted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_dialog_books`;
CREATE TABLE `user_dialog_books` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `chatbot_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `input_message` text COLLATE utf8mb4_general_ci NOT NULL,
  `output_message` text COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_emails`;
CREATE TABLE `user_emails` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_lists`;
-- `color` ve `description` migration 009 ile geldi ama temel şemaya
-- eklenmemişti. Sonuç: sıfırdan kurulan bir veritabanında SocialController'ın
-- addUserList()/getUserLists() sorguları "Unknown column 'color'" ile
-- patlıyor, yani liste oluşturma ve listeleme hiç çalışmıyordu (AUDIT K-01).
-- 009 idempotent (sütun varsa dokunmuyor), bu yüzden ikisi çakışmaz.
CREATE TABLE `user_lists` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'violet',
  `description` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_phones`;
CREATE TABLE `user_phones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_plan_selection`;
-- D-05: `expires_at` migration 011 ile geldi. Paket 30 GÜNLÜK TEK
-- SEFERLİK bir satış; yinelenen tahsilat yok. NULL = süresiz (bu
-- sütundan önce yazılmış satırlar geriye dönük iptal edilmesin diye).
CREATE TABLE `user_plan_selection` (
  `user_id` int NOT NULL,
  `plan_name` varchar(30) NOT NULL,
  `selected_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_user_plan_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_subscriptions`;
CREATE TABLE `user_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `chatbot_id` int NOT NULL,
  `duration_weeks` int NOT NULL,
  `expiry_date` datetime NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `chatbot_id` (`chatbot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `user_tokens`;
CREATE TABLE `user_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `selector` varchar(32) COLLATE utf8mb4_general_ci NOT NULL,
  `hashed_validator` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `expiry` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
