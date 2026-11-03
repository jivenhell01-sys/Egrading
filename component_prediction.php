<?php
require_once('DBConnection.php');
require_once('classes/PredictionService.php');

// Check if user is logged in
if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0){
    // User is logged in, continue
} else {
    header("Location: login.php");
    exit;
}

class ComponentPredictionService {
    private $conn;
    private $standardThreshold = 75.0;
    private $highThreshold = 75.0;
    
    public function __construct($standardThreshold = 75.0, $highThreshold = 75.0) {
        $this->conn = new DBConnection();
        $this->standardThreshold = $standardThreshold;
        $this->highThreshold = $highThreshold;
    }
    
    public function getComponentPredictions($classId, $quarter = null) {
        // Get class information
        $classStmt = $this->conn->prepare("
            SELECT cl.*, s.name as subject_name 
            FROM class_list cl 
            JOIN subjects s ON cl.subject_id = s.subject_id 
            WHERE cl.class_id = ?
        ");
        $classStmt->bindValue(1, $classId, SQLITE3_INTEGER);
        $classResult = $classStmt->execute();
        $classInfo = $classResult->fetchArray(SQLITE3_ASSOC);
        
        if (!$classInfo) {
            return null;
        }
        
        // Get grading components for this class
        $componentsStmt = $this->conn->prepare("
            SELECT gc.*, csp.percentage 
            FROM grading_components gc 
            JOIN component_subject_percentage csp ON gc.component_id = csp.component_id 
            JOIN class_list cl ON cl.subject_id = csp.subject_id 
            WHERE cl.class_id = ? 
            ORDER BY gc.name
        ");
        $componentsStmt->bindValue(1, $classId, SQLITE3_INTEGER);
        $componentsResult = $componentsStmt->execute();
        
        $components = [];
        while ($component = $componentsResult->fetchArray(SQLITE3_ASSOC)) {
            $components[] = $component;
        }
        
        // Get students in this class (exclude dropped students)
        $studentsStmt = $this->conn->prepare("
            SELECT sl.* FROM student_list sl 
            JOIN class_list cl ON sl.class_id = cl.class_id 
            WHERE cl.class_id = ? AND (sl.status IS NULL OR sl.status != 'dropped')
        ");
        $studentsStmt->bindValue(1, $classId, SQLITE3_INTEGER);
        $studentsResult = $studentsStmt->execute();
        
        $students = [];
        while ($student = $studentsResult->fetchArray(SQLITE3_ASSOC)) {
            $students[] = $student;
        }
        
        // Calculate predictions for each student
        $predictions = [];
        foreach ($students as $student) {
            $studentPredictions = $this->calculateStudentComponentPredictions($student, $components, $classId, $quarter);
            $predictions[] = [
                'student' => $student,
                'components' => $studentPredictions,
                'overall_status' => $this->getOverallStatus($studentPredictions)
            ];
        }
        
        return [
            'class_info' => $classInfo,
            'components' => $components,
            'predictions' => $predictions,
            'summary' => $this->getClassSummary($predictions, $components),
            'selected_quarter' => $quarter
        ];
    }
    
    private function calculateStudentComponentPredictions($student, $components, $classId, $quarter = null) {
        $componentPredictions = [];
        
        foreach ($components as $component) {
            // Get student's current scores for this component
            $query = "
                SELECT ml.*, al.hps 
                FROM mark_list ml 
                JOIN assessment_list al ON ml.assessment_id = al.assessment_id 
                WHERE ml.student_id = ? AND al.component_id = ?
            ";
            
            $params = [$student['student_id'], $component['component_id']];
            $types = [SQLITE3_INTEGER, SQLITE3_INTEGER];
            
            // Add quarter filter if specified
            if ($quarter !== null) {
                $query .= " AND al.quarter = ?";
                $params[] = $quarter;
                $types[] = SQLITE3_INTEGER;
            }
            
            $scoresStmt = $this->conn->prepare($query);
            
            // Bind parameters dynamically
            foreach ($params as $i => $param) {
                $scoresStmt->bindValue($i + 1, $param, $types[$i]);
            }
            
            $scoresResult = $scoresStmt->execute();
            
            $totalScore = 0;
            $totalHPS = 0;
            $assessmentCount = 0;
            
            while ($score = $scoresResult->fetchArray(SQLITE3_ASSOC)) {
                $totalScore += $score['mark'];
                $totalHPS += $score['hps'];
                $assessmentCount++;
            }
            
            $currentPercentage = $totalHPS > 0 ? ($totalScore / $totalHPS) * 100 : 0;
            
            // Calculate required score to pass this component
            $requiredPercentage = $this->calculateRequiredComponentPercentage($component, $components);
            $requiredScore = $this->calculateRequiredScore($currentPercentage, $requiredPercentage, $totalHPS, $assessmentCount);
            
            $componentPredictions[] = [
                'component' => $component,
                'current_percentage' => round($currentPercentage, 2),
                'required_percentage' => round($requiredPercentage, 2),
                'current_score' => $totalScore,
                'total_hps' => $totalHPS,
                'assessment_count' => $assessmentCount,
                'required_score' => round($requiredScore, 2),
                'status' => $currentPercentage >= $requiredPercentage ? 'PASSING' : 'NEEDS_IMPROVEMENT',
                'gap' => max(0, $requiredPercentage - $currentPercentage)
            ];
        }
        
        return $componentPredictions;
    }
    
    private function calculateRequiredComponentPercentage($component, $allComponents) {
        // Use dynamic thresholds: standard for most components, higher for major exams or highest-weighted
        
        // Check if this is a major exam (by name) or has the highest percentage weight
        $componentName = strtolower($component['name']);
        $isMajorExam = strpos($componentName, 'major') !== false || strpos($componentName, 'exam') !== false;
        
        // Find the highest weighted component
        $maxWeight = 0;
        foreach ($allComponents as $comp) {
            if ($comp['percentage'] > $maxWeight) {
                $maxWeight = $comp['percentage'];
            }
        }
        $isHighestWeighted = $component['percentage'] == $maxWeight;
        
        // Use dynamic thresholds
        if ($isMajorExam || $isHighestWeighted) {
            return $this->highThreshold;
        }
        
        return $this->standardThreshold;
    }
    
    private function calculateRequiredScore($currentPercentage, $requiredPercentage, $totalHPS, $assessmentCount) {
        if ($currentPercentage >= $requiredPercentage) {
            return 0; // Already passing
        }
        
        if ($assessmentCount == 0) {
            return 0; // No assessments yet
        }
        
        // Calculate additional score needed
        $currentScore = ($currentPercentage / 100) * $totalHPS;
        $requiredScore = ($requiredPercentage / 100) * $totalHPS;
        
        return $requiredScore - $currentScore;
    }
    
    private function getOverallStatus($componentPredictions) {
        $passingComponents = 0;
        $totalComponents = count($componentPredictions);
        
        foreach ($componentPredictions as $prediction) {
            if ($prediction['status'] === 'PASSING') {
                $passingComponents++;
            }
        }
        
        $passingRate = ($passingComponents / $totalComponents) * 100;
        
        if ($passingRate >= 80) {
            return 'ON_TRACK';
        } elseif ($passingRate >= 75) {
            return 'AT_RISK';
        } else {
            return 'CRITICAL';
        }
    }
    
    private function getClassSummary($predictions, $components) {
        $summary = [];
        
        foreach ($components as $component) {
            $passingCount = 0;
            $totalCount = 0;
            $totalGap = 0;
            
            foreach ($predictions as $studentPrediction) {
                // Skip students with dropped status
                if (isset($studentPrediction['student']['status']) && 
                    strtolower($studentPrediction['student']['status']) === 'dropped') {
                    continue;
                }
                
                foreach ($studentPrediction['components'] as $compPrediction) {
                    if ($compPrediction['component']['component_id'] == $component['component_id']) {
                        // Skip if marks are 0
                        if ($compPrediction['current_score'] == 0) {
                            continue;
                        }
                        
                        $totalCount++;
                        if ($compPrediction['status'] === 'PASSING') {
                            $passingCount++;
                        }
                        $totalGap += $compPrediction['gap'];
                    }
                }
            }
            
            $summary[$component['component_id']] = [
                'component_name' => $component['name'],
                'passing_count' => $passingCount,
                'total_count' => $totalCount,
                'passing_rate' => $totalCount > 0 ? round(($passingCount / $totalCount) * 100, 1) : 0,
                'average_gap' => $totalCount > 0 ? round($totalGap / $totalCount, 2) : 0
            ];
        }
        
        return $summary;
    }
}

// Get available classes for dropdown (like attendance.php)
$classes = [];
$class_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class, 
                          a.quarter as quarter
                          FROM `class_list` c 
                          INNER JOIN `subjects` s ON c.subject_id = s.subject_id
                          INNER JOIN `assessment_list` a ON c.class_id = a.class_id
                          GROUP BY c.class_id, a.quarter
                          ORDER BY `class`, a.quarter ASC");

$availableClasses = [];
// Custom-made quarters per class (from marks.php: first made = value 1, next = 2, ...). Never static Prelim/Midterm.
$customQuarterNames = [];
$cq_qry = $conn->query("SELECT class_id, quarter, name FROM quarter_list ORDER BY class_id ASC, quarter ASC");
if ($cq_qry) {
    while ($cq_row = $cq_qry->fetchArray(SQLITE3_ASSOC)) {
        $customQuarterNames[(int)$cq_row['class_id']][(int)$cq_row['quarter']] = $cq_row['name'];
    }
}
while($row = $class_qry->fetchArray(SQLITE3_ASSOC)) {
    $cid = (int)$row['class_id'];
    $qval = (int)$row['quarter'];
    $quarter_name = $customQuarterNames[$cid][$qval] ?? ('Quarter ' . $qval);
    $availableClasses[] = [
        'class_id' => $row['class_id'],
        'class_name' => $row['class'],
        'quarter' => $row['quarter'],
        'display_text' => $row['class'] . ' - ' . $quarter_name
    ];
}

// Get class ID and quarter from URL
$classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : null;

// Get threshold settings from URL or use defaults
$standardThreshold = isset($_GET['standard_threshold']) ? floatval($_GET['standard_threshold']) : 75.0;
$highThreshold = isset($_GET['high_threshold']) ? floatval($_GET['high_threshold']) : 75.0;

// Available quarters for the selected class — custom-made (values increment from 1 as created in Marks)
$availableQuarters = [];
if ($classId > 0 && isset($customQuarterNames[$classId])) {
    foreach ($customQuarterNames[$classId] as $qval => $qname) {
        $availableQuarters[$qval] = $qname;
    }
} else {
    $aq_qry = $conn->query("SELECT DISTINCT quarter FROM assessment_list" . ($classId > 0 ? " WHERE class_id = '{$classId}'" : "") . " ORDER BY quarter ASC");
    if ($aq_qry) {
        while ($aq_row = $aq_qry->fetchArray(SQLITE3_ASSOC)) {
            $qv = (int)$aq_row['quarter'];
            $availableQuarters[$qv] = ($classId > 0 && isset($customQuarterNames[$classId][$qv])) ? $customQuarterNames[$classId][$qv] : ('Quarter ' . $qv);
        }
    }
}
$selectedQuarterName = ($quarter !== null && isset($availableQuarters[$quarter])) ? $availableQuarters[$quarter] : null;

if ($classId > 0) {
    $componentService = new ComponentPredictionService($standardThreshold, $highThreshold);
    $predictions = $componentService->getComponentPredictions($classId, $quarter);
}
?>

<div class="row">
    <div class="col-12">
        <div class="card modern-card">
            <div class="card-header modern-card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="flex-grow-1">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>
                                    Component-Level Predictions
                                </h5>
                            </div>
                            <div class="col-md-8">
                                <label for="classSelect" class="form-label mb-1">
                                    <i class="fas fa-graduation-cap me-2"></i>Select Class
                                </label>
                                <select class="form-select form-select-sm" id="classSelect" onchange="changeClass()">
                                    <option value="">-- Select Class --</option>
                                    <?php 
                                    // Get unique classes (without duplicates) like attendance.php
                                    $unique_classes = [];
                                    foreach($availableClasses as $class) {
                                        $key = $class['class_id'] . '-' . $class['quarter'];
                                        if (!isset($unique_classes[$key])) {
                                            $unique_classes[$key] = $class;
                                        }
                                    }
                                    
                                    foreach($unique_classes as $class): 
                                        $selected = ($classId === $class['class_id'] && $quarter === $class['quarter']) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $class['class_id'] ?>" 
                                                data-quarter="<?= $class['quarter'] ?>" 
                                                <?= $selected ?>>
                                            <?= htmlspecialchars($class['display_text']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="quarter" id="quarter" value="<?= $quarter ?: ($availableClasses[0]['quarter'] ?? 1) ?>">
                            </div>
                        </div>
                        <small class="text-muted">
                            <?php if(isset($predictions['class_info'])): ?>
                                <?= htmlspecialchars($predictions['class_info']['subject_name']) ?> -
                                <?= htmlspecialchars($predictions['class_info']['course_no']) ?> -
                                <?= htmlspecialchars($predictions['class_info']['section']) ?>
                                <?= $selectedQuarterName ? ' · <strong>' . htmlspecialchars($selectedQuarterName) . '</strong>' : '' ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div>
                        <a href="?page=prediction_dashboard<?php echo $classId ? '&class_id=' . $classId : '' ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                        <button onclick="exportComponentPredictions()" class="btn btn-warning btn-sm">
                            <i class="fas fa-download me-2"></i>Export
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if(isset($predictions)): ?>
                    <!-- Threshold Settings -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="standardThreshold" class="form-label">
                                <i class="fas fa-percentage me-2"></i>Standard Component Threshold (%)
                            </label>
                            <input type="number" class="form-control" id="standardThreshold" 
                                   value="<?= $standardThreshold ?>" 
                                   min="0" max="100" step="0.1"
                                   placeholder="75.0"
                                   onchange="updateThresholds()">
                            <small class="form-text text-muted">Required percentage for regular components</small>
                        </div>
                        <div class="col-md-6">
                            <label for="highThreshold" class="form-label">
                                <i class="fas fa-percentage me-2"></i>Major/Highest-Weighted Threshold (%)
                            </label>
                            <input type="number" class="form-control" id="highThreshold" 
                                   value="<?= $highThreshold ?>" 
                                   min="0" max="100" step="0.1"
                                   placeholder="75.0"
                                   onchange="updateThresholds()">
                            <small class="form-text text-muted">Required percentage for major exams or highest-weighted components</small>
                        </div>
                    </div>

                    <!-- Component Overview -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <h6 class="alert-heading">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Understanding Component Predictions
                                </h6>
                                <p class="mb-2 text-dark">
                                    This analysis shows the minimum scores needed in each component to achieve the <strong>75% overall passing grade</strong> (equivalent to 3.0 in transmutation).
                                </p>
                                <p class="mb-0 text-dark">
                                    <strong>Required percentages are calculated based on component weights</strong> - higher-weighted components require higher scores to maintain the 75% overall target.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Class Summary -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="mb-3">
                                <i class="fas fa-chart-bar me-2"></i>
                                Class Component Performance Summary
                            </h6>
                            <div class="row">
                                <?php foreach ($predictions['summary'] as $componentId => $summary): ?>
                                    <div class="col-md-3 mb-3">
                                        <div class="card border-0 bg-light">
                                            <div class="card-body text-center">
                                                <h6 class="mb-2 text-dark"><?= htmlspecialchars($summary['component_name']) ?></h6>
                                                <div class="h3 mb-1 <?= $summary['passing_rate'] >= 75 ? 'text-success' : ($summary['passing_rate'] >= 50 ? 'text-warning' : 'text-danger') ?>">
                                                    <?= $summary['passing_rate'] ?>%
                                                </div>
                                                <small class="text-muted">Passing Rate</small>
                                                <div class="mt-2 text-dark">
                                                    <small><?= $summary['passing_count'] ?>/<?= $summary['total_count'] ?> students</small>
                                                </div>
                                                <?php if ($summary['average_gap'] > 0): ?>
                                                    <div class="mt-1">
                                                        <small class="text-warning">Avg Gap: <?= $summary['average_gap'] ?>%</small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Individual Student Predictions -->
                    <div class="row">
                        <div class="col-12">
                            <h6 class="mb-3">
                                <i class="fas fa-users me-2"></i>
                                Individual Component Predictions
                            </h6>
                            
                            <div class="mb-3">
                                <input type="text" class="form-control" id="studentSearch" placeholder="Search students by name or student number...">
                            </div>

                            <div id="componentPredictionsList">
                                <?php 
                                // Get all unique components for table headers
                                $allComponents = [];
                                foreach ($predictions['predictions'] as $studentPrediction) {
                                    foreach ($studentPrediction['components'] as $componentPrediction) {
                                        $componentId = $componentPrediction['component']['component_id'];
                                        if (!isset($allComponents[$componentId])) {
                                            $allComponents[$componentId] = $componentPrediction['component']['name'];
                                        }
                                    }
                                }
                                ?>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover align-middle" id="componentPredictionsTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th style="width: 150px;">Student</th>
                                                <th style="width: 50px;">Student Number</th>
                                                <th style="width: 50px;">Status</th>
                                                <?php foreach ($allComponents as $componentId => $componentName): ?>
                                                    <th><?= htmlspecialchars($componentName) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($predictions['predictions'] as $studentPrediction): ?>
                                                <tr data-student-name="<?= strtolower($studentPrediction['student']['name'] . ' ' . $studentPrediction['student']['student_number']) ?>">
                                                    <td>
                                                        <strong><?= htmlspecialchars($studentPrediction['student']['name']) ?></strong>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted"><?= htmlspecialchars($studentPrediction['student']['student_number']) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $statusClass = $studentPrediction['overall_status'] === 'ON_TRACK' ? 'bg-success' : 
                                                                     ($studentPrediction['overall_status'] === 'AT_RISK' ? 'bg-warning' : 'bg-danger');
                                                        $statusText = $studentPrediction['overall_status'] === 'ON_TRACK' ? 'On Track' : 
                                                                     ($studentPrediction['overall_status'] === 'AT_RISK' ? 'At Risk' : 'Critical');
                                                        ?>
                                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                                    </td>
                                                    <?php 
                                                    // Create a map of component data for easy lookup
                                                    $componentMap = [];
                                                    foreach ($studentPrediction['components'] as $componentPrediction) {
                                                        $componentMap[$componentPrediction['component']['component_id']] = $componentPrediction;
                                                    }
                                                    
                                                    // Display each component in its column
                                                    foreach ($allComponents as $componentId => $componentName): 
                                                        $componentPrediction = $componentMap[$componentId] ?? null;
                                                    ?>
                                                        <td>
                                                            <?php if ($componentPrediction): ?>
                                                                <div class="mb-1">
                                                                    <small class="text-muted">Current: </small>
                                                                    <span class="fw-bold <?= $componentPrediction['current_percentage'] >= $componentPrediction['required_percentage'] ? 'text-success' : 'text-danger' ?>">
                                                                        <?= $componentPrediction['current_percentage'] ?>%
                                                                    </span>
                                                                </div>
                                                                <div class="mb-1">
                                                                    <small class="text-muted">Required: </small>
                                                                    <span class="fw-bold text-warning"><?= $componentPrediction['required_percentage'] ?>%</span>
                                                                </div>
                                                                <?php if ($componentPrediction['gap'] > 0): ?>
                                                                    <div class="text-danger small">
                                                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                                                        Need <?= $componentPrediction['gap'] ?>%
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="text-success small">
                                                                        <i class="fas fa-check-circle me-1"></i>
                                                                        On track
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-chart-pie fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">No Class Selected</h4>
                        <p class="text-muted">Please access this page from the Prediction Dashboard with a class selected.</p>
                        <a href="?page=prediction_dashboard" class="btn btn-primary">
                            <i class="fas fa-arrow-left me-2"></i>Go to Prediction Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-sm {
    width: 40px;
    height: 40px;
    font-size: 14px;
    font-weight: 600;
}

.progress {
    background-color: #e9ecef;
    border-radius: 4px;
}

.progress-bar {
    font-size: 0.75rem;
    line-height: 8px;
    text-align: center;
}

.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
</style>

<script>
$(document).ready(function() {
    // Initialize DataTables for component predictions table
    $('#componentPredictionsTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'asc']],
        columnDefs: [
            { orderable: true, targets: [0, 1, 2] },
            { orderable: false, targets: '_all' }
        ],
        language: {
            search: "Search students:",
            lengthMenu: "Show _MENU_ entries per page",
            info: "Showing _START_ to _END_ of _TOTAL_ students",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        }
    });
});

function changeClass() {
    const classSelect = document.getElementById('classSelect');
    const selectedOption = classSelect.options[classSelect.selectedIndex];
    const selectedClassId = classSelect.value;
    const quarter = selectedOption.getAttribute('data-quarter');
    
    // Update hidden quarter field
    document.getElementById('quarter').value = quarter;
    
    if (selectedClassId) {
        let url = `?page=component_prediction&class_id=${selectedClassId}&quarter=${quarter}`;
        
        // Preserve current threshold settings
        const standardThreshold = document.getElementById('standardThreshold');
        const highThreshold = document.getElementById('highThreshold');
        if (standardThreshold && highThreshold) {
            url += `&standard_threshold=${standardThreshold.value}&high_threshold=${highThreshold.value}`;
        }
        
        window.location.href = url;
    } else {
        // If no class selected, redirect to prediction dashboard
        window.location.href = '?page=prediction_dashboard';
    }
}

function exportComponentPredictions() {
    const classId = <?= $classId ?>;
    const quarter = <?= $quarter ?: 'null' ?>;
    
    if (classId) {
        let url = `export_component_predictions.php?class_id=${classId}`;
        if (quarter !== null) {
            url += `&quarter=${quarter}`;
        }
        window.open(url, '_blank');
    } else {
        alert('No class selected for export.');
    }
}

function updateThresholds() {
    const classSelect = document.getElementById('classSelect');
    const selectedClassId = classSelect.value;
    
    if (!selectedClassId) {
        alert('Please select a class first.');
        return;
    }
    
    const standardThreshold = document.getElementById('standardThreshold').value;
    const highThreshold = document.getElementById('highThreshold').value;
    
    // Get quarter from hidden field
    const quarter = document.getElementById('quarter').value;
    
    let url = `?page=component_prediction&class_id=${selectedClassId}&quarter=${quarter}`;
    url += `&standard_threshold=${standardThreshold}&high_threshold=${highThreshold}`;
    
    window.location.href = url;
}


// Student search functionality
document.getElementById('studentSearch')?.addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const cards = document.querySelectorAll('#componentPredictionsList .card');
    
    cards.forEach(card => {
        const studentName = card.dataset.studentName;
        if (studentName.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
});
</script>

