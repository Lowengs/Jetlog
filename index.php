<?php
/**
 * JetLog: Login & Authentication Page
 * Reference: Page 1 of System Specification Document
 */

require_once __DIR__ . '/auth.php';

// If already logged in, redirect straight to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = $_GET['error'] ?? '';
$successMessage = $_GET['msg'] ?? '';
$emailInput = '';

// Handle POST login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $emailInput = trim($_POST['email'] ?? '');
    $passwordInput = trim($_POST['password'] ?? '');

    $result = attemptLogin($emailInput, $passwordInput);
    if ($result['success']) {
        $redirectTo = $_POST['redirect'] ?? 'dashboard.php';
        header('Location: ' . $redirectTo);
        exit;
    } else {
        $errorMessage = $result['error'];
    }
}

// Handle POST forgot password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $forgotEmail = trim($_POST['forgot_email'] ?? '');
    if (!empty($forgotEmail)) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('SELECT user_id, first_name FROM users WHERE email = :email');
            $stmt->execute([':email' => $forgotEmail]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(24));
                $insertStmt = $pdo->prepare('
                    INSERT INTO password_resets (email, token, expires_at) 
                    VALUES (:email, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))
                ');
                $insertStmt->execute([':email' => $forgotEmail, ':token' => $token]);
                $successMessage = "Password reset instructions and token have been issued for {$forgotEmail}. Token: {$token}";
            } else {
                $errorMessage = "No account found registered with {$forgotEmail}.";
            }
        } catch (Exception $e) {
            $errorMessage = "Database error: " . $e->getMessage();
        }
    }
}

// Handle POST technician self-registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_technician') {
    $firstName = trim($_POST['reg_first_name'] ?? '');
    $lastName = trim($_POST['reg_last_name'] ?? '');
    $username = strtolower(trim($_POST['reg_username'] ?? ''));
    $email = strtolower(trim($_POST['reg_email'] ?? ''));
    $phone = trim($_POST['reg_phone'] ?? '');
    $role = trim($_POST['reg_role'] ?? 'Technician');
    $password = trim($_POST['reg_password'] ?? '');
    $confirmPassword = trim($_POST['reg_password_confirm'] ?? '');

    if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
        $errorMessage = 'Please complete all required fields (Name, Email, and Password).';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'Passwords do not match. Please re-enter your password.';
    } elseif (strlen($password) < 4) {
        $errorMessage = 'Password must be at least 4 characters long.';
    } else {
        if (empty($username)) {
            $username = strtolower(substr($firstName, 0, 1) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        }

        try {
            $pdo = getDbConnection();
            if (!$pdo) {
                throw new Exception('Database connection unavailable. Please check that MySQL is running in XAMPP.');
            }
            $checkStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = :u OR email = :e LIMIT 1');
            $checkStmt->execute([':u' => $username, ':e' => $email]);
            if ($checkStmt->fetch()) {
                $errorMessage = "An account with username '{$username}' or email '{$email}' already exists.";
            } else {
                $passHash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $pdo->prepare('
                    INSERT INTO users (username, email, password_hash, first_name, last_name, role, phone, status)
                    VALUES (:u, :e, :p, :fn, :ln, :r, :ph, "Active")
                ');
                $ins->execute([
                    ':u' => $username,
                    ':e' => $email,
                    ':p' => $passHash,
                    ':fn' => $firstName,
                    ':ln' => $lastName,
                    ':r' => $role,
                    ':ph' => $phone ?: '+63 917 000 0000'
                ]);

                // Auto-login newly registered technician
                $loginRes = attemptLogin($email, $password);
                if ($loginRes['success']) {
                    header('Location: dashboard.php?welcome=' . urlencode($firstName));
                    exit;
                } else {
                    $successMessage = "Technician account '{$username}' successfully created! Please log in below.";
                    $emailInput = $email;
                }
            }
        } catch (Exception $e) {
            $errorMessage = 'Registration error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JetLog: Aircraft Maintenance Login</title>
<link rel="stylesheet" href="styles.css">
<style>
  body {
    background: #0f172a;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #334155;
    margin: 0;
    padding: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }
  .login-page-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.85) 0%, rgba(30, 58, 138, 0.75) 100%),
                radial-gradient(circle at top right, #1e3a8a, transparent 50%),
                #0f172a;
  }
  .login-card {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 20px 35px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
    width: 100%;
    max-width: 480px;
    padding: 38px 34px;
    box-sizing: border-box;
  }
  .login-logo {
    text-align: center;
    margin-bottom: 24px;
  }
  .login-logo svg {
    color: #0284c7;
    filter: drop-shadow(0 4px 6px rgba(2, 132, 199, 0.25));
  }
  .login-title {
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: 2px;
    margin: 8px 0 2px 0;
  }
  .login-subtitle {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
  }
  .form-group {
    margin-bottom: 18px;
    text-align: left;
  }
  .form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
  }
  .form-input {
    width: 100%;
    padding: 11px 14px;
    font-size: 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    box-sizing: border-box;
    transition: all 0.2s ease;
    background: #f8fafc;
  }
  .form-input:focus {
    outline: none;
    border-color: #0284c7;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
  }
  .btn-login {
    width: 100%;
    padding: 12px;
    background: #0284c7;
    color: #ffffff;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    margin-top: 6px;
  }
  .btn-login:hover {
    background: #0369a1;
  }
  .forgot-link {
    display: block;
    text-align: center;
    margin-top: 18px;
    font-size: 13px;
    color: #0284c7;
    text-decoration: none;
    cursor: pointer;
  }
  .forgot-link:hover {
    text-decoration: underline;
  }
  .alert {
    padding: 12px 14px;
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.4;
  }
  .alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
  }
  .alert-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
  }
  .demo-accounts {
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px dashed #cbd5e1;
    font-size: 12px;
  }
  .demo-title {
    font-weight: 700;
    color: #475569;
    margin-bottom: 8px;
    text-align: center;
  }
  .demo-pills {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }
  .demo-btn {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #1e293b;
    padding: 7px 9px;
    border-radius: 6px;
    font-size: 11px;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s ease-in-out;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .demo-btn:hover {
    background: #f0f9ff;
    color: #0284c7;
    border-color: #0284c7;
    box-shadow: 0 2px 5px rgba(2, 132, 199, 0.15);
  }
  .demo-role-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    color: #0284c7;
    background: #e0f2fe;
    padding: 1px 6px;
    border-radius: 4px;
    width: fit-content;
  }
  .demo-email-hint {
    font-size: 10px;
    color: #64748b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  /* Modals */
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.75);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .modal-card {
    background: #fff;
    border-radius: 10px;
    max-width: 420px;
    width: 100%;
    padding: 26px;
    box-shadow: 0 20px 30px rgba(0,0,0,0.3);
  }
  .modal-card-wide {
    max-width: 520px;
  }
  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  @media (max-width: 480px) {
    .demo-pills { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<div class="login-page-wrap">
  <div class="login-card">
    <div class="login-logo">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17.8 19.2L16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.3c.4-.2.6-.6.5-1.1z"/>
      </svg>
      <div class="login-title">JETLOG</div>
      <div class="login-subtitle">Aircraft Maintenance Tracking &amp; Management</div>
    </div>

    <?php if (!empty($errorMessage)): ?>
      <div class="alert alert-error">
        <strong>Login Failed:</strong> <?= htmlspecialchars($errorMessage) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
      <div class="alert alert-success">
        <?= htmlspecialchars($successMessage) ?>
      </div>
    <?php endif; ?>

    <!-- Working Authorization Form -->
    <form method="POST" action="index.php" id="loginForm">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? 'dashboard.php') ?>">

      <div class="form-group">
        <label for="email">Email or Username</label>
        <input type="text" id="email" name="email" class="form-input" placeholder="technician@jetlog.com" value="<?= htmlspecialchars($emailInput) ?>" required autofocus>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-input" placeholder="Enter your password" required>
      </div>

      <button type="submit" class="btn-login" id="btnLogin">Log in</button>
      
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
        <a class="forgot-link" onclick="openForgotModal()" style="margin:0;">Forgot password?</a>
        <a class="register-link" onclick="openRegisterModal()" style="font-size:13px; color:#0284c7; font-weight:600; text-decoration:none; cursor:pointer;">+ Register Technician</a>
      </div>
    </form>

    
    
  </div>
</div>

<!-- Modal 1: Technician Self-Registration -->
<div class="modal-overlay" id="registerModal">
  <div class="modal-card modal-card-wide">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
      <div>
        <h3 style="margin:0; color:#0f172a; font-size:18px;">Register New Technician</h3>
        <p style="margin:2px 0 0; font-size:12px; color:#64748b;">Create your aircraft maintenance staff credentials.</p>
      </div>
      <button type="button" class="modal-close" onclick="closeRegisterModal()" title="Close">&times;</button>
    </div>

    <form method="POST" action="index.php">
      <input type="hidden" name="action" value="register_technician">

      <div class="form-row">
        <div class="form-group">
          <label for="reg_first_name">First Name *</label>
          <input type="text" id="reg_first_name" name="reg_first_name" class="form-input" placeholder="e.g. Eduardo" required>
        </div>
        <div class="form-group">
          <label for="reg_last_name">Last Name *</label>
          <input type="text" id="reg_last_name" name="reg_last_name" class="form-input" placeholder="e.g. Tan" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="reg_username">Username <span style="font-weight:normal; color:#94a3b8;">(optional)</span></label>
          <input type="text" id="reg_username" name="reg_username" class="form-input" placeholder="e.g. e.tan">
        </div>
        <div class="form-group">
          <label for="reg_email">Email Address *</label>
          <input type="email" id="reg_email" name="reg_email" class="form-input" placeholder="e.g. etan@jetlog.com" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="reg_role">Role / Specialization *</label>
          <select id="reg_role" name="reg_role" class="form-input" style="height:44px;" required>
            <option value="Technician" selected>Technician (General Maintenance)</option>
            <option value="Lead Technician">Lead Technician</option>
            <option value="Avionics Specialist">Avionics Specialist</option>
            <option value="Inspector">Inspector (Quality &amp; Safety)</option>
          </select>
        </div>
        <div class="form-group">
          <label for="reg_phone">Phone Number</label>
          <input type="text" id="reg_phone" name="reg_phone" class="form-input" placeholder="+63 917 555 0199">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="reg_password">Password *</label>
          <input type="password" id="reg_password" name="reg_password" class="form-input" placeholder="Minimum 4 characters" required>
        </div>
        <div class="form-group">
          <label for="reg_password_confirm">Confirm Password *</label>
          <input type="password" id="reg_password_confirm" name="reg_password_confirm" class="form-input" placeholder="Re-type password" required>
        </div>
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:14px;">
        <button type="button" class="btn small btn-secondary" onclick="closeRegisterModal()">Cancel</button>
        <button type="submit" class="btn small" style="background:#0284c7; color:#fff;">+ Create Technician Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 2: Forgot Password Modal -->
<div class="modal-overlay" id="forgotModal">
  <div class="modal-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
      <h3 style="margin:0; color:#0f172a;">Reset Password</h3>
      <button type="button" class="modal-close" onclick="closeForgotModal()" title="Close">&times;</button>
    </div>
    <p style="font-size:13px; color:#64748b; margin-top:4px;">Enter the registered email associated with your JetLog technician account.</p>
    <form method="POST" action="index.php">
      <input type="hidden" name="action" value="forgot_password">
      <div class="form-group">
        <label for="forgot_email">Email Address</label>
        <input type="email" id="forgot_email" name="forgot_email" class="form-input" placeholder="technician@jetlog.com" required>
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
        <button type="button" class="btn small btn-secondary" onclick="closeForgotModal()">Cancel</button>
        <button type="submit" class="btn small">Send Reset Link</button>
      </div>
    </form>
  </div>
</div>

<script>
function fillCredentials(email, pass) {
  document.getElementById('email').value = email;
  document.getElementById('password').value = pass;
  document.getElementById('loginForm').submit();
}

function openForgotModal() {
  document.getElementById('forgotModal').style.display = 'flex';
}

function closeForgotModal() {
  document.getElementById('forgotModal').style.display = 'none';
}

function openRegisterModal() {
  document.getElementById('registerModal').style.display = 'flex';
}

function closeRegisterModal() {
  document.getElementById('registerModal').style.display = 'none';
}

// Close modals when clicking outside card
window.addEventListener('click', function(e) {
  var regModal = document.getElementById('registerModal');
  var fgtModal = document.getElementById('forgotModal');
  if (e.target === regModal) regModal.style.display = 'none';
  if (e.target === fgtModal) fgtModal.style.display = 'none';
});
</script>

</body>
</html>
