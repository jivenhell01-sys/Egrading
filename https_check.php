<?php
// HTTPS Security Configuration Checker
// This script helps verify HTTPS setup and security headers

function check_https_security() {
    $results = [];
    
    // Check if HTTPS is active
    $results['https_active'] = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $results['https_port'] = $_SERVER['SERVER_PORT'] == '443';
    $results['https_forwarded'] = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
    
    // Check security headers
    $headers = getallheaders();
    $results['security_headers'] = [
        'hsts' => isset($headers['Strict-Transport-Security']),
        'csp' => isset($headers['Content-Security-Policy']),
        'x_content_type' => isset($headers['X-Content-Type-Options']),
        'x_frame_options' => isset($headers['X-Frame-Options']),
        'x_xss_protection' => isset($headers['X-XSS-Protection'])
    ];
    
    // Check SSL certificate info (if available)
    if (function_exists('stream_context_get_params') && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        $context = stream_context_get_params(stream_context_get_default());
        if (isset($context['options']['ssl']['peer_certificate'])) {
            $results['ssl_cert'] = 'Certificate available';
        }
    }
    
    return $results;
}

// Output results as JSON for AJAX requests
if (isset($_GET['check_https'])) {
    header('Content-Type: application/json');
    echo json_encode(check_https_security());
    exit;
}

// Display results for direct access
?>
<!DOCTYPE html>
<html>
<head>
    <title>HTTPS Security Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .status { padding: 10px; margin: 5px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <h1>HTTPS Security Configuration Check</h1>
    
    <?php
    $check = check_https_security();
    
    echo "<h2>HTTPS Status</h2>";
    echo "<div class='status " . ($check['https_active'] ? 'success' : 'error') . "'>";
    echo "HTTPS Active: " . ($check['https_active'] ? 'YES' : 'NO');
    echo "</div>";
    
    echo "<div class='status " . ($check['https_port'] ? 'success' : 'warning') . "'>";
    echo "HTTPS Port (443): " . ($check['https_port'] ? 'YES' : 'NO');
    echo "</div>";
    
    echo "<div class='status " . ($check['https_forwarded'] ? 'success' : 'warning') . "'>";
    echo "HTTPS Forwarded Header: " . ($check['https_forwarded'] ? 'YES' : 'NO');
    echo "</div>";
    
    echo "<h2>Security Headers</h2>";
    foreach ($check['security_headers'] as $header => $present) {
        $status = $present ? 'success' : 'warning';
        echo "<div class='status $status'>";
        echo ucfirst(str_replace('_', ' ', $header)) . ": " . ($present ? 'PRESENT' : 'MISSING');
        echo "</div>";
    }
    
    if (isset($check['ssl_cert'])) {
        echo "<h2>SSL Certificate</h2>";
        echo "<div class='status success'>{$check['ssl_cert']}</div>";
    }
    ?>
    
    <h2>Recommendations</h2>
    <ul>
        <li>Ensure SSL certificate is properly installed</li>
        <li>Configure HSTS for long-term HTTPS enforcement</li>
        <li>Set up Content Security Policy to prevent mixed content</li>
        <li>Test HTTPS redirect from HTTP URLs</li>
        <li>Verify all resources load over HTTPS</li>
    </ul>
</body>
</html>
