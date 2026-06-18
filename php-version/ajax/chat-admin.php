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
    // Sending implies done typing — clear the beacon immediately.
    $pdo->prepare('UPDATE chat_leads SET typing_admin_at = NULL WHERE id=?')->execute([$leadId]);
    echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    exit;
}

// Typing beacon — admin is composing.  JS pings ~every 2s while
// the textarea has focus + non-empty content.  Customer-side poller
// surfaces this within 1 tick as "● Admin is typing…".
if ($action === 'typing') {
    $leadId = (int)($in['lead_id'] ?? 0);
    if (!$leadId) { echo json_encode(['ok'=>false]); exit; }
    $on = !empty($in['typing']);
    $pdo->prepare('UPDATE chat_leads SET typing_admin_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id=?')
        ->execute([$leadId]);
    echo json_encode(['ok'=>true]); exit;
}

// Presence — bulk online/offline map for the Leads tab so chat-pill
// colours flip green → metallic-gray within a single polling tick
// once the customer leaves / idles for 2 min.  Accepts a list of lead
// IDs; returns only the rows that have changed visible since last
// page-load.  120-sec threshold matches the table's server-side check.
if ($action === 'presence') {
    $ids = array_slice(array_map('intval', $in['lead_ids'] ?? []), 0, 200);
    if (!$ids) { echo json_encode(['ok'=>true,'presence'=>[]]); exit; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, last_seen FROM chat_leads WHERE id IN ($ph)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt as $r) {
        $online = $r['last_seen'] && (time() - strtotime($r['last_seen'])) <= 120;
        $out[] = ['id'=>(int)$r['id'], 'online'=>$online, 'last_seen'=>$r['last_seen']];
    }
    echo json_encode(['ok'=>true, 'presence'=>$out]); exit;
}

if ($action === 'unread') {
    // Leads needing attention — mirrors the sidebar badge: unread customer
    // messages OR brand-new callback/ProAssist leads not yet opened.
    $r = $pdo->query("
        SELECT COUNT(*) FROM chat_leads l
        WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.lead_id=l.id AND m.sender='customer' AND m.read_at IS NULL)
           OR (l.callback_requested=1 AND l.admin_seen_at IS NULL)
    ")->fetchColumn();
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
$lead = $pdo->prepare('SELECT id, name, email, phone, last_seen, typing_customer_at FROM chat_leads WHERE id=?');
$lead->execute([$leadId]); $leadRow = $lead->fetch();
if (!$leadRow) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
$msgs = $pdo->prepare('SELECT id, sender, message, attachment_url, attachment_type, attachment_name, sent_at FROM chat_messages WHERE lead_id=? ORDER BY id ASC LIMIT 200');
$msgs->execute([$leadId]);
// Mark customer messages as read
$pdo->prepare("UPDATE chat_messages SET read_at=NOW() WHERE lead_id=? AND sender='customer' AND read_at IS NULL")->execute([$leadId]);
// Mark the lead as seen by an admin so it drops off the "needs attention"
// sidebar badge (covers new callback/ProAssist leads with no message yet).
$pdo->prepare("UPDATE chat_leads SET admin_seen_at=NOW() WHERE id=? AND admin_seen_at IS NULL")->execute([$leadId]);
// Surface the customer's typing state so the admin chat panel can show
// "● Customer is typing…" within one polling tick.
$customerIsTyping = $leadRow['typing_customer_at']
    && (time() - strtotime($leadRow['typing_customer_at'])) <= 5;
echo json_encode([
    'ok' => true,
    'lead' => [
        'id'             => (int)$leadRow['id'],
        'name'           => $leadRow['name'],
        'email'          => $leadRow['email'],
        'phone'          => $leadRow['phone'],
        'last_seen'      => $leadRow['last_seen'],
        'online'         => _is_online($leadRow['last_seen']),
        'customer_typing'=> $customerIsTyping,
    ],
    'messages' => $msgs->fetchAll(),
]);
