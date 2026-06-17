"""Iteration 13 — Vibe-schedule label + optional promo logo shown on
cart, email receipts and invoice PDFs.

For "BLACK FRIDAY SALE" example the same label appears in 3 places:
  1. Public cart page  → red banner at top
  2. Order delivery email + every other transactional email
  3. Invoice + receipt PDF → red bar at the top of every page
"""

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
def _seed_active_promo():
    """Ensure an active schedule covering 'now' for every test in this
    module; clean up afterwards so we don't pollute the dashboard."""
    _mysql(
        "INSERT INTO vibe_schedule (vibe, starts_at, ends_at, label, logo_path) "
        "VALUES ('classic', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), "
        "'PYTEST PROMO 99', '')"
    )
    yield
    _mysql("DELETE FROM vibe_schedule WHERE label='PYTEST PROMO 99';")


def test_vibe_schedule_table_has_logo_path_column():
    cols = _mysql("SHOW COLUMNS FROM vibe_schedule LIKE 'logo_path';")
    assert "logo_path" in cols


def test_cart_renders_active_promo_banner():
    html = requests.get(f"{BASE}/cart.php", timeout=15).text
    assert 'data-testid="vibe-promo-banner"' in html
    assert "PYTEST PROMO 99" in html


def test_order_email_includes_promo_banner():
    # Use a dummy order so build_order_email_html() runs end-to-end.
    php = """
require_once '/app/php-version/includes/functions.php';
require_once '/app/php-version/includes/email.php';
$order = ['id'=>1,'order_number'=>'TEST','currency'=>'USD','total'=>49.99,
          'email'=>'t@e.com','first_name'=>'Test','last_name'=>'User',
          'status'=>'paid','payment_method'=>'card',
          'created_at'=>date('Y-m-d H:i:s'),'card_statement_name'=>'MVT'];
echo build_order_email_html($order, [], [], 'tok');
"""
    out = subprocess.check_output(["php", "-r", php]).decode()
    assert 'data-testid="email-promo-banner"' in out
    assert "PYTEST PROMO 99" in out


def test_invoice_pdf_includes_promo_label():
    php = """
require_once '/app/php-version/includes/functions.php';
require_once '/app/php-version/includes/email.php';
require_once '/app/php-version/includes/pdf.php';
$order = ['id'=>1,'order_number'=>'TEST','currency'=>'USD','total'=>49.99,
          'email'=>'t@e.com','first_name'=>'Test','last_name'=>'User',
          'status'=>'paid','payment_method'=>'card',
          'created_at'=>date('Y-m-d H:i:s')];
file_put_contents('/tmp/pytest-invoice.pdf',
    generate_invoice_pdf($order, [['name'=>'X','qty'=>1,'price'=>49.99]]));
"""
    subprocess.check_output(["php", "-r", php])
    text = subprocess.check_output(
        ["pdftotext", "/tmp/pytest-invoice.pdf", "-"]
    ).decode()
    assert "PYTEST PROMO 99" in text


def test_admin_form_accepts_logo_upload():
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN[0], "password": ADMIN[1]},
        allow_redirects=True,
        timeout=15,
    )
    # 1×1 PNG (transparent) — smallest valid image
    png = bytes.fromhex(
        "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
        "0000000a49444154789c6300010000000500010d0a2db40000000049454e44ae426082"
    )
    files = {"logo_file": ("promo.png", png, "image/png")}
    data = {
        "action": "add_vibe_schedule",
        "vibe": "classic",
        "starts_at": "2026-02-01T00:00",
        "ends_at": "2026-12-31T23:59",
        "label": "PNG TEST LABEL 33",
    }
    s.post(f"{BASE}/admin.php?tab=company", data=data, files=files, timeout=15)

    row = _mysql(
        "SELECT logo_path FROM vibe_schedule WHERE label='PNG TEST LABEL 33' "
        "ORDER BY id DESC LIMIT 1;"
    )
    assert row.startswith("uploads/vibe-promos/promo-"), (
        f"Logo path not saved: {row!r}"
    )
    # And the file actually lives on disk
    file_path = "/app/php-version/" + row
    import os
    assert os.path.isfile(file_path), f"Uploaded logo file missing: {file_path}"

    # Cleanup
    _mysql("DELETE FROM vibe_schedule WHERE label='PNG TEST LABEL 33';")
    try:
        os.remove(file_path)
    except OSError:
        pass


def test_admin_form_has_enctype_and_logo_input():
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN[0], "password": ADMIN[1]},
        allow_redirects=True,
        timeout=15,
    )
    html = s.get(f"{BASE}/admin.php?tab=company", timeout=15).text
    assert 'enctype="multipart/form-data"' in html
    assert 'name="logo_file"' in html
    assert 'data-testid="vsf-logo"' in html
