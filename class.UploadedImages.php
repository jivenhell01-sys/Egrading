<?php
/**
 * UploadedImages Class
 * Handles CRUD operations for uploaded images (headers and footers)
 * Works with SQLite database and header_footer_settings table
 */
class UploadedImages {
    private $conn;
    private $table_name = "header_footer_settings";
    
    // Object properties
    public $id;
    public $image_type; // header or footer
    public $image_position; // left, center, or right
    public $image_path;
    public $image_width;
    public $image_height;
    public $image_name;
    public $file_size;
    public $mime_type;
    public $created_at;
    public $updated_at;
    public $is_active;
    
    // Constructor with DB connection
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create or update uploaded image record (upsert)
     * @return boolean
     */
    public function create() {
        // Sanitize inputs
        $this->image_type = htmlspecialchars(strip_tags($this->image_type));
        $this->image_position = htmlspecialchars(strip_tags($this->image_position));
        $this->image_path = htmlspecialchars(strip_tags($this->image_path));
        $this->image_width = (int)$this->image_width;
        $this->image_height = (int)$this->image_height;
        
        // Check if there's already an active image for this type and position
        $this->deactivateExistingImages();
        
        // Try to update existing record first
        $query = "UPDATE " . $this->table_name . "
                SET image_path = :image_path,
                    image_width = :image_width,
                    image_height = :image_height,
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE element_type = :image_type 
                AND position = :image_position";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind values
        $stmt->bindValue(":image_type", $this->image_type, SQLITE3_TEXT);
        $stmt->bindValue(":image_position", $this->image_position, SQLITE3_TEXT);
        $stmt->bindValue(":image_path", $this->image_path, SQLITE3_TEXT);
        $stmt->bindValue(":image_width", $this->image_width, SQLITE3_INTEGER);
        $stmt->bindValue(":image_height", $this->image_height, SQLITE3_INTEGER);
        
        // Execute update
        $result = $stmt->execute();
        $changes = $this->conn->changes();
        
        // If no rows were updated, insert a new record
        if ($changes == 0) {
            $query = "INSERT INTO " . $this->table_name . "
                    (element_type, position, image_path, image_width, image_height, is_active)
                    VALUES (:image_type, :image_position, :image_path, :image_width, :image_height, 1)";
            
            $stmt = $this->conn->prepare($query);
            
            // Bind values
            $stmt->bindValue(":image_type", $this->image_type, SQLITE3_TEXT);
            $stmt->bindValue(":image_position", $this->image_position, SQLITE3_TEXT);
            $stmt->bindValue(":image_path", $this->image_path, SQLITE3_TEXT);
            $stmt->bindValue(":image_width", $this->image_width, SQLITE3_INTEGER);
            $stmt->bindValue(":image_height", $this->image_height, SQLITE3_INTEGER);
            
            // Execute insert
            $result = $stmt->execute();
            
            if ($result) {
                $this->id = $this->conn->lastInsertRowID();
                return true;
            }
        } else {
            // Update was successful, get the ID of the updated record
            $query = "SELECT id FROM " . $this->table_name . "
                    WHERE element_type = :image_type 
                    AND position = :image_position 
                    AND is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":image_type", $this->image_type, SQLITE3_TEXT);
            $stmt->bindValue(":image_position", $this->image_position, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($row) {
                $this->id = $row['id'];
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Update uploaded image record
     * @return boolean
     */
    public function update() {
        // First read existing data to preserve other fields
        if (!$this->readOne()) {
            return false;
        }
        
        // Sanitize inputs (only update what's provided)
        if ($this->image_width !== null) {
            $this->image_width = (int)$this->image_width;
        }
        if ($this->image_height !== null) {
            $this->image_height = (int)$this->image_height;
        }
        
        // Update query for SQLite - update only dimensions and timestamp
        $query = "UPDATE " . $this->table_name . "
                SET image_width = :image_width,
                    image_height = :image_height,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind values
        $stmt->bindValue(":id", $this->id, SQLITE3_INTEGER);
        $stmt->bindValue(":image_width", $this->image_width, SQLITE3_INTEGER);
        $stmt->bindValue(":image_height", $this->image_height, SQLITE3_INTEGER);
        
        // Execute query
        return $stmt->execute();
    }
    
    /**
     * Read all uploaded images
     * @return array
     */
    public function readAll() {
        $query = "SELECT id, element_type as image_type, position as image_position, image_path, 
                        image_width, image_height, created_at, updated_at, is_active
                 FROM " . $this->table_name . "
                 ORDER BY created_at DESC";
        
        $result = $this->conn->query($query);
        $images = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $images[] = $row;
        }
        
        return $images;
    }
    
    /**
     * Read active images by type and position
     * @param string $image_type
     * @param string $image_position
     * @return array|null
     */
    public function readActiveByTypeAndPosition($image_type, $image_position) {
        $query = "SELECT id, element_type as image_type, position as image_position, image_path, 
                        image_width, image_height, created_at
                 FROM " . $this->table_name . "
                 WHERE element_type = :image_type 
                 AND position = :image_position 
                 AND is_active = 1
                 ORDER BY created_at DESC
                 LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":image_type", $image_type, SQLITE3_TEXT);
        $stmt->bindValue(":image_position", $image_position, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row ? $row : null;
    }
    
    /**
     * Read all active images grouped by type and position
     * @return array
     */
    public function readAllActive() {
        $query = "SELECT id, element_type as image_type, position as image_position, image_path, 
                        image_width, image_height, created_at
                 FROM " . $this->table_name . "
                 WHERE is_active = 1
                 ORDER BY element_type, position, created_at DESC";
        
        $result = $this->conn->query($query);
        $images = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $key = $row['image_type'] . '_' . $row['image_position'];
            if (!isset($images[$key])) {
                $images[$key] = $row;
            }
        }
        
        return $images;
    }
    
    /**
     * Read single image by ID
     * @return boolean
     */
    public function readOne() {
        $query = "SELECT id, element_type as image_type, position as image_position, image_path, 
                        image_width, image_height, created_at, updated_at, is_active
                 FROM " . $this->table_name . "
                 WHERE id = :id
                 LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if($row) {
            // Set properties
            $this->image_type = $row['image_type'];
            $this->image_position = $row['image_position'];
            $this->image_path = $row['image_path'];
            $this->image_width = $row['image_width'];
            $this->image_height = $row['image_height'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            $this->is_active = $row['is_active'];
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Delete uploaded image record
     * @return boolean
     */
    public function delete() {
        // First, get the image path to delete the file
        $this->readOne();
        $image_path = $this->image_path;
        
        // Delete from database
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->id, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        
        if($result) {
            // Delete physical file
            if($image_path && file_exists($image_path)) {
                unlink($image_path);
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Deactivate existing images for the same type and position
     * @return boolean
     */
    private function deactivateExistingImages() {
        $query = "UPDATE " . $this->table_name . "
                SET is_active = 0
                WHERE element_type = :image_type 
                AND position = :image_position 
                AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":image_type", $this->image_type, SQLITE3_TEXT);
        $stmt->bindValue(":image_position", $this->image_position, SQLITE3_TEXT);
        
        return $stmt->execute();
    }
    
    /**
     * Toggle active status
     * @return boolean
     */
    public function toggleActive() {
        $query = "UPDATE " . $this->table_name . "
                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->id, SQLITE3_INTEGER);
        
        return $stmt->execute();
    }
    
    /**
     * Get upload directory path
     * @return string
     */
    public static function getUploadDir() {
        return __DIR__ . '/uploads/images/';
    }
    
    /**
     * Create upload directory if it doesn't exist
     * @return boolean
     */
    public static function ensureUploadDir() {
        $upload_dir = self::getUploadDir();
        if (!file_exists($upload_dir)) {
            return mkdir($upload_dir, 0755, true);
        }
        return true;
    }
    
    /**
     * Generate unique filename
     * @param string $original_name
     * @return string
     */
    public static function generateUniqueFilename($original_name) {
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $basename = pathinfo($original_name, PATHINFO_FILENAME);
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return $basename . '_' . $timestamp . '_' . $random . '.' . $extension;
    }
    
    /**
     * Validate image file
     * @param array $file
     * @return array
     */
    public static function validateImage($file) {
        $errors = [];
        
        // Check if file is actually uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'No file uploaded or upload error occurred.';
            return $errors;
        }
        
        // Check file size (5MB max)
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            $errors[] = 'File size must be less than 5MB.';
        }
        
        // Check file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            $errors[] = 'Only JPG, PNG, and GIF images are allowed.';
        }
        
        // Check if it's actually an image
        if (!getimagesize($file['tmp_name'])) {
            $errors[] = 'Uploaded file is not a valid image.';
        }
        
        return $errors;
    }
}
