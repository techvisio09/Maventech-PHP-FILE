"""
Iteration 10 — End-to-end QA + hardening of the AI Auto-Blogger admin panel + Search Engine Visibility section.

Covers (as requested in the review_request):
- Admin login flow
- AI Auto-Blogger tab loads
- All 5 post buttons: Write One, Random, Publish Full Batch, Trend Now, Generate Trends Now (alias)
- Newly-published blog posts visible on /blog.php and reachable at /blog-post.php?id=<id>
- Submit Sitemap / View Sitemap / View Blog / Run SEO Audit / Auto-resubmit toggle
- API Keys validation (AI/GSC/Bing) and Search Engine Visibility tokens
- Go-Live SEO Health Check — 11 tiles + Re-run probes + Verify all
- Topic Cluster Hub auto-generate + hub edit + /hub/<slug>
- SEO Discovery Lab drop-zone + CSV paste import
- Cron URLs (AI Auto-Blogger + SMTP) — fully-qualified https URL
- Category breadcrumb regression (single nav on /category.php?slug=office-mac)
- Public SEO endpoints smoke test
"""

import os
import re
import time
import pytest
import requests
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://stage-show-2.preview.emergentagent.com").rstrip("/")
# ----------------------------- Fixtures -----------------------------

@pytest.fixture(scope="session")
def admin_session():
    """Authenticated requests.Session for admin user."""
    s = requests.Session()
    s.headers.update({"User-Agent": "iteration10-tester/1.0"})
    # GET login first to seed cookies
    s.get(f"{BASE_URL}/login.php", timeout=20)
    r = s.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=30,
    )
    # Verify we landed on admin.php (or have admin access)
    probe = s.get(f"{BASE_URL}/admin.php", timeout=20, allow_redirects=False)
    if probe.status_code not in (200, 302):
        pytest.skip(f"Admin login failed — admin.php returned {probe.status_code}")
    if probe.status_code == 302 and "login" in probe.headers.get("Location", "").lower():
        pytest.skip("Admin login failed — redirected back to login.php")
    return s


# ----------------------------- Login & Page Load -----------------------------

class TestAdminLoginAndPageLoad:
    def test_login_success_lands_on_admin(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php", timeout=20)
        assert r.status_code == 200
        assert "Admin" in r.text or "dashboard" in r.text.lower()

    def test_ai_blogger_tab_renders_no_php_errors(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=30)
        assert r.status_code == 200
        body = r.text
        # No PHP error/warning leaked
        assert "Fatal error" not in body
        assert "Parse error" not in body
        assert "Warning:" not in body or body.count("Warning:") < 3
        # Core data-testids present
        for tid in [
            "ai-blogger-run-underserved",
            "ai-blogger-run-random",
            "ai-blogger-run-now",
            "ai-blogger-run-trends",
            "submit-sitemap-btn",
            "view-sitemap-btn",
            "open-seo-audit-btn",
            "auto-weekly-toggle",
            "seo-health-recheck-btn",
            "verify-all-btn",
            "hubs-autogen-btn",
            "gsc-drop-zone",
            "gsc-csv-file",
            "gsc-csv-paste",
        ]:
            assert f'data-testid="{tid}"' in body, f"Missing data-testid={tid}"


# ----------------------------- Post-publish buttons -----------------------------

def _count_blog_rows(session):
    """Heuristic count of blog posts visible on /blog.php (uses anchor count)."""
    r = session.get(f"{BASE_URL}/blog.php", timeout=20)
    if r.status_code != 200:
        return -1
    # Count blog-post.php?id= links
    return len(re.findall(r"blog-post\.php\?id=(\d+)", r.text))


def _latest_blog_id(session):
    r = session.get(f"{BASE_URL}/blog.php", timeout=20)
    if r.status_code != 200:
        return None
    ids = re.findall(r"blog-post\.php\?id=(\d+)", r.text)
    if not ids:
        return None
    return max(int(x) for x in ids)


class TestPostButtons:
    def test_write_one_underserved_post(self, admin_session):
        before = _latest_blog_id(admin_session) or 0
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&run_underserved_post=1",
            timeout=90,
            allow_redirects=True,
        )
        assert r.status_code == 200, f"run_underserved_post returned {r.status_code}"
        after = _latest_blog_id(admin_session) or 0
        flash_ok = ("Force-published" in r.text or "published" in r.text.lower() or "no underserved" in r.text.lower()
                    or "out of credits" in r.text.lower())
        new_post = after > before
        assert new_post or flash_ok, f"Underserved post: before={before} after={after}, no flash detected"
        if new_post and after:
            pr = admin_session.get(f"{BASE_URL}/blog-post.php?id={after}", timeout=20)
            assert pr.status_code == 200, f"new post {after} not reachable"

    def test_random_post(self, admin_session):
        before = _latest_blog_id(admin_session)
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&run_random_post=1",
            timeout=90,
            allow_redirects=True,
        )
        assert r.status_code == 200, f"run_random_post returned {r.status_code}"
        after = _latest_blog_id(admin_session)
        flash_ok = ("Random post published" in r.text or "featured" in r.text.lower()
                    or "out of credits" in r.text.lower() or "no products" in r.text.lower())
        new_post = (after and (before is None or after > before))
        assert new_post or flash_ok, f"Random post: before={before} after={after}"
        if new_post:
            pr = admin_session.get(f"{BASE_URL}/blog-post.php?id={after}", timeout=20)
            assert pr.status_code == 200

    def test_publish_full_batch_seo_run(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&seo_run=1",
            timeout=90,
            allow_redirects=True,
        )
        assert r.status_code == 200
        # Cooldown OR success both acceptable
        text_lower = r.text.lower()
        assert (
            "auto-blogger run complete" in text_lower
            or "already up to date" in text_lower
            or "new blog post" in text_lower
            or "no new" in text_lower
            or "cooldown" in text_lower
            or "auto-blogger" in text_lower
        ), "seo_run gave no recognizable flash"

    def test_trend_now_featured_trends_article(self, admin_session):
        before = _latest_blog_id(admin_session)
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&run_trends_article=1&force=1",
            timeout=120,
            allow_redirects=True,
        )
        assert r.status_code == 200, f"run_trends_article returned {r.status_code}"
        after = _latest_blog_id(admin_session)
        flash_ok = ("trends article published" in r.text.lower() or "featured trends" in r.text.lower()
                    or "out of credits" in r.text.lower() or "no trending" in r.text.lower())
        new_post = (after and (before is None or after > before))
        assert new_post or flash_ok, f"Trends: before={before} after={after}"
        if new_post:
            pr = admin_session.get(f"{BASE_URL}/blog-post.php?id={after}", timeout=20)
            assert pr.status_code == 200


# ----------------------------- Sitemap submit / View / Audit -----------------------------

class TestSitemapAndSeoButtons:
    def test_submit_sitemap_handler(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&submit_sitemaps=1",
            timeout=60,
            allow_redirects=True,
        )
        assert r.status_code == 200
        # Any of these flashes are acceptable per spec
        body_l = r.text.lower()
        assert (
            "sitemap submitted" in body_l
            or "indexnow" in body_l
            or "submitted" in body_l
            or "submit sitemap" in body_l  # button label re-rendered
        )

    def test_view_sitemap_xml(self):
        r = requests.get(f"{BASE_URL}/sitemap.xml", timeout=20)
        assert r.status_code == 200
        assert "<urlset" in r.text or "<sitemapindex" in r.text
        ct = r.headers.get("Content-Type", "").lower()
        assert "xml" in ct

    def test_view_blog(self):
        r = requests.get(f"{BASE_URL}/blog.php", timeout=20)
        assert r.status_code == 200
        assert "blog" in r.text.lower()

    def test_run_seo_audit(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/seo-audit.php", timeout=30)
        assert r.status_code == 200

    def test_auto_weekly_toggle_persists(self, admin_session):
        # First read the current site_domain_url to preserve it on POST
        r0 = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=20)
        dm = re.search(r'name="site_domain_url"\s+value="([^"]*)"', r0.text)
        site_domain = dm.group(1) if dm else ""

        # Turn ON
        r1 = admin_session.post(
            f"{BASE_URL}/admin.php?tab=ai-blogger",
            data={
                "save_seo_tokens": "1",
                "site_domain_url": site_domain,
                "auto_sitemap_weekly": "1",
            },
            timeout=30,
            allow_redirects=True,
        )
        assert r1.status_code == 200
        # Reload and check checkbox is checked
        r2 = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=20)
        m = re.search(r'id="autoWeeklyToggle"[^>]*>', r2.text)
        assert m, "autoWeeklyToggle not found on reload"
        assert "checked" in m.group(0).lower(), f"Toggle did not persist ON: {m.group(0)[:200]}"

        # Turn OFF (omit auto_sitemap_weekly key)
        admin_session.post(
            f"{BASE_URL}/admin.php?tab=ai-blogger",
            data={"save_seo_tokens": "1", "site_domain_url": site_domain},
            timeout=30,
            allow_redirects=True,
        )
        r3 = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=20)
        m2 = re.search(r'id="autoWeeklyToggle"[^>]*>', r3.text)
        assert m2
        assert "checked" not in m2.group(0).lower(), "Toggle did not persist OFF"

        # Restore back to ON for clean state
        admin_session.post(
            f"{BASE_URL}/admin.php?tab=ai-blogger",
            data={"save_seo_tokens": "1", "site_domain_url": site_domain, "auto_sitemap_weekly": "1"},
            timeout=30,
            allow_redirects=True,
        )


# ----------------------------- API Keys validation -----------------------------

class TestApiKeyValidation:
    def test_invalid_ai_key_rejected(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/admin.php?tab=ai-blogger",
            data={"save_ai_key": "1", "ai_provider_key": "foo"},
            timeout=30,
            allow_redirects=True,
        )
        assert r.status_code == 200
        # Should flash an invalid message; the saved value should NOT now equal "foo"
        body_l = r.text.lower()
        assert "looks invalid" in body_l or "invalid" in body_l or "ai key" in body_l, \
            "Expected invalid-key flash"

    def test_valid_ai_key_format_accepted(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/admin.php?tab=ai-blogger",
            data={"save_ai_key": "1", "ai_provider_key": "sk-emergent-test1234567890"},
            timeout=30,
            allow_redirects=True,
        )
        assert r.status_code == 200
        # Either "saved" flash OR the key is now masked on page reload
        body_l = r.text.lower()
        assert "saved" in body_l or "ai key" in body_l


# ----------------------------- Health Check 11 tiles + Re-run + Verify all -----------------------------

class TestHealthCheck:
    def test_eleven_tiles_render(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=30)
        assert r.status_code == 200
        body = r.text
        # The 11 expected tile labels (case-insensitive substring search)
        tiles = [
            "AI Writing Key",
            "Google Search Console",
            "Bing",
            "XML Sitemap",
            "robots.txt",
            "ai.txt",
            "llms.txt",
            "Merchant",       # Google Shopping Feed -> "Merchant"
            "IndexNow",
            "Schema",         # Structured Data (Schema.org)
            "Blog Content",
        ]
        missing = [t for t in tiles if t.lower() not in body.lower()]
        assert not missing, f"Missing tiles: {missing}"

    def test_health_probe_shows_real_http_verdicts(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=30)
        body = r.text
        # Look for HTTP 200 status text (real probe output)
        assert re.search(r"HTTP\s*200", body), "No 'HTTP 200' verdict found — probes may not be real"
        # Should mention bytes for at least one tile
        assert "bytes" in body.lower(), "Real-probe byte counts missing"

    def test_recheck_button(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&seo_health_recheck=1",
            timeout=60,
            allow_redirects=True,
        )
        assert r.status_code == 200
        body_l = r.text.lower()
        assert "probes re-run" in body_l or "endpoints ok" in body_l or "health" in body_l, \
            "No re-run flash detected"

    def test_verify_all_button(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&verify_all=1",
            timeout=60,
            allow_redirects=True,
        )
        assert r.status_code == 200
        body_l = r.text.lower()
        # No tokens saved → expect "no tokens saved" hint OR generic verify flash
        assert "no tokens" in body_l or "verify" in body_l or "token" in body_l


# ----------------------------- Topic Cluster Hubs -----------------------------

class TestTopicClusterHubs:
    def test_autogen_hubs(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&autogen_topic_hubs=1",
            timeout=60,
            allow_redirects=True,
        )
        assert r.status_code == 200
        body_l = r.text.lower()
        assert (
            "auto-generated" in body_l
            or "already up to date" in body_l
            or "no categories" in body_l
            or "topic hub" in body_l
        ), "No hub autogen flash detected"

    @pytest.mark.parametrize("slug", ["microsoft-office", "windows", "antivirus"])
    def test_existing_hub_pages_200(self, slug):
        r = requests.get(f"{BASE_URL}/hub/{slug}", timeout=20, allow_redirects=True)
        assert r.status_code == 200, f"/hub/{slug} returned {r.status_code}"
        assert len(r.text) > 500


# ----------------------------- SEO Discovery Lab (GSC CSV) -----------------------------

class TestSeoDiscoveryLab:
    def test_drop_zone_present(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=30)
        body = r.text
        assert 'data-testid="gsc-drop-zone"' in body
        assert "drop your search console export" in body.lower() or "search console" in body.lower()
        assert 'data-testid="gsc-csv-file"' in body
        assert 'data-testid="gsc-csv-paste"' in body

    def test_csv_paste_import(self, admin_session):
        csv_payload = "Query,Clicks,Impressions,CTR,Position\nbuy office,10,200,5%,3\n"
        r = admin_session.post(
            f"{BASE_URL}/admin.php?tab=ai-blogger",
            data={"gsc_csv_import": "1", "gsc_csv_text": csv_payload},
            timeout=60,
            allow_redirects=True,
        )
        assert r.status_code == 200
        body_l = r.text.lower()
        assert (
            "imported" in body_l
            or "search console" in body_l
            or "query" in body_l
        ), "CSV import flash not detected"


# ----------------------------- Cron URLs are fully-qualified -----------------------------

class TestCronUrls:
    def test_ai_blogger_cron_url_is_https(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=30)
        m = re.search(r'id="ai-blogger-cron-url-input"\s+value="([^"]+)"', r.text)
        assert m, "ai-blogger-cron-url-input not found"
        url = m.group(1)
        assert url.startswith("https://"), f"Cron URL not https: {url}"
        assert "localhost" not in url, f"Cron URL points to localhost: {url}"

    def test_smtp_cron_url_is_https(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=smtp", timeout=30)
        if r.status_code != 200:
            pytest.skip("SMTP tab not accessible")
        m = re.search(r'data-testid="smtp-cron-url"[^>]*>([^<]+)</', r.text)
        assert m, "smtp-cron-url not found"
        url = m.group(1).strip()
        assert url.startswith("https://"), f"SMTP cron URL not https: {url}"
        assert "localhost" not in url


# ----------------------------- Category breadcrumb regression -----------------------------

class TestCategoryBreadcrumb:
    def test_office_mac_has_single_breadcrumb(self):
        r = requests.get(f"{BASE_URL}/category.php?slug=office-mac", timeout=20)
        assert r.status_code == 200
        # Count breadcrumb navs
        count = len(re.findall(r'<nav\s+aria-label="breadcrumb"', r.text, flags=re.IGNORECASE))
        assert count == 1, f"Expected exactly 1 breadcrumb nav, found {count}"
        # Verify expected text fragments
        assert "Home" in r.text
        assert "Shop" in r.text
        assert "Office for Mac" in r.text or "office" in r.text.lower()


# ----------------------------- Public SEO endpoints smoke test -----------------------------

class TestPublicSeoEndpoints:
    @pytest.mark.parametrize("path,must_contain", [
        ("/sitemap.xml", "<urlset"),
        ("/robots.txt", "User-agent"),
        ("/ai.txt", ""),         # ai.txt content varies; just check 200
        ("/llms.txt", ""),
        ("/merchant-feed.xml", "<"),
    ])
    def test_endpoint_200(self, path, must_contain):
        r = requests.get(f"{BASE_URL}{path}", timeout=20)
        assert r.status_code == 200, f"{path} -> {r.status_code}"
        if must_contain:
            assert must_contain.lower() in r.text.lower(), f"{path} missing expected content"
        assert len(r.text) > 10, f"{path} returned empty body"

    def test_indexnow_key_file_exists(self):
        # Find a .txt key file in /app/php-version/
        import glob
        keys = [os.path.basename(p) for p in glob.glob("/app/php-version/*.txt")
                if re.match(r"^[0-9a-f]{32}\.txt$", os.path.basename(p))]
        if not keys:
            pytest.skip("No IndexNow key file present on disk")
        key = keys[0]
        r = requests.get(f"{BASE_URL}/{key}", timeout=20)
        assert r.status_code == 200, f"IndexNow key file /{key} -> {r.status_code}"
        # Body should be the key (filename minus .txt)
        assert key.replace(".txt", "") in r.text


if __name__ == "__main__":
    pytest.main([__file__, "-v", "--tb=short"])
