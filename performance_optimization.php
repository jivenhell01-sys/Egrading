<?php
// Performance monitoring and optimization script
$start_time = microtime(true);

// Enable output buffering with compression
if (!ob_start("ob_gzhandler")) {
    ob_start();
}

// Set performance headers
header("X-Content-Type-Options: nosniff");
header("Cache-Control: public, max-age=3600, must-revalidate");
header("Connection: keep-alive");

// Database query optimization
function optimized_query($query, $params = []) {
    global $conn;
    
    static $query_cache = [];
    $cache_key = md5($query . serialize($params));
    
    // Cache SELECT queries for 60 seconds
    if (preg_match('/^\s*SELECT/i', $query) && isset($query_cache[$cache_key])) {
        $cached = $query_cache[$cache_key];
        if (time() - $cached['time'] < 60) {
            return $cached['result'];
        }
    }
    
    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Query preparation failed");
        }
        
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Cache successful SELECT queries
        if (preg_match('/^\s*SELECT/i', $query)) {
            $query_cache[$cache_key] = [
                'result' => $result,
                'time' => time()
            ];
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Session optimization
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Performance monitoring
function log_performance($script_name) {
    global $start_time;
    $execution_time = microtime(true) - $start_time;
    $memory_usage = memory_get_peak_usage(true) / 1024 / 1024; // MB
    
    error_log(sprintf(
        "Performance: %s - Time: %.4fs - Memory: %.2fMB",
        $script_name,
        $execution_time,
        $memory_usage
    ));
    
    // Add performance header for debugging
    header("X-Performance-Time: " . round($execution_time, 4));
    header("X-Performance-Memory: " . round($memory_usage, 2));
}

// Auto-cleanup old cache entries
function cleanup_cache() {
    $cache_dir = __DIR__ . '/cache';
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 3600) { // 1 hour
                unlink($file);
            }
        }
    }
}

// Register cleanup function
register_shutdown_function('cleanup_cache');
register_shutdown_function(function() {
    log_performance($_SERVER['SCRIPT_NAME']);
});

?>
