<?php
// Dismiss a single pending landing registration from the pending notification.
// Removes it for every viewer (deletes the landing row). Every pending item
// has its own cross button, and the 75%-match duplicates show "already exists".
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id']) || !in_array(($_SESSION['role'] ?? ''), ['admin','faculty'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Please login.']);
    exit;
}
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Pending id is required']);
    exit;
}
require_once("landing_db.php");
try {
    $db = landing_db();
    $row = $db->query("SELECT id, student_number, name FROM landing_registrations WHERE id='{$id}'")->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Pending student not found']);
        exit;
    }
    $ok = $db->exec("DELETE FROM landing_registrations WHERE id='{$id}'");
    if ($ok) {
        echo json_encode(['status' => 'success', 'message' => 'Removed from pending']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to remove pending student']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
