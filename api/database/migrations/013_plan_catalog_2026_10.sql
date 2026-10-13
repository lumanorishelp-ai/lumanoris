-- 013 — Paket kataloğu: müşteri paket tablosu (AUDIT.md, Faz 3)
--
-- DURUM: ONAYLANDI (kullanıcı, 2026-10-06). Mevcut veriyi GÜNCELLEYEN migration;
-- onay alındı.
--
--
-- 012'ye BAĞIMLI (privacy_right_limit kolonu ve NULL = sınırsız).
--
-- DELETE YOK: önceki sürüm `plan_icerikler` satırlarını silip yeniden
-- ekliyordu (--allow-destructive gerektiriyordu). Bu sürüm her planın mevcut
-- özellik satırlarını id sırasıyla YENİ metinlerle günceller, yeni listede
-- fazla kalan maddeleri ekler. Yerel ölçüm (2026-10-06): mevcut satır sayısı
-- Ücretsiz 2 / Gümüş 3 / Altın 4 / Elmas 4, yeni liste 4 / 5 / 6 / 6 — yani
-- her planda mevcut satırlar yeni listeye sığıyor. Bir planda yeni listeden
-- FAZLA satır varsa (ör. admin panelden eklenmiş), fazlası dokunulmadan kalır
-- ve en alttaki doğrulama sorgusu onları "FAZLA_ESKI_SATIR" olarak listeler.
--
-- migrate.php bu dosyayı yıkıcı SAYMAZ (satır başında DELETE/TRUNCATE/DROP
-- yok) → `--apply` yeterli. Tek transaction: geçici tablo (TEMPORARY)
-- örtük COMMIT yapmaz; hata olursa tamamı geri alınır.
--
-- Idempotent: tekrar çalışırsa aynı metinleri tekrar yazar, ekleme yapmaz
-- (sayılar artık eşit).
--
-- ── Değişen değerler (007 seed'ine göre) ────────────────────────────────
--   Altın : coin 200 → 100, bağımsız 10 → 5, herkese açık 15 → 10
--   Elmas : 599 → 849 ₺, coin 1000 → 200, bağımsız/herkese açık 50 → sınırsız (NULL)
--   Hak   : Ücretsiz 1, Gümüş 3, Altın 5, Elmas 20 (toplam — GK-17)
--   Ücretsiz ve Gümüş fiyat/coin/bot limitleri değişmiyor.
--
-- ── Geri alma notu ───────────────────────────────────────────────────────
-- Plan değerleri: 007_plan_limits.sql'deki değerlerle aynı UPDATE'ler
-- (privacy_right_limit = 1). Özellik metinleri: 007'nin BÖLÜM 3'ündeki
-- metinler ilk satırlara geri yazılır; bu dosyanın EKLEDİĞİ satırlar ancak
-- silinerek kaldırılabilir (o adım yıkıcıdır, kullanıcı çalıştırır).

UPDATE `plans` SET `monthly_price` = 0.00,   `daily_message_limit` = 10,
       `independent_bot_limit` = 1,    `public_bot_limit` = 2,    `privacy_right_limit` = 1
 WHERE `name_tr` = 'Ücretsiz';

UPDATE `plans` SET `monthly_price` = 149.00, `daily_message_limit` = 50,
       `independent_bot_limit` = 3,    `public_bot_limit` = 5,    `privacy_right_limit` = 3
 WHERE `name_tr` = 'Gümüş';

UPDATE `plans` SET `monthly_price` = 299.00, `daily_message_limit` = 100,
       `independent_bot_limit` = 5,    `public_bot_limit` = 10,   `privacy_right_limit` = 5
 WHERE `name_tr` = 'Altın';

UPDATE `plans` SET `monthly_price` = 849.00, `daily_message_limit` = 200,
       `independent_bot_limit` = NULL, `public_bot_limit` = NULL, `privacy_right_limit` = 20
 WHERE `name_tr` = 'Elmas';

-- Yeni özellik listesi (paket tablosuyla birebir). Oturuma özel; bağlantı
-- kapanınca kendiliğinden kalkar.
CREATE TEMPORARY TABLE `tmp_plan_features_013` (
  `plan`       varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `sira`       int NOT NULL,
  `feature_tr` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `feature_en` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`plan`, `sira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tmp_plan_features_013` (`plan`, `sira`, `feature_tr`, `feature_en`) VALUES
  ('Ücretsiz', 1, 'Günlük 10 Luma Coin',                     '10 Luma Coins per day'),
  ('Ücretsiz', 2, '1 bağımsız chatbot',                      '1 private chatbot'),
  ('Ücretsiz', 3, '2 herkese açık chatbot',                  '2 public chatbots'),
  ('Ücretsiz', 4, '1 yayından kaldırma (özel yapma) hakkı',  '1 unpublish (make private) right'),

  ('Gümüş',    1, 'Günlük 50 Luma Coin',                     '50 Luma Coins per day'),
  ('Gümüş',    2, '3 bağımsız chatbot',                      '3 private chatbots'),
  ('Gümüş',    3, '5 herkese açık chatbot',                  '5 public chatbots'),
  ('Gümüş',    4, '3 yayından kaldırma (özel yapma) hakkı',  '3 unpublish (make private) rights'),
  ('Gümüş',    5, 'Diğer tüm özelliklerde sınırsız deneyim', 'Unlimited experience in all other features'),

  ('Altın',    1, 'Günlük 100 Luma Coin',                    '100 Luma Coins per day'),
  ('Altın',    2, '5 bağımsız chatbot',                      '5 private chatbots'),
  ('Altın',    3, '10 herkese açık chatbot',                 '10 public chatbots'),
  ('Altın',    4, '5 yayından kaldırma (özel yapma) hakkı',  '5 unpublish (make private) rights'),
  ('Altın',    5, 'Öncelikli destek',                        'Priority support'),
  ('Altın',    6, 'Tüm Lumanoris deneyimlerine erişim',      'Access to all Lumanoris experiences'),

  ('Elmas',    1, 'Günlük 200 Luma Coin',                    '200 Luma Coins per day'),
  ('Elmas',    2, 'Sınırsız bağımsız chatbot',               'Unlimited private chatbots'),
  ('Elmas',    3, 'Sınırsız herkese açık chatbot',           'Unlimited public chatbots'),
  ('Elmas',    4, '20 yayından kaldırma (özel yapma) hakkı', '20 unpublish (make private) rights'),
  ('Elmas',    5, '7/24 VIP destek',                         '24/7 VIP support'),
  ('Elmas',    6, 'Yeni özelliklere erken erişim',           'Early access to new features');

-- 1) Mevcut satırları id sırasıyla yeni metinlerle güncelle.
--    ROW_NUMBER penceresi türetilmiş tabloyu somutlaştırıyor (MySQL 8), bu
--    yüzden hedef tabloyu FROM'da okumak 1093 hatası vermiyor.
UPDATE `plan_icerikler` pi
JOIN (
    SELECT pi2.id, p.name_tr,
           ROW_NUMBER() OVER (PARTITION BY pi2.plan_id ORDER BY pi2.id) AS sira
    FROM `plan_icerikler` pi2
    JOIN `plans` p ON p.id = pi2.plan_id
    WHERE p.name_tr IN ('Ücretsiz', 'Gümüş', 'Altın', 'Elmas')
) r ON r.id = pi.id
JOIN `tmp_plan_features_013` f ON f.plan = r.name_tr AND f.sira = r.sira
SET pi.feature_tr = f.feature_tr,
    pi.feature_en = f.feature_en;

-- 2) Yeni listede mevcut satır sayısını aşan maddeleri ekle.
INSERT INTO `plan_icerikler` (`plan_id`, `feature_tr`, `feature_en`)
SELECT p.id, f.feature_tr, f.feature_en
FROM `tmp_plan_features_013` f
JOIN `plans` p ON p.name_tr = f.plan
WHERE f.sira > (SELECT COUNT(*) FROM `plan_icerikler` x WHERE x.plan_id = p.id)
ORDER BY p.sort_order, f.sira;

-- Doğrulama 1 — plan değerleri ve özellik sayısı
SELECT p.name_tr, p.monthly_price, p.daily_message_limit,
       p.independent_bot_limit, p.public_bot_limit, p.privacy_right_limit,
       COUNT(pi.id) AS ozellik
FROM `plans` p
LEFT JOIN `plan_icerikler` pi ON pi.plan_id = p.id
GROUP BY p.id ORDER BY p.sort_order;

-- Doğrulama 2 — yeni listeden fazla kalan (dokunulmamış) eski satırlar.
-- Boş dönmeli; dönerse admin panelinden elle temizlenmeli.
SELECT 'FAZLA_ESKI_SATIR' AS durum, p.name_tr, pi.id, pi.feature_tr
FROM `plan_icerikler` pi
JOIN `plans` p ON p.id = pi.plan_id
WHERE p.name_tr IN ('Ücretsiz', 'Gümüş', 'Altın', 'Elmas')
  AND pi.feature_tr NOT IN (SELECT feature_tr FROM `tmp_plan_features_013` t WHERE t.plan = p.name_tr);
