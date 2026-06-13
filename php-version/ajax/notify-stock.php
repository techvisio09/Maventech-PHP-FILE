<?php
// AJAX endpoint: subscribe a customer to "back in stock" notifications for a
// specific product (current region). Publicly callable (no admin auth).
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$in   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$slug = trim((string)($in['product_slug'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));

if ($slug === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing product or email']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

$pdo    = db();
$region = active_region_code();

// Confirm product exists
$pst = $pdo->prepare('SELECT slug, name FROM products WHERE slug = ?');
$pst->execute([$slug]);
$product = $pst->fetch();
if (!$product) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product not found']);
    exit;
}

// De-dupe: if this email is already pending (notified_at IS NULL) for the same
// product+region, just respond success without inserting again.
try {
    $dup = $pdo->prepare("SELECT id FROM stock_notifications
                          WHERE product_slug=? AND email=? AND region=? AND notified_at IS NULL
                          LIMIT 1");
    $dup->execute([$slug, $email, $region]);
    if ($dup->fetchColumn()) {
        echo json_encode([
            'ok'      => true,
            'already' => true,
            'message' => "You're already on the list — we'll email you the moment it's back in stock.",
        ]);
        exit;
    }

    $pdo->prepare("INSERT INTO stock_notifications (product_slug, email, region, created_at)
                   VALUES (?,?,?,NOW())")
        ->execute([$slug, $email, $region]);

    echo json_encode([
        'ok'      => true,
        'already' => false,
        'message' => "Thanks! We'll email " . $email . " the moment " . $product['name'] . " is back in stock.",
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save your request. Please try again.']);
}
