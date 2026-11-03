<?php
/**
 * AJAX endpoint: quarter dropdown options for the Reports (Class Records) page.
 *
 * Returns the friendly, per-class quarter names that the instructor defined in
 * the Marks section (stored in `quarter_list`), merged with the default quarter
 * names for quarters 1-4 so the dropdown always shows a usable set.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once('DBConnection.php');

header('Content-Type: application/json');

// Prevent browsers from serving a cached copy - quarter names can be renamed
// at any time from the Marks section (double-click edit), so this response
// must always be fetched fresh.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Auth guard - matches the protection used by other AJAX endpoints.
if (empty($_SESSION['admin_id']) && empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$response = ['status' => 'error', 'options' => [], 'class_id' => 0];

try {
    $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $response['class_id'] = $class_id;

    // Quarters are CUSTOM MADE per class and live in `quarter_list`.
    // No hardcoded default term names are used - only what exists in the table.
    if ($class_id < 1) {
        // No class selected yet - nothing meaningful to show.
        $response['status'] = 'success';
        echo json_encode($response);
        exit;
    }

    $conn = $conn ?? new DBConnection();

    $quarters = [];
    $qry = $conn->query("SELECT quarter, name FROM quarter_list WHERE class_id = " . $class_id . " ORDER BY quarter ASC");
    if ($qry) {
        while ($row = $qry->fetchArray(SQLITE3_ASSOC)) {
            $q_num = (int)$row['quarter'];
            $q_name = trim((string)$row['name']);
            if ($q_name === '') {
                continue;
            }
            $quarters[$q_num] = $q_name;
        }
    }

    ksort($quarters, SORT_NUMERIC);

    $response['status'] = 'success';
    foreach ($quarters as $q_num => $q_name) {
        $response['options'][] = ['value' => $q_num, 'name' => $q_name];
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;