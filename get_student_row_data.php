<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

include 'DBConnection.php';

// Transmutation function - moved to top to avoid undefined function errors
function get_equivalent_from_transmutation($grade) {
    global $conn;
    
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
        return number_format($equivalent, 1);
    }

    // If equivalent is above 5.0 (but matched a range), show 5.0;
    // the 5.5 default (no range matched) means DROPPED.
    if ($equivalent > 5.0 && $equivalent != 5.5) {
        return '5.0';
    } elseif ($equivalent === 5.5) {
        return 'DR';
    }

    return number_format($equivalent, 1);
}

// Raw component score rescaled onto a 55-95 band: (score / total items) * 40 + 55
// Must stay in sync with get_scaled_component_raw() in reports_content.php so a
// refreshed AJAX row shows the same value as the initial page render.
function get_scaled_component_raw($score, $total_items) {
    $total_items = floatval($total_items);
    if ($total_items <= 0) {
        return 0.0;
    }
    $score = floatval($score);
    return (($score / $total_items) * 40) + 55;
}

// Weighted Percentage column = scaled raw score x component percentage as a decimal
function get_weighted_percentage($score, $total_items, $component_percentage) {
    $raw = get_scaled_component_raw($score, $total_items);
    return $raw * (floatval($component_percentage) / 100);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Cast before use: these arrive from POST and are interpolated straight
    // into SQL and into the data-* attributes of the HTML below.
    $student_id = (int)($_POST['student_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $rawQuarter = trim((string)($_POST['quarter'] ?? ''));
    // 'active' is the only non-numeric quarter the report page ever sends.
    $quarter = ($rawQuarter === 'active') ? 'active' : (string)(int)$rawQuarter;
    if ($student_id <= 0 || $class_id <= 0 || $quarter === '' || $quarter === '0') {
        echo json_encode(['status' => 'error', 'message' => 'student_id, class_id and quarter are required']);
        exit;
    }
    
    // Check if this is active quarters request
    $is_active_quarters = ($quarter === 'active');
    
    try {
        // Get student information
        $student = $conn->query("SELECT * FROM `student_list` WHERE student_id = '$student_id'")->fetchArray();
        
        // Get the same data that reports.php uses
        // First get class info to get subject_id
        $class_query = $conn->query("SELECT * FROM `class_list` WHERE class_id = '$class_id'")->fetchArray();
        $subject_id = $class_query['subject_id'];
        
        // MUST match reports_content.php exactly, including the
        // `percentage > 0` filter. Without it this endpoint keeps components
        // the page render drops, so the refreshed row grew a phantom
        // "No assessments for this component" cell for a component that is
        // not even in the table - shifting every column after it.
        $components_query = $conn->query("SELECT c.*,g.name FROM `component_subject_percentage` c inner join `grading_components` g on c.component_id = g.component_id where c.subject_id = '$subject_id' AND CAST(c.percentage AS REAL) > 0 order by component_id asc ");
        $components = array();
        while($row = $components_query->fetchArray()){
            $components[$row['component_id']] = $row;
        }
        
        // Calculate component percentage array like in reports.php
        $c_perc_arr = array();
        foreach($components as $row) {
            $c_perc_arr[$row['component_id']] = ($row['percentage'] / 100);
        }
        
        // Handle active quarters differently
        if ($is_active_quarters) {
            // Query to find active quarters (quarters with assessments)
            $active_quarters_query = $conn->query("SELECT DISTINCT quarter FROM `assessment_list` where class_id = '" . $class_id . "' ORDER BY quarter");
            $active_quarters = array();
            if ($active_quarters_query) {
                while($row = $active_quarters_query->fetchArray(SQLITE3_ASSOC)){
                    $active_quarters[] = $row['quarter'];
                }
            }
            
            $assessments = $conn->query("SELECT * FROM `assessment_list` where class_id = '" . $class_id . "' AND quarter IN (" . implode(',', $active_quarters) . ") order by quarter, `name` asc ");
        } else {
            $assessments = $conn->query("SELECT * FROM `assessment_list` WHERE class_id = '$class_id' and quarter = '$quarter' ORDER BY `name` ASC");
        }
        
        $assess = array();
        $component_totals = array(); // To store total percentage for each component
        
        // First pass: Store assessments and calculate total percentage per component
        while($row = $assessments->fetchArray()){
            if ($is_active_quarters) {
                // Organize by component -> quarter -> assessment for active quarters view
                $assess[$row['component_id']][$row['quarter']][$row['assessment_id']] = $row;
            } else {
                // Original structure for single quarter view
                $assess[$row['component_id']][$row['assessment_id']] = $row;
            }
            // Initialize component total if not set
            if (!isset($component_totals[$row['component_id']])) {
                $component_totals[$row['component_id']] = 0;
            }
            // Add to component total (ensure percentage is treated as a number)
            $component_totals[$row['component_id']] += floatval($row['percentage']);
        }
        
        // Second pass: Calculate weight for each assessment
        foreach ($assess as $component_id => $assessments_data) {
            $component_total = $component_totals[$component_id] > 0 ? $component_totals[$component_id] : 100; // Avoid division by zero
            
            if ($is_active_quarters) {
                // Handle active quarters structure: component -> quarter -> assessment
                foreach ($assessments_data as $quarter_num => $quarter_assessments) {
                    foreach ($quarter_assessments as $assessment_id => $assessment) {
                        // Calculate weight as a percentage of the component's total percentage
                        $weight = ($assessment['percentage'] / $component_total) * 100;
                        $assess[$component_id][$quarter_num][$assessment_id]['weight'] = $weight;
                    }
                }
            } else {
                // Handle single quarter structure: component -> assessment
                foreach ($assessments_data as $assessment_id => $assessment) {
                    // Calculate weight as a percentage of the component's total percentage
                    $weight = ($assessment['percentage'] / $component_total) * 100;
                    $assess[$component_id][$assessment_id]['weight'] = $weight;
                }
            }
        }
        
        // Get marks for this student
        $marks_query = $conn->query("SELECT * FROM `mark_list` WHERE student_id = '$student_id'");
        $marks = array();
        while($row = $marks_query->fetchArray()){
            $marks[$row['assessment_id']] = $row['mark'];
        }
        
        // Get student status - also check student_list for direct status
        $student_status = array();
        
        // First check if student has status in student_list (primary source)
        $student_direct_status = $conn->query("SELECT status FROM `student_list` WHERE student_id = '$student_id'")->fetchArray();
        if ($student_direct_status && !empty($student_direct_status['status'])) {
            $student_status[$student_id] = $student_direct_status['status'];
        } else {
            // Fallback to student_grades table
            if ($is_active_quarters) {
                // For active quarters, get status from all active quarters
                foreach ($active_quarters as $q) {
                    $status_query = $conn->query("SELECT * FROM `student_grades` WHERE class_id = '$class_id' and quarter = '$q' AND student_id = '$student_id'");
                    $row = $status_query->fetchArray();
                    if ($row && !empty($row['status'])) {
                        $student_status[$student_id] = $row['status'];
                        break; // Use first found status
                    }
                }
            } else {
                $status_query = $conn->query("SELECT * FROM `student_grades` WHERE class_id = '$class_id' and quarter = '$quarter' AND student_id = '$student_id'");
                $row = $status_query->fetchArray();
                if ($row && !empty($row['status'])) {
                    $student_status[$student_id] = $row['status'];
                }
            }
        }
        
        // Generate the same HTML structure as reports.php
        $initial = 0;
        ob_start();

        // Determine student status for display
        $current_status = isset($student_status[$student_id]) ? $student_status[$student_id] : 'Active';
        $row_class = '';
        if ($current_status === 'Dropped') {
            $row_class = ' status-dropped';
        } elseif ($current_status === 'NoSub') {
            $row_class = ' status-nosub';
        } elseif ($current_status === 'NoExam') {
            $row_class = ' status-noexam';
        }

        // Status accent color for the sticky student cell's right border
        // (mirrors the logic in reports_content.php)
        $border_color = '#dee2e6';
        if ($current_status === 'Dropped') {
            $border_color = '#b91c1c';
        } elseif ($current_status === 'NoSub') {
            $border_color = '#b45309';
        } elseif ($current_status === 'NoExam') {
            $border_color = '#0369a1';
        }
        ?>
        <tr data-student-id="<?php echo $student_id; ?>" data-student-status="<?php echo htmlspecialchars($current_status); ?>" class="rq-row<?php echo $row_class; ?>">
            <td class="py-0 px-1 sticky-col rq-student" style="position: sticky; left: 0; z-index: 5; border-right: 2px solid <?php echo $border_color; ?>;">
                <span class="rq-student-name"><?php echo $student['name'] ?></span>
                <?php if($current_status === 'Dropped'): ?>
                    <span class="badge rq-badge rq-badge-dropped">Dropped</span>
                <?php elseif($current_status === 'NoSub'): ?>
                    <span class="badge rq-badge rq-badge-nosub">No Submissions</span>
                <?php elseif($current_status === 'NoExam'): ?>
                    <span class="badge rq-badge rq-badge-noexam">No Exam</span>
                <?php endif; ?>
            </td>
        <?php
        if ($is_active_quarters) {
            // Active quarters calculations
            $final_grade = 0;
            $total_weighted_score = 0;
            $total_percentage = 0;
            
            foreach($components as $component_id => $component):
                foreach($active_quarters as $q_num):
                    if(isset($assess[$component_id][$q_num])):
                        $quarter_total_hps = 0;
                        $quarter_total_score = 0;
                        $quarter_any_value = false;
                        $quarter_has_graded = false;
                        foreach($assess[$component_id][$q_num] as $assessment):
                            // Blank = "not yet graded" (no mark_list row). Arithmetic
                            // still uses 0; only the rendered cell stays empty. Must
                            // match reports_content.php or the refreshed row would
                            // disagree with the initial page render.
                            $mark_has_value = isset($marks[$assessment['assessment_id']]);
                            $mark = $mark_has_value ? $marks[$assessment['assessment_id']] : 0;
                            $mark_display = $mark_has_value ? $mark : '';
                            if ($mark_has_value) { $quarter_any_value = true; }
                            if ($mark_has_value && trim((string)$marks[$assessment['assessment_id']]) !== '') { $quarter_has_graded = true; }
                            // A blank's HPS counts in the denominator too. Must match
                            // reports_content.php or this refreshed row would disagree
                            // with the initial page render.
                            $quarter_total_hps += $assessment['hps'];
                            $quarter_total_score += $mark;
                    ?>
                            <td class="py-0 px-1 text-center editable-mark rq-mark"
                                title="Assessment Score"
                                data-student-id="<?php echo $student_id; ?>"
                                data-assessment-id="<?php echo $assessment['assessment_id']; ?>"
                                data-class-id="<?php echo $class_id; ?>"
                                data-quarter="<?php echo $q_num; ?>"
                                contenteditable="<?php echo ($current_status === 'Dropped') ? 'false' : 'true'; ?>"><?php echo $mark_display ?></td>
                    <?php 
                        endforeach;
                        if ($quarter_has_graded) {
                            $quarter_avg = get_scaled_component_raw($quarter_total_score, $quarter_total_hps);
                            $quarter_weighted = get_weighted_percentage($quarter_total_score, $quarter_total_hps, $component['percentage']);
                        } else {
                            // No graded work at all: contributes 0, exactly as before.
                            $quarter_avg = 0.0;
                            $quarter_weighted = 0.0;
                        }
                        $total_weighted_score += $quarter_weighted;
                        $total_percentage += $component['percentage'];
                    ?>
                            <td class="py-0 px-1 text-center rq-total"><?php echo number_format($quarter_any_value ? $quarter_total_score : 0, 2) ?></td>
                            <td class="py-0 px-1 text-center rq-weighted"><?php echo number_format($quarter_any_value ? $quarter_weighted : 0, 2) ?></td>
                    <?php else: ?>
                            <td class="py-0 px-1 text-center rq-none" colspan="2">
                                <small>No assessments</small>
                            </td>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            
            <!-- Quarter-wise Transmuted Grade Columns -->
            <?php foreach($active_quarters as $q_num): ?>
                <?php
                // Calculate quarter-specific grade
                $quarter_total_weighted = 0;
                $quarter_total_percentage = 0;
                $has_zero_q = false;
                foreach($components as $component_id => $component):
                    if(isset($assess[$component_id][$q_num])):
                        $quarter_total_hps = 0;
                        $quarter_total_score = 0;
                        $quarter_has_blank = false;
                        $quarter_has_graded_b = false;
                            foreach($assess[$component_id][$q_num] as $assessment):
                            $__qr_mark = $marks[$assessment['assessment_id']] ?? null;
                            if ($__qr_mark === null || trim((string)$__qr_mark) === '') { $quarter_has_blank = true; }
                            else { $quarter_has_graded_b = true; }
                            $mark = isset($marks[$assessment['assessment_id']]) ? $marks[$assessment['assessment_id']] : 0;
                            $quarter_total_score += $mark;
                            // A blank's HPS counts in the denominator too.
                            $quarter_total_hps += $assessment['hps'];
                        endforeach;
                        if ($quarter_has_graded_b) {
                            $quarter_avg = get_scaled_component_raw($quarter_total_score, $quarter_total_hps);
                            $quarter_weighted = get_weighted_percentage($quarter_total_score, $quarter_total_hps, $component['percentage']);
                        } else {
                            // No graded work at all: contributes 0, exactly as before.
                            $quarter_avg = 0.0;
                            $quarter_weighted = 0.0;
                        }
                        $quarter_total_weighted += $quarter_weighted;
                        $quarter_total_percentage += $component['percentage'];
                        // Only a BLANK under a 30%+ component feeds INC. An explicitly
                        // encoded 0 is failed work (5.0 through the raw grade).
                        if($quarter_has_blank && ($component['percentage'] ?? 0) >= 30){
                            $has_zero_q = true;
                        }
                    endif;
                endforeach;
                
                $quarter_grade = $quarter_total_percentage > 0 ? ($quarter_total_weighted / $quarter_total_percentage) * 100 : 0;
                $quarter_transmuted = get_equivalent_from_transmutation($quarter_grade);
                $quarter_transmuted_num = is_numeric($quarter_transmuted) ? (float)$quarter_transmuted : 0;
                // INC rule: a BLANK mark under a 30%+ component plus a raw
                // quarter grade in [40, 55.49] => INC. An explicitly encoded
                // 0 never triggers INC on its own.
                if($quarter_grade >= 40 && $quarter_grade <= 55.49 && $has_zero_q && $current_status !== 'Dropped'){
                    $quarter_transmuted = 'INC';
                    $quarter_transmuted_num = 0;
                }
                
                // Professional pill colors for quarter grades
                if ($quarter_transmuted === 'INC') {
                    $quarter_pill_style = 'color:#9a3412;background-color:#ffedd5;border-color:#fed7aa;';
                } elseif ($quarter_transmuted === 'DR') {
                    $quarter_pill_style = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
                } elseif ($quarter_transmuted_num >= 3.1 && $quarter_transmuted_num <= 3.5) {
                    $quarter_pill_style = 'color:#92400e;background-color:#fef3c7;border-color:#fde68a;';
                } elseif (is_numeric($quarter_transmuted) && $quarter_transmuted_num > 3.5) {
                    $quarter_pill_style = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
                } elseif (is_numeric($quarter_transmuted) && $quarter_transmuted_num >= 1.0 && $quarter_transmuted_num <= 3.0) {
                    $quarter_pill_style = 'color:#166534;background-color:#dcfce7;border-color:#bbf7d0;';
                } else {
                    $quarter_pill_style = 'color:#334155;background-color:#f1f5f9;border-color:#e2e8f0;';
                }
                ?>
                <td class="py-0 px-1 text-center transmuted-grade fw-bold rq-grade-cell" width="40px">
                    <span class="rq-pill" style="<?php echo $quarter_pill_style; ?>"><?php echo is_numeric($quarter_transmuted) ? number_format($quarter_transmuted, 1) : $quarter_transmuted; ?></span>
                </td>
            <?php endforeach; ?>
            
            <!-- Final Grade Columns -->
            <?php 
            $final_grade = $total_percentage > 0 ? ($total_weighted_score / $total_percentage) * 100 : 0;
            
            // Calculate initial grade for active quarters view
            $initial = $final_grade;
            // INC check for final grade: raw initial in [40, 55.49] plus any
            // BLANK mark under a 30%+ component => INC. An explicitly encoded
            // 0 is failed work, not incomplete work.
            $has_zero_final = false;
            foreach($components as $hz_cid => $hz_comp){
                foreach($active_quarters as $hz_q){
                    if(isset($assess[$hz_cid][$hz_q])){
                        $hz_total_score = 0; $hz_total_hps = 0; $hz_blank = false;
                        foreach($assess[$hz_cid][$hz_q] as $hz_ass){
                            $hz_mark = $marks[$hz_ass['assessment_id']] ?? null;
                            if ($hz_mark === null || trim((string)$hz_mark) === '') { $hz_blank = true; }
                            $hz_total_score += $hz_mark ?? 0;
                            $hz_total_hps += $hz_ass['hps'];
                        }
                        $hz_avg = get_scaled_component_raw($hz_total_score, $hz_total_hps);
                        $hz_weighted = get_weighted_percentage($hz_total_score, $hz_total_hps, $hz_comp['percentage']);
                        if($hz_blank && ($hz_comp['percentage'] ?? 0) >= 30){ $has_zero_final=true; break 2;}
                    }
                }
            }
            
            // Get the equivalent grade from the transmutation table
            
            $transmuted_equivalent = get_equivalent_from_transmutation($initial);
            $transmuted_equivalent_num = is_numeric($transmuted_equivalent) ? (float)$transmuted_equivalent : 0;
            if($initial >= 40 && $initial <= 55.49 && $has_zero_final && $current_status !== 'Dropped'){
                $transmuted_equivalent = 'INC';
                $transmuted_equivalent_num = 0;
            }
            
            // Check if student is dropped
            if ($transmuted_equivalent === 'INC') {
                $pill_style = 'color:#9a3412;background-color:#ffedd5;border-color:#fed7aa;';
            } elseif ($transmuted_equivalent === 'DR') {
                $pill_style = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
            } elseif ($transmuted_equivalent_num >= 3.1 && $transmuted_equivalent_num <= 3.5) {
                $pill_style = 'color:#92400e;background-color:#fef3c7;border-color:#fde68a;';
            } elseif (is_numeric($transmuted_equivalent) && $transmuted_equivalent_num > 3.5) {
                $pill_style = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
            } elseif (is_numeric($transmuted_equivalent) && $transmuted_equivalent_num >= 1.0 && $transmuted_equivalent_num <= 3.0) {
                $pill_style = 'color:#166534;background-color:#dcfce7;border-color:#bbf7d0;';
            } else {
                $pill_style = 'color:#334155;background-color:#f1f5f9;border-color:#e2e8f0;';
            }
            ?>
            <td class="py-0 px-1 text-center fw-bold initial-grade-cell rq-initial-cell" width="40px"><?php echo number_format($initial, 2) ?></td>
            <td class="py-0 px-1 text-center transmuted-grade fw-bold rq-grade-cell" width="40px">
                <span class="rq-pill" style="<?php echo $pill_style; ?>"><?php echo is_numeric($transmuted_equivalent) ? number_format($transmuted_equivalent, 1) : $transmuted_equivalent; ?></span>
            </td>
        <?php } else { ?>
            <?php $single_has_zero = false; foreach($components as $cid => $c):
                $total_hps = 0;            // Total score (sum of marks) for this component
                $total_hps_possible = 0;   // Total HPS across this component's assessments
                $perc_hps = 0;             // Weighted component contribution (scaled raw score * component %)
                $perc_hpss = 0;            // Scaled raw component score ((total score / total HPS) * 40 + 55)
                $component_any_value = false;
                $component_has_blank = false;
                $component_has_graded = false;
                if(isset($assess[$cid])):
                    foreach($assess[$cid] as $assessment_id => $row): 
                        // Blank = "not yet graded": it scores nothing. A missing (or
                        // empty) mark row is a BLANK, not a zero: only blanks feed
                        // the INC rule below.
                        $mark_has_value = isset($marks[$row['assessment_id']]);
                        if (!$mark_has_value || trim((string)($marks[$row['assessment_id']] ?? '')) === '') { $component_has_blank = true; }
                        else { $component_has_graded = true; }
                        $mark = $mark_has_value ? $marks[$row['assessment_id']] : 0;
                        $mark_display = $mark_has_value ? $mark : '';
                        if ($mark_has_value) { $component_any_value = true; }
                        $total_hps += $mark;
                        // A blank's HPS counts in the denominator too.
                        $total_hps_possible += $row['hps'];
                        // Relative weight of this assessment within the component (tooltip info only)
                        $weight = isset($row['weight']) ? $row['weight'] : 0;
        ?>
                    <td class="py-0 px-1 text-center editable-mark rq-mark"
                        width="30px"
                        title="Weight: <?php echo number_format($weight, 2) ?>%"
                        data-student-id="<?php echo $student_id; ?>"
                        data-assessment-id="<?php echo $row['assessment_id']; ?>"
                        data-class-id="<?php echo $class_id; ?>"
                        data-quarter="<?php echo $quarter; ?>"
                        contenteditable="<?php echo ($current_status === 'Dropped') ? 'false' : 'true'; ?>">
                        <?php echo $mark_display?>
                    </td>
        <?php 
                endforeach; 
                // Scaled raw score = (total score / total HPS) * 40 + 55, then weighted by the
                // component percentage (same method as reports_content.php).
                // A component with no graded work at all contributes 0.
                if($component_has_graded) {
                    $perc_hpss = get_scaled_component_raw($total_hps, $total_hps_possible);
                    $perc_hps = get_weighted_percentage($total_hps, $total_hps_possible, $c['percentage']);
                }
                // Only a BLANK under a 30%+ component feeds INC. An explicitly
                // encoded 0 is failed work (5.0 through the raw grade).
                if(isset($assess[$cid]) && ($component_has_blank ?? false) && ($c['percentage'] ?? 0) >= 30){
                    $single_has_zero = true;
                }
        ?>
                    <td class="py-0 px-1 text-center rq-total" width="30px"><?php echo number_format($component_any_value ? $total_hps : 0, 2) ?></td>
                    <td class="py-0 px-1 text-center rq-weighted" width="30px"><?php echo number_format($component_any_value ? $perc_hps : 0, 2) ?></td>
        <?php 
            else:
        ?>
                    <td class="py-0 px-1 text-center rq-none" colspan="3">No assessments for this component</td>
        <?php
            endif;
        ?>
        <?php
        $initial += ($perc_hpss * $c_perc_arr[$cid]);
        endforeach; 
        
        // Store the initial grade in the database
        $check = $conn->query("SELECT id FROM `student_initial_grade` where student_id = '$student_id' and class_id = '$class_id' and quarter = '$quarter'")->fetchArray();
        if($check){
            $conn->query("UPDATE `student_initial_grade` set initial_grade = '$initial' where id = '{$check['id']}'");
        }else{
            $conn->query("INSERT INTO `student_initial_grade` (student_id, class_id, quarter, initial_grade) VALUES('$student_id', '$class_id', '$quarter', '$initial')");
        }
        
        // Get the equivalent grade with the same rounded lookup the
        // pre-edit table (reports_content.php) uses, so the refreshed row
        // shows the identical transmuted value.
        $transmuted_equivalent = get_equivalent_from_transmutation($initial);
        $transmuted_equivalent_num = is_numeric($transmuted_equivalent) ? (float)$transmuted_equivalent : 0;
        // INC rule: a BLANK mark under a 30%+ component plus a raw initial
        // in [40, 55.49] => INC. An explicitly encoded 0 never triggers
        // INC on its own.
        if($initial >= 40 && $initial <= 55.49 && $single_has_zero && $current_status !== 'Dropped'){
            $transmuted_equivalent = 'INC';
            $transmuted_equivalent_num = 0;
        }
        
        // Check if student is dropped
        $rowDepEd = (get_active_transmutation_system($conn) === 'DEPED');
        if ($transmuted_equivalent === 'INC') {
            $color_class = 'color:#9a3412;background-color:#ffedd5;border-color:#fed7aa;';
        } elseif ($transmuted_equivalent === 'DR') {
            $color_class = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
        } elseif ($rowDepEd && $transmuted_equivalent_num >= 75) {
            $color_class = 'color:#166534;background-color:#dcfce7;border-color:#bbf7d0;';
        } elseif ($rowDepEd) {
            $color_class = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
        } elseif ($transmuted_equivalent_num >= 3.1 && $transmuted_equivalent_num <= 3.5) {
            $color_class = 'color:#92400e;background-color:#fef3c7;border-color:#fde68a;'; // Amber pill for borderline
        } elseif ($transmuted_equivalent_num > 3.5) {
            $color_class = 'color:#991b1b;background-color:#fee2e2;border-color:#fecaca;';
        } elseif ($transmuted_equivalent_num >= 1.0 && $transmuted_equivalent_num <= 3.0) {
            $color_class = 'color:#166534;background-color:#dcfce7;border-color:#bbf7d0;';
        } else {
            $color_class = 'color:#334155;background-color:#f1f5f9;border-color:#e2e8f0;';
        }
        ?>
        <td class="py-0 px-1 text-center fw-bold initial-grade-cell rq-initial-cell" width="40px"><?php echo number_format($initial, 2) ?></td>
        <td class="py-0 px-1 text-center transmuted-grade fw-bold rq-grade-cell" width="40px">
            <span class="rq-pill" style="<?php echo $color_class; ?>"><?php echo is_numeric($transmuted_equivalent) ? number_format($transmuted_equivalent, 1) : $transmuted_equivalent; ?></span>
        </td>
    </tr>
        <?php } ?>
        <?php
        $row_html = ob_get_clean();
        
        echo json_encode([
            'status' => 'success',
            'row_html' => $row_html,
            'student_status' => $current_status
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method'
    ]);
}
?>
