# Changelog

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
