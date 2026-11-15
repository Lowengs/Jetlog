<?php
/**
 * JetLog: Admin Panel & User Management
 * Manages user accounts, technician credentials, role assignments, and system access.
 */

require_once __DIR__ . '/auth.php';
requireAdmin();

$currentUser = getCurrentUser();
$activePage = 'admin';
$pdo = getDbConnection();
$flash = getFlashMessage();
$isAdmin = true;

// Handle Actions (Only for Administrators/Supervisors)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. ADD NEW USER
    if ($action === 'add_user') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $role = $_POST['role'] ?? 'Technician';
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? 'password123');
        $status = $_POST['status'] ?? 'Active';

        if (empty($username) || empty($email) || empty($firstName) || empty($lastName)) {
            setFlashMessage('error', 'All required fields (Username, Email, First Name, Last Name) must be filled.');
        } else {
            try {
                // Check duplicate
                $chk = $pdo->prepare('SELECT user_id FROM users WHERE username = :u OR email = :e LIMIT 1');
                $chk->execute([':u' => $username, ':e' => $email]);
                if ($chk->fetch()) {
                    setFlashMessage('error', 'A user with that username or email already exists.');
                } else {
                    $passHash = password_hash($password ?: 'password123', PASSWORD_BCRYPT);
                    $ins = $pdo->prepare('
                        INSERT INTO users (username, email, password_hash, first_name, last_name, role, phone, status)
                        VALUES (:u, :e, :p, :fn, :ln, :r, :ph, :s)
                    ');
                    $ins->execute([
                        ':u' => $username,
                        ':e' => $email,
                        ':p' => $passHash,
                        ':fn' => $firstName,
                        ':ln' => $lastName,
                        ':r' => $role,
                        ':ph' => $phone ?: '+63 917 000 0000',
                        ':s' => $status
                    ]);
                    setFlashMessage('success', "New user account '{$username}' ({$role}) created successfully.");
                }
            } catch (Exception $e) {
                setFlashMessage('error', 'Database Error: ' . $e->getMessage());
            }
        }
        header('Location: admin.php');
        exit;
    }

    // 2. UPDATE USER
    if ($action === 'edit_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $role = $_POST['role'] ?? 'Technician';
        $phone = trim($_POST['phone'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if ($userId <= 0) {
            setFlashMessage('error', 'Invalid user ID.');
        } else {
            try {
                $upd = $pdo->prepare('
                    UPDATE users 
                    SET first_name = :fn, last_name = :ln, role = :r, phone = :ph, status = :s
                    WHERE user_id = :id
                ');
                $upd->execute([
                    ':fn' => $firstName,
                    ':ln' => $lastName,
                    ':r' => $role,
                    ':ph' => $phone,
                    ':s' => $status,
                    ':id' => $userId
                ]);
                setFlashMessage('success', "User account ID #{$userId} successfully updated.");
            } catch (Exception $e) {
                setFlashMessage('error', 'Error updating user: ' . $e->getMessage());
            }
        }
        header('Location: admin.php');
        exit;
    }

    // 3. RESET PASSWORD
    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? 'password123');
        if ($userId > 0) {
            try {
                $hash = password_hash($newPass ?: 'password123', PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :p WHERE user_id = :id');
                $stmt->execute([':p' => $hash, ':id' => $userId]);
                setFlashMessage('success', "Password for user #{$userId} has been reset to: '{$newPass}'");
            } catch (Exception $e) {
                setFlashMessage('error', 'Error resetting password: ' . $e->getMessage());
            }
        }
        header('Location: admin.php');
        exit;
    }

    // 4. TOGGLE STATUS (Active <-> Inactive)
    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'Inactive';
        if ($userId > 0) {
            if ($userId === (int)($currentUser['user_id'] ?? 0)) {
                setFlashMessage('error', 'You cannot deactivate your own current logged-in account.');
            } else {
                try {
                    $stmt = $pdo->prepare('UPDATE users SET status = :s WHERE user_id = :id');
                    $stmt->execute([':s' => $newStatus, ':id' => $userId]);
                    setFlashMessage('success', "Account status changed to '{$newStatus}'.");
                } catch (Exception $e) {
                    setFlashMessage('error', 'Error toggling status: ' . $e->getMessage());
                }
            }
        }
        header('Location: admin.php');
        exit;
    }

    // 5. RESTORE / SEED ALL DEFAULT USERS
    if ($action === 'seed_default_users') {
        try {
            $defaultUsers = [
                [
                    'username' => 'admin',
                    'email' => 'admin@jetlog.com',
                    'first_name' => 'JetLog',
                    'last_name' => 'Administrator',
                    'role' => 'Administrator',
                    'phone' => '+63 917 111 0001',
                    'status' => 'Active'
                ],
                [
                    'username' => 'msantos',
                    'email' => 'msantos@jetlog.com',
                    'first_name' => 'M.',
                    'last_name' => 'Santos',
                    'role' => 'Lead Technician',
                    'phone' => '+63 917 111 0004',
                    'status' => 'Active'
                ],
                [
                    'username' => 'demo.technician',
                    'email' => 'technician@jetlog.com',
                    'first_name' => 'Demo',
                    'last_name' => 'Technician',
                    'role' => 'Technician',
                    'phone' => '+63 917 111 0002',
                    'status' => 'Active'
                ],
                [
                    'username' => 'acruz',
                    'email' => 'acruz@jetlog.com',
                    'first_name' => 'A.',
                    'last_name' => 'Cruz',
                    'role' => 'Technician',
                    'phone' => '+63 917 111 0003',
                    'status' => 'Active'
                ],
                [
                    'username' => 'jreyes',
                    'email' => 'jreyes@jetlog.com',
                    'first_name' => 'J.',
                    'last_name' => 'Reyes',
                    'role' => 'Avionics Specialist',
                    'phone' => '+63 917 111 0005',
                    'status' => 'Active'
                ],
                [
                    'username' => 'rdelrosario',
                    'email' => 'rdelrosario@jetlog.com',
                    'first_name' => 'R.',
                    'last_name' => 'Del Rosario',
                    'role' => 'Inspector',
                    'phone' => '+63 917 111 0006',
                    'status' => 'Active'
                ]
            ];

            $defaultHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // 'password'
            $addedCount = 0;

            foreach ($defaultUsers as $u) {
                // Check if user already exists
                $checkStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = :u OR email = :e LIMIT 1');
                $checkStmt->execute([':u' => $u['username'], ':e' => $u['email']]);
                if (!$checkStmt->fetch()) {
                    $insertStmt = $pdo->prepare('
                        INSERT INTO users (username, email, password_hash, first_name, last_name, role, phone, status)
                        VALUES (:u, :e, :p, :fn, :ln, :r, :ph, :s)
                    ');
                    $insertStmt->execute([
                        ':u' => $u['username'],
                        ':e' => $u['email'],
                        ':p' => $defaultHash,
                        ':fn' => $u['first_name'],
                        ':ln' => $u['last_name'],
                        ':r' => $u['role'],
                        ':ph' => $u['phone'],
                        ':s' => $u['status']
                    ]);
                    $addedCount++;
                }
            }

            setFlashMessage('success', "Seeding complete! Added {$addedCount} standard user account(s). Password for all is: password123 (or password)");
        } catch (Exception $e) {
            setFlashMessage('error', 'Error seeding users: ' . $e->getMessage());
        }
        header('Location: admin.php');
        exit;
    }
}

// Search and Filter parameters
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = 'SELECT * FROM users WHERE 1=1';
$params = [];

if (!empty($search)) {
    $sql .= ' AND (username LIKE :q OR email LIKE :q OR first_name LIKE :q OR last_name LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if (!empty($roleFilter)) {
    $sql .= ' AND role = :role';
    $params[':role'] = $roleFilter;
}
if (!empty($statusFilter)) {
    $sql .= ' AND status = :status';
    $params[':status'] = $statusFilter;
}

$sql .= ' ORDER BY user_id ASC';
$usersStmt = $pdo->prepare($sql);
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();

// Metrics
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE status="Active"')->fetchColumn();
$adminUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("Administrator", "Maintenance Supervisor")')->fetchColumn();
$techUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("Technician", "Lead Technician", "Avionics Specialist")')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Admin Panel & User Management</title>
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

  .role-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
  }
  .role-admin { background: #fee2e2; color: #991b1b; }
  .role-supervisor { background: #fef3c7; color: #92400e; }
  .role-lead { background: #e0e7ff; color: #3730a3; }
  .role-tech { background: #e0f2fe; color: #0369a1; }
  .role-avionics { background: #ede9fe; color: #5b21b6; }
  .role-inspector { background: #ccfbf1; color: #115e59; }

  .status-active { background: #dcfce7; color: #166534; font-weight: 600; }
  .status-inactive { background: #fee2e2; color: #991b1b; }
  .status-suspended { background: #ffedd5; color: #9a3412; }

  .user-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #0284c7;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 13px;
    vertical-align: middle;
    margin-right: 8px;
  }
  
  .filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
    background: #f8fafc;
    padding: 14px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    align-items: center;
  }

  .btn-sm {
    padding: 4px 8px;
    font-size: 11px;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    background: #fff;
    cursor: pointer;
    text-decoration: none;
    color: #334155;
    font-weight: 600;
  }
  .btn-sm:hover {
    background: #f1f5f9;
  }
  .btn-primary {
    background: #0284c7;
    color: #fff;
    border: none;
  }
  .btn-primary:hover {
    background: #0369a1;
  }

  /* Modals */
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .modal-card {
    background: #ffffff;
    border-radius: 10px;
    max-width: 520px;
    width: 100%;
    padding: 26px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
    max-height: 90vh;
    overflow-y: auto;
  }
  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .form-group {
    margin-bottom: 12px;
  }
  .form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 4px;
  }
  .form-control {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    font-size: 13px;
    box-sizing: border-box;
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
        <h1>Admin Panel &amp; User Directory</h1>
        <div class="breadcrumb">
          <a href="dashboard.php" style="color:#64748b; text-decoration:none;">Dashboard</a> &gt; Admin Panel
        </div>
      </div>
      <div style="display:flex; gap:8px;">
        <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Restore default system demo users (Admin, Lead Tech, Technicians)? Existing records will not be duplicated.');">
          <input type="hidden" name="action" value="seed_default_users">
          <button type="submit" class="btn" style="background:#475569; font-size:12px;">+ Restore 6 Demo Accounts</button>
        </form>
        <button type="button" class="btn" onclick="openAddModal()" style="font-size:12px;">+ Create New User</button>
      </div>
    </div>

    <!-- 4 KPI Cards -->
    <div class="card-grid">
      <div class="stat-card">
        <div class="stat-label">Total Users</div>
        <div class="stat-value"><?= $totalUsers ?></div>
        <div class="stat-hint">Registered accounts</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Active Users</div>
        <div class="stat-value" style="color:#16a34a;"><?= $activeUsers ?></div>
        <div class="stat-hint">Can log in</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Technicians &amp; Crew</div>
        <div class="stat-value" style="color:#0284c7;"><?= $techUsers ?></div>
        <div class="stat-hint">Active hangar staff</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Admins &amp; Supervisors</div>
        <div class="stat-value" style="color:#d97706;"><?= $adminUsers ?></div>
        <div class="stat-hint">Full management access</div>
      </div>
    </div>

    <!-- Filter and Search Bar -->
    <form method="GET" action="admin.php" class="filter-bar">
      <div style="flex:1; min-width:200px;">
        <input type="text" name="search" class="form-control" placeholder="Search by name, username, or email..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div style="width:180px;">
        <select name="role" class="form-control">
          <option value="">All Roles</option>
          <option value="Administrator" <?= $roleFilter === 'Administrator' ? 'selected' : '' ?>>Administrator</option>
          <option value="Maintenance Supervisor" <?= $roleFilter === 'Maintenance Supervisor' ? 'selected' : '' ?>>Maintenance Supervisor</option>
          <option value="Lead Technician" <?= $roleFilter === 'Lead Technician' ? 'selected' : '' ?>>Lead Technician</option>
          <option value="Technician" <?= $roleFilter === 'Technician' ? 'selected' : '' ?>>Technician</option>
          <option value="Avionics Specialist" <?= $roleFilter === 'Avionics Specialist' ? 'selected' : '' ?>>Avionics Specialist</option>
          <option value="Inspector" <?= $roleFilter === 'Inspector' ? 'selected' : '' ?>>Inspector</option>
        </select>
      </div>
      <div style="width:140px;">
        <select name="status" class="form-control">
          <option value="">All Statuses</option>
          <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
          <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="Suspended" <?= $statusFilter === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>
      </div>
      <button type="submit" class="btn small" style="cursor:pointer;">Filter</button>
      <?php if (!empty($search) || !empty($roleFilter) || !empty($statusFilter)): ?>
        <a href="admin.php" class="btn small btn-secondary">Reset</a>
      <?php endif; ?>
    </form>

    <!-- Users Table -->
    <div class="panel">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h2 style="margin:0;">User Accounts (<?= count($users) ?>)</h2>
        <span style="font-size:12px; color:#64748b;">Default password for seed accounts: <code>password123</code></span>
      </div>

      <table class="wire">
        <thead>
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Email</th>
            <th>Role</th>
            <th>Phone</th>
            <th>Status</th>
            <th>Last Login</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($users)): ?>
            <?php foreach ($users as $u): ?>
              <?php
                $roleClass = match($u['role']) {
                  'Administrator' => 'role-admin',
                  'Maintenance Supervisor' => 'role-supervisor',
                  'Lead Technician' => 'role-lead',
                  'Avionics Specialist' => 'role-avionics',
                  'Inspector' => 'role-inspector',
                  default => 'role-tech',
                };
                $statusClass = match($u['status']) {
                  'Active' => 'status-active',
                  'Inactive' => 'status-inactive',
                  default => 'status-suspended',
                };
                $initials = strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1));
              ?>
              <tr>
                <td>#<?= (int)$u['user_id'] ?></td>
                <td>
                  <div style="display:flex; align-items:center;">
                    <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                    <div>
                      <strong><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong><br>
                      <span style="font-size:11px; color:#64748b;">@<?= htmlspecialchars($u['username']) ?></span>
                    </div>
                  </div>
                </td>
                <td>
                  <a href="mailto:<?= htmlspecialchars($u['email']) ?>" style="color:#0284c7; text-decoration:none;">
                    <?= htmlspecialchars($u['email']) ?>
                  </a>
                </td>
                <td>
                  <span class="role-badge <?= $roleClass ?>">
                    <?= htmlspecialchars($u['role']) ?>
                  </span>
                </td>
                <td><?= htmlspecialchars($u['phone'] ?: '—') ?></td>
                <td>
                  <span class="role-badge <?= $statusClass ?>">
                    <?= htmlspecialchars($u['status']) ?>
                  </span>
                </td>
                <td style="font-size:12px; color:#64748b;">
                  <?= !empty($u['last_login']) ? date('M d, Y H:i', strtotime($u['last_login'])) : 'Never' ?>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <button type="button" class="btn-sm" onclick='openEditModal(<?= json_encode($u) ?>)'>Edit</button>
                  <button type="button" class="btn-sm" onclick="openResetModal(<?= (int)$u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">Reset Pass</button>
                  
                  <?php if ((int)$u['user_id'] !== (int)($currentUser['user_id'] ?? 0)): ?>
                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Toggle status for <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?');">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <input type="hidden" name="new_status" value="<?= $u['status'] === 'Active' ? 'Inactive' : 'Active' ?>">
                      <button type="submit" class="btn-sm" style="color: <?= $u['status'] === 'Active' ? '#dc2626' : '#16a34a' ?>;">
                        <?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" style="text-align:center; padding:30px; color:#64748b;">
                No user accounts found matching your filter criteria.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>

</div>

<!-- Modal: Add New User -->
<div class="modal-overlay" id="addUserModal">
  <div class="modal-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="margin:0; color:#0f172a;">Create New User Account</h3>
      <button type="button" class="modal-close" onclick="closeAddModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="add_user">

      <div class="form-grid">
        <div class="form-group">
          <label>First Name *</label>
          <input type="text" name="first_name" class="form-control" required placeholder="e.g. Juan">
        </div>
        <div class="form-group">
          <label>Last Name *</label>
          <input type="text" name="last_name" class="form-control" required placeholder="e.g. Dela Cruz">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Username *</label>
          <input type="text" name="username" class="form-control" required placeholder="e.g. jdelacruz">
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="email" class="form-control" required placeholder="e.g. jdelacruz@jetlog.com">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Role</label>
          <select name="role" class="form-control" required>
            <option value="Technician" selected>Technician</option>
            <option value="Lead Technician">Lead Technician</option>
            <option value="Avionics Specialist">Avionics Specialist</option>
            <option value="Inspector">Inspector</option>
            <option value="Maintenance Supervisor">Maintenance Supervisor</option>
            <option value="Administrator">Administrator</option>
          </select>
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="text" name="phone" class="form-control" placeholder="+63 917 123 4567">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Initial Password</label>
          <input type="text" name="password" class="form-control" value="password123">
        </div>
        <div class="form-group">
          <label>Account Status</label>
          <select name="status" class="form-control">
            <option value="Active" selected>Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Suspended">Suspended</option>
          </select>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
        <button type="button" class="btn-sm" onclick="closeAddModal()" style="padding:8px 14px;">Cancel</button>
        <button type="submit" class="btn small" style="padding:8px 16px;">Save User</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit User -->
<div class="modal-overlay" id="editUserModal">
  <div class="modal-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="margin:0; color:#0f172a;">Edit User Account</h3>
      <button type="button" class="modal-close" onclick="closeEditModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="user_id" id="edit_user_id">

      <div class="form-grid">
        <div class="form-group">
          <label>First Name</label>
          <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
        </div>
        <div class="form-group">
          <label>Last Name</label>
          <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Role</label>
          <select name="role" id="edit_role" class="form-control" required>
            <option value="Technician">Technician</option>
            <option value="Lead Technician">Lead Technician</option>
            <option value="Avionics Specialist">Avionics Specialist</option>
            <option value="Inspector">Inspector</option>
            <option value="Maintenance Supervisor">Maintenance Supervisor</option>
            <option value="Administrator">Administrator</option>
          </select>
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="text" name="phone" id="edit_phone" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label>Account Status</label>
        <select name="status" id="edit_status" class="form-control">
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
          <option value="Suspended">Suspended</option>
        </select>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
        <button type="button" class="btn-sm" onclick="closeEditModal()" style="padding:8px 14px;">Cancel</button>
        <button type="submit" class="btn small" style="padding:8px 16px;">Update User</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Reset Password -->
<div class="modal-overlay" id="resetPassModal">
  <div class="modal-card" style="max-width:380px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0; color:#0f172a;">Reset Password</h3>
      <button type="button" class="modal-close" onclick="closeResetModal()" title="Close">&times;</button>
    </div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="reset_user_id">
      
      <p style="font-size:13px; color:#64748b; margin-top:0;">
        Reset password for user: <strong id="reset_username" style="color:#0f172a;"></strong>
      </p>

      <div class="form-group">
        <label>New Temporary Password</label>
        <input type="text" name="new_password" class="form-control" value="password123" required>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
        <button type="button" class="btn-sm" onclick="closeResetModal()" style="padding:8px 14px;">Cancel</button>
        <button type="submit" class="btn small" style="padding:8px 16px;">Save New Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddModal() {
  document.getElementById('addUserModal').style.display = 'flex';
}
function closeAddModal() {
  document.getElementById('addUserModal').style.display = 'none';
}

function openEditModal(user) {
  document.getElementById('edit_user_id').value = user.user_id;
  document.getElementById('edit_first_name').value = user.first_name || '';
  document.getElementById('edit_last_name').value = user.last_name || '';
  document.getElementById('edit_role').value = user.role || 'Technician';
  document.getElementById('edit_phone').value = user.phone || '';
  document.getElementById('edit_status').value = user.status || 'Active';
  document.getElementById('editUserModal').style.display = 'flex';
}
function closeEditModal() {
  document.getElementById('editUserModal').style.display = 'none';
}

function openResetModal(userId, username) {
  document.getElementById('reset_user_id').value = userId;
  document.getElementById('reset_username').textContent = '@' + username;
  document.getElementById('resetPassModal').style.display = 'flex';
}
function closeResetModal() {
  document.getElementById('resetPassModal').style.display = 'none';
}
</script>

</body>
</html>
