<?php
// Quiz Generator (faculty/admin) — everything on one page: paste a
// discussion/topic or write questions manually, auto-generate, review the
// questions list, save, then Activate so only the selected class sees it
// as a notification in students/index.php.
if (!isset($_SESSION['admin_id'])) { header('Location:./login.php'); exit; }
require_once('quiz_schema.php');
if (isset($conn) && $conn instanceof SQLite3) { quiz_ensure_schema($conn); }
else { try { $tmpQ = new DBConnection(); quiz_ensure_schema($tmpQ); } catch (Exception $e) {} }
?>
<div class="quiz-hub">
<style>
    /* One unified tab bar for every section of a class folder */
    .quiz-hub .quiz-tabs-wrapper{
        display:flex;align-items:center;
        background:var(--horizon-card-bg,#fff) !important;
        border:1px solid var(--horizon-card-border,#e2e8f0) !important;
        border-radius:16px !important;
        padding:7px !important;
        margin-bottom:1.25rem !important;
        box-shadow:0 2px 12px rgba(15,23,42,.06) !important;
    }
    .quiz-hub .quiz-tabs{display:flex !important;flex-wrap:wrap !important;gap:6px !important;list-style:none !important;margin:0 !important;padding:0 !important;width:100% !important}
    .quiz-hub .quiz-tabs .nav-item{
        flex:1 1 auto !important;min-width:128px !important;margin-bottom:0 !important;
        border-radius:11px !important;overflow:hidden !important;
        border:1px solid transparent !important;transition:all .2s ease !important;
    }
    .quiz-hub .quiz-tabs .nav-item:hover:not(.active){
        background-color:#eef7f1 !important;border-color:#cfe6d8 !important;box-shadow:none !important;
    }
    .quiz-hub .quiz-tabs .nav-item.active{
        background:linear-gradient(135deg,#1a4d2e 0%,#2d5f3f 100%) !important;
        border-color:#1a4d2e !important;
        box-shadow:0 6px 16px rgba(26,77,46,.30) !important;
    }
    .quiz-hub .quiz-tabs .quiz-tab-btn{
        display:flex !important;align-items:center !important;justify-content:center !important;
        gap:.5rem !important;width:100% !important;padding:.62rem 1rem !important;
        font-size:.88rem !important;font-weight:600 !important;letter-spacing:.01em !important;
        color:#475569 !important;background:transparent !important;border:none !important;
        border-radius:11px !important;box-shadow:none !important;cursor:pointer !important;
        transition:color .2s ease !important;
    }
    .quiz-hub .quiz-tabs .quiz-tab-btn i{font-size:.92rem !important;color:#1a4d2e !important;opacity:.7 !important}
    .quiz-hub .quiz-tabs .nav-item.active .quiz-tab-btn{color:#fff !important;font-weight:700 !important}
    .quiz-hub .quiz-tabs .nav-item.active .quiz-tab-btn i{color:#d4af37 !important;opacity:1 !important}
    .quiz-hub .quiz-tabs .badge{background:rgba(26,77,46,.10) !important;color:#1a4d2e !important;font-weight:700 !important}
    .quiz-hub .quiz-tabs .nav-item.active .badge{background:rgba(255,255,255,.22) !important;color:#fff !important}
    .quiz-tab-panel{animation:quizTabFadeIn .3s ease}
    @keyframes quizTabFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    @media (max-width:576px){
        .quiz-hub .quiz-tabs .nav-item{min-width:100% !important}
        .quiz-hub .quiz-tabs .quiz-tab-btn{justify-content:flex-start !important;padding:.7rem 1rem !important}
    }

</style>
<style>
    /* Folder UI styles (copied from students.php) */
    .stu-folder-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
    }
    .stu-folder-card {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        padding: 1rem 1.1rem;
        background: var(--horizon-card-bg, #fff);
        border: 1px solid var(--horizon-card-border, #e0e0e0);
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(16,24,40,.06);
        cursor: pointer;
        transition: transform .15s ease, box-shadow .2s ease, border-color .2s ease;
        text-align: left;
        width: 100%;
    }
    button.stu-folder-card {
        background: var(--horizon-card-bg, #fff);
    }
    .stu-folder-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(26,77,46,.16);
        border-color: #d4af37;
    }
    .stu-folder-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        min-width: 48px;
        border-radius: 14px;
        background: linear-gradient(135deg,#1a4d2e 0%,#2d5f3f 100%);
        color: #fff;
        font-size: 20px;
        box-shadow: 0 4px 12px rgba(26,77,46,.3);
    }
    .stu-folder-icon.section {
        background: linear-gradient(135deg,#d4af37 0%,#f4e5c2 100%);
        color: #1a4d2e;
        box-shadow: 0 4px 12px rgba(212,175,55,.35);
    }
    .stu-folder-meta { min-width: 0; }
    .stu-folder-title {
        font-weight: 800;
        font-size: 0.95rem;
        color: var(--horizon-text-heading, #1B2559);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .stu-folder-sub {
        font-size: 0.75rem;
        color: var(--horizon-text-muted, #A3AED0);
        font-weight: 600;
    }
    .stu-breadcrumb {
        background: transparent;
        font-size: 1.1rem;
    }
    .stu-breadcrumb a {
        text-decoration: none;
        font-weight: 600;
        color: #d4af37;
    }
    .stu-breadcrumb a:hover {
        color: #fff;
    }
    .stu-breadcrumb .breadcrumb-item.active {
        color: #007bff;
    }
    /* Semester folder sits between the year and the class folders */
    .stu-folder-icon.semester {
        background: linear-gradient(135deg,#14532d 0%,#1a4d2e 100%);
        border: 2px solid #d4af37;
        color: #f4e5c2;
    }
    /* Read-only class chip — the class comes from the open class folder */
    .quiz-class-chip {
        display: inline-flex;
        align-items: center;
        gap: .55rem;
        max-width: 100%;
        padding: .6rem 1rem;
        border-radius: 12px;
        font-weight: 700;
        font-size: .9rem;
        background: linear-gradient(135deg,#eaf4ee 0%,#dfeee6 100%);
        border: 1px solid #cfe6d8;
        color: #1a4d2e;
    }
    .quiz-class-chip i { color: #d4af37; }
    .quiz-class-chip .quiz-class-chip-text {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .quiz-class-chip.is-empty {
        background: #f8fafc;
        border-color: #e2e8f0;
        color: #94a3b8;
        font-weight: 600;
    }
    .quiz-class-chip.is-empty i { color: #94a3b8; }
    .quiz-class-chip-sm {
        padding: .42rem .75rem;
        font-size: .8rem;
        border-radius: 10px;
    }
</style>
<!-- Folder navigation: All Years -> Year -> Semester -> Class (like students.php) -->
<div class="horizon-card mb-3" id="quizFolderUI">
    <div class="horizon-card-body py-2">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb stu-breadcrumb mb-0" id="quizClassBreadcrumb">
                <li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="root"><i class="fas fa-home me-1"></i>All Years</a></li>
            </ol>
        </nav>
        <div id="quizYearGrid" class="stu-folder-grid mt-2" style="display:none;"></div>
        <div id="quizSemGrid" class="stu-folder-grid mt-2" style="display:none;"></div>
        <div id="quizClassGrid" class="stu-folder-grid mt-2" style="display:none;"></div>
    </div>
</div>
<div class="quiz-tabs-wrapper" id="quizMainTabs" style="display:none">
    <ul class="quiz-tabs" role="tablist">
        <li class="nav-item active" role="presentation">
            <button type="button" class="quiz-tab-btn" data-sec="setup" role="tab" aria-selected="true">
                <i class="fas fa-cog"></i>
                <span>Setup</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button type="button" class="quiz-tab-btn" data-sec="questions" role="tab" aria-selected="false">
                <i class="fas fa-tasks"></i>
                <span>Questions</span>
                <span class="badge badge-secondary ms-1" id="quizSecQCount">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button type="button" class="quiz-tab-btn" data-sec="manual" role="tab" aria-selected="false">
                <i class="fas fa-pen-square"></i>
                <span>Manual</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button type="button" class="quiz-tab-btn" data-sec="import" role="tab" aria-selected="false">
                <i class="fas fa-file-import"></i>
                <span>Import</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button type="button" class="quiz-tab-btn" data-sec="saved" role="tab" aria-selected="false">
                <i class="fas fa-clipboard-list"></i>
                <span>Saved Quizzes</span>
                <span class="badge badge-secondary ms-1" id="quizSavedCount">0</span>
            </button>
        </li>
    </ul>
</div>
<div id="quiz-panels" style="display:none">
<div id="quizAlert"></div>
<div id="quiz-sec-setup" class="quiz-tab-panel quiz-sec-panel" style="display:none">
<div class="horizon-card mb-3">
    <div class="horizon-card-header">
        <div class="d-flex align-items-center gap-2">
            <div class="horizon-widget-icon-box brand" style="width:40px;height:40px;min-width:40px;font-size:16px"><i class="fas fa-magic"></i></div>
            <div>
                <h4 class="mb-0 fw-bold">Quiz Generator</h4>
                <small class="text-muted">Paste a discussion, set items / choices / type, auto-generate, then activate for a class</small>
            </div>
        </div>
        <span class="badge badge-brand"><i class="fas fa-bolt me-1"></i>Auto quiz</span>
    </div>
    <div class="horizon-card-body">
        <input type="hidden" id="quiz_edit_id" value="">
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <label class="form-label fw-bold small">Class <span class="text-muted fw-normal">— picked from the class folder</span></label>
                <div class="quiz-class-chip" data-class-chip>
                    <i class="fas fa-folder-open"></i>
                    <span class="quiz-class-chip-text">No class folder open</span>
                </div>
                <input type="hidden" id="quiz_class" value="">
                <input type="hidden" id="quiz_semester" value="">
                <div class="quiz-class-chip" data-semester-chip>
                    <i class="fas fa-calendar-alt"></i>
                    <span class="quiz-class-chip-text">No semester folder open</span>
                </div>
                <div><small class="text-muted">Only the students of the class folder you opened will be notified.</small></div>
            </div>
            <div class="col-12 col-lg-8">
                <label class="form-label fw-bold small">Quiz title <span class="text-danger">*</span></label>
                <input type="text" id="quiz_title" class="form-control" maxlength="150" placeholder="e.g. Photosynthesis — Quiz 1">
            </div>
            <div class="col-12" id="quizAssignWrap" style="display:none">
                <label class="form-label fw-bold small">Assign to <span class="text-muted fw-normal">— default: whole class</span></label>
                <div class="dropdown" id="quizAssignDD">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="quizAssignBtn"><i class="fas fa-users me-1"></i><span id="quizAssignLabel">Whole class</span></button>
                    <div class="dropdown-menu p-2" style="min-width:280px;max-width:340px;background:var(--horizon-card-bg)">
                        <input type="search" class="form-control form-control-sm mb-2" id="quizAssignSearch" placeholder="Search name or number…" autocomplete="off">
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-secondary flex-fill" id="quizAssignAll">Select all</button>
                            <button type="button" class="btn btn-sm btn-secondary flex-fill" id="quizAssignNone">Clear</button>
                        </div>
                        <div id="quizAssignList" class="d-flex flex-column gap-1" style="max-height:260px;overflow:auto"></div>
                    </div>
                </div>
                <div><small class="text-muted" id="quizAssignHint">Default: every student in the class will see this quiz.</small></div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-bold small">Items</label>
                <input type="number" id="quiz_items" class="form-control" min="1" max="100" value="10">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-bold small">Choices</label>
                <input type="number" id="quiz_choices" class="form-control" min="2" max="5" value="4">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label fw-bold small">Type</label>
                <select id="quiz_type" class="form-select">
                    <option value="multiple_choice" selected>Multiple Choice</option>
                    <option value="identification">Identification</option>
                    <option value="true_false">True or False</option>
                    <option value="mixed">Mixed (all types)</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-bold small">Time limit (min)</label>
                <input type="number" id="quiz_time" class="form-control" min="0" max="300" value="0">
                <small class="text-muted">0 = no limit</small>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-bold small">Question time (sec)</label>
                <input type="number" id="quiz_qtime" class="form-control" min="0" max="3600" value="0">
                <small class="text-muted">All questions</small>
            </div>
            <div class="col-12 col-lg-3 d-flex align-items-end gap-2 flex-wrap">
                <button type="button" class="btn btn-primary" id="quizGenerateBtn"><i class="fas fa-magic me-1"></i>Generate</button>
                <button type="button" class="btn btn-secondary quiz-save-btn" id="quizSaveBtn"><i class="fas fa-save me-1"></i>Save quiz</button>
            </div>
            <div class="col-12">
                <label class="form-label fw-bold small">Discussion / topic <span class="text-danger">*</span> <span class="text-muted fw-normal">— paste lesson content here</span></label>
                <textarea id="quiz_source" class="form-control" rows="7" placeholder="Paste the discussion or topic material here. The generator builds questions from its sentences and key terms…"></textarea>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <small class="text-muted"><span id="quizSrcCount">0</span> characters · <span id="quizSentCount">0</span> usable sentences</small>
                    <button type="button" class="btn btn-sm btn-secondary" id="quizClearBtn"><i class="fas fa-eraser me-1"></i>Clear</button>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /quiz-sec-setup -->

<div id="quiz-sec-questions" class="quiz-tab-panel quiz-sec-panel" style="display:none">
<div class="horizon-card mb-3" id="quizPreviewCard" style="display:none">
    <div class="horizon-card-header">
        <div class="d-flex align-items-center gap-2">
            <div class="horizon-widget-icon-box brand" style="width:40px;height:40px;min-width:40px;font-size:16px"><i class="fas fa-tasks"></i></div>
            <div>
                <h4 class="mb-0 fw-bold">Questions list <span class="badge badge-secondary" id="quizPreviewCount">0</span></h4>
                <small class="text-muted">Auto, manual &amp; imported — review / edit before saving</small>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" id="quiz_title_q" class="form-control form-control-sm quiz-title-mirror" style="max-width:280px" maxlength="150" placeholder="Quiz title *">
            </span>
            <span class="quiz-class-chip quiz-class-chip-sm" data-class-chip title="Class comes from the folder you opened"><i class="fas fa-folder-open"></i><span class="quiz-class-chip-text">No class folder open</span></span>
            <span class="quiz-class-chip quiz-class-chip-sm" data-semester-chip title="Semester"><i class="fas fa-calendar-alt"></i><span class="quiz-class-chip-text">No semester folder open</span></span>
            <span class="quiz-class-chip quiz-class-chip-sm" data-semester-chip title="Semester"><i class="fas fa-calendar-alt"></i><span class="quiz-class-chip-text">No semester folder open</span></span>
            <button type="button" class="btn btn-sm btn-primary quiz-save-btn"><i class="fas fa-save me-1"></i>Save quiz</button>
            <button type="button" class="btn btn-sm btn-secondary" id="quizAddItemBtn"><i class="fas fa-plus me-1"></i>Add item</button>
            <button type="button" class="btn btn-sm btn-secondary" id="quizRegenBtn"><i class="fas fa-sync-alt me-1"></i>Regenerate</button>
        </div>
    </div>
    <div class="horizon-card-body"><div id="quizPreviewList" class="d-flex flex-column gap-2"></div></div>
</div>

</div><!-- /quiz-sec-questions -->

<div id="quiz-sec-manual" class="quiz-tab-panel quiz-sec-panel" style="display:none">
<div class="horizon-card mb-3" id="quizManualCard">
    <div class="horizon-card-header">
        <div class="d-flex align-items-center gap-2">
            <div class="horizon-widget-icon-box green" style="width:40px;height:40px;min-width:40px;font-size:16px"><i class="fas fa-pen-square"></i></div>
            <div>
                <h4 class="mb-0 fw-bold">Manual questions</h4>
                <small class="text-muted">Write your own items — they join the questions list above when you Save</small>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" id="quiz_title_m" class="form-control form-control-sm quiz-title-mirror" style="max-width:280px" maxlength="150" placeholder="Quiz title *">
            <span class="quiz-class-chip quiz-class-chip-sm" data-class-chip title="Class comes from the folder you opened"><i class="fas fa-folder-open"></i><span class="quiz-class-chip-text">No class folder open</span></span>
            <span class="quiz-class-chip quiz-class-chip-sm" data-semester-chip title="Semester"><i class="fas fa-calendar-alt"></i><span class="quiz-class-chip-text">No semester folder open</span></span>
            <div class="d-flex flex-column">
                <label class="small fw-bold mb-1">Time limit (min)</label>
                <input type="number" class="form-control form-control-sm quiz-time-mirror" style="width:95px" min="0" max="300" value="0" title="Time limit (min)">
            </div>
            <div class="d-flex flex-column">
                <label class="small fw-bold mb-1">Question time (sec)</label>
                <input type="number" class="form-control form-control-sm quiz-qtime-mirror" style="width:95px" min="0" max="3600" value="0" title="Question time (sec)">
            </div>
            <button type="button" class="btn btn-sm btn-primary quiz-save-btn"><i class="fas fa-save me-1"></i>Save quiz</button>
        </div>
    </div>
    <div class="horizon-card-body">
        <div class="row g-3">
            <div class="col-12 col-lg-3">
                <label class="form-label fw-bold small" for="quizManualType">Type</label>
                <select id="quizManualType" class="form-select">
                    <option value="multiple_choice" selected>Multiple Choice</option>
                    <option value="identification">Identification</option>
                    <option value="true_false">True or False</option>
                    <option value="essay">Essay (manual grading)</option>
                </select>
            </div>
            <div class="col-12 col-lg-9">
                <label class="form-label fw-bold small" for="quizManualQ">Question <span class="text-danger">*</span></label>
                <textarea id="quizManualQ" class="form-control" rows="2" placeholder="e.g. Which tag creates a table?"></textarea>
            </div>
            <div class="col-12" id="quizManualChoicesWrap">
                <label class="form-label fw-bold small">Choices <span class="text-danger">*</span> <span class="text-muted fw-normal">— tick the correct one</span></label>
                <div id="quizManualChoices" class="d-flex flex-column gap-1"></div>
                <button type="button" class="btn btn-sm btn-secondary mt-2" id="quizManualAddChoice"><i class="fas fa-plus me-1"></i>Add choice</button>
            </div>
            <div class="col-12 col-lg-6" id="quizManualAnswerWrap" style="display:none">
                <label class="form-label fw-bold small" for="quizManualAnswer" id="quizManualAnswerLabel">Correct answer <span class="text-danger">*</span></label>
                <input type="text" id="quizManualAnswer" class="form-control" placeholder="Correct answer">
                <small class="text-muted" id="quizManualAnswerNote"><i class="fas fa-lock me-1"></i>Hidden from students — they only see a text box. The answer never leaves the server.</small>
            </div>
            <div class="col-12 col-lg-3" id="quizManualTFWrap" style="display:none">
                <label class="form-label fw-bold small" for="quizManualTF">Correct answer</label>
                <select id="quizManualTF" class="form-select"><option>True</option><option>False</option></select>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-primary" id="quizManualAddBtn"><i class="fas fa-plus me-1"></i>Add question to list</button>
                <button type="button" class="btn btn-secondary" id="quizManualClearBtn"><i class="fas fa-eraser me-1"></i>Clear</button>
            </div>
        </div>
    </div>
</div>

</div><!-- /quiz-sec-manual -->

<div id="quiz-sec-import" class="quiz-tab-panel quiz-sec-panel" style="display:none">
<div class="horizon-card mb-3" id="quizImportCard">
    <div class="horizon-card-header">
        <div class="d-flex align-items-center gap-2">
            <div class="horizon-widget-icon-box amber" style="width:40px;height:40px;min-width:40px;font-size:16px"><i class="fas fa-paste"></i></div>
            <div>
                <h4 class="mb-0 fw-bold">Import quiz text</h4>
                <small class="text-muted">Paste ready-made questions — they join the questions list above on Save</small>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" id="quiz_title_i" class="form-control form-control-sm quiz-title-mirror" style="max-width:280px" maxlength="150" placeholder="Quiz title *">
            <span class="quiz-class-chip quiz-class-chip-sm" data-class-chip title="Class comes from the folder you opened"><i class="fas fa-folder-open"></i><span class="quiz-class-chip-text">No class folder open</span></span>
            <span class="quiz-class-chip quiz-class-chip-sm" data-semester-chip title="Semester"><i class="fas fa-calendar-alt"></i><span class="quiz-class-chip-text">No semester folder open</span></span>
            <div class="d-flex flex-column">
                <label class="small fw-bold mb-1">Time limit (min)</label>
                <input type="number" class="form-control form-control-sm quiz-time-mirror" style="width:95px" min="0" max="300" value="0" title="Time limit (min)">
            </div>
            <div class="d-flex flex-column">
                <label class="small fw-bold mb-1">Question time (sec)</label>
                <input type="number" class="form-control form-control-sm quiz-qtime-mirror" style="width:95px" min="0" max="3600" value="0" title="Question time (sec)">
            </div>
            <button type="button" class="btn btn-sm btn-primary quiz-save-btn"><i class="fas fa-save me-1"></i>Save quiz</button>
        </div>
    </div>
    <div class="horizon-card-body">
        <label class="form-label fw-bold small" for="quizImportText">Quiz text <span class="text-danger">*</span> <span class="text-muted fw-normal">— numbered questions with lettered choices</span></label>
        <textarea id="quizImportText" class="form-control" rows="8" placeholder="1. Which tag creates a table?&#10;A. &lt;tab&gt;&#10;B. &lt;table&gt;&#10;C. &lt;tbl&gt;&#10;D. &lt;data&gt;&#10;&#10;2. HTML is easy to learn.&#10;&#10;Answer Key:&#10;1. B"></textarea>
        <details class="mt-2">
            <summary class="small fw-bold" style="cursor:pointer">Format guide</summary>
            <pre class="small mt-2 p-3" style="white-space:pre-wrap;background:var(--horizon-table-hover);border-radius:10px;">1. Which tag creates a table?
A. &lt;tab&gt;
B. &lt;table&gt;
C. &lt;tbl&gt;
D. &lt;data&gt;

2. Photosynthesis happens in the leaf.   (no choices = needs a key answer)

True or False items: use True / False as the choices.

Answer Key:   (optional, goes last)
1. B
2. Chloroplast
(a table works too:  1  A   21  B   41  C)

Rules: questions start with 1. 2. … (or 1) 2)); choices are
single letters A–F in order; an optional "Answer Key:" block at
the end maps numbers to letters or answers. Without answers, the
first choice is pre-marked — verify it before saving.</pre>
        </details>
        <div class="d-flex gap-2 flex-wrap mt-2">
            <button type="button" class="btn btn-primary" id="quizParseBtn"><i class="fas fa-file-import me-1"></i>Parse &amp; add to list</button>
            <button type="button" class="btn btn-secondary" id="quizClearImportBtn"><i class="fas fa-eraser me-1"></i>Clear</button>
        </div>
    </div>
</div>
</div><!-- /quiz-sec-import -->

<div id="quiz-sec-saved" class="quiz-tab-panel quiz-sec-panel" style="display:none">
<div class="horizon-card">
    <div class="horizon-card-header">
        <div class="d-flex align-items-center gap-2">
            <div class="horizon-widget-icon-box amber" style="width:40px;height:40px;min-width:40px;font-size:16px"><i class="fas fa-bullhorn"></i></div>
            <div>
                <h4 class="mb-0 fw-bold">Saved quizzes</h4>
                <small class="text-muted">Activate a quiz to notify its class · deactivate to hide it<br><span id="quizDbLabel"></span></small>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" id="quizRefreshBtn"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
    </div>
    <div class="px-3 pt-3 d-flex gap-2 flex-wrap align-items-center" id="quizSavedFilterBar">
      <span class="badge badge-brand" id="quizCtxChip" style="display:none"><i class="fas fa-folder-open me-1"></i><span id="quizCtxLabel"></span></span>
      <div class="position-relative flex-grow-1" style="min-width:200px">
        <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px"></i>
        <input type="search" id="quizSavedSearch" class="form-control form-control-sm" placeholder="Search title or class…" autocomplete="off" style="padding-left:32px;border-radius:10px">
      </div>
      <div class="btn-group btn-group-sm" role="group" aria-label="Filter by status">
        <button type="button" class="btn btn-primary q-status" data-s="all">All</button>
        <button type="button" class="btn btn-secondary q-status" data-s="active">Active</button>
        <button type="button" class="btn btn-secondary q-status" data-s="inactive">Inactive</button>
      </div>
      <small id="quizSavedFilterNote" class="text-muted ms-auto"></small>
    </div>
    <div class="table-responsive">
        <table class="horizon-table mb-0" id="quizTable">
            <thead><tr><th>Title</th><th>Class</th><th class="text-center">Type</th><th class="text-center">Items</th><th class="text-center">Taken</th><th class="text-center">Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody id="quizTableBody"><tr><td class="text-center text-muted py-4">Loading…</td><td class="text-center text-muted py-4"></td><td class="text-center text-muted py-4"></td><td class="text-center text-muted py-4"></td><td class="text-center text-muted py-4"></td><td class="text-center text-muted py-4"></td><td class="text-center text-muted py-4"></td></tr></tbody>
        </table>
    </div>
</div>
</div><!-- /quiz-sec-saved -->
</div><!-- /quiz-panels -->
</div><!-- /quiz-hub -->

<div class="modal fade modern-modal" id="quizResultsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width:900px"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-poll me-2 text-primary"></i><span id="quizResultsTitle">Results</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="quizResultsBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade modern-modal" id="quizViolationsModal" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-shield-alt me-2 text-warning"></i><span id="quizViolationsTitle">Violations</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="quizViolationsBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>
<div class="modal fade modern-modal" id="quizAttemptModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width:900px"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-alt me-2 text-success"></i><span id="quizAttemptTitle">Attempt</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="quizAttemptBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>
<div class="modal fade modern-modal" id="quizCloneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-copy me-2 text-primary"></i>Clone Quiz</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Target class</label>
                <select id="quizCloneClass" class="form-select"></select>
            </div>
            <div class="mb-3"><label class="form-label">New quiz title</label>
                <input type="text" id="quizCloneTitle" class="form-control" placeholder="Enter title for cloned quiz">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="quizCloneConfirm">Clone</button>
        </div>
    </div></div>
</div>

    <div class="modal fade modern-modal" id="quizConfirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered" style="max-width:420px"><div class="modal-content">
        <div class="modal-body text-center" style="padding:28px 24px 20px">
            <span id="quizConfirmIcon" class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 text-danger" style="width:56px;height:56px;font-size:22px;background:rgba(220,53,69,.1)"><i class="fas fa-exclamation-triangle"></i></span>
            <h5 id="quizConfirmTitle" class="fw-bold mb-1" style="color:var(--horizon-text-title)">Are you sure?</h5>
            <p id="quizConfirmText" class="small text-muted mb-0"></p>
        </div>
        <div class="modal-footer border-0 justify-content-center gap-2" style="padding:0 24px 24px">
            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-danger rounded-pill px-4" id="quizConfirmBtn"><i class="fas fa-trash me-1"></i>Delete</button>
        </div>
    </div></div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>


<script>
(function(){
'use strict';
var preview = [];
var quizLastResults = [];
var quizLastList = [];
var quizLastQuizId = 0;
var quizLastQuizTitle = '';
var quizPushComps = [];
var quizPushSubmitted = 0;
var quizPushLoading = false;
var quizPushSel = { compId: 0, compName: '', assessId: 0, assessName: '', hps: 0 };
var quizPushMsg = { type: '', text: '' };
var quizLastSummary = { total: 0, submitted: 0, in_progress: 0, not_taken: 0 };
var quizResultsFilter = 'all';
var STOP = {the:1,a:1,an:1,and:1,or:1,but:1,of:1,to:1,in:1,on:1,at:1,for:1,with:1,by:1,from:1,as:1,is:1,are:1,was:1,were:1,be:1,been:1,being:1,has:1,have:1,had:1,having:1,do:1,does:1,did:1,doing:1,will:1,would:1,should:1,could:1,may:1,might:1,must:1,shall:1,can:1,this:1,that:1,these:1,those:1,it:1,its:1,they:1,them:1,their:1,there:1,here:1,which:1,who:1,whom:1,what:1,when:1,where:1,why:1,how:1,not:1,no:1,yes:1,if:1,then:1,than:1,so:1,such:1,into:1,over:1,under:1,between:1,through:1,during:1,each:1,other:1,some:1,any:1,all:1,both:1,few:1,more:1,most:1,many:1,much:1,own:1,same:1,only:1,very:1,just:1,also:1,about:1,up:1,out:1,off:1,after:1,before:1,because:1,while:1,until:1,against:1,among:1,within:1,without:1,along:1,across:1,behind:1,beyond:1,plus:1,except:1,toward:1,towards:1,upon:1,per:1,via:1,one:1,two:1,three:1,first:1,second:1,new:1,used:1,using:1,use:1,often:1,well:1,even:1,ever:1,never:1,always:1,usually:1,generally:1,particularly:1,especially:1,however:1,therefore:1,thus:1,hence:1,although:1,though:1,since:1,despite:1};
var pendingClone = null;
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function phTime(utcStr){
    // DB timestamps are UTC — render as Philippine 12-hour time.
    if (!utcStr) return '—';
    var m = String(utcStr).trim().match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!m) return String(utcStr);
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)));
    try {
        return d.toLocaleString('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
    } catch (e) { return String(utcStr); }
}
function alertBox(type, msg){
    var el = document.getElementById('quizAlert');
    el.innerHTML = '<div class="horizon-flash-banner horizon-flash-' + type + '" role="alert"><i class="fas ' + (type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle') + '"></i><span style="font-weight:700;font-size:13px">' + esc(msg) + '</span></div>';
    setTimeout(function(){ el.innerHTML = ''; }, 4500);
}
function sentencesOf(text){
    text = String(text || '').replace(/\s+/g, ' ').trim();
    if (!text) return [];
    var parts = text.split(/(?<=[.!?])\s+|\n+/);
    if (!parts.length) parts = [text];
    var out = [];
    parts.forEach(function(p){ p = String(p || '').trim(); if (p.length >= 25 && p.split(/\s+/).length >= 5) out.push(p.length > 400 ? p.slice(0, 400) : p); });
    return out;
}
function keywordsOf(sents, limit){
    var freq = {};
    sents.forEach(function(s){
        s.split(/[^A-Za-z0-9\-]+/).forEach(function(w){
            w = String(w || '').replace(/^-+|-+$/g, '');
            if (w.length < 4) return;
            var lw = w.toLowerCase();
            if (STOP[lw] || /^\d+$/.test(w)) return;
            if (!freq[lw]) freq[lw] = { word: w, n: 0 };
            freq[lw].n++;
            if (w.length > freq[lw].word.length) freq[lw].word = w;
        });
    });
    var arr = Object.keys(freq).map(function(k){ return freq[k]; });
    arr.sort(function(a, b){ return b.n !== a.n ? b.n - a.n : b.word.length - a.word.length; });
    return arr.slice(0, limit || 80).map(function(f){ return f.word; });
}
function escRegExp(s){ return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
function swapTerm(sentence, kw, rep){
    // Replace whole-word occurrences of the answer term. Substring-only
    // matches (e.g. "number" inside "numbers") are left intact — swapping
    // there manufactures frankenwords like "numberss". Callers already fall
    // back when the text comes back unchanged.
    var s = String(sentence), repS = String(rep);
    try {
        var probe = new RegExp('\\b' + escRegExp(kw) + '\\b', 'i');
        if (!probe.test(s)) return s;
        return s.replace(new RegExp('\\b' + escRegExp(kw) + '\\b', 'gi'), repS);
    }
    catch (e) {
        var out = '', idx = 0, lkw = String(kw).toLowerCase(), found = false;
        while (true){
            var p = s.toLowerCase().indexOf(lkw, idx);
            if (p < 0){ out += s.slice(idx); break; }
            found = true;
            out += s.slice(idx, p) + repS;
            idx = p + String(kw).length;
        }
        return found ? out : s;
    }
}
function termsOverlap(a, b){
    // "table"/"tables", "cell"/"cellar": too similar to sit side by side.
    var la = String(a).toLowerCase(), lb = String(b).toLowerCase();
    if (la === lb) return true;
    return la.indexOf(lb) >= 0 || lb.indexOf(la) >= 0;
}
function cleanChoices(arr){
    var out = [], seen = {};
    (arr || []).forEach(function(c){
        var v = String(c == null ? '' : c).replace(/\s+/g, ' ').trim();
        if (!v || seen[v.toLowerCase()]) return;
        seen[v.toLowerCase()] = 1; out.push(v);
    });
    return out;
}
function wrongTermsFor(pool, s, kw, maxN){
    var wrongs = [], ls = String(s).toLowerCase();
    for (var g = 0; g < pool.length && wrongs.length < maxN; g++){
        var cand = pool[g];
        if (termsOverlap(cand, kw)) continue;
        if (ls.indexOf(String(cand).toLowerCase()) >= 0) continue;
        wrongs.push(cand);
    }
    return wrongs;
}
function toWhQuestion(sentence, kw){
    // Definitional sentences ("X is ...") become proper "What is ...?" questions.
    var s = String(sentence || '').trim();
    var m = s.match(new RegExp('^(?:(?:the|a|an)\\s+)?' + escRegExp(kw) + '\\s+(is|are|was|were)\\s+(.+?)\\s*[.!?]*$', 'i'));
    if (!m) return null;
    var rest = String(m[2]).trim().replace(/^([A-Z])(?=[a-z])/, function(c){ return c.toLowerCase(); });
    if (rest.length < 3) return null;
    var rw = rest.split(/\s+/)[0].toLowerCase().replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '');
    if (QUIZ_WH_BAD[rw]) return null;
    if (rest.toLowerCase().indexOf(String(kw).toLowerCase()) >= 0) return null;
    return 'What ' + String(m[1]).toLowerCase() + ' ' + rest + '?';
}
var QUIZ_ROLES = ['tag','element','attribute','property','process','organelle','theory','vessel','tissue','cell','organ','system','list','table','law','principle','method','technique','device','tool','instrument','part','section','chapter','type','kind','form','stage','phase','step','component','structure','unit','reaction','cycle','layer','region','muscle','bone','gland','hormone','planet','star','country','city','river','mountain','ocean','continent','language','currency','sport','game','dance','song','festival','drug','disease','symptom','profession','occupation'];
var QUIZ_AUX = {is:1,are:1,was:1,were:1,am:1,been:1,being:1,has:1,have:1,had:1,does:1,did:1,do:1,will:1,would:1,can:1,could:1,shall:1,should:1,may:1,might:1,must:1,go:1,goes:1,say:1,says:1};
var QUIZ_ROLE_SET = {};
QUIZ_ROLES.forEach(function(r){ QUIZ_ROLE_SET[r] = 1; });
var QUIZ_WH_BAD = {that:1,which:1,who:1,whom:1,whose:1,where:1,when:1,how:1,why:1,what:1,whether:1,if:1,to:1,and:1,but:1,or:1,not:1};
var QUIZ_HEAD_BAD = {in:1,on:1,at:1,for:1,with:1,by:1,from:1,through:1,during:1,after:1,before:1,because:1,when:1,while:1,where:1,which:1,who:1,whom:1,what:1,how:1,why:1,if:1,although:1,though:1,despite:1,between:1,among:1,against:1,under:1,over:1,into:1,without:1,within:1,along:1,across:1,behind:1,beyond:1,via:1,per:1,plus:1,except:1,until:1,toward:1,towards:1,upon:1,as:1,of:1,to:1,and:1,but:1,or:1,nor:1,so:1,yet:1,then:1,than:1,that:1,this:1,these:1,those:1,it:1,its:1,they:1,them:1,their:1,he:1,she:1,we:1,you:1,there:1,here:1,his:1,her:1,our:1,your:1,my:1,not:1};
var QUIZ_REM_BAD = {which:1,that:1,who:1,whom:1,whose:1,where:1,when:1,and:1,but:1,or:1,nor:1,for:1,so:1,yet:1,because:1,if:1,as:1,since:1,although:1,though:1,unless:1,until:1,whereas:1,to:1,of:1,in:1,on:1,at:1,by:1,with:1,from:1,into:1,is:1,are:1,was:1,were:1,be:1,been:1,being:1,am:1,a:1,an:1,the:1,this:1,these:1,those:1,it:1,they:1,he:1,she:1,we:1,you:1,there:1,here:1,not:1,very:1,really:1,quite:1,rather:1,almost:1,nearly:1,hardly:1,barely:1,merely:1,quickly:1,slowly:1,carefully:1,easily:1,directly:1,indirectly:1,mainly:1,mostly:1,usually:1,often:1,always:1,never:1,sometimes:1,generally:1,typically:1,normally:1,rarely:1,simply:1,also:1,just:1,even:1,still:1,already:1,effectively:1,efficiently:1,primarily:1,largely:1,highly:1,widely:1,commonly:1,regularly:1,constantly:1,recently:1,currently:1,previously:1,finally:1,therefore:1,however:1,moreover:1,furthermore:1,instead:1,indeed:1};
var QUIZ_VERBS = {is:1,are:1,was:1,were:1,has:1,have:1,had:1,does:1,did:1,will:1,would:1,can:1,could:1,shall:1,should:1,may:1,might:1,must:1,creates:1,create:1,contains:1,contain:1,defines:1,define:1,provides:1,provide:1,uses:1,use:1,indicates:1,indicate:1,inserts:1,insert:1,makes:1,make:1,absorbs:1,absorb:1,speeds:1,speed:1,proposes:1,propose:1,proposed:1,includes:1,include:1,means:1,mean:1,shows:1,show:1,displays:1,display:1,forms:1,form:1,produces:1,produce:1,releases:1,release:1,converts:1,convert:1,moves:1,move:1,holds:1,hold:1,stores:1,store:1,carries:1,carry:1,supports:1,support:1,allows:1,allow:1,helps:1,help:1,causes:1,cause:1,occurs:1,occur:1,stands:1,stand:1,gives:1,give:1,takes:1,take:1,needs:1,need:1,requires:1,require:1,called:1,known:1,come:1,came:1,see:1,saw:1,seen:1,get:1,got:1,gotten:1,find:1,found:1,know:1,knew:1,think:1,thought:1,bring:1,brought:1,write:1,wrote:1,written:1,speak:1,spoke:1,spoken:1,choose:1,chose:1,chosen:1,draw:1,drew:1,drawn:1,grow:1,grew:1,grown:1,throw:1,threw:1,thrown:1,begin:1,began:1,begun:1,drink:1,drank:1,drunk:1,ring:1,rang:1,rung:1,sing:1,sang:1,sung:1,swim:1,swam:1,swum:1,eat:1,ate:1,eaten:1,fall:1,fell:1,fallen:1,forget:1,forgot:1,forgotten:1,freeze:1,froze:1,frozen:1,hide:1,hid:1,hidden:1,rise:1,rose:1,risen:1,shake:1,shook:1,shaken:1,steal:1,stole:1,stolen:1,tear:1,tore:1,torn:1,wear:1,wore:1,worn:1,drive:1,drove:1,driven:1,ride:1,rode:1,ridden:1,ran:1,run:1,went:1,built:1,sent:1,spent:1,taught:1,caught:1,fought:1,bought:1,sold:1,told:1,heard:1,held:1,kept:1,slept:1,swept:1,left:1,met:1,paid:1,laid:1,flow:1,flows:1,flowed:1,walk:1,walks:1,walked:1,talk:1,talks:1,talked:1,live:1,lives:1,lived:1,love:1,loves:1,loved:1,work:1,works:1,worked:1,play:1,plays:1,played:1,study:1,studies:1,studied:1,learn:1,learns:1,learned:1,teach:1,teaches:1,listen:1,listens:1,listened:1,watch:1,watches:1,watched:1,open:1,opens:1,opened:1,close:1,closes:1,closed:1,start:1,starts:1,started:1,stop:1,stops:1,stopped:1,end:1,ends:1,ended:1,join:1,joins:1,joined:1,separate:1,separates:1,separated:1,divide:1,divides:1,divided:1,multiply:1,multiplies:1,multiplied:1,add:1,adds:1,added:1,subtract:1,subtracts:1,subtracted:1,share:1,shares:1,shared:1,serve:1,serves:1,served:1,breathe:1,breathes:1,breathed:1,pump:1,pumps:1,pumped:1,filter:1,filters:1,filtered:1,transport:1,transports:1,transported:1,protect:1,protects:1,protected:1,float:1,floats:1,floated:1,sink:1,sinks:1,sank:1,sunk:1,pull:1,pulls:1,pulled:1,push:1,pushes:1,pushed:1,turn:1,turns:1,turned:1,spin:1,spins:1,spun:1,rotate:1,rotates:1,rotated:1,orbit:1,orbits:1,orbited:1,reflect:1,reflects:1,reflected:1,attract:1,attracts:1,attracted:1,repel:1,repels:1,repelled:1,expand:1,expands:1,expanded:1,contract:1,contracts:1,contracted:1,stretch:1,stretches:1,stretched:1,shrink:1,shrinks:1,shrank:1,shrunk:1,break:1,breaks:1,broke:1,broken:1,melt:1,melts:1,melted:1,boil:1,boils:1,boiled:1,evaporate:1,evaporates:1,evaporated:1,condense:1,condenses:1,condensed:1,dissolve:1,dissolves:1,dissolved:1,mix:1,mixes:1,mixed:1,combine:1,combines:1,combined:1,split:1,splits:1,cut:1,cuts:1,dig:1,digs:1,dug:1,planted:1,hunt:1,hunts:1,hunted:1,gather:1,gathers:1,gathered:1,migrate:1,migrates:1,migrated:1,shed:1,sheds:1,hatch:1,hatches:1,hatched:1,digest:1,digests:1,digested:1,reproduce:1,reproduces:1,reproduced:1,evolve:1,evolves:1,evolved:1,adapt:1,adapts:1,adapted:1,survive:1,survives:1,survived:1,compete:1,competes:1,competed:1,communicate:1,communicates:1,communicated:1,respond:1,responds:1,responded:1,react:1,reacts:1,reacted:1,detect:1,detects:1,detected:1,regulate:1,regulates:1,regulated:1,maintain:1,maintains:1,maintained:1,generate:1,generates:1,generated:1,deliver:1,delivers:1,delivered:1,collect:1,collects:1,collected:1,describe:1,describes:1,described:1,explain:1,explains:1,explained:1,discuss:1,discusses:1,discussed:1,mentioned:1,stated:1,noted:1,refer:1,refers:1,referred:1,relate:1,relates:1,related:1,compare:1,compares:1,compared:1,contrast:1,contrasts:1,contrasted:1,match:1,matches:1,matched:1,measure:1,measures:1,measured:1,weigh:1,weighs:1,weighed:1,cost:1,costs:1,last:1,lasts:1,lasted:1,consist:1,consists:1,consisted:1,rely:1,relies:1,relied:1,belong:1,belongs:1,belonged:1,spell:1,spells:1,spelled:1,label:1,labels:1,labeled:1,listed:1,call:1,calls:1,ask:1,asks:1,asked:1,answered:1,reply:1,replies:1,replied:1,continue:1,continues:1,continued:1,happen:1,happens:1,happened:1,exist:1,exists:1,existed:1,remain:1,remains:1,remained:1,sit:1,sits:1,sat:1,hang:1,hangs:1,hung:1,wake:1,wakes:1,woke:1,woken:1,die:1,dies:1,died:1,kill:1,kills:1,killed:1,heal:1,heals:1,healed:1,cure:1,cures:1,cured:1,prevent:1,prevents:1,prevented:1,avoid:1,avoids:1,avoided:1,escape:1,escapes:1,escaped:1,seek:1,seeks:1,sought:1,search:1,searches:1,searched:1,explore:1,explores:1,explored:1,discover:1,discovers:1,discovered:1,invent:1,invents:1,invented:1,create:1,creates:1,created:1,design:1,designs:1,designed:1,compose:1,composes:1,composed:1,construct:1,constructs:1,constructed:1,destroy:1,destroys:1,destroyed:1,damage:1,damages:1,damaged:1,repair:1,repairs:1,repaired:1,fix:1,fixes:1,fixed:1,clean:1,cleans:1,cleaned:1,wash:1,washes:1,washed:1,cook:1,cooks:1,cooked:1,feed:1,feeds:1,fed:1,lie:1,lies:1,lay:1,lain:1,arise:1,arises:1,arose:1,arisen:1,runs:1,comes:1,sees:1,gets:1,finds:1,knows:1,thinks:1,brings:1,writes:1,speaks:1,chooses:1,draws:1,grows:1,throws:1,begins:1,drinks:1,rings:1,sings:1,swims:1,eats:1,falls:1,forgets:1,freezes:1,hides:1,rises:1,shakes:1,steals:1,tears:1,wears:1,drives:1,rides:1,builds:1,sends:1,spends:1,catches:1,fights:1,buys:1,sells:1,tells:1,hears:1,keeps:1,sleeps:1,sweeps:1,leaves:1,meets:1,pays:1,lays:1,become:1,becomes:1,became:1,put:1,puts:1,set:1,sets:1,hit:1,hits:1,lead:1,leads:1,led:1,read:1,reads:1,said:1,did:1,cover:1,covers:1,covered:1,bear:1,bears:1,bore:1,born:1,face:1,faces:1,faced:1,cross:1,crosses:1,crossed:1,enter:1,enters:1,entered:1,reach:1,reaches:1,reached:1,touch:1,touches:1,touched:1,affect:1,affects:1,affected:1,effect:1,effects:1,effected:1,reduce:1,reduces:1,reduced:1,increase:1,increases:1,increased:1,decrease:1,decreases:1,decreased:1,improve:1,improves:1,improved:1,develop:1,develops:1,developed:1,vary:1,varies:1,varied:1,range:1,ranges:1,ranged:1,tend:1,tends:1,tended:1,seem:1,seems:1,seemed:1,appear:1,appears:1,appeared:1,stay:1,stays:1,stayed:1,own:1,owns:1,owned:1,involve:1,involves:1,involved:1,represent:1,represents:1,represented:1,demonstrate:1,demonstrates:1,demonstrated:1,suggest:1,suggests:1,suggested:1,imply:1,implies:1,implied:1,conclude:1,concludes:1,concluded:1,decide:1,decides:1,decided:1,determine:1,determines:1,determined:1,control:1,controls:1,controlled:1,pass:1,passes:1,passed:1,fail:1,fails:1,failed:1,marked:1,graded:1,tested:1,reviewed:1,quiz:1,quizzes:1,quizzed:1,copy:1,click:1,clicks:1,clicked:1,press:1,presses:1,pressed:1,typed:1,select:1,selects:1,selected:1,submit:1,submits:1,submitted:1,upload:1,uploads:1,uploaded:1,print:1,prints:1,printed:1,save:1,saves:1,saved:1,delete:1,deletes:1,deleted:1,edit:1,edits:1,edited:1};
function toThirdSingular(verb){
    // "speed" -> "speeds" so "What speeds ...?" reads professionally.
    var v = String(verb || '');
    if (!/^[A-Za-z][A-Za-z\-]*$/.test(v) || v.length < 2) return v;
    var L = v.toLowerCase();
    var keep = {is:1,are:1,was:1,were:1,am:1,been:1,being:1,has:1,does:1,goes:1,says:1,can:1,could:1,will:1,would:1,shall:1,should:1,may:1,might:1,must:1,ought:1,did:1,had:1,said:1,read:1};
    if (keep[L]) return v;
    if (/ing$/.test(L) || /ied$/.test(L)) return v;
    if (/ed$/.test(L) && !/[^e]eed$/.test(L)) return v;
    if (/(ses|xes|zes|ches|shes|oes)$/.test(L)) return v;
    if (/([^aeiou])ies$/.test(L)) return v;
    if (/[^s]s$/.test(L)) return v;
    var out = L;
    var irr = {have:'has', do:'does', go:'goes', say:'says'};
    if (irr[L]) out = irr[L];
    else if (/[^aeiou]y$/.test(L)) out = L.slice(0, -1) + 'ies';
    else if (/(s|x|z|ch|sh|o)$/.test(L)) out = L + 'es';
    else out = L + 's';
    if (/^[A-Z]/.test(v)) out = out.charAt(0).toUpperCase() + out.slice(1);
    return out;
}
function toDirectQuestion(sentence, kw){
    // Build a proper "Which tag creates ...?", "What absorbs ...?",
    // "Who proposed ...?" style question from a source sentence.
    var s = String(sentence || '').trim(), k = String(kw || '');
    if (!s || !k) return null;
    var wh = toWhQuestion(s, k);
    if (wh) return wh;
    var ekw = escRegExp(k);
    var pos = s.search(new RegExp('\\b' + ekw + '\\b', 'i'));
    if (pos < 0) pos = s.toLowerCase().indexOf(k.toLowerCase());
    if (pos < 0) return null;
    var after = s.slice(pos + k.length);
    var role = null, subjEnd = pos + k.length;
    var rm = after.match(new RegExp('^\\s+(' + QUIZ_ROLES.join('s?|') + 's?)\\b', 'i'));
    if (rm){
        var raw = String(rm[1]).toLowerCase();
        role = QUIZ_ROLE_SET[raw] ? raw : (QUIZ_ROLE_SET[raw.replace(/s$/, '')] ? raw.replace(/s$/, '') : raw);
        subjEnd = pos + k.length + rm[0].length;
    }
    var head = s.slice(0, subjEnd);
    if (head.length > 48 || /[,;:]/.test(head)) return null;
    var hwords = head.trim().split(/\s+/);
    var hcount = hwords.length - (/^(the|a|an)$/i.test(hwords[0]) ? 1 : 0);
    if (hcount < 1 || hcount > 3) return null;
    var first = hwords[0].toLowerCase();
    if (QUIZ_HEAD_BAD[first]) return null;
    var remainder = s.slice(subjEnd).trim().replace(/[\s.,;:!?…]+$/, '');
    if (remainder.length < 3) return null;
    var rwords = remainder.split(/\s+/);
    var rfirst = rwords[0].toLowerCase().replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '');
    if (QUIZ_REM_BAD[rfirst]) return null;
    var hasVerb = false;
    for (var vi = 0; vi < rwords.length; vi++){
        if (QUIZ_VERBS[rwords[vi].toLowerCase().replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '')]){ hasVerb = true; break; }
    }
    if (!hasVerb) return null;
    var qw;
    // "What" is never grammatically wrong; "Who" misfires on Title-Case
    // common nouns, so it is intentionally not used.
    if (role) qw = 'Which ' + role;
    else qw = 'What';
    var parts = remainder.split(/\s+/);
    var vclean = parts[0].replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '');
    if (!vclean || vclean.length < 2) return null;
    var vkey = vclean.toLowerCase();
    // The first word must be a known verb. Guessing from word endings
    // produced garbage like "What ses …?" (single letters), "What ups …?"
    // or "What informations …?" (nouns) — those now fall back cleanly.
    if (!QUIZ_VERBS[vkey]) return null;
    if (parts.length > 1){
        // The word after the verb must be ordinary prose. Code fragments
        // ("names = {", "types arrayName[size]") or a second verb ("plant
        // produces", "form requires") mean vclean is a noun sharing spelling
        // with a verb — bail out instead of printing nonsense.
        var w2 = parts[1].replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '');
        if (w2 === '' || /[a-z].*[A-Z]/.test(w2)) return null;
        if (!/^[A-Za-z]+$/.test(w2) && !/^\d+$/.test(w2)) return null;
        var w2v = w2.toLowerCase();
        if (!QUIZ_AUX[vkey] && QUIZ_VERBS[w2v]) return null;
    }
    var conj = toThirdSingular(vclean);
    conj = conj.charAt(0).toLowerCase() + conj.slice(1);
    var q = (qw + ' ' + conj + (parts.length > 1 ? ' ' + parts.slice(1).join(' ') : '') + '?').replace(/\s+/g, ' ');
    return q;
}
function pickDirect(pool, s, startKi, used){
    // First keyword (from offset) occurring in the sentence that builds a
    // proper direct question — skipping already-used pairs so twins never
    // repeat. Null when the sentence shape allows none.
    var n = pool.length;
    used = used || {};
    for (var t = 0; t < n; t++){
        var idx = (startKi + t) % n, kw = pool[idx];
        if (String(s).toLowerCase().indexOf(String(kw).toLowerCase()) < 0) continue;
        if (used[String(s) + '||' + String(kw)]) continue;
        if (toDirectQuestion(s, kw)) return { kw: kw, idx: idx };
    }
    return null;
}
function pickAny(pool, s, startKi, used){
    var n = pool.length;
    used = used || {};
    for (var t = 0; t < n; t++){
        var idx = (startKi + t) % n, kw = pool[idx];
        if (String(s).toLowerCase().indexOf(String(kw).toLowerCase()) < 0) continue;
        if (used[String(s) + '||' + String(kw)]) continue;
        return { kw: kw, idx: idx };
    }
    return null;
}
function firstPair(pool, sents, startSi, used){
    // First (sentence, keyword-in-it) pair scanning sentences from an offset.
    used = used || {};
    for (var a = 0; a < sents.length; a++){
        var s = sents[(startSi + a) % sents.length];
        for (var b = 0; b < pool.length; b++){
            if (String(s).toLowerCase().indexOf(String(pool[b]).toLowerCase()) < 0) continue;
            if (used[String(s) + '||' + String(pool[b])]) continue;
            return { s: s, kw: pool[b] };
        }
    }
    return null;
}
function shuffleChoices(choices, seedNum, salt){
    var out = choices.slice();
    for (var k = out.length - 1; k > 0; k--){
        var r = (seedNum * 7 + k * 13 + salt) % (k + 1);
        var tmp = out[k]; out[k] = out[r]; out[r] = tmp;
    }
    return out;
}
function errorSpotItem(pool, s, kw, qtype, numChoices, seedNum){
    // "Identify the incorrect word" fallback: valid, professional, and needs
    // no placeholder words and no blanks.
    var wrongList = wrongTermsFor(pool, s, kw, 1);
    if (!wrongList.length){
        // Last resort: allow a term from the same sentence (still never a
        // look-alike of the answer) so the item keeps its requested type.
        for (var g = 0; g < pool.length && !wrongList.length; g++){
            if (!termsOverlap(pool[g], kw)) wrongList = [pool[g]];
        }
    }
    var wrong = wrongList[0] || null;
    if (!wrong) return null;
    var falseS = swapTerm(s, kw, wrong);
    if (falseS === s) return null;
    var stem = 'Which word in the following statement is incorrect according to the discussion?\n\n"' + falseS + '"';
    if (qtype === 'identification') return { qtype: 'identification', question: stem, choices: null, correct_answer: wrong };
    var choices = [wrong], seen = {};
    seen[String(wrong).toLowerCase()] = 1;
    function tryAdd(c){
        var lc = String(c).toLowerCase();
        if (seen[lc]) return false;
        seen[lc] = 1; choices.push(c); return true;
    }
    for (var g = 0; g < pool.length && choices.length < numChoices; g++){
        if (termsOverlap(pool[g], wrong)) continue;
        if (falseS.toLowerCase().indexOf(String(pool[g]).toLowerCase()) < 0) continue;
        tryAdd(pool[g]);
    }
    for (var h = 0; h < pool.length && choices.length < numChoices; h++){
        if (termsOverlap(pool[h], wrong)) continue;
        tryAdd(pool[h]);
    }
    var padN = 0;
    while (choices.length < numChoices && padN <= 10){
        var pad = padN === 0 ? 'None of the above' : 'None of the above (' + (padN + 1) + ')';
        padN++; tryAdd(pad);
    }
    return { qtype: 'multiple_choice', question: 'Choose the correct answer:\n\n' + stem, choices: shuffleChoices(cleanChoices(choices), seedNum, String(wrong).length), correct_answer: wrong };
}
function buildOneQuizItem(sents, pool, numChoices, quizType, seq, i, st){
    // Build a single item, advancing the st.si / st.ki cursors and marking
    // used (sentence, keyword) pairs so later items stay fresh.
    st.used = st.used || {};
    function markUsed(s, kw){ st.used[String(s) + '||' + String(kw)] = 1; }
    var t = quizType === 'mixed' ? seq[i % 3] : (seq.indexOf(quizType) >= 0 ? quizType : 'multiple_choice');
    var item;
    if (t === 'true_false'){
        var sTF = null, kwTF = null, gTF = 0;
        while (kwTF === null && gTF < sents.length + 1){
            var candTF = sents[st.si % sents.length];
            var pickTF = pickAny(pool, candTF, st.ki, st.used);
            if (pickTF){ sTF = candTF; kwTF = pickTF.kw; st.ki = pickTF.idx + 1; markUsed(sTF, kwTF); }
            else { st.si++; gTF++; }
        }
        if (kwTF === null){ sTF = sents[st.si % sents.length]; kwTF = pool[st.ki % pool.length]; st.ki++; markUsed(sTF, kwTF); }
        var isTrue = (i % 2 === 0);
        if (isTrue){ item = { qtype: 'true_false', question: 'True or False:\n\n' + sTF, choices: ['True', 'False'], correct_answer: 'True' }; }
        else {
            var wrong = wrongTermsFor(pool, sTF, kwTF, 1)[0] || null;
            if (wrong === null){ item = { qtype: 'true_false', question: 'True or False:\n\nIt is not true that ' + sTF.charAt(0).toLowerCase() + sTF.slice(1), choices: ['True', 'False'], correct_answer: 'False' }; }
            else {
                var fs = swapTerm(sTF, kwTF, wrong);
                if (fs === sTF) fs = 'It is not true that ' + sTF.charAt(0).toLowerCase() + sTF.slice(1);
                item = { qtype: 'true_false', question: 'True or False:\n\n' + fs, choices: ['True', 'False'], correct_answer: 'False' };
            }
        }
        st.si++; st.ki++;
        return item;
    }
    // MC/ID: seek a sentence with a direct-capable keyword so the
    // stem is always a proper question — no placeholders, no blanks.
    var sDQ = null, kwDQ = null, gDQ = 0;
    while (kwDQ === null && gDQ < sents.length + 1){
        var candDQ = sents[st.si % sents.length];
        var pickDQ = pickDirect(pool, candDQ, st.ki, st.used);
        if (pickDQ){ sDQ = candDQ; kwDQ = pickDQ.kw; st.ki = pickDQ.idx + 1; markUsed(sDQ, kwDQ); }
        else { st.si++; gDQ++; }
    }
    if (kwDQ !== null){
        var dq = toDirectQuestion(sDQ, kwDQ);
        if (t === 'identification'){
            item = { qtype: 'identification', question: dq, choices: null, correct_answer: kwDQ };
        } else {
            var choices = cleanChoices([kwDQ]);
            for (var g = 0; g < pool.length && choices.length < numChoices; g++){
                var cand = pool[g];
                var dup = false;
                for (var d = 0; d < choices.length; d++){ if (termsOverlap(choices[d], cand)){ dup = true; break; } }
                if (dup) continue;
                if (sDQ.toLowerCase().indexOf(String(cand).toLowerCase()) >= 0) continue;
                choices.push(String(cand).replace(/\s+/g, ' ').trim());
            }
            choices = cleanChoices(choices);
            var padN = 0;
            while (choices.length < numChoices){
                var pad = padN === 0 ? 'None of the above' : 'None of the above (' + (padN + 1) + ')';
                padN++;
                var pexists = false;
                for (var px = 0; px < choices.length; px++){ if (choices[px].toLowerCase() === pad.toLowerCase()){ pexists = true; break; } }
                if (!pexists) choices.push(pad);
                if (padN > 10) break;
            }
            item = { qtype: 'multiple_choice', question: 'Choose the correct answer:\n\n' + dq, choices: shuffleChoices(choices, i, String(kwDQ).length), correct_answer: kwDQ };
        }
    } else {
        // No sentence yields a direct question: spot-the-error item.
        var fp = firstPair(pool, sents, st.si, st.used);
        if (!fp) fp = firstPair(pool, sents, st.si, {});
        if (!fp) fp = { s: sents[st.si % sents.length], kw: pool[st.ki % pool.length] };
        markUsed(fp.s, fp.kw);
        var fb = errorSpotItem(pool, fp.s, fp.kw, t, numChoices, i);
        if (fb) item = fb;
        else item = { qtype: 'true_false', question: 'True or False:\n\n' + fp.s, choices: ['True', 'False'], correct_answer: 'True' };
        st.ki++;
    }
    st.si++; st.ki++;
    return item;
}
function generateItems(source, numItems, numChoices, quizType){
    var sents = sentencesOf(source);
    if (!sents.length) return { error: 'Paste a longer discussion first (need several full sentences).' };
    var pool = keywordsOf(sents, 80);
    if (!pool.length) return { error: 'Could not find key terms — paste more descriptive text.' };
    var seq = ['multiple_choice', 'identification', 'true_false'];
    var items = [], st = { si: 0, ki: 0 }, seen = {};
    for (var i = 0; i < numItems; i++){
        // Retry with fresh cursors so twin questions never repeat.
        var made = null;
        for (var att = 0; att < 10; att++){
            var it = buildOneQuizItem(sents, pool, numChoices, quizType, seq, i, st);
            var key = it.qtype + '|' + it.question + '|' + it.correct_answer;
            if (!seen[key] || att === 9){ seen[key] = 1; made = it; break; }
        }
        items.push(made);
    }
    return { items: items };
}
function typeBadge(t){
    var map = { multiple_choice: ['MC', 'badge-brand'], identification: ['ID', 'badge-secondary'], true_false: ['T/F', 'badge-success'], essay: ['ES', 'badge-warning'] };
    var m = map[t] || ['?', 'badge-secondary'];
    return '<span class="badge ' + m[1] + '">' + m[0] + '</span>';
}
function renderPreview(){
    var card = document.getElementById('quizPreviewCard');
    var list = document.getElementById('quizPreviewList');
    document.getElementById('quizPreviewCount').textContent = preview.length;
    var secBadge = document.getElementById('quizSecQCount');
    if (secBadge) secBadge.textContent = preview.length;
    if (!preview.length){ card.style.display = 'none'; list.innerHTML = ''; return; }
    card.style.display = 'block';
    var html = '';
    preview.forEach(function(q, i){
        html += '<div class="p-3" style="border:1px solid var(--horizon-card-border);border-radius:12px;background:var(--horizon-card-bg)" data-i="' + i + '">';
        html += '<div class="d-flex align-items-center gap-2 mb-2 flex-wrap"><span class="badge badge-secondary">#' + (i + 1) + '</span>' + typeBadge(q.qtype);
        html += '<select class="form-select form-select-sm q-type" style="max-width:170px"><option value="multiple_choice"' + (q.qtype === 'multiple_choice' ? ' selected' : '') + '>Multiple Choice</option><option value="identification"' + (q.qtype === 'identification' ? ' selected' : '') + '>Identification</option><option value="true_false"' + (q.qtype === 'true_false' ? ' selected' : '') + '>True or False</option><option value="essay"' + (q.qtype === 'essay' ? ' selected' : '') + '>Essay</option></select>';
        html += '<button type="button" class="btn btn-sm btn-secondary ms-auto q-del"><i class="fas fa-trash text-danger"></i></button></div>';
        html += '<textarea class="form-control form-control-sm q-text mb-2" rows="3" style="white-space:pre-line">' + esc(q.question) + '</textarea>';
        if (q.qtype === 'multiple_choice'){
            html += '<div class="q-choices">';
            (q.choices || []).forEach(function(c, ci){
                html += '<div class="input-group input-group-sm mb-1"><span class="input-group-text"><input type="radio" name="qcorr' + i + '" value="' + ci + '"' + (String(c).toLowerCase() === String(q.correct_answer).toLowerCase() ? ' checked' : '') + ' title="Mark as correct"></span><input type="text" class="form-control q-choice" value="' + esc(c) + '"><button type="button" class="btn btn-outline-danger q-choice-del" title="Remove choice"><i class="fas fa-times"></i></button></div>';
            });
            html += '</div><button type="button" class="btn btn-sm btn-secondary q-choice-add"><i class="fas fa-plus me-1"></i>Add choice</button>';
        } else if (q.qtype === 'true_false'){
            html += '<select class="form-select form-select-sm q-tf" style="max-width:220px"><option' + (q.correct_answer === 'True' ? ' selected' : '') + '>True</option><option' + (q.correct_answer === 'False' ? ' selected' : '') + '>False</option></select>';
        } else {
            html += '<input type="text" class="form-control form-control-sm q-answer" value="' + esc(q.correct_answer) + '" placeholder="Correct answer">';
        }
        html += '</div>';
    });
    list.innerHTML = html;
}
function syncPreviewFromDOM(){
    // Safely sync the preview array from DOM, guarding against missing elements
    document.querySelectorAll('#quizPreviewList > div[data-i]').forEach(function(box){
        var i = parseInt(box.getAttribute('data-i'), 10);
        var q = preview[i];
        if (!q) return;
        // Type dropdown
        var typeEl = box.querySelector('.q-type');
        if (typeEl) q.qtype = typeEl.value;
        // Question textarea
        var textEl = box.querySelector('.q-text');
        if (textEl) q.question = textEl.value;
        if (q.qtype === 'multiple_choice'){
            var vals = [];
            box.querySelectorAll('.q-choice').forEach(function(inp){ vals.push(inp.value); });
            var checked = box.querySelector('input[type="radio"]:checked');
            q.choices = vals;
            q.correct_answer = checked ? vals[parseInt(checked.value, 10)] : (vals[0] || '');
        } else if (q.qtype === 'true_false'){
            q.choices = ['True', 'False'];
            var tfEl = box.querySelector('.q-tf');
            if (tfEl) q.correct_answer = tfEl.value;
        } else {
            q.choices = null;
            var ansEl = box.querySelector('.q-answer');
            if (ansEl) q.correct_answer = ansEl.value;
        }
    });
}
function doGenerate(){
    var src = document.getElementById('quiz_source').value;
    var n = Math.max(1, Math.min(100, parseInt(document.getElementById('quiz_items').value, 10) || 10));
    var c = Math.max(2, Math.min(5, parseInt(document.getElementById('quiz_choices').value, 10) || 4));
    var t = document.getElementById('quiz_type').value;
    var r = generateItems(src, n, c, t);
    if (r.error){ alertBox('danger', r.error); return; }
    preview = r.items;
    renderPreview();
    quizShowSec('questions');
    alertBox('success', 'Generated ' + preview.length + ' question(s) — review, then Save.');
    document.getElementById('quizPreviewCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function collectPayload(){
    syncPreviewFromDOM();
    var globalQTime = parseInt((document.getElementById('quiz_qtime') || {}).value, 10) || 0;
    preview.forEach(function(q){ q.time_limit_seconds = globalQTime; });
    return {
        quiz_id: document.getElementById('quiz_edit_id').value || '',
        class_id: document.getElementById('quiz_class').value || '',
        title: document.getElementById('quiz_title').value.trim(),
        source_text: document.getElementById('quiz_source').value,
        quiz_type: document.getElementById('quiz_type').value,
        num_items: parseInt(document.getElementById('quiz_items').value, 10) || preview.length,
        num_choices: parseInt(document.getElementById('quiz_choices').value, 10) || 4,
        time_limit_minutes: parseInt(document.getElementById('quiz_time').value, 10) || 0,
        assigned_student_ids: quizAssignedIds(),
        questions: preview
    };
}
function post(action, data, onOk){
    // Send as application/x-www-form-urlencoded, NOT multipart FormData:
    // the reverse proxy in front of the deployed site (openresty) answers
    // multipart/form-data POSTs with 403 Forbidden, and that HTML error page
    // is what JSON.parse choked on ("Server error — please retry.").
    // PHP fills $_POST identically for both encodings, so the server side is
    // unchanged and local installs keep working.
    var parts = ['action=' + encodeURIComponent(action)];
    Object.keys(data || {}).forEach(function(k){
        var v = data[k];
        var s = (typeof v === 'object') ? JSON.stringify(v) : (v == null ? '' : String(v));
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(s));
    });
    fetch('quiz_actions.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: parts.join('&')
    })
        .then(function(r){ return r.text().then(function(t){ return { status: r.status, statusText: r.statusText, text: t }; }); })
        .then(function(x){
            var j = null;
            try {
                j = JSON.parse(x.text);
            } catch (e) {
                var snip = String(x.text || '').replace(/\s+/g, ' ').trim().slice(0, 140);
                throw new Error('Server returned HTTP ' + x.status + (x.statusText ? ' ' + x.statusText : '') + ' instead of JSON.'
                    + (snip ? ' Response: ' + snip : '')
                    + (x.status === 403 ? ' The host blocked this request — reload the page (fresh protection cookie) and try again.' : ''));
            }
            if (!j || j.status !== 'success') throw new Error((j && j.msg) || ('Request failed (HTTP ' + x.status + ').'));
            onOk(j);
        })
        .catch(function(e){ alertBox('danger', e.message || 'Request failed.'); });
}
function statusBadge(q){
    return parseInt(q.is_active, 10) === 1
        ? '<span class="badge badge-success"><i class="fas fa-bell me-1"></i>Active — students notified</span>'
        : '<span class="badge badge-secondary">Inactive</span>';
}
// ---- One unified tab bar: Setup | Questions | Manual | Import | Saved Quizzes ----
// Every section of a class folder sits behind the same bar, so the whole hub
// shares one tab design. Cards stay mounted (typed input is preserved); only
// visibility toggles.
var QUIZ_SECTIONS = ['setup', 'questions', 'manual', 'import', 'saved'];
function quizNormalizeSec(v){
    if (v === 'quizzes') v = 'saved'; // legacy value from the old two-bar layout
    return QUIZ_SECTIONS.indexOf(v) >= 0 ? v : 'setup';
}
var quizActiveSec = quizNormalizeSec(
    localStorage.getItem('quizActiveSec') || localStorage.getItem('quizActivePane') || 'setup'
);
function quizShowSec(name){
    quizActiveSec = quizNormalizeSec(name);
    document.querySelectorAll('#quizMainTabs .quiz-tab-btn[data-sec]').forEach(function(b){
        var on = b.getAttribute('data-sec') === quizActiveSec;
        b.closest('.nav-item').classList.toggle('active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    QUIZ_SECTIONS.forEach(function(v){
        var p = document.getElementById('quiz-sec-' + v);
        if (p) p.style.display = (v === quizActiveSec) ? '' : 'none';
    });
    localStorage.setItem('quizActiveSec', quizActiveSec);
    // The saved table can only be measured once its panel is on screen.
    if (quizActiveSec === 'saved'){ requestAnimationFrame(function(){ initQuizTable(); applySavedFilter(); }); }
}
document.querySelectorAll('#quizMainTabs .quiz-tab-btn[data-sec]').forEach(function(b){
    b.addEventListener('click', function(){ quizShowSec(b.getAttribute('data-sec')); });
});
// Initialize the tab state; the bar itself only appears inside a class folder.
quizShowSec(quizActiveSec);
// Per-student assignment: empty selection = whole class (backward compatible).
var quizAssignList = [];
var quizAssignSel = {};
var quizAssignClass = 0;
function updateAssignLabel(){
    var ids = Object.keys(quizAssignSel);
    var lbl = document.getElementById('quizAssignLabel');
    var hint = document.getElementById('quizAssignHint');
    if (!ids.length){
        if (lbl) lbl.textContent = 'Whole class' + (quizAssignList.length ? ' (' + quizAssignList.length + ')' : '');
        if (hint) hint.textContent = 'Default: every student in the class will see this quiz.';
    } else {
        if (lbl) lbl.textContent = ids.length + ' selected';
        if (hint) hint.textContent = 'Only the ' + ids.length + ' selected student(s) will see this quiz.';
    }
}
function renderAssignList(){
    var box = document.getElementById('quizAssignList');
    if (!box) return;
    var q = ((document.getElementById('quizAssignSearch') || {}).value || '').toLowerCase();
    var html = '';
    quizAssignList.forEach(function(s){
        if (q && (String(s.name || '') + ' ' + String(s.student_number || '')).toLowerCase().indexOf(q) < 0) return;
        html += '<label class="dropdown-item d-flex gap-2 align-items-center mb-0" style="cursor:pointer"><input type="checkbox" class="form-check-input q-assign mt-0" value="' + s.student_id + '"' + (quizAssignSel[s.student_id] ? ' checked' : '') + '><span class="flex-grow-1"><span class="fw-bold" style="font-size:12.5px">' + esc(s.name) + '</span><br><small class="text-muted">' + esc(s.student_number) + '</small></span></label>';
    });
    box.innerHTML = html || '<div class="text-muted small px-2 py-1">No students found.</div>';
}
function loadAssignStudents(classId, keepIds){
    quizAssignClass = parseInt(classId, 10) || 0;
    quizAssignList = []; quizAssignSel = {};
    var wrap = document.getElementById('quizAssignWrap');
    var box = document.getElementById('quizAssignList');
    var search = document.getElementById('quizAssignSearch');
    if (search) search.value = '';
    if (!quizAssignClass){ if (wrap) wrap.style.display = 'none'; return; }
    if (wrap) wrap.style.display = '';
    if (box) box.innerHTML = '<div class="text-muted small px-2 py-1">Loading students…</div>';
    updateAssignLabel();
    var keep = [];
    try { keep = (typeof keepIds === 'string') ? JSON.parse(keepIds || '[]') : (keepIds || []); } catch (e) { keep = []; }
    fetch('quiz_actions.php?action=students&class_id=' + encodeURIComponent(quizAssignClass), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j || j.status !== 'success') throw new Error((j && j.msg) || 'Load failed.');
            quizAssignList = j.data || [];
            var valid = {};
            quizAssignList.forEach(function(s){ valid[String(s.student_id)] = 1; });
            (keep || []).forEach(function(id){ var nid = parseInt(id, 10); if (valid[String(nid)]) quizAssignSel[nid] = 1; });
            renderAssignList();
            updateAssignLabel();
        })
        .catch(function(){ quizAssignList = []; quizAssignSel = {}; renderAssignList(); updateAssignLabel(); });
}
function quizAssignedIds(){
    // Intersect with the loaded roster so a stale selection can never leak
    // across classes; empty = whole class.
    var out = [];
    Object.keys(quizAssignSel).forEach(function(k){
        var id = parseInt(k, 10);
        if (id > 0 && quizAssignList.some(function(s){ return String(s.student_id) === String(id); })) out.push(id);
    });
    return out;
}
// ---- DataTables on the saved-quizzes table ----
// The table is re-rendered from scratch on every loadQuizzes(), so the widget
// is destroyed before the tbody is rewritten and re-created right after.
function quizDT(){
    // $.fn.dataTable is the documented namespace (isDataTable, ext.search, …).
    return (window.jQuery && jQuery.fn && jQuery.fn.dataTable) ? jQuery.fn.dataTable : null;
}
var quizDTPreserve = null;
function dtPreserve(){
    if (!quizDTPreserve) quizDTPreserve = { page: 0, order: [] };
    return quizDTPreserve;
}
function quizTableOnScreen(){
    // offsetParent is null when the panel — or any ancestor (the folder level
    // container) — is hidden, so this stays false while browsing folders.
    var p = document.getElementById('quiz-sec-saved');
    return !!p && p.offsetParent !== null;
}
function destroyQuizTable(){
    var DT = quizDT();
    if (!DT) return;
    try {
        if (DT.isDataTable('#quizTable')){
            var api = jQuery('#quizTable').DataTable();
            var info = api.page.info();
            var keep = dtPreserve();
            keep.page = (info && typeof info.page === 'number') ? info.page : 0;
            keep.order = api.order() || [];
            api.destroy();
        }
    } catch(e){}
}
function initQuizTable(){
    console.log('initQuizTable called, onScreen=', quizTableOnScreen());
    var DT = quizDT();
    if (!DT) return false;
    // Column widths are measured on init — never do it while the pane is hidden.
    if (!quizTableOnScreen()) return false;
    try {
        if (DT.isDataTable('#quizTable')) return true;
        var api = jQuery('#quizTable').DataTable({
            pageLength: 8,
            lengthChange: false,
            searching: false,
            info: true,
            ordering: true,
            autoWidth: false,
            order: (dtPreserve().order && dtPreserve().order.length) ? dtPreserve().order : []
        });
        console.log('DataTable rows after init:', api.rows().count());
        var pages = api.page.info().pages || 1;
        if (dtPreserve().page > 0){
            api.page(Math.min(dtPreserve().page, pages - 1)).draw(false);
        }
        return true;
    } catch(e){ return false; }
}
// Status + search filter runs inside DataTables so paging/sorting stay correct.
if (window.jQuery && jQuery.fn && jQuery.fn.dataTable){
    jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
        if (!settings || !settings.nTable || settings.nTable.id !== 'quizTable') return true;
        var row = settings.aoData && settings.aoData[dataIndex];
        var tr = row && row.nTr;
        var t = tr && tr.querySelector('.q-toggle');
        if (!t) return true; // placeholder / error row
        var all = quizSavedRows || [];
        var qid = t.getAttribute('data-id'), q = null;
        for (var i = 0; i < all.length; i++){
            if (String(all[i].quiz_id) === String(qid)){ q = all[i]; break; }
        }
        if (!q) return true;
        var st = quizSavedStatus || 'all';
        var term = quizSavedQ || '';
        if (st === 'active' && parseInt(q.is_active, 10) !== 1) return false;
        if (st === 'inactive' && parseInt(q.is_active, 10) === 1) return false;
        if (term !== ''){
            var hay = String(q.title || '') + ' ' + String(q.class_label || '') + ' #' + String(q.quiz_id || '');
            if (hay.toLowerCase().indexOf(term) < 0) return false;
        }
        return true;
    });
}
function loadQuizzes(){
    // cache:'no-store' + timestamp: never serve a cached count.
    // Scope follows the class folder the user is currently inside.
    var classId = window.quizSelectedClassId || '';
    var semesterId = document.getElementById('quiz_semester').value || '';


var url = 'quiz_actions.php?action=list&_=' + Date.now();
    if (classId) {
        url += '&class_id=' + encodeURIComponent(classId);
    } else if (semesterId) {
        // Only filter by semester when no class is selected.
        url += '&semester_id=' + encodeURIComponent(semesterId);
    }
    // semester filter handled above
fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            var tb = document.getElementById('quizTableBody');
            var lbl = document.getElementById('quizDbLabel');
            var cnt = document.getElementById('quizSavedCount');
            if (cnt) cnt.textContent = (j && j.data) ? j.data.length : 0;
            if (lbl && j && j.db) lbl.innerHTML = 'Reading from <strong>' + esc(j.db) + '</strong> · updated ' + new Date().toLocaleTimeString();
            // No data or error – clear table and initialise for default empty message
            if (!j || j.status !== 'success' || !j.data.length) {
                // No rows for this class – clear any existing DataTable and show the default empty message
                destroyQuizTable();
                quizSavedRows = [];
                tb.innerHTML = '';
                requestAnimationFrame(function(){ initQuizTable(); applySavedFilter(); });
                return;
            }
            quizSavedRows = j.data;
            var html = '';
            quizSavedRows.forEach(function(q){
                var active = parseInt(q.is_active, 10) === 1;
                html += '<tr><td><strong>' + esc(q.title) + '</strong><br><small class="text-muted">ID #' + q.quiz_id + ' · ' + esc(phTime(q.created_at)) + '</small></td>';
                var asgN = parseInt(q.assigned_count || 0, 10);
                html += '<td>' + esc(q.class_label || ('Class #' + q.class_id)) + '<br><small class="text-muted">' + (asgN > 0 ? '<i class="fas fa-users me-1"></i>' + asgN + ' assigned' : 'Whole class') + '</small></td>';
                html += '<td class="text-center">' + esc(q.quiz_type_label || q.quiz_type) + '</td>';
                html += '<td class="text-center">' + q.num_items + '</td>';
                html += '<td class="text-center"><button type="button" class="badge badge-brand q-taken" data-id="' + q.quiz_id + '" data-title="' + esc(q.title) + '" title="View students who submitted" style="cursor:pointer;border:none">' + (q.taken || 0) + '</button></td>';
                html += '<td class="text-center">' + statusBadge(q) + '</td>';
                html += '<td class="text-end"><div class="btn-group btn-group-sm" role="group">';
                html += '<button class="btn btn-sm ' + (active ? 'btn-warning' : 'btn-success') + ' q-toggle" data-id="' + q.quiz_id + '" title="' + (active ? 'Deactivate (hide from students)' : 'Activate (notify students)') + '"><i class="fas ' + (active ? 'fa-bell-slash' : 'fa-bell') + '"></i> ' + (active ? 'Deactivate' : 'Activate') + '</button>';
                html += '<button class="btn btn-sm btn-outline-primary q-edit" data-id="' + q.quiz_id + '"><i class="fas fa-edit"></i></button>';
                html += '<button class="btn btn-sm btn-outline-info q-results" data-id="' + q.quiz_id + '" data-title="' + esc(q.title) + '"><i class="fas fa-poll"></i></button>';
                html += '<button class="btn btn-sm btn-outline-danger q-del" data-id="' + q.quiz_id + '" data-title="' + esc(q.title) + '"><i class="fas fa-trash"></i></button>';
                html += '<button class="btn btn-sm btn-outline-secondary q-clone" data-id="' + q.quiz_id + '" title="Clone to another class"><i class="fas fa-copy"></i></button>';
                html += '</div></td></tr>';
            });
            // Ensure any previous DataTable is removed before inserting fresh rows
            destroyQuizTable();
            tb.innerHTML = html;
            requestAnimationFrame(function(){ initQuizTable(); applySavedFilter(); });

        })
        .catch(function(){ quizSavedRows = []; document.getElementById('quizTableBody').innerHTML = '<tr>'+Array(7).fill('<td class="text-center text-danger py-4">Could not load quizzes.</td>').join('')+'</tr>'; });
}
var quizSavedRows = [];
var quizSavedQ = '';
var quizSavedStatus = 'all';
function applySavedFilter(){
    // Client-side filter over the last loaded rows: search text + status.
    // With DataTables active the predicate lives in ext.search, so a redraw
    // applies it (and keeps paging/sorting intact); otherwise rows are hidden.
    var tb = document.getElementById('quizTableBody');
    if (!tb) return;
    var DT = quizDT();
    if (DT && DT.isDataTable('#quizTable')){
        var api = jQuery('#quizTable').DataTable();
        api.draw(false);
        var cur = api.rows({ search: 'applied', page: 'current' }).count();
        var tot = api.rows({ search: 'applied' }).count();
        var note = document.getElementById('quizSavedFilterNote');
        if (note) note.textContent = ((quizSavedQ || '') !== '' || (quizSavedStatus || 'all') !== 'all') ? ('Showing ' + cur + ' of ' + tot) : '';
        return;
    }
    var visible = 0, total = 0;
    var allRows = quizSavedRows || [];
    tb.querySelectorAll('tr').forEach(function(tr){
        var t = tr.querySelector('.q-toggle');
        if (!t) return; // placeholder row (empty / error state)
        total++;
        var qid = t.getAttribute('data-id'), q = null;
        for (var i = 0; i < allRows.length; i++){
            if (String(allRows[i].quiz_id) === String(qid)){ q = allRows[i]; break; }
        }
        var show = true;
        if (q){
            if ((quizSavedStatus || 'all') === 'active' && parseInt(q.is_active, 10) !== 1) show = false;
            if ((quizSavedStatus || 'all') === 'inactive' && parseInt(q.is_active, 10) === 1) show = false;
            if (show && (quizSavedQ || '') !== ''){
                var hay = String(q.title || '') + ' ' + String(q.class_label || '') + ' #' + String(q.quiz_id || '');
                if (hay.toLowerCase().indexOf(quizSavedQ) < 0) show = false;
            }
        }
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var note = document.getElementById('quizSavedFilterNote');
    if (note) note.textContent = ((quizSavedQ || '') !== '' || (quizSavedStatus || 'all') !== 'all') ? ('Showing ' + visible + ' of ' + total) : '';
}
document.getElementById('quizGenerateBtn').addEventListener('click', doGenerate);
document.getElementById('quizRegenBtn').addEventListener('click', doGenerate);
document.getElementById('quizClearBtn').addEventListener('click', function(){ document.getElementById('quiz_source').value = ''; updateCounts(); });
document.getElementById('quizRefreshBtn').addEventListener('click', loadQuizzes);
(function(){
    var s = document.getElementById('quizSavedSearch');
    if (s) s.addEventListener('input', function(){ quizSavedQ = this.value.toLowerCase(); applySavedFilter(); });
    document.querySelectorAll('#quizSavedFilterBar .q-status').forEach(function(b){
        b.addEventListener('click', function(){
            quizSavedStatus = b.getAttribute('data-s');
            document.querySelectorAll('#quizSavedFilterBar .q-status').forEach(function(x){
                var on = x === b;
                x.classList.toggle('btn-primary', on);
                x.classList.toggle('btn-secondary', !on);
            });
            applySavedFilter();

        });
    });
})();
// Class context: there is no class dropdown anywhere — the class comes from the
// class folder you opened. #quiz_class is the hidden field every Save-quiz
// button reads; the Setup card and the Questions / Manual / Import headers all
// show the same read-only chip instead of mirror <select>s.
function setQuizClassValue(val){
    var v = String(val == null ? '' : val);
    var src = document.getElementById('quiz_class');
    if (src && src.value !== v) src.value = v;
}
function setClassChip(label){
    var txt = label || '';
    document.querySelectorAll('[data-class-chip]').forEach(function(el){
        var t = el.querySelector('.quiz-class-chip-text');
        if (t) t.textContent = txt || 'No class folder open';
        el.classList.toggle('is-empty', !txt);
    });
}
function applyQuizClass(val, keepIds){
    setQuizClassValue(val);
    loadAssignStudents(val, keepIds || []);
}

// Load class tree for year/class filtering
function loadClassTree() {
    fetch('quiz_actions.php?action=class_tree', { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j || j.status !== 'success') return;
            var tree = j.data || [];
            // Store tree globally for later use
            window.quizClassTree = tree;
            renderYearGrid(tree);
        })
        .catch(function(){});
}

// ---- Folder levels: All Years -> Year -> Semester -> Class (mirrors students.php) ----
window.quizSelectedYear = '';
window.quizSelectedSemester = '';
window.quizSelectedClassId = '';
window.quizSelectedClassLabel = '';
function quizHideChrome(){
    // Outside a class folder there is neither a tab bar nor a content panel.
    var mt = document.getElementById('quizMainTabs'); if (mt) mt.style.display = 'none';
    var pg = document.getElementById('quiz-panels'); if (pg) pg.style.display = 'none';
}
function quizShowGrids(which){
    // 'year' | 'semester' | 'class' | 'none'
    var yg = document.getElementById('quizYearGrid');
    var sg = document.getElementById('quizSemGrid');
    var cg = document.getElementById('quizClassGrid');
    if (yg) yg.style.display = (which === 'year') ? 'grid' : 'none';
    if (sg) sg.style.display = (which === 'semester') ? 'grid' : 'none';
    if (cg) cg.style.display = (which === 'class') ? 'grid' : 'none';
    var fu = document.getElementById('quizFolderUI'); if (fu) fu.style.display = '';
}
function quizSetChip(label){
    var chip = document.getElementById('quizCtxChip');
    var lbl = document.getElementById('quizCtxLabel');
    if (lbl) lbl.textContent = label || '';
    if (chip) chip.style.display = label ? '' : 'none';
}
function quizResetSelection(){
    window.quizSelectedYear = '';
    window.quizSelectedSemester = '';
    window.quizSelectedClassId = '';
    window.quizSelectedClassLabel = '';
    setClassChip('');
    var semInput = document.getElementById('quiz_semester'); if (semInput) semInput.value = '';
    quizSetChip('');
}
function quizShowRoot(){
    quizHideChrome();
    quizShowGrids('year');
    quizResetSelection();
    renderBreadcrumb([]);
}
function quizShowYear(yobj){
    // Inside a year: its semester folders.
    quizHideChrome();
    quizShowGrids('semester');
    quizResetSelection();
    renderBreadcrumb([yobj.year]);
}
function quizShowSemester(yobj, semester){
    // Inside a semester: its class folders.
    quizHideChrome();
    quizShowGrids('class');
    quizResetSelection();
    renderBreadcrumb([yobj.year, semester]);
}
function quizEnterClass(yobj, semObj, cls){
    // Inside a class folder: breadcrumb + one unified tab bar + its panels.
    var title = cls.grade + ' - ' + cls.section;
    window.quizSelectedYear = yobj.year;
    window.quizSelectedSemester = semObj.semester;
    var semInput = document.getElementById('quiz_semester'); if (semInput) semInput.value = window.quizSelectedSemester;
    window.quizSelectedClassId = cls.class_id ? String(cls.class_id) : '';
    window.quizSelectedClassLabel = [yobj.year, semObj.semester, title].join(' · ');
    quizShowGrids('none');
    var mt = document.getElementById('quizMainTabs'); if (mt) mt.style.display = '';
    var pg = document.getElementById('quiz-panels'); if (pg) pg.style.display = '';
    setClassChip(window.quizSelectedClassLabel);
    quizSetChip(window.quizSelectedClassLabel);
    renderBreadcrumb([yobj.year, semObj.semester, title]);
    applyQuizClass(cls.class_id);
    quizShowSec(quizActiveSec);
    loadQuizzes();
}

function renderYearGrid(tree){
    var grid = document.getElementById('quizYearGrid');
    if(!grid) return;
    if(!tree || !tree.length){
        grid.innerHTML = '<div class="text-muted">No classes found.</div>';
    } else {
        grid.innerHTML = tree.map(function(y){
            var sems = y.semesters || [];
            var nClass = 0;
            sems.forEach(function(s){ nClass += (s.classes ? s.classes.length : 0); });
            return '<button type="button" class="stu-folder-card" data-year="' + esc(y.year) + '" title="Open ' + esc(y.year) + '">' +
                '<span class="stu-folder-icon"><i class="fas fa-folder"></i></span>' +
                '<span class="stu-folder-meta"><span class="stu-folder-title">' + esc(y.year) + '</span><br>' +
                '<span class="stu-folder-sub">' + sems.length + ' semester' + (sems.length === 1 ? '' : 's') +
                ' · ' + nClass + ' class' + (nClass === 1 ? '' : 'es') + '</span></span>' +
                '</button>';
        }).join('');
    }
    quizShowRoot();
}

function renderSemGrid(yobj){
    var grid = document.getElementById('quizSemGrid');
    if(!grid) return;
    var sems = yobj.semesters || [];
    if (!sems.length){
        grid.innerHTML = '<div class="text-muted">No semester in ' + esc(yobj.year) + ' yet.</div>';
        quizShowYear(yobj);
        return;
    }
    grid.innerHTML = sems.map(function(s){
        var n = (s.classes ? s.classes.length : 0);
        return '<button type="button" class="stu-folder-card" data-year="' + esc(yobj.year) + '" data-sem="' + esc(s.semester) + '" title="Open ' + esc(s.semester) + '">' +
            '<span class="stu-folder-icon semester"><i class="fas fa-calendar-alt"></i></span>' +
            '<span class="stu-folder-meta"><span class="stu-folder-title">' + esc(s.semester) + '</span><br>' +
            '<span class="stu-folder-sub">' + n + ' class' + (n === 1 ? '' : 'es') + '</span></span>' +
            '</button>';
    }).join('');
    quizShowYear(yobj);
}

function renderClassGrid(yobj, semObj){
    var grid = document.getElementById('quizClassGrid');
    if(!grid) return;
    var classes = semObj.classes || [];
    if (!classes.length){
        grid.innerHTML = '<div class="text-muted">No class in ' + esc(semObj.semester) + ' yet.</div>';
        quizShowSemester(yobj, semObj.semester);
        return;
    }
    grid.innerHTML = classes.map(function(c){
        var title = c.grade + ' - ' + c.section;
        var students = parseInt(c.students, 10) || 0;
        var sub = (c.subject ? c.subject : 'Class ID ' + c.class_id)
            + (students ? ' · ' + students + ' student' + (students === 1 ? '' : 's') : '');
        return '<button type="button" class="stu-folder-card" data-class-id="' + esc(c.class_id) + '" data-year="' + esc(yobj.year) + '" data-sem="' + esc(semObj.semester) + '" title="Open ' + esc(title) + '">' +
            '<span class="stu-folder-icon section"><i class="fas fa-folder-open"></i></span>' +
            '<span class="stu-folder-meta"><span class="stu-folder-title">' + esc(title) + '</span><br>' +
            '<span class="stu-folder-sub">' + esc(sub) + '</span></span>' +
            '</button>';
    }).join('');
    quizShowSemester(yobj, semObj.semester);
}

function renderBreadcrumb(parts){
    var bc = document.getElementById('quizClassBreadcrumb');
    if(!bc) return;
    var html = '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="root"><i class="fas fa-home me-1"></i>All Years</a></li>';
    if (parts.length === 1){
        html += '<li class="breadcrumb-item active" aria-current="page">' + esc(parts[0]) + '</li>';
    } else if (parts.length >= 2){
        html += '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="year" data-year="' + esc(parts[0]) + '">' + esc(parts[0]) + '</a></li>';
        if (parts.length === 2){
            html += '<li class="breadcrumb-item active" aria-current="page">' + esc(parts[1]) + '</li>';
        } else {
            html += '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="sem" data-year="' + esc(parts[0]) + '" data-sem="' + esc(parts[1]) + '">' + esc(parts[1]) + '</a></li>';
            html += '<li class="breadcrumb-item active" aria-current="page">' + esc(parts[2]) + '</li>';
        }
    }
    bc.innerHTML = html;
}

function quizTreeYear(y){
    return (window.quizClassTree || []).find(function(x){ return x.year === y; });
}
function quizTreeSem(yobj, sem){
    return ((yobj && yobj.semesters) || []).find(function(s){ return s.semester === sem; });
}

// Click handling for folder cards and breadcrumbs
document.addEventListener('click', function(e){
    if (!e.target || !e.target.closest) return;
    var classBtn = e.target.closest('.stu-folder-card[data-class-id]');
    if(classBtn){
        var yA = quizTreeYear(classBtn.getAttribute('data-year'));
        var sA = yA ? quizTreeSem(yA, classBtn.getAttribute('data-sem')) : null;
        var cid = classBtn.getAttribute('data-class-id');
        var cls = sA ? (sA.classes || []).find(function(c){ return String(c.class_id) === String(cid); }) : null;
        if (yA && sA && cls) quizEnterClass(yA, sA, cls);
        return;
    }
    var semBtn = e.target.closest('.stu-folder-card[data-sem]');
    if(semBtn){
        var yB = quizTreeYear(semBtn.getAttribute('data-year'));
        var sB = yB ? quizTreeSem(yB, semBtn.getAttribute('data-sem')) : null;
        if (yB && sB) renderClassGrid(yB, sB);
        return;
    }
    var yearBtn = e.target.closest('.stu-folder-card[data-year]');
    if(yearBtn){
        var yC = quizTreeYear(yearBtn.getAttribute('data-year'));
        if (yC) renderSemGrid(yC);
        return;
    }
    var nav = e.target.closest('[data-nav]');
    if(nav){
        var type = nav.getAttribute('data-nav');
        if(type==='root'){
            quizShowRoot();
        } else if(type==='year'){
            var yD = quizTreeYear(nav.getAttribute('data-year'));
            if (yD) renderSemGrid(yD);
        } else if(type==='sem'){
            var yE = quizTreeYear(nav.getAttribute('data-year'));
            var sE = yE ? quizTreeSem(yE, nav.getAttribute('data-sem')) : null;
            if (yE && sE) renderClassGrid(yE, sE);
        }
    }
});

// Start at the root folder: no class folder open yet, so the tab bar and the
// panels stay hidden until a class folder is opened.
quizShowRoot();
loadClassTree();
// Quiz title mirrors: same idea as the class dropdown — every Save quiz
// header carries the title field, all of them write the shared value.
var quizTitleMirrors = Array.prototype.slice.call(document.querySelectorAll('.quiz-title-mirror'));
function setQuizTitleValue(val){
    var v = String(val == null ? '' : val);
    var src = document.getElementById('quiz_title');
    if (src && src.value !== v) src.value = v;
    quizTitleMirrors.forEach(function(inp){ if (inp.value !== v) inp.value = v; });
}
(function(){
    var master = document.getElementById('quiz_title');
    if (master) master.addEventListener('input', function(){ setQuizTitleValue(this.value); });
    quizTitleMirrors.forEach(function(inp){ inp.addEventListener('input', function(){ setQuizTitleValue(this.value); }); });
})();
// Time limit mirrors (master = Setup fields).
var quizTimeMirrors = Array.prototype.slice.call(document.querySelectorAll('.quiz-time-mirror'));
var quizQTimeMirrors = Array.prototype.slice.call(document.querySelectorAll('.quiz-qtime-mirror'));
function setQuizTimeValue(val){
    var v = String(val == null ? '' : val);
    var src = document.getElementById('quiz_time');
    if (src && src.value !== v) src.value = v;
    quizTimeMirrors.forEach(function(inp){ if (inp.value !== v) inp.value = v; });
}
function setQuizQTimeValue(val){
    var v = String(val == null ? '' : val);
    var src = document.getElementById('quiz_qtime');
    if (src && src.value !== v) src.value = v;
    quizQTimeMirrors.forEach(function(inp){ if (inp.value !== v) inp.value = v; });
}
(function(){
    var masterTime = document.getElementById('quiz_time');
    if (masterTime) masterTime.addEventListener('input', function(){ setQuizTimeValue(this.value); });
    quizTimeMirrors.forEach(function(inp){ inp.addEventListener('input', function(){ setQuizTimeValue(this.value); }); });
    var masterQTime = document.getElementById('quiz_qtime');
    if (masterQTime) masterQTime.addEventListener('input', function(){ setQuizQTimeValue(this.value); });
    quizQTimeMirrors.forEach(function(inp){ inp.addEventListener('input', function(){ setQuizQTimeValue(this.value); }); });
})();
document.getElementById('quizAssignSearch').addEventListener('input', renderAssignList);
document.getElementById('quizAssignAll').addEventListener('click', function(){ quizAssignList.forEach(function(s){ quizAssignSel[s.student_id] = 1; }); renderAssignList(); updateAssignLabel(); });
document.getElementById('quizAssignNone').addEventListener('click', function(){ quizAssignSel = {}; renderAssignList(); updateAssignLabel(); });
document.getElementById('quizAssignList').addEventListener('change', function(e){
    var c = e.target.closest('.q-assign');
    if (!c) return;
    var id = parseInt(c.value, 10);
    if (c.checked) quizAssignSel[id] = 1; else delete quizAssignSel[id];
    updateAssignLabel();
});
document.getElementById('quizAddItemBtn').addEventListener('click', function(){ syncPreviewFromDOM(); preview.push({ qtype: 'multiple_choice', question: '', choices: ['Option A', 'Option B', 'Option C', 'Option D'], correct_answer: 'Option A', time_limit_seconds: (parseInt((document.getElementById('quiz_qtime') || {}).value, 10) || 0) }); renderPreview(); });
function manualComposerType(){
    var typeEl = document.getElementById('quizManualType');
    if (!typeEl) return; // Guard against missing element
    var t = typeEl.value;
    var choicesWrap = document.getElementById('quizManualChoicesWrap');
    if (choicesWrap) choicesWrap.style.display = (t === 'multiple_choice') ? '' : 'none';
    var showAns = (t === 'identification' || t === 'essay');
    var answerWrap = document.getElementById('quizManualAnswerWrap');
    if (answerWrap) answerWrap.style.display = showAns ? '' : 'none';
    var tfWrap = document.getElementById('quizManualTFWrap');
    if (tfWrap) tfWrap.style.display = (t === 'true_false') ? '' : 'none';
    var lbl = document.getElementById('quizManualAnswerLabel');
    var inp = document.getElementById('quizManualAnswer');
    var note = document.getElementById('quizManualAnswerNote');
    if (t === 'essay'){
        if (lbl) lbl.innerHTML = 'Model answer / grading guide <span class="text-danger">*</span>';
        if (inp) inp.placeholder = 'Key points the answer must contain…';
        if (note) note.innerHTML = '<i class="fas fa-lock me-1"></i>Hidden from students — they only see an essay box. Essays need manual grading.';
    } else {
        if (lbl) lbl.innerHTML = 'Correct answer <span class="text-danger">*</span>';
        if (inp) inp.placeholder = 'Correct answer';
        if (note) note.innerHTML = '<i class="fas fa-lock me-1"></i>Hidden from students — they only see a text box. The answer never leaves the server.';
    }
}
function manualAddChoiceRow(val, checked){
    var wrap = document.getElementById('quizManualChoices');
    if (wrap.querySelectorAll('.q-choice').length >= 6) return;
    var div = document.createElement('div');
    div.className = 'input-group input-group-sm';
    div.innerHTML = '<span class="input-group-text"><input type="radio" name="manualcorr"' + (checked ? ' checked' : '') + ' title="Mark as correct"></span>';
    var inp = document.createElement('input');
    inp.type = 'text'; inp.className = 'form-control q-choice'; inp.placeholder = 'Choice text'; inp.value = val || '';
    var del = document.createElement('button');
    del.type = 'button'; del.className = 'btn btn-outline-danger q-choice-del'; del.title = 'Remove choice';
    del.innerHTML = '<i class="fas fa-times"></i>';
    del.addEventListener('click', function(){
        if (wrap.querySelectorAll('.q-choice').length <= 2){ alertBox('danger', 'Keep at least 2 choices.'); return; }
        div.remove();
    });
    div.appendChild(inp); div.appendChild(del);
    wrap.appendChild(div);
}
function manualResetChoices(){ document.getElementById('quizManualChoices').innerHTML = ''; manualAddChoiceRow('', true); manualAddChoiceRow('', false); }
function manualClear(){ document.getElementById('quizManualQ').value = ''; document.getElementById('quizManualAnswer').value = ''; document.getElementById('quizManualTF').selectedIndex = 0; if (document.getElementById('quizManualTime')) document.getElementById('quizManualTime').value = 0; manualResetChoices(); }
var manualTypeEl = document.getElementById('quizManualType');
if (manualTypeEl) manualTypeEl.addEventListener('change', manualComposerType);
document.getElementById('quizManualAddChoice').addEventListener('click', function(){ manualAddChoiceRow('', false); });
document.getElementById('quizManualClearBtn').addEventListener('click', manualClear);
function buildManualItem(){
    // Pure builder for the manual composer: returns {item} or {error, focus?}.
    // Shared by the Add button and the Save auto-ingest (see doSaveQuiz).
    var t = document.getElementById('quizManualType').value;
    var q = document.getElementById('quizManualQ').value.trim();
    var tl = parseInt((document.getElementById('quiz_qtime') || {}).value, 10) || 0;
    if (!q) return { error: 'Write the question first.', focus: 'quizManualQ' };
    if (t === 'multiple_choice'){
        var vals = [];
        document.querySelectorAll('#quizManualChoices .q-choice').forEach(function(inp){ vals.push(inp.value.trim()); });
        var checked = document.querySelector('#quizManualChoices input[type="radio"]:checked');
        var nonEmpty = vals.filter(function(v){ return v !== ''; });
        if (nonEmpty.length < 2) return { error: 'Enter at least 2 non-empty choices.' };
        if (!checked) return { error: 'Tick the correct choice.' };
        var ci = Array.prototype.indexOf.call(document.querySelectorAll('#quizManualChoices input[type="radio"]'), checked);
        var ans = vals[ci] || '';
        if (!ans) return { error: 'The ticked choice needs text.' };
        return { item: { qtype: 'multiple_choice', question: q, choices: nonEmpty.slice(), correct_answer: ans, time_limit_seconds: tl } };
    } else if (t === 'identification'){
        var a = document.getElementById('quizManualAnswer').value.trim();
        if (!a) return { error: 'Enter the correct answer (students will not see it).', focus: 'quizManualAnswer' };
        return { item: { qtype: 'identification', question: q, choices: null, correct_answer: a, time_limit_seconds: tl } };
    } else if (t === 'essay'){
        var g = document.getElementById('quizManualAnswer').value.trim();
        if (!g) return { error: 'Enter a model answer or grading guide (students will not see it).', focus: 'quizManualAnswer' };
        return { item: { qtype: 'essay', question: q, choices: null, correct_answer: g, time_limit_seconds: tl } };
    }
    return { item: { qtype: 'true_false', question: q, choices: ['True', 'False'], correct_answer: document.getElementById('quizManualTF').value, time_limit_seconds: tl } };
}
function hasManualContent(){
    // Anything typed in the composer counts — a half-filled composer must
    // error loudly on Save, never be silently dropped.
    if (document.getElementById('quizManualQ').value.trim() !== '') return true;
    if (document.getElementById('quizManualAnswer').value.trim() !== '') return true;
    var touched = false;
    document.querySelectorAll('#quizManualChoices .q-choice').forEach(function(inp){ if (inp.value.trim() !== '') touched = true; });
    return touched;
}
document.getElementById('quizManualAddBtn').addEventListener('click', function(){
    syncPreviewFromDOM();
    var r = buildManualItem();
    if (r.error){ alertBox('danger', r.error); if (r.focus) document.getElementById(r.focus).focus(); return; }
    preview.push(r.item);
    renderPreview();
    quizShowSec('questions');
    manualClear();
    alertBox('success', 'Question added — review the list above, then Save.');
    document.getElementById('quizPreviewCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
manualResetChoices(); manualComposerType();
function parseQuizText(text){
    // Parse pasted ready-made quizzes into question objects:
    //   1. Question? / A. choice ... / optional trailing "Answer Key:" block.
    // Returns { items, stats } — correct defaults to the first choice when
    // no answer is given (caller must tell the teacher to verify).
    var lines = String(text || '').split(/\r?\n/);
    var items = [], cur = null, expectedNum = 1, inKey = false;
    var keyMap = {}, stats = { mc: 0, id: 0, tf: 0, defaulted: 0 };
    function pushCur(){
        if (cur){
            cur.question = String(cur.question || '').replace(/\s+/g, ' ').trim();
            if (cur.question || (cur.choices && cur.choices.length)) items.push(cur);
        }
        cur = null;
    }
    function parseKeyParts(text){
        String(text).split(',').forEach(function(part){
            var km = String(part).match(/^\s*(\d+)\s*[.)\-:]\s*(.+?)\s*$/);
            if (km) keyMap[parseInt(km[1], 10)] = km[2];
        });
        // Whitespace-separated ANSWER KEY tables ("1  A   21  B   41  C",
        // tabs or spaces): pair each bare number with the token right after
        // it. Comma entries above win (guard) so "1. B, 2. C" is never
        // clobbered by a stray "B,". Single-token values only — multi-word
        // answers still need the comma form ("2. Very Good").
        var toks = String(text).split(/\s+/).filter(function(t){ return t !== ''; });
        for (var i = 0; i + 1 < toks.length; i++){
            // Tolerate "1, A, 2, B" (commas as separators, not key syntax).
            var nt = toks[i].replace(/[,;]+$/, '');
            var nm = nt.match(/^(\d+)[.)\-:]*$/);
            if (!nm) continue;
            var num = parseInt(nm[1], 10);
            if (keyMap[num] !== undefined) continue;
            keyMap[num] = toks[i + 1].replace(/[,;]+$/, '');
            i++;
        }
    }
    function stripInlineAnswer(text){
        var m = String(text).match(/^(.*?)\s*\(?(?:answer|ans)\s*:\s*([^()]+?)\)?\s*$/i);
        if (!m) return { text: text, explicit: null };
        return { text: (m[1] || '').trim(), explicit: (m[2] || '').trim() };
    }
    lines.forEach(function(raw){
        var line = String(raw).replace(/\s+$/g, '');
        if (/^\s*$/.test(line)) return;
        // Key header, optionally with entries on the same line
        // ("Answer Key: 1-B, 2-D"). A content line merely starting with
        // "Key ..." is NOT a header unless key entries follow it.
        var khm = line.match(/^\s*(answers?\s*(key|guide)?|answer\s*key|key)\s*:?\s*(.*)$/i);
        if (khm){
            if (/^\s*$/.test(khm[3])){ pushCur(); inKey = true; return; }
            var probe = false;
            String(khm[3]).split(',').forEach(function(part){
                if (/^\s*\d+\s*[.)\-:]\s*.+?\s*$/.test(part)) probe = true;
            });
            if (!probe){
                // Single-line whitespace table ("Answer Key: 1 A 2 B").
                // Strict: the FIRST token must be a bare number, so a content
                // line like "Key concepts include 5 stages…" never flips the
                // parser into key mode and eats the rest of the quiz.
                var kt = String(khm[3]).split(/\s+/).filter(function(t){ return t !== ''; });
                if (kt.length > 1 && /^\d+[.)\-:]*$/.test(kt[0].replace(/[,;]+$/, ''))) probe = true;
            }
            if (probe){ pushCur(); inKey = true; parseKeyParts(khm[3]); return; }
            // else: ordinary content line — fall through below
        }
        if (inKey){
            parseKeyParts(line);
            return;
        }
        var qm = line.match(/^\s*(\d+)\s*[.)]\s*(.*)$/);
        if (qm){
            var qnum = parseInt(qm[1], 10);
            // A numbered line starts a new item only if it continues the
            // sequence — or restarts at 1 after a finished item. Otherwise
            // it is content: a sub-list ("1. fast") or wrapped text stays
            // inside the current question. An item is finished when it has
            // choices or its text ends like a sentence.
            var curComplete = !cur || cur.choices.length > 0 || /[?!.]["']?\s*$/.test(cur.question || '');
            var firstItem = !cur && !items.length;
            if ((qnum === expectedNum || qnum === 1 || firstItem) && curComplete){
                pushCur();
                var stripped = stripInlineAnswer(qm[2] || '');
                cur = { num: qnum, question: stripped.text, choices: [], explicit: stripped.explicit };
                expectedNum = qnum + 1;
                return;
            }
        }
        var cm = line.match(/^\s*([A-Za-z])\s*[.)\-:]\s*(.*)$/);
        if (cm && cur){
            // Choices must run A,B,C… in order — anything else (e.g. an
            // "e.g. …" continuation line) belongs to the question text.
            var want = String.fromCharCode(65 + cur.choices.length);
            if (cm[1].toUpperCase() === want){ cur.choices.push(cm[2] || ''); return; }
        }
        if (cur){ cur.question = (cur.question ? cur.question + ' ' : '') + line.trim(); }
    });
    pushCur();
    items.forEach(function(it, idx){
        var keyVal = (keyMap[idx + 1] !== undefined) ? String(keyMap[idx + 1]).trim() : '';
        var resolved = null, defaulted = true;
        function letterToChoice(letter){
            var li = letter.toUpperCase().charCodeAt(0) - 65;
            if (li >= 0 && li < it.choices.length) return it.choices[li];
            return null;
        }
        var cand = (it.explicit !== null && it.explicit !== '') ? it.explicit : (keyVal !== '' ? keyVal : null);
        if (cand !== null){
            if (/^[A-Za-z]$/.test(cand) && it.choices.length){
                var lc = letterToChoice(cand);
                if (lc !== null && String(lc).trim() !== ''){ resolved = lc; defaulted = false; }
            } else if (it.choices.length){
                for (var c = 0; c < it.choices.length; c++){
                    if (String(it.choices[c]).trim().toLowerCase() === cand.toLowerCase()){ resolved = it.choices[c]; defaulted = false; break; }
                }
            } else {
                resolved = cand; defaulted = false;
            }
        }
        var nonEmpty = it.choices.filter(function(c){ return String(c).trim() !== ''; });
        if (nonEmpty.length >= 2){
            var lows = nonEmpty.map(function(c){ return String(c).trim().toLowerCase(); });
            var isTF = lows.length === 2 && lows.indexOf('true') >= 0 && lows.indexOf('false') >= 0;
            if (isTF){
                it.qtype = 'true_false'; it.choices = ['True', 'False'];
                it.correct_answer = (resolved !== null && String(resolved).trim().toLowerCase() === 'false') ? 'False' : 'True';
                if (cand === null) defaulted = true;
                stats.tf++;
            } else {
                it.qtype = 'multiple_choice'; it.choices = it.choices.slice();
                if (resolved !== null && String(resolved).trim() !== '') it.correct_answer = resolved;
                else {
                    it.correct_answer = '';
                    for (var d = 0; d < it.choices.length; d++){ if (String(it.choices[d]).trim() !== ''){ it.correct_answer = it.choices[d]; break; } }
                }
                stats.mc++;
            }
        } else {
            it.qtype = 'identification'; it.choices = null;
            it.correct_answer = (resolved !== null) ? resolved : '';
            if (cand === null) defaulted = true;
            stats.id++;
        }
        if (defaulted) stats.defaulted++;
        delete it.num; delete it.explicit;
    });
    if (!items.length) return { items: [], stats: stats, error: 'No questions found — number them 1. 2. … with lettered A. B. … choices (see Format guide).' };
    return { items: items, stats: stats, error: null };
}
function validatePreviewItems(list){
    // Pre-save guard so invalid items error loudly instead of being
    // silently dropped by the server.
    if (!list || !list.length) return { ok: false, msg: 'Generate, write, or import questions first.' };
    for (var i = 0; i < list.length; i++){
        var q = list[i] || {}, n = i + 1;
        if (!String(q.question || '').trim()) return { ok: false, msg: 'Item #' + n + ' has no question text.' };
        var ans = String(q.correct_answer || '').trim();
        if (q.qtype === 'multiple_choice'){
            var ch = (q.choices || []).map(function(c){ return String(c == null ? '' : c).trim(); }).filter(function(c){ return c !== ''; });
            if (ch.length < 2) return { ok: false, msg: 'Item #' + n + ' needs at least 2 non-empty choices.' };
            var hit = false;
            for (var c = 0; c < ch.length; c++){ if (ch[c].toLowerCase() === ans.toLowerCase()){ hit = true; break; } }
            if (!ans || !hit) return { ok: false, msg: 'Item #' + n + ' needs a marked correct choice.' };
        } else if (!ans){
            return { ok: false, msg: 'Item #' + n + ' needs a correct answer.' };
        }
    }
    return { ok: true, msg: '' };
}
function ingestImportToPreview(clearAfter){
    // Parse the import textarea into the questions list. Returns
    // {added, stats} or {added:0} when empty or {added:0, error} when the
    // text parses to nothing. clearAfter (Save path) empties the box so a
    // second Save can never double-add the same items.
    var txt = document.getElementById('quizImportText').value;
    if (!String(txt).trim()) return { added: 0 };
    var r = parseQuizText(txt);
    if (!r.items.length) return { added: 0, error: r.error || 'No questions found — check the format guide.' };
    var importTl = parseInt((document.getElementById('quiz_qtime') || {}).value, 10) || 0;
    r.items.forEach(function(q){ q.time_limit_seconds = importTl; preview.push(q); });
    if (clearAfter) document.getElementById('quizImportText').value = '';
    renderPreview();
    return { added: r.items.length, stats: r.stats };
}
function importSummaryMsg(res){
    var bits = [];
    if (res.stats.mc) bits.push(res.stats.mc + ' multiple-choice');
    if (res.stats.id) bits.push(res.stats.id + ' identification');
    if (res.stats.tf) bits.push(res.stats.tf + ' true/false');
    var msg = 'Imported ' + res.added + ' question(s)' + (bits.length ? ' (' + bits.join(', ') + ')' : '') + '.';
    if (res.stats.defaulted) msg += ' ' + res.stats.defaulted + ' had no answer — first choice pre-marked, please verify before saving.';
    return msg;
}
document.getElementById('quizParseBtn').addEventListener('click', function(){
    syncPreviewFromDOM();
    var res = ingestImportToPreview(false);
    if (!res.added){ alertBox('danger', res.error || 'No questions found — check the format guide.'); return; }
    alertBox('success', importSummaryMsg(res));
    quizShowSec('questions');
    document.getElementById('quizPreviewCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
document.getElementById('quizClearImportBtn').addEventListener('click', function(){ document.getElementById('quizImportText').value = ''; });
document.getElementById('quiz_source').addEventListener('input', updateCounts);
function updateCounts(){
    var v = document.getElementById('quiz_source').value;
    document.getElementById('quizSrcCount').textContent = v.length;
    try { document.getElementById('quizSentCount').textContent = sentencesOf(v).length; } catch (e) {}
}
document.getElementById('quizPreviewList').addEventListener('click', function(e){
    var del = e.target.closest('.q-del');
    if (del){ syncPreviewFromDOM(); preview.splice(parseInt(del.closest('[data-i]').getAttribute('data-i'), 10), 1); renderPreview(); return; }
    var add = e.target.closest('.q-choice-add');
    if (add){ syncPreviewFromDOM(); var box = add.closest('[data-i]'); var q = preview[parseInt(box.getAttribute('data-i'), 10)]; q.choices = q.choices || []; if (q.choices.length < 6){ q.choices.push('Option ' + (q.choices.length + 1)); renderPreview(); } return; }
    var rdel = e.target.closest('.q-choice-del');
    if (rdel){ syncPreviewFromDOM(); var b2 = rdel.closest('[data-i]'); var q2 = preview[parseInt(b2.getAttribute('data-i'), 10)]; var idx = Array.prototype.indexOf.call(b2.querySelectorAll('.q-choice'), rdel.closest('.input-group').querySelector('.q-choice')); if (q2.choices.length > 2){ q2.choices.splice(idx, 1); if (q2.choices.indexOf(q2.correct_answer) < 0) q2.correct_answer = q2.choices[0]; renderPreview(); } return; }
});
document.getElementById('quizPreviewList').addEventListener('change', function(e){
    if (e.target.classList && e.target.classList.contains('q-type')){ syncPreviewFromDOM(); renderPreview(); }
});
function doSaveQuiz(){
    syncPreviewFromDOM();
    // Honor the card subtitles: pending manual/import input joins the
    // questions list on Save — no extra clicks needed. Ingested composers
    // are cleared, so re-saving can never double-add. Invalid pending
    // input aborts loudly instead of being silently dropped.
    var autoNotes = [];
    if (hasManualContent()){
        var m = buildManualItem();
        if (m.error){ alertBox('danger', m.error + ' Fix the Manual questions card, then Save again.'); if (m.focus) document.getElementById(m.focus).focus(); return; }
        preview.push(m.item);
        manualClear();
        autoNotes.push('1 manual question');
    }
    var imp = ingestImportToPreview(true);
    if (imp.error){ alertBox('danger', imp.error + ' Fix the Import card, then Save again.'); return; }
    if (imp.added) autoNotes.push(imp.added + ' imported question(s)');
    var p = collectPayload();
    if (!p.class_id){
        alertBox('danger', 'Open a class folder first — every quiz is saved into the class folder you are inside.');
        quizShowRoot();
        return;
    }
    if (!p.title){
        alertBox('danger', 'Enter a quiz title.');
        quizShowSec('setup');
        var tEl = document.getElementById('quiz_title');
        if (tEl) tEl.focus();
        return;
    }
    if (!p.questions.length){ alertBox('danger', 'Generate questions first (or Regenerate).'); return; }
    var v = validatePreviewItems(p.questions);
    if (!v.ok){ alertBox('danger', v.msg + ' Fix it in the questions list, then Save again.'); return; }
    post('save', p, function(j){
        alertBox('success', (autoNotes.length ? 'Added ' + autoNotes.join(' + ') + '. ' : '') + (j.msg || 'Quiz saved.'));
        document.getElementById('quiz_edit_id').value = '';
        loadQuizzes();
    });
}
document.querySelectorAll('.quiz-save-btn').forEach(function(btn){
    btn.addEventListener('click', doSaveQuiz);
});
document.getElementById('quizTableBody').addEventListener('click', function(e){
    var t = e.target.closest('.q-toggle');
    if (t){ post('toggle', { quiz_id: t.getAttribute('data-id') }, function(j){ alertBox('success', j.msg); loadQuizzes(); }); return; }
    var d = e.target.closest('.q-del');
    if (d){ var delQid = d.getAttribute('data-id'), delQTitle = d.getAttribute('data-title'); openQuizConfirm({icon:'fa-trash-alt', title:'Delete quiz?', text:'"' + delQTitle + '" and ALL its questions, attempts and scores will be permanently removed.', okLabel:'Delete quiz', onConfirm:function(){ post('delete', { quiz_id: delQid }, function(j){ alertBox('success', j.msg); loadQuizzes(); }); }}); return; }
    var ed = e.target.closest('.q-edit');
    if (ed){
        fetch('quiz_actions.php?action=get&quiz_id=' + encodeURIComponent(ed.getAttribute('data-id')), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j || j.status !== 'success') throw new Error((j && j.msg) || 'Load failed.');
                var q = j.data;
                // The class always follows the open class folder (no dropdown).
                var effClass = String(window.quizSelectedClassId || '') || String(q.class_id || '');
                document.getElementById('quiz_edit_id').value = q.quiz_id;
                setQuizClassValue(effClass);
                setQuizTitleValue(q.title || '');
                document.getElementById('quiz_source').value = q.source_text || '';
                document.getElementById('quiz_type').value = q.quiz_type || 'multiple_choice';
                document.getElementById('quiz_items').value = q.num_items || (q.questions || []).length;
                document.getElementById('quiz_choices').value = q.num_choices || 4;
                setQuizTimeValue(q.time_limit_minutes || 0);
                var qtimeVal = (q.questions && q.questions.length) ? (parseInt(q.questions[0].time_limit_seconds, 10) || 0) : 0;
                setQuizQTimeValue(qtimeVal);
                loadAssignStudents(effClass, q.assigned_student_ids);
                preview = (q.questions || []).map(function(x){ var qs = String((x && x.question) || '').replace(/^Choose the correct answer:[ \t]+/, 'Choose the correct answer:\n\n'); return { qtype: x.qtype, question: qs, choices: x.choices, correct_answer: x.correct_answer, time_limit_seconds: (parseInt(x.time_limit_seconds, 10) || 0) }; });
                updateCounts(); renderPreview();
                quizShowSec('questions');
                alertBox('info', 'Editing quiz #' + q.quiz_id + ' — Generate to rebuild or edit below, then Save.');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(function(err){ alertBox('danger', err.message); });
        return;
    }
    var cl = e.target.closest('.q-clone');
    if (cl){
        var cloneId = cl.getAttribute('data-id');
        // Load original quiz data to prefill modal
        fetch('quiz_actions.php?action=get&quiz_id=' + encodeURIComponent(cloneId), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j || j.status !== 'success') throw new Error((j && j.msg) || 'Load failed.');
                var q = j.data;
                // Save for later use when confirming clone
                pendingClone = { id: cloneId, data: q };
                // Prefill title input (default copy suffix)
                var titleInput = document.getElementById('quizCloneTitle');
                if (titleInput) titleInput.value = q.title + ' (copy)';
                // Open the clone modal (class options will be populated on show event)
                quizShowModal('quizCloneModal');
            })
            .catch(function(err){ alertBox('danger', err.message); });
        return;
    }

    var tk = e.target.closest('.q-taken');
    if (tk){ openQuizResults(tk.getAttribute('data-id'), tk.getAttribute('data-title'), 'submitted'); return; }
    var rs = e.target.closest('.q-results');
    if (rs){ openQuizResults(rs.getAttribute('data-id'), rs.getAttribute('data-title'), 'all'); return; }
});
var quizModalInsts = {};
function quizShowModal(id){
    // One shared instance per modal: re-showing an open modal is a safe
    // no-op, so backdrops can never stack (the old stuck-blur bug).
    try {
        var el = document.getElementById(id);
        if (!el) return;
        quizModalInsts[id] = quizModalInsts[id] || bootstrap.Modal.getOrCreateInstance(el);
        quizModalInsts[id].show();
    } catch (err) {}
}
function quizHideModal(id){
    try {
        var inst = quizModalInsts[id] || bootstrap.Modal.getInstance(document.getElementById(id));
        if (inst) inst.hide();
    } catch (err) {}
}
// Stacked-modal scroll hygiene (confirm over results): keep the lock while
// any modal is still visible.
document.addEventListener('hidden.bs.modal', function(){
    setTimeout(function(){
        try {
            if (document.querySelector('.modal.show') && !document.body.classList.contains('modal-open')) {
                document.body.classList.add('modal-open');
            }
        } catch (e) {}
    }, 60);
});
var quizConfirmCb = null;
function openQuizConfirm(opts){
    opts = opts || {};
    document.getElementById('quizConfirmTitle').textContent = opts.title || 'Are you sure?';
    document.getElementById('quizConfirmText').textContent = opts.text || '';
    var btn = document.getElementById('quizConfirmBtn');
    btn.className = 'btn rounded-pill px-4 ' + (opts.okClass || 'btn-danger');
    btn.innerHTML = '<i class="fas ' + (opts.okIcon || 'fa-trash') + ' me-1"></i>' + (opts.okLabel || 'Delete');
    var ic = document.getElementById('quizConfirmIcon');
    var danger = (opts.tone || 'danger') !== 'success';
    ic.className = 'd-inline-flex align-items-center justify-content-center rounded-circle mb-3 ' + (danger ? 'text-danger' : 'text-success');
    ic.style.background = danger ? 'rgba(220,53,69,.1)' : 'rgba(5,205,153,.12)';
    ic.innerHTML = '<i class="fas ' + (opts.icon || 'fa-exclamation-triangle') + '"></i>';
    quizConfirmCb = opts.onConfirm || null;
    quizShowModal('quizConfirmModal');
}
document.getElementById('quizConfirmBtn').addEventListener('click', function(){
    var cb = quizConfirmCb;
    quizConfirmCb = null;
    quizHideModal('quizConfirmModal');
    if (cb) cb();
});

// Populate the clone target list from the folder tree (year · semester · class)
document.getElementById('quizCloneModal').addEventListener('show.bs.modal', function(){
    var select = document.getElementById('quizCloneClass');
    if (!select) return;
    select.innerHTML = '';
    var tree = window.quizClassTree || [];
    if (!tree.length){
        select.innerHTML = '<option value="">No classes found</option>';
        return;
    }
    tree.forEach(function(y){
        (y.semesters || []).forEach(function(s){
            (s.classes || []).forEach(function(c){
                var opt = document.createElement('option');
                opt.value = String(c.class_id);
                opt.text = y.year + ' · ' + s.semester + ' · ' + c.grade + ' - ' + c.section;
                if (String(c.class_id) === String(window.quizSelectedClassId || '')) opt.selected = true;
                select.appendChild(opt);
            });
        });
    });
});

// Handle the actual cloning when user confirms in the modal
document.getElementById('quizCloneConfirm').addEventListener('click', function(){
    if (!pendingClone) return;
    var targetClass = document.getElementById('quizCloneClass').value;
    if (!targetClass) { alertBox('danger', 'Select a target class.'); return; }
    var newTitle = document.getElementById('quizCloneTitle').value.trim() || pendingClone.data.title;
    var q = pendingClone.data;
    var payload = {
        quiz_id: '',
        class_id: targetClass,
        title: newTitle,
        source_text: q.source_text || '',
        quiz_type: q.quiz_type || 'multiple_choice',
        num_items: q.num_items || (q.questions || []).length,
        num_choices: q.num_choices || 4,
        time_limit_minutes: q.time_limit_minutes || 0,
        assigned_student_ids: [],
        questions: q.questions || []
    };
    post('save', payload, function(res){
        alertBox('success', res.msg || 'Quiz cloned.');
        loadQuizzes();
        quizHideModal('quizCloneModal');
        pendingClone = null;
    });
});
function doPushMarks(){
    var goBtn = document.getElementById('quizPushGo');
    if (!goBtn || !quizPushSel.assessId) return;
    goBtn.disabled = true;
    goBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting…';
    var body = 'action=' + encodeURIComponent('push_marks')
        + '&quiz_id=' + encodeURIComponent(quizLastQuizId)
        + '&assessment_id=' + encodeURIComponent(quizPushSel.assessId);
    fetch('quiz_actions.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body
    })
        .then(function(r){ return r.text().then(function(t){ return { status: r.status, statusText: r.statusText, text: t }; }); })
        .then(function(x){
            var j = null;
            try {
                j = JSON.parse(x.text);
            } catch (e) {
                // Same openresty 403-on-multipart issue as post() above:
                // report the real HTTP status instead of a generic message.
                throw new Error('Server returned HTTP ' + x.status + (x.statusText ? ' ' + x.statusText : '') + ' instead of JSON.');
            }
            if (!j || j.status !== 'success') throw new Error((j && j.msg) || ('Push failed (HTTP ' + x.status + ').'));
            quizPushMsg = { type: 'success', text: j.msg || 'Scores submitted as marks.' };
            renderPushPanel();
        })
        .catch(function(err){
            quizPushMsg = { type: 'danger', text: err.message || 'Push failed.' };
            renderPushPanel();
        });
}
function openQuizResults(qid, title, filter){
    quizLastQuizId = qid;
    quizLastQuizTitle = title;
    // Live roster: while the modal is open, starters appear under
    // In progress by themselves (paused if another popup is open).
    if (window._quizResultsTimer) clearInterval(window._quizResultsTimer);
    window._quizResultsTimer = setInterval(function(){
        try {
            var resOpen = document.getElementById('quizResultsModal').classList.contains('show');
            var vioOpen = document.getElementById('quizViolationsModal').classList.contains('show');
            var conOpen = document.getElementById('quizConfirmModal').classList.contains('show');
            var attOpen = document.getElementById('quizAttemptModal').classList.contains('show');
            if (!resOpen || vioOpen || conOpen || attOpen || document.hidden) return;
            fetch('quiz_actions.php?action=results&quiz_id=' + encodeURIComponent(quizLastQuizId), { credentials: 'same-origin', cache: 'no-store' })
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (!j || j.status !== 'success') return;
                    quizLastResults = j.data || [];
                    quizLastSummary = j.summary || quizLastSummary;
                    renderQuizResults(quizResultsFilter);
                }).catch(function(){});
        } catch (e) {}
    }, 10000);
    quizPushComps = [];
    quizPushSubmitted = 0;
    quizPushLoading = true;
    quizPushSel = { compId: 0, compName: '', assessId: 0, assessName: '', hps: 0 };
    quizPushMsg = { type: '', text: '' };
    document.getElementById('quizResultsTitle').textContent = 'Results — ' + title;
    document.getElementById('quizResultsBody').innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    quizShowModal('quizResultsModal');
    fetch('quiz_actions.php?action=results&quiz_id=' + encodeURIComponent(qid), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j || j.status !== 'success') throw new Error((j && j.msg) || 'Load failed.');
            quizLastResults = j.data || [];
            quizLastSummary = j.summary || { total: quizLastResults.length, submitted: 0, in_progress: 0, not_taken: 0 };
            renderQuizResults(filter || 'all');
            loadPushTargets();
        })
        .catch(function(err){ document.getElementById('quizResultsBody').innerHTML = '<div class="text-danger text-center py-4">' + esc(err.message) + '</div>'; });
}
function quizStateBadge(a, li){
    // Submitted badges open the student's taken form (see .q-state handler).
    // In-progress rows have no stored answers yet, so they stay static.
    if (a.state === 'submitted') return '<button type="button" class="badge badge-success q-state" data-i="' + li + '" title="View the quiz form taken by this student" style="cursor:pointer;border:none"><i class="fas fa-check-circle me-1"></i>Submitted</button>';
    if (a.state === 'in_progress') return '<span class="badge badge-warning"><i class="fas fa-pen me-1"></i>In progress</span>';
    return '<span class="badge badge-secondary">Not taken</span>';
}
function renderQuizResults(filter){
    quizResultsFilter = filter || 'all';
    var s = quizLastSummary;
    var list = quizLastResults.filter(function(a){ return quizResultsFilter === 'all' || a.state === quizResultsFilter; });
    quizLastList = list;
    var h = '<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">';
    h += '<span class="badge badge-brand">' + s.total + ' students</span>';
    h += '<span class="badge badge-success">' + s.submitted + ' submitted</span>';
    h += '<span class="badge badge-warning">' + s.in_progress + ' in progress</span>';
    h += '<span class="badge badge-secondary">' + s.not_taken + ' not taken</span>';
    h += '<span class="ms-auto d-flex gap-1 flex-wrap">';
    [['all', 'All'], ['submitted', 'Submitted'], ['in_progress', 'In progress'], ['not_taken', 'Not taken']].forEach(function(f){
        h += '<button type="button" class="btn btn-sm ' + (quizResultsFilter === f[0] ? 'btn-primary' : 'btn-secondary') + ' q-filter" data-f="' + f[0] + '">' + f[1] + '</button>';
    });
    if (((s.submitted || 0) + (s.in_progress || 0)) > 0) h += '<button type="button" class="btn btn-sm btn-outline-danger q-clear-all" title="Delete ALL attempts for this quiz (class can retake)"><i class="fas fa-trash me-1"></i>Clear all</button>';
    h += '</span></div>';
    h += '<div id="quizPushWrap" class="mb-3"></div>';
    if (!quizLastResults.length){
        document.getElementById('quizResultsBody').innerHTML = h + '<div class="text-center text-muted py-4">No students enrolled in this class yet.</div>';
        renderPushPanel();
        return;
    }
    if (!list.length){
        document.getElementById('quizResultsBody').innerHTML = h + '<div class="text-center text-muted py-4">Nobody in this group.</div>';
        renderPushPanel();
        return;
    }
    h += '<div class="table-responsive"><table class="table horizon-table mb-0"><thead><tr><th>Student</th><th>Number</th><th class="text-center">Score</th><th class="text-center">Violations</th><th class="text-center">Status</th><th>Started / Submitted</th><th class="text-end">Action</th></tr></thead><tbody>';
    list.forEach(function(a, li){
        var done = (a.state === 'submitted');
        var vcount = parseInt(a.violations || 0, 10);
        h += '<tr><td>' + esc(a.student_name || ('ID ' + a.student_id)) + '</td><td>' + esc(a.student_number || '') + '</td>';
        h += '<td class="text-center fw-bold">' + (done ? esc(a.score) + ' / ' + esc(a.total) : '<span class="text-muted">—</span>') + '</td>';
        h += '<td class="text-center">' + (vcount > 0 ? '<button type="button" class="badge badge-warning q-viol" data-i="' + li + '" title="View violation details" style="cursor:pointer;border:none">' + vcount + '</button>' : '0') + '</td>';
        h += '<td class="text-center">' + quizStateBadge(a, li) + (a.attempt_status === 'auto_submitted' ? ' <span class="badge badge-warning" title="Auto-submitted (violations / time)">auto</span>' : '') + '</td>';
        h += '<td><small>' + (done ? 'Submitted ' + esc(phTime(a.submitted_at)) : (a.state === 'in_progress' ? '<span class="badge badge-warning" style="font-size:10px"><i class="fas fa-pen me-1"></i>Started</span> ' + esc(phTime(a.started_at)) : '—')) + '</small></td>';
        h += '<td class="text-end">' + (a.attempt_id ? '<button type="button" class="btn btn-sm btn-outline-danger q-attempt-del" data-aid="' + a.attempt_id + '" data-name="' + esc(a.student_name || a.student_number || ('ID ' + a.student_id)) + '" title="Delete this attempt (student can retake)"><i class="fas fa-trash"></i></button>' : '<span class="text-muted">—</span>') + '</td></tr>';
    });
    document.getElementById('quizResultsBody').innerHTML = h + '</tbody></table></div>';
    renderPushPanel();
}
function quizNorm(s){ return String(s == null ? '' : s).replace(/\s+/g, ' ').trim().toLowerCase(); }
function openQuizAttempt(li){
    // The taken form: every question with the student's stored answer marked
    // against the correct one (same normalization as server-side grading).
    var a = quizLastList[parseInt(li, 10)];
    if (!a || !a.attempt_id) return;
    document.getElementById('quizAttemptTitle').textContent = 'Attempt — ' + (a.student_name || a.student_number || ('ID ' + a.student_id));
    document.getElementById('quizAttemptBody').innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    quizShowModal('quizAttemptModal');
    fetch('quiz_actions.php?action=attempt_detail&quiz_id=' + encodeURIComponent(quizLastQuizId) + '&attempt_id=' + encodeURIComponent(a.attempt_id), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j || j.status !== 'success') throw new Error((j && j.msg) || 'Load failed.');
            renderQuizAttempt(j.attempt, j.questions || []);
        })
        .catch(function(err){ document.getElementById('quizAttemptBody').innerHTML = '<div class="text-danger text-center py-4">' + esc(err.message) + '</div>'; });
}
function renderQuizAttempt(att, questions){
    var ans = (att && att.answers) || {};
    var h = '<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">';
    h += '<span class="badge badge-brand">' + esc(att.student_name || '') + '</span>';
    h += '<span class="badge badge-secondary">' + esc(att.student_number || '') + '</span>';
    h += '<span class="badge badge-success">' + esc(att.score) + ' / ' + esc(att.total) + '</span>';
    if (att.status === 'auto_submitted') h += '<span class="badge badge-warning" title="Auto-submitted (violations / time)">auto</span>';
    h += '<small class="text-muted ms-auto">Submitted ' + esc(phTime(att.submitted_at)) + '</small></div>';
    if (!questions.length){
        document.getElementById('quizAttemptBody').innerHTML = h + '<div class="text-center text-muted py-4">No questions found for this quiz.</div>';
        return;
    }
    var correct = 0;
    var essayPending = 0, autoCount = 0;
    questions.forEach(function(q, i){
        var given = '';
        if (ans[String(q.question_id)] !== undefined) given = String(ans[String(q.question_id)]);
        else if (ans[q.question_id] !== undefined) given = String(ans[q.question_id]);
        given = given.trim();
        var right = String(q.correct_answer || '').trim();
        var isEssay = (q.qtype === 'essay');
        var ok = !isEssay && given !== '' && quizNorm(given) === quizNorm(right);
        if (ok) correct++;
        if (isEssay) essayPending++; else autoCount++;
        h += '<div class="p-3 mb-2" style="border:1px solid var(--horizon-card-border);border-radius:12px;background:var(--horizon-card-bg)">';
        h += '<div class="d-flex align-items-center gap-2 mb-2 flex-wrap"><span class="badge badge-secondary">#' + (i + 1) + '</span>' + typeBadge(q.qtype);
        h += '<span class="ms-auto badge ' + (isEssay ? 'badge-warning' : (ok ? 'badge-success' : (given === '' ? 'badge-secondary' : 'badge-danger'))) + '">' + (isEssay ? 'Needs manual grading' : (ok ? '<i class="fas fa-check me-1"></i>Correct' : (given === '' ? 'No answer' : '<i class="fas fa-times me-1"></i>Wrong'))) + '</span></div>';
        h += '<div class="fw-bold mb-2" style="white-space:pre-line;font-size:.92rem;color:var(--horizon-text-title)">' + esc(q.question) + '</div>';
        if (q.qtype === 'identification' || q.qtype === 'essay' || !q.choices || !q.choices.length){
            h += '<div class="d-flex flex-column gap-1">';
            h += '<div class="p-2 rounded" style="font-size:.85rem;white-space:pre-line;background:' + (isEssay ? 'rgba(67,24,255,.06)' : (ok ? 'rgba(5,205,153,.12)' : 'rgba(220,53,69,.08)')) + '"><span class="text-muted">Student answer:</span> <strong>' + (given === '' ? '<span class="text-muted">—</span>' : esc(given)) + '</strong></div>';
            if (!ok) h += '<div class="p-2 rounded" style="font-size:.85rem;white-space:pre-line;background:rgba(5,205,153,.12)"><span class="text-muted">' + (isEssay ? 'Model answer / guide:' : 'Correct answer:') + '</span> <strong>' + esc(right) + '</strong></div>';
            h += '</div>';
        } else {
            var matched = false;
            h += '<div class="d-flex flex-column gap-1">';
            q.choices.forEach(function(c){
                var isGiven = given !== '' && quizNorm(c) === quizNorm(given);
                var isRight = quizNorm(c) === quizNorm(right);
                if (isGiven) matched = true;
                var bg = 'transparent', extra = 'border:1px solid var(--horizon-card-border)', tag = '';
                if (isGiven && isRight){ bg = 'rgba(5,205,153,.15)'; extra = 'border:1px solid rgba(5,205,153,.5)'; tag = ' <span class="badge badge-success">Your answer ✓</span>'; }
                else if (isRight){ bg = 'rgba(5,205,153,.07)'; extra = 'border:1px dashed rgba(5,205,153,.5)'; tag = ' <span class="badge badge-success">Correct answer</span>'; }
                else if (isGiven){ bg = 'rgba(220,53,69,.08)'; extra = 'border:1px solid rgba(220,53,69,.4)'; tag = ' <span class="badge badge-danger">Your answer ✗</span>'; }
                h += '<div class="p-2 rounded d-flex align-items-center gap-2 flex-wrap" style="font-size:.85rem;background:' + bg + ';' + extra + '"><span>' + esc(c) + '</span>' + tag + '</div>';
            });
            if (given !== '' && !matched) h += '<div class="p-2 rounded" style="font-size:.85rem;background:rgba(220,53,69,.08)"><span class="text-muted">Student answer (no longer among choices):</span> <strong>' + esc(given) + '</strong></div>';
            h += '</div>';
        }
        h += '</div>';
    });
    h += '<small class="text-muted">Choices shown in saved order (students see them shuffled). ' + correct + ' of ' + autoCount + ' auto-graded correct' + (essayPending ? ' · ' + essayPending + ' essay(s) need manual grading.' : '.') + '</small>';
    document.getElementById('quizAttemptBody').innerHTML = h;
}
function quizReasonLabel(code){
    var map = {
        tab_hidden: 'Switched tab / browser hidden',
        window_blur: 'Window lost focus (new tab, another app, or minimize)',
        page_hide: 'Left the quiz page',
        navigate_away: 'Tried to navigate away',
        devtools_f12: 'Pressed F12 (developer tools)',
        devtools_shortcut: 'Used a developer-tools shortcut',
        devtools_open: 'Opened developer tools',
        exfil_copy: 'Tried to copy quiz questions (possible AI-search attempt)',
        exfil_cut: 'Tried to cut quiz text (possible AI-search attempt)',
        exfil_paste: 'Tried to paste outside text (possible AI-provided answer)',
        exfil_drag: 'Tried to drag quiz text out (possible AI-search attempt)'
    };
    return map[code] || ('Security event: ' + code);
}
function showViolations(li){
    var a = quizLastList[parseInt(li, 10)];
    if (!a) return;
    document.getElementById('quizViolationsTitle').textContent = 'Violations — ' + (a.student_name || a.student_number || ('ID ' + a.student_id));
    var raw = String(a.violation_log || '').split('\n').map(function(x){ return x.trim(); }).filter(function(x){ return x !== ''; });
    var h = '<div class="d-flex gap-2 mb-3 align-items-center flex-wrap"><span class="badge badge-warning">' + esc(a.violations || 0) + ' total</span><span class="badge badge-secondary">' + esc(a.student_number || '') + '</span>' + quizStateBadge(a, li) + '</div>';
    if (!raw.length){
        h += '<div class="text-center text-muted py-3">No detailed entries recorded.</div>';
    } else {
        h += '<div class="d-flex flex-column gap-2">';
        raw.forEach(function(line, i){
            var ts = line.slice(0, 19), code = line.slice(19).trim();
            if (!/^\d{4}-\d{2}-\d{2}/.test(ts)){ ts = ''; code = line; }
            h += '<div class="d-flex gap-2 align-items-start p-2" style="border:1px solid var(--horizon-card-border);border-radius:10px"><span class="badge badge-secondary">#' + (i + 1) + '</span><div><div style="font-size:12.5px;font-weight:700;color:var(--horizon-text-title)">' + esc(quizReasonLabel(code)) + '</div><div style="font-size:11px;color:var(--horizon-text-muted);font-weight:600">' + (ts ? esc(phTime(ts)) : esc(code)) + (ts && code !== ts ? ' · <span style="font-family:monospace">' + esc(code) + '</span>' : '') + '</div></div></div>';
        });
        h += '</div>';
    }
    document.getElementById('quizViolationsBody').innerHTML = h;
    try {
        var first = document.getElementById('quizResultsModal');
        quizShowModal('quizViolationsModal');
        // Keep the results modal underneath; restore its scroll lock when done.
        document.getElementById('quizViolationsModal').addEventListener('hidden.bs.modal', function(){
            if (first && first.classList.contains('show')) document.body.classList.add('modal-open');
        }, { once: true });
    } catch (err) {}
}
function loadPushTargets(){
    fetch('quiz_actions.php?action=gradebook_targets&quiz_id=' + encodeURIComponent(quizLastQuizId), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            quizPushLoading = false;
            if (j && j.status === 'success'){ quizPushComps = j.components || []; quizPushSubmitted = j.submitted || 0; }
            else { quizPushComps = []; quizPushMsg = { type: 'danger', text: (j && j.msg) || 'Could not load gradebook targets.' }; }
            renderPushPanel();
        })
        .catch(function(){ quizPushLoading = false; quizPushComps = []; renderPushPanel(); });
}
function renderPushPanel(){
    var wrap = document.getElementById('quizPushWrap');
    if (!wrap) return;
    var h = '<div class="p-3" style="border:1px dashed var(--horizon-brand-300,#c7d2fe);border-radius:12px;background:rgba(67,24,255,.03)">';
    h += '<div class="fw-bold mb-2" style="font-size:12.5px;color:var(--horizon-text-title)"><i class="fas fa-share-square me-1 text-primary"></i>Push scores to gradebook marks</div>';
    if (quizPushLoading){
        h += '<div class="small text-muted"><div class="spinner-border spinner-border-sm"></div> Loading components…</div>';
    } else if (quizPushSubmitted <= 0){
        h += '<div class="small text-muted">No submitted scores to push yet.</div>';
    } else if (!quizPushComps.length){
        h += '<div class="small text-muted">No components with percentage for this class subject — set them in Manage Percentage first.</div>';
    } else {
        var btnLbl = quizPushSel.compId ? esc(quizPushSel.compName) : 'Select component…';
        h += '<div class="d-flex gap-2 flex-wrap align-items-center">';
        h += '<button type="button" class="btn btn-sm btn-secondary" id="quizPushBtn"><i class="fas fa-layer-group me-1"></i>' + btnLbl + ' <i class="fas fa-caret-down ms-1"></i></button>';
        h += '<div id="quizPushMenu" style="display:none;position:fixed;z-index:3000;min-width:240px;max-width:300px;max-height:260px;overflow:auto;background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);border-radius:12px;box-shadow:0 14px 40px rgba(15,23,42,.25);padding:6px;">';
        quizPushComps.forEach(function(c){
            var n = (c.assessments || []).length;
            h += '<button type="button" class="dropdown-item d-flex align-items-center gap-2 q-push-comp" data-c="' + c.component_id + '"><span class="flex-grow-1">' + esc(c.name) + '</span><span class="badge badge-secondary">' + n + '</span><i class="fas fa-chevron-right text-muted" style="font-size:10px"></i></button>';
        });
        h += '</div>';
        h += '<span id="quizPushSelLabel" class="small fw-bold">' + (quizPushSel.assessId ? esc(quizPushSel.compName) + ' › ' + esc(quizPushSel.assessName) + ' <span class="badge badge-secondary">HPS ' + esc(quizPushSel.hps) + '</span>' : '<span class="text-muted">Hover a component to see its assessments, then click one.</span>') + '</span>';
        if (quizPushSel.assessId) h += '<button type="button" class="btn btn-sm btn-success fw-bold" id="quizPushGo"><i class="fas fa-paper-plane me-1"></i>Submit as marks</button>';
        h += '</div>';
        if (quizPushMsg.text) h += '<div class="horizon-flash-banner horizon-flash-' + quizPushMsg.type + ' mt-2 mb-0"><i class="fas ' + (quizPushMsg.type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i><span style="font-weight:700;font-size:12px">' + esc(quizPushMsg.text) + '</span></div>';
    }
    h += '</div>';
    wrap.innerHTML = h;
    bindPushHover();
}
function quizPushCompById(id){
    for (var i = 0; i < quizPushComps.length; i++){ if (String(quizPushComps[i].component_id) === String(id)) return quizPushComps[i]; }
    return null;
}
function hidePushPop(){
    var p = document.getElementById('quizPushPop');
    if (p) p.style.display = 'none';
    if (window._quizPushTimer){ clearTimeout(window._quizPushTimer); window._quizPushTimer = null; }
}
function hidePushMenu(){
    var m = document.getElementById('quizPushMenu');
    if (m) m.style.display = 'none';
}
function bindPushHover(){
    hidePushPop();
    var btn = document.getElementById('quizPushBtn');
    var menu = document.getElementById('quizPushMenu');
    if (btn && menu){
        btn.addEventListener('click', function(){
            if (menu.style.display === 'block'){ hidePushMenu(); hidePushPop(); return; }
            var r = btn.getBoundingClientRect();
            menu.style.display = 'block';
            menu.style.left = Math.min(r.left, Math.max(8, window.innerWidth - 310)) + 'px';
            menu.style.top = (r.bottom + 6) + 'px';
        });
    }
    if (!window._quizPushDocBound){
        window._quizPushDocBound = true;
        document.addEventListener('click', function(e){
            if (e.target.closest && (e.target.closest('#quizPushMenu') || e.target.closest('#quizPushBtn') || e.target.closest('#quizPushPop'))) return;
            hidePushMenu(); hidePushPop();
        });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ hidePushMenu(); hidePushPop(); } });
    }
    if (!menu) return;
    var pop = document.getElementById('quizPushPop');
    if (!pop){
        pop = document.createElement('div');
        pop.id = 'quizPushPop';
        pop.style.cssText = 'display:none;position:fixed;z-index:3010;min-width:250px;max-width:320px;max-height:300px;overflow:auto;background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);border-radius:12px;box-shadow:0 14px 40px rgba(15,23,42,.25);padding:6px;';
        document.body.appendChild(pop);
        pop.addEventListener('mouseenter', function(){ if (window._quizPushTimer){ clearTimeout(window._quizPushTimer); window._quizPushTimer = null; } });
        pop.addEventListener('mouseleave', hidePushPop);
        pop.addEventListener('click', function(e){
            var a = e.target.closest('.q-push-assess');
            if (!a || a.disabled) return;
            var c = quizPushCompById(a.getAttribute('data-c'));
            if (!c) return;
            quizPushSel = { compId: c.component_id, compName: c.name, assessId: a.getAttribute('data-a'), assessName: a.getAttribute('data-n'), hps: a.getAttribute('data-h') };
            quizPushMsg = { type: '', text: '' };
            hidePushMenu();
            hidePushPop();
            renderPushPanel();
        });
    }
    menu.querySelectorAll('.q-push-comp').forEach(function(item){
        item.addEventListener('mouseenter', function(){
            if (window._quizPushTimer){ clearTimeout(window._quizPushTimer); window._quizPushTimer = null; }
            var c = quizPushCompById(item.getAttribute('data-c'));
            if (!c) return;
            var list = c.assessments || [];
            var h = '<div class="px-2 py-1 small fw-bold" style="color:var(--horizon-text-muted)">' + esc(c.name) + ' — assessments</div>';
            if (!list.length){
                h += '<div class="px-2 py-2 small text-muted">No assessments yet — create one in Assessments first.</div>';
            } else {
                list.forEach(function(x){
                    h += '<button type="button" class="dropdown-item q-push-assess" data-c="' + c.component_id + '" data-a="' + x.assessment_id + '" data-n="' + esc(x.name) + '" data-h="' + esc(x.hps) + '"><span class="fw-bold" style="font-size:12.5px">' + esc(x.name) + '</span><br><small class="text-muted">' + esc(x.quarter_name || '') + ' · HPS ' + esc(x.hps) + '</small></button>';
                });
            }
            pop.innerHTML = h;
            var r = item.getBoundingClientRect();
            pop.style.display = 'block';
            pop.style.left = Math.min(r.right + 6, Math.max(8, window.innerWidth - 330)) + 'px';
            pop.style.top = Math.max(8, Math.min(r.top, window.innerHeight - 220)) + 'px';
        });
        item.addEventListener('mouseleave', function(){
            if (window._quizPushTimer) clearTimeout(window._quizPushTimer);
            window._quizPushTimer = setTimeout(hidePushPop, 180);
        });
    });
    menu.addEventListener('mouseleave', function(){
        if (window._quizPushTimer) clearTimeout(window._quizPushTimer);
        window._quizPushTimer = setTimeout(hidePushPop, 180);
    });
    /* custom fixed menu: closes on outside click / Escape (bound once above) */
    var go = document.getElementById('quizPushGo');
    if (go) go.addEventListener('click', function(){
        if (!quizPushSel.assessId) return;
        openQuizConfirm({tone:'success', icon:'fa-paper-plane', title:'Submit as marks?', text:'Push ' + (quizLastSummary.submitted || quizPushSubmitted) + ' raw score(s) to "' + quizPushSel.assessName + '"? Each quiz score is recorded as-is and replaces existing marks for those students.', okLabel:'Submit', okIcon:'fa-paper-plane', okClass:'btn-success', onConfirm:function(){ doPushMarks(); }});
    });
}
document.getElementById('quizResultsBody').addEventListener('click', function(e){
    var f = e.target.closest('.q-filter');
    if (f){ renderQuizResults(f.getAttribute('data-f')); return; }
    var v = e.target.closest('.q-viol');
    if (v){ showViolations(v.getAttribute('data-i')); return; }
    var st = e.target.closest('.q-state');
    if (st){ openQuizAttempt(st.getAttribute('data-i')); return; }
    var del = e.target.closest('.q-attempt-del');
    if (del){
        var delAid = del.getAttribute('data-aid'), delAName = del.getAttribute('data-name');
        openQuizConfirm({icon:'fa-user-times', title:'Delete this attempt?', text:'Score and violations of "' + delAName + '" will be removed and the student can retake the quiz.', okLabel:'Delete attempt', onConfirm:function(){ post('delete_attempt', { quiz_id: quizLastQuizId, attempt_id: delAid }, function(j){ alertBox('success', j.msg); openQuizResults(quizLastQuizId, quizLastQuizTitle, quizResultsFilter); }); }});
        return;
    }
    var clr = e.target.closest('.q-clear-all');
    if (clr){
        openQuizConfirm({icon:'fa-trash-alt', title:'Clear all attempts?', text:'Every score for this quiz will be removed and the whole class can retake. This cannot be undone.', okLabel:'Clear all', onConfirm:function(){ post('clear_attempts', { quiz_id: quizLastQuizId }, function(j){ alertBox('success', j.msg); openQuizResults(quizLastQuizId, quizLastQuizTitle, quizResultsFilter); }); }});
    }
});
updateCounts();
loadQuizzes();
// Keep Taken counts live: the table otherwise only loads once, so a quiz
// submitted after page load would still show 0 until manual Refresh.
setInterval(function(){ if (!document.hidden) loadQuizzes(); }, 15000);
document.getElementById('quizResultsModal').addEventListener('hidden.bs.modal', function(){ if (window._quizResultsTimer){ clearInterval(window._quizResultsTimer); window._quizResultsTimer = null; } loadQuizzes(); });
})();
</script>
