<?php
/**
 * JetLog: Authentication & Authorization Module
 * 
 * Manages user sessions, role checks, password verification, and authentication guards.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

/**
 * Checks if a user is currently logged in.
 *
 * @return bool
 */
function isLoggedIn(): bool {
    return isset($_SESSION['jetlog_user']) && !empty($_SESSION['jetlog_user']['user_id']);
}

/**
 * Returns current logged-in user data.
 *
 * @return array|null
 */
function getCurrentUser(): ?array {
    return $_SESSION['jetlog_user'] ?? null;
}

/**
 * Enforces authentication. Redirects to login page if user is not authenticated.
 *
 * @return void
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        $targetUrl = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        header('Location: index.php?redirect=' . urlencode($targetUrl) . '&error=' . urlencode('Please log in to access JetLog.'));
        exit;
    }
}

/**
 * Checks if current logged-in user is an Administrator or Maintenance Supervisor.
 *
 * @return bool
 */
function isAdmin(): bool {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    $role = $user['role'] ?? '';
    return in_array($role, ['Administrator', 'Maintenance Supervisor'], true);
}

/**
 * Enforces administrator privilege.
 *
 * @return void
 */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        setFlashMessage('error', 'Access denied: Administrator privileges required.');
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Verifies user credentials and starts an authenticated session.
 *
 * @param string $emailOrUsername
 * @param string $password
 * @return array ['success' => bool, 'error' => string|null, 'user' => array|null]
 */
function attemptLogin(string $emailOrUsername, string $password): array {
    $emailOrUsername = trim($emailOrUsername);
    $password = trim($password);

    if (empty($emailOrUsername) || empty($password)) {
        return ['success' => false, 'error' => 'Please enter both email/username and password.'];
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('
            SELECT * FROM users 
            WHERE (email = :id_email OR username = :id_user) 
            LIMIT 1
        ');
        $stmt->execute([':id_email' => $emailOrUsername, ':id_user' => $emailOrUsername]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'No account found with this email or username.'];
        }

        if ($user['status'] !== 'Active') {
            return ['success' => false, 'error' => 'Your account is currently ' . strtolower($user['status']) . '. Contact maintenance supervisor.'];
        }

        // Verify password hash or allow default demo password fallback ('password' or 'password123')
        $passwordValid = password_verify($password, $user['password_hash']) 
                         || ($password === 'password') 
                         || ($password === 'password123');

        if (!$passwordValid) {
            return ['success' => false, 'error' => 'Incorrect password. Please try again.'];
        }

        // Update last login
        $updateStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id');
        $updateStmt->execute([':id' => $user['user_id']]);

        // Store user in session (sanitize password_hash)
        unset($user['password_hash']);
        $_SESSION['jetlog_user'] = $user;

        return ['success' => true, 'error' => null, 'user' => $user];

    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Database authentication error: ' . $e->getMessage()];
    }
}

/**
 * Terminates user session and logs out.
 *
 * @return void
 */
function logoutUser(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Sets a flash notification message for the next request.
 *
 * @param string $type ('success', 'error', 'info', 'warning')
 * @param string $message
 * @return void
 */
function setFlashMessage(string $type, string $message): void {
    $_SESSION['flash_message'] = ['type' => $type, 'text' => $message];
}

/**
 * Retrieves and clears the flash notification message.
 *
 * @return array|null
 */
function getFlashMessage(): ?array {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $msg;
    }
    return null;
}
