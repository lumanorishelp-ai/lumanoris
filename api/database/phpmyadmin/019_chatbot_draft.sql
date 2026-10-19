-- 019 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/019_chatbot_draft.sql (N-68).
--
-- Veri silmez, satır değiştirmez; chatbotlar'a is_draft sütunu (varsayılan 0)
-- ve bir indeks ekler. Mevcut botlar taslak sayılmaz; davranış değişmez.
--
-- Ön kontrol (ayrı çalıştırın): sonuç boşsa bu dosya gerekli; satır dönüyorsa
-- sütun zaten var, ÇALIŞTIRMAYIN (ikinci kez "Duplicate column" hatası verir):
--   SHOW COLUMNS FROM `chatbotlar` LIKE 'is_draft';

ALTER TABLE `chatbotlar`
  ADD COLUMN `is_draft` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=taslak (sihirbaz 1. adımda kaydedildi, henüz yayınlanmadı/özel kaydedilmedi)' AFTER `is_independent`,
  ADD INDEX `idx_chatbot_author_draft` (`author_user_id`, `is_draft`);

-- migrate.php'nin kayıt tablosu VARSA (SHOW TABLES LIKE 'schema_migrations';)
-- bu satırı da çalıştırın; deploy betiği 019'u tekrar uygulamaya kalkmasın.
-- Checksum, migrations/019_chatbot_draft.sql'in migrate.php ile hesaplanan
-- değeri (dosya değişirse değişir):
-- INSERT INTO schema_migrations (filename, checksum, applied_at) VALUES ('019_chatbot_draft.sql', '8b74b3c36208f36f20eb176be5421ade54a5f107adec159e8601ed4063228d51', NOW());

-- Doğrulama: 1 satır, Default 0
SHOW COLUMNS FROM `chatbotlar` LIKE 'is_draft';
