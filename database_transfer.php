<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// Determine user role and set variables accordingly
$user_role = $_SESSION['role'];
$user_id = $_SESSION['admin_id'];
$username = $_SESSION['username'];
$db_path = $_SESSION['db_file'] ?? '';

// Set role-specific variables
if ($user_role === 'admin') {
    $backup_dir = __DIR__ . '/db/backups/Administrator';
    $transfer_url = 'transfer/admin_transfer.php';
    $restore_url = 'transfer/admin_restore.php';
    $portal_title = 'Admin Database Transfer';
} else {
    $backup_dir = __DIR__ . '/db/faculty_backups';
    $transfer_url = 'transfer/faculty_transfer.php';
    $restore_url = 'transfer/faculty_restore.php';
    $portal_title = 'Faculty Database Transfer';
}

require_once 'DBConnection.php';
$db = new DBConnection();

// Get available backups for this user
$available_backups = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '/' . $username . '_*.db');
    foreach ($files as $file) {
        $filename = basename($file);
        $timestamp = preg_replace('/^' . $username . '_|\.db$/', '', $filename);
        $available_backups[] = [
            'filename' => $filename,
            'timestamp' => $timestamp,
            'filepath' => $file,
            'size' => filesize($file)
        ];
    }
    rsort($available_backups); // Most recent first
}

// Hero stats (HORIZON badges)
$backup_count = count($available_backups);
$backup_bytes = 0;
foreach ($available_backups as $b) { $backup_bytes += $b['size']; }
$backup_size_txt = $backup_bytes >= 1048576
    ? number_format($backup_bytes / 1048576, 2) . ' MB'
    : number_format($backup_bytes / 1024, 2) . ' KB';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $portal_title; ?> - Grading System</title>
    <link href="./css/bootstrap.min.css" rel="stylesheet">
    <link href="./css/horizon-theme.css" rel="stylesheet">
    <link href="./Font-Awesome-master/css/all.min.css" rel="stylesheet">
    <style>
        /* HORIZON design system (css/horizon-theme.css) styles cards, buttons,
           badges and modals globally. Page-specific rules below reuse its tokens. */
        .horizon-hero-icon {
            width: 52px;
            height: 52px;
            min-width: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--horizon-brand-linear);
            color: #fff;
            font-size: 22px;
            box-shadow: 0 10px 24px rgba(67, 24, 255, .25);
            flex-shrink: 0;
        }
        .horizon-page-kicker {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--horizon-text-muted);
        }
        .horizon-page-title {
            font-weight: 800;
            color: var(--horizon-text-title);
        }
        .horizon-page-sub {
            color: var(--horizon-text-muted);
            font-size: 13px;
        }
        .backup-item {
            background: var(--horizon-card-bg);
            border: 1px solid var(--horizon-card-border);
            border-radius: var(--horizon-radius-md);
            padding: 1rem 1.25rem;
            margin-bottom: .75rem;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .backup-item:hover {
            border-color: var(--horizon-brand-200);
            box-shadow: var(--horizon-card-shadow);
        }
        .backup-item h6 {
            color: var(--horizon-text-title);
        }
        .dbport-pane {
            border: 1px solid var(--horizon-card-border);
            border-radius: var(--horizon-radius-md);
            padding: 16px;
            height: 100%;
            background: var(--horizon-card-bg);
        }
        .dbport-pane h6 {
            color: var(--horizon-text-title);
        }
        #backupStatus, #restoreStatus, #dbportStatus {
            display: none;
        }
        #dbportFile {
            border-color: var(--horizon-input-border);
            color: var(--horizon-input-text);
        }
        .loading {
            display: none;
            text-align: center;
            padding: 2rem;
            background: var(--horizon-card-bg);
            border-radius: var(--horizon-radius-md);
            margin: 1rem 0;
            border: 1px solid var(--horizon-card-border);
        }
        .spinner {
            width: 3rem;
            height: 3rem;
            border: 3px solid var(--horizon-brand-100);
            border-top-color: var(--horizon-brand-500);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        .loading p {
            margin: 0.5rem 0 0;
            font-weight: 600;
            color: var(--horizon-text-muted);
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .horizon-empty {
            text-align: center;
            padding: 28px;
            color: var(--horizon-text-muted);
        }
        .horizon-empty i {
            font-size: 22px;
            margin-bottom: 8px;
            display: block;
        }
        @media (max-width: 576px) {
            #dbportFile { max-width: 100% !important; }
            .horizon-hero-icon { width: 44px; height: 44px; min-width: 44px; font-size: 19px; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col-12">
                <section class="horizon-card">
                    <div class="horizon-card-body">
                        <div class="d-flex gap-3 align-items-center flex-wrap">
                            <div class="horizon-hero-icon"><i class="fas fa-database"></i></div>
                            <div class="flex-grow-1" style="min-width:240px">
                                <div class="horizon-page-kicker">Database</div>
                                <h2 class="horizon-page-title mb-1" style="font-size:clamp(1.2rem,2vw,1.5rem)"><?php echo htmlspecialchars($portal_title); ?></h2>
                                <p class="horizon-page-sub mb-2">Manage your database backups and transfers</p>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge badge-brand"><i class="fas fa-user-shield me-1"></i><?php echo htmlspecialchars($user_role); ?> · <?php echo htmlspecialchars($username); ?></span>
                                    <span class="badge badge-secondary"><i class="fas fa-box-archive me-1"></i><?php echo $backup_count; ?> backup(s)</span>
                                    <span class="badge badge-secondary"><i class="fas fa-hdd me-1"></i><?php echo $backup_size_txt; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="horizon-card">
                    <div class="horizon-card-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="horizon-widget-icon-box brand" style="width:36px;height:36px;min-width:36px;font-size:14px"><i class="fas fa-copy"></i></span>
                            <h5>Create Database Backup</h5>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" onclick="createBackup()">
                            <i class="fas fa-download me-1"></i> Create Backup
                        </button>
                    </div>
                    <div class="horizon-card-body">
                        <p class="horizon-page-sub">Create a backup copy of your current database.</p>
                        <div class="loading" id="backupLoading">
                            <div class="spinner"></div>
                            <p>Creating backup...</p>
                        </div>
                        <div class="horizon-flash-banner" id="backupStatus" style="display:none"></div>
                        <?php if (empty($available_backups)): ?>
                            <div class="horizon-empty"><i class="fas fa-box-open"></i><strong>No backups available.</strong><p class="mb-0 small">Create your first backup with the button above.</p></div>
                        <?php else: ?>
                            <?php foreach ($available_backups as $backup): ?>
                                <div class="backup-item">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="horizon-widget-icon-box brand me-2" style="width:40px;height:40px;min-width:40px;font-size:15px"><i class="fas fa-file-archive"></i></span>
                                                <div>
                                                    <h6 class="mb-1" style="font-weight:700">
                                                        <?php echo htmlspecialchars($backup['filename']); ?>
                                                    </h6>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <span class="badge badge-secondary">
                                                            <i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y h:i A', strtotime($backup['timestamp'])); ?>
                                                        </span>
                                                        <span class="badge badge-secondary">
                                                            <i class="fas fa-database me-1"></i><?php echo number_format($backup['size'] / 1024, 2); ?> KB
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-md-end mt-2 mt-md-0">
                                            <div class="btn-group" role="group">
                                          <!--      <button type="button" class="btn btn-success btn-sm me-2" 
                                                    onclick="restoreBackup('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                    <i class="fas fa-undo me-1"></i> Restore
                                                </button> -->
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                    onclick="deleteBackup('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                    <i class="fas fa-trash me-1"></i>
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="loading" id="restoreLoading">
                            <div class="spinner"></div>
                            <p>Processing...</p>
                        </div>
                        <div class="horizon-flash-banner" id="restoreStatus" style="display:none"></div>
                    </div>
                </div>
            </div>
        </div>

        
        <?php if (in_array($user_role, ['admin', 'faculty'], true)): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="horizon-card">
                    <div class="horizon-card-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="horizon-widget-icon-box brand" style="width:36px;height:36px;min-width:36px;font-size:14px"><i class="fas fa-file-export"></i></span>
                            <h5>Export / Import Database</h5>
                        </div>
                        <span class="badge badge-brand"><i class="fas fa-shield-halved me-1"></i>validated + backed up</span>
                    </div>
                    <div class="horizon-card-body">
                        <p class="horizon-page-sub">Move <code><?php echo htmlspecialchars($user_role === 'faculty' && !empty($db_path) ? basename($db_path) : 'db/grading_db.db'); ?></code> between this copy and the live site
                        (e.g. <code>https://essuc-academic.yzz.me</code>) in two steps:
                        <strong>Export</strong> here to download the file, then <strong>Import</strong> it on the
                        other site's own Database Transfer page. Imports are validated as genuine grading
                        databases, and the replaced database is always backed up first.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="dbport-pane">
                                    <h6 class="fw-bold"><i class="fas fa-download me-1" style="color:var(--horizon-brand-500)"></i> Export</h6>
                                    <p class="small horizon-page-sub mb-2">Download the current database file to your PC.</p>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="exportDb()">
                                        <i class="fas fa-download me-1"></i> Download Database
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="dbport-pane">
                                    <h6 class="fw-bold"><i class="fas fa-upload me-1" style="color:var(--horizon-brand-500)"></i> Import</h6>
                                    <p class="small horizon-page-sub mb-2">Replace the current database with an exported file.</p>
                                    <div class="d-flex flex-wrap align-items-center" style="gap:.5rem">
                                        <input id="dbportFile" type="file" accept=".db,.sqlite,.sqlite3" class="form-control form-control-sm" style="max-width:260px">
                                        <button type="button" class="btn btn-warning btn-sm" onclick="importDb()">
                                            <i class="fas fa-upload me-1"></i> Import &amp; Replace
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="horizon-flash-banner horizon-flash-warning mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>Import <strong>replaces</strong> everything — records that exist here but not in the uploaded file will be overwritten (a backup is kept automatically).</div>
                        </div>
                        <div class="loading" id="dbportLoading">
                            <div class="spinner"></div>
                            <p id="dbportLoadingText">Working...</p>
                        </div>
                        <div class="horizon-flash-banner" id="dbportStatus" style="display:none"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Modern Modal -->
    <div class="modal fade" id="messageModal" tabindex="-1" role="dialog" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="fas fa-info-circle"></i> <span id="modalTitleText">Message</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="modalMessage"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmModalTitle">
                        <i class="fas fa-exclamation-triangle text-warning"></i> <span id="confirmModalTitleText">Confirm Action</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="confirmModalMessage"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmModalConfirm">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!defined('HORIZON_LAYOUT_LOADED')): ?>
    <script src="js/jquery-3.6.0.min.js"></script>
    <script src="js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>
    <script>
        // Modal helper that works with Bootstrap 5 (native) and Bootstrap 4 (jQuery).
        function bsModal(sel, action) {
            var node = typeof sel === 'string' ? document.querySelector(sel) : sel;
            if (!node) return;
            if (window.bootstrap && bootstrap.Modal) {
                var inst = bootstrap.Modal.getInstance(node) || new bootstrap.Modal(node);
                if (action === 'show') inst.show(); else inst.hide();
                return;
            }
            if (window.jQuery && $(node).modal) { $(node).modal(action); }
        }
        // Explicit close handlers (do not rely on data-dismiss / data-bs-dismiss alone).
        $(document).on('click', '#messageModal [data-dismiss="modal"], #messageModal [data-bs-dismiss="modal"]', function() {
            bsModal('#messageModal', 'hide');
        });
        $(document).on('click', '#confirmModal [data-dismiss="modal"], #confirmModal [data-bs-dismiss="modal"]', function() {
            bsModal('#confirmModal', 'hide');
        });
        // Modern Modal Helper Function
        function showModal(title, message, type = 'info') {
            var iconClass = type === 'success' ? 'fa-check-circle text-success' : 
                           type === 'error' ? 'fa-exclamation-circle text-danger' : 
                           'fa-info-circle text-primary';
            
            $('#modalTitle').html('<i class="fas ' + iconClass + '"></i> <span id="modalTitleText">' + title + '</span>');
            $('#modalMessage').html(message);
            bsModal('#messageModal', 'show');
        }

        // Confirmation Modal Helper Function
        function showConfirm(title, message, onConfirm) {
            $('#confirmModalTitleText').text(title);
            $('#confirmModalMessage').html(message);
            
            // Remove previous event handlers
            $('#confirmModalConfirm').off('click');
            $('#confirmModal [data-dismiss="modal"], #confirmModal [data-bs-dismiss="modal"]').off('click.dismiss');
            
            // Add new event handler for confirm button
            $('#confirmModalConfirm').on('click', function() {
                bsModal('#confirmModal', 'hide');
                setTimeout(function() {
                    onConfirm();
                }, 300); // Wait for modal to close
            });
            
            // Add explicit close handlers for close and cancel buttons
            $('#confirmModal [data-dismiss="modal"], #confirmModal [data-bs-dismiss="modal"]').on('click.dismiss', function() {
                bsModal('#confirmModal', 'hide');
            });
            
            bsModal('#confirmModal', 'show');
        }
        function createBackup() {
            var deleteData = $('#deleteDataCheckbox').is(':checked');
            var confirmMessage = deleteData ? 
                'Are you sure you want to create a backup and delete all data? <strong>This action cannot be undone.</strong>' : 
                'Are you sure you want to create a backup?';
            var confirmTitle = deleteData ? 'Backup & Delete Data' : 'Create Backup';
            
            showConfirm(confirmTitle, confirmMessage, function() {
                $('#backupLoading').show();
                $('#backupStatus').hide();
                performBackup(deleteData);
            });
        }
        
        function performBackup(deleteData) {
            $.ajax({
                url: '<?php echo $transfer_url; ?>',
                type: 'POST',
                data: { action: 'backup', delete_data: deleteData },
                dataType: 'json',
                success: function(response) {
                    $('#backupLoading').hide();
                    if (response.status === 'success' || response.status === 'warning') {
                        var message = response.message;
                        if (response.deleted_tables && response.deleted_tables.length > 0) {
                            message += '<br><small>Cleared tables: ' + response.deleted_tables.join(', ') + '</small>';
                        }
                        $('#backupStatus').removeClass('horizon-flash-danger').addClass('horizon-flash-success')
                            .html('<i class="fas fa-check-circle"></i> ' + message).css('display', 'flex');
                        setTimeout(() => location.reload(), 3000);
                    } else {
                        $('#backupLoading').hide();
                        showModal('Error', response.message, 'error');
                    }
                },
                error: function() {
                    $('#backupLoading').hide();
                    showModal('Error', 'Error creating backup. Please try again.', 'error');
                }
            });
        }

        function restoreBackup(filename) {
            showConfirm('Restore Backup', 'Are you sure you want to restore this backup? <strong>This will replace your current database.</strong>', function() {
                performRestore(filename);
            });
        }
        
        function performRestore(filename) {
            $('#restoreLoading').show();
            $('#restoreStatus').hide();
            
            $.ajax({
                url: '<?php echo $restore_url; ?>',
                type: 'POST',
                data: { action: 'restore', filename: filename },
                dataType: 'json',
                success: function(response) {
                    $('#restoreLoading').hide();
                    if (response.status === 'success') {
                        var message = response.message;
                        if (response.backup_deleted) {
                            message += '<br><small>Backup file deleted automatically after restore</small>';
                        }
                        $('#restoreStatus').removeClass('horizon-flash-danger').addClass('horizon-flash-success')
                            .html('<i class="fas fa-check-circle"></i> ' + message).css('display', 'flex');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        $('#restoreLoading').hide();
                        showModal('Error', response.message, 'error');
                    }
                },
                error: function() {
                    $('#restoreLoading').hide();
                    showModal('Error', 'Error restoring backup. Please try again.', 'error');
                }
            });
        }

        function deleteBackup(filename) {
            showConfirm('Delete Backup', 'Are you sure you want to delete this backup? <strong>This action cannot be undone.</strong>', function() {
                performDelete(filename);
            });
        }
        
        function performDelete(filename) {
            $.ajax({
                url: '<?php echo $transfer_url; ?>',
                type: 'POST',
                data: { action: 'delete', filename: filename },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        showModal('Success', 'Backup deleted successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showModal('Error', 'Error deleting backup: ' + response.message, 'error');
                    }
                },
                error: function() {
                    showModal('Error', 'Error deleting backup. Please try again.', 'error');
                }
            });
        }

        // ---- Export / Import database (admin + faculty) ----
        // NOTE: status text is injected as TEXT, never HTML.
        function dbportStatus(msg, ok) {
            var el = $('#dbportStatus');
            el.removeClass('horizon-flash-success horizon-flash-danger').addClass(ok ? 'horizon-flash-success' : 'horizon-flash-danger').empty()
                .append($('<i>').addClass('fas ' + (ok ? 'fa-check-circle' : 'fa-exclamation-circle') + ' me-1'))
                .append(document.createTextNode(' ' + msg)).css('display', 'flex');
        }
        function exportDb() {
            window.location = 'transfer/db_portable.php?action=export';
        }
        function importDb() {
            var input = $('#dbportFile')[0];
            if (!input || !input.files || !input.files[0]) {
                dbportStatus('Choose a .db file first.', false);
                return;
            }
            var file = input.files[0];
            var safeName = $('<div>').text(file.name).html();
            showConfirm('Import Database',
                'Replace the <strong>current</strong> database with <strong>' + safeName + '</strong> (' + Math.round(file.size / 1024) + ' KB)? The current database will be backed up first.',
                function() {
                    $('#dbportLoadingText').text('Uploading and validating...');
                    $('#dbportLoading').show();
                    $('#dbportStatus').hide();
                    var fd = new FormData();
                    fd.append('action', 'import');
                    fd.append('database', file);
                    $.ajax({
                        url: 'transfer/db_portable.php',
                        type: 'POST',
                        data: fd,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        timeout: 200000
                    }).done(function(res) {
                        $('#dbportLoading').hide();
                        dbportStatus((res && res.message) || 'Import failed.', res && res.status === 'success');
                        if (res && res.status === 'success') setTimeout(function() { location.reload(); }, 2500);
                    }).fail(function() {
                        $('#dbportLoading').hide();
                        dbportStatus('Network error during import.', false);
                    });
                });
        }
    </script>
</body>
</html>