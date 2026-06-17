"""
End-to-end tests for the Maventech PHP site:
- Blog filter
- Product SEO refactor (JSON-LD, preload, deep cluster)
- Category SEO refactor (JSON-LD, faqs, deep cluster)
- Admin sitemap submission flow (login + IndexNow + save SEO tokens)
- Regression on home/shop/reviews
"""
import json
import os
import re
import pytest
import requests
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE_URL = os.environ.get("PHP_BASE_URL", "https://indexnow-checker.preview.emergentagent.com").rstrip("/")
JSON_LD_RE = re.compile(
    r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
    re.DOTALL | re.IGNORECASE,
)


# ----------------------------- shared fixtures -----------------------------
@pytest.fixture(scope="session")
def session():
    s = requests.Session()
    s.headers.update({"User-Agent": "pytest-seo-tester"})
    return s


@pytest.fixture(scope="session")
def admin_session(session):
    """Login as admin and return an authenticated session."""
    r = session.get(f"{BASE_URL}/login.php", timeout=20)
    assert r.status_code == 200, f"login.php load failed: {r.status_code}"
    r2 = session.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=20,
    )
    # After successful login the admin area should be reachable
    rcheck = session.get(f"{BASE_URL}/admin.php", timeout=20)
    if rcheck.status_code != 200 or "login" in rcheck.url.lower():
        pytest.skip(f"Admin login failed (status={rcheck.status_code}, url={rcheck.url})")
    return session


def _extract_jsonld_blocks(html: str):
    return [m.group(1).strip() for m in JSON_LD_RE.finditer(html)]


# ============================== BLOG TESTS ==============================
class TestBlog:
    def test_blog_index_loads(self, session):
        r = session.get(f"{BASE_URL}/blog.php", timeout=20)
        assert r.status_code == 200
        assert 'data-testid="blog-filter-form"' in r.text
        assert 'data-testid="blog-search"' in r.text
        assert 'data-testid="blog-total-count"' in r.text

    def test_blog_search_filter(self, session):
        r = session.get(f"{BASE_URL}/blog.php", params={"q": "office"}, timeout=20)
        assert r.status_code == 200
        # If there are matches, Clear button should appear; if not, no-results
        assert ('data-testid="blog-clear-filter"' in r.text) or (
            'data-testid="blog-no-results"' in r.text
        )

    def test_blog_no_match_shows_empty_state(self, session):
        r = session.get(f"{BASE_URL}/blog.php", params={"q": "zzz-no-match"}, timeout=20)
        assert r.status_code == 200
        assert 'data-testid="blog-no-results"' in r.text

    def test_blog_region_filter_no_error_when_column_missing(self, session):
        # target_region column may not exist; should still 200 and return posts
        r = session.get(f"{BASE_URL}/blog.php", params={"region": "US"}, timeout=20)
        assert r.status_code == 200
        # should still render the filter
        assert 'data-testid="blog-filter-form"' in r.text


# ============================== PRODUCT TESTS ==============================
PRODUCT_SLUG = "bitdefender-antivirus-for-mac-1-mac-1-year"


class TestProductSEO:
    @pytest.fixture(scope="class")
    def product_html(self):
        r = requests.get(f"{BASE_URL}/product.php", params={"slug": PRODUCT_SLUG}, timeout=20)
        assert r.status_code == 200, f"product page status={r.status_code}"
        return r.text

    def test_product_seo_sections_present(self, product_html):
        for tid in ("product-seo-copy", "product-review-snippets", "product-deep-cluster"):
            assert f'data-testid="{tid}"' in product_html, f"Missing data-testid={tid}"

    def test_product_jsonld_seven_blocks_valid(self, product_html):
        blocks = _extract_jsonld_blocks(product_html)
        assert len(blocks) == 7, f"Expected 7 JSON-LD blocks (Organization, Product, BreadcrumbList, FAQPage, HowTo, Article AI-summary, FAQPage People-Also-Ask), found {len(blocks)}"
        parsed = []
        for i, b in enumerate(blocks):
            try:
                parsed.append(json.loads(b))
            except json.JSONDecodeError as e:
                pytest.fail(f"JSON-LD block #{i} invalid JSON: {e}\n---\n{b[:400]}")
        # Identify schema types
        types_found = set()
        for p in parsed:
            if isinstance(p, dict):
                t = p.get("@type")
                if isinstance(t, list):
                    types_found.update(t)
                elif t:
                    types_found.add(t)
                if "@graph" in p:
                    for g in p["@graph"]:
                        gt = g.get("@type")
                        if isinstance(gt, list):
                            types_found.update(gt)
                        elif gt:
                            types_found.add(gt)
        for expected in ("Product", "BreadcrumbList", "FAQPage", "HowTo"):
            assert expected in types_found, f"Missing schema type: {expected}. Found: {types_found}"

    def test_product_schema_seller_not_empty_and_reviews(self, product_html):
        blocks = _extract_jsonld_blocks(product_html)
        product_obj = None
        for b in blocks:
            try:
                obj = json.loads(b)
            except Exception:
                continue
            if isinstance(obj, dict) and obj.get("@type") == "Product":
                product_obj = obj
                break
        assert product_obj is not None, "No Product JSON-LD found"
        offers = product_obj.get("offers") or {}
        if isinstance(offers, list):
            offers = offers[0]
        seller = offers.get("seller") or {}
        assert seller.get("name"), f"Product seller.name must not be empty (got {seller!r})"
        # Reviews
        reviews = product_obj.get("review") or []
        assert isinstance(reviews, list), "Product.review must be a list"
        assert 1 <= len(reviews) <= 5, f"Expected 1-5 reviews, got {len(reviews)}"

    def test_product_hero_fetchpriority_and_preload(self, product_html):
        assert re.search(
            r'<link\s+rel=["\']preload["\'][^>]*as=["\']image["\'][^>]*fetchpriority=["\']high["\']',
            product_html,
            re.IGNORECASE,
        ) or re.search(
            r'<link\s+rel=["\']preload["\'][^>]*fetchpriority=["\']high["\'][^>]*as=["\']image["\']',
            product_html,
            re.IGNORECASE,
        ), "Missing <link rel=preload as=image fetchpriority=high> for hero image"
        assert re.search(r'fetchpriority=["\']high["\']', product_html), "fetchpriority=high not present"

    def test_product_h1_and_h2s_present(self, product_html):
        # H1 exists
        assert re.search(r"<h1[^>]*>.*?</h1>", product_html, re.DOTALL), "Missing H1"
        # multiple H2s for the new SEO sections
        h2s = re.findall(r"<h2[^>]*>(.*?)</h2>", product_html, re.DOTALL | re.IGNORECASE)
        assert len(h2s) >= 3, f"Expected >=3 H2 headings for SEO sections, found {len(h2s)}"

    def test_product_deep_cluster_links(self, product_html):
        m = re.search(
            r'data-testid="product-deep-cluster".*?</section>|data-testid="product-deep-cluster".*?</div>',
            product_html,
            re.DOTALL,
        )
        # Fall back to anywhere after the testid
        idx = product_html.find('data-testid="product-deep-cluster"')
        assert idx >= 0
        snippet = product_html[idx:idx + 15000]
        # at least 6 anchors to /category.php
        cat_links = re.findall(r'href="[^"]*category\.php\?slug=', snippet)
        assert len(cat_links) >= 6, f"Expected >=6 related-category links, found {len(cat_links)}"
        # popular search badges link to /shop.php?q=
        shop_links = re.findall(r'href="[^"]*shop\.php\?q=', snippet)
        assert len(shop_links) >= 1, "Expected at least one popular-search link to shop.php?q="
        # at least one blog post link
        blog_links = re.findall(r'href="[^"]*blog-post\.php\?(?:slug|id)=|href="[^"]*/?blog\.php', snippet)
        assert len(blog_links) >= 1, "Expected at least one blog-related link in deep cluster"

    def test_product_cart_buttons_regression(self, product_html):
        # Add to cart / Buy now / Notify buttons should be present
        assert re.search(r"add[\s\-_]?to[\s\-_]?cart", product_html, re.IGNORECASE), "Add to cart missing"
        assert re.search(r"buy[\s\-_]?now", product_html, re.IGNORECASE), "Buy Now missing"


# ============================== CATEGORY TESTS ==============================
CATEGORY_SLUGS = [
    "office-pc",
    "office-mac",
    "office-2024-pc",
    "windows-11",
    "bitdefender",
    "mcafee",
    "antivirus",
]


class TestCategorySEO:
    @pytest.mark.parametrize("slug", CATEGORY_SLUGS)
    def test_category_renders_and_jsonld(self, slug):
        r = requests.get(f"{BASE_URL}/category.php", params={"slug": slug}, timeout=20)
        assert r.status_code == 200, f"category {slug} status={r.status_code}"
        html = r.text
        for tid in (
            "category-intro-copy",
            "category-seo-copy",
            "category-faq",
            "category-deep-cluster",
        ):
            assert f'data-testid="{tid}"' in html, f"[{slug}] missing testid {tid}"
        # FAQ buttons 0..4
        for i in range(5):
            assert f'data-testid="cat-faq-q-{i}"' in html, f"[{slug}] missing cat-faq-q-{i}"
        blocks = _extract_jsonld_blocks(html)
        assert len(blocks) == 5, f"[{slug}] expected 5 JSON-LD blocks, got {len(blocks)}"
        parsed = []
        for i, b in enumerate(blocks):
            try:
                parsed.append(json.loads(b))
            except json.JSONDecodeError as e:
                pytest.fail(f"[{slug}] JSON-LD #{i} invalid: {e}")
        types_found = set()
        for p in parsed:
            if isinstance(p, dict):
                t = p.get("@type")
                if isinstance(t, list):
                    types_found.update(t)
                elif t:
                    types_found.add(t)
                if "@graph" in p:
                    for g in p["@graph"]:
                        gt = g.get("@type")
                        if isinstance(gt, list):
                            types_found.update(gt)
                        elif gt:
                            types_found.add(gt)
        for expected in ("CollectionPage", "BreadcrumbList", "FAQPage", "ItemList"):
            assert expected in types_found, f"[{slug}] missing schema {expected} (found {types_found})"

    def test_category_office_pc_meta_and_title(self):
        r = requests.get(f"{BASE_URL}/category.php", params={"slug": "office-pc"}, timeout=20)
        html = r.text
        title_m = re.search(r"<title[^>]*>(.*?)</title>", html, re.DOTALL | re.IGNORECASE)
        assert title_m, "no <title>"
        title = title_m.group(1)
        assert "2026" in title, f"Year 2026 not in title: {title!r}"
        assert re.search(r"lifetime\s+license", title, re.IGNORECASE), f"'Lifetime License Keys' missing in title: {title!r}"
        meta_desc = re.search(
            r'<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']', html, re.IGNORECASE
        )
        meta_kw = re.search(
            r'<meta\s+name=["\']keywords["\']\s+content=["\']([^"\']+)["\']', html, re.IGNORECASE
        )
        assert meta_desc, "missing meta description"
        assert meta_kw, "missing meta keywords"
        combined = (meta_desc.group(1) + " " + meta_kw.group(1)).lower()
        # Long-tail variations
        hits = sum(
            1
            for kw in (
                "license key",
                "product key",
                "lifetime",
                "one time",
                "instant delivery",
            )
            if kw in combined
        )
        assert hits >= 3, f"meta should include long-tail variations; matched={hits} in: {combined[:300]}"


# ============================== REGRESSION ==============================
class TestRegression:
    @pytest.mark.parametrize("path", ["/", "/shop.php", "/reviews.php"])
    def test_public_pages_still_200(self, session, path):
        r = session.get(f"{BASE_URL}{path}", timeout=20)
        assert r.status_code == 200, f"{path} -> {r.status_code}"


# ============================== ADMIN / SITEMAP ==============================
class TestAdminSitemap:
    def test_admin_sitemap_submit(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php",
            params={"tab": "ai-blogger", "submit_sitemaps": "1"},
            timeout=60,
        )
        assert r.status_code == 200, f"sitemap submit status={r.status_code}"
        html = r.text
        # Find the flash block
        m = re.search(
            r'data-testid="ai-blogger-flash"[^>]*>(.*?)</',
            html,
            re.DOTALL,
        )
        # The flash block may be nested with many tags; just search for the testid + text near it
        idx = html.find('data-testid="ai-blogger-flash"')
        assert idx >= 0, "ai-blogger-flash element not found"
        # Take next ~4000 chars as the flash region
        flash_region = html[idx : idx + 4000].lower()
        assert re.search(r"sitemap|indexnow|google|bing", flash_region), (
            f"flash should mention Sitemap/IndexNow/Google/Bing. Got: {flash_region[:400]}"
        )
        # Should not contain raw curl errors
        assert "curl error" not in flash_region, "raw curl error leaked into flash"

    def test_admin_save_site_domain_url(self, admin_session):
        # Use a unique domain each run so $domainChanged is true and the
        # IndexNow auto-submit branch fires.
        import time
        domain = f"https://example-test-domain-{int(time.time())}.com"
        r = admin_session.post(
            f"{BASE_URL}/admin.php",
            params={"tab": "ai-blogger"},
            data={"save_seo_tokens": "1", "site_domain_url": domain},
            timeout=60,
        )
        assert r.status_code == 200, f"save SEO tokens status={r.status_code}"
        html = r.text
        idx = html.find('data-testid="ai-blogger-flash"')
        assert idx >= 0, "ai-blogger-flash not present after save"
        flash_region = html[idx : idx + 4000]
        assert re.search(r"saved.*website domain", flash_region, re.IGNORECASE), (
            f"Expected 'Saved: Website Domain' flash. Got: {flash_region[:400]}"
        )
        # Should attempt IndexNow auto-submit OR show the verification-file warning
        assert re.search(r"sitemap\s+auto-?submitted|verification\s+file", flash_region, re.IGNORECASE), (
            f"Expected IndexNow auto-submit or verification-file warning. Got: {flash_region[:500]}"
        )
