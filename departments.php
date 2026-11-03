<?php
/**
 * Department + course catalog.
 *
 * Departments (college units) and the courses they offer live in the MAIN
 * database so that every page reads the same list:
 *   - admin/departments.php  adds / renames / removes them (Administration)
 *   - admin/faculty.php      Department dropdown on the faculty form
 *   - landingpage.php        Course dropdown on the public registration form
 *
 * The main db file is opened on purpose - never the session-selected database.
 * The landing page is public (no session) and the catalog must be identical for
 * every visitor.
 *
 * The default colleges/courses are written exactly once - when these tables
 * are first created. After that the list is only ever changed by an admin from
 * the Departments & Courses page, so deleting something there sticks.
 */

if (!function_exists('departments_db')) {
    function departments_db() {
        static $conn = null;
        if ($conn instanceof SQLite3) {
            return $conn;
        }
        $path = __DIR__ . '/db/grading_db.db';
        if (!file_exists($path)) {
            return null;
        }
        $conn = new SQLite3($path);
        $conn->exec("PRAGMA busy_timeout = 5000");
        $conn->exec("PRAGMA journal_mode = WAL");
        ensure_departments_schema($conn);
        return $conn;
    }
}

if (!function_exists('default_departments')) {
    function default_departments() {
        return [
            ['code' => 'CCS',  'name' => 'College of Computer Studies',           'courses' => ['BSIT', 'BSCS', 'BSCE', 'BSIS']],
            ['code' => 'CCJE', 'name' => 'College of Criminal Justice Education', 'courses' => ['BSCrim', 'BSCJA']],
            ['code' => 'COED', 'name' => 'College of Education',                  'courses' => ['BEED', 'BSED', 'BPE', 'BSPED']],
            ['code' => 'CBA',  'name' => 'College of Business Administration',    'courses' => ['BSBA', 'BSBA-FM', 'BSTM', 'BSHM']],
            ['code' => 'COA',  'name' => 'College of Agriculture',                'courses' => ['BSA', 'BSAB', 'BSF']],
        ];
    }
}

if (!function_exists('ensure_departments_schema')) {
    function ensure_departments_schema($db) {
        if (!$db instanceof SQLite3) {
            return false;
        }
        // Seed ONLY the very first time the tables are created. Re-seeding on
        // every page load would resurrect a department the admin deleted.
        $firstRun = !$db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='departments'");

        @$db->exec("CREATE TABLE IF NOT EXISTS `departments` (
            `dept_code` TEXT NOT NULL PRIMARY KEY,
            `dept_name` TEXT NOT NULL,
            `sort_order` INTEGER NOT NULL DEFAULT 0,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        @$db->exec("CREATE TABLE IF NOT EXISTS `department_courses` (
            `course_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `dept_code` TEXT NOT NULL,
            `course` TEXT NOT NULL,
            `sort_order` INTEGER NOT NULL DEFAULT 0,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(`dept_code`, `course`)
        )");
        @$db->exec("CREATE INDEX IF NOT EXISTS idx_department_courses_code ON `department_courses`(`dept_code`)");

        if (!$firstRun) {
            return true;
        }

        $deptRows = [];
        $courseRows = [];
        foreach (default_departments() as $order => $dept) {
            $code = $db->escapeString($dept['code']);
            $deptRows[] = "('" . $code . "','" . $db->escapeString($dept['name']) . "'," . ((int)$order + 1) . ")";
            foreach ($dept['courses'] as $cOrder => $course) {
                $courseRows[] = "('" . $code . "','" . $db->escapeString($course) . "'," . ((int)$cOrder + 1) . ")";
            }
        }
        if (!empty($deptRows)) {
            @$db->exec("INSERT OR IGNORE INTO `departments` (`dept_code`, `dept_name`, `sort_order`) VALUES " . implode(',', $deptRows));
        }
        if (!empty($courseRows)) {
            @$db->exec("INSERT OR IGNORE INTO `department_courses` (`dept_code`, `course`, `sort_order`) VALUES " . implode(',', $courseRows));
        }
        return true;
    }
}

if (!function_exists('department_catalog')) {
    /**
     * dept_code => ['name' => full name, 'short' => name without "College of ",
     *               'courses' => [course, ...]]
     * Returns [] when the catalog cannot be read so callers can degrade to an
     * empty dropdown instead of fataling.
     */
    function department_catalog($db = null) {
        $db = ($db instanceof SQLite3) ? $db : departments_db();
        $catalog = [];
        if (!$db instanceof SQLite3) {
            return $catalog;
        }
        $has = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='departments'");
        if (!$has) {
            return $catalog;
        }
        $res = @$db->query("SELECT `dept_code`, `dept_name` FROM `departments` ORDER BY `sort_order` ASC, `dept_code` ASC");
        if ($res) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $code = trim((string)$row['dept_code']);
                if ($code === '') {
                    continue;
                }
                $name = trim((string)$row['dept_name']);
                $catalog[$code] = [
                    'name'       => $name,
                    'short'      => (string)preg_replace('/^College of\s+/i', '', $name),
                    'courses'    => [],
                    'course_ids' => [],
                ];
            }
        }
        $res = @$db->query("SELECT `course_id`, `dept_code`, `course` FROM `department_courses` ORDER BY `sort_order` ASC, `course` ASC");
        if ($res) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $code = trim((string)$row['dept_code']);
                $course = trim((string)$row['course']);
                if ($code === '' || $course === '') {
                    continue;
                }
                if (!isset($catalog[$code])) {
                    $catalog[$code] = ['name' => $code, 'short' => $code, 'courses' => [], 'course_ids' => []];
                }
                $catalog[$code]['courses'][]    = $course;
                $catalog[$code]['course_ids'][] = (int)$row['course_id'];
            }
        }
        return $catalog;
    }
}

if (!function_exists('department_dropdown_options')) {
    /**
     * dept_code => "CCS - Computer Studies" for a <select>.
     */
    function department_dropdown_options($db = null) {
        $options = [];
        foreach (department_catalog($db) as $code => $dept) {
            $options[$code] = $dept['short'] !== '' && $dept['short'] !== $code
                ? $code . ' - ' . $dept['short']
                : $code;
        }
        return $options;
    }
}

if (!function_exists('all_course_options')) {
    /**
     * Every course in the catalog, de-duplicated and sorted. Used by the public
     * registration form, which only asks for a course (no department).
     */
    function all_course_options($db = null) {
        $courses = [];
        foreach (department_catalog($db) as $dept) {
            foreach ($dept['courses'] as $course) {
                $courses[$course] = $course;
            }
        }
        $courses = array_values($courses);
        sort($courses);
        return $courses;
    }
}

if (!function_exists('department_course_groups')) {
    /**
     * dept_code => ['label' => "CCS - Computer Studies", 'courses' => [...]]
     * Used to render the course dropdown as <optgroup> per department.
     */
    function department_course_groups($db = null) {
        $groups = [];
        $seen = [];
        foreach (department_catalog($db) as $code => $dept) {
            $label = $dept['short'] !== '' && $dept['short'] !== $code
                ? $code . ' - ' . $dept['short']
                : $code;
            $groups[$code] = ['label' => $label, 'courses' => []];
            foreach ($dept['courses'] as $course) {
                // A course listed under two departments is offered once only.
                if (isset($seen[$course])) {
                    continue;
                }
                $seen[$course] = true;
                $groups[$code]['courses'][] = $course;
            }
        }
        // Drop departments that end up with no courses after de-duplication.
        foreach ($groups as $code => $group) {
            if (empty($group['courses'])) {
                unset($groups[$code]);
            }
        }
        return $groups;
    }
}

if (!function_exists('session_course_scope')) {
    /**
     * Which courses the logged-in user may see on the landing registration
     * screens.
     *
     * A College Dean or Program Head is scoped to the courses of their own
     * department (admin_list.program); every other account (admins, Faculty)
     * keeps seeing all courses.
     *
     * Returns:
     *   scoped           bool   - true when the list must be filtered
     *   position         string - the account's position
     *   department       string - department code
     *   department_name  string - full college name
     *   courses          array  - allowed course codes (meaningful when scoped)
     *   reason           string - '' | 'no_department' | 'no_courses'
     *
     * The account is re-read from admin_list on every call so a department or
     * position changed later takes effect immediately instead of waiting for
     * the user to log in again (the session copy goes stale).
     */
    function session_course_scope($session = null) {
        $session = is_array($session) ? $session : $_SESSION;
        $position = trim((string)($session['position'] ?? ''));
        $program  = strtoupper(trim((string)($session['program'] ?? '')));
        $adminId  = (int)($session['admin_id'] ?? 0);

        if ($adminId > 0) {
            $db = departments_db();
            if ($db) {
                $row = @$db->querySingle(
                    "SELECT `position`, `program` FROM `admin_list` WHERE `admin_id` = " . $adminId . " LIMIT 1",
                    true
                );
                if (is_array($row)) {
                    if (array_key_exists('position', $row)) $position = trim((string)$row['position']);
                    if (array_key_exists('program', $row)) $program = strtoupper(trim((string)$row['program']));
                }
            }
        }

        $scope = [
            'scoped'          => false,
            'position'        => $position,
            'department'      => $program,
            'department_name' => '',
            'courses'         => [],
            'reason'          => '',
        ];
        if (!in_array(strtolower($position), ['college dean', 'program head'], true)) {
            return $scope;
        }

        $scope['scoped'] = true;
        if ($program === '') {
            // No department on file: show nothing rather than leaking every
            // college's roster. The page explains why the list is empty.
            $scope['reason'] = 'no_department';
            return $scope;
        }

        $catalog = department_catalog();
        $scope['department_name'] = isset($catalog[$program]['name']) ? $catalog[$program]['name'] : $program;
        $scope['courses'] = isset($catalog[$program]['courses']) ? $catalog[$program]['courses'] : [];
        if (empty($scope['courses'])) {
            $scope['reason'] = 'no_courses';
        }
        return $scope;
    }
}

if (!function_exists('session_course_scope_sql')) {
    /**
     * SQL fragment restricting a landing_registrations query to the courses the
     * session may see.
     *
     * Returns null when nothing should be filtered, "0 = 1" when the user is
     * scoped but has no usable course list (matches nothing), or a
     * "course IN (...)" clause. $db must be the landing database handle.
     */
    function session_course_scope_sql($db, $scope = null) {
        if (!is_array($scope)) {
            $scope = session_course_scope();
        }
        if (empty($scope['scoped'])) {
            return null;
        }
        $courses = array_slice(array_values(array_filter(array_map('trim', (array)$scope['courses']), 'strlen')), 0, 500);
        if (empty($courses)) {
            return '0 = 1';
        }
        $list = [];
        foreach ($courses as $course) {
            $list[] = "'" . $db->escapeString($course) . "'";
        }
        return 'course IN (' . implode(',', $list) . ')';
    }
}