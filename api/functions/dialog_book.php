<?php
/**
 * N-30 — Diyalog Defteri ve bot cevabı kaydı.
 *
 * Eskiden defter kaydının "asistan yanıtı" istemciden aynen alınıyordu ve
 * bot erişimi denetlenmiyordu: herkes herhangi bir bota (başkasının özel botu
 * dahil) uydurma cevap yazıp herkese açık akışta o botun yanıtıymış gibi
 * yayınlayabiliyordu; akış özel botun adını ve sahibini de gösteriyordu.
 * Aynı kök: bot cevabını istemci `addChat(sent_by:"bot")` ile kaydediyordu.
 *
 * Kurallar (kullanıcı kararı, 2026-10-09):
 *   • Bot cevabını SUNUCU kaydeder (generateReply, chatSaveBotReply).
 *     addChat istemciden yalnızca kullanıcı mesajı ve botun kendi karşılama
 *     mesajını kabul eder; ikisi de sohbet erişimi ister.
 *   • Defter kaydı kullanıcının KENDİ geçmişindeki bir bot mesajının
 *     kimliğine dayanır; soru (önceki kullanıcı mesajı) ve cevap DB'den
 *     kopyalanır. Kayıt anında o bota sohbet erişimi denetlenir.
 *   • Akış ve kimlikle erişilen tüm defter uçları: bot vitrin kuralını
 *     geçiyorsa herkese, geçmiyorsa yalnızca paylaşana görünür.
 */

const DIALOG_BOOK_TABLE = 'user_dialog_books';

/** Gemini SSE gövdesinden (data: {...} satırları) cevap metnini birleştirir. */
function geminiSseText(string $raw): string
{
    $text = '';
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!str_starts_with($line, 'data: ')) continue;
        $json = json_decode(substr($line, 6), true);
        if (!is_array($json)) continue;
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
        }
    }
    return $text;
}

/** Sunucunun ürettiği bot cevabını kaydeder; yeni satırın kimliğini döner. */
function chatSaveBotReply(Database $db, int $userId, int $chatbotId, string $text): int
{
    return (int) $db->insert('chatbot_chats', [
        'chatbot_id' => $chatbotId,
        'user_id'    => $userId,
        'sent_by'    => 'bot',
        'message'    => $text,
    ]);
}

/**
 * addChat'in kapısı. Kullanıcı mesajı: sohbet erişimi yeter. "bot": yalnızca
 * botun karşılama mesajı (sohbet_basi_mesaj) birebir aynıysa — üretilmiş
 * cevaplar generateReply'da sunucuda kaydediliyor.
 *
 * @throws PermissionException|ValidationException
 */
function chatMessageWriteCheck(ChatbotRepository $repo, Database $db, int $userId, int $chatbotId, string $sentBy, string $message): void
{
    if (!$repo->userHasAccess($chatbotId, $userId, 'chat')) {
        throw new PermissionException('Bu chatbot ile sohbet erişiminiz yok.');
    }
    if ($sentBy === 'user') return;
    $greet = (string) ($db->selectSingle('sohbet_basi_mesaj FROM chatbotlar WHERE id = ?', [$chatbotId])['sohbet_basi_mesaj'] ?? '');
    if ($sentBy !== 'bot' || trim($greet) === '' || $message !== $greet) {
        throw new ValidationException('Bot mesajı istemciden kaydedilemez.');
    }
}

/**
 * Kullanıcının kendi geçmişindeki bot mesajından defter kaydı oluşturur.
 *
 * @throws NotFoundException|PermissionException|ValidationException
 */
function dialogBookCreateFromMessage(Database $db, ChatbotRepository $repo, int $userId, int $messageId, string $name): int
{
    $name = trim($name);
    if ($name === '') throw new ValidationException('Başlık boş olamaz.', ['name' => 'Başlık boş olamaz.']);

    $answer = $db->selectSingle(
        "id, chatbot_id, message FROM chatbot_chats WHERE id = ? AND user_id = ? AND sent_by = 'bot'",
        [$messageId, $userId]
    );
    if (!$answer) throw new NotFoundException('Bu mesaj sohbet geçmişinizde bulunamadı.');

    $chatbotId = (int) $answer['chatbot_id'];
    if (!$repo->userHasAccess($chatbotId, $userId, 'chat')) {
        throw new PermissionException('Bu chatbot ile sohbet erişiminiz yok.');
    }

    $question = $db->selectSingle(
        "message FROM chatbot_chats
          WHERE chatbot_id = ? AND user_id = ? AND sent_by = 'user' AND id < ?
          ORDER BY id DESC LIMIT 1",
        [$chatbotId, $userId, $messageId]
    );
    if (!$question) throw new ValidationException('Bu yanıtın bir sorusu yok; deftere eklenemez.');

    return (int) $db->insert(DIALOG_BOOK_TABLE, [
        'user_id'        => $userId,
        'chatbot_id'     => $chatbotId,
        'name'           => mb_substr($name, 0, 255),
        'input_message'  => (string) $question['message'],
        'output_message' => (string) $answer['message'],
    ]);
}

/** Görünürlük: bot vitrin kuralını geçiyor ya da izleyen paylaşanın kendisi. */
function dialogBookVisibleSql(): string
{
    return '(' . ChatbotRepository::publicVisibleSql() . ' OR udb.user_id = ?)';
}

function dialogBookIsVisible(Database $db, int $dialogId, int $viewerId): bool
{
    return (bool) $db->selectSingle(
        "udb.id FROM " . DIALOG_BOOK_TABLE . " udb
           JOIN chatbotlar c ON c.id = udb.chatbot_id
           LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id AND pms.status = 'active'
          WHERE udb.id = ? AND " . dialogBookVisibleSql(),
        [$dialogId, $viewerId]
    );
}

/** Akış: yalnızca izleyenin görebildiği kayıtlar (en fazla $limit, rastgele). */
function dialogBookFeed(Database $db, int $viewerId, int $limit = 100): array
{
    $limit = max(1, min($limit, 1000));
    return $db->selectMulti(
        "udb.id, udb.user_id, udb.chatbot_id, udb.name,
         udb.input_message, udb.output_message, udb.created_at,
         udb.chatbot_id AS conversation_chatbot_id,
         c.owner_user_id, c.isim AS chatbot_isim, c.kategori_id AS chatbot_kategori_id,
         c.profil_fotografi AS chatbot_profil_fotografi,
         k.kullanici_adi AS owner_kullanici_adi,
         sharer.kullanici_adi AS sharer_kullanici_adi
         FROM " . DIALOG_BOOK_TABLE . " udb
         JOIN chatbotlar c ON udb.chatbot_id = c.id
         LEFT JOIN param_marketplace_sellers pms ON pms.user_id = c.author_user_id AND pms.status = 'active'
         LEFT JOIN kullanicilar k ON c.owner_user_id = k.id
         LEFT JOIN kullanicilar sharer ON udb.user_id = sharer.id
         WHERE " . dialogBookVisibleSql() . "
         ORDER BY RAND() LIMIT $limit",
        [$viewerId]
    );
}
