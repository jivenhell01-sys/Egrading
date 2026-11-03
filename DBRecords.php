<?php
// Include DBConnection first to make my_udf_md5 available

$db_dir = __DIR__ . DIRECTORY_SEPARATOR . 'db/backups';
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0777, true);
}
if (!defined('db_file')) define('db_file', $db_dir . DIRECTORY_SEPARATOR . 'grading_db_latest_backup.sqlite');

class DBRecords extends SQLite3
{
    protected $db;
    function __construct()
    {
        $this->open(db_file);
        $this->createFunction('md5', 'my_udf_md5');
        $this->exec("PRAGMA foreign_keys = ON;");

        $this->exec("CREATE TABLE IF NOT EXISTS `admin_list` (
            `admin_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `fullname` TEXT NOT NULL,
            `username` TEXT NOT NULL,
            `password` TEXT NOT NULL,
            `image` TEXT DEFAULT NULL,
            `status` INTEGER NOT NULL DEFAULT 1,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
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

        // Migration: add new student detail fields to existing databases
        $newFields = [
            'birthdate', 'contact_number', 'email', 
            'barangay', 'municipality', 'province', 'zipcode', 'gender',
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
            `grade` REAL NOT NULL
        )");
        
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

        $this->exec("INSERT or IGNORE INTO `admin_list` VALUES (1,'Administrator','admin',md5('admin123'),1, CURRENT_TIMESTAMP,'')");
        // First, clear existing data
        $this->exec("DELETE FROM `transmutation_table`");
        
        // Insert new data with 54.50-99.99 range and 5.5-1.0 equivalents
        $this->exec("INSERT INTO `transmutation_table` VALUES 
 (1,49.50,50.49,5.5),
            (2,50.50,51.49,5.4),
            (3,51.50,52.49,5.3),
            (4,52.50,53.49,5.2),
            (5,53.50,54.49,5.1),
            (6,54.50,55.49,5.0),
            (7,55.50,56.49,4.9),
            (8,56.50,57.49,4.8),
            (9,57.50,58.49,4.7),
            (10,58.50,59.49,4.6),
            (11,59.50,60.49,4.5),
            (12,60.50,61.49,4.4),
            (13,61.50,62.49,4.3),
            (14,62.50,63.49,4.2),
            (15,63.50,64.49,4.1),
            (16,64.50,65.49,4.0),
            (17,65.50,66.49,3.9),
            (18,66.50,67.49,3.8),
            (19,67.50,68.49,3.7),
            (20,68.50,69.49,3.6),
            (21,69.50,70.49,3.5),
            (22,70.50,71.49,3.4),
            (23,71.50,72.49,3.3),
            (24,72.50,73.49,3.2),
            (25,73.50,74.49,3.1),
            (26,74.50,75.49,3.0),
            (27,75.50,76.49,2.9),
            (28,76.50,77.49,2.8),
            (29,77.50,78.49,2.7),
            (30,78.50,79.49,2.6),
            (31,79.50,80.49,2.5),
            (32,80.50,81.49,2.4),
            (33,81.50,82.49,2.3),
            (34,82.50,83.49,2.2),
            (35,83.50,84.49,2.1),
            (36,84.50,85.49,2.0),
            (37,85.50,86.49,1.9),
            (38,86.50,87.49,1.8),
            (39,87.50,88.49,1.7),
            (40,88.50,89.49,1.6),
            (41,89.50,90.49,1.5),
            (42,90.50,92.49,1.4),
            (43,92.50,94.49,1.3),
            (44,94.50,96.49,1.2),
            (45,96.50,98.49,1.1),
            (46,98.50,100.00,1.0)
        ");        
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
        // Check if attendance table exists
        $tableExists = $this->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance'");
        
        if ($tableExists) {
            // Check if date_created column exists
            $columns = $this->query("PRAGMA table_info(attendance)");
            $hasDateCreated = false;
            
            while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
                if ($col['name'] === 'date_created') {
                    $hasDateCreated = true;
                    break;
                }
            }
            
            if (!$hasDateCreated) {
                // Add date_created column
                $this->exec("ALTER TABLE attendance ADD COLUMN date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            }
        }
    }
    
    // Method to create necessary database objects
    public function initializeDatabase() {
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
            UNIQUE(element_type)
        )");
        
        // Create attendance table if it doesn't exist
        $this->exec("CREATE TABLE IF NOT EXISTS `attendance` (
            `attendance_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `student_id` INTEGER NOT NULL,
            `date` DATE NOT NULL,
            `status` TEXT NOT NULL,
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
    }
}

// Initialize the database connection and create necessary tables/triggers
$conn = new DBRecords();
$conn->initializeDatabase();
