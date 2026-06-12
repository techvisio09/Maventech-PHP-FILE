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
if (!function_exists('current_admin')) {
    function current_admin(): ?array { return function_exists('current_user') ? current_user() : null; }
}
$navItems = [
    'dashboard'   => ['icon' => 'bi-speedometer2',       'label' => 'Dashboard',          'href' => 'admin.php?tab=dashboard'],
    'inventory'   => ['icon' => 'bi-boxes',              'label' => 'Inventory Mgmt',     'href' => 'inventory.php'],
    'products'    => ['icon' => 'bi-box-seam',           'label' => 'Products / Key Inventory', 'href' => 'admin.php?tab=products'],
    'orders'      => ['icon' => 'bi-receipt',            'label' => 'Orders',             'href' => 'admin.php?tab=orders'],
    'sales'       => ['icon' => 'bi-graph-up-arrow',     'label' => 'Sales Detail',       'href' => 'admin.php?tab=sales'],
    'leads'       => ['icon' => 'bi-person-lines-fill',  'label' => 'Lead Management',    'href' => 'admin.php?tab=leads'],
    'emails'      => ['icon' => 'bi-envelope',           'label' => 'Email Activity',     'href' => 'admin.php?tab=emails'],
    'reviews'     => ['icon' => 'bi-star',                'label' => 'Customer Reviews',   'href' => 'admin.php?tab=reviews'],
    'templates'   => ['icon' => 'bi-file-earmark-richtext','label'=> 'Email Templates',   'href' => 'admin.php?tab=templates'],
    'api'         => ['icon' => 'bi-plug',               'label' => 'API Management',     'href' => 'admin.php?tab=api'],
    'regions'     => ['icon' => 'bi-globe',              'label' => 'Regions',            'href' => 'admin.php?tab=regions'],
    'settings'    => ['icon' => 'bi-gear',               'label' => 'Settings',           'href' => 'admin.php?tab=settings'],
];
$adminActive = $adminActive ?? '';
$pageTitle   = $pageTitle ?? 'Admin Panel';
$admin       = $admin ?? current_admin();
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= $adminMode === 'dark' ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
[data-bs-theme="dark"] .s-badge.paid, [data-bs-theme="dark"] .s-badge.delivered, [data-bs-theme="dark"] .s-badge.sent {
  background:#065f46; color:#a7f3d0;
}
[data-bs-theme="dark"] .s-badge.failed, [data-bs-theme="dark"] .s-badge.refunded {
  background:#991b1b; color:#fecaca;
}
[data-bs-theme="dark"] .s-badge.queued, [data-bs-theme="dark"] .s-badge.pending {
  background:#92400e; color:#fde68a;
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
/* Ensure content stays inside boxes — alignment + overflow safety */
.card-e { overflow:hidden; }
.card-e .card-body-p { overflow-x:auto; }
.tbl-e { overflow:auto; max-width:100%; }
.tbl-e table { table-layout:auto; }
.tbl-e td, .tbl-e th { vertical-align: middle; word-break: break-word; }

body { background: var(--bg); color: var(--text); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; font-size: 14px; position:relative; }

/* Microsoft-style watermark — very subtle 4-square logo pattern.
   Uses a data:URI SVG repeated as background; opacity baked into the SVG
   via fill colors so content stays fully readable. */
body::before {
  content: "";
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220' viewBox='0 0 220 220'><g opacity='0.05'><rect x='40' y='40' width='28' height='28' fill='%23F25022'/><rect x='72' y='40' width='28' height='28' fill='%2300A4EF'/><rect x='40' y='72' width='28' height='28' fill='%237FBA00'/><rect x='72' y='72' width='28' height='28' fill='%23FFB900'/><text x='108' y='62' font-family='Segoe UI,Arial' font-size='13' font-weight='600' fill='%23999'>Microsoft</text><rect x='150' y='150' width='22' height='22' fill='%23185ABD'/><rect x='150' y='176' width='22' height='22' fill='%23107C41'/><rect x='176' y='150' width='22' height='22' fill='%23D24726'/><rect x='176' y='176' width='22' height='22' fill='%237B83EB'/></g></svg>");
  background-repeat: repeat;
  background-size: 220px 220px;
  background-position: 0 0;
  opacity: 1;
}
/* Dark mode — bump watermark opacity slightly (dark bg absorbs more) */
[data-bs-theme="dark"] body::before { opacity: 0.55; }

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
}
.adm-top .brand-center small { font-size:9px;letter-spacing:1.8px;color:var(--muted);font-weight:600;}
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
}
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
  border:1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.02);
  transition: box-shadow .2s ease, transform .15s ease;
}
.card-e:hover { box-shadow: 0 4px 14px rgba(15,23,42,.06), 0 1px 3px rgba(15,23,42,.04); }
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
}
</style>
</head>
<body>

<header class="adm-top" data-testid="adm-topbar">
  <div class="left">
    <button class="adm-hamburger" data-testid="sidebar-toggle" onclick="document.querySelector('.adm-sidebar').classList.toggle('open')" title="Menu">
      <i class="bi bi-list"></i>
    </button>
  </div>

  <div class="brand-center">
    <span class="m-logo">M</span>
    <div>
      <div><?= esc(defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software') ?></div>
      <small>ADMIN CONTROL PANEL</small>
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
    <a class="adm-iconbtn" href="?theme=<?= $adminMode==='dark'?'light':'dark' ?>" title="Toggle theme" data-testid="theme-toggle">
      <i class="bi <?= $adminMode==='dark'?'bi-sun':'bi-moon-stars' ?>"></i>
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
    <?php foreach (['dashboard','inventory'] as $k): $i = $navItems[$k]; ?>
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
      </a>
    <?php endforeach; ?>
    <div class="side-section">Communication</div>
    <?php foreach (['emails','reviews','templates'] as $k): $i = $navItems[$k]; ?>
      <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
        <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
      </a>
    <?php endforeach; ?>
    <div class="side-section">System</div>
    <?php foreach (['api','regions','settings'] as $k): $i = $navItems[$k]; ?>
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
</script>
