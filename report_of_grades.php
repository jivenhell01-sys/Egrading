<?php

require_once('DBConnection.php');
require_once('Actions.php');
require 'vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Shared\Converter;

$action = new Actions();
$conn = new DBConnection();

// Function to convert grade to equivalent (1.0-5.5 scale) using transmutation_table
function get_equivalent_from_transmutation(mixed $grade): string {
	global $conn;
	
	if ($grade === 'N/A' || !is_numeric($grade)) {
		return 'N/A';
	}
	
	$grade = round(floatval($grade), 2);
	
	// Get the matching range from the active (CHED/DEPED) transmutation table
	static $trans_system = null;
	if ($trans_system === null) { $trans_system = get_active_transmutation_system($conn); }
	$transmutation_query = $conn->query("SELECT grade, `from`, `to` FROM `transmutation_table` WHERE `system` = '{$trans_system}' ORDER BY `from` ASC");
	$equivalent = 5.5; // Default numeric value if no range matches

	while ($row = $transmutation_query->fetchArray(SQLITE3_ASSOC)) {
		$from = floatval($row['from']);
		$to   = floatval($row['to']);

		if ($grade >= $from && $grade <= $to) {
			$equivalent = floatval($row['grade']);
			break;
		}
	}

	// Debug: Log the transmutation process
	
	// DepEd transmuted grades (60-100 scale) pass through untouched.
	// If equivalent is 5.5, treat as DROPPED (CHED).
	if ($equivalent > 5.5) {
                             return number_format($equivalent, 1);
                         } elseif ($equivalent >= 5.0 && $equivalent <= 5.5  ) {
                            return '5.0';
                        } elseif($equivalent === 5.5){
                            return 'DR';   
                        }

	$result = number_format($equivalent, 1);
	error_log("DEBUG Transmutation: Returning $result for equivalent=$equivalent");
	return $result;
}

// Function to convert transmuted grade to equivalent (1.0-5.0 scale)
function transmuted_to_equivalent(float|int|string $from, float|int|string|null $to = null): string {
    // Handle both old and new function signatures
    if ($to === null) {
        $g = floatval($from);
        if ($g <= 60) return number_format(5.0, 1);
        if ($g >= 99.5) return number_format(1.0, 1);
        $equiv = 5.0 - (($g - 60.0) / 39.5) * 4.0; // maps 60->5.0 down to 99.5->1.0
        if ($equiv < 1.0) $equiv = 1.0;
        if ($equiv > 5.0) $equiv = 5.0;
        return number_format($equiv, 1);
    } else {
        // New implementation using from and to
        $from = floatval($from);
        $to = floatval($to);
        $midpoint = ($from + $to) / 2;
        
        // Handle edge cases
        if ($midpoint <= 50) return 0.0;
        if ($midpoint >= 99.5) return 1.0;
        
        // Calculate equivalent based on the range
        if ($midpoint <= 75) {
            // Linear interpolation between 50 (5.5) and 75 (3.0)
            $equiv = 5.0 - (($midpoint - 50) / (75 - 50)) * 2.5;
        } else {
            // Linear interpolation between 75 (3.0) and 100 (1.0)
            $equiv = 3.0 - (($midpoint - 75) / (99.5 - 75)) * 2.0;
        }
        
        // Ensure the equivalent is within bounds
        return max(1.0, min(5.0, $equiv));
    }
}

// Function to get equivalent grade from transmutation table
function get_equivalent_grade(mixed $grade): string {
    global $conn;

    if ($grade === 'N/A' || !is_numeric($grade)) {
        return 'N/A';
    }

    // DepEd pipeline: the "equivalent" IS the official transmuted grade
    // (60-100 scale) — no second conversion.
    if (get_active_transmutation_system($conn) === 'DEPED') {
        return number_format(floatval($grade), 1);
    }

    $grade = floatval($grade);
    
    // Get the equivalent grade from the active (CHED/DEPED) transmutation table
    // The transmutation table has 'from' and 'to' columns to define score ranges
    static $trans_system2 = null;
    if ($trans_system2 === null) { $trans_system2 = get_active_transmutation_system($conn); }
    $result = $conn->query("SELECT * FROM transmutation_table WHERE `system` = '{$trans_system2}' AND $grade BETWEEN `from` AND `to`");
    
    if ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Calculate the equivalent grade based on the range
        // This is a simplified calculation - adjust based on your actual transmutation logic
        $from = $row['from'];
        $to = $row['to'];
        $range = $to - $from;
        
        // If it's the lowest range (failing grade), return the maximum equivalent (5.0)
        if ($grade <= 55 && $grade >= 50) {
            return '5.0';
        }
        // If it's the highest range (excellent), return the minimum equivalent (1.0)
        elseif ($grade >= 99.5) {
            return '1.0';
        }
        // For other ranges, calculate based on position within the range
        else {
            // This is a simplified linear interpolation - adjust based on your actual scale
            $position = ($grade - $from) / $range;
            $equivalent = 5.0 - ($position * 4.0); // Scale from 5.0 to 1.0
            return number_format(max(1.0, min(5.0, $equivalent)), 1);
        }
    }
    
    // Fallback to direct calculation if no match in the table
    return get_equivalent_from_transmutation($grade);
}

// Raw component score rescaled onto a 55-95 band:
//   (score / total items) * 40 + 55
// A perfect score lands on 95, a zero score on 55.
// $total_items is the sum of the component's assessment HPS for that quarter.
// MUST stay identical to reports_content.php / get_student_row_data.php /
// export_to_word.php or the on-screen grade and the printed/exported grade
// would disagree.
function get_scaled_component_raw($score, $total_items) {
    $total_items = floatval($total_items);
    if ($total_items <= 0) {
        return 0.0;
    }
    $score = floatval($score);
    return (($score / $total_items) * 40) + 55;
}

// Weighted Percentage = scaled raw score x component percentage as a decimal
function get_weighted_percentage($score, $total_items, $component_percentage) {
    $raw = get_scaled_component_raw($score, $total_items);
    return $raw * (floatval($component_percentage) / 100);
}

// Round Half Even (bankers rounding: ties go to the nearest even).
// PHP's round(..., PHP_ROUND_HALF_EVEN) does this natively, but binary-float noise
// ((2.3 + 5.0) / 2 is 3.64999999999999991, not 3.65) would make a naive
// round() miss the half entirely. So scale first, strip the noise, then round
// ties to even, matching the grading policy:
//     2.45 -> 2.4
//     2.55 -> 2.6
//     3.05 -> 3.0
//     3.65 -> 3.6 (e.g. midterm 2.3 + final 5.0 = 7.3 / 2)
// Applied to every computed average so this page, the class record and the
// Word export all show the same number.
function round_half_even($value, $precision = 0) {
    $value = (float)$value;
    if (!is_finite($value)) {
        return $value;
    }
    $precision = (int)$precision;
    $sign = ($value < 0) ? -1 : 1;
    $factor = pow(10, $precision);
    // Scale, then strip binary-float noise (3.65 * 10 can be 36.499999...).
    $scaled = round(abs($value) * $factor, 10);
    // Bankers rounding: ties go to the nearest even integer.
    $out = round($scaled, 0, PHP_ROUND_HALF_EVEN);
    // Re-round at the target precision to strip the scaling artefact.
    return $sign * round($out / $factor, $precision);
}

// Legacy Round Half Up (halves away from zero), kept for non-average uses.
// Averages must use round_half_even() above.
function round_half_up($value, $precision = 0) {
    $value = (float)$value;
    if (!is_finite($value)) {
        return $value;
    }
    $precision = (int)$precision;
    $sign = ($value < 0) ? -1 : 1;
    $factor = pow(10, $precision);
    // Scale, then strip binary-float noise (3.05 * 10 can be 30.499999...)
    $scaled = round(abs($value) * $factor, 10);
    $out = floor($scaled + 0.5);
    // Re-round at the target precision to strip the scaling artefact
    return $sign * round($out / $factor, $precision);
}

// Quarterly Average = mean of the quarters' TRANSMUTED grades, i.e. the values
// actually shown in the quarter columns.
//
// This is deliberately NOT "transmute the mean of the raw initial grades".
// The two methods disagree by up to a full transmutation step, and the
// class record (reports_content.php) has always used this one, so using
// transmute-of-average here made the same student show two different numbers
// on two screens:
//     midterm 2.3 and final 5.0
//       average-of-transmuted -> (2.3 + 5.0)/2 = 3.65 -> 3.6
//       transmute-of-average  -> 85.99+62.90 = 74.45 -> 3.1
// Half-even rounding is applied via round_half_even(), so 3.65 becomes 3.6.
//
// Non-numeric entries ('INC', 'NO GRADE', 'N/A') are skipped - a quarter that
// did not produce a transmuted grade cannot contribute to the mean.
// Returns null when nothing numeric is available, so the caller can fall back.
function get_average_of_transmuted($values, $precision = 1) {
    $nums = array();
    foreach ((array)$values as $v) {
        if (is_numeric($v)) {
            $nums[] = (float)$v;
        }
    }
    if (empty($nums)) {
        return null;
    }
    return round_half_even(array_sum($nums) / count($nums), $precision);
}

// Get all classes for dropdown with all class details
$classes = $conn->query("SELECT c.*, s.name as subject_name
    FROM class_list c 
    JOIN subjects s ON c.subject_id = s.subject_id 
    ORDER BY c.year DESC, c.semester, c.grade, c.section ASC");
$class_options = '';
while($class = $classes->fetchArray(SQLITE3_ASSOC)){
    $class_display = sprintf(
        '%s - %s - %s %s (%s)',
        $class['course_no'],
        $class['grade'] . '-' . $class['section'],
        ucfirst($class['semester']),
        $class['year'],
        $class['subject_name']
    );
    $class_options .= "<option value='".$class['class_id']."' style='font-weight:bold;'>".htmlspecialchars($class_display)."</option>";
}
// Initialize variables
$students = [];
$class_name = '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$show_table = false;
$active_quarters = [];
$class_info = [];
$header_info = ['logo' => ''];
$footer_info = ['footer' => ''];

if(isset($_GET['header']) && $_GET['header'] == 'logo'){
    $header_info['logo'] = $_GET['header'];
}
if(isset($_GET['footer']) && $_GET['footer'] == 'footer'){
    $footer_info['footer'] = $_GET['footer'];
}
// Fetch class information if class_id is provided
if ($class_id > 0) {
    $class_query = $conn->query("SELECT c.*, s.name as subject_name 
                                FROM class_list c 
                                JOIN subjects s ON c.subject_id = s.subject_id 
                                WHERE c.class_id = '{$class_id}'
                                LIMIT 1");
    $class_info = $class_query->fetchArray(SQLITE3_ASSOC);
}

// Define all possible quarters. Quarters are CUSTOM MADE per class, so we
// start from neutral labels and overlay each class's custom names from
// `quarter_list` below.
$all_quarters = [
    1 => ['name' => 'Quarter 1', 'key' => 'prelim', 'class' => 'text-primary'],
    2 => ['name' => 'Quarter 2', 'key' => 'midterm', 'class' => 'text-primary'],
    3 => ['name' => 'Quarter 3', 'key' => 'pre_final', 'class' => 'text-primary'],
    4 => ['name' => 'Quarter 4', 'key' => 'final', 'class' => 'text-success']
];

// Override quarter display names with any custom names defined for the selected class.
if ($class_id > 0) {
    $custom_quarter_names = [];
    $custom_q_qry = $conn->query("SELECT quarter, name FROM quarter_list WHERE class_id = '{$class_id}' ORDER BY quarter ASC");
    if ($custom_q_qry) {
        while ($custom_q_row = $custom_q_qry->fetchArray(SQLITE3_ASSOC)) {
            $custom_quarter_names[(int)$custom_q_row['quarter']] = $custom_q_row['name'];
        }
    }
    foreach ($all_quarters as $q_idx => $q_data) {
        if (isset($custom_quarter_names[$q_idx]) && $custom_quarter_names[$q_idx] !== '') {
            $all_quarters[$q_idx]['name'] = $custom_quarter_names[$q_idx];
        }
    }
}

// Check if form was submitted
if(isset($_GET['class_id']) && $class_id > 0){
    $show_table = true;
    // Quarterly Average = average of the quarters' transmuted grades on BOTH systems.
    // CHED no longer uses transmute-of-average - that made this page disagree
    // with the class record. See get_average_of_transmuted().
    $depEdActiveReport = (get_active_transmutation_system($conn) === 'DEPED');
    // Get class details with subject name
    $class = $conn->querySingle("SELECT c.*, s.name as subject_name 
                                FROM class_list c 
                                JOIN subjects s ON c.subject_id = s.subject_id 
                                WHERE c.class_id = $class_id", true);
    $class_name = $class ? 'Grade '.$class['grade'].' - '.$class['section'].' ('.$class['subject_name'].')' : '';
    
    // Get all quarters that have assessments for this class
    $active_quarters = [];
    $quarters_result = $conn->query("SELECT DISTINCT quarter FROM assessment_list 
                                   WHERE class_id = $class_id ORDER BY quarter");
    
    if ($quarters_result) {
        while ($row = $quarters_result->fetchArray(SQLITE3_ASSOC)) {
            $q_num = intval($row['quarter']);
            if (isset($all_quarters[$q_num])) {
                $active_quarters[$q_num] = $all_quarters[$q_num];
            }
        }
    }
    
    // If no assessments found, default to all quarters
    if (empty($active_quarters)) {
        $active_quarters = $all_quarters;
    }
    

    // Get all components (assessment types) and their percentages for this class
    $components = [];
    $c_perc_arr = []; // Component percentages array
    
    // First, get the subject_id for this class
    $subject_id = $conn->querySingle("SELECT subject_id FROM class_list WHERE class_id = $class_id");
    
    if ($subject_id) {
        // Get all components and their percentages for this subject
        $comp_qry = $conn->query("SELECT gc.component_id, gc.name, csp.percentage 
                                 FROM component_subject_percentage csp 
                                 JOIN grading_components gc ON csp.component_id = gc.component_id 
                                 WHERE csp.subject_id = $subject_id 
                                 ORDER BY csp.component_id ASC");
        
        while($row = $comp_qry->fetchArray(SQLITE3_ASSOC)){
            $components[$row['component_id']] = $row['name'];
            $c_perc_arr[$row['component_id']] = $row['percentage'];
        }
    }
    
    // Get all assessments for this class
    $assess = [];
    $assess_qry = $conn->query("SELECT * FROM assessment_list WHERE class_id = $class_id ORDER BY component_id, assessment_id ASC");
    
    while($row = $assess_qry->fetchArray(SQLITE3_ASSOC)){
        $assess[$row['component_id']][] = $row;
    }
    
    // Check which quarters have complete scores for all components
    $quarters_with_complete_scores = [];
    if (!empty($active_quarters) && !empty($components)) {
        foreach ($active_quarters as $q_num => $q_data) {
            $quarter_has_complete_scores = true;
            
            // Check each component for this quarter
            foreach ($components as $cid => $cname) {
                $component_has_scores = false;
                
                // Check if this component has assessments in this quarter
                if (isset($assess[$cid])) {
                    foreach ($assess[$cid] as $assessment) {
                        if ($assessment['quarter'] == $q_num) {
                            // Check if any student has scores for this assessment
                            $score_check = $conn->querySingle("SELECT COUNT(*) as count FROM mark_list 
                                                              WHERE assessment_id = " . $assessment['assessment_id'] . " 
                                                              AND mark > 0");
                            
                            if ($score_check > 0) {
                                $component_has_scores = true;
                                break;
                            }
                        }
                    }
                }
                
                // If any component has no scores for this quarter, exclude the quarter
                if (!$component_has_scores) {
                    $quarter_has_complete_scores = false;
                    break;
                }
            }
            
            // Only include quarters where all components have scores
            if ($quarter_has_complete_scores) {
                $quarters_with_complete_scores[$q_num] = $q_data;
            }
        }
    }
    
    // Use quarters with complete scores, fallback to all quarters if no complete scores found
    if (!empty($quarters_with_complete_scores)) {
        $active_quarters = $quarters_with_complete_scores;
    } elseif (empty($active_quarters)) {
        $active_quarters = $all_quarters;
    }
    
    // Calculate total HPS (Highest Possible Score) for each component
    $total_hps_arr = [];
    foreach($assess as $cid => $assessments){
        $total_hps = 0;
        foreach($assessments as $k => $v){
            $total_hps += $v['hps'];
        }
        $total_hps_arr[$cid] = $total_hps;
    }
    
    // Get all students in this class with their student numbers
    $students = [];
    $student_qry = $conn->query("SELECT * FROM student_list WHERE class_id = $class_id ORDER BY TRIM(name) COLLATE NOCASE");
    
    while($student = $student_qry->fetchArray(SQLITE3_ASSOC)){
        $students[$student['student_id']] = [
            'name' => $student['name'],
            'student_number' => $student['student_number'],
            'status' => $student['status'] ?? 'Active'
        ];
    }
    
    // Get all student marks
    $marks = [];
    if (!empty($students)) {
        $marks_qry = $conn->query("SELECT * FROM mark_list 
                                  WHERE student_id IN (" . implode(",", array_keys($students)) . ") 
                                  AND assessment_id IN (SELECT assessment_id FROM assessment_list WHERE class_id = $class_id)");
        
        if ($marks_qry) {
            while($row = $marks_qry->fetchArray(SQLITE3_ASSOC)){
                $marks[$row['student_id']][$row['assessment_id']] = $row['mark'];
            }
        } else {
            // Log error if query fails
            error_log("Error fetching marks: " . $conn->lastErrorMsg());
        }
    }
    
    // Calculate grades for each student and quarter
    $student_grades = [];
    foreach($students as $student_id => $student_data) {
        $student_grades[$student_id] = [
            'name' => $student_data['name'],
            'student_number' => $student_data['student_number'],
            'status' => $student_data['status'],
            'grades' => []
        ];
        
        // Calculate for each quarter
        foreach($all_quarters as $q_num => $q_data) {
            $initial = 0;
            $has_grades = false;
            $component_grades = [];
            
            // Calculate grade for each component
            foreach($components as $cid => $cname) {
                $total_score = 0;
                $total_hps = 0;
                $component_has_grades = false;
                $component_has_graded = false;
                
                if(isset($assess[$cid])) {
                    foreach($assess[$cid] as $assessment) {
                        if($assessment['quarter'] == $q_num) {
                            $__mk = $marks[$student_id][$assessment['assessment_id']] ?? null;
                            if ($__mk !== null && trim((string)$__mk) !== '') { $component_has_graded = true; }
                            $mark = $marks[$student_id][$assessment['assessment_id']] ?? 0;
                            $total_score += $mark;
                            // A blank's HPS counts in the denominator too. Must
                            // match reports_content.php / export_to_word.php /
                            // get_student_row_data.php or this page's grade
                            // would differ.
                            $total_hps += $assessment['hps'];
                            $component_has_grades = true;
                            $has_grades = true;
                        }
                    }
                    
                    // Calculate percentage for this component. A component with
                    // no graded work at all contributes 0, exactly as before.
                    if($component_has_grades && $component_has_graded) {
                        // Scaled raw score (score / total items) * 40 + 55, then
                        // weighted by the component percentage as a decimal.
                        $component_grade = get_scaled_component_raw($total_score, $total_hps);
                        $component_weight = $c_perc_arr[$cid] / 100; // Convert percentage to decimal
                        $initial += $component_grade * $component_weight;
                        $component_grades[$cid] = $component_grade;
                    }
                }
            }
            
            // Store the calculated grade
            if ($has_grades) {
                $initial_grade = $initial;
                $transmuted_grade = $initial_grade; // Default to initial grade

                // Apply transmutation if needed (CHED floor only; DepEd table covers 0-100 natively)
                if (!$depEdActiveReport && $initial_grade < 54.50) {
                    $transmuted_grade = 54.50; // Minimum passing grade
                }
                
                $equivalent = get_equivalent_grade($transmuted_grade);
                
                // Store the initial grade in the database
                $check = $conn->query("SELECT id FROM `student_initial_grade` where student_id = '{$student_id}' and class_id = '{$class_id}' and quarter = '{$q_num}'")->fetchArray();
                if($check){
                    $conn->query("UPDATE `student_initial_grade` set initial_grade = '{$initial_grade}' where id = '{$check['id']}'");
                }else{
                    $conn->query("INSERT INTO `student_initial_grade` (student_id, class_id, quarter, initial_grade) VALUES('{$student_id}', '{$class_id}', '{$q_num}', '{$initial_grade}')");
                }
                
                $student_grades[$student_id]['grades'][$q_num] = [
                    'raw' => $initial_grade,
                    'transmuted' => $transmuted_grade,
                    'equivalent' => $equivalent,
                    'components' => $component_grades
                ];
            } else {
                $student_grades[$student_id]['grades'][$q_num] = [
                    'raw' => 'N/A',
                    'transmuted' => 'N/A',
                    'equivalent' => 'N/A',
                    'components' => []
                ];
            }
        }
    }
}
?>
<div class="container-fluid py-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <div class="d-flex align-items-center">
                    <h3 class="card-title mb-0">Report of Grades</h3>
                    
                </div>
                <div class="d-flex gap-2">
                    <a href="./?page=manage_images" class="btn btn-outline-primary btn-sm">
                        <i class="fa fa-images"></i> Manage Images
                    </a>
                </div>
            </div>
            <div class="card-body">
                <form action="" method="GET" class="mb-4" id="reportForm">
                    <input type="hidden" name="page" value="report_of_grades">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="class_id" class="form-label">Class</label>
                            <select name="class_id" id="class_id" class="form-select" style="font-weight: bold;" required>
                                <option value="">Select Class</option>
                                <?php echo $class_options; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6 d-flex align-items-end">
                      
                     <!--       <button class="btn btn-success btn-sm rounded-1 fw-bold me-1" type="button" id="print">
                                <i class="fa fa-print"></i> Print
                            </button> -->
                            <button class="btn btn-primary btn-sm rounded-1 fw-bold me-2" type="button" id="export-docx">
                                <i class="fa fa-file-word"></i> Export to Word
                            </button>
                            <button class="btn btn-warning btn-sm rounded-1 fw-bold me-1" type="button" id="publish-grades" <?php echo $show_table ? '' : 'disabled'; ?>>
                                <i class="fa fa-upload"></i> Publish Grades
                            </button>
                            <button class="btn btn-danger btn-sm rounded-1 fw-bold me-1" type="button" id="publish-all-grades" title="Publish grades for every class">
                                <i class="fa fa-cloud-upload-alt"></i> Publish All
                            </button>

                        
                        <!-- Image Upload Button -->
                        <div class="btn-group ms-1" role="group">
                            <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#imageUploadModal">
                                <i class="fa fa-image"></i> Upload Image
                            </button>
                        </div>
                        
                    </div>
                </form>
                <?php if($show_table): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-bordered w-100" id="gradesTable">
                        <thead class="sticky-top">
                            <tr class="bg-dark text-white">
                                <th class="py-1 px-2 text-center align-middle" rowspan="2" style="min-width: 200px;">Student Name</th>
                                <th class="py-1 px-2 text-center align-middle" rowspan="2" style="min-width: 100px;">Student Number</th>
                                <?php if(empty($active_quarters)): ?>
                                    <th class="py-1 px-2 text-center" colspan="1">No Data Available</th>
                                <?php else: ?>
                                    <?php foreach ($active_quarters as $q_num => $q_data): ?>
                                    <th class="py-1 px-2 text-center" colspan="1"><?php echo $q_data['name']; ?></th>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <th class="py-1 px-2 text-center" rowspan="2" style="min-width: 100px;">Average</th>
                                <th class="py-1 px-2 text-center" rowspan="2" style="min-width: 120px;">Remarks</th>
                            </tr>
                            <tr class="bg-warning bg-opacity-25">
                                <?php if(!empty($active_quarters)): ?>
                                    <?php foreach ($active_quarters as $q_num => $q_data): ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($student_grades)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No students found in this class or no grades available.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($student_grades as $student_id => $student): ?>
                                <tr>
                                    <td class="py-1 px-2" style="font-weight: bold;"><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td class="py-1 px-2 text-center"><?php echo htmlspecialchars($student['student_number']); ?></td>
                                    <?php
                                    $grades = [];
                                    $has_grades = false;
                                    // q_num => the transmuted grade shown in that
                                    // quarter's column. Feeds the Average column
                                    // for BOTH CHED and DepEd via
                                    // get_average_of_transmuted().
                                    $quarter_transmuted = [];
                                    // Set when any quarter column below renders INC.
                                    $any_quarter_inc = false;
                                    $has_assessments = true; // Assume assessments exist since we're storing grades
                                    
                                    foreach ($active_quarters as $q_num => $q_data):
                                        // Get the initial grade from the database
                                        $initial_grade = $conn->querySingle("SELECT initial_grade FROM student_initial_grade 
                                            WHERE student_id = '{$student_id}' 
                                            AND class_id = '{$class_id}'
                                            AND quarter = '{$q_num}'", true);
                                        
                                        $grade_value = $initial_grade ? $initial_grade['initial_grade'] : 0;
                                        $equivalent = 'N/A';
                                        $grade_class = 'text-muted';
                                        // INC check: a BLANK (missing) mark under a component with
                                        // percentage >= 30% plus a raw initial in [40, 55.49]
                                        // => INC. An explicitly encoded 0 never triggers INC
                                        // on its own.
                                        $has_blank_component = false;
                                        foreach($components as $hz_cid => $hz_cname){
                                            if(isset($assess[$hz_cid])){
                                                $hz_q_score=0; $hz_q_hps=0; $hz_has_assess=false; $hz_q_blank=false;
                                                foreach($assess[$hz_cid] as $hz_ass){
                                                    if($hz_ass['quarter']==$q_num){
                                                        $hz_has_assess=true;
                                                        $hz_mark = $marks[$student_id][$hz_ass['assessment_id']] ?? null;
                                                        // A missing (or empty) mark row is a BLANK, not a zero.
                                                        // Only blanks feed the INC rule below: an explicitly
                                                        // encoded 0 is failed work (5.0), not incomplete work.
                                                        if ($hz_mark === null || trim((string)$hz_mark) === '') { $hz_q_blank = true; }
                                                        $hz_q_score += $hz_mark ?? 0;
                                                        $hz_q_hps += $hz_ass['hps'];
                                                    }
                                                }
                                                if($hz_has_assess){
                                                    $hz_comp_grade = get_scaled_component_raw($hz_q_score, $hz_q_hps);
                                                    $hz_weighted = get_weighted_percentage($hz_q_score, $hz_q_hps, $c_perc_arr[$hz_cid] ?? 0);
                                                    if($hz_q_blank && ($c_perc_arr[$hz_cid] ??0) >=30){ $has_blank_component=true; break; }
                                                }
                                            }
                                        }
                                        
                                        // Check if student is dropped - if so, show NO GRADE and set grade to 0
                                        if (isset($student['status']) && $student['status'] === 'Dropped') {
                                            $grade_value = 0;
                                            $equivalent = 'NO GRADE';
                                            $grade_class = 'text-muted';
                                        } elseif (!$depEdActiveReport && $grade_value >= 40 && $grade_value <= 55.49 && $has_blank_component) {
                                            $equivalent = 'INC';
                                            $grade_class = 'text-warning fw-bold';
                                        } elseif (!$depEdActiveReport && $grade_value >=0 && $grade_value < 55.49) {
                                            $equivalent = '5.0';
                                            $grade_class = 'text-danger fw-bold';
                                        } elseif ($grade_value > 0) {
                                            // Get the equivalent grade from the transmutation table
                                            $equivalent = get_equivalent_from_transmutation($grade_value);
                                            $equivalent_num = is_numeric($equivalent) ? (float)$equivalent : 0;

                                            // Set the appropriate color class based on the equivalent grade
                                            $grade_class = grade_color_class($equivalent_num, $depEdActiveReport ? 'DEPED' : 'CHED');
                                            if ($depEdActiveReport ? ($equivalent_num >= 75) : ($equivalent_num >= 1.0 && $equivalent_num <= 3.0)) {
                                                $has_grades = true;
                                                $grades[] = $equivalent_num;
                                            }
                                        } else {
                                            $equivalent = 'NO GRADE';
                                            $grade_class = 'text-muted';
                                        }
                                        // Collect the transmuted value actually shown in
                                        // this quarter's column. Done for EVERY grading
                                        // system (not just DepEd) because the Average
                                        // column now averages these values instead of
                                        // transmuting the mean of the raw initial grades.
                                        // Non-numeric cells ('INC', 'NO GRADE') are
                                        // skipped by get_average_of_transmuted().
                                        if (is_numeric($equivalent)) {
                                            $quarter_transmuted[$q_num] = (float)$equivalent;
                                        }
                                        // If any quarter column shows INC, the Average
                                        // must be INC too - a student cannot be handed a
                                        // passing average built only from the quarters
                                        // they did pass. This is the rule the class record
                                        // (reports_content.php) has always applied; without
                                        // it this page printed e.g. 1.7 for a student the
                                        // class record marked INC.
                                        if ($equivalent === 'INC') {
                                            $any_quarter_inc = true;
                                        }
                                    ?>
                                    <td class="py-1 px-2 text-center fw-bold <?php echo $grade_class; ?>" title="Initial Grade: <?php echo $grade_value > 0 ? number_format($grade_value, 2) : 'N/A'; ?>">
                                        <?php echo $equivalent; ?>
                                    </td>
                                    <?php 
                                    endforeach; 
                                    
                                    // Calculate average of initial grades
                                    $average = 'N/A';
                                    $average_class = 'text-muted';
                                    $final_grade = 0.0;
                                    $has_incomplete = false;
                                    $has_dropped = false;
                                    $incomplete = 0;
                                    $total_initial = 0;
                                    $valid_quarters = 0;
                                    $all_quarters_have_grades = true;
                                    $average_num = 0; // Initialize to prevent undefined variable error
                                    
                                    // Check if student is dropped first
                                    if (isset($student['status']) && $student['status'] === 'Dropped') {
                                        $average = 'NO GRADE';
                                        $average_class = 'text-muted';
                                        $final_grade = 0;
                                    } else {
                                        // Check each quarter for grades and calculate average
                                        foreach ($active_quarters as $q_num => $q_data) {
                                            // Get the initial grade from the database
                                            $initial_grade = $conn->querySingle("SELECT initial_grade FROM student_initial_grade 
                                                WHERE student_id = '{$student_id}' 
                                                AND class_id = '{$class_id}'
                                                AND quarter = '{$q_num}'", true);
                                            
                                            if ($initial_grade && $initial_grade['initial_grade'] > 0) {
                                                // Use the actual initial grade for average calculation
                                                $grade_value = $initial_grade['initial_grade'];
                                                $total_initial += $grade_value;
                                                $valid_quarters++;
                                            } else {
                                                $all_quarters_have_grades = false;
                                            }
                                        }
                                    }
                                    
                                    // Calculate average if we have valid grades (only for non-dropped students)
                                    // A quarter showing INC forces the Average to INC
                                    // as well, exactly like reports_content.php:
                                    // averaging only the passed quarters would hand a
                                    // student a passing average they did not earn.
                                    // INC also outranks every numeric average below.
                                    if (!(isset($student['status']) && $student['status'] === 'Dropped')) {
                                        if ($any_quarter_inc) {
                                            $average = 'INC';
                                            $average_class = 'text-warning fw-bold';
                                            $final_grade = 0;
                                            $has_dropped = false;
                                            $has_incomplete = false;
                                        }
                                    }
                                    if (!(isset($student['status']) && $student['status'] === 'Dropped') && !$any_quarter_inc) {
                                        // Average is ALWAYS the literal mean of the quarters'
                                        // displayed transmuted grades. No raw-grade gates: a
                                        // weak quarter already drags the mean down through its
                                        // own transmuted grade (e.g. 1.8 + 5.0 -> 3.4).
                                        // INC quarters are handled above ($any_quarter_inc
                                        // forces INC) and Dropped students never reach here.
                                        if ($valid_quarters > 1) {
                                            // Only calculate average if we have more than one quarter
                                            // Half-even rounding at 2dp - the same precision
                                            // get_equivalent_from_transmutation() applies, so the
                                            // transmutation lookup can't land on a different value
                                            // than the displayed average.
                                            $average_initial = round_half_even($total_initial / $valid_quarters, 2);
                                            // No average-level zero-component check: the Average is
                                            // the literal mean below even when a 30%+ component
                                            // totals zero somewhere. That weakness already shows
                                            // in its quarter column (5.0) and drags the mean down.
                                            // Only a quarter column showing INC forces INC (above).
                                                // Average the quarters' TRANSMUTED grades (the values shown in
                                                // the quarter columns) instead of transmuting the mean of
                                                // the raw initial grades. Matches reports_content.php and
                                                // export_to_word.php; half-even rounding applied inside the
                                                // helper. $average_initial is still used for the
                                                // no-numeric-quarters fallback and the tooltip below.
                                                $average_num = get_average_of_transmuted($quarter_transmuted);
                                                if ($average_num === null) {
                                                    // No quarter produced a numeric transmuted grade - fall back
                                                    // to transmuting the raw average (previous behaviour).
                                                    $average_fallback = get_equivalent_from_transmutation($average_initial);
                                                    $average_num = is_numeric($average_fallback) ? (float)$average_fallback : 0;
                                                }

                                                    $average = number_format($average_num, 1, '.', '');

                                                // Set the appropriate color class
                                                if ($average_num >= 1.0 && $average_num <= 3.0) {
                                                    $average_class = 'text-success fw-bold';
                                                } elseif ($average_num > 3.0 && $average_num <= 3.5) {
                                                    $average_class = 'text-warning fw-bold';
                                                } elseif ($average_num > 3.5 || $average_num < 1.0) {
                                                    $average_class = 'text-danger fw-bold';
                                                    $has_dropped = $average_num < 1.0 || $average_num > 5.0;
                                                }

                                                // Set final grade for status determination
                                                $final_grade = $average_num;
                                        } elseif ($valid_quarters === 1) {
                                            // If only one quarter has grades, use that quarter's grade as the average
                                            $average_initial = $total_initial; // Since there's only one quarter, total_initial is the grade
                                            // No average-level zero-component check here either:
                                            // same literal-mean rule as the multi-quarter branch.
                                                // Same method as the multi-quarter branch above: average the
                                                // quarters' TRANSMUTED grades. With one graded quarter that is
                                                // simply that quarter's own transmuted grade.
                                                $average_num = get_average_of_transmuted($quarter_transmuted);
                                                if ($average_num === null) {
                                                    // Fall back to transmuting the raw grade (previous behaviour).
                                                    $average_fallback = get_equivalent_from_transmutation($average_initial);
                                                    $average_num = is_numeric($average_fallback) ? (float)$average_fallback : 0;
                                                }

                                                    $average = number_format($average_num, 1, '.', '');

                                                // Set the appropriate color class
                                                if ($average_num >= 1.0 && $average_num <= 3.0) {
                                                    $average_class = 'text-success fw-bold';
                                                } elseif ($average_num > 3.0 && $average_num <= 3.5) {
                                                    $average_class = 'text-warning fw-bold';
                                                } elseif ($average_num > 3.5 || $average_num < 1.0) {
                                                    $average_class = 'text-danger fw-bold';
                                                    $has_dropped = $average_num < 1.0 || $average_num > 5.0;
                                                }

                                                // Set final grade for status determination
                                                $final_grade = $average_num;
                                                $has_incomplete = false;
                                        } elseif($valid_quarters === 0 && $average === 'N/A') {
                                            // No valid grades at all
                                            $has_incomplete = true;
                                            $incomplete = $has_incomplete;
                                        }
                                    }
                                    // Quarterly Average = average of the quarters' transmuted grades.
                                    // The CHED branch above now uses this same method, so
                                    // this block only differs by using the DepEd colour
                                    // scale; the arithmetic is deliberately shared so the
                                    // two systems cannot drift apart again.
                                    if ($depEdActiveReport && !(isset($student['status']) && $student['status'] === 'Dropped')) {
                                        $average_num = get_average_of_transmuted($quarter_transmuted);
                                        if ($average_num !== null) {
                                            // Half-even rounding inside the helper:
                                            // e.g. 2.3 + 5.0 = 3.65 -> 3.6.
                                            $average = number_format($average_num, 1, '.', '');
                                            $average_class = grade_color_class($average_num, 'DEPED');
                                            $final_grade = $average_num;
                                            $has_dropped = false;
                                            $has_incomplete = false;
                                            $incomplete = 0;
                                        } else {
                                            $average = 'N/A';
                                            $average_class = 'text-muted';
                                            $final_grade = 0;
                                            $has_dropped = false;
                                            $has_incomplete = false;
                                            $incomplete = 0;
                                        }
                                    }
                                    ?>
                                    <td class="py-1 px-2 text-center fw-bold <?php echo $average_class; ?>" title="Average Grade: <?php echo (isset($average_initial) && $average_initial > 0) ? number_format(round_half_even($average_initial, 2), 2) : 'N/A'; ?>">
                                        <?php echo $average; ?>
                                    </td>
                                    <td class="py-1 px-2 text-center">
                                        <?php 
                                        // Check if student is dropped first
                                        if (isset($student['status']) && $student['status'] === 'Dropped') {
                                            echo '<span class="text-danger fw-bold">DROPPED</span>';
                                        } elseif ($incomplete) {
                                            echo '<span class="text-danger fw-bold">DROPPED</span>';
                                        } elseif ($average === 'DR') {
                                            echo '<span class="text-danger fw-bold">DROPPED</span>';
                                        } elseif ($average === 'INC') {
                                            // Wording differs from the Word export on purpose
                                            // (it prints INCOMPLETE here). Only the Average
                                            // column is required to match; see export_to_word.php.
                                            echo '<span class="text-warning fw-bold">INC</span>';
                                        } elseif ($average === 'N/A') {
                                            echo '<span class="text-danger fw-bold">INC</span>';
                                        } elseif ($average !== 'N/A' && $average !== '0.0') {
                                            $avg_float = floatval($average);
                                            if ($avg_float == 0) {
                                                echo '<span class="text-muted">NO GRADE</span>';
                                            } else {
                                                $rem_text = remarks_for_grade($avg_float, $depEdActiveReport ? 'DEPED' : 'CHED');
                                                $rem_cls = $rem_text === 'PASSED' ? 'text-success fw-bold' : ($rem_text === 'CONDITIONAL' ? 'text-warning fw-bold' : ($rem_text === 'NO GRADE' ? 'text-muted' : 'text-danger fw-bold'));
                                                echo '<span class="' . $rem_cls . '">' . $rem_text . '</span>';
                                            }
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="alert alert-info">Please select a class and click Generate to view the report.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-12 mt-5">
                    <div class="row">
                        <?php
                        // Define signatory types and their display titles
                        $signatoryTypes = [
                            'certified' => 'CERTIFIED TRUE AND CORRECT',
                            'checked' => 'CHECKED',
                            'noted' => 'NOTED',
                            'received' => 'RECEIVED BY'
                        ];
                        
                        // Initialize signatories array
                        $signatories = [];
                        
                        // First, check if the signatories table has the type column
                        $hasTypeColumn = false;
                        try {
                            $result = $conn->query("PRAGMA table_info(signatories)");
                            while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
                                if ($col['name'] === 'type') {
                                    $hasTypeColumn = true;
                                    break;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Error checking table structure: " . $e->getMessage());
                        }
                        
                        // Fetch signatories based on whether type column exists
                        foreach ($signatoryTypes as $type => $title) {
                            $signatories[$type] = false;
                            try {
                                if ($hasTypeColumn) {
                                    // New query with type column
                                    $result = $conn->query("
                                        SELECT * FROM `signatories` 
                                        WHERE `type` = '" . SQLite3::escapeString($type) . "' 
                                        ORDER BY `date_created` DESC 
                                        LIMIT 1");
                                } else {
                                    // Fallback for old schema (just get the first signatory)
                                    $result = $conn->query("
                                        SELECT * FROM `signatories` 
                                        ORDER BY `date_created` DESC 
                                        LIMIT 1");
                                }
                                
                                if ($result) {
                                    $signatories[$type] = $result->fetchArray(SQLITE3_ASSOC);
                                }
                            } catch (Exception $e) {
                                error_log("Error fetching signatory ($type): " . $e->getMessage());
                            }
                        }
                        
                        // Output each signatory section
                        foreach ($signatoryTypes as $type => $title):
                            $signatory = $signatories[$type];
                        ?>
                        <div class="col-md-4 text-center">
                            <div class="pt-4">
                                <h6 class="mb-4"><?php echo $title; ?>:</h6>
                                <div class="mb-4" style="min-height: 80px;">
                                    <div class="mb-1" style="border-bottom: 1px solid #000; width: 90%; height: 50px; margin: 0 auto;">
                                        <?php if($signatory): ?>
                                            <div style="height: 100%; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px;">
                                                <?php echo htmlspecialchars($signatory['fullname']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2 fw-bold"><?php echo $signatory ? htmlspecialchars($signatory['designation']) : 'Designation' ?></div>
                                    <div class="text-muted small">Signature Over Printed Name</div>
                                    <?php if ($type === 'received'): ?>
                                    <div class="mt-2" style="border-bottom: 1px solid #000; width: 200px; height: 20px; margin: 10px auto 0;">
                                        <br>
                                        <div class="text-muted small" style="margin-top: -15px;"><br>Date</div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
    </div>

    <script>
        
        $(document).ready(function(){
            // Function to handle filter action
            function applyFilter() {
                var class_id = $('#class_id').val();
                if(!class_id) {
                    return false;
                }
                window.location.href = 'index.php?page=reports&tab=report_of_grades&class_id=' + class_id;
            }
            
            // Filter button click handler
            $('#filter').click(applyFilter);
            
            // Auto-filter when class is selected
            $('#class_id').change(applyFilter);
            
            // Print button click handler
            $('#print').click(function(){
                // Create a new window for printing
                var printWindow = window.open('', '_blank');
                
                // Get the current date
                var currentDate = new Date().toLocaleDateString();
                
                // Get the report title and class info
                var title = "Report of Grades";
                var classInfo = $('#class_id option:selected').text();
                
                // Get the table HTML and fix any issues with components
                var table = $('#gradesTable').clone();
                table.removeClass('dataTable no-footer').addClass('table-print').css('width', '100%');
                
                // Create print content with proper styling
                var printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>
                        @page {
                            size: A4 portrait;
                            margin: 100px 10px 100px 10px !important;
                            padding: 0;
                        }
                        body {
                            margin: 0 !important;
                            padding: 0 !important;
                            font-family: Arial, sans-serif;
                            font-size: 10pt;
                            color: #000;
                            background: #fff;
                        }
                        .header-info {
                        margin-top: 3cm;
                            margin-bottom: 15px;
                            page-break-after: avoid;
                        }
                        .header-info h3 {
                            text-align: center;
                            margin: 5px 0;
                            font-size: 12pt;
                        }
                        .header-info div {
                            margin: 2px 0;
                            font-size: 9pt;
                        }
                        .table-print {
                            width: 100% !important;
                            max-width: 100% !important;
                            margin: 0 auto !important;
                            font-size: 9pt !important;
                            border-collapse: collapse !important;
                            border: 1px solid #000 !important;
                            page-break-inside: auto;
                            table-layout: fixed !important;
                            word-wrap: break-word;
                            box-sizing: border-box !important;
                        }
                        .table-print th, .table-print td {
                            padding: 2px 3px !important;
                            line-height: 1.1;
                            white-space: normal !important;
                            word-wrap: break-word;
                            overflow-wrap: break-word;
                            border: 1px solid #000 !important;
                            page-break-inside: avoid;
                            vertical-align: middle !important;
                            box-sizing: border-box;
                        }
                        .table-print th {
                            font-size: 10pt !important;
                            background-color: #f0f0f0 !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                            font-weight: bold;
                            color: #000 !important;
                        }
                        .table-print .bg-dark {
                            background-color: #333 !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                            color: #fff !important;
                        }
                        .table-print .text-center {
                            text-align: center !important;
                        }
                        .table-print .text-start {
                            text-align: left !important;
                        }
                        .table-print .fw-bold {
                            font-weight: bold !important;
                        }
                        @media print {
                            @page {
                                margin: .5cm 1cm 1cm 1cm !important;
                                padding: 0 !important;
                                size: auto;
                            }
                            html, body {
                                margin: 0 !important;
                                padding: 0 !important;
                            }
                            body {
                                margin: 0;
                                padding: 10px;
                                font-size: 8pt;
                            }
                            .table-print {
                                font-size: 9pt !important;
                                width: 100% !important;
                                max-width: 100% !important;
                                margin: 0 auto !important;
                                transform: scale(1) !important;
                                transform-origin: 0 0 !important;
                                table-layout: fixed !important;
                            }
                        }
                    </style>
                </head>
                <body>
                <table style="width: 100%;">
                <tr>
                    <td style = "text-align: left; position: fixed; left: 0;"> <img src="uploads/EssuLogo.jpg" style="width: 300px; height: 80px;" /></td>
                    <td style = "text-align: right; position: fixed; right: 0;"> <img src="uploads/Bagong Pilipinas.png" style="width: 100px; height: 80px;" /></td>
                </tr>
                
                </table>
                    <div class="header-info">
                        <h3>${title}</h3>
                        <i style="font-size: 10pt; color: #6c757d; text-align: center; display: flex; justify-content: center; font-style: italic; font-family: Arial, sans-serif; margin-bottom: 10px;">
                                    <?php echo $class_info['semester'] ?? 'N/A'; ?>, School Year: <?php echo $class_info['year'] ?? 'N/A'; ?>
                                </i>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 20px; gap: 20px;">
                            <div style="flex: 1;">
                                
                                <table class="class-info-table" style="width: 100%; margin-top:28px; border-width: 2px; border-color: rgb(6, 6, 6);">
                                    <tr>
                                        <td style="width: 40%; padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><strong>Course Code:</strong>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><?php echo $class_info['course_no'] ?? 'N/A'; ?></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><strong>Subject:</strong></td>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><?php echo $class_info['subject_name'] ?? 'N/A'; ?></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><strong>Course & Section:</strong></td>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;">
                                            <?php 
                                            if (isset($class_info['grade']) && isset($class_info['section'])) {
                                                echo htmlspecialchars($class_info['grade'] . ' - ' . $class_info['section']);
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><strong>Schedule:</strong></td>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><?php echo htmlspecialchars($class_info['schedule'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><strong>Units:</strong></td>
                                        <td style="padding: 2.5px 5px; border: 1px solid rgb(0, 0, 0); font-size: 8pt;"><?php echo htmlspecialchars($class_info['unit'] ?? 'N/A'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div style="flex: 1; margin-left: 4cm;">
                                <table style="width: 100%; border-collapse: collapse; margin-top: 28px;">
                                    <tr>
                                        <td style="padding: 5px 10px; border: 1px solid #dee2e6; font-size: 7pt; text-align: center;" colspan="3">
                                            <strong>GRADING SYSTEM</strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 7pt; text-align: center;">
                                            <strong>Actual Rating</strong>
                                        </td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 7pt; text-align: center;">
                                            <strong>Equivalent Rating</strong>
                                        </td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 7pt; text-align: center;">
                                            <strong>Adjectival Rating</strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">95-100</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">1.0</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Oustanding</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">90-94</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">1.5-1.1</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Excellent</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">85-89</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">2.0-1.6</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Very Good</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">80-84</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">2.5-2.1</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Good</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">75-79</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">3.0-2.6</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Fair</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">70-74</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">3.5-3.1</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Conditional</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">55-69</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">5.0-3.6</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Failed</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">INC</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">INC</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Incomplete</td>
                                    </tr>
                                     <tr>
                                    </tr>
                                     <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">WP</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">WP</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">Withdrawn w/ Permission</td>
                                    </tr>
                                     <tr>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">IP</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">IP</td>
                                        <td style="padding: 2.5px 5px; border: 1px solid #dee2e6; font-size: 6pt; text-align: center;">In Progress</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 10px;">
                            <strong>Date Generated:</strong> ${currentDate}
                        </div>
                    </div>
                    ${table[0].outerHTML}

                    <!-- Signatory Section -->
                    <div class="row mt-5" style="page-break-inside: avoid; margin-top: 30px; padding: 15px 0; border-top: 1px solid #eee;">
                        <?php
                        // Define signatory types and their display titles
                        $signatoryTypes = [
                            'certified' => 'CERTIFIED TRUE AND CORRECT',
                            'checked' => 'CHECKED BY',
                            'noted' => 'NOTED BY',
                            'received' => 'RECEIVED BY'
                        ];  
                        
                        // Get signatories from database or use default values
                        $signatories = [];
                        $signatoryQuery = $conn->query("SELECT * FROM signatories ORDER BY type");
                        while($row = $signatoryQuery->fetchArray(SQLITE3_ASSOC)) {
                            $signatories[$row['type']] = $row;
                        }
                        
                        // Output each signatory column
                        foreach ($signatoryTypes as $type => $title) {
                            $signatory = $signatories[$type] ?? ['fullname' => '', 'designation' => 'Designation'];
                            ?>
                            <div class="col-md-4 text-center" style="float: left; width: 33.33%; margin: 10px 0; padding: 0 10px; box-sizing: border-box;">
                                <div class="pt-4">
                                    <h6 class="mb-3" style="font-size: 7pt; margin: 10px 0 3px 0; font-weight: bold;"><?php echo $title; ?>:</h6>
                                    <div class="mb-4" style="min-height: 80px;">
                                        <div class="mb-0" style="border-bottom: 1px solid #000; width: 70%; height: 50px; margin: 0 auto;">
                                            <?php if (!empty($signatory['fullname'])): ?>
                                                <div style="height: 100%; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px;">
                                                    <?php echo htmlspecialchars($signatory['fullname']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-center fw-bold" style="font-weight: bold;font-size: 8pt; margin-top: 0px; min-height: 20px; justify-content: center; display: flex; align-items: flex-end;">
                                            <?php echo htmlspecialchars($signatory['designation']); ?>
                                        </div>
                                        <div class="text-muted small" style="font-size: 7pt; color: #666; justify-content: center; display: flex; align-items: flex-end;">Signature Over Printed Name</div>
                                        <?php if ($type === 'received'): ?>
                                            <div class="mt-2" style="border-bottom: 1px solid #000; width: 70px; height: 15px; margin: 7px auto 0;">
                                            </div>
                                            <div class="text-muted small" style="margin-top: 5px; font-size: 8pt; color: #666; justify-content: center; display: flex; align-items: flex-end;">Date</div>

                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                        <div style="clear: both;"></div>
                    </div>
                    
                    <script>
                        // Auto-print when the window loads
                        window.onload = function() {
                            setTimeout(function() {
                                window.print();
                                // Close window after printing
                                setTimeout(function() {
                                    window.close();
                                }, 100);
                            }, 100);
                        };
                    <\/script>
                    <table style="width: 100%;">
                <tr>
                    <td style = "text-align: bottom-left; position: fixed; bottom: 0;"> <img src="uploads/EssuAcad.png" style="width: 230px; height: 50px;" /></td>
                </tr>
                </table>
                </body>
                </html>`;
                
                // Write the print content to the new window
                printWindow.document.open();
                printWindow.document.write(printContent);
                printWindow.document.close();
            });
            
            // Set the selected class in the dropdown
            $('#class_id').val('<?php echo $class_id; ?>');
            
            
            // DOCX Export button click handler
            $('#export-docx').click(function(){
                var class_id = $('#class_id').val();
                if(!class_id) {
                    alert('Please select a class first.');
                    return false;
                }
                // Redirect to export_to_word.php with the class_id
                window.location.href = 'export_to_word.php?class_id=' + class_id + '&export=grades';
            });
            
            // Basic table functionality
            $('#gradesTable').addClass('table-hover table-bordered');
            
        });
    </script>
    
    <!-- Image Upload Modal -->
    <div class="modal fade" id="imageUploadModal" tabindex="-1" aria-labelledby="imageUploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageUploadModalLabel">Upload Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="imageUploadForm">
                        <div class="mb-3">
                            <label for="imageType" class="form-label">Image Type</label>
                            <select class="form-select" id="imageType" required>
                                <option value="">Select image type...</option>
                                <option value="header">Header</option>
                                <option value="footer">Footer</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="imagePosition" class="form-label">Position</label>
                            <select class="form-select" id="imagePosition" required>
                                <option value="">Select position...</option>
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Image Dimensions</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="imageWidth" class="form-label">Width (px)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="imageWidth" min="10" max="1000" value="240" placeholder="Width">
                                        <span class="input-group-text">px</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="imageHeight" class="form-label">Height (px)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="imageHeight" min="10" max="1000" value="65" placeholder="Height">
                                        <span class="input-group-text">px</span>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted">Set custom dimensions for the uploaded image (10-1000px)</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="maintainAspectRatio" checked>
                                <label class="form-check-label" for="maintainAspectRatio">
                                    Maintain aspect ratio
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="imageDropZone" class="form-label">Upload Image</label>
                            <div id="imageDropZone" class="border-2 border-dashed border-secondary rounded p-4 text-center" style="min-height: 200px; cursor: pointer;">
                                <div id="dropZoneContent">
                                    <i class="fa fa-cloud-upload fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Drag & drop an image here or click to browse</p>
                                    <small class="text-muted">Supported formats: JPG, PNG, GIF (Max 5MB)</small>
                                </div>
                                <div id="imagePreview" class="d-none">
                                    <img id="previewImg" class="img-fluid mb-3" style="max-height: 300px;">
                                </div>
                            </div>
                            <input type="file" id="imageInput" class="d-none" accept="image/*">
                        </div>
                        
                        <div class="mb-3 d-none" id="rotateControls">
                            <label class="form-label">Rotate Image</label>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="rotateLeftButton">
                                    <i class="fa fa-rotate-left"></i> Rotate Left
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="rotateRightButton">
                                    <i class="fa fa-rotate-right"></i> Rotate Right
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" id="resetRotateButton">
                                    <i class="fa fa-undo"></i> Reset
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1">Current rotation: <span id="rotationDisplay">0°</span></small>
                        </div>
                        
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveImageButton">Save Image</button>
                </div>
            </div>
        </div>
    </div>
    
    
    <script>
        $(document).ready(function() {
            const dropZone = $('#imageDropZone');
            const imageInput = $('#imageInput');
            const imagePreview = $('#imagePreview');
            const dropZoneContent = $('#dropZoneContent');
            const widthInput = $('#imageWidth');
            const heightInput = $('#imageHeight');
            const aspectRatioCheckbox = $('#maintainAspectRatio');
            const rotateControls = $('#rotateControls');
            const rotationDisplay = $('#rotationDisplay');
            
            // Rotation variables
            let currentRotation = 0;
            let originalAspectRatio = 1;
            
            // Aspect ratio management
            function updateAspectRatio() {
                if (aspectRatioCheckbox.is(':checked') && originalAspectRatio > 0) {
                    widthInput.on('input', function() {
                        const width = parseInt($(this).val());
                        if (width > 0) {
                            heightInput.val(Math.round(width / originalAspectRatio));
                        }
                    });
                    
                    heightInput.on('input', function() {
                        const height = parseInt($(this).val());
                        if (height > 0) {
                            widthInput.val(Math.round(height * originalAspectRatio));
                        }
                    });
                } else {
                    widthInput.off('input');
                    heightInput.off('input');
                }
            }
            
            aspectRatioCheckbox.on('change', updateAspectRatio);
            
            // Click to upload
            dropZone.on('click', function() {
                imageInput.click();
            });
            
            // Drag and drop events
            dropZone.on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('border-primary bg-light');
            });
            
            dropZone.on('dragleave', function(e) {
                e.preventDefault();
                $(this).removeClass('border-primary bg-light');
            });
            
            dropZone.on('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('border-primary bg-light');
                
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    handleImageFile(files[0]);
                }
            });
            
            // File input change
            imageInput.on('change', function() {
                const file = this.files[0];
                if (file) {
                    handleImageFile(file);
                }
            });
            
            // Handle image file
            function handleImageFile(file) {
                // Validate file type
                if (!file.type.startsWith('image/')) {
                    alert('Please select an image file.');
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('Image size must be less than 5MB.');
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    displayImage(e.target.result);
                };
                reader.readAsDataURL(file);
            }
            
            // Display image without cropper
            function displayImage(imageSrc) {
                dropZoneContent.addClass('d-none');
                imagePreview.removeClass('d-none');
                rotateControls.removeClass('d-none');
                
                // Reset rotation
                currentRotation = 0;
                updateRotationDisplay();
                applyRotation();
                
                $('#previewImg').attr('src', imageSrc);
                
                // Get original image dimensions for aspect ratio
                const img = new Image();
                img.onload = function() {
                    originalAspectRatio = this.width / this.height;
                    
                    // Set default dimensions based on image type and position
                    const imageType = $('#imageType').val();
                    const imagePosition = $('#imagePosition').val();
                    
                    if (imageType === 'header') {
                        if (imagePosition === 'left') {
                            widthInput.val(240);
                            heightInput.val(65);
                        } else if (imagePosition === 'right') {
                            widthInput.val(70);
                            heightInput.val(70);
                        } else {
                            widthInput.val(200);
                            heightInput.val(60);
                        }
                    } else if (imageType === 'footer') {
                        widthInput.val(250);
                        heightInput.val(45);
                    }
                    
                    // Update aspect ratio listeners
                    updateAspectRatio();
                };
                img.src = imageSrc;
            }
            
            // Rotation functions
            function updateRotationDisplay() {
                rotationDisplay.text(currentRotation + '°');
            }
            
            function applyRotation() {
                $('#previewImg').css('transform', `rotate(${currentRotation}deg)`);
            }
            
            function rotateImage(direction) {
                if (direction === 'left') {
                    currentRotation -= 90;
                } else if (direction === 'right') {
                    currentRotation += 90;
                }
                
                // Keep rotation within -360 to 360 range
                if (currentRotation > 360) currentRotation -= 360;
                if (currentRotation < -360) currentRotation += 360;
                
                updateRotationDisplay();
                applyRotation();
            }
            
            // Rotation button events
            $('#rotateLeftButton').on('click', function() {
                rotateImage('left');
            });
            
            $('#rotateRightButton').on('click', function() {
                rotateImage('right');
            });
            
            $('#resetRotateButton').on('click', function() {
                currentRotation = 0;
                updateRotationDisplay();
                applyRotation();
            });
            
            
            // Save image button
            $('#saveImageButton').on('click', function() {
                const imageType = $('#imageType').val();
                const imagePosition = $('#imagePosition').val();
                const imageWidth = parseInt(widthInput.val());
                const imageHeight = parseInt(heightInput.val());
                
                if (!imageType) {
                    alert('Please select an image type.');
                    return;
                }
                
                if (!imagePosition) {
                    alert('Please select a position.');
                    return;
                }
                
                if (!imageWidth || imageWidth < 10 || imageWidth > 1000) {
                    alert('Please enter a valid width between 10 and 1000 pixels.');
                    return;
                }
                
                if (!imageHeight || imageHeight < 10 || imageHeight > 1000) {
                    alert('Please enter a valid height between 10 and 1000 pixels.');
                    return;
                }
                
                if (!$('#previewImg').attr('src')) {
                    alert('Please upload an image first.');
                    return;
                }
                
                // Create a canvas to resize the image
                const img = new Image();
                img.src = $('#previewImg').attr('src');
                img.onload = function() {
                    // Get the original file to determine format
                    const originalFile = imageInput[0].files[0];
                    const originalFormat = originalFile ? originalFile.type.split('/')[1] : 'jpeg';
                    
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Calculate canvas dimensions based on rotation
                    let canvasWidth, canvasHeight;
                    if (Math.abs(currentRotation) % 180 === 90) {
                        // 90 or 270 degree rotation - swap dimensions
                        canvasWidth = imageHeight;
                        canvasHeight = imageWidth;
                    } else {
                        // 0, 180, or 360 degree rotation - keep original dimensions
                        canvasWidth = imageWidth;
                        canvasHeight = imageHeight;
                    }
                    
                    // Set canvas dimensions
                    canvas.width = canvasWidth;
                    canvas.height = canvasHeight;
                    
                    // Save context state
                    ctx.save();
                    
                    // Apply rotation to canvas
                    ctx.translate(canvasWidth / 2, canvasHeight / 2);
                    ctx.rotate(currentRotation * Math.PI / 180);
                    
                    // Draw and resize image
                    if (Math.abs(currentRotation) % 180 === 90) {
                        ctx.drawImage(img, -imageHeight / 2, -imageWidth / 2, imageHeight, imageWidth);
                    } else {
                        ctx.drawImage(img, -imageWidth / 2, -imageHeight / 2, imageWidth, imageHeight);
                    }
                    
                    // Restore context state
                    ctx.restore();
                    
                    if (canvas) {
                        // Use original format, fallback to PNG for transparency preservation
                        const outputFormat = (originalFormat === 'png' || originalFormat === 'gif') ? 'image/png' : 'image/jpeg';
                        const quality = outputFormat === 'image/jpeg' ? 1.0 : undefined;
                        const fileExtension = outputFormat === 'image/png' ? 'png' : 'jpg';
                        
                        canvas.toBlob(function(blob) {
                            const formData = new FormData();
                            formData.append('image', blob, `image.${fileExtension}`);
                            formData.append('image_type', imageType);
                            formData.append('image_position', imagePosition);
                            formData.append('image_width', imageWidth);
                            formData.append('image_height', imageHeight);
                        
                        // Send to server via AJAX
                        $.ajax({
                            url: 'api_uploaded_images.php',
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            beforeSend: function() {
                                $('#saveImageButton').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Uploading...');
                            },
                            success: function(response) {
                                console.log('Server response:', response);
                                
                                if (response.success) {
                                    const action = response.data.replaced ? 'replaced' : 'uploaded';
                                    alert('Image ' + action + ' successfully as ' + imageType + ' (Position: ' + imagePosition + ', Size: ' + imageWidth + 'x' + imageHeight + 'px)!');
                                    
                                    // Reset modal
                                    $('#imageUploadModal').modal('hide');
                                    resetModal();
                                    
                                    // Optionally refresh the page or update UI
                                    if (typeof refreshImages === 'function') {
                                        refreshImages();
                                    }
                                } else {
                                    alert('Upload failed: ' + (response.message || 'Unknown error'));
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Upload error:', error);
                                alert('Upload failed: ' + (xhr.responseJSON?.message || error || 'Network error'));
                            },
                            complete: function() {
                                $('#saveImageButton').prop('disabled', false).html('Save Image');
                            }
                        });
                    }, outputFormat, quality);
                    }
                };
            });
            
            // Reset modal when closed
            $('#imageUploadModal').on('hidden.bs.modal', function() {
                resetModal();
            });
            
            function resetModal() {
                // Reset form
                $('#imageUploadForm')[0].reset();
                
                // Reset rotation
                currentRotation = 0;
                
                // Reset UI
                dropZoneContent.removeClass('d-none');
                imagePreview.addClass('d-none');
                rotateControls.addClass('d-none');
                imageInput.val('');
                
                // Reset image transform
                $('#previewImg').css('transform', 'rotate(0deg)');
                
                // Reset dimensions to defaults
                widthInput.val(240);
                heightInput.val(65);
                aspectRatioCheckbox.prop('checked', true);
                
                // Clear event listeners
                widthInput.off('input');
                heightInput.off('input');
            }
        // Publish Grades functionality
            $('#publish-grades').click(function() {
                if (!$(this).prop('disabled')) {
                    publishGrades();
                }
            });

        // Publish All functionality (every class, each committed independently)
            $('#publish-all-grades').click(function() {
                if (!$(this).prop('disabled')) {
                    publishAllGrades();
                }
            });

            function publishAllGrades() {
                if (!confirm('Publish grades for ALL classes? Each class is saved independently; already-published records are updated. This will make grades visible to students.')) {
                    return;
                }

                $.ajax({
                    url: 'publish_grades.php',
                    type: 'POST',
                    data: {
                        class_id: 'all',
                        action: 'publish'
                    },
                    dataType: 'json',
                    timeout: 0,
                    beforeSend: function() {
                        $('#publish-all-grades').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Publishing All...');
                        $('#publish-grades').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            var msg = response.message || 'Publish All complete.';
                            if (response.details && response.details.length) {
                                var lines = response.details.map(function(d) {
                                    return (d.empty ? '[no students] ' : '') + d.label + ': ' + d.published + ' record(s)';
                                });
                                msg += '\n\n' + lines.join('\n');
                            }
                            if (response.classes_failed && response.classes_failed.length) {
                                msg += '\n\nFailed:\n' + response.classes_failed.map(function(f) {
                                    return 'Class ' + f.class_id + ': ' + f.error;
                                }).join('\n');
                            }
                            alert(msg);
                        } else {
                            alert('Failed to publish all grades: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Publish All error:', error);
                        alert('Failed to publish all grades: ' + (xhr.responseJSON?.message || error || 'Network error'));
                    },
                    complete: function() {
                        $('#publish-all-grades').prop('disabled', false).html('<i class="fa fa-cloud-upload-alt"></i> Publish All');
                        <?php if($show_table): ?>
                        $('#publish-grades').prop('disabled', false).html('<i class="fa fa-upload"></i> Publish Grades');
                        <?php endif; ?>
                    }
                });
            }
            
            function publishGrades() {
                const classId = $('#class_id').val();
                
                if (!classId) {
                    alert('Please select a class first.');
                    return;
                }
                
                if (!confirm('Are you sure you want to publish these grades? This will make the grades visible to students on the landing page.')) {
                    return;
                }
                
                $.ajax({
                    url: 'publish_grades.php',
                    type: 'POST',
                    data: {
                        class_id: classId,
                        action: 'publish'
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        $('#publish-grades').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Publishing...');
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Grades published successfully! ' + response.message);
                            // Optionally update UI or refresh
                        } else {
                            alert('Failed to publish grades: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Publish error:', error);
                        alert('Failed to publish grades: ' + (xhr.responseJSON?.message || error || 'Network error'));
                    },
                    complete: function() {
                        $('#publish-grades').prop('disabled', false).html('<i class="fa fa-upload"></i> Publish Grades');
                    }
                });
            }
        });
    </script>
<?php ob_end_flush(); ?>
