<?php
// Start output buffering to prevent any HTML output
ob_start();

// Enable error reporting for debugging
error_reporting(0);
ini_set('display_errors', 0); // Don't display errors, but log them

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Custom error handler to catch errors and return JSON
function handleError($errno, $errstr, $errfile, $errline) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "PHP Error: $errstr",
        "error_details" => [
            "errno" => $errno,
            "file" => $errfile,
            "line" => $errline
        ]
    ]);
    exit();
}

// Set error handler
set_error_handler('handleError');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

// Include database and classes
try {
    require_once 'DBConnection.php';
    require_once 'class.UploadedImages.php';
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to include required files: " . $e->getMessage()
    ]);
    exit();
}

// Get database connection
try {
    $conn = new DBConnection();
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit();
}

// Initialize uploaded images object
try {
    $uploaded_image = new UploadedImages($conn);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to initialize UploadedImages class: " . $e->getMessage()
    ]);
    exit();
}

// Get HTTP method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        // Handle image upload
        handleUpload($uploaded_image);
        break;
        
    case 'GET':
        // Handle read operations
        handleRead($uploaded_image);
        break;
        
    case 'PUT':
        // Handle update operations
        handleUpdate($uploaded_image);
        break;
        
    case 'DELETE':
        // Handle delete operations
        handleDelete($uploaded_image);
        break;
        
    default:
        ob_end_clean();
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        break;
}

/**
 * Handle image upload
 */
function handleUpload($uploaded_image) {
    try {
        // Check if file was uploaded
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "No file uploaded or upload error occurred",
                "error_code" => isset($_FILES['image']['error']) ? $_FILES['image']['error'] : 'no_file'
            ]);
            return;
        }
        
        // Get form data
        $image_type = $_POST['image_type'] ?? '';
        $image_position = $_POST['image_position'] ?? '';
        $image_width = (int)($_POST['image_width'] ?? 0);
        $image_height = (int)($_POST['image_height'] ?? 0);
        
        // Validate required fields
        if (empty($image_type) || empty($image_position)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Image type and position are required",
                "received" => [
                    "image_type" => $image_type,
                    "image_position" => $image_position
                ]
            ]);
            return;
        }
        
        // Validate image type and position
        $valid_types = ['header', 'footer'];
        $valid_positions = ['left', 'center', 'right'];
        
        if (!in_array($image_type, $valid_types) || !in_array($image_position, $valid_positions)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid image type or position",
                "valid_types" => $valid_types,
                "valid_positions" => $valid_positions
            ]);
            return;
        }
        
        // Validate uploaded image
        $validation_errors = UploadedImages::validateImage($_FILES['image']);
        if (!empty($validation_errors)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Validation failed",
                "errors" => $validation_errors
            ]);
            return;
        }
        
        // Ensure upload directory exists
        if (!UploadedImages::ensureUploadDir()) {
            ob_end_clean();
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to create upload directory"
            ]);
            return;
        }
        
        // Generate unique filename
        $unique_filename = UploadedImages::generateUniqueFilename($_FILES['image']['name']);
        $upload_path = UploadedImages::getUploadDir() . $unique_filename;
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
            ob_end_clean();
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to save uploaded file",
                "tmp_name" => $_FILES['image']['tmp_name'],
                "upload_path" => $upload_path
            ]);
            return;
        }
        
        // Set object properties
        $uploaded_image->image_type = $image_type;
        $uploaded_image->image_position = $image_position;
        $uploaded_image->image_path = $upload_path;
        $uploaded_image->image_width = $image_width;
        $uploaded_image->image_height = $image_height;
        
        // Get old image path before updating for cleanup
        $old_image = $uploaded_image->readActiveByTypeAndPosition($image_type, $image_position);
        $old_image_path = $old_image['image_path'] ?? null;
        
        // Create/update database record (upsert)
        if ($uploaded_image->create()) {
            // Clean up old image file if it was replaced
            if ($old_image_path && $old_image_path !== $upload_path && file_exists($old_image_path)) {
                unlink($old_image_path);
            }
            
            ob_end_clean();
            http_response_code(201);
            echo json_encode([
                "success" => true,
                "message" => "Image uploaded successfully",
                "data" => [
                    "id" => $uploaded_image->id,
                    "image_type" => $uploaded_image->image_type,
                    "image_position" => $uploaded_image->image_position,
                    "image_path" => $uploaded_image->image_path,
                    "image_width" => $uploaded_image->image_width,
                    "image_height" => $uploaded_image->image_height,
                    "created_at" => $uploaded_image->created_at,
                    "replaced" => $old_image_path ? true : false
                ]
            ]);
        } else {
            // Clean up uploaded file if database insert failed
            if (file_exists($upload_path)) {
                unlink($upload_path);
            }
            
            ob_end_clean();
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to save image record to database"
            ]);
        }
        
    } catch (Exception $e) {
        // Clean up uploaded file if it exists
        if (isset($upload_path) && file_exists($upload_path)) {
            unlink($upload_path);
        }
        
        ob_end_clean();
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Server error during upload: " . $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ]);
    }
}

/**
 * Handle read operations
 */
function handleRead($uploaded_image) {
    try {
        // Check if specific ID is requested
        if (isset($_GET['id'])) {
            $uploaded_image->id = (int)$_GET['id'];
            
            if ($uploaded_image->readOne()) {
                echo json_encode([
                    "success" => true,
                    "data" => [
                        "id" => $uploaded_image->id,
                        "image_type" => $uploaded_image->image_type,
                        "image_position" => $uploaded_image->image_position,
                        "image_path" => $uploaded_image->image_path,
                        "image_name" => $uploaded_image->image_name,
                        "file_size" => $uploaded_image->file_size,
                        "mime_type" => $uploaded_image->mime_type,
                        "created_at" => $uploaded_image->created_at,
                        "updated_at" => $uploaded_image->updated_at,
                        "created_by" => $uploaded_image->created_by,
                        "is_active" => $uploaded_image->is_active
                    ]
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    "success" => false,
                    "message" => "Image not found"
                ]);
            }
        }
        // Check if active images by type and position are requested
        elseif (isset($_GET['image_type']) && isset($_GET['image_position'])) {
            $image_type = $_GET['image_type'];
            $image_position = $_GET['image_position'];
            
            $result = $uploaded_image->readActiveByTypeAndPosition($image_type, $image_position);
            
            if ($result) {
                echo json_encode([
                    "success" => true,
                    "data" => $result
                ]);
            } else {
                echo json_encode([
                    "success" => true,
                    "data" => null,
                    "message" => "No active image found for this type and position"
                ]);
            }
        }
        // Get all active images
        elseif (isset($_GET['active']) && $_GET['active'] == 'true') {
            $result = $uploaded_image->readAllActive();
            
            echo json_encode([
                "success" => true,
                "data" => array_values($result)
            ]);
        }
        // Get all images
        else {
            $images = $uploaded_image->readAll();
            
            echo json_encode([
                "success" => true,
                "data" => $images
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Server error: " . $e->getMessage()
        ]);
    }
}

/**
 * Handle update operations
 */
function handleUpdate($uploaded_image) {
    try {
        // Get input data
        $data = json_decode(file_get_contents("php://input"));
        
        if (!isset($data->id)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Image ID is required"
            ]);
            return;
        }
        
        $uploaded_image->id = (int)$data->id;
        
        // Check if image exists
        if (!$uploaded_image->readOne()) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "Image not found"
            ]);
            return;
        }
        
        // Update properties if provided
        if (isset($data->image_type)) $uploaded_image->image_type = $data->image_type;
        if (isset($data->image_position)) $uploaded_image->image_position = $data->image_position;
        if (isset($data->is_active)) $uploaded_image->is_active = $data->is_active;
        
        if ($uploaded_image->update()) {
            echo json_encode([
                "success" => true,
                "message" => "Image updated successfully",
                "data" => [
                    "id" => $uploaded_image->id,
                    "image_type" => $uploaded_image->image_type,
                    "image_position" => $uploaded_image->image_position,
                    "is_active" => $uploaded_image->is_active,
                    "updated_at" => $uploaded_image->updated_at
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to update image"
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Server error: " . $e->getMessage()
        ]);
    }
}

/**
 * Handle delete operations
 */
function handleDelete($uploaded_image) {
    try {
        // Get image ID
        $uploaded_image->id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($uploaded_image->id <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Valid image ID is required"
            ]);
            return;
        }
        
        // Check if image exists
        if (!$uploaded_image->readOne()) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "Image not found"
            ]);
            return;
        }
        
        if ($uploaded_image->delete()) {
            echo json_encode([
                "success" => true,
                "message" => "Image deleted successfully"
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to delete image"
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Server error: " . $e->getMessage()
        ]);
    }
}
?>
