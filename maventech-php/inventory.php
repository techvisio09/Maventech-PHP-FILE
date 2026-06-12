<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Inventory';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'update_threshold') {
        $id = (int)$_POST['id']; $t = (int)$_POST['threshold'];
        $pdo->prepare('UPDATE products SET low_stock_threshold=? WHERE id=?')->execute([$t,$id]);
        log_activity('update','product',$id,"threshold=$t");
        flash_set('success','Threshold updated.');
        redirect('inventory.php');
    }
}

$rows = $pdo->query('
  SELECT p.id, p.name, p.sku, p.low_stock_threshold, p.is_active,
    SUM(lk.status="available") AS available,
    SUM(lk.status="assigned")  AS assigned,
    SUM(lk.status="sold")      AS sold,
    SUM(lk.status="expired")   AS expired,
    COUNT(lk.id) AS total
  FROM products p
  LEFT JOIN license_keys lk ON lk.product_id = p.id
  GROUP BY p.id
  ORDER BY (SUM(lk.status="available") - p.low_stock_threshold) ASC
')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>
<h4 class="mb-3">Inventory</h4>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Product</th><th>SKU</th>
        <th>Available</th><th>Assigned</th><th>Sold</th><th>Expired</th><th>Total</th>
        <th>Threshold</th><th>Alert</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $low = (int)$r['available'] <= (int)$r['low_stock_threshold']; ?>
        <tr class="<?= $low?'table-warning':'' ?>">
          <td class="fw-semibold"><?= e($r['name']) ?> <?= !$r['is_active']?'<small class="text-muted">(disabled)</small>':'' ?></td>
          <td><code><?= e($r['sku']) ?></code></td>
          <td><span class="badge <?= $low?'bg-danger':'bg-success' ?>"><?= (int)$r['available'] ?></span></td>
          <td><?= (int)$r['assigned'] ?></td>
          <td><?= (int)$r['sold'] ?></td>
          <td><?= (int)$r['expired'] ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td style="width:130px;">
            <form method="post" class="d-flex gap-1">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="update_threshold">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input class="form-control form-control-sm" type="number" name="threshold" value="<?= (int)$r['low_stock_threshold'] ?>">
              <button class="btn btn-sm btn-primary"><i class="fa fa-save"></i></button>
            </form>
          </td>
          <td>
            <?php if ($low): ?><span class="badge bg-danger"><i class="fa fa-triangle-exclamation"></i> Low stock</span>
            <?php else: ?><span class="badge bg-success"><i class="fa fa-check"></i> OK</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
