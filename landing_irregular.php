<?php
// Shared helper for the pending-student pipeline (class.php,
// get_pending_students.php, assign_bulk_by_section.php, get_all_pending.php).
//
// Regular landing rows match a class by their OWN section (+course).
// Irregular rows (is_irregular = 1) NEVER match by their own section —
// they only match a class when one of their picked irregular_subjects
// entries matches that class's section AND subject (code or name).
// Entry shape: ['year' => '2', 'section' => '2A', 'code' => 'IT 211', 'name' => '...'].

if (!function_exists('irregular_entries_of')) {
    // Decode + validate the irregular_subjects JSON of a landing row.
    // Returns a list of ['year','section','code','name'] (may be empty).
    function irregular_entries_of($landing_row) {
        $out = [];
        if (!is_array($landing_row)) return $out;
        if ((int)($landing_row['is_irregular'] ?? 0) !== 1) return $out;
        $decoded = json_decode($landing_row['irregular_subjects'] ?? '', true);
        if (!is_array($decoded)) return $out;
        foreach ($decoded as $e) {
            if (!is_array($e)) continue;
            $year = trim((string)($e['year'] ?? ''));
            $section = strtoupper(trim((string)($e['section'] ?? '')));
            $code = trim((string)($e['code'] ?? ''));
            $name = trim((string)($e['name'] ?? ''));
            if (!preg_match('/^[1-6]$/', $year)) continue;
            if (!preg_match('/^[1-6][A-Z]+$/', $section) || $section[0] !== $year) continue;
            if ($code === '' || $name === '') continue;
            $out[] = ['year' => $year, 'section' => $section, 'code' => $code, 'name' => $name];
        }
        return $out;
    }
}

if (!function_exists('irregular_match_for_class')) {
    // Does this landing row hold an irregular entry for the given class?
    // $secLower/$codeLower/$nameLower are the class's lowercased section,
    // course_no and subject name. Returns the matched entry or null.
    function irregular_match_for_class($landing_row, $secLower, $codeLower, $nameLower) {
        foreach (irregular_entries_of($landing_row) as $e) {
            if (irregular_entry_matches_class($e, $secLower, $codeLower, $nameLower)) return $e;
        }
        return null;
    }
}

if (!function_exists('irregular_entry_matches_class')) {
    // Does a single validated entry match the given class identifiers?
    function irregular_entry_matches_class($entry, $secLower, $codeLower, $nameLower) {
        if (!is_array($entry)) return false;
        if (strtolower(trim($entry['section'] ?? '')) !== (string)$secLower) return false;
        $ecode = strtolower(trim($entry['code'] ?? ''));
        $ename = strtolower(trim($entry['name'] ?? ''));
        if ($ecode === '' || $ename === '') return false;
        $codeLower = (string)$codeLower;
        $nameLower = (string)$nameLower;
        $codeHit = ($ecode === $codeLower || ($nameLower !== '' && $ecode === $nameLower));
        $nameHit = ($ename === $nameLower || ($codeLower !== '' && $ename === $codeLower));
        return ($codeHit || $nameHit);
    }
}

if (!function_exists('landing_row_is_irregular')) {
    function landing_row_is_irregular($landing_row) {
        return is_array($landing_row) && (int)($landing_row['is_irregular'] ?? 0) === 1;
    }
}

if (!function_exists('landing_has_passed_anywhere')) {
    // Has this student_number passed this subject (code or name) in any
    // school year, looking through backup/previous databases?
    // Live faculty DBs are excluded here (same as the per-class checks) —
    // current enrollments there are covered by find_student_enrollments().
    function landing_has_passed_anywhere($student_number, $subject) {
        static $cache = [];
        $student_number = trim((string)$student_number);
        $subject = trim((string)$subject);
        if ($student_number === '' || $subject === '') return false;
        $key = strtolower($student_number) . '|' . strtolower($subject);
        if (isset($cache[$key])) return $cache[$key];
        $cache[$key] = false;
        $patterns = [__DIR__ . '/db/backups/*/*.db', __DIR__ . '/db/backups/*/*/*.db', __DIR__ . '/db/previous_database/*.db', __DIR__ . '/db/grading_dbold.db', __DIR__ . '/db/*.db'];
        $seen = [];
        foreach ($patterns as $pat) {
            foreach (glob($pat) ?: [] as $bf) {
                if (isset($seen[$bf])) continue;
                $seen[$bf] = true;
                if (basename($bf) === 'landing_db.db') continue;
                if (strpos($bf, DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR) !== false && preg_match('#/db/[^/]+/[^/]+\.db$#', $bf)) continue;
                try {
                    $bdb = new SQLite3($bf);
                    $bdb->enableExceptions(true);
                    $escNum = $bdb->escapeString($student_number);
                    $escSub = $bdb->escapeString($subject);
                    $row = null;
                    $q = $bdb->query("SELECT pg.remarks, pg.average_grade, pg.average_equivalent, pg.equivalent_grade FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(c.course_no) COLLATE NOCASE='{$escSub}' LIMIT 1");
                    if ($q && ($r = $q->fetchArray(SQLITE3_ASSOC))) $row = $r;
                    if (!$row) {
                        $q2 = $bdb->query("SELECT pg.remarks, pg.average_grade, pg.average_equivalent, pg.equivalent_grade FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN subjects s ON c.subject_id=s.subject_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(s.name) COLLATE NOCASE='{$escSub}' LIMIT 1");
                        if ($q2 && ($r2 = $q2->fetchArray(SQLITE3_ASSOC))) $row = $r2;
                    }
                    if ($row) {
                        $rem = strtoupper(trim($row['remarks'] ?? ''));
                        if (in_array($rem, ['PASSED', 'PASSED WITH DISTINCTION', 'PASSED WITH HONORS'], true)) { $cache[$key] = true; $bdb->close(); return true; }
                        $avg = $row['average_grade'] ?? $row['average_equivalent'] ?? $row['equivalent_grade'] ?? null;
                        if ($avg !== null && is_numeric($avg)) {
                            $avgf = floatval($avg);
                            if ($avgf >= 1.0 && $avgf <= 3.0) { $cache[$key] = true; $bdb->close(); return true; }
                        }
                    }
                    $bdb->close();
                } catch (Exception $e) {}
            }
            if ($cache[$key]) return true;
        }
        return false;
    }
}

if (!function_exists('irregular_pending_entries')) {
    // Entries of an irregular row that still need action: not enrolled in a
    // matching class in ANY user database and not previously passed.
    // Returns NULL when the row has no assessable entries (never flip on that).
    function irregular_pending_entries($landing_row) {
        $entries = irregular_entries_of($landing_row);
        if (empty($entries)) return null;
        $num = trim((string)($landing_row['student_number'] ?? ''));
        if ($num === '') return $entries;
        if (!function_exists('find_student_enrollments')) { @require_once(__DIR__ . '/landing_enrollments.php'); }
        static $enrollCache = [];
        if (!isset($enrollCache[$num])) {
            $enrollCache[$num] = function_exists('find_student_enrollments') ? find_student_enrollments($num) : [];
        }
        $out = [];
        foreach ($entries as $e) {
            $done = false;
            foreach ($enrollCache[$num] as $en) {
                if (irregular_entry_matches_class($e, strtolower(trim($en['section'] ?? '')), strtolower(trim($en['course_no'] ?? '')), strtolower(trim($en['subject'] ?? '')))) { $done = true; break; }
            }
            if (!$done && landing_has_passed_anywhere($num, $e['code'])) $done = true;
            if (!$done) $out[] = $e;
        }
        return $out;
    }
}

if (!function_exists('landing_reconcile_irregular_status')) {
    // Reconcile stored status both ways so admin counts/statuses clear AND
    // reappear correctly: 'enrolled' only when fully handled (every picked
    // subject done AND enrolled in the regular year/section classes).
    // Per-class pending queries never depend on this flag for irregulars,
    // so persisting it hides nothing any class still needs.
    function landing_reconcile_irregular_status($db) {
        $changed = 0;
        try {
            $q = $db->query("SELECT id, student_number, course, year, section, status, is_irregular, irregular_subjects FROM landing_registrations WHERE is_irregular = 1");
            if (!$q) return 0;
            while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
                $want = irregular_fully_handled($r) ? 'enrolled' : 'pending';
                if (($r['status'] ?? '') !== $want) {
                    $rid = (int)($r['id'] ?? 0);
                    if ($rid > 0) @$db->exec("UPDATE landing_registrations SET status='{$want}', updated_at=CURRENT_TIMESTAMP WHERE id='{$rid}'");
                    $changed++;
                }
            }
        } catch (Exception $e) {}
        return $changed;
    }
}

if (!function_exists('irregular_fully_handled')) {
    // Fully handled = every picked entry done (enrolled anywhere or passed)
    // AND enrolled anywhere in the regular year/section classes. An
    // irregular with no assessable entries is never auto-resolved.
    function irregular_fully_handled($landing_row) {
        if (!landing_row_is_irregular($landing_row)) return false;
        $entries = irregular_entries_of($landing_row);
        if (empty($entries)) return false;
        $pend = irregular_pending_entries($landing_row);
        if ($pend === null || !empty($pend)) return false;
        $num = trim((string)($landing_row['student_number'] ?? ''));
        if ($num === '') return false;
        $sec = strtolower(trim($landing_row['section'] ?? ''));
        if ($sec === '') return true;
        $course = strtolower(trim($landing_row['course'] ?? ''));
        if (!function_exists('find_student_enrollments')) { @require_once(__DIR__ . '/landing_enrollments.php'); }
        if (!function_exists('find_student_enrollments')) return false;
        foreach (find_student_enrollments($num) as $en) {
            if (strtolower(trim($en['section'] ?? '')) !== $sec) continue;
            $g = strtolower(trim($en['course'] ?? ''));
            if ($g !== '' && $course !== '' && $g !== $course) continue;
            return true;
        }
        return false;
    }
}

if (!function_exists('landing_name_parts')) {
    // Extract firstname/middlename/lastname for duplicate scoring.
    // Prefers explicit columns; falls back to parsing "Lastname, Firstname Middlename".
    function landing_name_parts($row) {
        $fn = trim((string)($row['firstname'] ?? ''));
        $mn = trim((string)($row['middlename'] ?? ''));
        $ln = trim((string)($row['lastname'] ?? ''));
        if ($fn !== '' || $ln !== '') {
            return ['first' => $fn, 'middle' => $mn, 'last' => $ln];
        }
        $full = trim((string)($row['name'] ?? ''));
        if ($full === '') return ['first' => '', 'middle' => $mn, 'last' => ''];
        // Strip extension suffix
        foreach ([' Jr.', ' Sr.', ' II', ' III', ' IV', ' V'] as $ext) {
            if (substr($full, -strlen($ext)) === $ext) { $full = trim(substr($full, 0, -strlen($ext))); break; }
        }
        $comma = strpos($full, ',');
        if ($comma === false) return ['first' => $full, 'middle' => $mn, 'last' => ''];
        $ln = trim(substr($full, 0, $comma));
        $after = trim(substr($full, $comma + 1));
        if ($mn !== '' && substr($after, -strlen(' '.$mn)) === ' '.$mn) {
            $fn = trim(substr($after, 0, -strlen(' '.$mn)));
        } else {
            $fn = $after;
            // second token of after is often middlename when landing used free-form name
            $parts = preg_split('/\s+/', $after);
            if (count($parts) > 1) { $fn = $parts[0]; if ($mn === '') $mn = implode(' ', array_slice($parts, 1)); }
        }
        return ['first' => $fn, 'middle' => $mn, 'last' => $ln];
    }
}

if (!function_exists('landing_name_similarity')) {
    function landing_name_similarity($a, $b) {
        $a = strtolower(trim((string)$a));
        $b = strtolower(trim((string)$b));
        if ($a === '' && $b === '') return 100.0;
        if ($a === '' || $b === '') return 0.0;
        similar_text($a, $b, $pct);
        return (float)$pct;
    }
}

if (!function_exists('landing_duplicate_score')) {
    // 75% threshold: average of firstname + middlename + lastname + student_number
    // (exact SN = 100, mismatch = 0). Both-empty middlename counts as 100 so it
    // doesn't unfairly penalize students without a middle name.
    function landing_duplicate_score($pendingRow, $enrolledRow) {
        $pp = landing_name_parts($pendingRow);
        $ep = landing_name_parts($enrolledRow);
        $first = landing_name_similarity($pp['first'], $ep['first']);
        $middle = landing_name_similarity($pp['middle'], $ep['middle']);
        $last = landing_name_similarity($pp['last'], $ep['last']);
        $snA = strtolower(trim((string)($pendingRow['student_number'] ?? '')));
        $snB = strtolower(trim((string)($enrolledRow['student_number'] ?? '')));
        $sn = ($snA !== '' && $snA === $snB) ? 100.0 : 0.0;
        return ($first + $middle + $last + $sn) / 4.0;
    }
}

if (!function_exists('landing_best_duplicate')) {
    // Returns ['score'=>float,'match'=>?enrolledRow] best >=0; caller checks >=75.
    function landing_best_duplicate($pendingRow, $enrolledRows) {
        $best = ['score' => 0.0, 'match' => null];
        foreach ($enrolledRows as $er) {
            $sc = landing_duplicate_score($pendingRow, $er);
            if ($sc > $best['score']) $best = ['score' => $sc, 'match' => $er];
        }
        return $best;
    }
}
?>
