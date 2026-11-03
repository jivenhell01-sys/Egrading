
<?php
require_once(__DIR__ . '/../DBConnection.php');

$main_db_path = __DIR__ . '/../db/grading_db.db';
$main_conn = new SQLite3($main_db_path);
$main_conn->enableExceptions(true);
$main_conn->exec("PRAGMA busy_timeout = 5000");
$main_conn->exec("PRAGMA journal_mode = WAL");

$availablePages = [
    'home' => ['label' => 'Home', 'description' => 'Dashboard overview', 'group' => 'General', 'icon' => 'fa-home'],
    'class' => ['label' => 'Classes', 'description' => 'Manage assigned classes', 'group' => 'General', 'icon' => 'fa-chalkboard-teacher'],
    'students' => ['label' => 'Students', 'description' => 'View student roster', 'group' => 'General', 'icon' => 'fa-user-graduate'],
    'assessments' => ['label' => 'Assessments', 'description' => 'Manage assessment setup', 'group' => 'Assessments', 'icon' => 'fa-clipboard-list'],
    'marks' => ['label' => 'Marks', 'description' => 'Encode student marks', 'group' => 'Assessments', 'icon' => 'fa-pen'],
    'attendance' => ['label' => 'Attendance', 'description' => 'Track attendance', 'group' => 'Assessments', 'icon' => 'fa-calendar-check'],
    'reports' => ['label' => 'Class Records', 'description' => 'Generate class record PDFs', 'group' => 'Reports', 'icon' => 'fa-file-alt'],
    'report_of_grades' => ['label' => 'Report of Grades', 'description' => 'Print report of grades', 'group' => 'Reports', 'icon' => 'fa-award'],
    'transmutation_table' => ['label' => 'Transmutation', 'description' => 'View grade transmutation', 'group' => 'Reports', 'icon' => 'fa-exchange-alt'],
    'component_prediction' => ['label' => 'Component Prediction', 'description' => 'Predict grade components', 'group' => 'Reports', 'icon' => 'fa-chart-line'],
    'prediction_dashboard' => ['label' => 'Prediction Dashboard', 'description' => 'View grade predictions', 'group' => 'Reports', 'icon' => 'fa-brain'],
    'database_transfer' => ['label' => 'Transfer Records', 'description' => 'Download or upload data', 'group' => 'Advanced', 'icon' => 'fa-database'],
    'maintenance' => ['label' => 'Maintenance', 'description' => 'Reference materials & utilities', 'group' => 'Advanced', 'icon' => 'fa-tools'],
    'admin/faculty' => ['label' => 'Faculty Accounts', 'description' => 'Create accounts & assign access', 'group' => 'Administration', 'icon' => 'fa-chalkboard'],
    'admin/departments' => ['label' => 'Departments & Courses', 'description' => 'Maintain departments & courses', 'group' => 'Administration', 'icon' => 'fa-sitemap'],
    'admin/monitoring' => ['label' => 'Faculty Monitoring', 'description' => 'Monitor faculty records', 'group' => 'Administration', 'icon' => 'fa-chart-line'],
    'admin/device_logs' => ['label' => 'Device Logs', 'description' => 'Audit student device bindings', 'group' => 'Administration', 'icon' => 'fa-fingerprint'],
    'manage_images' => ['label' => 'Image Manager', 'description' => 'Manage uploaded images', 'group' => 'Administration', 'icon' => 'fa-images'],
    'landing_registrations' => ['label' => 'Landing Registrations', 'description' => 'Review incoming registrants', 'group' => 'Administration', 'icon' => 'fa-inbox'],
    'manage_account' => ['label' => 'My Account', 'description' => 'Update profile & password', 'group' => 'General', 'icon' => 'fa-user-cog'],
];

$groupedPages = [];
foreach ($availablePages as $key => $page) {
    $groupedPages[$page['group']][$key] = $page;
}

// Departments come from the shared catalog (Administration > Departments &
// Courses), so the list here is whatever the admin has set up.
require_once(__DIR__ . '/../departments.php');
$departmentCatalog = department_catalog();
$programLabels = [];
foreach ($departmentCatalog as $deptCode => $dept) {
    $programLabels[$deptCode] = [
        'full'  => $dept['name'] !== '' ? $dept['name'] : $deptCode,
        'short' => $dept['short'] !== '' ? $dept['short'] : $deptCode,
    ];
}
$positionOptions = ['College Dean', 'Program Head', 'Faculty'];

$columns = $main_conn->query("PRAGMA table_info(admin_list)");
$adminColumns = [];
while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
    $adminColumns[] = $col['name'];
}
if (!in_array('last_activity', $adminColumns)) {
    $main_conn->exec("ALTER TABLE admin_list ADD COLUMN last_activity TIMESTAMP DEFAULT NULL");
}
// Department / Position assignment of each faculty account. Same guarded
// ALTER pattern as last_activity above, so existing installs pick the columns
// up the first time this page loads.
foreach (['program', 'position'] as $newCol) {
    if (!in_array($newCol, $adminColumns)) {
        try {
            $main_conn->exec("ALTER TABLE admin_list ADD COLUMN {$newCol} TEXT DEFAULT NULL");
        } catch (Throwable $e) {
            error_log("admin/faculty: could not add {$newCol} to admin_list - " . $e->getMessage());
        }
    }
}

$facultyList = [];
$qry = $main_conn->query("SELECT admin_id, fullname, username, role, allowed_pages, status, date_created, last_activity, program, position FROM admin_list WHERE role IN ('faculty','admin') AND admin_id != 1 ORDER BY fullname ASC");
while ($row = $qry->fetchArray(SQLITE3_ASSOC)) {
    $row['allowed_pages'] = !empty($row['allowed_pages']) ? json_decode($row['allowed_pages'], true) : [];
    $row['is_logged_in'] = !empty($row['last_activity']) && (time() - strtotime($row['last_activity'] . ' UTC')) < 300;
    $facultyList[] = $row;
}
$totalFaculty = count($facultyList);
$onlineCount = count(array_filter($facultyList, fn($f) => $f['is_logged_in']));
$offlineCount = $totalFaculty - $onlineCount;
?>

<style>
.faculty-page .horizon-stat-card{border:0;border-radius:16px;padding:18px;position:relative;overflow:hidden}
.faculty-page .horizon-stat-card::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 85% 20%, rgba(255,255,255,.18), transparent 45%);pointer-events:none}
.faculty-page .stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.faculty-page .directory-card{border:0;border-radius:16px;overflow:hidden}
.faculty-page .directory-card .card-header{border-bottom:1px solid var(--horizon-card-border);padding:16px 20px}
.faculty-page .directory-card .card-body{padding:0}
.faculty-page .directory-card .dataTables_wrapper{padding:16px 16px 8px}
.faculty-page .directory-card .dataTables_filter input{border-radius:8px;border:1px solid var(--horizon-card-border);padding:6px 12px}
.faculty-page .directory-card .dataTables_length select{border-radius:8px;padding:4px 28px 4px 10px}
.faculty-page .table thead th{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:700;white-space:nowrap;border-bottom-width:1px}
.faculty-page .table tbody td{font-size:.88rem;vertical-align:middle}
.faculty-page .avatar-sm{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0}
.faculty-page .module-badge{font-size:.68rem;letter-spacing:.02em}
.faculty-page .accordion-button{font-weight:600;font-size:.88rem;padding:10px 14px}
.faculty-page .accordion-button:not(.collapsed){background:var(--horizon-bg-main);color:var(--horizon-text-title)}
.faculty-page #form-alert{font-size:.85rem}
.faculty-page .modal-section-title{font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--horizon-text-muted);margin:0 0 .65rem}
.faculty-page #facultyModal .modal-body{max-height:70vh;overflow-y:auto}
.faculty-page #pageAccordion .accordion-body{max-height:300px;overflow-y:auto}
html[data-theme="dark"] .faculty-page .table thead th{color:var(--horizon-text-muted)}
html[data-theme="dark"] .faculty-page .dataTables_wrapper{color:var(--horizon-text-muted)}
</style>

<div class="container-fluid px-0 faculty-page">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="letter-spacing:-.02em"><i class="fas fa-chalkboard-teacher text-primary me-2"></i>Faculty Management</h4>
            <p class="text-muted small mb-0">Create credentials, assign module access and monitor faculty activity.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="./?page=admin/monitoring" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                <i class="fas fa-chart-line me-1"></i> Monitor Faculty
            </a>
            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" id="add-faculty-btn">
                <i class="fas fa-user-plus me-1"></i> Add Faculty
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="horizon-stat-card shadow-sm" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:#fff">
                <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
                    <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-users"></i></div>
                    <div><div class="fw-bold fs-4 lh-1"><?php echo $totalFaculty; ?></div><small class="opacity-75">Total Faculty</small></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="horizon-stat-card shadow-sm" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color:#fff">
                <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
                    <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-signal"></i></div>
                    <div><div class="fw-bold fs-4 lh-1"><?php echo $onlineCount; ?></div><small class="opacity-75">Online Now</small></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="horizon-stat-card shadow-sm" style="background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%); color:#fff">
                <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
                    <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-moon"></i></div>
                    <div><div class="fw-bold fs-4 lh-1"><?php echo $offlineCount; ?></div><small class="opacity-75">Offline</small></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Directory (full width; the form now lives in a modal) -->
    <div class="row g-4 align-items-start">
        <div class="col-12">
            <div class="card shadow-sm directory-card">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-users text-primary me-2"></i>Faculty Directory</h6>
                    <small class="text-muted"><?php echo $totalFaculty; ?> account<?php echo $totalFaculty!==1?'s':''; ?> • live status refreshes every 60s</small>
                </div>
                <div class="card-body">
                    <table id="facultyTable" class="table table-hover align-middle mb-0 w-100">
                        <thead>
                            <tr>
                                <th style="width:42px" class="text-center">#</th>
                                <th>Faculty</th>
                                <th>Username</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Modules</th>
                                <th style="width:96px" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facultyList as $idx => $faculty): ?>
                            <tr>
                                <td class="text-center text-muted small"><?php echo $idx+1; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar-sm" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($faculty['fullname'],0,1))); ?></span>
                                        <div><div class="fw-semibold" style="font-size:.88rem"><?php echo htmlspecialchars($faculty['fullname']); ?></div><small class="text-muted"><?php echo date('M d, Y', strtotime($faculty['date_created'])); ?></small></div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark border fw-normal"><?php echo htmlspecialchars($faculty['username']); ?></span></td>
                                <td>
                                    <?php $fProgram = trim((string)($faculty['program'] ?? '')); ?>
                                    <?php if ($fProgram !== ''): ?>
                                        <span class="badge rounded-pill bg-info bg-opacity-10 text-info border" title="<?php echo htmlspecialchars($programLabels[$fProgram]['full'] ?? $fProgram, ENT_QUOTES); ?>"><?php echo htmlspecialchars($fProgram); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $fPosition = trim((string)($faculty['position'] ?? '')); ?>
                                    <?php if ($fPosition !== ''): ?>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success border"><?php echo htmlspecialchars($fPosition); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($faculty['role'] ?? 'faculty') === 'admin'): ?>
                                        <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning"><i class="fas fa-user-shield me-1"></i>Admin</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border"><i class="fas fa-chalkboard-teacher me-1"></i>Faculty</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($faculty['is_logged_in']): ?>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-circle me-1" style="font-size:7px; vertical-align:middle"></i>Online</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary border"><i class="fas fa-circle me-1" style="font-size:7px; vertical-align:middle"></i>Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $pages=$faculty['allowed_pages']; if(empty($pages) && ($faculty['role'] ?? 'faculty') === 'admin'): ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning border module-badge">Full Access</span>
                                    <?php elseif(empty($pages)): ?>
                                        <span class="badge bg-light text-muted border module-badge">No Access</span>
                                    <?php else: foreach(array_slice($pages,0,4) as $k): $lbl=$availablePages[$k]['label']??$k; ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border module-badge me-1 mb-1"><?php echo htmlspecialchars($lbl); ?></span>
                                    <?php endforeach; if(count($pages)>4): ?>
                                        <button type="button" class="badge bg-light text-muted border module-badge view-modules" data-name="<?php echo htmlspecialchars($faculty['fullname'], ENT_QUOTES); ?>" data-allowed='<?php echo htmlspecialchars(json_encode($faculty['allowed_pages']), ENT_QUOTES); ?>' style="cursor:pointer">+<?php echo count($pages)-4; ?> more</button>
                                    <?php endif; endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary edit-faculty" title="Edit"
                                            data-id="<?php echo $faculty['admin_id']; ?>"
                                            data-fullname="<?php echo htmlspecialchars($faculty['fullname'], ENT_QUOTES); ?>"
                                            data-username="<?php echo htmlspecialchars($faculty['username'], ENT_QUOTES); ?>"
                                            data-role="<?php echo htmlspecialchars($faculty['role'] ?? 'faculty', ENT_QUOTES); ?>"
                                            data-program="<?php echo htmlspecialchars($faculty['program'] ?? '', ENT_QUOTES); ?>"
                                            data-position="<?php echo htmlspecialchars($faculty['position'] ?? '', ENT_QUOTES); ?>"
                                            data-allowed='<?php echo htmlspecialchars(json_encode($faculty['allowed_pages']), ENT_QUOTES); ?>'><i class="fas fa-pen"></i></button>
                                        <button type="button" class="btn btn-outline-danger delete-faculty" title="Delete" data-id="<?php echo $faculty['admin_id']; ?>" data-name="<?php echo htmlspecialchars($faculty['fullname'], ENT_QUOTES); ?>"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Faculty form modal -->
<div class="modal fade faculty-page" id="facultyModal" tabindex="-1" aria-labelledby="facultyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden">
            <form id="faculty-form">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title mb-0 fw-bold" id="facultyModalLabel"><i class="fas fa-user-plus text-primary me-2"></i><span id="form-title">New Faculty</span></h6>
                        <small class="text-muted">Credentials, assignment &amp; module access</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="form-alert" class="alert d-none"></div>
                    <input type="hidden" name="id" value="">
                    <input type="hidden" name="role" id="role" value="faculty">
                    <p class="modal-section-title">Account credentials</p>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="fullname" class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-id-card text-muted"></i></span>
                                <input type="text" class="form-control" name="fullname" id="fullname" placeholder="Juan Dela Cruz" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="username" class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-at text-muted"></i></span>
                                <input type="text" class="form-control" name="username" id="username" required autocomplete="off" placeholder="jdelacruz">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label small fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-key text-muted"></i></span>
                                <input type="password" class="form-control" name="password" id="password" placeholder="Leave blank to keep current" autocomplete="new-password">
                            </div>
                            <small class="text-muted">Required for new accounts only.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="school_year" class="form-label small fw-semibold">School Year</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar text-muted"></i></span>
                                <input type="text" class="form-control" name="school_year" id="school_year" value="<?php $cm=date('n'); $cy=date('Y'); echo $cm>=7 ? "$cy-".($cy+1) : ($cy-1)."-$cy"; ?>" readonly>
                            </div>
                        </div>
                    </div>
                    <p class="modal-section-title">Department &amp; position</p>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="program" class="form-label small fw-semibold">Department <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-graduation-cap text-muted"></i></span>
                                <select class="form-select" name="program" id="program" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($programLabels as $code => $meta): ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($meta['full'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($code . ' - ' . $meta['short']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="position" class="form-label small fw-semibold">Position <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user-tie text-muted"></i></span>
                                <select class="form-select" name="position" id="position" required>
                                    <option value="">Select Position</option>
                                    <?php foreach ($positionOptions as $position): ?>
                                    <option value="<?php echo htmlspecialchars($position, ENT_QUOTES); ?>"><?php echo htmlspecialchars($position); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-1" id="module-access-wrap">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <p class="modal-section-title mb-0">Module access <span class="text-danger">*</span></p>
                            <div>
                                <button class="btn btn-link btn-sm p-0 me-2" type="button" id="select-all-pages">Select all</button>
                                <button class="btn btn-link btn-sm p-0 text-danger" type="button" id="clear-pages">Clear</button>
                            </div>
                        </div>
                        <div class="accordion" id="pageAccordion">
                            <?php foreach ($groupedPages as $group => $pages): $collapseId='group-'.preg_replace('/\s+/', '-', strtolower($group)); ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="heading-<?php echo $collapseId; ?>">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
                                        <i class="fas fa-folder me-2 text-muted"></i><?php echo htmlspecialchars($group); ?> <span class="badge bg-light text-muted border ms-2 group-count">0</span>
                                    </button>
                                </h2>
                                <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse" data-bs-parent="#pageAccordion">
                                    <div class="accordion-body p-2">
                                        <div class="row g-2">
                                            <?php foreach ($pages as $key => $page): ?>
                                            <div class="col-md-6">
                                                <label class="form-check rounded border p-2 d-flex gap-2 mb-0 h-100" style="cursor:pointer">
                                                    <input class="form-check-input mt-1" type="checkbox" value="<?php echo htmlspecialchars($key); ?>" id="page-<?php echo htmlspecialchars($key); ?>" name="allowed_pages[]" data-page-key="<?php echo htmlspecialchars($key); ?>">
                                                    <span><i class="fas <?php echo $page['icon']; ?> text-muted me-1"></i><strong style="font-size:.85rem"><?php echo htmlspecialchars($page['label']); ?></strong><br><small class="text-muted" style="font-size:.75rem"><?php echo htmlspecialchars($page['description']); ?></small></span>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                    <button class="btn btn-light rounded-pill px-3" id="reset-form" type="button"><i class="fas fa-undo me-1"></i>Reset</button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4" id="save-faculty"><i class="fas fa-save me-2"></i><span>Save Faculty</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade faculty-page" id="deleteFacultyModal" tabindex="-1" aria-labelledby="deleteFacultyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden">
            <div class="modal-header">
                <h6 class="modal-title mb-0 fw-bold" id="deleteFacultyModalLabel"><i class="fas fa-trash text-danger me-2"></i>Delete Faculty</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete faculty account <strong id="delete-faculty-name"></strong>? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger rounded-pill px-4" id="confirm-delete-faculty"><i class="fas fa-trash me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- All-modules viewer modal -->
<div class="modal fade faculty-page" id="modulesModal" tabindex="-1" aria-labelledby="modulesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title mb-0 fw-bold" id="modulesModalLabel"><i class="fas fa-th-large text-primary me-2"></i>Module Access</h6>
                    <small class="text-muted" id="modules-modal-name"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modules-modal-list" class="d-flex flex-wrap gap-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function(){
    const $form = $('#faculty-form');
    const $alert = $('#form-alert');
    const $username = $('#username');
    const $modal = $('#facultyModal');
    const pageKeys = <?php echo json_encode(array_keys($availablePages)); ?>;
    const pageLabels = <?php echo json_encode(array_map(function($p){ return $p['label']; }, $availablePages)); ?>;
    const showModal = () => { try { bootstrap.Modal.getOrCreateInstance($modal[0]).show(); } catch(e){ $modal.modal('show'); } };
    const hideModal = () => { try { bootstrap.Modal.getOrCreateInstance($modal[0]).hide(); } catch(e){ $modal.modal('hide'); } };

    const showAlert = (type, message) => {
        $alert.removeClass('d-none alert-success alert-danger').addClass(`alert alert-${type}`);
        $alert.text(message);
    };
    const hideAlert = () => { $alert.addClass('d-none').removeClass('alert alert-success alert-danger').text(''); };
    const updateGroupCounts = () => {
        $('#pageAccordion .accordion-item').each(function(){
            const total = $(this).find('input[name="allowed_pages[]"]').length;
            const checked = $(this).find('input[name="allowed_pages[]"]:checked').length;
            $(this).find('.group-count').text(checked + '/' + total);
        });
    };
    const setCheckboxes = (pages = []) => {
        $('input[name="allowed_pages[]"]').prop('checked', false);
        const uniquePages = pages && pages.length ? pages : pageKeys;
        uniquePages.forEach(page => { $(`input[data-page-key="${page}"]`).prop('checked', true); });
        updateGroupCounts();
    };
    // Position rule: a plain Faculty account never carries Administration
    // modules. Whenever Faculty is the selected position, every checkbox in
    // the Administration group is unchecked automatically.
    const applyPositionModuleRule = () => {
        if ($('#position').val() !== 'Faculty') return;
        $('#pageAccordion .accordion-item').filter(function(){
            return $(this).find('.accordion-button').first().text().trim().indexOf('Administration') === 0;
        }).find('input[name="allowed_pages[]"]').prop('checked', false);
        updateGroupCounts();
    };
    const resetForm = () => {
        hideAlert(); $form.get(0).reset(); $form.find('input[name="id"]').val('');
        $('#role').val('faculty');
        $username.prop('readonly', false); setCheckboxes();
        $('#save-faculty span').text('Save Faculty'); $('#form-title').text('New Faculty');
    };

    // DataTables
    const dt = $('#facultyTable').DataTable({
        responsive: true,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        order: [[1, 'asc']],
        columnDefs: [
            { orderable: false, targets: [8] },
            { searchable: false, targets: [0, 6, 7, 8] }
        ],
        language: {
            search: "",
            searchPlaceholder: "Search faculty, username…",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_–_END_ of _TOTAL_",
            infoEmpty: "No faculty accounts",
            emptyTable: "No faculty accounts yet.",
            zeroRecords: "No matching faculty found.",
            paginate: { previous: "‹", next: "›" }
        },
        dom: '<"row g-2 mb-3"<"col-sm-6"l><"col-sm-6"f>>rt<"row mt-3"<"col-sm-6"i><"col-sm-6"p>>'
    });
    // Bootstrap style tweaks for DataTables controls
    $('.dataTables_filter input').addClass('form-control form-control-sm');
    $('.dataTables_length select').addClass('form-select form-select-sm d-inline-block w-auto');

    setCheckboxes();
    updateGroupCounts();
    $(document).on('change', 'input[name="allowed_pages[]"]', updateGroupCounts);
    $('#position').on('change', applyPositionModuleRule);
    $('#reset-form').on('click', resetForm);
    $('#select-all-pages').on('click', function(){ $('input[name="allowed_pages[]"]').prop('checked', true); updateGroupCounts(); });
    $('#clear-pages').on('click', function(){ $('input[name="allowed_pages[]"]').prop('checked', false); updateGroupCounts(); });

    $('#add-faculty-btn').on('click', function(){ resetForm(); showModal(); });
    $modal.on('hidden.bs.modal', function(){ hideAlert(); });

    $form.on('submit', function(e){
        e.preventDefault(); hideAlert();
        $('#role').val('faculty');
        if($('input[name="allowed_pages[]"]:checked').length === 0){
            showAlert('danger', 'Select at least one module the faculty can access.'); return;
        }
        // Fallback validation - Department/Position carry `required`, so a normal click
        // is already stopped by the browser and this only runs when the form is
        // submitted programmatically (Enter on a text input, form.submit()).
        const missing = [];
        if(!$.trim($('#fullname').val())) missing.push('Full Name');
        if(!$.trim($username.val())) missing.push('Username');
        if(!$('#program').val()) missing.push('Department');
        if(!$('#position').val()) missing.push('Position');
        if(missing.length){
            showAlert('danger', 'Please provide a ' + missing.join(', ') + '.'); return;
        }
        const schoolYear = $('#school_year').val();
        const formDataWithoutSchoolYear = $form.find('input, select, textarea').not('[name="school_year"]').serialize();
        const $btn = $('#save-faculty'); $btn.prop('disabled', true); $btn.find('span').text('Saving...');
        $.ajax({
            url: './Actions.php?a=save_user', method: 'POST', data: formDataWithoutSchoolYear, dataType: 'json',
            success: function(resp) {
                if(resp.status === 'success'){
                    showAlert('success', resp.msg || 'Faculty saved successfully.');
                    if(resp.msg.includes('New Admin User successfully saved')){
                        $.ajax({
                            url: './Actions.php?a=provision_database_with_year', method: 'POST',
                            data: { school_year: schoolYear, username: $('#username').val() }, dataType: 'json',
                            success: function(p){ if(p.status !== 'success') console.warn('Provisioning warning:', p.msg); setTimeout(()=>location.reload(), 500); },
                            error: function(){ console.warn('Provisioning failed'); setTimeout(()=>location.reload(), 500); }
                        });
                    } else { setTimeout(()=>location.reload(), 800); }
                } else { showAlert('danger', resp.msg || 'Unable to save faculty.'); $btn.prop('disabled', false); $btn.find('span').text($form.find('input[name="id"]').val() ? 'Update Faculty' : 'Save Faculty'); }
            },
            error: function(err){ console.error(err); showAlert('danger', 'An unexpected error occurred.'); $btn.prop('disabled', false); $btn.find('span').text($form.find('input[name="id"]').val() ? 'Update Faculty' : 'Save Faculty'); }
        });
    });

    $(document).on('click', '.edit-faculty', function(){
        const data = $(this).data(); hideAlert();
        $form.find('input[name="id"]').val(data.id);
        $('#fullname').val(data.fullname);
        $username.val(data.username).prop('readonly', true);
        $('#password').val('');
        $('#role').val('faculty');
        $('#program').val(data.program || '');
        $('#position').val(data.position || '');
        try {
            let allowed = JSON.parse(data.allowed || '[]');
            if (!allowed || !allowed.length) { allowed = pageKeys; }
            setCheckboxes(allowed);
        } catch(e){ setCheckboxes(); }
        applyPositionModuleRule();
        $('#save-faculty span').text('Update Faculty'); $('#form-title').text('Edit Faculty');
        showModal();
    });

    let pendingDeleteId = null;
    $(document).on('click', '.delete-faculty', function(){
        pendingDeleteId = $(this).data('id');
        $('#delete-faculty-name').text($(this).data('name') || '');
        try { bootstrap.Modal.getOrCreateInstance($('#deleteFacultyModal')[0]).show(); } catch(e){ $('#deleteFacultyModal').modal('show'); }
    });
    $('#confirm-delete-faculty').on('click', function(){
        if(!pendingDeleteId) return;
        const $btn = $(this); $btn.prop('disabled', true);
        $.ajax({
            url: './Actions.php?a=delete_user', method: 'POST', data: { id: pendingDeleteId }, dataType: 'json',
            error: err => { console.error(err); alert('Unable to delete faculty at the moment.'); $btn.prop('disabled', false); },
            success: resp => { if(resp.status === 'success') location.reload(); else { alert(resp.msg || 'Failed to delete faculty.'); $btn.prop('disabled', false); } }
        });
    });
    $('#deleteFacultyModal').on('hidden.bs.modal', function(){ pendingDeleteId = null; $('#confirm-delete-faculty').prop('disabled', false); });

    $(document).on('click', '.view-modules', function(){
        const name = $(this).data('name') || 'Faculty';
        let allowed = [];
        try { allowed = JSON.parse($(this).attr('data-allowed') || '[]'); } catch(e){ allowed = []; }
        $('#modules-modal-name').text(name);
        const $list = $('#modules-modal-list').empty();
        if (!allowed.length) { $list.append('<span class="text-muted small">No modules assigned.</span>'); }
        allowed.forEach(function(k){
            const lbl = (pageLabels && pageLabels[k]) ? pageLabels[k] : k;
            $list.append('<span class="badge bg-primary bg-opacity-10 text-primary border module-badge">' + $('<div>').text(lbl).html() + '</span>');
        });
        try { bootstrap.Modal.getOrCreateInstance($('#modulesModal')[0]).show(); } catch(e){ $('#modulesModal').modal('show'); }
    });
});
</script>
