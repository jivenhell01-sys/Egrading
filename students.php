<style>
    /* From Uiverse.io by cssbuttons-io */ 
button {
 position: relative;
 border: none;
 background: transparent;
 padding: 2px;
 cursor: pointer;
 outline-offset: 4px;
 transition: filter 250ms;
 user-select: none;
 touch-action: manipulation;
 gap: 25px;
}

.shadow {
 position: absolute;
 top: 0;
 left: 0;
 width: 100%;
 height: 100%;
 border-radius: 12px;
 background: hsl(0deg 0% 0% / 0.25);
 will-change: transform;
 transform: translateY(2px);
 transition: transform
    600ms
    cubic-bezier(.3, .7, .4, 1);
}

.edge {
 position: absolute;
 top: 0;
 left: 0;
 width: 100%;
 height: 100%;
 border-radius: 12px;
 background: linear-gradient(
    to left,
    hsl(340deg 100% 16%) 0%,
    hsl(340deg 100% 32%) 8%,
    hsl(340deg 100% 32%) 92%,
    hsl(340deg 100% 16%) 100%
  );
}

.front {
 display: block;
 position: relative;
 padding: 8px 20px;
 border-radius: 12px;
 font-size: 11px;
 font-weight: bold;
 color: white;
 background: hsl(345deg 100% 47%);
 will-change: transform;
 transform: translateY(-4px);
 transition: transform
    600ms
    cubic-bezier(.3, .7, .4, 1);
 text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
}

button:hover {
 filter: brightness(110%);
}

button:hover .front {
 transform: translateY(-6px);
 transition: transform
    250ms
    cubic-bezier(.3, .7, .4, 1.5);
}

button:active .front {
 transform: translateY(-2px);
 transition: transform 34ms;
}

button:hover .shadow {
 transform: translateY(4px);
 transition: transform
    250ms
    cubic-bezier(.3, .7, .4, 1.5);
}

button:active .shadow {
 transform: translateY(1px);
 transition: transform 34ms;
}

button:focus:not(:focus-visible) {
 outline: none;
}
 .btn_act {
          background-color:rgb(255, 174, 0) !important;
          border-radius: 5px;
          width: 100px;
          height: 30px;
          color: white !important;
      }
      .btn_act:hover{
          color: black !important;
          font-weight: bold !important;
          cursor: pointer !important;
          border:solid 1px !important;
      }
      
</style>
<link rel="stylesheet" href="css/button.css">
<link rel="stylesheet" href="./Font-Awesome-master/css/all.min.css">

<div class="card class-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h4 class="m-0 text-light"><i class="fas fa-users me-2"></i>Student Directory</h4>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <button class="btn btn-sm btn-danger p-2" type="button" id="delete_selected" style="display: none;" title="Delete selected students">
                <i class="fas fa-trash me-1"></i><span id="delete_selected_label">Delete Selected</span>
            </button>

            <button type="button" class="btn btn-primary btn-sm" id="create_new" title="Add a single student">
                <i class="fas fa-plus me-1"></i> Add Student
            </button>

            <!-- Secondary actions collapsed into a single dropdown -->
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" id="stuActionsBtn"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true" title="More actions">
                    <i class="fas fa-ellipsis-vertical me-1"></i> Actions
                </button>
                <ul class="dropdown-menu dropdown-menu-end stu-actions-menu" id="stuActionsMenu" aria-labelledby="stuActionsBtn">
                    <li><h6 class="dropdown-header">Students</h6></li>
                    <li>
                        <button type="button" class="dropdown-item" id="bulk_add" title="Add many students at once via the Bulk tab">
                            <i class="fas fa-users"></i> Bulk Add
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item" id="export_students_excel" title="Export the current view to Excel">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Print</h6></li>
                    <li>
                        <button type="button" class="dropdown-item" id="print_master_list" title="Print master list">
                            <i class="fas fa-print"></i> Print Master List
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item" id="print_class_info" title="Print class information (needs a section)">
                            <i class="fas fa-file-alt"></i> Print Class Info
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Print Style (for the print_master_list.php page) -->
    <style type="text/css" media="print">
        @page {
            size: auto;
            margin: 20mm;
        }
        body {
            padding: 20px;
            font-family: Arial, sans-serif;
        }
        .print-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .print-header h2, .print-header h3 {
            margin: 5px 0;
        }
        .print-date {
            text-align: right;
            margin-bottom: 20px;
            color: #666;
        }
        .section-title {
            background-color: #f5f5f5;
            padding: 8px 15px;
            margin: 20px 0 10px 0;
            border-left: 4px solid #007bff;
            font-weight: bold;
        }
        .student-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .student-table th, .student-table td {
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            text-align: left;
        }
        .student-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .student-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .no-print {
            display: none !important;
        }
        .print-footer {
            margin-top: 30px;
            text-align: right;
        }
        
    </style>
    <div class="card-body p-3">
        <!-- Breadcrumb + search + section actions -->
        <div class="stu-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb stu-breadcrumb mb-0" id="stuBreadcrumb">
                    <li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="root"><i class="fas fa-home me-1"></i>All Year Levels</a></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-light text-dark border" id="stuCountBadge">0 students</span>
                <div class="input-group input-group-sm stu-search-wrap">
                    <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control" id="stuSearch" placeholder="Search..." autocomplete="off">
                </div>
                <button type="button" id="printQRCodes" class="btn btn-primary btn-sm" style="display:none;" title="Print QR codes for this section">
                    <i class="fas fa-qrcode me-1"></i> Print QR Codes
                </button>
                <button type="button" id="stuSelectAll" class="btn btn-outline-secondary btn-sm" style="display:none;" title="Select / deselect all students in this section">
                    <i class="fas fa-check-double me-1"></i> Select All
                </button>
            </div>
        </div>
        <div id="stuLoading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
            <div class="small text-muted mt-2">Loading student directory...</div>
        </div>
        <div id="stuEmpty" class="text-center py-5 text-muted" style="display:none;">
            <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-50"></i>
            <div class="fw-bold" id="stuEmptyTitle">Nothing here yet</div>
            <small id="stuEmptySub">Try a different search or add new records.</small>
        </div>
        <!-- Level 1: Year-level folders -->
        <div id="yearGrid" class="stu-folder-grid" style="display:none;"></div>
        <!-- Level 2: Section folders inside a year level -->
        <div id="sectionGrid" class="stu-folder-grid" style="display:none;"></div>
        <!-- Level 3: Student cards inside a section -->
        <div id="studentGrid" class="stu-student-grid" style="display:none;"></div>
    </div>
</div>
<style>
    .class-card {
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        font-size: 0.9rem;
    }
    /* Irregular badge + modal */
    .irregular-badge{cursor:pointer; font-size:.62rem; letter-spacing:.04em; text-transform:uppercase; border-radius:999px; padding:.28em .55em; border:1px solid #f59e0b; background:#fffbeb; color:#92400e; font-weight:700;}
    .irregular-badge:hover{background:#fef3c7; border-color:#d97706;}
    #irregularModal .modal-header{padding:.9rem 1rem;}
    #irregularModal .table thead th{font-size:.78rem; text-transform:uppercase; letter-spacing:.04em;}
    .class-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }
   
    .card-header h4 {
        font-size: 1.5rem;
        margin: 0;
    }
    .card-header {
        padding: 0.5rem 1rem !important;
    }
    .table {
        --bs-table-hover-bg: rgba(78, 115, 223, 0.05);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }
    .table td, .table th {
        padding: 0.20rem 0.30rem;
        vertical-align: middle;
    }
    .table tr {
        height: auto;
    }
    .table th {
        font-weight: bold;
        text-transform: uppercase;
        font-size: 9px;
        letter-spacing: 0.3px;
        background-color: #f8f9fc;
        padding: 0.3rem 0.35rem;
    }
    .action-btn {
        min-width: 30px;
        padding: 0.20rem 0.45rem;
        font-size: 0.8rem;
        transition: all 0.2s ease;
    }
    .dropdown-item {
        padding: 0.40rem 0.80rem;
        font-size: 12px;
        font-weight: bold;
        line-height: 1.2;
    }
    .dropdown-item i {
        width: 20px;
        text-align: center;
        margin-right: 6px;
        font-size: 12px;
    }
    .edit_data {
        color:rgb(113, 178, 231);
    }
    .edit_data:hover {
        background-color: rgba(28, 200, 138, 0.1);
        color:rgb(0, 0, 0);
    }
    .delete_data {
        color: #e74a3b;
    }
    .delete_data:hover {
        background-color: rgba(231, 74, 59, 0.1);
        color: #e74a3b;
    }
    .student-name {
        font-weight: 600;
        color: #2e59d9;
        line-height: 1.2;
    }
    .student-number {
        font-family: 'Courier New', monospace;
        color: #6c757d;
    }
    
    /* Preloader styles */
    .preloader {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(255, 255, 255, 0.7);
        z-index: 1060;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    
    /* Ensure modal appears above the preloader */
    .modal {
        z-index: 1070;
    }
    
    /* Make sure the delete confirmation modal is visible */
    #deleteConfirmModal .modal-dialog {
        z-index: 1080;
    
        font-size: 1rem;
    }
    
    /* Style for the checkboxes */
    .student-checkbox {
        cursor: pointer;
    }

    /* Status-based row coloring - static colors */
    .status-dropped {
        background-color: rgba(220, 53, 69, 0.15) !important;
    }

    .status-nosub {
        background-color: rgba(255, 165, 0, 0.15) !important;
    }

    .status-noexam {
        background-color: rgba(0, 255, 255, 0.15) !important;
    }

    /* Ensure status colors remain on hover */
    .table-hover tbody tr.status-dropped:hover td {
        background-color: rgba(220, 53, 69, 0.15) !important;
    }
    .table-hover tbody tr.status-nosub:hover td {
        background-color: rgba(255, 165, 0, 0.15) !important;
    }
    .table-hover tbody tr.status-noexam:hover td {
        background-color: rgba(0, 255, 255, 0.15) !important;
    }

    /* Toast notification styles */
    .custom-toast {
        position: fixed !important;
        top: 20px !important;
        right: 20px !important;
        z-index: 9999 !important;
        min-width: 300px !important;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
        border-radius: 4px !important;
        padding: 15px 20px !important;
        margin-bottom: 10px !important;
        opacity: 0;
        transition: opacity 0.3s ease-in-out;
    }
    
    .custom-toast.show {
        opacity: 1;
    }
    
    .custom-toast .btn-close {
        padding: 0.5rem 0.5rem;
        margin: -0.5rem -0.5rem -0.5rem auto;
    }

    /* Prevent button padding changes when modal opens/closes */
    .btn {
        padding: 0.375rem 0.75rem !important;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem !important;
    }
    
    /* Ensure specific buttons maintain their padding */
    #delete_selected {
        padding: 0.75rem !important;
    }
    
    .action-btn {
        padding: 0.20rem 0.45rem !important;
    }
    
    /* Fix for modal affecting button styles */
    .modal-open .btn {
        padding: 0.375rem 0.75rem !important;
    }

    .modal-open .btn-sm {
        padding: 0.25rem 0.5rem !important;
    }

    /* ===== Student Directory: folders + cards (professional) ===== */
    .stu-breadcrumb {
        background: transparent;
        font-size: 0.85rem;
    }
    .stu-breadcrumb a {
        text-decoration: none;
        font-weight: 600;
    }
    /* ---- Palette: matches landingpage.php (ESSU green + gold) ---- */
    .stu-search-wrap {
        width: 240px;
    }
    .stu-search-wrap .form-control,
    .stu-search-wrap .input-group-text {
        border: 2px solid #e0e0e0;
        background: #f8f9fa;
        border-radius: 10px;
        color: #1a1a1a;
        transition: border-color .25s ease, box-shadow .25s ease;
    }
    .stu-search-wrap .form-control:focus {
        border-color: #1a4d2e;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(26,77,46,.1);
    }
    .stu-search-wrap .input-group-text {
        border-right: 0;
        border-radius: 10px 0 0 10px;
        color: #1a4d2e;
    }
    .stu-search-wrap .form-control {
        border-left: 0;
        border-radius: 0 10px 10px 0;
        padding-left: .6rem;
    }
    #stuCountBadge {
        background: rgba(212,175,55,.14) !important;
        color: #7a5c12 !important;
        border: 1px solid rgba(212,175,55,.5) !important;
        font-weight: 700;
        letter-spacing: .03em;
    }
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
        filter: none;
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
    .stu-student-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 1rem;
    }
    .stu-card {
        position: relative;
        background: var(--horizon-card-bg, #fff);
        border: 1px solid var(--horizon-card-border, #e9ecef);
        border-radius: 14px;
        padding: 1rem;
        box-shadow: 0 1px 2px rgba(16,24,40,.06);
        cursor: pointer;
        transition: transform .15s ease, box-shadow .2s ease, border-color .2s ease;
        overflow: visible;
    }
    .stu-card:has(.dropdown-menu.show) { z-index: 5; }
    .stu-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(26,77,46,.14);
        border-color: #d4af37;
    }
    .stu-card.selected {
        border-color: #1a4d2e;
        box-shadow: 0 0 0 3px rgba(26,77,46,.16);
    }
    .stu-card-top { display: flex; align-items: center; gap: 0.75rem; }
    .stu-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        min-width: 46px;
        border-radius: 50%;
        background: linear-gradient(135deg,#1a4d2e 0%,#2d5f3f 100%);
        color: #fff;
        font-weight: 800;
        font-size: 0.9rem;
        letter-spacing: .02em;
    }
    .stu-card.status-Dropped .stu-avatar { background: linear-gradient(135deg,#f87171 0%,#dc2626 100%); }
    .stu-card.status-NoSub .stu-avatar { background: linear-gradient(135deg,#fbbf24 0%,#d97706 100%); }
    .stu-card.status-NoExam .stu-avatar { background: linear-gradient(135deg,#22d3ee 0%,#0891b2 100%); }
    .stu-name {
        font-weight: 800;
        font-size: 0.88rem;
        text-transform: uppercase;
        color: var(--horizon-text-heading, #1B2559);
        line-height: 1.25;
        word-break: break-word;
    }
    .stu-num {
        font-family: 'Courier New', monospace;
        font-size: 0.88rem;
        color: #8a6a12;
        font-weight: 700;
    }
    /* Grade + section context line (search results outside a folder) */
    .stu-where {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        margin-top: .25rem;
        font-size: .66rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #6b5312;
        background: rgba(212,175,55,.18);
        border: 1px solid rgba(212,175,55,.45);
        border-radius: 999px;
        padding: .12rem .5rem;
    }
    .stu-where i { font-size: .62rem; }
    .stu-card-locate {
        margin-top: .55rem;
        width: 100%;
        border: 1px solid #1a4d2e;
        background: #fff;
        color: #1a4d2e;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        border-radius: 8px;
        padding: .3rem .5rem !important;
    }
    .stu-card-locate:hover {
        background: linear-gradient(135deg,#1a4d2e 0%,#2d5f3f 100%);
        color: #fff;
        filter: none;
    }
    .stu-search-note {
        display: flex;
        align-items: center;
        gap: .4rem;
        margin-bottom: .6rem;
        font-size: .74rem;
        font-weight: 600;
        color: #6b5312;
        background: linear-gradient(135deg, rgba(212,175,55,.18) 0%, rgba(244,229,194,.35) 100%);
        border-left: 4px solid #d4af37;
        border-radius: 10px;
        padding: .45rem .7rem;
    }
    button.stu-status-btn {
        background: transparent;
        border: 0;
        padding: 0;
        cursor: pointer;
        line-height: 1;
    }
    button.stu-status-btn:hover { filter: none; }
    .stu-card .dropdown-menu { font-size: 0.8rem; min-width: 11rem; }
    .stu-card .dropdown-item { cursor: pointer; font-weight: 600; }
    .stu-card-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-top: 0.7rem;
    }
    .stu-status {
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-radius: 999px;
        padding: 0.25em 0.65em;
    }
    .stu-status.st-Active { background: rgba(26,77,46,.12); color: #1a4d2e; }
    .stu-status.st-Dropped { background: rgba(220,53,69,.14); color: #b91c1c; }
    .stu-status.st-NoSub { background: rgba(212,175,55,.2); color: #7a5c12; }
    .stu-status.st-NoExam { background: rgba(13,110,253,.12); color: #0b4a9c; }
    .stu-card-actions { display: flex; gap: 0.25rem; }
    .stu-card-actions .btn {
        padding: 0.2rem 0.45rem !important;
        font-size: 0.72rem;
    }
    .stu-select {
        position: absolute;
        top: 0.6rem;
        right: 0.6rem;
    }
    .stu-select input { width: 1.05rem; height: 1.05rem; cursor: pointer; }
    /* Header Actions dropdown */
    .stu-actions-menu {
        min-width: 15rem;
    }
    .stu-actions-menu .dropdown-header {
        font-size: 0.66rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .stu-actions-menu .dropdown-item {
        white-space: nowrap;
    }
    /* Directory header + primary actions, green/gold like landingpage.php */
    .class-card .card-header {
        background: linear-gradient(135deg,#1a4d2e 0%,#2d5f3f 100%);
        border-radius: 8px 8px 0 0;
        position: relative;
    }
    .class-card .card-header::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        background: linear-gradient(90deg,#d4af37,#f4e5c2,#d4af37);
        background-size: 200% 100%;
        animation: stuDirShimmer 3s infinite;
    }
    @keyframes stuDirShimmer {
        0% { background-position: -1000px 0; }
        100% { background-position: 1000px 0; }
    }
    .class-card .card-header h4 {
        color: #fff;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        font-size: 1.05rem;
        text-shadow: 0 2px 4px rgba(0,0,0,.3);
    }
    .class-card .card-header .btn-primary {
        background: linear-gradient(135deg,#1a4d2e 0%,#2d5f3f 100%);
        border-color: transparent;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        box-shadow: 0 4px 12px rgba(26,77,46,.3);
    }
    .class-card .card-header .btn-primary:hover {
        filter: brightness(1.08);
        color: #fff;
    }
    .class-card .card-header .btn-outline-secondary {
        border-color: rgba(212,175,55,.65);
        color: #f4e5c2;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .class-card .card-header .btn-outline-secondary:hover {
        background: #d4af37;
        border-color: #d4af37;
        color: #1a4d2e;
        filter: none;
    }
    .class-card .card-header .btn-danger {
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .stu-breadcrumb a {
        color: #1a4d2e;
    }
    .stu-breadcrumb a:hover {
        color: #8a6a12;
    }
    .stu-breadcrumb .breadcrumb-item.active {
        color: #6c757d;
    }

    /* Dark mode */
    html[data-theme="dark"] .stu-folder-card, body[data-theme="dark"] .stu-folder-card, body[data-bs-theme="dark"] .stu-folder-card,
    html[data-theme="dark"] .stu-card, body[data-theme="dark"] .stu-card, body[data-bs-theme="dark"] .stu-card {
        background: var(--horizon-card-bg, #182235);
        border-color: var(--horizon-card-border, #2b3850);
    }
    html[data-theme="dark"] .stu-folder-title, body[data-theme="dark"] .stu-folder-title, body[data-bs-theme="dark"] .stu-folder-title,
    html[data-theme="dark"] .stu-name, body[data-theme="dark"] .stu-name, body[data-bs-theme="dark"] .stu-name {
        color: var(--horizon-text-title, #f1f5ff);
    }
    html[data-theme="dark"] .stu-num, body[data-theme="dark"] .stu-num, body[data-bs-theme="dark"] .stu-num {
        color: #F2C94C;
    }
    html[data-theme="dark"] .stu-where, body[data-theme="dark"] .stu-where, body[data-bs-theme="dark"] .stu-where {
        background: rgba(212,175,55,.18);
        border-color: rgba(212,175,55,.45);
        color: #F2C94C;
    }
    html[data-theme="dark"] .stu-card-locate, body[data-theme="dark"] .stu-card-locate, body[data-bs-theme="dark"] .stu-card-locate {
        border-color: #d4af37;
        color: #F2C94C;
        background: transparent;
    }
    html[data-theme="dark"] .stu-search-note, body[data-theme="dark"] .stu-search-note, body[data-bs-theme="dark"] .stu-search-note {
        background: rgba(212,175,55,.14);
        color: #F2C94C;
    }
    html[data-theme="dark"] .stu-search-wrap .form-control,
    body[data-theme="dark"] .stu-search-wrap .form-control,
    body[data-bs-theme="dark"] .stu-search-wrap .form-control,
    html[data-theme="dark"] .stu-search-wrap .input-group-text,
    body[data-theme="dark"] .stu-search-wrap .input-group-text,
    body[data-bs-theme="dark"] .stu-search-wrap .input-group-text {
        background: #141d2e;
        border-color: #35445e;
        color: #f1f5ff;
    }
    html[data-theme="dark"] .stu-folder-card:hover, body[data-theme="dark"] .stu-folder-card:hover, body[data-bs-theme="dark"] .stu-folder-card:hover,
    html[data-theme="dark"] .stu-card:hover, body[data-theme="dark"] .stu-card:hover, body[data-bs-theme="dark"] .stu-card:hover {
        border-color: #d4af37;
    }
    @media (max-width: 576px) {
        .stu-search-wrap { width: 100%; }
        .stu-folder-grid, .stu-student-grid { grid-template-columns: 1fr 1fr; gap: 0.7rem; }
    }
    /* Fixed-height scrollable directory viewport */
    #yearGrid, #sectionGrid, #studentGrid {
        height: calc(100vh - 290px);
        min-height: 380px;
        max-height: 700px ;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 0.25rem 0.35rem 0.75rem 0.1rem;
        scrollbar-width: thin;
        scrollbar-color: var(--horizon-brand-300, #8171FC) transparent;
    }
    #yearGrid::-webkit-scrollbar, #sectionGrid::-webkit-scrollbar, #studentGrid::-webkit-scrollbar {
        width: 8px;
    }
    #yearGrid::-webkit-scrollbar-track, #sectionGrid::-webkit-scrollbar-track, #studentGrid::-webkit-scrollbar-track {
        background: transparent;
    }
    #yearGrid::-webkit-scrollbar-thumb, #sectionGrid::-webkit-scrollbar-thumb, #studentGrid::-webkit-scrollbar-thumb {
        background: var(--horizon-brand-200, #A195FD);
        border-radius: 8px;
    }
    #yearGrid, #sectionGrid, #studentGrid { align-content: start; }
    @media (max-width: 576px) {
        #yearGrid, #sectionGrid, #studentGrid { height: calc(100vh - 320px); min-height: 300px; }
    }
</style>
<script>
    // Utility functions for showing/hiding loading state
    function start_loader() {
        $('body').append('<div class="preloader flex-column justify-content-center align-items-center"><div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div></div>');
    }

    function end_loader() {
        $('.preloader').fadeOut('fast', function() {
            $(this).remove();
        });
    }
    
    // Toast notification function
    function alert_toast(message, type = 'info', timer = 3000) {
        // Remove any existing toasts
        $('.custom-toast').remove();
        
        // Create toast element
        var toast = $('<div class="custom-toast alert alert-' + (type === 'error' ? 'danger' : type) + ' alert-dismissible fade show" role="alert">' +
                     message +
                     '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                     '</div>');
        
        // Position the toast
        toast.css({
            'position': 'fixed',
            'top': '20px',
            'right': '20px',
            'z-index': '9999',
            'min-width': '250px',
            'box-shadow': '0 4px 12px rgba(0,0,0,0.15)'
        });
        
        // Add to body and show
        $('body').append(toast);
        
        // Auto-hide after delay
        setTimeout(function() {
            toast.alert('close');
        }, timer);
    }

    $(function(){
        // Card checkbox selection (section view)
        $(document).on('change', '.student-checkbox', function(e) {
            e.stopPropagation();
            var id = $(this).val();
            var card = $(this).closest('.stu-card');
            if ($(this).is(':checked')) {
                StudentDir.select(id, true);
                card.addClass('selected');
            } else {
                StudentDir.select(id, false);
                card.removeClass('selected');
            }
            StudentDir.updateDeleteButton();
        });

        // Select-all toggle button (section view)
        $(document).on('click', '#stuSelectAll', function() {
            StudentDir.toggleSelectAll();
        });

        // Live search across the current level
        $(document).on('input', '#stuSearch', function() {
            StudentDir.search($(this).val());
        });

        // Breadcrumb navigation
        $(document).on('click', '#stuBreadcrumb [data-nav]', function() {
            var nav = $(this).data('nav');
            if (nav === 'root') StudentDir.goRoot();
            else if (nav === 'grade') StudentDir.openGrade($(this).data('grade'));
        });
        
        // Initialize DataTable
        // Initialize DataTable with autoWidth and fixedHeader
        /* ===== Student Directory folder engine (replaces the DataTable) ===== */
        window.StudentDir = (function(){
            var cache = [];
            var grade = null;
            var section = null;
            var selected = {};
            var search = '';

            function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
            function initials(name){
                var parts = String(name || '').replace(/,/g, ' ').split(/\s+/).filter(Boolean);
                if(!parts.length) return '?';
                var last = parts.length > 1 ? parts[parts.length-1][0] : '';
                return (parts[0][0] + last).toUpperCase();
            }
            function normStatus(st){ return String(st || 'Active'); }
            var STATUS_OPTS = [
                { v: 'Active', label: 'Active' },
                { v: 'Dropped', label: 'Dropped' },
                { v: 'NoSub', label: 'No Submissions' },
                { v: 'NoExam', label: 'No Exam' }
            ];
            function statusOptions(id, current){
                return STATUS_OPTS.map(function(o){
                    var mark = (o.v === current) ? ' <i class="fas fa-check ms-1"></i>' : '';
                    return '<li><a class="dropdown-item stu-status-opt' + (o.v === current ? ' active' : '') + '" data-id="' + id + '" data-status="' + o.v + '">' + o.label + mark + '</a></li>';
                }).join('');
            }
            function statusLabel(st){
                st = normStatus(st);
                if(st === 'NoSub') return 'No Sub';
                if(st === 'NoExam') return 'No Exam';
                return st;
            }
            function findGrade(g){ return cache.find(function(x){ return x.grade === g; }); }
            function findSection(g, s){
                var G = findGrade(g);
                if(!G) return null;
                return (G.sections || []).find(function(x){ return x.section === s; }) || null;
            }
            function currentStudents(){
                var S = (grade && section) ? findSection(grade, section) : null;
                return S ? (S.students || []) : [];
            }
            function matchesQuery(st, q){
                if(!q) return true;
                return String(st.name || '').toLowerCase().indexOf(q) !== -1 ||
                       String(st.student_number || '').toLowerCase().indexOf(q) !== -1;
            }
            function filteredStudents(){
                var q = search.trim().toLowerCase();
                var list = currentStudents();
                if(!q) return list;
                return list.filter(function(st){ return matchesQuery(st, q); });
            }

            // Search across the WHOLE directory, not just the open folder.
            // Returns [{student, grade, section}] so a hit can be located even
            // when it lives in a year level / section that was never opened.
            function searchAllStudents(){
                var q = search.trim().toLowerCase();
                var hits = [];
                if(!q) return hits;
                cache.forEach(function(g){
                    (g.sections || []).forEach(function(s){
                        (s.students || []).forEach(function(st){
                            if(matchesQuery(st, q)) hits.push({ student: st, grade: g.grade, section: s.section });
                        });
                    });
                });
                return hits;
            }

            function load(){
                $('#stuLoading').show();
                $('#yearGrid, #sectionGrid, #studentGrid, #stuEmpty').hide();
                $.ajax({
                    url: './Actions.php?a=get_student_folders',
                    method: 'GET',
                    dataType: 'json',
                    success: function(resp){
                        $('#stuLoading').hide();
                        if(resp && resp.status === 'success'){
                            cache = resp.data || [];
                            selected = {};
                            // Stay on the currently opened folder after a refresh
                            // (e.g. after adding/editing a student). Only fall
                            // back to the Year Level folders (root) when the
                            // opened folder no longer exists. A full page
                            // reload still starts at root since grade/section
                            // are null on fresh page load.
                            if (grade && !findGrade(grade)) { grade = null; section = null; }
                            else if (grade && section && !findSection(grade, section)) { section = null; }
                            try{ sessionStorage.removeItem('stuDirPath'); }catch(e){}
                            render();
                        } else {
                            showEmpty('Failed to load students', esc((resp && resp.msg) || 'Please try again.'));
                        }
                    },
                    error: function(){
                        $('#stuLoading').hide();
                        showEmpty('Connection error', 'Could not reach the server. Please reload the page.');
                    }
                });
            }
            function refresh(){ selected = {}; load(); }

            function showEmpty(title, sub){
                $('#yearGrid, #sectionGrid, #studentGrid').hide();
                $('#printQRCodes, #stuSelectAll').hide();
                $('#stuEmptyTitle').text(title);
                $('#stuEmptySub').text(sub);
                $('#stuEmpty').show();
                $('#stuCountBadge').text('0 students');
            }

            function renderBreadcrumb(){
                var h = '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="root"><i class="fas fa-home me-1"></i>All Year Levels</a></li>';
                if(grade){
                    h += '<li class="breadcrumb-item"><a href="javascript:void(0)" data-nav="grade" data-grade="' + esc(grade) + '">' + esc(grade) + '</a></li>';
                }
                if(grade && section){
                    h += '<li class="breadcrumb-item active" aria-current="page">' + esc(section) + '</li>';
                }
                $('#stuBreadcrumb').html(h);
            }

            // A search while no folder (or the wrong folder) is open shows every
            // match in the whole directory, each tagged with its grade/section.
            function renderGlobalSearch(q){
                var hits = searchAllStudents();
                var where = $('<div class="stu-search-note"><i class="fas fa-magnifying-glass"></i><span></span></div>');
                where.find('span').text(
                    hits.length + ' student' + (hits.length === 1 ? '' : 's') +
                    ' found across all year levels and sections' +
                    (grade ? ' (searching everywhere, not just ' + section + ')' : '')
                );
                $('#stuCountBadge').text(hits.length + ' student' + (hits.length === 1 ? '' : 's'));
                if(!hits.length){
                    showEmpty('No matching students', 'Try a different name or student number.');
                    return;
                }
                $('#studentGrid')
                    .html(where.prop('outerHTML') + hits.map(function(h){ return studentCard(h.student, { grade: h.grade, section: h.section }); }).join(''))
                    .show();
            }

            function render(){
                renderBreadcrumb();
                $('#stuEmpty').hide();
                $('#yearGrid, #sectionGrid, #studentGrid').hide();
                $('#printQRCodes, #stuSelectAll').hide();
                selected = {};
                updateDeleteButton();
                var q = search.trim().toLowerCase();

                if(q){
                    // Global search takes priority over folder filtering so a
                    // student is findable no matter which folder is open (or
                    // whether any folder is open at all).
                    renderGlobalSearch(q);
                    return;
                }

                if(!grade){
                    var total = cache.reduce(function(n, g){ return n + g.student_count; }, 0);
                    $('#stuCountBadge').text(total + ' student' + (total === 1 ? '' : 's'));
                    if(!cache.length){ showEmpty('No year levels found', 'Add new records to get started.'); return; }
                    $('#yearGrid').html(cache.map(yearCard).join('')).show();
                } else if(!section){
                    var G = findGrade(grade);
                    if(!G){ grade = null; render(); return; }
                    $('#stuCountBadge').text(G.student_count + ' student' + (G.student_count === 1 ? '' : 's'));
                    if(!G.sections.length){ showEmpty('No sections found', 'This year level has no sections yet.'); return; }
                    $('#sectionGrid').html(G.sections.map(sectionCard).join('')).show();
                } else {
                    var list = currentStudents();
                    var all = currentStudents();
                    $('#stuCountBadge').text(all.length + ' student' + (all.length === 1 ? '' : 's'));
                    $('#printQRCodes, #stuSelectAll').show();
                    if(!list.length){ showEmpty('No students in this section', 'Use Add Student or Bulk Add to enroll students here.'); return; }
                    $('#studentGrid').html(list.map(function(st){ return studentCard(st); }).join('')).show();
                }
            }

            function yearCard(g){
                return '<button type="button" class="stu-folder-card" data-grade="' + esc(g.grade) + '" title="Open ' + esc(g.grade) + '">' +
                    '<span class="stu-folder-icon"><i class="fas fa-folder"></i></span>' +
                    '<span class="stu-folder-meta"><span class="stu-folder-title">' + esc(g.grade) + '</span><br>' +
                    '<span class="stu-folder-sub">' + g.section_count + ' section' + (g.section_count === 1 ? '' : 's') + ' &middot; ' + g.student_count + ' student' + (g.student_count === 1 ? '' : 's') + '</span></span>' +
                '</button>';
            }
            function sectionCard(s){
                return '<button type="button" class="stu-folder-card" data-section="' + esc(s.section) + '" title="Open ' + esc(s.section) + '">' +
                    '<span class="stu-folder-icon section"><i class="fas fa-folder-open"></i></span>' +
                    '<span class="stu-folder-meta"><span class="stu-folder-title">' + esc(s.section) + '</span><br>' +
                    '<span class="stu-folder-sub">' + s.count + ' student' + (s.count === 1 ? '' : 's') + '</span></span>' +
                '</button>';
            }
            function studentCard(st, where){
                var stt = normStatus(st.status);
                var sel = selected[st.student_id] ? ' checked' : '';
                var selCls = selected[st.student_id] ? ' selected' : '';
                // where = {grade, section} when the card was rendered by the
                // global search instead of from inside a folder.
                var context = where && where.grade
                    ? '<span class="stu-where"><i class="fas fa-folder-open"></i>' + esc(where.grade + ' - ' + where.section) + '</span>'
                    : '';
                var locate = where && where.grade
                    ? '<button type="button" class="btn stu-card-locate" data-locate-grade="' + esc(where.grade) + '" data-locate-section="' + esc(where.section) + '" title="Open ' + esc(where.section) + '">' +
                        '<i class="fas fa-folder-open me-1"></i>Open Section</button>'
                    : '';
                return '<div class="stu-card status-' + esc(stt) + selCls + '" data-id="' + st.student_id + '">' +
                    '<label class="stu-select" title="Select student"><input type="checkbox" class="student-checkbox form-check-input" value="' + st.student_id + '"' + sel + '></label>' +
                    '<div class="stu-card-top">' +
                        '<span class="stu-avatar">' + esc(initials(st.name)) + '</span>' +
                        '<div style="min-width:0;"><div class="stu-name">' + esc(st.name || 'Unnamed') + '</div>' +
                        '<div class="stu-num">' + esc(st.student_number || 'No number') + (st.gender ? ' &middot; ' + esc(st.gender) : '') + '</div>' +
                        context + '</div>' +
                    '</div>' +
                    locate +
                    '<div class="stu-card-foot">' +
                        '<span class="dropdown">' +
                            '<button type="button" class="stu-status-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Click to change status">' +
                                '<span class="stu-status st-' + esc(stt) + '">' + esc(statusLabel(stt)) + '</span>' +
                            '</button>' +
                            '<ul class="dropdown-menu">' + statusOptions(st.student_id, stt) + '</ul>' +
                        '</span>' +
                        '<span class="stu-card-actions">' +
                            '<button type="button" class="btn btn-sm btn-outline-info qr-btn" data-id="' + st.student_id + '" title="QR Code"><i class="fas fa-qrcode"></i></button>' +
                            '<button type="button" class="btn btn-sm btn-outline-primary edit_data" data-id="' + st.student_id + '" title="Edit"><i class="fas fa-edit"></i></button>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger stu-del" data-id="' + st.student_id + '" data-name="' + esc(st.name) + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                        '</span>' +
                    '</div>' +
                '</div>';
            }

            // Opening a folder clears the search so the directory returns to
            // normal browsing.
            function openGrade(g){ grade = g; section = null; clearSearch(); render(); }
            function openSection(s){ section = s; clearSearch(); render(); }
            function goRoot(){ grade = null; section = null; clearSearch(); render(); }
            function clearSearch(){
                search = '';
                $('#stuSearch').val('');
            }
            function sectionParam(){ return (grade && section) ? section : ''; }

            // "Open Section" on a global-search hit: jump straight to the folder
            // that holds the student, then highlight them.
            function locateStudent(gradeName, sectionName){
                var G = findGrade(gradeName);
                if(!G) return;
                grade = gradeName;
                section = sectionName;
                clearSearch();
                render();
                var hit = $('.stu-card').filter(function(){
                    var $c = $(this);
                    return $c.find('.stu-name').text().trim() !== '';
                }).first();
                hit.addClass('selected');
                setTimeout(function(){ hit.removeClass('selected'); }, 1600);
                if(hit.length) hit[0].scrollIntoView({ block: 'nearest' });
            }

            function select(id, on){
                id = String(id);
                if(on) selected[id] = true; else delete selected[id];
            }
            function selectedIds(){ return Object.keys(selected); }
            function toggleSelectAll(){
                var list = filteredStudents();
                var allOn = list.length > 0 && list.every(function(st){ return selected[String(st.student_id)]; });
                list.forEach(function(st){ select(st.student_id, !allOn); });
                // sync DOM without full re-render
                $('#studentGrid .stu-card').each(function(){
                    var $c = $(this);
                    var on = !!selected[String($c.data('id'))];
                    $c.toggleClass('selected', on);
                    $c.find('.student-checkbox').prop('checked', on);
                });
                updateDeleteButton();
            }
            function updateDeleteButton(){
                var n = selectedIds().length;
                if(n > 0){
                    $('#delete_selected').show();
                    $('#delete_selected_label').text('Delete Selected (' + n + ')');
                } else {
                    $('#delete_selected').hide();
                }
            }
            function findStudent(id){
                id = String(id);
                for(var i = 0; i < cache.length; i++){
                    var secs = cache[i].sections || [];
                    for(var j = 0; j < secs.length; j++){
                        var found = (secs[j].students || []).find(function(st){ return String(st.student_id) === id; });
                        if(found) return { student: found, grade: cache[i].grade, section: secs[j].section };
                    }
                }
                return null;
            }
            function setStatus(id, st){
                var hit = findStudent(id);
                if(hit){ hit.student.status = st; }
                render();
            }
            function removeIds(ids){
                var map = {};
                ids.forEach(function(id){ map[String(id)] = true; });
                cache.forEach(function(g){
                    (g.sections || []).forEach(function(s){
                        var before = (s.students || []).length;
                        s.students = (s.students || []).filter(function(st){ return !map[String(st.student_id)]; });
                        var removed = before - s.students.length;
                        s.count = s.students.length;
                        g.student_count -= removed;
                    });
                });
                Object.keys(selected).forEach(function(id){ if(map[String(id)]) delete selected[id]; });
                render();
            }

            // folder + card clicks (delegated; card buttons/checkboxes stop on their own)
            $(document).on('click', '.stu-folder-card[data-grade]', function(){ openGrade($(this).data('grade')); });
            $(document).on('click', '.stu-folder-card[data-section]', function(){ openSection($(this).data('section')); });
            $(document).on('click', '.stu-card', function(e){
                if($(e.target).closest('button, a, input, label').length) return;
                uni_modal('Edit Student Details In Class', 'manage_student.php?id=' + $(this).data('id'));
            });
            $(document).on('click', '.stu-del', function(e){
                e.stopPropagation();
                var id = $(this).data('id');
                var hit = findStudent(id);
                var nm = hit ? hit.student.name : ('Student ' + id);
                if(window.confirmDeleteStudents) window.confirmDeleteStudents([id], [nm], function(){ removeIds([id]); });
            });

            return {
                load: load, refresh: refresh, render: render,
                openGrade: openGrade, openSection: openSection, goRoot: goRoot,
                search: function(v){ search = v || ''; render(); },
                sectionParam: sectionParam,
                select: select, selectedIds: selectedIds, toggleSelectAll: toggleSelectAll,
                updateDeleteButton: updateDeleteButton,
                findStudent: findStudent, setStatus: setStatus, removeIds: removeIds,
                searchAllStudents: searchAllStudents, locateStudent: locateStudent
            };
        })();

        // "Open Section" button on a global-search hit
        $(document).on('click', '.stu-card-locate', function(e){
            e.stopPropagation();
            StudentDir.locateStudent($(this).data('locate-grade'), $(this).data('locate-section'));
        });

        // Print QR Codes button click (section toolbar, visible inside a section)
        $('#printQRCodes').click(function() {
            printQRCodes();
        });
        
        // Create new student
        $('#create_new').off('click').on('click', function(){
            uni_modal('Add New Student In Class', 'manage_student.php');
        });

        // Header buttons: act on the current folder context (whole directory at root,
        // current section inside a section folder)
        $('#bulk_add').on('click', function(){
            openStudentModal('Bulk Add Students', 'manage_student.php', 'bulk');
        });
        $('#export_students_excel').on('click', function(){
            window.location = 'export_students_excel.php?section=' + encodeURIComponent(StudentDir.sectionParam());
        });
        $('#print_master_list').on('click', function(){
            window.open('print_master_list.php?section=' + StudentDir.sectionParam(), '_blank');
        });
        $('#print_class_info').on('click', function(){ printClassInfo(); });

        // Close the Actions dropdown once an item is used (Bootstrap does not
        // auto-close menus built from non-anchor items)
        $(document).on('click', '#stuActionsMenu .dropdown-item', function(){
            var toggle = document.getElementById('stuActionsBtn');
            if(toggle && window.bootstrap){
                var dd = bootstrap.Dropdown.getInstance(toggle);
                if(dd) dd.hide();
            }
        });

        // Load the directory (always starts at the Year Level folders)
        StudentDir.load();
        // Delete selected students (card checkboxes in section view)
        $('#delete_selected').off('click').on('click', function() {
            var ids = StudentDir.selectedIds();
            if(!ids.length){
                alert_toast('No students selected.', 'warning');
                return;
            }
            var names = ids.map(function(id){
                var hit = StudentDir.findStudent(id);
                return hit ? hit.student.name : ('Student ' + id);
            });
            confirmDeleteStudents(ids, names, function(){
                StudentDir.removeIds(ids);
            });
        });
        // Irregular badge -> subject list
        $(document).on('click', '.irregular-badge', function(){
            var sn=$(this).data('sn')||'';
            if(!sn) return;
            var body=$('#irregularModalBody');
            $('#irregularModalTitle').text('Irregular Subjects — '+sn);
            body.html('<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Loading irregular subjects...</span></div>');
            if(window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById('irregularModal')).show();
            fetch('get_student_irregular.php?student_number='+encodeURIComponent(sn))
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if(!res||res.status!=='success'){ body.html('<div class="alert alert-danger mb-0">'+$('<div>').text((res&&res.message)||'Failed to load irregular subjects.').html()+'</div>'); return; }
                    if(!res.subjects||!res.subjects.length){
                        if(res.entries&&res.entries.length){
                            var h='<div class="alert alert-warning mb-2">Picked subjects have no live class yet in any database.</div><ul class="list-group list-group-flush mb-0">';
                            res.entries.forEach(function(e){ h+='<li class="list-group-item"><strong>'+$('<div>').text(e.code||'').html()+'</strong> — '+$('<div>').text(e.name||'').html()+' <span class="badge bg-secondary ms-1">'+$('<div>').text(e.section||'').html()+'</span></li>'; });
                            h+='</ul>'; body.html(h);
                        } else {
                            body.html('<div class="alert alert-info mb-0">This student is flagged Irregular but has no recorded irregular subjects.</div>');
                        }
                        return;
                    }
                    var hh='<div class="table-responsive"><table class="table table-sm table-striped table-bordered mb-0"><thead class="table-dark"><tr><th>Subject</th><th>Section</th><th>Faculty</th><th>State</th></tr></thead><tbody>';
                    res.subjects.forEach(function(s){
                        var st=s.state==='enrolled'?'<span class="badge bg-success">Enrolled</span>':(s.state==='passed'?'<span class="badge bg-info text-dark">Passed</span>':'<span class="badge bg-warning text-dark">Pending</span>');
                        hh+='<tr><td><strong>'+$('<div>').text(s.code||'').html()+'</strong> — '+$('<div>').text(s.name||'').html()+'<br><small class="text-muted">'+$('<div>').text((s.semester?s.semester+' · ':'')+s.acad_year).html()+'</small></td><td>'+$('<div>').text((s.program?s.program+' - ':'')+s.section).html()+'</td><td>'+$('<div>').text(s.faculty||'').html()+'</td><td>'+st+'</td></tr>';
                    });
                    hh+='</tbody></table></div><small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i>Pending = waiting to be assigned to that specific subject class.</small>';
                    body.html(hh);
                })
                .catch(function(){ body.html('<div class="alert alert-danger mb-0">Connection error. Please try again.</div>'); });
        });

        // Handle QR code button click
        $(document).on('click', '.qr-btn', function() {
            var studentId = $(this).data('id');
            var studentName = $(this).closest('tr').find('td:eq(3)').text().trim();
            
            // Open modal with QR code
            uni_modal('QR Code for ' + studentName, 'view_qrcode.php?student_id=' + studentId, 'modal-md');
        });

        // Handle edit button click
        $(document).on('click', '.edit_data', function() {
            uni_modal('Edit Student Details In Class', 'manage_student.php?id=' + $(this).data('id'));
        });
    });

    // Delete single or multiple students
    function delete_data(ids, callback) {
        if (!Array.isArray(ids)) {
            ids = [ids];
        }
        
        console.log('Deleting student(s) with IDs:', ids);

        // Don't show confirm dialog again if coming from delete_multiple_students
        if ($('#deleteConfirmModal').length === 0) {
            var _conf = confirm('Are you sure you want to delete the selected student(s)?');
            if(!_conf) {
                if (typeof callback === 'function') {
                    callback({ status: 'cancelled', msg: 'Operation cancelled by user' });
                }
                return false;
            }
        }

        start_loader();

        // Clean and validate IDs
        ids = ids.map(function(id) {
            // Convert to number if possible, otherwise keep as string
            var numId = parseInt(id, 10);
            return isNaN(numId) ? id.toString() : numId;
        }).filter(function(id, index, self) {
            // Remove empty, null, undefined, and duplicate values
            return id !== '' && 
                   id !== null && 
                   id !== undefined && 
                   id.toString().indexOf('row-') !== 0 &&
                   self.indexOf(id) === index;
        });

        if (ids.length === 0) {
            var errorMsg = 'No valid student IDs provided for deletion. Please check the IDs and try again.';
            console.error(errorMsg, 'Original IDs:', arguments[0]);
            alert_toast(errorMsg, 'error');
            end_loader();
            if (typeof callback === 'function') {
                callback({ status: 'error', msg: errorMsg });
            }
            return false;
        }
        
        // Prepare the data object based on whether we have one or multiple IDs
        var postData = {
            a: (ids.length > 1 ? 'delete_multiple_students' : 'delete_student')
        };
        
        // For single deletion, use 'id', for multiple use 'ids[]'
        if (ids.length === 1) {
            postData.id = ids[0];
        } else {
            postData.ids = ids;
        }
        
        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: postData,
            dataType: 'json',
            timeout: 30000, // 30 seconds timeout
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {
                    status: status,
                    error: error,
                    response: xhr.responseText,
                    statusCode: xhr.status,
                    statusText: xhr.statusText
                });
                
                var errorMsg = 'An error occurred while deleting the student(s).';
                
                try {
                    var response = xhr.responseText ? JSON.parse(xhr.responseText) : null;
                    if (response && response.msg) {
                        errorMsg = response.msg;
                    } else if (xhr.responseText && xhr.responseText.length < 1000) {
                        // If response is not JSON but contains text, show it
                        errorMsg = xhr.responseText;
                    }
                } catch (e) {
                    console.error('Error parsing error response:', e);
                }
                
                console.error('Deletion failed:', errorMsg);
                alert_toast('Error: ' + errorMsg, 'error');
                end_loader();
                
                if (typeof callback === 'function') {
                    callback({ 
                        status: 'error', 
                        msg: errorMsg,
                        error: error,
                        statusCode: xhr.status
                    });
                }
            },
            success: function(resp) {
                try {
                    console.log('Raw response:', resp);
                    
                    // If resp is a string, try to parse it as JSON
                    if (typeof resp === 'string') {
                        try {
                            resp = JSON.parse(resp);
                        } catch (e) {
                            console.error('Failed to parse response as JSON:', e);
                            throw new Error('Invalid server response format');
                        }
                    }
                    
                    if (resp && resp.status === 'success') {
                        var successMsg = resp.msg || (ids.length > 1 ? 
                            'Students deleted successfully' : 'Student deleted successfully');
                        
                        console.log('Success:', successMsg);
                        alert_toast(successMsg, 'success');
                        
                        // Refresh the student list without reloading the page
                        setTimeout(function() {
                            console.log('Refreshing student list after successful deletion...');
                            refreshStudentList();
                            end_loader();
                            
                            // Call the callback with success
                            if (typeof callback === 'function') {
                                callback(resp);
                            }
                        }, 500);
                    } else {
                        var errorMsg = (resp && (resp.msg || resp.error)) || 'An unknown error occurred';
                        console.error('Deletion failed:', errorMsg);
                        alert_toast('Error: ' + errorMsg, 'error');
                        end_loader();
                        
                        // Call the callback with error
                        if (typeof callback === 'function') {
                            callback({ 
                                status: 'error', 
                                msg: errorMsg,
                                response: resp
                            });
                        }
                    }
                } catch (e) {
                    var errorMsg = 'An error occurred while processing the response: ' + e.message;
                    console.error('Error in success handler:', e, 'Response:', resp);
                    alert_toast(errorMsg, 'error');
                    end_loader();
                    
                    // Call the callback with error
                    if (typeof callback === 'function') {
                        callback({ 
                            status: 'error', 
                            msg: errorMsg,
                            error: e.toString()
                        });
                    }
                }
            }
        });
    }

    // Print QR codes for selected section
    function printQRCodes() {
        var section = (window.StudentDir ? StudentDir.sectionParam() : '');
        if (!section) {
            alert_toast('Please select a section first to generate QR codes.', 'warning');
            return;
        }
        
        // Show loading
        start_loader();
        
        // Get students in the selected section
        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'get_students_for_qr',
                section: section
            },
            dataType: 'json',
            timeout: 10000, // 10 seconds timeout
            error: function(xhr, status, error) {
                console.error('AJAX Error Details:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    statusCode: xhr.status
                });
                end_loader();
                
                let errorMsg = 'Error loading students. Please try again.';
                if (xhr.status === 404) {
                    errorMsg = 'Action not found. Please check the server configuration.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error occurred. Please check the logs.';
                } else if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                }
                
                alert_toast(errorMsg, 'error');
            },
            success: function(resp) {
                console.log('AJAX Response:', resp);
                end_loader();
                
                if (!resp) {
                    alert_toast('No response from server', 'error');
                    return;
                }
                
                if (resp.status === 'success') {
                    if (!resp.data || resp.data.length === 0) {
                        alert_toast('No students found in the selected section.', 'info');
                        return;
                    }
                    
                    console.log('Students data:', resp.data);
                    
                    // Open print window with QR codes
                    var printWindow = window.open('', '_blank', 'width=1200,height=800,scrollbars=yes');
                    
                    if (!printWindow) {
                        alert_toast('Please allow popups for this website to print QR codes.', 'warning');
                        return;
                    }
                    
                    // Create HTML for print page
                    var html = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>QR Codes - ${section}</title>
                        <style>
                            @page { 
                                size: auto; 
                                margin: 10mm;
                            }
                            body { 
                                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                                padding: 20px;
                                background-color: #f8f9fa;
                            }
                            .header { 
                                text-align: center; 
                                margin-bottom: 20px;
                                padding: 15px;
                                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                                border-radius: 8px;
                            }
                            .header h2 { 
                                margin: 0;
                                color: #0d6efd;
                                font-weight: 600;
                            }
                            .header p { 
                                margin: 5px 0 0; 
                                color: #6c757d;
                                font-size: 0.9em;
                            }
                            .qr-container { 
                                display: grid; 
                                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); 
                                gap: 15px; 
                                margin: 20px 0;
                            }
                            .qr-item { 
                                background: #e8e8e8ff;
                                border-radius: 10px;
                                padding: 15px;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
                                transition: transform 0.2s, box-shadow 0.2s;
                                break-inside: avoid;
                                border: 5px solid darkgreen;
                                margin-bottom: 10px;
                                display: flex;
                                align-items: flex-start;
                            }
                            .qr-item:hover {
                                transform: translateY(-2px);
                                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                            }
                            .qr-item img { 
                                max-width: 100px; 
                                height: auto; 
                                margin-bottom: 5px;
                            }
                            .student-name { 
                                font-weight: bold; 
                                margin: 5px 0;
                                font-size: 14px;
                            }
                            .student-id { 
                                color: #666; 
                                font-size: 12px;
                                margin-bottom: 5px;
                            }
                            .no-print { display: none; }
                            @media print {
                                .no-print { display: none; }
                                .print-button { display: none; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h2>${section}</h2>
                            <p>Student QR Codes</p>
                            <p>Generated on: ${new Date().toLocaleDateString()}</p>
                            <button class="print-button no-print" onclick="window.print()" style="margin: 10px 0; padding: 8px 15px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                Print QR Codes
                            </button>
                        </div>
                        <div class="qr-container">
                    `;
                    
                    // Add QR codes to HTML with cache buster to prevent caching
                    resp.data.forEach(function(student) {
                        var cacheBuster = '&_=' + new Date().getTime();
                        var qrUrl = `generate_qrcode.php?student_id=${student.student_id}${cacheBuster}`;
                        html += `
                            <div class="qr-item d-flex align-items-start p-3">
                                <!-- QR Code Column -->
                                <div class="position-relative me-3" style="flex-shrink: 0; width: 110px;">
                                    <img src="${qrUrl}" 
                                         alt="QR Code" 
                                         class="img-fluid"
                                         style="width: 100%; height: auto; aspect-ratio: 1/1; object-fit: cover; border: 4px solid white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                </div>
                                
                                <!-- Text Content Column -->
                                <div class="text-start" style="flex: 1; min-width: 0; padding-left: 5px;">
                                    <div class="institution-name small fw-bold mb-1" style="color: #0d6efd; font-size: 0.7rem; white-space: wrap; overflow: hidden; text-overflow: ellipsis;">EASTERN SAMAR STATE UNIVERSITY</div>
                                    <div class="campus-name small text-uppercase mb-2" style="color: #198754; font-size: 0.6rem; letter-spacing: 0.5px;">Can-avid Campus</div>
                                    <div class="student-name fw-bold" style="font-size: 0.9rem; white-space: wrap; overflow: hidden; text-overflow: ellipsis;">${student.name || 'N/A'}</div>
                                        
                                    <span class="position-absolute top-0 start-50 translate-middle badge bg-primary rounded-pill shadow-sm" style="font-size: 0.6rem; white-space: nowrap; transform: translateX(-50%);">
                                            <i class="fas fa-id-badge me-1"></i>${student.student_number || student.student_id || 'N/A'}
                                        </span>
                                    <div class="student-section small text-muted" style="font-weight:bold;">${student.section || 'No Section'}</div>
                                </div>
                            </div>
                            `;
                    });
                    
                    html += `
                        </div>
                 <!--       <script>
                            // Auto-print when loaded
                            window.onload = function() {
                                setTimeout(function() {
                                    window.print();
                                    // Close the window after printing
                                    setTimeout(function() {
                                        window.close();
                                    }, 1000);
                                }, 500);
                            };
                            
                            // Handle print dialog close
                            window.onafterprint = function() {
                                window.close();
                            };
                        <\/script> -->
                    </body>
                    </html>`;
                    
                    // Write HTML to print window
                    printWindow.document.open();
                    printWindow.document.write(html);
                    printWindow.document.close();
                    
                } else {
                    alert_toast(resp.msg || 'Error loading students', 'error');
                }
            }
        });
    }
    
    // Print Class Information function
    function printClassInfo() {
        var section = (window.StudentDir ? StudentDir.sectionParam() : '');
        if (!section) {
            alert_toast('Please select a section first to print class information.', 'warning');
            return;
        }
        
        // Show loading
        start_loader();
        
        // Get class information and students for the selected section
        $.ajax({
            url: './Actions.php',
            method: 'POST',
            data: {
                a: 'get_class_info_for_print',
                section: section
            },
            dataType: 'json',
            timeout: 10000,
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                end_loader();
                alert_toast('Error loading class information. Please try again.', 'error');
            },
            success: function(resp) {
                end_loader();
                
                if (resp.status === 'success') {
                    if (!resp.data || resp.data.length === 0) {
                        alert_toast('No class information found for the selected section.', 'info');
                        return;
                    }
                    
                    // Open print window
                    var printWindow = window.open('', '_blank', 'width=1200,height=800,scrollbars=yes');
                    
                    if (!printWindow) {
                        alert_toast('Please allow popups for this website to print class information.', 'warning');
                        return;
                    }
                    
                    // Create HTML for print page
                    var grade = resp.data && resp.data.length > 0 ? resp.data[0].grade : '';
                    var gender = resp.data && resp.data.length > 0 ? resp.data[0].gender : '';
                    var html = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Class List of ${grade} - ${section}</title>
                        <style>
                            @page { 
                                size: auto; 
                                margin-top: 0;
                                margin-bottom:0.5cm;
                                margin-left:0.5cm;
                                margin-right:0.5cm;
                            }
                            body { 
                                font-family: Arial, sans-serif;
                                padding: 20px;
                                background-color: #ffffff;
                            }
                            .print-header { 
                                text-align: center; 
                                font-size:12px;
                            }
                           
                           
                            .header-images {
                                display: flex;
                                justify-content: space-between;
                                align-items: flex-start;
                                margin-bottom: 20px;
                                min-height: 80px;
                                padding: 0;
                                position: relative;
                            }
                            .header-image {
                                
                                position: absolute;
                                top: 0;
                            }
                            .header-image.left {
                                height: 80px;
                                width: 275px;
                                left: 0;
                            }
                            .header-image.right {
                                max-height: 80px;
                                max-width: 200px;
                                right: 0;
                            }
                            .header-image.center {
                                left: 50%;
                                transform: translateX(-50%);
                            }
                            
                            .class-info-table {
                                width: 100%;
                                border-collapse: collapse;
                                margin-bottom: 30px;
                            }
                            .class-info-table th, .class-info-table td {
                                padding: 5px;
                                text-align: left;
                            }
                            .class-info-table th {
                                background-color: #f8f9fa;
                                font-weight: bold;
                                width: 20%;
                            }
                            .student-table {
                                width: 100%;
                                border-collapse: collapse;
                                margin-top: 20px;
                            }
                            .student-table th, .student-table td {
                                padding: 5px;
                                text-align: left;
                            }
                            .student-table th {
                                background-color: #f8f9fa;
                                font-weight: bold;
                            }
                            .student-table tbody tr:nth-child(even) {
                                background-color: #f9f9f9;
                            }
                            .print-date {
                                text-align: right;
                                margin-bottom: 20px;
                                color: #666;
                                font-size: 14px;
                            }
                           
                            .no-print { display: none; }
                            @media print {
                                .no-print { display: none; }
                                .print-button { display: none; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="header-images">
                    `;
                    
                    // Add header images from settings
                    html += `<img src="uploads/EssuLogo.jpg" class="header-image left" alt="Header Left">`;
                    html += `<img src="uploads/Bagong Pilipinas.png" class="header-image right" alt="Header right">`;
                    
                    html += `
                        </div>
                        
                        <div class="print-header">
                           <p>Republic of the Philippines <br> Commission on Higher Education <br> <strong>Eastern Samar State University Can-avid</strong></p>
                        </div>
                        
                    `;
                    
                    // Add class information table
                    if (resp.data && resp.data.length > 0) {
                        var classInfo = resp.data[0]; // All students in same section have same class info
                        
                        html += `
                            <div class="section-title" style="border-bottom: 2px solid #333; padding-bottom: 5px; margin-bottom: 20px;"></div>
                            <table class="class-info-table" style="font-size:12px;">
                                <tr>
                                    <th>Course Code:</th>
                                    <td>${classInfo.course_no || 'N/A'}</td>
                                    <th>Semester:</th>
                                    <td>${classInfo.semester || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>Course:</th>
                                    <td>${classInfo.grade || 'N/A'}</td>
                                    <th>School Year:</th>
                                    <td>${classInfo.year || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>Section:</th>
                                    <td>${classInfo.section || 'N/A'}</td>
                                    <th>Schedule:</th>
                                    <td>${classInfo.schedule || 'N/A'}</td>
                                    
                                </tr>
                                <tr>
                                    <th>Units:</th>
                                    <td>${classInfo.unit || 'N/A'}</td>
                                    <th>Instructor:</th>
                                    <td>${resp.instructor || 'N/A'}</td>
                                </tr>
                            </table><ta
                        `;
                        
                        // Add student list
                        html += `
                        
                            <div class="section-title" style="font-size:14px;">Student's List</div>
                            <table class="student-table" style="font-size:11px;">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student Number</th>
                                        <th>Name</th>
                                        <th>Gender</th>
                                    </tr>
                                </thead>
                                <tbody>
                        `;
                        
                        resp.data.forEach(function(student, index) {
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td>${student.student_number || 'N/A'}</td>
                                    <td>${student.name || 'N/A'}</td>
                                    <td>${student.gender || 'N/A'}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                        
                                </tbody>
                            </table>
                        `;
                    }
                    
                    // Add footer images from settings
                    if (resp.header_settings) {
                        html += `<div class="footer-images" style="margin-top: 30px; display: flex; justify-content: space-between; align-items: center;">`;
                        if (resp.header_settings.footer.left) {
                            html += `<img src="${resp.header_settings.footer.left}" class="footer-image" style="max-height: 60px; max-width: 150px;" alt="Footer Left">`;
                        }
                        if (resp.header_settings.footer.center) {
                            html += `<img src="${resp.header_settings.footer.center}" class="footer-image" style="max-height: 60px; max-width: 150px;" alt="Footer Center">`;
                        }
                        if (resp.header_settings.footer.right) {
                            html += `<img src="${resp.header_settings.footer.right}" class="footer-image" style="max-height: 60px; max-width: 150px;" alt="Footer Right">`;
                        }
                        html += `</div>`;
                    }
                    
                    html += `
                        
                    </body>
                    </html>`;
                    
                    // Write HTML to print window
                    printWindow.document.open();
                    printWindow.document.write(html);
                    printWindow.document.close();
                    
                } else {
                    alert_toast(resp.msg || 'Error loading class information', 'error');
                }
            }
        });
    }
    
    // Delete multiple students
    function delete_multiple_students(ids) {
        delete_data(ids);
    }

    // Clickable status badge -> change a student's status from the card
    $(document).on('click', '.stu-status-opt', function(e){
        e.stopPropagation();
        var id = $(this).data('id');
        var st = $(this).data('status');
        var toggle = $(this).closest('.dropdown').find('[data-bs-toggle="dropdown"]')[0];
        if(window.bootstrap && toggle){
            var dd = bootstrap.Dropdown.getInstance(toggle);
            if(dd) dd.hide();
        }
        if(st === 'NoSub' || st === 'NoExam'){
            showQuarterModal(id, st);
        } else {
            setStudentStatus(id, st, null);
        }
    });

    function setStudentStatus(studentId, status, quarter){
        start_loader();
        var payload = { id: studentId, status: status };
        if(quarter) payload.quarter = quarter;
        $.ajax({
            url: './Actions.php?a=update_student_status',
            method: 'POST',
            data: payload,
            dataType: 'json',
            error: function(xhr){
                console.error(xhr);
                alert_toast('An error occurred while updating status', 'error');
                end_loader();
            },
            success: function(resp){
                if(resp && resp.status === 'success'){
                    alert_toast(resp.msg || 'Status updated', 'success');
                    if(window.StudentDir) StudentDir.setStatus(studentId, status);
                } else {
                    alert_toast((resp && (resp.msg || resp.error)) || 'Failed to update status', 'error');
                }
                end_loader();
            }
        });
    }

    function showQuarterModal(studentId, status){
        if(!window.bootstrap){
            alert_toast('UI library still loading, please try again.', 'warning');
            return;
        }
        var old = document.getElementById('quarterModal');
        if(old) old.remove();
        var title = status === 'NoSub' ? 'No Submissions' : 'No Exam';
        var modalHtml =
            '<div class="modal fade" id="quarterModal" tabindex="-1" aria-hidden="true">' +
                '<div class="modal-dialog modal-dialog-centered">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header">' +
                            '<h5 class="modal-title">Select Quarter for ' + title + '</h5>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<p class="small text-muted">Please select which quarter this status applies to:</p>' +
                            '<div class="form-check"><input class="form-check-input" type="radio" name="quarter" id="quarter1" value="1"><label class="form-check-label" for="quarter1">Prelim</label></div>' +
                            '<div class="form-check"><input class="form-check-input" type="radio" name="quarter" id="quarter2" value="2"><label class="form-check-label" for="quarter2">Midterm</label></div>' +
                            '<div class="form-check"><input class="form-check-input" type="radio" name="quarter" id="quarter3" value="3"><label class="form-check-label" for="quarter3">Pre-Final</label></div>' +
                            '<div class="form-check"><input class="form-check-input" type="radio" name="quarter" id="quarter4" value="4"><label class="form-check-label" for="quarter4">Final</label></div>' +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>' +
                            '<button type="button" class="btn btn-primary btn-sm" id="quarterConfirmBtn">Confirm</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        window.quarterModalData = { studentId: studentId, status: status };
        bootstrap.Modal.getOrCreateInstance(document.getElementById('quarterModal')).show();
    }
    $(document).on('click', '#quarterConfirmBtn', function(){
        var quarter = $('input[name="quarter"]:checked').val();
        if(!quarter){
            alert_toast('Please select a quarter before confirming.', 'warning');
            return;
        }
        var d = window.quarterModalData || {};
        var m = document.getElementById('quarterModal');
        if(m) bootstrap.Modal.getOrCreateInstance(m).hide();
        setStudentStatus(d.studentId, d.status, quarter);
    });

    // Function to refresh the student directory without reloading the page
    // (keeps this name: manage_student.php + realtime listeners call it)
    function refreshStudentList() {
        if(window.StudentDir){
            StudentDir.refresh();
        } else {
            window.location.reload();
        }
    }

    // Open a modal and optionally activate one of its tabs (e.g. 'bulk')
    window.openStudentModal = function(title, url, tab){
        uni_modal(title, url);
        if(tab){
            var tries = 0;
            var timer = setInterval(function(){
                tries++;
                var $t = $('#uni_modal #' + tab + '-tab');
                if($t.length){ $t.trigger('click'); clearInterval(timer); }
                else if(tries > 25){ clearInterval(timer); }
            }, 200);
        }
    };

    // Shared delete confirmation (single or bulk) -> runs delete_data, then onDone
    window.confirmDeleteStudents = function(ids, names, onDone){
        var message = 'Are you sure you want to delete the following student(s)?<br><br>' +
                      '<ul>' + names.map(function(name) {
                          return '<li>' + $('<div>').text(name).html() + '</li>';
                      }).join('') + '</ul>';

        var modal = $('<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog">' +
                    '<div class="modal-dialog" role="document">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title">Confirm Deletion</h5>' +
                    '<button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span>' +
                    '</button>' +
                    '</div>' +
                    '<div class="modal-body">' + message + '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>' +
                    '</div></div></div></div>');

        $('body').append(modal);
        modal.modal('show');

        modal.find('#confirmDeleteBtn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...');

            delete_data(ids, function(response) {
                $btn.prop('disabled', false).text('Delete');
                modal.modal('hide');

                try {
                    var resp = typeof response === 'string' ? JSON.parse(response) : response;
                    if (resp && resp.status === 'success') {
                        if (typeof onDone === 'function') onDone(resp);
                    } else if (resp && resp.msg && resp.status !== 'cancelled') {
                        alert_toast(resp.msg, 'error');
                    }
                } catch (e) {
                    alert_toast('An error occurred while processing the response', 'error');
                }
            });
        });

        modal.on('hidden.bs.modal', function() {
            modal.remove();
        });
    };

    // (refreshStudentList is defined above — folder-aware version)

    // Function to show notification when table is updated
    function showUpdateNotification(studentData) {
        // Create notification element
        var notification = $('<div class="alert alert-success alert-dismissible fade show" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideInRight 0.3s ease-out;">' +
            '<i class="fas fa-user-plus me-2"></i>' +
            '<strong>New Student Registered!</strong><br>' +
            '<small>' + (studentData.name || 'Unknown') + ' (' + (studentData.student_number || 'Unknown') + ')</small>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>');
        
        // Add to body
        $('body').append(notification);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            notification.fadeOut(500, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Listen for student registration notifications
    function listenForStudentUpdates() {
        // Listen for localStorage changes
        $(window).on('storage', function(e) {
            if (e.key === 'student_registered') {
                try {
                    var notification = JSON.parse(e.originalEvent.newValue);
                    if (notification.type === 'student_registered') {
                        refreshStudentList();
                        showUpdateNotification(notification.data);
                    }
                } catch (error) {
                    console.error('Error parsing notification:', error);
                }
            }
        });

        // Listen for BroadcastChannel messages
        if ('BroadcastChannel' in window) {
            var channel = new BroadcastChannel('student_updates');
            channel.onmessage = function(e) {
                if (e.data.type === 'student_registered') {
                    refreshStudentList();
                    showUpdateNotification(e.data.data);
                }
            };
        }

        // Check for any missed notifications on page load
        var missedNotification = localStorage.getItem('student_registered');
        if (missedNotification) {
            try {
                var notification = JSON.parse(missedNotification);
                if (notification.type === 'student_registered' && (Date.now() - notification.timestamp) < 10000) {
                    refreshStudentList();
                    showUpdateNotification(notification.data);
                }
                localStorage.removeItem('student_registered');
            } catch (error) {
                console.error('Error parsing missed notification:', error);
            }
        }
    }

    // Initialize the listener
    listenForStudentUpdates();

    // Add CSS animation for slide-in effect
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `)
        .appendTo('head');
</script>


<!-- Irregular Subjects Modal -->
<div class="modal fade" id="irregularModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-random me-2 text-warning"></i><span id="irregularModalTitle">Irregular Subjects</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="irregularModalBody"><div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Loading...</span></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>