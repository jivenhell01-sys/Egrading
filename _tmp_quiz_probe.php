<?php
$before = session_status();
$auto = ini_get('session.auto_start');
$prep = ini_get('auto_prepend_file');
session_start();
header('Content-Type: application/json');
echo json_encode([
    'status_before_session_start' => $before,   // 1 = none, 2 = active
    'auto_start' => $auto,
    'auto_prepend' => $prep,
    'sid' => session_id(),
    'admin_id' => $_SESSION['admin_id'] ?? null,
]);
