<?php
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['admin'])) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($pass, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id'    => (int)$admin['id'],
            'name'  => $admin['name'],
            'email' => $admin['email'],
            'role'  => $admin['role'],
        ];
        $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);
        log_activity('login', 'admin', $admin['id'], 'Successful login');
        redirect('index.php');
    } else {
        $error = 'Invalid email or password.';
        log_activity('login_failed', 'admin', null, 'email=' . $email);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="text-center mb-3">
      <div style="width:54px;height:54px;border-radius:14px;background:#0f172a;display:inline-flex;align-items:center;justify-content:center;color:#facc15;font-size:24px;">
        <i class="fa fa-cube"></i>
      </div>
    </div>
    <h1 class="text-center"><?= e(COMPANY_NAME) ?></h1>
    <p class="muted text-center">Sign in to the admin panel</p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Email</label>
        <input type="email" name="email" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn btn-accent w-100"><i class="fa fa-right-to-bracket me-1"></i> Login</button>
    </form>

    <p class="muted text-center mt-4 mb-0 small">
      Default: <code>admin@maventech.com</code> / <code>Admin@123</code>
    </p>
  </div>
</div>
</body>
</html>
