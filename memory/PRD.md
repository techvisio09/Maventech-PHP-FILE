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

## Test Credentials
See `/app/memory/test_credentials.md`.

## Roadmap / Backlog (P2)
- Split `admin.php` (>1500 lines) into per-tab partials under `includes/tabs/`
- Add bulk-paste key validation (deduplicate vs. existing)
- Add CSV export for sold keys per product
- Email template A/B test variants
