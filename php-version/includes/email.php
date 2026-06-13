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

/* Default review-request template used when admin hasn't customised it. */
function default_review_template(): string {
    // 5 clickable golden stars — each pre-fills the rating on review.php?t=...&rating=N
    // NOTE: {{review_url}} already contains ?t=<token>, so the next param ALWAYS
    // uses '&' as the separator.  The earlier `strpos` check ran on the literal
    // placeholder string, returning '?' and producing URLs like ?t=X?rating=Y
    // which the review page treated as an invalid token.
    $starsHtml = '';
    for ($i = 1; $i <= 5; $i++) {
        $starsHtml .= '<a href="{{review_url}}&rating=' . $i . '" '
                    . 'style="text-decoration:none;display:inline-block;margin:0 4px;font-size:42px;line-height:1;color:#f59e0b;text-shadow:0 2px 6px rgba(245,158,11,0.35);" '
                    . 'title="Rate ' . $i . ' star' . ($i>1?'s':'') . '">&#9733;</a>';
    }
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<div style="max-width:620px;margin:0 auto;padding:30px 16px;">
  <div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);">
    <!-- Brand header -->
    <div style="background:linear-gradient(135deg,#0ea5e9,#2563eb);padding:28px 32px;text-align:center;color:#fff;">
      <div style="display:inline-block;background:rgba(255,255,255,.18);border-radius:14px;padding:8px 14px;font-weight:800;font-size:22px;letter-spacing:.3px;">
        <span style="display:inline-block;width:30px;height:30px;background:#fff;color:#2563eb;border-radius:8px;text-align:center;line-height:30px;font-weight:900;margin-right:8px;vertical-align:-8px;">M</span>{{company_name}}
      </div>
      <div style="font-size:11px;letter-spacing:1.8px;font-weight:600;margin-top:8px;opacity:.95;">AUTHORIZED MICROSOFT RESELLER</div>
    </div>

    <div style="padding:32px;">
      <h1 style="margin:0 0 8px;font-size:24px;color:#0f172a;font-weight:700;text-align:center;">How did we do, {{customer_name}}?</h1>
      <p style="margin:0 0 4px;color:#475569;text-align:center;font-size:14px;line-height:1.6;">We hope you&rsquo;re loving <strong style="color:#0f172a;">{{product_name}}</strong>.<br>Tap a star below &mdash; one click sends us your rating.</p>

      <div style="text-align:center;margin:24px 0 6px;">
        ' . $starsHtml . '
      </div>
      <p style="text-align:center;font-size:12px;color:#94a3b8;margin:0 0 22px;">
        <strong style="color:#f59e0b;">1</strong> = needs work &nbsp;&middot;&nbsp; <strong style="color:#f59e0b;">5</strong> = excellent
      </p>

      <!-- AI-assist card -->
      <div style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #c7d2fe;border-radius:14px;padding:18px;margin:0 0 20px;">
        <div style="font-weight:700;color:#3730a3;font-size:14px;margin-bottom:4px;">&#10024; Need help finding the words?</div>
        <div style="font-size:13px;color:#475569;line-height:1.6;">After you pick a star rating, our <strong>AI assistant</strong> can draft a thoughtful comment for you in one click &mdash; or you can write it manually. Either way, your feedback helps thousands of other customers.</div>
      </div>

      <div style="text-align:center;margin:0 0 22px;">
        <a href="{{review_url}}" style="display:inline-block;padding:13px 34px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;box-shadow:0 6px 18px rgba(59,130,246,.35);">Write a full review &rarr;</a>
      </div>

      <div style="text-align:center;border-top:1px solid #f1f3f5;padding-top:18px;margin-top:14px;">
        <div style="font-size:13px;color:#0f172a;font-weight:600;">Thanks for your valuable feedback!</div>
        <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Your review helps us keep prices low and service genuine.</div>
      </div>
    </div>

    <div style="background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;">
      <strong style="color:#0f172a;">Need help?</strong> <a href="mailto:{{support_email}}" style="color:#3b82f6;text-decoration:none;">{{support_email}}</a> &middot; {{support_phone}}<br>
      <span style="font-size:11px;color:#94a3b8;">&copy; {{year}} {{company_name}}. All rights reserved.</span>
    </div>
  </div>
</div>{{tracking_pixel}}</body></html>';
}

/* Default Lead Follow-up template — sent to a prospective customer who showed interest. */
function default_lead_followup_template(): string {
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<div style="max-width:620px;margin:0 auto;padding:30px 16px;">
  <div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);">
    <!-- Brand header -->
    <div style="background:#fff;padding:24px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;">
          <span style="display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-radius:7px;text-align:center;line-height:28px;font-weight:900;margin-right:8px;vertical-align:-6px;">M</span>{{company_name}}
        </div>
        <div style="font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;margin-top:2px;">AUTHORIZED MICROSOFT RESELLER</div>
      </div>
      <span style="font-size:11px;color:#2563eb;font-weight:700;background:#dbeafe;padding:6px 12px;border-radius:999px;">&#128075; CHECKING IN</span>
    </div>

    <div style="padding:30px 32px;">
      <h1 style="margin:0 0 8px;font-size:22px;color:#0f172a;font-weight:700;">Hi {{customer_name}}, still thinking it over?</h1>
      <p style="margin:0 0 18px;font-size:14px;color:#475569;line-height:1.65;">
        We noticed you were browsing genuine Microsoft license keys on our store but didn&rsquo;t finish checking out. No worries &mdash; we&rsquo;re saving your cart for you, and we wanted to make sure you have everything you need to decide.
      </p>

      <!-- Why buy from us -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
        <tr>
          <td style="padding:14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;width:33%;text-align:center;">
            <div style="font-size:22px;">&#10003;</div>
            <div style="font-weight:700;color:#065f46;font-size:13px;margin-top:4px;">100% Genuine</div>
            <div style="font-size:11.5px;color:#475569;margin-top:2px;">Direct from authorized channels</div>
          </td>
          <td style="width:8px;"></td>
          <td style="padding:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;width:33%;text-align:center;">
            <div style="font-size:22px;">&#9889;</div>
            <div style="font-weight:700;color:#1e40af;font-size:13px;margin-top:4px;">Instant Delivery</div>
            <div style="font-size:11.5px;color:#475569;margin-top:2px;">Email within 15&ndash;30 minutes</div>
          </td>
          <td style="width:8px;"></td>
          <td style="padding:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;width:33%;text-align:center;">
            <div style="font-size:22px;">&#127942;</div>
            <div style="font-weight:700;color:#9a3412;font-size:13px;margin-top:4px;">Lifetime License</div>
            <div style="font-size:11.5px;color:#475569;margin-top:2px;">One purchase, no subscription</div>
          </td>
        </tr>
      </table>

      <!-- Exclusive discount -->
      <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px dashed #f59e0b;border-radius:14px;padding:18px;text-align:center;margin:0 0 24px;">
        <div style="font-size:12px;color:#92400e;letter-spacing:1.5px;font-weight:700;">EXCLUSIVE OFFER &middot; JUST FOR YOU</div>
        <div style="font-size:26px;font-weight:800;color:#0f172a;margin:6px 0;">10% OFF your order</div>
        <div style="font-size:13px;color:#78350f;">Use code <code style="background:#fff;padding:3px 10px;border-radius:6px;font-weight:700;letter-spacing:1px;">WELCOME10</code> at checkout</div>
      </div>

      <div style="text-align:center;margin:0 0 20px;">
        <a href="' . htmlspecialchars($siteUrl) . '/shop.php" style="display:inline-block;padding:13px 34px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;box-shadow:0 6px 18px rgba(59,130,246,.35);">Continue Shopping &rarr;</a>
      </div>

      <!-- Questions / chat -->
      <div style="background:#f8fafc;border-radius:12px;padding:16px;border:1px solid #e2e8f0;font-size:13px;color:#475569;line-height:1.7;">
        <strong style="color:#0f172a;">Questions before you buy?</strong> Reply to this email, call us, or chat with our <strong>AI assistant</strong> on the site &mdash; we&rsquo;re here Mon&ndash;Sat to help you pick the right product.
      </div>
    </div>

    <div style="background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;">
      <strong style="color:#0f172a;">Talk to a human:</strong> <a href="mailto:{{support_email}}" style="color:#3b82f6;text-decoration:none;">{{support_email}}</a> &middot; {{support_phone}}<br>
      <span style="font-size:11px;color:#94a3b8;">&copy; {{year}} {{company_name}}. All rights reserved.</span>
    </div>
  </div>
</div>{{tracking_pixel}}</body></html>';
}

/* Default Order Pending Payment template — payment not yet received. */
function default_order_pending_template(): string {
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    return '<!doctype html><html><body style="margin:0;padding:0;background:#fffbeb;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<div style="max-width:640px;margin:0 auto;padding:30px 16px;">
  <div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);">
    <!-- Brand header -->
    <div style="background:#fff;padding:24px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:20px;font-weight:800;color:#0f172a;">
          <span style="display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-radius:7px;text-align:center;line-height:28px;font-weight:900;margin-right:8px;vertical-align:-6px;">M</span>{{company_name}}
        </div>
        <div style="font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;margin-top:2px;">AUTHORIZED MICROSOFT RESELLER</div>
      </div>
      <span style="font-size:11px;color:#92400e;font-weight:700;background:#fef3c7;padding:6px 12px;border-radius:999px;">&#9203; PAYMENT PENDING</span>
    </div>

    <div style="padding:30px 32px;">
      <h1 style="margin:0 0 6px;font-size:22px;color:#0f172a;font-weight:700;">Almost there, {{customer_name}}!</h1>
      <p style="margin:0 0 18px;font-size:14px;color:#475569;line-height:1.6;">
        Your order has been placed but we haven&rsquo;t received your payment yet. Once it&rsquo;s confirmed, we&rsquo;ll email you the license key + step-by-step install guide instantly.
      </p>

      <!-- Order summary -->
      <table width="100%" style="border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:20px;font-size:13px;color:#475569;">
        <tr>
          <td style="padding:14px 18px;">Order #<br><strong style="color:#0f172a;font-size:15px;">{{order_number}}</strong></td>
          <td style="padding:14px 18px;">Amount Due<br><strong style="color:#0f172a;font-size:15px;">${{amount}}</strong></td>
          <td style="padding:14px 18px;">Account<br><strong style="color:#0f172a;font-size:13px;">{{customer_email}}</strong></td>
        </tr>
      </table>

      <!-- Statement / merchant name notice -->
      <div style="border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:16px;margin:0 0 20px;">
        <div style="font-weight:700;color:#1e40af;font-size:14px;margin-bottom:6px;">&#128179; Look for this on your statement</div>
        <p style="margin:0;font-size:13px;color:#1e3a8a;line-height:1.6;">
          When the charge goes through, it will appear as
          <strong style="font-family:\'SF Mono\',Menlo,monospace;background:#fff;padding:2px 8px;border-radius:6px;letter-spacing:1px;color:#1d4ed8;">{{statement_name}}</strong>
          on your card or bank statement. There&rsquo;s no need to do anything else &mdash; we&rsquo;ll send delivery as soon as it clears.
        </p>
      </div>

      <!-- What happens next -->
      <h2 style="font-size:15px;color:#0f172a;margin:24px 0 10px;">What happens next?</h2>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr><td style="padding:10px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;">
          <table width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" width="46"><div style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">1</div></td>
            <td valign="top" style="padding-left:8px;"><div style="font-weight:700;color:#0f172a;">Payment confirmation</div><div style="font-size:13px;color:#475569;margin-top:2px;">We&rsquo;ll verify the transaction (usually within minutes for cards &middot; up to 1 hour for PayPal).</div></td>
          </tr></table>
        </td></tr>
        <tr><td style="height:8px;"></td></tr>
        <tr><td style="padding:10px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;">
          <table width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" width="46"><div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">2</div></td>
            <td valign="top" style="padding-left:8px;"><div style="font-weight:700;color:#0f172a;">License key delivery</div><div style="font-size:13px;color:#475569;margin-top:2px;">You&rsquo;ll get a second email with the genuine key, official download link and full activation guide.</div></td>
          </tr></table>
        </td></tr>
        <tr><td style="height:8px;"></td></tr>
        <tr><td style="padding:10px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;">
          <table width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" width="46"><div style="background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">3</div></td>
            <td valign="top" style="padding-left:8px;"><div style="font-weight:700;color:#0f172a;">Install &amp; activate</div><div style="font-size:13px;color:#475569;margin-top:2px;">Run the installer, sign in with a Microsoft Account and enter the key &mdash; activation is instant.</div></td>
          </tr></table>
        </td></tr>
      </table>

      <!-- Support + AI chat -->
      <div style="margin-top:24px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #c7d2fe;border-radius:14px;padding:18px;">
        <div style="font-weight:700;color:#5b21b6;font-size:14px;margin-bottom:6px;">&#129302; Need help right now?</div>
        <p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6;">Our <strong>AI chat assistant</strong> is online 24/7 to answer questions about your order, activation or compatibility &mdash; right inside our website.</p>
        <a href="' . htmlspecialchars($siteUrl) . '/?openchat=1" style="display:inline-block;padding:10px 22px;background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;">&#128172; Open Live Chat</a>
        <a href="mailto:{{support_email}}" style="display:inline-block;padding:10px 22px;border:1px solid #c7d2fe;color:#5b21b6;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;margin-left:6px;">&#9993; Email Support</a>
      </div>

      <p style="font-size:12px;color:#64748b;margin-top:20px;">
        Already paid? Please ignore this email &mdash; you&rsquo;ll receive your license key as soon as your payment is verified.
      </p>
    </div>

    <div style="background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;">
      <strong style="color:#0f172a;">Need help?</strong> <a href="mailto:{{support_email}}" style="color:#3b82f6;text-decoration:none;">{{support_email}}</a> &middot; {{support_phone}}<br>
      <span style="font-size:11px;color:#94a3b8;">&copy; {{year}} {{company_name}}. All rights reserved.</span>
    </div>
  </div>
</div>{{tracking_pixel}}</body></html>';
}

/* Default Refund Confirmation template. */
function default_refund_template(): string {
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<div style="max-width:620px;margin:0 auto;padding:30px 16px;">
  <div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);">
    <!-- Brand header -->
    <div style="background:#fff;padding:24px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:20px;font-weight:800;color:#0f172a;">
          <span style="display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-radius:7px;text-align:center;line-height:28px;font-weight:900;margin-right:8px;vertical-align:-6px;">M</span>{{company_name}}
        </div>
        <div style="font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;margin-top:2px;">AUTHORIZED MICROSOFT RESELLER</div>
      </div>
      <span style="font-size:11px;color:#7e22ce;font-weight:700;background:#f3e8ff;padding:6px 12px;border-radius:999px;">&#128179; REFUND INITIATED</span>
    </div>

    <div style="padding:30px 32px;">
      <h1 style="margin:0 0 8px;font-size:22px;color:#0f172a;font-weight:700;">Your refund is on its way, {{customer_name}}</h1>
      <p style="margin:0 0 18px;font-size:14px;color:#475569;line-height:1.65;">
        We&rsquo;ve initiated the refund for your order. The amount will be credited back to the <strong>same bank account / card</strong> you used at checkout. Most banks process this within <strong>3&ndash;5 business working days</strong>, though some may take a little longer depending on their settlement schedule.
      </p>

      <!-- Refund summary -->
      <table width="100%" style="border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:22px;font-size:13px;color:#475569;">
        <tr>
          <td style="padding:14px 18px;">Order #<br><strong style="color:#0f172a;font-size:15px;">{{order_number}}</strong></td>
          <td style="padding:14px 18px;">Refund Amount<br><strong style="color:#059669;font-size:15px;">${{amount}}</strong></td>
          <td style="padding:14px 18px;">Initiated<br><strong style="color:#0f172a;font-size:13px;">Today</strong></td>
        </tr>
      </table>

      <!-- Timeline -->
      <h2 style="font-size:15px;color:#0f172a;margin:0 0 10px;">When will I see the money?</h2>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr><td style="padding:12px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;">
          <table width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" width="46"><div style="background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">&#10003;</div></td>
            <td valign="top" style="padding-left:8px;"><div style="font-weight:700;color:#0f172a;">Refund initiated today</div><div style="font-size:13px;color:#475569;margin-top:2px;">We&rsquo;ve pushed the reversal to our payment processor.</div></td>
          </tr></table>
        </td></tr>
        <tr><td style="height:8px;"></td></tr>
        <tr><td style="padding:12px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;">
          <table width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" width="46"><div style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">&#9201;</div></td>
            <td valign="top" style="padding-left:8px;"><div style="font-weight:700;color:#0f172a;">3&ndash;5 business working days</div><div style="font-size:13px;color:#475569;margin-top:2px;">The amount will appear in your authorized bank account / card statement.</div></td>
          </tr></table>
        </td></tr>
        <tr><td style="height:8px;"></td></tr>
        <tr><td style="padding:12px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;">
          <table width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" width="46"><div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;">&#9888;</div></td>
            <td valign="top" style="padding-left:8px;"><div style="font-weight:700;color:#0f172a;">Don&rsquo;t see it after 5 business days?</div><div style="font-size:13px;color:#475569;margin-top:2px;">Reach out and we&rsquo;ll share the bank reference / ARN so your bank can locate it.</div></td>
          </tr></table>
        </td></tr>
      </table>

      <!-- Apology box -->
      <div style="margin-top:22px;background:linear-gradient(135deg,#fef3c7,#fff7ed);border:1px solid #fed7aa;border-radius:14px;padding:18px;">
        <div style="font-weight:700;color:#92400e;font-size:14px;margin-bottom:6px;">We&rsquo;re truly sorry for the inconvenience.</div>
        <p style="margin:0;font-size:13px;color:#78350f;line-height:1.65;">
          Whatever made the experience fall short of your expectations, we&rsquo;d love to hear about it. A quick reply with what went wrong helps us do better for the next customer &mdash; and we&rsquo;d be grateful if you gave us another chance in the future.
        </p>
      </div>
    </div>

    <div style="background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;">
      <strong style="color:#0f172a;">Questions about your refund?</strong> <a href="mailto:{{support_email}}" style="color:#3b82f6;text-decoration:none;">{{support_email}}</a> &middot; {{support_phone}}<br>
      <span style="font-size:11px;color:#94a3b8;">Reference order <strong>{{order_number}}</strong> in your reply &middot; &copy; {{year}} {{company_name}}.</span>
    </div>
  </div>
</div>{{tracking_pixel}}</body></html>';
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

/**
 * Render a DB-stored email template (by `code`) with the standard
 * substitutions. Returns null when the template is missing/inactive.
 * Centralises the Company-Info pipeline so that updating the
 * Dashboard → Company Info card propagates to every transactional email.
 */
function render_template(string $code, array $vars = []): ?string {
    try {
        $row = db()->prepare("SELECT html FROM email_templates WHERE code=? AND active=1 LIMIT 1");
        $row->execute([$code]);
        $html = (string)($row->fetchColumn() ?: '');
    } catch (Throwable $e) { return null; }
    if (trim($html) === '') return null;

    $co = company_info();
    $logoHtml = $co['logo']
        ? '<img src="' . esc($co['logo']) . '" alt="' . esc($co['name']) . ' logo" style="max-height:48px;max-width:200px;display:inline-block;vertical-align:middle;">'
        : '';

    $base = [
        '{{company_name}}'    => esc($co['name']),
        '{{company_logo}}'    => $logoHtml,
        '{{company_address}}' => nl2br(esc($co['address'])),
        '{{support_email}}'   => esc($co['email']),
        '{{support_phone}}'   => esc($co['phone']),
        '{{year}}'            => date('Y'),
        '{{tracking_pixel}}'  => '',
    ];
    foreach ($vars as $k => $v) { $base['{{' . $k . '}}'] = $v; }
    return strtr($html, $base);
}

function render_template_subject(string $code, array $vars = []): ?string {
    try {
        $row = db()->prepare("SELECT subject FROM email_templates WHERE code=? AND active=1 LIMIT 1");
        $row->execute([$code]);
        $s = (string)($row->fetchColumn() ?: '');
    } catch (Throwable $e) { return null; }
    if ($s === '') return null;
    $co = company_info();
    $base = [
        '{{company_name}}'  => $co['name'],
        '{{support_email}}' => $co['email'],
        '{{support_phone}}' => $co['phone'],
        '{{year}}'          => date('Y'),
    ];
    foreach ($vars as $k => $v) { $base['{{'.$k.'}}'] = $v; }
    return strtr($s, $base);
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

    $co = company_info();
    $logoHtml = $co['logo']
        ? '<img src="' . esc($co['logo']) . '" alt="' . esc($co['name']) . ' logo" style="max-height:48px;max-width:200px;display:inline-block;vertical-align:middle;">'
        : '';
    $addressHtml = $co['address'] ? nl2br(esc($co['address'])) : '';

    $replacements = [
        '{{company_name}}'       => esc($co['name']),
        '{{company_logo}}'       => $logoHtml,
        '{{company_address}}'    => $addressHtml,
        '{{customer_name}}'      => esc(($order['first_name'] ?? '') ?: 'there'),
        '{{customer_email}}'     => esc($order['email'] ?? ''),
        '{{order_number}}'       => esc($order['order_number'] ?? ''),
        '{{amount}}'             => number_format((float)($order['total'] ?? 0), 2),
        '{{statement_name}}'     => esc($stmtName),
        '{{support_email}}'      => esc($co['email']),
        '{{support_phone}}'      => esc($co['phone']),
        '{{year}}'               => date('Y'),
        '{{installation_guide}}' => $guideHtml,
        '{{products_block}}'     => render_products_block($assignments),
        '{{tracking_pixel}}'     => $pixel,
    ];
    return strtr($tplHtml, $replacements);
}

function send_email(string $to, string $subject, string $html, ?int $orderId = null, ?string $templateCode = null, int $delayMinutes = 0): void {
    require_once __DIR__ . '/mailer.php';
    $pdo  = db();
    $tok  = bin2hex(random_bytes(16));
    // Embed pixel at the very end too (in case template lacks {{tracking_pixel}})
    if (strpos($html, 'track-open.php') === false) {
        $base = rtrim(site_url(), '/');
        $html .= '<img src="' . $base . '/track-open.php?t=' . urlencode($tok) . '" width="1" height="1" alt="">';
    }
    // Skip obviously invalid addresses (header-injection defence happens inside smtp_send)
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, template_code)
            VALUES (?,?,?,"failed",?,?,?,?)')
            ->execute([$to, $subject, $html, 'Invalid recipient address', $orderId, $tok, $templateCode]);
        return;
    }

    $smtp = smtp_config();
    if ($smtp['enabled'] && $smtp['host'] !== '') {
        // Production path: queue, then process this row immediately (unless
        // a delay is requested — the cron worker honours `next_retry_at`).
        $rowId = smtp_queue_email($to, $subject, $html, [
            'tracking_token' => $tok,
            'template_code'  => $templateCode,
            'order_id'       => $orderId,
            'delay_minutes'  => $delayMinutes,
        ]);
        if ($delayMinutes <= 0) {
            smtp_process_queue(1);
        }
        return;
    }

    // Dev / preview path — when delayed, store as 'queued' with future
    // next_retry_at so the cron worker picks it up at the right time.
    if ($delayMinutes > 0) {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, next_retry_at, priority)
            VALUES (?,?,?,'queued','Delayed send (dev mode)',?,?,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),5)")
            ->execute([$to, $subject, $html, $orderId, $tok, $templateCode, $delayMinutes]);
        return;
    }

    // Resend fallback (legacy support — used if RESEND_API_KEY is set)
    $apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
    if ($apiKey !== '') {
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
            ->execute([$to, $subject, $html, $ok ? 'sent' : 'failed',
                $ok ? null : ('Delivery failed (HTTP ' . $code . ')'),
                $orderId, $tok, $providerId, $ok ? date('Y-m-d H:i:s') : null, $templateCode]);
        return;
    }

    // Dev / preview mode — capture the email so the admin can verify content,
    // tracking pixel, links, etc. In production configure SMTP from the admin.
    $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, delivered_at, template_code)
        VALUES (?,?,?,"sent",?,?,?,?,?)')
        ->execute([$to, $subject, $html, 'Dev mode — no SMTP configured', $orderId, $tok, date('Y-m-d H:i:s'), $templateCode]);
}

function fulfill_order(int $orderId, bool $forceAdminOverride = false): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order || $order['fulfilled']) return;

    // CRITICAL: never consume license keys (decrement stock) before the
    // customer's payment is confirmed. `status='paid'` is set by:
    //   - the Stripe return handler in order-success.php (after verifying
    //     `payment_status === paid`),
    //   - the demo / dev checkout path which short-circuits to 'paid' when
    //     no real gateway is configured,
    //   - the admin manually flipping order status to 'paid'.
    // Admin can still trigger a manual fulfilment for legitimate edge cases
    // (e.g. bank-transfer orders) by passing $forceAdminOverride=true; in
    // that case we also mark the order paid so the books stay consistent.
    if ($order['status'] !== 'paid') {
        if (!$forceAdminOverride) {
            error_log("fulfill_order: refusing to consume stock for order #{$orderId} — status='{$order['status']}' (payment not confirmed).");
            return;
        }
        $pdo->prepare('UPDATE orders SET status="paid" WHERE id=?')->execute([$orderId]);
        $order['status'] = 'paid';
    }

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
        '{{product_name}}' => ($items[0]['name'] ?? 'your software'),
        '{{amount}}'       => number_format((float)($order['total'] ?? 0), 2),
        '{{company_name}}' => company_info()['name'] ?? (defined('SITE_BRAND') ? SITE_BRAND : ''),
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
        $vars = [
            'customer_name' => esc(($order['first_name'] ?? '') ?: 'there'),
            'customer_email'=> esc($order['email'] ?? ''),
            'order_number'  => esc($order['order_number'] ?? ''),
            'amount'        => number_format((float)($order['total'] ?? 0), 2),
            'product_name'  => esc($item['name']),
            'review_url'    => $reviewUrl,
        ];
        $reviewHtml = render_template('review_request', $vars);
        if ($reviewHtml === null) {
            // Fallback to baked-in default if the admin's template is empty.
            $tplHtml = default_review_template();
            $reviewHtml = strtr($tplHtml, array_merge([
                '{{company_name}}'  => esc(company_info()['name']),
                '{{support_email}}' => esc(company_info()['email']),
                '{{support_phone}}' => esc(company_info()['phone']),
                '{{year}}'          => date('Y'),
                '{{tracking_pixel}}'=> '',
            ], array_combine(array_map(fn($k) => '{{'.$k.'}}', array_keys($vars)), array_values($vars))));
        }
        $subj = render_template_subject('review_request', $vars)
              ?: ('How was your ' . $item['name'] . '? · Quick 30-second review');
        // Send the review request 10 minutes after order fulfilment — gives the
        // customer time to receive their license and try it before being asked
        // for feedback.
        send_email($order['email'], $subj, $reviewHtml, $orderId, 'review_request', 10);
    }
}


/**
 * Customer service auto-acknowledgement email.
 *
 * Sent when a visitor submits the Contact or Support form.  Purchase emails
 * (`send_email(...)` with default 0-min delay) leave the queue immediately so
 * the customer gets their license key within seconds.  Customer-service
 * acknowledgements deliberately wait 5 minutes via the queue worker — this
 * mirrors the human cadence ("we received your note, here's what to expect")
 * and prevents the mailbox from being flagged as a robotic instant-bounce.
 */
function send_customer_service_ack(string $to, string $name, string $subjectLine, string $userMessage, string $source = 'contact'): void {
    $co = company_info();
    $brand   = $co['name']  ?: (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
    $support = $co['email'] ?: (defined('SITE_EMAIL') ? SITE_EMAIL : '');
    $phone   = $co['phone'] ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
    $hours   = defined('SITE_HOURS') ? SITE_HOURS : 'Mon-Sat, 9 AM - 6 PM EST';
    $first   = trim(strtok($name, ' ')) ?: $name ?: 'there';
    $excerpt = trim(mb_substr($userMessage, 0, 320));
    if (mb_strlen($userMessage) > 320) $excerpt .= '…';

    $subject = '[' . $brand . '] We received your message — '. mb_substr($subjectLine, 0, 80);
    $logoBlock = '<div style="font-size:22px;font-weight:800;letter-spacing:.3px;color:#fff;">'
               . '<span style="display:inline-block;width:32px;height:32px;background:#fff;color:#2563eb;border-radius:8px;text-align:center;line-height:32px;font-weight:900;margin-right:8px;vertical-align:-9px;">M</span>'
               . esc($brand) . '</div>';

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">'
          . '<div style="max-width:620px;margin:0 auto;padding:30px 16px;">'
          . '<div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);">'
          . '<div style="background:linear-gradient(135deg,#2563eb,#1e40af);padding:26px 32px;text-align:center;">'
          . $logoBlock
          . '<div style="font-size:11px;letter-spacing:1.8px;font-weight:600;margin-top:8px;color:rgba(255,255,255,.92);">CUSTOMER SUPPORT</div>'
          . '</div>'
          . '<div style="padding:30px 32px;">'
          . '<h1 style="margin:0 0 8px;font-size:22px;color:#0f172a;font-weight:700;">Hi ' . esc($first) . ', we got your message!</h1>'
          . '<p style="margin:0 0 16px;font-size:14px;color:#475569;line-height:1.65;">Thanks for reaching out to ' . esc($brand) . '. A member of our support team has been notified and will reply to <strong style="color:#0f172a;">' . esc($to) . '</strong> within one business day (typically much faster during ' . esc($hours) . ').</p>'
          . '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin:0 0 18px;">'
          . '<div style="font-size:11px;color:#64748b;letter-spacing:1px;font-weight:700;text-transform:uppercase;margin-bottom:4px;">Your message</div>'
          . '<div style="font-size:13.5px;color:#1f2937;line-height:1.6;"><strong>' . esc($subjectLine) . '</strong><br>' . nl2br(esc($excerpt)) . '</div>'
          . '</div>'
          . '<div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin:0 0 22px;font-size:13px;color:#1e3a8a;">'
          . '<strong>Need a faster answer?</strong> Call us at <a href="tel:' . esc($phone) . '" style="color:#1d4ed8;font-weight:700;">' . esc($phone) . '</a> during ' . esc($hours) . ', or reply to this email directly.'
          . '</div>'
          . '<p style="margin:0;font-size:12.5px;color:#64748b;">— The ' . esc($brand) . ' team</p>'
          . '</div>'
          . '<div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #f1f3f5;font-size:11.5px;color:#64748b;text-align:center;">'
          . '<a href="mailto:' . esc($support) . '" style="color:#2563eb;text-decoration:none;">' . esc($support) . '</a> · ' . esc($phone) . '<br>'
          . '<span style="font-size:11px;color:#94a3b8;">© ' . date('Y') . ' ' . esc($brand) . '. Source: ' . esc($source) . '</span>'
          . '</div></div></div></body></html>';

    // 5-minute delay (purchase emails go instantly with the default 0-min
    // delay; this mirrors what the user requested in handoff message 539).
    send_email($to, $subject, $html, null, 'customer_service_ack', 5);
}
