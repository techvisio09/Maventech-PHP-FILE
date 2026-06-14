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

/* =====================================================================
 *  AEO HELPERS — Answer Engine Optimization
 *  ---------------------------------------------------------------------
 *  Helpers that emit the page elements Google AI Overviews, Bing Chat,
 *  ChatGPT, Perplexity and voice assistants reward most: a 40-60 word
 *  direct answer at the top of the page; a "People Also Ask"-style
 *  related-questions block with answers visible as plain text; and
 *  visible breadcrumb trails that complement the BreadcrumbList JSON-LD.
 * =================================================================== */

/**
 * Render an AEO "Quick Answer" callout — a 40-60 word direct answer
 * styled as a sky-blue card.  This is the FIRST thing a featured-snippet
 * crawler grabs.  Pair with the page's H1 for maximum relevance.
 *
 * @param string $question  The short question this card answers.
 * @param string $answer    The 40-60 word answer (plain text or trusted HTML).
 * @param string $testid    Optional data-testid suffix.
 */
function render_aeo_answer(string $question, string $answer, string $testid = 'quick-answer'): string
{
    $q = esc(trim($question));
    return '<aside class="aeo-quick-answer" data-testid="' . esc($testid) . '" aria-labelledby="aeo-q-' . esc($testid) . '" '
         . 'style="border-left:4px solid #2563eb;background:linear-gradient(180deg,rgba(59,130,246,.08),rgba(59,130,246,.02));'
         . 'border-radius:10px;padding:14px 18px;margin:0 0 1.25rem;">'
         . '<div class="d-flex align-items-center gap-2 mb-2">'
         . '<i class="bi bi-lightning-charge-fill" style="color:#2563eb;font-size:18px;"></i>'
         . '<strong id="aeo-q-' . esc($testid) . '" class="small text-uppercase" style="letter-spacing:.5px;color:#1e40af;">Quick answer</strong>'
         . '</div>'
         . '<div class="fw-bold mb-1" style="font-size:15px;">' . $q . '</div>'
         . '<div class="aeo-answer-body" data-testid="' . esc($testid) . '-body" style="font-size:14px;line-height:1.55;color:#1e293b;">' . $answer . '</div>'
         . '</aside>';
}

/**
 * Render a visible "People Also Ask" / related-questions block.
 * Each Q→A pair becomes an accordion entry visually AND serializes
 * to a separate FAQPage JSON-LD via faq_to_jsonld() in the caller.
 *
 * @param array  $faqs    [{question, answer}, ...]
 * @param string $heading Heading text.
 * @param string $testid  Optional data-testid suffix.
 */
function render_paa_block(array $faqs, string $heading = 'People also ask', string $testid = 'paa-block'): string
{
    if (!$faqs) return '';
    $h  = '<section class="paa-block mt-5" aria-labelledby="paa-heading-' . esc($testid) . '" data-testid="' . esc($testid) . '">';
    $h .= '<div class="d-flex align-items-center gap-2 mb-3">';
    $h .= '<i class="bi bi-question-diamond-fill" style="font-size:22px;color:#2563eb;"></i>';
    $h .= '<h2 id="paa-heading-' . esc($testid) . '" class="fw-bold h4 mb-0">' . esc($heading) . '</h2>';
    $h .= '</div>';
    $h .= '<div class="accordion pd-faq-accordion" id="' . esc($testid) . '-acc">';
    foreach ($faqs as $idx => $f) {
        $itemId = $testid . '-q-' . $idx;
        $h .= '<div class="accordion-item">';
        $h .= '<h3 class="accordion-header"><button class="accordion-button ' . ($idx > 0 ? 'collapsed' : '') . '" '
            . 'type="button" data-bs-toggle="collapse" data-bs-target="#' . esc($itemId) . '" '
            . 'aria-expanded="' . ($idx === 0 ? 'true' : 'false') . '" aria-controls="' . esc($itemId) . '" '
            . 'data-testid="' . esc($testid) . '-q-' . $idx . '">' . esc($f['question']) . '</button></h3>';
        $h .= '<div id="' . esc($itemId) . '" class="accordion-collapse collapse ' . ($idx === 0 ? 'show' : '') . '" '
            . 'data-bs-parent="#' . esc($testid) . '-acc">';
        $h .= '<div class="accordion-body" data-testid="' . esc($testid) . '-a-' . $idx . '">' . $f['answer'] . '</div>';
        $h .= '</div></div>';
    }
    $h .= '</div></section>';
    return $h;
}

/**
 * Generate up to 6 product-aware "People Also Ask" Q→A pairs.  Plain
 * deterministic templates so the block renders even when the AI bot
 * hasn't filled in custom FAQs yet.  Adopt the same 40-60 word answer
 * pattern Google promotes in AI Overviews.
 */
function product_paa_faqs(array $p): array
{
    $name     = (string)$p['name'];
    $brand    = product_detected_brand($p);
    $platform = $p['platform'] ?: 'Windows';
    $price    = format_price((float)$p['price']);
    return [
        [
            'question' => 'Where is the cheapest place to buy ' . $name . '?',
            'answer'   => esc(SITE_BRAND) . ' sells ' . esc($name) . ' for ' . esc($price) . ' &mdash; up to 81% below the manufacturer&rsquo;s retail price. We work directly with authorised channels, which is how we keep prices low while guaranteeing every key is genuine, activates inside the official ' . esc($brand) . ' installer, and ships with a 30-day money-back protection.',
        ],
        [
            'question' => 'How long does ' . $name . ' delivery take?',
            'answer'   => 'Your ' . esc($name) . ' licence key arrives by email within 15&ndash;30 minutes of completing payment &mdash; often in seconds. The email contains the activation key, the official ' . esc($brand) . ' download link and step-by-step instructions. There is no physical shipping; everything is digital.',
        ],
        [
            'question' => 'Will ' . $name . ' work on my ' . esc($platform) . ' device?',
            'answer'   => 'Yes &mdash; this listing is the ' . esc($platform) . ' edition of ' . esc($name) . '. As long as your computer meets ' . esc($brand) . '&rsquo;s minimum system requirements (any ' . esc($platform) . ' machine from the last ~5 years), this key will activate without issue. We exchange any wrong-platform purchase free of charge within 30 days.',
        ],
        [
            'question' => 'Is ' . $name . ' a subscription or a one-time purchase?',
            'answer'   => 'Every ' . esc($name) . ' listing on ' . esc(SITE_BRAND) . ' is a one-time purchase with a perpetual (lifetime) licence. Pay once, activate on your device, and use the software for as long as you own the computer. There are no monthly fees, no renewals and no automatic re-billing.',
        ],
        [
            'question' => 'What happens if my ' . $name . ' key fails to activate?',
            'answer'   => 'In the rare case a key fails to activate, contact support within 30 days. We will either send a working replacement key at no extra cost or issue a full refund &mdash; your choice. Most activation issues are resolved by our specialists in under 10 minutes via live chat.',
        ],
        [
            'question' => 'Can I install ' . $name . ' on more than one computer?',
            'answer'   => 'Each ' . esc($name) . ' licence covers a single device by default. If you need to move the licence to a new computer (e.g. when upgrading your laptop), our support team transfers it for you free of charge. Multi-device family packs are listed separately on the relevant category page.',
        ],
    ];
}

/**
 * Render a visible breadcrumb <nav> wired to the same crumbs the
 * JSON-LD BreadcrumbList is built from.  Use this so Google AND
 * AI search engines see consistent navigation context.
 *
 * @param array $crumbs  [['name'=>'Home', 'url'=>'/'], ...]  Last item omits url.
 */
function render_breadcrumb_nav(array $crumbs, string $testid = 'breadcrumb'): string
{
    if (!$crumbs) return '';
    $h  = '<nav aria-label="breadcrumb" data-testid="' . esc($testid) . '">';
    $h .= '<ol class="breadcrumb small mb-3">';
    foreach ($crumbs as $i => $c) {
        $isLast = $i === count($crumbs) - 1;
        $name   = esc((string)($c['name'] ?? ''));
        $url    = $c['url']  ?? '';
        if ($isLast || $url === '') {
            $h .= '<li class="breadcrumb-item active" aria-current="page">' . $name . '</li>';
        } else {
            $h .= '<li class="breadcrumb-item"><a href="' . esc((string)$url) . '">' . $name . '</a></li>';
        }
    }
    $h .= '</ol></nav>';
    return $h;
}

/* =====================================================================
 *  TOPIC CLUSTER HUB HELPERS
 *  --------------------------------------------------------------------
 *  Every hub lives in the `topic_hubs` table (auto-seeded on first run
 *  with the three legacy default topics).  These helpers are the single
 *  read-point used by hub.php, sitemap-xml.php, the admin panel and
 *  product.php / category.php "Topic Hub" backlink renders.
 * ===================================================================== */

/** Default hubs that get seeded if `topic_hubs` is empty.  Mirrors the
 *  legacy in-file $TOPICS array so a fresh install ships with content. */
function _topic_hub_default_seeds(): array
{
    $brand = function_exists('site_brand_safe') ? site_brand_safe() : (defined('SITE_BRAND') ? SITE_BRAND : 'our store');
    return [
        [
            'slug'       => 'microsoft-office',
            'title'      => 'Microsoft Office — the complete buying guide',
            'headline'   => 'Microsoft Office is a one-time-purchase office suite (Word, Excel, PowerPoint, Outlook, Publisher, Access) sold by ' . $brand . ' at up to 81% below retail. Every licence is genuine, lifetime, activates inside the official Microsoft installer, delivered by email in 15-30 minutes, and protected by a 30-day money-back guarantee.',
            'audience'   => 'home users, students, freelancers and small-business owners choosing between Office 2024, 2021 and 2019 on Windows or Mac',
            'categories' => ['office-pc','office-mac','office-2024-pc','office-2021-pc','office-2019-pc','office-2024-mac','office-2021-mac','office-2019-mac','apps','microsoft-project','microsoft-visio'],
            'blogTags'   => ['%office%','%word%','%excel%','%powerpoint%','%outlook%','%microsoft 365%','%publisher%'],
            'keywords'   => 'Microsoft Office, Office 2024, Office 2021, Office 2019, Office for Mac, Office for PC, Office lifetime license, Office one time purchase, buy Microsoft Office key, Microsoft Office product key, Office Home and Student, Office Home and Business, Office Professional Plus, Microsoft Project, Microsoft Visio',
            'aboutLink'  => 'category.php?slug=apps',
            'color'      => '#dc2626',
            'videos'     => [],
        ],
        [
            'slug'       => 'windows',
            'title'      => 'Microsoft Windows — Windows 11, 10 and Pro buying guide',
            'headline'   => 'Microsoft Windows is the world\'s most-used desktop operating system. ' . $brand . ' sells genuine Windows 11 and Windows 10 product keys (Home, Pro and Education) at up to 81% off retail. Pay once, activate inside the official Windows setup, and keep the licence for life — instant email delivery and 30-day guarantee.',
            'audience'   => 'self-builders, IT teams and home upgraders looking for a genuine Windows 11 Pro or Windows 10 product key',
            'categories' => ['windows-11','windows-10','windows','os'],
            'blogTags'   => ['%windows 11%','%windows 10%','%windows%'],
            'keywords'   => 'Microsoft Windows, Windows 11 Pro, Windows 11 Home, Windows 10 Pro, Windows 10 Home, Windows product key, buy Windows 11 key, Windows lifetime activation, Windows OEM key, Windows 11 vs 10, upgrade to Windows 11',
            'aboutLink'  => 'category.php?slug=windows-11',
            'color'      => '#0078d4',
            'videos'     => [],
        ],
        [
            'slug'       => 'antivirus',
            'title'      => 'Antivirus software — Bitdefender, McAfee & internet-security buying guide',
            'headline'   => 'Modern antivirus software protects every device in your household from malware, ransomware and identity theft. ' . $brand . ' carries genuine Bitdefender and McAfee licences for 1, 3, 5 and 10 devices at up to 81% off retail. Activates inside the official vendor installer, delivered by email, with our 30-day money-back guarantee.',
            'audience'   => 'home users, families and small businesses choosing between Bitdefender Total Security, McAfee Total Protection and other paid antivirus suites',
            'categories' => ['antivirus','bitdefender','mcafee','internet-security'],
            'blogTags'   => ['%bitdefender%','%mcafee%','%antivirus%','%malware%','%ransomware%','%internet security%'],
            'keywords'   => 'antivirus, Bitdefender Total Security, McAfee Total Protection, internet security, anti-malware, ransomware protection, family antivirus plans, best antivirus 2026, antivirus for Mac, multi-device antivirus',
            'aboutLink'  => 'category.php?slug=antivirus',
            'color'      => '#16a34a',
            'videos'     => [],
        ],
    ];
}

/** Seed default hubs into the DB if the table is empty (idempotent). */
function topic_hubs_seed_defaults(): void
{
    static $seeded = false;
    if ($seeded) return;
    $seeded = true;
    try {
        ensure_db_schema();
        $pdo = db();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM topic_hubs")->fetchColumn();
        if ($count > 0) return;
        $stmt = $pdo->prepare("INSERT IGNORE INTO topic_hubs
            (slug, title, headline, audience, categories_json, blog_tags_json, keywords, about_link, color, videos_json, active, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,1,'seed')");
        foreach (_topic_hub_default_seeds() as $h) {
            $stmt->execute([
                $h['slug'], $h['title'], $h['headline'], $h['audience'],
                json_encode($h['categories']), json_encode($h['blogTags']),
                $h['keywords'], $h['aboutLink'], $h['color'],
                json_encode($h['videos']),
            ]);
        }
    } catch (Throwable $e) {
        @error_log('[topic_hubs_seed_defaults] ' . $e->getMessage());
    }
}

/** Hydrate a `topic_hubs` row into a hub array (legacy $TOPICS shape). */
function _topic_hub_hydrate(array $row): array
{
    $cats   = json_decode((string)($row['categories_json'] ?? '[]'), true);
    $tags   = json_decode((string)($row['blog_tags_json']  ?? '[]'), true);
    $videos = json_decode((string)($row['videos_json']     ?? '[]'), true);
    return [
        'id'         => (int)($row['id'] ?? 0),
        'slug'       => (string)($row['slug'] ?? ''),
        'title'      => (string)($row['title'] ?? ''),
        'headline'   => (string)($row['headline'] ?? ''),
        'audience'   => (string)($row['audience'] ?? ''),
        'categories' => is_array($cats) ? $cats : [],
        'blogTags'   => is_array($tags) ? $tags : [],
        'keywords'   => (string)($row['keywords'] ?? ''),
        'aboutLink'  => (string)($row['about_link'] ?? ''),
        'color'      => (string)($row['color'] ?? '#0078d4'),
        'videos'     => is_array($videos) ? $videos : [],
        'active'     => (int)($row['active'] ?? 1),
        'source'     => (string)($row['source'] ?? 'manual'),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

/** Returns every hub (default: active only) keyed by slug. */
function topic_hubs_all(bool $activeOnly = true): array
{
    topic_hubs_seed_defaults();
    try {
        $sql = "SELECT * FROM topic_hubs" . ($activeOnly ? " WHERE active=1" : "") . " ORDER BY id ASC";
        $rows = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) { $h = _topic_hub_hydrate($r); $out[$h['slug']] = $h; }
    return $out;
}

/** Fetch one hub by slug, or null if missing / inactive. */
function topic_hub_by_slug(string $slug, bool $activeOnly = true): ?array
{
    topic_hubs_seed_defaults();
    try {
        $sql = "SELECT * FROM topic_hubs WHERE slug = ?" . ($activeOnly ? " AND active=1" : "") . " LIMIT 1";
        $st  = db()->prepare($sql);
        $st->execute([$slug]);
        $r = $st->fetch();
    } catch (Throwable $e) { return null; }
    return $r ? _topic_hub_hydrate($r) : null;
}

/** Find every hub that contains a given category slug — used by category.php
 *  and product.php to render a "Part of topic hub" backlink. */
function topic_hubs_for_category(string $categorySlug): array
{
    $hubs = topic_hubs_all(true);
    $cat  = strtolower(trim($categorySlug));
    if ($cat === '') return [];
    $out = [];
    foreach ($hubs as $h) {
        foreach ($h['categories'] as $c) {
            if (strtolower((string)$c) === $cat) { $out[] = $h; break; }
        }
    }
    return $out;
}

/** Convenience wrapper for products. */
function topic_hubs_for_product(array $p): array
{
    return topic_hubs_for_category((string)($p['category'] ?? ''));
}

/** Auto-generate hubs from top product categories.  Picks every category
 *  that has >= $minProducts active products and isn't already covered by
 *  an existing hub, then inserts a new auto-source hub for each.
 *  Returns the list of new slugs created. */
function topic_hubs_auto_generate(int $minProducts = 4): array
{
    topic_hubs_seed_defaults();
    $pdo  = db();
    $hubs = topic_hubs_all(false);
    $covered = [];
    foreach ($hubs as $h) {
        foreach ($h['categories'] as $c) $covered[strtolower((string)$c)] = 1;
    }
    $rows = $pdo->query(
        "SELECT LOWER(category) AS cat, COUNT(*) AS n
           FROM products
          WHERE is_active=1 AND category IS NOT NULL AND category <> ''
          GROUP BY LOWER(category)
         HAVING n >= " . (int)$minProducts . "
          ORDER BY n DESC"
    )->fetchAll();

    $created = [];
    $insert = $pdo->prepare("INSERT IGNORE INTO topic_hubs
        (slug, title, headline, audience, categories_json, blog_tags_json, keywords, about_link, color, videos_json, active, source)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,'auto')");
    $palette = ['#0078d4','#16a34a','#dc2626','#9333ea','#0ea5e9','#f59e0b','#ec4899','#22c55e','#6366f1','#14b8a6'];
    $brand   = function_exists('site_brand_safe') ? site_brand_safe() : (defined('SITE_BRAND') ? SITE_BRAND : 'our store');

    foreach ($rows as $r) {
        $slugCat = (string)$r['cat'];
        if ($slugCat === '' || isset($covered[$slugCat])) continue;
        $title    = function_exists('category_title') ? category_title($slugCat) : ucwords(str_replace('-', ' ', $slugCat));
        if ($title === '' || strtolower($title) === strtolower($slugCat)) {
            $title = ucwords(str_replace('-', ' ', $slugCat));
        }
        $hubTitle = $title . ' — buying guide & best picks';
        $headline = $title . ' products are available at ' . $brand . ' with genuine licences, lifetime activation and instant email delivery. Compare the most popular ' . $title . ' titles, read editorial guides, and get answers to common buyer questions on one page.';
        $audience = 'shoppers comparing ' . $title . ' options before they buy';
        $keywords = $title . ', buy ' . $title . ', ' . $title . ' license key, best ' . $title . ' deals, ' . $title . ' product key, ' . $title . ' lifetime activation';
        $tags     = ['%' . strtolower($title) . '%'];
        $color    = $palette[count($created) % count($palette)];
        try {
            $insert->execute([
                $slugCat, $hubTitle, $headline, $audience,
                json_encode([$slugCat]), json_encode($tags),
                $keywords, 'category.php?slug=' . $slugCat, $color, json_encode([]),
            ]);
            if ($pdo->lastInsertId()) {
                $created[] = $slugCat;
                $covered[$slugCat] = 1;
            }
        } catch (Throwable $e) {
            @error_log('[topic_hubs_auto_generate] ' . $e->getMessage());
        }
    }
    return $created;
}

/** VideoObject JSON-LD array for a single YouTube URL (best-effort). */
function topic_hub_video_jsonld(array $video, array $hub): ?array
{
    $url = trim((string)($video['url'] ?? ''));
    if ($url === '') return null;
    $vid = '';
    if (preg_match('#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_\-]{11})#', $url, $m)) $vid = $m[1];
    $thumb = $vid !== '' ? 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg' : '';
    return [
        '@context'     => 'https://schema.org',
        '@type'        => 'VideoObject',
        'name'         => (string)($video['title'] ?? ('Video — ' . strip_tags((string)$hub['title']))),
        'description'  => (string)($video['title'] ?? strip_tags((string)$hub['headline'])),
        'thumbnailUrl' => $thumb,
        'uploadDate'   => $hub['updated_at'] ?? date('c'),
        'contentUrl'   => $url,
        'embedUrl'     => $vid !== '' ? 'https://www.youtube.com/embed/' . $vid : $url,
    ];
}

/** Helper safe-brand reader (works pre-bootstrap). */
function site_brand_safe(): string
{
    return defined('SITE_BRAND') ? SITE_BRAND : 'our store';
}

/* =====================================================================
 *  GSC QUERY CLUSTERING (SEO Discovery Lab)
 *  --------------------------------------------------------------------
 *  Admins upload the "Performance Report" CSV from Search Console.  We
 *  tokenise every query, fold equivalent terms (singular/plural, stop
 *  words) and group queries that share their two top tokens — the
 *  resulting clusters become "create new hub" suggestions ranked by
 *  total impressions.
 * ===================================================================== */

/** Tokenise a query into significant lowercase words. */
function gsc_tokenise(string $q): array
{
    $stop = ['the','a','an','for','to','of','and','or','with','is','are','vs','in','my','on','at','by','from','this','best','top'];
    $q = strtolower(trim($q));
    $q = preg_replace('/[^a-z0-9\s\-]+/u', ' ', $q) ?? '';
    $parts = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $p) {
        if (strlen($p) < 3 || in_array($p, $stop, true)) continue;
        // crude singularisation: strip trailing 's' for length-5+ words
        if (strlen($p) >= 5 && substr($p, -1) === 's' && substr($p, -2) !== 'ss') $p = substr($p, 0, -1);
        $out[] = $p;
    }
    return $out;
}

/** Compute a cluster key (two most-relevant tokens) for a query. */
function gsc_cluster_key(string $q): string
{
    $t = gsc_tokenise($q);
    if (count($t) === 0) return 'other';
    sort($t);                  // stable order
    return implode('-', array_slice(array_values(array_unique($t)), 0, 2));
}

/** Parse a Search Console Performance CSV and persist queries.  Returns
 *  ['inserted' => N, 'skipped' => N, 'errors' => []]. */
function gsc_import_csv(string $csvText): array
{
    $report = ['inserted' => 0, 'skipped' => 0, 'errors' => []];
    $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText) ?? $csvText; // strip BOM
    $lines = preg_split('/\r\n|\n|\r/', trim($csvText));
    if (!$lines || count($lines) < 2) {
        $report['errors'][] = 'CSV is empty or missing rows.';
        return $report;
    }
    $headerRaw = array_shift($lines);
    $header    = array_map(static fn($s) => strtolower(trim($s)), str_getcsv((string)$headerRaw));
    $find = static fn(array $opts) => (function() use ($header, $opts) {
        foreach ($opts as $opt) { $i = array_search($opt, $header, true); if ($i !== false) return $i; }
        return -1;
    })();
    $idxQ = $find(['query','top queries','search query']);
    $idxI = $find(['impressions','total impressions','impr.']);
    $idxC = $find(['clicks','total clicks']);
    $idxP = $find(['position','avg. position','average position']);
    $idxR = $find(['ctr','avg. ctr']);
    if ($idxQ < 0) { $report['errors'][] = 'No "Query" column found.'; return $report; }

    $pdo = db();
    $upsert = $pdo->prepare(
        "INSERT INTO gsc_queries (query, impressions, clicks, ctr, position, cluster_key, uploaded_at)
         VALUES (?,?,?,?,?,?, NOW())
         ON DUPLICATE KEY UPDATE
           impressions = VALUES(impressions),
           clicks      = VALUES(clicks),
           ctr         = VALUES(ctr),
           position    = VALUES(position),
           cluster_key = VALUES(cluster_key),
           uploaded_at = NOW()"
    );
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $q   = trim((string)($row[$idxQ] ?? ''));
        if ($q === '' || mb_strlen($q) > 250) { $report['skipped']++; continue; }
        $impr = $idxI >= 0 ? (int)str_replace([',', ' '], '', (string)($row[$idxI] ?? 0)) : 0;
        $clk  = $idxC >= 0 ? (int)str_replace([',', ' '], '', (string)($row[$idxC] ?? 0)) : 0;
        $pos  = $idxP >= 0 ? (float)str_replace(',', '.', (string)($row[$idxP] ?? 0)) : 0.0;
        $ctrRaw = $idxR >= 0 ? (string)($row[$idxR] ?? '0') : '0';
        $ctr  = (float)str_replace(['%', ',', ' '], ['', '.', ''], $ctrRaw);
        try {
            $upsert->execute([$q, $impr, $clk, $ctr, $pos, gsc_cluster_key($q)]);
            $report['inserted']++;
        } catch (Throwable $e) {
            $report['skipped']++;
            if (count($report['errors']) < 5) $report['errors'][] = $e->getMessage();
        }
    }
    return $report;
}

/** Return up to $limit query clusters ranked by total impressions.  Each
 *  cluster carries sample queries + a suggested hub slug/title. */
function gsc_top_clusters(int $limit = 15): array
{
    try {
        $rows = db()->query(
            "SELECT cluster_key,
                    COUNT(*)              AS query_count,
                    SUM(impressions)      AS impressions,
                    SUM(clicks)           AS clicks,
                    GROUP_CONCAT(query ORDER BY impressions DESC SEPARATOR '|') AS sample
               FROM gsc_queries
              WHERE cluster_key <> ''
              GROUP BY cluster_key
              ORDER BY impressions DESC
              LIMIT " . (int)$limit
        )->fetchAll();
    } catch (Throwable $e) { return []; }
    $hubs = topic_hubs_all(false);
    $existingSlugs = array_keys($hubs);
    $out = [];
    foreach ($rows as $r) {
        $samples = array_slice(array_filter(explode('|', (string)$r['sample'])), 0, 6);
        $hubSlug = preg_replace('/[^a-z0-9\-]/', '', (string)$r['cluster_key']) ?: 'topic';
        $exists  = in_array($hubSlug, $existingSlugs, true);
        $out[] = [
            'cluster_key'   => (string)$r['cluster_key'],
            'query_count'   => (int)$r['query_count'],
            'impressions'   => (int)$r['impressions'],
            'clicks'        => (int)$r['clicks'],
            'samples'       => array_values($samples),
            'suggested_slug'  => $hubSlug,
            'suggested_title' => ucwords(str_replace('-', ' ', (string)$r['cluster_key'])) . ' — buying guide & top picks',
            'already_exists'  => $exists,
        ];
    }
    return $out;
}
