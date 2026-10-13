-- 017 — Admin işlem logu (GK-23: başvuru IBAN'ının banka_bilgileri'ne aktarımı)
--
-- DURUM: ONAYLANDI (kullanıcı, 2026-10-07).
--
--
-- Neden: GK-23, admin "incelendi" dediğinde başvurudaki IBAN'ın kullanıcının
-- para çekme IBAN'ına (banka_bilgileri.iban) yazılmasını ve bu değişikliğin
-- (kim, ne zaman, eski ve yeni IBAN maskeli) KALICI kayıt altına alınmasını
-- istiyor. Kod tabanında mevcut bir admin işlem logu YOK: iade denetimi bile
-- yalnızca error_log'a yazılıyor (döner, silinir, aranamaz). Bu tablo o
-- boşluğu genel amaçlı kapatır; ilk kullanıcısı IBAN aktarımı olur, ileride
-- iade / çekim durumu / başvuru kararları da buraya yazılabilir.
--
-- Tasarım:
--   • Silinmez, güncellenmez (uygulama yalnızca INSERT eder).
--   • Kullanıcıya FK YOK: kullanıcı silinse de denetim kaydı kalmalı.
--   • admin_name: env tabanlı admin hesaplarında admin_id olmayabilir
--     ($_SESSION['admin_source'] = 'env'); kim olduğu yine kayıtlı kalsın.
--   • details: yalnızca MASKELİ değerler (ör. {"old_iban":"TR** … 1234",
--     "new_iban":"TR** … 5678","application_id":7}). Tam IBAN yazılmaz.
--
-- Onaydan sonra kod (öneri; bu dosyayla birlikte yazılacak):
--   admin/ajax/basvurular.php POST status=reviewed içinde, aynı transaction'da:
--     1. Kullanıcının `para_cekme_talepleri.durum = 'beklemede'` talebi varsa
--        IBAN GÜNCELLENMEZ; başvuru yine "incelendi" olur, yanıt admin'e
--        "bekleyen çekim talebi var, IBAN aktarılmadı" uyarısını döner.
--     2. Yoksa banka_bilgileri.iban ← başvuru IBAN'ı (satır yoksa user_id + iban
--        ile eklenir), ve bu tabloya action='iban_update_from_application'
--        satırı yazılır (eski/yeni maskeli). IBAN aynıysa yazım ve log yok.
--   Liste uç noktasındaki `iban_transfer_enabled` true olur.
--
-- Salt ekleme; idempotent. Geri alma: tabloyu kaldırmak (yıkıcı — kullanıcı).

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

-- Doğrulama
SELECT COUNT(*) AS tablo_var FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_log';
