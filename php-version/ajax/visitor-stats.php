<?php
/*
 * Visitor stats — admin-only AJAX partial.
 *   GET  /ajax/visitor-stats.php?range=today|7d|30d|90d|1y|custom&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   Returns an HTML fragment (the inner content of the Visitors widget) that
 *   the dashboard JS can swap in-place without a full page reload.
 */
require_once __DIR__ . '/../includes/functions.php';
require_admin();
header('Content-Type: text/html; charset=utf-8');

$pdo  = db();
$range = (string)($_GET['range'] ?? 'today');

// Same range catalog as the dashboard PHP so the AJAX & initial render stay in sync.
$vRanges = [
    'today' => ['Today',          'CURDATE()'],
    '7d'    => ['Last 7 days',    'DATE_SUB(CURDATE(), INTERVAL 6 DAY)'],
    '30d'   => ['Last 30 days',   'DATE_SUB(CURDATE(), INTERVAL 29 DAY)'],
    '90d'   => ['Last 3 months',  'DATE_SUB(CURDATE(), INTERVAL 89 DAY)'],
    '1y'    => ['Last year',      'DATE_SUB(CURDATE(), INTERVAL 364 DAY)'],
];
$fromDate = $toDate = null;
if ($range === 'custom') {
    $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
    $toDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to']   ?? '')) ? $_GET['to']   : '';
    if (!$fromDate || !$toDate) { $range = 'today'; }
}
if ($range !== 'custom' && !isset($vRanges[$range])) $range = 'today';

if ($range === 'custom') {
    $vRangeLabel = 'Custom · ' . $fromDate . ' → ' . $toDate;
    $vWhere      = "DATE(visited_at) BETWEEN " . $pdo->quote($fromDate) . " AND " . $pdo->quote($toDate);
    $rangeDays   = (int)((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1;
    $prevFrom    = date('Y-m-d', strtotime($fromDate) - 86400 * $rangeDays);
    $prevTo      = date('Y-m-d', strtotime($fromDate) - 86400);
    $vPrevWhere  = "DATE(visited_at) BETWEEN " . $pdo->quote($prevFrom) . " AND " . $pdo->quote($prevTo);
    $vTrendN     = min(30, max(7, $rangeDays));
    $vTrendGroup = $rangeDays > 60 ? 'week' : 'day';
} else {
    [$vRangeLabel, $vRangeStart] = $vRanges[$range];
    if ($range === 'today') {
        $vWhere     = "DATE(visited_at)=CURDATE()";
        $vPrevWhere = "DATE(visited_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $rangeDays  = 1;
    } else {
        $vWhere    = "visited_at >= $vRangeStart";
        $rangeDays = ['7d'=>7,'30d'=>30,'90d'=>90,'1y'=>365][$range];
        $vPrevWhere = "visited_at >= DATE_SUB($vRangeStart, INTERVAL $rangeDays DAY) AND visited_at < $vRangeStart";
    }
    $vTrendN     = ['today'=>7,'7d'=>7,'30d'=>30,'90d'=>12,'1y'=>12][$range];
    $vTrendGroup = ($range === '90d' || $range === '1y') ? 'week' : 'day';
}

$vTodayUniq = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) FROM visitor_log WHERE $vWhere AND session_id<>''")->fetchColumn();
$vTodayHits = (int)$pdo->query("SELECT COUNT(*) FROM visitor_log WHERE $vWhere")->fetchColumn();
$vYestUniq  = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) FROM visitor_log WHERE $vPrevWhere AND session_id<>''")->fetchColumn();
$vDelta     = $vYestUniq > 0 ? round((($vTodayUniq - $vYestUniq) / $vYestUniq) * 100) : ($vTodayUniq > 0 ? 100 : 0);

$vOs = $pdo->query("SELECT os, COUNT(DISTINCT session_id) c FROM visitor_log WHERE $vWhere AND session_id<>'' GROUP BY os ORDER BY c DESC LIMIT 8")->fetchAll();
$vDev = $pdo->query("SELECT device, COUNT(DISTINCT session_id) c FROM visitor_log WHERE $vWhere AND session_id<>'' GROUP BY device ORDER BY c DESC")->fetchAll();
$vCountry = $pdo->query("SELECT country, COUNT(DISTINCT session_id) c FROM visitor_log WHERE $vWhere AND session_id<>'' AND country<>'' GROUP BY country ORDER BY c DESC LIMIT 8")->fetchAll();

if ($vTrendGroup === 'day') {
    $vTrendRows = $pdo->query("SELECT DATE(visited_at) d, COUNT(DISTINCT session_id) c
                                FROM visitor_log
                                WHERE visited_at >= DATE_SUB(CURDATE(), INTERVAL ".($vTrendN-1)." DAY) AND session_id<>''
                                GROUP BY DATE(visited_at)")->fetchAll();
    $vTrendMap = []; foreach ($vTrendRows as $r) $vTrendMap[$r['d']] = (int)$r['c'];
    $vTrend = [];
    for ($i=$vTrendN-1; $i>=0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $vTrend[] = ['d'=>$d, 'lbl'=>date('D', strtotime($d)), 'c'=>(int)($vTrendMap[$d] ?? 0)]; }
} else {
    $vTrendRows = $pdo->query("SELECT YEARWEEK(visited_at, 3) yw, MIN(DATE(visited_at)) d, COUNT(DISTINCT session_id) c
                                FROM visitor_log
                                WHERE visited_at >= DATE_SUB(CURDATE(), INTERVAL ".(7*$vTrendN-1)." DAY) AND session_id<>''
                                GROUP BY YEARWEEK(visited_at, 3)
                                ORDER BY yw ASC")->fetchAll();
    $vTrend = [];
    foreach ($vTrendRows as $r) $vTrend[] = ['d'=>$r['d'], 'lbl'=>'W'.((int)substr($r['yw'],-2)), 'c'=>(int)$r['c']];
    while (count($vTrend) < $vTrendN) array_unshift($vTrend, ['d'=>'', 'lbl'=>'', 'c'=>0]);
}
$vTrendMax = max(array_column($vTrend,'c')) ?: 1;

$osIcons = [
    'Windows 10/11'=>['bi-windows','#0078D4'], 'Windows 8.1'=>['bi-windows','#0078D4'],
    'Windows 8'=>['bi-windows','#0078D4'], 'Windows 7'=>['bi-windows','#0078D4'],
    'Windows'=>['bi-windows','#0078D4'],
    'macOS'=>['bi-apple','#1d1d1f'], 'iOS'=>['bi-apple','#1d1d1f'],
    'Android'=>['bi-android2','#3DDC84'], 'Linux'=>['bi-ubuntu','#E95420'],
    'Chrome OS'=>['bi-google','#FBBC05'], 'Unknown'=>['bi-question-circle','#9ca3af'],
];
$devIcons = ['Desktop'=>['bi-display','#3b82f6'], 'Mobile'=>['bi-phone','#10b981'], 'Tablet'=>['bi-tablet','#f59e0b']];
?>
<div class="card-head" data-vrange-header>
  <div class="ttl"><i class="bi bi-people-fill"></i> Visitors <span class="sub ms-2"><?= esc($vRangeLabel) ?> · real humans · bots filtered</span></div>
  <span class="badge bg-success-subtle text-success" style="font-size:11px;"><i class="bi bi-eye-fill me-1"></i><?= number_format($vTodayHits) ?> page-views</span>
</div>
<div class="card-body-p">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="vis-headline">
        <div class="vis-num" data-testid="visitors-today-unique"><?= number_format($vTodayUniq) ?></div>
        <div class="vis-lbl">unique visitors · <?= esc($vRangeLabel) ?></div>
        <div class="vis-delta <?= $vDelta>=0?'up':'down' ?>">
          <i class="bi bi-arrow-<?= $vDelta>=0?'up':'down' ?>-right"></i>
          <?= $vDelta>=0?'+':'' ?><?= $vDelta ?>%
          <span class="text-muted ms-1">vs previous (<?= number_format($vYestUniq) ?>)</span>
        </div>
        <div class="vis-spark">
          <?php foreach ($vTrend as $tt):
            $h = max(8, ($tt['c']/$vTrendMax)*100);
            $isCurrent = $tt['d'] === date('Y-m-d');
          ?>
            <div class="vis-spark-bar <?= $isCurrent?'today':'' ?>" style="height:<?= $h ?>%;" title="<?= esc($tt['d']) ?>: <?= $tt['c'] ?> visitors">
              <span class="vis-spark-val"><?= $tt['c']>0 ? $tt['c'] : '' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="vis-spark-x">
          <?php foreach ($vTrend as $tt): ?><span><?= esc($tt['lbl'] ?? '') ?></span><?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4 col-md-6">
      <div class="vis-block" data-testid="visitors-os-block">
        <div class="vis-block-ttl"><i class="bi bi-display me-1"></i> Operating System</div>
        <?php if (empty($vOs)): ?>
          <div class="text-muted small py-3 text-center">No visitors in this range.</div>
        <?php else: foreach ($vOs as $row):
          $pct = $vTodayUniq>0 ? round(((int)$row['c']/$vTodayUniq)*100) : 0;
          $ic = $osIcons[$row['os']] ?? ['bi-pc-display','#6b7280'];
        ?>
          <div class="vis-row">
            <i class="bi <?= esc($ic[0]) ?>" style="color:<?= esc($ic[1]) ?>;font-size:16px;"></i>
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex justify-content-between">
                <span class="small fw-semibold text-truncate"><?= esc($row['os']) ?></span>
                <span class="small text-muted"><?= (int)$row['c'] ?> · <?= $pct ?>%</span>
              </div>
              <div class="vis-bar"><span style="width:<?= $pct ?>%;background:<?= esc($ic[1]) ?>;"></span></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="col-lg-4 col-md-6">
      <div class="vis-block" data-testid="visitors-device-block">
        <div class="vis-block-ttl"><i class="bi bi-phone me-1"></i> Device</div>
        <?php if (empty($vDev)): ?>
          <div class="text-muted small py-2">—</div>
        <?php else: foreach ($vDev as $row):
          $pct = $vTodayUniq>0 ? round(((int)$row['c']/$vTodayUniq)*100) : 0;
          $ic = $devIcons[$row['device']] ?? ['bi-question-circle','#9ca3af'];
        ?>
          <div class="vis-row">
            <i class="bi <?= esc($ic[0]) ?>" style="color:<?= esc($ic[1]) ?>;font-size:16px;"></i>
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex justify-content-between">
                <span class="small fw-semibold"><?= esc($row['device']) ?></span>
                <span class="small text-muted"><?= (int)$row['c'] ?> · <?= $pct ?>%</span>
              </div>
              <div class="vis-bar"><span style="width:<?= $pct ?>%;background:<?= esc($ic[1]) ?>;"></span></div>
            </div>
          </div>
        <?php endforeach; endif; ?>

        <?php if (!empty($vCountry)): ?>
        <div class="vis-block-ttl mt-3"><i class="bi bi-globe2 me-1"></i> Top Countries</div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($vCountry as $c): ?>
            <span class="vis-chip"><?= esc($c['country'] ?: 'XX') ?> <strong><?= (int)$c['c'] ?></strong></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
