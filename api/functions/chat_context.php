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

// ── N-60: bilgi bankasından yalnızca soruyla ilgili parçalar ────────────────
//
// Eğitim metni 60.000 karaktere kadar çıkabiliyor ve her mesajda TAMAMI
// gönderiliyordu (+ 16.000 karakter geçmiş + stil). Bütçeyi aşan metinde
// soruyla ilgili parçalar seçilir; giriş parçası (genelde botun tanımı) her
// zaman korunur, özgün sıra bozulmaz. Hiçbir sözcük eşleşmezse eski davranış:
// baştan bütçe kadar. Vektör araması yok: sözcük kökü (ilk 5 harf) eşleşmesi,
// Türkçe ekleri tolere ediyor (mantığındaki → mantı ← mantık).

const CHAT_CORPUS_BUDGET     = 24000; // gönderilecek bilgi bankası üst sınırı (karakter)
const CHAT_CORPUS_CHUNK      = 1500;  // parça boyutu hedefi
const CHAT_CORPUS_GAP_MARKER = "\n\n[…]\n\n";

/** @return string[] kök listesi (tekrarlı) */
function chatTermStems(string $text): array
{
    static $stop = ['için' => 1, 'gibi' => 1, 'olan' => 1, 'nedir' => 1, 'neden' => 1, 'nasıl' => 1, 'hangi' => 1,
        'daha' => 1, 'çok' => 1, 'bana' => 1, 'beni' => 1, 'sana' => 1, 'şunu' => 1, 'bunu' => 1, 'veya' => 1,
        'ama' => 1, 'ile' => 1, 'bir' => 1, 'bu' => 1, 'şu' => 1, 've' => 1, 'mı' => 1, 'mi' => 1, 'mu' => 1,
        'mü' => 1, 'ne' => 1, 'da' => 1, 'de' => 1, 'ki' => 1, 'peki' => 1, 'verir' => 1, 'misin' => 1,
        'mısın' => 1, 'örnek' => 1, 'anlat' => 1, 'açıkla' => 1, 'the' => 1, 'and' => 1];
    $lower = mb_strtolower(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    preg_match_all('/\p{L}{3,}/u', $lower, $m);
    $out = [];
    foreach ($m[0] as $w) {
        if (!isset($stop[$w])) {
            $out[] = mb_substr($w, 0, 5);
        }
    }
    return $out;
}

/** Paragraf sınırlarında ~CHAT_CORPUS_CHUNK'lık parçalar; dev paragraf cümle/kelime sınırından bölünür. */
function chatCorpusChunks(string $corpus): array
{
    $paras  = preg_split('/\n\s*\n/u', trim($corpus)) ?: [];
    $pieces = [];
    foreach ($paras as $p) {
        $p = trim($p);
        while (mb_strlen($p) > CHAT_CORPUS_CHUNK * 2) {
            $head = mb_substr($p, 0, CHAT_CORPUS_CHUNK);
            $cut  = max(mb_strrpos($head, '. ') ?: 0, mb_strrpos($head, ' ') ?: 0);
            $cut  = $cut > CHAT_CORPUS_CHUNK / 2 ? $cut + 1 : CHAT_CORPUS_CHUNK;
            $pieces[] = trim(mb_substr($p, 0, $cut));
            $p = trim(mb_substr($p, $cut));
        }
        if ($p !== '') {
            $pieces[] = $p;
        }
    }
    // Küçük paragrafları hedef boyuta kadar birleştir.
    $chunks = [];
    foreach ($pieces as $p) {
        $n = count($chunks);
        if ($n > 0 && mb_strlen($chunks[$n - 1]) + mb_strlen($p) + 2 <= CHAT_CORPUS_CHUNK) {
            $chunks[$n - 1] .= "\n\n" . $p;
        } else {
            $chunks[] = $p;
        }
    }
    return $chunks;
}

/**
 * @param string $query        bu mesaj
 * @param string $previousUser önceki kullanıcı mesajı ("peki örnek verir misin?" gibi takip soruları için)
 */
function chatSelectCorpus(string $corpus, string $query, string $previousUser = ''): string
{
    if (mb_strlen($corpus) <= CHAT_CORPUS_BUDGET) {
        return $corpus;
    }
    $chunks = chatCorpusChunks($corpus);
    $n      = count($chunks);
    $q      = array_unique(array_merge(chatTermStems($query), chatTermStems($previousUser)));

    // Parça başına kök sayıları + belge frekansı (idf).
    $tf = [];
    $df = [];
    foreach ($chunks as $i => $c) {
        $tf[$i] = array_count_values(chatTermStems($c));
        foreach ($q as $s) {
            if (isset($tf[$i][$s])) {
                $df[$s] = ($df[$s] ?? 0) + 1;
            }
        }
    }
    $score = [];
    foreach ($chunks as $i => $c) {
        $sc = 0.0;
        foreach ($q as $s) {
            if (isset($tf[$i][$s])) {
                $sc += (1 + log($tf[$i][$s])) * log(1 + $n / $df[$s]);
            }
        }
        $score[$i] = $sc;
    }

    $picked = [0 => true];
    $used   = mb_strlen($chunks[0]);
    $add = static function (int $i) use (&$picked, &$used, $chunks): bool {
        $len = mb_strlen($chunks[$i]) + mb_strlen(CHAT_CORPUS_GAP_MARKER);
        if (isset($picked[$i]) || $used + $len > CHAT_CORPUS_BUDGET) {
            return false;
        }
        $picked[$i] = true;
        $used += $len;
        return true;
    };

    if (max($score) > 0) {
        arsort($score);
        foreach ($score as $i => $sc) {
            if ($sc <= 0) break;
            $add($i);
        }
    } else {
        for ($i = 1; $i < $n; $i++) {
            if (!$add($i)) break;
        }
    }

    ksort($picked);
    $out  = '';
    $prev = -1;
    foreach (array_keys($picked) as $i) {
        if ($out !== '') {
            $out .= $i === $prev + 1 ? "\n\n" : CHAT_CORPUS_GAP_MARKER;
        }
        $out .= $chunks[$i];
        $prev = $i;
    }
    if ($prev < $n - 1) {
        $out .= CHAT_CORPUS_GAP_MARKER;
    }
    return mb_strlen($out) <= CHAT_CORPUS_BUDGET ? $out : mb_substr($out, 0, CHAT_CORPUS_BUDGET);
}
