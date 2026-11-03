<?php
require_once("DBConnection.php");
// Course/Section suggestions from landing registrations (own database)
if (!function_exists('landing_db')) {
    require_once("landing_db.php");
}
$landing_courses = [];
$sections_by_course = [];   // course => [section => count]
try {
    $landing_conn = landing_db();
    $lc_qry = $landing_conn->query("SELECT course, COUNT(*) as total FROM `landing_registrations` WHERE course IS NOT NULL AND TRIM(course) != '' GROUP BY course ORDER BY course ASC");
    if ($lc_qry) {
        while ($lc_row = $lc_qry->fetchArray(SQLITE3_ASSOC)) {
            $landing_courses[] = ['value' => $lc_row['course'], 'total' => (int)$lc_row['total']];
        }
    }
    // Sections per course, so the Section datalist can be narrowed to the
    // course picked in the form instead of listing every section on record.
    $ls_qry = $landing_conn->query("SELECT course, section, COUNT(*) as total FROM `landing_registrations` WHERE section IS NOT NULL AND TRIM(section) != '' AND course IS NOT NULL AND TRIM(course) != '' GROUP BY course, section");
    if ($ls_qry) {
        while ($ls_row = $ls_qry->fetchArray(SQLITE3_ASSOC)) {
            // Namespaced names: $course / $section are the form field variables.
            $catCourse = trim((string)$ls_row['course']);
            $catSection = trim((string)$ls_row['section']);
            if ($catCourse === '' || $catSection === '') continue;
            $sections_by_course[$catCourse][$catSection] = ($sections_by_course[$catCourse][$catSection] ?? 0) + (int)$ls_row['total'];
        }
    }
    $landing_conn->close();
} catch (Exception $e) {
    // Suggestions are optional - form still works without them
}
// Also count sections already used by existing classes, so a section that has
// classes but no registrations yet still shows up in the datalist.
try {
    $cls_qry = $conn->query("SELECT `grade`, `section`, COUNT(*) as `total` FROM `class_list` WHERE `section` IS NOT NULL AND TRIM(`section`) != '' AND `grade` IS NOT NULL AND TRIM(`grade`) != '' GROUP BY `grade`, `section`");
    if ($cls_qry) {
        while ($cls_row = $cls_qry->fetchArray(SQLITE3_ASSOC)) {
            $catCourse = trim((string)$cls_row['grade']);
            $catSection = trim((string)$cls_row['section']);
            if ($catCourse === '' || $catSection === '') continue;
            $sections_by_course[$catCourse][$catSection] = ($sections_by_course[$catCourse][$catSection] ?? 0) + (int)$cls_row['total'];
        }
    }
} catch (Exception $e) {}
ksort($sections_by_course);
foreach ($sections_by_course as $catKey => $catList) {
    ksort($sections_by_course[$catKey]);
}
if(isset($_GET['id'])){
    $qry = $conn->query("SELECT * FROM `class_list` where class_id = '{$_GET['id']}'");
        foreach($qry->fetchArray() as $k => $v){
            $$k = $v;
        }
    }
    ?>
<style>
    :root {
        --form-bg-light: #ffffff;
        --form-bg-dark: #1e293b;
        --form-text-light: #1a1a1a;
        --form-text-dark: #f8fafc;
        --form-border-light: #d2d6dc;
        --form-border-dark: #475569;
        --form-label-light: #2d3748;
        --form-label-dark: #e2e8f0;
        --form-section-bg-light: #ffffff;
        --form-section-bg-dark: rgba(30, 41, 59, 0.95);
        --primary-color: #FFD700;
        --secondary-color: #006400;
    }

    .form-container {
        max-width: 800px;
        margin: 0 auto;
        font-size: 9px;
        font-weight: bold;
    }
    
    .control-label {
        font-weight: bold;
        font-size: 9px;
        margin-bottom: 0.25rem;
        color: var(--form-label-light);
        display: block;
    }
    
    .form-control, .form-select {
        font-size: 9px;
        font-weight: bold;
        padding: 0.35rem 0.75rem;
        height: calc(1.5em + 0.5rem + 2px);
        border: 2px solid var(--form-border-light);
        border-radius: 8px;
        transition: all 0.3s ease;
        background: var(--form-bg-light);
        color: var(--form-text-light);
    }
    
    .form-control:focus, .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.2);
        background: var(--form-bg-light);
        color: var(--form-text-light);
        outline: none;
    }
    
    .form-control::placeholder, .form-select option {
        color: #6c757d;
        font-weight: normal;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }

    .form-hint {
        display: block;
        font-size: 8px;
        font-weight: normal;
        margin-top: 0.2rem;
        color: #6c757d;
        line-height: 1.3;
    }
    
    .pop_msg {
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        border-radius: 8px;
        font-size: 9px;
        font-weight: bold;
        border: none;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .pop_msg.alert-success {
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        color: #166534;
        border-left: 4px solid #22c55e;
    }
    
    .pop_msg.alert-danger {
        background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        color: #dc2626;
        border-left: 4px solid #ef4444;
    }
    
    .btn-submit {
        padding: 0.5rem 1.5rem;
        font-size: 9px;
        font-weight: bold;
        transition: all 0.3s ease;
        border-radius: 8px;
        border: none;
    }
    
    .form-section {
        background: var(--form-section-bg-light);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        margin-bottom: 1.5rem;
        border: 1px solid rgba(255, 215, 0, 0.3);
    }
    
    .form-section-title {
        font-size: 11px;
        font-weight: bold;
        color: var(--form-label-light);
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid rgba(255, 215, 0, 0.3);
        display: flex;
        align-items: center;
    }
    
    .form-section-title i {
        color: var(--primary-color);
        font-size: 12px;
    }
    
    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        .control-label {
            color: var(--form-label-dark);
        }
        
        .form-control, .form-select {
            background: rgba(30, 41, 59, 0.9);
            color: var(--form-text-dark);
            border-color: var(--form-border-dark);
        }
        
        .form-control:focus, .form-select:focus {
            background: rgba(30, 41, 59, 1);
            color: var(--form-text-dark);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.3);
        }
        
        .form-control::placeholder, .form-select option {
            color: #94a3b8;
        }
        
        .form-section {
            background: var(--form-section-bg-dark);
            border-color: rgba(255, 215, 0, 0.4);
        }
        
        .form-section-title {
            color: var(--form-label-dark);
            border-bottom-color: rgba(255, 215, 0, 0.4);
        }
        
        .pop_msg.alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.05) 100%);
            color: #4ade80;
            border-left-color: #22c55e;
        }
        
        .pop_msg.alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
            color: #f87171;
            border-left-color: #ef4444;
        }
    }
    
    /* Fetched class dropdown (Subject) - white text as requested */
    #subject_id {
        color: #ffffff !important;
        background-color: #1e293b !important;
        border-color: var(--form-border-dark) !important;
    }
    #subject_id:focus {
        color: #ffffff !important;
        background-color: #1e293b !important;
    }
    #subject_id option {
        color: #ffffff !important;
        background-color: #1e293b !important;
    }

    @media (max-width: 768px) {
        .form-container {
            padding: 0 0.75rem;
        }
        .form-section {
            padding: 1rem;
        }
        
        .form-section-title {
            font-size: 10px;
        }
        
        .control-label {
            font-size: 8px;
        }
    }
</style>

<div class="container-fluid form-container">
    <form action="" id="class-form">
        <input type="hidden" name="id" value="<?php echo isset($class_id) ? $class_id : '' ?>">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-journal-text me-2"></i>Class Information
                    </div>
                    <div class="row g-3">
                    <div class="col-md-12 form-group">
                        <label for="subject_id" class="control-label">Subject</label>
                        <select name="subject_id" id="subject_id" class="form-select form-select-sm rounded-0 text-white" required>
                            <option <?php echo (!isset($subject_id)) ? 'selected' : '' ?> disabled>Please Select Here</option>
                            <?php
                            $dept_qry = $conn->query("SELECT * FROM subjects  order by `name` asc");
                            while($row= $dept_qry->fetchArray()):
                            ?>
                                <option value="<?php echo $row['subject_id'] ?>" <?php echo (isset($subject_id) && $subject_id == $row['subject_id'] ) ? 'selected' : '' ?>><?php echo $row['name'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="grade" class="control-label">Course</label>
                        <input type="text" name="grade" autofocus id="grade" required list="landing-course-list" class="form-control form-control-sm rounded-0" value="<?php echo isset($grade) ? $grade : '' ?>">
                        <datalist id="landing-course-list">
                            <?php foreach($landing_courses as $lc): ?>
                                <option value="<?php echo htmlspecialchars($lc['value']) ?>"><?php echo htmlspecialchars($lc['value']) ?> (<?php echo $lc['total'] ?> registered)</option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="section" class="control-label">Year and Section</label>
                        <input type="text" name="section" id="section" required list="landing-section-list" class="form-control form-control-sm rounded-0" value="<?php echo isset($section) ? $section : '' ?>" autocomplete="off">
                        <datalist id="landing-section-list">
                            <?php foreach ($sections_by_course as $lc_course => $lc_sections): ?>
                            <optgroup label="<?php echo htmlspecialchars($lc_course, ENT_QUOTES) ?>">
                                <?php foreach ($lc_sections as $ls_value => $ls_total): ?>
                                <option value="<?php echo htmlspecialchars($ls_value, ENT_QUOTES) ?>"><?php echo htmlspecialchars($ls_value, ENT_QUOTES) ?> (<?php echo (int)$ls_total ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="form-hint" id="section-hint"></small>
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="course_no" class="control-label">Course Code</label>
                        <input type="text" name="course_no" id="course_no" required class="form-control form-control-sm rounded-0" value="<?php echo isset($course_no) ? $course_no : '' ?>">
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="semester" class="control-label">Semester</label>
                        <select name="semester" id="semester" required class="form-select form-select-sm rounded-0">
                            <option value="1st Semester" <?php echo (isset($semester) && $semester == '1st Semester') ? 'selected' : '' ?>>1st Semester</option>
                            <option value="2nd Semester" <?php echo (isset($semester) && $semester == '2nd Semester') ? 'selected' : '' ?>>2nd Semester</option>
                            <option value="Summer" <?php echo (isset($semester) && $semester == 'Summer') ? 'selected' : '' ?>>Summer</option>
                        </select>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="year" class="control-label">School Year</label>
                        <input type="text" name="year" id="year" placeholder="e.g. 2023-2024" required class="form-control form-control-sm rounded-0" value="<?php echo isset($year) ? $year : '' ?>">
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="unit" class="control-label">Unit</label>
                        <input type="number" name="unit" id="unit" min="1" max="10" required class="form-control form-control-sm rounded-0" value="<?php echo isset($unit) ? $unit : '3' ?>">
                    </div>
                    <div class="col-md-9 form-group">
                        <label for="schedule" class="control-label">Schedule</label>
                        <div id="schedule-container">
                            <?php
                            // Parse schedule string to extract multiple schedules
                            $schedules = [];
                            if(isset($schedule) && !empty($schedule)) {
                                // Split by " | " for multiple schedules
                                $schedule_parts = explode(' | ', $schedule);
                                foreach($schedule_parts as $part) {
                                    // Parse each schedule: "DAYS HH:MM AM/PM - HH:MM AM/PM"
                                    if(preg_match('/^([A-Z]+)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i', trim($part), $matches)) {
                                        $schedules[] = [
                                            'days' => strtoupper($matches[1]),
                                            'start_time' => $matches[2],
                                            'end_time' => $matches[3]
                                        ];
                                    }
                                }
                            }
                            
                            // If no schedules parsed, create one empty row
                            if(empty($schedules)) {
                                $schedules[] = ['days' => '', 'start_time' => '', 'end_time' => ''];
                            }
                            
                            // Generate schedule rows
                            foreach($schedules as $index => $sched):
                            ?>
                            <div class="schedule-row row g-2 mb-2">
                                <div class="col-12">
                                    <select name="days[]" class="form-select form-select-sm rounded-0 days-select" required>
                                        <option value="">Select Days</option>
                                        <option value="MONDAY" <?php echo ($sched['days'] == 'MONDAY') ? 'selected' : '' ?>>MONDAY</option>
                                        <option value="TUESDAY" <?php echo ($sched['days'] == 'TUESDAY') ? 'selected' : '' ?>>TUESDAY</option>
                                        <option value="WEDNESDAY" <?php echo ($sched['days'] == 'WEDNESDAY') ? 'selected' : '' ?>>WEDNESDAY</option>
                                        <option value="THURSDAY" <?php echo ($sched['days'] == 'THURSDAY') ? 'selected' : '' ?>>THURSDAY</option>
                                        <option value="FRIDAY" <?php echo ($sched['days'] == 'FRIDAY') ? 'selected' : '' ?>>FRIDAY (Fri)</option>
                                        <option value="SATURDAY" <?php echo ($sched['days'] == 'SATURDAY') ? 'selected' : '' ?>>SATURDAY (Sat)</option>
                                        <option value="MWF" <?php echo ($sched['days'] == 'MWF') ? 'selected' : '' ?>>MWF (Mon-Wed-Fri)</option>
                                        <option value="TTH" <?php echo ($sched['days'] == 'TTH') ? 'selected' : '' ?>>TTH (Tue-Thu)</option>
                                        <option value="MW" <?php echo ($sched['days'] == 'MW') ? 'selected' : '' ?>>MW (Mon-Wed)</option>
                                        <option value="DAILY" <?php echo ($sched['days'] == 'DAILY' || $sched['days'] == 'Daily') ? 'selected' : '' ?>>DAILY</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <input type="time" name="start_time[]" class="form-control form-control-sm rounded-0 start-time" required 
                                        value="<?php 
                                            if(!empty($sched['start_time'])) {
                                                $time24 = date('H:i', strtotime($sched['start_time']));
                                                echo $time24;
                                            }
                                        ?>">
                                </div>
                                <div class="col-6">
                                    <input type="time" name="end_time[]" class="form-control form-control-sm rounded-0 end-time" required 
                                        value="<?php 
                                            if(!empty($sched['end_time'])) {
                                                $time24 = date('H:i', strtotime($sched['end_time']));
                                                echo $time24;
                                            }
                                        ?>">
                                </div>
                                <div class="col-2">
                                    <button type="button" class="btn btn-danger btn-sm rounded-0 remove-schedule" style="display: <?php echo ($index == 0) ? 'none' : 'block'; ?>;">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-schedule" class="btn btn-primary btn-sm rounded-0 mt-2">
                            <i class="fas fa-plus"></i> Add Schedule
                        </button>
                    </div>
                    
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    $(function(){
        // Sections per course: landing registrations + existing classes.
        var SECTIONS_BY_COURSE = <?php echo json_encode($sections_by_course, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        // Narrow the Section datalist to the Course currently typed in #grade.
        function renderSectionList(course, keepValue) {
            var $list = $('#landing-section-list').empty();
            var $hint = $('#section-hint');
            var wanted = $.trim(course).toUpperCase();
            var counts = null;
            $.each(SECTIONS_BY_COURSE, function(key, sections) {
                if ($.trim(key).toUpperCase() === wanted) {
                    counts = sections;
                    return false;
                }
            });

            var names = counts ? Object.keys(counts) : [];
            // Never drop the section being edited just because it has no history.
            var keep = $.trim(keepValue);
            if (keep && $.inArray(keep, names) === -1) {
                names.push(keep);
            }
            names.sort();

            if (names.length) {
                var group = document.createElement('optgroup');
                group.label = wanted || 'Sections';
                names.forEach(function(name) {
                    var opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name + (counts && counts[name] ? ' (' + counts[name] + ')' : '');
                    group.appendChild(opt);
                });
                $list.append(group);
            }

            if (!wanted) {
                $hint.text('Type a course to see its sections.');
            } else if (!names.length) {
                $hint.text('No sections on record for ' + $.trim(course) + ' yet — you can type a new one.');
            } else {
                $hint.text(names.length + ' section' + (names.length === 1 ? '' : 's') + ' available for ' + $.trim(course) + '.');
            }
        }

        renderSectionList($('#grade').val(), $('#section').val());
        $('#grade').on('input change', function() {
            renderSectionList($(this).val(), $('#section').val());
        });
        $('#section').on('input', function() {
            renderSectionList($('#grade').val(), $(this).val());
        });

        // Add keydown event listener to all input fields in the form
        $('#class-form input').on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#class-form').submit();
            }
        });
        
        // Auto-format school year input
        $('#year').on('input', function() {
            let value = $(this).val().replace(/\D/g, ''); // Remove non-digits
            if (value.length > 4) {
                value = value.slice(0, 4) + '-' + value.slice(4, 8);
            }
            $(this).val(value);
        });

        // Add new schedule row
        $('#add-schedule').on('click', function() {
            const newRow = $('.schedule-row:first').clone();
            
            // Clear values in the new row
            newRow.find('select').val('');
            newRow.find('input[type="time"]').val('');
            
            // Show remove button
            newRow.find('.remove-schedule').show();
            
            // Append to container
            $('#schedule-container').append(newRow);
            
            // Update remove button visibility
            updateRemoveButtons();
        });

        // Remove schedule row
        $(document).on('click', '.remove-schedule', function() {
            $(this).closest('.schedule-row').remove();
            updateRemoveButtons();
        });

        // Update remove button visibility based on number of rows
        function updateRemoveButtons() {
            const rowCount = $('.schedule-row').length;
            $('.remove-schedule').toggle(rowCount > 1);
        }

        // Initialize remove button visibility
        updateRemoveButtons();

        $('#class-form').submit(function(e){
            e.preventDefault();
            
            // Form validation
            var subjectId = $('#subject_id').val();
            var grade = $('#grade').val().trim();
            var section = $('#section').val().trim();
            var courseNo = $('#course_no').val().trim();
            var semester = $('#semester').val();
            var year = $('#year').val().trim();
            var unit = $('#unit').val();
            
            // Validate schedule fields (check if at least one schedule is filled)
            var hasValidSchedule = false;
            $('.schedule-row').each(function() {
                var days = $(this).find('.days-select').val();
                var startTime = $(this).find('.start-time').val();
                var endTime = $(this).find('.end-time').val();
                if (days && startTime && endTime) {
                    hasValidSchedule = true;
                }
            });
            
            // Check required fields
            if (!subjectId || !grade || !section || !courseNo || !semester || !year || !unit || !hasValidSchedule) {
                var _el = $('<div class="pop_msg alert-danger">');
                _el.html('<strong>Error:</strong> Please fill in all required fields.');
                _el.hide().prependTo($(this)).show('slow');
                setTimeout(function() {
                    _el.fadeOut('fast', function() {
                        $(this).remove();
                    });
                }, 2000);
                return false;
            }
            
            // Validate year format (should be like 2023-2024 or 2023)
            if (!year.match(/^\d{4}(-\d{4})?$/)) {
                var _el = $('<div class="pop_msg alert-danger">');
                _el.html('<strong>Error:</strong> Year format should be like "2023-2024" or "2023".');
                _el.hide().prependTo($(this)).show('slow');
                setTimeout(function() {
                    _el.fadeOut('fast', function() {
                        $(this).remove();
                    });
                }, 2000);
                return false;
            }
            
            // Validate unit (should be between 1 and 10)
            if (unit < 1 || unit > 10) {
                var _el = $('<div class="pop_msg alert-danger">');
                _el.html('<strong>Error:</strong> Unit should be between 1 and 10.');
                _el.hide().prependTo($(this)).show('slow');
                setTimeout(function() {
                    _el.fadeOut('fast', function() {
                        $(this).remove();
                    });
                }, 2000);
                return false;
            }
            
            // Combine schedule fields into single string (handle multiple schedules)
            var scheduleStrings = [];
            
            // Convert 24-hour time to 12-hour format with AM/PM
            function convertTo12Hour(time24) {
                if (!time24) return '';
                var [hours, minutes] = time24.split(':');
                var period = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12 || 12; // Convert 0 to 12
                return hours + ':' + minutes + ' ' + period;
            }
            
            $('.schedule-row').each(function() {
                var days = $(this).find('.days-select').val();
                var startTime = $(this).find('.start-time').val();
                var endTime = $(this).find('.end-time').val();
                
                if (days && startTime && endTime) {
                    var startTime12 = convertTo12Hour(startTime);
                    var endTime12 = convertTo12Hour(endTime);
                    var scheduleString = days + ' ' + startTime12 + '-' + endTime12;
                    scheduleStrings.push(scheduleString);
                }
            });
            
            // Join multiple schedules with separator or use single schedule
            var finalSchedule = scheduleStrings.join(' | ');
            
            // Create hidden input for schedule
            $('<input>').attr({
                type: 'hidden',
                name: 'schedule',
                value: finalSchedule
            }).appendTo('#class-form');
            
            $('.pop_msg').remove()
            var _this = $(this)
            var _el = $('<div>')
                _el.addClass('pop_msg')
            $('#uni_modal button').attr('disabled',true)
                        $('#uni_modal button[type="submit"]').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...')
            $.ajax({
                url:'./Actions.php?a=save_class',
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
                error: function(xhr, status, error) {
                    console.log('AJAX Error:', xhr.responseText);
                    _el.addClass('alert alert-danger');
                    let errorMsg = 'An error occurred while saving the class.';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response && response.msg) {
                            errorMsg = response.msg;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                    }
                    _el.html('<strong>Error:</strong> ' + errorMsg);
                    _this.prepend(_el);
                    _el.show('slow');
                    $('#uni_modal button').attr('disabled', false);
                    $('#uni_modal button[type="submit"]').text('Save');
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        _el.addClass('alert alert-success');
                        _el.text(resp.msg).hide().prependTo(_this).show('slow');
                        
                        // Re-enable buttons immediately after successful response
                        $('#uni_modal button').attr('disabled', false);
                        $('#uni_modal button[type="submit"]').text('Save');
                        
                        // Auto-hide after 1.5 seconds without page reload
                        setTimeout(() => {
                            _el.fadeOut('fast', function() {
                                $(this).remove();
                                if("<?php echo isset($class_id) ?>" != 1) {
                                    _this.get(0).reset();
                                }
                                $('#uni_modal').modal('hide');
                                // Update any parent elements that might need refreshing
                                if(window.updateClassData) {
                                    updateClassData()
                                }
                            });
                        }, 1500);
                    } else {
                        _el.addClass('alert alert-danger');
                        _el.text(resp.msg || 'An error occurred');
                        _el.hide().prependTo(_this).show('slow');
                        // Re-enable buttons on error
                        $('#uni_modal button').attr('disabled', false);
                        $('#uni_modal button[type="submit"]').text('Save');
                    }
                }
            })
        })
    })
</script>