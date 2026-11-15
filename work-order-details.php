<?php
/**
 * JetLog: Live Work Order Details & Maintenance Progress Flow
 * Reference: work-order-details.html
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'work-orders';
$pdo = getDbConnection();
$flash = getFlashMessage();

$woId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$woNum = trim($_GET['num'] ?? '');

// Fetch Work Order
if (!empty($woId)) {
    $stmt = $pdo->prepare('
        SELECT 
            wo.*,
            a.registration_number,
            a.model AS aircraft_model,
            a.aircraft_id,
            CONCAT(u.first_name, " ", u.last_name) AS technician_name,
            u.role AS technician_role,
            CONCAT(v.first_name, " ", v.last_name) AS verifier_name
        FROM work_orders wo
        JOIN aircraft a ON wo.aircraft_id = a.aircraft_id
        LEFT JOIN users u ON wo.assigned_technician_id = u.user_id
        LEFT JOIN users v ON wo.verified_by_user_id = v.user_id
        WHERE wo.work_order_id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $woId]);
    $workOrder = $stmt->fetch();
} else {
    if (empty($woNum)) $woNum = 'WO-2026-001';
    $stmt = $pdo->prepare('
        SELECT 
            wo.*,
            a.registration_number,
            a.model AS aircraft_model,
            a.aircraft_id,
            CONCAT(u.first_name, " ", u.last_name) AS technician_name,
            u.role AS technician_role,
            CONCAT(v.first_name, " ", v.last_name) AS verifier_name
        FROM work_orders wo
        JOIN aircraft a ON wo.aircraft_id = a.aircraft_id
        LEFT JOIN users u ON wo.assigned_technician_id = u.user_id
        LEFT JOIN users v ON wo.verified_by_user_id = v.user_id
        WHERE wo.work_order_number = :num
        LIMIT 1
    ');
    $stmt->execute([':num' => $woNum]);
    $workOrder = $stmt->fetch();
}

if (!$workOrder) {
    setFlashMessage('error', 'Work order not found.');
    header('Location: work-orders.php');
    exit;
}

$woId = (int)$workOrder['work_order_id'];

// Handle Status Advancement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $newStatus = $_POST['new_status'] ?? '';
    $statusRemarks = trim($_POST['status_remarks'] ?? '');

    $allowedStatuses = ['Open', 'Assigned', 'In Progress', 'For Verification', 'Completed'];
    if (in_array($newStatus, $allowedStatuses)) {
        try {
            $pdo->beginTransaction();

            $updateParams = [
                ':status' => $newStatus,
                ':id' => $woId
            ];

            $extraSql = '';
            if ($newStatus === 'Completed') {
                $extraSql = ', completed_date = CURRENT_DATE(), verified_by_user_id = :vid, verified_at = NOW()';
                $updateParams[':vid'] = $currentUser['user_id'];
            }

            $upd = $pdo->prepare("UPDATE work_orders SET status = :status {$extraSql} WHERE work_order_id = :id");
            $upd->execute($updateParams);

            // Insert into history
            $hist = $pdo->prepare('
                INSERT INTO work_order_status_history (work_order_id, status, changed_by_user_id, remarks)
                VALUES (:wid, :status, :uid, :rem)
            ');
            $hist->execute([
                ':wid' => $woId,
                ':status' => $newStatus,
                ':uid' => $currentUser['user_id'],
                ':rem' => $statusRemarks ?: "Status advanced to {$newStatus}"
            ]);

            // If completed, add an entry into maintenance logbook if not already present
            if ($newStatus === 'Completed') {
                $logCheck = $pdo->prepare('SELECT log_id FROM maintenance_logbook WHERE work_order_id = :wid');
                $logCheck->execute([':wid' => $woId]);
                if (!$logCheck->fetch()) {
                    $logIns = $pdo->prepare('
                        INSERT INTO maintenance_logbook 
                        (aircraft_id, work_order_id, activity_type, technician_id, log_date, result_summary, remarks)
                        VALUES (:aid, :wid, "Completed Work Order", :tid, CURRENT_DATE(), :res, :rem)
                    ');
                    $logIns->execute([
                        ':aid' => $workOrder['aircraft_id'],
                        ':wid' => $woId,
                        ':tid' => $workOrder['assigned_technician_id'] ?: $currentUser['user_id'],
                        ':res' => "Work order {$workOrder['work_order_number']} completed and signed off",
                        ':rem' => $workOrder['maintenance_action_performed'] ?: 'All maintenance items signed off.'
                    ]);
                }
            }

            $pdo->commit();
            setFlashMessage('success', "Work order status successfully updated to '{$newStatus}'.");
            header("Location: work-order-details.php?id={$woId}");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Failed to update status: ' . $e->getMessage());
        }
    }
}

// Handle Save Remarks POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_remarks') {
    $remarks = trim($_POST['technician_remarks'] ?? '');
    $upd = $pdo->prepare('UPDATE work_orders SET technician_remarks = :rem WHERE work_order_id = :id');
    $upd->execute([':rem' => $remarks, ':id' => $woId]);
    setFlashMessage('success', 'Technician remarks saved successfully.');
    header("Location: work-order-details.php?id={$woId}");
    exit;
}

// Handle Save Action Performed POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_action') {
    $actionText = trim($_POST['maintenance_action_performed'] ?? '');
    $upd = $pdo->prepare('UPDATE work_orders SET maintenance_action_performed = :act WHERE work_order_id = :id');
    $upd->execute([':act' => $actionText, ':id' => $woId]);
    setFlashMessage('success', 'Maintenance action performed recorded successfully.');
    header("Location: work-order-details.php?id={$woId}");
    exit;
}

// Handle Reassign Technician POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign_technician') {
    $newTechId = !empty($_POST['technician_id']) ? (int)$_POST['technician_id'] : null;
    $upd = $pdo->prepare('UPDATE work_orders SET assigned_technician_id = :tid, status = IF(status="Open", "Assigned", status) WHERE work_order_id = :id');
    $upd->execute([':tid' => $newTechId, ':id' => $woId]);
    
    // Log history
    $hist = $pdo->prepare('INSERT INTO work_order_status_history (work_order_id, status, changed_by_user_id, remarks) VALUES (:wid, :st, :uid, :rem)');
    $hist->execute([
        ':wid' => $woId,
        ':st' => $workOrder['status'] === 'Open' ? 'Assigned' : $workOrder['status'],
        ':uid' => $currentUser['user_id'],
        ':rem' => 'Technician assignment updated.'
    ]);
    
    setFlashMessage('success', 'Assigned technician updated successfully.');
    header("Location: work-order-details.php?id={$woId}");
    exit;
}

// Handle Delete Work Order POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_work_order') {
    try {
        $pdo->beginTransaction();

        // 1. Unlink from maintenance schedules
        $updSched = $pdo->prepare('UPDATE maintenance_schedules SET linked_work_order_id = NULL WHERE linked_work_order_id = :id');
        $updSched->execute([':id' => $woId]);

        // 2. Unlink from maintenance logbook
        $updLog = $pdo->prepare('UPDATE maintenance_logbook SET work_order_id = NULL WHERE work_order_id = :id');
        $updLog->execute([':id' => $woId]);

        // 3. Delete work order status history
        $delHist = $pdo->prepare('DELETE FROM work_order_status_history WHERE work_order_id = :id');
        $delHist->execute([':id' => $woId]);

        // 4. Delete the work order itself
        $delWo = $pdo->prepare('DELETE FROM work_orders WHERE work_order_id = :id');
        $delWo->execute([':id' => $woId]);

        $pdo->commit();
        setFlashMessage('success', "Work Order {$workOrder['work_order_number']} has been permanently deleted from the database.");
        header('Location: work-orders.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error deleting work order: ' . $e->getMessage());
        header("Location: work-order-details.php?id={$woId}");
        exit;
    }
}

// Fetch Active Technicians for assignment dropdown
$allTechs = $pdo->query('SELECT user_id, first_name, last_name, role FROM users WHERE status="Active" ORDER BY first_name ASC')->fetchAll();

// Fetch Status History
$histStmt = $pdo->prepare('
    SELECT h.*, CONCAT(u.first_name, " ", u.last_name) AS user_name, u.role
    FROM work_order_status_history h
    LEFT JOIN users u ON h.changed_by_user_id = u.user_id
    WHERE h.work_order_id = :id
    ORDER BY h.changed_at ASC
');
$histStmt->execute([':id' => $woId]);
$historyEntries = $histStmt->fetchAll();

// Construct real absolute URL for mobile QR code access
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$qrUrl = "{$protocol}://{$host}/JetLog/work-order-details.php?id={$woId}";

// Status workflow stages
$stages = ['Reported', 'Assigned', 'In Progress', 'For Verification', 'Completed'];
$currentStatus = $workOrder['status'];
$currentIndex = array_search($currentStatus, $stages);
if ($currentIndex === false) {
    $currentIndex = ($currentStatus === 'Open') ? 0 : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Work Order <?= htmlspecialchars($workOrder['work_order_number']) ?></title>
<link rel="stylesheet" href="styles.css">
<script src="qr-helper.js"></script>
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
  .qr-real-container {
    display: flex;
    align-items: center;
    gap: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 16px;
    border-radius: 8px;
  }
  .qr-frame {
    background: #fff;
    padding: 8px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    cursor: pointer;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
  }
  .qr-frame:hover {
    border-color: #0284c7;
  }
  .flow-action-bar {
    margin-top: 16px;
    padding: 12px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
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
        <h1>Work Order Details: <?= htmlspecialchars($workOrder['work_order_number']) ?></h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; 
          <a href="work-orders.php" style="color:#64748b; text-decoration:none;">Work Orders</a> &gt; 
          Work Order Details
        </div>
      </div>
      <div>
        <a class="btn small" href="aircraft-profile.php?reg=<?= urlencode($workOrder['registration_number']) ?>">
          View Aircraft Profile &rarr;
        </a>
      </div>
    </div>

    <!-- Work Order Information -->
    <div class="panel">
      <h2>Work Order Information</h2>
      <div class="detail-list">
        <div class="detail-row">
          <div class="detail-label">Work Order Number</div>
          <div class="detail-value"><strong><?= htmlspecialchars($workOrder['work_order_number']) ?></strong></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Aircraft</div>
          <div class="detail-value">
            <a href="aircraft-profile.php?reg=<?= urlencode($workOrder['registration_number']) ?>" style="font-weight:600; color:#0284c7;">
              <?= htmlspecialchars($workOrder['registration_number']) ?> (<?= htmlspecialchars($workOrder['aircraft_model']) ?>)
            </a>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Reported Issue / Defect</div>
          <div class="detail-value"><?= htmlspecialchars($workOrder['issue_summary']) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Priority</div>
          <div class="detail-value"><strong><?= htmlspecialchars($workOrder['priority']) ?></strong></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Assigned Technician</div>
          <div class="detail-value">
            <form method="POST" action="work-order-details.php?id=<?= $woId ?>" style="display:inline-flex; align-items:center; gap:6px;">
              <input type="hidden" name="action" value="reassign_technician">
              <select name="technician_id" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
                <option value="">-- Unassigned --</option>
                <?php foreach ($allTechs as $tech): ?>
                  <option value="<?= (int)$tech['user_id'] ?>" <?= ((int)($workOrder['assigned_technician_id'] ?? 0) === (int)$tech['user_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tech['first_name'] . ' ' . $tech['last_name']) ?> (<?= htmlspecialchars($tech['role']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn small" style="padding:3px 8px; font-size:11px; cursor:pointer;">Update</button>
            </form>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Current Status</div>
          <div class="detail-value">
            <?php
              $woClass = match($workOrder['status']) {
                'In Progress' => 'status-progress',
                'For Verification' => 'status-verification',
                'Assigned' => 'status-assigned',
                'Completed' => 'status-completed',
                default => 'status-open',
              };
            ?>
            <span class="status <?= $woClass ?>" style="font-size:13px; font-weight:600; padding:3px 8px;">
              <?= htmlspecialchars($workOrder['status']) ?>
            </span>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Date Created</div>
          <div class="detail-value"><?= date('M d, Y', strtotime($workOrder['date_created'])) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Due Date</div>
          <div class="detail-value"><?= date('M d, Y', strtotime($workOrder['due_date'])) ?></div>
        </div>
        <?php if (!empty($workOrder['completed_date'])): ?>
          <div class="detail-row">
            <div class="detail-label">Sign-off Verification</div>
            <div class="detail-value" style="color:#166534;">
              Completed on <?= date('M d, Y', strtotime($workOrder['completed_date'])) ?> &bull; Verified by <?= htmlspecialchars($workOrder['verifier_name'] ?? 'Inspector') ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Real Maintenance Progress Flow with Action Buttons -->
    <div class="panel">
      <h2>Maintenance Progress Flow</h2>
      <div class="flow">
        <?php foreach ($stages as $idx => $stage): ?>
          <?php
            $isDone = ($idx < $currentIndex) || ($workOrder['status'] === 'Completed');
            $isCurrent = ($idx === $currentIndex) && ($workOrder['status'] !== 'Completed');
            $stepClass = $isDone ? 'done' : ($isCurrent ? 'current' : '');
          ?>
          <div class="flow-step <?= $stepClass ?>">
            <?= htmlspecialchars($stage) ?><?= $isCurrent ? ' (current)' : '' ?>
          </div>
          <?php if ($idx < count($stages) - 1): ?>
            <div class="flow-arrow">&darr;</div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Interactive Status Advancement Form -->
      <div class="flow-action-bar">
        <strong style="color:#334155; font-size:13px;">Advance Status:</strong>
        <form method="POST" action="work-order-details.php?id=<?= $woId ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <input type="hidden" name="action" value="update_status">
          
          <?php if ($workOrder['status'] === 'Open'): ?>
            <input type="hidden" name="new_status" value="Assigned">
            <button type="submit" class="btn small" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
              &rarr; Mark as Assigned
            </button>
          <?php elseif ($workOrder['status'] === 'Assigned'): ?>
            <input type="hidden" name="new_status" value="In Progress">
            <button type="submit" class="btn small" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
              &rarr; Start Work (Move to In Progress)
            </button>
          <?php elseif ($workOrder['status'] === 'In Progress'): ?>
            <input type="hidden" name="new_status" value="For Verification">
            <button type="submit" class="btn small" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
              &rarr; Submit for Verification
            </button>
          <?php elseif ($workOrder['status'] === 'For Verification'): ?>
            <input type="hidden" name="new_status" value="Completed">
            <button type="submit" class="btn small" style="background:#16a34a; color:#fff; border-color:#16a34a; cursor:pointer;">
              &check; Sign Off &amp; Complete Work Order
            </button>
          <?php else: ?>
            <span style="font-size:12px; color:#15803d; font-weight:600;">&check; This work order is fully completed and signed off.</span>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- QR Code Access: Real Scannable 2D QR Code -->
    <div class="panel">
      <h2>Hangar Floor QR Code Access</h2>
      <div class="qr-real-container">
        <div class="qr-frame" onclick="openQrModal('<?= htmlspecialchars($qrUrl) ?>')">
          <div id="wo-qr-box"></div>
        </div>
        <div class="qr-caption" style="flex:1;">
          <h4 style="margin:0 0 6px 0; color:#1e293b;">Hangar Floor Work Order Tracking</h4>
          <p style="margin:0 0 8px 0; color:#475569; font-size:13px;">
            Scanning this QR code on a mobile device or rugged hangar tablet opens this exact work order for real-time progress logging.
          </p>
          <div style="font-size:11px; font-family:monospace; color:#64748b; background:#e2e8f0; padding:4px 8px; border-radius:4px; display:inline-block; margin-bottom:8px;">
            Token: <?= htmlspecialchars($workOrder['qr_code_token']) ?>
          </div>
          <div>
            <button class="btn small" onclick="openQrModal('<?= htmlspecialchars($qrUrl) ?>')" style="cursor:pointer;">
              Zoom QR &amp; Test Link &rarr;
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Technician Remarks (Editable & Persisted to DB) -->
    <div class="panel">
      <h2>Technician Remarks</h2>
      <form method="POST" action="work-order-details.php?id=<?= $woId ?>">
        <input type="hidden" name="action" value="save_remarks">
        <textarea name="technician_remarks" rows="4" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box; font-family:inherit; font-size:13px;"><?= htmlspecialchars($workOrder['technician_remarks'] ?? '') ?></textarea>
        <div style="margin-top:8px;">
          <button type="submit" class="btn small" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
            Save Remarks
          </button>
        </div>
      </form>
    </div>

    <!-- Maintenance Action Performed (Editable & Persisted to DB) -->
    <div class="panel">
      <h2>Maintenance Action Performed</h2>
      <form method="POST" action="work-order-details.php?id=<?= $woId ?>">
        <input type="hidden" name="action" value="save_action">
        <textarea name="maintenance_action_performed" rows="4" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box; font-family:inherit; font-size:13px;"><?= htmlspecialchars($workOrder['maintenance_action_performed'] ?? '') ?></textarea>
        <div style="margin-top:8px;">
          <button type="submit" class="btn small" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
            Save Action Performed
          </button>
        </div>
      </form>
    </div>

    <!-- Status History Audit Trail -->
    <?php if (!empty($historyEntries)): ?>
      <div class="panel">
        <h2>Work Order Audit History</h2>
        <table class="wire">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Status</th>
              <th>Changed By</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historyEntries as $h): ?>
              <tr>
                <td><?= date('M d, Y - H:i', strtotime($h['changed_at'])) ?></td>
                <td><span class="status status-progress"><?= htmlspecialchars($h['status']) ?></span></td>
                <td><?= htmlspecialchars($h['user_name'] ?? 'System') ?> (<?= htmlspecialchars($h['role'] ?? '') ?>)</td>
                <td><?= htmlspecialchars($h['remarks'] ?? 'No remarks') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="panel">
      <div class="button-row" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; gap:10px;">
          <a class="btn" href="work-orders.php">&larr; Back to Work Orders</a>
          <a class="btn" href="aircraft-profile.php?reg=<?= urlencode($workOrder['registration_number']) ?>">View Aircraft Profile</a>
        </div>
        <form method="POST" action="work-order-details.php?id=<?= $woId ?>" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete Work Order <?= htmlspecialchars($workOrder['work_order_number']) ?>? This action will remove all history and cannot be undone.');">
          <input type="hidden" name="action" value="delete_work_order">
          <button type="submit" class="btn small" style="background:#ef4444; color:#fff; border-color:#ef4444; font-weight:600; cursor:pointer;" title="Permanently delete this work order">
            &times; Delete Work Order
          </button>
        </form>
      </div>
    </div>

    <div class="footer-note">
      JetLog Work Order System &bull; Live updates saved to table <code>work_orders</code>.
    </div>

  </main>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  renderQrCode('wo-qr-box', '<?= htmlspecialchars($qrUrl) ?>', 140);
});
</script>

</body>
</html>
