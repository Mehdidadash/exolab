<?php
// panel/logout.php
// Include auth.php (not just config.php) so the session is opened with the SAME
// custom save path (storage/sessions) used by the rest of the app. Otherwise
// logout can't find the real session and the user stays logged in.
require_once __DIR__ . '/auth.php';

// Clear all session data, expire the cookie, and destroy the server-side file.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;