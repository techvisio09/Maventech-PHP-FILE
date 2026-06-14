<?php
/* =====================================================================
 *  SEO content helpers — long-tail keyword copy blocks, schema.org
 *  structured data, deep-link clusters and buying-guide content for
 *  product and category pages.
 *
 *  Why this file:
 *    - Modern Google + AI search engines (ChatGPT, Perplexity, Bing
 *      Chat, Google AI Overviews) reward pages with rich on-page copy,
 *      explicit schema.org metadata and tight internal link clusters.
 *    - These helpers generate that content per-product / per-category
 *      from the same database — every new product becomes an SEO
 *      landing page automatically.
 * ===================================================================== */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email.php';

/* ------------------------------------------------------------------
 *  product_long_tail_keywords()
 *  Returns a dense comma-separated meta-keywords string targeting
 *  high-intent mid-tail and long-tail searches.
 * ----------------------------------------------------------------- */
function product_long_tail_keywords(array $p): string
{
    $name     = trim((string)$p['name']);
    $platform = $p['platform'] ?: 'Windows';
    $brand    = product_detected_brand($p);
    $year     = '';
    if (preg_match('/\b(20\d{2})\b/', $name, $m)) $year = $m[1];
    $base = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $name));

    $kw = [
        $name,
        'buy ' . $name,
        $name . ' license key',
        $name . ' product key',
        $name . ' activation key',
        $name . ' digital download',
        $name . ' lifetime license',
        $name . ' one time purchase no subscription',
        $name . ' instant email delivery',
        'genuine ' . $name . ' license',
        'cheap ' . $name . ' license key',
        'discount ' . $name,
        $name . ' for ' . $platform,
        $name . ' for ' . $platform . ' download',
        $name . ' activation key online',
        'how to activate ' . $name,
        'how to download ' . $name,
        'where to buy ' . $name,
        'best price for ' . $name,
        'is ' . $name . ' a one time purchase',
        $brand . ' authorized reseller',
        $brand . ' genuine software',
    ];
    if ($year !== '') {
        $kw[] = $base . ' ' . $year . ' license key';
        $kw[] = $base . ' ' . $year . ' for ' . $platform;
        $kw[] = $base . ' ' . $year . ' lifetime activation';
        $kw[] = $name . ' new edition';
    }
    return implode(', ', array_values(array_unique(array_filter($kw))));
}

/* ------------------------------------------------------------------
 *  product_detected_brand()
 *  Same brand-detection dictionary used in product.php — extracted
 *  here so other helpers can reuse it without duplication.
 * ----------------------------------------------------------------- */
function product_detected_brand(array $p): string
{
    $lookup = [
        'bitdefender' => 'Bitdefender', 'norton' => 'Norton', 'mcafee' => 'McAfee',
        'kaspersky'   => 'Kaspersky',   'eset'   => 'ESET',   'avast'  => 'Avast',
        'avg'         => 'AVG',         'webroot'=> 'Webroot','trend micro' => 'Trend Micro',
        'malwarebytes'=> 'Malwarebytes','adobe'  => 'Adobe',  'autocad'=> 'Autodesk',
        'autodesk'    => 'Autodesk',    'corel'  => 'Corel',  'parallels' => 'Parallels',
        'windows'     => 'Microsoft',   'office' => 'Microsoft','visio' => 'Microsoft',
        'project'     => 'Microsoft',   'microsoft' => 'Microsoft',
    ];
    $needle = strtolower((string)($p['name'] ?? ''));
    foreach ($lookup as $kw => $br) {
        if (strpos($needle, $kw) !== false) return $br;
    }
    return 'Microsoft';
}

/* ------------------------------------------------------------------
 *  product_seo_copy()
 *  Returns rich HTML SEO content with H2/H3 hierarchy + long-tail
 *  keyword phrases woven naturally into the body.  Rendered visibly
 *  on the product page so both humans AND crawlers consume it.
 * ----------------------------------------------------------------- */
function product_seo_copy(array $p): string
{
    $name     = esc((string)$p['name']);
    $platform = esc($p['platform'] ?: 'Windows');
    $brand    = esc(product_detected_brand($p));
    $price    = format_price((float)$p['price']);
    $year     = '';
    if (preg_match('/\b(20\d{2})\b/', (string)$p['name'], $m)) $year = $m[1];

    $h = '<section class="pd-seo-copy" data-testid="product-seo-copy" aria-labelledby="pd-seo-heading">';
    $h .= '<h2 id="pd-seo-heading" class="fw-bold h4 mt-5 mb-3">Why buy ' . $name . ' from ' . esc(SITE_BRAND) . '?</h2>';
    $h .= '<p class="text-secondary">Looking for the most reliable place to <strong>buy ' . $name . ' online</strong>? You are in the right place. ';
    $h .= esc(SITE_BRAND) . ' delivers a <strong>genuine ' . $brand . ' license key</strong> for ' . $name . ' at ' . esc($price) . ' &mdash; ';
    $h .= 'a one-time purchase with a <strong>lifetime activation</strong>, no monthly fees and no surprise renewals. ';
    $h .= 'Your key arrives by email in 15&ndash;30 minutes, ready to activate directly inside the official ' . $brand . ' installer.</p>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">' . $name . ' &mdash; quick facts</h3>';
    $h .= '<ul class="pd-seo-facts small text-secondary mb-4">';
    $h .= '<li><strong>Platform:</strong> ' . $platform . ($year ? ' &middot; <strong>Edition:</strong> ' . esc($year) : '') . '</li>';
    $h .= '<li><strong>Licence type:</strong> Lifetime / perpetual &mdash; not a rental subscription.</li>';
    $h .= '<li><strong>Delivery:</strong> Instant email with the activation key + official download link.</li>';
    $h .= '<li><strong>Activation:</strong> Direct inside the official ' . $brand . ' software &mdash; no third-party loaders.</li>';
    $h .= '<li><strong>Guarantee:</strong> 30-day money-back, replacement key if anything goes wrong.</li>';
    $h .= '</ul>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How to activate ' . $name . ' after purchase</h3>';
    $h .= '<ol class="small text-secondary mb-4">';
    $h .= '<li>Complete checkout &mdash; your ' . $name . ' license key + official ' . $brand . ' download link arrive by email within 15&ndash;30 minutes.</li>';
    $h .= '<li>Download the genuine installer from the link in the email (or directly from the ' . $brand . ' website).</li>';
    $h .= '<li>Run the installer and sign in to your ' . $brand . ' account.</li>';
    $h .= '<li>Paste the activation key when prompted &mdash; activation completes in seconds.</li>';
    $h .= '<li>Need help? Our specialists set it up for you on a free assisted call.</li>';
    $h .= '</ol>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Is ' . $name . ' a one-time purchase?</h3>';
    $h .= '<p class="text-secondary mb-4">Yes &mdash; this listing is the perpetual licence. Pay once at ' . esc($price) . ', activate on your ' . $platform . ' device, and use ' . $name . ' for as long as you own the device. There are no monthly fees, no renewals and no surprise charges. If you need to move the licence to a new computer, our support team helps you transfer it free of charge.</p>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Best price for ' . $name . ' in ' . date('Y') . '</h3>';
    $h .= '<p class="text-secondary mb-0">' . esc(SITE_BRAND) . ' partners directly with authorised channels, which is how we can sell ' . $name . ' for ' . esc($price) . ' &mdash; up to 81% below the manufacturer&rsquo;s retail price. ';
    $h .= 'Every key is verified pre-dispatch, every payment is encrypted, and every order is protected by our 30-day money-back guarantee. ';
    $h .= 'Compare us with any other reseller on price, delivery speed and support quality &mdash; we are confident you will buy here.</p>';
    $h .= '</section>';
    return $h;
}

/* ------------------------------------------------------------------
 *  product_howto_jsonld()
 *  HowTo schema for "How to activate <product>".  Google promotes
 *  HowTo rich results AND AI search engines parse them for step-by-step
 *  guidance.
 * ----------------------------------------------------------------- */
function product_howto_jsonld(array $p): array
{
    $name  = (string)$p['name'];
    $brand = product_detected_brand($p);
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'HowTo',
        'name'     => 'How to activate ' . $name,
        'description' => 'Step-by-step guide to activating your ' . $name . ' licence key from ' . SITE_BRAND . '.',
        'totalTime'   => 'PT5M',
        'tool'        => ['Your ' . $brand . ' licence key', 'Internet connection', 'Your ' . ($p['platform'] ?: 'Windows') . ' device'],
        'step'        => [
            ['@type' => 'HowToStep', 'position' => 1, 'name' => 'Receive your licence key',
             'text' => 'Your ' . $name . ' licence key arrives by email within 15-30 minutes of completing checkout, along with the official ' . $brand . ' download link.'],
            ['@type' => 'HowToStep', 'position' => 2, 'name' => 'Download the official installer',
             'text' => 'Click the download link in the email or visit the official ' . $brand . ' website to download the genuine installer for ' . ($p['platform'] ?: 'Windows') . '.'],
            ['@type' => 'HowToStep', 'position' => 3, 'name' => 'Install the software',
             'text' => 'Run the downloaded installer and follow the on-screen prompts. Sign in with (or create) your ' . $brand . ' account when asked.'],
            ['@type' => 'HowToStep', 'position' => 4, 'name' => 'Enter your activation key',
             'text' => 'When the installer asks for an activation key, paste the key from your delivery email and confirm. Activation completes in seconds.'],
            ['@type' => 'HowToStep', 'position' => 5, 'name' => 'Start using ' . $name,
             'text' => 'Once activated, ' . $name . ' is yours for life &mdash; no renewals or subscriptions. If anything goes wrong, our support team is available to help.'],
        ],
    ];
}

/* ------------------------------------------------------------------
 *  product_ai_summary_jsonld()
 *  AI-friendly Article schema with `about > Product` linkage.
 *  Why this format:
 *    - ChatGPT, Perplexity, Bing Copilot and Google AI Overviews
 *      preferentially quote `Article` blocks because they read as
 *      self-contained, attributable paragraphs.
 *    - The `about` property creates an explicit graph edge from the
 *      Article to the underlying Product entity, so the LLM keeps
 *      structured facts (price, brand, platform) tied to the prose
 *      summary it just quoted.
 *    - `audience` + `keywords` give the model an unambiguous signal
 *      about who the page is for, boosting answer-relevance scoring.
 * ----------------------------------------------------------------- */
function product_ai_summary_jsonld(array $p): array
{
    $name     = (string)$p['name'];
    $platform = $p['platform'] ?: 'Windows';
    $brand    = product_detected_brand($p);
    $price    = format_price((float)$p['price']);
    $url      = site_url() . '/product.php?slug=' . urlencode((string)$p['slug']);

    // Two-paragraph editorial summary: paragraph 1 = what + who-for,
    // paragraph 2 = how-it-works + the trust signals.  Total length is
    // tuned for AI Overview snippet eligibility (~600-900 chars).
    $headline = $name . ': lifetime ' . $brand . ' licence for ' . $platform;
    $body  = $name . ' is a one-time purchase, perpetual licence sold by ' . SITE_BRAND . ' for ' . $price . '. ';
    $body .= 'The licence is genuine, activates directly inside the official ' . $brand . ' software on ' . $platform . ', and remains valid for the life of the device — there is no monthly subscription and no automatic re-billing. ';
    $body .= 'Ideal for shoppers searching for "buy ' . strtolower($name) . ' lifetime", "' . strtolower($name) . ' product key", "' . strtolower($name) . ' one-time purchase no subscription" or "' . $brand . ' authorised reseller". ';
    $body .= "\n\n";
    $body .= 'After checkout the activation key arrives by email within 15-30 minutes, alongside the official ' . $brand . ' download link and step-by-step activation instructions. ';
    $body .= 'Activation completes in under five minutes; help is available six days a week via live chat, email and phone. ';
    $body .= 'Every order is backed by a 30-day money-back guarantee and protected by encrypted payment processing. ';

    return [
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $headline,
        'description'   => 'Plain-language summary of ' . $name . ' for AI search engines and shoppers comparing genuine ' . $brand . ' licence keys.',
        'articleBody'   => $body,
        'author'        => ['@type' => 'Organization', 'name' => SITE_BRAND, 'url' => site_url() . '/'],
        'publisher'     => ['@type' => 'Organization', 'name' => SITE_BRAND, 'url' => site_url() . '/'],
        'datePublished' => date('Y-m-d'),
        'dateModified'  => date('Y-m-d'),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'inLanguage'    => 'en',
        'about'         => [
            '@type'    => 'Product',
            'name'     => $name,
            'brand'    => ['@type' => 'Brand', 'name' => $brand],
            'category' => (string)($p['category'] ?? 'Software'),
            'offers'   => [
                '@type'         => 'Offer',
                'price'         => (string)(float)$p['price'],
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'url'           => $url,
            ],
        ],
        'audience'      => [
            '@type' => 'Audience',
            'audienceType' => 'Home users, small-business owners and IT teams looking for a one-time-purchase ' . $platform . ' licence',
        ],
        'keywords'      => product_long_tail_keywords($p),
    ];
}


/* ------------------------------------------------------------------
 *  product_review_items_jsonld()
 *  Returns up to N Review schema items pulled from the
 *  customer_reviews table.  Embeds them inside the Product schema so
 *  Google + AI search engines surface actual verified review text.
 * ----------------------------------------------------------------- */
function product_review_items_jsonld(array $p, int $limit = 5): array
{
    $out = [];
    try {
        // Reviews aren't strictly tied to a slug in the current schema, so
        // we pull the highest-rated recent reviews as social proof.  This
        // mirrors what shoppers see on the public storefront.
        $stmt = db()->prepare("SELECT customer_name AS reviewer_name, rating, comment, submitted_at
                                 FROM customer_reviews
                                WHERE status = 'published'
                                  AND comment IS NOT NULL AND comment <> ''
                             ORDER BY rating DESC, submitted_at DESC
                                LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                '@type'        => 'Review',
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string)(int)$r['rating'],
                    'bestRating'  => '5',
                ],
                'author'       => ['@type' => 'Person', 'name' => (string)($r['reviewer_name'] ?? 'Verified Buyer')],
                'datePublished'=> !empty($r['submitted_at']) ? date('Y-m-d', strtotime($r['submitted_at'])) : date('Y-m-d'),
                'reviewBody'   => (string)$r['comment'],
            ];
        }
    } catch (Throwable $e) { error_log('[seo-content.product_review_items_jsonld] ' . $e->getMessage()); }
    return $out;
}

/* ------------------------------------------------------------------
 *  product_review_snippets()
 *  Same query as the JSON-LD helper but returns plain rows so the
 *  page can render them visibly to humans (also indexable text).
 * ----------------------------------------------------------------- */
function product_review_snippets(int $limit = 3): array
{
    try {
        $stmt = db()->prepare("SELECT customer_name AS reviewer_name, rating, comment, submitted_at
                                 FROM customer_reviews
                                WHERE status = 'published'
                                  AND comment IS NOT NULL AND comment <> ''
                             ORDER BY rating DESC, submitted_at DESC
                                LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) { error_log('[seo-content.product_review_snippets] ' . $e->getMessage()); return []; }
}

/* ------------------------------------------------------------------
 *  product_related_articles()
 *  Returns up to N blog posts linked to this product (deep-link
 *  cluster).  Falls back to recent posts if there are none tagged.
 * ----------------------------------------------------------------- */
function product_related_articles(array $p, int $limit = 3): array
{
    $out = [];
    try {
        $stmt = db()->prepare("SELECT id, title, image, date, read_time FROM blog_posts
                                WHERE product_id = ?
                             ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
                                LIMIT ?");
        $stmt->bindValue(1, (int)$p['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = $stmt->fetchAll();
    } catch (Throwable $e) { error_log('[seo-content.product_related_articles primary] ' . $e->getMessage()); }
    if (!$out) {
        try {
            // Fallback — recent posts so the cluster section is never empty
            $stmt = db()->prepare("SELECT id, title, image, date, read_time FROM blog_posts
                                ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $out = $stmt->fetchAll();
        } catch (Throwable $e) { error_log('[seo-content.product_related_articles fallback] ' . $e->getMessage()); }
    }
    return $out;
}

/* ------------------------------------------------------------------
 *  product_sibling_category()
 *  Returns the slug + title of the "sister" category (e.g. Mac if
 *  this is PC) so we can deep-link a cross-platform variant.
 * ----------------------------------------------------------------- */
function product_sibling_category(array $p): ?array
{
    $cat = (string)($p['category'] ?? '');
    if ($cat === '') return null;
    $sister = null;
    if (str_ends_with($cat, '-pc'))  $sister = substr($cat, 0, -3) . '-mac';
    elseif (str_ends_with($cat, '-mac')) $sister = substr($cat, 0, -4) . '-pc';
    if (!$sister) return null;
    return ['slug' => $sister, 'title' => category_title($sister)];
}

/* ------------------------------------------------------------------
 *  category_intro_seo()
 *  Hero intro copy for a category landing page.
 * ----------------------------------------------------------------- */
function category_intro_seo(string $slug, string $title): string
{
    $year = date('Y');
    $isAntivirus = (strpos($slug, 'bitdefender') !== false || strpos($slug, 'mcafee') !== false || $slug === 'antivirus');
    $isOffice    = (strpos($slug, 'office') !== false || $slug === 'apps' || strpos($slug, 'microsoft-') === 0);
    $isWindows   = (strpos($slug, 'windows') !== false);

    if ($isAntivirus) {
        return 'Compare and buy genuine ' . esc($title) . ' licences below &mdash; full antivirus, anti-malware and ransomware protection with the lowest verified prices online, instant email delivery and 30-day money-back peace of mind. Every key is sourced directly from authorised channels and activates inside the official ' . esc($title) . ' installer.';
    }
    if ($isOffice) {
        return 'Shop ' . esc($title) . ' &mdash; a one-time purchase that gives you a lifetime licence for Word, Excel, PowerPoint and the rest of the Office apps. No monthly Microsoft 365 fees, no renewals, no surprise charges. Pay once, install on your device, and use it for as long as you own the computer. Backed by our 30-day money-back guarantee.';
    }
    if ($isWindows) {
        return 'Activate your PC with a genuine ' . esc($title) . ' product key in minutes. Buy the perpetual licence below and pay once &mdash; never a subscription. Instant email delivery, free upgrade-style updates within the version and round-the-clock activation support.';
    }
    return 'Explore the full range of ' . esc($title) . ' below. Every licence is a perpetual one-time purchase with instant email delivery, free activation support and a 30-day money-back guarantee. Save up to 81% versus retail pricing in ' . $year . '.';
}

/* ------------------------------------------------------------------
 *  category_long_tail_keywords()
 *  Keyword-dense meta-keywords string for a category page.
 * ----------------------------------------------------------------- */
function category_long_tail_keywords(string $title, string $platform = ''): string
{
    $year = date('Y');
    $kw = [
        $title,
        'buy ' . $title,
        $title . ' license key',
        $title . ' product key',
        $title . ' activation key',
        $title . ' lifetime license',
        $title . ' one time purchase',
        $title . ' download',
        $title . ' digital download',
        $title . ' instant delivery',
        $title . ' no subscription',
        $title . ' best price',
        $title . ' discount',
        $title . ' ' . $year,
        $title . ' for sale',
        $title . ' authorized reseller',
        'cheap ' . $title . ' license',
        'how to activate ' . $title,
    ];
    if ($platform === 'Windows' || $platform === 'Mac') {
        $kw[] = $title . ' for ' . $platform;
        $kw[] = $title . ' for ' . $platform . ' download';
        $kw[] = $title . ' ' . $platform . ' license key';
    }
    return implode(', ', array_values(array_unique(array_filter($kw))));
}

/* ------------------------------------------------------------------
 *  category_faqs()
 *  Returns 5 category-specific FAQ pairs that appear visibly on the
 *  page AND are emitted as FAQPage JSON-LD.
 * ----------------------------------------------------------------- */
function category_faqs(string $slug, string $title): array
{
    $brand = (strpos($slug, 'bitdefender') !== false) ? 'Bitdefender'
           : ((strpos($slug, 'mcafee') !== false) ? 'McAfee'
           : ((strpos($slug, 'office') !== false || strpos($slug, 'microsoft') === 0 || strpos($slug, 'windows') !== false || $slug === 'apps') ? 'Microsoft' : 'the vendor'));
    return [
        [
            'question' => 'Are the ' . $title . ' license keys genuine?',
            'answer'   => 'Yes. Every ' . $title . ' licence key sold by ' . SITE_BRAND . ' is genuine and sourced through authorised channels. The key activates directly inside the official ' . $brand . ' software downloaded from the manufacturer&rsquo;s website. We never sell cracked, repackaged or modified installers, and every key is verified before dispatch.',
        ],
        [
            'question' => 'How quickly will I receive my ' . $title . ' license key?',
            'answer'   => 'Your ' . $title . ' licence key is delivered by email within 15-30 minutes of completing payment &mdash; often in seconds. The email contains the activation key, the official ' . $brand . ' download link and step-by-step activation instructions. There is no physical shipping; everything is digital.',
        ],
        [
            'question' => 'Is ' . $title . ' a one-time purchase or a subscription?',
            'answer'   => 'Every ' . $title . ' listing on this page is a one-time purchase with a perpetual (lifetime) licence. Pay once, activate on your device, and use the software for as long as you own the computer. There are no monthly fees, no renewals and no automatic re-billing.',
        ],
        [
            'question' => 'What if my ' . $title . ' key does not activate?',
            'answer'   => 'In the rare case a ' . $title . ' key fails to activate, contact our support team within 30 days. We will either send a working replacement key at no extra cost or issue a full refund &mdash; your choice. Most activation issues are resolved by our specialists in under 10 minutes.',
        ],
        [
            'question' => 'Do you offer volume discounts on ' . $title . '?',
            'answer'   => 'Yes. Buying 5 or more ' . $title . ' licences automatically applies our volume discount at checkout. For larger orders (10+) or for a consolidated invoice, contact us for a custom quote &mdash; we deliver hundreds of licences daily to schools, agencies and IT teams.',
        ],
    ];
}

/* ------------------------------------------------------------------
 *  category_buying_guide_html()
 *  Long-form on-page SEO copy block (H2/H3 hierarchy + intent-matched
 *  long-tail phrases).  Rendered visibly so it is indexable.
 * ----------------------------------------------------------------- */
function category_buying_guide_html(string $slug, string $title, int $productCount): string
{
    $year = date('Y');
    $isOffice = (strpos($slug, 'office') !== false || strpos($slug, 'microsoft-') === 0 || $slug === 'apps');
    $isWin    = (strpos($slug, 'windows') !== false);
    $isAv     = (strpos($slug, 'bitdefender') !== false || strpos($slug, 'mcafee') !== false || $slug === 'antivirus');

    $h = '<section class="cat-seo-copy mt-5" data-testid="category-seo-copy" aria-labelledby="cat-guide-heading">';
    $h .= '<h2 id="cat-guide-heading" class="fw-bold h4 mb-3">' . esc($title) . ' buying guide</h2>';
    $h .= '<p class="text-secondary">All ' . (int)$productCount . ' ' . esc($title) . ' listings above are <strong>genuine, perpetual licences</strong> delivered as a digital key by email. ';
    $h .= 'There are no monthly fees, no subscriptions and no rented &ldquo;cloud account&rdquo; that disappears if you stop paying. ';
    $h .= 'Pay once at the price you see, activate on your device, and the licence is yours for life. ';
    $h .= 'Below is a quick guide to picking the right edition, activating in minutes and getting help if you need it.</p>';

    if ($isOffice) {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Which ' . esc($title) . ' edition should I buy?</h3>';
        $h .= '<p class="text-secondary">If you mainly write documents and crunch spreadsheets, the <strong>Home and Student</strong> edition (Word, Excel and PowerPoint) is the best value. ';
        $h .= 'If you also send a lot of email and run a small business, choose <strong>Home and Business</strong> &mdash; it adds Outlook. ';
        $h .= 'Power users who need Publisher and Access should go for <strong>Professional Plus</strong>. ';
        $h .= 'Every edition is a single one-time payment &mdash; no Microsoft 365 monthly fees, no renewals.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Mac or Windows?</h3>';
        $h .= '<p class="text-secondary">Each ' . esc($title) . ' listing is tagged with its operating system. Make sure you pick the <strong>Mac</strong> edition for macOS computers and the <strong>Windows / PC</strong> edition for laptops and desktops. ';
        $h .= 'If you accidentally buy the wrong one, we will exchange it free of charge within 30 days.</p>';
    } elseif ($isWin) {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Home, Pro or Education &mdash; which ' . esc($title) . ' edition?</h3>';
        $h .= '<p class="text-secondary">For home computers and personal laptops, the <strong>Home</strong> edition covers everyday use, gaming and family productivity. ';
        $h .= 'If you work from home, run virtual machines or need BitLocker drive encryption and Remote Desktop, choose <strong>Pro</strong>. ';
        $h .= 'Students and teachers can save more with the <strong>Education</strong> edition where listed.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Will ' . esc($title) . ' work on my PC?</h3>';
        $h .= '<p class="text-secondary">Microsoft publishes a hardware compatibility checker called <em>PC Health Check</em> &mdash; run it before buying if you are upgrading from an older Windows version. ';
        $h .= 'If your PC meets the minimum specifications (1 GHz CPU, 4 GB RAM, 64 GB storage and TPM 2.0 for Windows 11), this licence will activate without issue.</p>';
    } elseif ($isAv) {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How many devices do I need to cover?</h3>';
        $h .= '<p class="text-secondary">Each ' . esc($title) . ' listing shows how many devices the licence covers (1, 3, 5 or 10) and how long the protection lasts (1 or 2 years). ';
        $h .= 'For a single laptop, a 1-device subscription is perfect; for a whole family with phones and tablets, a 5- or 10-device plan is the best value per device.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Real-time protection vs full security suite</h3>';
        $h .= '<p class="text-secondary">If you just need malware and ransomware protection, the standard ' . esc($title) . ' antivirus is enough. ';
        $h .= 'Looking for a built-in VPN, password manager, parental controls and webcam protection? Pick a Total Security or Premium-tier edition where shown.</p>';
    } else {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How to pick the right ' . esc($title) . '</h3>';
        $h .= '<p class="text-secondary">Compare the editions above by platform (Windows or Mac), included apps, and number of devices. ';
        $h .= 'Filter using the <em>Platform</em> selector at the top of the page to narrow the list. Need help deciding? Use the <a href="contact.php">Request a Quote</a> link &mdash; our team replies within an hour.</p>';
    }

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Delivery, activation &amp; support &mdash; what to expect</h3>';
    $h .= '<p class="text-secondary"><strong>Delivery:</strong> ' . esc($title) . ' licence keys arrive by email within 15-30 minutes of completing payment, with the activation key, the official download link and step-by-step activation instructions.<br>';
    $h .= '<strong>Activation:</strong> The keys are activated directly inside the official ' . esc($title) . ' software &mdash; never through third-party loaders, cracks or modified installers.<br>';
    $h .= '<strong>Support:</strong> Live chat, email and phone support is available six days a week. Our specialists handle activation, transfers, downgrades and replacement keys at no extra charge.</p>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Lowest verified prices on ' . esc($title) . ' in ' . $year . '</h3>';
    $h .= '<p class="text-secondary mb-0">' . esc(SITE_BRAND) . ' works directly with authorised distributors, which is how we offer ' . esc($title) . ' at <strong>up to 81% below retail</strong>. ';
    $h .= 'Every licence is paid for upfront, fully transferable and protected by our 30-day money-back guarantee. ';
    $h .= 'If you find ' . esc($title) . ' cheaper at another verified reseller, we will match the price &mdash; just send us the link.</p>';
    $h .= '</section>';
    return $h;
}

/* ------------------------------------------------------------------
 *  category_itemlist_jsonld()
 *  ItemList schema for a category page.  Strong category-page signal
 *  for Google &mdash; tells the crawler "here are the products on this
 *  page" so they can be indexed individually.
 * ----------------------------------------------------------------- */
function category_itemlist_jsonld(array $products, string $title): array
{
    $items = [];
    $pos = 1;
    $siteUrl = site_url();
    foreach ($products as $p) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'url'      => $siteUrl . '/product.php?slug=' . urlencode((string)$p['slug']),
            'name'     => (string)$p['name'],
        ];
    }
    return [
        '@context'       => 'https://schema.org',
        '@type'          => 'ItemList',
        'name'           => $title,
        'numberOfItems'  => count($items),
        'itemListElement'=> $items,
    ];
}

/* ------------------------------------------------------------------
 *  category_breadcrumb_jsonld()
 *  BreadcrumbList schema for a category page.
 * ----------------------------------------------------------------- */
function category_breadcrumb_jsonld(string $slug, string $title): array
{
    $siteUrl = site_url();
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => $siteUrl . '/shop.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $title],
        ],
    ];
}

/* ------------------------------------------------------------------
 *  faq_to_jsonld()
 *  Convert an array of [{question,answer},...] to a FAQPage schema.
 *  Adds `speakable` so AI / voice assistants know which parts can be
 *  spoken aloud (improves AEO answer rate).
 * ----------------------------------------------------------------- */
function faq_to_jsonld(array $faqs): array
{
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'speakable'  => [
            '@type'    => 'SpeakableSpecification',
            'cssSelector' => ['.pd-faq-accordion', '.cat-faq', '.pd-seo-copy'],
        ],
        'mainEntity' => array_map(function($f) {
            return [
                '@type'          => 'Question',
                'name'           => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
            ];
        }, $faqs),
    ];
}

/* ------------------------------------------------------------------
 *  related_category_links()
 *  Returns a list of related category slugs + descriptive anchor text
 *  for the internal-linking cluster section.  Drives Google's PageRank
 *  graph by linking out with mid-tail keyword anchor text.
 * ----------------------------------------------------------------- */
function related_category_links(string $currentSlug): array
{
    $isOffice = (strpos($currentSlug, 'office') !== false || strpos($currentSlug, 'microsoft-') === 0 || $currentSlug === 'apps');
    $isWin    = (strpos($currentSlug, 'windows') !== false);
    $isAv     = (strpos($currentSlug, 'bitdefender') !== false || strpos($currentSlug, 'mcafee') !== false || $currentSlug === 'antivirus');

    $all = [
        ['slug' => 'office-pc',       'anchor' => 'Microsoft Office for PC &mdash; lifetime licence keys'],
        ['slug' => 'office-mac',      'anchor' => 'Microsoft Office for Mac &mdash; perpetual licence'],
        ['slug' => 'office-2024-pc',  'anchor' => 'Buy Microsoft Office 2024 for Windows'],
        ['slug' => 'office-2021-pc',  'anchor' => 'Microsoft Office 2021 product key &mdash; one-time purchase'],
        ['slug' => 'office-2019-pc',  'anchor' => 'Office 2019 licence key for Windows'],
        ['slug' => 'windows-11',      'anchor' => 'Windows 11 Pro genuine product key'],
        ['slug' => 'windows-10',      'anchor' => 'Windows 10 Pro &amp; Home activation key'],
        ['slug' => 'microsoft-project','anchor' => 'Microsoft Project 2024 / 2021 licence keys'],
        ['slug' => 'microsoft-visio', 'anchor' => 'Microsoft Visio 2024 / 2021 licence keys'],
        ['slug' => 'bitdefender',     'anchor' => 'Bitdefender Antivirus &amp; Total Security'],
        ['slug' => 'mcafee',          'anchor' => 'McAfee Total Protection multi-device plans'],
        ['slug' => 'antivirus',       'anchor' => 'Antivirus &amp; internet security software'],
    ];

    // Filter out the current category itself and re-rank by relevance.
    $filtered = array_values(array_filter($all, fn($x) => $x['slug'] !== $currentSlug));

    usort($filtered, function($a, $b) use ($isOffice, $isWin, $isAv) {
        $score = function($s) use ($isOffice, $isWin, $isAv) {
            $isAvLink = (strpos($s, 'bitdefender') !== false || strpos($s, 'mcafee') !== false || $s === 'antivirus');
            $isOfficeLink = (strpos($s, 'office') !== false || $s === 'apps' || strpos($s, 'microsoft-') === 0);
            $isWinLink = (strpos($s, 'windows') !== false);
            if ($isOffice && $isOfficeLink) return 0;
            if ($isWin && $isWinLink)       return 0;
            if ($isAv && $isAvLink)         return 0;
            return 1;
        };
        return $score($a['slug']) <=> $score($b['slug']);
    });

    return array_slice($filtered, 0, 8);
}

/* ------------------------------------------------------------------
 *  popular_search_terms()
 *  Mid-tail / long-tail keyword anchors linking to /shop.php?q=…
 *  Powers the "Popular searches" deep-link cluster used at the
 *  bottom of category and product pages.
 * ----------------------------------------------------------------- */
function popular_search_terms(string $context = ''): array
{
    $generic = [
        'Microsoft Office 2024 lifetime key',
        'Office 2021 Home and Business for Mac',
        'Windows 11 Pro product key',
        'Windows 10 Home activation key',
        'Bitdefender Total Security 5 devices',
        'McAfee Total Protection 3 device',
        'Microsoft Project 2024 licence',
        'Microsoft Visio 2021 product key',
        'Office for Mac one time purchase',
        'cheap Microsoft Office key online',
    ];
    return $generic;
}
