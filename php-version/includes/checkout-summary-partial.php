<?php
/**
 * Order-summary partial — used by checkout.php for the initial render
 * AND by ajax/checkout-summary.php to refresh the summary in-place when
 * the customer applies a coupon or changes the quantity. Re-using the
 * exact same PHP avoids drift between the first render and refreshes.
 *
 * Expected in scope:
 *   $items, $proAssist, $subtotal, $savings,
 *   $couponCode, $couponPct, $discount, $total
 */
if (!isset($items)) { return; }
?>
<span class="co-watermark" aria-hidden="true"><?= render_logo(300) ?></span>
<span class="co-banner-secure badge rounded-pill text-bg-success-subtle text-success border border-success-subtle position-absolute top-0 end-0 m-3" style="z-index:2;"><i class="bi bi-lock-fill me-1"></i>Secure Checkout</span>
<a href="index.php" class="logo-3d d-inline-flex align-items-center justify-content-center gap-2 mx-auto mb-2 text-decoration-none" data-testid="co-banner-brand">
  <?= render_logo(36) ?>
  <span>
    <span class="brand-text d-block lh-1">Maventech <span class="brand-grad">Software</span></span>
    <small class="brand-tag">AUTHORIZED RESELLER</small>
  </span>
</a>
<small class="text-secondary">Pay <?= SITE_LEGAL ?></small>
<div class="receipt-amount fw-bold" data-testid="checkout-amount-banner"><?= format_price($total) ?></div>
<small class="text-secondary mb-2"><?= count($items) + ($proAssist ? 1 : 0) ?> item<?= (count($items) + ($proAssist ? 1 : 0)) !== 1 ? 's' : '' ?> · Instant digital delivery</small>
<hr class="my-2">
<?php foreach ($items as $i): ?>
  <div class="d-flex gap-2 mb-2 align-items-center" data-testid="summary-item-<?= esc($i['slug']) ?>">
    <img src="<?= esc($i['image']) ?>" alt="<?= esc($i['name']) ?> — lifetime license key | <?= SITE_BRAND ?>" style="width:40px;height:40px;object-fit:contain;" class="bg-body-tertiary rounded p-1">
    <div class="flex-grow-1">
      <div class="small fw-semibold"><?= esc($i['name']) ?></div>
      <div class="d-flex justify-content-between align-items-center mt-1">
        <div class="input-group input-group-sm" style="width: 96px;">
          <button type="button" class="btn btn-outline-secondary px-2" data-cart-qty="<?= $i['qty'] - 1 ?>" data-slug="<?= esc($i['slug']) ?>">−</button>
          <span class="form-control text-center px-1"><?= (int)$i['qty'] ?></span>
          <button type="button" class="btn btn-outline-secondary px-2" data-cart-qty="<?= $i['qty'] + 1 ?>" data-slug="<?= esc($i['slug']) ?>">+</button>
        </div>
        <span class="fw-bold text-primary small"><?= format_price($i['price'] * $i['qty']) ?></span>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if ($proAssist): ?>
  <div class="d-flex gap-2 mb-3 border-top pt-3 align-items-center">
    <span class="logo-mark" style="width:48px;height:48px;"><i class="bi bi-headset"></i></span>
    <div class="flex-grow-1">
      <div class="small fw-semibold">ProAssist Premium Installation</div>
      <div class="d-flex justify-content-between small"><span class="text-secondary">Qty 1</span><span class="fw-bold text-primary"><?= format_price(PRO_ASSIST_PRICE) ?></span></div>
    </div>
  </div>
<?php endif; ?>
<hr class="my-2">
<!-- Coupon -->
<?php if ($couponCode): ?>
  <div class="d-flex justify-content-between align-items-center small mb-2 text-success" data-testid="coupon-applied">
    <span><i class="bi bi-tag-fill me-1"></i>Coupon <strong><?= esc($couponCode) ?></strong> applied</span>
    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="applyCoupon('')" data-testid="coupon-remove">Remove</button>
  </div>
<?php else: ?>
  <div class="input-group input-group-sm mb-2" style="max-width: 320px;">
    <input id="coupon-input" class="form-control" placeholder="Coupon code" data-testid="coupon-input">
    <button type="button" class="btn btn-outline-primary" onclick="applyCoupon(document.getElementById('coupon-input').value)" data-testid="coupon-apply">Apply</button>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between small mb-2"><span class="text-secondary">Subtotal</span><span class="fw-semibold"><?= format_price($subtotal) ?></span></div>
<?php if ($savings > 0): ?>
  <div class="d-flex justify-content-between small mb-2 text-success"><span>You Save</span><span data-testid="checkout-savings">-<?= format_price($savings) ?></span></div>
<?php endif; ?>
<?php if ($discount > 0): ?>
  <div class="d-flex justify-content-between small mb-2 text-success"><span>Coupon (<?= esc($couponCode) ?> — <?= $couponPct ?>% off)</span><span data-testid="checkout-discount">-<?= format_price($discount) ?></span></div>
<?php endif; ?>
<div class="summary-total d-flex justify-content-between align-items-center p-2 px-3 rounded-3 mt-1">
  <span class="fw-bold">Total</span><span class="fw-bold text-primary fs-5" data-testid="checkout-total"><?= format_price($total) ?></span>
</div>
