<?php
require_once __DIR__ . '/config.php';
startSession();

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    redirectToDashboard($_SESSION['role']);
}

$error   = '';
$success = '';

// Handle flash from redirect
$msg = $_GET['msg'] ?? '';
$msgMap = [
    'login_required'  => ['type' => 'warning', 'text' => 'Please log in to continue.'],
    'session_expired' => ['type' => 'warning', 'text' => 'Your session has expired. Please log in again.'],
    'access_denied'   => ['type' => 'danger',  'text' => 'Access denied. You do not have permission for that page.'],
    'logged_out'      => ['type' => 'success', 'text' => 'You have been logged out successfully.'],
];
$flashMsg = $msgMap[$msg] ?? null;

// ─── POST: Login Attempt ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        } else {
            $db = getDB();

            // Rate-limit: max 5 failed attempts in 15 min (stored in session)
            $attempts  = $_SESSION['login_attempts'] ?? 0;
            $lockUntil = $_SESSION['login_lock_until'] ?? 0;

            if ($lockUntil > time()) {
                $wait  = ceil(($lockUntil - time()) / 60);
                $error = "Too many failed attempts. Try again in {$wait} minute(s).";
            } else {
                $stmt = $db->prepare(
                    'SELECT id, full_name, username, password, role, status
                     FROM users
                     WHERE username = ? OR email = ?
                     LIMIT 1'
                );
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {

                    if ($user['status'] !== 'active') {
                        $error = 'Your account is inactive. Contact administrator.';
                    } else {
                        // Successful login
                        session_regenerate_id(true);
                        $_SESSION['user_id']   = $user['id'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['username']  = $user['username'];
                        $_SESSION['role']      = $user['role'];
                        $_SESSION['last_active'] = time();

                        // Reset rate limit
                        unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);

                        // Log activity
                        logActivity($user['id'], 'User logged in', 'Auth');

                        redirectToDashboard($user['role']);
                    }
                } else {
                    // Failed
                    $attempts++;
                    $_SESSION['login_attempts'] = $attempts;
                    if ($attempts >= 5) {
                        $_SESSION['login_lock_until'] = time() + 900; // 15 min
                        $_SESSION['login_attempts']   = 0;
                        $error = 'Too many failed attempts. Account locked for 15 minutes.';
                    } else {
                        $remaining = 5 - $attempts;
                        $error = "Invalid username or password. {$remaining} attempt(s) remaining.";
                    }
                }
            }
        }
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Inter', sans-serif;
      padding: 20px;
    }

    .login-card {
      background: #fff;
      border-radius: 20px;
      padding: 48px 44px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 25px 60px rgba(0,0,0,.35);
    }

    .login-logo {
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.7rem;
      color: #fff;
      margin: 0 auto 20px;
    }

    .login-title {
      font-size: 1.4rem;
      font-weight: 700;
      color: #0f172a;
      text-align: center;
      margin-bottom: 4px;
    }

    .login-subtitle {
      text-align: center;
      color: #64748b;
      font-size: .85rem;
      margin-bottom: 32px;
    }

    .form-label {
      font-weight: 600;
      font-size: .84rem;
      color: #374151;
    }

    .form-control {
      border-radius: 10px;
      padding: 11px 14px;
      border: 1.5px solid #e2e8f0;
      font-size: .9rem;
      transition: border-color .2s, box-shadow .2s;
    }

    .form-control:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,.15);
    }

    .input-group-text {
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px 0 0 10px;
      color: #64748b;
    }

    .input-group .form-control { border-radius: 0 10px 10px 0; }

    .btn-login {
      width: 100%;
      padding: 12px;
      border-radius: 10px;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      border: none;
      color: #fff;
      font-weight: 600;
      font-size: .95rem;
      transition: opacity .2s, transform .2s;
      margin-top: 8px;
    }

    .btn-login:hover {
      opacity: .92;
      transform: translateY(-1px);
      color: #fff;
    }

    .demo-creds {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 10px;
      padding: 12px 16px;
      margin-top: 24px;
      font-size: .78rem;
      color: #0369a1;
    }

    .demo-creds strong { display: block; margin-bottom: 6px; color: #0284c7; }
    .demo-creds code {
      background: #e0f2fe;
      padding: 1px 5px;
      border-radius: 4px;
      font-size: .76rem;
    }
  </style>
</head>
<body>

<div class="login-card">
  <div class="login-logo">
    <i class="bi bi-mortarboard-fill"></i>
  </div>
  <h1 class="login-title"><?= APP_NAME ?></h1>
  <p class="login-subtitle">Sign in to your account</p>

  <!-- Flash message -->
  <?php if ($flashMsg): ?>
    <div class="alert alert-<?= $flashMsg['type'] ?> py-2 mb-3" role="alert">
      <i class="bi bi-info-circle me-1"></i>
      <?= e($flashMsg['text']) ?>
    </div>
  <?php endif; ?>

  <!-- Error -->
  <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" role="alert">
      <i class="bi bi-exclamation-circle me-1"></i>
      <?= e($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

    <div class="mb-3">
      <label for="username" class="form-label">Username or Email</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-person"></i></span>
        <input type="text" class="form-control" id="username" name="username"
               value="<?= e($_POST['username'] ?? '') ?>"
               placeholder="Enter username or email"
               autocomplete="username" required>
      </div>
    </div>

    <div class="mb-3">
      <label for="password" class="form-label">Password</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-lock"></i></span>
        <input type="password" class="form-control" id="password" name="password"
               placeholder="Enter password"
               autocomplete="current-password" required>
        <button class="btn btn-outline-secondary" type="button" id="togglePass"
                style="border-radius:0 10px 10px 0; border:1.5px solid #e2e8f0">
          <i class="bi bi-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-login">
      <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
    </button>
  </form>

 

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Toggle password visibility
  document.getElementById('togglePass').addEventListener('click', function() {
    const pass = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (pass.type === 'password') {
      pass.type = 'text';
      icon.className = 'bi bi-eye-slash';
    } else {
      pass.type = 'password';
      icon.className = 'bi bi-eye';
    }
  });
</script>
</body>
</html>
