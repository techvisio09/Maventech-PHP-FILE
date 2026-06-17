"""Iteration 14 — Coupon code in vibe schedule + dark-mode KPI icons + logo URL fix."""

from __future__ import annotations
import subprocess
import requests
import pytest
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE = "https://indexnow-checker.preview.emergentagent.com"
ADMIN = (ADMIN_EMAIL, ADMIN_PASSWORD)


def _mysql(sql: str) -> str:
    return subprocess.check_output(["mysql", "-uroot", "ucode_store", "-N", "-e", sql]).decode().strip()


@pytest.fixture(scope="module", autouse=True)
def _seed_promo_with_coupon():
    _mysql("DELETE FROM vibe_schedule WHERE label='ITER14 TEST PROMO';")
    _mysql(
        "INSERT INTO vibe_schedule (vibe, starts_at, ends_at, label, coupon_code, coupon_percent) "
        "VALUES ('classic', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), "
        "'ITER14 TEST PROMO', 'ITR14', 25)"
    )
    yield
    _mysql("DELETE FROM vibe_schedule WHERE label='ITER14 TEST PROMO';")


def test_coupon_columns_exist():
    cols = _mysql("SHOW COLUMNS FROM vibe_schedule LIKE 'coupon_%';")
    assert "coupon_code" in cols
    assert "coupon_percent" in cols


def test_active_promo_returns_coupon():
    out = subprocess.check_output(
        ["php", "-r",
         "require_once '/app/php-version/includes/functions.php'; "
         "$p = active_vibe_promo(); echo $p['coupon_code'].':'.$p['coupon_percent'];"]
    ).decode().strip()
    assert "ITR14:25" in out


def test_coupons_includes_active_promo_code():
    out = subprocess.check_output(
        ["php", "-r",
         "require_once '/app/php-version/includes/functions.php'; "
         "$c = coupons(); echo isset($c['ITR14']) ? 'ITR14='.$c['ITR14'] : 'MISSING';"]
    ).decode().strip()
    assert "ITR14=25" in out


def test_cart_banner_shows_coupon_pill():
    html = requests.get(f"{BASE}/cart.php", timeout=15).text
    assert 'data-testid="vibe-promo-coupon"' in html
    assert "Use" in html and "ITR14" in html and "25% off" in html


def test_email_banner_shows_coupon_for_order():
    php = """
require_once '/app/php-version/includes/functions.php';
require_once '/app/php-version/includes/email.php';
echo email_promo_banner_html();
"""
    out = subprocess.check_output(["php", "-r", php]).decode()
    assert "ITR14" in out
    assert "25% off" in out


def test_invoice_pdf_shows_coupon():
    php = """
require_once '/app/php-version/includes/functions.php';
require_once '/app/php-version/includes/email.php';
require_once '/app/php-version/includes/pdf.php';
$order = ['id'=>1,'order_number'=>'TEST','currency'=>'USD','total'=>49.99,
          'email'=>'t@e.com','first_name'=>'T','last_name'=>'U',
          'status'=>'paid','payment_method'=>'card',
          'created_at'=>date('Y-m-d H:i:s')];
file_put_contents('/tmp/pytest-iter14.pdf',
    generate_invoice_pdf($order, [['name'=>'X','qty'=>1,'price'=>49.99]]));
"""
    subprocess.check_output(["php", "-r", php])
    text = subprocess.check_output(["pdftotext", "/tmp/pytest-iter14.pdf", "-"]).decode()
    assert "ITR14" in text
    assert "25% off" in text


def test_admin_form_has_coupon_inputs():
    s = requests.Session()
    s.post(f"{BASE}/login.php",
           data={"email": ADMIN[0], "password": ADMIN[1]},
           allow_redirects=True, timeout=15)
    html = s.get(f"{BASE}/admin.php?tab=company", timeout=15).text
    assert 'name="coupon_code"' in html
    assert 'name="coupon_percent"' in html
    assert 'data-testid="vsf-coupon-code"' in html
    assert 'data-testid="vsf-coupon-percent"' in html


def test_logo_url_is_root_relative_not_internal_cluster():
    """The cart banner image src must NOT include the internal cluster URL
    (which the public browser cannot reach).  Root-relative '/uploads/...'
    is the correct value."""
    # Stash a fake logo path on the active promo so the helper builds a URL
    _mysql("UPDATE vibe_schedule SET logo_path='uploads/vibe-promos/fake.png' WHERE label='ITER14 TEST PROMO';")
    out = subprocess.check_output(
        ["php", "-r",
         "require_once '/app/php-version/includes/functions.php'; "
         "$p = active_vibe_promo(); echo $p['logo_url'];"]
    ).decode().strip()
    assert out == "/uploads/vibe-promos/fake.png", (
        f"logo_url must be root-relative for cart, got: {out!r}"
    )
    # cleanup
    _mysql("UPDATE vibe_schedule SET logo_path='' WHERE label='ITER14 TEST PROMO';")


def test_kpi_dark_mode_icon_overrides_present():
    s = requests.Session()
    s.post(f"{BASE}/login.php",
           data={"email": ADMIN[0], "password": ADMIN[1]},
           allow_redirects=True, timeout=15)
    html = s.get(f"{BASE}/admin.php?tab=dashboard", timeout=15).text
    # All 6 KPI variants get an explicit dark-mode override.  CSS source
    # uses variable-width whitespace before .kpi-icon, so just check for
    # the variant selector + ".kpi-icon" appearing nearby.
    import re
    for variant in ["green", "blue", "amber", "red", "purple", "cyan"]:
        pattern = rf'\[data-bs-theme="dark"\]\s*\.kpi-tile\.{variant}\s+\.kpi-icon'
        assert re.search(pattern, html), (
            f"Missing KPI dark-mode override for variant: {variant}"
        )


def test_schedule_ends_reverts_default_vibe():
    """When the schedule window ends, apply_vibe_schedule() must revert
    to whatever the user had set BEFORE the schedule kicked in."""
    php = """
require_once '/app/php-version/includes/functions.php';
$pdo = db();
// Snapshot existing active schedules so we can restore them after
$snapshot = $pdo->query("SELECT id FROM vibe_schedule WHERE starts_at<=NOW() AND (ends_at IS NULL OR ends_at>=NOW())")->fetchAll(PDO::FETCH_COLUMN);
$placeholders = $snapshot ? implode(',', array_fill(0, count($snapshot), '?')) : '0';
if ($snapshot) $pdo->prepare("UPDATE vibe_schedule SET starts_at=DATE_ADD(NOW(), INTERVAL 10 YEAR) WHERE id IN ($placeholders)")->execute($snapshot);

setting_set('company_brand_vibe', 'classic');
setting_set('company_brand_vibe_default', '');

// Insert an ACTIVE test schedule
$pdo->prepare("INSERT INTO vibe_schedule (vibe, starts_at, ends_at, label)
    VALUES ('playful', DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 1 HOUR), 'ITER14 REVERT TEST')")->execute();
$testId = (int)$pdo->lastInsertId();
apply_vibe_schedule();
echo "during=".setting_get('company_brand_vibe').";";

// Expire it AND reset the static-cache by running PHP in a fresh subprocess
$pdo->exec("UPDATE vibe_schedule SET ends_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=$testId");

// Cleanup BEFORE the second test so we get a clean state
$pdo->exec("DELETE FROM vibe_schedule WHERE id=$testId");
if ($snapshot) $pdo->prepare("UPDATE vibe_schedule SET starts_at=DATE_SUB(NOW(), INTERVAL 1 YEAR) WHERE id IN ($placeholders)")->execute($snapshot);
"""
    out = subprocess.check_output(["php", "-r", php]).decode().strip()
    assert "during=playful" in out, f"Vibe should switch DURING the window: {out}"
    # Now run a SECOND PHP process (fresh static cache) to verify revert
    php2 = """
require_once '/app/php-version/includes/functions.php';
$pdo = db();
// Make sure no schedule is active right now
$snapshot = $pdo->query("SELECT id FROM vibe_schedule WHERE starts_at<=NOW() AND (ends_at IS NULL OR ends_at>=NOW())")->fetchAll(PDO::FETCH_COLUMN);
$placeholders = $snapshot ? implode(',', array_fill(0, count($snapshot), '?')) : '0';
if ($snapshot) $pdo->prepare("UPDATE vibe_schedule SET starts_at=DATE_ADD(NOW(), INTERVAL 10 YEAR) WHERE id IN ($placeholders)")->execute($snapshot);

setting_set('company_brand_vibe', 'playful');
setting_set('company_brand_vibe_default', 'classic');
apply_vibe_schedule();
echo "after=".setting_get('company_brand_vibe');

if ($snapshot) $pdo->prepare("UPDATE vibe_schedule SET starts_at=DATE_SUB(NOW(), INTERVAL 1 YEAR) WHERE id IN ($placeholders)")->execute($snapshot);
"""
    out2 = subprocess.check_output(["php", "-r", php2]).decode().strip()
    assert "after=classic" in out2, f"Vibe should revert to default AFTER window: {out2}"
