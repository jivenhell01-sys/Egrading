<?php
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters for cross-domain access
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // Allow all domains
        'secure' => false, // Allow HTTP (ngrok)
        'httponly' => true,
        'samesite' => 'Lax' // Allow cross-site requests
    ]);
    session_start();
}
// ...existing code...
$db_dir = __DIR__ . DIRECTORY_SEPARATOR . 'db';
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0777, true);
}
$active_db_file = $db_dir . DIRECTORY_SEPARATOR . 'grading_db.db';

// Check if user has manually switched database recently (within last hour)
if (isset($_SESSION['db_manually_switched']) && $_SESSION['db_manually_switched'] === true &&
    isset($_SESSION['db_file']) && is_string($_SESSION['db_file']) && file_exists($_SESSION['db_file']) &&
    isset($_SESSION['db_switch_time']) && (time() - $_SESSION['db_switch_time']) < 3600) {
    // Use the manually switched database
    $active_db_file = $_SESSION['db_file'];
} elseif (!empty($_SESSION['db_file']) && is_string($_SESSION['db_file']) && file_exists($_SESSION['db_file'])) {
    // Use the regular session database
    $active_db_file = $_SESSION['db_file'];
}

if (!defined('db_file')) define('db_file', $active_db_file);
// ...existing code...
function my_udf_md5($string)
{
    return md5($string);
}

class DBConnection extends SQLite3
{
    // Bump when adding new columns/tables so runSchemaSetup() re-runs and
    // applies the guarded ALTER migrations to existing databases.
    private const SCHEMA_VERSION = 5;

    protected $db;
    function __construct()
    {
        global $active_db_file;
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if we have a database file in session
        $dbFileToUse = null;
        
        // First check if database was manually switched and is recent (within last hour)
        if (isset($_SESSION['db_manually_switched']) && $_SESSION['db_manually_switched'] === true &&
            isset($_SESSION['db_file']) && file_exists($_SESSION['db_file']) &&
            isset($_SESSION['db_switch_time']) && (time() - $_SESSION['db_switch_time']) < 3600) {
            $dbFileToUse = $_SESSION['db_file'];
        }
        // Otherwise, check for regular session db_file
        elseif (isset($_SESSION['db_file']) && file_exists($_SESSION['db_file'])) {
            $dbFileToUse = $_SESSION['db_file'];
            error_log("DBConnection: Using regular session database: " . $dbFileToUse);
        } else {
            // Fall back to the default database
            $dbFileToUse = defined('db_file') ? db_file : $active_db_file;
        }
        
        // If still no database file, use the main one
        if (!$dbFileToUse || !file_exists($dbFileToUse)) {
            $dbFileToUse = __DIR__ . '/db/grading_db.db';
        }
        
        // Open the database with optimized settings
        $this->open($dbFileToUse);
        
        // Set performance optimizations
        $this->createFunction('md5', 'my_udf_md5');
        $this->exec("PRAGMA foreign_keys = ON;");
        $this->exec("PRAGMA busy_timeout = 10000");  // Increased timeout
        $this->exec("PRAGMA journal_mode = WAL");    // Better concurrency
        $this->exec("PRAGMA synchronous = NORMAL");  // Balanced safety/speed
        $this->exec("PRAGMA cache_size = 10000");    // 10MB cache
        $this->exec("PRAGMA temp_store = MEMORY");   // Use memory for temp tables
        $this->exec("PRAGMA mmap_size = 268435456"); // 256MB memory mapping

        if ((int)$this->querySingle('PRAGMA user_version') < self::SCHEMA_VERSION) {
            $this->runSchemaSetup();
            $this->initializeDatabase();
            $this->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        }
        // Idempotent ensure for device-binding tables even if user_version
        // already equals SCHEMA_VERSION (e.g. manual DB copies, faculty DBs).
        $this->ensureStudentDeviceBindingSchema();
        $this->ensureAttendanceSessionSchema();
    }

    private function ensureAttendanceSessionSchema()
    {
        @$this->exec("CREATE TABLE IF NOT EXISTS `attendance_sessions` (
            `session_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `date` TEXT NOT NULL,
            `status` TEXT NOT NULL DEFAULT 'active',
            `created_by` INTEGER,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            UNIQUE(`class_id`, `quarter`, `date`)
        )");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_sess_class ON `attendance_sessions`(`class_id`, `quarter`, `date`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_sess_status ON `attendance_sessions`(`status`)");
        @$this->exec("CREATE TABLE IF NOT EXISTS `attendance_requests` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `session_id` INTEGER NOT NULL,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `date` TEXT NOT NULL,
            `student_id` INTEGER NOT NULL,
            `student_number` TEXT NOT NULL,
            `status` TEXT NOT NULL DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `confirmed_at` TIMESTAMP,
            FOREIGN KEY(`session_id`) REFERENCES `attendance_sessions`(`session_id`) ON DELETE CASCADE,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            UNIQUE(`session_id`, `student_id`)
        )");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_req_session ON `attendance_requests`(`session_id`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_req_student ON `attendance_requests`(`student_number`, `status`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_req_class ON `attendance_requests`(`class_id`, `quarter`, `date`)");
    }

    private function ensureStudentDeviceBindingSchema()
    {
        // Device RECOGNITION is retired: no fingerprint/family/token schema.
        // student_device_bindings (and its UNIQUE index on device_fingerprint,
        // which could not tell two identical phones apart) is DROPPED rather
        // than recreated — it used to be created here on every connection,
        // which fought the drop in students/device_keys.php and kept the old
        // blocking schema alive.
        //
        // What remains is the append-only AUDIT trail, kept for history; its
        // fingerprint column is simply empty on new rows. The live hardware-key
        // schema (student_device_keys / device_resets / student_bind_pins) is
        // owned by students/device_keys.php, so this file never resurrects
        // retired tables.
        @$this->exec("DROP TABLE IF EXISTS `student_device_bindings`");
        @$this->exec("DROP TABLE IF EXISTS `student_device_browsers`");
        @$this->exec("CREATE TABLE IF NOT EXISTS `student_device_logs` (
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
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_student_number ON `student_device_logs`(`student_number`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_status ON `student_device_logs`(`status`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_created_at ON `student_device_logs`(`created_at`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_fingerprint ON `student_device_logs`(`device_fingerprint`)");
    }

    private function runSchemaSetup()
    {
        $this->exec("CREATE TABLE IF NOT EXISTS `admin_list` (
            `admin_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `fullname` TEXT NOT NULL,
            `username` TEXT NOT NULL,
            `password` TEXT NOT NULL,
            `image` TEXT DEFAULT NULL,
            `status` INTEGER NOT NULL DEFAULT 1,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `role` TEXT NOT NULL DEFAULT 'admin',
            `allowed_pages` TEXT DEFAULT NULL,
            `db_path` TEXT DEFAULT NULL,
            `remember_token` TEXT DEFAULT NULL,
            `remember_expiry` TEXT DEFAULT NULL
        )");

        $adminColumns = [];
        if ($res = $this->query("PRAGMA table_info('admin_list')")) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if (isset($row['name'])) {
                    $adminColumns[] = $row['name'];
                }
            }
        }
        if (!in_array('role', $adminColumns)) {
            @$this->exec("ALTER TABLE `admin_list` ADD COLUMN `role` TEXT NOT NULL DEFAULT 'admin'");
            @$this->exec("UPDATE `admin_list` SET `role` = 'admin' WHERE `role` IS NULL OR `role` = ''");
        }
        if (!in_array('allowed_pages', $adminColumns)) {
            @$this->exec("ALTER TABLE `admin_list` ADD COLUMN `allowed_pages` TEXT DEFAULT NULL");
        }
        if (!in_array('db_path', $adminColumns)) {
            @$this->exec("ALTER TABLE `admin_list` ADD COLUMN `db_path` TEXT DEFAULT NULL");
        }
        if (!in_array('remember_token', $adminColumns)) {
            @$this->exec("ALTER TABLE `admin_list` ADD COLUMN `remember_token` TEXT DEFAULT NULL");
        }
        if (!in_array('remember_expiry', $adminColumns)) {
            @$this->exec("ALTER TABLE `admin_list` ADD COLUMN `remember_expiry` TEXT DEFAULT NULL");
        }
        $this->exec("CREATE TABLE IF NOT EXISTS `subjects` (
            `subject_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `name` TEXT NOT NULL           
        )");

        $this->exec("CREATE TABLE IF NOT EXISTS `grading_components` (
            `component_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `name` TEXT NOT NULL
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `component_subject_percentage` (
            `csp_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `subject_id` INTEGER NOT NULL,
            `component_id` INTEGER NOT NULL,
            `percentage` TEXT NOT NULL,
            FOREIGN KEY(`subject_id`) REFERENCES `subjects`(`subject_id`) ON DELETE CASCADE,
            FOREIGN KEY(`component_id`) REFERENCES `grading_components`(`component_id`) ON DELETE CASCADE
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `registered_devices` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `device_id` TEXT NOT NULL UNIQUE,
            `device_name` TEXT NOT NULL,
            `ip_address` TEXT,
            `mac_address` TEXT,
            `is_active` INTEGER DEFAULT 1,
            `registered_by` INTEGER,
            `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(`registered_by`) REFERENCES `admin_list`(`admin_id`) ON DELETE SET NULL
        )");

        $this->exec("CREATE TABLE IF NOT EXISTS `class_list` (
            `class_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `subject_id` INTEGER NOT NULL,
            `course_no` TEXT NOT NULL,
            `grade` TEXT NOT NULL,
            `section` TEXT NOT NULL,
            `semester` TEXT NOT NULL,
            `year` TEXT NOT NULL,
            `schedule` TEXT NOT NULL,
            `unit` INTEGER NOT NULL,
            FOREIGN KEY(`subject_id`) REFERENCES `subjects`(`subject_id`) ON DELETE CASCADE
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `quarter_list` (
            `quarter_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `name` TEXT NOT NULL,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            UNIQUE(`class_id`, `quarter`)
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `student_list` (
            `student_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `student_number` TEXT NOT NULL,
            `name` TEXT NOT NULL,
            `middlename` TEXT,
            `birthdate` DATE,
            `age` INTEGER,
            `contact_number` TEXT,
            `email` TEXT,
            `barangay` TEXT,
            `municipality` TEXT,
            `province` TEXT,
            `zipcode` TEXT,
            `gender` TEXT,
            `civil_status` TEXT,
            `family_status` TEXT,
            `special_sector` TEXT,
            
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `assessment_list` (
            `assessment_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `component_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `name` TEXT NOT NULL,
            `percentage` TEXT NOT NULL,
            `hps` REAL NOT NULL,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            FOREIGN KEY(`component_id`) REFERENCES `grading_components`(`component_id`) ON DELETE CASCADE
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `mark_list` (
            `assessment_id` INTEGER NOT NULL,
            `student_id` INTEGER NOT NULL,
            `mark` REAL NOT NULL,         
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(`assessment_id`) REFERENCES `assessment_list`(`assessment_id`) ON DELETE CASCADE,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `student_initial_grade` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `student_id` INTEGER NOT NULL,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `initial_grade` REAL NOT NULL,
            `date_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            UNIQUE(`student_id`, `class_id`, `quarter`)
        )");
        $this->exec("CREATE TABLE IF NOT EXISTS `published_grades` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `student_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `initial_grade` REAL NOT NULL,
            `equivalent_grade` TEXT,
            `average_grade` REAL,
            `average_equivalent` TEXT,
            `remarks` TEXT,
            `published_by` INTEGER NOT NULL,
            `published_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `academic_year` TEXT,
            `semester` TEXT,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            FOREIGN KEY(`published_by`) REFERENCES `admin_list`(`admin_id`),
            UNIQUE(`class_id`, `student_id`, `quarter`)
        )");
        // Migration for published_grades table - add missing columns
        $publishedGradesColumns = [];
        if ($res = $this->query("PRAGMA table_info('published_grades')")) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if (isset($row['name'])) {
                    $publishedGradesColumns[] = $row['name'];
                }
            }
        }
        
        $requiredPublishedColumns = ['equivalent_grade', 'average_equivalent', 'academic_year'];
        foreach ($requiredPublishedColumns as $column) {
            if (!in_array($column, $publishedGradesColumns)) {
                @$this->exec("ALTER TABLE `published_grades` ADD COLUMN `$column` TEXT");
            }
        }

        // Lightweight migration: ensure older DBs have student_number column in student_list
        $hasStudentNumber = false;
        if ($res = $this->query("PRAGMA table_info('student_list')")) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if (isset($row['name']) && $row['name'] === 'student_number') {
                    $hasStudentNumber = true;
                    break;
                }
            }
        }
        if (!$hasStudentNumber) {
            // Add the column without NOT NULL to avoid migration failures on existing rows
            @$this->exec("ALTER TABLE `student_list` ADD COLUMN `student_number` TEXT");
            // Best-effort index; unique may fail if duplicates exist in legacy data
            @$this->exec("CREATE INDEX IF NOT EXISTS idx_student_list_student_number ON `student_list`(`student_number`)");
        }

        // Guard: one student_number per class — makes same-class duplicates
        // impossible even on double-submit races. Best-effort: silently skips
        // legacy databases that already contain exact duplicates.
        try { @$this->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_student_class_number ON `student_list`(`class_id`, `student_number`)"); }
        catch (Throwable $e) { error_log('idx_student_class_number skipped: ' . $e->getMessage()); }

        // Migration: add new student detail fields to existing databases
        $newFields = [
            'birthdate', 'contact_number', 'email', 
            'barangay', 'municipality', 'province', 'zipcode', 'gender',
            'firstname', 'lastname',
            'civil_status', 'family_status', 'special_sector'
        ];
        
        foreach ($newFields as $field) {
            $hasField = false;
            if ($res = $this->query("PRAGMA table_info('student_list')")) {
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    if (isset($row['name']) && $row['name'] === $field) {
                        $hasField = true;
                        break;
                    }
                }
            }
            if (!$hasField) {
                @$this->exec("ALTER TABLE `student_list` ADD COLUMN `$field` TEXT");
            }
        }
        $this->exec("CREATE TABLE IF NOT EXISTS `transmutation_table` (
            `trans_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `from` REAL NOT NULL,
            `to` REAL NOT NULL,
            `grade` REAL NOT NULL,
            `system` TEXT NOT NULL DEFAULT 'CHED'
        )");
        $this->exec("CREATE INDEX IF NOT EXISTS idx_transmutation_system ON `transmutation_table`(`system`)");
        
        $this->exec("CREATE TABLE IF NOT EXISTS `student_grades` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `student_id` INTEGER NOT NULL,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `grade` REAL NOT NULL,
            `date_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            UNIQUE(`student_id`, `class_id`, `quarter`)
        )");

        // Suppress warnings for production environment
        $old_error_reporting = error_reporting(E_ALL & ~E_WARNING);
        
        $this->exec("INSERT OR IGNORE INTO `admin_list` (`admin_id`,`fullname`,`username`,`password`,`image`,`status`,`date_created`,`role`,`allowed_pages`) VALUES (1,'Administrator','admin',md5('admin123'),NULL,1,CURRENT_TIMESTAMP,'admin',NULL)");
        
        // Insert new data with proper error handling (only if table is empty)
        try {
            // Check if table has data first
            $count = $this->querySingle("SELECT COUNT(*) FROM `transmutation_table` WHERE `system` = 'CHED'");
            if ($count == 0) {
                $this->exec("INSERT OR IGNORE INTO `transmutation_table` (`trans_id`, `from`, `to`, `grade`, `system`) VALUES 
             (1,49.50,50.49,5.5,'CHED'),
            (2,50.50,51.49,5.4,'CHED'),
            (3,51.50,52.49,5.3,'CHED'),
            (4,52.50,53.49,5.2,'CHED'),
            (5,53.50,54.49,5.1,'CHED'),
            (6,54.50,55.49,5.0,'CHED'),
            (7,55.50,56.49,4.9,'CHED'),
            (8,56.50,57.49,4.8,'CHED'),
            (9,57.50,58.49,4.7,'CHED'),
            (10,58.50,59.49,4.6,'CHED'),
            (11,59.50,60.49,4.5,'CHED'),
            (12,60.50,61.49,4.4,'CHED'),
            (13,61.50,62.49,4.3,'CHED'),
            (14,62.50,63.49,4.2,'CHED'),
            (15,63.50,64.49,4.1,'CHED'),
            (16,64.50,65.49,4.0,'CHED'),
            (17,65.50,66.49,3.9,'CHED'),
            (18,66.50,67.49,3.8,'CHED'),
            (19,67.50,68.49,3.7,'CHED'),
            (20,68.50,69.49,3.6,'CHED'),
            (21,69.50,70.49,3.5,'CHED'),
            (22,70.50,71.49,3.4,'CHED'),
            (23,71.50,72.49,3.3,'CHED'),
            (24,72.50,73.49,3.2,'CHED'),
            (25,73.50,74.49,3.1,'CHED'),
            (26,74.50,75.49,3.0,'CHED'),
            (27,75.50,76.49,2.9,'CHED'),
            (28,76.50,77.49,2.8,'CHED'),
            (29,77.50,78.49,2.7,'CHED'),
            (30,78.50,79.49,2.6,'CHED'),
            (31,79.50,80.49,2.5,'CHED'),
            (32,80.50,81.49,2.4,'CHED'),
            (33,81.50,82.49,2.3,'CHED'),
            (34,82.50,83.49,2.2,'CHED'),
            (35,83.50,84.49,2.1,'CHED'),
            (36,84.50,85.49,2.0,'CHED'),
            (37,85.50,86.49,1.9,'CHED'),
            (38,86.50,87.49,1.8,'CHED'),
            (39,87.50,88.49,1.7,'CHED'),
            (40,88.50,89.49,1.6,'CHED'),
            (41,89.50,90.49,1.5,'CHED'),
            (42,90.50,92.49,1.4,'CHED'),
            (43,92.50,94.49,1.3,'CHED'),
            (44,94.50,96.49,1.2,'CHED'),
            (45,96.50,98.49,1.1,'CHED'),
            (46,98.50,100.00,1.0,'CHED')
        ");
            }
        } catch (Exception $e) {
            // Ignore if data already exists or table is locked
            error_log("Warning inserting transmutation data: " . $e->getMessage());
        }

        // CHED/DEPED system support + DepEd seed for this database.
        ensure_transmutation_systems($this);
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        // First, check if signatories table exists
        $tableExists = $this->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='signatories'");
        
        if ($tableExists) {
            // Check if type column exists
            $columns = $this->query("PRAGMA table_info(signatories)");
            $hasTypeColumn = false;
            
            while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
                if ($col['name'] === 'type') {
                    $hasTypeColumn = true;
                    break;
                }
            }
            
            if (!$hasTypeColumn) {
                // Add type column with default value
                $this->exec("ALTER TABLE `signatories` ADD COLUMN `type` TEXT NOT NULL DEFAULT 'certified'");
                
                // If there are existing records, update them to have the default type
                $this->exec("UPDATE `signatories` SET `type` = 'certified' WHERE `type` IS NULL OR `type` = ''");
            }
        } else {
            // Create new table with all columns
            $this->exec("CREATE TABLE `signatories` (
                `signatory_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `fullname` TEXT NOT NULL,
                `designation` TEXT NOT NULL,
                `type` TEXT NOT NULL DEFAULT 'certified',
                `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
        
        // Create index for faster lookups
        try {
            $this->exec("CREATE INDEX IF NOT EXISTS idx_signatory_type ON signatories(type)");
        } catch (Exception $e) {
            // Index might already exist, ignore error
            error_log("Note: Could not create index (it might already exist): " . $e->getMessage());
        }
        
        // Create transfer_logs table to track database transfers
        $this->exec("CREATE TABLE IF NOT EXISTS `transfer_logs` (
            `transfer_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `admin_id` INTEGER NOT NULL,
            `transfer_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `backup_file` TEXT NOT NULL,
            `file_size` INTEGER,
            `tables_transferred` INTEGER,
            `status` TEXT NOT NULL DEFAULT 'completed',
            `notes` TEXT,
            FOREIGN KEY(`admin_id`) REFERENCES `admin_list`(`admin_id`) ON DELETE CASCADE
        )");
        
        // Create index for transfer logs
        try {
            $this->exec("CREATE INDEX IF NOT EXISTS idx_transfer_logs_date ON transfer_logs(transfer_date)");
        } catch (Exception $e) {
            // Index might already exist, ignore error
            error_log("Note: Could not create transfer log index (it might already exist): " . $e->getMessage());
        }
        
        // Create restore_logs table to track database restores
        $this->exec("CREATE TABLE IF NOT EXISTS `restore_logs` (
            `restore_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `admin_id` INTEGER NOT NULL,
            `restore_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `backup_file` TEXT NOT NULL,
            `file_size` INTEGER,
            `tables_restored` INTEGER,
            `status` TEXT NOT NULL DEFAULT 'completed',
            `notes` TEXT,
            FOREIGN KEY(`admin_id`) REFERENCES `admin_list`(`admin_id`) ON DELETE CASCADE
        )");
        
        // Create index for restore logs
        try {
            $this->exec("CREATE INDEX IF NOT EXISTS idx_restore_logs_date ON restore_logs(restore_date)");
        } catch (Exception $e) {
            // Index might already exist, ignore error
            error_log("Note: Could not create restore log index (it might already exist): " . $e->getMessage());
        }

        $this->exec("CREATE TABLE IF NOT EXISTS `security_settings` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `security_enabled` INTEGER NOT NULL DEFAULT 1,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $securityCount = (int)$this->querySingle("SELECT COUNT(*) FROM `security_settings`");
        if ($securityCount === 0) {
            $this->exec("INSERT INTO `security_settings` (security_enabled) VALUES (1)");
        }

        // ------------------------------------------------------------------
        // Student device audit log — history only.
        // Device RECOGNITION (fingerprint/family/token binding) is retired.
        // student_device_bindings and its UNIQUE index on device_fingerprint
        // are dropped on sight: that schema could not tell two identical
        // phones apart, so it locked students out of each other's devices.
        // The replacement is a hardware-backed cryptographic key (one active
        // key per student number), owned by students/device_keys.php. This
        // file must never recreate the retired tables, or the two definitions
        // fight on every request.
        // ------------------------------------------------------------------
        @$this->exec("DROP TABLE IF EXISTS `student_device_bindings`");
        @$this->exec("DROP TABLE IF EXISTS `student_device_browsers`");

        $this->exec("CREATE TABLE IF NOT EXISTS `student_device_logs` (
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
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_student_number ON `student_device_logs`(`student_number`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_status ON `student_device_logs`(`status`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_created_at ON `student_device_logs`(`created_at`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_sdl_fingerprint ON `student_device_logs`(`device_fingerprint`)");

        // ------------------------------------------------------------------
        // Attendance confirmation sessions — faculty Start Attendance flow
        // ------------------------------------------------------------------
        $this->exec("CREATE TABLE IF NOT EXISTS `attendance_sessions` (
            `session_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `date` TEXT NOT NULL,
            `status` TEXT NOT NULL DEFAULT 'active',
            `created_by` INTEGER,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            UNIQUE(`class_id`, `quarter`, `date`)
        )");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_sess_class ON `attendance_sessions`(`class_id`, `quarter`, `date`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_sess_status ON `attendance_sessions`(`status`)");
        $this->exec("CREATE TABLE IF NOT EXISTS `attendance_requests` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `session_id` INTEGER NOT NULL,
            `class_id` INTEGER NOT NULL,
            `quarter` INTEGER NOT NULL,
            `date` TEXT NOT NULL,
            `student_id` INTEGER NOT NULL,
            `student_number` TEXT NOT NULL,
            `status` TEXT NOT NULL DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `confirmed_at` TIMESTAMP,
            FOREIGN KEY(`session_id`) REFERENCES `attendance_sessions`(`session_id`) ON DELETE CASCADE,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            UNIQUE(`session_id`, `student_id`)
        )");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_req_session ON `attendance_requests`(`session_id`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_req_student ON `attendance_requests`(`student_number`, `status`)");
        @$this->exec("CREATE INDEX IF NOT EXISTS idx_att_req_class ON `attendance_requests`(`class_id`, `quarter`, `date`)");
    }
    
    function __destruct()
    {
        $this->close();
    }
    
    public function getHeaderFooterSettings() {
        $settings = [
            'header' => [
                'left' => null,
                'center' => null,
                'right' => null
            ],
            'footer' => [
                'left' => null,
                'center' => null,
                'right' => null
            ]
        ];
        
        $result = $this->query("SELECT * FROM header_footer_settings WHERE is_active = 1");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($settings[$row['element_type']][$row['position']])) {
                $settings[$row['element_type']][$row['position']] = $row['image_path'];
            }
        }
        
        return $settings;
    }
    
    // Method to check and update database schema
    private function updateDatabaseSchema() {
        // Check and add date column to assessment_list table
        $assessmentColumns = $this->query("PRAGMA table_info(assessment_list)");
        $hasDateColumn = false;
        
        while ($col = $assessmentColumns->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'date') {
                $hasDateColumn = true;
                break;
            }
        }
        
        if (!$hasDateColumn) {
            // Add date column to assessment_list table
            $this->exec("ALTER TABLE assessment_list ADD COLUMN date TEXT DEFAULT NULL");
        }
        
        // Check if attendance table exists
        $tableExists = $this->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance'");
        
        if ($tableExists) {
            // Check if date_created column exists
            $columns = $this->query("PRAGMA table_info(attendance)");
            $hasDateCreated = false;
            $hasIsClicked = false;
            $hasReason = false;
            
            while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
                if ($col['name'] === 'date_created') {
                    $hasDateCreated = true;
                }
                if ($col['name'] === 'is_clicked') {
                    $hasIsClicked = true;
                }
                if ($col['name'] === 'reason') {
                    $hasReason = true;
                }
            }
            
            if (!$hasDateCreated) {
                // Add date_created column
                $this->exec("ALTER TABLE attendance ADD COLUMN date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            }
            
            if (!$hasIsClicked) {
                // Add is_clicked column with default value 0
                $this->exec("ALTER TABLE attendance ADD COLUMN is_clicked INTEGER DEFAULT 0");
            } else {
                // Check if is_clicked column is NOT NULL and needs to be altered
                $columnInfo = $this->querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name='attendance'");
                if ($columnInfo && strpos($columnInfo, 'is_clicked INTEGER NOT NULL') !== false) {
                    // For SQLite, we need to recreate the table to change NOT NULL to DEFAULT
                    $this->exec("BEGIN TRANSACTION");
                    
                    // Create new table structure with reason column
                    $this->exec("CREATE TABLE attendance (
                        `attendance_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                        `class_id` INTEGER NOT NULL,
                        `student_id` INTEGER NOT NULL,
                        `date` DATE NOT NULL,
                        `status` TEXT NOT NULL,
                        `is_clicked` INTEGER DEFAULT 0,
                        `reason` TEXT,
                        `quarter` INTEGER NOT NULL,
                        `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
                        FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
                        UNIQUE(`class_id`, `student_id`, `date`, `quarter`)
                    )");
                    
                    // Copy data from backup
                    $this->exec("INSERT INTO attendance SELECT 
                        attendance_id, class_id, student_id, date, status, 
                        CASE WHEN is_clicked IS NULL THEN 0 ELSE is_clicked END,
                        NULL, -- reason column (NULL for existing records)
                        quarter, date_created 
                        FROM attendance_backup");
                    
                    // Drop backup table
                    $this->exec("DROP TABLE attendance_backup");
                    
                    $this->exec("COMMIT");
                    
                    // Mark reason as existing since we created it in new table
                    $hasReason = true;
                }
            }
            
            if (!$hasReason) {
                // Add reason column
                $this->exec("ALTER TABLE attendance ADD COLUMN reason TEXT");
            }
        }
    }
    
    // Method to create necessary database objects
    public function initializeDatabase() {
        // Patch older databases: the early schema had UNIQUE(element_type)
        // which blocked more than one position per type (header/footer).
        try {
            $hfs_sql = $this->querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name='header_footer_settings'");
            if ($hfs_sql && strpos($hfs_sql, 'UNIQUE(element_type)') !== false) {
                $this->exec("CREATE TABLE IF NOT EXISTS `header_footer_settings_new` (
                    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                    `element_type` TEXT NOT NULL CHECK(element_type IN ('header', 'footer')),
                    `position` TEXT NOT NULL CHECK(position IN ('left', 'center', 'right')),
                    `image_path` TEXT,
                    `image_width` INTEGER,
                    `image_height` INTEGER,
                    `is_active` INTEGER NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(element_type, position)
                )");
                $this->exec("INSERT OR IGNORE INTO `header_footer_settings_new` (id, element_type, position, image_path, is_active, created_at, updated_at) SELECT id, element_type, position, image_path, is_active, created_at, updated_at FROM `header_footer_settings`");
                $this->exec("DROP TABLE `header_footer_settings`");
                $this->exec("ALTER TABLE `header_footer_settings_new` RENAME TO `header_footer_settings`");
            }
        } catch (Exception $e) {}

        // Ensure missing columns exist on older header_footer_settings tables
        try {
            $hfs_cols = [];
            if ($res = $this->query("PRAGMA table_info('header_footer_settings')")) {
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    if (isset($row['name'])) $hfs_cols[] = $row['name'];
                }
            }
            if (!in_array('image_width', $hfs_cols)) {
                @$this->exec("ALTER TABLE `header_footer_settings` ADD COLUMN `image_width` INTEGER");
            }
            if (!in_array('image_height', $hfs_cols)) {
                @$this->exec("ALTER TABLE `header_footer_settings` ADD COLUMN `image_height` INTEGER");
            }
        } catch (Exception $e) {}

        // Update database schema if needed
        $this->updateDatabaseSchema();
        // Create header_footer_settings table if it doesn't exist
        $this->exec("CREATE TABLE IF NOT EXISTS `header_footer_settings` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `element_type` TEXT NOT NULL CHECK(element_type IN ('header', 'footer')),
            `position` TEXT NOT NULL CHECK(position IN ('left', 'center', 'right')),
            `image_path` TEXT,
            `is_active` INTEGER NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(element_type, position)
        )");
        
        // Create attendance table if it doesn't exist
        $this->exec("CREATE TABLE IF NOT EXISTS `attendance` (
            `attendance_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `student_id` INTEGER NOT NULL,
            `date` DATE NOT NULL,
            `status` TEXT NOT NULL,
            `is_clicked` INTEGER DEFAULT 0,
            `reason` TEXT,
            `quarter` INTEGER NOT NULL,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            UNIQUE(`class_id`, `student_id`, `date`, `quarter`)
        )");
        
        // Create a trigger to update the updated_at timestamp
        $this->exec("CREATE TRIGGER IF NOT EXISTS update_header_footer_timestamp
        AFTER UPDATE ON header_footer_settings
        BEGIN
            UPDATE header_footer_settings SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END;");

        // Seed quarter_list from existing assessments. Quarters are CUSTOM
        // MADE, so new entries start with a neutral 'Quarter N' label that the
        // instructor can rename in the Marks section.
        $this->exec("INSERT OR IGNORE INTO quarter_list (class_id, quarter, name)
            SELECT DISTINCT class_id, quarter, 'Quarter ' || quarter
            FROM assessment_list");
    }
}

// ---------------------------------------------------------------------------
// Shared quarter-name helper.
// Quarters are CUSTOM MADE per class and stored in `quarter_list`
// (class_id, quarter, name). This helper lets reports / exports render the
// friendly quarter label that the instructor defined in the Marks section.
// There are no hardcoded term names - an unknown quarter falls back to a
// neutral 'Quarter N' label.
// ---------------------------------------------------------------------------
if (!function_exists('get_quarter_name')) {
    function get_quarter_name($db, $class_id, $quarter)
    {
        $class_id = (int)$class_id;
        $quarter  = (string)$quarter;

        if ($quarter === '') {
            return '';
        }

        // Combined multi-quarter ("Active Quarters") view keeps its label.
        if ($quarter === 'active') {
            return 'All Quarters';
        }

        $safeQuarter = intval($quarter);

        if ($class_id > 0) {
            $row = $db->querySingle(
                "SELECT name FROM quarter_list WHERE class_id = " . $class_id .
                " AND quarter = " . $safeQuarter,
                true
            );
            if ($row && !empty($row['name'])) {
                return $row['name'];
            }
        }

        return 'Quarter ' . $safeQuarter;
    }
}

// ---------------------------------------------------------------------------
// CHED / DEPED transmutation systems.
//
// `transmutation_table.system` holds one row-set per system ('CHED' or 'DEPED').
// CHED rows are the original tertiary 1.0-5.0 table.
// DEPED rows are the OFFICIAL DepEd (DO 8, s. 2015) transmutation table:
// Initial Grade range -> Transmuted Grade (report-card grade, 60 floor,
// 60.00-61.59 -> 75 is the minimum passing mark). Verified anchor points:
// initial 83.33 -> 89, 60.00 -> 75, 100 -> 100, 0 -> 60.
// `system_settings.active_system` picks which set grade computation uses.
// Remarks vocabulary stays PASSED/FAILED/CONDITIONAL/DROPPED/INC/NO GRADE so
// every existing consumer (enrollment checks, portals, exports) keeps working;
// DepEd numbers simply never yield CONDITIONAL.
// ---------------------------------------------------------------------------
if (!function_exists('deped_official_transmutation_rows')) {
    // [from, to, transmuted] — full 0-100 coverage, contiguous, no overlaps.
    function deped_official_transmutation_rows()
    {
        return [
            [100.00, 100.00, 100],
            [98.40, 99.99, 99],
            [96.80, 98.39, 98],
            [95.20, 96.79, 97],
            [93.60, 95.19, 96],
            [92.00, 93.59, 95],
            [90.40, 91.99, 94],
            [88.80, 90.39, 93],
            [87.20, 88.79, 92],
            [85.60, 87.19, 91],
            [84.00, 85.59, 90],
            [82.40, 83.99, 89],
            [80.80, 82.39, 88],
            [79.20, 80.79, 87],
            [77.60, 79.19, 86],
            [76.00, 77.59, 85],
            [74.40, 75.99, 84],
            [72.80, 74.39, 83],
            [71.20, 72.79, 82],
            [69.60, 71.19, 81],
            [68.00, 69.59, 80],
            [66.40, 67.99, 79],
            [64.80, 66.39, 78],
            [63.20, 64.79, 77],
            [61.60, 63.19, 76],
            [60.00, 61.59, 75],
            [56.00, 59.99, 74],
            [52.00, 55.99, 73],
            [48.00, 51.99, 72],
            [44.00, 47.99, 71],
            [40.00, 43.99, 70],
            [36.00, 39.99, 69],
            [32.00, 35.99, 68],
            [28.00, 31.99, 67],
            [24.00, 27.99, 66],
            [20.00, 23.99, 65],
            [16.00, 19.99, 64],
            [12.00, 15.99, 63],
            [8.00, 11.99, 62],
            [4.00, 7.99, 61],
            [0.00, 3.99, 60],
        ];
    }
}

if (!function_exists('ensure_transmutation_systems')) {
    function ensure_transmutation_systems($db)
    {
        // Works with both SQLite3 and PDO handles (students portal uses PDO).
        $fetchAll = function ($sql) use ($db) {
            if ($db instanceof PDO) {
                $r = $db->query($sql);
                return $r ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
            }
            $out = [];
            if ($res = $db->query($sql)) {
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
            }
            return $out;
        };
        $fetchOne = function ($sql) use ($db) {
            if ($db instanceof PDO) {
                $r = $db->query($sql);
                return $r ? $r->fetchColumn() : false;
            }
            return $db->querySingle($sql);
        };
        $num = function ($v) {
            return number_format((float)$v, 2, '.', '');
        };
        try {
            $cols = [];
            foreach ($fetchAll("PRAGMA table_info('transmutation_table')") as $row) {
                if (isset($row['name'])) $cols[] = $row['name'];
            }
            if (empty($cols)) return; // table does not exist in this database
            if (!in_array('system', $cols)) {
                @$db->exec("ALTER TABLE `transmutation_table` ADD COLUMN `system` TEXT NOT NULL DEFAULT 'CHED'");
                @$db->exec("UPDATE `transmutation_table` SET `system` = 'CHED' WHERE `system` IS NULL OR TRIM(`system`) = ''");
            }
            @$db->exec("CREATE INDEX IF NOT EXISTS idx_transmutation_system ON `transmutation_table`(`system`)");
            @$db->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
                `id` INTEGER NOT NULL PRIMARY KEY CHECK (id = 1),
                `active_system` TEXT NOT NULL DEFAULT 'CHED',
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            @$db->exec("INSERT OR IGNORE INTO `system_settings` (id, active_system) VALUES (1, 'CHED')");

            // Seed the official DepEd table. If the short-lived linear
            // approximation (22 rows incl. from=0.00/to=74.99/grade=5.0 and
            // from=100.00/to=100.00/grade=1.0) is present exactly as seeded,
            // replace it with the official 41 rows; user-customized sets are
            // left untouched.
            $depedRows = $fetchAll("SELECT `from`, `to`, `grade` FROM `transmutation_table` WHERE `system` = 'DEPED'");
            if (empty($depedRows)) {
                foreach (deped_official_transmutation_rows() as $r) {
                    $db->exec("INSERT INTO `transmutation_table` (`from`, `to`, `grade`, `system`) VALUES (" . $num($r[0]) . ", " . $num($r[1]) . ", " . $num($r[2]) . ", 'DEPED')");
                }
            } else {
                $sig = [];
                foreach ($depedRows as $r) {
                    $sig[] = $num($r['from']) . '|' . $num($r['to']) . '|' . $num($r['grade']);
                }
                sort($sig);
                $linear = ['0.00|74.99|5.00', '100.00|100.00|1.00'];
                for ($k = 0; $k < 20; $k++) {
                    $from = 75 + 1.25 * $k;
                    $linear[] = $num($from) . '|' . $num($from + 1.24) . '|' . $num(3.0 - 0.1 * $k);
                }
                sort($linear);
                if ($sig === $linear) {
                    $db->exec("DELETE FROM `transmutation_table` WHERE `system` = 'DEPED'");
                    foreach (deped_official_transmutation_rows() as $r) {
                        $db->exec("INSERT INTO `transmutation_table` (`from`, `to`, `grade`, `system`) VALUES (" . $num($r[0]) . ", " . $num($r[1]) . ", " . $num($r[2]) . ", 'DEPED')");
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ensure_transmutation_systems: ' . $e->getMessage());
        } catch (PDOException $e) {
            error_log('ensure_transmutation_systems: ' . $e->getMessage());
        }
    }
}

if (!function_exists('get_active_transmutation_system')) {
    // Which transmutation set grade computation must use ('CHED' or 'DEPED').
    // Result is cached per connection object for the rest of the request.
    function get_active_transmutation_system($db)
    {
        static $cache = [];
        $key = is_object($db) ? spl_object_id($db) : 'default';
        if (!isset($cache[$key])) {
            ensure_transmutation_systems($db);
            $v = 'CHED';
            try {
                if ($db instanceof PDO) {
                    $r = $db->query("SELECT active_system FROM `system_settings` WHERE id = 1");
                    $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
                } else {
                    $row = $db->querySingle("SELECT active_system FROM `system_settings` WHERE id = 1", true);
                }
                if (is_array($row) && isset($row['active_system'])) {
                    $t = strtoupper(trim((string)$row['active_system']));
                    if ($t === 'CHED' || $t === 'DEPED') $v = $t;
                }
            } catch (Exception $e) {}
            $cache[$key] = $v;
        }
        return $cache[$key];
    }
}

if (!function_exists('remarks_for_grade')) {
    // Remarks vocabulary stays PASSED/FAILED/CONDITIONAL/DROPPED/INC/NO GRADE
    // for both systems so every consumer (enrollment checks, portals, exports)
    // keeps working. $system is 'CHED', 'DEPED', or anything else for AUTO
    // scale detection (numbers above 5.5 can only be DepEd transmuted grades).
    function remarks_for_grade($num, $system = 'CHED')
    {
        $num = is_numeric($num) ? floatval($num) : 0;
        $sys = strtoupper(trim((string)$system));
        if ($sys !== 'CHED' && $sys !== 'DEPED') {
            $sys = ($num > 5.5) ? 'DEPED' : 'CHED';
        }
        if ($sys === 'DEPED') {
            if ($num <= 0) return 'NO GRADE';
            if ($num < 60 || $num > 100) return 'DROPPED';
            return $num >= 75 ? 'PASSED' : 'FAILED';
        }
        if ($num == 0) return 'NO GRADE';
        if ($num == 5.0) return 'FAILED';
        if ($num > 5.0 || $num < 1.0) return 'DROPPED';
        if ($num >= 3.6) return 'FAILED';
        if ($num >= 3.1) return 'CONDITIONAL';
        return 'PASSED';
    }
}

if (!function_exists('grade_color_class')) {
    // Bootstrap text color for a computed numeric grade. Same AUTO rule.
    function grade_color_class($num, $system = 'CHED')
    {
        $num = is_numeric($num) ? floatval($num) : 0;
        $sys = strtoupper(trim((string)$system));
        if ($sys !== 'CHED' && $sys !== 'DEPED') {
            $sys = ($num > 5.5) ? 'DEPED' : 'CHED';
        }
        if ($sys === 'DEPED') {
            return $num >= 75 ? 'text-success fw-bold' : 'text-danger fw-bold';
        }
        if ($num >= 5.0 || $num < 1.0) return 'text-danger fw-bold';
        if ($num >= 3.1 && $num <= 3.5) return 'text-warning fw-bold';
        if ($num > 3.5) return 'text-danger fw-bold';
        if ($num >= 1.0 && $num <= 3.0) return 'text-success fw-bold';
        return 'text-muted';
    }
}

$conn = new DBConnection();
