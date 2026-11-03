<?php
/**
 * Landing enrollment lookup.
 *
 * Finds every class (across the main database and all faculty databases)
 * that a landing-registered student_number is enrolled in, along with
 * the owning faculty. Used by the admin Landing Registrations page to
 * auto-reconcile statuses and to display a student's enrollments.
 */

if (!function_exists('landing_user_databases')) {
    // List every user database: main DB first, then each active faculty DB, then admin backups (so view modal finds all subjects even in admin DB)
    function landing_user_databases() {
        $dbs = [];
        $main_path = __DIR__ . '/db/grading_db.db';
        $adminFullnameForMain = null;
        try {
            $tmpMain = new SQLite3($main_path);
            $tmpMain->enableExceptions(true);
            $ar = $tmpMain->querySingle("SELECT fullname FROM admin_list WHERE role='admin' ORDER BY admin_id ASC", true);
            if ($ar && !empty($ar['fullname'])) $adminFullnameForMain = $ar['fullname'];
            $tmpMain->close();
        } catch (Exception $e) {}
        if ($adminFullnameForMain === null) $adminFullnameForMain = 'Admin';
        if (file_exists($main_path)) {
            $dbs[] = ['label' => 'Main Database', 'faculty' => $adminFullnameForMain, 'path' => $main_path];
        }
        try {
            $main = new SQLite3($main_path);
            $main->enableExceptions(true);
            $main->exec("PRAGMA busy_timeout = 5000");
            $res = $main->query("SELECT admin_id, fullname, role, db_path FROM admin_list WHERE role = 'faculty' AND status = 1 ORDER BY fullname ASC");
            if ($res) {
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    $db_path = $row['db_path'] ?? '';
                    $resolved = null;
                    if (!empty($db_path) && file_exists($db_path)) {
                        $resolved = $db_path;
                    } elseif (!empty($db_path) && !preg_match('/\.db$/', $db_path)) {
                        $dir = __DIR__ . '/db/' . basename($db_path);
                        if (is_dir($dir)) {
                            $files = glob($dir . '/*.db');
                            if (!empty($files)) {
                                $files = array_filter($files, fn($f)=>stripos(basename($f),'backup')===false && stripos(basename($f),'_old')===false);
                                if (!empty($files)) { usort($files, fn($a,$b)=>filemtime($b) <=> filemtime($a)); $resolved = $files[0]; }
                            }
                        }
                    } elseif (!empty($db_path)) {
                        $candidate = __DIR__ . '/db/' . basename($db_path);
                        if (file_exists($candidate)) {
                            $resolved = $candidate;
                        }
                    }
                    if ($resolved && $resolved !== $main_path) {
                        $dbs[] = ['label' => $row['fullname'], 'faculty' => $row['fullname'], 'path' => $resolved];
                    }
                }
            }
            $main->close();
        } catch (Exception $e) {
            error_log('landing_user_databases: ' . $e->getMessage());
        }
        // Include admin backup DBs and previous databases so students existing in admin_2026-03-08_05-24-09.db (1st Semester) are found
        $extraPatterns = [
            __DIR__ . '/db/backups/Administrator/*.db',
            __DIR__ . '/db/backups/faculty_backups/*.db',
            __DIR__ . '/db/previous_database/*.db',
            __DIR__ . '/db/grading_dbold.db',
        ];
        $seenPaths = array_flip(array_column($dbs, 'path'));
        foreach ($extraPatterns as $pat) {
            foreach (glob($pat) as $bf) {
                if (isset($seenPaths[$bf]) || !file_exists($bf)) continue;
                $seenPaths[$bf]=true;
                $label = basename($bf);
                // Resolve fullname for this backup (not database filename)
                $facultyLabel = null;
                try {
                    $tmp2 = new SQLite3($main_path);
                    $tmp2->enableExceptions(true);
                    $base = basename($bf);
                    $dir = basename(dirname($bf));
                    // Try by db_path or username
                    $escBase = $tmp2->escapeString($base);
                    $escDir = $tmp2->escapeString($dir);
                    $q = $tmp2->query("SELECT fullname FROM admin_list WHERE db_path LIKE '%{$escBase}%' OR db_path LIKE '%{$escDir}%' OR username COLLATE NOCASE='{$escDir}' LIMIT 1");
                    if ($q && ($rr=$q->fetchArray(SQLITE3_ASSOC)) && !empty($rr['fullname'])) $facultyLabel = $rr['fullname'];
                    $tmp2->close();
                } catch (Exception $e) {}
                if ($facultyLabel === null) {
                    if (strpos($bf, 'Administrator')!==false) $facultyLabel = $adminFullnameForMain;
                    elseif (strpos($bf, 'faculty_backups')!==false) $facultyLabel = 'Faculty Backup';
                    elseif (strpos($bf, 'previous_database')!==false) $facultyLabel = 'Previous Database';
                    elseif (basename($bf)==='grading_dbold.db') $facultyLabel = 'Old Grading DB';
                    else $facultyLabel = pathinfo($bf, PATHINFO_FILENAME);
                }
                $dbs[] = ['label' => $label, 'faculty' => $facultyLabel, 'path' => $bf];
            }
        }
        return $dbs;
    }
}

if (!function_exists('find_student_enrollments')) {
    // Returns every enrollment of a student_number:
    // [['faculty','subject','course','section','semester','year','class_id','db_label'], ...]
    function find_student_enrollments($student_number) {
        $enrollments = [];
        $student_number = trim((string)$student_number);
        if ($student_number === '') {
            return $enrollments;
        }
        foreach (landing_user_databases() as $db_info) {
            try {
                $db = new SQLite3($db_info['path']);
                $db->enableExceptions(true);
                $db->exec("PRAGMA busy_timeout = 5000");
                $esc = $db->escapeString($student_number);
                // Include remarks / grades from published_grades (per subject, custom quarter handled via remarks)
                $qry = $db->query("SELECT DISTINCT s.class_id, s.student_id, c.grade, c.section, c.semester, c.year, c.course_no, sub.name AS subject_name,
                                   pg.remarks, pg.initial_grade, pg.average_grade
                                   FROM `student_list` s
                                   LEFT JOIN `class_list` c ON s.class_id = c.class_id
                                   LEFT JOIN `subjects` sub ON c.subject_id = sub.subject_id
                                   LEFT JOIN `published_grades` pg ON pg.student_id = s.student_id AND pg.class_id = s.class_id
                                   WHERE s.student_number = '{$esc}' GROUP BY s.class_id");
                if ($qry) {
                    while ($row = $qry->fetchArray(SQLITE3_ASSOC)) {
                        $faculty = $db_info['faculty'];
                        if ($faculty === null) {
                            $faculty = 'Admin (Main Database)';
                        }
                        // Remarks: prefer published_grades remarks, fallback to computed via average
                        $remarks = trim($row['remarks'] ?? '');
                        if ($remarks === '' && isset($row['average_grade']) && is_numeric($row['average_grade'])) {
                            // Stored averages may be CHED (1.0-5.0) or DepEd (60-100) scale.
                            $remarks = remarks_for_grade($row['average_grade'], 'AUTO');
                        }
                        if ($remarks === '') $remarks = '—';
                        $enrollments[] = [
                            'faculty' => $faculty,
                            'subject' => $row['subject_name'] ?? 'N/A',
                            'course' => $row['grade'] ?? 'N/A',
                            'section' => $row['section'] ?? 'N/A',
                            'semester' => $row['semester'] ?? 'N/A',
                            'year' => $row['year'] ?? 'N/A',
                            'course_no' => $row['course_no'] ?? '',
                            'remarks' => $remarks,
                            'equivalent_grade' => $row['equivalent_grade'] ?? $row['average_equivalent'] ?? '',
                            'class_id' => $row['class_id'],
                            'db_label' => $db_info['label']
                        ];
                    }
                }
                // Also include published_grades where student_list row was deleted but grade still exists — deduplicate by class_id (avoid duplicate when first query already found same class)
                $seenCids = []; foreach ($enrollments as $e) { $k=(string)($e['class_id'] ?? ''); if($k!=='') $seenCids[$k]=true; }
                // Only run second query if first found nothing for this DB (to avoid duplicate when class still exists)
                if (!empty($seenCids)) { /* first query already found enrollments for this DB, skip archived second query to avoid duplicate */ }
                else {
                    $q2 = $db->query("SELECT pg.class_id, pg.remarks, pg.initial_grade, pg.average_grade, c.grade, c.section, c.semester, c.year, c.course_no, sub.name AS subject_name FROM `published_grades` pg LEFT JOIN `class_list` c ON pg.class_id=c.class_id LEFT JOIN `subjects` sub ON c.subject_id=sub.subject_id WHERE pg.student_id IN (SELECT student_id FROM student_list WHERE student_number='{$esc}')");
                    if ($q2) {
                        while ($r2 = $q2->fetchArray(SQLITE3_ASSOC)) {
                            $cid2 = (string)($r2['class_id'] ?? '');
                            if ($cid2==='' || isset($seenCids[$cid2])) continue;
                            $seenCids[$cid2]=true;
                        $faculty2 = $db_info['faculty'] ?? 'Admin (Main Database)'; if ($faculty2===null) $faculty2='Admin (Main Database)';
                        $rem2 = trim($r2['remarks'] ?? ''); if($rem2==='') $rem2='—';
                        $enrollments[] = [
                            'faculty' => $faculty2,
                            'subject' => $r2['subject_name'] ?? 'N/A',
                            'course' => $r2['grade'] ?? 'N/A',
                            'section' => $r2['section'] ?? 'N/A',
                            'semester' => $r2['semester'] ?? 'N/A',
                            'year' => $r2['year'] ?? 'N/A',
                            'course_no' => $r2['course_no'] ?? '',
                            'remarks' => $rem2,
                            'equivalent_grade' => $r2['equivalent_grade'] ?? '',
                            'class_id' => $r2['class_id'],
                            'db_label' => $db_info['label']
                        ];
                    }
                }
                } // end else (only run archived query when first found nothing)
                $db->close();
            } catch (Exception $e) {
                error_log('find_student_enrollments: ' . $e->getMessage());
            }
        }
        return $enrollments;
    }
}

if (!function_exists('landing_mark_fully_placed_enrolled')) {
    // Reverse reconcile: landing rows stuck at 'pending' whose student is already
    // placed in EVERY live class of their course+section (e.g. added manually via
    // the Students page, which never flips landing status) are marked 'enrolled'.
    // Regular rows only — irregulars keep their own fully-handled resolution.
    // Live DBs only (main + faculty); backups/previous DBs don't count as placed.
    // Returns number of rows flipped. Best-effort: never throws.
    function landing_mark_fully_placed_enrolled($landing_conn) {
        try {
            $rows = [];
            try {
                $q = $landing_conn->query("SELECT id, student_number, course, section FROM landing_registrations WHERE (status IS NULL OR status != 'enrolled') AND COALESCE(is_irregular, 0) = 0");
                if (!$q) throw new Exception('no irregular col');
            } catch (Throwable $e) {
                $q = $landing_conn->query("SELECT id, student_number, course, section FROM landing_registrations WHERE (status IS NULL OR status != 'enrolled')");
            }
            if ($q) {
                while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
                    $sn = trim((string)($r['student_number'] ?? ''));
                    if ($sn === '') continue;
                    $rows[] = ['id' => (int)($r['id'] ?? 0), 'sn' => $sn,
                               'course' => strtolower(trim((string)($r['course'] ?? ''))),
                               'section' => strtolower(trim((string)($r['section'] ?? '')))];
                }
            }
            if (empty($rows)) return 0;
            // NOTE: class_ids collide across DBs (both start at 1), so placement
            // is re-verified per-DB below instead of merging ids across DBs.
            $flipped = 0;
            foreach (landing_user_databases() as $db_info) {
                $p = $db_info['path'] ?? '';
                if ($p === '' || stripos($p, 'backups') !== false || stripos($p, 'previous_database') !== false
                    || basename($p) === 'grading_dbold.db' || basename($p) === 'landing_db.db') continue;
                try {
                    $db = new SQLite3($p, SQLITE3_OPEN_READONLY);
                    $db->enableExceptions(true);
                    $db->exec("PRAGMA busy_timeout = 5000");
                    $cm = [];
                    $cq = $db->query("SELECT class_id, grade, section FROM class_list WHERE section IS NOT NULL AND TRIM(section) != ''");
                    if ($cq) {
                        while ($cr = $cq->fetchArray(SQLITE3_ASSOC)) {
                            $k = strtolower(trim((string)($cr['grade'] ?? ''))) . '|' . strtolower(trim((string)$cr['section']));
                            $cm[$k][] = (int)$cr['class_id'];
                        }
                    }
                    if (empty($cm)) { $db->close(); continue; }
                    $eq = $db->query("SELECT student_number, class_id FROM student_list WHERE student_number IS NOT NULL AND TRIM(student_number) != ''");
                    $have = [];
                    if ($eq) {
                        while ($er = $eq->fetchArray(SQLITE3_ASSOC)) {
                            $k = strtolower(trim((string)($er['student_number'] ?? '')));
                            if ($k === '') continue;
                            if (!isset($have[$k])) $have[$k] = [];
                            $have[$k][(int)$er['class_id']] = true;
                        }
                    }
                    $db->close();
                    foreach ($rows as $lr) {
                        $k = $lr['course'] !== '' ? ($lr['course'] . '|' . $lr['section']) : ('|' . $lr['section']);
                        $ids = $cm[$k] ?? $cm['|' . $lr['section']] ?? [];
                        if (empty($ids)) continue;
                        $ok = true;
                        foreach ($ids as $cid) {
                            if (empty($have[$lr['sn']][$cid])) { $ok = false; break; }
                        }
                        if ($ok && $lr['id'] > 0) {
                            try { @$landing_conn->exec("UPDATE landing_registrations SET status = 'enrolled', updated_at = CURRENT_TIMESTAMP WHERE id = '{$lr['id']}' AND (status IS NULL OR status != 'enrolled')"); if ($landing_conn->changes() > 0) $flipped++; } catch (Throwable $e) {}
                        }
                    }
                } catch (Throwable $e) {}
            }
            return $flipped;
        } catch (Throwable $e) { return 0; }
    }
}
if (!function_exists('landing_numbers_enrolled_anywhere')) {
    // Batch version: given student numbers, returns those found in ANY class.
    function landing_numbers_enrolled_anywhere($student_numbers) {
        $wanted = [];
        foreach ((array)$student_numbers as $n) {
            $n = trim((string)$n);
            if ($n !== '') {
                $wanted[$n] = true;
            }
        }
        if (empty($wanted)) {
            return [];
        }
        $found = [];
        foreach (landing_user_databases() as $db_info) {
            if (count($found) >= count($wanted)) {
                break;
            }
            try {
                $db = new SQLite3($db_info['path']);
                $db->enableExceptions(true);
                $db->exec("PRAGMA busy_timeout = 5000");
                $escaped = [];
                foreach (array_keys($wanted) as $n) {
                    if (!isset($found[$n])) {
                        $escaped[] = "'" . $db->escapeString($n) . "'";
                    }
                }
                if (empty($escaped)) {
                    $db->close();
                    continue;
                }
                $qry = $db->query("SELECT DISTINCT student_number FROM `student_list` WHERE student_number IN (" . implode(',', $escaped) . ")");
                if ($qry) {
                    while ($row = $qry->fetchArray(SQLITE3_ASSOC)) {
                        $found[$row['student_number']] = true;
                    }
                }
                $db->close();
            } catch (Exception $e) {
                error_log('landing_numbers_enrolled_anywhere: ' . $e->getMessage());
            }
        }
        return array_keys($found);
    }
}
?>
