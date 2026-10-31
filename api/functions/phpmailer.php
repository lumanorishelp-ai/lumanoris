<?php
/**
 * Transactional e-posta gönderimi.
 *
 * DEP-003 🟠 — burası bir stub'dı ve **fail-open** davranıyordu: hiçbir şey
 * göndermeden `['success' => true]` dönüyordu. `$htmlBody` parametresini alıp
 * hiç kullanmıyordu bile — ne gönderiyor ne logluyordu. Şifre sıfırlama kodu
 * yalnızca `$body`'de ve DB'de SHA-256 hash'i olarak vardı, yani hesabını
 * kaybeden kullanıcı için kurtarma yolu **yoktu**; README'nin "kod
 * error_log'da görünür" tavsiyesi de yanlıştı.
 *
 * Artık gerçek bir SMTP gönderimi yapılıyor (functions/smtp_client.php,
 * bağımlılıksız). Ayarlar admin panelindeki "SMTP Ayarları" sayfasının zaten
 * yazdığı `global_vars` satırlarından okunuyor: smtp_host, smtp_email,
 * smtp_pass, smtp_name. Host ayarlanmamışsa gönderim **fail-closed**:
 * `success:false` döner, böylece çağıran taraf kullanıcıya "kod gönderildi"
 * demek yerine gerçeği söyleyebilir.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/smtp_client.php';

/**
 * @return array{success: bool, message: string}
 */
function sendEmail(
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $htmlBody,
    ?array $attachment = null
): array {
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Geçersiz alıcı adresi.'];
    }

    // Staging/yerel: MAIL_TRANSPORT=log iken hiçbir e-posta gönderilmez, yalnızca
    // loga yazılır. SMTP ayarı .env'de boşsa global_vars'a (canlı DB kopyasında
    // canlı SMTP hesabı) düşüldüğü için .env'i boşaltmak tek başına yetmiyor.
    if (strtolower(trim((string) env_get('MAIL_TRANSPORT', 'smtp'))) === 'log') {
        error_log('[mailer:log] to=' . $toEmail . ' subject=' . $subject . ' body=' . mb_substr(mailerHtmlToText($htmlBody), 0, 2000));
        return ['success' => true, 'message' => 'E-posta gönderildi.'];
    }

    try {
        $config = mailerConfig();
    } catch (Throwable $e) {
        error_log('[mailer] yapılandırma okunamadı: ' . $e->getMessage());
        return ['success' => false, 'message' => 'E-posta yapılandırması okunamadı.'];
    }

    if ($config['host'] === '') {
        // Fail-closed: sessizce "gönderildi" demek, kullanıcıyı hiç gelmeyecek
        // bir e-postayı beklemeye bırakıyordu.
        error_log('[mailer] SMTP host tanımlı değil — gönderim yapılmadı. to=' . $toEmail);
        return [
            'success' => false,
            'message' => 'E-posta gönderimi yapılandırılmamış. Yönetici panelinden SMTP ayarlarını tamamlayın.',
        ];
    }

    // Gönderen adresi SMTP hesabıyla uyuşmalı; çoğu sağlayıcı farklı bir
    // From ile mesajı reddeder ya da spam'e düşürür.
    $envelopeFrom = $config['email'] !== '' ? $config['email'] : $fromEmail;
    $displayName  = $fromName !== '' ? $fromName : ($config['name'] !== '' ? $config['name'] : 'Lumanoris');

    if ($attachment !== null) {
        // Bugün hiçbir çağıran ek göndermiyor; sessizce yutmak yerine açıkça
        // söylüyoruz ki ileride eklenirse fark edilsin.
        error_log('[mailer] ek dosya desteklenmiyor, yoksayıldı. to=' . $toEmail);
    }

    $client = new SmtpClient(
        $config['host'],
        $config['port'],
        $config['email'],
        $config['pass'],
        15,
        $config['encryption']
    );

    try {
        $client->send(
            ['email' => $envelopeFrom, 'name' => $displayName],
            [$toEmail],
            $subject,
            $htmlBody,
            mailerHtmlToText($htmlBody)
        );
    } catch (Throwable $e) {
        error_log('[mailer] gönderim başarısız: ' . $e->getMessage() . ' to=' . $toEmail);
        return ['success' => false, 'message' => 'E-posta gönderilemedi.'];
    }

    return ['success' => true, 'message' => 'E-posta gönderildi.'];
}

/**
 * @return array{host:string,port:int,email:string,pass:string,name:string,encryption:string}
 */
function mailerConfig(): array
{
    // Ortam değişkeni varsa o kazanır (container dağıtımı), yoksa admin
    // panelinin yazdığı global_vars satırları.
    env_load();

    $vars = [];
    try {
        $vars = Database::getInstance()->getGlobalVars('smtp_host', 'smtp_email', 'smtp_pass', 'smtp_name') ?: [];
    } catch (Throwable $e) {
        error_log('[mailer] global_vars okunamadı: ' . $e->getMessage());
    }

    $host = env_get('SMTP_HOST') ?? (string) ($vars['smtp_host'] ?? '');
    $host = trim($host);

    // "smtp.example.com:587" biçimi de kabul edilsin.
    $port = (int) (env_get('SMTP_PORT') ?? 0);
    if (str_contains($host, ':')) {
        [$host, $hostPort] = explode(':', $host, 2);
        if ($port === 0) {
            $port = (int) $hostPort;
        }
    }
    if ($port === 0) {
        $port = 587;
    }

    return [
        'host'       => trim($host),
        'port'       => $port,
        'email'      => trim(env_get('SMTP_USER') ?? (string) ($vars['smtp_email'] ?? '')),
        'pass'       => (string) (env_get('SMTP_PASS') ?? ($vars['smtp_pass'] ?? '')),
        'name'       => trim(env_get('SMTP_NAME') ?? (string) ($vars['smtp_name'] ?? '')),
        'encryption' => strtolower(trim(env_get('SMTP_ENCRYPTION') ?? 'auto')),
    ];
}

/** HTML gövdeden okunabilir bir düz metin alternatifi üretir. */
function mailerHtmlToText(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|tr|h[1-6])>/i', "\n\n", (string) $text);
    $text = strip_tags((string) $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
    return trim((string) $text);
}
