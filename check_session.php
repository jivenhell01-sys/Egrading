<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in and get session data

// Configure session for ngrok compatibility
if (session_status() === PHP_SESSION_NONE) {
    // Allow cross-origin requests from ngrok
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Credentials: true");
    
    // Set session cookie parameters for cross-domain access
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // Allow all domains
        'secure' => false, // Allow HTTP (ngrok)
        'httponly' => true,
        'samesite' => 'None' // Allow cross-site requests
    ]);
    session_start();
}

// Set content type to JSON
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Check if user is logged in and get session data
if (isset($_SESSION['admin_id'])) {
    // Create a copy of session data without sensitive information
    $sessionData = $_SESSION;
    
    // Remove any sensitive data before output
    unset($sessionData['password']);
    
    $response = [
        'status' => 'success',
        'admin_id' => $_SESSION['admin_id'],
        'username' => $_SESSION['username'] ?? 'Not set',
        'fullname' => $_SESSION['fullname'] ?? 'Not set',
        'role' => $_SESSION['role'] ?? 'Not set',
        'session_data' => $sessionData
    ];
} else {
    $response = [
        'status' => 'not_logged_in',
        'message' => 'No user is currently logged in.',
        'session_id' => session_id(),
        'session_status' => session_status(),
        'cookies_received' => $_COOKIE
    ];
}

// Output the response as JSON
echo json_encode($response, JSON_PRETTY_PRINT);
?>
