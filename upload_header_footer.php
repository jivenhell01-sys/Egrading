<?php
require_once("DBConnection.php");

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => ''];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

// Get POST data
$element_type = $_POST['element_type'] ?? '';
$position = $_POST['position'] ?? '';

// Validate input
if (!in_array($element_type, ['header', 'footer']) || !in_array($position, ['left', 'center', 'right'])) {
    $response['message'] = 'Invalid element type or position';
    echo json_encode($response);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $response['message'] = 'No file uploaded or error uploading file';
    echo json_encode($response);
    exit;
}

$file = $_FILES['image'];
$upload_dir = 'uploads/';

// Create directory if it doesn't exist
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        $response['message'] = 'Failed to create upload directory';
        echo json_encode($response);
        exit;
    }
}

// Generate a unique filename
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

if (!in_array($file_ext, $allowed_ext)) {
    $response['message'] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
    echo json_encode($response);
    exit;
}

$filename = $position . '_' . uniqid() . '.' . $file_ext;
$filepath = $upload_dir . $filename;

// Move the uploaded file
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    // Process image to make background transparent
    $processed_path = makeBackgroundTransparent($filepath);
    if ($processed_path && $processed_path !== $filepath) {
        // Update the filepath to the processed image
        $filepath = $processed_path;
    }
    
    // Save to database
    $filepath = str_replace('\\', '/', $filepath); // Ensure consistent path separators
    
    // Check if entry exists
    $stmt = $conn->prepare("SELECT id FROM header_footer_settings WHERE element_type = ? AND position = ?");
    $stmt->bindValue(1, $element_type, SQLITE3_TEXT);
    $stmt->bindValue(2, $position, SQLITE3_TEXT);
    $result = $stmt->execute();
    $existing = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        // Update existing
        $stmt = $conn->prepare("UPDATE header_footer_settings SET image_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bindValue(1, $filepath, SQLITE3_TEXT);
        $stmt->bindValue(2, $existing['id'], SQLITE3_INTEGER);
    } else {
        // Insert new
        $stmt = $conn->prepare("INSERT INTO header_footer_settings (element_type, position, image_path) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $element_type, SQLITE3_TEXT);
        $stmt->bindValue(2, $position, SQLITE3_TEXT);
        $stmt->bindValue(3, $filepath, SQLITE3_TEXT);
    }
    
    if ($stmt->execute()) {
        $response['status'] = 'success';
        $response['message'] = 'File uploaded successfully';
        $response['filepath'] = $filepath;
    } else {
        $response['message'] = 'Failed to save to database';
        // Clean up the uploaded file if database save failed
        @unlink($filepath);
    }
} else {
    $response['message'] = 'Failed to move uploaded file';
}

echo json_encode($response);
?>

<?php
/**
 * Process image to make background transparent and resize to 4K
 * @param string $image_path Path to the uploaded image
 * @return string|false Path to processed image or false on failure
 */
function makeBackgroundTransparent($image_path) {
    try {
        // Get image info
        $image_info = getimagesize($image_path);
        if (!$image_info) {
            return false;
        }
        
        $mime_type = $image_info['mime'];
        $original_width = $image_info[0];
        $original_height = $image_info[1];
        
        // Create image resource based on mime type
        switch ($mime_type) {
            case 'image/jpeg':
                $source_image = imagecreatefromjpeg($image_path);
                break;
            case 'image/jpg':
                $source_image = imagecreatefromjpeg($image_path);
                break;
            case 'image/png':
                $source_image = imagecreatefrompng($image_path);
                break;
            case 'image/gif':
                $source_image = imagecreatefromgif($image_path);
                break;
            default:
                return false; // Unsupported format
        }
        
        if (!$source_image) {
            return false;
        }
        
        // Calculate 4K dimensions (3840x2160) while maintaining aspect ratio
        $target_width = 3840;
        $target_height = 2160;
        
        // Calculate scaling to fit within 4K while maintaining aspect ratio
        $scale_w = $target_width / $original_width;
        $scale_h = $target_height / $original_height;
        $scale = min($scale_w, $scale_h);
        
        // If image is smaller than 4K, we can upscale it with high quality
        // If it's larger, we downscale it to fit within 4K
        $new_width = (int)($original_width * $scale);
        $new_height = (int)($original_height * $scale);
        
        // Create a new true color image with transparency
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        // Enable alpha blending and save alpha
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        
        // Fill with transparent background
        $transparent_color = imagecolorallocatealpha($new_image, 0, 0, 0, 127);
        imagefill($new_image, 0, 0, $transparent_color);
        
        // Enable alpha blending for copying
        imagealphablending($new_image, true);
        
        // High-quality resize and copy
        imagecopyresampled($new_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);
        
        // Convert background to transparent
        // This is a simple approach - we'll make white/light colors transparent
        // For better results, you might want to use more sophisticated algorithms
        $threshold = 240; // Threshold for "white/light" colors (0-255)
        
        for ($x = 0; $x < $new_width; $x++) {
            for ($y = 0; $y < $new_height; $y++) {
                $rgb = imagecolorat($new_image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Check if pixel is "white/light" enough
                if ($r > $threshold && $g > $threshold && $b > $threshold) {
                    // Make this pixel transparent
                    $alpha_color = imagecolorallocatealpha($new_image, $r, $g, $b, 127);
                    imagesetpixel($new_image, $x, $y, $alpha_color);
                }
            }
        }
        
        // Generate new filename for processed image
        $path_info = pathinfo($image_path);
        $new_filename = $path_info['filename'] . '_4k_transparent.png';
        $new_path = $path_info['dirname'] . '/' . $new_filename;
        
        // Save as PNG with maximum quality to preserve 4K resolution
        // Use compression level 0 for best quality (larger file size)
        $result = imagepng($new_image, $new_path, 0);
        
        // Clean up memory
        imagedestroy($source_image);
        imagedestroy($new_image);
        
        if ($result) {
            // Delete original file and return new path
            unlink($image_path);
            return $new_path;
        } else {
            return false;
        }
        
    } catch (Exception $e) {
        // If anything goes wrong, return the original path
        return $image_path;
    }
}
?>
