<aside class="sidebar" id="sidebar">
  <ul class="nav flex-column">
    <li class="nav-section">Overview</li>
    <li><a class="nav-link <?= active_page('index.php') ?>" href="<?= BASE_URL ?>/index.php"><i class="fa fa-gauge-high"></i> Dashboard</a></li>
    <li><a class="nav-link <?= active_page('sales.php') ?>" href="<?= BASE_URL ?>/sales.php"><i class="fa fa-chart-line"></i> Sales &amp; Reports</a></li>

    <li class="nav-section">Catalog</li>
    <li><a class="nav-link <?= active_page('products.php') ?>" href="<?= BASE_URL ?>/products.php"><i class="fa fa-box"></i> Products</a></li>
    <li><a class="nav-link <?= active_page('categories.php') ?>" href="<?= BASE_URL ?>/categories.php"><i class="fa fa-tags"></i> Categories</a></li>
    <li><a class="nav-link <?= active_page('licenses.php') ?>" href="<?= BASE_URL ?>/licenses.php"><i class="fa fa-key"></i> License Keys</a></li>
    <li><a class="nav-link <?= active_page('inventory.php') ?>" href="<?= BASE_URL ?>/inventory.php"><i class="fa fa-warehouse"></i> Inventory</a></li>

    <li class="nav-section">Commerce</li>
    <li><a class="nav-link <?= active_page('orders.php') ?>" href="<?= BASE_URL ?>/orders.php"><i class="fa fa-receipt"></i> Orders</a></li>
    <li><a class="nav-link <?= active_page('customers.php') ?>" href="<?= BASE_URL ?>/customers.php"><i class="fa fa-users"></i> Customers</a></li>

    <li class="nav-section">System</li>
    <li><a class="nav-link <?= active_page('admins.php') ?>" href="<?= BASE_URL ?>/admins.php"><i class="fa fa-user-shield"></i> Admin Users</a></li>
    <li><a class="nav-link <?= active_page('activity_logs.php') ?>" href="<?= BASE_URL ?>/activity_logs.php"><i class="fa fa-list-check"></i> Activity Logs</a></li>
  </ul>
</aside>

<main class="content">
  <div class="container-fluid py-4">
    <?= flash_render() ?>
