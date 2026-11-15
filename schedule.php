<?php
/**
 * JetLog: Live Maintenance Schedule Management
 * Reference: schedule.html & Page 2 Upcoming Maintenance
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'schedule';
$pdo = getDbConnection();
$flash = getFlashMessage();

// Handle Schedule Task POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_schedule') {
    $aircraftId = (int)($_POST['aircraft_id'] ?? 0);
    $type = trim($_POST['maintenance_type'] ?? '');
    $schedDate = $_POST['scheduled_date'] ?? date('Y-m-d');
    $dueDate = $_POST['due_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');

    if ($aircraftId <= 0 || empty($type) || empty($schedDate)) {
        setFlashMessage('error', 'Please select an aircraft, maintenance type, and scheduled date.');
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO maintenance_schedules 
                (aircraft_id, maintenance_type, scheduled_date, due_date, status, notes)
                VALUES (:aid, :type, :sdate, :ddate, "Upcoming", :notes)
            ');
            $stmt->execute([
                ':aid' => $aircraftId,
                ':type' => $type,
                ':sdate' => $schedDate,
                ':ddate' => $dueDate ?: null,
                ':notes' => $notes ?: null
            ]);
            setFlashMessage('success', "Maintenance task '{$type}' successfully scheduled.");
            header('Location: schedule.php');
            exit;
        } catch (Exception $e) {
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }
    }
}

// Handle Mark Completed / Change Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
    $schedId = (int)($_POST['schedule_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? 'Completed';
    $stmt = $pdo->prepare('UPDATE maintenance_schedules SET status = :status WHERE schedule_id = :id');
    $stmt->execute([':status' => $newStatus, ':id' => $schedId]);
    setFlashMessage('success', "Task status updated to {$newStatus}.");
    header('Location: schedule.php');
    exit;
}

// Handle Delete Scheduled Task POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_schedule') {
    $schedId = (int)($_POST['schedule_id'] ?? 0);
    if ($schedId > 0) {
        $delStmt = $pdo->prepare('DELETE FROM maintenance_schedules WHERE schedule_id = :id');
        $delStmt->execute([':id' => $schedId]);
        setFlashMessage('success', "Scheduled task #{$schedId} has been successfully deleted from the database.");
    }
    header('Location: schedule.php');
    exit;
}

// Read filters
$filterAircraft = trim($_GET['aircraft'] ?? 'All Aircraft');
$filterStatus = trim($_GET['status'] ?? 'All Statuses');
$filterMonth = trim($_GET['month'] ?? 'All Months');

// Build query
$sql = '
    SELECT 
        ms.*,
        a.registration_number AS aircraft_registration,
        a.model AS aircraft_model
    FROM maintenance_schedules ms
    JOIN aircraft a ON ms.aircraft_id = a.aircraft_id
    WHERE 1=1
';
$params = [];

if (!empty($filterAircraft) && $filterAircraft !== 'All Aircraft') {
    $sql .= ' AND a.registration_number = :reg';
    $params[':reg'] = $filterAircraft;
}

if (!empty($filterStatus) && $filterStatus !== 'All Statuses') {
    $sql .= ' AND ms.status = :status';
    $params[':status'] = $filterStatus;
}

if (!empty($filterMonth) && $filterMonth !== 'All Months') {
    $sql .= ' AND DATE_FORMAT(ms.scheduled_date, "%Y-%m") = :ym';
    $params[':ym'] = $filterMonth;
}

$sql .= ' ORDER BY ms.scheduled_date ASC, ms.schedule_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

// Fetch aircraft for dropdowns
$acList = $pdo->query('SELECT aircraft_id, registration_number, model FROM aircraft ORDER BY registration_number ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Maintenance and Inspection Schedule</title>
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
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.7);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .modal-box {
    background: #fff;
    border-radius: 8px;
    max-width: 500px;
    width: 100%;
    padding: 24px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
  }
</style>
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
        <h1>Maintenance and Inspection Schedule</h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Maintenance Schedule
        </div>
      </div>
      <div>
        <button class="btn" onclick="openScheduleModal()" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
          + Schedule Maintenance Task
        </button>
      </div>
    </div>

    <!-- Schedule Filters Form -->
    <div class="panel">
      <h2>Schedule Filters</h2>
      <form method="GET" action="schedule.php" class="filter-bar">
        <div class="filter-item">
          <label for="month">Month</label>
          <select id="month" name="month">
            <option value="All Months" <?= $filterMonth === 'All Months' ? 'selected' : '' ?>>All Months</option>
            <option value="2026-10" <?= $filterMonth === '2026-10' ? 'selected' : '' ?>>October 2026</option>
            <option value="2026-11" <?= $filterMonth === '2026-11' ? 'selected' : '' ?>>November 2026</option>
            <option value="2026-12" <?= $filterMonth === '2026-12' ? 'selected' : '' ?>>December 2026</option>
          </select>
        </div>
        <div class="filter-item">
          <label for="aircraft">Aircraft</label>
          <select id="aircraft" name="aircraft">
            <option value="All Aircraft">All Aircraft</option>
            <?php foreach ($acList as $ac): ?>
              <option value="<?= htmlspecialchars($ac['registration_number']) ?>" <?= ($filterAircraft === $ac['registration_number']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($ac['registration_number']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-item">
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="All Statuses" <?= $filterStatus === 'All Statuses' ? 'selected' : '' ?>>All Statuses</option>
            <option value="Upcoming" <?= $filterStatus === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
            <option value="Due Soon" <?= $filterStatus === 'Due Soon' ? 'selected' : '' ?>>Due Soon</option>
            <option value="Overdue" <?= $filterStatus === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
          </select>
        </div>
        <div class="filter-item" style="display:flex; gap:6px; align-items:flex-end;">
          <button type="submit" class="btn small" style="cursor:pointer;">Apply</button>
          <?php if ($filterAircraft !== 'All Aircraft' || $filterStatus !== 'All Statuses' || $filterMonth !== 'All Months'): ?>
            <a href="schedule.php" class="btn small btn-secondary">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Scheduled Maintenance Table (Live SQL Data) -->
    <div class="panel">
      <h2>Scheduled Maintenance Tasks (<?= count($schedules) ?> Found)</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Aircraft</th>
            <th>Maintenance Type</th>
            <th>Scheduled Date</th>
            <th>Status</th>
            <th>Notes</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($schedules)): ?>
            <?php foreach ($schedules as $sched): ?>
              <tr>
                <td>
                  <a href="aircraft-profile.php?reg=<?= urlencode($sched['aircraft_registration']) ?>" style="font-weight:600; color:#0284c7;">
                    <?= htmlspecialchars($sched['aircraft_registration']) ?> (<?= htmlspecialchars($sched['aircraft_model']) ?>)
                  </a>
                </td>
                <td><strong><?= htmlspecialchars($sched['maintenance_type']) ?></strong></td>
                <td><?= date('M d, Y', strtotime($sched['scheduled_date'])) ?></td>
                <td>
                  <?php
                    $scClass = match($sched['status']) {
                      'Overdue' => 'status-overdue',
                      'Due Soon' => 'status-due-soon',
                      'Completed' => 'status-completed',
                      default => 'status-upcoming',
                    };
                  ?>
                  <span class="status <?= $scClass ?>"><?= htmlspecialchars($sched['status']) ?></span>
                </td>
                <td><?= htmlspecialchars($sched['notes'] ?? 'Standard interval check') ?></td>
                <td style="white-space:nowrap;">
                  <?php if ($sched['status'] !== 'Completed'): ?>
                    <form method="POST" action="schedule.php" style="display:inline;">
                      <input type="hidden" name="action" value="update_task_status">
                      <input type="hidden" name="schedule_id" value="<?= (int)$sched['schedule_id'] ?>">
                      <input type="hidden" name="new_status" value="Completed">
                      <button type="submit" class="btn small" style="background:#16a34a; color:#fff; border-color:#16a34a; font-size:11px; padding:3px 6px; cursor:pointer;" title="Mark task complete">
                        &check; Complete
                      </button>
                    </form>
                  <?php else: ?>
                    <span style="color:#16a34a; font-size:12px; margin-right:4px;">Completed</span>
                  <?php endif; ?>
                  
                  <form method="POST" action="schedule.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this scheduled maintenance task for <?= htmlspecialchars($sched['aircraft_registration']) ?>?');">
                    <input type="hidden" name="action" value="delete_schedule">
                    <input type="hidden" name="schedule_id" value="<?= (int)$sched['schedule_id'] ?>">
                    <button type="submit" class="btn small" style="background:#ef4444; color:#fff; border-color:#ef4444; font-size:11px; padding:3px 6px; cursor:pointer;" title="Permanently delete from database">
                      &times; Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" style="text-align:center; color:#64748b; padding:20px;">No scheduled maintenance tasks found for current criteria.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      
      <div class="panel-actions">
        <button class="btn" onclick="openScheduleModal()" style="cursor:pointer;">+ Schedule Maintenance Task</button>
      </div>
    </div>

    <div class="footer-note">
      JetLog Maintenance Schedule &bull; Live queries from table <code>maintenance_schedules</code>.
    </div>

  </main>

</div>

<!-- Modal: Schedule Task -->
<div class="modal-overlay" id="scheduleModal">
  <div class="modal-box">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:14px;">
      <h3 style="margin:0; color:#0f172a;">Schedule Maintenance Task</h3>
      <button type="button" class="modal-close" onclick="closeScheduleModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="schedule.php">
      <input type="hidden" name="action" value="add_schedule">

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_sched_ac">Aircraft *</label>
        <select id="modal_sched_ac" name="aircraft_id" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
          <option value="">-- Select Aircraft --</option>
          <?php foreach ($acList as $ac): ?>
            <option value="<?= (int)$ac['aircraft_id'] ?>">
              <?= htmlspecialchars($ac['registration_number']) ?> &ndash; <?= htmlspecialchars($ac['model']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_sched_type">Maintenance / Inspection Type *</label>
        <input type="text" id="modal_sched_type" name="maintenance_type" placeholder="e.g. 100-Hour Routine Inspection" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div class="form-row">
          <label for="modal_sdate">Scheduled Date *</label>
          <input type="date" id="modal_sdate" name="scheduled_date" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
        <div class="form-row">
          <label for="modal_ddate">Due Date</label>
          <input type="date" id="modal_ddate" name="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
      </div>

      <div class="form-row" style="margin-bottom:16px;">
        <label for="modal_notes">Task Notes / Instructions</label>
        <textarea id="modal_notes" name="notes" rows="3" placeholder="Reference AMM / inspection card item..." style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeScheduleModal()">Cancel</button>
        <button type="submit" class="btn small">Save Task</button>
      </div>
    </form>
  </div>
</div>

<script>
function openScheduleModal() {
  document.getElementById('scheduleModal').style.display = 'flex';
}
function closeScheduleModal() {
  document.getElementById('scheduleModal').style.display = 'none';
}
</script>

</body>
</html>
