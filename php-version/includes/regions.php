<?php
// Region helpers — multi-region inventory + per-region pricing/tax/currency.

function active_region_code(): string {
    if (isset($_GET['region'])) {
        $r = strtoupper(preg_replace('/[^A-Z]/i', '', $_GET['region']));
        if ($r) {
            $_SESSION['region'] = $r;
            setting_set('active_region', $r);
        }
    }
    return $_SESSION['region'] ?? setting_get('active_region', 'US');
}

function active_region(): array {
    $code = active_region_code();
    $row = db()->prepare('SELECT * FROM regions WHERE code = ?');
    $row->execute([$code]);
    $r = $row->fetch();
    if ($r) return $r;
    return ['code'=>'US','name'=>'United States','currency'=>'USD','currency_symbol'=>'$','tax_rate'=>0,'active'=>1];
}

function all_regions(): array {
    return db()->query('SELECT * FROM regions WHERE active=1 ORDER BY code')->fetchAll();
}

function region_money(float $amount): string {
    $r = active_region();
    return $r['currency_symbol'] . number_format($amount, 2);
}

function region_filter_sql(string $alias = ''): string {
    $pre = $alias === '' ? '' : ($alias . '.');
    return $pre . "region = " . db()->quote(active_region_code());
}
