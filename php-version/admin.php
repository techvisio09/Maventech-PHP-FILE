<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/regions.php';
require_once __DIR__ . '/includes/mailer.php';
ensure_admin();
$admin = require_admin();
$pdo = db();
// Drain the email queue on every admin page load (no cron required for low-volume sites)
try { smtp_process_queue(3); } catch (Throwable $e) { /* never block the UI */ }
$tab = $_GET['tab'] ?? 'dashboard';
// Legacy `keys` tab merged into Products tab — keep URLs working
if ($tab === 'keys') { header('Location: admin.php?tab=products'); exit; }
$flash = $_GET['msg'] ?? '';
$rg = active_region();
$region_code = active_region_code();

// =========================================================================
// POST ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_product') {
        $pdo->prepare('UPDATE products SET name=?, sku=?, brand=?, year=?, platform=?, category=?, license_type=?,
            price=?, original_price=?, badge=?, description=?, is_active=?, activation_url=?, install_guide_url=?, image=COALESCE(NULLIF(?,""),image) WHERE slug=?')
            ->execute([
                trim($_POST['name']), trim($_POST['sku']), trim($_POST['brand']) ?: null,
                $_POST['year']!==''?(int)$_POST['year']:null, $_POST['platform'], $_POST['category'],
                $_POST['license_type'], (float)$_POST['price'],
                $_POST['original_price']!==''?(float)$_POST['original_price']:null,
                trim($_POST['badge']) ?: null, trim($_POST['description'] ?? '') ?: null,
                isset($_POST['is_active']) ? 1 : 0,
                trim($_POST['activation_url'] ?? '') ?: null,
                trim($_POST['install_guide_url'] ?? '') ?: null,
                trim($_POST['image'] ?? ''), $_POST['slug']
            ]);
        header('Location: admin.php?tab=products&edit='.urlencode($_POST['slug']).'&msg=Saved'); exit;

    } elseif ($action === 'add_product') {
        $slug = preg_replace('/[^a-z0-9]+/i','-', strtolower(trim($_POST['name']))) . '-' . substr(md5(uniqid()),0,5);
        $pdo->prepare('INSERT INTO products (slug,name,sku,brand,year,platform,category,license_type,price,original_price,badge,description,image,is_active,activation_url,install_guide_url,region,apps,rating,reviews) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,4.5,0)')
            ->execute([$slug, trim($_POST['name']), trim($_POST['sku']) ?: 'SKU-'.strtoupper(substr(md5($slug),0,8)), trim($_POST['brand']) ?: null,
                $_POST['year']!==''?(int)$_POST['year']:null, $_POST['platform'], $_POST['category'], $_POST['license_type'],
                (float)$_POST['price'], $_POST['original_price']!==''?(float)$_POST['original_price']:null,
                trim($_POST['badge']) ?: null, trim($_POST['description'] ?? '') ?: null, trim($_POST['image'] ?? '') ?: null,
                isset($_POST['is_active']) ? 1 : 0, trim($_POST['activation_url'] ?? '') ?: null, trim($_POST['install_guide_url'] ?? '') ?: null, $region_code, '']);
        header('Location: admin.php?tab=products&edit='.urlencode($slug).'&msg=Product+created'); exit;

    } elseif ($action === 'duplicate_product') {
        $src = $pdo->prepare('SELECT * FROM products WHERE slug=?'); $src->execute([$_POST['slug']]); $s = $src->fetch();
        if ($s) {
            $newSlug = $s['slug'] . '-copy-' . substr(md5(uniqid()),0,4);
            $pdo->prepare('INSERT INTO products (slug,name,sku,brand,year,platform,category,license_type,price,original_price,badge,description,image,is_active,region,apps,rating,reviews) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$newSlug, $s['name'].' (copy)', 'SKU-'.strtoupper(substr(md5($newSlug),0,8)),
                    $s['brand'], $s['year'], $s['platform'], $s['category'], $s['license_type'],
                    $s['price'], $s['original_price'], $s['badge'], $s['description'], $s['image'],
                    $s['is_active'], $region_code, $s['apps'], $s['rating'], 0]);
        }
        header('Location: admin.php?tab=products&msg=Product+duplicated'); exit;

    } elseif ($action === 'delete_product') {
        $pdo->prepare('DELETE FROM products WHERE slug=?')->execute([$_POST['slug']]);
        header('Location: admin.php?tab=products&msg=Product+deleted'); exit;

    } elseif ($action === 'toggle_product') {
        $pdo->prepare('UPDATE products SET is_active=1-is_active WHERE slug=?')->execute([$_POST['slug']]);
        header('Location: admin.php?tab=products&msg=Status+toggled'); exit;

    } elseif ($action === 'move_product') {
        $pdo->prepare('UPDATE products SET category=? WHERE slug=?')->execute([$_POST['category'], $_POST['slug']]);
        header('Location: admin.php?tab=products&msg=Moved'); exit;

    } elseif ($action === 'ai_autofill_urls') {
        // ---------------------------------------------------------------------
        // AI Auto-fill activation_url + install_guide_url for ALL products with
        // empty fields. Uses Emergent LLM key via OpenAI-compatible endpoint.
        // Single batched prompt — gpt-4o for accuracy.
        // ---------------------------------------------------------------------
        $onlyMissing = !empty($_POST['only_missing']);
        $wh = "WHERE region=?";
        if ($onlyMissing) $wh .= " AND (activation_url IS NULL OR activation_url='' OR install_guide_url IS NULL OR install_guide_url='')";
        $st = $pdo->prepare("SELECT slug, name, brand FROM products $wh ORDER BY id");
        $st->execute([$region_code]);
        $prods = $st->fetchAll();

        if (empty($prods)) {
            header('Location: admin.php?tab=products&msg=All+products+already+have+URLs+filled'); exit;
        }
        if (!OPENAI_API_KEY) {
            header('Location: admin.php?tab=products&msg=AI+key+missing+%E2%80%94+configure+EMERGENT_LLM_KEY'); exit;
        }

        $items = [];
        foreach ($prods as $p) {
            $items[] = ['slug'=>$p['slug'], 'name'=>$p['name'], 'brand'=>$p['brand'] ?? ''];
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_SLASHES);

        $prompt = "You are an expert in software licensing portals. For each product below, return the OFFICIAL vendor URLs:\n"
                . "1. \"activation_url\" — the official sign-in / activation page where the customer enters their license key (e.g. https://setup.office.com for Microsoft Office, https://central.bitdefender.com for Bitdefender).\n"
                . "2. \"install_guide_url\" — the official installation help / KB article URL from the vendor.\n\n"
                . "RULES:\n"
                . "- Only use real, current, vendor-official domains (microsoft.com, bitdefender.com, mcafee.com, norton.com, adobe.com, etc.). NO third-party sites.\n"
                . "- If unsure, use the most authoritative vendor support landing page.\n"
                . "- Return STRICT JSON only, no markdown, no preamble. Schema: {\"results\":[{\"slug\":\"...\",\"activation_url\":\"https://...\",\"install_guide_url\":\"https://...\"}]}\n\n"
                . "PRODUCTS:\n" . $itemsJson;

        $ch = curl_init(rtrim(OPENAI_BASE_URL, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o',
                'messages' => [
                    ['role'=>'system','content'=>'You return strict JSON only. Never use markdown. Never wrap output in code fences.'],
                    ['role'=>'user','content'=>$prompt],
                ],
                'temperature' => 0.1,
                'max_tokens' => 4096,
                'response_format' => ['type' => 'json_object'],
            ]),
            CURLOPT_TIMEOUT => 90,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $updated = 0; $err = '';
        if ($resp && $code >= 200 && $code < 300) {
            $d = json_decode($resp, true);
            $text = $d['choices'][0]['message']['content'] ?? '';
            // Strip code fences if any
            $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
            $parsed = json_decode($text, true);
            $results = $parsed['results'] ?? null;
            if (is_array($results)) {
                $upd = $pdo->prepare('UPDATE products SET
                    activation_url    = CASE WHEN (activation_url IS NULL OR activation_url="")    THEN ? ELSE activation_url    END,
                    install_guide_url = CASE WHEN (install_guide_url IS NULL OR install_guide_url="") THEN ? ELSE install_guide_url END
                    WHERE slug=?');
                foreach ($results as $r) {
                    if (empty($r['slug'])) continue;
                    $au = filter_var($r['activation_url'] ?? '', FILTER_VALIDATE_URL) ? $r['activation_url'] : null;
                    $gu = filter_var($r['install_guide_url'] ?? '', FILTER_VALIDATE_URL) ? $r['install_guide_url'] : null;
                    if ($au === null && $gu === null) continue;
                    $upd->execute([$au, $gu, $r['slug']]);
                    if ($upd->rowCount() > 0) $updated++;
                }
            } else {
                $err = 'invalid+JSON+from+AI';
            }
        } else {
            $err = 'AI+HTTP+'.$code;
        }
        $msg = $updated > 0
            ? $updated.'+products+updated+with+AI+URLs'
            : ($err ?: 'No+products+updated');
        header('Location: admin.php?tab=products&msg='.$msg); exit;

    } elseif ($action === 'old_update_product') {

    } elseif ($action === 'update_order') {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$_POST['status'], (int)$_POST['order_id']]);
        if ($_POST['status']==='paid') fulfill_order((int)$_POST['order_id']);
        header('Location: admin.php?tab=orders&msg=Order+updated'); exit;

    } elseif ($action === 'resend_email') {
        $pdo->prepare('UPDATE orders SET fulfilled=0 WHERE id=?')->execute([(int)$_POST['order_id']]);
        fulfill_order((int)$_POST['order_id']);
        header('Location: admin.php?tab=orders&msg=Email+resent'); exit;

    } elseif ($action === 'save_billing_note') {
        // Customize the company name shown on customers' bank/card statements.
        // Source of truth for billing notes in the order-delivery email.
        if (isset($_POST['merchant_name'])) {
            setting_set('gw_card_merchant_name', trim($_POST['merchant_name']));
        }
        if (isset($_POST['account_name'])) {
            setting_set('gw_paypal_account_name', trim($_POST['account_name']));
        }
        $back = !empty($_POST['return_tpl_id'])
            ? 'admin.php?tab=templates&edit='.(int)$_POST['return_tpl_id'].'&msg=Billing+note+updated'
            : 'admin.php?tab=templates&msg=Billing+note+updated';
        header('Location: '.$back); exit;

    } elseif ($action === 'save_company_info') {
        // Single source of truth for company branding shown across all transactional emails.
        setting_set('company_name',    trim($_POST['company_name']    ?? ''));
        setting_set('company_email',   trim($_POST['company_email']   ?? ''));
        setting_set('company_phone',   trim($_POST['company_phone']   ?? ''));
        setting_set('company_address', trim($_POST['company_address'] ?? ''));
        if (!empty($_POST['company_logo'])) setting_set('company_logo', trim($_POST['company_logo']));
        if (!empty($_POST['clear_logo']))    setting_set('company_logo', '');
        header('Location: admin.php?tab=company&msg=Saved'); exit;

    } elseif ($action === 'save_smtp') {
        require_once __DIR__ . '/includes/mailer.php';
        smtp_set_config([
            'enabled'      => !empty($_POST['enabled']),
            'host'         => $_POST['host']       ?? '',
            'port'         => $_POST['port']       ?? '587',
            'username'     => $_POST['username']   ?? '',
            // Empty password = keep existing (so admins can re-save without re-typing)
            'password'     => ($_POST['password'] ?? '') !== '' ? $_POST['password'] : smtp_config()['password'],
            'encryption'   => $_POST['encryption'] ?? 'tls',
            'from_email'   => $_POST['from_email'] ?? '',
            'from_name'    => $_POST['from_name']  ?? '',
            'reply_to'     => $_POST['reply_to']   ?? '',
            'max_retries'  => $_POST['max_retries']?? '3',
            'rate_per_min' => $_POST['rate_per_min']?? '60',
            'verify_peer'  => !empty($_POST['verify_peer']),
            'debug_level'  => $_POST['debug_level']?? '0',
        ]);
        header('Location: admin.php?tab=smtp&msg=SMTP+saved'); exit;

    } elseif ($action === 'resend_outbox') {
        // Re-attempt sending an outbox email — UPDATE the existing row in place
        // (status / recipient / delivered_at) instead of creating a duplicate row.
        $emailId = (int)$_POST['email_id'];
        $newTo = trim($_POST['new_recipient'] ?? '');
        $row = $pdo->prepare('SELECT * FROM email_outbox WHERE id=?');
        $row->execute([$emailId]); $em = $row->fetch();
        if (!$em) { header('Location: admin.php?tab=emails&msg=Email+not+found'); exit; }

        $to = $newTo !== '' ? $newTo : $em['recipient'];
        // Try real delivery via Resend if configured, otherwise dev-mode success
        $apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
        $ok = true; $note = null; $providerId = $em['provider_id'] ?? null;
        if ($apiKey !== '') {
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['from' => SENDER_EMAIL, 'to' => [$to], 'subject' => $em['subject'], 'html' => $em['html']]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                CURLOPT_TIMEOUT => 20,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $ok = $res !== false && $code >= 200 && $code < 300;
            if ($ok) { $d = json_decode($res, true); $providerId = $d['id'] ?? $providerId; }
            else { $note = 'Delivery failed (HTTP '.$code.')'; }
        }
        // Update existing row in place — same id, new status / recipient / timestamps
        $pdo->prepare('UPDATE email_outbox
                       SET recipient=?, status=?, note=?, provider_id=?, delivered_at=?, opened_at=NULL, opened_count=0, clicked_at=NULL, click_count=0, created_at=NOW()
                       WHERE id=?')
            ->execute([
                $to,
                $ok ? 'sent' : 'failed',
                $note,
                $providerId,
                $ok ? date('Y-m-d H:i:s') : null,
                $emailId,
            ]);
        header('Location: admin.php?tab=emails&msg=Email+'.($ok?'resent+to+'.urlencode($to):'failed')); exit;

    } elseif ($action === 'add_keys') {
        $keys = array_filter(array_map('trim', explode("\n", $_POST['keys'] ?? '')));
        $stmt = $pdo->prepare('INSERT INTO license_keys (product_slug, license_key, region) VALUES (?,?,?)');
        $n=0; foreach ($keys as $k) try { $stmt->execute([$_POST['product_slug'], $k, $region_code]); $n++; } catch (Exception $e) {}
        $rs = $_POST['return_slug'] ?? $_POST['product_slug'] ?? '';
        $back = $rs ? 'admin.php?tab=products&inv='.urlencode($rs).'&invtab=available' : 'admin.php?tab=products';
        header('Location: '.$back.'&msg='.$n.'+key(s)+added'); exit;

    } elseif ($action === 'delete_key') {
        $pdo->prepare('DELETE FROM license_keys WHERE id=? AND status="available"')->execute([(int)$_POST['key_id']]);
        $rs = $_POST['return_slug'] ?? '';
        $back = $rs ? 'admin.php?tab=products&inv='.urlencode($rs).'&invtab=available' : 'admin.php?tab=products';
        header('Location: '.$back.'&msg=Key+removed'); exit;

    } elseif ($action === 'save_template') {
        $tplId = (int)$_POST['tpl_id'];
        $tpl = $pdo->prepare('SELECT * FROM email_templates WHERE id=?');
        $tpl->execute([$tplId]); $cur = $tpl->fetch();
        if ($cur) {
            // Save version snapshot before overwrite
            $pdo->prepare('INSERT INTO email_template_versions (template_id, version_num, subject, html, edited_by_email) VALUES (?,?,?,?,?)')
                ->execute([$tplId, $cur['current_version'], $cur['subject'], $cur['html'], $admin['email']]);
            $newV = $cur['current_version'] + 1;
            $pdo->prepare('UPDATE email_templates SET subject=?, html=?, current_version=?, active=? WHERE id=?')
                ->execute([trim($_POST['subject']), $_POST['html'], $newV, isset($_POST['active'])?1:0, $tplId]);
        }
        header('Location: admin.php?tab=templates&edit='.$tplId.'&msg=Template+saved'); exit;

    } elseif ($action === 'restore_template_version') {
        $tplId = (int)$_POST['tpl_id']; $vId = (int)$_POST['version_id'];
        $v = $pdo->prepare('SELECT * FROM email_template_versions WHERE id=? AND template_id=?');
        $v->execute([$vId, $tplId]); $ver = $v->fetch();
        if ($ver) {
            $cur = $pdo->prepare('SELECT * FROM email_templates WHERE id=?'); $cur->execute([$tplId]); $c = $cur->fetch();
            $pdo->prepare('INSERT INTO email_template_versions (template_id, version_num, subject, html, edited_by_email) VALUES (?,?,?,?,?)')
                ->execute([$tplId, $c['current_version'], $c['subject'], $c['html'], $admin['email']]);
            $pdo->prepare('UPDATE email_templates SET subject=?, html=?, current_version=current_version+1 WHERE id=?')
                ->execute([$ver['subject'], $ver['html'], $tplId]);
        }
        header('Location: admin.php?tab=templates&edit='.$tplId.'&msg=Version+restored'); exit;

    } elseif ($action === 'save_api') {
        $gw = $_POST['gateway']; // card | paypal
        if ($gw==='card') {
            setting_set('gw_card_status',         $_POST['status']);
            setting_set('gw_card_provider',       trim($_POST['provider']));
            setting_set('gw_card_merchant_name',  trim($_POST['merchant_name']));
            if (!empty($_POST['public_key']))     setting_set('gw_card_public_key', trim($_POST['public_key']));
            if (!empty($_POST['secret_key']))     setting_set('gw_card_secret_key', trim($_POST['secret_key']));
            if (!empty($_POST['webhook_secret'])) setting_set('gw_card_webhook_secret', trim($_POST['webhook_secret']));
        } else {
            setting_set('gw_paypal_status',       $_POST['status']);
            setting_set('gw_paypal_account_name', trim($_POST['account_name']));
            if (!empty($_POST['client_id']))      setting_set('gw_paypal_client_id', trim($_POST['client_id']));
            if (!empty($_POST['secret']))         setting_set('gw_paypal_secret', trim($_POST['secret']));
            if (!empty($_POST['webhook_id']))     setting_set('gw_paypal_webhook_id', trim($_POST['webhook_id']));
            setting_set('paypal_enabled', $_POST['status']==='active' ? '1' : '0');
        }
        header('Location: admin.php?tab=api&msg=API+settings+saved'); exit;

    } elseif ($action === 'update_lead') {
        $lid = (int)$_POST['lead_id'];
        $pdo->prepare('UPDATE chat_leads SET status=?, assigned_to=?, requested_product=? WHERE id=?')
            ->execute([$_POST['status'], $_POST['assigned_to']?:null, $_POST['requested_product']?:null, $lid]);
        if (!empty($_POST['note'])) {
            $pdo->prepare('INSERT INTO lead_notes (lead_id, note, author_name) VALUES (?,?,?)')
                ->execute([$lid, trim($_POST['note']), $admin['email']]);
        }
        header('Location: admin.php?tab=leads&open='.$lid.'&msg=Lead+updated'); exit;

    } elseif ($action === 'review_update_status') {
        $pdo->prepare('UPDATE customer_reviews SET status=? WHERE id=?')
            ->execute([$_POST['status'], (int)$_POST['review_id']]);
        header('Location: admin.php?tab=reviews&msg=Status+updated'); exit;
    } elseif ($action === 'review_delete') {
        $pdo->prepare('DELETE FROM customer_reviews WHERE id=?')->execute([(int)$_POST['review_id']]);
        header('Location: admin.php?tab=reviews&msg=Review+deleted'); exit;
    } elseif ($action === 'save_settings') {
        setting_set('statement_name_card',   trim($_POST['statement_name_card']));
        setting_set('statement_name_paypal', trim($_POST['statement_name_paypal']));
        header('Location: admin.php?tab=settings&msg=Settings+saved'); exit;

    } elseif ($action === 'save_region') {
        $code = strtoupper($_POST['region_code']);
        $pdo->prepare('UPDATE regions SET name=?, currency=?, currency_symbol=?, tax_rate=?, active=? WHERE code=?')
            ->execute([trim($_POST['name']), trim($_POST['currency']), trim($_POST['currency_symbol']),
                       (float)$_POST['tax_rate'], (int)($_POST['active'] ?? 0)?1:0, $code]);
        header('Location: admin.php?tab=regions&msg=Region+updated'); exit;
    }
}

// =========================================================================
// Notifications: new leads in last 24h (used in nav bell)
// =========================================================================
$newLeadCount = (int)$pdo->query("SELECT COUNT(*) FROM chat_leads WHERE status='new' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$pageTitle = 'Admin · ' . ucfirst($tab) . ' · ' . SITE_BRAND;
$adminActive = in_array($tab, ['template','settings'], true) ? $tab : (in_array($tab,['order-view'])?'orders':$tab);
include __DIR__ . '/includes/admin-shell.php';
?>

<?php if ($flash): ?><div class="alert alert-success py-2 small" data-testid="admin-flash"><?= esc($flash) ?></div><?php endif; ?>

<?php
// ============================================================================
// DASHBOARD
// ============================================================================
if ($tab === 'dashboard'):
    $rev   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND region=".$pdo->quote($region_code))->fetchColumn();
    $rev7  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND region=".$pdo->quote($region_code)." AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $rev30 = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND region=".$pdo->quote($region_code)." AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $ord   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE region=".$pdo->quote($region_code))->fetchColumn();
    $ordPaid = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','delivered') AND region=".$pdo->quote($region_code))->fetchColumn();
    $cust  = (int)$pdo->query("SELECT COUNT(DISTINCT email) FROM orders WHERE region=".$pdo->quote($region_code))->fetchColumn();
    $kAv   = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status='available' AND region=".$pdo->quote($region_code))->fetchColumn();
    $kSo   = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status='sold' AND region=".$pdo->quote($region_code))->fetchColumn();
    $avg   = $ordPaid > 0 ? $rev / $ordPaid : 0;
    $opens = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE opened_at IS NOT NULL")->fetchColumn();
    $sent  = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='sent'")->fetchColumn();
    $openRate = $sent > 0 ? round($opens/$sent*100) : 0;

    // 30-day sales chart
    $byDay = $pdo->prepare("SELECT DATE(created_at) AS d, SUM(total) AS r FROM orders WHERE status IN ('paid','delivered') AND region=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)");
    $byDay->execute([$region_code]);
    $dayMap = []; foreach ($byDay as $r) $dayMap[$r['d']] = (float)$r['r'];
    $days = []; for ($i=29;$i>=0;$i--) { $d = date('Y-m-d', strtotime("-$i days")); $days[] = ['d'=>$d, 'r'=>(float)($dayMap[$d] ?? 0)]; }
    $maxDay = max(array_column($days,'r')) ?: 1;

    // Top sellers
    $top = $pdo->prepare("SELECT oi.product_slug, oi.name, p.image, SUM(oi.qty) units, SUM(oi.qty*oi.price) revenue
        FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.slug=oi.product_slug
        WHERE o.status IN ('paid','delivered') AND o.region=?
        GROUP BY oi.product_slug,oi.name,p.image ORDER BY revenue DESC LIMIT 5");
    $top->execute([$region_code]);
    $top = $top->fetchAll();

    // Recent orders
    $recent = $pdo->prepare("SELECT * FROM orders WHERE region=? ORDER BY created_at DESC LIMIT 6");
    $recent->execute([$region_code]);
    $recent = $recent->fetchAll();

    // Low stock
    $lowStock = $pdo->prepare("SELECT p.slug, p.name, p.image,
        (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='available' AND lk.region=?) AS avail
        FROM products p WHERE p.is_active=1
        HAVING avail > 0 AND avail < 5 ORDER BY avail ASC LIMIT 5");
    $lowStock->execute([$region_code]);
    $lowStock = $lowStock->fetchAll();

    // Funnel
    $leadsTotal = (int)$pdo->query("SELECT COUNT(*) FROM chat_leads")->fetchColumn();
    $ordPending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending' AND region=".$pdo->quote($region_code))->fetchColumn();
    $ordDeliv   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='delivered' AND region=".$pdo->quote($region_code))->fetchColumn();
    $maxFunnel = max($leadsTotal, $ord, $ordPaid, $ordDeliv, 1);
?>
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h4 fw-bold mb-1">Dashboard <span class="text-muted fs-6">· <?= esc($rg['name']) ?> region</span></h1>
      <small class="text-muted">Real-time business overview · Showing <strong><?= esc($rg['code']) ?></strong> data in <strong><?= esc($rg['currency']) ?></strong></small>
    </div>
    <?php if ($newLeadCount): ?>
      <a href="admin.php?tab=leads" class="card-e px-3 py-2 text-decoration-none d-flex align-items-center gap-2" style="border-left:4px solid var(--amber);">
        <i class="bi bi-bell-fill text-warning"></i>
        <span><strong><?= $newLeadCount ?></strong> new lead<?= $newLeadCount>1?'s':'' ?> in last 24h</span>
        <i class="bi bi-arrow-right text-muted"></i>
      </a>
    <?php endif; ?>
  </div>

  <!-- ====================================================================
       (Company Info card lives on its own tab — admin.php?tab=company)
       ==================================================================== -->


  <!-- KPI ROW -->
  <div class="row g-3 mb-3" data-testid="admin-kpis">
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi-tile green">
      <div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div>
      <div class="kpi-label">Revenue</div>
      <div class="kpi-value"><?= esc($rg['currency_symbol']) ?><?= number_format($rev,0) ?></div>
      <div class="kpi-delta text-success"><i class="bi bi-arrow-up-right"></i> Last 7d: <?= esc($rg['currency_symbol']) ?><?= number_format($rev7,0) ?></div>
    </div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi-tile blue">
      <div class="kpi-icon"><i class="bi bi-receipt"></i></div>
      <div class="kpi-label">Orders</div>
      <div class="kpi-value"><?= number_format($ord) ?></div>
      <div class="kpi-delta text-muted"><?= $ordPaid ?> paid · <?= $ordPending ?> pending</div>
    </div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi-tile purple">
      <div class="kpi-icon"><i class="bi bi-people"></i></div>
      <div class="kpi-label">Customers</div>
      <div class="kpi-value"><?= number_format($cust) ?></div>
      <div class="kpi-delta text-muted">avg <?= esc($rg['currency_symbol']) ?><?= number_format($avg,2) ?></div>
    </div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi-tile amber">
      <div class="kpi-icon"><i class="bi bi-key"></i></div>
      <div class="kpi-label">Keys Available</div>
      <div class="kpi-value"><?= number_format($kAv) ?></div>
      <div class="kpi-delta text-muted"><?= $kSo ?> sold</div>
    </div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi-tile cyan">
      <div class="kpi-icon"><i class="bi bi-envelope-open"></i></div>
      <div class="kpi-label">Email Open Rate</div>
      <div class="kpi-value"><?= $openRate ?>%</div>
      <div class="kpi-delta text-muted"><?= $opens ?> of <?= $sent ?> opened</div>
    </div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi-tile red">
      <div class="kpi-icon"><i class="bi bi-person-lines-fill"></i></div>
      <div class="kpi-label">Leads (all)</div>
      <div class="kpi-value"><?= number_format($leadsTotal) ?></div>
      <div class="kpi-delta text-muted"><?= $newLeadCount ?> new in 24h</div>
    </div></div>
  </div>

  <div class="row g-3">
    <!-- 30-day Revenue Trend -->
    <div class="col-xl-8">
      <div class="card-e">
        <div class="card-head"><div class="ttl"><i class="bi bi-bar-chart-line"></i> Revenue Trend <span class="sub ms-2">last 30 days</span></div>
          <div class="sub">Total <strong style="color:var(--text);"><?= esc($rg['currency_symbol']) ?><?= number_format($rev30,2) ?></strong></div>
        </div>
        <div class="card-body-p">
          <div class="chart-bars">
            <?php foreach ($days as $d): $h = max(2, round($d['r']/$maxDay*120)); ?>
              <div class="b" style="height:<?= $h ?>px;opacity:<?= $d['r']>0?1:.18 ?>;" title="<?= esc($d['d']) ?> — <?= esc($rg['currency_symbol']) ?><?= number_format($d['r'],2) ?>"></div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex justify-content-between mt-2 small text-muted">
            <span><?= esc(date('M j', strtotime($days[0]['d']))) ?></span>
            <span><?= esc(date('M j', strtotime($days[15]['d']))) ?></span>
            <span><?= esc(date('M j', strtotime(end($days)['d']))) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Conversion Funnel -->
    <div class="col-xl-4">
      <div class="card-e h-100">
        <div class="card-head"><div class="ttl"><i class="bi bi-funnel"></i> Conversion Funnel</div></div>
        <div class="card-body-p">
          <?php
          $funnel = [
            ['Leads',        $leadsTotal, 'purple'],
            ['Total Orders', $ord,        'cyan'],
            ['Paid Orders',  $ordPaid,    ''],
            ['Delivered',    $ordDeliv,   'green'],
          ];
          foreach ($funnel as [$lbl,$val,$cls]):
            $pct = $maxFunnel>0 ? max(8, round($val/$maxFunnel*100)) : 8;
          ?>
            <div class="funnel-row">
              <span class="funnel-label"><?= esc($lbl) ?></span>
              <div class="funnel-bar <?= $cls ?>" style="max-width:<?= $pct ?>%;">
                <span class="funnel-num"><?= number_format($val) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <hr>
          <div class="d-flex justify-content-between small">
            <span class="text-muted">Lead → Paid conversion</span>
            <strong class="text-success"><?= $leadsTotal>0 ? round($ordPaid/$leadsTotal*100,1).'%' : '—' ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php
  // ---- Payment Methods sales breakdown (Card vs PayPal + merchant name)
  $pmStmt = $pdo->prepare("SELECT payment_method,
                            COUNT(*) AS orders_cnt,
                            COALESCE(SUM(CASE WHEN status='paid' THEN total ELSE 0 END),0) AS rev,
                            SUM(status='paid') AS paid_cnt
                          FROM orders WHERE region=? GROUP BY payment_method");
  $pmStmt->execute([$region_code]);
  $pmRows = $pmStmt->fetchAll();
  $pmTotalRev = 0; foreach ($pmRows as $r) $pmTotalRev += (float)$r['rev'];
  $cardMerch = setting_get('gw_card_merchant_name','Maventech Software');
  $ppMerch   = setting_get('gw_paypal_account_name','Maventech Software LLC');
  ?>
  <div class="row g-3 mt-1">
    <div class="col-12">
      <div class="card-e">
        <div class="card-head">
          <div class="ttl"><i class="bi bi-credit-card-2-front"></i> Sales by Payment Method <span class="sub ms-2">(<?= esc($rg['name']) ?>)</span></div>
          <a href="admin.php?tab=api" class="sub" style="color:var(--brand);">API Management →</a>
        </div>
        <div class="card-body-p">
          <div class="row g-3" data-testid="payment-methods-breakdown">
            <?php
            $pmKnown = ['card'=>['Stripe', $cardMerch, '#635bff', 'bi-credit-card-2-front-fill'],
                        'paypal'=>['PayPal', $ppMerch, '#0070ba', 'bi-paypal']];
            foreach (['card','paypal'] as $pmKey):
              $found = null;
              foreach ($pmRows as $r) if (strtolower($r['payment_method'])===$pmKey) { $found=$r; break; }
              $rev   = $found ? (float)$found['rev'] : 0;
              $cnt   = $found ? (int)$found['orders_cnt'] : 0;
              $paid  = $found ? (int)$found['paid_cnt'] : 0;
              $share = $pmTotalRev > 0 ? round(($rev/$pmTotalRev)*100) : 0;
              [$gw, $merch, $color, $icon] = $pmKnown[$pmKey];
            ?>
              <div class="col-md-6">
                <div class="card-e p-3" style="border-left:4px solid <?= esc($color) ?>;background:var(--bg);">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:46px;height:46px;border-radius:10px;background:<?= esc($color) ?>15;color:<?= esc($color) ?>;display:inline-flex;align-items:center;justify-content:center;font-size:22px;"><i class="bi <?= esc($icon) ?>"></i></div>
                    <div class="flex-grow-1">
                      <div class="fw-bold" style="font-size:15px;"><?= esc(ucfirst($pmKey)) ?> Payments</div>
                      <small class="text-muted">Gateway: <strong style="color:<?= esc($color) ?>;"><?= esc($gw) ?></strong> · Merchant: <strong><?= esc($merch) ?></strong></small>
                    </div>
                    <div class="text-end">
                      <div class="fw-bold" style="font-size:22px;color:<?= esc($color) ?>;" data-testid="pm-<?= $pmKey ?>-revenue"><?= esc($rg['currency_symbol']) ?><?= number_format(region_price($rev),2) ?></div>
                      <small class="text-muted"><?= $share ?>% of revenue</small>
                    </div>
                  </div>
                  <div class="d-flex justify-content-between" style="font-size:12px;">
                    <span><i class="bi bi-receipt me-1 text-muted"></i><strong><?= $cnt ?></strong> total order<?= $cnt!==1?'s':'' ?></span>
                    <span><i class="bi bi-check-circle-fill me-1 text-success"></i><strong><?= $paid ?></strong> paid</span>
                    <?php $rate = $cnt > 0 ? round(($paid/$cnt)*100) : 0; ?>
                    <span class="text-muted"><?= $rate ?>% conversion</span>
                  </div>
                  <div class="prog mt-2" style="height:6px;background:<?= esc($color) ?>1a;border-radius:3px;overflow:hidden;">
                    <span style="display:block;height:100%;background:<?= esc($color) ?>;width:<?= $share ?>%;transition:width .3s;"></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <!-- Top Sellers -->
    <div class="col-lg-4">
      <div class="card-e h-100">
        <div class="card-head"><div class="ttl"><i class="bi bi-trophy"></i> Top Sellers</div>
          <a href="admin.php?tab=sales" class="sub" style="color:var(--brand);">View all →</a>
        </div>
        <div class="card-body-p">
          <?php if (empty($top)): ?>
            <p class="text-muted small mb-0 text-center py-3">No sales yet in this region.</p>
          <?php endif; ?>
          <?php foreach ($top as $i=>$t): ?>
            <div class="mini-row">
              <span class="rank"><?= $i+1 ?></span>
              <?php if ($t['image']): ?><img src="<?= esc($t['image']) ?>" class="thumb"><?php endif; ?>
              <div class="flex-grow-1 min-width-0">
                <div class="small fw-semibold text-truncate" title="<?= esc($t['name']) ?>"><?= esc($t['name']) ?></div>
                <small class="text-muted"><?= (int)$t['units'] ?> units sold</small>
              </div>
              <strong class="text-success small"><?= esc($rg['currency_symbol']) ?><?= number_format($t['revenue'],0) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Recent Orders -->
    <div class="col-lg-4">
      <div class="card-e h-100">
        <div class="card-head"><div class="ttl"><i class="bi bi-receipt-cutoff"></i> Recent Orders</div>
          <a href="admin.php?tab=orders" class="sub" style="color:var(--brand);">View all →</a>
        </div>
        <div class="card-body-p">
          <?php if (empty($recent)): ?><p class="text-muted small mb-0 text-center py-3">No orders yet.</p><?php endif; ?>
          <?php foreach ($recent as $o): ?>
            <a href="order-view.php?id=<?= (int)$o['id'] ?>" class="mini-row text-decoration-none" style="color:var(--text);">
              <i class="bi bi-receipt" style="font-size:18px;color:var(--brand);"></i>
              <div class="flex-grow-1 min-width-0">
                <div class="small fw-semibold">#<?= esc($o['order_number']) ?></div>
                <small class="text-muted"><?= esc($o['first_name'].' '.$o['last_name']) ?> · <?= esc(date('M j', strtotime($o['created_at']))) ?></small>
              </div>
              <div class="text-end">
                <strong class="small"><?= esc($rg['currency_symbol']) ?><?= number_format($o['total'],2) ?></strong><br>
                <span class="s-badge <?= esc($o['status']) ?>" style="font-size:9px;padding:1px 7px;"><?= esc($o['status']) ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Low Stock Alert -->
    <div class="col-lg-4">
      <div class="card-e h-100">
        <div class="card-head"><div class="ttl"><i class="bi bi-exclamation-triangle text-danger"></i> Low Stock Alert</div>
          <a href="inventory.php" class="sub" style="color:var(--brand);">Inventory →</a>
        </div>
        <div class="card-body-p">
          <?php if (empty($lowStock)): ?>
            <div class="text-center py-3">
              <i class="bi bi-check-circle-fill text-success" style="font-size:32px;"></i>
              <p class="small text-muted mb-0 mt-2">All products well-stocked!</p>
            </div>
          <?php endif; ?>
          <?php foreach ($lowStock as $ls):
            $cls = $ls['avail']==0?'danger':'warn';
            $pct = min(100, ($ls['avail']/5)*100);
          ?>
            <div class="mini-row">
              <?php if ($ls['image']): ?><img src="<?= esc($ls['image']) ?>" class="thumb"><?php else: ?><div class="thumb d-flex align-items-center justify-content-center"><i class="bi bi-box-seam text-muted"></i></div><?php endif; ?>
              <div class="flex-grow-1 min-width-0">
                <div class="small fw-semibold text-truncate" title="<?= esc($ls['name']) ?>"><?= esc($ls['name']) ?></div>
                <div class="prog <?= $cls ?> mt-1"><span style="width:<?= $pct ?>%;"></span></div>
              </div>
              <span class="s-badge <?= $ls['avail']==0?'failed':'queued' ?>" style="font-size:10px;"><?= (int)$ls['avail'] ?> left</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

<?php
// ============================================================================
// COMPANY INFO — single source of truth used by every email template.
// Sidebar item below Dashboard.
// ============================================================================
elseif ($tab === 'company'):
  $co = company_info();
?>
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h4 fw-bold mb-1"><i class="bi bi-building me-1 text-primary"></i> Company Info</h1>
      <small class="text-muted">Update your company name, email, toll-free number, address and logo. These details appear in <strong>every</strong> transactional email your customers receive — headers, footers, signatures and the billing note.</small>
    </div>
    <?php if (!empty($_GET['msg'])): ?>
      <span class="badge bg-success-subtle text-success" data-testid="ci-saved-toast"><i class="bi bi-check2-circle me-1"></i><?= esc($_GET['msg']) ?></span>
    <?php endif; ?>
  </div>

  <div class="card-e p-4 mb-3" data-testid="company-info-card" style="border-left:4px solid #3b82f6;">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
      <div class="d-flex align-items-center gap-3">
        <div class="ci-logo-box" data-testid="ci-logo-preview">
          <?php if ($co['logo']): ?>
            <img src="<?= esc($co['logo']) ?>" alt="Logo" class="ci-logo-img" data-testid="ci-logo-img">
          <?php else: ?>
            <span class="ci-logo-fb"><i class="bi bi-buildings"></i></span>
          <?php endif; ?>
        </div>
        <div>
          <h6 class="fw-bold mb-0"><?= esc($co['name'] ?: 'Your company') ?></h6>
          <small class="text-muted">Updating any field below auto-syncs across all 5 email templates.</small>
        </div>
      </div>
      <button type="button" class="btn btn-soft-blue btn-sm" id="ciEditBtn" data-testid="ci-edit-btn"><i class="bi bi-pencil-square me-1"></i> Edit</button>
    </div>

    <!-- Read-only summary -->
    <div id="ciView" class="row g-2 small">
      <div class="col-md-3"><div class="ci-tile"><div class="ci-tile-label"><i class="bi bi-building me-1"></i>Company</div><div class="ci-tile-val" data-testid="ci-name-current"><?= esc($co['name'] ?: '—') ?></div></div></div>
      <div class="col-md-3"><div class="ci-tile"><div class="ci-tile-label"><i class="bi bi-envelope me-1"></i>Email</div><div class="ci-tile-val" data-testid="ci-email-current"><?= esc($co['email'] ?: '—') ?></div></div></div>
      <div class="col-md-3"><div class="ci-tile"><div class="ci-tile-label"><i class="bi bi-telephone me-1"></i>Toll-free</div><div class="ci-tile-val" data-testid="ci-phone-current"><?= esc($co['phone'] ?: '—') ?></div></div></div>
      <div class="col-md-3"><div class="ci-tile"><div class="ci-tile-label"><i class="bi bi-geo-alt me-1"></i>Address</div><div class="ci-tile-val" data-testid="ci-address-current" style="white-space:pre-wrap;font-size:12px;"><?= esc($co['address'] ?: '—') ?></div></div></div>
    </div>

    <!-- Edit form -->
    <form id="ciEdit" method="post" class="d-none mt-3" data-testid="ci-edit-form">
      <input type="hidden" name="action" value="save_company_info">
      <input type="hidden" name="company_logo" id="ciLogoUrl" value="<?= esc($co['logo']) ?>" data-testid="ci-logo-url">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold"><i class="bi bi-building me-1"></i>Company Name</label>
          <input class="form-control" name="company_name" value="<?= esc($co['name']) ?>" required data-testid="ci-name-input">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold"><i class="bi bi-envelope me-1"></i>Email Address</label>
          <input class="form-control" name="company_email" type="email" value="<?= esc($co['email']) ?>" required data-testid="ci-email-input">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold"><i class="bi bi-telephone me-1"></i>Toll-free Number</label>
          <input class="form-control" name="company_phone" value="<?= esc($co['phone']) ?>" placeholder="1-888-…" data-testid="ci-phone-input">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold"><i class="bi bi-geo-alt me-1"></i>Company Address</label>
          <textarea class="form-control" name="company_address" rows="2" placeholder="Street, City, State ZIP, Country" data-testid="ci-address-input"><?= esc($co['address']) ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold"><i class="bi bi-image me-1"></i>Company Logo <span class="text-muted fw-normal">— shows at the top of every email</span></label>
          <div class="d-flex align-items-center gap-3 flex-wrap p-3 rounded" style="background:var(--bg);border:1px dashed var(--border);">
            <div class="ci-logo-preview-lg" id="ciLogoPreviewLg">
              <?php if ($co['logo']): ?>
                <img src="<?= esc($co['logo']) ?>" alt="Logo" data-testid="ci-logo-preview-img">
              <?php else: ?>
                <span class="text-muted small"><i class="bi bi-image"></i> No logo yet</span>
              <?php endif; ?>
            </div>
            <div class="flex-grow-1 d-flex gap-2 flex-wrap align-items-center">
              <input type="file" class="form-control form-control-sm" id="ciLogoFile" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="max-width:300px;" data-testid="ci-logo-file">
              <button type="button" class="btn btn-soft-blue btn-sm" id="ciLogoUploadBtn" data-testid="ci-logo-upload-btn"><i class="bi bi-cloud-upload me-1"></i> Upload</button>
              <?php if ($co['logo']): ?>
                <button type="button" class="btn btn-soft-gray btn-sm" id="ciLogoRemoveBtn" data-testid="ci-logo-remove-btn"><i class="bi bi-x-circle me-1"></i> Remove</button>
              <?php endif; ?>
              <span class="text-muted small">JPG · PNG · SVG · max 3 MB</span>
            </div>
          </div>
          <div id="ciLogoErr" class="small text-danger mt-2 d-none"></div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button class="btn btn-soft-blue btn-sm" data-testid="ci-save-btn"><i class="bi bi-check2 me-1"></i> Save Company Info</button>
        <button type="button" class="btn btn-soft-gray btn-sm" id="ciCancelBtn" data-testid="ci-cancel-btn">Cancel</button>
        <small class="text-muted align-self-center ms-auto">All email templates pick up these values automatically.</small>
      </div>
    </form>
  </div>

  <!-- Where it shows up -->
  <div class="card-e p-3 mb-3" style="background:linear-gradient(135deg,#f0f9ff,#eff6ff);border:1px solid #bfdbfe;">
    <div class="d-flex gap-3 align-items-start">
      <i class="bi bi-info-circle text-primary" style="font-size:22px;line-height:1;"></i>
      <div class="small">
        <strong class="d-block mb-1" style="color:#1e40af;">Where these details appear</strong>
        <div class="row g-2">
          <div class="col-md-4">&bull; Email header logo &amp; brand name</div>
          <div class="col-md-4">&bull; Order confirmation footer (support email + phone)</div>
          <div class="col-md-4">&bull; Refund &amp; review-request emails</div>
          <div class="col-md-4">&bull; Lead follow-up signature</div>
          <div class="col-md-4">&bull; Billing-statement note</div>
          <div class="col-md-4">&bull; Template editor live preview</div>
        </div>
      </div>
    </div>
  </div>

  <style>
    .ci-logo-box {
      width: 60px; height: 60px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      display: inline-flex; align-items: center; justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
    }
    .ci-logo-img { max-width: 56px; max-height: 56px; object-fit: contain; }
    .ci-logo-fb  { font-size: 28px; color: var(--brand); }
    .ci-tile {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 12px;
      height: 100%;
    }
    .ci-tile-label { font-size: 10.5px; color: var(--text-muted, #64748b); letter-spacing: .5px; text-transform: uppercase; font-weight: 600; }
    .ci-tile-val   { font-weight: 700; color: var(--text, #0f172a); margin-top: 4px; font-size: 13.5px; word-break: break-word; }
    .ci-logo-preview-lg {
      width: 96px; height: 96px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 12px;
      display: inline-flex; align-items: center; justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
    }
    .ci-logo-preview-lg img { max-width: 90px; max-height: 90px; object-fit: contain; }
  </style>

  <script>
  (function(){
    var editBtn = document.getElementById('ciEditBtn');
    var view    = document.getElementById('ciView');
    var form    = document.getElementById('ciEdit');
    var cancel  = document.getElementById('ciCancelBtn');
    if (editBtn) editBtn.addEventListener('click', function(){ view.classList.add('d-none'); form.classList.remove('d-none'); });
    if (cancel)  cancel.addEventListener('click',  function(){ form.classList.add('d-none'); view.classList.remove('d-none'); });

    var fileEl   = document.getElementById('ciLogoFile');
    var upBtn    = document.getElementById('ciLogoUploadBtn');
    var rmBtn    = document.getElementById('ciLogoRemoveBtn');
    var urlInput = document.getElementById('ciLogoUrl');
    var preview  = document.getElementById('ciLogoPreviewLg');
    var errBox   = document.getElementById('ciLogoErr');

    function showErr(m){ if (!errBox) return; errBox.textContent = m || ''; errBox.classList.toggle('d-none', !m); }
    function renderLogo(url){
      if (!preview) return;
      preview.innerHTML = url
        ? '<img src="' + url + '" alt="Logo" data-testid="ci-logo-preview-img">'
        : '<span class="text-muted small"><i class="bi bi-image"></i> No logo yet</span>';
    }

    if (upBtn) upBtn.addEventListener('click', function(){
      showErr('');
      if (!fileEl.files || !fileEl.files[0]) { showErr('Please choose a logo image first.'); return; }
      var fd = new FormData(); fd.append('logo', fileEl.files[0]);
      var orig = upBtn.innerHTML; upBtn.disabled = true;
      upBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
      fetch('ajax/company-logo.php', { method:'POST', body: fd })
        .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'Server error'}; }); })
        .then(function(j){
          upBtn.disabled = false; upBtn.innerHTML = orig;
          if (!j || !j.ok) { showErr((j && j.error) || 'Upload failed.'); return; }
          urlInput.value = j.url;
          renderLogo(j.url);
        }).catch(function(){
          upBtn.disabled = false; upBtn.innerHTML = orig;
          showErr('Network error — please try again.');
        });
    });

    if (rmBtn) rmBtn.addEventListener('click', function(){
      if (!confirm('Remove the company logo?')) return;
      urlInput.value = '';
      renderLogo('');
      var clr = document.createElement('input');
      clr.type = 'hidden'; clr.name = 'clear_logo'; clr.value = '1';
      form.appendChild(clr);
      rmBtn.disabled = true;
    });
  })();
  </script>

<?php
// ============================================================================
// PRODUCTS (region-filtered)
// ============================================================================
elseif ($tab === 'products'):
  // ---- Filters
  $f = [
    'q'       => trim($_GET['q'] ?? ''),
    'year'    => $_GET['year'] ?? '',
    'os'      => $_GET['os'] ?? '',
    'type'    => $_GET['type'] ?? '',
    'cat'     => $_GET['cat'] ?? '',
    'brand'   => $_GET['brand'] ?? '',
    'status'  => $_GET['status'] ?? '',
    'pmin'    => $_GET['pmin'] ?? '',
    'pmax'    => $_GET['pmax'] ?? '',
    'stock'   => $_GET['stock'] ?? '',
    'region'  => $_GET['p_region'] ?? '',
    'sort'    => $_GET['sort'] ?? 'newest',
  ];
  $where = ['1=1']; $args = [];
  if ($f['q'])      { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $args[]="%{$f['q']}%"; $args[]="%{$f['q']}%"; }
  if ($f['year']!=='')   { $where[] = 'p.year = ?';     $args[] = (int)$f['year']; }
  if ($f['os'])     { $where[] = 'p.platform = ?'; $args[] = $f['os']; }
  // ---- High-level Type filter (groups categories/brands into intuitive buckets)
  if ($f['type']==='office')    { $where[] = "p.category LIKE 'office-%'"; }
  elseif ($f['type']==='antivirus') { $where[] = "(p.brand IN ('Bitdefender','McAfee','Norton','Kaspersky','Avast','AVG','ESET','Trend Micro','Webroot') OR p.category LIKE 'antivirus%' OR p.category IN ('bitdefender','mcafee','norton','kaspersky'))"; }
  elseif ($f['type']==='windows-os') { $where[] = "p.category LIKE 'windows-%'"; }
  elseif ($f['type']==='other') { $where[] = "p.category NOT LIKE 'office-%' AND p.category NOT LIKE 'windows-%' AND (p.brand IS NULL OR p.brand NOT IN ('Bitdefender','McAfee','Norton','Kaspersky','Avast','AVG','ESET','Trend Micro','Webroot'))"; }
  if ($f['cat'])    { $where[] = 'p.category = ?'; $args[] = $f['cat']; }
  if ($f['brand'])  { $where[] = 'p.brand = ?';    $args[] = $f['brand']; }
  if ($f['status']!=='') { $where[] = 'p.is_active = ?'; $args[] = (int)$f['status']; }
  // Convert region-currency input to USD before comparing
  $rate = region_rates()[$region_code] ?? 1.0;
  if ($f['pmin']!=='')   { $where[] = 'p.price >= ?'; $args[] = (float)$f['pmin'] / $rate; }
  if ($f['pmax']!=='')   { $where[] = 'p.price <= ?'; $args[] = (float)$f['pmax'] / $rate; }
  if ($f['region']) { $where[] = 'p.region = ?'; $args[] = $f['region']; }

  // ---- Sort
  $orderBy = match ($f['sort']) {
    'oldest'      => 'p.id ASC',
    'price_asc'   => 'p.price ASC',
    'price_desc'  => 'p.price DESC',
    'name_asc'    => 'p.name ASC',
    'name_desc'   => 'p.name DESC',
    'best_sellers'=> 'sold_keys DESC, p.id DESC',
    default       => 'p.is_active DESC, p.id DESC',
  };

  $sql = "SELECT p.*,
    (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='available') AS avail_keys,
    (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='sold')      AS sold_keys
    FROM products p WHERE " . implode(' AND ', $where) . " ORDER BY $orderBy LIMIT 500";
  $st = $pdo->prepare($sql); $st->execute($args);
  $products = $st->fetchAll();
  if ($f['stock']==='in')    $products = array_filter($products, fn($p) => $p['avail_keys'] > 0);
  if ($f['stock']==='out')   $products = array_filter($products, fn($p) => $p['avail_keys'] == 0);
  if ($f['stock']==='low')   $products = array_filter($products, fn($p) => $p['avail_keys'] > 0 && $p['avail_keys'] < 5);

  // ---- Filter dropdown values
  $years  = $pdo->query('SELECT DISTINCT year FROM products WHERE year IS NOT NULL ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);
  $oss    = $pdo->query('SELECT DISTINCT platform FROM products ORDER BY platform')->fetchAll(PDO::FETCH_COLUMN);
  $cats   = $pdo->query('SELECT DISTINCT category FROM products ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
  $brands = $pdo->query('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL ORDER BY brand')->fetchAll(PDO::FETCH_COLUMN);
  $sortLabels = [
    'newest'=>'Newest','oldest'=>'Oldest','price_asc'=>'Price: Low → High','price_desc'=>'Price: High → Low',
    'name_asc'=>'Name: A → Z','name_desc'=>'Name: Z → A','best_sellers'=>'Best Sellers',
  ];

  $editSlug = $_GET['edit'] ?? ($_GET['add'] ?? '');
  $isAdd = ($_GET['add'] ?? '') !== '';
  $editing = null;
  if ($editSlug && !$isAdd) {
    $e = $pdo->prepare('SELECT * FROM products WHERE slug=?'); $e->execute([$editSlug]); $editing = $e->fetch();
  } elseif ($isAdd) {
    $editing = ['slug'=>'','name'=>'','sku'=>'','brand'=>'Microsoft','year'=>date('Y'),'platform'=>'Windows','category'=>($cats[0]??''),'license_type'=>'lifetime','price'=>'','original_price'=>'','badge'=>'','description'=>'','image'=>'','is_active'=>1,'rating'=>4.5,'reviews'=>0];
  }

  // Helper to build pill URLs preserving other query params
  $qsBuild = function (array $overrides) {
    $base = ['tab'=>'products'];
    $cur = array_intersect_key($_GET, array_flip(['q','year','os','type','cat','brand','status','pmin','pmax','stock','p_region','sort']));
    $merged = array_merge($base, $cur, $overrides);
    // strip empty values
    foreach ($merged as $k=>$v) if ($v === '' || $v === null) unset($merged[$k]);
    return '?' . http_build_query($merged);
  };
  $hasAdvanced = ($f['cat'] || $f['brand'] || $f['status']!=='' || $f['stock'] || $f['region'] || $f['pmin']!=='' || $f['pmax']!=='');
?>
<style>
.pill-toggle { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:999px; font-size:13px; font-weight:500; background:var(--bg); color:var(--text); border:1px solid var(--border); text-decoration:none; transition:all .15s ease; cursor:pointer; }
.pill-toggle:hover { background:var(--blue-soft); color:var(--brand-dk); border-color:transparent; }
.pill-toggle.active { background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; border-color:transparent; box-shadow:0 2px 8px rgba(59,130,246,.25); }
.pill-toggle.active:hover { color:#fff; filter:brightness(1.05); }
.pill-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.pill-row .pill-label { font-size:11px; color:var(--text-muted, #94a3b8); text-transform:uppercase; letter-spacing:1px; font-weight:600; margin-right:4px; min-width:64px; }
.search-pill { display:flex; align-items:center; gap:8px; padding:8px 14px; background:var(--bg); border:1px solid var(--border); border-radius:999px; transition:border-color .15s; }
.search-pill:focus-within { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.search-pill input { border:none; background:transparent; outline:none; flex:1; color:var(--text); font-size:13px; }
.sort-select { padding:8px 32px 8px 14px; border-radius:999px; border:1px solid var(--border); background:var(--bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 16 16'%3E%3Cpath fill='%2394a3b8' d='M3.5 5.5L8 10l4.5-4.5z'/%3E%3C/svg%3E") no-repeat right 12px center; font-size:13px; font-weight:500; color:var(--text); appearance:none; cursor:pointer; }
.advanced-toggle { font-size:12px; color:var(--text-muted, #94a3b8); cursor:pointer; user-select:none; }
.advanced-toggle:hover { color:#3b82f6; }
.advanced-panel { animation: slideDown .25s ease-out; }
@keyframes slideDown { from { opacity:0; transform:translateY(-4px);} to { opacity:1; transform:translateY(0);} }
</style>

  <!-- HEADER: title + count + add -->
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h5 class="fw-bold mb-0" data-testid="products-title">All Products</h5>
      <small class="text-muted" data-testid="products-count"><strong><?= count($products) ?></strong> products available</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <form method="post" class="d-inline" onsubmit="return confirm('Use AI (gpt-4o) to look up the official activation &amp; installation-guide URL for every product missing one? This may take 20-60 seconds.');">
        <input type="hidden" name="action" value="ai_autofill_urls">
        <input type="hidden" name="only_missing" value="1">
        <button class="btn btn-soft-green btn-sm" data-testid="ai-autofill-urls" title="Auto-fill activation & installation URLs for all products with empty fields using AI">
          <i class="bi bi-stars me-1"></i> Auto-fill URLs with AI
        </button>
      </form>
      <a href="?tab=products&add=1" class="btn-add-glow" data-testid="add-product-glow" title="Add new product"><i class="bi bi-plus-lg"></i></a>
    </div>
  </div>

  <!-- REDESIGNED FILTER BAR -->
  <div class="card-e p-3 mb-3" data-testid="product-filters">

    <!-- Row 1: Search + Sort + More filters toggle -->
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <form method="get" class="flex-grow-1" style="min-width:220px;max-width:480px;">
        <input type="hidden" name="tab" value="products">
        <?php foreach (['year','os','type','cat','brand','status','pmin','pmax','stock','p_region','sort'] as $kp): if (!empty($_GET[$kp])): ?>
          <input type="hidden" name="<?= esc($kp) ?>" value="<?= esc($_GET[$kp]) ?>">
        <?php endif; endforeach; ?>
        <label class="search-pill">
          <i class="bi bi-search text-muted"></i>
          <input name="q" value="<?= esc($f['q']) ?>" placeholder="Search products by name or SKU…" data-testid="search-input">
          <?php if ($f['q']): ?><a href="<?= esc($qsBuild(['q'=>''])) ?>" class="text-muted text-decoration-none" title="Clear search"><i class="bi bi-x-circle"></i></a><?php endif; ?>
        </label>
      </form>

      <div class="d-flex gap-2 align-items-center ms-auto">
        <span class="advanced-toggle" onclick="document.getElementById('advFilters').classList.toggle('d-none');" data-testid="toggle-advanced">
          <i class="bi bi-sliders"></i> More filters <?php if ($hasAdvanced): ?><span class="badge bg-primary ms-1" style="font-size:9px;">on</span><?php endif; ?>
        </span>
        <form method="get" class="d-inline">
          <input type="hidden" name="tab" value="products">
          <?php foreach (['q','year','os','type','cat','brand','status','pmin','pmax','stock','p_region'] as $kp): if (!empty($_GET[$kp])): ?>
            <input type="hidden" name="<?= esc($kp) ?>" value="<?= esc($_GET[$kp]) ?>">
          <?php endif; endforeach; ?>
          <select class="sort-select" name="sort" onchange="this.form.submit()" data-testid="sort-select">
            <?php foreach ($sortLabels as $k=>$lbl): ?>
              <option value="<?= $k ?>" <?= $f['sort']===$k?'selected':'' ?>><?= esc($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>

    <!-- Row 2: Type pills (high-level grouping) -->
    <div class="pill-row mb-2" data-testid="type-pills">
      <span class="pill-label">Type</span>
      <a href="<?= esc($qsBuild(['type'=>''])) ?>" class="pill-toggle <?= $f['type']===''?'active':'' ?>" data-testid="type-all">All</a>
      <a href="<?= esc($qsBuild(['type'=>'office'])) ?>" class="pill-toggle <?= $f['type']==='office'?'active':'' ?>" data-testid="type-office"><i class="bi bi-file-earmark-text"></i> Office</a>
      <a href="<?= esc($qsBuild(['type'=>'antivirus'])) ?>" class="pill-toggle <?= $f['type']==='antivirus'?'active':'' ?>" data-testid="type-antivirus"><i class="bi bi-shield-check"></i> Antivirus</a>
      <a href="<?= esc($qsBuild(['type'=>'windows-os'])) ?>" class="pill-toggle <?= $f['type']==='windows-os'?'active':'' ?>" data-testid="type-windows-os"><i class="bi bi-windows"></i> Windows OS</a>
      <a href="<?= esc($qsBuild(['type'=>'other'])) ?>" class="pill-toggle <?= $f['type']==='other'?'active':'' ?>" data-testid="type-other"><i class="bi bi-three-dots"></i> Other</a>
    </div>

    <!-- Row 3: Platform pills -->
    <div class="pill-row mb-2" data-testid="platform-pills">
      <span class="pill-label">Platform</span>
      <a href="<?= esc($qsBuild(['os'=>''])) ?>" class="pill-toggle <?= $f['os']===''?'active':'' ?>" data-testid="platform-all">All</a>
      <?php foreach ($oss as $o): ?>
        <a href="<?= esc($qsBuild(['os'=>$o])) ?>" class="pill-toggle <?= $f['os']===$o?'active':'' ?>" data-testid="platform-<?= esc($o) ?>">
          <i class="bi bi-<?= $o==='Mac'?'apple':($o==='Windows'?'windows':'pc-display') ?>"></i> <?= esc($o) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Row 3: Version (Year) pills -->
    <?php if ($years): ?>
    <div class="pill-row mb-2" data-testid="version-pills">
      <span class="pill-label">Version</span>
      <a href="<?= esc($qsBuild(['year'=>''])) ?>" class="pill-toggle <?= $f['year']===''?'active':'' ?>" data-testid="version-all">All</a>
      <?php foreach ($years as $y): ?>
        <a href="<?= esc($qsBuild(['year'=>$y])) ?>" class="pill-toggle <?= (string)$f['year']===(string)$y?'active':'' ?>" data-testid="version-<?= (int)$y ?>"><?= (int)$y ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Advanced filters (hidden by default unless any is set) -->
    <div id="advFilters" class="advanced-panel pt-3 mt-2 <?= $hasAdvanced ? '' : 'd-none' ?>" style="border-top:1px dashed var(--border);">
      <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="products">
        <?php if ($f['q']): ?><input type="hidden" name="q" value="<?= esc($f['q']) ?>"><?php endif; ?>
        <?php if ($f['os']): ?><input type="hidden" name="os" value="<?= esc($f['os']) ?>"><?php endif; ?>
        <?php if ($f['year']!==''): ?><input type="hidden" name="year" value="<?= esc($f['year']) ?>"><?php endif; ?>
        <?php if ($f['sort']!=='newest'): ?><input type="hidden" name="sort" value="<?= esc($f['sort']) ?>"><?php endif; ?>
        <div class="col-6 col-md-3 col-lg-2">
          <label class="small text-muted mb-1"><i class="bi bi-tag me-1"></i>Category</label>
          <select class="form-select form-select-sm" name="cat"><option value="">All categories</option><?php foreach($cats as $c): ?><option value="<?= esc($c) ?>" <?= $f['cat']===$c?'selected':'' ?>><?= esc($c) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <label class="small text-muted mb-1"><i class="bi bi-bookmark-star me-1"></i>Brand</label>
          <select class="form-select form-select-sm" name="brand"><option value="">All brands</option><?php foreach($brands as $b): ?><option value="<?= esc($b) ?>" <?= $f['brand']===$b?'selected':'' ?>><?= esc($b) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <label class="small text-muted mb-1"><i class="bi bi-toggle-on me-1"></i>Status</label>
          <select class="form-select form-select-sm" name="status"><option value="">All status</option><option value="1" <?= $f['status']==='1'?'selected':'' ?>>Active</option><option value="0" <?= $f['status']==='0'?'selected':'' ?>>Inactive</option></select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <label class="small text-muted mb-1"><i class="bi bi-box-seam me-1"></i>Stock</label>
          <select class="form-select form-select-sm" name="stock"><option value="">Any</option><option value="in" <?= $f['stock']==='in'?'selected':'' ?>>In stock</option><option value="low" <?= $f['stock']==='low'?'selected':'' ?>>Low (&lt;5)</option><option value="out" <?= $f['stock']==='out'?'selected':'' ?>>Out</option></select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <label class="small text-muted mb-1"><i class="bi bi-globe me-1"></i>Region</label>
          <select class="form-select form-select-sm" name="p_region"><option value="">All regions</option><?php foreach(all_regions() as $r): ?><option value="<?= esc($r['code']) ?>" <?= $f['region']===$r['code']?'selected':'' ?>><?= esc($r['code']) ?> · <?= esc($r['currency_symbol']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <label class="small text-muted mb-1"><i class="bi bi-currency-dollar me-1"></i>Price (<?= esc($rg['currency_symbol']) ?>)</label>
          <div class="d-flex gap-1">
            <input class="form-control form-control-sm" type="number" step="0.01" name="pmin" value="<?= esc($f['pmin']) ?>" placeholder="Min">
            <input class="form-control form-control-sm" type="number" step="0.01" name="pmax" value="<?= esc($f['pmax']) ?>" placeholder="Max">
          </div>
        </div>
        <div class="col-12 col-md-auto">
          <button class="btn btn-soft-blue btn-sm"><i class="bi bi-funnel me-1"></i>Apply</button>
          <a href="?tab=products" class="btn btn-soft-gray btn-sm" title="Clear all"><i class="bi bi-x-lg"></i> Clear</a>
        </div>
      </form>
    </div>

    <?php // Active filter chips
    $chips = [];
    $chipMap = ['q'=>'Search','type'=>'Type','cat'=>'Category','brand'=>'Brand','status'=>'Status','stock'=>'Stock','region'=>'Region','pmin'=>'Min','pmax'=>'Max'];
    $typeLabels = ['office'=>'Office','antivirus'=>'Antivirus','windows-os'=>'Windows OS','other'=>'Other'];
    foreach ($chipMap as $k=>$label) {
      $v = $f[$k] ?? '';
      if ($v === '' || $v === null) continue;
      if ($k==='status') $val = ($v=='1'?'Active':'Inactive');
      elseif ($k==='type') $val = $typeLabels[$v] ?? $v;
      else $val = $v;
      $remove = $_GET; unset($remove[$k==='region'?'p_region':$k]);
      $remove['tab']='products';
      $chips[] = ['label'=>$label, 'value'=>$val, 'url'=>'?'.http_build_query($remove)];
    }
    if ($chips): ?>
      <div class="d-flex gap-1 flex-wrap mt-3 pt-3" style="border-top:1px dashed var(--border);">
        <small class="text-muted me-1 mt-1">Filters:</small>
        <?php foreach ($chips as $c): ?>
          <a href="<?= esc($c['url']) ?>" class="s-badge sent text-decoration-none" style="font-size:11px;">
            <?= esc($c['label']) ?>: <strong><?= esc($c['value']) ?></strong> <i class="bi bi-x"></i>
          </a>
        <?php endforeach; ?>
        <a href="?tab=products" class="s-badge failed text-decoration-none" style="font-size:11px;">Clear all <i class="bi bi-x-lg"></i></a>
      </div>
    <?php endif; ?>
  </div>

  <!-- COMPACT PRODUCT GRID -->
  <div class="row g-4" data-testid="products-grid">
    <?php if (empty($products)): ?>
      <div class="col-12 card-e p-5 text-center text-muted">No products match the current filters.</div>
    <?php endif; ?>
    <?php foreach ($products as $p):
      $disc = ($p['original_price'] && $p['original_price'] > $p['price'])
              ? round(100 - ($p['price']/$p['original_price']*100)) : 0;
      $save = ($p['original_price'] && $p['original_price'] > $p['price']) ? $p['original_price'] - $p['price'] : 0;
      $av = (int)$p['avail_keys'];
      $sd = (int)$p['sold_keys'];
    ?>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="card-e h-100 position-relative" style="padding:14px;font-size:13px;<?= !$p['is_active']?'opacity:.55;':'' ?>" data-testid="prod-<?= esc($p['slug']) ?>">
          <?php if ($p['badge']): ?>
            <span style="position:absolute;top:10px;left:10px;background:#ef4444;color:#fff;font-weight:700;font-size:10px;padding:3px 8px;border-radius:5px;letter-spacing:.4px;z-index:1;"><?= esc($p['badge']) ?></span>
          <?php endif; ?>
          <?php if ($disc > 0): ?>
            <span style="position:absolute;top:10px;right:10px;background:#facc15;color:#854d0e;font-weight:700;font-size:11px;padding:3px 8px;border-radius:5px;z-index:1;"><?= $disc ?>% OFF</span>
          <?php endif; ?>
          <a href="?tab=products&inv=<?= urlencode($p['slug']) ?>" class="d-block text-decoration-none" style="color:inherit;" title="Click to view & manage license keys">
            <div style="height:110px;background:var(--bg);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;">
              <?php if ($p['image']): ?><img src="<?= esc($p['image']) ?>" style="max-height:100px;max-width:90%;object-fit:contain;"><?php else: ?><i class="bi bi-box-seam text-muted fs-3"></i><?php endif; ?>
            </div>
            <div class="fw-bold" title="<?= esc($p['name']) ?>" style="font-size:13px;line-height:1.3;min-height:34px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= esc($p['name']) ?></div>
            <div class="text-muted mt-1" style="font-size:11px;"><code style="font-size:10px;"><?= esc($p['sku']) ?></code> · <?= esc($p['platform']) ?></div>
          </a>
          <div class="d-flex align-items-baseline gap-2 mt-2">
            <strong style="color:#10b981;font-size:15px;"><?= esc($rg['currency_symbol']) ?><?= number_format(region_price((float)$p['price']),2) ?></strong>
            <?php if ($p['original_price'] && $p['original_price'] > $p['price']): ?>
              <small class="text-muted text-decoration-line-through" style="font-size:11px;"><?= esc($rg['currency_symbol']) ?><?= number_format(region_price((float)$p['original_price']),2) ?></small>
            <?php endif; ?>
          </div>
          <div class="d-flex justify-content-between mt-2 pt-2" style="font-size:11px;border-top:1px dashed var(--border);">
            <span title="Available keys"><span class="<?= $av==0?'text-danger':($av<5?'text-warning':'text-success') ?>">●</span> <strong><?= $av ?></strong> stock</span>
            <span title="Sold keys" class="text-primary"><i class="bi bi-cart-check"></i> <strong><?= $sd ?></strong> sold</span>
            <span class="<?= $p['is_active']?'text-success':'text-muted' ?>"><?= $p['is_active']?'Active':'Off' ?></span>
          </div>
          <div class="d-flex gap-1 mt-3">
            <a href="?tab=products&edit=<?= urlencode($p['slug']) ?>" class="btn btn-soft-blue btn-sm flex-grow-1 py-1" style="font-size:11px;" data-testid="edit-<?= esc($p['slug']) ?>" title="Edit product info"><i class="bi bi-pencil"></i> Edit</a>
            <a href="?tab=products&inv=<?= urlencode($p['slug']) ?>" class="btn btn-soft-green btn-sm flex-grow-1 py-1" style="font-size:11px;" data-testid="inv-<?= esc($p['slug']) ?>" title="Update inventory"><i class="bi bi-key"></i> Update Inventory</a>
            <form method="post" class="d-inline"><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="slug" value="<?= esc($p['slug']) ?>"><button class="btn btn-soft-gray btn-sm py-1 px-2" style="font-size:11px;" title="<?= $p['is_active']?'Disable':'Enable' ?>"><i class="bi bi-<?= $p['is_active']?'eye-slash':'eye' ?>"></i></button></form>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this product permanently?');"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="slug" value="<?= esc($p['slug']) ?>"><button class="btn btn-soft-red btn-sm py-1 px-2" style="font-size:11px;" title="Delete"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- EDIT / ADD MODAL -->
  <?php if ($editing):
    $editPrice = (float)($editing['price'] ?: 0);
    $editOrig  = (float)($editing['original_price'] ?: 0);
    $disc = ($editOrig > $editPrice && $editPrice > 0)
            ? round(100 - ($editPrice/$editOrig*100)) : 0;
    $editSave = max(0, $editOrig - $editPrice);
    $hasDisc  = $editOrig > $editPrice;
  ?>
  <div class="modal d-block" style="background:rgba(0,0,0,.55);" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content card-e" style="background:var(--card-bg);">
        <div class="modal-header" style="border-color:var(--border);">
          <h5 class="modal-title"><i class="bi bi-<?= $isAdd?'plus-square':'pencil-square' ?> me-2"></i><?= $isAdd?'Add Product':'Edit Product' ?></h5>
          <a href="?tab=products" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <form method="post" id="prodForm">
            <input type="hidden" name="action" value="<?= $isAdd?'add_product':'update_product' ?>">
            <?php if (!$isAdd): ?><input type="hidden" name="slug" value="<?= esc($editing['slug']) ?>"><?php endif; ?>
            <div class="row g-3">
              <div class="col-lg-7">
                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Product Information</h6>
                <div class="row g-2 mb-3">
                  <div class="col-12"><label class="form-label small mb-0">Product Name *</label><input class="form-control form-control-sm" id="f_name" name="name" required value="<?= esc($editing['name']) ?>"></div>
                  <div class="col-6"><label class="form-label small mb-0">SKU / Product ID</label><input class="form-control form-control-sm" id="f_sku" name="sku" value="<?= esc($editing['sku']) ?>"></div>
                  <div class="col-6"><label class="form-label small mb-0">Brand</label><input class="form-control form-control-sm" name="brand" value="<?= esc($editing['brand'] ?? '') ?>"></div>
                  <div class="col-4"><label class="form-label small mb-0">Year</label><input class="form-control form-control-sm" name="year" type="number" value="<?= esc($editing['year']) ?>"></div>
                  <div class="col-4"><label class="form-label small mb-0">OS / Platform</label>
                    <select class="form-select form-select-sm" id="f_platform" name="platform">
                      <?php foreach (['Windows','Mac','Linux','Cross-platform'] as $o): ?><option value="<?= $o ?>" <?= $editing['platform']===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-4"><label class="form-label small mb-0">License Type</label>
                    <select class="form-select form-select-sm" name="license_type">
                      <?php foreach (['lifetime','subscription','single_use','multi_use'] as $o): ?><option value="<?= $o ?>" <?= ($editing['license_type'] ?? '')===$o?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$o)) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-8"><label class="form-label small mb-0">Category</label>
                    <select class="form-select form-select-sm" id="f_cat" name="category">
                      <?php foreach ($cats as $c): ?><option value="<?= esc($c) ?>" <?= $editing['category']===$c?'selected':'' ?>><?= esc($c) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-4 d-flex align-items-end"><div class="form-check form-switch mt-2"><input type="checkbox" class="form-check-input" name="is_active" id="f_act" <?= ($editing['is_active'] ?? 1)?'checked':'' ?>><label class="form-check-label small" for="f_act">Active</label></div></div>
                  <div class="col-12"><label class="form-label small mb-0">Image URL</label><input class="form-control form-control-sm" id="f_image" name="image" value="<?= esc($editing['image']) ?>" placeholder="https://… or upload to /uploads"></div>
                  <div class="col-12"><label class="form-label small mb-0">Description</label><textarea class="form-control form-control-sm" id="f_desc" name="description" rows="3"><?= esc($editing['description'] ?? '') ?></textarea></div>
                </div>

                <h6 class="fw-bold mb-2"><i class="bi bi-tag me-1"></i>Pricing &amp; Discount</h6>
                <div class="row g-2 mb-3">
                  <div class="col-4"><label class="form-label small mb-0">Original Price</label><input class="form-control form-control-sm" id="f_orig" name="original_price" type="number" step="0.01" value="<?= esc($editing['original_price']) ?>" oninput="updPrev()"></div>
                  <div class="col-4"><label class="form-label small mb-0">Sale Price *</label><input class="form-control form-control-sm" id="f_price" name="price" type="number" step="0.01" required value="<?= esc($editing['price']) ?>" oninput="updPrev()"></div>
                  <div class="col-4">
                    <label class="form-label small mb-0">Auto-Calculated</label>
                    <div class="form-control form-control-sm bg-light" style="background:var(--bg)!important;">
                      <span class="text-danger fw-bold" id="discOut"><?= $disc ?>% OFF</span>
                      <small class="text-muted ms-1" id="saveOut">save $<?= number_format($editSave,2) ?></small>
                    </div>
                  </div>
                  <div class="col-12">
                    <label class="form-label small mb-0">Promotional Badge</label>
                    <div class="d-flex gap-1 flex-wrap mb-1">
                      <?php foreach (['Best Seller','New Arrival','Limited Time Offer','Most Popular','Hot Deal','Recommended','Featured Product','Special Discount'] as $bg): ?>
                        <button type="button" class="btn btn-soft-gray btn-sm py-0 px-2" style="font-size:11px;" onclick="document.getElementById('f_badge').value='<?= $bg ?>';updPrev();"><?= $bg ?></button>
                      <?php endforeach; ?>
                    </div>
                    <input class="form-control form-control-sm" id="f_badge" name="badge" value="<?= esc($editing['badge']) ?>" oninput="updPrev()" placeholder="Or type custom badge text">
                  </div>
                </div>

                <h6 class="fw-bold mb-2"><i class="bi bi-link-45deg me-1"></i>Activation / Sign-in URL</h6>
                <div class="row g-2 mb-3">
                  <div class="col-12">
                    <label class="form-label small mb-0">Where should the customer go to activate? <span class="badge bg-success ms-1" style="font-size:9px;">used in order email</span></label>
                    <input class="form-control form-control-sm" name="activation_url" value="<?= esc($editing['activation_url'] ?? '') ?>" placeholder="https://setup.office.com (leave blank → auto Google search per product name)" data-testid="f-activation-url">
                    <small class="text-muted">Customers see a "Sign in to activate →" button in the order email that opens this URL. Leave blank and we auto-generate a Google search link with the product name so they always land on the right page.</small>
                    <div class="d-flex gap-1 flex-wrap mt-1">
                      <?php foreach ([
                        'Office (setup)'  => 'https://setup.office.com',
                        'Microsoft Account' => 'https://account.microsoft.com',
                        'Bitdefender'     => 'https://central.bitdefender.com',
                        'McAfee'          => 'https://home.mcafee.com',
                        'Norton'          => 'https://my.norton.com',
                        'Adobe'           => 'https://account.adobe.com',
                      ] as $lbl=>$u): ?>
                        <button type="button" class="btn btn-soft-gray btn-sm py-0 px-2" style="font-size:11px;" onclick="document.querySelector('[name=activation_url]').value='<?= esc($u) ?>';"><?= esc($lbl) ?></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div class="col-12 mt-3">
                    <label class="form-label small mb-0"><i class="bi bi-book me-1"></i>Installation Guide URL <span class="badge bg-success ms-1" style="font-size:9px;">used in order email</span></label>
                    <input class="form-control form-control-sm" name="install_guide_url" value="<?= esc($editing['install_guide_url'] ?? '') ?>" placeholder="https://support.microsoft.com/install-office  (optional)" data-testid="f-install-guide-url">
                    <small class="text-muted">Customers see a "📖 View installation guide →" button in the order email that opens this URL. Use a vendor support page, your own KB article, or a YouTube tutorial — whatever helps them install fastest.</small>
                    <div class="d-flex gap-1 flex-wrap mt-1">
                      <?php foreach ([
                        'MS Office install'  => 'https://support.microsoft.com/office/install',
                        'Bitdefender install'=> 'https://www.bitdefender.com/consumer/support/answer/2099/',
                        'McAfee install'     => 'https://service.mcafee.com/?articleId=TS101331',
                        'Norton install'     => 'https://support.norton.com/sp/en/us/home/current/solutions/v138918432',
                        'Adobe install'      => 'https://helpx.adobe.com/download-install.html',
                      ] as $lbl=>$u): ?>
                        <button type="button" class="btn btn-soft-gray btn-sm py-0 px-2" style="font-size:11px;" onclick="document.querySelector('[name=install_guide_url]').value='<?= esc($u) ?>';"><?= esc($lbl) ?></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                  <button class="btn btn-soft-blue"><i class="bi bi-check2 me-1"></i><?= $isAdd?'Create Product':'Save Changes' ?></button>
                  <a href="?tab=products" class="btn btn-soft-gray">Cancel</a>
                  <?php if (!$isAdd): ?>
                    <button type="submit" formaction="?tab=products&dummy=1" name="action" value="duplicate_product" class="btn btn-soft-gray ms-auto"><i class="bi bi-files me-1"></i>Duplicate</button>
                    <button type="submit" name="action" value="delete_product" formnovalidate class="btn btn-soft-red" onclick="return confirm('Delete permanently?')"><i class="bi bi-trash me-1"></i>Delete</button>
                  <?php endif; ?>
                </div>
              </div>

              <!-- LIVE PREVIEW -->
              <div class="col-lg-5">
                <h6 class="fw-bold mb-2"><i class="bi bi-eye me-1"></i>Live Website Preview</h6>
                <div id="livePrev" class="card-e p-3" style="background:#fff;color:#1f2937;border:1px solid #e5e7eb;font-family:Arial,sans-serif;">
                  <div class="position-relative" style="height:160px;background:#f8fafc;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <span id="pvBadge" style="position:absolute;top:8px;left:8px;background:#ef4444;color:#fff;font-weight:700;font-size:11px;padding:3px 8px;border-radius:5px;<?= $editing['badge']?'':'display:none;' ?>"><?= esc($editing['badge']) ?></span>
                    <span id="pvDisc"  style="position:absolute;top:8px;right:8px;background:#facc15;color:#854d0e;font-weight:700;font-size:12px;padding:3px 8px;border-radius:5px;<?= $disc?'':'display:none;' ?>"><span id="pvDiscN"><?= $disc ?></span>% OFF</span>
                    <img id="pvImg" src="<?= esc($editing['image']) ?>" style="max-height:140px;max-width:90%;object-fit:contain;<?= $editing['image']?'':'display:none;' ?>">
                    <i id="pvNoimg" class="bi bi-box-seam text-muted" style="font-size:42px;<?= $editing['image']?'display:none;':'' ?>"></i>
                  </div>
                  <div style="font-size:11px;color:#94a3b8;letter-spacing:1px;text-transform:uppercase;" id="pvCat"><?= esc($editing['category']) ?> · <span id="pvPlatform"><?= esc($editing['platform']) ?></span></div>
                  <div style="font-size:16px;font-weight:700;margin:6px 0;color:#0f172a;" id="pvName"><?= esc($editing['name'] ?: 'Product Name') ?></div>
                  <div style="font-size:13px;color:#64748b;line-height:1.5;" id="pvDesc"><?= esc(mb_strimwidth($editing['description'] ?? '', 0, 140, '…')) ?></div>
                  <div style="margin:10px 0;">
                    <span style="color:#f59e0b;">★★★★☆</span>
                    <small style="color:#94a3b8;"><?= $editing['rating'] ?? '4.5' ?> (<?= $editing['reviews'] ?? 0 ?> reviews)</small>
                  </div>
                  <div class="d-flex align-items-baseline gap-2 mb-1">
                    <span style="font-size:22px;font-weight:800;color:#10b981;" id="pvPrice">$<?= number_format($editing['price'] ?: 0,2) ?></span>
                    <span id="pvOrig" style="font-size:14px;color:#94a3b8;text-decoration:line-through;<?= $hasDisc?'':'display:none;' ?>">$<?= number_format($editing['original_price'] ?: 0,2) ?></span>
                  </div>
                  <div id="pvSave" style="font-size:12px;color:#10b981;font-weight:600;<?= $hasDisc?'':'display:none;' ?>">You save <span id="pvSaveN">$<?= number_format($editSave,2) ?></span></div>
                  <div class="mt-2" style="font-size:12px;color:#10b981;">● <span id="pvStock">In stock — instant delivery</span></div>
                </div>

                <?php if (!$isAdd): ?>
                  <hr>
                  <h6 class="fw-bold mb-2 small"><i class="bi bi-arrow-left-right me-1"></i>Move to another category</h6>
                  <div class="d-flex gap-2">
                    <select id="moveCat" class="form-select form-select-sm">
                      <?php foreach ($cats as $c): ?><option value="<?= esc($c) ?>" <?= $editing['category']===$c?'selected':'' ?>><?= esc($c) ?></option><?php endforeach; ?>
                    </select>
                    <form method="post" onsubmit="document.getElementById('moveCatHidden').value=document.getElementById('moveCat').value;">
                      <input type="hidden" name="action" value="move_product">
                      <input type="hidden" name="slug" value="<?= esc($editing['slug']) ?>">
                      <input type="hidden" name="category" id="moveCatHidden">
                      <button class="btn btn-soft-gray btn-sm">Move</button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script>
  function updPrev() {
    var price = parseFloat(document.getElementById('f_price').value) || 0;
    var orig  = parseFloat(document.getElementById('f_orig').value)  || 0;
    var name  = document.getElementById('f_name').value || 'Product Name';
    var desc  = document.getElementById('f_desc').value || '';
    var badge = document.getElementById('f_badge').value;
    var img   = document.getElementById('f_image').value;
    var cat   = document.getElementById('f_cat').value;
    var pf    = document.getElementById('f_platform').value;

    document.getElementById('pvName').textContent = name;
    document.getElementById('pvDesc').textContent = desc.length>140?desc.substring(0,140)+'…':desc;
    document.getElementById('pvCat').firstChild.textContent = cat + ' · ';
    document.getElementById('pvPlatform').textContent = pf;
    document.getElementById('pvPrice').textContent = '$' + price.toFixed(2);
    document.getElementById('pvImg').src = img;
    document.getElementById('pvImg').style.display = img ? '' : 'none';
    document.getElementById('pvNoimg').style.display = img ? 'none' : '';
    document.getElementById('pvBadge').textContent = badge;
    document.getElementById('pvBadge').style.display = badge ? '' : 'none';
    if (orig > price) {
      var pct = Math.round(100 - (price/orig*100));
      var save = (orig-price).toFixed(2);
      document.getElementById('pvDisc').style.display='';
      document.getElementById('pvDiscN').textContent = pct;
      document.getElementById('pvOrig').textContent = '$' + orig.toFixed(2);
      document.getElementById('pvOrig').style.display='';
      document.getElementById('pvSave').style.display='';
      document.getElementById('pvSaveN').textContent = '$' + save;
      document.getElementById('discOut').textContent = pct + '% OFF';
      document.getElementById('saveOut').textContent = 'save $' + save;
    } else {
      document.getElementById('pvDisc').style.display='none';
      document.getElementById('pvOrig').style.display='none';
      document.getElementById('pvSave').style.display='none';
      document.getElementById('discOut').textContent = '0% OFF';
      document.getElementById('saveOut').textContent = 'save $0.00';
    }
  }
  </script>
  <?php endif; ?>

  <?php
  // =======================================================================
  // INVENTORY MODAL — opens when ?inv=SLUG (manage keys for one product)
  // =======================================================================
  $invSlug = $_GET['inv'] ?? '';
  $invProd = null;
  if ($invSlug) {
    $ip = $pdo->prepare('SELECT * FROM products WHERE slug=?');
    $ip->execute([$invSlug]);
    $invProd = $ip->fetch();
  }
  if ($invProd):
    $invTab = $_GET['invtab'] ?? 'available';
    $availSt = $pdo->prepare("SELECT * FROM license_keys WHERE product_slug=? AND region=? AND status='available' ORDER BY created_at DESC LIMIT 300");
    $availSt->execute([$invProd['slug'], $region_code]); $availKeys = $availSt->fetchAll();
    $soldSt = $pdo->prepare("SELECT lk.*, o.id AS o_id, o.order_number, o.email AS o_email,
                             CONCAT(COALESCE(o.first_name,''),' ',COALESCE(o.last_name,'')) AS o_name,
                             o.total AS o_total, o.payment_method AS o_pm, o.status AS o_status
                             FROM license_keys lk LEFT JOIN orders o ON o.id=lk.order_id
                             WHERE lk.product_slug=? AND lk.region=? AND lk.status='sold'
                             ORDER BY lk.assigned_at DESC LIMIT 300");
    $soldSt->execute([$invProd['slug'], $region_code]); $soldKeys = $soldSt->fetchAll();
    $cntAvail = count($availKeys); $cntSold = count($soldKeys);
  ?>
  <div class="modal d-block" style="background:rgba(0,0,0,.55);" tabindex="-1" data-testid="inv-modal">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content card-e" style="background:var(--card-bg);">
        <div class="modal-header" style="border-color:var(--border);">
          <div class="d-flex align-items-center gap-3">
            <?php if ($invProd['image']): ?><img src="<?= esc($invProd['image']) ?>" style="width:48px;height:48px;object-fit:contain;background:var(--bg);border-radius:8px;padding:4px;"><?php endif; ?>
            <div>
              <h5 class="modal-title mb-0"><i class="bi bi-key me-2 text-success"></i>Update Inventory</h5>
              <small class="text-muted"><?= esc($invProd['name']) ?> · <code><?= esc($invProd['sku']) ?></code> · Region <strong><?= esc($region_code) ?></strong></small>
            </div>
          </div>
          <a href="?tab=products<?= $f['q']?'&q='.urlencode($f['q']):'' ?>" class="btn-close" data-testid="close-inv-modal"></a>
        </div>
        <div class="modal-body">
          <!-- Two-option toggle: Available / Sold -->
          <ul class="nav nav-pills mb-3" role="tablist">
            <li class="nav-item"><a class="nav-link <?= $invTab==='available'?'active':'' ?>" href="?tab=products&inv=<?= urlencode($invProd['slug']) ?>&invtab=available" data-testid="inv-tab-available">
              <i class="bi bi-key text-success"></i> Available Keys <span class="badge bg-success ms-1"><?= $cntAvail ?></span>
            </a></li>
            <li class="nav-item"><a class="nav-link <?= $invTab==='sold'?'active':'' ?>" href="?tab=products&inv=<?= urlencode($invProd['slug']) ?>&invtab=sold" data-testid="inv-tab-sold">
              <i class="bi bi-cart-check text-primary"></i> Sold Keys <span class="badge bg-primary ms-1"><?= $cntSold ?></span>
            </a></li>
          </ul>

          <?php if ($invTab==='available'): ?>
            <div class="row g-3">
              <div class="col-lg-5">
                <div class="card-e p-3" style="background:var(--bg);">
                  <h6 class="fw-bold mb-2"><i class="bi bi-plus-circle text-success me-1"></i>Add License Keys</h6>
                  <p class="small text-muted mb-2">Paste one license key per line. Region: <strong><?= esc($region_code) ?></strong></p>
                  <form method="post">
                    <input type="hidden" name="action" value="add_keys">
                    <input type="hidden" name="product_slug" value="<?= esc($invProd['slug']) ?>">
                    <input type="hidden" name="return_slug" value="<?= esc($invProd['slug']) ?>">
                    <textarea name="keys" rows="8" required class="form-control font-monospace mb-2" placeholder="XXXX-XXXX-XXXX-XXXX&#10;YYYY-YYYY-YYYY-YYYY" data-testid="inv-add-keys-textarea"></textarea>
                    <button class="btn btn-soft-blue w-100" data-testid="inv-add-keys-submit"><i class="bi bi-plus-circle me-1"></i>Add to Inventory</button>
                  </form>
                </div>
              </div>
              <div class="col-lg-7">
                <h6 class="fw-bold mb-2"><i class="bi bi-key text-success me-1"></i>Available keys (<?= $cntAvail ?>)</h6>
                <div class="tbl-e" style="max-height:420px;overflow-y:auto;">
                  <table class="table mb-0">
                    <thead><tr><th>License Key</th><th>Added</th><th></th></tr></thead>
                    <tbody>
                      <?php if (empty($availKeys)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4"><i class="bi bi-inbox"></i> No available keys — add some on the left.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($availKeys as $k): ?>
                        <tr>
                          <td><code style="font-size:12px;"><?= esc($k['license_key']) ?></code></td>
                          <td><small class="text-muted"><?= esc(date('M j, Y', strtotime($k['created_at']))) ?></small></td>
                          <td><form method="post" class="d-inline" onsubmit="return confirm('Delete this key?');">
                            <input type="hidden" name="action" value="delete_key">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <input type="hidden" name="return_slug" value="<?= esc($invProd['slug']) ?>">
                            <button class="btn btn-soft-red btn-sm py-0 px-2"><i class="bi bi-trash"></i></button>
                          </form></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          <?php else: // sold tab ?>
            <h6 class="fw-bold mb-2"><i class="bi bi-cart-check text-primary me-1"></i>Sold keys (<?= $cntSold ?>) <small class="text-muted fw-normal">— click any row to view full purchase details</small></h6>
            <div class="tbl-e">
              <table class="table mb-0">
                <thead><tr><th>License Key</th><th>Customer</th><th>Order</th><th>Paid</th><th>Sold On</th><th></th></tr></thead>
                <tbody>
                  <?php if (empty($soldKeys)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-bag-x"></i> No keys sold yet for this product.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($soldKeys as $sk):
                    $oid = (int)($sk['o_id'] ?? 0);
                    $rowHref = $oid ? 'order-view.php?id='.$oid : '#';
                  ?>
                    <tr style="cursor:<?= $oid?'pointer':'default' ?>;" onclick="<?= $oid ? "window.location='".esc($rowHref)."'" : '' ?>" data-testid="inv-sold-key-<?= (int)$sk['id'] ?>">
                      <td><code style="font-size:12px;"><?= esc($sk['license_key']) ?></code></td>
                      <td>
                        <strong style="font-size:13px;"><?= esc($sk['o_name'] ?? '—') ?></strong>
                        <div><small class="text-muted"><?= esc($sk['o_email'] ?? '') ?></small></div>
                      </td>
                      <td><?= $sk['order_number'] ? '<code class="small">#'.esc($sk['order_number']).'</code>' : '—' ?>
                        <div><small class="text-muted"><?= esc(ucfirst($sk['o_pm'] ?? '')) ?></small></div></td>
                      <td><strong><?= esc($rg['currency_symbol']) ?><?= number_format(region_price((float)($sk['o_total'] ?? 0)),2) ?></strong>
                        <div><span class="s-badge <?= ($sk['o_status']??'')==='paid'?'paid':'queued' ?>" style="font-size:10px;"><?= esc($sk['o_status'] ?? '—') ?></span></div></td>
                      <td><small class="text-muted"><?= $sk['assigned_at'] ? esc(date('M j, Y H:i', strtotime($sk['assigned_at']))) : '—' ?></small></td>
                      <td><?php if ($oid): ?><a href="<?= esc($rowHref) ?>" class="btn btn-soft-blue btn-sm py-0 px-2" onclick="event.stopPropagation();"><i class="bi bi-arrow-right-circle"></i> View order</a><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php
// ============================================================================
// ORDERS (region-filtered, click → order-view.php)
// ============================================================================
elseif ($tab === 'orders'): ?>
  <h5 class="fw-bold mb-3">Orders <span class="text-muted fs-6">(<?= esc($rg['name']) ?>)</span></h5>
  <div class="tbl-e">
    <table class="table mb-0" data-testid="admin-orders-table">
      <thead><tr><th>Order / Status</th><th>Customer</th><th>Total</th><th>Payment</th><th>Fulfill</th><th></th></tr></thead>
      <tbody>
        <?php
        $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE region=? ORDER BY created_at DESC LIMIT 200");
        $orderStmt->execute([$region_code]);
        foreach ($orderStmt as $o): ?>
          <tr style="cursor:pointer;" onclick="location.href='order-view.php?id=<?= (int)$o['id'] ?>'">
            <td>
              <strong>#<?= esc($o['order_number']) ?></strong>
              <span class="s-badge <?= esc($o['status']) ?> text-capitalize ms-1" style="font-size:10px;"><?= esc($o['status']) ?></span>
              <br><small class="text-muted"><?= esc(date('M j, Y · H:i', strtotime($o['created_at']))) ?></small>
            </td>
            <td><?= esc($o['first_name'].' '.$o['last_name']) ?><br><small class="text-muted"><?= esc($o['email']) ?></small></td>
            <td class="fw-bold"><?= region_money((float)$o['total']) ?></td>
            <td><span class="s-badge sent text-capitalize"><?= esc($o['payment_method']) ?></span></td>
            <td><?= $o['fulfilled'] ? '<span class="s-badge delivered">Fulfilled</span>' : '<span class="s-badge queued">Pending</span>' ?></td>
            <td onclick="event.stopPropagation()"><a class="btn btn-soft-blue btn-sm py-0 px-2" href="order-view.php?id=<?= (int)$o['id'] ?>" data-testid="open-order-<?= (int)$o['id'] ?>"><i class="bi bi-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php
// ============================================================================
// SALES DETAIL (full with email status)
// ============================================================================
elseif ($tab === 'sales'):
  $sales = $pdo->prepare("SELECT o.*, oi.name AS product_name, oi.product_slug, oi.price AS unit_price, oi.qty,
      lk.license_key, lk.assigned_at,
      em.status AS email_status, em.opened_at, em.opened_count, em.id AS email_id, em.delivered_at
    FROM orders o
    JOIN order_items oi ON oi.order_id=o.id
    LEFT JOIN license_keys lk ON lk.order_id=o.id AND lk.product_slug=oi.product_slug
    LEFT JOIN email_outbox em ON em.order_id=o.id
    WHERE o.status IN ('paid','delivered') AND o.region=?
    ORDER BY o.created_at DESC LIMIT 500");
  $sales->execute([$region_code]);
?>
  <h5 class="fw-bold mb-3">Sales Detail — <?= esc($rg['name']) ?></h5>
  <div class="tbl-e">
    <table class="table mb-0">
      <thead><tr><th>Date</th><th>Order#</th><th>Customer</th><th>Country</th><th>Product</th><th>Amount</th><th>License Key Sent</th><th>Email</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($sales as $s):
          $emStatus = $s['opened_at'] ? 'opened' : ($s['email_status'] ?: 'pending'); ?>
          <tr>
            <td><small><?= esc(date('M j, Y', strtotime($s['created_at']))) ?></small></td>
            <td><strong>#<?= esc($s['order_number']) ?></strong></td>
            <td><small><?= esc($s['first_name'].' '.$s['last_name']) ?><br><span class="text-muted"><?= esc($s['email']) ?></span></small></td>
            <td><small><?= esc($s['country'] ?: '—') ?></small></td>
            <td><small><?= esc($s['product_name']) ?> ×<?= (int)$s['qty'] ?></small></td>
            <td><strong><?= region_money((float)$s['unit_price']*(int)$s['qty']) ?></strong></td>
            <td><?php if ($s['license_key']): ?><code style="background:var(--blue-soft);color:var(--brand-dk);padding:3px 8px;border-radius:6px;font-size:12px;"><?= esc($s['license_key']) ?></code><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
            <td><span class="s-badge <?= $emStatus ?>"><?= esc($emStatus) ?></span><?php if ($s['opened_at']): ?><br><small class="text-muted">opened <?= (int)$s['opened_count'] ?>×</small><?php endif; ?></td>
            <td>
              <a href="order-view.php?id=<?= (int)$s['id'] ?>" class="btn btn-soft-blue btn-sm py-0 px-2" title="Open order"><i class="bi bi-eye"></i></a>
              <?php if ($s['email_id']): ?><a href="email-view.php?id=<?= (int)$s['email_id'] ?>" target="_blank" class="btn btn-soft-gray btn-sm py-0 px-2" title="View email"><i class="bi bi-envelope"></i></a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php
// ============================================================================
// LEAD MANAGEMENT
// ============================================================================
elseif ($tab === 'leads'):
  $open = (int)($_GET['open'] ?? 0);
  $statusFilter = $_GET['status'] ?? '';
  $w=''; $args=[];
  if ($statusFilter) { $w = ' WHERE status=?'; $args[]=$statusFilter; }
  $st = $pdo->prepare("SELECT * FROM chat_leads $w ORDER BY created_at DESC LIMIT 200");
  $st->execute($args);
  $leads = $st->fetchAll();
  $admins = $pdo->query("SELECT id, email FROM users WHERE role='admin'")->fetchAll();
?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0">Lead Management</h5>
    <div class="d-flex gap-2">
      <?php foreach (['' => 'All', 'new'=>'New', 'contacted'=>'Contacted', 'qualified'=>'Qualified', 'converted'=>'Converted', 'lost'=>'Lost'] as $k=>$lbl): ?>
        <a class="adm-pill <?= $statusFilter===$k?'active':'' ?>" href="?tab=leads<?= $k?'&status='.$k:'' ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-<?= $open?'7':'12' ?>">
      <div class="tbl-e">
        <table class="table mb-0" data-testid="leads-table">
          <thead><tr><th>Name</th><th>Contact</th><th>Product</th><th>Status</th><th>Assigned</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($leads)): ?><tr><td colspan="6" class="text-center text-muted py-4">No leads found.</td></tr><?php endif; ?>
            <?php foreach ($leads as $l):
              $assignEmail = '';
              if ($l['assigned_to']) {
                foreach ($admins as $a) if ($a['id']==$l['assigned_to']) $assignEmail = $a['email'];
              }
            ?>
              <tr style="cursor:pointer;<?= $open==$l['id']?'background:var(--blue-soft);':'' ?>" onclick="location.href='?tab=leads&open=<?= $l['id'] ?>'">
                <td class="fw-semibold"><?= esc($l['name'] ?: 'Anonymous') ?><?php if ($l['callback_requested']): ?> <i class="bi bi-telephone-fill text-warning ms-1" title="Callback requested"></i><?php endif; ?></td>
                <td><small><?= esc($l['email'] ?: '—') ?><br><?= esc($l['phone'] ?: '') ?></small></td>
                <td><small><?= esc($l['requested_product'] ?: '—') ?></small></td>
                <td><span class="s-badge <?= esc($l['status']) ?>"><?= esc($l['status']) ?></span></td>
                <td><small><?= esc($assignEmail ?: '—') ?></small></td>
                <td><small class="text-muted"><?= esc(date('M j, Y', strtotime($l['created_at']))) ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($open):
      $lead = $pdo->prepare('SELECT * FROM chat_leads WHERE id=?'); $lead->execute([$open]); $lead = $lead->fetch();
      $notes = $pdo->prepare('SELECT * FROM lead_notes WHERE lead_id=? ORDER BY created_at DESC'); $notes->execute([$open]); $notes = $notes->fetchAll();
    ?>
    <div class="col-lg-5">
      <div class="card-e p-4 sticky-top" style="top:90px;">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h6 class="fw-bold mb-0"><?= esc($lead['name'] ?: 'Anonymous Lead') ?></h6>
          <a href="?tab=leads" class="btn-close" style="font-size:12px;"></a>
        </div>
        <div class="row g-2 small mb-3">
          <div class="col-6"><span class="text-muted">Email:</span><br><strong><?= esc($lead['email'] ?: '—') ?></strong></div>
          <div class="col-6"><span class="text-muted">Phone:</span><br><strong><?= esc($lead['phone'] ?: '—') ?></strong></div>
          <div class="col-6"><span class="text-muted">Country:</span><br><strong><?= esc($lead['country'] ?: '—') ?></strong></div>
          <div class="col-6"><span class="text-muted">Created:</span><br><strong><?= esc(date('M j, Y H:i', strtotime($lead['created_at']))) ?></strong></div>
          <?php if ($lead['callback_requested']): ?><div class="col-12"><span class="s-badge new">Callback Requested</span></div><?php endif; ?>
          <?php if ($lead['message']): ?><div class="col-12 mt-2"><span class="text-muted">Message:</span><div class="p-2 mt-1 rounded" style="background:var(--bg);"><?= esc($lead['message']) ?></div></div><?php endif; ?>
        </div>

        <form method="post" class="border-top pt-3">
          <input type="hidden" name="action" value="update_lead">
          <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small mb-0">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php foreach (['new','contacted','qualified','converted','lost'] as $s): ?>
                  <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6"><label class="form-label small mb-0">Assigned to</label>
              <select name="assigned_to" class="form-select form-select-sm">
                <option value="">— Unassigned —</option>
                <?php foreach ($admins as $a): ?><option value="<?= $a['id'] ?>" <?= $lead['assigned_to']==$a['id']?'selected':'' ?>><?= esc($a['email']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label small mb-0">Requested Product</label>
              <input class="form-control form-control-sm" name="requested_product" value="<?= esc($lead['requested_product']) ?>">
            </div>
            <div class="col-12"><label class="form-label small mb-0">Add Follow-up Note</label>
              <textarea name="note" class="form-control form-control-sm" rows="2" placeholder="Internal note (optional)"></textarea>
            </div>
          </div>
          <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Update Lead</button>
        </form>

        <?php if ($notes): ?>
          <h6 class="fw-bold mt-3 mb-2 small">Follow-up History</h6>
          <?php foreach ($notes as $n): ?>
            <div class="p-2 mb-2 rounded small" style="background:var(--bg);border-left:3px solid var(--brand);">
              <div><?= nl2br(esc($n['note'])) ?></div>
              <div class="text-muted mt-1" style="font-size:11px;"><?= esc($n['author_name'] ?: 'admin') ?> · <?= esc(date('M j, H:i', strtotime($n['created_at']))) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

<?php
// ============================================================================
// KEY INVENTORY
// ============================================================================
elseif ($tab === 'keys'):
  // ============================================================================
  // MIXED INVENTORY + KEYS VIEW — per-product card with stock / sold / add-key
  // ============================================================================
  $invFilter = trim($_GET['inv_q'] ?? '');
  $expandSlug = $_GET['expand'] ?? '';

  // Build product list scoped to current region, with key counts
  $sqlInv = "SELECT p.slug, p.name, p.sku, p.image, p.platform, p.category, p.price, p.is_active,
              (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.region=? AND lk.status='available') AS stock,
              (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.region=? AND lk.status='sold')      AS sold
            FROM products p WHERE p.region=?";
  $args = [$region_code, $region_code, $region_code];
  if ($invFilter !== '') { $sqlInv .= " AND (p.name LIKE ? OR p.sku LIKE ?)"; $args[]="%$invFilter%"; $args[]="%$invFilter%"; }
  $sqlInv .= " ORDER BY p.is_active DESC, p.name ASC LIMIT 500";
  $stInv = $pdo->prepare($sqlInv); $stInv->execute($args);
  $invProducts = $stInv->fetchAll();

  // Totals (region scope)
  $totalAvail = $pdo->prepare("SELECT COUNT(*) FROM license_keys WHERE region=? AND status='available'");
  $totalAvail->execute([$region_code]); $kpiAvail = (int)$totalAvail->fetchColumn();
  $totalSold = $pdo->prepare("SELECT COUNT(*) FROM license_keys WHERE region=? AND status='sold'");
  $totalSold->execute([$region_code]); $kpiSold = (int)$totalSold->fetchColumn();
  $kpiOutCount = 0; $kpiLowCount = 0;
  foreach ($invProducts as $ip) { if ((int)$ip['stock']===0) $kpiOutCount++; elseif ((int)$ip['stock']<5) $kpiLowCount++; }
?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h5 class="fw-bold mb-0">Inventory &amp; Keys <span class="text-muted fs-6">— <?= esc($rg['code']) ?> region</span></h5>
      <small class="text-muted">Select a product to add license keys, view stock vs sold counts, and drill into purchase details.</small>
    </div>
    <form method="get" class="d-flex gap-2" style="min-width:260px;">
      <input type="hidden" name="tab" value="keys">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input class="form-control" name="inv_q" value="<?= esc($invFilter) ?>" placeholder="Search products by name or SKU…">
        <?php if ($invFilter): ?><a href="?tab=keys" class="btn btn-soft-gray"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </div>
    </form>
  </div>

  <!-- KPI tiles -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="kpi-tile blue"><div class="kpi-icon"><i class="bi bi-box-seam"></i></div><div class="kpi-label">Products</div><div class="kpi-value" data-testid="kpi-products"><?= count($invProducts) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-tile green"><div class="kpi-icon"><i class="bi bi-key"></i></div><div class="kpi-label">Keys in stock</div><div class="kpi-value" data-testid="kpi-stock"><?= $kpiAvail ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-tile amber"><div class="kpi-icon"><i class="bi bi-cart-check"></i></div><div class="kpi-label">Keys sold</div><div class="kpi-value" data-testid="kpi-sold"><?= $kpiSold ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-tile red"><div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-label">Out / Low (&lt;5)</div><div class="kpi-value"><?= $kpiOutCount ?> <small class="text-muted fs-6">/ <?= $kpiLowCount ?></small></div></div></div>
  </div>

  <?php if (empty($invProducts)): ?>
    <div class="card-e p-5 text-center text-muted">No products in this region match the filter.</div>
  <?php endif; ?>

  <!-- Per-product inventory rows -->
  <div class="d-flex flex-column gap-2" data-testid="inventory-list">
    <?php foreach ($invProducts as $ip):
      $isExpanded = ($expandSlug === $ip['slug']);
      $stock = (int)$ip['stock']; $sold = (int)$ip['sold'];
      $stockColor = $stock===0 ? '#ef4444' : ($stock<5 ? '#f59e0b' : '#10b981');
      $stockLabel = $stock===0 ? 'Out of stock' : ($stock<5 ? 'Low stock' : 'In stock');
    ?>
      <div class="card-e p-0 overflow-hidden" data-testid="inv-row-<?= esc($ip['slug']) ?>">
        <!-- Compact row -->
        <a href="?tab=keys<?= $invFilter ? '&inv_q='.urlencode($invFilter) : '' ?><?= $isExpanded ? '' : '&expand='.urlencode($ip['slug']) ?>" class="d-flex align-items-center gap-3 p-3 text-decoration-none" style="color:var(--text);">
          <div style="width:48px;height:48px;background:var(--bg);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <?php if ($ip['image']): ?><img src="<?= esc($ip['image']) ?>" style="max-width:42px;max-height:42px;object-fit:contain;"><?php else: ?><i class="bi bi-box-seam text-muted"></i><?php endif; ?>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold text-truncate" style="font-size:14px;"><?= esc($ip['name']) ?></div>
            <div class="text-muted small text-truncate"><code style="font-size:11px;"><?= esc($ip['sku']) ?></code> · <?= esc($ip['platform']) ?> · <?= esc($ip['category']) ?> · <strong><?= esc($rg['currency_symbol']) ?><?= number_format(region_price((float)$ip['price']),2) ?></strong></div>
          </div>
          <div class="text-center" style="min-width:90px;">
            <div class="fw-bold" style="font-size:18px;color:<?= $stockColor ?>;"><?= $stock ?></div>
            <small class="text-muted">Stock</small>
          </div>
          <div class="text-center" style="min-width:90px;">
            <div class="fw-bold" style="font-size:18px;color:#3b82f6;"><?= $sold ?></div>
            <small class="text-muted">Sold</small>
          </div>
          <span class="s-badge <?= $stock===0?'failed':($stock<5?'queued':'paid') ?>" style="min-width:90px;text-align:center;"><?= $stockLabel ?></span>
          <i class="bi bi-chevron-<?= $isExpanded?'up':'down' ?> text-muted ms-2"></i>
        </a>

        <?php if ($isExpanded):
          $availSt = $pdo->prepare("SELECT * FROM license_keys WHERE product_slug=? AND region=? AND status='available' ORDER BY created_at DESC LIMIT 200");
          $availSt->execute([$ip['slug'], $region_code]);
          $availKeys = $availSt->fetchAll();
          $soldSt = $pdo->prepare("SELECT lk.*, o.id AS o_id, o.order_number, o.email AS o_email,
                                   CONCAT(COALESCE(o.first_name,''),' ',COALESCE(o.last_name,'')) AS o_name,
                                   o.total AS o_total, o.payment_method AS o_pm, o.status AS o_status, o.created_at AS o_created
                                   FROM license_keys lk LEFT JOIN orders o ON o.id=lk.order_id
                                   WHERE lk.product_slug=? AND lk.region=? AND lk.status='sold'
                                   ORDER BY lk.assigned_at DESC LIMIT 200");
          $soldSt->execute([$ip['slug'], $region_code]);
          $soldKeys = $soldSt->fetchAll();
        ?>
        <div class="p-3" style="border-top:1px solid var(--border);background:var(--bg);">
          <div class="row g-3">
            <!-- Add Keys form -->
            <div class="col-lg-5">
              <div class="card-e p-3" style="background:var(--card-bg);">
                <h6 class="fw-bold mb-2"><i class="bi bi-plus-circle text-success me-1"></i>Add License Keys</h6>
                <p class="small text-muted mb-2">Paste one license key per line. Region: <strong><?= esc($region_code) ?></strong></p>
                <form method="post">
                  <input type="hidden" name="action" value="add_keys">
                  <input type="hidden" name="product_slug" value="<?= esc($ip['slug']) ?>">
                  <input type="hidden" name="return_slug" value="<?= esc($ip['slug']) ?>">
                  <textarea name="keys" rows="6" required class="form-control font-monospace mb-2" placeholder="XXXX-XXXX-XXXX-XXXX&#10;YYYY-YYYY-YYYY-YYYY" data-testid="add-keys-<?= esc($ip['slug']) ?>"></textarea>
                  <button class="btn btn-soft-blue w-100" data-testid="submit-keys-<?= esc($ip['slug']) ?>"><i class="bi bi-plus-circle me-1"></i>Add to Inventory</button>
                </form>
              </div>
            </div>

            <!-- Available keys -->
            <div class="col-lg-7">
              <h6 class="fw-bold mb-2"><i class="bi bi-key text-success me-1"></i>Available keys (<?= count($availKeys) ?>)</h6>
              <div class="tbl-e mb-3" style="max-height:230px;overflow-y:auto;">
                <table class="table mb-0">
                  <thead><tr><th>License Key</th><th>Added</th><th></th></tr></thead>
                  <tbody>
                    <?php if (empty($availKeys)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-3"><i class="bi bi-inbox"></i> No available keys yet — add some on the left.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($availKeys as $k): ?>
                      <tr>
                        <td><code style="font-size:12px;"><?= esc($k['license_key']) ?></code></td>
                        <td><small class="text-muted"><?= esc(date('M j, Y', strtotime($k['created_at']))) ?></small></td>
                        <td><form method="post" class="d-inline" onsubmit="return confirm('Delete this key?');">
                          <input type="hidden" name="action" value="delete_key">
                          <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                          <input type="hidden" name="return_slug" value="<?= esc($ip['slug']) ?>">
                          <button class="btn btn-soft-red btn-sm py-0 px-2"><i class="bi bi-trash"></i></button>
                        </form></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <!-- Sold keys with click → order-view.php -->
              <h6 class="fw-bold mb-2"><i class="bi bi-cart-check text-primary me-1"></i>Sold keys (<?= count($soldKeys) ?>) <small class="text-muted fw-normal">— click any row to view purchase details</small></h6>
              <div class="tbl-e" style="max-height:260px;overflow-y:auto;">
                <table class="table mb-0">
                  <thead><tr><th>License Key</th><th>Customer</th><th>Order</th><th>Paid</th><th>Sold On</th></tr></thead>
                  <tbody>
                    <?php if (empty($soldKeys)): ?>
                      <tr><td colspan="5" class="text-center text-muted py-3"><i class="bi bi-bag-x"></i> No keys sold yet for this product.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($soldKeys as $sk):
                      $oid = (int)($sk['o_id'] ?? 0);
                      $rowHref = $oid ? 'order-view.php?id='.$oid : '#';
                    ?>
                      <tr style="cursor:<?= $oid?'pointer':'default' ?>;" onclick="<?= $oid ? "window.location='".esc($rowHref)."'" : '' ?>" data-testid="sold-key-<?= (int)$sk['id'] ?>">
                        <td><code style="font-size:12px;"><?= esc($sk['license_key']) ?></code></td>
                        <td>
                          <strong style="font-size:13px;"><?= esc($sk['o_name'] ?? '—') ?></strong>
                          <div><small class="text-muted"><?= esc($sk['o_email'] ?? '') ?></small></div>
                        </td>
                        <td><?= $sk['order_number'] ? '<code class="small">#'.esc($sk['order_number']).'</code>' : '—' ?>
                          <div><small class="text-muted"><?= esc(ucfirst($sk['o_pm'] ?? '')) ?></small></div></td>
                        <td><strong><?= esc($rg['currency_symbol']) ?><?= number_format(region_price((float)($sk['o_total'] ?? 0)),2) ?></strong>
                          <div><span class="s-badge <?= ($sk['o_status']??'')==='paid'?'paid':'queued' ?>" style="font-size:10px;"><?= esc($sk['o_status'] ?? '—') ?></span></div></td>
                        <td><small class="text-muted"><?= $sk['assigned_at'] ? esc(date('M j, Y H:i', strtotime($sk['assigned_at']))) : '—' ?></small>
                          <?php if ($oid): ?><div><a href="<?= esc($rowHref) ?>" class="small text-decoration-none" onclick="event.stopPropagation();"><i class="bi bi-arrow-right-circle"></i> View order</a></div><?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php ?>

<?php
// ============================================================================
// EMAIL ACTIVITY CENTER
// ============================================================================
elseif ($tab === 'emails'):
  $c = $pdo->query("SELECT SUM(status='queued') q, SUM(status='sent') s, SUM(opened_at IS NOT NULL) o, SUM(status='failed') f, COUNT(*) t FROM email_outbox")->fetch();
?>
  <h5 class="fw-bold mb-1">Email Activity Center</h5>
  <p class="text-muted small mb-3">Every transactional email — with delivery, open and click tracking. Click <i class="bi bi-eye"></i> to view the exact email the customer received.</p>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Sent</small><div class="fs-4 fw-bold" style="color:#3b82f6;"><?= (int)$c['s'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Opened</small><div class="fs-4 fw-bold text-success"><?= (int)$c['o'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Queued</small><div class="fs-4 fw-bold" style="color:#d97706;"><?= (int)$c['q'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Failed</small><div class="fs-4 fw-bold text-danger"><?= (int)$c['f'] ?></div></div></div>
  </div>

  <div class="tbl-e">
    <table class="table mb-0" data-testid="email-activity">
      <thead><tr><th>Customer</th><th>Phone</th><th>License Key</th><th>Email Subject</th><th>Order</th><th>Sent &amp; Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php
        $emQuery = $pdo->query("SELECT em.*, o.order_number, o.first_name, o.last_name, o.phone,
                                  (SELECT GROUP_CONCAT(lk.license_key SEPARATOR '|')
                                     FROM license_keys lk WHERE lk.order_id=em.order_id) AS keys_list
                                FROM email_outbox em LEFT JOIN orders o ON o.id=em.order_id
                                ORDER BY em.created_at DESC LIMIT 200");
        $rowCount = 0;
        foreach ($emQuery as $e):
          $rowCount++;
          $ds = $e['status'];
          $custName = trim(($e['first_name'] ?? '').' '.($e['last_name'] ?? ''));
          $oid = (int)($e['order_id'] ?? 0);
          $detailHref = $oid ? 'order-view.php?id='.$oid : '#';
          // Template label
          $tplLabels = [
            'order_delivery'  => ['label'=>'License delivery', 'color'=>'#2563eb', 'bg'=>'#dbeafe'],
            'review_request'  => ['label'=>'Review request',   'color'=>'#7c3aed', 'bg'=>'#ede9fe'],
            'order_confirmation' => ['label'=>'Order confirm', 'color'=>'#10b981', 'bg'=>'#d1fae5'],
          ];
          $tpl = $tplLabels[$e['template_code']] ?? ['label'=>($e['template_code'] ?: 'inline'), 'color'=>'#475569', 'bg'=>'#f1f5f9'];
        ?>
          <tr>
            <!-- Customer: clickable name -->
            <td style="min-width:180px;">
              <?php if ($custName !== '' && $oid): ?>
                <a href="<?= esc($detailHref) ?>" class="fw-bold text-decoration-none" style="font-size:13.5px;color:var(--brand-dk,#1d4ed8);" data-testid="customer-link-<?= (int)$e['id'] ?>" title="View full transaction detail">
                  <?= esc($custName) ?> <i class="bi bi-box-arrow-up-right" style="font-size:10px;opacity:.6;"></i>
                </a>
              <?php elseif ($custName !== ''): ?>
                <strong style="font-size:13.5px;"><?= esc($custName) ?></strong>
              <?php endif; ?>
              <div><small class="text-muted"><i class="bi bi-envelope" style="font-size:10px;"></i> <?= esc($e['recipient']) ?></small></div>
            </td>
            <!-- Phone -->
            <td><small><?= $e['phone'] ? '<i class="bi bi-telephone-fill text-success" style="font-size:10px;"></i> '.esc($e['phone']) : '<span class="text-muted">—</span>' ?></small></td>
            <!-- License Key + "sold" tag inline -->
            <td>
              <?php if (!empty($e['keys_list'])): ?>
                <?php foreach (explode('|', $e['keys_list']) as $lk): ?>
                  <div class="lk-row">
                    <code class="lk-pill"><?= esc($lk) ?></code>
                    <span class="sold-tag" title="License key has been sold &amp; assigned">SOLD</span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <small class="text-muted">—</small>
              <?php endif; ?>
            </td>
            <!-- Subject + template chip -->
            <td style="min-width:220px;">
              <div style="font-size:13px;"><?= esc(mb_strimwidth($e['subject'],0,48,'…')) ?></div>
              <span class="tpl-chip" data-tpl="<?= esc($e['template_code'] ?: 'inline') ?>"><i class="bi bi-tag-fill" style="font-size:9px;"></i> <?= esc($tpl['label']) ?></span>
            </td>
            <!-- Order link -->
            <td><?= $e['order_number'] ? '<a href="'.esc($detailHref).'" class="fw-semibold" style="font-size:12.5px;"><code>#'.esc($e['order_number']).'</code></a>' : '<small class="text-muted">—</small>' ?></td>
            <!-- Sent timestamp + status INLINE in same cell -->
            <td style="min-width:170px;">
              <div class="d-flex align-items-center gap-2">
                <?php if ($ds === 'sent'): ?>
                  <span class="s-badge paid" data-testid="status-badge-<?= (int)$e['id'] ?>" style="font-weight:600;font-size:10.5px;"><i class="bi bi-check-circle-fill me-1"></i>Success</span>
                <?php elseif ($ds === 'failed'): ?>
                  <span class="s-badge failed" data-testid="status-badge-<?= (int)$e['id'] ?>" style="font-weight:600;font-size:10.5px;"><i class="bi bi-x-circle-fill me-1"></i>Failed</span>
                <?php else: ?>
                  <span class="s-badge <?= esc($ds) ?>" data-testid="status-badge-<?= (int)$e['id'] ?>" style="font-weight:600;font-size:10.5px;"><i class="bi bi-hourglass-split me-1"></i><?= esc(ucfirst($ds)) ?></span>
                <?php endif; ?>
                <small class="text-muted" style="line-height:1.2;"><?= esc(date('M j', strtotime($e['created_at']))) ?><br><?= esc(date('H:i', strtotime($e['created_at']))) ?></small>
              </div>
              <?php if ($e['opened_at']): ?>
                <div class="mt-1"><i class="bi bi-eye-fill text-success" style="font-size:11px;"></i> <small class="text-success" style="font-size:10px;font-weight:600;"><?= (int)$e['opened_count'] ?> open<?= $e['opened_count']>1?'s':'' ?></small>
                <?php if ($e['clicked_at']): ?> · <i class="bi bi-cursor-fill text-primary" style="font-size:11px;"></i> <small class="text-primary" style="font-size:10px;font-weight:600;"><?= (int)$e['click_count'] ?> click<?= $e['click_count']>1?'s':'' ?></small><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($e['note']): ?><div class="mt-1"><small class="text-danger" title="<?= esc($e['note']) ?>" style="font-size:10px;"><i class="bi bi-exclamation-circle"></i> <?= esc(mb_strimwidth($e['note'],0,30,'…')) ?></small></div><?php endif; ?>
            </td>
            <!-- Actions -->
            <td class="text-end text-nowrap">
              <a class="btn btn-soft-blue btn-sm py-0 px-2" href="email-view.php?id=<?= (int)$e['id'] ?>" target="_blank" data-testid="view-email-<?= (int)$e['id'] ?>" title="View email content"><i class="bi bi-eye"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Resend this email to <?= esc($e['recipient']) ?>?');">
                <input type="hidden" name="action" value="resend_outbox">
                <input type="hidden" name="email_id" value="<?= (int)$e['id'] ?>">
                <button class="btn btn-soft-green btn-sm py-0 px-2" data-testid="resend-email-<?= (int)$e['id'] ?>" title="Resend to same recipient"><i class="bi bi-arrow-clockwise"></i></button>
              </form>
              <button type="button" class="btn btn-soft-gray btn-sm py-0 px-2" data-testid="edit-resend-<?= (int)$e['id'] ?>" title="Edit recipient &amp; resend"
                      onclick="document.getElementById('editResend<?= (int)$e['id'] ?>').classList.toggle('d-none');"><i class="bi bi-pencil-square"></i></button>
              <div id="editResend<?= (int)$e['id'] ?>" class="d-none mt-2 p-2 card-e" style="background:var(--card-bg);text-align:left;position:absolute;right:14px;z-index:10;min-width:280px;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                <small class="text-muted d-block mb-1">Resend to a different address:</small>
                <form method="post" class="d-flex gap-1 align-items-center">
                  <input type="hidden" name="action" value="resend_outbox">
                  <input type="hidden" name="email_id" value="<?= (int)$e['id'] ?>">
                  <input type="email" name="new_recipient" class="form-control form-control-sm" required placeholder="new@email.com" value="<?= esc($e['recipient']) ?>" data-testid="new-recipient-<?= (int)$e['id'] ?>">
                  <button class="btn btn-soft-blue btn-sm py-0 px-2" data-testid="confirm-resend-<?= (int)$e['id'] ?>" title="Send"><i class="bi bi-send"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rowCount === 0): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:24px;"></i><br>No transactional emails yet. They'll appear here automatically after the first order.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php
// ============================================================================
// EMAIL TEMPLATES (multiple + version history)
// ============================================================================
elseif ($tab === 'templates'):
  $editId = (int)($_GET['edit'] ?? 0);
  $tpls = $pdo->query('SELECT * FROM email_templates ORDER BY name')->fetchAll();
  $editing = null;
  if ($editId) {
    $s = $pdo->prepare('SELECT * FROM email_templates WHERE id=?'); $s->execute([$editId]); $editing = $s->fetch();
  }
?>
  <h5 class="fw-bold mb-3">Email Templates</h5>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card-e p-2">
        <?php foreach ($tpls as $t): ?>
          <div class="d-flex align-items-stretch gap-1 mb-1 tpl-row <?= $editId==$t['id']?'tpl-row-active':'' ?>" data-testid="tpl-row-<?= esc($t['code']) ?>">
            <a href="?tab=templates&edit=<?= (int)$t['id'] ?>" class="flex-grow-1 px-3 py-2 rounded text-decoration-none tpl-list-item <?= $editId==$t['id']?'active':'' ?>">
              <div class="d-flex justify-content-between align-items-center gap-2">
                <strong style="font-size:13px;"><?= esc($t['name']) ?></strong>
                <?= $t['active']?'<span class="s-badge active">ON</span>':'<span class="s-badge inactive">OFF</span>' ?>
              </div>
              <small class="text-muted" style="font-size:11px;"><code style="font-size:10.5px;"><?= esc($t['code']) ?></code> · v<?= (int)$t['current_version'] ?></small>
            </a>
            <a href="?tab=templates&edit=<?= (int)$t['id'] ?>" class="btn btn-soft-blue btn-sm d-inline-flex align-items-center px-2" data-testid="edit-template-<?= esc($t['code']) ?>" title="Edit template content &amp; images">
              <i class="bi bi-pencil-square"></i><span class="d-none d-xl-inline ms-1">Edit</span>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-8">
      <?php if ($editing): ?>
        <?php
        $tplHtml = trim($editing['html']);
        if ($tplHtml === '') {
          if ($editing['code'] === 'order_delivery')      $tplHtml = default_email_template();
          elseif ($editing['code'] === 'review_request')  $tplHtml = default_review_template();
          elseif ($editing['code'] === 'lead_followup')   $tplHtml = default_lead_followup_template();
          elseif ($editing['code'] === 'order_pending')   $tplHtml = default_order_pending_template();
          elseif ($editing['code'] === 'refund_confirm')  $tplHtml = default_refund_template();
        }
        // Variables you can insert into the content
        $tplVars = [
          'customer_name'   => "Customer's name",
          'customer_email'  => "Customer's email",
          'order_number'    => 'Order number',
          'amount'          => 'Order total',
          'product_name'    => 'Product name',
          'products_block'  => 'Products + license keys block',
          'installation_guide' => 'Installation guide steps',
          'review_url'      => 'Star-rating review link',
          'statement_name'  => 'Statement/merchant name',
          'company_name'    => 'Company name',
          'company_logo'    => 'Company logo image',
          'company_address' => 'Company address',
          'support_email'   => 'Support email',
          'support_phone'   => 'Support phone',
          'year'            => 'Current year',
        ];
        $co = company_info();
        ?>
        <div class="card-e p-3 mb-3">
          <form method="post" id="tplForm">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="tpl_id" value="<?= (int)$editing['id'] ?>">
            <input type="hidden" name="html" id="htmlEd" value="">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <strong><?= esc($editing['name']) ?></strong>
                <small class="text-muted ms-2">v<?= (int)$editing['current_version'] ?> · <code><?= esc($editing['code']) ?></code></small>
              </div>
              <div class="form-check form-switch mb-0">
                <input type="checkbox" class="form-check-input" name="active" id="actSw" <?= $editing['active']?'checked':'' ?>>
                <label class="form-check-label small" for="actSw">Active</label>
              </div>
            </div>
            <label class="form-label small fw-semibold">Subject</label>
            <input class="form-control mb-3" name="subject" value="<?= esc($editing['subject']) ?>" data-testid="tpl-subject">

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small fw-semibold d-flex align-items-center justify-content-between">
                  <span><i class="bi bi-card-text me-1 text-primary"></i> Email Content <span class="text-muted fw-normal">— what your customer will see</span></span>
                </label>

                <!-- Formatting toolbar -->
                <div class="tpl-toolbar d-flex flex-wrap gap-1 p-2 rounded-top" style="background:var(--bg);border:1px solid var(--border);border-bottom:0;" data-testid="tpl-toolbar">
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="bold" title="Bold (Ctrl+B)"><i class="bi bi-type-bold"></i></button>
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="italic" title="Italic (Ctrl+I)"><i class="bi bi-type-italic"></i></button>
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="underline" title="Underline"><i class="bi bi-type-underline"></i></button>
                  <span class="vr"></span>
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="insertUnorderedList" title="Bullet list"><i class="bi bi-list-ul"></i></button>
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="insertOrderedList" title="Numbered list"><i class="bi bi-list-ol"></i></button>
                  <span class="vr"></span>
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="formatBlock" data-val="h2" title="Heading"><i class="bi bi-type-h2"></i></button>
                  <button type="button" class="btn btn-sm btn-soft-gray tpl-tb-btn" data-cmd="formatBlock" data-val="p" title="Normal text"><i class="bi bi-paragraph"></i></button>
                  <span class="vr"></span>
                  <button type="button" class="btn btn-sm btn-soft-gray" id="tplLinkBtn" title="Add link"><i class="bi bi-link-45deg"></i></button>
                  <button type="button" class="btn btn-sm btn-soft-gray" id="tplAlignL" data-cmd="justifyLeft" title="Align left"><i class="bi bi-text-left"></i></button>
                  <button type="button" class="btn btn-sm btn-soft-gray" id="tplAlignC" data-cmd="justifyCenter" title="Center"><i class="bi bi-text-center"></i></button>
                  <span class="vr"></span>
                  <select class="form-select form-select-sm tpl-var-pick" id="tplVarPick" style="max-width:170px;" data-testid="tpl-var-pick" title="Insert dynamic value">
                    <option value="">Insert variable…</option>
                    <?php foreach ($tplVars as $k => $lbl): ?>
                      <option value="<?= esc($k) ?>"><?= esc($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <!-- Rich content editor -->
                <div id="tplContent"
                     contenteditable="true"
                     class="form-control tpl-content-editor"
                     style="min-height:430px;max-height:600px;overflow:auto;border-top-left-radius:0;border-top-right-radius:0;background:#fff;color:#0f172a;line-height:1.55;font-size:14px;"
                     data-testid="tpl-content"><?= $tplHtml ?></div>
                <small class="text-muted d-block mt-1">Type freely. Use the toolbar to format. Use <strong>Insert variable</strong> for dynamic values like the customer's name or the license key block.</small>
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold"><i class="bi bi-eye me-1 text-primary"></i> Live Preview</label>
                <iframe id="prev" style="width:100%;height:466px;border:1px solid var(--border);border-radius:10px;background:#fff;"></iframe>
                <small class="text-muted d-block mt-1">This is exactly what the customer will receive.</small>
              </div>
            </div>

            <!-- Image upload + insert ----------------------------------------- -->
            <div class="tpl-img-uploader mt-3 p-3 rounded" style="background:var(--bg);border:1px dashed var(--border);" data-testid="tpl-image-uploader">
              <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                <div>
                  <h6 class="fw-bold mb-0" style="font-size:13px;"><i class="bi bi-image me-1 text-primary"></i> Add or replace an image</h6>
                  <small class="text-muted">Upload a banner / logo / product image and insert it into the email HTML at the cursor position.</small>
                </div>
                <span class="badge bg-light text-muted" style="font-size:10.5px;">JPG · PNG · GIF · WEBP · SVG · max 5 MB</span>
              </div>
              <div class="row g-2 align-items-end">
                <div class="col-sm-7">
                  <input type="file" class="form-control form-control-sm" id="tplImgFile" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" data-testid="tpl-image-file">
                </div>
                <div class="col-sm-5 d-flex gap-2">
                  <button type="button" class="btn btn-soft-blue btn-sm flex-grow-1" id="tplImgUploadBtn" data-testid="tpl-image-upload-btn"><i class="bi bi-cloud-upload me-1"></i> Upload</button>
                </div>
              </div>
              <div id="tplImgResult" class="mt-2 d-none">
                <div class="d-flex flex-wrap align-items-center gap-2 p-2 rounded" style="background:#fff;border:1px solid var(--border);">
                  <img id="tplImgThumb" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">
                  <input type="text" class="form-control form-control-sm flex-grow-1" id="tplImgUrl" readonly style="font-size:11.5px;" data-testid="tpl-image-url">
                  <button type="button" class="btn btn-soft-gray btn-sm" id="tplImgCopyBtn" data-testid="tpl-image-copy"><i class="bi bi-clipboard"></i></button>
                  <button type="button" class="btn btn-soft-blue btn-sm" id="tplImgInsertBtn" data-testid="tpl-image-insert"><i class="bi bi-arrow-left-square me-1"></i>Insert into HTML</button>
                </div>
              </div>
              <div id="tplImgError" class="small text-danger mt-2 d-none"></div>
            </div>

            <div class="d-flex gap-2 mt-3">
              <button class="btn btn-soft-blue btn-sm" data-testid="save-template-btn"><i class="bi bi-check2 me-1"></i> Save Changes</button>
            </div>
          </form>
        </div>

        <?php if ($editing['code'] === 'order_delivery'):
          $bnCard   = setting_get('gw_card_merchant_name', defined('SITE_LEGAL') ? SITE_LEGAL : 'Maventech Software');
          $bnPaypal = setting_get('gw_paypal_account_name', defined('SITE_LEGAL') ? SITE_LEGAL : 'Maventech Software LLC');
        ?>
        <div class="card-e p-3 mb-3" data-testid="billing-note-card" style="border-left:4px solid #10b981;">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h6 class="fw-bold mb-1"><i class="bi bi-receipt text-success me-1"></i>Billing Notes</h6>
              <small class="text-muted">The company name customers see on their bank / card statement — also shown in the order-confirmation email's billing footer.</small>
            </div>
            <button type="button" class="btn btn-soft-blue btn-sm" data-testid="customize-billing-btn" onclick="document.getElementById('bnEdit').classList.toggle('d-none');document.getElementById('bnView').classList.toggle('d-none');">
              <i class="bi bi-pencil-square me-1"></i> Customize
            </button>
          </div>

          <!-- Read-only view -->
          <div id="bnView" class="row g-2 mt-2">
            <div class="col-md-6">
              <div class="p-2 rounded" style="background:var(--bg);border:1px solid var(--border);">
                <small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;"><i class="bi bi-credit-card me-1"></i>Card / Stripe statement</small>
                <div class="fw-bold mt-1" data-testid="bn-card-current"><?= esc($bnCard) ?></div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-2 rounded" style="background:var(--bg);border:1px solid var(--border);">
                <small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;"><i class="bi bi-paypal me-1"></i>PayPal merchant</small>
                <div class="fw-bold mt-1" data-testid="bn-paypal-current"><?= esc($bnPaypal) ?></div>
              </div>
            </div>
          </div>

          <!-- Edit form (hidden by default) -->
          <form id="bnEdit" method="post" class="d-none mt-2">
            <input type="hidden" name="action" value="save_billing_note">
            <input type="hidden" name="return_tpl_id" value="<?= (int)$editing['id'] ?>">
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small fw-semibold"><i class="bi bi-credit-card me-1"></i>Card / Stripe statement name</label>
                <input class="form-control form-control-sm" name="merchant_name" id="bnCardInput" value="<?= esc($bnCard) ?>" maxlength="22" required data-testid="bn-card-input" oninput="bnUpdatePreview()">
                <small class="text-muted">Max 22 chars · shown on the customer's bank statement.</small>
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold"><i class="bi bi-paypal me-1"></i>PayPal merchant name</label>
                <input class="form-control form-control-sm" name="account_name" id="bnPaypalInput" value="<?= esc($bnPaypal) ?>" maxlength="60" required data-testid="bn-paypal-input" oninput="bnUpdatePreview()">
                <small class="text-muted">Shown when PayPal is used as the payment method.</small>
              </div>
            </div>
            <div class="d-flex gap-2 mt-3">
              <button class="btn btn-soft-blue btn-sm" data-testid="bn-save"><i class="bi bi-check2 me-1"></i> Save</button>
              <button type="button" class="btn btn-soft-gray btn-sm" data-testid="bn-cancel" onclick="document.getElementById('bnEdit').classList.add('d-none');document.getElementById('bnView').classList.remove('d-none');">Cancel</button>
              <small class="text-muted ms-auto align-self-center">Mirrors <a href="admin.php?tab=api">API Management</a> · billing notes update everywhere instantly.</small>
            </div>
          </form>

          <div class="mt-3 p-2 rounded" style="background:#f0fdf4;border:1px dashed #86efac;">
            <small><i class="bi bi-eye me-1 text-success"></i><strong>Preview in email:</strong> "Billing note: this charge appears as <strong style="color:#047857;" id="bnPreview" data-testid="bn-preview"><?= esc($bnCard) ?></strong> on your card statement."</small>
          </div>
        </div>
        <script>
        function bnUpdatePreview() {
          var c = document.getElementById('bnCardInput');
          var p = document.getElementById('bnPreview');
          var view = document.getElementById('bnView');
          if (!c || !p) return;
          var val = c.value.trim() || 'YOUR COMPANY';
          p.textContent = val;
          // Also update read-only tiles (in case user clicks Cancel later)
          var cardTile = view ? view.querySelector('[data-testid="bn-card-current"]') : null;
          if (cardTile) cardTile.textContent = val;
          var pp = document.getElementById('bnPaypalInput');
          var ppTile = view ? view.querySelector('[data-testid="bn-paypal-current"]') : null;
          if (pp && ppTile) ppTile.textContent = pp.value.trim() || 'YOUR COMPANY LLC';
        }
        </script>
        <?php endif; ?>

        <script>
        (function(){
          var contentEl = document.getElementById('tplContent');
          var hidden    = document.getElementById('htmlEd');
          var form      = document.getElementById('tplForm');
          var iframe    = document.getElementById('prev');
          if (!contentEl || !hidden || !iframe) return;

          // Demo substitutions for the live preview — pulls from the Dashboard
          // → Company Info card so the preview matches what customers will see.
          var demo = {
            company_name:'<?= esc($co['name']) ?>',
            company_logo: <?= $co['logo'] ? '\'<img src="' . esc($co['logo']) . '" alt="' . esc($co['name']) . '" style="max-height:48px;max-width:200px;display:inline-block;">\'' : "''" ?>,
            company_address:'<?= esc(str_replace(["\r\n","\n"], '<br>', $co['address'])) ?>',
            customer_name:'John Smith',
            customer_email:'john@example.com',
            order_number:'MVT-2026-0042',
            amount:'129.99',
            statement_name:'<?= esc(setting_get('gw_card_merchant_name', setting_get('statement_name_card','MAVENTECH SOFTWARE'))) ?>',
            support_email:'<?= esc($co['email']) ?>',
            support_phone:'<?= esc($co['phone']) ?>',
            year: new Date().getFullYear(),
            installation_guide:'1. Download installer.<br>2. Run setup.<br>3. Enter license key.<br>4. Activate.',
            product_name:'Microsoft Office 2024',
            review_url:'<?= esc(SITE_URL) ?>/review.php?t=DEMO_TOKEN',
            products_block:'<div style="border:1px solid #eef0f3;border-radius:12px;padding:14px;background:#fff;"><div style="font-weight:700;">Sample Product</div><div style="margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px;text-align:center;"><div style="font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;">License Key</div><div style="font-family:monospace;font-weight:bold;color:#1d4ed8;font-size:17px;">XXXXX-YYYYY-ZZZZZ-AAAAA</div></div></div>',
            tracking_pixel:''
          };

          // Friendly badges to show {{variables}} INSIDE the editor while typing
          function makeVarBadges(node){
            // Walk text nodes and wrap {{var}} occurrences in styled spans (display-only)
            var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
            var batch = [];
            while (walker.nextNode()) batch.push(walker.currentNode);
            batch.forEach(function(tn){
              if (!/\{\{[a-z_]+\}\}/i.test(tn.nodeValue)) return;
              var frag = document.createDocumentFragment();
              var re = /\{\{([a-z_]+)\}\}/gi, last = 0, m;
              while ((m = re.exec(tn.nodeValue)) !== null) {
                if (m.index > last) frag.appendChild(document.createTextNode(tn.nodeValue.slice(last, m.index)));
                var chip = document.createElement('span');
                chip.className = 'tpl-var-chip';
                chip.setAttribute('contenteditable','false');
                chip.setAttribute('data-var', m[1]);
                chip.textContent = m[1].replace(/_/g,' ');
                frag.appendChild(chip);
                last = m.index + m[0].length;
              }
              if (last < tn.nodeValue.length) frag.appendChild(document.createTextNode(tn.nodeValue.slice(last)));
              tn.parentNode.replaceChild(frag, tn);
            });
          }
          makeVarBadges(contentEl);

          // Read HTML out of the editor, converting var chips back to {{var}} text
          function exportHtml(){
            var clone = contentEl.cloneNode(true);
            clone.querySelectorAll('.tpl-var-chip').forEach(function(c){
              var v = c.getAttribute('data-var');
              c.replaceWith(document.createTextNode('{{'+v+'}}'));
            });
            return clone.innerHTML;
          }

          function renderPreview(){
            var html = exportHtml();
            Object.keys(demo).forEach(function(k){ html = html.split('{{'+k+'}}').join(demo[k]); });
            iframe.srcdoc = html;
            hidden.value = html;
          }
          renderPreview();
          contentEl.addEventListener('input', function(){
            clearTimeout(window._tt); window._tt = setTimeout(renderPreview, 350);
          });
          // Ensure hidden field is up-to-date right before submit
          if (form) form.addEventListener('submit', function(){ hidden.value = exportHtml(); });

          // Toolbar formatting buttons
          document.querySelectorAll('.tpl-tb-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
              contentEl.focus();
              document.execCommand(btn.getAttribute('data-cmd'), false, btn.getAttribute('data-val') || null);
              renderPreview();
            });
          });
          ['tplAlignL','tplAlignC'].forEach(function(id){
            var b = document.getElementById(id);
            if (b) b.addEventListener('click', function(){
              contentEl.focus();
              document.execCommand(b.getAttribute('data-cmd'));
              renderPreview();
            });
          });
          var linkBtn = document.getElementById('tplLinkBtn');
          if (linkBtn) linkBtn.addEventListener('click', function(){
            var url = prompt('Enter the link URL (https://…)');
            if (!url) return;
            contentEl.focus();
            document.execCommand('createLink', false, url);
            renderPreview();
          });

          // Insert variable
          var varPick = document.getElementById('tplVarPick');
          if (varPick) varPick.addEventListener('change', function(){
            if (!varPick.value) return;
            contentEl.focus();
            var chip = document.createElement('span');
            chip.className = 'tpl-var-chip';
            chip.setAttribute('contenteditable','false');
            chip.setAttribute('data-var', varPick.value);
            chip.textContent = varPick.value.replace(/_/g,' ');
            // insert at caret
            var sel = window.getSelection();
            if (sel && sel.rangeCount && contentEl.contains(sel.anchorNode)) {
              var range = sel.getRangeAt(0);
              range.deleteContents();
              range.insertNode(chip);
              range.setStartAfter(chip); range.setEndAfter(chip);
              sel.removeAllRanges(); sel.addRange(range);
            } else {
              contentEl.appendChild(chip);
            }
            varPick.value = '';
            renderPreview();
          });

          // Image uploader (AJAX) — now inserts <img> into the contenteditable
          var fileEl   = document.getElementById('tplImgFile');
          var upBtn    = document.getElementById('tplImgUploadBtn');
          var resBox   = document.getElementById('tplImgResult');
          var errBox   = document.getElementById('tplImgError');
          var thumb    = document.getElementById('tplImgThumb');
          var urlInput = document.getElementById('tplImgUrl');
          var copyBtn  = document.getElementById('tplImgCopyBtn');
          var insBtn   = document.getElementById('tplImgInsertBtn');

          function showErr(m){ if (!errBox) return; errBox.textContent = m || ''; errBox.classList.toggle('d-none', !m); }

          if (upBtn) upBtn.addEventListener('click', function(){
            showErr('');
            if (!fileEl.files || !fileEl.files[0]) { showErr('Please choose an image first.'); return; }
            var fd = new FormData();
            fd.append('image', fileEl.files[0]);
            upBtn.disabled = true;
            var orig = upBtn.innerHTML;
            upBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Uploading…';
            fetch('ajax/template-image.php', { method:'POST', body: fd })
              .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'Server error'}; }); })
              .then(function(j){
                upBtn.disabled = false; upBtn.innerHTML = orig;
                if (!j || !j.ok) { showErr((j && j.error) || 'Upload failed.'); return; }
                thumb.src = j.url; urlInput.value = j.url; resBox.classList.remove('d-none');
              }).catch(function(){
                upBtn.disabled = false; upBtn.innerHTML = orig;
                showErr('Network error — please try again.');
              });
          });

          if (copyBtn) copyBtn.addEventListener('click', function(){
            urlInput.select();
            try {
              navigator.clipboard.writeText(urlInput.value);
              copyBtn.innerHTML = '<i class="bi bi-check2"></i>';
              setTimeout(function(){ copyBtn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1200);
            } catch(e) { document.execCommand('copy'); }
          });

          if (insBtn) insBtn.addEventListener('click', function(){
            if (!urlInput.value) return;
            contentEl.focus();
            var img = document.createElement('img');
            img.src = urlInput.value;
            img.alt = '';
            img.style.maxWidth = '100%'; img.style.height = 'auto'; img.style.display = 'block';
            var sel = window.getSelection();
            if (sel && sel.rangeCount && contentEl.contains(sel.anchorNode)) {
              var range = sel.getRangeAt(0);
              range.deleteContents();
              range.insertNode(img);
              range.setStartAfter(img); range.setEndAfter(img);
              sel.removeAllRanges(); sel.addRange(range);
            } else {
              contentEl.appendChild(img);
            }
            renderPreview();
          });
        })();
        </script>
      <?php else: ?>
        <div class="card-e p-5 text-center text-muted">Select a template on the left to edit.</div>
      <?php endif; ?>
    </div>
  </div>

<?php
// ============================================================================
// SMTP / MAIL SERVER
// ============================================================================
elseif ($tab === 'smtp'):
  require_once __DIR__ . '/includes/mailer.php';
  $smtp = smtp_config();
  // Auto-mint cron + API tokens once, so the admin can copy them from this page.
  $cronToken = setting_get('cron_token', '');
  if ($cronToken === '') { $cronToken = bin2hex(random_bytes(20)); setting_set('cron_token', $cronToken); }
  $apiToken  = setting_get('api_token', '');
  if ($apiToken === '')  { $apiToken  = bin2hex(random_bytes(24)); setting_set('api_token', $apiToken); }
  // Queue stats
  $st = db()->query("SELECT
        COUNT(*) total,
        SUM(status='sent') sent,
        SUM(status='queued') queued,
        SUM(status='retrying') retrying,
        SUM(status='failed') failed,
        SUM(status='bounced') bounced
      FROM email_outbox")->fetch();
  $siteHost = parse_url(rtrim(SITE_URL,'/'), PHP_URL_HOST) ?: 'your-domain.com';
?>
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h4 fw-bold mb-1"><i class="bi bi-envelope-paper-heart me-1 text-primary"></i> SMTP / Mail Server</h1>
      <small class="text-muted">Configure your outgoing-mail server. Once enabled, every transactional email (orders, refunds, leads, reviews, OTPs) flows through your SMTP with full retry, queueing and bounce tracking.</small>
    </div>
    <?php if (!empty($_GET['msg'])): ?>
      <span class="badge bg-success-subtle text-success" data-testid="smtp-saved-toast"><i class="bi bi-check2-circle me-1"></i><?= esc($_GET['msg']) ?></span>
    <?php endif; ?>
  </div>

  <!-- Status / queue summary tiles -->
  <div class="row g-2 mb-3">
    <div class="col-md-2 col-6"><div class="card-e p-3"><div class="small text-muted">Status</div><div class="fw-bold mt-1" data-testid="smtp-status-pill"><?= $smtp['enabled'] ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Live</span>' : '<span class="text-warning"><i class="bi bi-pause-circle-fill"></i> Disabled</span>' ?></div></div></div>
    <div class="col-md-2 col-6"><div class="card-e p-3"><div class="small text-muted">Sent (all-time)</div><div class="h5 fw-bold mb-0 text-success" data-testid="smtp-stat-sent"><?= (int)($st['sent'] ?? 0) ?></div></div></div>
    <div class="col-md-2 col-6"><div class="card-e p-3"><div class="small text-muted">Queued</div><div class="h5 fw-bold mb-0 text-primary" data-testid="smtp-stat-queued"><?= (int)($st['queued'] ?? 0) ?></div></div></div>
    <div class="col-md-2 col-6"><div class="card-e p-3"><div class="small text-muted">Retrying</div><div class="h5 fw-bold mb-0 text-warning" data-testid="smtp-stat-retrying"><?= (int)($st['retrying'] ?? 0) ?></div></div></div>
    <div class="col-md-2 col-6"><div class="card-e p-3"><div class="small text-muted">Failed</div><div class="h5 fw-bold mb-0 text-danger" data-testid="smtp-stat-failed"><?= (int)($st['failed'] ?? 0) ?></div></div></div>
    <div class="col-md-2 col-6"><div class="card-e p-3"><div class="small text-muted">Bounced</div><div class="h5 fw-bold mb-0 text-secondary" data-testid="smtp-stat-bounced"><?= (int)($st['bounced'] ?? 0) ?></div></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card-e p-4" data-testid="smtp-config-card">
        <form method="post" id="smtpForm">
          <input type="hidden" name="action" value="save_smtp">

          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
              <h6 class="fw-bold mb-0"><i class="bi bi-server me-1 text-primary"></i> Server Configuration</h6>
            </div>
            <div class="form-check form-switch mb-0">
              <input type="checkbox" class="form-check-input" name="enabled" id="smtpEnabled" <?= $smtp['enabled']?'checked':'' ?> data-testid="smtp-enabled">
              <label class="form-check-label small fw-semibold" for="smtpEnabled">Enable SMTP</label>
            </div>
          </div>

          <!-- Provider presets -->
          <label class="form-label small fw-semibold mb-1">Quick preset</label>
          <div class="d-flex flex-wrap gap-2 mb-3" data-testid="smtp-presets">
            <button type="button" class="btn btn-soft-gray btn-sm" data-preset="cpanel">cPanel / Plesk</button>
            <button type="button" class="btn btn-soft-gray btn-sm" data-preset="gmail">Gmail</button>
            <button type="button" class="btn btn-soft-gray btn-sm" data-preset="o365">Office 365</button>
            <button type="button" class="btn btn-soft-gray btn-sm" data-preset="sendgrid">SendGrid</button>
            <button type="button" class="btn btn-soft-gray btn-sm" data-preset="ses">Amazon SES</button>
            <button type="button" class="btn btn-soft-gray btn-sm" data-preset="custom">Custom</button>
          </div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label small fw-semibold">SMTP Host</label>
              <input class="form-control" name="host" id="smtpHost" value="<?= esc($smtp['host']) ?>" placeholder="mail.<?= esc($siteHost) ?>" required data-testid="smtp-host">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Port</label>
              <input class="form-control" name="port" id="smtpPort" type="number" value="<?= esc($smtp['port']) ?>" data-testid="smtp-port">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Username</label>
              <input class="form-control" name="username" id="smtpUser" value="<?= esc($smtp['username']) ?>" placeholder="noreply@<?= esc($siteHost) ?>" autocomplete="off" data-testid="smtp-username">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Password <span class="text-muted fw-normal">(leave blank to keep current)</span></label>
              <input class="form-control" name="password" type="password" placeholder="<?= $smtp['password'] !== '' ? '•••••••• (saved)' : 'Enter password' ?>" autocomplete="new-password" data-testid="smtp-password">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Encryption</label>
              <select class="form-select" name="encryption" id="smtpEnc" data-testid="smtp-encryption">
                <option value="tls"  <?= $smtp['encryption']==='tls' ?'selected':'' ?>>TLS (STARTTLS · 587)</option>
                <option value="ssl"  <?= $smtp['encryption']==='ssl' ?'selected':'' ?>>SSL (Implicit · 465)</option>
                <option value="none" <?= $smtp['encryption']==='none'?'selected':'' ?>>None (plain · 25)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Max retries</label>
              <input class="form-control" name="max_retries" type="number" min="0" max="10" value="<?= esc($smtp['max_retries']) ?>" data-testid="smtp-max-retries">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Rate / minute</label>
              <input class="form-control" name="rate_per_min" type="number" min="1" max="2000" value="<?= esc($smtp['rate_per_min']) ?>" data-testid="smtp-rate">
            </div>
          </div>

          <hr class="my-3">
          <h6 class="fw-bold mb-2"><i class="bi bi-person-badge me-1 text-primary"></i> Sender Identity</h6>
          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label small fw-semibold">From Email</label>
              <input class="form-control" name="from_email" type="email" value="<?= esc($smtp['from_email']) ?>" placeholder="noreply@<?= esc($siteHost) ?>" required data-testid="smtp-from-email">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">From Name</label>
              <input class="form-control" name="from_name" value="<?= esc($smtp['from_name']) ?>" placeholder="<?= esc(setting_get('company_name','Your Brand')) ?>" data-testid="smtp-from-name">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Reply-To</label>
              <input class="form-control" name="reply_to" type="email" value="<?= esc($smtp['reply_to']) ?>" placeholder="support@<?= esc($siteHost) ?>" data-testid="smtp-reply-to">
            </div>
          </div>

          <div class="form-check mt-3">
            <input type="checkbox" class="form-check-input" name="verify_peer" id="smtpVerify" <?= $smtp['verify_peer']?'checked':'' ?> data-testid="smtp-verify-peer">
            <label class="form-check-label small" for="smtpVerify">Strict TLS peer verification <span class="text-muted">(uncheck only if your self-signed cert fails)</span></label>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <button class="btn btn-soft-blue btn-sm" data-testid="smtp-save-btn"><i class="bi bi-check2 me-1"></i> Save Configuration</button>
            <button type="button" class="btn btn-soft-gray btn-sm" id="smtpTestBtn" data-testid="smtp-test-btn"><i class="bi bi-send-check me-1"></i> Send Test Email</button>
            <button type="button" class="btn btn-soft-gray btn-sm" id="smtpProcessBtn" data-testid="smtp-process-btn"><i class="bi bi-play-circle me-1"></i> Process Queue Now</button>
          </div>

          <div id="smtpResult" class="mt-3 d-none small"></div>
        </form>
      </div>
    </div>

    <div class="col-lg-5">
      <!-- DNS deliverability card -->
      <div class="card-e p-3 mb-3" data-testid="smtp-dns-card">
        <h6 class="fw-bold mb-2"><i class="bi bi-shield-check me-1 text-success"></i> DNS records for inbox placement</h6>
        <p class="small text-muted mb-3">Add these records at your DNS host to pass SPF / DKIM / DMARC and stop landing in spam folders.</p>
        <div class="dns-row mb-2"><span class="dns-type">SPF</span><code>v=spf1 include:<?= esc($siteHost) ?> ~all</code></div>
        <div class="dns-row mb-2"><span class="dns-type">DMARC</span><code>v=DMARC1; p=quarantine; rua=mailto:dmarc@<?= esc($siteHost) ?>; pct=100</code></div>
        <div class="dns-row"><span class="dns-type">DKIM</span><code>Generated by your SMTP provider — copy the selector from the provider dashboard.</code></div>
        <p class="small text-muted mt-2 mb-0">After adding records, use <a href="https://www.mail-tester.com" target="_blank" rel="noopener">mail-tester.com</a> to verify a perfect 10/10 score.</p>
      </div>

      <!-- Cron / API tokens card -->
      <div class="card-e p-3 mb-3" data-testid="smtp-cron-card">
        <h6 class="fw-bold mb-2"><i class="bi bi-clock me-1 text-primary"></i> Background queue worker</h6>
        <p class="small text-muted mb-2">For bulk sending, add this cron job in cPanel / Plesk so the queue processes every minute:</p>
        <div class="copy-row mb-2">
          <code data-testid="smtp-cron-url"><?= esc(rtrim(SITE_URL,'/')) ?>/cron.php?token=<?= esc($cronToken) ?></code>
          <button type="button" class="btn btn-sm btn-soft-gray" onclick="copyToClipboard(this, '<?= esc(rtrim(SITE_URL,'/')) ?>/cron.php?token=<?= esc($cronToken) ?>')"><i class="bi bi-clipboard"></i></button>
        </div>
        <p class="small text-muted mb-0">Without cron, the queue still drains incrementally on each admin page load.</p>
      </div>

      <!-- REST API card -->
      <div class="card-e p-3" data-testid="smtp-api-card">
        <h6 class="fw-bold mb-2"><i class="bi bi-plug me-1 text-primary"></i> REST API token</h6>
        <p class="small text-muted mb-2">Use this Bearer token to send emails programmatically:</p>
        <div class="copy-row mb-2">
          <code data-testid="smtp-api-token" style="font-size:11px;"><?= esc($apiToken) ?></code>
          <button type="button" class="btn btn-sm btn-soft-gray" onclick="copyToClipboard(this, '<?= esc($apiToken) ?>')"><i class="bi bi-clipboard"></i></button>
        </div>
        <details class="small">
          <summary class="text-muted" style="cursor:pointer;">Endpoints</summary>
          <ul class="mt-2 mb-0" style="font-size:12px;line-height:1.7;">
            <li><code>POST /email-api.php?action=send</code> &mdash; render+send immediately</li>
            <li><code>POST /email-api.php?action=queue</code> &mdash; queue only</li>
            <li><code>GET  /email-api.php?action=status&amp;id=N</code></li>
            <li><code>GET  /email-api.php?action=stats</code></li>
            <li><code>POST /email-api.php?action=resend&amp;id=N</code></li>
            <li><code>POST /email-api.php?action=process</code></li>
          </ul>
        </details>
      </div>
    </div>
  </div>

  <style>
    .dns-row { font-size:12px; }
    .dns-row .dns-type { display:inline-block; min-width:56px; font-weight:700; color:#0f172a; background:#dbeafe; padding:2px 8px; border-radius:6px; margin-right:6px; }
    .dns-row code { background:var(--bg); padding:4px 8px; border-radius:6px; border:1px solid var(--border); word-break:break-all; display:inline-block; }
    .copy-row { display:flex; align-items:center; gap:6px; }
    .copy-row code { flex:1; background:var(--bg); padding:6px 10px; border-radius:6px; border:1px solid var(--border); font-size:11.5px; word-break:break-all; }
    #smtpResult.ok  { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:12px; border-radius:8px; }
    #smtpResult.err { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:8px; }
  </style>

  <script>
  function copyToClipboard(btn, text){
    try { navigator.clipboard.writeText(text); btn.innerHTML = '<i class="bi bi-check2"></i>'; setTimeout(()=>btn.innerHTML='<i class="bi bi-clipboard"></i>', 1200); }
    catch(e){ document.execCommand('copy'); }
  }
  (function(){
    var presets = {
      cpanel:   { host: 'mail.' + '<?= esc($siteHost) ?>', port: 465, enc: 'ssl' },
      gmail:    { host: 'smtp.gmail.com',     port: 587, enc: 'tls' },
      o365:     { host: 'smtp.office365.com', port: 587, enc: 'tls' },
      sendgrid: { host: 'smtp.sendgrid.net',  port: 587, enc: 'tls', user: 'apikey' },
      ses:      { host: 'email-smtp.us-east-1.amazonaws.com', port: 587, enc: 'tls' },
      custom:   { host: '', port: 587, enc: 'tls' }
    };
    document.querySelectorAll('[data-preset]').forEach(function(b){
      b.addEventListener('click', function(){
        var p = presets[b.getAttribute('data-preset')];
        if (!p) return;
        document.getElementById('smtpHost').value = p.host;
        document.getElementById('smtpPort').value = p.port;
        document.getElementById('smtpEnc').value  = p.enc;
        if (p.user) document.getElementById('smtpUser').value = p.user;
      });
    });

    var result = document.getElementById('smtpResult');
    function showResult(ok, msg){
      result.classList.remove('d-none', 'ok', 'err');
      result.classList.add(ok ? 'ok' : 'err');
      result.innerHTML = (ok ? '<i class="bi bi-check2-circle me-1"></i>' : '<i class="bi bi-exclamation-triangle me-1"></i>') + msg;
    }

    document.getElementById('smtpTestBtn').addEventListener('click', function(){
      var to = prompt('Send a test email to:', document.querySelector('[name=reply_to]').value || document.querySelector('[name=from_email]').value);
      if (!to) return;
      var b = this, orig = b.innerHTML; b.disabled=true; b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
      var fd = new FormData(); fd.append('to', to);
      fetch('ajax/smtp-test.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(j => { b.disabled=false; b.innerHTML=orig; showResult(j.ok, j.message || (j.ok?'Sent':'Failed')); })
        .catch(() => { b.disabled=false; b.innerHTML=orig; showResult(false, 'Network error'); });
    });

    document.getElementById('smtpProcessBtn').addEventListener('click', function(){
      var b = this, orig = b.innerHTML; b.disabled=true; b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Processing…';
      var fd = new FormData(); fd.append('batch', '25');
      fetch('ajax/smtp-process.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(j => { b.disabled=false; b.innerHTML=orig; showResult(j.ok, 'Processed <strong>' + (j.processed||0) + '</strong> email(s) — refresh to see updated counts.'); })
        .catch(() => { b.disabled=false; b.innerHTML=orig; showResult(false, 'Network error'); });
    });
  })();
  </script>

<?php
// ============================================================================
// API MANAGEMENT (Card + PayPal)
// ============================================================================
elseif ($tab === 'api'):
  function mask($v) { if (!$v) return ''; $l = strlen($v); if ($l <= 8) return str_repeat('*', $l); return substr($v,0,4).str_repeat('*', $l-8).substr($v,-4); }
  $cardStatus = setting_get('gw_card_status','inactive');
  $cardProv   = setting_get('gw_card_provider','Stripe');
  $cardMerch  = setting_get('gw_card_merchant_name','Maventech Software');
  $cardPub    = setting_get('gw_card_public_key','');
  $cardSec    = setting_get('gw_card_secret_key','');
  $cardWh     = setting_get('gw_card_webhook_secret','');
  $cardWhUrl  = setting_get('gw_card_webhook_url','/stripe-webhook.php');

  $ppStatus   = setting_get('gw_paypal_status','inactive');
  $ppAcc      = setting_get('gw_paypal_account_name','Maventech Software LLC');
  $ppCid      = setting_get('gw_paypal_client_id','');
  $ppSec      = setting_get('gw_paypal_secret','');
  $ppWh       = setting_get('gw_paypal_webhook_id','');
  $ppWhUrl    = setting_get('gw_paypal_webhook_url','/paypal-webhook.php');

  $txCard = (int)$pdo->query("SELECT COUNT(*) FROM transaction_logs WHERE gateway='card'")->fetchColumn();
  $txPp   = (int)$pdo->query("SELECT COUNT(*) FROM transaction_logs WHERE gateway='paypal'")->fetchColumn();
?>
  <h5 class="fw-bold mb-1">API Management</h5>
  <p class="text-muted small mb-3">Configure payment gateway credentials and view live status. Changes apply instantly — credentials live in the <code>settings</code> table.</p>

  <!-- Card / PayPal tab switcher -->
  <?php $apiTab = $_GET['gw'] ?? 'card'; ?>
  <ul class="nav nav-pills mb-3" role="tablist" data-testid="api-tab-switcher">
    <li class="nav-item"><a class="nav-link <?= $apiTab==='card'?'active':'' ?>" href="?tab=api&gw=card" data-testid="api-tab-card"><i class="bi bi-credit-card-2-front me-1"></i> Card Payment API <span class="badge bg-light text-dark ms-1"><?= $txCard ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $apiTab==='paypal'?'active':'' ?>" href="?tab=api&gw=paypal" data-testid="api-tab-paypal"><i class="bi bi-paypal me-1"></i> PayPal API <span class="badge bg-light text-dark ms-1"><?= $txPp ?></span></a></li>
  </ul>

  <div class="row g-3">
    <div class="col-lg-12" <?= $apiTab!=='card' ? 'style="display:none;"' : '' ?>>
      <div class="card-e p-4" data-testid="api-card-gateway">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-credit-card-2-front text-primary me-1"></i> Card Payment API</h6>
            <small class="text-muted">Gateway: <?= esc($cardProv) ?></small>
          </div>
          <span class="s-badge <?= $cardStatus==='active'?'paid':'failed' ?>"><?= esc($cardStatus) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="save_api">
          <input type="hidden" name="gateway" value="card">
          <div class="row g-2 small mb-3">
            <div class="col-6"><label class="form-label small mb-0">API Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="active" <?= $cardStatus==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $cardStatus!=='active'?'selected':'' ?>>Inactive</option>
              </select>
            </div>
            <div class="col-6"><label class="form-label small mb-0">Gateway Provider</label><input class="form-control form-control-sm" name="provider" value="<?= esc($cardProv) ?>"></div>
            <div class="col-12">
              <label class="form-label small mb-0">Merchant / Company Name <span class="badge bg-success ms-1" style="font-size:9px;">used in Billing notes</span></label>
              <input class="form-control form-control-sm" name="merchant_name" value="<?= esc($cardMerch) ?>" data-testid="api-card-merchant">
              <small class="text-muted">Shown on bank/card statements <em>and</em> in the order-confirmation email billing note.</small>
            </div>
            <div class="col-12"><label class="form-label small mb-0">Publishable Key <small class="text-muted"><?= esc(mask($cardPub)) ?></small></label><input class="form-control form-control-sm" name="public_key" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Secret Key <small class="text-muted"><?= esc(mask($cardSec)) ?></small></label><input class="form-control form-control-sm" name="secret_key" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook Secret <small class="text-muted"><?= esc(mask($cardWh)) ?></small></label><input class="form-control form-control-sm" name="webhook_secret" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook URL</label><input class="form-control form-control-sm" readonly value="<?= esc(site_url().$cardWhUrl) ?>"></div>
          </div>
          <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Save Card API Settings</button>
        </form>
        <div class="mt-3 pt-3 border-top d-flex justify-content-between small">
          <span class="text-muted">Webhook Status</span>
          <span class="s-badge <?= $cardWh ? 'paid' : 'queued' ?>"><?= $cardWh ? 'configured' : 'not configured' ?></span>
        </div>
        <div class="d-flex justify-content-between small">
          <span class="text-muted">Transaction Logs</span>
          <strong><?= $txCard ?></strong>
        </div>
      </div>
    </div>

    <div class="col-lg-12" <?= $apiTab!=='paypal' ? 'style="display:none;"' : '' ?>>
      <div class="card-e p-4" data-testid="api-paypal-gateway">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-paypal me-1" style="color:#003087;"></i> PayPal API</h6>
            <small class="text-muted">Business: <?= esc($ppAcc) ?></small>
          </div>
          <span class="s-badge <?= $ppStatus==='active'?'paid':'failed' ?>"><?= esc($ppStatus) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="save_api">
          <input type="hidden" name="gateway" value="paypal">
          <div class="row g-2 small mb-3">
            <div class="col-12"><label class="form-label small mb-0">API Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="active" <?= $ppStatus==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $ppStatus!=='active'?'selected':'' ?>>Inactive</option>
              </select>
              <small class="text-muted">Toggling Active also reveals PayPal on the public checkout.</small>
            </div>
            <div class="col-12">
              <label class="form-label small mb-0">PayPal Business Account Name <span class="badge bg-success ms-1" style="font-size:9px;">used in Billing notes</span></label>
              <input class="form-control form-control-sm" name="account_name" value="<?= esc($ppAcc) ?>" data-testid="api-paypal-account">
              <small class="text-muted">Shown in the order email billing note when PayPal is used as payment method.</small>
            </div>
            <div class="col-12"><label class="form-label small mb-0">Client ID <small class="text-muted"><?= esc(mask($ppCid)) ?></small></label><input class="form-control form-control-sm" name="client_id" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Client Secret <small class="text-muted"><?= esc(mask($ppSec)) ?></small></label><input class="form-control form-control-sm" name="secret" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook ID <small class="text-muted"><?= esc(mask($ppWh)) ?></small></label><input class="form-control form-control-sm" name="webhook_id" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook URL</label><input class="form-control form-control-sm" readonly value="<?= esc(site_url().$ppWhUrl) ?>"></div>
          </div>
          <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Save PayPal API Settings</button>
        </form>
        <div class="mt-3 pt-3 border-top d-flex justify-content-between small">
          <span class="text-muted">Webhook Status</span>
          <span class="s-badge <?= $ppWh ? 'paid' : 'queued' ?>"><?= $ppWh ? 'configured' : 'not configured' ?></span>
        </div>
        <div class="d-flex justify-content-between small">
          <span class="text-muted">Transaction Logs</span>
          <strong><?= $txPp ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card-e p-3 mt-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-list-ul me-1"></i> Recent Transaction Logs</h6>
    <div class="tbl-e">
      <table class="table table-sm mb-0" data-testid="tx-logs-table">
        <thead><tr><th>Gateway</th><th>Payment Mode</th><th>Transaction</th><th>Order</th><th>Amount</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
          <?php
          $logs = $pdo->query('SELECT tl.*, o.order_number FROM transaction_logs tl LEFT JOIN orders o ON o.id=tl.order_id ORDER BY tl.created_at DESC LIMIT 50');
          $any=false; foreach ($logs as $tl):
            $any=true;
            $gw = strtolower((string)$tl['gateway']);
            // Resolve human-friendly gateway name from API Management settings, then fall back.
            if ($gw === 'paypal' || $gw === 'pp') {
              $gwName = setting_get('gw_paypal_provider','PayPal') ?: 'PayPal';
              $mode = 'paypal';
            } else { // card / stripe / etc.
              $gwName = setting_get('gw_card_provider','Stripe') ?: 'Stripe';
              $mode = 'card';
            }
          ?>
            <tr>
              <td><span class="s-badge sent" data-testid="tx-gateway-<?= (int)$tl['id'] ?>"><i class="bi bi-<?= $mode==='paypal'?'paypal':'credit-card-2-front' ?> me-1"></i><?= esc($gwName) ?></span></td>
              <td><span class="text-capitalize fw-semibold" data-testid="tx-mode-<?= (int)$tl['id'] ?>"><?= esc($mode) ?></span></td>
              <td><code style="font-size:11px;"><?= esc($tl['transaction_id']) ?></code></td>
              <td><?= $tl['order_number'] ? '<a href="order-view.php?id='.(int)$tl['order_id'].'"><code>#'.esc($tl['order_number']).'</code></a>' : '—' ?></td>
              <td><?= esc($tl['currency'].' '.number_format((float)$tl['amount'],2)) ?></td>
              <td><span class="s-badge <?= esc($tl['status']) ?>"><?= esc($tl['status']) ?></span></td>
              <td><small class="text-muted"><?= esc(date('M j, Y H:i', strtotime($tl['created_at']))) ?></small></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$any): ?><tr><td colspan="7" class="text-center text-muted py-3">No transactions logged yet — they'll appear here automatically as orders are processed.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php
// ============================================================================
// REGIONS
// ============================================================================
elseif ($tab === 'regions'):
  $regions = $pdo->query('SELECT * FROM regions ORDER BY code')->fetchAll();
?>
  <h5 class="fw-bold mb-1">Regions</h5>
  <p class="text-muted small mb-3">Each region maintains separate inventory, license keys, pricing and reports. Toggle a region <strong>off</strong> to instantly hide its products from the public website.</p>
  <div class="row g-3">
    <?php foreach ($regions as $r):
      $prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE region=".$pdo->quote($r['code']))->fetchColumn();
      $keysAv    = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE region=".$pdo->quote($r['code'])." AND status='available'")->fetchColumn();
      $rev       = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE region=".$pdo->quote($r['code'])." AND status IN ('paid','delivered')")->fetchColumn();
    ?>
      <div class="col-md-6">
        <div class="card-e p-4 region-card" data-region-card="<?= esc($r['code']) ?>" data-testid="region-card-<?= esc($r['code']) ?>">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="d-flex align-items-center gap-3">
              <?php
                $flagMap = ['US'=>'us','UK'=>'gb','GB'=>'gb','CA'=>'ca','EU'=>'eu','AU'=>'au','IN'=>'in','DE'=>'de','FR'=>'fr','ES'=>'es','IT'=>'it','JP'=>'jp','MX'=>'mx','BR'=>'br'];
                $fcode = $flagMap[strtoupper($r['code'])] ?? strtolower($r['code']);
              ?>
              <img src="https://flagcdn.com/w80/<?= esc($fcode) ?>.png"
                   srcset="https://flagcdn.com/w160/<?= esc($fcode) ?>.png 2x"
                   alt="<?= esc($r['name']) ?> flag"
                   class="region-flag"
                   data-testid="region-flag-<?= esc($r['code']) ?>"
                   onerror="this.outerHTML='<span class=\'region-flag-fb\'><i class=\'bi bi-flag-fill\'></i></span>';">
              <div>
                <h6 class="fw-bold mb-0"><?= esc($r['code']) ?> · <?= esc($r['name']) ?></h6>
                <small class="text-muted"><?= esc($r['currency_symbol']) ?> <?= esc($r['currency']) ?> · Tax <?= number_format($r['tax_rate']*100,1) ?>%</small>
              </div>
            </div>
            <span class="rg-status-pill <?= $r['active']?'on':'off' ?>" data-rg-pill data-testid="region-pill-<?= esc($r['code']) ?>"><?= $r['active']?'<i class="bi bi-broadcast me-1"></i>Live':'<i class="bi bi-pause-circle me-1"></i>Paused' ?></span>
          </div>

          <form method="post" class="rg-settings-form" data-testid="region-settings-<?= esc($r['code']) ?>">
            <input type="hidden" name="action" value="save_region">
            <input type="hidden" name="region_code" value="<?= esc($r['code']) ?>">
            <input type="hidden" name="active" value="<?= $r['active'] ?>" data-rg-hidden-active>
            <div class="row g-2 small mb-3">
              <div class="col-12"><label class="form-label small mb-0">Region Name</label><input class="form-control form-control-sm" name="name" value="<?= esc($r['name']) ?>"></div>
              <div class="col-5"><label class="form-label small mb-0">Currency</label><input class="form-control form-control-sm" name="currency" value="<?= esc($r['currency']) ?>"></div>
              <div class="col-3"><label class="form-label small mb-0">Symbol</label><input class="form-control form-control-sm" name="currency_symbol" value="<?= esc($r['currency_symbol']) ?>"></div>
              <div class="col-4"><label class="form-label small mb-0">Tax Rate</label><input class="form-control form-control-sm" name="tax_rate" type="number" step="0.0001" value="<?= esc($r['tax_rate']) ?>"></div>
            </div>
            <div class="row g-2 small mb-3">
              <div class="col-4"><div class="p-2 rounded" style="background:var(--bg);"><div class="fw-bold"><?= $prodCount ?></div><small class="text-muted">Products</small></div></div>
              <div class="col-4"><div class="p-2 rounded" style="background:var(--bg);"><div class="fw-bold text-success"><?= $keysAv ?></div><small class="text-muted">Keys Avail</small></div></div>
              <div class="col-4"><div class="p-2 rounded" style="background:var(--bg);"><div class="fw-bold"><?= esc($r['currency_symbol']) ?><?= number_format($rev,0) ?></div><small class="text-muted">Revenue</small></div></div>
            </div>
            <button class="btn btn-soft-gray btn-sm w-100" data-testid="save-region-settings-<?= esc($r['code']) ?>"><i class="bi bi-sliders me-1"></i> Save Settings</button>
          </form>

          <!-- Active / Deactive toggle bar (instant AJAX) -->
          <div class="rg-toggle-bar mt-3" data-rg-bar data-rg-state="<?= $r['active']?'on':'off' ?>" role="group" aria-label="Region status">
            <button type="button" class="rg-toggle-opt rg-on <?= $r['active']?'sel':'' ?>" data-rg-set="1" data-testid="region-activate-<?= esc($r['code']) ?>">
              <i class="bi bi-check-circle-fill me-1"></i> Active
            </button>
            <button type="button" class="rg-toggle-opt rg-off <?= $r['active']?'':'sel' ?>" data-rg-set="0" data-testid="region-deactivate-<?= esc($r['code']) ?>">
              <i class="bi bi-slash-circle me-1"></i> Deactive
            </button>
            <span class="rg-toggle-thumb" data-rg-thumb></span>
          </div>
          <div class="rg-toggle-hint small text-muted mt-2 text-center" data-rg-hint>
            <?= $r['active']?'Products in this region are <strong>visible</strong> on the public website.':'Products in this region are <strong>hidden</strong> from the public website.' ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <style>
    .region-flag {
      width: 44px; height: 32px;
      object-fit: cover;
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(0,0,0,.18);
      border: 1px solid rgba(255,255,255,.6);
      flex-shrink: 0;
    }
    [data-bs-theme="dark"] .region-flag { border-color: rgba(255,255,255,.15); box-shadow: 0 2px 8px rgba(0,0,0,.45); }
    .region-flag-fb {
      width: 44px; height: 32px;
      display:inline-flex; align-items:center; justify-content:center;
      border-radius: 6px; background: var(--bg); color: var(--brand);
      border: 1px solid var(--border); font-size: 18px;
    }
    .rg-status-pill { display:inline-flex; align-items:center; font-size:11px; font-weight:600; padding:4px 10px; border-radius:999px; letter-spacing:.3px; }
    .rg-status-pill.on  { background:#dcfce7; color:#166534; }
    .rg-status-pill.off { background:#fee2e2; color:#991b1b; }
    [data-bs-theme="dark"] .rg-status-pill.on  { background:rgba(34,197,94,.15); color:#86efac; }
    [data-bs-theme="dark"] .rg-status-pill.off { background:rgba(239,68,68,.15); color:#fca5a5; }

    .rg-toggle-bar {
      position: relative;
      display: grid;
      grid-template-columns: 1fr 1fr;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 999px;
      padding: 4px;
      overflow: hidden;
    }
    .rg-toggle-opt {
      position: relative; z-index: 2;
      border: 0; background: transparent;
      padding: 8px 10px;
      font-size: 13px; font-weight: 600;
      color: var(--text-muted, #64748b);
      border-radius: 999px;
      transition: color .25s ease;
      cursor: pointer;
    }
    .rg-toggle-opt.sel { color: #fff; }
    .rg-toggle-opt:focus { outline: none; }
    .rg-toggle-thumb {
      position: absolute; top: 4px; bottom: 4px;
      width: calc(50% - 4px);
      border-radius: 999px;
      transition: transform .28s cubic-bezier(.4,.0,.2,1), background-color .25s ease, box-shadow .25s ease;
      z-index: 1;
      pointer-events: none;
    }
    .rg-toggle-bar[data-rg-state="on"]  .rg-toggle-thumb { transform: translateX(0);    background: linear-gradient(135deg,#10b981,#059669); box-shadow: 0 4px 12px rgba(16,185,129,.35); }
    .rg-toggle-bar[data-rg-state="off"] .rg-toggle-thumb { transform: translateX(100%); background: linear-gradient(135deg,#ef4444,#b91c1c); box-shadow: 0 4px 12px rgba(239,68,68,.35); }
    .rg-toggle-bar.is-saving { opacity: .6; pointer-events: none; }
    .rg-toggle-bar.is-saving::after {
      content:""; position:absolute; right:8px; top:50%; width:14px; height:14px;
      margin-top:-7px; border:2px solid #fff; border-top-color:transparent;
      border-radius:50%; animation: rg-spin .7s linear infinite; z-index:3;
    }
    @keyframes rg-spin { to { transform: rotate(360deg); } }
  </style>

  <script>
  (function(){
    document.querySelectorAll('[data-rg-bar]').forEach(function(bar){
      var card  = bar.closest('[data-region-card]');
      var code  = card.getAttribute('data-region-card');
      var pill  = card.querySelector('[data-rg-pill]');
      var hint  = card.querySelector('[data-rg-hint]');
      var hidden= card.querySelector('[data-rg-hidden-active]');
      bar.querySelectorAll('[data-rg-set]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var want = btn.getAttribute('data-rg-set') === '1';
          var cur  = bar.getAttribute('data-rg-state') === 'on';
          if (want === cur) return;
          bar.classList.add('is-saving');
          fetch('ajax/region-toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code, active: want ? 1 : 0 })
          }).then(function(r){ return r.json(); }).then(function(j){
            bar.classList.remove('is-saving');
            if (!j || !j.ok) {
              alert((j && j.error) ? j.error : 'Failed to update region');
              return;
            }
            // Update UI in place
            bar.setAttribute('data-rg-state', want ? 'on' : 'off');
            bar.querySelectorAll('[data-rg-set]').forEach(function(b){
              b.classList.toggle('sel', b.getAttribute('data-rg-set') === (want ? '1' : '0'));
            });
            if (pill) {
              pill.classList.toggle('on',  want);
              pill.classList.toggle('off', !want);
              pill.innerHTML = want
                ? '<i class="bi bi-broadcast me-1"></i>Live'
                : '<i class="bi bi-pause-circle me-1"></i>Paused';
            }
            if (hint) {
              hint.innerHTML = want
                ? 'Products in this region are <strong>visible</strong> on the public website.'
                : 'Products in this region are <strong>hidden</strong> from the public website.';
            }
            if (hidden) hidden.value = want ? 1 : 0;
          }).catch(function(){
            bar.classList.remove('is-saving');
            alert('Network error — please try again.');
          });
        });
      });
    });
  })();
  </script>

<?php
// ============================================================================
// SETTINGS (statement names — moved here from old)
// ============================================================================
elseif ($tab === 'reviews'):
  $sf = $_GET['status'] ?? '';
  $w='WHERE cr.rating IS NOT NULL'; $args=[];
  if (in_array($sf,['published','hidden'],true)) { $w.=' AND cr.status=?'; $args[]=$sf; }
  $st = $pdo->prepare("SELECT cr.*, p.name AS product_name, p.image AS product_image, o.order_number
    FROM customer_reviews cr LEFT JOIN products p ON p.slug=cr.product_slug LEFT JOIN orders o ON o.id=cr.order_id $w ORDER BY cr.submitted_at DESC, cr.id DESC LIMIT 200");
  $st->execute($args);
  $reviews = $st->fetchAll();
  $cnt = $pdo->query("SELECT
    SUM(status='published' AND rating IS NOT NULL) p,
    SUM(status='hidden' AND rating IS NOT NULL) h,
    AVG(CASE WHEN status='published' THEN rating END) avg_r,
    SUM(rating IS NOT NULL) responded,
    COUNT(*) t FROM customer_reviews")->fetch();
?>
  <h5 class="fw-bold mb-1">Customer Reviews <span class="text-muted fs-6">— only customers who responded</span></h5>
  <p class="text-muted small mb-3">Showing reviews where the customer submitted a rating. Pending invites are hidden by default.</p>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="kpi-tile amber"><div class="kpi-icon"><i class="bi bi-star-fill"></i></div><div class="kpi-label">Avg Rating</div><div class="kpi-value"><?= number_format((float)($cnt['avg_r'] ?? 0), 1) ?> ★</div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-tile green"><div class="kpi-icon"><i class="bi bi-check-circle"></i></div><div class="kpi-label">Published</div><div class="kpi-value"><?= (int)$cnt['p'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-tile blue"><div class="kpi-icon"><i class="bi bi-chat-square-text"></i></div><div class="kpi-label">Total Responded</div><div class="kpi-value"><?= (int)$cnt['responded'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-tile red"><div class="kpi-icon"><i class="bi bi-eye-slash"></i></div><div class="kpi-label">Hidden</div><div class="kpi-value"><?= (int)$cnt['h'] ?></div></div></div>
  </div>

  <div class="d-flex gap-2 mb-3 flex-wrap">
    <?php foreach (['' => 'All Responded', 'published'=>'Published', 'hidden'=>'Hidden'] as $k=>$lbl): ?>
      <a class="adm-pill <?= $sf===$k?'active':'' ?>" href="?tab=reviews<?= $k?'&status='.$k:'' ?>"><?= esc($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="tbl-e">
    <table class="table mb-0" data-testid="reviews-table">
      <thead><tr><th>Customer</th><th>Product</th><th>Order</th><th>Rating</th><th>Comment</th><th>Source</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($reviews)): ?><tr><td colspan="9" class="text-center text-muted py-4">No customer responses yet. Reviews appear here only after the customer submits a rating + comment via the post-purchase email.</td></tr><?php endif; ?>
        <?php foreach ($reviews as $r): $r_stars = (int)$r['rating']; ?>
          <tr>
            <td><strong><?= esc($r['customer_name']) ?></strong><br><small class="text-muted"><?= esc($r['customer_email']) ?></small></td>
            <td><div class="d-flex align-items-center gap-2">
              <?php if ($r['product_image']): ?><img src="<?= esc($r['product_image']) ?>" style="width:32px;height:32px;object-fit:contain;background:var(--bg);border-radius:6px;padding:3px;"><?php endif; ?>
              <small><?= esc(mb_strimwidth($r['product_name'] ?? '', 0, 40, '…')) ?></small>
            </div></td>
            <td><?= $r['order_number'] ? '<a href="order-view.php?id='.(int)$r['order_id'].'"><code>#'.esc($r['order_number']).'</code></a>' : '—' ?></td>
            <td><span style="color:#facc15;font-size:14px;letter-spacing:1px;"><?= str_repeat('★', $r_stars) . str_repeat('☆', 5-$r_stars) ?></span><div><small><strong><?= $r_stars ?>/5</strong></small></div></td>
            <td style="max-width:320px;"><small><?= esc(mb_strimwidth($r['comment'] ?? '', 0, 140, '…')) ?></small></td>
            <td><?= $r['ai_generated'] ? '<span class="s-badge sent"><i class="bi bi-stars"></i> AI</span>' : '<span class="s-badge delivered">Manual</span>' ?></td>
            <td><span class="s-badge <?= $r['status']==='published'?'paid':'failed' ?>"><?= esc($r['status']) ?></span></td>
            <td><small class="text-muted"><?= esc(date('M j, Y', strtotime($r['submitted_at']))) ?></small></td>
            <td class="text-nowrap">
              <?php if ($r['status']!=='hidden'): ?>
                <form method="post" class="d-inline"><input type="hidden" name="action" value="review_update_status"><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="hidden"><button class="btn btn-soft-gray btn-sm py-0 px-2" title="Hide"><i class="bi bi-eye-slash"></i></button></form>
              <?php else: ?>
                <form method="post" class="d-inline"><input type="hidden" name="action" value="review_update_status"><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="published"><button class="btn btn-soft-blue btn-sm py-0 px-2" title="Re-publish"><i class="bi bi-eye"></i></button></form>
              <?php endif; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this review permanently?')"><input type="hidden" name="action" value="review_delete"><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-soft-red btn-sm py-0 px-2"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'settings'):
  $cardMerch  = setting_get('gw_card_merchant_name','Maventech Software');
  $ppAcc      = setting_get('gw_paypal_account_name','Maventech Software LLC');
?>
  <h5 class="fw-bold mb-1">Settings</h5>
  <p class="text-muted small mb-3">General settings. Payment credentials and merchant/company names live in <a href="admin.php?tab=api">API Management</a>.</p>

  <div class="card-e p-4" style="max-width:760px;">
    <h6 class="fw-bold mb-2"><i class="bi bi-credit-card me-1"></i> Billing &amp; Statement Names</h6>
    <p class="text-muted small mb-3">
      The company name shown on the customer's bank/card statement and in the order-confirmation email
      is now sourced from <a href="admin.php?tab=api"><strong>API Management</strong></a>.
      Update it on the Card or PayPal API card and it will flow through to billing notes everywhere automatically.
    </p>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="p-3 rounded" style="background:var(--bg);border:1px solid var(--border);">
          <small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Card / Stripe Merchant</small>
          <div class="fw-bold mt-1" data-testid="stmt-card-readonly"><?= esc($cardMerch) ?></div>
          <a href="admin.php?tab=api" class="small">Edit in API Management <i class="bi bi-arrow-right-short"></i></a>
        </div>
      </div>
      <div class="col-md-6">
        <div class="p-3 rounded" style="background:var(--bg);border:1px solid var(--border);">
          <small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">PayPal Business</small>
          <div class="fw-bold mt-1" data-testid="stmt-paypal-readonly"><?= esc($ppAcc) ?></div>
          <a href="admin.php?tab=api" class="small">Edit in API Management <i class="bi bi-arrow-right-short"></i></a>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
