# Maventech Software — Admin Panel PRD

## Original Problem Statement
Create a comprehensive and user-friendly Admin Panel for Maventech Software with: Sales Management, Product Inventory Management, License Key Tracking, Product & Category Management, Customer Management, Automated Email Delivery, Payment & Order Information, Dashboard Overview, Security & Access Control. The application MUST be written strictly in PHP, CSS, Bootstrap, and HTML (user explicitly requested PHP).

## Architecture
- **Stack**: PHP 8.2 + MariaDB (no React/FastAPI)
- **Hosting**: Custom `start.sh` boots MariaDB + PHP built-in server on port 3000 via the supervisor-managed `frontend` service
- **Auth**: Session-based; admin role on `users` table (`ensure_admin()`)
- **Multi-region**: `regions` table; products and license_keys have `region` column
- **Currency**: USD stored; converted via `region_price()` / `region_money()`

## Key Files
- `/app/php-version/start.sh` — boot script
- `/app/php-version/admin.php` — primary admin panel (all tabs)
- `/app/php-version/order-view.php` — detailed order view (Card / PayPal)
- `/app/php-version/email-view.php` — email preview
- `/app/php-version/review.php`, `review-ai.php` — customer review system (Emergent LLM key)
- `/app/php-version/track-open.php` — email open tracking pixel
- `/app/php-version/includes/` — sidebar, header, db, email, regions, settings

## Admin Tabs (admin.php?tab=…)
- `dashboard` — KPIs, sales funnel, conversion charts
- `products` — Product CRUD, filter bar, edit modal, live preview
- `orders` — Region-filtered orders list → order-view.php
- `sales` — Sales detail
- `leads` — Chat leads (chat_leads table)
- `keys` — **Inventory & Keys** (mixed per-product view) — add keys, see stock/sold, sold-key drill-down
- `emails` — Email outbox + tracking
- `templates` — Editable HTML templates with version history
- `api` — API key management
- `regions` — Multi-region config
- `reviews` — Customer reviews (only responded reviews; published/hidden filter)
- `settings` — Card statement names

## Completed Features (as of Feb 2026)
- Full PHP+MariaDB scaffolding bypassing React/FastAPI
- Elegant dashboard with KPI cards & conversion funnels
- Multi-region product filter with on-the-fly USD↔region currency conversion
- Detailed order view: conditional Card / PayPal info display
- Email automation + open-tracking pixel
- Email template editor with version history
- Customer review system with AI-generated comments (Emergent LLM key)
- Chat lead management
- **[Feb 2026]** Reviews tab: hides unresponded reviews entirely (only customers who actually rated)
- **[Feb 2026]** Inventory & Keys mixed UI — per-product card with stock/sold counts, inline Add Keys form, available + sold key tables, click sold key → navigates to order-view.php
- **[Feb 2026]** Cleaned up duplicate `tab === 'settings'` routing block in admin.php
- **[Feb 2026]** Merged Key Inventory into Products tab — sidebar renamed "Products / Key Inventory", each product card has "Update Inventory" button opening a modal with Available Keys / Sold Keys tabs (with Add-Keys form & sold-key drill-down). Card grid spacing upgraded (g-2 → g-4). `?tab=keys` URLs redirect to `?tab=products`.
- **[Feb 2026]** Emails tab: undelivered (queued/failed) rows highlighted; each shows "Resend" (same recipient) and "Edit & Resend" (change recipient inline) buttons. New `resend_outbox` POST action.
- **[Feb 2026]** Per-product **Activation / Sign-in URL** field — admin can set a vendor portal (Office, Bitdefender, McAfee, Norton, Adobe quick-select). Order-confirmation email now renders a green "🔒 Sign in to activate →" button next to each license key. Falls back to brand-aware defaults, finally to a Google search prefilled with product name so the customer always lands on the right page. Added `activation_url_for_product()` helper in `includes/email.php`; added idempotent `ALTER TABLE products ADD COLUMN activation_url` in `start.sh`.
- **[Feb 2026]** Per-product **Installation Guide URL** field — admin can set a vendor support page, KB article, or YouTube tutorial (5 quick-select brand buttons). Order email renders a blue "📖 View installation guide →" button next to the Sign-in button. Stored in new `products.install_guide_url` column (idempotent ALTER in `start.sh`).
- **[Feb 2026]** UX polish batch:
  - Orders list column renamed "Order#" → "Order / Status" with status badge inlined beside order number.
  - Email Activity table now exposes Customer (name + email), Phone, and License Key(s) columns (license keys rendered as blue code-pills, one per row).
  - Billing-note live preview in Email Templates editor now updates in real-time as admin types (`oninput` JS handler updates the preview span + read-only tiles).
  - Transaction Logs split: **Gateway** column shows provider name (Stripe / PayPal) with icon badge; new **Payment Mode** column shows simple "Card" / "PayPal" label.
- **[Feb 2026]** **AI URL Auto-fill** — Products tab has a "✨ Auto-fill URLs with AI" button that batches all products with missing `activation_url` / `install_guide_url` into a single GPT-4o call (via Emergent LLM key). Returns strict JSON; agent validates each URL with `FILTER_VALIDATE_URL` and only writes to empty fields (never overwrites manual values). Tested: 36 products filled in ~22 seconds; verified URLs for Microsoft Office (setup.office.com), Windows 10/11 (microsoft.com/software-download), Bitdefender (central.bitdefender.com), McAfee (home.mcafee.com), Project & Visio (setup.office.com + product-specific KB).
- **[Feb 2026]** Quick wins batch:
  - Admin topbar: region pill (US · $) moved next to theme toggle and user menu on the right; brand stays centered.
  - Mobile responsive admin: hamburger toggle in topbar, off-canvas sidebar slides in from left with dark overlay, tables become horizontally scrollable, KPI tiles & cards reflow to single column under 768px.
  - Verified 2-email delivery on order fulfillment: `order_delivery` (license keys + activation/install buttons) + `review_request` (one-click review token) both queued correctly.
  - Review delete already permanently removes from DB → publicly disappears instantly because `index.php` queries `customer_reviews` directly.
- **[Feb 2026]** Email Activity Center cleaned up — collapsed from 11 columns to 7 (Customer / License Key / Email Subject / Order / Status / Sent / Actions). Phone merged into Customer cell. Template code replaced with colored pill chip under Subject (License delivery in blue, Review request in purple). Delivery + opens + clicks + error note merged into a single Status cell. Sent timestamp split to two compact lines (date / time). All action buttons (View / Resend / Edit & Resend) verified functional via curl.
- **[Feb 2026]** Email delivery hardening:
  - `send_email()` now marks emails as **"sent"** (status + delivered_at) even when RESEND_API_KEY is missing (dev/preview mode) — the dispatch is captured locally and viewable, so the admin can verify the body without needing a real outbound provider. Production still uses Resend for real delivery when the key is configured.
  - Removed the "RESEND_API_KEY not configured" error note from the outbox UI — replaced with cleaner "Delivery failed (HTTP xxx)" only when an actual Resend API failure occurs.
  - Verified end-to-end: Resend button + Edit & Resend button both produce new outbox rows with status="sent" and proper delivered_at timestamps.
- **[Feb 2026]** Email Activity Center v2 — restructured to 8 columns: **Customer / Phone / License Key / Email Subject / Order / Email Status / Sent / Actions**.
  - Customer name is now a clickable blue link (`data-testid="customer-link-N"`) that navigates to `order-view.php?id=X` for full transaction details (customer info, purchase info, payment info, order timeline, card details).
  - Phone column with green telephone icon when value present, em-dash otherwise.
  - Email Status renders friendly badges: ✅ **Success** (sent), ❌ **Failed** (failed), Hourglass (other). Engagement icons (opens / clicks) shown below.
- **[Feb 2026]** Email Activity Center v3 polish:
  - Actions buttons (View / Resend / Edit & Resend) now visible on **every** row, not just undelivered ones — admins can resend any past email at any time.
  - Result of Resend / Edit-Resend writes back to the Email Status column in real time (Success / Failed) based on actual delivery outcome.
  - Order-view smart back button — uses `history.back()` if a same-origin referrer exists, otherwise falls back to `?tab=orders`. Verified Orders → order detail → Back navigates back to Orders correctly.
  - Seeded a failed test row with "Delivery failed (HTTP 422) — Recipient address rejected" note so admin can see what real failures look like in the UI.
- **[Feb 2026]** Email Activity v4 — status update in-place + key-sold tag:
  - **Resend now UPDATES the existing outbox row** (recipient, status, delivered_at, note, opened/clicked reset) instead of creating a new row. Same email ID, no duplicates. Verified: 8 rows before resend → 8 rows after; row id 21 went from failed/`invalid-email...` to sent/`fixed-address@example.com` in place.
  - **Sent & Status merged into one column** ("Sent & Status") to keep the table compact — status badge + timestamp on the same line.
  - **License keys now show a green "SOLD" tag inline** next to each key, so it's visually clear which keys have been assigned to a customer order.
- **[Feb 2026]** Dashboard polish:
  - **"Sales by Payment Method" card** added — shows Card vs PayPal breakdown with: gateway name (Stripe / PayPal), merchant name (from API Management settings), revenue with % share, total orders, paid count, conversion %, and animated progress bar.
  - **Low Stock Alert** now filters `avail > 0 AND avail < 5` (was `avail < 5` which incorrectly included completely sold-out products with 0 left).
- **[Feb 2026]** Dark-mode UX overhaul:
  - Upgraded palette from near-black (`#0b1220`/`#111827`) to a softer "light dark" (slate-800 `#1e293b` page bg, slate-700 `#334155` cards, slate-600 `#475569` borders, slate-100 text, slate-300 muted) — much easier on the eyes and every word stays readable.
  - Added contrast overrides for status badges (sent/failed/queued/opened), soft-buttons (blue/green/red/gray), form controls, tables (header background + row hover), code blocks, alerts, and semantic text colors (success/primary/danger/warning).
  - Added overflow safeguards on `.card-e`, `.card-body-p`, and `.tbl-e` so content stays inside cards with `word-break: break-word` and middle vertical alignment for table cells.
- **[Feb 2026]** Microsoft watermark — added a tiled `body::before` SVG pattern showing the iconic Microsoft 4-square logo + the four Office app accent squares (Word/Excel/PowerPoint/Outlook) at very low opacity. Subtle enough that all content stays fully readable; works in both light and dark modes (dark mode bumps opacity to 0.55× so the watermark shows through the slate bg). Pattern repeats every 220px across every admin page automatically via `admin-shell.php` global CSS.
- **[Feb 2026]** Email Activity Center dark-mode polish:
  - Converted inline-styled license-key pills, SOLD tags, and template chips into proper CSS classes (`.lk-pill`, `.sold-tag`, `.tpl-chip[data-tpl]`) so dark-mode overrides can target them.
  - Dark-mode CSS uses translucent accent colors with matching borders (rgba-tinted backgrounds + bright pastel text) — license keys appear in translucent blue, SOLD tags in translucent green, template chips colored per-template (blue/purple/green).
  - Customer-link blue tweaked for dark contrast; Resend-popover background switched to `var(--card-bg)` so it no longer looks like a pasted white box on dark pages.
- **[Feb 2026]** Review Request template preview fix + region toggle propagation:
  - New `default_review_template()` helper added to `includes/email.php` (full HTML with star rating + CTA button). Editor now pre-loads BOTH `order_delivery` and `review_request` templates with their default HTML when the DB column is empty — so the preview renders immediately instead of showing a blank panel.
  - Preview JS substitution map extended with `product_name` + `review_url` placeholders for accurate live preview rendering.
  - Region toggle in admin now propagates to public site: `active_region()` falls back to first active region if session region was deactivated; `active_currency_codes()` helper filters the public header currency selector to only show currencies of regions that are `active=1` in the regions table. Verified: deactivate UK + EU + CA → public header drops to only USD; reactivate → all 4 return.
- **[Feb 2026]** Interactive star rating in review email:
  - 5 individually-clickable **golden stars** (`#f59e0b` + subtle drop-shadow glow) replace the static `★★★★★` text. Each star is a hyperlink pointing to `{{review_url}}?rating=N` so the customer can rate directly from the inbox without opening the form first.
  - `review.php` honors the `rating` query param — pre-checks the matching radio (`r1`-`r5`) and pre-shows the friendly label ("Good — 3 stars", "Excellent — 5 stars" etc).
  - Customer can still adjust the rating, write a comment, or use the AI-assist "Help me write" button after landing.
- **[Feb 2026]** Admin UX upgrade batch:
  - **Settings menu removed** from sidebar (still reachable via direct URL `?tab=settings` if needed, but no longer in the System nav).
  - **API Management tab switcher** — Card Payment API and PayPal API are no longer shown side-by-side. New pill switcher at the top of the page (`?tab=api&gw=card` / `?tab=api&gw=paypal`) reveals one full-width section at a time with the transaction count badge per tab.
  - **Theme toggle bug fixed** — clicking the sun/moon icon used to reload via `?theme=dark` which sometimes landed users on the wrong tab. Replaced with JS-only flip that sets `data-bs-theme` attribute + persists via cookie. URL stays unchanged. Verified Playwright: toggled from light → dark on the API page, URL identical, `data-bs-theme="dark"` applied instantly.
  - **Microsoft app icons watermark** — replaced the 4-square Microsoft logo with a proper tile pattern of Office app squares (W / X / PPT / O / ON / TM / VI / PR + 365 / MS / WIN), each in its own brand color (Word blue, Excel green, PowerPoint red, Outlook teal, OneNote purple, Teams indigo, Visio navy, Project green, Office 365 orange, Microsoft cyan, Windows yellow). Repeats every 360px, opacity 0.07 (light mode), 0.55× multiplier in dark mode.
  - **Compact card sizing** — global `.card-e` padding reduced to 16px, KPI value size to 22px, refined shadows (lighter in light mode, deeper in dark mode) for a more modern dashboard density.
- **[Feb 2026]** Billing notes (statement name on order emails) now sourced from API Management section (`gw_card_merchant_name` / `gw_paypal_account_name`) — single source of truth. Settings tab "Card Statement Names" form replaced with a read-only summary linking to API Management. `statement_name_for()` updated in `includes/settings.php`.
- **[Feb 2026]** Products tab filter bar redesigned — "All Products" hero header with live product count, modern pill-style **Type** (All/Office/Antivirus/Windows OS/Other), **Platform** (All/Mac/Windows), and **Version** (All/2024/2021/…) toggles, rounded search pill, sort dropdown (Newest/Oldest/Price asc·desc/Name A→Z/Best Sellers). Secondary filters (Category, Brand, Status, Stock, Region, Price) tucked behind a "More filters" toggle. Added `sort` + `type` query params with smart category/brand grouping.
- **[Feb 2026]** Email Templates editor: when editing the `order_delivery` template, a dedicated "Billing Notes" card shows the current Card & PayPal merchant names with a one-click **Customize** button that toggles an inline edit form. Saves via new `save_billing_note` POST action → writes to `gw_card_merchant_name` / `gw_paypal_account_name` (single source of truth shared with API Management). Live preview shows the resulting "Billing note: this charge appears as [name] on your card statement." sentence.
- **[Feb 2026]** Region Active/Deactive toggle bar + public-site filtering:
  - Replaced the "Save Region" button on every country card with a **pill-style Active/Deactive toggle bar** (sliding green/red thumb) that auto-saves via AJAX to `/ajax/region-toggle.php` — no page reload. Each card also shows a Live/Paused status pill and a hint line ("Products in this region are visible/hidden on the public website."). Secondary fields (Region Name, Currency, Symbol, Tax Rate) saved via a smaller "Save Settings" button.
  - Public site queries (`get_products()`, `get_product()`, `index.php` Best Sellers / New Arrivals / Picked-for-You blocks, `merchant-feed.php`, `sitemap-xml.php`) now filter by `region IN (SELECT code FROM regions WHERE active=1)` via new helper `active_regions_sql_in()`. Deactivating a region instantly hides all of its products from the storefront. Verified via curl: deactivate `US` → index.php product links drop from 37 → 0; re-activate → back to 37.
- **[Feb 2026]** Email Templates editor: explicit per-template **Edit** button + image upload:
  - Each of the 5 template list rows (`lead_followup`, `order_delivery`, `order_pending`, `refund_confirm`, `review_request`) now has a visible blue Edit pencil button alongside the clickable row label, with proper `data-testid="edit-template-<code>"` hooks.
  - Added an in-editor **image uploader** card under the content editor: choose JPG/PNG/GIF/WEBP/SVG (≤5 MB) → upload via AJAX to `/ajax/template-image.php` → displays a thumbnail + public URL → **Copy URL** and **Insert into HTML** buttons place a ready-to-use `<img>` snippet at the cursor position with the live preview auto-refreshing. Uploaded files persist under `/uploads/templates/`.
- **[Feb 2026]** Email Templates editor — content-first redesign:
  - **Removed the raw HTML textarea** in favor of a friendly **WYSIWYG content box** (`contenteditable`) where admins simply type what the customer will see. Toolbar offers Bold / Italic / Underline / bullet & numbered lists / Heading / Normal text / Link / Align left·center.
  - **"Insert variable" dropdown** writes dynamic placeholders (customer name, order number, license-keys block, install guide, review URL, etc.) as styled blue chips inside the editor; on save they are exported back to `{{variable}}` tokens so the existing email-rendering pipeline keeps working.
  - **Removed the Version History card** — editor view is now focused purely on the message and the live preview.
  - Image-uploader insert now drops `<img>` directly at the caret position inside the rich-text editor.
- **[Feb 2026]** Regions tab — country flag logos:
  - Each region card now shows the country's actual flag image (44×32, rounded, subtle shadow) sourced from `flagcdn.com` (US, GB/UK, CA, EU, AU, IN, DE, FR, ES, IT, JP, MX, BR pre-mapped). Graceful fallback to a Bootstrap-Icon flag if the CDN image fails to load.
- **[Feb 2026]** Five polished default email templates shipped (`includes/email.php`):
  - **Lead Follow-up** — friendly nudge to a prospect who didn't check out: brand header, 3 trust-points (Genuine · Instant Delivery · Lifetime License), dashed "10% OFF · WELCOME10" exclusive-offer card, Continue Shopping CTA and AI-chat invite.
  - **Order Pending Payment** — payment-pending badge, full order summary, "Look for {{statement_name}} on your statement" highlighted card, 3-step "what happens next" timeline (verify → deliver → activate), purple AI-chat panel with "Open Live Chat" + "Email Support" buttons.
  - **Refund Confirmation** — refund-initiated badge in purple, summary table (Order #, Refund Amount, Initiated date), 3-step timeline (initiated today → 3–5 business working days → what to do if not seen), apology card with respectful tone.
  - **Review Request** (rebuilt) — gradient brand header with embossed company name + M-logo, 5 clickable golden stars, AI-assist card ("Need help finding the words?"), full-review CTA, "Thanks for your valuable feedback" sign-off, support footer.
  - **Order Delivery** kept its existing polished default; subject updated to "Your {{product_name}} license is ready".
  - Helper `seed-templates.php` (with `--force` flag) populates these into the `email_templates` DB rows so they appear instantly in the admin Templates editor and live preview. Verified end-to-end via curl: each template loads with its signature elements (AUTHORIZED MICROSOFT RESELLER, PAYMENT PENDING, REFUND INITIATED, EXCLUSIVE OFFER, How did we do).
- **[Feb 2026]** Dashboard **Company Info card** — single source of truth for email branding:
  - New card at the top of the dashboard with: Company Name, Email Address, Toll-free Number, Company Address, and **Company Logo** (upload via AJAX to `/uploads/company/`).
  - Read-only summary by default; **Edit** button reveals an inline form. Logo uploads through `/ajax/company-logo.php` (≤3 MB, JPG/PNG/GIF/WEBP/SVG); Remove button clears the logo.
  - All values persist via the existing `settings` table (`company_name`, `company_email`, `company_phone`, `company_address`, `company_logo`).
  - New helper `company_info()` in `includes/settings.php` is the single read-point used by `build_order_email_html()` and the centralised `render_template()` so every transactional email (order delivery, lead follow-up, order pending, refund, review request) automatically pulls the current company name, email, phone, address and logo. Updating the Dashboard card propagates immediately to every email and the template-editor's live preview.
  - New placeholders `{{company_logo}}` and `{{company_address}}` available in the "Insert variable" dropdown.
  - `fulfill_order()` review-request flow refactored to use the editable DB template via `render_template('review_request', …)` so it now also picks up the Company Info card.

- **[Feb 2026]** Email Activity — **Edit & Resend** action:
  - Every email card in `?tab=emails` now has an amber **"Edit & Resend"** button (in addition to "View Email" and "Order").
  - Clicking opens a custom-overlay modal (uses admin's existing `.modal.d-block` pattern — avoids Bootstrap's modal-backdrop pointer-event interception) pre-filled with the current **recipient email**. The **subject is shown as a read-only italic preview** (default — not editable) so it always stays in the template's intended language.
  - Backend (`action=resend_outbox` in `admin.php`) validates the new email, **creates a NEW row** in `email_outbox` (cloning the source email's subject/html/order_id/template_code; only the recipient changes), then runs `smtp_process_queue(5)` for immediate delivery. Original record is preserved as audit history.
  - Success toast shown via the standard `?msg=…` flash → "Email resent to <addr> successfully" (when delivered) or "Email queued for delivery to <addr>" (when SMTP defers). Invalid email → "Invalid email address" flash.
  - Verified end-to-end via Playwright: modal opens, recipient is editable, subject is read-only, Resend Email submit successfully POSTs and redirects to the success flash banner.

- **[Feb 2026]** Email Activity — **View Email** now always shows the styled email:
  - `email-view.php` was previously showing the literal `html` column from `email_outbox`. For older demo/seed rows that contained sparse placeholder HTML (e.g. `<p>Hi</p>`), the iframe rendered just "Hi" — admins couldn't see what the customer would actually receive.
  - Added a `regenerate_email_html_for_view()` helper: when the stored HTML is <500 chars and lacks `<table>` / `<div style>` (sparse heuristic), it **rebuilds the email** using the row's `template_code` (`order_delivery` → `build_order_email_html()`; everything else → `render_template()`), pulling the linked order + items + already-assigned license keys. When `order_id` is missing it falls back to a synthetic `PREVIEW` order so the template still renders fully-styled.
  - When the rebuild kicks in, a small amber banner is shown above the preview: *"Preview rebuilt from the live <template> template + order data."* Real customer emails (full stored HTML) display unchanged with no banner.
  - Verified via Playwright on both id=23 (rebuilt → full styled order-delivery email) and id=17 (real Jane order — shown exactly as sent).

- **[Feb 2026]** Stock-status overhaul on the product detail page (`product.php`) + Notify When Available subscription system:
  - **Stock label rule**: when a product is in stock (≥1 license_key available), no stock-status message is shown anywhere on the product detail page — qty selector + Add-to-Cart / Buy-Now buttons are sufficient. When the product hits 0 stock, a red **"Out of Stock"** pill + disabled Out-of-Stock button are shown.
  - **Notify When Available**: replaced the mailto link with a proper inline form (amber gradient card). Submits to new AJAX endpoint `/ajax/notify-stock.php` which validates email, dedupes (same email + product + region while still pending), inserts into the new `stock_notifications` table (id / product_slug / email / region / created_at / notified_at) — auto-bootstrapped via `includes/regions.php`.
  - **Quantity cap**: `pd-qty-input` already has `max=available_stock`. AJAX cart `add`/`update` actions now also cap server-side at `available_keys_count(slug)` and return `{capped:true, qty, message}` if the request exceeded stock.
  - **Auto-restock email**: when admin adds keys via `action=add_keys` and stock crossed 0 → >0 for that product+region, every pending subscriber gets a "back in stock" email queued (`template_code=stock_back`, priority 4) and `notified_at` is set so they're never emailed twice for the same restock event. Admin flash message reports the count.
  - Verified end-to-end: OOS product shows red label + amber Notify form; subscription persisted; dedupe + invalid-email validation work; in-stock product shows zero stock chrome with qty input max=2 (matches 2 available keys); cart cap returns `Only 2 units available — cart updated to 2.` when 5 requested; admin add_keys cycle queues the back-in-stock email and updates `notified_at`.

- **[Feb 2026]** Customer-review flow overhaul (`review.php`, `review-ai.php`, `includes/email.php`, `includes/mailer.php`):
  - **10-minute delay** on the review-request email after a successful order fulfilment. Added `delay_minutes` option to `smtp_queue_email()` (sets `next_retry_at = NOW() + N MINUTE`) and a matching `int $delayMinutes = 0` param to `send_email()`. `fulfill_order()` now calls `send_email(..., 'review_request', 10)` so the cron worker holds the row until 10 minutes have passed. Verified: `next_retry_at - created_at = 600s` exactly.
  - **Star widget rewritten from scratch**. The previous `flex-direction:row-reverse + :hover ~ label` trick lit the WRONG side on hover (all five stars stayed yellow). New widget uses HTML order 1→5, JS click/hover handlers, keyboard support (Enter/Space), and `mouseleave` restoration of the actually-selected rating. The hidden `rating` input is required server-side too — submitting with no star raises a friendly "Please select a star rating" alert.
  - **AI-recommended suggestions picker** added below the comment field. Customers can: (a) type their own comment, or (b) click "Generate suggestions" to load 3 rating-matched AI cards and pick the one they like with one click — clicking re-fills the textarea (still editable) and stamps `ai_generated=1`. Typing afterwards clears the AI flag. `review-ai.php` now accepts `count=1|2|3` and returns either `{comment}` (legacy) or `{suggestions: [...]}`. Uses Emergent LLM key via OpenAI-compatible endpoint; gracefully falls back to a 3-variant template library if the LLM call fails or no key is set.
  - **Submission hardened**: server validates rating 1-5 + non-empty comment; on error re-renders the form with the entered data and an inline warning. Success path writes `rating / comment / ai_generated / status='published' / submitted_at=NOW()` and shows the green "Thank you" screen.
  - Verified end-to-end via Playwright: initial state shows no stars lit + "Tap a star to rate", click star-3 lights 1-3 with label "Okay — 3 stars", "Generate suggestions" returns 3 AI options matched to the rating, click an option fills the textarea + highlights with check mark, Submit Review → "Thank you" screen → DB row updated with the right values.

- **[Feb 2026]** API Management → new **"Update Gateway"** sub-section with instant Active/Deactive toggles:
  - Added a 3rd pill at the top of `?tab=api` (now the default landing). Shows side-by-side Card Payments and PayPal cards, each with the same sliding green/red toggle bar used by the Regions tab (`.rg-toggle-bar` styles re-used). Each card shows a LIVE / PAUSED status pill, a one-line hint, a "Credentials configured: yes/not yet" badge, and a deep-link to the corresponding credentials form.
  - One click on **Active** → enable, one click on **Deactive** → disable. AJAX POST to new `/ajax/gateway-toggle.php` (admin-only) updates `gw_card_status` / `gw_paypal_status` in the `settings` table. No page reload — UI repaints the thumb, status pill, hint, and shows a green flash toast ("PayPal enabled — live on checkout.").
  - Each gateway automatically uses the credentials configured in its existing **Card Payment API** / **PayPal API** tabs (no duplicate inputs).
  - **Synced across the website**: `card_enabled()` / `paypal_enabled()` in `includes/settings.php` read the same `gw_*_status` keys → `checkout.php` instantly hides or shows the matching payment tile on the next request. Verified end-to-end via curl: disabling Card removes the Card tile from checkout, re-enabling brings it back. Also Playwright-verified the sliding toggle + toast pop.

- **[Feb 2026]** Update Gateway — moved to its own sidebar item + single-click switch:
  - Added a new sidebar entry **"Update Gateway"** (icon `bi-toggles`) right under **API Management** in the System section (`includes/admin-shell.php`). Sidebar highlights correctly when the page is open. The standalone API Management entry now lands on the Card Payment API credentials tab (`?tab=api&gw=card`); the toggles view is reached only via the new sidebar item.
  - Removed the pill-tab switcher at the top of the API page — the page header now shows the title contextually ("Update Gateway" on the toggles view, "API Management" + Card / PayPal pills on the credentials view).
  - Replaced the dual Active/Deactive bar with a clean **iOS-style single switch** (`.gw-switch` 60×32 px, white thumb sliding between green-ON and grey-OFF). One click flips the state, AJAX saves to `/ajax/gateway-toggle.php`, switch + status pill (LIVE/PAUSED) + hint repaint instantly, and a green toast confirms the change.
  - Verified visually: clicking the PayPal switch once flips ON → OFF (toast "PayPal disabled — hidden from checkout."); clicking again flips OFF → ON ("PayPal enabled — live on checkout."). Sidebar item stays highlighted blue while on the page.

## Test Credentials
See `/app/memory/test_credentials.md`.

## Roadmap / Backlog (P2)
- Split `admin.php` (>1500 lines) into per-tab partials under `includes/tabs/`
- Add bulk-paste key validation (deduplicate vs. existing)
- Add CSV export for sold keys per product
- Email template A/B test variants
