<?php
// ============================================================
// Helper functions
// ============================================================

/** Escape output to prevent XSS */
function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/** Format money */
function money($amount) {
    return CURRENCY_SYMBOL . number_format((float)$amount, 2);
}

/** Generate CSRF token */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Verify CSRF */
function verify_csrf() {
    $t = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

/** Redirect helper */
function redirect($path) {
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

/** Flash messages */
function flash_set($type, $msg) {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function flash_render() {
    if (empty($_SESSION['flash'])) return '';
    $out = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'danger' : 'info');
        $out .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
              . e($f['msg'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    $_SESSION['flash'] = [];
    return $out;
}

/** Current admin */
function current_admin() {
    return $_SESSION['admin'] ?? null;
}

/** Role check */
function admin_has_role($roles) {
    $a = current_admin();
    if (!$a) return false;
    if (is_string($roles)) $roles = [$roles];
    return in_array($a['role'], $roles, true);
}

/** Activity log */
function log_activity($action, $entity = null, $entity_id = null, $details = null) {
    global $pdo;
    $a = current_admin();
    $stmt = $pdo->prepare('INSERT INTO activity_logs (admin_id, admin_name, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $a['id'] ?? null,
        $a['name'] ?? 'system',
        $action,
        $entity,
        $entity_id,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/** Generate unique order number */
function generate_order_number() {
    return 'MVT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/** Generate license key (when no pre-imported keys exist) */
function generate_license_key($prefix = 'MVT') {
    $parts = [];
    for ($i = 0; $i < 4; $i++) $parts[] = strtoupper(bin2hex(random_bytes(2)));
    return $prefix . '-' . implode('-', $parts);
}

/** Inventory counts for a product */
function product_stock($product_id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT
        SUM(status="available") AS available,
        SUM(status="assigned")  AS assigned,
        SUM(status="sold")      AS sold,
        SUM(status="expired")   AS expired,
        COUNT(*)                AS total
        FROM license_keys WHERE product_id = ?');
    $stmt->execute([$product_id]);
    return $stmt->fetch() ?: ['available'=>0,'assigned'=>0,'sold'=>0,'expired'=>0,'total'=>0];
}

/** Assign first available license key for a product to an order */
function assign_license_to_order($product_id, $order_id, $customer_id) {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, license_key FROM license_keys
            WHERE product_id = ? AND status = "available" LIMIT 1 FOR UPDATE');
        $stmt->execute([$product_id]);
        $key = $stmt->fetch();
        if (!$key) { $pdo->rollBack(); return null; }
        $upd = $pdo->prepare('UPDATE license_keys SET status="sold", customer_id=?, order_id=?, assigned_at=NOW() WHERE id=?');
        $upd->execute([$customer_id, $order_id, $key['id']]);
        $pdo->commit();
        return $key;
    } catch (Exception $e) {
        $pdo->rollBack();
        return null;
    }
}

/** Send license key email (uses PHP mail(); swap for PHPMailer in production) */
function send_license_email($order_id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.email AS customer_email
        FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id = ?');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) return false;

    $items = $pdo->prepare('SELECT oi.*, p.description, p.installation_guide, lk.license_key
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        LEFT JOIN license_keys lk ON lk.id = oi.license_key_id
        WHERE oi.order_id = ?');
    $items->execute([$order_id]);
    $rows = $items->fetchAll();

    $html = render_license_email_html($order, $rows);
    $subject = COMPANY_NAME . ' - Your License Key(s) for Order ' . $order['order_number'];

    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=utf-8';
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>';
    $headers[] = 'Reply-To: ' . SUPPORT_EMAIL;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $ok = @mail($order['customer_email'], $subject, $html, implode("\r\n", $headers));
    if ($ok) {
        $pdo->prepare('UPDATE orders SET email_sent = 1 WHERE id = ?')->execute([$order_id]);
    }
    return $ok;
}

/** Render the license email HTML */
function render_license_email_html($order, $items) {
    $logo = BASE_URL . '/' . COMPANY_LOGO_URL;
    $rowsHtml = '';
    foreach ($items as $it) {
        $key = $it['license_key'] ?: '(pending allocation)';
        $rowsHtml .= '
        <div style="border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin:12px 0;background:#fafafa;">
            <h3 style="margin:0 0 6px 0;color:#0f172a;">' . e($it['product_name']) . '</h3>
            <p style="margin:0 0 10px;color:#475569;font-size:14px;">' . nl2br(e($it['description'])) . '</p>
            <div style="background:#0f172a;color:#facc15;font-family:Consolas,monospace;font-size:16px;padding:12px;border-radius:8px;letter-spacing:1px;">
                ' . e($key) . '
            </div>
            <h4 style="margin:14px 0 6px;color:#0f172a;">Installation Guide</h4>
            <pre style="white-space:pre-wrap;font-family:Arial,sans-serif;font-size:13px;color:#334155;margin:0;">' . e($it['installation_guide']) . '</pre>
            <p style="margin:8px 0 0;color:#475569;font-size:13px;">Quantity: ' . (int)$it['quantity']
                . ' &nbsp; | &nbsp; Unit Price: ' . money($it['unit_price'])
                . ' &nbsp; | &nbsp; Line Total: ' . money($it['line_total']) . '</p>
        </div>';
    }

    return '
<!doctype html>
<html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);">
  <tr>
    <td style="background:#0f172a;padding:24px 28px;color:#fff;">
      <table width="100%"><tr>
        <td><img src="' . e($logo) . '" alt="' . e(COMPANY_NAME) . '" height="40" style="display:block;"></td>
        <td align="right" style="color:#cbd5e1;font-size:13px;">Order ' . e($order['order_number']) . '</td>
      </tr></table>
    </td>
  </tr>
  <tr><td style="padding:28px 28px 6px;">
    <h2 style="margin:0 0 6px;color:#0f172a;">Thank you for your purchase, ' . e($order['customer_name']) . '!</h2>
    <p style="margin:0;color:#475569;font-size:14px;">Your payment has been received and your license key(s) are below.</p>
  </td></tr>
  <tr><td style="padding:10px 28px 0;">' . $rowsHtml . '</td></tr>
  <tr><td style="padding:10px 28px;">
    <h3 style="margin:14px 0 8px;color:#0f172a;">Payment & Order Details</h3>
    <table width="100%" style="font-size:14px;color:#334155;border-collapse:collapse;">
      <tr><td style="padding:6px 0;">Order Number</td><td align="right"><strong>' . e($order['order_number']) . '</strong></td></tr>
      <tr><td style="padding:6px 0;">Payment Method</td><td align="right">' . e($order['payment_method']) . '</td></tr>
      <tr><td style="padding:6px 0;">Transaction ID</td><td align="right">' . e($order['transaction_id'] ?: 'N/A') . '</td></tr>
      <tr><td style="padding:6px 0;">Subtotal</td><td align="right">' . money($order['subtotal']) . '</td></tr>
      <tr><td style="padding:6px 0;">Tax</td><td align="right">' . money($order['tax']) . '</td></tr>
      <tr><td style="padding:8px 0;border-top:1px solid #e5e7eb;"><strong>Amount Paid</strong></td><td align="right" style="border-top:1px solid #e5e7eb;"><strong>' . money($order['total']) . ' ' . e($order['currency']) . '</strong></td></tr>
    </table>
    <p style="margin:14px 0 0;font-size:12px;color:#64748b;">
      The charge on your card statement will appear as <strong>' . e(COMPANY_STATEMENT_NAME) . '</strong>.
    </p>
  </td></tr>
  <tr><td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;">
    <p style="margin:0 0 4px;color:#0f172a;font-size:14px;"><strong>Need help?</strong></p>
    <p style="margin:0;color:#475569;font-size:13px;">Contact our support team at
      <a href="mailto:' . e(SUPPORT_EMAIL) . '" style="color:#0ea5e9;">' . e(SUPPORT_EMAIL) . '</a>
      or call ' . e(SUPPORT_PHONE) . '.
    </p>
    <p style="margin:14px 0 0;color:#94a3b8;font-size:12px;">© ' . date('Y') . ' ' . e(COMPANY_NAME) . '. All rights reserved.</p>
  </td></tr>
</table>
</td></tr>
</table>
</body></html>';
}

/** Get current page name from URL */
function active_page($name) {
    $current = basename($_SERVER['SCRIPT_NAME']);
    return strpos($current, $name) === 0 ? 'active' : '';
}
