<?php
// Output buffering: guarantees the post-registration redirect (PRG) can send
// headers even if an include emits stray whitespace.
ob_start();

require_once("DBConnection.php");
require_once("Actions.php");

// Include barangay and zipcode data
require_once("Biliran_barangays.php");
require_once("Eastern_samar_barangays.php");
require_once("Leyte_barangays.php");
require_once("Northern_samar_barangays.php");
require_once("Samar_barangays.php");
require_once("Southern_leyte_barangays.php");
require_once("Zipcode.php");

// Initialize Actions class for database operations
$actions = new Actions();

// Landing page keeps its own database - student registrations are stored
// here instead of any faculty/admin user database. No faculty or class
// selection is involved.
require_once("landing_db.php");
$conn = landing_db();

// Additional Personal Information option lists (single source of truth for
// the main form, the View/Edit modal and the POST validation).
// Category => [value => label]
$CIVIL_STATUS_OPTIONS = [
    'Single'     => 'Single',
    'Married'    => 'Married',
    'Divorced'   => 'Divorced',
    'Widowed'    => 'Widow / Widower',
    'Separated'  => 'Separated',
    'Annulled'   => 'Annulled',
];

$FAMILY_STATUS_OPTIONS = [
    'Parent'         => 'Parent',
    'Household Head' => 'Household Head',
    'Dependent'      => 'Dependent',
    'Solo Parent'    => 'Solo Parent',
];

$SPECIAL_SECTOR_OPTIONS = [
    'PWD'               => 'PWD (Person With Disability)',
    'Indigenous Person' => 'Indigenous Person',
];

// Course dropdown is fed by the shared department catalog that the admin
// maintains under Administration > Departments & Courses. Grouped per
// department, de-duplicated, and only used if that catalog cannot be read.
require_once("departments.php");
$course_opts = all_course_options();
$course_groups = department_course_groups();
if (empty($course_opts)) {
    // Never leave the public form without a usable course list.
    $course_opts = ['BSIT', 'BSED', 'BEED', 'BSBA', 'BSA', 'BSCrim'];
    $course_groups = ['GENERAL' => ['label' => 'Course', 'courses' => $course_opts]];
}

// Render a <select> for one of the lists above.
// $optional = true adds a "None" entry (value '') for non-required fields.
function renderPersonalSelect($name, $id, $options, $selected, $optional, $placeholder, $required = false)
{
    $sel  = htmlspecialchars((string)$selected, ENT_QUOTES);
    $req  = $required ? ' required' : '';
    $ph   = htmlspecialchars($placeholder, ENT_QUOTES);
    echo "<select name=\"$name\" id=\"$id\" class=\"form-select\"$req>";
    if ($optional) {
        $noneSel = ($selected === '' || $selected === null) ? ' selected' : '';
        echo "<option value=\"\"$noneSel>None</option>";
    } else {
        $phSel = ($selected === '' || $selected === null) ? ' selected' : '';
        echo "<option value=\"\" disabled$phSel>$ph</option>";
    }
    foreach ($options as $value => $label) {
        $isSel = ((string)$value === (string)$selected) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . $isSel . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    echo "</select>";
}

// Function to safely close database connections
function closeConnection(&$connection)
{
    if ($connection && $connection instanceof SQLite3) {
        try {
            $connection->close();
        } catch (Exception $e) {
            error_log("Error closing database connection: " . $e->getMessage());
        }
        $connection = null;
    }
}

// Register cleanup function to run at script end
register_shutdown_function(function () use ($conn) {
    $connection = null;
    closeConnection($conn);
});

// Get device information
function getDeviceInfo()
{
    // Get more stable browser and device information
    $screen_resolution = $_POST['screen_resolution'] ?? 'unknown';
    $timezone = $_POST['timezone'] ?? 'unknown';

    // Use only stable characteristics that don't change frequently
    $device_info = [
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'http_accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        'timezone' => $timezone,
        'screen_resolution' => $screen_resolution,
        'device_name' => gethostbyaddr($_SERVER['REMOTE_ADDR'])
    ];

    // Create a more stable device ID using only consistent characteristics
    $device_id = md5(json_encode($device_info));

    return [
        'device_id' => $device_id,
        'device_name' => $device_info['device_name']
    ];
}

// Flash success message after the PRG redirect (set by the POST handler).
// Only consumed on GET so a POST back to ?registered=1 can't show stale text.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['registered']) && !empty($_SESSION['landing_success'])) {
    $success_msg = $_SESSION['landing_success'];
    unset($_SESSION['landing_success']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get device information
    $device_info = getDeviceInfo();

    // Process the student form data
    $student_number = $_POST['student_number'] ?? '';
    $lastname = trim($_POST['lastname'] ?? '');
    $firstname = trim($_POST['firstname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '');
    $name_extension = trim($_POST['name_extension'] ?? '');
    $name = $lastname . ', ' . $firstname;
    if (!empty($middlename) && strtolower($middlename) !== 'none') {
        $name .= ' ' . $middlename;
    }
    if (!empty($name_extension) && strtolower($name_extension) !== 'none') {
        $name .= ' ' . $name_extension;
    }
    $birthdate = $_POST['birthdate'] ?? '';
    $age = $_POST['age'] ?? '';
    $gender = $_POST['gender'] ?? '';
    // Additional personal information (dropdowns)
    $civil_status = trim($_POST['civil_status'] ?? '');
    $family_status = trim($_POST['family_status'] ?? '');
    $special_sector = trim($_POST['special_sector'] ?? '');
    $contact_number = $_POST['contact_number'] ?? '';
    $email = $_POST['email'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $municipality = $_POST['municipality'] ?? '';
    $province = $_POST['province'] ?? '';
    $zipcode = $_POST['zipcode'] ?? '';
    $course = trim($_POST['course'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $section = strtoupper(trim($_POST['section'] ?? ''));
    // Irregular enrollee: checkbox + JSON list of {year, section, code, name}
    // picked from the Irregular Subjects modal.
    $is_irregular = (isset($_POST['is_irregular']) && $_POST['is_irregular'] === '1') ? 1 : 0;
    $irregular_subjects_raw = trim($_POST['irregular_subjects_json'] ?? '');
    $irregular_subjects = [];
    $irregular_error = '';
    if ($is_irregular) {
        $decoded_irr = json_decode($irregular_subjects_raw, true);
        if (!is_array($decoded_irr) || empty($decoded_irr)) {
            $irregular_error = "You marked Irregular — please add at least one subject (year level, section and subject).";
        } else {
            foreach (array_slice($decoded_irr, 0, 20) as $irr_item) {
                if (!is_array($irr_item)) {
                    $irregular_error = "Invalid irregular subject entry. Please re-pick your subjects.";
                    $irregular_subjects = [];
                    break;
                }
                $irr_year = trim((string)($irr_item['year'] ?? ''));
                $irr_section = strtoupper(trim((string)($irr_item['section'] ?? '')));
                $irr_code = trim((string)($irr_item['code'] ?? ''));
                $irr_name = trim((string)($irr_item['name'] ?? ''));
                if (!preg_match('/^[1-6]$/', $irr_year) || !preg_match('/^[1-6][A-Z]+$/', $irr_section) || $irr_section[0] !== $irr_year || $irr_code === '' || $irr_name === '') {
                    $irregular_error = "Invalid irregular subject entry. Please re-pick your subjects.";
                    $irregular_subjects = [];
                    break;
                }
                $irregular_subjects[] = [
                    'year' => $irr_year,
                    'section' => $irr_section,
                    'code' => mb_substr($irr_code, 0, 50),
                    'name' => mb_substr($irr_name, 0, 150),
                ];
            }
            if ($irregular_error === '' && empty($irregular_subjects)) {
                $irregular_error = "You marked Irregular — please add at least one subject (year level, section and subject).";
            }
        }
    }

    // Additional personal information: civil status is required, the other two
    // are optional ("None"). Only values from the option lists are accepted.
    if ($civil_status !== '' && !array_key_exists($civil_status, $CIVIL_STATUS_OPTIONS)) {
        $civil_status = '';
    }
    if ($family_status !== '' && !array_key_exists($family_status, $FAMILY_STATUS_OPTIONS)) {
        $family_status = '';
    }
    if ($special_sector !== '' && !array_key_exists($special_sector, $SPECIAL_SECTOR_OPTIONS)) {
        $special_sector = '';
    }

    // Basic validation (no faculty/class selection - landing has its own database)
    // Section: must be year digit (1-6) + letters only (A-Z), no symbols (e.g. 1A, 2B)
    if (mb_strlen($section) === 1) {
        $error_msg = "Section must be at least 2 characters (e.g. 1A, 2B).";
    } elseif (!empty($section) && !preg_match('/^[1-6][A-Z]+$/', $section)) {
        $error_msg = "Section must start with the year number (1-6) followed by letters only A-Z (e.g. 1A, 2B). Symbols are not allowed.";
    } elseif (!empty($section) && !empty($year) && $section[0] !== (string)$year) {
        $error_msg = "Section must start with the selected Year Level (e.g. Year 1 → 1A).";
    } elseif ($civil_status === '') {
        $error_msg = "Please select your Civil / Marital Status.";
    } elseif ($irregular_error !== '') {
        $error_msg = $irregular_error;
    } elseif (!empty($student_number) && !empty($lastname) && !empty($firstname) && !empty($course) && !empty($year) && !empty($section) && mb_strlen($section) >= 2) {
        try {
            // Check for duplicate student number in the landing database
            $duplicate_check = $conn->query("SELECT COUNT(*) as count FROM `landing_registrations`
                                            WHERE `student_number` = '$student_number'");
            $duplicate_result = $duplicate_check->fetchArray();
            $check = isset($duplicate_result['count']) ? (int)$duplicate_result['count'] : 0;

            if ($check > 0) {
                $error_msg = "This student number is already registered.";
            } else {
                // Insert new student with device tracking (+ irregular flag/subjects)
                // Store full firstname in database, but use first word for duplicate checking
                $irr_json_esc = $conn->escapeString(json_encode($irregular_subjects));
                $civil_status_esc = $conn->escapeString($civil_status);
                $family_status_esc = $conn->escapeString($family_status);
                $special_sector_esc = $conn->escapeString($special_sector);
                $insert_qry = $conn->query("INSERT INTO `landing_registrations` (student_number, name, middlename, firstname, lastname, name_extension, course, year, section, birthdate, age, gender, civil_status, family_status, special_sector, contact_number, email, barangay, municipality, province, zipcode, device_id, device_name, is_irregular, irregular_subjects)
                                        VALUES ('$student_number', '$name', '$middlename', '$firstname', '$lastname', '$name_extension', '$course', '$year', '$section', '$birthdate', '$age', '$gender', '$civil_status_esc', '$family_status_esc', '$special_sector_esc', '$contact_number', '$email', '$barangay', '$municipality', '$province', '$zipcode', '{$device_info['device_id']}', '{$device_info['device_name']}', '{$is_irregular}', '{$irr_json_esc}' )");

                if ($insert_qry) {
                    // Store notification for students.php
                    $_SESSION['student_registered'] = [
                        'timestamp' => time(),
                        'student_number' => $student_number,
                        'name' => $name,
                        'class_id' => null
                    ];

                    // PRG: redirect so reloading the page never triggers the
                    // browser's "Confirm Form Resubmission" dialog, never
                    // re-inserts, and always renders a fresh empty form.
                    $_SESSION['landing_success'] = "Student registered successfully!";
                    header('Location: landingpage.php?registered=1', true, 303);
                    exit;
                } else {
                    $error_msg = "Error registering student. Please try again.";
                }
            }
        } catch (Exception $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all required fields (Student Number, Last Name, First Name, Course, Year and Section).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="uploads/Eastern_Samar_State_University_logo.ico" type="image/x-icon">
    <title>Student Registration | Grading System</title>
    <link rel="stylesheet" href="./css/bootstrap.min.css">
    <link rel="stylesheet" href="./Font-Awesome-master/css/all.min.css">
    <script src="./js/jquery-3.6.0.min.js"></script>
    <script src="./js/popper.min.js"></script>
    <script src="./js/bootstrap.min.js"></script>

    <style>
        /* Elegant Landing Page Styles - Matching Login Page Design */
        :root {
            --primary-dark-green: #1a4d2e;
            --secondary-dark-green: #2d5f3f;
            --accent-gold: #d4af37;
            --light-gold: #f4e5c2;
            --pure-white: #ffffff;
            --soft-black: #1a1a1a;
            --gray-white: #f8f9fa;
            --border-gray: #e0e0e0;
            --text-muted: #6c757d;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }

            100% {
                background-position: 1000px 0;
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        body {
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--soft-black) 0%, var(--primary-dark-green) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            overflow-x: hidden;
        }

        .landing-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 20%, rgba(212, 175, 55, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 70% 80%, rgba(26, 77, 46, 0.2) 0%, transparent 50%);
            pointer-events: none;
        }

        .registration-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-out;
            position: relative;
            z-index: 1;
        }

        /* Professional top bar */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 12px 20px;
            margin-bottom: 18px;
        }

        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-logo {
            width: 46px;
            height: 46px;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        }

        .topbar-name {
            color: var(--pure-white);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.95rem;
            line-height: 1.2;
        }

        .topbar-sub {
            color: var(--light-gold);
            font-size: 0.78rem;
            letter-spacing: 0.4px;
        }

        .topbar-signin {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--pure-white);
            border: 1px solid rgba(212, 175, 55, 0.6);
            background: rgba(212, 175, 55, 0.12);
            border-radius: 10px;
            padding: 9px 18px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .topbar-signin:hover {
            background: var(--accent-gold);
            color: var(--soft-black);
        }

        /* Title band + step pills */
        .title-band {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .title-band h1 {
            color: var(--pure-white);
            font-size: 1.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0;
            text-shadow: 0 3px 6px rgba(0, 0, 0, 0.4);
            line-height: 1.2;
        }

        .title-band p {
            color: var(--light-gold);
            font-size: 0.85rem;
            margin: 4px 0 0 0;
            letter-spacing: 0.4px;
        }

        .steps-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: var(--pure-white);
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
            padding: 6px 13px 6px 7px;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .step-pill:hover {
            border-color: var(--accent-gold);
            color: var(--light-gold);
        }

        .step-pill .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--accent-gold);
            color: var(--soft-black);
            font-size: 0.7rem;
            font-weight: 700;
        }

        .sec-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: var(--primary-dark-green);
            color: var(--pure-white);
            font-size: 0.75rem;
            font-weight: 700;
            margin-right: 8px;
            vertical-align: middle;
        }

        .registration-card {
            background: var(--pure-white);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .registration-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.4);
        }

        .card-header-custom {
            background: linear-gradient(135deg, var(--primary-dark-green) 0%, var(--secondary-dark-green) 100%);
            padding: 25px;
            text-align: center;
            position: relative;
            border-radius: 20px 20px 0 0;
        }

        .card-header-custom::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold), var(--light-gold), var(--accent-gold));
            background-size: 200% 100%;
            animation: shimmer 3s infinite;
        }

        .card-title-custom {
            color: var(--pure-white);
            font-size: 1.3rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .card-dots {
            display: none;
        }

        .card-body {
            padding: 30px;
        }

        .form-section-title {
            color: var(--primary-dark-green);
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin: 2rem 0 1.5rem 0;
            padding-bottom: 0.8rem;
            border-bottom: 2px solid var(--accent-gold);
            line-height: 1.3;
        }

        .form-group {
            position: relative;
            margin-bottom: 25px;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid var(--border-gray);
            border-radius: 10px;
            font-size: 1rem;
            background: var(--gray-white);
            transition: all 0.3s ease;
            outline: none;
            box-sizing: border-box;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-dark-green);
            background: var(--pure-white);
            box-shadow: 0 0 0 3px rgba(26, 77, 46, 0.1);
        }

        .form-control::placeholder,
        .form-select::placeholder {
            color: transparent;
        }

        .form-control:focus::placeholder,
        .form-select:focus::placeholder {
            color: #999;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-dark-green);
            font-size: 18px;
            pointer-events: none;
            transition: color 0.3s ease;
        }

        .form-group:focus-within .input-icon {
            color: var(--accent-gold);
        }

        .floating-label {
            position: absolute;
            left: 45px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-size: 1rem;
            pointer-events: none;
            transition: all 0.3s ease;
            background: var(--gray-white);
            padding: 0 5px;
        }

        .form-control:focus~.floating-label,
        .form-control:not(:placeholder-shown)~.floating-label,
        .form-select:focus~.floating-label,
        .form-select:valid~.floating-label {
            top: -10px;
            left: 10px;
            font-size: 0.85rem;
            color: var(--primary-dark-green);
            background: var(--pure-white);
            font-weight: 500;
        }

        .submit-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary-dark-green) 0%, var(--secondary-dark-green) 100%);
            border: none;
            color: var(--pure-white);
            font-family: inherit;
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-top: 1.5rem;
            box-shadow: 0 4px 12px rgba(26, 77, 46, 0.3);
            line-height: 1.3;
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(26, 77, 46, 0.4);
        }

        .submit-btn:hover::before {
            left: 100%;
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .alert {
            border-radius: 10px;
            border: none;
            font-family: inherit;
            padding: 18px 22px;
            margin-bottom: 25px;
            font-size: 1rem;
            font-weight: 500;
            line-height: 1.4;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .alert-success {
            background: linear-gradient(135deg, #51cf66, #40c057);
            color: white;
        }

        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }

        .alert-warning {
            background: linear-gradient(135deg, #ffd93d, #ffb84d);
            color: var(--soft-black);
        }

        .alert-info {
            background: linear-gradient(135deg, #74c0fc, #4dabf7);
            color: white;
        }

        /* Form validation styles */
        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
            background-color: rgba(220, 53, 69, 0.05) !important;
        }

        .form-control.is-invalid:focus,
        .form-select.is-invalid:focus {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
        }

        .login-link {
            text-align: center;
            margin-top: 2.5rem;
            padding-top: 2.5rem;
            border-top: 2px solid var(--border-gray);
        }

        .login-link a {
            color: var(--primary-dark-green);
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            line-height: 1.3;
        }

        .login-link a:hover {
            color: var(--accent-gold);
        }

        /* Remove particles */
        #particles-js {
            display: none;
        }

        /* Tooltip styles */
        .caps-tooltip {
            position: absolute;
            top: -35px;
            left: 50%;
            transform: translateX(-50%);
            background-color: #dc3545;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .caps-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: #dc3545;
        }

        .caps-tooltip.show,
        .format-tooltip.show {
            opacity: 1;
        }

        .format-tooltip {
            position: absolute;
            top: -50px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--accent-gold);
            color: var(--soft-black);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .format-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: var(--accent-gold);
        }

        .input-group {
            position: relative;
        }

        .text-muted {
            color: var(--text-muted) !important;
        }

        /* Modal styles */
        .modal-content {
            background: var(--pure-white);
            border: none;
            border-radius: 20px;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-dark-green) 0%, var(--secondary-dark-green) 100%);
            border-bottom: none;
            border-radius: 20px 20px 0 0;
            color: var(--pure-white);
        }

        .modal-title {
            color: var(--pure-white) !important;
        }

        .modal-body {
            font-size: 11px !important;
        }

        .modal-body .form-label,
        .modal-body .form-control,
        .modal-body .form-select,
        .modal-body .btn,
        .modal-body p,
        .modal-body h5,
        .modal-body h6,
        .modal-body small,
        .modal-body .table,
        .modal-body .alert {
            font-size: 11px !important;
        }

        .btn-close {
            filter: brightness(0) invert(1);
        }

        /* Modal backdrop blur effect */
        .modal-backdrop {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            backdrop-filter: blur(8px) !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
            z-index: 1040 !important;
        }

        .modal.show .modal-backdrop {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            backdrop-filter: blur(8px) !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
            z-index: 1040 !important;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .topbar {
                padding: 10px 14px;
            }

            .title-band h1 {
                font-size: 1.35rem;
            }

            .registration-container {
                margin: 0;
            }

            .registration-card {
                border-radius: 15px;
            }

            .card-header-custom {
                padding: 20px;
            }

            .card-body {
                padding: 20px;
            }

            .form-section-title {
                font-size: 1rem;
                margin: 1.5rem 0 1rem 0;
            }

            .form-control,
            .form-select {
                padding: 12px 12px 12px 40px;
                font-size: 0.9rem;
            }

            .floating-label {
                left: 40px;
                font-size: 0.9rem;
            }

            .input-icon {
                left: 12px;
                font-size: 16px;
            }

            .submit-btn {
                font-size: 1rem;
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .title-band h1 {
                font-size: 1.2rem;
            }

            .topbar-signin {
                width: 100%;
                justify-content: center;
            }

            .card-body {
                padding: 15px;
            }

            .form-section-title {
                font-size: 0.9rem;
            }

            .submit-btn {
                font-size: 0.9rem;
                padding: 12px;
            }
        }
    </style>
</head>

<body>
    <!-- Top Bar -->
    <div class="registration-container">
        <div class="topbar">
            <div class="topbar-brand">
                <img src="uploads/Eastern_Samar_State_University_logo.ico" alt="University Logo" class="topbar-logo">
                <div>
                    <div class="topbar-name">Eastern Samar State University</div>
                    <div class="topbar-sub">College of Computer Studies &bull; Can-avid Campus</div>
                </div>
            </div>
            <a href="students/login.php" class="topbar-signin">
                <i class="fas fa-graduation-cap"></i> Student Login
            </a>
        </div>

        <!-- Title + Steps -->
        <div class="title-band">
            <div>
                <h1>Student Registration</h1>
                <p>Complete all four sections below to submit your enrollment.</p>
            </div>
            <div class="steps-bar">
                <a class="step-pill" href="#sec-identity"><span class="num">01</span>Identity</a>
                <a class="step-pill" href="#sec-academic"><span class="num">02</span>Academic</a>
                <a class="step-pill" href="#sec-personal"><span class="num">03</span>Personal</a>
                <a class="step-pill" href="#sec-address"><span class="num">04</span>Address</a>
            </div>
        </div>
    </div>

    <!-- Registration Form -->
    <div class="registration-container">
        <div class="registration-card">
            <div class="card-header-custom">
                <div class="card-title-custom">
                    <i class="fas fa-user-plus"></i>
                    Enrollment Form
                </div>
            </div>
            <div class="card-body">

                <?php if (isset($warning_msg)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $warning_msg; ?>
                    </div>
                <?php endif; ?>

                <!-- Security Announcement -->
                <!--      <div class="alert alert-info" style="background: rgba(13, 110, 253, 0.1); border: 1px solid rgba(13, 110, 253, 0.3); color: #e5e5e5;">
            <h5 style="color: #00f2ea; margin-top: 0;"><i class="fas fa-shield-alt me-2"></i>🔐 Important Notice</h5>
            <p style="margin-bottom: 10px; color:black;"><strong>To update your information Follow the intructions below</strong></p>
            <ol style="margin-bottom: 0; font-size: 0.9rem; color:black;">
                <li>Enter your student number</li>
                <li>Click "View/Edit Data" button</li>
                <li>Edit any necessary information</li>
                <li>Save changes to lock your account to your device</li>
            </ol>
            <p style="margin-bottom: 0; font-size: 0.85rem; color: #f59e0b;"><strong>⚠️ Please always follow instructions to avoid unnecessary mistakes!</strong></p>
        </div> -->

                <?php if (isset($success_msg)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $success_msg; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_msg)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>


                <form action="" method="POST" id="student-form">
                    <input type="hidden" name="timezone" id="timezone" value="">
                    <input type="hidden" name="screen_resolution" id="screenResolution" value="">

                    <!-- Basic Information -->
                    <h5 class="form-section-title" id="sec-identity"><span class="sec-num">01</span>Basic Information</h5>
                    <div class="row g-3">
                        <div class="col-12 col-md">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-id-card"></i></span>
                                <input type="text" name="student_number" id="student_number" class="form-control"
                                    value="<?php echo isset($_POST['student_number']) ? $_POST['student_number'] : '' ?>"
                                    maxlength="8" pattern="^\d{2}-\d{5}$" inputmode="numeric" autocomplete="on" required
                                    placeholder="" oninput="autoFormatStudentNumber(this); checkStudentRegistration(this.value)">
                                <label for="student_number" class="floating-label">Student Number *</label>
                            </div>

                            <small class="text-muted" style="color: var(--text-muted) !important; font-size: 0.8rem; margin-left: 10px; display: block;">Format: XX-XXXXX</small>
                            <div id="student-status" class="mt-2"></div>
                            <div id="student-actions" class="mt-2" style="display: none;">
                                <button type="button" class="submit-btn" style="width: auto; padding: 10px 20px; font-size: 0.9rem;" onclick="viewStudentData()">
                                    <i class="fas fa-eye me-1"></i>View/Edit Data
                                </button>
                            </div>
                        </div>
                        <div class="col-12 col-md">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" name="lastname" id="lastname" class="form-control"
                                    value="<?php echo isset($_POST['lastname']) ? htmlspecialchars($_POST['lastname']) : '' ?>"
                                    oninput="formatNamePart(this)"
                                    placeholder="" required>
                                <label for="lastname" class="floating-label">Last Name *</label>
                                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); border: none; background: none; color: var(--primary-dark-green);">
                                    <i>Ñ</i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('ñ'); return false;">ñ</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('Ñ'); return false;">Ñ</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-12 col-md">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" name="firstname" id="firstname" class="form-control"
                                    value="<?php echo isset($_POST['firstname']) ? htmlspecialchars($_POST['firstname']) : '' ?>"
                                    oninput="formatNamePart(this)"
                                    placeholder="" required>
                                <label for="firstname" class="floating-label">First Name *</label>
                                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); border: none; background: none; color: var(--primary-dark-green);">
                                    <i>Ñ</i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('ñ'); return false;">ñ</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('Ñ'); return false;">Ñ</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-12 col-md">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                                <input type="text" name="middlename" id="middlename" class="form-control"
                                    value="<?php echo isset($_POST['middlename']) ? htmlspecialchars($_POST['middlename']) : '' ?>"
                                    oninput="formatNamePart(this)"
                                    placeholder="" data-bs-toggle="tooltip" data-bs-placement="top" title="Leave blank if NONE">
                                <label for="middlename" class="floating-label">Middle Name *</label>
                                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); border: none; background: none; color: var(--primary-dark-green);">
                                    <i>Ñ</i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('ñ'); return false;">ñ</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('Ñ'); return false;">Ñ</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-12 col-md">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-user-plus"></i></span>
                                <select name="name_extension" id="name_extension" class="form-select">
                                    <?php
                                    $extensions = ['' => 'None', 'Jr.' => 'Jr.', 'Sr.' => 'Sr.', 'II' => 'II', 'III' => 'III', 'IV' => 'IV', 'V' => 'V'];
                                    $selected_extension = $_POST['name_extension'] ?? '';
                                    foreach ($extensions as $value => $label) {
                                        $selected = ($selected_extension === $value) ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($value) . "\" {$selected}>" . htmlspecialchars($label) . "</option>";
                                    }
                                    ?>
                                </select>
                                <label for="name_extension" class="floating-label">Extension</label>
                            </div>
                            <small class="text-muted" style="color: var(--text-muted) !important; font-size: 0.8rem; margin-left: 10px; display: block;">e.g. Jr., II, III</small>
                        </div>
                    </div>
                    <!-- Academic Information -->
                    <h5 class="form-section-title" id="sec-academic"><span class="sec-num">02</span>Academic Information</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-graduation-cap"></i></span>
                                <select name="course" id="course" class="form-select" required>
                                    <option value="" disabled <?php echo (!isset($_POST['course']) || $_POST['course'] === '') ? 'selected' : ''; ?>>Select Course</option>
                                    <?php $cur_course = $_POST['course'] ?? '';
                                    foreach ($course_groups as $group): if (empty($group['courses'])) continue; ?>
                                        <optgroup label="<?php echo htmlspecialchars($group['label'], ENT_QUOTES); ?>">
                                            <?php foreach ($group['courses'] as $opt): $sel = ($cur_course === $opt) ? 'selected' : ''; ?>
                                                <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($opt); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach;
                                    if ($cur_course !== '' && !in_array($cur_course, $course_opts, true)) {
                                        echo "<option value=\"" . htmlspecialchars($cur_course, ENT_QUOTES) . "\" selected>" . htmlspecialchars($cur_course) . " (existing)</option>";
                                    } ?>
                                </select>
                                <label for="course" class="floating-label">Course *</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-layer-group"></i></span>
                                <select name="year" id="year" class="form-select" required onchange="syncSectionFromYear('year','section')">
                                    <option value="" disabled <?php echo (!isset($_POST['year']) || $_POST['year'] === '') ? 'selected' : ''; ?>>Select Year Level</option>
                                    <?php for ($yl = 1; $yl <= 6; $yl++): $label = $yl == 1 ? '1st Year' : ($yl == 2 ? '2nd Year' : ($yl == 3 ? '3rd Year' : $yl . 'th Year'));
                                        $sel = (isset($_POST['year']) && (string)$_POST['year'] === (string)$yl) ? 'selected' : ''; ?>
                                        <option value="<?php echo $yl; ?>" <?php echo $sel; ?>><?php echo $label; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <label for="year" class="floating-label">Year Level *</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-users"></i></span>
                                <input type="text" name="section" id="section" class="form-control"
                                    value="<?php echo isset($_POST['section']) ? htmlspecialchars($_POST['section']) : '' ?>"
                                    placeholder="" required minlength="2" maxlength="4" pattern="^[1-6][A-Za-z]+$" title="Year digit followed by letters only A-Z (e.g. 1A, 2B) - no symbols" style="text-transform: uppercase;">
                                <label for="section" class="floating-label">Section *</label>
                            </div>
                            <small class="text-muted" style="color: var(--text-muted) !important; font-size: 0.8rem; margin-left: 10px; display: block;">Auto-prefixed with Year (e.g. 1 → 1A) — letters A-Z only, no symbols</small>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12">
                            <div class="form-check d-flex align-items-center gap-2" style="padding-left: 0;">
                                <input class="form-check-input" type="checkbox" name="is_irregular" id="is_irregular" value="1"
                                    style="width: 20px; height: 20px; margin: 0; accent-color: var(--primary-dark-green); cursor: pointer; flex-shrink: 0;"
                                    <?php echo isset($_POST['is_irregular']) ? 'checked' : ''; ?>
                                    onchange="toggleIrregular()">
                                <label class="form-check-label mb-0" for="is_irregular" style="font-weight: 700; color: var(--primary-dark-green); text-transform: uppercase; letter-spacing: 1px; font-size: 0.9rem; cursor: pointer;">
                                    Irregular
                                </label>
                                <small class="text-muted" style="color: var(--text-muted) !important; font-size: 0.8rem;">Check if you take subjects outside your regular year / section</small>
                            </div>
                            <input type="hidden" name="irregular_subjects_json" id="irregular_subjects_json"
                                value="<?php echo isset($_POST['irregular_subjects_json']) ? htmlspecialchars($_POST['irregular_subjects_json']) : ''; ?>">
                            <div id="irregular-summary" class="mt-2" style="display: none;"></div>
                        </div>
                    </div>
                    <!-- Personal Information -->
                    <h5 class="form-section-title" id="sec-personal"><span class="sec-num">03</span>Personal Information</h5>
                    <div class="row g-3">
                        <div class="col-md-2">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-calendar"></i></span>
                                <select name="birth_year" id="birth_year" class="form-select" onchange="updateBirthdate()">
                                    <option value="" selected disabled>Year</option>
                                    <?php
                                    $currentYear = date('Y');
                                    for ($year = $currentYear; $year >= 1900; $year--) {
                                        $selected = (isset($_POST['birth_year']) && $_POST['birth_year'] == $year) ? 'selected' : '';
                                        echo "<option value=\"{$year}\" {$selected}>{$year}</option>";
                                    }
                                    ?>
                                </select>
                                <label for="birth_year" class="floating-label">Birth Year</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <select name="birth_month" id="birth_month" class="form-select" onchange="updateBirthdate()">
                                    <option value="" selected disabled>Month</option>
                                    <?php
                                    $months = [
                                        '01' => 'Jan',
                                        '02' => 'Feb',
                                        '03' => 'Mar',
                                        '04' => 'Apr',
                                        '05' => 'May',
                                        '06' => 'Jun',
                                        '07' => 'Jul',
                                        '08' => 'Aug',
                                        '09' => 'Sep',
                                        '10' => 'Oct',
                                        '11' => 'Nov',
                                        '12' => 'Dec'
                                    ];
                                    foreach ($months as $num => $name) {
                                        $selected = (isset($_POST['birth_month']) && $_POST['birth_month'] == $num) ? 'selected' : '';
                                        echo "<option value=\"{$num}\" {$selected}>{$name}</option>";
                                    }
                                    ?>
                                </select>
                                <label for="birth_month" class="floating-label">Birth Month</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-calendar-day"></i></span>
                                <select name="birth_day" id="birth_day" class="form-select" onchange="updateBirthdate()">
                                    <option value="" selected disabled>Day</option>
                                    <?php
                                    for ($day = 1; $day <= 31; $day++) {
                                        $dayStr = str_pad($day, 2, '0', STR_PAD_LEFT);
                                        $selected = (isset($_POST['birth_day']) && $_POST['birth_day'] == $dayStr) ? 'selected' : '';
                                        echo "<option value=\"{$dayStr}\" {$selected}>{$day}</option>";
                                    }
                                    ?>
                                </select>
                                <label for="birth_day" class="floating-label">Birth Day</label>
                            </div>
                            <input type="hidden" name="birthdate" id="birthdate" value="<?php echo isset($_POST['birthdate']) ? $_POST['birthdate'] : '' ?>" onchange="calculateAge()">
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-hourglass-half"></i></span>
                                <input type="number" name="age" id="age" class="form-control"
                                    value="<?php echo isset($_POST['age']) ? $_POST['age'] : '' ?>" readonly>
                                <label for="age" class="floating-label">Age</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-venus-mars"></i></span>
                                <select name="gender" id="gender" class="form-select" required>
                                    <option value="" selected disabled></option>
                                    <option value="Male" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'Other') ? 'selected' : '' ?>>Other</option>
                                </select>
                                <label for="gender" class="floating-label">Gender *</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="tel" name="contact_number" id="contact_number" class="form-control"
                                    value="<?php echo isset($_POST['contact_number']) ? $_POST['contact_number'] : '' ?>"
                                    placeholder="" pattern="[0-9]{10,11}" required>
                                <label for="contact_number" class="floating-label">Contact *</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-ring"></i></span>
                                <?php renderPersonalSelect('civil_status', 'civil_status', $CIVIL_STATUS_OPTIONS, $_POST['civil_status'] ?? '', false, 'Select Civil Status', true); ?>
                                <label for="civil_status" class="floating-label">Civil / Marital Status *</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-house-user"></i></span>
                                <?php renderPersonalSelect('family_status', 'family_status', $FAMILY_STATUS_OPTIONS, $_POST['family_status'] ?? '', true, 'Select Family Status'); ?>
                                <label for="family_status" class="floating-label">Family / Household Status</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-star"></i></span>
                                <?php renderPersonalSelect('special_sector', 'special_sector', $SPECIAL_SECTOR_OPTIONS, $_POST['special_sector'] ?? '', true, 'Select Special Sector'); ?>
                                <label for="special_sector" class="floating-label">Special Sector / Target Group</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="email" class="form-control"
                                    value="<?php echo isset($_POST['email']) ? $_POST['email'] : '' ?>"
                                    placeholder="" required>
                                <label for="email" class="floating-label">Email Address *</label>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <h5 class="form-section-title" id="sec-address"><span class="sec-num">04</span>Address Information</h5>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-map-marked-alt"></i></span>
                                <select name="province" id="province" class="form-select" required>
                                    <option value="" selected disabled></option>
                                    <option value="Biliran" <?php echo (isset($_POST['province']) && $_POST['province'] === 'Biliran') ? 'selected' : ''; ?>>
                                        Biliran
                                    </option>
                                    <option value="Leyte" <?php echo (isset($_POST['province']) && $_POST['province'] === 'Leyte') ? 'selected' : ''; ?>>
                                        Leyte
                                    </option>
                                    <option value="Southern Leyte" <?php echo (isset($_POST['province']) && $_POST['province'] === 'Southern Leyte') ? 'selected' : ''; ?>>
                                        Southern Leyte
                                    </option>
                                    <option value="Samar" <?php echo (isset($_POST['province']) && $_POST['province'] === 'Samar') ? 'selected' : ''; ?>>
                                        Samar
                                    </option>
                                    <option value="Eastern Samar" <?php echo (isset($_POST['province']) && $_POST['province'] === 'Eastern Samar') ? 'selected' : ''; ?>>
                                        Eastern Samar
                                    </option>
                                    <option value="Northern Samar" <?php echo (isset($_POST['province']) && $_POST['province'] === 'Northern Samar') ? 'selected' : ''; ?>>
                                        Northern Samar
                                    </option>
                                </select>
                                <label for="province" class="floating-label">Province *</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-city"></i></span>
                                <select name="municipality" id="municipality" class="form-select" title="Select Province First" required>
                                    <option value="" selected disabled></option>
                                </select>
                                <label for="municipality" class="floating-label">Municipality *</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-home"></i></span>
                                <select name="barangay" id="barangay" class="form-select" title="Select Municipality First" required>
                                    <option value="" selected disabled></option>
                                </select>
                                <label for="barangay" class="floating-label">Barangay *</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-mail-bulk"></i></span>
                                <input type="text" name="zipcode" id="zipcode" class="form-control" title="Auto Filled" readonly
                                    value="<?php echo isset($_POST['zipcode']) ? $_POST['zipcode'] : '' ?>">
                                <label for="zipcode" class="floating-label">Zip Code</label>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <button type="submit" class="submit-btn" style="margin-top: 0;">
                                <i class="fas fa-user-plus me-2"></i>
                                Submit Registration
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View/Edit Student Data Modal -->
    <div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="studentModalLabel">
                        <i class="fas fa-user-edit me-2"></i>View/Edit Student Data
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="edit-student-form">
                        <!-- Basic Information -->
                        <h6 class="form-section-title mt-3">Basic Information</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-id-card"></i></span>
                                    <input type="text" name="student_number" id="edit-student-number" class="form-control" required>
                                    <label for="edit-student-number" class="floating-label">Student Number *</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-user"></i></span>
                                    <input type="text" name="lastname" id="edit-lastname" class="form-control" oninput="formatNamePart(this)" required>
                                    <label for="edit-lastname" class="floating-label">Last Name *</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-user"></i></span>
                                    <input type="text" name="firstname" id="edit-firstname" class="form-control" oninput="formatNamePart(this)" required>
                                    <label for="edit-firstname" class="floating-label">First Name *</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                                    <input type="text" name="middlename" id="edit-middlename" class="form-control" oninput="formatNamePart(this)">
                                    <label for="edit-middlename" class="floating-label">Middle Name</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-user-plus"></i></span>
                                    <select name="name_extension" id="edit-name-extension" class="form-select">
                                        <option value="">None</option>
                                        <option value="Jr.">Jr.</option>
                                        <option value="Sr.">Sr.</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                        <option value="IV">IV</option>
                                        <option value="V">V</option>
                                    </select>
                                    <label for="edit-name-extension" class="floating-label">Extension</label>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Information -->
                        <h6 class="form-section-title mt-3">Academic Information</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-graduation-cap"></i></span>
                                    <select name="course" id="edit-course" class="form-select" required>
                                        <option value="" disabled selected>Select Course</option>
                                        <?php foreach ($course_groups as $group): if (empty($group['courses'])) continue; ?>
                                            <optgroup label="<?php echo htmlspecialchars($group['label'], ENT_QUOTES); ?>">
                                                <?php foreach ($group['courses'] as $opt): ?>
                                                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="edit-course" class="floating-label">Course *</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-layer-group"></i></span>
                                    <select name="year" id="edit-year" class="form-select" required onchange="syncSectionFromYear('edit-year','edit-section')">
                                        <option value="" disabled selected>Select Year Level</option>
                                        <option value="1">1st Year</option>
                                        <option value="2">2nd Year</option>
                                        <option value="3">3rd Year</option>
                                        <option value="4">4th Year</option>
                                        <option value="5">5th Year</option>
                                        <option value="6">6th Year</option>
                                    </select>
                                    <label for="edit-year" class="floating-label">Year Level *</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-users"></i></span>
                                    <input type="text" name="section" id="edit-section" class="form-control" required minlength="2" maxlength="4" pattern="^[1-6][A-Za-z]+$" title="Year digit followed by letters only A-Z (e.g. 1A, 2B) - no symbols" style="text-transform: uppercase;">
                                    <label for="edit-section" class="floating-label">Section *</label>
                                </div>
                                <small class="text-muted" style="font-size:0.75rem;display:block;margin-top:4px;">Letters A-Z only, no symbols</small>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-12">
                                <div class="form-check d-flex align-items-center gap-2" style="padding-left:0;">
                                    <input class="form-check-input" type="checkbox" name="is_irregular" id="edit-is_irregular" value="1"
                                        style="width:20px;height:20px;margin:0;accent-color:var(--primary-dark-green);cursor:pointer;flex-shrink:0;"
                                        onchange="toggleEditIrregular()">
                                    <label class="form-check-label mb-0" for="edit-is_irregular" style="font-weight:700;color:var(--primary-dark-green);text-transform:uppercase;letter-spacing:1px;font-size:.9rem;cursor:pointer;">Irregular</label>
                                    <small class="text-muted" style="color:var(--text-muted)!important;font-size:.8rem;">Check if you take subjects outside your regular year / section</small>
                                </div>
                                <input type="hidden" name="irregular_subjects_json" id="edit-irregular_subjects_json" value="">
                                <div id="edit-irregular-summary" class="mt-2" style="display:none;"></div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <h6 class="form-section-title mt-3">Personal Information</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-calendar"></i></span>
                                    <input type="date" name="birthdate" id="edit-birthdate" class="form-control" onchange="calculateAge('edit-birthdate', 'edit-age')">
                                    <label for="edit-birthdate" class="floating-label">Birthdate</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-hourglass-half"></i></span>
                                    <input type="number" name="age" id="edit-age" class="form-control" readonly>
                                    <label for="edit-age" class="floating-label">Age</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-venus-mars"></i></span>
                                    <select name="gender" id="edit-gender" class="form-select">
                                        <option value="" selected disabled>Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    <label for="edit-gender" class="floating-label">Gender</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-phone"></i></span>
                                    <input type="tel" name="contact_number" id="edit-contact-number" class="form-control">
                                    <label for="edit-contact-number" class="floating-label">Contact Number</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                    <input type="email" name="email" id="edit-email" class="form-control">
                                    <label for="edit-email" class="floating-label">Email Address</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-ring"></i></span>
                                    <?php renderPersonalSelect('civil_status', 'edit-civil-status', $CIVIL_STATUS_OPTIONS, '', false, 'Select Civil Status'); ?>
                                    <label for="edit-civil-status" class="floating-label">Civil / Marital Status</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-house-user"></i></span>
                                    <?php renderPersonalSelect('family_status', 'edit-family-status', $FAMILY_STATUS_OPTIONS, '', true, 'Select Family Status'); ?>
                                    <label for="edit-family-status" class="floating-label">Family / Household Status</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-star"></i></span>
                                    <?php renderPersonalSelect('special_sector', 'edit-special-sector', $SPECIAL_SECTOR_OPTIONS, '', true, 'Select Special Sector'); ?>
                                    <label for="edit-special-sector" class="floating-label">Special Sector / Target Group</label>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <h6 class="form-section-title mt-3">Address Information</h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-map-marked-alt"></i></span>
                                    <select name="province" id="edit-province" class="form-select">
                                        <option value="" selected disabled>Select Province</option>
                                        <option value="Biliran">Biliran</option>
                                        <option value="Leyte">Leyte</option>
                                        <option value="Southern Leyte">Southern Leyte</option>
                                        <option value="Samar">Samar</option>
                                        <option value="Eastern Samar">Eastern Samar</option>
                                        <option value="Northern Samar">Northern Samar</option>
                                    </select>
                                    <label for="edit-province" class="floating-label">Province</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-city"></i></span>
                                    <select name="municipality" id="edit-municipality" class="form-select">
                                        <option value="" selected disabled>Select Province</option>
                                    </select>
                                    <label for="edit-municipality" class="floating-label">Municipality</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-home"></i></span>
                                    <select name="barangay" id="edit-barangay" class="form-select">
                                        <option value="" selected disabled>Select Municipality</option>
                                    </select>
                                    <label for="edit-barangay" class="floating-label">Barangay</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <span class="input-icon"><i class="fas fa-mail-bulk"></i></span>
                                    <input type="text" name="zipcode" id="edit-zipcode" class="form-control" readonly>
                                    <label for="edit-zipcode" class="floating-label">Zip Code</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer" style="border-top: 1px solid rgba(0, 242, 234, 0.2);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="updateStudentData()">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Irregular Subjects Modal -->
    <div class="modal fade" id="irregularModal" tabindex="-1" aria-labelledby="irregularModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="irregularModalLabel">
                        <i class="fas fa-random me-2"></i>Irregular Subjects
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Pick the year level and section of the class offering — the subject list shows every subject assigned to that section by any faculty. You may add more than one subject.</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-layer-group"></i></span>
                                <select id="irr-year" class="form-select">
                                    <option value="" disabled selected>Year</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                    <option value="5">5th Year</option>
                                    <option value="6">6th Year</option>
                                </select>
                                <label for="irr-year" class="floating-label">Year Level</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-users"></i></span>
                                <input type="text" id="irr-section" class="form-control"
                                    minlength="2" maxlength="4" style="text-transform: uppercase;" autocomplete="off" spellcheck="false">
                                <label for="irr-section" class="floating-label">Section</label>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <span class="input-icon"><i class="fas fa-book"></i></span>
                                <select id="irr-subject" class="form-select">
                                    <option value="" disabled selected>Select year &amp; section first</option>
                                </select>
                                <label for="irr-subject" class="floating-label">Subject</label>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm mt-2" onclick="addIrregularSubject()">
                        <i class="fas fa-plus me-1"></i>Add Subject
                    </button>
                    <div id="irr-error" class="mt-2" style="display: none; color: #dc3545; font-size: 0.8rem;"></div>
                    <div id="irr-list" class="mt-3"></div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid rgba(0, 242, 234, 0.2);">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                        <i class="fas fa-check me-1"></i>Done
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Status Modal (student number check responses) -->
    <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="statusModalHeader">
                    <h5 class="modal-title" id="statusModalLabel"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="statusModalBody"></div>
                <div class="modal-footer" id="statusModalFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Every course the admin has set up under Administration > Departments
        // & Courses. Used to keep a stored course selectable when the list
        // changes and the stored value is no longer part of it.
        const COURSE_OPTIONS = <?php echo json_encode(array_values($course_opts)); ?>;

        // Remove particle creation - simplified approach
        document.addEventListener('DOMContentLoaded', function() {
            // Form initialization and other functions can go here
            console.log('Landing page loaded');
        });

        // Form functions
        function updateBirthdate() {
            const year = document.getElementById('birth_year').value;
            const month = document.getElementById('birth_month').value;
            const day = document.getElementById('birth_day').value;

            if (year && month && day) {
                const birthdate = `${year}-${month}-${day}`;
                document.getElementById('birthdate').value = birthdate;
                calculateAge();
            }
        }

        function calculateAge(birthdateId = 'birthdate', ageId = 'age') {
            const birthdateEl = document.getElementById(birthdateId);
            const ageEl = document.getElementById(ageId);
            if (!birthdateEl || !ageEl) return;

            const birthdate = birthdateEl.value;
            if (birthdate) {
                const birth = new Date(birthdate);
                const today = new Date();
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();

                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }

                ageEl.value = age;
            } else {
                ageEl.value = '';
            }
        }

        // Form validation
        document.getElementById('student-form').addEventListener('submit', function(e) {
            e.preventDefault();

            // Get all required inputs
            const requiredInputs = this.querySelectorAll('input[required], select[required]');
            let firstEmptyField = null;
            let isValid = true;

            // Clear previous error states
            requiredInputs.forEach(input => {
                input.classList.remove('is-invalid');
            });

            // Check each required field
            requiredInputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('is-invalid');

                    if (!firstEmptyField) {
                        firstEmptyField = input;
                    }
                }
            });

            // Special validation for student number format
            const studentNumber = document.getElementById('student_number');
            const studentNumberPattern = /^\d{2}-\d{5}$/;
            if (studentNumber.value && !studentNumberPattern.test(studentNumber.value)) {
                isValid = false;
                studentNumber.classList.add('is-invalid');
                if (!firstEmptyField) firstEmptyField = studentNumber;
            }

            // Section: must be year digit + letters only A-Z, no symbols (e.g. 1A, 2B)
            const sectionInput = document.getElementById('section');
            const yearInput = document.getElementById('year');
            const sectionVal = sectionInput ? sectionInput.value.trim().toUpperCase() : '';
            const yearVal = yearInput ? String(yearInput.value || '').trim() : '';
            const sectionPattern = /^[1-6][A-Z]+$/;
            if (sectionInput && sectionVal.length > 0 && sectionVal.length < 2) {
                isValid = false;
                sectionInput.classList.add('is-invalid');
                if (!firstEmptyField) firstEmptyField = sectionInput;
                showError('Section must be at least 2 characters (e.g. 1A, 2B).');
                return;
            }
            if (sectionInput && sectionVal && !sectionPattern.test(sectionVal)) {
                isValid = false;
                sectionInput.classList.add('is-invalid');
                if (!firstEmptyField) firstEmptyField = sectionInput;
                showError('Section must start with the year number (1-6) followed by letters only A-Z (e.g. 1A, 2B). Symbols are not allowed.');
                return;
            }
            if (sectionInput && sectionVal && yearVal && sectionVal[0] !== yearVal) {
                isValid = false;
                sectionInput.classList.add('is-invalid');
                if (!firstEmptyField) firstEmptyField = sectionInput;
                showError('Section must start with the selected Year Level (e.g. Year ' + yearVal + ' → ' + yearVal + 'A).');
                return;
            }

            // Irregular students must pick at least one subject via the modal
            const irrCheckbox = document.getElementById('is_irregular');
            if (irrCheckbox && irrCheckbox.checked) {
                let irrItems = [];
                try {
                    irrItems = JSON.parse(document.getElementById('irregular_subjects_json').value || '[]');
                } catch (e) {
                    irrItems = [];
                }
                if (!Array.isArray(irrItems) || irrItems.length === 0) {
                    isValid = false;
                    showError('You marked Irregular — please click Manage and add at least one subject.');
                    const mgrBtn = document.getElementById('manage-irregular-btn');
                    if (mgrBtn) mgrBtn.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    return;
                }
            }

            // Special validation for consecutive capital letters in name fields
            const nameFields = ['lastname', 'firstname', 'middlename'];
            for (const fieldId of nameFields) {
                const nameInput = document.getElementById(fieldId);
                if (nameInput.value && hasConsecutiveCaps(nameInput.value)) {
                    isValid = false;
                    nameInput.classList.add('is-invalid');
                    if (!firstEmptyField) firstEmptyField = nameInput;
                    showError('Please avoid using 2 or more consecutive capital letters in the name fields.');
                    return;
                }
            }

            // Special validation for single-letter middle name (with or without period)
            const middlenameInput = document.getElementById('middlename');
            if (middlenameInput.value) {
                const trimmedValue = middlenameInput.value.trim().replace('.', '');
                if (trimmedValue.length === 1) {
                    isValid = false;
                    middlenameInput.classList.add('is-invalid');
                    if (!firstEmptyField) firstEmptyField = middlenameInput;
                    showError('Middle name cannot be a single letter. Please enter a full middle name or leave it blank.');
                    return;
                }
            }
            const birthYear = document.getElementById('birth_year').value;
            const birthMonth = document.getElementById('birth_month').value;
            const birthDay = document.getElementById('birth_day').value;
            if (!birthYear || !birthMonth || !birthDay) {
                isValid = false;
                if (!birthYear) document.getElementById('birth_year').classList.add('is-invalid');
                if (!birthMonth) document.getElementById('birth_month').classList.add('is-invalid');
                if (!birthDay) document.getElementById('birth_day').classList.add('is-invalid');

                if (!firstEmptyField && !birthYear) firstEmptyField = document.getElementById('birth_year');
                else if (!firstEmptyField && !birthMonth) firstEmptyField = document.getElementById('birth_month');
                else if (!firstEmptyField && !birthDay) firstEmptyField = document.getElementById('birth_day');
            }

            if (!isValid) {
                // Scroll to first empty field
                if (firstEmptyField) {
                    firstEmptyField.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    firstEmptyField.focus();
                }

                // Show error message
                showError('Please fill in all required fields correctly before submitting.');
                return;
            }

            // If validation passes, submit the form
            this.submit();
        });

        function showError(message) {
            // Remove any existing error alerts
            const existingError = document.querySelector('.alert-danger');
            if (existingError) {
                existingError.remove();
            }

            // Create new error alert
            const errorDiv = document.createElement('div');
            errorDiv.className = 'alert alert-danger alert-dismissible fade show';
            errorDiv.innerHTML = `
                <i class="fas fa-exclamation-triangle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            // Insert at the top of the form
            const form = document.getElementById('student-form');
            form.parentNode.insertBefore(errorDiv, form);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                errorDiv.style.transition = 'opacity 0.5s';
                errorDiv.style.opacity = '0';
                setTimeout(() => errorDiv.remove(), 500);
            }, 5000);
        }

        // Remove error state when user starts typing
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('is-invalid')) {
                e.target.classList.remove('is-invalid');
            }
        });

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('is-invalid')) {
                e.target.classList.remove('is-invalid');
            }
        });

        function autoFormatStudentNumber(input) {
            // Remove any non-digit characters
            let value = input.value.replace(/\D/g, '');

            // Add dash after 2 digits
            if (value.length > 2) {
                value = value.slice(0, 2) + '-' + value.slice(2);
            }

            // Limit to 8 characters (2 digits + dash + 5 digits)
            if (value.length > 8) {
                value = value.slice(0, 8);
            }

            input.value = value;
        }

        // Escape HTML for safe injection of server-provided strings
        function escHtml(str) {
            return String(str === undefined || str === null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Show a student-number check response inside the status modal.
        // opts: { type: 'warning'|'success'|'danger', icon, title, body, showViewEdit }
        function showStatusModal(opts) {
            var header = document.getElementById('statusModalHeader');
            var headerText = opts.type === 'warning' ? 'text-dark' : 'text-white';
            header.className = 'modal-header bg-' + opts.type + ' ' + headerText;
            document.getElementById('statusModalLabel').innerHTML =
                '<i class="fas ' + opts.icon + ' me-2"></i>' + escHtml(opts.title);
            document.getElementById('statusModalBody').innerHTML = opts.body;
            var footer = document.getElementById('statusModalFooter');
            footer.innerHTML = '';
            if (opts.showViewEdit) {
                var viewBtn = document.createElement('button');
                viewBtn.type = 'button';
                viewBtn.className = 'btn btn-primary';
                viewBtn.innerHTML = '<i class="fas fa-eye me-1"></i>View/Edit Data';
                viewBtn.onclick = function() {
                    bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
                    viewStudentData();
                };
                footer.appendChild(viewBtn);
            }
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'btn btn-secondary';
            closeBtn.setAttribute('data-bs-dismiss', 'modal');
            closeBtn.textContent = 'Close';
            footer.appendChild(closeBtn);
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        // Check student registration status
        let registrationTimeout;

        function checkStudentRegistration(studentNumber) {
            const statusDiv = document.getElementById('student-status');
            const actionsDiv = document.getElementById('student-actions');

            // Clear previous timeout
            if (registrationTimeout) {
                clearTimeout(registrationTimeout);
            }

            // Clear previous status
            statusDiv.innerHTML = '';

            // Only check if student number is complete (8 characters with dash)
            if (studentNumber.length !== 8 || !studentNumber.match(/^\d{2}-\d{5}$/)) {
                actionsDiv.style.display = 'none';
                return;
            }

            // Show view/edit button for any valid student number
            actionsDiv.style.display = 'block';

            // Add a small delay to avoid too many API calls
            registrationTimeout = setTimeout(() => {
                // urlencoded, NOT multipart FormData: the reverse proxy in front
                // of the deployed host (openresty) answers every multipart POST
                // with 403 Forbidden, and that HTML error page is what
                // response.json() choked on ("Unexpected token '<'"). PHP fills
                // $_POST identically for both encodings — the check itself still
                // reads db/landing_db.db (landing_registrations) unchanged.
                fetch('check_student_registration.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: 'student_number=' + encodeURIComponent(studentNumber)
                    })
                    .then(response => response.text().then(text => ({ status: response.status, text: text })))
                    .then(x => {
                        try { return JSON.parse(x.text); }
                        catch (e) {
                            // Non-JSON body (host block page / JS challenge):
                            // report the real HTTP status instead of a raw
                            // SyntaxError stack trace in the console.
                            throw new Error(x.status === 403
                                ? 'the server refused the request (HTTP 403).'
                                : ('unexpected response (HTTP ' + x.status + ').'));
                        }
                    })
                    .then(data => {
                        // Keep the inline area clear - every response goes in the modal
                        statusDiv.innerHTML = '';

                        if (data.status === 'registered') {
                            // Student number is already registered - modal with details + View/Edit button
                            showStatusModal({
                                type: 'warning',
                                icon: 'fa-exclamation-triangle',
                                title: 'Already Registered!',
                                body: '<p class="mb-2">This student number is already registered.</p>' +
                                    '<table class="table table-sm table-bordered mb-0">' +
                                    '<tr><th style="width: 40%;">Name</th><td>' + escHtml(data.data.name) + '</td></tr>' +
                                    '<tr><th>Course / Year / Section</th><td>' + escHtml(data.data.course) + ' - ' + escHtml(data.data.year) + ' - ' + escHtml(data.data.section) + '</td></tr>' +
                                    '<tr><th>Registered</th><td>' + escHtml(data.data.registration_date) + '</td></tr>' +
                                    '</table>',
                                showViewEdit: true
                            });

                            // Show view/edit button
                            actionsDiv.style.display = 'block';

                            // Add visual indicator to input field
                            document.getElementById('student_number').style.borderColor = '#f59e0b';
                            document.getElementById('student_number').style.boxShadow = '0 0 0 0.25rem rgba(245, 158, 11, 0.25)';

                            // Disable submit button to prevent duplicate registration
                            const enrollBtn = document.querySelector('button[type="submit"]');
                            if (enrollBtn) {
                                enrollBtn.disabled = true;
                                enrollBtn.style.opacity = '0.6';
                                enrollBtn.innerHTML = '<i class="fas fa-ban me-2"></i>Already Registered';
                            }

                        } else if (data.status === 'available') {
                            // Student number is available for registration
                            showStatusModal({
                                type: 'success',
                                icon: 'fa-check-circle',
                                title: 'Available for Registration',
                                body: '<p class="mb-0">This student number is not yet registered. You may proceed with the enrollment form.</p>',
                                showViewEdit: false
                            });

                            // Keep view/edit button visible
                            actionsDiv.style.display = 'block';

                            // Reset input field styling
                            document.getElementById('student_number').style.borderColor = '';
                            document.getElementById('student_number').style.boxShadow = '';

                            // Enable submit button
                            const enrollBtn = document.querySelector('button[type="submit"]');
                            if (enrollBtn) {
                                enrollBtn.disabled = false;
                                enrollBtn.style.opacity = '';
                                enrollBtn.innerHTML = '<i class="fas fa-user-plus me-2"></i>Submit Registration';
                            }

                        } else if (data.status === 'error') {
                            // Error occurred
                            showStatusModal({
                                type: 'danger',
                                icon: 'fa-exclamation-triangle',
                                title: 'Error',
                                body: '<p class="mb-0">' + escHtml(data.message) + '</p>',
                                showViewEdit: false
                            });

                            // Keep view/edit button visible for troubleshooting
                            actionsDiv.style.display = 'block';

                            // Reset input field styling
                            document.getElementById('student_number').style.borderColor = '';
                            document.getElementById('student_number').style.boxShadow = '';

                            // Enable submit button
                            const enrollBtn = document.querySelector('button[type="submit"]');
                            if (enrollBtn) {
                                enrollBtn.disabled = false;
                                enrollBtn.style.opacity = '';
                                enrollBtn.innerHTML = '<i class="fas fa-user-plus me-2"></i>Submit Registration';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error checking student registration:', error);
                        showStatusModal({
                            type: 'danger',
                            icon: 'fa-exclamation-triangle',
                            title: 'Connection Error',
                            body: '<p class="mb-0">Unable to verify student number. Please check your connection and try again.</p>' +
                                (error && error.message ? '<p class="mb-0 small mt-2 opacity-75">(' + escHtml(error.message) + ')</p>' : ''),
                            showViewEdit: false
                        });

                        // Keep view/edit button visible for troubleshooting
                        actionsDiv.style.display = 'block';
                    });
            }, 500); // 500ms delay
        }

        // View student data function
        function viewStudentData() {
            const studentNumber = document.getElementById('student_number').value;

            if (!studentNumber || studentNumber.length !== 8) {
                showError('Please enter a valid student number first.');
                return;
            }

            // Get current timezone and screen resolution
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const screenResolution = window.screen.width + 'x' + window.screen.height;

            // Fetch student data with timezone and screen resolution parameters
            let url = `get_student_data.php?student_number=${studentNumber}&timezone=${timezone}&screen_resolution=${screenResolution}`;

            // Fetch student data with timezone and screen resolution parameters
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Populate modal with student data
                        populateEditModal(data.data);

                        // Show modal
                        const modal = new bootstrap.Modal(document.getElementById('studentModal'));
                        modal.show();
                    } else if (data.error_code === 'DEVICE_MISMATCH') {
                        // Device mismatch - show specific error
                        showError('Access denied: You can only view/edit data submitted by you. This student was registered from a different owner.');
                    } else {
                        showError(data.message || 'Failed to load student data.');
                    }
                })
                .catch(error => {
                    console.error('Error fetching student data:', error);
                    showError('Connection error. Please try again.');
                });
        }

        // Parse stored name into separate fields for the edit modal
        function parseStudentName(fullName, middlenameFromDb) {
            const extensions = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
            let namePart = (fullName || '').trim();
            let name_extension = '';

            for (const ext of extensions) {
                const suffix = ' ' + ext;
                if (namePart.endsWith(suffix)) {
                    name_extension = ext;
                    namePart = namePart.slice(0, -suffix.length).trim();
                    break;
                }
            }

            const commaIndex = namePart.indexOf(',');
            if (commaIndex === -1) {
                return {
                    lastname: namePart,
                    firstname: '',
                    middlename: middlenameFromDb || '',
                    name_extension
                };
            }

            const lastname = namePart.slice(0, commaIndex).trim();
            let afterComma = namePart.slice(commaIndex + 1).trim();
            const middlename = middlenameFromDb || '';
            let firstname = afterComma;

            if (middlename) {
                const middleSuffix = ' ' + middlename;
                if (afterComma.endsWith(middleSuffix)) {
                    firstname = afterComma.slice(0, -middleSuffix.length).trim();
                } else if (afterComma === middlename) {
                    firstname = '';
                }
            }

            return {
                lastname,
                firstname,
                middlename,
                name_extension
            };
        }

        // Populate edit modal with student data
        function populateEditModal(student) {
            // Set student number (hidden field)
            document.getElementById('edit-student-number').value = student.student_number;

            // Basic information
            const nameParts = parseStudentName(student.name, student.middlename);
            document.getElementById('edit-lastname').value = nameParts.lastname;
            document.getElementById('edit-firstname').value = nameParts.firstname;
            document.getElementById('edit-middlename').value = nameParts.middlename;
            document.getElementById('edit-name-extension').value = student.name_extension || nameParts.name_extension;

            // Academic information
            (function() {
                const courseEl = document.getElementById('edit-course');
                const courseVal = student.course || '';
                const allowed = COURSE_OPTIONS;
                if (courseVal && !allowed.includes(courseVal)) {
                    let opt = [...courseEl.options].find(o => o.value === courseVal);
                    if (!opt) {
                        opt = document.createElement('option');
                        opt.value = courseVal;
                        opt.textContent = courseVal + ' (existing)';
                        courseEl.appendChild(opt);
                    }
                }
                courseEl.value = courseVal;
            })();
            document.getElementById('edit-year').value = student.year || '';
            document.getElementById('edit-section').value = student.section || '';
            // Irregular (landing self-registration)
            (function() {
                const cb = document.getElementById('edit-is_irregular');
                const hidden = document.getElementById('edit-irregular_subjects_json');
                let entries = [];
                try {
                    const raw = student.irregular_subjects;
                    const parsed = typeof raw === 'string' ? JSON.parse(raw || '[]') : (Array.isArray(raw) ? raw : []);
                    if (Array.isArray(parsed)) {
                        entries = parsed.filter(e => e && /^[1-6]$/.test(String(e.year || '')) &&
                            /^[1-6][A-Z]+$/.test(String(e.section || '').toUpperCase()) &&
                            String(e.code || '').trim() !== '' && String(e.name || '').trim() !== '');
                    }
                } catch (e) {
                    entries = [];
                }
                const isIrr = parseInt(student.is_irregular ?? 0, 10) === 1 && entries.length > 0 ? true : (parseInt(student.is_irregular ?? 0, 10) === 1);
                // Keep checkbox checked only if actually irregular with entries; otherwise reflect stored flag but allow clearing
                if (cb) cb.checked = isIrr;
                editIrregularSubjects = entries;
                if (hidden) hidden.value = JSON.stringify(editIrregularSubjects);
                // If stored flag was irregular but entries got filtered empty, keep array empty so validation will prompt
                if (isIrr && !entries.length) {
                    try {
                        const raw2 = student.irregular_subjects;
                        const p2 = typeof raw2 === 'string' ? JSON.parse(raw2 || '[]') : raw2;
                        if (Array.isArray(p2) && p2.length) editIrregularSubjects = [];
                    } catch (e2) {}
                }
                syncIrregularHidden();
                renderIrregularSummary();
                activeIrregularContext = 'edit';
                syncSectionFromYear('edit-year', 'edit-section');
            })();

            // Personal information
            document.getElementById('edit-birthdate').value = student.birthdate || '';
            calculateAge('edit-birthdate', 'edit-age');
            document.getElementById('edit-gender').value = student.gender || '';
            document.getElementById('edit-contact-number').value = student.contact_number || '';
            document.getElementById('edit-email').value = student.email || '';
            // Additional personal information (tolerate missing/legacy values)
            (function() {
                const pairs = [
                    ['edit-civil-status', student.civil_status],
                    ['edit-family-status', student.family_status],
                    ['edit-special-sector', student.special_sector]
                ];
                pairs.forEach(function(p) {
                    const el = document.getElementById(p[0]);
                    if (!el) return;
                    const val = (p[1] === undefined || p[1] === null) ? '' : String(p[1]);
                    // Only select the value if it exists as an option
                    el.value = [...el.options].some(o => o.value === val) ? val : '';
                });
            })();

            // Address information
            document.getElementById('edit-province').value = student.province || '';
            updateEditMunicipalities(); // Update municipalities based on province

            // Set municipality after municipalities are populated
            setTimeout(() => {
                document.getElementById('edit-municipality').value = student.municipality || '';
                updateEditBarangays(); // Update barangays based on municipality

                // Set barangay after barangays are populated
                setTimeout(() => {
                    document.getElementById('edit-barangay').value = student.barangay || '';
                    document.getElementById('edit-zipcode').value = student.zipcode || '';
                }, 100);
            }, 100);
        }

        // Update municipalities for edit modal
        function updateEditMunicipalities() {
            const provinceSelect = document.getElementById('edit-province');
            const municipalitySelect = document.getElementById('edit-municipality');
            const selectedProvince = provinceSelect.value;

            // Clear current options
            municipalitySelect.innerHTML = '<option value="">Select Municipality</option>';

            if (selectedProvince && municipalitiesData[selectedProvince]) {
                const municipalities = municipalitiesData[selectedProvince];
                municipalities.forEach(municipality => {
                    const option = document.createElement('option');
                    option.value = municipality;
                    option.textContent = municipality;
                    municipalitySelect.appendChild(option);
                });
            }
        }

        // Update student data function
        function updateStudentData() {
            const form = document.getElementById('edit-student-form');
            const formData = new FormData(form);

            // Add timezone and screen resolution to form data
            formData.append('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone);
            formData.append('screen_resolution', window.screen.width + 'x' + window.screen.height);

            // Validate required fields
            if (!formData.get('lastname') || !formData.get('firstname') || !formData.get('course') || !formData.get('year') || !formData.get('section')) {
                showError('Last Name, First Name, Course, Year and Section are required fields.');
                return;
            }

            // Section: must be year digit + letters only A-Z, no symbols (e.g. 1A, 2B)
            const editSectionRaw = String(formData.get('section') || '').trim().toUpperCase();
            const editYearRaw = String(formData.get('year') || '').trim();
            const editSectionPattern = /^[1-6][A-Z]+$/;
            if (editSectionRaw.length < 2) {
                showError('Section must be at least 2 characters (e.g. 1A, 2B).');
                return;
            }
            if (!editSectionPattern.test(editSectionRaw)) {
                showError('Section must start with the year number (1-6) followed by letters only A-Z (e.g. 1A, 2B). Symbols are not allowed.');
                return;
            }
            if (editYearRaw && editSectionRaw[0] !== editYearRaw) {
                showError('Section must start with the selected Year Level (e.g. Year ' + editYearRaw + ' → ' + editYearRaw + 'A).');
                return;
            }
            // Irregular: if checked, at least one picked subject required
            const editIrrChecked = document.getElementById('edit-is_irregular') && document.getElementById('edit-is_irregular').checked;
            if (editIrrChecked) {
                let editIrrItems = [];
                try {
                    editIrrItems = JSON.parse(document.getElementById('edit-irregular_subjects_json').value || '[]');
                } catch (e) {
                    editIrrItems = [];
                }
                if (!Array.isArray(editIrrItems) || editIrrItems.length === 0) {
                    showError('You marked Irregular — please click Manage and add at least one subject.');
                    return;
                }
                // Also ensure checkbox value is sent as 1
                formData.set('is_irregular', '1');
                formData.set('irregular_subjects_json', JSON.stringify(editIrrItems));
            } else {
                formData.set('is_irregular', '0');
                formData.set('irregular_subjects_json', '[]');
            }

            const editNameFields = ['edit-lastname', 'edit-firstname', 'edit-middlename'];
            for (const fieldId of editNameFields) {
                const nameInput = document.getElementById(fieldId);
                if (nameInput.value && hasConsecutiveCaps(nameInput.value)) {
                    showError('Please avoid using 2 or more consecutive capital letters in the name fields.');
                    return;
                }
            }

            // Special validation for single-letter middle name in edit form (with or without period)
            const editMiddlenameInput = document.getElementById('edit-middlename');
            if (editMiddlenameInput.value) {
                const trimmedValue = editMiddlenameInput.value.trim().replace('.', '');
                if (trimmedValue.length === 1) {
                    showError('Middle name cannot be a single letter. Please enter a full middle name or leave it blank.');
                    return;
                }
            }

            // Send update request. The form has no file inputs, so serialize
            // the collected fields as urlencoded: the deployed host's proxy
            // (openresty) answers every multipart/form-data POST with 403
            // Forbidden, and FormData always posts multipart. PHP fills $_POST
            // identically either way.
            const bodyParams = new URLSearchParams();
            formData.forEach((v, k) => bodyParams.append(k, v));
            fetch('update_student_data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: bodyParams.toString()
                })
                .then(response => response.text().then(text => ({ status: response.status, text: text })))
                .then(x => {
                    try { return JSON.parse(x.text); }
                    catch (e) {
                        // Non-JSON body (host block page / JS challenge): surface
                        // the HTTP status instead of a raw SyntaxError.
                        throw new Error('unexpected response (HTTP ' + x.status + ')');
                    }
                })
                .then(data => {
                    if (data.status === 'success') {
                        // Show success message
                        showSuccess('Student data updated successfully!');

                        // Close modal
                        const modal = bootstrap.Modal.getInstance(document.getElementById('studentModal'));
                        modal.hide();

                        // Re-check student registration to update display
                        checkStudentRegistration(document.getElementById('student_number').value);
                    } else if (data.error_code === 'DEVICE_MISMATCH') {
                        // Device mismatch - show specific error
                        showError('Access denied: You can only edit data submitted from this device. This student was registered from a different device.');
                    } else {
                        showError(data.message || 'Failed to update student data.');
                    }
                })
                .catch(error => {
                    console.error('Error updating student data:', error);
                    showError('Connection error. Please try again.' + (error && error.message ? ' (' + error.message + ')' : ''));
                });
        }

        // Show success message as popup toast
        function showSuccess(message) {
            // Remove any existing success toasts
            const existingToast = document.querySelector('.success-toast');
            if (existingToast) {
                existingToast.remove();
            }

            // Create toast container if it doesn't exist
            let toastContainer = document.getElementById('toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'toast-container';
                toastContainer.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                `;
                document.body.appendChild(toastContainer);
            }

            // Create new success toast
            const toastDiv = document.createElement('div');
            toastDiv.className = 'success-toast';
            toastDiv.style.cssText = `
                background: linear-gradient(135deg, #51cf66, #40c057);
                color: white;
                padding: 15px 20px;
                border-radius: 10px;
                margin-bottom: 10px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                min-width: 300px;
                animation: slideInRight 0.3s ease-out;
                font-size: 11px;
            `;
            toastDiv.innerHTML = `
                <i class="fas fa-check-circle me-2" style="font-size: 14px;"></i>
                <span style="flex: 1;">${message}</span>
                <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; color: white; font-size: 16px; cursor: pointer; margin-left: 10px;">×</button>
            `;

            // Add to container
            toastContainer.appendChild(toastDiv);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                toastDiv.style.transition = 'opacity 0.5s, transform 0.5s';
                toastDiv.style.opacity = '0';
                toastDiv.style.transform = 'translateX(100%)';
                setTimeout(() => toastDiv.remove(), 500);
            }, 5000);
        }

        // Add event listener for edit modal province change
        document.addEventListener('DOMContentLoaded', function() {
            // Enable Bootstrap tooltips (e.g. Middle Name hint)
            if (window.bootstrap) {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    new bootstrap.Tooltip(el);
                });
            }
            // Set timezone for main form
            document.getElementById('timezone').value = Intl.DateTimeFormat().resolvedOptions().timeZone;

            // Set screen resolution for main form
            const screenResolution = window.screen.width + 'x' + window.screen.height;
            document.getElementById('screenResolution').value = screenResolution;

            // Add event listener to edit modal province dropdown
            const editProvinceSelect = document.getElementById('edit-province');
            if (editProvinceSelect) {
                editProvinceSelect.addEventListener('change', function() {
                    updateEditMunicipalities();
                    // Clear barangay and zipcode when province changes
                    document.getElementById('edit-barangay').value = '';
                    document.getElementById('edit-zipcode').value = '';
                });
            }

            // Add event listener to edit modal municipality dropdown
            const editMunicipalitySelect = document.getElementById('edit-municipality');
            if (editMunicipalitySelect) {
                editMunicipalitySelect.addEventListener('change', function() {
                    updateEditBarangays();
                    updateEditZipcode();
                });
            }

            // Check for URL parameters to auto-populate student number
            const urlParams = new URLSearchParams(window.location.search);
            const studentNumber = urlParams.get('student_number');
            if (studentNumber) {
                document.getElementById('student_number').value = studentNumber;
                checkStudentRegistration(studentNumber);
            }
        });

        // Function to populate barangays for edit modal
        function updateEditBarangays() {
            const provinceSelect = document.getElementById('edit-province');
            const municipalitySelect = document.getElementById('edit-municipality');
            const barangaySelect = document.getElementById('edit-barangay');
            const selectedProvince = provinceSelect.value;
            const selectedMunicipality = municipalitySelect.value;

            // Clear current options
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';

            if (selectedProvince && selectedMunicipality) {
                // Get barangays from PHP data
                const barangaysData = <?php echo json_encode([
                                            'Biliran' => $biliran_barangays ?? [],
                                            'Eastern Samar' => $eastern_samar_barangays ?? [],
                                            'Leyte' => $leyte_barangays ?? [],
                                            'Northern Samar' => $northern_samar_barangays ?? [],
                                            'Samar' => $samar_barangays ?? [],
                                            'Southern Leyte' => $southern_leyte_barangays ?? []
                                        ]); ?>;

                if (barangaysData[selectedProvince] && barangaysData[selectedProvince][selectedMunicipality]) {
                    const barangays = barangaysData[selectedProvince][selectedMunicipality];
                    barangays.forEach(barangay => {
                        const option = document.createElement('option');
                        option.value = barangay;
                        option.textContent = barangay;
                        barangaySelect.appendChild(option);
                    });
                }
            }
        }

        // Function to auto-fill zipcode for edit modal
        function updateEditZipcode() {
            const provinceSelect = document.getElementById('edit-province');
            const municipalitySelect = document.getElementById('edit-municipality');
            const zipcodeInput = document.getElementById('edit-zipcode');
            const selectedProvince = provinceSelect.value;
            const selectedMunicipality = municipalitySelect.value;

            if (selectedProvince && selectedMunicipality) {
                // Get zipcodes from PHP data
                const zipcodesData = <?php echo json_encode($zipcodes); ?>;

                if (zipcodesData[selectedProvince] && zipcodesData[selectedProvince][selectedMunicipality]) {
                    zipcodeInput.value = zipcodesData[selectedProvince][selectedMunicipality];
                } else {
                    zipcodeInput.value = '';
                }
            } else {
                zipcodeInput.value = '';
            }
        }

        // Municipality data for each province
        const municipalitiesData = {
            'Biliran': [
                'Almeria', 'Biliran', 'Cabucgayan', 'Caibiran', 'Culaba',
                'Kawayan', 'Maripipi', 'Naval'
            ],
            'Eastern Samar': [
                'Arteche', 'Balangiga', 'Balangkayan', 'Borongan', 'Can-avid', 'Dolores',
                'Gen. MacArthur', 'Giporlos', 'Guiuan', 'Hernani', 'Jipapad',
                'Lawaan', 'Llorente', 'Maslog', 'Maydolong', 'Mercedes', 'Oras', 'Quinapondan',
                'Salcedo', 'San Julian', 'San Policarpo', 'Sulat', 'Taft'
            ],
            'Leyte': [
                'Tacloban City', 'Ormoc City', 'Abuyog', 'Alangalang', 'Albuera',
                'Babatngon', 'Barugo', 'Bato', 'Baybay City', 'Burauen', 'Capoocan',
                'Carigara', 'Dagami', 'Dulag', 'Hilongos', 'Hindang', 'Inopacan',
                'Isabel', 'Jaro', 'Javier', 'Julita', 'Kananga', 'La Paz', 'Leyte',
                'MacArthur', 'Mahaplag', 'Matag-ob', 'Matalom', 'Mayorga', 'Merida',
                'Palo', 'Palompon', 'Pastrana', 'San Isidro', 'San Miguel', 'Santa Fe',
                'Tabango', 'Tabontabon', 'Tanauan', 'Tolosa', 'Tunga', 'Villaba'
            ],
            'Northern Samar': [
                'Allen', 'Biri', 'Bobon', 'Capul', 'Catarman', 'Catubig', 'Gamay',
                'Laoang', 'Lapinig', 'Las Navas', 'Lavezares', 'Lope de Vega', 'Mapanas',
                'Mondragon', 'Palapag', 'Pambujan', 'Rosario', 'San Antonio', 'San Isidro',
                'San Jose', 'San Roque', 'San Vicente', 'Silvino Lobos', 'Victoria'
            ],
            'Samar': [
                'Calbayog City', 'Catbalogan City', 'Almagro', 'Basey', 'Calbiga',
                'Daram', 'Gandara', 'Hinabangan', 'Jiabong', 'Marabut', 'Matuguinao',
                'Motiong', 'Pagsanghan', 'Paranas', 'Pinabacdao', 'San Jorge',
                'San Jose de Buan', 'San Sebastian', 'Santa Margarita', 'Santa Rita',
                'Santo Niño', 'Tagapul-an', 'Talalora', 'Tarangnan', 'Villareal', 'Zumarraga'
            ],
            'Southern Leyte': [
                'Maasin City', 'Anahawan', 'Bontoc', 'Hinunangan', 'Hinundayan',
                'Libagon', 'Liloan', 'Macrohon', 'Malitbog', 'Padre Burgos', 'Pintuyan',
                'Saint Bernard', 'San Francisco', 'San Juan', 'San Ricardo', 'Silago',
                'Sogod', 'Tomas Oppus'
            ]
        };

        // Function to populate municipalities based on selected province
        function updateMunicipalities() {
            const provinceSelect = document.getElementById('province');
            const municipalitySelect = document.getElementById('municipality');
            const selectedProvince = provinceSelect.value;

            // Clear current options
            municipalitySelect.innerHTML = '<option value="">Select Municipality</option>';

            if (selectedProvince && municipalitiesData[selectedProvince]) {
                const municipalities = municipalitiesData[selectedProvince];
                municipalities.forEach(municipality => {
                    const option = document.createElement('option');
                    option.value = municipality;
                    option.textContent = municipality;

                    // Preserve selected value if it matches
                    const currentValue = '<?php echo isset($_POST['municipality']) ? $_POST['municipality'] : ''; ?>';
                    if (municipality === currentValue) {
                        option.selected = true;
                    }

                    municipalitySelect.appendChild(option);
                });
            }
        }

        // Add event listener to province dropdown
        document.getElementById('province').addEventListener('change', function() {
            updateMunicipalities();
            // Clear barangay and zipcode when province changes
            document.getElementById('barangay').value = '';
            document.getElementById('zipcode').value = '';
        });

        // Add event listener to municipality dropdown for barangay and zipcode
        document.getElementById('municipality').addEventListener('change', function() {
            updateBarangays();
            updateZipcode();
        });

        // Function to populate barangays based on selected municipality and province
        function updateBarangays() {
            const provinceSelect = document.getElementById('province');
            const municipalitySelect = document.getElementById('municipality');
            const barangaySelect = document.getElementById('barangay');
            const selectedProvince = provinceSelect.value;
            const selectedMunicipality = municipalitySelect.value;

            // Clear current options
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';

            if (selectedProvince && selectedMunicipality) {
                // Get barangays from PHP data
                const barangaysData = <?php echo json_encode([
                                            'Biliran' => $biliran_barangays ?? [],
                                            'Eastern Samar' => $eastern_samar_barangays ?? [],
                                            'Leyte' => $leyte_barangays ?? [],
                                            'Northern Samar' => $northern_samar_barangays ?? [],
                                            'Samar' => $samar_barangays ?? [],
                                            'Southern Leyte' => $southern_leyte_barangays ?? []
                                        ]); ?>;

                if (barangaysData[selectedProvince] && barangaysData[selectedProvince][selectedMunicipality]) {
                    const barangays = barangaysData[selectedProvince][selectedMunicipality];
                    barangays.forEach(barangay => {
                        const option = document.createElement('option');
                        option.value = barangay;
                        option.textContent = barangay;
                        barangaySelect.appendChild(option);
                    });
                }
            }
        }

        // Function to auto-fill zipcode based on selected municipality and province
        function updateZipcode() {
            const provinceSelect = document.getElementById('province');
            const municipalitySelect = document.getElementById('municipality');
            const zipcodeInput = document.getElementById('zipcode');
            const selectedProvince = provinceSelect.value;
            const selectedMunicipality = municipalitySelect.value;

            if (selectedProvince && selectedMunicipality) {
                // Get zipcodes from PHP data
                const zipcodesData = <?php echo json_encode($zipcodes); ?>;

                if (zipcodesData[selectedProvince] && zipcodesData[selectedProvince][selectedMunicipality]) {
                    zipcodeInput.value = zipcodesData[selectedProvince][selectedMunicipality];
                } else {
                    zipcodeInput.value = '';
                }
            } else {
                zipcodeInput.value = '';
            }
        }

        function insertSpecialChar(char) {
            const activeField = document.activeElement;
            const nameFieldIds = ['lastname', 'firstname', 'middlename', 'edit-lastname', 'edit-firstname', 'edit-middlename'];
            const nameField = (activeField && nameFieldIds.includes(activeField.id)) ?
                activeField :
                document.getElementById('lastname');
            const start = nameField.selectionStart;
            const end = nameField.selectionEnd;
            const text = nameField.value;
            const before = text.substring(0, start);
            const after = text.substring(end, text.length);

            nameField.value = before + char + after;
            nameField.focus();
            nameField.setSelectionRange(start + char.length, start + char.length);
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Capitalize each name part and validate consecutive capitals
        function formatNamePart(input) {
            let value = input.value;

            if (!value) {
                hideCapsTooltip(input);
                return;
            }

            if (hasConsecutiveCaps(value)) {
                showCapsTooltip(input);
            } else {
                hideCapsTooltip(input);
            }

            value = value.charAt(0).toUpperCase() + value.slice(1);
            value = value.replace(/(?:^|\s+)([a-z])/g, (_, letter) => ' ' + letter.toUpperCase());

            if (input.value !== value) {
                input.value = value;
            }
        }

        // Function to check for 2 or more consecutive capital letters
        function hasConsecutiveCaps(text) {
            // Check for 2 or more consecutive uppercase letters (excluding commas, spaces, and periods)
            const consecutiveCapsPattern = /[A-Z]{2,}/;
            // Remove commas, spaces, and periods for checking
            const cleanText = text.replace(/[,\.\s]/g, '');
            return consecutiveCapsPattern.test(cleanText);
        }

        // Function to show all caps tooltip
        function showCapsTooltip(input) {
            // Find or create tooltip
            let tooltip = input.parentNode.querySelector('.caps-tooltip');
            if (!tooltip) {
                tooltip = document.createElement('div');
                tooltip.className = 'caps-tooltip';
                tooltip.textContent = 'No All capital letters';
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(tooltip);
            }

            // Show tooltip
            setTimeout(() => {
                tooltip.classList.add('show');
            }, 10);
        }

        // Function to hide all caps tooltip
        function hideCapsTooltip(input) {
            const tooltip = input.parentNode.querySelector('.caps-tooltip');
            if (tooltip) {
                tooltip.classList.remove('show');
            }
        }

        // Function to show format tooltip
        function showFormatTooltip(input) {
            // Find or create tooltip
            let tooltip = input.parentNode.querySelector('.format-tooltip');
            if (!tooltip) {
                tooltip = document.createElement('div');
                tooltip.className = 'format-tooltip';
                tooltip.innerHTML = 'Format: Lastname, Firstname, Middle Initial<br>Example: Delacruz, Juan P. Jr. or III';
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(tooltip);
            }

            // Show tooltip
            setTimeout(() => {
                tooltip.classList.add('show');
            }, 10);
        }

        // Function to hide format tooltip
        function hideFormatTooltip(input) {
            const tooltip = input.parentNode.querySelector('.format-tooltip');
            if (tooltip) {
                tooltip.classList.remove('show');
            }
        }

        // Function to notify other tabs when a new student is registered
        function notifyStudentRegistered(studentData) {
            // Use localStorage to notify other tabs
            const notification = {
                type: 'student_registered',
                data: studentData,
                timestamp: Date.now()
            };

            // Store in localStorage
            localStorage.setItem('student_registered', JSON.stringify(notification));

            // Broadcast to other tabs using BroadcastChannel API
            if ('BroadcastChannel' in window) {
                const channel = new BroadcastChannel('student_updates');
                channel.postMessage(notification);
                channel.close();
            }

            // Remove the notification after 5 seconds
            setTimeout(() => {
                localStorage.removeItem('student_registered');
            }, 5000);
        }

        // Year Level -> Section prefix lock (e.g. Year 1 => Section 1, 1A - "1" cannot be erased, suffix capitalized)
        function syncSectionFromYear(yearId, sectionId) {
            const y = document.getElementById(yearId);
            const s = document.getElementById(sectionId);
            if (!y || !s) return;
            const v = String(y.value || '').trim().toUpperCase();
            const oldPrefix = s.dataset.prefix || '';
            if (!v) {
                s.dataset.prefix = '';
                s.value = '';
                s.placeholder = 'e.g. 1A, 2B';
                return;
            }
            s.dataset.prefix = v;
            s.style.textTransform = 'uppercase';
            let suffix = '';
            const cur = s.value ? String(s.value).toUpperCase() : '';
            if (cur) {
                if (oldPrefix && cur.startsWith(oldPrefix)) {
                    suffix = cur.slice(oldPrefix.length);
                } else {
                    suffix = cur.replace(/^[1-6]+/, '');
                }
                suffix = suffix.replace(/[^A-Z]/g, '');
            }
            s.value = v + suffix;
            requestAnimationFrame(() => {
                const pos = Math.max(v.length, s.selectionStart || v.length);
                try {
                    s.setSelectionRange(pos, pos);
                } catch (e) {}
            });
        }

        function enforceSectionPrefix(e) {
            const s = e.target;
            const prefix = s.dataset.prefix || '';
            if (!prefix) return;
            let raw = s.value ? String(s.value).toUpperCase() : '';
            let suffix = raw.replace(/^[1-6]*/, '').replace(/[^A-Z]/g, '');
            let expected = prefix + suffix;
            if (s.value !== expected) {
                const pos = s.selectionStart;
                s.value = expected;
                try {
                    let newPos = Math.max(prefix.length, Math.min(expected.length, pos !== null ? pos : expected.length));
                    // if characters were stripped, adjust back by diff
                    if (raw.length !== expected.length && pos !== null) {
                        newPos = Math.max(prefix.length, pos - (raw.length - expected.length));
                        newPos = Math.min(newPos, expected.length);
                    }
                    s.setSelectionRange(newPos, newPos);
                } catch (e2) {}
            }
        }

        function guardSectionBackspace(e) {
            const s = e.target;
            const prefix = s.dataset.prefix || '';
            if (!prefix) return;
            const start = s.selectionStart;
            const end = s.selectionEnd;
            if (start < prefix.length) {
                if (e.key === 'Backspace' && start <= prefix.length && end <= prefix.length) {
                    e.preventDefault();
                    try {
                        s.setSelectionRange(prefix.length, prefix.length);
                    } catch (e2) {}
                } else if (e.key === 'Delete' && start < prefix.length) {
                    e.preventDefault();
                } else if (e.key.length === 1 && start < prefix.length) {
                    e.preventDefault();
                    try {
                        s.setSelectionRange(s.value.length, s.value.length);
                    } catch (e2) {}
                }
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            syncSectionFromYear('year', 'section');
            syncSectionFromYear('edit-year', 'edit-section');
            ['section', 'edit-section'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                el.style.textTransform = 'uppercase';
                el.setAttribute('autocomplete', 'off');
                el.setAttribute('spellcheck', 'false');
                el.addEventListener('keydown', function(e) {
                    if (e.key === ' ') e.preventDefault();
                });
                el.addEventListener('input', function(e) {
                    // Strip spaces immediately and enforce prefix/uppercase
                    if (this.value.includes(' ')) {
                        const pos = this.selectionStart;
                        this.value = this.value.replace(/\s+/g, '');
                        try {
                            this.setSelectionRange(pos - 1, pos - 1);
                        } catch (e2) {}
                    }
                    enforceSectionPrefix({
                        target: this
                    });
                });
                el.addEventListener('keydown', guardSectionBackspace);
                el.addEventListener('cut', function(e) {
                    const prefix = this.dataset.prefix || '';
                    if (!prefix) return;
                    const start = this.selectionStart;
                    if (start < prefix.length) e.preventDefault();
                });
                el.addEventListener('paste', function(e) {
                    const prefix = this.dataset.prefix || '';
                    if (!prefix) return;
                    setTimeout(() => {
                        // Strip spaces from pasted content as well
                        this.value = this.value.replace(/\s+/g, '');
                        enforceSectionPrefix({
                            target: this
                        });
                    }, 0);
                });
                // Also block space via beforeinput for IME
                el.addEventListener('beforeinput', function(e) {
                    if (e.data === ' ') e.preventDefault();
                });
            });
            const y1 = document.getElementById('year');
            const y2 = document.getElementById('edit-year');
            if (y1) y1.addEventListener('change', () => syncSectionFromYear('year', 'section'));
            if (y2) y2.addEventListener('change', () => syncSectionFromYear('edit-year', 'edit-section'));
        });

        // Ensure section stays synced when edit modal is opened (populated via viewStudentData)
        document.addEventListener('DOMContentLoaded', function() {
            const sm = document.getElementById('studentModal');
            if (sm) {
                sm.addEventListener('shown.bs.modal', function() {
                    setTimeout(() => syncSectionFromYear('edit-year', 'edit-section'), 80);
                });
            }
            // Also sync if edit button is clicked (fallback)
            document.querySelectorAll('.landing-view-btn, [data-bs-target="#studentModal"]').forEach(btn => {
                btn.addEventListener('click', () => setTimeout(() => syncSectionFromYear('edit-year', 'edit-section'), 300));
            });
        });

        // ---- Irregular subjects picker (shared by main form and view/edit modal) ----
        // Checkbox opens the modal; year + section drive the subject dropdown
        // (subjects offered to that section by ANY user via get_irregular_subjects.php).
        let irregularSubjects = [];
        let editIrregularSubjects = [];
        let irrFetchedSubjects = [];
        let irrLoadTimer = null;
        let activeIrregularContext = 'main'; // 'main' or 'edit'
        try {
            const savedIrr = JSON.parse(document.getElementById('irregular_subjects_json').value || '[]');
            if (Array.isArray(savedIrr)) {
                irregularSubjects = savedIrr.filter(e => e && /^[1-6]$/.test(String(e.year || '')) &&
                    /^[1-6][A-Z]+$/.test(String(e.section || '').toUpperCase()) &&
                    String(e.code || '').trim() !== '' && String(e.name || '').trim() !== '');
            }
        } catch (e) {
            irregularSubjects = [];
        }
        try {
            const savedEditIrr = JSON.parse(document.getElementById('edit-irregular_subjects_json').value || '[]');
            if (Array.isArray(savedEditIrr)) {
                editIrregularSubjects = savedEditIrr.filter(e => e && /^[1-6]$/.test(String(e.year || '')) &&
                    /^[1-6][A-Z]+$/.test(String(e.section || '').toUpperCase()) &&
                    String(e.code || '').trim() !== '' && String(e.name || '').trim() !== '');
            }
        } catch (e) {
            editIrregularSubjects = [];
        }

        function getActiveIrregularArray() {
            return activeIrregularContext === 'edit' ? editIrregularSubjects : irregularSubjects;
        }

        function setActiveIrregularArray(arr) {
            if (activeIrregularContext === 'edit') editIrregularSubjects = arr;
            else irregularSubjects = arr;
        }

        function irrShowError(msg) {
            const box = document.getElementById('irr-error');
            if (!box) return;
            box.textContent = msg;
            box.style.display = 'block';
            clearTimeout(box._t);
            box._t = setTimeout(() => {
                box.style.display = 'none';
            }, 4000);
        }

        function prefillIrregularModal() {
            const srcYear = document.getElementById(activeIrregularContext === 'edit' ? 'edit-year' : 'year');
            const irrYear = document.getElementById('irr-year');
            if (srcYear && irrYear && srcYear.value && !irrYear.value) irrYear.value = srcYear.value;
            syncSectionFromYear('irr-year', 'irr-section');
            renderIrregularList();
            loadIrregularSubjects();
        }

        function toggleIrregular() {
            activeIrregularContext = 'main';
            const cb = document.getElementById('is_irregular');
            if (cb.checked) {
                prefillIrregularModal();
                new bootstrap.Modal(document.getElementById('irregularModal')).show();
            } else {
                irregularSubjects = [];
                syncIrregularHidden();
            }
            renderIrregularSummary();
        }

        function toggleEditIrregular() {
            activeIrregularContext = 'edit';
            const cb = document.getElementById('edit-is_irregular');
            if (cb.checked) {
                prefillIrregularModal();
                new bootstrap.Modal(document.getElementById('irregularModal')).show();
            } else {
                editIrregularSubjects = [];
                syncIrregularHidden();
            }
            renderIrregularSummary();
        }

        function openIrregularModal() {
            activeIrregularContext = 'main';
            prefillIrregularModal();
            new bootstrap.Modal(document.getElementById('irregularModal')).show();
        }

        function openEditIrregularModal() {
            activeIrregularContext = 'edit';
            prefillIrregularModal();
            new bootstrap.Modal(document.getElementById('irregularModal')).show();
        }

        function queueIrregularReload() {
            if (irrLoadTimer) clearTimeout(irrLoadTimer);
            irrLoadTimer = setTimeout(loadIrregularSubjects, 350);
        }

        function loadIrregularSubjects() {
            const yearEl = document.getElementById('irr-year');
            const secEl = document.getElementById('irr-section');
            const subEl = document.getElementById('irr-subject');
            if (!yearEl || !secEl || !subEl) return;
            const y = String(yearEl.value || '').trim();
            const s = String(secEl.value || '').trim().toUpperCase();
            irrFetchedSubjects = [];
            if (!/^[1-6]$/.test(y) || !/^[1-6][A-Z]+$/.test(s) || s[0] !== y) {
                subEl.innerHTML = '<option value="" disabled selected>Select a valid year &amp; section first</option>';
                return;
            }
            subEl.innerHTML = '<option value="" disabled selected>Loading subjects…</option>';
            const courseEl = document.getElementById(activeIrregularContext === 'edit' ? 'edit-course' : 'course');
            const course = courseEl ? courseEl.value || '' : '';
            fetch('get_irregular_subjects.php?year=' + encodeURIComponent(y) +
                    '&section=' + encodeURIComponent(s) + '&course=' + encodeURIComponent(course))
                .then(r => r.json())
                .then(data => {
                    if (!data || data.status !== 'success') {
                        subEl.innerHTML = '<option value="" disabled selected>' + escHtml((data && data.message) || 'Failed to load subjects') + '</option>';
                        return;
                    }
                    irrFetchedSubjects = data.subjects || [];
                    if (!irrFetchedSubjects.length) {
                        subEl.innerHTML = '<option value="" disabled selected>No subjects found for ' + escHtml(s) + '</option>';
                        return;
                    }
                    subEl.innerHTML = '<option value="" disabled selected>Select subject</option>';
                    irrFetchedSubjects.forEach((subj, i) => {
                        const opt = document.createElement('option');
                        opt.value = String(i);
                        opt.textContent = subj.label || ((subj.code || '') + ' - ' + (subj.name || ''));
                        subEl.appendChild(opt);
                    });
                })
                .catch(() => {
                    subEl.innerHTML = '<option value="" disabled selected>Connection error — try again</option>';
                });
        }

        function addIrregularSubject() {
            const y = String(document.getElementById('irr-year').value || '').trim();
            const s = String(document.getElementById('irr-section').value || '').trim().toUpperCase();
            const subEl = document.getElementById('irr-subject');
            if (!/^[1-6]$/.test(y) || !/^[1-6][A-Z]+$/.test(s) || s[0] !== y) {
                irrShowError('Pick a valid year level and section first.');
                return;
            }
            const idx = subEl.value;
            if (idx === '' || !irrFetchedSubjects[idx]) {
                irrShowError('Please select a subject from the dropdown first.');
                return;
            }
            const subj = irrFetchedSubjects[idx];
            const arr = getActiveIrregularArray();
            const dupe = arr.some(e => e.year === y && e.section === s &&
                String(e.code).toLowerCase() === String(subj.code).toLowerCase());
            if (dupe) {
                irrShowError('That subject is already in your list.');
                return;
            }
            arr.push({
                year: y,
                section: s,
                code: subj.code,
                name: subj.name
            });
            subEl.value = '';
            renderIrregularList();
            syncIrregularHidden();
            renderIrregularSummary();
        }

        function removeIrregularSubject(i) {
            const arr = getActiveIrregularArray();
            arr.splice(i, 1);
            renderIrregularList();
            syncIrregularHidden();
            renderIrregularSummary();
        }

        function renderIrregularList() {
            const box = document.getElementById('irr-list');
            if (!box) return;
            box.innerHTML = '';
            const arr = getActiveIrregularArray();
            if (!arr.length) {
                box.innerHTML = '<small class="text-muted">No subjects added yet.</small>';
                return;
            }
            const ul = document.createElement('ul');
            ul.className = 'list-group';
            arr.forEach((e, i) => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                const left = document.createElement('span');
                left.innerHTML = '<strong>' + escHtml(e.code) + '</strong> — ' + escHtml(e.name) +
                    ' <small class="text-muted">(' + escHtml(e.section) + ')</small>';
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-danger';
                btn.innerHTML = '<i class="fas fa-times"></i>';
                btn.setAttribute('aria-label', 'Remove subject');
                btn.onclick = (function(idx) {
                    return function() {
                        removeIrregularSubject(idx);
                    };
                })(i);
                li.appendChild(left);
                li.appendChild(btn);
                ul.appendChild(li);
            });
            box.appendChild(ul);
        }

        function syncIrregularHidden() {
            const mainEl = document.getElementById('irregular_subjects_json');
            const editEl = document.getElementById('edit-irregular_subjects_json');
            if (mainEl) mainEl.value = JSON.stringify(irregularSubjects);
            if (editEl) editEl.value = JSON.stringify(editIrregularSubjects);
        }

        function renderIrregularSummary() {
            // Main form summary
            (function() {
                const box = document.getElementById('irregular-summary');
                const cb = document.getElementById('is_irregular');
                if (!box || !cb) return;
                box.innerHTML = '';
                if (!cb.checked) {
                    box.style.display = 'none';
                    return;
                }
                box.style.display = 'block';
                if (!irregularSubjects.length) {
                    box.innerHTML = '<small class="text-muted">No subjects added yet. </small>';
                } else {
                    irregularSubjects.forEach(e => {
                        const chip = document.createElement('span');
                        chip.className = 'badge bg-success me-1 mb-1';
                        chip.style.fontSize = '0.75rem';
                        chip.textContent = e.section + ': ' + e.code + ' — ' + e.name;
                        box.appendChild(chip);
                    });
                    box.appendChild(document.createElement('br'));
                }
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.id = 'manage-irregular-btn';
                btn.className = 'btn btn-outline-primary btn-sm mt-1';
                btn.innerHTML = '<i class="fas fa-cog me-1"></i>Manage subjects';
                btn.onclick = function() {
                    activeIrregularContext = 'main';
                    openIrregularModal();
                };
                box.appendChild(btn);
            })();
            // Edit modal summary
            (function() {
                const box = document.getElementById('edit-irregular-summary');
                const cb = document.getElementById('edit-is_irregular');
                if (!box || !cb) return;
                box.innerHTML = '';
                if (!cb.checked) {
                    box.style.display = 'none';
                    return;
                }
                box.style.display = 'block';
                if (!editIrregularSubjects.length) {
                    box.innerHTML = '<small class="text-muted">No subjects added yet. </small>';
                } else {
                    editIrregularSubjects.forEach(e => {
                        const chip = document.createElement('span');
                        chip.className = 'badge bg-success me-1 mb-1';
                        chip.style.fontSize = '0.75rem';
                        chip.textContent = e.section + ': ' + e.code + ' — ' + e.name;
                        box.appendChild(chip);
                    });
                    box.appendChild(document.createElement('br'));
                }
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.id = 'manage-edit-irregular-btn';
                btn.className = 'btn btn-outline-primary btn-sm mt-1';
                btn.innerHTML = '<i class="fas fa-cog me-1"></i>Manage subjects';
                btn.onclick = function() {
                    activeIrregularContext = 'edit';
                    openEditIrregularModal();
                };
                box.appendChild(btn);
            })();
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Same year-prefix lock + guards as the main section field
            syncSectionFromYear('irr-year', 'irr-section');
            const irrSec = document.getElementById('irr-section');
            const irrYear = document.getElementById('irr-year');
            if (irrSec) {
                irrSec.style.textTransform = 'uppercase';
                irrSec.addEventListener('keydown', function(e) {
                    if (e.key === ' ') e.preventDefault();
                });
                irrSec.addEventListener('input', function() {
                    if (this.value.includes(' ')) {
                        const pos = this.selectionStart;
                        this.value = this.value.replace(/\s+/g, '');
                        try {
                            this.setSelectionRange(pos - 1, pos - 1);
                        } catch (e2) {}
                    }
                    enforceSectionPrefix({
                        target: this
                    });
                    queueIrregularReload();
                });
                irrSec.addEventListener('keydown', guardSectionBackspace);
                irrSec.addEventListener('cut', function(e) {
                    const prefix = this.dataset.prefix || '';
                    if (prefix && this.selectionStart < prefix.length) e.preventDefault();
                });
                irrSec.addEventListener('paste', function() {
                    const el = this;
                    setTimeout(() => {
                        el.value = el.value.replace(/\s+/g, '');
                        enforceSectionPrefix({
                            target: el
                        });
                        loadIrregularSubjects();
                    }, 0);
                });
                irrSec.addEventListener('beforeinput', function(e) {
                    if (e.data === ' ') e.preventDefault();
                });
            }
            if (irrYear) irrYear.addEventListener('change', function() {
                syncSectionFromYear('irr-year', 'irr-section');
                loadIrregularSubjects();
            });
            const mainYear = document.getElementById('year');
            if (mainYear) mainYear.addEventListener('change', function() {
                // Refresh subject list against the newly selected course
                if (document.getElementById('is_irregular').checked) {
                    activeIrregularContext = 'main';
                    loadIrregularSubjects();
                }
            });
            const mainCourse = document.getElementById('course');
            if (mainCourse) mainCourse.addEventListener('change', function() {
                if (document.getElementById('is_irregular').checked) {
                    activeIrregularContext = 'main';
                    loadIrregularSubjects();
                }
            });
            const editYear = document.getElementById('edit-year');
            if (editYear) editYear.addEventListener('change', function() {
                if (document.getElementById('edit-is_irregular') && document.getElementById('edit-is_irregular').checked) {
                    activeIrregularContext = 'edit';
                    loadIrregularSubjects();
                }
            });
            const editCourse = document.getElementById('edit-course');
            if (editCourse) editCourse.addEventListener('change', function() {
                if (document.getElementById('edit-is_irregular') && document.getElementById('edit-is_irregular').checked) {
                    activeIrregularContext = 'edit';
                    loadIrregularSubjects();
                }
            });
            renderIrregularList();
            renderIrregularSummary();
        });
    </script>
</body>

</html>