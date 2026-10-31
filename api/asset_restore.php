<?php

require_once __DIR__ . '/functions/uploads.php';

$rel  = (string) ($_GET['p'] ?? '');
$path = uploadsNormalizeAsset($rel) !== null ? uploadsRestore($rel) : null;

if ($path === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Bulunamadı';
    exit;
}

header('Content-Type: ' . uploadsMime($path));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($path);
