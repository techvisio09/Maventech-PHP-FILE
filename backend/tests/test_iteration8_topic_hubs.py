"""
Iteration 8 — Topic Cluster Hubs enhancements + GSC Discovery Lab.

What we cover here (all of it driven through real HTTP + DB, no mocks):
  1.  DB-backed hubs (`topic_hubs`) replace the legacy hard-coded array.
  2.  Seed data is auto-created (microsoft-office, windows, antivirus).
  3.  Admin can CREATE / TOGGLE / DELETE hubs (server actions).
  4.  Auto-generate from busy categories is idempotent (no dupes).
  5.  Sitemap.xml reflects DB hub list, not a static slice.
  6.  category.php + product.php expose hub backlinks (anchor graph).
  7.  GSC CSV import populates `gsc_queries` with proper cluster keys.
  8.  "Create hub from GSC cluster" inserts a new gsc-source hub and
      makes it visible at /hub/<slug>.
"""

from __future__ import annotations
import os
import re
import subprocess
import time
import pytest
import requests

BASE = "https://indexnow-checker.preview.emergentagent.com"
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"


def _mysql(sql: str) -> str:
    out = subprocess.check_output(
        ["mysql", "-uroot", "ucode_store", "-N", "-e", sql],
        stderr=subprocess.STDOUT,
    )
    return out.decode("utf-8").strip()


@pytest.fixture(scope="module")
def admin_session() -> requests.Session:
    s = requests.Session()
    r = s.post(
        f"{BASE}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=15,
    )
    assert r.status_code == 200, f"Login failed: {r.status_code}"
    # Hitting a protected page must NOT redirect us back to login.
    r2 = s.get(f"{BASE}/admin.php?tab=dashboard", timeout=15)
    assert "login" not in r2.url.lower(), f"Login session not preserved: {r2.url}"
    return s


# ---------------------------------------------------------------------------
# 1. Tables exist and seed defaults are present.
# ---------------------------------------------------------------------------

def test_topic_hubs_table_exists():
    assert _mysql("SHOW TABLES LIKE 'topic_hubs';") == "topic_hubs"
    assert _mysql("SHOW TABLES LIKE 'gsc_queries';") == "gsc_queries"


def test_topic_hubs_seed_defaults_present():
    rows = _mysql("SELECT slug FROM topic_hubs WHERE source='seed' ORDER BY slug;")
    slugs = set(rows.splitlines()) if rows else set()
    assert {"antivirus", "microsoft-office", "windows"}.issubset(slugs)


# ---------------------------------------------------------------------------
# 2. Public hub pages render off the DB.
# ---------------------------------------------------------------------------

@pytest.mark.parametrize("slug", ["microsoft-office", "windows", "antivirus"])
def test_public_hub_renders(slug: str):
    r = requests.get(f"{BASE}/hub/{slug}", timeout=15)
    assert r.status_code == 200
    assert "data-testid=\"hub-hero\"" in r.text
    assert "data-testid=\"hub-h1\"" in r.text


# ---------------------------------------------------------------------------
# 3. Sitemap pulls hub URLs from DB (so any admin-added hub ships
#    automatically without a code change).
# ---------------------------------------------------------------------------

def test_sitemap_includes_every_active_hub():
    db_slugs = _mysql("SELECT slug FROM topic_hubs WHERE active=1;").splitlines()
    sm = requests.get(f"{BASE}/sitemap.xml", timeout=15).text
    for s in db_slugs:
        assert f"/hub/{s}" in sm, f"Hub /hub/{s} missing from sitemap"


# ---------------------------------------------------------------------------
# 4. Anchor graph — category & product pages show a hub backlink.
# ---------------------------------------------------------------------------

def test_category_page_shows_hub_backlink():
    r = requests.get(f"{BASE}/category.php?slug=bitdefender", timeout=15)
    assert r.status_code == 200
    assert 'data-testid="category-hub-link"' in r.text


def test_product_page_shows_hub_backlink():
    slug = _mysql(
        "SELECT slug FROM products WHERE category='bitdefender' AND is_active=1 LIMIT 1;"
    )
    assert slug, "No bitdefender product available to verify hub anchor"
    r = requests.get(f"{BASE}/product.php?slug={slug}", timeout=15)
    assert r.status_code == 200
    assert 'data-testid="product-hub-link"' in r.text


# ---------------------------------------------------------------------------
# 5. Admin CRUD for hubs.
# ---------------------------------------------------------------------------

def test_admin_create_toggle_delete_hub(admin_session):
    # Clean any prior leftover
    _mysql("DELETE FROM topic_hubs WHERE slug='pytest-hub';")

    payload = {
        "save_topic_hub": "1",
        "hub_id": "0",
        "hub_slug": "pytest-hub",
        "hub_title": "Pytest Hub",
        "hub_headline": "Headline used inside the pytest suite to verify hub creation.",
        "hub_audience": "test runners",
        "hub_categories": "bitdefender",
        "hub_blog_tags": "%bitdefender%",
        "hub_keywords": "pytest hub, regression hub",
        "hub_about_link": "category.php?slug=bitdefender",
        "hub_color": "#ff00ff",
        "hub_videos": "",
        "hub_active": "1",
    }
    r = admin_session.post(f"{BASE}/admin.php?tab=ai-blogger", data=payload, timeout=15)
    assert r.status_code == 200
    hub_id = _mysql("SELECT id FROM topic_hubs WHERE slug='pytest-hub';")
    assert hub_id.isdigit() and int(hub_id) > 0

    # Public hub now lives
    assert requests.get(f"{BASE}/hub/pytest-hub", timeout=15).status_code == 200

    # Toggle (active -> 0)
    admin_session.get(
        f"{BASE}/admin.php?tab=ai-blogger&toggle_topic_hub={hub_id}", timeout=15
    )
    assert _mysql(f"SELECT active FROM topic_hubs WHERE id={hub_id};") == "0"

    # When inactive, hub.php should 404 because topic_hub_by_slug filters active=1.
    r404 = requests.get(f"{BASE}/hub/pytest-hub", timeout=15)
    assert r404.status_code == 404, f"Inactive hub should 404, got {r404.status_code}"

    # Delete
    admin_session.get(
        f"{BASE}/admin.php?tab=ai-blogger&delete_topic_hub={hub_id}", timeout=15
    )
    assert _mysql(f"SELECT COUNT(*) FROM topic_hubs WHERE id={hub_id};") == "0"


# ---------------------------------------------------------------------------
# 6. Auto-generate is idempotent.
# ---------------------------------------------------------------------------

def test_autogenerate_is_idempotent(admin_session):
    before = int(_mysql("SELECT COUNT(*) FROM topic_hubs;"))
    admin_session.get(
        f"{BASE}/admin.php?tab=ai-blogger&autogen_topic_hubs=1", timeout=15
    )
    after_first = int(_mysql("SELECT COUNT(*) FROM topic_hubs;"))
    admin_session.get(
        f"{BASE}/admin.php?tab=ai-blogger&autogen_topic_hubs=1", timeout=15
    )
    after_second = int(_mysql("SELECT COUNT(*) FROM topic_hubs;"))
    assert after_second == after_first, (
        "Second auto-generate must NOT create duplicates"
    )
    assert after_first >= before


# ---------------------------------------------------------------------------
# 7. GSC CSV upload populates gsc_queries.
# ---------------------------------------------------------------------------

def test_gsc_csv_upload_creates_queries_and_clusters(admin_session):
    _mysql("TRUNCATE TABLE gsc_queries;")
    csv = (
        "Top queries,Clicks,Impressions,CTR,Position\n"
        "windows 11 pro key,55,1500,3.6%,3.8\n"
        "windows 11 home key,33,950,3.4%,4.5\n"
        "bitdefender total security,18,400,4.5%,6.3\n"
        "bitdefender vs mcafee,12,250,4.8%,8.0\n"
        "mcafee total protection,22,600,3.6%,5.4\n"
    )
    files = {"gsc_csv": ("queries.csv", csv, "text/csv")}
    r = admin_session.post(
        f"{BASE}/admin.php?tab=ai-blogger",
        data={"upload_gsc_csv": "1"},
        files=files,
        timeout=15,
    )
    assert r.status_code == 200
    n = int(_mysql("SELECT COUNT(*) FROM gsc_queries;"))
    assert n == 5, f"Expected 5 imported rows, got {n}"
    # Each row should have a non-empty cluster_key
    empty = int(_mysql("SELECT COUNT(*) FROM gsc_queries WHERE cluster_key='';"))
    assert empty == 0


def test_create_hub_from_cluster(admin_session):
    # Pick the top cluster.
    cluster = _mysql(
        "SELECT cluster_key FROM gsc_queries GROUP BY cluster_key ORDER BY SUM(impressions) DESC LIMIT 1;"
    )
    assert cluster, "No clusters available for the test"
    _mysql(f"DELETE FROM topic_hubs WHERE slug='{cluster}';")
    r = admin_session.get(
        f"{BASE}/admin.php?tab=ai-blogger&hub_from_cluster={cluster}", timeout=15
    )
    assert r.status_code == 200
    slug = _mysql(f"SELECT slug FROM topic_hubs WHERE slug='{cluster}';")
    assert slug == cluster
    # Public page now serves it
    assert requests.get(f"{BASE}/hub/{cluster}", timeout=15).status_code == 200
    # And it shows up in the sitemap straight away
    sm = requests.get(f"{BASE}/sitemap.xml", timeout=15).text
    assert f"/hub/{cluster}" in sm
    # cleanup
    _mysql(f"DELETE FROM topic_hubs WHERE slug='{cluster}';")
    _mysql("TRUNCATE TABLE gsc_queries;")


# ---------------------------------------------------------------------------
# 8. Hub form is editable from admin (edit_hub=<slug>).
# ---------------------------------------------------------------------------

def test_admin_edit_hub_form_prefills(admin_session):
    r = admin_session.get(
        f"{BASE}/admin.php?tab=ai-blogger&edit_hub=microsoft-office", timeout=15
    )
    assert r.status_code == 200
    assert 'data-testid="hub-form-card"' in r.text
    # Slug pre-filled (attribute order is not guaranteed)
    assert 'value="microsoft-office"' in r.text
    assert 'data-testid="hub-slug-input"' in r.text
    # Title pre-filled
    assert "Microsoft Office" in r.text


# ---------------------------------------------------------------------------
# 9. Video JSON-LD is emitted only when hub has videos configured.
# ---------------------------------------------------------------------------

def test_hub_video_jsonld_emitted_when_videos_present(admin_session):
    # Save a YouTube video on antivirus hub.
    payload = {
        "save_topic_hub": "1",
        "hub_id": _mysql("SELECT id FROM topic_hubs WHERE slug='antivirus';"),
        "hub_slug": "antivirus",
        "hub_title": "Antivirus software — Bitdefender, McAfee & internet-security buying guide",
        "hub_headline": "Modern antivirus software protects every device in your household.",
        "hub_audience": "home users",
        "hub_categories": "antivirus, bitdefender, mcafee, internet-security",
        "hub_blog_tags": "%bitdefender%, %mcafee%, %antivirus%",
        "hub_keywords": "antivirus, bitdefender, mcafee",
        "hub_about_link": "category.php?slug=antivirus",
        "hub_color": "#16a34a",
        "hub_videos": "https://www.youtube.com/watch?v=dQw4w9WgXcQ | How to install Bitdefender",
        "hub_active": "1",
    }
    admin_session.post(f"{BASE}/admin.php?tab=ai-blogger", data=payload, timeout=15)
    html = requests.get(f"{BASE}/hub/antivirus", timeout=15).text
    assert '"@type":"VideoObject"' in html
    assert "youtube.com/embed/dQw4w9WgXcQ" in html
    # Reset videos to empty so the next test run starts clean
    payload["hub_videos"] = ""
    admin_session.post(f"{BASE}/admin.php?tab=ai-blogger", data=payload, timeout=15)
