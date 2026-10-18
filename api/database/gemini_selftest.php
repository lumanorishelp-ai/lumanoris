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
// N-59: ilk deneme GEMINI_TIMEOUT ile; ikinci deneme süresi geminiRetryPlan'dan
// (PHP sınırının ≥5 sn altında biter — H bölümü).
check('ChatController: cURL süreleri sabitlerden (ilk deneme GEMINI_TIMEOUT)', str_contains($ctl, '$runAttempt(GEMINI_TIMEOUT)') && str_contains($ctl, 'CURLOPT_CONNECTTIMEOUT => GEMINI_CONNECT_TIMEOUT'));
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

echo "\n=== H) Hata türü ve tek otomatik yeniden deneme (N-59) ===\n\n";
if (!function_exists('geminiFailureKind') || !function_exists('geminiRetryPlan') || !function_exists('geminiErrorStats')) {
    check('functions/gemini.php: geminiFailureKind, geminiRetryPlan, geminiErrorStats', false, 'tanımlı değil');
    finish();
}
// Gövdeler Google'ın gerçek biçiminde (BLOCKERS B4, 2026-10-18 canlı gözlem).
$b503 = '[{"error":{"code":503,"message":"This model is currently experiencing high demand. Spikes in demand are usually temporary. Please try again later.","status":"UNAVAILABLE"}}]';
$b429min = '[{"error":{"code":429,"message":"Resource has been exhausted (e.g. check quota).","status":"RESOURCE_EXHAUSTED","details":[{"@type":"type.googleapis.com/google.rpc.RetryInfo","retryDelay":"2s"}]}}]';
$b429day = '[{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details.","status":"RESOURCE_EXHAUSTED","details":[{"@type":"type.googleapis.com/google.rpc.QuotaFailure","violations":[{"quotaId":"GenerateRequestsPerDayPerProjectPerModel-FreeTier"}]},{"@type":"type.googleapis.com/google.rpc.RetryInfo","retryDelay":"53s"}]}}]';
$b403 = '{"error":{"code":403,"message":"API key not valid","status":"PERMISSION_DENIED"}}';

check('503 UNAVAILABLE → busy', geminiFailureKind(503, '', $b503) === 'busy');
check('429 dakikalık → busy', geminiFailureKind(429, '', $b429min) === 'busy');
check('429 günlük kota → quota', geminiFailureKind(429, '', $b429day) === 'quota');
check('500 / 502 / 504 → unavailable', geminiFailureKind(500, '', '') === 'unavailable' && geminiFailureKind(502, '', '') === 'unavailable' && geminiFailureKind(504, '', '') === 'unavailable');
check('cURL zaman aşımı → timeout', geminiFailureKind(0, 'Operation timed out after 30001 milliseconds with 0 bytes received', '') === 'timeout');
check('cURL bağlantı hatası → network', geminiFailureKind(0, 'Could not resolve host: generativelanguage.googleapis.com', '') === 'network');
check('400/403/404 → config (yöneticiye)', geminiFailureKind(403, '', $b403) === 'config' && geminiFailureKind(404, '', '') === 'config' && geminiFailureKind(400, '', '') === 'config');
check('200 + boş metin → empty', geminiFailureKind(200, '', '') === 'empty');

$p = geminiRetryPlan(503, '', $b503, 1.2, false);
check('503, akış başlamadı, 1,2 sn geçti → tekrar dene (~1 sn bekle)', $p['retry'] && $p['delay_ms'] >= 500 && $p['delay_ms'] <= 2000, json_encode($p));
check('ikinci denemenin zaman aşımı PHP sınırının ≥5 sn altında biter', $p['retry'] && 1.2 + $p['delay_ms'] / 1000 + $p['timeout'] <= CHAT_PHP_TIME_LIMIT - 5 && $p['timeout'] >= 10, json_encode($p));
$p = geminiRetryPlan(429, '', $b429min, 0.4, false);
check('429 retryDelay=2s → 2 sn bekleyip tekrar', $p['retry'] && $p['delay_ms'] === 2000, json_encode($p));
check('429 günlük kota (retryDelay=53s) → tekrar DENEME (boşa istek)', !geminiRetryPlan(429, '', $b429day, 0.4, false)['retry']);
check('akış başladıysa (metin gönderildi) → tekrar deneme yok', !geminiRetryPlan(503, '', $b503, 1.0, true)['retry']);
check('ilk deneme uzun sürdüyse (12 sn) → bütçe yok', !geminiRetryPlan(503, '', $b503, 12.0, false)['retry']);
check('cURL zaman aşımı (30 sn) → tekrar yok', !geminiRetryPlan(0, 'Operation timed out after 30001 milliseconds', '', 30.0, false)['retry']);
check('bağlantı hatası, hızlı → tekrar', geminiRetryPlan(0, 'Could not resolve host: x', '', 0.1, false)['retry']);
check('403 anahtar → tekrar yok', !geminiRetryPlan(403, '', $b403, 0.3, false)['retry']);
check('200 boş → tekrar yok (güvenlik engeli olabilir)', !geminiRetryPlan(200, '', '', 3.0, false)['retry']);

check('ChatController: yeniden deneme planı geminiRetryPlan ile', str_contains($ctl, 'geminiRetryPlan('));
check('ChatController: cURL toplam süresi plandan (sabit değil, bütçeli)', str_contains($ctl, 'CURLOPT_TIMEOUT        => $timeout'));
check('ChatController: hata karesi tür (kind) taşıyor', str_contains($ctl, "'kind'"));
check('ChatController: iade kararı yine chatReplyOutcome ile (N-48)', str_contains($ctl, 'chatReplyOutcome('));
check('arayüz: tür → mesaj eşlemesi tek yerde (chatFailureText)', str_contains($page, 'chatFailureText('));

echo "\n=== J) Bağlam boyutu ve olgusal doğruluk (N-60) ===\n\n";
if (!function_exists('chatSelectCorpus') || !defined('CHAT_CORPUS_BUDGET')) {
    check('functions/chat_context.php: chatSelectCorpus, CHAT_CORPUS_BUDGET', false, 'tanımlı değil');
} else {
    $small = "Kısa bir bilgi bankası.\n\nİkinci paragraf.";
    check('bütçe altındaki metin aynen döner', chatSelectCorpus($small, 'herhangi') === $small);

    $topics = ['astronomi gezegen yörünge', 'mutfak tarif hamur', 'futbol kaleci penaltı', 'mantık kıyas önerme',
               'botanik yaprak fotosentez', 'denizcilik gemi liman', 'müzik nota akor', 'kimya molekül bağ'];
    $sections = [];
    for ($i = 0; $i < 40; $i++) {
        $t = $topics[$i % count($topics)];
        $sections[] = "BÖLÜM $i — " . ($i === 0 ? 'Giriş: bu botun tanıtımı. ' : '')
            . str_repeat("Bu bölüm $t konusunu anlatır ve ayrıntı verir. ", 30);
    }
    $sections[23] = 'BÖLÜM 23 — Aristoteles mantığında kıyas, iki öncülden bir sonuç çıkaran akıl yürütmedir. '
        . str_repeat('Mantık tarihinde kıyasın yeri büyüktür ve önermeler sınıflanır. ', 30);
    $big = implode("\n\n", $sections);
    $sel = chatSelectCorpus($big, "Aristoteles'in mantığındaki kıyas nedir?");
    check('büyük metin bütçeye iniyor', mb_strlen($sel) <= CHAT_CORPUS_BUDGET && mb_strlen($big) > CHAT_CORPUS_BUDGET,
        mb_strlen($big) . ' → ' . mb_strlen($sel));
    check('soruyla ilgili bölüm seçildi (Türkçe ek: mantığındaki → mantık)', str_contains($sel, 'BÖLÜM 23 —'));
    check('giriş bölümü her zaman korunuyor', str_contains($sel, 'BÖLÜM 0 —'));
    check('ilgisiz bölüm (futbol) seçilmedi', !str_contains($sel, 'futbol kaleci'));
    $p0 = strpos($sel, 'BÖLÜM 0 —'); $p23 = strpos($sel, 'BÖLÜM 23 —');
    check('özgün sıra korunuyor', $p0 !== false && $p23 !== false && $p0 < $p23);
    check('atlanan yerler işaretli', str_contains($sel, '[…]'));
    $none = chatSelectCorpus($big, 'xyzqw qwerty');
    check('hiç eşleşme yoksa baştan kesilir (eski davranış), bütçede', str_starts_with($none, 'BÖLÜM 0 —') && mb_strlen($none) <= CHAT_CORPUS_BUDGET);
    $follow = chatSelectCorpus($big, 'peki örnek verir misin?', 'Aristoteles mantığında kıyas nedir?');
    check('takip sorusu: önceki kullanıcı mesajı da bağlam sayılıyor', str_contains($follow, 'BÖLÜM 23 —'));
    $oneBlock = str_repeat('Tek parça çok uzun bir metin, paragraf yok. ', 2000);
    check('paragrafsız dev metin de bütçeye iniyor', mb_strlen(chatSelectCorpus($oneBlock, 'metin')) <= CHAT_CORPUS_BUDGET);
}
check('ChatController: bilgi kaynağı chatSelectCorpus ile', str_contains($ctl, 'chatSelectCorpus('));
check('talimat: emin olmadığın bilgiyi uydurma', str_contains($ctl, 'uydurma'));
check('talimat: kişiye ait olmayan görüşü ona atfetme', str_contains($ctl, 'atfetme'));
check('istek temperature/topP/topK göndermiyor (3.8 Flash\'ta kullanımdan kaldırıldı)',
    !preg_match('/[\'"](temperature|topP|topK|top_p|top_k)[\'"]/', $ctl . json_encode(geminiGenerationConfig())));

echo "\n=== I) Son 24 saat hata sayımı (admin Sistem Durumu) ===\n\n";
$now = strtotime('2026-10-18 12:00:00 UTC');
$lines = [
    '[18-Oct-2026 11:50:00 UTC] [gemini] generatereply hata: http=503 model=x status=UNAVAILABLE message=high demand deneme=1/2',
    '[18-Oct-2026 11:50:02 UTC] [gemini] tekrar deneme başarılı: ilk=http=503',
    '[18-Oct-2026 10:00:00 UTC] [gemini] generatereply hata: http=429 model=x status=RESOURCE_EXHAUSTED tür=quota deneme=1/1',
    '[18-Oct-2026 09:00:00 UTC] [gemini] generatereply hata: http=0 model=x curl=Operation timed out after 30001 milliseconds',
    '[18-Oct-2026 08:00:00 UTC] [gemini] generatereply hata: http=200 model=x boş cevap (metin yok)',
    '[18-Oct-2026 07:00:00 UTC] [gemini] admin test hata: http=403 model=x status=PERMISSION_DENIED',
    '[16-Oct-2026 07:00:00 UTC] [gemini] generatereply hata: http=503 model=x status=UNAVAILABLE',
    '[18-Oct-2026 11:00:00 UTC] [readpdf] başka bir satır',
    // N-59 öncesi (tür= yok) canlı satır biçimi: 429 "exceeded your current quota" → kota
    '[18-Oct-2026 06:40:55 UTC] [gemini] generatereply hata: http=429 model=x status=RESOURCE_EXHAUSTED message=You exceeded your current quota, please check your plan and billing details.',
];
$s = geminiErrorStats($lines, $now);
check('24 saat dışı satır sayılmıyor', $s['total'] === 6, json_encode($s, JSON_UNESCAPED_UNICODE));
check('türler: busy 1, quota 2 (biri eski etiketsiz satır), timeout 1, empty 1, config 1', ($s['by_kind']['busy'] ?? 0) === 1 && ($s['by_kind']['quota'] ?? 0) === 2
    && ($s['by_kind']['timeout'] ?? 0) === 1 && ($s['by_kind']['empty'] ?? 0) === 1 && ($s['by_kind']['config'] ?? 0) === 1, json_encode($s['by_kind']));
check('tekrar denemeyle kurtarılan sayılıyor', $s['recovered'] === 1);
check('son hata zamanı', $s['last'] === '18-Oct-2026 11:50:00 UTC', (string) $s['last']);
check('anahtar/ham gövde döndürmüyor (yalnızca sayılar ve zaman)', !str_contains(json_encode($s), 'high demand'));

finish();
