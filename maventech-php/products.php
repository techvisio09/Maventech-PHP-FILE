<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Products';

// Handle add / edit / delete / toggle
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action==='save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name']);
        $sku  = trim($_POST['sku']);
        $cat  = (int)($_POST['category_id'] ?? 0) ?: null;
        $desc = trim($_POST['description']);
        $guide= trim($_POST['installation_guide']);
        $price= (float)$_POST['price'];
        $lic  = $_POST['license_type'];
        $thresh = (int)$_POST['low_stock_threshold'];
        $active = isset($_POST['is_active']) ? 1 : 0;

        $imagePath = $_POST['existing_image'] ?? null;
        if (!empty($_FILES['image']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext, $allowed)) {
                $fn = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/uploads/' . $fn);
                $imagePath = 'uploads/' . $fn;
            }
        }

        if ($id > 0) {
            $pdo->prepare('UPDATE products SET category_id=?,name=?,sku=?,description=?,installation_guide=?,price=?,image_path=?,license_type=?,low_stock_threshold=?,is_active=? WHERE id=?')
                ->execute([$cat,$name,$sku,$desc,$guide,$price,$imagePath,$lic,$thresh,$active,$id]);
            log_activity('update','product',$id,$name);
            flash_set('success','Product updated.');
        } else {
            $pdo->prepare('INSERT INTO products (category_id,name,sku,description,installation_guide,price,image_path,license_type,low_stock_threshold,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cat,$name,$sku,$desc,$guide,$price,$imagePath,$lic,$thresh,$active]);
            log_activity('create','product',(int)$pdo->lastInsertId(),$name);
            flash_set('success','Product created.');
        }
        redirect('products.php');
    }

    if ($action==='toggle') {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        log_activity('toggle','product',$id);
        redirect('products.php');
    }

    if ($action==='delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        log_activity('delete','product',$id);
        flash_set('success','Product deleted.');
        redirect('products.php');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT p.*, c.name AS category_name,
   (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_id=p.id AND lk.status="available") AS available,
   (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_id=p.id AND lk.status="sold") AS sold
   FROM products p LEFT JOIN categories c ON c.id=p.category_id
   ORDER BY p.created_at DESC')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Products</h4>
  <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#prodModal" onclick="resetForm()">
    <i class="fa fa-plus me-1"></i> Add Product
  </button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th></th><th>Name</th><th>SKU</th><th>Category</th><th>Price</th><th>License</th>
          <th>Available</th><th>Sold</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td>
              <?php if ($p['image_path']): ?>
                <img src="<?= BASE_URL ?>/<?= e($p['image_path']) ?>" style="width:46px;height:46px;object-fit:cover;border-radius:6px;">
              <?php else: ?>
                <div style="width:46px;height:46px;background:#e5e7eb;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#94a3b8;"><i class="fa fa-box"></i></div>
              <?php endif; ?>
            </td>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td><code><?= e($p['sku']) ?></code></td>
            <td><?= e($p['category_name']) ?: '—' ?></td>
            <td><?= money($p['price']) ?></td>
            <td><small><?= e($p['license_type']) ?></small></td>
            <td><?php $av=(int)$p['available']; ?>
              <span class="badge <?= $av<=$p['low_stock_threshold']?'bg-danger':'bg-success' ?>"><?= $av ?></span>
            </td>
            <td><?= (int)$p['sold'] ?></td>
            <td>
              <?= $p['is_active']
                  ? '<span class="badge bg-success">Active</span>'
                  : '<span class="badge bg-secondary">Disabled</span>' ?>
            </td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$p['id'] ?>#edit"><i class="fa fa-pen"></i></a>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-warning"><i class="fa fa-power-off"></i></button>
              </form>
              <form method="post" class="d-inline" data-confirm="Delete this product? This cannot be undone.">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Add / Edit -->
<div class="modal fade <?= $edit?'show':'' ?>" id="prodModal" style="<?= $edit?'display:block;background:rgba(0,0,0,.5)':'' ?>"  tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <input type="hidden" name="existing_image" value="<?= e($edit['image_path'] ?? '') ?>">
  <div class="modal-header">
    <h5 class="modal-title" id="edit"><?= $edit?'Edit Product':'Add Product' ?></h5>
    <a class="btn-close" href="products.php"></a>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label">SKU</label><input class="form-control" name="sku" required value="<?= e($edit['sku'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label">Price</label><input class="form-control" type="number" step="0.01" name="price" required value="<?= e($edit['price'] ?? '0') ?>"></div>

      <div class="col-md-4"><label class="form-label">Category</label>
        <select class="form-select" name="category_id">
          <option value="">(none)</option>
          <?php foreach($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($edit['category_id']??0)==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label">License Type</label>
        <select class="form-select" name="license_type">
          <?php foreach (['single_use','multi_use','subscription','lifetime'] as $lt): ?>
            <option value="<?= $lt ?>" <?= ($edit['license_type']??'')===$lt?'selected':'' ?>><?= $lt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label">Low-stock threshold</label>
        <input type="number" class="form-control" name="low_stock_threshold" value="<?= (int)($edit['low_stock_threshold'] ?? 10) ?>">
      </div>

      <div class="col-12"><label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea>
      </div>
      <div class="col-12"><label class="form-label">Installation Guide</label>
        <textarea class="form-control" name="installation_guide" rows="4"><?= e($edit['installation_guide'] ?? '') ?></textarea>
      </div>

      <div class="col-md-8"><label class="form-label">Product Image</label>
        <input type="file" class="form-control" name="image" accept="image/*">
        <?php if (!empty($edit['image_path'])): ?>
          <img src="<?= BASE_URL ?>/<?= e($edit['image_path']) ?>" style="height:60px;margin-top:8px;border-radius:6px;">
        <?php endif; ?>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="is_active" id="actChk" <?= (!$edit || $edit['is_active'])?'checked':'' ?>>
          <label class="form-check-label" for="actChk">Active</label>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <a class="btn btn-secondary" href="products.php">Cancel</a>
    <button class="btn btn-accent">Save Product</button>
  </div>
</form>
</div></div></div>

<script>
function resetForm(){
  document.querySelector('#prodModal form').reset();
  document.querySelector('input[name=id]').value = 0;
  document.querySelector('input[name=existing_image]').value = '';
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
