<?php
// Shared helpers: session, currency, cart, products, rendering
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Security headers — sent on every page-load via functions.php.
 *
 * These reduce the chance of Google Safe Browsing flagging the domain
 * as "deceptive" by signalling clear intent to browsers + crawlers:
 *   - X-Content-Type-Options: blocks MIME sniffing (prevents arbitrary
 *     uploaded files from being executed as scripts).
 *   - X-Frame-Options: disallows iframe embedding so a phishing page
 *     cannot wrap our checkout/login in a deceptive overlay.
 *   - Referrer-Policy: trims leaked URLs to third parties.
 *   - Permissions-Policy: tells browsers we don't request camera/mic/
 *     geolocation — Safe Browsing weighs this when scoring trust.
 *   - Strict-Transport-Security: only added when the request is HTTPS
 *     so dev/local installs still work over plain HTTP.
 * `headers_sent()` guards against the rare case where output started
 * before this file was loaded (e.g. cron scripts).
 */
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Regions are a core dependency: public queries must hide products from
// regions that an admin has toggled off. Loaded here so it is available
// in scripts that call db() before including the page header.
require_once __DIR__ . '/regions.php';

function esc($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Returns the base URL path the application is installed under, always with
 * a trailing slash.  Works whether the project lives at the domain root
 * ("/") or inside a subfolder ("/admin/", "/my-shop/").
 *
 * Used by the JS layer (window.MAVEN_BASE) so all fetch() URLs to
 * /ajax/... endpoints stay correct regardless of where the app is deployed.
 *
 * Example:
 *   https://example.com/admin.php          → base_url() = "/"
 *   https://example.com/shop/admin.php     → base_url() = "/shop/"
 *   https://example.com/foo/bar/admin.php  → base_url() = "/foo/bar/"
 */
function base_url(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') $dir = '';
    return $cached = $dir . '/';
}

/**
 * Self-healing schema migration.  Idempotent — safe to call on every
 * admin page-load.  Adds any new tables / columns required by features
 * that were introduced after a fresh server install (visitor analytics,
 * live chat, chat tokens, etc.).  Failures are logged but never thrown
 * so a transient DB error doesn't take down the admin panel.
 */
function ensure_db_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // Visitor analytics
        $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            os VARCHAR(40) NOT NULL DEFAULT 'Unknown',
            browser VARCHAR(40) NOT NULL DEFAULT 'Unknown',
            device VARCHAR(20) NOT NULL DEFAULT 'Desktop',
            country VARCHAR(8) NOT NULL DEFAULT '',
            page_url VARCHAR(255) NOT NULL DEFAULT '',
            referer VARCHAR(255) NOT NULL DEFAULT '',
            visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_visited (visited_at),
            KEY idx_session (session_id),
            KEY idx_os (os),
            KEY idx_device (device)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Live chat — admin ↔ visitor messages
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            sender ENUM('customer','admin') NOT NULL DEFAULT 'customer',
            message TEXT NOT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL DEFAULT NULL,
            KEY idx_lead (lead_id),
            KEY idx_sent (sent_at),
            KEY idx_unread (sender, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // chat_leads — ensure last_seen + chat_token columns exist (added later
        // than the original schema, may be missing on older installs).
        foreach ([
            "ALTER TABLE chat_leads ADD COLUMN last_seen DATETIME NULL DEFAULT NULL",
            "ALTER TABLE chat_leads ADD COLUMN chat_token VARCHAR(40) NOT NULL DEFAULT ''",
            // customer_reviews — admin_seen_at lets the topbar star-bell badge
            // tell which low-rating submissions are still unacknowledged.
            "ALTER TABLE customer_reviews ADD COLUMN admin_seen_at DATETIME NULL DEFAULT NULL",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) { /* column already exists */ }
        }
    } catch (Throwable $e) {
        @error_log('[ensure_db_schema] ' . $e->getMessage());
    }
}

/* ---------------- Currency ---------------- */
if (isset($_GET['cur']) && isset($GLOBALS['CURRENCIES'][$_GET['cur']])) {
    $_SESSION['currency'] = $_GET['cur'];
}

/** Returns the list of currency codes whose region is currently active in admin. */
function active_currency_codes(): array {
    $map = ['US'=>'USD','UK'=>'GBP','EU'=>'EUR','CA'=>'CAD','AU'=>'AUD'];
    $out = [];
    try {
        foreach (db()->query('SELECT code, currency FROM regions WHERE active=1') as $r) {
            $cc = $map[$r['code']] ?? $r['currency'] ?? null;
            if ($cc && isset($GLOBALS['CURRENCIES'][$cc])) $out[$cc] = true;
        }
    } catch (Throwable $e) { /* DB not ready */ }
    if (empty($out)) $out['USD'] = true;
    return array_keys($out);
}

function current_currency(): array
{
    $code = $_SESSION['currency'] ?? 'USD';
    $active = active_currency_codes();
    if (!in_array($code, $active, true)) {
        // Session currency was deactivated in admin — fall back to first active.
        $code = $active[0] ?? 'USD';
        $_SESSION['currency'] = $code;
    }
    if (!isset($GLOBALS['CURRENCIES'][$code])) $code = 'USD';
    return ['code' => $code] + $GLOBALS['CURRENCIES'][$code];
}

function format_price(float $usd): string
{
    $c = current_currency();
    return $c['symbol'] . number_format($usd * $c['rate'], 2);
}

/* ---------------- Auth ---------------- */
function ensure_admin(): void
{
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([strtolower(ADMIN_EMAIL)]);
    if (!$stmt->fetch()) {
        $ins = db()->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)');
        $ins->execute([strtolower(ADMIN_EMAIL), 'Admin', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT), 'admin']);
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id, email, name, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(): array
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: login.php?next=admin.php');
        exit;
    }
    return $user;
}

/* ---------------- Cart (session) ---------------- */
function cart(): array
{
    return $_SESSION['cart'] ?? []; // [slug => qty]
}

function cart_count(): int
{
    return array_sum(cart());
}

function cart_items(): array
{
    $c = cart();
    if (!$c) return [];
    $in = implode(',', array_fill(0, count($c), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE slug IN ($in)");
    $stmt->execute(array_keys($c));
    $items = [];
    foreach ($stmt->fetchAll() as $p) {
        $p['qty'] = $c[$p['slug']];
        $items[] = $p;
    }
    return $items;
}

function cart_subtotal(): float
{
    $t = 0;
    foreach (cart_items() as $i) $t += $i['price'] * $i['qty'];
    return $t;
}

/* ---------------- Products ---------------- */
function get_product(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE slug = ? AND ' . active_regions_sql_in('region'));
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

// Parent/alias category slugs -> list of granular categories
function category_children(string $slug): array
{
    $map = [
        'office-pc'  => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc'],
        'office-mac' => ['office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'office'     => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc', 'office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'windows'    => ['windows-11', 'windows-10'],
        'apps'       => ['microsoft-project', 'microsoft-visio'],
        'antivirus'  => ['bitdefender', 'mcafee'],
        // legacy aliases
        'microsoft-office'       => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc', 'office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'microsoft-office-2024'  => ['office-2024-pc', 'office-2024-mac'],
        'microsoft-office-2021'  => ['office-2021-pc', 'office-2021-mac'],
        'microsoft-office-2019'  => ['office-2019-pc', 'office-2019-mac'],
        'office-2024-for-mac'    => ['office-2024-mac'],
        'office-2021-for-mac'    => ['office-2021-mac'],
        'office-2019-for-mac'    => ['office-2019-mac'],
        'office-for-mac'         => ['office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'office-for-macs'        => ['office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'office-for-windows'     => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc'],
        'windows-os'             => ['windows-11', 'windows-10'],
        'mcafee-antivirus'       => ['mcafee'],
        'microsoft-apps'         => ['microsoft-project', 'microsoft-visio'],
    ];
    return $map[$slug] ?? [$slug];
}

function category_title(string $slug): string
{
    $stmt = db()->prepare('SELECT name FROM categories WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) return $row['name'];
    return ucwords(str_replace('-', ' ', $slug));
}

function get_products(array $categories = [], string $platform = '', string $sort = ''): array
{
    $sql = 'SELECT * FROM products';
    $where = [active_regions_sql_in('region')];
    $params = [];
    if ($categories) {
        $where[] = 'category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        $params = array_merge($params, $categories);
    }
    if ($platform === 'Windows' || $platform === 'Mac') {
        $where[] = 'platform = ?';
        $params[] = $platform;
    }
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $orders = [
        'price_asc'  => 'price ASC',
        'price_desc' => 'price DESC',
        'rating'     => 'rating DESC, reviews DESC',
        'reviews'    => 'reviews DESC',
        'newest'     => 'is_new DESC, id ASC',
    ];
    $sql .= ' ORDER BY ' . ($orders[$sort] ?? 'id ASC');
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function slugify(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/* ---------------- App icons ---------------- */
function app_icons(): array
{
    return [
        'word'       => 'https://gosoftwarebuy.com/assets/Microsoft_Office_Word_1765865381845-Cby-XFtN.png',
        'excel'      => 'https://gosoftwarebuy.com/assets/excel_1765865381846-Ch1DG1gu.jpeg',
        'powerpoint' => 'https://gosoftwarebuy.com/assets/Microsoft_Office_PowerPoint_1765865381846-CB2GUPqO.png',
        'outlook'    => 'https://gosoftwarebuy.com/assets/Microsoft_Outlook_Icon_1765865381846-DMb4j-mZ.png',
        'access'     => 'https://gosoftwarebuy.com/assets/Microsoft_Office_Access_1765865381846-C4OFiOlK.png',
    ];
}

/* ---------------- Mega menu data ---------------- */
// Each column: heading => ['all' => [categorySlug, label], 'groups' => [yearLabel => categorySlug]]
function nav_microsoft(): array
{
    return [
        'OFFICE FOR PC' => [
            'all' => ['office-pc', 'All Office for PC'],
            'groups' => ['Office 2024' => 'office-2024-pc', 'Office 2021' => 'office-2021-pc', 'Office 2019' => 'office-2019-pc'],
        ],
        'OFFICE FOR MAC' => [
            'all' => ['office-mac', 'All Office for Mac'],
            'groups' => ['Office 2024 for Mac' => 'office-2024-mac', 'Office 2021 for Mac' => 'office-2021-mac', 'Office 2019 for Mac' => 'office-2019-mac'],
        ],
        'WINDOWS' => [
            'all' => ['windows', 'All Windows'],
            'groups' => ['Windows 11' => 'windows-11', 'Windows 10' => 'windows-10'],
        ],
        'APPS' => [
            'all' => ['apps', 'All Microsoft Apps'],
            'groups' => ['Microsoft Project' => 'microsoft-project', 'Microsoft Visio' => 'microsoft-visio'],
        ],
    ];
}

// Brand Vibe — bundles motion + gradient + font-weight + corner-radius.
// Admin selects one of these in Company Info → "Brand Vibe"; the chosen
// preset cascades across the entire storefront (navbar, admin topbar,
// auto-generated logo gradient, body buttons, card radii).  A custom
// per-field override is intentionally NOT exposed — the whole point of a
// "vibe" is one-click visual cohesion.
function brand_vibes(): array
{
    return [
        'premium' => [
            'label'    => 'Premium',
            'desc'     => 'Static · charcoal + gold · sharp corners',
            'icon'     => 'bi-gem',
            'motion'   => 'static',
            'gradient' => ['#0c0a09', '#3f3f46', '#facc15'],
            'fontw'    => 800,
            'radius'   => 6,
            'accent'   => '#facc15',
        ],
        'classic' => [
            'label'    => 'Classic',
            'desc'     => 'Bounce · navy + teal · balanced radius',
            'icon'     => 'bi-stars',
            'motion'   => 'bounce',
            'gradient' => ['#312e81', '#1e40af', '#06b6d4'],
            'fontw'    => 700,
            'radius'   => 14,
            'accent'   => '#06b6d4',
        ],
        'playful' => [
            'label'    => 'Playful',
            'desc'     => 'Bounce · sunset gradient · super-round',
            'icon'     => 'bi-emoji-smile',
            'motion'   => 'bounce',
            'gradient' => ['#f97316', '#ec4899', '#a855f7'],
            'fontw'    => 800,
            'radius'   => 22,
            'accent'   => '#f97316',
        ],
        'bold' => [
            'label'    => 'Bold',
            'desc'     => 'Spin · electric purple + cyan · heavy weight',
            'icon'     => 'bi-lightning-charge',
            'motion'   => 'spin',
            'gradient' => ['#7c3aed', '#ec4899', '#0ea5e9'],
            'fontw'    => 900,
            'radius'   => 10,
            'accent'   => '#7c3aed',
        ],
    ];
}

function current_vibe(): array
{
    $key = setting_get('company_brand_vibe', 'classic');
    $all = brand_vibes();
    return $all[$key] ?? $all['classic'];
}

// Brand logo — rounded gradient square with the FIRST LETTER of the company
// name as a white monogram.  Gradient colours follow the active Brand Vibe
// so the auto-generated mark always matches the storefront aesthetic.
// Falls back to "M" if the company name is empty.  When the admin uploads a
// custom logo via the Company Info tab, `$brandLogo` takes precedence in
// header.php / footer.php and this SVG is never rendered.
function render_logo(int $size = 40, ?string $letter = null): string
{
    if ($letter === null || $letter === '') {
        $name = function_exists('company_info') ? (company_info()['name'] ?? '') : '';
        if ($name === '' && defined('SITE_BRAND')) $name = SITE_BRAND;
        $name = preg_replace('/^[^A-Za-z0-9]+/', '', trim($name));
        $letter = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : 'M';
    } else {
        $letter = mb_strtoupper(mb_substr($letter, 0, 1));
    }
    $vibe = current_vibe();
    [$g0, $g1, $g2] = $vibe['gradient'];
    $radius = max(4, (int)round($vibe['radius'] / 14 * 13)); // scale 4-22px → SVG units
    $id = 'lgrad' . $size . '_' . md5($letter . $g0 . $g1 . $g2);
    $fontSize = (int)round($size * 0.58);
    return '<svg class="brand-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" fill="none" aria-hidden="true" role="img" data-brand-mark="1">'
        . '<defs>'
        .   '<linearGradient id="' . $id . '" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">'
        .     '<stop offset="0"   stop-color="' . esc($g0) . '"/>'
        .     '<stop offset=".45" stop-color="' . esc($g1) . '"/>'
        .     '<stop offset="1"   stop-color="' . esc($g2) . '"/>'
        .   '</linearGradient>'
        .   '<radialGradient id="' . $id . '_hl" cx=".25" cy=".15" r=".75">'
        .     '<stop offset="0" stop-color="rgba(255,255,255,.32)"/>'
        .     '<stop offset="1" stop-color="rgba(255,255,255,0)"/>'
        .   '</radialGradient>'
        . '</defs>'
        . '<rect x="1.5" y="1.5" width="45" height="45" rx="' . $radius . '" fill="url(#' . $id . ')"/>'
        . '<rect x="1.5" y="1.5" width="45" height="45" rx="' . $radius . '" fill="url(#' . $id . '_hl)"/>'
        . '<text x="24" y="24" text-anchor="middle" dominant-baseline="central" font-family="Manrope,Segoe UI,Arial,sans-serif" font-weight="800" font-size="' . $fontSize . '" fill="#fff" letter-spacing="-1">' . esc($letter) . '</text>'
        . '<circle cx="40" cy="38" r="2.4" fill="' . esc($vibe['accent']) . '" opacity=".92"/>'
        . '</svg>';
}

// Stores a contact/support form submission
function save_support_message(array $d): void
{
    $stmt = db()->prepare('INSERT INTO support_messages (name, email, phone, order_number, subject, message, source) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$d['name'], $d['email'], $d['phone'] ?? '', $d['order_number'] ?? '', $d['subject'], $d['message'], $d['source'] ?? 'contact']);
}

// Volume-pricing / support promo band (nav dropdowns + Disclaimer page)
function render_menu_promo(bool $compact = false): string
{
    $phone = company_info()['phone'] ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
    $volume = '<div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-boxes text-primary fs-5"></i><span class="fw-bold">Volume Pricing</span></div>'
            . '<small class="text-secondary d-block mb-2">Exclusive discounts on bulk licenses for teams and businesses.</small>'
            . '<a href="contact.php" class="btn btn-sm btn-primary rounded-pill px-3" data-testid="menu-request-quote">Request a Quote</a>';
    $question = '<div class="fw-bold small">Have a Question?</div>'
              . '<small class="text-secondary d-block">Call Mon–Fri 9 AM–6 PM EST</small>'
              . '<a href="tel:' . esc($phone) . '" class="fw-bold text-decoration-none">' . esc($phone) . '</a> '
              . '<small class="text-secondary">or</small> '
              . '<a href="#" onclick="toggleChat();return false;" class="fw-bold text-decoration-none text-primary">chat with a sales expert</a>';
    if ($compact) {
        return '<div class="mega-promo mt-3 pt-3" data-testid="menu-promo">' . $volume . '<div class="mt-3">' . $question . '</div></div>';
    }
    return '<div class="mega-promo mt-4 pt-3 row g-3 align-items-center" data-testid="menu-promo">'
         . '<div class="col-lg-7">' . $volume . '</div>'
         . '<div class="col-lg-5 text-lg-end">' . $question . '</div></div>';
}

/* ---------------- SEO helpers ---------------- */
// Rich descriptive alt text for product images (Google Images / Merchant Center friendly)
function product_img_alt(array $p): string
{
    $pct = (!empty($p['original_price']) && $p['original_price'] > $p['price'])
        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
    $alt = $p['name'] . ' — genuine lifetime license key for ' . ($p['platform'] ?: 'Windows') . ', instant digital delivery';
    if ($pct > 0) $alt .= ', ' . $pct . '% off';
    return $alt . ' | ' . SITE_BRAND;
}

// Exact + phrase + broad keyword variations generated per product (meta keywords)
function product_keywords(array $p): string
{
    $name = $p['name'];
    $platform = $p['platform'] ?: 'Windows';
    $base = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $name));
    $kw = [
        $name,                              // exact
        'buy ' . $name,                     // phrase
        $name . ' product key',
        $name . ' lifetime license',
        $name . ' license key',
        $name . ' instant delivery',
        $name . ' no subscription',
        $name . ' digital download',
        $base . ' for ' . $platform,        // broad
        'affordable ' . $base,
        'genuine ' . $base . ' key',
        'discount ' . $base,
        'microsoft software license key store',
    ];
    return implode(', ', array_unique($kw));
}

function site_url(): string
{
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // Behind the Kubernetes ingress the original scheme arrives via X-Forwarded-Proto
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* ---------------- Coupons: code => percent off ---------------- */
function coupons(): array
{
    return ['MAVEN20' => 20, 'BIT20' => 20, 'MATRIX20' => 20, 'ZED20' => 20, 'FIVE20' => 20, 'UCODE90' => 20, 'WELCOME10' => 10, 'SAVE15' => 15, 'OFFICE25' => 25];
}

/* ---------------- Rendering helpers ---------------- */
// Payment method icon images (footer + checkout)
function render_payment_icons(string $class = 'pay-icon'): string
{
    $pays = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover', 'paypal' => 'PayPal'];
    $h = '';
    foreach ($pays as $f => $alt) {
        $h .= '<img src="assets/images/payments/' . $f . '.svg" alt="' . $alt . '" title="' . $alt . '" class="' . $class . '" loading="lazy">';
    }
    return $h;
}

function render_stars(float $rating): string
{
    $h = '<span class="text-warning">';
    for ($i = 1; $i <= 5; $i++) {
        $h .= $i <= round($rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
    }
    return $h . '</span>';
}

// Wide horizontal product banner row — shared by shop list view and category pages
function render_product_row(array $p): string
{
    $pct = ($p['original_price'] && $p['original_price'] > $p['price'])
        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
    $orig = $pct ? '<small class="text-secondary text-decoration-line-through d-block">' . format_price((float)$p['original_price']) . '</small>' : '';
    $save = $pct ? '<span class="badge text-bg-danger">Save ' . $pct . '%</span>' : '';
    $badge = $p['badge'] ? '<span class="badge text-bg-primary">' . esc($p['badge']) . '</span>' : '';
    $osIcon = $p['platform'] === 'Mac' ? 'macos' : 'windows';
    return '
    <div class="card product-card shop-row p-3 p-sm-4" data-testid="product-row-' . esc($p['slug']) . '">
      <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3 gap-sm-4">
        <a href="product.php?slug=' . esc($p['slug']) . '" class="flex-shrink-0 mx-auto mx-sm-0">
          <div class="shop-row-img rounded-4">
            <img src="' . esc($p['image']) . '" alt="' . esc(product_img_alt($p)) . '" title="' . esc($p['name']) . '" loading="lazy">
          </div>
        </a>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            ' . $badge . '
            <span class="badge os-badge"><img src="assets/images/os/' . $osIcon . '.svg" alt="" class="os-icon me-1">' . esc($p['platform'] ?: 'Windows') . '</span>
            ' . $save . '
          </div>
          <a href="product.php?slug=' . esc($p['slug']) . '" class="text-decoration-none text-body fw-bold fs-6 d-block">' . esc($p['name']) . '</a>
          <div class="small my-1">' . render_stars((float)$p['rating']) . ' <span class="text-secondary">(' . (int)$p['reviews'] . ' reviews)</span></div>
          <div class="d-flex flex-wrap gap-3 small text-secondary">
            <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant email delivery</span>
            <span><i class="bi bi-infinity text-primary me-1"></i>Lifetime license</span>
            <span class="d-none d-md-inline"><i class="bi bi-headset text-primary me-1"></i>Free install support</span>
          </div>
        </div>
        <div class="shop-row-buy text-sm-end flex-shrink-0">
          ' . $orig . '
          <div class="fw-bold text-primary fs-4 lh-1 mb-2">' . format_price((float)$p['price']) . '</div>
          <div class="mb-2">' . render_stock_pill($p['slug']) . '</div>
          <div class="d-flex flex-sm-column gap-2">
            ' . (available_keys_count($p['slug']) > 0
                ? '<button class="btn btn-sm btn-primary rounded-pill px-3 add-to-cart-btn" data-slug="' . esc($p['slug']) . '" data-testid="add-to-cart-' . esc($p['slug']) . '"><i class="bi bi-cart-plus me-1"></i>Add to Cart</button>
                   <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold buy-now-btn" data-slug="' . esc($p['slug']) . '" data-testid="buy-now-' . esc($p['slug']) . '"><i class="bi bi-lightning-charge me-1"></i>Buy Now</button>'
                : '<button class="btn btn-sm btn-secondary rounded-pill px-3" disabled data-testid="out-of-stock-' . esc($p['slug']) . '"><i class="bi bi-bell me-1"></i>Notify Me</button>') . '
            <a href="product.php?slug=' . esc($p['slug']) . '" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-testid="view-details-' . esc($p['slug']) . '">Details</a>
          </div>
        </div>
      </div>
    </div>';
}

function render_product_card(array $p): string
{
    $pct = ($p['original_price'] && $p['original_price'] > $p['price'])
        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
    $discount = $pct ? '<span class="badge text-bg-danger position-absolute top-0 end-0 m-2">-' . $pct . '%</span>' : '';
    $badge = $p['badge'] ? '<span class="badge text-bg-primary position-absolute top-0 start-0 m-2">' . esc($p['badge']) . '</span>' : '';
    $orig = $pct ? '<small class="text-secondary text-decoration-line-through">' . format_price((float)$p['original_price']) . '</small>' : '';
    $osIcon = $p['platform'] === 'Mac' ? 'macos' : 'windows';
    $stockN = available_keys_count($p['slug']);
    $stockPill = render_stock_pill($p['slug']);
    $cartBtn = $stockN > 0
        ? '<button class="pc-btn pc-btn-cart add-to-cart-btn" data-slug="' . esc($p['slug']) . '" data-testid="add-to-cart-' . esc($p['slug']) . '" aria-label="Add to cart"><i class="bi bi-cart-plus"></i><span class="pc-btn-label">Add</span></button>
           <button class="pc-btn pc-btn-buy buy-now-btn" data-slug="' . esc($p['slug']) . '" data-testid="buy-now-' . esc($p['slug']) . '" aria-label="Buy now"><i class="bi bi-lightning-charge-fill"></i><span class="pc-btn-label">Buy</span></button>'
        : '<button class="pc-btn pc-btn-notify" disabled data-testid="out-of-stock-' . esc($p['slug']) . '"><i class="bi bi-bell"></i><span class="pc-btn-label">Notify</span></button>';
    return '
    <div class="card product-card tilt-3d h-100 position-relative ' . ($stockN <= 0 ? 'is-out-of-stock' : '') . '" data-testid="product-card-' . esc($p['slug']) . '">
      ' . $badge . $discount . '
      <a href="product.php?slug=' . esc($p['slug']) . '" class="text-decoration-none">
        <div class="ratio ratio-1x1 bg-body-tertiary rounded-top product-img-wrap">
          <img src="' . esc($p['image']) . '" alt="' . esc(product_img_alt($p)) . '" title="' . esc($p['name']) . '" class="object-fit-contain p-3" loading="lazy">
        </div>
      </a>
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
          <span class="badge os-badge"><img src="assets/images/os/' . $osIcon . '.svg" alt="" class="os-icon me-1">' . esc($p['platform'] ?: 'Windows') . '</span>
          <span class="small">' . render_stars((float)$p['rating']) . ' <span class="text-secondary">(' . (int)$p['reviews'] . ')</span></span>
        </div>
        <a href="product.php?slug=' . esc($p['slug']) . '" class="text-decoration-none text-body fw-semibold product-title mb-1">' . esc($p['name']) . '</a>
        <div class="mb-2">' . $stockPill . '</div>
        <small class="text-secondary pc-meta mb-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant email delivery · Lifetime license</small>
        <div class="pc-price-row d-flex align-items-center justify-content-between gap-2 mt-auto pt-2">
          <div class="lh-1 d-flex align-items-baseline gap-2"><span class="fw-bold text-primary fs-5">' . format_price((float)$p['price']) . '</span>' . $orig . '</div>
          ' . $cartBtn . '
        </div>
      </div>
    </div>';
}

function generate_order_number(): string
{
    return 'MV' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

/**
 * Inventory helpers — count available license-keys for a product (within the
 * active region) so the public site can show real stock instead of relying on
 * the manual `products.stock` column.
 *
 * Results are memoized per-request so a listing of 12 products only hits the
 * DB once, not 12 times.
 */
function available_keys_count(string $slug): int {
    static $cache = [];
    $region = active_region_code();
    $key = $region . ':' . $slug;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM license_keys WHERE product_slug = ? AND status = 'available' AND region = ?");
        $st->execute([$slug, $region]);
        $cache[$key] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = 0;
    }
    return $cache[$key];
}

/** Renders the stock pill shown on every product card / row / strip card. */
function render_stock_pill(string $slug, string $size = 'sm'): string {
    $n = available_keys_count($slug);
    $cls = $size === 'lg' ? 'pc-stock-pill pc-stock-lg' : 'pc-stock-pill';
    if ($n <= 0) {
        return '<span class="' . $cls . ' is-out" data-testid="stock-out-' . esc($slug) . '">'
             . '<i class="bi bi-x-octagon-fill me-1"></i>Out of Stock</span>';
    }
    $low = $n <= 5;
    return '<span class="' . $cls . ' ' . ($low ? 'is-low' : 'is-in') . '" data-testid="stock-avail-' . esc($slug) . '" data-count="' . $n . '">'
         . '<i class="bi ' . ($low ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill') . ' me-1"></i>'
         . ($low ? 'Only ' . $n . ' left' : $n . ' in stock')
         . '</span>';
}

// Elegant page-header band with breadcrumb (shop / category / blog / cart)
// $crumbs: [label => href|null]; null = active crumb
function render_page_head(string $title, string $subtitle = '', array $crumbs = [], string $testId = 'page-head-title'): string
{
    $h = '<div class="page-head"><div class="container py-4 py-lg-5">';
    $h .= '<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">';
    $h .= '<li class="breadcrumb-item"><a href="index.php">Home</a></li>';
    foreach ($crumbs as $label => $href) {
        $h .= $href
            ? '<li class="breadcrumb-item"><a href="' . esc($href) . '">' . esc($label) . '</a></li>'
            : '<li class="breadcrumb-item active">' . esc($label) . '</li>';
    }
    $h .= '</ol></nav>';
    $h .= '<h1 class="fw-bold h2 mb-1" data-testid="' . esc($testId) . '">' . esc($title) . '</h1>';
    if ($subtitle) $h .= '<p class="text-secondary mb-0">' . esc($subtitle) . '</p>';
    return $h . '</div></div>';
}

/* ---------- product variants (Version / Edition / OS selectors) ---------- */

function parse_variant(array $p): array
{
    $n = preg_replace('/\s+/', ' ', strtolower(str_replace('&', 'and', $p['name'])));
    preg_match('/\b(20\d{2})\b/', $n, $m);
    $year = $m[1] ?? null;
    $v = array_merge($p, [
        'os' => ($p['platform'] === 'Mac' || str_contains($n, 'mac')) ? 'Mac' : 'PC',
        'year' => $year, 'base' => null, 'version' => null, 'edition' => null,
    ]);
    if (str_contains($n, 'project')) { $v['base'] = 'project'; $v['version'] = $year; return $v; }
    if (str_contains($n, 'visio'))   { $v['base'] = 'visio';   $v['version'] = $year; return $v; }
    if (str_starts_with($n, 'windows')) {
        $ver = str_contains($n, '11') ? '11' : (str_contains($n, '10') ? '10' : null);
        if (!$ver) return $v;
        $v['base'] = 'windows'; $v['version'] = $ver;
        $v['edition'] = str_contains($n, 'pro') ? 'Pro' : 'Home';
        return $v;
    }
    if (str_contains($n, 'word') && $year)  { $v['base'] = 'word';  $v['version'] = $year; return $v; }
    if (str_contains($n, 'excel') && $year) { $v['base'] = 'excel'; $v['version'] = $year; return $v; }
    if (str_contains($n, 'office') && $year) {
        $v['base'] = 'office'; $v['version'] = $year;
        foreach (['professional plus' => 'Professional Plus', 'home and business' => 'Home and Business',
                  'home and student' => 'Home and Student', 'home' => 'Home'] as $needle => $label) {
            if (str_contains($n, $needle)) { $v['edition'] = $label; break; }
        }
        return $v;
    }
    return $v;
}

function get_variant_group(array $product): array
{
    $cur = parse_variant($product);
    if (!$cur['base']) return ['cur' => $cur, 'versions' => [], 'editions' => [], 'os_options' => [], 'group' => []];

    $seen = []; $group = [];
    foreach (get_products() as $p) {
        $k = preg_replace('/\s+/', ' ', strtolower(str_replace('&', 'and', $p['name'])));
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $pv = parse_variant($p);
        if ($pv['base'] === $cur['base']) $group[] = $pv;
    }

    $versions = array_values(array_unique(array_filter(array_column($group, 'version'))));
    rsort($versions);
    $order = ['Home and Business', 'Professional Plus', 'Home and Student', 'Home', 'Pro'];
    $editions = array_values(array_unique(array_filter(array_column($group, 'edition'))));
    usort($editions, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
    // Always show both OS options for software that exists on PC/Mac families
    // (unavailable one is rendered blurred). Windows OS itself is PC-only.
    if ($cur['base'] !== 'windows') {
        $os = ['PC', 'Mac'];
    } else {
        $os = [];
    }
    if (count($editions) < 2) $editions = [];
    return ['cur' => $cur, 'versions' => $versions, 'editions' => $editions, 'os_options' => $os, 'group' => $group];
}

// null = wildcard for any of version / os / edition
function find_variant(array $group, ?string $version, ?string $os = null, ?string $edition = null): ?array
{
    foreach ($group as $p) {
        if (($version === null || $p['version'] === $version)
            && ($os === null || $p['os'] === $os)
            && ($edition === null || $p['edition'] === $edition)) return $p;
    }
    return null;
}

function render_variant_row(string $title, string $testPrefix, array $options, ?string $currentValue, callable $resolve, ?callable $label = null): string
{
    if (!$options) return '';
    $label = $label ?? fn($o) => $o;
    $osIcon = fn($o) => $testPrefix === 'os'
        ? '<img src="assets/images/os/' . ($o === 'Mac' ? 'macos' : 'windows') . '.svg" alt="" class="os-icon me-1">'
        : '';
    $html = '<div class="mb-3" data-testid="' . $testPrefix . '-selector"><small class="text-secondary d-block mb-1">' . esc($title)
          . ': <span class="fw-semibold">' . esc($label($currentValue)) . '</span></small><div class="d-flex flex-wrap gap-2">';
    foreach ($options as $opt) {
        $active = $opt === $currentValue;
        $target = $active ? null : $resolve($opt);
        $tid = ' data-testid="' . $testPrefix . '-option-' . slugify((string)$opt) . '"';
        if ($active) {
            $html .= '<span class="btn btn-sm btn-primary"' . $tid . '>' . $osIcon($opt) . esc($label($opt)) . '</span>';
        } elseif ($target) {
            $html .= '<a href="product.php?slug=' . esc($target['slug']) . '" class="btn btn-sm btn-outline-secondary"' . $tid . '>' . $osIcon($opt) . esc($label($opt)) . '</a>';
        } else {
            $html .= '<span class="btn btn-sm btn-outline-secondary variant-blur" title="Not available for this configuration"' . $tid . '>' . $osIcon($opt) . esc($label($opt)) . '</span>';
        }
    }
    return $html . '</div></div>';
}
