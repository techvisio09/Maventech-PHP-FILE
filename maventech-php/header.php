<?php /** @var string $page_title */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'Dashboard') ?> · <?= e(APP_NAME) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg topbar shadow-sm">
  <div class="container-fluid">
    <button class="btn btn-light d-lg-none me-2" id="sidebarToggle"><i class="fa fa-bars"></i></button>
    <a class="navbar-brand fw-bold text-white" href="<?= BASE_URL ?>/index.php">
      <i class="fa fa-cube me-1"></i> <?= e(COMPANY_NAME) ?>
    </a>
    <div class="ms-auto d-flex align-items-center">
      <span class="text-white-50 small me-3 d-none d-sm-inline">
        <i class="fa fa-user-shield me-1"></i><?= e(current_admin()['name'] ?? '') ?>
        <span class="badge bg-warning text-dark ms-1"><?= e(current_admin()['role'] ?? '') ?></span>
      </span>
      <a class="btn btn-sm btn-outline-light" href="<?= BASE_URL ?>/logout.php">
        <i class="fa fa-sign-out-alt"></i> Logout
      </a>
    </div>
  </div>
</nav>

<div class="layout">
