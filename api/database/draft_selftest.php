<?php
/**
 * Taslak bot öz-testi (N-68, Madde 4 / Faz C) — yerel veritabanı,
 * TRANSACTION + ROLLBACK (hiçbir satır kalıcı yazılmaz). Migration 019 gerekir.
 *
 *   php api/database/draft_selftest.php
 *
 * Kural: bot sihirbazın 1. adımında TASLAK kaydedilir (is_independent = 1 AND
 * is_draft = 1). Taslak:
 *   A) hiçbir herkese açık yerde görünmez (vitrin, sohbet erişimi başkasına kapalı),
 *   B) bot limitlerine SAYILMAZ (özel/herkese açık hak yemez); kullanıcı başına
 *      en fazla CHATBOT_DRAFT_LIMIT taslak,
 *   C) "Yayınla" anında seçilen türün limiti uygulanır, is_draft = 0 olur,
 *   D) Chatbotlarım ve düzenleme ekranı taslağı tanır.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/plans.php';
require_once __DIR__ . '/../functions/chatbot_limits.php';

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

if (!function_exists('chatbotHasDraftColumn') || !defined('CHATBOT_DRAFT_LIMIT') || !function_exists('countDraftBots')) {
    check('functions/chatbot_limits.php: chatbotHasDraftColumn, CHATBOT_DRAFT_LIMIT, countDraftBots', false, 'tanımlı değil');
    finish();
}
$db   = Database::getInstance();
$conn = $db->getConnection();
check('migration 019 uygulanmış (chatbotlar.is_draft)', chatbotHasDraftColumn($db));
if (!chatbotHasDraftColumn($db)) {
    finish();
}

$users = $db->selectMulti('id FROM kullanicilar ORDER BY id DESC LIMIT 2');
if (count($users) < 2) {
    check('en az iki kullanıcı', false);
    finish();
}
[$owner, $other] = [(int) $users[0]['id'], (int) $users[1]['id']];
$repo = new ChatbotRepository();
$mk = fn (int $indep, int $draft, string $name) => $repo->create([
    'isim' => $name, 'aciklama' => 'draft_selftest', 'style_prompt' => 'test', 'sohbet_basi_mesaj' => '',
    'author_user_id' => $owner, 'owner_user_id' => $owner, 'is_independent' => $indep, 'is_draft' => $draft,
]);

$conn->beginTransaction();
try {
    $before = $repo->countByOwner($owner);
    $d1 = $mk(1, 1, 'Taslak bir');

    echo "\n=== A) Görünürlük ===\n\n";
    $pub = array_column($repo->getPublished([], $other), 'id');
    check('taslak vitrinde yok (getPublished)', !in_array($d1, array_map('intval', $pub), true));
    check('başka kullanıcı taslakla sohbet edemez', !$repo->userHasAccess($d1, $other, 'chat'));
    check('sahibi taslakla sohbet edebilir (önizleme)', $repo->userHasAccess($d1, $owner, 'chat'));

    echo "\n=== B) Limitler ===\n\n";
    $after = $repo->countByOwner($owner);
    check('taslak özel bot sayısına girmiyor', $after['independent'] === $before['independent'], json_encode([$before, $after]));
    check('taslak herkese açık bot sayısına girmiyor', $after['public'] === $before['public']);
    check('countByOwner taslak sayısını ayrıca veriyor', ($after['draft'] ?? -1) === ($before['draft'] ?? 0) + 1, json_encode($after));
    check('countUserChatbots(özel) taslağı saymıyor', countUserChatbots($db, $owner, 1) === $before['independent']);
    check('countDraftBots', countDraftBots($db, $owner) === ($before['draft'] ?? 0) + 1);
    check('taslak sınırı 3', CHATBOT_DRAFT_LIMIT === 3);

    echo "\n=== C) Yayınla / özel kaydet ===\n\n";
    $repo->publish($d1);
    $row = $db->selectSingle('is_independent, is_draft FROM chatbotlar WHERE id = ?', [$d1]);
    check('publish: herkese açık + taslak değil', (int) $row['is_independent'] === 0 && (int) $row['is_draft'] === 0, json_encode($row));
    $d2 = $mk(1, 1, 'Taslak iki');
    check('finalizePrivate: özel + taslak değil', $repo->finalizeDraftPrivate($d2)
        && (int) $db->selectSingle('is_draft FROM chatbotlar WHERE id = ?', [$d2])['is_draft'] === 0
        && (int) $db->selectSingle('is_independent FROM chatbotlar WHERE id = ?', [$d2])['is_independent'] === 1);
    check('özel kaydedilen bot artık özel bot sayısında', $repo->countByOwner($owner)['independent'] === $before['independent'] + 1);

    echo "\n=== D) Chatbotlarım ve düzenleme ===\n\n";
    $d3 = $mk(1, 1, 'Taslak üç');
    $menu = array_values(array_filter($repo->getMenuItems($owner), fn ($r) => (int) $r['id'] === $d3));
    check('Chatbotlarım taslağı is_draft = 1 ile listeliyor', count($menu) === 1 && (int) ($menu[0]['is_draft'] ?? 0) === 1, json_encode($menu));
    $det = $repo->getDetail($d3, $owner);
    check('düzenleme ekranı (getDetail) is_draft döndürüyor', $det !== null && (int) ($det['is_draft'] ?? 0) === 1);
    check('başkası taslağın ayrıntısını göremiyor (getDetail null)', $repo->getDetail($d3, $other) === null);

    echo "\n=== E) Uçlar ===\n\n";
    $ctl = (string) file_get_contents(__DIR__ . '/../src/Presentation/Controllers/ChatbotController.php');
    check('saveChatbot taslak oluşturmayı biliyor (is_draft + CHATBOT_DRAFT_LIMIT)', str_contains($ctl, 'CHATBOT_DRAFT_LIMIT') && str_contains($ctl, "'is_draft'"));
    check('publishChatbot taslağı temizliyor (repo->publish)', (bool) preg_match('/function publishChatbot[\s\S]*is_draft/', $ctl));
    check('finalizeDraft ucu var', str_contains($ctl, 'function finalizeDraft') && is_file(__DIR__ . '/../api/chatbot/finalizedraft.php'));
    check('updateChatbot is_draft yazdırmıyor (beyaz liste)', !preg_match("/UPDATABLE_CHATBOT_COLUMNS\s*=\s*\[[^\]]*'is_draft'/", $ctl));

    // N-70 (karar 2026-10-29) — taslak her zaman is_independent = 1 saklandığı
    // için yeniden açılan taslakta "Özel olarak kaydet" seçili geliyordu.
    // Hiçbir seçenek seçili gelmez; seçim yapılmadan düğme pasif.
    $ui = (string) file_get_contents(__DIR__ . '/../../web/src/app/dashboard/chatbots/create/page.jsx');
    check('Yayınla: taslakta seçim varsayılanı yok (publishAs null)', (bool) preg_match('/const \[publishAs, setPublishAs\] = useState\(null\)/', $ui));
    check('Yayınla: seçim yapılmadan düğme pasif', (bool) preg_match('/disabled=\{[^}]*isDraft && !publishAs/', $ui));
    check('Yayınla: istemci tarafında da seçimsiz finalize yok', (bool) preg_match('/if \(!publishAs\)/', $ui));
} finally {
    $conn->rollBack();
}

finish();
