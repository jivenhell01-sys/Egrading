<?php
require_once('DBConnection.php');
require_once('Actions.php');

$action = new Actions();
$conn = new DBConnection();

// Get class_id from URL
$class_id = isset($_GET['class_id']) ? $_GET['class_id'] : 0;
$class_id = intval($class_id);

// Get class information
$class_info = [];
if ($class_id > 0) {
    $class_info = $conn->querySingle("SELECT c.*, s.name as subject_name 
                                   FROM class_list c 
                                   JOIN subjects s ON c.subject_id = s.subject_id 
                                   WHERE c.class_id = '" . $class_id . "'");
}

// Initialize signatories array
$signatories = [];
$hasTypeColumn = false;

// Check if signatories table has type column
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

// Define signatory types and their display titles
$signatoryTypes = [
    'certified' => 'CERTIFIED TRUE AND CORRECT',
    'checked' => 'CHECKED/NOTED BY',
    'received' => 'RECEIVED BY'
];

// Initialize signatories array
$signatories = [];

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
            // Old query without type column
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Report of Grades</title>
    <link rel="stylesheet" href="./Font-Awesome-master/css/all.min.css">
    <link href="./css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 1cm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            margin: 0;
            padding: 20px;
        }
        .signature-section {
            margin-top: 60px;
            page-break-inside: avoid;
        }
        .signature-box {
            border: 1px solid #000;
            min-height: 100px;
            margin-top: 40px;
            padding: 10px;
            position: relative;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 80%;
            margin: 0 auto;
            position: relative;
            top: 30px;
        }
        .signature-label {
            position: absolute;
            bottom: 5px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
        }
        .signature-name {
            font-weight: bold;
            margin-top: 5px;
        }
        .signature-title {
            font-size: 8pt;
            color: #666;
        }
        .btn-export {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <button id="exportWord" class="btn btn-success btn-export">
        <i class="fas fa-file-word"></i> Export to Word
    </button>

    <div id="contentToExport">
        <!-- Header -->
        <div class="text-center mb-4">
            <h3>Report of Grades</h3>
            <div class="mb-2"><?php echo $class_info['semester'] ?? 'N/A'; ?>, School Year: <?php echo $class_info['year'] ?? 'N/A'; ?></div>
            
            <table class="table table-bordered" style="width: 100%; margin-bottom: 20px;">
                <tr>
                    <td style="width: 30%;"><strong>Course Code:</strong></td>
                    <td><?php echo $class_info['course_no'] ?? 'N/A'; ?></td>
                </tr>
                <tr>
                    <td><strong>Subject:</strong></td>
                    <td><?php echo $class_info['subject_name'] ?? 'N/A'; ?></td>
                </tr>
                <tr>
                    <td><strong>Course & Section:</strong></td>
                    <td><?php echo ($class_info['grade'] ?? 'N/A') . ' - ' . ($class_info['section'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td><strong>Schedule:</strong></td>
                    <td><?php echo $class_info['schedule'] ?? 'N/A'; ?></td>
                </tr>
            </table>
        </div>

        <!-- Grading System -->
        <div class="mb-4">
            <table class="table table-bordered" style="width: 100%;">
                <tr>
                    <th colspan="3" class="text-center">GRADING SYSTEM</th>
                </tr>
                <tr>
                    <th class="text-center">Actual Rating</th>
                    <th class="text-center">Equivalent Rating</th>
                    <th class="text-center">Adjectival Rating</th>
                </tr>
                <tr>
                    <td class="text-center">1.0</td>
                    <td class="text-center">95-100</td>
                    <td class="text-center">Excellent</td>
                </tr>
                <tr>
                    <td class="text-center">1.25</td>
                    <td class="text-center">90-94</td>
                    <td class="text-center">Very Good</td>
                </tr>
                <!-- Add more rows as needed -->
            </table>
        </div>

        <!-- Signatures Section -->
        <div style="margin-top: 60px; page-break-inside: avoid;">
            <div style="display: flex; justify-content: space-between;">
                <!-- Certified -->
                <div style="width: 32%; text-align: center;">
                    <div class="pt-4">
                        <h6 class="mb-4" style="font-size: 11px; font-weight: bold; margin: 0 0 15px 0;">CERTIFIED TRUE AND CORRECT:</h6>
                        <div style="min-height: 80px;">
                            <div style="border-bottom: 1px solid #000; width: 90%; height: 50px; margin: 0 auto 5px;">
                                <div style="height: 100%; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px; font-weight: bold;">
                                    <?php echo $signatories['certified']['fullname'] ?? ''; ?>
                                </div>
                            </div>
                            <div style="margin-top: 5px; font-weight: bold; font-size: 10pt;">
                                <?php echo $signatories['certified']['designation'] ?? 'Designation'; ?>
                            </div>
                            <div style="font-size: 9pt; color: #6c757d; margin-top: 5px;">Signature Over Printed Name</div>
                        </div>
                    </div>
                </div>
                
                <!-- Checked/Noted -->
                <div style="width: 32%; text-align: center;">
                    <div class="pt-4">
                        <h6 class="mb-4" style="font-size: 11px; font-weight: bold; margin: 0 0 15px 0;">CHECKED/NOTED BY:</h6>
                        <div style="min-height: 80px;">
                            <div style="border-bottom: 1px solid #000; width: 90%; height: 50px; margin: 0 auto 5px;">
                                <div style="height: 100%; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px; font-weight: bold;">
                                    <?php echo $signatories['checked']['fullname'] ?? ''; ?>
                                </div>
                            </div>
                            <div style="margin-top: 5px; font-weight: bold; font-size: 10pt;">
                                <?php echo $signatories['checked']['designation'] ?? 'Designation'; ?>
                            </div>
                            <div style="font-size: 9pt; color: #6c757d; margin-top: 5px;">Signature Over Printed Name</div>
                        </div>
                    </div>
                </div>
                
                <!-- Received -->
                <div style="width: 32%; text-align: center;">
                    <div class="pt-4">
                        <h6 class="mb-4" style="font-size: 11px; font-weight: bold; margin: 0 0 15px 0;">RECEIVED BY:</h6>
                        <div style="min-height: 80px;">
                            <div style="border-bottom: 1px solid #000; width: 90%; height: 50px; margin: 0 auto 5px;">
                                <div style="height: 100%; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px; font-weight: bold;">
                                    <?php echo $signatories['received']['fullname'] ?? ''; ?>
                                </div>
                            </div>
                            <div style="margin-top: 5px; font-weight: bold; font-size: 10pt;">
                                <?php echo $signatories['received']['designation'] ?? 'Designation'; ?>
                            </div>
                            <div style="font-size: 9pt; color: #6c757d; margin-top: 5px;">
                                <div style="margin-top: 20px;">
                                    <div style="border-bottom: 1px solid #000; width: 80%; height: 25px; margin: 0 auto;">
                                        <div style="text-align: center; padding-top: 5px; font-size: 0.8em;">
                                            Date
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Include required libraries -->
    <script src="./js/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Export to Word
        $('#exportWord').click(function() {
            // Get the HTML content to export
            const content = document.documentElement.outerHTML;
            
            // Create a Blob with the content
            const blob = new Blob(['\ufeff', content], {
                type: 'application/msword;charset=utf-8'
            });
            
            // Generate filename
            const className = '<?php echo ($class_info['grade'] ?? '') . '_' . ($class_info['section'] ?? ''); ?>';
            const semester = '<?php echo $class_info['semester'] ?? ''; ?>';
            const year = '<?php echo $class_info['year'] ?? ''; ?>';
            const filename = 'Grades_Report_' + className + '_' + semester + '_' + year + '.doc';
            
            // Save the file
            saveAs(blob, filename);
        });
    });
    </script>
</body>
</html>