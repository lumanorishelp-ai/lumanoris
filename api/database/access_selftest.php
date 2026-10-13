<?php
/**
 * Erişim / listeleme öz-testi (Faz 4, madde 10) — KALICI YAZMA YOK.
 *
 *   php api/database/access_selftest.php
 *
 * Senaryo verisi (ücretsiz herkese açık bot, pasif satıcı) tek bir
 * transaction içinde kurulur ve sonda ROLLBACK edilir.
 *
 * Kilitlenen kurallar (AUDIT.md, Faz 4 matrisi):
 *   • Özel bot: kimseye görünmez, kimse sohbet edemez (sahibi hariç).
 *   • Ücretsiz herkese açık: vitrinde, herkes sohbet eder; persona/eğitim
 *     ('full') kapalı.
 *   • Fiyatlı + aktif satıcı: vitrinde, abonelik olmadan sohbet YOK.
 *   • Fiyatlı + pasif satıcı: vitrinde değil, sohbet yok.
 *   • Persona (`style_prompt`) yalnızca sahibin getDetail yanıtında.
 *
 * `api/database/` üç denylist tarafından da web'e kapalı.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $label\n"; }
    else     { $fail++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$db   = Database::getInstance();
$conn = $db->getConnection();
$repo = new ChatbotRepository();

$priced = $db->selectSingle(
    "c.id, c.author_user_id FROM chatbotlar c
     JOIN param_marketplace_sellers p ON p.user_id = c.author_user_id AND p.status = 'active'
     WHERE c.is_independent = 0 AND c.ucret_haftalik > 0 ORDER BY c.id LIMIT 1"
);
$private = $db->selectSingle('c.id, c.author_user_id FROM chatbotlar c WHERE c.is_independent = 1 ORDER BY c.id LIMIT 1');
if (!$priced || !$private) {
    echo "  (senaryo için gereken bot yok: fiyatlı+aktif satıcı ve özel bot gerekli — atlandı)\n";
    echo "\n=== SONUÇ: $pass geçti, $fail başarısız ===\n\n";
    exit(0);
}
$freeCandidate = $db->selectSingle(
    "c.id, c.author_user_id FROM chatbotlar c
     LEFT JOIN param_marketplace_sellers p ON p.user_id = c.author_user_id AND p.status = 'active'
     WHERE c.is_independent = 1 AND p.user_id IS NULL AND c.id <> ? ORDER BY c.id LIMIT 1",
    [$private['id']]
);
$stranger = $db->selectSingle(
    "k.id FROM kullanicilar k
     WHERE k.id NOT IN (?, ?, ?)
       AND NOT EXISTS (SELECT 1 FROM user_subscriptions us WHERE us.user_id = k.id AND us.status = 1 AND us.expiry_date > NOW())
     ORDER BY k.id LIMIT 1",
    [$priced['author_user_id'], $private['author_user_id'], $freeCandidate['author_user_id'] ?? 0]
);
$U = (int) $stranger['id'];

$listed = function (int $id) use ($repo): bool {
    foreach ($repo->getPublished(['limit' => 200]) as $b) {
        if ((int) $b['id'] === $id) return true;
    }
    return false;
};
$persona = fn(?array $d) => $d !== null && array_key_exists('style_prompt', $d);

echo "\n=== Erişim matrisi (U = #$U: sahip değil, aboneliği yok) ===\n\n";

$conn->beginTransaction();
try {
    // Özel bot
    $id = (int) $private['id'];
    check("özel #$id: vitrinde değil", !$listed($id));
    check("özel #$id: U preview yok", !$repo->userHasAccess($id, $U, 'preview'));
    check("özel #$id: U sohbet yok", !$repo->userHasAccess($id, $U, 'chat'));
    check("özel #$id: U detay yok", $repo->getDetail($id, $U) === null);
    check("özel #$id: sahibi sohbet eder", $repo->userHasAccess($id, (int) $private['author_user_id'], 'chat'));

    // Ücretsiz herkese açık (yazarı satıcı değil)
    if ($freeCandidate) {
        $id = (int) $freeCandidate['id'];
        $db->execute('UPDATE chatbotlar SET is_independent = 0, ucret_haftalik = NULL, ucret_aylik = NULL WHERE id = ?', [$id]);
        check("ücretsiz #$id: vitrinde", $listed($id));
        check("ücretsiz #$id: U preview var", $repo->userHasAccess($id, $U, 'preview'));
        check("ücretsiz #$id: U sohbet eder", $repo->userHasAccess($id, $U, 'chat'));
        check("ücretsiz #$id: U full (persona/eğitim) YOK", !$repo->userHasAccess($id, $U, 'full'));
        $d = $repo->getDetail($id, $U);
        check("ücretsiz #$id: getDetail has_access", ($d['has_access'] ?? false) === true);
        check("ücretsiz #$id: getDetail persona YOK", !$persona($d));
        check("ücretsiz #$id: sahibine persona döner", $persona($repo->getDetail($id, (int) $freeCandidate['author_user_id'])));
        // N-24: istemci "Sepete Ekle"yi sahibe göstermemek için bunu okur.
        check("ücretsiz #$id: getDetail is_owner false (U)", ($d['is_owner'] ?? null) === false);
        check("ücretsiz #$id: getDetail is_owner true (sahip)", ($repo->getDetail($id, (int) $freeCandidate['author_user_id'])['is_owner'] ?? null) === true);
    } else {
        echo "  (satıcı olmayan yazarın özel botu yok — ücretsiz senaryo atlandı)\n";
    }

    // Fiyatlı + aktif satıcı
    $id = (int) $priced['id'];
    check("fiyatlı-aktif #$id: vitrinde", $listed($id));
    check("fiyatlı-aktif #$id: U preview var", $repo->userHasAccess($id, $U, 'preview'));
    check("fiyatlı-aktif #$id: U abonelik olmadan sohbet YOK", !$repo->userHasAccess($id, $U, 'chat'));
    $d = $repo->getDetail($id, $U);
    check("fiyatlı-aktif #$id: getDetail has_access false", ($d['has_access'] ?? null) === false);
    check("fiyatlı-aktif #$id: getDetail persona YOK", !$persona($d));
    check("fiyatlı-aktif #$id: getDetail is_owner false (U)", ($d['is_owner'] ?? null) === false);

    // N-27 — ana sayfa bot seçicisinin kilidi sohbet kuralıyla AYNI kaynaktan.
    if (!method_exists($repo, 'chatAccessibleIds')) {
        check('N-27: ChatbotRepository::chatAccessibleIds tanımlı', false, 'metot yok');
    } else {
        $ids = array_values(array_filter([$freeCandidate ? (int) $freeCandidate['id'] : 0, $id]));
        $got = $repo->chatAccessibleIds($ids, $U);
        $want = array_values(array_filter($ids, fn($x) => $repo->userHasAccess($x, $U, 'chat')));
        sort($got); sort($want);
        check('N-27: seçici erişimi = userHasAccess(chat) [' . implode(',', $ids) . ']', $got === $want, json_encode(['got' => $got, 'want' => $want]));
        if ($freeCandidate) check('N-27: ücretsiz herkese açık bot seçicide açık', in_array((int) $freeCandidate['id'], $got, true));
        check("N-27: fiyatlı #$id (abonelik yok) seçicide kilitli", !in_array($id, $got, true));
    }

    // Fiyatlı + pasif satıcı
    $other = $db->selectSingle(
        'c.id, c.author_user_id FROM chatbotlar c WHERE c.is_independent = 0 AND c.ucret_haftalik > 0 AND c.author_user_id <> ? ORDER BY c.id LIMIT 1',
        [$priced['author_user_id']]
    );
    if ($other) {
        $db->execute("UPDATE param_marketplace_sellers SET status = 'suspended' WHERE user_id = ?", [$other['author_user_id']]);
        $id = (int) $other['id'];
        check("fiyatlı-pasif #$id: vitrinde değil", !$listed($id));
        check("fiyatlı-pasif #$id: U preview yok", !$repo->userHasAccess($id, $U, 'preview'));
        check("fiyatlı-pasif #$id: U sohbet yok", !$repo->userHasAccess($id, $U, 'chat'));
    } else {
        echo "  (ayrı yazarlı ikinci fiyatlı bot yok — pasif satıcı senaryosu atlandı)\n";
    }
} finally {
    $conn->rollBack();
}

check('rollback sonrası senaryo verisi geri alındı',
    !$freeCandidate || (int) $db->selectSingle('is_independent FROM chatbotlar WHERE id = ?', [$freeCandidate['id']])['is_independent'] === 1);

echo "\n--- N-30: Diyalog Defteri yalnızca gerçek mesajdan, görünürlük vitrinle aynı ---\n";
$dbLib = __DIR__ . '/../functions/dialog_book.php';
if (is_file($dbLib)) require_once $dbLib;
$need30 = ['dialogBookCreateFromMessage', 'dialogBookIsVisible', 'dialogBookFeed', 'chatMessageWriteCheck', 'geminiSseText', 'chatSaveBotReply'];
$missing30 = array_values(array_filter($need30, fn($f) => !function_exists($f)));
if ($missing30 !== []) {
    check('N-30: dialog_book.php fonksiyonları tanımlı', false, implode(', ', $missing30));
} elseif (!$freeCandidate) {
    echo "  (ücretsiz aday bot yok — N-30 atlandı)\n";
} else {
    $free  = (int) $freeCandidate['id'];
    $priv  = (int) $private['id'];
    $owner = (int) $private['author_user_id'];
    $viewer = (int) ($db->selectSingle('k.id FROM kullanicilar k WHERE k.id NOT IN (?, ?, ?) ORDER BY k.id LIMIT 1', [$U, $owner, (int) $freeCandidate['author_user_id']])['id'] ?? 0);
    $throws = function (callable $fn, string $class): bool { try { $fn(); } catch (Throwable $e) { return $e instanceof $class; } return false; };
    $conn->beginTransaction();
    try {
        $db->execute('UPDATE chatbotlar SET is_independent = 0, ucret_haftalik = NULL, ucret_aylik = NULL WHERE id = ?', [$free]);

        // Sunucu akıştan cevabı çıkarıp kendisi kaydeder (istemci addChat ile "bot" yazamaz).
        $sse = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Kuruluş \"}]}}]}\r\n\r\n"
             . "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"yılı 1987.\"}]}}]}\n\n";
        check('N-30: geminiSseText akıştan metni birleştirir', geminiSseText($sse) === 'Kuruluş yılı 1987.', geminiSseText($sse));

        check('N-30: addChat — kullanıcı mesajı (erişimli bot) kabul', !$throws(fn() => chatMessageWriteCheck($repo, $db, $U, $free, 'user', 'Soru?'), AppException::class));
        check('N-30: addChat — istemciden serbest "bot" metni RED', $throws(fn() => chatMessageWriteCheck($repo, $db, $U, $free, 'bot', 'Uydurma cevap'), ValidationException::class));
        $greet = (string) ($db->selectSingle('sohbet_basi_mesaj FROM chatbotlar WHERE id = ?', [$free])['sohbet_basi_mesaj'] ?? '');
        if (trim($greet) !== '') {
            check('N-30: addChat — botun karşılama mesajı kabul', !$throws(fn() => chatMessageWriteCheck($repo, $db, $U, $free, 'bot', $greet), AppException::class));
        }
        check('N-30: addChat — erişimsiz özel bot RED', $throws(fn() => chatMessageWriteCheck($repo, $db, $U, $priv, 'user', 'x'), PermissionException::class));

        $qId  = (int) $db->insert('chatbot_chats', ['chatbot_id' => $free, 'user_id' => $U, 'sent_by' => 'user', 'message' => 'E2E N-30 soru']);
        $aId  = chatSaveBotReply($db, $U, $free, 'E2E N-30 gerçek cevap');
        $book = dialogBookCreateFromMessage($db, $repo, $U, $aId, 'N-30 başlık');
        $row  = $db->selectSingle('chatbot_id, input_message, output_message, user_id FROM user_dialog_books WHERE id = ?', [$book]);
        check('N-30: defter metni DB\'den kopyalandı', ($row['input_message'] ?? '') === 'E2E N-30 soru' && ($row['output_message'] ?? '') === 'E2E N-30 gerçek cevap' && (int) $row['chatbot_id'] === $free && (int) $row['user_id'] === $U, json_encode($row));

        check('N-30: başkasının mesaj kimliği RED', $throws(fn() => dialogBookCreateFromMessage($db, $repo, $viewer, $aId, 'x'), NotFoundException::class));
        check('N-30: kullanıcı mesajı kimliği RED', $throws(fn() => dialogBookCreateFromMessage($db, $repo, $U, $qId, 'x'), NotFoundException::class));
        $lone = chatSaveBotReply($db, $owner, $priv, 'sorusuz cevap');
        check('N-30: sorusu olmayan cevap RED', $throws(fn() => dialogBookCreateFromMessage($db, $repo, $owner, $lone, 'x'), ValidationException::class));

        // Erişim kaybedilmiş bot: U'nun özel bottaki (geçmişten kalma) mesajı.
        $db->insert('chatbot_chats', ['chatbot_id' => $priv, 'user_id' => $U, 'sent_by' => 'user', 'message' => 'eski soru']);
        $old = chatSaveBotReply($db, $U, $priv, 'eski cevap');
        check('N-30: erişimi olmayan bot için kayıt RED', $throws(fn() => dialogBookCreateFromMessage($db, $repo, $U, $old, 'x'), PermissionException::class));

        // Özel bot kaydı (sahibi paylaştı): yalnızca sahibine görünür.
        $db->insert('chatbot_chats', ['chatbot_id' => $priv, 'user_id' => $owner, 'sent_by' => 'user', 'message' => 'sahip sorusu']);
        $ownA = chatSaveBotReply($db, $owner, $priv, 'sahip cevabı');
        $privBook = dialogBookCreateFromMessage($db, $repo, $owner, $ownA, 'Özel bot kaydı');

        $ids = fn(int $v) => array_map(fn($r) => (int) $r['id'], dialogBookFeed($db, $v, 1000));
        check('N-30: herkese açık bot kaydı başkasının akışında', in_array($book, $ids($viewer), true));
        check('N-30: özel bot kaydı başkasının akışında YOK', !in_array($privBook, $ids($viewer), true));
        check('N-30: özel bot kaydı paylaşanın akışında', in_array($privBook, $ids($owner), true));
        $leak = json_encode(array_values(array_filter(dialogBookFeed($db, $viewer, 1000), fn($r) => (int) $r['chatbot_id'] === $priv)));
        check('N-30: akışta özel botun adı/sahibi sızmıyor', $leak === '[]', $leak);
        check('N-30: gizli kayda kimlikle erişim (etkileşim/yorum) RED', !dialogBookIsVisible($db, $privBook, $viewer));
        check('N-30: gizli kayıt paylaşana görünür', dialogBookIsVisible($db, $privBook, $owner));
        check('N-30: açık kayıt herkese görünür', dialogBookIsVisible($db, $book, $viewer));
    } finally {
        $conn->rollBack();
    }
}

echo "\n--- N-34: bot yorumu istemciden user_id göndermez (kimlik oturumdan) ---\n";
// Sunucu (SocialController::addComment) izin listesi dışındaki anahtarı reddediyor ve user_id'yi
// oturumdan alıyor; bu doğru. Hata istemcideydi. Kilit: addcomment.php'ye istek tek yardımcıdan
// gider ve o yardımcı user_id içermez.
$webSrc = realpath(__DIR__ . '/../../web/src');
$callers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!preg_match('/\.(jsx?|tsx?)$/', $file->getFilename())) continue;
    if (str_contains((string) file_get_contents($file->getPathname()), '/api/social/addcomment.php')) {
        $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($webSrc) + 1));
    }
}
check('N-34: addcomment.php yalnızca features/comments/api.js\'ten çağrılıyor', $callers === ['features/comments/api.js'], implode(', ', $callers));
$helper = (string) @file_get_contents($webSrc . '/features/comments/api.js');
$helperCode = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $helper); // yorumlar değil, kod
check('N-34: yorum yardımcısı user_id göndermiyor', $helper !== '' && !str_contains($helperCode, 'user_id') && str_contains($helperCode, 'chatbot_id: chatbotId, comment'));
$ctrl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/SocialController.php');
check('N-34: sunucu izin listesi gevşetilmedi (chatbot_id, comment)', str_contains($ctrl, "pickAllowed(\$data, ['chatbot_id', 'comment'])"));
check('N-34: sunucu yorumu oturumdaki kullanıcıyla yazıyor', (bool) preg_match("/'chatbot_comments',\s*\[\s*'chatbot_id'\s*=>\s*\\\$chatbotId,\s*'user_id'\s*=>\s*\\\$userId,/", $ctrl));

echo "\n--- N-35: takip/beğeni açık eylemle (idempotent), liste beğeni durumunu taşıyor ---\n";
$relLib = __DIR__ . '/../functions/social_relations.php';
if (is_file($relLib)) require_once $relLib;
if (!function_exists('chatbotRelationSet')) {
    check('N-35: chatbotRelationSet tanımlı (functions/social_relations.php)', false, 'fonksiyon yok');
} else {
    $botForRel = (int) ($repo->getPublished(['limit' => 1, 'offset' => 0])[0]['id'] ?? 0);
    $has = fn(string $t) => (bool) $db->selectSingle("id FROM $t WHERE user_id = ? AND chatbot_id = ?", [$U, $botForRel]);
    $conn->beginTransaction();
    try {
        $db->execute('DELETE FROM chatbot_follows WHERE user_id = ? AND chatbot_id = ?', [$U, $botForRel]);
        $r = chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, 'unfollow');
        check('N-35: takip yokken "unfollow" → değişmez, YENİDEN TAKİP ETMEZ', $r['action'] === 'unchanged' && !$has('chatbot_follows'), json_encode($r));
        $r = chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, 'follow');
        check('N-35: "follow" → takip edildi', $r['action'] === 'followed' && $has('chatbot_follows'), json_encode($r));
        $r = chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, 'follow');
        check('N-35: ikinci "follow" → değişmez (idempotent)', $r['action'] === 'unchanged' && $has('chatbot_follows'), json_encode($r));
        $r = chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, 'unfollow');
        check('N-35: "unfollow" → kaldırıldı ve kalıcı', $r['action'] === 'unfollowed' && !$has('chatbot_follows'), json_encode($r));
        $r1 = chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, null);
        $r2 = chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, null);
        check('N-35: eylemsiz çağrı eskisi gibi toggle (sohbet sayfası değişmedi)', $r1['action'] === 'followed' && $r2['action'] === 'unfollowed', json_encode([$r1, $r2]));

        $db->execute('DELETE FROM chatbot_likes WHERE user_id = ? AND chatbot_id = ?', [$U, $botForRel]);
        $r = chatbotRelationSet($db, 'chatbot_likes', 'liked_at', $U, $botForRel, 'like');
        check('N-35: "like" → beğenildi', $r['action'] === 'liked' && $has('chatbot_likes'), json_encode($r));
        $row = null;
        foreach ($repo->getPublished(['limit' => 200, 'offset' => 0], $U) as $b) { if ((int) $b['id'] === $botForRel) $row = $b; }
        check('N-35: vitrin satırı liked_by_me=1 (beğenen kullanıcı için)', (int) ($row['liked_by_me'] ?? -1) === 1, json_encode($row['liked_by_me'] ?? null));
        $row0 = null;
        foreach ($repo->getPublished(['limit' => 200, 'offset' => 0], 0) as $b) { if ((int) $b['id'] === $botForRel) $row0 = $b; }
        check('N-35: oturumsuz liste liked_by_me=0', (int) ($row0['liked_by_me'] ?? -1) === 0, json_encode($row0['liked_by_me'] ?? null));
        $r = chatbotRelationSet($db, 'chatbot_likes', 'liked_at', $U, $botForRel, 'unlike');
        check('N-35: "unlike" → kaldırıldı', $r['action'] === 'unliked' && !$has('chatbot_likes'), json_encode($r));
        $threw = false; try { chatbotRelationSet($db, 'chatbot_follows', 'followed_at', $U, $botForRel, 'like'); } catch (ValidationException $e) { $threw = true; }
        check('N-35: tabloya uymayan eylem reddedilir', $threw);
    } finally {
        $conn->rollBack();
    }
}

echo "\n--- Arayüz kilitleri (N-37…): durum yalnızca sunucu başarısında, uydurma veri yok ---\n";
// Kaynak düzeyinde gerileme kilitleri; davranış tarayıcıda (zorlanmış 500) doğrulandı.
$web = fn(string $rel) => (string) @file_get_contents(realpath(__DIR__ . '/../../web/src') . '/' . $rel);
$codeOnly = fn(string $src) => (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~\{/\*.*?\*/\}~s'], '', $src);
$hist = $codeOnly($web('app/dashboard/history/page.jsx'));
check('N-37: Geçmişim\'de uydurma sohbet yok', $hist !== '' && !preg_match('/Stripe Entegrasyon|Mimari Analiz|App Router Yeniden/', $hist));
check('N-37: Geçmişim silme başarısızlıkta satırı silmiyor', (bool) preg_match('/if \(res\.ok && result\?\.success\) \{\s*setHistoryItems/', $hist));
check('N-37: başlık değişikliği sunucu yanıtını okuyor ve geri alıyor', str_contains($hist, 'updateconversation.php') && str_contains($hist, 'revert('));
$hdr = $codeOnly($web('widgets/DashboardHeader.jsx'));
check('N-38: "tümünü okundu" yalnızca sunucunun onayladıklarını işaretliyor', (bool) preg_match('/readnotification\.php[\s\S]{0,400}result\?\.success[\s\S]{0,600}done\.has\(n\.id\)/', $hdr));
$side = $codeOnly($web('widgets/Sidebar.jsx'));
$logout = $codeOnly($web('shared/lib/logout.js'));
check('N-39: çıkış yalnızca sunucu success dönünce /login\'e gidiyor', (bool) preg_match('/!result\?\.success[\s\S]{0,200}return[\s\S]{0,400}location\.href = "\/login"/', $logout));
check('N-39: başlık ve kenar çubuğu ortak çıkışı kullanıyor, kendi logout isteği yok', str_contains($hdr, 'logoutAndRedirect()') && str_contains($side, 'logoutAndRedirect()') && !str_contains($hdr . $side, 'logout.php'));
$pc = $codeOnly($web('entities/user/ui/ProfileCard.jsx'));
check('N-40: takip/beğeni/beğenmeme başarısızlıkta hata gösteriyor', (bool) preg_match('/res\.ok && result\?\.success\) return result;\s*toast\(\{ variant: "destructive"/', $pc)
    && substr_count($pc, 'await postRelation(') === 3);
check('N-40: sunucuya bağlı olmayan "Bildirimler Açık/Kapalı" düğmesi yok', $pc !== '' && !str_contains($pc, 'notificationsEnabled'));
check('N-40: sepet durumu localStorage\'dan okunmuyor (yalnızca getcart)', !str_contains($pc, 'localStorage'));
$share = $codeOnly($web('features/sharing/ShareModal.jsx'));
$notes = $codeOnly($web('app/dashboard/notes/page.jsx'));
$clip = $codeOnly($web('shared/lib/clipboard.js'));
check('N-41: paylaşım bağlantısı sohbet sayfasının okuduğu botId parametresini kullanıyor', str_contains($share, '/dashboard/chat/?botId=') && !str_contains($share, '?botid='));
check('N-41: "Kopyalandı" yalnızca pano yazması tamamlanınca (paylaş + diyalog paylaş)', str_contains($clip, 'await navigator.clipboard.writeText')
    && !preg_match('/navigator\.clipboard/', $share . $notes)
    && preg_match_all('/if \(!ok\) return;\s*setCopied\(true\)/', $share . $notes) === 2);
$create = $web('app/dashboard/chatbots/create/page.jsx');
$purch  = $codeOnly($web('app/dashboard/purchased/page.jsx'));
$follow = $codeOnly($web('app/dashboard/following/page.jsx'));
$maxTxt = number_format(AppConfig::MAX_TRAINING_CHARS, 0, ',', '.');
check('N-44: web KB_MAX_CHARS = AppConfig::MAX_TRAINING_CHARS (elle senkron)', (bool) preg_match('/const KB_MAX_CHARS = ' . AppConfig::MAX_TRAINING_CHARS . ';/', $create));
check('N-44: arayüzde RAG / vektör arama iddiası yok', $purch !== '' && $follow !== '' && !preg_match('/\bRAG\b/', $purch . $follow . $codeOnly($create)));
check('N-44: Satın Aldıklarım eğitim sınırını doğru yazıyor', str_contains($purch, "en fazla $maxTxt karakter"), $maxTxt);
$cta = '';
foreach (['app/dashboard/checkout/page.jsx', 'app/dashboard/following/page.jsx', 'app/dashboard/purchased/page.jsx', 'app/components/landing/HeroSection.jsx', 'app/dashboard/page.jsx'] as $rel) {
    $cta .= $codeOnly($web($rel));
}
check('N-45: Keşfet düğmeleri yalnızca "Keşfet" (Pazaryerine Git / Pazaryerini Keşfet vb. yok)', $cta !== ''
    && !preg_match('/Pazaryerine Git|Pazaryerini Keşfet|Pazaryerinde Keşfet|Pazaryerine Göz At|Hemen Keşfet|Keşfet\'te bot bul/u', $cta));
check('N-45: başlıkta arama kutusunun yanında "Keşfet" düğmesi yok', !preg_match('/<span>Keşfet<\/span>/u', $hdr));
check('N-42: diyalog defterinde bot gizleme başarısızlıkta hata gösteriyor', (bool) preg_match('/addhide\.php[\s\S]{0,300}response\.ok && result\?\.success\)[\s\S]{0,500}\} else \{\s*toast\.error\(/', $notes));

echo "\n--- N-26: \"Daha Önce Satıldı\" yalnızca gerçek satış kaydıyla ---\n";
// Eskiden getPublished `1 AS durum` döndürüyordu; arayüz her bota rozet basıyordu.
$rows = $repo->getPublished(['limit' => 200, 'offset' => 0]);
$mismatch = [];
$withSales = 0;
foreach ($rows as $row) {
    if (!array_key_exists('has_sales', $row)) { $mismatch[] = "#{$row['id']} has_sales yok"; continue; }
    $real = (int) $db->selectSingle(
        '((SELECT COUNT(*) FROM user_subscriptions WHERE chatbot_id = ?) + (SELECT COUNT(*) FROM chatbot_purchase_credits WHERE chatbot_id = ?)) AS n',
        [$row['id'], $row['id']]
    )['n'] > 0;
    if ((bool) (int) $row['has_sales'] !== $real) $mismatch[] = "#{$row['id']} has_sales={$row['has_sales']} gerçek=" . (int) $real;
    $withSales += $real ? 1 : 0;
}
check('vitrin satırlarında has_sales gerçek satış kaydıyla aynı (' . count($rows) . " bot, $withSales satışlı)", $mismatch === [], implode('; ', array_slice($mismatch, 0, 5)));

// Olumlu durum: satış kaydı eklenince (rollback) rozet bayrağı açılır.
$probe = null;
foreach ($rows as $row) { if (!(int) ($row['has_sales'] ?? 0)) { $probe = (int) $row['id']; break; } }
if ($probe) {
    $conn->beginTransaction();
    try {
        $db->execute(
            'INSERT INTO user_subscriptions (user_id, chatbot_id, duration_weeks, expiry_date, status) VALUES (?, ?, 1, NOW() - INTERVAL 1 DAY, 0)',
            [$U, $probe]
        );
        $after = null;
        foreach ($repo->getPublished(['limit' => 200, 'offset' => 0]) as $row) { if ((int) $row['id'] === $probe) $after = $row; }
        check("satış kaydı eklenen #$probe: has_sales 1 (süresi dolmuş abonelik de satıştır)", (int) ($after['has_sales'] ?? 0) === 1);
    } finally {
        $conn->rollBack();
    }
}

echo "\n=== SONUÇ: $pass geçti, $fail başarısız ===\n\n";
exit($fail > 0 ? 1 : 0);
