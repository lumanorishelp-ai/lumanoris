-- 015 — N-14: platform botu "Lumanoris AI" ücretsiz herkese açık
--
-- DURUM: KULLANICI ONAYLADI (2026-10-06) — GK-18, müşteri onayı yok.
-- Mevcut veriyi GÜNCELLEYEN migration (satır silmez; migrate.php yıkıcı
-- saymaz).
--
-- Sorun (N-14, yerelde doğrulandı): ana sayfa ve takip listesi bu botu HERKESE
-- varsayılan bot olarak sunuyor, ama bot 50 ₺ fiyatlı; abonesi olmayan
-- kullanıcı sohbet edemiyor (generateReply 403). Faz 4 (M10) ile fiyatsız
-- herkese açık bot = herkes sohbet eder (mesaj kullanıcının günlük coin'inden).
-- Fiyatı kaldırmak botu o sınıfa alır.
--
-- Eşleşme: ana sayfanın kullandığı ad (`HOUSE_BOT_NAME = "LUMANORIS AI"`,
-- büyük/küçük harf duyarsız) ve yayında olması. Kurulumdan kuruluma sahip
-- farklı (yerelde `lumanoris`, getFollowedBots `SYSTEM` arıyor), o yüzden
-- sahibe bakılmıyor. GÜVENLİK: tam olarak BİR eşleşme yoksa (0 ya da 2+)
-- hiçbir satır güncellenmez — yanlış bota dokunmaktansa hiç dokunmamak.
--
-- Mevcut abonelikler: dokunulmaz; süreleri dolana kadar sürer (ödenmiş).
-- Doğrulama SELECT'i aktif abonelik sayısını gösterir.
--
-- Geri alma: botun eski fiyatı doğrulama çıktısında yok — uygulamadan ÖNCE
-- `SELECT id, ucret_haftalik, ucret_aylik FROM chatbotlar WHERE UPPER(TRIM(isim)) = 'LUMANORIS AI'`
-- not alınmalı; geri almak için o değerlerle UPDATE.

UPDATE `chatbotlar`
SET `ucret_haftalik` = NULL,
    `ucret_aylik`    = NULL
WHERE `id` = (
    SELECT id FROM (
        SELECT MIN(id) AS id
        FROM `chatbotlar`
        WHERE UPPER(TRIM(`isim`)) = 'LUMANORIS AI' AND `is_independent` = 0
        HAVING COUNT(*) = 1
    ) AS tek
);

-- Doğrulama: fiyat NULL olmalı; eslesen = 1 olmalı.
SELECT c.id, c.isim, c.is_independent, c.ucret_haftalik, c.ucret_aylik,
       (SELECT COUNT(*) FROM `chatbotlar` x WHERE UPPER(TRIM(x.isim)) = 'LUMANORIS AI' AND x.is_independent = 0) AS eslesen,
       (SELECT COUNT(*) FROM user_subscriptions us WHERE us.chatbot_id = c.id AND us.status = 1 AND us.expiry_date > NOW()) AS aktif_abonelik
FROM `chatbotlar` c
WHERE UPPER(TRIM(c.isim)) = 'LUMANORIS AI';
