<?php
// Secure quiz-taking page. Tab switch / minimize / new-tab backgrounding is
// detected (Page Visibility + blur), logged as a violation, and auto-submits
// after 3 violations. Copy, right-click, print and devtools shortcuts are blocked.
if (session_status() === PHP_SESSION_NONE) {
    $p = '/students/';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($d !== '' && $d !== '.' && $d !== '/') $p = $d . '/';
    }
    session_set_cookie_params(['lifetime' => 0, 'path' => $p, 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
    header('Location: login.php');
    exit();
}
require_once(__DIR__ . '/../quiz_schema.php');
$student_number = (string)$_SESSION['student_number'];
$quiz_id = (int)($_GET['q'] ?? 0);
$class_id = (int)($_GET['c'] ?? 0);
$db_key = trim($_GET['k'] ?? '');
$gate_error = '';
$quiz_meta = null;
if ($quiz_id <= 0 || $class_id <= 0 || $db_key === '') {
    $gate_error = 'Invalid quiz link. Open the quiz from your dashboard notification.';
} else {
    foreach (quiz_candidate_db_files() as $f) {
        if (quiz_db_key($f) !== $db_key) continue;
        try {
            $pdo = new PDO('sqlite:' . $f);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            quiz_ensure_schema($pdo);
            $st = $pdo->prepare("SELECT * FROM quiz_list WHERE quiz_id = :q AND class_id = :c LIMIT 1");
            $st->execute([':q' => $quiz_id, ':c' => $class_id]);
            $q = $st->fetch(PDO::FETCH_ASSOC);
            if (!$q) { $gate_error = 'Quiz not found.'; break; }
            if ((int)$q['is_active'] !== 1) { $gate_error = 'This quiz is no longer active.'; break; }
            $se = $pdo->prepare("SELECT student_id, name FROM student_list WHERE student_number = :sn AND class_id = :c LIMIT 1");
            $se->execute([':sn' => $student_number, ':c' => $class_id]);
            $stu = $se->fetch(PDO::FETCH_ASSOC);
            if (!$stu) { $gate_error = 'This quiz is for a different class — it is not assigned to you.'; break; }
            $asgGate = json_decode($q['assigned_student_ids'] ?? '', true);
            if (is_array($asgGate) && count($asgGate)) {
                $okGate = false;
                foreach ($asgGate as $aid) { if ((int)$aid === (int)$stu['student_id']) { $okGate = true; break; } }
                if (!$okGate) { $gate_error = 'This quiz was not assigned to you.'; break; }
            }
            $nq = $pdo->prepare("SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = :q");
            $nq->execute([':q' => $quiz_id]);
            $quiz_meta = ['title' => $q['title'], 'quiz_type_label' => quiz_type_label($q['quiz_type']), 'num_items' => (int)$nq->fetchColumn(), 'time_limit_minutes' => (int)$q['time_limit_minutes']];
        } catch (Exception $e) { $gate_error = 'Could not open the quiz — please retry.'; }
        break;
    }
    if ($quiz_meta === null && $gate_error === '') $gate_error = 'Quiz not found for your class.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Take Quiz — HORIZON Student Portal</title>
<script>
    // Apply saved Horizon theme before first paint (shares key with portal).
    try {
        if (localStorage.getItem('horizon-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (error) {}
</script>
<link rel="icon" href="../favicon.ico" type="image/x-icon">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link href="../css/horizon-theme.css" rel="stylesheet">
<link href="../Font-Awesome-master/css/all.min.css" rel="stylesheet">
<style>
.horizon-page-bg{background:var(--horizon-bg-main);min-height:100vh}
.quiz-shell{max-width:860px;margin:0 auto;padding:14px 12px 40px}
.quiz-q{border:1px solid var(--horizon-card-border);border-radius:14px;background:var(--horizon-card-bg);padding:16px;margin-bottom:12px}
.quiz-q h6{font-weight:800;color:var(--horizon-text-title);font-size:14px;line-height:1.5;white-space:pre-line}
.quiz-opt{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--horizon-card-border);border-radius:10px;padding:10px 12px;margin-top:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--horizon-text-title)}
.quiz-opt:hover{border-color:var(--horizon-brand-300)}
.quiz-opt.sel{border-color:var(--horizon-brand-500);background:var(--horizon-brand-50)}
/* Dark mode: keep selected choice readable (light text needs a dark tint, not pale lavender) */
html[data-theme="dark"] .quiz-opt.sel{background:rgba(67,24,255,.32);border-color:#818cf8}
html[data-theme="dark"] .quiz-opt:hover{border-color:#818cf8}
#secureBar{position:sticky;top:0;z-index:50}
.violation-shake{animation:vshake .4s}
@keyframes vshake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
#gateOverlay{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55);padding:16px}
html[data-theme="dark"] .quiz-shell .form-control{background-color:var(--horizon-input-bg);border-color:var(--horizon-input-border);color:var(--horizon-input-text)}
html[data-theme="dark"] .quiz-shell .form-control::placeholder{color:var(--horizon-text-muted)}
/* ── Professional exam organization ── */
.quiz-head{border:1px solid var(--horizon-card-border);border-radius:14px;background:var(--horizon-card-bg);box-shadow:var(--horizon-card-shadow);padding:16px;margin-bottom:12px}
.quiz-progress{height:8px;border-radius:999px;background:var(--horizon-table-hover);overflow:hidden}
.quiz-progress > div{height:100%;width:0;background:var(--horizon-brand-linear);transition:width .25s ease}
.quiz-pal-grid{display:flex;flex-wrap:wrap;gap:6px}
.quiz-pal{width:34px;height:34px;border-radius:9px;border:1px solid var(--horizon-card-border);background:var(--horizon-card-bg);color:var(--horizon-text-muted);font-weight:800;font-size:12px;cursor:pointer;transition:all .15s}
.quiz-pal:hover{border-color:var(--horizon-brand-500);color:var(--horizon-brand-500)}
.quiz-pal.answered{background:var(--horizon-brand-500);border-color:var(--horizon-brand-500);color:#fff}
.quiz-q-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap}
.quiz-opt input{position:absolute;opacity:0;width:1px;height:1px}
.quiz-opt:focus-within{border-color:var(--horizon-brand-500)}
.opt-letter{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;min-width:26px;border-radius:8px;background:var(--horizon-table-hover);color:var(--horizon-text-title);font-weight:800;font-size:12px}
.quiz-opt.sel .opt-letter{background:var(--horizon-brand-500);color:#fff}
#quizSubmitOverlay{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.55);padding:16px}
/* ── Modern answer form: big touch targets, icon inputs, sticky mobile submit ── */
.quiz-opt{min-height:52px;padding:13px 14px;border-radius:13px;border-width:1.5px;transition:border-color .15s,background .15s,box-shadow .15s}
.quiz-opt:active{transform:scale(.995)}
.quiz-opt .opt-letter{width:30px;height:30px;min-width:30px;border-radius:10px;font-size:13px}
.quiz-opt.sel{box-shadow:0 4px 14px rgba(67,24,255,.18)}
.quiz-ident-wrap{position:relative;margin-top:8px}
.quiz-ident-wrap>i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--horizon-text-muted);font-size:13px;pointer-events:none}
.quiz-ident-wrap .quiz-ident{height:50px;border-radius:12px;border:1.5px solid var(--horizon-card-border);font-weight:600;font-size:14px;padding-left:40px;transition:border-color .15s,box-shadow .15s}
.quiz-ident-wrap .quiz-ident:focus{border-color:var(--horizon-brand-500);box-shadow:0 0 0 3px rgba(67,24,255,.15);outline:none}
.quiz-ident-wrap.tall>i{top:20px;transform:none}
.quiz-ident-wrap textarea.quiz-ident{height:auto;min-height:140px;padding-top:12px;line-height:1.6;resize:vertical}
html[data-theme="dark"] .quiz-ident-wrap .quiz-ident{background-color:var(--horizon-input-bg);border-color:var(--horizon-input-border);color:var(--horizon-input-text)}
#quizStickyBar{display:none}
@media (max-width:768px){
    #quizStickyBar{display:flex;position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:1500;align-items:center;gap:12px;background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);border-radius:16px;box-shadow:0 16px 40px rgba(15,23,42,.25);padding:10px 12px}
    #quizStickyBar .quiz-progress{flex:1 1 auto}
    .quiz-shell{padding-bottom:120px !important}
}
/* ── One question at a time ── */
.quiz-stage{margin-bottom:12px}
.q-blur-wrap{position:relative}
.q-blur-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.38);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-radius:14px;z-index:20}
.q-blur-overlay .btn{font-weight:800;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.quiz-nav{display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap}
.quiz-q-timer{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:var(--horizon-brand-50);color:var(--horizon-brand-500);font-weight:800;font-size:12px}
html[data-theme="dark"] .quiz-q-timer{background:rgba(67,24,255,.25);color:#c7d2fe}
</style>
</head>
<body class="horizon-page-bg">
<nav id="horizon-navbar" style="margin:12px 20px">
  <div class="horizon-breadcrumb-area"><div class="horizon-breadcrumb"><a href="index.php">Student</a> <span style="opacity:.5">/</span> Quiz</div>
  <h1 class="horizon-page-title" style="font-size:16px"><i class="fas fa-pen-square me-2" style="color:var(--horizon-brand-500)"></i><?php echo $quiz_meta ? htmlspecialchars($quiz_meta['title']) : 'Quiz'; ?></h1></div>
  <div class="horizon-nav-pill-toolbar">
    <button type="button" id="horizon-theme-toggle" class="horizon-nav-icon-btn" aria-label="Enable dark mode" title="Enable dark mode" style="background:var(--horizon-brand-50);color:var(--horizon-brand-500)"><i class="fas fa-moon"></i></button>
    <span class="badge badge-secondary" id="quizTimer" style="display:none"><i class="fas fa-clock me-1"></i><span id="quizTimerText">--:--</span></span>
    <span class="badge badge-warning" id="violationBadge" title="Tab-switch / minimize violations"><i class="fas fa-shield-alt me-1"></i>Violations: <span id="violationCount">0</span>/3</span>
  </div>
</nav>
<main class="quiz-shell">
<?php if ($gate_error !== ''): ?>
  <div class="horizon-card"><div class="horizon-card-body text-center" style="padding:36px">
    <i class="fas fa-exclamation-triangle text-warning mb-3 d-block" style="font-size:28px"></i>
    <h5 class="fw-bold"><?php echo htmlspecialchars($gate_error); ?></h5>
    <a href="index.php" class="btn btn-primary mt-3"><i class="fas fa-arrow-left me-1"></i>Back to dashboard</a>
  </div></div>
<?php else: ?>
  <div id="secureBar" class="horizon-flash-banner horizon-flash-warning mb-3"><i class="fas fa-shield-alt"></i><span style="font-weight:700;font-size:12px">Secure mode: stay on this tab. Switching tabs, minimizing, or running this browser in the background is detected, logged, and auto-submits after 3 violations. Copying questions out or pasting text in (e.g. to use AI apps like ChatGPT/Gemini) is also detected and logged. Copy / right-click / print are disabled.</span></div>
  <div id="quizBody"><div class="horizon-card"><div class="horizon-card-body text-center py-5"><div class="spinner-border text-primary"></div><div class="small text-muted mt-2">Loading your quiz…</div></div></div></div>
<?php endif; ?>
</main>
<div id="gateOverlay" style="display:none"><div class="horizon-card" style="max-width:440px;width:100%"><div class="horizon-card-body text-center" style="padding:28px">
  <i class="fas fa-exclamation-triangle text-danger mb-2 d-block" style="font-size:30px"></i>
  <h5 class="fw-bold" id="gateTitle">Warning</h5>
  <p class="small text-muted" id="gateText"></p>
  <button class="btn btn-primary" id="gateBtn">I understand — continue</button>
</div></div></div>
<div id="readinessOverlay" style="display:none;position:fixed;inset:0;z-index:2100;align-items:center;justify-content:center;background:rgba(15,23,42,.6);padding:16px">
  <div class="horizon-card" style="max-width:520px;width:100%"><div class="horizon-card-body" style="padding:24px">
    <h5 class="fw-bold mb-2" style="color:var(--horizon-text-title)"><i class="fas fa-mobile-alt me-2 text-primary"></i>Device readiness check</h5>
    <p class="small text-muted mb-3">Before the quiz starts, please confirm these settings on your phone.</p>
    <div class="mb-3">
      <div class="d-flex align-items-center gap-2 mb-1"><strong style="font-size:13px">Internet connection</strong><span class="badge" id="connStatus">Checking…</span></div>
      <small class="text-muted" id="connDetail">Testing connection speed…</small>
    </div>
    <div class="mb-3">
      <label class="d-flex align-items-start gap-2" style="cursor:pointer">
        <input type="checkbox" id="screenTimeoutAck" class="form-check-input mt-1">
        <span class="small">I have set my phone’s screen sleep / auto-lock to <strong>Never</strong> (or the longest available) so the screen does not turn off during the quiz.</span>
      </label>
    </div>
    <div class="mb-3">
      <label class="d-flex align-items-start gap-2" style="cursor:pointer">
        <input type="checkbox" id="fullscreenAck" class="form-check-input mt-1">
        <span class="small">I will keep this quiz in <strong>fullscreen</strong> and will not use floating windows or split screen.</span>
      </label>
    </div>
    <div class="mb-3">
      <label class="d-flex align-items-start gap-2" style="cursor:pointer">
        <input type="checkbox" id="notificationsAck" class="form-check-input mt-1" onclick="openPhoneSettings()">
        <span class="small">I have turned off all notifications and enabled <strong>Do Not Disturb</strong> mode on my device.</span>
        <a href="javascript:void(0)" onclick="openPhoneSettings()" style="margin-left:6px;font-size:0.9rem;color:var(--horizon-brand-500);text-decoration:underline;">Open Settings</a>
      </label>
    </div>
    <div class="d-flex gap-2 justify-content-end">
      <button type="button" class="btn btn-secondary" id="recheckConnBtn"><i class="fas fa-sync-alt me-1"></i>Re-check</button>
      <button type="button" class="btn btn-primary fw-bold" id="readinessStartBtn" disabled><i class="fas fa-play me-1"></i>Start quiz</button>
    </div>
  </div></div>
</div>
<div id="doneWrap" style="display:none" class="quiz-shell"><div class="horizon-card"><div class="horizon-card-body text-center" style="padding:36px">
  <i class="fas fa-check-circle text-success mb-3 d-block" style="font-size:32px"></i>
  <h4 class="fw-bold" id="doneTitle">Submitted</h4>
  <p class="text-muted" id="doneText"></p>
  <a href="index.php" class="btn btn-primary"><i class="fas fa-arrow-left me-1"></i>Back to dashboard</a>
</div></div></div>
<div id="quizSubmitOverlay" style="display:none"><div class="horizon-card" style="max-width:440px;width:100%"><div class="horizon-card-body text-center" style="padding:28px">
  <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:56px;height:56px;font-size:22px;background:rgba(67,24,255,.1);color:var(--horizon-brand-500)"><i class="fas fa-paper-plane"></i></span>
  <h5 class="fw-bold" style="color:var(--horizon-text-title)">Submit quiz?</h5>
  <p class="small text-muted" id="quizSubmitSummary"></p>
  <div class="d-flex gap-2 justify-content-center">
    <button class="btn btn-secondary rounded-pill px-4" id="quizSubmitKeep">Keep answering</button>
    <button class="btn btn-primary rounded-pill px-4 fw-bold" id="quizSubmitGo"><i class="fas fa-check me-1"></i>Submit now</button>
  </div>
</div></div></div>
<div id="quizStickyBar" role="region" aria-label="Quiz progress and submit">
  <div style="min-width:0;flex:1 1 auto">
    <div class="d-flex align-items-baseline gap-2"><strong id="quizStickyNote" style="font-size:13px;color:var(--horizon-text-title)">0/0</strong><small style="font-size:10.5px;color:var(--horizon-text-muted);font-weight:700">answered</small></div>
    <div class="quiz-progress mt-1"><div id="quizStickyFill"></div></div>
  </div>
  <button class="btn btn-primary rounded-pill fw-bold" id="quizStickyGo" style="white-space:nowrap"><i class="fas fa-paper-plane me-1"></i>Submit</button>
</div>
<script src="../js/bootstrap.bundle.min.js"></script>
<script>
// Horizon dark-mode toggle (same localStorage key as the portal).
(function(){
    var btn = document.getElementById('horizon-theme-toggle');
    function paint(){
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        document.body.classList.toggle('dark', dark);
        if (btn){
            btn.innerHTML = '<i class="fas fa-' + (dark ? 'sun' : 'moon') + '"></i>';
            btn.setAttribute('aria-label', dark ? 'Enable light mode' : 'Enable dark mode');
            btn.title = dark ? 'Enable light mode' : 'Enable dark mode';
        }
    }
    paint();
    if (btn) btn.addEventListener('click', function(){
        var dark = document.documentElement.getAttribute('data-theme') !== 'dark';
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        try { localStorage.setItem('horizon-theme', dark ? 'dark' : 'light'); } catch (e) {}
        paint();
    });
})();
</script>
<?php if ($gate_error === ''): ?>
<script>
(function(){
'use strict';
var Q = <?php echo (int)$quiz_id; ?>, C = <?php echo (int)$class_id; ?>, K = <?php echo json_encode($db_key); ?>;
var questions = [], displayOrder = [], quizMeta = null, violations = 0, submitted = false, timerSecs = 0, timerHandle = null, warnCooldownUntil = 0;
var answers = {};
var currentIndex = 0;
var startedQuestions = {};
var questionRemaining = {};
var qTimerHandle = null;
var qTimerSecs = 0;
var qTimerQid = 0;
var MAX_VIOLATIONS = 5;
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtQ(s){ s = String(s == null ? '' : s); s = s.replace(/^Choose the correct answer:[ \t]+/, 'Choose the correct answer:\n\n'); return esc(s).replace(/\r?\n/g, '<br>'); }
function showGate(title, text, btnLabel){
    var o = document.getElementById('gateOverlay');
    document.getElementById('gateTitle').textContent = title;
    document.getElementById('gateText').textContent = text;
    document.getElementById('gateBtn').textContent = btnLabel || 'I understand — continue';
    o.style.display = 'flex';
}
document.getElementById('gateBtn').addEventListener('click', function(){
    document.getElementById('gateOverlay').style.display = 'none';
    try { document.documentElement.requestFullscreen().catch(function(){}); } catch (e) {}
});
document.getElementById('quizSubmitKeep').addEventListener('click', function(){
    document.getElementById('quizSubmitOverlay').style.display = 'none';
});
document.getElementById('quizSubmitGo').addEventListener('click', function(){
    document.getElementById('quizSubmitOverlay').style.display = 'none';
    submitQuiz(false);
});
function setViolations(n){
    violations = n;
    document.getElementById('violationCount').textContent = n;
    var b = document.getElementById('violationBadge');
    b.classList.remove('violation-shake'); void b.offsetWidth; b.classList.add('violation-shake');
}
function logViolation(reason){
    if (submitted) return;
    var fd = new FormData();
    fd.append('action', 'violate'); fd.append('quiz_id', Q); fd.append('class_id', C); fd.append('db_key', K); fd.append('reason', reason);
    fetch('quiz_api.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r){ return r.json(); }).then(function(j){
            if (j && j.status === 'success' && typeof j.violations !== 'undefined') setViolations(j.violations);
        }).catch(function(){});
    // local immediate feedback even if network lags
    setViolations(violations + 1);
    if (violations + 1 >= MAX_VIOLATIONS || violations >= MAX_VIOLATIONS){ autoSubmit('Too many tab-switch violations'); return; }
    if (Date.now() > warnCooldownUntil){
        warnCooldownUntil = Date.now() + 8000;
        showGate('Warning ' + violations + ' of ' + MAX_VIOLATIONS, 'Leaving this tab, minimizing, or putting this browser in the background is not allowed during the quiz. This incident was logged. One more set of violations will auto-submit your answers.', 'I understand — continue');
    }
    if (violations >= MAX_VIOLATIONS) autoSubmit('Too many tab-switch violations');
}
function collectAnswers(){
    // Persist the currently visible answer first, then return the full map.
    var cur = displayOrder[currentIndex];
    if (cur) {
        if (cur.qtype === 'multiple_choice' || cur.qtype === 'true_false') {
            var sel = document.querySelector('input[name="ans' + cur.question_id + '"]:checked');
            if (sel) answers[String(cur.question_id)] = sel.value;
        } else {
            var inp = document.getElementById('ans' + cur.question_id);
            if (inp) answers[String(cur.question_id)] = inp.value.trim();
        }
    }
    var out = {};
    Object.keys(answers).forEach(function(k){ out[k] = answers[k]; });
    return out;
}
function typeBadge(t){
    if (t === 'identification') return '<span class="badge badge-secondary">ID · Identification</span>';
    if (t === 'essay') return '<span class="badge badge-warning">ES · Essay — manual grading</span>';
    if (t === 'true_false') return '<span class="badge badge-success">T/F · True or False</span>';
    return '<span class="badge badge-brand">MC · Multiple Choice</span>';
}
function isAnswered(q){
    var v = answers[String(q.question_id)];
    if (q.qtype === 'identification' || q.qtype === 'essay') return !!(v && String(v).trim() !== '');
    return !!(v && String(v).trim() !== '');
}
function updateProgress(){
    var done = 0, total = displayOrder.length;
    displayOrder.forEach(function(q, i){
        var ok = isAnswered(q);
        if (ok) done++;
        var pal = document.querySelector('#quizPalette .quiz-pal[data-i="' + i + '"]');
        if (pal) pal.classList.toggle('answered', ok);
        var flag = document.querySelector('.quiz-ans-flag[data-flag="' + q.question_id + '"]');
        if (flag) flag.innerHTML = ok ? '<span class="badge badge-success" style="font-size:9px"><i class="fas fa-check me-1"></i>Answered</span>' : '';
    });
    var badge = document.getElementById('quizProgBadge');
    if (badge) badge.textContent = done + '/' + total;
    var fill = document.getElementById('quizProgFill');
    if (fill) fill.style.width = (total ? Math.round(100 * done / total) : 0) + '%';
    var note = document.getElementById('quizFootNote');
    if (note) note.textContent = done + ' of ' + total + ' answered' + (done < total ? ' · ' + (total - done) + ' remaining' : ' · all done — good luck!');
    var stickyNote = document.getElementById('quizStickyNote');
    if (stickyNote) stickyNote.textContent = done + '/' + total;
    var stickyFill = document.getElementById('quizStickyFill');
    if (stickyFill) stickyFill.style.width = (total ? Math.round(100 * done / total) : 0) + '%';
}
var pendingQuizTimerMinutes = 0;
function setReadinessStartEnabled(){
    var btn = document.getElementById('readinessStartBtn');
    var ack1 = document.getElementById('screenTimeoutAck');
    var ack2 = document.getElementById('fullscreenAck');
    var ack3 = document.getElementById('notificationsAck');
    var connOk = document.getElementById('connStatus') && document.getElementById('connStatus').getAttribute('data-ok') === '1';
    if (btn) btn.disabled = !(connOk && ack1 && ack1.checked && ack2 && ack2.checked && ack3 && ack3.checked);
}

// Open OS notification settings (Android or iOS) when user requests it.
function openPhoneSettings() {
    var ua = navigator.userAgent || navigator.vendor || window.opera;
    // Android
    if (/android/i.test(ua)) {
        // Attempt to open the app notification settings; replace package name with your actual app package if known.
        var pkg = window.location.hostname; // fallback placeholder
        var intent = 'intent:#Intent;action=android.settings.APP_NOTIFICATION_SETTINGS;S.android.provider.extra.APP_PACKAGE=' + pkg + ';end';
        window.location.href = intent;
    }
    // iOS
    else if (/iPad|iPhone|iPod/.test(ua) && !window.MSStream) {
        window.location.href = 'app-settings:';
    } else {
        alert('Please open your device settings manually and disable notifications / enable Do Not Disturb.');
    }
}
function runConnectionCheck(){
    var status = document.getElementById('connStatus');
    var detail = document.getElementById('connDetail');
    if (status) { status.textContent = 'Checking…'; status.className = 'badge badge-secondary'; status.setAttribute('data-ok', '0'); }
    if (detail) detail.textContent = 'Testing connection speed…';
    var start = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    var done = false;
    function finish(ok, msg){
        if (done) return; done = true;
        if (status) {
            status.textContent = ok ? 'OK' : 'Slow / Offline';
            status.className = ok ? 'badge badge-success' : 'badge badge-danger';
            status.setAttribute('data-ok', ok ? '1' : '0');
        }
        if (detail) detail.textContent = msg;
        setReadinessStartEnabled();
    }
    if (!navigator.onLine) { finish(false, 'You appear to be offline. Connect to the internet and re-check.'); return; }
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn) {
        var et = String(conn.effectiveType || '');
        if (et === 'slow-2g' || et === '2g') { finish(false, 'Connection is too slow (' + et + '). Use a faster network.'); return; }
        if (typeof conn.downlink === 'number' && conn.downlink < 0.5) { finish(false, 'Connection is too slow (' + conn.downlink + ' Mbps).'); return; }
    }
    // Lightweight speed test using a small local asset.
    fetch('../favicon.ico?_=' + Date.now(), { cache: 'no-store' })
        .then(function(){ 
            var ms = ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now()) - start;
            if (ms <= 4000) finish(true, 'Connection OK' + (conn && conn.downlink ? ' (' + conn.downlink + ' Mbps)' : '') + '.');
            else finish(false, 'Connection is slow (' + Math.round(ms) + ' ms). Try a better network.');
        })
        .catch(function(){ finish(false, 'Could not reach the server. Check your internet connection.'); });
    setTimeout(function(){ if (!done) finish(false, 'Connection check timed out. Try again.'); }, 6000);
}
function showReadiness(minutes){
    pendingQuizTimerMinutes = minutes || 0;
    var overlay = document.getElementById('readinessOverlay');
    if (overlay) overlay.style.display = 'flex';
    runConnectionCheck();
    var ack1 = document.getElementById('screenTimeoutAck');
    var ack2 = document.getElementById('fullscreenAck');
    var ack3 = document.getElementById('notificationsAck');
    if (ack1) ack1.addEventListener('change', setReadinessStartEnabled);
    if (ack2) ack2.addEventListener('change', setReadinessStartEnabled);
    if (ack3) ack3.addEventListener('change', setReadinessStartEnabled);
    var recheck = document.getElementById('recheckConnBtn');
    if (recheck) recheck.addEventListener('click', runConnectionCheck);
    var startBtn = document.getElementById('readinessStartBtn');
    if (startBtn) startBtn.addEventListener('click', function(){
        if (startBtn.disabled) return;
        try { document.documentElement.requestFullscreen().catch(function(){}); } catch (e) {}
        if (navigator.wakeLock && navigator.wakeLock.request) {
            navigator.wakeLock.request('screen').catch(function(){});
        }
        var overlay = document.getElementById('readinessOverlay');
        if (overlay) overlay.style.display = 'none';
        startTimer(pendingQuizTimerMinutes);
        armSecurity();
        showGate('Secure quiz mode', 'Do not switch tabs, minimize, or background this browser. Violations are logged and 3 violations auto-submit your quiz.', 'Begin quiz');
    });
}
function openSubmitModal(){
    var done = 0, total = displayOrder.length;
    displayOrder.forEach(function(q){ if (isAnswered(q)) done++; });
    document.getElementById('quizSubmitSummary').innerHTML = 'You answered <strong>' + done + '</strong> of <strong>' + total + '</strong>.' + (done < total ? '<br><span class="text-warning fw-bold">Unanswered: ' + (total - done) + ' — blank answers score zero.</span>' : '<br>All questions answered.');
    document.getElementById('quizSubmitOverlay').style.display = 'flex';
}
function renderCurrent(){
    var stage = document.getElementById('quizStage');
    if (!stage || !displayOrder.length) return;
    var q = displayOrder[currentIndex];
    var started = !!startedQuestions[q.question_id];
    var tl = parseInt(q.time_limit_seconds || 0, 10) || 0;
    var letters = 'ABCDEF';
    var h = '<div class="quiz-q q-blur-wrap" id="qq' + q.question_id + '">';
    if (!started) {
        h += '<div class="q-blur-overlay"><button type="button" class="btn btn-primary btn-lg" data-answer-btn="' + q.question_id + '"><i class="fas fa-play me-2"></i>Answer</button></div>';
    }
    h += '<div class="quiz-q-head"><span class="badge badge-secondary">#' + (currentIndex + 1) + '</span>' + typeBadge(q.qtype) + (tl > 0 ? '<span class="quiz-q-timer"><i class="fas fa-clock"></i>' + (started ? (questionRemaining[q.question_id] || tl) + 's' : tl + 's') + '</span>' : '') + '<span class="ms-auto quiz-ans-flag" data-flag="' + q.question_id + '"></span></div>';
    h += '<h6>' + fmtQ(q.question) + '</h6>';
    var dis = started ? '' : ' disabled';
    if (q.qtype === 'essay') {
        h += '<div class="quiz-ident-wrap tall"><i class="fas fa-pen"></i><textarea class="form-control quiz-ident" id="ans' + q.question_id + '" rows="5" placeholder="Write your essay answer…" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Your essay answer"' + dis + '>' + esc(answers[String(q.question_id)] || '') + '</textarea></div>';
    } else if (q.qtype === 'identification') {
        h += '<div class="quiz-ident-wrap"><i class="fas fa-pen"></i><input type="text" class="form-control quiz-ident" id="ans' + q.question_id + '" placeholder="Type your answer…" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Your answer" value="' + esc(answers[String(q.question_id)] || '') + '"' + dis + '></div>';
    } else {
        (q.choices || []).forEach(function(c, ci){
            var id = 'q' + q.question_id + '_' + ci;
            var checked = (answers[String(q.question_id)] === c) ? ' checked' : '';
            h += '<label class="quiz-opt" for="' + id + '"><input type="radio" class="form-check-input mt-1" name="ans' + q.question_id + '" id="' + id + '" value="' + esc(c) + '"' + checked + dis + '><span class="opt-letter">' + (letters[ci] || (ci + 1)) + '</span><span>' + esc(c) + '</span></label>';
        });
    }
    h += '</div>';
    h += '<div class="quiz-nav">';
    h += '<div class="small fw-bold" style="color:var(--horizon-text-muted)">' + (currentIndex + 1) + ' of ' + displayOrder.length + '</div>';
    if (currentIndex < displayOrder.length - 1) h += '<button type="button" class="btn btn-primary" id="quizNextBtn">Next<i class="fas fa-arrow-right ms-1"></i></button>';
    else h += '<button type="button" class="btn btn-primary" id="quizFinishNavBtn"><i class="fas fa-paper-plane me-1"></i>Review &amp; submit</button>';
    h += '</div>';
    stage.innerHTML = h;

    var btn = stage.querySelector('[data-answer-btn]');
    if (btn) btn.addEventListener('click', function(){
        startedQuestions[q.question_id] = true;
        if (tl > 0 && questionRemaining[q.question_id] === undefined) questionRemaining[q.question_id] = tl;
        renderCurrent();
        updateProgress();
        if (tl > 0) startQuestionTimer(q.question_id);
    });
    stage.querySelectorAll('.quiz-opt input').forEach(function(r){
        r.addEventListener('change', function(){
            stage.querySelectorAll('input[name="' + r.name + '"]').forEach(function(x){ x.closest('.quiz-opt').classList.remove('sel'); });
            r.closest('.quiz-opt').classList.add('sel');
            answers[String(q.question_id)] = r.value;
            updateProgress();
        });
    });
    var textInp = document.getElementById('ans' + q.question_id);
    if (textInp) textInp.addEventListener('input', function(){
        answers[String(q.question_id)] = textInp.value.trim();
        updateProgress();
    });
    var nextBtn = document.getElementById('quizNextBtn');
    if (nextBtn) nextBtn.addEventListener('click', function(){ navigateQuestion(currentIndex + 1); });
    var finishBtn = document.getElementById('quizFinishNavBtn');
    if (finishBtn) finishBtn.addEventListener('click', openSubmitModal);
    // Disable backward navigation in the palette.
    document.querySelectorAll('#quizPalette .quiz-pal').forEach(function(b){
        var i = parseInt(b.getAttribute('data-i'), 10);
        b.disabled = (i < currentIndex);
        b.style.opacity = (i < currentIndex) ? '.45' : '';
        b.style.cursor = (i < currentIndex) ? 'not-allowed' : 'pointer';
    });
    var submitCard = document.getElementById('quizSubmitCard');
    if (submitCard) submitCard.style.display = (currentIndex === displayOrder.length - 1) ? '' : 'none';
    var stickyGo = document.getElementById('quizStickyGo');
    if (stickyGo) stickyGo.style.display = (currentIndex === displayOrder.length - 1) ? '' : 'none';
    // restore selected styling
    if (q.qtype !== 'identification' && q.qtype !== 'essay') {
        var checked = document.querySelector('input[name="ans' + q.question_id + '"]:checked');
        if (checked && checked.closest('.quiz-opt')) checked.closest('.quiz-opt').classList.add('sel');
    }
    updateProgress();
}
function render(){
    var body = document.getElementById('quizBody');
    // Shuffle display order per student load so "number X is answer Y" notes
    // can't be shared between seats. Grading is by question_id, unaffected.
    var order = questions.slice();
    for (var s = order.length - 1; s > 0; s--){ var rr = Math.floor(Math.random() * (s + 1)); var tt = order[s]; order[s] = order[rr]; order[rr] = tt; }
    displayOrder = order;
    var total = order.length;
    var h = '<div class="quiz-head"><div class="d-flex align-items-center gap-2 flex-wrap mb-2">'
        + '<span class="horizon-widget-icon-box brand" style="width:40px;height:40px;min-width:40px;font-size:16px"><i class="fas fa-pen-square"></i></span>'
        + '<div class="flex-grow-1" style="min-width:200px"><div class="fw-bold" style="font-size:14px;color:var(--horizon-text-title)">' + esc(quizMeta ? quizMeta.title : 'Quiz') + '</div>'
        + '<div class="small" style="color:var(--horizon-text-muted);font-weight:600">' + esc(quizMeta ? (quizMeta.quiz_type_label || '') : '') + ' · ' + total + ' questions' + ((quizMeta && quizMeta.time_limit_minutes > 0) ? ' · ' + quizMeta.time_limit_minutes + ' min limit' : '') + '</div></div>'
        + '<span class="badge badge-brand" id="quizProgBadge">0/' + total + '</span></div>'
        + '<div class="quiz-progress mb-2"><div id="quizProgFill"></div></div>'
        + '<div class="quiz-pal-grid" id="quizPalette">';
    order.forEach(function(q, i){ h += '<button type="button" class="quiz-pal" data-i="' + i + '" title="Go to question ' + (i + 1) + '">' + (i + 1) + '</button>'; });
    h += '</div></div>';
    h += '<div class="quiz-stage" id="quizStage"></div>';
    h += '<div class="horizon-card" id="quizSubmitCard" style="display:none"><div class="horizon-card-body d-flex align-items-center gap-3 flex-wrap"><div><div class="fw-bold" style="font-size:14px;color:var(--horizon-text-title)">Ready to submit?</div><div class="small" style="color:var(--horizon-text-muted);font-weight:600" id="quizFootNote"></div></div><button class="btn btn-primary ms-auto fw-bold px-4" id="quizSubmitBtn"><i class="fas fa-paper-plane me-1"></i>Submit answers</button></div></div>';
    body.innerHTML = h;
    body.querySelectorAll('#quizPalette .quiz-pal').forEach(function(b){
        b.addEventListener('click', function(){
            navigateQuestion(parseInt(b.getAttribute('data-i'), 10));
        });
    });
    document.getElementById('quizSubmitBtn').addEventListener('click', openSubmitModal);
    var stickyGo = document.getElementById('quizStickyGo');
    if (stickyGo && !stickyGo.dataset.bound){ stickyGo.dataset.bound = '1'; stickyGo.addEventListener('click', openSubmitModal); }
    currentIndex = 0;
    renderCurrent();
}
function navigateQuestion(idx){
    if (idx < currentIndex || idx >= displayOrder.length) return;
    // stop current question timer but keep remaining
    if (qTimerHandle){ clearTimeout(qTimerHandle); qTimerHandle = null; }
    collectAnswers();
    currentIndex = idx;
    renderCurrent();
    var q = displayOrder[currentIndex];
    if (startedQuestions[q.question_id] && (q.time_limit_seconds || 0) > 0 && (questionRemaining[q.question_id] || 0) > 0) {
        startQuestionTimer(q.question_id, true);
    }
}
function startTimer(mins){
    if (!mins || mins <= 0) return;
    timerSecs = mins * 60;
    var badge = document.getElementById('quizTimer');
    badge.style.display = '';
    function tick(){
        if (submitted) return;
        var m = Math.floor(timerSecs / 60), s = timerSecs % 60;
        document.getElementById('quizTimerText').textContent = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
        if (timerSecs <= 0){ autoSubmit('Time is up'); return; }
        timerSecs--;
        timerHandle = setTimeout(tick, 1000);
    }
    tick();
}
function startQuestionTimer(qid, resume){
    var q = null;
    for (var i = 0; i < displayOrder.length; i++){ if (displayOrder[i].question_id === qid){ q = displayOrder[i]; break; } }
    if (!q) return;
    var tl = parseInt(q.time_limit_seconds || 0, 10) || 0;
    if (tl <= 0) return;
    if (qTimerHandle) clearTimeout(qTimerHandle);
    qTimerQid = qid;
    if (!resume || questionRemaining[qid] === undefined) questionRemaining[qid] = tl;
    qTimerSecs = questionRemaining[qid];
    function updateBadge(){
        var el = document.querySelector('#qq' + qid + ' .quiz-q-timer');
        if (el) el.innerHTML = '<i class="fas fa-clock"></i>' + qTimerSecs + 's';
    }
    updateBadge();
    function tickQ(){
        if (submitted) return;
        if (qTimerQid !== qid) return;
        if (qTimerSecs <= 0){ questionRemaining[qid] = 0; if (qTimerHandle) { clearTimeout(qTimerHandle); qTimerHandle = null; } if (currentIndex < displayOrder.length - 1) { navigateQuestion(currentIndex + 1); } else { submitQuiz(true); } return; }
        questionRemaining[qid] = qTimerSecs;
        qTimerSecs--;
        updateBadge();
        qTimerHandle = setTimeout(tickQ, 1000);
    }
    qTimerHandle = setTimeout(tickQ, 1000);
}
function finishView(title, text){
    submitted = true;
    if (timerHandle) clearTimeout(timerHandle);
    if (qTimerHandle) clearTimeout(qTimerHandle);
    document.getElementById('quizBody').style.display = 'none';
    var sb = document.getElementById('secureBar'); if (sb) sb.style.display = 'none';
    var stb = document.getElementById('quizStickyBar'); if (stb) stb.style.display = 'none';
    var w = document.getElementById('doneWrap'); w.style.display = 'block';
    document.getElementById('doneTitle').textContent = title;
    document.getElementById('doneText').textContent = text;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function submitQuiz(auto){
    if (submitted) return;
    submitted = true;
    var fd = new FormData();
    fd.append('action', 'submit'); fd.append('quiz_id', Q); fd.append('class_id', C); fd.append('db_key', K);
    fd.append('answers', JSON.stringify(collectAnswers()));
    if (auto) fd.append('auto', '1');
    fetch('quiz_api.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j && (j.status === 'success' || j.status === 'submitted')){
                finishView(auto ? 'Auto-submitted' : 'Quiz submitted', 'Your score: ' + j.score + ' out of ' + j.total + (auto ? ' (auto-submitted for a security violation / time limit).' : '.'));
            } else { submitted = false; alert((j && j.msg) || 'Submit failed — please retry.'); }
        })
        .catch(function(){ submitted = false; alert('Network error — please check your connection and retry.'); });
}
function autoSubmit(reason){
    if (submitted) return;
    showGate('Auto-submitting', reason + '. Your current answers are being submitted now.', 'OK');
    setTimeout(function(){ submitQuiz(true); }, 1200);
}
// ---- boot: fetch questions (answers stay server-side) ----
(function boot(){
    var fd = new FormData();
    fd.append('action', 'start'); fd.append('quiz_id', Q); fd.append('class_id', C); fd.append('db_key', K);
    fetch('quiz_api.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j && j.status === 'submitted'){ finishView('Already submitted', 'Your score: ' + j.score + ' out of ' + j.total + '.'); return; }
            if (!j || j.status !== 'success' || !j.questions || !j.questions.length) throw new Error((j && j.msg) || 'Could not load the quiz.');
            questions = j.questions;
            quizMeta = j.quiz || null;
            if (j.violations) setViolations(j.violations);
            render();
            showReadiness(j.quiz.time_limit_minutes);
        })
        .catch(function(e){ document.getElementById('quizBody').innerHTML = '<div class="horizon-card"><div class="horizon-card-body text-center text-danger py-5">' + esc(e.message) + '<br><a href="index.php" class="btn btn-secondary mt-3">Back to dashboard</a></div></div>'; });
})();
// ---- anti-tab-switch / background / devtools security ----
function armSecurity(){
    var lastHiddenReport = 0;
    document.addEventListener('visibilitychange', function(){
        if (document.hidden){ if (Date.now() - lastHiddenReport > 1500){ lastHiddenReport = Date.now(); logViolation('tab_hidden'); } }
    });
    window.addEventListener('blur', function(){
        // blur fires when the window loses focus: new tab, another app, minimize
        if (!submitted && !document.hidden) logViolation('window_blur');
    });
    window.addEventListener('pagehide', function(){ if (!submitted) logViolation('page_hide'); });
    document.addEventListener('mouseleave', function(e){ if (!e.relatedTarget && !submitted && !document.hidden){ /* pointer left window — soft signal only */ } });
    document.addEventListener('contextmenu', function(e){ e.preventDefault(); return false; });
    // Exfiltration tripwire (anti-AI-search): the page cannot see inside
    // ChatGPT/Gemini/another tab — browsers isolate those — but copying
    // question text OUT (to feed an AI app) or pasting text IN (from an AI
    // answer) happens on THIS page and is observable, so every attempt is
    // blocked and logged as a violation.
    var lastExfilReport = 0;
    function reportExfil(kind){
        var now = Date.now();
        if (now - lastExfilReport < 5000) return; // at most one strike per 5s
        lastExfilReport = now;
        logViolation('exfil_' + kind);
    }
    document.addEventListener('copy', function(e){ e.preventDefault(); reportExfil('copy'); return false; });
    document.addEventListener('cut', function(e){ e.preventDefault(); reportExfil('cut'); return false; });
    document.addEventListener('paste', function(e){ e.preventDefault(); reportExfil('paste'); return false; });
    document.addEventListener('dragstart', function(e){ e.preventDefault(); reportExfil('drag'); return false; });
    document.addEventListener('keydown', function(e){
        var k = e.keyCode || e.which;
        if (k === 123) { e.preventDefault(); logViolation('devtools_f12'); return false; } // F12
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && (k === 73 || k === 74 || k === 67)) { e.preventDefault(); logViolation('devtools_shortcut'); return false; }
        if ((e.ctrlKey || e.metaKey) && (k === 85 || k === 83 || k === 80)) { e.preventDefault(); return false; } // U/S/P
        if ((e.ctrlKey || e.metaKey) && k === 84) { /* Ctrl+T cannot be blocked; blur handler logs it */ }
    });
    window.addEventListener('beforeprint', function(e){ e.preventDefault(); return false; });
    window.addEventListener('beforeunload', function(e){
        if (!submitted){ e.preventDefault(); e.returnValue = ''; logViolation('navigate_away'); }
    });
    // Detect devtools-docked resizing heuristic (supplementary; primary is visibility/blur)
    var devtoolsOpen = false;
    setInterval(function(){
        if (submitted) return;
        var threshold = 170;
        var open = (window.outerWidth - window.innerWidth > threshold) || (window.outerHeight - window.innerHeight > threshold);
        if (open && !devtoolsOpen){ devtoolsOpen = true; logViolation('devtools_open'); }
        else if (!open){ devtoolsOpen = false; }
    }, 2500);
    // Floating window / popup / picture-in-picture detection.
    var floatingReported = false;
    setInterval(function(){
        if (submitted || floatingReported) return;
        var floating = false;
        try {
            if (document.pictureInPictureElement) floating = true;
            if (window.opener) floating = true;
            if (window.top !== window.self) floating = true;
            // Small detached window heuristic (floating popup).
            if ((window.outerWidth > 0 && window.outerWidth < 420) || (window.outerHeight > 0 && window.outerHeight < 320)) floating = true;
        } catch (e) {}
        if (floating){ floatingReported = true; logViolation('floating_window'); }
    }, 2000);
    window.addEventListener('enterpictureinpicture', function(){ if (!submitted){ floatingReported = true; logViolation('floating_window'); } });
    // Block mobile/browser back button during the quiz.
    try {
        history.pushState(null, '', location.href);
        window.addEventListener('popstate', function(){
            history.pushState(null, '', location.href);
            if (!submitted) logViolation('back_button');
        });
    } catch (e) {}
    // Mobile floating-window mode usually shrinks the viewport width.
    window.addEventListener('resize', function(){
        if (submitted || floatingReported) return;
        try {
            if (/Mobi|Android|iPhone|iPad/i.test(navigator.userAgent || '')) {
                if (screen.width > 0 && window.innerWidth > 0 && window.innerWidth < Math.round(screen.width * 0.82)) {
                    floatingReported = true; logViolation('floating_window');
                }
            }
        } catch (e) {}
    });
}
})();
</script>
<?php endif; ?>
</body>
</html>
