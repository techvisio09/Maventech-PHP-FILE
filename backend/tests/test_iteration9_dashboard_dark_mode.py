"""
Iteration 9 — Sales-by-Category donut chart on Admin Dashboard,
              Quick-Answer moved BELOW product grid on category pages,
              full dark-mode sweep across the site.
"""

from __future__ import annotations
import subprocess
import pytest
import requests

BASE = "https://indexnow-checker.preview.emergentagent.com"
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"


def _mysql(sql: str) -> str:
    out = subprocess.check_output(["mysql", "-uroot", "ucode_store", "-N", "-e", sql])
    return out.decode("utf-8").strip()


@pytest.fixture(scope="module")
def admin_session() -> requests.Session:
    s = requests.Session()
    s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=15,
    )
    r = s.get(f"{BASE}/admin.php?tab=dashboard", timeout=15)
    assert "login" not in r.url.lower()
    return s


# ---------------------------------------------------------------------------
# 1. Quick Answer position — must appear AFTER product cards.
# ---------------------------------------------------------------------------

def test_category_quick_answer_renders_below_products():
    html = requests.get(f"{BASE}/category.php?slug=office-2024-mac", timeout=15).text
    qa_pos = html.find('data-testid="category-quick-answer"')
    products_pos = html.find('data-testid="category-list"')
    assert qa_pos > 0, "Quick Answer block missing"
    assert products_pos > 0, "Product list missing"
    assert qa_pos > products_pos, (
        f"Quick Answer ({qa_pos}) must render AFTER product list ({products_pos})"
    )


# ---------------------------------------------------------------------------
# 2. Sales-by-Category donut chart on dashboard.
# ---------------------------------------------------------------------------

def test_dashboard_renders_sales_by_category(admin_session):
    html = admin_session.get(f"{BASE}/admin.php?tab=dashboard", timeout=15).text
    assert 'data-testid="sales-by-category-row"' in html
    # If there is at least one paid order in the active region the chart canvas
    # appears; otherwise the empty-state appears.  Both are valid.
    has_chart = 'data-testid="sales-by-category-chart"' in html
    has_empty = 'data-testid="sales-by-category-empty"' in html
    assert has_chart or has_empty, "Neither chart nor empty state rendered"

    if has_chart:
        # When the chart renders, the legend + total label must be there.
        assert 'data-testid="sales-by-category-legend"' in html
        assert 'data-testid="sales-by-category-total"' in html
        # Chart.js must be loaded from a CDN
        assert "chart.umd.min.js" in html


def test_dashboard_sales_by_category_loads_chartjs_only_when_data_exists(admin_session):
    """Performance — don't load Chart.js if there's nothing to chart."""
    html = admin_session.get(f"{BASE}/admin.php?tab=dashboard", timeout=15).text
    has_data = 'data-testid="sales-by-category-chart"' in html
    has_chartjs = "chart.umd.min.js" in html
    assert has_data == has_chartjs


# ---------------------------------------------------------------------------
# 3. Dark-mode sweep — every problematic block now has a !important override.
# ---------------------------------------------------------------------------

@pytest.mark.parametrize(
    "selector",
    [
        ".cat-topic-hub",                          # the obvious bug
        '[data-testid="category-topic-hub"]',
        '[data-testid="cluster-popular"]',
        ".aeo-quick-answer",
        '[data-testid$="-quick-answer"]',
        ".shop-toolbar",
        ".cat-faq .accordion-button",
        ".cat-buying-guide h2",
        ".alert-light",
        '[data-testid="hub-related-link"]',
        '[data-testid="product-topic-hub-row"]',
        ".pd-deep-cluster h2",
        ".cat-deep-cluster h2",
    ],
)
def test_dark_mode_polish_has_override_for(selector: str):
    css = requests.get(
        f"{BASE}/assets/css/dark-mode-polish.css", timeout=15
    ).text
    assert (
        f'[data-bs-theme="dark"] {selector}' in css
        or f'[data-bs-theme="dark"]{selector}' in css
    ), f"Missing dark-mode override for {selector}"


def test_dark_mode_polish_is_cache_busted():
    """The <link rel=stylesheet href=...> must include a ?v= cache-buster."""
    html = requests.get(f"{BASE}/category.php?slug=office-2024-mac", timeout=15).text
    assert "dark-mode-polish.css?v=" in html, (
        "CSS link must be cache-busted with ?v=<filemtime>"
    )


# ---------------------------------------------------------------------------
# 4. Regression — quick-answer + topic-hub block STILL present after reorder.
# ---------------------------------------------------------------------------

def test_topic_hub_callout_present_after_quick_answer_reorder():
    html = requests.get(f"{BASE}/category.php?slug=office-2024-mac", timeout=15).text
    # Topic hub callout must still exist on a category that has a hub
    # (office-2024-mac belongs to the microsoft-office hub by default).
    assert 'data-testid="category-topic-hub"' in html
    assert 'data-testid="category-hub-link"' in html
    # And the topic-hub callout comes BEFORE the deep-cluster which comes
    # AFTER the Quick Answer.
    qa = html.find('data-testid="category-quick-answer"')
    hub = html.find('data-testid="category-topic-hub"')
    deep = html.find('data-testid="category-deep-cluster"')
    assert qa < hub < deep, (
        f"Order broken — QA({qa}) should precede HUB({hub}) which precedes DEEP({deep})"
    )
