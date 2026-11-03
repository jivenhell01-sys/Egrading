<?php
require_once("DBConnection.php");
if(isset($_GET['id'])){
$qry = $conn->query("SELECT * FROM `student_list` where student_id = '{$_GET['id']}'");
    foreach($qry->fetchArray() as $k => $v){
        $$k = $v;
    }
}

$firstname = isset($firstname) ? trim($firstname) : '';
$lastname = isset($lastname) ? trim($lastname) : '';
$middlename = isset($middlename) ? trim($middlename) : '';

if ((empty($firstname) || empty($lastname)) && !empty($name)) {
    if (strpos($name, ',') !== false) {
        $parts = explode(',', $name, 2);
        $lastname = trim($lastname ?: ($parts[0] ?? ''));
        $nameParts = preg_split('/\s+/', trim($parts[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($firstname) && !empty($nameParts)) {
            $firstname = array_shift($nameParts);
        }
        if (empty($middlename) && !empty($nameParts)) {
            $middlename = implode(' ', $nameParts);
        }
    } else {
        $nameParts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($firstname) && !empty($nameParts)) {
            $firstname = array_shift($nameParts);
        }
        if (empty($lastname) && !empty($nameParts)) {
            $lastname = array_pop($nameParts);
        }
        if (empty($middlename) && !empty($nameParts)) {
            $middlename = implode(' ', $nameParts);
        }
    }
}
?>
<div class="container-fluid stu-form">
    <form action="" id="student-form">
        <input type="hidden" name="id" value="<?php echo isset($student_id) ? $student_id : '' ?>">
        <input type="hidden" name="class_id" id="form_class_id" value="<?php echo isset($class_id) ? $class_id : '' ?>">
        <?php if(isset($student_id) && !empty($student_id)): ?>
        <div class="stu-mode-banner stu-mode-edit">
            <i class="fas fa-user-edit"></i>
            <div><strong>Editing student record</strong><span>Changes apply to this enrollment. The directory refreshes automatically after saving.</span></div>
        </div>
        <?php else: ?>
        <div class="stu-mode-banner stu-mode-add">
            <i class="fas fa-user-plus"></i>
            <div><strong>New student enrollment</strong><span>Add one student via Single, or many at once via Bulk.</span></div>
        </div>
        <?php endif; ?>
        <ul class="nav nav-tabs stu-tabs" id="studentTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="single-tab" data-bs-toggle="tab" data-bs-target="#single" type="button" role="tab"><i class="fa fa-user me-1"></i> Single</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button" role="tab"><i class="fa fa-users me-1"></i> Bulk</button>
            </li>
        </ul>

        <div class="tab-content" id="studentTabContent">
            <!-- Single Entry Tab -->
            <div class="tab-pane fade show active" id="single" role="tabpanel" aria-labelledby="single-tab">
                <div class="stu-sec-title"><i class="fas fa-school"></i>Enrollment</div>
                <div class="row g-2">
                    <div class="col-md-12">
                        <div class="form-group mb-2">
                            <label for="class_id" class="form-label mb-1">Class</label>
                            <select name="class_id" id="class_id" class="form-select form-select-sm" style="cursor: pointer;" required>
                                <option <?php echo (!isset($class_id)) ? 'selected' : '' ?> value="">Select Class</option>
                                <?php
                                $dept_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class FROM `class_list` c inner join `subjects` s on c.subject_id = s.subject_id order by `class` asc");
                                while($row= $dept_qry->fetchArray()):
                                ?>
                                    <option value="<?php echo $row['class_id'] ?>" <?php echo (isset($class_id) && $class_id == $row['class_id'] ) ? 'selected' : '' ?>><?php echo $row['class'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="student_number" class="form-label mb-1">Student No.</label>
                            <input type="text" name="student_number" id="student_number" class="form-control form-control-sm" value="<?php echo isset($student_number) ? $student_number : '' ?>"
                                   placeholder="25-12345" maxlength="8" pattern="^\d{2}-\d{5}$" inputmode="numeric" autocomplete="off"
                                  >
                        </div>
                    </div>
                    <input type="hidden" name="name" id="name" value="<?php echo isset($name) ? htmlspecialchars($name, ENT_QUOTES) : '' ?>">
                    <div class="col-12"><div class="stu-sec-title"><i class="fas fa-signature"></i>Student Name</div></div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="lastname" class="form-label mb-1">Last Name</label>
                            <div class="input-group">
                                <input type="text" name="lastname" id="lastname" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($lastname, ENT_QUOTES) ?>"
                                       required>
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    ©®™
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('lastname', '©'); return false;">© Copyright</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('lastname', '®'); return false;">® Registered</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('lastname', '™'); return false;">™ Trademark</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('lastname', 'ñ'); return false;">ñ</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('lastname', 'Ñ'); return false;">Ñ</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="firstname" class="form-label mb-1">First Name</label>
                            <div class="input-group">
                                <input type="text" name="firstname" id="firstname" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($firstname, ENT_QUOTES) ?>"
                                       required>
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    ©®™
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('firstname', '©'); return false;">© Copyright</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('firstname', '®'); return false;">® Registered</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('firstname', '™'); return false;">™ Trademark</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('firstname', 'ñ'); return false;">ñ</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('firstname', 'Ñ'); return false;">Ñ</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="middlename" class="form-label mb-1">Middle Name</label>
                            <div class="input-group">
                                <input type="text" name="middlename" id="middlename" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($middlename, ENT_QUOTES) ?>"
                                      
                                       placeholder="Optional">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    ©®™
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('middlename', '©'); return false;">© Copyright</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('middlename', '®'); return false;">® Registered</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('middlename', '™'); return false;">™ Trademark</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('middlename', 'ñ'); return false;">ñ</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="insertSpecialChar('middlename', 'Ñ'); return false;">Ñ</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                     <div class="col-md-2">
                        <div class="form-group mb-2">
                            <label for="name_extension" class="form-label mb-1">Extension</label>
                            <select name="name_extension" id="name_extension" class="form-select form-select-sm">
                                <?php
                                $extensions = ['' => 'None', 'Jr.' => 'Jr.', 'Sr.' => 'Sr.', 'II' => 'II', 'III' => 'III', 'IV' => 'IV', 'V' => 'V'];
                                $name_extension = isset($name_extension) ? trim($name_extension) : '';
                                foreach ($extensions as $ext_value => $ext_label) {
                                    $ext_selected = ($name_extension === $ext_value) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($ext_value, ENT_QUOTES) . "\" {$ext_selected}>" . htmlspecialchars($ext_label) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                     <div class="col-md-2">
                        <div class="form-group mb-2">
                            <label for="age" class="form-label mb-1">Age</label>
                            <input type="number" name="age" id="age" class="form-control form-control-sm"
                                   value="<?php echo isset($age) ? $age : '' ?>" readonly
                                  >
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group mb-2">
                            <label for="gender" class="form-label mb-1">Gender</label>
                            <select name="gender" id="gender" class="form-select form-select-sm">
                                <option value="">Select</option>
                                <option value="Male" <?php echo (isset($gender) && $gender == 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?php echo (isset($gender) && $gender == 'Female') ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?php echo (isset($gender) && $gender == 'Other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="contact_number" class="form-label mb-1">Contact Number</label>
                            <input type="tel" name="contact_number" id="contact_number" class="form-control form-control-sm"
                                   value="<?php echo isset($contact_number) ? $contact_number : '' ?>"
                                   placeholder="09123456789" pattern="[0-9]{10,11}"
                                  >
                        </div>
                    </div>
                </div>
                <!-- Personal Information -->


                <div class="stu-sec-title"><i class="fas fa-id-card"></i>Personal Information</div>
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="birthdate" class="form-label mb-1">Birthdate</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                <div class="input-group-append" style="position: relative;">
                                    <select name="birth_year" id="birth_year" class="form-select form-select-sm" style="width: 80px;" onchange="updateBirthdate()">
                                        <option value="">Year</option>
                                        <?php
                                        $currentYear = date('Y');
                                        for($year = $currentYear; $year >= 1900; $year--) {
                                            $selected = (isset($birthdate) && !empty($birthdate) && date('Y', strtotime($birthdate)) == $year) ? 'selected' : '';
                                            echo "<option value=\"{$year}\" {$selected}>{$year}</option>";
                                        }
                                        ?>
                                    </select>
                                    <select name="birth_month" id="birth_month" class="form-select form-select-sm" style="width: 70px;" onchange="updateBirthdate()">
                                        <option value="">Month</option>
                                        <?php
                                        $months = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'May', '06' => 'Jun',
                                                   '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'];
                                        foreach($months as $num => $name) {
                                            $selected = (isset($birthdate) && !empty($birthdate) && date('m', strtotime($birthdate)) == $num) ? 'selected' : '';
                                            echo "<option value=\"{$num}\" {$selected}>{$name}</option>";
                                        }
                                        ?>
                                    </select>
                                    <select name="birth_day" id="birth_day" class="form-select form-select-sm" style="width: 60px;" onchange="updateBirthdate()">
                                        <option value="">Day</option>
                                        <?php
                                        if(isset($birthdate) && !empty($birthdate)) {
                                            $selectedDay = date('d', strtotime($birthdate));
                                            for($day = 1; $day <= 31; $day++) {
                                                $dayStr = str_pad($day, 2, '0', STR_PAD_LEFT);
                                                $selected = ($selectedDay == $dayStr) ? 'selected' : '';
                                                echo "<option value=\"{$dayStr}\" {$selected}>{$day}</option>";
                                            }
                                        } else {
                                            for($day = 1; $day <= 31; $day++) {
                                                $dayStr = str_pad($day, 2, '0', STR_PAD_LEFT);
                                                echo "<option value=\"{$dayStr}\">{$day}</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                    <input type="hidden" name="birthdate" id="birthdate" class="form-control"
                                           value="<?php echo isset($birthdate) ? $birthdate : '' ?>"
                                           onchange="calculateAge()">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="email" class="form-label mb-1">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control form-control-sm"
                                   value="<?php echo isset($email) ? $email : '' ?>"
                                   placeholder="student@example.com"
                                  >
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="stu-sec-title"><i class="fas fa-map-marker-alt"></i>Address Information</div>
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="barangay" class="form-label mb-1">Barangay</label>
                            <input type="text" name="barangay" id="barangay" class="form-control form-control-sm"
                                   value="<?php echo isset($barangay) ? $barangay : '' ?>"
                                  >
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="municipality" class="form-label mb-1">Municipality</label>
                            <input type="text" name="municipality" id="municipality" class="form-control form-control-sm"
                                   value="<?php echo isset($municipality) ? $municipality : '' ?>"
                                  >
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="province" class="form-label mb-1">Province</label>
                            <input type="text" name="province" id="province" class="form-control form-control-sm"
                                   value="<?php echo isset($province) ? $province : '' ?>"
                                  >
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="zipcode" class="form-label mb-1">Zip Code</label>
                            <input type="text" name="zipcode" id="zipcode" class="form-control form-control-sm"
                                   value="<?php echo isset($zipcode) ? $zipcode : '' ?>"
                                   placeholder="1234" pattern="[0-9]{4}"
                                   maxlength="4"
                                  >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Entry Tab -->
            <div class="tab-pane fade" id="bulk" role="tabpanel" aria-labelledby="bulk-tab">
            <div class="col-12">
                <div class="card border-0 shadow-sm bulk-entry-card mt-1">
                    <div class="card-body p-2">
                        <div class="stu-sec-title"><i class="fas fa-file-excel"></i>Import from CSV or Excel</div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-7">
                                <label for="bulkFileUpload" class="form-label mb-1">Upload file</label>
                                <input type="file" id="bulkFileUpload" class="form-control form-control-sm" accept=".csv,.xls,.xlsx">
                                <small class="text-muted">Smart matching detects columns like student no, lastname, firstname, middlename, extension, birthdate, gender, contact, email, barangay, municipality, province, and zipcode.</small>
                            </div>
                            <div class="col-md-5 d-flex gap-2 flex-wrap">
                                <button type="button" id="processBulkFile" class="btn btn-success btn-sm">
                                    <i class="fas fa-upload me-1"></i> Import File
                                </button>
                             <!-- --   <button type="button" id="downloadStudentTemplateCsv" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-file-csv me-1"></i> Export CSV Format
                                </button> -->
                                <button type="button" id="downloadStudentTemplateExcel" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-file-excel me-1"></i> Export Excel Format
                                </button>
                            </div>
                        </div>
                        <div id="fileUploadStatus" class="mt-2 small"></div>
                    </div>
                </div>
            </div>
                <div class="stu-sec-title"><i class="fas fa-keyboard"></i>Manual Entry</div>
                <div class="row g-2">
                    <div class="col-12">
                        <div class="alert stu-note">
                            <i class="fa fa-info-circle me-1"></i>
                            <strong>Note:</strong> Bulk entry captures all student information. Student Number, Last Name, and First Name are required, other fields are optional.
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group mb-2">
                            <label for="bulk_class_id" class="form-label mb-1">Class</label>
                            <select name="bulk_class_id" id="bulk_class_id" class="form-select form-select-sm" required>
                                <option value="" selected disabled>Select Class</option>
                                <?php
                                $dept_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class FROM `class_list` c inner join `subjects` s on c.subject_id = s.subject_id order by `class` asc");
                                while($row= $dept_qry->fetchArray()):
                                ?>
                                    <option value="<?php echo $row['class_id'] ?>"><?php echo $row['class'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label for="student_numbers" class="form-label mb-1">Student Numbers <small class="text-muted">(One per line, format: XX-XXXXX)</small></label>
                            <textarea name="student_numbers" id="student_numbers" class="form-control form-control-sm" rows="8" placeholder="25-12345\n25-12346" pattern="\d{2}-\d{5}"></textarea>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="student_lastnames" class="form-label mb-1">Last Names <small class="text-muted">(One per line)</small></label>
                            <textarea name="student_lastnames" id="student_lastnames" class="form-control form-control-sm" rows="8" placeholder="Doe\nSmith" oninput="formatBulkNames(this)"></textarea>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="student_firstnames" class="form-label mb-1">First Names <small class="text-muted">(One per line)</small></label>
                            <textarea name="student_firstnames" id="student_firstnames" class="form-control form-control-sm" rows="8" placeholder="John\nJane" oninput="formatBulkNames(this)"></textarea>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="student_middlenames" class="form-label mb-1">Middle Names <small class="text-muted">(One per line, leave empty if not available)</small></label>
                            <textarea name="student_middlenames" id="student_middlenames" class="form-control form-control-sm" rows="8" placeholder="Smith\nMarie" oninput="formatBulkNames(this)"></textarea>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="student_extensions" class="form-label mb-1">Extensions <small class="text-muted">(One per line: Jr., Sr., II, III, IV, V — leave empty if not available)</small></label>
                            <textarea name="student_extensions" id="student_extensions" class="form-control form-control-sm" rows="8" placeholder="Jr.\nII"></textarea>
                        </div>
                    </div>


                    <!-- Additional Information Fields -->
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label for="bulk_birthdates" class="form-label mb-1">Birthdates <small class="text-muted">(One per line, format: YYYY-MM-DD, leave empty if not available)</small></label>
                            <textarea name="bulk_birthdates" id="bulk_birthdates" class="form-control form-control-sm" rows="4" placeholder="2000-01-15\n2000-02-20"></textarea>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label for="bulk_genders" class="form-label mb-1">Genders <small class="text-muted">(One per line: Male/Female/Other, leave empty if not available)</small></label>
                            <textarea name="bulk_genders" id="bulk_genders" class="form-control form-control-sm" rows="4" placeholder="Male\nFemale"></textarea>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label for="bulk_contacts" class="form-label mb-1">Contact Numbers <small class="text-muted">(One per line, leave empty if not available)</small></label>
                            <textarea name="bulk_contacts" id="bulk_contacts" class="form-control form-control-sm" rows="4" placeholder="09123456789\n09987654321"></textarea>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label for="bulk_emails" class="form-label mb-1">Email Addresses <small class="text-muted">(One per line, leave empty if not available)</small></label>
                            <textarea name="bulk_emails" id="bulk_emails" class="form-control form-control-sm" rows="4" placeholder="student1@example.com\nstudent2@example.com"></textarea>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="bulk_barangays" class="form-label mb-1">Barangays <small class="text-muted">(One per line)</small></label>
                            <textarea name="bulk_barangays" id="bulk_barangays" class="form-control form-control-sm" rows="4" placeholder="Barangay 1\nBarangay 2"></textarea>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="bulk_municipalities" class="form-label mb-1">Municipalities <small class="text-muted">(One per line)</small></label>
                            <textarea name="bulk_municipalities" id="bulk_municipalities" class="form-control form-control-sm" rows="4" placeholder="Municipality 1\nMunicipality 2"></textarea>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="bulk_provinces" class="form-label mb-1">Provinces <small class="text-muted">(One per line)</small></label>
                            <textarea name="bulk_provinces" id="bulk_provinces" class="form-control form-control-sm" rows="4" placeholder="Province 1\nProvince 2"></textarea>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-2">
                            <label for="bulk_zipcodes" class="form-label mb-1">Zip Codes <small class="text-muted">(One per line, 4 digits)</small></label>
                            <textarea name="bulk_zipcodes" id="bulk_zipcodes" class="form-control form-control-sm" rows="4" placeholder="1234\n5678"></textarea>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="button" id="preview_entries" class="btn btn-sm btn-primary">
                            <i class="fa fa-eye me-1"></i> Preview Entries
                        </button>
                        <div id="preview_container" class="mt-2" style="display:none;">
                            <h6 class="mb-1">Preview (First 5 entries):</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-1">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 15%;">Student Number</th>
                                            <th style="width: 25%;">Name</th>
                                            <th style="width: 10%;">Birthdate</th>
                                            <th style="width: 8%;">Gender</th>
                                            <th style="width: 12%;">Contact</th>
                                            <th style="width: 15%;">Email</th>
                                            <th style="width: 15%;">Address</th>
                                        </tr>
                                    </thead>
                                    <tbody id="preview_table_body">
                                    </tbody>
                                </table>
                            </div>
                            <div id="total_entries" class="text-muted small"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
    /* =====================================================================
       ADD / EDIT STUDENT MODAL — matches landingpage.php (Enrollment Form)
       Palette: ESSU dark green + gold. Same section numbering, gold rules,
       green gradients and input treatment as the public registration form.
       Scoped to #uni_modal so nothing leaks into the rest of the admin UI.
       ===================================================================== */
    #uni_modal {
        --stu-green: #1a4d2e;
        --stu-green-2: #2d5f3f;
        --stu-gold: #d4af37;
        --stu-gold-light: #f4e5c2;
        --stu-border: #e0e0e0;
        --stu-surface: #f8f9fa;
    }

    #uni_modal .modal-dialog {
        width: min(1140px, 96vw) !important;
        max-width: min(1140px, 96vw) !important;
    }

    /* ---- Modal chrome: green header + gold hairline, like .card-header-custom ---- */
    #uni_modal .modal-content {
        border: none;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
    }
    #uni_modal .modal-header {
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%);
        border-bottom: none;
        border-radius: 18px 18px 0 0;
        padding: 0.85rem 1.25rem;
        position: relative;
    }
    #uni_modal .modal-header::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--stu-gold), var(--stu-gold-light), var(--stu-gold));
        background-size: 200% 100%;
        animation: stuShimmer 3s infinite;
    }
    @keyframes stuShimmer {
        0% { background-position: -1000px 0; }
        100% { background-position: 1000px 0; }
    }
    #uni_modal .modal-title {
        color: #fff;
        font-weight: 700;
        font-size: 0.95rem;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    #uni_modal .modal-header .btn-close {
        filter: brightness(0) invert(1);
        opacity: 0.9;
    }
    #uni_modal .modal-footer {
        background: #fff;
        border-top: 1px solid var(--stu-border);
    }

    /* ---- Save button: the landing page .submit-btn treatment ---- */
    #uni_modal .modal-footer #submit,
    #uni_modal .stu-form .btn-primary {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%) !important;
        border: none !important;
        color: #fff !important;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        border-radius: 10px;
        padding: 0.55rem 1.15rem !important;
        box-shadow: 0 4px 12px rgba(26, 77, 46, 0.3);
        transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
    }
    #uni_modal .modal-footer #submit::before,
    #uni_modal .stu-form .btn-primary::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.35), transparent);
        transition: left 0.5s ease;
    }
    #uni_modal .modal-footer #submit:hover,
    #uni_modal .stu-form .btn-primary:hover {
        filter: none;
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(26, 77, 46, 0.4);
    }
    #uni_modal .modal-footer #submit:hover::before,
    #uni_modal .stu-form .btn-primary:hover::before { left: 100%; }
    #uni_modal .modal-footer #submit:active,
    #uni_modal .stu-form .btn-primary:active { transform: translateY(0); }
    #uni_modal .modal-footer .btn-secondary {
        border-radius: 10px;
        padding: 0.55rem 1rem !important;
        font-weight: 600;
    }

    /* ---- Bulk tab action buttons ---- */
    #uni_modal .stu-form #processBulkFile {
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%);
        border: none;
        color: #fff;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-radius: 10px;
        padding: 0.45rem 0.9rem !important;
        box-shadow: 0 4px 12px rgba(26, 77, 46, 0.28);
    }
    #uni_modal .stu-form #downloadStudentTemplateExcel {
        border: 2px solid var(--stu-gold) !important;
        color: #8a6a12;
        background: #fff;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-radius: 10px;
        padding: 0.4rem 0.9rem !important;
    }
    #uni_modal .stu-form #downloadStudentTemplateExcel:hover {
        background: var(--stu-gold);
        border-color: var(--stu-gold) !important;
        color: var(--stu-green);
        filter: none;
    }

    #uni_modal .stu-form {
        padding: 0 4px;
        counter-reset: stuSection;
    }
    #uni_modal .stu-form .container-fluid {
        width: 100% !important;
        padding: 0 15px !important;
    }

    /* ---- Mode banner (Add vs Edit context) ---- */
    #uni_modal .stu-mode-banner {
        display: flex;
        align-items: flex-start;
        gap: 0.7rem;
        border-radius: 12px;
        padding: 0.7rem 0.9rem;
        margin-bottom: 0.9rem;
        font-size: 0.82rem;
    }
    #uni_modal .stu-mode-banner > i {
        font-size: 1.15rem;
        margin-top: 0.15rem;
    }
    #uni_modal .stu-mode-banner strong {
        display: block;
        font-size: 0.85rem;
    }
    #uni_modal .stu-mode-banner span { opacity: 0.85; }
    #uni_modal .stu-mode-add {
        background: rgba(212, 175, 55, 0.12);
        border: 1px solid rgba(212, 175, 55, 0.45);
        color: #7a5c12;
    }
    #uni_modal .stu-mode-edit {
        background: rgba(26, 77, 46, 0.08);
        border: 1px solid rgba(26, 77, 46, 0.28);
        color: var(--stu-green);
    }

    /* ---- Tabs styled as the landing page step pills ---- */
    #uni_modal .stu-tabs {
        border: 0;
        gap: 0.25rem;
        background: rgba(26, 77, 46, 0.07);
        border: 1px solid rgba(26, 77, 46, 0.14);
        padding: 0.3rem;
        border-radius: 999px;
        display: inline-flex;
        margin-bottom: 1rem !important;
    }
    #uni_modal .stu-tabs .nav-link {
        border: 0;
        border-radius: 999px;
        font-weight: 700;
        font-size: 0.76rem !important;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--stu-green-2);
        padding: 0.45rem 1.15rem !important;
        transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
    }
    #uni_modal .stu-tabs .nav-link:hover {
        filter: none;
        color: var(--stu-green);
        background: rgba(212, 175, 55, 0.2);
    }
    #uni_modal .stu-tabs .nav-link.active {
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(26, 77, 46, 0.3);
    }

    /* ---- Section titles: auto-numbered 01 02 03... with a gold rule ---- */
    #uni_modal .stu-sec-title {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        font-size: 0.82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--stu-green);
        margin: 1rem 0 0.7rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--stu-gold);
    }
    #uni_modal .stu-sec-title::before {
        counter-increment: stuSection;
        content: counter(stuSection, decimal-leading-zero);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        min-width: 26px;
        border-radius: 8px;
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0;
        box-shadow: 0 2px 6px rgba(26, 77, 46, 0.25);
    }
    #uni_modal .stu-sec-title > i {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        min-width: 26px;
        border-radius: 8px;
        background: linear-gradient(135deg, var(--stu-gold) 0%, var(--stu-gold-light) 100%);
        color: var(--stu-green);
        font-size: 0.7rem;
    }
    #uni_modal .stu-sec-title::after { content: none; }

    /* ---- Labels ---- */
    #uni_modal .stu-form .form-label {
        font-size: 0.72rem !important;
        font-weight: 700 !important;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--stu-green) !important;
        margin-bottom: 0.3rem !important;
    }
    #uni_modal .stu-form .form-label small { text-transform: none; letter-spacing: 0; font-weight: 500; }

    /* ---- Controls: landing page border / surface / focus treatment ---- */
    #uni_modal .stu-form .form-control,
    #uni_modal .stu-form .form-select {
        font-size: 0.88rem !important;
        padding: 0.5rem 0.65rem !important;
        border: 2px solid var(--stu-border) !important;
        border-radius: 10px;
        background-color: var(--stu-surface);
        color: #1a1a1a;
        box-shadow: none;
        transition: border-color 0.25s ease, box-shadow 0.25s ease, background-color 0.25s ease;
    }
    #uni_modal .stu-form .form-control:focus,
    #uni_modal .stu-form .form-select:focus {
        border-color: var(--stu-green) !important;
        background-color: #fff;
        box-shadow: 0 0 0 3px rgba(26, 77, 46, 0.1) !important;
    }
    #uni_modal .stu-form .form-control::placeholder { color: #9aa3a0; }
    #uni_modal .stu-form .form-control[readonly] {
        background-color: #eef1ef;
        color: #55655c;
    }
    #uni_modal .stu-form .input-group-text {
        background: var(--stu-gold-light);
        border: 2px solid var(--stu-border);
        color: var(--stu-green);
        font-size: 0.8rem;
    }
    #uni_modal .stu-form .input-group .btn {
        border: 2px solid var(--stu-border) !important;
        background: #fff;
        color: var(--stu-green);
        border-radius: 0 10px 10px 0 !important;
        font-size: 0.75rem;
        font-weight: 700;
    }
    #uni_modal .stu-form .input-group .btn:hover {
        background: var(--stu-gold);
        border-color: var(--stu-gold) !important;
        color: var(--stu-green);
        filter: none;
    }

    /* ---- Bulk textareas ---- */
    #uni_modal .stu-form textarea.form-control {
        font-family: 'JetBrains Mono', 'Courier New', monospace;
        font-size: 0.84rem !important;
        min-height: 120px;
        resize: vertical;
        line-height: 1.5;
    }

    /* ---- File import zone ---- */
    #uni_modal .stu-form input[type="file"].form-control {
        border: 2px dashed var(--stu-gold) !important;
        background: #fdfaf1;
        padding: 0.6rem 0.7rem !important;
    }
    #uni_modal .stu-form input[type="file"].form-control:focus {
        border-color: var(--stu-green) !important;
        background: #fff;
    }
    #uni_modal .stu-form .bulk-entry-card {
        border: 1px solid var(--stu-border);
        border-radius: 14px;
        background: #fff;
    }

    /* ---- Bulk note ---- */
    #uni_modal .stu-form .stu-note {
        border: 0;
        border-left: 4px solid var(--stu-gold);
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(212, 175, 55, 0.18) 0%, rgba(244, 229, 194, 0.35) 100%);
        color: #6b5312;
        font-size: 0.82rem !important;
        padding: 0.65rem 0.8rem !important;
        margin-bottom: 0;
    }

    /* ---- Preview table ---- */
    #uni_modal .stu-form .table-sm td,
    #uni_modal .stu-form .table-sm th {
        padding: 0.3rem 0.5rem;
        border-color: var(--stu-border);
        font-size: 0.8rem;
    }
    #uni_modal .stu-form .table-sm thead th {
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%);
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 0.68rem;
        border-color: var(--stu-green-2);
    }

    /* ---- Better spacing for wider layout ---- */
    #uni_modal .stu-form .row.g-2 {
        margin-left: -0.5rem;
        margin-right: -0.5rem;
    }
    #uni_modal .stu-form .row.g-2 > * {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }

    /* ===================== DARK MODE (admin shell) ===================== */
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-tabs {
        background: rgba(212, 175, 55, 0.12);
        border-color: rgba(212, 175, 55, 0.28);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-tabs .nav-link {
        color: var(--stu-gold-light);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-tabs .nav-link:not(.active):hover {
        color: #fff;
        background: rgba(212, 175, 55, 0.22);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .form-label {
        color: var(--stu-gold-light) !important;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .form-control,
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .form-select {
        background-color: #141d2e !important;
        border-color: var(--horizon-input-border, #35445e) !important;
        color: var(--horizon-input-text, #f1f5ff) !important;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .form-control:focus,
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .form-select:focus {
        border-color: var(--stu-gold) !important;
        box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.2) !important;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .form-control[readonly] {
        background-color: #202d43 !important;
        color: var(--horizon-text-muted, #8e9dbc) !important;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .input-group .btn {
        background: #202d43;
        border-color: var(--horizon-input-border, #35445e) !important;
        color: var(--stu-gold-light);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form input[type="file"].form-control {
        background: rgba(212, 175, 55, 0.08) !important;
        border-color: rgba(212, 175, 55, 0.45) !important;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-sec-title {
        color: var(--stu-gold-light);
        border-bottom-color: rgba(212, 175, 55, 0.6);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-sec-title::before {
        background: linear-gradient(135deg, var(--stu-green) 0%, var(--stu-green-2) 100%);
        color: #fff;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-sec-title > i {
        background: rgba(212, 175, 55, 0.22);
        color: var(--stu-gold-light);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .stu-note {
        background: rgba(212, 175, 55, 0.14);
        color: var(--stu-gold-light);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-mode-add {
        background: rgba(212, 175, 55, 0.14);
        border-color: rgba(212, 175, 55, 0.4);
        color: var(--stu-gold-light);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-mode-edit {
        background: rgba(45, 95, 63, 0.3);
        border-color: rgba(212, 175, 55, 0.35);
        color: #d8ead9;
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .bulk-entry-card {
        background: #182235;
        border-color: var(--horizon-card-border, #2b3850);
    }
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form .downloadStudentTemplateExcel,
    :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #uni_modal .stu-form #downloadStudentTemplateExcel {
        background: transparent;
        color: var(--stu-gold-light);
        border-color: var(--stu-gold) !important;
    }
</style>


<script>
    // Function to update birthdate when dropdowns change
    function updateBirthdate() {
        const year = document.getElementById('birth_year').value;
        const month = document.getElementById('birth_month').value;
        const day = document.getElementById('birth_day').value;

        // Update day options based on selected month and year
        if (year && month) {
            updateDayOptions(year, month);
        }

        // Set the birthdate value if all fields are selected
        if (year && month && day) {
            const birthdate = `${year}-${month}-${day}`;
            document.getElementById('birthdate').value = birthdate;
            calculateAge();
        } else {
            document.getElementById('birthdate').value = '';
            document.getElementById('age').value = '';
        }
    }

    // Function to update day options based on month and year (for leap years)
    function updateDayOptions(year, month) {
        const daySelect = document.getElementById('birth_day');
        const currentDay = daySelect.value;

        // Get the number of days in the selected month
        const daysInMonth = new Date(parseInt(year), parseInt(month), 0).getDate();

        // Clear current options
        daySelect.innerHTML = '<option value="">Day</option>';

        // Add day options up to the correct number
        for (let day = 1; day <= daysInMonth; day++) {
            const dayStr = day.toString().padStart(2, '0');
            const selected = (currentDay == dayStr) ? 'selected' : '';
            daySelect.innerHTML += `<option value="${dayStr}" ${selected}>${day}</option>`;
        }
    }

    // Function to calculate age based on birthdate
    function calculateAge() {
        const birthdate = document.getElementById('birthdate').value;
        if (birthdate) {
            const today = new Date();
            const birthDate = new Date(birthdate);
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();

            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }

            document.getElementById('age').value = age;
        } else {
            document.getElementById('age').value = '';
        }
    }

    // Initialize date dropdowns when document is ready
    $(document).ready(function() {
        // Calculate age on page load if birthdate is set
        calculateAge();
    });

    $(function(){
        // Auto-format student number as 25-12345
        const $sn = $('#student_number');

        // Format student number helper function
        function formatStudentNumber(raw) {
            const rawValue = String(raw || '').trim();
            if (!rawValue) return '';

            const alreadyFormatted = rawValue.match(/^(\d{2})-(\d{1,5})$/);
            if (alreadyFormatted) {
                return alreadyFormatted[1] + '-' + alreadyFormatted[2].slice(0, 5);
            }

            const digits = rawValue.replace(/\D/g, '').slice(0, 7);
            if (digits.length <= 2) return digits;
            return digits.slice(0,2) + '-' + digits.slice(2,7);
        }

        // Format bulk student numbers in textarea
        $('#student_numbers').on('input', function() {
            const $this = $(this);
            const lines = ($this.val() || '').split('\n');
            const formattedLines = lines.map(line => {
                const trimmed = line.trim();
                return trimmed ? formatStudentNumber(trimmed) : '';
            });

            const cursorPos = this.selectionStart;
            $this.val(formattedLines.join('\n'));
            this.setSelectionRange(cursorPos, cursorPos);
        });

        // Format student number while preserving cursor position
        function setFormattedValuePreserveCaret(e) {
            const el = e.target;
            const oldStart = el.selectionStart;
            const oldEnd = el.selectionEnd;
            const oldVal = el.value;
            const before = oldVal.slice(0, oldStart);
            const digitsBefore = before.replace(/\D/g, '');
            el.value = formatStudentNumber(oldVal);
            // Recompute caret based on number of digits before caret
            const d = digitsBefore.length;
            let newPos = d <= 2 ? d : d + 1; // add 1 for hyphen when past 2 digits
            // Clamp within bounds
            newPos = Math.max(0, Math.min(el.value.length, newPos));
            el.setSelectionRange(newPos, newPos);
        }

        // Single entry student number formatting
        $sn.on('input', function(e){
            setFormattedValuePreserveCaret(e);
        });

        $sn.on('paste', function(e){
            // Delay to allow paste, then format
            const el = this;
            setTimeout(function(){
                el.value = formatStudentNumber(el.value);
            }, 0);
        });

        // Initialize format if existing value
        if ($sn.val()) {
            $sn.val(formatStudentNumber($sn.val()));
        }

        // Update the hidden class_id field when the dropdown changes
        $('#class_id').on('change', function() {
            $('#form_class_id').val($(this).val());
        });

        // Initialize the hidden field on page load
        if ($('#class_id').length) {
            $('#form_class_id').val($('#class_id').val());
        }

        function titleCaseText(value) {
            return String(value || '')
                .trim()
                .toLocaleLowerCase()
                .replace(/(^|\s|-|'|\u00D1|\u00F1)([\p{L}])/gu, function(match, prefix, letter) {
                    return prefix + letter.toLocaleUpperCase();
                });
        }

        function titleCaseTextPreserveSpaces(value) {
            // Preserve internal spaces while applying title case
            return String(value || '')
                .replace(/^\s+|\s+$/g, '') // Only trim leading/trailing spaces
                .toLocaleLowerCase()
                .replace(/(^|\s|-|'|\u00D1|\u00F1)([\p{L}])/gu, function(match, prefix, letter) {
                    return prefix + letter.toLocaleUpperCase();
                });
        }

        function sentenceCaseText(value) {
            // Convert to title case (first letter of each word capitalized)
            return String(value || '')
                .replace(/^\s+|\s+$/g, '') // Only trim leading/trailing spaces
                .toLocaleLowerCase()
                .replace(/(^|\s)([\p{L}])/gu, function(match, prefix, letter) {
                    return prefix + letter.toLocaleUpperCase();
                });
        }

        window.insertSpecialChar = function(targetFieldId, char) {
            const input = document.getElementById(targetFieldId);
            if (!input) return;

            input.focus();

            const currentValue = input.value || '';
            const hasValidSelection = typeof input.selectionStart === 'number' && typeof input.selectionEnd === 'number';
            const start = hasValidSelection ? input.selectionStart : currentValue.length;
            const end = hasValidSelection ? input.selectionEnd : currentValue.length;

            input.value = currentValue.slice(0, start) + char + currentValue.slice(end);

            if (['lastname', 'firstname', 'middlename'].includes(targetFieldId)) {
                input.value = titleCaseText(input.value);
                syncSingleFullName();
            }

            const caretPos = Math.min((hasValidSelection ? start : currentValue.length) + char.length, input.value.length);
            requestAnimationFrame(() => {
                input.focus();
                input.setSelectionRange(caretPos, caretPos);
            });
        }

        function composeFullName(lastname, firstname, middlename, name_extension) {
            lastname = sentenceCaseText(lastname);
            firstname = sentenceCaseText(firstname);
            middlename = sentenceCaseText(middlename);
            name_extension = String(name_extension || '').trim();
            if (name_extension && name_extension.toLowerCase() === 'none') name_extension = '';
            return [lastname, firstname + (middlename ? ' ' + middlename : '') + (name_extension ? ' ' + name_extension : '')]
                .filter(Boolean)
                .join(', ')
                .trim();
        }

        function syncSingleFullName() {
            const lastname = $('#lastname').val();
            const firstname = $('#firstname').val();
            const middlename = $('#middlename').val();
            const name_extension = $('#name_extension').val();
            $('#name').val(composeFullName(lastname, firstname, middlename, name_extension));
        }

        $('#lastname, #firstname, #middlename, #name_extension').on('input', function() {
            syncSingleFullName();
        }).on('blur', function() {
            this.value = sentenceCaseText(this.value);
            syncSingleFullName();
        });

        function splitLines(selector) {
            const rawValue = $(selector).val() || '';
            return rawValue.split('\n').map(n => n.trim());
        }

        function normalizeHeaderText(value) {
            return String(value || '')
                .trim()
                .toLowerCase()
                .replace(/[_\-.#()/\\]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function detectColumnIndex(headers, aliases) {
            const normalizedAliases = aliases.map(alias => normalizeHeaderText(alias));
            for (let i = 0; i < headers.length; i++) {
                const header = normalizeHeaderText(headers[i]);
                if (normalizedAliases.some(alias => header === alias || header.includes(alias) || alias.includes(header))) {
                    return i;
                }
            }
            return -1;
        }

        function formatBulkStudentNumbersTextarea() {
            const $textarea = $('#student_numbers');
            const lines = ($textarea.val() || '').split('\n');
            const formattedLines = lines.map(line => {
                const trimmed = line.trim();
                return trimmed ? formatStudentNumber(trimmed) : '';
            });
            $textarea.val(formattedLines.join('\n'));
        }

        function populateBulkFieldsFromRows(rows) {
            const studentNumbers = [];
            const lastnames = [];
            const firstnames = [];
            const middlenames = [];
            const extensions = [];
            const birthdates = [];
            const genders = [];
            const contacts = [];
            const emails = [];
            const barangays = [];
            const municipalities = [];
            const provinces = [];
            const zipcodes = [];

            rows.forEach(row => {
                studentNumbers.push(normalizeImportedStudentNumber(row.student_number || ''));
                lastnames.push(row.lastname || '');
                firstnames.push(row.firstname || '');
                middlenames.push(row.middlename || '');
                extensions.push(row.name_extension || '');
                birthdates.push(row.birthdate || '');
                genders.push(row.gender || '');
                contacts.push(row.contact_number || '');
                emails.push(row.email || '');
                barangays.push(row.barangay || '');
                municipalities.push(row.municipality || '');
                provinces.push(row.province || '');
                zipcodes.push(row.zipcode || '');
            });

            $('#student_numbers').val(studentNumbers.join('\n'));
            formatBulkStudentNumbersTextarea();
            $('#student_lastnames').val(lastnames.join('\n'));
            $('#student_firstnames').val(firstnames.join('\n'));
            $('#student_middlenames').val(middlenames.join('\n'));
            $('#student_extensions').val(extensions.join('\n'));
            $('#bulk_birthdates').val(birthdates.join('\n'));
            $('#bulk_genders').val(genders.join('\n'));
            $('#bulk_contacts').val(contacts.join('\n'));
            $('#bulk_emails').val(emails.join('\n'));
            $('#bulk_barangays').val(barangays.join('\n'));
            $('#bulk_municipalities').val(municipalities.join('\n'));
            $('#bulk_provinces').val(provinces.join('\n'));
            $('#bulk_zipcodes').val(zipcodes.join('\n'));
        }

        // Bulk entries preview
        $('#preview_entries').click(function() {
            const studentNumbers = ($('#student_numbers').val() || '').trim();
            const studentLastnames = ($('#student_lastnames').val() || '').trim();
            const studentFirstnames = ($('#student_firstnames').val() || '').trim();

            if (!studentNumbers || !studentLastnames || !studentFirstnames) {
                alert('Please enter student numbers, last names, and first names to preview.');
                return;
            }

            const numbers = studentNumbers.split('\n').map(n => n.trim()).filter(n => n);
            const lastnames = studentLastnames.split('\n').map(n => titleCaseText(n)).filter(n => n);
            const firstnames = studentFirstnames.split('\n').map(n => titleCaseText(n)).filter(n => n);

            const middlenames = splitLines('#student_middlenames').map(titleCaseText);
            const extensions = splitLines('#student_extensions').map(e => e.trim());
            const birthdates = splitLines('#bulk_birthdates');
            const genders = splitLines('#bulk_genders');
            const contacts = splitLines('#bulk_contacts');
            const emails = splitLines('#bulk_emails');
            const barangays = splitLines('#bulk_barangays');
            const municipalities = splitLines('#bulk_municipalities');
            const provinces = splitLines('#bulk_provinces');
            const zipcodes = splitLines('#bulk_zipcodes');

            if (numbers.length !== lastnames.length || numbers.length !== firstnames.length) {
                alert('Number of student numbers must match the number of last names and first names. Please check your entries.');
                return;
            }

            const entries = [];
            let validEntries = 0;
            const previewRows = [];

            numbers.forEach((studentNumber, index) => {
                const lastname = lastnames[index] || '';
                const firstname = firstnames[index] || '';
                const middlename = middlenames[index] || '';
                const name_extension = extensions[index] || '';
                const birthdate = birthdates[index] || '';
                const gender = genders[index] || '';
                const contact = contacts[index] || '';
                const email = emails[index] || '';
                const barangay = barangays[index] || '';
                const municipality = municipalities[index] || '';
                const province = provinces[index] || '';
                const zipcode = zipcodes[index] || '';
                const fullName = composeFullName(lastname, firstname, middlename, name_extension);

                const studentNumberRegex = /^\d{2}-\d{5}$/;
                if (!studentNumberRegex.test(studentNumber)) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td colspan="6" class="text-danger">Invalid student number format (should be XX-XXXXX)</td></tr>`);
                    return;
                }

                if (!lastname || !firstname) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td colspan="6" class="text-danger">Last name and first name are required</td></tr>`);
                    return;
                }

                if (birthdate && !/^\d{4}-\d{2}-\d{2}$/.test(birthdate)) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td>${fullName}</td><td colspan="5" class="text-danger">Invalid birthdate format (should be YYYY-MM-DD)</td></tr>`);
                    return;
                }

                if (gender && !['Male', 'Female', 'Other'].includes(gender)) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td>${fullName}</td><td>${birthdate}</td><td colspan="4" class="text-danger">Gender must be Male, Female, or Other</td></tr>`);
                    return;
                }

                if (contact && !/^[0-9]{10,11}$/.test(contact)) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td>${fullName}</td><td>${birthdate}</td><td>${gender}</td><td colspan="3" class="text-danger">Invalid contact number format</td></tr>`);
                    return;
                }

                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td>${fullName}</td><td>${birthdate}</td><td>${gender}</td><td>${contact}</td><td colspan="2" class="text-danger">Invalid email format</td></tr>`);
                    return;
                }

                if (zipcode && !/^[0-9]{4}$/.test(zipcode)) {
                    previewRows.push(`<tr class="table-warning"><td>${studentNumber}</td><td>${fullName}</td><td>${birthdate}</td><td>${gender}</td><td>${contact}</td><td>${email}</td><td class="text-danger">Invalid zipcode format (should be 4 digits)</td></tr>`);
                    return;
                }

                validEntries++;
                if (validEntries <= 5) {
                    const address = [barangay, municipality, province, zipcode].filter(Boolean).join(', ') || '';
                    previewRows.push(`<tr><td>${studentNumber}</td><td>${fullName}</td><td>${birthdate}</td><td>${gender}</td><td>${contact}</td><td>${email}</td><td>${address}</td></tr>`);
                }

                entries.push({
                    studentNumber,
                    lastname,
                    firstname,
                    middlename,
                    name: fullName,
                    name_extension: name_extension || '',
                    birthdate,
                    gender,
                    contact_number: contact,
                    email,
                    barangay,
                    municipality,
                    province,
                    zipcode
                });
            });

            // Update preview table
            if (entries.length === 0) {
                previewRows.push('<tr><td colspan="7" class="text-center">No valid entries found</td></tr>');
            }

            $('#preview_table_body').html(previewRows.join(''));
            $('#total_entries').text(`Total valid entries: ${validEntries} (showing first 5)`);
            $('#preview_container').show();
        });
        // Process bulk entries
        function processBulkEntries() {
            const studentNumbers = ($('#student_numbers').val() || '').trim();
            const studentLastnames = ($('#student_lastnames').val() || '').trim();
            const studentFirstnames = ($('#student_firstnames').val() || '').trim();

            if (!studentNumbers || !studentLastnames || !studentFirstnames) {
                alert('Please enter student numbers, last names, and first names.');
                return false;
            }

            const numbers = studentNumbers.split('\n').map(n => n.trim()).filter(n => n);
            const lastnames = studentLastnames.split('\n').map(n => titleCaseText(n)).filter(n => n);
            const firstnames = studentFirstnames.split('\n').map(n => titleCaseText(n)).filter(n => n);

            const middlenames = splitLines('#student_middlenames').map(titleCaseText);
            const extensions = splitLines('#student_extensions').map(e => e.trim());
            const birthdates = splitLines('#bulk_birthdates');
            const genders = splitLines('#bulk_genders');
            const contacts = splitLines('#bulk_contacts');
            const emails = splitLines('#bulk_emails');
            const barangays = splitLines('#bulk_barangays');
            const municipalities = splitLines('#bulk_municipalities');
            const provinces = splitLines('#bulk_provinces');
            const zipcodes = splitLines('#bulk_zipcodes');

            if (numbers.length !== lastnames.length || numbers.length !== firstnames.length) {
                alert('Number of student numbers must match the number of last names and first names. Please check your entries.');
                return false;
            }

            const entries = [];
            let hasErrors = false;

            numbers.forEach((studentNumber, index) => {
                const lastname = lastnames[index] || '';
                const firstname = firstnames[index] || '';
                const middlename = middlenames[index] || '';
                const name_extension = extensions[index] || '';
                const birthdate = birthdates[index] || '';
                const gender = genders[index] || '';
                const contact = contacts[index] || '';
                const email = emails[index] || '';
                const barangay = barangays[index] || '';
                const municipality = municipalities[index] || '';
                const province = provinces[index] || '';
                const zipcode = zipcodes[index] || '';
                const fullName = composeFullName(lastname, firstname, middlename, name_extension);

                const studentNumberRegex = /^\d{2}-\d{5}$/;
                if (!studentNumberRegex.test(studentNumber)) {
                    alert(`Error on line ${index + 1}: Invalid student number format (should be XX-XXXXX)`);
                    hasErrors = true;
                    return;
                }

                if (!lastname || !firstname) {
                    alert(`Error on line ${index + 1}: Last name and first name are required`);
                    hasErrors = true;
                    return;
                }

                if (birthdate && !/^\d{4}-\d{2}-\d{2}$/.test(birthdate)) {
                    alert(`Error on line ${index + 1}: Invalid birthdate format (should be YYYY-MM-DD)`);
                    hasErrors = true;
                    return;
                }

                if (gender && !['Male', 'Female', 'Other'].includes(gender)) {
                    alert(`Error on line ${index + 1}: Gender must be Male, Female, or Other`);
                    hasErrors = true;
                    return;
                }

                if (contact && !/^[0-9]{10,11}$/.test(contact)) {
                    alert(`Error on line ${index + 1}: Invalid contact number format`);
                    hasErrors = true;
                    return;
                }

                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    alert(`Error on line ${index + 1}: Invalid email format`);
                    hasErrors = true;
                    return;
                }

                if (zipcode && !/^[0-9]{4}$/.test(zipcode)) {
                    alert(`Error on line ${index + 1}: Invalid zipcode format (should be 4 digits)`);
                    hasErrors = true;
                    return;
                }

                entries.push({
                    student_number: studentNumber,
                    lastname: lastname,
                    firstname: firstname,
                    middlename: middlename || null,
                    name: fullName,
                    name_extension: name_extension || null,
                    birthdate: birthdate || null,
                    gender: gender || null,
                    contact_number: contact || null,
                    email: email || null,
                    barangay: barangay || null,
                    municipality: municipality || null,
                    province: province || null,
                    zipcode: zipcode || null
                });
            });

            if (hasErrors || entries.length === 0) {
                return false;
            }

            return entries;
        }

        // Add keydown event listener to all input fields in the form
        $('#student-form input, #student-form textarea, #student-form select').on('keydown', function(e) {
            if (e.key === 'Enter' && !$(this).is('textarea')) {
                e.preventDefault();
                // Trigger the form's submit handler
                $('#student-form').trigger('submit');
            }
        });

        // Function to format bulk names with proper capitalization
        function formatBulkNames(textarea) {
            const cursorPos = textarea.selectionStart;
            const lines = textarea.value.split('\n');
            const formattedLines = lines.map(line => {
                // Capitalize first letter of each word
                return line.replace(/\b\w/g, l => l.toUpperCase());
            });

            textarea.value = formattedLines.join('\n');
            // Restore cursor position
            textarea.setSelectionRange(cursorPos, cursorPos);
        }

        // Format names when pasting into bulk name textareas
        $('#student_lastnames, #student_firstnames, #student_middlenames').on('paste', function() {
            setTimeout(() => {
                formatBulkNames(this);
            }, 0);
        });

        $('#student_lastnames, #student_firstnames, #student_middlenames').on('keyup', function(e) {
            if (e.key === ' ' || e.key === 'Enter') {
                formatBulkNames(this);
            }
        });

        $('#downloadStudentTemplateCsv').click(function() {
            const csvRows = [
                ['student_number','lastname','firstname','middlename','name_extension','birthdate','gender','contact_number','email','barangay','municipality','province','zipcode'],
                ['25-12345','Doe','John','Smith','Jr.','2000-01-15','Male','09123456789','john.doe@example.com','Barangay 1','Townsville','Province A','1234'],
                ['25-12346','Santos','Maria','Lopez','','2001-02-20','Female','09987654321','maria.santos@example.com','Barangay 2','Cityville','Province B','5678']
            ];
            const csvContent = csvRows.map(row => row.map(value => `"${String(value).replace(/"/g, '""')}"`).join(',')).join('\r\n');
            const blob = new Blob(['\ufeff', csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'student_import_template.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        });

        function ensureXLSXLoaded(callback) {
            if (typeof XLSX !== 'undefined') {
                callback();
                return;
            }

            const existingScript = document.getElementById('sheetjs-xlsx-script');
            if (existingScript) {
                existingScript.addEventListener('load', callback, { once: true });
                existingScript.addEventListener('error', function() {
                    $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Excel support failed to load. Please use CSV format instead.');
                }, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.id = 'sheetjs-xlsx-script';
            script.src = './js/xlsx.full.min.js';
            script.onload = callback;
            script.onerror = function() {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Excel support failed to load. Please use CSV format instead.');
            };
            document.head.appendChild(script);
        }

        $('#downloadStudentTemplateExcel').click(function() {
            ensureXLSXLoaded(function() {
                const templateRows = [
                    ['student_number','lastname','firstname','middlename','name_extension','birthdate','gender','contact_number','email','barangay','municipality','province','zipcode'],
                    ['25-12345','Doe','John','Smith','Jr.','2000-01-15','Male','09123456789','john.doe@example.com','Barangay 1','Townsville','Province A','1234'],
                    ['25-12346','Santos','Maria','Lopez','','2001-02-20','Female','09987654321','maria.santos@example.com','Barangay 2','Cityville','Province B','5678']
                ];
                const worksheet = XLSX.utils.aoa_to_sheet(templateRows);
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'Students');
                XLSX.writeFile(workbook, 'student_import_template.xlsx');
            });
        });

        $('#processBulkFile').click(function() {
            const fileInput = document.getElementById('bulkFileUpload');
            const file = fileInput.files[0];

            if (!file) {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-warning"></i> Please select a file to upload.');
                return;
            }

            const fileExtension = file.name.split('.').pop().toLowerCase();
            $('#fileUploadStatus').html('<i class="fas fa-spinner fa-spin me-1"></i> Processing file...');

            if (fileExtension === 'csv') {
                const reader = new FileReader();
                reader.onload = function(e) {
                    processStudentCSVFile(e.target.result);
                };
                reader.readAsText(file);
            } else if (fileExtension === 'xlsx' || fileExtension === 'xls') {
                ensureXLSXLoaded(function() {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        try {
                            const data = new Uint8Array(e.target.result);
                            const workbook = XLSX.read(data, { type: 'array' });
                            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                            const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
                            processStudentExcelData(jsonData);
                        } catch (error) {
                            $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Error processing Excel file: ' + error.message);
                        }
                    };
                    reader.readAsArrayBuffer(file);
                });
            } else {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Unsupported file format. Please use CSV or Excel files.');
            }
        });

        function normalizeImportedStudentNumber(value) {
            const raw = String(value ?? '').trim();
            if (!raw) return '';
            return formatStudentNumber(raw);
        }

        function mapStudentRow(columns, detectedColumns) {
            const getValue = (idx) => idx >= 0 ? String(columns[idx] ?? '').trim() : '';
            return {
                student_number: normalizeImportedStudentNumber(getValue(detectedColumns.student_number)),
                lastname: titleCaseText(getValue(detectedColumns.lastname)),
                firstname: titleCaseText(getValue(detectedColumns.firstname)),
                middlename: titleCaseText(getValue(detectedColumns.middlename)),
                name_extension: getValue(detectedColumns.name_extension).replace(/\.$/, ''),
                birthdate: getValue(detectedColumns.birthdate),
                gender: titleCaseText(getValue(detectedColumns.gender)),
                contact_number: getValue(detectedColumns.contact_number),
                email: getValue(detectedColumns.email),
                barangay: titleCaseText(getValue(detectedColumns.barangay)),
                municipality: titleCaseText(getValue(detectedColumns.municipality)),
                province: titleCaseText(getValue(detectedColumns.province)),
                zipcode: getValue(detectedColumns.zipcode)
            };
        }

        function detectStudentColumns(headers) {
            return {
                student_number: detectColumnIndex(headers, [
                    'student no', 'student no.', 'student nos', 'student nos.', 'student number', 'student numbers',
                    'student num', 'student nums', 'student #', 'student id', 'student identifier',
                    'student_number', 'student_numbers', 'student_num', 'student_nums',
                    'id no', 'id no.', 'id number', 'id numbers', 'school id', 'school id number',
                    'lrn', 'learner reference number'
                ]),
                lastname: detectColumnIndex(headers, [
                    'last name', 'lastname', 'last names', 'surname', 'surnames', 'family name', 'family names'
                ]),
                firstname: detectColumnIndex(headers, [
                    'first name', 'firstname', 'first names', 'given name', 'given names', 'forename', 'forenames'
                ]),
                middlename: detectColumnIndex(headers, [
                    'middle name', 'middlename', 'middle names', 'middle initial', 'middle initials', 'm i', 'mi'
                ]),
                name_extension: detectColumnIndex(headers, [
                    'extension', 'name extension', 'suffix', 'name suffix', 'jr', 'ext'
                ]),
                birthdate: detectColumnIndex(headers, [
                    'birthdate', 'birth date', 'date of birth', 'dob', 'birthday', 'b day'
                ]),
                gender: detectColumnIndex(headers, [
                    'gender', 'sex', 'gender/sex'
                ]),
                contact_number: detectColumnIndex(headers, [
                    'contact', 'contact no', 'contact no.', 'contact number', 'contact numbers',
                    'mobile', 'mobile no', 'mobile no.', 'mobile number', 'phone', 'phone number', 'cp number', 'cellphone'
                ]),
                email: detectColumnIndex(headers, [
                    'email', 'email address', 'e mail', 'mail'
                ]),
                barangay: detectColumnIndex(headers, [
                    'barangay', 'brgy', 'brgy.'
                ]),
                municipality: detectColumnIndex(headers, [
                    'municipality', 'municipalities', 'city', 'town', 'municipality/city', 'city municipality'
                ]),
                province: detectColumnIndex(headers, [
                    'province', 'state'
                ]),
                zipcode: detectColumnIndex(headers, [
                    'zipcode', 'zip code', 'zip codes', 'postal code', 'postal codes', 'zip'
                ])
            };
        }

        function processStudentCSVFile(csvText) {
            const lines = csvText.split('\n').filter(line => line.trim());
            if (!lines.length) {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> CSV file appears empty.');
                return;
            }

            const headers = lines[0].split(',').map(col => col.trim());
            const detectedColumns = detectStudentColumns(headers);
            const hasHeaders = detectedColumns.student_number >= 0 && detectedColumns.lastname >= 0 && detectedColumns.firstname >= 0;
            const startIndex = hasHeaders ? 1 : 0;
            const rows = [];

            for (let i = startIndex; i < lines.length; i++) {
                const columns = lines[i].split(',').map(col => col.trim());
                const mapped = hasHeaders
                    ? mapStudentRow(columns, detectedColumns)
                    : {
                        student_number: normalizeImportedStudentNumber(columns[0] || ''),
                        lastname: titleCaseText(columns[1] || ''),
                        firstname: titleCaseText(columns[2] || ''),
                        middlename: titleCaseText(columns[3] || ''),
                        name_extension: String(columns[12] || '').trim().replace(/\.$/, ''),
                        birthdate: String(columns[4] || '').trim(),
                        gender: titleCaseText(columns[5] || ''),
                        contact_number: String(columns[6] || '').trim(),
                        email: String(columns[7] || '').trim(),
                        barangay: titleCaseText(columns[8] || ''),
                        municipality: titleCaseText(columns[9] || ''),
                        province: titleCaseText(columns[10] || ''),
                        zipcode: String(columns[11] || '').trim()
                    };

                if (mapped.student_number || mapped.lastname || mapped.firstname) {
                    rows.push(mapped);
                }
            }

            if (rows.length) {
                populateBulkFieldsFromRows(rows);
                $('#fileUploadStatus').html(`<i class="fas fa-check-circle me-1 text-success"></i> Successfully parsed ${rows.length} student records from CSV.`);
                setTimeout(() => $('#preview_entries').click(), 300);
            } else {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> No valid student records found in CSV file.');
            }
        }

        function processStudentExcelData(jsonData) {
            if (jsonData.length < 1) {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Excel file appears empty or invalid.');
                return;
            }

            const headers = (jsonData[0] || []).map(col => String(col || '').trim());
            const detectedColumns = detectStudentColumns(headers);
            const hasHeaders = detectedColumns.student_number >= 0 && detectedColumns.lastname >= 0 && detectedColumns.firstname >= 0;
            const startIndex = hasHeaders ? 1 : 0;
            const rows = [];

            for (let i = startIndex; i < jsonData.length; i++) {
                const columns = jsonData[i] || [];
                const mapped = hasHeaders
                    ? mapStudentRow(columns, detectedColumns)
                    : {
                        student_number: normalizeImportedStudentNumber(columns[0] || ''),
                        lastname: titleCaseText(columns[1] || ''),
                        firstname: titleCaseText(columns[2] || ''),
                        middlename: titleCaseText(columns[3] || ''),
                        name_extension: String(columns[12] || '').trim().replace(/\.$/, ''),
                        birthdate: String(columns[4] || '').trim(),
                        gender: titleCaseText(columns[5] || ''),
                        contact_number: String(columns[6] || '').trim(),
                        email: String(columns[7] || '').trim(),
                        barangay: titleCaseText(columns[8] || ''),
                        municipality: titleCaseText(columns[9] || ''),
                        province: titleCaseText(columns[10] || ''),
                        zipcode: String(columns[11] || '').trim()
                    };

                if (mapped.student_number || mapped.lastname || mapped.firstname) {
                    rows.push(mapped);
                }
            }

            if (rows.length) {
                populateBulkFieldsFromRows(rows);
                $('#fileUploadStatus').html(`<i class="fas fa-check-circle me-1 text-success"></i> Successfully parsed ${rows.length} student records from Excel file.`);
                setTimeout(() => $('#preview_entries').click(), 300);
            } else {
                $('#fileUploadStatus').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> No valid student records found in Excel file.');
            }
        }

        $('#student-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove();
            const _this = $(this);
            const _el = $('<div>').addClass('pop_msg');
            const submitBtn = $('#uni_modal button[type="submit"]');
            const isBulkTabActive = $('#bulk-tab').hasClass('active');

            // Disable all buttons and show loading
            $('#uni_modal button').prop('disabled', true);
            submitBtn.text('Processing...');

            // Prepare form data
            let formData = new FormData(this);
            let url = './Actions.php?a=' + (isBulkTabActive ? 'bulk_save_students' : 'save_student');

            // If bulk tab is active, process entries first
            if (isBulkTabActive) {
                const entries = processBulkEntries();
                if (!entries) {
                    $('#uni_modal button').prop('disabled', false);
                    submitBtn.text('Save');
                    return;
                }
                // Add entries to form data
                formData.set('class_id', $('#bulk_class_id').val());
                formData.set('entries', JSON.stringify(entries));
            }

            // Submit the form
            $.ajax({
                url: url,
                data: formData,
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });

                    let errorMsg = "An error occurred while processing your request.";
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.msg) errorMsg = response.msg;
                        if (response.error) errorMsg += " " + response.error;
                    } catch (e) {
                        // If we can't parse the response, use the raw text
                        if (xhr.responseText) {
                            errorMsg += " Server response: " + xhr.responseText.substring(0, 200);
                        }
                    }

                    _el.addClass('alert alert-danger')
                       .html('<strong>Error:</strong> ' + errorMsg + '<br><small>Please check the console for more details.</small>')
                       .hide()
                       .prependTo(_this)
                       .show('slow');

                    $('#uni_modal button').prop('disabled', false);
                    submitBtn.text('Save');
                },
                success: function(resp) {
                    if (resp.status === 'success') {
                        _el.addClass('alert alert-success');

                        // Clear only the student number and name fields, keep the class selection
                        if (isBulkTabActive) {
                            // For bulk upload, clear all textareas and hide preview
                            $('#student_numbers').val('');
                            $('#student_lastnames').val('');
                            $('#student_firstnames').val('');
                            $('#student_middlenames').val('');
                            $('#student_extensions').val('');
                            $('#bulk_birthdates').val('');
                            $('#bulk_genders').val('');
                            $('#bulk_contacts').val('');
                            $('#bulk_emails').val('');
                            $('#bulk_barangays').val('');
                            $('#bulk_municipalities').val('');
                            $('#bulk_provinces').val('');
                            $('#bulk_zipcodes').val('');
                            $('#bulkFileUpload').val('');
                            $('#fileUploadStatus').html('');
                            $('#preview_container').hide();
                        } else {
                            // For single entry, clear all fields except class selection
                            $('#student_number').val('');
                            $('#name').val('');
                            $('#lastname').val('');
                            $('#firstname').val('');
                            $('#middlename').val('');
                            $('#name_extension').val('');
                            $('#birth_year').val('');
                            $('#birth_month').val('');
                            $('#birth_day').val('');
                            $('#birthdate').val('');
                            $('#age').val('');
                            $('#gender').val('');
                            $('#contact_number').val('');
                            $('#email').val('');
                            $('#barangay').val('');
                            $('#municipality').val('');
                            $('#province').val('');
                            $('#zipcode').val('');
                            // Set focus back to student number field
                            $('#student_number').focus();
                        }

                        // Show success message and refresh the student list without closing the modal
                        _el.addClass('alert alert-success')
                          .text(resp.msg || 'Student saved successfully')
                          .hide()
                          .prependTo(_this)
                          .show('slow')
                          .delay(500)
                          .fadeOut('slow', function() {
                              $(this).remove();

                              // Close the modal after message fades out


                              // Refresh the student list in the parent window
                              if (window.opener && typeof window.opener.refreshStudentList === 'function') {
                                  window.opener.refreshStudentList();
                              }
                          });

                        // Also refresh the student list in the background
                        if (typeof window.parent.refreshStudentList === 'function') {
                            window.parent.refreshStudentList();
                        }
                    } else {
                        _el.addClass('alert alert-danger')
                           .text(resp.msg || (resp.message || 'Operation completed.'))
                           .hide()
                           .prependTo(_this)
                           .show('slow');
                    }

                    $('#uni_modal button').prop('disabled', false);
                    submitBtn.text('Save');
                }
            });
        })
    })

    // =====================================================================
    // insertSpecialChar(fieldId, char)
    // The © ® ™ ñ Ñ dropdowns next to each name field call this with the
    // target field id. Inserts at the caret and re-syncs the hidden full name.
    // =====================================================================
    function insertSpecialChar(fieldId, char) {
        const field = document.getElementById(fieldId);
        if (!field) return;

        const start = field.selectionStart != null ? field.selectionStart : field.value.length;
        const end = field.selectionEnd != null ? field.selectionEnd : start;
        const text = field.value || '';

        field.value = text.substring(0, start) + char + text.substring(end, text.length);
        field.focus();

        const caret = start + String(char).length;
        try { field.setSelectionRange(caret, caret); } catch (e) {}

        // Keep the hidden full-name mirror in sync with the typed parts.
        if (typeof syncSingleFullName === 'function') syncSingleFullName();
    }
</script>
