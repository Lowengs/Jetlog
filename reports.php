<?php
/**
 * JetLog: Live Reporting and Analytics Dashboard
 * Reference: reports.html
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'reports';
$pdo = getDbConnection();
$flash = getFlashMessage();

// 1. Fetch Top KPI Metrics
$kpiStmt = $pdo->query('
    SELECT 
        (SELECT COUNT(*) FROM work_orders) AS total_wo,
        (SELECT COUNT(*) FROM work_orders WHERE status = "Completed") AS completed_wo,
        (SELECT COUNT(*) FROM work_orders WHERE status != "Completed" AND status != "Cancelled") AS open_wo,
        (SELECT COUNT(*) FROM maintenance_schedules WHERE status = "Overdue") AS overdue_tasks,
        (SELECT COUNT(*) FROM aircraft) AS total_aircraft
');
$kpi = $kpiStmt->fetch();

$totalWo = max(1, (int)$kpi['total_wo']);
$totalAircraft = max(1, (int)$kpi['total_aircraft']);

// 2. Aircraft Status Distribution
$acDistStmt = $pdo->query('
    SELECT status, COUNT(*) AS cnt 
    FROM aircraft 
    GROUP BY status 
    ORDER BY FIELD(status, "Available", "Under Maintenance", "Inspection Due")
');
$acDist = $acDistStmt->fetchAll();

// 3. Work Orders by Status
$woStatusStmt = $pdo->query('SELECT * FROM vw_work_orders_by_status');
$woStatusData = $woStatusStmt->fetchAll();

// 4. Monthly Maintenance Activities
$monthlyStmt = $pdo->query('SELECT * FROM vw_monthly_maintenance_activities');
$monthlyActivities = $monthlyStmt->fetchAll();

$maxMonthly = 1;
foreach ($monthlyActivities as $m) {
    if ((int)$m['activity_count'] > $maxMonthly) {
        $maxMonthly = (int)$m['activity_count'];
    }
}

// 5. Common Discrepancy Categories
$catStmt = $pdo->query('SELECT * FROM vw_discrepancies_by_category');
$catData = $catStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Reporting and Analytics</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>

<div class="layout">

  <!-- Sidebar -->
  <?php include __DIR__ . '/navbar.php'; ?>

  <main class="main">

    <?php if ($flash): ?>
      <div class="flash-banner flash-<?= htmlspecialchars($flash['type']) ?>">
        <span><?= htmlspecialchars($flash['text']) ?></span>
        <button type="button" onclick="this.parentElement.style.display='none'" class="flash-close" title="Dismiss">&times;</button>
      </div>
    <?php endif; ?>

    <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-end;">
      <div>
        <h1>Reporting and Analytics Dashboard</h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Reports and Analytics
        </div>
      </div>
      <div style="font-size:12px; color:#64748b;">
        Real-Time Fleet Intelligence &bull; Synced with Database
      </div>
    </div>

    <!-- 4 Live Stat Cards -->
    <div class="card-grid">
      <a class="stat-card" href="work-orders.php">
        <div class="stat-label">Total Work Orders</div>
        <div class="stat-value"><?= (int)$kpi['total_wo'] ?></div>
        <div class="stat-hint">All time &rarr;</div>
      </a>
      <a class="stat-card" href="work-orders.php?status=Completed">
        <div class="stat-label">Completed Work Orders</div>
        <div class="stat-value" style="color:#16a34a;"><?= (int)$kpi['completed_wo'] ?></div>
        <div class="stat-hint">Signed off &rarr;</div>
      </a>
      <a class="stat-card" href="work-orders.php?status=Active">
        <div class="stat-label">Open Work Orders</div>
        <div class="stat-value" style="color:#0284c7;"><?= (int)$kpi['open_wo'] ?></div>
        <div class="stat-hint">Active hangar tasks &rarr;</div>
      </a>
      <a class="stat-card" href="schedule.php?status=Overdue">
        <div class="stat-label">Overdue Tasks</div>
        <div class="stat-value" style="color:#dc2626;"><?= (int)$kpi['overdue_tasks'] ?></div>
        <div class="stat-hint">Requires action &rarr;</div>
      </a>
    </div>

    <!-- Panel 1: Aircraft Status -->
    <div class="panel">
      <h2>Fleet Aircraft Status Distribution</h2>
      <?php foreach ($acDist as $row): ?>
        <?php 
          $cnt = (int)$row['cnt'];
          $pct = round(($cnt / $totalAircraft) * 100);
          $fillColor = match($row['status']) {
            'Available' => '#22c55e',
            'Under Maintenance' => '#eab308',
            'Inspection Due' => '#ef4444',
            default => '#64748b'
          };
        ?>
        <div class="bar-row">
          <div class="bar-label"><?= htmlspecialchars($row['status']) ?></div>
          <div class="bar-track"><div class="bar-fill" style="width: <?= $pct ?>%; background: <?= $fillColor ?>;"></div></div>
          <div class="bar-value"><?= $cnt ?> (<?= $pct ?>%)</div>
        </div>
      <?php endforeach; ?>
      <div class="panel-note">Live fleet breakdown based on <?= $totalAircraft ?> total aircraft in database.</div>
    </div>

    <!-- Panel 2: Work Orders by Status -->
    <div class="panel">
      <h2>Work Orders by Status</h2>
      <?php foreach ($woStatusData as $st): ?>
        <?php 
          $pct = (float)$st['percentage'];
          $fillColor = match($st['status']) {
            'Completed' => '#16a34a',
            'In Progress' => '#0284c7',
            'For Verification' => '#8b5cf6',
            'Assigned' => '#f59e0b',
            default => '#64748b'
          };
        ?>
        <div class="bar-row">
          <div class="bar-label"><?= htmlspecialchars($st['status']) ?></div>
          <div class="bar-track"><div class="bar-fill" style="width: <?= max(2, $pct) ?>%; background: <?= $fillColor ?>;"></div></div>
          <div class="bar-value"><?= (int)$st['total_count'] ?> (<?= $pct ?>%)</div>
        </div>
      <?php endforeach; ?>
      <div class="panel-note">Calculated across <?= (int)$kpi['total_wo'] ?> total work orders.</div>
    </div>

    <!-- Panel 3: Maintenance Activities by Month -->
    <div class="panel">
      <h2>Maintenance Activities by Month</h2>
      <?php if (!empty($monthlyActivities)): ?>
        <?php foreach ($monthlyActivities as $m): ?>
          <?php 
            $cnt = (int)$m['activity_count'];
            $relPct = round(($cnt / $maxMonthly) * 100);
          ?>
          <div class="bar-row">
            <div class="bar-label"><?= htmlspecialchars($m['month_label']) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= max(5, $relPct) ?>%; background: #0284c7;"></div></div>
            <div class="bar-value"><?= $cnt ?> activities</div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:#64748b; font-size:13px;">No monthly activity data recorded yet.</p>
      <?php endif; ?>
      <div class="panel-note">Aggregated dynamically from table <code>maintenance_logbook</code>.</div>
    </div>

    <!-- Panel 4: Common Discrepancy Categories -->
    <div class="panel">
      <h2>Common Discrepancy Categories</h2>
      <?php foreach ($catData as $cat): ?>
        <?php 
          $cnt = (int)$cat['total_count'];
          $pct = (float)$cat['percentage'];
        ?>
        <div class="bar-row">
          <div class="bar-label"><?= htmlspecialchars($cat['category_name']) ?></div>
          <div class="bar-track"><div class="bar-fill" style="width: <?= max(3, $pct) ?>%; background: #6366f1;"></div></div>
          <div class="bar-value"><?= $cnt ?> (<?= $pct ?>%)</div>
        </div>
      <?php endforeach; ?>
      <div class="panel-note">Discrepancy category distribution queried from <code>vw_discrepancies_by_category</code>.</div>
    </div>

    <div class="footer-note">
      JetLog Analytics System &bull; Live queries directly computing fleet metrics from MySQL.
    </div>

  </main>

</div>

</body>
</html>
