<?php
// get_all_academic_terms.php

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON content type
header('Content-Type: application/json');

// Include database connection
require_once 'DBConnection.php';

// Start session to get user role
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get user role from session
$userRole = $_SESSION['role'] ?? 'admin';

// Determine database paths based on role
if ($userRole === 'admin') {
    $backupDir = __DIR__ . '/db/backups/Administrator/';
    $defaultDbPath = __DIR__ . '/db/grading_db.db';
} elseif ($userRole === 'faculty') {
    // For faculty, find their personal database
    $facultyUsername = $_SESSION['username'] ?? '';
    if ($facultyUsername) {
        // Create faculty directory path
        $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($facultyUsername));
        $facultyDir = __DIR__ . '/db/' . $safeUsername;
        
        if (is_dir($facultyDir)) {
            // Look for any .db file in the faculty directory
            $facultyDbFiles = glob($facultyDir . '/*.db');
            if (!empty($facultyDbFiles)) {
                // Use the first (or most recent) database file
                $defaultDbPath = $facultyDbFiles[0];
            } else {
                // Fallback to a default path if no database found
                $defaultDbPath = __DIR__ . '/db/grading_db.db';
            }
        } else {
            // Fallback if faculty directory doesn't exist
            $defaultDbPath = __DIR__ . '/db/grading_db.db';
        }
    } else {
        // Fallback if no username in session
        $defaultDbPath = __DIR__ . '/db/grading_db.db';
    }
    $backupDir = __DIR__ . '/db/faculty_backups/';
} else {
    // Default to admin if role is unknown
    $backupDir = __DIR__ . '/db/backups/Administrator/';
    $defaultDbPath = __DIR__ . '/db/grading_db.db';
}

// Gather every .db file.  Live DB is included so the *current* year is
// always represented, but a historical year that also lives in a backup
// must resolve to that backup — the live DB only keeps a stale stub for
// old years (e.g. 1 class vs 8 in the archive).  The dedup below picks
// the best source per (year, semester) instead of first-seen wins.
$databaseFiles = glob($backupDir . '*.db') ?: [];
if (!in_array($defaultDbPath, $databaseFiles)) $databaseFiles[] = $defaultDbPath;
$allTerms = [];

// Debug logging
error_log("Get Academic Terms Debug - User role: " . $userRole);
error_log("Get Academic Terms Debug - Faculty username: " . ($facultyUsername ?? 'none'));
error_log("Get Academic Terms Debug - Backup directory: " . $backupDir);
error_log("Get Academic Terms Debug - Default DB path: " . $defaultDbPath);
error_log("Get Academic Terms Debug - Initial database files from backup: " . print_r($databaseFiles, true));

// (Live database already first in the list above — no append needed.)

// For faculty users, also explicitly add their personal database as a selectable option
if ($userRole === 'faculty' && $facultyUsername) {
    $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($facultyUsername));
    $facultyDir = __DIR__ . '/db/' . $safeUsername;
    
    error_log("Get Academic Terms Debug - Faculty directory: " . $facultyDir);
    
    if (is_dir($facultyDir)) {
        $facultyDbFiles = glob($facultyDir . '/*.db');
        error_log("Get Academic Terms Debug - Faculty DB files found: " . print_r($facultyDbFiles, true));
        
        if (!empty($facultyDbFiles)) {
            // Add faculty personal database if not already in the list
            foreach ($facultyDbFiles as $facultyDb) {
                if (!in_array($facultyDb, $databaseFiles)) {
                    $databaseFiles[] = $facultyDb;
                    error_log("Get Academic Terms Debug - Added faculty DB: " . $facultyDb);
                }
            }
        }
    } else {
        error_log("Get Academic Terms Debug - Faculty directory does not exist");
    }
}

error_log("Get Academic Terms Debug - Final database files list: " . print_r($databaseFiles, true));

foreach ($databaseFiles as $dbFile) {
    try {
        // Skip if file doesn't exist
        if (!file_exists($dbFile)) {
            continue;
        }

        // Connect to the database
        $db = new SQLite3($dbFile);
        
        // Check if class_list table exists
        $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='class_list'");
        if (!$tableCheck->fetchArray()) {
            $db->close();
            continue;
        }

        // Query to get unique year and semester combinations
        $query = "SELECT DISTINCT year, semester 
                 FROM class_list 
                 WHERE year IS NOT NULL AND year != '' 
                 AND semester IS NOT NULL AND semester != ''";
        
        $result = $db->query($query);
        
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $key = $row['year'] . '|' . $row['semester'] . '|' . $dbFile;
                $allTerms[$key] = $row + ['source_db' => $dbFile];
            }
        }
        
        $db->close();
        
    } catch (Exception $e) {
        // Log error but continue with next database
        error_log("Error processing database $dbFile: " . $e->getMessage());
        continue;
    }
}

// Sort the terms by year (descending) and semester
usort($allTerms, function($a, $b) {
    if ($a['year'] == $b['year']) {
        $order = ['1st Semester' => 1, '2nd Semester' => 2, 'Summer' => 3];
        return ($order[$a['semester']] ?? 4) <=> ($order[$b['semester']] ?? 4);
    }
    return $b['year'] <=> $a['year'];
});

// Determine the live DB's current academic year (max year it holds).  When
// a historical year exists in both live and a backup, the backup is the
// authoritative archive; only the current year should resolve to live.
$liveMaxYear = '';
try {
    if (file_exists($defaultDbPath)) {
        $liveDb = new SQLite3($defaultDbPath, SQLITE3_OPEN_READONLY);
        $yr = $liveDb->querySingle("SELECT year FROM class_list WHERE year IS NOT NULL AND TRIM(year) != '' ORDER BY year DESC LIMIT 1");
        if (is_string($yr) && $yr !== '') $liveMaxYear = $yr;
        $liveDb->close();
    }
} catch (Exception $e) {}

// Group by year — for duplicate (year, semester) pick the best source:
//  * current year (== liveMaxYear)  → live DB wins if it has it
//  * historical year                 → backup wins (newest file among backups)
//  This replaces the old first-seen-wins dedup.
$byKey = [];
foreach ($allTerms as $t) {
    $k = $t['year'] . '|' . $t['semester'];
    $byKey[$k][] = $t;
}
$groupedTerms = [];
foreach ($byKey as $k => $candidates) {
    // Choose best candidate for this (year, semester)
    $best = $candidates[0];
    if (count($candidates) > 1) {
        $liveCandidates = array_filter($candidates, function($c) use ($defaultDbPath) { return $c['source_db'] === $defaultDbPath; });
        $backupCandidates = array_filter($candidates, function($c) use ($defaultDbPath) { return $c['source_db'] !== $defaultDbPath; });
        list($bestYear) = explode('|', $k);
        if ($bestYear === $liveMaxYear && !empty($liveCandidates)) {
            $best = array_values($liveCandidates)[0];
        } elseif (!empty($backupCandidates)) {
            // Newest backup file wins for historical terms
            usort($backupCandidates, function($a, $b) { return filemtime($b['source_db']) <=> filemtime($a['source_db']); });
            $best = array_values($backupCandidates)[0];
        } elseif (!empty($liveCandidates)) {
            $best = array_values($liveCandidates)[0];
        }
    }
    $year = $best['year'];
    if (!isset($groupedTerms[$year])) $groupedTerms[$year] = [];
    // Should be unique per semester after the dedup above
    $semesterExists = false;
    foreach ($groupedTerms[$year] as $existing) {
        if ($existing['semester'] === $best['semester']) { $semesterExists = true; break; }
    }
    if (!$semesterExists) {
        $term = $best;
        $label = $term['semester'] . ' SY ' . $term['year'];
        
        // Add special label for faculty personal database
        if ($userRole === 'faculty' && isset($term['source_db'])) {
            $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($facultyUsername));
            $facultyDir = __DIR__ . '/db/' . $safeUsername . '/';
            
            // Check if this is the faculty's personal database
            if (strpos($term['source_db'], $facultyDir) === 0) {
                $label = $term['semester'] . ' SY ' . $term['year'] . ' (Current)';
            }
        }
        
        $groupedTerms[$year][] = [
            'year' => $term['year'],
            'semester' => $term['semester'],
            'label' => $label,
            'source_db' => $term['source_db'] ?? ''
        ];
    }
}

// Output the results
echo json_encode([
    'success' => true,
    'terms' => $groupedTerms,
    'debug' => [
        'databases_checked' => $databaseFiles,
        'default_db_path' => $defaultDbPath,
        'user_role' => $userRole,
        'faculty_username' => $_SESSION['username'] ?? 'none'
    ]
], JSON_PRETTY_PRINT);
