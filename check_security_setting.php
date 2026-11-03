<?php
require_once('DBConnection.php');

try {
    $result = $conn->query('SELECT * FROM security_settings ORDER BY id DESC LIMIT 1');
    $setting = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($setting) {
        echo "Current security setting: " . ($setting['security_enabled'] ? 'ENABLED' : 'DISABLED') . "<br>";
        echo "Last updated: " . $setting['updated_at'] . "<br>";
        echo "Setting value: " . $setting['security_enabled'] . "<br>";
    } else {
        echo "No security settings found<br>";
    }
    
    // Test updating the setting
    echo "<br>Testing update to DISABLED...<br>";
    $update = $conn->exec("UPDATE security_settings SET security_enabled = 0, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
    
    if ($update) {
        echo "✅ Update successful<br>";
        
        // Check again
        $result = $conn->query('SELECT * FROM security_settings ORDER BY id DESC LIMIT 1');
        $setting = $result->fetchArray(SQLITE3_ASSOC);
        echo "New security setting: " . ($setting['security_enabled'] ? 'ENABLED' : 'DISABLED') . "<br>";
        echo "New updated_at: " . $setting['updated_at'] . "<br>";
    } else {
        echo "❌ Update failed<br>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
