<?php
/**
 * JetLog: Database Connection & Helper Functions
 * 
 * Uses PHP PDO for secure, prepared-statement interactions with MySQL/MariaDB in XAMPP.
 */

// Database configuration settings (Default XAMPP credentials)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'jetlog_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a shared PDO database connection instance.
 *
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Provide informative error message without exposing credentials
            error_log('Database Connection Error: ' . $e->getMessage());
            throw new Exception("Unable to connect to JetLog database '" . DB_NAME . "'. Please ensure MySQL is running in XAMPP.");
        }
    }

    return $pdo;
}

/**
 * Quick helper to test database connectivity.
 *
 * @return bool
 */
function testDbConnection(): bool {
    try {
        $pdo = getDbConnection();
        return ($pdo !== null);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Fetches dashboard KPI cards data matching Page 2 of the JetLog wireframes.
 *
 * @return array
 */
function getDashboardMetrics(): array {
    $pdo = getDbConnection();
    $stmt = $pdo->query('SELECT * FROM vw_dashboard_stats');
    return $stmt->fetch() ?: [
        'total_aircraft'           => 0,
        'available_aircraft'       => 0,
        'maintenance_aircraft'     => 0,
        'inspection_due_aircraft'  => 0,
        'open_work_orders'         => 0,
        'completed_work_orders'    => 0,
        'total_work_orders'        => 0,
        'overdue_tasks'            => 0,
        'upcoming_inspections'     => 0,
    ];
}

/**
 * Fetches recent work orders for the dashboard table.
 *
 * @param int $limit
 * @return array
 */
function getRecentWorkOrders(int $limit = 4): array {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM vw_recent_work_orders LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Fetches upcoming maintenance schedule tasks.
 *
 * @param int $limit
 * @return array
 */
function getUpcomingMaintenance(int $limit = 5): array {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM vw_upcoming_maintenance LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Fetches aircraft with optional filters (Search, Status, Manufacturer).
 * Matches Page 3 (Aircraft Registry).
 *
 * @param string|null $search
 * @param string|null $status
 * @param string|null $manufacturer
 * @return array
 */
function getAircraftList(?string $search = null, ?string $status = null, ?string $manufacturer = null): array {
    $pdo = getDbConnection();
    $sql = 'SELECT * FROM aircraft WHERE 1=1';
    $params = [];

    if (!empty($search)) {
        $sql .= ' AND (registration_number LIKE :search_reg OR model LIKE :search_model)';
        $params[':search_reg'] = '%' . $search . '%';
        $params[':search_model'] = '%' . $search . '%';
    }

    if (!empty($status) && $status !== 'All Statuses') {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    if (!empty($manufacturer) && $manufacturer !== 'All Manufacturers') {
        $sql .= ' AND manufacturer = :manufacturer';
        $params[':manufacturer'] = $manufacturer;
    }

    $sql .= ' ORDER BY (registration_number = "RP-C1234") DESC, registration_number ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
