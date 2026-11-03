<?php
/**
 * Landing page database helper.
 *
 * Public student self-registration (landingpage.php and its helper
 * endpoints) keeps its own database instead of writing into any
 * faculty/admin user database. No faculty or class selection involved.
 */

if (!function_exists('landing_db')) {
    function landing_db() {
        $path = __DIR__ . '/db/landing_db.db';
        $db = new SQLite3($path);
        $db->enableExceptions(true);
        $db->exec("PRAGMA busy_timeout = 5000");
        $db->exec("PRAGMA journal_mode = WAL");
        $db->exec("CREATE TABLE IF NOT EXISTS `landing_registrations` (
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
            `civil_status` TEXT,
            `family_status` TEXT,
            `special_sector` TEXT,
            `contact_number` TEXT,
            `email` TEXT,
            `barangay` TEXT,
            `municipality` TEXT,
            `province` TEXT,
            `zipcode` TEXT,
            `device_id` TEXT,
            `device_name` TEXT,
            `status` TEXT NOT NULL DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // Migrate older landing databases missing newer columns
        $existing = [];
        $col_qry = $db->query("PRAGMA table_info('landing_registrations')");
        while ($col_row = $col_qry->fetchArray(SQLITE3_ASSOC)) {
            $existing[] = $col_row['name'];
        }
        foreach (['name_extension' => 'TEXT', 'course' => 'TEXT', 'year' => 'TEXT', 'section' => 'TEXT', 'is_irregular' => 'INTEGER DEFAULT 0', 'irregular_subjects' => 'TEXT', 'civil_status' => 'TEXT', 'family_status' => 'TEXT', 'special_sector' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $existing)) {
                @$db->exec("ALTER TABLE `landing_registrations` ADD COLUMN `$col` $type");
            }
        }
        return $db;
    }
}
?>
