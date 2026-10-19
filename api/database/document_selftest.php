<?php
/**
 * Belge yükleme öz-testi (N-62) — çevrimdışı, veritabanına dokunmaz.
 *
 *   php api/database/document_selftest.php
 *
 * Madde 3: belge yüklemede yalnızca PDF vardı; TXT ve DOCX yoktu, şifreli /
 * taranmış PDF genel "ayrıştırılamadı" diyordu. Test dosyaları çalışma
 * anında üretilir (repoda ikili dosya yok).
 *
 * A) Tür tespiti: imza (magic bytes) önce, uzantı sonra; .doc ve şifreli Office ayrı.
 * B) Metin: TXT (UTF-8, BOM'lu UTF-8, UTF-16LE, windows-1254), DOCX, PDF.
 * C) Hata sınıfları ve Türkçe mesajlar: boyut, tür, .doc, şifreli, taranmış, bozuk, boş.
 * D) Uç nokta: readdocument.php var; readpdf.php geriye dönük çalışıyor.
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

$api = realpath(__DIR__ . '/..');
foreach (['/src/Shared/Utilities/VendorAutoload.php', '/src/Shared/Utilities/DocumentText.php'] as $f) {
    if (is_file($api . $f)) require_once $api . $f;
}
if (!class_exists('DocumentText')) {
    check('src/Shared/Utilities/DocumentText.php: DocumentText', false, 'tanımlı değil');
    finish();
}
VendorAutoload::load('selftest', VendorAutoload::PDF_CLASSES);

/** Basit tek sayfalık PDF (metinli ya da metinsiz). */
function makePdf(?string $text): string {
    $stream = $text === null ? '0 0 m 10 10 l S' : 'BT /F1 12 Tf 72 720 Td (' . strtr($text, ['(' => '', ')' => '']) . ') Tj ET';
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $o) { $off[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}
function makeDocx(array $paragraphs): string {
    $tmp = tempnam(sys_get_temp_dir(), 'dx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $body = '';
    foreach ($paragraphs as $p) {
        $body .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($p, ENT_XML1) . '</w:t></w:r></w:p>';
    }
    $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body . '<w:p><w:r><w:t>Sekme</w:t><w:tab/><w:t>sonrası</w:t><w:br/><w:t>alt satır</w:t></w:r></w:p></w:body></w:document>');
    $z->close();
    $b = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $b;
}
$cfb = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 200);

echo "\n=== A) Tür tespiti ===\n\n";
check('PDF imzası', DocumentText::detectType('x.pdf', makePdf('a')) === 'pdf');
check('PDF imzası, uzantı yanlış', DocumentText::detectType('belge.txt', makePdf('a')) === 'pdf');
check('DOCX (zip + word/document.xml)', DocumentText::detectType('a.docx', makeDocx(['x'])) === 'docx');
check('düz metin', DocumentText::detectType('notlar.txt', "Merhaba dünya") === 'txt');
check('eski Word (.doc, OLE imzası)', DocumentText::detectType('eski.doc', $cfb) === 'doc');
check('şifreli Office (OLE + EncryptedPackage)', DocumentText::detectType('gizli.docx', $cfb . 'E' . "\0" . 'n' . "\0" . 'c' . "\0" . 'r' . "\0" . 'y' . "\0" . 'p' . "\0" . 't' . "\0" . 'e' . "\0" . 'd' . "\0" . 'P' . "\0" . 'a' . "\0" . 'c' . "\0" . 'k' . "\0" . 'a' . "\0" . 'g' . "\0" . 'e' . "\0") === 'encrypted');
check('görsel (PNG) → desteklenmiyor', DocumentText::detectType('a.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0\x01\xFF", 50)) === 'unsupported');
check('ikili çöp .txt uzantısıyla → desteklenmiyor', DocumentText::detectType('a.txt', str_repeat("\0\x01\x02\xFF", 100)) === 'unsupported');

echo "\n=== B) Metin çıkarma ===\n\n";
$r = DocumentText::read('notlar.txt', "Satır bir\nSatır iki: Çağlayan");
check('TXT UTF-8', $r['ok'] && str_contains($r['text'], 'Çağlayan'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('bom.txt', "\xEF\xBB\xBFBOM'lu Ğ");
check('TXT BOM\'lu UTF-8 (BOM atılıyor)', $r['ok'] && $r['text'] === "BOM'lu Ğ", json_encode($r['text'] ?? null, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('u16.txt', "\xFF\xFE" . mb_convert_encoding('UTF-16 Şişli', 'UTF-16LE', 'UTF-8'));
check('TXT UTF-16LE', $r['ok'] && $r['text'] === 'UTF-16 Şişli', json_encode($r['text'] ?? null, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('eski.txt', iconv('UTF-8', 'WINDOWS-1254', 'Eski Türkçe kodlama: ğüşıöç İĞ'));
check('TXT windows-1254', $r['ok'] && str_contains($r['text'], 'ğüşıöç İĞ'), json_encode($r['text'] ?? null, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('rapor.docx', makeDocx(['Birinci paragraf.', 'İkinci paragraf: kıyas & önerme <test>']));
check('DOCX paragraflar ayrı satırda, varlıklar çözülmüş', $r['ok'] && str_contains($r['text'], "Birinci paragraf.\nİkinci paragraf: kıyas & önerme <test>"), json_encode($r['text'] ?? null, JSON_UNESCAPED_UNICODE));
check('DOCX sekme ve satır sonu', $r['ok'] && str_contains($r['text'], "Sekme\tsonrası\nalt satır"));
$r = DocumentText::read('a.pdf', makePdf('Lumanoris PDF metni: Aristoteles Stagira da dogdu.'));
check('PDF metni', $r['ok'] && str_contains($r['text'], 'Aristoteles Stagira'), json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== C) Hata sınıfları ===\n\n";
$r = DocumentText::read('buyuk.pdf', str_repeat('a', DocumentText::MAX_BYTES + 1));
check('boyut sınırı → too_large, MB söylüyor', !$r['ok'] && $r['reason'] === 'too_large' && str_contains($r['message'], '5 MB'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('foto.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 50));
check('desteklenmeyen tür → unsupported, desteklenenleri sayıyor', !$r['ok'] && $r['reason'] === 'unsupported' && str_contains($r['message'], 'PDF') && str_contains($r['message'], 'DOCX') && str_contains($r['message'], 'TXT'), $r['message'] ?? '');
$r = DocumentText::read('eski.doc', $cfb);
check('.doc → doc_legacy (.docx olarak kaydet)', !$r['ok'] && $r['reason'] === 'doc_legacy' && str_contains($r['message'], '.docx'), $r['message'] ?? '');
check('PDF kütüphanesi "Secured pdf" → encrypted', DocumentText::classifyPdfError(new Exception('Secured pdf file are currently not supported.')) === 'encrypted');
check('PDF "Possible secured file" → encrypted', DocumentText::classifyPdfError(new Exception('Object list not found. Possible secured file.')) === 'encrypted');
check('PDF "Unable to find xref" → corrupt', DocumentText::classifyPdfError(new Exception('Unable to find xref (PDF corrupted?)')) === 'corrupt');
$r = DocumentText::read('tarama.pdf', makePdf(null));
check('metinsiz PDF (taranmış) → scanned', !$r['ok'] && $r['reason'] === 'scanned' && str_contains($r['message'], 'taranmış'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('bozuk.pdf', "%PDF-1.4\nçöp veri");
check('bozuk PDF → corrupt', !$r['ok'] && $r['reason'] === 'corrupt', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = DocumentText::read('bos.txt', "   \n  ");
check('boş TXT → empty', !$r['ok'] && $r['reason'] === 'empty');
$r = DocumentText::read('bos.docx', makeDocx([]));
check('içi boş DOCX (yalnız boşluk) değil — sekme satırı var, okunur', $r['ok']);
foreach (['too_large', 'unsupported', 'doc_legacy', 'encrypted', 'scanned', 'corrupt', 'empty'] as $reason) {
    $m = DocumentText::message($reason);
    check("mesaj ($reason) Türkçe, genel 'sunucu hatası' değil", $m !== '' && !preg_match('/sunucu hatas/iu', $m), $m);
}

echo "\n=== D) Uç nokta ===\n\n";
check('api/training/readdocument.php var', is_file($api . '/api/training/readdocument.php'));
$ctl = (string) file_get_contents($api . '/src/Presentation/Controllers/TrainingController.php');
check('readDocument DocumentText kullanıyor ve reason döndürüyor', str_contains($ctl, 'function readDocument') && str_contains($ctl, 'DocumentText::read(') && str_contains($ctl, "'reason'"));
check('readPdf geriye dönük: readDocument ile aynı işleyici', (bool) preg_match('/function readPdf\(\)[^{]*\{\s*self::handleDocumentUpload\(/', $ctl)
    && (bool) preg_match('/function readDocument\(\)[^{]*\{\s*self::handleDocumentUpload\(/', $ctl));

finish();
