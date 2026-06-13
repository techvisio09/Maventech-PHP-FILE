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

if ($action === 'send') {
    $msg = trim($in['message'] ?? '');
    if ($msg === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
        ->execute([$leadId, 'customer', mb_substr($msg, 0, 2000, 'UTF-8')]);
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
echo json_encode(['ok'=>true, 'messages'=>$rows]);
