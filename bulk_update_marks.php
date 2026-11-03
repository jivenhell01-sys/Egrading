<?php
// Batch mark writer for the grades grid's "Bulk Set Range" tool
// (reports_content.php). Same blank semantics as update_mark.php - a blank
// mark means "not yet graded" and DELETES the row instead of storing 0 - but
// many student/assessment pairs are applied in a single transaction, so
// rewriting a whole column costs one request instead of one per cell.
//
// POST updates = JSON array of {student_id, assessment_id, mark}
//   mark: number, or "" / null to clear the score.

session_start();
require_once('DBConnection.php');

// Enable error reporting for debugging
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

// Initialize response
$response = [
    'status' => 'error',
    'message' => '',
    'updated' => 0,
    'cleared' => 0,
    'skipped' => 0,
];

$db = null;

try {
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        throw new Exception('POST method required.');
    }

    $raw = isset($_POST['updates']) ? trim((string)$_POST['updates']) : '';
    if ($raw === '') {
        throw new Exception('No updates supplied.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new Exception('Malformed updates payload.');
    }

    // Accept either a bare list of update objects or {updates: [...]}.
    if (isset($decoded['updates']) && is_array($decoded['updates'])) {
        $decoded = $decoded['updates'];
    }

    if (empty($decoded)) {
        throw new Exception('No updates supplied.');
    }

    // Initialize database connection
    $db = new DBConnection();
    if (!$db) {
        throw new Exception('Failed to connect to database');
    }

    $selectStmt = $db->prepare("SELECT 1 FROM `mark_list` WHERE `student_id` = :student_id AND `assessment_id` = :assessment_id");
    if (!$selectStmt) {
        throw new Exception('Failed to prepare select statement: ' . $db->lastErrorMsg());
    }
    $updateStmt = $db->prepare("UPDATE `mark_list` SET `mark` = :mark, `updated_at` = CURRENT_TIMESTAMP WHERE `student_id` = :student_id AND `assessment_id` = :assessment_id");
    if (!$updateStmt) {
        throw new Exception('Failed to prepare update statement: ' . $db->lastErrorMsg());
    }
    $insertStmt = $db->prepare("INSERT INTO `mark_list` (`student_id`, `assessment_id`, `mark`) VALUES (:student_id, :assessment_id, :mark)");
    if (!$insertStmt) {
        throw new Exception('Failed to prepare insert statement: ' . $db->lastErrorMsg());
    }
    $deleteStmt = $db->prepare("DELETE FROM `mark_list` WHERE `student_id` = :student_id AND `assessment_id` = :assessment_id");
    if (!$deleteStmt) {
        throw new Exception('Failed to prepare delete statement: ' . $db->lastErrorMsg());
    }

    if ($db->exec('BEGIN IMMEDIATE TRANSACTION') === false) {
        throw new Exception('Failed to start transaction: ' . $db->lastErrorMsg());
    }

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            $response['skipped']++;
            continue;
        }

        $studentId = isset($item['student_id']) ? (int)$item['student_id'] : 0;
        $assessmentId = isset($item['assessment_id']) ? (int)$item['assessment_id'] : 0;
        if ($studentId <= 0 || $assessmentId <= 0) {
            $response['skipped']++;
            continue;
        }

        // Blank / null means "not yet graded" - clear the score, never store 0.
        $markRaw = array_key_exists('mark', $item) ? trim((string)$item['mark']) : '';
        $isBlankMark = ($markRaw === '');

        if (!$isBlankMark && !is_numeric($markRaw)) {
            $response['skipped']++;
            continue;
        }

        $selectStmt->reset();
        $selectStmt->clear();
        $selectStmt->bindValue(':student_id', $studentId, SQLITE3_INTEGER);
        $selectStmt->bindValue(':assessment_id', $assessmentId, SQLITE3_INTEGER);
        $selectResult = $selectStmt->execute();
        if ($selectResult === false) {
            throw new Exception('Failed to check existing mark: ' . $db->lastErrorMsg());
        }
        $exists = ($selectResult->fetchArray(SQLITE3_ASSOC) !== false);

        if ($isBlankMark) {
            if (!$exists) {
                // Already blank - nothing to clear.
                continue;
            }
            $deleteStmt->reset();
            $deleteStmt->clear();
            $deleteStmt->bindValue(':student_id', $studentId, SQLITE3_INTEGER);
            $deleteStmt->bindValue(':assessment_id', $assessmentId, SQLITE3_INTEGER);
            if ($deleteStmt->execute() === false) {
                throw new Exception('Failed to clear mark: ' . $db->lastErrorMsg());
            }
            $response['cleared']++;
            continue;
        }

        $mark = max(0.0, (float)$markRaw);

        if ($exists) {
            $updateStmt->reset();
            $updateStmt->clear();
            $updateStmt->bindValue(':mark', $mark, SQLITE3_FLOAT);
            $updateStmt->bindValue(':student_id', $studentId, SQLITE3_INTEGER);
            $updateStmt->bindValue(':assessment_id', $assessmentId, SQLITE3_INTEGER);
            if ($updateStmt->execute() === false) {
                throw new Exception('Failed to update mark: ' . $db->lastErrorMsg());
            }
        } else {
            $insertStmt->reset();
            $insertStmt->clear();
            $insertStmt->bindValue(':student_id', $studentId, SQLITE3_INTEGER);
            $insertStmt->bindValue(':assessment_id', $assessmentId, SQLITE3_INTEGER);
            $insertStmt->bindValue(':mark', $mark, SQLITE3_FLOAT);
            if ($insertStmt->execute() === false) {
                throw new Exception('Failed to add mark: ' . $db->lastErrorMsg());
            }
        }
        $response['updated']++;
    }

    if ($db->exec('COMMIT') === false) {
        throw new Exception('Failed to commit transaction: ' . $db->lastErrorMsg());
    }

    $response['status'] = 'success';
    $response['message'] = 'Saved ' . ($response['updated'] + $response['cleared']) . ' score(s).';

} catch (Exception $e) {
    if ($db instanceof SQLite3) {
        @$db->exec('ROLLBACK');
    }
    $response['message'] = $e->getMessage();
    error_log('Error in bulk_update_marks.php: ' . $e->getMessage());
}

echo json_encode($response);
exit;
