<?php
/**
 * Admin panelindeki dosya yüklemeleri için ortak sertleştirme.
 *
 * AUDIT G-06 / H-06 — panelde ÜÇ ayrı yükleme yolu vardı
 * (`ajax/upload.php`, `ajax/updategv.php`, `ajax/seo.php`) ve her biri
 * kendi MIME listesini, kendi boyut tavanını ve kendi tip tespitini
 * taşıyordu. Kopyalar zaten ayrışmıştı, ve en zayıfı (`seo.php`) iki ayrı
 * açık barındırıyordu:
 *
 *   (a) Uzantı İSTEMCİNİN DOSYA ADINDAN alınıyordu
 *       (`pathinfo($file['name'], PATHINFO_EXTENSION)`), yani doğrulanan
 *       tip ile diske yazılan uzantı birbirinden bağımsızdı: geçerli bir
 *       PNG "og_image.php" olarak kaydedilebiliyordu. Dosya web'den
 *       servis edilen bir dizine yazıldığı için, PHP'ye ek uzantı veren
 *       her Apache/nginx yapılandırmasında bu doğrudan kod çalıştırma
 *       demekti.
 *   (b) `mime_content_type()` `function_exists` koruması olmadan
 *       çağrılıyordu; ext-fileinfo yoksa (denetlenen ortamda YOK) bu
 *       yakalanamayan bir fatal error, yani boş gövdeli 500 üretiyordu.
 *
 * Buradaki tek uygulama iki sorunu da kapatıyor: tip dosyanın KENDİ
 * baytlarından okunuyor ve uzantı o doğrulanmış tipten türetiliyor.
 * Sınırlar `AppConfig`ten geliyor.
 */

require_once __DIR__ . '/../../src/Shared/Constants/AppConfig.php';

/**
 * Dosyanın gerçek MIME tipini KENDİ baytlarından okur; adına asla bakmaz.
 *
 * ext-fileinfo'nun varlığı garanti değil ve o yokken `finfo_open()` /
 * `mime_content_type()` çağırmak fatal error — bu yüzden her adım
 * korumalı ve eklenti gerektirmeyen bir geri düşüş var.
 */
function upload_detect_mime(string $tmpPath): string|false {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if ($mime !== false) return $mime;
        }
    }

    $info = @getimagesize($tmpPath);
    if ($info !== false && !empty($info['mime'])) return $info['mime'];

    $head = (string) @file_get_contents($tmpPath, false, null, 0, 5);
    if ($head === '%PDF-') return 'application/pdf';

    // .ico dosyalarının imzası: 00 00 01 00
    $icoHead = (string) @file_get_contents($tmpPath, false, null, 0, 4);
    if ($icoHead === "\x00\x00\x01\x00") return 'image/x-icon';

    return false;
}

/**
 * Legacy CRUD uçlarının (`ajax/create.php`, `ajax/update.php`) dosya
 * alanlarını diske yazar ve `$data`ya GÖRELİ YOLU koyar.
 *
 * G-14 — bu iki uç eskiden dosyayı base64'e çevirip DOĞRUDAN veritabanı
 * sütununa yazıyordu. Üç sorun:
 *   1. Uygulamanın geri kalanı bu sütunlarda `assets/...` YOLU bekliyor
 *      (`ChatbotController::handleImageUploads`), base64 değil — yani
 *      panelden yüklenen görsel sitede kırık çıkıyordu (F-01/A-03 ile aynı
 *      kök).
 *   2. Hiçbir MIME/boyut doğrulaması yoktu: her tür içerik, her boyutta.
 *   3. `mime_content_type()` `function_exists` koruması olmadan
 *      çağrılıyordu (ext-fileinfo yoksa fatal error).
 *
 * Artık `handleImageUploads()` ile aynı sözleşme: doğrulanmış MIME,
 * sunucunun belirlediği ad, `assets/<sütun>/<ad>` göreli yolu.
 *
 * @param array $files `$_FILES`
 * @param array $data  yazılacak sütunlar
 * @return array güncellenmiş $data
 * @throws RuntimeException doğrulama başarısızsa
 */
function admin_store_uploads(array $files, array $data): array {
    foreach ($files as $key => $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        // "kapak_fotografi_file" -> "kapak_fotografi"
        $column = preg_replace('/_file$/', '', (string) $key);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column)) {
            throw new RuntimeException('Geçersiz dosya alanı: ' . $key);
        }

        $error    = null;
        $verified = upload_verify($file, AppConfig::IMAGE_MIME_EXTENSIONS, AppConfig::MAX_UPLOAD_SIZE_BYTES, $error);
        if ($verified === null) {
            throw new RuntimeException($error ?? 'Dosya doğrulanamadı.');
        }
        [, $ext] = $verified;

        $uploadDir = __DIR__ . '/../../assets/' . $column;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Yükleme klasörü oluşturulamadı.');
        }

        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
            throw new RuntimeException('Dosya kaydedilemedi.');
        }

        $data[$column] = 'assets/' . $column . '/' . $fileName;
        require_once __DIR__ . '/../../functions/uploads.php';
        uploadsBackupCopy($data[$column]);
    }

    return $data;
}

/**
 * Bir `$_FILES` girdisini doğrular ve doğrulanmış [mime, uzantı] döndürür.
 *
 * @param array               $file        `$_FILES[...]` girdisi
 * @param array<string,string> $mimeToExt  izin verilen MIME → uzantı
 * @param int                 $maxBytes    boyut tavanı
 * @return array{0:string,1:string}|null   [mime, ext] ya da hata durumunda null
 */
function upload_verify(array $file, array $mimeToExt, int $maxBytes, ?string &$error = null): ?array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Dosya yüklenemedi.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        $error = 'Dosya doğrulaması başarısız oldu.';
        return null;
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        $error = 'Dosya çok büyük! Maksimum ' . (int) round($maxBytes / 1024) . ' KB olmalıdır.';
        return null;
    }

    $mime = upload_detect_mime($file['tmp_name']);
    if ($mime === false || !isset($mimeToExt[$mime])) {
        $error = 'Geçersiz dosya türü! Sadece izin verilenler yüklenebilir.';
        return null;
    }

    return [$mime, $mimeToExt[$mime]];
}
