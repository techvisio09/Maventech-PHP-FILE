<?php
// Router for PHP's built-in server (used by start.sh in the Emergent preview).
// Serves existing files/scripts directly; maps "/" to index.php and /sitemap.xml
// to the dynamic generator. Unknown URLs return a real 404 (important for SEO —
// previously they fell through to the homepage, creating duplicate content).
// Not needed on Apache/nginx hosting (use equivalent rewrite + ErrorDocument rules).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/* ===================================================================
   SEO: 301-redirect the "www." host to the canonical bare-host version
   (or the opposite, depending on `seo_canonical_host_pref`).  Until this
   is done, an external SEO audit reports "www and non-www versions are
   not redirected to the same site" — which dilutes PageRank because
   inbound links may target either host.

   Admin choice is read from the `settings` table key
   `seo_canonical_host_pref` (values: 'naked' | 'www').  Default = 'naked'.
   When the requested Host header doesn't match the preference, we issue
   a 301 Permanent Redirect to the canonical equivalent before any other
   routing fires.
   =================================================================== */
$__hostHdr = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
if ($__hostHdr !== '' && !preg_match('/(?:^|\.)preview\.emergentagent\.com$/i', $__hostHdr)
    && !preg_match('/^(localhost|127\.0\.0\.1|0\.0\.0\.0)(:|$)/i', $__hostHdr)) {
    // Try to load the canonical-host preference without booting the full app
    // (settings table not always available on a fresh container).
    $__pref = 'naked';
    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/db.php';
        if (function_exists('setting_get')) {
            $__pref = strtolower((string)setting_get('seo_canonical_host_pref', 'naked'));
            if (!in_array($__pref, ['naked', 'www'], true)) $__pref = 'naked';
        }
    } catch (Throwable $e) { /* fall through to default */ }

    $__isWww   = str_starts_with($__hostHdr, 'www.');
    $__wantWww = ($__pref === 'www');
    if ($__isWww !== $__wantWww) {
        $__targetHost = $__wantWww ? ('www.' . preg_replace('/^www\./', '', $__hostHdr))
                                   : preg_replace('/^www\./', '', $__hostHdr);
        $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        header('Location: ' . $__scheme . '://' . $__targetHost . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        return true;
    }
}


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
if ($path === '/llms.txt') {
    require __DIR__ . '/llms-txt.php';
    return true;
}
if ($path === '/agents.json') {
    require __DIR__ . '/agents-json.php';
    return true;
}
if ($path === '/robots.txt') {
    require __DIR__ . '/robots-txt.php';
    return true;
}
if ($path === '/ai.txt') {
    require __DIR__ . '/ai-txt.php';
    return true;
}
if (preg_match('#^/hub/([a-z0-9\-]+)/?$#', $path, $m)) {
    // Topic Cluster Hub — /hub/microsoft-office → ?topic=microsoft-office
    $_GET['topic'] = $m[1];
    require __DIR__ . '/hub.php';
    return true;
}

/* ============================================================
 *  BACKLINK BOOTSTRAP — Embeddable badge widget.
 *  Partners/bloggers paste a single <script> tag on their site:
 *     <script src="https://yourdomain/embed/badge.js"
 *             data-product="microsoft-office-2024" async></script>
 *  That script injects a styled "Buy from Maventech" badge that
 *  links back to us with a UTM-tagged anchor — every install is
 *  a real, crawlable backlink. */
if ($path === '/embed/badge.js' || $path === '/embed/badge') {
    require __DIR__ . '/embed-badge.php';
    return true;
}
if ($path === '/embed' || $path === '/embed/' || $path === '/press-kit' || $path === '/press-kit.php') {
    require __DIR__ . '/press-kit.php';
    return true;
}
// Serve site assets even when accessed under /hub/... (the browser
// resolves relative URLs like `assets/css/x.css` against /hub/<slug>
// — without a trailing slash, the last segment is dropped, so requests
// land at /hub/assets/...).  Map them back to the real /assets/... file.
if (preg_match('#^/hub/(assets/.+|ajax/.+|uploads/.+)$#', $path, $m)) {
    $file = __DIR__ . '/' . $m[1];
    if (file_exists($file) && !is_dir($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $_SERVER['SCRIPT_NAME'] = '/' . $m[1];
            $_SERVER['SCRIPT_FILENAME'] = $file;
            require $file;
            return true;
        }
        $mime = ['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','json'=>'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $longCacheExts = ['css','js','png','jpg','jpeg','gif','webp','avif','svg','ico','woff','woff2','ttf','eot','mp4','webm'];
    if (in_array($ext, $longCacheExts, true)) {
        $mime = [
            'css'=>'text/css; charset=UTF-8','js'=>'application/javascript; charset=UTF-8',
            'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
            'svg'=>'image/svg+xml','webp'=>'image/webp','avif'=>'image/avif','ico'=>'image/x-icon',
            'woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','eot'=>'application/vnd.ms-fontobject',
            'mp4'=>'video/mp4','webm'=>'video/webm',
        ][$ext] ?? 'application/octet-stream';

        // ----- On-the-fly minification for CSS / JS -----
        // PageSpeed's "Minified CSS" + "Minified JavaScript" audits both fail
        // when the bytes on the wire have whitespace/comments. We strip them
        // here once and cache the minified bytes to a sibling /.min/ file so
        // subsequent hits skip the work. Bumps the asset bytes-on-wire down
        // by ~30-40% before gzip. Source files (style.css, main.js) stay
        // human-editable; nothing touches them.
        if ($ext === 'css' || $ext === 'js') {
            $minDir = dirname($file) . '/.min';
            if (!is_dir($minDir)) @mkdir($minDir, 0775, true);
            $minFile = $minDir . '/' . basename($file);
            $srcMtime = filemtime($file);
            if (!file_exists($minFile) || filemtime($minFile) < $srcMtime) {
                $src = file_get_contents($file);
                if ($ext === 'css') {
                    // Strip /* ... */ comments (non-greedy), collapse whitespace,
                    // drop spaces around : ; { } , > + ~, remove trailing ; before }.
                    $src = preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $src);
                    $src = preg_replace('/\s+/', ' ', $src);
                    $src = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $src);
                    $src = str_replace(';}', '}', $src);
                } else {
                    // Conservative JS minifier: strip /* */ + // line comments,
                    // collapse multi-space, trim leading/trailing whitespace per
                    // line. NEVER touches strings/regex (regex is too risky in
                    // a hand-rolled minifier — gzip handles the rest).
                    $lines = explode("\n", $src);
                    $out = [];
                    foreach ($lines as $line) {
                        $line = preg_replace('#/\*[\s\S]*?\*/#', '', $line);
                        // Strip "// ..." comments only when NOT inside a string.
                        $line = preg_replace('#(?<![:"\'])//[^\n]*$#', '', $line);
                        $line = trim($line);
                        if ($line !== '') $out[] = $line;
                    }
                    $src = implode("\n", $out);
                    $src = preg_replace('/[ \t]+/', ' ', $src);
                }
                @file_put_contents($minFile, $src, LOCK_EX);
                @touch($minFile, $srcMtime); // keep mtime in sync for ETag
            }
            $file = $minFile;
        }

        header('Content-Type: ' . $mime, true);
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: public, max-age=31536000, immutable', true);
        header('Access-Control-Allow-Origin: *', true);
        header('Content-Length: ' . filesize($file), true);
        // Conditional GET — return 304 when ETag matches.
        $etag = '"' . md5_file($file) . '"';
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return true;
        }
        readfile($file);
        return true;
    }
    return false; // dynamic (.php) — let the built-in server run it
}
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
require __DIR__ . '/404.php';
