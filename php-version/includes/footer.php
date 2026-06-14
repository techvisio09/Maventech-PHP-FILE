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
          <li><a href="order-history.php" data-testid="footer-order-history-link">Order History &amp; Receipts</a></li>
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
<!-- AI welcome popup — first-visit only, styled to match the brand.
     Slides in from the bottom-right after page load, sitting just above
     the chat bubble.  "Close" dismisses + remembers, "Learn more" opens
     the existing chat panel so the customer drops into a live conversation.
-->
<div id="ai-intro-popup" class="ai-intro" style="display:none;" data-testid="ai-intro-popup" role="dialog" aria-labelledby="ai-intro-title">
  <button type="button" class="ai-intro-x" aria-label="Dismiss" onclick="aiIntroDismiss()" data-testid="ai-intro-close-x"><i class="bi bi-x"></i></button>
  <h3 id="ai-intro-title" class="ai-intro-title">
    Hi, I'm <span class="ai-intro-title-brand"><?= esc($brandName) ?></span><span class="ai-intro-title-suffix"> AI</span>
  </h3>
  <p class="ai-intro-body">
    Need help with activation, deals or your receipt? I'm here 24/7.
  </p>
  <div class="ai-intro-actions">
    <span class="ai-intro-live" aria-label="AI online" data-testid="ai-intro-live-dot">
      <span class="ai-intro-live-dot"></span>
      <span class="ai-intro-live-label">Online</span>
    </span>
    <button type="button" class="ai-intro-btn ai-intro-btn-primary" onclick="aiIntroOpen()" data-testid="ai-intro-chat-btn">
      <i class="bi bi-chat-dots-fill me-1"></i>Chat now
    </button>
  </div>
  <!-- Friendly little robot mascot — pure CSS/SVG, no emoji, theme-matched. -->
  <div class="ai-intro-bot" aria-hidden="true">
    <svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" width="92" height="92">
      <defs>
        <linearGradient id="aiBotBody" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%"  stop-color="#ffffff"/>
          <stop offset="100%" stop-color="#dbe2f0"/>
        </linearGradient>
        <linearGradient id="aiBotGlow" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%"  stop-color="#6366f1"/>
          <stop offset="100%" stop-color="#3b82f6"/>
        </linearGradient>
      </defs>
      <!-- antenna -->
      <circle cx="48" cy="10" r="3" fill="url(#aiBotGlow)"/>
      <rect   x="47" y="11" width="2" height="9" fill="#94a3b8"/>
      <!-- head -->
      <rect x="20" y="20" width="56" height="46" rx="14" ry="14" fill="url(#aiBotBody)" stroke="#cbd5e1" stroke-width="1.2"/>
      <!-- visor / face plate -->
      <rect x="28" y="30" width="40" height="26" rx="6" ry="6" fill="#0f172a"/>
      <!-- eyes -->
      <circle cx="40" cy="43" r="4" fill="#67e8f9"/>
      <circle cx="56" cy="43" r="4" fill="#67e8f9"/>
      <circle cx="41" cy="42" r="1.3" fill="#ffffff"/>
      <circle cx="57" cy="42" r="1.3" fill="#ffffff"/>
      <!-- mouth -->
      <rect x="42" y="50" width="12" height="2" rx="1" fill="#67e8f9" opacity=".7"/>
      <!-- side ears / speakers -->
      <rect x="14" y="34" width="6" height="14" rx="3" fill="#cbd5e1"/>
      <rect x="76" y="34" width="6" height="14" rx="3" fill="#cbd5e1"/>
      <!-- collar -->
      <rect x="32" y="66" width="32" height="6" rx="3" fill="#a5b4fc" opacity=".55"/>
    </svg>
  </div>
</div>

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

<style>
/* ============================================================
   AI welcome popup — matches the brand-blue palette + halo theme
   ============================================================ */
.ai-intro {
  position: fixed; right: 20px; bottom: 100px;
  width: 280px; max-width: calc(100vw - 32px);
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  border: 1px solid rgba(99, 102, 241, .18);
  border-radius: 18px;
  padding: 16px 18px 14px;
  box-shadow:
    0 20px 50px rgba(30, 41, 99, .22),
    0 4px 14px rgba(59, 130, 246, .10),
    0 0 0 1px rgba(255, 255, 255, .65) inset;
  z-index: 1080;
  animation: ai-intro-in .55s cubic-bezier(.18,.89,.32,1.28) both;
  opacity: 1;
}
.ai-intro.is-leaving { animation: ai-intro-out .35s ease-in forwards; }
@keyframes ai-intro-in {
  0%   { opacity: 0; transform: translateY(28px) scale(.92); }
  60%  { opacity: 1; transform: translateY(-6px)  scale(1.03); }
  100% { opacity: 1; transform: translateY(0)    scale(1); }
}
@keyframes ai-intro-out {
  to { opacity: 0; transform: translateY(20px) scale(.95); }
}
.ai-intro-x {
  position: absolute; top: 10px; right: 12px;
  width: 28px; height: 28px; border-radius: 50%;
  border: none; background: rgba(15, 23, 42, .04); color: #475569;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: background-color .15s ease, color .15s ease;
}
.ai-intro-x:hover { background: rgba(15, 23, 42, .12); color: #0f172a; }
.ai-intro-x i { font-size: 16px; line-height: 1; }

.ai-intro-title {
  font-size: 17px; font-weight: 800;
  color: #1e3a8a; letter-spacing: -.3px;
  margin: 2px 0 6px;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  padding-right: 22px;
}
.ai-intro-title .ai-intro-title-brand {
  color: #2563eb;
  background: linear-gradient(90deg, #1e40af, #6366f1, #3b82f6);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.ai-intro-title .ai-intro-title-suffix { color: #6366f1; font-weight: 600; }

.ai-intro-body {
  font-size: 13px; line-height: 1.5;
  color: #475569;
  margin: 0 0 12px;
}
[data-bs-theme="dark"] .ai-intro {
  background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
  border-color: rgba(99, 102, 241, .35);
  box-shadow:
    0 24px 60px rgba(0, 0, 0, .55),
    0 0 0 1px rgba(99, 102, 241, .12) inset;
}
[data-bs-theme="dark"] .ai-intro-title       { color: #93c5fd; }
[data-bs-theme="dark"] .ai-intro-body        { color: #cbd5e1; }
[data-bs-theme="dark"] .ai-intro-x           { background: rgba(255, 255, 255, .06); color: #cbd5e1; }
[data-bs-theme="dark"] .ai-intro-x:hover     { background: rgba(255, 255, 255, .12); color: #fff; }
[data-bs-theme="dark"] .ai-intro-btn-secondary { background: rgba(255,255,255,.05); color: #cbd5e1; border-color: rgba(255,255,255,.10); }
[data-bs-theme="dark"] .ai-intro-btn-secondary:hover { background: rgba(255,255,255,.10); }

.ai-intro-actions {
  display: flex; gap: 10px; align-items: center;
  justify-content: space-between;
}
/* "AI is online" indicator — emerald dot with a soft expanding ring
   pulse so the visitor can tell at a glance the assistant is live
   (not a contact form).  Pure CSS, no JS heartbeat needed. */
.ai-intro-live {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11.5px; font-weight: 700; letter-spacing: .3px;
  color: #047857;
}
.ai-intro-live-dot {
  position: relative;
  width: 8px; height: 8px; border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 6px rgba(16, 185, 129, .55);
}
.ai-intro-live-dot::before {
  content: ''; position: absolute; inset: -2px;
  border-radius: 50%;
  background: rgba(16, 185, 129, .55);
  animation: ai-live-ring 1.6s ease-out infinite;
  z-index: -1;
}
@keyframes ai-live-ring {
  0%   { transform: scale(.85); opacity: .8; }
  100% { transform: scale(2.6); opacity: 0;  }
}
[data-bs-theme="dark"] .ai-intro-live { color: #6ee7b7; }
@media (prefers-reduced-motion: reduce) {
  .ai-intro-live-dot::before { animation: none; }
}
.ai-intro-btn {
  border: none; cursor: pointer;
  padding: 9px 18px; border-radius: 999px;
  font-weight: 700; font-size: 13px;
  font-family: inherit;
  transition: transform .15s ease, box-shadow .2s ease, filter .15s ease;
  white-space: nowrap;
  display: inline-flex; align-items: center;
}
.ai-intro-btn:active { transform: translateY(1px); }
.ai-intro-btn-secondary {
  background: #ffffff; color: #1e293b;
  border: 1px solid #e2e8f0;
}
.ai-intro-btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; }
.ai-intro-btn-primary {
  background: linear-gradient(135deg, #3b82f6 0%, #6366f1 60%, #8b5cf6 100%);
  color: #ffffff;
  box-shadow: 0 6px 20px rgba(99, 102, 241, .35);
}
.ai-intro-btn-primary:hover { filter: brightness(1.06); box-shadow: 0 8px 24px rgba(99, 102, 241, .45); }

/* Floating robot mascot — sits half-outside the card on the bottom right
   so it feels alive.  Subtle float animation. */
.ai-intro-bot {
  position: absolute;
  right: -18px; bottom: -22px;
  width: 76px; height: 76px;
  background: radial-gradient(closest-side, rgba(99, 102, 241, .35), rgba(99, 102, 241, 0));
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  animation: ai-bot-float 3.6s ease-in-out infinite;
  filter: drop-shadow(0 8px 18px rgba(99, 102, 241, .35));
  pointer-events: none;
}
.ai-intro-bot svg { width: 72px; height: 72px; }
@keyframes ai-bot-float {
  0%, 100% { transform: translateY(0)   rotate(-3deg); }
  50%      { transform: translateY(-6px) rotate(3deg); }
}
.ai-intro-bot svg { will-change: transform; }

/* Robot is visible when popup is open — hide chat bubble to avoid overlap. */
body.ai-intro-active #chat-bubble { display: none !important; }

/* Mobile — sit clear of the navbar + chat bubble, full-width minus gutter. */
@media (max-width: 520px) {
  .ai-intro { right: 12px; left: 12px; width: auto; bottom: 96px; padding: 18px 18px 16px; }
  .ai-intro-title { font-size: 19px; }
  .ai-intro-body  { font-size: 13.5px; }
  .ai-intro-bot   { right: -14px; bottom: -20px; width: 78px; height: 78px; }
  .ai-intro-actions { gap: 8px; }
  .ai-intro-btn   { padding: 10px 18px; font-size: 13px; }
}
/* Respect reduce-motion */
@media (prefers-reduced-motion: reduce) {
  .ai-intro, .ai-intro-bot { animation: none; }
}
</style>
<script>
(function () {
  // First-visit reveal + dismissal memory.  Don't show on checkout / cart
  // / order-success / order-history pages — the customer is mid-funnel,
  // and we don't want to distract them with a chat invite.
  var blockedScripts = ['checkout.php','cart.php','order-success.php','order-history.php','login.php','register.php','account.php'];
  var script = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
  if (blockedScripts.indexOf(script) !== -1) return;

  try {
    if (localStorage.getItem('mvt_ai_intro_dismissed') === '1') return;
  } catch (e) { /* private-mode browsers throw on localStorage; show anyway */ }

  var pop = document.getElementById('ai-intro-popup');
  if (!pop) return;
  // 2-second delay so the page has time to settle before we pop in.
  setTimeout(function () {
    if (document.hidden) return;
    // Don't show if the chat panel is already open (rare race).
    var chatPanel = document.getElementById('chat-panel');
    if (chatPanel && chatPanel.classList.contains('open')) return;
    pop.style.display = 'block';
    document.body.classList.add('ai-intro-active');
  }, 2000);
})();

function aiIntroDismiss() {
  var pop = document.getElementById('ai-intro-popup');
  if (!pop) return;
  pop.classList.add('is-leaving');
  try { localStorage.setItem('mvt_ai_intro_dismissed', '1'); } catch (e) {}
  setTimeout(function () {
    pop.style.display = 'none';
    pop.classList.remove('is-leaving');
    document.body.classList.remove('ai-intro-active');
  }, 380);
}
function aiIntroOpen() {
  aiIntroDismiss();
  // Defer opening so the dismiss animation runs first.
  setTimeout(function () {
    if (typeof toggleChat === 'function') toggleChat();
  }, 200);
}
</script>

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
      <span class="chat-avatar"><i class="bi bi-stars"></i></span>
      <div class="lh-sm">
        <div class="chat-head-name">Max · AI Assistant</div>
        <small class="chat-head-sub"><span class="chat-online-dot"></span>Online · typically replies in seconds</small>
      </div>
    </div>
    <button class="btn btn-sm btn-link p-0 text-white" onclick="toggleChat()" aria-label="Close chat" data-testid="chat-close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div id="chat-body">
    <div class="chat-msg bot" id="chat-welcome-msg" data-testid="chat-default-message">Hi there! I'm here to help with products, pricing, activation or anything else you need. What can I look up for you?</div>
    <div class="chat-chips" id="chat-chips" data-testid="chat-chips">
      <button class="chat-chip" onclick="quickAsk('Which Office is right for my Mac?')" data-testid="chat-chip-mac"><i class="bi bi-apple me-1"></i>Office for Mac</button>
      <button class="chat-chip" onclick="quickAsk('What is the best deal on Office 2024 right now?')" data-testid="chat-chip-deal"><i class="bi bi-tags me-1"></i>Best deals on Office 2024</button>
      <button class="chat-chip" onclick="quickAsk('How do I activate my license key after purchase?')" data-testid="chat-chip-activate"><i class="bi bi-key me-1"></i>Activation help</button>
      <button class="chat-chip" onclick="quickAsk('Do your licenses expire or need a subscription?')" data-testid="chat-chip-license"><i class="bi bi-infinity me-1"></i>License validity</button>
    </div>
    <div id="chat-lead-form" class="chat-lead-card" style="display:none;" data-testid="chat-lead-form">
      <div id="chat-lead-nudge" class="chat-lead-nudge" style="display:none;" data-testid="chat-lead-nudge">
        <i class="bi bi-lightning-charge-fill"></i>
        <span><strong>Don't lose this</strong> — agent on the way. Share your details so we don't miss you ↓</span>
      </div>
      <div class="chat-lead-title">Share your name, email and phone — we'll connect you with a live agent right away.</div>
      <input id="lead-name"  class="form-control form-control-sm chat-lead-input" placeholder="Full name"      data-testid="lead-name" autocomplete="name">
      <input id="lead-email" type="email" class="form-control form-control-sm chat-lead-input" placeholder="Email address" data-testid="lead-email" autocomplete="email">
      <input id="lead-phone" class="form-control form-control-sm chat-lead-input" placeholder="Phone number"   data-testid="lead-phone" autocomplete="tel">
      <button type="button" class="btn btn-sm chat-lead-cta-chat chat-lead-cta-primary" onclick="submitLead('chat')" data-testid="lead-chat-btn"><i class="bi bi-chat-dots-fill me-1"></i>Connect me with an agent</button>
      <a href="tel:<?= esc($brandPhone) ?>" class="btn btn-sm chat-lead-cta-alt" onclick="submitLead(false)" data-testid="lead-call-btn"><i class="bi bi-telephone me-1"></i>Or call us at <?= esc($brandPhone) ?></a>
    </div>
    <!-- ProAssist install-call scheduler card (hidden until JS detects a ProAssist lead). -->
    <div id="pa-sched-card" class="pa-sched-card" style="display:none;" data-testid="pa-sched-card">
      <div class="pa-sched-header">
        <i class="bi bi-calendar2-week"></i>
        <div>
          <div class="pa-sched-title" data-testid="pa-sched-title">Schedule your install call</div>
          <div class="pa-sched-sub" data-testid="pa-sched-sub">Pick a 30-minute slot — Mon-Sat · 9 AM – 6 PM EST</div>
        </div>
      </div>
      <div class="pa-sched-step" id="pa-sched-step-date">
        <div class="pa-sched-step-label">1. Choose a date</div>
        <div class="pa-sched-dates" id="pa-sched-dates" data-testid="pa-sched-dates"><!-- date pills injected by JS --></div>
      </div>
      <div class="pa-sched-step" id="pa-sched-step-time" style="display:none;">
        <div class="pa-sched-step-label">2. Choose a time <span class="pa-sched-tz">EST</span></div>
        <div class="pa-sched-times" id="pa-sched-times" data-testid="pa-sched-times"><!-- time pills injected by JS --></div>
        <button type="button" class="pa-sched-back" onclick="paSchedBackToDates()" data-testid="pa-sched-back"><i class="bi bi-arrow-left me-1"></i>Pick a different date</button>
      </div>
      <div class="pa-sched-error" id="pa-sched-error" style="display:none;" data-testid="pa-sched-error"></div>
    </div>
    <!-- ProAssist booked confirmation card (shown after booking, hides the picker). -->
    <div id="pa-sched-confirm" class="pa-sched-confirm" style="display:none;" data-testid="pa-sched-confirm">
      <div class="pa-sched-confirm-icon"><i class="bi bi-check2-circle"></i></div>
      <div class="pa-sched-confirm-title">Install call scheduled</div>
      <div class="pa-sched-confirm-when" id="pa-sched-confirm-when" data-testid="pa-sched-confirm-when">—</div>
      <button type="button" class="pa-sched-reschedule" onclick="paSchedReschedule()" data-testid="pa-sched-reschedule"><i class="bi bi-arrow-repeat me-1"></i>Reschedule</button>
    </div>
  </div>
  <div id="chat-typing" class="chat-typing" style="display:none;" data-testid="chat-admin-typing">
    <div class="chat-typing-bubble">
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-text">Live agent is typing…</span>
    </div>
  </div>
  <form id="chat-input-row" class="chat-input-row d-flex align-items-center gap-2 p-2" onsubmit="sendChat(event)" style="display:none;" data-testid="chat-input-row">
    <input id="chat-input" class="form-control form-control-sm chat-input" placeholder="Type a message…" autocomplete="off" data-testid="chat-input">
    <button class="btn chat-send-btn" type="submit" aria-label="Send" data-testid="chat-send"><i class="bi bi-send-fill"></i></button>
  </form>
  <div class="chat-talk-band" data-testid="chat-talk-band"><i class="bi bi-headset me-1"></i>Prefer to talk?<span class="ttf-sep">·</span><?= esc(SITE_HOURS) ?><span class="ttf-sep">·</span><a href="tel:<?= esc($brandPhone) ?>"><?= esc($brandPhone) ?></a></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
