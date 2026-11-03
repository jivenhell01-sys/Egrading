<?php
require_once("DBConnection.php");

try {
    // Add image column to admin_list table
    $sql = "ALTER TABLE admin_list ADD COLUMN IF NOT EXISTS image TEXT";
    $conn->exec($sql);
    
    echo "Successfully added 'image' column to admin_list table.<br>";
    echo "<a href='manage_account.php'>Go to Account Management</a>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
