<?php
// Cart AJAX API: add / update / remove / count
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$slug = $in['slug'] ?? '';

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

if ($action === 'add' && $slug && get_product($slug)) {
    $qty = max(1, (int)($in['qty'] ?? 1));
    $cur = (int)($_SESSION['cart'][$slug] ?? 0);
    $stock = available_keys_count($slug);
    if ($stock <= 0) {
        echo json_encode(['ok' => false, 'error' => 'This product is currently out of stock.', 'count' => cart_count()]);
        exit;
    }
    // Cap final cart line at available stock
    $newQty = min($stock, $cur + $qty);
    $capped = ($newQty < $cur + $qty);
    $_SESSION['cart'][$slug] = $newQty;
    if ($capped) {
        echo json_encode([
            'ok'      => true,
            'capped'  => true,
            'qty'     => $newQty,
            'stock'   => $stock,
            'message' => "Only {$stock} unit" . ($stock===1?'':'s') . " available — cart updated to {$newQty}.",
            'count'   => cart_count(),
        ]);
        exit;
    }
} elseif ($action === 'update' && $slug && isset($_SESSION['cart'][$slug])) {
    $qty = (int)($in['qty'] ?? 1);
    if ($qty <= 0) {
        unset($_SESSION['cart'][$slug]);
    } else {
        $stock = available_keys_count($slug);
        if ($stock <= 0) {
            unset($_SESSION['cart'][$slug]);
            echo json_encode(['ok' => false, 'error' => 'This product is now out of stock.', 'count' => cart_count()]);
            exit;
        }
        $capped = $qty > $stock;
        $_SESSION['cart'][$slug] = min($stock, $qty);
        if ($capped) {
            echo json_encode([
                'ok'      => true,
                'capped'  => true,
                'qty'     => $_SESSION['cart'][$slug],
                'stock'   => $stock,
                'message' => "Only {$stock} unit" . ($stock===1?'':'s') . " available.",
                'count'   => cart_count(),
            ]);
            exit;
        }
    }
} elseif ($action === 'remove' && $slug) {
    unset($_SESSION['cart'][$slug]);
} elseif ($action === 'coupon') {
    $code = strtoupper(trim($in['code'] ?? ''));
    if ($code === '') {
        unset($_SESSION['coupon'], $_SESSION['coupon_pct']);
        echo json_encode(['ok' => true, 'coupon' => null, 'count' => cart_count()]);
        exit;
    }
    $valid = coupons();
    if (isset($valid[$code])) {
        $_SESSION['coupon'] = $code;
        $_SESSION['coupon_pct'] = $valid[$code];
        echo json_encode(['ok' => true, 'coupon' => $code, 'pct' => $valid[$code], 'count' => cart_count()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid coupon code', 'count' => cart_count()]);
    }
    exit;
}

echo json_encode(['ok' => true, 'count' => cart_count()]);
