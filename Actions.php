<?php

// Suppress warnings and errors to prevent JSON corruption
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters for cross-domain access
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // Allow all domains
        'secure' => false, // Allow HTTP (ngrok)
        'httponly' => true,
        'samesite' => 'Lax' // Allow cross-site requests
    ]);
    session_start();
}
require_once('DBConnection.php');

Class Actions extends DBConnection{
    private $allowedMimeTypes = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        'application/msword' // .doc
    ];

    private $maxFileSize = 5 * 1024 * 1024; // 5MB

    function __construct(){
        parent::__construct();
        // Ensure we have a valid database connection
        if (!($this instanceof SQLite3)) {
            throw new Exception('Database connection failed');
        }
    }
    function __destruct(){
        parent::__destruct();
    }

    function handle_template_upload($file, $uploadDir = 'uploads/templates/') {
        // Create upload directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        // Validate file
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new Exception('Invalid parameters');
        }

        // Check for upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new Exception('No file was uploaded');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception('Exceeded file size limit');
            default:
                throw new Exception('Unknown upload error');
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            throw new Exception('File is too large. Maximum size is ' . ($this->maxFileSize / 1024 / 1024) . 'MB');
        }

        // Check MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!in_array($mime, $this->allowedMimeTypes)) {
            throw new Exception('Invalid file type. Only Word documents (.docx, .doc) are allowed');
        }

        // Generate a unique filename
        $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid('template_') . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;

        // Move the uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Failed to move uploaded file');
        }

        return [
            'file_name' => $file['name'],
            'file_path' => $filePath,
            'file_type' => $mime,
            'file_size' => $file['size']
        ];
    }

    function upload_template() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_FILES['template_file']) || !isset($_POST['name']) || !isset($_POST['template_type'])) {
                throw new Exception('Missing required fields');
            }

            // Handle file upload
            $uploadInfo = $this->handle_template_upload($_FILES['template_file']);

            // Save to database
            $sql = "INSERT INTO document_templates (
                name,
                description,
                file_name,
                file_path,
                file_type,
                file_size,
                template_type,
                uploaded_by
            ) VALUES (
                :name,
                :description,
                :file_name,
                :file_path,
                :file_type,
                :file_size,
                :template_type,
                :uploaded_by
            )";

            $stmt = $this->prepare($sql);
            $stmt->bindValue(':name', $_POST['name'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $_POST['description'] ?? null, SQLITE3_TEXT);
            $stmt->bindValue(':file_name', $uploadInfo['file_name'], SQLITE3_TEXT);
            $stmt->bindValue(':file_path', $uploadInfo['file_path'], SQLITE3_TEXT);
            $stmt->bindValue(':file_type', $uploadInfo['file_type'], SQLITE3_TEXT);
            $stmt->bindValue(':file_size', $uploadInfo['file_size'], SQLITE3_INTEGER);
            $stmt->bindValue(':template_type', $_POST['template_type'], SQLITE3_TEXT);
            $stmt->bindValue(':uploaded_by', $_SESSION['admin_id'] ?? null, SQLITE3_INTEGER);

            if ($stmt->execute()) {
                $response = [
                    'status' => 'success',
                    'msg' => 'Template uploaded successfully',
                    'template_id' => $this->lastInsertRowID()
                ];
            } else {
                // If database insert fails, delete the uploaded file
                if (file_exists($uploadInfo['file_path'])) {
                    @unlink($uploadInfo['file_path']);
                }
                throw new Exception('Failed to save template to database');
            }

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    function get_component_suggested_percentage() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['class_id']) || !isset($_POST['component_id']) || !isset($_POST['quarter'])) {
                throw new Exception('Missing required parameters');
            }

            $class_id = $this->escapeString($_POST['class_id']);
            $component_id = $this->escapeString($_POST['component_id']);
            $quarter = $this->escapeString($_POST['quarter']);

            // Count existing assessments for this class, component, and quarter
            $count = $this->querySingle("SELECT COUNT(assessment_id) FROM `assessment_list` WHERE class_id = '{$class_id}' AND component_id = '{$component_id}' AND quarter = '{$quarter}'");
            $count = (int)$count;

            // Suggested percentage: evenly divide 100 across existing + new assessment
            $suggested = 100;
            if ($count >= 0) {
                $suggested = 100 / ($count + 1);
            }

            $response = [
                'status' => 'success',
                'suggested' => round($suggested, 2)
            ];

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    function update_template() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['template_id']) || !isset($_POST['name']) || !isset($_POST['template_type'])) {
                throw new Exception('Missing required fields');
            }

            $templateId = (int)$_POST['template_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $templateType = $_POST['template_type'];

            // Get current template info
            $current = $this->querySingle("SELECT * FROM document_templates WHERE template_id = $templateId", true);
            if (!$current) {
                throw new Exception('Template not found');
            }

            // Update template in database
            $sql = "UPDATE document_templates SET
                    name = :name,
                    description = :description,
                    template_type = :template_type
                    WHERE template_id = :template_id";

            $stmt = $this->prepare($sql);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':description', $description, SQLITE3_TEXT);
            $stmt->bindValue(':template_type', $templateType, SQLITE3_TEXT);
            $stmt->bindValue(':template_id', $templateId, SQLITE3_INTEGER);

            if ($stmt->execute()) {
                $response = [
                    'status' => 'success',
                    'msg' => 'Template updated successfully'
                ];
            } else {
                throw new Exception('Failed to update template');
            }

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    function delete_template() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['template_id'])) {
                throw new Exception('Template ID is required');
            }

            $templateId = (int)$_POST['template_id'];

            // Get template info before deleting
            $template = $this->querySingle("SELECT * FROM document_templates WHERE template_id = $templateId", true);

            if (!$template) {
                throw new Exception('Template not found');
            }

            // Delete the file
            if (file_exists($template['file_path'])) {
                if (!unlink($template['file_path'])) {
                    throw new Exception('Failed to delete template file');
                }
            }

            // Delete from database
            $sql = "DELETE FROM document_templates WHERE template_id = :template_id";
            $stmt = $this->prepare($sql);
            $stmt->bindValue(':template_id', $templateId, SQLITE3_INTEGER);

            if ($stmt->execute()) {
                $response = [
                    'status' => 'success',
                    'msg' => 'Template deleted successfully'
                ];
            } else {
                throw new Exception('Failed to delete template from database');
            }

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    function get_template() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_GET['id'])) {
                throw new Exception('Template ID is required');
            }

            $templateId = (int)$_GET['id'];
            $template = $this->querySingle("SELECT * FROM document_templates WHERE template_id = $templateId", true);

            if ($template) {
                $response = [
                    'status' => 'success',
                    'data' => $template
                ];
            } else {
                throw new Exception('Template not found');
            }

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    function download_template() {
        try {
            if (!isset($_GET['id'])) {
                throw new Exception('Template ID is required');
            }

            $templateId = (int)$_GET['id'];
            $template = $this->querySingle("SELECT * FROM document_templates WHERE template_id = $templateId", true);

            if (!$template) {
                throw new Exception('Template not found');
            }

            $filePath = $template['file_path'];
            $fileName = $template['file_name'];

            if (!file_exists($filePath)) {
                throw new Exception('Template file not found');
            }

            // Set headers for file download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));

            // Clear output buffer
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Read the file and output it to the browser
            readfile($filePath);
            exit;

        } catch (Exception $e) {
            // If headers were already sent, we can't send JSON
            if (!headers_sent()) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
            } else {
                die('Error: ' . $e->getMessage());
            }
        }
    }

    function use_template() {
        // This function would be used to apply a template to a report
        // Implementation depends on how you want to use the template
        // For now, we'll just redirect to the reports page with the template ID
        if (isset($_GET['id'])) {
            $_SESSION['selected_template_id'] = (int)$_GET['id'];
            header('Location: reports.php');
            exit;
        } else {
            header('Location: reports.php');
            exit;
        }
    }

    function save_signatory(){
        extract($_POST);

        // Validate input
        if(empty($designation) || empty($type)){
            return json_encode(['status' => 'failed', 'msg' => 'All fields are required.']);
        }

        // Validate type
        $validTypes = ['certified', 'checked', 'noted', 'received'];
        if(!in_array($type, $validTypes)) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid signatory type.']);
        }

        // Check if a signatory of this type already exists
        $existing = $this->query("SELECT signatory_id FROM `signatories` WHERE `type` = '" . $this->escapeString($type) . "'")->fetchArray();

        try {
            if($existing) {
                // Update existing signatory
                $sql = "UPDATE `signatories` SET
                        `fullname` = '" . $this->escapeString($fullname) . "',
                        `designation` = '" . $this->escapeString($designation) . "',
                        `date_created` = CURRENT_TIMESTAMP
                        WHERE `type` = '" . $this->escapeString($type) . "'";
            } else {
                // Insert new signatory
                $sql = "INSERT INTO `signatories`
                        (`fullname`, `designation`, `type`)
                        VALUES (
                            '" . $this->escapeString($fullname) . "',
                            '" . $this->escapeString($designation) . "',
                            '" . $this->escapeString($type) . "'
                        )";
            }

            $save = $this->exec($sql);

            if($save) {
                return json_encode(['status' => 'success']);
            } else {
                return json_encode(['status' => 'failed', 'msg' => 'Failed to save signatory.']);
            }
        } catch(Exception $e) {
            // Log error for debugging
            error_log('Error saving signatory: ' . $e->getMessage());
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while saving.']);
        }
    }
    function login(){
        extract($_POST);
        $sql = "SELECT * FROM admin_list where username = '{$username}' and `password` = '".md5($password)."' ";
        @$qry = $this->query($sql)->fetchArray();
        if(!$qry){
            $resp['status'] = "failed";
            $resp['msg'] = "Invalid username or password.";
        } else {
            $resp['status'] = "success";
            $resp['msg'] = "Login successfully.";

            // Preserve manually switched database state before clearing session
            $preserveDbSwitch = false;
            $preserveDbFile = null;
            $preserveDbSwitchTime = null;

            error_log("Actions Debug - Session before unset: " . print_r($_SESSION, true));

            if (isset($_SESSION['db_manually_switched']) && $_SESSION['db_manually_switched'] === true &&
                isset($_SESSION['db_switch_time']) && (time() - $_SESSION['db_switch_time']) < 3600) {
                $preserveDbSwitch = true;
                $preserveDbFile = $_SESSION['db_file'] ?? null;
                $preserveDbSwitchTime = $_SESSION['db_switch_time'];
                error_log("Actions Debug - Preserving manual switch: " . $preserveDbFile);
            }

            // Clear any existing session data
            session_unset();

            // Set session variables
            foreach($qry as $k => $v){
                if(!is_numeric($k)) {
                    $_SESSION[$k] = $v;
                }
            }

            // Restore manually switched database state if it was recent
            if ($preserveDbSwitch && $preserveDbFile) {
                $_SESSION['db_file'] = $preserveDbFile;
                $_SESSION['db_manually_switched'] = true;
                $_SESSION['db_switch_time'] = $preserveDbSwitchTime;
                error_log("Actions Debug - Restored manual switch: " . $preserveDbFile);
            }

            error_log("Actions Debug - Session after restore: " . print_r($_SESSION, true));

            // Only set db_file for faculty members with valid database paths
            // But don't override if database was manually switched recently
            if(isset($qry['role']) && $qry['role'] === 'faculty') {
                // Check if database was manually switched recently (within last hour)
                $dbManuallySwitched = isset($_SESSION['db_manually_switched']) && $_SESSION['db_manually_switched'] === true &&
                                    isset($_SESSION['db_switch_time']) && (time() - $_SESSION['db_switch_time']) < 3600;

                if (!$dbManuallySwitched) {
                    // Only set default database if not manually switched
                    if(!empty($qry['db_path']) && file_exists($qry['db_path'])) {
                        $_SESSION['db_file'] = $qry['db_path'];
                        error_log("Actions Debug - Setting default faculty database: " . $qry['db_path']);
                    } else {
                        // Auto-provision database if not found
                        $provisionResult = $this->provision_database_with_year_auto($qry['admin_id'], $qry['username']);
                        if($provisionResult['status'] === 'success') {
                            $_SESSION['db_file'] = $provisionResult['path'];
                            error_log("Actions Debug - Auto-provisioned faculty database: " . $provisionResult['path']);
                        } else {
                            $resp['status'] = "failed";
                            $resp['msg'] = "Faculty database not found and auto-provision failed: " . $provisionResult['msg'];
        return json_encode($resp);
    }

    // Validate "Keep me logged in" cookies and restore the session.
    // Returns the admin row on success, false otherwise.
    function remember_login(){
        if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
            return false;
        }
        if (!isset($_COOKIE['hgs_remember_user'], $_COOKIE['hgs_remember_token'])) {
            return false;
        }
        $rm_uid = (int)$_COOKIE['hgs_remember_user'];
        $rm_token = (string)$_COOKIE['hgs_remember_token'];
        if ($rm_uid <= 0 || !preg_match('/^[a-f0-9]{64}$/', $rm_token)) {
            return false;
        }
        $rm_hash = hash('sha256', $rm_token);
        $row = @$this->query("SELECT * FROM admin_list WHERE admin_id = '{$rm_uid}'")->fetchArray(SQLITE3_ASSOC);
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            return false;
        }
        if (empty($row['remember_token']) || !hash_equals((string)$row['remember_token'], $rm_hash)) {
            return false;
        }
        if (empty($row['remember_expiry']) || strtotime($row['remember_expiry']) <= time()) {
            return false;
        }
        foreach ($row as $k => $v) {
            if (!is_numeric($k)) {
                $_SESSION[$k] = $v;
            }
        }
        unset($_SESSION['remember_token'], $_SESSION['remember_expiry']);
        if (isset($row['role']) && $row['role'] === 'faculty' && !empty($row['db_path']) && file_exists($row['db_path'])) {
            $_SESSION['db_file'] = $row['db_path'];
        }
        // Rotate the token on each auto-login
        $new_token = bin2hex(random_bytes(32));
        $new_expiry = date('Y-m-d H:i:s', time() + 30 * 86400);
        @$this->exec("UPDATE admin_list SET remember_token = '" . hash('sha256', $new_token) . "', remember_expiry = '{$new_expiry}', last_activity = CURRENT_TIMESTAMP WHERE admin_id = '{$rm_uid}'");
        $cookie_expiry = time() + 30 * 86400;
        setcookie('hgs_remember_user', (string)$rm_uid, ['expires' => $cookie_expiry, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('hgs_remember_token', $new_token, ['expires' => $cookie_expiry, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        return $row;
    }
                    }
                } else {
                    error_log("Actions Debug - Keeping manually switched database, not overriding with default");
                }
                // If manually switched, keep the existing db_file from session
            } else {
                // For admin users, make sure db_file is not set (unless manually switched)
                $dbManuallySwitched = isset($_SESSION['db_manually_switched']) && $_SESSION['db_manually_switched'] === true &&
                                    isset($_SESSION['db_switch_time']) && (time() - $_SESSION['db_switch_time']) < 3600;

                if (!$dbManuallySwitched) {
                    unset($_SESSION['db_file']);
                }
            }

            // Update last_activity timestamp for the logged-in user using main database
            $main_db_path = __DIR__ . '/db/grading_db.db';
            $main_conn = new SQLite3($main_db_path);
            $main_conn->enableExceptions(true);
            $main_conn->exec("PRAGMA busy_timeout = 5000");
            $main_conn->exec("PRAGMA journal_mode = WAL");
            $main_conn->exec("UPDATE admin_list SET last_activity = CURRENT_TIMESTAMP WHERE admin_id = '{$qry['admin_id']}'");
            $main_conn->close();

            // "Keep me logged in" persistent login (30 days). The checkbox now
            // submits as rememberMe=1; without it any stale token is cleared.
            $remember_days = 30;
            $login_admin_id = (int)$qry['admin_id'];
            if (!empty($rememberMe)) {
                $remember_token = bin2hex(random_bytes(32));
                $remember_hash = hash('sha256', $remember_token);
                $remember_expiry = date('Y-m-d H:i:s', time() + $remember_days * 86400);
                @$this->exec("UPDATE admin_list SET remember_token = '{$remember_hash}', remember_expiry = '{$remember_expiry}' WHERE admin_id = '{$login_admin_id}'");
                $cookie_expiry = time() + $remember_days * 86400;
                setcookie('hgs_remember_user', (string)$login_admin_id, ['expires' => $cookie_expiry, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
                setcookie('hgs_remember_token', $remember_token, ['expires' => $cookie_expiry, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
            } else {
                @$this->exec("UPDATE admin_list SET remember_token = NULL, remember_expiry = NULL WHERE admin_id = '{$login_admin_id}'");
                setcookie('hgs_remember_user', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
                setcookie('hgs_remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
            }
        }
        return json_encode($resp);
    }

    function update_activity(){
        if(isset($_SESSION['admin_id'])){
            // Create separate connection to main database for user management
            $main_db_path = __DIR__ . '/db/grading_db.db';
            $main_conn = new SQLite3($main_db_path);
            $main_conn->enableExceptions(true);
            $main_conn->exec("PRAGMA busy_timeout = 5000");
            $main_conn->exec("PRAGMA journal_mode = WAL");
            // Use localtime so the value matches PHP time() / strtotime() on the
            // faculty page (which runs in Asia/Manila). CURRENT_TIMESTAMP is UTC
            // and would make `time() - strtotime(last_activity)` appear 8h stale.
            $main_conn->exec("UPDATE admin_list SET last_activity = datetime('now','localtime') WHERE admin_id = '{$_SESSION['admin_id']}'");
            $main_conn->close();
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed']);
    }
    function update_student_status(){
        // Set content type header to ensure JSON response
        header('Content-Type: application/json');

        // Immediate debugging output
        error_log("update_student_status method called");

        $resp = ['status' => 'failed', 'msg' => 'Method started'];

        try {
            error_log("update_student_status - POST data: " . print_r($_POST, true));

            if (!isset($_POST['id']) || empty($_POST['id'])) {
                throw new Exception('Student ID is required');
            }
            if (!isset($_POST['status'])) {
                throw new Exception('Status is required');
            }

            $student_id = (int)$_POST['id'];
            $status = trim($_POST['status']);
            $quarter = isset($_POST['quarter']) ? (int)$_POST['quarter'] : null;

            error_log("update_student_status - quarter: " . ($quarter !== null ? $quarter : 'NULL') . " (isset: " . (isset($_POST['quarter']) ? 'yes' : 'no') . ")");

            if (!in_array($status, ['Active', 'Dropped','NoSub','NoExam'])) {
                throw new Exception('Invalid status value. Must be either "Active", "Dropped", "NoSub", or "NoExam"');
            }

            if ($quarter !== null && ($quarter < 1 || $quarter > 4)) {
                throw new Exception('Invalid quarter value. Must be between 1 and 4');
            }

            // Check if status_quarter column exists before trying to use it
            $columns = $this->query("PRAGMA table_info(student_list)");
            $hasStatusQuarter = false;
            $hasStatus = false;

            while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
                if ($col['name'] === 'status_quarter') {
                    $hasStatusQuarter = true;
                }
                if ($col['name'] === 'status') {
                    $hasStatus = true;
                }
            }

            if (!$hasStatus) {
                throw new Exception('Status column does not exist in student_list table');
            }

            error_log("Columns check - Status: " . ($hasStatus ? 'exists' : 'missing') . ", StatusQuarter: " . ($hasStatusQuarter ? 'exists' : 'missing'));

            // Start transaction
            $this->exec('BEGIN TRANSACTION');

            try {
                // Update student status with quarter information
                $update_fields = "`status` = :status";
                $params = [
                    ':status' => $status,
                    ':student_id' => $student_id
                ];

                // Only use status_quarter if the column exists
                if ($hasStatusQuarter) {
                    if ($quarter !== null && ($status === 'NoSub' || $status === 'NoExam')) {
                        $update_fields .= ", `status_quarter` = :quarter";
                        $params[':quarter'] = $quarter;
                    } else {
                        // For Active/Dropped status, clear the quarter field
                        $update_fields .= ", `status_quarter` = NULL";
                    }
                }

                $stmt = $this->prepare("UPDATE `student_list` SET {$update_fields} WHERE `student_id` = :student_id");
                if ($stmt === false) {
                    throw new Exception('Failed to prepare status update: ' . $this->lastErrorMsg());
                }

                foreach ($params as $key => $value) {
                    $param_type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
                    $stmt->bindValue($key, $value, $param_type);
                }

                $result = $stmt->execute();

                if (!$result) {
                    throw new Exception('Failed to update student status. Error: ' . $this->lastErrorMsg());
                }

                $resp['msg'] = 'Student status updated successfully';

                // Commit transaction if everything succeeded
                $this->exec('COMMIT');
                $resp['status'] = 'success';

            } catch (Exception $e) {
                // Rollback transaction on error
                $this->exec('ROLLBACK');
                error_log("update_student_status error: " . $e->getMessage());
                throw $e; // Re-throw to be caught by outer try-catch
            }
        } catch (Exception $e) {
            $resp['msg'] = $e->getMessage();
            error_log("update_student_status final error: " . $e->getMessage());
        }

        error_log("update_student_status response: " . json_encode($resp));

        // Ensure we always return JSON
        echo json_encode($resp);
        exit;
    }
    function customer_login(){
        return $this->login();
    }
    function logout(){
        // Clear last_activity timestamp for the logged-out user
        if(isset($_SESSION['admin_id'])){
            // Create separate connection to main database for user management
            $main_db_path = __DIR__ . '/db/grading_db.db';
            $main_conn = new SQLite3($main_db_path);
            $main_conn->enableExceptions(true);
            $main_conn->exec("PRAGMA busy_timeout = 5000");
            $main_conn->exec("PRAGMA journal_mode = WAL");
            $main_conn->exec("UPDATE admin_list SET last_activity = NULL WHERE admin_id = '{$_SESSION['admin_id']}'");
            $main_conn->close();
            // Revoke persistent login token and drop remember-me cookies
            $logout_admin_id = (int)$_SESSION['admin_id'];
            @$this->exec("UPDATE admin_list SET remember_token = NULL, remember_expiry = NULL WHERE admin_id = '{$logout_admin_id}'");
        }
        setcookie('hgs_remember_user', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('hgs_remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);

        session_destroy();

        // Create universal URL for both local and hosted environments
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base_path = dirname($_SERVER['PHP_SELF']);

        // Remove trailing slash if present
        $base_path = rtrim($base_path, '/');

        // Construct base URL
        $base_url = $protocol . '://' . $host . $base_path;

        // If we're in root directory, redirect to login.php
        if (empty($base_path) || $base_path === '/') {
            header("location: " . $base_url . "/login.php");
        } else {
            // If we're in subdirectory, redirect to login.php in that subdirectory
            header("location: " . $base_url . "/login.php");
        }
        exit;
    }
    function customer_logout(){
        return $this->logout();
    }
    function save_user(){
        extract($_POST);

        if(isset($_POST['role']) && !in_array($_POST['role'], ['admin','faculty'])){
            $_POST['role'] = 'admin';
        }

        if(isset($_POST['allowed_pages'])){
            if(is_array($_POST['allowed_pages'])){
                $_POST['allowed_pages'] = json_encode(array_values(array_unique($_POST['allowed_pages'])));
            }else{
                $decoded = json_decode($_POST['allowed_pages'], true);
                if(is_array($decoded)){
                    $_POST['allowed_pages'] = json_encode(array_values(array_unique($decoded)));
                }
            }
        }

        // Create separate connection to main database for user management
        $main_db_path = __DIR__ . '/db/grading_db.db';
        $main_conn = new SQLite3($main_db_path);
        $main_conn->enableExceptions(true);
        $main_conn->exec("PRAGMA busy_timeout = 5000");
        $main_conn->exec("PRAGMA journal_mode = WAL");

        $cols = [];
        $values = [];
        $updates = [];
        $hasPasswordField = false;
        $skip_keys = ['id','type'];

        foreach($_POST as $k => $v){
            if(in_array($k,$skip_keys)) continue;

            if($k == 'password'){
                $v = trim($v ?? '');
                if(!empty($id) && $v === ''){
                    continue;
                }
                if($v === ''){
                    continue;
                }
                $v = md5($v);
                $hasPasswordField = true;
            }

            $escaped = $main_conn->escapeString($v);

            if(!empty($id)){
                $updates[] = " `{$k}` = '{$escaped}' ";
            }else{
                $cols[] = "`{$k}`";
                $values[] = "'{$escaped}'";
                if($k == 'password') $hasPasswordField = true;
            }
        }

        if(empty($id)){
            if(!$hasPasswordField){
                $cols[] = "`password`";
                $values[] = "'".md5($main_conn->escapeString($username))."'";
            }
            if(!empty($cols) && !empty($values)){
                $data = "(".implode(',',$cols).") VALUES (".implode(',',$values).")";
            }else{
                $resp['status'] = 'failed';
                $resp['msg'] = 'No data to save.';
                $main_conn->close();
                return json_encode($resp);
            }
        }else{
            if(empty($updates)){
                $resp['status'] = 'success';
                $resp['msg'] = 'No changes detected.';
                $main_conn->close();
                return json_encode($resp);
            }
            $data = implode(',', $updates);
        }

        @$check= $main_conn->query("SELECT count(admin_id) as `count` FROM admin_list where `username` = '{$main_conn->escapeString($username)}' ".($id > 0 ? " and admin_id != '{$id}' " : ""))->fetchArray()['count'];
        if(@$check> 0){
            $resp['status'] = 'failed';
            $resp['msg'] = "Username already exists.";
        }else{
            if(empty($id)){
                $sql = "INSERT INTO `admin_list` {$data}";
            }else{
                $sql = "UPDATE `admin_list` set {$data} where admin_id = '{$id}'";
            }
            @$save = $main_conn->query($sql);
            if($save){
                $resp['status'] = 'success';
                if(empty($id))
                $resp['msg'] = 'New Admin User successfully saved.';
                else
                $resp['msg'] = 'Admin User Details successfully updated.';

                if(empty($id) && isset($_POST['role']) && $_POST['role'] === 'faculty'){
                    // Don't create database here anymore - will be handled by provision_database_with_year
                    $resp['msg'] .= ' Faculty folder and database will be created separately.';
                }
            }else{
                $resp['status'] = 'failed';
                $resp['msg'] = 'Saving Admin User Details Failed. Error: '.$main_conn->lastErrorMsg();
                $resp['sql'] =$sql;
            }
        }

        $main_conn->close();
        return json_encode($resp);
    }
    function delete_user(){
        extract($_POST);

        // Create separate connection to main database for user management
        $main_db_path = __DIR__ . '/db/grading_db.db';
        $main_conn = new SQLite3($main_db_path);
        $main_conn->enableExceptions(true);
        $main_conn->exec("PRAGMA busy_timeout = 5000");
        $main_conn->exec("PRAGMA journal_mode = WAL");

        // Get faculty data before deletion for backup purposes
        $faculty = $main_conn->query("SELECT admin_id, fullname, username, role, db_path FROM admin_list WHERE rowid = '{$id}'")->fetchArray(SQLITE3_ASSOC);

        // Backup faculty database if this is a faculty member with a database
        if($faculty && $faculty['role'] === 'faculty' && !empty($faculty['db_path']) && file_exists($faculty['db_path'])){
            $backupResult = $this->backup_faculty_database($faculty);
            if($backupResult['status'] === 'failed'){
                $main_conn->close();
                return json_encode(['status' => 'failed', 'msg' => 'Failed to backup faculty database: ' . $backupResult['msg']]);
            }
        }

        // Note: faculty table insertion removed as table doesn't exist in schema
        // If needed, create faculty table first or remove this functionality

        @$delete = $main_conn->query("DELETE FROM `admin_list` where rowid = '{$id}'");
        if($delete){
            $resp['status']='success';
            $_SESSION['flashdata']['type'] = 'success';

            // Customize message based on whether database was backed up
            if($faculty && $faculty['role'] === 'faculty' && !empty($faculty['db_path']) && file_exists($faculty['db_path'])){
                $_SESSION['flashdata']['msg'] = 'Admin User successfully deleted. Faculty database has been backed up to previous_database directory.';
            } else {
                $_SESSION['flashdata']['msg'] = 'Admin User successfully deleted.';
            }
        }else{
            $resp['status']='failed';
            $resp['error']=$main_conn->lastErrorMsg();
        }

        $main_conn->close();
        return json_encode($resp);
    }

    function backup_faculty_database($faculty){
        // Ensure previous_database directory exists
        $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'previous_database';
        if(!is_dir($backupDir)){
            if(!mkdir($backupDir, 0777, true)){
                return ['status' => 'failed', 'msg' => 'Unable to create backup directory.'];
            }
        }

        // Create a descriptive backup filename
        $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($faculty['username']));
        $safeFullname = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($faculty['fullname']));
        $timestamp = date('Y-m-d_H-i-s');

        $backupFilename = "{$safeUsername}_{$safeFullname}_{$timestamp}.db";
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Copy the database file to backup location
        if(!copy($faculty['db_path'], $backupPath)){
            return ['status' => 'failed', 'msg' => 'Failed to copy database file.'];
        }

        // Create a metadata file with faculty information
        $metadataPath = $backupDir . DIRECTORY_SEPARATOR . "{$backupFilename}.meta.json";
        $metadata = [
            'faculty_id' => $faculty['admin_id'],
            'username' => $faculty['username'],
            'fullname' => $faculty['fullname'],
            'role' => $faculty['role'],
            'original_db_path' => $faculty['db_path'],
            'backup_date' => date('Y-m-d H:i:s'),
            'backup_filename' => $backupFilename
        ];

        if(file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT)) === false){
            // Metadata creation failed but backup was successful, log warning
            error_log("Warning: Failed to create metadata file for faculty database backup: {$metadataPath}");
        }

        // Delete the original faculty database after successful backup
        if(unlink($faculty['db_path'])){
            error_log("Original faculty database deleted: {$faculty['db_path']}");

            // Also remove the faculty folder if it's empty
            $facultyFolder = dirname($faculty['db_path']);
            if(is_dir($facultyFolder)){
                $files = glob($facultyFolder . '/*');
                if(empty($files)){
                    if(rmdir($facultyFolder)){
                        error_log("Faculty folder removed: {$facultyFolder}");
                    } else {
                        error_log("Warning: Failed to remove faculty folder: {$facultyFolder}");
                    }
                } else {
                    error_log("Faculty folder not empty, keeping: {$facultyFolder}");
                }
            }
        } else {
            error_log("Warning: Failed to delete original faculty database: {$faculty['db_path']}");
        }

        return ['status' => 'success', 'msg' => 'Database backed up and original deleted.', 'backup_path' => $backupPath];
    }

    function update_credentials(){
        extract($_POST);
        $data = "";

        // Validate old password only if provided (leave blank to keep current)
        if(!empty($old_password)){
            if(md5($old_password) != $_SESSION['password']){
                $resp['status'] = 'failed';
                $resp['msg'] = "Current password is incorrect.";
                return json_encode($resp);
            }
        }

        // Handle file upload
        $uploadDir = 'image/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Process file upload if exists
        if(isset($_FILES['user_image']) && $_FILES['user_image']['error'] == 0) {
            $file = $_FILES['user_image'];
            $fileName = uniqid() . '_' . basename($file['name']);
            $targetPath = $uploadDir . $fileName;

            // Check file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if(in_array($file['type'], $allowedTypes)) {
                if(move_uploaded_file($file['tmp_name'], $targetPath)) {
                    if(!empty($data)) $data .= ",";
                    $data .= " `image` = '{$targetPath}' ";
                    // Update session image if it's the current user
                    if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] == $id) {
                        $_SESSION['image'] = $targetPath;
                    }
                }
            }
        }

        if(!empty($_POST['password']) && $_POST['password'] !== ($_POST['confirm_password'] ?? '')){
            $resp['status'] = 'failed';
            $resp['msg'] = "New password and confirmation do not match.";
            return json_encode($resp);
        }

        // Process other form fields
        foreach($_POST as $k => $v){
            if(!in_array($k,array('id','old_password','confirm_password','user_image')) && !empty($v)){
                if(!empty($data)) $data .= ",";
                if($k == 'password') $v = md5($v);
                $data .= " `{$k}` = '{$v}' ";
            }
        }

        // If no data to update, return success
        if(empty($data)){
            $resp['status'] = 'success';
            $resp['msg'] = "No changes to update.";
            return json_encode($resp);
        }

        $sql = "UPDATE `admin_list` set {$data} where admin_id = '{$_SESSION['admin_id']}'";
        @$save = $this->query($sql);
        if($save){
            $resp['status'] = 'success';
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Credential successfully updated.';

            // Update session variables for changed fields
            foreach($_POST as $k => $v){
                if(!in_array($k,array('id','old_password','confirm_password','user_image')) && !empty($v)){
                    if($k == 'password') {
                        $_SESSION[$k] = md5($v);
                    } else {
                        $_SESSION[$k] = $v;
                    }
                }
            }
        }else{
            $resp['status'] = 'failed';
            $resp['msg'] = 'Updating Credentials Failed. Error: '.$this->lastErrorMsg();
            $resp['sql'] =$sql;
        }
        return json_encode($resp);
    }
    function save_subject(){
        extract($_POST);
        if(empty($id))
            $sql = "INSERT INTO `subjects` (`name`)VALUES('{$name}')";
        else{
            $data = "";
             foreach($_POST as $k => $v){
                 if(!in_array($k,array('id'))){
                     if(!empty($data)) $data .= ", ";
                     $data .= " `{$k}` = '{$v}' ";
                 }
             }
            $sql = "UPDATE `subjects` set {$data} where `subject_id` = '{$id}' ";
        }
        @$check= $this->query("SELECT COUNT(subject_id) as count from `subjects` where `name` = '{$name}' ".($id > 0 ? " and subject_id != '{$id}'" : ""))->fetchArray()['count'];
        if(@$check> 0){
            $resp['status'] ='failed';
            $resp['msg'] = 'Subject already exists.';
        }else{
            @$save = $this->query($sql);
            if($save){
                $resp['status']="success";
                if(empty($id))
                    $resp['msg'] = "Subject successfully saved.";
                else
                    $resp['msg'] = "Subject successfully updated.";
            }else{
                $resp['status']="failed";
                if(empty($id))
                    $resp['msg'] = "Saving New Subject Failed.";
                else
                    $resp['msg'] = "Updating Subject Failed.";
                $resp['error']=$this->lastErrorMsg();
            }
        }
        return json_encode($resp);
    }
    function delete_subject(){
        extract($_POST);

        @$delete = $this->query("DELETE FROM `subjects` where subject_id = '{$id}'");
        if($delete){
            $resp['status']='success';
            $resp['msg'] = 'Subject successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }
    function save_component(){
        extract($_POST);
        if(empty($id))
            $sql = "INSERT INTO `grading_components` (`name`)VALUES('{$name}')";
        else{
            $data = "";
             foreach($_POST as $k => $v){
                 if(!in_array($k,array('id'))){
                     if(!empty($data)) $data .= ", ";
                     $data .= " `{$k}` = '{$v}' ";
                 }
             }
            $sql = "UPDATE `grading_components` set {$data} where `component_id` = '{$id}' ";
        }
        @$check= $this->query("SELECT COUNT(component_id) as count from `grading_components` where `name` = '{$name}' ".($id > 0 ? " and component_id != '{$id}'" : ""))->fetchArray()['count'];
        if(@$check> 0){
            $resp['status'] ='failed';
            $resp['msg'] = 'Component already exists.';
        }else{
            @$save = $this->query($sql);
            if($save){
                $resp['status']="success";
                if(empty($id))
                    $resp['msg'] = "Component successfully saved.";
                else
                    $resp['msg'] = "Component successfully updated.";
            }else{
                $resp['status']="failed";
                if(empty($id))
                    $resp['msg'] = "Saving New Component Failed.";
                else
                    $resp['msg'] = "Updating Component Failed.";
                $resp['error']=$this->lastErrorMsg();
            }
        }
        return json_encode($resp);
    }
    function delete_component(){
        extract($_POST);

        @$delete = $this->query("DELETE FROM `grading_components` where component_id = '{$id}'");
        if($delete){
            $resp['status']='success';
            $resp['msg'] = 'Component successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }
    function get_components(){
        $components = array();
        $query = $this->query("SELECT * FROM `grading_components` ORDER BY `name` ASC");
        if($query){
            while($row = $query->fetchArray(SQLITE3_ASSOC)){
                $components[] = $row;
            }
        }
        $resp['status'] = 'success';
        $resp['data'] = $components;
        return json_encode($resp);
    }

    function get_components_for_class(){
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['class_id'])) {
                throw new Exception('Missing class_id parameter');
            }

            $class_id = $this->escapeString($_POST['class_id']);

            // Get components that have percentage assignments for this class's subject
            // Exclude components with 0 percentage
            $sql = "SELECT gc.*
                    FROM grading_components gc
                    INNER JOIN component_subject_percentage csp ON gc.component_id = csp.component_id
                    INNER JOIN class_list cl ON csp.subject_id = cl.subject_id
                    WHERE cl.class_id = '{$class_id}'
                    AND CAST(csp.percentage AS REAL) > 0
                    ORDER BY gc.name ASC";

            $components = array();
            $query = $this->query($sql);
            if($query){
                while($row = $query->fetchArray(SQLITE3_ASSOC)){
                    $components[] = $row;
                }
            }

            $response = [
                'status' => 'success',
                'data' => $components
            ];

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }
    function get_subjects(){
        $subjects = array();
        $query = $this->query("SELECT * FROM `subjects` ORDER BY `name` ASC");
        if($query){
            while($row = $query->fetchArray(SQLITE3_ASSOC)){
                $subjects[] = $row;
            }
        }
        $resp['status'] = 'success';
        $resp['data'] = $subjects;
        return json_encode($resp);
    }
    function get_classes(){
        $classes = array();
        $query = $this->query("SELECT c.*, s.name as subject_name FROM `class_list` c
                        INNER JOIN `subjects` s ON c.subject_id = s.subject_id
                        ORDER BY c.year DESC, c.semester, c.grade, c.section ASC");
        if($query){
            while($row = $query->fetchArray(SQLITE3_ASSOC)){
                $classes[] = $row;
            }
        }
        $resp['status'] = 'success';
        $resp['data'] = $classes;
        return json_encode($resp);
    }

    function get_quarters(){
        $class_id = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
        $quarters = [];

        if ($class_id > 0) {
            $query = $this->query("SELECT quarter, name FROM quarter_list WHERE class_id = '{$class_id}' ORDER BY quarter ASC");
            if ($query) {
                while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
                    $quarters[] = $row;
                }
            }
        }

        return json_encode(['status' => 'success', 'data' => $quarters]);
    }

    function save_quarter(){
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $name = trim($_POST['name'] ?? '');
        $resp = ['status' => 'failed', 'msg' => 'Unable to create quarter.'];

        if ($class_id < 1 || $name === '') {
            $resp['msg'] = 'Class and quarter name are required.';
            return json_encode($resp);
        }

        $classExists = (int)$this->querySingle("SELECT COUNT(*) FROM class_list WHERE class_id = '{$class_id}'");
        if ($classExists === 0) {
            $resp['msg'] = 'Selected class was not found.';
            return json_encode($resp);
        }

        // ---- Quarter numbering -------------------------------------------------
        //  * the first quarter of a class is 1;
        //  * a name that is already used by this class REUSES that quarter
        //    number instead of inserting a second row;
        //  * any other name gets the previous quarter + 1.
        // `MAX(quarter) + 1` alone could not do this: it bumped the number on
        // every insert, so re-entering "Midterm" produced a second Midterm under
        // a new number and split one term across two quarters.
        $lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $escLowerName = $this->escapeString($lowerName);

        $existingQuarter = (int)$this->querySingle(
            "SELECT quarter FROM quarter_list
             WHERE class_id = '{$class_id}' AND LOWER(TRIM(name)) = '{$escLowerName}'
             ORDER BY quarter ASC LIMIT 1"
        );

        if ($existingQuarter > 0) {
            // Same name as a quarter that already exists - hand back that quarter
            // so the caller lands on it instead of creating a duplicate.
            $resp['status'] = 'success';
            $resp['quarter'] = ['quarter' => $existingQuarter, 'name' => $name];
            $resp['reused'] = true;
            $resp['msg'] = "Quarter {$existingQuarter} already uses the name '{$name}'.";
            return json_encode($resp);
        }

        $nextQuarter = (int)$this->querySingle("SELECT COALESCE(MAX(quarter), 0) FROM quarter_list WHERE class_id = '{$class_id}'") + 1;
        // ---- end quarter numbering ---------------------------------------------

        $safeName = $this->escapeString($name);
        if ($this->query("INSERT INTO quarter_list (class_id, quarter, name) VALUES ('{$class_id}', '{$nextQuarter}', '{$safeName}')")) {
            $resp['status'] = 'success';
            $resp['quarter'] = ['quarter' => $nextQuarter, 'name' => $name];
            $resp['msg'] = "Quarter {$nextQuarter} created successfully.";
        } else {
            $resp['msg'] = $this->lastErrorMsg();
        }

        return json_encode($resp);
    }
    function update_quarter(){
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $quarter = isset($_POST['quarter']) ? (int)$_POST['quarter'] : 0;
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $resp = ['status' => 'failed', 'msg' => 'Unable to rename quarter.'];

        if ($class_id < 1 || $quarter < 1 || $name === '') {
            $resp['msg'] = 'Class, quarter number, and new name are required.';
            return json_encode($resp);
        }

        $exists = (int)$this->querySingle("SELECT COUNT(*) FROM quarter_list WHERE class_id = '{$class_id}' AND quarter = '{$quarter}'");
        if ($exists === 0) {
            $resp['msg'] = 'Quarter not found for this class.';
            return json_encode($resp);
        }

        $safeName = $this->escapeString($name);

        // Keep one name per quarter: creating/reusing a quarter resolves the name
        // by matching it, so a duplicate would make that lookup ambiguous.
        $lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $clash = (int)$this->querySingle(
            "SELECT quarter FROM quarter_list
             WHERE class_id = '{$class_id}' AND quarter <> '{$quarter}'
               AND LOWER(TRIM(name)) = '" . $this->escapeString($lowerName) . "'
             LIMIT 1"
        );
        if ($clash > 0) {
            $resp['msg'] = "This class already has a quarter named '{$name}' (Quarter {$clash}).";
            return json_encode($resp);
        }

        if ($this->query("UPDATE quarter_list SET name = '{$safeName}' WHERE class_id = '{$class_id}' AND quarter = '{$quarter}'")) {
            $resp['status'] = 'success';
            $resp['quarter'] = ['quarter' => $quarter, 'name' => $name];
            $resp['msg'] = 'Quarter renamed successfully.';
        } else {
            $resp['msg'] = $this->lastErrorMsg();
        }

        return json_encode($resp);
    }

    function delete_quarter(){
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $quarter = isset($_POST['quarter']) ? (int)$_POST['quarter'] : 0;
        $resp = ['status' => 'failed', 'msg' => 'Unable to delete quarter.'];

        if ($class_id < 1 || $quarter < 1) {
            $resp['msg'] = 'Class and quarter are required.';
            return json_encode($resp);
        }

        $exists = (int)$this->querySingle("SELECT COUNT(*) FROM quarter_list WHERE class_id = '{$class_id}' AND quarter = '{$quarter}'");
        if ($exists === 0) {
            $resp['msg'] = 'Quarter not found for this class.';
            return json_encode($resp);
        }

        $this->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            // Remove the marks belonging to this quarter's assessments
            $this->query("DELETE FROM `mark_list` WHERE `assessment_id` IN (SELECT `assessment_id` FROM `assessment_list` WHERE `class_id` = '{$class_id}' AND `quarter` = '{$quarter}')");
            // Remove the assessments of this quarter
            $this->query("DELETE FROM `assessment_list` WHERE `class_id` = '{$class_id}' AND `quarter` = '{$quarter}'");
            // Remove quarter-scoped computed/published data so nothing is orphaned
            $this->query("DELETE FROM `student_initial_grade` WHERE `class_id` = '{$class_id}' AND `quarter` = '{$quarter}'");
            $this->query("DELETE FROM `published_grades` WHERE `class_id` = '{$class_id}' AND `quarter` = '{$quarter}'");
            $this->query("DELETE FROM `attendance` WHERE `class_id` = '{$class_id}' AND `quarter` = '{$quarter}'");
            // Finally remove the quarter itself
            $this->query("DELETE FROM `quarter_list` WHERE `class_id` = '{$class_id}' AND `quarter` = '{$quarter}'");
            $this->exec('COMMIT');

            $resp['status'] = 'success';
            $resp['msg'] = 'Quarter successfully deleted.';
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Quarter successfully deleted.';
        } catch (Exception $e) {
            $this->exec('ROLLBACK');
            $resp['msg'] = 'Unable to delete quarter: ' . $e->getMessage();
        }

        return json_encode($resp);
    }

    function save_percentage(){
        extract($_POST);
        $data = "";
        foreach($component_id as $k => $v){
            if(!empty($data)) $data .= ", ";
            $data .= "('$id','{$v}','{$percentage[$k]}')";
        }
        if(!empty($data))
        $this->query("DELETE FROM `component_subject_percentage` where `subject_id` = '{$id}'");
        $sql = "INSERT INTO `component_subject_percentage` (`subject_id`,`component_id`,`percentage`)VALUES {$data}";
        $insert = $this->query($sql);
        if($insert){
            $resp['status'] ='success';
            $resp['msg'] = "Data successfully saved";
        }else{
            $resp['status'] ='failed';
            $resp['msg'] = "Data fails to save. Error: ". $this->lastErrorMsg();
            $resp['sql'] = $sql;
        }
        return json_encode($resp);
    }
    // Copy landing-page registrations whose course + section match the
    // saved class into this database's student_list. Skips student numbers
    // already enrolled in the class (idempotent) and marks copied rows
    // as enrolled in the landing database. Returns the number imported.
    function import_landing_students($class_id, $course, $section){
        $imported = 0;
        try {
            $landing_path = __DIR__ . '/db/landing_db.db';
            if (!file_exists($landing_path)) {
                return 0;
            }
            if (!function_exists('landing_db')) {
                require_once('landing_db.php');
            }
            $class_id = (int)$class_id;
            $course = trim((string)$course);
            $section = trim((string)$section);
            if ($class_id <= 0 || $course === '' || $section === '') {
                return 0;
            }
            // Columns actually present in this database's student_list
            $target_cols = [];
            $col_qry = $this->query("PRAGMA table_info('student_list')");
            if ($col_qry) {
                while ($col_row = $col_qry->fetchArray(SQLITE3_ASSOC)) {
                    $target_cols[] = $col_row['name'];
                }
            }
            if (!in_array('student_number', $target_cols) || !in_array('name', $target_cols)) {
                return 0;
            }
            // Landing fields mapped onto target columns that exist
            $field_map = ['student_number','name','middlename','firstname','lastname','name_extension','birthdate','age','gender','contact_number','email','barangay','municipality','province','zipcode','device_id','device_name','civil_status','family_status','special_sector'];
            $ldb = landing_db();
            $esc_course = $ldb->escapeString($course);
            $esc_section = $ldb->escapeString($section);
            $res = $ldb->query("SELECT * FROM `landing_registrations` WHERE course COLLATE NOCASE = '{$esc_course}' AND section COLLATE NOCASE = '{$esc_section}' ORDER BY name ASC");
            if (!$res) {
                return 0;
            }
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $esc_num = $this->escapeString($row['student_number']);
                $already = $this->querySingle("SELECT COUNT(*) FROM `student_list` WHERE class_id = '{$class_id}' AND student_number = '{$esc_num}'");
                if ($already > 0) {
                    continue;
                }
                $cols = ['class_id'];
                $vals = ["'{$class_id}'"];
                foreach ($field_map as $field) {
                    if (in_array($field, $target_cols) && array_key_exists($field, $row)) {
                        $cols[] = "`{$field}`";
                        $vals[] = "'" . $this->escapeString((string)($row[$field] ?? '')) . "'";
                    }
                }
                if ($this->exec("INSERT INTO `student_list` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")) {
                    $imported++;
                    $lid = (int)$row['id'];
                    @$ldb->exec("UPDATE `landing_registrations` SET status = 'enrolled', updated_at = CURRENT_TIMESTAMP WHERE id = '{$lid}'");
                }
            }
        } catch (Exception $e) {
            error_log('import_landing_students: ' . $e->getMessage());
        }
        return $imported;
    }
    function save_class(){
        extract($_POST);
        // Set default unit to 3 if not provided
        $unit = isset($unit) ? $unit : 3;
        if(empty($id))
            $sql = "INSERT INTO `class_list` (`subject_id`, `grade`, `section`, `course_no`, `semester`, `year`, `schedule`, `unit`)
                   VALUES('{$subject_id}','{$grade}','{$section}','{$course_no}','{$semester}','{$year}','{$schedule}','{$unit}')";
        else{
            $data = "";
             foreach($_POST as $k => $v){
                 if(!in_array($k,array('id','days','start_time','end_time'))){
                     if(!empty($data)) $data .= ", ";
                     $data .= " `{$k}` = '{$v}' ";
                 }
             }
            $sql = "UPDATE `class_list` set {$data} where `class_id` = '{$id}' ";
        }
        @$check= $this->query("SELECT COUNT(class_id) as count from `class_list` where `subject_id` = '{$subject_id}' and `grade` ='{$grade}' and `section` ='{$section}' ".($id > 0 ? " and class_id != '{$id}'" : ""))->fetchArray()['count'];
        if(@$check> 0){
            $resp['status'] ='failed';
            $resp['msg'] = 'Class already exists.';
        }else{
            @$save = $this->query($sql);
            if($save){
                $resp['status']="success";
                if(empty($id)) {
                    $resp['msg'] = "Class successfully saved.";
                    $saved_class_id = $this->lastInsertRowID();
                } else {
                    $resp['msg'] = "Class successfully updated.";
                    $saved_class_id = $id;
                }
                // Enroll matching landing registrations into this class
                $imported = $this->import_landing_students($saved_class_id, $grade ?? '', $section ?? '');
                if ($imported > 0) {
                    $resp['msg'] .= " {$imported} student(s) imported from landing registrations.";
                    $resp['imported'] = $imported;
                }
            }else{
                $resp['status']="failed";
                if(empty($id))
                    $resp['msg'] = "Saving New Class Failed.";
                else
                    $resp['msg'] = "Updating Class Failed.";
                $resp['error']=$this->lastErrorMsg();
            }
        }
        return json_encode($resp);
    }
    function delete_class(){
        extract($_POST);

        @$delete = $this->query("DELETE FROM `class_list` where class_id = '{$id}'");
        if($delete){
            $resp['status']='success';
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Class successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }
    function save_student(){
        // Initialize response array
        $resp = array('status' => 'failed', 'msg' => 'An error occurred');

        try {
            // Log the received POST data for debugging
            error_log('POST data: ' . print_r($_POST, true));

            // Normalize and sanitize inputs
            $id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
            $class_id = isset($_POST['class_id']) ? trim($_POST['class_id']) : '';
            $student_number = isset($_POST['student_number']) ? trim($_POST['student_number']) : '';
            $lastname = isset($_POST['lastname']) ? trim($_POST['lastname']) : '';
            $firstname = isset($_POST['firstname']) ? trim($_POST['firstname']) : '';
            $middlename = isset($_POST['middlename']) ? trim($_POST['middlename']) : '';
            $name_extension = isset($_POST['name_extension']) ? trim($_POST['name_extension']) : '';
            if (in_array(strtolower($name_extension), ['none', 'n/a', 'na'])) {
                $name_extension = '';
            }
            $allowed_extensions = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
            if (!in_array($name_extension, $allowed_extensions, true)) {
                $name_extension = '';
            }
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';

            if (!empty($lastname) || !empty($firstname) || !empty($middlename)) {
                $full_firstname = $firstname;
                $name = trim($lastname . ', ' . $firstname . (!empty($middlename) ? ' ' . $middlename : '') . (!empty($name_extension) ? ' ' . $name_extension : ''));
            } else {
                $full_firstname = '';
                if (!empty($name)) {
                    if (strpos($name, ',') !== false) {
                        $parts = explode(',', $name, 2);
                        $lastname = trim($parts[0]);
                        $name_parts = trim($parts[1]);
                        $name_words = preg_split('/\s+/', $name_parts, -1, PREG_SPLIT_NO_EMPTY);
                        $firstname = isset($name_words[0]) ? trim($name_words[0]) : '';
                        $full_firstname = $firstname;
                        if (empty($middlename) && count($name_words) > 1) {
                            $middlename = trim(implode(' ', array_slice($name_words, 1)));
                        }
                    } else {
                        $name_words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
                        $firstname = isset($name_words[0]) ? trim($name_words[0]) : '';
                        $lastname = isset($name_words[1]) ? trim($name_words[1]) : '';
                        $full_firstname = $firstname;
                        if (empty($middlename) && count($name_words) > 2) {
                            $middlename = trim(implode(' ', array_slice($name_words, 1, -1)));
                        }
                    }
                }
            }
            $birthdate = isset($_POST['birthdate']) ? trim($_POST['birthdate']) : '';
            $age = isset($_POST['age']) ? trim($_POST['age']) : '';
            $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
            $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $barangay = isset($_POST['barangay']) ? trim($_POST['barangay']) : '';
            $municipality = isset($_POST['municipality']) ? trim($_POST['municipality']) : '';
            $province = isset($_POST['province']) ? trim($_POST['province']) : '';
            $zipcode = isset($_POST['zipcode']) ? trim($_POST['zipcode']) : '';

            // Validate required fields
            if (empty($class_id)) {
                $resp['msg'] = 'Class is required';
                return json_encode($resp);
            }

            if (empty($student_number)) {
                $resp['msg'] = 'Student number is required';
                return json_encode($resp);
            }

            if (empty($lastname) || empty($firstname)) {
                $resp['msg'] = 'Last name and first name are required';
                return json_encode($resp);
            }

            // Validate student number format (e.g., 25-12345)
            if(!preg_match('/^\d{2}-\d{5}$/', $student_number)) {
                $resp['msg'] = 'Please enter a valid student number (e.g., 25-12345)';
                return json_encode($resp);
            }

            // Validate email format if provided
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $resp['msg'] = 'Please enter a valid email address';
                return json_encode($resp);
            }

            // Validate phone number format if provided
            if (!empty($contact_number) && !preg_match('/^[0-9]{10,11}$/', $contact_number)) {
                $resp['msg'] = 'Please enter a valid contact number (10-11 digits)';
                return json_encode($resp);
            }

            // Validate zipcode format if provided
            if (!empty($zipcode) && !preg_match('/^[0-9]{4}$/', $zipcode)) {
                $resp['msg'] = 'Please enter a valid zip code (4 digits)';
                return json_encode($resp);
            }

            // Check if student number already exists in the same class - only for new students (exclude for edit form)
            if (empty($id)) {
                $sql_check_sn = "SELECT COUNT(s.student_id) as count
                                FROM `student_list` s
                                INNER JOIN `class_list` c1 ON s.class_id = c1.class_id
                                WHERE s.`student_number` = :student_number
                                AND c1.grade = (SELECT grade FROM `class_list` WHERE class_id = :class_id)
                                AND c1.section = (SELECT section FROM `class_list` WHERE class_id = :class_id2)
                                AND c1.subject_id != (SELECT subject_id FROM `class_list` WHERE class_id = :class_id3)";

                $stmt = $this->prepare($sql_check_sn);
                $stmt->bindValue(':student_number', $student_number, SQLITE3_TEXT);
                $stmt->bindValue(':class_id', $class_id, SQLITE3_INTEGER);
                $stmt->bindValue(':class_id2', $class_id, SQLITE3_INTEGER);
                $stmt->bindValue(':class_id3', $class_id, SQLITE3_INTEGER);
                $result = $stmt->execute();

                if(!$result){
                    $resp['msg'] = 'Validating Student Number failed. Error: '.$this->lastErrorMsg();
                    $resp['sql'] = $sql_check_sn;
                    return json_encode($resp);
                }

                $row_sn = $result->fetchArray(SQLITE3_ASSOC);
                $check_student_number = isset($row_sn['count']) ? (int)$row_sn['count'] : 0;

                if($check_student_number > 0) {
                    $resp['msg'] = 'Student Number already exists in this grade and section for a different subject';
                    return json_encode($resp);
                }
            }

            // Ensure the name_extension column exists (safe migration for existing databases)
            $cols_res = $this->query('PRAGMA table_info(student_list)');
            $has_name_extension = false;
            while ($col = $cols_res->fetchArray(SQLITE3_ASSOC)) {
                if ($col['name'] === 'name_extension') { $has_name_extension = true; break; }
            }
            if (!$has_name_extension) {
                $this->query("ALTER TABLE `student_list` ADD COLUMN `name_extension` TEXT DEFAULT ''");
            }

            // Prepare the SQL query based on insert or update
            if(empty($id)) {
                $sql = "INSERT INTO `student_list` (`class_id`, `student_number`, `name`, `middlename`, `firstname`, `lastname`, `name_extension`, `birthdate`, `age`, `gender`, `contact_number`, `email`, `barangay`, `municipality`, `province`, `zipcode`)
                        VALUES(:class_id, :student_number, :name, :middlename, :firstname, :lastname, :name_extension, :birthdate, :age, :gender, :contact_number, :email, :barangay, :municipality, :province, :zipcode)";
                $stmt = $this->prepare($sql);
                $stmt->bindValue(':class_id', $class_id, SQLITE3_TEXT);
                $stmt->bindValue(':student_number', $student_number, SQLITE3_TEXT);
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':middlename', $middlename, SQLITE3_TEXT);
                $stmt->bindValue(':firstname', $full_firstname, SQLITE3_TEXT);
                $stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
                $stmt->bindValue(':name_extension', $name_extension, SQLITE3_TEXT);
                $stmt->bindValue(':birthdate', $birthdate, SQLITE3_TEXT);
                $stmt->bindValue(':age', $age, SQLITE3_TEXT);
                $stmt->bindValue(':gender', $gender, SQLITE3_TEXT);
                $stmt->bindValue(':contact_number', $contact_number, SQLITE3_TEXT);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':barangay', $barangay, SQLITE3_TEXT);
                $stmt->bindValue(':municipality', $municipality, SQLITE3_TEXT);
                $stmt->bindValue(':province', $province, SQLITE3_TEXT);
                $stmt->bindValue(':zipcode', $zipcode, SQLITE3_TEXT);
            } else {
                $sql = "UPDATE `student_list` SET `class_id` = :class_id, `student_number` = :student_number, `name` = :name, `middlename` = :middlename, `firstname` = :firstname, `lastname` = :lastname,
                        `name_extension` = :name_extension, `birthdate` = :birthdate, `age` = :age, `gender` = :gender, `contact_number` = :contact_number, `email` = :email,
                        `barangay` = :barangay, `municipality` = :municipality, `province` = :province, `zipcode` = :zipcode
                        WHERE `student_id` = :id";
                $stmt = $this->prepare($sql);
                $stmt->bindValue(':class_id', $class_id, SQLITE3_TEXT);
                $stmt->bindValue(':student_number', $student_number, SQLITE3_TEXT);
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':middlename', $middlename, SQLITE3_TEXT);
                $stmt->bindValue(':firstname', $full_firstname, SQLITE3_TEXT);
                $stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
                $stmt->bindValue(':name_extension', $name_extension, SQLITE3_TEXT);
                $stmt->bindValue(':birthdate', $birthdate, SQLITE3_TEXT);
                $stmt->bindValue(':age', $age, SQLITE3_TEXT);
                $stmt->bindValue(':gender', $gender, SQLITE3_TEXT);
                $stmt->bindValue(':contact_number', $contact_number, SQLITE3_TEXT);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':barangay', $barangay, SQLITE3_TEXT);
                $stmt->bindValue(':municipality', $municipality, SQLITE3_TEXT);
                $stmt->bindValue(':province', $province, SQLITE3_TEXT);
                $stmt->bindValue(':zipcode', $zipcode, SQLITE3_TEXT);
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            }

            // Check for duplicate student in the same class using firstname and lastname
            // This prevents duplicates regardless of middlename differences
            $sql_check_name = "SELECT COUNT(student_id) as count from `student_list`
                              where `class_id` = :class_id
                              and `firstname` = :firstname
                              and `lastname` = :lastname";
            if($id > 0) {
                $sql_check_name .= " and student_id != :student_id";
            }

            $stmt_check = $this->prepare($sql_check_name);
            $stmt_check->bindValue(':class_id', $class_id, SQLITE3_TEXT);
            $stmt_check->bindValue(':firstname', $firstname, SQLITE3_TEXT);
            $stmt_check->bindValue(':lastname', $lastname, SQLITE3_TEXT);
            if($id > 0) {
                $stmt_check->bindValue(':student_id', $id, SQLITE3_INTEGER);
            }

            $result_check = $stmt_check->execute();
            if(!$result_check){
                $resp['msg'] = 'Validating Student duplicate failed. Error: '.$this->lastErrorMsg();
                return json_encode($resp);
            }

            $row_name = $result_check->fetchArray(SQLITE3_ASSOC);
            $check = isset($row_name['count']) ? (int)$row_name['count'] : 0;

            // Fallback: check using full name field for legacy records without firstname/lastname
            // Parse existing records' full names to extract firstname and lastname for comparison
            // Use same parsing logic as current entry (first word only as firstname)
            if($check == 0) {
                $sql_check_fullname = "SELECT student_id, name from `student_list`
                                      where `class_id` = :class_id";
                if($id > 0) {
                    $sql_check_fullname .= " and student_id != :student_id";
                }

                $stmt_check_full = $this->prepare($sql_check_fullname);
                $stmt_check_full->bindValue(':class_id', $class_id, SQLITE3_TEXT);
                if($id > 0) {
                    $stmt_check_full->bindValue(':student_id', $id, SQLITE3_INTEGER);
                }

                $result_check_full = $stmt_check_full->execute();
                if($result_check_full){
                    while($row = $result_check_full->fetchArray(SQLITE3_ASSOC)){
                        $existing_name = $row['name'];
                        // Parse existing name to extract firstname and lastname
                        // Use same logic as current parsing (first word only as firstname)
                        $existing_lastname = '';
                        $existing_firstname = '';

                        if (strpos($existing_name, ',') !== false) {
                            $parts = explode(',', $existing_name, 2);
                            $existing_lastname = trim($parts[0]);
                            $name_parts = trim($parts[1]);
                            // Take only first word as firstname for duplicate checking
                            $name_words = preg_split('/\s+/', $name_parts, -1, PREG_SPLIT_NO_EMPTY);
                            $existing_firstname = isset($name_words[0]) ? trim($name_words[0]) : '';
                        } else {
                            $name_words = explode(' ', $existing_name);
                            $existing_firstname = isset($name_words[0]) ? trim($name_words[0]) : '';
                            $existing_lastname = isset($name_words[1]) ? trim($name_words[1]) : '';
                        }
                        // Check if both firstname and lastname match (case-insensitive)
                        if (strcasecmp($existing_lastname, $lastname) == 0 &&
                            strcasecmp($existing_firstname, $firstname) == 0) {
                            $check++;
                            break;
                        }
                    }
                }
            }

            if($check > 0) {
                $resp['msg'] = 'A student with the same name (firstname and lastname) already exists in this class.';
                return json_encode($resp);
            }

            // Execute the prepared statement
            $result = $stmt->execute();

            if($result) {
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? 'Student successfully saved.' : 'Student successfully updated.';
            } else {
                $resp['msg'] = empty($id) ? 'Saving New Student Failed.' : 'Updating Student Failed.';
                $resp['error'] = $this->lastErrorMsg();
                error_log('Database Error: ' . $this->lastErrorMsg());
            }
        } catch (Exception $e) {
            // Log the exception
            error_log('Exception in save_student: ' . $e->getMessage());
            $resp['msg'] = 'An error occurred: ' . $e->getMessage();
            $resp['error'] = $e->getMessage();
            $resp['exception'] = $e->getTraceAsString();
        }

        return json_encode($resp);
    }
    function delete_student(){
        extract($_POST);

        @$delete = $this->query("DELETE FROM `student_list` where student_id = '{$id}'");
        if($delete){
            $resp['status']='success';
            $resp['msg'] = 'Student successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }

    function delete_multiple_students(){
        $resp = array('status' => 'failed', 'msg' => 'An error occurred');

        try {
            if (!isset($_POST['ids']) || empty($_POST['ids'])) {
                $resp['msg'] = 'No student IDs provided';
                return json_encode($resp);
            }

            $ids = is_array($_POST['ids']) ? $_POST['ids'] : [$_POST['ids']];
            if (empty($ids)) {
                $resp['msg'] = 'Invalid student IDs';
                return json_encode($resp);
            }

            // Ensure all IDs are integers
            $ids = array_map('intval', $ids);
            $placeholders = rtrim(str_repeat('?,', count($ids)), ',');

            // Begin transaction
            $this->exec('BEGIN TRANSACTION');

            // First, delete related records in mark_list
            // Note: The ON DELETE CASCADE in the schema should handle this,
            // but we'll do it explicitly to be safe
            $sql_marks = "DELETE FROM mark_list WHERE student_id IN (" . $placeholders . ")";
            $stmt_marks = $this->prepare($sql_marks);

            // Bind parameters for mark_list deletion
            foreach ($ids as $index => $id) {
                $stmt_marks->bindValue($index + 1, $id, SQLITE3_INTEGER);
            }

            $result_marks = $stmt_marks->execute();
            if (!$result_marks) {
                $error = $this->lastErrorMsg();
                error_log('Failed to delete marks: ' . $error);
                throw new Exception('Failed to delete related marks: ' . $error);
            }

            // Then delete the students
            $sql_students = "DELETE FROM student_list WHERE student_id IN (" . $placeholders . ")";
            $stmt_students = $this->prepare($sql_students);

            // Bind parameters for student deletion
            foreach ($ids as $index => $id) {
                $stmt_students->bindValue($index + 1, $id, SQLITE3_INTEGER);
            }

            $result_students = $stmt_students->execute();

            if ($result_students) {
                $deletedCount = $this->changes();
                if ($deletedCount === 0) {
                    // No rows were deleted, which might indicate the students don't exist
                    $this->exec('ROLLBACK');
                    $resp['msg'] = 'No students were deleted. The specified students may not exist.';
                    error_log('No students deleted. IDs: ' . implode(',', $ids));
                    return json_encode($resp);
                }

                $this->exec('COMMIT');
                $resp['status'] = 'success';
                $resp['msg'] = $deletedCount . ' student(s) have been deleted successfully.';
                $_SESSION['flashdata']['type'] = 'success';
                $_SESSION['flashdata']['msg'] = $deletedCount . ' student(s) successfully deleted.';

                // Log the deletion
                error_log('Successfully deleted ' . $deletedCount . ' students. IDs: ' . implode(',', $ids));
            } else {
                $error = $this->lastErrorMsg();
                error_log('Failed to delete students: ' . $error);
                throw new Exception('Failed to delete students: ' . $error);
            }

        } catch (Exception $e) {
            $this->exec('ROLLBACK');
            $resp['msg'] = $e->getMessage();
            $resp['error'] = $e->getMessage();
            error_log('Error in delete_multiple_students: ' . $e->getMessage());
        }

        return json_encode($resp);
    }

    private function redistributeAssessmentPercentages($class_id, $component_id, $quarter) {
        $assessments = $this->query("SELECT assessment_id FROM `assessment_list` WHERE `class_id` = '{$class_id}' AND `component_id` = '{$component_id}' AND `quarter` = '{$quarter}' ORDER BY assessment_id ASC");
        $ids = [];
        while ($row = $assessments->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = $row['assessment_id'];
        }

        $count = count($ids);
        if ($count > 0) {
            $even_share = floor((100 / $count) * 100) / 100;
            $running_total = 0;

            foreach ($ids as $index => $aid) {
                if ($index === $count - 1) {
                    $value = 100 - $running_total;
                } else {
                    $value = $even_share;
                    $running_total += $value;
                }
                $this->query("UPDATE `assessment_list` SET `percentage` = '{$value}' WHERE `assessment_id` = '{$aid}'");
            }
        }
    }

    private function fetchAssessmentData($assessment_id) {
        return $this->query("SELECT
            a.*,
            (s.name || ' ' || c.grade || ' - ' || c.section) as class,
            cc.name as component,
            a.component_id as component_id
        FROM `assessment_list` a
        INNER JOIN `class_list` c ON a.class_id = c.class_id
        INNER JOIN `subjects` s ON c.subject_id = s.subject_id
        INNER JOIN `grading_components` cc ON a.component_id = cc.component_id
        WHERE a.assessment_id = '{$assessment_id}'")->fetchArray(SQLITE3_ASSOC);
    }

    function get_assessment() {
        $assessment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($assessment_id <= 0) {
            return json_encode(['status' => 'error', 'msg' => 'Invalid assessment ID']);
        }

        $assessment = $this->fetchAssessmentData($assessment_id);

        if ($assessment) {
            return json_encode(['status' => 'success', 'data' => $assessment]);
        } else {
            return json_encode(['status' => 'error', 'msg' => 'Assessment not found']);
        }
    }

    function save_assessment(){
        extract($_POST);
        $resp = ['status' => 'failed', 'msg' => 'An error occurred'];

        if (!empty($id) && isset($class_id) && $class_id === 'all') {
            $resp['msg'] = 'Cannot edit an assessment for all classes at once.';
            return json_encode($resp);
        }

        if (empty($id) && isset($class_id) && $class_id === 'all') {
            $created_assessments = [];
            $skipped = 0;
            $failed = 0;
            $percentage = ($percentage !== '' && $percentage !== null) ? $percentage : 0;

            $classes_qry = $this->query("SELECT cl.class_id
                FROM class_list cl
                INNER JOIN component_subject_percentage csp ON csp.subject_id = cl.subject_id
                WHERE csp.component_id = '{$component_id}'
                AND CAST(csp.percentage AS REAL) > 0
                ORDER BY cl.class_id ASC");

            if ($classes_qry) {
                while ($class_row = $classes_qry->fetchArray(SQLITE3_ASSOC)) {
                    $target_class_id = $class_row['class_id'];

                    $check = $this->query("SELECT COUNT(assessment_id) as count from `assessment_list` where `class_id` = '{$target_class_id}' and `component_id` = '{$component_id}' and `quarter` = '{$quarter}' and `name` ='{$name}'")->fetchArray()['count'];
                    if ($check > 0) {
                        $skipped++;
                        continue;
                    }

                    $insert_sql = "INSERT INTO `assessment_list` (`class_id`,`component_id`,`quarter`,`name`,`percentage`,`hps`)VALUES('{$target_class_id}','{$component_id}','{$quarter}','{$name}','{$percentage}','{$hps}')";
                    $save = $this->query($insert_sql);
                    if ($save) {
                        $assessment_id = $this->lastInsertRowID();
                        if (empty($_POST['manual_percentage'])) {
                            $this->redistributeAssessmentPercentages($target_class_id, $component_id, $quarter);
                        }
                        $assessment_data = $this->fetchAssessmentData($assessment_id);
                        if ($assessment_data) {
                            $created_assessments[] = $assessment_data;
                        }
                    } else {
                        $failed++;
                    }
                }
            }

            if (count($created_assessments) > 0) {
                $resp['status'] = 'success';
                $resp['created_assessments'] = $created_assessments;
                $count = count($created_assessments);
                $resp['msg'] = "Assessment successfully saved to {$count} class" . ($count !== 1 ? 'es' : '') . '.';
                if ($skipped > 0) {
                    $resp['msg'] .= " ({$skipped} skipped — already exists)";
                }
            } else {
                $resp['msg'] = $skipped > 0
                    ? 'Assessment already exists for all applicable classes.'
                    : 'Saving New Assessment Failed. No applicable classes found for this component.';
                if ($failed > 0) {
                    $resp['error'] = $this->lastErrorMsg();
                }
            }

            return json_encode($resp);
        }

        if(empty($id))
            $sql = "INSERT INTO `assessment_list` (`class_id`,`component_id`,`quarter`,`name`,`percentage`,`hps`)VALUES('{$class_id}','{$component_id}','{$quarter}','{$name}','{$percentage}','{$hps}')";
        else{
            $data = "";
             foreach($_POST as $k => $v){
                 if(!in_array($k,array('id'))){
                     if(!empty($data)) $data .= ", ";
                     $data .= " `{$k}` = '{$v}' ";
                 }
             }
            $sql = "UPDATE `assessment_list` set {$data} where `assessment_id` = '{$id}' ";
        }
        // Check if assessment with same name already exists
        @$check= $this->query("SELECT COUNT(assessment_id) as count from `assessment_list` where `class_id` = '{$class_id}' and `component_id` = '{$component_id}' and `quarter` = '{$quarter}' and `name` ='{$name}' ".($id > 0 ? " and assessment_id != '{$id}'" : ""))->fetchArray()['count'];
        if(@$check> 0){
            $resp['status'] ='failed';
            $resp['msg'] = 'Assessment already exists.';
        } else {
            // Get component name for reference
            $component_check = $this->query("SELECT name FROM `grading_components` WHERE `component_id` = '{$component_id}'")->fetchArray()['name'];

            @$save = $this->query($sql);
            if($save){
                // Get the saved/updated assessment data for dynamic table updates
                $assessment_id = empty($id) ? $this->lastInsertRowID() : $id;
                $assessment_data = $this->fetchAssessmentData($assessment_id);

                if (empty($_POST['manual_percentage'])) {
                    $this->redistributeAssessmentPercentages($class_id, $component_id, $quarter);
                }

                if ($assessment_id) {
                    $assessment_data = $this->fetchAssessmentData($assessment_id);
                }
                $resp['status']="success";
                $resp['assessment_data'] = $assessment_data;
                if(empty($id))
                    $resp['msg'] = "Assessment successfully saved.";
                else
                    $resp['msg'] = "Assessment successfully updated.";
            }else{
                $resp['status']="failed";
                if(empty($id))
                    $resp['msg'] = "Saving New Assessment Failed.";
                else
                    $resp['msg'] = "Updating Assessment Failed.";
                $resp['error']=$this->lastErrorMsg();
            }
        }
        return json_encode($resp);
    }

    function save_assessment_date(){
        $resp = ['status' => 'failed', 'msg' => ''];
        try {
            extract($_POST);

            if(empty($assessment_id) || empty($date)) {
                $resp['status'] = 'failed';
                $resp['msg'] = 'Assessment ID and date are required';
                return json_encode($resp);
            }

            // Validate date format
            if(!DateTime::createFromFormat('Y-m-d', $date)) {
                $resp['status'] = 'failed';
                $resp['msg'] = 'Invalid date format';
                return json_encode($resp);
            }

            // Check if assessment exists
            $check = $this->query("SELECT assessment_id FROM `assessment_list` WHERE assessment_id = '{$assessment_id}'")->fetchArray();
            if(!$check) {
                $resp['status'] = 'failed';
                $resp['msg'] = 'Assessment not found';
                return json_encode($resp);
            }

            // Update the assessment date
            $stmt = $this->prepare("UPDATE `assessment_list` SET `date` = :date WHERE `assessment_id` = :assessment_id");
            $stmt->bindValue(':date', $date, SQLITE3_TEXT);
            $stmt->bindValue(':assessment_id', $assessment_id, SQLITE3_INTEGER);

            if($stmt->execute()) {
                $resp['status'] = 'success';
                $resp['msg'] = 'Assessment date saved successfully';
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = 'Failed to save assessment date';
                $resp['error'] = $this->lastErrorMsg();
            }

        } catch(Exception $e) {
            $resp['status'] = 'failed';
            $resp['msg'] = 'An error occurred: ' . $e->getMessage();
            $resp['error'] = $this->lastErrorMsg();
        }

        return json_encode($resp);
    }

    function delete_assessment(){
        extract($_POST);

        // Get context before deleting (needed for redistribution)
        $row = $this->query("SELECT
            a.*,
            (s.name || ' ' || c.grade || ' - ' || c.section) as class,
            cc.name as component
        FROM `assessment_list` a
        INNER JOIN `class_list` c ON a.class_id = c.class_id
        INNER JOIN `subjects` s ON c.subject_id = s.subject_id
        INNER JOIN `grading_components` cc ON a.component_id = cc.component_id
        WHERE a.assessment_id = '{$id}'")->fetchArray(SQLITE3_ASSOC);

        @$delete = $this->query("DELETE FROM `assessment_list` where assessment_id = '{$id}'");
        if($delete){
            $resp['status']='success';
            $resp['deleted_assessment'] = $row; // Include deleted assessment data
            // Explicitly remove corresponding student marks (deterministic,
            // independent of FK cascade enforcement on this connection).
            @$this->query("DELETE FROM `mark_list` WHERE `assessment_id` = '{$id}'");
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Assessment successfully deleted.';

            // If this was any assessment, redistribute remaining percentages
            if ($row && isset($row['class_id'], $row['component_id'], $row['quarter'])) {
                $class_id = $row['class_id'];
                $component_id = $row['component_id'];
                $quarter = $row['quarter'];

                $assessments = $this->query("SELECT assessment_id FROM `assessment_list` WHERE `class_id` = '{$class_id}' AND `component_id` = '{$component_id}' AND `quarter` = '{$quarter}' ORDER BY assessment_id ASC");
                $ids = [];
                while($aRow = $assessments->fetchArray(SQLITE3_ASSOC)) {
                    $ids[] = $aRow['assessment_id'];
                }

                $count = count($ids);
                if ($count > 0) {
                    $even_share = 100 / $count;
                    $running_total = 0;
                    foreach ($ids as $index => $aid) {
                        if ($index === $count - 1) {
                            $value = 100 - $running_total;
                        } else {
                            $value = $even_share;
                            $running_total += $value;
                        }
                        $this->query("UPDATE `assessment_list` SET `percentage` = '{$value}' WHERE `assessment_id` = '{$aid}'");
                    }

                    // Get updated assessments data for remaining assessments
                    $resp['updated_assessments'] = [];
                    $updated_query = $this->query("SELECT
                        a.assessment_id, a.percentage
                    FROM `assessment_list` a
                    WHERE a.class_id = '{$class_id}' AND a.component_id = '{$component_id}' AND a.quarter = '{$quarter}'
                    ORDER BY a.assessment_id ASC");

                    while($updated_row = $updated_query->fetchArray(SQLITE3_ASSOC)) {
                        $resp['updated_assessments'][] = $updated_row;
                    }
                }
            }
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }
    function save_mark(){
        $resp = ['status' => 'failed', 'msg' => ''];
        try {
            // Debug: Log the received POST data
            error_log("save_mark POST data: " . print_r($_POST, true));

            if (!isset($_POST['student_id']) || !is_array($_POST['student_id']) ||
                !isset($_POST['mark']) || !is_array($_POST['mark']) ||
                !isset($_POST['assessment_id'])) {
                throw new Exception("Invalid input data");
            }

            extract($_POST);
            $data = [];

            // Process all students
            foreach($student_id as $k => $v) {
                if (!isset($mark[$k])) continue;
                $v = $this->escapeString($v);
                // Blank (empty string) means "not yet graded". mark_list.mark is
                // NOT NULL, so a blank must be OMITTED rather than stored as NULL
                // or 0 - the missing row is what renders the cell empty. Because
                // this method clears the assessment's marks first, skipping the
                // student leaves them with no row at all. An unparseable value is
                // treated the same way instead of silently becoming 0.
                $raw = trim((string)$mark[$k]);
                if ($raw === '' || !is_numeric($raw)) continue;
                $m = (float)$raw;
                $data[] = "('{$assessment_id}','{$v}','{$m}')";
            }

            if(!empty($data)){
                // Start transaction
                $this->exec('BEGIN TRANSACTION');

                // Delete existing marks for this assessment
                $delete = $this->exec("DELETE FROM `mark_list` WHERE assessment_id = '{$assessment_id}'");
                if ($delete === false) {
                    throw new Exception("Failed to clear existing marks: " . $this->lastErrorMsg());
                }

                // Insert new marks
                $values = implode(",", $data);
                $sql = "INSERT INTO `mark_list` (`assessment_id`, `student_id`, `mark`) VALUES {$values}";
                $save = $this->exec($sql);

                if($save === false) {
                    throw new Exception("Failed to save marks: " . $this->lastErrorMsg() . "\nSQL: " . $sql);
                }

                // Commit transaction
                $this->exec('COMMIT');

                $resp['status'] = 'success';
                $resp['msg'] = "Marks successfully saved for " . count($data) . " students";
            } else {
                $resp['msg'] = "No valid student data to save";
            }
        } catch (Exception $e) {
            $this->exec('ROLLBACK');
            $resp['msg'] = "An error occurred while saving marks: " . $e->getMessage();
            error_log("Error in save_mark: " . $e->getMessage());
        }
        return json_encode($resp);
    }

    function bulk_save_students() {
        $resp = array('status' => 'failed', 'msg' => 'An error occurred');

        try {
            // Get the form data directly from $_POST
            if (!isset($_POST['class_id']) || empty($_POST['class_id'])) {
                $resp['msg'] = 'Class ID is required';
                return json_encode($resp);
            }

            if (!isset($_POST['entries']) || empty($_POST['entries'])) {
                $resp['msg'] = 'No student entries provided';
                return json_encode($resp);
            }

            $class_id = $this->escapeString($_POST['class_id']);

            // Ensure the name_extension column exists (safe migration for existing databases)
            $cols_res = $this->query('PRAGMA table_info(student_list)');
            $has_name_extension = false;
            while ($col = $cols_res->fetchArray(SQLITE3_ASSOC)) {
                if ($col['name'] === 'name_extension') { $has_name_extension = true; break; }
            }
            if (!$has_name_extension) {
                $this->query("ALTER TABLE `student_list` ADD COLUMN `name_extension` TEXT DEFAULT ''");
            }

            $entries = json_decode($_POST['entries'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $resp['msg'] = 'Invalid entries format: ' . json_last_error_msg();
                return json_encode($resp);
            }

            $success_count = 0;
            $errors = [];

            // Start transaction
            $this->exec('BEGIN TRANSACTION');

            foreach ($entries as $index => $entry) {
                if (empty($entry['student_number']) || empty($entry['lastname']) || empty($entry['firstname'])) {
                    $errors[] = "Row " . ($index + 1) . ": Student number, last name, and first name are required";
                    continue;
                }

                $student_number = $this->escapeString($entry['student_number']);
                $lastname_raw = trim($entry['lastname']);
                $firstname_raw = trim($entry['firstname']);
                $middlename_raw = isset($entry['middlename']) ? trim($entry['middlename']) : '';
                $name_extension_raw = isset($entry['name_extension']) ? trim($entry['name_extension']) : '';
                if (in_array(strtolower($name_extension_raw), ['none', 'n/a', 'na'])) {
                    $name_extension_raw = '';
                }
                $allowed_extensions = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
                if (!in_array($name_extension_raw, $allowed_extensions, true)) {
                    $name_extension_raw = '';
                }
                $name_extension = !empty($name_extension_raw) ? $this->escapeString($name_extension_raw) : 'NULL';
                $full_name_raw = !empty($entry['name']) ? trim($entry['name']) : trim($lastname_raw . ', ' . $firstname_raw . (!empty($middlename_raw) ? ' ' . $middlename_raw : '') . (!empty($name_extension_raw) ? ' ' . $name_extension_raw : ''));
                $name = $this->escapeString($full_name_raw);
                $middlename = !empty($middlename_raw) ? $this->escapeString($middlename_raw) : 'NULL';
                $birthdate = isset($entry['birthdate']) && !empty($entry['birthdate']) ? $this->escapeString($entry['birthdate']) : 'NULL';
                $gender = isset($entry['gender']) && !empty($entry['gender']) ? $this->escapeString($entry['gender']) : 'NULL';
                $contact_number = isset($entry['contact_number']) && !empty($entry['contact_number']) ? $this->escapeString($entry['contact_number']) : 'NULL';
                $email = isset($entry['email']) && !empty($entry['email']) ? $this->escapeString($entry['email']) : 'NULL';
                $barangay = isset($entry['barangay']) && !empty($entry['barangay']) ? $this->escapeString($entry['barangay']) : 'NULL';
                $municipality = isset($entry['municipality']) && !empty($entry['municipality']) ? $this->escapeString($entry['municipality']) : 'NULL';
                $province = isset($entry['province']) && !empty($entry['province']) ? $this->escapeString($entry['province']) : 'NULL';
                $zipcode = isset($entry['zipcode']) && !empty($entry['zipcode']) ? $this->escapeString($entry['zipcode']) : 'NULL';

                $firstname = $this->escapeString($firstname_raw);
                $lastname = $this->escapeString($lastname_raw);
                $full_firstname = $firstname;

                // Check if student number already exists IN THIS CLASS (the same
                // student may be enrolled in other subject classes)
                $check = $this->querySingle("SELECT COUNT(*) as count FROM `student_list` WHERE `class_id` = '{$class_id}' AND `student_number` = '{$student_number}'");

                if ($check > 0) {
                    $errors[] = "Row " . ($index + 1) . ": Student number '{$student_number}' already exists in this class";
                    continue;
                }

                // Check for duplicate student with same firstname and lastname in相同 class
                // This prevents duplicates regardless of middlename differences
                $duplicate_check = $this->querySingle("SELECT COUNT(*) as count FROM `student_list`
                                                        WHERE `class_id` = '{$class_id}'
                                                        AND `firstname` = '{$firstname}'
                                                        AND `lastname` = '{$lastname}'");

                // Fallback: check using full name for legacy records
                if ($duplicate_check == 0) {
                    $duplicate_check = $this->querySingle("SELECT COUNT(*) as count FROM `student_list`
                                                            WHERE `class_id` = '{$class_id}'
                                                            AND `name` = '{$name}'");
                }

                if ($duplicate_check > 0) {
                    $errors[] = "Row " . ($index + 1) . ": A student with the same name (firstname and lastname) already exists in this class";
                    continue;
                }

                // Insert the student with all fields
                $sql = "INSERT INTO `student_list` (`class_id`, `student_number`, `name`, `middlename`, `firstname`, `lastname`, `name_extension`, `birthdate`, `gender`, `contact_number`, `email`, `barangay`, `municipality`, `province`, `zipcode`)
                        VALUES ('{$class_id}', '{$student_number}', '{$name}', " .
                        ($middlename !== 'NULL' ? "'{$middlename}'" : 'NULL') . ", " .
                        "'{$full_firstname}', '{$lastname}', " .
                        ($name_extension !== 'NULL' ? "'{$name_extension}'" : 'NULL') . ", " .
                        ($birthdate !== 'NULL' ? "'{$birthdate}'" : 'NULL') . ", " .
                        ($gender !== 'NULL' ? "'{$gender}'" : 'NULL') . ", " .
                        ($contact_number !== 'NULL' ? "'{$contact_number}'" : 'NULL') . ", " .
                        ($email !== 'NULL' ? "'{$email}'" : 'NULL') . ", " .
                        ($barangay !== 'NULL' ? "'{$barangay}'" : 'NULL') . ", " .
                        ($municipality !== 'NULL' ? "'{$municipality}'" : 'NULL') . ", " .
                        ($province !== 'NULL' ? "'{$province}'" : 'NULL') . ", " .
                        ($zipcode !== 'NULL' ? "'{$zipcode}'" : 'NULL') . ")";

                $result = $this->exec($sql);
                if ($result) {
                    $success_count++;
                } else {
                    $errors[] = "Row " . ($index + 1) . ": Failed to save - " . $this->lastErrorMsg();
                }
            }

            // Commit transaction
            $this->exec('COMMIT');

            $resp['status'] = 'success';
            $resp['msg'] = "Successfully saved {$success_count} student(s)";
            if (!empty($errors)) {
                $resp['msg'] .= ". " . count($errors) . " error(s) occurred.";
                $resp['errors'] = $errors;
            }

        } catch (Exception $e) {
            // Rollback on error
            $this->exec('ROLLBACK');
            $resp['msg'] = 'Error: ' . $e->getMessage();
        }

        return json_encode($resp);
    }

    function get_remaining_percentage() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['class_id']) || !isset($_POST['component_id']) || !isset($_POST['quarter'])) {
                throw new Exception('Missing required parameters');
            }

            $class_id = $this->escapeString($_POST['class_id']);
            $component_id = $this->escapeString($_POST['component_id']);
            $quarter = $this->escapeString($_POST['quarter']);
            $current_id = isset($_POST['current_id']) ? $this->escapeString($_POST['current_id']) : 0;

            // Get total percentage of existing assessments for this class, component, and quarter
            $sql = "SELECT COALESCE(SUM(percentage), 0) as total
                    FROM assessment_list
                    WHERE class_id = '{$class_id}'
                    AND component_id = '{$component_id}'
                    AND quarter = '{$quarter}'";

            // Exclude current assessment if editing
            if (!empty($current_id)) {
                $sql .= " AND assessment_id != '{$current_id}'";
            }

            $total_percentage = $this->querySingle($sql);
            $remaining = 100 - (float)$total_percentage;

            $response = [
                'status' => 'success',
                'remaining' => number_format($remaining, 2),
                'total_used' => number_format($total_percentage, 2)
            ];

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    function get_student_details() {
        $student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($student_id <= 0) {
            return json_encode(['status' => 'error', 'message' => 'Invalid student ID: ' . $student_id]);
        }

        try {
            // First try a simple query without joins to test basic functionality
            $sql = "SELECT * FROM student_list WHERE student_id = {$student_id}";

            $result = $this->query($sql);

            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                // Now try to get the additional information
                try {
                    $class_sql = "SELECT c.grade || ' - ' || c.section as grade_section,
                                          sub.name as subject_name
                                   FROM class_list c
                                   INNER JOIN subjects sub ON c.subject_id = sub.subject_id
                                   WHERE c.class_id = " . ($row['class_id'] ?? 0);

                    $class_result = $this->query($class_sql);
                    $class_row = $class_result->fetchArray(SQLITE3_ASSOC);

                    // Merge the results
                    if ($class_row) {
                        $row['grade_section'] = $class_row['grade_section'];
                        $row['subject_name'] = $class_row['subject_name'];
                    } else {
                        $row['grade_section'] = 'N/A';
                        $row['subject_name'] = 'N/A';
                    }
                } catch (Exception $e) {
                    // If class query fails, set defaults
                    $row['grade_section'] = 'N/A';
                    $row['subject_name'] = 'N/A';
                }

                return json_encode([
                    'status' => 'success',
                    'data' => $row
                ]);
            } else {
                return json_encode(['status' => 'error', 'message' => 'Student not found with ID: ' . $student_id]);
            }
        } catch (Exception $e) {
            return json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    function get_students() {
        // Set JSON header
        header('Content-Type: application/json');

        $response = [];
        try {
            // Get the request parameters from DataTables
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 1;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $search = isset($_POST['search']['value']) ? $this->escapeString($_POST['search']['value']) : '';
            $orderColumn = isset($_POST['order'][0]['column']) ? intval($_POST['order'][0]['column']) : 3; // Default sort by Name (column index 3)
            $orderDir = isset($_POST['order'][0]['dir']) ? strtoupper($this->escapeString($_POST['order'][0]['dir'])) : 'ASC';
            $sectionFilter = isset($_POST['sectionFilter']) ? $this->escapeString($_POST['sectionFilter']) : '';

            // Define column names for ordering
            $columns = [
                0 => 'student_id',      // checkbox column (not sortable)
                1 => 'student_id',      // DT_RowIndex (not sortable)
                2 => 'student_number',  // student_number column
                3 => 'ss.name',         // student name column (use table alias to avoid ambiguity)
                4 => 'sname',           // subject name column
                5 => 'grade_section',   // grade_section column
                6 => 'student_id',      // qr_code column (not sortable)
                7 => 'student_id'       // action column (not sortable)
            ];

            $orderBy = isset($columns[$orderColumn]) ? $columns[$orderColumn] : 'ss.name';

            // Simple case-insensitive sorting for name column
            if ($orderColumn == 3 && $orderBy == 'ss.name') {
                $orderBy = 'TRIM(ss.name) COLLATE NOCASE';
            }

            // Build the base query — include course_no/year for previous-year PASSED check (do not submit if already passed)
            $sql = "SELECT ss.*, (c.grade || ' - ' || c.section) as grade_section, s.name as sname, c.course_no, c.year
                   FROM `student_list` ss
                   INNER JOIN `class_list` c ON ss.class_id = c.class_id
                   INNER JOIN `subjects` s ON c.subject_id = s.subject_id";

            // Add search condition if search term is provided
            $where = [];
            if (!empty($search)) {
                $where[] = "(ss.name LIKE '%{$search}%' OR
                            ss.student_number LIKE '%{$search}%' OR
                            s.name LIKE '%{$search}%' OR
                            (c.grade || ' - ' || c.section) LIKE '%{$search}%')";
            }

            // Add section filter if provided
            if (isset($_POST['sectionFilter']) && !empty($_POST['sectionFilter'])) {
                $section = $this->escapeString($_POST['sectionFilter']);
                $where[] = "(c.grade || ' - ' || c.section) = '{$section}'";
            }

            // Add section filter if provided
            if (!empty($sectionFilter)) {
                $where[] = "(c.grade || ' - ' || c.section) = '{$sectionFilter}'";
            }

            // Combine where conditions
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }

            // Get total records count (without any filters)
            $totalRecords = $this->querySingle("SELECT COUNT(*) FROM student_list");

            // Get filtered count (with search and section filter)
            $filteredQuery = "SELECT COUNT(*) as count FROM ($sql) AS filtered";
            $filteredCount = $this->querySingle($filteredQuery);

            // Add sorting and pagination
            $sql .= " ORDER BY {$orderBy} {$orderDir}";
            $sql .= " LIMIT {$length} OFFSET {$start}";

            // Execute the query
            $result = $this->query($sql);
            $pageRows = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $pageRows[] = $row; }

            // Irregular lookup: landing DB holds the source-of-truth for irregular
            // subjects. Batch by student_number for this single page.
            $irregularByNumber = [];
            try {
                require_once("landing_db.php");
                require_once("landing_irregular.php");
                $ldb = landing_db();
                $wantedNums = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)($r['student_number'] ?? '')), $pageRows))));
                if (!empty($wantedNums)) {
                    $escNums = array_map(fn($n) => "'" . $ldb->escapeString($n) . "'", $wantedNums);
                    // chunk to avoid over-long IN (…) 
                    foreach (array_chunk($escNums, 25) as $chunk) {
                        $q = $ldb->query("SELECT student_number, is_irregular, irregular_subjects FROM landing_registrations WHERE student_number IN (" . implode(',', $chunk) . ")");
                        if ($q) { while ($r = $q->fetchArray(SQLITE3_ASSOC)) { $irregularByNumber[trim((string)$r['student_number'])] = $r; } }
                    }
                }
            } catch (Exception $e) { $irregularByNumber = []; }

            $data = [];
            $i = $start + 1;

            // Cache for hasPassed per student+subjectCode+prevYear
            $passedCache = [];
            foreach ($pageRows as $row) {
                // Do NOT display/submit student who already PASSED this subject_code in previous school year (e.g. IT-111 DISCRETE 2025-2026 PASSED -> not in 2026-2027 IT-111 Computer Prog 1)
                $codeCheck = trim($row['course_no'] ?? $row['sname'] ?? '');
                $yearCheck = trim($row['year'] ?? '');
                $prevYearCheck = null;
                if(!empty($yearCheck) && preg_match('/(\d{4})\s*-\s*(\d{4})/', $yearCheck, $mC)) $prevYearCheck=((int)$mC[1]-1).'-'.((int)$mC[2]-1);
                if($codeCheck!=='' && !empty($row['student_number'])){
                    $keyCheck=strtolower(trim($row['student_number'])).'|'.strtolower($codeCheck).'|'.($prevYearCheck??'');
                    if(isset($passedCache[$keyCheck])){ if($passedCache[$keyCheck]) continue; }
                    else {
                        $hasPassed=false;
                        $pats=[__DIR__.'/db/backups/*/*.db', __DIR__.'/db/backups/*/*/*.db', __DIR__.'/db/previous_database/*.db', __DIR__.'/db/grading_dbold.db', __DIR__.'/db/*.db'];
                        $seenP=[];
                        foreach($pats as $pat){ foreach(glob($pat) as $bf){ if(isset($seenP[$bf])) continue; $seenP[$bf]=true; if(basename($bf)==='landing_db.db') continue; if(strpos($bf, DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR)!==false && preg_match('#/db/[^/]+/[^/]+\.db$#',$bf)) continue;
                            try{
                                $bdbP=new SQLite3($bf); $bdbP->enableExceptions(true);
                                $escNumP=$bdbP->escapeString($row['student_number']); $escSubP=$bdbP->escapeString($codeCheck);
                                $sqlP="SELECT pg.remarks, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNumP}' AND TRIM(c.course_no) COLLATE NOCASE='{$escSubP}'";
                                if($prevYearCheck) $sqlP.=" AND TRIM(c.year) COLLATE NOCASE='".$bdbP->escapeString($prevYearCheck)."'";
                                $sqlP.=" LIMIT 1";
                                $qP=$bdbP->query($sqlP);
                                if(!$qP || !($rowP=$qP->fetchArray(SQLITE3_ASSOC))){
                                    $sql2P="SELECT pg.remarks, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN subjects s ON c.subject_id=s.subject_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNumP}' AND TRIM(s.name) COLLATE NOCASE='{$escSubP}'";
                                    if($prevYearCheck) $sql2P.=" AND TRIM(c.year) COLLATE NOCASE='".$bdbP->escapeString($prevYearCheck)."'";
                                    $sql2P.=" LIMIT 1";
                                    $q2P=$bdbP->query($sql2P);
                                    if($q2P && ($row2P=$q2P->fetchArray(SQLITE3_ASSOC))) $rowP=$row2P; else { $bdbP->close(); continue; }
                                }
                                if($rowP){
                                    $remP=strtoupper(trim($rowP['remarks']??'')); if($remP==='PASSED' || $remP==='PASSED WITH DISTINCTION' || $remP==='PASSED WITH HONORS'){ $hasPassed=true; }
                                }
                                $bdbP->close();
                                if($hasPassed) break;
                            }catch(Exception $e){}
                        }}
                        $passedCache[$keyCheck]=$hasPassed;
                        if($hasPassed) continue;
                    }
                }
                $currentStatus = isset($row['status']) && in_array($row['status'], ['Active', 'Dropped','NoSub','NoExam'])
                    ? $row['status']
                    : 'Active';

                // Status badge styling
                $statusClass = match($currentStatus) {
                    'Active'  => 'badge-success',
                    'Dropped' => 'badge-danger',
                    'NoSub'   => 'badge-warning',
                    'NoExam'  => 'badge-warning',
                    default   => 'badge-secondary'
                };

                $action  = '<div class="d-flex align-items-center gap-1 justify-content-center">';

                // Status dropdown (compact, styled)
                $action .= '<select class="form-select form-select-sm status-dropdown" '
                         . 'data-id="' . $row['student_id'] . '" '
                         . 'data-status="' . htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') . '" '
                         . 'style="font-size:9px; min-width:90px; cursor:pointer; padding:0.2rem 0.4rem;" '
                         . 'onchange="handleStatusChange(this)">';
                foreach (['Active' => 'Active', 'Dropped' => 'Dropped', 'NoSub' => 'No Sub', 'NoExam' => 'No Exam'] as $val => $label) {
                    $sel = $currentStatus === $val ? ' selected' : '';
                    $action .= "<option value=\"{$val}\"{$sel}>{$label}</option>";
                }
                $action .= '</select>';

                // Manage dropdown
                $action .= '<div class="dropdown">';
                $action .= '<button class="btn btn-sm btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size:10px; padding:8px 10px;">'
                         . '<i class="fas fa-cog"></i>'
                         . '</button>';
                $action .= '<ul class="dropdown-menu dropdown-menu-end">';
                $action .= '<li><a class="dropdown-item view-student" data-id="' . $row['student_id'] . '" href="javascript:void(0)">'
                         . '<i class="fas fa-eye text-info me-2"></i> View</a></li>';
                $action .= '<li><a class="dropdown-item edit_data" data-id="' . $row['student_id'] . '" href="javascript:void(0)">'
                         . '<i class="fas fa-edit text-primary me-2"></i> Edit</a></li>';
                $action .= '<li><hr class="dropdown-divider"></li>';
                $action .= '<li><a class="dropdown-item delete_data text-danger" data-id="' . $row['student_id'] . '" href="javascript:void(0)">'
                         . '<i class="fas fa-trash-alt me-2"></i> Delete</a></li>';
                $action .= '</ul></div>';

                $action .= '</div>';

                $data[] = [
                    'DT_RowIndex' => $i++,
                    'id' => $row['student_id'], // Add student_id as 'id' for DataTables
                    'student_id' => $row['student_id'], // Also include as student_id for backward compatibility
                    'student_number' => $row['student_number'],
                    'name' => $row['name'],
                    'gender' => $row['gender'],
                    'sname' => $row['sname'],
                    'grade_section' => $row['grade_section'],
                    'status' => $currentStatus, // Add status for row coloring
                    'is_irregular' => 0,
                    'irregular_subjects' => [],
                    'action' => $action
                ];
                // Attach irregular subjects (from landing) when this enrollee is an irregular student.
                $landingIrr = $irregularByNumber[trim((string)($row['student_number'] ?? ''))] ?? null;
                if ($landingIrr && (int)($landingIrr['is_irregular'] ?? 0) === 1) {
                    $entries = function_exists('irregular_entries_of') ? irregular_entries_of($landingIrr) : [];
                    if (!empty($entries)) {
                        $data[count($data) - 1]['is_irregular'] = 1;
                        $data[count($data) - 1]['irregular_subjects'] = $entries;
                    }
                }
            }

            // Prepare the response
            $response = [
                'draw' => $draw,
                'recordsTotal' => intval($totalRecords),
                'recordsFiltered' => intval($filteredCount),
                'data' => $data
            ];

            // Debug information
                $response['debug'] = [
                    'sql' => $sql,
                    'sectionFilter' => $sectionFilter,
                    'where' => $where,
                    'orderColumn' => $orderColumn,
                    'orderDir' => $orderDir,
                    'orderBy' => $orderBy
                ];

        } catch (Exception $e) {
            $response = [
                'draw' => isset($draw) ? $draw : 0,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => $e->getMessage()
            ];
        }

        return json_encode($response);
    }

    public function get_students_for_qr() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['section']) || empty($_POST['section'])) {
                throw new Exception('Section is required');
            }

            $section = $this->escapeString($_POST['section']);

            // Query to get all students in the selected section
            $sql = "SELECT s.student_id, s.student_number, s.name,
                           (c.grade || ' - ' || c.section) as section
                    FROM student_list s
                    INNER JOIN class_list c ON s.class_id = c.class_id
                    WHERE (c.grade || ' - ' || c.section) = '{$section}'
                    ORDER BY s.name ASC";

            $result = $this->query($sql);
            $students = [];

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $students[] = [
                    'student_id' => $row['student_id'],
                    'student_number' => $row['student_number'],
                    'name' => $row['name'],
                    'section' => $row['section']
                ];
            }

            if (empty($students)) {
                throw new Exception('No students found in the selected section');
            }

            $response = [
                'status' => 'success',
                'data' => $students
            ];

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    public function get_class_info_for_print() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            if (!isset($_POST['section']) || empty($_POST['section'])) {
                throw new Exception('Section is required');
            }

            $section = $this->escapeString($_POST['section']);

            // Query to get class information and students for the selected section
            $sql = "SELECT s.student_number, s.name, s.gender,
                           c.course_no, c.grade, c.section, c.semester, c.year, c.schedule, c.unit
                    FROM student_list s
                    INNER JOIN class_list c ON s.class_id = c.class_id
                    WHERE (c.grade || ' - ' || c.section) = '{$section}'
                    ORDER BY s.name ASC";

            $result = $this->query($sql);
            $data = [];

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $data[] = [
                    'student_number' => $row['student_number'],
                    'name' => $row['name'],
                    'gender' => $row['gender'],
                    'course_no' => $row['course_no'],
                    'grade' => $row['grade'],
                    'section' => $row['section'],
                    'semester' => $row['semester'],
                    'year' => $row['year'],
                    'schedule' => $row['schedule'],
                    'unit' => $row['unit']

                ];
            }

            if (empty($data)) {
                throw new Exception('No class information found for the selected section');
            }

            // Get header/footer settings
            $header_settings = $this->getHeaderFooterSettings();

            // Get certified signatories
            $signatory_result = $this->query("SELECT fullname, designation FROM signatories WHERE type = 'certified' ORDER BY signatory_id ASC LIMIT 1");
            $instructor = $signatory_result->fetchArray(SQLITE3_ASSOC);

            $response = [
                'status' => 'success',
                'data' => $data,
                'header_settings' => $header_settings,
                'instructor' => $instructor ? $instructor['fullname'] : 'N/A'
            ];

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    // Grouped directory for the Students folder view: grade -> sections -> distinct students.
    // One lightweight call powers Year folders, Section folders and Student cards.
    public function get_student_folders() {
        header('Content-Type: application/json');
        $resp = ['status' => 'failed', 'msg' => ''];
        try {
            // status column is added by migration on some installs — probe before selecting it
            $hasStatus = false;
            try {
                $cols = $this->query("PRAGMA table_info(student_list)");
                if ($cols) {
                    while ($c = $cols->fetchArray(SQLITE3_ASSOC)) {
                        if (isset($c['name']) && strtolower($c['name']) === 'status') { $hasStatus = true; break; }
                    }
                }
            } catch (Exception $e) { $hasStatus = false; }

            $statusSel = $hasStatus ? "ss.status" : "'Active' AS status";
            $sql = "SELECT ss.student_id, ss.student_number, ss.name, ss.gender, {$statusSel},
                           c.grade, (c.grade || ' - ' || c.section) AS section
                    FROM `student_list` ss
                    INNER JOIN `class_list` c ON ss.class_id = c.class_id
                    ORDER BY c.grade COLLATE NOCASE, c.section COLLATE NOCASE, TRIM(ss.name) COLLATE NOCASE";
            $result = $this->query($sql);

            $tree = [];
            if ($result) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $grade = trim((string)($row['grade'] ?? ''));
                    if ($grade === '') $grade = 'Unassigned';
                    $section = trim((string)($row['section'] ?? ''));
                    if ($section === '') $section = $grade;
                    // One card per student per section (same student_number repeats per subject enrollment)
                    $sn = trim((string)($row['student_number'] ?? ''));
                    $dedupe = $section . '|' . ($sn !== '' ? $sn : '#id' . $row['student_id']);
                    if (!isset($tree[$grade])) $tree[$grade] = ['grade' => $grade, 'sections' => []];
                    if (!isset($tree[$grade]['sections'][$section])) {
                        $tree[$grade]['sections'][$section] = ['section' => $section, 'students' => [], 'seen' => []];
                    }
                    if (isset($tree[$grade]['sections'][$section]['seen'][$dedupe])) continue;
                    $tree[$grade]['sections'][$section]['seen'][$dedupe] = true;
                    $tree[$grade]['sections'][$section]['students'][] = [
                        'student_id' => (int)$row['student_id'],
                        'student_number' => $row['student_number'],
                        'name' => $row['name'],
                        'gender' => $row['gender'] ?? null,
                        'status' => (!empty($row['status']) ? $row['status'] : 'Active')
                    ];
                }
            }

            $data = [];
            $totalSections = 0;
            $totalStudents = 0;
            foreach ($tree as $g) {
                $sections = [];
                $gradeCount = 0;
                foreach ($g['sections'] as $s) {
                    unset($s['seen']);
                    $s['count'] = count($s['students']);
                    $gradeCount += $s['count'];
                    $totalStudents += $s['count'];
                    $totalSections++;
                    $sections[] = $s;
                }
                $data[] = [
                    'grade' => $g['grade'],
                    'section_count' => count($sections),
                    'student_count' => $gradeCount,
                    'sections' => $sections
                ];
            }

            $resp = [
                'status' => 'success',
                'data' => $data,
                'totals' => ['grades' => count($data), 'sections' => $totalSections, 'students' => $totalStudents]
            ];
        } catch (Exception $e) {
            $resp = ['status' => 'failed', 'msg' => $e->getMessage()];
        }
        return json_encode($resp);
    }

    function get_signatories() {
        $response = ['status' => 'error', 'msg' => ''];

        try {
            // Get signatories with type 'certified'
            $sql = "SELECT fullname, designation FROM signatories WHERE type = 'certified' ORDER BY signatory_id ASC";
            $result = $this->query($sql);
            $signatories = [];

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $signatories[] = [
                    'fullname' => $row['fullname'],
                    'designation' => $row['designation']
                ];
            }

            $response = [
                'status' => 'success',
                'data' => $signatories
            ];

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        return json_encode($response);
    }

    public function save_attendance() {
        extract($_POST);

        if(!isset($class_id) || !isset($date) || !isset($quarter) || !isset($attendance_data)) {
            return json_encode(array('status' => 'error', 'msg' => 'Missing required parameters'));
        }

        // Begin transaction
        $this->exec('BEGIN TRANSACTION');

        try {
            // Delete existing attendance for this class, date, and quarter
            $delete = $this->query("DELETE FROM attendance WHERE class_id = '{$class_id}' AND date = '{$date}' AND quarter = '{$quarter}'");

            if(!$delete) {
                throw new Exception("Failed to clear existing attendance records: " . $this->lastErrorMsg());
            }

            // Insert new attendance records
            $attendance_data = json_decode($attendance_data, true);
            $inserted = 0;

            foreach($attendance_data as $student_id => $status) {
                $student_id = $this->escapeString($student_id);
                $status = $this->escapeString($status);

                $sql = "INSERT INTO attendance (class_id, student_id, quarter, date, status, is_clicked, date_created)
                        VALUES ('{$class_id}', '{$student_id}', '{$quarter}', '{$date}', '{$status}', 1, CURRENT_TIMESTAMP)";

                $insert = $this->query($sql);

                if(!$insert) {
                    throw new Exception("Failed to save attendance for student ID: {$student_id} - " . $this->lastErrorMsg());
                }

                $inserted++;
            }

            // Commit transaction
            $this->exec('COMMIT');

            return json_encode(array('status' => 'success', 'msg' => 'Attendance saved successfully', 'records_updated' => $inserted));

        } catch (Exception $e) {
            // Rollback transaction on error
            $this->exec('ROLLBACK');
            return json_encode(array('status' => 'error', 'msg' => $e->getMessage()));
        }
    }

    public function validate_student_scan() {
        extract($_POST);

        // Accepts the students.php QR payload pieces: numeric student_id and/or
        // student_number (e.g. "Student ID: 5 / Student Number: 23-09693").
        $student_id = trim((string)($student_id ?? ''));
        $student_number = trim((string)($student_number ?? ''));
        // Server-side fallback: parse a raw students.php QR blob if sent as one value.
        if ($student_id !== '' && $student_number === '' && !ctype_digit($student_id)) {
            if (preg_match('/Student Number:\s*([^\s]+)/i', $student_id, $nm)) {
                $student_number = trim($nm[1]);
            }
            if (preg_match('/Student ID:\s*(\S+)/i', $student_id, $im)) {
                $student_id = trim($im[1]);
            }
        }

        if(($student_id === '' && $student_number === '') || !isset($class_id) || !isset($quarter)) {
            return json_encode(array('status' => 'error', 'msg' => 'Missing required parameters'));
        }

        // Non-numeric ids can never match the INTEGER student_id column.
        $sid_lookup = ctype_digit($student_id) ? $student_id : '-1';

        try {
            // Enrollment check: student must belong to the class. Grade records
            // (student_initial_grade) are NOT required — attendance must work
            // before any marks exist.
            $stmt = $this->prepare("SELECT s.*, c.grade, c.section, sub.name as subject_name
                                    FROM student_list s
                                    INNER JOIN class_list c ON s.class_id = c.class_id
                                    INNER JOIN subjects sub ON c.subject_id = sub.subject_id
                                    WHERE s.class_id = :class_id
                                    AND (s.student_id = :sid OR s.student_number = :snum)
                                    LIMIT 1");

            $stmt->bindValue(':sid', $sid_lookup, SQLITE3_TEXT);
            $stmt->bindValue(':snum', $student_number, SQLITE3_TEXT);
            $stmt->bindValue(':class_id', $class_id, SQLITE3_TEXT);

            $result = $stmt->execute();
            $student = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;

            if ($student) {
                // Quarter must be valid for this class (custom quarters live in
                // quarter_list; assessments in assessment_list).
                $q_stmt = $this->prepare("SELECT COUNT(*) AS n FROM (
                                            SELECT quarter FROM quarter_list WHERE class_id = :cid1
                                            UNION
                                            SELECT quarter FROM assessment_list WHERE class_id = :cid2
                                          ) WHERE quarter = :quarter");
                $q_stmt->bindValue(':cid1', $class_id, SQLITE3_TEXT);
                $q_stmt->bindValue(':cid2', $class_id, SQLITE3_TEXT);
                $q_stmt->bindValue(':quarter', (int)$quarter, SQLITE3_INTEGER);
                $q_res = $q_stmt->execute();
                $q_row = $q_res ? $q_res->fetchArray(SQLITE3_ASSOC) : null;
                if (!$q_row || (int)$q_row['n'] === 0) {
                    return json_encode(array('status' => 'error', 'msg' => 'Student not found in this class or quarter'));
                }

                // Get class schedule for timestamp reference
                $class_stmt = $this->prepare("SELECT schedule FROM class_list WHERE class_id = :class_id");
                $class_stmt->bindValue(':class_id', $class_id, SQLITE3_TEXT);
                $class_result = $class_stmt->execute();
                $class_data = $class_result ? $class_result->fetchArray(SQLITE3_ASSOC) : null;

                $student['schedule'] = ($class_data['schedule'] ?? null) ?: 'No schedule set';

                return json_encode(array(
                    'status' => 'success',
                    'msg' => 'Student validated successfully',
                    'student_data' => $student
                ));
            } else {
                return json_encode(array('status' => 'error', 'msg' => 'Student not found in this class or quarter'));
            }

        } catch (Exception $e) {
            return json_encode(array('status' => 'error', 'msg' => 'Database error: ' . $e->getMessage()));
        }
    }

    private function provision_faculty_database($facultyId){
        $dbDir = __DIR__ . DIRECTORY_SEPARATOR . 'db';
        if(!is_dir($dbDir)){
            if(!mkdir($dbDir, 0777, true)){
                return ['status' => 'failed', 'msg' => 'Unable to create database directory.'];
            }
        }

        // Create a directory for the faculty if it doesn't exist
        $facultyDir = $dbDir . DIRECTORY_SEPARATOR . $facultyId;
        if(!is_dir($facultyDir)){
            if(!mkdir($facultyDir, 0777, true)){
                return ['status' => 'failed', 'msg' => 'Unable to create faculty directory.'];
            }
        }

        $baseDb = $dbDir . DIRECTORY_SEPARATOR . 'grading_db.db';
        if(!file_exists($baseDb)){
            return ['status' => 'failed', 'msg' => 'Base database not found.'];
        }

        $faculty = $this->query("SELECT * FROM admin_list WHERE admin_id = '{$facultyId}'")->fetchArray(SQLITE3_ASSOC);
        if(!$faculty){
            return ['status' => 'failed', 'msg' => 'Faculty record not found after creation.'];
        }

        // Generate a unique database name with timestamp
        $timestamp = time();
        $targetPath = $facultyDir . DIRECTORY_SEPARATOR . "faculty_{$facultyId}_{$timestamp}.db";

        if(!copy($baseDb, $targetPath)){
            return ['status' => 'failed', 'msg' => 'Failed to duplicate base database.'];
        }

        $newDb = new SQLite3($targetPath);
        if(!$newDb){
            return ['status' => 'failed', 'msg' => 'Unable to initialize faculty database connection.'];
        }

        // Only keep essential tables and clear the rest
        $newDb->exec('PRAGMA foreign_keys = OFF;');

        // Get all tables
        $tables = $newDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $preserveTables = ['transmutation_table']; // Add any other tables that should be preserved

        while($tables && ($row = $tables->fetchArray(SQLITE3_ASSOC))){
            $table = $row['name'];
            if($table === 'admin_list') {
                // Only keep the faculty's own account
                $newDb->exec("DELETE FROM `admin_list`");
                $stmt = $newDb->prepare("INSERT INTO admin_list (admin_id, fullname, username, password, image, status, date_created, role, allowed_pages, db_path)
                                       VALUES (:admin_id, :fullname, :username, :password, :image, :status, :date_created, 'faculty', :allowed_pages, :db_path)");
                $stmt->bindValue(':admin_id', $faculty['admin_id'], SQLITE3_INTEGER);
                $stmt->bindValue(':fullname', $faculty['fullname'], SQLITE3_TEXT);
                $stmt->bindValue(':username', $faculty['username'], SQLITE3_TEXT);
                $stmt->bindValue(':password', $faculty['password'], SQLITE3_TEXT);
                $stmt->bindValue(':image', $faculty['image'], SQLITE3_TEXT);
                $stmt->bindValue(':status', 1, SQLITE3_INTEGER); // Set status to active
                $stmt->bindValue(':date_created', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stmt->bindValue(':allowed_pages', $faculty['allowed_pages'], SQLITE3_TEXT);
                $stmt->bindValue(':db_path', $targetPath, SQLITE3_TEXT);
                $stmt->execute();
            } elseif(!in_array($table, $preserveTables)) {
                // Clear all other tables except preserved ones
                $newDb->exec("DELETE FROM `{$table}`");
            }
        }

        $newDb->close();

        // Update the faculty's db_path in the main database
        $escapedPath = $this->escapeString($targetPath);
        $this->exec("UPDATE admin_list SET db_path = '{$escapedPath}' WHERE admin_id = '{$facultyId}'");

        return ['status' => 'success', 'msg' => 'Faculty database provisioned.', 'path' => $targetPath];
    }

    function provision_database_with_year_auto($facultyId, $username){
        $dbDir = __DIR__ . DIRECTORY_SEPARATOR . 'db';
        if(!is_dir($dbDir)){
            if(!mkdir($dbDir, 0777, true)){
                return ['status' => 'failed', 'msg' => 'Unable to create database directory.'];
            }
        }

        $baseDb = $dbDir . DIRECTORY_SEPARATOR . 'grading_db.db';
        if(!file_exists($baseDb)){
            return ['status' => 'failed', 'msg' => 'Base database not found.'];
        }

        $faculty = $this->query("SELECT * FROM admin_list WHERE admin_id = '{$facultyId}'")->fetchArray(SQLITE3_ASSOC);
        if(!$faculty){
            return ['status' => 'failed', 'msg' => 'Faculty record not found.'];
        }

        // Sanitize username for folder name
        $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($faculty['username']));
        if(empty($safeUsername)){
            $safeUsername = 'faculty_'.$facultyId;
        }

        // Create faculty folder
        $facultyFolder = $dbDir . DIRECTORY_SEPARATOR . $safeUsername;
        if(!is_dir($facultyFolder)){
            if(!mkdir($facultyFolder, 0777, true)){
                return ['status' => 'failed', 'msg' => 'Unable to create faculty folder.'];
            }
        }

        // Check if database already exists in faculty folder
        $existingDb = glob($facultyFolder . DIRECTORY_SEPARATOR . '*.db');
        if(!empty($existingDb)){
            // Use the first existing database
            $targetPath = $existingDb[0];
            // Update db_path if it's different
            if($faculty['db_path'] !== $targetPath){
                $escapedPath = $this->escapeString($targetPath);
                $this->exec("UPDATE admin_list SET db_path = '{$escapedPath}' WHERE admin_id = '{$facultyId}'");
            }
            return ['status' => 'success', 'msg' => 'Using existing faculty database.', 'path' => $targetPath];
        }

        // Generate school year (current)
        $current_month = date('n');
        $current_year = date('Y');
        if ($current_month >= 7) {
            $school_year = $current_year . '-' . ($current_year + 1);
        } else {
            $school_year = ($current_year - 1) . '-' . $current_year;
        }

        // Generate database name with school year
        $dbName = $safeUsername . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $school_year) . '.db';
        $targetPath = $facultyFolder . DIRECTORY_SEPARATOR . $dbName;
        $suffix = 1;
        while(file_exists($targetPath)){
            $targetPath = $facultyFolder . DIRECTORY_SEPARATOR . $safeUsername . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $school_year) . '_' . $suffix++ . '.db';
        }

        if(!copy($baseDb, $targetPath)){
            return ['status' => 'failed', 'msg' => 'Failed to duplicate base database.'];
        }

        $newDb = new SQLite3($targetPath);
        if(!$newDb){
            return ['status' => 'failed', 'msg' => 'Unable to initialize faculty database connection.'];
        }

        // Only keep essential tables and clear the rest
        $newDb->exec('PRAGMA foreign_keys = OFF;');

        // Get all tables
        $tables = $newDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $preserveTables = ['transmutation_table']; // Add any other tables that should be preserved

        while($tables && ($row = $tables->fetchArray(SQLITE3_ASSOC))){
            $table = $row['name'];
            if($table === 'admin_list') {
                // Only keep the faculty's own account
                $newDb->exec("DELETE FROM `admin_list`");
                $stmt = $newDb->prepare("INSERT INTO admin_list (admin_id, fullname, username, password, image, status, date_created, role, allowed_pages, db_path)
                                       VALUES (:admin_id, :fullname, :username, :password, :image, :status, :date_created, 'faculty', :allowed_pages, :db_path)");
                $stmt->bindValue(':admin_id', $faculty['admin_id'], SQLITE3_INTEGER);
                $stmt->bindValue(':fullname', $faculty['fullname'], SQLITE3_TEXT);
                $stmt->bindValue(':username', $faculty['username'], SQLITE3_TEXT);
                $stmt->bindValue(':password', $faculty['password'], SQLITE3_TEXT);
                $stmt->bindValue(':image', $faculty['image'], SQLITE3_TEXT);
                $stmt->bindValue(':status', 1, SQLITE3_INTEGER); // Set status to active
                $stmt->bindValue(':date_created', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stmt->bindValue(':allowed_pages', $faculty['allowed_pages'], SQLITE3_TEXT);
                $stmt->bindValue(':db_path', $targetPath, SQLITE3_TEXT);
                $stmt->execute();
            } elseif(!in_array($table, $preserveTables)) {
                // Clear all other tables except preserved ones
                $newDb->exec("DELETE FROM `{$table}`");
            }
        }

        $newDb->close();

        // Update the faculty's db_path in the main database
        $escapedPath = $this->escapeString($targetPath);
        $this->exec("UPDATE admin_list SET db_path = '{$escapedPath}' WHERE admin_id = '{$facultyId}'");

        return ['status' => 'success', 'msg' => 'Faculty database provisioned.', 'path' => $targetPath];
    }

    function provision_database_with_year(){
        extract($_POST);

        if(!isset($school_year) || !isset($username)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        // Find the faculty by username
        $faculty = $this->query("SELECT admin_id FROM admin_list WHERE username = '" . $this->escapeString($username) . "' AND role = 'faculty'")->fetchArray(SQLITE3_ASSOC);
        if(!$faculty){
            return json_encode(['status' => 'failed', 'msg' => 'Faculty not found.']);
        }

        $facultyId = $faculty['admin_id'];

        $dbDir = __DIR__ . DIRECTORY_SEPARATOR . 'db';
        if(!is_dir($dbDir)){
            if(!mkdir($dbDir, 0777, true)){
                return json_encode(['status' => 'failed', 'msg' => 'Unable to create database directory.']);
            }
        }
        $baseDb = $dbDir . DIRECTORY_SEPARATOR . 'grading_db.db';
        if(!file_exists($baseDb)){
            return json_encode(['status' => 'failed', 'msg' => 'Base database not found.']);
        }

        $facultyData = $this->query("SELECT * FROM admin_list WHERE admin_id = '{$facultyId}'")->fetchArray(SQLITE3_ASSOC);
        if(!$facultyData){
            return json_encode(['status' => 'failed', 'msg' => 'Faculty record not found.']);
        }

        $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($facultyData['username']));
        if(empty($safeUsername)){
            $safeUsername = 'faculty_'.$facultyId;
        }

        // Create faculty folder
        $facultyFolder = $dbDir . DIRECTORY_SEPARATOR . $safeUsername;
        if(!is_dir($facultyFolder)){
            if(!mkdir($facultyFolder, 0777, true)){
                return json_encode(['status' => 'failed', 'msg' => 'Unable to create faculty folder.']);
            }
        }

        // Generate database name with school year
        $dbName = $safeUsername;
        if(!empty($school_year)){
            $dbName .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $school_year);
        }
        $dbName .= '.db';

        $targetPath = $facultyFolder . DIRECTORY_SEPARATOR . $dbName;
        $suffix = 1;
        while(file_exists($targetPath)){
            $targetPath = $facultyFolder . DIRECTORY_SEPARATOR . $dbName . '_' . $suffix++ . '.db';
        }

        if(!copy($baseDb, $targetPath)){
            return json_encode(['status' => 'failed', 'msg' => 'Failed to duplicate base database.']);
        }

        $newDb = new SQLite3($targetPath);
        if(!$newDb){
            return json_encode(['status' => 'failed', 'msg' => 'Unable to initialize faculty database connection.']);
        }

        $newDb->exec('PRAGMA foreign_keys = OFF;');
        $tables = $newDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $preserveTables = ['transmutation_table'];
        while($tables && ($row = $tables->fetchArray(SQLITE3_ASSOC))){
            $table = $row['name'];
            if($table === 'admin_list') continue;
            if(in_array($table, $preserveTables)) continue;
            $newDb->exec("DELETE FROM `{$table}`");
        }

        $newDb->exec("DELETE FROM `admin_list`");
        $stmt = $newDb->prepare("INSERT INTO admin_list (admin_id, fullname, username, password, image, status, date_created, role, allowed_pages, db_path) VALUES (:admin_id,:fullname,:username,:password,:image,:status,:date_created,:role,:allowed_pages,:db_path)");
        $stmt->bindValue(':admin_id', $facultyData['admin_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':fullname', $facultyData['fullname'], SQLITE3_TEXT);
        $stmt->bindValue(':username', $facultyData['username'], SQLITE3_TEXT);
        $stmt->bindValue(':password', $facultyData['password'], SQLITE3_TEXT);
        $stmt->bindValue(':image', $facultyData['image'], SQLITE3_TEXT);
        $stmt->bindValue(':status', $facultyData['status'], SQLITE3_INTEGER);
        $stmt->bindValue(':date_created', $facultyData['date_created'], SQLITE3_TEXT);
        $stmt->bindValue(':role', 'faculty', SQLITE3_TEXT);
        $stmt->bindValue(':allowed_pages', $facultyData['allowed_pages'], SQLITE3_TEXT);
        $stmt->bindValue(':db_path', $targetPath, SQLITE3_TEXT);
        $stmt->execute();

        $newDb->close();

        $escapedPath = $this->escapeString($targetPath);
        $this->exec("UPDATE admin_list SET db_path = '{$escapedPath}' WHERE admin_id = '{$facultyId}'");

        return json_encode(['status' => 'success', 'msg' => 'Faculty database with school year provisioned.', 'path' => $targetPath]);
    }

    function get_class_students(){
        extract($_POST);
        if(!isset($faculty_id) || !isset($class_id)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        // Get faculty database path
        $faculty = $this->query("SELECT db_path FROM admin_list WHERE admin_id = '{$faculty_id}' AND role = 'faculty'")->fetchArray(SQLITE3_ASSOC);
        if(!$faculty || empty($faculty['db_path']) || !file_exists($faculty['db_path'])){
            return json_encode(['status' => 'failed', 'msg' => 'Faculty database not found.']);
        }

        // Connect to faculty database
        $facultyDb = new SQLite3($faculty['db_path']);

        $students = [];
        $qry = $facultyDb->query("SELECT student_id, fullname, status FROM student_list WHERE class_id = '{$class_id}' ORDER BY fullname ASC");
        while($row = $qry->fetchArray(SQLITE3_ASSOC)){
            $students[] = $row;
        }

        $facultyDb->close();

        return json_encode(['status' => 'success', 'data' => $students]);
    }

    function load_attendance_for_date(){
        extract($_POST);
        if(!isset($class_id) || !isset($quarter) || !isset($date)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        try {
            $attendance = [];
            $stmt = $this->prepare("SELECT student_id, status, is_clicked FROM attendance
                                   WHERE class_id = :class_id AND quarter = :quarter AND date = :date");
            $stmt->bindValue(':class_id', $class_id, SQLITE3_INTEGER);
            $stmt->bindValue(':quarter', $quarter, SQLITE3_INTEGER);
            $stmt->bindValue(':date', $date, SQLITE3_TEXT);

            $result = $stmt->execute();
            while($row = $result->fetchArray(SQLITE3_ASSOC)){
                $attendance[$row['student_id']] = [
                    'status' => $row['status'],
                    'is_clicked' => $row['is_clicked']
                ];
            }

            return json_encode(['status' => 'success', 'data' => $attendance]);

        } catch (Exception $e) {
            return json_encode(['status' => 'failed', 'msg' => $e->getMessage()]);
        }
    }

    function save_attendance_reasons(){
        extract($_POST);
        if(!isset($class_id) || !isset($quarter) || !isset($date) || !isset($reasons)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        try {
            $reasons_data = json_decode($reasons, true);

            // Begin transaction
            $this->exec('BEGIN TRANSACTION');

            foreach($reasons_data as $student_id => $reason){
                $student_id = $this->escapeString($student_id);
                $reason = $this->escapeString($reason);

                // Update or insert attendance reason
                $stmt = $this->prepare("UPDATE attendance SET reason = :reason
                                       WHERE class_id = :class_id AND student_id = :student_id
                                       AND quarter = :quarter AND date = :date");
                $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
                $stmt->bindValue(':class_id', $class_id, SQLITE3_INTEGER);
                $stmt->bindValue(':student_id', $student_id, SQLITE3_INTEGER);
                $stmt->bindValue(':quarter', $quarter, SQLITE3_INTEGER);
                $stmt->bindValue(':date', $date, SQLITE3_TEXT);
                $stmt->execute();
            }

            // Commit transaction
            $this->exec('COMMIT');

            return json_encode(['status' => 'success', 'msg' => 'Reasons saved successfully']);

        } catch (Exception $e) {
            // Rollback transaction on error
            $this->exec('ROLLBACK');
            return json_encode(['status' => 'failed', 'msg' => $e->getMessage()]);
        }
    }

    function load_attendance_reasons(){
        extract($_POST);
        if(!isset($class_id) || !isset($quarter) || !isset($date)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        try {
            $reasons = [];
            $stmt = $this->prepare("SELECT student_id, reason FROM attendance
                                   WHERE class_id = :class_id AND quarter = :quarter AND date = :date
                                   AND reason IS NOT NULL AND reason != ''");
            $stmt->bindValue(':class_id', $class_id, SQLITE3_INTEGER);
            $stmt->bindValue(':quarter', $quarter, SQLITE3_INTEGER);
            $stmt->bindValue(':date', $date, SQLITE3_TEXT);

            $result = $stmt->execute();
            while($row = $result->fetchArray(SQLITE3_ASSOC)){
                $reasons[$row['student_id']] = $row['reason'];
            }

            return json_encode(['status' => 'success', 'data' => $reasons]);

        } catch (Exception $e) {
            return json_encode(['status' => 'failed', 'msg' => $e->getMessage()]);
        }
    }

    // ==================== Attendance Confirmation Flow ====================
    // Faculty clicks Start Attendance → creates session + pending requests for all students in class.
    // Student polls and sees confirmation → confirms → Present is auto-checked via attendance table.
    public function start_attendance_session(){
        // Only faculty/admin can start
        if(!isset($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0){
            return json_encode(['status'=>'error','msg'=>'Not authenticated as faculty']);
        }
        $class_id = isset($_POST['class_id']) ? trim($_POST['class_id']) : '';
        $quarter = isset($_POST['quarter']) ? intval($_POST['quarter']) : 0;
        $date = isset($_POST['date']) ? trim($_POST['date']) : date('Y-m-d');
        if(empty($class_id) || empty($quarter) || empty($date)){
            return json_encode(['status'=>'error','msg'=>'Missing class, quarter or date']);
        }
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $date = date('Y-m-d');
        try{
            // Ensure tables exist in this DB (faculty DB or main)
            $this->exec("CREATE TABLE IF NOT EXISTS `attendance_sessions` (
                `session_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `class_id` INTEGER NOT NULL,
                `quarter` INTEGER NOT NULL,
                `date` TEXT NOT NULL,
                `status` TEXT NOT NULL DEFAULT 'active',
                `created_by` INTEGER,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `expires_at` TIMESTAMP,
                FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE,
                UNIQUE(`class_id`, `quarter`, `date`)
            )");
            $this->exec("CREATE TABLE IF NOT EXISTS `attendance_requests` (
                `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `session_id` INTEGER NOT NULL,
                `class_id` INTEGER NOT NULL,
                `quarter` INTEGER NOT NULL,
                `date` TEXT NOT NULL,
                `student_id` INTEGER NOT NULL,
                `student_number` TEXT NOT NULL,
                `status` TEXT NOT NULL DEFAULT 'pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `confirmed_at` TIMESTAMP,
                FOREIGN KEY(`session_id`) REFERENCES `attendance_sessions`(`session_id`) ON DELETE CASCADE,
                FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
                UNIQUE(`session_id`, `student_id`)
            )");
            // Check class exists
            $chk = $this->querySingle("SELECT class_id FROM class_list WHERE class_id = '". $this->escapeString($class_id) ."'", true);
            if(!$chk) return json_encode(['status'=>'error','msg'=>'Class not found in this database']);
            // Check existing active session
            $existing = $this->querySingle("SELECT * FROM attendance_sessions WHERE class_id = '". $this->escapeString($class_id) ."' AND quarter = '". $this->escapeString($quarter) ."' AND date = '". $this->escapeString($date) ."' LIMIT 1", true);
            if($existing){
                // If already active, ensure requests exist for all current students (in case new enrollees)
                $session_id = $existing['session_id'];
                // Count existing requests vs students
                $students = [];
                $sq = $this->query("SELECT student_id, student_number FROM student_list WHERE class_id = '". $this->escapeString($class_id) ."'");
                while($r = $sq->fetchArray(SQLITE3_ASSOC)) $students[] = $r;
                foreach($students as $s){
                    $sid = $this->escapeString($s['student_id']);
                    $snum = $this->escapeString($s['student_number']);
                    $this->exec("INSERT OR IGNORE INTO attendance_requests (session_id, class_id, quarter, date, student_id, student_number, status) VALUES ('$session_id','". $this->escapeString($class_id) ."','". $this->escapeString($quarter) ."','". $this->escapeString($date) ."','$sid','$snum','pending')");
                }
                // Reactivate if ended
                if($existing['status'] !== 'active'){
                    $this->exec("UPDATE attendance_sessions SET status='active' WHERE session_id='$session_id'");
                }
                $cnt = $this->querySingle("SELECT COUNT(*) FROM attendance_requests WHERE session_id='$session_id'");
                return json_encode(['status'=>'success','msg'=>'Attendance session already active','session_id'=>$session_id,'students'=>intval($cnt),'existing'=>true]);
            }
            // Create new session
            $admin_id = intval($_SESSION['admin_id']);
            $this->exec("INSERT INTO attendance_sessions (class_id, quarter, date, status, created_by) VALUES ('". $this->escapeString($class_id) ."','". $this->escapeString($quarter) ."','". $this->escapeString($date) ."','active','$admin_id')");
            $session_id = $this->lastInsertRowID();
            if(!$session_id) throw new Exception('Failed to create session');
            // Create pending requests for all students in class
            $students = [];
            $sq = $this->query("SELECT student_id, student_number FROM student_list WHERE class_id = '". $this->escapeString($class_id) ."'");
            while($r = $sq->fetchArray(SQLITE3_ASSOC)) $students[] = $r;
            $inserted = 0;
            foreach($students as $s){
                $sid = $this->escapeString($s['student_id']);
                $snum = $this->escapeString($s['student_number']);
                if($this->exec("INSERT OR IGNORE INTO attendance_requests (session_id, class_id, quarter, date, student_id, student_number, status) VALUES ('$session_id','". $this->escapeString($class_id) ."','". $this->escapeString($quarter) ."','". $this->escapeString($date) ."','$sid','$snum','pending')")){
                    $inserted++;
                }
            }
            return json_encode(['status'=>'success','msg'=>'Attendance started','session_id'=>$session_id,'students'=>$inserted]);
        } catch(Exception $e){
            return json_encode(['status'=>'error','msg'=>$e->getMessage()]);
        }
    }

    public function get_attendance_session_status(){
        $class_id = isset($_POST['class_id']) ? trim($_POST['class_id']) : '';
        $quarter = isset($_POST['quarter']) ? intval($_POST['quarter']) : 0;
        $date = isset($_POST['date']) ? trim($_POST['date']) : date('Y-m-d');
        if(empty($class_id) || empty($quarter)){
            return json_encode(['status'=>'error','msg'=>'Missing parameters']);
        }
        try{
            $sess = $this->querySingle("SELECT * FROM attendance_sessions WHERE class_id='". $this->escapeString($class_id) ."' AND quarter='". $this->escapeString($quarter) ."' AND date='". $this->escapeString($date) ."' LIMIT 1", true);
            if(!$sess) return json_encode(['status'=>'success','active'=>false,'msg'=>'No active session']);
            $sid = $sess['session_id'];
            $active = ($sess['status'] === 'active');
            // Fetch requests with student names
            $reqs = [];
            $q = $this->query("SELECT r.*, s.name as student_name FROM attendance_requests r LEFT JOIN student_list s ON r.student_id = s.student_id WHERE r.session_id='$sid' ORDER BY s.name ASC");
            while($r = $q->fetchArray(SQLITE3_ASSOC)) $reqs[] = $r;
            // Also fetch current attendance present status for increment display
            $presentCount = $this->querySingle("SELECT COUNT(*) FROM attendance WHERE class_id='". $this->escapeString($class_id) ."' AND quarter='". $this->escapeString($quarter) ."' AND date='". $this->escapeString($date) ."' AND status='present'");
            $total = count($reqs);
            $confirmed = 0; $pending = 0; $absentMarked = 0;
            foreach($reqs as $r){
                if($r['status']==='confirmed') $confirmed++;
                elseif($r['status']==='pending') $pending++;
                elseif($r['status']==='absent') $absentMarked++;
            }
            return json_encode(['status'=>'success','active'=>$active,'session'=>$sess,'requests'=>$reqs,'present_count'=>intval($presentCount),'total'=>$total,'confirmed'=>$confirmed,'pending'=>$pending,'absent_marked'=>$absentMarked]);
        } catch(Exception $e){
            return json_encode(['status'=>'error','msg'=>$e->getMessage()]);
        }
    }

    public function end_attendance_session(){
        if(!isset($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0){
            return json_encode(['status'=>'error','msg'=>'Not authenticated']);
        }
        $class_id = isset($_POST['class_id']) ? trim($_POST['class_id']) : '';
        $quarter = isset($_POST['quarter']) ? intval($_POST['quarter']) : 0;
        $date = isset($_POST['date']) ? trim($_POST['date']) : date('Y-m-d');
        if(empty($class_id) || empty($quarter)) return json_encode(['status'=>'error','msg'=>'Missing parameters']);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $date = date('Y-m-d');
        try{
            $this->exec('BEGIN TRANSACTION');
            $sess = $this->querySingle("SELECT * FROM attendance_sessions WHERE class_id='". $this->escapeString($class_id) ."' AND quarter='". $this->escapeString($quarter) ."' AND date='". $this->escapeString($date) ."' LIMIT 1", true);
            if(!$sess){
                $this->exec('ROLLBACK');
                return json_encode(['status'=>'error','msg'=>'No active session found for this class/date']);
            }
            $session_id = $sess['session_id'];
            // Mark session ended
            $this->exec("UPDATE attendance_sessions SET status='ended' WHERE session_id='$session_id'");
            // Auto-mark all still-pending as absent (instead of discarding)
            $pending = [];
            $q = $this->query("SELECT * FROM attendance_requests WHERE session_id='$session_id' AND status='pending'");
            while($r = $q->fetchArray(SQLITE3_ASSOC)) $pending[] = $r;
            $absentCount = 0;
            foreach($pending as $r){
                $sid = $this->escapeString($r['student_id']);
                $cid = $this->escapeString($r['class_id']);
                $qtr = $this->escapeString($r['quarter']);
                $d = $this->escapeString($r['date']);
                // Only insert absent if student doesn't already have an attendance record for this date (avoid overwriting present/late)
                $exists = $this->querySingle("SELECT attendance_id FROM attendance WHERE class_id='$cid' AND student_id='$sid' AND quarter='$qtr' AND date='$d' LIMIT 1");
                if(!$exists){
                    $this->exec("INSERT INTO attendance (class_id, student_id, quarter, date, status, is_clicked, date_created) VALUES ('$cid','$sid','$qtr','$d','absent',1, CURRENT_TIMESTAMP)");
                    $absentCount++;
                }
                // Mark request as expired/absent so student no longer sees it
                $this->exec("UPDATE attendance_requests SET status='absent', confirmed_at=CURRENT_TIMESTAMP WHERE id='". $this->escapeString($r['id']) ."'");
            }
            $this->exec('COMMIT');
            $msg = 'Session ended';
            if($absentCount>0) $msg .= " — $absentCount pending student(s) automatically marked as absent.";
            else $msg .= ".";
            return json_encode(['status'=>'success','msg'=>$msg,'absent_marked'=>$absentCount]);
        } catch(Exception $e){
            $this->exec('ROLLBACK');
            return json_encode(['status'=>'error','msg'=>$e->getMessage()]);
        }
    }

    // Student side: poll for pending confirmations for this student's student_number
    public function get_pending_attendance_for_student(){
        // Student session has student_number
        $student_number = '';
        if(isset($_SESSION['student_number']) && !empty($_SESSION['student_number'])){
            $student_number = trim($_SESSION['student_number']);
        } elseif(isset($_POST['student_number']) && !empty($_POST['student_number'])){
            $student_number = trim($_POST['student_number']);
        }
        if(empty($student_number) || !preg_match('/^\d{2}-\d{5}$/',$student_number)){
            return json_encode(['status'=>'error','msg'=>'Invalid student number','pending'=>[]]);
        }
        $pending = [];
        try{
            // Live only: an 'active' flag alone is not enough — sessions a
            // faculty member forgot to End stay active for days. The popup
            // must fire only while attendance is actually running TODAY
            // (Asia/Manila, same day Start was pressed).
            $today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            // Search across all DBs (main + faculty subfolders + backups) for active sessions containing this student
            $dbFiles = [];
            $mainDb = __DIR__ . '/db/grading_db.db';
            if(file_exists($mainDb)) $dbFiles[] = $mainDb;
            foreach(glob(__DIR__ . '/db/*.db') as $f){ if(basename($f)!=='grading_db.db' && basename($f)!=='landing_db.db' && !in_array($f,$dbFiles)) $dbFiles[]=$f; }
            foreach(glob(__DIR__ . '/db/*/*.db') as $f){ if(!in_array($f,$dbFiles)) $dbFiles[]=$f; }
            // Also check backups for completeness, but sessions are only in active DBs
            foreach($dbFiles as $dbFile){
                try{
                    $pdo = new PDO('sqlite:'.$dbFile);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    // Check tables exist
                    $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance_sessions'");
                    if(!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) continue;
                    $stmt = $pdo->prepare("SELECT s.session_id, s.class_id, s.quarter, s.date, s.status, s.created_at,
                                                  c.grade, c.section, c.schedule, sub.name as subject_name, q.name as quarter_name,
                                                  r.status as req_status, r.id as request_id
                                           FROM attendance_sessions s
                                           JOIN attendance_requests r ON r.session_id = s.session_id AND r.student_number = :snum AND r.status='pending'
                                           JOIN class_list c ON s.class_id = c.class_id
                                           LEFT JOIN subjects sub ON c.subject_id = sub.subject_id
                                           LEFT JOIN quarter_list q ON q.class_id = c.class_id AND q.quarter = s.quarter
                                           WHERE s.status='active' AND s.date = :today
                                           ORDER BY s.created_at DESC");
                    $stmt->execute([':snum'=>$student_number, ':today'=>$today]);
                    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                        $pending[] = [
                            'db_file'=>basename($dbFile),
                            'session_id'=>$row['session_id'],
                            'request_id'=>$row['request_id'],
                            'class_id'=>$row['class_id'],
                            'quarter'=>$row['quarter'],
                            'quarter_name'=>$row['quarter_name'] ?: ('Quarter '.$row['quarter']),
                            'date'=>$row['date'],
                            'subject_name'=>$row['subject_name'] ?: 'Subject',
                            'grade'=>$row['grade'],
                            'section'=>$row['section'],
                            'schedule'=>$row['schedule'],
                            'status'=>$row['req_status']
                        ];
                    }
                } catch(Exception $e){ continue; }
            }
        } catch(Exception $e){
            return json_encode(['status'=>'error','msg'=>$e->getMessage(),'pending'=>[]]);
        }
        return json_encode(['status'=>'success','pending'=>$pending]);
    }

    public function confirm_attendance(){
        // Student confirms attendance for a specific session/request
        $student_number = isset($_SESSION['student_number']) ? trim($_SESSION['student_number']) : '';
        if(empty($student_number) || !preg_match('/^\d{2}-\d{5}$/',$student_number)){
            return json_encode(['status'=>'error','msg'=>'Not logged in as student']);
        }
        $session_id = isset($_POST['session_id']) ? trim($_POST['session_id']) : '';
        $request_id = isset($_POST['request_id']) ? trim($_POST['request_id']) : '';
        // Also accept class_id/quarter/date as fallback
        $class_id = isset($_POST['class_id']) ? trim($_POST['class_id']) : '';
        $quarter = isset($_POST['quarter']) ? intval($_POST['quarter']) : 0;
        $date = isset($_POST['date']) ? trim($_POST['date']) : '';
        try{
            $found = false; $updatedDbFile = null;
            $dbFiles = [];
            $mainDb = __DIR__ . '/db/grading_db.db';
            if(file_exists($mainDb)) $dbFiles[] = $mainDb;
            foreach(glob(__DIR__ . '/db/*.db') as $f){ if(basename($f)!=='grading_db.db' && basename($f)!=='landing_db.db' && !in_array($f,$dbFiles)) $dbFiles[]=$f; }
            foreach(glob(__DIR__ . '/db/*/*.db') as $f){ if(!in_array($f,$dbFiles)) $dbFiles[]=$f; }
            foreach($dbFiles as $dbFile){
                try{
                    $pdo = new PDO('sqlite:'.$dbFile);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance_requests'");
                    if(!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) continue;
                    // Find the pending request for this student (live sessions only —
                    // same today-gate as the popup poll, so stale sessions can
                    // never be confirmed, even via a crafted POST).
                    $todayConfirm = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
                    $sql = "SELECT r.*, s.class_id as s_class, s.quarter as s_quarter, s.date as s_date FROM attendance_requests r JOIN attendance_sessions s ON r.session_id=s.session_id WHERE r.student_number=:snum AND r.status='pending' AND s.status='active' AND s.date=:today";
                    $params = [':snum'=>$student_number, ':today'=>$todayConfirm];
                    if(!empty($request_id)){
                        $sql .= " AND r.id=:rid";
                        $params[':rid'] = $request_id;
                    } elseif(!empty($session_id)){
                        $sql .= " AND r.session_id=:sid";
                        $params[':sid'] = $session_id;
                    } elseif(!empty($class_id) && !empty($quarter) && !empty($date)){
                        $sql .= " AND r.class_id=:cid AND r.quarter=:q AND r.date=:d";
                        $params[':cid']=$class_id; $params[':q']=$quarter; $params[':d']=$date;
                    }
                    $sql .= " LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $req = $stmt->fetch(PDO::FETCH_ASSOC);
                    if($req){
                        $pdo->beginTransaction();
                        // Check old attendance status for mark adjustment (need before overwrite)
                        $oldAtt = null;
                        $oldStmt = $pdo->prepare("SELECT status FROM attendance WHERE class_id=:cid AND student_id=:sid AND quarter=:q AND date=:d LIMIT 1");
                        $oldStmt->execute([':cid'=>$req['class_id'], ':sid'=>$req['student_id'], ':q'=>$req['quarter'], ':d'=>$req['date']]);
                        if($oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC)) $oldAtt = $oldRow['status'];
                        // Mark request confirmed
                        $pdo->prepare("UPDATE attendance_requests SET status='confirmed', confirmed_at=CURRENT_TIMESTAMP WHERE id=:id")->execute([':id'=>$req['id']]);
                        // Find student_id for attendance table
                        $student_id = $req['student_id'];
                        $cid = $req['class_id']; $q = $req['quarter']; $d = $req['date'];
                        // Insert or replace attendance as present (is_clicked=1)
                        $pdo->prepare("INSERT OR REPLACE INTO attendance (class_id, student_id, quarter, date, status, is_clicked, date_created) VALUES (:cid, :sid, :q, :d, 'present', 1, CURRENT_TIMESTAMP)")->execute([':cid'=>$cid,':sid'=>$student_id,':q'=>$q,':d'=>$d]);
                        // --- Increment Attendance component marks by 1 (mirrors update_marks.php) ---
                        try{
                            $currentDate = date('Y-m-d');
                            if($d === $currentDate){
                                $compStmt = $pdo->prepare("SELECT component_id FROM grading_components WHERE LOWER(name)='attendance' LIMIT 1");
                                $compStmt->execute();
                                if($compRow = $compStmt->fetch(PDO::FETCH_ASSOC)){
                                    $compId = $compRow['component_id'];
                                    $assStmt = $pdo->prepare("SELECT assessment_id FROM assessment_list WHERE class_id=:cid AND component_id=:comp AND quarter=:q");
                                    $assStmt->execute([':cid'=>$cid, ':comp'=>$compId, ':q'=>$q]);
                                    $assessments = $assStmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($assessments as $ass){
                                        $aid = $ass['assessment_id'];
                                        $mkStmt = $pdo->prepare("SELECT mark FROM mark_list WHERE student_id=:sid AND assessment_id=:aid LIMIT 1");
                                        $mkStmt->execute([':sid'=>$student_id, ':aid'=>$aid]);
                                        $mkRow = $mkStmt->fetch(PDO::FETCH_ASSOC);
                                        $curMark = $mkRow ? floatval($mkRow['mark']) : 0;
                                        $exists = (bool)$mkRow;
                                        // Increment only when transitioning to present from non-present (null/absent/late) and date is today — mirrors update_marks.php
                                        if($oldAtt !== 'present' && $oldAtt !== 'excused'){
                                            $newMark = $curMark + 1;
                                            if($exists){
                                                $pdo->prepare("UPDATE mark_list SET mark=:m WHERE student_id=:sid AND assessment_id=:aid")->execute([':m'=>$newMark, ':sid'=>$student_id, ':aid'=>$aid]);
                                            } else {
                                                $pdo->prepare("INSERT INTO mark_list (student_id, assessment_id, mark) VALUES (:sid, :aid, :m)")->execute([':sid'=>$student_id, ':aid'=>$aid, ':m'=>$newMark]);
                                            }
                                        }
                                    }
                                }
                            }
                        } catch(Exception $e){ /* marks increment is best-effort; don't fail attendance */ }
                        $pdo->commit();
                        $found = true; $updatedDbFile = $dbFile;
                        break;
                    }
                } catch(Exception $e){ if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); continue; }
            }
            if($found){
                return json_encode(['status'=>'success','msg'=>'Attendance confirmed — marked as present','db'=>basename($updatedDbFile)]);
            } else {
                return json_encode(['status'=>'error','msg'=>'No pending attendance found for your student number. It may have already been confirmed or expired.']);
            }
        } catch(Exception $e){
            return json_encode(['status'=>'error','msg'=>$e->getMessage()]);
        }
    }

    public function unconfirm_attendance(){
        if(!isset($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0){
            return json_encode(['status'=>'error','msg'=>'Not authenticated as faculty']);
        }
        $class_id = isset($_POST['class_id']) ? trim($_POST['class_id']) : '';
        $quarter = isset($_POST['quarter']) ? intval($_POST['quarter']) : 0;
        $date = isset($_POST['date']) ? trim($_POST['date']) : date('Y-m-d');
        $student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
        if(empty($class_id) || empty($quarter) || empty($date) || empty($student_id)){
            return json_encode(['status'=>'error','msg'=>'Missing parameters']);
        }
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $date = date('Y-m-d');
        try{
            // Find the most recent session for this class/quarter/date (active or ended)
            $sess = $this->querySingle("SELECT * FROM attendance_sessions WHERE class_id='". $this->escapeString($class_id) ."' AND quarter='". $this->escapeString($quarter) ."' AND date='". $this->escapeString($date) ."' ORDER BY session_id DESC LIMIT 1", true);
            if(!$sess){
                return json_encode(['status'=>'error','msg'=>'No attendance session found for this class/date']);
            }
            $session_id = $sess['session_id'];
            // Find the request for this student
            $req = $this->querySingle("SELECT * FROM attendance_requests WHERE session_id='$session_id' AND student_id='". $this->escapeString($student_id) ."' LIMIT 1", true);
            if(!$req){
                return json_encode(['status'=>'error','msg'=>'No attendance request found for this student']);
            }
            if($req['status'] !== 'confirmed'){
                return json_encode(['status'=>'error','msg'=>'Student has not confirmed yet (status: '.$req['status'].')']);
            }
            $this->exec('BEGIN TRANSACTION');
            // Revert request to pending so student can confirm again
            $this->exec("UPDATE attendance_requests SET status='pending', confirmed_at=NULL WHERE id='". $this->escapeString($req['id']) ."'");
            // Remove the present attendance record (faculty unconfirmed) — return to inactive state
            $cid = $this->escapeString($class_id);
            $sid = $this->escapeString($student_id);
            $qtr = $this->escapeString($quarter);
            $d = $this->escapeString($date);
            // Capture old status for mark revert (should be present)
            $oldAttRow = $this->querySingle("SELECT status FROM attendance WHERE class_id='$cid' AND student_id='$sid' AND quarter='$qtr' AND date='$d' LIMIT 1", true);
            $oldStatus = $oldAttRow ? $oldAttRow['status'] : null;
            $this->exec("DELETE FROM attendance WHERE class_id='$cid' AND student_id='$sid' AND quarter='$qtr' AND date='$d'");
            // --- Decrement Attendance component marks by 1 (return to original value) ---
            try{
                $currentDate = date('Y-m-d');
                if($d === $currentDate && $oldStatus === 'present'){
                    $compRow = $this->querySingle("SELECT component_id FROM grading_components WHERE LOWER(name)='attendance' LIMIT 1", true);
                    if($compRow){
                        $compId = $compRow['component_id'];
                        $assRes = $this->query("SELECT assessment_id FROM assessment_list WHERE class_id='$cid' AND component_id='$compId' AND quarter='$qtr'");
                        while($ass = $assRes->fetchArray(SQLITE3_ASSOC)){
                            $aid = $ass['assessment_id'];
                            $mkRow = $this->querySingle("SELECT mark FROM mark_list WHERE student_id='$sid' AND assessment_id='$aid' LIMIT 1", true);
                            if($mkRow){
                                $curMark = floatval($mkRow['mark']);
                                $newMark = max(0, $curMark - 1);
                                $this->exec("UPDATE mark_list SET mark='$newMark' WHERE student_id='$sid' AND assessment_id='$aid'");
                            }
                        }
                    }
                }
            } catch(Exception $e){ /* best-effort */ }
            $this->exec('COMMIT');
            return json_encode(['status'=>'success','msg'=>'Attendance unconfirmed — returned to inactive, marks reverted','session_id'=>$session_id]);
        } catch(Exception $e){
            $this->exec('ROLLBACK');
            return json_encode(['status'=>'error','msg'=>$e->getMessage()]);
        }
    }

    function get_class_attendance(){
        extract($_POST);
        if(!isset($faculty_id) || !isset($class_id)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        // Get faculty database path
        $faculty = $this->query("SELECT db_path FROM admin_list WHERE admin_id = '{$faculty_id}' AND role = 'faculty'")->fetchArray(SQLITE3_ASSOC);
        if(!$faculty || empty($faculty['db_path']) || !file_exists($faculty['db_path'])){
            return json_encode(['status' => 'failed', 'msg' => 'Faculty database not found.']);
        }

        // Connect to faculty database
        $facultyDb = new SQLite3($faculty['db_path']);

        $attendance = [];
        $qry = $facultyDb->query("SELECT
            date,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
            FROM attendance WHERE class_id = '{$class_id}'
            GROUP BY date ORDER BY date DESC");
        while($row = $qry->fetchArray(SQLITE3_ASSOC)){
            $attendance[] = $row;
        }

        $facultyDb->close();

        return json_encode(['status' => 'success', 'data' => $attendance]);
    }

    function get_class_grades(){
        extract($_POST);
        if(!isset($faculty_id) || !isset($class_id)){
            return json_encode(['status' => 'failed', 'msg' => 'Missing required parameters.']);
        }

        // Get faculty database path
        $faculty = $this->query("SELECT db_path FROM admin_list WHERE admin_id = '{$faculty_id}' AND role = 'faculty'")->fetchArray(SQLITE3_ASSOC);
        if(!$faculty || empty($faculty['db_path']) || !file_exists($faculty['db_path'])){
            return json_encode(['status' => 'failed', 'msg' => 'Faculty database not found.']);
        }

        // Connect to faculty database
        $facultyDb = new SQLite3($faculty['db_path']);

        $grades = [];
        $qry = $facultyDb->query("SELECT
            sl.fullname as student_name,
            a.assessment_name,
            m.score,
            m.total_score
            FROM marks m
            JOIN assessments a ON m.assessment_id = a.assessment_id
            JOIN student_list sl ON m.student_id = sl.student_id
            WHERE a.class_id = '{$class_id}'
            ORDER BY sl.fullname, a.assessment_name");
        while($row = $qry->fetchArray(SQLITE3_ASSOC)){
            $grades[] = $row;
        }

        $facultyDb->close();

        return json_encode(['status' => 'success', 'data' => $grades]);
    }

    // ------------------------------------------------------------------
    // Department / course catalog (admin/departments.php)
    //
    // Writes always target the MAIN database through its own connection,
    // never the session-selected faculty database, so the catalog that
    // admin/faculty.php and the public landingpage.php read stays the same
    // for everyone.
    // ------------------------------------------------------------------
    private function departments_connection()
    {
        // The catalog drives the public registration form, so these writes must
        // never be reachable without a logged-in session.
        if (empty($_SESSION['admin_id']) || (int)$_SESSION['admin_id'] <= 0) {
            throw new Exception('Not authenticated.');
        }
        require_once(__DIR__ . '/departments.php');
        $conn = departments_db();
        if (!$conn instanceof SQLite3) {
            throw new Exception('Department catalog database is unavailable.');
        }
        ensure_departments_schema($conn);
        return $conn;
    }

    function save_department()
    {
        $code = strtoupper(trim((string)($_POST['dept_code'] ?? '')));
        $name = trim((string)($_POST['dept_name'] ?? ''));
        $original = strtoupper(trim((string)($_POST['original_code'] ?? '')));

        if ($code === '' || $name === '') {
            return json_encode(['status' => 'failed', 'msg' => 'Department code and name are required.']);
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-]{0,9}$/', $code)) {
            return json_encode(['status' => 'failed', 'msg' => 'Code must be 1-10 characters (letters, numbers or dash only).']);
        }

        try {
            $conn = $this->departments_connection();
            $escCode = $conn->escapeString($code);
            $escName = $conn->escapeString($name);

            $clash = $conn->query("SELECT `dept_code` FROM `departments` WHERE `dept_code` = '{$escCode}'")->fetchArray();
            $isRename = ($original !== '' && $original !== $code);
            if ($clash && !$isRename) {
                return json_encode(['status' => 'failed', 'msg' => "Department code {$code} already exists."]);
            }

            if ($isRename) {
                $escOriginal = $conn->escapeString($original);
                // Keep every faculty assignment pointing at the renamed code.
                if ($conn->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_list'")) {
                    $hasProgram = $conn->querySingle("SELECT COUNT(*) FROM pragma_table_info('admin_list') WHERE name = 'program'");
                    if ($hasProgram > 0) {
                        @$conn->exec("UPDATE `admin_list` SET `program` = '{$escCode}' WHERE `program` = '{$escOriginal}'");
                    }
                }
                @$conn->exec("UPDATE `department_courses` SET `dept_code` = '{$escCode}' WHERE `dept_code` = '{$escOriginal}'");
            }

            if ($isRename || $original !== '') {
                $escOriginal = $conn->escapeString($original);
                $ok = $conn->exec("UPDATE `departments` SET `dept_code` = '{$escCode}', `dept_name` = '{$escName}' WHERE `dept_code` = '{$escOriginal}'");
                if (!$ok || $conn->changes() < 1) {
                    return json_encode(['status' => 'failed', 'msg' => 'Department not found.']);
                }
            } else {
                $maxOrder = (int)$conn->querySingle("SELECT COALESCE(MAX(`sort_order`), 0) FROM `departments`");
                $ok = $conn->exec("INSERT INTO `departments` (`dept_code`, `dept_name`, `sort_order`) VALUES ('{$escCode}', '{$escName}', " . ($maxOrder + 1) . ")");
                if (!$ok) {
                    return json_encode(['status' => 'failed', 'msg' => 'Failed to save department.']);
                }
            }

            $_SESSION['flashdata'] = ['type' => 'success', 'msg' => "Department {$code} saved successfully."];
            return json_encode(['status' => 'success', 'msg' => "Department {$code} saved successfully."]);
        } catch (Throwable $e) {
            error_log('save_department: ' . $e->getMessage());
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while saving the department.']);
        }
    }

    function delete_department()
    {
        $code = strtoupper(trim((string)($_POST['dept_code'] ?? '')));
        if ($code === '') {
            return json_encode(['status' => 'failed', 'msg' => 'No department selected.']);
        }

        try {
            $conn = $this->departments_connection();
            $escCode = $conn->escapeString($code);

            // Refuse while faculty accounts still reference the department -
            // deleting it would silently blank their Department column.
            if ($conn->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_list'")) {
                $hasProgram = $conn->querySingle("SELECT COUNT(*) FROM pragma_table_info('admin_list') WHERE name = 'program'");
                if ($hasProgram > 0) {
                    $inUse = (int)$conn->querySingle("SELECT COUNT(*) FROM `admin_list` WHERE `program` = '{$escCode}'");
                    if ($inUse > 0) {
                        return json_encode([
                            'status' => 'failed',
                            'msg'    => "{$code} is still assigned to {$inUse} faculty account(s). Reassign them first.",
                        ]);
                    }
                }
            }

            $conn->exec("DELETE FROM `department_courses` WHERE `dept_code` = '{$escCode}'");
            $deleted = $conn->exec("DELETE FROM `departments` WHERE `dept_code` = '{$escCode}'");
            if (!$deleted || $conn->changes() < 1) {
                return json_encode(['status' => 'failed', 'msg' => 'Department not found.']);
            }

            $_SESSION['flashdata'] = ['type' => 'success', 'msg' => "Department {$code} and its courses were deleted."];
            return json_encode(['status' => 'success', 'msg' => "Department {$code} deleted."]);
        } catch (Throwable $e) {
            error_log('delete_department: ' . $e->getMessage());
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while deleting the department.']);
        }
    }

    function save_department_course()
    {
        $code = strtoupper(trim((string)($_POST['dept_code'] ?? '')));
        $course = strtoupper(trim((string)($_POST['course'] ?? '')));

        if ($code === '' || $course === '') {
            return json_encode(['status' => 'failed', 'msg' => 'Choose a department and enter a course.']);
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9\- ]*[A-Z0-9]$|^[A-Z0-9]$/', $course) || strlen($course) > 30) {
            return json_encode(['status' => 'failed', 'msg' => 'Course must be 1-30 characters (letters, numbers, spaces or dash).']);
        }

        try {
            $conn = $this->departments_connection();
            $escCode = $conn->escapeString($code);
            $escCourse = $conn->escapeString($course);

            $dept = $conn->querySingle("SELECT COUNT(*) FROM `departments` WHERE `dept_code` = '{$escCode}'");
            if ((int)$dept < 1) {
                return json_encode(['status' => 'failed', 'msg' => 'That department no longer exists.']);
            }
            $dupe = $conn->querySingle("SELECT COUNT(*) FROM `department_courses` WHERE `dept_code` = '{$escCode}' AND `course` = '{$escCourse}'");
            if ((int)$dupe > 0) {
                return json_encode(['status' => 'failed', 'msg' => "{$course} is already listed under {$code}."]);
            }

            $maxOrder = (int)$conn->querySingle("SELECT COALESCE(MAX(`sort_order`), 0) FROM `department_courses` WHERE `dept_code` = '{$escCode}'");
            $ok = $conn->exec("INSERT INTO `department_courses` (`dept_code`, `course`, `sort_order`) VALUES ('{$escCode}', '{$escCourse}', " . ($maxOrder + 1) . ")");
            if (!$ok) {
                return json_encode(['status' => 'failed', 'msg' => 'Failed to add the course.']);
            }

            $_SESSION['flashdata'] = ['type' => 'success', 'msg' => "{$course} added to {$code}."];
            return json_encode(['status' => 'success', 'msg' => "{$course} added to {$code}."]);
        } catch (Throwable $e) {
            error_log('save_department_course: ' . $e->getMessage());
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while adding the course.']);
        }
    }

    function delete_department_course()
    {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId < 1) {
            return json_encode(['status' => 'failed', 'msg' => 'No course selected.']);
        }

        try {
            $conn = $this->departments_connection();
            $course = $conn->querySingle("SELECT `course` FROM `department_courses` WHERE `course_id` = " . $courseId);
            $deleted = $conn->exec("DELETE FROM `department_courses` WHERE `course_id` = " . $courseId);
            if (!$deleted || $conn->changes() < 1) {
                return json_encode(['status' => 'failed', 'msg' => 'Course not found.']);
            }

            $msg = "Course " . ($course ?: $courseId) . " deleted.";
            $_SESSION['flashdata'] = ['type' => 'success', 'msg' => $msg];
            return json_encode(['status' => 'success', 'msg' => $msg]);
        } catch (Throwable $e) {
            error_log('delete_department_course: ' . $e->getMessage());
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while deleting the course.']);
        }
    }
}
$a = isset($_REQUEST['a']) ? $_REQUEST['a'] : (isset($_REQUEST['action']) ? $_REQUEST['action'] : '');
// Skip auto-dispatch when included (students/index.php / attendance.php call methods directly).
// Without this guard, including Actions.php while $_POST['action']='confirm_attendance'
// would echo once here AND again in the includer -> concatenated JSON "{...}{...}"
// which breaks JSON.parse on the student side.
$__isDirectActionsRequest = false;
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $__isDirectActionsRequest = (basename((string)$_SERVER['SCRIPT_FILENAME']) === 'Actions.php');
} elseif (isset($_SERVER['SCRIPT_NAME'])) {
    $__isDirectActionsRequest = (basename((string)$_SERVER['SCRIPT_NAME']) === 'Actions.php');
}
if (!$__isDirectActionsRequest) {
    return;
}
$action = new Actions();

// Handle template-related actions
if (in_array($a, ['upload_template', 'update_template', 'delete_template', 'get_template', 'download_template', 'use_template'])) {
    // For file uploads, we need to handle them differently
    if ($a === 'upload_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo $action->upload_template();
        exit;
    }

    // For file downloads, we need to handle the output directly
    if ($a === 'download_template') {
        $action->download_template();
        exit;
    }

    // For other template actions
    if (method_exists($action, $a)) {
        echo $action->$a();
        exit;
    }
}

// Handle other actions
switch($a) {
    case 'get_students':
        header('Content-Type: application/json');
        echo $action->get_students();
        exit;
    case 'get_student_details':
        echo $action->get_student_details();
        exit;
    case 'save_signatory':
        echo $action->save_signatory();
        break;
    case 'login':
        echo $action->login();
        break;
    case 'customer_login':
        echo $action->customer_login();
        break;
    case 'logout':
        echo $action->logout();
        break;
    case 'save_user':
        echo $action->save_user();
    break;
    case 'delete_user':
        echo $action->delete_user();
    break;
    case 'save_department':
        echo $action->save_department();
    break;
    case 'delete_department':
        echo $action->delete_department();
    break;
    case 'save_department_course':
        echo $action->save_department_course();
    break;
    case 'delete_department_course':
        echo $action->delete_department_course();
    break;
    case 'update_credentials':
        echo $action->update_credentials();
    break;
    case 'save_subject':
        echo $action->save_subject();
    break;
    case 'delete_subject':
        echo $action->delete_subject();
    break;
    case 'save_component':
        echo $action->save_component();
    break;
    case 'delete_component':
        echo $action->delete_component();
    break;
    case 'get_components':
        echo $action->get_components();
    break;
    case 'get_components_for_class':
        echo $action->get_components_for_class();
    break;
    case 'get_subjects':
        echo $action->get_subjects();
    break;
    case 'get_classes':
        echo $action->get_classes();
    break;
    case 'get_quarters':
        echo $action->get_quarters();
    break;
    case 'save_quarter':
        echo $action->save_quarter();
    break;
    case 'update_quarter':
        echo $action->update_quarter();
    break;
    case 'delete_quarter':
        echo $action->delete_quarter();
    break;
    case 'save_percentage':
        echo $action->save_percentage();
    break;
    case 'save_class':
        echo $action->save_class();
    break;
    case 'delete_class':
        echo $action->delete_class();
    break;
    case 'save_student':
        echo $action->save_student();
    break;
    case 'update_student_status':
        echo $action->update_student_status();
    break;
    case 'delete_student':
        echo $action->delete_student();
    break;
    case 'delete_multiple_students':
        echo $action->delete_multiple_students();
    break;
    case 'save_assessment':
        echo $action->save_assessment();
    break;
    case 'delete_assessment':
        echo $action->delete_assessment();
    break;
    case 'save_mark':
        echo $action->save_mark();
    break;
    case 'bulk_save_students':
        echo $action->bulk_save_students();
        break;
    case 'get_students_for_qr':
        echo $action->get_students_for_qr();
    break;
    case 'get_class_info_for_print':
        echo $action->get_class_info_for_print();
    break;
    case 'get_student_folders':
        echo $action->get_student_folders();
    break;
    case 'get_signatories':
        echo $action->get_signatories();
    break;
    case 'get_remaining_percentage':
        echo $action->get_remaining_percentage();
    break;
    case 'get_component_suggested_percentage':
        echo $action->get_component_suggested_percentage();
    break;
    case 'save_attendance':
        echo $action->save_attendance();
    break;
    case 'load_attendance_for_date':
        echo $action->load_attendance_for_date();
    break;
    case 'save_attendance_reasons':
        echo $action->save_attendance_reasons();
    break;
    case 'load_attendance_reasons':
        echo $action->load_attendance_reasons();
    break;
    case 'start_attendance_session':
        echo $action->start_attendance_session();
    break;
    case 'get_attendance_session_status':
        echo $action->get_attendance_session_status();
    break;
    case 'end_attendance_session':
        echo $action->end_attendance_session();
    break;
    case 'get_pending_attendance_for_student':
        echo $action->get_pending_attendance_for_student();
    break;
    case 'confirm_attendance':
        echo $action->confirm_attendance();
    break;
    case 'unconfirm_attendance':
        echo $action->unconfirm_attendance();
    break;
    case 'validate_student_scan':
        echo $action->validate_student_scan();
    break;
    case 'get_class_students':
        echo $action->get_class_students();
    break;
    case 'get_class_attendance':
        echo $action->get_class_attendance();
    break;
    case 'get_class_grades':
        echo $action->get_class_grades();
    break;
    case 'provision_database_with_year':
        echo $action->provision_database_with_year();
    break;
    case 'save_assessment_date':
        echo $action->save_assessment_date();
    break;
    case 'get_assessment':
        echo $action->get_assessment();
    break;
    default:
    // default action here
    break;
}
