<?php
$role = $role ?? ($_SESSION['role'] ?? 'admin');
$allowedPages = $allowedPages ?? [];

$classTabs = [
    'classes' => [
        'label' => 'Class List',
        'icon' => 'fas fa-list',
    ],
    'maintenance' => [
        'label' => 'Maintenance',
        'icon' => 'fas fa-tools',
    ],
];

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'classes';
if (!isset($classTabs[$activeTab])) {
    $activeTab = 'classes';
}

$visibleTabs = $classTabs;

if (!isset($visibleTabs[$activeTab]) && !empty($visibleTabs)) {
    $activeTab = array_key_first($visibleTabs);
}

if (!function_exists('class_tab_url')) {
    function class_tab_url($tab)
    {
        $params = ['page' => 'class'];
        if ($tab !== 'classes') {
            $params['tab'] = $tab;
        }
        return './?' . http_build_query($params);
    }
}
?>
<style>
    .class-hub .class-tabs-wrapper {
        background: #f1f5f9 !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 14px !important;
        padding: 6px !important;
        margin-bottom: 1.25rem !important;
        box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06) !important;
    }

    .class-hub .class-tabs {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 6px !important;
        list-style: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border-bottom: none !important;
    }

    .class-hub .class-tabs .nav-item {
        flex: 1 1 auto !important;
        min-width: 140px !important;
        margin-bottom: 0 !important;
        border-radius: 10px !important;
        overflow: hidden !important;
        transition: all 0.25s ease !important;
    }

    .class-hub .class-tabs .nav-item:hover:not(.active) {
        background-color: #ffffff !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
    }

    .class-hub .class-tabs .nav-item.active {
        background-color: #2563eb !important;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35) !important;
    }

    .class-hub .class-tabs .nav-link {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.5rem !important;
        width: 100% !important;
        padding: 0.65rem 1.1rem !important;
        font-size: 0.9rem !important;
        font-weight: 500 !important;
        color: #64748b !important;
        text-decoration: none !important;
        border: none !important;
        border-radius: 10px !important;
        background: transparent !important;
        background-color: transparent !important;
        box-shadow: none !important;
        transition: all 0.25s ease !important;
    }

    .class-hub .class-tabs .nav-link i,
    .class-hub .class-tabs .nav-link span {
        color: inherit !important;
    }

    .class-hub .class-tabs .nav-link i {
        font-size: 0.95rem !important;
        opacity: 0.9 !important;
        transition: transform 0.25s ease, opacity 0.25s ease !important;
    }

    .class-hub .class-tabs .nav-item:hover:not(.active) .nav-link {
        color: #334155 !important;
    }

    .class-hub .class-tabs .nav-item:hover:not(.active) .nav-link i {
        transform: scale(1.06) !important;
        opacity: 1 !important;
    }

    .class-hub .class-tabs .nav-item.active .nav-link,
    .class-hub .class-tabs .nav-item.active .nav-link:hover,
    .class-hub .class-tabs .nav-item.active .nav-link:focus,
    .class-hub .class-tabs .nav-item.active .nav-link:active {
        color: #ffffff !important;
        font-weight: 600 !important;
        background: transparent !important;
        background-color: transparent !important;
        border: none !important;
        box-shadow: none !important;
    }

    .class-hub .class-tabs .nav-item.active .nav-link i,
    .class-hub .class-tabs .nav-item.active .nav-link span,
    .class-hub .class-tabs .nav-item.active .nav-link:hover i,
    .class-hub .class-tabs .nav-item.active .nav-link:hover span,
    .class-hub .class-tabs .nav-item.active .nav-link:focus i,
    .class-hub .class-tabs .nav-item.active .nav-link:focus span {
        color: #ffffff !important;
        opacity: 1 !important;
    }

    .class-tab-panel {
        animation: classTabFadeIn 0.3s ease;
    }

    @keyframes classTabFadeIn {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 576px) {
        .class-hub .class-tabs .nav-item {
            min-width: 100% !important;
        }

        .class-hub .class-tabs .nav-link {
            justify-content: flex-start !important;
            padding: 0.75rem 1rem !important;
        }
    }

    .pending-section-btn { min-width: 60px; font-weight: 600; border-radius: 8px; }
    .pending-section-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(37,99,235,.15); }
    /* ---- Professional pending-students modal ---- */
    #pendingModal .modal-content { border: 0; border-radius: 18px; overflow: hidden; }
    #pendingModal .modal-header { border: 0; background: linear-gradient(135deg, #4318FF 0%, #7c3aed 100%); color: #fff; padding: 1rem 1.25rem; }
    #pendingModal .modal-footer { border-top: 1px solid var(--horizon-divider, #eef2f7); padding: .8rem 1.25rem; }
    #pendingModalBody { max-height: 46vh; overflow-y: auto; }
    .pending-student-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid #eef2f7; border-radius: 12px; margin-bottom: 8px; background: #fff; transition: box-shadow .15s ease, border-color .15s ease; }
    .pending-student-item:hover { border-color: #c7d2fe; box-shadow: 0 2px 10px rgba(67,24,255,.08); }
    .pending-avatar { width: 40px; height: 40px; min-width: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 13px; letter-spacing: .02em; }
    .pending-info { flex: 1; min-width: 0; }
    .pending-name { font-weight: 700; font-size: 13.5px; color: var(--horizon-text-title, #0f172a); line-height: 1.25; }
    .pending-meta { font-size: 11.5px; color: #64748b; margin-top: 2px; }
    .pending-chip { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 8px; border-radius: 999px; background: #eef2ff; color: #4338ca; border: 1px solid #e0e7ff; margin-left: 6px; vertical-align: 1px; }
    .pending-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .assign-one-btn { border-radius: 999px; font-weight: 700; font-size: 11.5px; padding: 5px 14px; }
    .dismiss-pending-btn { opacity: .65; border-radius: 50%; width: 28px; height: 28px; padding: 0; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .dismiss-pending-btn:hover { opacity: 1; background: #dc3545; color: #fff; border-color: #dc3545; }
    .duplicate-tooltip { cursor: help; }
    html[data-theme="dark"] #pendingModal .modal-content, body[data-theme="dark"] #pendingModal .modal-content { background: #1e293b; }
    html[data-theme="dark"] #pendingModal .modal-footer, body[data-theme="dark"] #pendingModal .modal-footer { border-color: #334155; }
    html[data-theme="dark"] .pending-student-item, body[data-theme="dark"] .pending-student-item { background: #0f172a; border-color: #334155; }
    html[data-theme="dark"] .pending-student-item:hover, body[data-theme="dark"] .pending-student-item:hover { border-color: #6366f1; box-shadow: none; }
    html[data-theme="dark"] .pending-name, body[data-theme="dark"] .pending-name { color: #f1f5f9; }
    html[data-theme="dark"] .pending-meta, body[data-theme="dark"] .pending-meta, html[data-theme="dark"] #pendingModal .text-muted, body[data-theme="dark"] #pendingModal .text-muted { color: #94a3b8 !important; }
    html[data-theme="dark"] .pending-chip, body[data-theme="dark"] .pending-chip { background: rgba(99,102,241,.18); border-color: rgba(99,102,241,.4); color: #c7d2fe; }
</style>

<div class="horizon-card class-hub">
    
    <div class="horizon-card-body p-0">
        <?php if (count($visibleTabs) > 1): ?>
        <div class="class-tabs-wrapper">
            <ul class="class-tabs" role="tablist">
                <?php foreach ($visibleTabs as $key => $tab): ?>
                <li class="nav-item <?php echo ($activeTab === $key) ? 'active' : '' ?>" role="presentation">
                    <a class="nav-link"
                       href="<?php echo class_tab_url($key); ?>"
                       role="tab"
                       aria-selected="<?php echo ($activeTab === $key) ? 'true' : 'false'; ?>">
                        <i class="<?php echo $tab['icon']; ?>"></i>
                        <span><?php echo $tab['label']; ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="class-tab-panel">
            <?php if ($activeTab === 'classes'): ?>
            <!-- Class List Tab -->
            <div class="p-3 d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Classes</h4>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm" id="create_new">
                        <i class="fas fa-plus me-1"></i> Add Class
                    </button>
                </div>
            </div>
            <div class="table-responsive p-3 pt-0">
                <table class="table horizon-table align-middle mb-0" id="class-table">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 50px;">#</th>
                        <th>Subject & Code</th>
                        <th class="text-center">Course / Level</th>
                        <th class="text-center">Section</th>
                        <th class="text-center">Semester</th>
                        <th class="text-center">Academic Year</th>
                        <th class="text-center">Schedule</th>
                        <th class="text-center" style="width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Pre-compute pending counts per class for instant display (no flicker)
                    $pendingMap = [];
                    // Pre-compute enrolled counts per section (not whole level) for badge
                    $levelCounts = [];
                    try {
                        // Section counts: enrolled students per grade+section
                        $lcQ = $conn->query("SELECT c.grade AS grade, c.section AS section, COUNT(sl.student_id) AS cnt FROM student_list sl JOIN class_list c ON sl.class_id = c.class_id GROUP BY c.grade, c.section");
                        if ($lcQ) { while ($lr = $lcQ->fetchArray(SQLITE3_ASSOC)) { $k = trim((string)($lr['grade'] ?? '')) . '|' . trim((string)($lr['section'] ?? '')); $levelCounts[$k] = (int)$lr['cnt']; } }
                    } catch (Exception $e) { $levelCounts = []; }
                    try {
                        require_once("landing_db.php");
                        require_once("landing_irregular.php");
                        $ldb = landing_db();
                        $allPending = [];
                        // All section rows are pending candidates (no status filter: a student
                        // enrolled in one subject class is still pending for its other subjects;
                        // the per-class $already check below is the dedupe guard).
                        $pr = $ldb->query("SELECT course, section, student_number, is_irregular, irregular_subjects FROM landing_registrations");
                        if($pr){ while($prow=$pr->fetchArray(SQLITE3_ASSOC)){ $allPending[]=$prow; } }
                        // For each class, count pending where section+course matches and not already in this class and not passed in previous year.
                        // Irregular enrollees match their OWN section classes too, plus any
                        // picked irregular_subjects entry for this class's section + subject.
                        $tmpMap = [];
                        $cmapQ = $conn->query("SELECT c.class_id, c.grade, c.section, c.course_no, s.name AS subject_name FROM class_list c JOIN subjects s ON c.subject_id = s.subject_id");
                        $classInfos = [];
                        if($cmapQ){ while($crow=$cmapQ->fetchArray(SQLITE3_ASSOC)){ $classInfos[(int)$crow['class_id']] = $crow; } }
                        foreach($classInfos as $cid=>$cinfo){
                            $cnt=0;
                            $secLower=strtolower(trim($cinfo['section']??''));
                            $courseLower=strtolower(trim($cinfo['grade']??''));
                            $codeLower=strtolower(trim($cinfo['course_no']??''));
                            $nameLower=strtolower(trim($cinfo['subject_name']??''));
                            foreach($allPending as $prw){
                                $rowSecLower=strtolower(trim($prw['section']??''));
                                $rowCourseLower=strtolower(trim($prw['course']??''));
                                $courseOk=($courseLower==='' || $rowCourseLower===$courseLower);
                                $matched=false;
                                if(landing_row_is_irregular($prw)){
                                    if($rowSecLower===$secLower && $courseOk) $matched=true;
                                    elseif($courseOk && irregular_match_for_class($prw, $secLower, $codeLower, $nameLower)) $matched=true;
                                } else {
                                    if($rowSecLower===$secLower && $courseOk) $matched=true;
                                }
                                if(!$matched) continue;
                                // Check not already in this class
                                $escNum=$conn->escapeString($prw['student_number']??'');
                                if($escNum==='') continue;
                                $already=$conn->querySingle("SELECT COUNT(*) FROM student_list WHERE class_id='{$cid}' AND student_number='{$escNum}'");
                                if($already>0) continue;
                                $cnt++;
                            }
                            if($cnt>0) $pendingMap[$cid]=$cnt;
                        }
                    } catch(Exception $e){}
                    $sql = "SELECT c.*, s.name as subject_name FROM `class_list` c 
                            INNER JOIN `subjects` s ON c.subject_id = s.subject_id 
                            ORDER BY c.year DESC, c.semester, c.grade, c.section ASC";
                    $qry = $conn->query($sql);
                    $i = 1;
                    if ($qry):
                        while($row = $qry->fetchArray(SQLITE3_ASSOC)):
                            $pcnt = $pendingMap[(int)($row['class_id']??0)] ?? 0;
                    ?>
                    <tr>
                        <td class="text-center fw-bold text-muted"><?php echo $i++; ?></td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($row['subject_name'] ?? ''); ?></span>
                                <small class="text-muted"><code class="text-primary"><?php echo htmlspecialchars($row['course_no'] ?? ''); ?></code></small>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php $gradeKey = trim((string)($row['grade'] ?? '')); $sectionKey = trim((string)($row['section'] ?? '')); $lvlCnt = $levelCounts[$gradeKey . '|' . $sectionKey] ?? 0; ?>
                            <span class="badge badge-brand course-level-badge" data-grade="<?php echo htmlspecialchars($gradeKey, ENT_QUOTES); ?>" data-section="<?php echo htmlspecialchars($sectionKey, ENT_QUOTES); ?>" title="<?php echo $lvlCnt; ?> enrolled student(s) in <?php echo htmlspecialchars($gradeKey . ' - ' . $sectionKey, ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($gradeKey); ?>
                                <span class="badge ms-1 course-level-count" style="font-size:0.65rem; min-width:18px; background:#fff; color:#000 !important; border:1px solid #000;"><?php echo $lvlCnt; ?></span>
                            </span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm <?php echo $pcnt>0?'btn-primary':'btn-outline-primary'; ?> pending-section-btn position-relative" data-section="<?php echo htmlspecialchars($row['section'] ?? '', ENT_QUOTES); ?>" data-grade="<?php echo htmlspecialchars($row['grade'] ?? '', ENT_QUOTES); ?>" data-class-id="<?php echo $row['class_id']; ?>" data-class-name="<?php echo htmlspecialchars($row['subject_name'] . ' - ' . $row['grade'] . ' - ' . $row['section'], ENT_QUOTES); ?>" title="Click to view pending students for this section">
                                <?php echo htmlspecialchars($row['section'] ?? ''); ?>
                                <span class="pending-count-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:0.6rem; <?php echo $pcnt>0?'':'display:none;'; ?>"><?php echo $pcnt>0?$pcnt:0; ?></span>
                            </button>
                        </td>
                        <td class="text-center"><?php echo htmlspecialchars($row['semester'] ?? ''); ?></td>
                        <td class="text-center fw-semibold"><?php echo htmlspecialchars($row['year'] ?? ''); ?></td>
                        <td class="text-center"><small class="text-warning"><?php echo htmlspecialchars($row['schedule'] ?? 'TBA'); ?></small></td>
                        <td class="text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Manage
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item edit_data" data-id="<?php echo $row['class_id']; ?>" href="javascript:void(0)">
                                            <i class="fas fa-edit text-primary me-2"></i> Edit Details
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="./?page=assessments&class_id=<?php echo $row['class_id']; ?>">
                                            <i class="fas fa-tasks text-success me-2"></i> Assessments
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item delete_data text-danger" 
                                           data-id="<?php echo $row['class_id']; ?>" 
                                           data-name="<?php echo htmlspecialchars($row['subject_name']." - ".$row['grade'].$row['section'].' ('.$row['semester'].' '.$row['year'].')'); ?>" 
                                           href="javascript:void(0)">
                                            <i class="fas fa-trash-alt text-danger me-2"></i> Delete Class
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
                </table>
            </div>
            <?php elseif ($activeTab === 'maintenance'): ?>
            <!-- Maintenance Tab -->
            <div class="row g-0">
                <!-- Components Section -->
                <div class="col-lg-6 p-4 border-end">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="text-primary mb-0">
                            <i class="fas fa-cubes me-2"></i>Grading Components
                        </h5>
                        <button id="new_component" class="btn btn-sm btn-primary" title="Add New Component">
                            <i class="fas fa-plus me-1"></i> Add Component
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="componentsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Component Name</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $dept_qry = $conn->query("SELECT * FROM `grading_components` order by `name` asc");
                                $has_components = false;
                                while($row = $dept_qry->fetchArray()): 
                                    $has_components = true;
                                ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($row['name']) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="edit_component btn btn-outline-primary" 
                                                    data-id="<?php echo $row['component_id'] ?>" 
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Edit Component">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <button type="button" class="delete_component btn btn-outline-danger" 
                                                    data-id="<?php echo $row['component_id'] ?>" 
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Delete Component">
                                                <i class="fas fa-trash"></i> 
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if(!$has_components): ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No components found
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Subjects Section -->
                <div class="col-lg-6 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="text-primary mb-0">
                            <i class="fas fa-book me-2"></i>Subject Management
                        </h5>
                        <button id="new_subject" class="btn btn-sm btn-primary" title="Add New Subject">
                            <i class="fas fa-plus me-1"></i> Add Subject
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="subjectsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Subject Name</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $subj_qry = $conn->query("SELECT * FROM `subjects` order by `name` asc");
                                $has_subjects = false;
                                while($row = $subj_qry->fetchArray()): 
                                    $has_subjects = true;
                                ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($row['name']) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="manage_percentage btn btn-outline-info" 
                                                    data-id="<?php echo $row['subject_id'] ?>" 
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Manage Components">
                                                <i class="fas fa-percentage"></i> Percentage
                                            
                                            <button type="button" class="edit_subject btn btn-outline-primary" 
                                                    data-id="<?php echo $row['subject_id'] ?>" 
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Edit Subject">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <button type="button" class="delete_subject btn btn-outline-danger" 
                                                    data-id="<?php echo $row['subject_id'] ?>" 
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Delete Subject">
                                                <i class="fas fa-trash"></i> 
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if(!$has_subjects): ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No subjects found
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-light d-flex justify-content-between align-items-center border-top">
                <small class="text-muted">Last updated: <?php echo date('F j, Y, g:i a') ?></small>
                <div>
                    <?php 
                    // Reset the result pointer and count components
                    $dept_qry->reset();
                    $component_count = 0;
                    while($dept_qry->fetchArray()) $component_count++;
                    
                    // Reset the result pointer and count subjects
                    $subj_qry->reset();
                    $subject_count = 0;
                    while($subj_qry->fetchArray()) $subject_count++;
                    ?>
                    <span class="badge bg-light text-dark border">Total Components: <?php echo $component_count; ?></span>
                    <span class="badge bg-light text-dark border ms-2">Total Subjects: <?php echo $subject_count; ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Pending Students Modal (professional) -->
<div class="modal fade" id="pendingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:560px">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3 w-100">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width:44px;height:44px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.25)"><i class="fas fa-user-clock"></i></span>
                    <div class="flex-grow-1" style="min-width:0">
                        <h5 class="modal-title fw-bold mb-0">Pending Students</h5>
                        <small id="pendingModalSub" style="opacity:.85"></small>
                    </div>
                    <span class="badge rounded-pill bg-light text-dark flex-shrink-0" id="pendingModalCount" style="font-size:.8rem">0</span>
                    <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="p-3 pb-2" id="pendingSearchWrap" style="display:none">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control" id="pendingSearch" placeholder="Search name or student number…" autocomplete="off">
                </div>
            </div>
            <div class="modal-body pt-2" id="pendingModalBody" style="min-height:120px">
                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary"></div><div class="small mt-2">Loading pending students...</div></div>
            </div>
            <div class="modal-footer" id="pendingModalFooter" style="display:none">
                <small class="text-muted me-auto" id="pendingModalFooterText"></small>
                <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success rounded-pill px-4" id="assignAllBtn"><i class="fas fa-user-plus me-1"></i>Assign All</button>
            </div>
        </div>
    </div>
</div>

<!-- Global Pending Modal (popup on class navigation) -->
<div class="modal fade" id="globalPendingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Pending Students</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="globalPendingBody">
                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary"></div><div class="small mt-2">Loading pending students...</div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Pending Assign Confirm Popup (replaces alert/confirm) -->
<div class="modal fade" id="pendingConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
                <h5 class="modal-title fw-bold"><i class="fas fa-question-circle me-2"></i>Confirm Assign</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="pendingConfirmBody">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;"><i class="fas fa-user-plus fa-lg"></i></div>
                <p class="mb-1 fw-bold" id="pendingConfirmText"></p>
                <small class="text-muted">This will copy their records to the selected class and mark them as enrolled.</small>
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success rounded-pill px-4" id="pendingConfirmBtn"><i class="fas fa-check me-1"></i>Confirm Assign</button>
            </div>
        </div>
    </div>
</div>

<script>
    $(function(){
        $('#create_new').click(function(){
            uni_modal('Add New Class', "manage_class.php");
        });
        
        $('.edit_data').click(function(){
            uni_modal('Edit Class Details', "manage_class.php?id=" + $(this).attr('data-id'));
        });
        
        $('.delete_data').click(function(){
            _conf("Are you sure you want to delete <b>" + $(this).attr('data-name') + "</b> from Class List?", 'delete_data', [$(this).attr('data-id')]);
        });
        
        // Only initialize class table if on classes tab
        <?php if ($activeTab === 'classes'): ?>
        $('#class-table').dataTable({
            columnDefs: [
                { orderable: false, targets: 7, className: "text-center" },
                { targets: 0, className: "text-center" },
                { targets: [2,3,4,5,6], className: "text-center" }
            ],
            pageLength: 10,
            responsive: true,
            order: [[5, 'desc'], [0, 'asc']]
        });
        <?php endif; ?>

        // Pending students tooltip for sections
        let currentTooltipClassId = null;
        let currentTooltipSection = null;

        // Pending students modal helpers (professional)
        function pendingModal(){
            return bootstrap.Modal.getOrCreateInstance(document.getElementById('pendingModal'));
        }
        var pendingAvatarColors = ['#4318FF','#0ea5e9','#059669','#d97706','#dc2626','#7c3aed','#db2777','#0891b2'];

        function pendingInitials(name){
            var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) return '?';
            var first = parts[0].charAt(0), last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
            return (first + last).toUpperCase();
        }
        function pendingAvatarColor(name){
            var s = String(name || ''), h = 0;
            for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
            return pendingAvatarColors[h % pendingAvatarColors.length];
        }
        function pendingStudentRow(s){
            var esc = function(v){ return $('<div>').text(v || '').html(); };
            var irrBadge = (s.is_irregular) ? ' <span class="badge bg-warning text-dark" style="font-size:0.6rem;">Irregular</span>' : '';
            var irrSub = (s.irregular_match && (s.irregular_match.code || s.irregular_match.name))
                ? '<br><span class="text-primary">' + esc((s.irregular_match.code || '') + (s.irregular_match.name ? ' — ' + s.irregular_match.name : '')) + '</span>' : '';
            var meta = esc(s.student_number) + (s.course ? ' • ' + esc(s.course) : '') + ' • ' + esc(s.year || '') + ' - ' + esc(s.section || '') + irrSub;
            var searchKey = ((s.name || '') + ' ' + (s.student_number || '')).toLowerCase();
            return '<div class="pending-student-item" data-pending-id="' + (s.id || '') + '" data-search="' + esc(searchKey) + '">'
                 + '<div class="pending-avatar" style="background:' + pendingAvatarColor(s.name) + '">' + esc(pendingInitials(s.name)) + '</div>'
                 + '<div class="pending-info">'
                 + '<div class="pending-name" title="' + esc(s.name) + '">' + esc(s.name) + irrBadge + '</div>'
                 + '<div class="pending-meta">' + meta + '</div>'
                 + '</div>'
                 + '<div class="pending-actions">'
                 + '<button type="button" class="btn btn-sm btn-success assign-one-btn" data-id="' + (s.id || '') + '" data-name="' + esc(s.name) + '" title="Assign this student to the class"><i class="fas fa-user-plus me-1" style="font-size:0.65rem;"></i>Assign</button>'
                 + '<button type="button" class="btn btn-sm btn-outline-secondary dismiss-pending-btn ms-1" data-id="' + (s.id || '') + '" title="Remove from pending"><i class="fas fa-times" style="font-size:0.7rem;"></i></button>'
                 + '</div></div>';
        }
        function pendingEmptyHtml(section){
            return '<div class="p-4 text-center text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><div class="small">No pending students for <strong>' + $('<div>').text(section).html() + '</strong></div><small>All caught up!</small></div>';
        }
        function pendingCountRefresh(){
            var $list = $('#pendingModalBody .pending-student-item');
            var n = $list.length;
            $('#pendingModalCount').text(n);
            if (n === 0) {
                $('#pendingModalBody').html(pendingEmptyHtml(currentTooltipSection || ''));
                $('#pendingModalFooterText').text('All caught up');
                $('#assignAllBtn').hide();
                $('#pendingSearchWrap').hide();
            } else {
                $('#pendingModalFooterText').text(n + ' pending for ' + (currentTooltipSection || ''));
                $('#assignAllBtn').data('count', n).html('<i class="fas fa-user-plus me-1"></i>Assign All (' + n + ')').show();
            }
            return n;
        }

        function loadPendingForSection(section, grade, classId, className) {
            currentTooltipClassId = classId;
            currentTooltipSection = section;
            $('#pendingModalSub').text(section + (grade ? ' (' + grade + ')' : '') + (className ? ' • ' + className : ''));
            $('#pendingModalCount').text('…');
            $('#pendingModalBody').html('<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary"></div><div class="small mt-2">Loading pending students...</div></div>');
            $('#pendingSearch').val('');
            $('#pendingSearchWrap').hide();
            $('#pendingModalFooter').hide();
            pendingModal().show();
            $.ajax({
                url: './get_pending_students.php',
                method: 'GET',
                data: { section: section, grade: grade, class_id: classId },
                dataType: 'json',
                success: function(resp) {
                    if (resp.status !== 'success') {
                        $('#pendingModalBody').html('<div class="p-3 text-center text-danger small"><i class="fas fa-exclamation-triangle me-1"></i>' + (resp.message || 'Failed to load') + '</div>');
                        return;
                    }
                    if (!resp.students || resp.students.length === 0) {
                        $('#pendingModalBody').html(pendingEmptyHtml(section));
                        $('#pendingModalCount').text('0');
                        return;
                    }
                    var html = '';
                    resp.students.forEach(function(s){ html += pendingStudentRow(s); });
                    html += '<div id="pendingNoMatch" class="p-3 text-center text-muted small" style="display:none"><i class="fas fa-search me-1"></i>No student matches your search.</div>';
                    $('#pendingModalBody').html(html);
                    $('#pendingModalCount').text(resp.count);
                    $('#pendingModalFooterText').text(resp.count + ' pending for ' + section);
                    $('#pendingSearchWrap').show();
                    $('#pendingModalFooter').css('display', 'flex');
                    $('#assignAllBtn').data('section', section).data('grade', grade).data('class-id', classId).data('count', resp.count).prop('disabled', false).show().html('<i class="fas fa-user-plus me-1"></i>Assign All (' + resp.count + ')');
                },
                error: function() {
                    $('#pendingModalBody').html('<div class="p-3 text-center text-danger small">Connection error. Please try again.</div>');
                }
            });
        }

        // Load pending counts per class (per-subject, not per-section) + global notification
        function refreshPendingBadges() {
            const $btns = $('.pending-section-btn');
            if (!$btns.length) { updateGlobalPending(0, 0); return; }
            let totalPending = 0;
            const pendingSections = {};
            let done = 0;
            $btns.each(function(){
                const $btn = $(this);
                const section = $btn.data('section');
                const grade = $btn.data('grade');
                const classId = $btn.data('class-id');
                if (!section || !classId) { done++; if(done===$btns.length) updateGlobalPending(totalPending, Object.keys(pendingSections).length); return; }
                $.ajax({
                    url: './get_pending_students.php',
                    method: 'GET',
                    data: { section: section, grade: grade, class_id: classId },
                    dataType: 'json',
                    success: function(resp){
                        if (resp.status === 'success' && resp.count > 0) {
                            $btn.find('.pending-count-badge').text(resp.count).show();
                            $btn.removeClass('btn-outline-primary').addClass('btn-primary position-relative');
                            totalPending += resp.count;
                            pendingSections[String(section).toLowerCase().trim()] = true;
                        } else {
                            $btn.find('.pending-count-badge').hide();
                            $btn.removeClass('btn-primary').addClass('btn-outline-primary');
                        }
                    },
                    complete: function(){
                        done++;
                        if (done === $btns.length) updateGlobalPending(totalPending, Object.keys(pendingSections).length);
                    }
                });
            });
        }
        function updateGlobalPending(total, sections) {
            const $btn = $('#globalPendingBtn');
            const $count = $('#globalPendingCount');
            if (total > 0) {
                $count.text(total);
                $btn.show();
                // Popup notification on class navigation
                if (!sessionStorage.getItem('pendingNotified')) {
                    setTimeout(() => {
                        if (window.showAppToast) window.showAppToast('warning', total + ' pending student(s) in ' + (sections || 0) + ' section(s) — click Pending or any section badge to assign.');
                        sessionStorage.setItem('pendingNotified', '1');
                    }, 800);
                }
                // Pulse animation
                $btn.addClass('shadow');
            } else {
                $btn.hide();
            }
        }
        refreshPendingBadges();
        // Live update: pending/deleted on landing immediately reflects without admin visiting landing_registrations.php
        setInterval(refreshPendingBadges, 10000);
        // Also refresh when tab becomes visible (user switches back)
        document.addEventListener('visibilitychange', function(){ if (!document.hidden) refreshPendingBadges(); });

        // Course/Level enrolled counts per section - dynamic (no page reload)
        function refreshLevelCounts() {
            $.ajax({
                url: './get_level_counts.php',
                method: 'GET',
                dataType: 'json',
                success: function(resp){
                    if (resp.status === 'success' && resp.counts) {
                        $('.course-level-count').each(function(){
                            var badge = $(this).closest('.course-level-badge');
                            var grade = badge.data('grade');
                            var section = badge.data('section');
                            var key = String(grade ?? '') + '|' + String(section ?? '');
                            if (resp.counts.hasOwnProperty(key)) {
                                $(this).text(resp.counts[key]);
                                badge.attr('title', resp.counts[key] + ' enrolled student(s) in ' + grade + ' - ' + section);
                            } else if (grade !== undefined && section !== undefined) {
                                // No entry means 0 for this section
                                $(this).text(0);
                                badge.attr('title', '0 enrolled student(s) in ' + grade + ' - ' + section);
                            }
                        });
                    }
                }
            });
        }
        // initial + poll + after assign
        setInterval(refreshLevelCounts, 10000);
        document.addEventListener('visibilitychange', function(){ if (!document.hidden) refreshLevelCounts(); });

        $(document).on('click', '.pending-section-btn', function(e){
            e.stopPropagation();
            const $btn = $(this);
            const section = $btn.data('section');
            const grade = $btn.data('grade');
            const classId = $btn.data('class-id');
            const className = $btn.data('class-name');
            const pmEl = document.getElementById('pendingModal');
            if (pmEl.classList.contains('show') && currentTooltipSection === section && String(currentTooltipClassId) === String(classId)) {
                bootstrap.Modal.getInstance(pmEl).hide();
                return;
            }
            loadPendingForSection(section, grade, classId, className);
        });

        // Live search inside the modal
        $(document).on('input', '#pendingSearch', function(){
            var q = String($(this).val() || '').toLowerCase().trim();
            var visible = 0;
            $('#pendingModalBody .pending-student-item').each(function(){
                var hit = !q || String($(this).data('search') || '').indexOf(q) !== -1;
                $(this).toggle(hit);
                if (hit) visible++;
            });
            $('#pendingNoMatch').toggle(visible === 0);
            $('#pendingModalCount').text(visible);
        });

        // Assign ALL pending for this section to this class - uses popup confirmation (per-subject, not global)
        let pendingAssignData = null;
        $(document).on('click', '#assignAllBtn', function(){
            const $btn = $(this);
            const section = $btn.data('section');
            const grade = $btn.data('grade');
            const classId = $btn.data('class-id');
            const count = $btn.data('count') || 0;
            if (!section || !classId) return;
            pendingAssignData = { mode: 'bulk', section, grade, classId, count, $btn };
            $('#pendingConfirmText').html('Assign all <strong>' + count + '</strong> pending student(s) from section <strong>' + $('<div>').text(section).html() + '</strong> to this class?');
            // Hide pending modal and show confirmation modal (per-subject)
            pendingModal().hide();
            $('#pendingConfirmModal .modal-header').removeClass('bg-danger').addClass('bg-primary').removeClass('bg-success');
            new bootstrap.Modal(document.getElementById('pendingConfirmModal')).show();
        });
        // Assign ONE pending student (individual assignment from the modal)
        $(document).on('click', '.assign-one-btn', function(){
            const $btn = $(this);
            const landingId = $btn.data('id');
            const studentName = $btn.data('name') || 'this student';
            if (!landingId || !currentTooltipClassId) return;
            if ($btn.prop('disabled')) return;
            pendingAssignData = { mode: 'single', landingId, studentName, classId: currentTooltipClassId, $btn, original: $btn.html() };
            $('#pendingConfirmText').html('Assign <strong>' + $('<div>').text(studentName).html() + '</strong> to this class?');
            pendingModal().hide();
            $('#pendingConfirmModal .modal-header').removeClass('bg-danger').addClass('bg-primary').removeClass('bg-success');
            new bootstrap.Modal(document.getElementById('pendingConfirmModal')).show();
        });
        $('#pendingConfirmBtn').on('click', function(){
            if (!pendingAssignData) return;
            const $modal = bootstrap.Modal.getInstance(document.getElementById('pendingConfirmModal'));
            if ($modal) $modal.hide();
            // Mark as confirmed to prevent hidden handler from clearing prematurely
            pendingAssignData.confirmed = true;
            if (pendingAssignData.mode === 'single') { pendingAssignSingle(); return; }
            const { section, grade, classId, count, $btn } = pendingAssignData;
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Assigning...');
            $.ajax({
                url: './assign_bulk_by_section.php',
                method: 'POST',
                data: { section: section, grade: grade, class_id: classId },
                dataType: 'json',
                success: function(resp){
                    $btn.prop('disabled', false).html('<i class="fas fa-user-plus me-1"></i>Assign All (' + count + ')');
                    if (resp.status === 'success') {
                        $('#pendingModalBody').html('<div class="p-4 text-center text-success"><i class="fas fa-check-circle fa-2x mb-2"></i><div class="small fw-bold">' + $('<div>').text(resp.message).html() + '</div></div>');
                        $('#pendingModalFooter').hide();
                        $('#pendingSearchWrap').hide();
                        const $badge = $('.pending-section-btn').filter(function(){ return String($(this).data('class-id')) === String(classId) && $(this).data('section') === section; }).find('.pending-count-badge');
                        $badge.hide();
                        refreshPendingBadges();
                        refreshLevelCounts();
                        if (window.showAppToast) window.showAppToast('success', resp.message);
                        setTimeout(() => { pendingModal().hide(); }, 1500);
                    } else {
                        $('#pendingConfirmText').text(resp.message || 'Failed to assign students');
                        $('#pendingConfirmModal .modal-header').removeClass('bg-primary').addClass('bg-danger');
                        new bootstrap.Modal(document.getElementById('pendingConfirmModal')).show();
                        if (window.showAppToast) window.showAppToast('danger', resp.message);
                    }
                    pendingAssignData = null;
                },
                error: function(){
                    $btn.prop('disabled', false).html('<i class="fas fa-user-plus me-1"></i>Assign All (' + count + ')');
                    $('#pendingConfirmText').text('Connection error. Please try again.');
                    $('#pendingConfirmModal .modal-header').removeClass('bg-primary').addClass('bg-danger');
                    new bootstrap.Modal(document.getElementById('pendingConfirmModal')).show();
                    if (window.showAppToast) window.showAppToast('danger', 'Connection error');
                    pendingAssignData = null;
                }
            });
        });
        function pendingAssignSingle(){
            const data = pendingAssignData;
            if (!data) return;
            const $btn = data.$btn;
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.ajax({
                url: './assign_single_pending.php',
                method: 'POST',
                data: { landing_id: data.landingId, class_id: data.classId },
                dataType: 'json',
                success: function(resp){
                    if (resp.status === 'success') {
                        var $item = $btn.closest('.pending-student-item');
                        // Re-open the pending modal behind the toast, minus this row
                        pendingModal().show();
                        $item.fadeOut(180, function(){
                            $(this).remove();
                            pendingCountRefresh();
                            refreshPendingBadges();
                            refreshLevelCounts();
                        });
                        if (window.showAppToast) window.showAppToast('success', resp.message);
                    } else {
                        $btn.prop('disabled', false).html(data.original);
                        $('#pendingConfirmText').text(resp.message || 'Failed to assign student');
                        $('#pendingConfirmModal .modal-header').removeClass('bg-primary').addClass('bg-danger');
                        new bootstrap.Modal(document.getElementById('pendingConfirmModal')).show();
                        if (window.showAppToast) window.showAppToast('danger', resp.message);
                    }
                    pendingAssignData = null;
                },
                error: function(){
                    $btn.prop('disabled', false).html(data.original);
                    $('#pendingConfirmText').text('Connection error. Please try again.');
                    $('#pendingConfirmModal .modal-header').removeClass('bg-primary').addClass('bg-danger');
                    new bootstrap.Modal(document.getElementById('pendingConfirmModal')).show();
                    if (window.showAppToast) window.showAppToast('danger', 'Connection error');
                    pendingAssignData = null;
                }
            });
        }
        // If user dismisses confirm modal without confirming, re-enable button and clear data
        $('#pendingConfirmModal').on('hidden.bs.modal', function(){
            if (pendingAssignData && !pendingAssignData.confirmed) {
                if (pendingAssignData.mode === 'single') {
                    if (pendingAssignData.$btn) pendingAssignData.$btn.prop('disabled', false).html(pendingAssignData.original);
                } else {
                    if (pendingAssignData.$btn) pendingAssignData.$btn.prop('disabled', false).html('<i class="fas fa-user-plus me-1"></i>Assign All (' + pendingAssignData.count + ')');
                }
                pendingAssignData = null;
                // Reset header to primary
                $('#pendingConfirmModal .modal-header').removeClass('bg-danger').addClass('bg-primary');
            }
            if (pendingAssignData && pendingAssignData.confirmed) {
                // Keep data for the ongoing AJAX, will be cleared in success/error
                pendingAssignData.confirmed = false;
            }
        });

        // Global pending popup (class navigation)
        $('#globalPendingBtn').on('click', function(){
            const $modal = $('#globalPendingModal');
            $modal.modal('show');
            $('#globalPendingBody').html('<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary"></div><div class="small mt-2">Loading pending students...</div></div>');
            $.ajax({
                url: './get_all_pending.php',
                method: 'GET',
                dataType: 'json',
                success: function(resp){
                    if (resp.status !== 'success' || !resp.grouped || !Object.keys(resp.grouped).length) {
                        $('#globalPendingBody').html('<div class="p-4 text-center text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><div> No pending students — all caught up!</div></div>');
                        return;
                    }
                    let html = '';
                    for (const [section, students] of Object.entries(resp.grouped)) {
                        html += '<div class="mb-3"><h6 class="small fw-bold"><i class="fas fa-layer-group me-1 text-primary"></i>' + $('<div>').text(section).html() + ' <span class="badge bg-warning text-dark">' + students.length + '</span></h6><div class="list-group list-group-flush border rounded">';
                        students.forEach(s => {
                            var gIrrBadge = (s.is_irregular) ? ' <span class="badge bg-warning text-dark" style="font-size:0.6rem;">Irregular</span>' : '';
                            var gIrrSub = (s.is_irregular && s.irregular_label) ? '<br><span class="text-primary">' + $('<div>').text(s.irregular_label).html() + '</span>' : '';
                            html += '<div class="list-group-item py-2 px-3 d-flex justify-content-between align-items-start"><div style="flex:1; min-width:0;"><div class="small fw-bold">' + $('<div>').text(s.name).html() + gIrrBadge + '</div><small class="text-muted">' + $('<div>').text(s.student_number).html() + (s.course ? ' • ' + $('<div>').text(s.course).html() : '') + gIrrSub + '</small></div><button type="button" class="btn btn-sm btn-outline-secondary dismiss-pending-btn ms-2" data-id="'+(s.id||'')+'" title="Remove from pending"><i class="fas fa-times" style="font-size:0.7rem;"></i></button></div>';
                        });
                        html += '</div></div>';
                    }
                    html += '<div class="alert alert-info small mt-3 mb-0"><i class="fas fa-info-circle me-1"></i>Click any <strong>Section</strong> badge in the table to assign its pending students to that class.</div>';
                    $('#globalPendingBody').html(html);
                    try { var tt2=[].slice.call(document.querySelectorAll('#globalPendingBody [data-bs-toggle="tooltip"]')); tt2.forEach(function(el){ try{ new bootstrap.Tooltip(el); }catch(e){} }); } catch(e){}
                },
                error: function(){ $('#globalPendingBody').html('<div class="text-danger small p-3">Connection error. Please try again.</div>'); }
            });
        });

        // Dismiss pending student via cross button (every item has one; duplicates show "Already exists" tooltip)
        $(document).on('click', '.dismiss-pending-btn', function(e){
            e.stopPropagation();
            var $btn = $(this);
            var pid = $btn.data('id');
            if (!pid) return;
            if ($btn.prop('disabled')) return;
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;"></span>');
            $.ajax({
                url: './dismiss_pending.php',
                method: 'POST',
                data: { id: pid },
                dataType: 'json',
                success: function(resp){
                    if (resp.status === 'success') {
                        var $item = $btn.closest('.pending-student-item, .list-group-item');
                        $item.fadeOut(180, function(){ $(this).remove();
                            // refresh pending-modal counts (harmless no-op for other lists)
                            pendingCountRefresh();
                            refreshPendingBadges();
                            refreshLevelCounts();
                            if ($('#globalPendingBody').is(':visible')) { $item = $btn.closest('.list-group-item'); // global already removed in fadeOut
                            }
                            // global modal counts live on next open via refreshPendingBadges/next fetch, but also fix current view
                            var $gItems = $('#globalPendingBody .list-group-item');
                            if ($gItems.length === 0 && $('#globalPendingBody').find('.list-group').length) { $('#globalPendingBody').find('.list-group').html('<div class="p-3 text-center text-muted small"><i class="fas fa-check-circle me-1 text-success"></i>All caught up in this section.</div>'); }
                            if (window.showAppToast) window.showAppToast('success', 'Removed from pending');
                        });
                    } else {
                        $btn.prop('disabled', false).html('<i class="fas fa-times" style="font-size:0.7rem;"></i>');
                        if (window.showAppToast) window.showAppToast('danger', resp.message || 'Failed to remove');
                    }
                },
                error: function(){
                    $btn.prop('disabled', false).html('<i class="fas fa-times" style="font-size:0.7rem;"></i>');
                    if (window.showAppToast) window.showAppToast('danger', 'Connection error');
                }
            });
        });

        window.updateClassData = function() {
            location.reload();
        };

        // Components Functions
        $('#new_component').click(function(){
            uni_modal('Add New Component',"manage_component.php")
        })
        $('.edit_component').click(function(){
            uni_modal('Edit Component Details',"manage_component.php?id="+$(this).attr('data-id'))
        })
        $('.manage_percentage').click(function(){
            uni_modal('Manage Subject\'s Components Percentage',"manage_percentage.php?id="+$(this).attr('data-id'))
        })
        $('.delete_component').click(function(){
            _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from Component List?",'delete_component',[$(this).attr('data-id')])
        })

        // Subjects function
        $('#new_subject').click(function(){
            uni_modal('Add New subject',"manage_subject.php")
        })
        $('.edit_subject').click(function(){
            uni_modal('Edit subject Details',"manage_subject.php?id="+$(this).attr('data-id'))
        })
        $('.delete_subject').click(function(){
            _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from Subject List?",'delete_subject',[$(this).attr('data-id')])
        })
       
        // Initialize DataTables for maintenance tables only if on maintenance tab
        <?php if ($activeTab === 'maintenance'): ?>
        $('#componentsTable').dataTable({
            columnDefs: [
                { orderable: false, targets:1, className: "text-end" }
            ]
        })
        $('#subjectsTable').dataTable({
            columnDefs: [
                { orderable: false, targets:1, className: "text-end" }
            ]
        })
        <?php endif; ?>

        // Dynamic update functions for modal forms
        window.updateComponentData = function() {
            $.ajax({
                url: './Actions.php?a=get_components',
                method: 'GET',
                dataType: 'JSON',
                success: function(resp) {
                    if(resp.status === 'success') {
                        var table = $('#componentsTable').DataTable();
                        table.clear();
                        
                        if(resp.data.length === 0) {
                            table.row.add(['<div class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No components found</div>', '']).draw();
                        } else {
                            resp.data.forEach(function(comp) {
                                var actions = '<div class="btn-group btn-group-sm" role="group">' +
                                    '<button type="button" class="edit_component btn btn-outline-primary" data-id="' + comp.component_id + '" data-name="' + comp.name + '" title="Edit Component">' +
                                        '<i class="fas fa-edit"></i>' +
                                    '</button>' +
                                    '<button type="button" class="delete_component btn btn-outline-danger" data-id="' + comp.component_id + '" data-name="' + comp.name + '" title="Delete Component">' +
                                        '<i class="fas fa-trash"></i>' +
                                    '</button>' +
                                '</div>';
                                
                                table.row.add([comp.name, actions]);
                            });
                            table.draw();
                            
                            // Re-attach event handlers after draw
                            $('.edit_component').click(function(){
                                uni_modal('Edit Component Details',"manage_component.php?id="+$(this).attr('data-id'))
                            })
                            $('.delete_component').click(function(){
                                _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from Component List?",'delete_component',[$(this).attr('data-id')])
                            })
                        }
                        
                        // Update component count
                        $('.badge:contains("Total Components:")').text('Total Components: ' + resp.data.length);
                    }
                }
            });
        }

        window.updateSubjectData = function() {
            $.ajax({
                url: './Actions.php?a=get_subjects',
                method: 'GET',
                dataType: 'JSON',
                success: function(resp) {
                    if(resp.status === 'success') {
                        var table = $('#subjectsTable').DataTable();
                        table.clear();
                        
                        if(resp.data.length === 0) {
                            table.row.add(['<div class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No subjects found</div>', '']).draw();
                        } else {
                            resp.data.forEach(function(subj) {
                                var actions = '<div class="btn-group btn-group-sm" role="group">' +
                                    '<button type="button" class="manage_percentage btn btn-outline-info" data-id="' + subj.subject_id + '" data-name="' + subj.name + '" title="Manage Components">' +
                                        '<i class="fas fa-percentage"></i> Percentage' +
                                    '</button>' +
                                    '<button type="button" class="edit_subject btn btn-outline-primary" data-id="' + subj.subject_id + '" data-name="' + subj.name + '" title="Edit Subject">' +
                                        '<i class="fas fa-edit"></i>' +
                                    '</button>' +
                                    '<button type="button" class="delete_subject btn btn-outline-danger" data-id="' + subj.subject_id + '" data-name="' + subj.name + '" title="Delete Subject">' +
                                        '<i class="fas fa-trash"></i>' +
                                    '</button>' +
                                '</div>';
                                
                                table.row.add([subj.name, actions]);
                            });
                            table.draw();
                            
                            // Re-attach event handlers after draw
                            $('.manage_percentage').click(function(){
                                uni_modal('Manage Subject\'s Components Percentage',"manage_percentage.php?id="+$(this).attr('data-id'))
                            })
                            $('.edit_subject').click(function(){
                                uni_modal('Edit subject Details',"manage_subject.php?id="+$(this).attr('data-id'))
                            })
                            $('.delete_subject').click(function(){
                                _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from Subject List?",'delete_subject',[$(this).attr('data-id')])
                            })
                        }
                        
                        // Update subject count
                        $('.badge:contains("Total Subjects:")').text('Total Subjects: ' + resp.data.length);
                    }
                }
            });
        }

        // Safety net: never leave the confirm modal's buttons disabled,
        // otherwise the next delete confirmation opens unclickable.
        $('#confirm_modal').off('hidden.bs.modal.reenable').on('hidden.bs.modal.reenable', function(){
            $('#confirm_modal button').attr('disabled', false);
        });
    });

    function delete_data($id){
        $('#confirm_modal button').attr('disabled', true);
        $.ajax({
            url: './Actions.php?a=delete_class',
            method: 'POST',
            data: { id: $id },
            dataType: 'JSON',
            error: function(err){
                console.error(err);
                window.showAppToast('danger', 'An error occurred while deleting.');
                $('#confirm_modal button').attr('disabled', false);
            },
            success: function(resp){
                if(resp.status === 'success'){
                    $('#confirm_modal').modal('hide');
                    window.showAppToast('success', 'Class deleted successfully.');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    window.showAppToast('danger', resp.msg || 'An error occurred.');
                    $('#confirm_modal button').attr('disabled', false);
                }
            }
        });
    }

    function delete_subject($id){
        var $btns = $('#confirm_modal button');
        $btns.attr('disabled', true);
        $.ajax({
            url:'./Actions.php?a=delete_subject',
            method:'POST',
            data:{id:$id},
            dataType:'JSON',
            error:err=>{
                console.log(err)
                if(window.showAppToast){ window.showAppToast('danger', 'An error occurred while deleting the subject.'); }
                else { alert("An error occurred."); }
                $btns.attr('disabled', false);
            },
            success:function(resp){
                if(resp.status == 'success'){
                    // Re-enable buttons BEFORE hiding: otherwise the next
                    // _conf open finds Confirm/Cancel still disabled.
                    $btns.attr('disabled', false);
                    $('#confirm_modal').modal('hide');
                    // Scoped to #subjectsTable only: component_id and subject_id
                    // share the same numeric sequence, so an unscoped
                    // $('button[data-id=...]') selector would also remove the
                    // component row with the same id.
                    try {
                        if($.fn.DataTable.isDataTable('#subjectsTable')){
                            var dt = $('#subjectsTable').DataTable();
                            var $row = $('#subjectsTable button.delete_subject[data-id="'+$id+'"]').closest('tr');
                            if($row.length){ dt.row($row).remove().draw(); }
                            var n = dt.rows().count();
                            $('.badge:contains("Total Subjects:")').text('Total Subjects: ' + n);
                        } else {
                            $('#subjectsTable button.delete_subject[data-id="'+$id+'"]').closest('tr').remove();
                            var currentCount = parseInt($('.badge:contains("Total Subjects:")').text().replace(/[^0-9]/g, ''));
                            $('.badge:contains("Total Subjects:")').text('Total Subjects: ' + (currentCount - 1));
                        }
                    } catch(e){ console.log(e); }
                    if(window.showAppToast){ window.showAppToast('success', (resp && resp.msg) || 'Subject successfully deleted.'); }
                }else{
                    if(window.showAppToast){ window.showAppToast('danger', (resp && (resp.msg || resp.error)) || 'Failed to delete the subject.'); }
                    else { alert("An error occurred."); }
                    $btns.attr('disabled', false);
                }
            }
        })
    }

    function delete_component($id){
        var $btns = $('#confirm_modal button');
        $btns.attr('disabled', true);
        $.ajax({
            url:'./Actions.php?a=delete_component',
            method:'POST',
            data:{id:$id},
            dataType:'JSON',
            error:err=>{
                console.log(err)
                if(window.showAppToast){ window.showAppToast('danger', 'An error occurred while deleting the component.'); }
                else { alert("An error occurred."); }
                $btns.attr('disabled', false);
            },
            success:function(resp){
                if(resp.status == 'success'){
                    $btns.attr('disabled', false);
                    $('#confirm_modal').modal('hide');
                    // Scoped to #componentsTable only (see delete_subject above
                    // for why an unscoped selector is wrong here).
                    try {
                        if($.fn.DataTable.isDataTable('#componentsTable')){
                            var dt = $('#componentsTable').DataTable();
                            var $row = $('#componentsTable button.delete_component[data-id="'+$id+'"]').closest('tr');
                            if($row.length){ dt.row($row).remove().draw(); }
                            var n = dt.rows().count();
                            $('.badge:contains("Total Components:")').text('Total Components: ' + n);
                        } else {
                            $('#componentsTable button.delete_component[data-id="'+$id+'"]').closest('tr').remove();
                            var currentCount = parseInt($('.badge:contains("Total Components:")').text().replace(/[^0-9]/g, ''));
                            $('.badge:contains("Total Components:")').text('Total Components: ' + (currentCount - 1));
                        }
                    } catch(e){ console.log(e); }
                    if(window.showAppToast){ window.showAppToast('success', (resp && resp.msg) || 'Component successfully deleted.'); }
                }else{
                    if(window.showAppToast){ window.showAppToast('danger', (resp && (resp.msg || resp.error)) || 'Failed to delete the component.'); }
                    else { alert("An error occurred."); }
                    $btns.attr('disabled', false);
                }
            }
        })
    }
    
    // Function to refresh components table dynamically
    function refreshComponentsTable() {
        $.ajax({
            url: './Actions.php?a=get_components',
            method: 'GET',
            dataType: 'JSON',
            success: function(resp) {
                if(resp.status == 'success') {
                    var tbody = $('#componentsTable tbody');
                    tbody.empty();
                    
                    if(resp.data.length > 0) {
                        resp.data.forEach(function(component) {
                            var row = `
                                <tr>
                                    <td class="fw-medium">${component.name}</td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="edit_component btn btn-outline-primary" 
                                                    data-id="${component.component_id}" 
                                                    data-name="${component.name}"
                                                    title="Edit Component">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <button type="button" class="delete_component btn btn-outline-danger" 
                                                    data-id="${component.component_id}" 
                                                    data-name="${component.name}"
                                                    title="Delete Component">
                                                <i class="fas fa-trash"></i> 
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                            tbody.append(row);
                        });
                        
                        // Update component count
                        $('.badge:contains("Total Components:")').text('Total Components: ' + resp.data.length);
                        
                        // Re-attach event listeners
                        attachComponentEventListeners();
                    } else {
                        tbody.html(`
                            <tr>
                                <td colspan="2" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    No components found
                                </td>
                            </tr>
                        `);
                        $('.badge:contains("Total Components:")').text('Total Components: 0');
                    }
                    
                    // Reinitialize DataTables
                    $('#componentsTable').DataTable().destroy();
                    $('#componentsTable').DataTable({
                        columnDefs: [
                            { orderable: false, targets:1 }
                        ]
                    });
                }
            }
        });
    }
    
    // Function to refresh subjects table dynamically
    function refreshSubjectsTable() {
        $.ajax({
            url: './Actions.php?a=get_subjects',
            method: 'GET',
            dataType: 'JSON',
            success: function(resp) {
                if(resp.status == 'success') {
                    var tbody = $('#subjectsTable tbody');
                    tbody.empty();
                    
                    if(resp.data.length > 0) {
                        resp.data.forEach(function(subject) {
                            var row = `
                                <tr>
                                    <td class="fw-medium">${subject.name}</td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="manage_percentage btn btn-outline-info" 
                                                    data-id="${subject.subject_id}" 
                                                    data-name="${subject.name}"
                                                    title="Manage Components">
                                                <i class="fas fa-percentage"></i> Percentage
                                            
                                            <button type="button" class="edit_subject btn btn-outline-primary" 
                                                    data-id="${subject.subject_id}" 
                                                    data-name="${subject.name}"
                                                    title="Edit Subject">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <button type="button" class="delete_subject btn btn-outline-danger" 
                                                    data-id="${subject.subject_id}" 
                                                    data-name="${subject.name}"
                                                    title="Delete Subject">
                                                <i class="fas fa-trash"></i> 
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                            tbody.append(row);
                        });
                        
                        // Update subject count
                        $('.badge:contains("Total Subjects:")').text('Total Subjects: ' + resp.data.length);
                        
                        // Re-attach event listeners
                        attachSubjectEventListeners();
                    } else {
                        tbody.html(`
                            <tr>
                                <td colspan="2" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    No subjects found
                                </td>
                            </tr>
                        `);
                        $('.badge:contains("Total Subjects:")').text('Total Subjects: 0');
                    }
                    
                    // Reinitialize DataTables
                    $('#subjectsTable').DataTable().destroy();
                    $('#subjectsTable').DataTable({
                        columnDefs: [
                            { orderable: false, targets:1 }
                        ]
                    });
                }
            }
        });
    }
    
    // Function to re-attach component event listeners
    function attachComponentEventListeners() {
        $('.edit_component').off('click').on('click', function(){
            uni_modal('Edit Component Details',"manage_component.php?id="+$(this).attr('data-id'))
        })
        $('.delete_component').off('click').on('click', function(){
            _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from Component List?",'delete_component',[$(this).attr('data-id')])
        })
    }
    
    // Function to re-attach subject event listeners
    function attachSubjectEventListeners() {
        $('.manage_percentage').off('click').on('click', function(){
            uni_modal('Manage Subject\'s Components Percentage',"manage_percentage.php?id="+$(this).attr('data-id'))
        })
        $('.edit_subject').off('click').on('click', function(){
            uni_modal('Edit subject Details',"manage_subject.php?id="+$(this).attr('data-id'))
        })
        $('.delete_subject').off('click').on('click', function(){
            _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from Subject List?",'delete_subject',[$(this).attr('data-id')])
        })
    }
</script>