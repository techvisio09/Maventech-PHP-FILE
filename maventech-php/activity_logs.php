<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Activity Logs';

$logs = $pdo->query('SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 500')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>
<h4 class="mb-3">Activity Logs</h4>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Entity</th><th>ID</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><small><?= e(date('M j, Y H:i:s',strtotime($l['created_at']))) ?></small></td>
            <td><?= e($l['admin_name']) ?></td>
            <td><span class="badge bg-secondary"><?= e($l['action']) ?></span></td>
            <td><?= e($l['entity']) ?></td>
            <td><?= e($l['entity_id']) ?></td>
            <td><small><?= e($l['details']) ?></small></td>
            <td><small class="text-muted"><?= e($l['ip_address']) ?></small></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="7" class="text-center text-muted py-4">No activity yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
