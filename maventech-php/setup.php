<?php
/**
 * One-time setup script.
 * Run this in your browser ONCE after importing schema.sql to seed
 * the default admin account with a correctly hashed password.
 *
 * IMPORTANT: DELETE this file after running it for security.
 */
require_once __DIR__ . '/config.php';

$email = 'admin@maventech.com';
$pass  = 'Admin@123';
$hash  = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM admins WHERE email = ?');
$stmt->execute([$email]);

if ($stmt->fetch()) {
    $pdo->prepare('UPDATE admins SET password_hash = ?, is_active = 1, role = "super_admin" WHERE email = ?')
        ->execute([$hash, $email]);
    $msg = "Admin password reset for $email";
} else {
    $pdo->prepare('INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, "super_admin")')
        ->execute(['Super Admin', $email, $hash]);
    $msg = "Admin account created: $email";
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Maventech Setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-5" style="max-width:520px;">
<div class="card p-4">
  <h3>Maventech Admin · Setup Complete</h3>
  <div class="alert alert-success mt-3"><?= htmlspecialchars($msg) ?></div>
  <p><strong>Login credentials:</strong></p>
  <ul>
    <li>Email: <code>admin@maventech.com</code></li>
    <li>Password: <code>Admin@123</code></li>
  </ul>
  <div class="alert alert-warning">
    <strong>SECURITY:</strong> Delete <code>setup.php</code> from the server now, and change the default password after logging in (Admin Users page).
  </div>
  <a class="btn btn-primary" href="login.php">Go to Login →</a>
</div>
</div></body></html>
