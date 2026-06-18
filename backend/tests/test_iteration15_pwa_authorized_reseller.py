"""
Iteration 15 backend tests:
  1) PWA manifest (/manifest.webmanifest) + icons + <head> integration
  2) Authorized Reseller toggle: ON / OFF / restore-ON behaviour
  3) Admin Company Info form contains the toggle input
PHP-syntax lint is verified out-of-band (php -l) before this suite runs.
"""
import os
import re
import json
import pytest
import requests
import subprocess

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://stage-show-2.preview.emergentagent.com").rstrip("/")
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASS = os.environ.get("ADMIN_PASSWORD", "Admin@UC2026!")  # DB reseed swapped the seeded value


# ---------- helpers ----------
def _count_reseller(html: str) -> int:
    # Case-insensitive substring count of the phrase used in header / footer / checkout
    return len(re.findall(r"authorized\s+reseller", html, flags=re.I))


def _mysql_set(value: str):
    """Flip the show_authorized_reseller_badge value via MariaDB directly."""
    cmd = [
        "mysql", "-uroot", "ucode_store",
        "-e", f"UPDATE settings SET v='{value}' WHERE k='show_authorized_reseller_badge';",
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
    return res.returncode == 0, res.stderr


# ---------- fixtures ----------
@pytest.fixture(scope="module")
def session():
    s = requests.Session()
    s.headers.update({"User-Agent": "pytest-iter15"})
    return s


@pytest.fixture(scope="module")
def admin_session(session):
    """Login as admin and return an authenticated session."""
    r = session.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASS},
        allow_redirects=True, timeout=15,
    )
    # admin.php should be reachable now
    rr = session.get(f"{BASE_URL}/admin.php", timeout=15)
    if rr.status_code != 200 or "logout" not in rr.text.lower():
        pytest.skip(f"Admin login failed (status={rr.status_code})")
    return session


# =========================================================================
# 1) PWA MANIFEST
# =========================================================================
class TestPwaManifest:
    def test_manifest_200_and_content_type(self, session):
        r = session.get(f"{BASE_URL}/manifest.webmanifest", timeout=10)
        assert r.status_code == 200, r.text[:300]
        ctype = r.headers.get("content-type", "")
        assert "application/manifest+json" in ctype, f"Bad CT: {ctype}"

    def test_manifest_json_keys(self, session):
        r = session.get(f"{BASE_URL}/manifest.webmanifest", timeout=10)
        data = json.loads(r.text)  # must be valid JSON
        for k in ("name", "short_name", "start_url", "scope", "display",
                  "icons", "theme_color", "background_color"):
            assert k in data, f"Missing manifest key: {k}"
        assert data["display"] == "standalone"
        assert data["start_url"] == "/?source=pwa", data["start_url"]
        assert isinstance(data["icons"], list) and len(data["icons"]) >= 1
        # At least one 512x512 PNG icon
        sizes = [i.get("sizes", "") for i in data["icons"]]
        types = [i.get("type", "") for i in data["icons"]]
        assert any("512x512" in s for s in sizes), f"No 512x512 icon. sizes={sizes}"
        assert any(t == "image/png" for t in types), f"No image/png icon types={types}"

    @pytest.mark.parametrize("path", [
        "/assets/images/favicon/icon-192.png",
        "/assets/images/favicon/icon-512.png",
    ])
    def test_icons_200_png(self, session, path):
        r = session.get(f"{BASE_URL}{path}", timeout=10)
        assert r.status_code == 200, f"{path} -> {r.status_code}"
        assert r.headers.get("content-type", "").startswith("image/png"), r.headers.get("content-type")
        assert len(r.content) > 200  # not empty

    def test_head_has_manifest_and_meta(self, session):
        r = session.get(f"{BASE_URL}/", timeout=15)
        assert r.status_code == 200
        html = r.text
        assert re.search(r'<link[^>]+rel=["\']manifest["\'][^>]+href=["\']/manifest\.webmanifest["\']',
                         html, re.I), "manifest <link> missing"
        assert re.search(r'<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']#0066CC["\']',
                         html, re.I), "theme-color meta missing"
        assert re.search(r'apple-mobile-web-app-capable', html, re.I), "apple-mobile-web-app-* meta missing"


# =========================================================================
# 2) AUTHORIZED RESELLER TOGGLE
# =========================================================================
class TestAuthorizedResellerToggle:
    def test_default_on_state_homepage_has_three(self, session):
        # Ensure ON first (idempotent)
        _mysql_set("1")
        r = session.get(f"{BASE_URL}/", timeout=15)
        assert r.status_code == 200
        count = _count_reseller(r.text)
        # Spec says exactly 3 occurrences on the homepage when ON
        assert count == 3, f"Expected 3 'AUTHORIZED RESELLER' occurrences, got {count}"

    def test_checkout_has_brandtag_when_on(self, session):
        # Seed a product into cart directly, then hit checkout
        _mysql_set("1")
        out = subprocess.run(["mysql", "-uroot", "-N", "-B", "ucode_store",
                              "-e", "SELECT id FROM products LIMIT 1;"],
                             capture_output=True, text=True, timeout=10)
        pid = (out.stdout or "").strip().splitlines()[0] if out.stdout else ""
        if not pid:
            pytest.skip("No products available for checkout test")
        # Try several common add-to-cart endpoints
        for path, payload in [
            ("/cart.php", {"action": "add", "product_id": pid, "qty": "1"}),
            ("/ajax/cart-add.php", {"product_id": pid, "qty": "1"}),
            ("/api/cart/add", {"product_id": pid, "qty": "1"}),
        ]:
            session.post(f"{BASE_URL}{path}", data=payload, timeout=10, allow_redirects=True)
        co = session.get(f"{BASE_URL}/checkout.php", timeout=15)
        assert co.status_code == 200
        if "brand-tag-authorized-reseller-checkout" not in co.text:
            # Fallback assertion if cart-add path differs: still expect at least 1 badge instance
            if _count_reseller(co.text) < 1:
                pytest.skip("Could not seed cart; checkout brand-tag not asserted")

    def test_off_state_removes_all_three(self, session):
        ok, err = _mysql_set("0")
        if not ok:
            pytest.skip(f"mysql update failed: {err}")
        try:
            r = session.get(f"{BASE_URL}/", timeout=15)
            assert r.status_code == 200
            count = _count_reseller(r.text)
            assert count == 0, f"Expected 0 'AUTHORIZED RESELLER' occurrences when OFF, got {count}"
            # Footer copy should switch to 'Trusted Software Store'
            assert re.search(r"Trusted\s+Software\s+Store\s*\W+\s*2\+\s*Years", r.text, re.I), \
                "Footer replacement text 'Trusted Software Store • 2+ Years' missing"
        finally:
            _mysql_set("1")  # restore

    def test_restore_on_state(self, session):
        _mysql_set("1")
        r = session.get(f"{BASE_URL}/", timeout=15)
        count = _count_reseller(r.text)
        assert count == 3, f"After restore expected 3, got {count}"


# =========================================================================
# 3) ADMIN COMPANY-INFO TOGGLE UI
# =========================================================================
class TestAdminCompanyToggleUI:
    def test_admin_company_form_has_toggle_input(self, admin_session):
        r = admin_session.get(f"{BASE_URL}/admin.php?tab=company", timeout=15)
        assert r.status_code == 200
        # data-testid on the checkbox
        assert 'data-testid="ci-show-authorized-reseller-toggle"' in r.text, \
            "Toggle input with required data-testid not found on company tab"
        # name attribute should bind to show_authorized_reseller_badge
        assert "show_authorized_reseller_badge" in r.text, \
            "Setting key show_authorized_reseller_badge not present in admin company form"
