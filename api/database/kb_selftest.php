<?php
/**
 * Bilgi bankası (RAG) + OpenAI yardımcıları öz-testi — çevrimdışı, ağa çıkmaz,
 * veritabanına dokunmaz.
 *
 *   php api/database/kb_selftest.php
 *
 * A) Parçalama: başlık sınırı, hedef boyut, örtüşme, boş metin, çok uzun cümle.
 * B) Embedding BLOB paketleme ve kosinüs benzerliği.
 * C) Responses API akış ayrıştırıcısı: parçalı satır, metin, kullanım, hata.
 * D) Hata türü / yeniden deneme / anahtar maskeleme.
 * E) Arama: eşik, top-k, bağlam bütçesi (sahte DB).
 * F) Hata kodları Türkçe ve teknik ayrıntı içermiyor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $label\n"; }
    else     { $fail++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function finish(): void {
    global $pass, $fail;
    echo "\n=== SONUÇ: $pass geçti, $fail başarısız ===\n\n";
    exit($fail > 0 ? 1 : 0);
}

if (!defined('CHAT_PHP_TIME_LIMIT')) define('CHAT_PHP_TIME_LIMIT', 45);
require_once __DIR__ . '/../functions/kb.php';

echo "A) Parçalama\n";
check('boş metin → parça yok', kbChunkText("  \n\n ") === []);

$para = static fn(int $n, string $w = 'kelime'): string => trim(str_repeat($w . ' ', $n)) . '.';
$doc = "# Giriş\n\n" . $para(60) . "\n\n" . $para(60) . "\n\n## Etik\n\n" . $para(80, 'erdem') . "\n\n" . $para(80, 'erdem');
$chunks = kbChunkText($doc, 600, 100);
check('birden çok parça üretildi', count($chunks) >= 3, (string) count($chunks));
$maxLen = max(array_map(static fn($c) => mb_strlen($c['content']), $chunks));
check('parçalar hedef boyutun makul üstüne çıkmıyor', $maxLen <= 600 + 120, (string) $maxLen);
check('ilk parçanın başlığı "Giriş"', ($chunks[0]['heading'] ?? null) === 'Giriş', (string) ($chunks[0]['heading'] ?? 'null'));
$etik = array_values(array_filter($chunks, static fn($c) => $c['heading'] === 'Etik'));
check('"Etik" başlığı altındaki parçalar başlığı taşıyor', $etik !== [] && str_contains($etik[0]['content'], 'erdem'));
check('başlık değişince önceki bölümün metni yeni bölüme sızmıyor', $etik !== [] && !str_contains($etik[0]['content'], 'kelime'));
check('aynı bölümde ardışık parçalar örtüşüyor', count($etik) >= 2
    && str_starts_with($etik[1]['content'], kbOverlapTail($etik[0]['content'], 100)), json_encode(array_map(fn($c) => mb_substr($c['content'], 0, 30), $etik), JSON_UNESCAPED_UNICODE));
$long = str_repeat('a', 5000);
$lc = kbChunkText($long, 1000, 0);
check('boşluksuz çok uzun metin de bölünüyor', count($lc) >= 5 && max(array_map(fn($c) => mb_strlen($c['content']), $lc)) <= 1000);
$tr = kbChunkText("Şiir ve İçtenlik\n\nÇağdaş şiirde içtenlik, öznelliğin ölçüsüdür. " . $para(10, 'ğüşıöç'), 1400, 200);
check('Türkçe karakterler korunuyor', $tr !== [] && str_contains($tr[0]['content'], 'öznelliğin'));
// Staging'de yakalanan hata: başlıktan önceki KISA bölüm (örtüşmeden kısa) atılıyordu.
$short = kbChunkText("# Erdem\n\nErdem iki aşırılığın ortasıdır.\n\n## Mutluluk\n\nEudaimonia akla uygun etkinliktir.", 1400, 200);
check('kısa bölümler kaybolmuyor (her başlık kendi parçası)', count($short) === 2
    && str_contains($short[0]['content'], 'Erdem iki aşırılığın') && $short[0]['heading'] === 'Erdem'
    && str_contains($short[1]['content'], 'Eudaimonia') && $short[1]['heading'] === 'Mutluluk', json_encode($short, JSON_UNESCAPED_UNICODE));
$all = kbChunkText($doc, 600, 100);
$joined = implode(' ', array_column($all, 'content'));
check('hiçbir paragraf düşmüyor (tüm kelimeler parçalarda)', substr_count($joined, 'kelime') >= 120 && substr_count($joined, 'erdem') >= 160);
check('yalnızca örtüşme kuyruğundan ibaret parça üretilmiyor', count(array_filter($all, static fn($c) => mb_strlen($c['content']) <= 100)) === 0);
check('başlık sezgisi: cümle başlık değil', !kbIsHeading('Bu bir cümledir.') && kbIsHeading('BİRİNCİ BÖLÜM') && kbIsHeading('2.1 Yöntem') && kbIsHeading('### Notlar'));

$legacy = "Elle yazılmış giriş metni burada duruyor.\n\n### Kaynak: etik.pdf\nErdem iki aşırılığın ortasıdır, ölçülülük.\n\n### Kaynak: https://ornek.com/sss\nSıkça sorulan sorular ve cevapları.\n\n### Kaynak: bos\n  ";
$sp = kbSplitLegacyCorpus($legacy);
check('eski bilgi bankası "### Kaynak:" başlıklarından kaynaklara bölünüyor', count($sp) === 3
    && $sp[0]['name'] === KB_LEGACY_PREFIX && str_starts_with($sp[0]['text'], 'Elle yazılmış')
    && $sp[1]['name'] === KB_LEGACY_PREFIX . ' — etik.pdf' && str_contains($sp[1]['text'], 'ölçülülük')
    && $sp[2]['name'] === KB_LEGACY_PREFIX . ' — https://ornek.com/sss', json_encode($sp, JSON_UNESCAPED_UNICODE));
check('başlıksız eski metin tek kaynak', count(kbSplitLegacyCorpus("Sadece düz bir metin, başlık yok ama yeterince uzun.")) === 1);

echo "\nB) Embedding paketleme / benzerlik\n";
$v = [0.1, -0.25, 0.5, 0.0, 1e-3];
$u = embeddingUnpack(embeddingPack($v));
check('pack/unpack aynı vektör (float32 hassasiyeti)', count($u) === 5 && abs($u[2] - 0.5) < 1e-6 && abs($u[1] + 0.25) < 1e-6);
check('BLOB boyutu 4 bayt × boyut', strlen(embeddingPack($v)) === 20);
check('kosinüs: aynı yön 1', abs(embeddingCosine([1, 2, 3], [2, 4, 6]) - 1.0) < 1e-9);
check('kosinüs: dik 0', abs(embeddingCosine([1, 0], [0, 1])) < 1e-9);
check('kosinüs: boş vektör 0', embeddingCosine([], [1]) === 0.0);

echo "\nC) Akış ayrıştırıcısı\n";
$got = '';
$p = new OpenAiStreamParser(function (string $t) use (&$got) { $got .= $t; });
$stream = "event: response.created\ndata: {\"type\":\"response.created\"}\n\n"
    . "event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Mer\"}\n\n"
    . "event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"haba ğ\"}\n\n"
    . "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"usage\":{\"input_tokens\":1200,\"input_tokens_details\":{\"cached_tokens\":1024},\"output_tokens\":7}}}\n\n";
foreach (str_split($stream, 7) as $piece) { $p->feed($piece); } // satırlar parça parça gelir
check('parçalı gelen satırlardan metin eksiksiz', $got === 'Merhaba ğ' && $p->text === 'Merhaba ğ', $got);
check('tamamlandı + kullanım okundu', $p->completed && $p->usage === ['input' => 1200, 'cached' => 1024, 'cache_write' => 0, 'output' => 7], json_encode($p->usage));
$p2 = new OpenAiStreamParser(fn($t) => null);
$p2->feed("data: {\"type\":\"response.failed\",\"response\":{\"error\":{\"code\":\"server_error\",\"message\":\"x\"}}}\n");
check('response.failed yakalanıyor', !$p2->completed && str_starts_with($p2->streamError, 'failed:server_error'));
$frame = openaiClientFrame('Çay "ve" \n');
$j = json_decode(substr(trim($frame), 6), true);
check('istemci karesi Gemini biçiminde ve JSON güvenli', ($j['candidates'][0]['content']['parts'][0]['text'] ?? '') === 'Çay "ve" \n' && str_ends_with($frame, "\n\n"));

echo "\nD) Hata türü / yeniden deneme / maskeleme\n";
check('429 insufficient_quota → quota', openaiFailureKind(429, '', '{"error":{"code":"insufficient_quota"}}') === 'quota');
check('429 rate limit → busy', openaiFailureKind(429, '', '{"error":{"code":"rate_limit_exceeded"}}') === 'busy');
check('500 → unavailable, 401 → config, curl timeout → timeout',
    openaiFailureKind(500, '', '') === 'unavailable' && openaiFailureKind(401, '', '') === 'config'
    && openaiFailureKind(0, 'Operation timed out after 30001 ms', '') === 'timeout');
$plan = openaiChatRetryPlan(503, '', '', 1.0, false, 1);
check('akış başlamadan 503 → yeniden dene', $plan['retry'] && $plan['delay_ms'] > 0 && $plan['timeout'] >= 10, json_encode($plan));
check('akış başladıysa yeniden deneme yok', !openaiChatRetryPlan(503, '', '', 1.0, true, 1)['retry']);
check('3. denemeden sonra yeniden deneme yok', !openaiChatRetryPlan(503, '', '', 1.0, false, 3)['retry']);
check('kota/config yeniden denenmiyor', !openaiChatRetryPlan(429, '', 'insufficient_quota', 1.0, false, 1)['retry'] && !openaiChatRetryPlan(401, '', '', 1.0, false, 1)['retry']);
$b1 = openaiBackoffSeconds(1); $b4 = openaiBackoffSeconds(4);
check('üstel geri çekilme büyüyor', $b1 >= 1 && $b1 <= 1.25 && $b4 >= 8 && $b4 <= 10, "$b1 $b4");
check('Retry-After daha uzunsa o kullanılıyor (sınırla)', openaiBackoffSeconds(1, 20.0) === 20.0 && openaiBackoffSeconds(1, 99.0) === 30.0);
$key = 'sk-proj-TEST_anahtar_123';
check('özet anahtarı maskeliyor', !str_contains(openaiErrorSummary(401, '', '{"error":{"message":"Incorrect API key provided: ' . $key . '"}}', 'm', $key), $key));

echo "\nE) Arama\n";
final class FakeDb {
    public function __construct(private array $rows) {}
    public function selectMulti($q, $p = []) { return $this->rows; }
}
$mk = static fn(array $vec, string $txt, string $src = 'a.pdf') => ['heading' => null, 'content' => $txt, 'embedding' => embeddingPack($vec), 'source' => $src];
$rows = [$mk([1, 0, 0], 'tam eşleşme'), $mk([0.8, 0.6, 0], 'yakın'), $mk([0, 0, 1], 'alakasız'), $mk([0.6, 0.8, 0], 'orta', 'b.txt')];
$settings = ['chunk' => 1400, 'overlap' => 200, 'top_k' => 2, 'min_sim' => 0.5, 'context_chars' => 9000, 'source_max' => 1, 'bot_max' => 1];
$res = kbSearch(new FakeDb($rows), 1, [1, 0, 0], $settings);
check('en benzer önce, top-k=2', count($res) === 2 && $res[0]['content'] === 'tam eşleşme' && $res[1]['content'] === 'yakın', json_encode(array_column($res, 'content'), JSON_UNESCAPED_UNICODE));
check('eşik altındaki dönmüyor', !in_array('alakasız', array_column(kbSearch(new FakeDb($rows), 1, [1, 0, 0], ['top_k' => 10] + $settings), 'content'), true));
check('ilgili parça yoksa boş', kbSearch(new FakeDb($rows), 1, [0, -1, 0], $settings) === []);
$big = [$mk([1, 0, 0], str_repeat('x', 5000)), $mk([0.99, 0.1, 0], str_repeat('y', 5000))];
$mixed = [$mk([1, 0, 0, 0], 'eski boyut (4)'), $mk([1, 0, 0], 'doğru boyut (3)')];
check('farklı boyuttaki (eski modelin) parçası atlanıyor', array_column(kbSearch(new FakeDb($mixed), 1, [1, 0, 0], $settings), 'content') === ['doğru boyut (3)']);
$fu = ['Peki içine peynir konur mu?' => true, 'Örnek verir misin?' => true, 'Bunu biraz açar mısın?' => true, 'Neden?' => true,
       'Devam et' => true, "Japonyanın başkenti neresidir?" => false, 'Menemen nasıl yapılır, içinde neler var?' => false,
       'Bana bir fıkra anlat' => false, str_repeat('peki ', 20) => false];
$bad = array_keys(array_filter($fu, static fn($want, $q) => kbLooksLikeFollowUp($q) !== $want, ARRAY_FILTER_USE_BOTH));
check('takip sorusu sezgisi (bağlam isteyen kısa soru) / kendi başına tam soru', $bad === [], implode(' | ', $bad));
check('bağlam bütçesi aşılmıyor', count(kbSearch(new FakeDb($big), 1, [1, 0, 0], ['context_chars' => 6000, 'top_k' => 5] + $settings)) === 1);

echo "\nF) Kullanıcı mesajları\n";
$codes = ['empty', 'too_large', 'bot_full', 'quota', 'config', 'busy', 'unavailable', 'network', 'timeout', 'db', 'bilinmeyen'];
$bad = [];
foreach ($codes as $c) {
    $m = kbErrorMessage($c);
    if ($m === '' || preg_match('/\b(500|sunucu hatası|server error|exception|http|curl)\b/iu', $m)) $bad[] = $c;
}
check('tüm kodlar Türkçe ve teknik terim içermiyor', $bad === [], implode(',', $bad));
check('durum etiketleri Türkçe', kbStatusLabel('isleniyor') === 'İşleniyor' && kbStatusLabel('hazir') === 'Hazır');

echo "\nG) Sohbet isteği (talimat şablonu, bağlam, girdi dönüşümü)\n";
require_once __DIR__ . '/../functions/chat_openai.php';
$bot = ['isim' => 'Aristoteles', 'aciklama' => 'Etik üzerine konuşur.', 'style_prompt' => 'Sokratik sorularla ilerle.'];
$ins = chatSystemInstructions($bot);
check('şablon dosyası okunuyor ve yer tutucular dolduruluyor', str_contains($ins, '"Aristoteles"') && str_contains($ins, 'Etik üzerine konuşur.')
    && str_contains($ins, 'Sokratik sorularla') && !str_contains($ins, '{{'), mb_substr($ins, 0, 120));
check('talimat "eğitilmedim deme" ve "uydurma" kurallarını içeriyor', str_contains($ins, 'eğitilmedim') && str_contains($ins, 'uydurma'));
check('talimat bota göre sabit (önbellek öneki)', chatSystemInstructions($bot) === $ins);
$ctx = chatKbContextBlock([['content' => 'Erdem ortadır.', 'heading' => 'II. Kitap', 'source' => 'etik.pdf']], 'eski metin', true);
check('bağlam bloğu kaynak + bölüm etiketli', str_contains($ctx, 'Kaynak: etik.pdf — Bölüm: II. Kitap') && str_contains($ctx, 'Erdem ortadır.') && !str_contains($ctx, 'eski metin'));
check('RAG botunda sonuç yoksa bağlam boş (genel bilgiyle yanıt)', chatKbContextBlock([], 'eski metin', true) === '');
check('RAG verisi olmayan bot eski metne düşüyor', str_contains(chatKbContextBlock([], 'eski metin', false), 'eski metin'));
$contents = [
    ['role' => 'user', 'parts' => [['text' => 'İlk soru']]],
    ['role' => 'model', 'parts' => [['text' => 'İlk cevap']]],
    ['role' => 'user', 'parts' => [['text' => 'Erdem nedir?'], ['inline_data' => ['mime_type' => 'image/png', 'data' => 'AAA=']]]],
];
$in = chatOpenAiInput($contents, $ctx);
check('roller: user/assistant/user', array_column($in, 'role') === ['user', 'assistant', 'user']);
check('bağlam yalnızca son kullanıcı turunda; geçmiş turlar aynen (önbellek öneki bozulmuyor)',
    $in[0]['content'][0]['text'] === 'İlk soru' && $in[1]['content'] === 'İlk cevap'
    && str_starts_with($in[2]['content'][0]['text'], '[BİLGİ BANKASI') && str_ends_with($in[2]['content'][0]['text'], 'Erdem nedir?'));
check('önbellek noktası yalnızca geçmişteki son kullanıcı turunda (bağlamlı son tur yazılmaz)',
    ($in[0]['content'][0]['prompt_cache_breakpoint']['mode'] ?? '') === 'explicit' && !isset($in[2]['content'][0]['prompt_cache_breakpoint']));
$first = chatOpenAiInput([['role' => 'user', 'parts' => [['text' => 'Merhaba']]]], $ctx);
check('ilk turda önbellek noktası yok (yazma yapılmaz)', !isset($first[0]['content'][0]['prompt_cache_breakpoint']));
$four = [];
foreach (['s1', 'c1', 's2', 'c2', 's3', 'c3', 's4'] as $k => $t) $four[] = ['role' => $k % 2 ? 'model' : 'user', 'parts' => [['text' => $t]]];
$bp = array_map(static fn($m) => isset($m['content'][0]['prompt_cache_breakpoint']) ? 1 : 0, chatOpenAiInput($four, $ctx));
check('önbellek noktaları: geçmişteki son iki kullanıcı turu (önceki yazımı oku + yenisini yaz), son tur hariç', $bp === [0, 0, 1, 0, 1, 0, 0], json_encode($bp));
$noBp = array_filter(chatOpenAiInput($four, $ctx, false), static fn($m) => is_array($m['content']) && isset($m['content'][0]['prompt_cache_breakpoint']));
check('geçmiş kırpıldıysa önbellek noktası yok (okunamayacak yazma ödenmez)', $noBp === []);
require_once __DIR__ . '/../functions/chat_context.php';
$rows = [['sent_by' => 'user', 'message' => 'a'], ['sent_by' => 'bot', 'message' => 'Merhaba!'], ['sent_by' => 'bot', 'message' => 'b'], ['sent_by' => 'user', 'message' => 'son']];
$ctn = chatBuildContents($rows, 'Merhaba!', 'son');
check('kırpılmamış geçmiş → kırpıldı değil (karşılama ve güncel mesaj sayılmaz)', !chatHistoryTruncated($rows, $ctn, 'Merhaba!', 'son'));
$many = [];
for ($i = 0; $i < 30; $i++) $many[] = ['sent_by' => $i % 2 ? 'bot' : 'user', 'message' => "m$i"];
check('20 mesaj sınırını aşan geçmiş → kırpıldı', chatHistoryTruncated($many, chatBuildContents($many, '', 'yeni'), '', 'yeni'));
check('görsel ek input_image olarak gidiyor', ($in[2]['content'][1]['type'] ?? '') === 'input_image' && str_starts_with($in[2]['content'][1]['image_url'], 'data:image/png;base64,'));
$p = chatOpenAiFilePart('application/pdf', 'JVBE', 2);
check('PDF ek input_file', $p['type'] === 'input_file' && $p['filename'] === 'belge-2.pdf');
check('kaynak adları benzersiz', chatSourceNames([['source' => 'a'], ['source' => 'b'], ['source' => 'a']]) === ['a', 'b']);
putenv('OPENAI_CHAT_MODEL=test-model'); putenv('OPENAI_REASONING_EFFORT=low'); putenv('OPENAI_TEMPERATURE=0.4');
$_ENV['OPENAI_CHAT_MODEL'] = 'test-model'; $_ENV['OPENAI_REASONING_EFFORT'] = 'low'; $_ENV['OPENAI_TEMPERATURE'] = '0.4';
$pl = openaiChatPayload($ins, $in, 'bot-7');
check('istek: model .env\'den, akış açık, saklama kapalı, önbellek anahtarı, ayarlar',
    $pl['model'] === 'test-model' && $pl['stream'] === true && $pl['store'] === false && $pl['prompt_cache_key'] === 'bot-7'
    && ($pl['reasoning']['effort'] ?? '') === 'low' && $pl['temperature'] === 0.4 && $pl['instructions'] === $ins
    && ($pl['prompt_cache_options']['mode'] ?? '') === 'explicit', json_encode(array_diff_key($pl, ['input' => 1, 'instructions' => 1])));

finish();
