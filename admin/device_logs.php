<?php
// admin/device_logs.php — Student security-key console + audit trail.
// Admin surface for the strict 1-device-per-student system: live hardware
// keys table + full audit trail, with key-revoke and login-PIN actions.
//
// Model (see students/device_keys.php): one ACTIVE hardware keypair per
// student number (partial UNIQUE index), global UNIQUE per credential, plus
// the student-chosen 6-digit login PIN. Fingerprint/family/token device
// recognition is gone — its schema (student_device_bindings) is dropped
// below; audit history in student_device_logs is preserved.
//
// Architecture (single file, read top to bottom):
//   1. Bootstrap — session guard, main-DB handle (keys always live here).
//   2. Schema — device_logs_ensure_schema() (idempotent, all tables).
//   3. Helpers — JSON/redirect responses, keys scope, revoke worker.
//   4. POST handlers + router — one named device_logs_handle_*() per
//      device_action; the dispatch block runs after the definitions.
//   5. Data — stats, filters, keys + logs queries for the view.
//   6. View — stat cards, tabs, filter bar, both tables, all modals.
//   7. Client script — endpoint/toast utils, detail modals, action flows.
//
// Request shapes:
//   * Direct AJAX POST to admin/device_logs.php (JSON in/out, clean).
//     Posting via ./?page=... renders the Horizon layout around the JSON,
//     so non-AJAX callers get a flash + JS redirect instead.
//   * GET renders the page (tab=keys|logs, q/year/section/status).
// Loaded via index.php?page=admin/device_logs inside HORIZON layout.

if (session_status() === PHP_SESSION_NONE) {
    // Direct AJAX hits (admin/device_logs.php) must open the session BEFORE
    // the guard below — otherwise $_SESSION is always empty here and every
    // direct POST bounces to login. (Same params as DBConnection.php.)
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
if (!isset($_SESSION['admin_id'])) { header("Location: ../login.php"); exit; }

require_once(__DIR__ . '/../DBConnection.php');

$mainDbPath = __DIR__ . '/../db/grading_db.db';
// Fallback for case where admin db_file points elsewhere — logs live in main DB.
if (!file_exists($mainDbPath)) {
    $alt = dirname(__DIR__) . '/db/grading_db.db';
    if (file_exists($alt)) $mainDbPath = $alt;
}
$db = new SQLite3($mainDbPath);
$db->enableExceptions(true);
$db->exec("PRAGMA busy_timeout = 5000");
$db->exec("PRAGMA journal_mode = WAL");
// ---- 2. Schema (idempotent: fresh installs + column migrations) ----
if (!function_exists('device_logs_ensure_schema')) {
    function device_logs_ensure_schema($db) {
// Strict 1-device-per-student keys: one ACTIVE keypair per number (partial
// UNIQUE index), one number per credential globally. Revoked rows stay for
// audit; only is_active flips.
$db->exec("CREATE TABLE IF NOT EXISTS `student_device_keys` (
    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `student_number` TEXT NOT NULL,
    `student_name` TEXT,
    `credential_id` TEXT NOT NULL,
    `public_key` TEXT NOT NULL,
    `sign_count` INTEGER NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `is_active` INTEGER NOT NULL DEFAULT 1
)");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sdk_credential ON `student_device_keys`(`credential_id`)");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sdk_active_number ON `student_device_keys`(`student_number`) WHERE is_active = 1");
// Email-OTP device resets (phase 4.4): single-use codes authorizing a
// revocation. Only hashes are stored; used/expired codes never verify.
$db->exec("CREATE TABLE IF NOT EXISTS `device_resets` (
    `student_number` TEXT NOT NULL UNIQUE,
    `code_hash` TEXT NOT NULL,
    `expires_at` INTEGER NOT NULL,
    `attempts` INTEGER NOT NULL DEFAULT 0,
    `used_at` TIMESTAMP,
    `last_sent_at` INTEGER NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_by` TEXT NOT NULL DEFAULT ''
)");
// Retired fingerprint/token schema: superseded by cryptographic keys (see
// students/device_keys.php). Audit history lives on in student_device_logs,
// which is deliberately preserved; legacy tables never touched by code stay.
try { @$db->exec("DROP TABLE IF EXISTS `student_device_bindings`"); } catch (Throwable $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS `student_device_logs` (
    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `student_number` TEXT NOT NULL,
    `device_fingerprint` TEXT,
    `device_model` TEXT,
    `ip_address` TEXT,
    `user_agent` TEXT,
    `status` TEXT NOT NULL,
    `details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// Student-chosen login PINs, one row per student number. Only the hash is
// stored — the student picks the PIN on first binding (or after an admin
// reset); the admin side can only clear rows, never read or set a PIN.
$db->exec("CREATE TABLE IF NOT EXISTS `student_bind_pins` (
    `student_number` TEXT NOT NULL UNIQUE,
    `pin_hash` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_issued_at` TIMESTAMP,
    `fails` INTEGER NOT NULL DEFAULT 0,
    `locked_until` INTEGER NOT NULL DEFAULT 0,
    `device_token` TEXT
)");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sbp_student_number ON `student_bind_pins`(`student_number`)");
    }
}
device_logs_ensure_schema($db);

// ---- 3. Response + scope helpers ----
if (!function_exists('device_logs_is_ajax')) {
    // Direct JS posts carry X-Requested-With (or _ajax); anything else is a
    // plain form post that needs flash + redirect instead of JSON.
    function device_logs_is_ajax() {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['_ajax']);
    }
}
if (!function_exists('device_logs_json')) {
    function device_logs_json($payload) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
if (!function_exists('device_logs_redirect')) {
    // Non-AJAX POSTs land here via the Horizon layout (index.php already sent
    // the sidebar/navbar HTML before including this file), so header() alone
    // fails silently and exit would freeze a half-rendered page. Fall back to JS.
    function device_logs_redirect($url) {
        if (!headers_sent()) { header("Location: {$url}"); exit; }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo '<script>location.replace(' . json_encode($url) . ');</script>';
        echo '<p style="padding:24px;font-family:sans-serif">Done. <a href="' . htmlspecialchars($url) . '">Continue</a></p>';
        exit;
    }
}
if (!function_exists('device_logs_keys_scope')) {
    // Shared roster scope for the keys list. Year/section come from the
    // student's class in the main DB. Empty filters = no constraint
    // (includes roster-less rows). Returns [joinSql, whereArray].
    function device_logs_keys_scope($db, $q, $year, $section) {
        $join = '';
        $where = [];
        if ($q !== '') {
            $esc = $db->escapeString($q);
            $where[] = "(k.student_number LIKE '%{$esc}%' OR k.student_name LIKE '%{$esc}%' OR k.credential_id LIKE '%{$esc}%')";
        }
        if ($year !== '' || $section !== '') {
            $join = 'LEFT JOIN student_list sl ON sl.student_number = k.student_number LEFT JOIN class_list cl ON cl.class_id = sl.class_id';
            if ($year !== '') $where[] = "cl.year = '" . $db->escapeString($year) . "'";
            if ($section !== '') $where[] = "cl.section = '" . $db->escapeString($section) . "'";
        }
        return [$join, $where];
    }
}
if (!function_exists('device_logs_logs_scope')) {
    // Audit-log twin of device_logs_keys_scope(): the same Year/Section and
    // search filters apply to the audit view. Year/section resolve through
    // the student's class in the main DB. Returns [joinSql, whereArray].
    function device_logs_logs_scope($db, $q, $year, $section) {
        $join = '';
        $where = [];
        if ($q !== '') {
            $esc = $db->escapeString($q);
            $where[] = "(l.student_number LIKE '%{$esc}%' OR l.device_model LIKE '%{$esc}%' OR l.device_fingerprint LIKE '%{$esc}%' OR l.ip_address LIKE '%{$esc}%')";
        }
        if ($year !== '' || $section !== '') {
            $join = 'LEFT JOIN student_list sl ON sl.student_number = l.student_number LEFT JOIN class_list cl ON cl.class_id = sl.class_id';
            if ($year !== '') $where[] = "cl.year = '" . $db->escapeString($year) . "'";
            if ($section !== '') $where[] = "cl.section = '" . $db->escapeString($section) . "'";
        }
        return [$join, $where];
    }
}
if (!function_exists('device_logs_key_revoke')) {
    // Deactivate a number's hardware key (lost/stolen device, or admin reset
    // without OTP). The row stays for audit with is_active = 0; the partial
    // UNIQUE index then allows exactly one fresh enrollment afterwards.
    // Phase 4.4's student-driven path (email OTP) lives in
    // students/login.php; this is the admin-mediated equivalent used after
    // verifying the student in person. Returns [status, msg].
    function device_logs_key_revoke($db, $sn, $adminId, $adminName) {
        $sn = trim((string)$sn);
        if (!preg_match('/^\d{2}-\d{5}$/', $sn)) return ['status' => 'error', 'msg' => 'Invalid student number. Use XX-XXXXX.'];
        try {
            $snE = $db->escapeString($sn);
            $row = $db->querySingle("SELECT id FROM student_device_keys WHERE student_number = '{$snE}' AND is_active = 1", true);
            if (empty($row)) return ['status' => 'error', 'msg' => 'No active security key for ' . $sn . ' — nothing to revoke.'];
            $db->exec("UPDATE student_device_keys SET is_active = 0 WHERE student_number = '{$snE}' AND is_active = 1");
            if ($db->changes() < 1) return ['status' => 'error', 'msg' => 'Could not revoke the key. Please try again.'];
            $db->exec("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES ('{$snE}','','','admin','admin','key_revoked','" . $db->escapeString('Security key revoked for ' . $sn . ' by admin #' . (int)$adminId . ' (' . ($adminName !== '' ? $adminName : 'unknown') . ') — new enrollment allowed on next login') . "')");
            return ['status' => 'success', 'msg' => 'Security key revoked for ' . $sn . '. Their next login (number + PIN) will enroll the new device.'];
        } catch (Throwable $e) {
            error_log('device_logs key_revoke failed: ' . $e->getMessage());
            return ['status' => 'error', 'msg' => 'Could not revoke the key. Please try again.'];
        }
    }
}
if (!function_exists('device_logs_pin_clear')) {
    // Clear a number's login PIN entirely (forgotten PIN recovery). The row,
    // fail counter and lockout are all deleted — the student simply chooses
    // a fresh PIN on next login (self-service set mode). The admin can never
    // read or set the PIN itself, only clear it. Returns [status, msg].
    function device_logs_pin_clear($db, $sn, $adminId, $adminName) {
        $sn = trim((string)$sn);
        if (!preg_match('/^\d{2}-\d{5}$/', $sn)) return ['status' => 'error', 'msg' => 'Invalid student number. Use XX-XXXXX.'];
        try {
            $snE = $db->escapeString($sn);
            $row = $db->querySingle("SELECT fails FROM student_bind_pins WHERE student_number = '{$snE}'", true);
            if (empty($row)) return ['status' => 'error', 'msg' => 'No login PIN set for ' . $sn . ' yet — nothing to clear.'];
            $db->exec("DELETE FROM student_bind_pins WHERE student_number = '{$snE}'");
            if ($db->changes() < 1) return ['status' => 'error', 'msg' => 'Could not clear the PIN. Please try again.'];
            $db->exec("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES ('{$snE}','','','admin','admin','admin_pin_reset','" . $db->escapeString('Login PIN cleared for ' . $sn . ' (' . (int)($row['fails'] ?? 0) . ' fails) by admin #' . (int)$adminId . ' (' . ($adminName !== '' ? $adminName : 'unknown') . ') — student chooses a new PIN on next login') . "')");
            return ['status' => 'success', 'msg' => 'Login PIN cleared for ' . $sn . '. The student will choose a new one on next login.'];
        } catch (Throwable $e) {
            error_log('device_logs pin_clear failed: ' . $e->getMessage());
            return ['status' => 'error', 'msg' => 'Could not clear the PIN. Please try again.'];
        }
    }
}
if (!function_exists('device_logs_pin_reset_lock')) {
    // Clear a number's PIN fail counter + lockout (wrong-PIN lock). The PIN
    // itself is unchanged — the student retries with the same PIN. For a
    // forgotten PIN use device_logs_pin_clear instead (student picks new).
    function device_logs_pin_reset_lock($db, $sn, $adminId, $adminName) {
        $sn = trim((string)$sn);
        if (!preg_match('/^\d{2}-\d{5}$/', $sn)) return ['status' => 'error', 'msg' => 'Invalid student number. Use XX-XXXXX.'];
        try {
            $snE = $db->escapeString($sn);
            $row = $db->querySingle("SELECT fails, locked_until FROM student_bind_pins WHERE student_number = '{$snE}'", true);
            if (empty($row)) return ['status' => 'error', 'msg' => 'No login PIN set for ' . $sn . ' yet.'];
            $db->exec("UPDATE student_bind_pins SET fails = 0, locked_until = 0 WHERE student_number = '{$snE}'");
            $db->exec("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES ('{$snE}','','','admin','admin','admin_pin_reset','" . $db->escapeString('Setup PIN lock reset for ' . $sn . ' (' . (int)($row['fails'] ?? 0) . ' fails) by admin #' . (int)$adminId . ' (' . ($adminName !== '' ? $adminName : 'unknown') . ')') . "')");
            return ['status' => 'success', 'msg' => 'PIN lock cleared for ' . $sn . '. The student can retry with the same PIN.'];
        } catch (Throwable $e) {
            error_log('device_logs pin_reset_lock failed: ' . $e->getMessage());
            return ['status' => 'error', 'msg' => 'Could not reset the lock. Please try again.'];
        }
    }
}
if (!function_exists('device_logs_pins_clear_all')) {
    // Bulk forgotten-PIN recovery: delete EVERY login-PIN row in one action
    // (start-of-year reset, mass device change, or a suspected leak). Same
    // power as the per-number clear — rows are deleted, never read or set,
    // so every student simply chooses a fresh PIN on next login. Hardware
    // security keys are untouched: only PIN rows are affected.
    function device_logs_pins_clear_all($db, $adminId, $adminName) {
        try {
            $count = (int)$db->querySingle("SELECT COUNT(*) FROM student_bind_pins");
            if ($count < 1) return ['status' => 'error', 'msg' => 'No login PINs are set — nothing to clear.'];
            $db->exec("DELETE FROM student_bind_pins");
            if ($db->changes() < 1) return ['status' => 'error', 'msg' => 'Could not clear the PINs. Please try again.'];
            $db->exec("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES ('*','','','admin','admin','admin_pin_reset_all','" . $db->escapeString('All ' . $count . ' login PINs cleared by admin #' . (int)$adminId . ' (' . ($adminName !== '' ? $adminName : 'unknown') . ') — every student chooses a new PIN on next login') . "')");
            return ['status' => 'success', 'msg' => 'All ' . $count . ' login PINs deleted. Every student picks a new PIN on their next login (keys stay bound).'];
        } catch (Throwable $e) {
            error_log('device_logs pins_clear_all failed: ' . $e->getMessage());
            return ['status' => 'error', 'msg' => 'Could not clear the PINs. Please try again.'];
        }
    }
}
if (!function_exists('device_logs_pins_reset_all')) {
    // Bulk lock reset: keep every PIN, but zero all fail counters + lockouts
    // (class-wide wrong-PIN lock after e.g. a shared typo). The PINs
    // themselves are unchanged — students retry with the same digits.
    function device_logs_pins_reset_all($db, $adminId, $adminName) {
        try {
            $count = (int)$db->querySingle("SELECT COUNT(*) FROM student_bind_pins WHERE fails > 0 OR locked_until > 0");
            if ($count < 1) return ['status' => 'error', 'msg' => 'No PIN locks to reset — every PIN is already unlocked.'];
            $db->exec("UPDATE student_bind_pins SET fails = 0, locked_until = 0 WHERE fails > 0 OR locked_until > 0");
            $db->exec("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES ('*','','','admin','admin','admin_pin_unlock_all','" . $db->escapeString('All ' . $count . ' PIN lock(s) cleared by admin #' . (int)$adminId . ' (' . ($adminName !== '' ? $adminName : 'unknown') . ') — every PIN kept, fails + lockouts zeroed') . "')");
            return ['status' => 'success', 'msg' => $count . ' PIN lock' . ($count === 1 ? '' : 's') . ' reset. Every PIN stays the same.'];
        } catch (Throwable $e) {
            error_log('device_logs pins_reset_all failed: ' . $e->getMessage());
            return ['status' => 'error', 'msg' => 'Could not reset the locks. Please try again.'];
        }
    }
}
if (!function_exists('device_logs_pin_status')) {
    // Display state for the PIN modal: whether a PIN is set, when it was set,
    // current fails and lockout. Never exposes the hash or PIN (unreadable).
    function device_logs_pin_status($db, $sn) {
        $sn = trim((string)$sn);
        if (!preg_match('/^\d{2}-\d{5}$/', $sn)) return ['status' => 'error', 'msg' => 'Invalid student number. Use XX-XXXXX.'];
        try {
            $row = $db->querySingle("SELECT last_issued_at, fails, locked_until FROM student_bind_pins WHERE student_number = '" . $db->escapeString($sn) . "'", true);
            if (empty($row)) return ['status' => 'success', 'active' => false, 'msg' => 'No login PIN set for ' . $sn . ' yet — they will choose one on next login.'];
            $locked = (int)($row['locked_until'] ?? 0) > time();
            return ['status' => 'success', 'active' => true, 'locked' => $locked,
                'msg' => 'PIN set for ' . $sn . ' (chosen ' . ($row['last_issued_at'] ?: 'unknown') . ', ' . (int)($row['fails'] ?? 0) . ' wrong attempts' . ($locked ? ', LOCKED' : '') . ').'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'msg' => 'Could not read PIN status.'];
        }
    }
}
// ---- 4. POST handlers (one named function per device_action) ----
// NOTE: AJAX calls post DIRECTLY to admin/device_logs.php (see JS endpoint
// below) so the JSON stays clean. Posting via ./?page=... renders the full
// Horizon layout around the JSON and breaks response parsing ("Request
// failed" even though the DB write succeeded).
// The dispatch block lives AFTER these definitions: PHP executes
// top-to-bottom, so dispatching here would call not-yet-defined functions.
if (!function_exists('device_logs_handle_pin_clear')) {
    // Clear a number's login PIN (forgotten-PIN recovery). The student picks
    // a fresh PIN on next login. AJAX only needs a confirmation — there is no
    // secret to display, so the fallback flash is equally safe.
    function device_logs_handle_pin_clear($db) {
        $isAjax = device_logs_is_ajax();
        if (!isset($_POST['student_number'])) return;
        $fr = device_logs_pin_clear($db, (string)$_POST['student_number'], (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['fullname'] ?? ''));
        if ($isAjax) { device_logs_json(['status' => $fr['status'], 'msg' => $fr['msg'], 'tab' => 'keys']); }
        $_SESSION['flashdata'] = ['type' => $fr['status'] === 'success' ? 'success' : 'danger', 'msg' => $fr['msg']];
        device_logs_redirect("./?page=admin/device_logs&tab=keys");
    }
}
if (!function_exists('device_logs_handle_pin_reset_lock')) {
    // Clear a number's PIN fail counter + lockout; the PIN itself is unchanged.
    function device_logs_handle_pin_reset_lock($db) {
        $isAjax = device_logs_is_ajax();
        if (!isset($_POST['student_number'])) return;
        $fr = device_logs_pin_reset_lock($db, (string)$_POST['student_number'], (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['fullname'] ?? ''));
        if ($isAjax) { device_logs_json(['status' => $fr['status'], 'msg' => $fr['msg'], 'tab' => 'keys']); }
        $_SESSION['flashdata'] = ['type' => $fr['status'] === 'success' ? 'success' : 'danger', 'msg' => $fr['msg']];
        device_logs_redirect("./?page=admin/device_logs&tab=keys");
    }
}
if (!function_exists('device_logs_handle_pin_status')) {
    // Read-only state for the PIN modal (active / issued / fails / locked).
    function device_logs_handle_pin_status($db) {
        $isAjax = device_logs_is_ajax();
        if (!isset($_POST['student_number'])) return;
        $fr = device_logs_pin_status($db, (string)$_POST['student_number']);
        if ($isAjax) { device_logs_json($fr + ['tab' => 'keys']); }
        $_SESSION['flashdata'] = ['type' => $fr['status'] === 'success' ? 'info' : 'danger', 'msg' => $fr['msg']];
        device_logs_redirect("./?page=admin/device_logs&tab=keys");
    }
}
if (!function_exists('device_logs_handle_pins_reset_all')) {
    // Bulk lock reset (PIN modal "All PINs" console): zero every fail
    // counter + lockout at once; no PIN is changed or exposed.
    function device_logs_handle_pins_reset_all($db) {
        $isAjax = device_logs_is_ajax();
        $fr = device_logs_pins_reset_all($db, (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['fullname'] ?? ''));
        if ($isAjax) { device_logs_json(['status' => $fr['status'], 'msg' => $fr['msg'], 'tab' => 'keys']); }
        $_SESSION['flashdata'] = ['type' => $fr['status'] === 'success' ? 'success' : 'danger', 'msg' => $fr['msg']];
        device_logs_redirect("./?page=admin/device_logs&tab=keys");
    }
}
if (!function_exists('device_logs_handle_pins_clear_all')) {
    // Bulk forgotten-PIN recovery: delete EVERY login-PIN row. Students pick
    // a fresh PIN on next login; hardware keys stay bound.
    function device_logs_handle_pins_clear_all($db) {
        $isAjax = device_logs_is_ajax();
        $fr = device_logs_pins_clear_all($db, (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['fullname'] ?? ''));
        if ($isAjax) { device_logs_json(['status' => $fr['status'], 'msg' => $fr['msg'], 'tab' => 'keys']); }
        $_SESSION['flashdata'] = ['type' => $fr['status'] === 'success' ? 'success' : 'danger', 'msg' => $fr['msg']];
        device_logs_redirect("./?page=admin/device_logs&tab=keys");
    }
}
if (!function_exists('device_logs_handle_revoke_key')) {
    // Deactivate a number's hardware key (lost/stolen device, admin reset
    // without OTP). Phase 4.4's student-driven path (email OTP) lives in
    // students/login.php; this is the admin-mediated equivalent used after
    // verifying the student in person. Their next login (number + PIN)
    // enrolls the new device as the single active key.
    function device_logs_handle_revoke_key($db) {
        $isAjax = device_logs_is_ajax();
        if (!isset($_POST['student_number'])) return;
        $fr = device_logs_key_revoke($db, (string)$_POST['student_number'], (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['fullname'] ?? ''));
        if ($isAjax) { device_logs_json(['status' => $fr['status'], 'msg' => $fr['msg'], 'tab' => 'keys']); }
        $_SESSION['flashdata'] = ['type' => $fr['status'] === 'success' ? 'success' : 'danger', 'msg' => $fr['msg']];
        device_logs_redirect("./?page=admin/device_logs&tab=keys");
    }
}
if (!function_exists('device_logs_handle_clear_logs')) {
    // Audit-log pruning (30 days / N days / everything).
    function device_logs_handle_clear_logs($db) {
        $isAjax = device_logs_is_ajax();
        $olderThan = $_POST['older_than'] ?? '';
        try {
            if ($olderThan === 'all') {
                $db->exec("DELETE FROM student_device_logs");
                $msg = 'All device logs cleared.';
            } elseif (preg_match('/^\d+$/', $olderThan)) {
                $days = (int)$olderThan;
                $db->exec("DELETE FROM student_device_logs WHERE created_at < datetime('now','-{$days} days')");
                $msg = "Logs older than {$days} days cleared.";
            } else {
                $db->exec("DELETE FROM student_device_logs WHERE created_at < datetime('now','-30 days')");
                $msg = 'Logs older than 30 days cleared.';
            }
            $_SESSION['flashdata'] = ['type'=>'success','msg'=>$msg];
            if ($isAjax) { device_logs_json(['status'=>'success','msg'=>$msg,'tab'=>'logs']); }
            device_logs_redirect("./?page=admin/device_logs&tab=logs");
        } catch (Throwable $e) {
            error_log('device_logs clear_logs failed: ' . $e->getMessage());
            if ($isAjax) { device_logs_json(['status'=>'error','msg'=>'Clear logs failed. Please try again.']); }
            $_SESSION['flashdata'] = ['type'=>'danger','msg'=>'Clear logs failed. Please try again.']; device_logs_redirect("./?page=admin/device_logs&tab=logs");
        }
    }
}

// ---- Router ----
// One entry per device_action the client script can send; the values name the
// handler functions defined above. (This table was missing, so every AJAX POST
// fell through the dispatch, the full HTML page was rendered back to fetch(),
// and the client reported "unexpected response (are you still logged in?)".)
$deviceLogRoutes = [
    'pin_status'     => 'device_logs_handle_pin_status',
    'pin_reset_lock' => 'device_logs_handle_pin_reset_lock',
    'pin_clear'      => 'device_logs_handle_pin_clear',
    'pins_reset_all' => 'device_logs_handle_pins_reset_all',
    'pins_clear_all' => 'device_logs_handle_pins_clear_all',
    'revoke_key'     => 'device_logs_handle_revoke_key',
    'clear_logs'     => 'device_logs_handle_clear_logs',
];
$dlAction = $_POST['device_action'] ?? '';
if ($dlAction !== '' && isset($deviceLogRoutes[$dlAction])) {
    $dlHandler = $deviceLogRoutes[$dlAction];
    if (is_callable($dlHandler)) $dlHandler($db);
}

// ---- Stats ----
$totalKeys = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_keys WHERE is_active = 1");
$revokedKeys = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_keys WHERE is_active != 1");
$totalLogs = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_logs");
$blockedLogs = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_logs WHERE status IN ('blocked_key_mismatch','blocked_key_required','blocked_bad_pin','blocked_no_pin','blocked_race','blocked_mismatch','blocked_device_taken','blocked_no_fingerprint','blocked_taken','blocked_family_taken')");
$todayLogs = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_logs WHERE date(created_at, '+8 hours') = date('now', '+8 hours')");
$keyRegs = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_logs WHERE status = 'key_registered'");
$pinIssuedCount = 0;
try {
    $pinIssuedCount = (int)$db->querySingle("SELECT COUNT(*) FROM student_bind_pins");
} catch (Throwable $e) {}

// ---- Filters ----
// NOTE: the Filter form above is the server-side scope (search + status).
// DataTables below paginates / sorts / searches client-side across ALL
// rendered rows, so queries here intentionally have no LIMIT/OFFSET —
// except a safety cap on the (unbounded) audit log.
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['keys','logs'], true) ? $_GET['tab'] : 'keys';
$isRosterTab = ($tab === 'keys');
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$yearFilter = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
$sectionFilter = isset($_GET['section']) ? trim((string)$_GET['section']) : '';
$logsCap = 1000;

// Year (school year) + Section options from the main-DB roster.
$yearOptions = [];
$sectionOptions = [];
try {
    $yq = $db->query("SELECT DISTINCT year FROM class_list WHERE year IS NOT NULL AND TRIM(year) != '' ORDER BY year DESC");
    if ($yq) { while ($yr = $yq->fetchArray(SQLITE3_ASSOC)) $yearOptions[] = $yr['year']; }
    $sq = $db->query("SELECT DISTINCT section FROM class_list WHERE section IS NOT NULL AND TRIM(section) != '' ORDER BY section");
    if ($sq) { while ($sr = $sq->fetchArray(SQLITE3_ASSOC)) $sectionOptions[] = $sr['section']; }
} catch (Throwable $e) {}

// Keys list (bounded by unique numbers — safe to render all).
list($keysJoin, $keysScope) = device_logs_keys_scope($db, $q, $yearFilter, $sectionFilter);
$keysWhereSql = empty($keysScope) ? '' : 'WHERE ' . implode(' AND ', $keysScope);
$keysGroup = ($keysJoin !== '') ? ' GROUP BY k.id' : '';
$keysCount = (int)$db->querySingle("SELECT COUNT(DISTINCT k.id) FROM student_device_keys k {$keysJoin} {$keysWhereSql}");
$keysRows = [];
$resK = $db->query("SELECT k.* FROM student_device_keys k {$keysJoin} {$keysWhereSql}{$keysGroup} ORDER BY k.last_seen DESC");
if ($resK) { while ($r = $resK->fetchArray(SQLITE3_ASSOC)) $keysRows[] = $r; }

// Login-PIN coverage: how many numbers have a student-chosen PIN set.
// Shown in the Student PIN modal for context.
$pinIssuedCount = 0;
try {
    $pinIssuedCount = (int)$db->querySingle("SELECT COUNT(*) FROM student_bind_pins");
} catch (Throwable $e) {}

// Logs query (capped — audit trail is unbounded over time)
$logsWhere = [];
if ($q !== '' && $tab === 'logs') {
    $esc = $db->escapeString($q);
    $logsWhere[] = "(l.student_number LIKE '%{$esc}%' OR l.device_model LIKE '%{$esc}%' OR l.device_fingerprint LIKE '%{$esc}%' OR l.ip_address LIKE '%{$esc}%')";
}
if ($statusFilter === '__success') {
    // Group filter matching the successful-auth stat card.
    // bind_deferred = the student DID get in (auto-bind policy) — a success.
    $logsWhere[] = "l.status IN ('success','key_attendance','bind_deferred')";
} elseif ($statusFilter === '__blocked') {
    // Group filter matching the "Blocked Attempts" stat card.
    $logsWhere[] = "l.status IN ('blocked_key_mismatch','blocked_key_required','blocked_bad_pin','blocked_no_pin','blocked_race','blocked_mismatch','blocked_device_taken','blocked_no_fingerprint','blocked_taken','blocked_family_taken')";
} elseif ($statusFilter !== '') {
    $escS = $db->escapeString($statusFilter);
    $logsWhere[] = "l.status = '{$escS}'";
}
$logsWhereSql = empty($logsWhere) ? '' : 'WHERE ' . implode(' AND ', $logsWhere);
$logsCount = (int)$db->querySingle("SELECT COUNT(*) FROM student_device_logs l {$logsWhereSql}");
$logsCapped = $logsCount > $logsCap;
$logsRows = [];
$resL = $db->query("SELECT l.* FROM student_device_logs l {$logsWhereSql} ORDER BY l.created_at DESC, l.id DESC LIMIT {$logsCap}");
if ($resL) { while ($r = $resL->fetchArray(SQLITE3_ASSOC)) $logsRows[] = $r; }

// Best-effort name fallback for log rows written before attempt names were
// stored in details JSON (main-DB roster only).
$hgsNameByNumber = [];
try {
    $nmQ = $db->query("SELECT student_number, name FROM student_list WHERE student_number IS NOT NULL AND TRIM(student_number) != ''");
    if ($nmQ) { while ($nmr = $nmQ->fetchArray(SQLITE3_ASSOC)) { $hgsNameByNumber[strtolower(trim($nmr['student_number']))] = $nmr['name']; } }
} catch (Throwable $e) {}

$allStatuses = [];
$stRes = $db->query("SELECT DISTINCT status FROM student_device_logs ORDER BY status");
if ($stRes) { while ($r = $stRes->fetchArray(SQLITE3_ASSOC)) $allStatuses[] = $r['status']; }
if (!in_array('success', $allStatuses, true)) $allStatuses[] = 'success';
if (!in_array('first_bind', $allStatuses, true)) $allStatuses[] = 'first_bind';
sort($allStatuses);

if (!function_exists('hgs_status_badge')) {
function hgs_status_badge($s, $clickable = false, $tip = '') {
    // $tip: optional pre-escaped HTML tooltip (text parts escaped, <br>
    // separators literal) shown on hover. Empty = plain badge as before.
    $s = (string)$s;
    $map = [
        'success' => ['label'=>'Success','class'=>'bg-success','icon'=>'fa-check'],
        'first_bind' => ['label'=>'First Bind','class'=>'bg-primary','icon'=>'fa-link'],
        'blocked_mismatch' => ['label'=>'Blocked · Mismatch','class'=>'bg-danger','icon'=>'fa-ban'],
        'blocked_device_taken' => ['label'=>'Blocked · Taken','class'=>'bg-danger','icon'=>'fa-lock'],
        'blocked_taken' => ['label'=>'Blocked · Taken','class'=>'bg-danger','icon'=>'fa-lock'],
        'blocked_no_fingerprint' => ['label'=>'Blocked · No FP','class'=>'bg-warning text-dark','icon'=>'fa-triangle-exclamation'],
        'blocked_race' => ['label'=>'Blocked · Race','class'=>'bg-warning text-dark','icon'=>'fa-clock'],
        'blocked_family_taken' => ['label'=>'Blocked · Device Taken','class'=>'bg-danger','icon'=>'fa-desktop'],
        'family_enroll' => ['label'=>'Browser Enrolled','class'=>'bg-info text-dark','icon'=>'fa-window-restore'],
        'not_found' => ['label'=>'Not Found','class'=>'bg-secondary','icon'=>'fa-question'],
        'admin_unbind' => ['label'=>'Admin Unbind','class'=>'bg-info text-dark','icon'=>'fa-user-shield'],
        'admin_unbind_all' => ['label'=>'Unbind All','class'=>'bg-danger','icon'=>'fa-trash-alt'],
        'admin_force_bind' => ['label'=>'Force Bind','class'=>'bg-success','icon'=>'fa-link'],
        'admin_force_bind_all' => ['label'=>'Force Bind All','class'=>'bg-success','icon'=>'fa-layer-group'],
        'admin_allow_all' => ['label'=>'Allow All Requests','class'=>'bg-success','icon'=>'fa-user-check'],
        'admin_key_revoke' => ['label'=>'Key Revoked','class'=>'bg-warning text-dark','icon'=>'fa-key'],
        'key_registered' => ['label'=>'Key Enrolled','class'=>'bg-success','icon'=>'fa-key'],
        'key_register_failed' => ['label'=>'Key Failed','class'=>'bg-warning text-dark','icon'=>'fa-triangle-exclamation'],
        'blocked_key_mismatch' => ['label'=>'Blocked · Key','class'=>'bg-danger','icon'=>'fa-key'],
        'blocked_key_required' => ['label'=>'Blocked · Key','class'=>'bg-danger','icon'=>'fa-key'],
        'blocked_bad_pin' => ['label'=>'Blocked · Bad PIN','class'=>'bg-danger','icon'=>'fa-key'],
        'blocked_no_pin' => ['label'=>'Blocked · No PIN','class'=>'bg-danger','icon'=>'fa-key'],
        'admin_pin_issue' => ['label'=>'PIN Issued','class'=>'bg-info text-dark','icon'=>'fa-key'],
        'admin_pin_issue_all' => ['label'=>'PIN Bulk Issue','class'=>'bg-info text-dark','icon'=>'fa-layer-group'],
        'admin_pin_reset' => ['label'=>'PIN Reset','class'=>'bg-info text-dark','icon'=>'fa-rotate-left'],
        'admin_pin_unlock_all' => ['label'=>'All PINs Unlocked','class'=>'bg-info text-dark','icon'=>'fa-layer-group'],
        'admin_pin_reset_all' => ['label'=>'All PINs Cleared','class'=>'bg-danger','icon'=>'fa-layer-group'],
        'key_revoked' => ['label'=>'Key Revoked','class'=>'bg-warning text-dark','icon'=>'fa-key'],
        'key_attendance' => ['label'=>'Key Attendance','class'=>'bg-success','icon'=>'fa-clipboard-check'],
        'reset_issued' => ['label'=>'Reset Sent','class'=>'bg-info text-dark','icon'=>'fa-envelope'],
        'reset_used' => ['label'=>'Reset Used','class'=>'bg-success','icon'=>'fa-check'],
        'reset_requested' => ['label'=>'Reset Asked','class'=>'bg-secondary','icon'=>'fa-envelope'],
        'legacy_pin_login' => ['label'=>'PIN Login','class'=>'bg-secondary','icon'=>'fa-key'],
        'bind_request' => ['label'=>'Binding Request','class'=>'bg-warning text-dark','icon'=>'fa-hand-pointer'],
        'bind_deferred' => ['label'=>'Bind Deferred','class'=>'bg-warning text-dark','icon'=>'fa-hourglass-half'],
        'error' => ['label'=>'Error','class'=>'bg-dark','icon'=>'fa-bug'],
    ];
    $m = $map[$s] ?? ['label'=>htmlspecialchars($s),'class'=>'bg-secondary','icon'=>'fa-tag'];
    $inner = '<i class="fas '.$m['icon'].' me-1"></i>'.htmlspecialchars($m['label']);
    if ($clickable) {
        if ($tip !== '') {
            return '<button type="button" class="badge '.$m['class'].' log-status-btn dl-req-tip" style="font-size:.70rem;border:0" data-bs-toggle="tooltip" data-bs-html="true" data-bs-placement="top" title="'.$tip.'">'.$inner.'</button>';
        }
        return '<button type="button" class="badge '.$m['class'].' log-status-btn" style="font-size:.70rem;border:0" title="View log details">'.$inner.'</button>';
    }
    return '<span class="badge '.$m['class'].'" style="font-size:.70rem">'.$inner.'</span>';
}
function hgs_fp_short($fp) {
    $fp = (string)$fp;
    if ($fp === '') return '<span class="text-muted">—</span>';
    if (strlen($fp) <= 14) return '<code style="font-size:.78rem">'.htmlspecialchars($fp).'</code>';
    return '<code title="'.htmlspecialchars($fp).'" style="font-size:.78rem">'.htmlspecialchars(substr($fp,0,8)).'…'.htmlspecialchars(substr($fp,-4)).'</code> <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.70rem" onclick="hgsCopyFp(this)" data-fp="'.htmlspecialchars($fp, ENT_QUOTES).'"><i class="fas fa-copy"></i></button>';
}
}
if (!function_exists('hgs_log_attempt_meta')) {
// Who attempted this log event? Reads the names stored in details JSON
// (attempt_name + attempt_fp_owner*). Older rows predate those keys and
// resolve through $hgsNameByNumber (main-DB fallback) at render time.
function hgs_log_attempt_meta($l) {
    $d = json_decode((string)($l['details'] ?? ''), true);
    if (!is_array($d)) $d = [];
    return [
        'name' => $d['attempt_name'] ?? null,
        'owner_sn' => $d['attempt_fp_owner'] ?? ($d['owner'] ?? null),
        'owner_name' => $d['attempt_fp_owner_name'] ?? ($d['owner_name'] ?? null),
    ];
}
}
if (!function_exists('hgs_ph_display')) {
// Timestamps are stored as UTC instants (SQLite CURRENT_TIMESTAMP is always
// UTC). Render them in Philippine time (Asia/Manila, UTC+8, no DST).
function hgs_ph_display($utc, $fmt = 'M d, Y g:i A') {
    $utc = trim((string)($utc ?? ''));
    if ($utc === '') return '—';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dt->format($fmt);
    } catch (Exception $e) { return '—'; }
}
}
?>

<style>
.device-logs-page .horizon-stat-card{border:0;border-radius:16px;padding:18px;position:relative;overflow:hidden}
.device-logs-page .horizon-stat-card::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 85% 20%, rgba(255,255,255,.18), transparent 45%);pointer-events:none}
.device-logs-page .stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.device-logs-page .table thead th{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:700;white-space:nowrap;border-bottom-width:1px}
.device-logs-page .table tbody td{font-size:.85rem;vertical-align:middle}
.device-logs-page .nav-pills .nav-link{border-radius:999px;font-weight:600;font-size:.85rem}
.device-logs-page .nav-pills .nav-link.active{background:var(--horizon-brand-500)}
.device-logs-page .filter-bar .form-control,.device-logs-page .filter-bar .form-select{height:38px;font-size:.85rem}
.device-logs-page .log-status-btn{cursor:pointer;transition:box-shadow .15s ease, filter .15s ease}
.device-logs-page .log-status-btn:hover{box-shadow:0 2px 8px rgba(0,0,0,.3);filter:brightness(1.08)}
.device-logs-page #bindRequestPill{animation:dlReqPulse 1.8s ease-in-out infinite}
.device-logs-page .log-status-btn.dl-req-tip{animation:dlReqPulse 1.8s ease-in-out infinite}
@keyframes dlReqPulse{0%,100%{box-shadow:0 0 0 0 rgba(255,193,7,.55)}50%{box-shadow:0 0 0 6px rgba(255,193,7,0)}}
.device-logs-page a.horizon-stat-card{text-decoration:none !important;color:#fff !important;display:block;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease, outline-color .2s ease;outline:2px solid transparent;outline-offset:2px}
.device-logs-page a.horizon-stat-card:hover{transform:translateY(-3px);color:#fff !important}
.device-logs-page a.horizon-stat-card.stat-active{outline-color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.45), 0 8px 22px rgba(0,0,0,.25)}
</style>

<div class="container-fluid px-0 device-logs-page">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
      <h4 class="fw-bold mb-1" style="letter-spacing:-.02em"><i class="fas fa-key text-primary me-2"></i>Device Keys — 1:1 Bindings</h4>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-outline-warning btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#pinModal" title="Check a number's login PIN state, unlock it, or clear it so they can pick a new one"><i class="fas fa-key me-1"></i> Student PIN</button>
      <button type="button" class="btn btn-outline-danger btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#pinBulkModal" title="Reset every PIN lock or delete every login PIN at once"><i class="fas fa-layer-group me-1"></i> All PINs</button>
      <a href="./?page=admin/device_logs&tab=logs" class="btn btn-primary btn-sm rounded-pill"><i class="fas fa-clock-rotate-left me-1"></i> View Audit Log</a>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <a href="./?page=admin/device_logs&tab=keys" title="View bound security keys" class="horizon-stat-card shadow-sm<?php echo $tab==='keys'?' stat-active':''; ?>" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:#fff">
        <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
          <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-key"></i></div>
          <div><div class="fw-bold fs-4 lh-1" data-keys-count><?php echo $totalKeys; ?></div><small class="opacity-75">Bound Devices</small><div style="font-size:.70rem;opacity:.75"><?php echo $keyRegs; ?> enrollments • <?php echo $revokedKeys; ?> revoked</div></div>
        </div>
      </a>
    </div>
    <div class="col-6 col-xl-3">
      <a href="./?page=admin/device_logs&tab=logs&status=key_registered" title="View key enrollments" class="horizon-stat-card shadow-sm<?php echo ($tab==='logs' && $statusFilter==='key_registered')?' stat-active':''; ?>" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color:#fff">
        <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
          <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-check"></i></div>
          <div><div class="fw-bold fs-4 lh-1"><?php echo $keyRegs; ?></div><small class="opacity-75">Key Enrollments</small><div style="font-size:.70rem;opacity:.75">hardware keys bound</div></div>
        </div>
      </a>
    </div>
    <div class="col-6 col-xl-3">
      <a href="./?page=admin/device_logs&tab=logs&status=__blocked" title="View blocked attempts" class="horizon-stat-card shadow-sm<?php echo ($tab==='logs' && $statusFilter==='__blocked')?' stat-active':''; ?>" style="background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color:#fff">
        <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
          <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-ban"></i></div>
          <div><div class="fw-bold fs-4 lh-1"><?php echo $blockedLogs; ?></div><small class="opacity-75">Blocked Attempts</small><div style="font-size:.70rem;opacity:.75">key / PIN / race</div></div>
        </div>
      </a>
    </div>
    <div class="col-6 col-xl-3">
      <a href="./?page=admin/device_logs&tab=logs" title="View all audit events" class="horizon-stat-card shadow-sm<?php echo ($tab==='logs' && $statusFilter==='')?' stat-active':''; ?>" style="background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%); color:#fff">
        <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
          <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-list"></i></div>
          <div><div class="fw-bold fs-4 lh-1" data-total-logs><?php echo $totalLogs; ?></div><small class="opacity-75">Total Audit Events</small><div style="font-size:.70rem;opacity:.75"><?php echo $todayLogs; ?> today</div></div>
        </div>
      </a>
    </div>
  </div>

  <div class="card shadow-sm border-0 mb-4" style="border-radius:16px;overflow:hidden">
    <div class="card-body p-3">
      <ul class="nav nav-pills gap-2 mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <a class="nav-link <?php echo $tab==='keys'?'active':''; ?>" href="./?page=admin/device_logs&tab=keys<?php echo $q!==''?'&q='.urlencode($q):''; ?><?php echo $yearFilter!==''?'&year='.urlencode($yearFilter):''; ?><?php echo $sectionFilter!==''?'&section='.urlencode($sectionFilter):''; ?>"><i class="fas fa-key me-1"></i> Keys <span class="badge bg-light text-dark ms-1" data-keys-count><?php echo $keysCount; ?></span></a>
        </li>
        <li class="nav-item" role="presentation">
          <a class="nav-link <?php echo $tab==='logs'?'active':''; ?>" href="./?page=admin/device_logs&tab=logs<?php echo $q!==''?'&q='.urlencode($q):''; ?><?php echo $statusFilter!==''?'&status='.urlencode($statusFilter):''; ?>"><i class="fas fa-clock-rotate-left me-1"></i> Audit Logs <span class="badge bg-light text-dark ms-1"><?php echo $logsCount; ?></span></a>
        </li>
      </ul>

      <form method="GET" class="filter-bar row g-2 align-items-end mb-3">
        <input type="hidden" name="page" value="admin/device_logs">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <div class="col-12 <?php echo $isRosterTab ? 'col-md-4' : 'col-md-5'; ?>">
          <label class="form-label small fw-semibold mb-1">Search</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="Student number, device build, fingerprint, IP…">
          </div>
        </div>
        <?php if ($isRosterTab): ?>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Year</label>
          <select name="year" class="form-select">
            <option value="">All years</option>
            <?php foreach ($yearOptions as $yo): ?>
              <option value="<?php echo htmlspecialchars($yo); ?>" <?php echo $yearFilter===$yo?'selected':''; ?>><?php echo htmlspecialchars($yo); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Section</label>
          <select name="section" class="form-select">
            <option value="">All sections</option>
            <?php foreach ($sectionOptions as $so): ?>
              <option value="<?php echo htmlspecialchars($so); ?>" <?php echo $sectionFilter===$so?'selected':''; ?>><?php echo htmlspecialchars($so); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($tab==='logs'): ?>
        <div class="col-12 col-md-3">
          <label class="form-label small fw-semibold mb-1">Status</label>
          <select name="status" class="form-select">
            <option value="">All statuses</option>
            <option value="__success" <?php echo $statusFilter==='__success'?'selected':''; ?>>Successful logins (success + key auth + deferred binds)</option>
            <option value="__blocked" <?php echo $statusFilter==='__blocked'?'selected':''; ?>>Blocked attempts</option>
            <?php foreach ($allStatuses as $st): ?>
              <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter===$st?'selected':''; ?>><?php echo htmlspecialchars($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-12 col-md-4 d-flex gap-2">
          <button type="submit" class="btn btn-primary rounded-pill flex-fill"><i class="fas fa-filter me-1"></i> Filter</button>
          <a href="./?page=admin/device_logs&tab=<?php echo $tab; ?>" class="btn btn-outline-secondary rounded-pill">Clear</a>
        </div>
      </form>

      <?php if ($tab==='keys'): ?>
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <small class="text-muted"><?php echo $keysCount; ?> key<?php echo $keysCount === 1 ? '' : 's'; ?> — sort, search and page via the table controls</small>
        <small class="text-muted"><i class="fas fa-circle-info me-1"></i>Strict 1 device per student — one active hardware key per number</small>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 w-100" id="keysTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Student</th>
              <th>Security Key</th>
              <th>Status</th>
              <th>Enrolled</th>
              <th>Last Seen</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($keysRows as $idx => $k): ?>
              <?php
                $kActive = ((int)($k['is_active'] ?? 0)) === 1;
                $kFp = strtoupper(substr(hash('sha256', preg_replace('/\s+/', '', (string)($k['public_key'] ?? ''))), 0, 16));
              ?>
              <tr>
                <td class="text-muted small"><?php echo $idx + 1; ?></td>
                <td>
                  <div class="fw-semibold" style="color:gold;"><?php echo htmlspecialchars($k['student_number']); ?></div>
                  <text class="fw-semibold"><?php echo htmlspecialchars($k['student_name'] ?? '—'); ?></text>
                </td>
                <td>
                  <code title="<?php echo htmlspecialchars($k['credential_id'] ?? '', ENT_QUOTES); ?>" style="font-size:.78rem"><?php echo htmlspecialchars(substr((string)($k['credential_id'] ?? ''), 0, 12)); ?>…</code>
                  <br><small class="text-muted"><i class="fas fa-fingerprint me-1"></i>key <?php echo htmlspecialchars($kFp !== '' ? $kFp : '—'); ?> · <?php echo (int)($k['sign_count'] ?? 0); ?> signatures</small>
                </td>
                <td><?php echo $kActive ? '<span class="badge bg-success" style="font-size:.70rem"><i class="fas fa-link me-1"></i>Active</span>' : '<span class="badge bg-secondary" style="font-size:.70rem"><i class="fas fa-ban me-1"></i>Revoked</span>'; ?></td>
                <td data-order="<?php echo htmlspecialchars($k['created_at'] ?? '', ENT_QUOTES); ?>"><small><?php echo hgs_ph_display($k['created_at'] ?? null); ?></small></td>
                <td data-order="<?php echo htmlspecialchars($k['last_seen'] ?? '', ENT_QUOTES); ?>"><small><?php echo hgs_ph_display($k['last_seen'] ?? null); ?></small></td>
                <td class="text-center">
                  <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary view-key" title="View key details"
                      data-sn="<?php echo htmlspecialchars($k['student_number'], ENT_QUOTES); ?>"
                      data-name="<?php echo htmlspecialchars($k['student_name'] ?? '', ENT_QUOTES); ?>"
                      data-keyid="<?php echo htmlspecialchars($k['credential_id'] ?? '', ENT_QUOTES); ?>"
                      data-keyfp="<?php echo htmlspecialchars($kFp, ENT_QUOTES); ?>"
                      data-keycount="<?php echo (int)($k['sign_count'] ?? 0); ?>"
                      data-active="<?php echo $kActive ? '1' : '0'; ?>"
                      data-first="<?php echo htmlspecialchars($k['created_at'] ?? '', ENT_QUOTES); ?>"
                      data-first-ph="<?php echo htmlspecialchars(hgs_ph_display($k['created_at'] ?? null, 'M d, Y g:i:s A'), ENT_QUOTES); ?>"
                      data-last="<?php echo htmlspecialchars($k['last_seen'] ?? '', ENT_QUOTES); ?>"
                      data-last-ph="<?php echo htmlspecialchars(hgs_ph_display($k['last_seen'] ?? null, 'M d, Y g:i:s A'), ENT_QUOTES); ?>"><i class="fas fa-eye"></i></button>
                    <button type="button" class="btn btn-outline-secondary pin-for-btn" title="Open login-PIN tools for this number" data-sn="<?php echo htmlspecialchars($k['student_number'], ENT_QUOTES); ?>"><i class="fas fa-key"></i></button>
                    <?php if ($kActive): ?>
                    <button type="button" class="btn btn-outline-danger revoke-btn" title="Revoke this key — new enrollment allowed on next login" data-sn="<?php echo htmlspecialchars($k['student_number'], ENT_QUOTES); ?>"><i class="fas fa-ban"></i></button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php else: /* logs tab */ ?>
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <small class="text-muted"><?php echo $logsCapped ? 'Showing latest ' . count($logsRows) . ' of ' . $logsCount . ' events — refine filters to narrow' : $logsCount . ' event' . ($logsCount === 1 ? '' : 's'); ?></small>
        <div class="d-flex gap-2">
          <form method="POST" class="d-inline clear-logs-form" data-confirm="Clear logs older than 30 days? This cannot be undone." data-label="Clear >30 days">
            <input type="hidden" name="device_action" value="clear_logs"><input type="hidden" name="older_than" value="30">
            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill"><i class="fas fa-trash me-1"></i> Clear &gt;30 days</button>
          </form>
          <form method="POST" class="d-inline clear-logs-form" data-confirm="Clear ALL logs? This cannot be undone." data-label="Clear all">
            <input type="hidden" name="device_action" value="clear_logs"><input type="hidden" name="older_than" value="all">
            <button type="submit" class="btn btn-sm btn-danger rounded-pill"><i class="fas fa-trash-can me-1"></i> Clear all</button>
          </form>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 w-100" id="logsTable">
          <thead>
            <tr>
              <th style="width:46px">#</th>
              <th>Time</th>
              <th>Student</th>
              <th>Device Build</th>
              <th>Fingerprint</th>
              <th>IP</th>
              <th>Status</th>
              <th class="text-center">Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logsRows as $idx => $l): ?>
              <tr>
                <td class="text-muted small"><?php echo $idx + 1; ?></td>
                <td data-order="<?php echo htmlspecialchars($l['created_at'] ?? '', ENT_QUOTES); ?>"><small style="white-space:nowrap"><?php echo hgs_ph_display($l['created_at'] ?? null, 'M d, Y g:i:s A'); ?></small></td>
                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($l['student_number']); ?></span>
                  <?php
                    $am = hgs_log_attempt_meta($l);
                    $amName = $am['name'] ?: ($hgsNameByNumber[strtolower(trim($l['student_number']))] ?? null);
                    // Owner only from keys stored in the details JSON (legacy
                    // cross-device rows); no live fingerprint lookup anymore.
                    $amOwnerSn = trim((string)($am['owner_sn'] ?? ''));
                    $amOwnerName = $am['owner_name'] ?: ($amOwnerSn !== '' ? ($hgsNameByNumber[strtolower($amOwnerSn)] ?? null) : null);
                    if ($amOwnerSn !== '' && strtolower($amOwnerSn) !== strtolower(trim($l['student_number']))) {
                        $who1 = !empty($amName) ? $amName . ' (' . $l['student_number'] . ')' : $l['student_number'];
                        $who2 = !empty($amOwnerName) ? $amOwnerName . ' (' . $amOwnerSn . ')' : $amOwnerSn;
                        echo '<br><small class="text-warning" title="This login attempt came from a device already registered to another student"><i class="fas fa-mobile-screen me-1"></i>' . htmlspecialchars($who1) . ' attempted to login to the device of ' . htmlspecialchars($who2) . '</small>';
                    } elseif (!empty($amName)) {
                        echo '<br><text class="fw-semibold">' . htmlspecialchars($amName) . '</text>';
                    }
                  ?>
                </td>
                <td><small><?php echo htmlspecialchars($l['device_model'] ?: '—'); ?></small></td>
                <td><?php echo hgs_fp_short($l['device_fingerprint']); ?></td>
                <td><small class="text-muted"><?php echo htmlspecialchars($l['ip_address'] ?? '—'); ?></small></td>
                <td><?php echo hgs_status_badge($l['status'], true); ?></td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-outline-secondary view-log"
                    data-log-id="<?php echo (int)($l['id'] ?? 0); ?>"
                    data-sn="<?php echo htmlspecialchars($l['student_number'], ENT_QUOTES); ?>"
                    data-attempt-name="<?php echo htmlspecialchars($amName ?? '', ENT_QUOTES); ?>"
                    data-owner-sn="<?php echo htmlspecialchars($amOwnerSn ?? '', ENT_QUOTES); ?>"
                    data-owner-name="<?php echo htmlspecialchars($amOwnerName ?? '', ENT_QUOTES); ?>"
                    data-status="<?php echo htmlspecialchars($l['status'], ENT_QUOTES); ?>"
                    data-model="<?php echo htmlspecialchars($l['device_model'] ?? '', ENT_QUOTES); ?>"
                    data-fp="<?php echo htmlspecialchars($l['device_fingerprint'] ?? '', ENT_QUOTES); ?>"
                    data-ip="<?php echo htmlspecialchars($l['ip_address'] ?? '', ENT_QUOTES); ?>"
                    data-ua="<?php echo htmlspecialchars($l['user_agent'] ?? '', ENT_QUOTES); ?>"
                    data-details="<?php echo htmlspecialchars($l['details'] ?? '', ENT_QUOTES); ?>"
                    data-time="<?php echo htmlspecialchars($l['created_at'] ?? '', ENT_QUOTES); ?>"
                    data-time-ph="<?php echo htmlspecialchars(hgs_ph_display($l['created_at'] ?? null, 'M d, Y g:i:s A'), ENT_QUOTES); ?>"><i class="fas fa-eye"></i></button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ================= Modals ================= -->
<!-- 1. Key detail (read-only inspector: credential mapping proof) -->
<div class="modal fade" id="keyDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header" style="background:var(--horizon-brand-linear);color:#fff">
        <h6 class="modal-title fw-bold"><i class="fas fa-key me-2"></i>Security Key Detail</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="font-size:.90rem" id="keyDetailBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill" id="keyDetailRevokeBtn" style="display:none"><i class="fas fa-ban me-1"></i>Revoke this key</button>
        <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<!-- Revoke-device confirm modal (phase 4.4 admin-mediated path) -->
<div class="modal fade modern-modal" id="revokeConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="fas fa-ban text-danger me-2"></i>Revoke Device Key?</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger mb-3" style="width:56px;height:56px;"><i class="fas fa-key fa-lg"></i></div>
        <p class="mb-1 fw-bold">Revoke the security key for <span id="revokeConfirmSn"></span>?</p>
        <small class="text-muted d-block">The old phone stops working immediately. Their next login (number + PIN) enrolls the new device as the single active key. Verify the student's identity first — this cannot be undone, only re-enrolled.</small>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-2">
        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger rounded-pill px-4" id="revokeConfirmBtn"><i class="fas fa-ban me-1"></i>Revoke key</button>
      </div>
    </div>
  </div>
</div>
<!-- Log detail modal -->
<div class="modal fade" id="logDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header bg-dark text-white">
        <h6 class="modal-title fw-bold"><i class="fas fa-clock-rotate-left me-2"></i>Log Detail</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="font-size:.90rem" id="logDetailBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- Student PIN modal: students choose their own PIN on first binding; the
     teacher's only PIN powers are Check state, Reset lock (keep PIN), and
     Clear PIN (forgotten PIN — student picks a fresh one on next login). -->
<div class="modal fade" id="pinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="fas fa-key text-warning me-2"></i>Student Login PIN</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="pinStudentNumber" class="form-label small fw-semibold">Student Number</label>
          <input type="text" class="form-control" id="pinStudentNumber" placeholder="XX-XXXXX" maxlength="8" pattern="^\d{2}-\d{5}$" autocomplete="off">
          <div class="form-text small">Format <strong>XX-XXXXX</strong> (e.g., 25-12345)</div>
        </div>
        <div class="d-grid gap-2 mb-2">
          <button type="button" class="btn btn-outline-primary" id="pinStatusBtn"><i class="fas fa-magnifying-glass me-1"></i> Check PIN state</button>
        </div>
        <div id="pinStatusLine" class="small mt-2 text-muted">Type a number, then Check.</div>
        <hr class="my-3">
        <div class="d-grid gap-2">
          <button type="button" class="btn btn-outline-warning" id="pinResetBtn" style="display:none"><i class="fas fa-unlock-keyhole me-1"></i> Clear PIN lock</button>
          <button type="button" class="btn btn-outline-danger" id="pinClearBtn" style="display:none"><i class="fas fa-trash me-1"></i> Clear login PIN</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk PIN console: the class-wide versions of the per-number tools above
     (start-of-year reset / mass recovery). Same powers, same limits — the
     teacher can reset every lock or delete every PIN, never read or set one.
     Hardware security keys stay bound; only student_bind_pins rows change. -->
<div class="modal fade" id="pinBulkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="fas fa-layer-group text-danger me-2"></i>All Student Login PINs</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3"><i class="fas fa-circle-info me-1"></i><strong><?php echo (int)$pinIssuedCount; ?></strong> student<?php echo $pinIssuedCount === 1 ? '' : 's'; ?> currently have a login PIN set. Hardware security keys are not affected here — only the login PINs.</p>
        <div class="d-grid gap-2">
          <button type="button" class="btn btn-outline-warning" id="pinBulkUnlockBtn"><i class="fas fa-unlock-keyhole me-1"></i> Reset all PIN locks <small class="fw-normal opacity-75">(keeps every PIN)</small></button>
          <button type="button" class="btn btn-outline-danger" id="pinBulkClearBtn" data-count="<?php echo (int)$pinIssuedCount; ?>"><i class="fas fa-trash-can me-1"></i> Delete ALL login PINs</button>
        </div>
        <div id="pinBulkLine" class="small mt-2 text-muted">After a delete, every student re-chooses their PIN on the next login.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
function hgsCopyFp(btn){
  const fp = btn.getAttribute('data-fp')||'';
  if(!fp) return;
  navigator.clipboard && navigator.clipboard.writeText(fp).then(()=>{
    const old = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i>';
    setTimeout(()=> btn.innerHTML = old, 1200);
    if(window.showAppToast) window.showAppToast('success','Copied');
  }).catch(()=>{
    prompt('Value:', fp);
  });
}
document.addEventListener('DOMContentLoaded', function(){
  // ---- Endpoint + toast plumbing (shared by every flow below) ----
  // Direct endpoint (bypasses the Horizon layout) so AJAX JSON stays parseable.
  const DEVICE_LOGS_ENDPOINT = 'admin/device_logs.php';
  const PENDING_TOAST_KEY = 'horizon_device_logs_toast';

  function hgsToast(type, msg){
    if (window.showAppToast) { window.showAppToast(type, msg); return; }
    const stack = document.getElementById('horizon-toast-stack');
    if (!stack) { alert(msg); return; }
    const el = document.createElement('div');
    el.className = 'horizon-toast horizon-toast-' + (type === 'error' ? 'danger' : type);
    el.innerHTML = '<div class="horizon-toast-content"><div class="horizon-toast-message"></div></div>';
    el.querySelector('.horizon-toast-message').textContent = msg;
    stack.appendChild(el);
    setTimeout(() => el.remove(), 4000);
  }

  // Toast that survives the post-action redirect/reload: exactly one alert
  // per action (an immediate toast PLUS a queued one is what used to double).
  function queueToastForReload(type, msg){
    try { sessionStorage.setItem(PENDING_TOAST_KEY, JSON.stringify({type, msg})); } catch(e){}
  }
  try {
    const pending = JSON.parse(sessionStorage.getItem(PENDING_TOAST_KEY) || 'null');
    if (pending && pending.msg) { hgsToast(pending.type || 'success', pending.msg); }
    sessionStorage.removeItem(PENDING_TOAST_KEY);
  } catch(e){}

  async function parseDeviceLogsResponse(res){
    const text = await res.text();
    const trimmed = text.replace(/^\uFEFF/, '').trim();
    if (!trimmed) throw new Error('empty');
    try { return JSON.parse(trimmed); } catch(e){}
    const start = trimmed.indexOf('{');
    const end = trimmed.lastIndexOf('}');
    if (start !== -1 && end > start) {
      try { return JSON.parse(trimmed.slice(start, end + 1)); } catch(e){}
    }
    throw new Error('non-json');
  }

  function deviceLogsPost(body){
    return fetch(DEVICE_LOGS_ENDPOINT, {
      method: 'POST',
      headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
      body
    }).then(async (res)=>{
      let data = null;
      try { data = await parseDeviceLogsResponse(res); } catch(e){ data = null; }
      if (data && typeof data.status !== 'undefined') return data;
      throw new Error(res.ok ? 'unexpected response (are you still logged in?)' : ('HTTP ' + res.status));
    });
  }
  function deviceLogsSessionExpired(err){
    const m = ((err && err.message) || '').toLowerCase();
    if (m.indexOf('logged in') !== -1 || m.indexOf('login') !== -1) {
      window.location.href = 'login.php';
      return true;
    }
    return false;
  }
  function hgsSweepBackdrops(){
    try {
      if (!document.querySelector('.modal.show')) {
        document.querySelectorAll('.modal-backdrop').forEach(b=>b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
      }
    } catch(e){}
  }
  document.addEventListener('hidden.bs.modal', function(){ setTimeout(hgsSweepBackdrops, 60); });
  function hgsCloseModal(el){
    if (!el) return;
    try {
      const inst = (window.bootstrap && bootstrap.Modal)
        ? (bootstrap.Modal.getInstance(el) || bootstrap.Modal.getOrCreateInstance(el))
        : null;
      if (inst) inst.hide();
    } catch(e){}
    setTimeout(hgsSweepBackdrops, 400);
  }
  function hgsDecrementKeysCount(){
    document.querySelectorAll('[data-keys-count]').forEach(function(el){
      const v = parseInt((el.textContent || '').replace(/\D/g,''), 10);
      if (!isNaN(v)) el.textContent = Math.max(0, v - 1);
    });
  }
  function hgsIncrementTotalLogs(){
    document.querySelectorAll('[data-total-logs]').forEach(function(el){
      const v = parseInt((el.textContent || '').replace(/\D/g,''), 10);
      if (!isNaN(v)) el.textContent = v + 1;
    });
  }
  // ---- Key detail modal (read-only mapping proof) ----
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.view-key');
    if (!btn || !document.contains(btn)) return;
    const d = btn.dataset;
    const esc = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');
    const rk = document.getElementById('keyDetailRevokeBtn');
    if (rk) {
      rk.style.display = d.active === '1' ? '' : 'none';
      rk.setAttribute('data-sn', d.sn || '');
    }
    const body = document.getElementById('keyDetailBody');
    body.innerHTML = `
      <div class="row g-3">
        <div class="col-md-6"><strong>Student Number</strong><div><span class="badge bg-light text-dark border">${esc(d.sn)}</span></div><small class="text-muted">${esc(d.name)||'—'}</small></div>
        <div class="col-md-6"><strong>Status</strong><div>${d.active === '1' ? '<span class="badge bg-success">Active — sole bound device</span>' : '<span class="badge bg-secondary">Revoked</span>'}</div><small class="text-muted">One active key per number, enforced by the database.</small></div>
        <div class="col-12"><strong>Credential ID</strong><div><code style="word-break:break-all">${esc(d.keyid)||'—'}</code> <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('${(d.keyid||'').replace(/'/g,"\\'")}')"><i class='fas fa-copy'></i> Copy</button></div><small class="text-muted">Stored server-side at enrollment; the private half never leaves the phone.</small></div>
        <div class="col-md-6"><strong>Public-key fingerprint</strong><div><code>${esc(d.keyfp)||'—'}</code></div><small class="text-muted">SHA-256 of the stored public key.</small></div>
        <div class="col-md-6"><strong>Signatures verified</strong><div>${esc(d.keycount || '0')}</div><small class="text-muted">Replayed counters are rejected automatically.</small></div>
        <div class="col-md-6"><strong>Enrolled</strong><div>${esc(d.firstPh)||esc(d.first)||'—'}</div></div>
        <div class="col-md-6"><strong>Last seen</strong><div>${esc(d.lastPh)||esc(d.last)||'—'}</div></div>
      </div>`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('keyDetailModal')).show();
  });
  // ---- Revoke flow (phase 4.4 admin-mediated path) ----
  let pendingRevoke = null;
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.revoke-btn');
    if (!btn || !document.contains(btn)) return;
    pendingRevoke = btn.getAttribute('data-sn') || '';
    if (!pendingRevoke) return;
    document.getElementById('revokeConfirmSn').textContent = pendingRevoke;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('revokeConfirmModal')).show();
  });
  const revokeDetailBtn = document.getElementById('keyDetailRevokeBtn');
  if (revokeDetailBtn) revokeDetailBtn.addEventListener('click', function(){
    const sn = revokeDetailBtn.getAttribute('data-sn') || '';
    if (!sn) return;
    pendingRevoke = sn;
    hgsCloseModal(document.getElementById('keyDetailModal'));
    document.getElementById('revokeConfirmSn').textContent = sn;
    setTimeout(function(){
      bootstrap.Modal.getOrCreateInstance(document.getElementById('revokeConfirmModal')).show();
    }, 150);
  });
  const revokeConfirmBtn = document.getElementById('revokeConfirmBtn');
  if (revokeConfirmBtn) revokeConfirmBtn.addEventListener('click', function(){
    if (!pendingRevoke) return;
    const sn = pendingRevoke;
    pendingRevoke = null;
    const cbtn = this;
    cbtn.disabled = true;
    cbtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Revoking…';
    deviceLogsPost('device_action=revoke_key&student_number=' + encodeURIComponent(sn) + '&_ajax=1').then(j=>{
      if (j && j.status === 'success') {
        const msg = j.msg || ('Key revoked for ' + sn + '.');
        hgsCloseModal(document.getElementById('revokeConfirmModal'));
        // Remove the row inline when visible — no reload.
        let gone = false;
        try {
          document.querySelectorAll('.revoke-btn').forEach(function(b){
            if (!gone && b.getAttribute('data-sn') === sn) {
              const tr = b.closest('tr');
              if (window.jQuery && jQuery.fn.DataTable) {
                const $tbl = jQuery('#keysTable');
                if ($tbl.length && jQuery.fn.DataTable.isDataTable($tbl) && tr) {
                  $tbl.DataTable().row(tr).remove().draw(false);
                  gone = true;
                }
              }
              if (!gone && tr) {
                tr.style.transition = 'opacity .25s ease';
                tr.style.opacity = '0';
                setTimeout(()=>tr.remove(), 260);
                gone = true;
              }
            }
          });
        } catch(e){}
        if (gone) {
          hgsDecrementKeysCount();
          hgsIncrementTotalLogs();
          hgsToast('success', msg);
        } else {
          queueToastForReload('success', msg);
          setTimeout(()=>{ window.location.href = './?page=admin/device_logs&tab=keys'; }, 1100);
        }
      } else {
        hgsToast('danger', (j && j.msg) || 'Revoke failed. Please try again.');
        cbtn.disabled = false;
        cbtn.innerHTML = '<i class="fas fa-ban me-1"></i>Revoke key';
      }
    }).catch((err)=>{
      cbtn.disabled = false;
      cbtn.innerHTML = '<i class="fas fa-ban me-1"></i>Revoke key';
      if (!deviceLogsSessionExpired(err)) hgsToast('danger', 'Request failed: ' + ((err && err.message) || 'network error'));
    });
  });
  const revokeModalEl = document.getElementById('revokeConfirmModal');
  if (revokeModalEl) revokeModalEl.addEventListener('hidden.bs.modal', function(){
    pendingRevoke = null;
    const cbtn = document.getElementById('revokeConfirmBtn');
    if (cbtn) { cbtn.disabled = false; cbtn.innerHTML = '<i class="fas fa-ban me-1"></i>Revoke key'; }
  });
  // ---- Per-row PIN shortcut: open the PIN modal prefilled for the number ----
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.pin-for-btn');
    if (!btn || !document.contains(btn)) return;
    const sn = btn.getAttribute('data-sn') || '';
    const input = document.getElementById('pinStudentNumber');
    if (input && sn) input.value = sn;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('pinModal')).show();
    if (sn) {
      const chk = document.getElementById('pinStatusBtn');
      if (chk) setTimeout(function(){ chk.click(); }, 250);
    }
  });
  // ---- Tooltips inside DataTables must be rebound after every redraw ----
  try {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
      if (window.bootstrap && bootstrap.Tooltip) bootstrap.Tooltip.getOrCreateInstance(el);
    });
  } catch (e) {}
  if (window.jQuery && jQuery.fn.DataTable) {
    try {
      jQuery('#logsTable, #keysTable').on('draw.dt', function(){
        try {
          document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
            if (window.bootstrap && bootstrap.Tooltip) bootstrap.Tooltip.getOrCreateInstance(el);
          });
        } catch (e) {}
      });
    } catch (e) {}
  }
  // ---- Log detail modal (read-only inspector) ----
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.view-log');
    if (!btn || !document.contains(btn)) return;
    const d = btn.dataset;
    const esc = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');
    let detailsPretty = esc(d.details);
    try{ const j = JSON.parse(d.details||''); if(j && typeof j==='object') detailsPretty = '<pre style="white-space:pre-wrap;word-break:break-all;background:var(--horizon-table-hover);padding:10px;border-radius:8px;margin:0">'+esc(JSON.stringify(j,null,2))+'</pre>'; }catch(e){}
    let whoHtml = '';
    try {
      const dd = JSON.parse(d.details || 'null');
      if (dd && typeof dd === 'object') {
        const an = d.attemptName || dd.attempt_name || '';
        if (an) whoHtml += `<div class="col-12"><strong>Attempt by</strong><div>${esc(an)}</div></div>`;
      }
    } catch(e){}
    const body = document.getElementById('logDetailBody');
    body.innerHTML = `
        <div class="d-flex flex-wrap gap-2 mb-3"><span class="badge bg-light text-dark border">${esc(d.sn)}</span> <span class="badge bg-secondary">${esc(d.status)}</span> <small class="text-muted">${esc(d.timePh)||esc(d.time)}</small></div>
      <div class="row g-3">
        ${whoHtml}
        <div class="col-md-6"><strong>Device Build</strong><div>${esc(d.model)||'—'}</div></div>
        <div class="col-md-6"><strong>IP</strong><div>${esc(d.ip)||'—'}</div></div>
        <div class="col-12"><strong>Fingerprint</strong><div><code style="word-break:break-all">${esc(d.fp)||'—'}</code></div></div>
        <div class="col-12"><strong>User Agent</strong><div style="word-break:break-all;font-size:.80rem;color:var(--horizon-text-muted)">${esc(d.ua)||'—'}</div></div>
        <div class="col-12"><strong>Details</strong><div>${detailsPretty}</div></div>
      </div>`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('logDetailModal')).show();
  });
  // ---- Log maintenance (prune audit trail; success toast survives reload) ----
  document.querySelectorAll('.clear-logs-form').forEach(function(form){
    form.addEventListener('submit', function(e){
      e.preventDefault();
      if(!confirm(form.getAttribute('data-confirm') || 'Clear logs? This cannot be undone.')) return;
      const btn = form.querySelector('button[type="submit"]');
      const original = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Clearing…'; }
      const olderThan = (form.querySelector('input[name="older_than"]') || {}).value || '30';
      deviceLogsPost('device_action=clear_logs&older_than=' + encodeURIComponent(olderThan) + '&_ajax=1').then(j=>{
        if(j && j.status==='success'){
          const msg = j.msg || 'Logs cleared.';
          queueToastForReload('success', msg);
          setTimeout(()=>{ window.location.href = './?page=admin/device_logs&tab=logs'; }, 1100);
        }
        else { hgsToast('danger', (j && j.msg) || 'Clear logs failed. Please try again.'); if (btn) { btn.disabled=false; btn.innerHTML=original; } }
      }).catch((err)=>{ hgsToast('danger', 'Request failed: ' + ((err && err.message) || 'network error')); if (btn) { btn.disabled=false; btn.innerHTML=original; } });
    });
  });
  // ---- Student PIN tools (no reload: single toast per action) ----
  function pinModalSn(){
    const el = document.getElementById('pinStudentNumber');
    return ((el && el.value) || '').trim();
  }
  function pinStatusLine(msg, isErr){
    const el = document.getElementById('pinStatusLine');
    if (!el) return;
    el.textContent = msg;
    el.className = 'small mt-2 ' + (isErr ? 'text-danger' : 'text-muted');
  }
  const pinStatusBtn = document.getElementById('pinStatusBtn');
  if (pinStatusBtn) pinStatusBtn.addEventListener('click', function(){
    const sn = pinModalSn();
    if (!/^\d{2}-\d{5}$/.test(sn)) { pinStatusLine('Invalid student number. Use XX-XXXXX.', true); return; }
    deviceLogsPost('device_action=pin_status&student_number=' + encodeURIComponent(sn) + '&_ajax=1').then(j=>{
      if (!j || j.status !== 'success') {
        pinStatusLine((j && j.msg) || 'Could not read PIN status.', true);
        return;
      }
      pinStatusLine(j.msg || '', false);
      const rb = document.getElementById('pinResetBtn');
      const cb = document.getElementById('pinClearBtn');
      if (rb) rb.style.display = (j.active && j.locked) ? '' : 'none';
      if (cb) cb.style.display = j.active ? '' : 'none';
    }).catch((err)=>{
      pinStatusLine('Request failed: ' + ((err && err.message) || 'network error'), true);
    });
  });
  const pinResetBtn = document.getElementById('pinResetBtn');
  if (pinResetBtn) pinResetBtn.addEventListener('click', function(){
    const sn = pinModalSn();
    if (!/^\d{2}-\d{5}$/.test(sn)) { pinStatusLine('Invalid student number. Use XX-XXXXX.', true); return; }
    if (!confirm('Clear the PIN lock for ' + sn + '? The PIN itself stays the same.')) return;
    deviceLogsPost('device_action=pin_reset_lock&student_number=' + encodeURIComponent(sn) + '&_ajax=1').then(j=>{
      if (j && j.status === 'success') {
        pinStatusLine(j.msg || ('Lock cleared for ' + sn + '.'), false);
        pinResetBtn.style.display = 'none';
        hgsToast('success', j.msg || ('Lock cleared for ' + sn + '.'));
      } else {
        pinStatusLine((j && j.msg) || 'Reset failed. Please try again.', true);
        hgsToast('danger', (j && j.msg) || 'Reset failed. Please try again.');
      }
    }).catch((err)=>{
      if (!deviceLogsSessionExpired(err)) hgsToast('danger', 'Request failed: ' + ((err && err.message) || 'network error'));
    });
  });
  const pinClearBtn = document.getElementById('pinClearBtn');
  if (pinClearBtn) pinClearBtn.addEventListener('click', function(){
    const sn = pinModalSn();
    if (!/^\d{2}-\d{5}$/.test(sn)) { pinStatusLine('Invalid student number. Use XX-XXXXX.', true); return; }
    if (!confirm('Clear the login PIN for ' + sn + '? They will choose a brand-new PIN on next login. Use this when they forgot it.')) return;
    const cbtn = this;
    const original = cbtn.innerHTML;
    cbtn.disabled = true;
    cbtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Clearing…';
    deviceLogsPost('device_action=pin_clear&student_number=' + encodeURIComponent(sn) + '&_ajax=1').then(j=>{
      cbtn.disabled = false;
      cbtn.innerHTML = original;
      if (j && j.status === 'success') {
        pinStatusLine(j.msg || ('PIN cleared for ' + sn + '.'), false);
        pinClearBtn.style.display = 'none';
        document.getElementById('pinResetBtn').style.display = 'none';
        hgsToast('success', j.msg || ('PIN cleared for ' + sn + '.'));
      } else {
        pinStatusLine((j && j.msg) || 'Clear failed. Please try again.', true);
        hgsToast('danger', (j && j.msg) || 'Clear failed. Please try again.');
      }
    }).catch((err)=>{
      cbtn.disabled = false;
      cbtn.innerHTML = original;
      if (!deviceLogsSessionExpired(err)) hgsToast('danger', 'Request failed: ' + ((err && err.message) || 'network error'));
    });
  });
  // ---- Bulk PIN console (class-wide: reset every lock / delete every PIN) ----
  function pinBulkLine(msg, isErr){
    const el = document.getElementById('pinBulkLine');
    if (!el) return;
    el.textContent = msg;
    el.className = 'small mt-2 ' + (isErr ? 'text-danger' : 'text-muted');
  }
  const pinBulkUnlockBtn = document.getElementById('pinBulkUnlockBtn');
  if (pinBulkUnlockBtn) pinBulkUnlockBtn.addEventListener('click', function(){
    const b = this;
    const original = b.innerHTML;
    b.disabled = true;
    b.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Resetting…';
    deviceLogsPost('device_action=pins_reset_all&_ajax=1').then(j=>{
      b.disabled = false;
      b.innerHTML = original;
      if (j && j.status === 'success') {
        pinBulkLine(j.msg || 'All PIN locks reset.', false);
        hgsToast('success', j.msg || 'All PIN locks reset.');
      } else {
        pinBulkLine((j && j.msg) || 'Reset failed. Please try again.', true);
        hgsToast('danger', (j && j.msg) || 'Reset failed. Please try again.');
      }
    }).catch((err)=>{
      b.disabled = false;
      b.innerHTML = original;
      if (!deviceLogsSessionExpired(err)) hgsToast('danger', 'Request failed: ' + ((err && err.message) || 'network error'));
    });
  });
  const pinBulkClearBtn = document.getElementById('pinBulkClearBtn');
  if (pinBulkClearBtn) pinBulkClearBtn.addEventListener('click', function(){
    const total = pinBulkClearBtn.getAttribute('data-count') || '';
    if (!confirm('Delete EVERY login PIN' + (total ? ' (' + total + ' currently set)' : '') + '? Every student must choose a new PIN on their next login. Hardware security keys stay bound. This cannot be undone.')) return;
    const b = this;
    const original = b.innerHTML;
    b.disabled = true;
    b.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Deleting…';
    deviceLogsPost('device_action=pins_clear_all&_ajax=1').then(j=>{
      if (j && j.status === 'success') {
        // PIN counts on the page are stale after a wipe — reload once.
        queueToastForReload('success', j.msg || 'All login PINs deleted.');
        setTimeout(()=>{ window.location.href = './?page=admin/device_logs&tab=keys'; }, 1100);
      } else {
        b.disabled = false;
        b.innerHTML = original;
        pinBulkLine((j && j.msg) || 'Delete failed. Please try again.', true);
        hgsToast('danger', (j && j.msg) || 'Delete failed. Please try again.');
      }
    }).catch((err)=>{
      b.disabled = false;
      b.innerHTML = original;
      if (!deviceLogsSessionExpired(err)) hgsToast('danger', 'Request failed: ' + ((err && err.message) || 'network error'));
    });
  });
  const pinBulkModalEl = document.getElementById('pinBulkModal');
  if (pinBulkModalEl) pinBulkModalEl.addEventListener('hidden.bs.modal', function(){
    pinBulkLine('After a delete, every student re-chooses their PIN on the next login.', false);
  });
  const pinModalEl = document.getElementById('pinModal');
  if (pinModalEl) pinModalEl.addEventListener('hidden.bs.modal', function(){
    document.getElementById('pinResetBtn').style.display = 'none';
    document.getElementById('pinClearBtn').style.display = 'none';
    pinStatusLine('Type a number, then Check.', false);
  });
  // ---- Tables (DataTables over the server-rendered rows) ----
  function initDeviceLogsTable(selector, defaultOrder, emptyMsg){
    if (!window.jQuery || !jQuery.fn.DataTable) return;
    const $tbl = jQuery(selector);
    if (!$tbl.length) return;
    try { jQuery.fn.DataTable.ext.errMode = 'none'; } catch(e){}
    try {
      if (jQuery.fn.DataTable.isDataTable($tbl)) $tbl.DataTable().destroy();
      $tbl.DataTable({
        autoWidth: false,
        responsive: true,
        processing: true,
        deferRender: true,
        pageLength: 10,
        lengthMenu: [10, 15, 25, 50, 100],
        order: [defaultOrder],
        columnDefs: [
          { targets: [0, -1], orderable: false, searchable: false }
        ],
        language: {
          search: '',
          searchPlaceholder: 'Search table…',
          lengthMenu: 'Show _MENU_ per page',
          info: 'Showing _START_–_END_ of _TOTAL_',
          infoEmpty: 'No records',
          infoFiltered: '(filtered from _MAX_ total)',
          zeroRecords: '<div class="text-center text-muted py-3"><i class="fas fa-search fa-2x mb-2 d-block opacity-25"></i>No matching records.<br><small>Try a different search term.</small></div>',
          emptyTable: emptyMsg,
          paginate: { previous: '<i class="fas fa-chevron-left"></i>', next: '<i class="fas fa-chevron-right"></i>' }
        }
      });
    } catch(e){ console.warn('DataTables init failed for ' + selector, e); }
  }
  initDeviceLogsTable(
    '#keysTable',
    [5, 'desc'],
    '<div class="text-center text-muted py-4"><i class="fas fa-key fa-2x mb-2 d-block opacity-25"></i>No security keys yet. Students enroll one on first login from a capable device.<br><small>Keys appear here after enrollment.</small></div>'
  );
  initDeviceLogsTable(
    '#logsTable',
    [1, 'desc'],
    '<div class="text-center text-muted py-4"><i class="fas fa-clock-rotate-left fa-2x mb-2 d-block opacity-25"></i>No logs match your filters.<br><small>Each login attempt is recorded here in real time.</small></div>'
  );
  }); // end DOMContentLoaded (opened above the plumbing helpers)
</script>
