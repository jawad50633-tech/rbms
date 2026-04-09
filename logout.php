<?php
require_once __DIR__ . '/config.php';
startSession();

if (!empty($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'User logged out', 'Auth');
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

header('Location: ' . BASE_URL . '/login.php?msg=logged_out');
exit;
