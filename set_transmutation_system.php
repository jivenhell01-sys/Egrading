<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once('DBConnection.php');

header('Content-Type: application/json');

try {
    $conn = new DBConnection();
    ensure_transmutation_systems($conn);

    $system = strtoupper(trim($_POST['system'] ?? ''));
    if ($system !== 'CHED' && $system !== 'DEPED') {
        throw new Exception('Invalid system. Choose CHED or DEPED.');
    }

    $stmt = $conn->prepare("UPDATE `system_settings` SET active_system = :s, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
    $stmt->bindValue(':s', $system, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => $system . ' transmutation is now active for grade computation.',
        'active_system' => $system
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
