<?php
/**
 * Coin / message-credit engine.
 *
 * getActivePurchaseCredit()    — returns active (non-expired) purchase credit row for a user+chatbot pair.
 * getOrInitCoinBalance()       — returns (creating/resetting as needed) the user's daily coin balance row.
 * consumeMessage()             — decrements the appropriate credit counter and returns result.
 * calculateMessageAllowance()  — bonus message credits granted for a given paid amount (PHP mirror
 *                                 of the same formula in web/src/shared/lib/pricing.js).
 */

function getActivePurchaseCredit(Database $db, int $userId, int $chatbotId): ?array {
    return $db->selectSingle(
        'id, credits_remaining FROM ' . AppConfig::TABLE_PURCHASE_CREDITS . '
         WHERE user_id = ? AND chatbot_id = ? AND credits_remaining > 0
           AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY id ASC LIMIT 1',
        [$userId, $chatbotId]
    ) ?: null;
}

/**
 * COIN-002 🟡 / COIN-003 🟡 — iki ayrı sorun, tek düzeltme.
 *
 * COIN-003 (timezone): sıfırlama kararı PHP'nin `date()`/`time()` fonksiyonlarıyla
 * veriliyordu, `last_reset_date` ve `exhausted_at` ise MySQL'de saklanıyor.
 * PHP ve MySQL farklı saat dilimlerinde çalışabiliyor (bu projede yerelde
 * gözlendi: PHP=UTC, MySQL=UTC+3). Ayrım üç saatlik bir pencerede yanlış
 * karar veriyordu: kullanıcı ya haksız yere bir gün daha bekliyor ya da
 * günlük hakkını iki kez alıyordu. Aynı hata şifre sıfırlamada da vardı ve
 * orada NOW() + INTERVAL kullanılarak çözülmüştü — burada da öyle.
 *
 * COIN-002 (yarış): SELECT ile karar verilip ayrı bir UPDATE ile sıfırlanıyordu.
 * Aynı anda gelen iki istek de "sıfırlanmalı" görüp ikisi de UPDATE atıyordu;
 * ilki sıfırlıyor, kullanıcı bir mesaj harcıyor, ikincisi tekrar sıfırlıyordu
 * — yani günlük limit sessizce ikiye katlanabiliyordu.
 *
 * Artık karar da yazma da tek bir atomik UPDATE'te, tamamen SQL saatinde:
 * koşul WHERE'de, yani iki eşzamanlı istekten yalnızca biri satırı
 * güncelleyebiliyor.
 */
function getOrInitCoinBalance(Database $db, int $userId): array {
    // BIZ-002: günlük mesaj hakkı artık kullanıcının PLANINDAN geliyor.
    // Eskiden sabit AppConfig::DAILY_FREE_MESSAGES (10) idi ve ücretli plan
    // seçmiş bir kullanıcı da aynı 10 mesajı alıyordu. Migration 007
    // uygulanmamışsa fallbackPlan() yine 10 döndürüyor, yani davranış
    // değişmiyor.
    require_once __DIR__ . '/plans.php';
    $dailyLimit = getDailyMessageLimit($db, $userId);

    $selectQuery = 'coins_remaining, last_reset_date, exhausted_at FROM ' . AppConfig::TABLE_COIN_BALANCES . '
         WHERE user_id = ?';
    $row = $db->selectSingle($selectQuery, [$userId]);

    if (!$row) {
        try {
            $db->execute(
                'INSERT INTO ' . AppConfig::TABLE_COIN_BALANCES . '
                     (user_id, coins_remaining, last_reset_date, exhausted_at)
                 VALUES (?, ?, CURDATE(), NULL)',
                [$userId, $dailyLimit]
            );
            return ['coins_remaining' => $dailyLimit, 'exhausted_at' => null];
        } catch (Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }

            // A concurrent first message from the same user (two tabs) beat us
            // to the INSERT. PRIMARY KEY(user_id) is what stopped the duplicate,
            // so no data is corrupted — but the losing request used to surface
            // the violation as a 500. Re-read the row the winner just wrote.
            //
            // Deliberately NOT insert(..., updateOnDuplicate: true): that would
            // rewrite coins_remaining back to the daily allowance, and if the
            // winning request had already spent from it the loser would silently
            // hand out free messages.
            $row = $db->selectSingle($selectQuery, [$userId]);
            if (!$row) {
                throw $e;
            }
        }
    }

    // Two mutually exclusive renewal rules (spec: exhausted balances renew 24h
    // after the exhausting message; untouched balances renew at midnight).
    // Checking the calendar date alone — regardless of exhausted_at — used to
    // let a user who ran out at 23:59 renew a minute later instead of waiting
    // the full 24h, so the two rules are applied separately — ama artık ikisi
    // de SQL'de, tek ifadede.
    $resetRows = $db->execute(
        'UPDATE ' . AppConfig::TABLE_COIN_BALANCES . '
         SET coins_remaining = ?,
             last_reset_date = CURDATE(),
             exhausted_at    = NULL
         WHERE user_id = ?
           AND (
                (exhausted_at IS NOT NULL AND exhausted_at <= NOW() - INTERVAL 1 DAY)
             OR (exhausted_at IS NULL     AND (last_reset_date IS NULL OR last_reset_date <> CURDATE()))
           )',
        [$dailyLimit, $userId]
    );

    if ($resetRows > 0) {
        return ['coins_remaining' => $dailyLimit, 'exhausted_at' => null];
    }

    // Sıfırlanmadı (ya da bu yarışı başka bir istek kazandı) — güncel değeri
    // yeniden okuyoruz, yukarıdaki $row bayatlamış olabilir.
    $row = $db->selectSingle($selectQuery, [$userId]);
    return [
        'coins_remaining' => (int) ($row['coins_remaining'] ?? 0),
        'exhausted_at'    => $row['exhausted_at'] ?? null,
    ];
}

/**
 * Both branches below decrement with an atomic
 * `UPDATE ... SET x = x - 1 WHERE x > 0` guarded by the affected row count,
 * rather than reading a value in PHP and writing back a computed one — two
 * concurrent requests racing on a read-then-write would otherwise both read
 * the same remaining count and both succeed, granting an extra free message
 * past what the user actually has left.
 */
function consumeMessage(Database $db, int $userId, int $chatbotId): array {
    $credit = getActivePurchaseCredit($db, $userId, $chatbotId);

    if ($credit) {
        $conn = $db->getConnection();
        $stmt = $conn->prepare(
            'UPDATE ' . AppConfig::TABLE_PURCHASE_CREDITS . '
             SET credits_remaining = credits_remaining - 1
             WHERE id = ? AND credits_remaining > 0'
        );
        $stmt->execute([(int) $credit['id']]);
        if ($stmt->rowCount() > 0) {
            return ['allowed' => true, 'source' => 'purchase_credit', 'remaining' => (int) $credit['credits_remaining'] - 1];
        }
        // Exhausted by a concurrent request between the read above and this
        // update — fall through and try the daily coin balance instead.
    }

    getOrInitCoinBalance($db, $userId); // ensure today's balance row exists/has been reset

    $conn = $db->getConnection();
    $stmt = $conn->prepare(
        'UPDATE ' . AppConfig::TABLE_COIN_BALANCES . '
         SET coins_remaining = coins_remaining - 1,
             exhausted_at = IF(coins_remaining - 1 = 0, NOW(), exhausted_at)
         WHERE user_id = ? AND coins_remaining > 0'
    );
    $stmt->execute([$userId]);
    if ($stmt->rowCount() === 0) {
        return ['allowed' => false, 'source' => 'coins', 'remaining' => 0];
    }

    $remaining = (int) $db->selectSingle(
        'coins_remaining FROM ' . AppConfig::TABLE_COIN_BALANCES . ' WHERE user_id = ?',
        [$userId]
    )['coins_remaining'];

    return ['allowed' => true, 'source' => 'coins', 'remaining' => $remaining];
}

// Keep in sync with COIN_TIER_* in web/src/shared/lib/pricing.js. (The tiers
// used to live in BuyModal.jsx, which this comment still pointed at long
// after they were consolidated into pricing.js.)
const COIN_TIER_BASE = 150;
const COIN_TIER_STEP = 100;
const COIN_TIER_CAP  = 1000;

function calculateMessageAllowance(float $totalPaid): int {
    if ($totalPaid < 100) return 0;
    $tier = (int) floor($totalPaid / 100);
    return min(COIN_TIER_CAP, COIN_TIER_BASE + ($tier - 1) * COIN_TIER_STEP);
}

/**
 * PAY-003 🟠 — düz INSERT, UNIQUE(user_id, chatbot_id) kısıtına rağmen.
 *
 * Docblock "tops up" (üzerine ekle) diyordu, gövde düz `insert` yapıyordu.
 * Tabloda `UNIQUE KEY user_chatbot (user_id, chatbot_id)` olduğu için aynı
 * botun İKİNCİ kez satın alınması "Duplicate entry" ile 500 veriyordu; üstelik
 * bu çağrı createSubscription'ın transaction'ının içinde olduğundan tüm
 * checkout geri alınıyordu. Yani **abonelik yenileme hiç mümkün değildi.**
 *
 * Doğru davranış üzerine eklemek: kalan hak sıfırlanmamalı (kullanıcı ödedi),
 * toplam da büyümeli ki "kaç hakkın vardı / kaçı kaldı" oranı doğru kalsın.
 * Süre de uzuyor: yeni bitiş tarihi eskisinden ileriyse o geçerli olur.
 */
function grantPurchaseCredit(Database $db, int $userId, int $chatbotId, float $totalPaid, string $expiresAt): void {
    $allowance = calculateMessageAllowance($totalPaid);
    if ($allowance <= 0) return;

    $db->execute(
        'INSERT INTO ' . AppConfig::TABLE_PURCHASE_CREDITS . '
             (user_id, chatbot_id, credits_remaining, credits_total, expires_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             credits_remaining = credits_remaining + VALUES(credits_remaining),
             credits_total     = credits_total + VALUES(credits_total),
             -- I-11: burası düz `GREATEST(expires_at, VALUES(expires_at))` idi.
             -- MySQL\'de GREATEST argümanlarından biri NULL ise sonuç NULL, ve
             -- `expires_at IS NULL` bu tabloda DESTEKLENEN bir durum ("süresiz").
             -- Yani süresiz krediye sahip bir kullanıcı aynı botu yeniden satın
             -- aldığında satır NULL kalmaya devam ediyordu — ödediği yeni süre
             -- hiçbir şeye yazılmıyordu. COALESCE ile NULL, gelen değere
             -- düşürülüyor: sonuç her zaman iki tarihten ileri olanı.
             expires_at        = GREATEST(COALESCE(expires_at, VALUES(expires_at)), VALUES(expires_at))',
        [$userId, $chatbotId, $allowance, $allowance, $expiresAt]
    );
}

/**
 * COIN-004 / AI-005 — iade yolu.
 *
 * `chat/page.jsx` coin'i Gemini çağrısından ÖNCE yakıyordu ve çağrı
 * başarısız olduğunda hiçbir iade yolu yoktu: kullanıcı cevap alamadan
 * hakkını kaybediyordu. Sunucu tarafına taşınan akışta da tüketim önce
 * yapılıyor (aksi halde limit atlatılabilir), ama upstream hata verirse
 * tüketim geri alınıyor.
 *
 * $source, consumeMessage()'ın döndürdüğü 'purchase_credit' | 'coins'.
 */
function refundMessage(Database $db, int $userId, int $chatbotId, string $source): void {
    try {
        if ($source === 'purchase_credit') {
            $credit = getActivePurchaseCredit($db, $userId, $chatbotId);
            if ($credit) {
                $db->execute(
                    'UPDATE ' . AppConfig::TABLE_PURCHASE_CREDITS . '
                     SET credits_remaining = LEAST(credits_total, credits_remaining + 1)
                     WHERE id = ?',
                    [(int) $credit['id']]
                );
                return;
            }
            // Kredi bu arada süresi dolduysa günlük havuza iade et.
        }

        // I-10 — günlük havuza iade TAVANSIZDI (`coins_remaining + 1`), oysa
        // kardeş dal (satın alma kredisi, yukarıda) `LEAST(credits_total, …)`
        // ile sınırlıyor. Upstream'in art arda hata verdiği bir dakikada
        // kullanıcı günlük hakkının ÜSTÜNE çıkabiliyordu: her başarısız
        // istek tüketip iade ediyor, ama iade tavanı olmadığı için bakiye
        // plan limitini aşabiliyordu. Tavan plandan okunuyor.
        $dailyLimit = getDailyMessageLimit($db, $userId);
        $db->execute(
            'UPDATE ' . AppConfig::TABLE_COIN_BALANCES . '
             SET coins_remaining = LEAST(?, coins_remaining + 1),
                 exhausted_at = NULL
             WHERE user_id = ?',
            [$dailyLimit, $userId]
        );
    } catch (Throwable $e) {
        // İade başarısız olsa da kullanıcıya hata mesajı gitmeli; sessizce
        // yutmak yerine logluyoruz ki kayıp haklar görünür olsun.
        error_log(sprintf(
            '[coin_engine] refundMessage failed user=%d bot=%d source=%s: %s',
            $userId, $chatbotId, $source, $e->getMessage()
        ));
    }
}

// ── N-71 — paketin aktifleştiği günün coin hakkı ─────────────────────────────
//
// Karar (2026-10-29, müşteriye bildirilecek kural): ücretli paket ödemeyle
// aktifleştiği anda o günün bakiyesi, iki paketin günlük hakkı arasındaki fark
// kadar artar (kalan + fark). Eskiden yeni hak ancak ertesi günkü yenilemede
// geliyordu: Gümüş (50) alan kullanıcı o gün ücretsizden kalan 10 ile kalıyordu.
//
//   - Aynı gün ikinci kez verilmez: aynı paket için bugün verilmiş ve geri
//     alınmamış fark düşülür; aynı paketin yenilenmesinde fark zaten 0.
//   - İade edilen pakette fark geri alınır (reclaimPlanUpgradeCoins); bakiye
//     sıfırın altına inmez. Harcanmış kısım geri alınamaz ama aynı gün yeniden
//     satın almada tekrar da verilmez.
//   - Bakiye bugün henüz yenilenmemişse (ya da yenilenme zamanı geldiyse) fark
//     verilmez: ilk kullanımda getOrInitCoinBalance zaten yeni paketin TAM
//     hakkıyla yeniler.
//
// Verilen fark sipariş satırında (param_response_json.coin_bonus) kayıtlı:
// {amount, date, plan, reclaimed}. Şema değişikliği yok.

/** getOrInitCoinBalance'ın yenileme koşulunun tersi: bakiye "bugünün" bakiyesi. */
const COIN_BALANCE_LIVE_SQL = 'NOT ((exhausted_at IS NOT NULL AND exhausted_at <= NOW() - INTERVAL 1 DAY)
     OR (exhausted_at IS NULL AND (last_reset_date IS NULL OR last_reset_date <> CURDATE())))';

function planCoinBonusRecord(Database $db, int $paymentId, array $bonus): void
{
    $row  = $db->selectSingle('param_response_json FROM param_marketplace_payments WHERE id = ?', [$paymentId]);
    $meta = json_decode((string) ($row['param_response_json'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    $meta['coin_bonus'] = $bonus;
    $db->execute('UPDATE param_marketplace_payments SET param_response_json = ? WHERE id = ?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $paymentId]);
}

/**
 * Kesinleştirmenin transaction'ı içinde, paket yazıldıktan sonra çağrılır.
 * Verilen coin sayısını döner (0 = verilmedi).
 */
function grantPlanUpgradeCoins(Database $db, int $userId, int $oldLimit, int $newLimit, string $planName, int $paymentId): int
{
    $diff = $newLimit - $oldLimit;
    if ($diff <= 0) {
        return 0;
    }
    $today = (string) $db->selectSingle('CURDATE() AS d')['d'];

    $given = 0;
    $rows = $db->selectMulti(
        "param_response_json FROM param_marketplace_payments
          WHERE user_id = ? AND id <> ? AND order_id LIKE 'PLN-%' AND param_response_json LIKE '%coin_bonus%'",
        [$userId, $paymentId]
    );
    foreach ($rows as $r) {
        $b = json_decode((string) $r['param_response_json'], true)['coin_bonus'] ?? null;
        if (is_array($b) && ($b['date'] ?? '') === $today && ($b['plan'] ?? '') === $planName) {
            $given += max(0, (int) ($b['amount'] ?? 0) - (int) ($b['reclaimed'] ?? 0));
        }
    }
    $grant = max(0, $diff - $given);
    if ($grant === 0) {
        return 0;
    }

    $n = $db->execute(
        'UPDATE ' . AppConfig::TABLE_COIN_BALANCES . ' SET coins_remaining = coins_remaining + ?
          WHERE user_id = ? AND ' . COIN_BALANCE_LIVE_SQL,
        [$grant, $userId]
    );
    if ($n !== 1) {
        return 0; // satır yok ya da yenilenme zamanı gelmiş: ilk kullanımda tam hak
    }
    planCoinBonusRecord($db, $paymentId, ['amount' => $grant, 'date' => $today, 'plan' => $planName, 'reclaimed' => 0]);
    error_log(sprintf('[coin_engine] paket farkı verildi user=%d plan=%s +%d payment=%d', $userId, $planName, $grant, $paymentId));
    return $grant;
}

/**
 * İade edilen ve geri alınan paketin o gün verdiği farkı geri alır (bakiye
 * sıfırın altına inmez). İadenin transaction'ı içinde çağrılır. Geri alınan
 * coin sayısını döner.
 */
function reclaimPlanUpgradeCoins(Database $db, int $paymentId): int
{
    $row = $db->selectSingle('user_id, param_response_json FROM param_marketplace_payments WHERE id = ?', [$paymentId]);
    $b   = $row ? (json_decode((string) $row['param_response_json'], true)['coin_bonus'] ?? null) : null;
    if (!is_array($b)) {
        return 0;
    }
    $left = (int) ($b['amount'] ?? 0) - (int) ($b['reclaimed'] ?? 0);
    if ($left <= 0 || ($b['date'] ?? '') !== (string) $db->selectSingle('CURDATE() AS d')['d']) {
        return 0; // başka bir gün verildiyse bakiye o arada yenilendi
    }
    $userId = (int) $row['user_id'];
    $bal = $db->selectSingle(
        'coins_remaining FROM ' . AppConfig::TABLE_COIN_BALANCES . ' WHERE user_id = ? AND ' . COIN_BALANCE_LIVE_SQL . ' FOR UPDATE',
        [$userId]
    );
    if (!$bal) {
        return 0; // yenilenme zamanı gelmiş: yeni (geri alınmış) paketin hakkıyla yenilenecek
    }
    $take = min($left, max(0, (int) $bal['coins_remaining']));
    if ($take > 0) {
        $db->execute('UPDATE ' . AppConfig::TABLE_COIN_BALANCES . ' SET coins_remaining = coins_remaining - ? WHERE user_id = ?', [$take, $userId]);
    }
    $b['reclaimed'] = (int) ($b['reclaimed'] ?? 0) + $take;
    planCoinBonusRecord($db, $paymentId, $b);
    error_log(sprintf('[coin_engine] iade: paket farkı geri alındı user=%d -%d (kalan geri alınamayan %d) payment=%d', $userId, $take, $left - $take, $paymentId));
    return $take;
}
