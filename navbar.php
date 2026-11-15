<?php
/**
 * JetLog: Shared Sidebar Navigation Component
 */
require_once __DIR__ . '/auth.php';

if (!isset($currentUser)) {
    $currentUser = getCurrentUser() ?: [
        'username' => 'guest',
        'first_name' => 'Demo',
        'last_name' => 'Technician',
        'role' => 'Technician'
    ];
}
$activePage = $activePage ?? '';

$userIsAdmin = (function_exists('isAdmin') && isAdmin()) 
    || in_array(($currentUser['role'] ?? ''), ['Administrator', 'Maintenance Supervisor'], true);
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-logo" style="margin-bottom: 6px;">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#0056b3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block; vertical-align:middle;">
        <path d="M17.8 19.2L16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.3c.4-.2.6-.6.5-1.1z"/>
      </svg>
    </div>
    <div class="brand-name">JETLOG</div>
    <div class="brand-sub">Aircraft Maintenance Tracking</div>
  </div>
  
  <nav>
    <a href="dashboard.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="aircraft.php" class="<?= $activePage === 'aircraft' ? 'active' : '' ?>">Aircraft Registry</a>
    <a href="work-orders.php" class="<?= $activePage === 'work-orders' ? 'active' : '' ?>">Work Orders</a>
    <a href="schedule.php" class="<?= $activePage === 'schedule' ? 'active' : '' ?>">Maintenance Schedule</a>
    <a href="logbook.php" class="<?= $activePage === 'logbook' ? 'active' : '' ?>">Maintenance Logbook</a>
    <a href="reports.php" class="<?= $activePage === 'reports' ? 'active' : '' ?>">Reports and Analytics</a>
    <?php if ($userIsAdmin): ?>
      <a href="admin.php" class="<?= $activePage === 'admin' ? 'active' : '' ?>" style="font-weight:700; color:#0284c7;">Admin Panel</a>
    <?php endif; ?>
    <a href="logout.php" class="logout" onclick="return confirm('Are you sure you want to log out?');">Logout</a>
  </nav>

  <div class="sidebar-note">
    <div style="font-size: 11px; text-transform: uppercase; color: #888; font-weight: bold; margin-bottom: 3px;">Logged in as:</div>
    <strong style="color: #111; font-size: 13px;"><?= htmlspecialchars(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?></strong><br>
    <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-top: 4px;">
      <?= htmlspecialchars($currentUser['role'] ?? 'Technician') ?>
    </span>
    <div style="margin-top: 6px; font-size: 11px; color: #666;">
      @<?= htmlspecialchars($currentUser['username'] ?? 'user') ?>
    </div>
  </div>

  <?php $dbOk = function_exists('testDbConnection') ? testDbConnection() : false; ?>
  <?php if ($dbOk): ?>
    <div style="margin-top: 14px; padding: 8px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; font-size: 11px; color: #166534;">
      <span style="display:inline-block; width:8px; height:8px; background:#22c55e; border-radius:50%; margin-right:4px;"></span>
      <strong>Database:</strong> Connected (MySQL)
    </div>
  <?php else: ?>
    <div style="margin-top: 14px; padding: 8px 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; font-size: 11px; color: #991b1b;">
      <span style="display:inline-block; width:8px; height:8px; background:#ef4444; border-radius:50%; margin-right:4px;"></span>
      <strong>Database:</strong> Offline (<a href="setup_database.php" style="color:#b91c1c; text-decoration:underline;">Start MySQL</a>)
    </div>
  <?php endif; ?>

  <div style="margin-top: 12px; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px;">
    <div style="font-size: 11px; font-weight: bold; color: #475569; margin-bottom: 4px;">QR Quick Lookup:</div>
    <form method="GET" action="aircraft-profile.php" style="display:flex; gap:4px;">
      <input type="text" name="reg" placeholder="e.g. RP-C1234" style="width:100%; padding:4px 6px; font-size:12px; border:1px solid #cbd5e1; border-radius:3px;">
      <button type="submit" style="padding:4px 8px; font-size:11px; background:#0284c7; color:#fff; border:none; border-radius:3px; cursor:pointer;">Go</button>
    </form>
  </div>
</aside>
