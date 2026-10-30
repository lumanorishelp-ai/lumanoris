-- 020 — Bilgi bankası (RAG): kaynaklar, parçalar + embedding'ler, yapay zekâ kullanım kaydı
--
-- Bu dosya VERİ SİLMEZ, satır değiştirmez. Yalnızca yeni tablo EKLER.
--
-- ── Neden ────────────────────────────────────────────────────────────────
-- "Eğitim" bugün chatbotlar.training_prompt metni (≤ 60.000 karakter);
-- hangi belgeden/URL'den geldiği tutulmuyor, tek tek kaldırılamıyor ve her
-- soruda anahtar kelimeyle seçilen parçalar modele gidiyor. RAG ile:
--   kb_sources  — her yüklenen belge/URL/metin bir satır; durum makinesi
--                 (beklemede → isleniyor → hazir | basarisiz), cron işçisi
--                 kilitler (locked_at/locked_by), yarım kalanı geri alır.
--   kb_chunks   — kaynağın parçaları; embedding float32 dizisi (little-endian)
--                 olarak BLOB'da, aday kümesi chatbot_id ile daraltılıp
--                 benzerlik PHP'de hesaplanıyor (ANALIZ.md §7).
--   ai_usage_log — her model çağrısı: token, model, gecikme (maliyet/limit).
--
-- chatbotlar.training_prompt'a dokunulmuyor; RAG verisi yokken sohbet eski
-- yola düşüyor (geçiş güvenli, sıra: önce kod ya da önce bu dosya).
--
-- Idempotent: tablolar zaten varsa hiçbir şey yapmaz.
-- Geri alma (ayrı, onaylı ters migration; bilgi bankası verisi silinir):
--   DROP TABLE kb_chunks; DROP TABLE kb_sources; DROP TABLE ai_usage_log;

CREATE TABLE IF NOT EXISTS `kb_sources` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chatbot_id`      int(10) unsigned NOT NULL,
  `user_id`         int(11) NOT NULL COMMENT 'yükleyen',
  `type`            enum('file','url','text') NOT NULL,
  `name`            varchar(255) NOT NULL COMMENT 'dosya adı / URL / metin başlığı',
  `url`             varchar(2048) DEFAULT NULL,
  `mime`            varchar(127) DEFAULT NULL,
  `size_bytes`      int(10) unsigned NOT NULL DEFAULT 0,
  `content`         longtext DEFAULT NULL COMMENT 'çıkarılmış düz metin; işçi bundan parçalar',
  `status`          enum('beklemede','isleniyor','hazir','basarisiz') NOT NULL DEFAULT 'beklemede',
  `error_code`      varchar(64) DEFAULT NULL,
  `char_count`      int(10) unsigned NOT NULL DEFAULT 0,
  `chunk_count`     int(10) unsigned NOT NULL DEFAULT 0,
  `attempts`        tinyint(3) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `locked_at`       datetime DEFAULT NULL,
  `locked_by`       varchar(64) DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kb_sources_chatbot` (`chatbot_id`, `status`),
  KEY `idx_kb_sources_queue` (`status`, `next_attempt_at`),
  CONSTRAINT `fk_kb_sources_chatbot` FOREIGN KEY (`chatbot_id`) REFERENCES `chatbotlar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kb_chunks` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_id`       int(10) unsigned NOT NULL,
  `chatbot_id`      int(10) unsigned NOT NULL,
  `chunk_index`     int(10) unsigned NOT NULL,
  `heading`         varchar(255) DEFAULT NULL COMMENT 'parçanın altında durduğu başlık',
  `content`         mediumtext NOT NULL,
  `char_start`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'kaynak metindeki konum',
  `embedding`       mediumblob DEFAULT NULL COMMENT 'float32 LE dizisi',
  `embedding_model` varchar(64) DEFAULT NULL,
  `dims`            smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kb_chunks_chatbot` (`chatbot_id`),
  KEY `idx_kb_chunks_source` (`source_id`, `chunk_index`),
  CONSTRAINT `fk_kb_chunks_source` FOREIGN KEY (`source_id`) REFERENCES `kb_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_usage_log` (
  `id`             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`        int(11) DEFAULT NULL,
  `chatbot_id`     int(10) unsigned DEFAULT NULL,
  `kind`           enum('chat','embedding') NOT NULL,
  `model`          varchar(64) NOT NULL,
  `input_tokens`   int(10) unsigned NOT NULL DEFAULT 0,
  `cached_tokens`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'önbellekten okunan (0,1× fiyat)',
  `cache_write_tokens` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'önbelleğe yazılan (1,25× fiyat)',
  `output_tokens`  int(10) unsigned NOT NULL DEFAULT 0,
  `latency_ms`     int(10) unsigned NOT NULL DEFAULT 0,
  `outcome`        varchar(32) NOT NULL DEFAULT 'ok',
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_user_time` (`user_id`, `created_at`),
  KEY `idx_ai_usage_bot_time` (`chatbot_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doğrulama: 3 satır
SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('kb_sources','kb_chunks','ai_usage_log');
