"""Iteration 16 — Forgot/Reset password flow + elegant slim banner with popup."""
import subprocess
import requests
import pytest

BASE = "https://indexnow-checker.preview.emergentagent.com"
ADMIN_EMAIL = "admin@maventechsoftware.com"


def _mysql(sql: str) -> str:
    return subprocess.check_output(["mysql", "-uroot", "ucode_store", "-N", "-e", sql]).decode().strip()


def test_password_resets_table_exists():
    rows = _mysql("SHOW COLUMNS FROM password_resets;")
    for col in ("token_hash", "expires_at", "used_at", "user_id"):
        assert col in rows, f"Missing column: {col}"


def test_login_page_has_forgot_link():
    html = requests.get(f"{BASE}/login.php", timeout=15).text
    assert 'data-testid="login-forgot-link"' in html
    assert 'href="forgot-password.php"' in html
    assert "Forgot password" in html


def test_forgot_password_page_loads():
    html = requests.get(f"{BASE}/forgot-password.php", timeout=15).text
    assert 'data-testid="forgot-form"' in html
    assert 'data-testid="forgot-email"' in html
    assert 'data-testid="forgot-submit"' in html


def test_forgot_password_creates_token_for_existing_email():
    _mysql("DELETE FROM password_resets;")
    r = requests.post(
        f"{BASE}/forgot-password.php",
        data={"email": ADMIN_EMAIL},
        allow_redirects=True,
        timeout=15,
    )
    assert r.status_code == 200
    assert 'data-testid="forgot-success"' in r.text
    count = int(_mysql("SELECT COUNT(*) FROM password_resets;"))
    assert count == 1, f"Token should have been created, got {count}"


def test_forgot_password_silent_for_unknown_email():
    """No user enumeration — same success state, no token row created."""
    _mysql("DELETE FROM password_resets;")
    r = requests.post(
        f"{BASE}/forgot-password.php",
        data={"email": "nobody@example.com"},
        timeout=15,
    )
    assert r.status_code == 200
    assert 'data-testid="forgot-success"' in r.text
    count = int(_mysql("SELECT COUNT(*) FROM password_resets;"))
    assert count == 0


def test_reset_page_shows_invalid_for_missing_token():
    r = requests.get(f"{BASE}/reset-password.php", timeout=15)
    assert 'data-testid="reset-invalid"' in r.text


def test_reset_flow_updates_password():
    _mysql("DELETE FROM password_resets;")
    # Generate a fresh token directly via PHP CLI
    raw = subprocess.check_output([
        "php", "-r",
        "require_once '/app/php-version/includes/functions.php'; "
        "$raw = bin2hex(random_bytes(32));"
        "$hash = hash('sha256', $raw);"
        "$exp = date('Y-m-d H:i:s', time() + 3600);"
        "db()->prepare(\"INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (1,?,?)\")->execute([$hash, $exp]);"
        "echo $raw;"
    ]).decode().strip()
    # Use the token to set a new password
    r = requests.post(
        f"{BASE}/reset-password.php",
        data={"token": raw, "new_password": "ResetTest@456", "confirm_password": "ResetTest@456"},
        timeout=15,
    )
    assert r.status_code == 200
    assert 'data-testid="reset-success"' in r.text, f"Expected success, got: {r.text[:300]}"

    # Sign in with the new password
    s = requests.Session()
    s.post(f"{BASE}/login.php",
           data={"email": ADMIN_EMAIL, "password": "ResetTest@456"},
           allow_redirects=True, timeout=15)
    admin_html = s.get(f"{BASE}/admin.php?tab=dashboard", timeout=15).text
    assert "Admin" in admin_html or "Dashboard" in admin_html

    # Used token is burned (used_at populated)
    used = _mysql("SELECT used_at FROM password_resets ORDER BY id DESC LIMIT 1;")
    assert used and used != "NULL", f"Token should be marked used, got {used!r}"

    # Restore the original admin password so other tests still work
    subprocess.check_output([
        "php", "-r",
        "require_once '/app/php-version/includes/functions.php'; "
        "db()->prepare('UPDATE users SET password_hash=? WHERE email=?')->execute(["
        "password_hash('Admin@123', PASSWORD_BCRYPT), '" + ADMIN_EMAIL + "']);"
    ])


def test_reset_flow_rejects_used_token():
    raw = subprocess.check_output([
        "php", "-r",
        "require_once '/app/php-version/includes/functions.php'; "
        "$raw = bin2hex(random_bytes(32));"
        "$hash = hash('sha256', $raw);"
        "db()->prepare(\"INSERT INTO password_resets (user_id, token_hash, expires_at, used_at) VALUES (1,?,?, NOW())\")"
        "  ->execute([$hash, date('Y-m-d H:i:s', time() + 3600)]);"
        "echo $raw;"
    ]).decode().strip()
    r = requests.get(f"{BASE}/reset-password.php?token={raw}", timeout=15)
    assert 'data-testid="reset-invalid"' in r.text
    assert "already been used" in r.text


# ---------------------------------------------------------------------------
# Elegant slim banner + popup
# ---------------------------------------------------------------------------

@pytest.fixture
def seed_promo():
    _mysql("DELETE FROM vibe_schedule WHERE label='ITER16 ELEGANT';")
    _mysql(
        "INSERT INTO vibe_schedule (vibe, starts_at, ends_at, label, coupon_code, coupon_percent) "
        "VALUES ('classic', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), "
        "'ITER16 ELEGANT', 'IT16', 30)"
    )
    yield
    _mysql("DELETE FROM vibe_schedule WHERE label='ITER16 ELEGANT';")


def test_banner_uses_elegant_slate_color(seed_promo):
    html = requests.get(f"{BASE}/cart.php", timeout=15).text
    # Slate-900 + amber accent (not the old bright red)
    assert "#0f172a" in html or "1e293b" in html
    assert "#fbbf24" in html
    # No more loud red gradient
    assert "dc2626,#b91c1c" not in html


def test_banner_has_view_offer_button_and_popup(seed_promo):
    html = requests.get(f"{BASE}/cart.php", timeout=15).text
    assert 'data-testid="vibe-promo-view-offer"' in html
    assert 'data-testid="vibe-promo-popup"' in html
    assert 'data-testid="vibe-popup-copy"' in html
    assert 'data-testid="vibe-popup-code"' in html
    # Popup starts hidden
    assert 'hidden' in html
    # Click handler wired
    assert "navigator.clipboard" in html


def test_popup_renders_code_and_percent(seed_promo):
    html = requests.get(f"{BASE}/cart.php", timeout=15).text
    assert "IT16" in html
    assert ">30%<" in html or "30%" in html
