<?php
/**
 * Gemini teşhis öz-testi — çevrimdışı, ağa çıkmaz, veritabanına dokunmaz.
 *
 *   php api/database/gemini_selftest.php
 *
 * A) Google hata gövdesi (dizi içinde ya da düz) durum / neden / mesaja ayrışıyor.
 * B) Log ve admin çıktısında anahtar hiçbir biçimde (ham, URL-kodlu) görünmüyor.
 * C) Sohbet ucu modeli ve URL'yi tek kaynaktan (functions/gemini.php) alıyor.
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

$lib = __DIR__ . '/../functions/gemini.php';
if (is_file($lib)) {
    require_once $lib;
}
if (!function_exists('geminiParseError') || !function_exists('geminiErrorSummary') || !defined('GEMINI_MODEL')) {
    check('functions/gemini.php: GEMINI_MODEL, geminiParseError, geminiErrorSummary', false, 'tanımlı değil');
    finish();
}

$key = 'AIzaTEST-anahtar_123/+=';

echo "\n=== A) Google hata gövdesi ayrıştırma ===\n\n";
$invalid = '[{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT",'
         . '"details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"API_KEY_INVALID","domain":"googleapis.com"}]}}]';
$e = geminiParseError($invalid);
check('dizi içindeki hata: status', $e['status'] === 'INVALID_ARGUMENT', $e['status']);
check('dizi içindeki hata: reason', $e['reason'] === 'API_KEY_INVALID', $e['reason']);
check('dizi içindeki hata: mesaj', str_starts_with($e['message'], 'API key not valid'), $e['message']);
$e = geminiParseError('{"error":{"code":404,"message":"models/x is not found","status":"NOT_FOUND"}}');
check('düz hata: NOT_FOUND (model kaldırılmış)', $e['status'] === 'NOT_FOUND' && $e['reason'] === '');
$e = geminiParseError('<html>Bad Gateway</html>');
check('JSON olmayan gövde: mesaj kırpılmış ham metin', $e['status'] === '' && str_contains($e['message'], 'Bad Gateway'));

echo "\n=== B) Anahtar sızmıyor ===\n\n";
$echoed = '{"error":{"code":403,"message":"Consumer \'api_key:' . $key . '\' has been suspended. url=?key=' . urlencode($key) . '","status":"PERMISSION_DENIED"}}';
$s = geminiErrorSummary(403, '', $echoed, $key);
check('özet HTTP kodunu içeriyor', str_contains($s, 'http=403'), $s);
check('özet Google durumunu içeriyor', str_contains($s, 'PERMISSION_DENIED'), $s);
check('ham anahtar yok', !str_contains($s, $key), $s);
check('URL-kodlu anahtar yok', !str_contains($s, urlencode($key)), $s);
check('maskelenmiş işaret var', str_contains($s, '«gizli»'), $s);
$s = geminiErrorSummary(0, 'Could not resolve host', '', $key);
check('ağ hatası: curl mesajı', str_contains($s, 'http=0') && str_contains($s, 'Could not resolve host'), $s);

echo "\n=== C) Tek kaynak ===\n\n";
$url = geminiStreamUrl($key);
check('URL kodun modelini kullanıyor', str_contains($url, '/models/' . GEMINI_MODEL . ':streamGenerateContent?alt=sse'), $url);
$ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/ChatController.php');
check('ChatController modeli sabit metin olarak tutmuyor', !preg_match('#/models/gemini-[^:\'"]+:#', $ctl));
check('ChatController geminiStreamUrl kullanıyor', str_contains($ctl, 'geminiStreamUrl('));
check('ChatController hatayı geminiErrorSummary ile logluyor (ham gövde değil)', str_contains($ctl, 'geminiErrorSummary(')
    && !str_contains($ctl, "mb_substr(\$errorBody"));

echo "\n=== D) Coin: cevap kaydedildiyse düşer, üretilemediyse iade (N-48) ===\n\n";
if (!function_exists('chatReplyOutcome')) {
    check('chatReplyOutcome tanımlı', false, 'yok');
    finish();
}
check('200 + metin → kaydet (coin düşer)', chatReplyOutcome(200, '', 'Merhaba!') === 'save');
check('200 + boş metin (güvenlik engeli / boş akış) → iade', chatReplyOutcome(200, '', '') === 'refund');
check('200 + yalnızca boşluk → iade', chatReplyOutcome(200, '', "  \n") === 'refund');
check('cURL zaman aşımı, yarım metin → iade (kaydedilmez)', chatReplyOutcome(200, 'Operation timed out after 30000 ms', 'yarım') === 'refund');
check('ağ hatası (http=0) → iade', chatReplyOutcome(0, 'Could not resolve host', '') === 'refund');
check('Google 429 / 503 / 403 → iade', chatReplyOutcome(429, '', '') === 'refund' && chatReplyOutcome(503, '', '') === 'refund' && chatReplyOutcome(403, '', '') === 'refund');
check('ChatController kararı chatReplyOutcome ile veriyor', str_contains($ctl, 'chatReplyOutcome('));

echo "\n=== E) Süre zinciri: sunucu, arayüzden ve Vercel'den önce biter (N-48) ===\n\n";
$page = (string) file_get_contents(__DIR__ . '/../../web/src/app/dashboard/chat/page.jsx');
preg_match('/const CHAT_REPLY_TIMEOUT_MS = (\d[\d_]*);/', $page, $m);
$clientMs = isset($m[1]) ? (int) str_replace('_', '', $m[1]) : 0;
$vercelMs = 120000; // Vercel harici rewrite: ilk bayta kadar en fazla 120 sn (vercel.com/docs/limits)
check('defined: GEMINI_TIMEOUT, GEMINI_CONNECT_TIMEOUT, CHAT_PHP_TIME_LIMIT', defined('GEMINI_TIMEOUT') && defined('GEMINI_CONNECT_TIMEOUT') && defined('CHAT_PHP_TIME_LIMIT'));
if (defined('GEMINI_TIMEOUT') && defined('CHAT_PHP_TIME_LIMIT')) {
    check('cURL (bağlantı + toplam) < PHP süre sınırı', GEMINI_CONNECT_TIMEOUT <= GEMINI_TIMEOUT && GEMINI_TIMEOUT + 10 <= CHAT_PHP_TIME_LIMIT,
        GEMINI_TIMEOUT . ' / ' . CHAT_PHP_TIME_LIMIT);
    check('arayüz zaman aşımı (CHAT_REPLY_TIMEOUT_MS) cURL\'den en az 15 sn uzun', $clientMs >= (GEMINI_TIMEOUT + 15) * 1000, "istemci=$clientMs");
    check('arayüz zaman aşımı Vercel sınırından kısa', $clientMs > 0 && $clientMs < $vercelMs, "istemci=$clientMs");
}
check('ChatController: ignore_user_abort(true) — istemci kesse de kaydet/iade et', str_contains($ctl, 'ignore_user_abort(true)'));
check('ChatController: set_time_limit(CHAT_PHP_TIME_LIMIT)', str_contains($ctl, 'set_time_limit(CHAT_PHP_TIME_LIMIT)'));
check('ChatController: cURL süreleri sabitlerden', str_contains($ctl, 'CURLOPT_TIMEOUT        => GEMINI_TIMEOUT') && str_contains($ctl, 'CURLOPT_CONNECTTIMEOUT => GEMINI_CONNECT_TIMEOUT'));
check('ChatController: düşünme ayarı istekte (generationConfig)', str_contains($ctl, 'geminiGenerationConfig()'));
check('düşünme seviyesi sohbet için düşük', in_array(geminiGenerationConfig()['thinkingConfig']['thinkingLevel'] ?? '', ['minimal', 'low'], true));
check('meta karesi after_id taşıyor (yeniden sorgulama için)', str_contains($ctl, "'after_id'"));
check('arayüz zaman aşımında kaydedilmiş cevabı getchat ile yeniden soruyor', str_contains($page, 'pollSavedReply(') && str_contains($page, 'afterId'));

echo "\n=== F) Sohbet geçmişi Gemini'ye gidiyor (N-50) ===\n\n";
$ctxLib = __DIR__ . '/../functions/chat_context.php';
if (is_file($ctxLib)) {
    require_once $ctxLib;
}
if (!function_exists('chatBuildContents')) {
    check('functions/chat_context.php: chatBuildContents', false, 'tanımlı değil');
    finish();
}
$greet = 'Merhaba! Ben LUMANORIS AI. Sana nasıl yardımcı olabilirim?';
$rows = [
    ['sent_by' => 'bot',  'message' => $greet],                      // karşılama (sohbet başı)
    ['sent_by' => 'user', 'message' => 'naber'],
    ['sent_by' => 'bot',  'message' => 'İyiyim, sen nasılsın?'],
    ['sent_by' => 'user', 'message' => 'gelmedi senden bir haber'],  // istemcinin addchat'i önce yazdıysa
];
$c = chatBuildContents($rows, $greet, 'gelmedi senden bir haber');
$roles = array_column($c, 'role');
check('roller sırayla user/model/user', $roles === ['user', 'model', 'user'], implode(',', $roles));
check('karşılama metni geçmişe girmiyor', !str_contains(json_encode($c, JSON_UNESCAPED_UNICODE), 'Sana nasıl yardımcı'));
check('önceki bot cevabı model turu olarak gidiyor', ($c[1]['parts'][0]['text'] ?? '') === 'İyiyim, sen nasılsın?');
check('güncel mesaj son turda, tek kez', ($c[2]['parts'][0]['text'] ?? '') === 'gelmedi senden bir haber'
    && substr_count(json_encode($c, JSON_UNESCAPED_UNICODE), 'gelmedi senden bir haber') === 1);

// istemcinin addchat'i henüz yazmadıysa (yarış): güncel mesaj yine sonda
$c = chatBuildContents(array_slice($rows, 0, 3), $greet, 'gelmedi senden bir haber');
check('kullanıcı mesajı henüz kayıtta yokken de sonda', array_column($c, 'role') === ['user', 'model', 'user']
    && $c[2]['parts'][0]['text'] === 'gelmedi senden bir haber');

// karşılama kullanıcı sorusundan SONRA kaydedilmişse (AUDIT adayı) — yine atlanır
$c = chatBuildContents([['sent_by' => 'user', 'message' => 'selam'], ['sent_by' => 'bot', 'message' => $greet]], $greet, 'nasılsın');
check('soru sonrası kaydedilmiş karşılama da atlanıyor; ardışık user birleşiyor', array_column($c, 'role') === ['user']
    && count($c[0]['parts']) === 2);

// model ile başlayan geçmiş: ilk tur user olmalı
$c = chatBuildContents([['sent_by' => 'bot', 'message' => 'eski cevap'], ['sent_by' => 'user', 'message' => 'a']], '', 'b');
check('model ile başlayan geçmiş: baştaki model turu atılıyor', ($c[0]['role'] ?? '') === 'user');

// dosya parçaları son kullanıcı turunda
$c = chatBuildContents([], $greet, 'bu dosyada ne var?', [['inline_data' => ['mime_type' => 'application/pdf', 'data' => 'QQ==']]]);
check('dosya son turda, metinden sonra', count($c) === 1 && isset($c[0]['parts'][1]['inline_data']));

// sınırlar
$many = [];
for ($i = 0; $i < 60; $i++) {
    $many[] = ['sent_by' => $i % 2 ? 'bot' : 'user', 'message' => "m$i " . str_repeat('x', 900)];
}
$c = chatBuildContents($many, '', 'son');
$msgs = 0; $chars = 0;
foreach ($c as $turn) foreach ($turn['parts'] as $p) { $msgs++; $chars += mb_strlen($p['text'] ?? ''); }
check('en fazla CHAT_HISTORY_MAX_MESSAGES geçmiş mesajı + güncel', $msgs <= CHAT_HISTORY_MAX_MESSAGES + 1, "parça=$msgs");
check('geçmiş CHAT_HISTORY_MAX_CHARS sınırında', $chars <= CHAT_HISTORY_MAX_CHARS + mb_strlen('son'), "karakter=$chars");
check('en yeni mesajlar korunuyor (en eskiler atılıyor)', str_contains(json_encode($c), 'm59 ') && !str_contains(json_encode($c), '"m0 '));

$ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/ChatController.php');
check('ChatController geçmişi chatBuildContents ile kuruyor', str_contains($ctl, 'chatBuildContents('));
check('ChatController sistem talimatını systemInstruction alanında gönderiyor', str_contains($ctl, "'systemInstruction'"));
// N-54 — canlı: "naber" / "gelmedi senden bir haber" cevapları "Merhaba! Ben
// LUMANORIS AI, … resmi yapay zeka asistanıyım" ile başlıyordu. Karşılama
// mesajı botu zaten tanıtıyor; cevaplar hiç tanıtım/selamlama ile başlamamalı
// ve bu kural persona metninden SONRA gelmeli (model sondaki metne daha çok uyuyor).
$sysPos   = strpos($ctl, '[KİŞİLİK/STİL]');
$rulesPos = strpos($ctl, '[SOHBET KURALLARI]');
check('talimat: kendini tanıtma yasak (kullanıcı sormadıkça)', str_contains($ctl, 'Kendini tanıtma'));
check('talimat: cevaba selamlamayla başlama', str_contains($ctl, 'selamlamayla başlama'));
check('talimat: sohbet kuralları persona/stil metninden sonra', $sysPos !== false && $rulesPos !== false && $rulesPos > $sysPos);
check('eski "yalnızca ilk mesajda tanıt" kuralı kalmadı', !str_contains($ctl, 'yalnızca sohbetin ilk mesajında'));

echo "\n=== G) Coin tek kaynaktan (N-50) ===\n\n";
$layout = (string) file_get_contents(__DIR__ . '/../../web/src/app/dashboard/layout.jsx');
check('layout coin ayarlayıcısını UserContext ile paylaşıyor', str_contains($layout, 'setDailyCoinsRemaining') && str_contains($layout, 'UserContext.Provider'));
check('sohbet sayfası sunucudan gelen coin değerini paylaşılan bağlama yazıyor', str_contains($page, 'setDailyCoinsRemaining?.('));
check('sohbet çipi paylaşılan değeri gösteriyor', str_contains($page, 'sharedCoins'));

finish();
