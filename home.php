<?php
if(!isset($_SESSION['admin_id'])){
    header("Location:./login.php");
    exit;
}
require_once('DBConnection.php');

// Helper to generate dynamic registration URL from current request URL
if (!function_exists('generateRegistrationURL')) {
    function generateRegistrationURL($admin_id) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
        $scheme = $isHttps ? 'https://' : 'http://';
        // Prefer forwarded host (ngrok / proxy) then HTTP_HOST
        $host = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        if ($host === '') {
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        }
        $host = trim($host);
        // Base path of the app (e.g. /grading_system)
        $scriptPath = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
        // When included via index.php?page=home, PHP_SELF is /grading_system/index.php -> base /grading_system
        // When on localhost root or ngrok, keep host+scheme dynamic
        if ($basePath === '.' || $basePath === '\\' || $basePath === '/') $basePath = '';
        $baseUrl = $scheme . $host . $basePath;
        $baseUrl = rtrim($baseUrl, '/\\');
        return $baseUrl . '/students/login.php';
    }
}

// Fetch user data
$user_qry = $conn->query("SELECT * FROM `admin_list` WHERE `admin_id` = '{$_SESSION['admin_id']}'");
$user = $user_qry ? $user_qry->fetchArray(SQLITE3_ASSOC) : [];
$role = $_SESSION['role'] ?? 'admin';

// Query counts & summary metrics safely
$total_classes = 0;
$total_students = 0;
$total_faculty = 0;
$total_assessments = 0;
$recent_classes = [];
$recent_students = [];

try {
    $class_count_qry = $conn->query("SELECT count(*) as count FROM `class_list`");
    if ($class_count_qry) {
        $row = $class_count_qry->fetchArray(SQLITE3_ASSOC);
        $total_classes = $row['count'] ?? 0;
    }
} catch (Exception $e) {}

try {
    $student_count_qry = $conn->query("SELECT count(*) as count FROM `student_list`");
    if ($student_count_qry) {
        $row = $student_count_qry->fetchArray(SQLITE3_ASSOC);
        $total_students = $row['count'] ?? 0;
    }
} catch (Exception $e) {}

try {
    $faculty_count_qry = $conn->query("SELECT count(*) as count FROM `admin_list` WHERE `role` = 'faculty'");
    if ($faculty_count_qry) {
        $row = $faculty_count_qry->fetchArray(SQLITE3_ASSOC);
        $total_faculty = $row['count'] ?? 0;
    }
} catch (Exception $e) {}

try {
    $assessment_count_qry = $conn->query("SELECT count(*) as count FROM `assessment_list`");
    if ($assessment_count_qry) {
        $row = $assessment_count_qry->fetchArray(SQLITE3_ASSOC);
        $total_assessments = $row['count'] ?? 0;
    }
} catch (Exception $e) {}

// Fetch recent classes
try {
$classes_qry = $conn->query("
    SELECT 
        c.*,
        s.name AS subject_name
    FROM class_list c
    LEFT JOIN subjects s 
        ON c.subject_id = s.subject_id
    ORDER BY c.class_id DESC
    LIMIT 5
");    if ($classes_qry) {
        while ($row = $classes_qry->fetchArray(SQLITE3_ASSOC)) {
            $recent_classes[] = $row;
        }
    }
} catch (Exception $e) {}

// Fetch recent students
try {
$students_qry = $conn->query("
    SELECT 
        s.*,
        sub.name AS class_name
    FROM student_list s
    LEFT JOIN class_list c 
        ON s.class_id = c.class_id
    LEFT JOIN subjects sub 
        ON c.subject_id = sub.subject_id
    ORDER BY s.student_id DESC
    LIMIT 5
");
    if ($students_qry) {
        while ($row = $students_qry->fetchArray(SQLITE3_ASSOC)) {
            $recent_students[] = $row;
        }
    }
} catch (Exception $e) {}

$registration_url = generateRegistrationURL($_SESSION['admin_id']);
?>

<div class="row g-4 mb-4">
    <!-- Stat Widget 1: Total Classes -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box brand">
                <i class="fas fa-chalkboard"></i>
            </div>
            <div class="horizon-widget-content">
                <span class="horizon-widget-title">Active Classes</span>
                <h3 class="horizon-widget-value"><?php echo number_format($total_classes); ?></h3>
                <span class="horizon-widget-trend positive">
                    <i class="fas fa-arrow-up"></i> Academic Sections
                </span>
            </div>
        </div>
    </div>

    <!-- Stat Widget 2: Enrolled Students -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box green">
                <i class="fas fa-user-graduate"></i>
            </div>
            <div class="horizon-widget-content">
                <span class="horizon-widget-title">Total Students</span>
                <h3 class="horizon-widget-value"><?php echo number_format($total_students); ?></h3>
                <span class="horizon-widget-trend positive">
                    <i class="fas fa-check-circle"></i> Registered Enrollees
                </span>
            </div>
        </div>
    </div>

    <!-- Stat Widget 3: Assessments -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box amber">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="horizon-widget-content">
                <span class="horizon-widget-title">Assessments</span>
                <h3 class="horizon-widget-value"><?php echo number_format($total_assessments); ?></h3>
                <span class="horizon-widget-trend" style="color: var(--horizon-amber-500);">
                    <i class="fas fa-chart-bar"></i> Quizzes & Exams
                </span>
            </div>
        </div>
    </div>

    <!-- Stat Widget 4: Faculty Accounts -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box" style="background-color: rgba(0, 181, 216, 0.1); color: var(--horizon-cyan-500);">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="horizon-widget-content">
                <span class="horizon-widget-title">Faculty Staff</span>
                <h3 class="horizon-widget-value"><?php echo number_format($total_faculty); ?></h3>
                <span class="horizon-widget-trend" style="color: var(--horizon-cyan-500);">
                    <i class="fas fa-shield-alt"></i> Active Instructors
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Middle Row: Hero Banner & Quick Actions -->
<div class="row g-4 mb-4">
    <!-- Academic Portal Overview Card -->
    <div class="col-12 col-lg-8">
        <div class="horizon-card h-100 p-4" style="background: var(--horizon-brand-linear); color: #FFFFFF; position: relative; overflow: hidden;">
            <div style="position: absolute; right: -20px; bottom: -20px; font-size: 180px; color: rgba(255,255,255,0.06); pointer-events: none;">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="position-relative z-1">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-white text-primary fw-bold" style="border-radius: 8px;">HORIZON 2.0</span>
                    <span class="text-white-50" style="font-size: 13px;">Welcome back, <?php echo htmlspecialchars($_SESSION['fullname'] ?? 'Faculty'); ?></span>
                </div>
                <h2 class="text-white fw-bold mb-2" style="font-size: 26px; letter-spacing: -0.02em;">
                    ESSU Grading System Dashboard
                </h2>
                <p class="text-white-50 mb-4" style="max-width: 540px; font-size: 14px; line-height: 1.6;">
                    Manage your curriculum assessments, compute transmutation grades automatically, and analyze at-risk students with AI performance forecasting.
                </p>

                <!-- Student Self-Registration Quick Link -->
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.12); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <span class="fw-bold" style="font-size: 13px;">
                            <i class="fas fa-qrcode me-1"></i> Student Self-Registration Link
                        </span>
                        <span id="copy-feedback" class="badge bg-success text-white" style="display:none;">Copied!</span>
                    </div>
                    <div class="input-group">
                        <input type="text" id="reg-link-input" class="form-control form-control-sm text-dark bg-white border-0" value="<?php echo htmlspecialchars($registration_url); ?>" readonly style="font-size: 12px;">
                        <button type="button" class="btn btn-light btn-sm fw-bold" onclick="copyRegUrl()">
                            <i class="fas fa-copy me-1"></i> Copy
                        </button>
                        <a href="<?php echo htmlspecialchars($registration_url); ?>" target="_blank" class="btn btn-outline-light btn-sm fw-bold">
                            <i class="fas fa-external-link-alt"></i> Open
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Card -->
    <div class="col-12 col-lg-4">
        <div class="horizon-card h-100">
            <div class="horizon-card-header">
                <h5><i class="fas fa-bolt text-warning me-2"></i>Quick Actions</h5>
            </div>
            <div class="horizon-card-body d-flex flex-column gap-2">
                <a href="./?page=class" class="btn btn-secondary w-100 justify-content-start py-2.5">
                    <div class="horizon-widget-icon-box brand" style="width: 34px; height: 34px; min-width: 34px; font-size: 14px;">
                        <i class="fas fa-plus"></i>
                    </div>
                    <span>Create / Manage Classes</span>
                </a>
                <a href="./?page=students" class="btn btn-secondary w-100 justify-content-start py-2.5">
                    <div class="horizon-widget-icon-box green" style="width: 34px; height: 34px; min-width: 34px; font-size: 14px;">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <span>Enroll Students</span>
                </a>
                <a href="./?page=assessments" class="btn btn-secondary w-100 justify-content-start py-2.5">
                    <div class="horizon-widget-icon-box amber" style="width: 34px; height: 34px; min-width: 34px; font-size: 14px;">
                        <i class="fas fa-edit"></i>
                    </div>
                    <span>Record Marks & Attendance</span>
                </a>
                <a href="./?page=reports" class="btn btn-secondary w-100 justify-content-start py-2.5">
                    <div class="horizon-widget-icon-box" style="width: 34px; height: 34px; min-width: 34px; font-size: 14px; background: rgba(134, 140, 255, 0.15); color: var(--horizon-brand-400);">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <span>Generate Grade Sheets</span>
                </a>
                <a href="./?page=prediction_dashboard" class="btn btn-secondary w-100 justify-content-start py-2.5">
                    <div class="horizon-widget-icon-box" style="width: 34px; height: 34px; min-width: 34px; font-size: 14px; background: rgba(238, 93, 80, 0.15); color: var(--horizon-red-500);">
                        <i class="fas fa-brain"></i>
                    </div>
                    <span>AI Risk Prediction</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Row: Recent Classes & Student Activity Table -->
<div class="row g-4">
    <!-- Active Classes Table Card (Horizon Complex Table) -->
    <div class="col-12 col-xl-7">
        <div class="horizon-card h-100">
            <div class="horizon-card-header">
                <h5><i class="fas fa-chalkboard text-primary me-2"></i>Recent Classes</h5>
                <a href="./?page=class" class="btn btn-sm btn-secondary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="horizon-table">
                    <thead>
                        <tr>
                            <th>Class Name / Subject</th>
                            <th>Section & Level</th>
                            <th>Code</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($recent_classes)): ?>
                            <?php foreach($recent_classes as $c): ?>
                            <tr>
                                <td>
<div class="fw-bold text-dark">
    <?php echo htmlspecialchars($c['subject_name'] ?? 'Subject'); ?>
</div>

<small class="text-muted">
    <?php echo htmlspecialchars($c['course_no'] ?? ''); ?>
</small>                                </td>
                                <td>
<span class="badge badge-brand">
    <?php echo htmlspecialchars($c['grade'] ?? 'N/A'); ?> -
    <?php echo htmlspecialchars($c['section'] ?? ''); ?>
</span>                                </td>
                                <td>
<code class="fw-bold text-primary">
    <?php echo htmlspecialchars($c['course_no'] ?? 'N/A'); ?>
</code>                                </td>
                                <td class="text-end">
                                    <a href="./?page=assessments&class_id=<?php echo $c['class_id']; ?>" class="btn btn-sm btn-primary" title="View Class Assessments">
                                        <i class="fas fa-tasks"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                           <?php
$student_name = trim(
    ($s['lastname'] ?? '') . ', ' .
    ($s['firstname'] ?? '')
);

if ($student_name === ',') {
    $student_name = $s['name'] ?? 'Student';
}
?>

<div class="fw-bold text-dark" style="font-size: 13px;">
    <?php echo htmlspecialchars($student_name); ?>
</div>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Enrolled Students Card -->
    <div class="col-12 col-xl-5">
        <div class="horizon-card h-100">
            <div class="horizon-card-header">
                <h5><i class="fas fa-user-graduate text-success me-2"></i>Recent Enrollees</h5>
                <a href="./?page=students" class="btn btn-sm btn-secondary">View All</a>
            </div>
            <div class="horizon-card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if(!empty($recent_students)): ?>
                        <?php foreach($recent_students as $s): ?>
                        <li class="list-group-item d-flex align-items-center justify-content-between p-3 border-bottom" style="background: transparent;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="horizon-widget-icon-box brand" style="width: 40px; height: 40px; min-width: 40px; font-size: 16px;">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark" style="font-size: 13px;">
                                        <?php echo htmlspecialchars(($s['lastname'] ?? '') . ', ' . ($s['firstname'] ?? '')); ?>
                                    </div>
                                    <small class="text-muted">ID: <?php echo htmlspecialchars($s['code'] ?? 'N/A'); ?> &bull; <?php echo htmlspecialchars($s['class_name'] ?? 'Section'); ?></small>
                                </div>
                            </div>
                            <span class="badge badge-success">Active</span>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item text-center py-4 text-muted border-0" style="background: transparent;">
                            No recent students registered.
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function copyRegUrl() {
    const input = document.getElementById('reg-link-input');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        const feedback = document.getElementById('copy-feedback');
        if (feedback) {
            feedback.style.display = 'inline-block';
            setTimeout(() => feedback.style.display = 'none', 3000);
        }
        window.showAppToast('success', 'Registration link copied to clipboard!');
    }).catch(err => {
        document.execCommand('copy');
        window.showAppToast('success', 'Registration link copied!');
    });
}
</script>
