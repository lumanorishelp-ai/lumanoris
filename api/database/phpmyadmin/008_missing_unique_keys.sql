-- 008 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/008_missing_unique_keys.sql. Koşulsuz: BİR KEZ çalıştırın.
--
-- ÖNCE bu üç sorguyu AYRI çalıştırın; üçü de 0 değilse bu dosyayı
-- ÇALIŞTIRMAYIN (yinelenen satır varken UNIQUE eklenemez, #1062):
--   SELECT COUNT(*) FROM (SELECT 1 FROM chatbot_hide GROUP BY user_id, chatbot_id HAVING COUNT(*) > 1) x;
--   SELECT COUNT(*) FROM (SELECT 1 FROM chatbot_uninterested GROUP BY user_id, category_id HAVING COUNT(*) > 1) x;
--   SELECT COUNT(*) FROM (SELECT 1 FROM chatbot_in_list GROUP BY list_id, chatbot_id HAVING COUNT(*) > 1) x;

ALTER TABLE `chatbot_hide` ADD UNIQUE KEY `uq_chatbot_hide_user_chatbot` (`user_id`, `chatbot_id`);
ALTER TABLE `chatbot_uninterested` ADD UNIQUE KEY `uq_chatbot_uninterested_user_category` (`user_id`, `category_id`);
ALTER TABLE `chatbot_in_list` ADD UNIQUE KEY `uq_chatbot_in_list_list_chatbot` (`list_id`, `chatbot_id`);

-- Doğrulama: her biri 1 satır (Non_unique = 0)
SHOW INDEX FROM `chatbot_hide` WHERE Key_name = 'uq_chatbot_hide_user_chatbot' AND Seq_in_index = 1;
SHOW INDEX FROM `chatbot_uninterested` WHERE Key_name = 'uq_chatbot_uninterested_user_category' AND Seq_in_index = 1;
SHOW INDEX FROM `chatbot_in_list` WHERE Key_name = 'uq_chatbot_in_list_list_chatbot' AND Seq_in_index = 1;
