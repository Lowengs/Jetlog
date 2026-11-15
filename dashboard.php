<?php
/**
 * JetLog: Live Database-Driven Dashboard
 * Reference: Page 2 of System Specification Document
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'dashboard';
$flash = getFlashMessage();

// Fetch live metrics from database
$metrics = getDashboardMetrics();
$recentWorkOrders = getRecentWorkOrders(4);
$upcomingMaintenance = getUpcomingMaintenance(3);

// Calculate fleet percentages
$totalAircraft = max(1, (int)$metrics['total_aircraft']);
$pctAvailable = round(((int)$metrics['available_aircraft'] / $totalAircraft) * 100);
$pctMaint = round(((int)$metrics['maintenance_aircraft'] / $totalAircraft) * 100);
$pctInsp = round(((int)$metrics['inspection_due_aircraft'] / $totalAircraft) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Dashboard</title>
<link rel="stylesheet" href="styles.css">
<style>
  .flash-banner {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 18px;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .flash-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
  .flash-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
  .status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
  }
  .priority-high { color: #b91c1c; font-weight: bold; }
  .priority-critical { color: #7f1d1d; background: #fee2e2; padding: 2px 6px; border-radius: 3px; font-weight: bold; }
  .priority-medium { color: #d97706; font-weight: 600; }
  .priority-low { color: #475569; }
</style>
</head>
<body>

<div class="layout">

  <!-- Shared Sidebar with Live User Profile -->
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
        <h1>Dashboard</h1>
        <div class="breadcrumb">Login &gt; Dashboard</div>
      </div>
      <div style="font-size: 12px; color: #64748b;">
        Live Database Sync: <?= date('M d, Y - H:i:s') ?>
      </div>
    </div>

    <!-- 6 Live Stat Cards -->
    <div class="card-grid">
      <a class="stat-card" href="aircraft.php">
        <div class="stat-label">Total Aircraft</div>
        <div class="stat-value"><?= htmlspecialchars($metrics['total_aircraft']) ?></div>
        <div class="stat-hint">Open registry &rarr;</div>
      </a>
      <a class="stat-card" href="aircraft.php?status=Available">
        <div class="stat-label">Available Aircraft</div>
        <div class="stat-value"><?= htmlspecialchars($metrics['available_aircraft']) ?></div>
        <div class="stat-hint">In service &rarr;</div>
      </a>
      <a class="stat-card" href="aircraft.php?status=Under+Maintenance">
        <div class="stat-label">Aircraft Under Maintenance</div>
        <div class="stat-value"><?= htmlspecialchars($metrics['maintenance_aircraft']) ?></div>
        <div class="stat-hint">In hangar &rarr;</div>
      </a>
      <a class="stat-card" href="work-orders.php?status=Active">
        <div class="stat-label">Open Work Orders</div>
        <div class="stat-value"><?= htmlspecialchars($metrics['open_work_orders']) ?></div>
        <div class="stat-hint">Not yet completed &rarr;</div>
      </a>
      <a class="stat-card" href="schedule.php?status=Overdue">
        <div class="stat-label">Overdue Tasks</div>
        <div class="stat-value" style="color:#b91c1c;"><?= htmlspecialchars($metrics['overdue_tasks']) ?></div>
        <div class="stat-hint" style="color:#b91c1c;">Needs attention &rarr;</div>
      </a>
      <a class="stat-card" href="schedule.php?status=Upcoming">
        <div class="stat-label">Upcoming Inspections</div>
        <div class="stat-value"><?= htmlspecialchars($metrics['upcoming_inspections']) ?></div>
        <div class="stat-hint">Next 30 days &rarr;</div>
      </a>
    </div>

    <!-- Quick Actions Panel: All Buttons Functional -->
    <div class="panel">
      <h2>Quick Actions</h2>
      <div class="button-row">
        <a class="btn small" href="discrepancy.php">Report Discrepancy</a>
        <a class="btn small" href="work-orders.php?action=new">Create Work Order</a>
        <a class="btn small" href="logbook.php">Open Logbook</a>
        <a class="btn small" href="reports.php">View Reports</a>
      </div>
    </div>

    <!-- Recent Work Orders (Live SQL Query) -->
    <div class="panel">
      <h2>Recent Work Orders</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Work Order</th>
            <th>Aircraft</th>
            <th>Issue</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($recentWorkOrders)): ?>
            <?php foreach ($recentWorkOrders as $wo): ?>
              <tr>
                <td><strong><?= htmlspecialchars($wo['work_order_number']) ?></strong></td>
                <td>
                  <a href="aircraft-profile.php?reg=<?= urlencode($wo['aircraft_registration']) ?>" style="font-weight:600; color:#0284c7;">
                    <?= htmlspecialchars($wo['aircraft_registration']) ?>
                  </a>
                </td>
                <td><?= htmlspecialchars($wo['issue_summary']) ?></td>
                <td>
                  <span class="priority-<?= strtolower(htmlspecialchars($wo['priority'])) ?>">
                    <?= htmlspecialchars($wo['priority']) ?>
                  </span>
                </td>
                <td>
                  <?php
                    $statusClass = match($wo['status']) {
                      'In Progress' => 'status-progress',
                      'For Verification' => 'status-verification',
                      'Assigned' => 'status-assigned',
                      'Completed' => 'status-completed',
                      default => 'status-open',
                    };
                  ?>
                  <span class="status <?= $statusClass ?>"><?= htmlspecialchars($wo['status']) ?></span>
                </td>
                <td>
                  <a href="work-order-details.php?id=<?= (int)$wo['work_order_id'] ?>" style="color:#0284c7; font-weight:600;">View &rarr;</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" style="text-align:center; color:#64748b; padding:16px;">No recent work orders found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="panel-actions">
        <a class="btn" href="work-orders.php">View All Work Orders</a>
      </div>
    </div>

    <!-- Upcoming Maintenance (Live SQL Query) -->
    <div class="panel">
      <h2>Upcoming Maintenance</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Aircraft</th>
            <th>Maintenance Type</th>
            <th>Scheduled Date</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($upcomingMaintenance)): ?>
            <?php foreach ($upcomingMaintenance as $task): ?>
              <tr>
                <td>
                  <a href="aircraft-profile.php?reg=<?= urlencode($task['aircraft_registration']) ?>" style="font-weight:600; color:#0284c7;">
                    <?= htmlspecialchars($task['aircraft_registration']) ?>
                  </a>
                </td>
                <td><?= htmlspecialchars($task['maintenance_type']) ?></td>
                <td><?= date('M d, Y', strtotime($task['scheduled_date'])) ?></td>
                <td>
                  <?php
                    $schedClass = match($task['status']) {
                      'Overdue' => 'status-overdue',
                      'Due Soon' => 'status-due-soon',
                      default => 'status-upcoming',
                    };
                  ?>
                  <span class="status <?= $schedClass ?>"><?= htmlspecialchars($task['status']) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" style="text-align:center; color:#64748b; padding:16px;">No upcoming maintenance scheduled.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="panel-actions">
        <a class="btn" href="schedule.php">View Full Schedule</a>
      </div>
    </div>

    <!-- Aircraft Status Overview (Live Calculated Bars) -->
    <div class="panel">
      <h2>Aircraft Status Overview</h2>

      <div class="bar-row">
        <div class="bar-label">Available</div>
        <div class="bar-track"><div class="bar-fill" style="width: <?= $pctAvailable ?>%; background: #22c55e;"></div></div>
        <div class="bar-value"><?= (int)$metrics['available_aircraft'] ?> of <?= $totalAircraft ?> (<?= $pctAvailable ?>%)</div>
      </div>
      <div class="bar-row">
        <div class="bar-label">Under Maintenance</div>
        <div class="bar-track"><div class="bar-fill" style="width: <?= $pctMaint ?>%; background: #eab308;"></div></div>
        <div class="bar-value"><?= (int)$metrics['maintenance_aircraft'] ?> of <?= $totalAircraft ?> (<?= $pctMaint ?>%)</div>
      </div>
      <div class="bar-row">
        <div class="bar-label">Inspection Due</div>
        <div class="bar-track"><div class="bar-fill" style="width: <?= $pctInsp ?>%; background: #ef4444;"></div></div>
        <div class="bar-value"><?= (int)$metrics['inspection_due_aircraft'] ?> of <?= $totalAircraft ?> (<?= $pctInsp ?>%)</div>
      </div>

      <div class="panel-actions">
        <a class="btn" href="aircraft.php">Open Aircraft Registry</a>
      </div>
    </div>

    <div class="footer-note">
      JetLog Aircraft Maintenance Tracking System &bull; Active Database: <code><?= htmlspecialchars(DB_NAME) ?></code> &bull; All changes saved in real time.
    </div>

  </main>

</div>

</body>
</html>
