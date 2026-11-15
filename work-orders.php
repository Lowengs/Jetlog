<?php
/**
 * JetLog: Live Maintenance Work Orders List & Management
 * Reference: work-orders.html
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'work-orders';
$pdo = getDbConnection();
$flash = getFlashMessage();

// Handle Create Work Order POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_work_order') {
    $aircraftId = (int)($_POST['aircraft_id'] ?? 0);
    $issueSummary = trim($_POST['issue_summary'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $techId = !empty($_POST['assigned_technician_id']) ? (int)$_POST['assigned_technician_id'] : null;
    $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+5 days'));
    $remarks = trim($_POST['technician_remarks'] ?? '');

    if ($aircraftId <= 0 || empty($issueSummary)) {
        setFlashMessage('error', 'Please select an aircraft and specify an issue summary.');
    } else {
        try {
            $pdo->beginTransaction();

            // Generate work order number
            $countStmt = $pdo->query('SELECT COUNT(*) FROM work_orders');
            $nextNum = str_pad(((int)$countStmt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
            $woNumber = "WO-2026-{$nextNum}";
            $woToken = "JETLOG-WO-2026-{$nextNum}-" . bin2hex(random_bytes(3));
            $initStatus = $techId ? 'Assigned' : 'Open';

            $insertStmt = $pdo->prepare('
                INSERT INTO work_orders 
                (work_order_number, aircraft_id, issue_summary, priority, assigned_technician_id, status, date_created, due_date, technician_remarks, qr_code_token)
                VALUES (:num, :aid, :issue, :prio, :tid, :status, CURRENT_DATE(), :due, :rem, :token)
            ');
            $insertStmt->execute([
                ':num' => $woNumber,
                ':aid' => $aircraftId,
                ':issue' => $issueSummary,
                ':prio' => $priority,
                ':tid' => $techId,
                ':status' => $initStatus,
                ':due' => $dueDate,
                ':rem' => $remarks ?: null,
                ':token' => $woToken
            ]);
            $woId = (int)$pdo->lastInsertId();

            // Insert initial history transition
            $histStmt = $pdo->prepare('
                INSERT INTO work_order_status_history (work_order_id, status, changed_by_user_id, remarks)
                VALUES (:wid, :status, :uid, :rem)
            ');
            $histStmt->execute([
                ':wid' => $woId,
                ':status' => $initStatus,
                ':uid' => !empty($currentUser['user_id']) ? $currentUser['user_id'] : null,
                ':rem' => "Work order created and {$initStatus}."
            ]);

            $pdo->commit();
            setFlashMessage('success', "Work Order {$woNumber} created successfully.");
            header("Location: work-order-details.php?id={$woId}");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Database error creating work order: ' . $e->getMessage());
        }
    }
}

// Handle Delete Work Order POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_work_order') {
    $woId = (int)($_POST['work_order_id'] ?? 0);
    $woNum = trim($_POST['work_order_number'] ?? '');
    if ($woId > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE maintenance_schedules SET linked_work_order_id = NULL WHERE linked_work_order_id = :id')->execute([':id' => $woId]);
            $pdo->prepare('UPDATE maintenance_logbook SET work_order_id = NULL WHERE work_order_id = :id')->execute([':id' => $woId]);
            $pdo->prepare('DELETE FROM work_order_status_history WHERE work_order_id = :id')->execute([':id' => $woId]);
            $pdo->prepare('DELETE FROM work_orders WHERE work_order_id = :id')->execute([':id' => $woId]);
            $pdo->commit();
            setFlashMessage('success', "Work Order " . ($woNum ?: "#{$woId}") . " has been deleted from the database.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlashMessage('error', 'Error deleting work order: ' . $e->getMessage());
        }
    }
    header('Location: work-orders.php');
    exit;
}

// Read filter parameters
$searchQuery = trim($_GET['search'] ?? '');
$filterAircraft = trim($_GET['aircraft'] ?? 'All Aircraft');
$filterStatus = trim($_GET['status'] ?? 'All Statuses');
$filterPriority = trim($_GET['priority'] ?? 'All Priorities');

// Build SQL query
$sql = '
    SELECT 
        wo.*,
        a.registration_number AS aircraft_registration,
        a.model AS aircraft_model,
        CONCAT(u.first_name, " ", u.last_name) AS technician_name
    FROM work_orders wo
    JOIN aircraft a ON wo.aircraft_id = a.aircraft_id
    LEFT JOIN users u ON wo.assigned_technician_id = u.user_id
    WHERE 1=1
';
$params = [];

if (!empty($searchQuery)) {
    $sql .= ' AND (wo.work_order_number LIKE :search_wo OR wo.issue_summary LIKE :search_issue)';
    $params[':search_wo'] = '%' . $searchQuery . '%';
    $params[':search_issue'] = '%' . $searchQuery . '%';
}

if (!empty($filterAircraft) && $filterAircraft !== 'All Aircraft') {
    $sql .= ' AND a.registration_number = :reg';
    $params[':reg'] = $filterAircraft;
}

if (!empty($filterStatus) && $filterStatus !== 'All Statuses') {
    if ($filterStatus === 'Active') {
        $sql .= ' AND wo.status NOT IN ("Completed", "Cancelled")';
    } else {
        $sql .= ' AND wo.status = :status';
        $params[':status'] = $filterStatus;
    }
}

if (!empty($filterPriority) && $filterPriority !== 'All Priorities') {
    $sql .= ' AND wo.priority = :priority';
    $params[':priority'] = $filterPriority;
}

$sql .= ' ORDER BY wo.date_created DESC, wo.work_order_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$workOrders = $stmt->fetchAll();

// Fetch aircraft list for filter dropdown & modal
$acList = $pdo->query('SELECT aircraft_id, registration_number, model FROM aircraft ORDER BY registration_number ASC')->fetchAll();

// Fetch technicians for modal dropdown
$techList = $pdo->query('SELECT user_id, first_name, last_name, role FROM users WHERE status="Active" ORDER BY first_name ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Maintenance Work Orders</title>
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
  .priority-high { color: #b91c1c; font-weight: bold; }
  .priority-critical { color: #7f1d1d; background: #fee2e2; padding: 2px 6px; border-radius: 3px; font-weight: bold; }
  .priority-medium { color: #d97706; font-weight: 600; }
  .priority-low { color: #475569; }
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
        <h1>Maintenance Work Orders</h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Work Orders
        </div>
      </div>
      <div>
        <button class="btn" onclick="openCreateModal()" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
          + Create Work Order
        </button>
      </div>
    </div>

    <!-- Real Work Order Filters -->
    <div class="panel">
      <h2>Work Order Filters</h2>
      <form method="GET" action="work-orders.php" class="filter-bar">
        <div class="filter-item">
          <label for="search">Search</label>
          <input type="text" id="search" name="search" placeholder="WO number or issue" value="<?= htmlspecialchars($searchQuery) ?>">
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
            <option value="Active" <?= $filterStatus === 'Active' ? 'selected' : '' ?>>All Active (Open / In Progress)</option>
            <option value="Open" <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open</option>
            <option value="Assigned" <?= $filterStatus === 'Assigned' ? 'selected' : '' ?>>Assigned</option>
            <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
            <option value="For Verification" <?= $filterStatus === 'For Verification' ? 'selected' : '' ?>>For Verification</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
          </select>
        </div>
        <div class="filter-item">
          <label for="priority">Priority</label>
          <select id="priority" name="priority">
            <option value="All Priorities" <?= $filterPriority === 'All Priorities' ? 'selected' : '' ?>>All Priorities</option>
            <option value="Critical" <?= $filterPriority === 'Critical' ? 'selected' : '' ?>>Critical</option>
            <option value="High" <?= $filterPriority === 'High' ? 'selected' : '' ?>>High</option>
            <option value="Medium" <?= $filterPriority === 'Medium' ? 'selected' : '' ?>>Medium</option>
            <option value="Low" <?= $filterPriority === 'Low' ? 'selected' : '' ?>>Low</option>
          </select>
        </div>
        <div class="filter-item" style="display:flex; gap:6px; align-items:flex-end;">
          <button type="submit" class="btn small" style="cursor:pointer;">Apply Filters</button>
          <?php if (!empty($searchQuery) || $filterAircraft !== 'All Aircraft' || $filterStatus !== 'All Statuses' || $filterPriority !== 'All Priorities'): ?>
            <a href="work-orders.php" class="btn small btn-secondary">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Work Order List (Live SQL Data) -->
    <div class="panel">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 style="margin:0; border:none;">Work Orders (<?= count($workOrders) ?> Found)</h2>
      </div>
      <table class="wire">
        <thead>
          <tr>
            <th>Work Order</th>
            <th>Aircraft</th>
            <th>Issue Summary</th>
            <th>Assigned Technician</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($workOrders)): ?>
            <?php foreach ($workOrders as $wo): ?>
              <tr>
                <td><strong><?= htmlspecialchars($wo['work_order_number']) ?></strong></td>
                <td>
                  <a href="aircraft-profile.php?reg=<?= urlencode($wo['aircraft_registration']) ?>" style="font-weight:600; color:#0284c7;">
                    <?= htmlspecialchars($wo['aircraft_registration']) ?>
                  </a>
                </td>
                <td><?= htmlspecialchars($wo['issue_summary']) ?></td>
                <td><?= htmlspecialchars($wo['technician_name'] ?? 'Unassigned') ?></td>
                <td>
                  <span class="priority-<?= strtolower(htmlspecialchars($wo['priority'])) ?>">
                    <?= htmlspecialchars($wo['priority']) ?>
                  </span>
                </td>
                <td>
                  <?php
                    $woClass = match($wo['status']) {
                      'In Progress' => 'status-progress',
                      'For Verification' => 'status-verification',
                      'Assigned' => 'status-assigned',
                      'Completed' => 'status-completed',
                      default => 'status-open',
                    };
                  ?>
                  <span class="status <?= $woClass ?>"><?= htmlspecialchars($wo['status']) ?></span>
                </td>
                <td style="white-space:nowrap;">
                  <a href="work-order-details.php?id=<?= (int)$wo['work_order_id'] ?>" style="color:#0284c7; font-weight:600; margin-right:8px;">
                    View &rarr;
                  </a>
                  <form method="POST" action="work-orders.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete Work Order <?= htmlspecialchars($wo['work_order_number']) ?>?');">
                    <input type="hidden" name="action" value="delete_work_order">
                    <input type="hidden" name="work_order_id" value="<?= (int)$wo['work_order_id'] ?>">
                    <input type="hidden" name="work_order_number" value="<?= htmlspecialchars($wo['work_order_number']) ?>">
                    <button type="submit" class="btn small" style="background:#ef4444; color:#fff; border-color:#ef4444; font-size:11px; padding:3px 6px; cursor:pointer;" title="Delete work order">
                      &times; Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" style="text-align:center; color:#64748b; padding:20px;">No work orders match the current filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="panel-actions">
        <button class="btn" onclick="openCreateModal()" style="cursor:pointer;">+ Create Work Order</button>
      </div>
    </div>

    <div class="footer-note">
      JetLog Work Orders System &bull; Live queries from table <code>work_orders</code>.
    </div>

  </main>

</div>

<!-- Modal: Create Work Order -->
<div class="modal-overlay" id="createWoModal">
  <div class="modal-box">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:14px;">
      <h3 style="margin:0; color:#0f172a;">Create Work Order</h3>
      <button type="button" class="modal-close" onclick="closeCreateModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="work-orders.php">
      <input type="hidden" name="action" value="create_work_order">

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_aircraft">Aircraft *</label>
        <select id="modal_aircraft" name="aircraft_id" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
          <option value="">-- Select Fleet Aircraft --</option>
          <?php foreach ($acList as $ac): ?>
            <option value="<?= (int)$ac['aircraft_id'] ?>" <?= (isset($_GET['aircraft_id']) && (int)$_GET['aircraft_id'] === (int)$ac['aircraft_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($ac['registration_number']) ?> &ndash; <?= htmlspecialchars($ac['model']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_issue">Issue / Maintenance Task *</label>
        <input type="text" id="modal_issue" name="issue_summary" placeholder="e.g. Landing gear hydraulic leak" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div class="form-row">
          <label for="modal_prio">Priority</label>
          <select id="modal_prio" name="priority" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
            <option value="Low">Low</option>
            <option value="Medium" selected>Medium</option>
            <option value="High">High</option>
            <option value="Critical">Critical</option>
          </select>
        </div>
        <div class="form-row">
          <label for="modal_due">Due Date</label>
          <input type="date" id="modal_due" name="due_date" value="<?= date('Y-m-d', strtotime('+5 days')) ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
      </div>

      <div class="form-row" style="margin-bottom:12px;">
        <label for="modal_tech">Assign Technician</label>
        <select id="modal_tech" name="assigned_technician_id" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
          <option value="">-- Unassigned (Open Queue) --</option>
          <?php foreach ($techList as $tech): ?>
            <option value="<?= (int)$tech['user_id'] ?>" <?= ($currentUser['user_id'] === $tech['user_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($tech['first_name'] . ' ' . $tech['last_name']) ?> (<?= htmlspecialchars($tech['role']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row" style="margin-bottom:18px;">
        <label for="modal_remarks">Initial Remarks / Notes</label>
        <textarea id="modal_remarks" name="technician_remarks" rows="3" placeholder="Enter initial instructions or observations..." style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeCreateModal()">Cancel</button>
        <button type="submit" class="btn small">Save Work Order</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateModal() {
  document.getElementById('createWoModal').style.display = 'flex';
}
function closeCreateModal() {
  document.getElementById('createWoModal').style.display = 'none';
}
<?php if (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
  document.addEventListener('DOMContentLoaded', openCreateModal);
<?php endif; ?>
</script>

</body>
</html>
