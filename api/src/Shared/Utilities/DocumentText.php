<?php
/**
 * Bilgi bankası belgesinden metin — N-62 (Madde 3).
 *
 * Desteklenen: PDF (smalot/pdfparser, vendor), DOCX (ZipArchive +
 * word/document.xml), TXT (UTF-8, BOM'lu, UTF-16, windows-1254).
 * Tür, uzantıdan önce dosya imzasından (magic bytes) belirlenir.
 *
 * Her başarısızlık bir `reason` + Türkçe mesaj: boyut, desteklenmeyen tür,
 * eski Word (.doc), şifreli, taranmış (metinsiz) PDF, bozuk dosya, boş içerik.
 * Eskiden yalnızca PDF vardı ve şifreli/taranmış PDF genel "ayrıştırılamadı"
 * diyordu.
 */
final class DocumentText
{
    /** Arayüzde KB_MAX_PDF_MB ile ELLE senkron (base64 ile ~6,7 MB gövde < post_max_size 8M). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Taranmış PDF eşiği: sayfa başına bundan az görünür karakter. */
    private const SCANNED_CHARS_PER_PAGE = 20;

    private const MESSAGES = [
        'too_large'   => 'Dosya çok büyük: %s MB (en fazla 5 MB). Daha küçük bir dosya yükleyin ya da metni "Metin ekle" ile ekleyin.',
        'unsupported' => 'Bu dosya türü desteklenmiyor%s. PDF, DOCX veya TXT yükleyin.',
        'doc_legacy'  => 'Eski Word biçimi (.doc) desteklenmiyor. Dosyayı Word\'de "Farklı Kaydet → .docx" ile kaydedip yükleyin.',
        'encrypted'   => 'Dosya şifreli ya da parola korumalı; okunamadı. Korumayı kaldırıp yeniden yükleyin.',
        'scanned'     => 'PDF yalnızca taranmış görüntü içeriyor, okunabilir metin yok. Metni kopyalayıp "Metin ekle" ile ekleyin ya da metin içeren bir PDF yükleyin.',
        'corrupt'     => 'Dosya bozuk ya da okunamadı. Dosyayı yeniden kaydedip deneyin.',
        'empty'       => 'Dosyada okunabilir metin bulunamadı.',
    ];

    public static function message(string $reason, string ...$args): string
    {
        $tpl = self::MESSAGES[$reason] ?? 'Dosya okunamadı.';
        if (str_contains($tpl, '%s')) {
            $args = $args === [] ? [''] : $args;
            return vsprintf($tpl, $args);
        }
        return $tpl;
    }

    /** @return 'pdf'|'docx'|'txt'|'doc'|'encrypted'|'unsupported' */
    public static function detectType(string $filename, string $bytes): string
    {
        if (str_starts_with($bytes, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            // OLE/CFB: eski .doc ya da şifreli OOXML (EncryptedPackage akışı, UTF-16 ad).
            $utf16 = implode("\0", str_split('EncryptedPackage')) . "\0";
            return str_contains($bytes, $utf16) ? 'encrypted' : 'doc';
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return str_contains($bytes, 'word/document.xml') ? 'docx' : 'unsupported';
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'text', 'md', 'csv', ''], true) && self::looksLikeText($bytes)) {
            return 'txt';
        }
        return 'unsupported';
    }

    private static function looksLikeText(string $bytes): bool
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            return true; // UTF-16 BOM
        }
        $sample = substr($bytes, 0, 8192);
        if ($sample === '') {
            return true;
        }
        // NUL ve kontrol karakteri oranı (sekme/satır sonu hariç)
        $ctrl = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);
        return $ctrl / strlen($sample) < 0.02;
    }

    /**
     * @return array{ok: true, text: string, type: string}|array{ok: false, reason: string, message: string, type: string}
     */
    public static function read(string $filename, string $bytes): array
    {
        $fail = static fn (string $reason, string $type, string ...$a): array
            => ['ok' => false, 'reason' => $reason, 'message' => self::message($reason, ...$a), 'type' => $type];

        if (strlen($bytes) > self::MAX_BYTES) {
            // Yukarı yuvarla: 5 MB + birkaç bayt "5,0 MB (en fazla 5 MB)" görünmesin.
            return $fail('too_large', '', number_format(ceil(strlen($bytes) / 1048576 * 10) / 10, 1, ',', ''));
        }
        $type = self::detectType($filename, $bytes);
        switch ($type) {
            case 'doc':
                return $fail('doc_legacy', $type);
            case 'encrypted':
                return $fail('encrypted', $type);
            case 'unsupported':
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                return $fail('unsupported', $type, $ext !== '' ? " (.$ext)" : '');
            case 'txt':
                $text = self::decodeText($bytes);
                break;
            case 'docx':
                $text = self::docxText($bytes);
                if ($text === null) {
                    return $fail('corrupt', $type);
                }
                break;
            default: // pdf
                $res = self::pdfText($bytes);
                if (isset($res['reason'])) {
                    return $fail($res['reason'], $type);
                }
                $text = $res['text'];
        }
        $text = trim(preg_replace("/[ \t\x{00A0}]+\n/u", "\n", str_replace(["\r\n", "\r"], "\n", $text)) ?? $text);
        if ($text === '') {
            return $fail('empty', $type);
        }
        return ['ok' => true, 'text' => $text, 'type' => $type];
    }

    private static function decodeText(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        $conv = @iconv('WINDOWS-1254', 'UTF-8//IGNORE', $bytes);
        return $conv !== false ? $conv : mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    }

    private static function docxText(string $bytes): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return null;
        }
        $xml = null;
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        }
        @unlink($tmp);
        if (!is_string($xml)) {
            return null;
        }
        $xml = preg_replace(['#<w:tab\s*/>#', '#<w:(br|cr)\b[^>]*/>#', '#</w:p>#'], ["\t", "\n", "\n"], $xml) ?? $xml;
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return array{text: string}|array{reason: string} */
    private static function pdfText(string $bytes): array
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            // Çağıran (TrainingController) VendorAutoload::requireOrFail ile önceden 503 döner.
            return ['reason' => 'corrupt'];
        }
        try {
            $doc   = (new \Smalot\PdfParser\Parser())->parseContent($bytes);
            $text  = (string) $doc->getText();
            $pages = max(1, count($doc->getPages()));
        } catch (\Throwable $e) {
            error_log('[readdocument] pdf ' . get_class($e) . ': ' . $e->getMessage());
            return ['reason' => self::classifyPdfError($e)];
        }
        $visible = preg_replace('/\s+/u', '', $text) ?? '';
        if (mb_strlen($visible) < self::SCANNED_CHARS_PER_PAGE * min($pages, 3) && mb_strlen($visible) < 50) {
            return ['reason' => 'scanned'];
        }
        return ['text' => $text];
    }

    /** smalot/pdfparser istisnası → reason. */
    public static function classifyPdfError(\Throwable $e): string
    {
        return preg_match('/secured|encrypt|password/i', $e->getMessage()) ? 'encrypted' : 'corrupt';
    }
}
