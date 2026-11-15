<?php
/**
 * JetLog: Live Aircraft Registry
 * Reference: Page 3 of System Specification Document
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'aircraft';
$pdo = getDbConnection();
$flash = getFlashMessage();

// Handle POST to Add Aircraft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_aircraft') {
    $reg = strtoupper(trim($_POST['registration_number'] ?? ''));
    $model = trim($_POST['model'] ?? '');
    $mfg = trim($_POST['manufacturer'] ?? '');
    $status = $_POST['status'] ?? 'Available';
    $serial = trim($_POST['serial_number'] ?? '');
    $year = !empty($_POST['year_manufactured']) ? (int)$_POST['year_manufactured'] : null;
    $hours = !empty($_POST['total_flight_hours']) ? (float)$_POST['total_flight_hours'] : 0.00;
    $location = trim($_POST['location'] ?? 'Hangar 1');

    if (empty($reg) || empty($model) || empty($mfg)) {
        setFlashMessage('error', 'Registration number, model, and manufacturer are required.');
    } else {
        try {
            // Check for duplicate registration
            $checkStmt = $pdo->prepare('SELECT aircraft_id FROM aircraft WHERE registration_number = :reg');
            $checkStmt->execute([':reg' => $reg]);
            if ($checkStmt->fetch()) {
                setFlashMessage('error', "Aircraft with registration '{$reg}' already exists in the registry.");
            } else {
                $qrToken = "JETLOG-AIRCRAFT-{$reg}-" . bin2hex(random_bytes(4));
                $insertStmt = $pdo->prepare('
                    INSERT INTO aircraft 
                    (registration_number, model, manufacturer, status, serial_number, year_manufactured, total_flight_hours, location, qr_code_token)
                    VALUES (:reg, :model, :mfg, :status, :serial, :year, :hours, :loc, :qr)
                ');
                $insertStmt->execute([
                    ':reg' => $reg,
                    ':model' => $model,
                    ':mfg' => $mfg,
                    ':status' => $status,
                    ':serial' => $serial ?: null,
                    ':year' => $year,
                    ':hours' => $hours,
                    ':loc' => $location,
                    ':qr' => $qrToken
                ]);
                setFlashMessage('success', "Aircraft {$reg} ({$model}) has been successfully added to the registry.");
                header('Location: aircraft.php');
                exit;
            }
        } catch (Exception $e) {
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }
    }
}

// Handle Delete Aircraft POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_aircraft') {
    $delId = (int)($_POST['aircraft_id'] ?? 0);
    $delReg = trim($_POST['registration_number'] ?? '');
    $delMode = $_POST['delete_mode'] ?? 'soft';
    if ($delId > 0) {
        try {
            if ($delMode === 'hard') {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM maintenance_logbook WHERE aircraft_id = :id')->execute([':id' => $delId]);
                $pdo->prepare('DELETE FROM maintenance_schedules WHERE aircraft_id = :id')->execute([':id' => $delId]);
                $pdo->prepare('DELETE FROM work_order_status_history WHERE work_order_id IN (SELECT work_order_id FROM work_orders WHERE aircraft_id = :id)')->execute([':id' => $delId]);
                $pdo->prepare('DELETE FROM work_orders WHERE aircraft_id = :id')->execute([':id' => $delId]);
                $pdo->prepare('DELETE FROM discrepancies WHERE aircraft_id = :id')->execute([':id' => $delId]);
                $pdo->prepare('DELETE FROM aircraft WHERE aircraft_id = :id')->execute([':id' => $delId]);
                $pdo->commit();
                setFlashMessage('success', "Aircraft {$delReg} and all associated records have been permanently deleted from the database.");
            } else {
                $pdo->prepare('UPDATE aircraft SET status = "Decommissioned" WHERE aircraft_id = :id')->execute([':id' => $delId]);
                setFlashMessage('success', "Aircraft {$delReg} has been decommissioned (retired from service).");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlashMessage('error', 'Error deleting aircraft: ' . $e->getMessage());
        }
    }
    header('Location: aircraft.php');
    exit;
}

// Read filter parameters
$searchQuery = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? 'All Statuses');
$filterMfg = trim($_GET['manufacturer'] ?? 'All Manufacturers');

// Fetch distinct manufacturers for dropdown
$mfgStmt = $pdo->query('SELECT DISTINCT manufacturer FROM aircraft ORDER BY manufacturer ASC');
$manufacturers = $mfgStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch filtered aircraft list
$aircraftList = getAircraftList($searchQuery, $filterStatus, $filterMfg);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Aircraft Registry</title>
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
  
  /* Modal styling */
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
    background: #ffffff;
    border-radius: 8px;
    max-width: 540px;
    width: 100%;
    padding: 24px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
    max-height: 90vh;
    overflow-y: auto;
  }
  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
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
        <h1>Aircraft Registry</h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Aircraft Registry
        </div>
      </div>
      <div>
        <button class="btn" onclick="openAddModal()" style="background:#0284c7; color:#fff; border-color:#0284c7; cursor:pointer;">
          + Add Aircraft
        </button>
      </div>
    </div>

    <!-- Search and Filter Form (Fully Functional) -->
    <div class="panel">
      <h2>Search and Filter</h2>
      <form method="GET" action="aircraft.php" class="filter-bar">
        <div class="filter-item">
          <label for="search">Search</label>
          <input type="text" id="search" name="search" placeholder="Registration or model" value="<?= htmlspecialchars($searchQuery) ?>">
        </div>
        <div class="filter-item">
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="All Statuses" <?= $filterStatus === 'All Statuses' ? 'selected' : '' ?>>All Statuses</option>
            <option value="Available" <?= $filterStatus === 'Available' ? 'selected' : '' ?>>Available</option>
            <option value="Under Maintenance" <?= $filterStatus === 'Under Maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
            <option value="Inspection Due" <?= $filterStatus === 'Inspection Due' ? 'selected' : '' ?>>Inspection Due</option>
            <option value="Decommissioned" <?= $filterStatus === 'Decommissioned' ? 'selected' : '' ?>>Decommissioned</option>
          </select>
        </div>
        <div class="filter-item">
          <label for="manufacturer">Manufacturer</label>
          <select id="manufacturer" name="manufacturer">
            <option value="All Manufacturers" <?= $filterMfg === 'All Manufacturers' ? 'selected' : '' ?>>All Manufacturers</option>
            <?php foreach ($manufacturers as $mfg): ?>
              <option value="<?= htmlspecialchars($mfg) ?>" <?= $filterMfg === $mfg ? 'selected' : '' ?>>
                <?= htmlspecialchars($mfg) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-item" style="display:flex; gap:6px; align-items:flex-end;">
          <button type="submit" class="btn small" style="cursor:pointer;">Search</button>
          <?php if (!empty($searchQuery) || $filterStatus !== 'All Statuses' || $filterMfg !== 'All Manufacturers'): ?>
            <a href="aircraft.php" class="btn small btn-secondary">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Aircraft List (Live Database Records) -->
    <div class="panel">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 style="margin:0; border:none;">Aircraft Fleet (<?= count($aircraftList) ?> Found)</h2>
        <span style="font-size:12px; color:#64748b;">Click <strong>View</strong> to open aircraft profile &amp; QR access</span>
      </div>
      <table class="wire">
        <thead>
          <tr>
            <th>Registration</th>
            <th>Model</th>
            <th>Manufacturer</th>
            <th>Status</th>
            <th>Location</th>
            <th>Flight Hours</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($aircraftList)): ?>
            <?php foreach ($aircraftList as $ac): ?>
              <tr>
                <td><strong><?= htmlspecialchars($ac['registration_number']) ?></strong></td>
                <td><?= htmlspecialchars($ac['model']) ?></td>
                <td><?= htmlspecialchars($ac['manufacturer']) ?></td>
                <td>
                  <?php
                    $acClass = match($ac['status']) {
                      'Under Maintenance' => 'status-maintenance',
                      'Inspection Due' => 'status-inspection',
                      'Decommissioned' => 'status-decommissioned',
                      default => 'status-available',
                    };
                  ?>
                  <span class="status <?= $acClass ?>"><?= htmlspecialchars($ac['status']) ?></span>
                </td>
                <td><?= htmlspecialchars($ac['location'] ?? 'Main Hangar') ?></td>
                <td><?= number_format((float)$ac['total_flight_hours'], 1) ?> hrs</td>
                <td style="white-space:nowrap;">
                  <a href="aircraft-profile.php?reg=<?= urlencode($ac['registration_number']) ?>" style="font-weight:600; color:#0284c7; margin-right:8px;">
                    View &rarr;
                  </a>
                  <form method="POST" action="aircraft.php" style="display:inline;" onsubmit="return confirm('Decommission aircraft <?= htmlspecialchars($ac['registration_number']) ?>?\n\nClick OK to retire this aircraft from active service.');">
                    <input type="hidden" name="action" value="delete_aircraft">
                    <input type="hidden" name="aircraft_id" value="<?= (int)$ac['aircraft_id'] ?>">
                    <input type="hidden" name="registration_number" value="<?= htmlspecialchars($ac['registration_number']) ?>">
                    <input type="hidden" name="delete_mode" value="soft">
                    <button type="submit" class="btn small" style="background:#ef4444; color:#fff; border-color:#ef4444; font-size:11px; padding:3px 6px; cursor:pointer;" title="Decommission / Retire Aircraft">
                      &times; Retire
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" style="text-align:center; color:#64748b; padding:20px;">
                No aircraft found matching current search criteria.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="panel-actions">
        <button class="btn" onclick="openAddModal()" style="cursor:pointer;">+ Add Aircraft</button>
      </div>
    </div>

    <div class="footer-note">
      JetLog Aircraft Registry &bull; Total records queried from table <code>aircraft</code> &bull; All updates reflected fleet-wide.
    </div>

  </main>

</div>

<!-- Modal: Add Aircraft -->
<div class="modal-overlay" id="addAircraftModal">
  <div class="modal-box">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:14px;">
      <h3 style="margin:0; color:#0f172a;">Register New Aircraft</h3>
      <button type="button" class="modal-close" onclick="closeAddModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="aircraft.php">
      <input type="hidden" name="action" value="add_aircraft">

      <div class="form-grid">
        <div class="form-row">
          <label for="add_reg">Registration Number *</label>
          <input type="text" id="add_reg" name="registration_number" placeholder="e.g. RP-C8888" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
        <div class="form-row">
          <label for="add_model">Model *</label>
          <input type="text" id="add_model" name="model" placeholder="e.g. Cessna 172" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
      </div>

      <div class="form-grid" style="margin-top:10px;">
        <div class="form-row">
          <label for="add_mfg">Manufacturer *</label>
          <input type="text" id="add_mfg" name="manufacturer" placeholder="e.g. Cessna" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
        <div class="form-row">
          <label for="add_status">Status</label>
          <select id="add_status" name="status" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
            <option value="Available">Available</option>
            <option value="Under Maintenance">Under Maintenance</option>
            <option value="Inspection Due">Inspection Due</option>
          </select>
        </div>
      </div>

      <div class="form-grid" style="margin-top:10px;">
        <div class="form-row">
          <label for="add_serial">Serial Number</label>
          <input type="text" id="add_serial" name="serial_number" placeholder="e.g. 172S-1099" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
        <div class="form-row">
          <label for="add_year">Year Manufactured</label>
          <input type="number" id="add_year" name="year_manufactured" placeholder="2020" min="1950" max="2030" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
      </div>

      <div class="form-grid" style="margin-top:10px;">
        <div class="form-row">
          <label for="add_hours">Total Flight Hours</label>
          <input type="number" step="0.1" id="add_hours" name="total_flight_hours" placeholder="125.0" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
        <div class="form-row">
          <label for="add_location">Location / Hangar</label>
          <input type="text" id="add_location" name="location" placeholder="Hangar 1 - Bay A" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeAddModal()">Cancel</button>
        <button type="submit" class="btn small">Save Aircraft</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddModal() {
  document.getElementById('addAircraftModal').style.display = 'flex';
}
function closeAddModal() {
  document.getElementById('addAircraftModal').style.display = 'none';
}
</script>

</body>
</html>
