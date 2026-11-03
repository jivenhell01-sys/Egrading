<?php
// Academic Year Promotion for landing registrations (admin-only).
// - Stores a From/To promotion period.
// - Preview/apply: year + 1 and section + 1 for every registration.
// - Students reaching year 5 are graduates: confirmed separately and
//   migrated to db/graduate_db.db instead of being promoted.
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$__plr = ($_SESSION['role'] ?? '');
$__plp = [];
if (!empty($_SESSION['allowed_pages'])) { $__d = json_decode($_SESSION['allowed_pages'], true); if (is_array($__d)) $__plp = $__d; }
if (!isset($_SESSION['admin_id']) || ($__plr !== 'admin' && !in_array('landing_registrations', $__plp, true))) {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

require_once("landing_db.php");

// Graduate at this year level (configurable: 5th, 6th or 7th year depending on program).
// Students at/above it graduate instead of promoting.
define('PROMO_GRADUATE_YEARS', [5, 6, 7]);
define('PROMO_DEFAULT_GRADUATE_YEAR', 5);

function promo_db() {
    $db = landing_db();
    $db->exec("CREATE TABLE IF NOT EXISTS `promotion_settings` (
        `id` INTEGER NOT NULL PRIMARY KEY CHECK (id = 1),
        `from_date` TEXT,
        `to_date` TEXT,
        `last_applied` TEXT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    if (!$db->querySingle("SELECT COUNT(*) FROM `promotion_settings` WHERE id = 1")) {
        $db->exec("INSERT INTO `promotion_settings` (id) VALUES (1)");
    }
    // Migrate older settings tables missing cycle-tracking columns
    $cols = [];
    $cq = $db->query("PRAGMA table_info('promotion_settings')");
    while ($cr = $cq->fetchArray(SQLITE3_ASSOC)) { $cols[] = $cr['name']; }
    // last_promoted_ids: ids promoted into their current year by the last apply run.
    // They must NOT be treated as graduates just because they now sit at year 4.
    // pending_graduate_ids: graduate candidates confirmed by the last preview/apply.
    foreach (['last_promoted_ids' => "TEXT DEFAULT '[]'", 'pending_graduate_ids' => "TEXT DEFAULT '[]'", 'graduate_year' => "INTEGER DEFAULT 5"] as $col => $type) {
        if (!in_array($col, $cols)) { @$db->exec("ALTER TABLE `promotion_settings` ADD COLUMN `$col` $type"); }
    }
    return $db;
}

function promo_json_ids($json) {
    $arr = json_decode((string)$json, true);
    if (!is_array($arr)) return [];
    return array_map('intval', $arr);
}

function promo_graduate_db() {
    $path = __DIR__ . '/db/graduate_db.db';
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    $db->exec("PRAGMA busy_timeout = 5000");
    $db->exec("PRAGMA journal_mode = WAL");
    $db->exec("CREATE TABLE IF NOT EXISTS `graduates` (
        `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        `student_number` TEXT NOT NULL UNIQUE,
        `name` TEXT NOT NULL,
        `middlename` TEXT,
        `firstname` TEXT,
        `lastname` TEXT,
        `name_extension` TEXT,
        `course` TEXT,
        `year` TEXT,
        `section` TEXT,
        `birthdate` DATE,
        `age` INTEGER,
        `gender` TEXT,
        `contact_number` TEXT,
        `email` TEXT,
        `barangay` TEXT,
        `municipality` TEXT,
        `province` TEXT,
        `zipcode` TEXT,
        `device_id` TEXT,
        `device_name` TEXT,
        `status` TEXT NOT NULL DEFAULT 'graduated',
        `created_at` TIMESTAMP,
        `updated_at` TIMESTAMP,
        `graduated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    return $db;
}

function promo_parse_year($v) {
    if (preg_match('/(\d+)/', (string)$v, $m)) return (int)$m[1];
    return null;
}

function promo_ordinal($n) {
    if ($n == 1) return '1st';
    if ($n == 2) return '2nd';
    if ($n == 3) return '3rd';
    return $n . 'th';
}

function promo_format_year($v, $n) {
    $t = trim((string)$v);
    if (preg_match('/\d+(st|nd|rd|th)\s*year/i', $t)) return promo_ordinal($n) . ' Year';
    if (preg_match('/\d+(st|nd|rd|th)/i', $t)) return promo_ordinal($n);
    return (string)$n;
}

function promo_next_section($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^(\d+)(.*)$/', $s, $m)) return ((int)$m[1] + 1) . $m[2];
    if (preg_match('/^[A-Za-z]$/', $s)) {
        $c = strtoupper($s);
        return $c === 'Z' ? 'Z' : chr(ord($c) + 1);
    }
    return null;
}

function promo_graduate_year($v) {
    $n = (int)$v;
    return in_array($n, PROMO_GRADUATE_YEARS, true) ? $n : PROMO_DEFAULT_GRADUATE_YEAR;
}

function promo_get_settings($db) {
    $row = $db->querySingle("SELECT from_date, to_date, last_applied, last_promoted_ids, pending_graduate_ids, graduate_year FROM promotion_settings WHERE id = 1", true);
    return [
        'from_date' => $row['from_date'] ?? null,
        'to_date' => $row['to_date'] ?? null,
        'last_applied' => $row['last_applied'] ?? null,
        'last_promoted_ids' => promo_json_ids($row['last_promoted_ids'] ?? '[]'),
        'pending_graduate_ids' => promo_json_ids($row['pending_graduate_ids'] ?? '[]'),
        'graduate_year' => promo_graduate_year($row['graduate_year'] ?? PROMO_DEFAULT_GRADUATE_YEAR),
    ];
}

function promo_is_due($settings, $today) {
    if (empty($settings['to_date'])) return false;
    if ($today < $settings['to_date']) return false;
    if (empty($settings['last_applied'])) return true;
    return $settings['last_applied'] < $settings['to_date'];
}

// Builds the full promotion plan without writing anything.
// $excludeIds: ids promoted into year 4 by the last apply run — they stay
// 4th-years and must not reappear as graduates when the plan is rebuilt.
// $gradYear: year level at which students graduate (5, 6 or 7).
function promo_build_plan($db, $excludeIds = [], $gradYear = PROMO_DEFAULT_GRADUATE_YEAR) {
    $gradYear = promo_graduate_year($gradYear);
    $excluded = array_flip(array_map('intval', (array)$excludeIds));
    $plan = ['promote' => [], 'graduates' => [], 'skipped' => [], 'graduate_year' => $gradYear];
    $q = $db->query("SELECT id, student_number, name, course, year, section, status FROM landing_registrations ORDER BY name ASC");
    if (!$q) return $plan;
    while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
        $y = promo_parse_year($r['year'] ?? '');
        if ($y === null) {
            $plan['skipped'][] = $r + ['reason' => 'Year is not a number'];
            continue;
        }
        if ($y >= $gradYear) {
            $plan['graduates'][] = $r + ['new_year' => promo_format_year($r['year'], $y)];
            continue;
        }
        $newYear = $y + 1;
        if ($newYear >= $gradYear) {
            if (!isset($excluded[(int)$r['id']])) {
                $plan['graduates'][] = $r + ['new_year' => promo_format_year($r['year'], $newYear)];
            }
            continue;
        }
        $newSection = promo_next_section($r['section'] ?? '');
        $plan['promote'][] = $r + [
            'new_year' => promo_format_year($r['year'], $newYear),
            'new_section' => $newSection !== null ? $newSection : ($r['section'] ?? ''),
            'section_changed' => $newSection !== null,
        ];
    }
    return $plan;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input)) { $input = $_POST; }
    $action = trim($input['action'] ?? ($_GET['action'] ?? ''));
    $today = date('Y-m-d');

    if ($action === 'get') {
        $db = promo_db();
        $settings = promo_get_settings($db);
        $plan = promo_build_plan($db, $settings['last_promoted_ids'], $settings['graduate_year']);
        $db->close();
        echo json_encode([
            'status' => 'success',
            'settings' => $settings,
            'today' => $today,
            'due' => promo_is_due($settings, $today),
            'counts' => [
                'promote' => count($plan['promote']),
                'graduates' => count($plan['graduates']),
                'skipped' => count($plan['skipped']),
            ],
        ]);
        exit;
    }

    if ($action === 'save_settings') {
        $from = trim($input['from_date'] ?? '');
        $to = trim($input['to_date'] ?? '');
        if ($from === '' || $to === '') {
            echo json_encode(['status' => 'error', 'message' => 'From and To dates are required']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            echo json_encode(['status' => 'error', 'message' => 'Dates must be YYYY-MM-DD']);
            exit;
        }
        if ($from > $to) {
            echo json_encode(['status' => 'error', 'message' => 'From date cannot be after To date']);
            exit;
        }
        $db = promo_db();
        $stmt = $db->prepare("UPDATE promotion_settings SET from_date = :f, to_date = :t, graduate_year = :g, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
        $stmt->bindValue(':f', $from, SQLITE3_TEXT);
        $stmt->bindValue(':t', $to, SQLITE3_TEXT);
        $stmt->bindValue(':g', promo_graduate_year($input['graduate_year'] ?? PROMO_DEFAULT_GRADUATE_YEAR), SQLITE3_INTEGER);
        $stmt->execute();
        $settings = promo_get_settings($db);
        $db->close();
        echo json_encode(['status' => 'success', 'message' => "Promotion period saved: {$from} to {$to} (graduates at " . promo_ordinal($settings['graduate_year']) . " year).", 'due' => promo_is_due($settings, $today), 'graduate_year' => $settings['graduate_year']]);
        exit;
    }

    if ($action === 'preview') {
        $db = promo_db();
        $settings = promo_get_settings($db);
        $plan = promo_build_plan($db, $settings['last_promoted_ids'], $settings['graduate_year']);
        $db->close();
        echo json_encode([
            'status' => 'success',
            'settings' => $settings,
            'today' => $today,
            'due' => promo_is_due($settings, $today),
            'promote' => array_slice($plan['promote'], 0, 200),
            'promote_total' => count($plan['promote']),
            'graduates' => array_slice($plan['graduates'], 0, 200),
            'graduates_total' => count($plan['graduates']),
            'skipped' => array_slice($plan['skipped'], 0, 200),
            'skipped_total' => count($plan['skipped']),
        ]);
        exit;
    }

    if ($action === 'apply') {
        $db = promo_db();
        $settings = promo_get_settings($db);
        $plan = promo_build_plan($db, $settings['last_promoted_ids'], $settings['graduate_year']);
        $db->exec('BEGIN TRANSACTION');
        $promoted = 0;
        $promotedIds = $settings['last_promoted_ids'];
        try {
            foreach ($plan['promote'] as $p) {
                $stmt = $db->prepare("UPDATE landing_registrations SET year = :y, section = :s, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->bindValue(':y', $p['new_year'], SQLITE3_TEXT);
                $stmt->bindValue(':s', $p['new_section'], SQLITE3_TEXT);
                $stmt->bindValue(':id', (int)$p['id'], SQLITE3_INTEGER);
                $stmt->execute();
                $promoted++;
                $promotedIds[] = (int)$p['id'];
            }
            $promotedIds = array_values(array_unique(array_map('intval', $promotedIds)));
            $pendingIds = array_values(array_unique(array_map(fn($g) => (int)$g['id'], $plan['graduates'])));
            $stmt = $db->prepare("UPDATE promotion_settings SET last_applied = :t, last_promoted_ids = :p, pending_graduate_ids = :g, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $stmt->bindValue(':t', $today, SQLITE3_TEXT);
            $stmt->bindValue(':p', json_encode($promotedIds), SQLITE3_TEXT);
            $stmt->bindValue(':g', json_encode($pendingIds), SQLITE3_TEXT);
            $stmt->execute();
            $db->exec('COMMIT');
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        $db->close();
        $msg = "{$promoted} student(s) promoted (year + 1, section + 1).";
        if (!empty($plan['graduates'])) $msg .= " " . count($plan['graduates']) . " reached " . promo_ordinal($settings['graduate_year']) . " year and need graduation confirmation.";
        if (!empty($plan['skipped'])) $msg .= " " . count($plan['skipped']) . " skipped (non-numeric year).";
        echo json_encode(['status' => 'success', 'message' => $msg, 'promoted' => $promoted,
            'graduates_pending' => count($plan['graduates']), 'skipped' => count($plan['skipped'])]);
        exit;
    }

    if ($action === 'graduate_apply') {
        $ids = $input['ids'] ?? 'all';
        $db = promo_db();
        $settings = promo_get_settings($db);
        $gradYear = $settings['graduate_year'];
        $plan = promo_build_plan($db, $settings['last_promoted_ids'], $gradYear);
        $targets = [];
        if ($ids !== 'all') {
            $want = array_flip(array_map('intval', (array)$ids));
            foreach ($plan['graduates'] as $g) {
                if (isset($want[(int)$g['id']])) $targets[] = $g;
            }
            // Safety net: explicitly picked rows sitting above the max year always qualify.
            $q = $db->query("SELECT id, student_number, name, course, year, section, status FROM landing_registrations");
            if ($q) while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
                if (!isset($want[(int)$r['id']])) continue;
                $y = promo_parse_year($r['year'] ?? '');
                if ($y !== null && $y >= $gradYear) {
                    $dup = false;
                    foreach ($targets as $t) { if ((int)$t['id'] === (int)$r['id']) { $dup = true; break; } }
                    if (!$dup) $targets[] = $r + ['new_year' => promo_format_year($r['year'], $y)];
                }
            }
        } else {
            // Migrate the confirmed pending list (intersected with rows still present)
            // plus any rows genuinely above the max year.
            $pending = array_flip($settings['pending_graduate_ids']);
            $present = [];
            $q = $db->query("SELECT id, student_number, name, course, year, section, status FROM landing_registrations");
            if ($q) while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
                $present[(int)$r['id']] = $r;
            }
            foreach ($plan['graduates'] as $g) {
                if (isset($pending[(int)$g['id']]) && isset($present[(int)$g['id']])) $targets[] = $g;
            }
            foreach ($present as $id => $r) {
                $y = promo_parse_year($r['year'] ?? '');
                if ($y !== null && $y >= $gradYear) {
                    $dup = false;
                    foreach ($targets as $t) { if ((int)$t['id'] === $id) { $dup = true; break; } }
                    if (!$dup) $targets[] = $r + ['new_year' => promo_format_year($r['year'], $y)];
                }
            }
        }
        if (empty($targets)) {
            $db->close();
            echo json_encode(['status' => 'error', 'message' => 'No 5th-year students to graduate']);
            exit;
        }
        $gdb = promo_graduate_db();
        $db->exec('BEGIN TRANSACTION');
        $gdb->exec('BEGIN TRANSACTION');
        $migrated = 0;
        try {
            foreach ($targets as $g) {
                $full = $db->querySingle("SELECT * FROM landing_registrations WHERE id = '" . (int)$g['id'] . "'", true);
                if (!$full) continue;
                $stmt = $gdb->prepare("INSERT OR REPLACE INTO graduates
                    (student_number, name, middlename, firstname, lastname, name_extension, course, year, section,
                     birthdate, age, gender, contact_number, email, barangay, municipality, province, zipcode,
                     device_id, device_name, status, created_at, updated_at)
                    VALUES (:sn, :nm, :mn, :fn, :ln, :ex, :co, :yr, :se, :bd, :ag, :ge, :cp, :em, :ba, :mu, :pr, :zi, :di, :dn, 'graduated', :ca, :ua)");
                $stmt->bindValue(':sn', $full['student_number'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':nm', $full['name'] ?? '', SQLITE3_TEXT);
                foreach ([[':mn','middlename'],[':fn','firstname'],[':ln','lastname'],[':ex','name_extension'],[':co','course'],[':yr','year'],[':se','section'],[':bd','birthdate'],[':ge','gender'],[':cp','contact_number'],[':em','email'],[':ba','barangay'],[':mu','municipality'],[':pr','province'],[':zi','zipcode'],[':di','device_id'],[':dn','device_name'],[':ca','created_at'],[':ua','updated_at']] as $pair) {
                    $stmt->bindValue($pair[0], $full[$pair[1]] ?? null, SQLITE3_TEXT);
                }
                $stmt->bindValue(':ag', $full['age'] ?? null, SQLITE3_INTEGER);
                $stmt->execute();
                $db->exec("DELETE FROM landing_registrations WHERE id = '" . (int)$g['id'] . "'");
                $migrated++;
            }
            $gdb->exec('COMMIT');
            $db->exec('COMMIT');
        } catch (Exception $e) {
            $gdb->exec('ROLLBACK');
            $db->exec('ROLLBACK');
            throw $e;
        }
        // Prune migrated ids from the pending list so they are never offered again.
        $remaining = array_values(array_diff($settings['pending_graduate_ids'], array_map(fn($t) => (int)$t['id'], $targets)));
        $stmt = $db->prepare("UPDATE promotion_settings SET pending_graduate_ids = :g, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
        $stmt->bindValue(':g', json_encode($remaining), SQLITE3_TEXT);
        $stmt->execute();
        $gdb->close();
        $db->close();
        echo json_encode(['status' => 'success', 'message' => "{$migrated} student(s) marked graduated and migrated to db/graduate_db.db.", 'migrated' => $migrated]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
