<?php
require_once 'vendor/autoload.php';
require_once 'DBConnection.php';
require_once 'Actions.php';
require_once 'class.UploadedImages.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

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
    $equivalent = 5.0; // Default numeric value if no range matches

    while ($row = $transmutation_query->fetchArray(SQLITE3_ASSOC)) {
        $from = floatval($row['from']);
        $to = floatval($row['to']);
        
        if ($grade >= $from && $grade <= $to) {
            $equivalent = floatval($row['grade']);
            break;
        }
    }
    
    return floatval(number_format($equivalent, 1));
}

// Function to convert transmuted grade to equivalent (1.0-5.0 scale)
function transmuted_to_equivalent($from, $to = null) {
    if ($to === null) {
        $to = $from;
    }
    
    if ($from >= 97 && $to <= 100) return 1.0;
    if ($from >= 94 && $to <= 96) return 1.25;
    if ($from >= 91 && $to <= 93) return 1.5;
    if ($from >= 89 && $to <= 90) return 1.75;
    if ($from >= 86 && $to <= 88) return 2.0;
    if ($from >= 83 && $to <= 85) return 2.25;
    if ($from >= 80 && $to <= 82) return 2.5;
    if ($from >= 77 && $to <= 79) return 2.75;
    if ($from >= 75 && $to <= 76) return 3.0;
    if ($from >= 72 && $to <= 74) return 3.25;
    if ($from >= 70 && $to <= 71) return 3.5;
    if ($from >= 67 && $to <= 69) return 3.75;
    if ($from >= 64 && $to <= 66) return 4.0;
    if ($from >= 60 && $to <= 63) return 4.25;
    if ($from >= 55 && $to <= 59) return 4.5;
    if ($from >= 50 && $to <= 54) return 4.75;
    if ($from >= 0 && $to <= 49) return 5.0;
    
    return 5.0; // Default
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
error_log('Excel Export debug - GET params: ' . print_r($_GET, true));

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

        // Get all students in this class with their student numbers
        $students_query = $conn->query("SELECT s.*, sl.student_number 
                                       FROM students s 
                                       LEFT JOIN student_list sl ON s.student_id = sl.student_id 
                                       WHERE sl.class_id = $class_id 
                                       ORDER BY s.name ASC");
        
        $students = [];
        while($row = $students_query->fetchArray(SQLITE3_ASSOC)){
            $students[$row['student_id']] = $row;
        }

        // Create new Spreadsheet object
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator('Grading System')
            ->setLastModifiedBy('Grading System')
            ->setTitle('Class Records - ' . $class['grade'] . '-' . $class['section'] . ' ' . $class['subject_name'])
            ->setSubject('Class Records')
            ->setDescription('Generated class records for ' . $class['grade'] . '-' . $ $class['section'] . ' ' . $class['subject_name']);

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(30); // Student Name
        $sheet->getColumnDimension('B')->setWidth(15); // Student Number
        
        // Set quarter column widths
        $quarter_col = 'C';
        foreach ($active_quarters as $q_num => $q_data) {
            $sheet->getColumnDimension($quarter_col)->setWidth(12);
            $quarter_col++;
        }
        
        $sheet->getColumnDimension($quarter_col)->setWidth(12); // Initial Grade
        $sheet->getColumnDimension(++$quarter_col)->setWidth(12); // Final Grade

        // Create header styles
        $headerStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => '006400']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'FFD700']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];

        // Create data styles
        $dataStyle = [
            'font' => [
                'size' => 10
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];

        // Add headers
        $row = 1;
        $sheet->setCellValue('A' . $row, 'Student Name');
        $sheet->setCellValue('B' . $row, 'Student Number');
        
        // Add quarter headers
        $col = 'C';
        foreach ($active_quarters as $q_num => $q_data) {
            $sheet->setCellValue($col . $row, $q_data['name']);
            $col++;
        }
        
        $sheet->setCellValue($col . $row, 'Initial Grade');
        $sheet->setCellValue(++$col . $row, 'Final Grade');

        // Apply header style to all header cells
        $headerRange = 'A1:' . $col . '1';
        $sheet->getStyle($headerRange)->applyFromArray($headerStyle);

        // Add student data
        $row = 2;
        foreach ($students as $student_id => $student) {
            $col = 'A';
            
            // Student name
            $studentText = $student['name'];
            if ($student['status'] === 'Dropped') {
                $studentText .= ' (D)';
            }
            $sheet->setCellValue($col . $row, $studentText);
            $col++;
            
            // Student number
            $sheet->setCellValue($col . $row, $student['student_number'] ?? 'N/A');
            $col++;
            
            // Quarter grades
            if (!empty($active_quarters)) {
                foreach ($active_quarters as $q_num => $q_data) {
                    $initial_grade = $conn->querySingle("SELECT initial_grade FROM student_initial_grade 
                        WHERE student_id = '{$student_id}' 
                        AND class_id = '{$class_id}' 
                        AND quarter = '{$q_num}'");
                    
                    $grade_display = $initial_grade ? number_format($initial_grade, 1) : 'N/A';
                    $sheet->setCellValue($col . $row, $grade_display);
                    $col++;
                }
            } else {
                $sheet->setCellValue($col . $row, 'N/A');
                $col++;
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
            
            // Initial Grade
            $sheet->setCellValue($col . $row, number_format($final_grade, 1));
            $col++;
            
            // Final Grade with transmutation
            $transmuted_equivalent = get_equivalent_from_transmutation($final_grade);
            $sheet->setCellValue($col . $row, $transmuted_equivalent);

            // Apply data style to the entire row
            $rowRange = 'A' . $row . ':' . $col . $row;
            $sheet->getStyle($rowRange)->applyFromArray($dataStyle);
            
            $row++;
        }

        // Auto-size rows for better readability
        foreach ($sheet->getRowIterator() as $row) {
            $sheet->getRowDimension($row->getRowIndex())->setRowHeight(-1);
        }

        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'grades_') . '.xlsx';
        
        // Save the spreadsheet
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);
        
        // Set headers for download
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        
        // Generate a clean filename
        $filename = 'Class_Records_' . preg_replace('/[^a-zA-Z0-9_]/', '_', 
            $class['grade'] . '-' . $class['section'] . '_' . $class['subject_name']);
            
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
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
    }
    
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
        'message' => 'Error generating Excel document: ' . $e->getMessage()
    ]);
    exit();
}
?>
