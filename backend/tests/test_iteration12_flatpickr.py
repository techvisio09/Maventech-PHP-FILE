"""Iteration 12 — Flatpickr calendar/date-time picker on every admin date input."""

from __future__ import annotations
import requests
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE = "https://stage-show-2.preview.emergentagent.com"


def test_admin_loads_flatpickr_css_and_js():
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=15,
    )
    html = s.get(f"{BASE}/admin.php?tab=company", timeout=15).text
    # CDN assets
    assert "flatpickr@4.6.13/dist/flatpickr.min.css" in html
    assert "flatpickr@4.6.13/dist/flatpickr.min.js" in html
    # Auto-enhancement script
    assert 'querySelectorAll(\'input[type="date"]:not(.fp-enhanced)' in html
    assert "altInput: true" in html


def test_admin_has_datetime_inputs_on_company_tab():
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=15,
    )
    html = s.get(f"{BASE}/admin.php?tab=company", timeout=15).text
    # The Brand Vibe Schedule form is where the user's screenshot was taken
    assert 'data-testid="vibe-schedule-form"' in html
    assert 'type="datetime-local" name="starts_at"' in html
    assert 'type="datetime-local" name="ends_at"' in html


def test_dark_mode_flatpickr_css_present():
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=15,
    )
    html = s.get(f"{BASE}/admin.php?tab=company", timeout=15).text
    # Dark-mode overrides in admin-shell.php
    assert '[data-bs-theme="dark"] .flatpickr-calendar' in html
    assert '[data-bs-theme="dark"] .flatpickr-day.selected' in html
    assert '[data-bs-theme="dark"] .flatpickr-time' in html
