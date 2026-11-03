<?php



require_once 'DBConnection.php';

// Get database connection
$conn = new DBConnection();

// Initialize uploaded images object
require_once 'class.UploadedImages.php';
$uploaded_image = new UploadedImages($conn);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_dimensions') {
        $id = $_POST['id'] ?? '';
        $width = $_POST['width'] ?? '';
        $height = $_POST['height'] ?? '';
        
        // Validate input
        if (empty($id) || !is_numeric($id) || empty($width) || empty($height) || !is_numeric($width) || !is_numeric($height)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid input data']);
            exit;
        }
        
        // Update dimensions in database
        try {
            $stmt = $conn->prepare("UPDATE header_footer_settings SET image_width = ?, image_height = ? WHERE id = ?");
            $stmt->bindValue(1, $width, SQLITE3_INTEGER);
            $stmt->bindValue(2, $height, SQLITE3_INTEGER);
            $stmt->bindValue(3, $id, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Dimensions updated successfully']);
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Handle GET actions (toggle status, delete)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action === 'toggle') {
        // Toggle active status
        try {
            $stmt = $conn->prepare("UPDATE header_footer_settings SET is_active = NOT is_active WHERE id = ?");
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success_msg'] = 'Image status updated successfully';
        } catch (Exception $e) {
            $_SESSION['error_msg'] = 'Failed to update image status';
        }
        header('Location: ?page=manage_images');
        exit;
    } elseif ($action === 'delete') {
        // Delete image
        try {
            // Validate ID
            if (!is_numeric($id) || $id <= 0) {
                $_SESSION['error_msg'] = 'Invalid image ID';
               header('Location: ?page=manage_images');
                exit;
            }
            
            // Get image path before deletion
            $stmt = $conn->prepare("SELECT image_path FROM header_footer_settings WHERE id = ?");
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $image = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
            
            if (!$image) {
                $_SESSION['error_msg'] = 'Image not found in database';
                header('Location: ?page=manage_images');
                exit;
            }
            
            // Delete file if it exists
            if ($image['image_path'] && file_exists($image['image_path'])) {
                if (!unlink($image['image_path'])) {
                    $_SESSION['error_msg'] = 'Failed to delete image file, but database record will be removed';
                }
            }
            
            // Delete from database
            $stmt = $conn->prepare("DELETE FROM header_footer_settings WHERE id = ?");
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success_msg'] = 'Image deleted successfully';
        } catch (Exception $e) {
            $_SESSION['error_msg'] = 'Error: ' . $e->getMessage();
        }
        header('Location: ?page=manage_images');
        exit;
    }
}

// Get all images
$images = $uploaded_image->readAll();

// Get active images grouped by type and position
$active_images = $uploaded_image->readAllActive();

// Get first available class for preview
$preview_class = null;
$classes_query = $conn->query("SELECT class_id FROM class_list ORDER BY class_id ASC LIMIT 1");
if ($classes_query) {
    $first_class = $classes_query->fetchArray(SQLITE3_ASSOC);
    $preview_class_id = $first_class['class_id'] ?? 1;
} else {
    $preview_class_id = 1; // Fallback
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Uploaded Images</title>
    <link href="./css/bootstrap.min.css" rel="stylesheet">
    <link href="./Font-Awesome-master/css/all.min.css" rel="stylesheet">
    <style>
        .image-preview {
            max-width: 100px;
            max-height: 100px;
            object-fit: cover;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .status-badge {
            font-size: 0.8em;
        }
        .image-card {
            transition: transform 0.2s;
        }
        .image-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        /* Dark mode - follows the Horizon theme (html[data-theme="dark"]) */
        html[data-theme="dark"] #image-manager .card {
            background-color: var(--horizon-card-bg);
            border-color: var(--horizon-card-border);
            color: var(--horizon-text-title);
        }
        html[data-theme="dark"] #image-manager .card-header {
            background-color: rgba(255,255,255,0.03);
            border-color: var(--horizon-card-border);
            color: var(--horizon-text-title);
        }
        html[data-theme="dark"] #image-manager h2,
        html[data-theme="dark"] #image-manager h4,
        html[data-theme="dark"] #image-manager h5,
        html[data-theme="dark"] #image-manager h6,
        html[data-theme="dark"] #image-manager .card-title {
            color: var(--horizon-text-title);
        }
        html[data-theme="dark"] #image-manager .text-muted,
        html[data-theme="dark"] #image-manager .form-text {
            color: var(--horizon-text-muted) !important;
        }
        html[data-theme="dark"] #image-manager .table {
            --bs-table-bg: transparent;
            --bs-table-color: #e2e8f0;
            --bs-table-border-color: #2b3850;
            --bs-table-striped-bg: rgba(255,255,255,0.03);
            --bs-table-striped-color: #e2e8f0;
            --bs-table-hover-bg: rgba(255,255,255,0.06);
            --bs-table-hover-color: #f1f5ff;
            color: #e2e8f0;
            border-color: #2b3850;
        }
        html[data-theme="dark"] #image-manager .table thead.table-dark {
            --bs-table-bg: #0f172a;
            --bs-table-color: #f1f5ff;
            --bs-table-border-color: #2b3850;
        }
        html[data-theme="dark"] #image-manager .form-control,
        html[data-theme="dark"] #image-manager .form-select {
            background-color: var(--horizon-input-bg);
            border-color: #2b3850;
            color: #f1f5ff;
        }
        html[data-theme="dark"] #image-manager .form-control::placeholder {
            color: var(--horizon-text-muted);
        }
        html[data-theme="dark"] #image-manager .input-group-text {
            background-color: #202d43;
            border-color: #2b3850;
            color: #cbd5e1;
        }
        html[data-theme="dark"] #image-manager .bg-light {
            background-color: #202d43 !important;
            color: #cbd5e1 !important;
        }
        html[data-theme="dark"] #image-manager .image-preview,
        html[data-theme="dark"] #image-manager .img-thumbnail {
            border-color: #2b3850;
            background-color: #202d43;
        }
        html[data-theme="dark"] #image-manager .alert-info {
            background-color: #1e3a5f;
            border-color: #2b4a73;
            color: #bfdbfe;
        }
        html[data-theme="dark"] #image-manager .preview-iframe-container {
            border-color: #2b3850 !important;
            background-color: #ffffff;
        }
        html[data-theme="dark"] #image-manager .nav-tabs .nav-link {
            color: var(--horizon-text-muted);
        }
        html[data-theme="dark"] #image-manager .nav-tabs .nav-link.active {
            background-color: var(--horizon-card-bg);
            border-color: #2b3850 #2b3850 var(--horizon-card-bg);
            color: var(--horizon-text-title);
        }
        html[data-theme="dark"] #image-manager .modal-content {
            background-color: var(--horizon-card-bg);
            border-color: var(--horizon-card-border);
            color: var(--horizon-text-title);
        }
        html[data-theme="dark"] #image-manager .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4" id="image-manager">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <a href="javascript:history.back()" class="btn btn-outline-secondary me-3">
                            <i class="fa fa-arrow-left"></i> Go Back
                        </a>
                        <h2 class="mb-0"><i class="fa fa-images"></i> Manage Uploaded Images</h2>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_msg'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Upload Image Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fa fa-upload"></i> Upload Header / Footer Image</h5>
                            </div>
                            <div class="card-body">
                                <form id="uploadImageForm" enctype="multipart/form-data">
                                    <div class="row g-3 align-items-end">
                                        <div class="col-md-3">
                                            <label for="uploadType" class="form-label">Type</label>
                                            <select class="form-select" id="uploadType" name="element_type" required>
                                                <option value="header">Header</option>
                                                <option value="footer">Footer</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="uploadPosition" class="form-label">Position</label>
                                            <select class="form-select" id="uploadPosition" name="position" required>
                                                <option value="left">Left</option>
                                                <option value="center">Center</option>
                                                <option value="right">Right</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="uploadImageFile" class="form-label">Image</label>
                                            <input type="file" class="form-control" id="uploadImageFile" name="image" accept="image/*" required>
                                            <div class="form-text">JPG, PNG &amp; GIF only. Recommended: 150x50px headers, 200x80px footers.</div>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-primary w-100" id="uploadImageBtn">
                                                <i class="fa fa-upload"></i> Upload
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mt-2" id="uploadImagePreview"></div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Images Summary -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="mb-3">Active Images</h4>
                        <div class="row">
                            <?php if (empty($active_images)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fa fa-info-circle"></i> No active images found. Use the upload form above to add images.
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($active_images as $key => $image): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="card image-card">
                                            <div class="card-body">
                                                <h6 class="card-title">
                                                    <?php echo ucfirst($image['image_type']); ?> - <?php echo ucfirst($image['image_position']); ?>
                                                </h6>
                                                <?php if ($image['image_path'] && file_exists($image['image_path'])): ?>
                                                    <?php 
                                                    // Convert absolute path to relative URL
                                                    $relative_path = str_replace(__DIR__, '', $image['image_path']);
                                                    $relative_path = ltrim($relative_path, '/\\');
                                                    $web_url = $relative_path;
                                                    ?>
                                                    <img src="<?php echo htmlspecialchars($web_url); ?>" class="image-preview mb-2" alt="Preview" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                                    <div style="display:none;" class="bg-light p-3 text-center mb-2">
                                                        <i class="fa fa-image fa-2x text-muted"></i>
                                                        <p class="text-muted small mb-0">Image failed to load</p>
                                                        <p class="text-muted small"><?php echo htmlspecialchars($web_url); ?></p>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="bg-light p-3 text-center mb-2">
                                                        <i class="fa fa-image fa-2x text-muted"></i>
                                                        <p class="text-muted small mb-0">Image file not found</p>
                                                        <p class="text-muted small"><?php echo htmlspecialchars($image['image_path']); ?></p>
                                                    </div>
                                                <?php endif; ?>
                                                <p class="card-text small text-muted">
                                                    <?php echo basename($image['image_path']); ?><br>
                                                    <?php echo ($image['image_width'] ?: 'N/A') . ' × ' . ($image['image_height'] ?: 'N/A'); ?><br>
                                                    <?php echo date('M j, Y', strtotime($image['created_at'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                
                
                <!-- All Images Table -->
                <div class="row">
                    <div class="col-12">
                        <h4 class="mb-3">All Images</h4>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Preview</th>
                                        <th>Type</th>
                                        <th>Position</th>
                                        <th>Dimensions</th>
                                        <th>Image Path</th>
                                        <th>Uploaded</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($images)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">
                                                <i class="fa fa-images fa-2x mb-2"></i><br>
                                                No images uploaded yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($images as $image): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($image['image_path'] && file_exists($image['image_path'])): ?>
                                                        <?php 
                                                        // Convert absolute path to relative URL
                                                        $relative_path = str_replace(__DIR__, '', $image['image_path']);
                                                        $relative_path = ltrim($relative_path, '/\\');
                                                        $web_url = $relative_path;
                                                        ?>
                                                        <img src="<?php echo htmlspecialchars($web_url); ?>" class="image-preview" alt="Preview" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                                        <div style="display:none;" class="text-muted">
                                                            <i class="fa fa-exclamation-triangle"></i> Load failed
                                                            <br><small><?php echo htmlspecialchars($web_url); ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted">
                                                            <i class="fa fa-image"></i> No preview
                                                            <br><small><?php echo htmlspecialchars($image['image_path']); ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $image['image_type'] == 'header' ? 'primary' : 'secondary'; ?>">
                                                        <?php echo ucfirst($image['image_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo ucfirst($image['image_position']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="dimension-display">
                                                            <?php echo ($image['image_width'] ?: 'N/A') . ' × ' . ($image['image_height'] ?: 'N/A'); ?>
                                                        </span>
                                                        <button class="btn btn-sm btn-outline-primary edit-dimensions" 
                                                                data-id="<?php echo $image['id']; ?>"
                                                                data-width="<?php echo $image['image_width'] ?: ''; ?>"
                                                                data-height="<?php echo $image['image_height'] ?: ''; ?>"
                                                                title="Edit dimensions">
                                                            <i class="fa fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small><?php echo basename($image['image_path']); ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M j, Y H:i', strtotime($image['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge status-badge <?php echo $image['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo $image['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="?page=manage_images&action=toggle&id=<?php echo $image['id']; ?>" 
                                                           class="btn btn-outline-<?php echo $image['is_active'] ? 'warning' : 'success'; ?>"
                                                           title="<?php echo $image['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                                           onclick="return confirm('Are you sure you want to <?php echo $image['is_active'] ? 'deactivate' : 'activate'; ?> this image?')">
                                                            <i class="fa fa-<?php echo $image['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                                                        </a>
                                                        <a href="?page=manage_images&action=delete&id=<?php echo $image['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Are you sure you want to delete this <?php echo $image['image_type']; ?> - <?php echo $image['image_position']; ?> image? This action cannot be undone.')"
                                                           title="Delete">
                                                            <i class="fa fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Document Preview Section -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="mb-3">Document Preview</h4>
                        <div class="card">
                            <div class="card-header">
                                <ul class="nav nav-tabs card-header-tabs" id="previewTab" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="grades-preview-tab" data-bs-toggle="tab" data-bs-target="#grades-preview" type="button" role="tab">
                                            <i class="fa fa-file-word"></i> Grades Report Preview
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="previewTabContent">
                                    <div class="tab-pane fade show active" id="grades-preview" role="tabpanel">
                                        <div class="preview-container">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6>Report of Grades Layout Preview</h6>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="refreshPreview()">
                                                        <i class="fa fa-refresh"></i> Refresh
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="preview-iframe-container border rounded" style="height: 600px; overflow: hidden;">
                                                <div class="text-center py-5" id="preview-loading">
                                                    <div class="spinner-border text-primary" role="status">
                                                        <span class="visually-hidden">Loading preview...</span>
                                                    </div>
                                                    <div class="mt-2 text-muted">Loading document preview...</div>
                                                </div>
                                                <iframe class="w-100 h-100"
                                                        style="border: none; display: none;"
                                                        id="preview-iframe"
                                                        title="Document preview">
                                                </iframe>
                                            </div>
                                            
                                            <div class="mt-2 text-muted small">
                                                <i class="fa fa-info-circle"></i> 
                                                This preview shows how your uploaded images will appear in the exported Word document. 
                                                The preview uses the first available class for demonstration.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Dimensions Modal -->
    <div class="modal fade" id="editDimensionsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Image Dimensions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editDimensionsForm" method="POST">
                    <input type="hidden" name="id" id="editImageId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="editWidth" class="form-label">Width (px)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="editWidth" name="width" min="10" max="1000" required>
                                    <span class="input-group-text">px</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="editHeight" class="form-label">Height (px)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="editHeight" name="height" min="10" max="1000" required>
                                    <span class="input-group-text">px</span>
                                </div>
                            </div>
                        </div>
                        <small class="text-muted">Enter dimensions between 10 and 1000 pixels</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Update Dimensions
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (!defined('HORIZON_LAYOUT_LOADED')): ?>
    <script src="./js/bootstrap.bundle.min.js"></script>
    <script src="./js/jquery-3.6.0.min.js"></script>
    <?php endif; ?>
    <script>
        // Modal helper that works with Bootstrap 5 (native) and Bootstrap 4 (jQuery).
        function imgModal(action) {
            var node = document.getElementById('editDimensionsModal');
            if (!node) return;
            if (window.bootstrap && bootstrap.Modal) {
                var inst = bootstrap.Modal.getInstance(node) || new bootstrap.Modal(node);
                if (action === 'show') inst.show(); else inst.hide();
                return;
            }
            if (window.jQuery && $(node).modal) { $(node).modal(action); }
        }
        $(document).ready(function() {
            // Handle edit dimensions button click
            $('.edit-dimensions').on('click', function() {
                const id = $(this).data('id');
                const width = $(this).data('width');
                const height = $(this).data('height');
                
                $('#editImageId').val(id);
                $('#editWidth').val(width || '');
                $('#editHeight').val(height || '');
                
                imgModal('show');
            });
            
            // Handle form submission
            $('#editDimensionsForm').on('submit', function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize() + '&action=update_dimensions';
                
                console.log('Submitting form data:', formData); // Debug log
                console.log('Current URL:', window.location.href); // Debug log
                console.log('Form elements:', $(this).serializeArray()); // Debug log
                
                $.ajax({
                    url: 'manage_images.php', // Use current file directly
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    beforeSend: function() {
                        console.log('About to send AJAX request'); // Debug log
                        $('button[type="submit"]').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
                    },
                    success: function(response) {
                        console.log('Server response:', response); // Debug log
                        
                        if (response.success) {
                            // Close modal and refresh page to show updated dimensions
                            imgModal('hide');
                            location.reload();
                        } else {
                            alert('Failed to update dimensions: ' + (response.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', xhr.responseText); // Debug log
                        console.error('Status:', status); // Debug log
                        console.error('Error:', error); // Debug log
                        alert('Error updating dimensions: ' + (xhr.responseJSON?.message || error || 'Network error'));
                    },
                    complete: function() {
                        console.log('AJAX request completed'); // Debug log
                        $('button[type="submit"]').prop('disabled', false).html('<i class="fa fa-save"></i> Update Dimensions');
                    }
                });
            });
        });
        
        // Handle header/footer image upload
            $('#uploadImageFile').on('change', function() {
                var file = this.files[0];
                if (!file) {
                    $('#uploadImagePreview').empty();
                    return;
                }
                var reader = new FileReader();
                reader.onload = function(e) {
                    $('#uploadImagePreview').html(
                        '<img src="' + e.target.result + '" class="img-thumbnail mt-2" style="max-height: 100px;" alt="Preview">'
                    );
                };
                reader.readAsDataURL(file);
            });

            $('#uploadImageForm').on('submit', function(e) {
                e.preventDefault();

                var fileInput = $('#uploadImageFile')[0];
                if (!fileInput.files.length) {
                    alert('Please choose an image file to upload.');
                    return;
                }

                var formData = new FormData();
                formData.append('element_type', $('#uploadType').val());
                formData.append('position', $('#uploadPosition').val());
                formData.append('image', fileInput.files[0]);

                var $btn = $('#uploadImageBtn');
                var originalHtml = $btn.html();
                $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Uploading...');

                $.ajax({
                    url: 'upload_header_footer.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.status === 'success') {
                            alert('Image uploaded successfully.');
                            location.reload();
                        } else {
                            alert('Upload failed: ' + ((response && response.message) || 'Unknown error'));
                        }
                    },
                    error: function(xhr) {
                        alert('Upload failed: ' + (xhr.responseText || 'Network error'));
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        
        // Preview handling functions
        function handlePreviewLoad(iframe) {
            console.log('Preview loaded successfully');
            // Hide loading indicator and show iframe
            $('#preview-loading').hide();
            $(iframe).show();
        }
        
        function handlePreviewError(iframe) {
            console.error('Preview failed to load');
            // Show error message
            $('#preview-loading').hide();
            $(iframe).parent().html('<div class="alert alert-warning">Preview could not be loaded. Please ensure you have classes available and try again.</div>');
        }
        
        function refreshPreview() {
            const iframe = $('#preview-iframe');
            const currentSrc = iframe.attr('src');
            const timestamp = new Date().getTime();
            
            // Show loading indicator again
            $('#preview-loading').show();
            iframe.hide();
            
            // Add timestamp to prevent caching (iframe starts with no src).
            const newSrc = (currentSrc && currentSrc.includes('?')) ? 
                currentSrc.split('?')[0] + '?export=grades&class_id=<?php echo $preview_class_id; ?>&preview=true&t=' + timestamp : 
                'export_to_word.php?export=grades&class_id=<?php echo $preview_class_id; ?>&preview=true&t=' + timestamp;
            
            iframe.attr('src', newSrc);
        }
        
        // Load preview on page load (handlers bound BEFORE src is set —
        // the old inline onload raced the initial blank-document load).
        $(document).ready(function() {
            $('#preview-iframe').on('load', function() { handlePreviewLoad(this); });
            $('#preview-iframe').on('error', function() { handlePreviewError(this); });
            refreshPreview();
        });
    </script>
</body>
</html>
