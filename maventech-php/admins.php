<?php
require_once __DIR__ . '/auth_check.php';
if (!admin_has_role(['super_admin'])) {
    http_response_code(403);
    die('Access denied: only super_admin can manage admin users.');
}
$page_title = 'Admin Users';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action==='save') {
        $id = (int)($_POST['id'] ?? 0);
        $name=trim($_POST['name']); $email=trim($_POST['email']);
        $role=$_POST['role']; $active = isset($_POST['is_active'])?1:0;
        $pass = $_POST['password'] ?? '';

        if ($id) {
            if ($pass !== '') {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE admins SET name=?,email=?,role=?,is_active=?,password_hash=? WHERE id=?')
                    ->execute([$name,$email,$role,$active,$hash,$id]);
            } else {
                $pdo->prepare('UPDATE admins SET name=?,email=?,role=?,is_active=? WHERE id=?')
                    ->execute([$name,$email,$role,$active,$id]);
            }
            log_activity('update','admin',$id,$email);
        } else {
            if ($pass==='') { flash_set('error','Password required'); redirect('admins.php'); }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            try {
                $pdo->prepare('INSERT INTO admins (name,email,password_hash,role,is_active) VALUES (?,?,?,?,?)')
                    ->execute([$name,$email,$hash,$role,$active]);
                log_activity('create','admin',(int)$pdo->lastInsertId(),$email);
            } catch (Exception $e) { flash_set('error','Email already exists.'); redirect('admins.php'); }
        }
        flash_set('success','Admin saved.');
        redirect('admins.php');
    }

    if ($action==='delete') {
        $id=(int)$_POST['id'];
        if ($id !== (int)current_admin()['id']) {
            $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$id]);
            log_activity('delete','admin',$id);
            flash_set('success','Admin deleted.');
        }
        redirect('admins.php');
    }
}

$admins = $pdo->query('SELECT * FROM admins ORDER BY created_at DESC')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Admin Users (RBAC)</h4>
  <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#am"><i class="fa fa-plus"></i> Add Admin</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td class="fw-semibold"><?= e($a['name']) ?></td>
            <td><?= e($a['email']) ?></td>
            <td><span class="badge bg-secondary"><?= e($a['role']) ?></span></td>
            <td><?= $a['is_active']?'<span class="badge bg-success">Active</span>':'<span class="badge bg-danger">Disabled</span>' ?></td>
            <td><?= $a['last_login_at']?e(date('M j, Y H:i',strtotime($a['last_login_at']))):'—' ?></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#am" onclick='loadEdit(<?= json_encode($a) ?>)'><i class="fa fa-pen"></i></button>
              <?php if ((int)$a['id'] !== (int)current_admin()['id']): ?>
                <form method="post" class="d-inline" data-confirm="Delete this admin?">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $a['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="am" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<form method="post" id="adminForm">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="0">
  <div class="modal-header"><h5 class="modal-title">Admin User</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
  <div class="modal-body row g-3">
    <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div>
    <div class="col-md-6"><label class="form-label">Role</label>
      <select class="form-select" name="role">
        <option value="super_admin">Super Admin</option>
        <option value="admin" selected>Admin</option>
        <option value="manager">Manager</option>
      </select>
    </div>
    <div class="col-md-6"><label class="form-label">Password <small class="text-muted">(blank = keep)</small></label><input class="form-control" type="password" name="password"></div>
    <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="is_active" id="ax" checked><label class="form-check-label" for="ax">Active</label></div>
  </div>
  <div class="modal-footer"><button class="btn btn-accent">Save</button></div>
</form>
</div></div></div>

<script>
function loadEdit(a){
  const f = document.getElementById('adminForm');
  f.id.value = a.id;
  f.name.value = a.name;
  f.email.value = a.email;
  f.role.value = a.role;
  f.password.value = '';
  f.is_active.checked = a.is_active == 1;
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
