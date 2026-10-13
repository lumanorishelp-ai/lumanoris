<?php
/**
 * N-35 — takip / beğeni ilişkisini kurma ya da kaldırma.
 *
 * `followchatbot.php` ve `likechatbot.php` yalnızca toggle'dı: istemci hangi
 * durumu istediğini söyleyemiyordu. Bir "takibi bırak" düğmesi bayat bir
 * sayfadan (ör. başka sekmede zaten bırakılmışken) toggle çağırırsa botu
 * YENİDEN takip ederdi. Açık eylem (`follow` / `unfollow`, `like` / `unlike`)
 * idempotent: istenen durum zaten varsa `unchanged` döner, hiçbir şey yazmaz.
 * Eylemsiz çağrı eski toggle davranışını aynen korur (sohbet sayfası onu
 * kullanıyor).
 *
 * C-02 deseni korunuyor: önce DELETE (etkilenen satır sayısı atomik "var
 * mıydı?" cevabı), sonra INSERT … ON DUPLICATE KEY UPDATE.
 */

const CHATBOT_RELATIONS = [
    'chatbot_follows' => ['on' => 'follow', 'off' => 'unfollow', 'done_on' => 'followed', 'done_off' => 'unfollowed'],
    'chatbot_likes'   => ['on' => 'like',   'off' => 'unlike',   'done_on' => 'liked',    'done_off' => 'unliked'],
];

/**
 * @param string      $table    chatbot_follows | chatbot_likes
 * @param string      $tsColumn followed_at | liked_at
 * @param string|null $want     açık eylem; null = toggle
 * @return array{action:string, changed:bool, id?:int}
 * @throws ValidationException geçersiz tablo ya da eylem
 */
function chatbotRelationSet(Database $db, string $table, string $tsColumn, int $userId, int $chatbotId, ?string $want): array
{
    $rel = CHATBOT_RELATIONS[$table] ?? null;
    if ($rel === null || !in_array($tsColumn, ['followed_at', 'liked_at'], true)) {
        throw new ValidationException('Geçersiz ilişki.');
    }
    if ($want !== null && $want !== $rel['on'] && $want !== $rel['off']) {
        throw new ValidationException('Geçersiz eylem: ' . $want);
    }

    $exists = fn(): bool => (bool) $db->selectSingle("id FROM `$table` WHERE user_id = ? AND chatbot_id = ?", [$userId, $chatbotId]);

    if ($want === $rel['off'] || $want === null) {
        if ($db->delete($table, 'user_id = ? AND chatbot_id = ?', [$userId, $chatbotId]) > 0) {
            return ['action' => $rel['done_off'], 'changed' => true];
        }
        if ($want === $rel['off']) {
            return ['action' => 'unchanged', 'changed' => false];
        }
    } elseif ($exists()) {
        return ['action' => 'unchanged', 'changed' => false];
    }

    $id = $db->insert($table, ['user_id' => $userId, 'chatbot_id' => $chatbotId, $tsColumn => date('Y-m-d H:i:s')], true);
    return ['action' => $rel['done_on'], 'changed' => true, 'id' => (int) $id];
}
