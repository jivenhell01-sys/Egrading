<?php
require_once("DBConnection.php");
$qry = $conn->query("SELECT * FROM `admin_list` where admin_id = '{$_SESSION['admin_id']}'");
if ($qry) {
    foreach($qry->fetchArray(SQLITE3_ASSOC) as $k => $v){
        $$k = $v;
    }
}
$role = $_SESSION['role'] ?? 'admin';
?>

<!-- Cropper.js for image editing -->
<link rel="stylesheet" href="./css/cropper.min.css" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css';">
<script src="./js/cropper.min.js" onerror="this.onerror=null;this.src='https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js';"></script>
<style>
.cropper-unavailable-note {
    font-size: 0.875rem;
    color: var(--horizon-text-body);
    background: rgba(255, 193, 7, 0.12);
    border: 1px solid rgba(255, 193, 7, 0.35);
    border-radius: 12px;
    padding: 0.75rem 1rem;
}
</style>

<div class="row g-4">
    <!-- LEFT COLUMN: Horizon Profile Banner Card & Security Settings -->
    <div class="col-12 col-lg-4">
        <!-- Horizon Profile Banner Card -->
        <div class="horizon-card text-center p-3 mb-4">
            <!-- Cover Banner -->
            <div class="horizon-profile-banner">
                <!-- Avatar Center Overlay -->
                <div class="horizon-profile-avatar-wrapper position-relative">
                    <img src="<?php echo !empty($image) ? htmlspecialchars($image) : './image/68ddeb9e9db18_pexels-photo-1097456.jpeg' ?>" 
                         alt="Profile Image" 
                         id="profile-image" 
                         class="horizon-profile-avatar"
                         onerror="this.src='./image/68ddeb9e9db18_pexels-photo-1097456.jpeg'">
                    
                    <!-- Camera Upload Overlay Button -->
                    <label for="user_image" class="btn btn-primary rounded-circle position-absolute" 
                           style="bottom: 0; right: 0; width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 10px rgba(67, 24, 255, 0.4);"
                           title="Change Photo">
                        <i class="fas fa-camera" style="font-size: 13px;"></i>
                        <input type="file" name="user_image_trigger" id="user_image" class="d-none" accept="image/*">
                    </label>
                </div>
            </div>

            <!-- Profile Info -->
            <div style="margin-top: 55px;">
                <h4 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($fullname ?? 'Administrator'); ?></h4>
                <div class="badge badge-brand mb-3"><?php echo ucwords(str_replace('_', ' ', $role)); ?></div>
                <p class="text-muted" style="font-size: 13px;">
                    Username: <strong>@<?php echo htmlspecialchars($username ?? ''); ?></strong>
                </p>
            </div>

            <!-- Profile Stats Counter -->
            <div class="horizon-profile-stats">
                <div class="horizon-stat-item">
                    <h5 class="horizon-stat-count text-primary">Active</h5>
                    <span class="horizon-stat-label">Status</span>
                </div>
                <div class="horizon-stat-item">
                    <h5 class="horizon-stat-count"><?php echo ucfirst($role); ?></h5>
                    <span class="horizon-stat-label">Role</span>
                </div>
                <div class="horizon-stat-item">
                    <h5 class="horizon-stat-count text-success"><i class="fas fa-check"></i></h5>
                    <span class="horizon-stat-label">Verified</span>
                </div>
            </div>
        </div>

        <?php if($role === 'admin'): ?>
        <!-- Admin Security Protection Settings Card -->
        <div class="horizon-card p-4">
            <div class="horizon-card-header p-0 pb-3 mb-3">
                <h5><i class="fas fa-shield-alt text-primary me-2"></i>Security Protection</h5>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="security_toggle" name="security_toggle">
                <label class="form-check-label fw-bold text-dark" for="security_toggle">
                    Enable System Protection
                </label>
                <small class="text-muted d-block mt-1">
                    Prevents right-click, view source, inspect element, and unauthorized debugger tools for logged-in sessions.
                </small>
            </div>
            <div class="p-2 rounded bg-lightPrimary" style="border: 1px solid var(--horizon-divider);">
                <small class="text-muted">
                    <i class="fas fa-info-circle text-info me-1"></i> Changes sync instantly across all active browser sessions.
                </small>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT COLUMN: Account & Password Edit Form -->
    <div class="col-12 col-lg-8">
        <div class="horizon-card">
            <div class="horizon-card-header">
                <h5><i class="fas fa-user-cog text-primary me-2"></i>Edit Account Credentials</h5>
            </div>
            <div class="horizon-card-body">
                <!-- Cropper Workspace (Shown when new photo is picked) -->
                <div id="cropper-container" class="d-none mb-4 p-3 rounded-3" style="background: var(--horizon-input-bg); border: 1px dashed var(--horizon-brand-400);">
                    <h6 class="fw-bold mb-2 text-primary"><i class="fas fa-crop-alt me-1"></i> Crop Profile Image</h6>
                    <div style="max-height: 280px; overflow: hidden; border-radius: 12px;" class="mb-3 text-center">
                        <img id="image-to-crop" class="img-fluid" style="max-height: 280px;">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm" id="crop-btn">
                            <i class="fas fa-check me-1"></i> Apply Cropped Image
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="cancel-crop">
                            <i class="fas fa-times me-1"></i> Cancel
                        </button>
                    </div>
                </div>

                <!-- Form -->
                <form action="" id="user-form" enctype="multipart/form-data">
                    <input type="hidden" name="id" value="<?php echo isset($admin_id) ? $admin_id : '' ?>">
                    <input type="file" name="user_image" id="actual_user_image" class="d-none" accept="image/*">

                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                        <i class="fas fa-id-card text-muted me-1"></i> Personal Information
                    </h6>

                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="fullname" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   id="fullname" 
                                   name="fullname" 
                                   value="<?php echo isset($fullname) ? htmlspecialchars($fullname) : '' ?>" 
                                   placeholder="Your full name"
                                   required>
                        </div>
                        <div class="col-md-12">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-at"></i></span>
                                <input type="text" 
                                       class="form-control" 
                                       id="username" 
                                       name="username" 
                                       value="<?php echo isset($username) ? htmlspecialchars($username) : '' ?>" 
                                       placeholder="Username"
                                       required>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                        <i class="fas fa-key text-muted me-1"></i> Change Password
                        <small class="text-muted fw-normal ms-1">(Leave blank to keep unchanged)</small>
                    </h6>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="password" class="form-label">New Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" 
                                       class="form-control" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Min. 8 characters">
                                <button class="btn btn-outline-secondary toggle-password" type="button">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" 
                                       class="form-control" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       placeholder="Confirm new password">
                                <button class="btn btn-outline-secondary toggle-password" type="button">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 rounded-3 mb-4" style="background: var(--horizon-input-bg); border: 1px solid var(--horizon-divider);">
                        <label for="old_password" class="form-label mb-1">
                            <i class="fas fa-unlock-alt text-warning me-1"></i> Current Password Confirmation
                        </label>
                        <small class="text-muted d-block mb-2">Enter your current password to authorize credential changes.</small>
                        <input type="password" 
                               class="form-control" 
                               id="old_password" 
                               name="old_password" 
                               placeholder="Current password"
                               style="max-width: 380px;">
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                            <i class="fas fa-arrow-left me-1"></i> Back
                        </button>
                        <button type="submit" class="btn btn-primary" id="save-account-btn">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let cropper;
let currentImageUrl = '';

function applyImageWithoutCrop(file) {
    const reader = new FileReader();
    reader.onload = function(event) {
        $('#profile-image').attr('src', event.target.result);
    };
    reader.readAsDataURL(file);

    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    document.getElementById('actual_user_image').files = dataTransfer.files;

    $('#cropper-container').removeClass('d-none').html(
        '<div class="cropper-unavailable-note"><i class="fas fa-info-circle me-2"></i>Image cropper is unavailable right now. The selected image will be uploaded as-is when you save changes.</div>'
    );

    window.showAppToast('warning', 'Cropper library is unavailable. The selected image will be uploaded without cropping.');
}

// When the file input changes
$('#user_image').on('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    if (!file.type.match('image.*')) {
        window.showAppToast('warning', 'Please select an image file (JPEG, PNG, GIF).');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = function(event) {
        currentImageUrl = event.target.result;
        $('#cropper-container').removeClass('d-none');
        
        const image = document.getElementById('image-to-crop');
        image.src = currentImageUrl;
        
        if (cropper) {
            cropper.destroy();
        }

        if (typeof Cropper === 'undefined') {
            applyImageWithoutCrop(file);
            return;
        }
        
        cropper = new Cropper(image, {
            aspectRatio: 1,
            viewMode: 1,
            autoCropArea: 0.8,
            responsive: true,
            guides: true,
            center: true,
            highlight: false,
            cropBoxMovable: true,
            cropBoxResizable: true,
            minCropBoxWidth: 100,
            minCropBoxHeight: 100
        });
    };
    reader.readAsDataURL(file);
});

// When crop button is clicked
$('#crop-btn').on('click', function() {
    if (!cropper) return;
    
    const canvas = cropper.getCroppedCanvas({
        width: 400,
        height: 400,
        fillColor: '#fff',
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
    });
    
    if (!canvas) {
        window.showAppToast('danger', 'Error cropping image.');
        return;
    }
    
    canvas.toBlob((blob) => {
        const croppedFile = new File([blob], 'profile.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#profile-image').attr('src', e.target.result);
            
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(croppedFile);
            document.getElementById('actual_user_image').files = dataTransfer.files;
            
            $('#cropper-container').addClass('d-none');
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            window.showAppToast('info', 'Cropped photo ready. Click Save Changes to update.');
        };
        reader.readAsDataURL(blob);
    }, 'image/jpeg', 0.9);
});

$('#cancel-crop').on('click', function() {
    $('#cropper-container').addClass('d-none');
    $('#user_image').val('');
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
});

// Toggle password visibility
document.querySelectorAll('.toggle-password').forEach(function(button) {
    button.addEventListener('click', function() {
        const input = this.previousElementSibling;
        const icon = this.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    });
});

// Form submit handler
$(function(){
    $('#user-form').submit(function(e){
        e.preventDefault();
        $('.pop_msg').remove();
        var _this = $(this);
        var _el = $('<div>').addClass('pop_msg horizon-flash-banner horizon-flash-info mb-3');
        
        $('#save-account-btn').attr('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');
        
        var formData = new FormData(this);
        $.ajax({
            url: './Actions.php?a=update_credentials',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'JSON',
            error: function(err){
                console.error(err);
                _el.removeClass('horizon-flash-info').addClass('horizon-flash-danger');
                _el.html('<i class="fas fa-exclamation-circle me-1"></i> An error occurred while saving.');
                _this.prepend(_el);
                $('#save-account-btn').attr('disabled', false).html('<i class="fas fa-save me-1"></i> Save Changes');
                window.showAppToast('danger', 'Failed to update credentials.');
            },
            success: function(resp){
                if(resp.status === 'success'){
                    _el.removeClass('horizon-flash-info').addClass('horizon-flash-success');
                    _el.html('<i class="fas fa-check-circle me-1"></i> ' + resp.msg);
                    _this.prepend(_el);
                    window.showAppToast('success', resp.msg);
                    setTimeout(() => {
                        location.reload();
                    }, 1200);
                } else {
                    _el.removeClass('horizon-flash-info').addClass('horizon-flash-danger');
                    _el.html('<i class="fas fa-exclamation-triangle me-1"></i> ' + resp.msg);
                    _this.prepend(_el);
                    window.showAppToast('danger', resp.msg);
                    $('#save-account-btn').attr('disabled', false).html('<i class="fas fa-save me-1"></i> Save Changes');
                }
            }
        });
    });
});

// Security Protection Toggle Sync
function loadAccountSecuritySetting() {
    $.ajax({
        url: './security_settings.php',
        method: 'GET',
        dataType: 'JSON',
        success: function(response) {
            if (response.status === 'success') {
                const securityToggle = document.getElementById('security_toggle');
                if (securityToggle) {
                    securityToggle.checked = response.security_enabled;
                }
            }
        }
    });
}

const securityToggle = document.getElementById('security_toggle');
if (securityToggle) {
    securityToggle.addEventListener('change', function() {
        const isEnabled = this.checked;
        $.ajax({
            url: './security_settings.php',
            method: 'POST',
            data: { security_enabled: isEnabled ? 1 : 0 },
            dataType: 'JSON',
            success: function(response) {
                if (response.status === 'success') {
                    window.showAppToast('success', 'Security protection ' + (isEnabled ? 'enabled' : 'disabled'));
                } else {
                    $('#security_toggle').prop('checked', !isEnabled);
                    window.showAppToast('danger', 'Failed to update security setting');
                }
            },
            error: function() {
                $('#security_toggle').prop('checked', !isEnabled);
                window.showAppToast('danger', 'Error updating security setting');
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    loadAccountSecuritySetting();
});
</script>