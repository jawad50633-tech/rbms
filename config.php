<?php
// ============================================================
//  config.php — Core Application Configuration
//  RBMS: Role-Based Management System
// ============================================================

// ─── Environment ────────────────────────────────────────────
define('APP_NAME',    'AI Future Leaders Academy');
define('APP_VERSION', '1.0.0');
define('BASE_URL',    'http://ai-admin.page.gd/rbms');   // Change to your domain
define('BASE_PATH',   __DIR__);

// ─── Database ───────────────────────────────────────────────
define('DB_HOST', 'sql208.infinityfree.com');
define('DB_NAME', 'if0_40816501_portal');
define('DB_USER', 'if0_40816501');          // Change to your DB user
define('DB_PASS', 'lirW3R3fNvdaIU');              // Change to your DB password
define('DB_CHAR', 'utf8mb4');

// ─── Upload Paths ───────────────────────────────────────────
define('UPLOAD_PHOTOS',      BASE_PATH . '/uploads/photos/');
define('UPLOAD_ASSIGNMENTS', BASE_PATH . '/uploads/assignments/');
define('MAX_FILE_SIZE',      100 * 1024 * 1024);   // 10 MB

// ─── Session ────────────────────────────────────────────────
define('SESSION_NAME',    'rbms_session');
define('SESSION_TIMEOUT', 3600);   // 1 hour idle timeout

// ─── Roles ──────────────────────────────────────────────────
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_ADMIN',       'admin');
define('ROLE_TEACHER',     'teacher');
define('ROLE_STUDENT',     'student');

// ─────────────────────────────────────────────────────────────
//  Database Connection (PDO — singleton style)
// ─────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST
             . ';dbname='    . DB_NAME
             . ';charset='   . DB_CHAR;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never expose DB errors to browser in production
            error_log('DB Connection failed: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:40px;color:#c00">
                 <h2>Service Unavailable</h2>
                 <p>Database connection error. Please contact administrator.</p>
                 </div>');
        }
    }
    return $pdo;
}

// ─────────────────────────────────────────────────────────────
//  Session Bootstrap
// ─────────────────────────────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

// ─────────────────────────────────────────────────────────────
//  Auth Helpers
// ─────────────────────────────────────────────────────────────

/**
 * Require login. Call at top of every protected page.
 * Optionally restrict to specific role(s).
 */
function requireLogin(string|array $allowedRoles = []): void {
    startSession();

    // Check session exists
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php?msg=login_required');
        exit;
    }

    // Idle timeout
    if (isset($_SESSION['last_active']) &&
        (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php?msg=session_expired');
        exit;
    }
    $_SESSION['last_active'] = time();

    // Role check
    if (!empty($allowedRoles)) {
        $roles = (array) $allowedRoles;
        if (!in_array($_SESSION['role'], $roles, true)) {
            header('Location: ' . BASE_URL . '/login.php?msg=access_denied');
            exit;
        }
    }
}

/** Return current logged-in user array or empty array */
function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? 0,
        'name'      => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? '',
        'username'  => $_SESSION['username']  ?? '',
    ];
}

/** Redirect to role dashboard */
function redirectToDashboard(string $role): void {
    $map = [
        ROLE_SUPER_ADMIN => BASE_URL . '/dashboards/superadmin_dashboard.php',
        ROLE_ADMIN       => BASE_URL . '/dashboards/admin_dashboard.php',
        ROLE_TEACHER     => BASE_URL . '/dashboards/teacher_dashboard.php',
        ROLE_STUDENT     => BASE_URL . '/dashboards/student_dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? BASE_URL . '/login.php'));
    exit;
}

// ─────────────────────────────────────────────────────────────
//  Utility Helpers
// ─────────────────────────────────────────────────────────────

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

function setFlash(string $type, string $message): void {
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function logActivity(int $userId, string $action, string $module = ''): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $db->prepare(
            'INSERT INTO activity_log (user_id, action, module, ip_address)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([$userId, $action, $module, $ip]);
    } catch (PDOException $e) {
        error_log('Activity log error: ' . $e->getMessage());
    }
}

function sanitizeFileName(string $name): string {
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return preg_replace('/_+/', '_', $name);
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 2)    . ' KB';
    return $bytes . ' B';
}

function formatDate(string $date): string {
    return $date ? date('d M Y', strtotime($date)) : '—';
}
