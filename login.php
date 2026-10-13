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
  <title>Login <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="../logo.png">
  <style>
    body {
      min-height: 100vh;
      background: #090d16;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Inter', sans-serif;
      padding: 20px;
      overflow: hidden;
      position: relative;
    }

    /* ─── Robotic Background Animations ─── */
    .robot-bg {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 0;
      pointer-events: none;
      overflow: hidden;
    }

    /* Cybernetic grid overlay */
    .robot-bg::before {
      content: "";
      position: absolute;
      width: 200%;
      height: 200%;
      top: -50%;
      left: -50%;
      background-image: 
        linear-gradient(rgba(59, 130, 246, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(59, 130, 246, 0.05) 1px, transparent 1px);
      background-size: 40px 40px;
      animation: gridMove 25s linear infinite;
    }

    @keyframes gridMove {
      0% { transform: translateY(0); }
      100% { transform: translateY(40px); }
    }

    /* Moving laser scan line */
    .scanner-line {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 3px;
      background: linear-gradient(90deg, transparent, #3b82f6, #60a5fa, #3b82f6, transparent);
      box-shadow: 0 0 15px #3b82f6, 0 0 30px #1d4ed8;
      animation: scanLaser 6s ease-in-out infinite;
    }

    @keyframes scanLaser {
      0%, 100% { top: 0%; opacity: 0.2; }
      50% { top: 100%; opacity: 0.9; }
    }

    /* ─── Walking Robots ─── */
    .walking-robot {
      position: absolute;
      color: rgba(59, 130, 246, 0.35);
      font-size: 2.2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      filter: drop-shadow(0 0 10px rgba(59, 130, 246, 0.4));
      z-index: 1;
    }

    .walking-robot i {
      animation: robotBob 0.6s ease-in-out infinite alternate;
    }

    @keyframes robotBob {
      0% { transform: translateY(0) rotate(-3deg); }
      100% { transform: translateY(-8px) rotate(3deg); }
    }

    /* Robot 1: Walking across the bottom */
    .robot-1 {
      bottom: 10%;
      animation: walkLeftToRight 16s linear infinite;
    }

    /* Robot 2: Walking across higher up, reverse direction */
    .robot-2 {
      top: 15%;
      font-size: 1.8rem;
      color: rgba(96, 165, f5, 0.25);
      animation: walkRightToLeft 22s linear infinite;
      transform: scaleX(-1);
    }

    @keyframes walkLeftToRight {
      0% { left: -100px; }
      100% { left: calc(100% + 100px); }
    }

    @keyframes walkRightToLeft {
      0% { right: -100px; left: auto; }
      100% { right: calc(100% + 100px); left: auto; }
    }

    /* ─── Login Card Styling ─── */
    .login-card {
      position: relative;
      z-index: 10;
      background: rgba(16, 21, 31, 0.85);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(59, 130, 246, 0.2);
      border-radius: 20px;
      padding: 48px 44px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7), 0 0 30px rgba(59, 130, 246, 0.1);
      animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0;
      transform: translateY(20px);
    }

    @keyframes fadeInUp {
      to {
        opacity: 1;
        transform: translateY(0);
      }
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
      box-shadow: 0 0 20px rgba(59, 130, 246, 0.5);
      animation: bounceLogo 2s ease-in-out infinite;
    }

    @keyframes bounceLogo {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }

    .login-title {
      font-size: 1.4rem;
      font-weight: 700;
      color: #e7ebf3;
      text-align: center;
      margin-bottom: 4px;
    }

    .login-subtitle {
      text-align: center;
      color: #94a3b8;
      font-size: .85rem;
      margin-bottom: 32px;
    }

    .form-label {
      font-weight: 600;
      font-size: .84rem;
      color: #a3adc2;
    }

    .form-control {
      border-radius: 10px;
      padding: 11px 14px;
      border: 1.5px solid #232b3a;
      background: #161c29;
      color: #e7ebf3;
      font-size: .9rem;
      transition: all 0.3s ease;
    }

    .form-control::placeholder { color: #5f6b82; }

    .form-control:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.25);
      background: #161c29;
      color: #e7ebf3;
      transform: scale(1.01);
    }

    .input-group-text {
      background: #161c29;
      border: 1.5px solid #232b3a;
      border-radius: 10px 0 0 10px;
      color: #94a3b8;
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
      transition: all 0.3s ease;
      margin-top: 8px;
      box-shadow: 0 4px 20px rgba(59, 130, 246, 0.4);
    }

    .btn-login:hover {
      opacity: .95;
      transform: translateY(-2px);
      box-shadow: 0 6px 25px rgba(59, 130, 246, 0.6);
      color: #fff;
    }

    .btn-login:active {
      transform: translateY(0);
    }

    .alert {
      animation: shake 0.5s ease-in-out both;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      20%, 60% { transform: translateX(-5px); }
      40%, 80% { transform: translateX(5px); }
    }
  </style>
</head>
<body>

<!-- Robotic Background Animations with Walking Robots -->
<div class="robot-bg">
  <div class="scanner-line"></div>
  <div class="walking-robot robot-1">
    <i class="bi bi-robot"></i>
  </div>
  <div class="walking-robot robot-2">
    <i class="bi bi-cpu"></i>
  </div>
</div>

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
                style="border-radius:0 10px 10px 0; border:1.5px solid #232b3a; background:#161c29; color:#94a3b8">
          <i class="bi bi-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-login">
      <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
    </button>
  </form>
</div>

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