"""
Iteration 17 — Backend tests for:
  • per-product OG image (/og-product.png) with disk cache
  • /llms.txt blog section + cache invalidation
  • /robots.txt sitemap declarations + Host-header rewrite
  • /sitemap.xml blog-post entries (59 expected)
  • Canonical/og:url Host-header rewrite on /, /shop.php, /product.php
  • PWA manifest regression
  • Authorized Reseller toggle regression (3 vs 0 occurrences)
  • admin?tab=ai-blogger still shows twitter-site-handle-input + facebook-app-id-input
"""
import os
import re
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL").rstrip("/")
# Direct PHP origin (port 3000) — used for Host-header rewrite tests because
# the public ingress rejects non-matching Host headers with 403. The user
# explicitly noted that DNS for maventechsoftware.com does NOT need to resolve.
PHP_ORIGIN = "http://localhost:3000"
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"
PROD_SLUG = "microsoft-office-2024-professional-plus-windows"
PROD_HOST = "maventechsoftware.com"
CACHE_DIR = "/app/php-version/uploads/og"


# ---------- Per-product OG image ----------
class TestOgProduct:
    def test_og_product_live_then_cache(self):
        cache_path = f"{CACHE_DIR}/{PROD_SLUG}.png"
        # ensure fresh
        if os.path.exists(cache_path):
            os.remove(cache_path)

        r1 = requests.get(f"{BASE_URL}/og-product.png", params={"slug": PROD_SLUG},
                          timeout=30, allow_redirects=False)
        assert r1.status_code == 200, f"expected 200 got {r1.status_code}"
        assert r1.headers.get("Content-Type", "").startswith("image/png"), r1.headers
        assert len(r1.content) >= 10 * 1024, f"PNG too small ({len(r1.content)} bytes)"
        assert r1.headers.get("X-OG-Source") == "live", r1.headers.get("X-OG-Source")
        assert os.path.exists(cache_path), "cache file not written"

        # second call -> cache
        r2 = requests.get(f"{BASE_URL}/og-product.png", params={"slug": PROD_SLUG},
                          timeout=30, allow_redirects=False)
        assert r2.status_code == 200
        assert r2.headers.get("X-OG-Source") == "cache", r2.headers.get("X-OG-Source")
        assert r2.content == r1.content, "cached bytes differ from live bytes"

    def test_og_product_unknown_slug_falls_back(self):
        r = requests.get(f"{BASE_URL}/og-product.png",
                         params={"slug": "nonexistent-slug-xyz"},
                         timeout=20, allow_redirects=False)
        assert r.status_code == 302, f"expected 302 got {r.status_code}"
        loc = r.headers.get("Location", "")
        assert "/og-default.png" in loc, loc

    def test_product_page_meta_uses_og_product(self):
        r = requests.get(f"{BASE_URL}/product.php", params={"slug": PROD_SLUG}, timeout=30)
        assert r.status_code == 200
        html = r.text
        m = re.search(r'<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']', html, re.I)
        assert m, "og:image meta missing"
        assert "/og-product.png" in m.group(1), m.group(1)
        assert f"slug={PROD_SLUG}" in m.group(1).replace("%2F", "/"), m.group(1)


# ---------- /llms.txt ----------
class TestLlmsTxt:
    def test_llms_txt_has_blog_section(self):
        r = requests.get(f"{BASE_URL}/llms.txt", timeout=30)
        assert r.status_code == 200
        body = r.text
        # Source header — accept live-template OR ai-auto-blogger (per agent note);
        # primary assertion is the URL count.
        src = r.headers.get("X-LLMs-Source", "")
        assert src in ("live-template", "ai-auto-blogger"), f"unexpected X-LLMs-Source: {src}"
        assert len(body) > 30 * 1024, f"llms.txt too small ({len(body)} bytes)"
        assert "## Blog & guides" in body or "Blog & guides" in body, "blog section heading missing"
        urls = re.findall(r"blog-post\.php\?id=\d+", body)
        unique_urls = set(urls)
        assert len(unique_urls) >= 50, f"only {len(unique_urls)} unique blog-post URLs (need >=50)"


# ---------- /robots.txt ----------
class TestRobotsTxt:
    def test_robots_has_all_sitemap_lines(self):
        r = requests.get(f"{BASE_URL}/robots.txt", timeout=20)
        assert r.status_code == 200
        body = r.text
        for needle in ("/sitemap.xml", "/merchant-feed.xml", "/llms.txt", "/agents.json"):
            assert needle in body, f"robots.txt missing Sitemap entry for {needle}"
        # all Sitemap: lines
        sm = re.findall(r"^\s*Sitemap:\s*(\S+)", body, re.M)
        assert len(sm) >= 4, f"expected >=4 Sitemap lines, got {len(sm)}: {sm}"

    def test_robots_host_header_rewrite(self):
        # Public ingress 403s on non-matching Host; hit PHP origin directly.
        r = requests.get(f"{PHP_ORIGIN}/robots.txt",
                         headers={"Host": PROD_HOST},
                         timeout=20)
        assert r.status_code == 200
        sm = re.findall(r"^\s*Sitemap:\s*(\S+)", r.text, re.M)
        assert sm, "no Sitemap: lines"
        assert all(PROD_HOST in s for s in sm), \
            f"Host-header rewrite not applied to all Sitemap lines: {sm}"


# ---------- /sitemap.xml ----------
class TestSitemap:
    def test_sitemap_blog_post_count(self):
        r = requests.get(f"{BASE_URL}/sitemap.xml", timeout=30)
        assert r.status_code == 200
        body = r.text
        assert "<urlset" in body, "<urlset> root missing"
        # count blog-post.php loc entries
        locs = re.findall(r"<loc>([^<]*blog-post\.php[^<]*)</loc>", body)
        assert len(locs) == 59, f"expected 59 blog-post loc entries, got {len(locs)}"


# ---------- Canonical / og:url host rewrite ----------
class TestCanonicalHostRewrite:
    @pytest.mark.parametrize("path,params", [
        ("/", None),
        ("/shop.php", None),
        ("/product.php", {"slug": PROD_SLUG}),
    ])
    def test_canonical_uses_request_host(self, path, params):
        r = requests.get(f"{PHP_ORIGIN}{path}", params=params,
                         headers={"Host": PROD_HOST},
                         timeout=30)
        assert r.status_code == 200, f"{path} -> {r.status_code}"
        html = r.text
        canon = re.search(r'<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']', html, re.I)
        ogurl = re.search(r'<meta\s+property=["\']og:url["\']\s+content=["\']([^"\']+)["\']', html, re.I)
        assert canon, f"no canonical link on {path}"
        assert ogurl, f"no og:url meta on {path}"
        assert PROD_HOST in canon.group(1), f"{path} canonical not rewritten: {canon.group(1)}"
        assert PROD_HOST in ogurl.group(1), f"{path} og:url not rewritten: {ogurl.group(1)}"


# ---------- PWA manifest regression ----------
class TestPwaManifest:
    def test_manifest_valid(self):
        r = requests.get(f"{BASE_URL}/manifest.webmanifest", timeout=15)
        assert r.status_code == 200
        data = r.json()
        assert "icons" in data and isinstance(data["icons"], list) and data["icons"]
        assert "start_url" in data
        assert data["start_url"].endswith("?source=pwa"), data["start_url"]


# ---------- Authorized Reseller toggle regression ----------
class TestAuthorizedResellerToggle:
    def _admin_session(self):
        s = requests.Session()
        # GET login first to capture cookie/csrf if any
        s.get(f"{BASE_URL}/login.php", timeout=15)
        r = s.post(f"{BASE_URL}/login.php",
                   data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
                   timeout=20, allow_redirects=True)
        assert r.status_code in (200, 302), r.status_code
        return s

    def _set_setting(self, sess, key, val):
        # Use admin settings endpoint; try the company-info / brand tab POST.
        # Fallback: update DB directly via admin AJAX is not available, so use mysql.
        import subprocess
        subprocess.run(
            ["mysql", "-uroot", "ucode_store", "-e",
             f"INSERT INTO settings(k,v) VALUES('{key}','{val}') "
             f"ON DUPLICATE KEY UPDATE v=VALUES(v)"],
            check=True, capture_output=True
        )

    def test_reseller_toggle(self):
        sess = self._admin_session()
        # ON
        self._set_setting(sess, "show_authorized_reseller_badge", "1")
        r_on = requests.get(f"{BASE_URL}/", timeout=20,
                            headers={"Cache-Control": "no-cache"})
        on_count = len(re.findall(r"Authorized Reseller", r_on.text, re.I))
        # OFF
        self._set_setting(sess, "show_authorized_reseller_badge", "0")
        r_off = requests.get(f"{BASE_URL}/", timeout=20,
                             headers={"Cache-Control": "no-cache"})
        off_count = len(re.findall(r"Authorized Reseller", r_off.text, re.I))
        # restore ON for normal browsing
        self._set_setting(sess, "show_authorized_reseller_badge", "1")
        assert on_count == 3, f"expected 3 Authorized Reseller mentions when ON, got {on_count}"
        assert off_count == 0, f"expected 0 mentions when OFF, got {off_count}"


# ---------- Admin AI-Blogger page still has OG-twitter/facebook inputs ----------
class TestAdminAiBloggerOgInputs:
    def test_admin_ai_blogger_has_og_inputs(self):
        s = requests.Session()
        s.get(f"{BASE_URL}/login.php", timeout=15)
        r = s.post(f"{BASE_URL}/login.php",
                   data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
                   timeout=20, allow_redirects=True)
        assert r.status_code in (200, 302)
        r2 = s.get(f"{BASE_URL}/admin.php", params={"tab": "ai-blogger"}, timeout=30)
        assert r2.status_code == 200
        html = r2.text
        assert 'data-testid="twitter-site-handle-input"' in html \
            or 'id="twitter-site-handle-input"' in html \
            or 'twitter-site-handle-input' in html, "twitter-site-handle-input missing"
        assert 'facebook-app-id-input' in html, "facebook-app-id-input missing"
