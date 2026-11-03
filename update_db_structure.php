<?php
require_once("DBConnection.php");

try {
    // Disable foreign keys temporarily
    $conn->exec("PRAGMA foreign_keys=off");
    
    // Check if the table exists and get its structure
    $tableInfo = $conn->querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name='admin_list'");
    
    if (strpos($tableInfo, '"image" TEXT NOT NULL') !== false) {
        // Table exists with NOT NULL constraint on image
        
        // Create a temporary table with the new structure
        $conn->exec("CREATE TABLE admin_list_new (
            `admin_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `fullname` TEXT NOT NULL,
            `username` TEXT NOT NULL,
            `password` TEXT NOT NULL,
            `image` TEXT DEFAULT NULL,
            `status` INTEGER NOT NULL DEFAULT 1,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Copy data from old table to new table
        $conn->exec("INSERT INTO admin_list_new (admin_id, fullname, username, password, status, date_created) 
                    SELECT admin_id, fullname, username, password, status, date_created FROM admin_list");
        
        // Drop the old table
        $conn->exec("DROP TABLE admin_list");
        
        // Rename new table to original name
        $conn->exec("ALTER TABLE admin_list_new RENAME TO admin_list");
        
        echo "Database structure updated successfully! The 'image' column is now nullable.<br>";
    } else {
        echo "No changes were needed. The 'image' column is already nullable or doesn't exist.<br>";
    }
    
    // Re-enable foreign keys
    $conn->exec("PRAGMA foreign_keys=on");
    
    echo "<a href='manage_account.php'>Go to Account Management</a>";
    
} catch (Exception $e) {
    // Re-enable foreign keys in case of error
    $conn->exec("PRAGMA foreign_keys=on");
    echo "Error: " . $e->getMessage();
}
?>
