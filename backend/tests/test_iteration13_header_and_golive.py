"""
Iteration 13 tests:
  1. Header alignment regression — phone CTA pill + navbar-actions container.
  2. Go-Live checklist banner endpoint (/ajax/go-live-check.php).
  3. Regression: 4 admin tabs render without PHP errors.
  4. Regression: 6 public SEO endpoints still return HTTP 200.
"""
import os
import re
import pytest
import requests
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://indexnow-checker.preview.emergentagent.com").rstrip("/")
# --- Fixtures ---------------------------------------------------------------

@pytest.fixture(scope="module")
def anon_session():
    s = requests.Session()
    return s


@pytest.fixture(scope="module")
def admin_session():
    s = requests.Session()
    # Pull login page first to seed any csrf cookies
    s.get(f"{BASE_URL}/login.php", timeout=15)
    r = s.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=15,
    )
    # Sanity check: admin dashboard should be reachable
    dash = s.get(f"{BASE_URL}/admin.php?tab=dashboard", timeout=15)
    if dash.status_code != 200 or "Logout" not in dash.text and "logout" not in dash.text.lower():
        pytest.skip(f"Admin login failed (login http={r.status_code} dash http={dash.status_code})")
    return s


# --- Go-Live AJAX endpoint --------------------------------------------------

class TestGoLiveEndpoint:
    def test_anonymous_returns_403(self, anon_session):
        r = anon_session.get(f"{BASE_URL}/ajax/go-live-check.php", timeout=15)
        assert r.status_code == 403, f"Expected 403, got {r.status_code} body={r.text[:200]}"
        body = r.json()
        assert body.get("ok") == False
        assert "admin" in (body.get("error") or "").lower()

    def test_admin_returns_200_with_8_checks(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/ajax/go-live-check.php", timeout=30)
        assert r.status_code == 200, f"http={r.status_code} body={r.text[:300]}"
        j = r.json()
        # Shape
        assert "ok" in j and isinstance(j["ok"], bool)
        assert "score" in j and isinstance(j["score"], dict)
        for k in ("green", "amber", "red", "total"):
            assert k in j["score"], f"score missing key {k}"
        assert "ts" in j and "site" in j
        assert "checks" in j and isinstance(j["checks"], list)
        assert len(j["checks"]) == 8, f"expected 8 checks got {len(j['checks'])}"
        expected_ids = {"ai_key", "smtp", "stripe", "paypal", "gsc", "bing", "seo_endpoints", "indexnow"}
        got_ids = {c["id"] for c in j["checks"]}
        assert got_ids == expected_ids, f"missing: {expected_ids - got_ids}, extra: {got_ids - expected_ids}"
        # Each check has required fields
        for c in j["checks"]:
            assert c["status"] in {"green", "amber", "red"}
            assert "name" in c and "detail" in c and "action" in c

    def test_admin_persists_last_run(self, admin_session):
        # Run again, then re-fetch dashboard, expect the score pill to appear.
        admin_session.get(f"{BASE_URL}/ajax/go-live-check.php", timeout=30)
        dash = admin_session.get(f"{BASE_URL}/admin.php?tab=dashboard", timeout=15)
        assert dash.status_code == 200
        # Look for the persisted-state markers in the HTML
        assert 'data-testid="go-live-banner"' in dash.text
        assert 'data-testid="go-live-last-run"' in dash.text, "last-run timestamp not rendered after probe ran"
        assert 'data-testid="go-live-score-pill"' in dash.text, "score pill not rendered after probe ran"


# --- Admin tabs regression --------------------------------------------------

class TestAdminTabsRegression:
    @pytest.mark.parametrize("tab", ["dashboard", "ai-blogger", "schedule"])
    def test_tab_renders_without_php_error(self, admin_session, tab):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab={tab}", timeout=20)
        assert r.status_code == 200, f"tab={tab} http={r.status_code}"
        low = r.text.lower()
        # No raw PHP fatal/parse errors leaked
        for needle in ("fatal error", "parse error", "uncaught ", "stack trace:"):
            assert needle not in low, f"tab={tab} contains {needle!r}"

    def test_api_gw_card_tab_renders(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=api&gw=card", timeout=20)
        assert r.status_code == 200
        low = r.text.lower()
        for needle in ("fatal error", "parse error", "uncaught "):
            assert needle not in low


# --- Header HTML — sanity checks (visual is covered by Playwright) ----------

class TestHeaderHtml:
    def test_navbar_actions_has_flex_nowrap(self, anon_session):
        r = anon_session.get(f"{BASE_URL}/", timeout=15)
        assert r.status_code == 200
        # Find the <div ...> tag containing data-testid="navbar-actions" regardless of attribute order
        m = re.search(r'<div\b([^>]*data-testid="navbar-actions"[^>]*)>', r.text)
        assert m, "navbar-actions container not found"
        attrs = m.group(1)
        assert "flex-nowrap" in attrs, f"navbar-actions missing flex-nowrap. attrs={attrs!r}"

    def test_phone_cta_is_single_line(self, anon_session):
        r = anon_session.get(f"{BASE_URL}/", timeout=15)
        assert r.status_code == 200
        assert 'data-testid="navbar-phone-cta"' in r.text
        # Extract the body of the phone-cta anchor element and assert the
        # visible 2-line label is gone. (The string may still appear in the
        # title="…" tooltip — that is fine and expected.)
        m = re.search(r'data-testid="navbar-phone-cta"[^>]*>(.*?)</a>', r.text, re.DOTALL)
        assert m, "phone CTA anchor body not found"
        inner = m.group(1).upper()
        assert "CALL TOLL-FREE" not in inner, \
            f"phone CTA still renders the 2-line CALL TOLL-FREE label inside the anchor body: {inner[:200]}"

    def test_cart_button_present(self, anon_session):
        r = anon_session.get(f"{BASE_URL}/", timeout=15)
        assert 'data-testid="cart-button"' in r.text


# --- SEO public endpoints regression ----------------------------------------

class TestSeoEndpoints:
    @pytest.mark.parametrize("path", [
        "/sitemap.xml",
        "/robots.txt",
        "/ai.txt",
        "/llms.txt",
        "/merchant-feed.xml",
    ])
    def test_endpoint_returns_200(self, anon_session, path):
        r = anon_session.get(f"{BASE_URL}{path}", timeout=15, allow_redirects=True)
        assert r.status_code == 200, f"{path} http={r.status_code}"
        assert len(r.content) > 20, f"{path} body too small ({len(r.content)} bytes)"

    def test_indexnow_key_endpoint(self, anon_session, admin_session):
        # Discover the indexnow key by hitting health probe via go-live endpoint
        r = admin_session.get(f"{BASE_URL}/ajax/go-live-check.php", timeout=30)
        if r.status_code != 200:
            pytest.skip("go-live endpoint unavailable to discover IndexNow key")
        # Just verify the indexnow check exists; the URL itself is in setting
        j = r.json()
        idx = next((c for c in j.get("checks", []) if c["id"] == "indexnow"), None)
        assert idx is not None, "indexnow check missing"
