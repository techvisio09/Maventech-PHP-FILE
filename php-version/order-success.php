<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/stripe.php';
$pageTitle = 'Order Confirmed | ' . SITE_BRAND;

$orderNumber = $_GET['order'] ?? '';
$sessionId = $_GET['session_id'] ?? '';
$order = null;
if ($orderNumber) {
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
if ($order && $order['status'] === 'paid') {
    $publicHost = trim((string)setting_get('site_domain_url', '')) ?: site_url();
    $qrUrl = rtrim($publicHost, '/')
           . '/order-history.php?email=' . urlencode((string)$order['email'])
           . '&order=' . urlencode((string)$order['order_number']);
}
?>
<div class="container py-5" style="max-width: 1000px;">
  <?php if ($order && $order['status'] === 'paid'): ?>
  <div class="row g-4 align-items-start" data-testid="order-success-grid">
    <!-- ===== QR code rail (left on ≥md, top on mobile) — compact ===== -->
    <div class="col-12 col-md-3">
      <div class="receipt-qr-block sticky-top text-center" data-testid="receipt-qr-card" style="top:24px;">
        <div class="receipt-qr-tag" data-testid="receipt-qr-tag">
          <i class="bi bi-qr-code-scan me-1"></i>SCAN WITH YOUR PHONE
        </div>
        <div class="receipt-qr-wrap" data-testid="receipt-qr-wrap">
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

    <!-- ===== Existing thank-you content (right column) ===== -->
    <div class="col-12 col-md-9 text-center">
    <div class="success-tick mb-4" data-testid="success-tick"><i class="bi bi-check-lg"></i></div>
    <h1 class="fw-bold mt-3 h3" data-testid="order-success-title">Thanks for purchasing with us<?= $order['first_name'] ? ', ' . esc($order['first_name']) : '' ?>!</h1>
    <p class="text-secondary" data-testid="order-success-msg">For your <strong>product key</strong>, please check your email <strong>inbox or spam folder</strong> — we've sent it to <strong><?= esc($order['email']) ?></strong>.</p>
    <div class="card co-banner p-4 my-4 text-start">
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Order Number</span><span class="fw-bold" data-testid="order-number">#<?= esc($order['order_number']) ?></span></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Payment Method</span><span class="fw-semibold"><?= $order['payment_method'] === 'paypal' ? 'PayPal' : 'Credit/Debit Card' ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary">Total</span><span class="fw-bold text-primary"><?= format_price((float)$order['total']) ?></span></div>
    </div>
    <p class="small text-secondary">The charge will appear as <strong><?= SITE_LEGAL ?></strong> on your card statement.</p>

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
        <div class="col-4"><a href="tel:<?= SITE_PHONE ?>" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-telephone text-primary"></i><div class="small fw-semibold mt-1">Phone</div></a></div>
        <div class="col-4"><a href="contact.php" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-envelope text-primary"></i><div class="small fw-semibold mt-1">Email</div></a></div>
        <div class="col-4"><a href="#" onclick="toggleChat();return false;" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-chat-dots text-primary"></i><div class="small fw-semibold mt-1">Chat</div></a></div>
      </div>
    </div>

    </div><!-- /.col-md-8 -->
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
