<?php
// Configure session for student system to avoid conflicts with admin system
if (session_status() === PHP_SESSION_NONE) {
    $studentCookiePath = '/students/';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($d !== '' && $d !== '.' && $d !== '/') {
            $studentCookiePath = $d . '/';
        }
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $studentCookiePath,
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Destroy all session data
session_unset();
session_destroy();

// Delete session cookie (use same dynamic path as login)
$logoutCookiePath = '/students/';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $d2 = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($d2 !== '' && $d2 !== '.' && $d2 !== '/') $logoutCookiePath = $d2 . '/';
}
if (isset($_COOKIE['device_bound_student'])) {
    setcookie('device_bound_student', '', time() - 3600, $logoutCookiePath);
}

// Redirect to login page
header('Location: login.php');
exit();
?>
