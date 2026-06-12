<?php
require_once __DIR__ . '/config.php';
if (!empty($_SESSION['admin'])) {
    log_activity('logout', 'admin', $_SESSION['admin']['id']);
}
$_SESSION = [];
session_destroy();
redirect('login.php');
