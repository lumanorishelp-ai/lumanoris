<?php
/**
 * Bilgi bankası (RAG): parçalama, iş kuyruğu (cron işçisi), embedding ve arama.
 *
 * Akış:
 *   1. İstemci belgeyi/URL'yi mevcut uçlarla (readdocument/readurl) metne
 *      çevirir — doğrulama ve Türkçe hata mesajları orada (N-61/N-62).
 *   2. kb_add metni kb_sources'a `beklemede` olarak yazar ve hemen döner.
 *   3. cron/kb_worker.php kaynağı kilitler (`isleniyor`), parçalar, embedding
 *      üretir, parçaları yazar (`hazir`) ya da hata kodunu kaydeder
 *      (geçici hata → üstel geri çekilmeyle yeniden kuyruğa, kalıcı → `basarisiz`
 *      + sahibine e-posta).
 *   4. Sohbet kbRetrieve() ile soruya en yakın parçaları alır.
 *
 * Ayarlar .env'den (varsayılanlar parantezde): KB_CHUNK_CHARS (1400),
 * KB_CHUNK_OVERLAP (200), RAG_TOP_K (6), RAG_MIN_SIMILARITY (0.42),
 * RAG_CONTEXT_CHARS (9000), KB_SOURCE_MAX_CHARS (2000000),
 * KB_BOT_MAX_CHARS (3000000).
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/openai.php';

const KB_STATUSES = ['beklemede', 'isleniyor', 'hazir', 'basarisiz'];
/** `isleniyor`da bu kadar dakikadan uzun kalan iş yarım kalmış sayılır ve geri alınır. */
const KB_LOCK_TIMEOUT_MIN = 10;

/** @return array{chunk:int,overlap:int,top_k:int,min_sim:float,context_chars:int,source_max:int,bot_max:int} */
function kbSettings(): array
{
    $int = static fn(string $k, int $d): int => max(0, (int) env_get($k, (string) $d));
    return [
        'chunk'         => max(300, $int('KB_CHUNK_CHARS', 1400)),
        'overlap'       => $int('KB_CHUNK_OVERLAP', 200),
        'top_k'         => max(1, $int('RAG_TOP_K', 6)),
        'min_sim'       => (float) env_get('RAG_MIN_SIMILARITY', '0.42'),
        'context_chars' => max(1000, $int('RAG_CONTEXT_CHARS', 9000)),
        'source_max'    => max(1000, $int('KB_SOURCE_MAX_CHARS', 2000000)),
        'bot_max'       => max(1000, $int('KB_BOT_MAX_CHARS', 3000000)),
    ];
}

/**
 * Hata kodu → kullanıcıya gösterilecek Türkçe metin (tek kaynak).
 * Teknik ayrıntı yalnızca sunucu logunda.
 */
function kbErrorMessage(string $code): string
{
    return match ($code) {
        'empty'       => 'Bu kaynakta işlenebilecek metin bulunamadı. İçeriği kontrol edip tekrar ekleyebilirsiniz.',
        'too_large'   => 'Bu kaynak çok uzun. Daha küçük parçalara bölerek ekleyebilirsiniz.',
        'bot_full'    => 'Bu chatbotun bilgi bankası dolu. Yeni içerik eklemek için önce bazı kaynakları kaldırın.',
        'quota'       => 'Yapay zekâ servisinin kullanım kotası doldu. Lütfen daha sonra tekrar deneyin; sorun sürerse bize ulaşın.',
        'config'      => 'Yapay zekâ servisi şu an yapılandırılmamış. Sorun kayda geçti; lütfen daha sonra tekrar deneyin.',
        'busy', 'unavailable', 'network', 'timeout'
                      => 'Yapay zekâ servisine şu an ulaşılamadı (birkaç kez denendi). "Tekrar dene" ile yeniden başlatabilirsiniz.',
        default       => 'Bu kaynak işlenemedi. "Tekrar dene" ile yeniden başlatabilir ya da içeriği metin olarak ekleyebilirsiniz.',
    };
}

/** Durum anahtarı → arayüz etiketi. */
function kbStatusLabel(string $status): string
{
    return match ($status) {
        'beklemede' => 'Beklemede',
        'isleniyor' => 'İşleniyor',
        'hazir'     => 'Hazır',
        'basarisiz' => 'Başarısız',
        default     => $status,
    };
}

// ── Parçalama ──────────────────────────────────────────────────────────────

/** Satır bir başlık mı: Markdown `#`, numaralı başlık ya da kısa BÜYÜK HARF satırı. */
function kbIsHeading(string $line): bool
{
    $t = trim($line);
    if ($t === '' || mb_strlen($t) > 90) return false;
    if (preg_match('/^#{1,6}\s+\S/u', $t)) return true;
    if (preg_match('/[.,;:!?…]$/u', $t)) return false;
    if (preg_match('/^(\d+(\.\d+)*[.)]?|[IVXLC]+\.|BÖLÜM|Bölüm|KISIM|Kısım|Madde)\s+\S/u', $t)) return true;
    $letters = preg_replace('/[^\p{L}]/u', '', $t);
    return mb_strlen($letters) >= 3 && $letters === mb_strtoupper($letters);
}

/** Uzun bloğu cümle sınırlarından, gerekirse kelime sınırından böler. */
function kbSplitLong(string $block, int $max): array
{
    $sentences = preg_split('/(?<=[.!?…])\s+/u', $block) ?: [$block];
    $out = [];
    $cur = '';
    foreach ($sentences as $s) {
        while (mb_strlen($s) > $max) {
            $cut = mb_strrpos(mb_substr($s, 0, $max), ' ');
            $cut = ($cut === false || $cut < $max / 2) ? $max : $cut;
            if ($cur !== '') { $out[] = $cur; $cur = ''; }
            $out[] = trim(mb_substr($s, 0, $cut));
            $s = trim(mb_substr($s, $cut));
        }
        if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($s) > $max) {
            $out[] = $cur;
            $cur = $s;
        } else {
            $cur = $cur === '' ? $s : $cur . ' ' . $s;
        }
    }
    if (trim($cur) !== '') $out[] = $cur;
    return $out;
}

/** Önceki parçanın sonundan, kelime sınırında başlayan örtüşme metni. */
function kbOverlapTail(string $text, int $overlap): string
{
    if ($overlap <= 0 || mb_strlen($text) <= $overlap) return '';
    $tail = mb_substr($text, -$overlap);
    $sp = mb_strpos($tail, ' ');
    return $sp === false ? $tail : trim(mb_substr($tail, $sp + 1));
}

/**
 * Metni başlık/paragraf sınırlarında, örtüşmeli parçalara böler.
 *
 * @return list<array{heading:?string, content:string, char_start:int}>
 */
function kbChunkText(string $text, int $target = 1400, int $overlap = 200): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
    $text = trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
    if ($text === '') return [];

    $chunks   = [];
    $heading  = null;
    $buf      = '';   // biriken parça (başında önceki parçanın örtüşme kuyruğu olabilir)
    $fresh    = false; // $buf'ta örtüşme dışında YENİ içerik var mı
    $bufStart = 0;
    $cursor   = 0;

    // Yalnızca yeni içerik varsa parça çıkar; bölüm ne kadar kısa olursa olsun
    // kaybolmaz. Sonrasında aynı bölümde devam için örtüşme kuyruğu kalır.
    $flush = static function () use (&$chunks, &$buf, &$fresh, &$bufStart, &$heading, $overlap): void {
        $c = trim($buf);
        if ($fresh && $c !== '') {
            $chunks[] = ['heading' => $heading, 'content' => $c, 'char_start' => $bufStart];
        }
        $buf   = $c === '' ? '' : kbOverlapTail($c, $overlap);
        $fresh = false;
    };

    foreach (preg_split("/\n\n/u", $text) ?: [] as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $pos = mb_strpos($text, $block, $cursor);
        if ($pos !== false) $cursor = $pos;

        $lines = explode("\n", $block);
        // Bloğun ilk satırı başlıksa: yeni bölüm — mevcut parçayı kapat; örtüşme
        // bölüm sınırını geçmez (önceki bölümün metni yeni bölüme sızmasın).
        if (kbIsHeading($lines[0])) {
            $flush();
            $buf = '';
            $heading = mb_substr(ltrim(trim($lines[0]), "# \t"), 0, 255);
            array_shift($lines);
            $block = trim(implode("\n", $lines));
            if ($block === '') continue;
        }

        $pieces = mb_strlen($block) > $target ? kbSplitLong($block, $target) : [$block];
        foreach ($pieces as $piece) {
            if ($fresh && mb_strlen($buf) + 2 + mb_strlen($piece) > $target) {
                $flush();
            }
            if (!$fresh) {
                $bufStart = $cursor;
            }
            $buf   = $buf === '' ? $piece : $buf . "\n\n" . $piece;
            $fresh = true;
        }
    }
    $flush();
    return $chunks;
}

/** Embedding'e giden metin: kaynak adı + başlık bağlamı parçanın anlamını güçlendirir. */
function kbEmbeddingInput(string $sourceName, ?string $heading, string $content): string
{
    $prefix = 'Kaynak: ' . $sourceName . ($heading ? "\nBölüm: " . $heading : '');
    return $prefix . "\n\n" . $content;
}

// ── Kuyruk / işçi ──────────────────────────────────────────────────────────

/** Yarım kalmış (`isleniyor`da takılmış) işleri kuyruğa geri koyar. */
function kbReclaimStale($db): int
{
    return $db->execute(
        "UPDATE kb_sources SET status = 'beklemede', locked_at = NULL, locked_by = NULL
          WHERE status = 'isleniyor' AND locked_at < (NOW() - INTERVAL " . KB_LOCK_TIMEOUT_MIN . ' MINUTE)'
    );
}

/** Sıradaki işi atomik olarak kilitler; yoksa null. */
function kbClaimNext($db, string $workerId): ?array
{
    $n = $db->execute(
        "UPDATE kb_sources SET status = 'isleniyor', locked_at = NOW(), locked_by = ?, attempts = attempts + 1
          WHERE id = (SELECT id FROM (SELECT id FROM kb_sources
                       WHERE status = 'beklemede' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                       ORDER BY id LIMIT 1) AS t)
            AND status = 'beklemede'",
        [$workerId]
    );
    if ($n < 1) return null;
    $row = $db->selectSingle(
        "* FROM kb_sources WHERE status = 'isleniyor' AND locked_by = ? ORDER BY locked_at DESC, id DESC LIMIT 1",
        [$workerId]
    );
    return $row ?: null;
}

/**
 * Tek kaynağı işler: parçala → embedding → yaz. Sonucu kb_sources'a yazar.
 *
 * @return string 'hazir' | 'beklemede' (yeniden denenecek) | 'basarisiz' | 'silindi'
 */
function kbProcessSource($db, array $src, ?callable $embed = null): string
{
    $embed ??= static fn(array $texts): array => openaiEmbed($texts);
    $id = (int) $src['id'];
    $s  = kbSettings();
    $content = (string) ($src['content'] ?? '');
    $chunks  = kbChunkText($content, $s['chunk'], $s['overlap']);

    if ($chunks === []) {
        return kbFinishFailure($db, $src, 'empty', 'parça üretilemedi', false);
    }

    $inputs = array_map(
        static fn(array $c): string => kbEmbeddingInput((string) $src['name'], $c['heading'], $c['content']),
        $chunks
    );
    $started = microtime(true);
    $r = $embed($inputs);
    $c = openaiConfig();
    aiUsageLog($db, (int) $src['user_id'], (int) $src['chatbot_id'], 'embedding', $c['embedding_model'] ?: '-',
        (int) ($r['tokens'] ?? 0), 0, 0, (int) round((microtime(true) - $started) * 1000), $r['ok'] ? 'ok' : (string) $r['kind']);

    if (!$r['ok']) {
        return kbFinishFailure($db, $src, (string) $r['kind'], (string) $r['error'], openaiIsTransient((string) $r['kind']));
    }

    $conn = $db->getConnection();
    $conn->beginTransaction();
    try {
        // Kaynak işlenirken kaldırıldıysa (kb_delete) sonucu yazma.
        $still = $db->selectSingle('id FROM kb_sources WHERE id = ? FOR UPDATE', [$id]);
        if (!$still) {
            $conn->rollBack();
            return 'silindi';
        }
        $db->execute('DELETE FROM kb_chunks WHERE source_id = ?', [$id]);
        $dims = count($r['vectors'][0] ?? []);
        $model = $c['embedding_model'];
        foreach ($chunks as $i => $chunk) {
            $db->execute(
                'INSERT INTO kb_chunks (source_id, chatbot_id, chunk_index, heading, content, char_start, embedding, embedding_model, dims)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, (int) $src['chatbot_id'], $i, $chunk['heading'], $chunk['content'], $chunk['char_start'],
                 embeddingPack($r['vectors'][$i]), $model, $dims]
            );
        }
        $db->execute(
            "UPDATE kb_sources SET status = 'hazir', error_code = NULL, chunk_count = ?, char_count = ?,
                    locked_at = NULL, locked_by = NULL, next_attempt_at = NULL WHERE id = ?",
            [count($chunks), mb_strlen($content), $id]
        );
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        error_log('[kb] yazma hatası source=' . $id . ': ' . $e->getMessage());
        return kbFinishFailure($db, $src, 'db', $e->getMessage(), true);
    }
    return 'hazir';
}

/**
 * Hata sonucu: geçici ve deneme hakkı varsa üstel geri çekilmeyle yeniden
 * kuyruğa (1, 4, 9, 16 dk), aksi hâlde `basarisiz` + sahibine e-posta.
 */
function kbFinishFailure($db, array $src, string $code, string $detail, bool $transient): string
{
    $id = (int) $src['id'];
    $attempts = (int) ($src['attempts'] ?? 1);
    error_log(sprintf('[kb] kaynak=%d bot=%d hata=%s deneme=%d %s', $id, (int) $src['chatbot_id'], $code, $attempts, $detail));

    if ($transient && $attempts < OPENAI_BG_MAX_ATTEMPTS) {
        $db->execute(
            "UPDATE kb_sources SET status = 'beklemede', error_code = ?, locked_at = NULL, locked_by = NULL,
                    next_attempt_at = NOW() + INTERVAL ? MINUTE WHERE id = ?",
            [$code, $attempts * $attempts, $id]
        );
        return 'beklemede';
    }
    $db->execute(
        "UPDATE kb_sources SET status = 'basarisiz', error_code = ?, locked_at = NULL, locked_by = NULL WHERE id = ?",
        [$code, $id]
    );
    kbNotifyFailure($db, $src, $code);
    return 'basarisiz';
}

/** Kalıcı hatada kaynağı ekleyen kullanıcıya e-posta (staging: MAIL_TRANSPORT=log). */
function kbNotifyFailure($db, array $src, string $code): void
{
    try {
        require_once __DIR__ . '/phpmailer.php';
        $u = $db->selectSingle('eposta, ad_soyad FROM kullanicilar WHERE id = ?', [(int) $src['user_id']]);
        $bot = $db->selectSingle('isim FROM chatbotlar WHERE id = ?', [(int) $src['chatbot_id']]);
        if (!$u || empty($u['eposta'])) return;
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $ad = trim((string) ($u['ad_soyad'] ?? ''));
        $html = '<p>Merhaba' . ($ad !== '' ? ' ' . $h($ad) : '') . ',</p>'
            . '<p><strong>' . $h((string) ($bot['isim'] ?? 'Chatbot')) . '</strong> chatbotunuzun bilgi bankasına eklediğiniz '
            . '<strong>' . $h((string) $src['name']) . '</strong> kaynağı işlenemedi.</p>'
            . '<p>' . $h(kbErrorMessage($code)) . '</p>'
            . '<p>Chatbotunuzu "Yönet" ile açıp Bilgi Bankası sekmesinden kaynağı tekrar deneyebilir ya da kaldırabilirsiniz.</p>'
            . '<p>Lumanoris</p>';
        sendEmail((string) (env_get('NOREPLY_EMAIL') ?? ''), 'Lumanoris', (string) $u['eposta'],
            'Bilgi bankası kaynağı işlenemedi', $html);
    } catch (Throwable $e) {
        error_log('[kb] bildirim e-postası gönderilemedi: ' . $e->getMessage());
    }
}

// ── Eski bilgi bankasının taşınması ────────────────────────────────────────

/** Taşınan kaynakların adının başına eklenen işaret (tekrar çalıştırma güvenliği). */
const KB_LEGACY_PREFIX = 'Önceki bilgi bankası';

/**
 * Eski training_prompt metnini kaynaklara böler. Eski arayüz her kaynağı
 * "\n\n### Kaynak: <ad>\n" başlığıyla ekliyordu; başlıktan önceki metin
 * (elle yazılmış ya da çok eski kayıt) tek bir kaynak olur.
 *
 * @return list<array{name:string, text:string}>
 */
function kbSplitLegacyCorpus(string $corpus): array
{
    $corpus = str_replace(["\r\n", "\r"], "\n", $corpus);
    $parts = preg_split('/^### Kaynak: (.+)$/mu', $corpus, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$corpus];
    $out = [];
    $head = trim((string) array_shift($parts));
    if (mb_strlen($head) >= 20) {
        $out[] = ['name' => KB_LEGACY_PREFIX, 'text' => $head];
    }
    for ($i = 0; $i + 1 < count($parts); $i += 2) {
        $text = trim((string) $parts[$i + 1]);
        if (mb_strlen($text) >= 20) {
            $out[] = ['name' => KB_LEGACY_PREFIX . ' — ' . mb_substr(trim((string) $parts[$i]), 0, 200), 'text' => $text];
        }
    }
    return $out;
}

/**
 * Eski bilgi bankası olan botları kb_sources'a taşır (yalnızca INSERT;
 * training_prompt'a dokunulmaz). Taşınmış kaynağı olan bot atlanır.
 *
 * @return array{bots:int, sources:int, skipped:int, detail:list<string>}
 */
function kbImportLegacy($db, bool $apply): array
{
    $r = ['bots' => 0, 'sources' => 0, 'skipped' => 0, 'detail' => []];
    $bots = $db->selectMulti(
        "id, isim, author_user_id, training_prompt FROM chatbotlar
          WHERE training_prompt IS NOT NULL AND CHAR_LENGTH(TRIM(training_prompt)) > 0 ORDER BY id"
    ) ?: [];
    foreach ($bots as $b) {
        $id = (int) $b['id'];
        $done = $db->selectSingle('COUNT(*) AS n FROM kb_sources WHERE chatbot_id = ? AND name LIKE ?', [$id, KB_LEGACY_PREFIX . '%']);
        if ((int) ($done['n'] ?? 0) > 0) {
            $r['skipped']++;
            $r['detail'][] = "bot $id ({$b['isim']}): zaten taşınmış, atlandı";
            continue;
        }
        $pieces = kbSplitLegacyCorpus((string) $b['training_prompt']);
        $r['bots']++;
        $r['sources'] += count($pieces);
        $r['detail'][] = sprintf('bot %d (%s): %d karakter → %d kaynak%s', $id, $b['isim'],
            mb_strlen((string) $b['training_prompt']), count($pieces), $apply ? '' : ' (kuru çalışma)');
        if (!$apply) continue;
        foreach ($pieces as $p) {
            $db->insert('kb_sources', [
                'chatbot_id' => $id,
                'user_id'    => (int) $b['author_user_id'],
                'type'       => 'text',
                'name'       => mb_substr($p['name'], 0, 255),
                'content'    => $p['text'],
                'char_count' => mb_strlen($p['text']),
                'status'     => 'beklemede',
            ]);
        }
    }
    return $r;
}

// ── Arama (retrieval) ──────────────────────────────────────────────────────

/** Botun en az bir hazır parçası var mı (yoksa sohbet eski yola düşer). */
function kbBotHasChunks($db, int $chatbotId): bool
{
    try {
        return (bool) $db->selectSingle('1 AS x FROM kb_chunks WHERE chatbot_id = ? LIMIT 1', [$chatbotId]);
    } catch (Throwable $e) {
        return false; // 020 uygulanmamış
    }
}

/**
 * Soruya en yakın parçalar. Eşiğin altındakiler dönmez; bağlam karakter
 * bütçesi aşılmaz.
 *
 * @param list<float> $queryVector
 * @return list<array{content:string, heading:?string, source:string, score:float}>
 */
function kbSearch($db, int $chatbotId, array $queryVector, ?array $settings = null): array
{
    $s = $settings ?? kbSettings();
    $rows = $db->selectMulti(
        "c.heading, c.content, c.embedding, s.name AS source
           FROM kb_chunks c JOIN kb_sources s ON s.id = c.source_id
          WHERE c.chatbot_id = ? AND s.status = 'hazir'",
        [$chatbotId]
    ) ?: [];
    $scored = [];
    $dims = count($queryVector);
    foreach ($rows as $r) {
        $vec = embeddingUnpack((string) $r['embedding']);
        // Embedding modeli/boyutu .env'de değiştiyse eski parça karşılaştırılamaz:
        // yeniden işlenene ("Tekrar dene") kadar atlanır, yanlış eşleşme üretmez.
        if (count($vec) !== $dims) {
            continue;
        }
        $score = embeddingCosine($queryVector, $vec);
        if ($score >= $s['min_sim']) {
            $scored[] = ['content' => (string) $r['content'], 'heading' => $r['heading'], 'source' => (string) $r['source'], 'score' => $score];
        }
    }
    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
    $out = [];
    $budget = $s['context_chars'];
    foreach ($scored as $c) {
        if (count($out) >= $s['top_k']) break;
        $len = mb_strlen($c['content']);
        if ($len > $budget && $out !== []) break;
        $out[] = $c;
        $budget -= $len;
    }
    return $out;
}

/**
 * Mesaj bağlam isteyen kısa bir takip sorusu mu ("Peki örnek?", "Bunu açar
 * mısın?", "Neden?")? Kendi başına tam soru ("Japonya'nın başkenti neresi?")
 * takip sayılmaz — staging ölçümü: o durumda önceki konunun kaynağı bağlama
 * giriyordu.
 */
function kbLooksLikeFollowUp(string $message): bool
{
    $m = mb_strtolower(trim($message));
    if ($m === '' || mb_strlen($m) >= 60) return false;
    return (bool) preg_match(
        '/(^|\s)(peki|ya|bunu|bunun|bunlar\w*|onu|onun|onlar\w*|şunu|şunun|bu konu\w*|örnek\w*|mesela|devam\w*|detay\w*|ayrıntı\w*|açıkla\w*|açar mısın|daha fazla|başka|neden|niye|nasıl yani|yani)(\s|\?|$|[.,!])/u',
        $m
    );
}

/**
 * Sohbet için arama: soru (kısa takip sorusunda önceki kullanıcı mesajıyla
 * birlikte) embedding'e çevrilir. Embedding alınamazsa boş döner — sohbet
 * bilgi bankası olmadan sürer, kullanıcı hata görmez.
 *
 * @return array{chunks: list<array>, tokens: int, ok: bool}
 */
function kbRetrieve($db, int $chatbotId, string $message, string $prevUser = ''): array
{
    $query  = trim($message);
    $inputs = [mb_substr($query, 0, 6000)];
    // Kısa takip sorusu ("peki örnek?") tek başına anlamsız olabilir: önceki
    // soruyla birleşik sorgu da (aynı istekte) alınır, ama YALNIZCA mesaj bir
    // takip sorusuysa (kbLooksLikeFollowUp) ve sorunun kendisi hiçbir parça
    // bulamazsa kullanılır. Önceden
    // her kısa soruda birleşik sorgu kullanılıyordu; konu değişince ("Japonya'nın
    // başkenti?") önceki konunun kaynağı (ör. yemek notları) bağlama giriyordu.
    $followUp = $prevUser !== '' && kbLooksLikeFollowUp($query);
    if ($followUp) {
        $inputs[] = mb_substr(trim($prevUser) . "\n" . $query, 0, 6000);
    }
    $r = openaiEmbed($inputs, 2, 10);
    if (!$r['ok']) {
        error_log('[kb] soru embedding alınamadı bot=' . $chatbotId . ' tür=' . $r['kind'] . ' ' . $r['error']);
        return ['chunks' => [], 'tokens' => 0, 'ok' => false];
    }
    $s = kbSettings();
    $chunks = kbSearch($db, $chatbotId, $r['vectors'][0], $s);
    if ($chunks === [] && $followUp) {
        $chunks = kbSearch($db, $chatbotId, $r['vectors'][1], $s);
    }
    return ['chunks' => $chunks, 'tokens' => (int) $r['tokens'], 'ok' => true];
}
