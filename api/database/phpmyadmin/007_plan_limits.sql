-- 007 — phpMyAdmin sürümü (information_schema / PREPARE yok). Asıl dosya:
-- migrations/007_plan_limits.sql. Koşulsuz: BİR KEZ çalıştırın.
-- Ön koşul: SHOW COLUMNS FROM `plans` LIKE 'independent_bot_limit'; → boş.

ALTER TABLE `plans`
  ADD COLUMN `independent_bot_limit` INT NOT NULL DEFAULT 1,
  ADD COLUMN `public_bot_limit` INT NOT NULL DEFAULT 2,
  ADD COLUMN `daily_message_limit` INT NOT NULL DEFAULT 10,
  ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0,
  ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `plans` ADD UNIQUE KEY `uq_plans_name_tr` (`name_tr`);

INSERT IGNORE INTO `plans`
    (`name_tr`, `name_en`, `monthly_price`, `yearly_price`, `currency`,
     `description_tr`,
     `independent_bot_limit`, `public_bot_limit`, `daily_message_limit`,
     `sort_order`, `is_default`)
VALUES
    ('Ücretsiz', 'Free',    0.00,   NULL, 1,
     'Lumanoris''i keşfetmeye başlamak için ücretsiz plan.',
     1,  2,  10, 0, 1),
    ('Gümüş',   'Silver',  149.00, NULL, 1,
     'Daha fazla mesaj hakkı ve gelişmiş özelliklerle bir üst seviyeye taşıyın.',
     3,  5,  50, 1, 0),
    ('Altın',   'Gold',    299.00, NULL, 1,
     'Yoğun kullanıcılar için genişletilmiş limitler ve öncelikli destek.',
     10, 15, 200, 2, 0),
    ('Elmas',   'Diamond', 599.00, NULL, 1,
     'Sınırsız imkanlar ve VIP destekle maksimum verim alın.',
     50, 50, 1000, 3, 0);

INSERT IGNORE INTO `plan_icerikler` (`plan_id`, `feature_tr`, `feature_en`)
SELECT p.id, f.feature_tr, f.feature_en
FROM `plans` p
JOIN (
    SELECT 'Ücretsiz' AS plan, 'Günlük 10 ücretsiz mesaj'          AS feature_tr, '10 free messages per day'       AS feature_en
    UNION ALL SELECT 'Ücretsiz', '1 bağımsız + 2 herkese açık chatbot', '1 private + 2 public chatbots'
    UNION ALL SELECT 'Gümüş',   'Artırılmış günlük mesaj hakkı',       'Increased daily message allowance'
    UNION ALL SELECT 'Gümüş',   'Daha fazla chatbot oluşturma limiti',  'Higher chatbot creation limit'
    UNION ALL SELECT 'Gümüş',   'Öncelikli destek',                     'Priority support'
    UNION ALL SELECT 'Altın',   'Yüksek günlük mesaj hakkı',           'High daily message allowance'
    UNION ALL SELECT 'Altın',   'Genişletilmiş chatbot limiti',        'Extended chatbot limit'
    UNION ALL SELECT 'Altın',   'Öncelikli destek',                     'Priority support'
    UNION ALL SELECT 'Altın',   'Gelişmiş istatistikler',              'Advanced statistics'
    UNION ALL SELECT 'Elmas',   'Sınırsıza yakın mesaj hakkı',        'Near-unlimited message allowance'
    UNION ALL SELECT 'Elmas',   'Sınırsıza yakın chatbot oluşturma',   'Near-unlimited chatbot creation'
    UNION ALL SELECT 'Elmas',   '7/24 VIP destek',                      '24/7 VIP support'
    UNION ALL SELECT 'Elmas',   'Gelişmiş istatistikler',              'Advanced statistics'
) f ON f.plan = p.name_tr
WHERE NOT EXISTS (
    SELECT 1 FROM `plan_icerikler` pi
    WHERE pi.plan_id = p.id AND pi.feature_tr = f.feature_tr
);

-- Doğrulama: 4 satır
SELECT p.name_tr, p.monthly_price,
       p.independent_bot_limit, p.public_bot_limit, p.daily_message_limit,
       p.is_default, COUNT(pi.id) AS ozellik
FROM `plans` p
LEFT JOIN `plan_icerikler` pi ON pi.plan_id = p.id
GROUP BY p.id ORDER BY p.sort_order;
