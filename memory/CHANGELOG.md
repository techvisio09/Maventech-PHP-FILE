# Changelog
## 2026-02-15 — PayPal outline highlight + product elegance + mega-menu shrink v3

### New asks delivered (3 changes)
1. **Product boxes more elegant** — 1.5px outer outline (rgba(15,23,42,.14)), 14px border-radius, refined inner image-wrap with light gradient, hover lifts border to `#0f172a` solid + translateY(-3px) + 12px shadow.

2. **PayPal-style outlined boxes & buttons** (every clickable element):
   - `.btn-hero-cta` (Shop Now) → solid dark `#0f172a` pill with 1.5px border, white text — exactly like PayPal "Pay with PayPal"
   - `.btn-hero-ghost` (Compare Editions) → white pill, 1.5px dark border, dark text
   - `.btn-primary`, `.btn-outline-primary`, `.btn-outline-secondary` → all rebuilt as dark pill outlines
   - `.card`, `.product-card`, `.spotlight-card`, `.side-product-row`, `[data-testid="welcome-back-strip"]`, trust-badges, how-it-works, why-choose, accordion-items → all get the 1.5px dark outline + refined hover
   - `.cat-chip` → pill outline, dark on hover
   - Dark-mode mirror for all of the above

3. **Mega-menu shrunk further (v3)**:
   - Microsoft Products: padding `1rem 1.1rem` → `.7rem .85rem`, mega-heading `.65rem` → `.6rem`, mega-year `.82rem` → `.78rem`, mega-link `.76rem` → `.72rem`
   - Antivirus: min-width `250px` → `220px`, padding `.85rem` → `.65rem`, border-radius `.75rem` → `.65rem`
   - Volume Pricing promo: even tighter — fonts, button padding, icon size reduced

Verified visually: hero, both mega-menus, best-sellers spotlight, picked-for-you grid, trust badges, how-it-works, why-choose, FAQ — every box has refined defined outlines, buttons feel like PayPal pills, mega-menus are significantly smaller.

---

## 2026-02-15 — PayPal-style typography (heavy headings + clean body) — v3

### Big swing: bold display headings + light body
User shared PayPal "Check out your way" reference. Applied that exact pattern:
- **Headings now HEAVY BOLD** (Manrope 800, negative letter-spacing -.028 to -.035em, tight 1.06-1.15 line-height) — h1, h2, .display-4/5
- **Body text fully CLEAN/REGULAR** (400 weight, no chunky bold paragraphs)
- **Contrast pair** — `--bs-emphasis-color: #0f172a` for headings, `--bs-body-color: #1e293b` for body
- **Section h2** scales `clamp(1.75rem, 2.6vw, 2.35rem)` — impactful on desktop, balanced on mobile
- **Buttons** — pill-shaped globally, hero CTA bumped to 700 for emphasis

### What changed in CSS
- `body { font-family: "Manrope", "Lato", ... !important; font-weight: 400 }`
- h1/.display-* / .h1: Manrope 800, letter-spacing -.035em, line-height 1.08
- h2: 800 / -.028em / 1.15; h3: 750 / -.022em; h4-h6: 700
- `.fw-bold` 600, `.fw-semibold` 500, navbar links 600
- Hero h1: 800, letter-spacing -.035em, color #0a0f1c
- `.btn { border-radius: 999px }` for global pill aesthetic

### Result
"Boost Productivity with Microsoft Office 2024", "Picked for you", "What Our Customers Say", "How It Works" all have PayPal-like impact. Body paragraphs read clean & light. Mega-menus retain v2 compactness with refined typography.

---

## 2026-02-15 — Homepage typography elegance pass + v2 site-wide polish

### v2 elegance polish (Feb 15 evening) — site-wide
1. **Mega-menu shrink** — Microsoft Products dropdown padding reduced (`p-4` → `p-3`), antivirus min-width 320px → 260px. Tighter row gutters, smaller `.mega-heading` (.65rem), tighter `.mega-year` (.82rem), smaller `.mega-link` (.76rem). Topic-hub badges slimmed (padding/font/weight).
2. **Volume Pricing card** — `functions.php` markup rebuilt with smaller icons, font sizes, button paddings; uses `fw-semibold` instead of `fw-bold`.
3. **Cards / blocks shrunk** — global `.card.p-4` → 1.1rem padding; `.card.p-5` → 1.5rem; spotlight 1.25rem; side-product-row .75rem; `.py-5` 3rem → 2.4rem; `.py-4` 1.6rem.
4. **Homepage card padding** — explicit `p-4` → `p-3` swaps on Welcome-back, How-it-works, Why-choose, hero stats, biz-card; CTA band `p-5` → `p-4`.
5. **Site-wide boldness reduced again** — `.fw-bold` to 600, `.fw-semibold` to 500, navbar nav-links to 500, headings to 600, hero h1 to 650.
6. **Contrast lift** — `--bs-body-color: #1e293b` (slate-800), `--bs-secondary-color: #475569` (slate-600), `--bs-emphasis-color: #0f172a` (slate-900). Dark mode mirrored with `#e2e8f0` / `#94a3b8` / `#f8fafc`.
7. **Card aesthetics** — border-radius `.65rem` (smaller than .75rem), softer shadow, refined border color.
8. **Buttons / chips** — Eyebrow .65rem 600 weight; btn-lg & btn-hero-cta paddings reduced.

All changes are purely additive CSS overrides + tiny markup swaps — no regressions to admin or backend logic. Verified visually on homepage (hero + both mega-menus + sections), shop.php, blog.php, about-us.php in both light & dark themes.

---

## 2026-02-15 — Homepage typography elegance pass (initial)

### Less-bold, more-elegant content (assets/css/style.css)
- Reduced `.fw-bold` weight from `800!important` → `600!important` (now semibold instead of black-bold)
- Reduced `.fw-semibold` from `650` → `550`, `.btn` from `650` → `550`
- Reduced `.display-4 / .display-5` from `800` → `700`
- Added global `h1-h6` base weight of `650`
- Hero h1 (`.hero h1`) from `800` → `700`; hero-badge from `700` → `600`; hero CTA from `700` → `600`
- Hero stats (`.hero .hero-stats .fs-3`) from `800` → `700`
- Brand text (`.brand-text`) from `800` → `700`; brand-tag from `700` → `600`
- Eyebrow / mega-heading / filter-group-title from `800` → `700`
- Mega-menu year links (`.mega-year`) from `700` → `550`
- Product-title from `700` → `600`; accordion-button from `700` → `600`
- `.page-content h2/h3` softened
- Added font-smoothing (`-webkit-font-smoothing: antialiased`, `text-rendering: optimizeLegibility`) on `body` for cleaner rendering

### Why
User feedback: "The content on the homepage, especially homepage, the content look too much bold. Reduce the boldness, make it less bold, more elegant, suit with the theme."

### Tested
Visual diff on light + dark themes — hero, mega-menu, best-sellers, FAQ, CTA, footer all render with refined typographic hierarchy. No layout regressions observed.

---



## 2026-02-14 (iteration 7) — Admin post quick-actions + Topic Cluster Hubs

### Published Blog Posts — Write One Post + Random Post buttons
- New in-section quick-action cluster (data-testid=posts-quick-actions): a country picker `<select>` (data-testid=posts-quick-region) + green **Write One Post** button (data-testid=posts-write-one-btn) + blue **Random Post** button (data-testid=posts-random-btn).
- JS rewrites both buttons' href to append `&region=XX` when the operator picks a country.
- Clicking any country pill in the filter bar ALSO syncs the quick-action picker — pill click → posts-quick-region.value updates → both button hrefs update. Clicking "All" clears the picker.
- Matches the visual style of the existing "Generate Trends Article Now" button in the Trending Articles section.

### Topic Cluster Hub — /hub/<topic>
- NEW `/app/php-version/hub.php` — single dynamic template; topics declared in `$TOPICS` config (microsoft-office, windows, antivirus shipped).
- NEW router rewrite in `/app/php-version/router.php` — `^/hub/([a-z0-9\-]+)/?$` → hub.php?topic=<slug>. SEO-friendly URLs without `.php` extension.
- 404 fallback for invalid slugs links to the three real hubs.
- Each hub renders:
  - Hero with topic accent colour (red/blue/green) + "TOPIC CLUSTER HUB" badge + H1.
  - Stat pills: products / guides / answers / last-updated.
  - AEO Quick Answer card (40-60 word direct answer).
  - Table-of-contents chip nav.
  - **Products** section — aggregates every product in the topic's category list (ORDER BY rating*reviews DESC).
  - **Guides** section — pulls blog posts whose title matches the topic's LIKE patterns.
  - **FAQs** section — dedup'd Q&A pulled from the top 4 products' `product_faqs()`.
  - **Related topic hubs** cards — cross-links the OTHER two hubs.
- Five JSON-LD blocks per hub: Organization graph + CollectionPage (with @id `#cluster`, mentions array, audience.audienceType, keywords, dateModified) + BreadcrumbList + FAQPage + ItemList.

### Hub discoverability
- Added to `sitemap-xml.php` at priority 0.9 (highest non-homepage weight).
- Added to the homepage navbar dropdowns: Microsoft-Products → "Microsoft Office guide" + "Windows guide" badges; Antivirus → "Antivirus topic hub" badge.

### Testing
- 286/286 pytest passing (184 baseline + 102 iter6 tests across 8 classes in `/app/backend/tests/test_iteration6_features.py`).
- Live Playwright verification on the preview URL — all hub testids + admin quick-action region rewrite verified working.

---

## 2026-02-14 (iteration 6) — Full SEO / AEO / GEO upgrade across the site

### Foundations
- `/app/php-version/robots-txt.php` (already dynamic) verified — explicit allow-list for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Bingbot + Sitemap line. Disallows admin/checkout/account routes.
- `/app/php-version/index.php` — homepage now emits **WebSite + SearchAction** JSON-LD so Google offers the sitelinks search box AND AI assistants can deep-link search results.
- `/app/php-version/contact.php` — **ContactPage** JSON-LD with a 2-entry `contactPoint` array (customer support + sales) and Organization social links.
- `/app/php-version/about-us.php` — **AboutPage** JSON-LD with E-E-A-T signals: foundingDate, knowsAbout, aggregateRating, awards.

### AEO (Answer Engine Optimization)
- New helper `render_aeo_answer()` — visible 40-60 word "Quick Answer" callout that AI Overviews / Bing Chat / ChatGPT / Perplexity quote verbatim.
- New helper `render_paa_block()` — visible "People also ask" accordion.
- New helper `product_paa_faqs()` — 6 deterministic product-aware Q&A pairs (where to buy, delivery time, platform support, subscription vs one-time, activation failure, multi-device).
- Product page now emits BOTH a Quick Answer card AND a People-Also-Ask block — visible to users, serialised to a **second FAQPage JSON-LD**. Result: product pages now ship 7 valid JSON-LD blocks.
- Category page has its own Quick Answer card with the dynamic lowest-price call-out.

### GEO (Generative Engine Optimization)
- `/app/php-version/blog-post.php` schema upgraded:
  - @type now `Article` for trends, `BlogPosting` for regular posts.
  - `author` is a Person (or Organization for AI posts) with `knowsAbout`, `worksFor`, `jobTitle` (E-E-A-T).
  - `publisher` references Organization @id `#organization` for graph cohesion.
  - Adds `wordCount`, `timeRequired` (ISO 8601), `articleSection` from product category, `speakable` selectors.
  - `dateModified` now tracks the real `updated_at` column, not the publish date.
- Visible: `data-testid=blog-post-byline` with calendar + read time + AI Editorial Team badge + Updated stamp + country badge.
- LLM prompt for regional blog generator rewritten:
  - `lead` field now an explicit 40-60 word DIRECT ANSWER (AEO).
  - Must "include at least ONE concrete statistic with attribution".
  - Body must START with H2 sections (lead is rendered separately above).
- LLM prompt for trends generator hardened to demand pure JSON output (carryover from iter4).
- New schema columns `blog_posts.lead`, `blog_posts.faq_json`, `blog_posts.updated_at` (idempotent via `seo_bot_ensure_schema()`).
- Both regional + trends INSERTs persist the LLM-emitted `lead` value + bump `updated_at = NOW()`.

### Automated content freshness
- New backend function `seo_bot_freshness_tick()` in seo-bot.php:
  - Runs from the shutdown handler at the bottom of every admin/auto-blogger page load.
  - Gated on (a) 23-hour cooldown so we only refresh ONE post per day, (b) post must be NULL `updated_at` OR older than 90 days.
  - Picks the SINGLE oldest stale AI post.
  - Re-runs the LLM (short 700-token call) for a fresh `lead` + 3-item FAQ.
  - UPDATEs `lead`, `faq_json`, `updated_at = NOW()` → bumps the JSON-LD `dateModified` → freshest-content signal.
  - Persists `seo_freshness_last_tick_at` + `seo_freshness_last_refreshed_id` so the operator can audit.

### Visible breadcrumbs
- New helper `render_breadcrumb_nav()`.
- `/app/php-version/category.php` now renders the visible `<nav aria-label="breadcrumb">` block above the H1 (matches the existing BreadcrumbList JSON-LD).
- Other pages already had visible breadcrumbs (product.php) or don't need them (homepage).

### Testing
- 184/184 pytest passing.  111 baseline + 73 new tests in `/app/backend/tests/test_iteration5_features.py` across 16 classes covering every JSON-LD shape, visible testid, prompt directive, schema migration, and the freshness tick state machine.

---

## 2026-02-14 (iteration 5) — Per-country posts + dedicated Trending Articles section

### Quick Actions country picker
- New `<select id="quick-action-region">` (data-testid=quick-action-region) above the four Quick Action cards.  Options: 🌍 Auto/All (default), 🇺🇸 US, 🇬🇧 UK, 🇦🇺 AU, 🇨🇦 CA.
- JS rewrites each card's `href` in-place using `data-base-href` + appending `&region=XX` when a country is picked.  Updates the small hint copy under each card too (e.g. "Target: 🇺🇸 United States").
- Cards affected: Write One Post, Random Post, Generate Trends Now.  Daily Batch card unchanged (always all regions).

### Regional trends articles
- `seo_publish_featured_trends_article()` signature changed from `(array &$report, bool $force = false)` to `(array &$report, bool $force = false, string $targetRegion = 'ALL')`.
- Region whitelist `['ALL','US','UK','AU','CA']`; invalid input falls back to `'ALL'`.
- New `regionAudienceMap` tailors the LLM system prompt per region (US: NIST/SOC 2/USD, UK: GDPR/Cyber Essentials/GBP, etc).
- INSERT now binds `$targetRegion` instead of hard-coded `'ALL'`.
- `run_trends_article` handler reads `?region=XX`, normalises it, passes the value as the 3rd argument, and produces a flash message that includes either "Global audience" or "Targeted at US/UK/AU/CA".

### Generate Trends Article Now (bypass cooldown)
- Quick Actions trend card + the new Trending Articles section both link to `?run_trends_article=1&force=1` so the 20-hour cooldown is skipped.
- The Trending Articles section also exposes a dedicated `data-testid=trends-generate-now-btn` button at the top of the country pill bar.

### Dedicated "Trending Articles" admin sub-section
- New `<details id="trends-section">` placed directly after the Published Blog Posts section.
- Six country filter pills (All / 🌍 Global / 🇺🇸 US / 🇬🇧 UK / 🇦🇺 AU / 🇨🇦 CA), each with a server-computed count badge.
- JS filter rules: All → everything, Global → only data-is-global='1' rows, country code → that country's rows + globals.
- Empty state: data-testid=trends-empty-state shows "Click Generate Trends Article Now to write your first one."

### De-duplication: Published Blog Posts vs Trending Articles
- Published Blog Posts query now excludes `is_featured_trends=1` rows (`AND COALESCE(is_featured_trends,0) = 0`).
- Per-region counts + total badge also exclude trends articles so the header count matches the rendered rows.
- The same article never appears in both lists anymore (verified: Playwright found 5 in trends + 6 in published, overlap = 0).

### Testing
- 111/111 pytest passing (78 baseline + 33 iter4).
- New `/app/backend/tests/test_iteration4_features.py` with 33 tests across 11 classes covering the Quick Actions picker DOM/JS, trends function signature, regionAudienceMap, trends sub-section DOM, filter JS rules, and flash branches.

---

## 2026-02-14 (iteration 4) — Country filter + trends JSON + auto-weekly

### Public blog country filter
- `/app/php-version/blog.php` line ~33 — region filter SQL now matches `target_region = ? OR target_region = 'ALL' OR target_region IS NULL`.  Before: picking a country returned 0 rows whenever seed posts weren't tagged.  After: every country pill shows that country's regional posts + all global/seed posts.

### Admin "Published Blog Posts" — country visibility & filter parity
- Each row now carries `data-region` + `data-is-global` attributes.
- Region-agnostic posts (target_region=NULL or 'ALL') render a 🌍 emoji with a `Global` label (`.post-flag-label`); region-specific posts render their country code.
- The country-pill JS filter (admin.php ~line 2716) keeps globals visible across every country selection, matching public-page behaviour.

### Trends "invalid JSON from LLM" fix
- New helper `_seo_llm_json_decode()` in `seo-bot.php` (lines ~30-100) handles:
  - Pure JSON
  - ` ```json … ``` ` fenced JSON
  - Chatty prefixes ("Here is the JSON:\n{…}")
  - Smart-quoted JSON (`"…"` curly quotes)
  - Raw newlines / tabs INSIDE string values (control chars LLMs love to emit)
- Used in BOTH LLM call sites: regional blog generator + trends generator.
- Trends prompt strengthened to require `{ … }` only (no preface, no markdown).
- Error report now includes a 160-char snippet of what the LLM actually returned so the operator can debug fast.

### Auto-resubmit sitemap weekly
- New toggle in admin SEO panel: data-testid=`auto-weekly-toggle` (Bootstrap switch).  Auto-submits on change.
- Persists `auto_sitemap_weekly` setting (0/1).  Hint copy mirrors current state.
- New backend function `seo_bot_weekly_sitemap_tick()` (seo-bot.php) — runs from the shutdown handler at the bottom of every admin/auto-blogger page load:
  - Gated on toggle == '1' AND `last_sitemap_submit_at` > 7 days old.
  - Re-pings IndexNow with up to 100 URLs; updates timestamp + sets `last_sitemap_submit_kind = 'auto_weekly'` on success.
- Submit-button "Sitemap Submitted" pill now shows `· auto` suffix when the most recent submission was triggered by the weekly cron (visual cue that you can leave it running).

### Testing
- 78/78 pytest passing (24 baseline + 23 iter2 + 30 iter3 + 1 overlap).
- New `/app/backend/tests/test_iteration3_features.py` with 30 tests across 9 classes.

---

## 2026-02-14 (iteration 3) — Token UX parity + submitted-state button

### Token "Uploaded" pattern parity (Google Search Console + Bing Webmaster)
- `/app/php-version/admin.php` API Keys card now reads `$gscToken`/`$bingToken` (defined earlier on the page) instead of `$seoGsc`/`$seoBing` which were lower-scoped and always undefined at that point — fixed the bug where the green "Uploaded" state never triggered no matter how many times tokens were saved.
- Both tokens now match the AI Key card's UX exactly: green panel + masked value + "Change" button (data-testids `gsc-uploaded-card` / `gsc-change-btn` / `gsc-masked` and the Bing equivalents).

### "Sitemap Submitted" state for the green Submit button
- Successful sitemap submissions persist `last_sitemap_submit_at` + `last_sitemap_submit_count` in the settings table.
- For 30 minutes after a successful submission, BOTH submit buttons (top API-Keys card + lower Search-Engine-Visibility card) flip to a disabled green "Sitemap Submitted · N URLs · Xm ago" pill with a sidebar "Resubmit" link/button.
- After 30 minutes the original "Submit Sitemap to (All) Search Engines" buttons return automatically.
- New helper `human_time_diff_compact()` in `includes/functions.php`.

### Testing
- 48/48 pytest passing (24 baseline + 24 iteration-2/3 tests).
- Two new dual-state tests: `test_button_pre_submit_state` + `test_button_post_submit_state`.

---

## 2026-02-14 (later) — Dark mode polish + sitemap UX + review stars + AI summary

### Customer Review stars (interactive)
- `/app/php-version/reviews.php` — removed `checked` from the default 5-star radio; the form now opens with EVERY star empty.
- `/app/php-version/assets/css/style.css` — `.star-input` uses gold (`#f59e0b` light / `#fbbf24` dark) with `-webkit-text-stroke` for crisp empty/filled states + dark-mode override.
- Server-side validation: `rating < 1` now triggers an inline `data-testid=review-error` instead of silently defaulting to 5.

### Sitemap submission flow
- `/app/php-version/admin.php` — removed the calls to deprecated `google.com/ping` and `bing.com/ping`. The handler now uses ONLY IndexNow + Search Console/Webmaster Tools discovery messaging.
- Catch-all flash no longer dumps raw HTTP status codes / "deprecated" labels.
- New auto-sitemap hint: when `site_domain_url` is set, the admin SEO panel renders `data-testid=auto-sitemap-hint` with a clickable `<domain>/sitemap.xml` link (`data-testid=auto-sitemap-url`) so the operator can see the detected sitemap URL without typing.

### Dark mode polish
- NEW `/app/php-version/assets/css/dark-mode-polish.css` — loaded by both `includes/header.php` (public) and `includes/admin-shell.php` (admin). Adds:
  - Solid card backgrounds (replaces 4%-opacity transparent surfaces)
  - Visible borders + inset highlights for depth
  - Strong-contrast input fields + focus rings
  - Polished modal/dropdown/accordion/table palettes
  - High-contrast badge variants (Active / Not set / Connected / Recommended)
  - Cleaner footer + topbar gradients
- `/app/php-version/admin.php` SEO platform cards now use a proper gradient + `.platform-name` class for guaranteed contrast in both themes.

### Logo placement everywhere
- `/app/php-version/includes/admin-shell.php` — admin topbar logo now carries `.logo-3d` + `.brand-mark` classes so it inherits the body's `data-brand-motion="bounce"` animation (already enabled storefront-wide).
- `/app/php-version/includes/pdf.php` — receipt + invoice PDFs now resolve the configured Company Info logo via new helper `_pdf_company_logo_path()`. Falls back to the bundled email-logo.gif.

### AI-friendly summary JSON-LD
- `/app/php-version/includes/seo-content.php` — new `product_ai_summary_jsonld()` emits an `@type: Article` block with `about > Product` linkage, `audience.audienceType`, `keywords`, `mainEntityOfPage`. This is the format AI search engines (ChatGPT, Perplexity, Google AI Overviews, Bing Copilot) preferentially quote.
- `/app/php-version/product.php` + `/app/php-version/includes/header.php` — emit `$jsonLdAiSummary` so each product page now ships 6 JSON-LD blocks (Org, Product, Breadcrumb, FAQ, HowTo, Article-AI-summary).
- `/app/backend/tests/test_seo_php.py` — bumped expectation from 5 → 6 blocks.

### Testing
- 47/47 pytest cases passing (24 baseline `test_seo_php.py` + 23 new `test_iteration2_features.py`).

---

## 2026-02-14 — SEO overhaul: product + category pages

(same as prior entry — see earlier in this file)

---

## 2026-02-14 — Sitemap & blog filter fixes (prior to this session)
- IndexNow now uses configured `site_domain_url` instead of preview Host header (fixes HTTP 403)
- Auto-submit IndexNow when domain is saved in admin SEO panel
- Friendly "✓ Submitted successfully" flash messages
- /blog.php public filter (search by title/content + region + clear button)

### Summary
Major on-page SEO upgrade for `product.php` and `category.php` so the storefront
ranks well on Google and is parsed correctly by AI search engines (ChatGPT,
Perplexity, Bing Chat, Google AI Overviews). Plus environment recovery (PHP 8.2
+ MariaDB installed in the pod) and one regression bug fix from the previous
session.

### Files added
- `/app/php-version/includes/seo-content.php` — new helper module:
  - `product_long_tail_keywords()` — dense mid/long-tail keywords meta string per product
  - `product_seo_copy()` — visible H2/H3 SEO copy block ("Why buy", "How to activate", "Is X one-time?", "Best price")
  - `product_howto_jsonld()` — HowTo schema (5-step activation)
  - `product_review_items_jsonld()` / `product_review_snippets()` — DB-backed review schema + visible cards
  - `product_related_articles()` — blog-post deep links per product
  - `product_sibling_category()` — Mac↔PC sister category resolution
  - `category_intro_seo()` — hero intro paragraph (intent-matched per slug family)
  - `category_long_tail_keywords()` / `category_faqs()` / `category_buying_guide_html()` — full category-page SEO copy
  - `category_itemlist_jsonld()` / `category_breadcrumb_jsonld()` — schema generators
  - `faq_to_jsonld()` — FAQPage schema with Speakable selectors for AI assistants
  - `related_category_links()` / `popular_search_terms()` — internal-link cluster generators
- `/app/backend/tests/test_seo_php.py` — pytest regression suite (24 tests)

### Files updated
- `/app/php-version/product.php`
  - Long-tail title: `<Name> — Lifetime License Key for <Platform> | Brand`
  - Fixed `$brandName` undefined → uses `SITE_BRAND` (broke Product.offers.seller JSON-LD)
  - Embeds up to 5 real customer reviews in `Product.review[]`
  - Emits HowTo + Speakable schema
  - Sets `$preloadImage` so the header preloads the LCP image with `fetchpriority="high"`
  - Adds visible SEO copy block + review snippets section + deep-link cluster
  - Empty-state "Be the first to review" CTA preserves H2 hierarchy
- `/app/php-version/category.php` — full rewrite with:
  - CollectionPage + BreadcrumbList + FAQPage + ItemList JSON-LD
  - Hero intro copy, long-form buying guide (H2/H3 hierarchy), accordion FAQ
  - Deep-link cluster (related categories + popular searches + latest blog posts)
  - Long-tail title `<Title> — Lifetime License Keys (<year>)` + dense keyword meta
- `/app/php-version/includes/header.php`
  - Emits `$jsonLdHowTo` + `$jsonLdItemList`
  - `<link rel="preload" as="image" fetchpriority="high">` for `$preloadImage`
- `/app/php-version/admin.php` — save_seo_tokens flow:
  - When the same domain is re-saved, surfaces a friendly "Domain unchanged — skipping IndexNow resubmission" hint (previously silent)

### Testing
- 24/24 pytest cases passing (blog filter, product SEO, 7 category slugs, admin sitemap, regression).
- Visual smoke tests on /category.php?slug=office-pc and /product.php (Bitdefender Mac) — render clean, all sections show.

### Operational notes
- PHP 8.2.31-CLI + MariaDB 10.11 were installed in the pod (apt). The `frontend` supervisor service is back online on port 3000.
- Seeded 3 demo `customer_reviews` rows (Alice/Bob/Carla) so the review schema has data to embed in dev. Safe to drop on prod.
- IndexNow API may return rate-limited from the preview host; the flash handler shows a friendly warning, not a fatal error.

---

## 2026-02-14 — Sitemap & blog filter fixes (prior to this session)
- IndexNow now uses configured `site_domain_url` instead of preview Host header (fixes HTTP 403)
- Auto-submit IndexNow when domain is saved in admin SEO panel
- Friendly "✓ Submitted successfully" flash messages
- /blog.php public filter (search by title/content + region + clear button)
