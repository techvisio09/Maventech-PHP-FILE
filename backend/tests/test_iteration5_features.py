"""
Iteration 5 — SEO/AEO/GEO upgrades
Tests for: WebSite+SearchAction, ContactPage, AboutPage, breadcrumbs,
Quick Answer / People-Also-Ask, blog E-E-A-T, freshness tick, robots.txt.
"""
import json
import os
import re
import pytest
import requests

BASE_URL = os.environ.get("PHP_BASE_URL", "http://localhost:3000").rstrip("/")
PHP_ROOT = "/app/php-version"


def _get(path):
    r = requests.get(f"{BASE_URL}{path}", timeout=30,
                     headers={"User-Agent": "iter5-tester"})
    return r


def _jsonld_blocks(html):
    """Extract & decode every <script type=application/ld+json> block."""
    out = []
    for m in re.finditer(
        r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
        html, re.DOTALL | re.IGNORECASE):
        try:
            out.append(json.loads(m.group(1).strip()))
        except Exception:
            pass
    return out


def _find_type(blocks, t):
    """Walk every block + @graph for an entity with @type == t."""
    for b in blocks:
        if isinstance(b, dict):
            if b.get("@type") == t:
                return b
            for g in b.get("@graph", []) or []:
                if isinstance(g, dict) and g.get("@type") == t:
                    return g
    return None


# ============== Homepage WebSite + SearchAction ==============
class TestHomepageWebsiteSearchAction:
    def setup_method(self):
        r = _get("/")
        assert r.status_code == 200
        self.html = r.text
        self.blocks = _jsonld_blocks(self.html)

    def test_homepage_has_two_jsonld_blocks(self):
        assert len(self.blocks) == 2, f"Expected 2 JSON-LD blocks, got {len(self.blocks)}"

    def test_website_block_present(self):
        ws = _find_type(self.blocks, "WebSite")
        assert ws is not None
        assert str(ws.get("@id", "")).endswith("#website")

    def test_searchaction_target_template(self):
        ws = _find_type(self.blocks, "WebSite")
        action = ws.get("potentialAction") or {}
        assert action.get("@type") == "SearchAction"
        tmpl = action.get("target", {}).get("urlTemplate") if isinstance(action.get("target"), dict) else action.get("target")
        assert "/shop.php?q={search_term_string}" in str(tmpl)
        assert action.get("query-input") == "required name=search_term_string"


# ============== ContactPage ==============
class TestContactPageSchema:
    def setup_method(self):
        r = _get("/contact.php")
        assert r.status_code == 200
        self.blocks = _jsonld_blocks(r.text)

    def test_contactpage_present(self):
        cp = _find_type(self.blocks, "ContactPage")
        assert cp is not None, "ContactPage JSON-LD missing"
        assert str(cp.get("@id", "")).endswith("#contactpage")

    def test_contactpage_about_org(self):
        cp = _find_type(self.blocks, "ContactPage")
        about = cp.get("about") or {}
        about_id = about.get("@id") if isinstance(about, dict) else about
        assert str(about_id).endswith("#organization")

    def test_contactpage_contactpoint_array(self):
        cp = _find_type(self.blocks, "ContactPage")
        # Gather contactPoints from ContactPage directly OR from its about/mainEntity
        # Organization OR from the top-level Organization @graph entry.
        candidates = []
        def _grab(node):
            if isinstance(node, dict):
                cps = node.get("contactPoint")
                if isinstance(cps, list):
                    candidates.extend(cps)
                for v in node.values():
                    if isinstance(v, (dict, list)):
                        _grab(v)
            elif isinstance(node, list):
                for it in node:
                    _grab(it)
        for b in self.blocks:
            _grab(b)
        assert candidates, "no contactPoint anywhere in Contact JSON-LD"
        types = [str(c.get("contactType", "")).lower() for c in candidates]
        assert any("customer" in t or "support" in t or "service" in t for t in types), f"missing customer-support contactPoint: {types}"
        assert any("sales" in t for t in types), f"missing sales contactPoint: {types}"


# ============== AboutPage ==============
class TestAboutPageSchema:
    def setup_method(self):
        r = _get("/about-us.php")
        assert r.status_code == 200
        self.blocks = _jsonld_blocks(r.text)

    def test_aboutpage_present(self):
        ap = _find_type(self.blocks, "AboutPage")
        assert ap is not None, "AboutPage JSON-LD missing"

    def test_aboutpage_mainentity_eeat(self):
        ap = _find_type(self.blocks, "AboutPage")
        me = ap.get("mainEntity") or {}
        assert isinstance(me, dict)
        for k in ("foundingDate", "knowsAbout", "aggregateRating", "award"):
            assert k in me, f"AboutPage.mainEntity missing E-E-A-T field: {k}"


# ============== Blog post JSON-LD ==============
class TestBlogPostJsonLd:
    def setup_method(self):
        r = _get("/blog-post.php?id=1")
        assert r.status_code == 200
        self.html = r.text
        self.blocks = _jsonld_blocks(r.text)

    def test_article_block_present(self):
        art = _find_type(self.blocks, "Article") or _find_type(self.blocks, "BlogPosting")
        assert art is not None, "Article / BlogPosting JSON-LD missing"
        for k in ("headline", "datePublished", "dateModified", "author",
                  "publisher", "mainEntityOfPage", "wordCount", "timeRequired",
                  "articleSection"):
            assert k in art, f"Article field missing: {k}"

    def test_wordcount_is_positive_int(self):
        art = _find_type(self.blocks, "Article") or _find_type(self.blocks, "BlogPosting")
        wc = art.get("wordCount")
        assert isinstance(wc, int) and wc > 0, wc

    def test_time_required_iso8601(self):
        art = _find_type(self.blocks, "Article") or _find_type(self.blocks, "BlogPosting")
        tr = art.get("timeRequired", "")
        assert re.match(r"^PT\d+M$", str(tr)), tr

    def test_publisher_org_id(self):
        art = _find_type(self.blocks, "Article") or _find_type(self.blocks, "BlogPosting")
        pub = art.get("publisher") or {}
        pub_id = pub.get("@id", "")
        assert str(pub_id).endswith("#organization"), pub_id

    def test_author_person_or_org_with_knowsabout(self):
        art = _find_type(self.blocks, "Article") or _find_type(self.blocks, "BlogPosting")
        author = art.get("author")
        if isinstance(author, list):
            author = author[0]
        assert isinstance(author, dict)
        assert author.get("@type") in ("Person", "Organization")
        if author.get("@type") == "Organization":
            assert "knowsAbout" in author

    def test_speakable_present(self):
        art = _find_type(self.blocks, "Article") or _find_type(self.blocks, "BlogPosting")
        assert "speakable" in {k.lower() for k in art.keys()}, list(art.keys())


# ============== Blog post visible UI (no lead — id=1) ==============
class TestBlogPostVisible:
    def setup_method(self):
        r = _get("/blog-post.php?id=1")
        self.html = r.text

    def test_byline_present(self):
        assert 'data-testid="blog-post-byline"' in self.html

    def test_last_updated_stamp_shown_on_ai_post(self):
        # id=1 has NULL updated_at so the stamp is intentionally hidden.
        # Verify the stamp DOES render on the AI post that was refreshed.
        r = _get("/blog-post.php?id=ai-20260614-bitdefender-antivirus-for-mac-1-mac-1-year-us")
        assert 'data-testid="blog-post-last-updated"' in r.text

    def test_no_quick_answer_when_no_lead(self):
        # id=1 has NULL lead (per problem statement)
        assert 'data-testid="blog-post-quick-answer"' not in self.html


# ============== Blog post visible UI (AI post with lead) ==============
class TestBlogPostAiPostQuickAnswer:
    POST_ID = "ai-20260614-bitdefender-antivirus-for-mac-1-mac-1-year-us"

    def setup_method(self):
        r = _get(f"/blog-post.php?id={self.POST_ID}")
        assert r.status_code == 200
        self.html = r.text

    def test_ai_post_has_quick_answer(self):
        assert 'data-testid="blog-post-quick-answer"' in self.html

    def test_ai_post_has_author_badge(self):
        assert 'data-testid="blog-post-author-badge"' in self.html

    def test_ai_post_has_byline(self):
        assert 'data-testid="blog-post-byline"' in self.html


# ============== Product 7 JSON-LD blocks ==============
class TestProductSevenBlocks:
    SLUG = "bitdefender-antivirus-for-mac-1-mac-1-year"

    def setup_method(self):
        r = _get(f"/product.php?slug={self.SLUG}")
        assert r.status_code == 200
        self.html = r.text
        self.blocks = _jsonld_blocks(r.text)

    def test_seven_blocks(self):
        assert len(self.blocks) == 7, f"expected 7 JSON-LD blocks, got {len(self.blocks)}"

    def test_all_required_types_present(self):
        types = []
        for b in self.blocks:
            if isinstance(b, dict):
                if b.get("@graph"):
                    for g in b["@graph"]:
                        if isinstance(g, dict):
                            types.append(g.get("@type"))
                types.append(b.get("@type"))
        for required in ("Organization", "Product", "BreadcrumbList", "FAQPage", "HowTo", "Article"):
            assert required in types, f"missing {required} in {types}"
        # FAQPage must appear twice (product FAQ + PAA FAQ)
        assert types.count("FAQPage") >= 2, f"FAQPage count: {types.count('FAQPage')}"

    def test_product_offer_seller_and_reviews(self):
        prod = _find_type(self.blocks, "Product")
        assert prod is not None
        offers = prod.get("offers") or {}
        seller = offers.get("seller") if isinstance(offers, dict) else None
        assert seller and seller.get("name"), seller
        assert isinstance(prod.get("review"), list) and len(prod["review"]) > 0

    def test_paa_faqpage_has_six_questions(self):
        # second FAQPage = PAA
        faq_pages = []
        for b in self.blocks:
            if isinstance(b, dict) and b.get("@type") == "FAQPage":
                faq_pages.append(b)
        assert len(faq_pages) >= 2
        paa = faq_pages[-1]
        me = paa.get("mainEntity") or []
        assert len(me) >= 6, f"PAA FAQPage should have 6 questions; got {len(me)}"
        for q in me[:6]:
            assert q.get("@type") == "Question"
            assert q.get("name")
            assert q.get("acceptedAnswer", {}).get("text")


# ============== Product AEO Quick Answer ==============
class TestProductQuickAnswer:
    SLUG = "bitdefender-antivirus-for-mac-1-mac-1-year"

    def setup_method(self):
        r = _get(f"/product.php?slug={self.SLUG}")
        self.html = r.text

    def test_quick_answer_present(self):
        assert 'data-testid="product-quick-answer"' in self.html

    def test_quick_answer_label(self):
        # "Quick answer" label exists somewhere near the testid block
        assert re.search(r"Quick answer", self.html, re.IGNORECASE)

    def test_quick_answer_body_length(self):
        m = re.search(
            r'data-testid="product-quick-answer-body"[^>]*>(.*?)</div>',
            self.html, re.DOTALL)
        assert m, "product-quick-answer-body element missing"
        plain = re.sub(r"<[^>]+>", "", m.group(1))
        # decode HTML entities for char count
        import html as _h
        plain = _h.unescape(plain).strip()
        assert len(plain) >= 200, f"quick answer too short: {len(plain)} -> {plain[:120]}"


# ============== Product PAA ==============
class TestProductPAA:
    SLUG = "bitdefender-antivirus-for-mac-1-mac-1-year"

    def setup_method(self):
        r = _get(f"/product.php?slug={self.SLUG}")
        self.html = r.text

    def test_paa_present(self):
        assert 'data-testid="product-paa"' in self.html

    @pytest.mark.parametrize("idx", [0, 1, 2, 3, 4, 5])
    def test_paa_question_present(self, idx):
        assert f'data-testid="product-paa-q-{idx}"' in self.html, f"q-{idx} missing"
        assert f'data-testid="product-paa-a-{idx}"' in self.html, f"a-{idx} missing"

    @pytest.mark.parametrize("idx", [0, 1, 2, 3, 4, 5])
    def test_paa_answer_wordcount(self, idx):
        m = re.search(
            rf'data-testid="product-paa-a-{idx}"[^>]*>(.*?)</div>',
            self.html, re.DOTALL)
        assert m, f"answer body {idx} missing"
        plain = re.sub(r"<[^>]+>", "", m.group(1)).strip()
        words = len(plain.split())
        # spec: 40-60 word answer — allow a small tolerance
        assert 35 <= words <= 75, f"a-{idx} word count {words} out of 35-75 range"


# ============== Category breadcrumbs + quick answer ==============
class TestCategoryAEOAndBreadcrumb:
    def setup_method(self):
        r = _get("/category.php?slug=office-pc")
        assert r.status_code == 200
        self.html = r.text

    def test_breadcrumb_present(self):
        assert 'data-testid="category-breadcrumb"' in self.html
        items = re.findall(r'<li[^>]*class="[^"]*breadcrumb-item', self.html)
        assert len(items) >= 3, f"breadcrumb has {len(items)} items"

    def test_breadcrumb_text_chain(self):
        # Crude: Home + Shop + Office for PC
        assert re.search(r"Home", self.html)
        assert re.search(r"Shop", self.html)
        assert re.search(r"Office for PC", self.html, re.IGNORECASE)

    def test_quick_answer_present_with_price(self):
        assert 'data-testid="category-quick-answer"' in self.html
        m = re.search(
            r'data-testid="category-quick-answer"(.*?)</section>',
            self.html, re.DOTALL)
        if not m:
            m = re.search(
                r'data-testid="category-quick-answer"(.*?)</aside>',
                self.html, re.DOTALL)
        assert m, "category-quick-answer block not found"
        body = m.group(1)
        assert re.search(r"\$\d|AUD|USD|CAD|GBP", body), "no price in category quick answer"


# ============== Category 5 JSON-LD regression ==============
class TestCategoryFiveJsonLd:
    def test_office_pc_five_blocks(self):
        r = _get("/category.php?slug=office-pc")
        blocks = _jsonld_blocks(r.text)
        assert len(blocks) == 5, f"expected 5 category JSON-LD, got {len(blocks)}"
        types = []
        for b in blocks:
            if isinstance(b, dict):
                if b.get("@graph"):
                    for g in b["@graph"]:
                        if isinstance(g, dict):
                            types.append(g.get("@type"))
                types.append(b.get("@type"))
        for t in ("Organization", "CollectionPage", "BreadcrumbList", "FAQPage", "ItemList"):
            assert t in types, f"missing {t}; got {types}"


# ============== robots.txt ==============
class TestRobotsTxt:
    def setup_method(self):
        r = _get("/robots.txt")
        assert r.status_code == 200
        assert r.headers.get("content-type", "").startswith("text/plain")
        self.body = r.text

    def test_allow_root(self):
        assert "Allow: /" in self.body

    def test_disallow_admin(self):
        assert "Disallow: /admin.php" in self.body

    @pytest.mark.parametrize("bot", ["GPTBot", "ClaudeBot", "PerplexityBot", "Google-Extended"])
    def test_ai_bot_allow_listed(self, bot):
        assert f"User-agent: {bot}" in self.body, f"{bot} not allow-listed"

    def test_sitemap_line(self):
        assert re.search(r"^Sitemap:.*sitemap\.xml", self.body, re.MULTILINE)


# ============== Freshness tick PHP function presence + logic ==============
class TestFreshnessTickSource:
    @classmethod
    def setup_class(cls):
        with open(f"{PHP_ROOT}/includes/seo-bot.php") as f:
            cls.src = f.read()

    def test_function_defined(self):
        assert "function seo_bot_freshness_tick(" in self.src

    def test_23_hour_bail(self):
        # bails when last_tick_at is within 23 hours
        assert "23 * 3600" in self.src or "23*3600" in self.src

    def test_picks_oldest_stale(self):
        # 90 day staleness query
        assert "INTERVAL 90 DAY" in self.src
        assert "ORDER BY COALESCE(updated_at" in self.src
        assert "LIMIT 1" in self.src

    def test_calls_llm(self):
        # uses chat/completions
        assert "/chat/completions" in self.src

    def test_updates_lead_faq_updated_at(self):
        m = re.search(
            r"UPDATE blog_posts SET lead = \?.*?faq_json.*?updated_at = NOW\(\)",
            self.src, re.DOTALL)
        assert m, "freshness UPDATE does not set lead + faq_json + updated_at"

    def test_persists_settings(self):
        assert "setting_set('seo_freshness_last_tick_at'" in self.src
        assert "setting_set('seo_freshness_last_refreshed_id'" in self.src

    def test_autotick_wires_freshness(self):
        assert "seo_bot_freshness_tick();" in self.src
        # in shutdown handler
        assert "register_shutdown_function" in self.src


# ============== Schema migration ==============
class TestSchemaMigration:
    @classmethod
    def setup_class(cls):
        with open(f"{PHP_ROOT}/includes/seo-bot.php") as f:
            cls.src = f.read()

    def test_schema_adds_columns(self):
        # ensure_schema adds lead + faq_json + updated_at
        # find the body of seo_bot_ensure_schema
        m = re.search(
            r"function seo_bot_ensure_schema\(.*?\)\s*:\s*void\s*\{(.*?)^}",
            self.src, re.DOTALL | re.MULTILINE)
        assert m, "seo_bot_ensure_schema() not found"
        body = m.group(1)
        for col in ("lead", "faq_json", "updated_at"):
            assert col in body, f"ensure_schema does not reference column `{col}`"

    def test_idempotent(self):
        # idempotency typically via INFORMATION_SCHEMA check or IF NOT EXISTS / try-catch
        m = re.search(
            r"function seo_bot_ensure_schema\(.*?\)\s*:\s*void\s*\{(.*?)^}",
            self.src, re.DOTALL | re.MULTILINE)
        body = m.group(1)
        has_check = ("INFORMATION_SCHEMA" in body
                     or "IF NOT EXISTS" in body
                     or "try" in body)
        assert has_check, "ensure_schema does not appear idempotent (no IS check / try)"


# ============== LLM prompts ==============
class TestLlmPrompts:
    @classmethod
    def setup_class(cls):
        with open(f"{PHP_ROOT}/includes/seo-bot.php") as f:
            cls.src = f.read()

    def test_regional_blog_prompt_requires_lead(self):
        assert re.search(r"lead:\s*A 40-60 word DIRECT ANSWER", self.src)

    def test_regional_prompt_requires_statistic(self):
        assert re.search(r"Include at least ONE concrete\s+statistic", self.src)
        assert "attribution" in self.src

    def test_trends_prompt_json_only(self):
        assert "Output MUST start with `{` and end with `}`" in self.src


# ============== INSERT statements ==============
class TestInsertStatements:
    @classmethod
    def setup_class(cls):
        with open(f"{PHP_ROOT}/includes/seo-bot.php") as f:
            cls.src = f.read()

    def test_inserts_include_lead_column(self):
        # find all INSERT INTO blog_posts statements
        inserts = re.findall(r"INSERT INTO blog_posts\s*\((.*?)\)", self.src, re.DOTALL)
        assert len(inserts) >= 2, f"expected >=2 INSERTs, got {len(inserts)}"
        for col_list in inserts:
            assert "lead" in col_list, f"INSERT missing `lead`: {col_list[:200]}"

    def test_inserts_set_updated_at_now(self):
        # both INSERTs should bind updated_at = NOW() (in column list + VALUES or via SET)
        # Easier: grep for 'NOW()' near INSERT INTO blog_posts
        chunks = re.findall(r"INSERT INTO blog_posts.*?;", self.src, re.DOTALL)
        assert len(chunks) >= 2
        for ch in chunks:
            assert "NOW()" in ch, f"INSERT missing NOW(): {ch[:200]}"


# ============== Public pages still 200 ==============
class TestPublicPagesStill200:
    @pytest.mark.parametrize("path", [
        "/",
        "/shop.php",
        "/reviews.php",
        "/blog.php",
        "/product.php?slug=bitdefender-antivirus-for-mac-1-mac-1-year",
        "/category.php?slug=office-pc",
        "/about-us.php",
        "/contact.php",
    ])
    def test_page_200(self, path):
        r = _get(path)
        assert r.status_code == 200, f"{path} -> {r.status_code}"
