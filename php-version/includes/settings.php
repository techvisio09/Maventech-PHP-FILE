<?php
// Settings helpers — used by admin template editor + email sender + checkout.
function setting_get(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = db()->query('SELECT k,v FROM settings')->fetchAll();
            $cache = [];
            foreach ($rows as $r) $cache[$r['k']] = $r['v'];
        } catch (Throwable $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, string $val): void {
    db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$key,$val]);
}

function paypal_enabled(): bool {
    // PayPal is shown only when explicitly enabled AND a PAYPAL_CLIENT_ID is configured.
    $envKey = getenv('PAYPAL_CLIENT_ID') ?: (defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : '');
    return setting_get('paypal_enabled','0') === '1' && $envKey !== '';
}

function statement_name_for(string $payment_method): string {
    $key = $payment_method === 'paypal' ? 'statement_name_paypal' : 'statement_name_card';
    return setting_get($key, SITE_LEGAL);
}
