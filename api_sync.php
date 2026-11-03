<?php
/**
 * HORIZON SQLite3 sync endpoint.
 *
 *   GET  api_sync.php?action=status                    → server time + table counts (token or session)
 *   GET  api_sync.php?action=pull&token=...            → full JSON snapshot of syncable tables
 *   POST api_sync.php?action=push   {token, tables}    → upsert snapshot (last-writer-wins)
 *   POST api_sync.php?action=apply  {token, tables}    → alias of push (apply remote snapshot locally)
 *   GET  api_sync.php?action=config                    → { server_url } (no auth, no token leak)
 *
 * Auth: valid admin session OR shared HORIZON_SYNC_TOKEN (query ?token=,
 * header X-Sync-Token, or JSON body {token}). CORS is open to the configured
 * sync peer + localhost/LAN so a local XAMPP copy can sync with
 * https://essuc-academic.yzz.me/.
 */
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// ---------- CORS (cross-server sync: localhost/LAN <-> hosted) ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    // Reflect any http(s) origin; auth is enforced via token/session below.
    // This intentionally allows the local XAMPP copy (http://localhost,
    // http://192.168.x.x) to call the hosted server and vice versa.
    if (preg_match('#^https?://#i', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Sync-Token');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/sync_config.php';
require_once __DIR__ . '/DBConnection.php';

function sync_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sync_auth_ok(&$via)
{
    if (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
        $via = 'session';
        return true;
    }
    $token = '';
    if (isset($_GET['token'])) $token = (string)$_GET['token'];
    if ($token === '' && isset($_SERVER['HTTP_X_SYNC_TOKEN'])) $token = (string)$_SERVER['HTTP_X_SYNC_TOKEN'];
    if ($token === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'bearer ') === 0) {
        $token = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
    }
    // JSON body token is checked by the caller after parsing; stash getter
    $via = 'token:' . ($token !== '' ? 'header' : 'none');
    if ($token !== '' && hash_equals((string)HORIZON_SYNC_TOKEN, $token)) {
        return true;
    }
    return false;
}

function sync_ensure_log_table($db)
{
    @$db->exec("CREATE TABLE IF NOT EXISTS `sync_log` (
        `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        `direction` TEXT NOT NULL,
        `device_id` TEXT,
        `db_name` TEXT,
        `tables_json` TEXT,
        `applied_json` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    @$db->exec("CREATE INDEX IF NOT EXISTS idx_sync_log_created ON `sync_log`(`created_at`)");
}

/** Timestamp-ish columns we can filter on for incremental pull. */
function sync_timestamp_col($db, $table)
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    if ($res = $db->query("PRAGMA table_info('{$table}')")) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['name'])) $cols[] = strtolower((string)$row['name']);
        }
    }
    $prefs = ['updated_at', 'date_updated', 'published_date', 'created_at', 'date_created', 'updated_at_timestamp'];
    foreach ($prefs as $p) {
        if (in_array($p, $cols, true)) {
            // return actual cased name
            if ($res2 = $db->query("PRAGMA table_info('{$table}')")) {
                while ($r = $res2->fetchArray(SQLITE3_ASSOC)) {
                    if (strtolower((string)$r['name']) === $p) {
                        $cache[$table] = $r['name'];
                        return $cache[$table];
                    }
                }
            }
        }
    }
    $cache[$table] = null;
    return null;
}

function sync_table_columns($db, $table)
{
    $cols = [];
    if ($res = $db->query("PRAGMA table_info('{$table}')")) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['name'])) $cols[] = $row['name'];
        }
    }
    return $cols;
}

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? 'status'));

// Public, no-auth: JS needs to know the hosted URL without leaking the token.
if ($action === 'config') {
    sync_json([
        'status' => 'success',
        'server_url' => rtrim((string)HORIZON_SYNC_SERVER_URL, '/') . '/',
        'sync_enabled' => (bool)HORIZON_SYNC_ENABLED,
        'server_time' => date('c'),
    ]);
}

// Parse JSON body for POST push/apply (also picks up {token} there).
$body = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $body = $dec;
    }
}

$via = '';
$authed = sync_auth_ok($via);
// JSON-body token fallback
if (!$authed && is_array($body) && !empty($body['token']) && hash_equals((string)HORIZON_SYNC_TOKEN, (string)$body['token'])) {
    $authed = true;
    $via = 'token:body';
}
if (!$authed) {
    sync_json(['status' => 'error', 'message' => 'Unauthorized. Provide a valid session or X-Sync-Token.'], 401);
}

try {
    $db = new DBConnection();
} catch (Throwable $e) {
    sync_json(['status' => 'error', 'message' => 'Database unavailable.'], 500);
}
sync_ensure_log_table($db);
$syncTables = horizon_sync_tables();

if ($action === 'status') {
    $counts = [];
    foreach ($syncTables as $t => $meta) {
        try {
            $counts[$t] = (int)$db->querySingle("SELECT COUNT(*) FROM `{$t}`");
        } catch (Throwable $e) {
            $counts[$t] = -1;
        }
    }
    $tail = [];
    try {
        if ($res = $db->query("SELECT id, direction, device_id, db_name, created_at FROM `sync_log` ORDER BY id DESC LIMIT 10")) {
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) $tail[] = $r;
        }
    } catch (Throwable $e) {
    }
    sync_json([
        'status' => 'success',
        'server_time' => date('c'),
        'auth_via' => $via,
        'db_file' => basename(defined('db_file') ? (string)db_file : ''),
        'tables' => array_keys($syncTables),
        'counts' => $counts,
        'recent_syncs' => $tail,
        'token_is_default' => ((string)HORIZON_SYNC_TOKEN === 'change-me-horizon-sync-2026'),
    ]);
}

if ($action === 'pull') {
    $only = null;
    if (!empty($_GET['tables'])) {
        $only = array_filter(array_map('trim', explode(',', (string)$_GET['tables'])));
    } elseif (is_array($body) && !empty($body['tables']) && is_string($body['tables'])) {
        $only = array_filter(array_map('trim', explode(',', (string)$body['tables'])));
    }
    $since = trim((string)($_GET['since'] ?? ''));
    $out = [];
    $meta = [];
    foreach ($syncTables as $t => $cfg) {
        if (is_array($only) && !in_array($t, $only, true)) continue;
        try {
            $tsCol = ($since !== '') ? sync_timestamp_col($db, $t) : null;
            $sql = "SELECT * FROM `{$t}`";
            if ($tsCol && $since !== '') {
                // Lexicographic compare works for CURRENT_TIMESTAMP-style values.
                $sql .= " WHERE `{$tsCol}` >= '" . $db->escapeString($since) . "'";
                $meta[$t] = ['incremental' => true, 'since_col' => $tsCol];
            } else {
                $meta[$t] = ['incremental' => false];
            }
            // Safety cap per table so a snapshot never OOMs the phone.
            $sql .= " LIMIT 50000";
            $rows = [];
            if ($res = $db->query($sql)) {
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
            }
            $out[$t] = $rows;
            $meta[$t]['rows'] = count($rows);
        } catch (Throwable $e) {
            $out[$t] = [];
            $meta[$t] = ['error' => 'unavailable'];
        }
    }
    sync_json([
        'status' => 'success',
        'server_time' => date('c'),
        'db_file' => basename(defined('db_file') ? (string)db_file : ''),
        'tables' => $out,
        'meta' => $meta,
    ]);
}

if ($action === 'push' || $action === 'apply') {
    if (!is_array($body) || empty($body['tables']) || !is_array($body['tables'])) {
        sync_json(['status' => 'error', 'message' => 'Missing {tables:{...}} JSON payload.'], 400);
    }
    $deviceId = substr((string)($body['device_id'] ?? ($_GET['device_id'] ?? '')), 0, 128);
    $dbName = basename(defined('db_file') ? (string)db_file : '');
    $applied = [];
    $errors = [];
    @$db->exec('BEGIN IMMEDIATE');
    try {
        foreach ($body['tables'] as $t => $rows) {
            if (!isset($syncTables[$t]) || !is_array($rows)) continue;
            $cols = sync_table_columns($db, $t);
            if (empty($cols)) continue;
            $pk = $syncTables[$t]['pk'];
            $n = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                // Keep only real columns.
                $clean = [];
                foreach ($row as $k => $v) {
                    if (in_array($k, $cols, true)) $clean[$k] = $v;
                }
                if (empty($clean)) continue;
                // Generic upsert: UPDATE by PK first, INSERT when nothing matched.
                // (Works for mark_list which has no UNIQUE constraint, where
                // INSERT OR REPLACE would create duplicates.)
                $where = [];
                $pkVals = [];
                foreach ((array)$pk as $pkCol) {
                    if (!array_key_exists($pkCol, $clean) || $clean[$pkCol] === null || $clean[$pkCol] === '') {
                        $pkVals = null;
                        break;
                    }
                    $pkVals[$pkCol] = $clean[$pkCol];
                }
                $didUpdate = false;
                if (is_array($pkVals)) {
                    $sets = [];
                    foreach ($clean as $k => $v) {
                        if (in_array($k, (array)$pk, true)) continue;
                        if ($v === null) $sets[] = "`{$k}` = NULL";
                        else $sets[] = "`{$k}` = '" . $db->escapeString((string)$v) . "'";
                    }
                    if (!empty($sets)) {
                        $w = [];
                        foreach ($pkVals as $k => $v) {
                            $w[] = "`{$k}` = '" . $db->escapeString((string)$v) . "'";
                        }
                        $usql = "UPDATE `{$t}` SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $w);
                        try {
                            @$db->exec($usql);
                            if ($db->changes() > 0) {
                                $didUpdate = true;
                                $n++;
                            }
                        } catch (Throwable $e) {
                        }
                    } else {
                        // Row is PK-only: treat as existing.
                        $didUpdate = true;
                    }
                }
                if (!$didUpdate) {
                    $ck = [];
                    $cv = [];
                    foreach ($clean as $k => $v) {
                        $ck[] = "`{$k}`";
                        if ($v === null) $cv[] = "NULL";
                        else $cv[] = "'" . $db->escapeString((string)$v) . "'";
                    }
                    $isql = "INSERT OR IGNORE INTO `{$t}` (" . implode(', ', $ck) . ") VALUES (" . implode(', ', $cv) . ")";
                    try {
                        if (@$db->exec($isql)) $n++;
                    } catch (Throwable $e) {
                        $errors[$t] = substr($e->getMessage(), 0, 200);
                    }
                }
            }
            $applied[$t] = $n;
        }
        @$db->exec('COMMIT');
    } catch (Throwable $e) {
        @$db->exec('ROLLBACK');
        sync_json(['status' => 'error', 'message' => 'Sync failed: ' . substr($e->getMessage(), 0, 300)], 500);
    }
    try {
        $stmt = $db->prepare("INSERT INTO `sync_log` (direction, device_id, db_name, tables_json, applied_json) VALUES (:d, :dev, :db, :tj, :aj)");
        if ($stmt) {
            $stmt->bindValue(':d', $action === 'apply' ? 'apply' : 'push', SQLITE3_TEXT);
            $stmt->bindValue(':dev', $deviceId, SQLITE3_TEXT);
            $stmt->bindValue(':db', $dbName, SQLITE3_TEXT);
            $stmt->bindValue(':tj', json_encode(array_keys($body['tables'])), SQLITE3_TEXT);
            $stmt->bindValue(':aj', json_encode($applied), SQLITE3_TEXT);
            @$stmt->execute();
        }
    } catch (Throwable $e) {
    }
    sync_json([
        'status' => 'success',
        'server_time' => date('c'),
        'applied' => $applied,
        'errors' => $errors,
    ]);
}

sync_json(['status' => 'error', 'message' => 'Unknown action. Use status|pull|push|apply|config.'], 400);
