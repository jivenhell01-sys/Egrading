<?php
require_once 'vendor/autoload.php';
require_once 'DBConnection.php';
require_once 'Actions.php';
require_once 'class.UploadedImages.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

$action = new Actions();
$conn = new DBConnection();

// Function to get uploaded images from database
function getUploadedImages($conn) {
    $uploaded_image = new UploadedImages($conn);
    return $uploaded_image->readAllActive();
}

// Function to convert grade to equivalent (1.0-5.5 scale) using transmutation_table
function get_equivalent_from_transmutation($grade) {
	global $conn;

	if ($grade === 0 || !is_numeric($grade)) {
		return '5.0'; // Default to 5.0 for non-numeric grades
	}

	$grade = round(floatval($grade), 2);

	// Get the matching range from the active (CHED/DEPED) transmutation table
	static $trans_system = null;
	if ($trans_system === null) { $trans_system = get_active_transmutation_system($conn); }
	$transmutation_query = $conn->query("SELECT grade, `from`, `to` FROM `transmutation_table` WHERE `system` = '{$trans_system}' ORDER BY `from` ASC");
	$equivalent = 5.0; // Default numeric value if no range matches

	while ($row = $transmutation_query->fetchArray(SQLITE3_ASSOC)) {
		$from = floatval($row['from']);
		$to   = floatval($row['to']);

		if ($grade >= $from && $grade <= $to) {
			$equivalent = floatval($row['grade']);
			break;
		}
	}

	// DepEd transmuted grades (60-100 scale) pass through untouched.
	if ($equivalent > 5.0 && $grade >= 60.0 && $grade <= 100.0) {
		return number_format($equivalent, 1);
	}

	// If equivalent is 5.5, treat as DROPPED
	if ($equivalent > 5.0) {
		return 'DR';
	}

	return number_format($equivalent, 1);
}

// Function to convert transmuted grade to equivalent (1.0-5.0 scale)
function transmuted_to_equivalent($from, $to = null) {
    // Handle both old and new function signatures
    if ($to === null) {
        $g = floatval($from);
        if ($g <= 60) return number_format(5.0, 1);
        if ($g >= 95) return number_format(1.0, 1);
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
        if ($midpoint <= 50) return 5.0;
        if ($midpoint >= 95) return 1.0;

        // Calculate equivalent based on the range
        if ($midpoint <= 75) {
            // Linear interpolation between 50 (5.0) and 75 (3.0)
            $equiv = 5.0 - (($midpoint - 50) / (75 - 50)) * 2.0;
        } else {
            // Linear interpolation between 75 (3.0) and 95 (1.0)
            $equiv = 3.0 - (($midpoint - 75) / (95 - 75)) * 2.0;
        }

        // Ensure the equivalent is within bounds
        return max(1.0, min(5.0, $equiv));
    }
}

// Function to get equivalent grade (wrapper for consistency)
function get_equivalent_grade($grade) {
	return get_equivalent_from_transmutation($grade);
}

// Raw component score rescaled onto a 55-95 band:
//   (score / total items) * 40 + 55
// A perfect score lands on 95, a zero score on 55.
// $total_items is the sum of the component's assessment HPS for that quarter.
// MUST stay identical to reports_content.php / get_student_row_data.php /
// report_of_grades.php or the exported grade would not match the screen.
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
// MUST stay identical to report_of_grades.php or the exported Quarterly
// average would not match the on-screen report.
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
// The two disagree by up to a full transmutation step, and the class record
// (reports_content.php) has always used this one, so the export used to print a
// different number from the screen:
//     midterm 2.3 and final 5.0
//       average-of-transmuted -> (2.3 + 5.0)/2 = 3.65 -> 3.6
//       transmute-of-average  -> 85.99+62.90 = 74.45 -> 3.1
// Half-even rounding is applied via round_half_even(), so 3.65 becomes 3.6.
//
// Non-numeric entries ('INC', 'N/A') are skipped - a quarter that produced no
// transmuted grade cannot contribute to the mean.
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

// Start output buffering
ob_start();

// Set error reporting
ini_set('display_errors', 0);
error_reporting(0);

// Initialize response array
$response = ['status' => 'error', 'message' => ''];

try {
    // Check if this is a preview request
    if (isset($_GET['preview']) && $_GET['preview'] === 'true') {
        // For preview mode, we'll generate a simplified HTML preview instead of Word document
        if (isset($_GET['export']) && $_GET['export'] === 'grades' && isset($_GET['class_id'])) {
            $class_id = intval($_GET['class_id']);

            // Get class details
            $class = $conn->querySingle("SELECT c.*, s.name as subject_name
                                       FROM class_list c
                                       JOIN subjects s ON c.subject_id = s.subject_id
                                       WHERE c.class_id = '" . $conn->escapeString($class_id) . "'", true);

            if (!$class) {
                echo '<div class="alert alert-danger">Class not found for preview</div>';
                exit;
            }

            // Get uploaded images for preview
            $uploaded_images = getUploadedImages($conn);

            // Generate HTML preview
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Document Preview</title>
                <link href="./css/bootstrap.min.css" rel="stylesheet">
                <style>
                    .preview-header { border-bottom: 2px solid #000; padding: 20px 0; }
                    .preview-footer { border-top: 2px solid #000; padding: 20px 0; margin-top: 30px; }
                    .preview-table { border: 1px solid #000; width: 100%; }
                    .preview-table th { background-color: #f8f9fa; border: 1px solid #000; padding: 8px; text-align: center; }
                    .preview-table td { border: 1px solid #000; padding: 6px; }
                    .header-images { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
                    .header-images img { max-height: 80px; }
                    .grading-system { font-size: 0.8em; }

                    /* Print-specific styles */
                    @media print {
                        body { margin-left: 0.5in;margin-right: 0.5in; margin-top: 0.3in; margin-bottom: 0.3in; font-size: 10pt; }
                        .preview-header { page-break-after: avoid; margin-bottom: 30px; }
                        .preview-footer { page-break-inside: avoid; margin-top: 30px; }
                        .preview-table { page-break-inside: auto; }
                        .preview-table tr { page-break-inside: avoid; }
                        .preview-table th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                        .text-success { color: #00AA00 !important; font-weight: bold; }
                        .text-warning { color: #FFA500 !important; font-weight: bold; }
                        .text-danger { color: #FF0000 !important; font-weight: bold; }
                        .no-print { display: none !important; }
                    }

                    /* Screen styles for better preview */
                    @media screen {
                        body { padding: 20px; background: #f8f9fa; }
                        .preview-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                    }
                </style>
            </head>
            <body>
                <div class="preview-container">
                    <!-- Header Preview -->
                    <div class="preview-header">
                        <div class="header-images">
                            <div>
                                <?php if (isset($uploaded_images['header_left']) && file_exists($uploaded_images['header_left']['image_path'])): ?>
                                    <?php
                                    // Convert absolute path to relative web URL - handle both Windows and Linux paths
                                    $image_path = $uploaded_images['header_left']['image_path'];
                                    // Get the base directory from the current script location
                                    $base_path = dirname(__FILE__);
                                    $web_path = str_replace($base_path, '', $image_path);
                                    $web_path = ltrim($web_path, '/\\');
                                    ?>
                                    <img src="<?php echo htmlspecialchars($web_path); ?>"
                                         style="max-width: <?php echo $uploaded_images['header_left']['image_width'] ?? 240; ?>px;
                                                max-height: <?php echo $uploaded_images['header_left']['image_height'] ?? 65; ?>px;"
                                         onerror="console.log('Image path: <?php echo htmlspecialchars($web_path); ?>'); this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div style="display:none;" class="bg-light p-2 text-center">
                                        <small>Debug: <?php echo htmlspecialchars($web_path); ?></small><br>
                                        <small>Original: <?php echo htmlspecialchars($image_path); ?></small>
                                    </div>
                                <?php else: ?>
                                    <img src="uploads/EssuLogo.jpg" style="width: 240px; height: 65px;">
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if (isset($uploaded_images['header_right']) && file_exists($uploaded_images['header_right']['image_path'])): ?>
                                    <?php
                                    // Convert absolute path to relative web URL
                                    $image_path = $uploaded_images['header_right']['image_path'];
                                    $base_path = dirname(__FILE__);
                                    $web_path = str_replace($base_path, '', $image_path);
                                    $web_path = ltrim($web_path, '/\\');
                                    ?>
                                    <img src="<?php echo htmlspecialchars($web_path); ?>"
                                         style="max-width: <?php echo $uploaded_images['header_right']['image_width'] ?? 70; ?>px;
                                                max-height: <?php echo $uploaded_images['header_right']['image_height'] ?? 70; ?>px;"
                                         onerror="console.log('Image path: <?php echo htmlspecialchars($web_path); ?>'); this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div style="display:none;" class="bg-light p-2 text-center">
                                        <small>Debug: <?php echo htmlspecialchars($web_path); ?></small><br>
                                        <small>Original: <?php echo htmlspecialchars($image_path); ?></small>
                                    </div>
                                <?php else: ?>
                                    <img src="uploads/Bagong Pilipinas.png" style="width: 70px;">
                                <?php endif; ?>
                            </div>
                        </div>

                        <h3 class="text-center">REPORT OF GRADES</h3>
                        <p class="text-center"><?php echo ($class['semester']) . ', School Year '. $class['year']; ?></p>

                        <div class="row">
                            <div class="col-md-8">
                                <table class="table table-sm">
                                    <tr><td><strong>Subject Code:</strong></td><td><?php echo $class['course_no']; ?></td></tr>
                                    <tr><td><strong>Descriptive Title:</strong></td><td><?php echo $class['subject_name']; ?></td></tr>
                                    <tr><td><strong>Course/Year/Section:</strong></td><td><?php echo $class['grade'] . ' - ' . $class['section']; ?></td></tr>
                                    <tr><td><strong>Units:</strong></td><td><?php echo $class['unit'] ?? 'N/A'; ?></td></tr>
                                    <tr><td><strong>Schedule:</strong></td><td><?php echo $class['schedule'] ?? 'N/A'; ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-4">
                                <table class="table table-sm grading-system">
                                    <tr><th colspan="3" class="text-center">GRADING SYSTEM</th></tr>
                                    <tr><th>Actual Rating</th><th>Equivalent</th><th>Adjectival</th></tr>
                                    <tr><td>95-100</td><td>1.0</td><td>Outstanding</td></tr>
                                    <tr><td>90-94</td><td>1.5-1.1</td><td>Excellent</td></tr>
                                    <tr><td>85-89</td><td>2.0-1.6</td><td>Very Good</td></tr>
                                    <tr><td>80-84</td><td>2.5-2.1</td><td>Good</td></tr>
                                    <tr><td>75-79</td><td>3.0-2.6</td><td>Fair</td></tr>
                                    <tr><td>70-74</td><td>3.5-3.1</td><td>Conditional</td></tr>
                                    <tr><td>55-69</td><td>5.0-3.6</td><td>Failed</td></tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sample Grades Table -->
                    <div class="mt-4">
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Student No.</th>
                                    <th>Quarter 1</th>
                                    <th>Quarter 2</th>
                                    <th>Quarter 3</th>
                                    <th>Quarter 4</th>
                                    <th>Average</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-center">1</td>
                                    <td>Sample Student Name</td>
                                    <td class="text-center">2023-0001</td>
                                    <td class="text-center">1.5</td>
                                    <td class="text-center">1.8</td>
                                    <td class="text-center">2.0</td>
                                    <td class="text-center">1.7</td>
                                    <td class="text-center">1.75</td>
                                    <td class="text-center text-success">PASSED</td>
                                </tr>
                                <tr>
                                    <td class="text-center">2</td>
                                    <td>Another Student</td>
                                    <td class="text-center">2023-0002</td>
                                    <td class="text-center">3.5</td>
                                    <td class="text-center">3.2</td>
                                    <td class="text-center">3.8</td>
                                    <td class="text-center">3.0</td>
                                    <td class="text-center">3.38</td>
                                    <td class="text-center text-warning">CONDITIONAL</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Footer Preview -->
                    <div class="preview-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if (isset($uploaded_images['footer_left']) && file_exists($uploaded_images['footer_left']['image_path'])): ?>
                                    <?php
                                    // Convert absolute path to relative web URL
                                    $image_path = $uploaded_images['footer_left']['image_path'];
                                    $base_path = dirname(__FILE__);
                                    $web_path = str_replace($base_path, '', $image_path);
                                    $web_path = ltrim($web_path, '/\\');
                                    ?>
                                    <img src="<?php echo htmlspecialchars($web_path); ?>"
                                         style="max-width: <?php echo $uploaded_images['footer_left']['image_width'] ?? 250; ?>px;
                                                max-height: <?php echo $uploaded_images['footer_left']['image_height'] ?? 45; ?>px;"
                                         onerror="console.log('Footer image path: <?php echo htmlspecialchars($web_path); ?>'); this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div style="display:none;" class="bg-light p-2 text-center">
                                        <small>Debug Footer: <?php echo htmlspecialchars($web_path); ?></small><br>
                                        <small>Original: <?php echo htmlspecialchars($image_path); ?></small>
                                    </div>
                                <?php else: ?>
                                    <img src="uploads/ESSUCFooter.png" style="width: 250px; height: 45px;">
                                <?php endif; ?>
                            </div>
                            <div class="text-muted">
                                Page 1 of 1
                            </div>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    // Check if this is a grades export request
    if (isset($_GET['export']) && $_GET['export'] === 'grades' && isset($_GET['class_id'])) {
        $class_id = intval($_GET['class_id']);

        // Get class details with subject name
        $class = $conn->querySingle("SELECT c.*, s.name as subject_name
                                   FROM class_list c
                                   JOIN subjects s ON c.subject_id = s.subject_id
                                   WHERE c.class_id = '" . $conn->escapeString($class_id) . "'", true);

        if (!$class) {
            die('Class not found');
        }

        // Get all quarters that have assessments for this class.
        // Quarters are CUSTOM MADE per class, so we start from neutral labels
        // and overlay each class's custom names from `quarter_list` below.
        $all_quarters = [
            1 => ['name' => 'Quarter 1', 'key' => 'prelim', 'class' => 'text-primary'],
            2 => ['name' => 'Quarter 2', 'key' => 'midterm', 'class' => 'text-primary'],
            3 => ['name' => 'Quarter 3', 'key' => 'pre_final', 'class' => 'text-primary'],
            4 => ['name' => 'Quarter 4', 'key' => 'final', 'class' => 'text-success']
        ];

        // Override quarter display names with any custom names defined for the selected class.
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

        // Optional single-quarter export (e.g. from the class-record page):
        // ?quarter=N restricts the report to that quarter when it has assessments.
        $req_quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 0;
        if ($req_quarter > 0 && isset($active_quarters[$req_quarter])) {
            $active_quarters = [$req_quarter => $active_quarters[$req_quarter]];
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

        // Get all students in this class with their student numbers
        $students = [];
        $student_qry = $conn->query("SELECT * FROM student_list WHERE class_id = '" . $conn->escapeString($class_id) . "' ORDER BY name ASC");

        while($student = $student_qry->fetchArray(SQLITE3_ASSOC)){
            $students[$student['student_id']] = [
                'name' => $student['name'],
                'student_number' => $student['student_number'],
                'status' => $student['status'] ?? 'Active' // Default to 'Active' if status is not set
            ];
        }

        // Get all student initial grades
        $initial_grades = [];
        if (!empty($students)) {
            $grades_qry = $conn->query("SELECT * FROM student_initial_grade
                                        WHERE class_id = '" . $conn->escapeString($class_id) . "'");

            while($row = $grades_qry->fetchArray(SQLITE3_ASSOC)) {
                $initial_grades[$row['student_id']] = $row;
            }
        }

        // Fetch marks for INC detection (Total or weighted % ==0)
        $marks = [];
        if (!empty($students)) {
            $marks_qry = $conn->query("SELECT * FROM mark_list WHERE student_id IN (" . implode(",", array_keys($students)) . ") AND assessment_id IN (SELECT assessment_id FROM assessment_list WHERE class_id = $class_id)");
            if ($marks_qry) {
                while($row = $marks_qry->fetchArray(SQLITE3_ASSOC)){
                    $marks[$row['student_id']][$row['assessment_id']] = $row['mark'];
                }
            }
        }

        // Recompute initial grades LIVE from current marks and persist them,
        // mirroring report_of_grades.php. The student_initial_grade table is
        // otherwise only refreshed when that page loads, so marks edited
        // elsewhere (e.g. class-record inline editing) would leave the export
        // showing stale grades that no longer match the on-screen report.
        if (!empty($students)) {
            foreach ($students as $recalc_id => $recalc_stu) {
                $recalc_id = (int)$recalc_id;
                foreach ($all_quarters as $rq_num => $rq_data) {
                    $recalc_initial = 0;
                    foreach ($components as $rc_cid => $rc_cname) {
                        $rc_score = 0;
                        $rc_hps = 0;
                        $rc_graded = false;
                        if (isset($assess[$rc_cid])) {
                            foreach ($assess[$rc_cid] as $rc_ass) {
                                if ((int)$rc_ass['quarter'] === (int)$rq_num) {
                                    $rc_mark = $marks[$recalc_id][$rc_ass['assessment_id']] ?? null;
                                    if ($rc_mark === null || trim((string)$rc_mark) === '') {
                                        // Blank = 'not yet graded': it scores nothing, but its HPS
                                        // still counts in the denominator so a partially graded
                                        // component is diluted by its blanks.
                                    } else { $rc_graded = true; }
                                    $rc_score += $rc_mark ?? 0;
                                    $rc_hps += $rc_ass['hps'];
                                }
                            }
                            // A component with no graded work at all contributes 0, exactly as
                            // before - otherwise (0 / items) * 40 + 55 hands it 55 free points
                            // (55 * weight, i.e. 22 points for a 40% Major Exams).
                            // Must match reports_content.php / report_of_grades.php /
                            // get_student_row_data.php or the exported quarter grade would not
                            // match the on-screen report.
                            if ($rc_graded) {
                                // Scaled raw score (score / total items) * 40 + 55,
                                // weighted by the component percentage as a decimal.
                                $rc_grade = get_scaled_component_raw($rc_score, $rc_hps);
                                $recalc_initial += $rc_grade * (($c_perc_arr[$rc_cid] ?? 0) / 100);
                            }
                        }
                    }
                    $chk = $conn->query("SELECT id FROM `student_initial_grade` where student_id = '{$recalc_id}' and class_id = '{$class_id}' and quarter = '{$rq_num}'")->fetchArray();
                    if ($chk) {
                        $conn->query("UPDATE `student_initial_grade` set initial_grade = '{$recalc_initial}' where id = '{$chk['id']}'");
                    } else {
                        $conn->query("INSERT INTO `student_initial_grade` (student_id, class_id, quarter, initial_grade) VALUES('{$recalc_id}', '{$class_id}', '{$rq_num}', '{$recalc_initial}')");
                    }
                }
            }
        }

        // Create new PHPWord object
        $phpWord = new PhpWord();

        // Set document properties
        $phpWord->getDocInfo()->setCreator('Grading System')
                             ->setLastModifiedBy('Grading System')
                             ->setTitle('Report of Grades')
                             ->setSubject('Report of Grades')
                             ->setDescription('Report of Grades Document');

        // Add a section with margins and page size
        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'pageSize' => [
                'width' => Converter::inchToTwip(8.5),
                'height' => Converter::inchToTwip(13)
            ],
            'marginLeft' => Converter::cmToTwip(1.27),   // 1 inch = 2.54cm
            'marginRight' => Converter::cmToTwip(1.27),
            'marginTop' => Converter::cmToTwip(0.3),   // 0.3 inch top margin for header
            'marginBottom' => Converter::cmToTwip(0.3),
            // Distance from the page edge to the top of the header / bottom of the footer (0.3" = 432 twips)
            'headerHeight' => Converter::inchToTwip(0.3),
            'footerHeight' => Converter::inchToTwip(0.3)
        ]);

        // Add header with logos and title
        $header = $section->addHeader();

        // Get uploaded images from database
        $uploaded_images = getUploadedImages($conn);

        $headerTable = $header->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::BOTH,
            'width' => 10800,
            'unit' => 'dxa',
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED
        ]);

        $headerTable->addRow(); // Smaller row height for header (0.1 inch)

        // Left header image (cell = 5400 twips of the 10500 twips usable width)
        $left_image = $uploaded_images['header_left'] ?? null;
        if ($left_image && file_exists($left_image['image_path'])) {
            $width = $left_image['image_width'] ?? 240;
            $height = $left_image['image_height'] ?? 65;
            $headerTable->addCell(5400, ['valign' => 'top'])->addImage(
                $left_image['image_path'],
                [
                    'width' => $width,
                    'height' => $height,
                    'alignment' => Jc::START
                ]
            );
        } else {
            // Fallback to default logo
            $headerTable->addCell(5400, ['valign' => 'top'])->addImage(
                'uploads/EssuLogo.jpg',
                [
                    'width' => 240,
                    'height' => 65,
                    'alignment' => Jc::START
                ]
            );
        }

        // Right header image
        $right_image = $uploaded_images['header_right'] ?? null;
        if ($right_image && file_exists($right_image['image_path'])) {
            $width = $right_image['image_width'] ?? 70;
            $height = $right_image['image_height'] ?? 70;
            $headerTable->addCell(5400, ['valign' => 'top'])->addImage(
                $right_image['image_path'],
                [
                    'width' => $width,
                    'height' => $height,
                    'alignment' => Jc::END
                ]
            );
        } else {
            // Fallback to default logo
            $headerTable->addCell(5400, ['valign' => 'top'])->addImage(
                'uploads/Bagong Pilipinas.png',
                [
                    'width' => 70,
                    'alignment' => Jc::END
                ]
            );
        }

        // Add title in header
        $header->addText(
            'REPORT OF GRADES',
            ['bold' => true, 'size' => 14],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 50]
        );

        // Add academic year and semester in header
        $header->addText(
            ($class['semester']) . ', ' .'School Year '. $class['year'],
            ['size' => 11, 'font' => 'Arial', 'italic' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 300]
        );

        // Cell style for all cells
        $cellStyle = [
            'valign' => 'center',
            'align' => 'left',
            'vAlign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0
        ];

        // Text style to remove paragraph spacing
        $textStyle = ['spaceAfter' => 0, 'spacing' => 0, 'size' => 9];

        // Create a container table with 2 columns that fills the usable width (10800 twips)
        $containerTable = $header->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::START,
            'width' => 10800,
            'unit' => 'dxa',
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED
        ]);
        $containerTable->addRow();

        // Left cell for class info (course/subject/schedule details)
        $leftCell = $containerTable->addCell(5200, ['vAlign' => 'top']);

        // Add class info table to left cell
        $rowHeight = 288; // 0.3 inches in twips (0.3 * 1440)

        $infoTable = $leftCell->addTable([
            'borderSize' => 0,
            'alignment' => Jc::START,
            'size' => 9,
            'borderColor' => 'FFFFFF'
        ]);

        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2200, $cellStyle)->addText('Subject Code:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' . $class['course_no'], null, $textStyle);

        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2200, $cellStyle)->addText('Descriptive Title:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' . $class['subject_name'], null, $textStyle);

        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2200, $cellStyle)->addText('Course/Year/Section:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' . $class['grade'] . ' - ' . $class['section'], null, $textStyle);

        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2200, $cellStyle)->addText('Units:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' . ($class['unit'] ?? 'N/A'), null, $textStyle);

        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2200, $cellStyle)->addText('Schedule:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' . ($class['schedule'] ?? 'N/A'), null, $textStyle);

        // Right cell for grading system (fits the remaining 5600 twips of usable width)
        $rightCell = $containerTable->addCell(5600, ['vAlign' => 'top']);

        // Add grading system table
        $gradingTable = $rightCell->addTable([
            'borderSize' => 1,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::CENTER,
            'width' => 5600,
            'unit' => 'dxa',
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED,
            'cellMargin' => 50,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0,
            'cellMarginLeft' => 50,
            'cellMarginRight' => 50
        ]);

        $gradingRowHeight = 200; // Reduced row height for more compact display
        $cellStyle = [
            'valign' => 'center',
            'vAlign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'spaceBefore' => 0
        ];

        $textStyle = [
            'spaceAfter' => 0,
            'spacing' => 0,
            'spaceBefore' => 0,
            'valign' => 'center',
            'vAlign' => 'center'
        ];

        // Grading system header with colspan 3 (width = sum of 3 columns: 3 x 1800 = 5400)
        $gradingTable->addRow($gradingRowHeight);
        $gradingTable->addCell(5400, array_merge($cellStyle, [
            'gridSpan' => 3
        ]))->addText('GRADING SYSTEM', [
            'bold' => true,
            'size' => 9,
            'valign' => 'center'
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 0,
            'spacing' => 0
        ]);

        // Column headers
        $gradingTable->addRow($gradingRowHeight);
        $gradingTable->addCell(1800, $cellStyle)
            ->addText('Actual Rating', ['size' => 8, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'spacing' => 0]);
        $gradingTable->addCell(1800, $cellStyle)
            ->addText('Equivalent Rating', ['size' => 8, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'spacing' => 0]);
        $gradingTable->addCell(1800, $cellStyle)
            ->addText('Adjectival Rating', ['size' => 8, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'spacing' => 0]);

        // Grading system rows
        $gradingRows = [
            ['95-100', '1.0', 'Outstanding'],
            ['90-94', '1.5-1.1', 'Excellent'],
            ['85-89', '2.0-1.6', 'Very Good'],
            ['80-84', '2.5-2.1', 'Good'],
            ['75-79', '3.0-2.6', 'Fair'],
            ['70-74', '3.5-3.1', 'Conditional'],
            ['55-69', '5.0-3.6', 'Failed'],
            ['INC', 'INC', 'Incomplete'],
            ['DR', 'DR', 'Dropped'],
            ['WP', 'WP', 'Withdrawn w/ Permission'],
            ['IP', 'IP', 'In Progress']
        ];

        foreach ($gradingRows as $row) {
            $gradingTable->addRow($gradingRowHeight);
            $gradingTable->addCell(1800, $cellStyle)
                ->addText($row[0], ['size' => 7], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'spacing' => 0]);
            $gradingTable->addCell(1800, $cellStyle)
                ->addText($row[1], ['size' => 7], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'spacing' => 0]);
            $gradingTable->addCell(1800, $cellStyle)
                ->addText($row[2], ['size' => 7], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'spacing' => 0]);
        }


        $header->addTextBreak(1);

        // Create table for student grades
        $table = $section->addTable([
            'borderSize' => 8,
            'borderColor' => '000000',
            'valign' => 'center',
            'align' => 'left',
            'vAlign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'width' => 10800,
            'unit' => 'dxa',
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED
        ]);

        $textStyle = ['spaceAfter' => 0, 'spacing' => 0];
        // Table header
        $headerStyle = array_merge($textStyle, [
            'bgColor' => 'E9ECEF',
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0
        ]);

        $table->addRow(360, [  // 0.6cm row height
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
            'tblHeader' => true,
            'exactHeight' => 400
        ]);

        // Calculate column widths so the guard table always fills the usable
        // width of the page (10500 twips). The quarter column(s) absorb the
        // remaining space, scaling down automatically when there are more quarters.
        $num_quarters = count($active_quarters);

        $num_width       = 500;   // '#' column
        $name_width      = 3600;  // Student Name
        $no_width        = 1300;  // Student No.
        $average_width   = 1200;  // Average
        $remarks_width   = 1600;  // Remarks

        // Remaining space is shared equally among the quarter columns.
        $fixed_width = $num_width + $name_width + $no_width + $average_width + $remarks_width;
        $quarter_width = intdiv(10800 - $fixed_width, $num_quarters);
        // Guard against an empty/zero division if no quarters are present.
        if ($quarter_width < 200) {
            $quarter_width = 200;
        }

        $table->addCell($num_width, $headerStyle)->addText('#', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $table->addCell($name_width, $headerStyle)->addText('Student Name', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $table->addCell($no_width, $headerStyle)->addText('Student No.', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);

        // Add quarter headers
        foreach ($active_quarters as $q_num => $q_data) {
            $table->addCell($quarter_width, $headerStyle)->addText($q_data['name'], ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        $table->addCell($average_width, $headerStyle)->addText('Average', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $table->addCell($remarks_width, $headerStyle)->addText('Remarks', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        // Add student rows
        $studentStyle = array_merge($textStyle, [
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 1,
            'cellMarginBottom' => 1,
            'size' => 6
        ]);
        $rowNum = 1;
        foreach ($students as $student_id => $student) {
            $table->addRow(288, [  // 0.6cm row height
                'spaceBefore' => 0,
                'spaceAfter' => 0,
                'spacing' => 0,
                'exactHeight' => 288
            ]);
            $table->addCell($num_width, $studentStyle)->addText($rowNum, null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $table->addCell($name_width, $studentStyle)->addText($student['name'], null, [ // Remove bold from student name
                'alignment' => Jc::START,
                'spaceAfter' => 0,
                'spacing' => 0,
                'indentation' => ['left' => 100]  // 200 twips = ~0.35 cm indent
            ]);
            $table->addCell($no_width, $studentStyle)->addText($student['student_number'], null, [ // Remove bold from student number
                'alignment' => Jc::CENTER,
                'spaceAfter' => 0,
            ]);

            $has_grades = false;
            $grades = [];
            $has_incomplete = false;
            // Tracks whether any quarter cell displays INC (mirrors the
            // report_of_grades.php quarter rule). Used to gate Average INC.
            $export_quarter_inc = false;
            // q_num => the transmuted grade printed in that quarter's column.
            // The Average column averages THESE (not the raw initial grades),
            // matching reports_content.php and report_of_grades.php.
            $export_quarter_transmuted = [];

            // Get grades for each quarter
            // Fetch initial grades for this student from the database
            $initial_grades_stmt = $conn->prepare("SELECT quarter, initial_grade FROM student_initial_grade WHERE student_id = :student_id AND class_id = :class_id");
            $initial_grades_stmt->bindValue(':student_id', $student_id, SQLITE3_INTEGER);
            $initial_grades_stmt->bindValue(':class_id', $class_id, SQLITE3_INTEGER);
            $result = $initial_grades_stmt->execute();

            // Create an array to store initial grades by quarter
            $student_initial_grades = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $student_initial_grades[$row['quarter']] = $row['initial_grade'];
            }

            foreach ($active_quarters as $q_num => $q_data) {
                // Get the initial grade for this quarter if it exists
                $initial_grade = $student_initial_grades[$q_num] ?? 'N/A';

                if ($initial_grade !== 'N/A' && is_numeric($initial_grade)) {
                    $initial_grade = floatval($initial_grade);
                    // INC rule: a BLANK (missing) mark under a component with
                    // percentage >= 30% => INC. An explicitly encoded 0 is
                    // failed work, not incomplete work.
                    $has_zero_q = false;
                    foreach($components as $hz_cid => $hz_cname){
                        if(isset($assess[$hz_cid])){
                            $hz_q_score=0; $hz_q_hps=0; $hz_has=false; $hz_blank=false;
                            foreach($assess[$hz_cid] as $hz_ass){
                                if($hz_ass['quarter']==$q_num){
                                    $hz_has=true;
                                    $hz_mark = $marks[$student_id][$hz_ass['assessment_id']] ?? null;
                                    if ($hz_mark === null || trim((string)$hz_mark) === '') { $hz_blank = true; }
                                    $hz_q_score += $hz_mark ?? 0;
                                    $hz_q_hps += $hz_ass['hps'];
                                }
                            }
                            if($hz_has){
                                $hz_comp_grade = get_scaled_component_raw($hz_q_score, $hz_q_hps);
                                $hz_weighted = get_weighted_percentage($hz_q_score, $hz_q_hps, $c_perc_arr[$hz_cid] ?? 0);
                                if($hz_blank && ($c_perc_arr[$hz_cid] ??0) >=30){ $has_zero_q=true; break; }
                            }
                        }
                    }
            if($student['status'] === 'Dropped'){
                         // 'NO GRADE', not 'N/A': matches report_of_grades.php, so a
                         // dropped student reads the same in the export and on screen.
                         $table->addCell($quarter_width, $studentStyle)->addText('NO GRADE', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                    } else if($initial_grade >= 40 && $initial_grade <= 55.49 && $has_zero_q){
                        // Blank under a 30%+ component plus a raw initial in
                        // [40, 55.49] => INC.
                        $table->addCell($quarter_width, $studentStyle)->addText('INC', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                        $export_quarter_inc = true;
                        $grades[] = 5.0;
                        $has_dropped = true;
                        // 'INC' is not a transmuted grade, so it is left out of the
                        // Average - same as the class record does.
                    } else if ($initial_grade >=0 && $initial_grade <= 55.49) {
                        $table->addCell($quarter_width, $studentStyle)->addText('5.0', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                        $grades[] = 5.0;
                        $has_dropped = false;
                        $export_quarter_transmuted[$q_num] = 5.0;
                    } else {
                        $has_grades = true;
                        $equivalent = get_equivalent_from_transmutation($initial_grade);
                        $grades[] = is_numeric($equivalent) ? (float)$equivalent : 5.0;
                        if (is_numeric($equivalent)) {
                            $export_quarter_transmuted[$q_num] = (float)$equivalent;
                        }
                        $table->addCell($quarter_width, $studentStyle)->addText($equivalent, null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Remove bold from grade
                    }
                } else {
                    $table->addCell($quarter_width, $studentStyle)->addText('N/A', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                    $grades[] = 5.0; // Use 5.0 for missing grades in average calculation
                }
            }

            // -----------------------------------------------------------------
            // Average column = average of the quarters' TRANSMUTED grades.
            // Active grading system is fixed per run.
            $depEdExport = (get_active_transmutation_system($conn) === 'DEPED');
            // NOTE: "a quarter showing INC forces the Average to INC" is applied
            // BELOW, right after $average_display_value is reset to null. Setting
            // it here was dead code - the reset wiped it out, so a student with
            // e.g. 1.7 / INC averaged to 1.7 and was exported as PASSED.
            //
            // Detect which quarters display as "DR" so the Average column can
            // follow these rules:
            //   * SINGLE quarter   -> if that quarter is DR, Average = DR.
            //   * MULTIPLE quarters -> if AT LEAST ONE quarter is DR, Average = N/A.
            // A quarter counts as DR when its transmuted equivalent is 'DR'.
            // -----------------------------------------------------------------
            $quarter_dr_flags = [];   // q_num => true when that quarter shows DR
            $dr_quarter_count = 0;
            $num_active_quarters = count($active_quarters);

            foreach ($active_quarters as $q_num => $q_data) {
                $q_initial = $student_initial_grades[$q_num] ?? 'N/A';
                $is_q_dr   = false;

                if ($q_initial !== 'N/A' && is_numeric($q_initial)) {
                    $eq_check = get_equivalent_from_transmutation(floatval($q_initial));
                    $is_q_dr  = ($eq_check === 'DR');
                }

                $quarter_dr_flags[$q_num] = $is_q_dr;
                if ($is_q_dr) {
                    $dr_quarter_count++;
                }
            }

            // Calculate average following report_of_grades.php pattern:
            // 1. First calculate average of initial grades
            $initial_grades_for_avg = [];
            $transmuted_grades_for_display = [];
            $valid_quarters = 0;
            $total_initial = 0;

            foreach ($active_quarters as $q_num => $q_data) {
                // Get the initial grade for this quarter if it exists
                $initial_grade = $student_initial_grades[$q_num] ?? 'N/A';

                if ($initial_grade !== 'N/A' && is_numeric($initial_grade) && $initial_grade > 0) {
                    $initial_grade = floatval($initial_grade);

                    // Ensure initial grade is not below 54.5 (CHED floor only;
                    // the DepEd table covers 0-100 natively)
                    if (!$depEdExport && $initial_grade < 54.5) {
                        $initial_grade = 54.5;
                    }

                    $total_initial += $initial_grade;
                    $valid_quarters++;

                    // Get transmuted equivalent for display
                    $equivalent = get_equivalent_from_transmutation($initial_grade);
                    $transmuted_grades_for_display[] = $equivalent;
                }
            }

            // 2. Resolve what the Average column should display.
            //    INC gate: show Average INC / Remarks INCOMPLETE only when at
            //    least one quarter shows INC on the reference pages —
            //    (a) this export's own quarter cells (same rule as
            //    report_of_grades.php), or (b) the reports_content.php class
            //    record (weighted quarter grade in [30,55.49) + zero-score
            //    30%+ component). Otherwise the average falls through to 5.0.
            $screen_quarter_inc = false;
            if (!isset($student['status']) || $student['status'] !== 'Dropped') {
                foreach ($active_quarters as $sq_num => $sq_data) {
                    $sq_w = 0;
                    $sq_p = 0;
                    $sq_zero = false;
                    foreach ($components as $sc_cid => $sc_cname) {
                        if (isset($assess[$sc_cid])) {
                            $sc_s = 0;
                            $sc_h = 0;
                            $sc_has = false;
                            $sc_blank = false;
                            $sc_graded = false;
                            foreach ($assess[$sc_cid] as $sc_a) {
                                if ((int)$sc_a['quarter'] === (int)$sq_num) {
                                    $sc_has = true;
                                    $sc_mark = $marks[$student_id][$sc_a['assessment_id']] ?? null;
                                    if ($sc_mark === null || trim((string)$sc_mark) === '') { $sc_blank = true; }
                                    else { $sc_graded = true; }
                                    $sc_s += $sc_mark ?? 0;
                                    // A blank's HPS counts in the denominator too.
                                    $sc_h += $sc_a['hps'];
                                }
                            }
                            if ($sc_has) {
                                // Only a BLANK under a 30%+ component counts toward
                                // INC. An explicitly encoded 0 is failed work.
                                if ($sc_blank && ($c_perc_arr[$sc_cid] ?? 0) >= 30) $sq_zero = true;
                                // A component with no graded work at all contributes 0 -
                                // otherwise (0 / items) * 40 + 55 hands it 55 free
                                // points. Same guard as the other three files.
                                if ($sc_graded) {
                                    // Same scaled-raw weighting the on-screen class
                                    // record uses, so the INC gate below matches it.
                                    $sq_w += get_weighted_percentage($sc_s, $sc_h, $c_perc_arr[$sc_cid] ?? 0);
                                    $sq_p += ($c_perc_arr[$sc_cid] ?? 0);
                                }
                            }
                        }
                    }
                    // The quarter's raw initial is the plain weighted SUM, exactly as
                    // report_of_grades.php builds it (sum of get_weighted_percentage
                    // over components). Dividing by $sq_p and rescaling to 100 made
                    // this a different number from the stored initial_grade, which
                    // is how a student with 5.0 / 5.0 quarters used to be exported
                    // as INC while the page showed FAILED.
                    $sq_grade = $sq_w;
                    // Blank under a 30%+ component with the quarter raw in
                    // [40, 55.49] => INC.
                    if ($sq_grade >= 40 && $sq_grade <= 55.49 && $sq_zero) { $screen_quarter_inc = true; break; }
                }
            }
            $allow_inc = ($export_quarter_inc || $screen_quarter_inc);
            //    Priority:
            //      a. Quarter-level DR rule (user requirement):
            //           - Single quarter  -> quarter is DR  => Average = DR
            //           - Multiple quarters -> >=1 quarter DR => Average = N/A
            //      b. Otherwise compute the numeric average of initial grades and
            //         transmute it (a 'DR' result still displays as DR).
            $average_initial = 0;
            $final_grade = 0;
            $average_display_value = null;   // null = use the computed numeric grade

            if ($dr_quarter_count > 0 && $valid_quarters > 1) {
                $average_display_value = '5.0';
            }

            // A quarter showing INC forces the Average to INC as well, exactly like
            // report_of_grades.php ($any_quarter_inc) and reports_content.php.
            // Without it this export averaged only the passed quarters and printed
            // a passing average (e.g. 1.7 / INC quarters -> 1.7 PASSED) for a student
            // both on-screen pages mark INC. Applied AFTER the $average_display_value
            // reset above (so it survives) and AFTER the DR rule (so INC wins over
            // DR), which is the same priority report_of_grades.php uses. Dropped
            // students are handled by their own branch further down.
            if ($export_quarter_inc) {
                $average_display_value = 'INC';
            }

            // INC check for average: if average initial <55.49 (>=1) and any BLANK
            // mark under a 30%+ component => INC. An explicitly encoded 0 is
            // failed work, not incomplete work.
            $has_zero_overall_avg = false;
            foreach($components as $hz_cid_avg => $hz_cname_avg){
                foreach($active_quarters as $hz_q_avg => $hz_qdata_avg){
                    if(isset($assess[$hz_cid_avg])){
                        $hz_q_score_avg=0; $hz_q_hps_avg=0; $hz_has_avg=false; $hz_blank_avg=false;
                        foreach($assess[$hz_cid_avg] as $hz_ass_avg){
                            if($hz_ass_avg['quarter']==$hz_q_avg){
                                $hz_has_avg=true;
                                $hz_m = $marks[$student_id][$hz_ass_avg['assessment_id']] ?? null;
                                if ($hz_m === null || trim((string)$hz_m) === '') { $hz_blank_avg = true; }
                                $hz_q_score_avg += $hz_m ?? 0;
                                $hz_q_hps_avg += $hz_ass_avg['hps'];
                            }
                        }
                        if($hz_has_avg){
                            $hz_comp_grade_avg = get_scaled_component_raw($hz_q_score_avg, $hz_q_hps_avg);
                            $hz_weighted_avg = get_weighted_percentage($hz_q_score_avg, $hz_q_hps_avg, $c_perc_arr[$hz_cid_avg] ?? 0);
                            if($hz_blank_avg && ($c_perc_arr[$hz_cid_avg] ??0) >=30){ $has_zero_overall_avg=true; break 2;}
                        }
                    }
                }
            }
            // Override DR/N/A with INC when a blank under a 30%+ component exists
            // and a quarter shows INC on the reference pages (see $allow_inc
            // above). No raw-grade range: any blank under 30%+ qualifies.
            if($has_zero_overall_avg && $valid_quarters>0 && $average_display_value !== null && $allow_inc){
                $average_display_value = 'INC';
            }
            if ($average_display_value === null && $valid_quarters > 0) {
                if ($valid_quarters > 1) {
                    // Calculate average if we have more than one quarter
                    // Half-even rounding at 2dp - matches report_of_grades.php
                    $average_initial = round_half_even($total_initial / $valid_quarters, 2);
                } else {
                    // If only one quarter has grades, use that quarter's grade as the average
                    $average_initial = $total_initial; // Since there's only one quarter, total_initial is the grade
                }
                // INC rule for average (gated by $allow_inc: needs quarter-level INC
                // plus a raw average in [40, 55.49]).
                // Otherwise the Average is ALWAYS the literal mean of the
                // quarters' displayed transmuted grades.
                if($average_initial >= 40 && $average_initial <= 55.49 && $has_zero_overall_avg && $allow_inc){
                    $average_display_value = 'INC';
                } else {
                    // Average the quarters' TRANSMUTED grades (the values printed
                    // in the quarter columns) instead of transmuting the mean of
                    // the raw initial grades. Matches reports_content.php and
                    // report_of_grades.php; half-even rounding applied inside the
                    // helper. $average_initial is still used for the
                    // no-numeric-quarters fallback below.
                    $avg_of_transmuted = get_average_of_transmuted($export_quarter_transmuted);
                    if ($avg_of_transmuted === null) {
                        // Nothing numeric to average - fall back to transmuting
                        // the raw average (previous behaviour).
                        $equivalent = get_equivalent_from_transmutation($average_initial);
                        $avg_of_transmuted = is_numeric($equivalent) ? (float)$equivalent : 0;
                    }
                    $final_grade = $avg_of_transmuted;
                }
            }

            if($student['status'] === 'Dropped'){
                // 'NO GRADE', not 'N/A': matches report_of_grades.php.
                $table->addCell($average_width, $studentStyle)->addText('NO GRADE', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                $table->addCell($remarks_width, $studentStyle)->addText('DROPPED', ['color' => 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            } else if ($average_display_value === 'INC') {
                // Checked BEFORE the DepEd branch so a quarter-INC cannot be
                // averaged away on either grading system.
                $table->addCell($average_width, $studentStyle)->addText('INC', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                // 'INCOMPLETE' vs the page's 'INC' in Remarks is deliberate (user
                // decision) - the Average column itself is what must match.
                $table->addCell($remarks_width, $studentStyle)->addText('INCOMPLETE', ['color' => '000000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            } else if ($depEdExport) {
                // DepEd Final = average of the quarterly transmuted grades above.
                // Uses the same helper as the CHED branch, and the same
                // $export_quarter_transmuted array, so the two grading systems
                // cannot drift apart. Half-even rounding lives in the helper.
                $dep_final = get_average_of_transmuted($export_quarter_transmuted);
                if ($dep_final !== null) {
                    $table->addCell($average_width, $studentStyle)->addText(number_format($dep_final, 1), null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                    $dep_rem = remarks_for_grade($dep_final, 'DEPED');
                    $table->addCell($remarks_width, $studentStyle)->addText($dep_rem, ['color' => $dep_rem === 'PASSED' ? '00AA00' : 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
                } else {
                    $table->addCell($average_width, $studentStyle)->addText('N/A', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                    $table->addCell($remarks_width, $studentStyle)->addText('DROPPED', ['color' => 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
                }
            } else if ($average_display_value === 'DR' || $average_display_value === '5.0') {
                $table->addCell($average_width, $studentStyle)->addText('5.0', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                $table->addCell($remarks_width, $studentStyle)->addText('FAILED', ['color' => 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            } else if ($average_display_value === 'N/A') {
                $table->addCell($average_width, $studentStyle)->addText('5.0', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                $table->addCell($remarks_width, $studentStyle)->addText('FAILED', ['color' => 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            } else if ($final_grade === 'DR' || $final_grade === '5.0' || $final_grade == 5 || $final_grade == 5.0) {
                $table->addCell($average_width, $studentStyle)->addText('5.0', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                $table->addCell($remarks_width, $studentStyle)->addText('FAILED', ['color' => 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            } else if ($final_grade > 0 && $valid_quarters > 0) {
                $final_grade = number_format($final_grade, 1);
                $table->addCell($average_width, $studentStyle)->addText($final_grade, null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Remove bold from average

                // Add remarks - matching api_view_grades.php logic
                $remarks = '';
                $remarksStyle = null;
                if ($student['status'] === 'Dropped') {
                    $remarks = 'DROPPED';
                    $remarksStyle = ['color' => 'FF0000', 'bold' => true];
                } elseif ($final_grade == 5.0) {
                    $remarks = 'FAILED';
                    $remarksStyle = ['color' => 'FF0000', 'bold' => true];
                } elseif ($final_grade > 5.0 || $final_grade < 1.0) {
                    $remarks = 'DROPPED';
                    $remarksStyle = ['color' => 'FF0000', 'bold' => true];
                } elseif ($final_grade >= 3.6) {
                    $remarks = 'FAILED';
                    $remarksStyle = ['color' => 'FF0000', 'bold' => true];
                } elseif ($final_grade >= 3.1) {
                    $remarks = 'CONDITIONAL';
                    $remarksStyle = ['color' => 'FFA500', 'bold' => true];
                } else {
                    $remarks = 'PASSED';
                    $remarksStyle = ['color' => '00AA00', 'bold' => true];
                }

                $table->addCell($remarks_width, $studentStyle)->addText($remarks, $remarksStyle, ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            } else {
                $table->addCell($average_width, $studentStyle)->addText('N/A', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                $table->addCell($remarks_width, $studentStyle)->addText('DROPPED', ['color' => 'FF0000', 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
            }

            $rowNum++;

        }

        // Add a row with 'nothing' as content spanning all columns
        $table->addRow(340.16, [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
        ]);

        // Calculate total width and column count dynamically
        $total_width = $num_width + $name_width + $no_width + ($num_quarters * $quarter_width) + $average_width + $remarks_width;
        $total_columns = 5 + $num_quarters; // # + Student Name + Student No. + Average + Remarks + quarters

        $mergedCell = $table->addCell($total_width, $studentStyle);
        $mergedCell->getStyle()->setGridSpan($total_columns);
        $mergedCell->addText('***Nothing as follows***', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);

        // Add footer with signature section and page numbers
        $footer = $section->addFooter();

        // Add a top border for the signature section in footer
        $footer->addText('', [], [
            'borderBottom' => [
                'borderBottomSize' => 1,
                'borderBottomColor' => '000000',
                'spaceAfter' => 300
            ]
        ]);

        // Create a table for the three-column signature section in footer
        $signatureTable = $footer->addTable([
            'borderSize' => 0,
            'cellMargin' => 150,
            'alignment' => Jc::BOTH,
            'width' => 10800,
            'unit' => 'dxa',
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED,
            'borderColor' => 'FFFFFF'
        ]);

        // Add a row for the signature columns
        $signatureTable->addRow();

        // Define signatory types and their display titles
        $signatoryTypes = [
            'certified' => 'Certified true and correct',
            'checked' => 'Checked by',
            'noted' => 'Noted by',
            'received' => 'Received by'
        ];

        // Get signatories from database
        $signatories = [];
        $signatoryQuery = $conn->query("SELECT * FROM signatories ORDER BY type");
        $hasSignatories = false;
        while($row = $signatoryQuery->fetchArray(SQLITE3_ASSOC)) {
            $signatories[$row['type']] = $row;
            $hasSignatories = true;
        }

        // Only show signature section if there are signatories
        if ($hasSignatories) {
            // Build a list of signatory types that actually have data — the
            // table's column count must match this list, otherwise Word/WPS
            // shows empty columns (2 signatories would still render as 4).
            $activeSignatoryTypes = [];
            foreach ($signatoryTypes as $type => $title) {
                $sig = $signatories[$type] ?? null;
                if ($sig && !empty($sig['fullname'])) {
                    $activeSignatoryTypes[$type] = $title;
                }
            }

            // Edge case: rows exist but none have a fullname — nothing to show
            if (empty($activeSignatoryTypes)) {
                $activeSignatoryTypes = [];
            } else {
                $colCount = count($activeSignatoryTypes);
                $colWidth = intdiv(10800, $colCount); // 2 cols -> 5400, 3 -> 3600, 4 -> 2700, etc.

                // Output each active signatory column — column width scales
                // with how many signatories are actually present
                foreach ($activeSignatoryTypes as $type => $title) {
                    $signatory = $signatories[$type] ?? null;

                    $cell = $signatureTable->addCell($colWidth, [
                        'valign' => 'top',
                        'align' => 'center'
                    ]);

                    if (!$signatory) {
                        // Leave the cell empty for signatories we have no data for.
                        continue;
                    }

                // Add title
                $cell->addText($title . ':', [
                    'bold' => true,
                    'size' => 8
                ], [
                    'spaceAfter' => 350,
                    'alignment' => Jc::START
                ]);

                if (!empty($signatory['fullname'])) {
                    $textRun = $cell->addTextRun([
                        'alignment' => Jc::CENTER,
                        'spaceAfter' => 20
                    ]);
                    $textRun->addText($signatory['fullname'], [
                        'bold' => true,
                        'underline' => 'single',  // Explicitly set underline style
                        'size' => 8,
                        'color' => '000000'      // Ensure black color for visibility
                    ]);
                }

                // Add designation
                $cell->addText($signatory['designation'], [
                    'size' => 7
                ], [
                    'alignment' => Jc::CENTER,
                    'spaceAfter' => 100
                ]);
                } // End of foreach loop
            } // End of else
        } // End of if ($hasSignatories)

        // Add page numbering below signature section
        $footer->addTextBreak(1);
        $footerTable = $footer->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::BOTH,
            'width' => 10800,
            'unit' => 'dxa',
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED
        ]);

        $footerTable->addRow();

        // Left cell for logo (8000 twips of the usable 10800 twip width)
        $left_footer_image = $uploaded_images['footer_left'] ?? null;
        if ($left_footer_image && file_exists($left_footer_image['image_path'])) {
            $width = $left_footer_image['image_width'] ?? 250;
            $height = $left_footer_image['image_height'] ?? 45;
            $footerTable->addCell(8000, ['vAlign' => 'bottom'])->addImage(
                $left_footer_image['image_path'],
                [
                    'width' => $width,
                    'height' => $height,
                    'alignment' => Jc::START
                ]
            );
        } else {
            // Fallback to default footer logo
            $footerTable->addCell(8000, ['vAlign' => 'bottom'])->addImage(
                'uploads/ESSUCFooter.png',
                [
                    'width' => 250,
                    'height' => 45,
                    'alignment' => Jc::START
                ]
            );
        }

        // Right cell for page numbers (remaining 2800 twips)
        $footerTable->addCell(2800, ['vAlign' => 'bottom'])->addPreserveText(
            'Page {PAGE} of {NUMPAGES}',
            [],
            [
                'alignment' => Jc::END,
                'spaceAfter' => 0
            ]
        );

        // Save the document to a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'grades_') . '.docx';
        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tempFile);

        // Discard ANY prior output (e.g. stray whitespace echoed by required
        // files before ob_start): even a single leading byte shifts every ZIP
        // offset and makes Word report "unreadable content".
        while (ob_get_level() > 0) { ob_end_clean(); }

        // Set headers for download
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        // Generate a clean filename with only alphanumeric characters and underscores
        $filename = 'Report_of_Grades_' . preg_replace('/[^a-zA-Z0-9_]/', '_',
            $class['grade'] . '-' . $class['section'] . '_' . $class['subject_name']);

        header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));

        // Output the file and clean up
        readfile($tempFile);
        unlink($tempFile);
        exit;
    }

    // Default response if not a grades export request
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;

} catch (Exception $e) {
    // Clean any output
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Return error response
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Error generating document: ' . $e->getMessage()
    ]);
    exit();
}
?>
