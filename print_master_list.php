<?php
require_once('DBConnection.php');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// Get selected section from URL parameter
$selected_section = isset($_GET['section']) ? $_GET['section'] : '';

// Get all sections
$db = new DBConnection();
$sections = $db->query("SELECT DISTINCT (grade || ' - ' || section) as section FROM `class_list` ORDER BY grade, section");

// If a section is selected, filter the sections
if (!empty($selected_section)) {
    $sections = $db->query("SELECT DISTINCT (grade || ' - ' || section) as section FROM `class_list` WHERE (grade || ' - ' || section) = '" . $db->escapeString($selected_section) . "' ORDER BY grade, section");
}

// Get school information
$school_info = [];
$meta = $db->query("SELECT * FROM `header_footer_settings` WHERE id = 1");
if($meta && $meta->fetchArray(SQLITE3_ASSOC)) {
    $meta->reset();
    $school_info = $meta->fetchArray(SQLITE3_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master List of Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page {
            margin: 1cm !important;
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.25;
        }
        .print-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #333;
        }
        .print-header h2, .print-header h3 {
            margin: 5px 0;
        }
        .section-title {
            background-color: #f5f5f5;
            padding: 8px 15px;
            margin: 20px 0 10px 0;
            font-weight: bold;
        }
        /* Fixed layout so the colgroup widths below are actually honoured,
           otherwise the browser auto-sizes and the long Name/Email text
           squeezes the other columns. Rows then grow to fit wrapped content. */
        .student-table {
            width: 100% !important;
            table-layout: fixed;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .student-table th, .student-table td {
            border: 1px solid #dee2e6;
            padding: 4px 6px;
            text-align: left;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            vertical-align: top;
            line-height: 1.2;
            font-size: 11px;
        }
        .student-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            font-size: 12px;
            text-align: center;
            vertical-align: middle;
        }
        .student-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        /* Repeat the header on every printed page */
        .student-table thead { display: table-header-group; }
        .student-table tr { page-break-inside: avoid; break-inside: avoid; }
        .no-print {
            display: none;
        }
        .print-footer {
            margin-top: 30px;
            
            text-align: right !important;
        }
        .print-date {
            text-align: right;
            margin-bottom: 20px;
            color: #666;
        }
    
        @media print {
            @page {
                margin: 1cm !important;
            }
            /* Column widths, table-layout and row sizing are inherited from the
               base rules above - only print-specific overrides belong here, or
               they would undo the colgroup sizing. */
            .container-fluid {
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body style="width:100% !important;">
    <div class="container-fluid" style="width:100% !important; padding:0 !important; margin:0 !important;">
        <!-- School Header -->
        <div class="print-header">
            <?php if(!empty($school_info['header_image_path']) && file_exists($school_info['header_image_path'])): ?>
                <img src="<?php echo $school_info['header_image_path']; ?>" alt="School Header" style="max-width: 100%; max-height: 100px; margin-bottom: 15px;">
            <?php else: ?>
                <h6 style="font-weight:bold;"><?php echo !empty($school_info['school_name']) ? htmlspecialchars($school_info['school_name']) : 'Eastern Samar State University-Canavid Campus'; ?></h6>
                <h7><?php echo !empty($school_info['school_address']) ? htmlspecialchars($school_info['school_address']) : 'Brgy. 10 Poblacion, Can-avid, Eastern Samar'; ?></h7>
              
            <?php endif; ?>
        </div>
  <h6>MASTER LIST OF STUDENTS</h6>
        <div class="print-date">
            <?php echo date('F d, Y'); ?>
        </div>

        <?php 
        // Process each section
        while($section = $sections->fetchArray(SQLITE3_ASSOC)): 
            $section_name = $section['section'];
            
            // Get students in this section
            $stmt = $db->prepare("SELECT student_number, name, birthdate, contact_number, email, barangay, municipality, province, zipcode, gender, civil_status, family_status, special_sector
                                 FROM student_list 
                                 WHERE class_id IN (SELECT class_id FROM class_list WHERE (grade || ' - ' || section) = :section)
                                 ORDER BY name");
            $stmt->bindValue(':section', $section_name, SQLITE3_TEXT);
            $students = $stmt->execute();
            
            // Count students
            $stmt_count = $db->prepare("SELECT COUNT(*) as count 
                                       FROM student_list 
                                       WHERE class_id IN (SELECT class_id FROM class_list WHERE (grade || ' - ' || section) = :section)");
            $stmt_count->bindValue(':section', $section_name, SQLITE3_TEXT);
            $count_result = $stmt_count->execute()->fetchArray(SQLITE3_ASSOC);
            $student_count = $count_result['count'];
            
            if ($student_count > 0):
        ?>
            <div class="section-container">
                <div class="section-title">
                    <?php echo htmlspecialchars($section_name); ?> (<?php echo $student_count; ?> Students)
                </div>
                
                <table class="student-table">
                    <?php
                    // Explicit column widths. Name gets the most room, Email is
                    // deliberately narrow (addresses wrap instead of stealing
                    // width from the other columns). With table-layout:fixed
                    // these are honoured exactly and rows auto-grow to fit.
                    ?>
                    <colgroup>
                        <col style="width: 3%;">                                   <!-- # -->
                        <col style="width: 17%;">                                  <!-- Name (widest) -->
                        <col style="width: 7%;">                                   <!-- Stud. No -->
                        <col style="width: 6%;">                                   <!-- B-date -->
                        <col style="width: 7%;">                                   <!-- Phone No. -->
                        <col style="width: 8%;">                                   <!-- Email (narrow - wraps) -->
                        <col style="width: 8%;">                                   <!-- Barangay -->
                        <col style="width: 7%;">                                   <!-- Municipality -->
                        <col style="width: 6%;">                                   <!-- Province -->
                        <col style="width: 5%;">                                   <!-- Zipcode -->
                        <col style="width: 6%;">                                   <!-- Gender -->
                        <col style="width: 7%;">                                   <!-- Civil / Marital Status -->
                        <col style="width: 7%;">                                   <!-- Family / Household Status -->
                        <col style="width: 6%;">                                   <!-- Special Sector -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">#</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">Name</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">Stud. No</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">B-date</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">Phone No.</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">Email</th>
                            <th style="text-align: center; font-size: 12px;" colspan="3">Address</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">Zipcode</th>
                            <th style="text-align: center; font-size: 12px;" rowspan="2">Gender</th>
                            <th style="text-align: center; font-size: 12px;" colspan="3">Additional Personal Information</th>
                        </tr>
                        <tr>
                            <th style="text-align: center; font-size: 12px;">Barangay</th>
                            <th style="text-align: center; font-size: 12px;">Municipality</th>
                            <th style="text-align: center; font-size: 12px;">Province</th>
                            <th style="text-align: center; font-size: 12px;">Civil / Marital Status</th>
                            <th style="text-align: center; font-size: 12px;">Family / Household Status</th>
                            <th style="text-align: center; font-size: 12px;">Special Sector / Target Group</th>
                        </tr>
                        
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        while($student = $students->fetchArray(SQLITE3_ASSOC)): 
                        ?>
                            <tr >
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo $counter++; ?></td>
                                <td style="text-align: left; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['name']); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['student_number']); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo $student['birthdate'] ? date('m/d/Y', strtotime($student['birthdate'])) : ''; ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['contact_number'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['email'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['barangay'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['municipality'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['province'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['zipcode'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['gender'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['civil_status'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['family_status'] ?? ''); ?></td>
                                <td style="text-align: center; font-size: 11px; white-space: normal;"><?php echo htmlspecialchars($student['special_sector'] ?? ''); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if($counter % 30 == 1): ?>
                <div style="page-break-after: always;"></div>
            <?php endif; ?>
            
        <?php 
            endif; // End if student_count > 0
        endwhile; // End foreach section 
        ?>
        
        <div class="print-footer">
            <div class="row mt-1">
                <?php
                // Fetch the certified signatory
                $certifiedSignatory = false;
                try {
                    // First try to get the certified signatory if type column exists
                    $result = $db->query("PRAGMA table_info(signatories)");
                    $hasTypeColumn = false;
                    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
                        if ($col['name'] === 'type') {
                            $hasTypeColumn = true;
                            break;
                        }
                    }
                    
                    if ($hasTypeColumn) {
                        // Get certified signatory by type
                        $result = $db->query("
                            SELECT * FROM `signatories` 
                            WHERE `type` = 'certified' 
                            ORDER BY `date_created` DESC 
                            LIMIT 1");
                    } else {
                        // Fallback to the first signatory if no type column
                        $result = $db->query("
                            SELECT * FROM `signatories` 
                            ORDER BY `date_created` DESC 
                            LIMIT 1");
                    }
                    
                    if ($result) {
                        $certifiedSignatory = $result->fetchArray(SQLITE3_ASSOC);
                    }
                } catch (Exception $e) {
                    error_log("Error fetching certified signatory: " . $e->getMessage());
                }
                ?>
                <div class="col-12">
                    <div class="pt-1" style="margin-left: 50px;">
                        <h6 class="mb-4" style="font-size: 12px; font-weight: bold; text-align: left;">CERTIFIED TRUE AND CORRECT:</h6>
                        <div style="min-height: 80px; max-width: 400px;">
                            <div style="height: 100%; display: flex; align-items: flex-end; padding-bottom: 5px; font-weight: bold; font-size: 11px; margin-left:9.5rem; margin-top:1.5rem">
                                    <?php echo $certifiedSignatory ? htmlspecialchars($certifiedSignatory['fullname']) : ''; ?>
                                </div>
                            <div style="border-bottom: 1px solid #000; width: 40%; margin-left:8rem;">
                                
                            </div>
                            <div style="margin-top: 5px; font-weight: bold; font-size: 9pt; text-align: center; padding-left: 20px;">
                                <?php echo $certifiedSignatory ? htmlspecialchars($certifiedSignatory['designation']) : 'Adviser/Teacher'; ?>
                            </div>
                            <div style="font-size: 8pt; color: #6c757d; margin-top: 5px; text-align: center; padding-left: 20px;">
                                Signature Over Printed Name
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
