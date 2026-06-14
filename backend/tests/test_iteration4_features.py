"""
Iteration 4 — Maventech AI-Blogger country-aware extensions.

Covers:
1. QUICK ACTIONS country picker present (data-testid=quick-action-region select with 5 options).
2. QUICK ACTIONS three cards have data-base-href + hint testids; default hints text.
3. JS wiring source contains the refresh logic + region encoding + hint templates.
4. WRITE-ONE-POST with ?region=US dispatches without 500.
5. seo_publish_featured_trends_article signature has $targetRegion='ALL' default.
6. seo_publish_featured_trends_article validates region against ['ALL','US','UK','AU','CA'].
7. Trends function INSERTs target_region using $targetRegion (not hard-coded 'ALL').
8. regionAudienceMap is region-specific in the system prompt builder.
9. TRENDS sub-section present: details#trends-section + filters + list/empty-state + generate-now btn.
10. Six filter pills with pre-computed counts; counts sum invariant.
11. Trends filter JS rules in source: 'all', 'ALL'=global only, country=country+global.
12. Generate-Trends-Now button href has both run_trends_article=1 AND force=1.
13. Quick-Actions trends card href also has force=1.
14. Published-blog-list still present (regression).
15. Flash-message branches: 'Global audience' vs 'Targeted at <region>'.
"""
import os
import re
import subprocess

import pytest
import requests

BASE_URL = os.environ.get("PHP_BASE_URL", "http://localhost:3000").rstrip("/")
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"
ADMIN_PHP = "/app/php-version/admin.php"
SEO_BOT_PHP = "/app/php-version/includes/seo-bot.php"


def _mysql(sql: str) -> str:
    try:
        r = subprocess.run(
            ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e", sql],
            capture_output=True, timeout=8, check=False,
        )
        return (r.stdout or b"").decode("utf-8", errors="replace").strip()
    except Exception as e:
        return f"__ERR__{e}"


def _setting_del(k: str) -> None:
    _mysql(f"DELETE FROM settings WHERE k='{k}'")


@pytest.fixture(scope="session")
def admin_session():
    s = requests.Session()
    s.headers.update({"User-Agent": "iter4-tester"})
    s.get(f"{BASE_URL}/login.php", timeout=20)
    s.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=20,
    )
    r = s.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=20)
    if r.status_code != 200 or "login" in r.url.lower():
        pytest.skip(f"admin login failed (status={r.status_code}, url={r.url})")
    return s


@pytest.fixture(scope="session")
def ai_blogger_html(admin_session) -> str:
    r = admin_session.get(f"{BASE_URL}/admin.php?tab=ai-blogger", timeout=20)
    assert r.status_code == 200, r.status_code
    return r.text


@pytest.fixture(scope="session")
def admin_php_source() -> str:
    with open(ADMIN_PHP, "r", encoding="utf-8") as f:
        return f.read()


@pytest.fixture(scope="session")
def seo_bot_source() -> str:
    with open(SEO_BOT_PHP, "r", encoding="utf-8") as f:
        return f.read()


# ============================================================
# 1. Quick Actions country picker
# ============================================================
class TestQuickActionsRegionPicker:
    def test_select_present(self, ai_blogger_html):
        assert 'data-testid="quick-action-region"' in ai_blogger_html
        # the <select> tag exists
        assert re.search(
            r'<select[^>]*id="quick-action-region"[^>]*>',
            ai_blogger_html,
        ), "quick-action-region select tag not found"

    def test_five_options(self, ai_blogger_html):
        # Find the select block and count options.
        m = re.search(
            r'<select[^>]*id="quick-action-region"[^>]*>(.*?)</select>',
            ai_blogger_html, re.DOTALL,
        )
        assert m, "could not locate select block"
        block = m.group(1)
        opts = re.findall(r'<option[^>]*value="([^"]*)"', block)
        assert opts == ["", "US", "UK", "AU", "CA"], opts


# ============================================================
# 2. Quick Action cards + default hints
# ============================================================
class TestQuickActionCards:
    @pytest.mark.parametrize("tid,base_substr,hint_tid,default_hint", [
        ("ai-blogger-run-underserved", "run_underserved_post=1",
         "qa-underserved-hint", "AI picks the next under-served country"),
        ("ai-blogger-run-random", "run_random_post=1",
         "qa-random-hint", "Random product, random country"),
        ("ai-blogger-run-trends", "run_trends_article=1&force=1",
         "qa-trends-hint", "Long-form trends article on demand"),
    ])
    def test_card_present_with_base_href_and_default_hint(
        self, ai_blogger_html, tid, base_substr, hint_tid, default_hint
    ):
        # Card anchor with data-base-href and data-testid
        anchor = re.search(
            rf'<a[^>]*data-testid="{tid}"[^>]*>',
            ai_blogger_html,
        )
        assert anchor, f"card {tid} not found"
        a_tag = anchor.group(0)
        assert 'data-base-href="' in a_tag, f"{tid} missing data-base-href"
        m = re.search(r'data-base-href="([^"]+)"', a_tag)
        assert m and base_substr in m.group(1), (
            f"{tid} data-base-href={m.group(1) if m else None!r} missing {base_substr!r}"
        )
        # Hint default text
        hint = re.search(
            rf'data-testid="{hint_tid}"[^>]*>([^<]+)</',
            ai_blogger_html,
        )
        assert hint, f"hint {hint_tid} not found"
        assert hint.group(1).strip() == default_hint, hint.group(1).strip()

    def test_trends_card_href_has_force(self, ai_blogger_html):
        # The href itself (used when no region picked) must include force=1.
        # In the rendered HTML, href appears BEFORE data-testid, so match
        # either order.
        m = re.search(
            r'<a\s[^>]*href="([^"]+)"[^>]*data-testid="ai-blogger-run-trends"',
            ai_blogger_html,
        ) or re.search(
            r'<a\s[^>]*data-testid="ai-blogger-run-trends"[^>]*href="([^"]+)"',
            ai_blogger_html,
        )
        assert m, "trends card not found"
        href = m.group(1)
        assert "run_trends_article=1" in href
        assert "force=1" in href, href


# ============================================================
# 3. JS wiring of the picker
# ============================================================
class TestQuickActionsJS:
    def test_picker_js_present(self, admin_php_source):
        # Refresh fn rewrites href + hint
        assert "data-base-href" in admin_php_source
        assert "quick-action-region" in admin_php_source
        # Region encoded into URL
        assert "'region=' + encodeURIComponent" in admin_php_source
        # Hint templates for all three
        for tid in ("qa-underserved-hint", "qa-random-hint", "qa-trends-hint"):
            assert tid in admin_php_source, tid
        # Region labels for all 4 countries
        for rc in ("US", "UK", "AU", "CA"):
            assert f"'{rc}'" in admin_php_source, rc


# ============================================================
# 4. Write-One-Post with region — handler reachable, no 500
# ============================================================
class TestWriteOnePostWithRegion:
    def test_run_underserved_with_region_us(self, admin_session):
        # Clear 60s cooldown so the handler attempts execution path.
        _setting_del("seo_bot_force_one_last_at")
        r = admin_session.get(
            f"{BASE_URL}/admin.php?tab=ai-blogger&run_underserved_post=1&region=US",
            timeout=60,
            allow_redirects=True,
        )
        # The handler always redirects to admin.php?tab=ai-blogger; we just
        # care that the round trip didn't 500. We don't assert a successful
        # LLM publish (no LLM key may be configured in CI).
        assert r.status_code == 200, r.status_code
        assert "ai-blogger" in r.url, r.url


# ============================================================
# 5/6/7. seo_publish_featured_trends_article signature, validation, INSERT
# ============================================================
class TestTrendsFunctionSignature:
    def test_signature_has_target_region_default_all(self, seo_bot_source):
        assert re.search(
            r"function\s+seo_publish_featured_trends_article\s*\(\s*"
            r"array\s+&\$report\s*,\s*bool\s+\$force\s*=\s*false\s*,\s*"
            r"string\s+\$targetRegion\s*=\s*'ALL'\s*\)",
            seo_bot_source,
        ), "signature not (&$report, bool $force=false, string $targetRegion='ALL')"

    def test_region_validation_array(self, seo_bot_source):
        assert "['ALL', 'US', 'UK', 'AU', 'CA']" in seo_bot_source, \
            "validRegions array not found"
        # Fallback to 'ALL' on invalid input
        assert re.search(
            r"if\s*\(\s*!in_array\(\$targetRegion,\s*\$validRegions[^\)]*\)\)"
            r"\s*\$targetRegion\s*=\s*'ALL'",
            seo_bot_source,
        ), "fallback to ALL on invalid region not found"

    def test_insert_uses_targetregion_var(self, seo_bot_source):
        # The INSERT block that mentions is_featured_trends should bind
        # $targetRegion (not hard-coded 'ALL').
        # Search the INSERT statement that contains is_featured_trends.
        block_match = re.search(
            r"INSERT INTO blog_posts[\s\S]{0,400}is_featured_trends[\s\S]{0,2000}",
            seo_bot_source,
        )
        assert block_match, "trends INSERT block not found"
        block = block_match.group(0)
        assert "$targetRegion" in block, (
            "trends INSERT does not bind $targetRegion (hard-coded?)"
        )

    def test_out_target_region_assigned(self, seo_bot_source):
        assert re.search(
            r"\$out\['target_region'\]\s*=\s*\$targetRegion",
            seo_bot_source,
        ), "out['target_region'] is not set to $targetRegion"


# ============================================================
# 8. regionAudienceMap region-aware prompt
# ============================================================
class TestRegionAudienceMap:
    def test_map_exists_with_all_five(self, seo_bot_source):
        m = re.search(r"\$regionAudienceMap\s*=\s*\[([\s\S]+?)\];", seo_bot_source)
        assert m, "regionAudienceMap array not found"
        block = m.group(1)
        for key in ("'ALL'", "'US'", "'UK'", "'AU'", "'CA'"):
            assert key in block, f"regionAudienceMap missing key {key}"

    def test_map_entries_are_distinct(self, seo_bot_source):
        m = re.search(r"\$regionAudienceMap\s*=\s*\[([\s\S]+?)\];", seo_bot_source)
        block = m.group(1)
        # Extract the string value after each "'XX' =>"
        rows = dict(re.findall(
            r"'(ALL|US|UK|AU|CA)'\s*=>\s*'([^']+)'", block,
        ))
        assert set(rows.keys()) == {"ALL", "US", "UK", "AU", "CA"}, rows.keys()
        # All five must be distinct
        assert len(set(rows.values())) == 5, (
            f"audience descriptions are not distinct: {rows}"
        )

    def test_audienceline_lookup_with_all_fallback(self, seo_bot_source):
        assert re.search(
            r"\$audienceLine\s*=\s*\$regionAudienceMap\[\$targetRegion\]"
            r"\s*\?\?\s*\$regionAudienceMap\['ALL'\]",
            seo_bot_source,
        ), "audienceLine fallback to ALL not found"


# ============================================================
# 9. Trending Articles sub-section DOM
# ============================================================
class TestTrendsSubSection:
    def test_details_block_present(self, ai_blogger_html):
        assert 'id="trends-section"' in ai_blogger_html
        assert 'data-testid="trends-filters"' in ai_blogger_html

    def test_list_or_empty_state(self, ai_blogger_html):
        has_list = 'data-testid="trends-list"' in ai_blogger_html
        has_empty = 'data-testid="trends-empty-state"' in ai_blogger_html
        # Exactly one of them must render (PHP if/else)
        assert has_list ^ has_empty, (has_list, has_empty)

    def test_generate_now_btn_present_with_force(self, ai_blogger_html):
        m = re.search(
            r'<a\s[^>]*href="([^"]+)"[^>]*data-testid="trends-generate-now-btn"',
            ai_blogger_html,
        ) or re.search(
            r'<a\s[^>]*data-testid="trends-generate-now-btn"[^>]*href="([^"]+)"',
            ai_blogger_html,
        )
        assert m, "trends-generate-now-btn not found"
        href = m.group(1)
        assert "run_trends_article=1" in href, href
        assert "force=1" in href, href

    def test_trends_rows_have_data_attrs(self, ai_blogger_html):
        # Either rows present (with data-region + data-is-global) OR empty state
        if 'data-testid="trends-list"' not in ai_blogger_html:
            pytest.skip("trends list empty — no rows to verify")
        rows = re.findall(
            r'<a[^>]*data-testid="trends-row"[^>]*>',
            ai_blogger_html,
        )
        assert rows, "trends-list present but no trends-row anchors"
        for row in rows:
            assert "data-region=" in row, row
            assert "data-is-global=" in row, row


# ============================================================
# 10. Six filter pills with correct counts
# ============================================================
class TestTrendsFilterPills:
    @pytest.mark.parametrize("tid", [
        "trends-filter-all",
        "trends-filter-global",
        "trends-filter-us",
        "trends-filter-uk",
        "trends-filter-au",
        "trends-filter-ca",
    ])
    def test_pill_present(self, ai_blogger_html, tid):
        assert f'data-testid="{tid}"' in ai_blogger_html, tid

    def _badge_int(self, html: str, tid: str) -> int:
        # Find pill, then first integer inside its <span class="badge ...">
        m = re.search(
            rf'data-testid="{tid}"[^>]*>([\s\S]+?)</button>',
            html,
        )
        assert m, f"pill {tid} not found"
        inner = m.group(1)
        n = re.search(r"<span[^>]*badge[^>]*>(\d+)", inner)
        assert n, f"badge int not found in {tid}: {inner[:120]}"
        return int(n.group(1))

    def test_counts_sum_invariant(self, ai_blogger_html):
        total = self._badge_int(ai_blogger_html, "trends-filter-all")
        per = sum(
            self._badge_int(ai_blogger_html, f"trends-filter-{x}")
            for x in ("global", "us", "uk", "au", "ca")
        )
        # All = Global + each country (since trends rows are partitioned).
        assert per == total, f"sum of per-bucket {per} != All {total}"


# ============================================================
# 11. Trends filter JS rules in source
# ============================================================
class TestTrendsFilterJS:
    def test_three_rules_present(self, admin_php_source):
        # Locate the trends filter <script> block (after details#trends-section)
        idx = admin_php_source.find('id="trends-section"')
        assert idx >= 0
        tail = admin_php_source[idx:]
        # "All" rule
        assert re.search(
            r"if\s*\(\s*region\s*===\s*'all'\s*\)[^;]*visible\s*=\s*true",
            tail,
        ), "'all' rule not found"
        # "Global" rule: visible = isGlobal
        assert re.search(
            r"region\s*===\s*'ALL'[^;]*visible\s*=\s*isGlobal",
            tail,
        ), "'ALL' (global) rule not found"
        # Country rule: rRegion === region OR isGlobal
        assert re.search(
            r"visible\s*=\s*\(rRegion\s*===\s*region\)\s*\|\|\s*isGlobal",
            tail,
        ), "country rule not found"


# ============================================================
# 14. Regression — published-blog-list still present
# ============================================================
class TestPublishedBlogListRegression:
    def test_published_blog_list_testid(self, ai_blogger_html):
        assert 'data-testid="published-blog-list"' in ai_blogger_html

    def test_country_pills_still_present(self, ai_blogger_html):
        # Published-blog list pills (iteration 3) still rendered
        for rc in ("all", "US", "UK", "AU", "CA"):
            # data-region="<rc>" appears in pill buttons; we just check existence
            assert f'data-region="{rc}"' in ai_blogger_html, rc


# ============================================================
# 15. Flash message branches in handler
# ============================================================
class TestFlashMessageBranches:
    def test_global_label_branch(self, admin_php_source):
        assert "Global audience" in admin_php_source
        assert "Targeted at " in admin_php_source

    def test_flash_includes_published_text(self, admin_php_source):
        assert "Featured trends article published" in admin_php_source

    def test_handler_passes_third_arg(self, admin_php_source):
        # seo_publish_featured_trends_article($report, !empty($_GET['force']), $trendsRegion)
        assert re.search(
            r"seo_publish_featured_trends_article\(\s*\$report\s*,"
            r"\s*!empty\(\$_GET\['force'\]\)\s*,\s*\$trendsRegion\s*\)",
            admin_php_source,
        ), "handler does not pass $trendsRegion as 3rd arg"

    def test_handler_normalises_region(self, admin_php_source):
        # $reqRegion validation includes the 4 supported regions
        assert "['', 'ALL', 'US', 'UK', 'AU', 'CA']" in admin_php_source
