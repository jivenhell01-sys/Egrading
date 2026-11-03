<?php
// Portable database Export / Import (admin + faculty, same-origin).
//
// EXPORT: downloads the current database to the user's PC.
// IMPORT: accepts an uploaded .db file, validates it is a genuine grading
//   database (SQLite magic bytes, integrity_check, core tables), backs up
//   the current database, then replaces it.
// Workflow across sites: Export here -> Import on the live site's own
// Database Transfer page. No tokens, no CORS, no server-to-server calls,
// so hosting bot-checks and firewalls cannot interfere.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!function_exists('dbport_main_db')) {
    function dbport_main_db() {
        $p = (isset($_SESSION['db_file']) && is_string($_SESSION['db_file']) && file_exists($_SESSION['db_file']))
            ? $_SESSION['db_file']
            : (__DIR__ . '/../db/grading_db.db');
        return $p;
    }
}

if (!function_exists('dbport_checkpoint')) {
    // Flush WAL content into the main .db so a plain file copy is complete.
    function dbport_checkpoint($dbPath) {
        try {
            $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
            $db->enableExceptions(true);
            @$db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
            $db->close();
        } catch (Exception $e) {}
    }
}

if (!function_exists('dbport_validate')) {
    // Read-only checks. Returns ['ok'=>bool,'error'=>string,'counts'=>array].
    function dbport_validate($path) {
        if (!is_file($path) || filesize($path) <= 0) {
            return ['ok' => false, 'error' => 'File is empty.', 'counts' => []];
        }
        if (filesize($path) > 100 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File exceeds 100 MB.', 'counts' => []];
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) return ['ok' => false, 'error' => 'Cannot read file.', 'counts' => []];
        $magic = fread($fh, 16);
        fclose($fh);
        if ($magic !== "SQLite format 3\x00") {
            return ['ok' => false, 'error' => 'Not a SQLite database file.', 'counts' => []];
        }
        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            if ($db->querySingle("PRAGMA integrity_check") !== 'ok') {
                $db->close();
                return ['ok' => false, 'error' => 'Integrity check failed.', 'counts' => []];
            }
            foreach (['admin_list', 'student_list', 'class_list', 'subjects'] as $t) {
                $has = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($t) . "'");
                if (!$has) {
                    $db->close();
                    return ['ok' => false, 'error' => 'Missing required table: ' . $t . ' (wrong database?).', 'counts' => []];
                }
            }
            $counts = [];
            foreach (['admin_list', 'student_list', 'class_list', 'subjects', 'published_grades', 'assessment_list'] as $t) {
                try { $counts[$t] = (int)$db->querySingle("SELECT COUNT(*) FROM \"$t\""); }
                catch (Exception $e) { $counts[$t] = 0; }
            }
            $db->close();
            return ['ok' => true, 'error' => '', 'counts' => $counts];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Cannot open as SQLite: ' . substr($e->getMessage(), 0, 120), 'counts' => []];
        }
    }
}

if (!function_exists('dbport_install')) {
    // Backs up $destDb, then replaces it with $srcFile (already validated).
    // Returns ['ok'=>bool,'error'=>string,'counts'=>array,'backup'=>?string].
    function dbport_install($srcFile, $destDb) {
        $destDir = dirname($destDb);
        if (file_exists($destDb)) {
            dbport_checkpoint($destDb);
            $role = $_SESSION['role'] ?? 'admin';
            if ($role === 'faculty') {
                // Faculty backups live in db/faculty_backups/<username>_*.db so they
                // show up in the faculty backup list and can be deleted from the UI.
                $backupDir = __DIR__ . '/../db/faculty_backups';
                if (!is_dir($backupDir)) @mkdir($backupDir, 0777, true);
                $who = (isset($_SESSION['username']) && is_string($_SESSION['username']) && $_SESSION['username'] !== '')
                    ? preg_replace('/[^A-Za-z0-9_-]/', '_', $_SESSION['username'])
                    : 'faculty';
                $backupName = $who . '_' . date('Y-m-d_H-i-s') . '.db';
            } else {
                $backupDir = $destDir . '/backups/Administrator';
                if (!is_dir($backupDir)) @mkdir($backupDir, 0777, true);
                $backupName = 'upload_' . date('Y-m-d_H-i-s') . '.db';
            }
            if (!@copy($destDb, $backupDir . '/' . $backupName)) {
                return ['ok' => false, 'error' => 'Could not back up the current database — aborted without changes.', 'counts' => [], 'backup' => null];
            }
        } else {
            $backupName = null;
        }
        if (!@copy($srcFile, $destDb)) {
            return ['ok' => false, 'error' => 'Could not write the new database file.', 'counts' => [], 'backup' => $backupName];
        }
        @unlink($destDb . '-shm');
        @unlink($destDb . '-wal');
        $verify = dbport_validate($destDb);
        if (!$verify['ok']) {
            return ['ok' => false, 'error' => 'Installed file failed verification: ' . $verify['error'], 'counts' => [], 'backup' => $backupName];
        }
        error_log("db_portable: database replaced by upload; backup={$backupName}; students=" . ($verify['counts']['student_list'] ?? '?'));
        return ['ok' => true, 'error' => '', 'counts' => $verify['counts'], 'backup' => $backupName];
    }
}

// Only act on direct HTTP requests to THIS file (include from tests is a no-op).
$__dbportDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && basename((string)$_SERVER['SCRIPT_FILENAME']) === 'db_portable.php';
if ($__dbportDirect) {
    if (!isset($_SESSION['admin_id']) || !in_array(($_SESSION['role'] ?? ''), ['admin', 'faculty'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Admin or faculty access required']);
        exit;
    }
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    try {
        if ($action === 'export') {
            $dbPath = dbport_main_db();
            if (!file_exists($dbPath)) throw new Exception('Database file not found.');
            dbport_checkpoint($dbPath);
            $check = dbport_validate($dbPath);
            if (!$check['ok']) throw new Exception('Current database failed validation: ' . $check['error']);
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="grading_db_' . date('Y-m-d') . '.db"');
            header('Content-Length: ' . filesize($dbPath));
            header('Cache-Control: no-store');
            readfile($dbPath);
            exit;
        }
        if ($action === 'import') {
            header('Content-Type: application/json');
            if (empty($_FILES['database']) || !is_array($_FILES['database'])) {
                throw new Exception('No file received.');
            }
            $up = $_FILES['database'];
            if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception('Upload failed with error code ' . (int)($up['error'] ?? -1) . '.');
            }
            $ext = strtolower(pathinfo((string)($up['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['db', 'sqlite', 'sqlite3'], true)) {
                throw new Exception('Only .db / .sqlite files are accepted.');
            }
            $check = dbport_validate($up['tmp_name']);
            if (!$check['ok']) throw new Exception('Rejected upload: ' . $check['error']);
            $res = dbport_install($up['tmp_name'], dbport_main_db());
            if (!$res['ok']) throw new Exception($res['error']);
            $c = $res['counts'];
            echo json_encode(['status' => 'success', 'message' => 'Database replaced — now holding '
                . ($c['student_list'] ?? '?') . ' students, ' . ($c['published_grades'] ?? '?')
                . ' published grades. Previous database kept as ' . ($res['backup'] ?? 'unknown') . '.']);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    } catch (Exception $e) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
