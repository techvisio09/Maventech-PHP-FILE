<?php
// Router for PHP's built-in server (used by start.sh in the Emergent preview).
// Serves existing files/scripts directly; maps "/" to index.php and /sitemap.xml
// to the dynamic generator. Unknown URLs return a real 404 (important for SEO —
// previously they fell through to the homepage, creating duplicate content).
// Not needed on Apache/nginx hosting (use equivalent rewrite + ErrorDocument rules).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/* ===================================================================
   SECURITY: Block direct access to sensitive files & directories.
   PHP's built-in server happily serves any file under the docroot,
   so this router is our deny-list of last resort.  Apache / nginx
   deployments must add equivalent rewrite rules in .htaccess / nginx.conf.
   =================================================================== */
$deniedExact = [
    '/.env', '/.env.local', '/.env.production', '/.env.example',
    '/composer.json', '/composer.lock', '/composer.phar',
    '/package.json', '/package-lock.json', '/yarn.lock',
    '/database.sql', '/start.sh', '/router.php', '/config.php.bak',
    '/.htpasswd', '/.user.ini', '/php.ini',
];
$deniedPrefixes = [
    '/.git/', '/.github/', '/.vscode/', '/.idea/',
    '/vendor/',           // composer dependencies — no need to expose
    '/includes/',         // PHP partials — must not be hit directly
    '/lib/',              // bundled libs (PHPMailer etc.)
    '/.well-known/security.txt' === $path ? '/__never_match__/' : '/.well-known/', // keep security.txt only
];
// Static files inside `uploads/order-pdfs/` carry customer PII (receipts +
// invoices) — they must be streamed only through the gated download flow
// in order-history.php, never served raw.
if (strpos($path, '/uploads/order-pdfs/') === 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Use the Order History page to download your receipt or invoice.";
    return true;
}
if (in_array($path, $deniedExact, true)) {
    http_response_code(404); // 404 (not 403) so we don't even acknowledge the file
    return true;
}
foreach ($deniedPrefixes as $pref) {
    if (strpos($path, $pref) === 0) {
        http_response_code(404);
        return true;
    }
}
// Block dotfiles in general — anything starting with `/.` that we didn't whitelist.
if (preg_match('#/\.[^/]+#', $path)) {
    http_response_code(404);
    return true;
}

if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap-xml.php';
    return true;
}
if ($path === '/merchant-feed.xml') {
    require __DIR__ . '/merchant-feed.php';
    return true;
}
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // let the built-in server handle real files (php, css, js, images)
}
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
require __DIR__ . '/404.php';
