<?php
/**
 * JetLog: Digital Maintenance Logbook
 * Reference: logbook.html & Page 4 Recent Maintenance History
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'logbook';
$pdo = getDbConnection();
$flash = getFlashMessage();

// Handle Add Logbook Entry POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_entry') {
    $aircraftId = (int)($_POST['aircraft_id'] ?? 0);
    $woId = !empty($_POST['work_order_id']) ? (int)$_POST['work_order_id'] : null;
    $activity = $_POST['activity_type'] ?? 'Inspection';
    $logDate = $_POST['log_date'] ?? date('Y-m-d');
    $resultSummary = trim($_POST['result_summary'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $hours = !empty($_POST['flight_hours_at_log']) ? (float)$_POST['flight_hours_at_log'] : null;

    if ($aircraftId <= 0 || empty($resultSummary)) {
        setFlashMessage('error', 'Please select an aircraft and provide an entry result summary.');
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO maintenance_logbook 
                (aircraft_id, work_order_id, activity_type, technician_id, log_date, result_summary, remarks, flight_hours_at_log)
                VALUES (:aid, :wid, :act, :tid, :dt, :res, :rem, :hrs)
            ');
            $stmt->execute([
                ':aid' => $aircraftId,
                ':wid' => $woId,
                ':act' => $activity,
                ':tid' => !empty($currentUser['user_id']) ? $currentUser['user_id'] : 1,
                ':dt' => $logDate,
                ':res' => $resultSummary,
                ':rem' => $remarks ?: null,
                ':hrs' => $hours
            ]);
            setFlashMessage('success', 'Official logbook entry recorded and signed off.');
            header('Location: logbook.php');
            exit;
        } catch (Exception $e) {
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }
    }
}

// Handle Delete Logbook Entry POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_entry') {
    $logId = (int)($_POST['log_id'] ?? 0);
    if ($logId > 0) {
        $delStmt = $pdo->prepare('DELETE FROM maintenance_logbook WHERE log_id = :id');
        $delStmt->execute([':id' => $logId]);
        setFlashMessage('success', "Logbook entry #{$logId} has been successfully deleted from the database.");
    }
    header('Location: logbook.php');
    exit;
}

// Read filter parameters
$searchQuery = trim($_GET['search'] ?? '');
$filterAircraft = trim($_GET['aircraft'] ?? 'All Aircraft');
$filterActivity = trim($_GET['activity'] ?? 'All Activities');

// Build SQL
$sql = '
    SELECT 
        ml.*,
        a.registration_number AS aircraft_registration,
        a.model AS aircraft_model,
        wo.work_order_number,
        CONCAT(u.first_name, " ", u.last_name) AS technician_name
    FROM maintenance_logbook ml
    JOIN aircraft a ON ml.aircraft_id = a.aircraft_id
    JOIN users u ON ml.technician_id = u.user_id
    LEFT JOIN work_orders wo ON ml.work_order_id = wo.work_order_id
    WHERE 1=1
';
$params = [];

if (!empty($searchQuery)) {
    $sql .= ' AND (a.registration_number LIKE :s_reg OR ml.result_summary LIKE :s_res OR wo.work_order_number LIKE :s_wo)';
    $params[':s_reg'] = '%' . $searchQuery . '%';
    $params[':s_res'] = '%' . $searchQuery . '%';
    $params[':s_wo'] = '%' . $searchQuery . '%';
}

if (!empty($filterAircraft) && $filterAircraft !== 'All Aircraft') {
    $sql .= ' AND a.registration_number = :reg';
    $params[':reg'] = $filterAircraft;
}

if (!empty($filterActivity) && $filterActivity !== 'All Activities') {
    $sql .= ' AND ml.activity_type = :act';
    $params[':act'] = $filterActivity;
}

$sql .= ' ORDER BY ml.log_date DESC, ml.log_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Aircraft list for filters & modal
$acList = $pdo->query('SELECT aircraft_id, registration_number, model FROM aircraft ORDER BY registration_number ASC')->fetchAll();

// Recent work orders for modal linkage
$woList = $pdo->query('SELECT work_order_id, work_order_number, issue_summary FROM work_orders ORDER BY date_created DESC LIMIT 25')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Digital Maintenance Logbook</title>
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
    max-width: 520px;
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
        <h1>Digital Maintenance Logbook</h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Maintenance Logbook
        </div>
      </div>
      <div>
        <button class="btn" onclick="openLogModal()" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
          + Add Logbook Entry
        </button>
      </div>
    </div>

    <!-- Search & Filters -->
    <div class="panel">
      <h2>Logbook Search</h2>
      <form method="GET" action="logbook.php" class="filter-bar">
        <div class="filter-item">
          <label for="search">Search</label>
          <input type="text" id="search" name="search" placeholder="Aircraft, activity, or work order" value="<?= htmlspecialchars($searchQuery) ?>">
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
          <label for="activity">Activity</label>
          <select id="activity" name="activity">
            <option value="All Activities" <?= $filterActivity === 'All Activities' ? 'selected' : '' ?>>All Activities</option>
            <option value="Inspection" <?= $filterActivity === 'Inspection' ? 'selected' : '' ?>>Inspection</option>
            <option value="Discrepancy" <?= $filterActivity === 'Discrepancy' ? 'selected' : '' ?>>Discrepancy</option>
            <option value="Repair" <?= $filterActivity === 'Repair' ? 'selected' : '' ?>>Repair</option>
            <option value="Completed Work Order" <?= $filterActivity === 'Completed Work Order' ? 'selected' : '' ?>>Completed Work Order</option>
          </select>
        </div>
        <div class="filter-item" style="display:flex; gap:6px; align-items:flex-end;">
          <button type="submit" class="btn small" style="cursor:pointer;">Search</button>
          <?php if (!empty($searchQuery) || $filterAircraft !== 'All Aircraft' || $filterActivity !== 'All Activities'): ?>
            <a href="logbook.php" class="btn small btn-secondary">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Logbook Entries Table (Live SQL Data) -->
    <div class="panel">
      <h2>Logbook Entries (<?= count($entries) ?> Found)</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Date</th>
            <th>Aircraft</th>
            <th>Activity</th>
            <th>Work Order</th>
            <th>Technician</th>
            <th>Result</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($entries)): ?>
            <?php foreach ($entries as $e): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($e['log_date'])) ?></td>
                <td>
                  <a href="aircraft-profile.php?reg=<?= urlencode($e['aircraft_registration']) ?>" style="font-weight:600; color:#0284c7;">
                    <?= htmlspecialchars($e['aircraft_registration']) ?>
                  </a>
                </td>
                <td>
                  <span class="status status-progress"><?= htmlspecialchars($e['activity_type']) ?></span>
                </td>
                <td>
                  <?php if (!empty($e['work_order_number'])): ?>
                    <a href="work-order-details.php?num=<?= urlencode($e['work_order_number']) ?>" style="font-weight:600; color:#0284c7;">
                      <?= htmlspecialchars($e['work_order_number']) ?>
                    </a>
                  <?php else: ?>
                    <span style="color:#94a3b8;">&ndash;</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($e['technician_name']) ?></td>
                <td><?= htmlspecialchars($e['result_summary']) ?></td>
                <td>
                  <form method="POST" action="logbook.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete logbook entry #<?= (int)$e['log_id'] ?>?');">
                    <input type="hidden" name="action" value="delete_entry">
                    <input type="hidden" name="log_id" value="<?= (int)$e['log_id'] ?>">
                    <button type="submit" class="btn small" style="background:#ef4444; color:#fff; border-color:#ef4444; font-size:11px; padding:3px 6px; cursor:pointer;" title="Delete logbook entry">
                      &times; Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" style="text-align:center; color:#64748b; padding:20px;">No logbook entries match current filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="panel-actions">
        <a class="btn" href="aircraft-profile.php?reg=RP-C1234">View Sample Aircraft Profile</a>
        <button class="btn" onclick="openLogModal()" style="cursor:pointer;">+ Add Logbook Entry</button>
      </div>
    </div>

    <div class="footer-note">
      JetLog Digital Logbook &bull; Certified records queried from table <code>maintenance_logbook</code>.
    </div>

  </main>

</div>

<!-- Modal: Add Logbook Entry -->
<div class="modal-overlay" id="logModal">
  <div class="modal-box">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:14px;">
      <h3 style="margin:0; color:#0f172a;">Record Certified Logbook Entry</h3>
      <button type="button" class="modal-close" onclick="closeLogModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="logbook.php">
      <input type="hidden" name="action" value="add_entry">

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_log_ac">Aircraft *</label>
        <select id="modal_log_ac" name="aircraft_id" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
          <option value="">-- Select Aircraft --</option>
          <?php foreach ($acList as $ac): ?>
            <option value="<?= (int)$ac['aircraft_id'] ?>">
              <?= htmlspecialchars($ac['registration_number']) ?> &ndash; <?= htmlspecialchars($ac['model']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div class="form-row">
          <label for="modal_activity">Activity Type *</label>
          <select id="modal_activity" name="activity_type" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
            <option value="Inspection">Inspection</option>
            <option value="Repair">Repair</option>
            <option value="Discrepancy">Discrepancy</option>
            <option value="Completed Work Order">Completed Work Order</option>
            <option value="Scheduled Maintenance">Scheduled Maintenance</option>
          </select>
        </div>
        <div class="form-row">
          <label for="modal_log_date">Entry Date</label>
          <input type="date" id="modal_log_date" name="log_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
      </div>

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_log_wo">Linked Work Order (Optional)</label>
        <select id="modal_log_wo" name="work_order_id" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
          <option value="">-- None / Standalone Entry --</option>
          <?php foreach ($woList as $w): ?>
            <option value="<?= (int)$w['work_order_id'] ?>">
              <?= htmlspecialchars($w['work_order_number']) ?> &ndash; <?= htmlspecialchars(substr($w['issue_summary'], 0, 45)) ?>...
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_res">Result Summary *</label>
        <input type="text" id="modal_res" name="result_summary" placeholder="e.g. Completed routine inspection, signed off to service" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
      </div>

      <div class="form-row" style="margin-bottom:18px;">
        <label for="modal_log_rem">Detailed Remarks / Maintenance Reference</label>
        <textarea id="modal_log_rem" name="remarks" rows="3" placeholder="Reference AMM chapters, replacement part numbers..." style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeLogModal()">Cancel</button>
        <button type="submit" class="btn small">Save Entry</button>
      </div>
    </form>
  </div>
</div>

<script>
function openLogModal() {
  document.getElementById('logModal').style.display = 'flex';
}
function closeLogModal() {
  document.getElementById('logModal').style.display = 'none';
}
</script>

</body>
</html>
