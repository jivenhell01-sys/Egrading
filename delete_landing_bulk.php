<?php
// Bulk delete for landing_registrations (supports JSON and form POST)
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
$role = $_SESSION['role'] ?? '';
$allowed = [];
if (!empty($_SESSION['allowed_pages'])) {
    $d = json_decode($_SESSION['allowed_pages'], true);
    if (is_array($d)) $allowed = $d;
}
if ($role !== 'admin' && !in_array('landing_registrations', $allowed, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: landing access required']);
    exit;
}

require_once("landing_db.php");
$landing_conn = landing_db();

$ids = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json) && isset($json['ids'])) {
        $ids = $json['ids'];
    } elseif (is_array($json) && isset($json['landing_action']) && $json['landing_action'] === 'delete_bulk' && isset($json['ids'])) {
        $ids = $json['ids'];
    }
}
if (empty($ids)) {
    if (isset($_POST['ids'])) {
        $ids = $_POST['ids'];
    } elseif (isset($_POST['ids_json'])) {
        $decoded = json_decode($_POST['ids_json'], true);
        if (is_array($decoded)) $ids = $decoded;
    }
}
if (is_string($ids)) {
    $decoded = json_decode($ids, true);
    if (is_array($decoded)) $ids = $decoded;
    else $ids = explode(',', $ids);
}
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0));
$ids = array_values(array_unique($ids));

if (empty($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided']);
    exit;
}

$in = implode(',', $ids);
try {
    $ok = $landing_conn->exec("DELETE FROM `landing_registrations` WHERE id IN ($in)");
    if ($ok) {
        $deleted = $landing_conn->changes();
        if ($deleted > 0) {
            echo json_encode(['status' => 'success', 'message' => $deleted . ' registration(s) deleted.', 'deleted' => $deleted]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No matching registrations found.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete registrations.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
