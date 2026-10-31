ALTER TABLE `chatbot_chats`
  ADD COLUMN IF NOT EXISTS `conversation_id` int DEFAULT NULL AFTER `user_id`,
  ADD INDEX IF NOT EXISTS `idx_chatbot_chats_conversation` (`conversation_id`, `id`);

UPDATE `chatbot_chats` c
   SET c.`conversation_id` = (
         SELECT cv.`id` FROM `chatbot_conversations` cv
          WHERE cv.`user_id` = c.`user_id`
            AND cv.`chatbot_id` = c.`chatbot_id`
            AND cv.`started_at` <= c.`sent_time`
          ORDER BY cv.`started_at` DESC, cv.`id` DESC
          LIMIT 1)
 WHERE c.`conversation_id` IS NULL;

SELECT COUNT(*) AS toplam,
       SUM(`conversation_id` IS NOT NULL) AS sohbete_baglanan,
       SUM(`conversation_id` IS NULL) AS sohbetsiz
  FROM `chatbot_chats`;
