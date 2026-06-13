<?php
/*
 * One-shot deployment sanity check + auto-repair.
 *
 *   Visit  https://your-domain.com/setup-check.php  (admin login required)
 *   to see whether every required table/column exists, which AJAX endpoints
 *   reachable, and what base URL the panel is running under.  Anything
 *   missing is auto-created (idempotent) so the panel becomes functional
 *   without shell access to the live server.
 */
require_once __DIR__ . '/includes/functions.php';
require_admin();

$pdo = db();

$reportTable = function(string $name) use ($pdo): array {
    try {
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return ['name' => $name, 'exists' => (bool)$st->fetchColumn()];
    } catch (Throwable $e) { return ['name' => $name, 'exists' => false, 'error' => $e->getMessage()]; }
};
$reportColumn = function(string $table, string $column) use ($pdo): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        return ['table' => $table, 'column' => $column, 'exists' => (bool)$st->fetchColumn()];
    } catch (Throwable $e) { return ['table' => $table, 'column' => $column, 'exists' => false, 'error' => $e->getMessage()]; }
};

// Force a re-run of the auto-migration (it's idempotent).
ensure_db_schema();

$tables = array_map($reportTable, ['products','orders','users','chat_leads','chat_messages','visitor_log','email_outbox','customer_reviews','companies','currency_overrides']);
$cols   = [
    $reportColumn('chat_leads','last_seen'),
    $reportColumn('chat_leads','chat_token'),
    $reportColumn('email_outbox','retry_count'),
    $reportColumn('email_outbox','max_retries'),
    $reportColumn('email_outbox','last_error'),
];
$ajaxFiles = ['ajax/chat-customer.php','ajax/chat-admin.php','ajax/email-resend.php','ajax/smtp-test-recipient.php','ajax/visitor-stats.php','ajax/lead.php','ajax/cart.php','ajax/chat.php'];

include __DIR__ . '/includes/admin-shell.php';
?>
<div class="adm-content">
<h2 class="mb-3"><i class="bi bi-tools me-2"></i>Setup & Deployment Check</h2>
<p class="text-muted">Run this page after uploading to a new server.  Anything that's red here is the most likely cause of "feature X isn't working".</p>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-globe2 me-2"></i>Environment</div>
  <table class="table table-sm mb-0">
    <tr><td><strong>Detected base URL</strong></td><td><code><?= esc(base_url()) ?></code></td></tr>
    <tr><td><strong>PHP version</strong></td><td><code><?= esc(PHP_VERSION) ?></code> <?= version_compare(PHP_VERSION,'8.0','>=')?'<span class="text-success"><i class="bi bi-check-circle-fill"></i> OK</span>':'<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Needs PHP 8.0+</span>' ?></td></tr>
    <tr><td><strong>HTTPS</strong></td><td><?= !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https' ? '<span class="text-success"><i class="bi bi-shield-check"></i> Secure</span>' : '<span class="text-warning">Plain HTTP — some browsers block AJAX from HTTPS pages</span>' ?></td></tr>
    <tr><td><strong>session.save_path writable</strong></td><td><?= is_writable(session_save_path() ?: sys_get_temp_dir()) ? '<span class="text-success">OK</span>' : '<span class="text-danger">NOT writable — sessions may not persist</span>' ?></td></tr>
    <tr><td><strong>PDO MySQL driver</strong></td><td><?= in_array('mysql', PDO::getAvailableDrivers(), true) ? '<span class="text-success">OK</span>' : '<span class="text-danger">Missing</span>' ?></td></tr>
  </table>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-table me-2"></i>Database tables</div>
  <table class="table table-sm mb-0">
    <?php foreach ($tables as $t): ?>
      <tr>
        <td><code><?= esc($t['name']) ?></code></td>
        <td><?= $t['exists'] ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> exists</span>' : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> MISSING</span>' ?></td>
        <?php if (!$t['exists']): ?><td class="small text-muted">Will be re-created by ensure_db_schema() on next admin load. Error: <code><?= esc($t['error'] ?? '—') ?></code></td><?php else: ?><td></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-columns me-2"></i>Required columns</div>
  <table class="table table-sm mb-0">
    <?php foreach ($cols as $c): ?>
      <tr>
        <td><code><?= esc($c['table']) ?>.<?= esc($c['column']) ?></code></td>
        <td><?= $c['exists'] ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ok</span>' : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> MISSING</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-link-45deg me-2"></i>AJAX endpoint files</div>
  <table class="table table-sm mb-0">
    <?php foreach ($ajaxFiles as $f): $abs = __DIR__ . '/' . $f; ?>
      <tr>
        <td><code><?= esc($f) ?></code></td>
        <td><?= file_exists($abs) ? '<span class="text-success">file exists</span>' : '<span class="text-danger">MISSING — re-upload it</span>' ?></td>
        <td><?= file_exists($abs) && is_readable($abs) ? '<span class="text-success">readable</span>' : '<span class="text-warning">check perms (644)</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <div class="small text-muted mt-2">JS calls these via <code>window.MAVEN_BASE + 'ajax/&lt;file&gt;.php'</code>.  Current MAVEN_BASE = <code><?= esc(base_url()) ?></code>.  If your hosting puts the project in a different folder, just upload everything and visit the admin panel — MAVEN_BASE auto-detects from <code>$_SERVER['SCRIPT_NAME']</code>.</div>
</div>

<div class="card-e p-3">
  <div class="fw-bold mb-2"><i class="bi bi-activity me-2"></i>Quick AJAX probe</div>
  <div id="probeResults" class="small">Click "Run probe" to check that every endpoint responds correctly from the browser…</div>
  <button class="btn btn-primary mt-2" onclick="runProbe()"><i class="bi bi-play-fill me-1"></i>Run probe</button>
</div>
</div>

<script>
async function runProbe(){
  const out = document.getElementById('probeResults'); out.innerHTML = '';
  const ep = [
    ['POST', 'ajax/chat-admin.php',         {action:'unread'}],
    ['GET',  'ajax/visitor-stats.php?from='+new Date().toISOString().slice(0,10)+'&to='+new Date().toISOString().slice(0,10), null],
    ['POST', 'ajax/smtp-test-recipient.php', {email:'test@example.com'}],
  ];
  for (const [method, path, body] of ep) {
    const url = (window.MAVEN_BASE||'/') + path;
    let line = '<div>' + method + ' <code>' + url + '</code> … ';
    try {
      const opts = {method};
      if (body) { opts.headers = {'Content-Type':'application/json'}; opts.body = JSON.stringify(body); }
      const r = await fetch(url, opts);
      const txt = await r.text();
      const looksOk = r.ok && (txt.includes('"ok":true') || txt.length > 50);
      line += '<span class="' + (looksOk?'text-success':'text-danger') + '">HTTP ' + r.status + (looksOk?' ✓':' ✗') + '</span></div>';
    } catch(e) {
      line += '<span class="text-danger">ERROR: ' + e.message + '</span></div>';
    }
    out.insertAdjacentHTML('beforeend', line);
  }
}
</script>
