<?php
// Start output buffering
ob_start();

// Set error reporting for development
ini_set('display_errors', 0);
error_reporting(0);

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/upload_errors.log');

// Log the start of the script
error_log("=== Starting header/footer save process ===");

// Include database connection
require_once('DBConnection.php');

// Initialize response
$response = ['status' => 'error', 'message' => ''];

// Log the POST and FILES data
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

try {
    // Check if this is a header/footer settings save request
    if (!isset($_POST['save_header_footer_settings'])) {
        throw new Exception('Invalid request');
    }

    // Get form data
    $elementType = $_POST['element_type'] ?? '';
    $position = $_POST['position'] ?? '';
    $imagePath = '';
    
    // Validate element type
    if (!in_array($elementType, ['header', 'footer'])) {
        throw new Exception('Invalid element type');
    }
    
    // Validate position
    if (!in_array($position, ['left', 'center', 'right'])) {
        throw new Exception('Invalid position');
    }
    
    // Handle file upload if present
    if (isset($_FILES['header_footer_image'])) {
        error_log("File upload detected. Error code: " . $_FILES['header_footer_image']['error']);
        error_log("File info: " . print_r($_FILES['header_footer_image'], true));
    }
    
    if (isset($_FILES['header_footer_image']) && $_FILES['header_footer_image']['error'] === UPLOAD_ERR_OK) {
        // Define upload directory using absolute path - only use 'uploads' directory
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        error_log("Upload directory: " . $uploadDir);
        
        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new Exception('Failed to create upload directory');
            }
        }
        
        // Ensure the directory is writable
        if (!is_writable($uploadDir)) {
            throw new Exception('Upload directory is not writable. Current permissions: ' . substr(sprintf('%o', fileperms($uploadDir)), -4));
        }
        
        // Generate a unique filename with element type prefix
        $fileExt = strtolower(pathinfo($_FILES['header_footer_image']['name'], PATHINFO_EXTENSION));
        $fileName = $elementType . '_' . uniqid() . '.' . $fileExt;
        $targetPath = $uploadDir . $fileName;
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($_FILES['header_footer_image']['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception('Only JPG, PNG & GIF files are allowed. Found: ' . $fileType);
        }
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['header_footer_image']['tmp_name'], $targetPath)) {
            $error = error_get_last();
            throw new Exception('Error uploading file: ' . ($error['message'] ?? 'Unknown error'));
        }
        
        // Store relative path for web access
        $imagePath = 'uploads/' . $fileName;
    }
    
    // Create database connection
    $conn = new DBConnection();
    
    // Check if record exists
    $check = $conn->querySingle("SELECT id FROM header_footer_settings WHERE element_type = '" . $conn->escapeString($elementType) . "'");
    
    if ($check) {
        // Store relative path in database (web-accessible path)
        $relativeImagePath = !empty($fileName) ? 'uploads/' . $fileName : '';
        
        // Update existing record
        $sql = "UPDATE header_footer_settings SET position = '" . $conn->escapeString($position) . "'" . 
               (!empty($relativeImagePath) ? ", image_path = '" . $conn->escapeString($relativeImagePath) . "'" : "") . 
               " WHERE id = " . $check;
        $result = $conn->exec($sql);
        
        if ($result === false) {
            throw new Exception('Error updating settings: ' . $conn->lastErrorMsg());
        }
        
        // If we have a new image and there was an old one, delete the old image
        if (!empty($imagePath)) {
            $oldImage = $conn->querySingle("SELECT image_path FROM header_footer_settings WHERE id = " . $check);
            if ($oldImage) {
                // Convert relative path to absolute path for deletion
                $oldImagePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldImage);
                if (file_exists($oldImagePath)) {
                    @unlink($oldImagePath);
                    error_log("Deleted old image: " . $oldImagePath);
                } else {
                    error_log("Old image not found: " . $oldImagePath);
                }
            }
        }
    } else {
        // Store relative path in database (web-accessible path)
        $relativeImagePath = !empty($fileName) ? 'uploads/' . $fileName : '';
        
        // Insert new record
        $sql = "INSERT INTO header_footer_settings (element_type, position, image_path) VALUES (" .
               "'" . $conn->escapeString($elementType) . "', " .
               "'" . $conn->escapeString($position) . "', " .
               "'" . $conn->escapeString($relativeImagePath) . "')";
        $result = $conn->exec($sql);
        
        if ($result === false) {
            throw new Exception('Error saving settings: ' . $conn->lastErrorMsg());
        }
    }
    
    // Get the updated settings
    $settings = $conn->querySingle("SELECT * FROM header_footer_settings WHERE element_type = '" . $conn->escapeString($elementType) . "'", true);
    
    // Ensure the image path is a URL that can be accessed from the web
    $imageUrl = '';
    if (!empty($settings['image_path'])) {
        // If it's already a full URL, use as is
        if (strpos($settings['image_path'], 'http') === 0) {
            $imageUrl = $settings['image_path'];
        } else {
            // Get the base URL
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
            $host = $_SERVER['HTTP_HOST'];
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            
            // Build the full URL
            $imagePath = ltrim($settings['image_path'], '/\\');
            $imageUrl = "$protocol://$host$basePath/$imagePath";
            
            // Log the URL construction for debugging
            error_log("Constructed image URL: $imageUrl");
            error_log("Script name: " . $_SERVER['SCRIPT_NAME']);
            error_log("Document root: " . $_SERVER['DOCUMENT_ROOT']);
        }
    }
    
    // Build the response with debug information
    $debugInfo = [
        'original_path' => $settings['image_path'] ?? '',
        'server_path' => __DIR__ . DIRECTORY_SEPARATOR . ($settings['image_path'] ?? ''),
        'file_exists' => file_exists(__DIR__ . DIRECTORY_SEPARATOR . ($settings['image_path'] ?? '')) ? 'yes' : 'no',
        'web_root' => $_SERVER['DOCUMENT_ROOT'],
        'script_name' => $_SERVER['SCRIPT_NAME'],
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'base_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'])
    ];
    
    $response = [
        'status' => 'success',
        'message' => 'Settings saved successfully!',
        'settings' => [
            'position' => $settings['position'],
            'image_path' => $imageUrl,
            'debug' => $debugInfo
        ]
    ];
    
    // Log the response for debugging
    error_log('Response data: ' . print_r($response, true));
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// Log the final response before sending
error_log("Final response: " . print_r($response, true));

// Clear any previous output
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Log any remaining output buffer
if (ob_get_length() > 0) {
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("Unexpected output: " . $output);
    }
}

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Ensure the response is a valid JSON object
if (!isset($response['status'])) {
    $response = [
        'status' => 'error',
        'message' => 'Invalid response format',
        'original' => $response
    ];
}

// Encode the response with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
$jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Check for JSON encoding errors
if ($jsonResponse === false) {
    $response = [
        'status' => 'error',
        'message' => 'Error encoding response: ' . json_last_error_msg(),
        'original' => $response
    ];
    $jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// Output the response
if ($jsonResponse === false) {
    // Fallback to a simple error response if JSON encoding fails
    header('Content-Type: text/plain');
    echo '{"status":"error","message":"Failed to encode response"}';
} else {
    echo $jsonResponse;
}

exit;
