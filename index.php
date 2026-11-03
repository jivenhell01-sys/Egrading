<?php
// Session cookie setup.
// NOTE: the environment-aware HTTPS enforcement that used to live here
// (an https:// 302 for any non-local hostname, plus its is_local_request()
// helper) was removed at the user's request. The app is served over plain
// HTTP on this host, so the session cookie must stay non-secure - which is
// also what login.php, DBConnection.php, Actions.php and check_session.php
// already do.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header("Location:./login.php");
    exit;
}
require_once('DBConnection.php');
// NOTE: the old device-registration gate (which redirected users without a
// registered device to a separate registration page) was removed. Login goes
// straight to the dashboard; faculty database routing is already handled at
// login time (Actions.php::login() sets db_file).
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Handle landing registrations CRUD (admin-only page, own database)
if ($page === 'landing_registrations' && (isset($_GET['delete']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['landing_action'])))) {
    require_once 'landing_db.php';
    $landing_conn = landing_db();

    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $lid = (int)$_GET['delete'];
        if ($landing_conn->exec("DELETE FROM `landing_registrations` WHERE id = '{$lid}'") && $landing_conn->changes() > 0) {
            $_SESSION['flashdata'] = ['type' => 'success', 'msg' => 'Registration deleted successfully.'];
        } else {
            $_SESSION['flashdata'] = ['type' => 'danger', 'msg' => 'Record not found or could not be deleted.'];
        }
        header("Location: ?page=landing_registrations");
        exit();
    }

    // Bulk delete (checkboxes + Delete Selected) - supports JSON fetch and form POST
    $isBulkJson = false;
    $bulkIds = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $rawBulk = file_get_contents('php://input');
            $jBulk = json_decode($rawBulk, true);
            if (is_array($jBulk) && isset($jBulk['ids']) && ($jBulk['landing_action'] ?? '') === 'delete_bulk') {
                $bulkIds = $jBulk['ids'];
                $isBulkJson = true;
            } elseif (is_array($jBulk) && isset($jBulk['ids']) && empty($jBulk['landing_action'])) {
                // also accept {ids:[...]} without landing_action when posted to delete_landing_bulk.php but kept for symmetry
                $bulkIds = $jBulk['ids'];
                $isBulkJson = true;
            }
            // also accept direct {landing_action:delete_bulk, ids:[]}
            if (empty($bulkIds) && is_array($jBulk) && ($jBulk['landing_action'] ?? '') === 'delete_bulk' && isset($jBulk['ids'])) {
                $bulkIds = $jBulk['ids'];
                $isBulkJson = true;
            }
        }
        if (!$isBulkJson && ($_POST['landing_action'] ?? '') === 'delete_bulk') {
            $bulkIds = $_POST['ids'] ?? [];
            if (is_string($bulkIds)) {
                $dec = json_decode($bulkIds, true);
                if (is_array($dec)) $bulkIds = $dec;
                else $bulkIds = explode(',', $bulkIds);
            }
        }
        if (!empty($bulkIds) || $isBulkJson || (($_POST['landing_action'] ?? '') === 'delete_bulk')) {
            if (!empty($bulkIds) || $isBulkJson) {
                if (is_string($bulkIds)) {
                    $dec = json_decode($bulkIds, true);
                    if (is_array($dec)) $bulkIds = $dec;
                    else $bulkIds = explode(',', $bulkIds);
                }
                $bulkIds = array_values(array_filter(array_map('intval', (array)$bulkIds), fn($v) => $v > 0));
                $bulkIds = array_values(array_unique($bulkIds));
                if ($isBulkJson) {
                    header('Content-Type: application/json');
                    if (empty($bulkIds)) {
                        echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided']);
                        exit;
                    }
                    $inBulk = implode(',', $bulkIds);
                    $okBulk = $landing_conn->exec("DELETE FROM `landing_registrations` WHERE id IN ($inBulk)");
                    echo json_encode($okBulk && $landing_conn->changes() > 0 ? ['status' => 'success', 'message' => $landing_conn->changes() . ' registration(s) deleted.', 'deleted' => $landing_conn->changes()] : ['status' => 'error', 'message' => 'No matching registrations found.']);
                    exit;
                } else {
                    if (empty($bulkIds)) {
                        $_SESSION['flashdata'] = ['type' => 'danger', 'msg' => 'No registrations selected.'];
                    } else {
                        $inBulk = implode(',', $bulkIds);
                        $okBulk = $landing_conn->exec("DELETE FROM `landing_registrations` WHERE id IN ($inBulk)");
                        $chg = $landing_conn->changes();
                        $_SESSION['flashdata'] = $okBulk && $chg > 0 ? ['type' => 'success', 'msg' => $chg . ' registration(s) deleted.'] : ['type' => 'danger', 'msg' => 'No matching registrations found.'];
                    }
                    header("Location: ?page=landing_registrations");
                    exit;
                }
            } else {
                // empty bulk submission (no ids sent)
                if ($isBulkJson) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided']);
                    exit;
                }
                $_SESSION['flashdata'] = ['type' => 'danger', 'msg' => 'No registrations selected.'];
                header("Location: ?page=landing_registrations");
                exit;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['landing_action'] ?? '') === 'save') {
        $lid = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        $f = [];
        foreach (['student_number', 'lastname', 'firstname', 'middlename', 'name_extension', 'course', 'year', 'section', 'birthdate', 'age', 'gender', 'contact_number', 'email', 'barangay', 'municipality', 'province', 'zipcode', 'status'] as $k) {
            $f[$k] = $landing_conn->escapeString(trim($_POST[$k] ?? ''));
        }
        if (in_array(strtolower($f['name_extension']), ['none', 'n/a', 'na'], true)) {
            $f['name_extension'] = '';
        }
        $f['name'] = $f['lastname'] . ', ' . $f['firstname'];
        if ($f['middlename'] !== '' && strtolower($f['middlename']) !== 'none') {
            $f['name'] .= ' ' . $f['middlename'];
        }
        if ($f['name_extension'] !== '') {
            $f['name'] .= ' ' . $f['name_extension'];
        }
        if ($f['student_number'] === '' || $f['lastname'] === '' || $f['firstname'] === '' || $f['course'] === '' || $f['year'] === '' || $f['section'] === '') {
            $_SESSION['flashdata'] = ['type' => 'danger', 'msg' => 'Student Number, Last Name, First Name, Course, Year and Section are required.'];
        } else {
            $dup = $landing_conn->querySingle("SELECT COUNT(*) FROM `landing_registrations` WHERE student_number = '{$f['student_number']}'" . ($lid > 0 ? " AND id != '{$lid}'" : ''));
            if ($dup > 0) {
                $_SESSION['flashdata'] = ['type' => 'danger', 'msg' => 'This student number is already registered.'];
            } else {
                $cols = ['student_number', 'name', 'middlename', 'firstname', 'lastname', 'name_extension', 'course', 'year', 'section', 'birthdate', 'age', 'gender', 'contact_number', 'email', 'barangay', 'municipality', 'province', 'zipcode', 'status'];
                if ($lid > 0) {
                    $sets = [];
                    foreach ($cols as $c) {
                        $sets[] = "`{$c}` = '{$f[$c]}'";
                    }
                    $sets[] = "`updated_at` = CURRENT_TIMESTAMP";
                    $ok = $landing_conn->exec("UPDATE `landing_registrations` SET " . implode(',', $sets) . " WHERE id = '{$lid}'");
                    $_SESSION['flashdata'] = $ok
                        ? ['type' => 'success', 'msg' => 'Registration updated successfully.']
                        : ['type' => 'danger', 'msg' => 'Failed to update registration.'];
                } else {
                    if ($f['status'] === '') {
                        $f['status'] = 'pending';
                    }
                    $ok = $landing_conn->exec("INSERT INTO `landing_registrations` (`" . implode('`,`', $cols) . "`) VALUES ('" . implode("','", array_map(function ($c) use ($f) {
                        return $f[$c];
                    }, $cols)) . "')");
                    $_SESSION['flashdata'] = $ok
                        ? ['type' => 'success', 'msg' => 'Registration added successfully.']
                        : ['type' => 'danger', 'msg' => 'Failed to save registration.'];
                }
            }
        }
        header("Location: ?page=landing_registrations");
        exit();
    }
}

// Handle manage_images actions
if ($page === 'manage_images' && isset($_REQUEST['action'])) {
    require_once 'class.UploadedImages.php';
    $uploaded_image = new UploadedImages($conn);

    switch ($_REQUEST['action']) {
        case 'delete':
            if (isset($_GET['id']) && is_numeric($_GET['id'])) {
                $uploaded_image->id = (int)$_GET['id'];

                if ($uploaded_image->readOne()) {
                    $image_info = $uploaded_image->image_type . ' - ' . $uploaded_image->image_position;

                    if ($uploaded_image->delete()) {
                        $_SESSION['success_msg'] = "Image '$image_info' deleted successfully!";
                    } else {
                        $_SESSION['error_msg'] = "Failed to delete image '$image_info'.";
                    }
                } else {
                    $_SESSION['error_msg'] = "Image not found.";
                }
            } else {
                $_SESSION['error_msg'] = "Invalid image ID.";
            }
            header("Location: ?page=manage_images");
            exit();
            break;

        case 'toggle':
            if (isset($_GET['id']) && is_numeric($_GET['id'])) {
                $uploaded_image->id = (int)$_GET['id'];

                if ($uploaded_image->readOne()) {
                    $image_info = $uploaded_image->image_type . ' - ' . $uploaded_image->image_position;
                    $new_status = $uploaded_image->is_active ? 'deactivated' : 'activated';

                    if ($uploaded_image->toggleActive()) {
                        $_SESSION['success_msg'] = "Image '$image_info' $new_status successfully!";
                    } else {
                        $_SESSION['error_msg'] = "Failed to update image status for '$image_info'.";
                    }
                } else {
                    $_SESSION['error_msg'] = "Image not found.";
                }
            } else {
                $_SESSION['error_msg'] = "Invalid image ID.";
            }
            header("Location: ?page=manage_images");
            exit();
            break;

        case 'update_dimensions':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
                header('Content-Type: application/json');

                try {
                    $uploaded_image->id = (int)$_POST['id'];
                    $uploaded_image->image_width = (int)$_POST['width'];
                    $uploaded_image->image_height = (int)$_POST['height'];

                    if ($uploaded_image->update()) {
                        echo json_encode([
                            "success" => true,
                            "message" => "Image dimensions updated successfully!"
                        ]);
                    } else {
                        echo json_encode([
                            "success" => false,
                            "message" => "Failed to update image dimensions - database update failed."
                        ]);
                    }
                } catch (Exception $e) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Error: " . $e->getMessage()
                    ]);
                }
                exit();
            }
            break;
    }
}

// Fetch user data including profile image
$user_qry = $conn->query("SELECT * FROM `admin_list` WHERE `admin_id` = '{$_SESSION['admin_id']}'");
$user = $user_qry ? $user_qry->fetchArray(SQLITE3_ASSOC) : [];

// Store user data in session if not already set
if (!isset($_SESSION['image']) && !empty($user['image'])) {
    $_SESSION['image'] = $user['image'];
}

$defaultAvatarSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><rect width="128" height="128" rx="24" fill="#e2e8f0"/><circle cx="64" cy="48" r="28" fill="#94a3b8"/><path d="M24 118c6-28 30-44 40-44s34 16 40 44" fill="#cbd5f5"/></svg>';
$defaultAvatar = 'data:image/svg+xml;base64,' . base64_encode($defaultAvatarSvg);

$role = $_SESSION['role'] ?? 'admin';
$allowedPages = [];
if ($role === 'faculty' && !empty($_SESSION['allowed_pages'])) {
    $decodedPages = json_decode($_SESSION['allowed_pages'], true);
    if (is_array($decodedPages)) {
        $allowedPages = $decodedPages;
    }
}

if (!function_exists('has_page_access')) {
    function has_page_access($key, $role, $allowedPages)
    {
        if ($role !== 'faculty') {
            return true;
        }
        if ($key === 'manage_account') {
            return true;
        }
        if (empty($allowedPages)) {
            return false;
        }
        return in_array($key, $allowedPages);
    }

    function has_any_access($keys, $role, $allowedPages)
    {
        foreach ($keys as $key) {
            if (has_page_access($key, $role, $allowedPages)) {
                return true;
            }
        }
        return false;
    }
}

$assessmentHubPages = ['marks', 'attendance'];
if (in_array($page, $assessmentHubPages, true)) {
    $params = $_GET;
    $params['page'] = 'assessments';
    $params['tab'] = $page;
    header('Location: ./?' . http_build_query($params));
    exit;
}

$reportHubPages = ['report_of_grades', 'transmutation_table'];
if (in_array($page, $reportHubPages, true)) {
    $params = $_GET;
    $params['page'] = 'reports';
    $params['tab'] = $page;
    header('Location: ./?' . http_build_query($params));
    exit;
}

// Header/Footer settings page removed - send direct visits back home
if ($page === 'header_footer_settings') {
    header('Location: ./');
    exit;
}

// Landing registrations page requires the module grant (admins always pass)
if ($page === 'landing_registrations' && !has_page_access($page, $role, $allowedPages)) {
    header('Location: ./');
    exit;
}
// Device logs is admin-only; faculty may only see it if explicitly granted.
if ($page === 'admin/device_logs' && $role === 'faculty' && !has_page_access($page, $role, $allowedPages)) {
    header('Location: ./');
    exit;
}

$pageAllowed = has_page_access($page, $role, $allowedPages);
if ($page === 'assessments') {
    $pageAllowed = has_any_access(['assessments', 'marks', 'attendance'], $role, $allowedPages);
}
if ($page === 'quiz_generator') {
    $pageAllowed = has_any_access(['quiz_generator', 'assessments', 'marks', 'attendance', 'class'], $role, $allowedPages);
}
if ($page === 'reports') {
    $pageAllowed = has_any_access(['reports', 'report_of_grades', 'transmutation_table'], $role, $allowedPages);
}

if ($role === 'faculty' && !$pageAllowed) {
    $fallback = !empty($allowedPages) ? $allowedPages[0] : 'manage_account';
    header("Location: ./?page=" . urlencode($fallback));
    exit;
}

if ($page === 'assessments' && $role === 'faculty') {
    $requestedTab = isset($_GET['tab']) ? $_GET['tab'] : 'assessments';
    $validTabs = ['assessments', 'marks', 'attendance'];
    if (!in_array($requestedTab, $validTabs, true)) {
        $requestedTab = 'assessments';
    }
    if (!has_page_access($requestedTab, $role, $allowedPages)) {
        foreach ($validTabs as $tab) {
            if (has_page_access($tab, $role, $allowedPages)) {
                $params = $_GET;
                $params['page'] = 'assessments';
                $params['tab'] = $tab;
                header('Location: ./?' . http_build_query($params));
                exit;
            }
        }
    }
}

if ($page === 'reports' && $role === 'faculty') {
    $requestedTab = isset($_GET['tab']) ? $_GET['tab'] : 'reports';
    $validTabs = ['reports', 'report_of_grades', 'transmutation_table'];
    if (!in_array($requestedTab, $validTabs, true)) {
        $requestedTab = 'reports';
    }
    if (!has_page_access($requestedTab, $role, $allowedPages)) {
        foreach ($validTabs as $tab) {
            if (has_page_access($tab, $role, $allowedPages)) {
                $params = $_GET;
                $params['page'] = 'reports';
                $params['tab'] = $tab;
                header('Location: ./?' . http_build_query($params));
                exit;
            }
        }
    }
}

$pageTitles = [
    'home' => 'Main Dashboard',
    'class' => 'Class Management',
    'students' => 'Student Directory',
    'assessments' => 'Assessment Hub',
    'quiz_generator' => 'Quiz Generator',
    'reports' => 'Reports & Transmutation',
    'database_transfer' => 'Database Transfer',
    'prediction_dashboard' => 'Prediction Dashboard',
    'component_prediction' => 'Component Analysis',
    'maintenance' => 'System Maintenance',
    'manage_account' => 'My Account & Profile',
    'admin/faculty' => 'Faculty Management',
    'admin/departments' => 'Departments & Courses',
    'admin/monitoring' => 'System Monitoring',
    'admin/device_logs' => 'Device Logs',
    'users' => 'User Management',
    'manage_images' => 'Image Management',
    'landing_registrations' => 'Landing Registrations'
];

$currentPageTitle = $pageTitles[$page] ?? ucwords(str_replace('_', ' ', $page));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="uploads/Eastern_Samar_State_University_logo.ico" type="image/x-icon">
    <meta name="theme-color" content="#4318FF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="HORIZON">
    <link rel="apple-touch-icon" href="students/icons/apple-touch-icon.png">
    <title><?php echo htmlspecialchars($currentPageTitle); ?> | Horizon Grading System</title>

    <!-- Icons & Core Styles -->
    <link rel="stylesheet" href="./Font-Awesome-master/css/all.min.css">
    <link rel="stylesheet" href="./css/bootstrap.min.css">
    <?php
    // Perf: DataTables CSS/JS only on pages that actually render tables.
    // Saves ~250KB + parse time on dashboard/profile/maintenance pages.
    $datatablesPages = ['class', 'students', 'assessments', 'reports', 'admin/faculty', 'admin/monitoring', 'admin/device_logs', 'landing_registrations', 'prediction_dashboard', 'component_prediction', 'maintenance', 'database_transfer', 'marks', 'attendance', 'report_of_grades', 'transmutation_table'];
    $needsDataTables = in_array($page, $datatablesPages, true);
    if ($needsDataTables): ?>
        <link rel="stylesheet" href="./DataTables/datatables.min.css">
    <?php endif; ?>

    <!-- Horizon Theme Engine -->
    <link rel="stylesheet" href="./css/horizon-theme.css">
    <script>
        try {
            if (localStorage.getItem('horizon-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (error) {}
    </script>

    <!-- Scripts: jQuery stays sync (inline scripts use $ immediately).
         Everything else deferred so first paint isn't blocked.
         NOTE: Font-Awesome all.min.js (1.2MB) removed — all.min.css already
         renders every <i class="fas ..."> icon via webfonts. -->
    <script src="./js/jquery-3.6.0.min.js"></script>
    <script src="./js/popper.min.js" defer></script>
    <script src="./js/bootstrap.min.js" defer></script>
    <?php if ($needsDataTables): ?>
        <script src="./DataTables/datatables.min.js" defer></script>
    <?php endif; ?>
    <script src="./js/script.js" defer></script>
    <script src="./js/horizon.js" defer></script>
    <script>
        window.HORIZON_SYNC_SERVER = 'https://essuc-academic.yzz.me/';
    </script>
</head>

<body id="app-body">
    <!-- Mobile Sidebar Backdrop Overlay -->
    <div id="horizon-sidebar-overlay" class="sidebar-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);z-index:1039;display:none;"></div>

    <div id="app-layout">
        <!-- HORIZON SIDEBAR -->
        <aside id="horizon-sidebar">
            <!-- Brand Header -->
            <div class="horizon-brand">
                <img src="uploads/Eastern_Samar_State_University_logo.ico" alt="Logo" class="horizon-brand-logo" onerror="this.style.display='none'">
                <div>
                    <div class="horizon-brand-title">HORIZON <span>GRADING</span></div>
                    <div class="horizon-brand-subtitle">ESSU Academic System</div>
                </div>
                <!-- Desktop Sidebar Collapse Toggle -->
                <button type="button" id="horizon-sidebar-toggle" class="horizon-sidebar-toggle" aria-label="Collapse Sidebar" title="Collapse Sidebar">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>

            <!-- Floating Expand Tab (visible when sidebar is collapsed) -->
            <button type="button" id="horizon-sidebar-expand" class="horizon-sidebar-expand" aria-label="Expand Sidebar" title="Expand Sidebar">
                <i class="fas fa-chevron-right"></i>
            </button>

            <!-- User Mini Profile Card -->
            <div class="horizon-user-profile-widget">
                <img src="<?php echo !empty($user['image']) ? htmlspecialchars($user['image']) : './image/68ddeb9e9db18_pexels-photo-1097456.jpeg' ?>"
                    alt="Profile"
                    class="horizon-user-avatar"
                    onerror="this.src='./image/68ddeb9e9db18_pexels-photo-1097456.jpeg'">
                <div class="horizon-user-details">
                    <div class="horizon-user-name" title="<?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?>">
                        <?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?>
                    </div>
                    <div class="horizon-user-role">
                        <?php echo ucwords(str_replace('_', ' ', $role)); ?>
                    </div>
                </div>
            </div>

            <!-- Navigation Links -->
            <ul class="horizon-nav-list">
                <li class="horizon-nav-group-title">Main Navigation</li>

                <?php if (has_page_access('home', $role, $allowedPages)): ?>
                    <li class="horizon-nav-item <?php echo ($page == 'home') ? 'active' : '' ?>">
                        <a href="./" class="horizon-nav-link <?php echo ($page == 'home') ? 'active' : '' ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (has_page_access('class', $role, $allowedPages)):
                    // Perf: no DB work on page load. Use session-cached count (refreshed
                    // async by get_all_pending.php?fast=1); JS below fetches the exact
                    // count and updates the badge + localStorage within ~100ms.
                    $sidebarPending = (int)($_SESSION['sidebar_pending_cache'] ?? 0);
                    if ($sidebarPending < 0) $sidebarPending = 0;
                    if ($sidebarPending > 99) $sidebarPending = 99;
                ?>
                    <li class="horizon-nav-item <?php echo ($page == 'class') ? 'active' : '' ?>" style="position:relative;">
                        <a href="./?page=class" class="horizon-nav-link <?php echo ($page == 'class') ? 'active' : '' ?>">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span>Classes</span>
                            <span class="pending-nav-badge badge bg-warning text-dark ms-auto" id="sidebarPendingBadge" style="<?php echo $sidebarPending > 0 ? '' : 'display:none;'; ?> font-size:0.65rem; padding:4px 6px; border-radius:999px;"><?php echo $sidebarPending > 0 ? $sidebarPending : 0; ?></span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (has_page_access('students', $role, $allowedPages)): ?>
                    <li class="horizon-nav-item <?php echo ($page == 'students') ? 'active' : '' ?>">
                        <a href="./?page=students" class="horizon-nav-link <?php echo ($page == 'students') ? 'active' : '' ?>">
                            <i class="fas fa-user-graduate"></i>
                            <span>Students</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (has_any_access(['assessments', 'marks', 'attendance'], $role, $allowedPages)): ?>
                    <li class="horizon-nav-item <?php echo ($page == 'assessments') ? 'active' : '' ?>">
                        <a href="./?page=assessments" class="horizon-nav-link <?php echo ($page == 'assessments') ? 'active' : '' ?>">
                            <i class="fas fa-clipboard-check"></i>
                            <span>Assessments</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (has_any_access(['reports', 'report_of_grades', 'transmutation_table'], $role, $allowedPages)): ?>
                    <li class="horizon-nav-item <?php echo ($page == 'reports') ? 'active' : '' ?>">
                        <a href="./?page=reports" class="horizon-nav-link <?php echo ($page == 'reports') ? 'active' : '' ?>">
                            <i class="fas fa-chart-pie"></i>
                            <span>Reports & Grading</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (has_any_access(['quiz_generator', 'assessments', 'marks', 'attendance'], $role, $allowedPages)): ?>
                    <li class="horizon-nav-item<?php echo ($page == 'quiz_generator') ? 'active open' : '' ?>">
                        <a href="./?page=quiz_generator" class="horizon-nav-link <?php echo ($page == 'quiz_generator') ? 'active' : '' ?>">
                            <i class="fas fa-brain"></i>
                            <span class="flex-grow-1">Quiz Generator</span>
                        </a>
                    </li>
                <?php endif; ?>



                <!-- Pending Students Popup (anchored to Classes navigation) -->
                <div id="sidebarPendingPopup" class="card shadow-lg border-0" style="display:none; position:absolute; left:295px; top:140px; z-index:1060; width:360px; max-height:420px; overflow:hidden; border-radius:12px;">
                    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center py-2 px-3">
                        <h6 class="mb-0 small fw-bold"><i class="fas fa-clock me-1"></i>Pending Students</h6>
                        <button type="button" class="btn-close btn-sm" id="closeSidebarPending" aria-label="Close"></button>
                    </div>
                    <div class="card-body p-0" id="sidebarPendingBody" style="max-height:320px; overflow-y:auto;">
                        <div class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <div class="small mt-2">Loading...</div>
                        </div>
                    </div>
                    <div class="card-footer bg-light py-2 small text-muted text-center" id="sidebarPendingFooter" style="display:none;"></div>
                </div>

                <li class="horizon-nav-group-title">Analytics & Tools</li>

                <?php if (has_page_access('prediction_dashboard', $role, $allowedPages)): ?>
                    <li class="horizon-nav-item has-horizon-submenu <?php echo (in_array($page, ['prediction_dashboard', 'student_comparison', 'component_prediction'])) ? 'active open' : '' ?>">
                        <a href="#" class="horizon-nav-link <?php echo (in_array($page, ['prediction_dashboard', 'student_comparison', 'component_prediction'])) ? 'active' : '' ?>">
                            <i class="fas fa-brain"></i>
                            <span class="flex-grow-1">AI Prediction</span>
                            <i class="fas fa-chevron-down ms-auto" style="font-size: 11px; width: auto; margin: 0;"></i>
                        </a>
                        <ul class="horizon-submenu <?php echo (in_array($page, ['prediction_dashboard', 'student_comparison', 'component_prediction'])) ? 'show' : '' ?>">
                            <li>
                                <a href="./?page=prediction_dashboard" class="horizon-submenu-link <?php echo ($page == 'prediction_dashboard') ? 'active' : '' ?>">
                                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                                </a>
                            </li>
                            <li>
                                <a href="./?page=component_prediction<?php echo isset($_GET['class_id']) ? '&class_id=' . intval($_GET['class_id']) : '' ?>" class="horizon-submenu-link <?php echo ($page == 'component_prediction') ? 'active' : '' ?>">
                                    <i class="fas fa-chart-line me-2"></i> Component Analysis
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (has_page_access('database_transfer', $role, $allowedPages)): ?>
                    <li class="horizon-nav-item <?php echo ($page == 'database_transfer') ? 'active' : '' ?>">
                        <a href="./?page=database_transfer" class="horizon-nav-link <?php echo ($page == 'database_transfer') ? 'active' : '' ?>">
                            <i class="fas fa-database"></i>
                            <span>Transfer Records</span>
                        </a>
                    </li>
                <?php endif; ?>

                <li class="horizon-nav-group-title">Administration</li>

                <li class="horizon-nav-item has-horizon-submenu <?php echo (in_array($page, ['users', 'manage_user', 'manage_account', 'admin/faculty', 'admin/departments', 'admin/monitoring', 'admin/device_logs', 'manage_images', 'landing_registrations', 'maintenance'])) ? 'active open' : '' ?>">
                    <a href="#" class="horizon-nav-link <?php echo (in_array($page, ['users', 'manage_user', 'manage_account', 'admin/faculty', 'admin/departments', 'admin/monitoring', 'admin/device_logs', 'manage_images', 'landing_registrations', 'maintenance'])) ? 'active' : '' ?>">
                        <i class="fas fa-users-cog"></i>
                        <span class="flex-grow-1">Management</span>
                        <i class="fas fa-chevron-down ms-auto" style="font-size: 11px; width: auto; margin: 0;"></i>
                    </a>
                    <ul class="horizon-submenu <?php echo (in_array($page, ['users', 'manage_user', 'manage_account', 'admin/faculty', 'admin/departments', 'admin/monitoring', 'admin/device_logs', 'manage_images', 'landing_registrations', 'maintenance'])) ? 'show' : '' ?>">
                        <?php if (has_page_access('admin/faculty', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=admin/faculty" class="horizon-submenu-link <?php echo ($page == 'admin/faculty') ? 'active' : '' ?>">
                                    <i class="fas fa-chalkboard me-2"></i> Faculty Accounts
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (has_page_access('admin/departments', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=admin/departments" class="horizon-submenu-link <?php echo ($page == 'admin/departments') ? 'active' : '' ?>">
                                    <i class="fas fa-sitemap me-2"></i> Departments &amp; Courses
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (has_page_access('admin/monitoring', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=admin/monitoring" class="horizon-submenu-link <?php echo ($page == 'admin/monitoring') ? 'active' : '' ?>">
                                    <i class="fas fa-chart-line me-2"></i> Faculty Monitoring
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($role !== 'faculty' || has_page_access('admin/device_logs', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=admin/device_logs" class="horizon-submenu-link <?php echo ($page == 'admin/device_logs') ? 'active' : '' ?>">
                                    <i class="fas fa-fingerprint me-2"></i> Device Logs
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (has_page_access('manage_images', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=manage_images" class="horizon-submenu-link <?php echo ($page == 'manage_images') ? 'active' : '' ?>">
                                    <i class="fas fa-images me-2"></i> Image Manager
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (has_page_access('landing_registrations', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=landing_registrations" class="horizon-submenu-link <?php echo ($page == 'landing_registrations') ? 'active' : '' ?>">
                                    <i class="fas fa-inbox me-2"></i> Landing Registrations
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (has_page_access('maintenance', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=maintenance" class="horizon-submenu-link <?php echo ($page == 'maintenance') ? 'active' : '' ?>">
                                    <i class="fas fa-tools me-2"></i> System Maintenance
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if (has_page_access('manage_account', $role, $allowedPages)): ?>
                            <li>
                                <a href="./?page=manage_account" class="horizon-submenu-link <?php echo ($page == 'manage_account') ? 'active' : '' ?>">
                                    <i class="fas fa-user-circle me-2"></i> My Profile
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>


                <li class="horizon-nav-item mt-3">
                    <a href="./Actions.php?a=logout" class="horizon-nav-link text-danger">
                        <i class="fas fa-sign-out-alt text-danger"></i>
                        <span>Log Out</span>
                    </a>
                </li>
            </ul>


        </aside>

        <!-- HORIZON MAIN CONTENT AREA -->
        <div id="horizon-main">
            <!-- FLOATING GLASS TOPBAR (Iconic Horizon Floating Navbar) -->
            <nav id="horizon-navbar">
                <!-- Breadcrumbs & Dynamic Page Title -->
                <div class="horizon-breadcrumb-area">
                    <div class="horizon-breadcrumb">
                        <a href="./">Pages</a>
                        <span>/</span>
                        <span><?php echo htmlspecialchars($currentPageTitle); ?></span>
                    </div>
                    <h1 class="horizon-page-title"><?php echo htmlspecialchars($currentPageTitle); ?></h1>
                </div>

                <!-- Floating Toolbar Pill -->
                <div class="horizon-nav-pill-toolbar">
                    <!-- Mobile Sidebar Hamburger Toggle (mobile/tablet only) -->
                    <button type="button" id="horizon-mobile-toggle" class="horizon-mobile-toggle" aria-label="Toggle Navigation">
                        <i class="fas fa-bars"></i>
                    </button>

                    <!-- Dark Mode Toggle -->
                    <button type="button" id="horizon-theme-toggle" class="btn btn-sm horizon-nav-icon-btn horizon-theme-toggle" aria-label="Enable dark mode" title="Enable dark mode">
                        <i class="fas fa-moon" aria-hidden="true"></i>
                    </button>

                    <!-- Search Input Box -->
                    <div class="horizon-search-input-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" class="horizon-search-input" id="horizonGlobalSearch" placeholder="Search...">
                    </div>

                    <!-- Academic Term Filter Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-sm horizon-nav-icon-btn text-brand" type="button" id="academicFilter" data-bs-toggle="dropdown" aria-expanded="false" title="Filter Academic Term">
                            <i class="fas fa-filter text-primary"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="academicFilter" style="max-height: 320px; overflow-y: auto; width: 300px;">
                            <li>
                                <h6 class="dropdown-header">Academic Term & Semester</h6>
                            </li>
                            <div id="academicTermsLoading" class="text-center p-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                <span class="ms-2 text-muted">Loading terms...</span>
                            </div>
                            <div id="academicTermsList"></div>
                            <div id="noTermsFound" class="d-none p-3 text-muted">No academic terms found.</div>
                        </ul>
                    </div>



                    <!-- System Clock & Timezone Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-sm horizon-nav-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="System Time">
                            <i class="fas fa-clock"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-3 text-center" style="width: 240px;">
                            <div class="fw-bold text-primary mb-1">Current Time</div>
                            <div id="timezone-display" class="fw-bold fs-6"></div>
                            <small class="text-muted d-block mt-1">Philippine Standard Time</small>
                        </div>
                    </div>


                    <!-- User Profile Dropdown -->
                    <div class="dropdown">
                        <button class="horizon-avatar-btn" type="button" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo !empty($user['image']) ? htmlspecialchars($user['image']) : './image/68ddeb9e9db18_pexels-photo-1097456.jpeg' ?>"
                                alt="Avatar"
                                onerror="this.src='./image/68ddeb9e9db18_pexels-photo-1097456.jpeg'">
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="userMenuDropdown" style="width: 230px;">
                            <div class="p-2 border-bottom mb-2">
                                <div class="fw-bold text-dark text-truncate"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?> (<?php echo htmlspecialchars($role); ?>)</small>
                            </div>
                            <a class="dropdown-item" href="./?page=manage_account">
                                <i class="fas fa-user-cog text-primary"></i> Account Settings
                            </a>
                            <?php if (has_page_access('admin/faculty', $role, $allowedPages)): ?>
                                <a class="dropdown-item" href="./?page=admin/faculty">
                                    <i class="fas fa-users text-info"></i> Manage Faculty
                                </a>
                            <?php endif; ?>
                            <?php if (has_page_access('maintenance', $role, $allowedPages)): ?>
                                <a class="dropdown-item" href="./?page=maintenance">
                                    <i class="fas fa-tools text-warning"></i> System Maintenance
                                </a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger" href="./Actions.php?a=logout">
                                <i class="fas fa-sign-out-alt text-danger"></i> Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Offline banner: shown automatically when the device loses internet -->
            <div id="horizonOfflineBar" style="display:none;align-items:center;gap:10px;margin:0 0 12px;padding:10px 16px;background:#FEF3C7;color:#92400E;font-size:12.5px;font-weight:700;border-radius:12px;">
                <i class="fas fa-wifi-slash"></i><span style="flex:1">You're offline — you can keep working. Changes queue on this device and sync later.</span>
                <a href="./offline.html" style="color:#92400E;font-weight:800">Offline workspace</a>
            </div>

            <!-- Main Page Viewport -->
            <main class="horizon-page-body">
                <!-- Toast Notification Container Stack -->
                <div id="horizon-toast-stack" class="horizon-toast-stack"></div>

                <!-- Flash Data Alert Banner -->
                <?php if (isset($_SESSION['flashdata'])): ?>
                    <?php
                    $flashType = $_SESSION['flashdata']['type'] ?? 'info';
                    $flashIcons = [
                        'success' => 'fa-check-circle',
                        'danger' => 'fa-exclamation-circle',
                        'warning' => 'fa-exclamation-triangle',
                        'info' => 'fa-info-circle',
                    ];
                    $flashIcon = $flashIcons[$flashType] ?? 'fa-info-circle';
                    ?>
                    <div class="horizon-flash-banner horizon-flash-<?php echo htmlspecialchars($flashType) ?> dynamic_alert" role="alert">
                        <i class="fas <?php echo $flashIcon ?> fs-5"></i>
                        <div class="flex-grow-1 fw-bold"><?php echo $_SESSION['flashdata']['msg'] ?></div>
                        <button type="button" class="btn-close" onclick="this.closest('.dynamic_alert').remove()" aria-label="Close"></button>
                    </div>
                    <script>
                        setTimeout(function() {
                            $('.dynamic_alert').fadeOut('fast', function() {
                                $(this).remove();
                            });
                        }, 4000);
                    </script>
                    <?php unset($_SESSION['flashdata']); ?>
                <?php endif; ?>

                <!-- Dynamic Component View Inclusion -->
                <div class="container-fluid px-0">
                    <?php
                    // Pages included here run inside the main layout (jQuery + Bootstrap
                    // already loaded above), so they must skip their own copies.
                    if (!defined('HORIZON_LAYOUT_LOADED')) define('HORIZON_LAYOUT_LOADED', true);
                    $page_file = str_ends_with($page, '.php') ? $page : $page . '.php';
                    if (file_exists($page_file)) {
                        include $page_file;
                    } else {
                        echo '<div class="horizon-card p-5 text-center">';
                        echo '<i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>';
                        echo '<h4>Page Not Found</h4>';
                        echo '<p class="text-muted">The requested page does not exist or has been moved.</p>';
                        echo '<a href="./" class="btn btn-primary">Return to Dashboard</a>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Enhanced Universal Modal -->
    <div class="modal fade modern-modal" id="uni_modal" role="dialog" data-bs-backdrop="static" data-bs-keyboard="true">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="submit" onclick="$('#uni_modal form').submit()">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Secondary Modal -->
    <div class="modal fade modern-modal" id="uni_modal_secondary" role="dialog" data-bs-backdrop="static" data-bs-keyboard="true">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="submit" onclick="$('#uni_modal_secondary form').submit()">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Confirmation Modal -->
    <div class="modal fade modern-modal" id="confirm_modal" role="dialog">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> Confirmation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="delete_content" class="text-secondary"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" id="confirm" onclick="">
                        <i class="fas fa-check me-1"></i> Confirm & Proceed
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Academic Term Database Switcher & Clock Scripts -->
    <script>
        function updateTimezone() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
            const el = document.getElementById('timezone-display');
            if (el) {
                el.textContent = timeString;
            }
            setTimeout(updateTimezone, 1000);
        }

        function switchDatabase(dbPath) {
            const formData = new FormData();
            formData.append('db_path', dbPath);

            return fetch('switch_database.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                            throw new Error('Network error: ' + response.status);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Failed to switch database');
                    }
                    return data;
                });
        }

        function handleTermClick(e) {
            e.preventDefault();
            const year = this.dataset.year;
            const semester = this.dataset.semester;
            const dbPath = this.dataset.dbPath;

            window.showAppToast('info', 'Switching academic term database...', 'Please wait');

            switchDatabase(dbPath)
                .then(data => {
                    window.showAppToast('success', 'Database switched successfully!', 'Reloading page...');
                    setTimeout(() => window.location.reload(), 800);
                })
                .catch(err => {
                    window.showAppToast('danger', 'Failed to switch database', err.message);
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateTimezone();

            const filterBtn = document.getElementById('academicFilter');
            if (filterBtn) {
                filterBtn.addEventListener('shown.bs.dropdown', function() {
                    const container = document.getElementById('academicTermsList');
                    const loading = document.getElementById('academicTermsLoading');
                    const noTerms = document.getElementById('noTermsFound');

                    loading.classList.remove('d-none');
                    noTerms.classList.add('d-none');
                    container.innerHTML = '';

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        const defaultItem = document.createElement('li');
                        defaultItem.innerHTML = `
                    <a class='dropdown-item academic-filter' href='#' data-year='' data-semester='' data-db-path=''>
                        <i class="fas fa-home text-primary me-2"></i> Default Database
                    </a>`;
                        container.appendChild(defaultItem);

                        const divider = document.createElement('li');
                        divider.innerHTML = '<hr class="dropdown-divider">';
                        container.appendChild(divider);
                    <?php endif; ?>

                    fetch('get_all_academic_terms.php')
                        .then(response => response.json())
                        .then(data => {
                            loading.classList.add('d-none');
                            if (data.success && Object.keys(data.terms).length > 0) {
                                Object.entries(data.terms).forEach(([year, terms]) => {
                                    const yearHeader = document.createElement('li');
                                    yearHeader.innerHTML = `<h6 class='dropdown-header text-primary'>SY ${year}</h6>`;
                                    container.appendChild(yearHeader);

                                    terms.forEach(term => {
                                        const item = document.createElement('li');
                                        const dbName = term.source_db ? term.source_db.split('/').pop() : 'Default DB';
                                        item.innerHTML = `
                                        <a class='dropdown-item academic-filter' href='#'
                                           data-year='${term.year}'
                                           data-semester='${term.semester}'
                                           data-db-path='${term.source_db || ''}'>
                                            <i class="fas fa-calendar-alt text-muted me-2"></i> ${term.semester}
                                            <small class='text-muted ms-auto'>${dbName}</small>

                                    </a>`;
                                        container.appendChild(item);
                                    });

                                    const divider = document.createElement('li');
                                    divider.innerHTML = '<hr class="dropdown-divider">';
                                    container.appendChild(divider);
                                });

                                container.querySelectorAll('.academic-filter').forEach(item => {
                                    item.addEventListener('click', handleTermClick);
                                });
                            } else {
                                noTerms.classList.remove('d-none');
                            }
                        })
                        .catch(err => {
                            loading.classList.add('d-none');
                            noTerms.classList.remove('d-none');
                        });
                });
            }
        });

        // Enter key submit on modal inputs
        $(document).on('keypress', '#uni_modal input, #uni_modal select', function(e) {
            if (e.which === 13 && !$(this).is('textarea')) {
                e.preventDefault();
                $('#uni_modal #submit').click();
            }
        });

        // Sidebar pending notification (Classes navigation) - only for users with matching sections
        // Per-user: badge shows ONLY pending whose course+section matches a class the logged-in user teaches and who is not yet enrolled in that grade+section
        (function() {
            const role = <?php echo json_encode($role); ?>;
            const pendingCacheKey = 'horizonSidebarPending_' + <?php echo json_encode($_SESSION['admin_id'] ?? 0); ?>;
            if (role !== 'admin' && role !== 'faculty') return;
            const $badge = $('#sidebarPendingBadge');
            const $popup = $('#sidebarPendingPopup');
            const $body = $('#sidebarPendingBody');
            const $footer = $('#sidebarPendingFooter');

            // Restore from localStorage so badge stays shown even when navigated to another page (before PHP/JS count)
            // Per-user key prevents showing another user's pending when sharing same browser
            try {
                const cached = localStorage.getItem(pendingCacheKey);
                if (cached && parseInt(cached, 10) > 0) {
                    $badge.text(cached).show();
                }
            } catch (e) {}

            function syncSidebarFromSectionBadges() {
                const $btns = $('.pending-section-btn');
                if (!$btns.length) return false;
                let total = 0;
                $btns.each(function() {
                    const t = $(this).find('.pending-count-badge');
                    if (t.is(':visible')) {
                        const v = parseInt(t.text() || '0', 10);
                        if (!isNaN(v)) total += v;
                    }
                });
                if (total > 0) {
                    $badge.text(total).show();
                    try {
                        localStorage.setItem(pendingCacheKey, total);
                    } catch (e) {}
                    return true;
                }
                return false;
            }

            function fetchPendingForSidebar() {
                if (syncSidebarFromSectionBadges()) return;
                $.ajax({
                    url: './get_all_pending.php?fast=1',
                    method: 'GET',
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.status === 'success' && resp.total > 0) {
                            $badge.text(resp.total).show();
                            try {
                                localStorage.setItem(pendingCacheKey, resp.total);
                            } catch (e) {}
                        } else if (resp.status === 'success') {
                            // Server is the source of truth and it is scoped to this
                            // specific user (only pendings matching their own classes).
                            // Zero pending clears the badge plus any stale
                            // server-rendered / localStorage count with it.
                            $badge.hide();
                            $popup.hide();
                            try {
                                localStorage.setItem(pendingCacheKey, '0');
                            } catch (e) {}
                        }
                        // On transport/validation errors the state is unknown, so the
                        // current badge is left untouched.
                    },
                    error: function() {}
                });
            }
            // Instant on load (no delay) + live (perf: 30s poll, no 1s timer)
            syncSidebarFromSectionBadges();
            fetchPendingForSidebar();
            setInterval(fetchPendingForSidebar, 30000);
            const obs = new MutationObserver(() => syncSidebarFromSectionBadges());
            const target = document.getElementById('class-table');
            if (target) obs.observe(target, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['style', 'class']
            });

            // Show popup only when clicking the pending badge (link itself remains navigable)
            $(document).on('click', '#sidebarPendingBadge', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if ($popup.is(':visible')) {
                    $popup.hide();
                    return;
                }
                $popup.show();
                $body.html('<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm text-primary"></div><div class="small mt-2">Loading pending...</div></div>');
                $footer.hide();
                $.ajax({
                    url: './get_all_pending.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.status !== 'success' || !resp.grouped || !Object.keys(resp.grouped).length) {
                            $body.html('<div class="p-3 text-center text-muted small"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><div>All caught up — no pending students</div></div>');
                            return;
                        }
                        let html = '';
                        for (const [section, students] of Object.entries(resp.grouped)) {
                            html += '<div class="mb-3"><div class="d-flex justify-content-between align-items-center mb-1"><h6 class="small fw-bold mb-0"><i class="fas fa-layer-group me-1 text-primary"></i>' + $('<div>').text(section).html() + ' <span class="badge bg-warning text-dark">' + students.length + '</span></h6><button type="button" class="btn btn-sm btn-success assign-section-btn" data-section="' + $('<div>').text(section).html() + '"><i class="fas fa-user-plus me-1"></i>Assign All</button></div><div class="list-group list-group-flush border rounded" style="max-height:120px; overflow-y:auto;">';
                            students.slice(0, 3).forEach(s => {
                                html += '<div class="list-group-item py-2 px-2 small"><div class="fw-bold text-truncate">' + $('<div>').text(s.name).html() + '</div><small class="text-muted">' + $('<div>').text(s.student_number).html() + '</small></div>';
                            });
                            if (students.length > 3) html += '<div class="list-group-item py-1 px-2 small text-muted text-center">+ ' + (students.length - 3) + ' more...</div>';
                            html += '</div></div>';
                        }
                        html += '<div class="alert alert-info small mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>Go to <a href="./?page=class" class="alert-link">Classes</a> and click a <strong>Section</strong> badge to assign its pending students.</div>';
                        $body.html(html);
                    },
                    error: function() {
                        $body.html('<div class="p-3 text-center text-danger small">Connection error</div>');
                    }
                });
            });

            $('#closeSidebarPending').on('click', function() {
                $popup.hide();
            });
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#sidebarPendingPopup').length && !$(e.target).closest('#sidebarPendingBadge').length) {
                    $popup.hide();
                }
            });

            // Handle Assign All from sidebar popup (needs class selection - redirect to class page)
            $(document).on('click', '.assign-section-btn', function() {
                const section = $(this).data('section');
                // Find a class with this section to assign to (first match)
                $.ajax({
                    url: './get_pending_students.php',
                    method: 'GET',
                    data: {
                        section: section
                    },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.status === 'success' && resp.count > 0) {
                            if (confirm('Assign all ' + resp.count + ' pending student(s) from section ' + section + '?\nYou will be redirected to Classes to choose the target class.')) {
                                window.location.href = './?page=class';
                            }
                        }
                    }
                });
            });
        })();
    </script>

    <!-- Security Protection System -->
    <?php require_once('security_protection.php'); ?>
<script>
// One-time cleanup: the offline service worker (sw.js) was retired and deleted
// from the server, but browsers that installed it keep running the stale copy,
// which rejects every fetch (e.g. favicon.ico) with "Failed to convert value
// to 'Response'". Force-unregister it and drop its caches. Safe to delete this
// block once every device has loaded any page at least once.
(function(){
  if (!('serviceWorker' in navigator)) return;
  try {
    navigator.serviceWorker.getRegistrations().then(function(regs){
      regs.forEach(function(r){ try { r.unregister(); } catch(e){} });
    }).catch(function(){});
    if (window.caches && caches.keys) {
      caches.keys().then(function(keys){
        keys.forEach(function(k){
          if (/horizon/i.test(k)) { try { caches.delete(k); } catch(e){} }
        });
      }).catch(function(){});
    }
  } catch(e){}
})();
</script>
</body>

</html>