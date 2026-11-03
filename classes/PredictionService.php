<?php
require_once __DIR__ . '/../DBConnection.php';

class PredictionService {
    private $db;
    
    public function __construct() {
        $this->db = new DBConnection();
    }
    
    /**
     * Calculate linear regression coefficients (slope and intercept)
     * y = mx + b where y is predicted grade, x is current performance
     */
    private function calculateLinearRegression($data) {
        if (count($data) < 2) {
            return ['slope' => 0, 'intercept' => 75, 'r2' => 0]; // Default to passing grade
        }
        
        $n = count($data);
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0; $sumY2 = 0;
        
        foreach ($data as $point) {
            $x = $point['current_performance'];
            $y = $point['final_grade'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
            $sumY2 += $y * $y;
        }
        
        $meanX = $sumX / $n;
        $meanY = $sumY / $n;
        
        // Calculate slope (m) and intercept (b)
        $numerator = $sumXY - ($sumX * $sumY) / $n;
        $denominator = $sumX2 - ($sumX * $sumX) / $n;
        
        if ($denominator == 0) {
            return ['slope' => 0, 'intercept' => $meanY, 'r2' => 0];
        }
        
        $slope = $numerator / $denominator;
        $intercept = $meanY - $slope * $meanX;
        
        // Calculate R-squared for model accuracy
        $ssTotal = $sumY2 - ($sumY * $sumY) / $n;
        $ssResidual = 0;
        
        foreach ($data as $point) {
            $predicted = $slope * $point['current_performance'] + $intercept;
            $ssResidual += pow($point['final_grade'] - $predicted, 2);
        }
        
        $r2 = $ssTotal > 0 ? 1 - ($ssResidual / $ssTotal) : 0;
        
        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r2' => max(0, min(1, $r2)) // Clamp between 0 and 1
        ];
    }
    
    /**
     * Get historical data for training the model
     */
    private function getHistoricalData($classId, $subjectId = null) {
        $sql = "
            SELECT 
                sl.student_id,
                sl.name,
                AVG(CASE WHEN al.quarter = 1 THEN (ml.mark / al.hps) * 100 END) as q1_performance,
                AVG(CASE WHEN al.quarter = 2 THEN (ml.mark / al.hps) * 100 END) as q2_performance,
                AVG(CASE WHEN al.quarter = 3 THEN (ml.mark / al.hps) * 100 END) as q3_performance,
                AVG(CASE WHEN al.quarter = 4 THEN (ml.mark / al.hps) * 100 END) as q4_performance,
                pg.average_grade as final_grade
            FROM student_list sl
            JOIN mark_list ml ON sl.student_id = ml.student_id
            JOIN assessment_list al ON ml.assessment_id = al.assessment_id
            LEFT JOIN published_grades pg ON sl.student_id = pg.student_id AND al.class_id = pg.class_id
            WHERE al.class_id = :class_id
            AND ml.mark IS NOT NULL AND al.hps > 0
            GROUP BY sl.student_id, sl.name, pg.average_grade
            HAVING pg.average_grade IS NOT NULL
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $trainingData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Average over whatever custom quarters have data (never assume fixed quarters)
            $vals = [];
            foreach ($row as $k => $v) {
                if (preg_match('/^q\d+_performance$/', $k) && !empty($v)) $vals[] = floatval($v);
            }
            if (count($vals) >= 2) {
                $trainingData[] = [
                    'current_performance' => array_sum($vals) / count($vals),
                    'final_grade' => $row['final_grade']
                ];
            }
        }
        
        return $trainingData;
    }
    
    /**
     * Get custom quarter names for a class (quarters are CUSTOM MADE per class)
     */
    public function getQuarterNames($classId) {
        $map = [];
        try {
            $stmt = $this->db->prepare("SELECT quarter, name FROM quarter_list WHERE class_id = :cid ORDER BY quarter ASC");
            $stmt->bindValue(':cid', $classId, SQLITE3_INTEGER);
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $map[(int)$row['quarter']] = $row['name'];
            }
        } catch (Exception $e) {}
        return $map;
    }

    /**
     * Get current student performance data (includes students with zero marks)
     */
    private function getCurrentStudentPerformance($classId, $studentId = null) {
        $studentFilter = $studentId ? "AND sl.student_id = :student_id" : "";

        $sql = "
            SELECT
                sl.student_id,
                sl.name,
                sl.student_number,
                AVG(CASE WHEN al.quarter = 1 THEN (ml.mark / al.hps) * 100 END) as q1_avg,
                AVG(CASE WHEN al.quarter = 2 THEN (ml.mark / al.hps) * 100 END) as q2_avg,
                AVG(CASE WHEN al.quarter = 3 THEN (ml.mark / al.hps) * 100 END) as q3_avg,
                AVG(CASE WHEN al.quarter = 4 THEN (ml.mark / al.hps) * 100 END) as q4_avg,
                COUNT(DISTINCT CASE WHEN al.class_id = :class_id THEN al.assessment_id END) as total_assessments,
                COUNT(CASE WHEN ml.mark IS NOT NULL THEN 1 END) as completed_assessments
            FROM student_list sl
            LEFT JOIN mark_list ml ON sl.student_id = ml.student_id
            LEFT JOIN assessment_list al ON ml.assessment_id = al.assessment_id AND al.class_id = :class_id
            WHERE sl.class_id = :class_id
            $studentFilter
            GROUP BY sl.student_id, sl.name, sl.student_number
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
        if ($studentId) {
            $stmt->bindValue(':student_id', $studentId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        
        $students = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $students[] = $row;
        }
        
        return $students;
    }
    
    /**
     * Calculate weighted current performance
     */
    private function calculateWeightedPerformance($studentData, $classId) {
        // Get component weights for this class
        $sql = "
            SELECT gc.name, csp.percentage
            FROM component_subject_percentage csp
            JOIN grading_components gc ON csp.component_id = gc.component_id
            JOIN class_list cl ON csp.subject_id = cl.subject_id
            WHERE cl.class_id = :class_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $weights = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $weights[$row['name']] = floatval($row['percentage']);
        }
        
        // Average over ALL custom quarters present (quarters are custom-made per class, never fixed 1-3)
        $valid = [];
        foreach ($studentData as $k => $v) {
            if (preg_match('/^q(\d+)_avg$/', $k) && !empty($v)) $valid[] = floatval($v);
        }
        if (empty($valid)) return 0;
        return array_sum($valid) / count($valid);
    }
    
    /**
     * Predict final grade for a student
     */
    public function predictStudentGrade($classId, $studentId = null) {
        // Get historical data for training
        $historicalData = $this->getHistoricalData($classId);
        
        if (empty($historicalData)) {
            // If no historical data, use default model
            $model = ['slope' => 0.8, 'intercept' => 15, 'r2' => 0.5];
        } else {
            $model = $this->calculateLinearRegression($historicalData);
        }
        
        // Get current student performance
        $currentStudents = $this->getCurrentStudentPerformance($classId, $studentId);
        
        $quarterNames = $this->getQuarterNames($classId);
        // Class's actual custom quarter IDs (from quarter_list; fall back to quarters seen in marks)
        $classQuarterIds = array_keys($quarterNames);
        if (empty($classQuarterIds)) {
            $tmp = [];
            foreach ($currentStudents as $cs) {
                foreach ($cs as $k => $v) {
                    if (preg_match('/^q(\d+)_avg$/', $k, $m)) $tmp[(int)$m[1]] = true;
                }
            }
            $classQuarterIds = array_keys($tmp);
        }
        sort($classQuarterIds);
        $predictions = [];
        foreach ($currentStudents as $student) {
            $currentPerformance = $this->calculateWeightedPerformance($student, $classId);
            $completeness = $this->calculateDataCompleteness($student, $quarterNames);

            // Apply linear regression: y = mx + b (fall back to current performance when model is weak)
            $predictedGrade = $model['slope'] * $currentPerformance + $model['intercept'];
            if ($model['r2'] < 0.3 || ($model['training_data_size'] ?? count($historicalData)) < 5) {
                // Blend toward current performance when training data is thin
                $predictedGrade = 0.5 * $predictedGrade + 0.5 * $currentPerformance;
            }
            $predictedGrade = max(0, min(100, $predictedGrade));

            $passingProbability = $this->calculatePassingProbability($predictedGrade, $model['r2'], $completeness);
            $trend = $this->calculateTrend($student, $quarterNames);
            $suggestions = $this->generateSuggestions($student, $currentPerformance, $predictedGrade, $quarterNames, $trend);

            // Risk tiers for clearer student improvement tracking
            $tier = 'On Track';
            if ($predictedGrade < 60 || $passingProbability < 0.25) $tier = 'Critical';
            elseif ($predictedGrade < 75 && $passingProbability < 0.6) $tier = 'At Risk';
            elseif ($predictedGrade < 80) $tier = 'Needs Work';

            // Per-quarter averages (custom quarters only) for the what-if calculator
            $qAvgs = [];
            foreach ($classQuarterIds as $qid) {
                $qAvgs[] = [
                    'id' => $qid,
                    'name' => $quarterNames[$qid] ?? ('Quarter '.$qid),
                    'avg' => isset($student['q'.$qid.'_avg']) && $student['q'.$qid.'_avg'] !== null && $student['q'.$qid.'_avg'] !== '' ? round(floatval($student['q'.$qid.'_avg']), 2) : null
                ];
            }
            $predictions[] = [
                'student_id' => $student['student_id'],
                'name' => $student['name'],
                'student_number' => $student['student_number'],
                'quarters' => $qAvgs,
                'current_performance' => round($currentPerformance, 2),
                'predicted_grade' => round($predictedGrade, 2),
                'passing_probability' => round($passingProbability * 100, 1),
                'will_pass' => $predictedGrade >= 75,
                'at_risk' => $predictedGrade < 75 && $passingProbability < 0.6,
                'risk_tier' => $tier,
                'trend' => $trend,
                'suggestions' => $suggestions,
                'improvement_plan' => $this->buildImprovementPlan($student, $currentPerformance, $predictedGrade, $quarterNames, $tier),
                'data_completeness' => $completeness,
                'model_accuracy' => round($model['r2'], 3)
            ];
        }
        // Sort at-risk first so instructors see who needs help
        usort($predictions, fn($a,$b) => [$a['predicted_grade']] <=> [$b['predicted_grade']]);
        
        return [
            'predictions' => $predictions,
            'model_info' => [
                'slope' => round($model['slope'], 4),
                'intercept' => round($model['intercept'], 2),
                'r2' => round($model['r2'], 3),
                'training_data_size' => count($historicalData)
            ]
        ];
    }
    
    /**
     * Calculate probability of passing (logistic-style, accounts for model confidence + data completeness)
     */
    private function calculatePassingProbability($predictedGrade, $modelConfidence, $dataCompleteness = 100) {
        $conf = max(0, min(1, floatval($modelConfidence)));
        $comp = max(0, min(100, floatval($dataCompleteness))) / 100;
        // Distance from 75% passing threshold, scaled by confidence and completeness
        $z = ($predictedGrade - 75) / 10;
        $prob = 1 / (1 + exp(-$z * (0.6 + 0.4 * $conf)));
        // Shrink toward 0.5 when data is sparse or model is weak
        $shrink = 0.5 + ($prob - 0.5) * (0.4 + 0.6 * $conf * (0.5 + 0.5 * $comp));
        return max(0.02, min(0.98, $shrink));
    }
    
    /**
     * Per-quarter trend (uses custom quarter names) for student improvement tracking
     */
    private function calculateTrend($student, $quarterNames = []) {
        $vals = [];
        foreach ([1,2,3,4] as $q) {
            $k = 'q'.$q.'_avg';
            if (!empty($student[$k])) $vals[$q] = floatval($student[$k]);
        }
        if (count($vals) < 2) return ['direction' => 'insufficient', 'label' => 'Not enough data', 'delta' => 0];
        $first = reset($vals); $last = end($vals);
        $delta = round($last - $first, 2);
        $names = function($q) use ($quarterNames) { return $quarterNames[$q] ?? ('Quarter '.$q); };
        if ($delta >= 5) return ['direction' => 'improving', 'label' => 'Improving ('.$names(array_key_first($vals)).' → '.$names(array_key_last($vals)).' +'.$delta.'%)', 'delta' => $delta];
        if ($delta <= -5) return ['direction' => 'declining', 'label' => 'Declining ('.$delta.'%) — needs attention', 'delta' => $delta];
        return ['direction' => 'stable', 'label' => 'Stable ('.($delta>=0?'+':'').$delta.'%)', 'delta' => $delta];
    }

    /**
     * Actual custom quarter IDs for a class (from quarter_list, never static 1-4)
     */
    private function resolveQuarterIds($student, $quarterNames = []) {
        if (!empty($quarterNames)) { $ids = array_keys($quarterNames); sort($ids); return $ids; }
        $ids = [];
        foreach ($student as $k => $v) {
            if (preg_match('/^q(\d+)_avg$/', $k, $m)) $ids[] = (int)$m[1];
        }
        sort($ids);
        return $ids;
    }

    /**
     * Actionable improvement plan (what to do next, by priority)
     */
    private function buildImprovementPlan($student, $currentPerformance, $predictedGrade, $quarterNames = [], $tier = 'On Track') {
        $plan = [];
        $missing = [];
        foreach ($this->resolveQuarterIds($student, $quarterNames) as $q) {
            if (empty($student['q'.$q.'_avg'])) $missing[] = $quarterNames[$q] ?? ('Quarter '.$q);
        }
        if (!empty($missing)) $plan[] = ['step' => 1, 'action' => 'Complete missing work in '.implode(', ', $missing).' — missing quarters lower prediction confidence.'];
        $gap = round(max(0, 75 - $predictedGrade), 1);
        if ($tier === 'Critical') {
            $plan[] = ['step' => 2, 'action' => "Urgent: needs +{$gap}% to reach 75%. Schedule 1-on-1 remediation and re-take lowest quiz."];
            $plan[] = ['step' => 3, 'action' => 'Focus on highest-weight component first (see Component Requirements below).'];
        } elseif ($tier === 'At Risk' || $tier === 'Needs Work') {
            $plan[] = ['step' => 2, 'action' => "Target +{$gap}% — aim for ".round(min(100, $currentPerformance + $gap + 5),1)."% average in remaining assessments."];
            $plan[] = ['step' => 3, 'action' => 'Review lowest component and redo 2 past assessments for mastery.'];
        } else {
            $plan[] = ['step' => 2, 'action' => 'Maintain pace. Stretch goal: 85%+ in next quarter for honors margin.'];
        }
        $plan[] = ['step' => 4, 'action' => 'Weekly check-in: re-run prediction after each new graded assessment.'];
        return $plan;
    }

    /**
     * Generate personalized improvement suggestions (custom quarters aware)
     */
    private function generateSuggestions($student, $currentPerformance, $predictedGrade, $quarterNames = [], $trend = null) {
        $suggestions = [];
        $qn = fn($q) => $quarterNames[$q] ?? ('Quarter '.$q);
        if ($predictedGrade < 75) {
            $needed = 75 - $predictedGrade;
            foreach ($this->resolveQuarterIds($student, $quarterNames) as $q) {
                if (empty($student['q'.$q.'_avg'])) $suggestions[] = "Missing ".$qn($q)." — completing it has the biggest lift (+".round($needed,1)."% needed).";
            }
            if ($trend && ($trend['direction'] ?? '') === 'declining') $suggestions[] = "Trend is declining (".$trend['label']."). Prioritize re-teaching of the last quarter's weakest component.";
            elseif ($trend && ($trend['direction'] ?? '') === 'improving') $suggestions[] = "Trend is improving — keep the same study routine that lifted recent scores.";
            if ($currentPerformance < 60) {
                $suggestions[] = "Critical (<60%): tutoring + foundational review; target ".round(75 + $needed)."% in remaining work.";
            } elseif ($currentPerformance < 70) {
                $suggestions[] = "Target ".round(75 + $needed)."% in remaining assessments; review past mistakes by component.";
            } else {
                $suggestions[] = "Close to passing: hold above ".round(75 + $needed/2)."% in remaining assessments.";
            }
            $suggestions[] = "Attend all classes and submit on time — completion rate directly raises predicted grade.";
        } else {
            $suggestions[] = "On track — hold current average to secure passing.";
            $lastQ = null;
            foreach ($this->resolveQuarterIds($student, $quarterNames) as $q) { $lastQ = $q; }
            if ($predictedGrade < 85 && $lastQ !== null) $suggestions[] = "Stretch: 85%+ in ".$qn($lastQ)." builds buffer against one low quiz.";
            if ($trend && ($trend['direction'] ?? '') === 'declining') $suggestions[] = "Watch trend: recent dip detected — review last quarter before it compounds.";
        }
        return array_values(array_unique($suggestions));
    }
    
    /**
     * Calculate data completeness percentage (over the class's actual custom quarters)
     */
    private function calculateDataCompleteness($student, $quarterNames = []) {
        $ids = $this->resolveQuarterIds($student, $quarterNames);
        if (empty($ids)) return 0;
        $completed = 0;
        foreach ($ids as $q) {
            if (!empty($student['q'.$q.'_avg'])) $completed++;
        }
        return round(($completed / count($ids)) * 100, 1);
    }
    
    /**
     * Get class statistics for prediction dashboard
     */
    public function getClassPredictionStats($classId) {
        $predictions = $this->predictStudentGrade($classId);
        
        if (empty($predictions['predictions'])) {
            return [
                'total_students' => 0,
                'passing_students' => 0,
                'at_risk_students' => 0,
                'average_predicted' => 0,
                'pass_rate' => 0
            ];
        }
        
        $totalStudents = count($predictions['predictions']);
        $passingStudents = 0;
        $atRiskStudents = 0;
        $totalPredicted = 0;
        
        foreach ($predictions['predictions'] as $prediction) {
            if ($prediction['will_pass']) {
                $passingStudents++;
            }
            if ($prediction['at_risk']) {
                $atRiskStudents++;
            }
            $totalPredicted += $prediction['predicted_grade'];
        }
        
        return [
            'total_students' => $totalStudents,
            'passing_students' => $passingStudents,
            'at_risk_students' => $atRiskStudents,
            'average_predicted' => round($totalPredicted / $totalStudents, 2),
            'pass_rate' => round(($passingStudents / $totalStudents) * 100, 2),
            'risk_rate' => round(($atRiskStudents / $totalStudents) * 100, 2)
        ];
    }
    
    /**
     * Calculate requirements for students to pass overall average across all quarters
     */
    public function calculateQuarterRequirements($classId) {
        // Get current student performance by quarter
        $sql = "
            SELECT 
                sl.student_id,
                sl.name,
                sl.student_number,
                sl.status,
                AVG(CASE WHEN al.quarter = 1 THEN (ml.mark / al.hps) * 100 END) as q1_avg,
                AVG(CASE WHEN al.quarter = 2 THEN (ml.mark / al.hps) * 100 END) as q2_avg,
                AVG(CASE WHEN al.quarter = 3 THEN (ml.mark / al.hps) * 100 END) as q3_avg,
                AVG(CASE WHEN al.quarter = 4 THEN (ml.mark / al.hps) * 100 END) as q4_avg,
                COUNT(CASE WHEN al.quarter = 1 AND ml.mark IS NOT NULL THEN 1 END) as q1_count,
                COUNT(CASE WHEN al.quarter = 2 AND ml.mark IS NOT NULL THEN 1 END) as q2_count,
                COUNT(CASE WHEN al.quarter = 3 AND ml.mark IS NOT NULL THEN 1 END) as q3_count,
                COUNT(CASE WHEN al.quarter = 4 AND ml.mark IS NOT NULL THEN 1 END) as q4_count
            FROM student_list sl
            LEFT JOIN mark_list ml ON sl.student_id = ml.student_id
            LEFT JOIN assessment_list al ON ml.assessment_id = al.assessment_id
            WHERE al.class_id = :class_id
            AND (sl.status IS NULL OR sl.status != 'dropped')
            AND (ml.mark IS NULL OR al.hps > 0)
            GROUP BY sl.student_id, sl.name, sl.student_number, sl.status
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $students = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $students[] = $row;
        }
        
        $requirements = [];
        foreach ($students as $student) {
            // Skip if student has dropped status
            if (isset($student['status']) && strtolower($student['status']) === 'dropped') {
                continue;
            }
            
            $quarterRequirements = $this->calculateStudentQuarterRequirements($student);
            
            $requirements[] = [
                'student_id' => $student['student_id'],
                'name' => $student['name'],
                'student_number' => $student['student_number'],
                'status' => $student['status'],
                'current_performance' => $quarterRequirements['current_performance'],
                'required_scores' => $quarterRequirements['required_scores'],
                'can_still_pass' => $quarterRequirements['can_still_pass'],
                'minimum_final_average' => $quarterRequirements['minimum_final_average'],
                'gap_to_passing' => $quarterRequirements['gap_to_passing']
            ];
        }
        
        return [
            'requirements' => $requirements,
            'summary' => $this->calculateRequirementsSummary($requirements)
        ];
    }
    
    /**
     * Calculate individual student's quarter requirements
     */
    private function calculateStudentQuarterRequirements($student, $quarterIds = null) {
        // Dynamic custom quarters for the class (never assume fixed 1-4)
        if ($quarterIds === null) {
            $quarterIds = [];
            foreach ($student as $k => $v) {
                if (preg_match('/^q(\d+)_avg$/', $k, $m)) $quarterIds[] = (int)$m[1];
            }
            sort($quarterIds);
        }
        $completedQuarters = [];
        $remainingQuarters = [];
        $currentTotal = 0;
        $completedCount = 0;

        foreach ($quarterIds as $qid) {
            $quarterKey = 'q'.$qid.'_avg';
            $countKey = 'q'.$qid.'_count';

            if (!empty($student[$quarterKey]) && (!isset($student[$countKey]) || $student[$countKey] > 0)) {
                $completedQuarters[$qid] = floatval($student[$quarterKey]);
                $currentTotal += floatval($student[$quarterKey]);
                $completedCount++;
            } else {
                $remainingQuarters[] = $qid;
            }
        }

        // Calculate current average
        $currentAverage = $completedCount > 0 ? $currentTotal / $completedCount : 0;

        // Calculate what's needed to reach 75% overall
        $targetAverage = 75;
        $totalQuarters = max(1, count($quarterIds));
        
        $requiredScores = [];
        $canStillPass = true;
        $minimumFinalAverage = 0;
        $gapToPassing = 0;
        
        if ($completedCount >= $totalQuarters) {
            // All quarters completed, check if passed
            $canStillPass = $currentAverage >= $targetAverage;
            $gapToPassing = max(0, $targetAverage - $currentAverage);
        } else {
            // Calculate required scores for remaining quarters
            $remainingQuartersCount = $totalQuarters - $completedCount;
            $totalNeeded = $targetAverage * $totalQuarters;
            $remainingNeeded = $totalNeeded - $currentTotal;
            
            if ($remainingQuartersCount > 0) {
                $requiredAverage = $remainingNeeded / $remainingQuartersCount;
                
                // Check if it's possible (required average <= 100)
                if ($requiredAverage > 100) {
                    $canStillPass = false;
                    $requiredAverage = 100; // Show maximum possible
                }
                
                $minimumFinalAverage = $requiredAverage;
                $gapToPassing = max(0, $requiredAverage - $currentAverage);
                
                // Assign required scores to remaining quarters
                foreach ($remainingQuarters as $quarter) {
                    $requiredScores[$quarter] = round($requiredAverage, 2);
                }
            }
        }
        
        return [
            'current_performance' => round($currentAverage, 2),
            'completed_quarters' => $completedQuarters,
            'remaining_quarters' => $remainingQuarters,
            'required_scores' => $requiredScores,
            'can_still_pass' => $canStillPass,
            'minimum_final_average' => round($minimumFinalAverage, 2),
            'gap_to_passing' => round($gapToPassing, 2)
        ];
    }
    
    /**
     * Calculate summary statistics for requirements
     */
    private function calculateRequirementsSummary($requirements) {
        if (empty($requirements)) {
            return [
                'total_students' => 0,
                'can_pass' => 0,
                'cannot_pass' => 0,
                'already_passed' => 0,
                'need_improvement' => 0,
                'average_gap' => 0
            ];
        }
        
        $totalStudents = count($requirements);
        $canPass = 0;
        $cannotPass = 0;
        $alreadyPassed = 0;
        $needImprovement = 0;
        $totalGap = 0;
        
        foreach ($requirements as $req) {
            if ($req['current_performance'] >= 75) {
                $alreadyPassed++;
                $canPass++;
            } elseif ($req['can_still_pass']) {
                $canPass++;
                $needImprovement++;
                $totalGap += $req['gap_to_passing'];
            } else {
                $cannotPass++;
                $totalGap += $req['gap_to_passing'];
            }
        }
        
        $studentsWithGap = $totalStudents - $alreadyPassed;
        $averageGap = $studentsWithGap > 0 ? $totalGap / $studentsWithGap : 0;
        
        return [
            'total_students' => $totalStudents,
            'can_pass' => $canPass,
            'cannot_pass' => $cannotPass,
            'already_passed' => $alreadyPassed,
            'need_improvement' => $needImprovement,
            'average_gap' => round($averageGap, 2),
            'pass_rate' => round(($canPass / $totalStudents) * 100, 2)
        ];
    }

    /**
     * Calculate component-level requirements for students to reach 75% overall average
     */
    public function calculateComponentRequirements($classId) {
        // Get component weights for this class
        $componentSql = "
            SELECT gc.component_id, gc.name, csp.percentage
            FROM grading_components gc
            JOIN component_subject_percentage csp ON gc.component_id = csp.component_id
            JOIN class_list cl ON csp.subject_id = cl.subject_id
            WHERE cl.class_id = :class_id
            ORDER BY gc.name
        ";
        
        $stmt = $this->db->prepare($componentSql);
        $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $components = [];
        $totalWeight = 0;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $components[] = [
                'component_id' => $row['component_id'],
                'name' => $row['name'],
                'percentage' => floatval($row['percentage'])
            ];
            $totalWeight += floatval($row['percentage']);
        }
        
        // Get student performance by component and quarter
        $studentSql = "
            SELECT 
                sl.student_id,
                sl.name,
                sl.student_number,
                sl.status,
                gc.component_id,
                gc.name as component_name,
                al.quarter,
                AVG(ml.mark / al.hps * 100) as component_score,
                COUNT(ml.mark) as assessment_count
            FROM student_list sl
            LEFT JOIN mark_list ml ON sl.student_id = ml.student_id
            LEFT JOIN assessment_list al ON ml.assessment_id = al.assessment_id
            LEFT JOIN grading_components gc ON al.component_id = gc.component_id
            WHERE al.class_id = :class_id
            AND (sl.status IS NULL OR sl.status != 'dropped')
            AND ml.mark IS NOT NULL AND al.hps > 0
            GROUP BY sl.student_id, sl.name, sl.student_number, sl.status, gc.component_id, gc.name, al.quarter
        ";
        
        $stmt = $this->db->prepare($studentSql);
        $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $studentData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $studentId = $row['student_id'];
            if (!isset($studentData[$studentId])) {
                $studentData[$studentId] = [
                    'student_id' => $studentId,
                    'name' => $row['name'],
                    'student_number' => $row['student_number'],
                    'status' => $row['status'],
                    'components' => []
                ];
            }
            
            $componentId = $row['component_id'];
            if (!isset($studentData[$studentId]['components'][$componentId])) {
                $studentData[$studentId]['components'][$componentId] = [
                    'name' => $row['component_name'],
                    'quarters' => []
                ];
            }
            
            $studentData[$studentId]['components'][$componentId]['quarters'][$row['quarter']] = [
                'score' => floatval($row['component_score']),
                'count' => intval($row['assessment_count'])
            ];
        }
        
        // Dynamic quarter split from this class's own custom quarters (never fixed 1-2/3-4):
        // earlier half = baseline, later half = remaining focus
        $allQ = [];
        foreach ($studentData as $sd) {
            foreach ($sd['components'] as $cd) {
                foreach (array_keys($cd['quarters']) as $qq) $allQ[(int)$qq] = true;
            }
        }
        if (empty($allQ)) {
            try {
                $qq = $this->db->prepare("SELECT DISTINCT quarter FROM assessment_list WHERE class_id = :cid ORDER BY quarter ASC");
                $qq->bindValue(':cid', $classId, SQLITE3_INTEGER);
                $qr = $qq->execute();
                while ($r = $qr->fetchArray(SQLITE3_ASSOC)) $allQ[(int)$r['quarter']] = true;
            } catch (Exception $e) {}
        }
        ksort($allQ);
        $qKeys = array_keys($allQ);
        $half = (int)ceil(count($qKeys) / 2);
        $earlyQuarters = array_slice($qKeys, 0, max(1, $half));
        $lateQuarters = array_slice($qKeys, max(1, $half));
        if (empty($lateQuarters)) $lateQuarters = $earlyQuarters;
        $qNames = $this->getQuarterNames($classId);

        // Calculate requirements for each student
        $requirements = [];
        foreach ($studentData as $student) {
            // Skip if student has dropped status
            if (isset($student['status']) && strtolower($student['status']) === 'dropped') {
                continue;
            }

            $componentRequirements = $this->calculateFinalQuarterRequirements($student, $components, $earlyQuarters, $lateQuarters, $qNames);
            
            $requirements[] = [
                'student_id' => $student['student_id'],
                'name' => $student['name'],
                'student_number' => $student['student_number'],
                'status' => $student['status'],
                'current_final_average' => $componentRequirements['current_final_average'],
                'component_requirements' => $componentRequirements['component_requirements'],
                'can_reach_target' => $componentRequirements['can_reach_target'],
                'overall_status' => $componentRequirements['overall_status']
            ];
        }
        
        return [
            'requirements' => $requirements,
            'components' => $components,
            'summary' => $this->calculateComponentRequirementsSummary($requirements)
        ];
    }
    
    /**
     * Calculate individual student's final quarter requirements
     */
    private function calculateFinalQuarterRequirements($student, $components, $earlyQuarters = [1,2], $lateQuarters = [3,4], $quarterNames = []) {
        $qn = function($q) use ($quarterNames) { return $quarterNames[$q] ?? ('Quarter '.$q); };
        $currentFinalTotal = 0;
        $componentRequirements = [];

        // Current average over the class's LATER custom quarters (remaining focus)
        foreach ($student['components'] as $componentId => $componentData) {
            $componentInfo = $this->findComponentById($components, $componentId);
            if (!$componentInfo) continue;

            $finalScore = 0;
            $finalCount = 0;

            foreach ($lateQuarters as $quarter) {
                if (isset($componentData['quarters'][$quarter])) {
                    $finalScore += $componentData['quarters'][$quarter]['score'];
                    $finalCount++;
                }
            }
            
            $avgFinalScore = $finalCount > 0 ? $finalScore / $finalCount : 0;
            $weightedFinalScore = ($avgFinalScore * $componentInfo['percentage']) / 100;
            $currentFinalTotal += $weightedFinalScore;
            
            $componentRequirements[$componentId] = [
                'name' => $componentInfo['name'],
                'percentage' => $componentInfo['percentage'],
                'current_final_score' => round($avgFinalScore, 2),
                'required_improvement_score' => 0
            ];
        }
        
        // Calculate what's needed to reach 75% overall
        $targetAverage = 75;
        $canReachTarget = true;
        $overallStatus = 'On Track';
        
        // Baseline over the class's EARLIER custom quarters
        $midtermTotal = 0;
        $earlyLabels = array_map($qn, $earlyQuarters);
        $lateLabels = array_map($qn, $lateQuarters);
        foreach ($student['components'] as $componentId => $componentData) {
            $componentInfo = $this->findComponentById($components, $componentId);
            if (!$componentInfo) continue;

            $midtermScore = 0;
            $midtermCount = 0;

            foreach ($earlyQuarters as $quarter) {
                if (isset($componentData['quarters'][$quarter])) {
                    $midtermScore += $componentData['quarters'][$quarter]['score'];
                    $midtermCount++;
                }
            }
            
            $avgMidtermScore = $midtermCount > 0 ? $midtermScore / $midtermCount : 0;
            $weightedMidtermScore = ($avgMidtermScore * $componentInfo['percentage']) / 100;
            $midtermTotal += $weightedMidtermScore;
        }
        
        // Calculate current overall average (midterm + final)
        $currentOverallAverage = ($midtermTotal + $currentFinalTotal) / 2;
        
        // Calculate improvement needed
        if ($currentOverallAverage < $targetAverage) {
            $neededImprovement = $targetAverage - $currentOverallAverage;
            
            // Calculate required improvement across all components
            $totalWeight = array_sum(array_column($components, 'percentage'));
            $requiredImprovementAverage = ($neededImprovement / $totalWeight) * 100;
            
            if ($requiredImprovementAverage > 100) {
                $canReachTarget = false;
                $requiredImprovementAverage = 100;
                $overallStatus = 'Cannot Reach Target';
            } else {
                $overallStatus = 'Needs Significant Improvement';
            }
            
            // Apply improvement requirement to components that can still improve
            foreach ($componentRequirements as $componentId => &$req) {
                if ($req['current_final_score'] < 100) {
                    $potentialImprovement = min(100 - $req['current_final_score'], $requiredImprovementAverage);
                    $req['required_improvement_score'] = round($req['current_final_score'] + $potentialImprovement, 2);
                } else {
                    $req['required_improvement_score'] = 100; // Already at max
                }
            }
        } else {
            $overallStatus = 'On Track';
            foreach ($componentRequirements as $componentId => &$req) {
                $req['required_improvement_score'] = round($req['current_final_score'], 2);
            }
        }
        
        return [
            'current_final_average' => round($currentOverallAverage, 2),
            'component_requirements' => $componentRequirements,
            'can_reach_target' => $canReachTarget,
            'overall_status' => $overallStatus
        ];
    }
    
    /**
     * Calculate individual student's component requirements (legacy)
     */
    private function calculateStudentComponentRequirements($student, $components) {
        $currentMidtermTotal = 0;
        $componentRequirements = [];
        $completedQuarters = [];
        
        // Calculate current midterm average (quarters 1-2)
        foreach ($student['components'] as $componentId => $componentData) {
            $componentInfo = $this->findComponentById($components, $componentId);
            if (!$componentInfo) continue;
            
            $midtermScore = 0;
            $midtermCount = 0;
            
            // Get midterm scores (quarters 1-2)
            for ($quarter = 1; $quarter <= 2; $quarter++) {
                if (isset($componentData['quarters'][$quarter])) {
                    $midtermScore += $componentData['quarters'][$quarter]['score'];
                    $midtermCount++;
                    $completedQuarters[] = $quarter;
                }
            }
            
            $avgMidtermScore = $midtermCount > 0 ? $midtermScore / $midtermCount : 0;
            $weightedMidtermScore = ($avgMidtermScore * $componentInfo['percentage']) / 100;
            $currentMidtermTotal += $weightedMidtermScore;
            
            $componentRequirements[$componentId] = [
                'name' => $componentInfo['name'],
                'percentage' => $componentInfo['percentage'],
                'current_midterm_score' => round($avgMidtermScore, 2),
                'required_final_score' => 0
            ];
        }
        
        // Calculate required final scores to maintain 75% average
        $targetAverage = 75;
        $canMaintainAverage = true;
        $overallStatus = 'On Track';
        
        // For a student with 85% midterm, they need much less in final to maintain 75%
        // Formula: (Target * 2) - Midterm = Needed from Final
        $neededFromFinal = ($targetAverage * 2) - $currentMidtermTotal;
        
        // Calculate required average across all components for final
        $totalWeight = array_sum(array_column($components, 'percentage'));
        
        if ($neededFromFinal <= 0) {
            // Student already has midterm >= 75%, needs minimal performance to maintain
            // If midterm is 85%, they only need (75*2 - 85) = 65 from final
            $requiredFinalAverage = max(0, $neededFromFinal);
            $overallStatus = 'On Track';
        } elseif ($neededFromFinal > $totalWeight) {
            // Even 100% in all components won't reach target
            $canMaintainAverage = false;
            $requiredFinalAverage = 100;
            $overallStatus = 'Cannot Reach Target';
        } else {
            // Calculate required final average
            $requiredFinalAverage = ($neededFromFinal / $totalWeight) * 100;
            if ($requiredFinalAverage > 100) {
                $canMaintainAverage = false;
                $requiredFinalAverage = 100;
                $overallStatus = 'Cannot Reach Target';
            } else {
                $overallStatus = 'Needs Improvement';
            }
        }
        
        // Apply calculated required score to all components
        // For students on track (midterm >= 75%), they can maintain with similar performance
        // For students needing improvement, apply the calculated requirement
        foreach ($componentRequirements as $componentId => &$req) {
            if ($overallStatus === 'On Track' && $req['current_midterm_score'] > 0) {
                // Student can maintain with similar performance to midterm
                $req['required_final_score'] = round($req['current_midterm_score'], 2);
            } else {
                // Student needs to improve to reach target
                $req['required_final_score'] = round($requiredFinalAverage, 2);
            }
        }
        
        return [
            'current_midterm_average' => round($currentMidtermTotal, 2),
            'component_requirements' => $componentRequirements,
            'can_maintain_average' => $canMaintainAverage,
            'overall_status' => $overallStatus
        ];
    }
    
    /**
     * Helper function to find component by ID
     */
    private function findComponentById($components, $componentId) {
        foreach ($components as $component) {
            if ($component['component_id'] == $componentId) {
                return $component;
            }
        }
        return null;
    }
    
    /**
     * Calculate summary for component requirements
     */
    private function calculateComponentRequirementsSummary($requirements) {
        if (empty($requirements)) {
            return [
                'total_students' => 0,
                'on_track' => 0,
                'needs_improvement' => 0,
                'cannot_reach_target' => 0,
                'average_current_score' => 0
            ];
        }
        
        $totalStudents = count($requirements);
        $onTrack = 0;
        $needsImprovement = 0;
        $cannotReachTarget = 0;
        $totalCurrentScore = 0;
        
        foreach ($requirements as $req) {
            switch ($req['overall_status']) {
                case 'On Track':
                    $onTrack++;
                    break;
                case 'Needs Significant Improvement':
                    $needsImprovement++;
                    break;
                case 'Cannot Reach Target':
                    $cannotReachTarget++;
                    break;
            }
            $totalCurrentScore += $req['current_final_average'];
        }
        
        return [
            'total_students' => $totalStudents,
            'on_track' => $onTrack,
            'needs_improvement' => $needsImprovement,
            'cannot_reach_target' => $cannotReachTarget,
            'average_current_score' => round($totalCurrentScore / $totalStudents, 2)
        ];
    }

    /**
     * Compare multiple students' predictions
     */
    public function compareStudents($classId, $studentIds) {
        $allPredictions = $this->predictStudentGrade($classId);
        
        $comparison = [];
        foreach ($allPredictions['predictions'] as $prediction) {
            if (in_array($prediction['student_id'], $studentIds)) {
                $comparison[] = $prediction;
            }
        }
        
        // Sort by predicted grade (highest first)
        usort($comparison, function($a, $b) {
            return $b['predicted_grade'] <=> $a['predicted_grade'];
        });
        
        return [
            'comparison' => $comparison,
            'rankings' => array_map(function($student, $index) {
                return [
                    'student_id' => $student['student_id'],
                    'name' => $student['name'],
                    'rank' => $index + 1,
                    'predicted_grade' => $student['predicted_grade'],
                    'will_pass' => $student['will_pass']
                ];
            }, $comparison, array_keys($comparison))
        ];
    }
}
?>
