<?php
/**
 * Touba Lyon 2026 - Admin Logout
 */
session_start();

// Unset all session variables
$_SESSION = array();

// If session is cookie-based, delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to index page
header("Location: index.php");
exit;
?>
