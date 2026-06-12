<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Sales & Reports';

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query('
      SELECT o.order_number, c.name AS customer, c.email,
             o.subtotal, o.tax, o.total, o.currency,
             o.payment_method, o.payment_status, o.transaction_id,
             o.order_status, o.created_at
      FROM orders o JOIN customers c ON c.id=o.customer_id
      ORDER BY o.created_at DESC')->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=sales_report_'.date('Ymd').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['Order#','Customer','Email','Subtotal','Tax','Total','Currency','PaymentMethod','PaymentStatus','TransactionId','Status','Date']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out); exit;
}

// Period filter
$period = $_GET['period'] ?? 'month';
$map = [
    'day'   => "DATE(created_at) = CURDATE()",
    'week'  => "YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)",
    'month' => "YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())",
    'year'  => "YEAR(created_at)=YEAR(CURDATE())",
    'all'   => '1=1',
];
$cond = $map[$period] ?? $map['month'];

$today   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND DATE(created_at)=CURDATE()")->fetchColumn();
$week    = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)")->fetchColumn();
$month   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
$year    = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();

$ordersInPeriod = $pdo->query("
  SELECT o.*, c.name AS customer_name
  FROM orders o JOIN customers c ON c.id=o.customer_id
  WHERE $cond
  ORDER BY o.created_at DESC LIMIT 200")->fetchAll();

// Top products
$top = $pdo->query("
  SELECT p.name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
  FROM order_items oi
  JOIN orders o ON o.id=oi.order_id
  JOIN products p ON p.id=oi.product_id
  WHERE o.payment_status='paid' AND $cond
  GROUP BY p.id ORDER BY revenue DESC LIMIT 5
")->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Sales &amp; Reports</h4>
    <small class="text-muted">Track revenue performance across periods</small>
  </div>
  <div class="d-flex gap-2">
    <div class="btn-group">
      <?php foreach (['day'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year','all'=>'All Time'] as $k=>$v): ?>
        <a class="btn btn-sm <?= $period===$k?'btn-primary':'btn-outline-secondary' ?>" href="?period=<?= $k ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-accent" href="?export=csv"><i class="fa fa-file-csv me-1"></i> Export CSV</a>
  </div>
</div>

<div class="row g-3">
  <?php
  $tiles = [
    ['Today',        $today,  'ok',    'fa-calendar-day'],
    ['This Week',    $week,   'info',  'fa-calendar-week'],
    ['This Month',   $month,  '',      'fa-calendar'],
    ['This Year',    $year,   'warn',  'fa-calendar-check'],
  ];
  foreach ($tiles as $t): ?>
    <div class="col-md-6 col-xl-3">
      <div class="card stat-card <?= $t[2] ?> p-3">
        <div class="d-flex justify-content-between">
          <div>
            <div class="stat-label"><?= $t[0] ?></div>
            <div class="stat-value"><?= money($t[1]) ?></div>
          </div>
          <div class="stat-icon"><i class="fa <?= $t[3] ?>"></i></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header">Orders in selected period (<?= e($period) ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr><th>Order#</th><th>Customer</th><th>Total</th><th>Payment</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php if(empty($ordersInPeriod)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No orders for selected period.</td></tr>
            <?php endif; foreach ($ordersInPeriod as $o): ?>
              <tr>
                <td><a href="<?= BASE_URL ?>/order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td>
                <td><?= e($o['customer_name']) ?></td>
                <td><?= money($o['total']) ?></td>
                <td><span class="badge bg-<?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
                <td><?= e(date('M j, Y H:i', strtotime($o['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header">Top Products</div>
      <ul class="list-group list-group-flush">
        <?php if (empty($top)): ?>
          <li class="list-group-item text-muted text-center">No data</li>
        <?php endif; foreach ($top as $i=>$t): ?>
          <li class="list-group-item d-flex justify-content-between">
            <div>
              <div class="fw-semibold"><?= ($i+1) ?>. <?= e($t['name']) ?></div>
              <small class="text-muted"><?= (int)$t['qty'] ?> units sold</small>
            </div>
            <span class="fw-semibold"><?= money($t['revenue']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
