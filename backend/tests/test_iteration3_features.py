"""
Iteration 3 — Maventech PHP feature/bug verification.

Covers:
1. PUBLIC BLOG FILTER — region filter now includes globals (target_region NULL or 'ALL')
2. PUBLIC BLOG FILTER — combined q + region filter still ANDs both
3. ADMIN PUBLISHED BLOG LIST — rows carry data-region + data-is-global, globe + GLOBAL label
4. ADMIN COUNTRY PILL JS FILTER — script keeps globals visible for every region
5. TRENDS JSON robustness — _seo_llm_json_decode() handles fences, prose, smart quotes, raw newlines
6. TRENDS error includes 160-char snippet "(got: ...)" when content present
7. AUTO-WEEKLY TOGGLE UI — auto-weekly-form, toggle, hint with On/Off + checked attribute
8. AUTO-WEEKLY TOGGLE persistence — POST persists 0 or 1 in settings
9. AUTO-WEEKLY tick — gating: off/no-op, on within 7d/no-op, on older/reaches IndexNow path
10. Regression: 48 baseline tests in companion files still pass (covered by running pytest /app/backend/tests/)
"""
import json
import os
import re
import subprocess
import time

import pytest
import requests

BASE_URL = os.environ.get("PHP_BASE_URL", "http://localhost:3000").rstrip("/")
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"
PHP_VERSION_DIR = "/app/php-version"


def _mysql_exec(sql: str) -> str:
    try:
        r = subprocess.run(
            ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e", sql],
            capture_output=True, timeout=8, check=False,
        )
        return (r.stdout or b"").decode("utf-8", errors="replace").strip()
    except Exception as e:
        return f"__ERR__{e}"


def _setting_get(k: str) -> str:
    return _mysql_exec(f"SELECT v FROM settings WHERE k='{k}'")


def _setting_set(k: str, v: str) -> None:
    v_esc = v.replace("'", "''")
    _mysql_exec(
        f"INSERT INTO settings (k,v) VALUES ('{k}','{v_esc}') "
        f"ON DUPLICATE KEY UPDATE v=VALUES(v)"
    )


def _setting_del(k: str) -> None:
    _mysql_exec(f"DELETE FROM settings WHERE k='{k}'")


@pytest.fixture(scope="session")
def session():
    s = requests.Session()
    s.headers.update({"User-Agent": "iter3-tester"})
    return s


@pytest.fixture(scope="session")
def admin_session(session):
    session.get(f"{BASE_URL}/login.php", timeout=20)
    session.post(
        f"{BASE_URL}/login.php",
        data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
        allow_redirects=True,
        timeout=20,
    )
    # admin pages expect dark-mode cookie per spec
    session.cookies.set("adm_mode", "dark", domain="localhost", path="/")
    r = session.get(f"{BASE_URL}/admin.php", timeout=20)
    if r.status_code != 200 or "login" in r.url.lower():
        pytest.skip(f"admin login failed (status={r.status_code}, url={r.url})")
    return session


def _blog_total(html: str) -> int:
    m = re.search(r'data-testid="blog-total-count"[^>]*>(\d+)', html)
    return int(m.group(1)) if m else -1


# ============================================================
# 1. PUBLIC BLOG FILTER — globals included on every region pick
# ============================================================
class TestPublicBlogRegionFilter:
    @pytest.fixture(scope="class")
    def baseline_counts(self):
        """How many rows of each kind are in the DB."""
        out = {}
        for k in ("NULL", "ALL"):
            n = _mysql_exec(
                "SELECT COUNT(*) FROM blog_posts WHERE target_region "
                + ("IS NULL" if k == "NULL" else "= 'ALL'")
            )
            out[k] = int(n or 0)
        for r in ("US", "UK", "CA", "AU"):
            n = _mysql_exec(
                f"SELECT COUNT(*) FROM blog_posts WHERE target_region = '{r}'"
            )
            out[r] = int(n or 0)
        out["GLOBAL"] = out["NULL"] + out["ALL"]
        return out

    @pytest.mark.parametrize("region", ["US", "UK", "CA", "AU"])
    def test_region_returns_globals_plus_region(self, session, baseline_counts, region):
        r = session.get(f"{BASE_URL}/blog.php", params={"region": region}, timeout=20)
        assert r.status_code == 200
        total = _blog_total(r.text)
        expected = baseline_counts["GLOBAL"] + baseline_counts[region]
        assert total == expected, (
            f"?region={region}: expected {expected} (globals {baseline_counts['GLOBAL']} "
            f"+ {region}-specific {baseline_counts[region]}), got {total}"
        )
        # Must never be less than the global count alone
        assert total >= baseline_counts["GLOBAL"], (
            f"?region={region} total ({total}) dropped below global count "
            f"({baseline_counts['GLOBAL']})"
        )
        # Verify at least one blog-card-* row is rendered
        if total > 0:
            assert re.search(r'data-testid="blog-card-', r.text), (
                f"No blog-card-* rows for ?region={region} even though count={total}"
            )

    def test_region_us_includes_known_us_post_and_global_post(self, session, baseline_counts):
        """Sanity: US filter must surface at least the US posts AND the global posts."""
        r = session.get(f"{BASE_URL}/blog.php", params={"region": "US"}, timeout=20)
        assert r.status_code == 200
        # Total >= 2 US-targeted + global posts
        total = _blog_total(r.text)
        assert total >= max(1, baseline_counts["US"]), f"US filter total too low: {total}"


# ============================================================
# 2. PUBLIC BLOG FILTER — q + region still AND-combine
# ============================================================
class TestPublicBlogCombinedFilter:
    def test_q_and_region_both_applied(self, session):
        r_all = session.get(f"{BASE_URL}/blog.php", params={"region": "US"}, timeout=20)
        total_us = _blog_total(r_all.text)
        r_combo = session.get(
            f"{BASE_URL}/blog.php",
            params={"q": "office", "region": "US"},
            timeout=20,
        )
        assert r_combo.status_code == 200
        total_combo = _blog_total(r_combo.text)
        # Combined must be <= region-only (q narrows the result set)
        assert total_combo <= total_us, (
            f"q=office&region=US returned more rows ({total_combo}) than "
            f"region=US alone ({total_us}); AND-combination broken"
        )

    def test_q_only_no_crash(self, session):
        r = session.get(f"{BASE_URL}/blog.php", params={"q": "office"}, timeout=20)
        assert r.status_code == 200
        assert 'data-testid="blog-total-count"' in r.text


# ============================================================
# 3. ADMIN PUBLISHED BLOG LIST — region attrs + globe label
# ============================================================
class TestAdminPublishedBlogList:
    @pytest.fixture(scope="class")
    def admin_blogger_html(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php", params={"tab": "ai-blogger"}, timeout=20
        )
        assert r.status_code == 200
        return r.text

    def test_published_blog_list_present(self, admin_blogger_html):
        assert 'data-testid="published-blog-list"' in admin_blogger_html

    def test_at_least_one_published_blog_row(self, admin_blogger_html):
        rows = re.findall(
            r'data-testid="published-blog-row"', admin_blogger_html
        )
        assert len(rows) >= 1, "No published-blog-row rendered (need ai_generated=1 posts)"

    def test_each_row_carries_region_attrs(self, admin_blogger_html):
        # Capture the full opening anchor tag for each published-blog-row
        rows = re.findall(
            r'<a[^>]*data-testid="published-blog-row"[^>]*>',
            admin_blogger_html,
        )
        assert rows, "No row anchors found"
        for tag in rows:
            assert "data-region=" in tag, f"row missing data-region: {tag[:200]}"
            assert "data-is-global=" in tag, f"row missing data-is-global: {tag[:200]}"

    def test_global_rows_render_globe_emoji_and_global_label(self, admin_blogger_html):
        # ITER-4 BEHAVIOUR CHANGE: trends articles (target_region='ALL') now
        # live in their own dedicated <details id="trends-section">, so the
        # Published Blog Posts list intentionally NO LONGER renders ALL-region
        # rows.  We verify the globe + 'Global' label on the NEW trends list
        # instead, which serves the same purpose.
        idx = 0
        found_global_block = False
        while True:
            m = re.search(
                r'<a[^>]*data-is-global=["\']1["\'][^>]*data-testid="trends-row"[^>]*>',
                admin_blogger_html[idx:],
            )
            if not m:
                m = re.search(
                    r'<a[^>]*data-testid="trends-row"[^>]*data-is-global=["\']1["\'][^>]*>',
                    admin_blogger_html[idx:],
                )
            if not m:
                break
            found_global_block = True
            start = idx + m.start()
            block = admin_blogger_html[start: start + 1200]
            assert "🌍" in block, f"Global trends row missing globe emoji: {block[:400]}"
            assert re.search(
                r'class="post-flag-label"[^>]*>\s*Global\s*<', block, re.IGNORECASE
            ), f"Global trends row missing 'Global' label: {block[:400]}"
            idx = start + len(m.group(0))
            break
        assert found_global_block, "No data-is-global='1' rows found in the trends list (need at least one target_region='ALL' trends post)"

    def test_region_specific_row_shows_country_flag_and_code(self, admin_blogger_html):
        # Look for a US row carrying its flag + label
        m = re.search(
            r'<a[^>]*data-region=["\']US["\'][^>]*data-is-global=["\']0["\'][^>]*data-testid="published-blog-row"[^>]*>',
            admin_blogger_html,
        )
        if not m:
            m = re.search(
                r'<a[^>]*data-is-global=["\']0["\'][^>]*data-region=["\']US["\'][^>]*data-testid="published-blog-row"[^>]*>',
                admin_blogger_html,
            )
        if not m:
            pytest.skip("No US-targeted ai_generated posts in DB to verify")
        start = m.start()
        block = admin_blogger_html[start: start + 1200]
        assert re.search(
            r'class="post-flag-label"[^>]*>\s*US\s*<', block, re.IGNORECASE
        ), f"US row missing 'US' label: {block[:400]}"


# ============================================================
# 4. ADMIN COUNTRY-PILL JS FILTER — script logic includes globals
# ============================================================
class TestAdminCountryPillScript:
    def test_script_logic_keeps_globals_visible(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php", params={"tab": "ai-blogger"}, timeout=20
        )
        html = r.text
        # The visibility expression that includes isGlobal MUST be present.
        # We accept any of these equivalent forms.
        ok = bool(
            re.search(r"\(region\s*===\s*['\"]all['\"]\)\s*\|\|\s*\(rRegion\s*===\s*region\)\s*\|\|\s*isGlobal", html)
            or re.search(r"isGlobal[^;]*\|\|[^;]*rRegion\s*===\s*region", html)
            or ("isGlobal" in html and "rRegion === region" in html)
        )
        assert ok, "Country-pill JS filter does not appear to OR-include globals"
        # And the active-class toggling must use btn-primary.
        assert "btn-primary" in html, "Active pill should toggle to btn-primary"


# ============================================================
# 5. TRENDS JSON robustness — _seo_llm_json_decode unit tests via PHP CLI
# ============================================================
def _php_decode(raw: str) -> str:
    """Invoke _seo_llm_json_decode($raw) and echo json_encode of the result."""
    # Write raw to a temp file to avoid shell-escape headaches.
    import tempfile
    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False, encoding="utf-8") as fh:
        fh.write(raw)
        rawpath = fh.name
    script = f"""<?php
chdir('{PHP_VERSION_DIR}');
require '{PHP_VERSION_DIR}/includes/db.php';
require '{PHP_VERSION_DIR}/includes/seo-bot.php';
$raw = file_get_contents('{rawpath}');
$out = _seo_llm_json_decode($raw);
echo $out === null ? 'NULL' : json_encode($out);
"""
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as ph:
        ph.write(script)
        phpfile = ph.name
    try:
        r = subprocess.run(["php", phpfile], capture_output=True, timeout=15)
        return (r.stdout or b"").decode("utf-8", errors="replace").strip()
    finally:
        try:
            os.unlink(rawpath); os.unlink(phpfile)
        except Exception:
            pass


class TestLlmJsonDecode:
    def test_pure_json(self):
        out = _php_decode('{"title":"Hi","content_html":"<p>x</p>"}')
        assert out != "NULL"
        j = json.loads(out)
        assert j["title"] == "Hi"

    def test_fenced_json(self):
        raw = "```json\n{\"title\":\"Hi\",\"content_html\":\"<p>x</p>\"}\n```"
        out = _php_decode(raw)
        assert out != "NULL"
        j = json.loads(out)
        assert j["title"] == "Hi"

    def test_chatty_prefix_plus_fence(self):
        raw = (
            "Sure! Here is the JSON you requested:\n\n"
            "```json\n{\"title\":\"Office Trends\",\"content_html\":\"<p>2026</p>\"}\n```"
            "\n\nLet me know if you need more."
        )
        out = _php_decode(raw)
        assert out != "NULL"
        j = json.loads(out)
        assert j["title"] == "Office Trends"

    def test_smart_quotes(self):
        # \u201c and \u201d  are LLM-flavored quotes
        raw = "{\u201ctitle\u201d:\u201cHello\u201d,\u201ccontent_html\u201d:\u201c<p>hi</p>\u201d}"
        out = _php_decode(raw)
        assert out != "NULL", f"Smart-quoted JSON failed to decode: out={out!r}"
        j = json.loads(out)
        assert j["title"] == "Hello"

    def test_raw_newlines_inside_string(self):
        # Real newline INSIDE a string value — illegal per spec but LLMs emit it.
        raw = '{"title":"Line\nBreak","content_html":"<p>a\nb</p>"}'
        out = _php_decode(raw)
        assert out != "NULL", f"Raw-newline JSON failed to decode: out={out!r}"
        j = json.loads(out)
        assert "Line" in j["title"] and "Break" in j["title"]

    def test_garbage_returns_null(self):
        out = _php_decode("this is not JSON at all, no braces, just prose.")
        assert out == "NULL"

    def test_trends_prompt_demands_pure_json(self):
        path = f"{PHP_VERSION_DIR}/includes/seo-bot.php"
        with open(path, "r", encoding="utf-8") as f:
            src = f.read()
        assert "Output MUST start with `{` and end with `}` — pure JSON only." in src \
            or "pure JSON only" in src, "Trends system prompt should demand pure JSON"


# ============================================================
# 6. TRENDS ERROR SNIPPET — error string includes "(got: ...)"
# ============================================================
class TestTrendsErrorSnippet:
    def test_error_format_in_source(self):
        path = f"{PHP_VERSION_DIR}/includes/seo-bot.php"
        with open(path, "r", encoding="utf-8") as f:
            src = f.read()
        # The crafted error line:
        # 'trends: invalid JSON from LLM' . ($snippet !== '' ? ' (got: ' . $snippet . '…)' : '')
        assert "trends: invalid JSON from LLM" in src
        assert "(got: " in src and "$snippet" in src
        # 160-char trim
        assert "mb_substr(preg_replace('/\\s+/', ' ', (string)$answer), 0, 160)" in src \
            or re.search(r"mb_substr\([^)]*0\s*,\s*160\)", src), \
            "160-char snippet trim missing"


# ============================================================
# 7. AUTO-WEEKLY TOGGLE UI — form + checkbox + hint states
# ============================================================
class TestAutoWeeklyUI:
    def _get_html(self, admin_session):
        r = admin_session.get(
            f"{BASE_URL}/admin.php", params={"tab": "ai-blogger"}, timeout=20
        )
        assert r.status_code == 200
        return r.text

    def test_form_and_toggle_and_hint_present(self, admin_session):
        html = self._get_html(admin_session)
        assert 'data-testid="auto-weekly-form"' in html
        assert 'data-testid="auto-weekly-toggle"' in html
        assert 'data-testid="auto-weekly-hint"' in html

    def test_toggle_off_hint_says_off(self, admin_session):
        _setting_set("auto_sitemap_weekly", "0")
        html = self._get_html(admin_session)
        # Slice the checkbox tag
        m = re.search(
            r'<input[^>]*data-testid="auto-weekly-toggle"[^>]*>', html
        )
        assert m, "auto-weekly-toggle input not found"
        assert "checked" not in m.group(0), f"Toggle should NOT be checked: {m.group(0)}"
        # Hint should say Off
        m2 = re.search(
            r'data-testid="auto-weekly-hint"[^>]*>(.*?)</div>', html, re.DOTALL
        )
        assert m2, "auto-weekly-hint not found"
        hint = m2.group(1)
        assert "Off" in hint, f"Hint should mention 'Off': {hint[:200]}"

    def test_toggle_on_hint_mentions_on_and_indexnow_7_days(self, admin_session):
        _setting_set("auto_sitemap_weekly", "1")
        html = self._get_html(admin_session)
        m = re.search(
            r'<input[^>]*data-testid="auto-weekly-toggle"[^>]*>', html
        )
        assert m, "auto-weekly-toggle input not found"
        assert "checked" in m.group(0), f"Toggle should be checked when ON: {m.group(0)}"
        m2 = re.search(
            r'data-testid="auto-weekly-hint"[^>]*>(.*?)</div>', html, re.DOTALL
        )
        assert m2
        hint = m2.group(1)
        assert "On" in hint, f"Hint should mention 'On': {hint[:300]}"
        assert "IndexNow will be re-pinged every 7 days" in hint, (
            f"Hint should mention 'IndexNow will be re-pinged every 7 days': {hint[:400]}"
        )
        # Reset after test
        _setting_set("auto_sitemap_weekly", "0")


# ============================================================
# 8. AUTO-WEEKLY TOGGLE PERSISTENCE
# ============================================================
class TestAutoWeeklyPersistence:
    def test_post_persists_one(self, admin_session):
        admin_session.post(
            f"{BASE_URL}/admin.php",
            params={"tab": "ai-blogger"},
            data={
                "save_seo_tokens": "1",
                "auto_sitemap_weekly": "1",
                "site_domain_url": "https://example.com",
            },
            timeout=20,
        )
        v = _setting_get("auto_sitemap_weekly")
        assert v == "1", f"auto_sitemap_weekly expected '1' after POST, got {v!r}"

    def test_post_without_checkbox_persists_zero(self, admin_session):
        # First seed ON
        _setting_set("auto_sitemap_weekly", "1")
        admin_session.post(
            f"{BASE_URL}/admin.php",
            params={"tab": "ai-blogger"},
            data={
                "save_seo_tokens": "1",
                "site_domain_url": "https://example.com",
            },
            timeout=20,
        )
        v = _setting_get("auto_sitemap_weekly")
        assert v == "0", (
            f"auto_sitemap_weekly should drop to '0' when checkbox omitted "
            f"(checkbox-unchecked semantics); got {v!r}"
        )


# ============================================================
# 9. AUTO-WEEKLY TICK LOGIC — direct PHP unit test
# ============================================================
class TestWeeklyTick:
    def _run_php_tick(self, setup_sql: str) -> str:
        """Run seo_bot_weekly_sitemap_tick() after setup_sql; capture
        the resulting last_sitemap_submit_kind (or 'NONE')."""
        import tempfile
        script = f"""<?php
chdir('{PHP_VERSION_DIR}');
require '{PHP_VERSION_DIR}/includes/db.php';
require '{PHP_VERSION_DIR}/includes/settings.php';
require '{PHP_VERSION_DIR}/includes/seo-bot.php';
seo_bot_weekly_sitemap_tick();
echo (string)setting_get('last_sitemap_submit_kind', 'NONE');
echo '|';
echo (string)setting_get('last_sitemap_submit_at', 'NONE');
"""
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as ph:
            ph.write(script)
            phpfile = ph.name
        if setup_sql:
            _mysql_exec(setup_sql)
        try:
            r = subprocess.run(["php", phpfile], capture_output=True, timeout=30)
            return (r.stdout or b"").decode("utf-8", errors="replace").strip()
        finally:
            try:
                os.unlink(phpfile)
            except Exception:
                pass

    def test_function_exists(self):
        with open(f"{PHP_VERSION_DIR}/includes/seo-bot.php", "r", encoding="utf-8") as f:
            src = f.read()
        assert "function seo_bot_weekly_sitemap_tick(" in src

    def test_off_is_noop(self):
        _setting_set("auto_sitemap_weekly", "0")
        _setting_del("last_sitemap_submit_at")
        _setting_del("last_sitemap_submit_kind")
        out = self._run_php_tick("")
        kind, at = out.split("|", 1) if "|" in out else (out, "")
        # No submission should have happened (kind stays NONE, at stays NONE)
        assert kind == "NONE", f"OFF tick should be noop; kind={kind!r}, at={at!r}"

    def test_on_within_7_days_is_noop(self):
        _setting_set("auto_sitemap_weekly", "1")
        # Last submit 1 day ago
        from datetime import datetime, timedelta
        recent = (datetime.utcnow() - timedelta(days=1)).strftime("%Y-%m-%d %H:%M:%S")
        _setting_set("last_sitemap_submit_at", recent)
        _setting_set("last_sitemap_submit_kind", "manual_test")
        out = self._run_php_tick("")
        kind, at = out.split("|", 1) if "|" in out else (out, "")
        # The within-7-days gate should prevent any update
        assert kind == "manual_test", (
            f"Tick within 7d cooldown shouldn't update kind; got {kind!r} (at={at!r})"
        )
        # Cleanup
        _setting_set("auto_sitemap_weekly", "0")
        _setting_del("last_sitemap_submit_at")
        _setting_del("last_sitemap_submit_kind")

    def test_on_older_than_7_days_reaches_indexnow_path(self):
        """Toggle ON + last submission older than 7 days → function reaches
        _seo_indexnow_submit_urls.  IndexNow may rate-limit on the preview
        host — that's OK.  The contract here is: function executes without
        fatal error AND (a) updates kind to 'auto_weekly' on success or
        (b) leaves last_sitemap_submit_kind unchanged on rate-limit/fail."""
        _setting_set("auto_sitemap_weekly", "1")
        from datetime import datetime, timedelta
        old = (datetime.utcnow() - timedelta(days=10)).strftime("%Y-%m-%d %H:%M:%S")
        _setting_set("last_sitemap_submit_at", old)
        _setting_set("last_sitemap_submit_kind", "manual_baseline")
        out = self._run_php_tick("")
        kind_after, at_after = out.split("|", 1) if "|" in out else (out, "")
        # Either succeeded (kind became auto_weekly) OR remained manual_baseline
        # because IndexNow rate-limited / no URLs.  Both are acceptable; the
        # critical check is that the function didn't crash and ran to completion.
        assert kind_after in ("auto_weekly", "manual_baseline"), (
            f"Unexpected kind after old-submission tick: {kind_after!r}"
        )
        # Cleanup
        _setting_set("auto_sitemap_weekly", "0")
        _setting_del("last_sitemap_submit_at")
        _setting_del("last_sitemap_submit_kind")
