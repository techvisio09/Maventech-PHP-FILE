<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Categories';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action==='save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name']);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-', $name));
        $desc = trim($_POST['description'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($id) {
            $pdo->prepare('UPDATE categories SET name=?,slug=?,description=?,is_active=? WHERE id=?')
                ->execute([$name,$slug,$desc,$active,$id]);
            log_activity('update','category',$id,$name);
        } else {
            $pdo->prepare('INSERT INTO categories (name,slug,description,is_active) VALUES (?,?,?,?)')
                ->execute([$name,$slug,$desc,$active]);
            log_activity('create','category',(int)$pdo->lastInsertId(),$name);
        }
        flash_set('success','Category saved.');
        redirect('categories.php');
    }
    if ($action==='delete') {
        $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([(int)$_POST['id']]);
        log_activity('delete','category',(int)$_POST['id']);
        flash_set('success','Category deleted.');
        redirect('categories.php');
    }
}

$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) AS product_count
  FROM categories c ORDER BY c.name')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Categories</h4>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Add Category</div>
      <form method="post" class="card-body">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save">
        <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"></textarea></div>
        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="is_active" id="ac" checked><label class="form-check-label" for="ac">Active</label></div>
        <button class="btn btn-accent w-100">Add</button>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">All Categories</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($cats as $c): ?>
              <tr>
                <td class="fw-semibold"><?= e($c['name']) ?></td>
                <td><code><?= e($c['slug']) ?></code></td>
                <td><?= (int)$c['product_count'] ?></td>
                <td><?= $c['is_active']?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Off</span>' ?></td>
                <td>
                  <form method="post" class="d-inline" data-confirm="Delete category?">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
