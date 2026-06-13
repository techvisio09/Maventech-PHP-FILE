<?php
// ProAssist install-call scheduler — customer-facing endpoint.
//
// Resolves the current visitor's chat_lead via session ($_SESSION['lead_id'])
// or the chat_token query/body field.  Returns the lead's ProAssist status
// (is_proassist, scheduled_at), open slots for a chosen date, or books a slot.
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$in     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $in['action'] ?? 'status';
$pdo    = db();

// Resolve the current lead from session_id or chat_token (matches
// chat-customer.php's resolution rules).
$leadId = (int)($_SESSION['lead_id'] ?? 0);
$token  = trim((string)($in['token'] ?? ''));
if (!$leadId && $token !== '') {
    $st = $pdo->prepare('SELECT id FROM chat_leads WHERE chat_token=? LIMIT 1');
    $st->execute([$token]);
    $leadId = (int)$st->fetchColumn();
    if ($leadId) $_SESSION['lead_id'] = $leadId;
}
if (!$leadId) {
    echo json_encode(['ok' => false, 'error' => 'No lead']);
    exit;
}

// Pull lead + most recent ProAssist order context so the chat widget can
// render personalised messaging ("Install call for Order #ORD-…").
$st = $pdo->prepare("SELECT l.id, l.name, l.email, l.phone, l.requested_product
                     FROM chat_leads l WHERE l.id=? LIMIT 1");
$st->execute([$leadId]);
$lead = $st->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
    echo json_encode(['ok' => false, 'error' => 'Lead not found']);
    exit;
}
$isPro = trim((string)($lead['requested_product'] ?? '')) === 'ProAssist Premium Installation';

// Latest ProAssist order for this email (best-effort — we just need the
// order_number for context strings).
$orderNumber = '';
$orderId     = null;
if ($isPro && !empty($lead['email'])) {
    try {
        $st = $pdo->prepare("SELECT o.id, o.order_number FROM orders o
                             WHERE o.email = ? AND o.pro_assist = 1
                             ORDER BY o.id DESC LIMIT 1");
        $st->execute([$lead['email']]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $orderNumber = (string)$row['order_number'];
            $orderId     = (int)$row['id'];
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// Helper — fetch the current schedule row for this lead (if any).
function _pa_schedule_for_lead(PDO $pdo, int $leadId): ?array
{
    $st = $pdo->prepare('SELECT id, scheduled_at, scheduled_utc, tz, status, order_number FROM proassist_schedules WHERE lead_id=? LIMIT 1');
    $st->execute([$leadId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ============================================================
// STATUS — chat widget polls this on open to decide whether to
// render the calendar card or the "you're scheduled" confirmation.
// ============================================================
if ($action === 'status') {
    $sched = _pa_schedule_for_lead($pdo, $leadId);
    echo json_encode([
        'ok'           => true,
        'is_proassist' => $isPro,
        'order_number' => $orderNumber,
        'customer'     => [
            'name'  => $lead['name'],
            'email' => $lead['email'],
            'phone' => $lead['phone'],
        ],
        'schedule'     => $sched ? [
            'id'           => (int)$sched['id'],
            'scheduled_at' => $sched['scheduled_at'],
            'tz'           => $sched['tz'],
            'status'       => $sched['status'],
            'pretty'       => date('l, M j · g:i A', strtotime($sched['scheduled_at'])) . ' EST',
        ] : null,
    ]);
    exit;
}

// ============================================================
// SLOTS — returns the 30-min slot grid for a given date, with
// each slot flagged as taken (already booked) or past (in the past).
// 9:00 AM - 5:30 PM EST, 30-min steps.  Sundays return empty.
// ============================================================
if ($action === 'slots') {
    $date = trim((string)($in['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date']);
        exit;
    }
    $tz = new DateTimeZone('America/New_York');
    try {
        $dayStart = new DateTime($date . ' 09:00:00', $tz);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date']);
        exit;
    }
    // Sundays closed.
    if ((int)$dayStart->format('N') === 7) {
        echo json_encode(['ok' => true, 'slots' => [], 'closed' => true, 'reason' => 'Sundays — office closed']);
        exit;
    }
    // Booked slots for that date (any lead, status != cancelled).
    $booked = [];
    try {
        $b = $pdo->prepare("SELECT scheduled_at FROM proassist_schedules
                            WHERE DATE(scheduled_at) = ? AND status <> 'cancelled' AND lead_id <> ?");
        $b->execute([$date, $leadId]);
        foreach ($b->fetchAll(PDO::FETCH_COLUMN) as $ts) {
            $booked[date('H:i', strtotime($ts))] = true;
        }
    } catch (Throwable $e) { /* non-fatal */ }
    // Build slots 09:00 → 17:30 every 30 min.
    $nowEst   = new DateTime('now', $tz);
    $todayKey = $nowEst->format('Y-m-d');
    $slots = [];
    $cursor = clone $dayStart;
    $end    = (clone $dayStart)->setTime(17, 30);
    while ($cursor <= $end) {
        $hm   = $cursor->format('H:i');
        $lab  = $cursor->format('g:i A');
        $past = ($date === $todayKey && $cursor <= $nowEst);
        $slots[] = [
            'time'    => $hm,
            'label'   => $lab,
            'taken'   => !empty($booked[$hm]),
            'past'    => $past,
        ];
        $cursor->modify('+30 minutes');
    }
    echo json_encode(['ok' => true, 'slots' => $slots, 'closed' => false]);
    exit;
}

// ============================================================
// BOOK — creates or updates the schedule row for this lead.
// Inserts an admin-side chat_messages confirmation so the customer
// sees a "✓ Confirmed for Tue Jun 17 · 2:30 PM EST" bubble immediately.
// ============================================================
if ($action === 'book') {
    if (!$isPro) {
        echo json_encode(['ok' => false, 'error' => 'Not a ProAssist lead']);
        exit;
    }
    $date = trim((string)($in['date'] ?? ''));
    $time = trim((string)($in['time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date or time']);
        exit;
    }
    $tzNY = new DateTimeZone('America/New_York');
    $tzUTC = new DateTimeZone('UTC');
    try {
        $local = new DateTime($date . ' ' . $time . ':00', $tzNY);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date/time']);
        exit;
    }
    // Reject past slots + Sundays + outside 9-17:30 window.
    if ((int)$local->format('N') === 7) {
        echo json_encode(['ok' => false, 'error' => 'Sundays are closed — please pick another day']);
        exit;
    }
    $now = new DateTime('now', $tzNY);
    if ($local <= $now) {
        echo json_encode(['ok' => false, 'error' => 'That slot has already passed — please pick another time']);
        exit;
    }
    $hour = (int)$local->format('H');
    $min  = (int)$local->format('i');
    if ($hour < 9 || ($hour > 17 || ($hour === 17 && $min > 30))) {
        echo json_encode(['ok' => false, 'error' => 'Office hours are 9:00 AM – 6:00 PM EST']);
        exit;
    }
    if (!in_array($min, [0, 30], true)) {
        echo json_encode(['ok' => false, 'error' => 'Slots are in 30-minute increments']);
        exit;
    }
    // Slot collision check (someone else already booked it).
    $localStr = $local->format('Y-m-d H:i:s');
    $coll = $pdo->prepare("SELECT id FROM proassist_schedules
                           WHERE scheduled_at = ? AND status <> 'cancelled' AND lead_id <> ?");
    $coll->execute([$localStr, $leadId]);
    if ($coll->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'That slot was just taken — please choose another time']);
        exit;
    }

    $utc = (clone $local)->setTimezone($tzUTC);
    $utcStr = $utc->format('Y-m-d H:i:s');

    // Upsert: one schedule per lead.  If a row already exists, update it
    // (reschedule), otherwise insert.
    $existing = _pa_schedule_for_lead($pdo, $leadId);
    if ($existing) {
        $up = $pdo->prepare("UPDATE proassist_schedules SET
                              scheduled_at=?, scheduled_utc=?, tz=?, status='pending'
                            WHERE id=?");
        $up->execute([$localStr, $utcStr, 'America/New_York', (int)$existing['id']]);
        $scheduleId = (int)$existing['id'];
        $wasReschedule = true;
    } else {
        $ins = $pdo->prepare("INSERT INTO proassist_schedules
            (lead_id, order_id, order_number, customer_name, customer_email, customer_phone,
             scheduled_at, scheduled_utc, tz, status)
            VALUES (?,?,?,?,?,?,?,?,?,'pending')");
        $ins->execute([
            $leadId,
            $orderId,
            $orderNumber,
            (string)$lead['name'],
            (string)$lead['email'],
            (string)$lead['phone'],
            $localStr, $utcStr, 'America/New_York',
        ]);
        $scheduleId = (int)$pdo->lastInsertId();
        $wasReschedule = false;
    }

    // Append a confirmation message to the chat thread so the customer
    // sees an instant acknowledgement (and the admin gets a record).
    $pretty = $local->format('l, F j') . ' at ' . $local->format('g:i A') . ' EST';
    $confirm = ($wasReschedule
                    ? "✅ Rescheduled — your install call is now booked for "
                    : "✅ Confirmed — your install call is booked for ")
             . $pretty
             . ". A specialist will call you on the number we have on file. "
             . "If you need to reschedule, just let us know here.";
    try {
        $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
            ->execute([$leadId, 'admin', $confirm]);
    } catch (Throwable $e) { /* non-fatal */ }

    echo json_encode([
        'ok'       => true,
        'schedule' => [
            'id'           => $scheduleId,
            'scheduled_at' => $localStr,
            'tz'           => 'America/New_York',
            'status'       => 'pending',
            'pretty'       => $pretty,
        ],
        'rescheduled' => $wasReschedule,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
