<?php

require_once __DIR__ . '/env.php';

const UPLOAD_ASSET_PATTERN = '#^(kapak_fotografi|profil_fotografi)/([0-9]+_[0-9a-f]+\.(png|jpe?g|webp|gif))$#';

const UPLOAD_ASSET_MIMES = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];

function uploadsAssetsDir(): string
{
    return dirname(__DIR__) . '/assets';
}

function uploadsBackupDir(): string
{
    env_load();
    $dir = trim((string) env_get('UPLOADS_BACKUP_DIR', ''));
    return rtrim($dir !== '' ? $dir : dirname(__DIR__, 2) . '/storage/uploads', '/');
}

function uploadsNormalizeAsset(string $relative): ?string
{
    $relative = preg_replace('#^/?assets/#', '', trim($relative)) ?? '';
    return preg_match(UPLOAD_ASSET_PATTERN, $relative) ? $relative : null;
}

function uploadsBackupCopy(string $relative): bool
{
    $rel = uploadsNormalizeAsset($relative);
    if ($rel === null) return false;
    $src = uploadsAssetsDir() . '/' . $rel;
    $dst = uploadsBackupDir() . '/' . $rel;
    if (!is_file($src)) return false;
    $dir = dirname($dst);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('[uploads] yedek klasörü oluşturulamadı: ' . $dir);
        return false;
    }
    if (!@copy($src, $dst)) {
        error_log('[uploads] yedek kopyası yazılamadı: ' . $rel);
        return false;
    }
    return true;
}

function uploadsRestore(string $relative): ?string
{
    $rel = uploadsNormalizeAsset($relative);
    if ($rel === null) return null;
    $target = uploadsAssetsDir() . '/' . $rel;
    if (is_file($target)) return $target;
    $backup = uploadsBackupDir() . '/' . $rel;
    if (!is_file($backup)) return null;
    $dir = dirname($target);
    if ((is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) && @copy($backup, $target)) {
        error_log('[uploads] görsel yedekten geri yüklendi: ' . $rel);
        return $target;
    }
    return $backup;
}

function uploadsMime(string $relative): string
{
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    return UPLOAD_ASSET_MIMES[$ext] ?? 'application/octet-stream';
}
