<?php
/**
 * Eski bilgi bankasını (chatbotlar.training_prompt) RAG kaynaklarına taşır. YALNIZCA CLI.
 *
 *   php api/database/import_legacy_kb.php           # kuru çalışma: yalnızca rapor
 *   php api/database/import_legacy_kb.php --apply   # kb_sources'a ekler (beklemede)
 *
 * Yalnızca INSERT; training_prompt'a dokunmaz (geri alma ve geçiş yedeği).
 * Taşınmış kaynağı olan bot atlanır — tekrar çalıştırmak güvenli.
 * Eklenen kaynakları cron/kb_worker.php işler (OPENAI_API_KEY gerekir).
 * Geri alma: DELETE FROM kb_sources WHERE name LIKE 'Önceki bilgi bankası%'
 * (parçalar FK CASCADE ile silinir; sohbet training_prompt yoluna döner).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../functions/kb.php';

$apply = in_array('--apply', $argv, true);
$r = kbImportLegacy(Database::getInstance(), $apply);
foreach ($r['detail'] as $line) {
    echo $line . "\n";
}
printf("%s: %d bot, %d kaynak, %d atlandı\n", $apply ? 'UYGULANDI' : 'KURU ÇALIŞMA', $r['bots'], $r['sources'], $r['skipped']);
