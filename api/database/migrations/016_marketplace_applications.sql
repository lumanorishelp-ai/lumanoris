-- 016 — Pazaryeri başvuruları (madde 3, Faz 5)
--
-- DURUM: ONAYLANDI (GK-19, 2026-10-06).
--
--
-- Bağlam: GK-7 (alanlar), GK-8 (şahıs + kurumsal; bireysel yok), GK-9 (onay
-- admin panelinden, "incelendi" yazar, satıcıyı `active` YAPMAZ), GK-5
-- (başvuru yapmış olmak Bakiyem'i açar).
--
-- Tasarım:
--   • Kullanıcı başına TEK satır (UNIQUE user_id). Reddedilen başvuru yeniden
--     gönderilince aynı satır güncellenir ve durum 'submitted'a döner.
--   • status: submitted (gönderildi, inceleme bekliyor) | reviewed ("incelendi"
--     — GK-9) | rejected (eksik/hatalı; review_note gerekçeyi taşır).
--     `active` bu tabloda YOK: satıcı aktifliği param_marketplace_sellers'ta
--     ve B1'e bağlı.
--   • Kişisel veri (IBAN, doğum tarihi, vergi no) burada; yalnızca sahibine
--     (maskeli) ve admin'e gösterilir, log'a yazılmaz.
--   • Kullanıcı silinince başvurusu da silinir (ON DELETE CASCADE — KVKK).
--
-- Salt ekleme: yeni tablo; mevcut veriye dokunmaz. Idempotent.
--
-- Geri alma: tabloyu kaldırmak (yıkıcı — kullanıcı çalıştırır). İçinde
-- başvuru varsa önce yedek.

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

-- Doğrulama
SELECT COUNT(*) AS tablo_var FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_applications';
