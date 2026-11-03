<?php
/**
 * AutoCare - Secure Logout Handler
 */
require_once __DIR__ . '/config.php';

// Unset all session variables
$_SESSION = [];

// If session cookie is set, delete it
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session on server
session_destroy();

// Start fresh session to pass logout flash message
session_start();
set_flash('info', 'تم تسجيل الخروج من النظام بنجاح.');

header('Location: login.php');
exit;
