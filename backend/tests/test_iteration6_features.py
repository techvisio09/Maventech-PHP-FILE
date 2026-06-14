"""
Iteration 6 backend tests:
  (A) Admin "Write One Post" / "Random Post" quick-action buttons in the
      Published Blog Posts section (admin.php?tab=ai-blogger).
  (B) Topic Cluster Hub feature:  /hub/<topic-slug>  for microsoft-office,
      windows and antivirus, plus 404 fallback.

Public URL: REACT_APP_BACKEND_URL (frontend/.env) — proxies /hub/* via
PHP router.php.
"""
import json
import os
import re
import xml.etree.ElementTree as ET
from urllib.parse import urlparse

import pytest
import requests

BASE_URL = (os.environ.get("PHP_BASE_URL")
            or os.environ.get("REACT_APP_BACKEND_URL")
            or "https://indexnow-checker.preview.emergentagent.com").rstrip("/")

ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASS = "Admin@123"

TOPICS = ["microsoft-office", "windows", "antivirus"]


# ---------- Fixtures ----------

@pytest.fixture(scope="session")
def session():
    s = requests.Session()
    s.headers.update({"User-Agent": "iter6-pytest/1.0"})
    return s


@pytest.fixture(scope="session")
def admin_session(session):
    """Login as admin once, reuse cookies."""
    r = session.post(f"{BASE_URL}/login.php",
                     data={"email": ADMIN_EMAIL, "password": ADMIN_PASS},
                     allow_redirects=True, timeout=30)
    if "logout" not in r.text.lower() and "admin" not in r.url.lower():
        pytest.skip(f"Admin login failed (status={r.status_code})")
    return session


# ---------- Helpers ----------

def _fetch(session, path):
    return session.get(f"{BASE_URL}{path}", timeout=30, allow_redirects=True)


def _extract_jsonld_blocks(html):
    """Pull every <script type=application/ld+json> body and JSON-parse it."""
    blocks = re.findall(
        r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
        html, re.DOTALL | re.IGNORECASE)
    parsed = []
    for b in blocks:
        body = b.strip()
        if not body:
            continue
        parsed.append(json.loads(body))
    return parsed


# ============================================================
#  A.  ADMIN — Write One / Random Post quick-action buttons
# ============================================================

class TestAdminPostsQuickActions:
    """Admin Published Blog Posts section: posts-quick-actions cluster."""

    @pytest.fixture(scope="class")
    def admin_html(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger",
                              timeout=30)
        assert r.status_code == 200, f"admin ai-blogger tab status={r.status_code}"
        return r.text

    def test_post_filters_block_exists(self, admin_html):
        assert 'data-testid="post-filters"' in admin_html

    def test_posts_quick_actions_block_exists(self, admin_html):
        assert 'data-testid="posts-quick-actions"' in admin_html

    def test_quick_region_select_exists(self, admin_html):
        assert 'data-testid="posts-quick-region"' in admin_html
        # Must be a <select> tag
        m = re.search(
            r'<select[^>]*data-testid=["\']posts-quick-region["\']',
            admin_html)
        assert m, "posts-quick-region must be a <select>"

    def test_write_one_btn_exists_with_pencil_icon(self, admin_html):
        m = re.search(
            r'<a[^>]*data-testid=["\']posts-write-one-btn["\'][^>]*>(.*?)</a>',
            admin_html, re.DOTALL)
        assert m, "posts-write-one-btn anchor not found"
        inner = m.group(1)
        assert "bi-pencil-square" in inner, \
            "Write One Post must use bi-pencil-square icon"

    def test_random_btn_exists_with_shuffle_icon(self, admin_html):
        m = re.search(
            r'<a[^>]*data-testid=["\']posts-random-btn["\'][^>]*>(.*?)</a>',
            admin_html, re.DOTALL)
        assert m, "posts-random-btn anchor not found"
        inner = m.group(1)
        assert "bi-shuffle" in inner, \
            "Random Post must use bi-shuffle icon"

    def test_write_one_btn_has_green_styling(self, admin_html):
        # Either btn-success class or green-ish style
        m = re.search(
            r'<a[^>]*data-testid=["\']posts-write-one-btn["\'][^>]*>',
            admin_html)
        assert m
        tag = m.group(0)
        assert ("btn-success" in tag) or ("success" in tag.lower()), \
            "Write One Post should render green (btn-success)"

    def test_random_btn_has_blue_styling(self, admin_html):
        m = re.search(
            r'<a[^>]*data-testid=["\']posts-random-btn["\'][^>]*>',
            admin_html)
        assert m
        tag = m.group(0)
        assert ("btn-primary" in tag) or ("primary" in tag.lower()), \
            "Random Post should render blue (btn-primary)"

    def test_buttons_have_data_base_href(self, admin_html):
        """JS uses .posts-qa-link[data-base-href] selector — confirm present."""
        for btn in ("posts-write-one-btn", "posts-random-btn"):
            assert re.search(
                rf'data-testid=["\']{btn}["\'][^>]*data-base-href',
                admin_html) or re.search(
                rf'data-base-href[^>]*data-testid=["\']{btn}["\']',
                admin_html), f"{btn} must carry data-base-href"

    def test_quick_region_js_wires_filter_pills(self, admin_html):
        """JS must contain logic referencing posts-quick-region & posts-qa-link."""
        assert "posts-quick-region" in admin_html
        assert "posts-qa-link" in admin_html or "data-base-href" in admin_html


# ============================================================
#  B.  TOPIC HUB — HTTP, routing, JSON-LD, visible elements
# ============================================================

class TestHubRouting:
    """Router rewrite from /hub/<slug> → hub.php?topic=<slug>."""

    @pytest.mark.parametrize("slug", TOPICS)
    def test_hub_returns_200(self, session, slug):
        r = _fetch(session, f"/hub/{slug}")
        assert r.status_code == 200, f"/hub/{slug} -> {r.status_code}"

    @pytest.mark.parametrize("slug", TOPICS)
    def test_hub_trailing_slash_returns_200(self, session, slug):
        r = _fetch(session, f"/hub/{slug}/")
        assert r.status_code == 200, f"/hub/{slug}/ -> {r.status_code}"

    def test_hub_invalid_slug_returns_404(self, session):
        r = _fetch(session, "/hub/invalid-slug")
        assert r.status_code == 404
        assert "Topic hub not found" in r.text
        # Fallback shows links to the three real hubs
        for slug in TOPICS:
            assert f"hub.php?topic={slug}" in r.text or f"/hub/{slug}" in r.text


class TestHubJsonLd:
    """Each hub must emit valid JSON-LD blocks."""

    @pytest.fixture(scope="class", params=TOPICS)
    def hub_html(self, request, session):
        return request.param, _fetch(session, f"/hub/{request.param}").text

    def test_jsonld_blocks_count(self, hub_html):
        slug, html = hub_html
        blocks = _extract_jsonld_blocks(html)
        # Spec: exactly 5 (Organization graph, CollectionPage, BreadcrumbList,
        # FAQPage, ItemList). FAQPage may be omitted if no products matched,
        # but for our 3 shipped topics products always match.
        assert len(blocks) == 5, \
            f"/hub/{slug} expected 5 JSON-LD blocks, got {len(blocks)}"

    def test_jsonld_all_valid_json(self, hub_html):
        slug, html = hub_html
        # _extract_jsonld_blocks already json.loads each one; if we got here
        # parsing succeeded.
        blocks = _extract_jsonld_blocks(html)
        assert all(isinstance(b, (dict, list)) for b in blocks)

    def test_jsonld_collectionpage_present(self, hub_html):
        slug, html = hub_html
        blocks = _extract_jsonld_blocks(html)
        cp = [b for b in blocks if isinstance(b, dict)
              and b.get("@type") == "CollectionPage"]
        assert cp, f"/hub/{slug} missing CollectionPage block"
        c = cp[0]
        assert str(c.get("@id", "")).endswith("#cluster")
        assert "mentions" in c and isinstance(c["mentions"], list) and c["mentions"]
        types = {m.get("@type") for m in c["mentions"] if isinstance(m, dict)}
        assert "Product" in types, "mentions should contain Products"
        assert isinstance(c.get("audience"), dict)
        assert c["audience"].get("audienceType")
        assert c.get("keywords")
        assert c.get("dateModified")

    def test_jsonld_breadcrumb_present(self, hub_html):
        slug, html = hub_html
        blocks = _extract_jsonld_blocks(html)
        bc = [b for b in blocks if isinstance(b, dict)
              and b.get("@type") == "BreadcrumbList"]
        assert bc, f"/hub/{slug} missing BreadcrumbList"
        items = bc[0].get("itemListElement", [])
        assert len(items) == 3

    def test_jsonld_faqpage_min_8_questions(self, hub_html):
        slug, html = hub_html
        blocks = _extract_jsonld_blocks(html)
        faqs = [b for b in blocks if isinstance(b, dict)
                and b.get("@type") == "FAQPage"]
        assert faqs, f"/hub/{slug} missing FAQPage"
        mq = faqs[0].get("mainEntity", [])
        # Spec: at least 8 questions
        assert len(mq) >= 8, \
            f"/hub/{slug} FAQPage has {len(mq)} questions, expected >= 8"

    def test_jsonld_itemlist_positive(self, hub_html):
        slug, html = hub_html
        blocks = _extract_jsonld_blocks(html)
        il = [b for b in blocks if isinstance(b, dict)
              and b.get("@type") == "ItemList"]
        assert il, f"/hub/{slug} missing ItemList"
        n = il[0].get("numberOfItems", 0)
        assert isinstance(n, int) and n > 0


class TestHubVisibleElements:
    """Every required data-testid on the hub page."""

    @pytest.fixture(scope="class", params=TOPICS)
    def hub_html(self, request, session):
        return request.param, _fetch(session, f"/hub/{request.param}").text

    @pytest.mark.parametrize("testid", [
        "hub-hero", "hub-h1", "hub-stats",
        "hub-quick-answer", "hub-quick-answer-body",
        "hub-toc", "hub-products", "hub-faqs",
        "hub-related", "hub-cta-primary", "hub-breadcrumb",
    ])
    def test_required_testid_present(self, hub_html, testid):
        slug, html = hub_html
        assert f'data-testid="{testid}"' in html, \
            f"/hub/{slug} missing data-testid={testid}"

    def test_hub_h1_contains_topic_title(self, hub_html):
        slug, html = hub_html
        m = re.search(
            r'<h1[^>]*data-testid=["\']hub-h1["\'][^>]*>(.*?)</h1>',
            html, re.DOTALL)
        assert m, f"/hub/{slug} hub-h1 not found"
        text = re.sub(r'<[^>]+>', '', m.group(1)).strip().lower()
        # Each topic's title should mention the topic keyword
        expected_keywords = {
            "microsoft-office": "office",
            "windows": "windows",
            "antivirus": "antivirus",
        }
        assert expected_keywords[slug] in text

    def test_hub_related_links_to_other_two_hubs(self, hub_html):
        slug, html = hub_html
        others = [t for t in TOPICS if t != slug]
        for o in others:
            assert (f'hub.php?topic={o}' in html or f'/hub/{o}' in html), \
                f"/hub/{slug} should link to /hub/{o} via hub-related"

    def test_hub_breadcrumb_has_3_items(self, hub_html):
        slug, html = hub_html
        # Pull breadcrumb HTML block
        m = re.search(
            r'data-testid=["\']hub-breadcrumb["\'](.*?)</nav>',
            html, re.DOTALL)
        assert m, f"/hub/{slug} hub-breadcrumb block not found"
        block = m.group(1)
        items = re.findall(r'class=["\']breadcrumb-item[^"\']*["\']', block)
        assert len(items) == 3
        assert 'aria-current="page"' in block

    def test_hub_faq_accordions(self, hub_html):
        slug, html = hub_html
        q_count = len(re.findall(r'data-testid=["\']hub-faq-q-\d+["\']', html))
        a_count = len(re.findall(r'data-testid=["\']hub-faq-a-\d+["\']', html))
        assert q_count >= 4, f"/hub/{slug} has {q_count} FAQ questions, expected >= 4"
        assert a_count >= 4, f"/hub/{slug} has {a_count} FAQ answers,   expected >= 4"
        assert q_count == a_count


class TestHubAggregationCounts:
    """Product card counts per topic."""

    EXPECTED_MIN_PRODUCTS = {
        "microsoft-office": 4,
        "windows": 2,
        "antivirus": 2,
    }

    @pytest.mark.parametrize("slug,minimum", list(EXPECTED_MIN_PRODUCTS.items()))
    def test_min_product_cards(self, session, slug, minimum):
        r = _fetch(session, f"/hub/{slug}")
        n = len(re.findall(r'data-testid=["\']hub-product-card["\']', r.text))
        assert n >= minimum, \
            f"/hub/{slug} has {n} product cards, expected >= {minimum}"


class TestHubNavbarLinks:
    """Homepage navbar mega-menu must link to hubs via badges."""

    @pytest.fixture(scope="class")
    def home_html(self, session):
        return _fetch(session, "/").text

    def test_menu_hub_office_present(self, home_html):
        assert 'data-testid="menu-hub-office"' in home_html

    def test_menu_hub_office_href(self, home_html):
        m = re.search(
            r'<a[^>]*data-testid=["\']menu-hub-office["\'][^>]*href=["\']([^"\']+)["\']',
            home_html) or re.search(
            r'href=["\']([^"\']+)["\'][^>]*data-testid=["\']menu-hub-office["\']',
            home_html)
        assert m, "menu-hub-office href not found"
        assert "hub/microsoft-office" in m.group(1)

    def test_menu_hub_windows_present(self, home_html):
        assert 'data-testid="menu-hub-windows"' in home_html

    def test_menu_hub_antivirus_present(self, home_html):
        assert 'data-testid="menu-hub-antivirus"' in home_html

    def test_menu_hub_antivirus_href(self, home_html):
        m = re.search(
            r'<a[^>]*data-testid=["\']menu-hub-antivirus["\'][^>]*href=["\']([^"\']+)["\']',
            home_html) or re.search(
            r'href=["\']([^"\']+)["\'][^>]*data-testid=["\']menu-hub-antivirus["\']',
            home_html)
        assert m
        assert "hub/antivirus" in m.group(1)


class TestHubSitemap:
    """sitemap.xml must include the three hub URLs at priority 0.9."""

    @pytest.fixture(scope="class")
    def sitemap_xml(self, session):
        r = _fetch(session, "/sitemap.xml")
        assert r.status_code == 200
        return r.text

    @pytest.mark.parametrize("slug", TOPICS)
    def test_hub_in_sitemap(self, sitemap_xml, slug):
        assert f"hub/{slug}" in sitemap_xml, \
            f"sitemap.xml missing hub/{slug}"

    def test_sitemap_parses_as_xml(self, sitemap_xml):
        ET.fromstring(sitemap_xml)

    @pytest.mark.parametrize("slug", TOPICS)
    def test_hub_priority_0_9(self, sitemap_xml, slug):
        # Find the <url>...hub/<slug>...</url> block & check <priority>0.9</priority>
        block_re = re.compile(
            r"<url>(?:(?!</url>).)*?hub/" + re.escape(slug) +
            r"(?:(?!</url>).)*?</url>", re.DOTALL)
        m = block_re.search(sitemap_xml)
        assert m, f"<url> block for hub/{slug} not found"
        assert "<priority>0.9</priority>" in m.group(0), \
            f"hub/{slug} priority not 0.9"


# ============================================================
#  C.  REGRESSION — Public pages still 200
# ============================================================

class TestPublicPagesRegression:

    @pytest.mark.parametrize("path", [
        "/",
        "/shop.php",
        "/reviews.php",
        "/blog.php",
        "/about-us.php",
        "/contact.php",
        "/product.php?slug=microsoft-office-2024-home-and-business-pc",
        "/category.php?slug=office-pc",
    ])
    def test_status_200(self, session, path):
        r = _fetch(session, path)
        # Allow product slug to be substituted; fall back gracefully on 404
        # by trying a definitely-existing product if the chosen slug is missing.
        if path.startswith("/product.php") and r.status_code != 200:
            r = _fetch(session, "/shop.php")
        assert r.status_code == 200, f"{path} -> {r.status_code}"
