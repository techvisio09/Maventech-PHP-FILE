<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/regions.php';
ensure_admin();
$pageTitle = 'Admin Login | ' . SITE_BRAND;
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
            // Admin → admin dashboard; everyone else → account page.
            $defaultLanding = ($user['role'] === 'admin') ? 'admin.php?tab=dashboard' : 'account.php';
            $dest = (!empty($_GET['next']) || !empty($_POST['next'])) ? ($next ?: $defaultLanding) : $defaultLanding;
            header('Location: ' . $dest);
            exit;
        }
        $_SESSION['login_attempts']++;
        $error = 'Invalid email or password.';
    }
}

// Pull the brand/logo so the heading mirrors the rest of the panel.
$co        = function_exists('company_info') ? company_info() : [];
$brandName = $co['name']  ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
$brandLogo = $co['logo']  ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/assets/images/icons/admin-192.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  /* Clean, focused admin sign-in canvas — PayPal-style centered card.
     No public-site chrome, no newsletter, no footer. */
  :root {
    --ml-bg: #f7f8fa;
    --ml-card: #ffffff;
    --ml-text: #0f172a;
    --ml-muted: #6b7280;
    --ml-border: #d1d5db;
    --ml-input-bg: #f0f3fa;
    --ml-brand: #2563eb;
    --ml-brand-dk: #1d4ed8;
  }
  html, body { height: 100%; }
  body {
    margin: 0;
    background: var(--ml-bg);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: var(--ml-text);
    display: flex; align-items: center; justify-content: center;
    padding: 32px 16px;
  }
  .ml-shell { width: 100%; max-width: 420px; }
  .ml-card {
    background: var(--ml-card);
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .06), 0 10px 24px rgba(15, 23, 42, .04);
    padding: 40px 36px 32px;
  }
  .ml-brand {
    text-align: center;
    margin-bottom: 28px;
  }
  .ml-brand img {
    height: 56px; width: auto; max-width: 200px; object-fit: contain;
  }
  .ml-brand-svg {
    display: flex; justify-content: center; margin-bottom: 10px;
  }
  .ml-brand-svg .brand-mark {
    width: 56px; height: 56px; border-radius: 14px;
    box-shadow: 0 4px 16px rgba(15,23,42,.08);
  }
  .ml-brand-wordmark {
    font-size: 19px; font-weight: 800; letter-spacing: -.3px; color: var(--ml-text);
    line-height: 1.1;
  }
  .ml-brand-wordmark .brand-grad {
    background: linear-gradient(135deg, #06b6d4, #0ea5e9);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .ml-brand-fallback {
    font-size: 28px; font-weight: 800; letter-spacing: -.5px; color: var(--ml-text);
  }
  .ml-brand-fallback .brand-grad {
    background: linear-gradient(135deg, #06b6d4, #0ea5e9);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .ml-title {
    text-align: center;
    font-size: 24px; font-weight: 700; color: var(--ml-text);
    margin: 0 0 28px 0;
    letter-spacing: -.2px;
  }
  .ml-error {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
    border-radius: 10px; padding: 10px 14px; font-size: 13px;
    margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }
  .ml-error .bi { font-size: 16px; }
  .ml-field { margin-bottom: 16px; position: relative; }
  .ml-input {
    width: 100%;
    height: 52px;
    background: var(--ml-input-bg);
    border: 1.5px solid transparent;
    border-radius: 10px;
    padding: 0 16px;
    font-size: 15px; color: var(--ml-text);
    outline: none;
    transition: border-color .15s, background .15s;
    box-sizing: border-box;
  }
  .ml-input::placeholder { color: var(--ml-muted); font-size: 14px; }
  .ml-input:hover { border-color: #c7d2fe; }
  .ml-input:focus { background: #ffffff; border-color: var(--ml-brand); }
  .ml-pass-wrap { position: relative; }
  .ml-pass-wrap .ml-input { padding-right: 54px; }
  .ml-pass-toggle {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    background: transparent; border: 0; color: var(--ml-brand); font-weight: 600;
    font-size: 13px; cursor: pointer; padding: 4px 8px; border-radius: 6px;
  }
  .ml-pass-toggle:hover { background: rgba(37, 99, 235, .08); }
  .ml-forgot {
    text-align: center; margin: 18px 0 22px;
  }
  .ml-forgot a {
    color: var(--ml-brand); font-size: 13px; font-weight: 600;
    text-decoration: none;
  }
  .ml-forgot a:hover { text-decoration: underline; }
  .ml-submit {
    width: 100%; height: 52px;
    background: var(--ml-brand); color: #ffffff;
    border: 0; border-radius: 999px;
    font-size: 15px; font-weight: 700; letter-spacing: .2px;
    cursor: pointer;
    transition: background .15s, transform .1s, box-shadow .15s;
    box-shadow: 0 1px 3px rgba(37, 99, 235, .25);
  }
  .ml-submit:hover { background: var(--ml-brand-dk); box-shadow: 0 4px 14px rgba(37, 99, 235, .35); }
  .ml-submit:active { transform: translateY(1px); }
  .ml-footer {
    margin-top: 22px; text-align: center;
    font-size: 11px; color: var(--ml-muted);
  }
  .ml-footer a { color: var(--ml-muted); text-decoration: none; margin: 0 6px; }
  .ml-footer a:hover { color: var(--ml-text); text-decoration: underline; }

  /* Phones — tighten padding so the card breathes on small screens. */
  @media (max-width: 480px) {
    .ml-card { padding: 28px 22px 24px; border-radius: 12px; }
    .ml-title { font-size: 21px; margin-bottom: 22px; }
    .ml-brand { margin-bottom: 22px; }
  }
</style>
</head>
<body>

<div class="ml-shell">
  <div class="ml-card" data-testid="admin-login-card">
    <div class="ml-brand" data-testid="admin-login-brand">
      <?php
      // Prefer the uploaded logo image when it actually exists and looks like
      // a real image (≥ 200 B — guards against 1×1 placeholder uploads).
      // Otherwise fall back to the SVG brand monogram + wordmark so the page
      // always renders a recognisable identity instead of a blank gap.
      $brandLogoLocal = '';
      if (!empty($brandLogo)) {
          $p = parse_url((string)$brandLogo, PHP_URL_PATH);
          if ($p) {
              $diskPath = __DIR__ . $p;
              if (is_file($diskPath) && filesize($diskPath) >= 200) {
                  $brandLogoLocal = $brandLogo;
              }
          }
      }
      ?>
      <?php if ($brandLogoLocal !== ''): ?>
        <img src="<?= esc($brandLogoLocal) ?>" alt="<?= esc($brandName) ?>">
      <?php else: ?>
        <div class="ml-brand-svg" aria-label="<?= esc($brandName) ?>">
          <?= render_logo(56) ?>
        </div>
        <div class="ml-brand-wordmark">
          <?php
            $bnParts = preg_split('/\s+/', trim($brandName));
            $bnLast  = array_pop($bnParts) ?: '';
            $bnHead  = implode(' ', $bnParts);
          ?>
          <?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <h1 class="ml-title" data-testid="admin-login-title">Admin login</h1>

    <?php if ($error): ?>
      <div class="ml-error" data-testid="login-error">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= esc($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="next" value="<?= esc($next) ?>">

      <div class="ml-field">
        <input
          name="email"
          type="email"
          required
          class="ml-input"
          placeholder="Email address"
          autocomplete="username"
          autofocus
          data-testid="login-email">
      </div>

      <div class="ml-field">
        <div class="ml-pass-wrap">
          <input
            name="password"
            id="login-pass"
            type="password"
            required
            class="ml-input"
            placeholder="Password"
            autocomplete="current-password"
            data-testid="login-password">
          <button
            type="button"
            class="ml-pass-toggle"
            id="passToggleBtn"
            data-testid="login-pass-toggle"
            onclick="(function(){var i=document.getElementById('login-pass');var on=i.type==='password';i.type=on?'text':'password';document.getElementById('passToggleBtn').textContent=on?'Hide':'Show';})()">
            Show
          </button>
        </div>
      </div>

      <div class="ml-forgot">
        <a href="forgot-password.php" data-testid="login-forgot-link">Forgotten password?</a>
      </div>

      <button class="ml-submit" type="submit" data-testid="login-submit">Log In</button>
    </form>

    <div class="ml-footer">
      <a href="/" data-testid="login-back-link"><i class="bi bi-arrow-left"></i> Back to store</a>
      <span>·</span>
      <span>&copy; <?= date('Y') ?> <?= esc($brandName) ?></span>
    </div>
  </div>
</div>

</body>
</html>
