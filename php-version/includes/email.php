<?php
// Order fulfillment + transactional email with tracking + editable template.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';

/**
 * Normalize an image URL for email rendering. Email clients (Gmail, Outlook,
 * Apple Mail) require ABSOLUTE URLs — a root-relative path like
 * /uploads/products/foo.webp renders as a broken icon. This prepends the
 * configured public host (or site_url() as fallback) when the path is not
 * already absolute. Returns the original value when empty.
 */
function email_absolute_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^(https?:)?//#i', $url)) return $url;
    if (preg_match('#^(data|cid):#i', $url))  return $url;
    $publicHost = trim((string)setting_get('site_domain_url', '')) ?: site_url();
    return rtrim($publicHost, '/') . '/' . ltrim($url, '/');
}

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

/**
 * Brand-aware installation steps for the order-delivery email.
 *
 * The hardcoded "setup.office.com + Microsoft Account" fallback was
 * sending Office-style instructions to Bitdefender / Norton / Adobe
 * customers — confusing and damaging to trust.  This helper inspects
 * the product name (and admin-set `activation_url` when present) and
 * returns the correct step-by-step flow + branded URL.
 *
 * @param array $product  Row from order_items / products; expects keys:
 *                        name, optional activation_url, optional category.
 * @return string  Multi-line HTML (<br> separated) of numbered steps.
 */
function installation_steps_for(array $product): string {
    $name  = strtolower((string)($product['name'] ?? ''));
    $url   = trim((string)($product['activation_url'] ?? ''));
    // Pick the activation flow by brand keyword.  Order matters — more
    // specific keywords first.
    $catalog = [
        'bitdefender' => [
            'url'  => 'https://central.bitdefender.com',
            'site' => 'central.bitdefender.com',
            'acct' => 'Bitdefender Central account',
        ],
        'norton'      => ['url' => 'https://norton.com/setup',  'site' => 'norton.com/setup',  'acct' => 'Norton account'],
        'mcafee'      => ['url' => 'https://home.mcafee.com/secure/register',     'site' => 'mcafee.com/activate', 'acct' => 'McAfee account'],
        'kaspersky'   => ['url' => 'https://my.kaspersky.com',  'site' => 'my.kaspersky.com',  'acct' => 'My Kaspersky account'],
        'eset'        => ['url' => 'https://my.eset.com',        'site' => 'my.eset.com',       'acct' => 'My ESET account'],
        'avast'       => ['url' => 'https://my.avast.com',       'site' => 'my.avast.com',      'acct' => 'Avast account'],
        'avg'         => ['url' => 'https://my.avg.com',         'site' => 'my.avg.com',        'acct' => 'AVG account'],
        'webroot'     => ['url' => 'https://my.webrootanywhere.com', 'site' => 'my.webrootanywhere.com', 'acct' => 'Webroot account'],
        'trend micro' => ['url' => 'https://account.trendmicro.com', 'site' => 'account.trendmicro.com', 'acct' => 'Trend Micro account'],
        'malwarebytes'=> ['url' => 'https://my.malwarebytes.com', 'site' => 'my.malwarebytes.com', 'acct' => 'Malwarebytes account'],
        'adobe'       => ['url' => 'https://account.adobe.com/products', 'site' => 'account.adobe.com', 'acct' => 'Adobe ID'],
        'autocad'     => ['url' => 'https://manage.autodesk.com', 'site' => 'manage.autodesk.com', 'acct' => 'Autodesk account'],
        'autodesk'    => ['url' => 'https://manage.autodesk.com', 'site' => 'manage.autodesk.com', 'acct' => 'Autodesk account'],
        'corel'       => ['url' => 'https://account.coreldraw.com', 'site' => 'account.coreldraw.com', 'acct' => 'Corel account'],
        'parallels'   => ['url' => 'https://my.parallels.com',   'site' => 'my.parallels.com',  'acct' => 'Parallels account'],
        'windows'     => ['url' => 'https://account.microsoft.com/services', 'site' => 'account.microsoft.com', 'acct' => 'Microsoft account', 'extra' => 'For Windows, you can also activate via Settings → System → Activation → Change product key.'],
        'visio'       => ['url' => 'https://setup.office.com',   'site' => 'setup.office.com',  'acct' => 'Microsoft account'],
        'project'     => ['url' => 'https://setup.office.com',   'site' => 'setup.office.com',  'acct' => 'Microsoft account'],
        'office'      => ['url' => 'https://setup.office.com',   'site' => 'setup.office.com',  'acct' => 'Microsoft account'],
        'microsoft'   => ['url' => 'https://setup.office.com',   'site' => 'setup.office.com',  'acct' => 'Microsoft account'],
    ];
    $match = null; $brandLabel = '';
    foreach ($catalog as $kw => $cfg) {
        if (strpos($name, $kw) !== false) { $match = $cfg; $brandLabel = ucwords($kw); break; }
    }
    // Admin override — if the product row has an explicit activation_url,
    // use that as the primary destination but still pick the right brand
    // copy for context.
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        if (!$match) $match = ['url' => $url, 'site' => $host, 'acct' => 'your vendor account'];
        else         $match['url'] = $url;
        $match['site'] = $host;
    }
    if (!$match) {
        // Generic fallback when we genuinely don't know the brand — never
        // mention Office.  Direct the customer to their license key + a
        // request to use the official vendor portal printed on the box /
        // confirmation page.
        return '1. Visit the official vendor website for your product (link on the product\'s order page).<br>'
             . '2. Sign in or create a free account with the vendor.<br>'
             . '3. Enter the license key shown above and follow the on-screen activation prompts.';
    }
    $extra = !empty($match['extra']) ? '<br><em style="color:#64748b;font-size:12px;">' . esc($match['extra']) . '</em>' : '';
    return '1. Visit <a href="' . esc($match['url']) . '" style="color:#1d4ed8;font-weight:600;text-decoration:underline;">' . esc($match['site']) . '</a> to download the official installer.<br>'
         . '2. Sign in with (or create) your <strong>' . esc($match['acct']) . '</strong>.<br>'
         . '3. Enter the license key shown above when prompted and follow the on-screen activation steps.'
         . $extra;
}

/**
 * Brand-aware product FAQ pairs — used by:
 *   1. product.php to render a visible accordion section, AND
 *   2. product.php to emit a Schema.org FAQPage JSON-LD block.
 *
 * Why brand-aware: ChatGPT/Perplexity quote the exact answer text we
 * provide.  A Norton customer asking "is my Norton key genuine?" needs
 * Norton-specific wording — not Microsoft.  The installation FAQ pulls
 * directly from `installation_steps_for()` so all surfaces stay in sync.
 *
 * @param array $product  Same row used elsewhere — needs `name`,
 *                        optional `category`, optional `activation_url`.
 * @return array<int, array{question:string, answer:string}>
 */
function product_faqs(array $product): array {
    $name   = trim((string)($product['name'] ?? 'this product'));
    $nameLc = strtolower($name);
    // Detect brand to personalise wording — same dictionary as the
    // install-guide helper to avoid drift.
    $brandLookup = [
        'bitdefender' => 'Bitdefender', 'norton' => 'Norton', 'mcafee' => 'McAfee',
        'kaspersky'   => 'Kaspersky',   'eset'   => 'ESET',   'avast'  => 'Avast',
        'avg'         => 'AVG',         'webroot'=> 'Webroot','trend micro' => 'Trend Micro',
        'malwarebytes'=> 'Malwarebytes','adobe'  => 'Adobe',  'autocad'=> 'Autodesk',
        'autodesk'    => 'Autodesk',    'corel'  => 'Corel',  'parallels' => 'Parallels',
        'windows'     => 'Microsoft',   'office' => 'Microsoft','visio' => 'Microsoft',
        'project'     => 'Microsoft',
    ];
    $brand = 'the vendor';
    foreach ($brandLookup as $kw => $br) {
        if (strpos($nameLc, $kw) !== false) { $brand = $br; break; }
    }
    // Pretty install steps without HTML (FAQ answers are plain text on
    // the page + we re-render JSON-LD safely).
    $installStepsPlain = strip_tags(str_replace('<br>', "\n", installation_steps_for($product)));
    $installStepsPlain = preg_replace('/\s+/', ' ', trim($installStepsPlain));

    $co = function_exists('company_info') ? company_info() : ['name' => 'Maventech Software', 'phone' => '', 'email' => ''];
    $brandStore = $co['name'] ?? 'Maventech Software';
    $supportHrs = defined('SITE_HOURS') ? SITE_HOURS : 'Mon-Sat, 9 AM - 6 PM EST';

    $faqs = [
        [
            'question' => 'How long does delivery take for ' . $name . '?',
            'answer'   => 'Your ' . $name . ' license key is delivered by email almost instantly — typically within 15-30 minutes of completing payment, often in seconds. The email includes the activation key, the official ' . $brand . ' download link, and step-by-step activation instructions. There is no physical shipping — everything is digital and reaches the email address you provided at checkout.',
        ],
        [
            'question' => 'Is this a genuine ' . $brand . ' license key?',
            'answer'   => 'Yes. ' . $brandStore . ' only sells genuine, activation-ready ' . $brand . ' license keys sourced through authorised channels. The key you receive activates the official ' . $brand . ' software downloaded directly from the ' . $brand . ' website — it is never a cracked, repackaged, or modified installer. Every key is verified before dispatch and backed by our 30-day money-back guarantee if it fails to activate.',
        ],
        [
            'question' => 'What if my ' . $name . ' license key does not activate?',
            'answer'   => 'In the rare case a key does not activate, contact our support team within 30 days and we will either provide a working replacement key at no extra cost or issue a full refund — your choice. Support is available via live chat on the site, email, or phone (' . $supportHrs . '). Most activation issues are resolved in under 10 minutes by our concierge team.',
        ],
        [
            'question' => 'How do I activate ' . $name . ' after purchase?',
            'answer'   => $installStepsPlain . ' If you would prefer we do it for you, our ProAssist Premium Installation add-on books a specialist to set everything up over a 30-minute video call.',
        ],
        [
            'question' => 'Can I use the same ' . $name . ' key on more than one device?',
            'answer'   => 'Each license key activates one device under the ' . $brand . ' end-user license agreement. For multi-device coverage, pick a multi-seat product (e.g. 3-device or 5-device variants when available) or buy additional keys with the volume discount applied automatically at checkout for 5+ units.',
        ],
    ];

    // Office-specific FAQ block — appended only when the product is a
    // Microsoft Office SKU.  These three questions target the highest-
    // volume AEO intent variants ("one time purchase vs subscription",
    // "Windows 11 compatibility", "Office vs Microsoft 365").  They are
    // emitted into both the visible accordion AND the FAQPage JSON-LD.
    if (function_exists('office_edition_meta')) {
        $officeMeta = office_edition_meta($product);
        if (!empty($officeMeta['is_office'])) {
            $year       = $officeMeta['year'] ?: '';
            $edition    = $officeMeta['edition'] ?: '';
            $yearTxt    = $year !== ''    ? ' ' . $year    : '';
            // Standalone Word / Excel / PowerPoint / Outlook reads more
            // naturally as "Microsoft Word 2021" than "Microsoft Office
            // 2021 Word" — switch the label when the edition is a single app.
            $standalone = in_array($edition, ['Word', 'Excel', 'PowerPoint', 'Outlook'], true);
            if ($standalone) {
                $productLabel = 'Microsoft ' . $edition . $yearTxt;
                $editionTxt   = '';
            } else {
                $productLabel = 'Microsoft Office' . $yearTxt . ($edition !== '' ? ' ' . $edition : '');
                $editionTxt   = $edition !== '' ? ' ' . $edition : '';
            }

            $faqs[] = [
                'question' => 'Is this ' . $productLabel . ' a one-time purchase or a subscription?',
                'answer'   => 'This is a one-time purchase with a perpetual lifetime license — not a Microsoft 365 subscription. Pay once at the price shown, activate the key inside the official Microsoft Office' . $yearTxt . ' installer on your ' . ($officeMeta['platform'] === 'Mac' ? 'Mac' : 'Windows PC') . ', and use ' . ($standalone ? $edition : 'Word, Excel, PowerPoint' . ($edition === 'Home & Business' || $edition === 'Professional Plus' ? ' and Outlook' : '')) . ' for as long as you own the device. There are no monthly fees, no auto-renewals and no cloud account that locks you out if you stop paying.',
            ];
            $faqs[] = [
                'question' => 'Will ' . $productLabel . ' work on ' . ($officeMeta['platform'] === 'Mac' ? 'macOS Sonoma / Sequoia?' : 'Windows 11 PC?'),
                'answer'   => $officeMeta['platform'] === 'Mac'
                    ? 'Yes. ' . $productLabel . ' is fully supported on macOS Sonoma (14) and Sequoia (15), as well as previous releases back to macOS Big Sur (11). Activation runs from inside the official Microsoft AutoUpdate installer downloaded straight from microsoft.com — never a cracked DMG.'
                    : 'Yes. ' . $productLabel . ' is fully supported on Windows 11 and Windows 10 PCs (and Windows 8.1/7 for Office 2019). The product key you receive activates the official 64-bit installer directly from Microsoft, so every Word, Excel, PowerPoint and (where applicable) Outlook update for this edition is included for the life of the license. If your PC meets Microsoft\'s minimum system requirements, activation completes in under five minutes.',
            ];
            $faqs[] = [
                'question' => 'What is the difference between ' . $productLabel . ' and Microsoft 365?',
                'answer'   => $productLabel . ' is a one-time-purchase perpetual license — you pay once and own this version forever. Microsoft 365 is a monthly or yearly subscription that includes always-updated apps plus 1 TB OneDrive storage. If you do not need the cloud storage or the constant feature drops, ' . $productLabel . ' is dramatically cheaper over five years and still delivers genuine ' . ($standalone ? $edition : 'Word, Excel and PowerPoint') . ' with full security updates for the version you bought.',
            ];
        }
    }

    // Windows OS FAQ block — Windows 10 / 11 (Pro / Home / Education).
    if (function_exists('windows_edition_meta')) {
        $winMeta = windows_edition_meta($product);
        if (!empty($winMeta['is_windows'])) {
            $v   = $winMeta['version'] ?: '';
            $ed  = $winMeta['edition'] ?: '';
            $vTxt   = $v  !== '' ? ' ' . $v  : '';
            $edTxt  = $ed !== '' ? ' ' . $ed : '';
            $faqs[] = [
                'question' => 'Is this a genuine retail Windows' . $vTxt . $edTxt . ' product key?',
                'answer'   => 'Yes. The 25-character key you receive is a genuine Microsoft activation code that pairs with the official Windows' . $vTxt . ' installer downloaded from microsoft.com/software-download. It is never an MAK, KMS or modified ISO. Activation completes inside Settings › System › Activation › Change product key, and the licence is tied to your hardware so it survives clean installs and Windows updates.',
            ];
            $faqs[] = [
                'question' => 'Will this Windows' . $vTxt . $edTxt . ' key activate on a brand-new PC build or a refurbished laptop?',
                'answer'   => 'Yes. The key activates fresh installs on new PC builds and clean reinstalls on refurbished or second-hand machines. Boot from the official Microsoft Media Creation Tool USB, install Windows' . $vTxt . ', skip the “I don\'t have a key” prompt and paste your code in Settings after install. ' . ($ed === 'Pro' ? 'Windows' . $vTxt . ' Pro additionally unlocks BitLocker, Remote Desktop host, Hyper-V and Group Policy.' : ''),
            ];
            $faqs[] = [
                'question' => $v === '10'
                    ? 'Can I upgrade from Windows 7 or 8.1 to Windows' . $vTxt . $edTxt . ' with this key?'
                    : 'Can I upgrade from Windows 10' . ($ed && $ed !== 'Home' ? ' Home' : '') . ' to Windows' . $vTxt . $edTxt . ' with this key?',
                'answer'   => $v === '10'
                    ? 'Yes. Run the official Windows 10 Media Creation Tool, choose "Upgrade this PC now", and Windows performs an in-place upgrade preserving your apps and files. When prompted, paste the 25-character code in Settings › Activation › Change product key to activate the new licence.'
                    : (($v === '11' && $ed)
                        ? 'Yes. A clean install of Windows 11 ' . $ed . ' is the cleanest path; or upgrade in-place from Windows 10 via Microsoft\'s PC Health Check and free upgrade flow, then enter this ' . $ed . ' key in Settings › Activation › Change product key to switch from Home to ' . $ed . '.'
                        : 'Yes. Open Settings › System › Activation › Change product key, paste the 25-character code, and Windows performs an in-place edition upgrade (e.g. Home → Pro) without reinstalling, without losing apps and without losing files.'),
            ];
        }
    }

    // Microsoft Project / Visio FAQ block.
    if (function_exists('project_visio_meta')) {
        $pvMeta = project_visio_meta($product);
        if (!empty($pvMeta['is_project_visio'])) {
            $k    = $pvMeta['kind_label'];   // 'Project' | 'Visio'
            $year = $pvMeta['year'] ?: '';
            $ed   = $pvMeta['edition'] ?: '';
            $yTxt = $year !== '' ? ' ' . $year : '';
            $faqs[] = [
                'question' => 'Is this a one-time purchase or a subscription to Microsoft ' . $k . ' Online?',
                'answer'   => 'This is a one-time purchase perpetual licence for Microsoft ' . $k . $yTxt . ($ed ? ' ' . $ed : '') . ' on Windows PC. There is no monthly subscription, no Microsoft ' . $k . ' Online seat fee and no auto-renewal. Activate the key once and use ' . $k . ' for as long as you own the device.',
            ];
            $faqs[] = [
                'question' => 'Can Microsoft ' . $k . $yTxt . ' be installed alongside my existing Microsoft Office?',
                'answer'   => 'Yes. Microsoft ' . $k . $yTxt . ' installs as a standalone app on the same Windows PC where Microsoft 365, Office 2024, Office 2021 or Office 2019 is already running. The installers do not conflict and you do not lose any existing Word, Excel, PowerPoint or Outlook settings.',
            ];
            $faqs[] = [
                'question' => 'Does Microsoft ' . $k . $yTxt . ' support Windows 11 and the latest file formats?',
                'answer'   => 'Yes. ' . ($k === 'Project'
                    ? 'Microsoft Project ' . ($year ?: '2024') . ' Professional supports Windows 11 and Windows 10, opens .mpp files from every previous Project version, exports to Excel / PDF / image, and connects to Project Online and Project Server via on-premises connectors when needed.'
                    : 'Microsoft Visio ' . ($year ?: '2024') . ' Professional supports Windows 11 and Windows 10, ships with the complete shape libraries (network, AWS / Azure / GCP, BPMN 2.0, UML 2.5, ITIL, floor plan, electrical) and reads / writes .vsd, .vsdx and .vsdm files cleanly.'),
            ];
        }
    }

    // Antivirus FAQ block (Bitdefender / McAfee / Norton / Kaspersky / etc.)
    if (function_exists('antivirus_meta')) {
        $avMeta = antivirus_meta($product);
        if (!empty($avMeta['is_antivirus'])) {
            $b   = $avMeta['brand_label'];
            $dev = $avMeta['devices'] ?: 'the licensed number of devices';
            $durRaw = $avMeta['duration'] ?? '';
            $durTxt = $durRaw !== '' ? $durRaw : 'subscription term';
            $plt = $avMeta['platform'] ?: 'Windows';
            $faqs[] = [
                'question' => 'Does this ' . $b . ' subscription auto-renew?',
                'answer'   => 'No. This is a fixed prepaid ' . $durTxt . ' ' . $b . ' subscription. You will NOT be charged automatically when the term ends — your credit card is never stored against the ' . $b . ' billing system because we activate the licence from a redemption code, not a recurring card. Renew on your own schedule (or buy a fresh key from us at the same discount).',
            ];
            $faqs[] = [
                'question' => 'When does the ' . $b . ' ' . $durTxt . ' coverage start counting?',
                'answer'   => 'The clock starts the day you redeem the activation code inside your ' . $b . ' account — not the day we email it to you. That means you can buy ahead of an existing subscription ending and queue up the new key without losing a single protected day.',
            ];
            $faqs[] = [
                'question' => 'How do I install ' . $b . ' on ' . $plt . ' after I receive the key?',
                'answer'   => '(1) Create or log in to your ' . $b . ' account at the brand\'s portal (Bitdefender Central, McAfee My Account, Norton My Account or Kaspersky My Account). (2) Click "Add subscription" or "Redeem code" and paste the 16-25 character activation key from your email. (3) Download the installer for ' . $plt . ' and install it on each of ' . $dev . '. Each device counts as one seat against your subscription.',
            ];
        }
    }

    return $faqs;
}


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
    <div style="display:flex;align-items:center;gap:14px;">
      <img src="{{site_url}}/assets/images/brand/email-logo.gif" alt="{{company_name}}" width="56" height="56" style="display:block;border-radius:14px;background:transparent;">
      <div>
        <div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;">{{company_name}}</div>
        <div style="font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;">AUTHORIZED MICROSOFT RESELLER</div>
      </div>
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
        ? '<img src="' . esc(email_absolute_url($co['logo'])) . '" alt="' . esc($co['name']) . ' logo" style="max-height:48px;max-width:200px;display:inline-block;vertical-align:middle;">'
        : '';

    $base = [
        '{{company_name}}'    => esc($co['name']),
        '{{company_logo}}'    => $logoHtml,
        '{{company_address}}' => nl2br(esc($co['address'])),
        '{{support_email}}'   => esc($co['email']),
        '{{support_phone}}'   => esc($co['phone']),
        '{{site_url}}'        => rtrim(site_url(), '/'),
        '{{year}}'            => date('Y'),
        '{{tracking_pixel}}'  => '',
        '{{promo_banner}}'    => function_exists('email_promo_banner_html') ? email_promo_banner_html() : '',
    ];
    foreach ($vars as $k => $v) { $base['{{' . $k . '}}'] = $v; }

    // If the caller supplied order_number + customer_email, expose a
    // ready-to-use Track Order URL + CTA button.  The CTA is only shown
    // when the template doesn't reference {{track_order_button}} — we
    // auto-inject it before </body> for legacy templates.
    $trackOrder = null;
    $orderNumber  = (string)($vars['order_number'] ?? '');
    $customerEmail = (string)($vars['customer_email'] ?? ($vars['email'] ?? ''));
    if ($orderNumber !== '' && $customerEmail !== '') {
        // Prefer the admin-configured public host so the link in the email
        // resolves on the live domain (not the internal cluster URL).
        $publicHost = trim((string)setting_get('site_domain_url', '')) ?: site_url();
        $siteBase = rtrim($publicHost, '/');
        $trackOrder = ['order_number' => $orderNumber, 'email' => $customerEmail];
        $base['{{track_order_url}}']    = $siteBase . '/track-order.php?email=' . urlencode($customerEmail) . '&order=' . urlencode($orderNumber);
        $base['{{track_order_button}}'] = track_order_button_html(['order_number' => $orderNumber, 'email' => $customerEmail], $siteBase);
    } else {
        $base['{{track_order_url}}']    = '';
        $base['{{track_order_button}}'] = '';
    }

    $out = strtr($html, $base);
    // If the template didn't use {{promo_banner}}, inject the banner at
    // the top of <body> so the active label + logo still appear.
    if (function_exists('email_promo_banner_html') && strpos($html, '{{promo_banner}}') === false) {
        $out = inject_promo_banner($out);
    }
    // Auto-inject the Track Order CTA for templates that didn't reference
    // {{track_order_button}} (so the link reaches every order-related email).
    if ($trackOrder !== null && strpos($html, '{{track_order_button}}') === false) {
        $publicHostForCta = trim((string)setting_get('site_domain_url', '')) ?: site_url();
        $out = inject_track_order_cta($out, $trackOrder, rtrim($publicHostForCta, '/'));
    }
    return $out;
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
        $imgSrc = email_absolute_url((string)($a['image'] ?? ''));
        $img = $imgSrc
            ? '<img src="' . esc($imgSrc) . '" width="68" height="68" alt="" style="border-radius:8px;background:#f8fafc;object-fit:contain;">'
            : '<div style="width:68px;height:68px;background:#f1f5f9;border-radius:8px;display:inline-block;"></div>';
        // Multi-seat "Valid for N PCs/devices" badge — shown only when qty > 1.
        // Microsoft / Office / Windows products use "PC" terminology; everything
        // else uses "device" (covers antivirus, security suites, utilities, etc.).
        $seats   = max(1, (int)($a['seats'] ?? 1));
        $isMS    = (stripos((string)($a['brand'] ?? ''), 'microsoft') !== false)
                || (stripos((string)$a['name'], 'microsoft') !== false)
                || (stripos((string)$a['name'], 'office')    !== false)
                || (stripos((string)$a['name'], 'windows')   !== false);
        $noun    = $isMS ? 'PC' : 'device';
        $seatBadge = ($seats > 1)
            ? '<div style="margin-top:10px;text-align:center;">'
              . '<span style="display:inline-block;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e3a8a;border:1px solid #93c5fd;border-radius:999px;padding:5px 13px;font-size:12px;font-weight:700;letter-spacing:.2px;">'
              . '&#10004; Valid for ' . $seats . ' ' . $noun . 's'
              . '</span></div>'
            : '';
        $key = $a['key']
            ? $seatBadge . '<div style="margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px 14px;text-align:center;">
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
    // Build installation guide — per-product first (admin can set
    // `installation_guide` on each product row), then a brand-aware
    // fallback so antivirus customers don't see Office instructions.
    $guides = [];
    foreach ($assignments as $a) {
        if (!empty($a['installation_guide'])) {
            $guides[] = '<strong>' . esc($a['name']) . ':</strong> ' . nl2br(esc($a['installation_guide']));
        } else {
            // Brand-aware fallback — uses the product name to detect
            // Bitdefender / Norton / McAfee / Kaspersky / Adobe / Office /
            // Windows / AutoCAD etc. and renders the matching activation
            // flow.  Each block ends with the official activation URL.
            $guides[] = '<strong>' . esc($a['name']) . ':</strong><br>' . installation_steps_for($a);
        }
    }
    $guideHtml = $guides
        ? implode('<br><br>', $guides)
        : installation_steps_for(['name' => '']);

    // Public host preference: admin-configured `site_domain_url` wins over
    // `site_url()` which can resolve to an internal cluster host when the
    // email is generated by an admin-triggered job (cron, manual resend).
    $publicHost = trim((string)setting_get('site_domain_url', '')) ?: site_url();
    $base = rtrim($publicHost, '/');
    $pixel = '<img src="' . $base . '/track-open.php?t=' . urlencode($trackingToken) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';

    $co = company_info();
    $logoHtml = $co['logo']
        ? '<img src="' . esc(email_absolute_url($co['logo'])) . '" alt="' . esc($co['name']) . ' logo" style="max-height:48px;max-width:200px;display:inline-block;vertical-align:middle;">'
        : '';
    $addressHtml = $co['address'] ? nl2br(esc($co['address'])) : '';

    $replacements = [
        '{{company_name}}'       => esc($co['name']),
        '{{company_logo}}'       => $logoHtml,
        '{{company_address}}'    => $addressHtml,
        '{{site_url}}'           => $base,
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
        '{{promo_banner}}'       => email_promo_banner_html(),
        '{{track_order_url}}'    => $base . '/track-order.php?email=' . urlencode((string)($order['email'] ?? '')) . '&order=' . urlencode((string)($order['order_number'] ?? '')),
        '{{track_order_button}}' => track_order_button_html($order, $base),
    ];
    $out = strtr($tplHtml, $replacements);
    // If the template doesn't reference {{track_order_button}}, auto-inject
    // the CTA right above the closing </body> tag so legacy templates and
    // customer-uploaded HTML continue to surface the "Track your order"
    // call-to-action.
    if (strpos($tplHtml, '{{track_order_button}}') === false) {
        $out = inject_track_order_cta($out, $order, $base);
    }
    // If the template doesn't reference {{promo_banner}}, auto-inject the
    // banner (when a vibe schedule is live) right after the opening <body>.
    if (strpos($tplHtml, '{{promo_banner}}') === false) {
        $out = inject_promo_banner($out);
    }
    return $out;
}

/**
 * Build the "Track your order" CTA block (email-safe inline-styled table).
 * Renders a centred pill button + a small helper line.  Always points to
 * /track-order.php with the customer's email + order number pre-filled so
 * the lookup happens automatically — identical to scanning the receipt QR.
 */
function track_order_button_html(array $order, string $base): string
{
    $url = $base . '/track-order.php?email=' . urlencode((string)($order['email'] ?? '')) . '&order=' . urlencode((string)($order['order_number'] ?? ''));
    $orderNum = esc((string)($order['order_number'] ?? ''));
    return '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" data-testid="email-track-order" style="margin:20px 0;">'
         . '  <tr><td align="center" style="padding:14px 18px;background:#f0f9ff;border-radius:12px;border:1px solid #bfdbfe;">'
         . '    <div style="font-size:12px;font-weight:700;letter-spacing:1.4px;color:#1d4ed8;text-transform:uppercase;margin-bottom:6px;">&#128666; Track your order</div>'
         . '    <div style="font-size:13px;color:#475569;margin-bottom:12px;line-height:1.5;">View this order anytime, re-download your receipt + invoice, or resend the keys to a different inbox.</div>'
         . '    <a href="' . esc($url) . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#ffffff;text-decoration:none;padding:11px 26px;border-radius:999px;font-size:14px;font-weight:700;">'
         . '      &#128270; View order ' . $orderNum
         . '    </a>'
         . '  </td></tr>'
         . '</table>';
}

/**
 * Auto-inject the Track-Order CTA right before the closing </body> tag
 * for templates that don't reference {{track_order_button}} yet.  Safe
 * no-op when the order's number is missing.
 */
function inject_track_order_cta(string $html, array $order, string $base): string
{
    if (empty($order['order_number'])) return $html;
    $cta = track_order_button_html($order, $base);
    if (preg_match('/<\/body>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        return substr($html, 0, $pos) . "\n" . $cta . "\n" . substr($html, $pos);
    }
    return $html . "\n" . $cta;
}

/**
 * Build the email-safe promo banner HTML (inline table layout so Gmail /
 * Outlook / Apple Mail render it identically).  Returns '' when no
 * vibe-schedule promo is currently active.
 */
function email_promo_banner_html(): string
{
    if (!function_exists('active_vibe_promo')) return '';
    $p = active_vibe_promo();
    if (!$p) return '';
    $label  = esc((string)$p['label']);
    $logo   = (string)($p['logo_url_absolute'] ?? '');
    $logoTd = '';
    if ($logo !== '') {
        $logoTd = '<td valign="middle" style="padding-right:10px;"><img src="' . esc($logo) . '" alt="' . $label . '" height="28" style="display:block;height:28px;width:auto;background:#ffffff;border-radius:6px;padding:3px 6px;"></td>';
    }
    $code = strtoupper(trim((string)($p['coupon_code'] ?? '')));
    $pct  = (int)($p['coupon_percent'] ?? 0);
    $couponTd = '';
    if ($code !== '' && $pct > 0) {
        $couponTd = '<td valign="middle" style="padding-left:14px;color:#fcd34d;font-family:-apple-system,Segoe UI,sans-serif;font-size:12px;font-weight:700;">'
                  . 'Use <span style="background:#fbbf24;color:#0f172a;padding:2px 9px;border-radius:5px;letter-spacing:.6px;">' . esc($code) . '</span> · ' . $pct . '% off'
                  . '</td>';
    }
    return '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" data-testid="email-promo-banner" style="background:#0f172a;border-radius:12px;margin:0 0 18px;border-left:4px solid #fbbf24;">'
         . '  <tr><td align="center" style="padding:11px 18px;">'
         . '    <table cellpadding="0" cellspacing="0" border="0" role="presentation"><tr>'
         . $logoTd
         . '      <td valign="middle" style="color:#f1f5f9;font-family:-apple-system,Segoe UI,sans-serif;font-size:13px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;">' . $label . '</td>'
         . $couponTd
         . '    </tr></table>'
         . '  </td></tr>'
         . '</table>';
}

/**
 * Inject the promo banner immediately after the opening <body...> tag.
 * Safe no-op when the banner is empty (no active vibe schedule).
 */
function inject_promo_banner(string $html): string
{
    $banner = email_promo_banner_html();
    if ($banner === '') return $html;
    if (preg_match('/<body[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $pos) . "\n" . $banner . substr($html, $pos);
    }
    // No <body> tag — prepend.
    return $banner . $html;
}

function send_email(string $to, string $subject, string $html, ?int $orderId = null, ?string $templateCode = null, int $delayMinutes = 0, array $attachments = []): void {
    require_once __DIR__ . '/mailer.php';
    $pdo  = db();
    $tok  = bin2hex(random_bytes(16));
    // Filter to existing files only — never let a missing path break the
    // outbox.  We persist as JSON so the worker can re-attach when sending.
    $attachJson = null;
    if ($attachments) {
        $existing = [];
        foreach ($attachments as $p) {
            if (is_string($p) && $p !== '' && is_file($p)) $existing[] = $p;
        }
        if ($existing) $attachJson = json_encode($existing);
    }
    // Embed pixel at the very end too (in case template lacks {{tracking_pixel}})
    if (strpos($html, 'track-open.php') === false) {
        $base = rtrim(site_url(), '/');
        $html .= '<img src="' . $base . '/track-open.php?t=' . urlencode($tok) . '" width="1" height="1" alt="">';
    }
    // Skip obviously invalid addresses (header-injection defence happens inside smtp_send)
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, template_code, attachments_json)
            VALUES (?,?,?,"failed",?,?,?,?,?)')
            ->execute([$to, $subject, $html, 'Invalid recipient address', $orderId, $tok, $templateCode, $attachJson]);
        return;
    }

    // Deliverability pre-flight — DNS MX/A lookup catches typo'd domains
    // (gmial.com / hotmial.com / nodomain.xyz) before they hit the queue.
    // Marking these "failed" immediately surfaces them on the admin's
    // Failed tab + bell counter so the customer can be reached out to.
    $deliv = email_address_deliverable($to);
    if (!$deliv['ok'] && in_array($deliv['reason'], ['no_mx','invalid_syntax'], true)) {
        $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, template_code, attachments_json)
            VALUES (?,?,?,"failed",?,?,?,?,?)')
            ->execute([$to, $subject, $html,
                'Undeliverable: ' . ($deliv['detail'] ?: $deliv['reason']),
                $orderId, $tok, $templateCode, $attachJson]);
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
            'attachments'    => $attachJson,
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
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, next_retry_at, priority, attachments_json)
            VALUES (?,?,?,'queued','Delayed send (dev mode)',?,?,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),5,?)")
            ->execute([$to, $subject, $html, $orderId, $tok, $templateCode, $delayMinutes, $attachJson]);
        return;
    }

    // Resend fallback (legacy support — used if RESEND_API_KEY is set)
    $apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
    if ($apiKey !== '') {
        // Build Resend attachments payload from our local file paths.
        $resendAttach = [];
        if ($attachJson) {
            foreach (json_decode($attachJson, true) ?: [] as $p) {
                if (is_file($p)) {
                    $resendAttach[] = [
                        'filename' => basename($p),
                        'content'  => base64_encode(file_get_contents($p)),
                    ];
                }
            }
        }
        $body = ['from' => SENDER_EMAIL, 'to' => [$to], 'subject' => $subject, 'html' => $html];
        if ($resendAttach) $body['attachments'] = $resendAttach;
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 20,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $res !== false && $code >= 200 && $code < 300;
        $providerId = null;
        if ($ok) { $d = json_decode($res, true); $providerId = $d['id'] ?? null; }
        $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, provider_id, delivered_at, template_code, attachments_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$to, $subject, $html, $ok ? 'sent' : 'failed',
                $ok ? null : ('Delivery failed (HTTP ' . $code . ')'),
                $orderId, $tok, $providerId, $ok ? date('Y-m-d H:i:s') : null, $templateCode, $attachJson]);
        // Only buzz the admin bell when delivery FAILED — every successful
        // sent message would be noisy.
        if (!$ok && function_exists('admin_notify')) {
            admin_notify(
                'email',
                'Email delivery failed',
                'To: ' . $to . ' · Subject: ' . mb_substr($subject, 0, 80, 'UTF-8') . ' · HTTP ' . $code,
                '/admin.php?tab=emails&filter=failed'
            );
        }
        return;
    }

    // Dev / preview mode — neither SMTP nor Resend is configured.
    // Historically these rows were marked `status='sent'` which made it
    // look like email actually left the building — a foot-gun in
    // production. Now we mark them `status='queued'` with a clear note
    // so the admin Failed-or-Pending pill catches it on Email Activity.
    // Once the operator configures SMTP (admin → SMTP / Mail Server) on
    // the live domain, the same rows will be picked up by the cron
    // worker and actually delivered + flipped to 'sent'.
    $pdo->prepare('INSERT INTO email_outbox (recipient, subject, html, status, note, order_id, tracking_token, delivered_at, template_code, attachments_json, next_retry_at)
        VALUES (?,?,?,"queued",?,?,?,NULL,?,?,NOW())')
        ->execute([$to, $subject, $html,
            '⚠ Pending delivery — configure SMTP (admin → SMTP / Mail Server) so the cron worker can dispatch this row.',
            $orderId, $tok, $templateCode, $attachJson]);
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
        // Multi-seat assignment: send ONE license key per line item, regardless
        // of qty. The customer's order line "5 × Microsoft Office 2024 (PC)"
        // becomes a single multi-seat key valid for 5 devices. Customers see
        // a "Valid for N PCs/devices" badge above the key on the success page
        // and in their delivery email.
        $seats = max(1, (int)$item['qty']);
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
            'seats' => $seats,
            'brand' => $item['brand'] ?? '',
        ];
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

    // Generate Receipt + Invoice PDFs and attach them to the order
    // delivery email.  Each customer gets a professionally-formatted
    // record they can keep for their books / claim back from finance.
    $pdfPaths = [];
    try {
        require_once __DIR__ . '/pdf.php';
        // Enrich items with sold license keys so PDFs can show them too.
        $pdfItems = [];
        foreach ($items as $idx => $it) {
            $pdfItems[] = array_merge($it, [
                'unit_price' => $it['price'] ?? 0,
                'quantity'   => $it['qty']   ?? 1,
            ]);
        }
        $pdfPaths = generate_order_pdfs($order, $pdfItems);
    } catch (Throwable $e) {
        @error_log('[fulfill_order pdf] ' . $e->getMessage());
        $pdfPaths = [];
    }

    send_email($order['email'], $subject, $html, $orderId, 'order_delivery', 0, $pdfPaths);
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
        // Queue the review request to land AFTER the customer has received
        // and read their order-delivery email.  A 10-minute delay is the
        // sweet spot — long enough for the customer to install + activate
        // their license (or at least open the order email and form an
        // initial impression), short enough to still feel "fresh" when the
        // review prompt arrives.  The cron worker honours `next_retry_at`,
        // so the review email is held in the queue until 10 minutes have
        // passed since fulfillment — guaranteed to never overtake the
        // order_delivery email in the customer's inbox.
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
