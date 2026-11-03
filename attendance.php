<?php 
require_once('DBConnection.php');
require_once('Actions.php');
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Check if class_id is provided
$class_id = isset($_GET['class_id']) ? $_GET['class_id'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Handle date navigation (legacy support)
if (isset($_GET['date_nav'])) {
    $current_date = new DateTime($date);
    if ($_GET['date_nav'] === 'prev') {
        $current_date->modify('-1 day');
    } elseif ($_GET['date_nav'] === 'next') {
        $current_date->modify('+1 day');
    }
    $date = $current_date->format('Y-m-d');
}

$classes = [];
$students = [];
$attendance = [];
$class_info = [];

// Which database file is serving THIS screen? A phone (ngrok) and the laptop
// only see each other's scans when both show the same database.
$active_db_label = 'Main Database';
try {
    $main_db_real = realpath(__DIR__ . '/db/grading_db.db');
    $cur_db_real = isset($active_db_file) ? realpath($active_db_file) : false;
    if ($main_db_real && $cur_db_real && $cur_db_real !== $main_db_real) {
        $mdb_tmp = new SQLite3($main_db_real, SQLITE3_OPEN_READONLY);
        $base_tmp = basename($cur_db_real);
        $lbl_tmp = $mdb_tmp->querySingle("SELECT fullname FROM admin_list WHERE db_path LIKE '%" . $mdb_tmp->escapeString($base_tmp) . "%' LIMIT 1");
        $mdb_tmp->close();
        $active_db_label = ($lbl_tmp ? $lbl_tmp : $base_tmp) . ' (faculty database)';
    }
} catch (Exception $e) { $active_db_label = 'Main Database'; }

// Fetch classes with their available quarters from assessment_list and quarter_list
$class_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class, 
                          a.quarter as quarter,
                          q.name as quarter_name
                          FROM `class_list` c 
                          INNER JOIN `subjects` s ON c.subject_id = s.subject_id
                          INNER JOIN `assessment_list` a ON c.class_id = a.class_id
                          LEFT JOIN `quarter_list` q ON q.class_id = c.class_id AND q.quarter = a.quarter
                          GROUP BY c.class_id, a.quarter
                          ORDER BY `class`, a.quarter ASC");

$classes = [];
while($row = $class_qry->fetchArray(SQLITE3_ASSOC)) {
    // Use dynamic quarter name from database, fallback to "Quarter X" if not set
    $quarter_name = !empty($row['quarter_name']) ? $row['quarter_name'] : ('Quarter ' . $row['quarter']);
    
    $classes[] = [
        'class_id' => $row['class_id'],
        'class_name' => $row['class'],
        'quarter' => $row['quarter'],
        'display_text' => $row['class'] . ' - ' . $quarter_name
    ];
}

// If class is selected, fetch students and their attendance
if (!empty($class_id) && !empty($_GET['quarter'])) {
    $quarter = intval($_GET['quarter']);
    
    // Fetch class information including schedule
    $class_info_qry = $conn->query("SELECT c.*, s.name as subject_name 
                                   FROM `class_list` c 
                                   INNER JOIN `subjects` s ON c.subject_id = s.subject_id
                                   WHERE c.class_id = '{$class_id}'");
    $class_info = $class_info_qry->fetchArray(SQLITE3_ASSOC) ?: [];
    
    // Fetch students in the class directly from student_list
    // No longer dependent on grade records existing first
    $student_qry = $conn->query("SELECT s.* 
                               FROM student_list s
                               WHERE s.class_id = '{$class_id}'
                               ORDER BY s.name ASC");
    
    while($row = $student_qry->fetchArray()) {
        $students[] = $row;
    }
    
    // Fetch attendance for the selected date, class and quarter (scoped - never leak across quarters)
    $att_qry = $conn->query("SELECT a.* 
                            FROM attendance a
                            WHERE a.class_id = '{$class_id}' 
                            AND a.date = '{$date}'
                            AND a.quarter = '{$quarter}'
                            ORDER BY a.student_id");
    
        // Store the quarter and date in the attendance array for reference
    $attendance['_quarter'] = $quarter;
    $attendance['_date'] = $date;
    
    // Reset the query pointer before fetching all rows
    $att_qry->reset();
    while($row = $att_qry->fetchArray(SQLITE3_ASSOC)) {
        $attendance[$row['student_id']] = [
            'status' => $row['status'],
            'reason' => $row['reason'] ?? ''
        ];
    }
}

// ---- Horizon summary stats (server-rendered initial values, live-updated by JS) ----
$stat_total = count($students);
$stat_present = 0; $stat_absent = 0; $stat_late = 0; $stat_excused = 0;
foreach ($students as $s) {
    $st = $attendance[$s['student_id']]['status'] ?? null;
    if ($st === 'present') $stat_present++;
    elseif ($st === 'absent') $stat_absent++;
    elseif ($st === 'late') $stat_late++;
    elseif ($st === 'excused') $stat_excused++;
}
$stat_unmarked = max(0, $stat_total - ($stat_present + $stat_absent + $stat_late + $stat_excused));
$quarter_val = isset($_GET['quarter']) ? intval($_GET['quarter']) : ($classes[0]['quarter'] ?? 1);
$selected_display = '';
foreach ($classes as $c) {
    if ((string)$c['class_id'] === (string)$class_id && isset($_GET['quarter']) && (int)$_GET['quarter'] === (int)$c['quarter']) {
        $selected_display = $c['display_text'];
        break;
    }
}
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
?>
<style>
/* ============ Attendance — Horizon professional skin (scoped, theme-token driven) ============ */
.attendance-horizon { display: flex; flex-direction: column; gap: 20px; }
.attendance-horizon .flatpickr-calendar { z-index: 99999 !important; }

/* Page head */
.att-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.att-head-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
.att-icon-box { width: 48px; height: 48px; min-width: 48px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; background: var(--horizon-brand-linear); color: #fff; box-shadow: 0 6px 16px rgba(67,24,255,.3); }
.att-title { margin: 0; font-size: 17px; font-weight: 800; color: var(--horizon-text-title); letter-spacing: -.01em; }
.att-subtitle { margin: 2px 0 0; font-size: 12px; font-weight: 500; color: var(--horizon-text-muted); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.att-subtitle i { color: var(--horizon-brand-500); }
.att-nav-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.att-nav-group .btn { border-radius: 999px !important; font-weight: 700; }
.att-nav-group .btn-outline-secondary { border: 1px solid var(--horizon-input-border) !important; color: var(--horizon-text-title) !important; background: var(--horizon-card-bg) !important; }
.att-nav-group .btn-outline-secondary:hover { border-color: var(--horizon-brand-500) !important; color: var(--horizon-brand-500) !important; }
.att-nav-group .btn-outline-primary { border: 1px solid var(--horizon-brand-500) !important; color: var(--horizon-brand-500) !important; background: transparent !important; }
.att-nav-group .btn-outline-primary:hover { background: var(--horizon-brand-500) !important; color: #fff !important; }

/* Stat widgets — compact variant */
.att-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; }
.att-stats .horizon-widget { padding: 14px 16px; gap: 12px; }
.att-stats .horizon-widget-icon-box { width: 44px; height: 44px; min-width: 44px; font-size: 16px; }
.att-stats .horizon-widget-value { font-size: 22px; }
.horizon-widget-icon-box.cyan { background: rgba(0,181,216,.12); color: var(--horizon-cyan-500); }
.horizon-widget-icon-box.slate { background: var(--horizon-input-bg); color: var(--horizon-text-muted); }
@media (max-width: 991.98px) { .att-stats { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 575.98px) { .att-stats { grid-template-columns: repeat(2, 1fr); } }

/* Filter + sync */
.att-filter-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 14px; }
@media (max-width: 767.98px) { .att-filter-grid { grid-template-columns: 1fr; } }
.att-sync-pill { display: inline-flex; align-items: center; gap: 8px; background: var(--horizon-input-bg); border: 1px solid var(--horizon-card-border); border-radius: 999px; padding: 8px 14px; font-size: 12px; font-weight: 600; color: var(--horizon-text-body); }
#liveBadge { font-size: 10px; letter-spacing: .4px; }
#lastSyncText { font-size: 12px; color: var(--horizon-text-muted); }
.att-db-line { font-size: 12px; color: var(--horizon-text-muted); margin-top: 8px; }
.att-db-line strong { color: var(--horizon-text-title); }

/* Session strip */
.att-session { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.att-session .btn { border-radius: 999px !important; }
#attendanceSessionStatus { font-size: 12.5px; color: var(--horizon-text-muted); font-weight: 500; }
#attendanceSessionCounts { font-size: 11px; }
.live-dot { display: inline-flex; align-items: center; gap: 6px; }
.live-dot::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: attPulse 1.6s ease-in-out infinite; }
@keyframes attPulse { 0%,100% { opacity: 1; transform: scale(1);} 50% { opacity: .45; transform: scale(.8);} }

/* Roster table */
.att-roster-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.att-student { display: flex; align-items: center; gap: 10px; min-width: 0; }
.att-avatar { width: 34px; height: 34px; min-width: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: var(--horizon-brand-500); background: var(--horizon-brand-50); border: 1px solid rgba(67,24,255,.18); }
.att-student-name { font-weight: 700; color: var(--horizon-text-title); font-size: 13px; line-height: 1.3; }
.att-student-no { font-size: 11.5px; color: var(--horizon-text-muted); font-weight: 600; }
.att-student-no.dup { color: var(--horizon-red-500); font-weight: 800; }

/* Segmented attendance control — built on existing .btn-check + label markup so all JS hooks keep working */
.att-seg.btn-group { display: flex; gap: 6px; flex-wrap: wrap; width: 100%; }
.att-seg .btn-check { position: absolute; opacity: 0; pointer-events: none; }
.att-seg .btn { flex: 1 1 auto; border-radius: 999px !important; font-size: 11px; font-weight: 700; padding: 7px 10px; white-space: nowrap; border: 1px solid var(--horizon-card-border) !important; background: var(--horizon-card-bg) !important; box-shadow: none !important; }
.att-seg .btn-outline-success { color: var(--horizon-green-600) !important; }
.att-seg .btn-outline-danger { color: var(--horizon-red-500) !important; }
.att-seg .btn-outline-warning { color: var(--horizon-amber-600) !important; }
.att-seg .btn-outline-info { color: var(--horizon-cyan-500) !important; }
.att-seg .btn:hover { transform: translateY(-1px); border-color: currentColor !important; }
.att-seg .btn-check:checked + .btn-outline-success { background: var(--horizon-green-500) !important; border-color: var(--horizon-green-500) !important; color: #fff !important; box-shadow: 0 4px 12px rgba(5,205,153,.35) !important; }
.att-seg .btn-check:checked + .btn-outline-danger { background: var(--horizon-red-500) !important; border-color: var(--horizon-red-500) !important; color: #fff !important; box-shadow: 0 4px 12px rgba(238,93,80,.35) !important; }
.att-seg .btn-check:checked + .btn-outline-warning { background: var(--horizon-amber-500) !important; border-color: var(--horizon-amber-500) !important; color: #fff !important; box-shadow: 0 4px 12px rgba(255,181,71,.4) !important; }
.att-seg .btn-check:checked + .btn-outline-info { background: var(--horizon-cyan-500) !important; border-color: var(--horizon-cyan-500) !important; color: #fff !important; box-shadow: 0 4px 12px rgba(0,181,216,.35) !important; }
.att-seg .btn-check:focus-visible + .btn { outline: 2px solid var(--horizon-brand-500); outline-offset: 2px; }
@media (max-width: 575.98px) { .att-seg .btn { flex: 1 1 45%; } }

/* Enrollment-status pill select */
.status-dropdown { border-radius: 999px !important; font-weight: 800 !important; font-size: 11px !important; text-transform: uppercase; letter-spacing: .4px; padding: 7px 12px !important; min-height: 34px; max-width: 160px; border: none !important; cursor: pointer; }
.status-dropdown.st-Active { background-color: var(--horizon-green-500) !important; color: #fff !important; }
.status-dropdown.st-Dropped { background-color: var(--horizon-red-500) !important; color: #fff !important; }
.status-dropdown.st-NoSub { background-color: var(--horizon-amber-500) !important; color: #fff !important; }
.status-dropdown.st-NoExam { background-color: var(--horizon-cyan-500) !important; color: #fff !important; }
.status-dropdown option { background: var(--horizon-card-bg); color: var(--horizon-text-title); font-weight: 600; text-transform: none; }

/* Reason */
.reason-input { resize: vertical; min-height: 36px; }
.expand-reason-btn { border-radius: 0 var(--horizon-radius-md) var(--horizon-radius-md) 0 !important; }

/* QR stage — true black camera view */
#qr-reader { width: 100%; max-width: 420px; margin: 0 auto; background: #000; border-radius: var(--horizon-radius-lg); overflow: hidden; min-height: 300px; position: relative; border: 1px solid var(--horizon-card-border); box-shadow: var(--horizon-card-shadow); }
#qr-reader video { width: 100% !important; height: 100% !important; max-height: 420px; min-height: 300px; object-fit: cover; background: #000; display: block; border-radius: var(--horizon-radius-lg); }
#qr-reader canvas { max-width: 100%; }
.scanner-controls { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
.scanner-controls .btn { border-radius: 999px !important; }
#scannedStudents { display: flex; flex-direction: column; gap: 8px; }
.scan-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 14px; border-radius: var(--horizon-radius-md); background: var(--horizon-input-bg); border: 1px solid var(--horizon-card-border); }
.scan-item strong { color: var(--horizon-text-title); font-size: 13px; }
.scan-item small { color: var(--horizon-text-muted); }

/* Scan success popup — theme aware */
#scanSuccessPopup { --scan-accent: #05CD99; position: fixed; inset: 0; z-index: 1090; display: none; align-items: center; justify-content: center; background: rgba(11,20,55,.45); backdrop-filter: blur(2px); opacity: 0; transition: opacity .25s ease; padding: 16px; }
#scanSuccessPopup.show { opacity: 1; }
.scan-check-card { background: var(--horizon-card-bg); border: 1px solid var(--horizon-card-border); border-radius: 20px; padding: 28px 40px 24px; text-align: center; max-width: 320px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.35); transform: scale(.7); transition: transform .28s cubic-bezier(.34,1.56,.64,1); }
#scanSuccessPopup.show .scan-check-card { transform: scale(1); }
.scan-check-svg { width: 88px; height: 88px; margin-bottom: 10px; }
.scan-check-circle { stroke: var(--scan-accent); stroke-width: 3; fill: none; stroke-dasharray: 151; stroke-dashoffset: 151; animation: scan-draw-circle .55s ease-out forwards; }
.scan-check-mark { stroke: var(--scan-accent); stroke-width: 4.5; fill: none; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 42; stroke-dashoffset: 42; animation: scan-draw-check .35s .5s ease-out forwards; }
@keyframes scan-draw-circle { to { stroke-dashoffset: 0; } }
@keyframes scan-draw-check { to { stroke-dashoffset: 0; } }
.scan-check-name { font-weight: 800; font-size: 1.05rem; color: var(--horizon-text-title); margin-bottom: 4px; word-break: break-word; }
.scan-check-status { font-weight: 700; font-size: .9rem; color: var(--scan-accent); }
.scan-check-hint { font-size: .72rem; color: var(--horizon-text-muted); margin-top: 10px; }
@media (prefers-reduced-motion: reduce) { .scan-check-circle, .scan-check-mark { animation: none; stroke-dashoffset: 0; } .scan-check-card { transition: none; transform: scale(1); } .live-dot::before { animation: none; } }
</style>

<div class="attendance-horizon">
    <!-- Page header -->
    <div class="horizon-card">
        <div class="horizon-card-body att-head">
            <div class="att-head-left">
                <div class="att-icon-box"><i class="fas fa-calendar-check"></i></div>
                <div class="min-w-0">
                    <h4 class="att-title">Daily Attendance</h4>
                    <p class="att-subtitle">
                        <span><i class="fas fa-calendar-alt me-1"></i><?php echo date('F d, Y (l)', strtotime($date)); ?></span>
                        <?php if ($selected_display): ?>
                            <span><i class="fas fa-chalkboard-teacher me-1"></i><?php echo htmlspecialchars($selected_display); ?></span>
                        <?php endif; ?>
                        <span class="horizon-badge badge-secondary" style="text-transform:none; letter-spacing:0;">
                            <i class="fas fa-database me-1"></i><?php echo htmlspecialchars($active_db_label); ?>
                        </span>
                    </p>
                </div>
            </div>
            <div class="att-nav-group">
                <a href="?page=assessments&tab=attendance&class_id=<?php echo $class_id; ?>&quarter=<?php echo isset($_GET['quarter']) ? $_GET['quarter'] : ''; ?>&date=<?php echo $prev_date; ?>"
                   class="btn btn-outline-secondary btn-sm" title="Previous Day">
                    <i class="fas fa-chevron-left"></i> Previous Day
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm" id="datePickerBtn" title="Select Date">
                    <i class="fas fa-calendar-day"></i> Calendar
                </button>
                <a href="?page=assessments&tab=attendance&class_id=<?php echo $class_id; ?>&quarter=<?php echo isset($_GET['quarter']) ? $_GET['quarter'] : ''; ?>&date=<?php echo $next_date; ?>"
                   class="btn btn-outline-secondary btn-sm" title="Next Day">
                    Next Day <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="horizon-card">
        <div class="horizon-card-header">
            <div class="d-flex align-items-center gap-2">
                <div class="horizon-widget-icon-box brand" style="width:38px;height:38px;min-width:38px;font-size:14px;"><i class="fas fa-filter"></i></div>
                <div>
                    <h5 class="mb-0 fw-bold">Session Filter</h5>
                    <small class="text-muted">Choose a class and date to take attendance</small>
                </div>
            </div>
            <span class="att-sync-pill"><span class="badge bg-success" id="liveBadge"><i class="fas fa-sync-alt me-1"></i>Live</span><span id="lastSyncText">Waiting for updates…</span></span>
        </div>
        <div class="horizon-card-body">
            <form id="attendanceFilter" class="att-filter-grid">
                <div>
                    <label class="form-label" for="class_id">Select Class</label>
                    <select name="class_id" id="class_id" class="form-select" required>
                        <option value="">-- Select Class --</option>
                        <?php
                        $unique_classes = [];
                        foreach($classes as $class) {
                            $key = $class['class_id'] . '-' . $class['quarter'];
                            if (!isset($unique_classes[$key])) {
                                $unique_classes[$key] = $class;
                            }
                        }
                        foreach($unique_classes as $class):
                            $selected = ($class_id == $class['class_id'] && isset($_GET['quarter']) && $_GET['quarter'] == $class['quarter']) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $class['class_id'] ?>"
                                    data-quarter="<?php echo $class['quarter'] ?>"
                                    <?php echo $selected ?>>
                                <?php echo htmlspecialchars($class['display_text']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="date">Date</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        <input type="text" name="date" id="date" class="form-control date-picker"
                               value="<?php echo date('Y-m-d', strtotime($date)) ?>"
                               data-date-format="Y-m-d"
                               readonly>
                    </div>
                </div>
                <div>
                    <label class="form-label">Database</label>
                    <div class="att-db-line" style="margin-top:0; padding:10px 2px;">
                        <i class="fas fa-database me-1 text-primary"></i>
                        <strong><?php echo htmlspecialchars($active_db_label); ?></strong>
                        <div><small>Phone + laptop only sync when both show the same database.</small></div>
                    </div>
                </div>
                <input type="hidden" name="quarter" id="quarter" value="<?php echo $quarter_val ?>">
            </form>
        </div>
    </div>

    <?php if(!empty($class_id) && !empty($_GET['quarter'])): ?>
    <!-- Live summary -->
    <div class="att-stats">
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box slate"><i class="fas fa-users"></i></div>
            <div class="horizon-widget-content"><p class="horizon-widget-title">Total Students</p><p class="horizon-widget-value" id="statTotal"><?php echo $stat_total; ?></p></div>
        </div>
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box green"><i class="fas fa-check"></i></div>
            <div class="horizon-widget-content"><p class="horizon-widget-title">Present</p><p class="horizon-widget-value" id="statPresent"><?php echo $stat_present; ?></p></div>
        </div>
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box red"><i class="fas fa-times"></i></div>
            <div class="horizon-widget-content"><p class="horizon-widget-title">Absent</p><p class="horizon-widget-value" id="statAbsent"><?php echo $stat_absent; ?></p></div>
        </div>
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box amber"><i class="fas fa-clock"></i></div>
            <div class="horizon-widget-content"><p class="horizon-widget-title">Late</p><p class="horizon-widget-value" id="statLate"><?php echo $stat_late; ?></p></div>
        </div>
        <div class="horizon-widget">
            <div class="horizon-widget-icon-box cyan"><i class="fas fa-user-clock"></i></div>
            <div class="horizon-widget-content"><p class="horizon-widget-title">Excused</p><p class="horizon-widget-value" id="statExcused"><?php echo $stat_excused; ?></p></div>
        </div>
    </div>

    <!-- Attendance session strip -->
    <div class="horizon-card" id="attendanceSessionCard">
        <div class="horizon-card-body att-session">
            <button type="button" class="btn btn-success btn-sm" id="startAttendanceBtn">
                <i class="fas fa-play me-1"></i> Start Attendance
            </button>
            <button type="button" class="btn btn-danger btn-sm" id="endAttendanceBtn" style="display:none;">
                <i class="fas fa-stop me-1"></i> End Session
            </button>
            <span id="attendanceSessionStatus">No active session — click Start to request confirmations from students in this class.</span>
            <span id="attendanceSessionCounts" class="badge badge-secondary" style="display:none;"></span>
            <span id="attendanceSessionLive" class="badge badge-success live-dot" style="display:none;">Live</span>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($class_id)): ?>
    <!-- Roster -->
    <div class="horizon-card">
        <div class="horizon-card-header">
            <div class="d-flex align-items-center gap-2">
                <div class="horizon-widget-icon-box brand" style="width:38px;height:38px;min-width:38px;font-size:14px;"><i class="fas fa-list-check"></i></div>
                <div>
                    <h5 class="mb-0 fw-bold">Class Roster</h5>
                    <small class="text-muted"><?php echo $stat_total; ?> student<?php echo $stat_total === 1 ? '' : 's'; ?> • <?php echo date('M d, Y', strtotime($date)); ?><?php echo $stat_unmarked > 0 ? ' • ' . $stat_unmarked . ' unmarked' : ''; ?></small>
                </div>
            </div>
            <div class="att-roster-meta">
                <span class="horizon-badge badge-brand" id="rosterMarkedBadge"><?php echo ($stat_total - $stat_unmarked); ?>/<?php echo $stat_total; ?> marked</span>
            </div>
        </div>
        <div class="horizon-card-body p-0">
            <div class="table-responsive p-3" id="alertContainer">
                <table class="table table-hover mb-0 horizon-table" id="attendanceTable">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th class="text-center">Student</th>
                            <th class="text-center">Attendance</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($students) > 0): ?>
                            <?php $i = 1; foreach($students as $student):
                                $status = $attendance[$student['student_id']]['status'] ?? null;
                                $is_dropped = (isset($student['status']) && $student['status'] === 'Dropped');
                                $initials = strtoupper(implode('', array_slice(array_map(function($w){ return $w[0] ?? ''; }, preg_split('/\s+/', trim($student['name']))), 0, 2)));
                                $currentStatus = isset($student['status']) && in_array($student['status'], ['Active', 'Dropped','NoSub','NoExam']) ? $student['status'] : 'Active';
                            ?>
                            <tr data-student-id="<?php echo $student['student_id'] ?>">
                                <td><?php echo $i++ ?></td>
                                <td>
                                    <div class="att-student">
                                        <div class="att-avatar"><?php echo htmlspecialchars($initials ?: 'ST'); ?></div>
                                        <div class="min-w-0">
                                            <div class="att-student-name"><?php echo htmlspecialchars($student['name']) ?></div>
                                            <div class="att-student-no <?php echo strpos($student['student_number'], '00') === 0 ? 'dup' : '' ?>"><?php echo htmlspecialchars($student['student_number']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($is_dropped): ?>
                                        <span class="horizon-badge badge-danger">Dropped</span>
                                    <?php else: ?>
                                        <div class="btn-group btn-group-sm att-seg w-100" role="group">
                                            <input type="radio" class="btn-check"
                                                name="status_<?php echo $student['student_id'] ?>"
                                                id="present_<?php echo $student['student_id'] ?>"
                                                value="present"
                                                autocomplete="off"
                                                <?php echo $status == 'present' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-success flex-grow-1"
                                                for="present_<?php echo $student['student_id'] ?>">
                                                <i class="fas fa-check me-1"></i>Present
                                            </label>

                                            <input type="radio" class="btn-check"
                                                name="status_<?php echo $student['student_id'] ?>"
                                                id="absent_<?php echo $student['student_id'] ?>"
                                                value="absent"
                                                autocomplete="off"
                                                <?php echo $status == 'absent' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-danger flex-grow-1"
                                                for="absent_<?php echo $student['student_id'] ?>">
                                                <i class="fas fa-times me-1"></i>Absent
                                            </label>

                                            <input type="radio" class="btn-check"
                                                name="status_<?php echo $student['student_id'] ?>"
                                                id="late_<?php echo $student['student_id'] ?>"
                                                value="late"
                                                autocomplete="off"
                                                <?php echo $status == 'late' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-warning flex-grow-1"
                                                for="late_<?php echo $student['student_id'] ?>">
                                                <i class="fas fa-clock me-1"></i>Late
                                            </label>

                                            <input type="radio" class="btn-check"
                                                name="status_<?php echo $student['student_id'] ?>"
                                                id="excused_<?php echo $student['student_id'] ?>"
                                                value="excused"
                                                autocomplete="off"
                                                <?php echo $status == 'excused' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-info flex-grow-1"
                                                for="excused_<?php echo $student['student_id'] ?>">
                                                <i class="fas fa-user-clock me-1"></i>Excused
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <select class="form-select form-select-sm status-dropdown st-<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>"
                                       data-id="<?php echo $student['student_id'] ?>"
                                       data-status="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>"
                                       onchange="handleStatusChange(this)">
                                        <option value="Active"<?php echo $currentStatus === 'Active' ? ' selected' : '' ?>>Active</option>
                                        <option value="Dropped"<?php echo $currentStatus === 'Dropped' ? ' selected' : '' ?>>Dropped</option>
                                        <option value="NoSub"<?php echo $currentStatus === 'NoSub' ? ' selected' : '' ?>>No Submissions</option>
                                        <option value="NoExam"<?php echo $currentStatus === 'NoExam' ? ' selected' : '' ?>>No Exam</option>
                                    </select>
                                </td>
                                 <td>
                                    <?php if ($is_dropped): ?>
                                        <span class="text-muted small">Not applicable</span>
                                    <?php else: ?>
                                        <div class="input-group input-group-sm">
                                            <textarea
                                                   class="form-control reason-input"
                                                   data-student-id="<?php echo $student['student_id'] ?>"
                                                   name="reason_<?php echo $student['student_id'] ?>"
                                                   placeholder="Enter reason"
                                                   rows="1"></textarea>
                                            <button type="button"
                                                    class="btn btn-secondary expand-reason-btn"
                                                    data-student-id="<?php echo $student['student_id'] ?>"
                                                    data-student-name="<?php echo htmlspecialchars($student['name']) ?>"
                                                    title="Expand for easier editing">
                                                <i class="fas fa-expand"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No students found in this class.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="horizon-flash-banner horizon-flash-info">
        <i class="fas fa-circle-info"></i>
        <div>Please select a class and date to view attendance.</div>
    </div>
    <?php endif; ?>

    <!-- QR Scanner -->
    <div class="horizon-card" id="scannerSection">
        <div class="horizon-card-header">
            <div class="d-flex align-items-center gap-2">
                <div class="horizon-widget-icon-box brand" style="width:38px;height:38px;min-width:38px;font-size:14px;"><i class="fas fa-qrcode"></i></div>
                <div>
                    <h5 class="mb-0 fw-bold">QR Code Scanner</h5>
                    <small class="text-muted">Scan student QR codes to record attendance instantly</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="horizon-badge badge-brand" id="scanCount">0 scanned</span>
                <span class="horizon-badge badge-warning" id="timerDisplay" style="display:none;">
                    <i class="fas fa-clock me-1"></i><span id="timerText">15:00</span>
                </span>
            </div>
        </div>
        <div class="horizon-card-body">
            <div class="row g-3">
                <!-- Scanner Column -->
                <div class="col-md-6">
                    <div class="text-center">
                        <div id="qr-reader"></div>
                        <div class="mt-2 d-flex justify-content-center align-items-center gap-2 flex-wrap">
                            <label for="cameraSelect" class="small text-muted mb-0"><i class="fas fa-video me-1"></i>Camera</label>
                            <select id="cameraSelect" class="form-select form-select-sm" style="max-width:260px; min-width:180px;" title="Choose which camera to use">
                                <option value="">Default camera</option>
                            </select>
                        </div>
                        <div class="mt-1"><small class="text-muted">Using DroidCam? Connect the phone in the DroidCam client first (app open on the phone), then choose the <strong>DroidCam</strong> camera above.</small></div>
                        <div class="mt-3 scanner-controls">
                            <button class="btn btn-success btn-sm" id="startScanner">
                                <i class="fas fa-camera me-1"></i> Start Scanner
                            </button>
                            <button class="btn btn-danger btn-sm" id="stopScanner" style="display: none;">
                                <i class="fas fa-stop me-1"></i> Stop Scanner
                            </button>
                            <button class="btn btn-secondary btn-sm" id="clearScans">
                                <i class="fas fa-trash me-1"></i> Clear Scans
                            </button>
                        </div>

                        <!-- Timer Status -->
                        <div class="mt-3" id="timerStatus" style="display: none;">
                            <div class="horizon-flash-banner horizon-flash-info mb-0" style="text-align:left;">
                                <i class="fas fa-circle-info"></i>
                                <div><strong>Status:</strong> <span id="statusText">Marking as Present</span><br>
                                <small>Students will be marked as <strong>Late</strong> after the timer expires.</small></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scanned Students Column -->
                <div class="col-md-6">
                    <h6 class="fw-bold" style="color:var(--horizon-text-title);">Recently Scanned Students</h6>
                    <div id="scannedStudents" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-muted text-center py-4">
                            <i class="fas fa-user-clock fa-3x mb-2"></i>
                            <p>No students scanned yet</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Schedule Info -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="horizon-flash-banner horizon-flash-info mb-0">
                        <i class="fas fa-circle-info"></i>
                        <div><strong>Class Schedule:</strong>
                        <span id="classSchedule"><?php echo htmlspecialchars($class_info['schedule'] ?? 'No schedule set'); ?></span>
                        <br>
                        <small>Scanning records timestamps from this schedule. Students scanned within 15 minutes are marked "Present", after that they're marked "Late".</small></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reason Edit Modal -->
<div class="modal fade modern-modal" id="reasonModal" tabindex="-1" aria-labelledby="reasonModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reasonModalLabel">
                    <i class="fas fa-edit me-2 text-primary"></i>Edit Reason for <span id="modalStudentName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="modalReasonText" class="form-label">Reason/Notes:</label>
                    <textarea
                        class="form-control"
                        id="modalReasonText"
                        rows="8"
                        placeholder="Enter detailed reason or notes here..."></textarea>
                </div>
                <div class="form-text">
                    <i class="fas fa-circle-info me-1"></i>
                    This text will be saved specifically for this date and student.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="saveReasonBtn">
                    <i class="fas fa-save me-1"></i>Save Reason
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scanner Error / Info Modal (replaces console errors) -->
<div class="modal fade modern-modal" id="scannerMsgModal" tabindex="-1" aria-labelledby="scannerMsgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="scannerMsgHeader">
                <h5 class="modal-title" id="scannerMsgModalLabel"><i class="fas fa-qrcode me-2"></i>Scanner</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="scannerMsgBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Animated scan-success check popup -->
<div id="scanSuccessPopup" role="status" aria-live="polite" title="Tap to dismiss">
    <div class="scan-check-card">
        <svg class="scan-check-svg" viewBox="0 0 52 52" aria-hidden="true">
            <circle class="scan-check-circle" cx="26" cy="26" r="24" />
            <path class="scan-check-mark" d="M14 27l8 8 16-16" />
        </svg>
        <div class="scan-check-name" id="scanSuccessName"></div>
        <div class="scan-check-status" id="scanSuccessStatus"></div>
        <div class="scan-check-hint">Tap anywhere to dismiss</div>
    </div>
</div>

<!-- End Attendance Confirmation Modal -->
<div class="modal fade modern-modal" id="endAttendanceModal" tabindex="-1" aria-labelledby="endAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="overflow:hidden">
            <div class="modal-header" style="background: linear-gradient(135deg, #EE5D50 0%, #E31A1A 100%); border-bottom:none;">
                <h5 class="modal-title" id="endAttendanceModalLabel" style="color:#fff;"><i class="fas fa-stop-circle me-2"></i> End Attendance Session?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 fw-bold" style="color:var(--horizon-text-title)">Are you sure you want to end this attendance session?</p>
                <p class="mb-0 small text-muted">Pending confirmations will be <strong style="color:var(--horizon-red-500)">automatically set to absent</strong> and students will no longer be able to confirm for this date. This action cannot be undone.</p>
                <div class="mt-3 p-2 rounded horizon-flash-banner horizon-flash-danger mb-0" style="font-size:.85rem">
                    <i class="fas fa-circle-info me-1"></i> <span id="endAttendanceModalCounts">Loading...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" style="border-radius:999px;" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" style="border-radius:999px;" id="confirmEndAttendanceBtn"><i class="fas fa-check me-1"></i> Yes, End Session</button>
            </div>
        </div>
    </div>
</div>

<script>
        function loadScript(src) {
            return new Promise(function(resolve, reject) {
                const existing = document.querySelector('script[src="' + src + '"]');
                if (existing) {
                    if (existing.dataset.loaded === 'true') {
                        resolve();
                        return;
                    }
                    existing.addEventListener('load', function() { resolve(); }, { once: true });
                    existing.addEventListener('error', function() { reject(new Error('Failed to load ' + src)); }, { once: true });
                    return;
                }

                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.onload = function() {
                    script.dataset.loaded = 'true';
                    resolve();
                };
                script.onerror = function() {
                    reject(new Error('Failed to load ' + src));
                };
                document.head.appendChild(script);
            });
        }

        function loadStylesheet(href) {
            return new Promise(function(resolve, reject) {
                const existing = document.querySelector('link[href="' + href + '"]');
                if (existing) {
                    resolve();
                    return;
                }

                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.onload = function() { resolve(); };
                link.onerror = function() { reject(new Error('Failed to load ' + href)); };
                document.head.appendChild(link);
            });
        }

        function initializeDatePicker() {
            if (typeof flatpickr === 'function') {
                if (window.datePicker && typeof window.datePicker.destroy === 'function') {
                    window.datePicker.destroy();
                }

                window.datePicker = flatpickr("#date", {
                    dateFormat: "Y-m-d",
                    defaultDate: "<?php echo date('Y-m-d', strtotime($date)) ?>",
                    appendTo: document.body,
                    static: false,
                    position: "auto center",
                    onChange: function(selectedDates, dateStr) {
                        if (selectedDates.length) {
                            window.location.href = `?page=assessments&tab=attendance&class_id=<?php echo $class_id ?>&quarter=<?php echo isset($_GET['quarter']) ? $_GET['quarter'] : '' ?>&date=${dateStr}&t=${new Date().getTime()}`;
                        }
                    }
                });
                return;
            }

            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.removeAttribute('readonly');
                dateInput.setAttribute('type', 'date');
            }
        }

        document.getElementById('datePickerBtn').addEventListener('click', function() {
            if (window.datePicker && typeof window.datePicker.open === 'function') {
                window.datePicker.open();
                return;
            }

            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.focus();
                if (dateInput.showPicker) { try { dateInput.showPicker(); } catch(e){} }
            }
        });

        // Class/quarter switching is handled by the single jQuery 'change' handler below
        // (it saves the OLD context first, then navigates). No vanilla handler here on purpose:
        // a second handler would overwrite #quarter before the save runs and mirror one
        // quarter's attendance into the other.

        initializeDatePicker();
        Promise.all([
            loadStylesheet('./css/flatpickr.min.css'),
            loadScript('./js/flatpickr.min.js')
        ]).then(function() {
            initializeDatePicker();
        }).catch(function(error) {
            console.warn(error.message);
        });

        function showScannerModal(message, type='danger', title='Scanner') {
            const header = document.getElementById('scannerMsgHeader');
            const titleEl = document.getElementById('scannerMsgModalLabel');
            const body = document.getElementById('scannerMsgBody');
            const icon = type==='success'?'fa-check-circle':type==='warning'?'fa-exclamation-triangle':'fa-times-circle';
            const bg = type==='success'?'#05CD99':type==='warning'?'#FFB547':'#EE5D50';
            if (header) header.style.borderTop = '4px solid ' + bg;
            if (titleEl) titleEl.innerHTML = '<i class="fas '+icon+' me-2"></i>' + title;
            if (body) body.innerHTML = '<p class="mb-0">'+message+'</p>';
            var modalEl = document.getElementById('scannerMsgModal');
            if (modalEl && typeof bootstrap !== 'undefined') new bootstrap.Modal(modalEl).show();
        }
        // Load QR lib with fallback to local copy; also warn if not in secure context (camera requires HTTPS on mobile)
        if (window.isSecureContext === false && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            showScannerModal('Camera requires <b>HTTPS</b> (or localhost). Open this page via <code>https://</code> on your phone, otherwise the scanner will fail.', 'warning', 'Secure Context Required');
        }
        loadScript('https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js').catch(function(error) {
            return loadScript('./js/html5-qrcode.min.js');
        }).then(function() {
            if (typeof Html5Qrcode === 'undefined') throw new Error('QR library not loaded');
            refreshCameraList();
        }).catch(function(error) {
            showScannerModal('QR scanner library failed to load. Check internet connection and refresh the page.', 'danger', 'Load Failed');
        });
    </script>
    <script>
    // Function to save attendance to database with is_clicked flag
    function saveAttendanceToDatabase(classId, quarter, date, studentId, status, attendanceData) {
        if (!classId || !quarter || !date) return;

        // If attendanceData is provided (multiple students), use it
        // Otherwise, create single student data
        const dataToSend = attendanceData || {[studentId]: status};

        return $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'save_attendance',
                class_id: classId,
                date: date,
                quarter: quarter,
                attendance_data: JSON.stringify(dataToSend)
            },
            dataType: 'json'
        });
    }

    // Saves the DOM's checked radios to the EXPLICITLY passed context
    // (defaults to current inputs) so a class/quarter switch can never save the
    // old page's buttons into the newly selected quarter.
    // Top-level on purpose: the QR scan path calls it from outside
    // $(document).ready. Resolves true on saved, false otherwise.
    function saveCurrentAttendanceState(saveClassId, saveQuarter, saveDate) {
        const classId = saveClassId || $('#class_id').val();
        const date = saveDate || $('#date').val();
        const quarter = saveQuarter || $('#quarter').val();

        if (!classId || !date || !quarter) return Promise.resolve(false);

        // Collect all currently checked radio buttons
        const allAttendanceData = {};
        $('tr[data-student-id]').each(function() {
            const studentId = $(this).data('student-id');
            const checkedRadio = $(this).find('input[type="radio"]:checked');
            if (checkedRadio.length) {
                allAttendanceData[studentId] = checkedRadio.val();
            }
        });

        // Save all attendance data to database
        if (Object.keys(allAttendanceData).length > 0) {
            return saveAttendanceToDatabase(classId, quarter, date, null, null, allAttendanceData)
                .then(function() { return true; })
                .catch(function() { return false; });
        }
        return Promise.resolve(true);
    }

    // Function to load attendance from database
    function loadAttendanceFromDatabase(classId, quarter, date) {
        if (!classId || !quarter || !date) return Promise.resolve({});

        return $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'load_attendance_for_date',
                class_id: classId,
                quarter: quarter,
                date: date
            },
            dataType: 'json'
        }).then(function(response) {
            if (response.status === 'success') {
                return response.data;
            } else {
                console.error('Error loading attendance:', response.msg);
                return {};
            }
        }).fail(function() {
            console.error('Failed to load attendance from database');
            return {};
        });
    }

    // Function to save reasons to database
    function saveReasonsToDatabase() {
        const classId = $('#class_id').val();
        const date = $('#date').val();
        const quarter = $('#quarter').val();

        if (!classId || !date || !quarter) return;

        const reasons = {};
        document.querySelectorAll('.reason-input').forEach(input => {
            const studentId = input.dataset.studentId;
            if (input.value.trim()) {
                reasons[studentId] = input.value.trim();
            }
        });

        // Save reasons to database (you'll need to implement this endpoint)
        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'save_attendance_reasons',
                class_id: classId,
                date: date,
                quarter: quarter,
                reasons: JSON.stringify(reasons)
            },
            dataType: 'json'
        });
    }

    // Function to load reasons from database
    function loadReasonsFromDatabase() {
        const classId = $('#class_id').val();
        const date = $('#date').val();
        const quarter = $('#quarter').val();

        if (!classId || !date || !quarter) return;

        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'load_attendance_reasons',
                class_id: classId,
                date: date,
                quarter: quarter
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Object.entries(response.data).forEach(([studentId, reason]) => {
                        const input = document.querySelector(`.reason-input[data-student-id="${studentId}"]`);
                        if (input) {
                            input.value = reason;
                        }
                    });
                }
            }
        });
    }

    // Horizon-aware alert: keeps the inline dismissible alert AND fires a Horizon toast when available.
    function showAlert(message, type = 'info') {
        try {
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(type, message, '', 3500);
            }
        } catch(e){}
        // Remove any existing alerts safely
        const existing = document.querySelectorAll('#alertContainer .alert-dismissible');
        existing.forEach(el => {
            try { bootstrap.Alert.getOrCreateInstance(el).close(); } catch(e){ el.remove(); }
        });
        // Fallback: ensure no stray alerts remain
        $('#alertContainer .alert-dismissible').remove();

        // Create alert element
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;

        // Add alert to the page
        $('#alertContainer').prepend(alertHtml);

        // Auto-hide after 5 seconds — fade out then remove
        setTimeout(() => {
            const alerts = document.querySelectorAll('#alertContainer .alert-dismissible');
            alerts.forEach(el => {
                try {
                    const inst = bootstrap.Alert.getOrCreateInstance(el);
                    inst.close();
                } catch(e){
                    el.remove();
                }
                // Safety net: if still in DOM after 600ms, force remove
                setTimeout(()=>{ if(document.body.contains(el)) el.remove(); }, 700);
            });
        }, 5000);
    }

    // Animated success check popup shown on every successful scan.
    let scanPopupTimer = null;
    function showScanSuccess(name, statusText, status) {
        const popup = document.getElementById('scanSuccessPopup');
        if (!popup) return;
        popup.style.setProperty('--scan-accent', status === 'late' ? '#FF8F00' : '#05CD99');
        document.getElementById('scanSuccessName').textContent = name || 'Scanned';
        document.getElementById('scanSuccessStatus').textContent = statusText || 'Recorded';
        // Replace the SVG so the draw animation restarts on every scan
        const svg = popup.querySelector('.scan-check-svg');
        if (svg) svg.replaceWith(svg.cloneNode(true));
        popup.style.display = 'flex';
        // Force reflow so the fade/scale transition plays even on rapid re-scans
        void popup.offsetWidth;
        popup.classList.add('show');
        try {
            const tick = new Audio('sounds/Pop_Effect.mp3');
            tick.play().catch(function() {});
        } catch (e) {}
        if (scanPopupTimer) clearTimeout(scanPopupTimer);
        scanPopupTimer = setTimeout(hideScanSuccess, 1900);
    }
    function hideScanSuccess() {
        const popup = document.getElementById('scanSuccessPopup');
        if (!popup) return;
        popup.classList.remove('show');
        setTimeout(function() {
            if (!popup.classList.contains('show')) popup.style.display = 'none';
        }, 280);
    }

    // Store previous status for each student
    const previousStatus = {};

    // Horizon stat widgets: recount from the DOM's checked radios.
    function refreshAttStats() {
        var p = 0, a = 0, l = 0, e = 0, total = 0;
        $('tr[data-student-id]').each(function() {
            var $row = $(this);
            if ($row.find('.status-dropdown').val() === 'Dropped') {
                return; // dropped students don't count toward attendance stats
            }
            total++;
            var v = $row.find('input[type="radio"]:checked').val();
            if (v === 'present') p++;
            else if (v === 'absent') a++;
            else if (v === 'late') l++;
            else if (v === 'excused') e++;
        });
        if ($('#statTotal').length) $('#statTotal').text(total);
        if ($('#statPresent').length) $('#statPresent').text(p);
        if ($('#statAbsent').length) $('#statAbsent').text(a);
        if ($('#statLate').length) $('#statLate').text(l);
        if ($('#statExcused').length) $('#statExcused').text(e);
        if ($('#rosterMarkedBadge').length) $('#rosterMarkedBadge').text((p + a + l + e) + '/' + total + ' marked');
    }

    // Function to update marks based on attendance status
    async function updateStudentMark(studentId, newStatus, oldStatus = null) {
        const classId = $('#class_id').val();
        const date = $('#date').val();
        const quarter = $('#quarter').val();

        if (!classId || !date || !quarter) {
            console.error('Missing required data for updateStudentMark');
            showAlert('Missing required data. Please refresh the page and try again.', 'danger');
            return false;
        }

        console.log(`Updating marks - Class: ${classId}, Quarter: ${quarter}, Date: ${date}, Student: ${studentId}, Status: ${newStatus}, Old Status: ${oldStatus || 'none'}`);

        // Save to database immediately
        try {
            // Collect all currently checked radio buttons, not just the one that was clicked
            const allAttendanceData = {};
            $('tr[data-student-id]').each(function() {
                const studentId = $(this).data('student-id');
                const checkedRadio = $(this).find('input[type="radio"]:checked');
                if (checkedRadio.length) {
                    allAttendanceData[studentId] = checkedRadio.val();
                }
            });

            const response = await $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {
                    a: 'save_attendance',
                    class_id: classId,
                    date: date,
                    quarter: quarter,
                    attendance_data: JSON.stringify(allAttendanceData)
                },
                dataType: 'json'
            });

            if (response.status !== 'success') {
                throw new Error(response.msg || 'Failed to save to database');
            }

            console.log('Attendance saved to database successfully');
        } catch (error) {
            console.error('Error saving attendance to database:', error);
            showAlert('Error saving attendance. Please try again.', 'danger');
            return false;
        }

        try {
            // Create form data for the request
            const formData = new URLSearchParams();
            formData.append('student_id', studentId);
            formData.append('class_id', classId);
            formData.append('date', date);
            formData.append('status', newStatus || '');
            formData.append('quarter', quarter);
            if (oldStatus) {
                formData.append('old_status', oldStatus);
            }

            // Show loading state
            console.log('Processing attendance update...');

            // Make the request to update marks
            const response = await fetch('update_marks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            });

            const result = await response.json();

            if (result.status === 'success') {
                console.log('Successfully processed marks update:', result.message);

               showAlert('Attendance updated!', 'success');
               try { refreshAttStats(); } catch(e){}

                return true;
            } else {
                console.error('Error updating marks:', result.message);
                showAlert('Error updating marks: ' + (result.message || 'Unknown error'), 'danger');
                return false;

            }
        } catch (error) {
            console.error('Error:', error);
            showAlert('An error occurred while updating marks. Please try again.', 'danger');
            return false;
        }
    }

    $(document).ready(function() {
        // Tap the success popup to dismiss it early
        $('#scanSuccessPopup').on('click', function() {
            if (scanPopupTimer) clearTimeout(scanPopupTimer);
            hideScanSuccess();
        });
        // Seed previousStatus from server-rendered checked radios so unmarking works on first click
        $('tr[data-student-id]').each(function() {
            var sid = $(this).data('student-id');
            var v = $(this).find('input[type="radio"]:checked').val() || null;
            previousStatus[sid] = v;
        });
        refreshAttStats();
        // Only initialize DataTable if there are student rows (not empty table)
        if ($('#attendanceTable tbody tr[data-student-id]').length > 0) {
            // Initialize data table
            const attendanceTable = $('#attendanceTable').DataTable({
                responsive: true,
                paging: false,
                searching: true,
                info: true,
                initComplete: function() {
                    // Load saved reasons when table is initialized
                    loadReasonsFromDatabase();

                    // Save reason when input changes
                    $(document).on('input', '.reason-input', function() {
                        saveReasonsToDatabase();
                    });
                }
            });
        } else {
            // For empty tables, just set up the reason input handlers
            $(document).on('input', '.reason-input', function() {
                saveReasonsToDatabase();
            });
        }

        // Clear any existing attendance data before loading
        function clearAttendanceDisplay() {
            $('tr[data-student-id]').each(function() {
                const studentId = $(this).data('student-id');
                $(this).find('input[type="radio"]').prop('checked', false);
                // Clear reason textarea as well
                $(this).find('.reason-input').val('');
                // Don't set any default status - leave unselected
                previousStatus[studentId] = null;
            });
            try { refreshAttStats(); } catch(e){}
        }

        // Load saved attendance from database
        function loadSavedAttendance() {
            const classId = $('#class_id').val();
            const quarter = $('#quarter').val();
            const date = $('#date').val();

            if (!classId || !quarter || !date) {
                console.log('Missing class, quarter, or date information');
                return;
            }

            console.log(`Loading attendance - Class: ${classId}, Quarter: ${quarter}, Date: ${date}`);

            // Clear current attendance display
            clearAttendanceDisplay();

            // Load attendance from database
            loadAttendanceFromDatabase(classId, quarter, date).then(function(savedAttendance) {
                console.log('Loaded attendance data from database:', savedAttendance);

                // Update radio buttons with the loaded attendance from database
                if (Object.keys(savedAttendance).length > 0) {
                    console.log('Applying database attendance data:', savedAttendance);

                    $('tr[data-student-id]').each(function() {
                        const studentId = $(this).data('student-id');
                        const attendanceData = savedAttendance[studentId];

                        if (attendanceData) {
                            const status = attendanceData.status;
                            const isClicked = attendanceData.is_clicked;

                            console.log(`Setting student ${studentId} to status: ${status}, clicked: ${isClicked} (from database)`);

                            // Uncheck all radio buttons for this student first
                            $(this).find('input[type="radio"]').prop('checked', false);

                            // Only set radio button if it was actually clicked (is_clicked = 1)
                            if (isClicked == 1) {
                                const radioToCheck = $(this).find(`input[type="radio"][value="${status}"]`);
                                if (radioToCheck.length) {
                                    radioToCheck.prop('checked', true);
                                    console.log(`Set radio for student ${studentId} to ${status} (from database)`);
                                } else {
                                    console.warn(`No radio button found for status: ${status}`);
                                }
                            } else {
                                console.log(`Student ${studentId} status not clicked, leaving unselected`);
                            }

                            previousStatus[studentId] = status;
                        }
                    });

                    console.log('Attendance data loaded successfully from database');
                } else {
                    console.log('No attendance data found in database for this date');
                }

                try { refreshAttStats(); } catch(e){}
                // Load reasons from database
                loadReasonsFromDatabase();
            });
        }

        // Live sync: pick up scans recorded from other devices (phone via ngrok)
        // without touching reason text the local user may be typing.
        let attendancePollTimer = null;
        const ATTENDANCE_POLL_MS = 5000;
        function updateLiveBadge(ok, changed) {
            const badge = document.getElementById('liveBadge');
            const txt = document.getElementById('lastSyncText');
            if (!badge || !txt) return;
            if (ok) {
                badge.className = 'badge bg-success';
                txt.textContent = changed > 0
                    ? 'Updated just now (' + changed + ' change' + (changed > 1 ? 's' : '') + ')'
                    : 'Synced ' + new Date().toLocaleTimeString();
            } else {
                badge.className = 'badge bg-secondary';
                txt.textContent = 'Sync paused — will retry';
            }
        }
        function refreshAttendanceFromServer() {
            if (isUpdating) return; // local save in flight — don't fight it
            if (document.hidden) return; // background tab: skip
            const classId = $('#class_id').val();
            const quarter = $('#quarter').val();
            const date = $('#date').val();
            if (!classId || !quarter || !date) return;
            loadAttendanceFromDatabase(classId, quarter, date).then(function(saved) {
                let changed = 0;
                $('tr[data-student-id]').each(function() {
                    const sid = $(this).data('student-id');
                    const rec = saved[sid];
                    if (!rec || rec.is_clicked != 1) return;
                    const $checked = $(this).find('input[type="radio"]:checked');
                    const domVal = $checked.length ? $checked.val() : null;
                    if (rec.status !== domVal) {
                        $(this).find('input[type="radio"]').prop('checked', false);
                        const $target = $(this).find(`input[type="radio"][value="${rec.status}"]`);
                        if ($target.length) {
                            $target.prop('checked', true);
                            previousStatus[sid] = rec.status;
                            changed++;
                        }
                    }
                });
                if (changed > 0) { try { refreshAttStats(); } catch(e){} }
                updateLiveBadge(true, changed);
            }).catch(function() { updateLiveBadge(false, 0); });
        }
        function startAttendancePolling() {
            stopAttendancePolling();
            updateLiveBadge(true, 0);
            attendancePollTimer = setInterval(refreshAttendanceFromServer, ATTENDANCE_POLL_MS);
        }
        function stopAttendancePolling() {
            if (attendancePollTimer) {
                clearInterval(attendancePollTimer);
                attendancePollTimer = null;
            }
        }

        // Initial load of saved attendance
        loadSavedAttendance();
        // Live sync so scans from other devices (phone via ngrok) render here automatically
        startAttendancePolling();
        window.addEventListener('pagehide', function() { stopAttendancePolling(); });

        // Reason Modal functionality
        let currentStudentId = null;

        // Handle expand button click
        $(document).on('click', '.expand-reason-btn', function() {
            currentStudentId = $(this).data('student-id');
            const studentName = $(this).data('student-name');

            // Get current reason text
            const currentReason = $(`textarea[data-student-id="${currentStudentId}"]`).val();

            // Set modal content
            $('#modalStudentName').text(studentName);
            $('#modalReasonText').val(currentReason);

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('reasonModal'));
            modal.show();
        });

        // Handle save reason button
        $('#saveReasonBtn').on('click', function() {
            const reasonText = $('#modalReasonText').val();

            // Update the original textarea
            $(`textarea[data-student-id="${currentStudentId}"]`).val(reasonText);

            // Save to database
            saveReasonsToDatabase();

            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('reasonModal')).hide();

            // Show success message
            showAlert('Reason saved successfully!', 'success');
        });

        // showAlert function has been moved to the top of the script

        // Track if we're currently processing an update
        let isUpdating = false;

        // Allow unclicking present/absent/late/excused (clicking an already selected button clears it)
        $(document).on('mousedown', 'input[type="radio"][name^="status_"]', function() {
            $(this).data('wasChecked', $(this).prop('checked'));
        });
        // When clicking the visible label (the styled button), the radio's mousedown doesn't fire, so capture it here too
        $(document).on('mousedown', 'label[for^="present_"], label[for^="absent_"], label[for^="late_"], label[for^="excused_"]', function() {
            const $input = $('#' + $(this).attr('for'));
            $input.data('wasChecked', $input.prop('checked'));
        });
        $(document).on('click', 'input[type="radio"][name^="status_"]', function(e) {
            const wasChecked = $(this).data('wasChecked');
            if (wasChecked) {
                const $radio = $(this);
                const $group = $radio.closest('.btn-group');
                const studentId = $radio.closest('tr').data('student-id');
                const oldStatus = previousStatus[studentId];
                e.preventDefault();
                e.stopImmediatePropagation();
                if (isUpdating) return false;
                // Immediately clear - ensure no button stays in clicked/filled state
                $radio.prop('checked', false).removeAttr('checked');
                $group.find('input[type="radio"]').prop('checked', false).removeAttr('checked');
                $group.find('label').removeClass('active focus').blur();
                void $group[0].offsetWidth;
                setTimeout(() => {
                    $group.find('input[type="radio"]').prop('checked', false).removeAttr('checked');
                    $group.find('label').removeClass('active focus').blur();
                    void $group[0].offsetWidth;
                }, 0);
                isUpdating = true;
                updateStudentMark(studentId, null, oldStatus).then(success => {
                    if (success) {
                        previousStatus[studentId] = null;
                        showAlert('Attendance cleared', 'info');
                        try { refreshAttStats(); } catch(e2){}
                    } else {
                        // Save failed - revert to old value so user sees previous selection again
                        if (oldStatus) {
                            const $old = $radio.closest('tr').find(`input[type="radio"][value="${oldStatus}"]`);
                            $old.prop('checked', true);
                            $old.next('label').addClass('active');
                        }
                    }
                }).finally(() => { isUpdating = false; });
                return false;
            }
        });
        // Handle attendance status change - using event delegation to prevent multiple bindings
        $(document).off('change', 'input[type="radio"][name^="status_"]').on('change', 'input[type="radio"][name^="status_"]', function() {
            // Prevent multiple simultaneous updates
            if (isUpdating) {
                return;
            }

            const $radio = $(this);
            const studentId = $radio.closest('tr').data('student-id');
            const newStatus = $radio.val();
            const oldStatus = previousStatus[studentId];

            // Only update if status actually changed
            if (newStatus !== oldStatus) {
                isUpdating = true;

                updateStudentMark(studentId, newStatus, oldStatus).then(success => {
                    if (success) {
                        previousStatus[studentId] = newStatus;
                        try { refreshAttStats(); } catch(e2){}
                    } else {
                        // Revert the radio button if update failed
                        $radio.prop('checked', false);
                        if (oldStatus) {
                            $radio.closest('tr').find(`input[type="radio"][value="${oldStatus}"]`).prop('checked', true);
                        }
                    }
                }).finally(() => {
                    isUpdating = false;
                });
            }
        });

        // Handle class selection change
        $('#class_id').on('change', function() {
            const selectedOption = $(this).find('option:selected');
            const quarter = selectedOption.data('quarter');

            if (!quarter) {
                console.error('No quarter data found for selected class');
                return;
            }

            console.log('Class changed, new quarter:', quarter);

            // Capture the OLD context BEFORE updating the hidden input, and save the
            // currently displayed buttons to that OLD context only.
            const oldClassId = $(this).val();
            const oldQuarter = $('#quarter').val();
            const oldDate = $('#date').val();
            saveCurrentAttendanceState(oldClassId, oldQuarter, oldDate);

            // Update the quarter value
            $('#quarter').val(quarter);

            // Clear the current attendance display
            clearAttendanceDisplay();

            // Reload the page with new class/quarter
            const class_id = $(this).val();
            const date = $('#date').val();

            console.log(`Navigating to: class_id=${class_id}, date=${date}, quarter=${quarter}`);

            // Force a page reload to ensure clean state
            window.location.href = `./?page=assessments&tab=attendance&class_id=${class_id}&date=${date}&quarter=${quarter}&t=${new Date().getTime()}`;
        });

        // Handle form submission
        $('#attendanceFilter').on('submit', function(e) {
            e.preventDefault();
            saveCurrentAttendanceState();

            const class_id = $('#class_id').val();
            const date = $('#date').val();
            const quarter = $('#class_id option:selected').data('quarter') || $('#quarter').val();

            window.location.href = `./?page=assessments&tab=attendance&class_id=${class_id}&date=${date}&quarter=${quarter}`;
        });

            });

    // QR Scanner Functionality (scoped to selected class + quarter + date only)
    let html5QrCode = null;
    let isScannerRunning = false;
    let scannedStudents = new Map(); // key: classId_quarter_date_studentId -> scan data (never shared across class/quarter)
    let scanCount = 0;
    function getScanContext() {
        return {
            classId: String($('#class_id').val() || ''),
            quarter: String($('#quarter').val() || ''),
            date: String($('#date').val() || '')
        };
    }
    function getScanKey(studentId) {
        const ctx = getScanContext();
        return ctx.classId + '_' + ctx.quarter + '_' + ctx.date + '_' + String(studentId);
    }
    function resetScannerStateForContext() {
        scannedStudents.clear();
        scanCount = 0;
        updateScanDisplay();
    }
    let scannerStartTime = null;
    let timerInterval = null;
    let scannerDuration = 15 * 60 * 1000; // 15 minutes in milliseconds
    let isTimerExpired = false;

    // Initialize scanner controls
    $('#startScanner').on('click', function() {
        startScanner();
    });

    $('#stopScanner').on('click', function() {
        stopScanner();
    });

    $('#clearScans').on('click', function() {
        clearScannedStudents();
    });

    // Switching cameras while scanning restarts the stream on the new device.
    $(document).on('change', '#cameraSelect', function() {
        selectedCameraId = this.value || '';
        if (isScannerRunning || scannerStartPromise) {
            stopScanner();
            setTimeout(function() { startScanner(); }, 900);
        }
    });

    function getScannerConfig() {
        return { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };
    }
    function onScanSuccess(decodedText, decodedResult) { handleQRScan(decodedText); }
    function onScanFailure(errorMessage) { /* ignore per-frame errors */ }

    // Tracks an in-flight start() so Stop (or a second Start) can never orphan
    // a camera stream. An orphaned stream keeps the camera busy and the next
    // preview renders solid green/black frames instead of the live view.
    let scannerStartPromise = null;

    // Camera selection + blank-frame watchdog (detects a stuck/busy camera that
    // only delivers a solid color instead of the live view).
    let selectedCameraId = '';
    let frameWatchdogInterval = null;
    let frameWatchWarned = false;
    let hasDroidCamDevice = false;

    function getSelectedCameraConfig() {
        const sel = document.getElementById('cameraSelect');
        const id = (sel && sel.value) || selectedCameraId;
        if (id) return { deviceId: { exact: id } };
        return { facingMode: "environment" };
    }

    function refreshCameraList() {
        if (typeof Html5Qrcode === 'undefined' || !Html5Qrcode.getCameras) return Promise.resolve([]);
        return Html5Qrcode.getCameras().then(function(cams) {
            const sel = document.getElementById('cameraSelect');
            if (!sel) return cams || [];
            const prev = sel.value || selectedCameraId;
            sel.innerHTML = '<option value="">Default camera</option>';
            hasDroidCamDevice = false;
            (cams || []).forEach(function(c, i) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.label || ('Camera ' + (i + 1));
                sel.appendChild(opt);
                if (/droidcam/i.test(c.label || '')) hasDroidCamDevice = true;
            });
            const values = Array.from(sel.options).map(function(o) { return o.value; });
            sel.value = (prev && values.indexOf(prev) !== -1) ? prev : '';
            selectedCameraId = sel.value || '';
            return cams || [];
        }).catch(function() { return []; });
    }

    function startFrameWatchdog() {
        stopFrameWatchdog();
        frameWatchWarned = false;
        let stillCount = 0;
        frameWatchdogInterval = setInterval(function() {
            try {
                const v = document.querySelector('#qr-reader video');
                if (!v || v.readyState < 2 || !v.videoWidth) return;
                const cw = 96, ch = 54;
                let cv = document.getElementById('qrFrameProbe');
                if (!cv) {
                    cv = document.createElement('canvas');
                    cv.id = 'qrFrameProbe';
                    cv.width = cw; cv.height = ch;
                    cv.style.display = 'none';
                    document.body.appendChild(cv);
                }
                const ctx = cv.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(v, 0, 0, cw, ch);
                const d = ctx.getImageData(0, 0, cw, ch).data;
                const step = 16, samples = d.length / step;
                let sum = 0, sumSq = 0;
                for (let i = 0; i < d.length; i += step) {
                    const g = (d[i] + d[i + 1] + d[i + 2]) / 3;
                    sum += g; sumSq += g * g;
                }
                const mean = sum / samples;
                const variance = sumSq / samples - mean * mean;
                if (variance < 12) {
                    stillCount++;
                    if (stillCount >= 3 && !frameWatchWarned) {
                        frameWatchWarned = true;
                        let label = 'the selected camera';
                        try {
                            const track = v.srcObject && v.srcObject.getVideoTracks && v.srcObject.getVideoTracks()[0];
                            if (track && track.label) label = track.label;
                        } catch (e) {}
                        let steps = '1) Close any other tab or app using the camera (Zoom, Messenger, Camera app).<br>2) Pick a different camera in the <b>Camera</b> dropdown above, then press <b>Start Scanner</b> again.';
                        if (hasDroidCamDevice || /droidcam/i.test(label)) {
                            steps = '1) On the phone, keep the <b>DroidCam app open</b> (camera preview running).<br>2) On the laptop, the <b>DroidCam client must show Connected</b> (Wi-Fi: same network + phone IP, or USB mode) <i>before</i> pressing Start.<br>3) In the <b>Camera</b> dropdown above, choose the <b>DroidCam</b> device — a disconnected DroidCam feed is exactly this blank/green image.<br>4) Close any other tab or app using the camera, then press <b>Start Scanner</b> again.';
                        }
                        showScannerModal('The camera (<b>' + String(label).replace(/</g, '&lt;') + '</b>) is sending a blank image — a stuck, busy, or disconnected camera looks exactly like this (often solid green).<br><br>' + steps, 'warning', 'Blank Camera Image');
                    }
                } else {
                    stillCount = 0;
                }
            } catch (e) { /* probe must never break scanning */ }
        }, 1500);
    }

    function stopFrameWatchdog() {
        if (frameWatchdogInterval) {
            clearInterval(frameWatchdogInterval);
            frameWatchdogInterval = null;
        }
    }

    function teardownScanner() {
        stopFrameWatchdog();
        const inst = html5QrCode;
        html5QrCode = null;
        isScannerRunning = false;
        scannerStartPromise = null;
        if (inst) {
            try {
                const p = inst.stop();
                if (p && typeof p.then === 'function') {
                    p.catch(function(){}).finally(function() { try { inst.clear(); } catch (e) {} });
                } else {
                    try { inst.clear(); } catch (e) {}
                }
            } catch (err) {
                try { inst.clear(); } catch (e) {}
            }
        }
        $('#startScanner').prop('disabled', false).show();
        $('#stopScanner').hide();
    }

    // Best effort: never leave the camera running when leaving the page.
    window.addEventListener('pagehide', function() {
        try { stopTimer(); } catch (e) {}
        try { stopFrameWatchdog(); } catch (e) {}
        try {
            if (html5QrCode) {
                const p = html5QrCode.stop();
                if (p && typeof p.catch === 'function') p.catch(function(){});
            }
        } catch (e) {}
        html5QrCode = null;
        isScannerRunning = false;
        scannerStartPromise = null;
    });

    function startScanner() {
        if (!$('#class_id').val()) {
            showScannerModal('Please select a class before starting the scanner.', 'warning', 'Class Required');
            return;
        }
        if (typeof Html5Qrcode === 'undefined') {
            showScannerModal('QR library not loaded. Check internet connection and refresh the page.', 'danger', 'Not Ready');
            return;
        }
        // Single-flight: ignore Start while running or while a start is pending.
        // Double starts create a second stream on the same camera, which is the
        // usual cause of a solid green/black preview.
        if (isScannerRunning || scannerStartPromise) {
            return;
        }
        $('#startScanner').prop('disabled', true);
        // Reset timer
        scannerStartTime = new Date();
        isTimerExpired = false;
        $('#timerDisplay').show();
        $('#timerStatus').show();
        updateTimerDisplay();
        startTimer();

        const config = getScannerConfig();

        const showStartError = (err) => {
            scannerStartPromise = null;
            teardownScanner();
            const errStr = (err && err.toString ? err.toString() : '').toLowerCase();
            const errName = (err && err.name ? err.name : '').toLowerCase();
            let msg = 'Unable to start camera. Please allow camera permission and use HTTPS.';
            if (errStr.includes('notfounderror') || errName.includes('notfounderror') || errName.includes('overconstrained')) {
                msg = 'No camera found on this device!!!.';
            } else if (errName.includes('notreadable') || errStr.includes('notreadable') || errStr.includes('in use') || errStr.includes('busy') || errStr.includes('track')) {
                msg = 'Camera is already in use by another tab or app — that is what shows as a green/black screen. Close it there, then press Start Scanner again.';
            } else if (errName.includes('notallowed') || errStr.includes('permission') || errStr.includes('denied')) {
                msg = 'Camera permission was denied. Allow camera access for this site, then press Start Scanner again.';
            }
            showScannerModal(msg, 'danger', 'Camera Error');
            stopTimer();
        };

        const doStart = (isRetry) => {
            if (!html5QrCode) html5QrCode = new Html5Qrcode("qr-reader");
            // Ensure the reader element is visible and has size (required for camera)
            const el = document.getElementById('qr-reader');
            if (el) { el.style.minHeight = '300px';
             }
            scannerStartPromise = html5QrCode.start(
                getSelectedCameraConfig(),
                config,
                onScanSuccess,
                onScanFailure
            );
            scannerStartPromise.then(() => {
                isScannerRunning = true;
                scannerStartPromise = null;
                $('#startScanner').prop('disabled', false).hide();
                $('#stopScanner').show();
                startFrameWatchdog();
                // Permission is granted now, so real camera names become available.
                refreshCameraList();
            }).catch((err) => {
                const errName = (err && err.name ? err.name : '').toLowerCase();
                const errStr = (err && err.toString ? err.toString() : '').toLowerCase();
                const sel = document.getElementById('cameraSelect');
                const usingFacingMode = !((sel && sel.value) || selectedCameraId);
                const noSuchCamera = errName.includes('overconstrained') || errName.includes('notfounderror');
                if (!isRetry && usingFacingMode && noSuchCamera && typeof Html5Qrcode.getCameras === 'function') {
                    // No rear camera (typical laptop / virtual cam like DroidCam):
                    // retry once with a concrete device — prefer DroidCam when present.
                    Html5Qrcode.getCameras().then(function(cams) {
                        if (cams && cams.length) {
                            let pick = cams[0];
                            for (let i = 0; i < cams.length; i++) {
                                if (/droidcam/i.test(cams[i].label || '')) { pick = cams[i]; break; }
                            }
                            selectedCameraId = pick.id;
                            if (sel) sel.value = selectedCameraId;
                            try { html5QrCode.clear(); } catch (e) {}
                            html5QrCode = null;
                            doStart(true);
                        } else {
                            showStartError(err);
                        }
                    }).catch(function() { showStartError(err); });
                    return;
                }
                showStartError(err);
            });
        };

        if (html5QrCode && isScannerRunning) {
            // Already running — stop/clear previous session then restart with proper config
            const inst = html5QrCode;
            try {
                inst.stop().then(() => { return inst.clear().catch(()=>{}); }).then(() => {
                    if (html5QrCode === inst) { html5QrCode = null; }
                    isScannerRunning = false;
                    doStart();
                }).catch(() => {
                    try { inst.clear(); } catch(e){}
                    if (html5QrCode === inst) { html5QrCode = null; }
                    isScannerRunning = false;
                    doStart();
                });
            } catch (e) {
                try { inst.clear(); } catch(e2){}
                if (html5QrCode === inst) { html5QrCode = null; }
                isScannerRunning = false;
                doStart();
            }
        } else if (html5QrCode) {
            // Exists but not running — just clear and restart
            try { html5QrCode.clear(); } catch(e){}
            html5QrCode = null;
            doStart();
        } else {
            doStart();
        }
    }

    function stopScanner() {
        // Stop timer immediately for UI feedback
        stopTimer();
        // A start is still pending: wait for it, then tear down so the stream
        // it opens is stopped instead of orphaned (orphans = busy camera).
        if (scannerStartPromise) {
            scannerStartPromise.catch(function(){}).finally(function() {
                teardownScanner();
            });
            return;
        }
        if (!html5QrCode || !isScannerRunning) {
            // Not running — just reset UI, don't call stop() (prevents "Cannot stop, scanner is not running")
            teardownScanner();
            return;
        }
        teardownScanner();
    }

    function startTimer() {
        // Update timer every second
        timerInterval = setInterval(() => {
            updateTimerDisplay();

            // Check if timer has expired
            const elapsed = new Date() - scannerStartTime;
            if (elapsed >= scannerDuration && !isTimerExpired) {
                isTimerExpired = true;
                onTimerExpired();
            }
        }, 1000);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        $('#timerDisplay').hide();
        $('#timerStatus').hide();
    }

    function updateTimerDisplay() {
        if (!scannerStartTime) return;

        const elapsed = new Date() - scannerStartTime;
        const remaining = Math.max(0, scannerDuration - elapsed);

        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);

        $('#timerText').text(`${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`);

        // Update status text
        const $statusText = $('#statusText');
        if (isTimerExpired) {
            $statusText.text('Marking as Late');
            var $banner = $('#timerStatus').find('.horizon-flash-banner');
            $banner.removeClass('horizon-flash-info').addClass('horizon-flash-warning');
        } else {
            $statusText.text('Marking as Present');
            var $banner2 = $('#timerStatus').find('.horizon-flash-banner');
            $banner2.removeClass('horizon-flash-warning').addClass('horizon-flash-info');
        }
    }

    function onTimerExpired() {
        // Timer expired - now marking students as late
        showAlert('Timer expired! Students will now be marked as Late.', 'warning');
        updateTimerDisplay();

        // Play a sound notification if available
        const audio = new Audio('sounds/Pop_Effect.mp3');
        audio.play().catch(e => console.log('Could not play sound:', e));
    }

    let lastScanAt = 0;
    // Parses every QR format students.php produces (generate_qrcode.php):
    //   "Student ID: 1015\nName: ...\nStudent Number: 23-09693\nClass: 32"
    // plus URL ?student_id=, bare numeric id, or bare student number.
    // Returns {student_id, student_number} (either may be '').
    function parseScannedPayload(decodedText) {
        const raw = String(decodedText || '').trim();
        let studentId = '', studentNumber = '';
        if (!raw) return { student_id: studentId, student_number: studentNumber };
        let m = raw.match(/Student ID:\s*(\S+)/i);
        if (m) studentId = m[1].trim();
        m = raw.match(/Student Number:\s*([^\s]+)/i);
        if (m) studentNumber = m[1].trim();
        if (studentId || studentNumber) return { student_id: studentId, student_number: studentNumber };
        if (raw.includes('student_id=')) {
            m = raw.match(/student_id=([^&\s]+)/);
            if (m) studentId = decodeURIComponent(m[1]).trim();
        } else if (raw.includes('?') || raw.includes('/')) {
            try {
                // Try as URL first
                const u = new URL(raw, window.location.origin);
                const param = u.searchParams.get('student_id');
                if (param) studentId = param.trim();
                else {
                    const parts = u.pathname.split('/').filter(Boolean);
                    if (parts.length) studentId = parts[parts.length - 1].trim();
                }
            } catch(e) {
                const parts = raw.split('?')[0].split('/').filter(Boolean);
                if (parts.length) studentId = parts[parts.length - 1].trim();
            }
        } else {
            studentId = raw;
        }
        // Bare XX-XXXXX student number with no labels
        if (!studentNumber && /^\d{2}-\d{4,6}$/.test(studentId)) {
            studentNumber = studentId;
        }
        return { student_id: studentId, student_number: studentNumber };
    }
    function handleQRScan(decodedText) {
        const now = Date.now();
        if (now - lastScanAt < 1500) return; // debounce rapid duplicate frames
        lastScanAt = now;
        if (navigator.vibrate) navigator.vibrate(80);
        const parsed = parseScannedPayload(decodedText);
        if (!parsed.student_id && !parsed.student_number) return;
        validateAndRecordScan(parsed.student_id, parsed.student_number);
    }

    function validateAndRecordScan(studentId, studentNumber) {
        const classId = $('#class_id').val();
        const date = $('#date').val();
        const quarter = $('#quarter').val();

        if (!classId || !date || !quarter) {
            showScannerModal('Please select a class first.', 'warning', 'Class Required');
            return;
        }

        // Check if student exists in current class via AJAX
        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'validate_student_scan',
                student_id: studentId || '',
                student_number: studentNumber || '',
                class_id: classId,
                quarter: quarter
            },
            dataType: 'json',
            success: function(resp) {
                if (resp.status === 'success') {
                    recordStudentScan(resp.student_data);
                } else {
                    showScannerModal(resp.msg || 'Student not found in this class.', 'danger', 'Not Found');
                }
            },
            error: function() {
                showScannerModal('Error validating student. Please try again.', 'danger', 'Network Error');
            }
        });
    }

    function recordStudentScan(studentData) {
        const now = new Date();
        const timestamp = now.toISOString();
        const localTimestamp = now.toLocaleString();
        const ctx = getScanContext();

        // Scoped to selected class + quarter + date (same student in another class/quarter is a separate scan)
        const today = ctx.date;
        const scanKey = getScanKey(studentData.student_id);

        if (scannedStudents.has(scanKey)) {
            showAlert(`${studentData.name} already scanned for this class / quarter / date.`, 'info');
            return;
        }

        // Determine attendance status based on timer
        let attendanceStatus = 'present';
        let statusBadge = 'bg-success';
        let statusText = 'Present';

        if (isTimerExpired) {
            attendanceStatus = 'late';
            statusBadge = 'bg-warning';
            statusText = 'Late';
        }

        // Store scan data (scoped to this class + quarter + date)
        scannedStudents.set(scanKey, {
            ...studentData,
            scan_time: timestamp,
            local_time: localTimestamp,
            date: today,
            class_id: ctx.classId,
            quarter: ctx.quarter,
            attendance_status: attendanceStatus,
            status_badge: statusBadge,
            status_text: statusText
        });

        scanCount++;
        updateScanDisplay();

        // Update attendance based on timer status. The success popup only
        // shows after the save actually lands; on failure the scan entry is
        // rolled back so an immediate retry is allowed.
        updateAttendanceFromScan(studentData.student_id, attendanceStatus).then(function(ok) {
            if (ok) {
                showScanSuccess(studentData.name, 'Marked as ' + statusText, attendanceStatus);
            } else {
                scannedStudents.delete(scanKey);
                updateScanDisplay();
                showAlert('Scan read but FAILED to save. Check connection and scan again.', 'danger');
            }
        });
    }

    function updateAttendanceFromScan(studentId, status) {
        const classId = $('#class_id').val();
        const date = $('#date').val();
        const quarter = $('#quarter').val();
        if (!classId || !date || !quarter) return Promise.resolve(false);

        // Update the radio for this student only (scoped by student row on this class/quarter page)
        const $radio = $(`input[name="status_${studentId}"][value="${status}"]`);
        if ($radio.length && !$radio.prop('checked')) {
            $radio.prop('checked', true);
        }
        previousStatus[studentId] = status;

        // Save ALL currently-checked buttons in one scoped write
        // (never single-student save: backend DELETEs the whole class+quarter+date set first,
        // so a single-row write would wipe classmates).
        const saved = saveCurrentAttendanceState();

        // Keep marks in sync (same endpoint the manual buttons use)
        const formData = new URLSearchParams();
        formData.append('student_id', studentId);
        formData.append('class_id', classId);
        formData.append('date', date);
        formData.append('status', status);
        formData.append('quarter', quarter);
        fetch('update_marks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        }).catch(function(err) { console.warn('Marks sync failed:', err); });

        return Promise.resolve(saved).then(function(ok) { try { refreshAttStats(); } catch(e){} return ok !== false; });
    }

    function updateScanDisplay() {
        const ctx = getScanContext();
        const prefix = ctx.classId + '_' + ctx.quarter + '_' + ctx.date + '_';
        const entries = Array.from(scannedStudents.entries()).filter(function(entry) {
            return entry[0].indexOf(prefix) === 0;
        });
        scanCount = entries.length;
        $('#scanCount').text(`${scanCount} scanned`);

        const container = $('#scannedStudents');

        if (entries.length === 0) {
            container.html(`
                <div class="text-muted text-center py-4">
                    <i class="fas fa-user-clock fa-3x mb-2"></i>
                    <p>No students scanned yet</p>
                </div>
            `);
            return;
        }

        let html = '';

        // Sort by scan time (most recent first)
        const sortedScans = entries.sort((a, b) =>
            new Date(b[1].scan_time) - new Date(a[1].scan_time)
        );

        sortedScans.forEach(([key, data]) => {
            html += `
                <div class="scan-item">
                    <div>
                        <strong>${data.name}</strong>
                        <br>
                        <small>${data.student_number}</small>
                    </div>
                    <div class="text-end">
                        <span class="badge ${data.status_badge}">${data.status_text}</span>
                        <br>
                        <small>${data.local_time}</small>
                    </div>
                </div>
            `;
        });

        container.html(html);
    }

    function clearScannedStudents() {
        const ctx = getScanContext();
        const prefix = ctx.classId + '_' + ctx.quarter + '_' + ctx.date + '_';
        let hasScoped = false;
        scannedStudents.forEach(function(val, key) {
            if (key.indexOf(prefix) === 0) hasScoped = true;
        });
        if (!hasScoped) return;

        if (confirm('Clear scanned students for this class / quarter / date?')) {
            const toDelete = [];
            scannedStudents.forEach(function(val, key) {
                if (key.indexOf(prefix) === 0) toDelete.push(key);
            });
            toDelete.forEach(function(key) { scannedStudents.delete(key); });
            updateScanDisplay();
            showAlert('Scanned students cleared.', 'info');
        }
    }

    // Initialize scanner display
    updateScanDisplay();

    // Function to handle student status change
    function handleStatusChange(selectElement) {
        const studentId = selectElement.dataset.id;
        const newStatus = selectElement.value;
        const oldStatus = selectElement.dataset.status;

        // Update pill class
        selectElement.classList.remove('st-Active', 'st-Dropped', 'st-NoSub', 'st-NoExam');
        selectElement.classList.add('st-' + newStatus);

        // Update dataset status
        selectElement.dataset.status = newStatus;

        // Toggle dropped state (no row tint — the red Dropped pill + badge identify the row)
        const row = selectElement.closest('tr');
        if (newStatus === 'Dropped') {
            // Disable attendance radio buttons for dropped students
            row.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.disabled = true;
            });

            // Disable reason textarea for dropped students
            const reasonInput = row.querySelector('.reason-input');
            if (reasonInput) {
                reasonInput.disabled = true;
                reasonInput.value = '';
            }
        } else {
            // Student is being set back to Active (or non-Dropped status)
            // Enable attendance radio buttons for active students
            row.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.disabled = false;
                // Make radio buttons and labels visible
                radio.style.display = '';
                const label = document.querySelector(`label[for="${radio.id}"]`);
                if (label) {
                    label.style.display = '';
                }
            });

            // Show the attendance radio button group if it was replaced by the Dropped badge
            const attendanceCell = row.querySelector('td:nth-child(3)');
            if (attendanceCell && !attendanceCell.querySelector('input[type="radio"]')) {
                // Remove the "Dropped" badge and show radio buttons
                attendanceCell.innerHTML = `
                    <div class="btn-group btn-group-sm att-seg w-100" role="group">
                        <input type="radio" class="btn-check"
                            name="status_${studentId}"
                            id="present_${studentId}"
                            value="present"
                            autocomplete="off">
                        <label class="btn btn-outline-success flex-grow-1"
                            for="present_${studentId}">
                            <i class="fas fa-check me-1"></i>Present
                        </label>

                        <input type="radio" class="btn-check"
                            name="status_${studentId}"
                            id="absent_${studentId}"
                            value="absent"
                            autocomplete="off">
                        <label class="btn btn-outline-danger flex-grow-1"
                            for="absent_${studentId}">
                            <i class="fas fa-times me-1"></i>Absent
                        </label>

                        <input type="radio" class="btn-check"
                            name="status_${studentId}"
                            id="late_${studentId}"
                            value="late"
                            autocomplete="off">
                        <label class="btn btn-outline-warning flex-grow-1"
                            for="late_${studentId}">
                            <i class="fas fa-clock me-1"></i>Late
                        </label>

                        <input type="radio" class="btn-check"
                            name="status_${studentId}"
                            id="excused_${studentId}"
                            value="excused"
                            autocomplete="off">
                        <label class="btn btn-outline-info flex-grow-1"
                            for="excused_${studentId}">
                            <i class="fas fa-user-clock me-1"></i>Excused
                        </label>
                    </div>
                `;
            }

            // Enable reason textarea for active students
            const reasonInput = row.querySelector('.reason-input');
            if (reasonInput) {
                reasonInput.disabled = false;
                // Also show the reason input group if it was replaced
                const reasonCell = row.querySelector('td:nth-child(5)');
                if (reasonCell && !reasonCell.querySelector('.reason-input')) {
                    reasonCell.innerHTML = `
                        <div class="input-group input-group-sm">
                            <textarea
                                   class="form-control reason-input"
                                   data-student-id="${studentId}"
                                   name="reason_${studentId}"
                                   placeholder="Enter reason"
                                   rows="1"></textarea>
                            <button type="button"
                                    class="btn btn-secondary expand-reason-btn"
                                    data-student-id="${studentId}"
                                    data-student-name="${row.querySelector('td:nth-child(2)').textContent.trim()}"
                                    title="Expand for easier editing">
                                <i class="fas fa-expand"></i>
                            </button>
                        </div>
                    `;
                }
            }
        }
        try { refreshAttStats(); } catch(e){}

        // Save status change to database via AJAX
        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'update_student_status',
                id: studentId,
                status: newStatus
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    showAlert(`Student status updated to ${newStatus}`, 'success');
                } else {
                    showAlert('Error updating student status: ' + response.message, 'danger');
                    // Revert the dropdown if update failed
                    selectElement.value = oldStatus;
                    selectElement.dataset.status = oldStatus;
                    selectElement.classList.remove('st-Active', 'st-Dropped', 'st-NoSub', 'st-NoExam');
                    selectElement.classList.add('st-' + oldStatus);
                }
            },
            error: function() {
                showAlert('Error updating student status. Please try again.', 'danger');
                // Revert the dropdown if update failed
                selectElement.value = oldStatus;
                selectElement.dataset.status = oldStatus;
                selectElement.classList.remove('st-Active', 'st-Dropped', 'st-NoSub', 'st-NoExam');
                selectElement.classList.add('st-' + oldStatus);
            }
        });
    }

    // ==================== Student Confirmation Attendance Flow ====================
    (function(){
        const $startBtn = $('#startAttendanceBtn');
        const $endBtn = $('#endAttendanceBtn');
        const $status = $('#attendanceSessionStatus');
        const $counts = $('#attendanceSessionCounts');
        const $live = $('#attendanceSessionLive');
        if(!$startBtn.length) return;
        let pollTimer = null;
        let lastConfirmed = -1;

        function getCtx(){
            return {
                class_id: $('#class_id').val() || '',
                quarter: $('#quarter').val() || '',
                date: $('#date').val() || ''
            };
        }

        function renderSession(data){
            if(!data || !data.active){
                $startBtn.show().prop('disabled', false).html('<i class="fas fa-play me-1"></i> Start Attendance');
                $endBtn.hide();
                $status.text('No active session — click Start to request confirmations from students in this class.');
                $counts.hide();
                $live.hide();
                lastConfirmed = -1;
                return;
            }
            $startBtn.hide();
            $endBtn.show();
            $status.html('<i class="fas fa-broadcast-tower me-1 text-success"></i> Attendance active — waiting for students to confirm. <small class="text-muted">Present will auto-check when they confirm.</small>');
            const pendingTxt = (typeof data.pending !== 'undefined') ? data.pending : (data.total - data.confirmed);
            $counts.show().text(data.confirmed + ' / ' + data.total + ' confirmed • ' + pendingTxt + ' pending • ' + data.present_count + ' present');
            $live.show();
            // If new confirmations arrived, force attendance refresh so Present renders immediately
            if(lastConfirmed !== -1 && data.confirmed > lastConfirmed){
                if(typeof refreshAttendanceFromServer === 'function') refreshAttendanceFromServer();
                showAlert(data.confirmed - lastConfirmed + ' student(s) confirmed attendance — Present auto-checked.', 'success');
            }
            lastConfirmed = data.confirmed;
            // Highlight rows that were confirmed via student app (add subtle badge + Unconfirm)
            if(data.requests && data.requests.length){
                data.requests.forEach(function(r){
                    const $row = $(`tr[data-student-id="${r.student_id}"]`);
                    if(!$row.length) return;
                    let $badge = $row.find('.student-confirmed-badge');
                    let $unBtn = $row.find('.student-unconfirm-btn');
                    if(r.status === 'confirmed'){
                        if(!$badge.length){
                            $row.find('td:nth-child(2)').append(' <span class="badge bg-success student-confirmed-badge" style="font-size:.65rem"><i class="fas fa-mobile-screen me-1"></i>Confirmed</span> <button type="button" class="btn btn-outline-danger btn-sm student-unconfirm-btn" data-student-id="'+r.student_id+'" title="Unconfirm — revert to pending & clear Present" style="font-size:.65rem; padding:1px 6px; border-radius:999px; line-height:1.2"><i class="fas fa-undo me-1"></i>Unconfirm</button>');
                        }
                        // Ensure Present radio is checked (in case polling lag)
                        const $present = $row.find(`input[value="present"]`);
                        if($present.length && !$present.prop('checked')){
                            $present.prop('checked', true);
                        }
                    } else {
                        $badge.remove();
                        $unBtn.remove();
                        $row.find('.student-unconfirm-btn').remove();
                        // Return Present button to outline (inactive) — clear checked + solid fill
                        const $group = $row.find('.btn-group');
                        const $presentRadio = $row.find('input[value="present"]');
                        // Only clear if this row was previously confirmed (had badge) — avoid wiping manual present
                        // Check if the radio was auto-checked due to confirmation: if badge existed, we know it was confirmed
                        // So we uncheck and force outline style
                        if($presentRadio.length && $presentRadio.prop('checked')){
                            $presentRadio.prop('checked', false).removeAttr('checked');
                        }
                        // If no radio should be checked after unconfirm, ensure all are unchecked and labels are outline
                        const anyChecked = $row.find('input[type="radio"]:checked').length > 0;
                        if(!anyChecked){
                            $group.find('input[type="radio"]').prop('checked', false).removeAttr('checked');
                            $group.find('label').removeClass('active focus').blur();
                            // Clear inline styles shortly after so future selections get proper filled color via CSS
                            setTimeout(()=>{ try { refreshAttStats(); } catch(e){} }, 50);
                        }
                    }
                });
            }
            try { refreshAttStats(); } catch(e){}
        }

        function pollSession(){
            const ctx = getCtx();
            if(!ctx.class_id || !ctx.quarter || !ctx.date) return;
            $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {a: 'get_attendance_session_status', class_id: ctx.class_id, quarter: ctx.quarter, date: ctx.date},
                dataType: 'json'
            }).done(function(res){
                if(res.status === 'success'){
                    renderSession(res);
                }
            });
        }

        $startBtn.on('click', function(){
            const ctx = getCtx();
            if(!ctx.class_id || !ctx.quarter || !ctx.date){
                showAlert('Select a class first.', 'warning');
                return;
            }
            $startBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Starting…');
            $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {a: 'start_attendance_session', class_id: ctx.class_id, quarter: ctx.quarter, date: ctx.date},
                dataType: 'json'
            }).done(function(res){
                if(res.status === 'success'){
                    showAlert('Attendance session started for ' + res.students + ' students. Students will see a confirmation on their portal.', 'success');
                    pollSession();
                } else {
                    showAlert(res.msg || 'Failed to start session', 'danger');
                    $startBtn.prop('disabled', false).html('<i class="fas fa-play me-1"></i> Start Attendance');
                }
            }).fail(function(){
                showAlert('Network error starting attendance', 'danger');
                $startBtn.prop('disabled', false).html('<i class="fas fa-play me-1"></i> Start Attendance');
            });
        });

        $endBtn.on('click', function(){
            const ctx = getCtx();
            if(!ctx.class_id || !ctx.quarter || !ctx.date){
                showAlert('Select a class first.', 'warning');
                return;
            }
            // Fetch latest counts to show in modal before confirming
            $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {a: 'get_attendance_session_status', class_id: ctx.class_id, quarter: ctx.quarter, date: ctx.date},
                dataType: 'json'
            }).always(function(res){
                let pending = 0, total = 0, confirmed = 0;
                try{
                    if(res && res.status==='success' && typeof res.pending!=='undefined'){
                        total = res.total; confirmed = res.confirmed; pending = res.pending;
                        $('#endAttendanceModalCounts').text(pending + ' pending of ' + total + ' students will be marked absent. ' + confirmed + ' already confirmed will remain present.');
                    } else if(res && res.status==='success' && typeof res.total!=='undefined'){
                        total = res.total; confirmed = res.confirmed; pending = res.total - res.confirmed;
                        $('#endAttendanceModalCounts').text(pending + ' pending of ' + total + ' students will be marked absent. ' + confirmed + ' already confirmed will remain present.');
                    } else {
                        $('#endAttendanceModalCounts').text('Pending students will be automatically marked absent.');
                    }
                } catch(e){ $('#endAttendanceModalCounts').text('Pending students will be automatically marked absent.'); }
                new bootstrap.Modal(document.getElementById('endAttendanceModal')).show();
            });
        });
        $('#confirmEndAttendanceBtn').on('click', function(){
            const ctx = getCtx();
            const $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Ending…');
            $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {a: 'end_attendance_session', class_id: ctx.class_id, quarter: ctx.quarter, date: ctx.date},
                dataType: 'json'
            }).done(function(res){
                bootstrap.Modal.getInstance(document.getElementById('endAttendanceModal')).hide();
                showAlert(res.msg || 'Session ended — pending set to absent', 'info');
                pollSession();
                if(typeof refreshAttendanceFromServer === 'function') refreshAttendanceFromServer();
            }).fail(function(){
                showAlert('Failed to end session', 'danger');
            }).always(function(){
                $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i> Yes, End Session');
            });
        });

        // Faculty unconfirm — revert a student-confirmed Present back to pending
        $(document).on('click', '.student-unconfirm-btn', function(){
            const studentId = $(this).data('student-id');
            const ctx = getCtx();
            if(!studentId || !ctx.class_id || !ctx.quarter || !ctx.date) return;
            if(!confirm('Unconfirm attendance for this student? This will clear Present and allow the student to confirm again.')) return;
            const $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {a: 'unconfirm_attendance', class_id: ctx.class_id, quarter: ctx.quarter, date: ctx.date, student_id: studentId},
                dataType: 'json'
            }).done(function(res){
                if(res.status==='success'){
                    showAlert('Unconfirmed — present cleared', 'info');
                    pollSession();
                    if(typeof refreshAttendanceFromServer === 'function') refreshAttendanceFromServer();
                } else {
                    showAlert(res.msg || 'Failed to unconfirm', 'danger');
                    $btn.prop('disabled', false).html('<i class="fas fa-undo me-1"></i>Unconfirm');
                }
            }).fail(function(){
                showAlert('Network error', 'danger');
                $btn.prop('disabled', false).html('<i class="fas fa-undo me-1"></i>Unconfirm');
            });
        });

        // If faculty manually unchecks a Confirmed student's Present (via radio), also revert the request to pending
        // This keeps the two sources in sync — otherwise attendance would be cleared but request stays confirmed.
        $(document).on('click', 'input[type="radio"][name^="status_"]', function(e){
            const wasChecked = $(this).data('wasChecked');
            if(!wasChecked) return; // only when unchecking
            const $radio = $(this);
            const studentId = $radio.closest('tr').data('student-id');
            const $row = $radio.closest('tr');
            if(!$row.find('.student-confirmed-badge').length) return; // only for confirmed rows
            const ctx = getCtx();
            // Fire unconfirm in background (don't block the radio clear which already happened)
            $.ajax({
                url: './Actions.php',
                method: 'POST',
                data: {a: 'unconfirm_attendance', class_id: ctx.class_id, quarter: ctx.quarter, date: ctx.date, student_id: studentId},
                dataType: 'json'
            }).done(function(res){
                if(res.status==='success'){
                    showAlert('Unconfirmed via radio — student can confirm again', 'info');
                    pollSession();
                }
            });
        });

        // Initial poll and live interval (3s) — separate from the 5s attendance sync
        pollSession();
        pollTimer = setInterval(pollSession, 3000);
        $(window).on('pagehide', function(){ clearInterval(pollTimer); });
        // Refresh session status when class/quarter/date change
        $('#class_id, #quarter, #date').on('change', function(){ setTimeout(pollSession, 300); });
    })();
    </script>
