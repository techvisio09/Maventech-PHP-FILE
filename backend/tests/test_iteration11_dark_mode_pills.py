"""Iteration 11 — Dark-mode polish for admin email cards + hub badges + content icons."""

from __future__ import annotations
import requests

BASE = "https://indexnow-checker.preview.emergentagent.com"


def test_admin_shell_email_card_dark_overrides():
    """Verify the admin-shell CSS now has dark-mode overrides for the
    light-bg/dark-text patterns that were invisible in the user's
    screenshot (License delivery pill, ec-key, ec-meta icons)."""
    # The dark-mode CSS lives inline in admin-shell.php so we can't fetch
    # it as a static file.  Instead we render the login page (which loads
    # admin-shell via every admin URL after auth) and check the HTML.
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": "admin@maventechsoftware.com", "password": "Admin@123"},
        allow_redirects=True,
        timeout=15,
    )
    html = s.get(f"{BASE}/admin.php?tab=emails", timeout=15).text
    assert '[data-bs-theme="dark"] .ec-tpl-chip' in html
    assert '[data-bs-theme="dark"] .ec-key' in html
    assert '[data-bs-theme="dark"] .ec-k .bi' in html
    assert '[data-bs-theme="dark"] .ec-meta' in html
    # Status pill light bg overrides for #d1fae5 / #fef3c7 / #fee2e2 etc.
    assert '#d1fae5' in html and '#fef3c7' in html
    # text-* utility brightening
    assert '[data-bs-theme="dark"] .text-primary' in html


def test_hub_badges_dark_mode_overrides_present():
    css = requests.get(
        f"{BASE}/assets/css/dark-mode-polish.css", timeout=15
    ).text
    # text-bg-light hub stats badges
    assert '[data-bs-theme="dark"] .badge.text-bg-light' in css
    # Inline #f1f5f9 / #fff bg badge anchors (hub related topics)
    assert 'a.badge[style*="#f1f5f9"]' in css
    assert 'a.badge[style*="#fff"]' in css
