<?php
require_once __DIR__ . '/includes/functions.php';
ensure_admin();
$pageTitle = 'Sign In | ' . SITE_BRAND;
$next = preg_replace('/[^a-z0-9.\-]/i', '', $_GET['next'] ?? ($_POST['next'] ?? 'account.php'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0);
    if ($_SESSION['login_attempts'] >= 8) {
        $error = 'Too many failed attempts. Please try again later.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            unset($_SESSION['login_attempts']);
            // If a specific ?next= was supplied, honor it. Otherwise admins land
            // on the admin dashboard, regular customers on their account page.
            $defaultLanding = ($user['role'] === 'admin') ? 'admin.php?tab=dashboard' : 'account.php';
            $dest = (!empty($_GET['next']) || !empty($_POST['next'])) ? ($next ?: $defaultLanding) : $defaultLanding;
            header('Location: ' . $dest);
            exit;
        }
        $_SESSION['login_attempts']++;
        $error = 'Invalid email or password.';
    }
}

include __DIR__ . '/includes/header.php';
?>
<!-- Login background watermark — same animated floating-tech-icons layer as the admin shell -->
<style>
  .login-floats { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
  .login-floats i {
    position: absolute; font-size: 52px; opacity: 0.10;
    filter: drop-shadow(0 2px 4px rgba(15,23,42,.08));
    animation: login-float-drift 16s ease-in-out infinite;
    will-change: transform;
  }
  [data-bs-theme="dark"] .login-floats i { opacity: 0.18; }
  .login-floats i:nth-child(even) { animation-name: login-float-drift-rev; animation-duration: 18s; }
  .login-floats i:nth-child(3n)   { animation-duration: 14s; }
  .login-floats i:nth-child(4n)   { animation-duration: 20s; }
  .login-floats i:nth-child(5n)   { animation-duration: 12s; }
  .login-floats .ic-win    { color: #0078D4; }
  .login-floats .ic-office { color: #D24726; }
  .login-floats .ic-shield { color: #DC2626; }
  .login-floats .ic-cloud  { color: #0EA5E9; }
  .login-floats .ic-key    { color: #F59E0B; }
  .login-floats .ic-cpu    { color: #8B5CF6; }
  .login-floats .ic-mail   { color: #2563EB; }
  .login-floats .ic-card   { color: #10B981; }
  .login-floats .ic-globe  { color: #6366F1; }
  .login-floats .ic-bell   { color: #EAB308; }
  .login-floats .ic-apple  { color: #6b7280; }
  .login-floats .ic-droid  { color: #3DDC84; }
  @keyframes login-float-drift {
    0%   { transform: translate(0, 0)        rotate(0deg)   scale(1); }
    25%  { transform: translate(20vw, -12vh) rotate(45deg)  scale(1.15); }
    50%  { transform: translate(35vw, 18vh)  rotate(-25deg) scale(0.9); }
    75%  { transform: translate(15vw, 30vh)  rotate(60deg)  scale(1.1); }
    100% { transform: translate(0, 0)        rotate(0deg)   scale(1); }
  }
  @keyframes login-float-drift-rev {
    0%   { transform: translate(0, 0)         rotate(0deg)    scale(1); }
    25%  { transform: translate(-18vw, 15vh)  rotate(-60deg)  scale(0.85); }
    50%  { transform: translate(-32vw, -10vh) rotate(40deg)   scale(1.2); }
    75%  { transform: translate(-15vw, -25vh) rotate(-30deg)  scale(1); }
    100% { transform: translate(0, 0)         rotate(0deg)    scale(1); }
  }
  @media (prefers-reduced-motion: reduce) { .login-floats i { animation: none; } }
  @media (max-width: 768px) { .login-floats i { font-size: 36px; opacity: 0.08; } }
  /* Lift the login card above the floating watermark */
  .container.login-shell { position: relative; z-index: 1; }
</style>
<div class="login-floats" aria-hidden="true" data-testid="login-floats">
  <i class="bi bi-windows      ic-win"    style="left:5%;  top:8%;  animation-delay: 0s;"></i>
  <i class="bi bi-microsoft    ic-office" style="left:18%; top:62%; animation-delay: -2s;"></i>
  <i class="bi bi-shield-lock  ic-shield" style="left:32%; top:18%; animation-delay: -4s;"></i>
  <i class="bi bi-key-fill     ic-key"    style="left:46%; top:75%; animation-delay: -6s;"></i>
  <i class="bi bi-cloud-fill   ic-cloud"  style="left:60%; top:30%; animation-delay: -1s;"></i>
  <i class="bi bi-laptop       ic-win"    style="left:74%; top:55%; animation-delay: -3s;"></i>
  <i class="bi bi-fingerprint  ic-shield" style="left:88%; top:12%; animation-delay: -5s;"></i>
  <i class="bi bi-cpu-fill     ic-cpu"    style="left:10%; top:42%; animation-delay: -7s;"></i>
  <i class="bi bi-envelope-paper ic-mail" style="left:28%; top:88%; animation-delay: -8s;"></i>
  <i class="bi bi-bag-check    ic-card"   style="left:52%; top:8%;  animation-delay: -9s;"></i>
  <i class="bi bi-graph-up     ic-cpu"    style="left:68%; top:85%; animation-delay: -10s;"></i>
  <i class="bi bi-globe2       ic-globe"  style="left:82%; top:38%; animation-delay: -11s;"></i>
  <i class="bi bi-credit-card-2-front ic-card" style="left:38%; top:48%; animation-delay: -12s;"></i>
  <i class="bi bi-bell-fill    ic-bell"   style="left:90%; top:72%; animation-delay: -13s;"></i>
  <i class="bi bi-apple        ic-apple"  style="left:2%;  top:78%; animation-delay: -14s;"></i>
  <i class="bi bi-android2     ic-droid"  style="left:42%; top:32%; animation-delay: -15s;"></i>
  <i class="bi bi-shield-check ic-shield" style="left:65%; top:65%; animation-delay: -2.5s;"></i>
  <i class="bi bi-window-stack ic-win"    style="left:22%; top:25%; animation-delay: -4.5s;"></i>
</div>
<div class="container py-5 login-shell" style="max-width: 460px;">
  <div class="card p-4 p-md-5">
    <h1 class="h4 fw-bold mb-1">Welcome back</h1>
    <p class="text-secondary small mb-4">Sign in to view your orders and license keys.</p>
    <?php if ($error): ?><div class="alert alert-danger py-2 small" data-testid="login-error"><?= esc($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="next" value="<?= esc($next) ?>">
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <input name="email" type="email" required class="form-control login-plain" placeholder="your@email.com" data-testid="login-email" autocomplete="username">
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <div class="position-relative">
          <input name="password" type="password" id="login-pass" required class="form-control login-plain" placeholder="••••••••" data-testid="login-password" autocomplete="current-password" style="padding-right:42px;">
          <button type="button" id="pass-eye" class="btn btn-link p-0 text-secondary"
                  onclick="(function(){var i=document.getElementById('login-pass');var on=i.type==='password';i.type=on?'text':'password';document.getElementById('pass-eye-icon').className=on?'bi bi-eye-slash':'bi bi-eye';})()"
                  data-testid="login-pass-toggle"
                  style="position:absolute;top:50%;right:10px;transform:translateY(-50%);text-decoration:none;line-height:1;">
            <i id="pass-eye-icon" class="bi bi-eye" style="font-size:18px;"></i>
          </button>
        </div>
      </div>
      <style>
        /* Plain, neutral login inputs — no theme-tinted backgrounds */
        .login-plain {
          background: #ffffff !important;
          border: 1px solid #d1d5db !important;
          color: #0f172a !important;
          border-radius: 8px !important;
          box-shadow: none !important;
        }
        .login-plain:focus {
          border-color: #2563eb !important;
          box-shadow: 0 0 0 3px rgba(37,99,235,.18) !important;
        }
        #pass-eye:hover { color:#2563eb !important; }
      </style>
      <button class="btn btn-primary w-100 rounded-pill" data-testid="login-submit">Sign In</button>
    </form>
    <p class="small text-secondary text-center mt-4 mb-0">New here? <a href="register.php" class="fw-semibold">Create an account</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
