"""Iteration 15 — Big Save button at the bottom + Copy code button on banner."""
import subprocess
import requests

BASE = "https://indexnow-checker.preview.emergentagent.com"
ADMIN = ("admin@maventechsoftware.com", "Admin@123")


def _mysql(sql: str) -> str:
    return subprocess.check_output(["mysql", "-uroot", "ucode_store", "-N", "-e", sql]).decode().strip()


def _seed_promo():
    _mysql("DELETE FROM vibe_schedule WHERE label='ITER15 COPY TEST';")
    _mysql(
        "INSERT INTO vibe_schedule (vibe, starts_at, ends_at, label, coupon_code, coupon_percent) "
        "VALUES ('classic', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), "
        "'ITER15 COPY TEST', 'ITR15', 15)"
    )


def test_admin_form_has_one_big_submit_button_at_bottom():
    s = requests.Session()
    s.post(f"{BASE}/login.php",
           data={"email": ADMIN[0], "password": ADMIN[1]},
           allow_redirects=True, timeout=15)
    html = s.get(f"{BASE}/admin.php?tab=company", timeout=15).text
    # The single submit button at the bottom of the form.
    assert 'data-testid="vsf-add"' in html
    assert 'Save Schedule' in html
    # And there should be ONLY ONE submit button inside vibe-schedule-form.
    # The form's HTML between data-testid="vibe-schedule-form" and </form>
    import re
    m = re.search(
        r'data-testid="vibe-schedule-form".*?</form>',
        html, re.DOTALL,
    )
    assert m, "vibe-schedule-form missing"
    form = m.group(0)
    submit_buttons = form.count('type="submit"') + form.count("<button class=\"btn btn-primary btn-sm\"")
    assert submit_buttons == 1, (
        f"Form must have exactly ONE submit button at the bottom, found {submit_buttons}"
    )


def test_cart_banner_has_copy_button_when_coupon_set():
    _seed_promo()
    try:
        html = requests.get(f"{BASE}/cart.php", timeout=15).text
        assert 'data-testid="vibe-promo-coupon"' in html
        assert 'data-testid="vibe-promo-copy"' in html
        assert 'data-promo-code="ITR15"' in html
        # Browser's clipboard API call must be in the inline handler.
        assert "navigator.clipboard" in html
    finally:
        _mysql("DELETE FROM vibe_schedule WHERE label='ITER15 COPY TEST';")


def test_coupon_still_valid_at_checkout_when_pasted():
    """The code must be honoured by coupons() when the user pastes it at
    checkout — even though it's not auto-applied from the banner."""
    _seed_promo()
    try:
        out = subprocess.check_output(
            ["php", "-r",
             "require_once '/app/php-version/includes/functions.php'; "
             "$c = coupons(); echo isset($c['ITR15']) ? $c['ITR15'] : 'MISSING';"]
        ).decode().strip()
        assert out == "15", f"Code ITR15 should resolve to 15% off in coupons(), got {out!r}"
    finally:
        _mysql("DELETE FROM vibe_schedule WHERE label='ITER15 COPY TEST';")
