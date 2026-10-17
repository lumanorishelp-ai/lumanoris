-- 018 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/018_user_plan_selection_collation.sql (N-49).
--
-- Veri silmez, satır değiştirmez; yalnızca user_plan_selection'ın collation'ını
-- plans ile aynı yapar (utf8mb4_general_ci). Tekrar çalıştırmak zararsız
-- (zaten general_ci ise aynı şeyi yeniden yazar).
--
-- Ön kontrol (ayrı çalıştırın): plans general_ci olmalı, plan_name değilse
-- bu dosya gerekli:
--   SHOW FULL COLUMNS FROM plans WHERE Field = 'name_tr';
--   SHOW FULL COLUMNS FROM user_plan_selection WHERE Field = 'plan_name';

ALTER TABLE `user_plan_selection` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Doğrulama: iki satırın Collation sütunu da utf8mb4_general_ci olmalı
SHOW FULL COLUMNS FROM `plans` WHERE Field = 'name_tr';
SHOW FULL COLUMNS FROM `user_plan_selection` WHERE Field = 'plan_name';
