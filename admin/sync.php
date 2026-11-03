<?php
/**
 * Admin-triggered sync to/from essuc-academic.yzz.me
 * POST ?action=push|pull|apply  {token: "..."}  → {status, ...}
 * Requires admin session.
 */
require_once('../DBConnection.php');
require_once('../sync_config.php');

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id']) || (int)$_SESSION['admin_id'] <= 0) {
    echo json_encode(['status'=>'error','msg'=>'Admin login required']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['status'=>'error','msg'=>'POST required']);
    exit;
}
$action = $_POST['action'] ?? '';
if (!in_array($action, ['push','pull','apply'], true)) {
    echo json_encode(['status'=>'error','msg'=>'Invalid action']);
    exit;
}
$token = trim((string)($_POST['token'] ?? ''));
if ($token === '' || !hash_equals((string)HORIZON_SYNC_TOKEN, $token)) {
    echo json_encode(['status'=>'error','msg'=>'Invalid sync token']);
    exit;
}

$url = rtrim(HORIZON_SYNC_SERVER_URL, '/') . '/api_sync.php?action=' . $action;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Sync-Token: ' . $token],
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false) { echo json_encode(['status'=>'error','msg'=>'cURL failed: ' . curl_error($ch)]); exit; }
echo $resp; // pass through remote JSON