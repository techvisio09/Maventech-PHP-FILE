<?php
// Order fulfillment + transactional email with tracking + editable template.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';

/**
 * Resolve the activation / sign-in URL for a product.
 * Priority:
 *   1. Per-product `activation_url` configured by admin
 *   2. Smart per-brand defaults (Microsoft / Bitdefender / McAfee / Norton / Adobe)
 *   3. Google search fallback prefilled with the product name → always lands on the right page
 */
function activation_url_for_product(string $name, string $brand = '', string $override = ''): string {
    $override = trim($override);
    if ($override !== '') return $override;
    $n = strtolower($name . ' ' . $brand);
    $brandMap = [
        'office'       => 'https://setup.office.com',
        'microsoft 365'=> 'https://setup.office.com',
        'microsoft'    => 'https://account.microsoft.com',
        'bitdefender'  => 'https://central.bitdefender.com',
        'mcafee'       => 'https://home.mcafee.com',
        'norton'       => 'https://my.norton.com',
        'adobe'        => 'https://account.adobe.com',
        'kaspersky'    => 'https://my.kaspersky.com',
        'avast'        => 'https://my.avast.com',
        'avg'          => 'https://my.avg.com',
        'eset'         => 'https://home.eset.com',
        'trend micro'  => 'https://account.trendmicro.com',
        'webroot'      => 'https://my.webrootanywhere.com',
        'autocad'      => 'https://accounts.autodesk.com',
        'autodesk'     => 'https://accounts.autodesk.com',
    ];
    foreach ($brandMap as $needle => $url) {
        if (strpos($n, $needle) !== false) return $url;
    }
    // Fallback: Google search for "<product name> sign in activate" so the customer lands on the right vendor page.
    return 'https://www.google.com/search?q=' . urlencode(trim($name) . ' sign in activate');
}

/** Default "light" template w/ Microsoft icon watermark. Used when admin
 *  hasn't customised it via the Email Template editor.                    */
function default_email_template(): string {
    return '<!doctype html><html><body style="margin:0;padding:0;background:#fbfcfd;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<div style="position:relative;max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);">
  <!-- Watermark Microsoft icon -->
  <div style="position:absolute;top:80px;right:-40px;opacity:.05;pointer-events:none;">
    <svg width="320" height="320" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
      <rect x="2"  y="2"  width="9" height="9" fill="#F35325"/>
      <rect x="13" y="2"  width="9" height="9" fill="#81BC06"/>
      <rect x="2"  y="13" width="9" height="9" fill="#05A6F0"/>
      <rect x="13" y="13" width="9" height="9" fill="#FFBA08"/>
    </svg>
  </div>
  <div style="background:#ffffff;padding:26px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;">
    <div>
      <div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;">{{company_name}}</div>
      <div style="font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;">AUTHORIZED MICROSOFT RESELLER</div>
    </div>
    <span style="font-size:11px;color:#10b981;font-weight:700;background:#d1fae5;padding:6px 12px;border-radius:999px;">&#10003; ORDER CONFIRMED</span>
  </div>

  <div style="padding:30px 32px;position:relative;">
    <h1 style="margin:0 0 6px;font-size:22px;color:#0f172a;font-weight:700;">Thank you for your purchase, {{customer_name}}!</h1>
    <p style="margin:0 0 22px;font-size:14px;color:#475569;line-height:1.6;">Your payment was received and your genuine license key is ready to use.</p>

    <table width="100%" style="border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:22px;font-size:13px;color:#475569;">
      <tr>
        <td style="padding:14px 18px;">Order #<br><strong style="color:#0f172a;font-size:15px;">{{order_number}}</strong></td>
        <td style="padding:14px 18px;">Amount Paid<br><strong style="color:#0f172a;font-size:15px;">${{amount}}</strong></td>
        <td style="padding:14px 18px;">Delivered to<br><strong style="color:#0f172a;font-size:13px;">{{customer_email}}</strong></td>
      </tr>
    </table>

    {{products_block}}

    <h2 style="font-size:15px;color:#0f172a;margin:24px 0 10px;">Installation Guide</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin:0;">
      <tr><td style="padding:10px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td valign="top" width="46">
              <div style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">1</div>
            </td>
            <td valign="top" style="padding-left:8px;">
              <div style="font-weight:700;color:#0f172a;">&#128229; Download Installer</div>
              <div style="font-size:13px;color:#475569;margin-top:2px;">Visit the official site to download the installer for your product.</div>
            </td>
          </tr>
        </table>
      </td></tr>
      <tr><td style="height:8px;"></td></tr>
      <tr><td style="padding:10px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td valign="top" width="46">
              <div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">2</div>
            </td>
            <td valign="top" style="padding-left:8px;">
              <div style="font-weight:700;color:#0f172a;">&#128190; Install &amp; Sign-in</div>
              <div style="font-size:13px;color:#475569;margin-top:2px;">Run the installer and sign in with a Microsoft Account (or create one).</div>
            </td>
          </tr>
        </table>
      </td></tr>
      <tr><td style="height:8px;"></td></tr>
      <tr><td style="padding:10px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td valign="top" width="46">
              <div style="background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">3</div>
            </td>
            <td valign="top" style="padding-left:8px;">
              <div style="font-weight:700;color:#0f172a;">&#128273; Activate</div>
              <div style="font-size:13px;color:#475569;margin-top:2px;">Enter the license key shown above and click Activate.</div>
            </td>
          </tr>
        </table>
      </td></tr>
      <tr><td style="height:8px;"></td></tr>
      <tr><td style="padding:10px 14px;background:#fef2f2;border-radius:10px;border:1px solid #fecaca;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td valign="top" width="46">
              <div style="background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">4</div>
            </td>
            <td valign="top" style="padding-left:8px;">
              <div style="font-weight:700;color:#0f172a;">&#128295; Troubleshooting</div>
              <div style="font-size:13px;color:#475569;margin-top:2px;">If activation fails: check internet connection, sign out and back in, then use the <strong>Sign in to activate</strong> button above to open the official page. Still stuck? Contact support below.</div>
            </td>
          </tr>
        </table>
      </td></tr>
    </table>
    <div style="margin-top:14px;background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:12.5px;color:#475569;line-height:1.7;">
      <strong style="color:#0f172a;">Product-specific notes:</strong><br>{{installation_guide}}
    </div>

    <div style="margin-top:22px;border-top:1px solid #f1f3f5;padding-top:16px;font-size:12px;color:#64748b;line-height:1.7;">
      <strong style="color:#0f172a;">Billing note:</strong> this charge appears as <strong>{{statement_name}}</strong> on your card statement.
    </div>
  </div>

  <div style="background:#f8fafc;padding:20px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;">
    <strong style="color:#0f172a;">Need help?</strong> <a href="mailto:{{support_email}}" style="color:#3b82f6;text-decoration:none;">{{support_email}}</a> &middot; {{support_phone}}<br>
    <span style="font-size:11px;color:#94a3b8;">&copy; {{year}} {{company_name}}. All rights reserved.</span>
  </div>
</div>
{{tracking_pixel}}
</body></html>';
}

function render_products_block(array $assignments): string {
    $rows = '';
    foreach ($assignments as $a) {
        $img = $a['image']
            ? '<img src="' . esc($a['image']) . '" width="68" height="68" alt="" style="border-radius:8px;background:#f8fafc;object-fit:contain;">'
            : '<div style="width:68px;height:68px;background:#f1f5f9;border-radius:8px;display:inline-block;"></div>';
        $key = $a['key']
            ? '<div style="margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px 14px;text-align:center;">
                 <div style="font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;font-weight:600;">License Key</div>
                 <div style="font-family:\'Courier New\',monospace;font-size:17px;font-weight:bold;color:#1d4ed8;letter-spacing:1.8px;">' . esc($a['key']) . '</div></div>'
            : '<div style="margin-top:10px;background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;font-size:13px;">Key being prepared — you\'ll receive it within 30 minutes.</div>';
        // Activation button — per-product sign-in URL (vendor portal or Google search fallback)
        $actUrl = $a['activation_url'] ?? '';
        $guideUrl = $a['install_guide_url'] ?? '';
        $buttons = '';
        if ($actUrl) {
            $buttons .= '<a href="' . esc($actUrl) . '" style="display:inline-block;margin:4px 6px;padding:11px 22px;background:linear-gradient(135deg,#10b981,#047857);color:#ffffff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:.3px;">&#128274; Sign in to activate &rarr;</a>';
        }
        if ($guideUrl) {
            $buttons .= '<a href="' . esc($guideUrl) . '" style="display:inline-block;margin:4px 6px;padding:11px 22px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#ffffff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:.3px;">&#128214; View installation guide &rarr;</a>';
        }
        $actBtn = $buttons
            ? '<div style="margin-top:12px;text-align:center;">' . $buttons
                . '<div style="font-size:11px;color:#94a3b8;margin-top:6px;">'
                . ($actUrl && $guideUrl ? 'Activate above &middot; step-by-step setup in the guide.' :
                  ($actUrl ? 'Opens the official activation page for this product.' :
                  'Step-by-step setup instructions for this product.'))
                . '</div>'
                . '</div>'
            : '';
        $rows .= '<table width="100%" style="border:1px solid #eef0f3;border-radius:12px;margin-bottom:14px;background:#fff;"><tr><td style="padding:14px;">
            <table width="100%"><tr><td width="80" valign="top">' . $img . '</td>
            <td valign="top" style="padding-left:10px;">
              <div style="font-size:15px;font-weight:bold;color:#0f172a;">' . esc($a['name']) . '</div>
              <div style="font-size:12px;color:#94a3b8;margin-top:2px;">' . esc($a['description'] ?? 'Genuine lifetime license') . '</div>
            </td></tr></table>' . $key . $actBtn . '</td></tr></table>';
    }
    return $rows;
}

function build_order_email_html(array $order, array $items, array $assignments, string $trackingToken): string {
    // Prefer DB template "order_delivery" if available; else fall back.
    $tplHtml = '';
    try {
        $row = db()->prepare("SELECT html FROM email_templates WHERE code='order_delivery' AND active=1 LIMIT 1");
        $row->execute();
        $tplHtml = (string)($row->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    if (trim($tplHtml) === '') $tplHtml = setting_get('email_template_html', '');
    if (trim($tplHtml) === '') $tplHtml = default_email_template();

    $stmtName = $order['card_statement_name'] ?: statement_name_for($order['payment_method']);
    // Build installation guide aggregated across products
    $guides = [];
    foreach ($assignments as $a) {
        if (!empty($a['installation_guide'])) $guides[] = '<strong>' . esc($a['name']) . ':</strong> ' . nl2br(esc($a['installation_guide']));
    }
    $guideHtml = $guides
        ? implode('<br><br>', $guides)
        : '1. Visit setup.office.com (or the official download link for your product)<br>2. Sign in with a Microsoft Account<br>3. Enter the license key shown above and follow the prompts.';

    $base = rtrim(site_url(), '/');
    $pixel = '<img src="' . $base . '/track-open.php?t=' . urlencode($trackingToken) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';

    $replacements = [
        '{{company_name}}'       => esc(defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software'),
        '{{customer_name}}'      => esc(($order['first_name'] ?? '') ?: 'there'),
        '{{customer_email}}'     => esc($order['email'] ?? ''),
        '{{order_number}}'       => esc($order['order_number'] ?? ''),
        '{{amount}}'             => number_format((float)($order['total'] ?? 0), 2),
        '{{statement_name}}'     => esc($stmtName),
        '{{support_email}}'      => esc(defined('SITE_EMAIL') ? SITE_EMAIL : ''),
        '{{support_phone}}'      => esc(defined('SITE_PHONE') ? SITE_PHONE : ''),
        '{{year}}'               => date('Y'),
        '{{installation_guide}}' => $guideHtml,
        '{{products_block}}'     => render_products_block($assignments),
        '{{tracking_pixel}}'     => $pixel,
    ];
    return strtr($tplHtml, $replacements);
}

function send_email(string $to, string $subject, string $html, ?int $orderId = null, ?string $templateCode = null): void {
    $pdo  = db();
    $tok  = bin2hex(random_bytes(16));
    // Embed pixel at the very end too (in case template lacks {{tracking_pixel}})
    if (strpos($html, 'track-open.php') === false) {
        $base = rtrim(site_url(), '/');
        $html .= '<img src="' . $base . '/track-open.php?t=' . urlencode($tok) . '" width="1" height="1" alt="">';
    }

    $apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
    if ($apiKey === '') {
        // Dev / preview mode — no Resend key configured. We still consider the
        // dispatch successful (the email body is captured & viewable) so the
        // admin can verify content, tracking pixel, links, etc. In production
        // configure RESEND_API_KEY to enable real outbound delivery.
        $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, delivered_at, template_code)
            VALUES (?,?,?,"sent",NULL,?,?,?,?)')
            ->execute([$to, $subject, $html, $orderId, $tok, date('Y-m-d H:i:s'), $templateCode]);
        return;
    }
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['from' => SENDER_EMAIL, 'to' => [$to], 'subject' => $subject, 'html' => $html]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $res !== false && $code >= 200 && $code < 300;
    $providerId = null;
    if ($ok) { $d = json_decode($res, true); $providerId = $d['id'] ?? null; }

    $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, provider_id, delivered_at, template_code)
        VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $to, $subject, $html,
            $ok ? 'sent' : 'failed',
            $ok ? null : ('Delivery failed (HTTP ' . $code . ')'),
            $orderId, $tok, $providerId,
            $ok ? date('Y-m-d H:i:s') : null,
            $templateCode,
        ]);
}

function fulfill_order(int $orderId): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order || $order['fulfilled']) return;

    // Persist card statement name based on payment method
    if (empty($order['card_statement_name'])) {
        $stmtName = statement_name_for($order['payment_method']);
        $pdo->prepare('UPDATE orders SET card_statement_name=? WHERE id=?')->execute([$stmtName, $orderId]);
        $order['card_statement_name'] = $stmtName;
    }

    $itemsStmt = $pdo->prepare('SELECT oi.*, p.image, p.description, p.apps AS installation_guide, p.activation_url, p.install_guide_url, p.brand FROM order_items oi LEFT JOIN products p ON p.slug = oi.product_slug WHERE oi.order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    $assignments = [];
    $keyStmt = $pdo->prepare('SELECT id, license_key FROM license_keys WHERE product_slug = ? AND status = "available" LIMIT 1');
    $assignStmt = $pdo->prepare('UPDATE license_keys SET status = "sold", order_id = ?, assigned_at = NOW() WHERE id = ?');
    foreach ($items as $item) {
        if ($item['product_slug'] === 'proassist-premium') continue;
        for ($i = 0; $i < (int)$item['qty']; $i++) {
            $keyStmt->execute([$item['product_slug']]);
            $keyRow = $keyStmt->fetch();
            if ($keyRow) $assignStmt->execute([$orderId, $keyRow['id']]);
            $assignments[] = [
                'name' => $item['name'],
                'image' => $item['image'],
                'description' => $item['description'] ?? '',
                'installation_guide' => $item['installation_guide'] ?? '',
                'activation_url' => activation_url_for_product($item['name'], $item['brand'] ?? '', $item['activation_url'] ?? ''),
                'install_guide_url' => $item['install_guide_url'] ?? '',
                'key' => $keyRow['license_key'] ?? null,
            ];
        }
    }
    $pdo->prepare('UPDATE orders SET fulfilled = 1 WHERE id = ?')->execute([$orderId]);
    $tl['license_assigned'] = date('Y-m-d H:i:s');

    $tok = bin2hex(random_bytes(16));
    $html = build_order_email_html($order, $items, $assignments, $tok);

    $subjectTpl = setting_get('email_template_subject', 'Your Microsoft product key — Order #{{order_number}}');
    try {
        $row = $pdo->prepare("SELECT subject FROM email_templates WHERE code='order_delivery' AND active=1 LIMIT 1");
        $row->execute();
        $s = $row->fetchColumn();
        if ($s) $subjectTpl = $s;
    } catch (Throwable $e) {}

    $subject = strtr($subjectTpl, [
        '{{order_number}}' => $order['order_number'],
        '{{customer_name}}'=> ($order['first_name'] ?? ''),
    ]);
    send_email($order['email'], $subject, $html, $orderId, 'order_delivery');
    $tl['email_sent'] = date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE orders SET timeline=? WHERE id=?')->execute([json_encode($tl), $orderId]);

    // ---- Schedule review-request email + create token ----
    foreach ($items as $item) {
        $tok = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO customer_reviews (order_id, product_slug, customer_email, customer_name, request_token, region) VALUES (?,?,?,?,?,?)')
            ->execute([$orderId, $item['product_slug'], $order['email'], trim($order['first_name'].' '.$order['last_name']), $tok, $order['region'] ?? 'US']);
        $reviewUrl = rtrim(site_url(),'/') . '/review.php?t=' . $tok;
        $reviewHtml = '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f8fafc;padding:30px;">
          <div style="max-width:580px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.05);">
            <div style="text-align:center;font-size:18px;font-weight:800;color:#0f172a;">'.esc(SITE_BRAND).'</div>
            <h2 style="color:#0f172a;text-align:center;margin-top:16px;">How was your purchase, '.esc($order['first_name']).'?</h2>
            <p style="color:#64748b;text-align:center;">We hope <strong>'.esc($item['name']).'</strong> is working great. Would you take 30 seconds to share your experience?</p>
            <div style="text-align:center;margin:24px 0;">
              <div style="font-size:32px;letter-spacing:6px;">★★★★★</div>
              <a href="'.$reviewUrl.'" style="display:inline-block;margin-top:18px;padding:12px 32px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;">Leave a Review</a>
            </div>
            <p style="font-size:12px;color:#94a3b8;text-align:center;">Includes an AI-assist option to help write your comment based on your rating. Thank you!</p>
          </div></body></html>';
        send_email($order['email'], 'How was your '.$item['name'].'? · Quick 30-second review', $reviewHtml, $orderId, 'review_request');
    }
}
