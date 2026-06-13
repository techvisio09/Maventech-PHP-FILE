<?php
// Customer-side live chat endpoint.
// Used by the public chat widget to (a) post messages to the admin, (b) poll
// for admin replies, and (c) maintain a "last_seen" presence timestamp so
// the admin sees online/offline status.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $in['action'] ?? 'poll';
$pdo = db();

// Resolve the current customer's lead row via the session-bound id or chat_token.
$leadId = (int)($_SESSION['lead_id'] ?? 0);
$token  = trim($in['token'] ?? '');
if (!$leadId && $token !== '') {
    $st = $pdo->prepare('SELECT id FROM chat_leads WHERE chat_token=? LIMIT 1');
    $st->execute([$token]); $leadId = (int)$st->fetchColumn();
    if ($leadId) $_SESSION['lead_id'] = $leadId;
}
if (!$leadId) { echo json_encode(['ok'=>false,'error'=>'No lead']); exit; }

// Heartbeat: update last_seen on every call
$pdo->prepare('UPDATE chat_leads SET last_seen=NOW() WHERE id=?')->execute([$leadId]);

// Typing beacon — customer is composing.  JS pings ~every 2s while
// the textarea has focus + non-empty content; the 5-sec freshness
// check in the response makes the admin's "Customer is typing…"
// indicator disappear quickly once the customer stops.
if ($action === 'typing') {
    $on = !empty($in['typing']);
    $pdo->prepare('UPDATE chat_leads SET typing_customer_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id=?')
        ->execute([$leadId]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'send') {
    $msg = trim($in['message'] ?? '');
    if ($msg === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
        ->execute([$leadId, 'customer', mb_substr($msg, 0, 2000, 'UTF-8')]);
    // Sending a message implies done typing — clear the beacon immediately.
    $pdo->prepare('UPDATE chat_leads SET typing_customer_at = NULL WHERE id=?')->execute([$leadId]);
}

$since = (int)($in['since'] ?? 0);
$st = $pdo->prepare('SELECT id, sender, message, sent_at FROM chat_messages
                     WHERE lead_id=? AND sender=\'admin\' AND id > ? ORDER BY id ASC LIMIT 50');
$st->execute([$leadId, $since]);
$rows = $st->fetchAll();
// Mark fetched admin messages as read by the customer
if ($rows) {
    $ids = array_column($rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $upd = $pdo->prepare("UPDATE chat_messages SET read_at=NOW() WHERE id IN ($ph)");
    $upd->execute($ids);
}

// Surface the admin's typing state to the customer poller so the public
// chat widget can render "● Admin is typing…" within 1 polling tick.
$tStmt = $pdo->prepare('SELECT typing_admin_at FROM chat_leads WHERE id=?');
$tStmt->execute([$leadId]);
$adminTyping = (string)$tStmt->fetchColumn();
$adminIsTyping = $adminTyping && (time() - strtotime($adminTyping)) <= 5;

echo json_encode(['ok'=>true, 'messages'=>$rows, 'admin_typing'=>$adminIsTyping]);
