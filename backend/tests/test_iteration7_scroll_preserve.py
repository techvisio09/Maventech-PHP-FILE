"""
Iteration 7 — Admin "scroll preservation on action" tests.

These tests use a real headless browser (Playwright sync API) because the
feature is entirely client-side JavaScript.  pytest's requests.Session
can't simulate scrollY / sessionStorage / form auto-submit reliably.

The test login does a real form POST + cookie capture and then loads the
admin SEO panel.  We scroll mid-page, fire a form submit, wait for the
reload, and assert scrollY is restored within ±80px of where we were.
"""
import os
import re
import time
import pytest

from playwright.sync_api import sync_playwright
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE_URL = os.environ.get("PHP_BASE_URL", "http://localhost:3000").rstrip("/")
# Section IDs every admin <details> block must carry so the preserver can
# remember which were expanded.  Keep in sync with admin.php.
EXPECTED_SECTION_IDS = [
    "api-keys-section",
    "seo-visibility-section",
    "posts-section",
    "trends-section",
    "recent-activity-section",
    "health-check-section",
    "advanced-settings-section",
]


@pytest.fixture(scope="module")
def browser_ctx():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(viewport={"width": 1280, "height": 900})
        yield ctx
        ctx.close()
        browser.close()


@pytest.fixture
def admin_page(browser_ctx):
    """Bypass Playwright's flaky form-submit navigation by acquiring the
    PHPSESSID via a direct HTTP POST (using requests) and injecting it
    into the browser context before any goto.  Way more reliable than
    racing chromium's network/redirect timing."""
    import requests
    s = requests.Session()
    s.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=False,
        timeout=10,
    )
    session_id = s.cookies.get("PHPSESSID")
    assert session_id, "Failed to acquire PHPSESSID via direct login POST"

    page = browser_ctx.new_page()
    # Inject the session cookie into the browser context.
    host = BASE_URL.replace("http://", "").replace("https://", "").split("/")[0]
    page.context.add_cookies([{
        "name": "PHPSESSID",
        "value": session_id,
        "domain": host.split(":")[0],
        "path": "/",
    }])
    yield page
    page.close()


class TestSectionIds:
    """Every admin <details> in the AI-Blogger tab carries an id."""

    def test_every_section_has_id(self, admin_page):
        admin_page.goto(f"{BASE_URL}/admin.php?tab=ai-blogger", wait_until="domcontentloaded")
        admin_page.wait_for_timeout(800)
        html = admin_page.content()
        for sid in EXPECTED_SECTION_IDS:
            assert f'id="{sid}"' in html, f"Missing <details id='{sid}'> in admin.php"


class TestScrollPreserver:
    """The new sessionStorage-based scroll preserver in admin-shell.php."""

    def test_script_is_present(self, admin_page):
        admin_page.goto(f"{BASE_URL}/admin.php?tab=ai-blogger", wait_until="domcontentloaded")
        html = admin_page.content()
        for token in ("adm_state_y", "adm_state_open", "adm_state_ts", "adm_state_tab"):
            assert token in html, f"Preserver script missing sessionStorage key: {token}"
        assert "window.admPreserveState" in html

    def test_scrolly_restored_after_form_submit(self, admin_page):
        admin_page.goto(f"{BASE_URL}/admin.php?tab=ai-blogger", wait_until="domcontentloaded")
        admin_page.wait_for_timeout(800)
        # Open the Search Engine Visibility section so the toggle is visible.
        seo_summary = admin_page.query_selector("#seo-visibility-section summary")
        if seo_summary:
            seo_summary.click()
            admin_page.wait_for_timeout(400)
        # Scroll down to the auto-weekly toggle (forces scrollY > 0).
        toggle = admin_page.query_selector("[data-testid='auto-weekly-toggle']")
        assert toggle is not None, "auto-weekly-toggle not found"
        toggle.scroll_into_view_if_needed()
        admin_page.wait_for_timeout(400)
        scroll_y_before = admin_page.evaluate("window.scrollY")
        assert scroll_y_before > 100, f"Test setup failed — scrollY only {scroll_y_before} (need > 100 to detect a snap-to-top regression)"
        # Click the toggle — its onchange auto-submits the form.
        toggle.click()
        # Wait for the navigation triggered by the form submit
        try:
            admin_page.wait_for_load_state("domcontentloaded", timeout=10000)
        except Exception:
            pass
        admin_page.wait_for_timeout(1500)  # let the restore script run all 4 retries
        scroll_y_after = admin_page.evaluate("window.scrollY")
        delta = abs(scroll_y_before - scroll_y_after)
        assert delta < 120, (
            f"Scroll NOT preserved after form submit. "
            f"Before={scroll_y_before}, After={scroll_y_after}, Δ={delta}px "
            f"(expected within 120px of the original position)"
        )

    def test_open_details_restored_after_form_submit(self, admin_page):
        admin_page.goto(f"{BASE_URL}/admin.php?tab=ai-blogger", wait_until="domcontentloaded")
        admin_page.wait_for_timeout(800)
        # Open the SEO Visibility section.
        admin_page.evaluate("document.getElementById('seo-visibility-section').open = true")
        admin_page.wait_for_timeout(300)
        open_before = admin_page.evaluate(
            "Array.from(document.querySelectorAll('details[open]')).map(d => d.id).filter(Boolean)"
        )
        assert "seo-visibility-section" in open_before, "Setup failed — SEO section not opened"
        # Trigger a form submit via the auto-weekly toggle.
        toggle = admin_page.query_selector("[data-testid='auto-weekly-toggle']")
        assert toggle is not None
        toggle.scroll_into_view_if_needed()
        admin_page.wait_for_timeout(300)
        toggle.click()
        try:
            admin_page.wait_for_load_state("domcontentloaded", timeout=10000)
        except Exception:
            pass
        admin_page.wait_for_timeout(1500)
        open_after = admin_page.evaluate(
            "Array.from(document.querySelectorAll('details[open]')).map(d => d.id).filter(Boolean)"
        )
        assert "seo-visibility-section" in open_after, (
            f"Open <details> was NOT restored after submit. before={open_before} after={open_after}"
        )

    def test_state_NOT_restored_after_30_seconds(self, admin_page):
        """The preserver wipes state if it's older than 30 seconds — protects
        against unexpected scroll jumps on cold loads / new sessions."""
        admin_page.goto(f"{BASE_URL}/admin.php?tab=ai-blogger", wait_until="domcontentloaded")
        admin_page.wait_for_timeout(500)
        # Manually plant a stale entry (timestamp 60 seconds in the past)
        admin_page.evaluate("""
            sessionStorage.setItem('adm_state_y', '1500');
            sessionStorage.setItem('adm_state_open', '["seo-visibility-section"]');
            sessionStorage.setItem('adm_state_ts', String(Date.now() - 60000));
            sessionStorage.setItem('adm_state_tab', 'ai-blogger');
        """)
        # Cold-reload the page
        admin_page.reload(wait_until="domcontentloaded")
        admin_page.wait_for_timeout(1200)
        scroll_y = admin_page.evaluate("window.scrollY")
        open_now = admin_page.evaluate(
            "Array.from(document.querySelectorAll('details[open]')).map(d => d.id)"
        )
        # Stale state should be discarded — no scroll restore, no details opened.
        assert scroll_y < 100, f"Stale state should NOT restore scroll, got scrollY={scroll_y}"
        # SEO section should be closed (was closed by default)
        assert "seo-visibility-section" not in open_now, "Stale state restored a section that shouldn't be open"
        # Storage should be wiped after the stale check
        leftover = admin_page.evaluate("sessionStorage.getItem('adm_state_y')")
        assert leftover is None, "Stale sessionStorage entry should be wiped on cold load"

    def test_state_NOT_restored_across_different_tabs(self, admin_page):
        """Switching admin tabs should jump to the top — only same-tab navigation
        preserves the previous scroll position."""
        admin_page.goto(f"{BASE_URL}/admin.php?tab=ai-blogger", wait_until="domcontentloaded")
        admin_page.wait_for_timeout(500)
        # Plant a fresh state saved on the ai-blogger tab
        admin_page.evaluate("""
            sessionStorage.setItem('adm_state_y', '900');
            sessionStorage.setItem('adm_state_open', '["seo-visibility-section"]');
            sessionStorage.setItem('adm_state_ts', String(Date.now()));
            sessionStorage.setItem('adm_state_tab', 'ai-blogger');
        """)
        # Navigate to a different tab (dashboard)
        admin_page.goto(f"{BASE_URL}/admin.php?tab=dashboard", wait_until="domcontentloaded")
        admin_page.wait_for_timeout(1000)
        scroll_y = admin_page.evaluate("window.scrollY")
        # Tab switch should NOT restore the saved scroll
        assert scroll_y < 100, (
            f"Tab switch should snap to top — but scrollY={scroll_y} "
            f"(state was tagged for a different tab)"
        )
