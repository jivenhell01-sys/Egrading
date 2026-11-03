<?php
// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');
// Initialize variables
$is_attendance = false;
if (isset($_GET['assessment_id'])) {
    $assessment = $conn->querySingle("SELECT * FROM assessment_list WHERE assessment_id = '{$_GET['assessment_id']}'", true);
    $is_attendance = ($assessment && stripos($assessment['name'], 'attendance') !== false);
}
?>
<!-- SheetJS (920KB) lazy-loaded on demand via ensureXLSXLoaded() below — not render-blocking -->
<style>
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
    .quarter-create-tab {
        width: 42px;
        min-width: 42px;
        border: 0;
        border-bottom: 2px solid transparent;
        border-radius: 0;
        background: transparent;
        color: var(--horizon-brand-500);
        text-align: center;
        font-weight: 700;
        transition: width 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .quarter-create-tab:focus {
        width: 180px;
        border-bottom-color: var(--horizon-brand-500);
        outline: none;
        box-shadow: none;
    }

    .quarter-create-tab::placeholder {
        color: var(--horizon-brand-500);
        opacity: 1;
    }

    .quarter-create-tab:hover {
        border-bottom-color: var(--horizon-brand-500);
    }

    .quarter-edit-tab {
        width: 150px;
        min-width: 150px;
        height: auto;
        border: 0;
        border-bottom: 2px solid var(--horizon-brand-500);
        border-radius: 0;
        background: transparent;
        color: inherit;
        text-align: center;
        font-weight: 700;
        box-shadow: none;
        padding-left: 0.25rem;
        padding-right: 0.25rem;
    }

    .quarter-edit-tab:focus {
        outline: none;
        box-shadow: none;
        border-bottom-color: var(--horizon-brand-500);
    }

    .assessment-create-tab {
        width: 42px;
        min-width: 42px;
        border: 0;
        border-bottom: 2px solid transparent;
        border-radius: 0;
        background: transparent;
        color: var(--horizon-brand-500);
        text-align: center;
        font-weight: 700;
        transition: width 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .assessment-create-tab:focus,
    .assessment-create-tab:hover {
        width: 180px;
        border-bottom-color: var(--horizon-brand-500);
        outline: none;
        box-shadow: none;
    }

    .assessment-create-tab::placeholder {
        color: var(--horizon-brand-500);
        opacity: 1;
    }

    .assessment-create-form {
        position: absolute;
        z-index: 1;
        left: 0;
        right: 0;
        padding: 0.5rem;
        background: var(--horizon-card-bg);
        border: 1px solid var(--horizon-card-border);
        border-radius: var(--horizon-radius-sm);
        box-shadow: var(--horizon-card-shadow);
    }

    .assessment-tabs-container {
        position: relative;
    }

    .assessment-tab-wrapper {
        position: relative;
        display: inline-block;
    }

    .marks-assessment-tab {
        position: relative;
    }

    .assessment-tab-wrapper .delete-assessment-btn {
        position: absolute;
        top: -8px;
        right: -8px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background-color: #dc3545;
        color: white;
        border: 2px solid white;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        cursor: pointer;
        z-index: 10;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .quarter-tab-wrapper {
        position: relative;
        display: inline-block;
    }

    .marks-quarter-tab {
        position: relative;
    }

    .quarter-tab-wrapper .delete-quarter-btn {
        position: absolute;
        top: -8px;
        right: -8px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background-color: #dc3545;
        color: white;
        border: 2px solid white;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        cursor: pointer;
        z-index: 10;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .assessment-tab-wrapper .delete-assessment-btn:hover {
        background-color: #c82333;
        transform: scale(1.1);
    }

    .quarter-tab-wrapper .delete-quarter-btn:hover {
        background-color: #c82333;
        transform: scale(1.1);
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
 font-size: 1rem;
 color: white;
 background: hsl(345deg 100% 47%);
 will-change: transform;
 transform: translateY(-4px);
 transition: transform
    600ms
    cubic-bezier(.3, .7, .4, 1);
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

 /* Toggle button for Enter behavior */
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

 .toggle-enter-switch.active {
  background: #198754;
}

.toggle-enter-switch.active .toggle-enter-knob {
  transform: translateX(16px);
}

 .toggle-enter-text {
  font-size: 0.75rem;
  margin-left: 6px;
  font-weight: 600;
 }

 .toggle-enter-btn.on .toggle-enter-switch {
  background: #0d55fdff;
  color: #ffffff;
 }

 .toggle-enter-btn.on .toggle-enter-knob {
  transform: translateX(16px);
 }

/* Mobile-specific styles */
@media (max-width: 768px) {
  .table-responsive {
    padding: 0.5rem !important;
  }

  #marks-table {
    min-width: 320px !important;
    font-size: 0.85rem;
  }

  #marks-table th:nth-child(1) {
    min-width: 150px !important;
    width: 150px !important;
  }

  #marks-table th {
    padding: 0.5rem 0.25rem !important;
    font-size: 0.75rem;
  }

  #marks-table td {
    padding: 0.5rem 0.25rem !important;
  }

  .avatar-sm {
    width: 24px !important;
    height: 24px !important;
  }

  .avatar-sm i {
    font-size: 10px !important;
  }

  .mark-input {
    font-size: 12px !important;
    padding: 0.25rem !important;
  }

  .input-group-text {
    font-size: 10px !important;
    padding: 0.25rem !important;
  }

  .badge {
    font-size: 9px !important;
    padding: 0.2em 0.4em !important;
  }

  h6.small {
    font-size: 0.8rem !important;
    line-height: 1.2;
  }

  .text-muted[style*="font-size: 11px"] {
    font-size: 0.75rem !important;
  }
}

@media (max-width: 480px) {
  #marks-table {
    min-width: 280px !important;
    font-size: 0.8rem;
  }

  #marks-table th:nth-child(1) {
    min-width: 120px !important;
    width: 120px !important;
  }

  #marks-table th {
    padding: 0.4rem 0.2rem !important;
    font-size: 0.7rem;
  }

  #marks-table td {
    padding: 0.4rem 0.2rem !important;
  }

  .avatar-sm {
    width: 20px !important;
    height: 20px !important;
  }

  .avatar-sm i {
    font-size: 8px !important;
  }

  .mark-input {
    font-size: 11px !important;
    padding: 0.2rem !important;
  }

  .input-group-text {
    font-size: 9px !important;
    padding: 0.2rem !important;
  }

  .badge {
    font-size: 8px !important;
    padding: 0.15em 0.3em !important;
  }

  h6.small {
    font-size: 0.75rem !important;
  }

  .text-muted[style*="font-size: 11px"] {
    font-size: 0.7rem !important;
  }
}

/* Status + attendance row coloring (applied to cells: Bootstrap paints td backgrounds, so tr-level color is hidden) */
#marks-table tbody tr.status-dropped > td {
    background-color: rgba(220, 53, 69, 0.07) !important;
    box-shadow: none !important;
}

#marks-table tbody tr.status-nosub > td {
    background-color: rgba(255, 165, 0, 0.07) !important;
    box-shadow: none !important;
}

#marks-table tbody tr.status-noexam > td {
    background-color: rgba(0, 255, 255, 0.07) !important;
    box-shadow: none !important;
}

/* Attendance is shown as a per-row badge in the Attendance column, not a row tint */

/* Ensure tinted colors remain on hover */
#marks-table.table-hover tbody tr.status-dropped:hover > td {
    background-color: rgba(220, 53, 69, 0.07) !important;
    box-shadow: none !important;
}
#marks-table.table-hover tbody tr.status-nosub:hover > td {
    background-color: rgba(255, 165, 0, 0.07) !important;
    box-shadow: none !important;
}
#marks-table.table-hover tbody tr.status-noexam:hover > td {
    background-color: rgba(0, 255, 255, 0.07) !important;
    box-shadow: none !important;
}

/* Dark mode: assessment + quarter tab fonts — visible neutral text, never blue.
   (On this page the tabs only get Bootstrap's blue .nav-link color, since the
   gray .report-class-tab theme lives in reports_content.php.) */
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #assessmentTabs.report-class-tabs,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #assessmentQuarterTabs.report-class-tabs {
    background: var(--horizon-card-bg, #111c33) !important;
    border-bottom-color: var(--horizon-card-border, #334155) !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .marks-assessment-tab,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .marks-quarter-tab,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .assessment-create-tab,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .quarter-create-tab {
    color: #e2e8f0 !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .marks-assessment-tab:hover,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .marks-quarter-tab:hover {
    color: #ffffff !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .marks-assessment-tab.active,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .marks-quarter-tab.active {
    color: #ffffff !important;
    background: rgba(67, 24, 255, 0.28) !important;
    border-color: var(--horizon-brand-500, #4318FF) !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .assessment-create-tab::placeholder,
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .quarter-create-tab::placeholder {
    color: #94a3b8 !important;
    opacity: 1;
}

/* Professional Assessment Date controls */
.assessment-date-group {
    display: flex;
    align-items: stretch;
    background: #fff;
    border: 1px solid var(--horizon-card-border, #e2e8f0);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.05);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.assessment-date-group:focus-within {
    border-color: var(--horizon-brand-500, #4318FF);
    box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.15);
}
.assessment-date-group #assessmentDate {
    border: 0 !important;
    box-shadow: none !important;
    background: transparent !important;
    font-weight: 600;
    color: #1e293b;
    padding: 0.45rem 0.65rem;
}
.assessment-date-group #assessmentDate:focus {
    outline: none !important;
    box-shadow: none !important;
}
button#saveAssessmentDateBtn.assessment-date-save-btn {
    position: relative;
    display: inline-flex !important;
    align-items: center;
    gap: 0.45rem;
    padding: 0 0.9rem !important;
    min-height: 34px;
    border: 0 !important;
    border-left: 1px solid rgba(255, 255, 255, 0.25) !important;
    border-radius: 0 !important;
    background: var(--horizon-brand-linear, linear-gradient(135deg, #868CFF 0%, #4318FF 100%)) !important;
    color: #fff !important;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    white-space: nowrap;
    cursor: pointer;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
    transition: background 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease !important;
}
button#saveAssessmentDateBtn.assessment-date-save-btn:hover {
    background: var(--horizon-brand-linear-hover, linear-gradient(135deg, #7551FF 0%, #3311DB 100%)) !important;
    filter: none !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 4px 12px rgba(67, 24, 255, 0.35) !important;
}
button#saveAssessmentDateBtn.assessment-date-save-btn:active {
    transform: translateY(1px);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2) !important;
}
button#saveAssessmentDateBtn.assessment-date-save-btn:focus-visible {
    outline: 2px solid var(--horizon-brand-500, #4318FF) !important;
    outline-offset: 2px;
}
button#saveAssessmentDateBtn.assessment-date-save-btn:disabled {
    opacity: 0.75;
    cursor: wait;
    transform: none !important;
}
button#saveAssessmentDateBtn.assessment-date-save-btn .save-btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.22);
    font-size: 10px;
}
button#saveAssessmentDateBtn.assessment-date-save-btn.is-saving {
    pointer-events: none;
}
#dateSaveStatus {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.75rem;
    font-weight: 600;
}
#dateSaveStatus.text-success {
    color: #15803d !important;
}
#dateSaveStatus.text-warning {
    color: #b45309 !important;
}
#dateSaveStatus.text-danger {
    color: #b91c1c !important;
}

/* Dark mode: Assessment Date controls */
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .assessment-date-group {
    background: var(--horizon-input-bg, #202d43) !important;
    border-color: var(--horizon-input-border, #35445e) !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3) !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .assessment-date-group:focus-within {
    border-color: #868CFF !important;
    box-shadow: 0 0 0 3px rgba(134, 140, 255, 0.25) !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .assessment-date-group #assessmentDate {
    color: var(--horizon-input-text, #f1f5ff) !important;
    color-scheme: dark;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) .assessment-date-group #assessmentDate::-webkit-calendar-picker-indicator {
    filter: invert(1) brightness(1.3);
    opacity: 0.85;
    cursor: pointer;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) button#saveAssessmentDateBtn.assessment-date-save-btn {
    border-left-color: rgba(255, 255, 255, 0.2) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2), 0 4px 14px rgba(67, 24, 255, 0.45) !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) button#saveAssessmentDateBtn.assessment-date-save-btn:hover {
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2), 0 6px 18px rgba(134, 140, 255, 0.5) !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #dateSaveStatus.text-success {
    color: #4ade80 !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #dateSaveStatus.text-warning {
    color: #fbbf24 !important;
}
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #dateSaveStatus.text-danger {
    color: #fca5a5 !important;
}

/* =====================================================================
   PAGE SHELL — header, navigation card, action toolbar, side card.
   The marks table below is deliberately untouched.
   ===================================================================== */
:root {
    --mk-navy: #1e293b;
    --mk-slate: #475569;
    --mk-muted: #64748b;
    --mk-line: #e2e8f0;
    --mk-line-strong: #cbd5e1;
    --mk-card-bg: #ffffff;
    --mk-bg-soft: #f8fafc;
    --mk-text: #0f172a;
    --mk-green: #1a4d2e;
    --mk-green-2: #2d5f3f;
    --mk-danger: #b91c1c;
}
/* One dark-mode block re-skins the whole shell. */
:is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) {
    --mk-slate: #c2cee6;
    --mk-muted: #8e9dbc;
    --mk-line: #2b3850;
    --mk-line-strong: #35445e;
    --mk-card-bg: #182235;
    --mk-bg-soft: #141d2e;
    --mk-text: #e5ebfa;
    --mk-danger: #fca5a5;
}

.mk-page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    flex-wrap: wrap;
    padding: 1rem 1.25rem;
    margin-bottom: 0.85rem;
    background: linear-gradient(135deg, var(--mk-navy) 0%, #243449 100%);
    border-radius: 14px;
    box-shadow: 0 10px 24px -12px rgba(15, 23, 42, 0.45);
    position: relative;
    overflow: hidden;
}
.mk-page-head::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: linear-gradient(90deg, #d4af37, #f4e5c2, #d4af37);
    background-size: 200% 100%;
    animation: mkShimmer 3s infinite;
}
@keyframes mkShimmer {
    0% { background-position: -1000px 0; }
    100% { background-position: 1000px 0; }
}
.mk-page-head-main { min-width: 0; }
.mk-page-title {
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
.mk-page-title-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    border-radius: 10px;
    background: linear-gradient(135deg, #d4af37 0%, #f4e5c2 100%);
    color: var(--mk-navy);
    font-size: 0.95rem;
}
.mk-page-sub {
    margin: 0.3rem 0 0 0;
    color: #cbd5e1;
    font-size: 0.78rem;
    letter-spacing: 0.02em;
}
.mk-page-head-class {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 220px;
    max-width: 330px;
    flex: 1 1 220px;
}
.mk-page-head-class .form-select {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-weight: 700;
    color: var(--mk-navy);
}
.mk-field-label {
    display: block;
    margin-bottom: 0.3rem;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--mk-slate);
}
.mk-page-head .mk-field-label { color: #f4e5c2; }
.mk-head-icon {
    background: rgba(255, 255, 255, 0.16);
    border-color: rgba(255, 255, 255, 0.25);
    color: #fff;
}

/* ---- Navigation card: quarter + assessment tabs ---- */
.mk-nav-card {
    padding: 0.85rem 1rem 1rem;
    margin-bottom: 0.85rem;
    background: var(--mk-card-bg);
    border: 1px solid var(--mk-line);
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}
.mk-tab-rows {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.mk-empty-hint {
    margin-top: 0.5rem;
    padding: 0.45rem 0.7rem;
    border: 1px solid var(--mk-line);
    border-left: 3px solid #f59e0b;
    border-radius: 10px;
    background: #fffbeb;
    color: #92400e;
    font-size: 0.75rem;
    font-weight: 600;
}

/* ---- Action toolbar ---- */
.mk-entry-card {
    background: var(--mk-card-bg);
    border: 1px solid var(--mk-line) !important;
}
.mk-toolbar {
    padding: 0.6rem 0.75rem;
    background: var(--mk-bg-soft);
    border-bottom: 1px solid var(--mk-line);
}
.mk-toolbar-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.mk-tool-group {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    min-width: 0;
}
.mk-tool-group + .mk-tool-group {
    padding-left: 0.6rem;
    border-left: 1px solid var(--mk-line);
}
.mk-tool-right { margin-left: auto; }
.mk-tool-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    height: 34px;
    padding: 0 0.75rem !important;
    border: 1px solid var(--mk-line-strong) !important;
    border-radius: 10px;
    background: var(--mk-card-bg);
    color: var(--mk-text);
    font-size: 0.8rem;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
.mk-tool-btn:hover {
    filter: none;
    border-color: #94a3b8 !important;
    background: var(--mk-bg-soft);
}
.mk-tool-btn-primary,
.mk-tool-btn-primary:hover {
    background: linear-gradient(135deg, var(--mk-green) 0%, var(--mk-green-2) 100%);
    border-color: transparent !important;
    color: #fff;
    box-shadow: 0 4px 12px rgba(26, 77, 46, 0.28);
}
.mk-tool-btn-primary:hover { filter: brightness(1.08); }
.mk-tool-btn-primary::after { border-top-color: #fff !important; }
.mk-tool-btn-ghost {
    border-color: rgba(26, 77, 46, 0.35) !important;
    background: var(--mk-card-bg);
    color: var(--mk-green);
}
.mk-tool-btn-ghost:hover {
    background: var(--mk-green);
    border-color: var(--mk-green) !important;
    color: #fff;
}
.mk-output-menu {
    min-width: 15rem;
    border-radius: 12px;
    padding: 0.35rem;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
}
.mk-output-menu .dropdown-header {
    font-size: 0.66rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--mk-muted);
}
.mk-output-menu .dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-radius: 8px;
    padding: 0.45rem 0.6rem;
    font-size: 0.82rem;
    font-weight: 600;
    white-space: nowrap;
}
.mk-output-menu .dropdown-item i {
    width: 18px;
    text-align: center;
    color: var(--mk-slate);
}
.mk-output-menu .dropdown-item:hover { background: var(--mk-bg-soft); }
.mk-output-menu .mk-danger-item { color: var(--mk-danger); }
.mk-output-menu .mk-danger-item i { color: var(--mk-danger); }
.mk-output-menu .mk-danger-item:hover { background: rgba(220, 53, 69, 0.12); }

/* Add-to-marks mini field */
.mk-add-wrap {
    position: relative;
    display: flex;
    align-items: stretch;
}
.mk-add-wrap .form-control {
    width: 130px;
    height: 34px;
    padding-left: 1.9rem;
    padding-right: 0.35rem;
    border-radius: 10px 0 0 10px;
    font-size: 0.8rem;
    font-weight: 600;
}
.mk-add-icon {
    position: absolute;
    left: 0.6rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--mk-green);
    font-size: 0.75rem;
    pointer-events: none;
    z-index: 3;
}
.mk-add-btn {
    height: 34px;
    padding: 0 0.65rem;
    border-radius: 0 10px 10px 0 !important;
    font-size: 0.78rem;
}

/* ---- Assessment context card ---- */
.mk-side-sticky {
    position: sticky;
    top: 20px;
}
.mk-side-card {
    background: var(--mk-card-bg);
    border: 1px solid var(--mk-line) !important;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}
.mk-side-head {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding-bottom: 0.7rem;
    margin-bottom: 0.2rem;
    border-bottom: 1px solid var(--mk-line);
}
.mk-side-head-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: 11px;
    background: linear-gradient(135deg, var(--mk-green) 0%, var(--mk-green-2) 100%);
    color: #fff;
    font-size: 0.95rem;
}
.mk-side-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--mk-text);
}
.mk-side-sub {
    font-size: 0.72rem;
    color: var(--mk-muted);
}
.mk-side-label {
    margin-top: 0.7rem;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--mk-muted);
}
.mk-side-value {
    font-weight: 700;
    color: var(--mk-text);
    word-break: break-word;
}
.mk-side-hps {
    font-size: 1.6rem;
    font-weight: 700;
    line-height: 1.15;
    color: var(--mk-green);
}
.mk-side-date {
    margin-top: 0.85rem;
    padding: 0.6rem;
    border: 1px solid var(--mk-line);
    border-radius: 12px;
    background: var(--mk-bg-soft);
}
.mk-side-date .mk-side-label { margin-top: 0; }

@media (max-width: 991px) {
    .mk-side-sticky { position: static; }
    .mk-tool-right { margin-left: 0; }
}
@media (max-width: 768px) {
    .mk-tool-group + .mk-tool-group {
        padding-left: 0;
        border-left: 0;
    }
    .mk-tool-btn-label { display: none; }
    .mk-add-wrap .form-control { width: 104px; }
}
</style>
<?php $class = []; ?>
<form action="" id="marks-form">
    <input type="hidden" name="a" value="save_mark">
    <input type="hidden" name="assessment_id" id="form_assessment_id" value="<?php echo isset($_GET['assessment_id']) ? $_GET['assessment_id'] : '' ?>">

    <!-- ===================== PAGE HEADER ===================== -->
    <div class="mk-page-head">
        <div class="mk-page-head-main">
            <h1 class="mk-page-title">
                <span class="mk-page-title-icon"><i class="fas fa-pen-to-square"></i></span>
                Encode Marks
            </h1>
            <p class="mk-page-sub">Score one assessment at a time &middot; every edit saves itself</p>
        </div>
        <div class="mk-page-head-class">
            <label for="class_id" class="mk-field-label"><i class="fas fa-chalkboard me-1"></i>Class</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text mk-head-icon">
                    <i class="fas fa-chalkboard"></i>
                </span>
                <select name="class_id" id="class_id" class="form-select form-select-sm border-start-0 ps-1" required>
                            <option value="" <?php echo (!isset($class_id)) ? 'selected' : '' ?> disabled>Select Class</option>
                            <?php
                            $class = [];
                            $class_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class FROM `class_list` c inner join `subjects` s on c.subject_id = s.subject_id order by `class` asc");
                            while($row = $class_qry->fetchArray()):
                                $class[$row['class_id']] = $row['class'];
                            ?>
                                <option value="<?php echo $row['class_id'] ?>" style="font-weight:bold;"
                                    <?php echo isset($_GET['class_id']) && $_GET['class_id'] == $row['class_id'] ? "selected" : '' ?>>
                                    <?php echo htmlspecialchars($row['class']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
            </div>
        </div>
    </div>

    <div class="col-12">
                <?php
                $selected_class_for_quarters = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
                    $quarter_names = [];
                    if ($selected_class_for_quarters > 0) {
                        $quarter_query = $conn->query("SELECT quarter, name FROM quarter_list WHERE class_id = '{$selected_class_for_quarters}' ORDER BY quarter ASC");
                        while ($quarter_row = $quarter_query->fetchArray(SQLITE3_ASSOC)) {
                            $quarter_names[(int)$quarter_row['quarter']] = $quarter_row['name'];
                        }
                    }
                    $assess = [];
                    $assess_quarter = [];
                    $assessment_tabs = [];
                    
                    // First, fetch all quarters for the selected class
                    $all_quarters = [];
                    if (!empty($_GET['class_id'])) {
                        $quarter_qry = $conn->query("SELECT * FROM quarter_list WHERE class_id = '{$_GET['class_id']}' ORDER BY quarter ASC");
                        while($q_row = $quarter_qry->fetchArray(SQLITE3_ASSOC)) {
                            $all_quarters[(int)$q_row['quarter']] = $q_row['name'] ?: ('Quarter ' . $q_row['quarter']);
                        }
                    }
                    
                    // Then fetch assessments
                    $dept_qry = $conn->query("SELECT a.*, gc.name as component_name, q.name as quarter_name
                        FROM assessment_list a
                        INNER JOIN grading_components gc ON a.component_id = gc.component_id
                        LEFT JOIN quarter_list q ON q.class_id = a.class_id AND q.quarter = a.quarter
                        ORDER BY a.quarter ASC, a.name ASC");
                    while($row = $dept_qry->fetchArray(SQLITE3_ASSOC)):
                        $assessment_id_value = (int)$row['assessment_id'];
                        $class_id_value = (int)$row['class_id'];
                        $quarter_value = (int)$row['quarter'];
                        $assess_quarter[$assessment_id_value] = $row['quarter_name'] ?: ('Quarter ' . $quarter_value);
                        $assess[$assessment_id_value] = $row;

                        if (!isset($assessment_tabs[$class_id_value])) {
                            $assessment_tabs[$class_id_value] = [];
                        }
                        if (!isset($assessment_tabs[$class_id_value][$quarter_value])) {
                            $assessment_tabs[$class_id_value][$quarter_value] = [];
                        }

                        $assessment_tabs[$class_id_value][$quarter_value][] = [
                            'assessment_id' => $assessment_id_value,
                            'name' => $row['name'],
                            'component_name' => $row['component_name'],
                            'quarter' => $quarter_value,
                            'percentage' => $row['percentage'],
                            'hps' => $row['hps']
                        ];
                    endwhile;
                    
                    // Use all quarters from quarter_list, not just those with assessments
                    $quarter_names = $all_quarters;

                    $selected_assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
                    $selected_assessment_quarter = ($selected_assessment_id > 0 && isset($assess[$selected_assessment_id]))
                        ? (int)$assess[$selected_assessment_id]['quarter']
                        : (isset($_GET['quarter']) ? (int)$_GET['quarter'] : 0);
                    ?>
                    <input type="hidden" name="assessment_id" id="assessment_id" value="<?php echo $selected_assessment_id ?: '' ?>">
                    <input type="hidden" id="assessment_quarter" value="<?php echo $selected_assessment_quarter ?: '' ?>">

                    <!-- ===================== NAVIGATION CARD ===================== -->
                    <div class="mk-nav-card">
                        <div class="mk-tab-rows">
                    <div class="marks-tab-panel d-none" id="assessmentQuarterTabsWrapper">
                        <label class="mk-field-label"><i class="fas fa-calendar-alt me-1"></i>Quarter</label>
                        <div id="assessmentQuarterTabs" class="nav nav-tabs border-bottom-0 report-class-tabs">
                            <?php foreach($quarter_names as $q => $quarter_name): ?>
                                <div class="quarter-tab-wrapper">
                                    <button
                                        type="button"
                                        class="nav-link report-class-tab marks-quarter-tab <?php echo ($selected_assessment_quarter === $q) ? 'active' : '' ?>"
                                        data-quarter="<?php echo $q ?>"
                                    >
                                        <?php echo htmlspecialchars($quarter_name) ?>
                                    </button>
                                    <button type="button" class="delete-quarter-btn" data-quarter="<?php echo $q ?>" title="Delete quarter">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                            <div style="position: relative; display: inline-block;">
                                <input type="text" id="newQuarterName" name="quarter_name" class="quarter-create-tab" placeholder="+" maxlength="80" title="Type a quarter name and press Enter" aria-label="New quarter name" autocomplete="off">
                                <div id="quarterNameDropdown" class="dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; z-index: 1000; min-width: 180px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto;"></div>
                            </div>
                        </div>
                        <div id="assessmentQuarterTabsEmptyState" class="mk-empty-hint d-none mb-0">
                            <i class="fas fa-circle-info me-1"></i>No quarters available for the selected class.
                        </div>
                    </div>

                    <div class="d-none assessment-tabs-container" id="assessmentTabsWrapper">
                        <label class="mk-field-label"><i class="fas fa-list-check me-1"></i>Assessment</label>
                        <div id="assessmentTabs" class="nav nav-tabs border-bottom-0 report-class-tabs">
                        <?php foreach($assessment_tabs as $class_id_value => $quarter_groups): ?>
                            <?php foreach($quarter_groups as $quarter_value => $assessment_group): ?>
                                <?php foreach($assessment_group as $assessment_row): ?>
                                    <div class="assessment-tab-wrapper">
                                        <button
                                            type="button"
                                            class="nav-link report-class-tab marks-assessment-tab <?php echo ($selected_assessment_id === (int)$assessment_row['assessment_id']) ? 'active' : '' ?>"
                                            data-class-id="<?php echo $class_id_value ?>"
                                            data-quarter="<?php echo $quarter_value ?>"
                                            data-assessment-id="<?php echo $assessment_row['assessment_id'] ?>"
                                        >
                                            <?php echo htmlspecialchars($assessment_row['name'] . ' - ' . $assessment_row['component_name'] . ' (' . $assessment_row['percentage'] . '%, HPS: ' . $assessment_row['hps'] . ')') ?>
                                        </button>
                                        <button type="button" class="delete-assessment-btn" data-assessment-id="<?php echo $assessment_row['assessment_id'] ?>" title="Delete assessment">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <input type="text" id="newAssessmentTab" class="assessment-create-tab" placeholder="+" maxlength="120" title="Click to create an assessment" aria-label="New assessment" style="width: 40px; cursor: pointer;">
                    </div>
                    <div id="assessmentCreateFields" class="input-group input-group-sm mt-2 assessment-create-form d-none">
                        <select id="newAssessmentComponent" class="form-select" aria-label="Assessment component">
                            <option value="">Component</option>
                        </select>
                        <input type="text" id="newAssessmentName" class="form-control" placeholder="Assessment name" maxlength="120">
                        <input type="number" id="newAssessmentPercentage" class="form-control" placeholder="Auto %" min="0" max="100" step="0.01" readonly>
                        <input type="number" id="newAssessmentHps" class="form-control" placeholder="HPS" min="0" step="any">
                        <button type="button" id="createAssessment" class="btn btn-primary" title="Create assessment">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                    <div id="assessmentTabsEmptyState" class="mk-empty-hint d-none mb-0">
                        <i class="fas fa-circle-info me-1"></i>No assessments available for the selected class and quarter.
                    </div>
                </div>
                    </div>
    </div>
    <?php if(!empty($_GET['class_id']) && !empty($_GET['assessment_id'])) : ?>
    <div class="row g-3" id="marksWorkspace">
        <!-- Left Side - Assessment Context -->
        <div class="col-lg-4">
            <div class="mk-side-sticky">
                <!-- Assessment Details Card -->
                <div class="card border-0 shadow-sm marks-info-card mk-side-card">
                    <div class="card-body">
                        <div class="mk-side-head">
                            <span class="mk-side-head-icon"><i class="fas fa-clipboard-check"></i></span>
                            <div class="mk-side-head-text">
                                <h5 class="mk-side-title">Assessment Details</h5>
                                <span class="mk-side-sub">HPS &amp; date</span>
                            </div>
                        </div>
                            <div class="mk-side-label">Assessment</div>
                            <div class="mk-side-value" id="mkAssessmentName"><?php echo isset($assess[$_GET['assessment_id']]) ? htmlspecialchars($assess[$_GET['assessment_id']]['name']) : "N/A" ?></div>

                            <div class="mk-side-label">Highest Possible Score</div>
                            <div class="mk-side-hps" id="hps"><?php echo isset($assess[$_GET['assessment_id']]) ? $assess[$_GET['assessment_id']]['hps'] : 0 ?></div>
                        <div class="mk-side-date">
                            <div class="mk-side-label mb-1">Assessment Date</div>
                            <div class="input-group input-group-sm assessment-date-group">
                                <input type="date"
                                       id="assessmentDate"
                                       class="form-control form-control-sm"
                                       name="assessment_date"
                                       value="<?php
                                       $assessment_id = isset($_GET['assessment_id']) ? $_GET['assessment_id'] : '';
                                       if ($assessment_id && isset($assess[$assessment_id]['date']) && $assess[$assessment_id]['date']) {
                                           echo $assess[$assessment_id]['date'];
                                       } else {
                                           echo date('Y-m-d');
                                       }
                                       ?>"
                                       data-assessment-id="<?php echo $assessment_id ?>">
                                <button type="button" id="saveAssessmentDateBtn" class="assessment-date-save-btn" title="Save assessment date">
                                    <span class="save-btn-icon"><i class="fas fa-save"></i></span><span class="save-btn-label">Save</span>
                                </button>
                            </div>
                            <div class="mt-1">
                                <small class="text-success" id="dateSaveStatus" style="display: none;">
                                    <i class="fas fa-check-circle"></i> Date saved automatically
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Right Side - Student Marks Table -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mk-entry-card">
                <!-- ===================== ACTION TOOLBAR =====================
                     Every control for the encoding workspace lives here: entry
                     helpers on the left, bulk tools in the middle, output
                     actions grouped in a dropdown on the right. The marks table
                     below is deliberately untouched. -->
                <div class="mk-toolbar">
                    <div class="mk-toolbar-row">
                        <!-- Entry group -->
                        <div class="mk-tool-group">
                            <button type="button" id="toggleEnterBehavior" class="toggle-enter-btn mk-tool-btn on" aria-pressed="true" title="With Fast Single Entry on, Enter or Tab jumps back to the search box; with it off, Enter walks down the column">
                                <span class="toggle-enter-label">Fast Single Entry</span>
                                <span class="toggle-enter-switch">
                                    <span class="toggle-enter-knob"></span>
                                    <span class="toggle-enter-text"></span>
                                </span>
                            </button>
                        </div>

                        <!-- Bulk group -->
                        <div class="mk-tool-group">
                            <button type="button" class="mk-tool-btn mk-tool-btn-primary" data-bs-toggle="modal" data-bs-target="#bulkEntryModal" title="Import or paste student numbers and marks in bulk">
                                <i class="fas fa-file-import"></i>
                                <span class="mk-tool-btn-label">Bulk Entry</span>
                            </button>
                            <div class="mk-add-wrap">
                                <span class="mk-add-icon" title="Added on top of each student's current score"><i class="fas fa-plus"></i></span>
                                <input type="number" id="addMarksValue" class="form-control form-control-sm" placeholder="Add to marks" min="0" step="0.01" aria-label="Value to add to existing marks">
                                <button type="button" id="applyAddMarks" class="btn btn-sm btn-primary mk-add-btn" title="Add to all existing marks">
                                    <i class="fas fa-check"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Output group -->
                        <div class="mk-tool-group mk-tool-right">
                            <div class="dropdown">
                                <button class="mk-tool-btn mk-tool-btn-ghost dropdown-toggle" type="button"
                                        id="mkOutputBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true" title="Export or reset this assessment's marks">
                                    <i class="fas fa-file-export"></i>
                                    <span class="mk-tool-btn-label">Output</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end mk-output-menu" id="mkOutputMenu" aria-labelledby="mkOutputBtn">
                                    <li><h6 class="dropdown-header">Export</h6></li>
                                    <li>
                                        <button type="button" id="exportMarks" class="dropdown-item" title="Download this assessment's marks as CSV">
                                            <i class="fas fa-file-excel"></i> Export Excel
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><h6 class="dropdown-header">Destructive</h6></li>
                                    <li>
                                        <button type="button" id="clearMarks" class="dropdown-item mk-danger-item" title="Set every score in this assessment back to 0">
                                            <i class="fas fa-undo-alt"></i> Reset All Marks to 0
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive p-3" style="max-height: 60vh; overflow-y: auto;">
                    <table id="marks-table" class="table table-hover align-middle mb-0" style="min-width: 400px;">
                        <thead>
                            <tr class="bg-gradient bg-light marks-table-header">
                                <th class="px-2 py-3 border-0 rounded-start" style="border-top-left-radius: 12px !important;">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-graduate me-2 text-primary"></i>
                                        <span class="text-uppercase text-dark small fw-bold">Student</span>
                                    </div>
                                </th>
                                <th class="px-2 py-3 border-0 text-center" style="width: 180px;">
                                    <div class="d-flex align-items-center justify-content-center">
                                        <i class="fas fa-star-half-alt me-2 text-primary"></i>
                                        <span class="text-uppercase text-dark small fw-bold">Score</span>
                                    </div>
                                </th>
                                <th class="px-2 py-3 border-0 text-center" style="width: 80px;">
                                    <div class="d-flex align-items-center justify-content-center">
                                        <i class="fas fa-percentage me-2 text-primary"></i>
                                        <span class="text-dark small fw-bold">%</span>
                                    </div>
                                </th>
                                <th class="px-2 py-3 border-0 text-center rounded-end" style="width: 110px; border-top-right-radius: 12px !important;">
                                    <div class="d-flex align-items-center justify-content-center">
                                        <i class="fas fa-calendar-check me-2 text-primary"></i>
                                        <span class="text-uppercase text-dark small fw-bold">Attendance</span>
                                    </div>
                                </th>
                            </tr>

                        </thead>
                        <tbody class="border-1">
                            <?php
                                $marks_qry = $conn->query("SELECT * FROM `mark_list` where assessment_id='{$_GET['assessment_id']}'");
                                $marks = array();
                                while($row = $marks_qry->fetchArray()){
                                    $marks[$row['student_id']] = $row['mark'];
                                }
                                $hps = isset($assess[$_GET['assessment_id']]) ? $assess[$_GET['assessment_id']]['hps'] : 0;

                                // Get current date and quarter for attendance checking
                                $current_date = date('Y-m-d');
                                $current_quarter = isset($assess[$_GET['assessment_id']]) ? $assess[$_GET['assessment_id']]['quarter'] : 1;

                                // Get attendance data for all students in this class for current date
                                $attendance_data = array();
                                $attendance_qry = $conn->query("SELECT student_id, status FROM attendance WHERE class_id = '{$_GET['class_id']}' AND date = '$current_date' AND quarter = '$current_quarter'");
                                while($att_row = $attendance_qry->fetchArray()){
                                    $attendance_data[$att_row['student_id']] = $att_row['status'];
                                }

                                $student = $conn->query("SELECT * FROM `student_list` where class_id = '{$_GET['class_id']}' order by `name` asc");
                                $has_students = false;
                                while($row = $student->fetchArray()):
                                    $has_students = true;
                                    // Blank means "not yet graded": a stored NULL or a
                                    // missing row renders as an empty input rather
                                    // than a 0 the teacher has to clear by hand.
                                    $mark_has_value = isset($marks[$row['student_id']]) && $marks[$row['student_id']] !== null;
                                    $mark = $mark_has_value ? $marks[$row['student_id']] : 0;
                                    // Ensure both values are treated as numbers
                                    $mark = is_numeric($mark) ? (float)$mark : 0;
                                    $hps = is_numeric($hps) ? (float)$hps : 0;
                                    $percentage = $hps > 0 ? number_format(($mark / $hps) * 100, 1) : 0;
                                    // Blank score shows an em dash, not 0.0%
                                    $percentage_display = $mark_has_value ? $percentage : '&mdash;';
                                    $percentage_class = $mark_has_value
                                        ? ($percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger'))
                                        : 'secondary';

                                    // Get student status for row coloring
                                    $student_status = isset($row['status']) ? $row['status'] : 'Active';
                                    $status_class = '';
                                    if ($student_status === 'Dropped') {
                                        $status_class = ' status-dropped';
                                    } elseif ($student_status === 'NoSub') {
                                        $status_class = ' status-nosub';
                                    } elseif ($student_status === 'NoExam') {
                                        $status_class = ' status-noexam';
                                    }

                                    $is_dropped = $student_status === 'Dropped';

                                    // Today's attendance status, shown as a badge in the Attendance column
                                    $attendance_status = isset($attendance_data[$row['student_id']]) ? $attendance_data[$row['student_id']] : null;
                            ?>
                            <tr class="position-relative<?php echo $status_class; ?><?php echo $is_dropped ? ' dropped-student-row' : ''; ?>" data-student-id="<?php echo $row['student_id']; ?>" data-student-status="<?php echo htmlspecialchars($student_status); ?>"<?php echo $is_dropped ? ' data-bs-toggle="tooltip" data-bs-placement="bottom" title="This student is dropped. Marks cannot be edited."' : ''; ?>>
                                <td class="px-2 py-2 border-0" >
                                    <div class="d-flex align-items-center" >
                                        <div class="avatar-sm bg-light rounded-circle me-2 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                            <i class="fas fa-user text-muted" style="font-size: 12px;"></i>
                                        </div>
                                        <div class="ms-1" >
                                            <input type="hidden" name="student_id[]" value="<?php echo $row['student_id'] ?>">
                                            <h6 class="mb-0 fw-bold small text-uppercase" ><?php echo htmlspecialchars(mb_strtoupper($row['name'], 'UTF-8')) ?>
                                                <?php if($student_status === 'Dropped'): ?>
                                                    <span class="badge bg-danger" style="font-size: 10px !important;" >!Dropped</span>
                                                <?php elseif($student_status === 'NoSub'): ?>
                                                    <span class="badge bg-warning" style="font-size: 10px !important;" >!No Submissions</span>
                                                <?php elseif($student_status === 'NoExam'): ?>
                                                    <span class="badge bg-warning" style="font-size: 10px !important;" >!No Exam</span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="fw-medium" style="font-size: 11px; color: gold;">ID: <?php echo $row['student_number'] ?></small>
                                            <?php if(!$is_dropped): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 ms-2 quick-scores-btn" title="Compute average of raw scores" data-student-id="<?php echo $row['student_id'] ?>" style="font-size: 11px; line-height: 1.4;">Enter Raw Score<i class="fas fa-plus"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-2 py-2 border-0">
                                    <div class="input-group input-group-sm">
                                        <input type="number"
                                               step="0.01"
                                               class="form-control form-control-sm border-end-0 text-end mark-input"
                                               style="font-weight:bold; font-size: 14px;<?php echo $is_dropped ? ' background-color: #f8d7da; cursor: not-allowed;' : ''; ?>"
                                               name="mark[]"
                                               value="<?php echo $mark_has_value ? htmlspecialchars((string)$mark) : '' ?>"
                                               placeholder="&ndash;"
                                               onfocus="this.select();"
                                               onblur="updatePercentage(this);"
                                               min="0"
                                               max="<?php echo $hps ?>"
                                               data-hps="<?php echo $hps ?>" <?php echo $is_dropped ? 'readonly' : 'required'; ?>>
                                        <span class="input-group-text marks-hps-suffix" style="font-size: 12px; height:32px;">/ <?php echo $hps ?></span>
                                    </div>
                                </td>

                                <td class="px-2 py-2 border-0 text-center">
                                    <span class="badge bg-soft-<?php echo $percentage_class; ?> text-<?php echo $percentage_class; ?> rounded-pill" style="font-size: 11px;">
                                        <?php echo $mark_has_value ? $percentage . '%' : $percentage_display ?>
                                    </span>
                                </td>
                                <td class="px-2 py-2 border-0 text-center">
                                    <?php if($attendance_status === 'present'): ?>
                                        <span class="badge bg-success rounded-pill" style="font-size: 11px;">Present</span>
                                    <?php elseif($attendance_status === 'absent'): ?>
                                        <span class="badge bg-danger rounded-pill" style="font-size: 11px;">Absent</span>
                                    <?php elseif($attendance_status === 'late'): ?>
                                        <span class="badge bg-warning text-dark rounded-pill" style="font-size: 11px;">Late</span>
                                    <?php elseif($attendance_status === 'excused'): ?>
                                        <span class="badge bg-info text-dark rounded-pill" style="font-size: 11px;">Excused</span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 11px;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if(!$has_students): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <div class="d-flex flex-column align-items-center text-muted">
                                        <i class="fas fa-user-graduate fa-2x mb-2"></i>
                                        <div>No students found in this class</div>
                                        <small class="mt-1">Add students to get started</small>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <style>
                /* Style for the tooltip */
                .tooltip-inner {
                    background-color: #dc3545;
                    padding: 0.5rem 1rem;
                    font-size: 0.875rem;
                }
                .bs-tooltip-bottom .tooltip-arrow::before {
                    border-bottom-color: #dc3545;
                }

                .disabled-link {
                    cursor: not-allowed !important;
                    text-decoration: none !important;
                }

                .mark-input:focus {
                    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
                    border-color: #86b7fe;
                }
                .table-hover > tbody > tr:not(.status-dropped):not(.status-nosub):not(.status-noexam):hover {
                    background-color: rgba(13, 110, 253, 0.03);
                }
                .avatar-sm {
                    background-color: #f8f9fa;
                }
                .badge {
                    padding: 0.4em 0.8em;
                    font-weight: 500;
                }

                /* Bulk Entry Styles */
                #bulkEntryText {
                    font-family: 'Courier New', monospace;
                    font-size: 0.9rem;
                }

                .bulk-entry-highlight {
                    background-color: #fff3cd !important;
                    border-color: #ffc107 !important;
                    animation: highlightPulse 2s ease-in-out;
                }

                @keyframes highlightPulse {
                    0% { background-color: #ffc107; }
                    50% { background-color: #fff3cd; }
                    100% { background-color: transparent; }
                }

                .bulk-entry-card {
                    transition: all 0.3s ease;
                }

                .bulk-entry-card:hover {
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                }

                /* Professional Bulk Entry Modal */
                #bulkEntryModal .modal-content {
                    border: 1px solid var(--horizon-card-border, #e2e8f0);
                    border-radius: 18px;
                    overflow: hidden;
                    box-shadow: 0 24px 60px rgba(16, 24, 40, 0.25);
                }
                #bulkEntryModal .bulk-entry-modal-header {
                    background: var(--horizon-brand-linear, linear-gradient(135deg, #868CFF 0%, #4318FF 100%));
                    color: #fff;
                    border-bottom: 0;
                    padding: 1rem 1.25rem;
                }
                #bulkEntryModal .bulk-entry-modal-header .modal-title {
                    display: flex;
                    align-items: center;
                    gap: 0.7rem;
                    font-weight: 800;
                    font-size: 1.02rem;
                    letter-spacing: 0.01em;
                }
                #bulkEntryModal .bulk-entry-header-icon {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 38px;
                    height: 38px;
                    min-width: 38px;
                    border-radius: 12px;
                    background: rgba(255, 255, 255, 0.2);
                    border: 1px solid rgba(255, 255, 255, 0.3);
                    font-size: 15px;
                }
                #bulkEntryModal .bulk-entry-header-sub {
                    display: block;
                    font-size: 0.76rem;
                    font-weight: 500;
                    opacity: 0.88;
                    margin-top: 1px;
                }
                #bulkEntryModal .bulk-entry-modal-header .btn-close {
                    filter: invert(1) brightness(1.2);
                    opacity: 0.9;
                }
                #bulkEntryModal .bulk-entry-modal-header .btn-close:hover {
                    opacity: 1;
                }
                #bulkEntryModal .modal-body {
                    background: #f8fafc;
                    padding: 1.1rem 1.25rem;
                }
                #bulkEntryModal .bulk-section {
                    background: #fff;
                    border: 1px solid var(--horizon-card-border, #e2e8f0);
                    border-radius: 14px;
                    padding: 0.95rem 1rem;
                    margin-bottom: 0.9rem;
                    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.05);
                }
                #bulkEntryModal .bulk-section:last-child {
                    margin-bottom: 0;
                }
                #bulkEntryModal .bulk-section-title {
                    display: flex;
                    align-items: center;
                    gap: 0.55rem;
                    font-size: 0.76rem;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #334155;
                    margin-bottom: 0.7rem;
                }
                #bulkEntryModal .bulk-section-title .bulk-step-badge {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 22px;
                    height: 22px;
                    border-radius: 50%;
                    background: var(--horizon-brand-50, #E9E3FF);
                    color: var(--horizon-brand-600, #3311DB);
                    font-size: 0.7rem;
                    font-weight: 800;
                }
                #bulkEntryModal .bulk-section-title .bulk-title-icon {
                    color: var(--horizon-brand-500, #4318FF);
                }
                #bulkEntryModal .bulk-hint {
                    font-size: 0.75rem;
                    color: #64748b;
                }
                #bulkEntryModal .bulk-file-input {
                    border-radius: 10px;
                    border: 1px dashed #c7d2fe !important;
                    background: #f5f7ff;
                    padding: 0.45rem 0.6rem;
                    font-size: 0.82rem;
                }
                #bulkEntryModal .bulk-file-input:focus {
                    border-color: var(--horizon-brand-500, #4318FF) !important;
                    box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.14) !important;
                    background: #fff;
                }
                #bulkEntryModal textarea.bulk-textarea {
                    font-family: 'JetBrains Mono', 'Courier New', monospace;
                    font-size: 0.83rem;
                    border-radius: 10px;
                    resize: vertical;
                    min-height: 148px;
                }
                #bulkEntryModal textarea.bulk-textarea:focus {
                    border-color: var(--horizon-brand-500, #4318FF) !important;
                    box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.14) !important;
                }
                #bulkEntryModal .bulk-field-label {
                    font-size: 0.76rem;
                    font-weight: 700;
                    color: #334155;
                    margin-bottom: 0.35rem;
                    display: flex;
                    align-items: center;
                    gap: 0.35rem;
                }
                #bulkEntryModal button.btn-bulk-primary {
                    background: var(--horizon-brand-linear, linear-gradient(135deg, #868CFF 0%, #4318FF 100%));
                    border: 0;
                    color: #fff;
                    font-weight: 700;
                    border-radius: 10px;
                    padding: 0.5rem 0.8rem;
                    box-shadow: 0 4px 12px rgba(67, 24, 255, 0.3);
                    transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease;
                }
                #bulkEntryModal button.btn-bulk-primary:hover {
                    filter: brightness(1.06);
                    transform: translateY(-1px);
                    box-shadow: 0 8px 18px rgba(67, 24, 255, 0.38);
                    color: #fff;
                }
                #bulkEntryModal button.btn-bulk-primary:active {
                    transform: translateY(0);
                }
                #bulkEntryModal button.btn-bulk-success {
                    background: linear-gradient(135deg, #34d399 0%, #059669 100%);
                    border: 0;
                    color: #fff;
                    font-weight: 700;
                    border-radius: 10px;
                    padding: 0.5rem 0.8rem;
                    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
                    transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease;
                }
                #bulkEntryModal button.btn-bulk-success:hover {
                    filter: brightness(1.05);
                    transform: translateY(-1px);
                    color: #fff;
                }
                #bulkEntryModal button.btn-bulk-ghost {
                    border: 1px solid var(--horizon-card-border, #e2e8f0);
                    background: #fff;
                    color: #475569;
                    font-weight: 700;
                    border-radius: 10px;
                    padding: 0.5rem 0.8rem;
                    transition: border-color 0.2s ease, background 0.2s ease, transform 0.15s ease;
                }
                #bulkEntryModal button.btn-bulk-ghost:hover {
                    border-color: var(--horizon-brand-300, #8171FC);
                    background: var(--horizon-brand-50, #E9E3FF);
                    color: var(--horizon-brand-600, #3311DB);
                }
                #bulkEntryModal .bulk-range-group {
                    border: 1px solid var(--horizon-card-border, #e2e8f0);
                    border-radius: 10px;
                    overflow: hidden;
                }
                #bulkEntryModal .bulk-range-group .input-group-text {
                    background: #f1f5f9;
                    border: 0;
                    font-size: 0.75rem;
                    font-weight: 700;
                    color: #475569;
                }
                #bulkEntryModal .bulk-range-group .form-control {
                    border: 0;
                    font-weight: 700;
                    text-align: center;
                }
                #bulkEntryModal .bulk-range-group .form-control:focus {
                    box-shadow: inset 0 0 0 2px rgba(67, 24, 255, 0.25);
                }
                #bulkEntryModal #fileUploadStatus,
                #bulkEntryModal #bulkEntryStatus {
                    display: flex;
                    align-items: center;
                    gap: 0.35rem;
                    min-height: 1.2em;
                }
                #bulkEntryModal .bulk-results-card {
                    border: 1px solid var(--horizon-card-border, #e2e8f0);
                    border-radius: 12px;
                    overflow: hidden;
                }
                #bulkEntryModal .bulk-results-card .card-header {
                    background: #f1f5f9 !important;
                    border-bottom: 1px solid var(--horizon-card-border, #e2e8f0);
                }
                #bulkEntryModal .modal-footer {
                    background: #fff;
                    border-top: 1px solid var(--horizon-card-border, #e2e8f0);
                }
                #bulkEntryModal .bulk-footer-hint {
                    font-size: 0.75rem;
                    color: #64748b;
                }
                #bulkEntryModal .bulk-footer-hint kbd {
                    background: #f1f5f9;
                    border: 1px solid #e2e8f0;
                    border-bottom-width: 2px;
                    border-radius: 6px;
                    padding: 0.05rem 0.35rem;
                    font-size: 0.7rem;
                    color: #334155;
                }
                @media (max-width: 768px) {
                    #bulkEntryModal .modal-body {
                        padding: 0.9rem;
                    }
                    #bulkEntryModal .bulk-section {
                        padding: 0.8rem;
                    }
                }

                /* Dark mode: Bulk Entry Modal */
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .modal-content {
                    background: var(--horizon-card-bg, #182235);
                    border-color: var(--horizon-card-border, #2b3850);
                    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .modal-body {
                    background: var(--horizon-card-bg, #182235);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-section {
                    background: var(--horizon-input-bg, #202d43);
                    border-color: var(--horizon-card-border, #2b3850);
                    box-shadow: none;
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-section-title,
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-field-label {
                    color: var(--horizon-text-heading, #e5ebfa);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-hint,
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-footer-hint {
                    color: var(--horizon-text-muted, #8e9dbc);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-file-input,
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal textarea.bulk-textarea,
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-range-group .form-control {
                    background: #141d2e !important;
                    border-color: var(--horizon-input-border, #35445e) !important;
                    color: var(--horizon-input-text, #f1f5ff) !important;
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-file-input {
                    border-style: dashed !important;
                    background: rgba(134, 140, 255, 0.08) !important;
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-range-group {
                    border-color: var(--horizon-input-border, #35445e);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-range-group .input-group-text {
                    background: #2b3850 !important;
                    color: var(--horizon-text-body, #c2cee6) !important;
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal button.btn-bulk-ghost {
                    background: transparent;
                    border-color: var(--horizon-input-border, #35445e);
                    color: var(--horizon-text-body, #c2cee6);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal button.btn-bulk-ghost:hover {
                    background: rgba(67, 24, 255, 0.22);
                    border-color: #868CFF;
                    color: #fff;
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-results-card .card-header {
                    background: #202d43 !important;
                    border-color: var(--horizon-card-border, #2b3850) !important;
                    color: var(--horizon-text-heading, #e5ebfa) !important;
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .modal-footer {
                    background: var(--horizon-card-bg, #182235);
                    border-color: var(--horizon-card-border, #2b3850);
                }
                :is(body.dark, body.dark-mode, body[data-theme="dark"], body[data-bs-theme="dark"], html[data-theme="dark"] body) #bulkEntryModal .bulk-footer-hint kbd {
                    background: #202d43;
                    border-color: #35445e;
                    color: var(--horizon-text-body, #c2cee6);
                }
            </style>

            <script>
                function updatePercentage(input) {
                    const row = input.closest('tr');
                    const badge = row.querySelector('.badge');
                    const hps = parseFloat(input.dataset.hps) || 1;
                    const raw = String(input.value).trim();

                    // Blank means "not yet graded": show an em dash, not 0.0%
                    if (raw === '') {
                        badge.textContent = '—';
                        badge.className = 'badge bg-soft-secondary text-secondary rounded-pill';
                        return;
                    }

                    const value = parseFloat(raw);
                    if (isNaN(value)) {
                        badge.textContent = '—';
                        badge.className = 'badge bg-soft-secondary text-secondary rounded-pill';
                        return;
                    }

                    const percentage = Math.min(100, Math.round((value / hps) * 100 * 10) / 10);
                    badge.textContent = percentage + '%';

                    if (percentage >= 75) {
                        badge.className = 'badge bg-soft-success text-success rounded-pill';
                    } else if (percentage >= 50) {
                        badge.className = 'badge bg-soft-warning text-warning rounded-pill';
                    } else {
                        badge.className = 'badge bg-soft-danger text-danger rounded-pill';
                    }
                }

                // Initialize percentage display on page load
                document.querySelectorAll('.mark-input').forEach(input => {
                    if (input.value) updatePercentage(input);
                });
</script>
    </div>
    <?php endif; ?>
</div>
</form>

<!-- Bulk Entry Modal -->
<div class="modal fade" id="bulkEntryModal" tabindex="-1" role="dialog" aria-labelledby="bulkEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header bulk-entry-modal-header">
                <h5 class="modal-title" id="bulkEntryModalLabel">
                    <span class="bulk-entry-header-icon"><i class="fas fa-file-import"></i></span>
                    <span>Bulk Entry
                        <span class="bulk-entry-header-sub">Import student numbers &amp; marks via file or manual entry</span>
                    </span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- File Upload Section -->
                <div class="bulk-section">
                    <div class="bulk-section-title">
                        <span class="bulk-step-badge">1</span>
                        <i class="fas fa-file-excel bulk-title-icon"></i> Import CSV / Excel File
                    </div>
                    <div class="row g-2 align-items-start">
                        <div class="col-md-8">
                            <input type="file" id="bulkFileUpload" class="form-control form-control-sm bulk-file-input"
                                   accept=".csv,.xlsx,.xls"
                                   placeholder="Upload CSV or Excel file with student numbers and marks">
                            <div class="bulk-hint mt-1">
                                Supported formats: CSV, Excel (.xlsx, .xls). File should contain student numbers and marks columns.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="button" id="processBulkFile" class="btn btn-sm btn-bulk-success w-100">
                                <i class="fas fa-upload me-1"></i> Process File
                            </button>
                        </div>
                    </div>
                    <div id="fileUploadStatus" class="small text-muted mt-2"></div>
                </div>

                <!-- Manual Entry Section -->
                <div class="bulk-section">
                    <div class="bulk-section-title">
                        <span class="bulk-step-badge">2</span>
                        <i class="fas fa-keyboard bulk-title-icon"></i> Manual Entry
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="bulk-field-label" for="bulkStudentNumbers"><i class="fas fa-id-card text-primary"></i> Student Numbers</label>
                            <textarea id="bulkStudentNumbers" class="form-control form-control-sm bulk-textarea" rows="6"
                                placeholder="Enter student numbers (one per line)&#10;Example:&#10;23-0001&#10;23-0002&#10;23-0003"></textarea>
                            <div class="bulk-hint mt-1">One student number per line</div>
                        </div>
                        <div class="col-md-4">
                            <label class="bulk-field-label" for="bulkMarks"><i class="fas fa-star-half-alt text-primary"></i> Corresponding Marks</label>
                            <textarea id="bulkMarks" class="form-control form-control-sm bulk-textarea" rows="6"
                                placeholder="Enter marks (one per line, corresponding to student numbers)&#10;Example:&#10;85&#10;92&#10;78"></textarea>
                            <div class="bulk-hint mt-1">Must match the same count as student numbers</div>
                        </div>
                        <div class="col-md-4">
                            <label class="bulk-field-label"><i class="fas fa-sliders-h text-primary"></i> Bulk Set Range</label>
                            <div class="input-group input-group-sm bulk-range-group">
                                <span class="input-group-text">From</span>
                                <input type="number" id="bulkSetMarksFrom" class="form-control form-control-sm" placeholder="0" min="0" step="0.01" value="0" title="Leave blank to target only the blank (ungraded) scores">
                                <span class="input-group-text">To</span>
                                <input type="number" id="bulkSetMarksTo" class="form-control form-control-sm" placeholder="—" min="0" step="0.01" title="Leave blank to clear the matching scores">
                            </div>
                            <div class="bulk-hint mt-1">Leave <strong>To</strong> blank to clear the matching scores, or <strong>From</strong> blank to target the blank ones.</div>
                            <div class="d-grid gap-2 mt-2">
                                <div class="btn-group w-100" role="group">
                                    <button type="button" id="applyBulkRange" class="btn btn-sm btn-bulk-ghost flex-grow-1">
                                        <i class="fas fa-sync me-1"></i> Apply Range
                                    </button>
                                    <button type="button" id="undoBulkRange" class="btn btn-sm btn-bulk-ghost flex-grow-1" style="display: none;">
                                        <i class="fas fa-undo me-1"></i> Undo
                                    </button>
                                </div>
                                <button type="button" id="parseBulkEntry" class="btn btn-sm btn-bulk-primary w-100">
                                    <i class="fas fa-magic me-1"></i> Submit Marks
                                </button>
                                <button type="button" id="clearBulkEntry" class="btn btn-sm btn-bulk-ghost w-100">
                                    <i class="fas fa-eraser me-1"></i> Clear All
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="bulkEntryStatus" class="small text-muted mt-2"></div>
                </div>

                <!-- Results Section -->
                <div id="bulkEntryResults" class="mt-3" style="display: none;">
                    <div class="card border-0 shadow-sm bulk-results-card">
                        <div class="card-header py-2">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-info-circle me-2 text-primary"></i> Bulk Entry Results
                            </h6>
                        </div>
                        <div class="card-body p-2">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th>Student Number</th>
                                            <th>Mark Applied</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bulkEntryResultsBody">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between align-items-center">
                <span class="bulk-footer-hint"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> to submit marks quickly</span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Handle bulk entry functionality
    $(document).ready(function() {
        // Parse bulk entry text and populate marks
        $('#parseBulkEntry').click(function() {
            const studentNumbers = $('#bulkStudentNumbers').val().trim();
            const marks = $('#bulkMarks').val().trim();

            if (!studentNumbers || !marks) {
                showBulkStatus('Please enter both student numbers and marks', 'warning');
                return;
            }

            const studentLines = studentNumbers.split('\n').filter(line => line.trim());
            const markLines = marks.split('\n').filter(line => line.trim());

            if (studentLines.length !== markLines.length) {
                showBulkStatus(`Count mismatch: ${studentLines.length} student numbers vs ${markLines.length} marks`, 'warning');
                return;
            }

            let successCount = 0;
            let errorCount = 0;
            const errors = [];

            // Create a mapping of student numbers to input fields
            const studentMap = {};
            $('input[name="mark[]"]').each(function() {
                const row = $(this).closest('tr');
                const studentNumber = row.find('small.text-muted').text().replace('ID: ', '');
                const isDropped = row.hasClass('dropped-student-row');
                studentMap[studentNumber] = {
                    input: $(this),
                    row: row,
                    isDropped: isDropped
                };
            });

            studentLines.forEach((studentLine, index) => {
                const studentNumber = studentLine.trim();
                const mark = parseFloat(markLines[index].trim());

                if (isNaN(mark)) {
                    errors.push(`Line ${index + 1}: Invalid mark value "${markLines[index]}" for student ${studentNumber}`);
                    errorCount++;
                    return;
                }

                // Check if student number exists in the current class
                if (studentMap[studentNumber]) {
                    const studentData = studentMap[studentNumber];

                    // Skip if student is dropped
                    if (studentData.isDropped) {
                        errors.push(`Line ${index + 1}: Student ${studentNumber} is dropped - mark not applied`);
                        errorCount++;
                        return;
                    }

                    const $input = studentData.input;
                    const $row = studentData.row;
                    const maxMark = parseFloat($input.data('hps') || 100);
                    const safeValue = Math.min(Math.max(mark, 0), maxMark);

                    // Add visual feedback
                    $row.addClass('bulk-entry-highlight');
                    setTimeout(() => {
                        $row.removeClass('bulk-entry-highlight');
                    }, 2000);

                    $input.val(safeValue).trigger('input');
                    updatePercentage($input[0]);
                    successCount++;

                    // Trigger auto-save after bulk entry
                    setTimeout(() => autoSaveMarks(), 100);
                } else {
                    // Try to find a matching student number (fuzzy matching)
                    let foundMatch = false;
                    const availableStudents = Object.keys(studentMap);

                    // Try exact match first
                    for (let availableStudent of availableStudents) {
                        if (availableStudent === studentNumber) {
                            foundMatch = true;
                            break;
                        }
                    }

                    // If no exact match, try to find similar student numbers
                    if (!foundMatch) {
                        for (let availableStudent of availableStudents) {
                            // Check if student number contains the input or vice versa
                            if (availableStudent.includes(studentNumber) || studentNumber.includes(availableStudent)) {
                                const studentData = studentMap[availableStudent];

                                // Skip if student is dropped
                                if (studentData.isDropped) {
                                    errors.push(`Line ${index + 1}: Student ${studentNumber} (matched with ${availableStudent}) is dropped - mark not applied`);
                                    errorCount++;
                                    foundMatch = true; // Mark as found but skipped
                                    break;
                                }

                                const $input = studentData.input;
                                const $row = studentData.row;
                                const maxMark = parseFloat($input.data('hps') || 100);
                                const safeValue = Math.min(Math.max(mark, 0), maxMark);

                                // Add visual feedback
                                $row.addClass('bulk-entry-highlight');
                                setTimeout(() => {
                                    $row.removeClass('bulk-entry-highlight');
                                }, 2000);

                                $input.val(safeValue).trigger('input');
                                updatePercentage($input[0]);
                                successCount++;
                                foundMatch = true;

                                errors.push(`Line ${index + 1}: Student ${studentNumber} not found, but matched with ${availableStudent}`);
                                break;
                            }
                        }
                    }

                    if (!foundMatch) {
                        errors.push(`Line ${index + 1}: Student ${studentNumber} not found in current class`);
                        errorCount++;
                    }
                }
            });

            // Show results in table format
            displayBulkEntryResults(successCount, errorCount, errors);

            if (successCount > 0) {
                hasUnsavedChanges = true;

                // Clear text areas immediately after successful fill
                $('#bulkStudentNumbers').val('');
                $('#bulkMarks').val('');
            }
        });

        // Clear bulk entry
        $('#clearBulkEntry').click(function() {
            $('#bulkStudentNumbers').val('');
            $('#bulkMarks').val('');
            $('#bulkEntryResults').hide();
            showBulkStatus('Bulk entry cleared', 'info');
        });

        // Function to display bulk entry results in table format
        function displayBulkEntryResults(successCount, errorCount, errors) {
            const resultsDiv = $('#bulkEntryResults');
            const resultsBody = $('#bulkEntryResultsBody');

            resultsBody.empty();

            if (successCount === 0 && errorCount === 0) {
                resultsDiv.hide();
                return;
            }

            resultsDiv.show();

            // Add success rows
            if (successCount > 0) {
                const successRow = `
                    <tr class="table-success">
                        <td><span class="badge bg-success">Success</span></td>
                        <td>-</td>
                        <td><strong>${successCount}</strong> student(s) updated successfully</td>
                    </tr>
                `;
                resultsBody.append(successRow);
            }

            // Add error rows
            if (errorCount > 0) {
                errors.forEach((error, index) => {
                    const errorRow = `
                        <tr class="table-danger">
                            <td><span class="badge bg-danger">Error</span></td>
                            <td>${error.split(': ')[1] || 'Unknown'}</td>
                            <td>${error.split(': ')[0] || 'Unknown error'}</td>
                        </tr>
                    `;
                    resultsBody.append(errorRow);
                });
            }
        }

        // Perf: lazy-load SheetJS only when an Excel file is actually used
        function ensureXLSXLoaded(callback) {
            if (typeof XLSX !== 'undefined') { callback(); return; }
            var existing = document.getElementById('sheetjs-xlsx-script');
            if (existing) {
                existing.addEventListener('load', callback, { once: true });
                return;
            }
            var s = document.createElement('script');
            s.id = 'sheetjs-xlsx-script';
            s.src = './js/xlsx.full.min.js';
            s.onload = callback;
            s.onerror = function() {
                document.getElementById('fileUploadStatus').innerHTML = '<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Excel support failed to load. Please use CSV instead.';
            };
            document.head.appendChild(s);
        }

        // Process CSV/Excel file upload
        $('#processBulkFile').click(function() {
            const fileInput = document.getElementById('bulkFileUpload');
            const file = fileInput.files[0];

            if (!file) {
                showBulkStatus('Please select a file to upload', 'warning');
                return;
            }

            const fileExtension = file.name.split('.').pop().toLowerCase();
            const statusDiv = document.getElementById('fileUploadStatus');

            statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing file...';

            if (fileExtension === 'csv') {
                // Process CSV file
                const reader = new FileReader();
                reader.onload = function(e) {
                    const text = e.target.result;
                    processCSVFile(text);
                };
                reader.readAsText(file);
            } else if (fileExtension === 'xlsx' || fileExtension === 'xls') {
                // Process Excel file using SheetJS (loaded on demand)
                statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Loading Excel support...';
                ensureXLSXLoaded(function() {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const data = new Uint8Array(e.target.result);
                        try {
                            const workbook = XLSX.read(data, {type: 'array'});
                            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                            const jsonData = XLSX.utils.sheet_to_json(firstSheet, {header: 1});
                            processExcelData(jsonData);
                        } catch (error) {
                            statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Error processing Excel file: ' + error.message;
                            console.error('Excel processing error:', error);
                        }
                    };
                    reader.readAsArrayBuffer(file);
                });
            } else {
                statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Unsupported file format. Please use CSV or Excel files.';
            }
        });

        function processCSVFile(csvText) {
            const lines = csvText.split('\n').filter(line => line.trim());
            const studentNumbers = [];
            const marks = [];

            // Try to detect column headers
            const firstLine = lines[0].toLowerCase();
            let studentNumberCol = -1;
            let markCol = -1;

            // Check for common column names
            const headers = firstLine.split(',').map(h => h.trim().toLowerCase());
            headers.forEach((header, index) => {
                if (header.includes('student') && (header.includes('number') || header.includes('id') || header.includes('no'))) {
                    studentNumberCol = index;
                }
                if (header.includes('mark') || header.includes('score') || header.includes('grade')) {
                    markCol = index;
                }
            });

            // If headers found, start from second line, otherwise treat first line as data
            const startIndex = (studentNumberCol >= 0 && markCol >= 0) ? 1 : 0;

            for (let i = startIndex; i < lines.length; i++) {
                const columns = lines[i].split(',').map(col => col.trim());

                let studentNumber, mark;

                if (studentNumberCol >= 0 && markCol >= 0) {
                    // Use detected column positions
                    studentNumber = columns[studentNumberCol];
                    mark = columns[markCol];
                } else {
                    // Assume first column is student number, second is mark
                    studentNumber = columns[0];
                    mark = columns[1];
                }

                if (studentNumber && mark !== undefined) {
                    studentNumbers.push(studentNumber);
                    marks.push(mark);
                }
            }

            if (studentNumbers.length > 0) {
                $('#bulkStudentNumbers').val(studentNumbers.join('\n'));
                $('#bulkMarks').val(marks.join('\n'));
                document.getElementById('fileUploadStatus').innerHTML =
                    `<i class="fas fa-check-circle me-1 text-success"></i> Successfully parsed ${studentNumbers.length} student records from CSV`;

                // Auto-trigger the bulk entry
                setTimeout(() => {
                    $('#parseBulkEntry').click();
                }, 500);
            } else {
                document.getElementById('fileUploadStatus').innerHTML =
                    '<i class="fas fa-exclamation-triangle me-1 text-danger"></i> No valid student records found in CSV file';
            }
        }

        function processExcelData(jsonData) {
            const studentNumbers = [];
            const marks = [];

            if (jsonData.length < 2) {
                document.getElementById('fileUploadStatus').innerHTML =
                    '<i class="fas fa-exclamation-triangle me-1 text-danger"></i> Excel file appears to be empty or has invalid format';
                return;
            }

            // Try to detect column headers
            const headers = jsonData[0].map(h => String(h).trim().toLowerCase());
            let studentNumberCol = -1;
            let markCol = -1;

            headers.forEach((header, index) => {
                if (header.includes('student') && (header.includes('number') || header.includes('id') || header.includes('no'))) {
                    studentNumberCol = index;
                }
                if (header.includes('mark') || header.includes('score') || header.includes('grade')) {
                    markCol = index;
                }
            });

            // If headers found, start from second row, otherwise treat first row as data
            const startIndex = (studentNumberCol >= 0 && markCol >= 0) ? 1 : 0;

            for (let i = startIndex; i < jsonData.length; i++) {
                const row = jsonData[i];

                let studentNumber, mark;

                if (studentNumberCol >= 0 && markCol >= 0) {
                    // Use detected column positions
                    studentNumber = String(row[studentNumberCol] || '').trim();
                    mark = row[markCol];
                } else {
                    // Assume first column is student number, second is mark
                    studentNumber = String(row[0] || '').trim();
                    mark = row[1];
                }

                if (studentNumber && mark !== undefined && mark !== null) {
                    studentNumbers.push(studentNumber);
                    marks.push(mark);
                }
            }

            if (studentNumbers.length > 0) {
                $('#bulkStudentNumbers').val(studentNumbers.join('\n'));
                $('#bulkMarks').val(marks.join('\n'));
                document.getElementById('fileUploadStatus').innerHTML =
                    `<i class="fas fa-check-circle me-1 text-success"></i> Successfully parsed ${studentNumbers.length} student records from Excel file`;

                // Auto-trigger the bulk entry
                setTimeout(() => {
                    $('#parseBulkEntry').click();
                }, 500);
            } else {
                document.getElementById('fileUploadStatus').innerHTML =
                    '<i class="fas fa-exclamation-triangle me-1 text-danger"></i> No valid student records found in Excel file';
            }
        }

        // Track previous values for undo functionality
        let bulkRangePreviousValues = {};

        // Apply bulk range
        $('#applyBulkRange').click(function() {
            const fromValue = String($('#bulkSetMarksFrom').val() || '').trim();
            const toValue = String($('#bulkSetMarksTo').val() || '').trim();

            // A blank "To" is allowed: it clears the matching marks instead of aborting
            if (fromValue === '' && toValue === '') {
                showBulkStatus('Enter a "From" value, a "To" value, or both', 'warning');
                return;
            }

            // Store current values before applying range (for undo)
            bulkRangePreviousValues = {};
            $('input[name="mark[]"]').not(':disabled').not('[readonly]').each(function() {
                const studentId = $(this).closest('tr').find('input[name="student_id[]"]').val();
                bulkRangePreviousValues[studentId] = $(this).val();
            });

            const updatedCount = setMarksFromTo(fromValue, toValue);
            const fromLabel = fromValue === '' ? 'blank' : fromValue;
            const toLabel = toValue === '' ? 'blank (cleared)' : toValue;
            showBulkStatus(`Applied range: ${fromLabel} → ${toLabel} (${updatedCount} student(s) updated)`, 'success');

            // Show undo button
            $('#undoBulkRange').show();
        });

        // Undo bulk range
        $('#undoBulkRange').click(function() {
            // Restore previous values
            Object.entries(bulkRangePreviousValues).forEach(([studentId, previousValue]) => {
                const input = $(`input[name="student_id[]"][value="${studentId}"]`).closest('tr').find('input[name="mark[]"]');
                if (input.length) {
                    input.val(previousValue).trigger('input');
                    updatePercentage(input[0]);
                }
            });

            // Clear stored values and hide undo button
            bulkRangePreviousValues = {};
            $('#undoBulkRange').hide();

            showBulkStatus('Bulk changes undone', 'info');

            // Trigger auto-save after undo
            setTimeout(() => autoSaveMarks(), 100);
        });

        // Show bulk entry status
        function showBulkStatus(message, type) {
            const $status = $('#bulkEntryStatus');
            $status.removeClass('text-muted text-success text-warning text-danger');

            switch(type) {
                case 'success':
                    $status.addClass('text-success');
                    $status.html(`<i class="fas fa-check-circle me-1"></i>${message}`);
                    break;
                case 'warning':
                    $status.addClass('text-warning');
                    $status.html(`<i class="fas fa-exclamation-triangle me-1"></i>${message}`);
                    break;
                case 'danger':
                    $status.addClass('text-danger');
                    $status.html(`<i class="fas fa-times-circle me-1"></i>${message}`);
                    break;
                case 'info':
                    $status.addClass('text-info');
                    $status.html(`<i class="fas fa-info-circle me-1"></i>${message}`);
                    break;
                default:
                    $status.addClass('text-muted');
                    $status.html(message);
            }
        }

        function setMarksFromTo(fromValue, toValue) {
            const fromRaw = String(fromValue == null ? '' : fromValue).trim();
            const toRaw = String(toValue == null ? '' : toValue).trim();
            // Blank "From" targets only the rows that are already blank; blank "To" clears them
            const fromMark = fromRaw === '' ? null : parseFloat(fromRaw);
            const toMark = toRaw === '' ? null : parseFloat(toRaw);
            if ((fromRaw !== '' && isNaN(fromMark)) || (toRaw !== '' && isNaN(toMark))) return 0;

            let updated = 0;

            // Only set marks for students who have the specified from value (exclude disabled and readonly inputs)
            $('input[name="mark[]"]').not(':disabled').not('[readonly]').each(function() {
                const $input = $(this);
                const currentRaw = String($input.val() || '').trim();
                const matches = fromMark === null
                    ? currentRaw === ''
                    : parseFloat(currentRaw) === fromMark;
                if (!matches) return;

                if (toMark === null) {
                    // Clear the score so it is saved as NULL (blank = not yet graded)
                    $input.val('').trigger('input');
                } else {
                    const maxMark = parseFloat($input.data('hps') || 100);
                    const safeValue = Math.min(Math.max(toMark, 0), maxMark);
                    $input.val(safeValue).trigger('input');
                }
                updatePercentage(this);
                updated++;
            });
            hasUnsavedChanges = true;

            // Trigger auto-save after range operation
            setTimeout(() => autoSaveMarks(), 100);
            return updated;
        }

        function setMarksForZeroStudents(value) {
            setMarksFromTo(0, value);
        }

        // Handle Enter key in bulk set input
        $('#bulkSetMarks').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                const $input = $(this);
                const value = $input.val();
                if (!isNaN(parseFloat(value)) && value.trim() !== '') {
                    setMarksForZeroStudents(value);
                    $input.val(''); // Clear the input after processing
                }
                return false;
            }
        });

        // Handle Enter key in bulk range inputs
        $('#bulkSetMarksFrom, #bulkSetMarksTo').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                const fromValue = String($('#bulkSetMarksFrom').val() || '').trim();
                const toValue = String($('#bulkSetMarksTo').val() || '').trim();

                // Blank "To" is valid: it clears the matching scores
                if (fromValue === '' && toValue === '') {
                    showBulkStatus('Enter a "From" value, a "To" value, or both', 'warning');
                    return false;
                }

                // Trigger the apply bulk range functionality
                $('#applyBulkRange').click();
                return false;
            }
        });

        // Handle Ctrl+Z for undo when undo button is visible
        $(document).on('keydown', function(e) {
            if (e.ctrlKey && e.key === 'z' && $('#undoBulkRange').is(':visible')) {
                e.preventDefault();
                $('#undoBulkRange').click();
                return false;
            }
        });


        // Handle Ctrl+Enter in bulk entry textareas to parse
        $('#bulkStudentNumbers, #bulkMarks').on('keydown', function(e) {
            if (e.ctrlKey && e.which === 13) { // Ctrl+Enter
                e.preventDefault();
                $('#parseBulkEntry').click();
            }
        });

        // Auto-fill 0 for blank lines after valued lines in bulkMarks textarea
        $('#bulkMarks').on('input', function(e) {
            let content = $(this).val();
            const lines = content.split('\n');
            let modified = false;

            // Find the last line that has actual content (not empty or just whitespace)
            let lastContentLineIndex = -1;
            for (let i = lines.length - 1; i >= 0; i--) {
                if (lines[i].trim() !== '') {
                    lastContentLineIndex = i;
                    break;
                }
            }

            for (let i = 0; i < lines.length; i++) {
                const currentLine = lines[i].trim();

                // Check if current line has a value and next line is blank
                // AND current line is NOT the last line with content
                if (currentLine !== '' && i < lines.length - 1 && i < lastContentLineIndex) {
                    const nextLine = lines[i + 1].trim();
                    if (nextLine === '') {
                        // Replace blank line with 0
                        lines[i + 1] = '0';
                        modified = true;
                    }
                }
            }

            // Update textarea if modifications were made
            if (modified) {
                // Remove all trailing empty lines and trailing 0s that represent blank lines
                let updatedContent = lines.join('\n');
                updatedContent = updatedContent.replace(/\n+$/, '').replace(/^\n+/, '');

                // Don't remove trailing 0s to maintain count with student numbers
                // Instead, just clean up formatting
                updatedContent = updatedContent.replace(/\n+$/, '').replace(/^\n+/, '');

                // Store cursor position before updating
                const $this = $(this);
                const cursorPos = $this[0].selectionStart;

                // Update content
                $this.val(updatedContent);

                // Restore cursor position or focus on last valued line
                setTimeout(function() {
                    if (updatedContent === '') {
                        // If content is empty, focus at start
                        $this[0].setSelectionRange(0, 0);
                    } else {
                        const linesAfterUpdate = updatedContent.split('\n');

                        // Find the last line with a non-zero value
                        let lastNonZeroIndex = -1;
                        for (let i = linesAfterUpdate.length - 1; i >= 0; i--) {
                            if (linesAfterUpdate[i].trim() !== '' && linesAfterUpdate[i].trim() !== '0') {
                                lastNonZeroIndex = i;
                                break;
                            }
                        }

                        // If no non-zero lines found, focus at the end
                        if (lastNonZeroIndex === -1) {
                            $this[0].setSelectionRange(updatedContent.length, updatedContent.length);
                        } else {
                            // Calculate position to end of last non-zero valued line
                            let targetPos = 0;
                            for (let i = 0; i <= lastNonZeroIndex; i++) {
                                if (i === lastNonZeroIndex) {
                                    targetPos += linesAfterUpdate[i].length;
                                } else {
                                    targetPos += linesAfterUpdate[i].length + 1; // +1 for newline
                                }
                            }

                            // Set cursor to end of last non-zero valued line
                            $this[0].setSelectionRange(targetPos, targetPos);
                        }
                    }
                    $this.focus();
                }, 0);
            }
        });


    });

    // Track if there are unsaved changes
    let hasUnsavedChanges = false;

    // Track original values
    let originalValues = {};

    // Function to validate marks against HPS (global scope)
    function validateMarks() {
        var hps = parseFloat($('#hps').text()) || 0;
        var hasError = false;

        $('input[name="mark[]"]').each(function() {
            var mark = parseFloat($(this).val()) || 0;
            if (mark > hps) {
                hasError = true;
                $(this).addClass('is-invalid');
                $(`<div class="invalid-feedback">Mark cannot exceed HPS (${hps})</div>`).insertAfter($(this));
            } else {
                $(this).removeClass('is-invalid');
                $(this).siblings('.invalid-feedback').remove();
            }
        });

        return !hasError;
    }

    // Function to auto-save marks via AJAX (global scope)
    function autoSaveMarks() {
        console.log('autoSaveMarks called');

        // First validate marks against HPS
        if (!validateMarks()) {
            console.log('Mark validation failed');
            return false;
        }

        console.log('Mark validation passed, proceeding with save');

        // Manually collect all mark data, including hidden rows from DataTable search
        var formData = new FormData();
        formData.append('a', 'save_mark');
        formData.append('assessment_id', $('#form_assessment_id').val());

        // Check if DataTable is initialized and use its API to get all rows
        var table = $.fn.DataTable.isDataTable('#marks-table') ? $('#marks-table').DataTable() : null;

        if (table) {
            // Use DataTable API to get all rows (including filtered ones)
            table.rows().every(function() {
                var $row = $(this.node());
                var studentId = $row.find('input[name="student_id[]"]').val();
                // Send '' for a blank input so save_mark stores NULL instead of 0
                var markValue = $.trim($row.find('input[name="mark[]"]').val() || '');

                if (studentId) {
                    formData.append('student_id[]', studentId);
                    formData.append('mark[]', markValue);
                }
            });
        } else {
            // Fallback to regular jQuery if DataTable is not initialized
            $('table#marks-table tbody tr').each(function() {
                var $row = $(this);
                var studentId = $row.find('input[name="student_id[]"]').val();
                // Send '' for a blank input so save_mark stores NULL instead of 0
                var markValue = $.trim($row.find('input[name="mark[]"]').val() || '');

                if (studentId) {
                    formData.append('student_id[]', studentId);
                    formData.append('mark[]', markValue);
                }
            });
        }

        $.ajax({
            url: './Actions.php?a=save_mark',
            data: formData,
            method: 'POST',
            processData: false,
            contentType: false,
            dataType: 'json',
            error: function(err) {
                console.error('Mark save AJAX error:', err);
                // Auto-save error handled silently
            },
            success: function(resp) {
                console.log('Mark save AJAX response:', resp);
                if(!!resp.status && resp.status == 'success') {
                    $('#assessmentDate').trigger('change');
                    // Flash avatar green with check mark only for the changed row
                    if (window.currentChangedRow) {
                        var avatar = window.currentChangedRow.find('.avatar-sm');
                        var icon = avatar.find('i');

                        // Store original HTML
                        var originalHtml = avatar.html();

                        // Change background to green and replace with check mark HTML
                        avatar.addClass('bg-success').removeClass('bg-light');
                        avatar.html('<i class="fas fa-check text-white"></i>');

                        setTimeout(function() {
                            // Revert back to original state using stored HTML
                            avatar.removeClass('bg-success').addClass('bg-light');
                            avatar.html(originalHtml);

                            // Ensure the icon is properly restored by re-finding it
                            var restoredIcon = avatar.find('i');
                            if (restoredIcon.length === 0) {
                                // If icon is missing, add it back manually
                                avatar.html('<i class="fas fa-user text-muted"></i>');
                            }
                        }, 1000);

                        // Clear the reference after use
                        window.currentChangedRow = null;
                    }

                    // Update original values after successful save
                    $('input[name^="mark"]').each(function() {
                        originalValues[$(this).attr('name')] = $(this).val();
                    });
                    hasUnsavedChanges = false;
                    enableNavigation();

                    // Update the HPS display if it changed
                    if(resp.hps) {
                        $('#hps').text(resp.hps);
                    }
                }
            }
        })
    }

    $(document).ready(function() {
        // Store original values on page load
        $('input[name^="mark"]').each(function() {
            originalValues[$(this).attr('name')] = $(this).val();
        });


        // Initialize tooltips for dropped students (modern popup style)
        var droppedRows = document.querySelectorAll('.dropped-student-row[data-bs-toggle="tooltip"]');
        droppedRows.forEach(function(el) {
            new bootstrap.Tooltip(el, {
                trigger: 'hover',
                placement: 'top'
            });
        });

        // Check for changes on input and auto-save
        $(document).on('input', 'input[name^="mark"]', function() {
            checkForChanges();

            // Store reference to the changed row for visual feedback
            window.currentChangedRow = $(this).closest('tr');

            // Auto-save marks when input changes
            autoSaveMarks();
        });



        // Make disableNavigation function available globally
        window.disableNavigation = function() {
            $('a:not([href^="#"])').each(function() {
                if (!$(this).hasClass('no-disable')) {
                    $(this).addClass('disabled-link');
                    $(this).css('pointer-events', 'none');
                    $(this).css('opacity', '0.5');
                }
            });
        }

        // Make enableNavigation function available globally
        window.enableNavigation = function() {
            $('.disabled-link').removeClass('disabled-link');
            $('a').css('pointer-events', '');
            $('a').css('opacity', '');
        }

        // Make checkForChanges function available globally
        window.checkForChanges = function() {
            hasUnsavedChanges = false;
            $('input[name^="mark"]').each(function() {
                if ($(this).val() !== originalValues[$(this).attr('name')]) {
                    hasUnsavedChanges = true;
                    return false; // Exit the loop early if any change is found
                }
            });

            if (hasUnsavedChanges) {
                disableNavigation();
            } else {
                enableNavigation();
            }
        }
    });

    // Function to reset all mark inputs to 0
    function clearAllMarks(skipAutoSave = false) {
        $('#marks-table input[name="mark[]"]').val('0');
        checkForChanges();

        // Only trigger auto-save if not skipping (for assessment changes)
        if (!skipAutoSave) {
            setTimeout(() => autoSaveMarks(), 100);
        }
    }

    function activateAssessmentQuarter(quarterValue) {
        $('#assessment_quarter').val(quarterValue);
        $('.marks-quarter-tab').removeClass('active');
        $('.marks-quarter-tab[data-quarter="' + quarterValue + '"]').addClass('active');
    }

    function activateAssessment(assessmentId) {
        $('#assessment_id').val(assessmentId);
        $('#form_assessment_id').val(assessmentId);
        $('.marks-assessment-tab').removeClass('active');
        $('.marks-assessment-tab[data-assessment-id="' + assessmentId + '"]').addClass('active');
    }

    function updateAssessmentQuarterTabs() {
        var selectedClassId = String($('#class_id').val() || '');
        var visibleQuarters = [];

        if (!selectedClassId) {
            $('#assessmentQuarterTabsWrapper, #assessmentTabsWrapper').addClass('d-none');
            $('#assessmentQuarterTabsEmptyState, #assessmentTabsEmptyState').addClass('d-none');
            $('.marks-quarter-tab, .marks-assessment-tab').removeClass('active').addClass('d-none');
            $('#assessment_id, #assessment_quarter, #form_assessment_id').val('');
            return { visibleQuarters: [], selectedQuarterVisible: false };
        }

        $('.marks-quarter-tab').each(function() {
            var $tab = $(this);
            var quarterValue = String($tab.data('quarter'));

            $tab.removeClass('d-none');
            visibleQuarters.push(quarterValue);
        });

        var selectedQuarter = String($('#assessment_quarter').val() || '');
        var selectedQuarterVisible = visibleQuarters.includes(selectedQuarter);
        $('#assessmentQuarterTabsWrapper').removeClass('d-none');
        $('#assessmentQuarterTabsEmptyState').toggleClass('d-none', visibleQuarters.length > 0);

        if (!selectedQuarterVisible) {
            $('#assessment_quarter').val('');
        }

        return { visibleQuarters: visibleQuarters, selectedQuarterVisible: selectedQuarterVisible };
    }

    function updateAssessmentTabs() {
        var selectedClassId = String($('#class_id').val() || '');
        var selectedQuarter = String($('#assessment_quarter').val() || '');
        var selectedAssessmentId = String($('#assessment_id').val() || '');
        var visibleAssessments = [];

        if (!selectedClassId || !selectedQuarter) {
            $('#assessmentTabsWrapper').addClass('d-none');
            $('#assessmentTabsEmptyState').addClass('d-none');
            $('#marksWorkspace').addClass('d-none');
            $('.marks-assessment-tab').removeClass('active').addClass('d-none');
            $('#assessment_id, #form_assessment_id').val('');
            return { visibleAssessments: [], selectedAssessmentVisible: false };
        }

        $('.marks-assessment-tab').each(function() {
            var $tab = $(this);
            var classId = String($tab.data('class-id'));
            var quarterValue = String($tab.data('quarter'));
            var assessmentId = String($tab.data('assessment-id'));
            var isVisible = classId === selectedClassId && quarterValue === selectedQuarter;

            $tab.toggleClass('d-none', !isVisible);

            if (isVisible) {
                visibleAssessments.push(assessmentId);
            } else if (assessmentId === selectedAssessmentId) {
                $tab.removeClass('active');
            }
        });

        var selectedAssessmentVisible = visibleAssessments.includes(selectedAssessmentId);
        $('#assessmentTabsWrapper').removeClass('d-none');
        $('#assessmentTabsEmptyState').toggleClass('d-none', visibleAssessments.length > 0);
        $('#marksWorkspace').toggleClass('d-none', visibleAssessments.length === 0);

        if (!selectedAssessmentVisible) {
            $('#assessment_id, #form_assessment_id').val('');
        }

        return { visibleAssessments: visibleAssessments, selectedAssessmentVisible: selectedAssessmentVisible };
    }

    function loadMarks(classId, assessmentId) {
        if(!classId || !assessmentId){
            return;
        }

        var currentClassId = '<?php echo isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0; ?>';
        var currentAssessmentId = '<?php echo isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0; ?>';
        var targetClassId = String(classId);
        var targetAssessmentId = String(assessmentId);

        if (currentClassId === targetClassId && currentAssessmentId === targetAssessmentId) {
            return;
        }

        $('#form_assessment_id').val(assessmentId);
        location.href = './index.php?page=assessments&tab=marks&class_id=' + encodeURIComponent(classId) + '&assessment_id=' + encodeURIComponent(assessmentId);
    }

    function initializeAssessmentTabs(autoPickFirst) {
        var quarterResult = updateAssessmentQuarterTabs();
        var currentQuarter = String($('#assessment_quarter').val() || '');
        var quarterAutoSelected = false;

        if (!quarterResult.selectedQuarterVisible && quarterResult.visibleQuarters.length > 0) {
            currentQuarter = quarterResult.visibleQuarters[0];
            activateAssessmentQuarter(currentQuarter);
            quarterAutoSelected = true;
        }

        var assessmentResult = updateAssessmentTabs();
        var currentAssessmentId = String($('#assessment_id').val() || '');
        var assessmentAutoSelected = false;

        if (!assessmentResult.selectedAssessmentVisible && assessmentResult.visibleAssessments.length > 0) {
            currentAssessmentId = assessmentResult.visibleAssessments[0];
            activateAssessment(currentAssessmentId);
            assessmentAutoSelected = true;
        }

        return {
            quarterResult: quarterResult,
            assessmentResult: assessmentResult,
            currentAssessmentId: currentAssessmentId,
            quarterAutoSelected: quarterAutoSelected,
            assessmentAutoSelected: assessmentAutoSelected
        };
    }

    $(function(){
        initializeAssessmentTabs(false);

        $('#class_id').change(function(){
            $('#assessment_quarter').val('');
            $('#assessment_id, #form_assessment_id').val('');
            var initResult = initializeAssessmentTabs(true);
            if (initResult.currentAssessmentId && (initResult.quarterAutoSelected || initResult.assessmentAutoSelected)) {
                loadMarks($(this).val(), initResult.currentAssessmentId);
            }
        });

        $(document).on('click', '.marks-quarter-tab', function(){
            var quarterValue = String($(this).data('quarter'));
            var selectedClassId = $('#class_id').val();
            var currentQuarter = String($('#assessment_quarter').val() || '');
            if (currentQuarter === quarterValue) {
                return;
            }
            activateAssessmentQuarter(quarterValue);
            $('#assessment_id, #form_assessment_id').val('');
            var initResult = initializeAssessmentTabs(true);
            if (selectedClassId && initResult.currentAssessmentId) {
                loadMarks(selectedClassId, initResult.currentAssessmentId);
            }
        });

        // Double-click a quarter tab to rename it inline
        $(document).on('dblclick', '.marks-quarter-tab', function(){
            var $tab = $(this);
            if ($tab.data('editing')) {
                return;
            }
            var quarterValue = String($tab.data('quarter'));
            var originalName = $.trim($tab.text());
            var classId = $('#class_id').val() || '<?php echo (int)($selected_class_for_quarters ?? 0); ?>';
            if (!classId || !quarterValue || !originalName) {
                return;
            }

            var $input = $('<input type="text" class="form-control form-control-sm quarter-edit-tab" maxlength="80" aria-label="Rename quarter" title="Press Enter to save, Esc to cancel">').val(originalName);
            $tab.addClass('d-none').data('editing', true).after($input);
            $input.trigger('focus').trigger('select');

            function closeEditor() {
                $input.off('blur keydown').remove();
                $tab.removeData('editing').removeClass('d-none');
            }

            function saveName() {
                var newName = $.trim($input.val());
                if (!newName || newName === originalName) {
                    closeEditor();
                    return;
                }
                $.post('./Actions.php?a=update_quarter', { class_id: classId, quarter: quarterValue, name: newName }, function(response){
                    if (response.status === 'success') {
                        $('.marks-quarter-tab[data-quarter="' + quarterValue + '"]').text(newName);
                        $('#selectedQuarterName').text(newName);
                    } else {
                        alert(response.msg || 'Unable to rename quarter.');
                    }
                    closeEditor();
                }, 'json').fail(function(){
                    alert('Network error while renaming the quarter.');
                    closeEditor();
                });
            }

            $input.on('keydown', function(event){
                if (event.key === 'Enter') {
                    event.preventDefault();
                    $input.trigger('blur');
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    $input.val(originalName).trigger('blur');
                }
            });
            $input.on('blur', saveName);
        });

        // Delete assessment button click handler
        $(document).on('click', '.delete-assessment-btn', function(e){
            e.stopPropagation(); // Prevent triggering the tab click
            var assessmentId = $(this).data('assessment-id');
            var assessmentName = $(this).siblings('.marks-assessment-tab').text().trim();

            showConfirm('Delete Assessment', 'Are you sure you want to delete the assessment "' + assessmentName + '"? This action cannot be undone.', function(){
                $.post('./Actions.php?a=delete_assessment', { id: assessmentId }, function(response){
                    if (response.status === 'success') {
                        location.reload();
                    } else {
                        alert(response.msg || 'Unable to delete assessment.');
                    }
                }, 'json').fail(function(){
                    alert('Network error while deleting the assessment.');
                });
            });
        });

        // Delete quarter button click handler
        $(document).on('click', '.delete-quarter-btn', function(e){
            e.stopPropagation(); // Prevent triggering the tab click
            var quarterValue = String($(this).data('quarter'));
            var quarterName = $.trim($(this).siblings('.marks-quarter-tab').text());
            var classId = String($('#class_id').val() || '');
            if (!classId || !quarterValue) {
                return;
            }

            showConfirm('Delete Quarter', 'Are you sure you want to delete the quarter "' + quarterName + '"? This will also permanently remove its assessments, marks, and related grade records. This action cannot be undone.', function(){
                $.post('./Actions.php?a=delete_quarter', { class_id: classId, quarter: quarterValue }, function(response){
                    if (response.status === 'success') {
                        // After a successful delete, reload showing the first remaining quarter
                        // and its first assessment so the marks workspace renders immediately.
                        $.get('./Actions.php?a=get_quarters', { class_id: classId }, function(qResponse){
                            var quarters = (qResponse && qResponse.status === 'success' && qResponse.data) ? qResponse.data : [];
                            var targetUrl = './index.php?page=assessments&tab=marks&class_id=' + encodeURIComponent(classId);
                            if (quarters.length > 0) {
                                var firstQuarter = String(quarters[0].quarter);
                                targetUrl += '&quarter=' + encodeURIComponent(firstQuarter);
                                // Pick the first assessment that belongs to the selected class and the
                                // first remaining quarter (assessment tabs are pre-rendered server-side).
                                var $firstAssessmentTab = $('.marks-assessment-tab[data-class-id="' + classId + '"][data-quarter="' + firstQuarter + '"]').first();
                                if ($firstAssessmentTab.length) {
                                    targetUrl += '&assessment_id=' + encodeURIComponent(String($firstAssessmentTab.data('assessment-id')));
                                }
                            }
                            location.href = targetUrl;
                        }, 'json').fail(function(){
                            // Fall back to the previous behavior if the quarter lookup fails
                            location.href = './index.php?page=assessments&tab=marks&class_id=' + encodeURIComponent(classId);
                        });
                    } else {
                        alert(response.msg || 'Unable to delete quarter.');
                    }
                }, 'json').fail(function(){
                    alert('Network error while deleting the quarter.');
                });
            });
        });

        // Show delete button on hover for active tabs only
        $(document).on('mouseenter', '.assessment-tab-wrapper', function(){
            var $tab = $(this).find('.marks-assessment-tab');
            if ($tab.hasClass('active')) {
                $(this).find('.delete-assessment-btn').css('display', 'flex').css('opacity', '1');
            }
        }).on('mouseleave', '.assessment-tab-wrapper', function(){
            $(this).find('.delete-assessment-btn').css('display', 'none').css('opacity', '0');
        });

        // Show delete button on hover for the active quarter tab only
        $(document).on('mouseenter', '.quarter-tab-wrapper', function(){
            var $tab = $(this).find('.marks-quarter-tab');
            if ($tab.hasClass('active')) {
                $(this).find('.delete-quarter-btn').css('display', 'flex').css('opacity', '1');
            }
        }).on('mouseleave', '.quarter-tab-wrapper', function(){
            $(this).find('.delete-quarter-btn').css('display', 'none').css('opacity', '0');
        });

        // Double-click an assessment tab to edit component, name, percentage, and HPS
        $(document).on('dblclick', '.marks-assessment-tab', function(){
            var $tab = $(this);
            if ($tab.data('editing')) {
                return;
            }
            var assessmentId = String($tab.data('assessment-id'));
            var classId = String($tab.data('class-id'));
            var quarterValue = String($tab.data('quarter'));
            var originalText = $.trim($tab.text());

            if (!assessmentId || !classId) {
                return;
            }

            // Parse the original text to extract name and component
            var parts = originalText.split(' - ');
            var originalName = parts[0] || '';
            var originalComponent = parts[1] || '';

            // Get current assessment data
            $.get('./Actions.php?a=get_assessment&id=' + assessmentId, function(assessmentData){
                if (!assessmentData || assessmentData.status !== 'success') {
                    alert('Unable to load assessment data.');
                    return;
                }

                        var assessment = assessmentData.data;
                var originalPercentage = assessment.percentage || 0;
                var originalHps = assessment.hps || 0;
                var originalComponentId = String(assessment.component_id || '');

                // Create edit form matching the add assessment structure
                var $editForm = $('<div class="input-group input-group-sm mt-2 assessment-create-form">');

                // Component dropdown
                $editForm.append('<select id="editComponent" class="form-select" aria-label="Assessment component"><option value="">Component</option></select>');

                // Name input
                $editForm.append('<input type="text" id="editName" class="form-control" placeholder="Assessment name" maxlength="120" value="' + originalName + '">');

                // Percentage input (readonly like in add form)
                $editForm.append('<input type="number" id="editPercentage" class="form-control" placeholder="Auto %" min="0" max="100" step="0.01" readonly value="' + originalPercentage + '">');

                // HPS input
                $editForm.append('<input type="number" id="editHps" class="form-control" placeholder="HPS" min="0" step="any" value="' + originalHps + '">');

                // Cancel button
                $editForm.append('<button type="button" id="cancelAssessmentEdit" class="btn btn-secondary" title="Cancel"><i class="fas fa-times"></i></button>');

                // Save button
                $editForm.append('<button type="button" id="saveAssessmentEdit" class="btn btn-primary" title="Save assessment"><i class="fas fa-save"></i> Save</button>');

                // Hide tab and show edit form
                $tab.addClass('d-none').data('editing', true);
                $tab.closest('.assessment-tab-wrapper').after($editForm);

                // Load components for the selected class
                $.post('./Actions.php?a=get_components_for_class', { class_id: classId }, function(componentsResponse){
                    if (componentsResponse.status === 'success' && componentsResponse.data) {
                        var $componentSelect = $('#editComponent');
                        $componentSelect.empty().append('<option value="">Select Component</option>');
                        componentsResponse.data.forEach(function(comp){
                            $componentSelect.append('<option value="' + comp.component_id + '" ' + (String(comp.component_id) === originalComponentId ? 'selected' : '') + '>' + comp.name + '</option>');
                        });
                    }
                }, 'json');

                function closeEditor() {
                    $editForm.remove();
                    $tab.removeData('editing').removeClass('d-none');
                }

                function saveAssessment() {
                    var newComponentId = $('#editComponent').val();
                    var newName = $.trim($('#editName').val());
                    var newPercentage = parseFloat($('#editPercentage').val()) || 0;
                    var newHps = parseFloat($('#editHps').val()) || 0;

                    if (!newComponentId || !newName) {
                        alert('Component and Name are required.');
                        return;
                    }

                    $.post('./Actions.php?a=save_assessment', {
                        id: assessmentId,
                        class_id: classId,
                        component_id: newComponentId,
                        quarter: quarterValue,
                        name: newName,
                        percentage: newPercentage,
                        hps: newHps
                    }, function(response){
                        if (response.status === 'success') {
                            // Reload page after successful update
                            location.reload();
                        } else {
                            alert(response.msg || 'Unable to update assessment.');
                        }
                    }, 'json').fail(function(){
                        alert('Network error while updating the assessment.');
                    });
                }

                $('#saveAssessmentEdit').on('click', saveAssessment);
                $('#cancelAssessmentEdit').on('click', closeEditor);

                // Handle Enter key to save, Escape key to cancel
                $editForm.find('input, select').on('keydown', function(event){
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        saveAssessment();
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        closeEditor();
                    }
                });

                // Click outside to cancel (with delay to prevent immediate cancellation)
                setTimeout(function(){
                    $(document).on('click.assessmentEdit', function(event){
                        if (!$editForm.is(event.target) && $editForm.has(event.target).length === 0 && !$tab.is(event.target)) {
                            $(document).off('click.assessmentEdit');
                            closeEditor();
                        }
                    });
                }, 100);

                // Focus on name field
                $('#editName').trigger('focus').trigger('select');
            }, 'json').fail(function(){
                alert('Network error while loading assessment data.');
            });
        });

        $(document).on('click', '.marks-assessment-tab', function(){
            var assessmentId = String($(this).data('assessment-id'));
            var classId = $('#class_id').val();
            var currentAssessmentId = String($('#assessment_id').val() || '');
            if (currentAssessmentId === assessmentId) {
                return;
            }
            activateAssessment(assessmentId);
            if (classId && assessmentId) {
                loadMarks(classId, assessmentId);
            }
        });

        $('#filter').click(function(){
            var classId = $('#class_id').val();
            var assessmentId = $('#assessment_id').val();
            if(!classId || !assessmentId){
                alert('Please select a class and assessment first.');
                return;
            }
            loadMarks(classId, assessmentId);
        });


    // Add click handler for save button (removed - no longer needed)

    // Function to save marks via AJAX
    function saveMarks() {
        // Update original values to current values before saving
        $('input[name^="mark"]').each(function() {
            originalValues[$(this).attr('name')] = $(this).val();
        });
        hasUnsavedChanges = false;
        enableNavigation();

        // First validate marks against HPS
        if (!validateMarks()) {
            var el = $('<div>')
                .addClass('pop_msg alert alert-danger')
                .text('One or more marks exceed the Highest Possible Score (HPS). Please correct them before saving.');

            $('.pop_msg').remove();
            $('#marks-form').prepend(el);
            el.show('slow');
            return false;
        }

        var _this = $('#marks-form')
        var _this_btn = _this.find('button')
        _this_btn.attr('disabled', true)
        _this.find('.err-msg').remove()
        var el = $('<div>')
        el.addClass('pop_msg')
        el.hide()

        $.ajax({
            url: './Actions.php?a=save_mark',
            data: _this.serialize(),
            method: 'POST',
            dataType: 'json',
            error: function(err) {
                console.error(err)
                el.addClass('alert alert-danger')
                el.text('An error occurred while saving the marks.')
                _this.prepend(el)
                el.show('slow')
                _this_btn.attr('disabled', false)
            },
            success: function(resp) {
                if(!!resp.status && resp.status == 'success') {
                    $('#assessmentDate').trigger('change');
                    el.addClass('alert alert-success')
                    el.text(resp.msg)
                    _this.prepend(el)
                    el.show('slow')

                    // Update the HPS display if it changed
                    if(resp.hps) {
                        $('#hps').text(resp.hps);
                    }

                    // Hide success message after 1 second
                    setTimeout(function(){
                        el.hide('slow', function(){
                            el.remove()
                        })
                    }, 100)
                } else {
                    el.addClass('alert alert-danger')
                    el.text(resp.msg)
                    _this.prepend(el)
                    el.show('slow')
                }
                _this_btn.attr('disabled', false)
            }
        })
    }

    // Add keydown event listener to all input fields in the marks table
    $(document).on('keydown', '#marks-table input[type="number"]', function(e) {
        const fastEntryEnabled = document.getElementById('toggleEnterBehavior')?.getAttribute('aria-pressed') === 'true';
        if (e.key === 'Enter') {
            // Fast Single Entry: Enter (and Tab) should return to the search bar
            // so the next student can be found without losing the mobile keyboard.
            // This path is handled by the dedicated fast-entry handler below;
            // skip the normal down-column move when fast entry is ON.
            if (fastEntryEnabled) return;
            e.preventDefault();

            // Validate the current input
            var hps = parseFloat($('#hps').text()) || 0;
            var mark = parseFloat($(this).val()) || 0;

            if (mark > hps) {
                // Show error for this field
                $(this).addClass('is-invalid');
                $(`<div class="invalid-feedback">Mark cannot exceed HPS (${hps})</div>`).insertAfter($(this));
                return false;
            } else {
                $(this).removeClass('is-invalid');
                $(this).siblings('.invalid-feedback').remove();

                // Trigger auto-save before moving to next field
                autoSaveMarks();

                // Move to next input field
                var $next = $(this).closest('tr').next().find('input[type="number"]');
                if ($next.length) {
                    $next.focus();
                }
            }
        } else if (e.key === 'Shift') {
            // Check if fast single entry is enabled
            const fastEntryEnabled = document.getElementById('toggleEnterBehavior')?.getAttribute('aria-pressed') === 'true';
            
            if (fastEntryEnabled) {
                e.preventDefault();
                
                // Find the quick scores button for the first student in the current row
                const $row = $(this).closest('tr');
                const $quickScoresBtn = $row.find('.quick-scores-btn').first();
                
                if ($quickScoresBtn.length) {
                    $quickScoresBtn.click();
                }
            }
        } else if (e.key === 'Tab') {
            // Fast Single Entry: Tab is handled by the fast-entry handler
            // below (search-focus, mobile-friendly). Skip normal Excel-like
            // Tab move when fast entry is ON.
            const fastEntryEnabledTab = document.getElementById('toggleEnterBehavior')?.getAttribute('aria-pressed') === 'true';
            if (fastEntryEnabledTab) return;
            // Normal mode: Tab moves across inputs like Excel
            e.preventDefault();
            
            var $currentRow = $(this).closest('tr');
            var $allInputs = $('#marks-table input[type="number"]');
            var currentIndex = $allInputs.index($(this));
            var nextIndex = currentIndex + 1;
            
            if (nextIndex < $allInputs.length) {
                $allInputs.eq(nextIndex).focus();
            } else {
                // If at last input, focus on first input
                $allInputs.first().focus();
            }
        }
    });

    // Add validation on input blur
    $(document).on('blur', '#marks-table input[type="number"]', function() {
        var hps = parseFloat($('#hps').text()) || 0;
        var mark = parseFloat($(this).val()) || 0;

        if (mark > hps) {
            $(this).addClass('is-invalid');
            $(`<div class="invalid-feedback">Mark cannot exceed HPS (${hps})</div>`).insertAfter($(this));
        } else {
            $(this).removeClass('is-invalid');
            $(this).siblings('.invalid-feedback').remove();
        }
    });


    // Handle form submission
    $('#marks-form').on('submit', function(e) {
        e.preventDefault();
        saveMarks();
    });

    // Handle Enter key on select elements
    $(document).on('keydown', 'select', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var $next = $(this).closest('.form-group').next('.form-group').find('select, input[type!="hidden"], button');
            if ($next.length) {
                $next.focus();
            } else {
                $('#filter').click();
            }
        }
    });

    if($('#marks-table').length > 0){
        var table = $('#marks-table').DataTable({
            responsive: true,
            columnDefs: [
                { orderable: true, targets: [1] } // Disable sorting on Mark column
            ],
            pageLength: -1,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            dom: '<"top"f>rt<"bottom"lip><"clear">',
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search students... (Press Enter to focus on marks)",
                lengthMenu: "Show _MENU_ students per page"
            },
            initComplete: function() {
                // Focus on search input when table is initialized
                $('.dataTables_filter input').focus();
            }
        });

        // Toggle flag for Enter key behavior (default ON)
        var enterBehaviorEnabled = true;

        // Toggle button click handler
        $('#toggleEnterBehavior').on('click', function() {
            enterBehaviorEnabled = !enterBehaviorEnabled;
            var $btn = $(this);
            var $text = $btn.find('.toggle-enter-text');

            if (enterBehaviorEnabled) {
                $btn.addClass('on').attr('aria-pressed', 'true');

            } else {
                $btn.removeClass('on').attr('aria-pressed', 'false');

            }
        });

        // Handle search input keydown
        $('.dataTables_filter input').on('keydown', function(e) {
            if (!enterBehaviorEnabled) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                // Focus on first input in the table
                $('input[name^="mark"]').first().focus().select();
            }
        });

        // Export Excel functionality
        $('#exportMarks').on('click', function() {
            var classId = $('#class_id').val() || '<?php echo isset($_GET['class_id']) ? $_GET['class_id'] : ''; ?>';
            var assessmentId = $('#form_assessment_id').val() || '<?php echo isset($_GET['assessment_id']) ? $_GET['assessment_id'] : ''; ?>';
            
            if (!classId || !assessmentId) {
                alert('Please select a class and assessment first.');
                return;
            }

            // Get student data from the table
            var csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Student Name,Student Number,Mark,Percentage\n";
            
            $('#marks-table tbody tr').each(function() {
                var $row = $(this);
                var studentName = $row.find('h6').text().trim();
                var studentNumber = $row.find('small').text().replace('ID: ', '').trim();
                var mark = $row.find('input.mark-input').val();
                var percentage = $row.find('.badge').text().replace('%', '').trim();
                
                // Clean up student name (remove badges)
                studentName = studentName.split('!')[0].trim();
                
                csvContent += '"' + studentName + '",' + studentNumber + ',' + mark + ',' + percentage + '\n';
            });

            // Create download link
            var encodedUri = encodeURI(csvContent);
            var link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "marks_export_" + classId + "_" + assessmentId + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });

        // Handle input fields keydown — Fast Single Entry: Enter *or* Tab
        // returns to the search bar (cleared) so the next student can be
        // typed without dismissing the mobile keyboard.
        $(document).on('keydown', 'input[name^="mark"]', function(e) {
            if (!enterBehaviorEnabled) return;
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                try { autoSaveMarks(); } catch(err) {}
                // Clear and focus on search input — Tab behaves like Enter here
                var searchInput = $('.dataTables_filter input');
                searchInput.val('').focus();
                table.search('').draw();
            }
        });
    }

    // Clear button click handler
    $('#clearMarks').click(function() {
        clearAllMarks();
    });

    // Add-to-existing-marks handler: adds the entered value on top of each student's current mark
    function applyAddMarks() {
        var addValue = parseFloat($('#addMarksValue').val());
        if (isNaN(addValue) || addValue === 0) {
            alert('Please enter a value to add to the existing marks.');
            return;
        }

        var changed = 0;
        $('#marks-table input[name="mark[]"]').not('[readonly]').each(function() {
            var $input = $(this);
            var hps = parseFloat($input.data('hps')) || 0;
            var current = parseFloat($input.val()) || 0;
            var newValue = Math.min(current + addValue, hps > 0 ? hps : current + addValue);
            if (newValue !== current) {
                $input.val(newValue).trigger('input');
                updatePercentage($input[0]);
                changed++;
            }
        });

        if (changed > 0) {
            hasUnsavedChanges = true;
            setTimeout(() => autoSaveMarks(), 100);
        }
        $('#addMarksValue').val('');
    }

    $('#applyAddMarks').click(applyAddMarks);
    $('#addMarksValue').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyAddMarks();
        }
    });

    if('<?php echo isset($_GET['class_id']) && isset($_GET['assessment_id']) ?>' == 1){
        // Clear marks when assessment changes
        $('#assessment_id').change(function() {
            clearAllMarks(true); // Skip auto-save when assessment changes
        });

        $('#marks-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove()
            var hps = parseFloat($('#hps').text())
            if($('input[name="mark[]"]').length <= 0 ){
                alert("No Student is listed yet in selected class.")
                return false;
            }
            $('input[name="mark[]"]').removeClass('border-danger')
            $('input[name="mark[]"]').each(function(){
                if(parseFloat($(this).val()) > hps){
                    $(this).addClass('border-danger')
                }
            })
            if($('input.border-danger[name="mark[]"]').length > 0){
                alert("Entered mark is greater than Heighest Possible Score.")
                return false;
            }
            var _this = $(this)
            var _el = $('<div>')
                _el.addClass('pop_msg')
            _this.find('button').attr('disabled',true)
            _this.find('button[type="submit"]').text('submitting form...')
            $.ajax({
                url:'./Actions.php?a=save_mark',
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
                error:err=>{
                    console.log(err)
                    _el.addClass('alert alert-danger')
                    _el.text("An error occurred.")
                    _this.prepend(_el)
                    _el.show('slow')
                     _this.find('button').attr('disabled',false)
                     _this.find('button[type="submit"]').text('Save')
                },
                success:function(resp){
                    if(resp.status == 'success'){
                            $('#assessmentDate').trigger('change');
                        _el.addClass('alert alert-success')
                        $('#uni_modal').on('hide.bs.modal',function(){
                            location.reload()
                        })
                    }else{
                        _el.addClass('alert alert-danger')
                    }
                    _el.text(resp.msg)

                    _el.hide()
                    _this.prepend(_el)
                    _el.show('slow')
                     _this.find('button').attr('disabled',false)
                     _this.find('button[type="submit"]').text('Save')
                }
            })
        })
        }
    })

    // Assessment Date Save Functionality (auto-save + manual Save button)
    $(document).ready(function() {
        var assessmentDateTimeout;
        var savedAssessmentDate = $('#assessmentDate').val();

        function performAssessmentDateSave(assessmentId, selectedDate) {
            var $saveStatus = $('#dateSaveStatus');
            var $saveBtn = $('#saveAssessmentDateBtn');
            var $icon = $saveBtn.find('.save-btn-icon');
            var $label = $saveBtn.find('.save-btn-label');
            var originalIcon = $icon.html();
            var originalLabel = $label.text();

            $saveStatus.removeClass('text-success text-danger')
                      .addClass('text-warning')
                      .html('<i class="fas fa-spinner fa-spin"></i> Saving...')
                      .show();
            $saveBtn.prop('disabled', true).addClass('is-saving');
            $icon.html('<i class="fas fa-spinner fa-spin"></i>');
            $label.text('Saving');

            $.ajax({
                url: 'Actions.php?a=save_assessment_date',
                method: 'POST',
                data: {
                    assessment_id: assessmentId,
                    date: selectedDate
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        savedAssessmentDate = selectedDate;
                        $saveStatus.removeClass('text-warning')
                                  .addClass('text-success')
                                  .html('<i class="fas fa-check-circle"></i> Date saved successfully');
                        $icon.html('<i class="fas fa-check"></i>');
                        $label.text('Saved');

                        // Hide success message after 3 seconds
                        setTimeout(function() {
                            $saveStatus.fadeOut();
                        }, 3000);
                        // Revert button label after a short confirmation
                        setTimeout(function() {
                            $icon.html(originalIcon);
                            $label.text(originalLabel);
                        }, 1600);
                    } else {
                        $saveStatus.removeClass('text-warning')
                                  .addClass('text-danger')
                                  .html('<i class="fas fa-exclamation-circle"></i> Error saving date');
                        $icon.html(originalIcon);
                        $label.text(originalLabel);

                        // Hide error message after 5 seconds
                        setTimeout(function() {
                            $saveStatus.fadeOut();
                        }, 5000);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Date Save AJAX Error:', error);
                    $saveStatus.removeClass('text-warning')
                              .addClass('text-danger')
                              .html('<i class="fas fa-exclamation-circle"></i> Network error');
                    $icon.html(originalIcon);
                    $label.text(originalLabel);

                    // Hide error message after 5 seconds
                    setTimeout(function() {
                        $saveStatus.fadeOut();
                    }, 5000);
                },
                complete: function() {
                    $saveBtn.prop('disabled', false).removeClass('is-saving');
                }
            });
        }

        $('#assessmentDate').on('change', function() {
            var $input = $(this);
            var assessmentId = $input.data('assessment-id');
            var selectedDate = $input.val();
            var $saveStatus = $('#dateSaveStatus');

            if (!assessmentId) {
                console.log('No assessment ID found');
                return;
            }

            if (selectedDate === savedAssessmentDate) {
                return;
            }

            // Clear any existing timeout
            clearTimeout(assessmentDateTimeout);

            // Show saving indicator
            $saveStatus.removeClass('text-success text-danger')
                      .addClass('text-warning')
                      .html('<i class="fas fa-spinner fa-spin"></i> Saving...')
                      .show();

            // Auto-save after 1 second delay (to prevent excessive database calls)
            assessmentDateTimeout = setTimeout(function() {
                performAssessmentDateSave(assessmentId, selectedDate);
            }, 1000); // 1 second delay
        });

        // Manual Save button for the assessment date
        $(document).on('click', '#saveAssessmentDateBtn', function(e) {
            e.preventDefault();
            var $input = $('#assessmentDate');
            var assessmentId = $input.data('assessment-id');
            var selectedDate = $input.val();

            if (!assessmentId) {
                console.log('No assessment ID found');
                return;
            }
            if (!selectedDate) {
                alert('Please select an assessment date first.');
                return;
            }

            clearTimeout(assessmentDateTimeout);
            performAssessmentDateSave(assessmentId, selectedDate);
        });
    });

    $(function() {
        var selectedClassId = '<?php echo $selected_class_for_quarters ?? 0; ?>';
        var selectedQuarter = '<?php echo $selected_assessment_quarter ?? 0; ?>';

        function loadInlineComponents(classId) {
            if (!classId) {
                $('#newAssessmentComponent').html('<option value="">Component</option>');
                return;
            }

            $.post('./Actions.php?a=get_components_for_class', { class_id: classId }, function(response) {
                var options = '<option value="">Component</option>';
                if (response.status === 'success') {
                    response.data.forEach(function(component) {
                        options += '<option value="' + component.component_id + '">' + $('<div>').text(component.name).html() + '</option>';
                    });
                }
                $('#newAssessmentComponent').html(options);
            }, 'json');
        }

        function loadInlineQuarters(classId, autoLoadFirstAssessment) {
            if (!classId) {
                $('#assessmentQuarterTabs, #assessmentTabs').empty();
                $('#assessmentQuarterTabsWrapper, #assessmentTabsWrapper').addClass('d-none');
                $('#assessmentQuarterTabsEmptyState, #assessmentTabsEmptyState, #assessmentCreateFields').addClass('d-none');
                return;
            }

            $.get('./Actions.php?a=get_quarters', { class_id: classId }, function(response) {
                var quarterTabs = '';
                var quarters = response.status === 'success' ? response.data : [];
                if (quarters.length) {
                    quarters.forEach(function(item) {
                        quarterTabs += '<div class="quarter-tab-wrapper">' +
                            '<button type="button" class="nav-link report-class-tab marks-quarter-tab' +
                            (String(item.quarter) === String(selectedQuarter) ? ' active' : '') +
                            '" data-quarter="' + item.quarter + '">' + $('<div>').text(item.name).html() + '</button>' +
                            '<button type="button" class="delete-quarter-btn" data-quarter="' + item.quarter + '" title="Delete quarter">' +
                            '<i class="fas fa-trash-alt"></i></button>' +
                            '</div>';
                    });
                }
                quarterTabs += '<input type="text" id="newQuarterName" class="quarter-create-tab" placeholder="+" maxlength="80" title="Type a quarter name and press Enter" aria-label="New quarter name">';
                $('#assessmentQuarterTabs').html(quarterTabs);
                $('#assessmentQuarterTabsWrapper').removeClass('d-none');
                $('#assessmentQuarterTabsEmptyState').toggleClass('d-none', quarters.length > 0);
                // Remember this class so the Reports landing dropdown can reuse its
                // custom quarter names (renames included) even without URL params.
                try { localStorage.setItem('rq_last_class_id', String(classId)); } catch (e) {}
                if (!selectedQuarter && response.data && response.data.length) {
                    selectedQuarter = response.data[0].quarter;
                    $('#assessment_quarter').val(selectedQuarter);
                    $('.marks-quarter-tab').first().addClass('active');
                }
                updateInlineAssessmentForm();

                if (autoLoadFirstAssessment && selectedQuarter) {
                    var assessmentResult = updateAssessmentTabs();
                    if (assessmentResult.visibleAssessments.length) {
                        loadMarks(classId, assessmentResult.visibleAssessments[0]);
                    }
                }
            }, 'json');
        }

        function updateInlineAssessmentForm() {
            var classId = $('#class_id').val() || selectedClassId;
            var quarter = $('#assessment_quarter').val() || selectedQuarter;
            $('#assessmentQuarterTabsWrapper').toggleClass('d-none', !classId);
            $('#assessmentTabsWrapper').toggleClass('d-none', !classId || !quarter);
            $('#assessmentCreateFields').addClass('d-none');
            loadInlineComponents(classId);
            updateInlinePercentage(classId, quarter);
        }

        function updateInlinePercentage(classId, quarter) {
            var componentId = $('#newAssessmentComponent').val();
            if (!classId || !quarter || !componentId) {
                $('#newAssessmentPercentage').val('');
                return;
            }

            $.post('./Actions.php?a=get_component_suggested_percentage', {
                class_id: classId,
                component_id: componentId,
                quarter: quarter
            }, function(response) {
                if (response.status === 'success') {
                    $('#newAssessmentPercentage').val(response.suggested);
                }
            }, 'json');
        }

        $('#class_id').on('change', function() {
            selectedClassId = $(this).val();
            selectedQuarter = '';
            $('#assessment_quarter').val('');
            loadInlineQuarters(selectedClassId, true);
            updateInlineAssessmentForm();
        });

        $(document).on('click', '.marks-quarter-tab', function() {
            selectedQuarter = String($(this).data('quarter'));
            $('#assessment_quarter').val(selectedQuarter);
            $('#assessmentCreateFields').addClass('d-none');
            updateInlineAssessmentForm();
        });

        $(document).on('click', '#newAssessmentTab', function() {
            var classId = $('#class_id').val() || selectedClassId;
            var quarter = $('#assessment_quarter').val() || selectedQuarter;
            if (!classId || !quarter) {
                return;
            }

            var isOpen = !$('#assessmentCreateFields').hasClass('d-none');
            $('#assessmentCreateFields').toggleClass('d-none', isOpen);
            if (!isOpen) {
                $('#newAssessmentName').trigger('focus');
            }
        });

        $(document).on('click', '.marks-assessment-tab', function() {
            $('#assessmentCreateFields').addClass('d-none');
        });

        $(document).on('change', '#newAssessmentComponent', function() {
            updateInlinePercentage($('#class_id').val() || selectedClassId, $('#assessment_quarter').val() || selectedQuarter);
        });

        $(document).on('keydown', '#newQuarterName', function(event) {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            var name = $('#newQuarterName').val().trim();
            if (!selectedClassId || !name) {
                if (!selectedClassId) {
                    alert('Select a class first.');
                }
                return;
            }

            // Save to localStorage
            saveQuarterNameToHistory(name);

            $.post('./Actions.php?a=save_quarter', { class_id: selectedClassId, name: name }, function(response) {
                if (response.status === 'success') {
                    location.href = './index.php?page=assessments&tab=marks&class_id=' + encodeURIComponent(selectedClassId) + '&quarter=' + response.quarter.quarter;
                } else {
                    alert(response.msg || 'Unable to create quarter.');
                }
            }, 'json');
        });

        // localStorage functions for quarter name history
        function saveQuarterNameToHistory(name) {
            try {
                var history = getQuarterNameHistory();
                if (!history.includes(name)) {
                    history.unshift(name);
                    if (history.length > 10) history.pop(); // Keep only last 10
                    localStorage.setItem('quarter_name_history', JSON.stringify(history));
                }
            } catch (e) {}
        }

        function getQuarterNameHistory() {
            try {
                var history = localStorage.getItem('quarter_name_history');
                return history ? JSON.parse(history) : [];
            } catch (e) {
                return [];
            }
        }

        // Show dropdown with quarter name suggestions
        $('#newQuarterName').on('focus', function() {
            var history = getQuarterNameHistory();
            if (history.length > 0) {
                showQuarterDropdown(history);
            }
        });

        $('#newQuarterName').on('input', function() {
            var value = $(this).val().trim();
            if (value.length > 0) {
                var history = getQuarterNameHistory();
                var filtered = history.filter(function(name) {
                    return name.toLowerCase().includes(value.toLowerCase());
                });
                if (filtered.length > 0) {
                    showQuarterDropdown(filtered);
                } else {
                    hideQuarterDropdown();
                }
            } else {
                hideQuarterDropdown();
            }
        });

        function showQuarterDropdown(names) {
            var dropdown = $('#quarterNameDropdown');
            dropdown.empty();
            names.forEach(function(name) {
                dropdown.append('<div class="dropdown-item" style="padding: 8px 12px; cursor: pointer; transition: background 0.2s;" data-name="' + name + '">' + name + '</div>');
            });
            dropdown.show();
            
            // Add hover effect
            dropdown.find('.dropdown-item').hover(
                function() { $(this).css('background', '#f1f5f9'); },
                function() { $(this).css('background', 'transparent'); }
            );
        }

        function hideQuarterDropdown() {
            $('#quarterNameDropdown').hide();
        }

        // Handle dropdown item clicks
        $(document).on('click', '#quarterNameDropdown .dropdown-item', function() {
            var name = $(this).data('name');
            $('#newQuarterName').val(name);
            hideQuarterDropdown();
            $('#newQuarterName').focus();
        });

        // Hide dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#newQuarterName').length && !$(e.target).closest('#quarterNameDropdown').length) {
                hideQuarterDropdown();
            }
        });

        function submitInlineAssessment() {
            var classId = $('#class_id').val() || selectedClassId;
            var quarter = $('#assessment_quarter').val() || selectedQuarter;
            var componentId = $('#newAssessmentComponent').val();
            var name = $('#newAssessmentName').val().trim();
            var percentage = $('#newAssessmentPercentage').val();
            var hps = $('#newAssessmentHps').val();

            if (!classId || !quarter || !componentId || !name || !percentage || !hps) {
                alert('Complete the quarter, component, assessment name, percentage, and HPS fields.');
                return;
            }

            $('#assessmentCreateFields').addClass('d-none');

            $.post('./Actions.php?a=save_assessment', {
                id: '',
                class_id: classId,
                component_id: componentId,
                quarter: quarter,
                name: name,
                percentage: percentage,
                hps: hps,
                manual_percentage: 1
            }, function(response) {
                if (response.status === 'success' && response.assessment_data) {
                    location.href = './index.php?page=assessments&tab=marks&class_id=' + encodeURIComponent(classId) +
                        '&quarter=' + encodeURIComponent(quarter) + '&assessment_id=' + encodeURIComponent(response.assessment_data.assessment_id);
                } else {
                    alert(response.msg || 'Unable to create assessment.');
                }
            }, 'json');
        }

        $('#createAssessment').on('click', submitInlineAssessment);

        $(document).on('keydown', '#assessmentCreateFields input, #assessmentCreateFields select', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitInlineAssessment();
            }
        });

        loadInlineQuarters(selectedClassId, false);
        updateInlineAssessmentForm();
    });
</script>

<!-- Page shell behaviour: close the Output dropdown once an item is used.
     Bootstrap only auto-closes menus whose items are anchors carrying
     data-bs-toggle="dropdown", so the <button class="dropdown-item"> entries
     in #mkOutputMenu need this. -->
<script>
    (function () {
        'use strict';
        $(document).on('click', '#mkOutputMenu .dropdown-item', function () {
            var btn = document.getElementById('mkOutputBtn');
            if (btn && window.bootstrap) {
                var dd = bootstrap.Dropdown.getInstance(btn);
                if (dd) dd.hide();
            }
        });
    })();
</script>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalMessage">
                Are you sure you want to proceed?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalConfirm">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global confirmation function using modal
function showConfirm(title, message, onConfirm) {
    $('#confirmModalTitle').text(title);
    $('#confirmModalMessage').text(message);

    var confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));

    $('#confirmModalConfirm').off('click').on('click', function() {
        confirmModal.hide();
        if (onConfirm) onConfirm();
    });

    confirmModal.show();
}
</script>


<!-- Quick Scores Average Modal -->
<style>
    #quickScoresModal .raw-score-row { animation: qsFadeIn 0.15s ease; }
    @keyframes qsFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
    #quickScoresModal .raw-score-input { font-weight: bold; }
    #quickScoresAverage { font-size: 1.4rem; }
</style>
<div class="modal fade" id="quickScoresModal" tabindex="-1" aria-labelledby="quickScoresModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="quickScoresModalLabel">
                    <i class="fas fa-calculator me-1 text-primary"></i> Raw Scores
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2">
                <div id="quickScoresStudentName" class="fw-bold small mb-2 text-muted"></div>
                <div id="rawScoresContainer" class="mb-2">
                    <div class="input-group input-group-sm raw-score-row mb-1">
                        <span class="input-group-text bg-light" style="font-size: 11px;">1.</span>
                        <input type="number" step="any" min="0" class="form-control raw-score-input" placeholder="Score">
                    </div>
                </div>
                <button type="button" id="addRawScoreBtn" class="btn btn-sm btn-outline-primary" title="Add another raw score">
                    <i class="fas fa-plus"></i> Add Score
                </button>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Average</span>
                    <span id="quickScoresAverage" class="fw-bold text-primary">0</span>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="applyQuickScoresBtn" class="btn btn-sm btn-primary">
                    <i class="fas fa-check me-1"></i> Apply <span class="small">(Enter)</span>
                </button>
            </div>
        </div>
    </div>
</div>
<script>
    (function(){
        let quickScoresModal = null;
        let targetMarkInput = null;
        let targetStudentId = null;

        // Storage key per assessment + student so raw scores survive page reloads
        function storageKey() {
            const assessmentId = (document.getElementById('form_assessment_id') || {}).value || '0';
            return 'qs_raw_' + assessmentId + '_' + targetStudentId;
        }
        
        // Storage key for number of input boxes per assessment (global across students)
        function rowCountKey() {
            const assessmentId = (document.getElementById('form_assessment_id') || {}).value || '0';
            return 'qs_rowcount_' + assessmentId;
        }
        
        // Get stored row count for this assessment
        function getRowCount() {
            try {
                const count = localStorage.getItem(rowCountKey());
                return count ? parseInt(count, 10) : 1;
            } catch (e) { return 1; }
        }
        
        // Save row count for this assessment
        function saveRowCount(count) {
            try {
                localStorage.setItem(rowCountKey(), count.toString());
            } catch (e) { /* storage unavailable - ignore */ }
        }

        function saveRawScores() {
            try {
                const values = [];
                document.querySelectorAll('#rawScoresContainer .raw-score-input').forEach(function(inp){
                    values.push(inp.value);
                });
                localStorage.setItem(storageKey(), JSON.stringify(values));
            } catch (e) { /* storage unavailable - ignore */ }
        }

        function loadRawScores() {
            try {
                const raw = localStorage.getItem(storageKey());
                return raw ? JSON.parse(raw) : null;
            } catch (e) { return null; }
        }

        function computeAverage() {
            let sum = 0, count = 0;
            document.querySelectorAll('#rawScoresContainer .raw-score-input').forEach(function(inp){
                const v = parseFloat(inp.value);
                if (!isNaN(v)) { sum += v; count++; }
            });
            const avg = count > 0 ? sum / count : 0;
            document.getElementById('quickScoresAverage').textContent = (Math.round(avg * 100) / 100).toString();
            return count > 0 ? avg : null;
        }

        function addRawScoreRow(focus) {
            const container = document.getElementById('rawScoresContainer');
            const row = document.createElement('div');
            row.className = 'input-group input-group-sm raw-score-row mb-1';
            const span = document.createElement('span');
            span.className = 'input-group-text bg-light';
            span.style.fontSize = '11px';
            span.textContent = (container.children.length + 1) + '.';
            const input = document.createElement('input');
            input.type = 'number';
            input.step = 'any';
            input.min = '0';
            input.className = 'form-control raw-score-input';
            input.placeholder = 'Score';
            input.addEventListener('input', function(){
                computeAverage();
                saveRawScores();
            });
            input.addEventListener('keydown', function(e){
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // Check if all inputs are filled
                    const allInputs = document.querySelectorAll('#rawScoresContainer .raw-score-input');
                    const allFilled = Array.from(allInputs).every(inp => inp.value.trim() !== '');
                    
                    if (allFilled) {
                        applyQuickScores();
                    } else {
                        // Focus on the next empty input
                        const currentIndex = Array.from(allInputs).indexOf(input);
                        const nextIndex = currentIndex + 1;
                        if (nextIndex < allInputs.length) {
                            allInputs[nextIndex].focus();
                        } else {
                            // If at last input but not all filled, add new row and focus it
                            addRawScoreRow(true);
                        }
                    }
                }
            });
            // Delete button for this row
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'btn btn-outline-danger';
            delBtn.title = 'Remove this score';
            delBtn.innerHTML = '<i class="fas fa-times"></i>';
            delBtn.addEventListener('click', function(){
                row.remove();
                renumberRows();
                computeAverage();
                saveRawScores();
                saveRowCount(container.children.length);
            });
            row.appendChild(input);
            row.appendChild(delBtn);
            container.appendChild(row);
            if (focus) input.focus();
            // Save new row count
            saveRowCount(container.children.length);
        }

        function renumberRows() {
            document.querySelectorAll('#rawScoresContainer .raw-score-row .input-group-text').forEach(function(span, i){
                span.textContent = (i + 1) + '.';
            });
        }

        function applyQuickScores() {
            const avg = computeAverage();
            if (avg === null) {
                alert('Please enter at least one raw score.');
                return;
            }
            if (targetMarkInput) {
                targetMarkInput.value = Math.round(avg * 100) / 100;
                // 'input' event is what the auto-save handler listens to -
                // dispatching blur alone never triggers autoSaveMarks()
                targetMarkInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (typeof updatePercentage === 'function') updatePercentage(targetMarkInput);
            }
            saveRawScores();
            
            // Check if fast single entry is enabled
            const fastEntryEnabled = document.getElementById('toggleEnterBehavior')?.getAttribute('aria-pressed') === 'true';
            
            if (quickScoresModal) quickScoresModal.hide();
            
            // Auto-focus search bar and clear if fast entry is enabled
            if (fastEntryEnabled) {
                setTimeout(function(){
                    const searchInput = document.querySelector('.dataTables_filter input');
                    if (searchInput) {
                        searchInput.value = '';
                        searchInput.focus();
                        // Trigger search to clear any active filter
                        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }, 300); // Small delay to ensure modal is hidden
            }
        }

        document.addEventListener('click', function(e){
            const btn = e.target.closest('.quick-scores-btn');
            if (!btn) return;
            const row = btn.closest('tr');
            targetMarkInput = row ? row.querySelector('input.mark-input') : null;
            if (!targetMarkInput) return;

            targetStudentId = btn.dataset.studentId || null;
            document.getElementById('quickScoresStudentName').textContent = (row.querySelector('h6')?.textContent || '').trim();
            
            // Get the stored row count for this assessment (mirrored across students)
            const rowCount = getRowCount();
            
            // Reset container and create the correct number of rows
            document.getElementById('rawScoresContainer').innerHTML = '';
            
            // Load saved scores for this specific student
            const saved = loadRawScores();
            
            // Create rows based on stored row count
            for (let i = 0; i < rowCount; i++) {
                addRawScoreRow(false);
            }
            
            // If we have saved scores, populate them
            if (saved && saved.length) {
                const inputs = document.querySelectorAll('#rawScoresContainer .raw-score-input');
                saved.forEach(function(val, i){
                    if (inputs[i]) {
                        inputs[i].value = val;
                    }
                });
            }
            
            computeAverage();

            quickScoresModal = new bootstrap.Modal(document.getElementById('quickScoresModal'));
            quickScoresModal.show();
        });

        document.getElementById('addRawScoreBtn').addEventListener('click', function(){
            addRawScoreRow(true);
        });

        document.getElementById('applyQuickScoresBtn').addEventListener('click', applyQuickScores);

        // When modal is fully shown, focus the first input; recompute on open
        document.getElementById('quickScoresModal').addEventListener('shown.bs.modal', function(){
            const first = document.querySelector('#rawScoresContainer .raw-score-input');
            if (first) first.focus();
        });
    })();
</script>
