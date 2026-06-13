// UCODE SOFTTECH Store - shared JS (theme, cart, chat, checkout)

/* ---------- Dark mode ---------- */
(function () {
  const saved = localStorage.getItem('uc_theme') || 'light';
  document.documentElement.setAttribute('data-bs-theme', saved);
})();
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-bs-theme', next);
  localStorage.setItem('uc_theme', next);
  syncThemeIcon();
}
function syncThemeIcon() {
  const icon = document.getElementById('theme-icon');
  if (icon) icon.className = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
}
document.addEventListener('DOMContentLoaded', syncThemeIcon);

/* ---------- Toast (rich notification card) ---------- */
function showToast(msg, opts = {}) {
  let wrap = document.getElementById('toast-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'toast-wrap';
    wrap.className = 'mv-toast-wrap';
    document.body.appendChild(wrap);
  }
  const el = document.createElement('div');
  el.className = 'mv-toast';
  el.setAttribute('data-testid', opts.title ? 'rich-toast' : 'toast');
  const ttl = opts.duration || 3200;
  el.style.setProperty('--toast-ttl', ttl + 'ms');
  el.innerHTML =
    (opts.icon ? '<span class="mv-toast-icon">' + opts.icon + '</span>' : '') +
    '<div class="mv-toast-body">' +
      (opts.title ? '<div class="mv-toast-title">' + opts.title + '</div>' : '') +
      '<div class="mv-toast-msg">' + msg + '</div>' +
      (opts.actionHref
        ? '<a href="' + opts.actionHref + '" class="mv-toast-action" data-testid="toast-open-cart">' + opts.actionLabel + ' <i class="bi bi-arrow-right"></i></a>'
        : '') +
    '</div>' +
    '<button class="mv-toast-close" aria-label="Dismiss" data-testid="toast-close"><i class="bi bi-x-lg"></i></button>' +
    '<span class="mv-toast-progress"></span>';
  wrap.appendChild(el);
  let timer;
  const dismiss = () => {
    clearTimeout(timer);
    el.classList.add('hide');
    setTimeout(() => el.remove(), 320);
  };
  el.querySelector('.mv-toast-close').addEventListener('click', dismiss);
  timer = setTimeout(dismiss, ttl);
}

/* ---------- Cart ---------- */
async function cartAction(payload) {
  const res = await fetch('ajax/cart.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  return res.json();
}

function updateCartBadge(count) {
  document.querySelectorAll('.cart-count-badge').forEach((b) => {
    b.textContent = count;
    b.classList.toggle('d-none', count === 0);
    // brief bounce so the cart is noticed (especially on mobile)
    b.classList.remove('cart-bump');
    void b.offsetWidth;
    b.classList.add('cart-bump');
  });
}

/* Added-to-cart button state */
function markAdded(btn) {
  btn.classList.add('added');
  btn.dataset.added = '1';
  const big = btn.classList.contains('btn-lg');
  btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>' + (big ? 'Added to Cart — View' : 'Added');
  btn.title = 'Already in your cart — click to view';
}

document.addEventListener('DOMContentLoaded', () => {
  const inCart = window.CART_SLUGS || [];
  document.querySelectorAll('.add-to-cart-btn').forEach((b) => {
    if (inCart.includes(b.dataset.slug)) markAdded(b);
  });
});

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.add-to-cart-btn');
  if (btn) {
    e.preventDefault();
    if (btn.dataset.added) { window.location.href = 'cart.php'; return; }
    const qty = parseInt(btn.dataset.qty || document.getElementById('pd-qty')?.value || '1', 10);
    const data = await cartAction({ action: 'add', slug: btn.dataset.slug, qty });
    updateCartBadge(data.count);
    markAdded(btn);
    if (window.CART_SLUGS && !window.CART_SLUGS.includes(btn.dataset.slug)) window.CART_SLUGS.push(btn.dataset.slug);
    showToast('Open the cart to review your items, or keep shopping.', {
      title: 'Added to cart!',
      icon: '<i class="bi bi-bag-check-fill"></i>',
      actionHref: 'cart.php',
      actionLabel: 'Open Cart',
      duration: 4500,
    });
    return;
  }
  const buy = e.target.closest('.buy-now-btn');
  if (buy) {
    e.preventDefault();
    // Buy Now semantics: set the cart line to EXACTLY the selected qty (1 by default).
    // Clicking Buy Now repeatedly never accumulates extra units.
    const qty = parseInt(buy.dataset.qty || document.getElementById('pd-qty')?.value || '1', 10);
    await cartAction({ action: 'set', slug: buy.dataset.slug, qty });
    window.location.href = 'cart.php';
  }
});

// Cart page qty / remove
document.addEventListener('click', async (e) => {
  const qbtn = e.target.closest('[data-cart-qty]');
  if (qbtn) {
    const data = await cartAction({ action: 'update', slug: qbtn.dataset.slug, qty: parseInt(qbtn.dataset.cartQty, 10) });
    updateCartBadge(data.count);
    location.reload();
    return;
  }
  const rbtn = e.target.closest('[data-cart-remove]');
  if (rbtn) {
    const data = await cartAction({ action: 'remove', slug: rbtn.dataset.cartRemove });
    updateCartBadge(data.count);
    location.reload();
  }
});

/* ---------- Newsletter + coupon ---------- */
function subscribeNewsletter(ev) {
  ev.preventDefault();
  const input = ev.target.querySelector('input[type="email"]');
  if (!input || !input.value) return;
  showToast('<i class="bi bi-check-circle me-1"></i> Subscribed! You\'ll receive exclusive deals soon.');
  input.value = '';
}

async function applyCoupon(code) {
  const data = await cartAction({ action: 'coupon', code: code || '' });
  if (data.ok) {
    showToast(data.coupon ? '<i class="bi bi-tag-fill me-1"></i> Coupon applied — ' + (data.pct || '') + '% off!' : 'Coupon removed.');
    location.reload();
  } else {
    showToast('<i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Invalid coupon code'));
  }
}

/* ---------- Bootstrap tooltips + coupon Enter-key guard ---------- */
document.addEventListener('DOMContentLoaded', () => {
  if (window.bootstrap) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));
  }
  // Coupon input lives inside the checkout form — Enter must apply the coupon,
  // not submit the whole checkout form silently.
  const couponInput = document.getElementById('coupon-input');
  if (couponInput) {
    couponInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        applyCoupon(couponInput.value);
      }
    });
  }
});

/* ---------- Checkout payment method toggle ---------- */
function syncPhoneFlag(sel) {
  const flag = document.getElementById('phone-flag');
  const opt = sel.options[sel.selectedIndex];
  if (flag && opt) flag.textContent = opt.dataset.flag || '🇺🇸';
}

/* Card field formatting: number groups of 4, MM/YY expiry, numeric CVV + live brand detect */
function detectCardBrand(digits) {
  if (digits.startsWith('4')) return 'visa';
  if (digits.startsWith('5')) return 'mastercard';
  if (digits.startsWith('3')) return 'amex';
  if (digits.startsWith('6')) return 'discover';
  return '';
}

document.addEventListener('input', (e) => {
  if (e.target.id === 'card-number') {
    const digits = e.target.value.replace(/\D/g, '').slice(0, 16);
    e.target.value = digits.replace(/(\d{4})(?=\d)/g, '$1 ');
    const brand = digits.length ? detectCardBrand(digits) : '';
    document.querySelectorAll('#card-brands .card-brand-icon').forEach((i) => {
      i.classList.toggle('active', i.dataset.brand === brand);
      i.classList.toggle('dimmed', brand !== '' && i.dataset.brand !== brand);
    });
  } else if (e.target.id === 'card-exp') {
    let v = e.target.value.replace(/\D/g, '').slice(0, 4);
    if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2);
    e.target.value = v;
  } else if (e.target.id === 'card-cvv') {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
  }
});

function selectPayMethod(method) {
  document.querySelectorAll('.pay-option').forEach((o) => o.classList.remove('active'));
  const opt = document.getElementById('pay-' + method);
  if (opt) opt.classList.add('active');
  const cardForm = document.getElementById('card-form');
  const paypalInfo = document.getElementById('paypal-info');
  const cardBtn = document.getElementById('btn-pay-card');
  const ppBtn = document.getElementById('btn-pay-paypal');
  const input = document.getElementById('payment-method-input');
  if (input) input.value = method;
  if (cardForm) cardForm.classList.toggle('d-none', method !== 'card');
  if (paypalInfo) paypalInfo.classList.toggle('d-none', method !== 'paypal');
  if (cardBtn) cardBtn.classList.toggle('d-none', method !== 'card');
  if (ppBtn) ppBtn.classList.toggle('d-none', method !== 'paypal');
}

/* ---------- Ask AI chat widget ---------- */
function toggleChat() {
  const panel = document.getElementById('chat-panel');
  panel.classList.toggle('open');
  if (panel.classList.contains('open') && !localStorage.getItem('uc_lead_done')) {
    const form = document.getElementById('chat-lead-form');
    if (form) form.style.display = 'block';
  }
}

function leadValues() {
  return {
    name: document.getElementById('lead-name').value.trim(),
    email: document.getElementById('lead-email').value.trim(),
    phone: document.getElementById('lead-phone').value.trim(),
  };
}

async function submitLead(callback) {
  const v = leadValues();
  if (!v.name || !/\S+@\S+\.\S+/.test(v.email) || v.phone.length < 7) {
    showToast('Please fill in your name, email and phone.');
    return;
  }
  let sid = localStorage.getItem('uc_chat_session');
  if (!sid) { sid = 's' + Date.now() + Math.random().toString(36).slice(2, 8); localStorage.setItem('uc_chat_session', sid); }
  try {
    await fetch('ajax/lead.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: sid, callback_requested: !!callback, ...v }),
    });
  } catch (e) { /* best-effort */ }
  localStorage.setItem('uc_lead_done', '1');
  document.getElementById('chat-lead-form').style.display = 'none';
  if (callback) {
    chatAppend('user', 'Name: ' + v.name + '\nEmail: ' + v.email + '\nPhone: ' + v.phone + '\n→ Requested a callback');
    chatAppend('bot', '✅ You\'re now connected with our live support team.');
    chatAppend('bot', '📞 Our agent is going to call you shortly — please be near your phone (' + v.phone + ').\n\nIn the meantime, I can help you right here with purchase assistance or the installation guide. What do you need?');
  } else {
    chatAppend('bot', 'Great, ' + v.name.split(' ')[0] + '! Call us now at ' + (window.SITE_PHONE || '') + ' and our team will assist you right away. I\'m also here if you\'d like to chat.');
  }
}

function skipLead() {
  localStorage.setItem('uc_lead_done', '1');
  document.getElementById('chat-lead-form').style.display = 'none';
  chatAppend('bot', 'No problem! Ask me anything — products, pricing, installation, activation… I\'m here to help. 😊');
}

function chatAppend(role, text) {
  const body = document.getElementById('chat-body');
  const div = document.createElement('div');
  div.className = 'chat-msg ' + role;
  div.textContent = text;
  body.appendChild(div);
  body.scrollTop = body.scrollHeight;
  return div;
}

function quickAsk(text) {
  const chips = document.getElementById('chat-chips');
  if (chips) chips.remove();
  const input = document.getElementById('chat-input');
  input.value = text;
  sendChat(new Event('submit'));
}

async function sendChat(ev) {
  ev.preventDefault();
  const input = document.getElementById('chat-input');
  const msg = input.value.trim();
  if (!msg) return;
  input.value = '';
  chatAppend('user', msg);
  const typing = chatAppend('bot', '');
  typing.classList.add('typing');
  typing.innerHTML = '<span></span><span></span><span></span>';
  let sid = localStorage.getItem('uc_chat_session');
  if (!sid) { sid = 's' + Date.now() + Math.random().toString(36).slice(2, 8); localStorage.setItem('uc_chat_session', sid); }
  try {
    const res = await fetch('ajax/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg, session_id: sid }),
    });
    const data = await res.json();
    typing.classList.remove('typing');
    typing.textContent = data.reply;
  } catch (err) {
    typing.classList.remove('typing');
    typing.textContent = 'Sorry, something went wrong. Call us at ' + (window.SITE_PHONE || '1-888-632-9902') + ' or email us.';
  }
}

/* ---------- Scroll reveal (staggered entrance animations) ---------- */
(() => {
  if (!('IntersectionObserver' in window) ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const cols = document.querySelectorAll('section .row > [class*="col-"], .accordion-item');
  cols.forEach((el) => {
    const idx = Array.prototype.indexOf.call(el.parentElement.children, el);
    el.classList.add('reveal');
    el.style.transitionDelay = `${Math.min(idx, 5) * 70}ms`;
  });
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    });
  }, { threshold: 0.06, rootMargin: '0px 0px -30px 0px' });
  document.querySelectorAll('.reveal').forEach((el) => io.observe(el));
})();

/* ---------- Limited-time deal bar: live countdown to local midnight ---------- */
(() => {
  const bar = document.getElementById('deal-bar');
  if (!bar) return;
  if (sessionStorage.getItem('uc_dealbar_dismissed') === '1') { bar.remove(); return; }
  document.body.classList.add('has-deal-bar');
  bar.querySelector('.deal-close').addEventListener('click', () => {
    sessionStorage.setItem('uc_dealbar_dismissed', '1');
    bar.remove();
    document.body.classList.remove('has-deal-bar');
  });
  const out = document.getElementById('deal-countdown');
  const pad = (n) => String(n).padStart(2, '0');
  const tick = () => {
    const now = new Date();
    const end = new Date(now);
    end.setHours(24, 0, 0, 0); // resets daily at local midnight
    let s = Math.max(0, Math.floor((end - now) / 1000));
    const h = Math.floor(s / 3600); s %= 3600;
    out.textContent = pad(h) + ':' + pad(Math.floor(s / 60)) + ':' + pad(s % 60);
  };
  tick();
  setInterval(tick, 1000);
})();
/* ---------- Product page: 360° viewer (sway + cursor tilt + drag-to-spin) ---------- */
(() => {
  const frame = document.querySelector('.pd-360-frame');
  if (!frame || !window.matchMedia('(prefers-reduced-motion: no-preference)').matches) return;
  const img = frame.querySelector('.pd-360-img');
  let dragging = false, startX = 0, baseRy = 0, ry = 0;
  frame.addEventListener('pointerdown', (e) => {
    dragging = true; startX = e.clientX; baseRy = ry;
    frame.classList.add('dragging');
    frame.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  frame.addEventListener('pointermove', (e) => {
    if (dragging) {
      ry = baseRy + (e.clientX - startX) * 0.9; // full 360° spin with drag
      img.style.setProperty('--ry', ry.toFixed(1) + 'deg');
      img.style.setProperty('--rx', '0deg');
    } else {
      const r = frame.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      frame.classList.add('tilting');
      ry = x * 46;
      img.style.setProperty('--ry', ry.toFixed(1) + 'deg');
      img.style.setProperty('--rx', (-y * 18).toFixed(1) + 'deg');
    }
  });
  const endDrag = () => { dragging = false; frame.classList.remove('dragging'); };
  frame.addEventListener('pointerup', endDrag);
  frame.addEventListener('pointercancel', endDrag);
  frame.addEventListener('pointerleave', () => { endDrag(); frame.classList.remove('tilting'); ry = 0; });
})();

/* ---------- Hero: big product icons cycle one-by-one ---------- */
(() => {
  const icons = document.querySelectorAll('.hero-big-icon');
  if (icons.length < 2) return;
  let i = 0;
  setInterval(() => {
    icons[i].classList.remove('active');
    i = (i + 1) % icons.length;
    icons[i].classList.add('active');
  }, 3000);
})();

/* ---------- Premium 360° tilt: hero showcase, brand logo, product cards ---------- */
(() => {
  if (!window.matchMedia('(prefers-reduced-motion: no-preference)').matches) return;

  // Hero: cursor-follow tilt over the abstract showcase panel
  const stage = document.querySelector('.hero-stage');
  const frame = document.querySelector('.hero-showcase-frame');
  if (stage && frame) {
    frame.addEventListener('pointermove', (e) => {
      const r = frame.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      stage.classList.add('tilting');
      stage.style.setProperty('--ry', (x * 38).toFixed(1) + 'deg');
      stage.style.setProperty('--rx', (-y * 22).toFixed(1) + 'deg');
    });
    frame.addEventListener('pointerleave', () => stage.classList.remove('tilting'));
  }

  // Generic mouse-tracking tilt (logo + product cards)
  const bindTilt = (el, maxY, maxX) => {
    el.addEventListener('pointermove', (e) => {
      const r = el.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      el.classList.add('tilting');
      el.style.setProperty('--ry', (x * maxY).toFixed(1) + 'deg');
      el.style.setProperty('--rx', (-y * maxX).toFixed(1) + 'deg');
    });
    el.addEventListener('pointerleave', () => el.classList.remove('tilting'));
  };
  document.querySelectorAll('.logo-3d').forEach((el) => bindTilt(el, 70, 50));
  document.querySelectorAll('.product-card.tilt-3d').forEach((el) => bindTilt(el, 16, 12));
})();

