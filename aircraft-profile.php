<?php
/**
 * JetLog: Live Aircraft Profile & QR Code Access
 * Reference: Page 4 of System Specification Document
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'aircraft';
$pdo = getDbConnection();
$flash = getFlashMessage();

// Identify target aircraft by registration, token, or ID (default to RP-C1234 if not specified)
$targetReg = strtoupper(trim($_GET['reg'] ?? ''));
$targetId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$targetToken = trim($_GET['token'] ?? '');

if (!empty($targetToken)) {
    $stmt = $pdo->prepare('SELECT * FROM aircraft WHERE qr_code_token = :token LIMIT 1');
    $stmt->execute([':token' => $targetToken]);
    $aircraft = $stmt->fetch();
} elseif (!empty($targetId)) {
    $stmt = $pdo->prepare('SELECT * FROM aircraft WHERE aircraft_id = :id LIMIT 1');
    $stmt->execute([':id' => $targetId]);
    $aircraft = $stmt->fetch();
} else {
    if (empty($targetReg)) {
        $targetReg = 'RP-C1234';
    }
    $stmt = $pdo->prepare('SELECT * FROM aircraft WHERE registration_number = :reg LIMIT 1');
    $stmt->execute([':reg' => $targetReg]);
    $aircraft = $stmt->fetch();
}

if (!$aircraft) {
    setFlashMessage('error', "Aircraft '{$targetReg}' not found in registry.");
    header('Location: aircraft.php');
    exit;
}

$aircraftId = (int)$aircraft['aircraft_id'];

// Handle Status Change form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status') {
    $newStatus = $_POST['status'] ?? $aircraft['status'];
    $newLoc = trim($_POST['location'] ?? $aircraft['location']);
    
    $updateStmt = $pdo->prepare('UPDATE aircraft SET status = :status, location = :loc WHERE aircraft_id = :id');
    $updateStmt->execute([':status' => $newStatus, ':loc' => $newLoc, ':id' => $aircraftId]);
    
    setFlashMessage('success', "Aircraft {$aircraft['registration_number']} status updated to {$newStatus}.");
    header("Location: aircraft-profile.php?reg=" . urlencode($aircraft['registration_number']));
    exit;
}

// Handle Delete / Decommission Aircraft POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_aircraft') {
    $deleteMode = $_POST['delete_mode'] ?? 'soft';
    try {
        if ($deleteMode === 'hard') {
            $pdo->beginTransaction();

            // Purge related records in child-to-parent order to satisfy foreign keys
            $pdo->prepare('DELETE FROM maintenance_logbook WHERE aircraft_id = :id')->execute([':id' => $aircraftId]);
            $pdo->prepare('DELETE FROM maintenance_schedules WHERE aircraft_id = :id')->execute([':id' => $aircraftId]);
            $pdo->prepare('DELETE FROM work_order_status_history WHERE work_order_id IN (SELECT work_order_id FROM work_orders WHERE aircraft_id = :id)')->execute([':id' => $aircraftId]);
            $pdo->prepare('DELETE FROM work_orders WHERE aircraft_id = :id')->execute([':id' => $aircraftId]);
            $pdo->prepare('DELETE FROM discrepancies WHERE aircraft_id = :id')->execute([':id' => $aircraftId]);
            $pdo->prepare('DELETE FROM aircraft WHERE aircraft_id = :id')->execute([':id' => $aircraftId]);

            $pdo->commit();
            setFlashMessage('success', "Aircraft {$aircraft['registration_number']} and all associated maintenance history were permanently deleted from the database.");
            header('Location: aircraft.php');
            exit;
        } else {
            // Soft delete: Decommission
            $pdo->prepare('UPDATE aircraft SET status = "Decommissioned" WHERE aircraft_id = :id')->execute([':id' => $aircraftId]);
            setFlashMessage('success', "Aircraft {$aircraft['registration_number']} has been successfully decommissioned (retired from service).");
            header("Location: aircraft-profile.php?reg=" . urlencode($aircraft['registration_number']));
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error during aircraft deletion: ' . $e->getMessage());
        header("Location: aircraft-profile.php?reg=" . urlencode($aircraft['registration_number']));
        exit;
    }
}

// Fetch Active Work Orders for this aircraft
$woStmt = $pdo->prepare('
    SELECT * FROM work_orders 
    WHERE aircraft_id = :id AND status != "Completed" AND status != "Cancelled"
    ORDER BY FIELD(priority, "Critical", "High", "Medium", "Low"), date_created DESC
');
$woStmt->execute([':id' => $aircraftId]);
$activeWorkOrders = $woStmt->fetchAll();

// Fetch Upcoming Inspections for this aircraft
$schedStmt = $pdo->prepare('
    SELECT * FROM maintenance_schedules 
    WHERE aircraft_id = :id AND status IN ("Upcoming", "Due Soon", "Overdue")
    ORDER BY scheduled_date ASC
');
$schedStmt->execute([':id' => $aircraftId]);
$upcomingInspections = $schedStmt->fetchAll();

// Fetch Recent Maintenance History for this aircraft
$histStmt = $pdo->prepare('
    SELECT ml.*, CONCAT(u.first_name, " ", u.last_name) AS technician_name 
    FROM maintenance_logbook ml
    JOIN users u ON ml.technician_id = u.user_id
    WHERE ml.aircraft_id = :id
    ORDER BY ml.log_date DESC
    LIMIT 6
');
$histStmt->execute([':id' => $aircraftId]);
$recentHistory = $histStmt->fetchAll();

// Construct real absolute URL for mobile QR code access
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$qrUrl = "{$protocol}://{$host}/JetLog/aircraft-profile.php?reg=" . urlencode($aircraft['registration_number']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Aircraft Profile - <?= htmlspecialchars($aircraft['registration_number']) ?></title>
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
    transform: scale(1.02);
    transition: transform 0.15s;
  }
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
    max-width: 440px;
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
        <h1>Aircraft Profile: <?= htmlspecialchars($aircraft['registration_number']) ?></h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; 
          <a href="aircraft.php" style="color:#64748b; text-decoration:none;">Aircraft Registry</a> &gt; 
          Aircraft Profile
        </div>
      </div>
      <div>
        <button class="btn small" onclick="openStatusModal()" style="cursor:pointer;">Update Status</button>
      </div>
    </div>

    <!-- Aircraft Information Panel -->
    <div class="panel">
      <h2>Aircraft Information</h2>
      <div class="detail-list">
        <div class="detail-row">
          <div class="detail-label">Registration Number</div>
          <div class="detail-value"><strong><?= htmlspecialchars($aircraft['registration_number']) ?></strong></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Aircraft Model</div>
          <div class="detail-value"><?= htmlspecialchars($aircraft['model']) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Manufacturer</div>
          <div class="detail-value"><?= htmlspecialchars($aircraft['manufacturer']) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Maintenance Status</div>
          <div class="detail-value">
            <?php
              $acClass = match($aircraft['status']) {
                'Under Maintenance' => 'status-maintenance',
                'Inspection Due' => 'status-inspection',
                'Decommissioned' => 'status-decommissioned',
                default => 'status-available',
              };
            ?>
            <span class="status <?= $acClass ?>"><?= htmlspecialchars($aircraft['status']) ?></span>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Current Location</div>
          <div class="detail-value"><?= htmlspecialchars($aircraft['location'] ?? 'Main Hangar') ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Total Flight Hours / Cycles</div>
          <div class="detail-value"><?= number_format((float)$aircraft['total_flight_hours'], 1) ?> hrs &bull; <?= number_format((int)$aircraft['total_flight_cycles']) ?> cycles</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Serial / Mfg Year</div>
          <div class="detail-value"><?= htmlspecialchars($aircraft['serial_number'] ?? 'N/A') ?> (Year: <?= htmlspecialchars($aircraft['year_manufactured'] ?? 'N/A') ?>)</div>
        </div>
      </div>
    </div>

    <!-- QR Code Access: Real Scannable 2D QR Code -->
    <div class="panel">
      <h2>QR Code Access</h2>
      <div class="qr-real-container">
        <div class="qr-frame" onclick="openQrModal('<?= htmlspecialchars($qrUrl) ?>')">
          <div id="aircraft-qr-box"></div>
        </div>
        <div class="qr-caption" style="flex:1;">
          <h4 style="margin:0 0 6px 0; color:#1e293b;">Hangar Floor Quick Scan</h4>
          <p style="margin:0 0 8px 0; color:#475569; font-size:13px;">
            Scanning this QR code with any mobile device or barcode scanner immediately opens this live aircraft profile, active work orders, and digital logbook.
          </p>
          <div style="font-size:11px; font-family:monospace; color:#64748b; background:#e2e8f0; padding:4px 8px; border-radius:4px; display:inline-block; margin-bottom:8px;">
            Token: <?= htmlspecialchars($aircraft['qr_code_token']) ?>
          </div>
          <div>
            <button class="btn small" onclick="openQrModal('<?= htmlspecialchars($qrUrl) ?>')" style="cursor:pointer;">
              Zoom QR &amp; Test Link &rarr;
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Work Orders for this aircraft -->
    <div class="panel">
      <h2>Active Work Orders</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Work Order</th>
            <th>Issue</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($activeWorkOrders)): ?>
            <?php foreach ($activeWorkOrders as $wo): ?>
              <tr>
                <td><strong><?= htmlspecialchars($wo['work_order_number']) ?></strong></td>
                <td><?= htmlspecialchars($wo['issue_summary']) ?></td>
                <td><?= htmlspecialchars($wo['priority']) ?></td>
                <td>
                  <?php
                    $woClass = match($wo['status']) {
                      'In Progress' => 'status-progress',
                      'For Verification' => 'status-verification',
                      'Assigned' => 'status-assigned',
                      default => 'status-open',
                    };
                  ?>
                  <span class="status <?= $woClass ?>"><?= htmlspecialchars($wo['status']) ?></span>
                </td>
                <td>
                  <a href="work-order-details.php?id=<?= (int)$wo['work_order_id'] ?>" style="color:#0284c7; font-weight:600;">View &rarr;</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" style="text-align:center; color:#64748b; padding:14px;">No active work orders for this aircraft.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="panel-actions">
        <a class="btn small" href="work-orders.php?aircraft=<?= urlencode($aircraft['registration_number']) ?>">View All Work Orders</a>
        <a class="btn small" href="work-orders.php?action=new&aircraft_id=<?= $aircraftId ?>">+ Create Work Order</a>
      </div>
    </div>

    <!-- Upcoming Inspections for this aircraft -->
    <div class="panel">
      <h2>Upcoming Inspections</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Inspection</th>
            <th>Scheduled Date</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($upcomingInspections)): ?>
            <?php foreach ($upcomingInspections as $ins): ?>
              <tr>
                <td><?= htmlspecialchars($ins['maintenance_type']) ?></td>
                <td><?= date('M d, Y', strtotime($ins['scheduled_date'])) ?></td>
                <td>
                  <?php
                    $insClass = match($ins['status']) {
                      'Overdue' => 'status-overdue',
                      'Due Soon' => 'status-due-soon',
                      default => 'status-upcoming',
                    };
                  ?>
                  <span class="status <?= $insClass ?>"><?= htmlspecialchars($ins['status']) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="3" style="text-align:center; color:#64748b; padding:14px;">No upcoming inspections scheduled for this aircraft.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="panel-actions">
        <a class="btn small" href="schedule.php?aircraft=<?= urlencode($aircraft['registration_number']) ?>">View Full Schedule</a>
      </div>
    </div>

    <!-- Recent Maintenance History -->
    <div class="panel">
      <h2>Recent Maintenance History</h2>
      <table class="wire">
        <thead>
          <tr>
            <th>Date</th>
            <th>Activity</th>
            <th>Technician</th>
            <th>Result</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($recentHistory)): ?>
            <?php foreach ($recentHistory as $log): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($log['log_date'])) ?></td>
                <td><?= htmlspecialchars($log['activity_type']) ?></td>
                <td><?= htmlspecialchars($log['technician_name']) ?></td>
                <td><?= htmlspecialchars($log['result_summary']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" style="text-align:center; color:#64748b; padding:14px;">No logbook entries on record.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="panel-actions">
        <a class="btn small" href="logbook.php?aircraft=<?= urlencode($aircraft['registration_number']) ?>">View Full Logbook</a>
      </div>
    </div>

    <!-- Aircraft Actions Panel: All Buttons Fully Functional -->
    <div class="panel">
      <h2>Aircraft Actions</h2>
      <div class="button-row" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <a class="btn" href="discrepancy.php?aircraft_id=<?= $aircraftId ?>">Report Discrepancy</a>
          <a class="btn" href="logbook.php?aircraft=<?= urlencode($aircraft['registration_number']) ?>">View Full Logbook</a>
          <button class="btn" onclick="openStatusModal()" style="cursor:pointer;">Update Status / Location</button>
        </div>
        <div>
          <button class="btn small" onclick="openDeleteAircraftModal()" style="background:#ef4444; color:#fff; border-color:#ef4444; font-weight:600; cursor:pointer;" title="Delete or decommission aircraft">
            &times; Decommission / Delete Aircraft
          </button>
        </div>
      </div>
    </div>

    <div class="footer-note">
      JetLog Aircraft Profile &bull; Registration: <code><?= htmlspecialchars($aircraft['registration_number']) ?></code> &bull; All data synced live.
    </div>

  </main>

</div>

<!-- Modal: Update Aircraft Status -->
<div class="modal-overlay" id="statusModal">
  <div class="modal-box">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:14px;">
      <h3 style="margin:0; color:#0f172a;">Update Aircraft Status</h3>
      <button type="button" class="modal-close" onclick="closeStatusModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="aircraft-profile.php?reg=<?= urlencode($aircraft['registration_number']) ?>">
      <input type="hidden" name="action" value="change_status">
      
      <div class="form-row" style="margin-bottom:12px;">
        <label for="statusSelect">Maintenance Status</label>
        <select id="statusSelect" name="status" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
          <option value="Available" <?= $aircraft['status'] === 'Available' ? 'selected' : '' ?>>Available</option>
          <option value="Under Maintenance" <?= $aircraft['status'] === 'Under Maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
          <option value="Inspection Due" <?= $aircraft['status'] === 'Inspection Due' ? 'selected' : '' ?>>Inspection Due</option>
          <option value="AOG" <?= $aircraft['status'] === 'AOG' ? 'selected' : '' ?>>AOG (Aircraft On Ground)</option>
        </select>
      </div>

      <div class="form-row" style="margin-bottom:16px;">
        <label for="locationInput">Current Location</label>
        <input type="text" id="locationInput" name="location" value="<?= htmlspecialchars($aircraft['location'] ?? 'Main Hangar') ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeStatusModal()">Cancel</button>
        <button type="submit" class="btn small">Update Status</button>
      </div>
    </form>
  </div>
</div>

<script>
// Render QR Code immediately
document.addEventListener('DOMContentLoaded', function() {
  renderQrCode('aircraft-qr-box', '<?= htmlspecialchars($qrUrl) ?>', 140);
});

function openStatusModal() {
  document.getElementById('statusModal').style.display = 'flex';
}
function closeStatusModal() {
  document.getElementById('statusModal').style.display = 'none';
}
function openDeleteAircraftModal() {
  document.getElementById('deleteAircraftModal').style.display = 'flex';
}
function closeDeleteAircraftModal() {
  document.getElementById('deleteAircraftModal').style.display = 'none';
}
</script>

<!-- Modal: Decommission or Permanently Delete Aircraft -->
<div class="modal-overlay" id="deleteAircraftModal">
  <div class="modal-box" style="max-width:480px;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #fee2e2; padding-bottom:8px; margin-bottom:14px;">
      <h3 style="margin:0; color:#b91c1c;">Decommission or Delete Aircraft <?= htmlspecialchars($aircraft['registration_number']) ?></h3>
      <button type="button" class="modal-close" onclick="closeDeleteAircraftModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="aircraft-profile.php?reg=<?= urlencode($aircraft['registration_number']) ?>" onsubmit="return confirm('Please confirm your selected action for aircraft <?= htmlspecialchars($aircraft['registration_number']) ?>:');">
      <input type="hidden" name="action" value="delete_aircraft">

      <p style="font-size:13px; color:#475569; margin-bottom:16px;">
        Choose the desired removal mode for <strong><?= htmlspecialchars($aircraft['registration_number']) ?> (<?= htmlspecialchars($aircraft['model']) ?>)</strong>:
      </p>

      <div style="margin-bottom:14px; padding:12px; border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:normal;">
          <input type="radio" name="delete_mode" value="soft" checked style="margin-top:3px;">
          <div>
            <strong>Decommission Aircraft (Soft Delete - Recommended)</strong>
            <div style="font-size:12px; color:#64748b; margin-top:3px;">
              Sets status to <code>Decommissioned</code>. All historical flight records, work orders, and logbooks are preserved for CAAP/FAA aviation audit compliance.
            </div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:18px; padding:12px; border:1px solid #fecaca; border-radius:6px; background:#fef2f2;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:normal;">
          <input type="radio" name="delete_mode" value="hard" style="margin-top:3px;">
          <div>
            <strong style="color:#b91c1c;">Permanently Delete (Hard Purge)</strong>
            <div style="font-size:12px; color:#991b1b; margin-top:3px;">
              Permanently cascades and deletes this aircraft, all linked work orders, discrepancy records, and log entries from the MySQL database.
            </div>
          </div>
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeDeleteAircraftModal()">Cancel</button>
        <button type="submit" class="btn small btn-danger">Confirm Deletion</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
