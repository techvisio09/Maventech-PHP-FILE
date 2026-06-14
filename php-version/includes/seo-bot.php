<?php
/**
 * SEO / GEO / AEO automation bot.
 *
 * Runs once every 24 hours (driven by /cron.php) and does:
 *
 *   1) IndexNow ping — submits the latest sitemap URLs to Bing (+ Yandex,
 *      Seznam, Naver — all share the IndexNow protocol).  No auth needed
 *      beyond hosting a single key file at /seobot-{key}.txt.
 *   2) Google sitemap ping — old but still-honoured /ping endpoint that
 *      asks Googlebot to refresh its sitemap copy.
 *   3) LLM content refresh — for every product missing a meta_description
 *      (or with one older than 30 days) the bot asks Claude Haiku to
 *      write a fresh ~155-char SEO description.  Uses the Emergent LLM
 *      gateway (OpenAI-compatible), batched to 5 products / run so we
 *      never spike LLM cost.
 *
 * All activity is logged to the `seo_runs` table so the dashboard mini-
 * card can show "last run / URLs submitted / LLM calls / errors".
 */

if (!defined('SEOBOT_INDEXNOW_BATCH'))  define('SEOBOT_INDEXNOW_BATCH', 100);
if (!defined('SEOBOT_LLM_BATCH'))       define('SEOBOT_LLM_BATCH',      5);
if (!defined('SEOBOT_REFRESH_DAYS'))    define('SEOBOT_REFRESH_DAYS',   30);
if (!defined('SEOBOT_BLOG_COOLDOWN_H')) define('SEOBOT_BLOG_COOLDOWN_H',20); // min hours between two auto-blog batches
if (!defined('SEOBOT_BLOG_POSTS_PER_REGION_PER_DAY')) define('SEOBOT_BLOG_POSTS_PER_REGION_PER_DAY', 6); // how many blogs per region per daily batch
// Markets the auto-blogger targets — keep in sync with regions table.
if (!defined('SEOBOT_BLOG_REGIONS'))    define('SEOBOT_BLOG_REGIONS',   'US,UK,AU,CA');

/**
 * Top-level entry point — called from cron.php after the email queue.
 * Returns an associative array summarising what happened (or
 * ['skipped' => true, 'reason' => '...'] when it's not yet due).
 */
function seo_bot_run_if_due(bool $force = false): array
{
    $pdo = db();
    seo_bot_ensure_schema($pdo);

    // Only one full run per 24h unless force=true (admin manual trigger).
    $lastRun = setting_get('seo_bot_last_run_at', '');
    if (!$force && $lastRun) {
        $hoursSince = (time() - strtotime($lastRun)) / 3600;
        if ($hoursSince < 24) {
            return ['skipped' => true, 'reason' => 'last run ' . round($hoursSince, 1) . 'h ago'];
        }
    }

    $runId = _seo_run_start($pdo);
    $report = [
        'started_at'       => date('c'),
        'indexnow_status'  => 'skipped',
        'indexnow_count'   => 0,
        'google_ping'      => 'skipped',
        'bing_ping'        => 'skipped',
        'llm_calls'        => 0,
        'llm_tokens_in'    => 0,
        'llm_tokens_out'   => 0,
        'products_updated' => 0,
        'blog_post_id'     => null,
        'blog_post_title'  => null,
        'blog_product_id'  => null,
        'blog_post_image'  => null,
        'errors'           => [],
    ];

    // 1) Sitemap pings — old-school but still works for both engines.
    $siteUrl  = rtrim(site_url(), '/');
    $sitemap  = $siteUrl . '/sitemap.xml';
    $report['google_ping'] = _seo_quick_get('https://www.google.com/ping?sitemap=' . urlencode($sitemap));
    $report['bing_ping']   = _seo_quick_get('https://www.bing.com/ping?sitemap='   . urlencode($sitemap));

    // 2) IndexNow batch submit.
    [$indexNowStatus, $indexNowCount] = _seo_indexnow_submit_urls(_seo_collect_index_urls(SEOBOT_INDEXNOW_BATCH), $report);
    $report['indexnow_status'] = $indexNowStatus;
    $report['indexnow_count']  = $indexNowCount;

    // 3) LLM-driven product metadata refresh.
    $refreshSummary = _seo_refresh_stale_metadata($pdo, $report);
    $report['products_updated'] = $refreshSummary['updated'];
    $report['llm_calls']        = $refreshSummary['calls'];
    $report['llm_tokens_in']    = $refreshSummary['tokens_in'];
    $report['llm_tokens_out']   = $refreshSummary['tokens_out'];

    // 4) AI-generated daily blog posts — N products, N fresh articles, fully
    //    automatic. SEOBOT_BLOG_POSTS_PER_DAY controls the batch size (6 by
    //    default). Each iteration's round-robin query picks a different
    //    product than the previous because we just inserted a row.
    $report['blog_posts'] = []; // [{id,title,product_id,image,product_name}, ...]
    $blogSummary = _seo_generate_daily_blog_batch($pdo, $report);
    if (!empty($blogSummary['posts'])) {
        $first = $blogSummary['posts'][0];
        $report['blog_post_id']    = $first['blog_post_id'];
        $report['blog_post_title'] = $first['blog_post_title'];
        $report['blog_product_id'] = $first['blog_product_id'];
        $report['blog_post_image'] = $first['blog_post_image'] ?? null;
        $report['blog_posts']      = $blogSummary['posts'];
        $report['llm_calls']       += (int)$blogSummary['calls'];
        $report['llm_tokens_in']   += (int)$blogSummary['tokens_in'];
        $report['llm_tokens_out']  += (int)$blogSummary['tokens_out'];
    }

    // Persist.
    setting_set('seo_bot_last_run_at', date('Y-m-d H:i:s'));
    _seo_run_finish($pdo, $runId, $report);

    $report['ended_at'] = date('c');
    return $report;
}

/* ===================================================================
 * Schema bootstrap — adds idempotent migrations so the bot works on a
 * cold pod / fresh DB without any manual SQL.
 * =================================================================== */
function seo_bot_ensure_schema(PDO $pdo): void
{
    try {
        // products: meta_description + seo_refreshed_at + ai_summary (used by llms.txt).
        $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('meta_description', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD meta_description VARCHAR(180) NULL AFTER description");
        }
        if (!in_array('seo_refreshed_at', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD seo_refreshed_at DATETIME NULL AFTER meta_description");
        }
        if (!in_array('ai_summary', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD ai_summary TEXT NULL AFTER seo_refreshed_at");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] products: ' . $e->getMessage()); }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS seo_runs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            started_at DATETIME NOT NULL,
            ended_at   DATETIME NULL,
            indexnow_status VARCHAR(20) NULL,
            indexnow_count  INT NULL,
            google_ping     VARCHAR(20) NULL,
            bing_ping       VARCHAR(20) NULL,
            llm_calls       INT NULL,
            llm_tokens_in   INT NULL,
            llm_tokens_out  INT NULL,
            products_updated INT NULL,
            errors_json     TEXT NULL,
            PRIMARY KEY(id),
            KEY idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { @error_log('[seo-bot schema] seo_runs: ' . $e->getMessage()); }

    try {
        // seo_runs: add auto-blog tracking columns if missing.
        $runCols = $pdo->query("SHOW COLUMNS FROM seo_runs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('blog_post_id', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_post_id VARCHAR(100) NULL AFTER products_updated");
        }
        if (!in_array('blog_post_title', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_post_title VARCHAR(255) NULL AFTER blog_post_id");
        }
        if (!in_array('blog_product_id', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_product_id INT NULL AFTER blog_post_title");
        }
        if (!in_array('blog_post_image', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_post_image VARCHAR(500) NULL AFTER blog_product_id");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] seo_runs blog cols: ' . $e->getMessage()); }

    try {
        // blog_posts: add light tracking so we know which posts were AI-authored
        // and which product they originated from (for round-robin rotation).
        $blogCols = $pdo->query("SHOW COLUMNS FROM blog_posts")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ai_generated', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD ai_generated TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('product_id', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD product_id INT NULL");
        }
        if (!in_array('created_at', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD created_at DATETIME NULL");
        }
        // Per-market targeting + per-post automation verification fields.
        if (!in_array('target_region', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD target_region VARCHAR(4) NULL, ADD KEY idx_target_region (target_region)");
        }
        if (!in_array('indexnow_status', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD indexnow_status VARCHAR(20) NULL");
        }
        if (!in_array('verified_http', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD verified_http SMALLINT NULL");
        }
        if (!in_array('verified_at', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD verified_at DATETIME NULL");
        }
        if (!in_array('internal_links_count', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD internal_links_count INT NULL");
        }
        if (!in_array('content_fingerprint', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD content_fingerprint VARCHAR(64) NULL, ADD KEY idx_fingerprint (content_fingerprint)");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] blog_posts: ' . $e->getMessage()); }
}

/* ===================================================================
 *  IndexNow — Bing / Yandex / Seznam / Naver
 * =================================================================== */
function _seo_indexnow_key(): string
{
    $key = setting_get('seo_indexnow_key', '');
    if ($key === '' || strlen($key) < 32) {
        $key = bin2hex(random_bytes(16)); // 32-char lowercase hex
        setting_set('seo_indexnow_key', $key);
    }
    // Drop the verification file into the webroot if missing.
    $file = __DIR__ . '/../' . $key . '.txt';
    if (!is_file($file)) @file_put_contents($file, $key);
    return $key;
}

function _seo_collect_index_urls(int $limit): array
{
    $pdo  = db();
    $site = rtrim(site_url(), '/');
    $urls = [];

    // Core pages first — these should always re-ping.
    foreach (['/', '/shop.php', '/reviews.php', '/blog.php', '/contact.php', '/sitemap.xml', '/merchant-feed.xml', '/llms.txt'] as $p) {
        $urls[] = $site . $p;
    }

    // Products — every active item.
    foreach ($pdo->query("SELECT slug FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT $limit") as $r) {
        $urls[] = $site . '/product.php?slug=' . urlencode($r['slug']);
    }

    return array_slice(array_unique($urls), 0, $limit);
}

function _seo_indexnow_submit_urls(array $urls, array &$report): array
{
    if (!$urls) return ['no_urls', 0];
    $key   = _seo_indexnow_key();
    $host  = parse_url(site_url(), PHP_URL_HOST);
    $body  = json_encode([
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => rtrim(site_url(), '/') . '/' . $key . '.txt',
        'urlList'     => array_values($urls),
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.indexnow.org/IndexNow');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 12,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $report['errors'][] = 'indexnow curl: ' . $err;
        return ['curl_error', 0];
    }
    // 200 = accepted; 202 = accepted async; 400/422 = invalid; 429 = too many.
    $status = 'http_' . $code;
    if ($code >= 200 && $code < 300) $status = 'ok';
    return [$status, count($urls)];
}

/* ===================================================================
 *  Tiny GET helper for the sitemap pings — short timeout, swallow errors.
 * =================================================================== */
function _seo_quick_get(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    @curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code ? 'http_' . $code : 'no_response';
}

/* ===================================================================
 *  LLM content refresh — Claude Haiku via the Emergent gateway.
 *  Generates a fresh 140-160 char SEO meta description for products
 *  whose `meta_description` is empty or older than SEOBOT_REFRESH_DAYS.
 * =================================================================== */
function _seo_refresh_stale_metadata(PDO $pdo, array &$report): array
{
    $out = ['updated' => 0, 'calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0];

    $cutoff = date('Y-m-d H:i:s', strtotime('-' . SEOBOT_REFRESH_DAYS . ' days'));
    $stmt = $pdo->prepare("
        SELECT id, slug, name, brand, category, version, price, description
          FROM products
         WHERE is_active = 1
           AND (meta_description IS NULL OR meta_description = ''
                OR seo_refreshed_at IS NULL OR seo_refreshed_at < ?)
         ORDER BY COALESCE(seo_refreshed_at, '1970-01-01') ASC
         LIMIT " . SEOBOT_LLM_BATCH);
    $stmt->execute([$cutoff]);
    $stale = $stmt->fetchAll();
    if (!$stale) return $out;

    $apiKey  = defined('OPENAI_API_KEY')  ? OPENAI_API_KEY  : (getenv('EMERGENT_LLM_KEY') ?: '');
    $baseUrl = defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : '';
    if ($apiKey === '' || $baseUrl === '') {
        $report['errors'][] = 'LLM key/base url not configured — skipping metadata refresh';
        return $out;
    }

    $upd = $pdo->prepare("UPDATE products
                            SET meta_description = ?, ai_summary = ?, seo_refreshed_at = NOW()
                          WHERE id = ?");

    foreach ($stale as $p) {
        $sys = <<<SYS
You are an expert e-commerce SEO copywriter for Maventech Software, an authorised
reseller of digital license keys (Microsoft, Bitdefender, Norton, McAfee, Adobe,
Autodesk, etc.).  For the product below, return STRICT JSON with exactly two keys:

  meta_description: a single-sentence SEO meta description, 140-160 characters,
                    natural English, no quotation marks, no emoji, includes
                    brand + edition + key benefit + 'instant delivery'.
  ai_summary:       2-3 short sentences (max 400 chars) optimised for AI search
                    engines (ChatGPT / Perplexity / Bing Chat) — answer the
                    question "what is {product} and who should buy it".
                    Plain English, no marketing fluff, no superlatives.

Output ONLY the JSON object — no prefix, no markdown fences.
SYS;
        $usr = "PRODUCT NAME: {$p['name']}\n"
             . "BRAND: " . ($p['brand'] ?: 'n/a') . "\n"
             . "CATEGORY: " . ($p['category'] ?: 'n/a') . "\n"
             . "VERSION: " . ($p['version'] ?: 'n/a') . "\n"
             . "PRICE: \${$p['price']}\n"
             . "RAW DESCRIPTION:\n" . trim((string)$p['description']);

        $payload = json_encode([
            'model'    => 'claude-haiku-4-5-20251001',
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $usr],
            ],
            'max_tokens'  => 320,
            'temperature' => 0.3,
        ]);

        $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => 25,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $out['calls']++;

        if ($err || !$raw || $code >= 400) {
            $report['errors'][] = "LLM for {$p['slug']}: " . ($err ?: 'HTTP ' . $code);
            continue;
        }
        $data   = json_decode((string)$raw, true);
        $answer = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        $out['tokens_in']  += (int)($data['usage']['prompt_tokens']     ?? 0);
        $out['tokens_out'] += (int)($data['usage']['completion_tokens'] ?? 0);

        // Strip code fences if the model wrapped the JSON.
        $answer = preg_replace('/^```(?:json)?\s*|```\s*$/i', '', $answer);
        $j = json_decode($answer, true);
        if (!is_array($j) || empty($j['meta_description'])) {
            $report['errors'][] = "LLM for {$p['slug']}: invalid JSON";
            continue;
        }
        $meta = mb_substr(trim((string)$j['meta_description']), 0, 180);
        $sum  = mb_substr(trim((string)($j['ai_summary'] ?? '')), 0, 400);
        $upd->execute([$meta, $sum, $p['id']]);
        $out['updated']++;
    }

    return $out;
}

/* ===================================================================
 *  AI-AUTHORED DAILY BLOG POST
 *  --------------------------------------------------------------------
 *  Once every ~24 h we pick ONE active product (round-robin so we never
 *  repeat the same product two weeks in a row), then ask Claude Haiku to
 *  write a short, 100% original, SEO-friendly blog article about it.
 *
 *  The result is inserted straight into `blog_posts` (the same table the
 *  public /blog.php page reads from) so it goes live with zero manual
 *  approval. The admin dashboard SEO Bot card then surfaces the brand-
 *  new post inline so the operator can see exactly what was published.
 * =================================================================== */
function _seo_generate_daily_blog_batch(PDO $pdo, array &$report): array
{
    $out = [
        'posts'      => [],
        'calls'      => 0,
        'tokens_in'  => 0,
        'tokens_out' => 0,
        'by_region'  => [], // ['US' => 6, 'UK' => 6, ...]
    ];

    // 24 h cooldown applies to the BATCH (not each individual post).
    $last = setting_get('seo_bot_last_blog_post_at', '');
    if ($last) {
        $hoursSince = (time() - strtotime($last)) / 3600;
        if ($hoursSince < SEOBOT_BLOG_COOLDOWN_H) {
            return $out;
        }
    }

    $apiKey  = defined('OPENAI_API_KEY')  ? OPENAI_API_KEY  : (getenv('EMERGENT_LLM_KEY') ?: '');
    $baseUrl = defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : '';
    if ($apiKey === '' || $baseUrl === '') {
        $report['errors'][] = 'blog: LLM key/base URL not configured';
        return $out;
    }

    // Loop EVERY target region (US, UK, AU, CA) × N posts each = 24 posts/day.
    // Each post is written FOR that market — currency, locale, delivery
    // wording all match the region.  Round-robin is maintained PER region,
    // so the US queue and the UK queue evolve independently.
    $regions = array_values(array_filter(array_map('trim', explode(',', SEOBOT_BLOG_REGIONS))));
    $perRegion = max(1, (int)SEOBOT_BLOG_POSTS_PER_REGION_PER_DAY);

    foreach ($regions as $targetRegion) {
        $out['by_region'][$targetRegion] = 0;
        $usedProductIds = [];
        for ($i = 1; $i <= $perRegion; $i++) {
            $one = _seo_generate_one_blog_post($pdo, $apiKey, $baseUrl, $targetRegion, $usedProductIds, $report);
            $out['calls']      += (int)$one['calls'];
            $out['tokens_in']  += (int)$one['tokens_in'];
            $out['tokens_out'] += (int)$one['tokens_out'];
            if (!empty($one['blog_post_id'])) {
                $usedProductIds[] = (int)$one['blog_product_id'];
                $out['by_region'][$targetRegion]++;
                $out['posts'][] = [
                    'blog_post_id'    => $one['blog_post_id'],
                    'blog_post_title' => $one['blog_post_title'],
                    'blog_product_id' => $one['blog_product_id'],
                    'blog_post_image' => $one['blog_post_image'] ?? null,
                    'product_name'    => $one['product_name'] ?? '',
                    'target_region'   => $targetRegion,
                ];
            } else {
                // Move to next region if this one ran out of eligible products
                // — the error is already in $report['errors'].
                break;
            }
        }
    }

    if ($out['posts']) {
        setting_set('seo_bot_last_blog_post_at', date('Y-m-d H:i:s'));
    }
    return $out;
}

/**
 * Write ONE auto-blog. Pulled out of the old _seo_generate_daily_blog_post
 * so the batch wrapper can call it repeatedly.  $excludeProductIds prevents
 * picking the same product twice within the same batch (the round-robin
 * SELECT already does this naturally but we add a hard NOT IN guard for
 * absolute safety against races).
 */
function _seo_generate_one_blog_post(PDO $pdo, string $apiKey, string $baseUrl, string $targetRegion, array $excludeProductIds, array &$report): array
{
    $out = [
        'blog_post_id'    => null,
        'blog_post_title' => null,
        'blog_product_id' => null,
        'blog_post_image' => null,
        'product_name'    => '',
        'calls'           => 0,
        'tokens_in'       => 0,
        'tokens_out'      => 0,
    ];

    // Per-region round-robin: a product can be blogged for each region once,
    // and within each region we always pick the one whose last AI post for
    // THIS region is the oldest (or none yet).  Plus a NOT-IN guard to keep
    // the current batch fresh.
    $excludeSql = '';
    if ($excludeProductIds) {
        $excludeSql = ' AND p.id NOT IN (' . implode(',', array_fill(0, count($excludeProductIds), '?')) . ')';
    }
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.name, p.brand, p.category, p.version, p.price,
               p.image, p.description, p.apps, p.region AS source_region,
               (SELECT MAX(bp.created_at) FROM blog_posts bp
                 WHERE bp.product_id = p.id AND bp.ai_generated = 1
                   AND bp.target_region = ?) AS last_ai_post_at
          FROM products p
         WHERE p.is_active = 1
           $excludeSql
         ORDER BY (last_ai_post_at IS NULL) DESC, last_ai_post_at ASC, RAND()
         LIMIT 1");
    $stmt->execute(array_merge([$targetRegion], $excludeProductIds));
    $product = $stmt->fetch();
    if (!$product) {
        $report['errors'][] = "blog[$targetRegion]: no eligible product (batch_pos=" . (count($excludeProductIds)+1) . ")";
        return $out;
    }
    $out['product_name'] = (string)$product['name'];

    // Region-specific copy hints — currency, locale, delivery wording.
    $regionContext = [
        'US' => ['country' => 'United States',  'currency' => 'USD ($)', 'locale' => 'American English', 'delivery' => 'instant digital delivery to any US address'],
        'UK' => ['country' => 'United Kingdom', 'currency' => 'GBP (£)', 'locale' => 'British English',  'delivery' => 'instant digital delivery across the UK including Northern Ireland'],
        'AU' => ['country' => 'Australia',      'currency' => 'AUD (A$)', 'locale' => 'Australian English', 'delivery' => 'instant digital delivery to anywhere in Australia and NZ'],
        'CA' => ['country' => 'Canada',         'currency' => 'CAD (C$)', 'locale' => 'Canadian English (with bilingual support)', 'delivery' => 'instant digital delivery coast-to-coast across Canada'],
    ];
    $rc = $regionContext[$targetRegion] ?? $regionContext['US'];

    $sys = <<<SYS
You are a senior content strategist for Maventech Software, an authorised
reseller of genuine digital software license keys (Microsoft, Bitdefender,
Norton, McAfee, Adobe, Autodesk, etc.). Your job is to write a SHORT,
ORIGINAL, SEO-friendly blog article that helps buyers in {$rc['country']}
decide whether the product below is right for them.

Return STRICT JSON with EXACTLY these keys (no markdown, no code fences):

  title:       A compelling, search-friendly title (50-70 chars). MUST include
               the country or currency naturally — e.g. "… for {$rc['country']}
               buyers" or "… (Price in {$rc['currency']})".  No quotes.
  lead:        A single one-sentence hook (100-160 chars).
  read_time:   A short string like "5 min read".
  content_html: Body HTML, 450-700 words. Use ONLY these tags: <p>, <h2>,
                <ul>, <li>, <strong>, <em>, <a>. NO inline styles, NO
                scripts. Start with a <p class="lead"> paragraph using the
                lead above, then 2-3 <h2> sections, a <ul> with 4-5 key
                takeaways, and finish with a closing paragraph that links
                to the product page using the slug provided.

Rules:
 - Write specifically for buyers in {$rc['country']}. Use {$rc['locale']}.
 - When you mention price or savings, quote in {$rc['currency']} only.
 - Mention {$rc['delivery']} once in the article.
 - First-person plural ("we", "our team") tone — confident, no hype.
 - Always include this anchor in the closing paragraph:
     <a href="product.php?slug=PRODUCT_SLUG">Shop &lt;brand&gt; &lt;edition&gt; in {$rc['country']} →</a>
 - DO NOT invent absolute prices (no "$179", no "£149"). Use phrases like
   "starting at our published {$rc['currency']} price" instead.
 - Do not promise discounts. Do not mention competitors by name.
 - Output MUST be valid JSON — no text before or after.
SYS;

    $usr = "PRODUCT_NAME: {$product['name']}\n"
         . "PRODUCT_SLUG: {$product['slug']}\n"
         . "BRAND: " . ($product['brand'] ?: 'n/a') . "\n"
         . "CATEGORY: " . ($product['category'] ?: 'n/a') . "\n"
         . "VERSION: " . ($product['version'] ?: 'n/a') . "\n"
         . "APPS: " . ($product['apps'] ?: 'n/a') . "\n"
         . "TARGET_COUNTRY: {$rc['country']}\n"
         . "TARGET_CURRENCY: {$rc['currency']}\n"
         . "RAW_DESCRIPTION:\n" . trim((string)$product['description']);

    $payload = json_encode([
        'model'       => 'claude-haiku-4-5-20251001',
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ],
        'max_tokens'  => 1400,
        'temperature' => 0.75,
    ]);

    $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 45,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $out['calls'] = 1;

    if ($err || !$raw || $code >= 400) {
        $report['errors'][] = "blog[$targetRegion]: LLM call failed — " . ($err ?: 'HTTP ' . $code);
        return $out;
    }
    $data   = json_decode((string)$raw, true);
    $answer = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    $out['tokens_in']  = (int)($data['usage']['prompt_tokens']     ?? 0);
    $out['tokens_out'] = (int)($data['usage']['completion_tokens'] ?? 0);

    $answer = preg_replace('/^```(?:json)?\s*|```\s*$/i', '', $answer);
    $j = json_decode($answer, true);
    if (!is_array($j) || empty($j['title']) || empty($j['content_html'])) {
        $report['errors'][] = "blog[$targetRegion]: invalid JSON from LLM";
        return $out;
    }

    $title    = mb_substr(trim((string)$j['title']), 0, 200);
    $readTime = mb_substr(trim((string)($j['read_time'] ?? '5 min read')), 0, 20) ?: '5 min read';
    $content  = _seo_blog_sanitize_html((string)$j['content_html']);
    $image    = (string)$product['image'] ?: 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?q=80&w=872&auto=format&fit=crop';

    if (mb_strlen(strip_tags($content)) < 200) {
        $report['errors'][] = "blog[$targetRegion]: LLM body too short (" . mb_strlen(strip_tags($content)) . ' chars)';
        return $out;
    }

    // Per-region post id — keeps the four regional variants for the same
    // product distinct (ai-20260614-office-2024-US, …-UK, …-AU, …-CA).
    $postId = 'ai-' . date('Ymd') . '-' . substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($product['slug'])), 0, 50) . '-' . strtolower($targetRegion);
    $existing = $pdo->prepare('SELECT 1 FROM blog_posts WHERE id = ?');
    $existing->execute([$postId]);
    if ($existing->fetchColumn()) {
        $postId .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    // Count internal anchor links (the "backend links" SEO signal) and
    // store a content fingerprint for the weekly DMCA scraper scan.
    $internalLinks = preg_match_all('/href=\"(product\.php|category\.php|blog-post\.php|shop\.php|page\.php)/i', $content, $im);
    $fingerprint   = hash('sha1', preg_replace('/\s+/', ' ', trim(strip_tags($content))));

    try {
        $ins = $pdo->prepare("INSERT INTO blog_posts
            (id, title, date, read_time, image, content, ai_generated, product_id,
             target_region, internal_links_count, content_fingerprint, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())");
        $ins->execute([
            $postId,
            $title,
            date('M j, Y'),
            $readTime,
            $image,
            $content,
            (int)$product['id'],
            $targetRegion,
            (int)$internalLinks,
            $fingerprint,
        ]);
    } catch (Throwable $e) {
        $report['errors'][] = "blog[$targetRegion]: insert failed — " . $e->getMessage();
        return $out;
    }

    // ---- Per-post backlink + indexing verification ----
    // 1) Fire IndexNow for the single new URL (instant Bing / Yandex / Naver)
    // 2) HEAD check the live URL so we know it really resolved 200
    // 3) Persist both results onto the blog_posts row.
    $siteUrl = rtrim(site_url(), '/');
    $postUrl = $siteUrl . '/blog-post.php?id=' . rawurlencode($postId);
    $indexNowStatus = 'skipped';
    try {
        $rep = [];
        [$indexNowStatus] = _seo_indexnow_submit_urls([$postUrl], $rep);
    } catch (Throwable $e) { $indexNowStatus = 'error'; }

    $verifiedHttp = 0;
    try {
        $cv = curl_init($postUrl);
        curl_setopt_array($cv, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($cv);
        $verifiedHttp = (int)curl_getinfo($cv, CURLINFO_RESPONSE_CODE);
        curl_close($cv);
    } catch (Throwable $e) {}

    try {
        $pdo->prepare("UPDATE blog_posts SET indexnow_status = ?, verified_http = ?, verified_at = NOW() WHERE id = ?")
            ->execute([$indexNowStatus, $verifiedHttp ?: null, $postId]);
    } catch (Throwable $e) {}

    $out['blog_post_id']    = $postId;
    $out['blog_post_title'] = $title;
    $out['blog_product_id'] = (int)$product['id'];
    $out['blog_post_image'] = $image;
    return $out;
}

/**
 * Whitelist-sanitise the model's HTML so we never let through scripts or
 * inline style/event-handler tricks. We allow only the tags we asked for.
 */
function _seo_blog_sanitize_html(string $html): string
{
    // Decode common JSON-escaped slashes.
    $html = str_replace('\/', '/', $html);
    // Drop any tag not in our allow-list.
    $allowed = '<p><h2><h3><ul><ol><li><strong><b><em><i><a><br>';
    $clean   = strip_tags($html, $allowed);
    // Strip on* event handlers and javascript: URIs, leave href intact.
    $clean = preg_replace('#\son[a-z]+="[^"]*"#i',   '', $clean);
    $clean = preg_replace("#\son[a-z]+='[^']*'#i",   '', $clean);
    $clean = preg_replace('#href\s*=\s*"\s*javascript:[^"]*"#i', 'href="#"', $clean);
    $clean = preg_replace("#href\s*=\s*'\s*javascript:[^']*'#i", 'href="#"', $clean);
    return trim($clean);
}

/* ===================================================================
 *  Run logging
 * =================================================================== */
function _seo_run_start(PDO $pdo): int
{
    $pdo->prepare("INSERT INTO seo_runs (started_at) VALUES (NOW())")->execute();
    return (int)$pdo->lastInsertId();
}
function _seo_run_finish(PDO $pdo, int $runId, array $report): void
{
    $pdo->prepare("UPDATE seo_runs SET
        ended_at = NOW(),
        indexnow_status = ?, indexnow_count = ?,
        google_ping = ?, bing_ping = ?,
        llm_calls = ?, llm_tokens_in = ?, llm_tokens_out = ?,
        products_updated = ?,
        blog_post_id = ?, blog_post_title = ?, blog_product_id = ?, blog_post_image = ?,
        errors_json = ?
      WHERE id = ?")->execute([
        (string)($report['indexnow_status'] ?? ''),
        (int)   ($report['indexnow_count']  ?? 0),
        (string)($report['google_ping']     ?? ''),
        (string)($report['bing_ping']       ?? ''),
        (int)   ($report['llm_calls']       ?? 0),
        (int)   ($report['llm_tokens_in']   ?? 0),
        (int)   ($report['llm_tokens_out']  ?? 0),
        (int)   ($report['products_updated'] ?? 0),
        $report['blog_post_id']    ?? null,
        $report['blog_post_title'] ?? null,
        $report['blog_product_id'] ?? null,
        $report['blog_post_image'] ?? null,
        json_encode((array)($report['errors'] ?? []), JSON_UNESCAPED_SLASHES),
        $runId,
    ]);
}

/* ===================================================================
 *  Public helper for the admin dashboard mini-card.
 * =================================================================== */
function seo_bot_latest_run(): ?array
{
    try {
        $pdo = db();
        seo_bot_ensure_schema($pdo);
        $r = $pdo->query("SELECT * FROM seo_runs ORDER BY id DESC LIMIT 1")->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ===================================================================
 *  SELF-CRON — fire-and-forget tick for every HTTP request.
 *  --------------------------------------------------------------------
 *  This is what makes the auto-blogger *truly* automatic: any visitor
 *  (or an admin opening the dashboard) becomes the heartbeat that
 *  publishes the daily blog post. No system cron, no cPanel setup,
 *  no manual button.
 *
 *  How it works:
 *    1) Tiny, single-row settings lookup ("was last run > 24 h ago?").
 *    2) A lock file in sys_get_temp_dir() prevents two concurrent
 *       requests from both firing the bot. The lock TTL is 10 minutes
 *       — long enough for the LLM call, short enough to recover from
 *       a crashed worker.
 *    3) After the lock is taken we close the HTTP response to the
 *       browser (so the visitor sees their page instantly) and run
 *       the actual SEO bot in the still-alive PHP worker.
 *
 *  Safe to call from header.php on EVERY request — the early exit
 *  branches add ~0.1 ms when the bot isn't due.
 * =================================================================== */
function seo_bot_autotick(): void
{
    // Don't trip during CLI scripts, the dedicated cron worker, or bots.
    if (PHP_SAPI === 'cli') return;
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['cron.php'], true)) return;
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '' || preg_match('/bot|crawler|spider|googlebot|bingbot|yandex|baidu|facebookexternalhit|slack|discord|preview|monitor/i', $ua)) return;

    try {
        $last = setting_get('seo_bot_last_run_at', '');
        if ($last && (time() - strtotime($last)) < 24 * 3600) return; // not due
    } catch (Throwable $e) { return; }

    // Single-flight lock so two simultaneous visitors don't both fire.
    $lockFile = sys_get_temp_dir() . '/maventech_seo_bot.lock';
    if (is_file($lockFile) && (time() - filemtime($lockFile)) < 600) return; // 10 min TTL
    if (@file_put_contents($lockFile, (string)time()) === false) return;

    // Defer the actual run until AFTER PHP has finished sending the response
    // to the browser, so the visitor isn't blocked by the LLM call.
    register_shutdown_function(static function () use ($lockFile) {
        // Close the connection to the browser ASAP.
        ignore_user_abort(true);
        @set_time_limit(120);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // For PHP built-in server / non-FPM environments — best-effort flush.
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
        }
        try {
            seo_bot_run_if_due(false);
        } catch (Throwable $e) {
            @error_log('[seo-bot autotick] ' . $e->getMessage());
        } finally {
            @unlink($lockFile);
        }
    });
}
