<?php
/**
 * Departments & Courses (Administration).
 *
 * Maintains the shared catalog that feeds the Department dropdown on
 * admin/faculty.php and the Course dropdown on landingpage.php. Writes go to
 * Actions.php; both tables live in the main database (see departments.php).
 */
require_once(__DIR__ . '/../departments.php');

$catalog = department_catalog();
$departmentOptions = department_dropdown_options();

// Department whose courses are listed in the right-hand panel. Keep the
// current one across reloads (?dept=CODE) so a save doesn't reset the view.
$activeCode = isset($_GET['dept']) ? strtoupper(trim((string)$_GET['dept'])) : '';
if (!isset($catalog[$activeCode])) {
    $keys = array_keys($catalog);
    $activeCode = $keys ? $keys[0] : '';
}
$totalCourses = 0;
foreach ($catalog as $dept) {
    $totalCourses += count($dept['courses']);
}
?>

<style>
.dept-page .horizon-stat-card{border:0;border-radius:16px;padding:18px;position:relative;overflow:hidden}
.dept-page .horizon-stat-card::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 85% 20%, rgba(255,255,255,.18), transparent 45%);pointer-events:none}
.dept-page .stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.dept-page .form-card{border:0;border-radius:16px;overflow:hidden}
.dept-page .form-card .card-header{border-bottom:1px solid var(--horizon-card-border);padding:16px 20px}
.dept-page .table thead th{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:700;white-space:nowrap;border-bottom-width:1px}
.dept-page .table tbody td{font-size:.88rem;vertical-align:middle}
.dept-page .code-chip{font-family:var(--bs-font-monospace,monospace);letter-spacing:.04em}
.dept-page .course-chip{font-size:.75rem;letter-spacing:.02em}
.dept-page .empty-state{color:var(--horizon-text-muted)}
html[data-theme="dark"] .dept-page .table thead th{color:var(--horizon-text-muted)}
</style>

<div class="container-fluid px-0 dept-page">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="letter-spacing:-.02em"><i class="fas fa-sitemap text-primary me-2"></i>Departments &amp; Courses</h4>
            <p class="text-muted small mb-0">Add the college units and the courses they offer. These lists populate the Department dropdown on Faculty Management and the Course dropdown on the student registration page.</p>
        </div>
        <a href="./?page=admin/faculty" class="btn btn-sm btn-outline-primary rounded-pill px-3">
            <i class="fas fa-chalkboard me-1"></i> Faculty Accounts
        </a>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="horizon-stat-card shadow-sm" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:#fff">
                <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
                    <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-sitemap"></i></div>
                    <div><div class="fw-bold fs-4 lh-1"><?php echo count($catalog); ?></div><small class="opacity-75">Departments</small></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="horizon-stat-card shadow-sm" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color:#fff">
                <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
                    <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-book"></i></div>
                    <div><div class="fw-bold fs-4 lh-1"><?php echo $totalCourses; ?></div><small class="opacity-75">Courses Listed</small></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="horizon-stat-card shadow-sm" style="background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%); color:#fff">
                <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
                    <div class="stat-icon" style="background:rgba(255,255,255,.18)"><i class="fas fa-list-check"></i></div>
                    <div><div class="fw-bold fs-4 lh-1"><?php echo $activeCode !== '' ? count($catalog[$activeCode]['courses']) : 0; ?></div><small class="opacity-75">In <?php echo htmlspecialchars($activeCode !== '' ? $activeCode : '—'); ?></small></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 align-items-start">
        <!-- Department form + list -->
        <div class="col-lg-5">
            <div class="card shadow-sm form-card mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <div><h6 class="mb-0 fw-bold"><i class="fas fa-plus-circle text-primary me-2"></i><span id="dept-form-title">New Department</span></h6><small class="text-muted">Code and full college name</small></div>
                    <button class="btn btn-light btn-sm rounded-pill" id="dept-reset" type="button"><i class="fas fa-undo me-1"></i>Reset</button>
                </div>
                <div class="card-body">
                    <div id="dept-alert" class="alert d-none"></div>
                    <form id="department-form" autocomplete="off">
                        <input type="hidden" name="original_code" id="dept-original" value="">
                        <div class="mb-3">
                            <label for="dept-code" class="form-label small fw-semibold">Code <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-hashtag text-muted"></i></span>
                                <input type="text" class="form-control" name="dept_code" id="dept-code" required maxlength="10" placeholder="CCS">
                            </div>
                            <small class="text-muted">Short code stored with each account — e.g. CCS.</small>
                        </div>
                        <div class="mb-3">
                            <label for="dept-name" class="form-label small fw-semibold">Department Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-building-columns text-muted"></i></span>
                                <input type="text" class="form-control" name="dept_name" id="dept-name" required maxlength="120" placeholder="College of Computer Studies">
                            </div>
                            <small class="text-muted">Shown as the tooltip and short label in the dropdowns.</small>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary rounded-pill py-2" id="save-department"><i class="fas fa-save me-2"></i><span>Save Department</span></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Courses of the selected department -->
        <div class="col-lg-7">
            <div class="card shadow-sm form-card">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h6 class="mb-0 fw-bold"><i class="fas fa-book text-primary me-2"></i>Courses</h6>
                        <small class="text-muted">
                            <?php if ($activeCode !== ''): ?>
                                Offered by <strong><?php echo htmlspecialchars($activeCode); ?> — <?php echo htmlspecialchars($catalog[$activeCode]['name']); ?></strong>
                            <?php else: ?>
                                Select a department to manage its courses.
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php if ($activeCode !== ''): ?>
                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success border"><?php echo count($catalog[$activeCode]['courses']); ?> listed</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div id="course-alert" class="alert d-none"></div>
                    <?php if ($activeCode === ''): ?>
                        <div class="empty-state text-center py-4"><i class="fas fa-sitemap fa-2x mb-2 d-block"></i>Add a department first.</div>
                    <?php else: ?>
                    <form id="course-form" autocomplete="off" class="mb-3">
                        <input type="hidden" name="dept_code" id="course-dept" value="<?php echo htmlspecialchars($activeCode, ENT_QUOTES); ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label for="course-dept-select" class="form-label small fw-semibold">Department</label>
                                <select class="form-select form-select-sm" id="course-dept-select">
                                    <?php foreach ($departmentOptions as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" <?php echo $code === $activeCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="course-name" class="form-label small fw-semibold">Course <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="course" id="course-name" required maxlength="30" placeholder="BSIT">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary btn-sm w-100" id="save-course"><i class="fas fa-plus me-1"></i>Add Course</button>
                            </div>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 w-100">
                            <thead>
                                <tr>
                                    <th style="width:60px" class="text-center">#</th>
                                    <th>Course</th>
                                    <th style="width:60px" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($catalog[$activeCode]['courses'])): ?>
                                    <tr><td colspan="3" class="text-center empty-state py-4">No courses listed for <?php echo htmlspecialchars($activeCode); ?> yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($catalog[$activeCode]['courses'] as $i => $course): ?>
                                <tr>
                                    <td class="text-center text-muted small"><?php echo $i + 1; ?></td>
                                    <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border course-chip"><?php echo htmlspecialchars($course); ?></span></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-course" title="Delete"
                                            data-id="<?php echo (int)($catalog[$activeCode]['course_ids'][$i] ?? 0); ?>"
                                            data-course="<?php echo htmlspecialchars($course, ENT_QUOTES); ?>"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Department list (full width so the college names never wrap) -->
    <div class="row g-4 mt-0">
        <div class="col-12">
            <div class="card shadow-sm form-card">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-list text-primary me-2"></i>Department List</h6>
                    <small class="text-muted">Pick <i class="fas fa-list-ul"></i> to manage a department's courses.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 w-100">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th style="width:90px" class="text-center">Courses</th>
                                    <th style="width:120px" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($catalog)): ?>
                                    <tr><td colspan="3" class="text-center empty-state py-4">No departments yet — add the first one.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($catalog as $code => $dept): ?>
                                <tr class="<?php echo $code === $activeCode ? 'table-active' : ''; ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border code-chip"><?php echo htmlspecialchars($code); ?></span>
                                            <span><?php echo htmlspecialchars($dept['name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center"><?php echo count($dept['courses']); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a class="btn btn-outline-secondary" href="./?page=admin/departments&dept=<?php echo urlencode($code); ?>" title="Show courses"><i class="fas fa-list-ul"></i></a>
                                            <button type="button" class="btn btn-outline-primary edit-department" title="Edit"
                                                data-code="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>"
                                                data-name="<?php echo htmlspecialchars($dept['name'], ENT_QUOTES); ?>"><i class="fas fa-pen"></i></button>
                                            <button type="button" class="btn btn-outline-danger delete-department" title="Delete"
                                                data-code="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>"
                                                data-name="<?php echo htmlspecialchars($dept['name'], ENT_QUOTES); ?>"
                                                data-courses="<?php echo count($dept['courses']); ?>"><i class="fas fa-trash"></i></button>
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
</div>

<script>
$(function(){
    const $deptForm = $('#department-form');
    const $courseForm = $('#course-form');
    const $deptAlert = $('#dept-alert');
    const $courseAlert = $('#course-alert');

    const showAlert = ($box, type, message) => {
        $box.removeClass('d-none alert-success alert-danger').addClass(`alert alert-${type}`).text(message);
    };
    const hideAlert = ($box) => { $box.addClass('d-none').removeClass('alert alert-success alert-danger').text(''); };

    const resetDepartmentForm = () => {
        hideAlert($deptAlert);
        $deptForm.get(0).reset();
        $('#dept-original').val('');
        $('#dept-form-title').text('New Department');
        $('#save-department span').text('Save Department');
    };

    // ---- department: save -------------------------------------------------
    $deptForm.on('submit', function(e){
        e.preventDefault(); hideAlert($deptAlert);
        const $btn = $('#save-department');
        $btn.prop('disabled', true).find('span').text('Saving...');
        $.ajax({
            url: './Actions.php?a=save_department', method: 'POST', data: $(this).serialize(), dataType: 'json',
            success: function(resp){
                if(resp.status === 'success'){
                    showAlert($deptAlert, 'success', resp.msg);
                    setTimeout(() => location.reload(), 600);
                } else {
                    showAlert($deptAlert, 'danger', resp.msg || 'Unable to save the department.');
                    $btn.prop('disabled', false).find('span').text('Save Department');
                }
            },
            error: function(err){ console.error(err); showAlert($deptAlert, 'danger', 'An unexpected error occurred.'); $btn.prop('disabled', false).find('span').text('Save Department'); }
        });
    });

    $('#dept-reset').on('click', resetDepartmentForm);

    $('.edit-department').on('click', function(){
        const data = $(this).data(); hideAlert($deptAlert);
        $('#dept-original').val(data.code);
        $('#dept-code').val(data.code).trigger('focus');
        $('#dept-name').val(data.name);
        $('#dept-form-title').text('Edit Department');
        $('#save-department span').text('Update Department');
        $('html, body').animate({ scrollTop: 0 }, 300);
    });

    $('.delete-department').on('click', function(){
        const code = $(this).data('code');
        const name = $(this).data('name');
        const courses = $(this).data('courses');
        if(!confirm(`Delete department "${code} - ${name}" and its ${courses} course(s)? Faculty accounts are never deleted. Department assignments pointing at ${code} must be cleared first.`)) return;
        $.ajax({
            url: './Actions.php?a=delete_department', method: 'POST', data: { dept_code: code }, dataType: 'json',
            success: function(resp){
                if(resp.status === 'success') location.reload();
                else alert(resp.msg || 'Failed to delete department.');
            },
            error: function(err){ console.error(err); alert('Unable to delete the department at the moment.'); }
        });
    });

    // ---- courses ---------------------------------------------------------
    $('#course-dept-select').on('change', function(){
        const code = $(this).val();
        if(code) location.href = './?page=admin/departments&dept=' + encodeURIComponent(code);
    });

    $courseForm.on('submit', function(e){
        e.preventDefault(); hideAlert($courseAlert);
        const $btn = $('#save-course');
        $btn.prop('disabled', true);
        $.ajax({
            url: './Actions.php?a=save_department_course', method: 'POST', data: $(this).serialize(), dataType: 'json',
            success: function(resp){
                if(resp.status === 'success'){
                    showAlert($courseAlert, 'success', resp.msg);
                    $('#course-name').val('');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showAlert($courseAlert, 'danger', resp.msg || 'Unable to add the course.');
                    $btn.prop('disabled', false);
                }
            },
            error: function(err){ console.error(err); showAlert($courseAlert, 'danger', 'An unexpected error occurred.'); $btn.prop('disabled', false); }
        });
    });

    $('.delete-course').on('click', function(){
        const id = $(this).data('id');
        const course = $(this).data('course');
        if(!id || !confirm(`Remove course "${course}"?`)) return;
        $.ajax({
            url: './Actions.php?a=delete_department_course', method: 'POST', data: { course_id: id }, dataType: 'json',
            success: function(resp){
                if(resp.status === 'success') location.reload();
                else alert(resp.msg || 'Failed to delete the course.');
            },
            error: function(err){ console.error(err); alert('Unable to delete the course at the moment.'); }
        });
    });
});
</script>