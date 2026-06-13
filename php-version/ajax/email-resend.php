<?php
/*
 * Email resend AJAX — admin-only.
 *
 *   action=resend  → re-queue an existing email (optionally to a new
 *                    recipient address) and try to deliver immediately.
 *                    Returns JSON describing the outcome so the admin UI
 *                    can update the row in-place AND refresh the failed-
 *                    email bell counter without a full page reload.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: ($_POST ?: $_GET);
$emailId = (int)($in['email_id'] ?? 0);
$newTo   = trim((string)($in['new_recipient'] ?? ''));

if (!$emailId) { echo json_encode(['ok'=>false, 'error'=>'email_id required']); exit; }

$pdo = db();
$row = $pdo->prepare('SELECT * FROM email_outbox WHERE id=?');
$row->execute([$emailId]);
$em = $row->fetch();
if (!$em) { echo json_encode(['ok'=>false, 'error'=>'Email not found']); exit; }

$to = $newTo !== '' ? $newTo : $em['recipient'];
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false, 'error'=>'Invalid email address']); exit;
}

$tok        = bin2hex(random_bytes(16));
$maxRetries = (int)(smtp_config()['max_retries'] ?? 3);

$pdo->prepare("INSERT INTO email_outbox
    (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority)
    VALUES (?,?,?,'queued',?,?,?,?,0,?,NOW(),?)")
    ->execute([
        $to,
        $em['subject'],
        $em['html'],
        'Edit & Resend of email #' . $emailId . ($newTo !== '' ? ' (to ' . $newTo . ')' : ''),
        $em['order_id'],
        $tok,
        $em['template_code'],
        $maxRetries,
        3,
    ]);
$newId = (int)$pdo->lastInsertId();

// Attempt immediate delivery via the SMTP worker.
$delivered = false;
$lastError = '';
try {
    smtp_process_queue(5);
    $check = $pdo->prepare("SELECT status, last_error FROM email_outbox WHERE id=?");
    $check->execute([$newId]);
    $r = $check->fetch();
    $delivered = (($r['status'] ?? '') === 'sent');
    $lastError = (string)($r['last_error'] ?? '');
} catch (Throwable $e) {
    $lastError = $e->getMessage();
}

// If the new send succeeded, also flip the ORIGINAL row to 'sent' so the
// admin's bell counter (which only counts failed/bounced rows) drops by 1
// and the email-activity card flips from red to green.
if ($delivered && ($em['status'] === 'failed' || $em['status'] === 'bounced')) {
    $pdo->prepare("UPDATE email_outbox
                   SET status='sent', last_error=NULL, delivered_at=NOW(),
                       note=CONCAT(IFNULL(note,''), ' · Resolved by Edit&Resend #', ?)
                   WHERE id=?")
        ->execute([$newId, $emailId]);
}

// Fresh failed/bounced counter for the topbar bell.
$failedCount = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status IN ('failed','bounced')")->fetchColumn();

echo json_encode([
    'ok'           => true,
    'delivered'    => $delivered,
    'new_email_id' => $newId,
    'recipient'    => $to,
    'error'        => $delivered ? null : ($lastError ?: 'Queued for retry'),
    'failed_count' => $failedCount,
]);
