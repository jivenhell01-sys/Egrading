<?php
require_once(__DIR__ . '/../DBConnection.php');

// Get unique years from admin_list table for filtering (faculty role)
$availableYears = [];
$yearsQuery = $conn->query("SELECT DISTINCT strftime('%Y', date_created) as year FROM admin_list WHERE role = 'faculty' AND date_created IS NOT NULL ORDER BY year DESC");
while ($row = $yearsQuery->fetchArray(SQLITE3_ASSOC)) {
    $availableYears[] = $row['year'];
}

// Get selected year from URL parameter
$selectedYear = isset($_GET['year']) ? $_GET['year'] : '';

// Get all faculty with their database paths (from both admin_list and faculty tables)
$facultyList = [];
$whereClause = "WHERE role = 'faculty'";
if (!empty($selectedYear)) {
    $whereClause .= " AND strftime('%Y', date_created) = '{$selectedYear}'";
}

$facultyQry = $conn->query("SELECT admin_id, fullname, username, db_path, status, date_created FROM admin_list {$whereClause} ORDER BY fullname ASC");
while ($row = $facultyQry->fetchArray(SQLITE3_ASSOC)) {
    $facultyList[] = $row;
}

// Get selected faculty from URL parameter
$selectedFacultyId = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : null;
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
$selectedQuarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : null;
$selectedFaculty = null;
$facultyData = [];

if ($selectedFacultyId) {
    foreach ($facultyList as $faculty) {
        if ($faculty['admin_id'] == $selectedFacultyId) {
            $selectedFaculty = $faculty;
            break;
        }
    }
    
    if ($selectedFaculty && !empty($selectedFaculty['db_path']) && file_exists($selectedFaculty['db_path'])) {
        // Connect to faculty's database
        $facultyDb = new SQLite3($selectedFaculty['db_path']);
        
        // Get faculty classes
        $classes = [];
        $classQry = $facultyDb->query("SELECT cl.*, s.name as subject_name, s.name as subject_code 
                                      FROM class_list cl 
                                      LEFT JOIN subjects s ON cl.subject_id = s.subject_id
                                      ORDER BY cl.grade, cl.section");
        if ($classQry) {
            while ($row = $classQry->fetchArray(SQLITE3_ASSOC)) {
                $classes[] = $row;
            }
        }
        $facultyData['classes'] = $classes;
        
        // Get students count per class
        $studentCounts = [];
        foreach ($classes as $class) {
            $countQry = $facultyDb->query("SELECT COUNT(*) as count FROM student_list WHERE class_id = '{$class['class_id']}'");
            $count = $countQry->fetchArray(SQLITE3_ASSOC);
            $studentCounts[$class['class_id']] = $count['count'];
        }
        $facultyData['student_counts'] = $studentCounts;
        
        // Get attendance summary
        $attendanceSummary = [];
        foreach ($classes as $class) {
            $attQry = $facultyDb->query("SELECT 
                COUNT(*) as total_sessions,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
                FROM attendance WHERE class_id = '{$class['class_id']}'");
            $att = $attQry->fetchArray(SQLITE3_ASSOC);
            $attendanceSummary[$class['class_id']] = $att;
        }
        $facultyData['attendance_summary'] = $attendanceSummary;
        
        // Get assessments summary
        $assessmentSummary = [];
        foreach ($classes as $class) {
            $assQry = $facultyDb->query("SELECT COUNT(*) as total_assessments FROM assessment_list WHERE class_id = '{$class['class_id']}'");
            $ass = $assQry->fetchArray(SQLITE3_ASSOC);
            $assessmentSummary[$class['class_id']] = $ass['total_assessments'];
        }
        $facultyData['assessment_summary'] = $assessmentSummary;
        
        $facultyDb->close();
    }
}
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="mb-1"><i class="fas fa-chart-line me-2"></i>Faculty Monitoring</h3>
                    <p class="text-muted mb-0">View faculty data from their database</p>
                </div>
                <div>
                    <form method="GET" class="d-inline-block me-2">
                        <input type="hidden" name="page" value="admin/monitoring">
                        <div class="d-flex align-items-center">
                            <select name="year" class="form-select me-2" style="width: 120px;" onchange="this.form.submit()">
                                <option value="">All Years</option>
                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $selectedYear == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="faculty_id" class="form-select" onchange="this.form.submit()">
                                <option value="">Select Faculty</option>
                                <?php foreach ($facultyList as $faculty): ?>
                                    <option value="<?php echo $faculty['admin_id']; ?>" <?php echo $selectedFacultyId == $faculty['admin_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($faculty['fullname']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                    <a href="./?page=admin/faculty" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Faculty
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($selectedFaculty && !empty($selectedFaculty['db_path']) && file_exists($selectedFaculty['db_path'])): ?>
        <?php
        // Connect to faculty's database
        $facultyDb = new SQLite3($selectedFaculty['db_path']);
        ?>

                <h1 class="mb-3" style="font-size:18px !important;"><i class="fas fa-user me-2"></i><?php echo htmlspecialchars($selectedFaculty['fullname']); ?>'s Records</h1>

        <!-- Classes Toggle Button -->
        <div class="mb-3">
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#classesCardCollapse" aria-expanded="false" aria-controls="classesCardCollapse">
                <i class="fas fa-eye me-1"></i>Show Classes
            </button>
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#studentsCardCollapse" aria-expanded="false" aria-controls="studentsCardCollapse">
                    <i class="fas fa-eye me-1"></i>Show Students
                </button>
            <button class="btn btn-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reportsCardCollapse" aria-expanded="false" aria-controls="reportsCardCollapse">
                <i class="fas fa-chart-bar me-1"></i>Show Reports
            </button>
            
        </div>

        <!-- Classes Table -->
        <div class="card mb-4 collapse" id="classesCardCollapse" style="border-radius:20px !important;">
            <div class="card-header bg-dark text-white" style="border-radius:20px !important;">
                <h5 class="mb-0"><i class="fas fa-chalkboard me-2"></i><?php echo htmlspecialchars($selectedFaculty['fullname']); ?>'s Classes</h5>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 w-100" id="classesTable">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Grade & Section</th>
                                <th>Schedule</th>
                                <th>Students</th>
                                <th>Assessments</th>
                                <th>Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $classQry = $facultyDb->query("SELECT cl.*, s.name as subject_name, s.name as subject_code 
                                                          FROM class_list cl 
                                                          LEFT JOIN subjects s ON cl.subject_id = s.subject_id
                                                          ORDER BY cl.grade, cl.section");
                            if ($classQry) {
                                while ($class = $classQry->fetchArray(SQLITE3_ASSOC)):
                                    // Get student count
                                    $studentCount = 0;
                                    $countQry = $facultyDb->query("SELECT COUNT(*) as count FROM student_list WHERE class_id = '{$class['class_id']}'");
                                    if ($countQry) {
                                        $result = $countQry->fetchArray(SQLITE3_ASSOC);
                                        $studentCount = $result['count'] ?? 0;
                                    }
                                    
                                    // Get attendance marks
                                    $attendanceMark = 0;
                                    $attQry = $facultyDb->query("SELECT AVG(ml.mark) as avg_mark, COUNT(*) as count
                                        FROM mark_list ml
                                        INNER JOIN assessment_list al ON ml.assessment_id = al.assessment_id
                                        INNER JOIN grading_components gc ON al.component_id = gc.component_id
                                        WHERE al.class_id = '{$class['class_id']}' 
                                        AND gc.name LIKE 'Attendance'");
                                    if ($attQry) {
                                        $attendance = $attQry->fetchArray(SQLITE3_ASSOC);
                                        if ($attendance && $attendance['avg_mark'] !== null && $attendance['count'] > 0) {
                                            $attendanceMark = round($attendance['avg_mark'], 1);
                                        }
                                    }
                                    
                                    // Get assessment count
                                    $assessmentCount = 0;
                                    $assQry = $facultyDb->query("SELECT COUNT(*) as count FROM assessment_list WHERE class_id = '{$class['class_id']}'");
                                    if ($assQry) {
                                        $result = $assQry->fetchArray(SQLITE3_ASSOC);
                                        $assessmentCount = $result['count'] ?? 0;
                                    }
                            ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($class['subject_name'] ?? 'N/A'); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($class['course_no'] ?? ''); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($class['grade'] ?? ''); ?> - <?php echo htmlspecialchars($class['section'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($class['schedule'] ?? ''); ?></td>
                                    <td><span class="badge bg-info"><?php echo $studentCount; ?></span></td>
                                    <td><span class="badge bg-primary"><?php echo $assessmentCount; ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="badge <?php echo $attendanceMark >= 75 ? 'bg-success' : ($attendanceMark >= 60 ? 'bg-warning' : 'bg-danger'); ?> me-2">
                                                <?php echo $attendanceMark; ?>%
                                            </span>
                                         
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                            <?php } ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Students Toggle Button -->
        

        <!-- Students Table -->
            <div class="card mb-4 collapse" id="studentsCardCollapse" style="border-radius:20px !important;">
                
            <div class="card-header bg-dark text-white " style="border-radius:20px !important;">
                <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>All Students</h5>
                <div class="mb-3">
                <div class="d-flex align-items-center justify-content-end">
                    <div class="d-flex align-items-center me-3">
                        <label class="me-2">Filter by Section:</label>
                        <select id="sectionFilter" class="form-select form-select-sm" style="width: 150px;">
                            <option value="">All Sections</option>
                            <?php
                            // Get unique sections for this faculty
                            $sections = [];
                            $sectionQry = $facultyDb->query("SELECT DISTINCT cl.section 
                                                        FROM class_list cl 
                                                        ORDER BY cl.section");
                            while ($section = $sectionQry->fetchArray(SQLITE3_ASSOC)) {
                                $sections[] = $section['section'];
                            }
                            foreach ($sections as $section) {
                                echo "<option value='" . htmlspecialchars($section) . "'>" . htmlspecialchars($section) . "</option>";
                            }
                            ?>
                        </select>
                        </div>
                </div>
            </div>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 w-100" id="studentsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Grade & Section</th>
                                <th>Attendance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $studentQry = $facultyDb->query("SELECT sl.*, cl.grade, cl.section,
                                                           (SELECT (SUM(m.mark) / SUM(a.hps)) * (csp.percentage / 100) * 100
                                                            FROM mark_list m 
                                                            INNER JOIN assessment_list a ON m.assessment_id = a.assessment_id
                                                            INNER JOIN grading_components gc ON a.component_id = gc.component_id
                                                            INNER JOIN component_subject_percentage csp ON gc.component_id = csp.component_id
                                                            WHERE m.student_id = sl.student_id AND gc.name LIKE '%Attendance%') as attendance_avg,
                                                           (SELECT SUM(a.hps)
                                                            FROM assessment_list a 
                                                            INNER JOIN grading_components gc ON a.component_id = gc.component_id
                                                            WHERE gc.name LIKE '%Attendance%') as attendance_hps
                                                           FROM student_list sl 
                                                           LEFT JOIN class_list cl ON sl.class_id = cl.class_id 
                                                           ORDER BY sl.name");
                            while ($student = $studentQry->fetchArray(SQLITE3_ASSOC)):
                                $status = $student['status'] ?? 'Unknown';
                                $statusText = $status;
                                $badgeClass = ($status === 'Active' || $status === 'active') ? 'bg-success' : 'bg-danger';
                                
                                // Add quarter info for NoExam and NoSubmissions
                                if (($status === 'NoExam' || $status === 'NoSub') && !empty($student['status_quarter'])) {
                                    if($student['status_quarter'] == 1)
                                    $statusText .= " (Prilem)";
                                    if($student['status_quarter'] == 2)
                                    $statusText .= " (Midterm)";
                                    if($student['status_quarter'] == 3)
                                    $statusText .= " (Pre-Final)";
                                    if($student['status_quarter'] == 4)
                                    $statusText .= " (Final)";
                                }
                                
                                // Format attendance average
                                $attendanceDisplay = '';
                                if ($student['attendance_avg'] !== null && $student['attendance_avg'] >= 0) {
                                    $attendanceDisplay = round($student['attendance_avg'], 1) . '%';
                                }
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['grade'] ?? ''); ?> - <?php echo htmlspecialchars($student['section'] ?? ''); ?></td>
                                    <td><?php echo $attendanceDisplay; ?>/<?php echo $student['attendance_hps'] ?? ''; ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusText); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reports Table -->
        <div class="card mb-4 collapse <?php echo $selectedClassId ? 'show' : ''; ?>" id="reportsCardCollapse" style="border-radius:20px !important;">
            <div class="card-header bg-dark text-white" style="border-radius:20px !important;">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Grade Reports</h5>
                    <div class="d-flex align-items-center">
                        <label class="me-2 mb-0">Filter by Class:</label>
                        <select id="classFilter" class="form-select form-select-sm" style="width: 300px;">
                            <option value="">All Classes</option>
                            <?php
                            // Get unique classes with quarters for this faculty
                            $classFilterQry = $facultyDb->query("SELECT DISTINCT cl.class_id, cl.grade, cl.section, s.name as subject_name, 
                                                                        a.quarter as assessment_quarter, q.name as quarter_name
                                                                FROM class_list cl 
                                                                LEFT JOIN subjects s ON cl.subject_id = s.subject_id
                                                                LEFT JOIN assessment_list a ON cl.class_id = a.class_id
                                                                LEFT JOIN quarter_list q ON q.class_id = cl.class_id AND q.quarter = a.quarter
                                                                GROUP BY cl.class_id, a.quarter
                                                                ORDER BY cl.grade, cl.section, a.quarter");
                            while ($classRow = $classFilterQry->fetchArray(SQLITE3_ASSOC)) {
                                $classValue = $classRow['class_id'] . '|' . ($classRow['assessment_quarter'] ?? '');
                                $quarterName = !empty($classRow['quarter_name']) ? $classRow['quarter_name'] : ('Quarter ' . ($classRow['assessment_quarter'] ?? ''));
                                $classLabel = ($classRow['grade'] ?? '') . ' - ' . ($classRow['section'] ?? '') . ' (' . ($classRow['subject_name'] ?? '') . ') - ' . $quarterName;
                                $isSelected = '';
                                if ($selectedClassId && $selectedQuarter) {
                                    if ((int)$selectedClassId === (int)$classRow['class_id'] && (int)$selectedQuarter === (int)$classRow['assessment_quarter']) {
                                        $isSelected = 'selected';
                                    }
                                }
                                echo "<option value='" . $classValue . "' " . $isSelected . ">" . htmlspecialchars($classLabel) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <?php 
                    $components = array();
                    $components_query=$facultyDb->query("SELECT c.*,g.name FROM `component_subject_percentage` c inner join `grading_components` g on c.component_id = g.component_id inner join class_list cl on c.subject_id = cl.subject_id " . ($selectedClassId ? "WHERE cl.class_id = '{$selectedClassId}'" : "") . " order by component_id asc ");
                    while($row = $components_query->fetchArray()){
                        $components[$row['component_id']] = $row;
                    }
                    $assess = array();
                    $assess_ids = array();
                    $assess_query=$facultyDb->query("SELECT * FROM `assessment_list` " . ($selectedClassId ? "WHERE class_id = '{$selectedClassId}'" : "") . " order by `name` asc ");
                    $component_totals = array(); // To store total percentage for each component
                    
                    // First pass: Store assessments and calculate total percentage per component
                    while($row = $assess_query->fetchArray()){
                        $assess[$row['component_id']][$row['assessment_id']] = $row;
                        $assess_ids[] = $row['assessment_id'];
                        // Initialize component total if not set
                        if (!isset($component_totals[$row['component_id']])) {
                            $component_totals[$row['component_id']] = 0;
                        }
                        // Add to component total (ensure percentage is treated as a number)
                        $component_totals[$row['component_id']] += floatval($row['percentage']);
                    }
                    
                    // Second pass: Calculate weight for each assessment
                    foreach ($assess as $component_id => $assessments) {
                        $component_total = $component_totals[$component_id] > 0 ? $component_totals[$component_id] : 100; // Avoid division by zero
                        foreach ($assessments as $assessment_id => $assessment) {
                            // Calculate weight as a percentage of the component's total percentage
                            $weight = ($assessment['percentage'] / $component_total) * 100;
                            $assess[$component_id][$assessment_id]['weight'] = $weight;
                        }
                    }
                    // Safeguard: if there are no assessments, use a harmless IN list to avoid SQL error
                    $a_ids = !empty($assess_ids) ? implode(",",$assess_ids) : '0';
                    $marks = array();
                    $students = array();
                    $marks_qry = $facultyDb->query("SELECT * FROM `mark_list` WHERE assessment_id IN ({$a_ids}) ");
                    while($row = $marks_qry->fetchArray()){
                        $marks[$row['student_id']][$row['assessment_id']] = $row['mark'];
                    }
                    $student_query = $facultyDb->query("SELECT sl.*, cl.class_id FROM student_list sl LEFT JOIN class_list cl ON sl.class_id = cl.class_id " . ($selectedClassId ? "WHERE cl.class_id = '{$selectedClassId}'" : "") . " ORDER BY sl.name ASC");
                    $students = array();
                    $student_status = array();
                    $student_classes = array();
                    
                    while($row = $student_query->fetchArray()){
                        $students[$row['student_id']] = $row['name'];
                        $student_status[$row['student_id']] = $row['status'] ?? 'Active';
                        $student_classes[$row['student_id']] = $row['class_id'] ?? '';
                    }
                    
                    // Filter assessments by quarter if selected
                    if ($selectedQuarter) {
                        foreach ($assess as $component_id => $assessments) {
                            foreach ($assessments as $assessment_id => $assessment) {
                                if ($assessment['quarter'] != $selectedQuarter) {
                                    unset($assess[$component_id][$assessment_id]);
                                }
                            }
                            // Remove empty component arrays
                            if (empty($assess[$component_id])) {
                                unset($assess[$component_id]);
                            }
                        }
                    }
                    $c_perc_arr = array();
                    foreach($components as $row){
                        $c_perc_arr[$row['component_id']] =( $row['percentage']/100);
                    }
                    $__trans_system = get_active_transmutation_system($facultyDb);
                    $transmutaion_query = $facultyDb->query("SELECT * FROM transmutation_table WHERE `system` = '{$__trans_system}'");
                    $trans_tbl = array();
                    while($row = $transmutaion_query->fetchArray()){
                        $trans_tbl[$row['trans_id']] = $row;
                    }
                    function transmute($grade = 0){
                        global $trans_tbl;
                        $transmuted = 0;
                        foreach($trans_tbl as $row){
                            if($row['from'] <= $grade && $grade <= $row['to']){
                                $transmuted = $row['grade'];
                            }
                        }
                        return $transmuted;
                    }
                    // Convert transmuted grade (60-100) to 1.0–5.0 equivalent
                     function get_equivalent_from_transmutation($grade) {
                        global $facultyDb;
                       
                        $grade = round(floatval($grade), 2);
                        
                        // Get the matching range from the active (CHED/DEPED) transmutation table
                        static $trans_system = null;
                        if ($trans_system === null) { $trans_system = get_active_transmutation_system($facultyDb); }
                        $transmutation_query = $facultyDb->query("SELECT grade, `from`, `to` FROM `transmutation_table` WHERE `system` = '{$trans_system}' ORDER BY `from` ASC");
                        $equivalent = 5.5; // Default numeric value if no range matches

                        while ($row = $transmutation_query->fetchArray(SQLITE3_ASSOC)) {
                            $from = floatval($row['from']);
                            $to   = floatval($row['to']);

                            if ($grade >= $from && $grade <= $to) {
                                $equivalent = floatval($row['grade']);
                                break;
                            }
                        }

                        // DepEd transmuted grades (60-100 scale) pass through untouched.
                        if ($equivalent > 5.5) {
                            return number_format($equivalent, 1);
                        }

                        // If equivalent is above 5.0, treat as DROPPED
                        if ($equivalent > 5.0) {
                            return 'DR';
                        }

                        return number_format($equivalent, 1);
                    }

                    function transmuted_to_equivalent($from, $to = null) {
                        // Handle both old and new function signatures
                        if ($to === null) {
                            $g = floatval($from);
                            if ($g <= 60) return number_format(5.0, 1);
                            if ($g == 100) return number_format(1.0, 1);
                            $equiv = 5.0 - (($g - 60.0) / 35.0) * 4.0; // maps 60->5.0 down to 95->1.0
                            if ($equiv < 1.0) $equiv = 1.0;
                            if ($equiv > 5.0) $equiv = 5.0;
                            return number_format($equiv, 1);
                        } else {
                            // New implementation using from and to
                            $from = floatval($from);
                            $to = floatval($to);
                            $midpoint = ($from + $to) / 2;
                            
                            // Handle edge cases
                            if ($midpoint <= 50) return 5.5;
                            if ($midpoint == 100) return 1.0;
                            
                            // Calculate equivalent based on the range
                            if ($midpoint <= 75) {
                                // Linear interpolation between 50 (5.5) and 75 (3.0)
                                $equiv = 5.5 - (($midpoint - 50) / (75 - 50)) * 2.5;
                            } else {
                                // Linear interpolation between 75 (3.0) and 100 (1.0)
                                $equiv = 3.0 - (($midpoint - 75) / (100 - 75)) * 2.0;
                            }
                            
                            // Ensure the equivalent is within bounds
                            return max(1.0, min(5.5, $equiv));
                        }
                    }
                    ?>
                    <?php if(empty($components)): ?>
                        <div class="alert alert-warning rounded-0 py-2">No component percentages configured for this class.</div>
                    <?php else: ?>
                    <table class="table table-striped table-hover table-bordered w-100" id="gradesTable" style="min-width: 800px max-width: 100%; border-width: 3px; border-color:rgb(0, 0, 0);">
                        <thead class="sticky-top">
                            <tr class="bg-dark text-white">
                                <th class="py-1 px-2 text-center align-middle" rowspan="3" style="font-size: 9pt; width: auto; min-width: 250px; max-width: 250px; background-color:gold !important; color:darkgreen !important; border-color:darkgreen !important; border-width:2px !important;">Student</th>
                                <?php 
                                foreach($components as $row): 
                                    $colspan = isset($assess[$row['component_id']]) ? count($assess[$row['component_id']]) + 2 : 3;
                                ?>
                                <th class="py-1 px-2 text-center" style="font-size: 9pt; background-color:gold !important; color:darkgreen !important; border-color:darkgreen !important; border-width:2px !important;" colspan="<?php echo $colspan ?>">
                                    <div class="d-flex flex-column">
                                        <span><?php 
                                            $name = htmlspecialchars($row['name']);
                                            $name = preg_replace('/([A-Z])/', '<span class="capital-red">$1</span>', $name);
                                            echo $name;
                                        ?></span>
                                        <small class="fw-normal">(<?php echo $row['percentage'] ?>%)</small>
                                        <style>
                                            .capital-red {
                                                color: red;
                                            }
                                        </style>
                                    </div>
                                </th>
                                
                                <?php endforeach; ?>
                                <th class="py-1 px-2 text-center align-middle initial-grade-header" style="font-size: 9pt; background-color:gold !important; color:darkgreen !important; border-color:darkgreen !important; border-width:2px !important;" rowspan="3" width="80px">Initial Grade</th>
                                <th class="py-1 px-2 text-center align-middle quarter-grade-header" style="font-size: 9pt; background-color:gold !important; color:darkgreen !important; border-color:darkgreen !important; border-width:2px !important;" rowspan="3" width="80px">Quarter<br>Grade</th>
                            </tr>
                            <tr>
                                <?php 
                                foreach($components as $k => $v): 
                                    if(isset($assess[$k])):
                                        foreach($assess[$k] as $row): 
                                ?>
                                <th class="py-1 px-2 text-center bg-info bg-opacity-50" style="font-size: 9pt; width: auto; min-width: 10px;" title="<?php echo htmlspecialchars($row['name']) ?>">
                                    <div class="text-truncate" style="font-size: 9pt; max-width: auto; color:maroon;"><?php echo htmlspecialchars($row['name']) ?></div>
                                </th>
                                
                                <?php 
                                        endforeach; 
                                ?>
                                <th class="py-1 px-2 text-center bg-warning bg-opacity-50" style="font-size: 9pt; min-width: 10px;">T</th>
                                <th class="py-1 px-2 text-center bg-dark bg-opacity-50 text-white" style="font-size: 9pt; min-width: 20px; background-color:rgb(175, 172, 172);">Avg</th>
                                <?php 
                                    else:
                                ?>
                                
                                <th class="py-1 px-2 text-center bg-secondary bg-opacity-25" colspan="3">
                                    <small>No assessments</small>
                                </th>
                                <?php
                                    endif;
                                endforeach; 
                                ?>
                            </tr>
                            <tr class="bg-warning bg-opacity-25">
                                <?php 
                                $total_hps_arr = array();
                                foreach($components as $k => $v): 
                                    $total_hps = 0;
                                    $percentage = $v['percentage'];
                                    if(isset($assess[$k])):
                                        foreach($assess[$k] as $row): 
                                            $total_hps += floatval($row['hps']);
                                ?>
                                <th class="py-1 px-2 text-center" style="font-size: 0.8rem;">/<?php echo $row['hps'] ?></th>
                                <?php 
                                        endforeach; 
                                ?>
                                <th class="py-1 px-2 text-center fw-bold" style="font-size: 0.9rem;"><?php echo $total_hps_arr[$k] = $total_hps ?></th>
                                <th class="py-1 px-2 text-center bg-dark bg-opacity-50 text-white" style="font-size: 0.9rem;"><?php echo $percentage ?>%</th>
                                <?php 
                                    else:
                                ?>
                                <th class="py-1 px-2 text-center" colspan="3">-</th>
                                <?php
                                    endif;
                                endforeach; 
                                ?>
                            </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Show a full-width row when no students are found
                        if(empty($students)){
                            $col_count = 1; // Student column
                            foreach($components as $k => $v){
                                $col_count += isset($assess[$k]) ? count($assess[$k]) + 2 : 3; // assessments + Total + % OR 3 when none
                            }
                            $col_count += 2; // Initial Grade + Quarterly Grade
                        ?>
                            <tr>
                                <td class="text-center" colspan="<?php echo $col_count; ?>">No students found for this class.</td>
                            </tr>
                        <?php 
                        }
                        foreach($students as $k =>$v):
                            $initial = 0;
                        ?>
                            <tr data-student-id="<?php echo $k; ?>" data-class-id="<?php echo $student_classes[$k] ?? ''; ?>"<?php echo (isset($student_status[$k]) && $student_status[$k] === 'Dropped' || $student_status[$k] === 'NoSub' || $student_status[$k] === 'NoExam') ? ' style="background-color: #ffebee;"' : ''; ?>>
                                <td class="py-0 px-1" style="font-weight:bold;<?php echo (isset($student_status[$k]))?>">
                                    <?php echo $v ?>
                                    <?php if(isset($student_status[$k]) && $student_status[$k] === 'Dropped'): ?>
                                        <span class="badge bg-danger" style="font-size: 10px !important;" >!Dropped</span>
                                    <?php elseif(isset($student_status[$k]) && $student_status[$k] === 'NoSub'): ?>  
                                        <span class="badge bg-warning" style="font-size: 10px !important;" >!No Submissions</span> 
                                    <?php elseif(isset($student_status[$k]) && $student_status[$k] === 'NoExam'): ?> 
                                        <span class="badge bg-danger" style="font-size: 10px !important;" >!No Exam</span>
                                    <?php endif; ?>
                                </td>
                                <?php 
                                foreach($components as $component_id => $v): 
                                    if(isset($assess[$component_id])):
                                        $total_score = 0;
                                        $total_hps = 0;
                                        foreach($assess[$component_id] as $row): 
                                            $score = $marks[$k][$row['assessment_id']] ?? 0;
                                            $total_score += floatval($score);
                                            $total_hps += floatval($row['hps']);
                                ?>
                                <td class="py-0 px-1 text-center"><?php echo $score ?></td>
                                <?php 
                                        endforeach; 
                                        $avg = $total_hps > 0 ? ($total_score / $total_hps) * 100 : 0;
                                        $initial += ($avg * $c_perc_arr[$component_id]);
                                ?>
                                <td class="py-0 px-1 text-center bg-dark bg-opacity-50 text-light" width="50px"><?php echo round($total_score, 2) ?></td>
                                <td class="py-0 px-1 text-center bg-dark bg-opacity-50" width="50px"><?php echo round($avg, 1) ?></td>
                                <?php 
                                    else:
                                ?>
                                <td class="py-0 px-1 text-center bg-secondary bg-opacity-25" colspan="3">-</td>
                                <?php
                                    endif;
                                endforeach; 
                                ?>
                                
                                <?php 
                                $transmuted_equivalent = get_equivalent_from_transmutation($initial);
                                $color_class = 'text-dark';
                                if ($transmuted_equivalent === 'DR') {
                                    $color_class = 'text-danger';
                                } else {
                                    $equivalent_float = floatval($transmuted_equivalent);
                                    if ($equivalent_float <= 3.0) {
                                        $color_class = 'text-success';
                                    } elseif ($equivalent_float <= 4.0) {
                                        $color_class = 'text-warning';
                                    } else {
                                        $color_class = 'text-danger';
                                    }
                                }
                                ?>
                                <td class="py-0 px-1 text-center bg-dark bg-opacity-50 text-light" width="50px"><?php echo round($initial, 2) ?></td>
                                <td class="py-0 px-1 text-center bg-dark bg-opacity-50 transmuted-grade <?php echo $color_class;?>" width="50px">
                                    <?php echo $transmuted_equivalent; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <?php $facultyDb->close(); ?>

    <?php elseif (!$selectedFaculty): ?>
    
        <div class='card text-center align-items-center'>
                <i class="fas fa-user-tie fa-3x text-muted mb-3 mt-3 " ></i>
                <h4 class="text-muted">Select a Faculty Member</h4>
                <p class="text-muted">Choose a faculty from the dropdown to view their data.</p>
            </div>
    <?php elseif (!$selectedFaculty['db_path']): ?>
        <div class="card text-center align-items-center">
            <div class="card-body py-5">
                <i class="fas fa-database fa-3x text-warning mb-3 "></i>
                <h4 class="text-warning">Faculty Database Not Found</h4>
                <p class="text-muted">The faculty database file is missing or inaccessible.</p>
            </div>
        </div>
    <?php endif; ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        let classesTableInitialized = false;
        let studentsTableInitialized = false;
        let classesTable = null;
        let studentsTable = null;
        
        // Handle Classes table toggle
        const classesToggle = document.querySelector('[data-bs-target="#classesCardCollapse"]');
        const classesCollapse = document.getElementById('classesCardCollapse');
        
        if (classesToggle && classesCollapse) {
            classesCollapse.addEventListener('show.bs.collapse', function () {
                classesToggle.innerHTML = '<i class="fas fa-eye-slash me-1"></i>Hide Classes';
                
                // Initialize DataTables if not already done
                if (!classesTableInitialized) {
                    classesTable = new DataTable('#classesTable', {
                        responsive: true,
                        paging: true,
                        searching: true,
                        ordering: true,
                        info: true,
                        pageLength: 8,
                        order: [[1, 'asc']], // Sort by Grade & Section by default
                        columnDefs: [
                            { width: '25%', targets: 0 },  // Subject
                            { width: '15%', targets: 1 },  // Grade & Section
                            { width: '20%', targets: 2 },  // Schedule
                            { width: '10%', targets: 3 },  // Students
                            { width: '10%', targets: 4 },  // Assessments
                            { width: '20%', targets: 5 }   // Attendance Rate
                        ]
                    });
                    classesTableInitialized = true;
                }
            });
            
            classesCollapse.addEventListener('hide.bs.collapse', function () {
                classesToggle.innerHTML = '<i class="fas fa-eye me-1"></i>Show Classes';
            });
        }
        
        // Handle Students table toggle
        const studentsToggle = document.querySelector('[data-bs-target="#studentsCardCollapse"]');
        const studentsCollapse = document.getElementById('studentsCardCollapse');
        
        if (studentsToggle && studentsCollapse) {
            studentsCollapse.addEventListener('show.bs.collapse', function () {
                studentsToggle.innerHTML = '<i class="fas fa-eye-slash me-1"></i>Hide Students';
                
                // Initialize DataTables if not already done
                if (!studentsTableInitialized) {
                    studentsTable = new DataTable('#studentsTable', {
                        responsive: true,
                        paging: true,
                        searching: true,
                        ordering: true,
                        info: true,
                        pageLength: 8,
                        order: [[1, 'asc']], // Sort by Name by default,
                        columnDefs: [
                            { width: '15%', targets: 0 },  // Student ID
                            { width: '35%', targets: 1 },  // Name
                            { width: '20%', targets: 2 },  // Grade & Section
                            { width: '20%', targets: 3 },  // Attendance
                            { width: '10%', targets: 4 }   // Status
                        ]
                    });
                    studentsTableInitialized = true;
                }
            });
            
            studentsCollapse.addEventListener('hide.bs.collapse', function () {
                studentsToggle.innerHTML = '<i class="fas fa-eye me-1"></i>Show Students';
            });
        }
        
        // Handle Reports table toggle (no DataTables due to complex header)
        const reportsToggle = document.querySelector('[data-bs-target="#reportsCardCollapse"]');
        const reportsCollapse = document.getElementById('reportsCardCollapse');
        
        if (reportsToggle && reportsCollapse) {
            reportsCollapse.addEventListener('show.bs.collapse', function () {
                reportsToggle.innerHTML = '<i class="fas fa-chart-bar me-1"></i>Hide Reports';
            });
            
            reportsCollapse.addEventListener('hide.bs.collapse', function () {
                reportsToggle.innerHTML = '<i class="fas fa-chart-bar me-1"></i>Show Reports';
            });
        }
        
        // Section filter functionality
        const sectionFilter = document.getElementById('sectionFilter');
        if (sectionFilter) {
            sectionFilter.addEventListener('change', function() {
                const selectedSection = this.value.toLowerCase();
                const studentRows = document.querySelectorAll('#studentsTable tbody tr');
                
                studentRows.forEach(row => {
                    const sectionCell = row.querySelector('td:nth-child(3)');
                    if (sectionCell) {
                        const rowSection = sectionCell.textContent.toLowerCase();
                        if (selectedSection === '' || rowSection.includes(selectedSection)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        }
        
        // Class filter functionality for reports table
        const classFilter = document.getElementById('classFilter');
        if (classFilter) {
            classFilter.addEventListener('change', function() {
                const selectedValue = this.value;
                const urlParams = new URLSearchParams(window.location.search);
                
                if (selectedValue === '') {
                    // Clear both class_id and quarter
                    urlParams.delete('class_id');
                    urlParams.delete('quarter');
                } else {
                    // Parse class_id|quarter format
                    const parts = selectedValue.split('|');
                    if (parts.length >= 1) {
                        urlParams.set('class_id', parts[0]);
                        if (parts.length >= 2 && parts[1] !== '') {
                            urlParams.set('quarter', parts[1]);
                        } else {
                            urlParams.delete('quarter');
                        }
                    }
                }
                window.location.href = '?' + urlParams.toString();
            });
        }
    });
    </script>
</div>
