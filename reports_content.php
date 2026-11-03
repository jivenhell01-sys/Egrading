    <?php
    // Start output buffering to catch any accidental output
    ob_start();

    // Set error reporting for development
    ini_set('display_errors', 0);
    error_reporting(0);

    ?>
    <link rel="stylesheet" href="css/button.css">
    <hr>
    <!-- Signatory Modal -->
    <div class="modal fade" id="signatoryModal" tabindex="-1" role="dialog" aria-labelledby="signatoryModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="signatoryModalLabel">Add Signatory Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body">
                    <form id="signatoryForm">
                        <div class="form-group mb-3">
                            <label for="signatoryType" class="form-label">Signatory Type</label>
                            <select class="form-select" id="signatoryType" required>
                                <option value="certified">Certified True and Correct</option>
                                <option value="checked">Checked</option>
                                <option value="noted">Noted</option>
                                <option value="received">Received By</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label for="fullName" class="form-label">Full Name, W/ Title</label>
                            <input type="text" class="form-control" placeholder="Leave Blank if not needed!!!" id="fullName" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label for="designation" class="form-label">Designation</label>
                            <select class="form-select" id="designation" required>
                                <option value="" disabled selected>Select Designation</option>
                                <option value="College Dean">College Dean</option>
                                <option value="Program Head">Program Head</option>
                                <option value="Registrar III">Registrar III</option>
                                <option value="Lecturer">Lecturer</option>
                                <option value="Instructor I">Instructor I</option>
                                <option value="Instructor II">Instructor II</option>
                                <option value="Instructor III">Instructor III</option>
                                <option value="Professor I">Professor I</option>
                                <option value="Professor II">Professor II</option>
                                <option value="Professor III">Professor III</option>
                                <option value="Professor IV">Professor IV</option>
                                <option value="Professor V">Professor V</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saveSignatory">Save changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== PAGE HEADER ===================== -->
    <div class="rq-page-head">
        <div class="rq-page-head-main">
            <h1 class="rq-page-title">
                <span class="rq-page-title-icon"><i class="fas fa-clipboard-list"></i></span>
                Class Record
            </h1>
            <p class="rq-page-sub">Report of Grades &middot; pick a quarter, then a subject and section</p>
        </div>
        <div class="rq-page-head-quarter">
            <label for="quarter" class="rq-field-label"><i class="fas fa-calendar-alt me-1"></i>Quarter</label>
                        <select name="quarter" id="quarter" class="form-select form-select-sm" required>
                            <?php
                            // Quarter dropdown: just the quarter names available in
                            // `quarter_list`. One option per distinct name (value and
                            // text are both the name). The name is resolved to the
                            // class's quarter number when the report loads, so the
                            // same "Midterm" works whether a class numbers it 1 or 2.
                            $report_rq_names = [];
                            if (isset($conn)) {
                                $report_rq_qry = $conn->query("SELECT TRIM(name) AS name, MIN(quarter) AS minq FROM quarter_list WHERE TRIM(name) <> '' GROUP BY LOWER(TRIM(name)) ORDER BY minq ASC, name ASC");
                                if ($report_rq_qry) {
                                    while ($report_rq_row = $report_rq_qry->fetchArray(SQLITE3_ASSOC)) {
                                        $report_rq_nm = trim((string)$report_rq_row['name']);
                                        if ($report_rq_nm !== '') {
                                            $report_rq_names[] = $report_rq_nm;
                                        }
                                    }
                                }
                            }
                            $report_rq_current = isset($_GET['quarter']) ? (string)$_GET['quarter'] : '';
                            $report_rq_current_lower = function_exists('mb_strtolower') ? mb_strtolower($report_rq_current, 'UTF-8') : strtolower($report_rq_current);
                            // Legacy numeric URLs (?quarter=2): map the number to its
                            // name so the dropdown still shows the right selection.
                            $report_rq_current_name = null;
                            if ($report_rq_current !== '' && $report_rq_current !== 'active' && ctype_digit($report_rq_current) && isset($conn)) {
                                $report_rq_url_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
                                if ($report_rq_url_class > 0) {
                                    $report_rq_nm_row = $conn->querySingle("SELECT name FROM quarter_list WHERE class_id = '{$report_rq_url_class}' AND quarter = " . (int)$report_rq_current, true);
                                    if (is_array($report_rq_nm_row) && trim((string)($report_rq_nm_row['name'] ?? '')) !== '') {
                                        $report_rq_current_name = trim((string)$report_rq_nm_row['name']);
                                    }
                                }
                                if ($report_rq_current_name === null) {
                                    $report_rq_nm_row = $conn->querySingle("SELECT name FROM quarter_list WHERE quarter = " . (int)$report_rq_current . " ORDER BY class_id ASC LIMIT 1", true);
                                    if (is_array($report_rq_nm_row) && trim((string)($report_rq_nm_row['name'] ?? '')) !== '') {
                                        $report_rq_current_name = trim((string)$report_rq_nm_row['name']);
                                    }
                                }
                            }
                            ?>
                            <option value="" disabled <?php echo ($report_rq_current === '') ? 'selected' : '' ?>>Select Quarter</option>
                            <?php foreach ($report_rq_names as $report_rq_name): ?>
                            <?php
                                $report_rq_lower = function_exists('mb_strtolower') ? mb_strtolower($report_rq_name, 'UTF-8') : strtolower($report_rq_name);
                                $report_rq_selected = ($report_rq_current !== '' && $report_rq_current !== 'active' && ($report_rq_current === $report_rq_name || $report_rq_current_lower === $report_rq_lower || ($report_rq_current_name !== null && $report_rq_name === $report_rq_current_name)));
                            ?>
                            <option style="font-weight:bold;" value="<?php echo htmlspecialchars($report_rq_name, ENT_QUOTES) ?>" <?php echo $report_rq_selected ? 'selected' : '' ?>>
                                <?php echo htmlspecialchars($report_rq_name) ?>
                            </option>
                            <?php endforeach; ?>
                            <option style="font-weight:bold;" value="active" <?php echo ($report_rq_current === 'active') ? 'selected' : '' ?>> Active Quarters</option>
                        </select>
                        <script>

                            window.reportQuarterNamesCache = {};
                            window.reportQuarterAllNames = <?php echo json_encode(array_values($report_rq_names)); ?>;

                            // Restores the full distinct-name list (used when moving
                            // to a subject whose quarters the scoped dropdown lacks).
                            window.renderReportQuarterAll = function (currentVal) {
                                window.renderReportQuarterSelect(window.reportQuarterAllNames || [], currentVal);
                            };

                            // Simple flat list of quarter names (value and text are
                            // both the name, exactly as stored in quarter_list).
                            window.renderReportQuarterSelect = function (names, currentVal) {
                                var $sel = $('#quarter');
                                if (!$sel.length) return;
                                var current = (typeof currentVal === 'string') ? currentVal : String($sel.val() || '');
                                var list = Array.isArray(names) ? names.slice() : Object.keys(names || {}).map(function (k) { return names[k]; });

                                var escHtml = function (s) {
                                    return String(s)
                                        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                };

                                var html = '<option value="" disabled' + (current === '' ? ' selected' : '') + '>Select Quarter</option>';
                                for (var i = 0; i < list.length; i++) {
                                    var nm = String(list[i]);
                                    html += '<option style="font-weight:bold;" value="' + escHtml(nm) + '"' + (nm === current ? ' selected' : '') + '>' + escHtml(nm) + '</option>';
                                }
                                html += '<option style="font-weight:bold;" value="active"' + (current === 'active' ? ' selected' : '') + '> Active Quarters</option>';
                                $sel.html(html);
                            };

                            // Refreshes the dropdown with the selected class's own
                            // quarter names. `onDone(names)` receives the string list.
                            window.applyReportQuarterOptions = function (cid, onDone) {
                                var $sel = $('#quarter');
                                if (!$sel.length) return;
                                var classKey = String(cid || '').trim();
                                if (classKey === '') {
                                    return;
                                }

                                if (window.reportQuarterNamesCache[classKey]) {
                                    window.renderReportQuarterSelect(window.reportQuarterNamesCache[classKey]);
                                    if (typeof onDone === 'function') {
                                        onDone(window.reportQuarterNamesCache[classKey]);
                                    }
                                    return;
                                }

                                $.ajax({
                                    url: 'get_quarter_options.php',
                                    data: { class_id: classKey },
                                    dataType: 'json',
                                    cache: false,
                                    success: function (res) {
                                        if (res && res.status === 'success' && Array.isArray(res.options) && res.options.length) {
                                            var names = [];
                                            for (var i = 0; i < res.options.length; i++) {
                                                var nm = String(res.options[i].name || '');
                                                if (nm !== '' && names.indexOf(nm) === -1) {
                                                    names.push(nm);
                                                }
                                            }
                                            window.reportQuarterNamesCache[classKey] = names;
                                            window.renderReportQuarterSelect(names);
                                            try { localStorage.setItem('rq_last_class_id', classKey); } catch (e) {}
                                            if (typeof onDone === 'function') {
                                                onDone(names);
                                            }
                                        }
                                    },
                                    error: function () {
                                    }
                                });
                            };
                        </script>
                    </div>
    </div>

    <!-- ===================== NAVIGATION CARD ===================== -->
    <div class="rq-nav-card">
        <?php $class = isset($class) && is_array($class) ? $class : []; ?>
        <form action="" id="marks-form" class="mb-0">
                <?php
                $class_assessment_quarters = [];
                $assessment_quarter_qry = $conn->query("SELECT class_id, quarter FROM `assessment_list` GROUP BY class_id, quarter ORDER BY class_id ASC, quarter ASC");
                if ($assessment_quarter_qry) {
                    while($assessment_quarter_row = $assessment_quarter_qry->fetchArray(SQLITE3_ASSOC)) {
                        $assessment_class_id = (int)$assessment_quarter_row['class_id'];
                        if (!isset($class_assessment_quarters[$assessment_class_id])) {
                            $class_assessment_quarters[$assessment_class_id] = [];
                        }
                        $class_assessment_quarters[$assessment_class_id][] = (string)$assessment_quarter_row['quarter'];
                    }
                }

                // Custom quarters the instructor defined for a class in the
                // Assessments section (`quarter_list`). These are what the Quarter
                // dropdown above is built from, so they must ALSO decide whether a
                // subject/section tab can be shown. Reading only `assessment_list`
                // made a class whose custom quarters have no assessments yet
                // unreachable: its tabs stayed hidden for every quarter, so the
                // custom quarter could be selected in the dropdown but never led
                // anywhere.
                $class_custom_quarters = [];
                $custom_quarter_qry = $conn->query("SELECT class_id, quarter FROM `quarter_list` GROUP BY class_id, quarter ORDER BY class_id ASC, quarter ASC");
                if ($custom_quarter_qry) {
                    while($custom_quarter_row = $custom_quarter_qry->fetchArray(SQLITE3_ASSOC)) {
                        $custom_class_id = (int)$custom_quarter_row['class_id'];
                        if (!isset($class_custom_quarters[$custom_class_id])) {
                            $class_custom_quarters[$custom_class_id] = [];
                        }
                        $class_custom_quarters[$custom_class_id][] = (string)$custom_quarter_row['quarter'];
                    }
                }

                // Quarter names per class, exactly as stored in `quarter_list`.
                // Needed because the dropdown now carries names (not numbers)
                // and tabs must match by name.
                $class_ql_names = [];
                $class_ql_num_to_name = [];
                $class_ql_name_qry = $conn->query("SELECT class_id, quarter, name FROM quarter_list ORDER BY class_id ASC, quarter ASC");
                if ($class_ql_name_qry) {
                    while ($class_ql_row = $class_ql_name_qry->fetchArray(SQLITE3_ASSOC)) {
                        $qn_cid = (int)$class_ql_row['class_id'];
                        $qn_nm = trim((string)$class_ql_row['name']);
                        if ($qn_nm === '') {
                            continue;
                        }
                        if (!isset($class_ql_names[$qn_cid])) {
                            $class_ql_names[$qn_cid] = [];
                        }
                        if (!in_array($qn_nm, $class_ql_names[$qn_cid], true)) {
                            $class_ql_names[$qn_cid][] = $qn_nm;
                        }
                        $class_ql_num_to_name[$qn_cid][(string)(int)$class_ql_row['quarter']] = $qn_nm;
                    }
                }

                // Quarters a class can be shown for: its custom quarters UNION the
                // quarters that already hold assessments.
                $class_available_quarters = function ($class_id_value) use ($class_assessment_quarters, $class_custom_quarters) {
                    $merged = array_merge(
                        $class_assessment_quarters[$class_id_value] ?? [],
                        $class_custom_quarters[$class_id_value] ?? []
                    );
                    $merged = array_values(array_unique($merged));
                    sort($merged, SORT_NUMERIC);
                    return array_map('strval', $merged);
                };

                // Quarter NAMES a class can be shown for: its quarter_list names
                // plus the names backing any assessment quarters (fallback
                // 'Quarter N' when a quarter_list row is missing).
                $class_available_quarter_names = function ($class_id_value) use ($class_assessment_quarters, $class_ql_names, $class_ql_num_to_name) {
                    $names = $class_ql_names[$class_id_value] ?? [];
                    foreach (($class_assessment_quarters[$class_id_value] ?? []) as $aq) {
                        $aq = (string)$aq;
                        $nm = $class_ql_num_to_name[$class_id_value][$aq] ?? ('Quarter ' . $aq);
                        if (!in_array($nm, $names, true)) {
                            $names[] = $nm;
                        }
                    }
                    return array_values($names);
                };

                $subject_tabs = [];
                $class_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class,s.name as sname,(c.grade || ' - ' || c.section) as grade_sec FROM `class_list` c inner join `subjects` s on c.subject_id = s.subject_id order by s.name asc, c.grade asc, c.section asc");
                while($row= $class_qry->fetchArray()):
                    $class_id_value = (int)$row['class_id'];
                    $subject_id_value = (int)$row['subject_id'];
                    $assessment_quarters = array_values(array_unique($class_assessment_quarters[$class_id_value] ?? []));
                    sort($assessment_quarters, SORT_NUMERIC);
                    $assessment_quarters = array_map('strval', $assessment_quarters);
                    $available_quarters = $class_available_quarters($class_id_value);
                    $available_quarter_names = $class_available_quarter_names($class_id_value);
                    $row['available_quarters'] = $available_quarters;
                    $row['assessment_quarters'] = $assessment_quarters;
                    $row['available_quarter_names'] = $available_quarter_names;
                    $class[$class_id_value] = $row;

                    if (!isset($subject_tabs[$subject_id_value])) {
                        $subject_tabs[$subject_id_value] = [
                            'subject_id' => $subject_id_value,
                            'subject_name' => $row['sname'],
                            'available_quarters' => [],
                            'assessment_quarters' => [],
                            'available_quarter_names' => [],
                            'sections' => []
                        ];
                    }

                    $subject_tabs[$subject_id_value]['available_quarters'] = array_values(array_unique(array_merge($subject_tabs[$subject_id_value]['available_quarters'], $available_quarters)));
                    $subject_tabs[$subject_id_value]['assessment_quarters'] = array_values(array_unique(array_merge($subject_tabs[$subject_id_value]['assessment_quarters'], $assessment_quarters)));
                    foreach ($available_quarter_names as $aqn) {
                        $found = false;
                        foreach ($subject_tabs[$subject_id_value]['available_quarter_names'] as $existing) {
                            if (strcasecmp($existing, $aqn) === 0) { $found = true; break; }
                        }
                        if (!$found) {
                            $subject_tabs[$subject_id_value]['available_quarter_names'][] = $aqn;
                        }
                    }
                    $subject_tabs[$subject_id_value]['sections'][] = [
                        'class_id' => $class_id_value,
                        'grade_sec' => $row['grade_sec'],
                        'available_quarters' => $available_quarters,
                        'assessment_quarters' => $assessment_quarters,
                        'available_quarter_names' => $available_quarter_names
                    ];
                endwhile;

                $selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
                $selected_subject_id = ($selected_class_id > 0 && isset($class[$selected_class_id])) ? (int)$class[$selected_class_id]['subject_id'] : 0;
                ?>
                <input type="hidden" name="class_id" id="class_id" value="<?php echo $selected_class_id ?: '' ?>">
                <input type="hidden" name="subject_id" id="subject_id" value="<?php echo $selected_subject_id ?: '' ?>">
                <div class="rq-tab-rows">
                    <div class="col-12 d-none" id="subjectTabsWrapper">
                        <label class="rq-field-label"><i class="fas fa-book me-1"></i>Subject</label>
                        <div id="subjectTabs" class="nav nav-tabs border-bottom-0 report-class-tabs">
                            <?php foreach($subject_tabs as $subject_row): ?>
                                <button
                                    type="button"
                                    class="nav-link report-class-tab report-subject-tab <?php echo ($selected_subject_id === (int)$subject_row['subject_id']) ? 'active' : '' ?>"
                                    data-subject-id="<?php echo $subject_row['subject_id'] ?>"
                                    data-quarters='<?php echo htmlspecialchars(json_encode($subject_row['available_quarters'] ?? []), ENT_QUOTES, 'UTF-8') ?>'
                                    data-assessment-quarters='<?php echo htmlspecialchars(json_encode($subject_row['assessment_quarters'] ?? []), ENT_QUOTES, 'UTF-8') ?>'
                                    data-quarter-names='<?php echo htmlspecialchars(json_encode($subject_row['available_quarter_names'] ?? []), ENT_QUOTES, 'UTF-8') ?>'
                                >
                                    <?php echo htmlspecialchars($subject_row['subject_name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div id="subjectTabsEmptyState" class="rq-empty-hint d-none mb-0">
                            <i class="fas fa-circle-info me-1"></i>No subjects available for the selected quarter.
                        </div>
                    </div>
                    <div class="col-12 d-none" id="sectionTabsWrapper">
                        <label class="rq-field-label"><i class="fas fa-users me-1"></i>Section</label>
                        <div id="sectionTabs" class="nav nav-tabs border-bottom-0 report-class-tabs">
                            <?php foreach($subject_tabs as $subject_row): ?>
                                <?php foreach($subject_row['sections'] as $section_row): ?>
                                    <button
                                        type="button"
                                        class="nav-link report-class-tab report-section-tab <?php echo ($selected_class_id === (int)$section_row['class_id']) ? 'active' : '' ?>"
                                        data-subject-id="<?php echo $subject_row['subject_id'] ?>"
                                        data-class-id="<?php echo $section_row['class_id'] ?>"
                                        data-quarters='<?php echo htmlspecialchars(json_encode($section_row['available_quarters'] ?? []), ENT_QUOTES, 'UTF-8') ?>'
                                        data-assessment-quarters='<?php echo htmlspecialchars(json_encode($section_row['assessment_quarters'] ?? []), ENT_QUOTES, 'UTF-8') ?>'
                                        data-quarter-names='<?php echo htmlspecialchars(json_encode($section_row['available_quarter_names'] ?? []), ENT_QUOTES, 'UTF-8') ?>'
                                    >
                                        <?php echo htmlspecialchars($section_row['grade_sec']) ?>
                                    </button>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                        <div id="sectionTabsEmptyState" class="rq-empty-hint d-none mb-0">
                            <i class="fas fa-circle-info me-1"></i>No sections available for the selected subject and quarter.
                        </div>
                    </div>
                </div>
        </form>
    </div>
        <?php
        // Render report only when both class_id and quarter are provided and valid.
        // The dropdown carries quarter NAMES (distinct names from quarter_list);
        // a name is resolved to the class's own quarter number here, so the same
        // "Midterm" works whether a class numbers it 1 or 2. Legacy numeric
        // URLs (?quarter=2) keep working as before.
        if(isset($_GET['class_id']) && isset($_GET['quarter']) && (int)$_GET['class_id'] > 0 && ($_GET['quarter'] == 'active' || trim((string)$_GET['quarter']) !== '')):
            // Quarters are CUSTOM MADE per class (quarter_list). Labels are resolved
            // through $class_quarter_names below; unknown quarter numbers fall
            // back to a neutral 'Quarter N'.
            $class_id = (int)$_GET['class_id'];
            $is_active_quarters = $_GET['quarter'] == 'active';
            $rq_requested_quarter = $is_active_quarters ? 'active' : trim((string)$_GET['quarter']);
            $current_quarter = 0;
            if (!$is_active_quarters) {
                if (ctype_digit($rq_requested_quarter)) {
                    $current_quarter = (int)$rq_requested_quarter;
                } else {
                    // Name -> this class's quarter number (case-insensitive).
                    $rq_lower = function_exists('mb_strtolower') ? mb_strtolower($rq_requested_quarter, 'UTF-8') : strtolower($rq_requested_quarter);
                    $rq_found = $conn->querySingle("SELECT quarter FROM quarter_list WHERE class_id = '" . $class_id . "' AND LOWER(TRIM(name)) = '" . $conn->escapeString($rq_lower) . "' ORDER BY quarter ASC LIMIT 1", true);
                    if (is_array($rq_found) && isset($rq_found['quarter'])) {
                        $current_quarter = (int)$rq_found['quarter'];
                    } else {
                        echo "<div class='alert alert-warning'>Quarter '" . htmlspecialchars($rq_requested_quarter) . "' was not found for this class.</div>";
                        return;
                    }
                }
            }
            if (!$is_active_quarters && $current_quarter < 1) {
                echo "<div class='alert alert-warning'>Invalid quarter selected.</div>";
                return;
            }

            // Custom quarter names defined for this class (single source of truth).
            $class_quarter_names = [];
            $class_qname_qry = $conn->query("SELECT quarter, name FROM quarter_list WHERE class_id = '" . $class_id . "' ORDER BY quarter ASC");
            if ($class_qname_qry) {
                while ($class_qname_row = $class_qname_qry->fetchArray(SQLITE3_ASSOC)) {
                    $class_quarter_names[(int)$class_qname_row['quarter']] = $class_qname_row['name'];
                }
            }

            // Helper closure to safely resolve a quarter label for any quarter index.
            $resolve_quarter_name = function($q_index) use ($class_quarter_names) {
                $q_index = (int)$q_index;
                if ($q_index > 0) {
                    if (isset($class_quarter_names[$q_index]) && trim((string)$class_quarter_names[$q_index]) !== '') {
                        return $class_quarter_names[$q_index];
                    }
                    return 'Quarter ' . $q_index;
                }
                return '';
            };

            // Fetch class information
            $stmt = $conn->prepare("
                SELECT
                    c.*,
                    s.name as subject_name
                FROM
                    class_list c
                JOIN
                    subjects s ON c.subject_id = s.subject_id
                WHERE
                    c.class_id = :class_id
            ");
            $stmt->bindValue(':class_id', $class_id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $class_info = $result->fetchArray(SQLITE3_ASSOC);

            if (!$class_info) {
                echo "<div class='alert alert-danger'>Class not found.</div>";
                exit;
            }
        ?>

                <!-- ===================== ACTION TOOLBAR =====================
                     Every control for the page lives here: view/filters on the
                     left, entry helpers in the middle, output actions grouped in
                     a dropdown on the right. The Bulk Set Range panel is
                     collapsible so the default toolbar stays compact.
                     All ids/classes the existing JS binds to are preserved. -->
                    <div class="rq-toolbar no-print">
                        <div class="rq-toolbar-row">
                            <!-- View / filter group -->
                            <div class="rq-tool-group rq-tool-grow">
                                <div class="rq-search-wrap">
                                    <span class="rq-search-icon"><i class="fas fa-search"></i></span>
                                    <input type="text" id="studentSearch" class="form-control" placeholder="Search student name or grade&hellip;" aria-label="Search students">
                                    <button type="button" id="filterGradesAbove3" class="rq-tool-btn rq-tool-btn-warning" aria-pressed="false" title="Show students with Initial Grade below 75">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span class="rq-tool-btn-label">Failed</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Entry helpers group -->
                            <div class="rq-tool-group">
                                <button type="button" id="rqFastEntryToggle" class="toggle-enter-btn rq-tool-btn" aria-pressed="false" title="Pick a component + assessment header, then Tab/Enter in Search jumps to that cell">
                                    <span class="toggle-enter-label">Fast Single Entry</span>
                                    <span class="toggle-enter-switch">
                                        <span class="toggle-enter-knob"></span>
                                        <span class="toggle-enter-text"></span>
                                    </span>
                                </button>
                                <small id="rqFastHint" class="rq-hint d-none d-lg-inline">Fast entry off</small>
                            </div>

                            <!-- Output group -->
                            <div class="rq-tool-group">
                                <div class="dropdown">
                                    <button class="rq-tool-btn rq-tool-btn-primary dropdown-toggle" type="button"
                                            id="rqOutputBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true" title="Print, export and signatory actions">
                                        <i class="fas fa-file-export"></i>
                                        <span class="rq-tool-btn-label">Output</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end rq-output-menu" id="rqOutputMenu" aria-labelledby="rqOutputBtn">
                                        <li><h6 class="dropdown-header">Print &amp; Export</h6></li>
                                        <li>
                                            <button type="button" id="print" class="dropdown-item" title="Print the class record">
                                                <i class="fas fa-print"></i> Print Class Record
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" id="exportWord" class="dropdown-item" title="Export the report of grades to Word">
                                                <i class="fas fa-file-word"></i> Export Report of Grades
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">Sign-off</h6></li>
                                        <li>
                                            <button type="button" id="addSignatory" class="dropdown-item" title="Add or update a signatory">
                                                <i class="fas fa-signature"></i> Add Signatory
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                                <button type="button" id="rqBulkToggle" class="rq-tool-btn rq-tool-btn-ghost" aria-expanded="false" aria-controls="rqBulkPanel" title="Bulk Set Range: rewrite every score in a column that holds the From value">
                                    <i class="fas fa-sliders-h"></i>
                                    <span class="rq-tool-btn-label">Bulk Set Range</span>
                                </button>
                            </div>
                        </div>

                        <!-- Bulk Set Range: a blank To clears the matching
                             scores; a blank From targets the blank ones. -->
                        <div class="rq-bulk-panel" id="rqBulkPanel">
                            <div class="rq-bulk-grid">
                                <div class="rq-bulk-field">
                                    <label for="rqBulkColumn" class="rq-field-label"><i class="fas fa-columns me-1"></i>Column</label>
                                    <select id="rqBulkColumn" class="form-select form-select-sm" title="Choose the assessment column to rewrite">
                                        <option value="">Select a column</option>
                                    </select>
                                </div>
                                <div class="rq-bulk-field">
                                    <label class="rq-field-label"><i class="fas fa-arrow-right-arrow-left me-1"></i>Range</label>
                                    <div class="input-group input-group-sm rq-bulk-range">
                                        <span class="input-group-text">From</span>
                                        <input type="number" id="rqBulkFrom" class="form-control" placeholder="0" min="0" step="0.01" value="0" title="Leave blank to target only the blank (ungraded) scores">
                                        <span class="input-group-text">To</span>
                                        <input type="number" id="rqBulkTo" class="form-control" placeholder="&mdash;" min="0" step="0.01" title="Leave blank to clear the matching scores">
                                    </div>
                                </div>
                                <div class="rq-bulk-field rq-bulk-actions">
                                    <button type="button" id="rqBulkApply" class="btn btn-sm btn-primary" title="Apply the range to the selected column">
                                        <i class="fas fa-sync me-1"></i> Apply
                                    </button>
                                    <button type="button" id="rqBulkUndo" class="btn btn-sm btn-outline-secondary" style="display: none;" title="Restore the scores replaced by the last bulk range">
                                        <i class="fas fa-undo me-1"></i> Undo
                                    </button>
                                </div>
                                <div class="rq-bulk-field rq-bulk-note">
                                    <small id="rqBulkStatus" class="rq-hint">Pick a column, then set the range.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12" id="outprint">
                               <div class="row" >
                                   <div class="col-md-12 py-3 w-100 ">
                                       <?php
                                       $components = array();
                                       $components_query = $conn->query("SELECT c.*,g.name FROM `component_subject_percentage` c inner join `grading_components` g on c.component_id = g.component_id where c.subject_id = '" . $conn->escapeString($class[$_GET['class_id']]['subject_id']) . "' AND CAST(c.percentage AS REAL) > 0 order by component_id asc ");
                                       if ($components_query) {
                                           while($row = $components_query->fetchArray(SQLITE3_ASSOC)){
                                               $components[$row['component_id']] = $row;
                                           }
                                       }
                                       $assess = array();
                                       $assess_ids = array();
                                       $active_quarters = array();

                                       // Query to find active quarters (quarters with assessments)
                                       if ($is_active_quarters) {
                                           $active_quarters_query = $conn->query("SELECT DISTINCT quarter FROM `assessment_list` where class_id = '" . $conn->escapeString($_GET['class_id']) . "' ORDER BY quarter");
                                           if ($active_quarters_query) {
                                               while($row = $active_quarters_query->fetchArray(SQLITE3_ASSOC)){
                                                   $active_quarters[] = $row['quarter'];
                                               }
                                           }
                                           $assess_query = $conn->query("SELECT * FROM `assessment_list` where class_id = '" . $conn->escapeString($_GET['class_id']) . "' AND quarter IN (" . implode(',', $active_quarters) . ") order by quarter, `name` asc ");
                                       } else {
                                           $assess_query = $conn->query("SELECT * FROM `assessment_list` where class_id = '" . $conn->escapeString($_GET['class_id']) . "' and quarter = '" . $current_quarter . "' order by `name` asc ");
                                       }
                                       $component_totals = array(); // To store total percentage for each component

                                       // First pass: Store assessments and calculate total percentage per component
                                       if ($assess_query) {
                                           while($row = $assess_query->fetchArray(SQLITE3_ASSOC)){
                                               if ($is_active_quarters) {
                                                   // Organize by component -> quarter -> assessment for active quarters view
                                                   $assess[$row['component_id']][$row['quarter']][$row['assessment_id']] = $row;
                                               } else {
                                                   // Original structure for single quarter view
                                                   $assess[$row['component_id']][$row['assessment_id']] = $row;
                                               }
                                               $assess_ids[] = $row['assessment_id'];
                                               // Initialize component total if not set
                                               if (!isset($component_totals[$row['component_id']])) {
                                                   $component_totals[$row['component_id']] = 0;
                                               }
                                               // Add to component total (ensure percentage is treated as a number)
                                               $component_totals[$row['component_id']] += floatval($row['percentage']);
                                           }
                                       }

                                       // Second pass: Calculate weight for each assessment
                                       foreach ($assess as $component_id => $assessments) {
                                           $component_total = $component_totals[$component_id] > 0 ? $component_totals[$component_id] : 100; // Avoid division by zero
                                           if ($is_active_quarters) {
                                               // Handle active quarters structure: component -> quarter -> assessment
                                               foreach ($assessments as $quarter_num => $quarter_assessments) {
                                                   foreach ($quarter_assessments as $assessment_id => $assessment) {
                                                       // Calculate weight as a percentage of the component's total percentage
                                                       $weight = ($assessment['percentage'] / $component_total) * 100;
                                                       $assess[$component_id][$quarter_num][$assessment_id]['weight'] = $weight;
                                                   }
                                               }
                                           } else {
                                               // Handle single quarter structure: component -> assessment
                                               foreach ($assessments as $assessment_id => $assessment) {
                                                   // Calculate weight as a percentage of the component's total percentage
                                                   $weight = ($assessment['percentage'] / $component_total) * 100;
                                                   $assess[$component_id][$assessment_id]['weight'] = $weight;
                                               }
                                           }
                                       }
                                       // Safeguard: if there are no assessments, use a harmless IN list to avoid SQL error
                                       $a_ids = !empty($assess_ids) ? implode(",", array_map('intval', $assess_ids)) : '0';
                                       $marks = array();
                                       $students = array();
                                       $marks_qry = $conn->query("SELECT * FROM `mark_list` WHERE assessment_id IN ({$a_ids}) ");
                                       if ($marks_qry) {
                                           while($row = $marks_qry->fetchArray(SQLITE3_ASSOC)){
                                               $marks[$row['student_id']][$row['assessment_id']] = $row['mark'];
                                           }
                                       }
                                       $student_query = $conn->query("SELECT * FROM student_list WHERE class_id = '" . $conn->escapeString($_GET['class_id']) . "' ORDER BY TRIM(name) COLLATE NOCASE");
                                       $students = array();
                                       $student_status = array();
                                       $student_status_details = array(); // Store detailed status info for active quarters

                                       if ($student_query) {
                                           while($row = $student_query->fetchArray(SQLITE3_ASSOC)){
                                               $students[$row['student_id']] = $row['name'];

                                               // Store detailed status information
                                               $student_status_details[$row['student_id']] = [
                                                   'status' => $row['status'] ?? 'Active',
                                                   'status_quarter' => $row['status_quarter']
                                               ];

                                           if ($is_active_quarters) {
                                               // For active quarters, show status as-is since we need to display quarter-specific info
                                               $student_status[$row['student_id']] = $row['status'] ?? 'Active';
                                           } else {
                                               $current_quarter = $current_quarter > 0 ? $current_quarter : 1;
                                               // For NoSub and NoExam statuses, only show if they apply to the current quarter
                                               if (($row['status'] === 'NoSub' || $row['status'] === 'NoExam') && $row['status_quarter'] != null) {
                                                   if ($row['status_quarter'] == $current_quarter) {
                                                       $student_status[$row['student_id']] = $row['status'];
                                                   } else {
                                                       $student_status[$row['student_id']] = 'Active'; // Show as Active for other quarters
                                                   }
                                               } else {
                                                   // For Active and Dropped, show as-is (they apply to all quarters)
                                                   $student_status[$row['student_id']] = $row['status'] ?? 'Active';
                                               }
                                           }
                                       }
                                       }
                                       $c_perc_arr = array();
                                       foreach($components as $row){
                                           $c_perc_arr[$row['component_id']] =( $row['percentage']/100);
                                       }
                                         $__trans_system = function_exists('get_active_transmutation_system') ? get_active_transmutation_system($conn) : 'CHED';
                                         $depEdReport = ($__trans_system === 'DEPED');
                                         $transmutaion_query = $conn->query("SELECT * FROM transmutation_table WHERE `system` = '{$__trans_system}' ORDER BY `from` ASC");
                                       $trans_tbl = array();
                                       while($row = $transmutaion_query->fetchArray(SQLITE3_ASSOC)){
                                           $trans_tbl[] = [
                                               'grade' => (float)$row['grade'],
                                               'from' => (float)$row['from'],
                                               'to' => (float)$row['to']
                                           ];
                                       }
                                       function transmute($grade = 0){
                                           global $trans_tbl;
                                           $transmuted = 0;
                                           foreach($trans_tbl as $row){
                                               if($row['from'] <= $grade && $grade <= $row['to']){
                                                   $transmuted = $row['grade'];
                                                   break;
                                               }
                                           }
                                           return $transmuted;
                                       }
                                       // Convert transmuted grade (60-100) to 1.0–5.0 equivalent
                                       function get_equivalent_from_transmutation($grade) {
                                           global $trans_tbl;

                                           $grade = round(floatval($grade), 2);
                                           $equivalent = 5.5; // Default numeric value if no range matches

                                           foreach ($trans_tbl as $row) {
                                               if ($grade >= $row['from'] && $grade <= $row['to']) {
                                                   $equivalent = (float)$row['grade'];
                                                   break;
                                               }
                                           }

                                            // DepEd transmuted grades (60-100 scale) pass through untouched.
                                            if ($equivalent > 5.5) {
                                                return number_format($equivalent, 1);
                                            }

                                            // If equivalent is above 5.0, treat as DROPPED
                                            if ($equivalent > 5.0 && $equivalent != 5.5) {
                                               return '5.0';
                                           } elseif ($equivalent === 5.5) {
                                               return 'DR';
                                           }

                                           return number_format($equivalent, 1);
                                       }

                                       // Raw component score rescaled onto a 55-95 band:
                                       //   (score / total items) * 40 + 55
                                       // A perfect score lands on 95, a zero score on 55.
                                       // $total_items is the sum of the component's
                                       // assessment HPS for that quarter.
                                       function get_scaled_component_raw($score, $total_items) {
                                           $total_items = floatval($total_items);
                                           if ($total_items <= 0) {
                                               return 0.0;
                                           }
                                           $score = floatval($score);
                                           return (($score / $total_items) * 40) + 55;
                                       }

                                       // Weighted Percentage column = scaled raw score
                                       // multiplied by the component percentage expressed
                                       // as a decimal (e.g. 30% -> 0.30).
                                       function get_weighted_percentage($score, $total_items, $component_percentage) {
                                           $raw = get_scaled_component_raw($score, $total_items);
                                           return $raw * (floatval($component_percentage) / 100);
                                       }


                                       // Round Half Even (bankers rounding: ties go to the nearest even).
                                       // PHP's round(..., PHP_ROUND_HALF_EVEN) does this natively, but
                                       // binary-float noise ((2.3 + 5.0) / 2 is 3.64999999999999991,
                                       // not 3.65) would make a naive round() miss the half entirely.
                                       // So scale first, strip the noise, then round ties to even,
                                       // matching the grading policy:
                                       //     2.45 -> 2.4
                                       //     2.55 -> 2.6
                                       //     3.05 -> 3.0
                                       //     3.65 -> 3.6 (e.g. midterm 2.3 + final 5.0 = 7.3 / 2)
                                       // MUST stay identical to report_of_grades.php / export_to_word.php or the
                                       // Quarterly average here would not match the report or the export.
                                       function round_half_even($value, $precision = 0) {
                                           $value = (float)$value;
                                           if (!is_finite($value)) {
                                               return $value;
                                           }
                                           $precision = (int)$precision;
                                           $sign = ($value < 0) ? -1 : 1;
                                           $factor = pow(10, $precision);
                                           // Scale, then strip binary-float noise (3.65 * 10 can be 36.499999...).
                                           $scaled = round(abs($value) * $factor, 10);
                                           // Bankers rounding: ties go to the nearest even integer.
                                           $out = round($scaled, 0, PHP_ROUND_HALF_EVEN);
                                           // Re-round at the target precision to strip the scaling artefact.
                                           return $sign * round($out / $factor, $precision);
                                       }

                                       // Legacy Round Half Up (halves away from zero), kept for
                                       // non-average uses. Averages must use round_half_even() above.
                                       function round_half_up($value, $precision = 0) {
                                           $value = (float)$value;
                                           if (!is_finite($value)) {
                                               return $value;
                                           }
                                           $precision = (int)$precision;
                                           $sign = ($value < 0) ? -1 : 1;
                                           $factor = pow(10, $precision);
                                           // Scale, then strip binary-float noise (3.05 * 10 can be 30.499999...)
                                           $scaled = round(abs($value) * $factor, 10);
                                           $out = floor($scaled + 0.5);
                                           // Re-round at the target precision to strip the scaling artefact
                                           return $sign * round($out / $factor, $precision);
                                       }

                                       // Quarterly Average = mean of the quarters' TRANSMUTED grades, i.e.
                                       // the values shown in the quarter columns. Deliberately NOT
                                       // "transmute the mean of the raw initial grades" - the two disagree by
                                       // up to a full transmutation step.
                                       // Non-numeric entries ('INC', 'N/A') are skipped; returns null when
                                       // nothing numeric is available.
                                       // Half-even rounding applies, e.g. midterm 2.3 + final 5.0 = 3.65 -> 3.6.
                                       // MUST stay identical to report_of_grades.php / export_to_word.php.
                                       function get_average_of_transmuted($values, $precision = 1) {
                                           $nums = array();
                                           foreach ((array)$values as $v) {
                                               if (is_numeric($v)) {
                                                   $nums[] = (float)$v;
                                               }
                                           }
                                           if (empty($nums)) {
                                               return null;
                                           }
                                           return round_half_even(array_sum($nums) / count($nums), $precision);
                                       }
                                       function transmuted_to_equivalent($from, $to = null) {
                                           // Handle both old and new function signatures
                                           if ($to === null) {
                                               $g = floatval($from);
                                               if ($g <= 60) return number_format(5.0, 1);
                                               if ($g == 100) return number_format(1.0, 1);
                                               $equiv = 5.0 - (($g - 60.0) / 35.0) * 4.0; // maps 60->5.0 down to 95->1.0
                                               if ($equiv < 1.0) $equiv = 1.0;
                                               if ($equiv > 5.0) $equiv = 5.0;
                                               return number_format($equiv, 1);
                                           } else {
                                               // New implementation using from and to
                                               $from = floatval($from);
                                               $to = floatval($to);
                                               $midpoint = ($from + $to) / 2;

                                               // Handle edge cases
                                               if ($midpoint <= 50) return 5.0;
                                               if ($midpoint == 100) return 1.0;

                                               // Calculate equivalent based on the range
                                               if ($midpoint <= 75) {
                                                   // Linear interpolation between 50 (5.5) and 75 (3.0)
                                                   $equiv = 5.0 - (($midpoint - 50) / (75 - 50)) * 2.5;
                                               } else {
                                                   // Linear interpolation between 75 (3.0) and 100 (1.0)
                                                   $equiv = 3.0 - (($midpoint - 75) / (100 - 75)) * 2.0;
                                               }

                                               // Ensure the equivalent is within bounds
                                               return max(1.0, min(5.0, $equiv));
                                           }
                                       }
                                       ?>
                                       <?php if(empty($components)): ?>
                                           <div class="alert alert-warning rounded-0 py-2">No component percentages configured for this subject. Please configure them in the Percentages section.</div>
                                       <?php else: ?>


                                       <div class="table-responsive freeze-panes-container" style="max-height: 80vh; ">

                                            <?php if ($is_active_quarters): ?>
                                            <!-- Active Quarters Table Structure -->
                                            <table class="table table-hover table-bordered b-100 rq-grades" id="gradesTable" style="min-width: 1200px; max-width: 100%; page-break-after: avoid;">
                                                <thead class="sticky-top table-header rq-thead">
                                                    <tr class="rq-row-main">
                                                        <th class="py-2 px-2 text-center align-middle sticky-col rq-h-main rq-h-student" rowspan="4" style="min-width: 175px; max-width: 250px; position: sticky; left: 0; z-index: 10;">Student</th>
                                                       <?php
                                                       $total_colspan = 0;
                                                       foreach($components as $component_id => $component):
                                                           $component_colspan = 0;
                                                           foreach($active_quarters as $q_num):
                                                               if(isset($assess[$component_id][$q_num])):
                                                                   $component_colspan += count($assess[$component_id][$q_num]) + 2; // assessments + Total + Avg + %
                                                               else:
                                                                   $component_colspan += 2; // No assessments placeholder
                                                               endif;
                                                           endforeach;
                                                           $total_colspan += $component_colspan;
                                                       ?>
                                                         <th class="py-2 px-2 text-center rq-h-main rq-h-component" data-component-id="<?php echo $component_id ?>" colspan="<?php echo $component_colspan ?>">
                                                            <div class="d-flex flex-column align-items-center gap-1">
                                                                <span class="rq-comp-name"><?php echo htmlspecialchars($component['name']); ?></span>
                                                                <small class="rq-comp-pct"><?php echo $component['percentage'] ?>%</small>
                                                            </div>
                                                        </th>
                                                        <?php endforeach; ?>

                                                        <!-- Transmuted Grade Headers for Each Quarter -->
                                                        <?php foreach($active_quarters as $q_num): ?>
                                                        <th class="py-2 px-2 text-center align-middle rq-h-main rq-h-grade" rowspan="4" width="40px"><?php echo htmlspecialchars($resolve_quarter_name($q_num)) ?><span class="rq-h-sub">Grade</span></th>
                                                        <?php endforeach; ?>

                                                        <th class="py-2 px-2 text-center align-middle rq-h-main rq-h-grade rq-h-avg" rowspan="4" width="40px">AVG<span class="rq-h-sub">Grade</span></th>
                                                   </tr>
                                                    <tr class="rq-row-quarter">
                                                        <?php
                                                        foreach($components as $component_id => $component):
                                                            foreach($active_quarters as $q_num):
                                                                $quarter_name = $resolve_quarter_name($q_num);
                                                                if(isset($assess[$component_id][$q_num])):
                                                                    $assessment_count = count($assess[$component_id][$q_num]);
                                                                ?>
                                                                <th class="py-1 px-2 text-center rq-h-quarter" data-component-id="<?php echo $component_id ?>" data-quarter="<?php echo $q_num ?>" colspan="<?php echo $assessment_count + 2 ?>">
                                                                    <?php echo htmlspecialchars($quarter_name) ?>
                                                                    <button type="button" class="rq-add-assess-btn" data-component-id="<?php echo $component_id ?>" data-quarter="<?php echo $q_num ?>" title="Add assessment">
                                                                        <i class="fas fa-plus"></i>
                                                                    </button>
                                                                </th>
                                                                <?php else: ?>
                                                                <th class="py-1 px-2 text-center rq-h-quarter rq-h-empty" data-component-id="<?php echo $component_id ?>" data-quarter="<?php echo $q_num ?>" colspan="2">
                                                                    <?php echo htmlspecialchars($quarter_name) ?>
                                                                    <button type="button" class="rq-add-assess-btn" data-component-id="<?php echo $component_id ?>" data-quarter="<?php echo $q_num ?>" title="Add assessment">
                                                                        <i class="fas fa-plus"></i>
                                                                    </button>
                                                                </th>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                    <tr class="rq-row-assess">
                                                        <?php
                                                        foreach($components as $component_id => $component):
                                                            foreach($active_quarters as $q_num):
                                                                if(isset($assess[$component_id][$q_num])):
                                                                    foreach($assess[$component_id][$q_num] as $assessment):
                                                                ?>
                                                                 <th class="py-1 px-2 text-center rq-h-assess" data-component-id="<?php echo $component_id ?>" data-quarter="<?php echo $q_num ?>" data-assessment-id="<?php echo $assessment['assessment_id']; ?>" data-name="<?php echo htmlspecialchars($assessment['name'], ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($assessment['name']) ?>">
                                                                     <div class="text-truncate rq-assess-name"><?php echo htmlspecialchars($assessment['name']) ?></div>
                                                                     <button type="button" class="rq-del-assess-btn" data-assessment-id="<?php echo $assessment['assessment_id']; ?>" data-name="<?php echo htmlspecialchars($assessment['name'], ENT_QUOTES); ?>" title="Delete assessment and its marks"><i class="fas fa-trash-alt"></i></button>
                                                                 </th>
                                                                 <?php
                                                                     endforeach;
                                                                 ?>
                                                                 <th class="py-1 px-2 text-center rq-h-total" title="Total score">T</th>

                                                                <th class="py-1 px-2 text-center rq-h-pct" title="Weighted percentage">%</th>
                                                                 <?php else: ?>
                                                                 <th class="py-1 px-2 text-center rq-h-none" colspan="2">
                                                                     <small>No assessments</small>
                                                                 </th>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                    <tr class="rq-row-hps">
                                                        <?php
                                                        foreach($components as $component_id => $component):
                                                            $percentage = $component['percentage'];
                                                            foreach($active_quarters as $q_num):
                                                                if(isset($assess[$component_id][$q_num])):
                                                                    $quarter_total_hps = 0;
                                                                    foreach($assess[$component_id][$q_num] as $assessment):
                                                                        $quarter_total_hps += $assessment['hps'];
                                                        ?>
                                                                 <th class="py-1 px-2 text-center rq-h-hps" data-component-id="<?php echo $component_id ?>" data-quarter="<?php echo $q_num ?>" data-assessment-id="<?php echo $assessment['assessment_id']; ?>" data-name="<?php echo htmlspecialchars($assessment['name'], ENT_QUOTES); ?>">/<?php echo $assessment['hps'] ?></th>
                                                         <?php
                                                                     endforeach;
                                                         ?>
                                                                 <th class="py-1 px-2 text-center rq-h-total-val"><?php echo $quarter_total_hps ?></th>
                                                                <th class="py-1 px-2 text-center rq-h-pct-val"><?php echo $percentage ?>%</th>
                                                         <?php else: ?>
                                                                 <th class="py-1 px-2 text-center rq-h-none" colspan="2">-</th>
                                                       <?php
                                                               endif;
                                                           endforeach;
                                                       endforeach;
                                                       ?>
                                                   </tr>
                                               </thead>
                                           <?php else: ?>
                                            <!-- Original Single Quarter Table Structure -->
                                            <table class="table table-hover table-bordered w-100 rq-grades" id="gradesTable" style="min-width: 800px; max-width: 100%; page-break-after: avoid;">
                                                <thead class="sticky-top table-header rq-thead">
                                                    <tr class="rq-row-main">
                                                        <th class="py-2 px-2 text-center align-middle sticky-col rq-h-main rq-h-student" rowspan="3" style="min-width: 200px; max-width: 250px; position: sticky; left: 0; z-index: 10;">Student</th>
                                                        <?php
                                                        foreach($components as $row):
                                                            $colspan = isset($assess[$row['component_id']]) ? count($assess[$row['component_id']]) + 2 : 3;
                                                        ?>
                                                         <th class="py-2 px-2 text-center rq-h-main rq-h-component" data-component-id="<?php echo $row['component_id'] ?>" colspan="<?php echo $colspan ?>">
                                                             <div class="d-flex flex-column align-items-center gap-1">
                                                                 <span class="rq-comp-name"><?php echo htmlspecialchars($row['name']); ?> <button type="button" class="rq-add-assess-btn" data-component-id="<?php echo $row['component_id'] ?>" data-quarter="<?php echo $current_quarter ?>" title="Add assessment to <?php echo htmlspecialchars($row['name']); ?>"><i class="fas fa-plus"></i></button></span>
                                                                 <small class="rq-comp-pct"><?php echo $row['percentage'] ?>%</small>
                                                             </div>
                                                         </th>

                                                        <?php endforeach; ?>
                                                        <th class="py-2 px-2 text-center align-middle initial-grade-header rq-h-main rq-h-grade rq-h-initial" rowspan="3" width="40px">Initial<span class="rq-h-sub">Grade</span></th>
                                                        <th class="py-2 px-2 text-center align-middle quarter-grade-header rq-h-main rq-h-grade" rowspan="3" width="40px"><?php echo htmlspecialchars($resolve_quarter_name($current_quarter)); ?><span class="rq-h-sub">Grade</span></th>
                                                    </tr>
                                                    <tr class="rq-row-assess">
                                                        <?php
                                                        foreach($components as $k => $v):
                                                            if(isset($assess[$k])):
                                                                foreach($assess[$k] as $row):
                                                        ?>
                                                        <th class="py-1 px-2 text-center rq-h-assess" data-component-id="<?php echo $k; ?>" data-quarter="<?php echo $current_quarter; ?>" data-assessment-id="<?php echo $row['assessment_id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" style="min-width: 10px;" title="<?php echo htmlspecialchars($row['name']) ?>">
                                                            <div class="text-truncate rq-assess-name"><?php echo htmlspecialchars($row['name']) ?></div>
                                                            <button type="button" class="rq-del-assess-btn" data-assessment-id="<?php echo $row['assessment_id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" title="Delete assessment and its marks"><i class="fas fa-trash-alt"></i></button>
                                                        </th>

                                                        <?php
                                                                endforeach;
                                                        ?>
                                                        <th class="py-1 px-2 text-center rq-h-total" style="min-width: 10px;" title="Total score">T</th>
                                                        <th class="py-1 px-2 text-center rq-h-pct" style="min-width: 20px;" title="Weighted percentage">%</th>
                                                        <?php
                                                            else:
                                                        ?>

                                                        <th class="py-1 px-2 text-center rq-h-none" colspan="3">
                                                            <small>No assessments</small>
                                                        </th>
                                                        <?php
                                                            endif;
                                                        endforeach;
                                                        ?>
                                                    </tr>
                                                    <tr class="rq-row-hps">
                                                        <?php
                                                        $total_hps_arr = array();
                                                        foreach($components as $k => $v):
                                                            $total_hps = 0;
                                                            $percentage = $v['percentage'];
                                                            if(isset($assess[$k])):
                                                                foreach($assess[$k] as $row):
                                                                    $total_hps += $row['hps'];
                                                        ?>
                                                        <th class="py-1 px-2 text-center rq-h-hps" data-component-id="<?php echo $k; ?>" data-quarter="<?php echo $current_quarter; ?>" data-assessment-id="<?php echo $row['assessment_id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>">/<?php echo $row['hps'] ?></th>
                                                        <?php
                                                                endforeach;
                                                        ?>
                                                        <th class="py-1 px-2 text-center fw-bold rq-h-total-val"><?php echo $total_hps_arr[$k] = $total_hps ?></th>
                                                        <th class="py-1 px-2 text-center rq-h-pct-val"><?php echo $percentage ?>%</th>
                                                        <?php
                                                            else:
                                                        ?>
                                                        <th class="py-1 px-2 text-center rq-h-none" colspan="3">-</th>
                                                       <?php
                                                           endif;
                                                       endforeach;
                                                       ?>
                                                   </tr>
                                               </thead>
                                               <?php endif; ?>
                                               <tbody>
                                               <?php ob_start(); ?>
                                               <?php
                                               // Show a full-width row when no students are found
                                               if(empty($students)){
                                                   if ($is_active_quarters) {
                                                       $col_count = 1; // Student column
                                                       foreach($components as $component_id => $component){
                                                           foreach($active_quarters as $q_num){
                                                               $col_count += isset($assess[$component_id][$q_num]) ? count($assess[$component_id][$q_num]) + 3 : 3;
                                                           }
                                                       }
                                                            $col_count += count($active_quarters) + 1; // Quarter Grades + AVG Grade
                                                   } else {
                                                       $col_count = 1; // Student column
                                                       foreach($components as $k => $v){
                                                            $col_count += isset($assess[$k]) ? count($assess[$k]) + 2 : 3; // assessments + Total + % OR 3 when none
                                                       }
                                                       $col_count += 2; // Initial Grade + Quarterly Grade
                                                   }
                                               ?>
                                                   <tr>
                                                       <td class="text-center" colspan="<?php echo $col_count; ?>" style="border: 1px solid #adb5bd;">No students found for this class.</td>
                                                   </tr>
                                               <?php
                                               }
                                               foreach($students as $k =>$v):
                                                   if ($is_active_quarters) {
                                                       // Active quarters calculations
                                                       $final_grade = 0;
                                                       $total_weighted_score = 0;
                                                       $total_percentage = 0;
                                                   } else {
                                                       // Single quarter calculations
                                                       $initial = 0;
                                                   }
                                               ?>
                                                   <?php
                                               $row_class = '';
                                               $border_color = '#dee2e6';
                                                if(isset($student_status[$k])) {
                                                    if($student_status[$k] === 'Dropped') {
                                                        $row_class = ' status-dropped';
                                                        $border_color = '#b91c1c';
                                                    } elseif($student_status[$k] === 'NoSub') {
                                                        $row_class = ' status-nosub';
                                                        $border_color = '#b45309';
                                                    } elseif($student_status[$k] === 'NoExam') {
                                                        $row_class = ' status-noexam';
                                                        $border_color = '#0369a1';
                                                    }
                                                }
                                               ?>
                                                    <tr data-student-id="<?php echo $k; ?>" data-student-status="<?php echo isset($student_status[$k]) ? htmlspecialchars($student_status[$k]) : 'Active'; ?>" class="rq-row <?php echo $row_class; ?>">
                                                        <td class="py-0 px-1 sticky-col rq-student" style="position: sticky; left: 0; z-index: 5; border-right: 2px solid <?php echo $border_color; ?>;">
                                                            <span class="rq-student-name"><?php echo $v ?></span>
                                                            <?php if(isset($student_status[$k]) && $student_status[$k] === 'Dropped'): ?>
                                                                <span class="badge rq-badge rq-badge-dropped">Dropped</span>
                                                            <?php elseif(isset($student_status[$k]) && $student_status[$k] === 'NoSub'): ?>
                                                                <span class="badge rq-badge rq-badge-nosub">No Submissions</span>
                                                            <?php elseif(isset($student_status[$k]) && $student_status[$k] === 'NoExam'): ?>
                                                                <span class="badge rq-badge rq-badge-noexam">No Exam</span>
                                                            <?php endif; ?>
                                                        </td>
                                                       <?php
                                                       if ($is_active_quarters) {
                                                           $student_component_quarter_stats = [];
                                                           $student_quarter_grade_stats = [];
                                                           $total_weighted_score = 0;
                                                           $total_percentage = 0;

                                                           foreach($components as $component_id => $component) {
                                                               foreach($active_quarters as $q_num) {
                                                                   if (!isset($assess[$component_id][$q_num])) {
                                                                       continue;
                                                                   }

                                                                   $quarter_total_hps = 0;
                                                                   $quarter_total_score = 0;
                                                                   $quarter_any_value = false;
                                                                   $quarter_has_blank = false;
                                                                   $quarter_has_graded = false;

                                                                   foreach($assess[$component_id][$q_num] as $assessment) {
                                                                       if (isset($marks[$k][$assessment['assessment_id']])) { $quarter_any_value = true; }
                                                                       $__rq_mark = $marks[$k][$assessment['assessment_id']] ?? null;
                                                                       // A missing (or empty) mark row is a BLANK, not a
                                                                       // zero. Only blanks feed the INC rule below: an
                                                                       // explicitly encoded 0 is failed work (5.0),
                                                                       // not incomplete work.
                                                                       if ($__rq_mark === null || trim((string)$__rq_mark) === '') { $quarter_has_blank = true; }
                                                                       else { $quarter_has_graded = true; }
                                                                       $mark = isset($marks[$k][$assessment['assessment_id']]) ? $marks[$k][$assessment['assessment_id']] : 0;
                                                                       $quarter_total_score += $mark;
                                                                       // A blank's HPS counts in the denominator too: a
                                                                       // partially graded component is diluted by its
                                                                       // blanks. (A component with no graded work at all
                                                                       // still contributes 0 - see the gate below.)
                                                                       $quarter_total_hps += $assessment['hps'];
                                                                   }

                                                                   if ($quarter_has_graded) {
                                                                       $quarter_avg = get_scaled_component_raw($quarter_total_score, $quarter_total_hps);
                                                                       $quarter_weighted = get_weighted_percentage($quarter_total_score, $quarter_total_hps, $component['percentage']);
                                                                   } else {
                                                                       // No graded work at all: contributes 0, exactly
                                                                       // as before (never the 55 floor).
                                                                       $quarter_avg = 0.0;
                                                                       $quarter_weighted = 0.0;
                                                                   }

                                                                   $student_component_quarter_stats[$component_id][$q_num] = [
                                                                       'score' => $quarter_total_score,
                                                                       'hps' => $quarter_total_hps,
                                                                       'any_value' => $quarter_any_value,
                                                                       'has_blank' => $quarter_has_blank,
                                                                       'avg' => $quarter_avg,
                                                                       'weighted' => $quarter_weighted,
                                                                       'component_percentage' => $component['percentage']
                                                                   ];

                                                                   $total_weighted_score += $quarter_weighted;
                                                                   $total_percentage += $component['percentage'];

                                                                   if (!isset($student_quarter_grade_stats[$q_num])) {
                                                                       $student_quarter_grade_stats[$q_num] = [
                                                                           'weighted_total' => 0,
                                                                           'percentage_total' => 0
                                                                       ];
                                                                   }
                                                                   $student_quarter_grade_stats[$q_num]['weighted_total'] += $quarter_weighted;
                                                                   $student_quarter_grade_stats[$q_num]['percentage_total'] += $component['percentage'];
                                                               }
                                                           }

                                                           $final_grade = $total_percentage > 0 ? ($total_weighted_score / $total_percentage) * 100 : 0;
                                                           $transmuted_equivalent = get_equivalent_from_transmutation($final_grade);
                                                            $is_failing = ($transmuted_equivalent === 'DR' || (is_numeric($transmuted_equivalent) && (!empty($depEdReport) ? (float)$transmuted_equivalent < 75 : (float)$transmuted_equivalent > 3.0)));

                                                            foreach($components as $component_id => $component):
                                                                foreach($active_quarters as $q_num):
                                                                    if(isset($assess[$component_id][$q_num])):
                                                                       $quarter_total_score = $student_component_quarter_stats[$component_id][$q_num]['score'] ?? 0;
                                                                       $quarter_weighted = $student_component_quarter_stats[$component_id][$q_num]['weighted'] ?? 0;
                                                                       // Nothing graded in this component/quarter yet -> keep T and % blank
                                                                       $quarter_any_value = $student_component_quarter_stats[$component_id][$q_num]['any_value'] ?? false;
                                                                       foreach($assess[$component_id][$q_num] as $assessment):
                                                                           // Blank means "not yet graded": no mark_list row exists. It scores
                                                                           // nothing, adds no items (see the loop below) and renders as 0.
                                                                           // unchanged, but render the cell empty instead of 0.
                                                                           $mark_has_value = isset($marks[$k][$assessment['assessment_id']]);
                                                                           $mark = $mark_has_value ? $marks[$k][$assessment['assessment_id']] : 0;
                                                                           $mark_display = $mark_has_value ? $mark : '';

                                                                           // Calculate if this mark is a major cause of failure
                                                                           $mark_class = '';
                                                                           if ($is_failing && $assessment['hps'] > 0 && $mark_has_value) {
                                                                               $mark_percentage = ($mark / $assessment['hps']) * 100;
                                                                               $component_weight = $component['percentage'];
                                                                               $impact_weight = $component_weight * ($assessment['percentage'] / 100);

                                                                               if ($mark_percentage < 75 && $impact_weight >= 5) {
                                                                                   if ($mark == 0 || $impact_weight >= 15) {
                                                                                       $mark_class = 'text-danger fw-bold bg-danger bg-opacity-25';
                                                                                   }
                                                                               }
                                                                           }
                                                                       ?>
                                                                         <td class="py-0 px-1 text-center editable-mark rq-mark <?php echo $mark_class; ?>"
                                                                         width="35px"
                                                                         title="Assessment Score"
                                                                         data-student-id="<?php echo $k; ?>"
                                                                         data-component-id="<?php echo $component_id; ?>"
                                                                         data-assessment-id="<?php echo $assessment['assessment_id']; ?>"
                                                                        data-class-id="<?php echo $_GET['class_id']; ?>"
                                                                        data-quarter="<?php echo $q_num; ?>"
                                                                        contenteditable="<?php echo (isset($student_status[$k]) && $student_status[$k] === 'Dropped') ? 'false' : 'true'; ?>"><?php echo $mark_display ?></td>
                                                                <?php
                                                                        endforeach;
                                                                ?>
                                                                        <td class="py-0 px-1 text-center rq-total" width="35px"><?php echo number_format($quarter_any_value ? $quarter_total_score : 0, 2) ?></td>
                                                                        <td class="py-0 px-1 text-center rq-weighted" width="35px"><?php echo number_format($quarter_any_value ? $quarter_weighted : 0, 2) ?></td>
                                                                <?php else: ?>
                                                                        <td class="py-0 px-1 text-center rq-none" colspan="2" width="35px">
                                                                            <small>No assessments</small>
                                                                        </td>
                                                               <?php endif; ?>
                                                               <?php endforeach; ?>
                                                           <?php endforeach; ?>

                                                            <!-- Quarter-wise Transmuted Grade Columns -->
                                                            <?php $rq_quarter_has_inc = false; $rq_q_transmuted = []; ?>
                                                            <?php foreach($active_quarters as $q_num): ?>
                                                               <?php
                                                                $quarter_total_weighted = $student_quarter_grade_stats[$q_num]['weighted_total'] ?? 0;
                                                                $quarter_total_percentage = $student_quarter_grade_stats[$q_num]['percentage_total'] ?? 0;
                                                                $quarter_grade = $quarter_total_percentage > 0 ? ($quarter_total_weighted / $quarter_total_percentage) * 100 : 0;
                                                                $quarter_transmuted = get_equivalent_from_transmutation($quarter_grade);
                                                                $quarter_transmuted_num = is_numeric($quarter_transmuted) ? (float)$quarter_transmuted : 0;
                                                                 // INC rule: a BLANK (missing) mark under a component
                                                                 // with percentage >= 30% plus a raw quarter grade in
                                                                 // [40, 55.49] => INC. An explicitly encoded 0 is
                                                                 // failed work (5.0 through the raw grade), not
                                                                 // incomplete work.
                                                                 $has_blank_q = false;
                                                                  foreach($components as $qc_cid => $qc_comp){
                                                                      if(isset($student_component_quarter_stats[$qc_cid][$q_num])){
                                                                          $qc_stat = $student_component_quarter_stats[$qc_cid][$q_num];
                                                                          if(!empty($qc_stat['has_blank']) && ($qc_comp['percentage'] ?? 0) >= 30){
                                                                              $has_blank_q = true; break;
                                                                          }
                                                                      }
                                                                  }
                                                                if($quarter_grade >= 40 && $quarter_grade <= 55.49 && $has_blank_q && (!isset($student_status[$k]) || $student_status[$k] !== 'Dropped')){
                                                                    $quarter_transmuted = 'INC';
                                                                    $quarter_transmuted_num = 0;
                                                                    $rq_quarter_has_inc = true;
                                                                }
                                                                $rq_q_transmuted[$q_num] = $quarter_transmuted;
 
                                                                if ($quarter_transmuted === 'INC') {
                                                                    $quarter_text_color = '#9a3412';
                                                                    $quarter_pill_bg = '#ffedd5';
                                                                    $quarter_pill_bd = '#fed7aa';
                                                                } elseif ($quarter_transmuted === 'DR') {
                                                                    $quarter_text_color = '#991b1b';
                                                                    $quarter_pill_bg = '#fee2e2';
                                                                    $quarter_pill_bd = '#fecaca';
                                                                } elseif (!empty($depEdReport) && $quarter_transmuted_num >= 75) {
                                                                    $quarter_text_color = '#166534';
                                                                    $quarter_pill_bg = '#dcfce7';
                                                                    $quarter_pill_bd = '#bbf7d0';
                                                                } elseif (!empty($depEdReport)) {
                                                                    $quarter_text_color = '#991b1b';
                                                                    $quarter_pill_bg = '#fee2e2';
                                                                    $quarter_pill_bd = '#fecaca';
                                                                } elseif ($quarter_transmuted_num >= 3.1 && $quarter_transmuted_num <= 3.5) {
                                                                    $quarter_text_color = '#92400e';
                                                                    $quarter_pill_bg = '#fef3c7';
                                                                    $quarter_pill_bd = '#fde68a';
                                                                } elseif ($quarter_transmuted_num > 3.5) {
                                                                    $quarter_text_color = '#991b1b';
                                                                    $quarter_pill_bg = '#fee2e2';
                                                                    $quarter_pill_bd = '#fecaca';
                                                                } elseif ($quarter_transmuted_num >= 1.0 && $quarter_transmuted_num <= 3.0) {
                                                                    $quarter_text_color = '#166534';
                                                                    $quarter_pill_bg = '#dcfce7';
                                                                    $quarter_pill_bd = '#bbf7d0';
                                                                } else {
                                                                    $quarter_text_color = '#334155';
                                                                    $quarter_pill_bg = '#f1f5f9';
                                                                    $quarter_pill_bd = '#e2e8f0';
                                                                }
                                                                ?>
                                                                 <td class="py-0 px-1 text-center transmuted-grade fw-bold rq-grade-cell" width="40px">
                                                                    <span class="rq-pill" style="color: <?php echo $quarter_text_color; ?>; background-color: <?php echo $quarter_pill_bg; ?>; border-color: <?php echo $quarter_pill_bd; ?>;"><?php echo is_numeric($quarter_transmuted) ? number_format($quarter_transmuted, 1) : $quarter_transmuted; ?></span>
                                                                </td>
                                                           <?php endforeach; ?>

                                                            <!-- Final Grade Columns -->
                                                            <?php
                                                            $initial = $final_grade;
                                                            // AVG shows INC when at least one quarter shows transmuted
                                                            // grade INC; otherwise AVG is the plain average of the
                                                            // quarters' transmuted grades (numeric ones with data).
                                                            $rq_not_dropped = (!isset($student_status[$k]) || $student_status[$k] !== 'Dropped');
                                                            if($rq_quarter_has_inc && $rq_not_dropped){
                                                                $transmuted_equivalent = 'INC';
                                                                $transmuted_equivalent_num = 0;
                                                            } else {
                                                                $rq_avg_vals = [];
                                                                foreach($active_quarters as $rq_aq){
                                                                    if(!isset($student_quarter_grade_stats[$rq_aq])) continue;
                                                                    $rq_qt = $rq_q_transmuted[$rq_aq] ?? null;
                                                                    if(is_numeric($rq_qt)) $rq_avg_vals[] = (float)$rq_qt;
                                                                }
                                                                if(count($rq_avg_vals) > 0){
                                                                    // Half-even rounding: e.g. 2.3 + 5.0 = 3.65 -> 3.6.
                                                                    // Must match report_of_grades.php
                                                                    // and export_to_word.php or the same student shows two different averages.
                                                                    $transmuted_equivalent = number_format(get_average_of_transmuted($rq_avg_vals, 1), 1);
                                                                    $transmuted_equivalent_num = (float)$transmuted_equivalent;
                                                                } else {
                                                                    $transmuted_equivalent = get_equivalent_from_transmutation($initial);
                                                                    $transmuted_equivalent_num = is_numeric($transmuted_equivalent) ? (float)$transmuted_equivalent : 0;
                                                                }
                                                            }

                                                            // Check if student is dropped
                                                        if ($transmuted_equivalent === 'INC') {
                                                            $single_text_color = '#9a3412';
                                                            $single_pill_bg = '#ffedd5';
                                                            $single_pill_bd = '#fed7aa';
                                                        } elseif ($transmuted_equivalent === 'DR') {
                                                            $single_text_color = '#991b1b';
                                                            $single_pill_bg = '#fee2e2';
                                                            $single_pill_bd = '#fecaca';
                                                        } elseif (!empty($depEdReport) && $transmuted_equivalent_num >= 75) {
                                                            $single_text_color = '#166534';
                                                            $single_pill_bg = '#dcfce7';
                                                            $single_pill_bd = '#bbf7d0';
                                                        } elseif (!empty($depEdReport)) {
                                                            $single_text_color = '#991b1b';
                                                            $single_pill_bg = '#fee2e2';
                                                            $single_pill_bd = '#fecaca';
                                                        } elseif ($transmuted_equivalent_num >= 3.1 && $transmuted_equivalent_num <= 3.5) {
                                                            $single_text_color = '#92400e';
                                                            $single_pill_bg = '#fef3c7';
                                                            $single_pill_bd = '#fde68a';
                                                        } elseif ($transmuted_equivalent_num > 3.5) {
                                                            $single_text_color = '#991b1b';
                                                            $single_pill_bg = '#fee2e2';
                                                            $single_pill_bd = '#fecaca';
                                                        } elseif ($transmuted_equivalent_num >= 1.0 && $transmuted_equivalent_num <= 3.0) {
                                                            $single_text_color = '#166534';
                                                            $single_pill_bg = '#dcfce7';
                                                            $single_pill_bd = '#bbf7d0';
                                                        } else {
                                                            $single_text_color = '#334155';
                                                            $single_pill_bg = '#f1f5f9';
                                                            $single_pill_bd = '#e2e8f0';
                                                        }
                                                            ?>
                                                              <td class="py-0 px-1 text-center fw-bold initial-grade-cell rq-initial-cell rq-initial-hidden d-none" width="40px"><?php echo number_format($initial, 2) ?></td>
                                                             <td class="py-0 px-1 text-center transmuted-grade fw-bold rq-grade-cell" width="40px">
                                                                <span class="rq-pill" style="color: <?php echo $single_text_color; ?>; background-color: <?php echo $single_pill_bg; ?>; border-color: <?php echo $single_pill_bd; ?>;"><?php echo is_numeric($transmuted_equivalent) ? number_format($transmuted_equivalent, 1) : $transmuted_equivalent; ?></span>
                                                            </td>
                                                       <?php } else { ?>
                                                           <?php
                                                            // Single quarter - calculate final grade first to determine if student is failing
                                                            $total_weighted_score = 0;
                                                            $total_percentage = 0;
                                                                       $single_has_blank = false;
                                                           foreach($components as $cid => $v):
                                                               $total_hps_possible = 0;
                                                               $total_score = 0;
                                                               $component_has_grades = false;
                                                               $component_has_graded = false;
                                                               if(isset($assess[$cid])) {
                                                                   foreach($assess[$cid] as $row):
                                                                       $__sq_mark = $marks[$k][$row['assessment_id']] ?? null;
                                                                       if ($__sq_mark !== null && trim((string)$__sq_mark) !== '') { $component_has_graded = true; }
                                                                       $mark = isset($marks[$k][$row['assessment_id']]) ? $marks[$k][$row['assessment_id']] : 0;
                                                                       $total_score += $mark;
                                                                       // A blank's HPS counts in the denominator too.
                                                                       $total_hps_possible += $row['hps'];
                                                                       $component_has_grades = true;
                                                                   endforeach;

                                                                   if($component_has_grades && $component_has_graded) {
                                                                       $component_grade = get_scaled_component_raw($total_score, $total_hps_possible);
                                                                       $perc_hps = get_weighted_percentage($total_score, $total_hps_possible, $components[$cid]['percentage']);
                                                                       $total_weighted_score += $perc_hps;
                                                                       $total_percentage += $components[$cid]['percentage'];
                                                                   }
                                                               }
                                                           endforeach;

                                                           $final_grade = $total_percentage > 0 ? ($total_weighted_score / $total_percentage) * 100 : 0;
                                                           $transmuted_equivalent = get_equivalent_from_transmutation($final_grade);
                                                            $is_failing = ($transmuted_equivalent === 'DR' || (is_numeric($transmuted_equivalent) && (!empty($depEdReport) ? (float)$transmuted_equivalent < 75 : (float)$transmuted_equivalent > 3.0)));

                                                            // Now display marks with impact analysis
                                                            foreach($components as $cid => $v): ?>
                                                               <?php
                                                               $total_hps_possible = 0;
                                                               $total_score = 0;
                                                               $perc_hps = 0;
                                                               $perc_hpss = 0;
                                                               $component_has_grades = false;
                                                               $component_any_value = false;
                                                               $component_has_blank = false;
                                                               $component_has_graded = false;
                                                               ?>
                                                                       <?php if(isset($assess[$cid])): ?>
                                                                   <?php foreach($assess[$cid] as $row): ?>
                                                                       <?php
                                                                       // Blank means "not yet graded": no mark_list row exists. It scores
                                                                       // nothing, adds no items (see below) and renders as 0 in T / %.
                                                                       $mark_has_value = isset($marks[$k][$row['assessment_id']]);
                                                                       if (!$mark_has_value || trim((string)($marks[$k][$row['assessment_id']] ?? '')) === '') { $component_has_blank = true; }
                                                                       $mark = $mark_has_value ? $marks[$k][$row['assessment_id']] : 0;
                                                                       $mark_display = $mark_has_value ? $mark : '';
                                                                       if ($mark_has_value) { $component_any_value = true; }
                                                                       if ($mark_has_value && trim((string)$marks[$k][$row['assessment_id']]) !== '') { $component_has_graded = true; }
                                                                       $total_score += $mark;
                                                                       // A blank's HPS counts in the denominator too.
                                                                       $total_hps_possible += $row['hps'];
                                                                       $component_has_grades = true;

                                                                       // Calculate if this mark is a major cause of failure
                                                                       $mark_class = '';
                                                                       if ($is_failing && $row['hps'] > 0 && $mark_has_value) {
                                                                           $mark_percentage = ($mark / $row['hps']) * 100;
                                                                           $component_weight = $components[$cid]['percentage'];
                                                                           $impact_weight = $component_weight * ($row['percentage'] / 100); // This assessment's weight in overall grade

                                                                           // If this mark is significantly below passing threshold and has significant weight
                                                                           if ($mark_percentage < 75 && $impact_weight >= 5) { // 5% or more of overall grade
                                                                               // Color code based on impact level
                                                                               if ($mark == 0) {
                                                                                   // Zero marks - always red regardless of impact
                                                                                   $mark_class = 'text-danger fw-bold bg-danger bg-opacity-25';
                                                                               } elseif ($impact_weight >= 15) {
                                                                                   // High impact (15% or more of overall grade) - Red
                                                                                   $mark_class = 'text-danger fw-bold bg-danger bg-opacity-25';
                                                                               }
                                                                           }
                                                                       }
                                                                       ?>
                                                                     <td class="py-0 px-1 text-center editable-mark rq-mark <?php echo $mark_class; ?>"
                                                                         width="35px"
                                                                         title="Assessment Score"
                                                                         data-student-id="<?php echo $k; ?>"
                                                                         data-component-id="<?php echo $cid; ?>"
                                                                         data-assessment-id="<?php echo $row['assessment_id']; ?>"
                                                                        data-class-id="<?php echo $_GET['class_id']; ?>"
                                                                        data-quarter="<?php echo $current_quarter; ?>"
                                                                        contenteditable="<?php echo (isset($student_status[$k]) && $student_status[$k] === 'Dropped') ? 'false' : 'true'; ?>">
                                                                        <?php echo $mark_display?>
                                                                    </td>
                                                                   <?php endforeach; ?>

                                                                    <?php
                                                                    // Calculate percentage for this component (same method as report_of_grades.php).
                                                                    // A component with no graded work at all contributes 0.
                                                                    if($component_has_grades && ($component_has_graded ?? false)) {
                                                                        $component_grade = get_scaled_component_raw($total_score, $total_hps_possible);
                                                                        $perc_hpss = $component_grade;
                                                                        $perc_hps = get_weighted_percentage($total_score, $total_hps_possible, $components[$cid]['percentage']);
                                                                    } else {
                                                                        $perc_hpss = 0;
                                                                        $perc_hps = 0;
                                                                    }
                                                                     // Track BLANK marks for the INC rule (only if component % >= 30).
                                                                     // An explicitly encoded 0 is failed work, not incomplete work.
                                                                      if(isset($assess[$cid]) && ($component_has_blank ?? false) && ($components[$cid]['percentage'] ?? 0) >= 30){
                                                                          $single_has_blank = true;
                                                                      }
                                                                    ?>
                                                                     <td class="py-0 px-1 text-center rq-total" width="35px"><?php echo number_format($component_any_value ? $total_score : 0, 2) ?></td>
                                                                     <td class="py-0 px-1 text-center rq-weighted" width="35px"><?php echo number_format($component_any_value ? $perc_hps : 0, 2) ?></td>
                                                                <?php else: ?>
                                                                    <td class="py-0 px-1 text-center rq-none" colspan="3">No assessments for this component</td>
                                                               <?php endif; ?>
                                                               <?php
                                                               $initial += ($perc_hpss * $c_perc_arr[$cid]);
                                                               ?>
                                                            <?php endforeach; ?>
                                                        <?php
                                                        // Get the equivalent grade from the transmutation table
                                                        $transmuted_equivalent = get_equivalent_from_transmutation($initial);
                                                        $transmuted_equivalent_num = is_numeric($transmuted_equivalent) ? (float)$transmuted_equivalent : 0;
                                                        // INC rule: a BLANK mark under a 30%+ component plus a raw
                                                        // initial in [40, 55.49] => INC. An explicitly encoded 0
                                                        // never triggers INC on its own.
                                                        if($initial >= 40 && $initial <= 55.49 && $single_has_blank && (!isset($student_status[$k]) || $student_status[$k] !== 'Dropped')){
                                                            $transmuted_equivalent = 'INC';
                                                            $transmuted_equivalent_num = 0;
                                                        }

                                                        // Check if student is dropped
                                                        if ($transmuted_equivalent === 'INC') {
                                                            $single_text_color = '#9a3412';
                                                            $single_pill_bg = '#ffedd5';
                                                            $single_pill_bd = '#fed7aa';
                                                        } elseif ($transmuted_equivalent === 'DR') {
                                                            $single_text_color = '#991b1b';
                                                            $single_pill_bg = '#fee2e2';
                                                            $single_pill_bd = '#fecaca';
                                                        } elseif (!empty($depEdReport) && $transmuted_equivalent_num >= 75) {
                                                            $single_text_color = '#166534';
                                                            $single_pill_bg = '#dcfce7';
                                                            $single_pill_bd = '#bbf7d0';
                                                        } elseif (!empty($depEdReport)) {
                                                            $single_text_color = '#991b1b';
                                                            $single_pill_bg = '#fee2e2';
                                                            $single_pill_bd = '#fecaca';
                                                        } elseif ($transmuted_equivalent_num >= 3.1 && $transmuted_equivalent_num <= 3.5) {
                                                            $single_text_color = '#92400e';
                                                            $single_pill_bg = '#fef3c7';
                                                            $single_pill_bd = '#fde68a';
                                                        } elseif ($transmuted_equivalent_num > 3.5) {
                                                            $single_text_color = '#991b1b';
                                                            $single_pill_bg = '#fee2e2';
                                                            $single_pill_bd = '#fecaca';
                                                        } elseif ($transmuted_equivalent_num >= 1.0 && $transmuted_equivalent_num <= 3.0) {
                                                            $single_text_color = '#166534';
                                                            $single_pill_bg = '#dcfce7';
                                                            $single_pill_bd = '#bbf7d0';
                                                        } else {
                                                            $single_text_color = '#334155';
                                                            $single_pill_bg = '#f1f5f9';
                                                            $single_pill_bd = '#e2e8f0';
                                                        }
                                                        ?>
                                                        <td class="py-0 px-1 text-center fw-bold initial-grade-cell rq-initial-cell" width="40px"><?php echo number_format($initial, 2) ?></td>
                                                        <td class="py-0 px-1 text-center transmuted-grade fw-bold rq-grade-cell" width="40px">
                                                            <span class="rq-pill" style="color: <?php echo $single_text_color; ?>; background-color: <?php echo $single_pill_bg; ?>; border-color: <?php echo $single_pill_bd; ?>;"><?php echo is_numeric($transmuted_equivalent) ? number_format($transmuted_equivalent, 1) : $transmuted_equivalent; ?></span>
                                                        </td>
                                                       
                                                                <?php }?>
                                                     </tr>
                                                <?php endforeach; ?>
                                                
                                                <?php $grades_table_body = ob_get_clean(); echo $grades_table_body; ?>
                                               </tbody>
                                           </table>                  
                                       </div>

                                       <?php endif; ?>
                                   </div>
                               </div>
                           </div>
                                    <!-- Signatories Section -->
                                   <div class="col-8" style="page-break-inside: auto;">
                                       <div class="row">
                                           <?php
                                           // Define signatory types and their display titles
                                           $signatoryTypes = [
                                               'certified' => 'CERTIFIED TRUE AND CORRECT',
                                               'checked' => 'CHECKED BY',
                                               'noted' => 'NOTED BY',
                                               'received' => 'RECEIVED BY'
                                           ];

                                           // Initialize signatories array
                                           $signatories = [];

                                           // First, check if the signatories table has the type column
                                           $hasTypeColumn = false;
                                           try {
                                               $result = $conn->query("PRAGMA table_info(signatories)");
                                               while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
                                                   if ($col['name'] === 'type') {
                                                       $hasTypeColumn = true;
                                                       break;
                                                   }
                                               }
                                           } catch (Exception $e) {
                                               error_log("Error checking table structure: " . $e->getMessage());
                                           }

                                           // Fetch signatories based on whether type column exists
                                           foreach ($signatoryTypes as $type => $title) {
                                               $signatories[$type] = false;
                                               try {
                                                   if ($hasTypeColumn) {
                                                       // New query with type column
                                                       $result = $conn->query("
                                                           SELECT * FROM `signatories`
                                                           WHERE `type` = '" . SQLite3::escapeString($type) . "'
                                                           ORDER BY `date_created` DESC
                                                           LIMIT 1");
                                                   } else {
                                                       // Fallback for old schema (just get the first signatory)
                                                       $result = $conn->query("
                                                           SELECT * FROM `signatories`
                                                           ORDER BY `date_created` DESC
                                                           LIMIT 1");
                                                   }

                                                   if ($result) {
                                                       $signatories[$type] = $result->fetchArray(SQLITE3_ASSOC);
                                                   }
                                               } catch (Exception $e) {
                                                   error_log("Error fetching signatory ($type): " . $e->getMessage());
                                               }
                                           }

                                           // Output each signatory section only if it has a name or fullname
                                           foreach ($signatoryTypes as $type => $title):
                                               $signatory = $signatories[$type];
                                               // Only display if signatory exists and has a fullname
                                               if($signatory && !empty($signatory['fullname'])):
                                           ?>
                                           <div class="col-md-4 text-center">
                                               <div class="pt-4">
                                                   <h6 class="mb-4"><?php echo $title; ?>:</h6>
                                                   <div class="mb-4" style="min-height: 80px;">
                                                       <div class="mb-1" style="border-bottom: 1px solid #000; width: 90%; height: 50px; margin: 0 auto;">
                                                           <div style="height: 100%; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px;">
                                                               <?php echo htmlspecialchars($signatory['fullname']) ?>
                                                           </div>
                                                       </div>
                                                       <div class="mt-2 fw-bold"><?php echo htmlspecialchars($signatory['designation']) ?></div>
                                                       <div class="text-muted small">Signature Over Printed Name</div>
                                                   </div>
                                               </div>
                                           </div>
                                           <?php endif; endforeach; ?>
                                       </div>
                                   </div>
                                   <!-- End Signatories Section -->
        <?php endif; ?>

    </script>

    <?php require_once('security_protection.php'); ?>

    </html>

    <style>
        /* =====================================================================
           PROFESSIONAL GRADES TABLE DESIGN SYSTEM (rq-grades)
           Clean institutional look: navy/slate headers, hairline borders,
           pill grade badges, subtle hover. Functional hooks (#gradesTable,
           .editable-mark, .sticky-col, status classes) are preserved.
           ===================================================================== */
        :root {
            --rq-navy: #1e293b;
            --rq-navy-2: #0f172a;
            --rq-slate: #475569;
            --rq-line: #e2e8f0;
            --rq-line-strong: #cbd5e1;
            --rq-bg-soft: #f8fafc;
            --rq-bg-quarter: #f1f5f9;
            --rq-text: #0f172a;
            --rq-muted: #64748b;
            --rq-accent: #2563eb;
            --rq-radius: 10px;
        }
        .freeze-panes-container {
            border: 1px solid var(--rq-line-strong);
            border-radius: var(--rq-radius);
            box-shadow: 0 1px 2px rgba(15,23,42,.06), 0 8px 24px -12px rgba(15,23,42,.18);
            background: #fff;
        }
        #gradesTable.rq-grades {
            --bs-table-bg: #ffffff;
            --bs-table-color: var(--rq-text);
            --bs-table-border-color: var(--rq-line);
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
            color: var(--rq-text);
            background: #fff;
        }
        #gradesTable.rq-grades thead.rq-thead {
            background: var(--rq-navy);
        }
        /* --- Main header row: deep slate --- */
        #gradesTable.rq-grades thead tr.rq-row-main th {
            background-color: var(--rq-navy);
            color: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            border: 1px solid #334155;
            border-bottom: 2px solid #0f172a;
            padding: 10px 8px;
            vertical-align: middle;
            white-space: nowrap;
        }
        #gradesTable.rq-grades thead tr.rq-row-main th.rq-h-student {
            text-align: center;
            min-width: 200px;
            background-color: var(--rq-navy-2);
            border-right: 2px solid #475569;
            border-top-left-radius: 9px;
        }
        #gradesTable.rq-grades .rq-comp-name {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #fff;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #gradesTable.rq-grades .rq-comp-pct {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .03em;
            color: #e2e8f0;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 999px;
            padding: 1px 8px;
            line-height: 1.5;
        }
        #gradesTable.rq-grades .rq-h-sub {
            display: block;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #cbd5e1;
            margin-top: 1px;
        }
        #gradesTable.rq-grades thead tr.rq-row-main th.rq-h-grade {
            background-color: #243449;
            min-width: 72px;
        }
        #gradesTable.rq-grades thead tr.rq-row-main th.rq-h-initial,
        #gradesTable.rq-grades thead tr.rq-row-main th.rq-h-avg {
            background-color: #1a365d;
        }
        /* --- Quarter band --- */
        #gradesTable.rq-grades thead tr.rq-row-quarter th {
            background-color: var(--rq-bg-quarter);
            color: var(--rq-slate);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            border: 1px solid var(--rq-line);
            padding: 6px 6px;
        }
        #gradesTable.rq-grades thead tr.rq-row-quarter th.rq-h-empty {
            color: #94a3b8;
            font-weight: 600;
        }
        /* --- Assessment / total / pct --- */
        #gradesTable.rq-grades thead tr.rq-row-assess th,
        #gradesTable.rq-grades thead tr.rq-row-hps th {
            border: 1px solid var(--rq-line);
            padding: 5px 6px;
        }
        #gradesTable.rq-grades thead tr.rq-row-assess th.rq-h-assess {
            background: #ffffff;
            color: var(--rq-text);
            font-size: 11px;
            font-weight: 600;
            min-width: 44px;
        }
        #gradesTable.rq-grades .rq-assess-name {
            font-size: 11px;
            font-weight: 600;
            color: #334155;
            max-width: 110px;
        }
        #gradesTable.rq-grades thead th.rq-h-total,
        #gradesTable.rq-grades thead th.rq-h-total-val {
            background: #dbe2ec;
            color: #334155;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .05em;
        }
        #gradesTable.rq-grades thead th.rq-h-pct,
        #gradesTable.rq-grades thead th.rq-h-pct-val {
            background: #cbd5e1;
            color: #1e293b;
            font-size: 10px;
            font-weight: 800;
        }
        #gradesTable.rq-grades thead th.rq-h-hps {
            background: #f8fafc;
            color: var(--rq-muted);
            font-size: 10px;
            font-weight: 600;
        }
        #gradesTable.rq-grades thead th.rq-h-none {
            background: #f8fafc;
            color: #94a3b8;
            font-size: 10px;
            font-weight: 600;
        }
        /* --- Body --- */
        #gradesTable.rq-grades tbody td {
            border: 1px solid var(--rq-line);
            padding: 2px 4px;
            font-size: 13px;
            vertical-align: middle;
            background: #fff;
            color: var(--rq-text);
        }
        #gradesTable.rq-grades tbody tr:nth-child(even) td:not(.rq-student):not(.rq-total):not(.rq-weighted):not(.rq-initial-cell):not(.rq-grade-cell) {
            background-color: #fcfdff;
        }
        #gradesTable.rq-grades td.rq-student {
            background: #fff;
            font-weight: 600;
            font-size: 13px;
            text-align: left;
            box-shadow: 6px 0 8px -6px rgba(15,23,42,.15);
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #gradesTable.rq-grades td.rq-student .rq-student-name {
            font-weight: 600;
            color: #0f172a;
        }
        #gradesTable.rq-grades .rq-badge {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            border-radius: 999px;
            padding: 2px 7px;
            border: 1px solid transparent;
            vertical-align: middle;
            margin-left: 6px;
        }
        #gradesTable.rq-grades .rq-badge-dropped { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        #gradesTable.rq-grades .rq-badge-nosub { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        #gradesTable.rq-grades .rq-badge-noexam { background: #e0f2fe; color: #075985; border-color: #bae6fd; }
        #gradesTable.rq-grades td.rq-mark {
            font-variant-numeric: tabular-nums;
            text-align: center;
            border: 1px solid var(--rq-line);
        }
        #gradesTable.rq-grades td.rq-mark.text-danger { background-color: #fef2f2; color: #b91c1c; }
        #gradesTable.rq-grades td.rq-total {
            background: #e2e8f0;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        #gradesTable.rq-grades td.rq-weighted {
            background: #d7dfea;
            font-weight: 700;
            color: #1e293b;
            font-variant-numeric: tabular-nums;
        }
        #gradesTable.rq-grades td.rq-none { background: #f8fafc; color: #94a3b8; font-size: 11px; }
        #gradesTable.rq-grades td.rq-initial-cell {
            font-weight: 800;
            font-size: 13px;
            font-variant-numeric: tabular-nums;
            background: #f8fafc;
            color: #0f172a;
            border-left: 2px solid var(--rq-line-strong);
            width: 40px;
            min-width: 40px;
            max-width: 40px;
            text-align: center;
        }
        #gradesTable.rq-grades td.rq-grade-cell {
            background: #fff;
            border-left: 1px solid var(--rq-line);
            width: 40px;
            min-width: 40px;
            max-width: 40px;
            text-align: center;
        }
        #gradesTable.rq-grades .rq-pill {
            display: inline-block;
            min-width: 34px;
            font-size: 13px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            border: 1px solid var(--rq-line);
            border-radius: 999px;
            padding: 1px 6px;
            line-height: 1.4;
        }
        /* Subtle professional hover (overrides loud orange below) */
        #gradesTable.rq-grades.table-hover tbody tr:hover > td {
            background-color: #f1f5f9;
        }
        #gradesTable.rq-grades.table-hover tbody tr:hover > td.rq-student {
            background-color: #eef2f7;
        }
        #gradesTable.rq-grades.table-hover tbody tr:hover {
            cursor: default;
        }
        #gradesTable.rq-grades .editable-mark:focus {
            background-color: #eff6ff;
            outline: 2px solid var(--rq-accent);
            outline-offset: -2px;
            border-radius: 4px;
        }
        #gradesTable.rq-grades .editable-mark.error {
            background-color: #fef2f2;
            outline: 2px solid #dc2626;
        }
        /* Status tints - soft professional */
        #gradesTable.rq-grades tbody tr.status-dropped td { background-color: rgba(185,28,28,.06); }
        #gradesTable.rq-grades tbody tr.status-dropped td.rq-student { background-color: #fef2f2; }
        #gradesTable.rq-grades tbody tr.status-nosub td { background-color: rgba(146,64,14,.06); }
        #gradesTable.rq-grades tbody tr.status-nosub td.rq-student { background-color: #fffbeb; }
        #gradesTable.rq-grades tbody tr.status-noexam td { background-color: rgba(7,89,133,.06); }
        #gradesTable.rq-grades tbody tr.status-noexam td.rq-student { background-color: #f0f9ff; }
        /* Freeze Panes Styles */
        .freeze-panes-container {
            position: relative;
            max-height: 70vh;
            max-width: 100%;
            width: 100%;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }

        .grades-table-container {
            overflow-x: auto;
            overflow-y: auto;
        }

        .table-header {
            position: sticky;
            top: 0;
            z-index: 15;
            /* Grades table is always light - even in dark mode */
            background-color: #ffffff;
        }

        .sticky-col {
            position: sticky !important;
            left: 0;
        }

        .sticky-student-col {
            left: 0 !important;
            min-width: 180px;
            width: 180px;
            max-width: 180px;
            background-color: #fff !important;
            background-clip: padding-box;
            border-right: 2px solid #adb5bd !important;
            box-shadow: 6px 0 8px -6px rgba(0,0,0,0.18);
            z-index: 12;
        }

        .grades-table tbody .sticky-student-col {
            position: sticky !important;
            left: 0 !important;
            z-index: 11;
            white-space: normal;
            word-break: break-word;
        }

        .grades-table thead .sticky-student-col {
            position: sticky !important;
            left: 0 !important;
            z-index: 30 !important;
        }

        /* Fix for scrolling alignment */
        .freeze-panes-container table {
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .grades-table {
            display: block;
            width: 100%;
            min-width: 100%;
            max-width: 100%;
            overflow-x: auto;
            table-layout: auto;
        }

        .active-quarters-container {
            overflow-x: auto;
            overflow-y: auto;
        }

        .active-quarters-table {
            width: 100%;
            min-width: 100%;
        }

        .single-quarter-table {
            width: 100%;
            min-width: 100%;
        }

        /* Adjust row height for tbody */
        .freeze-panes-container tbody tr {
            height: 25px;
            min-height: 25px;
        }

        .freeze-panes-container tbody td {
            padding: 4px 6px;
            vertical-align: middle;
            line-height: 1.2;
        }

        /* Fix border issues during scrolling */
        .freeze-panes-container .table-bordered {
            border: 1px solid #adb5bd;
        }

        .freeze-panes-container .table-bordered th,
        .freeze-panes-container .table-bordered td {
            border: 1px solid #adb5bd;
        }

        /* Ensure header borders remain visible */
        .table-header th {
            border-bottom: 1px solid #adb5bd !important;
            border-top: 1px solid #adb5bd !important;
        }

        /* Fix sticky column borders */
        .sticky-col {
            border-left: 1px solid #adb5bd !important;
        }

        .table-header th.sticky-col {
            border-left: 1px solid #adb5bd !important;
            border-top: 1px solid #adb5bd !important;
        }

        .grades-table tbody td.sticky-student-col {
            background-color: #ffffff !important;
        }

        .grades-table.table-hover tbody tr:hover td.sticky-student-col {
            background-color: #f1f5f9 !important;
        }

        /* =====================================================================
           #gradesTable ALWAYS renders in LIGHT mode.
           Dark mode is intentionally NOT applied to the grades table so the
           class record stays readable and matches what gets printed/exported.
           These rules neutralize the Horizon dark theme's .table styling;
           the ID selector outranks every class-based dark rule in
           css/horizon-theme.css.
           ===================================================================== */
        html[data-theme="dark"] #gradesTable,
        body[data-theme="dark"] #gradesTable,
        body[data-bs-theme="dark"] #gradesTable {
            /* Bootstrap table tokens - forced to their light values.
               Striped vars pinned to white: rows must NOT alternate. */
            --bs-table-bg: #ffffff;
            --bs-table-color: #000000;
            --bs-table-border-color: #adb5bd;
            --bs-table-striped-bg: #ffffff;
            --bs-table-striped-color: #000000;
            /* Student column vars - permanently light */
            --rq-student-bg: #ffffff;
            --rq-student-text: #000000;
            --rq-cell-border: #adb5bd;
            /* Neutral surface + black text regardless of theme */
            background-color: #ffffff;
            color: #000000;
        }

        html[data-theme="dark"] #gradesTable tbody td,
        body[data-theme="dark"] #gradesTable tbody td,
        body[data-bs-theme="dark"] #gradesTable tbody td {
            /* Undo Horizon's dark tbody fill; let Bootstrap striping show */
            background-color: transparent;
            color: inherit;
            border-color: #adb5bd;
        }

        /* Restore the original subtle row hover instead of the theme hover */
        html[data-theme="dark"] #gradesTable.table-hover tbody tr:hover > td,
        body[data-theme="dark"] #gradesTable.table-hover tbody tr:hover > td,
        body[data-bs-theme="dark"] #gradesTable.table-hover tbody tr:hover > td {
            background-color: #f1f5f9 !important;
            color: #0f172a;
        }

        /* Restore the original status row tints (not Horizon's dark ones) */
        html[data-theme="dark"] #gradesTable tbody tr.status-dropped td,
        body[data-theme="dark"] #gradesTable tbody tr.status-dropped td,
        body[data-bs-theme="dark"] #gradesTable tbody tr.status-dropped td {
            background-color: rgba(185, 28, 28, 0.08) !important;
        }
        html[data-theme="dark"] #gradesTable tbody tr.status-nosub td,
        body[data-theme="dark"] #gradesTable tbody tr.status-nosub td,
        body[data-bs-theme="dark"] #gradesTable tbody tr.status-nosub td {
            background-color: rgba(146, 64, 14, 0.08) !important;
        }
        html[data-theme="dark"] #gradesTable tbody tr.status-noexam td,
        body[data-theme="dark"] #gradesTable tbody tr.status-noexam td,
        body[data-bs-theme="dark"] #gradesTable tbody tr.status-noexam td {
            background-color: rgba(7, 89, 133, 0.08) !important;
        }

        /* Sticky student cell: white surface, black text even in dark mode */
        html[data-theme="dark"] #gradesTable tbody td.sticky-col,
        body[data-theme="dark"] #gradesTable tbody td.sticky-col,
        body[data-bs-theme="dark"] #gradesTable tbody td.sticky-col {
            background-color: #ffffff !important;
            color: #000000 !important;
        }

        /* Add hover effect for table cells */
        .editable-mark:focus {
            background-color: #eff6ff;
            outline: 2px solid #2563eb;
            border-radius: 3px;
            min-height: 24px; /* Ensure minimum cell height */
            padding: 2px; /* Add some padding */
        }

        .table-hover tbody tr:hover td {
            background-color: #f1f5f9 !important;
        }

        .table-hover tbody tr:hover .sticky-col {
            background-color: #f1f5f9 !important;
        }

        .table-hover tbody tr:hover {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .report-class-tabs {
            gap: 0;
            align-items: end;
            border-bottom: 1px solid #c6ccd3;
            padding-top: 0.35rem;
            padding-left: 0.35rem;
            overflow-x: auto;
            overflow-y: hidden;
            flex-wrap: nowrap;
            scrollbar-width: thin;
            background: linear-gradient(180deg, #eef2f6 0%, #e3e8ee 100%);
            border-radius: 0.65rem 0.65rem 0 0;
        }

        .report-class-tab {
            position: relative;
            margin-bottom: -1px;
            margin-right: -1px;
            padding: 0.55rem 1rem 0.5rem;
            border: 1px solid #bcc4cd;
            border-bottom: none;
            border-radius: 0.85rem 0.85rem 0 0;
            background: linear-gradient(180deg, #f1f4f7 0%, #dfe5ec 100%);
            color: #4b5563;
            font-size: 0.85rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.95);
            transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }

        .report-class-tab::before,
        .report-class-tab::after {
            content: "";
            position: absolute;
            bottom: 0;
            width: 12px;
            height: 12px;
            background: transparent;
            pointer-events: none;
        }

        .report-class-tab::before {
            left: -12px;
            border-bottom-right-radius: 12px;
            box-shadow: 6px 6px 0 0 #dfe5ec;
        }

        .report-class-tab::after {
            right: -12px;
            border-bottom-left-radius: 12px;
            box-shadow: -6px 6px 0 0 #dfe5ec;
        }

        .report-class-tab:hover {
            background: linear-gradient(180deg, #f9fbfc 0%, #e7edf3 100%);
            color: #1f2937;
            transform: translateY(-1px);
            z-index: 1;
        }

        .report-class-tab:hover::before {
            box-shadow: 6px 6px 0 0 #e7edf3;
        }

        .report-class-tab:hover::after {
            box-shadow: -6px 6px 0 0 #e7edf3;
        }

        .report-class-tab.active {
            background: linear-gradient(180deg, #ffffff 0%, #fdfefe 100%);
            border-color: #bcc4cd;
            color: #111827;
            z-index: 3;
            box-shadow: inset 0 1px 0 rgba(255,255,255,1), 0 -1px 0 rgba(255,255,255,1);
        }

        .report-class-tab.active::before {
            box-shadow: 6px 6px 0 0 #ffffff;
        }

        .report-class-tab.active::after {
            box-shadow: -6px 6px 0 0 #ffffff;
        }

        /* =====================================================================
           Dark mode (Horizon theme) - ACTIVE tab only.
           Only the selected Subject/Section tab switches to the dark card
           surface (melting into the content below via its ::before/::after
           corner shadows). Inactive tabs keep their regular styling.
           ===================================================================== */
        html[data-theme="dark"] .report-class-tab.active,
        body[data-theme="dark"] .report-class-tab.active,
        body[data-bs-theme="dark"] .report-class-tab.active {
            background: linear-gradient(180deg, #1d2a40 0%, #182235 100%);
            border-color: #3b4a63;
            color: #f1f5ff;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
        }

        html[data-theme="dark"] .report-class-tab.active::before,
        body[data-theme="dark"] .report-class-tab.active::before,
        body[data-bs-theme="dark"] .report-class-tab.active::before {
            box-shadow: 6px 6px 0 0 #182235;
        }

        html[data-theme="dark"] .report-class-tab.active::after,
        body[data-theme="dark"] .report-class-tab.active::after,
        body[data-bs-theme="dark"] .report-class-tab.active::after {
            box-shadow: -6px 6px 0 0 #182235;
        }

        @media print {
            /* Printing always uses light surfaces, even in dark mode */
            :root,
            [data-theme="dark"],
            body[data-theme="dark"],
            body[data-bs-theme="dark"] {
                --rq-student-bg: #ffffff;
                --rq-cell-border: #adb5bd;
                --rq-student-text: #000000;
            }
            @page {
                size: 8.5in 13in;
                margin: 0.5in;
            }
            body {
                margin: 0;
                padding: 10px;
                font-family: Arial, sans-serif;
                font-size: 9pt;
            }

            .freeze-panes-container {
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            .grades-table-container {
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            .card-header, .btn, #transmuted_table, .no-print {
                display: none !important;
            }
            /* Let the long grades table paginate naturally across pages so
               signatories that follow can't overlap continued rows */
            #outprint {
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            #outprint .row {
                display: block !important;
            }
            .table-responsive {
                margin-bottom: 0 !important;
                clear: both !important;
                position: static !important;
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
            }
            .table {
                margin-bottom: 20px !important;
                position: static !important;
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
            }
            /* Sticky/frozen panes must not apply in print - they pin cells
               over the continued rows on page 2 */
            .table-header,
            thead.sticky-top,
            .sticky-col,
            th.sticky-col,
            td.sticky-col,
            .table-header th.sticky-col {
                position: static !important;
                left: auto !important;
                top: auto !important;
                box-shadow: none !important;
                z-index: auto !important;
            }
            /* Signatories flow after the table/table container in print */
            .col-8 {
                display: block !important;
                position: static !important;
                float: none !important;
                clear: both !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 30px 0 0 0 !important;
                background: white !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .col-8.offset-2.mt-2 .row {
                display: flex !important;
                flex-wrap: wrap !important;
                margin-right: -15px !important;
                margin-left: -15px !important;
            }
            .col-md-4.text-center {
                display: block !important;
                page-break-inside: avoid;
                width: 33.33% !important;
                float: left !important;
                padding: 0 15px !important;
            }
            .col-md-4.text-center h6 {
                font-size: 10pt !important;
                margin-bottom: 15px !important;
            }
            .col-md-4.text-center .mb-4 {
                margin-bottom: 20px !important;
            }
            .col-md-4.text-center .mb-1 {
                border-bottom: 1px solid #000 !important;
                width: 90% !important;
                height: 50px !important;
                margin: 0 auto !important;
            }
            .col-md-4.text-center .mt-2 {
                margin-top: 10px !important;
                font-weight: bold !important;
                font-size: 9pt !important;
            }
            .col-md-4.text-center .text-muted {
                font-size: 8pt !important;
                color: #666 !important;
            }
            .table {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 0 30px 0 !important;
                font-size: 9pt !important;
                border-collapse: collapse !important;
                border: 1px solid #dee2e6 !important;
                position: static !important;
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            .table th, .table td {
                padding: 3px 5px !important;
                line-height: 1.2;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                border: 1px solid #dee2e6 !important;
            }
            .table td {
                width: 35px !important;
                min-width: 35px !important;
                max-width: 35px !important;
            }
            .table th {
                background-color:#1e293b !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-weight: bold;
            }
            .bg-dark {
                background-color: #1e293b !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                color: #fff !important;
            }
            .bg-warning {
                background-color: #fff3cd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .bg-info {
                background-color: #d1ecf1 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .text-center {
                text-align: center !important;
                font-size: 7pt !important;
            }
            .text-truncate {
                text-align: center !important;
                font-size: 7pt !important;
            }
            .text-danger {
                color: #991b1b !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .text-light {
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .text-success {
                color:#166534 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .text-warning {
                color: #92400e !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .text-dark {
                color: #1e293b !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            #gradesTable.rq-grades thead tr.rq-row-main th,
            #gradesTable.rq-grades thead th.rq-h-total,
            #gradesTable.rq-grades thead th.rq-h-pct,
            #gradesTable.rq-grades .rq-pill {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            /* --- Professional rq design in browser print (Ctrl+P) --- */
            #gradesTable.rq-grades thead tr.rq-row-main th {
                background-color: #1e293b !important;
                color: #f8fafc !important;
            }
            #gradesTable.rq-grades thead tr.rq-row-main th.rq-h-student {
                background-color: #0f172a !important;
            }
            #gradesTable.rq-grades thead tr.rq-row-quarter th {
                background-color: #f1f5f9 !important;
                color: #475569 !important;
            }
            #gradesTable.rq-grades thead tr.rq-row-assess th.rq-h-assess,
            #gradesTable.rq-grades thead tr.rq-row-hps th.rq-h-hps {
                background-color: #ffffff !important;
                color: #0f172a !important;
            }
            #gradesTable.rq-grades thead th.rq-h-total,
            #gradesTable.rq-grades thead th.rq-h-total-val {
                background-color: #dbe2ec !important;
                color: #334155 !important;
            }
            #gradesTable.rq-grades thead th.rq-h-pct,
            #gradesTable.rq-grades thead th.rq-h-pct-val {
                background-color: #cbd5e1 !important;
                color: #1e293b !important;
            }
            #gradesTable.rq-grades tbody td.rq-total {
                background-color: #e2e8f0 !important;
            }
            #gradesTable.rq-grades tbody td.rq-weighted {
                background-color: #d7dfea !important;
            }
            #gradesTable.rq-grades tbody td.rq-initial-cell {
                background-color: #f8fafc !important;
                color: #0f172a !important;
            }
            #gradesTable.rq-grades tbody td.rq-grade-cell {
                background-color: #ffffff !important;
            }
            #gradesTable.rq-grades .rq-pill,
            #gradesTable.rq-grades .rq-badge,
            #gradesTable.rq-grades .rq-comp-pct {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            /* Signatories section print styles */
            .pt-4 {
                padding-top: 20px !important;
            }
            .row {
                display: flex !important;
                flex-wrap: wrap !important;
                margin-right: -15px !important;
                margin-left: -15px !important;
            }
            /* Table positioning to ensure signatories appear after */
            #outprint {
                position: static !important;
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            #outprint .table-responsive {
                position: static !important;
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            #outprint .table {
                position: static !important;
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
                margin-bottom: 30px !important;
            }
            .mt-2 {
                margin-top: 20px !important;
            }
            .mb-4 {
                margin-bottom: 20px !important;
            }
            .mb-1 {
                margin-bottom: 5px !important;
            }
            .fw-bold {
                font-weight: bold !important;
            }
            .bg-opacity-50 {
                opacity: 1 !important;
            }
            /* Ensure tables don't break across pages */
            table { page-break-inside:auto; }
            tr    { page-break-inside:avoid; page-break-after:auto; }
            thead { display:table-header-group; }
            tfoot { display:table-footer-group; }
        }
    </style>
    <style>

            .text-success {
                color:#166534 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .capital-red {
                color: inherit !important;
                font-weight: inherit;
            }

    </style>

    <!-- Tooltip styles -->
    <style>
    .error-tooltip {
        position: absolute;
        background-color: #dc3545;
        color: white;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
        z-index: 9999;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transform: translateY(-5px);
        transition: opacity 0.3s ease, transform 0.3s ease;
    }

    .error-tooltip.show {
        opacity: 1;
        transform: translateY(0);
    }

    .error-tooltip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 10%;
        margin-left: -5px;
        border-width: 5px;
        border-style: solid;
        border-color: #dc3545 transparent transparent transparent;
    }

    /* Status-based row coloring - soft professional tints */
    .status-dropped {
        background-color: rgba(185, 28, 28, 0.06) !important;
    }

    .status-nosub {
        background-color: rgba(146, 64, 14, 0.06) !important;
    }

    .status-noexam {
        background-color: rgba(7, 89, 133, 0.06) !important;
    }

    /* Ensure status colors remain on hover */
    .table-hover tbody tr.status-dropped td {
        background-color: rgba(185, 28, 28, 0.06) !important;
    }
    .table-hover tbody tr.status-nosub td {
        background-color: rgba(146, 64, 14, 0.06) !important;
    }
    .table-hover tbody tr.status-noexam td {
        background-color: rgba(7, 89, 133, 0.06) !important;
    }

    .editable-mark.error {
        background-color: #fef2f2 !important;
        outline: 2px solid #dc2626 !important;
        border-radius: 4px !important;
    }

    /* Fast Single Entry toggle (same look as marks.php) */
    .toggle-enter-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border: none;
        background: transparent;
        cursor: pointer;
        font-weight: bold;
    }
    .toggle-enter-label {
        font-size: 0.8rem;
        text-transform: uppercase;
    }
    .toggle-enter-switch {
        position: relative;
        display: inline-flex;
        align-items: center;
        padding: 4px 12px 4px 4px;
        border-radius: 999px;
        background: #dee2e6;
        transition: background 0.2s ease-in-out;
    }
    .toggle-enter-knob {
        width: 22px;
        height: 16px;
        border-radius: 50%;
        background: white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        transition: transform 0.2s ease-in-out;
    }
    .toggle-enter-btn.on .toggle-enter-switch {
        background: #0d55fdff;
        color: #ffffff;
    }
    .toggle-enter-btn.on .toggle-enter-knob {
        transform: translateX(16px);
    }
    .toggle-enter-text {
        font-size: 0.75rem;
        margin-left: 6px;
        font-weight: 600;
    }
    /* =====================================================================
       PAGE SHELL — header, navigation card, action toolbar
       The grades table below is deliberately untouched.
       ===================================================================== */
    .rq-page-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1.25rem;
        flex-wrap: wrap;
        padding: 1rem 1.25rem;
        margin-bottom: 0.85rem;
        background: linear-gradient(135deg, var(--rq-navy) 0%, #243449 100%);
        border-radius: 14px;
        box-shadow: 0 10px 24px -12px rgba(15, 23, 42, 0.45);
        position: relative;
        overflow: hidden;
    }
    .rq-page-head::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        background: linear-gradient(90deg, #d4af37, #f4e5c2, #d4af37);
        background-size: 200% 100%;
        animation: rqShimmer 3s infinite;
    }
    @keyframes rqShimmer {
        0% { background-position: -1000px 0; }
        100% { background-position: 1000px 0; }
    }
    .rq-page-head-main { min-width: 0; }
    .rq-page-title {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin: 0;
        color: #f8fafc;
        font-size: 1.15rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.35);
        line-height: 1.2;
    }
    .rq-page-title-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        flex-shrink: 0;
        border-radius: 10px;
        background: linear-gradient(135deg, #d4af37 0%, #f4e5c2 100%);
        color: var(--rq-navy);
        font-size: 0.95rem;
    }
    .rq-page-sub {
        margin: 0.3rem 0 0 0;
        color: #cbd5e1;
        font-size: 0.78rem;
        letter-spacing: 0.02em;
    }
    .rq-page-head-quarter {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 220px;
        max-width: 320px;
        flex: 1 1 220px;
    }
    .rq-page-head-quarter .form-select {
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.25);
        font-weight: 600;
        color: var(--rq-navy);
    }
    .rq-field-label {
        display: block;
        margin-bottom: 0.3rem;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--rq-slate);
    }
    .rq-page-head .rq-field-label {
        color: var(--rq-gold-light, #f4e5c2);
    }
    .rq-hint {
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--rq-muted);
    }

    /* ---- Navigation card: subject + section tabs ---- */
    .rq-nav-card {
        padding: 0.85rem 1rem 1rem;
        margin-bottom: 0.85rem;
        background: var(--rq-card-bg, #fff);
        border: 1px solid var(--rq-line);
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    }
    .rq-tab-rows {
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
    }
    .rq-empty-hint {
        margin-top: 0.5rem;
        padding: 0.45rem 0.7rem;
        border: 1px solid var(--rq-line);
        border-left: 3px solid #f59e0b;
        border-radius: 10px;
        background: #fffbeb;
        color: #92400e;
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* ---- Action toolbar ---- */
    .rq-toolbar {
        margin-bottom: 0.85rem;
        padding: 0.6rem 0.75rem;
        background: var(--rq-card-bg, #fff);
        border: 1px solid var(--rq-line);
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        position: sticky;
        top: 0;
        z-index: 40;
    }
    .rq-toolbar-row {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
    }
    .rq-tool-group {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        min-width: 0;
    }
    .rq-tool-group + .rq-tool-group {
        padding-left: 0.6rem;
        border-left: 1px solid var(--rq-line);
    }
    .rq-tool-grow {
        flex: 1 1 260px;
        min-width: 0;
    }
    .rq-search-wrap {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
        min-width: 0;
    }
    .rq-search-wrap .form-control {
        padding-left: 2.1rem;
        padding-right: 0.4rem;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        background: #f8f9fa;
        font-size: 0.88rem;
        min-width: 0;
    }
    .rq-search-wrap .form-control:focus {
        border-color: #1a4d2e;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(26, 77, 46, 0.12);
    }
    .rq-search-icon {
        position: absolute;
        left: 0.7rem;
        color: #6c757d;
        font-size: 0.8rem;
        pointer-events: none;
        z-index: 2;
    }
    .rq-tool-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        height: 34px;
        padding: 0 0.75rem !important;
        border: 1px solid var(--rq-line-strong);
        border-radius: 10px;
        background: #fff;
        color: var(--rq-text);
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .rq-tool-btn:hover { filter: none; border-color: #94a3b8; background: #f8fafc; }
    .rq-tool-btn-primary,
    .rq-tool-btn-primary:hover {
        background: linear-gradient(135deg, #1a4d2e 0%, #2d5f3f 100%);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 4px 12px rgba(26, 77, 46, 0.28);
    }
    .rq-tool-btn-primary:hover { filter: brightness(1.08); }
    .rq-tool-btn-primary::after {
        border-top-color: #fff !important;
    }
    .rq-tool-btn-warning {
        border-color: rgba(245, 158, 11, 0.55);
        background: #fffbeb;
        color: #b45309;
        margin-left: 0.4rem;
        flex-shrink: 0;
    }
    .rq-tool-btn-warning:hover {
        background: var(--rq-gold, #d4af37);
        border-color: var(--rq-gold, #d4af37);
        color: #1a1a1a;
    }
    /* The failed-filter script toggles btn-outline-warning / btn-warning */
    #filterGradesAbove3.active {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        border-color: transparent;
        color: #fff;
    }
    .rq-tool-btn-ghost {
        border-color: rgba(26, 77, 46, 0.3);
        background: #f4f8f5;
        color: #1a4d2e;
    }
    .rq-tool-btn-ghost:hover {
        background: #1a4d2e;
        border-color: #1a4d2e;
        color: #fff;
    }
    /* Open state of the collapsible Bulk Set Range panel */
    .rq-tool-btn.rq-tool-btn-active {
        background: linear-gradient(135deg, #1a4d2e 0%, #2d5f3f 100%);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 4px 12px rgba(26, 77, 46, 0.28);
    }
    .rq-output-menu {
        min-width: 15rem;
        border-radius: 12px;
        padding: 0.35rem;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
    }
    .rq-output-menu .dropdown-header {
        font-size: 0.66rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--rq-muted);
    }
    .rq-output-menu .dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border-radius: 8px;
        padding: 0.45rem 0.6rem;
        font-size: 0.82rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .rq-output-menu .dropdown-item i {
        width: 18px;
        text-align: center;
        color: var(--rq-slate);
    }
    .rq-output-menu .dropdown-item:hover { background: var(--rq-bg-soft); }

    /* ---- Bulk Set Range panel ---- */
    .rq-bulk-panel {
        display: none;
        margin-top: 0.6rem;
        padding-top: 0.6rem;
        border-top: 1px dashed var(--rq-line-strong);
    }
    .rq-bulk-panel.is-open { display: block; }
    .rq-bulk-grid {
        display: flex;
        align-items: flex-end;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .rq-bulk-field {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .rq-bulk-actions {
        flex-direction: row;
        align-items: center;
        gap: 0.4rem;
    }
    .rq-bulk-note {
        flex: 1 1 12rem;
        justify-content: flex-end;
        text-align: right;
        padding-bottom: 0.2rem;
    }
    .rq-bulk-range .input-group-text {
        background: #eef2f6;
        border-color: var(--rq-line-strong);
        font-size: 0.72rem;
        font-weight: 700;
        color: #475569;
    }
    .rq-bulk-range .form-control {
        font-weight: 700;
        text-align: center;
        font-size: 0.8rem;
    }
    .rq-bulk-range .form-control:focus {
        box-shadow: inset 0 0 0 2px rgba(26, 77, 46, 0.25);
    }
    @media (max-width: 768px) {
        .rq-tool-group + .rq-tool-group {
            padding-left: 0;
            border-left: 0;
        }
        .rq-toolbar { position: static; }
        .rq-bulk-note { text-align: left; justify-content: flex-start; }
    }

    /* Bulk Set Range cell flash */
    #gradesTable.rq-grades td.rq-bulk-hit {
        background-color: #fef3c7 !important;
        animation: rqBulkPulse 2s ease-in-out;
    }
    @keyframes rqBulkPulse {
        0%   { background-color: #fcd34d; }
        100% { background-color: #fef3c7; }
    }

    /* Dark mode for the new shell (the table below stays light) */
    html[data-theme="dark"] .rq-nav-card,
    body[data-theme="dark"] .rq-nav-card,
    body[data-bs-theme="dark"] .rq-nav-card,
    html[data-theme="dark"] .rq-toolbar,
    body[data-theme="dark"] .rq-toolbar,
    body[data-bs-theme="dark"] .rq-toolbar {
        background: var(--rq-card-bg, #182235);
        border-color: #2b3850;
    }
    html[data-theme="dark"] .rq-tool-group + .rq-tool-group,
    body[data-theme="dark"] .rq-tool-group + .rq-tool-group,
    body[data-bs-theme="dark"] .rq-tool-group + .rq-tool-group {
        border-left-color: #2b3850;
    }
    html[data-theme="dark"] .rq-field-label,
    body[data-theme="dark"] .rq-field-label,
    body[data-bs-theme="dark"] .rq-field-label {
        color: #f4e5c2;
    }
    html[data-theme="dark"] .rq-hint,
    body[data-theme="dark"] .rq-hint,
    body[data-bs-theme="dark"] .rq-hint {
        color: #8e9dbc;
    }
    html[data-theme="dark"] .rq-search-wrap .form-control,
    body[data-theme="dark"] .rq-search-wrap .form-control,
    body[data-bs-theme="dark"] .rq-search-wrap .form-control {
        background: #141d2e;
        border-color: #35445e;
        color: #f1f5ff;
    }
    html[data-theme="dark"] .rq-search-icon,
    body[data-theme="dark"] .rq-search-icon,
    body[data-bs-theme="dark"] .rq-search-icon {
        color: #8e9dbc;
    }
    html[data-theme="dark"] .rq-tool-btn,
    body[data-theme="dark"] .rq-tool-btn,
    body[data-bs-theme="dark"] .rq-tool-btn {
        background: #202d43;
        border-color: #35445e;
        color: #e5ebfa;
    }
    html[data-theme="dark"] .rq-tool-btn:hover,
    body[data-theme="dark"] .rq-tool-btn:hover,
    body[data-bs-theme="dark"] .rq-tool-btn:hover {
        background: #2b3850;
        border-color: #868CFF;
        color: #fff;
    }
    html[data-theme="dark"] .rq-output-menu,
    body[data-theme="dark"] .rq-output-menu,
    body[data-bs-theme="dark"] .rq-output-menu {
        background: #182235;
        border-color: #2b3850;
    }
    html[data-theme="dark"] .rq-output-menu .dropdown-item,
    body[data-theme="dark"] .rq-output-menu .dropdown-item,
    body[data-bs-theme="dark"] .rq-output-menu .dropdown-item {
        color: #e5ebfa;
    }
    html[data-theme="dark"] .rq-output-menu .dropdown-item:hover,
    body[data-theme="dark"] .rq-output-menu .dropdown-item:hover,
    body[data-bs-theme="dark"] .rq-output-menu .dropdown-item:hover {
        background: rgba(67, 24, 255, 0.22);
        color: #fff;
    }
    html[data-theme="dark"] .rq-empty-hint,
    body[data-theme="dark"] .rq-empty-hint,
    body[data-bs-theme="dark"] .rq-empty-hint {
        background: rgba(245, 158, 11, 0.14);
        border-color: rgba(245, 158, 11, 0.4);
        color: #fcd34d;
    }
    html[data-theme="dark"] .rq-bulk-panel,
    body[data-theme="dark"] .rq-bulk-panel,
    body[data-bs-theme="dark"] .rq-bulk-panel {
        border-top-color: #2b3850;
    }
    @media print {
        .rq-toolbar,
        .rq-page-head,
        .rq-nav-card {
            position: static !important;
        }
    }
    /* Flash the cells a bulk range just rewrote */
    #gradesTable.rq-grades td.rq-bulk-hit {
        background-color: #fef3c7 !important;
        animation: rqBulkPulse 2s ease-in-out;
    }
    @keyframes rqBulkPulse {
        0%   { background-color: #fcd34d; }
        100% { background-color: #fef3c7; }
    }
    /* Selected component / assessment header target */
    #gradesTable th.rq-h-component.rq-fast-selected,
    #gradesTable th.rq-h-assess.rq-fast-selected {
        outline: 2px solid var(--horizon-brand-500, #4318FF) !important;
        outline-offset: -2px;
        background-color: rgba(67, 24, 255, 0.10) !important;
        color: #ffffff !important;
    }
    #gradesTable th.rq-h-component.rq-fast-selected *,
    #gradesTable th.rq-h-assess.rq-fast-selected * {
        
        background-color: rgba(67, 24, 255, 0.10) !important;
        color: #ffffff !important;

    }
    #gradesTable.rq-fast-armed th.rq-h-component,
    #gradesTable.rq-fast-armed th.rq-h-assess {
        cursor: pointer;
    }
    /* Inline assessment creator (plain + / - toggle, fixed top-right of its cell) */
    #gradesTable th.rq-h-component,
    #gradesTable th.rq-h-quarter {
        position: relative;
    }
    .rq-add-assess-btn {
        position: absolute;
        top: 1px;
        right: -1px;
        z-index: 3;
        background: none;
        border: 0;
        padding: 0 3px;
        color: var(--horizon-brand-500, #4318FF);
        font-size: 13px;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
    }
    .rq-add-assess-btn:hover {
        color: var(--horizon-brand-600, #3311DB);
        filter: none;
    }
    .rq-add-assess-btn.is-open {
        color: #dc3545;
    }
    .rq-add-assess-btn.is-open:hover {
        color: #b02a37;
    }
    #gradesTable th.rq-inline-ed {
        background-color: rgba(67, 24, 255, 0.08) !important;
        vertical-align: middle;
        padding: 6px !important;
    }
    .rq-inline-ed input {
        width: 100%;
        min-width: 110px;
        border: 1.5px solid #c7d2fe;
        border-radius: 8px;
        padding: 6px 10px;
        font-size: 13px;
        font-weight: 600;
        background: #fff;
        color: inherit;
        box-shadow: 0 1px 3px rgba(16, 24, 40, 0.08);
    }
    .rq-inline-ed input::placeholder {
        color: #9ca3af;
        font-weight: 500;
    }
    .rq-inline-ed input:focus {
        outline: none;
        border-color: var(--horizon-brand-500, #4318FF);
        box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.18);
    }
    .rq-inline-ed input:disabled {
        opacity: 0.65;
    }
    .rq-inline-ed-hps input {
        width: 60px;
        max-width: 60px;
        min-width: 0;
        text-align: center;
        margin: 0 auto;
        padding-left: 2px;
        padding-right: 2px;
    }
    html[data-theme="dark"] #gradesTable th.rq-inline-ed,
    body[data-theme="dark"] #gradesTable th.rq-inline-ed,
    body[data-bs-theme="dark"] #gradesTable th.rq-inline-ed {
        background-color: rgba(134, 140, 255, 0.16) !important;
    }
    html[data-theme="dark"] .rq-inline-ed input,
    body[data-theme="dark"] .rq-inline-ed input,
    body[data-bs-theme="dark"] .rq-inline-ed input {
        background: #202d43;
        border-color: #868CFF;
        color: #f1f5ff;
    }
    /* Double-click cell editing + hover trash icon */
    #gradesTable th.rq-h-assess {
        position: relative;
    }
    .rq-del-assess-btn {
        position: absolute;
        top: 0px;
        right: 0px;
        background: none;
        border: 0;
        padding: 0 2px;
        color: #dc3545;
        font-size: 11px;
        line-height: 1;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.2s ease;
        z-index: 4;
    }
    #gradesTable th.rq-h-assess:hover .rq-del-assess-btn {
        opacity: 1;
    }
    .rq-del-assess-btn:hover {
        color: #b02a37;
        filter: none;
    }
    #gradesTable th.rq-cell-ed {
        background-color: rgba(67, 24, 255, 0.08) !important;
        vertical-align: middle;
        padding: 4px !important;
    }
    .rq-cell-ed input {
        width: 100%;
        min-width: 90px;
        border: 1.5px solid #c7d2fe;
        border-radius: 8px;
        padding: 5px 8px;
        font-size: 13px;
        font-weight: 600;
        background: #fff;
        color: inherit;
    }
    .rq-cell-ed input:focus {
        outline: none;
        border-color: var(--horizon-brand-500, #4318FF);
        box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.18);
    }
    .rq-cell-ed input:disabled {
        opacity: 0.65;
    }
    .rq-cell-ed-hps input {
        width: 50px;
        max-width: 50px;
        min-width: 0;
        text-align: center;
        margin: 0 auto;
        padding-left: 2px;
        padding-right: 2px;
    }
    html[data-theme="dark"] #gradesTable th.rq-cell-ed,
    body[data-theme="dark"] #gradesTable th.rq-cell-ed,
    body[data-bs-theme="dark"] #gradesTable th.rq-cell-ed {
        background-color: rgba(134, 140, 255, 0.16) !important;
    }
    html[data-theme="dark"] .rq-cell-ed input,
    body[data-theme="dark"] .rq-cell-ed input,
    body[data-bs-theme="dark"] .rq-cell-ed input {
        background: #202d43;
        border-color: #868CFF;
        color: #f1f5ff;
    }
    @media print {
        .rq-add-assess-btn,
        .rq-del-assess-btn,
        #gradesTable th.rq-inline-ed {
            display: none !important;
        }
    }
    </style>

    <script>
        // Student name search and grade filter functionality
        function filterTable() {
            var searchText = $('#studentSearch').val().toLowerCase().trim();
            var filterFailed = $('#filterGradesAbove3').hasClass('active');

            $('#gradesTable tbody tr').each(function() {
                // Keep non-student placeholder rows (e.g. "No students found")
                // always visible regardless of search/filter state.
                if (!$(this).attr('data-student-id')) {
                    return;
                }

                var firstCell = $(this).find('td:first').text().trim().toLowerCase();
                var lastCell = $(this).find('td:last').text().trim().toLowerCase();

                // Find the student's Initial Grade cell
                var initialGradeCell = $(this).find('td.initial-grade-cell').last();
                var initialGradeText = initialGradeCell.text().trim();

                // Failed student = Initial Grade below the passing mark (75)
                var initialGradeNum = parseFloat(initialGradeText);

                var matchesSearch = searchText === '' || firstCell.includes(searchText) || lastCell.includes(searchText);
                var matchesGradeFilter = !filterFailed || (!isNaN(initialGradeNum) && initialGradeNum < 74.50);

                if (matchesSearch && matchesGradeFilter) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }

        $(document).on('input', '#studentSearch', filterTable);
        $(document).on('click', '#filterGradesAbove3', function() {
            // aria-pressed mirrors the state for assistive tech / the toolbar CSS
            $(this).toggleClass('active');
            var failedOn = $(this).hasClass('active');
            $(this).attr('aria-pressed', failedOn ? 'true' : 'false');
            filterTable();
        });

        // Inline assessment creator: "+" on a component/quarter header opens
        // in-cell editors (name in the assessment row, HPS in the HPS row).
        // Save posts to save_assessment (auto % like marks.php), then reloads.
        function rqToast(type, msg){
            if (window.showAppToast) { window.showAppToast(type, msg); return; }
            alert(msg);
        }
        function rqCloseInlineEd(){
            $('#gradesTable th.rq-inline-ed').remove();
            $('#gradesTable .rq-add-assess-btn').each(function(){
                $(this).removeClass('is-open').html('<i class="fas fa-plus"></i>').removeData('saving');
            });
        }
        // Locate the T (or "No assessments") cell that terminates a component
        // group so the editor cells land inside the right group:
        // single-quarter -> T cell per component; active-quarters -> T cell per (component, quarter).
        function rqGroupAnchor($thead, componentId, quarter){
            componentId = String(componentId);
            quarter = String(quarter);
            var $assessRow = $thead.find('tr.rq-row-assess');
            var $hpsRow = $thead.find('tr.rq-row-hps');
            if (!$assessRow.length || !$hpsRow.length) return null;
            var isActive = $thead.find('tr.rq-row-quarter').length > 0;
            if (!isActive) {
                var compIds = [];
                $thead.find('tr.rq-row-main th.rq-h-component').each(function(){
                    compIds.push(String($(this).attr('data-component-id') || ''));
                });
                var idx = compIds.indexOf(componentId);
                if (idx === -1) return null;
                var tIdx = 0, nIdx = 0, hasAssess = false;
                for (var i = 0; i < compIds.length; i++) {
                    var has = $assessRow.children('th.rq-h-assess[data-component-id="' + compIds[i] + '"]').length > 0;
                    if (i === idx) { hasAssess = has; break; }
                    if (has) tIdx++; else nIdx++;
                }
                if (hasAssess) {
                    return { $nameBefore: $assessRow.children('th.rq-h-total').eq(tIdx), $hpsBefore: $hpsRow.children('th.rq-h-total-val').eq(tIdx) };
                }
                return { $nameBefore: $assessRow.children('th.rq-h-none').eq(nIdx), $hpsBefore: $hpsRow.children('th.rq-h-none').eq(nIdx) };
            }
            var $qths = $thead.find('tr.rq-row-quarter > th');
            var $qth = $qths.filter('[data-component-id="' + componentId + '"][data-quarter="' + quarter + '"]');
            if (!$qth.length) return null;
            var groupIdx = $qths.index($qth);
            var tPos = 0, nPos = 0, curHas = false;
            for (var g = 0; g <= groupIdx; g++) {
                var empty = $qths.eq(g).hasClass('rq-h-empty');
                if (g === groupIdx) { curHas = !empty; break; }
                if (empty) nPos++; else tPos++;
            }
            if (curHas) {
                return { $nameBefore: $assessRow.children('th.rq-h-total').eq(tPos), $hpsBefore: $hpsRow.children('th.rq-h-total-val').eq(tPos) };
            }
            return { $nameBefore: $assessRow.children('th.rq-h-none').eq(nPos), $hpsBefore: $hpsRow.children('th.rq-h-none').eq(nPos) };
        }
        $(document).on('click', '.rq-add-assess-btn', function(e){
            e.stopPropagation();
            var $btn = $(this);
            var componentId = String($btn.attr('data-component-id') || '');
            var quarter = String($btn.attr('data-quarter') || '');
            var classId = String($('#class_id').val() || '');
            if (!classId) { rqToast('warning', 'Please select a class first.'); return; }
            if (!componentId) { rqToast('warning', 'Missing component.'); return; }
            if (!quarter || quarter === 'active' || quarter === '0') { rqToast('warning', 'Please select a specific quarter first.'); return; }
            var $thead = $btn.closest('thead');
            // Minus state cancels the open editor column
            if ($btn.hasClass('is-open')) {
                rqCloseInlineEd();
                return;
            }
            var anchor = rqGroupAnchor($thead, componentId, quarter);
            if (!anchor || !anchor.$nameBefore.length || !anchor.$hpsBefore.length) {
                rqToast('danger', 'Could not locate the assessment group.');
                return;
            }
            rqCloseInlineEd();
            rqCloseCellEdit();
            $btn.addClass('is-open').html('<i class="fas fa-minus"></i>');

            var $nameTh = $('<th class="py-1 px-2 text-center rq-inline-ed rq-inline-ed-name" title="New assessment name">' +
                '<input type="text" maxlength="120" aria-label="New assessment name">' +
                '</th>');
            var $hpsTh = $('<th class="py-1 px-2 text-center rq-inline-ed rq-inline-ed-hps" title="Highest possible score">' +
                '<input type="number" min="0" step="any" aria-label="Highest possible score">' +
                '</th>');

            anchor.$nameBefore.before($nameTh);
            anchor.$hpsBefore.before($hpsTh);
            var $nameInput = $nameTh.find('input');
            var $hpsInput = $hpsTh.find('input');

            // Auto percentage is applied silently (hidden from the UI)
            var suggestedPct = 0;
            $.post('./Actions.php?a=get_component_suggested_percentage', {
                class_id: classId, component_id: componentId, quarter: quarter
            }, function(resp){
                if (resp && resp.status === 'success') {
                    suggestedPct = resp.suggested;
                }
            }, 'json');

            function doSave(){
                if ($btn.data('saving')) return;
                var name = $.trim($nameInput.val());
                var hpsRaw = $.trim($hpsInput.val());
                var hps = parseFloat(hpsRaw);
                if (name === '') { rqToast('warning', 'Please enter an assessment name.'); $nameInput.trigger('focus'); return; }
                if (hpsRaw === '' || isNaN(hps) || hps <= 0) { rqToast('warning', 'Please enter a valid HPS greater than 0.'); $hpsInput.trigger('focus'); return; }
                $btn.data('saving', true);
                $nameInput.add($hpsInput).prop('disabled', true);
                $.post('./Actions.php?a=save_assessment', {
                    id: '', class_id: classId, component_id: componentId,
                    quarter: quarter, name: name, percentage: suggestedPct, hps: hps,
                    manual_percentage: 1
                }, function(resp){
                    if (resp && resp.status === 'success') {
                        rqToast('success', (resp.msg || 'Assessment successfully saved.'));
                        setTimeout(function(){ location.reload(); }, 600);
                    } else {
                        rqToast('danger', (resp && resp.msg) || 'Unable to create assessment.');
                        $btn.data('saving', false);
                        $nameInput.add($hpsInput).prop('disabled', false);
                    }
                }, 'json').fail(function(){
                    rqToast('danger', 'Network error while creating the assessment.');
                    $btn.data('saving', false);
                    $nameInput.add($hpsInput).prop('disabled', false);
                });
            }

            $nameTh.add($hpsTh).find('input').on('keydown', function(ev){
                if (ev.key === 'Enter') { ev.preventDefault(); doSave(); }
                else if (ev.key === 'Escape') { ev.preventDefault(); rqCloseInlineEd(); }
            });
            $nameInput.trigger('focus');
        });

        // Double-click to edit: an assessment name or HPS cell becomes an input.
        function rqCloseCellEdit(){
            $('#gradesTable th.rq-cell-ed').each(function(){
                var $th = $(this);
                var orig = $th.data('orig-html');
                if (orig !== undefined) $th.html(orig);
                $th.removeClass('rq-cell-ed rq-cell-ed-name rq-cell-ed-hps').removeData('orig-html').removeData('saving');
            });
        }
        function rqCommitCellEdit($th){
            if ($th.data('saving')) return;
            var kind = $th.data('edit-kind');
            var $input = $th.find('input');
            if (!$input.length) { rqCloseCellEdit(); return; }
            var assessmentId = String($th.attr('data-assessment-id') || '');
            var componentId = String($th.attr('data-component-id') || '');
            var quarter = String($th.attr('data-quarter') || '');
            var classId = String($('#class_id').val() || '');
            if (!assessmentId || !classId || !componentId || !quarter) { rqCloseCellEdit(); return; }
            var post = { id: assessmentId, class_id: classId, component_id: componentId, quarter: quarter };
            if (kind === 'name') {
                var name = $.trim($input.val());
                if (name === '') { rqToast('warning', 'Assessment name cannot be empty.'); $input.trigger('focus'); return; }
                if (name === String($th.attr('data-name') || '')) { rqCloseCellEdit(); return; }
                post.name = name;
            } else {
                var hpsRaw = $.trim($input.val());
                var hps = parseFloat(hpsRaw);
                if (hpsRaw === '' || isNaN(hps) || hps <= 0) { rqToast('warning', 'Please enter a valid HPS greater than 0.'); $input.trigger('focus'); return; }
                post.name = String($th.attr('data-name') || '');
                post.hps = hps;
            }
            $th.data('saving', true);
            $input.prop('disabled', true);
            $.post('./Actions.php?a=save_assessment', post, function(resp){
                if (resp && resp.status === 'success') {
                    rqToast('success', (resp.msg || 'Assessment successfully updated.'));
                    setTimeout(function(){ location.reload(); }, 600);
                } else {
                    rqToast('danger', (resp && resp.msg) || 'Unable to update.');
                    $th.data('saving', false);
                    $input.prop('disabled', false);
                }
            }, 'json').fail(function(){
                rqToast('danger', 'Network error while updating.');
                $th.data('saving', false);
                $input.prop('disabled', false);
            });
        }
        $(document).on('dblclick', '#gradesTable th.rq-h-assess, #gradesTable th.rq-h-hps', function(e){
            e.stopPropagation();
            var $th = $(this);
            if ($th.hasClass('rq-cell-ed') || $th.hasClass('rq-inline-ed')) return;
            if (!$th.attr('data-assessment-id')) return;
            rqCloseInlineEd();
            rqCloseCellEdit();
            $th.data('orig-html', $th.html());
            var isHps = $th.hasClass('rq-h-hps');
            $th.data('edit-kind', isHps ? 'hps' : 'name');
            var current = isHps
                ? String($th.text().replace('/', '').trim())
                : String($th.attr('data-name') || $th.attr('title') || '').trim();
            var $input = isHps
                ? $('<input type="number" min="0" step="any" aria-label="Edit HPS">').val(current)
                : $('<input type="text" maxlength="120" aria-label="Edit assessment name">').val(current);
            $th.addClass('rq-cell-ed').addClass(isHps ? 'rq-cell-ed-hps' : 'rq-cell-ed-name').empty().append($input);
            $input.on('keydown', function(ev){
                if (ev.key === 'Enter') { ev.preventDefault(); rqCommitCellEdit($th); }
                else if (ev.key === 'Escape') { ev.preventDefault(); rqCloseCellEdit(); }
            });
            $input.on('blur', function(){
                if (!$th.hasClass('rq-cell-ed') || $th.data('saving')) return;
                var now = $.trim($input.val());
                var orig = isHps ? current : String($th.attr('data-name') || '');
                if (now !== String(orig)) rqCommitCellEdit($th);
                else rqCloseCellEdit();
            });
            $input.trigger('focus').trigger('select');
        });

        // Hover trash icon: delete the assessment and its corresponding marks.
        $(document).on('click', '.rq-del-assess-btn', function(e){
            e.stopPropagation();
            var id = String($(this).attr('data-assessment-id') || '');
            var nm = String($(this).attr('data-name') || 'this assessment');
            if (!id) return;
            rqCloseInlineEd();
            rqCloseCellEdit();
            _conf('Are you sure you want to delete <b>' + $('<div>').text(nm).html() + '</b>? Its student marks will also be deleted.', 'rqDeleteAssessment', [id]);
        });
        window.rqDeleteAssessment = function(id){
            var $btns = $('#confirm_modal button');
            $btns.attr('disabled', true);
            $.post('./Actions.php?a=delete_assessment', { id: id }, function(resp){
                if (resp && resp.status === 'success') {
                    $btns.attr('disabled', false);
                    $('#confirm_modal').modal('hide');
                    rqToast('success', 'Assessment and its marks deleted.');
                    setTimeout(function(){ location.reload(); }, 600);
                } else {
                    rqToast('danger', (resp && (resp.msg || resp.error)) || 'Unable to delete assessment.');
                    $btns.attr('disabled', false);
                }
            }, 'json').fail(function(){
                rqToast('danger', 'Network error while deleting the assessment.');
                $btns.attr('disabled', false);
            });
        };

        // Fast Single Entry (reports): arm with the toggle, click a component
        // header cell then a specific assessment header cell. Afterwards,
        // Tab or Enter in the student search box jumps to the first visible
        // editable cell for that component + assessment.
        var rqFast = { on: false, comp: null, assess: null, compName: '', assessName: '' };
        function rqFastArmed(){ return rqFast.on && rqFast.comp !== null && rqFast.assess !== null; }
        function rqFastHint(){
            var $h = $('#rqFastHint');
            if (!$h.length) return;
            if (!rqFast.on) { $h.text('Fast entry off'); return; }
            if (rqFast.comp === null) { $h.text('Step 1: click a component header'); return; }
            if (rqFast.assess === null) { $h.text('Step 2: click an assessment header'); return; }
            $h.text('Target: ' + rqFast.compName + ' › ' + rqFast.assessName + ' — search, then Tab/Enter');
        }
        function rqFastRepaint(){
            var $tbl = $('#gradesTable');
            $tbl.toggleClass('rq-fast-armed', rqFast.on);
            $tbl.find('th.rq-h-component, th.rq-h-assess').removeClass('rq-fast-selected');
            if (rqFast.comp !== null) $tbl.find('th.rq-h-component[data-component-id="' + rqFast.comp + '"]').addClass('rq-fast-selected');
            if (rqFast.assess !== null) $tbl.find('th.rq-h-assess[data-assessment-id="' + rqFast.assess + '"]').addClass('rq-fast-selected');
            rqFastHint();
        }
        function rqFastHeaderText($th, fallbackSel){
            var t = $.trim($th.find(fallbackSel).first().text());
            return t !== '' ? t : $.trim($th.text());
        }
        $(document).on('click', '#rqFastEntryToggle', function(){
            rqFast.on = !rqFast.on;
            $(this).toggleClass('on', rqFast.on).attr('aria-pressed', rqFast.on ? 'true' : 'false');
            if (!rqFast.on) {
                // Toggling off clears the picked component + assessment
                rqFast.comp = null;
                rqFast.assess = null;
                rqFast.compName = '';
                rqFast.assessName = '';
            }
            rqFastRepaint();
        });
        // Step 1: pick the component header cell
        $(document).on('click', '#gradesTable th.rq-h-component', function(){
            if (!rqFast.on) return;
            var cid = String($(this).attr('data-component-id') || '');
            if (cid === '' || cid === 'undefined') return;
            rqFast.comp = cid;
            rqFast.compName = rqFastHeaderText($(this), '.rq-comp-name');
            // A previously picked assessment from another component no longer applies
            var $cur = $('#gradesTable th.rq-h-assess[data-assessment-id="' + rqFast.assess + '"]');
            if (rqFast.assess === null || String($cur.attr('data-component-id') || '') !== cid) {
                rqFast.assess = null;
                rqFast.assessName = '';
            }
            rqFastRepaint();
        });
        // Step 2: pick the specific assessment header cell (implies its component)
        $(document).on('click', '#gradesTable th.rq-h-assess', function(){
            if (!rqFast.on) return;
            var aid = String($(this).attr('data-assessment-id') || '');
            if (aid === '' || aid === 'undefined') return;
            rqFast.assess = aid;
            rqFast.assessName = rqFastHeaderText($(this), '.rq-assess-name');
            var ccid = String($(this).attr('data-component-id') || '');
            if (ccid !== '' && ccid !== 'undefined') {
                rqFast.comp = ccid;
                var $compHead = $('#gradesTable th.rq-h-component[data-component-id="' + ccid + '"]');
                if ($compHead.length) rqFast.compName = rqFastHeaderText($compHead, '.rq-comp-name');
            }
            rqFastRepaint();
        });
        function rqFastFocusCell($cell){
            if (!$cell || !$cell.length) return false;
            $cell.focus();
            try {
                var range = document.createRange();
                range.selectNodeContents($cell[0]);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } catch (err) {}
            try { $cell[0].scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (err) {}
            return true;
        }
        // Tab / Enter in the search box jumps to the first visible editable
        // cell of the selected component + assessment. Left alone when the
        // feature is not fully armed so normal Tab order is preserved.
        $(document).on('keydown', '#studentSearch', function(e){
            if (e.key !== 'Enter' && e.key !== 'Tab') return;
            if (!rqFastArmed()) return;
            e.preventDefault();
            var sel = '[data-component-id="' + rqFast.comp + '"][data-assessment-id="' + rqFast.assess + '"][contenteditable="true"]';
            var $cell = $();
            $('#gradesTable tbody tr').each(function(){
                var $row = $(this);
                if (!$row.is(':visible') || !$row.attr('data-student-id')) return;
                var $c = $row.find('td.editable-mark' + sel).first();
                if ($c.length) { $cell = $c; return false; }
            });
            if ($cell.length) {
                rqFastFocusCell($cell);
            } else if (window.showAppToast) {
                window.showAppToast('warning', 'No editable cell found for the selected component and assessment.');
            }
        });
        // Fast-entry loop: Tab / Enter inside an editable mark cell saves
        // the mark, refreshes the row totals/grades, then hands focus back
        // to the search box (cleared) for the next student. Registered
        // before the default cell handlers so it can suppress the
        // down-column move while fast entry is ON.
        $(document).on('keydown', '#gradesTable td.editable-mark', function(e){
            if (!rqFast.on) return;
            if (e.key !== 'Enter' && e.key !== 'Tab') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            var $cell = $(this);
            var studentId = $cell.data('student-id');
            var assessmentId = $cell.data('assessment-id');
            var classId = $cell.data('class-id');
            var quarter = $cell.data('quarter');
            var raw = $cell.text().trim();
            // Blank stays blank: send an empty mark so the row is removed
            // server-side instead of being stored as 0.
            if (raw === '') {
                $cell.text('');
                $.ajax({
                    url: 'update_mark.php',
                    type: 'POST',
                    data: { student_id: studentId, assessment_id: assessmentId, class_id: classId, quarter: quarter, mark: '' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            updateRowCalculations(studentId, classId, quarter, function() {
                                var $sb = $('#studentSearch');
                                if ($sb.length) {
                                    $sb.val('');
                                    $sb.focus();
                                    try { filterTable(); } catch (err) {}
                                }
                            });
                        }
                    }
                });
                return;
            }
            if (isNaN(raw)) {
                showErrorTooltip($cell, 'Please enter a valid number');
                return;
            }
            var mark = parseFloat(raw);
            var hps = (typeof assessmentHPS !== 'undefined') ? assessmentHPS[assessmentId] : null;
            if (hps && mark > hps) {
                showErrorTooltip($cell, 'Score cannot exceed HPS: ' + hps);
                $cell.text('');
                return;
            }
            mark = mark.toFixed(2);
            $cell.text(mark);
            $.ajax({
                url: 'update_mark.php',
                type: 'POST',
                data: { student_id: studentId, assessment_id: assessmentId, class_id: classId, quarter: quarter, mark: mark },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        updateRowCalculations(studentId, classId, quarter, function() {
                            var $sb = $('#studentSearch');
                            if ($sb.length) {
                                $sb.val('');
                                $sb.focus();
                                try { filterTable(); } catch (err) {}
                            }
                        });
                    } else {
                        alert('Error updating mark: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error updating mark. Please try again.');
                }
            });
        });
        rqFastHint();

        // Initialize assessment HPS data for validation
        var assessmentHPS = <?php
            $hps_data = [];
            if (isset($assess)) {
                foreach ($assess as $component_id => $assessments) {
                    foreach ($assessments as $assessment_id => $assessment) {
                        $hps_data[$assessment_id] = $assessment['hps'];
                    }
                }
            }
            echo json_encode($hps_data);
        ?>;

        // Function to show error tooltip
        function showErrorTooltip($cell, message) {
            // Remove any existing tooltips
            $('.error-tooltip').remove();

            // Remove error class from all cells
            $('.editable-mark').removeClass('error');

            // Add error class to current cell
            $cell.addClass('error');

            // Create tooltip element
            var $tooltip = $('<div class="error-tooltip">' + message + '</div>');

            // Position the tooltip above the cell
            var cellPosition = $cell.offset();
            var cellWidth = $cell.outerWidth();
            var cellHeight = $cell.outerHeight();

            $tooltip.css({
                left: cellPosition.left + (cellWidth / 2) - ($tooltip.outerWidth() / 2),
                top: cellPosition.top - $tooltip.outerHeight() - 10
            });

            // Add to body and show with animation
            $('body').append($tooltip);

            // Trigger reflow for animation
            $tooltip[0].offsetHeight;
            $tooltip.addClass('show');

            // Auto-hide after 3 seconds
            setTimeout(function() {
                $tooltip.removeClass('show');
                setTimeout(function() {
                    $tooltip.remove();
                    $cell.removeClass('error');
                }, 300);
            }, 3000);

            // Hide when user starts typing again
            $cell.on('input', function() {
                $tooltip.removeClass('show');
                setTimeout(function() {
                    $tooltip.remove();
                    $cell.removeClass('error');
                }, 300);
            });

            // Hide when cell loses focus (if not already being handled)
            $cell.on('blur', function() {
                setTimeout(function() {
                    $tooltip.removeClass('show');
                    setTimeout(function() {
                        $tooltip.remove();
                        $cell.removeClass('error');
                    }, 300);
                }, 100);
            });
        }

            // Function to update mark via AJAX
        function updateMark(studentId, assessmentId, classId, quarter, mark) {
            $.ajax({
                url: 'update_mark.php',
                type: 'POST',
                data: {
                    student_id: studentId,
                    assessment_id: assessmentId,
                    class_id: classId,
                    quarter: quarter,
                    mark: mark
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        // Show success message or update UI as needed
                        console.log('Mark updated successfully');
                        // Update calculations dynamically and then focus next cell
                        updateRowCalculations(studentId, classId, quarter, function() {
                            // After row update, focus on the next cell below
                            focusNextCellBelow(studentId, assessmentId);
                        });
                    } else {
                        alert('Error updating mark: ' + response.message);
                        window.location.reload();
                    }
                },
                error: function() {
                    alert('Error updating mark. Please try again.');
                    window.location.reload();
                }
            });
        }

        // Track if focus change is automatic (from Enter key) or manual (user click)
        var isAutomaticFocus = false;
        var $currentEditingCell = null;

        // Function to update row calculations dynamically
        function updateRowCalculations(studentId, classId, quarter, callback) {
            console.log('Updating row calculations for student:', studentId, 'class:', classId, 'quarter:', quarter);

            // Check if this is active quarters view
            var is_active_quarters = window.location.search.includes('quarter=active');

            if (is_active_quarters) {
                console.log('Active quarters view detected - using specialized update');
                // For active quarters, use a different endpoint or modify the data
                $.ajax({
                    url: 'get_student_row_data.php',
                    type: 'POST',
                    data: {
                        student_id: studentId,
                        class_id: classId,
                        quarter: 'active' // Special parameter for active quarters
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('Active quarters AJAX response:', response);
                        if (response.status === 'success') {
                            // Update the row with new data
                            var $row = $('tr[data-student-id="' + studentId + '"]');
                            console.log('Found row:', $row.length > 0);
                            if ($row.length > 0 && response.row_html) {
                                // Store the current focus before replacing
                                var $focusedElement = $(document.activeElement);
                                var focusSelector = '';

                                if ($focusedElement.length && $focusedElement.closest('tr').is($row)) {
                                    focusSelector = '.editable-mark[data-assessment-id="' + $focusedElement.data('assessment-id') + '"]';
                                }

                                $row.replaceWith(response.row_html);
                                console.log('Active quarters row updated successfully');

                                // Apply status class if provided in response
                                if (response.student_status) {
                                    var $newRow = $('tr[data-student-id="' + studentId + '"]');
                                    $newRow.removeClass('status-dropped status-nosub status-noexam');
                                    if (response.student_status === 'Dropped') {
                                        $newRow.addClass('status-dropped');
                                    } else if (response.student_status === 'NoSub') {
                                        $newRow.addClass('status-nosub');
                                    } else if (response.student_status === 'NoExam') {
                                        $newRow.addClass('status-noexam');
                                    }
                                }

                                // Restore focus if we had it
                                if (focusSelector) {
                                    var $newRow = $('tr[data-student-id="' + studentId + '"]');
                                    var $newFocusedElement = $newRow.find(focusSelector);
                                    if ($newFocusedElement.length) {
                                        $newFocusedElement.focus();
                                        // Move cursor to end
                                        var text = $newFocusedElement.text();
                                        $newFocusedElement.text('').text(text);
                                    }
                                }

                                // Execute callback if provided
                                if (typeof callback === 'function') {
                                    callback();
                                }
                            }
                        } else {
                            console.error('Error updating active quarters row calculations:', response.message);
                            // For active quarters, fallback to just executing callback
                            if (typeof callback === 'function') {
                                callback();
                            }
                        }
                    },
                    error: function() {
                        console.error('AJAX error for active quarters - executing callback');
                        // For active quarters, fallback to just executing callback
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }
                });
                return;
            }

            // Get updated row data via AJAX (only for single quarter view)
            $.ajax({
                url: 'get_student_row_data.php',
                type: 'POST',
                data: {
                    student_id: studentId,
                    class_id: classId,
                    quarter: quarter
                },
                dataType: 'json',
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.status === 'success') {
                        // Update the row with new data
                        var $row = $('tr[data-student-id="' + studentId + '"]');
                        console.log('Found row:', $row.length > 0);
                        if ($row.length > 0 && response.row_html) {
                            // Store the current focus before replacing
                            var $focusedElement = $(document.activeElement);
                            var focusSelector = '';

                            if ($focusedElement.length && $focusedElement.closest('tr').is($row)) {
                                focusSelector = '.editable-mark[data-assessment-id="' + $focusedElement.data('assessment-id') + '"]';
                            }

                            $row.replaceWith(response.row_html);
                            console.log('Row updated successfully');

                            // Apply status class if provided in response
                            if (response.student_status) {
                                var $newRow = $('tr[data-student-id="' + studentId + '"]');
                                $newRow.removeClass('status-dropped status-nosub status-noexam');
                                if (response.student_status === 'Dropped') {
                                    $newRow.addClass('status-dropped');
                                } else if (response.student_status === 'NoSub') {
                                    $newRow.addClass('status-nosub');
                                } else if (response.student_status === 'NoExam') {
                                    $newRow.addClass('status-noexam');
                                }
                            }

                            // Restore focus if we had it
                            if (focusSelector) {
                                var $newRow = $('tr[data-student-id="' + studentId + '"]');
                                var $newFocusedElement = $newRow.find(focusSelector);
                                if ($newFocusedElement.length) {
                                    $newFocusedElement.focus();
                                    // Move cursor to end
                                    var text = $newFocusedElement.text();
                                    $newFocusedElement.text('').text(text);
                                }
                            }

                            // Execute callback if provided
                            if (typeof callback === 'function') {
                                callback();
                            }
                        }
                    } else {
                        console.error('Error updating row calculations:', response.message);
                        // Fallback to page reload if AJAX fails

                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error updating row calculations');
                    console.error('Status:', status);
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    // Fallback to page reload if AJAX fails

                }
            });
        }

        // Function to focus on the next cell below
        function focusNextCellBelow(currentStudentId, currentAssessmentId) {
            // Try multiple times to find and focus the next cell
            var attempts = 0;
            var maxAttempts = 10;

            function tryFocus() {
                attempts++;
                var $currentRow = $('tr[data-student-id="' + currentStudentId + '"]');
                var $currentCell = $currentRow.find('.editable-mark[data-assessment-id="' + currentAssessmentId + '"]');

                if ($currentCell.length === 0) {
                    console.log('Current cell not found, attempt:', attempts);
                    if (attempts < maxAttempts) {
                        setTimeout(tryFocus, 50);
                    }
                    return;
                }

                // Find the next row
                var $nextRow = $currentRow.next('tr');

                if ($nextRow.length > 0) {
                    // Look for the cell with the same assessment_id in the next row
                    var $nextCell = $nextRow.find('.editable-mark[data-assessment-id="' + currentAssessmentId + '"]');

                    if ($nextCell.length > 0) {
                        $nextCell.focus();
                        // Select all text in the next cell
                        var range = document.createRange();
                        var sel = window.getSelection();
                        range.selectNodeContents($nextCell[0]);
                        sel.removeAllRanges();
                        sel.addRange(range);
                        console.log('Successfully focused on next cell with assessment_id:', currentAssessmentId);
                    } else {
                        console.log('Next cell not found with assessment_id:', currentAssessmentId);
                        // Fallback: try to find by column index
                        var currentColumnIndex = $currentCell.closest('td').index();
                        var $fallbackCell = $nextRow.find('td').eq(currentColumnIndex).find('.editable-mark');
                        if ($fallbackCell.length > 0) {
                            $fallbackCell.focus();
                            var range = document.createRange();
                            var sel = window.getSelection();
                            range.selectNodeContents($fallbackCell[0]);
                            sel.removeAllRanges();
                            sel.addRange(range);
                            console.log('Successfully focused using fallback column index:', currentColumnIndex);
                        } else {
                            console.log('Fallback also failed - no editable cell found in column:', currentColumnIndex);
                        }
                    }
                } else {
                    console.log('No next row found');
                }
            }

            // Start trying to focus
            setTimeout(tryFocus, 50);
        }

        // Handle mark editing
        $(document).on('blur', '.editable-mark', function() {
            var $cell = $(this);

            // Clear current editing cell
            if ($currentEditingCell && $currentEditingCell.is($cell)) {
                $currentEditingCell = null;
            }

            // Only proceed with automatic focus if this was triggered by Enter key
            if (!isAutomaticFocus) {
                return;
            }

            // Reset the flag
            isAutomaticFocus = false;

            var studentId = $cell.data('student-id');
            var assessmentId = $cell.data('assessment-id');
            var classId = $cell.data('class-id');
            var quarter = $cell.data('quarter');
            var mark = $cell.text().trim();

            // Blank is allowed: it clears the stored score instead of saving 0.
            if (mark === '') {
                $cell.text('');
                updateMark(studentId, assessmentId, classId, quarter, '');
                return;
            }

            // Validate mark is a number
            if (isNaN(mark)) {
                showErrorTooltip($cell, 'Please enter a valid number');
                return;
            }

            // Convert to float and check against HPS
            mark = parseFloat(mark);
            var assessmentId = $cell.data('assessment-id');
            var hps = assessmentHPS[assessmentId];

            if (hps && mark > hps) {
                showErrorTooltip($cell, 'Score cannot exceed HPS: ' + hps);
                // Reset to previous valid value or 0
                $cell.text('');
                return;
            }

            // Round to 2 decimal places
            mark = mark.toFixed(2);

            // Update the cell with formatted number
            $cell.text(mark);

            // Call the update function
            updateMark(studentId, assessmentId, classId, quarter, mark);
        });

        // Handle mouse click on editable cells
        $(document).on('mousedown', '.editable-mark', function(e) {
            var $clickedCell = $(this);

            // If we're currently editing a different cell, save it first
            if ($currentEditingCell && !$currentEditingCell.is($clickedCell)) {
                e.preventDefault(); // Prevent default focus behavior

                // Clear current editing cell and let blur handle the save
                $currentEditingCell = null;

                // Focus the clicked cell after a short delay to let blur complete
                setTimeout(function() {
                    $clickedCell.focus();
                }, 50);
            } else {
                // Track this cell as the current editing cell
                $currentEditingCell = $clickedCell;
            }
        });

        // Function to update mark and then focus a specific cell
        function updateMarkAndFocusCell(studentId, assessmentId, classId, quarter, mark, $targetCell) {
            $.ajax({
                url: 'update_mark.php',
                type: 'POST',
                data: {
                    student_id: studentId,
                    assessment_id: assessmentId,
                    class_id: classId,
                    quarter: quarter,
                    mark: mark
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        console.log('Mark updated successfully');
                        // Update calculations dynamically and then focus target cell
                        updateRowCalculationsAndFocus(studentId, classId, quarter, $targetCell);
                    } else {
                        alert('Error updating mark: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error updating mark. Please try again.');
                    window.location.reload();
                }
            });
        }

        // Function to update row calculations and focus a specific cell
        function updateRowCalculationsAndFocus(studentId, classId, quarter, $targetCell) {
            console.log('Updating row calculations for student:', studentId, 'class:', classId, 'quarter:', quarter);

            // Check if this is active quarters view
            var is_active_quarters = window.location.search.includes('quarter=active');

            if (is_active_quarters) {
                console.log('Active quarters view detected - using specialized update with focus');
                // For active quarters, use the same approach as updateRowCalculations but with focus
                $.ajax({
                    url: 'get_student_row_data.php',
                    type: 'POST',
                    data: {
                        student_id: studentId,
                        class_id: classId,
                        quarter: 'active' // Special parameter for active quarters
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('Active quarters AJAX response for focus:', response);
                        if (response.status === 'success') {
                            // Update the row with new data
                            var $row = $('tr[data-student-id="' + studentId + '"]');
                            console.log('Found row:', $row.length > 0);
                            if ($row.length > 0 && response.row_html) {
                                $row.replaceWith(response.row_html);
                                console.log('Active quarters row updated successfully');

                                // Apply status class if provided in response
                                if (response.student_status) {
                                    var $newRow = $('tr[data-student-id="' + studentId + '"]');
                                    $newRow.removeClass('status-dropped status-nosub status-noexam');
                                    if (response.student_status === 'Dropped') {
                                        $newRow.addClass('status-dropped');
                                    } else if (response.student_status === 'NoSub') {
                                        $newRow.addClass('status-nosub');
                                    } else if (response.student_status === 'NoExam') {
                                        $newRow.addClass('status-noexam');
                                    }
                                }

                                // Focus the target cell after row replacement with delay to ensure DOM is ready
                                setTimeout(function() {
                                    var targetAssessmentId = $targetCell.data('assessment-id');
                                    var targetQuarter = $targetCell.data('quarter');
                                    var $newTargetCell;

                                    if (targetQuarter) {
                                        // For active quarters, find by both assessment-id and quarter
                                        $newTargetCell = $('tr[data-student-id="' + studentId + '"]').find('[data-assessment-id="' + targetAssessmentId + '"][data-quarter="' + targetQuarter + '"]');
                                    } else {
                                        // For single quarter, find by assessment-id only
                                        $newTargetCell = $('tr[data-student-id="' + studentId + '"]').find('[data-assessment-id="' + targetAssessmentId + '"]');
                                    }

                                    if ($newTargetCell.length > 0) {
                                        $newTargetCell.focus();
                                        console.log('Successfully focused on target cell after row replacement');
                                    } else {
                                        console.log('Target cell not found after row replacement, trying fallback');
                                        // Fallback: try to focus on original target cell
                                        $targetCell.focus();
                                    }
                                }, 100); // Small delay to ensure DOM is updated
                            }
                        }
                    },
                    error: function() {
                        console.error('AJAX error for active quarters - focusing target cell directly');
                        // Fallback: just focus the target cell
                        $targetCell.focus();
                    }
                });
                return;
            }

            // Original single quarter logic
            $.ajax({
                url: 'get_student_row_data.php',
                type: 'POST',
                data: {
                    student_id: studentId,
                    class_id: classId,
                    quarter: quarter
                },
                dataType: 'json',
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.status === 'success') {
                        // Update the row with new data
                        var $row = $('tr[data-student-id="' + studentId + '"]');
                        console.log('Found row:', $row.length > 0);
                        if ($row.length > 0 && response.row_html) {
                            $row.replaceWith(response.row_html);
                            console.log('Row updated successfully');

                            // Focus the target cell after row update
                            setTimeout(function() {
                                focusSpecificCell($targetCell);
                            }, 100);
                        }
                    } else {
                        console.error('Error updating row:', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    alert('Error updating row calculations. Please try again.');
                }
            });
        }

        // Function to focus a specific cell
        function focusSpecificCell($targetCell) {
            // Find the cell again in case it was replaced during row update
            var targetStudentId = $targetCell.data('student-id');
            var targetAssessmentId = $targetCell.data('assessment-id');

            var $updatedCell = $('tr[data-student-id="' + targetStudentId + '"] .editable-mark[data-assessment-id="' + targetAssessmentId + '"]');

            if ($updatedCell.length > 0) {
                $updatedCell.focus();
                // Select all text in the cell
                var range = document.createRange();
                var sel = window.getSelection();
                range.selectNodeContents($updatedCell[0]);
                sel.removeAllRanges();
                sel.addRange(range);
                console.log('Successfully focused on target cell');

                // Update current editing cell
                $currentEditingCell = $updatedCell;
            } else {
                console.log('Target cell not found after row update');
            }
        }

        // Prevent newlines in editable cells — Enter or Tab saves the mark
        // and refreshes the row (totals / initial / transmuted). Fast-entry
        // mode is handled above and suppresses this path via
        // stopImmediatePropagation.
        $(document).on('keydown', '.editable-mark', function(e) {
            var isEnter = e.key === 'Enter' || e.keyCode === 13;
            var isTab = e.key === 'Tab' || e.keyCode === 9;
            if (!isEnter && !isTab) return;
            e.preventDefault();
            var $cell = $(this);
            var studentId = $cell.data('student-id');
            var assessmentId = $cell.data('assessment-id');

            // Track this cell as current editing
            $currentEditingCell = $cell;

            // Set flag to indicate this is an automatic focus change
            isAutomaticFocus = true;

            // Blur current cell to save changes and move to next cell
            $cell.blur();

            // Focus on next cell below after a short delay to allow row update
            setTimeout(function() {
                focusNextCellBelow(studentId, assessmentId);
            }, 300);
        });

        // Real-time validation while typing
        $(document).on('input', '.editable-mark', function() {
            var $cell = $(this);
            var currentValue = $cell.text().trim();
            var assessmentId = $cell.data('assessment-id');
            var hps = assessmentHPS[assessmentId];

            // Remove any existing error styling
            $cell.removeClass('error');
            $('.error-tooltip').remove();

            // Only validate if there's a value and it's a complete number
            if (currentValue !== '' && !isNaN(currentValue)) {
                var mark = parseFloat(currentValue);

                // Check against HPS
                if (hps && mark > hps) {
                    showErrorTooltip($cell, 'Score cannot exceed HPS: ' + hps);
                }
            }
        });

        // Handle input to only accept numbers
        $(document).on('input', '.editable-mark', function(e) {
            var $this = $(this);
            var currentValue = $this.text();

            // Remove any non-numeric characters except decimal point
            var numericValue = currentValue.replace(/[^0-9.]/g, '');

            // Ensure only one decimal point
            var parts = numericValue.split('.');
            if (parts.length > 2) {
                numericValue = parts[0] + '.' + parts.slice(1).join('');
            }

            // Update the cell content if it changed
            if (currentValue !== numericValue) {
                $this.text(numericValue);

                // Position cursor at the end after cleaning
                var range = document.createRange();
                var sel = window.getSelection();
                var textNode = $this[0].childNodes[0];
                if (textNode && textNode.nodeType === Node.TEXT_NODE) {
                    range.setStart(textNode, numericValue.length);
                    range.setEnd(textNode, numericValue.length);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }
        });

        // Handle blur event - a cleared cell is left blank on purpose
        // (it means "not yet graded", not a score of 0). The save path
        // sends an empty mark, which removes the stored row.
        $(document).on('blur', '.editable-mark', function() {
            var $this = $(this);
            var currentValue = $this.text().trim();

            if (currentValue === '') {
                $this.text('');
            }
        });

        // Track when cells gain focus
        $(document).on('focus', '.editable-mark', function() {
            $currentEditingCell = $(this);

            // Select the existing value so typing replaces it.
            // Do NOT auto-clear a stored 0: because blur now leaves blanks
            // alone, clearing here would silently erase a genuine zero score.
            var range = document.createRange();
            range.selectNodeContents(this);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        });

        $(function(){
            // Initialize modal
            var signatoryModal = new bootstrap.Modal(document.getElementById('signatoryModal'));

            // Handle signatory modal open
            $('#addSignatory').click(function(){
                // Reset form
                $('#signatoryForm')[0].reset();
                signatoryModal.show();
            });

            // Handle signatory type change to populate fields with existing data
            $('#signatoryType').change(function(){
                var selectedType = $(this).val();

                // Get existing signatories data from the page
                var signatories = {};
                $('.col-md-4.text-center').each(function() {
                    var title = $(this).find('h6').text().replace(':', '').trim();
                    var type = '';

                    // Determine the type based on title
                    if (title.includes('CERTIFIED')) type = 'certified';
                    else if (title.includes('CHECKED')) type = 'checked';
                    else if (title.includes('NOTED')) type = 'noted';
                    else if (title.includes('RECEIVED')) type = 'received';

                    if (type) {
                        // Get the name from the border-bottom div
                        var name = $(this).find('div[style*="border-bottom"] > div').first().text().trim();

                        // Get the designation from the .fw-bold element
                        var designation = $(this).find('.fw-bold').first().text().trim();

                        // Clean up the designation text
                        designation = designation.replace(/\s+/g, ' ').trim();

                        // If we couldn't find a designation or it's the default text, use empty string
                        if (!designation || designation === 'Designation') {
                            designation = '';
                        }

                        // If we couldn't find a name, use empty string
                        if (!name) {
                            name = '';
                        }

                        signatories[type] = {
                            fullname: name,
                            designation: designation
                        };
                    }
                });

                // Populate fields if signatory data exists for selected type
                if (signatories[selectedType]) {
                    $('#fullName').val(signatories[selectedType].fullname);
                    $('#designation').val(signatories[selectedType].designation);
                } else {
                    // Clear fields if no existing data
                    $('#fullName').val('');
                    $('#designation').val('');
                }
            });

            // Handle save signatory
            $('#saveSignatory').click(function(){
                var $btn = $(this);
                var fullName = $('#fullName').val().trim();
                var designation = $('#designation').val().trim();
                var signatoryType = $('#signatoryType').val();

                // Basic validation
                if(!designation) {
                    alert("Please fill in all required fields.");
                    return false;
                }

                // Show loading state
                var originalText = $btn.html();
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

                // Send AJAX request
                $.ajax({
                    url: 'Actions.php?a=save_signatory',
                    method: 'POST',
                    data: {
                        fullname: fullName,
                        designation: designation,
                        type: signatoryType
                    },
                    dataType: 'json',
                    success: function(resp) {
                        if(resp.status === 'success') {
                            // Show success message
                            alert("Signatory saved successfully!");
                            // Close modal and reload page
                            signatoryModal.hide();
                            location.reload();
                        } else {
                            // Show error message
                            alert(resp.msg || "An error occurred while saving the signatory.");
                            // Re-enable button
                            $btn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Error:", xhr.responseText);
                        alert("An error occurred while saving the signatory. Please check the console for details.");
                        // Re-enable button
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });

            $('#signatoryForm input, #signatoryForm select').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault(); // Prevent default form submission
                    $('#saveSignatory').click(); // Trigger save button click
                }
            });

            // Close modal handler to reset form
            $('#signatoryModal').on('hidden.bs.modal', function () {
                $('#signatoryForm')[0].reset();
            });

            function loadReport(classId, quarter) {
                if(!classId || !quarter){
                    alert("Please select quarter, subject, and section first.");
                    return;
                }
                location.href = "./?page=reports&class_id=" + encodeURIComponent(classId) + "&quarter=" + encodeURIComponent(quarter);
            }

            // Reads a quarter list off a tab. `quarters` holds quarter NUMBERS
            // (legacy / assessment-based); `quarterNames` holds the quarter
            // NAMES from quarter_list, which is what the dropdown carries now.
            function parseTabQuarterAttr($tab, dataKey) {
                var availableQuarters = $tab.data(dataKey);

                if (typeof availableQuarters === 'string') {
                    try {
                        availableQuarters = JSON.parse(availableQuarters);
                    } catch (e) {
                        availableQuarters = [];
                    }
                }

                return Array.isArray(availableQuarters) ? availableQuarters.map(String) : [];
            }

            function parseTabQuarters($tab) {
                return parseTabQuarterAttr($tab, 'quarters');
            }

            function parseTabQuarterNames($tab) {
                return parseTabQuarterAttr($tab, 'quarterNames');
            }

            function tabMatchesQuarter($tab, quarter) {
                if (!quarter) {
                    return false;
                }

                if (quarter === 'active') {
                    // "Active Quarters" means quarters that actually contain
                    // assessments, so a class whose custom quarters are still
                    // empty stays hidden here - it is still reachable through its
                    // own custom quarter though.
                    return parseTabQuarterAttr($tab, 'assessmentQuarters').length > 0;
                }

                // Numeric quarter (legacy URL): match by number as before.
                if (/^\d+$/.test(String(quarter))) {
                    return parseTabQuarters($tab).includes(String(quarter));
                }

                // Quarter name: match against the tab's quarter names,
                // case-insensitively (same rule as quarter creation).
                var want = String(quarter).toLowerCase();
                var names = parseTabQuarterNames($tab);
                for (var i = 0; i < names.length; i++) {
                    if (String(names[i]).toLowerCase() === want) {
                        return true;
                    }
                }
                return false;
            }

            function updateSubjectTabs() {
                var quarter = $('#quarter').val();
                var selectedSubjectId = String($('#subject_id').val() || '');
                var visibleSubjectIds = [];

                if (!quarter) {
                    $('#subjectTabsWrapper, #sectionTabsWrapper').addClass('d-none');
                    $('#subjectTabsEmptyState, #sectionTabsEmptyState').addClass('d-none');
                    $('.report-subject-tab, .report-section-tab').removeClass('active');
                    $('#subject_id, #class_id').val('');
                    return { visibleSubjectIds: [], selectedSubjectVisible: false };
                }

                $('.report-subject-tab').each(function(){
                    var $tab = $(this);
                    var isVisible = tabMatchesQuarter($tab, quarter);
                    var subjectId = String($tab.data('subject-id'));

                    $tab.toggleClass('d-none', !isVisible);

                    if (isVisible) {
                        visibleSubjectIds.push(subjectId);
                    } else if (subjectId === selectedSubjectId) {
                        $tab.removeClass('active');
                    }
                });

                var selectedSubjectVisible = visibleSubjectIds.includes(selectedSubjectId);
                $('#subjectTabsWrapper').removeClass('d-none');
                $('#subjectTabsEmptyState').toggleClass('d-none', visibleSubjectIds.length > 0);
                $('#sectionTabsWrapper').addClass('d-none');
                $('#sectionTabsEmptyState').addClass('d-none');

                if (!selectedSubjectVisible) {
                    $('#subject_id').val('');
                    $('#class_id').val('');
                }

                return {
                    visibleSubjectIds: visibleSubjectIds,
                    selectedSubjectVisible: selectedSubjectVisible
                };
            }

            function updateSectionTabs() {
                var quarter = $('#quarter').val();
                var selectedSubjectId = String($('#subject_id').val() || '');
                var selectedClassId = String($('#class_id').val() || '');
                var visibleSectionIds = [];

                if (!quarter || !selectedSubjectId) {
                    $('#sectionTabsWrapper').addClass('d-none');
                    $('#sectionTabsEmptyState').addClass('d-none');
                    $('.report-section-tab').removeClass('active');
                    $('#class_id').val('');
                    return { visibleSectionIds: [], selectedSectionVisible: false };
                }

                $('.report-section-tab').each(function(){
                    var $tab = $(this);
                    var subjectId = String($tab.data('subject-id'));
                    var classId = String($tab.data('class-id'));
                    var isVisible = subjectId === selectedSubjectId && tabMatchesQuarter($tab, quarter);

                    $tab.toggleClass('d-none', !isVisible);

                    if (isVisible) {
                        visibleSectionIds.push(classId);
                    } else if (classId === selectedClassId) {
                        $tab.removeClass('active');
                    }
                });

                var selectedSectionVisible = visibleSectionIds.includes(selectedClassId);
                $('#sectionTabsWrapper').removeClass('d-none');
                $('#sectionTabsEmptyState').toggleClass('d-none', visibleSectionIds.length > 0);

                if (!selectedSectionVisible) {
                    $('#class_id').val('');
                }

                return {
                    visibleSectionIds: visibleSectionIds,
                    selectedSectionVisible: selectedSectionVisible
                };
            }

            function activateSubject(subjectId) {
                $('#subject_id').val(subjectId);
                $('.report-subject-tab').removeClass('active');
                $('.report-subject-tab[data-subject-id="' + subjectId + '"]').addClass('active');
            }

            function activateSection(classId) {
                $('#class_id').val(classId);
                $('.report-section-tab').removeClass('active');
                $('.report-section-tab[data-class-id="' + classId + '"]').addClass('active');
                // Refresh the dropdown with this class's own quarter names.
                if (typeof window.applyReportQuarterOptions === 'function') {
                    window.applyReportQuarterOptions(classId, function (names) {
                        // The quarter we were on may not exist for the class just
                        // selected (names differ per class). Fall back to its
                        // first quarter name instead of leaving a value selected
                        // that hides every tab.
                        var cur = String($('#quarter').val() || '');
                        if (cur === '' || cur === 'active' || !names || !names.length) {
                            return;
                        }
                        var curLower = cur.toLowerCase();
                        for (var i = 0; i < names.length; i++) {
                            if (String(names[i]).toLowerCase() === curLower) {
                                return;
                            }
                        }
                        $('#quarter').val(String(names[0]));
                        initializeTabFlow(false);
                    });
                }
            }

            function initializeTabFlow(autoLoadFirstSection) {
                var quarter = $('#quarter').val();
                var subjectResult = updateSubjectTabs();
                var currentSubjectId = String($('#subject_id').val() || '');

                if (!subjectResult.selectedSubjectVisible && subjectResult.visibleSubjectIds.length > 0) {
                    currentSubjectId = subjectResult.visibleSubjectIds[0];
                    activateSubject(currentSubjectId);
                }

                var sectionResult = updateSectionTabs();
                var currentClassId = String($('#class_id').val() || '');

                if (!sectionResult.selectedSectionVisible && sectionResult.visibleSectionIds.length > 0) {
                    currentClassId = sectionResult.visibleSectionIds[0];
                    activateSection(currentClassId);
                }

                if (autoLoadFirstSection && quarter && currentClassId) {
                    loadReport(currentClassId, quarter);
                }
            }

            initializeTabFlow(false);

            // Ensure the dropdown reflects the custom quarter names of the class
            // currently loaded (URL) or auto-picked by the tab flow above. When the
            // URL has no class (fresh landing from the nav menu), fall back to the
            // last class used - so renames made in Marks show up right away.
            if (typeof window.applyReportQuarterOptions === 'function') {
                var rqInitClass = $('#class_id').val();
                if (!rqInitClass) {
                    try { rqInitClass = localStorage.getItem('rq_last_class_id') || ''; } catch (e) { rqInitClass = ''; }
                }
                window.applyReportQuarterOptions(rqInitClass);
            }

            $('#quarter').on('change', function(){
                initializeTabFlow(true);
            });

            $(document).on('click', '.report-subject-tab', function(){
                var quarter = $('#quarter').val();
                if(!quarter){
                    alert("Please select quarter first.");
                    return;
                }

                var subjectId = String($(this).data('subject-id'));
                activateSubject(subjectId);

                var sectionResult = updateSectionTabs();
                if (sectionResult.visibleSectionIds.length > 0) {
                    activateSection(sectionResult.visibleSectionIds[0]);
                    return;
                }

                // This subject has no section in the quarter that is selected.
                // Move to the first quarter name this subject really has, then
                // re-run the flow.
                var subjectNames = parseTabQuarterNames($('.report-subject-tab[data-subject-id="' + subjectId + '"]'));
                if (subjectNames.length > 0) {
                    // The dropdown may currently be scoped to the class we came
                    // from and not offer this name, so restore the full list
                    // before selecting it.
                    if (typeof window.renderReportQuarterAll === 'function') {
                        window.renderReportQuarterAll();
                    }
                    $('#quarter').val(subjectNames[0]);
                    initializeTabFlow(false);
                }
            });

            $(document).on('click', '.report-section-tab', function(){
                var quarter = $('#quarter').val();
                var classId = $(this).data('class-id');

                if(!quarter){
                    alert("Please select quarter first.");
                    return;
                }

                activateSection(String(classId));
                loadReport(classId, quarter);
            });

            $('#filter').click(function(){
                var class_id = $('#class_id').val();
                var quarter = $('#quarter').val();
                loadReport(class_id, quarter);
            });

            $('#transmuted_table').click(function(){
                uni_modal('Transmutation Table', 'transmutation_table.php', 'mid-large');
            });

            $('#print').click(function(){
                // Create a new window for printing
                var printWindow = window.open('', '_blank');

                // Get the current date
                var currentDate = new Date().toLocaleDateString();

                // Get the report title and class info
                var title = "CLASS RECORDS";
                var classInfo = $('.report-section-tab.active').text().trim();
                var quarter = $('#quarter option:selected').text();
                var subject = $('.report-subject-tab.active').text().trim();

                // Get the signatories data first
                var signatories = {};
                $('.col-md-4.text-center').each(function() {
                    var title = $(this).find('h6').text().replace(':', '').trim();
                    var type = '';

                    // Determine the type based on title
                    if (title.includes('CERTIFIED')) type = 'certified';
                    else if (title.includes('CHECKED')) type = 'checked';
                    else if (title.includes('NOTED')) type = 'noted';
                    else if (title.includes('RECEIVED')) type = 'received';

                    if (type) {
                        // Get the name from the border-bottom div
                        var name = $(this).find('div[style*="border-bottom"] > div').first().text().trim();

                        // Get the designation from the .fw-bold element
                        var designation = $(this).find('.fw-bold').first().text().trim();

                        // Clean up the designation text (remove any extra whitespace or newlines)
                        designation = designation.replace(/\s+/g, ' ').trim();

                        // If we couldn't find a designation, use a default
                        if (!designation || designation === 'Designation') {
                            designation = 'Designation'; // Default text if not found
                        }

                        signatories[type] = {
                            fullname: name,
                            designation: designation
                        };

                        console.log('Signatory:', type, name, designation); // Debug log
                    }
                });

                // Get the table HTML and fix any issues with components
                var tableHTML = $('#outprint').clone();

                // Strip interactive editing UI so + buttons, trash icons and
                // any open editor cells never appear on print output
                tableHTML.find('.rq-add-assess-btn, .rq-del-assess-btn').remove();
                tableHTML.find('th.rq-inline-ed, th.rq-cell-ed').remove();
                tableHTML.find('td.rq-initial-hidden').remove();

                // Ensure all components are visible in the print view
                tableHTML.find('th, td').css('display', '');
                tableHTML.find('.table').addClass('table-print');

                // The on-screen wrapper carries inline max-height:80vh for scrolling.
                // In the print window there is no Bootstrap to contain it, so rows
                // taller than 80vh overflow visibly on top of the signatories.
                // Strip those constraints so the table paginates naturally.
                tableHTML.find('.table-responsive, .freeze-panes-container').css({
                    'max-height': 'none',
                    'height': 'auto',
                    'overflow': 'visible',
                    'overflow-x': 'visible',
                    'overflow-y': 'visible'
                });
                tableHTML.find('.table-header, thead, th.sticky-col, td.sticky-col, .sticky-col').css({
                    'position': 'static',
                    'left': 'auto',
                    'top': 'auto',
                    'box-shadow': 'none'
                });

                // Build the signatories HTML so it renders right after the grades
                // table in the print output (previously the print window never
                // included the signatories section at all). "Received By" is
                // intentionally excluded from print — on-screen only.
                var printSignatoryTypes = [
                    { type: 'certified', title: 'CERTIFIED TRUE AND CORRECT:' },
                    { type: 'checked', title: 'CHECKED BY:' },
                    { type: 'noted', title: 'NOTED BY:' }
                ];
                var printActiveSignatories = printSignatoryTypes.filter(function(s){
                    return signatories[s.type] && signatories[s.type].fullname && String(signatories[s.type].fullname).trim() !== '';
                });
                var signatoriesHTML = '';
                if (printActiveSignatories.length > 0) {
                    var printCellWidth = (100 / printActiveSignatories.length).toFixed(2);
                    var printSignatoryCells = printActiveSignatories.map(function(s){
                        return '<td style="width: ' + printCellWidth + '%; padding: 10px; vertical-align: top;">' +
                            '<h6 style="margin: 0 0 15px 0; text-align: left; font-size: 9pt; font-weight: bold;">' + s.title + '</h6>' +
                            '<div style="margin-bottom: 20px;">' +
                                '<div style="border-bottom: 1px solid #000; text-align: center; width: 90%; height: 50px; margin: 0 auto; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px;">' +
                                    signatories[s.type].fullname +
                                '</div>' +
                                '<div style="margin-top: 10px; text-align: center; font-weight: bold; font-size: 9pt;">' + (signatories[s.type].designation || 'Designation') + '</div>' +
                                '<div style="font-size: 8pt; text-align: center; color: #666;">Signature Over Printed Name</div>' +
                            '</div>' +
                        '</td>';
                    }).join('');
                    signatoriesHTML = '<div class="print-signatories" style="width: 100%; padding: 15px 0 0 0; background: #fff; page-break-inside: avoid; break-inside: avoid; clear: both; margin-top: 30px;">' +
                        '<table style="width: 100%; border-collapse: collapse; margin: 0 auto;">' +
                            '<tr>' + printSignatoryCells + '</tr>' +
                        '</table>' +
                    '</div>';
                }

                // Create the print HTML with proper styling
                var printHTML = `
                <!DOCTYPE html>
                <html>
                <head>

                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>

                        @page {
                            size: 8.5in 13in portrait; /* Folio */
                            margin: 0.5in !important;
                            padding: 0;
                        }

                        body {
                            margin: 0 !important;
                            padding: 0 !important;
                            font-family: Arial, sans-serif;
                            font-size: 9pt;
                            color: #000;
                            background: #fff;
                        }
                        .card-header, .btn, .rq-add-assess-btn, .rq-del-assess-btn, #transmuted_table, .no-print, .dataTables_info, .dataTables_length, .dataTables_filter, .dataTables_paginate {
                            display: none !important;
                        }
                        /* Cloned scroll wrappers must not cap height in print -
                           otherwise overflow rows paint over the signatories */
                        #outprint,
                        .table-responsive,
                        .freeze-panes-container,
                        .grades-table-container {
                            overflow: visible !important;
                            max-height: none !important;
                            height: auto !important;
                        }
                        .table-print {
                            width: 100% !important;
                            max-width: 100% !important;
                            margin: 0 0 30px 0 !important;
                            height: auto !important;
                            max-height: none !important;
                            overflow: visible !important;
                            font-size: 9pt !important;
                            border-collapse: collapse !important;
                            border: 1px solid #000 !important;
                            table-layout: fixed !important;
                            word-wrap: break-word;
                            box-sizing: border-box !important;
                            transform: none !important;
                        }
                        .table-print th, .table-print td {
                            padding: 2px 3px !important;
                            line-height: 1.1;
                            white-space: normal !important;
                            word-break: break-all;
                            overflow-wrap: break-word;
                            border: 1px solid #000 !important;
                            width: auto !important;
                            min-width: 30px !important;
                            max-width: none !important;
                            vertical-align: center !important;
                            box-sizing: border-box;
                            font-size: 7pt !important; /* Consistent font size */
                        }
                        /* Student column fixed width */
                        .table-print th:first-child,
                        .table-print td:first-child {
                        width: 180px !important;
                            min-width: 180px !important;
                            max-width: 180px !important;
                            white-space: normal;
                            align-content: center !important;
                            font-size:9px !important;
                        }
                        /* Grade columns fixed width - Dynamic for quarter grades */
                        .table-print th[class*="Grade"],
                        .table-print td[class*="Grade"] {
                            width: 40px !important;
                            min-width: 40px !important;
                            max-width: 40px !important;
                            text-align: center !important;
                            white-space: normal;
                            align-content: center !important;
                        }
                        .table-print th:nth-last-child(2),
                        .table-print td:nth-last-child(2) {
                            width: 40px !important;
                            min-width: 40px !important;
                            max-width: 40px !important;
                            text-align: center !important;
                            white-space: normal;
                            align-content: center !important;
                        }
                        .table-print th:nth-last-child(1),
                        .table-print td:nth-last-child(1) {
                            width: 40px !important;
                            min-width: 40px !important;
                            max-width: 40px !important;
                            text-align: center !important;
                            white-space: normal;
                            align-content: center !important;
                        }
                        .table-print th {
                            font-size: 6pt !important; /* Slightly larger for headers */
                            background-color: #1e293b !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                            font-weight: bold;
                            color: #fff !important;
                        }
                        /* --- Professional rq hierarchy in print popup --- */
                        .table-print.rq-grades thead tr.rq-row-main th {
                            background-color: #1e293b !important;
                            color: #f8fafc !important;
                            font-size: 6pt !important;
                            text-transform: uppercase;
                            letter-spacing: .04em;
                        }
                        .table-print.rq-grades thead tr.rq-row-main th.rq-h-student {
                            background-color: #0f172a !important;
                            text-align: center !important;
                        }
                        .table-print.rq-grades thead tr.rq-row-quarter th {
                            background-color: #f1f5f9 !important;
                            color: #475569 !important;
                            font-size: 6pt !important;
                            text-transform: uppercase;
                        }
                        .table-print.rq-grades thead th.rq-h-assess,
                        .table-print.rq-grades thead th.rq-h-hps,
                        .table-print.rq-grades thead th.rq-h-none {
                            background-color: #ffffff !important;
                            color: #0f172a !important;
                        }
                        .table-print.rq-grades thead th.rq-h-total,
                        .table-print.rq-grades thead th.rq-h-total-val {
                            background-color: #dbe2ec !important;
                            color: #334155 !important;
                        }
                        .table-print.rq-grades thead th.rq-h-pct,
                        .table-print.rq-grades thead th.rq-h-pct-val {
                            background-color: #cbd5e1 !important;
                            color: #1e293b !important;
                        }
                        .table-print.rq-grades tbody td.rq-total {
                            background-color: #e2e8f0 !important;
                            font-weight: bold !important;
                        }
                        .table-print.rq-grades tbody td.rq-weighted {
                            background-color: #d7dfea !important;
                            font-weight: bold !important;
                            color: #1e293b !important;
                        }
                        .table-print.rq-grades tbody td.rq-initial-cell {
                            background-color: #f8fafc !important;
                            color: #0f172a !important;
                            font-weight: bold !important;
                        }
                        .table-print.rq-grades tbody td.rq-grade-cell {
                            background-color: #ffffff !important;
                        }
                        .table-print.rq-grades .rq-pill {
                            display: inline-block !important;
                            border: 1px solid #cbd5e1 !important;
                            border-radius: 999px !important;
                            padding: 1px 6px !important;
                            font-weight: bold !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print.rq-grades .rq-comp-pct {
                            border: 1px solid rgba(255,255,255,.4) !important;
                            border-radius: 999px !important;
                            padding: 0 5px !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print.rq-grades td.rq-student {
                            text-align: left !important;
                            font-weight: bold !important;
                        }
                            .table-print    .text-truncate {
                                font-size: 6pt !important;

                            }
                                .table-print .initial-grade-header {
                                    width: 25px !important;
                                    min-width: 25px !important;
                                    max-width: 25px !important;
                                }
                                .table-print .quarter-grade-header {
                                    width: 25px !important;
                                    min-width: 25px !important;
                                    max-width: 25px !important;
                                }
                        .table-print .bg-dark {
                            background-color: #1e293b !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                            color: #fff !important;
                        }
                        .table-print .bg-warning {
                            background-color: #fef3c7 !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .bg-info {
                            background-color: #e2e8f0 !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .text-center {
                            text-align: center !important;
                            font-size: 6pt !important;

                        }
                        .table-print .text-danger {
                            color: #991b1b !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .text-warning {
                            color: #92400e !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .text-success {
                            color:#166534 !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .text-dark {
                            color: #1e293b !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .fw-bold {
                            font-weight: bold !important;
                        }
                        .table-print .text-light {
                            color: #fff !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .table-print .bg-opacity-50 {
                            opacity: 0.5 !important;
                        }

                        /* Sticky/frozen panes off in the print window - the clone
                           carries inline position:sticky which would pin cells
                           over continued rows on page 2 */
                        .table-print thead,
                        .table-print .table-header,
                        .table-print .sticky-col,
                        .table-print th.sticky-col,
                        .table-print td.sticky-col {
                            position: static !important;
                            left: auto !important;
                            top: auto !important;
                            box-shadow: none !important;
                            z-index: auto !important;
                        }

                        /* Signatories block rendered after the grades table */
                        .print-signatories {
                            page-break-inside: avoid !important;
                            break-inside: avoid !important;
                            page-break-before: auto !important;
                            clear: both !important;
                            width: 100% !important;
                            overflow: visible !important;
                        }
                        .print-signatories h6 {
                            font-weight: bold;
                            margin: 0 0 15px 0;
                        }

                        /* Signatures styling */
                        .signature-line {
                            border-bottom: 1px solid #000;
                            height: 30px;
                            margin: 0 auto 5px;
                            width: 100%;
                        }
                        .signature-label {
                            font-weight: bold;
                            font-size: 9pt;
                            margin: 5px 0;
                        }
                        .signature-note {
                            font-size: 9pt;
                            color: #666;
                        }

                        thead {
                            display: table-header-group;
                            break-inside: avoid;
                        }
                        tbody {
                            display: table-row-group;
                        }
                        tfoot {
                            display: table-footer-group;
                        }
                    .header-info {
                            margin-top: 0;
                                margin-bottom: 0px;
                                page-break-after: avoid;
                            }
                            .header-info h3 {
                                text-align: center;
                                margin: 5px 0;
                                font-size: 12pt;
                            }
                            .header-info div {
                                margin: 2px 0;
                                font-size: 9pt;
                            }

                        @media print {
                                @page {
                                    size: 8.5in 13in portrait; /* Folio */
                                    margin: 0.5in !important;
                                    padding: 0 !important;
                                }
                                html, body {
                                    margin: 0 !important;
                                    padding: 0 !important;
                                }
                                body {
                                    margin: 0;
                                    padding: 10px;
                                    font-size: 8pt;
                                }
                                .table-print {
                                    font-size: 9pt !important;
                                    width: 100% !important;
                                    max-width: 100% !important;
                                    height: auto !important;
                                    max-height: none !important;
                                    overflow: visible !important;
                                    margin-bottom: 30px !important;
                                    transform: scale(1) !important;
                                    transform-origin: 0 0 !important;
                                    table-layout: fixed !important;
                                }
                                /* Ensure signatories are visible in print */
                                .col-8.offset-2.mt-2 {
                                    display: block !important;
                                    page-break-before: auto;
                                    margin-top: 30px !important;
                                }
                                .col-md-4.text-center {
                                    display: block !important;
                                    page-break-inside: avoid;
                                    width: 33.33% !important;
                                    float: left !important;
                                }
                                .col-md-4.text-center h6 {
                                    font-size: 10pt !important;
                                    margin-bottom: 15px !important;
                                }
                                .col-md-4.text-center .mb-4 {
                                    margin-bottom: 20px !important;
                                }
                                .col-md-4.text-center .mb-1 {
                                    border-bottom: 1px solid #000 !important;
                                    width: 90% !important;
                                    height: 50px !important;
                                    margin: 0 auto !important;
                                }
                                .col-md-4.text-center .mt-2 {
                                    margin-top: 10px !important;
                                    font-weight: bold !important;
                                    font-size: 9pt !important;
                                }
                                .col-md-4.text-center .text-muted {
                                    font-size: 8pt !important;
                                    color: #666 !important;
                                }
                            }
                    </style>

                </head>
                <body style="margin: 0; padding: 0;">
                    <!-- Header -->
                    <table style="width: 100%; margin: 0;">
                        <div class="header-info">
                            <h3>${title}</h3>
                            <i style="font-size: 10pt; color:rgb(87, 87, 87); text-align: center; display: flex; justify-content: center; font-style: italic; font-family: Arial, sans-serif; margin-bottom: 10px;">
                                    <?php
                                    // Map quarter number to name (custom names supported).
                                    // $current_quarter is already the resolved number.
                                    $quarter_num = $current_quarter;
                                    $quarter_name = get_quarter_name($conn, $class_id, $quarter_num);
                                    echo htmlspecialchars($class_info['semester'] ?? 'N/A') . ', ' . htmlspecialchars($quarter_name) . ', School Year: ' . htmlspecialchars($class_info['year'] ?? 'N/A');
                                    ?>
                                    </i>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 20px; gap: 20px;">
                                <div style="flex: 1;">

                                    <table class="class-info-table" style="width: 40%; margin-top:10px; border-width: 1px; border-color: rgb(143, 143, 143);">
                                        <tr>
                                            <td style="width: 40%; padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><strong>Course Code:</strong>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><?php echo $class_info['course_no'] ?? 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><strong>Subject:</strong></td>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><?php echo $class_info['subject_name'] ?? 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><strong>Course & Section:</strong></td>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;">
                                                <?php
                                                if (isset($class_info['grade']) && isset($class_info['section'])) {
                                                    echo htmlspecialchars($class_info['grade'] . ' - ' . $class_info['section']);
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><strong>Schedule:</strong></td>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><?php echo htmlspecialchars($class_info['schedule'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><strong>Units:</strong></td>
                                            <td style="padding: 2px 4px; border: 1px solid rgb(143, 143, 143); font-size: 8pt;"><?php echo htmlspecialchars($class_info['unit'] ?? 'N/A'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div style="text-align: right; margin-top: 10px;">
                                <strong>Date Generated:</strong> ${currentDate}
                            </div>
                        </div>
                    </table>
                    <div style="margin-bottom: 10px !important; position:relative !important;border: 4px solid rgb(0, 0, 0) !important;">
                        ${tableHTML.html()}
                    </div>
                    <!-- Signatories rendered after the grades table -->
                    ${signatoriesHTML}




                <script>
                        window.onload = function() {
                            // Force show all components in the table
                            var tables = document.getElementsByClassName('table-print');
                            for (var i = 0; i < tables.length; i++) {
                                var table = tables[i];
                                var rows = table.getElementsByTagName('tr');
                                for (var j = 0; j < rows.length; j++) {
                                    rows[j].style.display = '';
                                    var cells = rows[j].getElementsByTagName('th');
                                    for (var k = 0; k < cells.length; k++) {
                                        cells[k].style.display = '';
                                    }
                                    cells = rows[j].getElementsByTagName('td');
                                    for (k = 0; k < cells.length; k++) {
                                        cells[k].style.display = '';
                                    }
                                }
                            }

                            // Add page numbers
                            var pageCount = 1;
                            var pageNumDivs = document.getElementsByClassName('pageNumber');
                            for (var i = 0; i < pageNumDivs.length; i++) {
                                pageNumDivs[i].textContent = pageCount;
                            }

                            // Print after a short delay to ensure everything is rendered
                            setTimeout(function() {
                                window.print();
                                window.onafterprint = function() {
                                    window.close();
                                };
                            }, 500);
                        };
                    <\/script>

                </body>
                </html>
                `;

                // Write content to the new window
                printWindow.document.open();
                printWindow.document.write(printHTML);
                printWindow.document.close();
            });

            // Export Report of Grades to Word via export_to_word.php
            // (server-side PhpWord, same as report_of_grades.php).
            // Uses the selected class + quarter (subject follows from the class).
            $('#exportWord').click(function(){
                var classId = String($('#class_id').val() || '');
                if(!classId) {
                    alert('Please select a class first.');
                    return false;
                }
                var quarter = String($('#quarter').val() || '');
                var url = 'export_to_word.php?export=grades&class_id=' + encodeURIComponent(classId);
                if (quarter !== '' && quarter !== 'active') {
                    url += '&quarter=' + encodeURIComponent(quarter);
                }
                window.location.href = url;
            });
            // High Impact Assessment Detection Loop
            function determineHighImpact() {
                $('.editable-mark').each(function() {
                    var $cell = $(this);
                    var mark = parseFloat($cell.text()) || 0;
                    var studentId = $cell.data('student-id');
                    var assessmentId = $cell.data('assessment-id');

                    // Get HPS for this assessment (from assessmentHPS array)
                    var hps = assessmentHPS[assessmentId] || 0;

                    if (hps > 0) {
                        var markPercentage = (mark / hps) * 100;
                        var componentWeight = 0; // This would need to be calculated based on component

                        // For demonstration, assume component weight from data attributes or calculate
                        // In a real implementation, you'd need to get the component weight

                        // Simple high impact detection based on mark percentage
                        var isHighImpact = false;
                        var markClass = '';

                        if (markPercentage < 75) {
                            // Low scores are considered high impact
                            if (mark === 0) {
                                markClass = 'text-danger fw-bold bg-danger bg-opacity-25';
                                isHighImpact = true;
                            } else if (markPercentage < 50) {
                                markClass = 'text-danger fw-bold bg-danger bg-opacity-25';
                                isHighImpact = true;
                            } else if (markPercentage < 75) {
                                markClass = 'text-dark fw-bold bg-warning bg-opacity-25';
                                isHighImpact = true;
                            }
                        }

                        // Apply styling based on impact
                        if (isHighImpact) {
                            $cell.removeClass('text-danger fw-bold bg-danger bg-opacity-25 text-dark fw-bold bg-warning bg-opacity-25')
                                  .addClass(markClass);
                        } else {
                            // Remove high impact styling if not applicable
                            $cell.removeClass('text-danger fw-bold bg-danger bg-opacity-25 text-dark fw-bold bg-warning bg-opacity-25');
                        }
                    }
                });
            }

            // Start the high impact detection loop (runs every 1 second)
            setInterval(function() {
                determineHighImpact();
            }, 100);

            // Also run immediately on page load
            determineHighImpact();
        });
    </script>

    <!-- Bulk Set Range for the grades grid (needs assessmentHPS,
         updateRowCalculations, filterTable and rqToast from the block above) -->
    <script src="js/rq_bulk_range.js"></script>

    <script>
        // Page shell behaviour: collapsible Bulk Set Range panel + auto-closing
        // Output dropdown. Kept separate from rq_bulk_range.js so that file
        // stays focused on the range logic.
        (function () {
            'use strict';

            $(function () {
                var $panel = $('#rqBulkPanel');
                var $toggle = $('#rqBulkToggle');
                if (!$panel.length || !$toggle.length) return;

                $toggle.on('click', function () {
                    var open = !$panel.hasClass('is-open');
                    $panel.toggleClass('is-open', open);
                    $toggle.attr('aria-expanded', open ? 'true' : 'false');
                    $toggle.toggleClass('rq-tool-btn-active', open);
                    if (open) {
                        // The panel's status line is the only hint about blank
                        // From/To, so surface it on open.
                        $('#rqBulkStatus').attr('title',
                            'Leave To blank to clear the matching scores, or From blank to target the blank ones.');
                    }
                });

                // Apply/Undo live inside the panel; when the range runs, leave
                // the panel open so the result and Undo button stay visible.
                $('#rqBulkApply, #rqBulkUndo').on('click', function () {
                    $panel.addClass('is-open');
                    $toggle.attr('aria-expanded', 'true').addClass('rq-tool-btn-active');
                });

                // Close the Output menu once an item is used (Bootstrap does not
                // auto-close menus built from non-anchor items).
                $(document).on('click', '#rqOutputMenu .dropdown-item', function () {
                    var btn = document.getElementById('rqOutputBtn');
                    if (btn && window.bootstrap) {
                        var dd = bootstrap.Dropdown.getInstance(btn);
                        if (dd) dd.hide();
                    }
                });

                // The fast-entry hint only matters once the feature is armed.
                var $fastHint = $('#rqFastHint');
                if ($fastHint.length) {
                    new MutationObserver(function () {
                        var on = $('#rqFastEntryToggle').attr('aria-pressed') === 'true';
                        $fastHint.toggleClass('d-none', !on);
                    }).observe($fastHint[0], { attributes: true, childList: true, subtree: true });
                }
            });
        })();
    </script>
