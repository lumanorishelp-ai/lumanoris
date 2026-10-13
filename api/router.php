<?php
// Router for PHP's built-in dev server, replicating admin/.htaccess:
// serve real files/dirs as-is, otherwise route /admin/* to admin/index.php.
//
// SEC-001: the built-in server has no .htaccess, so the "serve real files
// as-is" branch below used to hand out api/admin/.env (Gemini API key),
// api/admin/db_backup/*.sql (full DB dump: every e-mail + bcrypt hash) and
// api/admin/error_log to anyone who asked, with no authentication at all.
// The Apache side is covered by admin/.htaccess + api/.htaccess; this
// denylist is the equivalent for `php -S`. Keep the two in sync.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Normalise before matching. urldecode() above turns %2e%2e back into "..",
// so a request for /admin/%2e%2e/.env would otherwise walk out of the
// denylist's reach; collapse the traversal first and reject what is left.
$uri = "/" . ltrim(str_replace(chr(92), "/", $uri), "/");
if (strpos($uri, '..') !== false) {
    http_response_code(404);
    exit;
}

$denied = [
    // secrets and generated artefacts, wherever they appear in the tree
    '/\.env(\..*)?$/i',
    '/\.(sql|sql\.gz|zip|tar|tar\.gz|bak|swp|key|pem|p12|pfx)$/i',
    '/(^|\/)error_log$/i',
    '/(^|\/)db_backup(\/|$)/i',
    '/(^|\/)composer\.(json|lock)$/i',
    '/(^|\/)\.git(\/|$)/i',
    '/(^|\/)\.ht[a-z]*$/i',
    // never expose the source tree or vendor code as static files
    '/(^|\/)(src|vendor|migrations|database|functions|cron)(\/|$)/i',
];
foreach ($denied as $pattern) {
    if (preg_match($pattern, $uri)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not Found";
        return true;
    }
}

$file = __DIR__ . $uri;

if ($uri !== '/' && (is_file($file) || is_dir($file))) {
    return false; // let the built-in server handle it directly
}

if (strpos($uri, '/admin') === 0) {
    $_SERVER['REQUEST_URI'] = $uri;
    require __DIR__ . '/admin/index.php';
    return true;
}

return false;
