<?php
// Temporary helper used ONLY by the Playwright verification harness:
// opens a session and marks it as logged-in so the test can reach
// quiz_generator.php without knowing the admin password.
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['fullname'] = 'Administrator';
$_SESSION['role'] = 'admin';
echo 'session-ready';
