<?php /* Footer + chat widget + scripts */ ?>
<footer class="footer-dark pt-0 pb-4 mt-5">

  <!-- Newsletter band -->
  <div class="border-bottom border-secondary-subtle" style="border-color: rgba(255,255,255,.12) !important;">
    <div class="container text-center py-5">
      <h3 class="text-white fw-bold fs-2">Join our list and save up to <span style="color:#67e8f9;">81%</span></h3>
      <p class="small mb-4">Subscribe and receive exclusive weekly deals straight to your inbox!</p>
      <form class="d-flex gap-2 mx-auto" style="max-width: 420px;" onsubmit="subscribeNewsletter(event)">
        <input type="email" required class="form-control rounded-pill px-3" placeholder="Enter your email" data-testid="newsletter-email">
        <button class="btn btn-primary rounded-pill px-4 fw-semibold" type="submit" data-testid="newsletter-join">Join</button>
      </form>
      <div class="d-flex justify-content-center gap-4 flex-wrap small mt-4">
        <span><i class="bi bi-patch-check-fill text-success me-1"></i>Genuine Products</span>
        <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant Delivery</span>
        <span><i class="bi bi-people-fill text-info me-1"></i>50,000+ Customers</span>
        <span><i class="bi bi-headset text-primary me-1"></i>Expert Support</span>
      </div>
    </div>
  </div>

  <div class="container pt-5">
    <div class="row g-4">
      <!-- Brand column -->
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <?php if (!empty($brandLogo)): ?>
            <img src="<?= esc($brandLogo) ?>" alt="<?= esc($brandName) ?>" style="height:42px;width:auto;max-width:140px;object-fit:contain;">
          <?php else: ?>
            <?= render_logo(42) ?>
          <?php endif; ?>
          <span>
            <?php
              $bnParts = preg_split('/\s+/', trim($brandName));
              $bnLast  = array_pop($bnParts) ?: '';
              $bnHead  = implode(' ', $bnParts);
            ?>
            <span class="brand-text d-block lh-1 text-white"><?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span></span>
            <small class="brand-tag">AUTHORIZED RESELLER</small>
          </span>
        </div>
        <p class="small">Your trusted source for genuine Microsoft Office licenses at competitive prices. Instant delivery, lifetime licenses, and professional support.</p>

        <div class="small fw-bold text-white mb-2">Subscribe for Deals</div>
        <form class="d-flex gap-2 mb-3" style="max-width: 320px;" onsubmit="subscribeNewsletter(event)">
          <input type="email" required class="form-control form-control-sm" placeholder="Enter your email">
          <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-arrow-right"></i></button>
        </form>

        <p class="small mb-1"><i class="bi bi-telephone me-2 text-info"></i><a href="tel:<?= esc($brandPhone) ?>"><?= esc($brandPhone) ?></a></p>
        <p class="small mb-1"><i class="bi bi-envelope me-2 text-info"></i><a href="mailto:<?= esc($brandEmail) ?>"><?= esc($brandEmail) ?></a></p>
        <p class="small mb-2"><i class="bi bi-geo-alt me-2 text-info"></i><?= esc($brandAddress) ?></p>
        <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($brandAddress) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light rounded-pill mb-2 gmap-btn" data-testid="footer-gmap-btn">
          <span class="gmap-pin"><i class="bi bi-geo-alt-fill"></i></span>View on Google Maps
        </a>
        <p class="small mb-3"><i class="bi bi-clock me-2 text-info"></i><?= SITE_HOURS ?></p>

        <div class="d-flex gap-2">
          <?php foreach ([['Facebook', 'bi-facebook'], ['Twitter', 'bi-twitter-x'], ['LinkedIn', 'bi-linkedin'], ['Instagram', 'bi-instagram']] as [$sn, $si]): ?>
            <a href="#top" aria-label="<?= $sn ?>" class="social-circle"><i class="bi <?= $si ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Products -->
      <div class="col-lg-2 col-md-4 col-6">
        <h6 class="text-white fw-bold mb-3">Products</h6>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="category.php?slug=office-2024-pc">Microsoft Office 2024</a></li>
          <li><a href="category.php?slug=office-2021-pc">Microsoft Office 2021</a></li>
          <li><a href="category.php?slug=office-2019-pc">Microsoft Office 2019</a></li>
          <li><a href="category.php?slug=microsoft-project">Microsoft Project</a></li>
          <li><a href="category.php?slug=microsoft-visio">Microsoft Visio</a></li>
          <li><a href="category.php?slug=office-mac">Office for Mac</a></li>
          <li><a href="category.php?slug=windows">Windows OS</a></li>
        </ul>
      </div>

      <!-- Support -->
      <div class="col-lg-3 col-md-4 col-6">
        <h6 class="text-white fw-bold mb-3">Support</h6>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="account.php">My Account</a></li>
          <li><a href="track-order.php" data-testid="footer-order-history-link">Track Order &amp; Receipts</a></li>
          <li><a href="support.php">Support Center</a></li>
          <li><a href="page.php?slug=help-center">Help Center</a></li>
          <li><a href="page.php?slug=installation-guide">Installation Guide</a></li>
          <li><a href="page.php?slug=activation-help">Activation Help</a></li>
          <li><a href="page.php?slug=faqs">FAQs</a></li>
          <li><a href="contact.php">Contact Us</a></li>
          <li><a href="returns.php">Returns &amp; Refunds</a></li>
        </ul>
      </div>

      <!-- Company -->
      <div class="col-lg-3 col-md-4 col-6">
        <h6 class="text-white fw-bold mb-3">Company</h6>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="page.php?slug=why-choose-us">Why Choose Us</a></li>
          <li><a href="reviews.php">Customer Reviews</a></li>
          <li><a href="blog.php">Blog</a></li>
          <?php
            // Auto-render a Brands sub-menu so users can reach each brand
            // profile (Microsoft, Bitdefender, McAfee...) and its dedicated
            // Articles tab from any page.
            try {
                $allBrands = db()->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' AND is_active = 1 ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) { $allBrands = []; }
            foreach ($allBrands as $bn):
                $bSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$bn));
          ?>
            <li><a href="brand.php?slug=<?= esc($bSlug) ?>" data-testid="footer-brand-<?= esc($bSlug) ?>"><?= esc($bn) ?> Hub</a></li>
          <?php endforeach; ?>
          <li><a href="affiliate.php">Affiliate Program</a></li>
        </ul>
      </div>
    </div>

    <!-- Secure payments / reviews band -->
    <hr class="border-secondary my-4">
    <div class="row g-4 align-items-center text-center text-md-start">
      <div class="col-md-5">
        <div class="text-white small fw-bold mb-2"><i class="bi bi-lock-fill text-success me-1"></i>Secure Payments</div>
        <div class="d-flex gap-3 small mb-3 flex-wrap justify-content-center justify-content-md-start">
          <span><i class="bi bi-lock-fill text-success me-1"></i>SSL Encrypted Checkout</span>
          <span><i class="bi bi-shield-fill-check text-info me-1"></i>Secure Encrypted Transactions</span>
        </div>
        <div class="d-flex gap-2 flex-wrap justify-content-center justify-content-md-start" data-testid="footer-pay-icons">
          <?= render_payment_icons() ?>
        </div>
      </div>
      <div class="col-md-3 text-md-center">
        <div class="fs-6"><span class="text-warning">★★★★★</span> <span class="text-white fw-bold">4.6</span><span class="small">/5</span></div>
        <div class="small">5,519+ verified reviews</div>
        <a href="reviews.php" class="small text-info" data-testid="footer-see-reviews">See all reviews →</a>
      </div>
      <div class="col-md-4 text-md-end">
        <div class="d-flex gap-2 justify-content-center justify-content-md-end mb-2" data-testid="footer-trust-badges">
          <img src="assets/images/badges/microsoft-verified.svg" alt="Microsoft Verified" class="trust-badge-img" loading="lazy">
          <img src="assets/images/badges/pci-compliant.svg" alt="PCI Compliant" class="trust-badge-img" loading="lazy">
        </div>
        <small><i class="bi bi-award-fill text-warning me-1"></i>Authorized Reseller • 2+ Years</small>
      </div>
    </div>

    <!-- Trademark + legal -->
    <hr class="border-secondary my-4">
    <p class="small text-center mx-auto" style="max-width: 760px;">Microsoft®, Office®, and Windows® are trademarks of Microsoft Corporation. <?= esc($brandName) ?> is independent of and not affiliated with Microsoft Corporation.</p>
    <div class="d-flex justify-content-center flex-wrap gap-2 small mb-3">
      <?php
      $legal = [
          ['Privacy Policy', 'page.php?slug=privacy-policy'], ['Terms of Service', 'page.php?slug=terms-of-service'],
          ['Refund Policy', 'page.php?slug=refund-policy'], ['Shipping & Delivery', 'page.php?slug=shipping-delivery'],
          ['Payment Policy', 'page.php?slug=payment-policy'], ['Cookie Policy', 'page.php?slug=cookie-policy'],
          ['Do Not Sell My Info', 'page.php?slug=do-not-sell'], ['Disclaimer', 'page.php?slug=disclaimer'], ['Sitemap', 'sitemap.php'],
      ];
      foreach ($legal as $idx => [$ll, $lh]): ?>
        <a href="<?= $lh ?>"><?= $ll ?></a><?= $idx < count($legal) - 1 ? '<span class="text-secondary">|</span>' : '' ?>
      <?php endforeach; ?>
    </div>
    <div class="text-center small">© <?= date('Y') ?> <?= esc($brandName) ?>. All rights reserved.</div>
  </div>
</footer>

<!-- AI chat widget -->
<button id="chat-bubble" onclick="toggleChat()" aria-label="Open chat" data-testid="chat-bubble">
  <i class="bi bi-chat-dots"></i>
  <!-- Tiny bell + unread count overlay; surfaces the moment an admin replies
       while the panel is closed.  Disappears once the customer opens chat
       or starts typing a reply. -->
  <span id="chat-bell" class="chat-bell" style="display:none;" data-testid="chat-bell" aria-hidden="true">
    <i class="bi bi-bell-fill"></i>
    <span id="chat-bell-count" class="chat-bell-count" data-testid="chat-bell-count">1</span>
  </span>
</button>
<!-- Messenger-style admin-reply preview — slides in to the LEFT of the
     chat bubble whenever an admin reply lands while the panel is closed,
     so the customer can see what the agent said before opening chat.
     Clicking it opens the chat immediately.  Auto-fades when the chat
     opens or the customer starts replying. -->
<div id="chat-msg-preview" class="chat-msg-preview" style="display:none;" onclick="openChatFromPreview()" data-testid="chat-msg-preview" role="button" tabindex="0">
  <div class="chat-msg-preview-head">
    <span class="chat-msg-preview-avatar"><i class="bi bi-headset"></i></span>
    <div class="chat-msg-preview-meta">
      <div class="chat-msg-preview-name">Maventech Support</div>
      <div class="chat-msg-preview-sub"><span class="chat-online-dot"></span>just now</div>
    </div>
    <button class="chat-msg-preview-close" type="button" onclick="event.stopPropagation(); hideChatMsgPreview();" aria-label="Dismiss preview" data-testid="chat-msg-preview-close"><i class="bi bi-x"></i></button>
  </div>
  <div class="chat-msg-preview-body" id="chat-msg-preview-body" data-testid="chat-msg-preview-body">—</div>
  <div class="chat-msg-preview-cta">Tap to reply →</div>
</div>
<div id="chat-panel" data-testid="chat-panel">
  <div id="chat-head" class="d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <span class="chat-avatar"><i class="bi bi-headset"></i></span>
      <div class="lh-sm">
        <div class="chat-head-name">Customer Support</div>
        <small class="chat-head-sub"><span class="chat-online-dot"></span>We're here · usually reply in a few minutes</small>
      </div>
    </div>
    <button class="btn btn-sm btn-link p-0 text-white" onclick="toggleChat()" aria-label="Close chat" data-testid="chat-close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div id="chat-body">
    <!-- AI welcome + quick chips kept in markup for ProAssist auto-open flows
         but hidden by default until JS detects the customer is already
         identified (proLeadId, returning lead, etc.). -->
    <div class="chat-msg bot" id="chat-welcome-msg" data-testid="chat-default-message" style="display:none;">Hi there! I'm here to help with products, pricing, activation or anything else you need. What can I look up for you?</div>
    <div class="chat-chips" id="chat-chips" data-testid="chat-chips" style="display:none;">
      <button class="chat-chip" onclick="quickAsk('Which Office is right for my Mac?')" data-testid="chat-chip-mac"><i class="bi bi-apple me-1"></i>Office for Mac</button>
      <button class="chat-chip" onclick="quickAsk('What is the best deal on Office 2024 right now?')" data-testid="chat-chip-deal"><i class="bi bi-tags me-1"></i>Best deals on Office 2024</button>
      <button class="chat-chip" onclick="quickAsk('How do I activate my license key after purchase?')" data-testid="chat-chip-activate"><i class="bi bi-key me-1"></i>Activation help</button>
      <button class="chat-chip" onclick="quickAsk('Do your licenses expire or need a subscription?')" data-testid="chat-chip-license"><i class="bi bi-infinity me-1"></i>License validity</button>
    </div>

    <!-- ====================================================================
         INITIAL VIEW (iteration 20): chat opens straight to the contact
         form — just 3 fields (full name, email, phone) and ONE blue send
         arrow button.  No "type a message" box yet.  Once the customer
         submits, this card is hidden and we reveal:
           (a) a "Thanks for contacting the support team" agent greeting
           (b) the message input box (chat-input-row below)
         The customer's real question is then routed straight to admin
         lead management — no AI auto-replies in between.
         ==================================================================== -->
    <div id="chat-lead-form" class="chat-lead-card" style="display:block;" data-testid="chat-lead-form">
      <div class="chat-lead-title" data-testid="chat-lead-title">Tell us how to reach you, and a support agent will get back in a few minutes.</div>
      <div class="chat-lead-field-row">
        <input id="lead-name"  class="form-control form-control-sm chat-lead-input" placeholder="Full name"      data-testid="lead-name"  autocomplete="name">
      </div>
      <div class="chat-lead-field-row">
        <input id="lead-email" type="email" class="form-control form-control-sm chat-lead-input" placeholder="Email address" data-testid="lead-email" autocomplete="email">
      </div>
      <div class="chat-lead-field-row chat-lead-row-send">
        <input id="lead-phone" class="form-control form-control-sm chat-lead-input" placeholder="Phone number"   data-testid="lead-phone" autocomplete="tel">
        <button type="button"
                class="chat-lead-send-btn"
                onclick="submitLead('chat')"
                data-testid="lead-send-btn"
                aria-label="Send to support">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
      <div id="chat-lead-error" class="chat-lead-error" style="display:none;" data-testid="chat-lead-error"></div>
      <!-- Backwards-compat hidden button so older test scripts that click
           [data-testid=lead-chat-btn] still trigger submitLead('chat'). -->
      <button type="button" class="d-none" onclick="submitLead('chat')" data-testid="lead-chat-btn"></button>
    </div>
    <!-- ProAssist welcome card (shown when JS detects a ProAssist lead — no calendar/time picker, just a clear instruction). -->
    <div id="pa-sched-card" class="pa-sched-card pa-sched-welcome" style="display:none;" data-testid="pa-sched-card">
      <div class="pa-sched-header">
        <i class="bi bi-headset"></i>
        <div>
          <div class="pa-sched-title" data-testid="pa-sched-title">Pro Assistance</div>
          <div class="pa-sched-sub" data-testid="pa-sched-sub">Connected · priority support</div>
        </div>
      </div>
      <div class="pa-welcome-body">
        <p class="mb-2">For Pro Assistance, please type your message below to connect with an agent — or call our toll-free number.</p>
        <a href="tel:<?= esc($brandPhone) ?>" class="pa-welcome-phone" data-testid="pa-welcome-phone">
          <i class="bi bi-telephone-fill"></i><?= esc($brandPhone) ?>
        </a>
      </div>
    </div>
    <!-- (Calendar / time picker removed per Pro Assistance flow update.) -->
    <div id="pa-sched-confirm" style="display:none;" data-testid="pa-sched-confirm" hidden></div>
  </div>
  <div id="chat-typing" class="chat-typing" style="display:none;" data-testid="chat-admin-typing">
    <div class="chat-typing-bubble">
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-text">Live agent is typing…</span>
    </div>
  </div>
  <form id="chat-input-row" class="chat-input-row d-none align-items-center gap-2 p-2" onsubmit="sendChat(event)" data-testid="chat-input-row">
    <input id="chat-input" class="form-control form-control-sm chat-input" placeholder="Type a message…" autocomplete="off" data-testid="chat-input">
    <button class="btn chat-send-btn" type="submit" aria-label="Send" data-testid="chat-send"><i class="bi bi-send-fill"></i></button>
  </form>
  <div class="chat-talk-band" data-testid="chat-talk-band">Prefer to talk?<a href="tel:<?= esc($brandPhone) ?>" class="chat-talk-phone" data-testid="chat-talk-phone"><i class="bi bi-telephone-fill chat-talk-phone-ring"></i><?= esc($brandPhone) ?></a></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
