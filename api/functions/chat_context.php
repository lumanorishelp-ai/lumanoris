<?php
/**
 * N-50 — Gemini'ye giden sohbet geçmişi.
 *
 * Önceden her istek tek bir `user` turuydu (talimat + yeni mesaj): model
 * önceki mesajları hiç görmüyordu, her cevaba "Merhaba! Ben …" diye sıfırdan
 * başlıyordu ve bir önceki söylenene atıf yapamıyordu.
 *
 * Geçmiş SUNUCUDA, veritabanından kurulur (chatbot_chats). İstemciden geçmiş
 * kabul edilmez: sahte "model" turlarıyla personayı/bilgi kaynağını ezmek
 * mümkün olurdu (SEC-015 ile aynı gerekçe).
 */

const CHAT_HISTORY_MAX_MESSAGES = 20;     // en yeni 20 mesaj (≈ 10 tur)
const CHAT_HISTORY_MAX_CHARS    = 16000;  // geçmiş metni üst sınırı (eğitim metni ayrı)

/**
 * @param array<int, array{sent_by: string, message: string}> $rows eskiden yeniye
 * @param string $greeting  botun karşılama metni (sohbet_basi_mesaj) — geçmişe girmez
 * @param string $message   bu istekteki kullanıcı mesajı
 * @param array  $fileParts bu istekteki dosyalar (inline_data parçaları)
 * @return array<int, array{role: string, parts: array}>
 */
function chatBuildContents(array $rows, string $greeting, string $message, array $fileParts = []): array
{
    $greeting = trim($greeting);
    $turns = [];
    foreach ($rows as $r) {
        $text = trim((string) ($r['message'] ?? ''));
        if ($text === '') {
            continue;
        }
        $isUser = ($r['sent_by'] ?? '') === 'user';
        // Karşılama, istemcinin sohbet açılışında yazdığı sabit metin; bazen
        // kullanıcının ilk sorusundan SONRA kaydediliyor (yarış). Geçmişe
        // girerse model onu bir cevap sanıyor ve kendini tanıtmayı tekrarlıyor.
        if (!$isUser && $greeting !== '' && $text === $greeting) {
            continue;
        }
        $turns[] = ['role' => $isUser ? 'user' : 'model', 'text' => $text];
    }

    // İstemcinin addchat'i (beklenmeden gönderiliyor) bu mesajı zaten
    // yazmış olabilir — iki kez gitmesin; güncel mesaj aşağıda sona eklenir.
    $last = end($turns);
    if ($last !== false && $last['role'] === 'user' && $last['text'] === trim($message)) {
        array_pop($turns);
    }

    // En yeni mesajlar korunur: sondan say, sınır aşılınca dur.
    $kept = [];
    $chars = 0;
    for ($i = count($turns) - 1; $i >= 0; $i--) {
        $len = mb_strlen($turns[$i]['text']);
        if (count($kept) >= CHAT_HISTORY_MAX_MESSAGES || $chars + $len > CHAT_HISTORY_MAX_CHARS) {
            break;
        }
        $chars += $len;
        array_unshift($kept, $turns[$i]);
    }

    // Gemini çok turlu isteği kullanıcı turuyla başlatmak istiyor.
    while ($kept !== [] && $kept[0]['role'] === 'model') {
        array_shift($kept);
    }

    $kept[] = ['role' => 'user', 'text' => trim($message), 'files' => $fileParts];

    // Ardışık aynı rol (ör. cevapsız kalmış bir soru) tek turda birleşir.
    $contents = [];
    foreach ($kept as $t) {
        $parts = [['text' => $t['text']]];
        foreach ($t['files'] ?? [] as $f) {
            $parts[] = $f;
        }
        $n = count($contents);
        if ($n > 0 && $contents[$n - 1]['role'] === $t['role']) {
            $contents[$n - 1]['parts'] = array_merge($contents[$n - 1]['parts'], $parts);
        } else {
            $contents[] = ['role' => $t['role'], 'parts' => $parts];
        }
    }
    return $contents;
}

/**
 * Geçmiş satırları: bu kullanıcı + bot, güncel sohbetin başından itibaren.
 * Sohbet kimliği istemciden gelir ama doğrulanır (kullanıcıya ve bota ait);
 * geçersizse en son sohbet, o da yoksa son mesajlar (sınırlı).
 *
 * @return array<int, array{sent_by: string, message: string}> eskiden yeniye
 */
function chatHistoryRows(Database $db, int $userId, int $chatbotId, int $conversationId): array
{
    $conv = null;
    if ($conversationId > 0) {
        $conv = $db->selectSingle(
            'started_at FROM chatbot_conversations WHERE id = ? AND user_id = ? AND chatbot_id = ?',
            [$conversationId, $userId, $chatbotId]
        );
    }
    if (!$conv) {
        $conv = $db->selectSingle(
            'started_at FROM chatbot_conversations WHERE user_id = ? AND chatbot_id = ? ORDER BY started_at DESC, id DESC LIMIT 1',
            [$userId, $chatbotId]
        );
    }

    $limit = CHAT_HISTORY_MAX_MESSAGES * 2; // karşılama vb. elenecekler için pay
    $rows = $conv
        ? $db->selectMulti(
            "sent_by, message FROM chatbot_chats WHERE chatbot_id = ? AND user_id = ? AND sent_time >= ? ORDER BY id DESC LIMIT $limit",
            [$chatbotId, $userId, $conv['started_at']]
        )
        : $db->selectMulti(
            "sent_by, message FROM chatbot_chats WHERE chatbot_id = ? AND user_id = ? ORDER BY id DESC LIMIT $limit",
            [$chatbotId, $userId]
        );
    return array_reverse($rows ?: []);
}
