<?php
// Inventory every available database copy for student device tables, so we know
// what is recoverable after the move from fingerprint binding to hardware keys.
// Read-only: opens every copy with SQLITE3_OPEN_READONLY.
// Columns: logs = audit rows, keys = ACTIVE hardware keys (the new 1:1 binding),
// pins = student-chosen login PINs, retired = old fingerprint-binding table.
$paths = [];
$base = __DIR__ . '/db';
$paths['LIVE (current)'] = $base . '/grading_db.db';
foreach (glob($base . '/backups/*.db') as $f) { $paths['backup: ' . basename($f)] = $f; }
foreach (glob($base . '/backups/Administrator/*.db') as $f) { $paths['backup: ' . basename($f)] = $f; }
$fac = glob($base . '/*/*_2026-2027.db');
sort($fac);
foreach (array_slice($fac, 0, 6) as $f) { $paths['faculty: ' . basename(dirname($f))] = $f; }

// Reads a count only when the table exists, so retired tables don't break the run.
function hgs_qa_count(SQLite3 $d, $table) {
    $has = (int)$d->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'");
    return $has ? (int)$d->querySingle("SELECT COUNT(*) FROM $table") : -1;
}

printf("%-46s %6s %6s %6s %8s %19s\n", 'DATABASE', 'logs', 'keys', 'pins', 'retired', 'newest log');
foreach ($paths as $label => $p) {
    if (!file_exists($p)) { printf("%-46s %6s\n", $label, 'MISSING'); continue; }
    try {
        $d = new SQLite3($p, SQLITE3_OPEN_READONLY);
        if (hgs_qa_count($d, 'student_device_logs') === -1) {
            printf("%-46s %6s\n", $label, 'no table'); $d->close(); continue;
        }
        $logs  = hgs_qa_count($d, 'student_device_logs');
        $keys  = hgs_qa_count($d, 'student_device_keys');
        $pins  = hgs_qa_count($d, 'student_bind_pins');
        $old   = hgs_qa_count($d, 'student_device_bindings');
        $new   = (string)$d->querySingle("SELECT MAX(created_at) FROM student_device_logs");
        printf("%-46s %6d %6d %6d %8s %19s\n", $label, $logs, $keys, $pins,
            $old >= 0 ? (string)$old : '-', $new !== '' ? $new : '-');
        $d->close();
    } catch (Throwable $e) {
        printf("%-46s %6s\n", $label, 'ERR');
    }
}