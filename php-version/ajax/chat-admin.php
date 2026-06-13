<?php
// Admin-side live chat endpoint.
// - thread:  return the full conversation for a lead + online status
// - send:    post a message from admin to the customer
// - unread:  return total unread customer messages (for sidebar badge + toast)
require_once __DIR__ . '/../includes/functions.php';
require_admin();
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: ($_POST ?: $_GET);
$action = $in['action'] ?? 'thread';
$pdo = db();

function _is_online(?string $lastSeen): bool {
    if (!$lastSeen) return false;
    return (time() - strtotime($lastSeen)) <= 120; // 2-minute window
}

if ($action === 'send') {
    $leadId = (int)($in['lead_id'] ?? 0);
    $msg    = trim($in['message'] ?? '');
    if (!$leadId || $msg === '') { echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }
    $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
        ->execute([$leadId, 'admin', mb_substr($msg, 0, 2000, 'UTF-8')]);
    echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    exit;
}

if ($action === 'unread') {
    $r = $pdo->query("SELECT COUNT(*) FROM chat_messages WHERE sender='customer' AND read_at IS NULL")->fetchColumn();
    // Get last unread message's lead+name for toast
    $latest = $pdo->query("SELECT cm.lead_id, cm.message, cl.name
                            FROM chat_messages cm LEFT JOIN chat_leads cl ON cl.id=cm.lead_id
                            WHERE cm.sender='customer' AND cm.read_at IS NULL
                            ORDER BY cm.id DESC LIMIT 1")->fetch();
    echo json_encode(['ok'=>true, 'unread'=>(int)$r, 'latest'=>$latest ?: null]);
    exit;
}

// thread (default)
$leadId = (int)($in['lead_id'] ?? 0);
if (!$leadId) { echo json_encode(['ok'=>false,'error'=>'lead_id required']); exit; }
$lead = $pdo->prepare('SELECT id, name, email, phone, last_seen FROM chat_leads WHERE id=?');
$lead->execute([$leadId]); $leadRow = $lead->fetch();
if (!$leadRow) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
$msgs = $pdo->prepare('SELECT id, sender, message, sent_at FROM chat_messages WHERE lead_id=? ORDER BY id ASC LIMIT 200');
$msgs->execute([$leadId]);
// Mark customer messages as read
$pdo->prepare("UPDATE chat_messages SET read_at=NOW() WHERE lead_id=? AND sender='customer' AND read_at IS NULL")->execute([$leadId]);
echo json_encode([
    'ok' => true,
    'lead' => [
        'id'        => (int)$leadRow['id'],
        'name'      => $leadRow['name'],
        'email'     => $leadRow['email'],
        'phone'     => $leadRow['phone'],
        'last_seen' => $leadRow['last_seen'],
        'online'    => _is_online($leadRow['last_seen']),
    ],
    'messages' => $msgs->fetchAll(),
]);
