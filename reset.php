<?php
require_once 'config.php';
$db = getDB();

$hash = password_hash('password', PASSWORD_BCRYPT);

$stmt = $db->prepare("UPDATE users SET password = :password WHERE username = 'superadmin'");
$stmt->execute(['password' => $hash]);

echo "Superadmin password reset successfully!<br>";
?>