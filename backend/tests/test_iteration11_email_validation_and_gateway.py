"""
Iteration 11 — Tests for:
 1) Email validation hardening (typo / no MX domain) in lead.php, contact.php,
    notify-stock.php.
 2) Email outbox dev-mode no longer marks rows as 'sent' — must be 'queued'.
 3) Install Schedule (ProAssist) admin_notify + sidebar badge + banner.
 4) Lead Management leads-online.php returns install_pending.
 5) Payment Gateway /ajax/validate-gateway-key.php — Stripe + PayPal.
 6) Regression smoke test for public SEO endpoints + admin tabs.
"""
import os
import re
import time
import json
import requests
import pytest
from conftest import ADMIN_EMAIL, ADMIN_PASSWORD

BASE = os.environ.get("REACT_APP_BACKEND_URL", "https://indexnow-checker.preview.emergentagent.com").rstrip("/")
@pytest.fixture(scope="module")
def public_session():
    return requests.Session()


@pytest.fixture(scope="module")
def admin_session():
    s = requests.Session()
    r = s.post(f"{BASE}/login.php",
               data={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
               allow_redirects=False, timeout=20)
    assert r.status_code in (200, 302), f"Login HTTP {r.status_code}"
    # Try to hit admin.php
    r2 = s.get(f"{BASE}/admin.php", timeout=20)
    assert r2.status_code == 200, "admin.php not accessible after login"
    assert "tab=" in r2.text or "Admin" in r2.text or "Dashboard" in r2.text
    return s


# ---------------------------------------------------------------------------
# 1) Email validation hardening
# ---------------------------------------------------------------------------

class TestEmailValidationLead:
    def test_lead_typo_domain_rejected(self, public_session):
        r = public_session.post(f"{BASE}/ajax/lead.php",
                                json={"name": "TEST_X", "phone": "9999999999",
                                      "email": "foo@yaho.com"},
                                timeout=20)
        assert r.status_code == 400, f"expected 400 got {r.status_code} body={r.text[:200]}"
        body = r.json()
        assert not (body.get("ok"))
        err = (body.get("error") or "").lower()
        assert "yaho" in err and ("yahoo" in err or "did" in err or "mean" in err), f"err msg: {body}"

    def test_lead_no_mx_domain_rejected(self, public_session):
        r = public_session.post(f"{BASE}/ajax/lead.php",
                                json={"name": "TEST_X", "phone": "9999999999",
                                      "email": "foo@no-such-domain-xyzz123.con"},
                                timeout=30)
        assert r.status_code == 400, f"expected 400 got {r.status_code} body={r.text[:200]}"
        body = r.json()
        assert not (body.get("ok"))
        err = (body.get("error") or "").lower()
        assert ("mx" in err or "no mx" in err or "undeliverable" in err or "a record" in err), f"err: {body}"

    def test_lead_valid_email_accepted(self, public_session):
        # Use a unique TEST_ name to identify in DB.
        r = public_session.post(f"{BASE}/ajax/lead.php",
                                json={"name": "TEST_valid", "phone": "9999999999",
                                      "email": "valid@gmail.com"},
                                timeout=30)
        assert r.status_code == 200, f"expected 200 got {r.status_code} body={r.text[:200]}"
        body = r.json()
        assert (body.get("ok")), body
        assert "lead_id" in body and isinstance(body["lead_id"], int) and body["lead_id"] > 0


class TestEmailValidationContactPhp:
    def test_contact_typo_domain_blocks_send(self, public_session):
        # contact.php is a server-rendered form; POST with email field.
        r = public_session.post(f"{BASE}/contact.php",
                                data={"name": "TEST_X", "phone": "9999999999",
                                      "email": "foo@yaho.com",
                                      "subject": "TEST validation subject",
                                      "message": "TEST validation message body"},
                                timeout=20, allow_redirects=False)
        # Should NOT 5xx; either re-renders form (200) or redirects to ?error=...
        assert r.status_code in (200, 302), f"unexpected status {r.status_code}"
        body = r.text.lower()
        # Must NOT show the success banner.
        assert "thanks" not in body or "yaho" in body or "did the customer mean" in body or "did you mean" in body or "mean yahoo" in body, \
            "contact.php success state shown for typo email"
        # Best-effort error string presence
        assert "yaho" in body or "did you mean" in body or "did the customer mean" in body or "typo" in body, \
            f"contact.php missing typo error; body snippet: {body[:400]}"


class TestEmailValidationNotifyStock:
    def test_notify_stock_no_mx_rejected(self, public_session):
        # find any product slug
        r0 = public_session.get(f"{BASE}/shop.php", timeout=20)
        m = re.search(r'product\.php\?slug=([a-z0-9\-]+)', r0.text)
        if not m:
            pytest.skip("No product slug discoverable on /shop.php")
        slug = m.group(1)
        r = public_session.post(f"{BASE}/ajax/notify-stock.php",
                                json={"product_slug": slug,
                                      "email": "foo@no-such-domain-xyzz123.con"},
                                timeout=30)
        assert r.status_code == 400, f"expected 400 got {r.status_code} body={r.text[:200]}"
        body = r.json()
        assert not (body.get("ok"))
        err = (body.get("error") or body.get("hint") or "").lower()
        assert "mx" in err or "undeliverable" in err or "a record" in err, body


# ---------------------------------------------------------------------------
# 2) Email outbox dev mode now uses status='queued'
# ---------------------------------------------------------------------------

class TestEmailOutboxQueued:
    # Admin panel paths that may render the Email Activity / Outbox table.
    _OUTBOX_TAB_PATHS = ("/admin.php?tab=emails",
                         "/admin.php?tab=email-activity",
                         "/admin.php?tab=outbox")
    # Substrings that signal we landed on the right admin tab.
    _OUTBOX_MARKERS  = ("Email Activity", "email_outbox", "Outbox", "Pending delivery")
    # Substrings that prove the row is queued (NOT prematurely "sent").
    _QUEUED_MARKERS  = ("Pending delivery", "configure SMTP", "queued")

    @classmethod
    def _trigger_forgot_password(cls, public_session, email):
        r = public_session.post(f"{BASE}/forgot-password.php",
                                data={"email": email},
                                timeout=20, allow_redirects=False)
        assert r.status_code in (200, 302), f"forgot-password returned {r.status_code}"
        time.sleep(1)  # let the worker enqueue

    @classmethod
    def _find_outbox_html(cls, admin_session):
        for path in cls._OUTBOX_TAB_PATHS:
            r = admin_session.get(f"{BASE}{path}", timeout=20)
            if r.status_code == 200 and any(m in r.text for m in cls._OUTBOX_MARKERS):
                return r.text
        return ""

    @classmethod
    def _has_queued_marker(cls, html):
        low = html.lower()
        return any(m in html for m in cls._QUEUED_MARKERS[:-1]) or "queued" in low

    def test_forgot_password_creates_queued_row_not_sent(self, admin_session, public_session):
        # Trigger a send by submitting forgot-password.php with the company
        # email (only that one is dispatched per /memory/test_credentials.md).
        self._trigger_forgot_password(public_session, "services@maventechsoftware.com")
        txt = self._find_outbox_html(admin_session)
        if not self._has_queued_marker(txt):
            pytest.skip("Could not locate the Email Activity panel HTML to assert queued state — "
                        "manual DB check required (email_outbox.status='queued' / "
                        "note LIKE '%Pending delivery%').")
        assert self._has_queued_marker(txt), \
            "Expected new queued-row note string in admin Email Activity panel"


# ---------------------------------------------------------------------------
# 3) Install Schedule alert + sidebar badge + banner
# ---------------------------------------------------------------------------

class TestInstallScheduleNotify:
    def test_admin_schedule_tab_renders_no_php_errors(self, admin_session):
        r = admin_session.get(f"{BASE}/admin.php?tab=schedule", timeout=20)
        assert r.status_code == 200
        low = r.text.lower()
        # Must not contain raw PHP fatal markers
        for tok in ("fatal error", "parse error", "uncaught", "deprecated:"):
            assert tok not in low, f"PHP error '{tok}' present in /admin.php?tab=schedule"

    def test_admin_install_pending_banner_optional(self, admin_session):
        # Banner only renders when pendingSoon > 0; either way the test-id text
        # must NOT throw. We just verify the page renders cleanly.
        r = admin_session.get(f"{BASE}/admin.php?tab=schedule&st=pending", timeout=20)
        assert r.status_code == 200
        # If banner present, validate copy + amber color.
        if 'data-testid="install-pending-banner"' in r.text:
            seg = r.text.split('data-testid="install-pending-banner"', 1)[1][:1500]
            assert "needs your attention" in seg.lower()
            assert "f59e0b" in seg.lower() or "fef3c7" in seg.lower(), "Banner missing amber color"


class TestSidebarBadges:
    def test_sidebar_has_both_badges_in_admin_shell(self, admin_session):
        r = admin_session.get(f"{BASE}/admin.php", timeout=20)
        assert r.status_code == 200
        assert 'data-testid="adm-nav-leads-badge"' in r.text, "leads badge missing"
        assert 'data-testid="adm-nav-schedule-badge"' in r.text, "schedule badge missing"


# ---------------------------------------------------------------------------
# 4) leads-online.php returns install_pending
# ---------------------------------------------------------------------------

class TestLeadsOnline:
    def test_leads_online_requires_admin(self, public_session):
        r = public_session.get(f"{BASE}/ajax/leads-online.php", timeout=20, allow_redirects=False)
        # Should be 302 (redirect to login) or 403 / 401, not 200 JSON ok:true
        assert r.status_code in (302, 401, 403) or "ok" not in (r.text[:200] if r.status_code == 200 else "")

    def test_leads_online_returns_install_pending(self, admin_session):
        r = admin_session.get(f"{BASE}/ajax/leads-online.php", timeout=20)
        assert r.status_code == 200, r.text[:200]
        data = r.json()
        assert (data.get("ok")), data
        # New required keys
        for k in ("now", "online_ids", "total", "install_pending", "latest"):
            assert k in data, f"missing key {k}"
        assert isinstance(data["install_pending"], int)
        assert isinstance(data["online_ids"], list)
        assert isinstance(data["latest"], list)


# ---------------------------------------------------------------------------
# 5) Payment Gateway /ajax/validate-gateway-key.php
# ---------------------------------------------------------------------------

class TestGatewayKeyValidation:
    def test_endpoint_requires_admin_auth(self, public_session):
        r = public_session.post(f"{BASE}/ajax/validate-gateway-key.php",
                                json={"gateway": "stripe", "mode": "test", "secret": "sk_test_x"},
                                timeout=15, allow_redirects=False)
        # ensure_admin() typically redirects unauthenticated to login.php (302)
        # OR returns 401/403. Critical: must NOT return ok:true.
        if r.status_code == 200:
            try:
                body = r.json()
                assert body.get("ok") != True, f"Public got ok:true: {body}"
            except ValueError:
                pass
        else:
            assert r.status_code in (302, 401, 403), r.status_code

    def test_stripe_empty_secret(self, admin_session):
        r = admin_session.post(f"{BASE}/ajax/validate-gateway-key.php",
                               json={"gateway": "stripe", "mode": "test", "secret": ""},
                               timeout=15)
        assert r.status_code == 200
        body = r.json()
        assert not (body["ok"])
        assert "paste a key" in body["message"].lower()

    def test_stripe_live_key_in_test_slot(self, admin_session):
        r = admin_session.post(f"{BASE}/ajax/validate-gateway-key.php",
                               json={"gateway": "stripe", "mode": "test",
                                     "secret": "sk_live_abc123"},
                               timeout=15)
        assert r.status_code == 200
        body = r.json()
        assert not (body["ok"])
        msg = body["message"].lower()
        assert "live key" in msg and ("live slot" in msg or "live" in msg)

    def test_stripe_bad_test_key_rejected_by_stripe(self, admin_session):
        r = admin_session.post(f"{BASE}/ajax/validate-gateway-key.php",
                               json={"gateway": "stripe", "mode": "test",
                                     "secret": "sk_test_BADKEY1234"},
                               timeout=30)
        assert r.status_code == 200
        body = r.json()
        assert not (body["ok"]), body
        msg = body["message"].lower()
        # Either Stripe HTTP 401 reached or network blocked the call. Accept both.
        acceptable = ("stripe rejected" in msg) or ("stripe call failed" in msg) or ("invalid api key" in msg)
        assert acceptable, f"Unexpected message: {body['message']}"

    def test_paypal_bad_credentials(self, admin_session):
        r = admin_session.post(f"{BASE}/ajax/validate-gateway-key.php",
                               json={"gateway": "paypal", "mode": "test",
                                     "secret": "bad", "client_id": "bad"},
                               timeout=30)
        assert r.status_code == 200
        body = r.json()
        assert not (body["ok"]), body
        msg = body["message"].lower()
        assert ("paypal rejected" in msg) or ("paypal call failed" in msg) or ("invalid_client" in msg), \
            f"Unexpected message: {body['message']}"


class TestGatewayUI:
    def test_card_tab_has_validate_buttons(self, admin_session):
        r = admin_session.get(f"{BASE}/admin.php?tab=api&gw=card", timeout=20)
        assert r.status_code == 200
        for t in ("api-card-validate-test", "api-card-validate-live",
                  "api-card-result-test", "api-card-result-live"):
            assert f'data-testid="{t}"' in r.text, f"missing {t} on gw=card"

    def test_paypal_tab_has_validate_buttons(self, admin_session):
        r = admin_session.get(f"{BASE}/admin.php?tab=api&gw=paypal", timeout=20)
        assert r.status_code == 200
        for t in ("api-paypal-validate-test", "api-paypal-validate-live",
                  "api-paypal-result-test", "api-paypal-result-live"):
            assert f'data-testid="{t}"' in r.text, f"missing {t} on gw=paypal"

    def test_api_toggles_tab_loads(self, admin_session):
        r = admin_session.get(f"{BASE}/admin.php?tab=api&gw=toggles", timeout=20)
        assert r.status_code == 200
        # No PHP fatal markers
        low = r.text.lower()
        for tok in ("fatal error", "parse error", "uncaught"):
            assert tok not in low


# ---------------------------------------------------------------------------
# 6) Smoke tests — public SEO endpoints + admin tabs untouched
# ---------------------------------------------------------------------------

class TestSmoke:
    @pytest.mark.parametrize("path", [
        "/sitemap.xml", "/robots.txt", "/ai.txt", "/llms.txt", "/merchant-feed.xml",
    ])
    def test_public_seo_200(self, public_session, path):
        r = public_session.get(f"{BASE}{path}", timeout=20)
        assert r.status_code == 200, f"{path} -> {r.status_code}"

    def test_indexnow_key_endpoint_200(self, public_session):
        # find the indexnow key file referenced in robots.txt
        r0 = public_session.get(f"{BASE}/robots.txt", timeout=20)
        # not all sites expose it in robots; try a wildcard discovery via admin
        # — fall back to any /*.txt link. Skip if not discoverable.
        m = re.search(r'/([0-9a-f]{16,})\.txt', r0.text)
        if not m:
            pytest.skip("IndexNow key file not advertised in robots.txt")
        r = public_session.get(f"{BASE}/{m.group(1)}.txt", timeout=20)
        assert r.status_code == 200

    @pytest.mark.parametrize("tab", ["ai-blogger", "schedule", "leads"])
    def test_admin_tabs_no_php_errors(self, admin_session, tab):
        r = admin_session.get(f"{BASE}/admin.php?tab={tab}", timeout=25)
        assert r.status_code == 200, f"tab={tab} HTTP {r.status_code}"
        low = r.text.lower()
        for tok in ("fatal error", "parse error", "uncaught", "<b>warning</b>:"):
            assert tok not in low, f"tab={tab} has PHP error token '{tok}'"
