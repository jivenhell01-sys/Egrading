<?php
// Landing registrations manager (admin-only). Reads/writes the landing
// page's own database - never any faculty/admin user database.
if (!function_exists('landing_db')) {
    require_once("landing_db.php");
}
$landing_conn = landing_db();
// Resolve fully-handled irregulars to enrolled first, so the Pending/Enrolled
// cards clear instead of sticking on assigned students (only irregular rows
// are scanned; per-class pending logic never depends on this flag for them).
if (!function_exists('landing_reconcile_irregular_status')) {
    require_once("landing_irregular.php");
}
try { landing_reconcile_irregular_status($landing_conn); } catch (Exception $e) {}
$landing_rows = []; // folders load via get_landing_folders.php; pending counts ride on the folders
// Helper: numeric year value (1..6) -> "1st Year" label; keep existing "1st Year" as-is
if (!function_exists('landing_year_label')) {
    function landing_year_label($v) {
        $t = trim((string)$v);
        if ($t === '') return '';
        if (preg_match('/^\d+$/', $t)) {
            $n = (int)$t;
            if ($n === 1) return '1st Year';
            if ($n === 2) return '2nd Year';
            if ($n === 3) return '3rd Year';
            return $n . 'th Year';
        }
        if (stripos($t, 'year') !== false) return $t;
        if (preg_match('/^\d+(st|nd|rd|th)$/i', $t)) return $t . ' Year';
        return $t;
    }
}
// Year options from landing DB for assign modal
$yearOptions = [];
try {
    $yr_qry = $landing_conn->query("SELECT DISTINCT year FROM landing_registrations WHERE year IS NOT NULL AND TRIM(year) != '' ORDER BY CAST(year AS INTEGER) ASC, year ASC");
    if ($yr_qry) {
        while ($row = $yr_qry->fetchArray(SQLITE3_ASSOC)) {
            $yearOptions[] = $row['year'];
        }
    }
} catch (Exception $e) {}
// College Dean / Program Head see only their own department's registrations
// and may only assign to faculty of that same department.
if (!function_exists('session_course_scope')) {
    require_once("departments.php");
}
$lrScope = session_course_scope();
// Faculty options from main DB for assign modal
$facultyOptions = [];
$facultyScopeNote = '';
try {
    $main_db_path = __DIR__ . '/db/grading_db.db';
    $mconn = new SQLite3($main_db_path);
    $mconn->enableExceptions(true);
    $mconn->exec("PRAGMA busy_timeout = 5000");
    $where = "WHERE role = 'faculty' AND status = 1";
    if (!empty($lrScope['scoped'])) {
        $hasProgram = false;
        $col_qry = $mconn->query("PRAGMA table_info(admin_list)");
        while ($col = $col_qry->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'program') { $hasProgram = true; break; }
        }
        if ($hasProgram) {
            // Same department only. An empty department code matches nothing on
            // purpose - a Dean/Head without a department must not be able to
            // assign to every faculty member.
            $where .= " AND UPPER(TRIM(program)) = '" . $mconn->escapeString($lrScope['department']) . "'";
            $facultyScopeNote = 'Only faculty assigned to ' . $lrScope['department'] . ' are listed.';
        }
    }
    $f_qry = $mconn->query("SELECT admin_id, fullname FROM admin_list {$where} ORDER BY fullname ASC");
    if ($f_qry) {
        while ($frow = $f_qry->fetchArray(SQLITE3_ASSOC)) {
            $facultyOptions[] = $frow;
        }
    }
    $mconn->close();
} catch (Exception $e) {}
?>

<div class="container-fluid px-0" id="landing-registrations">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0 fw-bold">Landing Registrations</h4>
            <small class="text-muted">Student self-registrations from the public landing page (own database)</small>
        </div>
        <div class="d-flex gap-2">
            <a href="export_landing_excel.php" id="exportLandingBtn" class="btn btn-success btn-sm">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
            </a>
            <button type="button" class="btn btn-info btn-sm" id="importGradingBtn">
                <i class="fas fa-download me-1"></i> Import from Grading
            </button>
            <button type="button" class="btn btn-warning btn-sm" id="assignStudentsBtn">
                <i class="fas fa-share me-1"></i> Assign Students
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="landingAddBtn">
                <i class="fas fa-plus me-1"></i> Add Registration
            </button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (!empty($lrScope['scoped'])): ?>
            <div class="alert alert-<?php echo $lrScope['reason'] !== '' ? 'warning' : 'info'; ?> d-flex align-items-start gap-2">
                <i class="fas fa-filter mt-1"></i>
                <div>
                    <?php if ($lrScope['reason'] === 'no_department'): ?>
                        <strong>No department assigned.</strong>
                        You are signed in as <strong><?php echo htmlspecialchars($lrScope['position']); ?></strong>, so only your own department's registrations are shown.
                        Assign a department to your account (Faculty Accounts &rarr; Department) to start seeing students.
                    <?php elseif ($lrScope['reason'] === 'no_courses'): ?>
                        <strong>No courses configured.</strong>
                        Department <strong><?php echo htmlspecialchars($lrScope['department']); ?></strong> has no courses listed yet, so there is nothing to show.
                        Add its courses under Administration &rarr; Departments &amp; Courses.
                    <?php else: ?>
                        Showing registrations for
                        <strong><?php echo htmlspecialchars($lrScope['department'] . ($lrScope['department_name'] !== '' ? ' - ' . $lrScope['department_name'] : '')); ?></strong> only
                        <span class="text-muted">(<?php echo htmlspecialchars($lrScope['position']); ?>)</span>
                        &mdash; <?php echo count($lrScope['courses']); ?> course<?php echo count($lrScope['courses']) === 1 ? '' : 's'; ?>:
                        <span class="text-muted"><?php echo htmlspecialchars(implode(', ', $lrScope['courses'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="lr-promo-wrap mb-3">
                <button type="button" class="lr-promo-toggle" data-bs-toggle="collapse" data-bs-target="#lrPromoCollapse" aria-expanded="false" aria-controls="lrPromoCollapse">
                    <span class="lr-promo-toggle-icon"><i class="fas fa-calendar-alt"></i></span>
                    <span class="lr-promo-toggle-text">Academic Year Promotion<small>Promotion period, graduates &amp; year/section increment</small></span>
                    <span id="promoTabDot" class="lr-tab-dot d-none" title="Promotion period ended — action required">!</span>
                    <i class="fas fa-chevron-down lr-promo-chevron"></i>
                </button>
                <div class="collapse" id="lrPromoCollapse">
                    <div class="lr-promo-body">
            <div id="promoDueBanner" class="alert alert-warning d-none align-items-center justify-content-between mt-3" role="alert">
        <div><i class="fas fa-exclamation-triangle me-2"></i><strong>Promotion period ended.</strong> Please confirm incrementing year and section for all students.</div>
        <button type="button" class="btn btn-warning btn-sm" id="promoDueReviewBtn"><i class="fas fa-check me-1"></i>Review &amp; Confirm</button>
    </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div>
                    <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-alt me-1 text-primary"></i>Academic Year Promotion</h6>
                    <small class="text-muted">Set the duration. When it ends, confirm to move every student year + 1 and section + 1. Students reaching the graduate year below graduate to <code>db/graduate_db.db</code> instead.</small>
                </div>
                <span class="badge bg-secondary" id="promoStatusBadge">Not set</span>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="promoFrom">From</label>
                    <input type="date" id="promoFrom" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="promoTo">To</label>
                    <input type="date" id="promoTo" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="promoGradYear">Graduate at</label>
                    <select id="promoGradYear" class="form-select">
                        <option value="5">5th Year</option>
                        <option value="6">6th Year</option>
                        <option value="7">7th Year</option>
                    </select>
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="promoSaveBtn"><i class="fas fa-save me-1"></i>Save Period</button>
                    <button type="button" class="btn btn-primary btn-sm" id="promoPreviewBtn"><i class="fas fa-arrow-up me-1"></i>Preview &amp; Promote</button>
                    <button type="button" class="btn btn-success btn-sm d-none" id="promoGraduatesBtn"><i class="fas fa-graduation-cap me-1"></i>Graduates (<span id="promoGraduatesCount">0</span>)</button>
                </div>
            </div>
            <div id="promoInfo" class="small text-muted mt-2"></div>
                    </div>
                </div>
            </div>
            <!-- Breadcrumb + search + section actions -->
            <div class="lr-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb lr-breadcrumb mb-0" id="lrBreadcrumb">
                        <li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="root"><i class="fas fa-home me-1"></i>All Courses</a></li>
                    </ol>
                </nav>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-light text-dark border" id="lrCountBadge">0 registrations</span>
                    <div class="input-group input-group-sm lr-search-wrap">
                        <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="lrSearch" placeholder="Search name or number..." autocomplete="off">
                    </div>
                    <button type="button" id="lrSelectAll" class="btn btn-outline-secondary btn-sm" style="display:none;" title="Select / deselect all in this section">
                        <i class="fas fa-check-double me-1"></i> Select All
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" id="bulkDeleteBtn" style="display:none;">
                        <i class="fas fa-trash me-1"></i> Delete Selected (<span id="bulkDeleteCount">0</span>)
                    </button>
                </div>
            </div>
            <div id="lrLoading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                <div class="small text-muted mt-2">Loading registrations...</div>
            </div>
            <div id="lrEmpty" class="text-center py-5 text-muted" style="display:none;">
                <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-50"></i>
                <div class="fw-bold" id="lrEmptyTitle">Nothing here yet</div>
                <small id="lrEmptySub">Try a different search or add new registrations.</small>
            </div>
            <!-- Level 0: Course folders -->
            <div id="lrCourseGrid" class="lr-folder-grid" style="display:none;"></div>
            <!-- Level 1: Year-level folders -->
            <div id="lrYearGrid" class="lr-folder-grid" style="display:none;"></div>
            <!-- Level 2: Section folders inside a year level -->
            <div id="lrSectionGrid" class="lr-folder-grid" style="display:none;"></div>
            <!-- Level 3: Registration cards inside a section -->
            <div id="lrStudentGrid" class="lr-student-grid" style="display:none;"></div>
        </div>
    </div>
</div>

<style>
/* ===== Landing Registrations: folders + cards (matches Student Directory) ===== */
.lr-breadcrumb { background: transparent; font-size: 0.85rem; }
.lr-breadcrumb a { text-decoration: none; font-weight: 600; }
.lr-search-wrap { width: 240px; }
/* Promotion dropdown container (smooth Bootstrap collapse) */
.lr-promo-wrap { position: relative; z-index: 1040; background: transparent; border: 0; }
button.lr-promo-toggle {
    display: inline-flex; align-items: center; gap: 0.6rem; width: auto;
    padding: 0.55rem 1.1rem; margin-left: 4px;
    background: var(--horizon-card-bg, #fff);
    border: 1px solid var(--horizon-card-border, #e9ecef);
    border-bottom: 0; border-radius: 12px 12px 0 0;
    cursor: pointer; text-align: left;
    box-shadow: 0 -4px 12px rgba(16, 24, 40, 0.06);
}
button.lr-promo-toggle:hover { filter: none; border-color: var(--horizon-brand-300, #8171FC); }
.lr-promo-toggle[aria-expanded="true"] {
    background: var(--horizon-brand-linear, linear-gradient(135deg,#868CFF 0%,#4318FF 100%));
    border-color: transparent;
    box-shadow: 0 6px 16px rgba(67, 24, 255, 0.4);
}
.lr-promo-toggle[aria-expanded="true"] .lr-promo-toggle-text { color: #fff; }
.lr-promo-toggle[aria-expanded="true"] .lr-promo-toggle-text small { color: rgba(255, 255, 255, 0.82); }
.lr-promo-toggle[aria-expanded="true"] .lr-promo-chevron { color: #fff; }
.lr-promo-toggle[aria-expanded="true"] .lr-promo-toggle-icon { background: rgba(255, 255, 255, 0.25); box-shadow: none; }
.lr-promo-toggle-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; min-width: 36px; border-radius: 11px;
    background: var(--horizon-brand-linear, linear-gradient(135deg,#868CFF 0%,#4318FF 100%));
    color: #fff; font-size: 15px;
    box-shadow: 0 4px 12px rgba(67,24,255,.3);
}
.lr-promo-toggle-text { font-weight: 800; font-size: 0.88rem; color: var(--horizon-text-heading, #1B2559); flex: 1; }
.lr-promo-toggle-text small { display: block; font-weight: 500; font-size: 0.72rem; color: var(--horizon-text-muted, #A3AED0); }
.lr-promo-chevron { color: var(--horizon-text-muted, #A3AED0); font-size: 0.8rem; transition: transform 0.3s ease; }
.lr-promo-toggle[aria-expanded="true"] .lr-promo-chevron { transform: rotate(180deg); }
/* Floating overlay attached under the tab: expands in front of the folders */
#lrPromoCollapse { position: absolute; top: 100%; left: 0; right: 0; z-index: 1040; margin-top: -1px; }
.lr-promo-body {
    border: 1px solid var(--horizon-card-border, #e9ecef);
    border-radius: 0 14px 14px 14px;
    padding: 1rem;
    background: var(--horizon-card-bg, #fff);
    box-shadow: 0 18px 44px rgba(16, 24, 40, 0.28);
}
html[data-theme="dark"] .lr-promo-wrap, body[data-theme="dark"] .lr-promo-wrap, body[data-bs-theme="dark"] .lr-promo-wrap {
    background: var(--horizon-card-bg, #182235);
    border-color: var(--horizon-card-border, #2b3850);
}
html[data-theme="dark"] .lr-promo-toggle-text, body[data-theme="dark"] .lr-promo-toggle-text, body[data-bs-theme="dark"] .lr-promo-toggle-text {
    color: var(--horizon-text-title, #f1f5ff);
}
html[data-theme="dark"] button.lr-promo-toggle:hover, body[data-theme="dark"] button.lr-promo-toggle:hover, body[data-bs-theme="dark"] button.lr-promo-toggle:hover {
    background: rgba(134, 140, 255, 0.08);
}
html[data-theme="dark"] .lr-promo-body, body[data-theme="dark"] .lr-promo-body, body[data-bs-theme="dark"] .lr-promo-body {
    background: var(--horizon-card-bg, #182235);
    border-color: var(--horizon-card-border, #2b3850);
    box-shadow: 0 18px 44px rgba(0, 0, 0, 0.55);
}
.lr-tab-dot {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; border-radius: 50%;
    background: #dc3545; color: #fff;
    font-size: 0.68rem; font-weight: 800; margin-left: 0.4rem;
}
.lr-folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; }
.lr-folder-card {
    display: flex; align-items: center; gap: 0.9rem;
    padding: 1rem 1.1rem;
    background: var(--horizon-card-bg, #fff);
    border: 1px solid var(--horizon-card-border, #e9ecef);
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(16,24,40,.06);
    cursor: pointer;
    transition: transform .15s ease, box-shadow .2s ease, border-color .2s ease;
    text-align: left; width: 100%;
}
button.lr-folder-card { background: var(--horizon-card-bg, #fff); }
.lr-folder-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(67,24,255,.14);
    border-color: var(--horizon-brand-300, #8171FC);
    filter: none;
}
.lr-folder-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 48px; height: 48px; min-width: 48px;
    border-radius: 14px;
    background: var(--horizon-brand-linear, linear-gradient(135deg,#868CFF 0%,#4318FF 100%));
    color: #fff; font-size: 20px;
    box-shadow: 0 4px 12px rgba(67,24,255,.3);
}
.lr-folder-icon.section {
    background: linear-gradient(135deg,#34d399 0%,#059669 100%);
    box-shadow: 0 4px 12px rgba(5,150,105,.3);
}
.lr-folder-meta { min-width: 0; }
.lr-folder-title {
    font-weight: 800; font-size: 0.95rem;
    color: var(--horizon-text-heading, #1B2559);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lr-folder-sub { font-size: 0.75rem; color: var(--horizon-text-muted, #A3AED0); font-weight: 600; }
.lr-pending-pill {
    display: inline-block;
    margin-top: 0.3rem;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .04em;
    border-radius: 999px;
    padding: 0.2em 0.6em;
    background: rgba(255, 181, 71, 0.18);
    color: #b45309;
    border: 1px solid rgba(217, 119, 6, 0.4);
}
.lr-folder-card.has-pending { border-color: rgba(217, 119, 6, 0.45); }
.lr-student-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
.lr-card {
    position: relative;
    background: var(--horizon-card-bg, #fff);
    border: 1px solid var(--horizon-card-border, #e9ecef);
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 1px 2px rgba(16,24,40,.06);
    cursor: pointer;
    transition: transform .15s ease, box-shadow .2s ease, border-color .2s ease;
}
.lr-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(16,24,40,.12);
    border-color: var(--horizon-brand-300, #8171FC);
}
.lr-card.selected {
    border-color: var(--horizon-brand-500, #4318FF);
    box-shadow: 0 0 0 3px rgba(67,24,255,.15);
}
.lr-card-top { display: flex; align-items: center; gap: 0.75rem; }
.lr-avatar {
    display: inline-flex; align-items: center; justify-content: center;
    width: 46px; height: 46px; min-width: 46px;
    border-radius: 50%;
    background: var(--horizon-brand-linear, linear-gradient(135deg,#868CFF 0%,#4318FF 100%));
    color: #fff; font-weight: 800; font-size: 0.9rem; letter-spacing: .02em;
}
.lr-card.is-enrolled .lr-avatar { background: linear-gradient(135deg,#34d399 0%,#059669 100%); }
.lr-name {
    font-weight: 800; font-size: 0.88rem; text-transform: uppercase;
    color: var(--horizon-text-heading, #1B2559);
    line-height: 1.25; word-break: break-word;
}
.lr-num { font-family: 'Courier New', monospace; font-size: 0.85rem; color: #B8860B; font-weight: 700; }
.lr-sub { font-size: 0.72rem; color: var(--horizon-text-muted, #A3AED0); font-weight: 600; }
.lr-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 0.7rem; }
.lr-card-foot .badge { cursor: pointer; }
.lr-card-actions { display: flex; gap: 0.25rem; }
.lr-card-actions .btn { padding: 0.2rem 0.45rem !important; font-size: 0.72rem; }
.lr-select { position: absolute; top: 0.6rem; right: 0.6rem; }
.lr-select input { width: 1.05rem; height: 1.05rem; cursor: pointer; }
/* Fixed-height scrollable viewport */
#lrCourseGrid, #lrYearGrid, #lrSectionGrid, #lrStudentGrid {
    height: calc(100vh - 340px);
    min-height: 320px;
    max-height: 660px;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0.25rem 0.35rem 0.75rem 0.1rem;
    scrollbar-width: thin;
    scrollbar-color: var(--horizon-brand-300, #8171FC) transparent;
    align-content: start;
}
#lrCourseGrid::-webkit-scrollbar, #lrYearGrid::-webkit-scrollbar, #lrSectionGrid::-webkit-scrollbar, #lrStudentGrid::-webkit-scrollbar { width: 8px; }
#lrCourseGrid::-webkit-scrollbar-track, #lrYearGrid::-webkit-scrollbar-track, #lrSectionGrid::-webkit-scrollbar-track, #lrStudentGrid::-webkit-scrollbar-track { background: transparent; }
#lrCourseGrid::-webkit-scrollbar-thumb, #lrYearGrid::-webkit-scrollbar-thumb, #lrSectionGrid::-webkit-scrollbar-thumb, #lrStudentGrid::-webkit-scrollbar-thumb {
    background: var(--horizon-brand-200, #A195FD);
    border-radius: 8px;
}
/* Dark mode */
html[data-theme="dark"] .lr-folder-card, body[data-theme="dark"] .lr-folder-card, body[data-bs-theme="dark"] .lr-folder-card,
html[data-theme="dark"] .lr-card, body[data-theme="dark"] .lr-card, body[data-bs-theme="dark"] .lr-card {
    background: var(--horizon-card-bg, #182235);
    border-color: var(--horizon-card-border, #2b3850);
}
html[data-theme="dark"] .lr-folder-title, body[data-theme="dark"] .lr-folder-title, body[data-bs-theme="dark"] .lr-folder-title,
html[data-theme="dark"] .lr-name, body[data-theme="dark"] .lr-name, body[data-bs-theme="dark"] .lr-name {
    color: var(--horizon-text-title, #f1f5ff);
}
html[data-theme="dark"] .lr-num, body[data-theme="dark"] .lr-num, body[data-bs-theme="dark"] .lr-num { color: #F2C94C; }
html[data-theme="dark"] .lr-pending-pill, body[data-theme="dark"] .lr-pending-pill, body[data-bs-theme="dark"] .lr-pending-pill {
    background: rgba(251, 191, 36, 0.15);
    color: #fbbf24;
    border-color: rgba(251, 191, 36, 0.4);
}
html[data-theme="dark"] .lr-folder-card:hover, body[data-theme="dark"] .lr-folder-card:hover, body[data-bs-theme="dark"] .lr-folder-card:hover,
html[data-theme="dark"] .lr-card:hover, body[data-theme="dark"] .lr-card:hover, body[data-bs-theme="dark"] .lr-card:hover {
    border-color: #868CFF;
}
@media (max-width: 576px) {
    .lr-search-wrap { width: 100%; }
    .lr-folder-grid, .lr-student-grid { grid-template-columns: 1fr 1fr; gap: 0.7rem; }
    #lrCourseGrid, #lrYearGrid, #lrSectionGrid, #lrStudentGrid { height: calc(100vh - 360px); min-height: 260px; }
}
</style>

<!-- Add / Edit Registration Modal -->
<div class="modal fade" id="landingRegModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="landingRegModalTitle">Add Registration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="?page=landing_registrations">
                <input type="hidden" name="landing_action" value="save">
                <input type="hidden" name="id" id="lr-id" value="">
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Student Number *</label>
                            <input type="text" name="student_number" id="lr-student_number" class="form-control" maxlength="8" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="lr-status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="enrolled">Enrolled</option>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="lastname" id="lr-lastname" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="firstname" id="lr-firstname" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middlename" id="lr-middlename" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Extension</label>
                            <select name="name_extension" id="lr-name_extension" class="form-select">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Course *</label>
                            <input type="text" name="course" id="lr-course" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year Level *</label>
                            <input type="text" name="year" id="lr-year" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Section *</label>
                            <input type="text" name="section" id="lr-section" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Birthdate</label>
                            <input type="date" name="birthdate" id="lr-birthdate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Age</label>
                            <input type="number" name="age" id="lr-age" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select name="gender" id="lr-gender" class="form-select">
                                <option value="">-</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" id="lr-contact_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="lr-email" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Province</label>
                            <input type="text" name="province" id="lr-province" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Municipality</label>
                            <input type="text" name="municipality" id="lr-municipality" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Barangay</label>
                            <input type="text" name="barangay" id="lr-barangay" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Zip Code</label>
                            <input type="text" name="zipcode" id="lr-zipcode" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-save me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Student Enrollments Modal -->
<div class="modal fade" id="landingViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="landingViewModalTitle">Student Enrollments</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="landingViewModalBody">
                <div class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm" role="status"></div>
                    <span class="ms-2">Loading enrollments...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Students Modal -->
<div class="modal fade" id="assignStudentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-share me-2"></i>Assign Students to Faculty</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="assignStudentsForm">
                <div class="modal-body">
                    <div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i>Select year level, then sections (multiple), then faculty and class. Matching pending students will be copied to the faculty's class.</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Year Level *</label>
                            <select id="assignYear" class="form-select" required>
                                <option value="">Select Year Level</option>
                                <?php foreach ($yearOptions as $y): ?>
                                    <option value="<?php echo htmlspecialchars($y, ENT_QUOTES); ?>"><?php echo htmlspecialchars(landing_year_label($y), ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($yearOptions)): ?>
                                    <option value="" disabled>No year levels found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Faculty *</label>
                            <select id="assignFaculty" class="form-select" required>
                                <option value="">Select Faculty</option>
                                <?php foreach ($facultyOptions as $f): ?>
                                    <option value="<?php echo (int)$f['admin_id']; ?>"><?php echo htmlspecialchars($f['fullname'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($facultyOptions)): ?>
                                    <option value="" disabled><?php
                                        echo !empty($lrScope['scoped'])
                                            ? 'No faculty assigned to ' . htmlspecialchars($lrScope['department'], ENT_QUOTES)
                                            : 'No faculty found';
                                    ?></option>
                                <?php endif; ?>
                            </select>
                            <?php if ($facultyScopeNote !== ''): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($facultyScopeNote, ENT_QUOTES); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sections <small class="text-muted">(check multiple)</small></label>
                            <div id="assignSectionsContainer" class="border rounded p-2" style="min-height: 60px; max-height: 180px; overflow-y: auto;">
                                <small class="text-muted">Select a year level first</small>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Class / Subject *</label>
                            <select id="assignClass" class="form-select" required>
                                <option value="">Select Faculty First</option>
                            </select>
                            <small class="text-muted">Classes from the selected faculty's database</small>
                        </div>
                        <div class="col-12">
                            <div id="assignPreview" class="small text-muted"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm" id="assignSubmitBtn"><i class="fas fa-paper-plane me-1"></i> Assign Students</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import from Grading Confirm Modal -->
<div class="modal fade" id="landingImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #0e7490 0%, #155e75 100%);">
                <h5 class="modal-title fw-bold"><i class="fas fa-download me-2"></i>Import from Grading</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="landingImportBody">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-info bg-opacity-10 text-info mb-3" style="width:56px;height:56px;"><i class="fas fa-database fa-lg"></i></div>
                <p class="mb-1 fw-bold" id="landingImportText">Checking grading database…</p>
                <small class="text-muted d-block mt-2">Copies missing students from <code>grading_db.student_list</code> as <strong>pending</strong> registrations (name, contacts, address, class). Existing student numbers are skipped — never duplicated or overwritten.</small>
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info rounded-pill px-4" id="landingImportConfirmBtn"><i class="fas fa-download me-1"></i>Import</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Result Popup (Professional) --><div class="modal fade" id="assignResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-0 text-white" id="assignResultHeader" style="background: linear-gradient(135deg, #1a4d2e 0%, #2d5f3f 100%);">
                <h5 class="modal-title fw-bold" id="assignResultTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="assignResultBody"></div>
            <div class="modal-footer border-0 justify-content-center">
                <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Promotion Confirm Modal -->
<div class="modal fade" id="promoConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-arrow-up me-2 text-primary"></i>Confirm Year &amp; Section Increment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="promoConfirmBody">
                <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Loading preview...</span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="promoConfirmApplyBtn"><i class="fas fa-check me-1"></i>Confirm Increment</button>
            </div>
        </div>
    </div>
</div>

<!-- Graduate Confirm Modal -->
<div class="modal fade" id="promoGraduateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-graduation-cap me-2 text-success"></i>Confirm Graduation (<span id="promoGradYearLabel">5th year</span>)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="promoGraduateBody">
                <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Loading graduates...</span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="promoGraduateApplyBtn"><i class="fas fa-graduation-cap me-1"></i>Set as Graduates &amp; Migrate</button>
            </div>
        </div>
    </div>
</div>

<!-- Landing Delete Confirmation Modal (single & bulk) -->
<div class="modal fade" id="landingDeleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #dc3545 0%, #a71d2a 100%);">
                <h5 class="modal-title fw-bold" id="landingDeleteConfirmTitle"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="landingDeleteConfirmBody">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger mb-3" style="width:56px;height:56px;"><i class="fas fa-trash fa-lg"></i></div>
                <p class="mb-1 fw-bold" id="landingDeleteConfirmText"></p>
                <small class="text-muted" id="landingDeleteConfirmSub">This cannot be undone.</small>
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger rounded-pill px-4" id="landingDeleteConfirmBtn"><i class="fas fa-trash me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function() {
        // Prevent DataTables default alert (tn/7) — we show inline error above
        if (window.jQuery && jQuery.fn.DataTable) { try { jQuery.fn.DataTable.ext.errMode = 'none'; } catch(e){} }
        // ===== Landing folder engine (replaces the DataTable) =====
        // ===== Landing folder engine: Course -> Year -> Section -> Students =====
        var LandingDir = (function(){
            var cache = [];
            var course = null;
            var year = null;       // raw year value
            var yearLabel = '';
            var section = null;    // section name
            var search = '';
            var selected = new Set();

            function esc(s){
                return String(s === undefined || s === null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
            function initials(name){
                var parts = String(name || '').replace(/,/g, ' ').split(/\s+/).filter(Boolean);
                if(!parts.length) return '?';
                var last = parts.length > 1 ? parts[parts.length-1][0] : '';
                return (parts[0][0] + last).toUpperCase();
            }
            function findCourse(c){ return cache.find(function(x){ return x.course === c; }); }
            function findYear(y){
                if(course === null) return null;
                var C = findCourse(course);
                if(!C) return null;
                return (C.years || []).find(function(x){ return String(x.year) === String(y); }) || null;
            }
            function findSection(y, s){
                var Y = findYear(y);
                if(!Y) return null;
                return (Y.sections || []).find(function(x){ return x.section === s; }) || null;
            }
            function currentStudents(){
                var S = (year !== null && section) ? findSection(year, section) : null;
                return S ? (S.students || []) : [];
            }
            function matchQ(st, q){
                return String(st.name || '').toLowerCase().indexOf(q) !== -1 ||
                       String(st.student_number || '').toLowerCase().indexOf(q) !== -1 ||
                       String(st.course || '').toLowerCase().indexOf(q) !== -1;
            }
            function pendingOf(list){
                return (list || []).filter(function(st){ return (st.status || 'pending') !== 'enrolled'; }).length;
            }
            function pendingPill(n){
                if(!n) return '';
                return ' <span class="lr-pending-pill" title="' + n + ' pending registration' + (n === 1 ? '' : 's') + '"><i class="fas fa-clock me-1"></i>' + n + ' pending</span>';
            }
            function hideAll(){
                ['lrCourseGrid', 'lrYearGrid', 'lrSectionGrid', 'lrStudentGrid', 'lrEmpty'].forEach(function(id){
                    document.getElementById(id).style.display = 'none';
                });
            }

            function load(){
                document.getElementById('lrLoading').style.display = '';
                hideAll();
                fetch('get_landing_folders.php')
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        document.getElementById('lrLoading').style.display = 'none';
                        if(res && res.status === 'success'){
                            cache = res.data || [];
                            if(course !== null && !findCourse(course)){ course = null; year = null; yearLabel = ''; section = null; }
                            if(year !== null && !findYear(year)){ year = null; yearLabel = ''; section = null; }
                            if(year !== null && section && !findSection(year, section)){ section = null; }
                            render();
                        } else {
                            showEmpty('Failed to load registrations', (res && res.message) || 'Please try again.');
                        }
                    })
                    .catch(function(){
                        document.getElementById('lrLoading').style.display = 'none';
                        showEmpty('Connection error', 'Could not reach the server. Please reload the page.');
                    });
            }

            function showEmpty(title, sub){
                hideAll();
                document.getElementById('lrSelectAll').style.display = 'none';
                document.getElementById('lrEmptyTitle').textContent = title;
                document.getElementById('lrEmptySub').textContent = sub;
                document.getElementById('lrEmpty').style.display = '';
                document.getElementById('lrCountBadge').textContent = '0 registrations';
            }

            function renderBreadcrumb(){
                var h = '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="root"><i class="fas fa-home me-1"></i>All Courses</a></li>';
                if(course !== null){
                    h += '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="course" data-course="' + esc(course) + '">' + esc(course) + '</a></li>';
                }
                if(course !== null && year !== null){
                    h += '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="year" data-year="' + esc(year) + '">' + esc(yearLabel || year) + '</a></li>';
                }
                if(course !== null && year !== null && section){
                    h += '<li class="breadcrumb-item active" aria-current="page">' + esc(section) + '</li>';
                }
                document.getElementById('lrBreadcrumb').innerHTML = h;
            }

            function render(){
                renderBreadcrumb();
                hideAll();
                document.getElementById('lrSelectAll').style.display = 'none';
                updateBulkUI();
                var q = search.trim().toLowerCase();

                if(course === null){
                    var courses = !q ? cache : cache.filter(function(c){ return String(c.course).toLowerCase().indexOf(q) !== -1; });
                    var total = cache.reduce(function(n, c){ return n + c.student_count; }, 0);
                    document.getElementById('lrCountBadge').textContent = total + ' registration' + (total === 1 ? '' : 's');
                    if(!courses.length){ showEmpty('No courses found', 'Try a different search or add new registrations.'); return; }
                    document.getElementById('lrCourseGrid').innerHTML = courses.map(courseCard).join('');
                    document.getElementById('lrCourseGrid').style.display = '';
                } else if(year === null){
                    var C = findCourse(course);
                    if(!C){ course = null; render(); return; }
                    var years = !q ? C.years : C.years.filter(function(g){ return String(g.year_label || g.year).toLowerCase().indexOf(q) !== -1; });
                    document.getElementById('lrCountBadge').textContent = C.student_count + ' registration' + (C.student_count === 1 ? '' : 's');
                    if(!years.length){ showEmpty('No year levels found', 'Try a different search.'); return; }
                    document.getElementById('lrYearGrid').innerHTML = years.map(yearCard).join('');
                    document.getElementById('lrYearGrid').style.display = '';
                } else if(!section){
                    var G = findYear(year);
                    if(!G){ year = null; yearLabel = ''; render(); return; }
                    var secs = !q ? G.sections : G.sections.filter(function(s){ return String(s.section).toLowerCase().indexOf(q) !== -1; });
                    document.getElementById('lrCountBadge').textContent = G.student_count + ' registration' + (G.student_count === 1 ? '' : 's');
                    if(!secs.length){ showEmpty('No sections found', 'Try a different search.'); return; }
                    document.getElementById('lrSectionGrid').innerHTML = secs.map(sectionCard).join('');
                    document.getElementById('lrSectionGrid').style.display = '';
                } else {
                    var all = currentStudents();
                    var list = !q ? all : all.filter(function(st){ return matchQ(st, q); });
                    document.getElementById('lrCountBadge').textContent = all.length + ' registration' + (all.length === 1 ? '' : 's');
                    document.getElementById('lrSelectAll').style.display = '';
                    if(!list.length){ showEmpty(all.length ? 'No matching registrations' : 'No registrations in this section', all.length ? 'Try a different search.' : 'Use Add Registration to add students here.'); return; }
                    document.getElementById('lrStudentGrid').innerHTML = list.map(studentCard).join('');
                    document.getElementById('lrStudentGrid').style.display = '';
                    syncCheckboxes();
                }
            }

            function courseCard(c){
                var pend = 0;
                (c.years || []).forEach(function(g){
                    (g.sections || []).forEach(function(s){ pend += pendingOf(s.students); });
                });
                var tip = pend > 0
                    ? esc(c.course) + ' — ' + pend + ' pending registration' + (pend === 1 ? '' : 's') + '. Click to review.'
                    : 'Open ' + esc(c.course);
                return '<button type="button" class="lr-folder-card' + (pend > 0 ? ' has-pending' : '') + '" data-course="' + esc(c.course) + '" title="' + tip + '">' +
                    '<span class="lr-folder-icon"><i class="fas fa-graduation-cap"></i></span>' +
                    '<span class="lr-folder-meta"><span class="lr-folder-title">' + esc(c.course) + '</span><br>' +
                    '<span class="lr-folder-sub">' + c.year_count + ' year' + (c.year_count === 1 ? '' : 's') + ' &middot; ' + c.student_count + ' registration' + (c.student_count === 1 ? '' : 's') + '</span>' + pendingPill(pend) + '</span>' +
                '</button>';
            }
            function yearCard(g){
                var pend = 0;
                (g.sections || []).forEach(function(s){ pend += pendingOf(s.students); });
                var tip = pend > 0
                    ? esc(g.year_label) + ' — ' + pend + ' pending registration' + (pend === 1 ? '' : 's') + '. Click to review.'
                    : 'Open ' + esc(g.year_label);
                return '<button type="button" class="lr-folder-card' + (pend > 0 ? ' has-pending' : '') + '" data-year="' + esc(g.year) + '" data-year-label="' + esc(g.year_label) + '" title="' + tip + '">' +
                    '<span class="lr-folder-icon"><i class="fas fa-folder"></i></span>' +
                    '<span class="lr-folder-meta"><span class="lr-folder-title">' + esc(g.year_label) + '</span><br>' +
                    '<span class="lr-folder-sub">' + g.section_count + ' section' + (g.section_count === 1 ? '' : 's') + ' &middot; ' + g.student_count + ' registration' + (g.student_count === 1 ? '' : 's') + '</span>' + pendingPill(pend) + '</span>' +
                '</button>';
            }
            function sectionCard(s){
                var pend = pendingOf(s.students);
                var tip = pend > 0
                    ? esc(s.section) + ' — ' + pend + ' pending registration' + (pend === 1 ? '' : 's') + '. Click to review.'
                    : 'Open ' + esc(s.section);
                return '<button type="button" class="lr-folder-card' + (pend > 0 ? ' has-pending' : '') + '" data-section="' + esc(s.section) + '" title="' + tip + '">' +
                    '<span class="lr-folder-icon section"><i class="fas fa-folder-open"></i></span>' +
                    '<span class="lr-folder-meta"><span class="lr-folder-title">' + esc(s.section) + '</span><br>' +
                    '<span class="lr-folder-sub">' + s.count + ' registration' + (s.count === 1 ? '' : 's') + '</span>' + pendingPill(pend) + '</span>' +
                '</button>';
            }
            var EDIT_KEYS = ['id','student_number','lastname','firstname','middlename','name_extension','course','year','section','birthdate','age','gender','contact_number','email','barangay','municipality','province','zipcode','status'];
            function editAttrs(st){
                return EDIT_KEYS.map(function(k){ return 'data-' + k + '="' + esc(st[k]) + '"'; }).join(' ');
            }
            function studentCard(st){
                var en = (st.status === 'enrolled');
                var sel = selected.has(st.id) ? ' selected' : '';
                var checked = selected.has(st.id) ? ' checked' : '';
                return '<div class="lr-card' + (en ? ' is-enrolled' : '') + sel + '" data-id="' + st.id + '">' +
                    '<label class="lr-select" title="Select registration"><input type="checkbox" class="form-check-input landing-row-check" value="' + st.id + '"' + checked + '></label>' +
                    '<div class="lr-card-top">' +
                        '<span class="lr-avatar">' + esc(initials(st.name)) + '</span>' +
                        '<div style="min-width:0;"><div class="lr-name">' + esc(st.name || 'Unnamed') + '</div>' +
                        '<div class="lr-num">' + esc(st.student_number || 'No number') + '</div>' +
                        '<div class="lr-sub">' + esc(st.course || '') + (st.course ? ' &middot; ' : '') + esc(yearLabel || st.year || '') + ' - ' + esc(section || st.section || '') + '</div></div>' +
                    '</div>' +
                    '<div class="lr-card-foot">' +
                        '<button type="button" class="badge landing-status-btn border-0 ' + (en ? 'bg-success' : 'bg-warning text-dark') + '" data-id="' + st.id + '" title="View subject assignment status" style="cursor:pointer;">' + (en ? 'Enrolled' : 'Pending') + '</button>' +
                        '<span class="lr-card-actions">' +
                            '<button type="button" class="btn btn-sm btn-outline-info landing-view-btn" data-student_number="' + esc(st.student_number) + '" data-name="' + esc(st.name) + '" title="View enrollments"><i class="fa fa-eye"></i></button>' +
                            '<button type="button" class="btn btn-sm btn-outline-primary landing-edit-btn" ' + editAttrs(st) + ' title="Edit"><i class="fa fa-edit"></i></button>' +
                            '<a href="?page=landing_registrations&delete=' + st.id + '" class="btn btn-sm btn-outline-danger landing-delete-btn" title="Delete" data-id="' + st.id + '" data-name="' + esc(st.name) + '" data-student_number="' + esc(st.student_number) + '"><i class="fa fa-trash"></i></a>' +
                        '</span>' +
                    '</div>' +
                '</div>';
            }

            function openCourse(c){ course = c; year = null; yearLabel = ''; section = null; search = ''; document.getElementById('lrSearch').value = ''; render(); }
            function openYear(y, label){ year = y; yearLabel = label || y; section = null; search = ''; document.getElementById('lrSearch').value = ''; render(); }
            function openSection(s){ section = s; search = ''; document.getElementById('lrSearch').value = ''; render(); }
            function goRoot(){ course = null; year = null; yearLabel = ''; section = null; search = ''; document.getElementById('lrSearch').value = ''; render(); }
            function context(){
                return {
                    course: (course !== null ? String(course) : ''),
                    year: (year !== null ? String(year) : ''),
                    section: section || '',
                    search: document.getElementById('lrSearch').value || ''
                };
            }

            function syncCheckboxes(){
                document.querySelectorAll('#lrStudentGrid input.landing-row-check').forEach(function(cb){
                    cb.checked = selected.has(parseInt(cb.value, 10));
                });
            }
            function updateBulkUI(){
                var count = selected.size;
                var btn = document.getElementById('bulkDeleteBtn');
                var cnt = document.getElementById('bulkDeleteCount');
                if(cnt) cnt.textContent = count;
                if(btn) btn.style.display = count > 0 ? '' : 'none';
            }

            document.addEventListener('click', function(e){
                var cb = e.target.closest('.lr-folder-card[data-course]');
                if(cb){ openCourse(cb.getAttribute('data-course')); return; }
                var yb = e.target.closest('.lr-folder-card[data-year]');
                if(yb){ openYear(yb.getAttribute('data-year'), yb.getAttribute('data-year-label')); return; }
                var sb = e.target.closest('.lr-folder-card[data-section]');
                if(sb){ openSection(sb.getAttribute('data-section')); return; }
                var nav = e.target.closest('#lrBreadcrumb [data-nav]');
                if(nav){
                    var kind = nav.getAttribute('data-nav');
                    if(kind === 'root') goRoot();
                    else if(kind === 'course') openCourse(nav.getAttribute('data-course'));
                    else if(kind === 'year') openYear(nav.getAttribute('data-year'), nav.textContent.trim());
                    return;
                }
            });
            document.addEventListener('change', function(e){
                if(e.target && e.target.classList.contains('landing-row-check')){
                    var id = parseInt(e.target.value, 10);
                    if(e.target.checked) selected.add(id);
                    else selected.delete(id);
                    e.target.closest('.lr-card').classList.toggle('selected', e.target.checked);
                    updateBulkUI();
                }
            });

            return {
                load: load, refresh: load,
                goRoot: goRoot, openCourse: openCourse, openYear: openYear, openSection: openSection,
                context: context, selected: selected, updateBulkUI: updateBulkUI,
                search: function(v){ search = v || ''; render(); }
            };
        })();
        LandingDir.load();
        // --- Bulk select / delete — Select All only affects currently rendered page (10 rows) ---
        // --- Bulk select / delete (card checkboxes; no pagination — selection persists) ---
        (function initBulkDelete(){
            var bulkBtn = document.getElementById('bulkDeleteBtn');
            var selectAllBtn = document.getElementById('lrSelectAll');
            if (!bulkBtn) return;
            var selected = LandingDir.selected;

            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', function(){
                    var boxes = Array.from(document.querySelectorAll('#lrStudentGrid input.landing-row-check'));
                    if (!boxes.length) return;
                    var allOn = boxes.every(function(cb){ return cb.checked; });
                    boxes.forEach(function(cb){
                        cb.checked = !allOn;
                        var id = parseInt(cb.value, 10);
                        if (!allOn) selected.add(id);
                        else selected.delete(id);
                        var card = cb.closest('.lr-card');
                        if (card) card.classList.toggle('selected', !allOn);
                    });
                    LandingDir.updateBulkUI();
                });
            }

            var pendingSingleUrl = null;
            var pendingBulkIds = null;
            function showLandingDeleteModal(opts){
                document.getElementById('landingDeleteConfirmTitle').innerHTML = opts.title || '<i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete';
                document.getElementById('landingDeleteConfirmText').textContent = opts.text || '';
                var subEl = document.getElementById('landingDeleteConfirmSub');
                if (subEl) subEl.textContent = opts.sub || 'This cannot be undone.';
                var btn = document.getElementById('landingDeleteConfirmBtn');
                if (btn) btn.innerHTML = opts.confirmText || '<i class="fas fa-trash me-1"></i>Delete';
                if (window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('landingDeleteConfirmModal')).show();
                } else {
                    document.getElementById('landingDeleteConfirmModal').style.display='block';
                }
            }
            bulkBtn.addEventListener('click', function(){
                var ids = Array.from(selected);
                if (!ids.length) return;
                pendingSingleUrl = null;
                pendingBulkIds = ids;
                showLandingDeleteModal({
                    title: '<i class="fas fa-trash me-2"></i>Delete Selected',
                    text: 'Delete ' + ids.length + ' selected registration(s)?',
                    sub: 'This will permanently remove the selected registrations. This cannot be undone.',
                    confirmText: '<i class="fas fa-trash me-1"></i>Delete ' + ids.length
                });
            });
            // Single delete — intercept anchor and show modal
            document.addEventListener('click', function(e){
                var a = e.target.closest('.landing-delete-btn');
                if (!a) return;
                e.preventDefault();
                pendingBulkIds = null;
                pendingSingleUrl = a.getAttribute('href');
                var name = a.getAttribute('data-name') || '';
                var num = a.getAttribute('data-student_number') || '';
                var label = name ? (name + (num ? ' ('+num+')' : '')) : (num || 'this registration');
                showLandingDeleteModal({
                    title: '<i class="fas fa-trash me-2"></i>Confirm Delete',
                    text: 'Delete registration for ' + label + '?',
                    sub: 'This cannot be undone.',
                    confirmText: '<i class="fas fa-trash me-1"></i>Delete'
                });
            });
            document.getElementById('landingDeleteConfirmBtn').addEventListener('click', function(){
                var btn = this;
                if (pendingSingleUrl) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting...';
                    window.location.href = pendingSingleUrl;
                    return;
                }
                if (pendingBulkIds && pendingBulkIds.length) {
                    var ids = pendingBulkIds.slice();
                    btn.disabled = true;
                    var orig = '<i class="fas fa-trash me-1"></i>Delete';
                    var origBulk = bulkBtn.innerHTML;
                    bulkBtn.disabled = true;
                    bulkBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Deleting...';
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting...';
                    fetch('delete_landing_bulk.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ids: ids })
                    }).then(function(r){ return r.json(); }).then(function(res){
                        btn.disabled = false;
                        btn.innerHTML = orig;
                        bulkBtn.disabled = false;
                        bulkBtn.innerHTML = origBulk;
                        if (window.bootstrap) bootstrap.Modal.getInstance(document.getElementById('landingDeleteConfirmModal')).hide();
                        if (res && res.status === 'success') {
                            location.reload();
                        } else {
                            alert((res && res.message) || 'Failed to delete selected.');
                        }
                    }).catch(function(){
                        btn.disabled = false;
                        btn.innerHTML = orig;
                        bulkBtn.disabled = false;
                        bulkBtn.innerHTML = origBulk;
                        if (window.bootstrap) bootstrap.Modal.getInstance(document.getElementById('landingDeleteConfirmModal')).hide();
                        alert('Connection error. Please try again.');
                    });
                    pendingBulkIds = null;
                }
            });
            document.getElementById('landingDeleteConfirmModal').addEventListener('hidden.bs.modal', function(){
                pendingSingleUrl = null;
                pendingBulkIds = null;
                var btn = document.getElementById('landingDeleteConfirmBtn');
                if (btn) { btn.disabled=false; btn.innerHTML='<i class="fas fa-trash me-1"></i>Delete'; }
            });

            LandingDir.updateBulkUI();
        })();
        // --- Folder toolbar: live search + card click opens Edit ---
        (function initFolderToolbar(){
            var searchEl = document.getElementById('lrSearch');
            if (searchEl) {
                searchEl.addEventListener('input', function(){ LandingDir.search(this.value); });
            }
            document.addEventListener('click', function(e){
                var card = e.target.closest ? e.target.closest('.lr-card') : null;
                if (!card) return;
                if (e.target.closest('button, a, input, label')) return;
                var btn = card.querySelector('.landing-edit-btn');
                if (btn) btn.click();
            });
        })();
        // Export respects current Year/Section folder (and search) — server-side
        (function initExportFilter(){
            var btn = document.getElementById('exportLandingBtn');
            if (!btn) return;
            btn.addEventListener('click', function(e){
                var ctx = (typeof LandingDir !== 'undefined') ? LandingDir.context() : { year: '', section: '', search: '' };
                var params = new URLSearchParams();
                if (ctx.year) params.set('year', ctx.year);
                if (ctx.section) params.set('section', ctx.section);
                if (ctx.search) params.set('search', ctx.search);
                var url = 'export_landing_excel.php' + (params.toString() ? '?' + params.toString() : '');
                btn.setAttribute('href', url);
                if (e.ctrlKey || e.metaKey || e.button === 1) return;
                e.preventDefault();
                window.location.href = url;
            });
        })();
        var modalEl = document.getElementById('landingRegModal');
        function openModal() {
            if (window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                modalEl.style.display = 'block';
            }
        }
        document.getElementById('landingAddBtn').addEventListener('click', function() {
            document.getElementById('landingRegModalTitle').textContent = 'Add Registration';
            var form = modalEl.querySelector('form');
            form.reset();
            document.getElementById('lr-id').value = '';
            document.getElementById('lr-status').value = 'pending';
            openModal();
        });
        // --- Import students from grading_db (idempotent; skips existing) ---
        (function initGradingImport(){
            var btn = document.getElementById('importGradingBtn');
            var modalEl = document.getElementById('landingImportModal');
            var textEl = document.getElementById('landingImportText');
            var confirmBtn = document.getElementById('landingImportConfirmBtn');
            if (!btn || !modalEl || !textEl || !confirmBtn) return;
            var pendingCount = 0;
            function setBody(html){ textEl.innerHTML = html; }
            btn.addEventListener('click', function(){
                confirmBtn.disabled = true;
                setBody('<span class="spinner-border spinner-border-sm me-2"></span>Checking grading database…');
                new bootstrap.Modal(modalEl).show();
                fetch('import_landing_students.php?action=count')
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || res.status !== 'success') {
                            setBody('Could not check grading database. ' + escHtml((res && res.message) || 'Please try again.'));
                            return;
                        }
                        pendingCount = parseInt(res.missing, 10) || 0;
                        if (pendingCount > 0) {
                            setBody('<span class="fs-4 fw-bold text-info">' + pendingCount + '</span> new student(s) found in the grading database.<br><small class="text-muted">They will be added as <strong>pending</strong> registrations.</small>');
                            confirmBtn.disabled = false;
                        } else {
                            setBody('Everything is up to date — all grading students already exist here.');
                        }
                    })
                    .catch(function(){ setBody('Connection error. Please try again.'); });
            });
            confirmBtn.addEventListener('click', function(){
                if (!pendingCount) return;
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing…';
                fetch('import_landing_students.php?action=import', { method: 'POST' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="fas fa-download me-1"></i>Import';
                        bootstrap.Modal.getInstance(modalEl).hide();
                        if (res && res.status === 'success') {
                            showAssignResult(res.message, 'success');
                            try { if (typeof LandingDir !== 'undefined') LandingDir.refresh(); } catch(e){}
                            document.getElementById('assignResultModal').addEventListener('hidden.bs.modal', function handler(){
                                document.getElementById('assignResultModal').removeEventListener('hidden.bs.modal', handler);
                                location.reload();
                            });
                        } else {
                            showAssignResult((res && res.message) || 'Import failed. Please try again.', 'error');
                        }
                    })
                    .catch(function(){
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="fas fa-download me-1"></i>Import';
                        bootstrap.Modal.getInstance(modalEl).hide();
                        showAssignResult('Connection error. Please try again.', 'error');
                    });
            });
            modalEl.addEventListener('hidden.bs.modal', function(){
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fas fa-download me-1"></i>Import';
                pendingCount = 0;
            });
        })();
        // Delegated handlers — rows beyond page 1 are recreated by DataTables, so direct bindings miss them
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.landing-edit-btn');
            if (!btn) return;
            document.getElementById('landingRegModalTitle').textContent = 'Edit Registration';
            var d = btn.dataset;
            ['id','student_number','lastname','firstname','middlename','name_extension','course','year','section','birthdate','age','gender','contact_number','email','barangay','municipality','province','zipcode','status'].forEach(function(k) {
                var el = document.getElementById('lr-' + k);
                if (el) { el.value = d[k] !== undefined ? d[k] : ''; }
            });
            openModal();
        });
        function escHtml(s) {
            return String(s === undefined || s === null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.landing-view-btn');
            if (!btn) return;
            var num = btn.dataset.student_number || '';
            var name = btn.dataset.name || '';
            document.getElementById('landingViewModalTitle').textContent = 'Enrollments - ' + num;
            var body = document.getElementById('landingViewModalBody');
            body.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Loading enrollments...</span></div>';
            if (window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('landingViewModal')).show();
            }
            fetch('get_landing_enrollments.php?student_number=' + encodeURIComponent(num))
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res || res.status !== 'success') {
                        body.innerHTML = '<div class="alert alert-danger mb-0">' + escHtml((res && res.message) || 'Failed to load enrollments.') + '</div>';
                        return;
                    }
                    var html = '<p class="mb-2"><strong>' + escHtml(name) + '</strong> <span class="text-muted">(' + escHtml(num) + ')</span></p>';
                    if (!res.enrollments || res.enrollments.length === 0) {
                        html += '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-1"></i>Not enrolled in any class. Status stays <strong>pending</strong>.</div>';
                    } else {
                        html += '<div class="table-responsive"><table class="table table-sm table-striped table-bordered mb-0"><thead class="table-dark"><tr><th>Subject</th><th>Course - Section</th><th>Semester</th><th>School Year</th><th>Faculty</th><th>Remarks</th></tr></thead><tbody>';
                        res.enrollments.forEach(function(e) {
                            var rem = e.remarks || '—';
                            var remCls = 'text-muted';
                            if (rem === 'PASSED') remCls = 'text-success fw-bold';
                            else if (rem === 'FAILED' || rem === 'DROPPED') remCls = 'text-danger fw-bold';
                            else if (rem === 'CONDITIONAL') remCls = 'text-warning fw-bold';
                            html += '<tr><td>' + escHtml(e.subject) + (e.course_no ? ' <small class="text-muted">('+escHtml(e.course_no)+')</small>' : '') + '</td>' +
                                '<td>' + escHtml(e.course) + ' - ' + escHtml(e.section) + '</td>' +
                                '<td>' + escHtml(e.semester) + '</td>' +
                                '<td>' + escHtml(e.year) + '</td>' +
                                '<td>' + escHtml(e.faculty) + '</td>' +
                                '<td class="' + remCls + '">' + escHtml(rem) + (e.equivalent_grade ? ' <small>(' + escHtml(e.equivalent_grade) + ')</small>' : '') + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                    }
                    body.innerHTML = html;
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-danger mb-0">Connection error. Please try again.</div>';
                });
        });
        // Status badge click — per-subject enrolled / pending / passed breakdown
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.landing-status-btn');
            if (!btn) return;
            var rid = btn.dataset.id || '';
            if (!rid) return;
            document.getElementById('landingViewModalTitle').textContent = 'Subject Assignment Status';
            var body = document.getElementById('landingViewModalBody');
            body.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Loading subject status...</span></div>';
            if (window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('landingViewModal')).show();
            }
            fetch('get_landing_subject_status.php?id=' + encodeURIComponent(rid))
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res || res.status !== 'success') {
                        body.innerHTML = '<div class="alert alert-danger mb-0">' + escHtml((res && res.message) || 'Failed to load subject status.') + '</div>';
                        return;
                    }
                    var st = res.student || {};
                    var counts = res.counts || { total: 0, enrolled: 0, pending: 0, passed: 0 };
                    var html = '<p class="mb-1"><strong>' + escHtml(st.name) + '</strong> <span class="text-muted">(' + escHtml(st.student_number) + ')</span> ' +
                        (st.is_irregular ? '<span class="badge bg-warning text-dark ms-1">Irregular</span>' : '') + '</p>';
                    html += '<p class="small text-muted mb-2">' + escHtml(st.course) + ' - ' + escHtml(st.year) + ' - ' + escHtml(st.section) + '</p>';
                    html += '<div class="mb-2 d-flex flex-wrap gap-1">' +
                        '<span class="badge bg-secondary">' + counts.total + ' subject(s)</span>' +
                        '<span class="badge bg-success">' + counts.enrolled + ' enrolled</span>' +
                        '<span class="badge bg-warning text-dark">' + counts.pending + ' pending</span>' +
                        '<span class="badge bg-info text-dark">' + counts.passed + ' passed</span></div>';
                    if (!res.subjects || res.subjects.length === 0) {
                        html += '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-1"></i>No classes found for this section in any faculty database yet.</div>';
                    } else {
                        html += '<div class="table-responsive"><table class="table table-sm table-striped table-bordered mb-0"><thead class="table-dark"><tr><th>Subject</th><th>Section</th><th>Faculty</th><th>Type</th><th>State</th></tr></thead><tbody>';
                        res.subjects.forEach(function(s) {
                            var stateBadge = s.state === 'enrolled'
                                ? '<span class="badge bg-success">Enrolled</span>'
                                : (s.state === 'passed'
                                    ? '<span class="badge bg-info text-dark">Passed</span>'
                                    : '<span class="badge bg-warning text-dark">Pending</span>');
                            var typeBadge = s.source && s.source.indexOf('Irregular') !== -1
                                ? '<span class="badge bg-warning text-dark">Irregular</span>'
                                : '<span class="badge bg-primary">Regular</span>';
                            if (s.source && s.source.indexOf('Regular') !== -1 && s.source.indexOf('Irregular') !== -1) {
                                typeBadge = '<span class="badge bg-primary">Regular</span> <span class="badge bg-warning text-dark">Irregular</span>';
                            }
                            html += '<tr><td><strong>' + escHtml(s.code) + '</strong> — ' + escHtml(s.name) +
                                '<br><small class="text-muted">' + escHtml(s.semester) + ' · ' + escHtml(s.acad_year) + '</small></td>' +
                                '<td>' + escHtml(s.program ? (s.program + ' - ') : '') + escHtml(s.section) + '</td>' +
                                '<td>' + escHtml(s.faculty) + '</td>' +
                                '<td>' + typeBadge + '</td>' +
                                '<td>' + stateBadge + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                        html += '<small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i>Pending = waiting to be assigned to that specific subject class.</small>';
                    }
                    body.innerHTML = html;
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-danger mb-0">Connection error. Please try again.</div>';
                });
        });

        // Assign Students Modal
        var assignYearEl = document.getElementById('assignYear');
        var assignFacultyEl = document.getElementById('assignFaculty');
        var assignClassEl = document.getElementById('assignClass');
        var assignSectionsContainer = document.getElementById('assignSectionsContainer');
        var assignPreview = document.getElementById('assignPreview');

        function updateAssignPreview() {
            var year = assignYearEl ? assignYearEl.value : '';
            var checked = assignSectionsContainer ? assignSectionsContainer.querySelectorAll('input[type="checkbox"]:checked') : [];
            var count = checked.length;
            var faculty = assignFacultyEl ? assignFacultyEl.options[assignFacultyEl.selectedIndex]?.text : '';
            var cls = assignClassEl ? assignClassEl.options[assignClassEl.selectedIndex]?.text : '';
            if (!year) { assignPreview.innerHTML = ''; return; }
            var html = '<span class="badge bg-primary me-1">Year: ' + escHtml(year) + '</span>';
            if (count) html += '<span class="badge bg-info me-1">' + count + ' section(s) selected</span>';
            else html += '<span class="badge bg-warning text-dark">No section selected</span>';
            if (faculty && assignFacultyEl.value) html += '<span class="badge bg-secondary me-1">Faculty: ' + escHtml(faculty) + '</span>';
            if (cls && assignClassEl.value) html += '<span class="badge bg-dark">Class: ' + escHtml(cls) + '</span>';
            assignPreview.innerHTML = html;
        }

        document.getElementById('assignStudentsBtn').addEventListener('click', function() {
            if (assignYearEl) assignYearEl.value = '';
            if (assignFacultyEl) assignFacultyEl.value = '';
            if (assignClassEl) assignClassEl.innerHTML = '<option value="">Select Faculty First</option>';
            if (assignSectionsContainer) assignSectionsContainer.innerHTML = '<small class="text-muted">Select a year level first</small>';
            if (assignPreview) assignPreview.innerHTML = '';
            new bootstrap.Modal(document.getElementById('assignStudentsModal')).show();
        });

        if (assignYearEl) {
            assignYearEl.addEventListener('change', function() {
                var year = this.value;
                if (!year) {
                    assignSectionsContainer.innerHTML = '<small class="text-muted">Select a year level first</small>';
                    updateAssignPreview();
                    return;
                }
                assignSectionsContainer.innerHTML = '<small class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>Loading sections...</small>';
                fetch('get_landing_sections.php?year=' + encodeURIComponent(year))
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || res.status !== 'success') {
                            assignSectionsContainer.innerHTML = '<small class="text-danger">' + escHtml(res.message || 'Failed to load sections') + '</small>';
                            return;
                        }
                        if (!res.sections || !res.sections.length) {
                            assignSectionsContainer.innerHTML = '<small class="text-muted">No sections found for this year</small>';
                            return;
                        }
                        var html = '';
                        res.sections.forEach(function(sec){
                            html += '<div class="form-check"><input class="form-check-input" type="checkbox" value="' + escHtml(sec) + '" id="sec_' + escHtml(sec).replace(/[^a-z0-9]/gi,'_') + '"><label class="form-check-label" for="sec_' + escHtml(sec).replace(/[^a-z0-9]/gi,'_') + '">' + escHtml(sec) + ' <small class="text-muted">(' + (res.counts[sec]||0) + ' students)</small></label></div>';
                        });
                        assignSectionsContainer.innerHTML = html;
                        assignSectionsContainer.querySelectorAll('input[type="checkbox"]').forEach(function(cb){
                            cb.addEventListener('change', updateAssignPreview);
                        });
                        updateAssignPreview();
                    })
                    .catch(function(){ assignSectionsContainer.innerHTML = '<small class="text-danger">Connection error</small>'; });
            });
        }

        if (assignFacultyEl) {
            assignFacultyEl.addEventListener('change', function() {
                var fid = this.value;
                if (!fid) {
                    assignClassEl.innerHTML = '<option value="">Select Faculty First</option>';
                    updateAssignPreview();
                    return;
                }
                assignClassEl.innerHTML = '<option value="">Loading classes...</option>';
                fetch('get_faculty_classes.php?faculty_id=' + encodeURIComponent(fid))
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || res.status !== 'success') {
                            assignClassEl.innerHTML = '<option value="">Failed to load classes</option>';
                            return;
                        }
                        if (!res.classes || !res.classes.length) {
                            assignClassEl.innerHTML = '<option value="">No classes found for this faculty</option>';
                            return;
                        }
                        var html = '<option value="">Select Class</option>';
                        res.classes.forEach(function(c){
                            html += '<option value="' + c.class_id + '">' + escHtml(c.display_text) + '</option>';
                        });
                        assignClassEl.innerHTML = html;
                        updateAssignPreview();
                    })
                    .catch(function(){ assignClassEl.innerHTML = '<option value="">Connection error</option>'; });
            });
        }

        if (assignClassEl) assignClassEl.addEventListener('change', updateAssignPreview);

        function showAssignResult(message, type='success') {
            const header = document.getElementById('assignResultHeader');
            const titleEl = document.getElementById('assignResultTitle');
            const bodyEl = document.getElementById('assignResultBody');
            const isSuccess = type === 'success';
            header.style.background = isSuccess ? 'linear-gradient(135deg, #1a4d2e 0%, #2d5f3f 100%)' : 'linear-gradient(135deg, #dc3545 0%, #a71d2a 100%)';
            titleEl.innerHTML = isSuccess ? '<i class="fas fa-check-circle me-2"></i>Success' : '<i class="fas fa-exclamation-triangle me-2"></i>Notice';
            const icon = isSuccess ? '<div class="mb-3"><span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success" style="width:56px;height:56px;"><i class="fas fa-check fa-lg"></i></span></div>' : '<div class="mb-3"><span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger" style="width:56px;height:56px;"><i class="fas fa-exclamation fa-lg"></i></span></div>';
            bodyEl.innerHTML = icon + '<p class="mb-0" style="font-size:0.95rem; color:#ffffff;">' + escHtml(message) + '</p>';
            bodyEl.style.background = '#1e293b';
            bodyEl.style.color = '#ffffff';
            new bootstrap.Modal(document.getElementById('assignResultModal')).show();
        }

        document.getElementById('assignStudentsForm').addEventListener('submit', function(e){
            e.preventDefault();
            var year = assignYearEl.value;
            var facultyId = assignFacultyEl.value;
            var classId = assignClassEl.value;
            var sections = Array.from(assignSectionsContainer.querySelectorAll('input[type="checkbox"]:checked')).map(function(cb){ return cb.value; });
            if (!year) { showAssignResult('Please select a year level.', 'error'); return; }
            if (!sections.length) { showAssignResult('Please select at least one section.', 'error'); return; }
            if (!facultyId) { showAssignResult('Please select a faculty.', 'error'); return; }
            if (!classId) { showAssignResult('Please select a class.', 'error'); return; }
            var btn = document.getElementById('assignSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Assigning...';
            fetch('assign_landing_students.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ year: year, sections: sections, faculty_id: facultyId, class_id: classId })
            }).then(function(r){ return r.json(); }).then(function(res){
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Assign Students';
                if (res.status === 'success') {
                    bootstrap.Modal.getInstance(document.getElementById('assignStudentsModal')).hide();
                    showAssignResult(res.message, 'success');
                    document.getElementById('assignResultModal').addEventListener('hidden.bs.modal', function handler(){
                        document.getElementById('assignResultModal').removeEventListener('hidden.bs.modal', handler);
                        location.reload();
                    });
                } else {
                    showAssignResult(res.message || 'Failed to assign students.', 'error');
                }
            }).catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Assign Students';
                showAssignResult('Connection error. Please try again.', 'error');
            });
        });
    });
})();
</script>

<script>
(function() {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    function esc(s) {
        return String(s === undefined || s === null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function promoPost(action, extra) {
        var payload = Object.assign({ action: action }, extra || {});
        return fetch('landing_promotion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function(r) { return r.json(); });
    }
    ready(function() {
        var fromEl = document.getElementById('promoFrom');
        var toEl = document.getElementById('promoTo');
        var badgeEl = document.getElementById('promoStatusBadge');
        var infoEl = document.getElementById('promoInfo');
        var bannerEl = document.getElementById('promoDueBanner');
        var gradBtn = document.getElementById('promoGraduatesBtn');
        var gradCount = document.getElementById('promoGraduatesCount');
        if (!fromEl || !toEl) return;
        var autoOpened = false;
        var gradYear = 5;
        var gradSel = document.getElementById('promoGradYear');
        var gradTitleLabel = document.getElementById('promoGradYearLabel');
        function gradLabel() { return gradYear + 'th year'; }
        function syncGradYear(v) {
            gradYear = parseInt(v || 5, 10);
            if (gradYear !== 5 && gradYear !== 6 && gradYear !== 7) gradYear = 5;
            if (gradSel) gradSel.value = String(gradYear);
            if (gradTitleLabel) gradTitleLabel.textContent = gradLabel();
        }

        function setBadge(text, cls) {
            badgeEl.textContent = text;
            badgeEl.className = 'badge ' + cls;
        }

        function refresh(autoOpen) {
            return promoPost('get').then(function(res) {
                if (!res || res.status !== 'success') {
                    infoEl.textContent = 'Could not load promotion settings.';
                    return res;
                }
                var s = res.settings || {};
                fromEl.value = s.from_date || '';
                toEl.value = s.to_date || '';
                syncGradYear(s.graduate_year);
                var c = res.counts || { promote: 0, graduates: 0, skipped: 0 };
                if (!s.to_date) {
                    setBadge('Not set', 'bg-secondary');
                    infoEl.textContent = 'Set a From and To date to enable automatic year/section promotion.';
                } else if (res.due) {
                    setBadge('Ended — confirmation required', 'bg-warning text-dark');
                    infoEl.innerHTML = 'Period <strong>' + esc(s.from_date) + ' to ' + esc(s.to_date) + '</strong> has ended. ' +
                        'Confirm to promote <strong>' + c.promote + '</strong> student(s). ' +
                        (c.graduates ? '<strong>' + c.graduates + '</strong> reached ' + gradLabel() + ' and will graduate. ' : '') +
                        (c.skipped ? esc(String(c.skipped)) + ' skipped (non-numeric year).' : '');
                    bannerEl.classList.remove('d-none');
                    bannerEl.classList.add('d-flex');
                    var promoTabDot = document.getElementById('promoTabDot');
                    if (promoTabDot) promoTabDot.classList.remove('d-none');
                    var promoCollapseEl = document.getElementById('lrPromoCollapse');
                    if (promoCollapseEl && window.bootstrap) bootstrap.Collapse.getOrCreateInstance(promoCollapseEl).show();
                } else {
                    var days = Math.ceil((new Date(s.to_date) - new Date(res.today)) / 86400000);
                    setBadge('Active — ' + days + ' day(s) left', 'bg-success');
                    infoEl.innerHTML = 'Period <strong>' + esc(s.from_date) + ' to ' + esc(s.to_date) + '</strong>. ' +
                        'On confirmation, <strong>' + c.promote + '</strong> student(s) move year + 1, section + 1.' +
                        (c.graduates ? ' <strong>' + c.graduates + '</strong> at ' + gradLabel() + ' will graduate.' : '');
                    bannerEl.classList.add('d-none');
                    bannerEl.classList.remove('d-flex');
                    var promoTabDotOff = document.getElementById('promoTabDot');
                    if (promoTabDotOff) promoTabDotOff.classList.add('d-none');
                }
                if (c.graduates > 0) {
                    gradBtn.classList.remove('d-none');
                    gradCount.textContent = c.graduates;
                } else {
                    gradBtn.classList.add('d-none');
                }
                if (autoOpen && res.due && !autoOpened) {
                    autoOpened = true;
                    openPreview();
                }
                return res;
            }).catch(function() {
                infoEl.textContent = 'Connection error loading promotion settings.';
            });
        }

        function renderPreviewTable(rows, cols) {
            if (!rows || !rows.length) return '<div class="alert alert-info mb-0">None.</div>';
            var shown = rows.slice(0, 50);
            var html = '<div class="table-responsive"><table class="table table-sm table-striped table-bordered mb-0"><thead class="table-dark"><tr>';
            cols.forEach(function(c) { html += '<th>' + esc(c[0]) + '</th>'; });
            html += '</tr></thead><tbody>';
            shown.forEach(function(r) {
                html += '<tr>';
                cols.forEach(function(c) { html += '<td>' + esc(r[c[1]] === undefined || r[c[1]] === null ? '' : r[c[1]]) + '</td>'; });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            if (rows.length > shown.length) html += '<small class="text-muted">Showing 50 of ' + rows.length + '.</small>';
            return html;
        }

        var lastPreview = null;
        function openPreview() {
            var body = document.getElementById('promoConfirmBody');
            body.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Loading preview...</span></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('promoConfirmModal')).show();
            promoPost('preview').then(function(res) {
                if (!res || res.status !== 'success') {
                    body.innerHTML = '<div class="alert alert-danger mb-0">' + esc((res && res.message) || 'Failed to load preview.') + '</div>';
                    return;
                }
                lastPreview = res;
                syncGradYear(res.settings && res.settings.graduate_year);
                var html = '<div class="alert alert-info"><i class="fas fa-info-circle me-1"></i>' +
                    'Year + 1 and section + 1 will be applied to <strong>' + res.promote_total + '</strong> student(s). ' +
                    'Nothing changes until you press <strong>Confirm Increment</strong>.</div>';
                html += '<h6 class="fw-bold">To promote (' + res.promote_total + ')</h6>';
                html += renderPreviewTable((res.promote || []).map(function(p) {
                    return { name: p.name, no: p.student_number, course: p.course, year: (p.year || '') + ' → ' + p.new_year, section: (p.section || '') + ' → ' + p.new_section };
                }), [['Name', 'name'], ['Student No.', 'no'], ['Course', 'course'], ['Year', 'year'], ['Section', 'section']]);
                if (res.graduates_total > 0) {
                    html += '<div class="alert alert-warning mt-3 mb-0"><i class="fas fa-graduation-cap me-1"></i>' +
                        '<strong>' + res.graduates_total + '</strong> student(s) reached ' + gradLabel() + ' — they graduate instead of promoting. ' +
                        '<button type="button" class="btn btn-success btn-sm ms-2" id="promoPreviewGradBtn">Review graduates</button></div>';
                }
                if (res.skipped_total > 0) {
                    html += '<h6 class="fw-bold mt-3">Skipped — non-numeric year (' + res.skipped_total + ')</h6>';
                    html += renderPreviewTable((res.skipped || []).map(function(p) {
                        return { name: p.name, no: p.student_number, year: p.year, reason: p.reason };
                    }), [['Name', 'name'], ['Student No.', 'no'], ['Year', 'year'], ['Reason', 'reason']]);
                }
                body.innerHTML = html;
                var gb = document.getElementById('promoPreviewGradBtn');
                if (gb) gb.addEventListener('click', openGraduates);
            }).catch(function() {
                body.innerHTML = '<div class="alert alert-danger mb-0">Connection error. Please try again.</div>';
            });
        }

        function openGraduates() {
            var body = document.getElementById('promoGraduateBody');
            body.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Loading graduates...</span></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('promoGraduateModal')).show();
            promoPost('preview').then(function(res) {
                if (!res || res.status !== 'success') {
                    body.innerHTML = '<div class="alert alert-danger mb-0">Failed to load graduates.</div>';
                    return;
                }
                syncGradYear(res.settings && res.settings.graduate_year);
                var html = '<div class="alert alert-warning"><i class="fas fa-graduation-cap me-1"></i>' +
                    'These <strong>' + res.graduates_total + '</strong> student(s) are at the ' + gradLabel() + ' limit. ' +
                    'Confirm to set them as <strong>graduated</strong> and migrate them to <code>db/graduate_db.db</code>.</div>';
                html += renderPreviewTable((res.graduates || []).map(function(p) {
                    return { name: p.name, no: p.student_number, course: p.course, year: p.year, section: p.section };
                }), [['Name', 'name'], ['Student No.', 'no'], ['Course', 'course'], ['Year', 'year'], ['Section', 'section']]);
                body.innerHTML = html;
            }).catch(function() {
                body.innerHTML = '<div class="alert alert-danger mb-0">Connection error. Please try again.</div>';
            });
        }

        document.getElementById('promoSaveBtn').addEventListener('click', function() {
            var btn = this;
            if (!fromEl.value || !toEl.value) { infoEl.textContent = 'Please pick both From and To dates.'; return; }
            btn.disabled = true;
            promoPost('save_settings', { from_date: fromEl.value, to_date: toEl.value, graduate_year: gradSel ? gradSel.value : gradYear }).then(function(res) {
                btn.disabled = false;
                if (res && res.status === 'success') {
                    infoEl.innerHTML = '<span class="text-success">' + esc(res.message) + '</span>';
                    refresh(false);
                } else {
                    infoEl.innerHTML = '<span class="text-danger">' + esc((res && res.message) || 'Failed to save.') + '</span>';
                }
            }).catch(function() {
                btn.disabled = false;
                infoEl.textContent = 'Connection error. Please try again.';
            });
        });

        document.getElementById('promoPreviewBtn').addEventListener('click', openPreview);
        document.getElementById('promoDueReviewBtn').addEventListener('click', openPreview);
        gradBtn.addEventListener('click', openGraduates);

        document.getElementById('promoConfirmApplyBtn').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Applying...';
            promoPost('apply').then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check me-1"></i>Confirm Increment';
                bootstrap.Modal.getInstance(document.getElementById('promoConfirmModal')).hide();
                if (res && res.status === 'success') {
                    infoEl.innerHTML = '<span class="text-success">' + esc(res.message) + '</span>';
                    refresh(false).then(function() { location.reload(); });
                    if (res.graduates_pending > 0) setTimeout(openGraduates, 600);
                } else {
                    infoEl.innerHTML = '<span class="text-danger">' + esc((res && res.message) || 'Failed to apply.') + '</span>';
                }
            }).catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check me-1"></i>Confirm Increment';
                infoEl.textContent = 'Connection error. Please try again.';
            });
        });

        document.getElementById('promoGraduateApplyBtn').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Migrating...';
            promoPost('graduate_apply', { ids: 'all' }).then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-graduation-cap me-1"></i>Set as Graduates &amp; Migrate';
                bootstrap.Modal.getInstance(document.getElementById('promoGraduateModal')).hide();
                if (res && res.status === 'success') {
                    infoEl.innerHTML = '<span class="text-success">' + esc(res.message) + '</span>';
                    refresh(false).then(function() { location.reload(); });
                } else {
                    infoEl.innerHTML = '<span class="text-danger">' + esc((res && res.message) || 'Failed to migrate.') + '</span>';
                }
            }).catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-graduation-cap me-1"></i>Set as Graduates &amp; Migrate';
                infoEl.textContent = 'Connection error. Please try again.';
            });
        });

        refresh(true);
    });
})();
</script>
