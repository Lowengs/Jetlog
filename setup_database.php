<?php
/**
 * JetLog: Database Setup & Migration Script
 *
 * Runs the database creation and seeding process from jetlog_db.sql.
 * Supports both CLI (`php setup_database.php`) and Web Browser (`http://localhost/JetLog/setup_database.php`).
 */

$isCli = (php_sapi_name() === 'cli');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = 'jetlog_db';
$sqlFile = __DIR__ . '/jetlog_db.sql';

$outputMessages = [];
$statusSuccess = false;
$tableStats = [];

function logMsg(string $msg, string $type = 'info'): void {
    global $outputMessages, $isCli;
    $outputMessages[] = ['text' => $msg, 'type' => $type];
    if ($isCli) {
        $prefix = match ($type) {
            'success' => '[SUCCESS] ',
            'error'   => '[ERROR] ',
            'warning' => '[WARN] ',
            default   => '[INFO] ',
        };
        echo $prefix . $msg . PHP_EOL;
    }
}

try {
    logMsg("Attempting connection to MySQL server at {$dbHost}:{$dbPort} as user '{$dbUser}'...");
    
    // Connect to MySQL server without database specified first
    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    logMsg("Connected to MySQL server successfully.", "success");

    // Check if SQL file exists
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found at: {$sqlFile}");
    }
    logMsg("Found SQL file: " . basename($sqlFile));

    // Read SQL file content
    $sqlContent = file_get_contents($sqlFile);
    if ($sqlContent === false || strlen($sqlContent) === 0) {
        throw new Exception("SQL file is empty or cannot be read.");
    }
    logMsg("SQL file loaded (" . number_format(strlen($sqlContent)) . " bytes).");

    logMsg("Executing database schema and seed commands...");
    $pdo->exec($sqlContent);
    logMsg("SQL commands executed successfully.", "success");

    // Reconnect explicitly to the newly created database
    $pdoDb = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Verify all created tables and record counts
    $tables = [
        'users' => 'Users / Technicians / Admins',
        'password_resets' => 'Password Reset Requests',
        'aircraft' => 'Aircraft Fleet Registry',
        'discrepancy_categories' => 'Discrepancy Categories',
        'discrepancies' => 'Reported Discrepancies',
        'work_orders' => 'Maintenance Work Orders',
        'work_order_status_history' => 'Work Order Status Flow History',
        'maintenance_schedules' => 'Scheduled Inspections & Tasks',
        'maintenance_logbook' => 'Digital Maintenance Logbook'
    ];

    foreach ($tables as $table => $desc) {
        $stmt = $pdoDb->query("SELECT COUNT(*) AS cnt FROM `{$table}`");
        $count = $stmt->fetchColumn();
        $tableStats[$table] = ['desc' => $desc, 'count' => (int)$count];
        logMsg("Verified table `{$table}` ({$desc}): {$count} records", "success");
    }

    // Verify view
    $viewStmt = $pdoDb->query("SELECT * FROM vw_dashboard_stats");
    $dashboardData = $viewStmt->fetch();
    logMsg("Verified view `vw_dashboard_stats`: Total Aircraft={$dashboardData['total_aircraft']}, Available={$dashboardData['available_aircraft']}, Maint={$dashboardData['maintenance_aircraft']}, Open WOs={$dashboardData['open_work_orders']}", "success");

    $statusSuccess = true;
    logMsg("JetLog database installation and verification complete!", "success");

} catch (PDOException $e) {
    logMsg("Database Error: " . $e->getMessage(), "error");
    if (str_contains($e->getMessage(), '2002') || str_contains($e->getMessage(), 'Connection refused')) {
        logMsg("Hint: Ensure the MySQL service is started in your XAMPP Control Panel.", "warning");
    }
} catch (Exception $e) {
    logMsg("Setup Error: " . $e->getMessage(), "error");
}

if ($isCli) {
    exit($statusSuccess ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JetLog Database Setup</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .setup-container {
            max-width: 800px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #dcdfe6;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 30px;
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 2px solid #eef1f6;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .header-title h1 {
            margin: 0;
            font-size: 24px;
            color: #1a2a3a;
        }
        .log-box {
            background: #181d24;
            color: #ecf0f1;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            padding: 16px;
            border-radius: 6px;
            max-height: 280px;
            overflow-y: auto;
            margin-bottom: 24px;
        }
        .log-line {
            padding: 2px 0;
        }
        .log-info { color: #8ab4f8; }
        .log-success { color: #81c995; }
        .log-warning { color: #fdd663; }
        .log-error { color: #f28b82; font-weight: bold; }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        .stats-table th, .stats-table td {
            border: 1px solid #e0e4e9;
            padding: 10px 14px;
            text-align: left;
        }
        .stats-table th {
            background: #f8fafc;
            color: #4a5568;
            font-weight: 600;
        }
        .badge-count {
            display: inline-block;
            background: #e8f0fe;
            color: #1967d2;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .success-banner {
            background: #e6f4ea;
            border: 1px solid #ceead6;
            color: #137333;
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .error-banner {
            background: #fce8e6;
            border: 1px solid #fad2cf;
            color: #c5221f;
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .nav-links {
            margin-top: 24px;
            display: flex;
            gap: 12px;
        }
    </style>
</head>
<body>

<div class="setup-container">
    <div class="header-title">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17.8 19.2L16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.3c.4-.2.6-.6.5-1.1z"/>
        </svg>
        <div>
            <h1>JetLog Database Installer</h1>
            <div style="color: #64748b; font-size: 13px;">Aircraft Maintenance Tracking &amp; Management System</div>
        </div>
    </div>

    <?php if ($statusSuccess): ?>
        <div class="success-banner">
            <strong>Database Installed Successfully!</strong> The database <code><?= htmlspecialchars($dbName) ?></code> has been created and populated with fleet data matching all wireframes.
        </div>
    <?php else: ?>
        <div class="error-banner">
            <strong>Installation could not be completed.</strong> Please check the execution log below for diagnostic details.
        </div>
    <?php endif; ?>

    <h3>Execution Log</h3>
    <div class="log-box">
        <?php foreach ($outputMessages as $item): ?>
            <div class="log-line log-<?= htmlspecialchars($item['type']) ?>">
                <?= htmlspecialchars($item['text']) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($tableStats)): ?>
        <h3>Installed Database Tables</h3>
        <table class="stats-table">
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Description</th>
                    <th>Records Seeded</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableStats as $tableName => $info): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($tableName) ?></code></td>
                        <td><?= htmlspecialchars($info['desc']) ?></td>
                        <td><span class="badge-count"><?= $info['count'] ?> records</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="nav-links">
        <a class="btn" href="dashboard.php">Go to Dashboard</a>
        <a class="btn btn-secondary" href="setup_database.php">Re-run Database Setup</a>
    </div>
</div>

</body>
</html>
