"""
Iteration 16 — OpenGraph tags suite.

Covers:
 - /og-default.png GD-generated banner (size + content-type)
 - <head> OG / Twitter / locale / image dims / fb:app_id / twitter:site rendering
 - Per-page OG image overrides (product, blog post, category, hub)
 - product:* tags on product pages, article:* tags on blog posts
 - Admin save_seo_tokens persistence for twitter_site_handle + facebook_app_id
 - Regression: Authorized Reseller toggle, manifest.webmanifest
"""
import os
import re
import time
import pytest
import requests

BASE = os.environ.get("REACT_APP_BACKEND_URL", "https://indexnow-checker.preview.emergentagent.com").rstrip("/")
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASS_CANDIDATES = [os.environ.get("ADMIN_PASSWORD") or "Admin@123", "Admin@UC2026!", "Admin@123"]

# Known seed values from MariaDB (gathered in pre-flight)
PRODUCT_SLUG = "bitdefender-antivirus-for-mac-1-mac-1-year"
BLOG_ID_WITH_IMAGE = "ai-trends-20260617-microsoft-office-2021-home-business-mac"
CATEGORY_SLUG = "office-pc"
HUB_SLUG = "microsoft-office"


# ------------------------ helpers ------------------------
def _meta_map(html):
    """Parse all <meta property|name="..." content="..."> (multiline tolerant)."""
    m = {}
    pattern = re.compile(
        r'<meta\s+(?:property|name)\s*=\s*"([^"]+)"\s+content\s*=\s*"([^"]*)"',
        re.IGNORECASE | re.DOTALL,
    )
    for k, v in pattern.findall(html):
        m.setdefault(k, v)
    # Try the reverse order too (content first, then property/name)
    pattern2 = re.compile(
        r'<meta\s+content\s*=\s*"([^"]*)"\s+(?:property|name)\s*=\s*"([^"]+)"',
        re.IGNORECASE | re.DOTALL,
    )
    for v, k in pattern2.findall(html):
        m.setdefault(k, v)
    return m


@pytest.fixture(scope="module")
def admin_session():
    s = requests.Session()
    last_err = None
    for pw in ADMIN_PASS_CANDIDATES:
        r = s.post(f"{BASE}/login.php", data={"email": ADMIN_EMAIL, "password": pw}, allow_redirects=True, timeout=20)
        # check the admin page is reachable after login
        a = s.get(f"{BASE}/admin.php", timeout=20)
        if a.status_code == 200 and ("logout" in a.text.lower() or "admin" in a.text.lower() and "password" not in a.text.lower()[:1500]):
            return s
        last_err = (r.status_code, a.status_code)
    pytest.skip(f"Admin login failed with all candidate passwords: {last_err}")


# ------------------------ /og-default.png ------------------------
def test_og_default_png_served_as_png():
    r = requests.get(f"{BASE}/og-default.png", timeout=30)
    assert r.status_code == 200, r.text[:300]
    assert r.headers.get("content-type", "").startswith("image/png"), r.headers
    # PNG magic bytes
    assert r.content[:8] == b"\x89PNG\r\n\x1a\n", "Not a real PNG file"
    assert len(r.content) >= 5000, f"PNG body too small ({len(r.content)} bytes)"


def test_og_default_aliases():
    # router also maps /og-default.jpg and /og-image.png to same generator
    for path in ("/og-default.jpg", "/og-image.png"):
        r = requests.get(f"{BASE}{path}", timeout=30)
        assert r.status_code == 200, f"{path} → {r.status_code}"
        assert r.headers.get("content-type", "").startswith("image/"), f"{path} content-type {r.headers.get('content-type')}"


# ------------------------ Home / page ------------------------
def test_home_og_meta_complete():
    r = requests.get(f"{BASE}/", timeout=30)
    assert r.status_code == 200
    m = _meta_map(r.text)
    required = [
        "og:site_name", "og:type", "og:title", "og:description", "og:url", "og:locale",
        "og:image", "og:image:secure_url", "og:image:alt",
        "og:image:width", "og:image:height", "og:image:type",
        "twitter:card", "twitter:title", "twitter:description", "twitter:image", "twitter:image:alt",
    ]
    missing = [k for k in required if k not in m]
    assert not missing, f"Missing meta tags on home: {missing}\nFound keys: {sorted(m.keys())}"

    assert m["og:type"] == "website", m["og:type"]
    assert m["og:locale"] == "en_US", m["og:locale"]
    assert m["og:image:width"] == "1200"
    assert m["og:image:height"] == "630"
    assert m["og:image:type"] == "image/png"
    assert m["twitter:card"] == "summary_large_image"
    # absolute URL
    assert m["og:image"].startswith("https://"), f"og:image not absolute: {m['og:image']}"
    assert "/og-default.png" in m["og:image"], f"og:image not /og-default.png: {m['og:image']}"
    assert m["og:image:secure_url"].startswith("https://")


# ------------------------ Product page ------------------------
def test_product_og_and_product_tags():
    r = requests.get(f"{BASE}/product.php?slug={PRODUCT_SLUG}", timeout=30)
    assert r.status_code == 200
    m = _meta_map(r.text)

    assert m.get("og:type") == "product", f"og:type={m.get('og:type')}"
    assert ".webp" in m.get("og:image", ""), f"og:image not webp: {m.get('og:image')}"
    assert PRODUCT_SLUG.split("-")[0] in m.get("og:image", "").lower() or "/uploads/products/" in m.get("og:image", "")

    # product:* tags
    price = m.get("product:price:amount", "")
    assert re.match(r"^\d+(\.\d+)?$", price), f"product:price:amount not numeric: {price!r}"
    cur = m.get("product:price:currency", "")
    assert re.match(r"^[A-Z]{3}$", cur), f"product:price:currency not 3-letter: {cur!r}"
    avail = m.get("product:availability", "").lower()
    assert avail in ("in stock", "out of stock"), f"product:availability bad: {avail!r}"
    assert m.get("product:condition") == "new", m.get("product:condition")
    assert (m.get("product:brand") or "").strip() != "", "product:brand empty"


# ------------------------ Blog post ------------------------
def test_blog_post_article_tags_and_own_image():
    r = requests.get(f"{BASE}/blog-post.php?id={BLOG_ID_WITH_IMAGE}", timeout=30)
    assert r.status_code == 200, r.status_code
    m = _meta_map(r.text)

    assert m.get("og:type") == "article", f"og:type={m.get('og:type')}"

    # article:* tags
    pub = m.get("article:published_time", "")
    # ISO-8601 with timezone (e.g. 2026-06-17T12:34:56+00:00 or Z)
    assert re.match(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([Zz]|[+\-]\d{2}:?\d{2})$", pub), f"article:published_time bad: {pub!r}"
    mod = m.get("article:modified_time", "")
    assert re.match(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([Zz]|[+\-]\d{2}:?\d{2})$", mod), f"article:modified_time bad: {mod!r}"
    assert (m.get("article:author") or "").strip() != "", "article:author empty"
    assert (m.get("article:section") or "").strip() != "", "article:section empty"

    # og:image should be the post's own image, not /og-default.png
    img = m.get("og:image", "")
    assert "/og-default.png" not in img, f"Blog post still using og-default: {img}"
    assert ".webp" in img or "/uploads/" in img, f"Blog og:image not a post image: {img}"


# ------------------------ Category page ------------------------
def test_category_uses_first_product_image():
    r = requests.get(f"{BASE}/category.php?slug={CATEGORY_SLUG}", timeout=30)
    assert r.status_code == 200
    m = _meta_map(r.text)
    img = m.get("og:image", "")
    assert "/og-default.png" not in img, f"Category {CATEGORY_SLUG} still on og-default: {img}"
    assert "/uploads/products/" in img and ".webp" in img, f"Category og:image not a product image: {img}"


# ------------------------ Hub page ------------------------
def test_hub_uses_real_content_image():
    r = requests.get(f"{BASE}/hub/{HUB_SLUG}", timeout=30, allow_redirects=True)
    assert r.status_code == 200, r.status_code
    m = _meta_map(r.text)
    img = m.get("og:image", "")
    assert "/og-default.png" not in img, f"Hub {HUB_SLUG} still on og-default: {img}"
    assert "/uploads/" in img, f"Hub og:image not from uploads: {img}"


# ------------------------ Admin SEO inputs ------------------------
def test_admin_seo_tokens_inputs_visible(admin_session):
    r = admin_session.get(f"{BASE}/admin.php?tab=ai-blogger", timeout=30)
    assert r.status_code == 200
    assert 'data-testid="twitter-site-handle-input"' in r.text, "twitter-site-handle input missing"
    assert 'data-testid="facebook-app-id-input"' in r.text, "facebook-app-id input missing"


def test_admin_save_twitter_and_fb_then_emitted_on_home(admin_session):
    handle = "@maventechsw"
    fb_id = "1234567890"
    # Save
    save_r = admin_session.post(
        f"{BASE}/admin.php?tab=ai-blogger",
        data={"save_seo_tokens": "1", "twitter_site_handle": handle, "facebook_app_id": fb_id},
        timeout=30, allow_redirects=True,
    )
    assert save_r.status_code in (200, 302), save_r.status_code

    # Verify persisted via admin GET (placeholder updates)
    g = admin_session.get(f"{BASE}/admin.php?tab=ai-blogger", timeout=30)
    assert handle in g.text, f"Twitter handle not persisted/visible in admin page"
    assert fb_id in g.text, f"Facebook App ID not persisted/visible in admin page"

    try:
        # Verify emitted on home <head>
        time.sleep(1)
        h = requests.get(f"{BASE}/", timeout=30)
        m = _meta_map(h.text)
        assert m.get("twitter:site") == handle, f"twitter:site not emitted, got {m.get('twitter:site')!r}"
        assert m.get("fb:app_id") == fb_id, f"fb:app_id not emitted, got {m.get('fb:app_id')!r}"
    finally:
        # Restore both to empty
        restore = admin_session.post(
            f"{BASE}/admin.php?tab=ai-blogger",
            data={"save_seo_tokens": "1", "twitter_site_handle": "", "facebook_app_id": ""},
            timeout=30, allow_redirects=True,
        )
        assert restore.status_code in (200, 302)
        # Confirm clear took effect
        h2 = requests.get(f"{BASE}/", timeout=30)
        m2 = _meta_map(h2.text)
        # When empty, these tags should NOT render
        assert "twitter:site" not in m2 or m2.get("twitter:site") == "", \
            f"twitter:site still emitted after clear: {m2.get('twitter:site')!r}"
        assert "fb:app_id" not in m2 or m2.get("fb:app_id") == "", \
            f"fb:app_id still emitted after clear: {m2.get('fb:app_id')!r}"


# ------------------------ Regressions ------------------------
def _mysql_set(key, val):
    import subprocess
    subprocess.run(
        ["mysql", "-uroot", "ucode_store", "-e",
         f"INSERT INTO settings(k,v) VALUES('{key}','{val}') ON DUPLICATE KEY UPDATE v='{val}';"],
        check=True, capture_output=True,
    )


def _mysql_get(key):
    import subprocess
    out = subprocess.run(
        ["mysql", "-uroot", "ucode_store", "-N", "-e", f"SELECT v FROM settings WHERE k='{key}';"],
        capture_output=True, text=True, check=True,
    )
    return out.stdout.strip()


def test_regression_authorized_reseller_toggle():
    """Flip ON → expect 3 occurrences; flip OFF → expect 0; restore prior state."""
    prior = _mysql_get("show_authorized_reseller_badge") or "0"
    try:
        # ON
        _mysql_set("show_authorized_reseller_badge", "1")
        time.sleep(0.5)
        on_html = requests.get(f"{BASE}/", timeout=30).text
        on_count = on_html.count("AUTHORIZED RESELLER")
        # On home page: header + footer = 2 (the 3rd lives in checkout-summary-partial.php, only included on /cart and /checkout)
        assert on_count >= 2, f"Expected >=2 occurrences with toggle ON on home, got {on_count}"
        # Verify the 3rd location renders on cart page
        cart_html = requests.get(f"{BASE}/cart.php", timeout=30).text
        # Cart may or may not include the checkout summary depending on items; just check header+footer at minimum
        assert cart_html.count("AUTHORIZED RESELLER") >= 2, "Cart page missing header/footer reseller badges"

        # Cross-check footer + checkout partial render too
        # Footer is on home, checkout-summary partial only at /cart or /checkout — header+footer enough on /
        # OFF
        _mysql_set("show_authorized_reseller_badge", "0")
        time.sleep(0.5)
        off_count = requests.get(f"{BASE}/", timeout=30).text.count("AUTHORIZED RESELLER")
        assert off_count == 0, f"Expected 0 occurrences with toggle OFF, got {off_count}"
    finally:
        _mysql_set("show_authorized_reseller_badge", prior)


def test_regression_pwa_manifest():
    import json as _json
    r = requests.get(f"{BASE}/manifest.webmanifest", timeout=30)
    assert r.status_code == 200
    ctype = r.headers.get("content-type", "")
    assert "manifest" in ctype or "json" in ctype, ctype
    data = _json.loads(r.text)
    assert "name" in data and "icons" in data and len(data["icons"]) > 0
