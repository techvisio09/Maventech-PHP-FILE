# Changelog

## 2026-02-14 — SEO overhaul: product + category pages

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
