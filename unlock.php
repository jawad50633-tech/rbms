<?php
session_name('rbms_session');
session_start();
unset($_SESSION['login_attempts']);
unset($_SESSION['login_lock_until']);
session_destroy();
echo "Unlocked! <a href='login.php'>Go to Login</a>";
?>