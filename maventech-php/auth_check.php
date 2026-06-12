<?php
// Session guard - include at the top of every protected page
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    redirect('login.php');
}

// Optional role enforcement: $require_role can be set by the including page
if (isset($require_role) && !admin_has_role($require_role)) {
    http_response_code(403);
    die('Access denied: insufficient privileges.');
}
