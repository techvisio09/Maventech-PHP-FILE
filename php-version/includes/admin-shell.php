<?php
// Standalone admin layout (replaces public site header for /admin*, /inventory.php, /order-view.php).
// Provides: centered company name, region switcher, currency selector, dark mode toggle,
// user profile menu, + vertical sidebar navigation.
require_once __DIR__ . '/regions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$adminMode = $_COOKIE['adm_mode'] ?? 'light';
$rg = active_region();
if (!function_exists('current_admin')) {
    function current_admin(): ?array { return function_exists('current_user') ? current_user() : null; }
}
$navItems = [
    'dashboard'   => ['icon' => 'bi-speedometer2',       'label' => 'Dashboard',          'href' => 'admin.php?tab=dashboard'],
    'inventory'   => ['icon' => 'bi-boxes',              'label' => 'Inventory Mgmt',     'href' => 'inventory.php'],
    'products'    => ['icon' => 'bi-box-seam',           'label' => 'Products',           'href' => 'admin.php?tab=products'],
    'orders'      => ['icon' => 'bi-receipt',            'label' => 'Orders',             'href' => 'admin.php?tab=orders'],
    'sales'       => ['icon' => 'bi-graph-up-arrow',     'label' => 'Sales Detail',       'href' => 'admin.php?tab=sales'],
    'leads'       => ['icon' => 'bi-person-lines-fill',  'label' => 'Lead Management',    'href' => 'admin.php?tab=leads'],
    'keys'        => ['icon' => 'bi-key',                'label' => 'Key Inventory',      'href' => 'admin.php?tab=keys'],
    'emails'      => ['icon' => 'bi-envelope',           'label' => 'Email Activity',     'href' => 'admin.php?tab=emails'],
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
  --bg: #0b1220; --card-bg: #111827; --border: #1f2937; --text: #e5e7eb; --muted: #94a3b8;
  --green-soft:#064e3b; --red-soft:#7f1d1d; --amber-soft:#78350f; --gray-soft:#374151; --blue-soft:#1e3a8a;
}

body { background: var(--bg); color: var(--text); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; font-size: 14px; }

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
.card-e { background: var(--card-bg); border:1px solid var(--border); border-radius: 12px; }
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
  .adm-shell { flex-direction:column; }
  .adm-sidebar { width:100%; position:static; }
}
</style>
</head>
<body>

<header class="adm-top" data-testid="adm-topbar">
  <div class="left">
    <div class="adm-dropdown" id="ddRegion">
      <button class="adm-pill" onclick="document.getElementById('ddRegion').classList.toggle('open')">
        <i class="bi bi-globe"></i> <?= esc($rg['code']) ?> · <?= esc($rg['currency']) ?>
        <i class="bi bi-chevron-down"></i>
      </button>
      <div class="adm-dropdown-menu">
        <?php foreach (all_regions() as $r): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['region' => $r['code']])) ?>" data-testid="region-<?= esc($r['code']) ?>">
            <i class="bi bi-flag<?= $r['code']===$rg['code']?'-fill':'' ?>"></i>
            <?= esc($r['name']) ?> <small class="ms-auto text-muted"><?= esc($r['currency']) ?></small>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="adm-dropdown" id="ddCur">
      <button class="adm-pill" onclick="document.getElementById('ddCur').classList.toggle('open')">
        <i class="bi bi-currency-exchange"></i> <?= esc($rg['currency']) ?> (<?= esc($rg['currency_symbol']) ?>)
      </button>
      <div class="adm-dropdown-menu">
        <?php foreach (all_regions() as $r): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['region' => $r['code']])) ?>">
            <?= esc($r['currency_symbol']) ?> <?= esc($r['currency']) ?> <small class="text-muted ms-auto"><?= esc($r['code']) ?></small>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="brand-center">
    <span class="m-logo">M</span>
    <div>
      <div><?= esc(defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software') ?></div>
      <small>ADMIN CONTROL PANEL</small>
    </div>
  </div>

  <div class="right">
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
// Theme toggle via query param → cookie
if (isset($_GET['theme']) && in_array($_GET['theme'], ['dark','light'], true)) {
    setcookie('adm_mode', $_GET['theme'], time()+86400*365, '/');
    $u = strtok($_SERVER['REQUEST_URI'], '?');
    $qs = $_GET; unset($qs['theme']);
    header('Location: ' . $u . ($qs ? '?'.http_build_query($qs) : ''));
    exit;
}
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
    <?php foreach (['products','keys'] as $k): $i = $navItems[$k]; ?>
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
    <?php foreach (['emails','templates'] as $k): $i = $navItems[$k]; ?>
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

  <main class="adm-content">

<script>
document.addEventListener('click', function(e){
  document.querySelectorAll('.adm-dropdown.open').forEach(function(d){
    if (!d.contains(e.target)) d.classList.remove('open');
  });
});
</script>
