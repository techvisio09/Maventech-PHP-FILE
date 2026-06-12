<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Customers';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action==='save') {
        $id = (int)($_POST['id'] ?? 0);
        $f = [trim($_POST['name']), trim($_POST['email']), trim($_POST['phone']),
              trim($_POST['country']), trim($_POST['company']), trim($_POST['notes'])];
        if ($id) {
            $pdo->prepare('UPDATE customers SET name=?,email=?,phone=?,country=?,company=?,notes=? WHERE id=?')
                ->execute(array_merge($f,[$id]));
            log_activity('update','customer',$id,$f[0]);
        } else {
            try {
                $pdo->prepare('INSERT INTO customers (name,email,phone,country,company,notes) VALUES (?,?,?,?,?,?)')->execute($f);
                log_activity('create','customer',(int)$pdo->lastInsertId(),$f[0]);
            } catch (Exception $e) { flash_set('error','Email already exists.'); redirect('customers.php'); }
        }
        flash_set('success','Customer saved.');
        redirect('customers.php');
    }
    if ($action==='delete') {
        $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([(int)$_POST['id']]);
        log_activity('delete','customer',(int)$_POST['id']);
        flash_set('success','Customer deleted.');
        redirect('customers.php');
    }
}

$q = trim($_GET['q'] ?? '');
$args=[]; $wsql='';
if ($q !== '') {
    $wsql = "WHERE c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ?
             OR EXISTS (SELECT 1 FROM orders o WHERE o.customer_id=c.id AND o.order_number LIKE ?)
             OR EXISTS (SELECT 1 FROM order_items oi JOIN orders o2 ON o2.id=oi.order_id
                        WHERE o2.customer_id=c.id AND oi.product_name LIKE ?)";
    $args = array_fill(0,5,"%$q%");
}

$stmt = $pdo->prepare("SELECT c.*,
   (SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id) AS order_count,
   (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id=c.id AND o.payment_status='paid') AS lifetime_value
   FROM customers c $wsql ORDER BY c.created_at DESC LIMIT 300");
$stmt->execute($args);
$customers = $stmt->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Customers</h4>
  <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#cm"><i class="fa fa-plus"></i> New Customer</button>
</div>

<form class="card p-3 mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-10"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by name, email, company, order#, product purchased"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Country</th><th>Orders</th><th>Lifetime</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td class="fw-semibold"><?= e($c['name']) ?><br><small class="text-muted"><?= e($c['company']) ?></small></td>
            <td><?= e($c['email']) ?></td>
            <td><?= e($c['phone']) ?></td>
            <td><?= e($c['country']) ?></td>
            <td><?= (int)$c['order_count'] ?></td>
            <td><?= money($c['lifetime_value']) ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="customer_view.php?id=<?= $c['id'] ?>"><i class="fa fa-eye"></i></a>
              <form method="post" class="d-inline" data-confirm="Delete this customer? Their orders will also be deleted.">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="cm" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<form method="post">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="save">
  <div class="modal-header"><h5 class="modal-title">New Customer</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-3">
    <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
    <div class="col-md-6"><label class="form-label">Country</label><input class="form-control" name="country"></div>
    <div class="col-12"><label class="form-label">Company</label><input class="form-control" name="company"></div>
    <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button class="btn btn-accent">Save</button></div>
</form>
</div></div></div>

<?php include __DIR__ . '/footer.php'; ?>
