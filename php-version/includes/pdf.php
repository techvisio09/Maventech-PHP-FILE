<?php
/*
 * Receipt + Invoice PDF generators — used by send_email() to attach
 * proper, professionally-formatted PDFs to every paid order email.
 *
 * Layout closely mirrors the reference Emergent receipt / invoice style
 * the product owner provided: clean sans-serif, two-column header with
 * company info on the left + brand logo / receipt number on the right,
 * "Bill to" customer block, single line-items table with right-aligned
 * currency, summary totals, payment-history table (for the receipt
 * variant only), and a clear statement-name line so the customer knows
 * what to look for on their bank statement.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Build a Dompdf instance with sane defaults for our receipts/invoices.
 */
function _pdf_dompdf(): Dompdf
{
    $o = new Options();
    $o->set('defaultFont',           'DejaVu Sans');   // ships with Dompdf
    $o->set('isHtml5ParserEnabled',  true);
    $o->set('isRemoteEnabled',       false);           // we never load remote assets
    $o->set('chroot',                __DIR__ . '/..'); // keep file access local
    return new Dompdf($o);
}

/**
 * Number → currency formatter that matches what we show on the site
 * (uses the symbol of the order's currency, not the active session one).
 */
function _pdf_money(float $amount, string $cur = 'USD'): string
{
    $sym = ['USD'=>'$','GBP'=>'£','EUR'=>'€','CAD'=>'CA$','AUD'=>'A$','INR'=>'₹','AED'=>'د.إ'][$cur] ?? '';
    return $sym . number_format($amount, 2);
}

/**
 * Shared HTML head + brand header used by both Receipt and Invoice.
 * Variant: 'receipt' or 'invoice' — only the title + sub-line change.
 */
function _pdf_shell(array $ctx, string $bodyHtml): string
{
    $co       = $ctx['co'];
    $brand    = htmlspecialchars($co['name']    ?? 'Maventech Software', ENT_QUOTES, 'UTF-8');
    $brandAddr= nl2br(htmlspecialchars($co['address'] ?? '',             ENT_QUOTES, 'UTF-8'));
    $brandEm  = htmlspecialchars($co['email']   ?? '',                   ENT_QUOTES, 'UTF-8');
    $logoUrl  = $ctx['logo']  ?? '';   // local file path is fine for Dompdf
    $docTitle = htmlspecialchars($ctx['title'] ?? 'Document',            ENT_QUOTES, 'UTF-8');
    $invNo    = htmlspecialchars($ctx['invoice_number'] ?? '',           ENT_QUOTES, 'UTF-8');
    $secondRow= '';
    if (!empty($ctx['receipt_number'])) {
        $secondRow .= '<tr><td>Receipt number</td><td class="r">' . htmlspecialchars($ctx['receipt_number'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_paid'])) {
        $secondRow .= '<tr><td>Date paid</td><td class="r">' . htmlspecialchars($ctx['date_paid'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_issued'])) {
        $secondRow .= '<tr><td>Date of issue</td><td class="r">' . htmlspecialchars($ctx['date_issued'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_due'])) {
        $secondRow .= '<tr><td>Date due</td><td class="r">'  . htmlspecialchars($ctx['date_due'], ENT_QUOTES, 'UTF-8')  . '</td></tr>';
    }
    $billLines = '';
    foreach ((array)($ctx['bill_to'] ?? []) as $line) {
        $billLines .= '<div>' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $logoTag = $logoUrl && file_exists($logoUrl)
        ? '<img src="' . $logoUrl . '" alt="' . $brand . '" style="height:44px;width:auto;vertical-align:top;">'
        : '<div style="font-size:18px;font-weight:800;color:#06b6d4;letter-spacing:.5px;">' . $brand . '</div>';

    return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 56px 48px; }
  body  { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #1f2937; }
  h1    { font-size: 22pt; font-weight: 700; margin: 0 0 14px; color: #0f172a; letter-spacing: .3px; }
  .head-grid { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
  .head-grid td { vertical-align: top; }
  .head-meta { width: 50%; }
  .head-meta table { width: 100%; border-collapse: collapse; font-size: 9.5pt; color: #475569; }
  .head-meta table td { padding: 2px 0; }
  .head-meta table td.r { text-align: right; color: #0f172a; font-weight: 600; }
  .head-brand { width: 50%; text-align: right; }
  .head-brand .brand-line { margin-top: 6px; font-size: 9pt; color: #64748b; line-height: 1.45; }

  .from-bill { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  .from-bill td { vertical-align: top; width: 50%; padding-right: 12px; font-size: 9.5pt; color: #1f2937; }
  .from-bill .label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2px; color: #94a3b8; font-weight: 700; margin-bottom: 4px; }
  .from-bill .bold  { color: #0f172a; font-weight: 700; }

  .amount-banner { background: #f8fafc; border-left: 4px solid #06b6d4; padding: 14px 16px; margin-bottom: 22px; }
  .amount-banner .amt { font-size: 18pt; font-weight: 700; color: #0f172a; }
  .amount-banner .sub { font-size: 9pt; color: #64748b; margin-top: 2px; }

  table.items, table.payhist { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  table.items th, table.items td, table.payhist th, table.payhist td { padding: 9px 4px; font-size: 9.5pt; }
  table.items thead, table.payhist thead { border-bottom: 2px solid #0f172a; }
  table.items th, table.payhist th { text-align: left; font-weight: 700; color: #0f172a; font-size: 9pt; text-transform: uppercase; letter-spacing: .5px; }
  table.items td, table.payhist td { border-bottom: 1px solid #e2e8f0; }
  table.items td.num, table.items th.num, table.payhist td.num, table.payhist th.num { text-align: right; }
  .totals { width: 50%; margin-left: 50%; border-collapse: collapse; font-size: 10pt; }
  .totals td { padding: 5px 4px; }
  .totals td.label { color: #475569; }
  .totals td.value { text-align: right; color: #0f172a; font-weight: 600; }
  .totals tr.total-row td { border-top: 2px solid #0f172a; padding-top: 9px; font-size: 11.5pt; font-weight: 700; color: #0f172a; }
  .totals tr.amount-paid td { padding-top: 9px; color: #047857; font-weight: 700; }
  .totals tr.amount-due td { padding-top: 9px; color: #b91c1c; font-weight: 700; }

  .statement {
    background: #fffbeb; border-left: 3px solid #f59e0b; padding: 10px 14px;
    border-radius: 4px; margin: 22px 0; font-size: 9.5pt; color: #92400e;
  }
  .statement .lbl { font-weight: 700; color: #78350f; }

  .footer {
    margin-top: 30px; padding-top: 14px; border-top: 1px solid #e2e8f0;
    font-size: 8pt; color: #94a3b8; line-height: 1.6;
  }
</style>
</head>
<body>
  <h1>{$docTitle}</h1>
  <table class="head-grid"><tr>
    <td class="head-meta">
      <table>
        <tr><td>Invoice number</td><td class="r">{$invNo}</td></tr>
        {$secondRow}
      </table>
    </td>
    <td class="head-brand">
      {$logoTag}
      <div class="brand-line">
        <strong style="color:#0f172a;">{$brand}</strong><br>
        {$brandAddr}<br>
        {$brandEm}
      </div>
    </td>
  </tr></table>

  <table class="from-bill"><tr>
    <td><div class="label">Bill to</div>{$billLines}</td>
    <td></td>
  </tr></table>

  {$bodyHtml}

  <div class="footer">
    Questions? Reply to this email or visit our support page. Thanks for choosing {$brand}.
  </div>
</body></html>
HTML;
}

/**
 * Generate a Receipt PDF (paid orders).  Returns the binary PDF string.
 * Throws on rendering failure.
 */
function generate_receipt_pdf(array $order, array $items, ?array $payment = null): string
{
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech Software'];
    $cur = (string)($order['currency'] ?? 'USD');
    $invoiceNo = (string)($order['order_number'] ?? '');
    $receiptNo = strtoupper(substr(bin2hex(sha1((string)$order['id'] . '-' . $invoiceNo, true)), 0, 9));
    // Insert a hyphen so it looks like "2797-4805"
    $receiptNo = substr($receiptNo, 0, 4) . '-' . substr($receiptNo, 4, 4);

    $datePaid = $order['paid_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s');
    $datePaid = date('F j, Y', strtotime($datePaid));

    // Bill-to block — sanitised, multi-line.
    $billTo = array_filter([
        trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? '')),
        (string)$order['email'],
        trim(((string)($order['address']  ?? '')) . (empty($order['address2']) ? '' : ', ' . $order['address2'])),
        trim(((string)($order['city']     ?? '')) . ', ' . ((string)($order['state'] ?? '')) . ' ' . ((string)($order['zip'] ?? ''))),
        (string)($order['country'] ?? ''),
    ], fn($l) => trim((string)$l) !== '');

    $stmtName = !empty($order['card_statement_name'])
        ? (string)$order['card_statement_name']
        : (function_exists('statement_name_for')
            ? (string)statement_name_for((string)($order['payment_method'] ?? 'card'))
            : (string)($co['name'] ?? 'Maventech Software'));

    // Items table rows.
    $itemsHtml = '<table class="items"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>';
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        $unit  = (float)($it['unit_price'] ?? $it['price'] ?? 0);
        $amt   = $qty * $unit;
        $subtotal += $amt;
        $itemsHtml .= '<tr><td>' . htmlspecialchars((string)($it['name'] ?? $it['product_name'] ?? '—'), ENT_QUOTES, 'UTF-8')
                   . '</td><td class="num">' . $qty
                   . '</td><td class="num">' . _pdf_money($unit, $cur)
                   . '</td><td class="num">' . _pdf_money($amt, $cur) . '</td></tr>';
    }
    $itemsHtml .= '</tbody></table>';

    $total = (float)($order['total'] ?? $subtotal);

    $payRow = '';
    if ($payment) {
        $payMethod = htmlspecialchars((string)($payment['method'] ?? 'Card'), ENT_QUOTES, 'UTF-8');
        $payDate   = htmlspecialchars((string)($payment['date']   ?? $datePaid), ENT_QUOTES, 'UTF-8');
        $payRow = "<tr><td>{$payMethod}</td><td>{$payDate}</td><td class=\"num\">" . _pdf_money($total, $cur) . "</td><td class=\"num\">{$receiptNo}</td></tr>";
    } elseif (!empty($order['card_brand']) || !empty($order['payment_method'])) {
        $brand = $order['card_brand'] ?: ucfirst((string)$order['payment_method']);
        $tail  = !empty($order['card_last4']) ? ' - ' . $order['card_last4'] : '';
        $payRow = "<tr><td>{$brand}{$tail}</td><td>{$datePaid}</td><td class=\"num\">" . _pdf_money($total, $cur) . "</td><td class=\"num\">{$receiptNo}</td></tr>";
    }

    $bodyHtml = '<div class="amount-banner">
                    <div class="amt">' . _pdf_money($total, $cur) . ' paid on ' . htmlspecialchars($datePaid, ENT_QUOTES, 'UTF-8') . '</div>
                    <div class="sub">Thanks for your purchase — your license keys are delivered in the accompanying email.</div>
                 </div>'
              . $itemsHtml
              . '<table class="totals">
                    <tr><td class="label">Subtotal</td><td class="value">' . _pdf_money($subtotal, $cur) . '</td></tr>
                    <tr class="total-row"><td class="label">Total</td><td class="value">' . _pdf_money($total, $cur) . '</td></tr>
                    <tr class="amount-paid"><td class="label">Amount paid</td><td class="value">' . _pdf_money($total, $cur) . '</td></tr>
                 </table>'
              . '<div class="statement"><span class="lbl">Bank statement note:</span> This charge will appear as <strong>' . htmlspecialchars($stmtName, ENT_QUOTES, 'UTF-8') . '</strong> on your card statement.</div>'
              . ($payRow ? '<div style="font-weight:700;color:#0f172a;margin:18px 0 6px;font-size:11pt;">Payment history</div>
                            <table class="payhist"><thead><tr><th>Payment method</th><th>Date</th><th class="num">Amount paid</th><th class="num">Receipt number</th></tr></thead><tbody>' . $payRow . '</tbody></table>' : '');

    $html = _pdf_shell([
        'co'              => $co,
        'logo'            => __DIR__ . '/../assets/images/brand/email-logo.gif',
        'title'           => 'Receipt',
        'invoice_number'  => $invoiceNo,
        'receipt_number'  => $receiptNo,
        'date_paid'       => $datePaid,
        'bill_to'         => $billTo,
    ], $bodyHtml);

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Generate an Invoice PDF (issued at order time — works for both paid and
 * pending orders).  Returns the binary PDF string.
 */
function generate_invoice_pdf(array $order, array $items): string
{
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech Software'];
    $cur = (string)($order['currency'] ?? 'USD');
    $invoiceNo = (string)($order['order_number'] ?? '');

    $dateIssued = date('F j, Y', strtotime((string)($order['created_at'] ?? 'now')));
    $dateDue    = $dateIssued;  // For our digital goods, due-on-issue.

    $billTo = array_filter([
        trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? '')),
        (string)$order['email'],
        trim(((string)($order['address']  ?? '')) . (empty($order['address2']) ? '' : ', ' . $order['address2'])),
        trim(((string)($order['city']     ?? '')) . ', ' . ((string)($order['state'] ?? '')) . ' ' . ((string)($order['zip'] ?? ''))),
        (string)($order['country'] ?? ''),
    ], fn($l) => trim((string)$l) !== '');

    $stmtName = !empty($order['card_statement_name'])
        ? (string)$order['card_statement_name']
        : (function_exists('statement_name_for')
            ? (string)statement_name_for((string)($order['payment_method'] ?? 'card'))
            : (string)($co['name'] ?? 'Maventech Software'));

    $itemsHtml = '<table class="items"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>';
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        $unit  = (float)($it['unit_price'] ?? $it['price'] ?? 0);
        $amt   = $qty * $unit;
        $subtotal += $amt;
        $itemsHtml .= '<tr><td>' . htmlspecialchars((string)($it['name'] ?? $it['product_name'] ?? '—'), ENT_QUOTES, 'UTF-8')
                   . '</td><td class="num">' . $qty
                   . '</td><td class="num">' . _pdf_money($unit, $cur)
                   . '</td><td class="num">' . _pdf_money($amt, $cur) . '</td></tr>';
    }
    $itemsHtml .= '</tbody></table>';

    $total = (float)($order['total'] ?? $subtotal);
    $isPaid = (string)($order['status'] ?? '') === 'paid';

    $bodyHtml = '<div class="amount-banner">
                    <div class="amt">' . _pdf_money($total, $cur) . ' ' . htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') . ($isPaid ? ' &mdash; paid' : ' due ' . htmlspecialchars($dateDue, ENT_QUOTES, 'UTF-8')) . '</div>
                    <div class="sub">' . ($isPaid ? 'Already paid — keep this invoice for your records.' : 'Please complete payment to receive your license keys.') . '</div>
                 </div>'
              . $itemsHtml
              . '<table class="totals">
                    <tr><td class="label">Subtotal</td><td class="value">' . _pdf_money($subtotal, $cur) . '</td></tr>
                    <tr class="total-row"><td class="label">Total</td><td class="value">' . _pdf_money($total, $cur) . '</td></tr>
                    <tr class="' . ($isPaid ? 'amount-paid' : 'amount-due') . '">
                        <td class="label">' . ($isPaid ? 'Amount paid' : 'Amount due') . '</td>
                        <td class="value">' . _pdf_money($total, $cur) . ' ' . htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                 </table>'
              . '<div class="statement"><span class="lbl">Bank statement note:</span> This charge ' . ($isPaid ? 'appears' : 'will appear') . ' as <strong>' . htmlspecialchars($stmtName, ENT_QUOTES, 'UTF-8') . '</strong> on your card statement.</div>';

    $html = _pdf_shell([
        'co'              => $co,
        'logo'            => __DIR__ . '/../assets/images/brand/email-logo.gif',
        'title'           => 'Invoice',
        'invoice_number'  => $invoiceNo,
        'date_issued'     => $dateIssued,
        'date_due'        => $dateDue,
        'bill_to'         => $billTo,
    ], $bodyHtml);

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Save both PDFs to /uploads/order-pdfs/{order_id}/ and return their
 * absolute paths so send_email() can attach them.  Idempotent — overwrites
 * existing files if called repeatedly for the same order.
 */
function generate_order_pdfs(array $order, array $items): array
{
    $dir = __DIR__ . '/../uploads/order-pdfs/' . (int)($order['id'] ?? 0);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rcptPath = $dir . '/Receipt-'   . (string)($order['order_number'] ?? 'X') . '.pdf';
    $invPath  = $dir . '/Invoice-'   . (string)($order['order_number'] ?? 'X') . '.pdf';
    try {
        @file_put_contents($rcptPath, generate_receipt_pdf($order, $items));
    } catch (Throwable $e) { @error_log('[pdf receipt] ' . $e->getMessage()); $rcptPath = ''; }
    try {
        @file_put_contents($invPath,  generate_invoice_pdf($order, $items));
    } catch (Throwable $e) { @error_log('[pdf invoice] ' . $e->getMessage()); $invPath  = ''; }
    return array_values(array_filter([$rcptPath, $invPath]));
}
