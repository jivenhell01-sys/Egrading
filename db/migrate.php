<?php
require_once('DBConnection.php');

class Migration {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function runMigrations() {
        $this->createAttendanceTable();
        $this->createAttendanceMarksLogTable();
        $this->addQuizListFields();
        $this->addClassFields();
        echo "Migrations completed successfully.\n";
    }
    
    private function createAttendanceTable() {
        $sql = "
        CREATE TABLE IF NOT EXISTS `attendance` (
            `attendance_id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `student_id` INTEGER NOT NULL,
            `class_id` INTEGER NOT NULL,
            `date` DATE NOT NULL,
            `status` TEXT NOT NULL CHECK(status IN ('present', 'absent', 'late', 'excused')),
            `remarks` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`student_id`) REFERENCES `student_list` (`student_id`) ON DELETE CASCADE,
            FOREIGN KEY (`class_id`) REFERENCES `class_list` (`class_id`) ON DELETE CASCADE,
            UNIQUE(`student_id`, `date`, `class_id`)
        )";
        
        $this->db->exec($sql);
        
        // Create indexes
        $this->db->exec("CREATE INDEX IF NOT EXISTS `idx_attendance_student_class` ON `attendance` (`student_id`, `class_id`)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS `idx_attendance_date` ON `attendance` (`date`)");
        
        echo "Created attendance table and indexes.\n";
    }
    
    private function createAttendanceMarksLogTable() {
        // Drop the table if it exists to ensure clean creation
        $this->db->exec("DROP TABLE IF EXISTS `attendance_marks_log`");
        
        $sql = "
        CREATE TABLE `attendance_marks_log` (
            `log_id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `student_id` INTEGER NOT NULL,
            `class_id` INTEGER NOT NULL,
            `date` DATE NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`student_id`) REFERENCES `student_list` (`student_id`) ON DELETE CASCADE,
            FOREIGN KEY (`class_id`) REFERENCES `class_list` (`class_id`) ON DELETE CASCADE
        )";
        
        $this->db->exec($sql);
        $this->db->exec("CREATE INDEX IF NOT EXISTS `idx_attendance_marks_log_student_class` ON `attendance_marks_log` (`student_id`, `class_id`)");
    }
    
    private function addQuizListFields() {
        // Add semester_id column to quiz_list if it doesn't exist.
        $columns = $this->db->query("PRAGMA table_info(quiz_list)");
        $existing_columns = [];
        while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
            $existing_columns[] = $col['name'];
        }
        if (!in_array('semester_id', $existing_columns)) {
            $this->db->exec("ALTER TABLE `quiz_list` ADD COLUMN `semester_id` TEXT NOT NULL DEFAULT ''");
            echo "Added column 'semester_id' to quiz_list table.\n";
        }
    }

    private function addClassFields() {
        $columns = $this->db->query("PRAGMA table_info(class_list)");
        $existing_columns = [];
        while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
            $existing_columns[] = $col['name'];
        }
        
        $new_columns = [
            'course_no' => "ALTER TABLE `class_list` ADD COLUMN `course_no` TEXT DEFAULT ''",
            'semester' => "ALTER TABLE `class_list` ADD COLUMN `semester` TEXT DEFAULT ''",
            'year' => "ALTER TABLE `class_list` ADD COLUMN `year` TEXT DEFAULT ''",
            'schedule' => "ALTER TABLE `class_list` ADD COLUMN `schedule` TEXT DEFAULT ''",
            'unit' => "ALTER TABLE `class_list` ADD COLUMN `unit` INTEGER DEFAULT 0"
        ];
        
        foreach ($new_columns as $col_name => $sql) {
            if (!in_array($col_name, $existing_columns)) {
                $this->db->exec($sql);
                echo 'Added column \'' . $col_name . '\' to class_list table.' . "\n";
            }
        }
    }
}
// Run migrations if this file is executed directly
if (php_sapi_name() === 'cli') {
    $db = new DBConnection();
    $migration = new Migration($db);
    $migration->runMigrations();
}
?>
