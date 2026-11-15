<?php
/**
 * JetLog: Discrepancy Reporting Form
 * Reference: discrepancy.html & Page 2 Quick Action
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$activePage = 'dashboard';
$pdo = getDbConnection();
$flash = getFlashMessage();

$preselectedAircraftId = !empty($_GET['aircraft_id']) ? (int)$_GET['aircraft_id'] : 0;

// Fetch aircraft for dropdown
$acStmt = $pdo->query('SELECT aircraft_id, registration_number, model, status FROM aircraft ORDER BY registration_number ASC');
$aircraftList = $acStmt->fetchAll();

// Fetch discrepancy categories
$catStmt = $pdo->query('SELECT category_id, category_name FROM discrepancy_categories ORDER BY category_id ASC');
$categories = $catStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report_discrepancy') {
    $aircraftId = (int)($_POST['aircraft_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $dateReported = $_POST['date_reported'] ?? date('Y-m-d');
    $createWorkOrder = isset($_POST['create_work_order']);

    if ($aircraftId <= 0 || $categoryId <= 0 || empty($description)) {
        setFlashMessage('error', 'Please select an aircraft, category, and enter discrepancy details.');
    } else {
        try {
            $pdo->beginTransaction();

            // Generate unique discrepancy number
            $countStmt = $pdo->query('SELECT COUNT(*) FROM discrepancies');
            $nextNum = str_pad(((int)$countStmt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
            $discNumber = "DISC-2026-{$nextNum}";

            // Insert Discrepancy
            $discStmt = $pdo->prepare('
                INSERT INTO discrepancies 
                (discrepancy_number, aircraft_id, category_id, description, priority, status, reported_by_user_id, date_reported)
                VALUES (:num, :aid, :cid, :desc, :prio, :status, :uid, :dt)
            ');
            $discStmt->execute([
                ':num' => $discNumber,
                ':aid' => $aircraftId,
                ':cid' => $categoryId,
                ':desc' => $description,
                ':prio' => $priority,
                ':status' => $createWorkOrder ? 'Assigned' : 'Reported',
                ':uid' => !empty($currentUser['user_id']) ? $currentUser['user_id'] : 1,
                ':dt' => $dateReported
            ]);
            $discrepancyId = (int)$pdo->lastInsertId();

            $createdWoId = null;
            if ($createWorkOrder) {
                // Generate Work Order Number
                $woCountStmt = $pdo->query('SELECT COUNT(*) FROM work_orders');
                $nextWoNum = str_pad(((int)$woCountStmt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
                $woNumber = "WO-2026-{$nextWoNum}";
                $woToken = "JETLOG-WO-2026-{$nextWoNum}-" . bin2hex(random_bytes(3));

                // Assign to current technician or default
                $techId = !empty($currentUser['user_id']) ? $currentUser['user_id'] : 1;
                $dueDate = date('Y-m-d', strtotime('+5 days'));

                $woStmt = $pdo->prepare('
                    INSERT INTO work_orders 
                    (work_order_number, aircraft_id, discrepancy_id, issue_summary, priority, assigned_technician_id, status, date_created, due_date, qr_code_token)
                    VALUES (:num, :aid, :did, :issue, :prio, :tid, "Open", :created, :due, :token)
                ');
                $woStmt->execute([
                    ':num' => $woNumber,
                    ':aid' => $aircraftId,
                    ':did' => $discrepancyId,
                    ':issue' => substr($description, 0, 200),
                    ':prio' => $priority,
                    ':tid' => $techId,
                    ':created' => $dateReported,
                    ':due' => $dueDate,
                    ':token' => $woToken
                ]);
                $createdWoId = (int)$pdo->lastInsertId();

                // Add to history
                $histStmt = $pdo->prepare('
                    INSERT INTO work_order_status_history (work_order_id, status, changed_by_user_id, remarks)
                    VALUES (:wid, "Reported", :uid, :rem)
                ');
                $histStmt->execute([
                    ':wid' => $createdWoId,
                    ':uid' => !empty($currentUser['user_id']) ? $currentUser['user_id'] : null,
                    ':rem' => 'Work order automatically opened upon discrepancy reporting.'
                ]);

                // Update aircraft status if High or Critical
                if (in_array($priority, ['High', 'Critical'])) {
                    $acUpd = $pdo->prepare('UPDATE aircraft SET status = "Under Maintenance" WHERE aircraft_id = :id');
                    $acUpd->execute([':id' => $aircraftId]);
                }
            }

            $pdo->commit();

            if ($createdWoId) {
                setFlashMessage('success', "Discrepancy {$discNumber} filed and Work Order {$woNumber} created successfully!");
                header("Location: work-order-details.php?id={$createdWoId}");
                exit;
            } else {
                setFlashMessage('success', "Discrepancy {$discNumber} has been logged successfully.");
                header('Location: dashboard.php');
                exit;
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Error filing discrepancy: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Discrepancy Reporting</title>
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
  .form-card {
    background: #fff;
    border: 1px solid #999;
    padding: 24px;
    margin-bottom: 20px;
    max-width: 680px;
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

    <div class="page-header">
      <h1>Report Discrepancy</h1>
      <div class="breadcrumb">
        <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Report Discrepancy
      </div>
    </div>

    <div class="panel form-card">
      <h2 style="margin-top:0;">Discrepancy Report Form</h2>
      <p style="color:#64748b; font-size:13px; margin-bottom:20px;">
        File an observed airframe, powerplant, avionics, or systems fault directly to the active fleet maintenance log.
      </p>

      <form method="POST" action="discrepancy.php">
        <input type="hidden" name="action" value="report_discrepancy">

        <div class="form-row">
          <label for="aircraft_id">Aircraft *</label>
          <select id="aircraft_id" name="aircraft_id" required style="width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:4px;">
            <option value="">-- Select Fleet Aircraft --</option>
            <?php foreach ($aircraftList as $ac): ?>
              <option value="<?= (int)$ac['aircraft_id'] ?>" <?= ($preselectedAircraftId === (int)$ac['aircraft_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($ac['registration_number']) ?> &ndash; <?= htmlspecialchars($ac['model']) ?> (<?= htmlspecialchars($ac['status']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row" style="margin-top:14px;">
          <label for="category_id">Discrepancy Category *</label>
          <select id="category_id" name="category_id" required style="width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:4px;">
            <option value="">-- Select Category --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['category_id'] ?>">
                <?= htmlspecialchars($cat['category_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row" style="margin-top:14px;">
          <label for="description">Fault / Defect Description *</label>
          <textarea id="description" name="description" rows="4" placeholder="Describe the discrepancy observed during flight, walkaround, or turnaround inspection..." required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;"></textarea>
        </div>

        <div class="form-row" style="margin-top:14px;">
          <label for="priority">Priority Level</label>
          <select id="priority" name="priority" style="width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:4px;">
            <option value="Low">Low (Non-essential deferred item)</option>
            <option value="Medium" selected>Medium (Standard scheduled repair)</option>
            <option value="High">High (Immediate hangar attention required)</option>
            <option value="Critical">Critical (Flight safety / AOG condition)</option>
          </select>
        </div>

        <div class="form-row" style="margin-top:14px;">
          <label for="date_reported">Date Reported</label>
          <input type="date" id="date_reported" name="date_reported" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>

        <div class="form-row" style="margin-top:14px;">
          <label for="reported_by">Reported By</label>
          <input type="text" id="reported_by" value="<?= htmlspecialchars(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?> (<?= htmlspecialchars($currentUser['role'] ?? '') ?>)" readonly style="width:100%; padding:9px; border:1px solid #e2e8f0; background:#f8fafc; border-radius:4px; color:#64748b;">
        </div>

        <div class="form-row" style="margin-top:18px; padding:12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:4px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal; margin:0;">
            <input type="checkbox" name="create_work_order" value="1" checked style="width:18px; height:18px;">
            <span><strong>Automatically create active Work Order</strong> and assign to duty hangar queue</span>
          </label>
        </div>

        <div class="form-row" style="margin-top:22px; display:flex; gap:10px;">
          <button type="submit" class="btn" style="background:#0284c7; color:#fff; border-color:#0284c7; padding:10px 22px; font-weight:600; cursor:pointer;">
            Submit Report &rarr;
          </button>
          <a class="btn" href="dashboard.php" style="background:#e2e8f0; color:#334155; text-decoration:none;">Cancel</a>
        </div>

      </form>
    </div>

    <div class="footer-note">
      JetLog Discrepancy System &bull; Active DB sync to tables <code>discrepancies</code> &amp; <code>work_orders</code>.
    </div>

  </main>

</div>

</body>
</html>
