<?php
// Buffer all output so the login redirect below can run even if an
// including script (e.g. index.php) has already started sending output.
ob_start();
require_once("DBConnection.php");

// Check if user is logged in (you should implement proper authentication)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    if (!headers_sent()) {
        header('Location: login.php');
    } else {
        echo '<script>window.location.href="login.php";</script>';
    }
    exit;
}

// Get current settings
$settings = [
    'header' => [
        'left' => null,
        'center' => null,
        'right' => null
    ],
    'footer' => [
        'left' => null,
        'center' => null,
        'right' => null
    ]
];

$result = $conn->query("SELECT * FROM header_footer_settings WHERE is_active = 1");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if (isset($settings[$row['element_type']][$row['position']])) {
        $settings[$row['element_type']][$row['position']] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Header & Footer Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .preview-image {
            max-width: 200px;
            max-height: 100px;
            margin-top: 10px;
        }
        .preview-container {
            border: 1px solid #ddd;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h2>Header & Footer Settings</h2>

        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h4>Header Settings</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach (['left', 'center', 'right'] as $position): ?>
                            <div class="mb-3">
                                <h5>Header <?php echo ucfirst($position); ?></h5>
                                <form class="upload-form" data-type="header" data-position="<?php echo $position; ?>">
                                    <div class="mb-2">
                                        <?php if (!empty($settings['header'][$position]['image_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($settings['header'][$position]['image_path']); ?>"
                                                 class="preview-image"
                                                 id="header-<?php echo $position; ?>-preview">
                                        <?php else: ?>
                                            <div class="preview-container">
                                                No image set
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="input-group mb-3">
                                        <input type="file" class="form-control" name="image" accept="image/*" required>
                                        <button class="btn btn-primary" type="submit">Upload</button>
                                    </div>
                                    <div class="form-text">Recommended size: 300x100 pixels</div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4>Footer Settings</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach (['left', 'center', 'right'] as $position): ?>
                            <div class="mb-3">
                                <h5>Footer <?php echo ucfirst($position); ?></h5>
                                <form class="upload-form" data-type="footer" data-position="<?php echo $position; ?>">
                                    <div class="mb-2">
                                        <?php if (!empty($settings['footer'][$position]['image_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($settings['footer'][$position]['image_path']); ?>"
                                                 class="preview-image"
                                                 id="footer-<?php echo $position; ?>-preview">
                                        <?php else: ?>
                                            <div class="preview-container">
                                                No image set
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="input-group mb-3">
                                        <input type="file" class="form-control" name="image" accept="image/*" required>
                                        <button class="btn btn-primary" type="submit">Upload</button>
                                    </div>
                                    <div class="form-text">Recommended size: 300x50 pixels</div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        $('.upload-form').on('submit', function(e) {
            e.preventDefault();

            const form = $(this);
            const formData = new FormData(this);
            const type = form.data('type');
            const position = form.data('position');

            formData.append('element_type', type);
            formData.append('position', position);

            $.ajax({
                url: 'upload_header_footer.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        alert('Settings saved successfully');
                        // Update preview
                        const previewId = `#${type}-${position}-preview`;
                        if ($(previewId).length) {
                            $(previewId).attr('src', response.filepath + '?' + new Date().getTime());
                        } else {
                            form.find('.preview-container').html(
                                `<img src="${response.filepath}" class="preview-image" id="${type}-${position}-preview">`
                            );
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('An error occurred while saving the settings');
                }
            });
        });
    });
    </script>
</body>
</html>
