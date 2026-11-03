<?php
// Initialize variables
$is_attendance = false;
if (isset($_GET['assessment_id'])) {
    $assessment = $conn->querySingle("SELECT * FROM assessment_list WHERE assessment_id = '{$_GET['assessment_id']}'", true);
    $is_attendance = ($assessment && stripos($assessment['name'], 'attendance') !== false);
}
?>
<style>
    /* Tab Navigation Styles */
    .nav-tabs .nav-link {
        border: none;
        border-bottom: 3px solid transparent;
        background: transparent;
        color: #6c757d;
        font-weight: 600;
        padding: 1rem 1.5rem;
        transition: all 0.3s ease;
    }
    
    .nav-tabs .nav-link:hover {
        border-bottom-color: #0d6efd;
        color: #0d6efd;
    }
    
    .nav-tabs .nav-link.active {
        border-bottom-color: #0d6efd;
        color: #0d6efd;
        background: transparent;
        font-weight: 700;
    }
    
    .nav-tabs {
        border-bottom: 2px solid #e9ecef;
    }
    
    .tab-content {
        margin-top: 2rem;
    }
    
    /* Button styles from marks.php */
    button {
        position: relative;
        border: none;
        background: transparent;
        padding: 2px;
        cursor: pointer;
        outline-offset: 4px;
        transition: filter 250ms;
        user-select: none;
        touch-action: manipulation;
    }

    .shadow {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border-radius: 12px;
        background: hsl(0deg 0% 0% / 0.25);
        will-change: transform;
        transform: translateY(2px);
        transition: transform
            600ms
            cubic-bezier(.3, .7, .4, 1);
    }

    .edge {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border-radius: 12px;
        background: linear-gradient(
            to left,
            hsl(340deg 100% 16%) 0%,
            hsl(340deg 100% 32%) 8%,
            hsl(340deg 100% 32%) 92%,
            hsl(340deg 100% 16%) 100%
        );
    }

    .front {
        display: block;
        position: relative;
        padding: 8px 20px;
        border-radius: 12px;
        font-size: 1rem;
        color: white;
        background: hsl(345deg 100% 47%);
        will-change: transform;
        transform: translateY(-4px);
        transition: transform
            600ms
            cubic-bezier(.3, .7, .4, 1);
    }

    button:hover {
        filter: brightness(110%);
    }

    button:hover .front {
        transform: translateY(-6px);
        transition: transform
            250ms
            cubic-bezier(.3, .7, .4, 1.5);
    }

    button:active .front {
        transform: translateY(-2px);
        transition: transform 34ms;
    }

    button:hover .shadow {
        transform: translateY(4px);
        transition: transform
            250ms
            cubic-bezier(.3, .7, .4, 1.5);
    }

    button:active .shadow {
        transform: translateY(1px);
        transition: transform 34ms;
    }

    button:focus:not(:focus-visible) {
        outline: none;
    }

    /* Toggle button for Enter behavior */
    .toggle-enter-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border: none;
        background: transparent;
        cursor: pointer;
        font-weight: bold;
    }

    .toggle-enter-label {
        font-size: 0.8rem;
        text-transform: uppercase;
    }

    .toggle-enter-switch {
        position: relative;
        display: inline-flex;
        align-items: center;
        padding: 2px 8px 2px 2px;
        border-radius: 999px;
        background: #dee2e6;
        transition: background 0.2s ease-in-out;
    }

    .toggle-enter-knob {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #ffffff;
        box-shadow: 0 0 2px rgba(0,0,0,0.3);
        transform: translateX(0);
        transition: transform 0.2s ease-in-out;
    }

    .toggle-enter-text {
        font-size: 0.75rem;
        margin-left: 6px;
        font-weight: 600;
    }

    .toggle-enter-btn.on .toggle-enter-switch {
        background: #0d55fdff;
        color: #ffffff;
    }

    .toggle-enter-btn.on .toggle-enter-knob {
        transform: translateX(16px);
    }

    /* Compact table styles from assessments.php */
    .compact-table th, .compact-table td {
        padding: 0.3rem 0.5rem !important;
        font-size: 0.85rem;
    }
    .compact-table th {
        font-weight: 500;
    }
    .card-header {
        padding: 0.5rem 1rem;
    }
    .card-title {
        font-size: 1.1rem;
        margin: 0;
    }
    .btn-sm {
        padding: 0.15rem 0.4rem;
        font-size: 0.8rem;
    }
    .form-control, .form-select {
        padding: 0.25rem 0.5rem;
        font-size: 0.85rem;
        height: calc(1.5em + 0.5rem + 2px);
    }
    .form-group {
        margin-bottom: 0.5rem;
    }
    
    /* Component cell styling for proper centering and overflow */
    .component-cell {
        text-align: center !important;
        vertical-align: middle !important;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    /* Highlight animation for new rows */
    .highlight-new {
        animation: highlightNewRow 2s ease-in-out;
        background-color: #d4edda !important;
        transition: background-color 2s ease-in-out;
    }
    
    @keyframes highlightNewRow {
        0% {
            background-color: #28a745 !important;
            color: white !important;
        }
        50% {
            background-color: #d4edda !important;
            color: inherit !important;
        }
        100% {
            background-color: transparent !important;
            color: inherit !important;
        }
    }
    
    /* Highlight animation for updated rows */
    .highlight-updated {
        animation: highlightUpdatedRow 1.5s ease-in-out;
        background-color: #fff3cd !important;
        transition: background-color 1.5s ease-in-out;
    }
    
    @keyframes highlightUpdatedRow {
        0% {
            background-color: #ffc107 !important;
            color: black !important;
        }
        100% {
            background-color: transparent !important;
            color: inherit !important;
        }
    }
    
    /* Highlight animation for removing rows */
    .highlight-removing {
        animation: highlightRemovingRow 0.3s ease-in-out;
        background-color: #f8d7da !important;
        transition: background-color 0.3s ease-in-out;
    }
    
    @keyframes highlightRemovingRow {
        0% {
            background-color: transparent !important;
        }
        100% {
            background-color: #dc3545 !important;
            color: white !important;
        }
    }
    
    /* Additional styles for marks section */
    .mark-input:focus {
        box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
        border-color: #86b7fe;
    }
    .table-hover > tbody > tr:hover {
        background-color: rgba(13, 110, 253, 0.03);
    }
    .avatar-sm {
        background-color: #f8f9fa;
    }
    .badge {
        padding: 0.4em 0.8em;
        font-weight: 500;
    }
    
    /* Bulk Entry Styles */
    #bulkEntryText {
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
    }
    
    .bulk-entry-highlight {
        background-color: #fff3cd !important;
        border-color: #ffc107 !important;
        animation: highlightPulse 2s ease-in-out;
    }
    
    @keyframes highlightPulse {
        0% { background-color: #ffc107; }
        50% { background-color: #fff3cd; }
        100% { background-color: transparent; }
    }
    
    .bulk-entry-card {
        transition: all 0.3s ease;
    }
    
    .bulk-entry-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    /* Tooltip styles */
    .tooltip-inner {
        background-color: #dc3545;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
    }
    .bs-tooltip-bottom .tooltip-arrow::before {
        border-bottom-color: #dc3545;
    }
    
    .disabled-link {
        cursor: not-allowed !important;
        text-decoration: none !important;
    }
</style>

<!-- Tab Navigation -->
<ul class="nav nav-tabs" id="marksAssessmentsTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="marks-tab" data-bs-toggle="tab" data-bs-target="#marks" type="button" role="tab" aria-controls="marks" aria-selected="true">
            <i class="fas fa-star-half-alt me-2"></i>Marks Entry
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="assessments-tab" data-bs-toggle="tab" data-bs-target="#assessments" type="button" role="tab" aria-controls="assessments" aria-selected="false">
            <i class="fas fa-tasks me-2"></i>Assessment Management
        </button>
    </li>
</ul>

<!-- Tab Content -->
<div class="tab-content" id="marksAssessmentsTabContent">
    <!-- Marks Tab -->
    <div class="tab-pane fade show active" id="marks" role="tabpanel" aria-labelledby="marks-tab">
        <form action="" id="marks-form">
            <input type="hidden" name="a" value="save_mark">
            <input type="hidden" name="assessment_id" id="form_assessment_id" value="<?php echo isset($_GET['assessment_id']) ? $_GET['assessment_id'] : '' ?>">
            <div class="col-12">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body p-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-0">
                                        <i class="fas fa-chalkboard text-muted"></i>
                                    </span>
                                    <select name="class_id" id="class_id" class="form-select form-select-sm border-start-0 ps-1" style="font-weight:bold;" required>
                                        <option value="" <?php echo (!isset($class_id)) ? 'selected' : '' ?> disabled>Select Class</option>
                                        <?php
                                        $class_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class FROM `class_list` c inner join `subjects` s on c.subject_id = s.subject_id order by `class` asc");
                                        while($row = $class_qry->fetchArray()):
                                            $class[$row['class_id']] = $row['class'];
                                        ?>
                                            <option value="<?php echo $row['class_id'] ?>" style="font-weight:bold;"
                                                <?php echo isset($_GET['class_id']) && $_GET['class_id'] == $row['class_id'] ? "selected" : '' ?>>
                                                <?php echo htmlspecialchars($row['class']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-5">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-0">
                                        <i class="fas fa-tasks text-muted"></i>
                                    </span>
                                    <select name="assessment_id" id="assessment_id" class="form-select form-select-sm border-start-0 ps-1" style="font-weight:bold;" required>
                                        <option value="" <?php echo (!isset($assessment_id)) ? 'selected' : '' ?> disabled>Select Assessment</option>
                                        <?php
                                        $dept_qry = $conn->query("SELECT a.*, gc.name as component_name FROM assessment_list a INNER JOIN grading_components gc ON a.component_id = gc.component_id order by a.quarter asc, a.name asc");
                                        $quarter = array('','Prelim','Midterm','Pre-Final','Final');
                                        while($row = $dept_qry->fetchArray()):
                                            $assess_quarter[$row['assessment_id']] = $quarter[$row['quarter']];
                                            $assess[$row['assessment_id']] = $row;
                                        ?>
                                            <option value="<?php echo $row['assessment_id'] ?>" style="font-weight:bold;"
                                                data-class="<?php echo $row['class_id'] ?>" 
                                                class="item" 
                                                <?php echo isset($_GET['assessment_id']) && $_GET['assessment_id'] == $row['assessment_id'] ? "selected" : '' ?>>
                                                <?php echo htmlspecialchars($quarter[$row['quarter']].' - ' .$row['name'].' - '.$row['component_name']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <button type="button" id="filter">
                                    <span class="shadow"></span>
                                    <span class="edge"></span>
                                    <span class="front text" style="font-weight:bold;">Load Students</span>
                                </button>
                            </div>
                            <div class="d-flex justify-content-center gap-2 ">
                                <button type="button" id="clearMarks" style="font-weight:bold;" class="btn btn-outline-danger px-3 d-flex align-items-center">
                                    <i class="fas fa-undo-alt me-2"></i> Reset Marks to 0
                                </button>
                                <button type="button" id="saveMarksBtn" style="font-weight:bold;" class="btn btn-outline-success px-4 d-flex align-items-center position-relative" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Save all marks first before navigating" data-bs-trigger="manual">
                                    <i class="fas fa-save me-2"></i> Save All Marks
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <hr>
                <?php if(isset($_GET['class_id']) && $_GET['assessment_id']) : ?>
                <div class="row g-4">
                    <!-- Left Side - Class Information -->
                    <div class="col-lg-4">
                        <div class="sticky-top" style="top: 20px;">
                            <!-- Class Info Card -->
                            <div class="card border-0 shadow-sm mb-1" style="background-color:rgb(217, 221, 219);">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3">
                                            <i class="fas fa-chalkboard-teacher text-primary"></i>
                                        </div>
                                        <h5 class="mb-0">Class Information</h5>
                                    </div>
                                    <div class="mb-1 p-1 bg-light bg-opacity-25 rounded-3">
                                        <div class="text-muted small mb-1">Class</div>
                                        <div class="fw-medium" style="font-weight:bold;"><?php echo isset($class[$_GET['class_id']]) ? htmlspecialchars($class[$_GET['class_id']]) : "N/A" ?></div>
                                    </div>
                                    <div class="mb-1 p-1 bg-light bg-opacity-25 rounded-3">
                                        <div class="text-muted small mb-1">Quarter</div>
                                        <div class="fw-medium" style="font-weight:bold;"><?php echo isset($assess_quarter[$_GET['assessment_id']]) ? htmlspecialchars($assess_quarter[$_GET['assessment_id']]) : "N/A" ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Assessment Details Card -->
                            <div class="card border-0 shadow-sm" style="background-color:rgb(217, 221, 219);">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3">
                                            <i class="fas fa-clipboard-check text-primary"></i>
                                        </div>
                                        <h5 class="mb-0">Assessment Details</h5>
                                    </div>
                                    <div class="mb-1 p-1 bg-light bg-opacity-25 rounded-3">
                                        <div class="text-muted small mb-1">Assessment</div>
                                        <div class="fw-medium" style="font-weight:bold;"><?php echo isset($assess[$_GET['assessment_id']]) ? htmlspecialchars($assess[$_GET['assessment_id']]['name']) : "N/A" ?></div>
                                    </div>
                                    <div class="p-1 bg-light bg-opacity-25 rounded-3">
                                        <div class="text-muted small mb-1">Highest Possible Score</div>
                                        <div class="fw-bold text-primary fs-4" style="font-weight:bold;" id="hps"><?php echo isset($assess[$_GET['assessment_id']]) ? $assess[$_GET['assessment_id']]['hps'] : 0 ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                         <!-- Attendance Color Legend -->
                            <div class="alert alert-info mb-3 mt-3" role="alert">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Attendance Color Legend (Today's Status)</strong>
                                </div>
                                <div class="row g-2">
                                    <div class="col-auto">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded me-2" style="width: 20px; height: 20px; background-color: #ccffcc; border: 1px solid #ccc;"></div>
                                            <span class="small">Present</span>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded me-2" style="width: 20px; height: 20px; background-color: #ffcccc; border: 1px solid #ccc;"></div>
                                            <span class="small">Absent</span>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded me-2" style="width: 20px; height: 20px; background-color: #ffddaa; border: 1px solid #ccc;"></div>
                                            <span class="small">Late</span>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded me-2" style="width: 20px; height: 20px; background-color: #ccffff; border: 1px solid #ccc;"></div>
                                            <span class="small">Excused</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    </div>
                    <!-- Right Side - Student Marks Table -->
                    <div class="col-lg-8">
                        <div class="card shadow-sm border-0">
                            <div class="d-flex justify-content-between align-items-center px-3 pt-3">
                                <button type="button" id="toggleEnterBehavior" class="toggle-enter-btn btn-sm" aria-pressed="false">
                                    <span class="toggle-enter-label">Fast Single Entry</span>
                                    <span class="toggle-enter-switch">
                                        <span class="toggle-enter-knob"></span>
                                        <span class="toggle-enter-text"></span>
                                    </span>
                                </button>                            </div>
                            
                            <!-- Bulk Entry Section -->
                            <div class="mb-3 px-3">
                                <div class="card border-0 shadow-sm bulk-entry-card">
                                    <div class="card-header bg-light py-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-import me-2 text-primary"></i>
                                            <h6 class="mb-0 fw-bold">Bulk Entry - Student Numbers & Marks</h6>
                                            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="collapse" data-bs-target="#bulkEntryCollapse">
                                                <i class="fas fa-chevron-down"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="collapse hide" id="bulkEntryCollapse">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-bold">Student Numbers:</label>
                                                        <textarea id="bulkStudentNumbers" class="form-control form-control-sm" rows="6" 
                                                            placeholder="Enter student numbers (one per line)&#10;Example:&#10;23-0001&#10;23-0002&#10;23-0003"></textarea>
                                                        <small class="text-muted">Enter one student number per line</small>
                                                   
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <button type="button" id="parseBulkEntry" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-magic me-1"></i> Fill Marks
                                                        </button>
                                                        <button type="button" id="clearBulkEntry" class="btn btn-sm btn-outline-secondary">
                                                            <i class="fas fa-eraser me-1"></i> Clear All
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                     <div class="mb-2">
                                                   
                                                        <label class="form-label small fw-bold">Corresponding Marks:</label>
                                                        <textarea id="bulkMarks" class="form-control form-control-sm" rows="6" 
                                                            placeholder="Enter marks (one per line, corresponding to student numbers)&#10;Example:&#10;85&#10;92&#10;78"></textarea>
                                                        <small class="text-muted">Enter one mark per line (must match same count as student numbers)</small>
                                                    </div>
                                                </div>    
                                                <div class="col-md-4">
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-bold">Bulk Set Score:</label>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text">From:</span>
                                                            <input type="number" id="bulkSetMarksFrom" class="form-control form-control-sm" placeholder="From value" min="0" step="0.01" value="0">
                                                            <span class="input-group-text">To:</span>
                                                            <input type="number" id="bulkSetMarksTo" class="form-control form-control-sm" placeholder="To value" min="0" step="0.01">
                                                        </div>
                                                    </div>
                                                    <div class="mb-2">
                                                        <button type="button" id="applyBulkRange" class="btn btn-sm btn-outline-primary w-100">
                                                            <i class="fas fa-sync me-1"></i> Apply Range
                                                        </button>
                                                    </div>
                                                    <div id="bulkEntryStatus" class="small text-muted mt-2"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            
                           
                            
                            <div class="table-responsive p-3" style="max-height: 60vh; overflow-y: auto;">
                                <table id="marks-table" class="table table-hover align-middle mb-0" style="min-width: 600px;">
                                    <thead>
                                        <tr class="bg-gradient bg-light">
                                            <th class="px-4 py-3 border-0 rounded-start" style="border-top-left-radius: 12px !important;">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-user-graduate me-2 text-primary"></i>
                                                    <span class="text-uppercase text-dark small fw-bold">Student Information</span>
                                                </div>
                                            </th>
                                            <th class="px-4 py-3 border-0 text-end" style="width: 150px;">
                                                <div class="d-flex align-items-center justify-content-end">
                                                    <i class="fas fa-star-half-alt me-2 text-primary"></i>
                                                    <span class="text-uppercase text-dark small fw-bold">Score</span>
                                                </div>
                                            </th>
                                            <th class="px-4 py-3 border-0 text-center rounded-end" style="width: 100px; border-top-right-radius: 12px !important;">
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-percentage me-2 text-primary"></i>
                                                    <span class="text-uppercase text-dark small fw-bold">%</span>
                                                </div>
                                            </th>
                                        </tr>
                                    
                                    </thead>
                                    <tbody class="border-1">
                                        <?php 
                                            $marks_qry = $conn->query("SELECT * FROM `mark_list` where assessment_id='{$_GET['assessment_id']}'");
                                            $marks = array();
                                            while($row = $marks_qry->fetchArray()){
                                                $marks[$row['student_id']] = $row['mark'];
                                            }
                                            $hps = isset($assess[$_GET['assessment_id']]) ? $assess[$_GET['assessment_id']]['hps'] : 0;
                                            
                                            // Get current date and quarter for attendance checking
                                            $current_date = date('Y-m-d');
                                            $current_quarter = isset($assess[$_GET['assessment_id']]) ? $assess[$_GET['assessment_id']]['quarter'] : 1;
                                            
                                            // Get attendance data for all students in this class for current date
                                            $attendance_data = array();
                                            $attendance_qry = $conn->query("SELECT student_id, status FROM attendance WHERE class_id = '{$_GET['class_id']}' AND date = '$current_date' AND quarter = '$current_quarter'");
                                            while($att_row = $attendance_qry->fetchArray()){
                                                $attendance_data[$att_row['student_id']] = $att_row['status'];
                                            }
                                            
                                            $student = $conn->query("SELECT * FROM `student_list` where class_id = '{$_GET['class_id']}' order by `name` asc");
                                            $has_students = false;
                                            while($row = $student->fetchArray()):
                                                $has_students = true;
                                                $mark = isset($marks[$row['student_id']]) ? $marks[$row['student_id']] : 0;
                                                // Ensure both values are treated as numbers
                                                $mark = is_numeric($mark) ? (float)$mark : 0;
                                                $hps = is_numeric($hps) ? (float)$hps : 0;
                                                $percentage = $hps > 0 ? number_format(($mark / $hps) * 100, 1) : 0;
                                                $is_dropped = isset($row['status']) && $row['status'] === 'Dropped';
                                                
                                                // Get attendance status and determine row color
                                                $attendance_status = isset($attendance_data[$row['student_id']]) ? $attendance_data[$row['student_id']] : null;
                                                $row_bg_color = '';
                                                if ($attendance_status === 'absent') {
                                                    $row_bg_color = 'background-color: #ffcccc;'; // Red
                                                } elseif ($attendance_status === 'present') {
                                                    $row_bg_color = 'background-color: #ccffcc;'; // Green  
                                                } elseif ($attendance_status === 'late') {
                                                    $row_bg_color = 'background-color: #ffddaa;'; // Orange
                                                } elseif ($attendance_status === 'excused') {
                                                    $row_bg_color = 'background-color: #ccffff;'; // Cyan
                                                }
                                        ?>
                                        <tr class="position-relative<?php echo $is_dropped ? ' dropped-student-row' : ''; ?>"<?php echo $is_dropped ? ' data-bs-toggle="tooltip" data-bs-placement="bottom" title="This student is dropped. Marks cannot be edited."' : ''; ?> style="<?php echo $row_bg_color; ?>">
                                            <td class="px-3 py-2 border-0" >
                                                <div class="d-flex align-items-center" >
                                                    <div class="avatar-sm bg-light rounded-circle me-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                                        <i class="fas fa-user text-muted"></i>
                                                    </div>
                                                    <div class="ms-1" >
                                                        <input type="hidden" name="student_id[]" value="<?php echo $row['student_id'] ?>">
                                                        <h6 class="mb-0 fw-bold" ><?php echo htmlspecialchars($row['name']) ?></h6>
                                                        <small class="text-muted">ID: <?php echo $row['student_number'] ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 border-0">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" 
                                                           step="0.01" 
                                                           class="form-control form-control-sm border-end-0 text-end mark-input" 
                                                           style="font-weight:bold;<?php echo $is_dropped ? ' background-color: #f8d7da; cursor: not-allowed;' : ''; ?>"
                                                           name="mark[]" 
                                                           value="<?php echo $mark == 0 ? '0' : $mark ?>" 
                                                           onfocus="if(this.value === '0') this.value = ''"
                                                           onblur="if(this.value === '') { this.value = '0'; } updatePercentage(this);" 
                                                           min="0" 
                                                           max="<?php echo $hps ?>"
                                                           data-hps="<?php echo $hps ?>" <?php echo $is_dropped ? 'readonly' : 'required'; ?>>
                                                    <span class="input-group-text bg-white">/ <?php echo $hps ?></span>
                                                </div>
                                            </td>
                                            
                                            <td class="px-3 py-2 border-0 text-end">
                                                <span class="badge bg-soft-<?php 
                                                    echo $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger'); 
                                                ?> text-<?php 
                                                    echo $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger'); 
                                                ?> rounded-pill">
                                                    <?php echo $percentage ?>%
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if(!$has_students): ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4">
                                                <div class="d-flex flex-column align-items-center text-muted">
                                                    <i class="fas fa-user-graduate fa-2x mb-2"></i>
                                                    <div>No students found in this class</div>
                                                    <small class="mt-1">Add students to get started</small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Assessments Tab -->
    <div class="tab-pane fade" id="assessments" role="tabpanel" aria-labelledby="assessments-tab">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <h5 class="card-title m-0">Assessment List</h5>
                <button type="button" id="create_new">
                    <span class="shadow"></span>
                    <span class="edge"></span>
                    <span class="front text" style="font-weight: bold;"> Add Assessments
                    </span>
                </button>
            </div>
            <div class="card-body p-2">
                <div class="row mb-2">
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="sectionFilter" class="form-label small mb-1">Filter by Section:</label>
                            <select id="sectionFilter" class="form-select form-select-sm">
                                <option value="">All Sections</option>
                                <?php 
                                $sections = $conn->query("SELECT DISTINCT (grade || ' - ' || section) as section FROM `class_list` ORDER BY grade, section");
                                while($row = $sections->fetchArray()):
                                ?>
                                <option value="<?php echo $row['section'] ?>"><?php echo $row['section'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <table class="table table-sm table-hover table-striped table-bordered compact-table w-100" id="assessmentsTable">
                    <colgroup>
                        <col width="3%">
                        <col width="25%">
                        <col width="20%">
                        <col width="8%">
                        <col width="5%">
                        <col width="4%">
                        <col width="5%">
                        <col width="15%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="text-center">#</th>
                            <th class="text-center">Class</th>
                            <th class="text-center">Component</th>
                            <th class="text-center">Quarter</th>
                            <th class="text-center">Name</th>
                            <th class="text-center">%</th>
                            <th class="text-center">HPS</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        try {
                            // Prepare the SQL query with proper joins and ordering
                            $sql = "SELECT 
                                        a.*, 
                                        (s.name || ' ' || c.grade || ' - ' || c.section) as class, 
                                        cc.name as component, 
                                        a.percentage as percentage 
                                    FROM `assessment_list` a 
                                    INNER JOIN `class_list` c ON a.class_id = c.class_id 
                                    INNER JOIN `subjects` s ON c.subject_id = s.subject_id 
                                    INNER JOIN `grading_components` cc ON a.component_id = cc.component_id 
                                    ORDER BY class ASC, component ASC, quarter ASC, a.name ASC, hps ASC";
                        
                            $qry = $conn->query($sql);
                            
                            if (!$qry) {
                                throw new Exception("Failed to fetch assessments: " . $conn->lastErrorMsg());
                            }
                            
                            $i = 1;
                            $quarters = [
                                '1' => 'Prelim', 
                                '2' => 'Midterm', 
                                '3' => 'Pre-Final', 
                                '4' => 'Final'
                            ];
                            
                            while($row = $qry->fetchArray(SQLITE3_ASSOC)): 
                                // Ensure all required fields exist
                                if (!isset($row['class'], $row['component'], $row['quarter'], $row['name'], $row['percentage'], $row['hps'], $row['assessment_id'])) {
                                    continue; // Skip invalid entries
                                }
                                
                                $quarterName = $quarters[$row['quarter']] ?? 'N/A';
                                $classTitle = htmlspecialchars($row['class']);
                                $componentTitle = htmlspecialchars($row['component']);
                                $assessmentName = htmlspecialchars($row['name']);
                                $deleteTitle = sprintf("%s - %s: %s", $classTitle, $componentTitle, $assessmentName);
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $i++; ?></td>
                            <td class="text-center" title="<?php echo $classTitle; ?>">
                                <?php echo $classTitle; ?>
                            </td>
                            <td class="text-center component-cell" title="<?php echo $componentTitle; ?>">
                                <?php echo $componentTitle; ?>
                            </td>
                            <td class="text-center"><?php echo $quarterName; ?></td>
                            <td class="text-center" title="<?php echo $assessmentName; ?>">
                                <?php echo $assessmentName; ?>
                            </td>
                            <td class="text-center"><?php echo htmlspecialchars($row['percentage']); ?>%</td>
                            <td class="text-center"><?php echo htmlspecialchars($row['hps']); ?></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary edit_data" style="margin-right:.5rem; border-radius:5px;"
                                        data-id='<?php echo (int)$row['assessment_id']; ?>' 
                                        title="Edit">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete_data" style="border-radius:5px;" 
                                        data-id='<?php echo (int)$row['assessment_id']; ?>' 
                                        data-name='<?php echo htmlspecialchars($deleteTitle); ?>' 
                                        title="Delete">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php 
                        } catch (Exception $e) {
                            echo '<tr><td colspan="8" class="text-center text-danger">Error: '.htmlspecialchars($e->getMessage()).'</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // Update percentage function for marks
    function updatePercentage(input) {
        const row = input.closest('tr');
        const value = parseFloat(input.value) || 0;
        const hps = parseFloat(input.dataset.hps) || 1;
        const percentage = Math.min(100, Math.round((value / hps) * 100 * 10) / 10);
        const badge = row.querySelector('.badge');
        
        // Update badge
        badge.textContent = percentage + '%';
        
        // Update badge color based on percentage
        if (percentage >= 75) {
            badge.className = 'badge bg-soft-success text-success rounded-pill';
        } else if (percentage >= 50) {
            badge.className = 'badge bg-soft-warning text-warning rounded-pill';
        } else {
            badge.className = 'badge bg-soft-danger text-danger rounded-pill';
        }
    }
    
    // Initialize percentage display on page load
    document.querySelectorAll('.mark-input').forEach(input => {
        if (input.value) updatePercentage(input);
    });

    // Handle bulk entry functionality
    $(document).ready(function() {
        // Parse bulk entry text and populate marks
        $('#parseBulkEntry').click(function() {
            const studentNumbers = $('#bulkStudentNumbers').val().trim();
            const marks = $('#bulkMarks').val().trim();
            
            if (!studentNumbers || !marks) {
                showBulkStatus('Please enter both student numbers and marks', 'warning');
                return;
            }
            
            const studentLines = studentNumbers.split('\n').filter(line => line.trim());
            const markLines = marks.split('\n').filter(line => line.trim());
            
            if (studentLines.length !== markLines.length) {
                showBulkStatus(`Count mismatch: ${studentLines.length} student numbers vs ${markLines.length} marks`, 'warning');
                return;
            }
            
            let successCount = 0;
            let errorCount = 0;
            const errors = [];
            
            // Create a mapping of student numbers to input fields
            const studentMap = {};
            $('input[name="mark[]"]').each(function() {
                const row = $(this).closest('tr');
                const studentNumber = row.find('small.text-muted').text().replace('ID: ', '');
                studentMap[studentNumber] = $(this);
            });
            
            studentLines.forEach((studentLine, index) => {
                const studentNumber = studentLine.trim();
                const mark = parseFloat(markLines[index].trim());
                
                if (isNaN(mark)) {
                    errors.push(`Line ${index + 1}: Invalid mark value "${markLines[index]}" for student ${studentNumber}`);
                    errorCount++;
                    return;
                }
                
                // Check if student number exists in the current class
                if (studentMap[studentNumber]) {
                    const $input = studentMap[studentNumber];
                    const $row = $input.closest('tr');
                    const maxMark = parseFloat($input.data('hps') || 100);
                    const safeValue = Math.min(Math.max(mark, 0), maxMark);
                    
                    // Add visual feedback
                    $row.addClass('bulk-entry-highlight');
                    setTimeout(() => {
                        $row.removeClass('bulk-entry-highlight');
                    }, 2000);
                    
                    $input.val(safeValue).trigger('input');
                    updatePercentage($input[0]);
                    successCount++;
                } else {
                    // Try to find a matching student number (fuzzy matching)
                    let foundMatch = false;
                    const availableStudents = Object.keys(studentMap);
                    
                    // Try exact match first
                    for (let availableStudent of availableStudents) {
                        if (availableStudent === studentNumber) {
                            foundMatch = true;
                            break;
                        }
                    }
                    
                    // If no exact match, try to find similar student numbers
                    if (!foundMatch) {
                        for (let availableStudent of availableStudents) {
                            // Check if student number contains the input or vice versa
                            if (availableStudent.includes(studentNumber) || studentNumber.includes(availableStudent)) {
                                const $input = studentMap[availableStudent];
                                const $row = $input.closest('tr');
                                const maxMark = parseFloat($input.data('hps') || 100);
                                const safeValue = Math.min(Math.max(mark, 0), maxMark);
                                
                                // Add visual feedback
                                $row.addClass('bulk-entry-highlight');
                                setTimeout(() => {
                                    $row.removeClass('bulk-entry-highlight');
                                }, 2000);
                                
                                $input.val(safeValue).trigger('input');
                                updatePercentage($input[0]);
                                successCount++;
                                foundMatch = true;
                                
                                errors.push(`Line ${index + 1}: Student ${studentNumber} not found, but matched with ${availableStudent}`);
                                break;
                            }
                        }
                    }
                    
                    if (!foundMatch) {
                        errors.push(`Line ${index + 1}: Student ${studentNumber} not found in current class`);
                        errorCount++;
                    }
                }
            });
            
            // Show status message
            if (successCount > 0) {
                showBulkStatus(`Successfully updated ${successCount} student(s)`, 'success');
                hasUnsavedChanges = true;
                
                // Clear text areas immediately after successful fill
                $('#bulkStudentNumbers').val('');
                $('#bulkMarks').val('');
                
            }
            
            if (errorCount > 0) {
                const notFoundStudents = errors.map(err => err.split(': ')[1]).filter(student => student);
                const studentList = notFoundStudents.join(', ');
                showBulkStatus(`Updated ${successCount} student(s). Not found: ${studentList}`, 'warning');
                console.log('Bulk Entry Errors:', errors);
            }
        });
        
        // Clear bulk entry
        $('#clearBulkEntry').click(function() {
            $('#bulkStudentNumbers').val('');
            $('#bulkMarks').val('');
            showBulkStatus('Bulk entry cleared', 'info');
        });
        
        // Apply bulk range
        $('#applyBulkRange').click(function() {
            const fromValue = $('#bulkSetMarksFrom').val();
            const toValue = $('#bulkSetMarksTo').val();
            
            if (!fromValue || !toValue) {
                showBulkStatus('Please enter both from and to values', 'warning');
                return;
            }
            
            setMarksFromTo(fromValue, toValue);
            showBulkStatus(`Applied range: ${fromValue} → ${toValue}`, 'success');
        });
        
        // Show bulk entry status
        function showBulkStatus(message, type) {
            const $status = $('#bulkEntryStatus');
            $status.removeClass('text-muted text-success text-warning text-danger');
            
            switch(type) {
                case 'success':
                    $status.addClass('text-success');
                    $status.html(`<i class="fas fa-check-circle me-1"></i>${message}`);
                    break;
                case 'warning':
                    $status.addClass('text-warning');
                    $status.html(`<i class="fas fa-exclamation-triangle me-1"></i>${message}`);
                    break;
                case 'danger':
                    $status.addClass('text-danger');
                    $status.html(`<i class="fas fa-times-circle me-1"></i>${message}`);
                    break;
                default:
                    $status.addClass('text-info');
                    $status.html(`<i class="fas fa-info-circle me-1"></i>${message}`);
            }
            
            // Add close button to status message
            $status.append('<button type="button"style="background-color:red; font-weight:bold; align-items:center;"class="btn-close ms-2" aria-label="Close">&times;</button>');
            
            // Handle close button click
            $status.find('.btn-close').on('click', function() {
                $status.fadeOut(500, function() {
                    $status.removeClass('text-success text-warning text-danger text-info')
                           .addClass('text-muted')
                           .html('')
                           .show();
                });
            });
        }
        
        function setMarksFromTo(fromValue, toValue) {
            const fromMark = parseFloat(fromValue);
            const toMark = parseFloat(toValue);
            if (isNaN(fromMark) || isNaN(toMark)) return;
            
            // Only set marks for students who have the specified from value (exclude disabled and readonly inputs)
            $('input[name="mark[]"]').not(':disabled').not('[readonly]').each(function() {
                if (parseFloat($(this).val()) == fromMark) {
                    const maxMark = parseFloat($(this).data('hps') || 100);
                    const safeValue = Math.min(Math.max(toMark, 0), maxMark);
                    $(this).val(safeValue).trigger('input');
                    updatePercentage(this);
                }
            });
            hasUnsavedChanges = true;
        }
        
        function setMarksForZeroStudents(value) {
            setMarksFromTo(0, value);
        }
        
        // Handle Enter key in bulk set input
        $('#bulkSetMarks').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                const $input = $(this);
                const value = $input.val();
                if (!isNaN(parseFloat(value)) && value.trim() !== '') {
                    setMarksForZeroStudents(value);
                    $input.val(''); // Clear the input after processing
                }
                return false;
            }
        });
        
        // Handle Enter key in the new "from/to" input boxes
        $('#bulkSetMarksTo').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                const fromValue = $('#bulkSetMarksFrom').val();
                const toValue = $('#bulkSetMarksTo').val();
                if (!isNaN(parseFloat(fromValue)) && !isNaN(parseFloat(toValue)) && 
                    fromValue.trim() !== '' && toValue.trim() !== '') {
                    setMarksFromTo(fromValue, toValue);
                    $('#bulkSetMarksFrom').val(''); // Clear the inputs after processing
                    $('#bulkSetMarksTo').val('');
                }
                return false;
            }
        });
        
        // Handle Ctrl+Enter in bulk entry textareas to parse
        $('#bulkStudentNumbers, #bulkMarks').on('keydown', function(e) {
            if (e.ctrlKey && e.which === 13) { // Ctrl+Enter
                e.preventDefault();
                $('#parseBulkEntry').click();
            }
        });
        
        // Auto-fill 0 for blank lines after valued lines in bulkMarks textarea
        $('#bulkMarks').on('input', function(e) {
            let content = $(this).val();
            const lines = content.split('\n');
            let modified = false;
            
            // Find the last line that has actual content (not empty or just whitespace)
            let lastContentLineIndex = -1;
            for (let i = lines.length - 1; i >= 0; i--) {
                if (lines[i].trim() !== '') {
                    lastContentLineIndex = i;
                    break;
                }
            }
            
            for (let i = 0; i < lines.length; i++) {
                const currentLine = lines[i].trim();
                
                // Check if current line has a value and next line is blank
                // AND current line is NOT the last line with content
                if (currentLine !== '' && i < lines.length - 1 && i < lastContentLineIndex) {
                    const nextLine = lines[i + 1].trim();
                    if (nextLine === '') {
                        // Replace blank line with 0
                        lines[i + 1] = '0';
                        modified = true;
                    }
                }
            }
            
            // Update textarea if modifications were made
            if (modified) {
                // Remove all trailing empty lines and trailing 0s that represent blank lines
                let updatedContent = lines.join('\n');
                updatedContent = updatedContent.replace(/\n+$/, '').replace(/^\n+/, '');
                
                // Don't remove trailing 0s to maintain count with student numbers
                // Instead, just clean up formatting
                updatedContent = updatedContent.replace(/\n+$/, '').replace(/^\n+/, '');
                
                // Store cursor position before updating
                const $this = $(this);
                const cursorPos = $this[0].selectionStart;
                
                // Update content
                $this.val(updatedContent);
                
                // Restore cursor position or focus on last valued line
                setTimeout(function() {
                    if (updatedContent === '') {
                        // If content is empty, focus at start
                        $this[0].setSelectionRange(0, 0);
                    } else {
                        const linesAfterUpdate = updatedContent.split('\n');
                        
                        // Find the last line with a non-zero value
                        let lastNonZeroIndex = -1;
                        for (let i = linesAfterUpdate.length - 1; i >= 0; i--) {
                            if (linesAfterUpdate[i].trim() !== '' && linesAfterUpdate[i].trim() !== '0') {
                                lastNonZeroIndex = i;
                                break;
                            }
                        }
                        
                        // If no non-zero lines found, focus at the end
                        if (lastNonZeroIndex === -1) {
                            $this[0].setSelectionRange(updatedContent.length, updatedContent.length);
                        } else {
                            // Calculate position to end of last non-zero valued line
                            let targetPos = 0;
                            for (let i = 0; i <= lastNonZeroIndex; i++) {
                                if (i === lastNonZeroIndex) {
                                    targetPos += linesAfterUpdate[i].length;
                                } else {
                                    targetPos += linesAfterUpdate[i].length + 1; // +1 for newline
                                }
                            }
                            
                            // Set cursor to end of last non-zero valued line
                            $this[0].setSelectionRange(targetPos, targetPos);
                        }
                    }
                    $this.focus();
                }, 0);
            }
        });
        
       
    });
    
    // Track if there are unsaved changes
    let hasUnsavedChanges = false;
    
    // Track original values
    let originalValues = {};
    
    // Initialize form change tracking
    $(document).ready(function() {
        // Store original values on page load
        $('input[name^="mark"]').each(function() {
            originalValues[$(this).attr('name')] = $(this).val();
        });
        
        // Initialize tooltip for save button
        const saveBtnTooltip = new bootstrap.Tooltip(document.getElementById('saveMarksBtn'), {
            trigger: 'manual',
            placement: 'top'
        });

        // Initialize tooltips for dropped students (modern popup style)
        var droppedRows = document.querySelectorAll('.dropped-student-row[data-bs-toggle="tooltip"]');
        droppedRows.forEach(function(el) {
            new bootstrap.Tooltip(el, {
                trigger: 'hover',
                placement: 'top'
            });
        });

        // Check for changes on input
        $(document).on('input', 'input[name^="mark"]', function() {
            checkForChanges();
            
            // Show tooltip on save button
            if ($(this).val() !== originalValues[$(this).attr('name')]) {
                saveBtnTooltip.show();
                // Hide tooltip after 3 seconds
               
            }
        });

        // Hide tooltip when save button is clicked
        $('#saveMarksBtn').on('click', function() {
            saveBtnTooltip.hide();
            // Clear DataTables search bar when saving marks
            if ($('#marks-table').length && $.fn.DataTable.isDataTable('#marks-table')) {
                $('#marks-table').DataTable().search('').draw();
            }
        });
        
        
        // Make disableNavigation function available globally
        window.disableNavigation = function() {
            $('a:not([href^="#"])').each(function() {
                if (!$(this).hasClass('no-disable')) {
                    $(this).addClass('disabled-link');
                    $(this).css('pointer-events', 'none');
                    $(this).css('opacity', '0.5');
                }
            });
        }
        
        // Make enableNavigation function available globally
        window.enableNavigation = function() {
            $('.disabled-link').removeClass('disabled-link');
            $('a').css('pointer-events', '');
            $('a').css('opacity', '');
        }
        
        // Make checkForChanges function available globally
        window.checkForChanges = function() {
            hasUnsavedChanges = false;
            $('input[name^="mark"]').each(function() {
                if ($(this).val() !== originalValues[$(this).attr('name')]) {
                    hasUnsavedChanges = true;
                    return false; // Exit the loop early if any change is found
                }
            });
            
            if (hasUnsavedChanges) {
                disableNavigation();
            } else {
                enableNavigation();
            }
        }
    });
    
    // Function to reset all mark inputs to 0
    function clearAllMarks() {
        $('#marks-table input[name="mark[]"]').val('0');
        checkForChanges();
    }

    $(function(){
        $('#class_id').change(function(){
            var selectedClassId = $(this).val();
            $('#assessment_id option.item').hide()
            var visibleAssessments = $('#assessment_id option.item[data-class="'+selectedClassId+'"]');
            if(visibleAssessments.length > 0) {
                visibleAssessments.show();
                // Reset to default option
                $('#assessment_id').val($('#assessment_id option:first').val());
            } else {
                // If no assessments for this class, clear the selection
                $('#assessment_id').val('');
                // Hide all assessment options except the default one
                $('#assessment_id option:not(:first)').hide();
            }
        })
        $('#filter').click(function(){
            var classId = $('#class_id').val();
            var assessmentId = $('#assessment_id').val();
            $('#form_assessment_id').val(assessmentId);
            location.href = './index.php?page=marks_and_assessments&class_id='+classId+'&assessment_id='+assessmentId;
        })
    // Function to validate marks against HPS
    function validateMarks() {
        var hps = parseFloat($('#hps').text()) || 0;
        var hasError = false;
        
        $('input[name="mark[]"]').each(function() {
            var mark = parseFloat($(this).val()) || 0;
            if (mark > hps) {
                hasError = true;
                $(this).addClass('is-invalid');
                $(`<div class="invalid-feedback">Mark cannot exceed HPS (${hps})</div>`).insertAfter($(this));
            } else {
                $(this).removeClass('is-invalid');
                $(this).siblings('.invalid-feedback').remove();
            }
        });
        
        return !hasError;
    }

    // Add click handler for save button
    $('#saveMarksBtn').click(function() {
        saveMarks();
    });

    // Function to save marks via AJAX
    function saveMarks() {
        // Update original values to current values before saving
        $('input[name^="mark"]').each(function() {
            originalValues[$(this).attr('name')] = $(this).val();
        });
        hasUnsavedChanges = false;
        enableNavigation();
        
        // First validate marks against HPS
        if (!validateMarks()) {
            var el = $('<div>')
                .addClass('pop_msg alert alert-danger')
                .text('One or more marks exceed the Highest Possible Score (HPS). Please correct them before saving.');
            
            $('.pop_msg').remove();
            $('#marks-form').prepend(el);
            el.show('slow');
            return false;
        }
        
        var _this = $('#marks-form')
        var _this_btn = _this.find('button')
        _this_btn.attr('disabled', true)
        _this.find('.err-msg').remove()
        var el = $('<div>')
        el.addClass('pop_msg')
        el.hide()
        
        $.ajax({
            url: './Actions.php?a=save_mark',
            data: _this.serialize(),
            method: 'POST',
            dataType: 'json',
            error: function(err) {
                console.error(err)
                el.addClass('alert alert-danger')
                el.text('An error occurred while saving the marks.')
                _this.prepend(el)
                el.show('slow')
                _this_btn.attr('disabled', false)
            },
            success: function(resp) {
                if(!!resp.status && resp.status == 'success') {
                    el.addClass('alert alert-success')
                    el.text(resp.msg)
                    _this.prepend(el)
                    el.show('slow')
                    
                    // Update the HPS display if it changed
                    if(resp.hps) {
                        $('#hps').text(resp.hps);
                    }
                    
                    // Hide success message after 1 second
                    setTimeout(function(){
                        el.hide('slow', function(){
                            el.remove()
                        })
                    }, 100)
                } else {
                    el.addClass('alert alert-danger')
                    el.text(resp.msg)
                    _this.prepend(el)
                    el.show('slow')
                }
                _this_btn.attr('disabled', false)
            }
        })
    }

    // Add keydown event listener to all input fields in the marks table
    $(document).on('keydown', '#marks-table input[type="number"]', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            
            // Validate the current input
            var hps = parseFloat($('#hps').text()) || 0;
            var mark = parseFloat($(this).val()) || 0;
            
            if (mark > hps) {
                // Show error for this field
                $(this).addClass('is-invalid');
                $(`<div class="invalid-feedback">Mark cannot exceed HPS (${hps})</div>`).insertAfter($(this));
                return false;
            } else {
                $(this).removeClass('is-invalid');
                $(this).siblings('.invalid-feedback').remove();
                
                
                // Move to next input field
                var $next = $(this).closest('tr').next().find('input[type="number"]');
                if ($next.length) {
                    $next.focus();
                }
            }
        }
    });
    
    // Add validation on input blur
    $(document).on('blur', '#marks-table input[type="number"]', function() {
        var hps = parseFloat($('#hps').text()) || 0;
        var mark = parseFloat($(this).val()) || 0;
        
        if (mark > hps) {
            $(this).addClass('is-invalid');
            $(`<div class="invalid-feedback">Mark cannot exceed HPS (${hps})</div>`).insertAfter($(this));
        } else {
            $(this).removeClass('is-invalid');
            $(this).siblings('.invalid-feedback').remove();
        }
    });
    
    
    // Handle form submission
    $('#marks-form').on('submit', function(e) {
        e.preventDefault();
        saveMarks();
    });
    
    // Handle Enter key on select elements
    $(document).on('keydown', 'select', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var $next = $(this).closest('.form-group').next('.form-group').find('select, input[type!="hidden"], button');
            if ($next.length) {
                $next.focus();
            } else {
                $('#filter').click();
            }
        }
    });
    
    if($('#marks-table').length > 0){
        var table = $('#marks-table').DataTable({
            responsive: true,
            columnDefs: [
                { orderable: true, targets: [1] } // Disable sorting on Mark column
            ],
            pageLength: -1,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            dom: '<"top"f>rt<"bottom"lip><"clear">',
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search students... (Press Enter to focus on marks)",
                lengthMenu: "Show _MENU_ students per page"
            },
            initComplete: function() {
                // Focus on search input when table is initialized
                $('.dataTables_filter input').focus();
            }
        });

        // Toggle flag for Enter key behavior (default OFF)
        var enterBehaviorEnabled = false;

        // Toggle button click handler
        $('#toggleEnterBehavior').on('click', function() {
            enterBehaviorEnabled = !enterBehaviorEnabled;
            var $btn = $(this);
            var $text = $btn.find('.toggle-enter-text');

            if (enterBehaviorEnabled) {
                $btn.addClass('on').attr('aria-pressed', 'true');
                
            } else {
                $btn.removeClass('on').attr('aria-pressed', 'false');
                
            }
        });

        // Handle search input keydown
        $('.dataTables_filter input').on('keydown', function(e) {
            if (!enterBehaviorEnabled) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                // Focus on first input in the table
                $('input[name^="mark"]').first().focus().select();
            }
        });

        // Handle input fields keydown
        $(document).on('keydown', 'input[name^="mark"]', function(e) {
            if (!enterBehaviorEnabled) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                // Clear and focus on search input
                var searchInput = $('.dataTables_filter input');
                searchInput.val('').focus();
                table.search('').draw();
            }
        });
    }
    
    // Clear button click handler
    $('#clearMarks').click(function() {
        clearAllMarks();
    });

    if('<?php echo isset($_GET['class_id']) && isset($_GET['assessment_id']) ?>' == 1){
        $('#class_id').trigger('change')
        
        // Clear marks when assessment changes
        $('#assessment_id').change(function() {
            clearAllMarks();
        });
        
        $('#marks-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove()
            var hps = parseFloat($('#hps').text())
            if($('input[name="mark[]"]').length <= 0 ){
                alert("No Student is listed yet in selected class.")
                return false;
            }
            $('input[name="mark[]"]').removeClass('border-danger')
            $('input[name="mark[]"]').each(function(){
                if(parseFloat($(this).val()) > hps){
                    $(this).addClass('border-danger')
                }
            })
            if($('input.border-danger[name="mark[]"]').length > 0){
                alert("Entered mark is greater than Heighest Possible Score.")
                return false;
            }
            var _this = $(this)
            var _el = $('<div>')
                _el.addClass('pop_msg')
            _this.find('button').attr('disabled',true)
            _this.find('button[type="submit"]').text('submitting form...')
            $.ajax({
                url:'./Actions.php?a=save_mark',
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
                error:err=>{
                    console.log(err)
                    _el.addClass('alert alert-danger')
                    _el.text("An error occurred.")
                    _this.prepend(_el)
                    _el.show('slow')
                     _this.find('button').attr('disabled',false)
                     _this.find('button[type="submit"]').text('Save')
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        _el.addClass('alert alert-success')
                        $('#uni_modal').on('hide.bs.modal',function(){
                            location.reload()
                        })
                    }else{
                        _el.addClass('alert alert-danger')
                    }
                    _el.text(resp.msg)

                    _el.hide()
                    _this.prepend(_el)
                    _el.show('slow')
                     _this.find('button').attr('disabled',false)
                     _this.find('button[type="submit"]').text('Save')
                }
            })
        })
        }
    })

    // Assessment Management Functions
    $(function(){
        var table; // Declare table variable in a higher scope
        
        $('#create_new').click(function(){
            uni_modal('Add New Assessment In Class',"manage_assessment.php")
        })
        $('.edit_data').click(function(){
            uni_modal('Edit Assessment Details In Class',"manage_assessment.php?id="+$(this).attr('data-id'))
        })
        $('.delete_data').click(function(){
            _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from list?",'delete_data',[$(this).attr('data-id')])
        })
        $('table td,table th').addClass('align-middle')
        
        // Initialize DataTable and store the reference
        table = $('#assessmentsTable').DataTable({
            stateSave: true, // Enable state saving
            stateSaveCallback: function(settings, data) {
                // Save only the page and search state
                localStorage.setItem('DataTables_' + settings.sInstance, JSON.stringify({
                    time: Date.now(),
                    start: data.start,
                    length: data.length,
                    order: data.order,
                    search: data.search,
                    page: data.start / data.length
                }));
            },
            stateLoadCallback: function(settings) {
                var state = localStorage.getItem('DataTables_' + settings.sInstance);
                if (state) {
                    state = JSON.parse(state);
                    // Only return state if it's less than 1 hour old
                    if (state.time > Date.now() - 60 * 60 * 1000) {
                        return state;
                    }
                }
                return null;
            },
            responsive: true,
            pageLength: 10,
            lengthMenu: [[10, 15, 25, 50, -1], [10, 15, 25, 50, 'All']],
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: [0, 7] },
                { searchable: false, targets: [0, 7] },
                { className: 'text-center', targets: [0, 1, 2, 3, 4, 5, 6, 7] },
                { className: 'component-cell', targets: 2 },
                { width: '30px', targets: 0 },
                { width: '80px', targets: 7 }
            ],
            language: {
                search: 'Search:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'No entries to show',
                infoFiltered: '(filtered from _MAX_ total entries)',
                paginate: {
                    first: 'First',
                    last: 'Last',
                    next: 'Next',
                    previous: 'Previous'
                }
            },
            dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row'<'col-sm-12'<'table-responsive'tr>>>" +
                 "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
            initComplete: function(settings, json) {
                var api = this.api();
                var state = api.state.loaded();
                
                // Restore page if it exists in the state
                if (state) {
                    api.page(state.page).draw('page');
                }
                
                // Custom search function for section filtering
                $.fn.dataTable.ext.search.push(
                    function(settings, data, dataIndex) {
                        var selectedSection = $('#sectionFilter').val();
                        if (!selectedSection || selectedSection === '') return true; // Show all if no section selected
                        
                        // Get the class text from the second column (index 1)
                        var classText = data[1] || '';
                        // Check if the class text contains the selected section
                        return classText.indexOf(selectedSection) !== -1;
                    }
                );

                // Restore section filter from localStorage if available
                var savedFilter = localStorage.getItem('assessmentSectionFilter');
                if (savedFilter !== null) {
                    $('#sectionFilter').val(savedFilter);
                    api.draw();
                }

                // Apply the section filter and save to localStorage
                $('#sectionFilter').on('change', function() {
                    var section = $(this).val();
                    // Save to localStorage
                    if (section) {
                        localStorage.setItem('assessmentSectionFilter', section);
                    } else {
                        localStorage.removeItem('assessmentSectionFilter');
                    }
                    api.draw();
                });
            }
        })
    })
    function recomputeComponentPercentages(classText, componentText, quarterText) {
        var rows = [];

        $('#assessmentsTable tbody tr').each(function(){
            var $tr = $(this);
            var tClass = $tr.find('td').eq(1).text().trim();
            var tComp = $tr.find('td').eq(2).text().trim();
            var tQuarter = $tr.find('td').eq(3).text().trim();

            if (tClass === classText && tComp === componentText && tQuarter === quarterText) {
                rows.push($tr);
            }
        });

        var count = rows.length;
        if (count === 0) return;

        // Mirror backend logic: equal share truncated to 2 decimals, last row gets remainder
        var evenShare = Math.floor((100 / count) * 100) / 100; // truncate to 2 decimals
        var runningTotal = 0;

        rows.forEach(function($tr, idx){
            var value;
            if (idx === count - 1) {
                value = 100 - runningTotal;
            } else {
                value = evenShare;
                runningTotal += value;
            }
            // Percentage column is index 5 (0:#,1:Class,2:Component,3:Quarter,4:Name,5:%,6:HPS,7:Action)
            $tr.find('td').eq(5).text(value.toFixed(2) + '%');
            $tr.addClass('highlight-updated');
            setTimeout(function(){
                $tr.removeClass('highlight-updated');
            }, 1500);
        });
    }

    function recomputeQuizzesPercentages(classText, componentText, quarterText) {
        // Only adjust if component is Quizzes
        if (componentText !== 'Quizzes') return;
        
        // Call the generic function for backward compatibility
        recomputeComponentPercentages(classText, componentText, quarterText);
    }

    function delete_data($id){
        $('#confirm_modal button').attr('disabled',true)
        $.ajax({
            url:'./Actions.php?a=delete_assessment',
            method:'POST',
            data:{id:$id},
            dataType:'JSON',
            error:err=>{
                console.log(err)
                showDynamicUpdateNotification('error', 'An error occurred while deleting the assessment.')
                $('#confirm_modal button').attr('disabled',false)
            },
            success:function(resp){
                if(resp.status == 'success'){
                    var $row = $(".delete_data[data-id='" + $id + "']").closest('tr');
                    var classText = $row.find('td').eq(1).text().trim();
                    var componentText = $row.find('td').eq(2).text().trim();
                    var quarterText = $row.find('td').eq(3).text().trim();

                    // Add removal animation
                    $row.addClass('highlight-removing');
                    
                    // Remove the deleted row from the table after animation
                    setTimeout(function(){
                        $row.fadeOut(400, function() {
                            $(this).remove();
                            
                            // Update row numbers
                            updateRowNumbers();
                            
                            // If this was any component and we have updated assessments data
                            if (resp.deleted_assessment && resp.updated_assessments) {
                                // Update percentages for remaining assessments in the component
                                resp.updated_assessments.forEach(function(updatedAssessment) {
                                    var $updateRow = $(".edit_data[data-id='" + updatedAssessment.assessment_id + "']").closest('tr');
                                    if ($updateRow.length) {
                                        $updateRow.find('td').eq(5).text(parseFloat(updatedAssessment.percentage).toFixed(2) + '%');
                                        $updateRow.addClass('highlight-updated');
                                        setTimeout(function(){
                                            $updateRow.removeClass('highlight-updated');
                                        }, 1500);
                                    }
                                });
                            }
                            
                            // Check if table is empty and show appropriate message
                            checkTableEmpty();
                        });
                    }, 200);
                    $('#confirm_modal button').attr('disabled',false)

                    $('#confirm_modal').modal('hide');
                }else{
                    showDynamicUpdateNotification('error', 'An error occurred while deleting the assessment.')
                    $('#confirm_modal button').attr('disabled',false)
                }
            }
        })
    }
    
    // Helper function to update row numbers after deletion
    function updateRowNumbers() {
        $('#assessmentsTable tbody tr').each(function(index) {
            $(this).find('td').eq(0).text(index + 1);
        });
    }
    
    // Helper function to check if table is empty and show message
    function checkTableEmpty() {
        var rowCount = $('#assessmentsTable tbody tr').length;
        if (rowCount === 0) {
            $('#assessmentsTable tbody').html('<tr><td colspan="8" class="text-center">No assessment records found.</td></tr>');
        }
    }
    
    // Function to show dynamic update notifications
    function showDynamicUpdateNotification(type, message) {
        var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        var icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
        
        var notification = $('<div class="alert ' + alertClass + ' alert-dismissible fade show position-fixed" style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;">' +
            '<i class="' + icon + ' me-2"></i>' +
            '<strong>' + (type === 'success' ? 'Success!' : 'Error!') + '</strong> ' + message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
        '</div>');
        
        $('body').append(notification);
        
        // Auto-hide after 1 second
        setTimeout(function() {
            notification.fadeOut(500, function() {
                $(this).remove();
            });
        }, 1000);
    }
</script>
