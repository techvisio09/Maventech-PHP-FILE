<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/stripe.php';
$pageTitle = 'Order Confirmed | ' . SITE_BRAND;

$orderNumber = $_GET['order'] ?? '';
$sessionId = $_GET['session_id'] ?? '';
$order = null;
$isDemo = (($_GET['demo'] ?? '') === '1');
if ($isDemo) {
    // Synthesised order for the test-preview iframe on /checkout.php.
    // No DB write — purely a render preview.
    $items = cart_items();
    $subtotal = cart_subtotal();
    $order = [
        'id'           => 0,
        'order_number' => $orderNumber ?: 'TEST-' . date('YmdHis'),
        'email'        => 'customer@example.com',
        'first_name'   => 'Sample',
        'last_name'    => 'Customer',
        'phone'        => '+1-555-000-0123',
        'address'      => '123 Demo Street',
        'city'         => 'San Francisco',
        'state'        => 'CA',
        'zip'          => '94107',
        'country'      => 'US',
        'subtotal'     => $subtotal,
        'total'        => $subtotal,
        'currency'     => current_currency()['code'],
        'payment_method' => 'card',
        'status'       => 'paid',
        'fulfilled'    => 1,
        'pro_assist'   => 0,
        'gw_mode'      => 'test',
        'created_at'   => date('Y-m-d H:i:s'),
    ];
} elseif ($orderNumber) {
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_number = ?');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
}

// ProAssist auto-chat: if this order has a ProAssist line item, the
// checkout flow already created a chat_lead + seeded an admin "welcome"
// message.  Bind that lead's chat_token to this browser so the chat
// widget auto-opens, skips the lead form, and starts polling for live
// agent replies immediately.
$proChatToken = null;
$proLeadId    = null;
if ($order && !empty($order['pro_assist'])) {
    try {
        $ld = db()->prepare("SELECT id, chat_token FROM chat_leads
                             WHERE email=? AND requested_product='ProAssist Premium Installation'
                             ORDER BY id DESC LIMIT 1");
        $ld->execute([$order['email']]);
        if ($row = $ld->fetch()) {
            $proLeadId    = (int)$row['id'];
            $proChatToken = (string)$row['chat_token'];
            // Re-bind to the current session in case the browser tab
            // switched after Stripe redirect (Stripe rotates session_id).
            $_SESSION['lead_id']    = $proLeadId;
            $_SESSION['chat_token'] = $proChatToken;
        }
    } catch (Throwable $e) { /* best-effort */ }
}

// Returning from Stripe: verify payment, capture admin-safe card details
// (brand, last4, expiry, funding, issuing country, risk score), then fulfill
// (idempotent — fulfill_order is a no-op on already-fulfilled rows).
if ($order && $sessionId && stripe_enabled() && $order['status'] !== 'paid') {
    try {
        $session = stripe_get_session($sessionId);
        if (($session['payment_status'] ?? '') === 'paid' && $order['stripe_session_id'] === $sessionId) {
            // Pull PCI-allowed card details from Stripe + Radar risk data.
            $cd = stripe_extract_card_details($session);
            $upd = db()->prepare("UPDATE orders SET
                status='paid',
                card_brand=?,    card_last4=?,    card_exp=?,
                card_funding=?,  card_country=?,  card_type=?,
                risk_score=?,    risk_level=?,
                payment_intent_id=?, transaction_id=?,
                billing_country=COALESCE(NULLIF(?, ''), billing_country)
                WHERE id=?");
            $upd->execute([
                $cd['card_brand']        ?: $order['card_brand'],
                $cd['card_last4']        ?: $order['card_last4'],
                $cd['card_exp']          ?: $order['card_exp'],
                $cd['card_funding']      ?: $order['card_funding'],
                $cd['card_country']      ?: $order['card_country'],
                $cd['card_type']         ?: $order['card_type'],
                $cd['risk_score'],
                $cd['risk_level'],
                $cd['payment_intent_id'],
                $cd['transaction_id'],
                $cd['billing_country'],
                $order['id'],
            ]);
            fulfill_order((int)$order['id']);
            $order['status'] = 'paid';
            // Refresh the local row so the post-paid template renders the
            // newly-captured card details if needed.
            $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$order['id']]);
            $order = $stmt->fetch() ?: $order;
        }
    } catch (RuntimeException $e) {
        // Show the pending state below; log for diagnostics.
        error_log('Stripe session verify failed for order ' . $order['order_number'] . ': ' . $e->getMessage());
    }
}

include __DIR__ . '/includes/header.php';

// Build the QR-code URL.  When the customer scans the QR on their phone,
// the receipt page (order-history.php) auto-looks up the order via the
// ?email=X&order=Y query string and renders the full delivery view —
// license keys, Sign-in-to-activate, Installation Guide, PDF download.
// This is the exact same data the customer got in their email.
$qrUrl = '';
$orderItems = [];
if ($order && $order['status'] === 'paid') {
    $publicHost = trim((string)setting_get('site_domain_url', '')) ?: site_url();
    $qrUrl = rtrim($publicHost, '/')
           . '/order-history.php?email=' . urlencode((string)$order['email'])
           . '&order=' . urlencode((string)$order['order_number']);

    // Pull this order's products + their already-assigned license keys so we
    // can render the same "what you bought + here's your license key + Sign
    // in to activate + View installation guide" card that the email shows.
    // This is the "show more focus on the product" iteration 20 request.
    try {
        if ($isDemo) {
            // Demo preview — use the live cart items + synthetic license keys
            // so the iframe in checkout's test-preview modal looks realistic.
            $orderItems = array_map(function($i) {
                return [
                    'product_slug' => $i['slug'],
                    'name'         => $i['name'],
                    'qty'          => (int)$i['qty'],
                    'price'        => $i['price'],
                    'image'        => $i['image'] ?? '',
                    'brand'        => $i['brand'] ?? '',
                    'activation_url'    => '',
                    'install_guide_url' => '',
                    'license_keys' => array_map(
                        fn($n) => 'XXXXX-XXXXX-XXXXX-' . strtoupper(substr(md5($i['slug'].$n), 0, 5)) . '-DEMO',
                        range(1, (int)$i['qty'])
                    ),
                ];
            }, cart_items());
        } else {
            $stIt = db()->prepare(
                'SELECT oi.product_slug, oi.name, oi.qty, oi.price,
                        p.image, p.brand, p.activation_url, p.install_guide_url
                 FROM order_items oi
                 LEFT JOIN products p ON p.slug = oi.product_slug
                 WHERE oi.order_id = ?'
            );
            $stIt->execute([(int)$order['id']]);
            $orderItems = $stIt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // Attach assigned license keys per item (in insertion order)
            $stKeys = db()->prepare(
                'SELECT product_slug, license_key
                 FROM license_keys
                 WHERE order_id = ? AND status = "sold"
                 ORDER BY id ASC'
            );
            $stKeys->execute([(int)$order['id']]);
            $keysByProduct = [];
            foreach ($stKeys->fetchAll(PDO::FETCH_ASSOC) as $kr) {
                $keysByProduct[$kr['product_slug']][] = $kr['license_key'];
            }
            foreach ($orderItems as &$it) {
                $slug = $it['product_slug'];
                $it['license_keys'] = $keysByProduct[$slug] ?? [];
                if (function_exists('activation_url_for_product')) {
                    $it['activation_url'] = activation_url_for_product(
                        (string)$it['name'],
                        (string)($it['brand'] ?? ''),
                        (string)($it['activation_url'] ?? '')
                    );
                }
            }
            unset($it);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}
?>
<div class="container py-4" style="max-width: 1100px;">
  <?php if ($order && $order['status'] === 'paid'): ?>
  <div class="row g-3 align-items-start" data-testid="order-success-grid">
    <!-- ===== QR code rail (pulled toward the left edge so the centered
         thank-you block has more breathing room) ===== -->
    <div class="col-12 col-md-3 ms-md-n2">
      <div class="receipt-qr-block" data-testid="receipt-qr-card" style="text-align:left;position:sticky;top:24px;z-index:1;">
        <div class="receipt-qr-tag" data-testid="receipt-qr-tag">
          <i class="bi bi-qr-code-scan me-1"></i>SCAN WITH YOUR PHONE
        </div>
        <div class="receipt-qr-wrap" data-testid="receipt-qr-wrap" style="margin-left:0;">
          <div id="receipt-qr"
               data-testid="receipt-qr"
               data-url="<?= esc($qrUrl) ?>"></div>
        </div>
        <div class="receipt-qr-title" data-testid="receipt-qr-title">
          View your license keys &amp; installation guide on any phone
        </div>
        <div class="receipt-qr-help" data-testid="receipt-qr-help">
          Scanning opens a secure receipt page showing this order, the product name, license key,
          <strong>Sign in to activate</strong> and <strong>View installation guide</strong> buttons &mdash; same details as the email.
        </div>
        <button type="button"
                class="receipt-qr-copy-btn"
                data-testid="receipt-qr-copy-link"
                onclick="(function(b){var t=document.getElementById('receipt-qr').dataset.url;if(!t)return;function done(){var o=b.dataset.orig||b.innerHTML;b.dataset.orig=b.dataset.orig||b.innerHTML;b.innerHTML='<i class=\'bi bi-check2 me-1\'></i>Link copied to clipboard';b.classList.add('is-copied');setTimeout(function(){b.innerHTML=o;b.classList.remove('is-copied');},1800);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done,done);}else{var ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');}catch(_){}ta.remove();done();}})(this)">
          <i class="bi bi-link-45deg me-1"></i>Or copy the link
        </button>
      </div>
    </div>

    <!-- ===== Thank-you content (centered, narrower for a more elegant feel) ===== -->
    <div class="col-12 col-md-9 text-center">
      <div class="success-thanks-block mx-auto" style="max-width:560px;">
    <div class="success-tick success-tick-sm mb-3" data-testid="success-tick"><i class="bi bi-check-lg"></i></div>
    <h1 class="fw-bold mt-2 mb-1 h4" data-testid="order-success-title" style="font-size:1.35rem;letter-spacing:.1px;">Thanks for purchasing with us<?= $order['first_name'] ? ', ' . esc($order['first_name']) : '' ?>!</h1>
    <p class="text-secondary mb-3" data-testid="order-success-msg" style="font-size:.85rem;line-height:1.5;">For your <strong>product key</strong>, please check your email <strong>inbox or spam folder</strong> &mdash; we've sent it to <strong><?= esc($order['email']) ?></strong>.</p>
    <div class="card co-banner p-3 my-3 text-start" style="border-radius:12px;">
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary small">Order Number</span><span class="fw-bold" data-testid="order-number" style="font-size:.9rem;">#<?= esc($order['order_number']) ?></span></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary small">Payment Method</span><span class="fw-semibold" style="font-size:.85rem;"><?= $order['payment_method'] === 'paypal' ? 'PayPal' : 'Credit/Debit Card' ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary small">Total</span><span class="fw-bold text-primary" style="font-size:.95rem;"><?= format_price((float)$order['total']) ?></span></div>
    </div>
    <p class="small text-secondary mb-3" style="font-size:.78rem;">The charge will appear as <strong><?= SITE_LEGAL ?></strong> on your card statement.</p>

    <!-- ====================================================================
         PRODUCT SHOWCASE — list every product on this order with its
         license key + Sign-in-to-activate + View-installation-guide
         buttons.  Same data as the customer's email, surfaced ON the
         success page so they can act on it immediately.  Each product
         renders only when an actual license key is present
         (ProAssist + accessories are intentionally omitted).
         ==================================================================== -->
    <?php if (!empty($orderItems)): ?>
    <div class="success-product-list text-start" data-testid="success-product-list">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge text-bg-success" style="background:#06b6d4 !important;color:#fff;border-radius:999px;padding:4px 11px;font-size:10.5px;letter-spacing:1px;font-weight:700;">
          <i class="bi bi-key-fill me-1"></i>YOUR PRODUCTS &amp; LICENSE KEYS
        </span>
      </div>
      <?php
      foreach ($orderItems as $oi):
        if (empty($oi['license_keys']) || ($oi['product_slug'] === 'proassist-premium')) continue;
        // We now assign ONE key per line item (multi-seat). Always render
        // just the FIRST key. The badge above tells the customer how many
        // seats/devices the key is valid for.
        $lk        = $oi['license_keys'][0];
        $seats     = max(1, (int)($oi['qty'] ?? 1));
        $isMS      = stripos((string)($oi['brand'] ?? ''), 'microsoft') !== false
                  || stripos((string)$oi['name'], 'microsoft') !== false
                  || stripos((string)$oi['name'], 'office') !== false
                  || stripos((string)$oi['name'], 'windows') !== false;
        $noun      = $isMS ? 'PC' : 'device';
        $seatLabel = ($seats > 1) ? ('Valid for ' . $seats . ' ' . $noun . 's') : '';
      ?>
        <div class="card co-banner p-3 mb-2" data-testid="success-product-card-<?= esc($oi['product_slug']) ?>" style="border-radius:14px;">
          <div class="d-flex align-items-start gap-3">
            <?php if (!empty($oi['image'])): ?>
              <img src="<?= esc($oi['image']) ?>" alt="<?= esc($oi['name']) ?>" style="width:64px;height:64px;object-fit:contain;border-radius:10px;background:#fff;padding:6px;flex-shrink:0;">
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
              <div class="fw-bold" style="font-size:.92rem;color:var(--bs-body-color);" data-testid="success-product-name"><?= esc($oi['name']) ?></div>
              <?php if ($seatLabel): ?>
                <div class="mt-2" data-testid="success-product-seats-<?= esc($oi['product_slug']) ?>">
                  <span style="display:inline-flex;align-items:center;gap:.35rem;background:linear-gradient(135deg,#e0f2fe,#bae6fd);color:#075985;border:1px solid #7dd3fc;border-radius:999px;padding:3px 11px;font-size:.74rem;font-weight:700;letter-spacing:.3px;">
                    <i class="bi bi-shield-check"></i><?= esc($seatLabel) ?>
                  </span>
                </div>
              <?php endif; ?>
              <div class="text-secondary" style="font-size:.72rem;letter-spacing:.4px;text-transform:uppercase;font-weight:700;margin-top:6px;">LICENSE KEY</div>
              <div class="license-key-pill mt-1" data-testid="success-product-license"
                   style="font-family:ui-monospace,Menlo,monospace;background:linear-gradient(135deg,#ecfeff,#cffafe);color:#0e7490;border:1px dashed #06b6d4;border-radius:8px;padding:6px 10px;font-size:.85rem;font-weight:700;letter-spacing:.6px;display:inline-block;word-break:break-all;">
                <?= esc($lk) ?>
              </div>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <?php if (!empty($oi['activation_url'])): ?>
                  <a href="<?= esc($oi['activation_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary rounded-pill" data-testid="success-activate-btn" style="font-size:.72rem;padding:4px 12px;background:linear-gradient(135deg,#06b6d4,#0891b2);border:0;">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Sign in to activate
                  </a>
                <?php endif; ?>
                <a href="<?= !empty($oi['install_guide_url']) ? esc($oi['install_guide_url']) : 'page.php?slug=installation-guide' ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary rounded-pill" data-testid="success-installguide-btn" style="font-size:.72rem;padding:4px 12px;">
                  <i class="bi bi-book me-1"></i>View installation guide
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Order History self-service -->
    <div class="card co-banner p-3 mb-4 text-start" style="background:linear-gradient(135deg,#ecfdf5,#f0fdfa);border:1px solid #a7f3d0;border-radius:14px;" data-testid="oh-cta-on-success">
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="d-none d-sm-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#10b981;color:#fff;border-radius:12px;flex-shrink:0;">
          <i class="bi bi-file-earmark-pdf" style="font-size:20px;"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-bold" style="color:#065f46;">Need to re-download your Receipt or Invoice?</div>
          <div class="small" style="color:#047857;">Anytime, with just your email + this order number &mdash; no support ticket needed.</div>
        </div>
        <a href="order-history.php" class="btn btn-success btn-sm rounded-pill px-3 ms-auto" data-testid="oh-cta-button"><i class="bi bi-receipt me-1"></i>Get my PDFs</a>
      </div>
    </div>

    <?php if (!empty($order['pro_assist']) && $proChatToken): ?>
    <div class="card co-banner p-4 my-4 text-start" style="border: 1px solid #1e40af33; background: linear-gradient(135deg,#eff6ff,#dbeafe);" data-testid="proassist-chat-banner">
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-tools" style="color:#1e3a8a;font-size:20px;"></i>
        <div class="fw-bold" style="color:#1e3a8a;">ProAssist Premium Installation</div>
      </div>
      <p class="small mb-3" style="color:#1e3a8a;">A specialist has been notified and will reach out within one business hour. We've also opened a live chat — type any questions and an agent will reply right here.</p>
      <button type="button" class="btn btn-sm rounded-pill px-3" style="background:#1e3a8a;color:#fff;border:none;" onclick="toggleChat()" data-testid="proassist-open-chat-btn"><i class="bi bi-chat-dots-fill me-1"></i>Open chat with agent</button>
    </div>
    <script>
      // Bind the ProAssist chat thread to this browser, skip the lead
      // form (we already have name/email/phone from checkout), and
      // start live-polling for admin replies.  Auto-open the chat
      // widget after the page finishes loading so the customer sees
      // the welcome message immediately.
      (function(){
        try {
          localStorage.setItem('uc_chat_token', <?= json_encode($proChatToken) ?>);
          localStorage.setItem('uc_lead_id',    <?= json_encode((string)$proLeadId) ?>);
          localStorage.setItem('uc_lead_done',  '1');
        } catch(_) {}
        document.addEventListener('DOMContentLoaded', function(){
          if (typeof startAdminPolling === 'function') startAdminPolling();
          // Open the chat widget once on this page so the customer
          // can't miss the agent welcome.  Subsequent navigations
          // honour the user's open/closed preference.
          setTimeout(function(){
            var panel = document.getElementById('chat-panel');
            if (panel && !panel.classList.contains('open')) {
              if (typeof toggleChat === 'function') toggleChat();
            }
          }, 800);
        });
      })();
    </script>
    <?php endif; ?>

    <a href="index.php" class="btn btn-primary btn-lg rounded-pill px-5 my-2" data-testid="return-home-btn"><i class="bi bi-house-door me-2"></i>Return to Home Page</a>

    <div class="card co-banner p-4 my-4 text-start bg-body-tertiary">
      <div class="fw-bold mb-1">Having trouble installing or activating?</div>
      <p class="small text-secondary mb-3">Follow our step-by-step installation guide for further assistance:</p>
      <a href="page.php?slug=installation-guide" class="btn btn-outline-primary rounded-pill" data-testid="installation-guide-btn"><i class="bi bi-book me-1"></i>Installation Guide</a>
    </div>

    <div class="text-start">
      <div class="small fw-bold mb-2">Still having problems? Connect with us:</div>
      <div class="row g-2">
        <div class="col-4"><a href="tel:<?= esc(tel_e164(SITE_PHONE)) ?>" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-telephone text-primary"></i><div class="small fw-semibold mt-1">Phone</div></a></div>
        <div class="col-4"><a href="contact.php" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-envelope text-primary"></i><div class="small fw-semibold mt-1">Email</div></a></div>
        <div class="col-4"><a href="#" onclick="toggleChat();return false;" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-chat-dots text-primary"></i><div class="small fw-semibold mt-1">Chat</div></a></div>
      </div>
    </div>

      </div><!-- /.success-thanks-block -->
    </div><!-- /.col-md-9 -->
  </div><!-- /.row -->

  <!-- QR generator — pure client-side from the URL above.  Uses qrcodejs
       (~10 KB, MIT) loaded from a CDN.  No external API call, no privacy
       leak: the QR matrix is computed locally in the customer's browser. -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    (function () {
      var el = document.getElementById('receipt-qr');
      if (!el || !window.QRCode) return;
      var url = el.dataset.url || '';
      if (!url) return;
      el.innerHTML = '';
      try {
        new QRCode(el, {
          text: url,
          width: 132,
          height: 132,
          colorDark: '#0f172a',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M,
        });
      } catch (e) {
        // Graceful fallback: render the URL as plain text so the customer
        // can at least copy/paste even if the QR library failed to load.
        el.innerHTML = '<div style="font-size:11px;word-break:break-all;color:#475569;padding:8px;">' + url + '</div>';
      }
    })();
  </script>

  <?php elseif ($order): ?>
  <div class="text-center">
    <i class="bi bi-hourglass-split text-warning display-1"></i>
    <h1 class="fw-bold mt-3 h3">Payment pending</h1>
    <p class="text-secondary">Order <strong>#<?= esc($order['order_number']) ?></strong> was created but the payment hasn't been confirmed yet. If you completed payment, refresh this page in a moment.</p>
    <a href="checkout.php" class="btn btn-primary rounded-pill px-4 mt-2">Back to Checkout</a>
  </div>
  <?php else: ?>
  <div class="text-center">
    <i class="bi bi-question-circle text-secondary display-1"></i>
    <h1 class="fw-bold mt-3 h3">Order not found</h1>
    <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-2">Back to Home</a>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
