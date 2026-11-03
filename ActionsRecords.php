<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once('DBRecords.php');

Class ActionsRecords extends DBRecords{
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
        if(empty($fullname) || empty($designation) || empty($type)){
            return json_encode(['status' => 'failed', 'msg' => 'All fields are required.']);
        }
        
        // Validate type
        $validTypes = ['certified', 'checked', 'received'];
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
        }else{
            $resp['status'] = "success";
            $resp['msg'] = "Login successfully.";
            foreach($qry as $k => $v){
                if(!is_numeric($k))
                $_SESSION[$k] = $v;
            }
        }
        return json_encode($resp);
    }
    function logout(){
        session_destroy();
        header("location:./");
    }
    function save_user(){
        extract($_POST);
        $data = "";
        foreach($_POST as $k => $v){
        if(!in_array($k,array('id','type'))){
            if(!empty($id)){
                if(!empty($data)) $data .= ",";
                $data .= " `{$k}` = '{$v}' ";
                }else{
                    $cols[] = $k;
                    $values[] = "'{$v}'";
                }
            }
        }
        if(empty($id)){
            $cols[] = 'password';
            $values[] = "'".md5($username)."'";
        }
        if(isset($cols) && isset($values)){
            $data = "(".implode(',',$cols).") VALUES (".implode(',',$values).")";
        }
        

       
        @$check= $this->query("SELECT count(admin_id) as `count` FROM admin_list where `username` = '{$username}' ".($id > 0 ? " and admin_id != '{$id}' " : ""))->fetchArray()['count'];
        if(@$check> 0){
            $resp['status'] = 'failed';
            $resp['msg'] = "Username already exists.";
        }else{
            if(empty($id)){
                $sql = "INSERT INTO `admin_list` {$data}";
            }else{
                $sql = "UPDATE `admin_list` set {$data} where admin_id = '{$id}'";
            }
            @$save = $this->query($sql);
            if($save){
                $resp['status'] = 'success';
                if(empty($id))
                $resp['msg'] = 'New Admin User successfully saved.';
                else
                $resp['msg'] = 'Admin User Details successfully updated.';
            }else{
                $resp['status'] = 'failed';
                $resp['msg'] = 'Saving Admin User Details Failed. Error: '.$this->lastErrorMsg();
                $resp['sql'] =$sql;
            }
        }
        return json_encode($resp);
    }
    function delete_user(){
        extract($_POST);

        @$delete = $this->query("DELETE FROM `admin_list` where rowid = '{$id}'");
        if($delete){
            $resp['status']='success';
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Admin User successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }
    function update_credentials(){
        extract($_POST);
        $data = "";
        
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
                    $data .= " `image` = '{$targetPath}' ";
                    // Update session image if it's the current user
                    if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] == $id) {
                        $_SESSION['image'] = $targetPath;
                    }
                }
            }
        }
        
        // Process other form fields
        foreach($_POST as $k => $v){
            if(!in_array($k,array('id','old_password','user_image')) && !empty($v)){
                if(!empty($data)) $data .= ",";
                if($k == 'password') $v = md5($v);
                $data .= " `{$k}` = '{$v}' ";
            }
        }
        if(!empty($password) && md5($old_password) != $_SESSION['password']){
            $resp['status'] = 'failed';
            $resp['msg'] = "Old password is incorrect.";
        }else{
            $sql = "UPDATE `admin_list` set {$data} where admin_id = '{$_SESSION['admin_id']}'";
            @$save = $this->query($sql);
            if($save){
                $resp['status'] = 'success';
                $_SESSION['flashdata']['type'] = 'success';
                $_SESSION['flashdata']['msg'] = 'Credential successfully updated.';
                foreach($_POST as $k => $v){
                    if(!in_array($k,array('id','old_password','user_image')) && !empty($v)){
                        if(!empty($data)) $data .= ",";
                        if($k == 'password') $v = md5($v);
                        $_SESSION[$k] = $v;
                    }
                }
            }else{
                $resp['status'] = 'failed';
                $resp['msg'] = 'Updating Credentials Failed. Error: '.$this->lastErrorMsg();
                $resp['sql'] =$sql;
            }
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
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Subject successfully deleted.';
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
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Component successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
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
                 if(!in_array($k,array('id'))){
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
                if(empty($id))
                    $resp['msg'] = "Class successfully saved.";
                else
                    $resp['msg'] = "Class successfully updated.";
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
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';

            // Validate required fields
            if (empty($class_id)) {
                $resp['msg'] = 'Class is required';
                return json_encode($resp);
            }
            
            if (empty($student_number)) {
                $resp['msg'] = 'Student number is required';
                return json_encode($resp);
            }
            
            if (empty($name)) {
                $resp['msg'] = 'Student name is required';
                return json_encode($resp);
            }
            
            // Validate student number format (e.g., 25-12345)
            if(!preg_match('/^\d{2}-\d{5}$/', $student_number)) {
                $resp['msg'] = 'Please enter a valid student number (e.g., 25-12345)';
                return json_encode($resp);
            }

            // Check if student number already exists in the same class
            $exclude = $id > 0 ? " and s.student_id != '{$id}'" : "";
            $sql_check_sn = "SELECT COUNT(s.student_id) as count 
                            FROM `student_list` s 
                            INNER JOIN `class_list` c1 ON s.class_id = c1.class_id
                            WHERE s.`student_number` = :student_number 
                            AND c1.grade = (SELECT grade FROM `class_list` WHERE class_id = :class_id)
                            AND c1.section = (SELECT section FROM `class_list` WHERE class_id = :class_id2)
                            AND c1.subject_id != (SELECT subject_id FROM `class_list` WHERE class_id = :class_id3)
                            {$exclude}";
            
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

            // Prepare the SQL query based on insert or update
            if(empty($id)) {
                $sql = "INSERT INTO `student_list` (`class_id`, `student_number`, `name`) VALUES(:class_id, :student_number, :name)";
                $stmt = $this->prepare($sql);
                $stmt->bindValue(':class_id', $class_id, SQLITE3_TEXT);
                $stmt->bindValue(':student_number', $student_number, SQLITE3_TEXT);
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            } else {
                $sql = "UPDATE `student_list` SET `class_id` = :class_id, `student_number` = :student_number, `name` = :name WHERE `student_id` = :id";
                $stmt = $this->prepare($sql);
                $stmt->bindValue(':class_id', $class_id, SQLITE3_TEXT);
                $stmt->bindValue(':student_number', $student_number, SQLITE3_TEXT);
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            }
        
            // Check for duplicate student in the same class
            $sql_check_name = "SELECT COUNT(student_id) as count from `student_list` where `class_id` = :class_id and `name` = :name";
            if($id > 0) {
                $sql_check_name .= " and student_id != :student_id";
            }
            
            $stmt_check = $this->prepare($sql_check_name);
            $stmt_check->bindValue(':class_id', $class_id, SQLITE3_TEXT);
            $stmt_check->bindValue(':name', $name, SQLITE3_TEXT);
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
            
            if($check > 0) {
                $resp['msg'] = 'A student with the same name already exists in this class.';
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
    function save_assessment(){
        extract($_POST);
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
            // Check if component is not Quizzes and validate percentage
            $component_check = $this->query("SELECT name FROM `grading_components` WHERE `component_id` = '{$component_id}'")->fetchArray()['name'];
            
            if ($component_check !== 'Quizzes') {
                // Calculate total percentage for this component, class, and quarter
                $total_percentage = $this->query("SELECT COALESCE(SUM(percentage), 0) as total FROM `assessment_list` WHERE `class_id` = '{$class_id}' AND `component_id` = '{$component_id}' AND `quarter` = '{$quarter}' ".($id > 0 ? " AND assessment_id != '{$id}'" : ""))->fetchArray()['total'];
                $new_total = $total_percentage + $percentage;
                
                if ($new_total > 100) {
                    $resp['status'] = 'failed';
                    $resp['msg'] = 'Total percentage for this component cannot exceed 100%. Current total: ' . $total_percentage . '%, New total would be: ' . $new_total . '%';
                    return json_encode($resp);
                }
            }
            @$save = $this->query($sql);
            if($save){
                $resp['status']="success";
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
    function delete_assessment(){
        extract($_POST);

        @$delete = $this->query("DELETE FROM `assessment_list` where assessment_id = '{$id}'");
        if($delete){
            $resp['status']='success';
            $_SESSION['flashdata']['type'] = 'success';
            $_SESSION['flashdata']['msg'] = 'Assessment successfully deleted.';
        }else{
            $resp['status']='failed';
            $resp['error']=$this->lastErrorMsg();
        }
        return json_encode($resp);
    }
    function save_mark(){
        extract($_POST);
        $data = "";
        foreach($student_id as $k => $v){
            if(!empty($data)) $data .=", ";
            $data .= "('{$assessment_id}','{$v}','{$mark[$k]}')";
        }
        if(!empty($data)){
            $this->query("DELETE  FROM `mark_list` where assessment_id = '{$assessment_id}'");
            $sql = "INSERT INTO `mark_list` (`assessment_id`,`student_id`,`mark`) VALUES {$data}";
            $save = $this->query($sql);
            if($save){
                $resp['status'] = 'success';
                $resp['msg'] = " Marks Successfuly Saved";
            }else{
                $resp['status']='failed';
                $resp['msg'] = "Saving Data Failed. Error: ".$this->lastErrorMsg();
                $resp['sql'] = $sql;
            }
        }else{
            $resp['status'] = 'failed';
            $resp['msg'] = "No Student Listed yet";
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
                if (empty($entry['student_number']) || empty($entry['name'])) {
                    $errors[] = "Row " . ($index + 1) . ": Student number and name are required";
                    continue;
                }
                
                $student_number = $this->escapeString($entry['student_number']);
                $name = $this->escapeString($entry['name']);
                
                // Check if student number already exists
                $check = $this->querySingle("SELECT COUNT(*) as count FROM `student_list` WHERE `student_number` = '{$student_number}'");
                
                if ($check > 0) {
                    $errors[] = "Row " . ($index + 1) . ": Student number '{$student_number}' already exists";
                    continue;
                }
                
                // Insert the student
                $sql = "INSERT INTO `student_list` (`class_id`, `student_number`, `name`) 
                        VALUES ('{$class_id}', '{$student_number}', '{$name}')";
                
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
    
    function get_students() {
        $response = [];
        try {
            // Get the request parameters from DataTables
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 1;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $search = isset($_POST['search']['value']) ? $this->escapeString($_POST['search']['value']) : '';
            $orderColumn = isset($_POST['order'][0]['column']) ? intval($_POST['order'][0]['column']) : 2; // Default sort by Name (column index 2)
            $orderDir = isset($_POST['order'][0]['dir']) ? strtoupper($this->escapeString($_POST['order'][0]['dir'])) : 'ASC';
            $sectionFilter = isset($_POST['sectionFilter']) ? $this->escapeString($_POST['sectionFilter']) : '';
            
            // Define column names for ordering
            $columns = [
                0 => 'student_id',
                1 => 'student_number',
                2 => 'name',
                3 => 'sname',
                4 => 'grade_section'
            ];
            
            $orderBy = isset($columns[$orderColumn]) ? $columns[$orderColumn] : 'name';
            
            // Build the base query
            $sql = "SELECT ss.*, (c.grade || ' - ' || c.section) as grade_section, s.name as sname 
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
            $data = [];
            $i = $start + 1;
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $action = '<div class="btn-group" role="group">';
                $action .= '<button type="button" class="btn_act edit_data bi bi-pencil-square" data-id=" ' . $row['student_id'] . '">Edit</button>';
                $action .= '</div>';
                
                $data[] = [
                    'DT_RowIndex' => $i++,
                    'id' => $row['student_id'], // Add student_id as 'id' for DataTables
                    'student_id' => $row['student_id'], // Also include as student_id for backward compatibility
                    'student_number' => $row['student_number'],
                    'name' => $row['name'],
                    'sname' => $row['sname'],
                    'grade_section' => $row['grade_section'],
                    'action' => $action
                ];
            }
            
            // Prepare the response
            $response = [
                'draw' => $draw,
                'recordsTotal' => intval($totalRecords),
                'recordsFiltered' => intval($filteredCount),
                'data' => $data
            ];
            
            // Debug information (uncomment if needed)
            // $response['debug'] = [
            //     'sql' => $sql,
            //     'sectionFilter' => $sectionFilter,
            //     'where' => $where
            // ];
            
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

    public function save_attendance() {
        extract($_POST);
        
        if(!isset($class_id) || !isset($date) || !isset($quarter) || !isset($attendance_data)) {
            return json_encode(array('status' => 'error', 'msg' => 'Missing required parameters'));
        }
        
        // Begin transaction
        $this->exec('BEGIN TRANSACTION');
        
        try {
            // Delete existing attendance for this class, date, and quarter
            $delete = $this->query("DELETE FROM attendance WHERE class_id = '{$class_id}' AND date = '{$date}' AND quarter = '{$quarter}'
                                 AND student_id IN (SELECT student_id FROM student_list WHERE class_id = '{$class_id}')");
            
            if(!$delete) {
                throw new Exception("Failed to clear existing attendance records: " . $this->lastErrorMsg());
            }
            
            // Insert new attendance records
            $attendance_data = json_decode($attendance_data, true);
            $inserted = 0;
            
            foreach($attendance_data as $student_id => $status) {
                $student_id = $this->escapeString($student_id);
                $status = $this->escapeString($status);
                
                $sql = "INSERT INTO attendance (class_id, student_id, quarter, date, status, date_created) 
                        VALUES ('{$class_id}', '{$student_id}', '{$quarter}', '{$date}', '{$status}', CURRENT_TIMESTAMP)";
                        
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
}
$a = isset($_REQUEST['a']) ? $_REQUEST['a'] : '';
$action = new ActionsRecords();

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
        echo $action->get_students();
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
    case 'customer_logout':
        echo $action->customer_logout();
        break;
    case 'save_user':
        echo $action->save_user();
    break;
    case 'delete_user':
        echo $action->delete_user();
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
    case 'get_remaining_percentage':
        echo $action->get_remaining_percentage();
    break;
    case 'save_attendance':
        echo $action->save_attendance();
    break;
    default:
    // default action here
    break;
}