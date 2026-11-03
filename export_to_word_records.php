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

	// DepEd transmuted grades (60-100 scale) pass through untouched.
	if ($equivalent > 5.5) {
		return floatval(number_format($equivalent, 1));
	}

	// If equivalent is 5.0 or above, treat as DROPPED (5.0)
	if ($equivalent > 5.0) {
		return 5.0;
	}

	return floatval(number_format($equivalent, 1));
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
        if ($midpoint <= 50) return 5.5;
        if ($midpoint >= 95) return 1.0;
        
        // Calculate equivalent based on the range
        if ($midpoint <= 75) {
            // Linear interpolation between 50 (5.5) and 75 (3.0)
            $equiv = 5.5 - (($midpoint - 50) / (75 - 50)) * 2.5;
        } else {
            // Linear interpolation between 75 (3.0) and 95 (1.0)
            $equiv = 3.0 - (($midpoint - 75) / (95 - 75)) * 2.0;
        }
        
        // Ensure the equivalent is within bounds
        return max(1.0, min(5.5, $equiv));
    }
}

// Function to get equivalent grade (wrapper for consistency)
function get_equivalent_grade($grade) {
	return get_equivalent_from_transmutation($grade);
}

// Start output buffering
ob_start();

// Set error reporting
ini_set('display_errors', 0);
error_reporting(0);

// Initialize response array
$response = ['status' => 'error', 'message' => ''];

// Debug: Log the GET parameters
error_log('Export debug - GET params: ' . print_r($_GET, true));

try {
    // Check if this is a grades export request
    if (isset($_GET['export']) && $_GET['export'] === 'grades' && isset($_GET['class_id']) && isset($_GET['quarter'])) {
        $class_id = intval($_GET['class_id']);
        
        // Get class details with subject name
        $class = $conn->querySingle("SELECT c.*, s.name as subject_name 
                                   FROM class_list c 
                                   JOIN subjects s ON c.subject_id = s.subject_id 
                                   WHERE c.class_id = '" . $conn->escapeString($class_id) . "'", true);
        
        if (!$class) {
            die('Class not found');
        }
        
        // Get all quarters that have assessments for this class
        $all_quarters = [
            1 => ['name' => 'Prelim', 'key' => 'prelim', 'class' => 'text-primary'],
            2 => ['name' => 'Midterm', 'key' => 'midterm', 'class' => 'text-primary'],
            3 => ['name' => 'Pre-Final', 'key' => 'pre_final', 'class' => 'text-primary'],
            4 => ['name' => 'Final', 'key' => 'final', 'class' => 'text-success']
        ];

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
        
        // Create new PHPWord object
        $phpWord = new PhpWord();
        
        // Set document properties
        $phpWord->getDocInfo()->setCreator('Grading System')
                             ->setLastModifiedBy('Grading System')
                             ->setTitle('Report of Grades')
                             ->setSubject('Report of Grades')
                             ->setDescription('Report of Grades Document')
                             ->setCreated(time())
                             ->setModified(time());
        
        // Add a section with margins
        $section = $phpWord->addSection([
            'pageSizeW' => Converter::inchToTwip(8.5),   // Folio width: 8.5 inches
            'pageSizeH' => Converter::inchToTwip(13),    // Folio height: 13 inches
            'marginLeft' => Converter::cmToTwip(1.27),   // 1 inch = 2.54cm
            'marginRight' => Converter::cmToTwip(1.27),
            'marginTop' => Converter::cmToTwip(0.3),   // 0.3 inch top margin for header
            'marginBottom' => Converter::cmToTwip(0.3),
            'orientation' => 'portrait'
        ]);
        
        // Add header with logos
        $header = $section->addHeader();
        
        $uploaded_images = getUploadedImages($conn);
        
        $headerTable = $header->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::BOTH
            
        ]);
        
        $headerTable->addRow(); // Smaller row height for header (0.1 inch)
        
        // Left header image
        $left_image = $uploaded_images['header_left'] ?? null;
        if ($left_image && file_exists($left_image['image_path'])) {
            $width = $left_image['image_width'] ?? 240;
            $height = $left_image['image_height'] ?? 65;
            $headerTable->addCell(8000, ['valign' => 'top'])->addImage(
                $left_image['image_path'],
                [
                    'width' => $width,
                    'height' => $height,
                    'alignment' => Jc::START
                ]
            );
        } else {
            // Fallback to default logo
            $headerTable->addCell(8000, ['valign' => 'top'])->addImage(
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
            $headerTable->addCell(8000, ['valign' => 'top'])->addImage(
                $right_image['image_path'],
                [
                    'width' => $width,
                    'height' => $height,
                    'alignment' => Jc::END
                ]
            );
        } else {
            // Fallback to default logo
            $headerTable->addCell(8000, ['valign' => 'top'])->addImage(
                'uploads/Bagong Pilipinas.png',
                [
                    'width' => 70,
                    'alignment' => Jc::END
                ]
            );
        }
        
        // Add title
        $section->addText(
            'CLASS RECORDS',
            ['bold' => true, 'size' => 14],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 50]
        );
        
        // Add academic year and semester
        $section->addText(
            ($class['semester']) . ', '. (isset($_GET['quarter']) ? ['Prelim', 'Midterm', 'Pre-Final', 'Final'][$_GET['quarter'] - 1] : 'N/A') . ', ' .'School Year: '. $class['year'],
            ['size' => 11, 'font' => 'Arial', 'italic' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 300]
        );
        
        // Add class and subject info
        $infoTable = $section->addTable([
            'borderSize' => 8,
            'alignment' => Jc::START,
        ]);
        
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
        
        // Create a container table with 2 columns
        $containerTable = $section->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::START,
            'width' => 100,  // 100% width
            'unit' => 'pct'
        ]);
        $containerTable->addRow();
        
        // Left cell for class info
        $leftCell = $containerTable->addCell(12000, ['vAlign' => 'top']);
        
        // Add class info table to left cell
        $rowHeight = 288; // 0.3 inches in twips (0.3 * 1440)
        
        $infoTable = $leftCell->addTable([
            'borderSize' => 8,
            'alignment' => Jc::START,
            'size' => 9,
            'borderColor' => '000000'
        ]);
        
        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2500, $cellStyle)->addText('Subject Code:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' .$class['course_no'], null, $textStyle,['size' => 9]);
        
        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2500, $cellStyle)->addText('Descriptive Title:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' .$class['subject_name'], null, $textStyle,['size' => 9]);
        
        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2500, $cellStyle)->addText('Course/Year/Section:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' .$class['grade'] . ' - ' . $class['section'], null, $textStyle,['size' => 9]);
        
        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2500, $cellStyle)->addText('Units:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' .$class['unit'] ?? 'N/A', null, $textStyle,['size' => 9]);
        
        $infoTable->addRow($rowHeight);
        $infoTable->addCell(2500, $cellStyle)->addText('Schedule:', ['bold' => true,'size' => 9], $textStyle);
        $infoTable->addCell(3000, $cellStyle)->addText(' ' .$class['schedule'] ?? 'N/A', null, $textStyle,['size' => 9]);

       
        
        
        $section->addTextBreak(1);
        
        // Get components and assessments for this class and quarter
        $components = array();
        $components_query = $conn->query("SELECT c.*, g.name FROM `component_subject_percentage` c 
                                        INNER JOIN `grading_components` g ON c.component_id = g.component_id 
                                        WHERE c.subject_id = '{$class['subject_id']}' 
                                        ORDER BY component_id ASC");
        while($row = $components_query->fetchArray()){
            $components[$row['component_id']] = $row;
        }
        
        // Handle active quarters parameter - use same logic as report_of_grades.php
        $all_quarters = [
            1 => ['name' => 'Prelim', 'key' => 'prelim', 'class' => 'text-primary'],
            2 => ['name' => 'Midterm', 'key' => 'midterm', 'class' => 'text-primary'],
            3 => ['name' => 'Pre-Final', 'key' => 'pre_final', 'class' => 'text-primary'],
            4 => ['name' => 'Final', 'key' => 'final', 'class' => 'text-success']
        ];

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

        $assess = array();
        $assess_ids = array();
        
        // Get assessments for all active quarters
        $quarter_ids = !empty($active_quarters) ? implode(',', array_keys($active_quarters)) : '0';
        $assess_query = $conn->query("SELECT * FROM `assessment_list` 
                                     WHERE class_id = '{$class_id}' AND quarter IN ({$quarter_ids}) 
                                     ORDER BY `name` ASC");
        $component_totals = array();
        
        // First pass: Store assessments and calculate total percentage per component
        while($row = $assess_query->fetchArray()){
            $assess[$row['component_id']][$row['assessment_id']] = $row;
            $assess_ids[] = $row['assessment_id'];
            if (!isset($component_totals[$row['component_id']])) {
                $component_totals[$row['component_id']] = 0;
            }
            $component_totals[$row['component_id']] += floatval($row['percentage']);
        }
        
        // Second pass: Calculate weight for each assessment
        foreach ($assess as $component_id => $assessments) {
            $component_total = $component_totals[$component_id] > 0 ? $component_totals[$component_id] : 100;
            foreach ($assessments as $assessment_id => $assessment) {
                $weight = ($assessment['percentage'] / $component_total) * 100;
                $assess[$component_id][$assessment_id]['weight'] = $weight;
            }
        }
        
        // Get marks for all assessments
        $a_ids = !empty($assess_ids) ? implode(",", $assess_ids) : '0';
        $marks = array();
        $marks_qry = $conn->query("SELECT * FROM `mark_list` WHERE assessment_id IN ({$a_ids})");
        while($row = $marks_qry->fetchArray()){
            $marks[$row['student_id']][$row['assessment_id']] = $row['mark'];
        }
        
        // Get component percentages array
        $c_perc_arr = array();
        foreach($components as $row){
            $c_perc_arr[$row['component_id']] = ($row['percentage']/100);
        }
        
        // Calculate total columns for dynamic width scaling
        $total_assessment_columns = 0;
        foreach($components as $k => $v) {
            $total_assessment_columns += isset($assess[$k]) ? count($assess[$k]) + 2 : 3;
        }
        $total_columns = 1 + $total_assessment_columns + 2; // Student + assessments + Initial + Final
        
        // Calculate dynamic base width - scale based on page width (8500 twips for 8.5 inch page with margins)
        $available_width = 8500; // Approximate usable width in twips
        $min_cell_width = 500; // Minimum width for any cell
        $student_width = max(2975, $available_width * 0.35); // Student name gets 25% or minimum 2000
        $grade_width = max(850, $available_width * 0.1); // Grade columns get 8% or minimum 600
        
        // Remaining width for assessment columns
        $remaining_width = $available_width - $student_width - ($grade_width * 2);
        $assessment_width = max($min_cell_width, $remaining_width / $total_assessment_columns);
        
        // Create table for detailed grades (adapted from reports.php)
        $table = $section->addTable([
            'borderSize' => 8,
            'borderColor' => '000000',
            'valign' => 'center',
            'align' => 'center',
            'vAlign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'width' => 100 * 50,  // 100% width but with smaller columns
            'unit' => 'pct',
            'cellMargin' => 50,
            'cellMarginTop' => 20,
            'cellMarginBottom' => 20,
            'cellMarginLeft' => 30,
            'cellMarginRight' => 30
        ]);
        
        $textStyle = ['spaceAfter' => 0, 'spacing' => 0];
        $headerStyle = array_merge($textStyle, [
            'bgColor' => 'FFD700', // Gold background like in reports.php
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0
        ]);
        
        // First header row - Component names and quarter headers
        $table->addRow(300); // Reduced row height
        
        // Create style for top row with thicker borders
        $topRowStyle = array_merge($headerStyle, [
            'borderSize' => 10,
            'borderColor' => '000000'
        ]);
        
        $studentCell = $table->addCell($student_width, $topRowStyle); // Dynamic student column width
        $studentCell->getStyle()->setGridSpan(1);
        $studentCell->getStyle()->setVMerge('restart'); // Start vertical merge
        $studentCell->addText('Student', ['bold' => true, 'size' => 7, 'color' => '006400'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Dark green font
        
        // Add quarter headers for active quarters
        if (!empty($active_quarters)) {
            foreach ($active_quarters as $q_num => $q_data) {
                $quarterCell = $table->addCell(1200, $topRowStyle); // Fixed width for each quarter
                $quarterCell->addText($q_data['name'], ['bold' => true, 'size' => 7, 'color' => '006400'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            }
        } else {
            $noDataCell = $table->addCell(1200, $topRowStyle);
            $noDataCell->addText('No Data Available', ['bold' => true, 'size' => 7, 'color' => '006400'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
        
        $initialGradeCell = $table->addCell($grade_width, $topRowStyle); // Dynamic grade column width
        $initialGradeCell->getStyle()->setVMerge('restart'); // Start vertical merge
        $initialGradeCell->addText('Initial Grade', ['bold' => true, 'size' => 7, 'color' => '006400'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Dark green font
        
        $finalGradeCell = $table->addCell($grade_width, $topRowStyle); // Dynamic grade column width
        $finalGradeCell->getStyle()->setVMerge('restart'); // Start vertical merge
        $finalGradeCell->addText(
            (isset($_GET['quarter']) ? ['Prelim', 'Midterm', 'Pre-Final', 'Final'][$_GET['quarter'] - 1] : 'N/A') . "\nGrade", 
            ['bold' => true, 'size' => 7, 'color' => '006400'], // Dark green font
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
        );
        
        // Second header row - Assessment names
        $table->addRow(250); // Reduced row height
        $studentCell2 = $table->addCell($student_width, $topRowStyle); // Dynamic student column width
        $studentCell2->getStyle()->setVMerge('continue'); // Continue vertical merge
        $studentCell2->addText('', [], ['alignment' => Jc::CENTER]); // Empty cell under Student
        
        foreach($components as $k => $v) {
            if(isset($assess[$k])) {
                foreach($assess[$k] as $row) {
                    $assessmentStyle = array_merge($headerStyle, [
                        'bgColor' => '#00ffff', // Light blue for assessment names
                        'valign' => 'center',
                        'spaceAfter' => 0,
                        'spacing' => 0,
                        'cellMarginTop' => 0,
                        'cellMarginBottom' => 0
                    ]);
                    $table->addCell($assessment_width, $assessmentStyle)->addText( // Reduced from 600
                        substr($row['name'], 0, 8), // Truncate to 8 chars for more compact display
                        ['bold' => true, 'size' => 6], // Bold font
                        ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
                    );
                }
                $totalStyle = array_merge($headerStyle, [
                    'bgColor' => '#FFE5B4', // Orange for Total
                    'valign' => 'center',
                    'spaceAfter' => 0,
                    'spacing' => 0,
                    'cellMarginTop' => 0,
                    'cellMarginBottom' => 0
                ]);
                $table->addCell($assessment_width, $totalStyle)->addText('T', ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Bold font
                
                }
                
                $initialGradeCell2 = $table->addCell($grade_width, $topRowStyle); // Dynamic grade column width
                $initialGradeCell2->getStyle()->setVMerge('continue'); // Continue vertical merge
                $initialGradeCell2->addText('', [], ['alignment' => Jc::CENTER]); // Empty under Initial Grade
        
        $finalGradeCell2 = $table->addCell($grade_width, $topRowStyle); // Dynamic grade column width
        $finalGradeCell2->getStyle()->setVMerge('continue'); // Continue vertical merge
        $finalGradeCell2->addText('', [], ['alignment' => Jc::CENTER]); // Empty under Quarter Grade
        
        // Third header row - HPS (Highest Possible Score)
        $table->addRow(250); // Reduced row height
        $studentCell3 = $table->addCell($student_width, $topRowStyle); // Dynamic student column width
        $studentCell3->getStyle()->setVMerge('continue'); // Continue vertical merge
        $studentCell3->addText('', [], ['alignment' => Jc::CENTER]); // Empty under Student
        
        foreach($components as $k => $v) {
            $total_hps = 0;
            $percentage = $v['percentage'];
            if(isset($assess[$k])) {
                $hpsStyle = array_merge($headerStyle, [
                    'bgColor' => 'F5F5DC', // Light beige for HPS
                    'valign' => 'center',
                    'spaceAfter' => 0,
                    'spacing' => 0,
                    'cellMarginTop' => 0,
                    'cellMarginBottom' => 0
                ]);
                foreach($assess[$k] as $row) {
                    $total_hps += $row['hps'];
                    $table->addCell($assessment_width, $hpsStyle)->addText('/' . $row['hps'], ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Bold font
                }
                $table->addCell($assessment_width, $hpsStyle)->addText($total_hps, ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Bold font
                $table->addCell($assessment_width, $hpsStyle)->addText($percentage . '%', ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Bold font
            } else {
                // Create a single cell that spans 3 columns for "-"
                $noHpsCell = $table->addCell($assessment_width * 3, $headerStyle); // Dynamic width for no HPS
                $noHpsCell->getStyle()->setGridSpan(3);
                $noHpsCell->addText('-', ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]); // Reduced font size
            }
        }
        
        $initialGradeCell3 = $table->addCell($grade_width, $topRowStyle); // Dynamic grade column width
        $initialGradeCell3->getStyle()->setVMerge('continue'); // Continue vertical merge
        $initialGradeCell3->addText('', [], ['alignment' => Jc::CENTER]); // Empty under Initial Grade
        
        $finalGradeCell3 = $table->addCell($grade_width, $topRowStyle); // Dynamic grade column width
        $finalGradeCell3->getStyle()->setVMerge('continue'); // Continue vertical merge
        $finalGradeCell3->addText('', [], ['alignment' => Jc::CENTER]); // Empty under Quarter Grade
        
        // Student data rows
        $studentStyle = array_merge($textStyle, [
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 1,
            'cellMarginBottom' => 1,
            'size' => 6 // Reduced from 7
        ]);
        
        // Define styles for Total and Average columns to match headers
        $totalCellStyle = array_merge($studentStyle, [
            'bgColor' => '#FFE5B4', // Orange for Total column
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 1,
            'cellMarginBottom' => 1
        ]);
        
        $avgCellStyle = array_merge($studentStyle, [
            'bgColor' => 'D3D3D3', // Light grey for Average column
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 1,
            'cellMarginBottom' => 1
        ]);
        
        // Define style for grade columns (Initial and Final)
        $gradeCellStyle = array_merge($studentStyle, [
            'valign' => 'center',
            'spaceAfter' => 0,
            'spacing' => 0,
            'cellMarginTop' => 1,
            'cellMarginBottom' => 1
        ]);
        
        foreach ($students as $student_id => $student) {
            $table->addRow(200); // Reduced row height
            
            // Student name with status - BOLD
            $studentText = $student['name'];
            if ($student['status'] === 'Dropped') {
                $studentText .= ' (D)';
            }
            $studentCell = $table->addCell($student_width, $studentStyle); // Dynamic student column width
            $studentCell->addText($studentText, ['bold' => true, 'size' => 6], ['alignment' => Jc::START, 'spaceAfter' => 0]); // BOLD font
            
            // Quarter columns - get initial grades for each active quarter
            if (!empty($active_quarters)) {
                foreach ($active_quarters as $q_num => $q_data) {
                    // Get the initial grade from the database
                    $initial_grade = $conn->querySingle("SELECT initial_grade FROM student_initial_grade 
                        WHERE student_id = '{$student_id}' 
                        AND class_id = '{$class_id}' 
                        AND quarter = '{$q_num}'");
                    
                    $grade_display = $initial_grade ? number_format($initial_grade, 1) : 'N/A';
                    
                    // Add quarter grade cell
                    $quarterCell = $table->addCell(1200, $studentStyle);
                    $quarterCell->addText($grade_display, ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                }
            } else {
                // No data cell
                $noDataCell = $table->addCell(1200, $studentStyle);
                $noDataCell->addText('N/A', ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            }
            
            // Calculate final grade (average of all active quarters)
            $final_grade = 0;
            $quarter_count = 0;
            
            if (!empty($active_quarters)) {
                foreach ($active_quarters as $q_num => $q_data) {
                    $initial_grade = $conn->querySingle("SELECT initial_grade FROM student_initial_grade 
                        WHERE student_id = '{$student_id}' 
                        AND class_id = '{$class_id}' 
                        AND quarter = '{$q_num}'");
                    
                    if ($initial_grade) {
                        $final_grade += $initial_grade;
                        $quarter_count++;
                    }
                }
                
                if ($quarter_count > 0) {
                    $final_grade = $final_grade / $quarter_count;
                }
            }
            
            // Initial Grade column
            $table->addCell($grade_width, $gradeCellStyle)->addText(number_format($final_grade, 1), ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            
            // Final Grade column with transmutation
            $transmuted_equivalent = get_equivalent_from_transmutation($final_grade);
            $table->addCell($grade_width, $gradeCellStyle)->addText($transmuted_equivalent, ['bold' => true, 'size' => 6], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
        
        // Calculate total columns for grid span
        $total_columns = 1; // Student column
        $total_columns += count($active_quarters); // Quarter columns
        $total_columns += 2; // Initial Grade + Final Grade
        
        // Add a row with 'nothing' as content spanning all columns
        $table->addRow(288, [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
        ]);
        $mergedCell = $table->addCell(14000, $studentStyle);
        $mergedCell->getStyle()->setGridSpan($total_columns);
        $mergedCell->addText('***Nothing as follows***', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'size' => 7]);
        
        // Add a top border for the signature section
        $section->addText('', [], [
            'borderBottom' => [
                'borderBottomSize' => 1,
                'borderBottomColor' => '000000',
                'spaceAfter' => 500
            ]
        ]);
        
        // Create a table for the three-column signature section
        $signatureTable = $section->addTable([
            'borderSize' => 0,
            'cellMargin' => 200,
            'alignment' => Jc::BOTH,
            'width' => 100 * 50,  // 100% width
            'unit' => 'pct',
            'borderColor' => 'FFFFFF'
        ]);
        
        // Add a row for the signature columns
        $signatureTable->addRow();
        
        // Define signatory types and their display titles
        $signatoryTypes = [
            'certified' => 'Certified true and correct',
            'checked' => 'Checked by',
            'noted' => 'Noted by'
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
            // Output each signatory column
            foreach ($signatoryTypes as $type => $title) {
                $signatory = $signatories[$type] ?? null;
                
                // Skip this signatory type if no data exists for it
                if (!$signatory) continue;
                
                // Create cell for each signatory
                $cell = $signatureTable->addCell(3333, [  // 33.33% width for each column
                    'valign' => 'top',
                    'align' => 'center'
                ]);
            
                // Add title
                $cell->addText($title . ':', [
                    'bold' => true,
                    'size' => 9
                ], [
                    'spaceAfter' => 500,
                    'alignment' => Jc::START
                ]);
                
               
                
                if (!empty($signatory['fullname'])) {
                    $textRun = $cell->addTextRun([
                        'alignment' => Jc::CENTER,
                        'spaceAfter' => 30
                    ]);
                    $textRun->addText($signatory['fullname'], [
                        'bold' => true,
                        'underline' => 'single',  // Explicitly set underline style
                        'size' => 9,
                        'color' => '000000'      // Ensure black color for visibility
                    ]);
                }
                
                // Add designation
                $cell->addText($signatory['designation'], [
                    'size' => 8
                ], [
                    'alignment' => Jc::CENTER,
                    'spaceAfter' => 30
                ]);
            } // End of foreach loop
        } // End of if ($hasSignatories)
        
        // Add footer with a two-column table
        $footer = $section->addFooter();
        $footerTable = $footer->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'alignment' => Jc::BOTH
        ]);
        
        $footerTable->addRow();
        
        // Left cell for logo
        $footerTable->addCell(9000, ['vAlign' => 'bottom'])->addImage(
            'uploads/ESSUCFooter.png',
            [
                'width' => 250,
                'height' => 40,
                'alignment' => Jc::START
            ]
        );
        
        // Right cell for page numbers
        $footerTable->addCell(9000, ['vAlign' => 'bottom'])->addPreserveText(
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
        }
        
        // Set headers for download
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        // Generate a clean filename with only alphanumeric characters and underscores
        $filename = 'Class_Records_' . preg_replace('/[^a-zA-Z0-9_]/', '_', 
            $class['grade'] . '-' . $class['section'] . '_' . $class['subject_name']);
            
        header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));
        
        // Output the file
        
// Right cell for page numbers
$footerTable->addCell(9000, ['vAlign' => 'bottom'])->addPreserveText(
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
}
        
// Set headers for download
header('Content-Description: File Transfer');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
// Generate a clean filename with only alphanumeric characters and underscores
$filename = 'Class_Records_' . preg_replace('/[^a-zA-Z0-9_]/', '_', 
    $class['grade'] . '-' . $class['section'] . '_' . $class['subject_name']);
            
header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($tempFile));
        
// Output the file
readfile($tempFile);
        
// Delete the temporary file
unlink($tempFile);
exit;
    
    // Default response if not a grades export request
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid request - export parameter not found']);
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