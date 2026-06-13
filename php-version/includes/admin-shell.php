<?php
// Standalone admin layout (replaces public site header for /admin*, /inventory.php, /order-view.php).
require_once __DIR__ . '/regions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Handle theme toggle BEFORE any HTML output
if (isset($_GET['theme']) && in_array($_GET['theme'], ['dark','light'], true)) {
    setcookie('adm_mode', $_GET['theme'], time()+86400*365, '/');
    $_COOKIE['adm_mode'] = $_GET['theme']; // immediate effect
    $u = strtok($_SERVER['REQUEST_URI'], '?');
    $qs = $_GET; unset($qs['theme']);
    header('Location: ' . $u . ($qs ? '?'.http_build_query($qs) : ''));
    exit;
}

$adminMode = $_COOKIE['adm_mode'] ?? 'light';
$rg = active_region();
// Ensure all auxiliary tables exist on first admin page-load.  This makes
// the panel self-healing when uploaded to a fresh server where start.sh's
// migrations were never executed.
ensure_db_schema();
if (!function_exists('current_admin')) {
    function current_admin(): ?array { return function_exists('current_user') ? current_user() : null; }
}
$navItems = [
    'dashboard'   => ['icon' => 'bi-speedometer2',       'label' => 'Dashboard',          'href' => 'admin.php?tab=dashboard'],
    'company'     => ['icon' => 'bi-building',           'label' => 'Company Info',       'href' => 'admin.php?tab=company'],
    'inventory'   => ['icon' => 'bi-boxes',              'label' => 'Inventory Mgmt',     'href' => 'inventory.php'],
    'products'    => ['icon' => 'bi-box-seam',           'label' => 'Products / Key Inventory', 'href' => 'admin.php?tab=products'],
    'orders'      => ['icon' => 'bi-receipt',            'label' => 'Orders',             'href' => 'admin.php?tab=orders'],
    'sales'       => ['icon' => 'bi-graph-up-arrow',     'label' => 'Sales Detail',       'href' => 'admin.php?tab=sales'],
    'leads'       => ['icon' => 'bi-person-lines-fill',  'label' => 'Lead Management',    'href' => 'admin.php?tab=leads'],
    'emails'      => ['icon' => 'bi-envelope',           'label' => 'Email Activity',     'href' => 'admin.php?tab=emails'],
    'reviews'     => ['icon' => 'bi-star',                'label' => 'Customer Reviews',   'href' => 'admin.php?tab=reviews'],
    'templates'   => ['icon' => 'bi-file-earmark-richtext','label'=> 'Email Templates',   'href' => 'admin.php?tab=templates'],
    'gateways'    => ['icon' => 'bi-credit-card-2-front','label' => 'API / Payment Gateway',  'href' => 'admin.php?tab=api&gw=toggles'],
    'smtp'        => ['icon' => 'bi-envelope-paper-heart','label' => 'SMTP / Mail Server', 'href' => 'admin.php?tab=smtp'],
    'regions'     => ['icon' => 'bi-globe',              'label' => 'Regions',            'href' => 'admin.php?tab=regions'],
    'settings'    => ['icon' => 'bi-gear',               'label' => 'Settings',           'href' => 'admin.php?tab=settings', 'hidden' => true],
];
$adminActive = $adminActive ?? '';
$pageTitle   = $pageTitle ?? 'Admin Panel';
$admin       = $admin ?? current_admin();
// Pull the brand letter for the topbar monogram (and the email "M" badge).
$adm_brand_name   = function_exists('company_info') ? (company_info()['name'] ?? '') : '';
if ($adm_brand_name === '' && defined('SITE_BRAND')) $adm_brand_name = SITE_BRAND;
$adm_brand_letter = mb_strtoupper(mb_substr(preg_replace('/^[^A-Za-z0-9]+/', '', $adm_brand_name) ?: 'M', 0, 1));
$adm_brand_logo   = function_exists('company_info') ? (company_info()['logo'] ?? '') : '';
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= $adminMode === 'dark' ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script>
  // Base URL the panel was loaded from — every fetch() to ajax/... uses this
  // so the admin works whether installed at "/" or in a subfolder like "/admin/".
  window.MAVEN_BASE = <?= json_encode(base_url()) ?>;
</script>
<style>
:root {
  --bg: #f7f8fa;
  --card-bg: #ffffff;
  --border: #e9ecef;
  --text: #1f2937;
  --muted: #64748b;
  --brand: #3b82f6;
  --brand-dk: #1d4ed8;
  --green: #10b981;
  --green-soft: #d1fae5;
  --red: #ef4444;
  --red-soft: #fee2e2;
  --amber: #f59e0b;
  --amber-soft: #fef3c7;
  --gray-soft: #e5e7eb;
  --blue-soft: #dbeafe;
}
[data-bs-theme="dark"] {
  /* Softer "light dark" palette — easier on the eyes, all text stays readable */
  --bg: #1e293b;             /* slate-800 — page bg (was #0b1220) */
  --card-bg: #334155;        /* slate-700 — card bg (was #111827) */
  --border: #475569;         /* slate-600 — visible borders */
  --text: #f1f5f9;           /* slate-100 — primary text */
  --muted: #cbd5e1;          /* slate-300 — secondary text (was #94a3b8) */
  --gray-soft: #475569;
  --blue-soft: #1e40af;
  --green-soft:#065f46; --red-soft:#991b1b; --amber-soft:#92400e;
}
/* Dark-mode contrast fixes: code blocks, links, soft badges, tables */
[data-bs-theme="dark"] code { background: rgba(255,255,255,0.08); color:#e0e7ff; }
[data-bs-theme="dark"] a { color:#93c5fd; }
[data-bs-theme="dark"] a:hover { color:#bfdbfe; }
[data-bs-theme="dark"] .text-muted { color:#cbd5e1 !important; }
[data-bs-theme="dark"] .s-badge { color:#f1f5f9; }
/* ---- Status badges: complete dark-mode palette (every keyword present in
   admin.php).  Without these, statuses like "new" / "contacted" / "qualified"
   inherited light-mode `color:#92400e` against the now-dark `--amber-soft`
   variable and rendered as invisible text-on-text. ---- */
[data-bs-theme="dark"] .s-badge.paid,
[data-bs-theme="dark"] .s-badge.delivered,
[data-bs-theme="dark"] .s-badge.sent {
  background:#065f46; color:#a7f3d0;
}
[data-bs-theme="dark"] .s-badge.failed,
[data-bs-theme="dark"] .s-badge.refunded,
[data-bs-theme="dark"] .s-badge.lost,
[data-bs-theme="dark"] .s-badge.cancelled {
  background:#991b1b; color:#fecaca;
}
[data-bs-theme="dark"] .s-badge.queued,
[data-bs-theme="dark"] .s-badge.pending,
[data-bs-theme="dark"] .s-badge.new {
  background:#92400e; color:#fde68a;
}
[data-bs-theme="dark"] .s-badge.contacted {
  background:#1e3a8a; color:#bfdbfe;
}
[data-bs-theme="dark"] .s-badge.qualified,
[data-bs-theme="dark"] .s-badge.active {
  background:#065f46; color:#86efac;
}
[data-bs-theme="dark"] .s-badge.converted {
  background:#155e75; color:#a5f3fc;
}
[data-bs-theme="dark"] .s-badge.inactive {
  background:#475569; color:#e2e8f0;
}
[data-bs-theme="dark"] .s-badge.opened { background:#1e40af; color:#bfdbfe; }
[data-bs-theme="dark"] .btn-soft-blue { background:#1e3a8a; color:#bfdbfe; }
[data-bs-theme="dark"] .btn-soft-blue:hover { background:#1d4ed8; color:#fff; }
[data-bs-theme="dark"] .btn-soft-green { background:#065f46; color:#a7f3d0; }
[data-bs-theme="dark"] .btn-soft-green:hover { background:#047857; color:#fff; }
[data-bs-theme="dark"] .btn-soft-red { background:#7f1d1d; color:#fecaca; }
[data-bs-theme="dark"] .btn-soft-red:hover { background:#991b1b; color:#fff; }
[data-bs-theme="dark"] .btn-soft-gray { background:#475569; color:#e2e8f0; }
[data-bs-theme="dark"] .btn-soft-gray:hover { background:#64748b; color:#fff; }
[data-bs-theme="dark"] .form-control, [data-bs-theme="dark"] .form-select {
  background: #1e293b; color:#f1f5f9; border-color: #475569;
}
[data-bs-theme="dark"] .form-control:focus, [data-bs-theme="dark"] .form-select:focus {
  background:#1e293b; color:#f1f5f9; border-color:#3b82f6; box-shadow:0 0 0 .2rem rgba(59,130,246,.25);
}
[data-bs-theme="dark"] .form-control::placeholder { color:#94a3b8; }
[data-bs-theme="dark"] .table { color: #f1f5f9; }
[data-bs-theme="dark"] .table thead th { background: #2d3a52; color:#e2e8f0; border-bottom-color:#475569; }
[data-bs-theme="dark"] .table tbody tr { border-bottom: 1px solid #475569; }
[data-bs-theme="dark"] .table tbody tr:hover { background: rgba(255,255,255,0.03); }
[data-bs-theme="dark"] .card-e { background: var(--card-bg); border-color: var(--border); color: var(--text); }
[data-bs-theme="dark"] .kpi-tile { background: var(--card-bg); border-color: var(--border); }
[data-bs-theme="dark"] .kpi-tile .kpi-label { color: #cbd5e1; }
[data-bs-theme="dark"] .kpi-tile .kpi-value { color:#f1f5f9; }
[data-bs-theme="dark"] .alert-success { background:#065f46; color:#a7f3d0; border-color:#047857; }
[data-bs-theme="dark"] .alert-danger { background:#991b1b; color:#fecaca; border-color:#dc2626; }
[data-bs-theme="dark"] .text-success { color:#6ee7b7 !important; }
[data-bs-theme="dark"] .text-primary { color:#93c5fd !important; }
[data-bs-theme="dark"] .text-danger { color:#fca5a5 !important; }
[data-bs-theme="dark"] .text-warning { color:#fcd34d !important; }
/* Compact card sizing — modern dashboard density */
.card-e { padding: 16px; border-radius: 12px; box-shadow: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04); }
.card-e.p-4 { padding: 18px !important; }
.card-e .card-head { padding: 12px 16px; }
.kpi-tile { padding: 14px; }
.kpi-tile .kpi-value { font-size: 22px; line-height: 1.2; }
.kpi-tile .kpi-label { font-size: 11px; letter-spacing: .5px; }
[data-bs-theme="dark"] .card-e { box-shadow: 0 1px 3px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.18); }

/* Email-template ON/OFF tiny pills (visible in dark mode too) */
.s-badge.active   { background:#d1fae5; color:#065f46; padding:1px 7px; font-size:9px; font-weight:800; letter-spacing:.4px; border-radius:999px; border:1px solid #6ee7b7; }
.s-badge.inactive { background:#fee2e2; color:#991b1b; padding:1px 7px; font-size:9px; font-weight:800; letter-spacing:.4px; border-radius:999px; border:1px solid #fca5a5; }
[data-bs-theme="dark"] .s-badge.active   { background: rgba(16,185,129,.18); color:#6ee7b7; border-color: rgba(16,185,129,.40); }
[data-bs-theme="dark"] .s-badge.inactive { background: rgba(239,68,68,.18);  color:#fca5a5; border-color: rgba(239,68,68,.40); }

/* Template list items — clean hover & active state, dark-mode aware */
.tpl-list-item { color: var(--text); border:1px solid transparent; transition: background .15s, border-color .15s; }
.tpl-list-item:hover { background: var(--bg); color: var(--text); }
.tpl-list-item.active { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.30); }
[data-bs-theme="dark"] .tpl-list-item.active { background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.45); }
/* Template row (clickable item + explicit Edit button) */
.tpl-row { min-height: 50px; }
.tpl-row .btn { align-self: stretch; }
.tpl-row-active .btn { background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.30); }

/* ---------- Email template content editor ---------- */
.tpl-toolbar { gap: 4px; }
.tpl-toolbar .vr { background: var(--border); width:1px; height:24px; align-self:center; margin: 0 2px; }
.tpl-toolbar .btn { padding: 4px 9px; font-size: 13px; line-height: 1; }
.tpl-content-editor { outline: none; }
.tpl-content-editor:focus { box-shadow: 0 0 0 .15rem rgba(59,130,246,.18); border-color: #93c5fd; }
.tpl-content-editor h1, .tpl-content-editor h2 { font-weight:700; margin: .6em 0 .3em; }
.tpl-content-editor p { margin: 0 0 .6em; }
.tpl-content-editor a { color: #2563eb; text-decoration: underline; }
.tpl-content-editor img { max-width: 100%; height: auto; border-radius: 6px; }
/* Variable chips inside the editor — visual badges that the user can delete as a single unit */
.tpl-var-chip {
  display: inline-block;
  background: linear-gradient(135deg, #dbeafe, #e0e7ff);
  color: #1d4ed8;
  padding: 1px 8px 2px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  font-family: 'SF Mono','Menlo','Monaco','Courier New',monospace;
  margin: 0 2px;
  vertical-align: 1px;
  user-select: all;
  border: 1px solid rgba(29,78,216,.25);
  white-space: nowrap;
}
.tpl-var-chip::before { content: "{ "; opacity: .55; }
.tpl-var-chip::after  { content: " }"; opacity: .55; }
[data-bs-theme="dark"] .tpl-var-chip { background: rgba(59,130,246,.22); color:#bfdbfe; border-color: rgba(147,197,253,.35); }

/* API form inputs: smaller + long keys wrap inside the box */
[data-testid^="api-"] .form-control,
[data-testid^="api-"] .form-select {
  font-size: 12px; padding: 5px 9px; line-height: 1.35;
  word-break: break-all; overflow-wrap: anywhere;
}
[data-testid^="api-"] textarea.form-control { min-height: 60px; font-family:'SF Mono','Menlo','Monaco','Courier New',monospace; }
[data-testid^="api-"] .form-label { font-size: 11px; margin-bottom: 2px; }
[data-testid^="api-"] code { word-break: break-all; overflow-wrap: anywhere; display:inline-block; max-width:100%; }
[data-testid^="api-"] input[type="text"], [data-testid^="api-"] input[type="url"], [data-testid^="api-"] input[type="email"] { font-family:'SF Mono','Menlo','Monaco','Courier New',monospace; }

/* Ensure content stays inside boxes — alignment + overflow safety */
.card-e { overflow:hidden; }
.card-e .card-body-p { overflow-x:auto; }
.tbl-e { overflow:auto; max-width:100%; }
.tbl-e table { table-layout:auto; }
.tbl-e td, .tbl-e th { vertical-align: middle; word-break: break-word; }

/* ============================================================
   EMAIL ACTIVITY CENTER — light + dark mode styles
   ============================================================ */
.lk-row { margin: 2px 0; line-height: 1; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; }
.lk-pill {
  font-size: 10.5px; font-weight: 600;
  background: #eff6ff; color: #1d4ed8;
  padding: 3px 7px; border-radius: 5px;
  border: 1px solid #bfdbfe;
  font-family: 'SF Mono','Menlo','Monaco','Courier New',monospace;
}
.sold-tag {
  font-size: 9px; font-weight: 800;
  color: #065f46; background: #d1fae5;
  padding: 3px 6px; border-radius: 4px;
  letter-spacing: .5px; vertical-align: middle;
  border: 1px solid #6ee7b7;
}
.tpl-chip {
  display: inline-block; font-size: 10px; font-weight: 600;
  padding: 3px 9px; border-radius: 999px; margin-top: 3px;
  color: #2563eb; background: #dbeafe; border: 1px solid #93c5fd;
}
.tpl-chip[data-tpl="review_request"]  { color:#7c3aed; background:#ede9fe; border-color:#c4b5fd; }
.tpl-chip[data-tpl="order_confirmation"] { color:#10b981; background:#d1fae5; border-color:#6ee7b7; }
.tpl-chip[data-tpl="inline"]          { color:#475569; background:#f1f5f9; border-color:#cbd5e1; }

/* DARK MODE — make every pill, tag & chip pop with proper contrast */
[data-bs-theme="dark"] .lk-pill {
  background: rgba(96,165,250,.12); color: #93c5fd; border-color: rgba(96,165,250,.35);
}
[data-bs-theme="dark"] .sold-tag {
  background: rgba(16,185,129,.18); color: #6ee7b7; border-color: rgba(16,185,129,.40);
}
[data-bs-theme="dark"] .tpl-chip {
  background: rgba(59,130,246,.18); color: #93c5fd; border-color: rgba(59,130,246,.40);
}
[data-bs-theme="dark"] .tpl-chip[data-tpl="review_request"] {
  background: rgba(167,139,250,.18); color: #c4b5fd; border-color: rgba(167,139,250,.40);
}
[data-bs-theme="dark"] .tpl-chip[data-tpl="order_confirmation"] {
  background: rgba(16,185,129,.18); color: #6ee7b7; border-color: rgba(16,185,129,.40);
}
[data-bs-theme="dark"] .tpl-chip[data-tpl="inline"] {
  background: rgba(148,163,184,.18); color: #cbd5e1; border-color: rgba(148,163,184,.40);
}

/* Customer link in Email Activity — readable in both modes */
[data-bs-theme="dark"] a[data-testid^="customer-link-"] { color: #93c5fd !important; }
[data-bs-theme="dark"] a[data-testid^="customer-link-"]:hover { color: #bfdbfe !important; text-decoration: underline; }

/* Resend popover background must match card-bg in dark mode (was hard-coded white) */
[data-bs-theme="dark"] div[id^="editResend"] {
  background: var(--card-bg) !important;
  border: 1px solid var(--border);
  color: var(--text);
}
[data-bs-theme="dark"] div[id^="editResend"] small { color: var(--muted); }

/* KPI tiles in Email Activity header (Sent / Opened / Queued / Failed) — keep
   their accent colour but lighten the value text in dark mode */
[data-bs-theme="dark"] .kpi-tile .kpi-value { color: #f8fafc; }

body { background: var(--bg); color: var(--text); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; font-size: 14px; position:relative; overflow-x: hidden; }

/* Watermark removed per user request — only the animated floating-icons
   layer below provides background ambience.  body::before still exists
   but holds nothing (kept as a hook in case we want a tint later). */
body::before { content: none; }

/* =============================================================
   FLOATING TECH ICONS — animated background layer.
   Larger, more visible glyphs that look like real product icons.
   Drift faster (12-18s/loop) so the screen feels alive.
   ============================================================= */
.adm-floats { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
.adm-floats i {
  position: absolute;
  font-size: 56px;
  opacity: 0.18;
  filter: drop-shadow(0 2px 4px rgba(15,23,42,.08));
  animation: adm-float-drift 16s ease-in-out infinite;
  will-change: transform;
}
[data-bs-theme="dark"] .adm-floats i { opacity: 0.22; }
.adm-floats i:nth-child(odd)  { animation-name: adm-float-drift; }
.adm-floats i:nth-child(even) { animation-name: adm-float-drift-rev; animation-duration: 18s; }
.adm-floats i:nth-child(3n)   { animation-duration: 14s; }
.adm-floats i:nth-child(4n)   { animation-duration: 20s; }
.adm-floats i:nth-child(5n)   { animation-duration: 12s; }

/* Per-icon real-product colours so they look like actual product logos */
.adm-floats .ic-win    { color: #0078D4; }     /* Windows blue */
.adm-floats .ic-office { color: #D24726; }     /* Office orange */
.adm-floats .ic-apple  { color: #6b7280; }     /* Apple gray */
.adm-floats .ic-droid  { color: #3DDC84; }     /* Android green */
.adm-floats .ic-shield { color: #DC2626; }     /* security red */
.adm-floats .ic-cloud  { color: #0EA5E9; }     /* cloud sky */
.adm-floats .ic-key    { color: #F59E0B; }     /* key amber */
.adm-floats .ic-cpu    { color: #8B5CF6; }     /* purple */
.adm-floats .ic-mail   { color: #2563EB; }     /* blue */
.adm-floats .ic-card   { color: #10B981; }     /* green */
.adm-floats .ic-globe  { color: #6366F1; }     /* indigo */
.adm-floats .ic-bell   { color: #EAB308; }     /* yellow */

@keyframes adm-float-drift {
  0%   { transform: translate(0, 0)         rotate(0deg)   scale(1); }
  25%  { transform: translate(20vw, -12vh)  rotate(45deg)  scale(1.15); }
  50%  { transform: translate(35vw, 18vh)   rotate(-25deg) scale(0.9); }
  75%  { transform: translate(15vw, 30vh)   rotate(60deg)  scale(1.1); }
  100% { transform: translate(0, 0)         rotate(0deg)   scale(1); }
}
@keyframes adm-float-drift-rev {
  0%   { transform: translate(0, 0)         rotate(0deg)    scale(1); }
  25%  { transform: translate(-18vw, 15vh)  rotate(-60deg)  scale(0.85); }
  50%  { transform: translate(-32vw, -10vh) rotate(40deg)   scale(1.2); }
  75%  { transform: translate(-15vw, -25vh) rotate(-30deg)  scale(1); }
  100% { transform: translate(0, 0)         rotate(0deg)    scale(1); }
}
@media (prefers-reduced-motion: reduce) {
  .adm-floats i { animation: none; }
}

/* Ensure all admin content sits above the watermark */
.adm-top, .adm-shell, .adm-sidebar, .adm-content, main, footer { position: relative; z-index: 1; }

/* ============ ADMIN TOPBAR (no public navbar) ============ */
.adm-top {
  background: var(--card-bg);
  border-bottom: 1px solid var(--border);
  padding: 14px 24px;
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  position: sticky; top:0; z-index: 1030;
  backdrop-filter: blur(6px);
}
.adm-top .brand-center {
  position:absolute; left:50%; transform:translateX(-50%);
  font-size:18px; font-weight:800; letter-spacing:.4px; color: var(--text);
  display:flex; align-items:center; gap:10px;
}
.adm-top .brand-center .m-logo {
  width:34px; height:34px; border-radius:9px;
  background:linear-gradient(135deg,#3b82f6,#1d4ed8);
  display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:18px;
  /* Animation driven by `body[data-brand-motion]` so admins can swap
     Bounce / Spin / Pulse / Static from Company Info → Brand Motion. */
  transform-style: preserve-3d;
  box-shadow: 0 6px 18px rgba(29,78,216,.35);
  will-change: transform;
}
.adm-top .brand-center .m-logo-img { box-shadow: 0 6px 18px rgba(29,78,216,.35); }
body[data-brand-motion="bounce"] .adm-top .brand-center .m-logo,
body[data-brand-motion="bounce"] .adm-top .brand-center .m-logo-img {
  animation: m-logo-spin-bounce 3s ease-in-out infinite;
}
body[data-brand-motion="spin"] .adm-top .brand-center .m-logo,
body[data-brand-motion="spin"] .adm-top .brand-center .m-logo-img {
  animation: m-logo-pure-spin 4.5s linear infinite;
}
body[data-brand-motion="pulse"] .adm-top .brand-center .m-logo,
body[data-brand-motion="pulse"] .adm-top .brand-center .m-logo-img {
  animation: m-logo-pulse 2.4s ease-in-out infinite;
}
body[data-brand-motion="static"] .adm-top .brand-center .m-logo,
body[data-brand-motion="static"] .adm-top .brand-center .m-logo-img {
  animation: none;
}
.adm-top .brand-center .m-logo:hover,
.adm-top .brand-center .m-logo-img:hover {
  animation-play-state: paused;
  cursor: pointer;
}
@keyframes m-logo-spin-bounce {
  0%   { transform: translateY(0)    rotateY(0deg)   scale(1); }
  25%  { transform: translateY(-6px) rotateY(90deg)  scale(1.05); }
  50%  { transform: translateY(0)    rotateY(180deg) scale(1); }
  75%  { transform: translateY(-6px) rotateY(270deg) scale(1.05); }
  100% { transform: translateY(0)    rotateY(360deg) scale(1); }
}
@keyframes m-logo-pure-spin {
  0%   { transform: rotateY(0deg); }
  100% { transform: rotateY(360deg); }
}
@keyframes m-logo-pulse {
  0%, 100% { transform: scale(1); box-shadow: 0 6px 18px rgba(29,78,216,.35); }
  50%      { transform: scale(1.10); box-shadow: 0 8px 26px rgba(29,78,216,.55); }
}
@media (prefers-reduced-motion: reduce) {
  .adm-top .brand-center .m-logo, .adm-top .brand-center .m-logo-img { animation: none; }
}
.adm-top .brand-center small { font-size:9px;letter-spacing:1.8px;color:var(--muted);font-weight:600;}
.adm-top .brand-center .adm-brand-cp {
  display: inline-block;
  font-size: 13px;
  letter-spacing: 3px;
  font-weight: 800;
  text-transform: uppercase;
  background: linear-gradient(90deg, #3b82f6 0%, #06b6d4 25%, #8b5cf6 50%, #ec4899 75%, #f59e0b 100%);
  background-size: 200% 100%;
  -webkit-background-clip: text;
          background-clip: text;
  -webkit-text-fill-color: transparent;
  text-shadow: 0 1px 0 rgba(255,255,255,.05);
  animation: adm-brand-cp-shimmer 6s linear infinite;
}
@keyframes adm-brand-cp-shimmer {
  0%   { background-position:   0% 50%; }
  100% { background-position: 200% 50%; }
}
@media (prefers-reduced-motion: reduce) {
  .adm-top .brand-center .adm-brand-cp { animation: none; }
}
.adm-top .left, .adm-top .right { display:flex;align-items:center;gap:10px; z-index:2; }

.adm-pill {
  background: var(--bg);
  border:1px solid var(--border);
  border-radius: 999px;
  padding: 6px 12px;
  font-size:12px; font-weight:600;
  color: var(--text);
  display:inline-flex; align-items:center; gap:6px;
  text-decoration:none;
}
.adm-pill:hover { background: var(--gray-soft); color: var(--text); }
.adm-pill.active { background: var(--brand); color:#fff; border-color: var(--brand); }
.adm-iconbtn {
  width:36px; height:36px; border-radius:50%;
  background: var(--bg); border:1px solid var(--border);
  display:inline-flex; align-items:center; justify-content:center;
  color: var(--text); cursor:pointer; text-decoration:none;
}
.adm-iconbtn:hover { background: var(--gray-soft); color: var(--text); }
.adm-bell { position: relative; }
.adm-bell .adm-bell-badge {
  position: absolute;
  top: -4px; right: -4px;
  min-width: 18px; height: 18px; padding: 0 5px;
  background: linear-gradient(135deg,#ef4444,#b91c1c);
  color: #fff;
  font-size: 10px; font-weight: 800;
  line-height: 18px; text-align: center;
  border-radius: 999px;
  border: 2px solid var(--card-bg, #fff);
  box-shadow: 0 2px 6px rgba(239,68,68,.45);
  letter-spacing: .2px;
}
.adm-bell:has(.adm-bell-badge) .bi { color: #ef4444; animation: adm-bell-shake 1.6s ease-in-out infinite; transform-origin: top center; }
/* Star bell uses amber for "review needs attention" semantics */
.adm-bell-rating:has(.adm-bell-badge) .bi { color: #f59e0b; }
.adm-bell-rating .adm-bell-badge {
  background: linear-gradient(135deg,#f59e0b,#d97706);
  box-shadow: 0 2px 6px rgba(245,158,11,.45);
}
.adm-nav-badge { margin-left:auto; background:#ef4444; color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:999px; line-height:1.4; min-width:18px; text-align:center; box-shadow:0 0 0 3px rgba(239,68,68,.18); animation: adm-bell-shake 1.6s ease-in-out infinite; transform-origin: center; }
.adm-sidebar .item { position: relative; display: flex; align-items: center; }
.adm-chat-toast { position:fixed; top:80px; right:22px; background:linear-gradient(135deg,#1e3a8a,#2563eb); color:#fff; padding:14px 18px; border-radius:12px; box-shadow:0 14px 30px rgba(15,23,42,.30); z-index:4000; max-width:340px; cursor:pointer; animation:adm-toast-in .25s cubic-bezier(.16,1,.3,1); }
.adm-chat-toast .ttl { font-weight:700; font-size:13px; margin-bottom:3px; display:flex; align-items:center; gap:6px; }
.adm-chat-toast .msg { font-size:12.5px; opacity:.95; line-height:1.4; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.adm-chat-toast .close { position:absolute; top:6px; right:8px; color:rgba(255,255,255,.7); cursor:pointer; font-size:14px; line-height:1; }
@keyframes adm-toast-in { from{opacity:0; transform:translateX(20px);} to{opacity:1; transform:translateX(0);} }
@keyframes adm-bell-shake {
  0%, 90%, 100% { transform: rotate(0); }
  92%, 96% { transform: rotate(-12deg); }
  94%, 98% { transform: rotate(12deg); }
}

.adm-dropdown { position:relative; }
.adm-dropdown-menu {
  position:absolute; right:0; top:calc(100% + 8px);
  background: var(--card-bg);
  border:1px solid var(--border);
  border-radius:12px;
  min-width: 220px;
  padding: 6px;
  box-shadow: 0 10px 28px rgba(0,0,0,.10);
  display:none;
  z-index: 2000;
}
.adm-dropdown.open .adm-dropdown-menu { display:block; }
.adm-dropdown-menu a {
  display:flex;align-items:center;gap:10px;
  padding:9px 12px; border-radius:8px;
  color: var(--text); text-decoration:none; font-size:13px;
}
.adm-dropdown-menu a:hover { background: var(--bg); }
.adm-dropdown-menu .sep { height:1px; background: var(--border); margin:4px 0; }

/* ============ LAYOUT ============ */
.adm-shell { display:flex; gap:22px; padding:22px; max-width: 1600px; margin: 0 auto; align-items: flex-start; }
.adm-sidebar {
  width: 230px; flex-shrink:0;
  background: var(--card-bg);
  border:1px solid var(--border); border-radius: 14px;
  padding: 12px 0;
  position: sticky; top: 84px;
  /* Cap the sidebar at viewport height and give it an isolated scroll —
     when the sidebar's own content overflows it scrolls internally,
     and `overscroll-behavior: contain` keeps the wheel/touch gesture
     from bubbling up to scroll the whole page behind it. */
  max-height: calc(100vh - 104px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
}
.adm-sidebar::-webkit-scrollbar { width: 6px; }
.adm-sidebar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.adm-sidebar::-webkit-scrollbar-thumb:hover { background: var(--muted); }
.adm-sidebar .side-section {
  padding:8px 18px 6px;
  font-size:10px;letter-spacing:1.5px;color: var(--muted);
  text-transform:uppercase; font-weight:700;
}
.adm-sidebar .item {
  display:flex; align-items:center; gap:11px;
  padding:9px 18px;
  color: var(--text); font-size:13.5px; font-weight:500;
  text-decoration:none;
  border-left:3px solid transparent;
}
.adm-sidebar .item i { font-size:16px; width:18px; }
.adm-sidebar .item:hover { background: var(--bg); }
.adm-sidebar .item.active {
  background: var(--blue-soft);
  color: var(--brand-dk);
  border-left-color: var(--brand);
  font-weight: 700;
}
[data-bs-theme="dark"] .adm-sidebar .item.active { color:#93c5fd; }
.adm-content { flex:1; min-width:0; }

/* ============ CARDS / TABLES ============ */
.card-e {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.02);
  transition: box-shadow .25s ease, transform .15s ease, border-color .25s ease;
  position: relative;
  overflow: hidden;
  isolation: isolate;
}
/* Premium gradient outline — sits *outside* the card body so the border
   becomes a multi-stop teal→blue→violet glow on hover.  Uses ::before so
   we don't touch the existing border / padding tokens. */
.card-e::before {
  content: "";
  position: absolute;
  inset: -1px;
  border-radius: inherit;
  padding: 1px;
  background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 35%, #8b5cf6 70%, #ec4899 100%);
  -webkit-mask: linear-gradient(#000, #000) content-box, linear-gradient(#000, #000);
  -webkit-mask-composite: xor;
          mask: linear-gradient(#000, #000) content-box, linear-gradient(#000, #000);
          mask-composite: exclude;
  opacity: 0;
  transition: opacity .25s ease;
  pointer-events: none;
  z-index: 0;
}
.card-e:hover::before { opacity: .55; }
/* 4px brand-color left-accent bar on every card-e, drawn via ::after so
   the existing border doesn't need to change.  Subtle in light mode,
   sharper in dark mode for contrast. */
.card-e::after {
  content: "";
  position: absolute;
  left: 0; top: 12px; bottom: 12px;
  width: 4px; border-radius: 2px;
  background: linear-gradient(180deg, #0ea5e9, #1d4ed8 60%, #4338ca);
  opacity: .85;
  pointer-events: none;
  z-index: 0;
}
[data-bs-theme="dark"] .card-e::after { opacity: 1; box-shadow: 0 0 14px rgba(59,130,246,.45); }
/* Make sure the card's children sit above the ::before / ::after layers. */
.card-e > * { position: relative; z-index: 1; }
.card-e:hover { box-shadow: 0 8px 24px rgba(15,23,42,.10), 0 2px 5px rgba(15,23,42,.06); border-color: transparent; transform: translateY(-1px); }
[data-bs-theme="dark"] .card-e:hover { box-shadow: 0 8px 28px rgba(0,0,0,.45), 0 2px 5px rgba(0,0,0,.30); }
/* Opt-out modifier — let specific callouts (the blue "Where these
   details appear" / red SMTP banner / amber alignment notice) suppress
   the global accent bar and gradient outline so their own coloured
   borders aren't visually duplicated. */
.card-e.card-e--plain::before,
.card-e.card-e--plain::after { content: none; }

/* ---------- Callout / banner variants used across admin tabs ---------- */
.ci-where-card {
  background: linear-gradient(135deg, #eff6ff, #f0f9ff);
  border: 1px solid #bfdbfe !important;
  color: #1e3a8a;
}
.ci-where-card .small { color: #1e3a8a; }
[data-bs-theme="dark"] .ci-where-card {
  background: linear-gradient(135deg, rgba(30,64,175,.22), rgba(14,165,233,.16));
  border-color: rgba(96,165,250,.42) !important;
  color: #dbeafe;
}
[data-bs-theme="dark"] .ci-where-card .small { color: #dbeafe; }
[data-bs-theme="dark"] .ci-where-card strong { color: #93c5fd !important; }

.smtp-banner-critical, .emails-banner-critical {
  background: linear-gradient(90deg, #fee2e2 0%, #fef3c7 100%);
  border: 1px solid #fca5a5 !important;
  border-left: 5px solid #ef4444 !important;
  color: #7f1d1d;
}
[data-bs-theme="dark"] .smtp-banner-critical,
[data-bs-theme="dark"] .emails-banner-critical {
  background: linear-gradient(90deg, rgba(127,29,29,.32) 0%, rgba(120,53,15,.28) 100%);
  border-color: rgba(248,113,113,.55) !important;
  border-left-color: #f87171 !important;
  color: #fecaca;
}
[data-bs-theme="dark"] .smtp-banner-critical strong,
[data-bs-theme="dark"] .emails-banner-critical strong { color:#fecaca; }

.smtp-banner-warn {
  background: linear-gradient(90deg, #fef3c7 0%, #fefce8 100%);
  border: 1px solid #fcd34d !important;
  border-left: 5px solid #f59e0b !important;
  color: #78350f;
}
[data-bs-theme="dark"] .smtp-banner-warn {
  background: linear-gradient(90deg, rgba(120,53,15,.30) 0%, rgba(133,77,14,.20) 100%);
  border-color: rgba(252,211,77,.50) !important;
  border-left-color: #fbbf24 !important;
  color: #fde68a;
}
[data-bs-theme="dark"] .smtp-banner-warn strong { color: #fcd34d; }

.company-info-shell { border-left: 4px solid #3b82f6 !important; }
[data-bs-theme="dark"] .company-info-shell { border-left-color: #60a5fa !important; }

/* ---------- Modern drag-and-drop upload zone ---------- */
.dz-upload {
  position: relative;
  border: 2px dashed var(--border);
  border-radius: 14px;
  padding: 22px 18px;
  background: linear-gradient(135deg, rgba(14,165,233,.04), rgba(99,102,241,.04));
  transition: border-color .2s ease, background .2s ease, transform .2s ease;
}
[data-bs-theme="dark"] .dz-upload {
  background: linear-gradient(135deg, rgba(14,165,233,.10), rgba(99,102,241,.08));
  border-color: rgba(96,165,250,.30);
}
.dz-upload:hover, .dz-upload.dz-hover {
  border-color: #3b82f6;
  background: linear-gradient(135deg, rgba(14,165,233,.10), rgba(99,102,241,.12));
  transform: translateY(-1px);
}
.dz-upload.dz-dragover {
  border-color: #06b6d4;
  background: linear-gradient(135deg, rgba(6,182,212,.18), rgba(14,165,233,.18));
  box-shadow: 0 0 0 4px rgba(14,165,233,.18);
}
.dz-upload input[type="file"] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.dz-upload .dz-body {
  display: flex; gap: 16px; align-items: center; flex-wrap: wrap;
}
.dz-upload .dz-icon {
  width: 54px; height: 54px; flex-shrink: 0;
  background: linear-gradient(135deg, #0ea5e9, #1d4ed8);
  border-radius: 14px;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff; font-size: 24px;
  box-shadow: 0 6px 18px rgba(29,78,216,.30);
}
.dz-upload .dz-label  { font-weight: 700; font-size: 14px; color: var(--text); }
.dz-upload .dz-hint   { font-size: 12px; color: var(--muted); margin-top: 2px; }
.dz-upload .dz-actions { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; position: relative; z-index: 2; }
.dz-upload .dz-btn {
  border: none; font-weight: 600; font-size: 13px;
  border-radius: 999px; padding: 7px 16px;
  display: inline-flex; align-items: center; gap: 6px;
  transition: filter .15s ease, transform .12s ease;
  cursor: pointer;
}
.dz-upload .dz-btn-primary { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; box-shadow: 0 4px 10px rgba(29,78,216,.30); }
.dz-upload .dz-btn-primary:hover { filter: brightness(1.05); transform: translateY(-1px); }
.dz-upload .dz-btn-ghost { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
.dz-upload .dz-btn-ghost:hover { background: var(--gray-soft); }
[data-bs-theme="dark"] .dz-upload .dz-btn-ghost { background: #334155; color:#e2e8f0; border-color:#475569; }
.dz-upload .dz-filename {
  font-size: 12px; color: var(--muted); max-width: 220px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.card-e .card-head {
  display:flex; align-items:center; justify-content:space-between;
  padding: 14px 18px; border-bottom: 1px solid var(--border);
}
.card-e .card-head .ttl { display:flex; align-items:center; gap:10px; font-weight:700; font-size:14px; color:var(--text); }
.card-e .card-head .ttl i { color: var(--brand); font-size:16px; }
.card-e .card-head .sub { font-size:11px; color: var(--muted); }
.card-e .card-body-p { padding: 18px; }

/* KPI tiles — premium */
.kpi-tile {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 18px 18px 16px;
  position: relative;
  overflow: hidden;
  transition: transform .15s ease, box-shadow .2s ease;
}
.kpi-tile:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,.08); }
.kpi-tile .kpi-icon {
  position:absolute; top:14px; right:14px;
  width:38px; height:38px; border-radius:10px;
  display:inline-flex; align-items:center; justify-content:center; font-size:18px;
}
.kpi-tile .kpi-label {
  font-size:11px; letter-spacing:1px; text-transform:uppercase;
  font-weight:700; color: var(--muted);
}
.kpi-tile .kpi-value { font-size:26px; font-weight:800; margin-top:6px; line-height:1.1; color: var(--text); }
.kpi-tile .kpi-delta { font-size:11px; font-weight:600; margin-top:6px; }
.kpi-tile.green  .kpi-icon { background:var(--green-soft); color:#047857; }  .kpi-tile.green  .kpi-value { color:#10b981; }
.kpi-tile.blue   .kpi-icon { background:var(--blue-soft);  color:#1d4ed8; }  .kpi-tile.blue   .kpi-value { color:#3b82f6; }
.kpi-tile.amber  .kpi-icon { background:var(--amber-soft); color:#92400e; }  .kpi-tile.amber  .kpi-value { color:#f59e0b; }
.kpi-tile.purple .kpi-icon { background:#ede9fe; color:#5b21b6; }            .kpi-tile.purple .kpi-value { color:#8b5cf6; }
.kpi-tile.red    .kpi-icon { background:var(--red-soft); color:#b91c1c; }    .kpi-tile.red    .kpi-value { color:#ef4444; }
.kpi-tile.cyan   .kpi-icon { background:#cffafe; color:#0e7490; }            .kpi-tile.cyan   .kpi-value { color:#06b6d4; }
[data-bs-theme="dark"] .kpi-tile.purple .kpi-icon { background:#312e81; color:#c4b5fd; }
[data-bs-theme="dark"] .kpi-tile.cyan   .kpi-icon { background:#155e75; color:#a5f3fc; }

/* Sparkline / mini chart bars */
.chart-bars { display:flex; align-items:end; gap:3px; height:140px; padding:6px 0; }
.chart-bars .b { flex:1; border-radius:5px 5px 0 0; background:linear-gradient(180deg, var(--brand) 0%, var(--brand-dk) 100%); min-width:5px; transition: opacity .15s; cursor:pointer; }
.chart-bars .b:hover { opacity:.75; }

/* Mini-list rows */
.mini-row {
  display:flex; align-items:center; gap:12px;
  padding: 10px 0; border-top: 1px solid var(--border);
}
.mini-row:first-child { border-top: none; }
.mini-row .rank {
  width:24px; height:24px; border-radius:50%;
  background: var(--blue-soft); color: var(--brand-dk);
  display:inline-flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700; flex-shrink:0;
}
.mini-row .thumb { width:32px; height:32px; object-fit:contain; background:var(--bg); border-radius:6px; padding:3px; flex-shrink:0; }

/* Progress bar */
.prog { height:6px; background:var(--bg); border-radius:3px; overflow:hidden; }
.prog > span { display:block; height:100%; background:linear-gradient(90deg,#10b981,#34d399); border-radius:3px; }
.prog.warn > span { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
.prog.danger > span { background:linear-gradient(90deg,#ef4444,#f87171); }

/* Funnel */
.funnel-row {
  display:flex; align-items:center; gap:12px;
  padding: 10px 0;
}
.funnel-bar {
  flex:1; height: 34px; border-radius:8px;
  background: var(--blue-soft);
  display:flex; align-items:center; padding: 0 12px;
  color: var(--brand-dk); font-weight:700; font-size:13px;
  position: relative; overflow:hidden;
}
.funnel-bar.green { background: var(--green-soft); color:#047857; }
.funnel-bar.amber { background: var(--amber-soft); color:#92400e; }
.funnel-bar.cyan  { background:#cffafe; color:#0e7490; }
.funnel-bar.purple{ background:#ede9fe; color:#5b21b6; }
[data-bs-theme="dark"] .funnel-bar.purple { background:#312e81; color:#c4b5fd; }
[data-bs-theme="dark"] .funnel-bar.cyan   { background:#155e75; color:#a5f3fc; }
.funnel-label { width: 110px; font-size:12px; color: var(--muted); }
.funnel-num { margin-left:auto; font-weight:800; font-size:14px; }
.tbl-e { background: var(--card-bg); border:1px solid var(--border); border-radius: 12px; overflow:hidden; }
.tbl-e table { margin:0; color: var(--text); }
.tbl-e thead th { background: var(--bg); color: var(--muted); text-transform:uppercase; font-size:11px; letter-spacing:.7px; font-weight:600; padding:11px 14px; border:none; }
.tbl-e tbody td { padding:12px 14px; border-top:1px solid var(--border); vertical-align: middle; font-size:13.5px; }
.tbl-e tbody tr:hover { background: var(--bg); }

/* ============ BADGES / BUTTONS ============ */
.s-badge { display:inline-block; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:600; }
.s-badge.queued, .s-badge.new      { background: var(--amber-soft); color:#92400e; }
.s-badge.sent, .s-badge.contacted  { background: var(--blue-soft); color:#1d4ed8; }
.s-badge.delivered, .s-badge.paid, .s-badge.qualified, .s-badge.active, .s-badge.opened { background: var(--green-soft); color:#047857; }
.s-badge.failed, .s-badge.lost, .s-badge.refunded, .s-badge.cancelled, .s-badge.inactive { background: var(--red-soft); color:#b91c1c; }
.s-badge.converted { background:#cffafe; color:#0e7490; }

.btn-soft-gray  { background: var(--gray-soft);  color: var(--text);    border:none; }
.btn-soft-green { background: var(--green-soft); color:#047857;         border:none; }
.btn-soft-blue  { background: var(--blue-soft);  color: var(--brand-dk);border:none; }
.btn-soft-red   { background: var(--red-soft);   color:#b91c1c;         border:none; }
.btn-soft-gray:hover, .btn-soft-blue:hover, .btn-soft-green:hover, .btn-soft-red:hover { filter: brightness(.95); }

.btn-add-glow {
  background: linear-gradient(135deg,#3b82f6 0%,#1d4ed8 100%);
  color:#fff; border:none; border-radius:50%;
  width:48px; height:48px; font-size:22px;
  display:inline-flex; align-items:center; justify-content:center;
  box-shadow:0 0 0 0 rgba(59,130,246,.6);
  animation: glowpulse 2s infinite;
}
.btn-add-glow:hover { transform: scale(1.06); color:#fff; }
@keyframes glowpulse {
  0%,100% { box-shadow:0 0 0 0 rgba(59,130,246,.55),0 4px 12px rgba(59,130,246,.35); }
  50%     { box-shadow:0 0 0 12px rgba(59,130,246,0),0 4px 12px rgba(59,130,246,.35); }
}

.key-stats { display:flex; gap:10px; }
.key-stats .key-pill { flex:1; background: var(--card-bg); border:1px solid var(--border); border-radius:10px; padding:12px 14px; text-align:center; }
.key-stats .key-pill .num { font-size:22px; font-weight:700; }
.key-stats .key-pill .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color: var(--muted); margin-top:3px;}
.key-stats .key-pill.avail { border-left:4px solid var(--green); }
.key-stats .key-pill.sold  { border-left:4px solid var(--brand); }

a { color: var(--brand-dk); }
a:hover { color: var(--brand); }
.form-control, .form-select { background: var(--card-bg); color: var(--text); border-color: var(--border); }
.form-control:focus, .form-select:focus { background: var(--card-bg); color: var(--text); }
hr { border-color: var(--border); opacity:.5; }

/* ============ TIMELINE ============ */
.timeline { padding-left:0; list-style:none; }
.timeline li { position:relative; padding:10px 0 10px 36px; border-left:2px solid var(--border); margin-left:12px; }
.timeline li::before {
  content:''; position:absolute; left:-9px; top:14px;
  width:16px;height:16px;border-radius:50%;
  background: var(--gray-soft); border:3px solid var(--card-bg);
}
.timeline li.done::before { background: var(--green); }
.timeline li.fail::before { background: var(--red); }
.timeline li .ttitle { font-weight:600; }
.timeline li .tdate { font-size:11px; color: var(--muted); }

/* ============ INSTALL GUIDE STEPS ============ */
.step-card { display:flex; gap:14px; padding:14px 16px; background: var(--card-bg); border:1px solid var(--border); border-radius:10px; margin-bottom:10px; }
.step-num {
  width:38px; height:38px; flex-shrink:0;
  border-radius:50%; background: linear-gradient(135deg,#3b82f6,#1d4ed8);
  color:#fff; display:inline-flex; align-items:center; justify-content:center;
  font-weight:700; font-size:14px;
}
.step-icon {
  width:42px; height:42px; flex-shrink:0;
  border-radius:10px; background: var(--blue-soft); color: var(--brand-dk);
  display:inline-flex; align-items:center; justify-content:center; font-size:20px;
}
.step-body .ttitle { font-weight:700; margin-bottom:2px; }
.step-body small { color: var(--muted); }

@media (max-width: 991px) {
  .adm-top .brand-center { position:static; transform:none; }
  .adm-top .brand-center small { display:none; }
  .adm-shell { flex-direction:column; padding:14px; gap:14px; }
  .adm-sidebar { width:260px; position:fixed; top:0; left:-280px; height:100vh; z-index:2500; border-radius:0; padding-top:60px; transition:left .25s ease; box-shadow:0 0 20px rgba(0,0,0,.25); overflow-y:auto; }
  .adm-sidebar.open { left:0; }
  .adm-sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:2400; }
  .adm-sidebar.open ~ .adm-sidebar-overlay,
  .adm-sidebar.open + .adm-sidebar-overlay { display:block; }
  .adm-hamburger { display:inline-flex !important; }
  .adm-pill { padding:5px 9px; font-size:11px; }
  .adm-pill .ms-1 { display:none; }
}
.adm-hamburger {
  display:none;
  width:36px; height:36px; border-radius:9px;
  background: var(--bg); border:1px solid var(--border);
  align-items:center; justify-content:center;
  color: var(--text); cursor:pointer; font-size:20px;
}
.adm-hamburger:hover { background: var(--gray-soft); }

/* Mobile-friendly tables (admin) */
@media (max-width: 768px) {
  .tbl-e { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .tbl-e table { min-width: 600px; }
  .card-e { border-radius:10px; }
  .card-e.p-3 { padding:12px !important; }
  .kpi-tile { padding:14px 14px 12px; }
  .kpi-tile .kpi-value { font-size:20px; }
  .row.g-3 > [class^="col-"], .row.g-4 > [class^="col-"] { margin-bottom:8px; }
  h5.fw-bold { font-size:16px; }

  /* Adm-content padding tightens up on small screens */
  .adm-content { padding: 12px !important; }
  .adm-top { padding: 0 12px; }

  /* Topbar — hide the verbose right-side widgets to keep room for the bell + avatar */
  .adm-top .right .adm-mode-toggle, .adm-top .right .adm-region-dd { display:none !important; }

  /* All filter pills & toolbars wrap nicely without horizontal scroll */
  .nav.nav-pills { flex-wrap:wrap !important; }
  .vis-filter-bar { padding: 8px 10px; gap: 6px; }
  .vis-filter-group { flex: 1 1 100%; }
  .vis-filter-group:last-child { justify-content: flex-start; }
  .vis-filter-group input[type="date"], .vis-filter-group select { max-width: 100%; flex: 1; }

  /* Email-activity cards stack their meta + buttons on small screens */
  .ec-head { flex-direction: column; align-items: flex-start; gap: 6px; }
  .ec-actions { flex-wrap: wrap; gap: 6px; }
  .ec-actions .btn { font-size: 11.5px; padding: 5px 10px; }

  /* Lead-management table cells wrap content rather than overflowing */
  table.table { font-size: 12.5px; }
  table.table td, table.table th { padding: 8px 6px; }
}

/* Extra-narrow phones — go even tighter */
@media (max-width: 480px) {
  .adm-content { padding: 8px !important; }
  .adm-top .adm-brand-cp { font-size: 9px !important; letter-spacing: 1px !important; }
  .vrange-pills, .vrange-pill { font-size: 11px; }
  .vis-num { font-size: 30px; }
  .vis-flag-chip { font-size: 11px; padding: 4px 8px; }
  .ec-actions .btn span:not(.spinner-border) { display:inline; }
}

/* ============ MOBILE OVERFLOW + DARK-MODE READABILITY ============
   Fixes the "background scrolls instead of menu" bug + ensures all
   text in dark-mode stays high-contrast on phones.
*/
@media (max-width: 991px) {
  /* Cards never push past the viewport.  Forces .row.g-3 children to
     respect the screen width so KPI tiles no longer clip on the right. */
  .adm-content { max-width: 100%; box-sizing: border-box; }
  .adm-content .row { margin-left: 0 !important; margin-right: 0 !important; }
  .adm-content .row > [class^="col-"], .adm-content .row > [class*=" col-"] { padding-left: 6px; padding-right: 6px; }
  .card-e, .kpi-tile { max-width: 100%; box-sizing: border-box; }

  /* The .adm-shell + .adm-content are inside body which already has
     overflow-x:hidden, but explicitly clip here for safety so admins
     don't see the empty horizontal gutter on iOS Safari. */
  .adm-shell { overflow-x: clip; width: 100%; }
}
@media (max-width: 768px) {
  /* Background floating-icon layer becomes too noisy on small screens
     in dark mode — keep it subtle for better content readability. */
  .adm-floats i { font-size: 38px; opacity: 0.10; }
  [data-bs-theme="dark"] .adm-floats i { opacity: 0.12; }

  /* Dark-mode high-contrast text on phones (the KPI label & sub-headings
     previously appeared washed-out on small AMOLED panels). */
  [data-bs-theme="dark"] .kpi-tile .kpi-label,
  [data-bs-theme="dark"] .text-muted,
  [data-bs-theme="dark"] small.text-muted { color: #e2e8f0 !important; }
  [data-bs-theme="dark"] .card-e { background: #1f2a3d; }
  [data-bs-theme="dark"] .adm-top { background: #1e293b; }
  [data-bs-theme="dark"] .adm-sidebar { background: #1f2a3d; }

  /* Topbar pills/icons get bigger tap targets and clear background fills
     so they don't blend into the navy bar on mobile dark mode. */
  [data-bs-theme="dark"] .adm-iconbtn { background: #334155; border-color:#475569; color:#f1f5f9; }
  [data-bs-theme="dark"] .adm-iconbtn:hover { background:#475569; }
  [data-bs-theme="dark"] .adm-pill { background:#334155; border-color:#475569; color:#f1f5f9; }
}
</style>
</head>
<body data-brand-motion="<?= esc(setting_get('company_logo_motion', 'bounce')) ?>">

<!-- ============================================================
     Floating tech icons — real product-style icons drift across
     the background.  Faster animation, bigger size, real colours.
     ============================================================ -->
<div class="adm-floats" aria-hidden="true" data-testid="adm-floats">
  <i class="bi bi-windows      ic-win"    style="left:5%;  top:8%;  animation-delay: 0s;"></i>
  <i class="bi bi-microsoft    ic-office" style="left:18%; top:62%; animation-delay: -2s;"></i>
  <i class="bi bi-shield-lock  ic-shield" style="left:32%; top:18%; animation-delay: -4s;"></i>
  <i class="bi bi-key-fill     ic-key"    style="left:46%; top:75%; animation-delay: -6s;"></i>
  <i class="bi bi-cloud-fill   ic-cloud"  style="left:60%; top:30%; animation-delay: -1s;"></i>
  <i class="bi bi-laptop       ic-win"    style="left:74%; top:55%; animation-delay: -3s;"></i>
  <i class="bi bi-fingerprint  ic-shield" style="left:88%; top:12%; animation-delay: -5s;"></i>
  <i class="bi bi-cpu-fill     ic-cpu"    style="left:10%; top:42%; animation-delay: -7s;"></i>
  <i class="bi bi-envelope-paper ic-mail" style="left:28%; top:88%; animation-delay: -8s;"></i>
  <i class="bi bi-bag-check    ic-card"   style="left:52%; top:8%;  animation-delay: -9s;"></i>
  <i class="bi bi-graph-up     ic-cpu"    style="left:68%; top:85%; animation-delay: -10s;"></i>
  <i class="bi bi-globe2       ic-globe"  style="left:82%; top:38%; animation-delay: -11s;"></i>
  <i class="bi bi-credit-card-2-front ic-card" style="left:38%; top:48%; animation-delay: -12s;"></i>
  <i class="bi bi-bell-fill    ic-bell"   style="left:90%; top:72%; animation-delay: -13s;"></i>
  <i class="bi bi-apple        ic-apple"  style="left:2%;  top:78%; animation-delay: -14s;"></i>
  <i class="bi bi-android2     ic-droid"  style="left:42%; top:32%; animation-delay: -15s;"></i>
  <i class="bi bi-shield-check ic-shield" style="left:65%; top:65%; animation-delay: -2.5s;"></i>
  <i class="bi bi-window-stack ic-win"    style="left:22%; top:25%; animation-delay: -4.5s;"></i>
</div>

<header class="adm-top" data-testid="adm-topbar">
  <div class="left">
    <button class="adm-hamburger" data-testid="sidebar-toggle" onclick="document.querySelector('.adm-sidebar').classList.toggle('open')" title="Menu">
      <i class="bi bi-list"></i>
    </button>
  </div>

  <div class="brand-center" data-testid="adm-brand">
    <?php if ($adm_brand_logo !== ''): ?>
      <img src="<?= esc($adm_brand_logo) ?>" alt="<?= esc($adm_brand_name) ?>" class="m-logo-img" style="height:34px;width:auto;max-width:120px;object-fit:contain;border-radius:9px;">
    <?php else: ?>
      <span class="m-logo" data-testid="adm-brand-letter"><?= esc($adm_brand_letter) ?></span>
    <?php endif; ?>
    <div>
      <small class="adm-brand-cp">ADMIN CONTROL PANEL</small>
    </div>
  </div>

  <div class="right">
    <div class="adm-dropdown" id="ddRegion" data-testid="region-dropdown">
      <button class="adm-pill" onclick="document.getElementById('ddRegion').classList.toggle('open')" title="Switch region / currency">
        <i class="bi bi-globe"></i> <?= esc($rg['code']) ?> · <?= esc($rg['currency_symbol']) ?>
        <i class="bi bi-chevron-down ms-1"></i>
      </button>
      <div class="adm-dropdown-menu">
        <?php foreach (all_regions() as $r): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['region' => $r['code']])) ?>" data-testid="region-<?= esc($r['code']) ?>">
            <i class="bi bi-flag<?= $r['code']===$rg['code']?'-fill text-primary':'' ?>"></i>
            <div><div class="fw-semibold"><?= esc($r['name']) ?></div><small class="text-muted"><?= esc($r['currency_symbol']) ?> <?= esc($r['currency']) ?> · Tax <?= number_format($r['tax_rate']*100,1) ?>%</small></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    // Compute failed email count for the notification bell (cheap query, cached per-request)
    try {
        $failedCount = (int)db()->query("SELECT COUNT(*) FROM email_outbox WHERE status IN ('failed','bounced')")->fetchColumn();
    } catch (Throwable $e) { $failedCount = 0; }
    // Compute unread customer chat messages (drives the Lead Management sidebar badge + bell)
    try {
        $chatUnread = (int)db()->query("SELECT COUNT(*) FROM chat_messages WHERE sender='customer' AND read_at IS NULL")->fetchColumn();
    } catch (Throwable $e) { $chatUnread = 0; }
    // Unhappy-customer alerts — reviews of 3 stars or less that the admin
    // hasn't acknowledged yet.  Drives the star-shaped notification bell.
    try {
        $lowRatingUnread = (int)db()->query("SELECT COUNT(*) FROM customer_reviews WHERE rating IS NOT NULL AND rating <= 3 AND admin_seen_at IS NULL")->fetchColumn();
    } catch (Throwable $e) { $lowRatingUnread = 0; }
    ?>
    <a class="adm-iconbtn adm-bell adm-bell-rating" href="admin.php?tab=reviews&status=hidden" title="<?= $lowRatingUnread?($lowRatingUnread.' new low-rating review(s) — needs attention'):'No new low-rating reviews' ?>" data-testid="adm-bell-rating">
      <i class="bi bi-star<?= $lowRatingUnread?'-fill':'' ?>"></i>
      <?php if ($lowRatingUnread > 0): ?>
        <span class="adm-bell-badge" data-testid="adm-bell-rating-badge"><?= $lowRatingUnread > 99 ? '99+' : $lowRatingUnread ?></span>
      <?php endif; ?>
    </a>
    <a class="adm-iconbtn adm-bell" href="admin.php?tab=emails&filter=failed" title="<?= $failedCount?($failedCount.' failed email(s) need attention'):'No failed emails' ?>" data-testid="adm-bell">
      <i class="bi bi-bell<?= $failedCount?'-fill':'' ?>"></i>
      <?php if ($failedCount > 0): ?>
        <span class="adm-bell-badge" data-testid="adm-bell-badge"><?= $failedCount > 99 ? '99+' : $failedCount ?></span>
      <?php endif; ?>
    </a>
    <a class="adm-iconbtn" href="#" title="Toggle theme" data-testid="theme-toggle" onclick="toggleAdmTheme(event)">
      <i id="admThemeIcon" class="bi <?= $adminMode==='dark'?'bi-sun':'bi-moon-stars' ?>"></i>
    </a>
    <div class="adm-dropdown" id="ddUser">
      <button class="adm-iconbtn" onclick="document.getElementById('ddUser').classList.toggle('open')" data-testid="user-menu">
        <i class="bi bi-person-circle"></i>
      </button>
      <div class="adm-dropdown-menu">
        <a href="#" style="pointer-events:none;">
          <i class="bi bi-person"></i>
          <div><div style="font-weight:600;"><?= esc($admin['email'] ?? '—') ?></div><small class="text-muted">Administrator</small></div>
        </a>
        <div class="sep"></div>
        <a href="admin.php?tab=settings"><i class="bi bi-gear"></i> Settings</a>
        <a href="admin.php?tab=api"><i class="bi bi-plug"></i> API Management</a>
        <div class="sep"></div>
        <a href="logout.php" data-testid="user-logout"><i class="bi bi-box-arrow-right text-danger"></i> Sign out</a>
      </div>
    </div>
  </div>
</header>

<?php
// Theme toggle is now handled at the top of this file BEFORE HTML output.
?>

<div class="adm-shell">
  <aside class="adm-sidebar" data-testid="adm-sidebar">
    <div class="side-section">Overview</div>
    <?php foreach (['dashboard','company','regions','inventory'] as $k): $i = $navItems[$k]; ?>
      <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
        <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
      </a>
    <?php endforeach; ?>
    <div class="side-section">Catalog</div>
    <?php foreach (['products'] as $k): $i = $navItems[$k]; ?>
      <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
        <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
      </a>
    <?php endforeach; ?>
    <div class="side-section">Commerce</div>
    <?php foreach (['orders','sales','leads'] as $k): $i = $navItems[$k]; ?>
      <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
        <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
        <?php if ($k==='leads' && $chatUnread > 0): ?>
          <span class="adm-nav-badge" id="navChatBadge" data-testid="adm-nav-leads-badge"><?= $chatUnread > 99 ? '99+' : $chatUnread ?></span>
        <?php elseif ($k==='leads'): ?>
          <span class="adm-nav-badge" id="navChatBadge" data-testid="adm-nav-leads-badge" style="display:none;">0</span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
    <div class="side-section">Communication</div>
    <?php foreach (['emails','reviews','templates'] as $k): $i = $navItems[$k]; ?>
      <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
        <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
      </a>
    <?php endforeach; ?>
    <div class="side-section">System</div>
    <?php foreach (['gateways','smtp'] as $k): $i = $navItems[$k]; ?>
      <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
        <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
      </a>
    <?php endforeach; ?>
  </aside>
  <div class="adm-sidebar-overlay" onclick="document.querySelector('.adm-sidebar').classList.remove('open')"></div>

  <main class="adm-content">

<script>
document.addEventListener('click', function(e){
  document.querySelectorAll('.adm-dropdown.open').forEach(function(d){
    if (!d.contains(e.target)) d.classList.remove('open');
  });
});

// ============================================================================
// LIVE CHAT GLOBAL POLLER
// Polls /ajax/chat-admin.php?action=unread every 8s.  Updates the sidebar
// "Lead Management" badge and pops a toast when a new customer message arrives.
// ============================================================================
(function(){
  if (window.__admChatPollerStarted) return; window.__admChatPollerStarted = true;
  let prev = parseInt(document.getElementById('navChatBadge')?.textContent || '0', 10) || 0;
  let prevLatestId = 0;

  function updateBadge(n){
    const b = document.getElementById('navChatBadge'); if (!b) return;
    if (n > 0) { b.textContent = n > 99 ? '99+' : n; b.style.display = ''; }
    else { b.style.display = 'none'; }
  }

  function showToast(latest){
    if (!latest) return;
    // Don't double-toast the same message
    if (latest.lead_id && latest.message && prevLatestId === (latest.lead_id+'|'+latest.message)) return;
    prevLatestId = latest.lead_id+'|'+latest.message;
    // Suppress toast if the admin already has that lead's chat open
    if (typeof window.admChatCurrentLeadId === 'function' && window.admChatCurrentLeadId() === parseInt(latest.lead_id,10)) return;
    const old = document.querySelector('.adm-chat-toast'); if (old) old.remove();
    const t = document.createElement('div');
    t.className = 'adm-chat-toast';
    t.setAttribute('data-testid', 'chat-toast');
    const safe = (s) => String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    t.innerHTML = '<span class="close">&times;</span>'
      + '<div class="ttl"><i class="bi bi-chat-dots-fill"></i> New message · '+safe(latest.name||'Customer')+'</div>'
      + '<div class="msg">'+safe(latest.message)+'</div>';
    t.addEventListener('click', function(e){
      if (e.target.classList.contains('close')) { t.remove(); return; }
      // Navigate to leads + auto-open chat
      window.location.href = 'admin.php?tab=leads&autochat=' + encodeURIComponent(latest.lead_id);
    });
    document.body.appendChild(t);
    setTimeout(()=> t.remove(), 8000);
    // Subtle ping sound via WebAudio (no external asset)
    try {
      const ac = new (window.AudioContext||window.webkitAudioContext)();
      const o = ac.createOscillator(), g = ac.createGain();
      o.connect(g); g.connect(ac.destination);
      o.frequency.value = 880; g.gain.value = 0.04;
      o.start(); o.frequency.exponentialRampToValueAtTime(1320, ac.currentTime + 0.12);
      g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.25);
      setTimeout(()=> { o.stop(); ac.close(); }, 300);
    } catch(e){}
  }

  async function tick(){
    try {
      const r = await fetch((window.MAVEN_BASE||'/') + 'ajax/chat-admin.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'unread'})
      });
      if (!r.ok) return;
      const j = await r.json();
      if (!j || !j.ok) return;
      const n = parseInt(j.unread||0, 10);
      updateBadge(n);
      if (n > prev && j.latest) showToast(j.latest);
      prev = n;
    } catch(e){ /* offline; try later */ }
  }
  // Kick off after a small delay; then every 8s
  setTimeout(tick, 1500);
  setInterval(tick, 8000);
})();
</script>
