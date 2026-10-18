<?php
/**
 * Sır taraması (N-55) — `.env` örneklerinde ve deploy paketinde gerçek
 * anahtar/değer kalmadığını denetler. Bulgular değeri ASLA tekrar etmez;
 * yalnızca satır numarası, değişken adı ve neden döner (çıktı loga/terminale
 * düşüyor, taramanın kendisi sızıntı olmamalı).
 *
 * Kullananlar: database/secret_scan_selftest.php (verify.sh),
 * scripts/build-backend-zip.sh (deploy paketi).
 */

/** Biçiminden tanınan anahtarlar: adı ne olursa olsun, yorumda bile olsa yakalanır. */
const SECRET_SCAN_PATTERNS = [
    'Google API anahtarı (AIza…)'        => '/AIza[0-9A-Za-z_\-]{30,}/',
    'Google anahtarı (AQ.…)'             => '/\bAQ\.[0-9A-Za-z_\-]{30,}/',
    'Google OAuth istemci sırrı (GOCSPX)' => '/GOCSPX-[0-9A-Za-z_\-]{20,}/',
    'özel anahtar bloğu'                 => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    'Stripe/benzeri canlı anahtar'       => '/\b[sr]k_live_[0-9A-Za-z]{16,}/',
    'GitHub token'                       => '/\bgh[pousr]_[0-9A-Za-z]{30,}/',
    'Slack token'                        => '/\bxox[abposr]-[0-9A-Za-z\-]{10,}/',
    'AWS erişim anahtarı'                => '/\bAKIA[0-9A-Z]{16}\b/',
];

/** Adı sır taşıdığını söyleyen değişkenler: değer boş ya da yer tutucu olmalı. */
const SECRET_SCAN_NAME = '/(KEY|SECRET|PASS|PASSWORD|TOKEN|GEMINI|MERCHANT|GUID|CLIENT_CODE|CLIENT_USERNAME)$/i';

/** Yer tutucu sayılan değerler (örnek dosyada bilerek yazılmış). */
const SECRET_SCAN_PLACEHOLDER = '/^(your[-_]|buraya|example|changeme|xxx|<|\$\{|\.\.\.)/i';

/**
 * Düz metinde biçiminden tanınan anahtarları arar.
 * @return string[] "satır N: neden" listesi
 */
function secretScanText(string $content): array {
    $hits = [];
    foreach (preg_split('/\R/', $content) as $i => $line) {
        foreach (SECRET_SCAN_PATTERNS as $label => $re) {
            if (preg_match($re, $line)) {
                $hits[] = 'satır ' . ($i + 1) . ": $label";
            }
        }
    }
    return $hits;
}

/**
 * `.env` örnek dosyasını tarar: biçim desenleri + sır adlı değişkenlerde
 * gerçek görünen değer.
 * @return string[] "satır N: AD — neden" listesi (değer yok)
 */
function secretScanEnvExample(string $content): array {
    $hits = secretScanText($content);
    foreach (preg_split('/\R/', $content) as $i => $line) {
        if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            continue; // yorum ya da boş satır (yorumdaki anahtarı secretScanText yakaladı)
        }
        [$name, $value] = [$m[1], trim($m[2])];
        $value = trim($value, "\"'");
        if ($value === '' || !preg_match(SECRET_SCAN_NAME, $name)) {
            continue;
        }
        if (preg_match(SECRET_SCAN_PLACEHOLDER, $value)) {
            continue;
        }
        $hits[] = 'satır ' . ($i + 1) . ": $name — sır adlı değişkende yer tutucu olmayan değer";
    }
    return $hits;
}
