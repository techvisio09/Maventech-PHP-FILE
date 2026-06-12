<?php
/**
 * cron.php — queue worker for shared-hosting cron jobs.
 *
 * Usage on cPanel / Plesk:
 *   * * * * * /usr/bin/curl -s "https://your-domain.com/cron.php?token=YOUR_SECRET" >/dev/null
 *
 * The token is the value of the `cron_token` setting (auto-generated on first
 * access). Hits without a valid token return 403.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

// Generate a cron token once (so the admin can copy it from the SMTP page).
$token = setting_get('cron_token', '');
if ($token === '') {
    $token = bin2hex(random_bytes(20));
    setting_set('cron_token', $token);
}

$given = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals($token, (string)$given)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden — invalid cron token.\n";
    exit;
}

header('Content-Type: text/plain');
$batch = max(1, min(200, (int)($_GET['batch'] ?? 50)));
$start = microtime(true);
$count = smtp_process_queue($batch);
$ms    = (int)((microtime(true) - $start) * 1000);
echo "[" . date('c') . "] cron.php: processed=$count batch=$batch elapsed_ms=$ms\n";
