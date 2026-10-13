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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="lgo.png">
  <style>
    body {
      min-height: 100vh;
      background: #040711;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Inter', sans-serif;
      padding: 20px;
      overflow: hidden;
      position: relative;
    }

    /* ─── Immersive Enterprise Cyber Background ─── */
    .cyber-bg {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 0;
      pointer-events: none;
      background-image: 
        linear-gradient(rgba(14, 165, 233, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(14, 165, 233, 0.03) 1px, transparent 1px);
      background-size: 60px 60px;
    }

    .cyber-bg::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 50% 50%, rgba(14, 165, 233, 0.08) 0%, transparent 70%);
    }

    /* ─── Holographic Telemetry Panels (Left & Right) ─── */
    .telemetry-panel {
      position: absolute;
      width: 280px;
      background: rgba(9, 14, 26, 0.75);
      border: 1px solid rgba(56, 189, 248, 0.2);
      border-radius: 14px;
      padding: 20px;
      backdrop-filter: blur(12px);
      z-index: 5;
      font-family: 'JetBrains Mono', monospace;
      box-shadow: 0 20px 40px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.05);
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .telemetry-panel:hover {
      border-color: rgba(56, 189, 248, 0.6);
      box-shadow: 0 20px 50px rgba(14, 165, 233, 0.25);
      transform: translateY(-3px);
    }

    .panel-left {
      left: 40px;
      top: 50%;
      transform: translateY(-50%);
    }

    .panel-right {
      right: 40px;
      top: 50%;
      transform: translateY(-50%);
    }

    @media (max-width: 1100px) {
      .telemetry-panel { display: none; }
    }

    .panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.75rem;
      color: #38bdf8;
      margin-bottom: 12px;
      border-bottom: 1px solid rgba(56, 189, 248, 0.15);
      padding-bottom: 8px;
      letter-spacing: 1px;
    }

    .panel-body {
      font-size: 0.78rem;
      color: #94a3b8;
      line-height: 1.5;
    }

    .status-indicator {
      display: inline-block;
      width: 8px;
      height: 8px;
      background: #22c55e;
      border-radius: 50%;
      box-shadow: 0 0 10px #22c55e;
      margin-right: 6px;
      animation: pulseGreen 2s infinite;
    }

    @keyframes pulseGreen {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.4; }
    }

    /* ─── Main Professional Glass Login Card ─── */
    .login-card {
      position: relative;
      z-index: 10;
      background: rgba(11, 17, 32, 0.85);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(56, 189, 248, 0.2);
      border-radius: 20px;
      padding: 48px 44px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 30px 80px rgba(0, 0, 0, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.08);
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
      width: 80px;
      height: 80px;
      margin: 0 auto 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      filter: drop-shadow(0 0 20px rgba(14, 165, 233, 0.5));
    }

    .login-logo img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      border-radius: 50%;
    }

    .login-title {
      font-size: 1.4rem;
      font-weight: 700;
      color: #f8fafc;
      text-align: center;
      margin-bottom: 4px;
      letter-spacing: -0.02em;
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
      color: #94a3b8;
    }

    .form-control {
      border-radius: 10px;
      padding: 11px 14px;
      border: 1px solid #1e293b;
      background: rgba(15, 23, 42, 0.7);
      color: #f1f5f9;
      font-size: .9rem;
      transition: all 0.25s ease;
    }

    .form-control::placeholder { color: #475569; }

    .form-control:focus {
      border-color: #0ea5e9;
      box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
      background: rgba(15, 23, 42, 0.95);
      color: #f1f5f9;
    }

    .input-group-text {
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid #1e293b;
      border-radius: 10px 0 0 10px;
      color: #64748b;
    }

    .input-group .form-control { border-radius: 0 10px 10px 0; }

    .btn-login {
      width: 100%;
      padding: 12px;
      border-radius: 10px;
      background: linear-gradient(135deg, #0ea5e9, #2563eb);
      border: none;
      color: #fff;
      font-weight: 600;
      font-size: .95rem;
      transition: all 0.25s ease;
      margin-top: 8px;
      box-shadow: 0 4px 20px rgba(14, 165, 233, 0.35);
    }

    .btn-login:hover {
      background: linear-gradient(135deg, #0284c7, #1d4ed8);
      transform: translateY(-1px);
      box-shadow: 0 6px 25px rgba(14, 165, 233, 0.55);
      color: #fff;
    }

    .alert {
      border-radius: 10px;
      font-size: 0.87rem;
    }

    /* ─── Professional Toast Alert ─── */
    .system-toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%) translateY(100px);
      background: rgba(11, 17, 32, 0.95);
      backdrop-filter: blur(16px);
      border: 1px solid #0ea5e9;
      color: #f8fafc;
      padding: 14px 24px;
      border-radius: 10px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.8);
      z-index: 1000;
      font-size: 0.86rem;
      font-family: 'JetBrains Mono', monospace;
      display: flex;
      align-items: center;
      gap: 12px;
      opacity: 0;
      transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
      pointer-events: none;
    }

    .system-toast.show {
      transform: translateX(-50%) translateY(0);
      opacity: 1;
    }
  </style>
</head>
<body>

<!-- Cyber Background Grid -->
<div class="cyber-bg"></div>

<!-- Left Telemetry Panel (Interactive) -->
<div class="telemetry-panel panel-left" onclick="showSystemInfo('SECURITY_LOG: TLS 1.3 Active. Zero-trust architecture verified across all edge worker clusters.')">
  <div class="panel-header">
    <span><span class="status-indicator"></span>CORE_SECURE</span>
    <i class="bi bi-shield-check"></i>
  </div>
  <div class="panel-body">
    > Protocol: AES-256-GCM<br>
    > Firewall: Optimal<br>
    > Click for security audit telemetry.
  </div>
</div>

<!-- Right Telemetry Panel (Interactive) -->
<div class="telemetry-panel panel-right" onclick="showSystemInfo('AI_DIAGNOSTIC: Authentication gateway is operating at 99.99% efficiency with zero rate-limit blocks.')">
  <div class="panel-header">
    <span><span class="status-indicator"></span>AI_GATEWAY</span>
    <i class="bi bi-cpu"></i>
  </div>
  <div class="panel-body">
    > Latency: 14ms<br>
    > Sessions Active: Live<br>
    > Click for AI subsystem metrics.
  </div>
</div>

<!-- System Notification Toast -->
<div id="systemToast" class="system-toast">
  <i class="bi bi-terminal-fill text-info fs-5"></i>
  <span id="toastText">System telemetry log loaded successfully.</span>
</div>

<!-- Login Card -->
<div class="login-card">
  <div class="login-logo">
    <img src="lgo.png" alt="AI Future Leaders Academy Logo">
  </div>
  <h1 class="login-title"><?= APP_NAME ?></h1>
  <p class="login-subtitle">Secure access authentication</p>

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
                style="border-radius:0 10px 10px 0; border:1px solid #1e293b; background:rgba(15, 23, 42, 0.7); color:#64748b">
          <i class="bi bi-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-login">
      <i class="bi bi-box-arrow-in-right me-2"></i>Authenticate
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

  // Interactive Professional Telemetry Toast Handler
  let toastTimer;
  function showSystemInfo(message) {
    const toast = document.getElementById('systemToast');
    const toastText = document.getElementById('toastText');
    
    toastText.innerText = message;
    toast.classList.add('show');
    
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.classList.remove('show');
    }, 5000);
  }
</script>
</body>
</html>