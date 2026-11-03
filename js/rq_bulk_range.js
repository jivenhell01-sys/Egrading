// =====================================================================
// BULK SET RANGE - grades grid (reports_content.php)
//
// Mirrors marks.php's Bulk Set Range, but operates on a column of the
// grades table instead of a list of inputs:
//   From 0     To 85    -> every 0 in the column becomes 85
//   From 0     To blank -> every 0 in the column is cleared (blank)
//   From blank To 85    -> every blank in the column becomes 85
// Matching is exact, numeric values are clamped to the column's HPS, and
// dropped students (contenteditable="false") are never touched.
// Rows hidden by the search / "Show Failed Students" filters are still
// processed, exactly like marks.php does with its filtered DataTable rows.
//
// The writes go out as ONE batch request to bulk_update_marks.php, then the
// touched rows are re-rendered from the server so T / weighted % / initial
// grade / transmuted grade all stay in sync without a page reload.
// =====================================================================
(function () {
    'use strict';

    var rqBulk = { previous: [], busy: false };
    var REFRESH_PARALLEL = 6; // keep Apache's worker pool from saturating

    // ---------------------------------------------------------------- UI
    function status(message, type) {
        var $status = $('#rqBulkStatus');
        if (!$status.length) return;
        $status
            .removeClass('text-muted text-success text-warning text-danger text-info')
            .addClass('text-' + (type || 'muted'))
            .text(message);
    }

    function notify(type, message) {
        if (typeof rqToast === 'function') {
            rqToast(type, message);
        } else if (window.showAppToast) {
            window.showAppToast(type, message);
        } else {
            alert(message);
        }
    }

    function setBusy(busy) {
        rqBulk.busy = !!busy;
        $('#rqBulkApply').prop('disabled', !!busy);
        $('#rqBulkUndo').prop('disabled', !!busy);
    }

    function columnHps(assessmentId, fallback) {
        if (typeof assessmentHPS !== 'undefined' && assessmentHPS && assessmentHPS[assessmentId] !== undefined) {
            var hps = parseFloat(assessmentHPS[assessmentId]);
            if (!isNaN(hps) && hps > 0) return hps;
        }
        var fb = parseFloat(fallback);
        return (!isNaN(fb) && fb > 0) ? fb : 0;
    }

    // ------------------------------------------------------------ columns
    // Read straight off the header so the dropdown always matches what is on
    // screen (single-quarter and "Active Quarters" layouts alike).
    function columns() {
        var cols = [];
        var seen = {};

        $('#gradesTable thead th.rq-h-assess[data-assessment-id]').each(function () {
            var $th = $(this);
            var aid = String($th.attr('data-assessment-id') || '');
            if (!aid || seen[aid]) return;
            seen[aid] = true;

            var componentId = String($th.attr('data-component-id') || '');
            var $compHead = $('#gradesTable thead th.rq-h-component[data-component-id="' + componentId + '"]').first();
            var componentName = $.trim($compHead.find('.rq-comp-name').first().text()) || ('Component ' + componentId);
            var assessName = $.trim($th.find('.rq-assess-name').first().text()) ||
                String($th.attr('data-name') || '') || ('Assessment ' + aid);

            // The quarter band only exists in the Active Quarters layout.
            var quarterLabel = '';
            var quarter = String($th.attr('data-quarter') || '');
            var $qHead = $('#gradesTable thead tr.rq-row-quarter th[data-component-id="' + componentId + '"]' +
                (quarter !== '' ? '[data-quarter="' + quarter + '"]' : '')).first();
            if ($qHead.length) {
                quarterLabel = $.trim($qHead.text().replace(/\s+/g, ' '));
            }

            var label = componentName + ' \u203A ' + assessName;
            if (quarterLabel) label += ' (' + quarterLabel + ')';

            cols.push({ assessmentId: aid, componentId: componentId, quarter: quarter, label: label });
        });

        return cols;
    }

    function syncColumns() {
        var $sel = $('#rqBulkColumn');
        if (!$sel.length) return [];

        var current = String($sel.val() || '');
        var cols = columns();

        $sel.empty().append('<option value="">Select a column</option>');
        cols.forEach(function (col) {
            $sel.append(
                '<option value="' + $('<div>').text(col.assessmentId).html() + '">' +
                $('<div>').text(col.label).html() + '</option>'
            );
        });

        var stillThere = false;
        cols.forEach(function (col) { if (col.assessmentId === current) stillThere = true; });
        $sel.val(stillThere ? current : '');

        if (!cols.length) {
            status('No assessment columns to edit in this view.', 'warning');
        } else {
            status('Pick a column, then set the range.', 'muted');
        }
        return cols;
    }

    // ------------------------------------------------------------ collect
    // Cells whose current score matches the "From" value, remembering what
    // they held so Undo can put it back.
    function collect(fromMark, toMark, assessmentId) {
        var updates = [];
        var previous = [];
        var cells = [];
        var hps = 0;

        $('#gradesTable tbody tr[data-student-id]').each(function () {
            var $row = $(this);
            var studentId = String($row.attr('data-student-id') || '');
            if (!studentId) return;

            $row.find('td.editable-mark[data-assessment-id="' + assessmentId + '"][contenteditable="true"]').each(function () {
                var $cell = $(this);
                var currentRaw = $.trim($cell.text());
                var matches = (fromMark === null)
                    ? currentRaw === ''
                    : (parseFloat(currentRaw) === fromMark);
                if (!matches) return;

                var nextValue = '';
                if (toMark !== null) {
                    hps = columnHps(assessmentId, $cell.data('hps'));
                    var safe = Math.max(toMark, 0);
                    if (hps > 0) safe = Math.min(safe, hps);
                    nextValue = safe.toFixed(2);
                }

                updates.push({ student_id: studentId, assessment_id: assessmentId, mark: nextValue });
                previous.push({ student_id: studentId, assessment_id: assessmentId, mark: currentRaw });
                cells.push($cell);
            });
        });

        return { updates: updates, previous: previous, cells: cells };
    }

    function flash($cells) {
        $cells.addClass('rq-bulk-hit');
        setTimeout(function () {
            $cells.removeClass('rq-bulk-hit');
        }, 2000);
    }

    // ------------------------------------------------------------- refresh
    // Re-render the rows a bulk write touched. updateRowCalculations() does not
    // invoke its callback on every failure path, so the request is issued here
    // and the slot is always released once the row is in place.
    function refreshRow(studentId, quarterParam, done) {
        var classId = String($('#class_id').val() || '');
        var $row = $('#gradesTable tbody tr[data-student-id="' + studentId + '"]');
        if (!$row.length || !classId) {
            done();
            return;
        }

        var settled = false;
        var once = function () {
            if (settled) return;
            settled = true;
            done();
        };

        $.ajax({
            url: 'get_student_row_data.php',
            type: 'POST',
            dataType: 'json',
            data: { student_id: studentId, class_id: classId, quarter: quarterParam },
            success: function (resp) {
                if (resp && resp.status === 'success' && resp.row_html) {
                    $row.replaceWith(resp.row_html);
                }
                once();
            },
            error: once
        });
    }

    function refreshRows(entries, highlightAssessmentId, done) {
        var classId = String($('#class_id').val() || '');
        var isActive = window.location.search.indexOf('quarter=active') !== -1;

        var queue = [];
        var seen = {};
        var quarter = '';
        (entries || []).forEach(function (entry) {
            var sid = String(entry.student_id || '');
            if (!sid || seen[sid]) return;
            seen[sid] = true;
            queue.push(sid);
            if (!quarter) quarter = String(entry.quarter || '');
        });

        if (!queue.length || !classId) {
            if (typeof done === 'function') done();
            return;
        }

        var quarterParam = isActive ? 'active' : quarter;
        var inFlight = 0;
        var finished = false;

        var finish = function () {
            if (finished) return;
            finished = true;
            // Keep the flash visible on the freshly rendered rows.
            if (highlightAssessmentId) {
                flash($('#gradesTable tbody td.editable-mark[data-assessment-id="' + highlightAssessmentId + '"][contenteditable="true"]'));
            }
            try { if (typeof filterTable === 'function') filterTable(); } catch (err) {}
            try { if (typeof determineHighImpact === 'function') determineHighImpact(); } catch (err) {}
            if (typeof done === 'function') done();
        };

        var pump = function () {
            while (inFlight < REFRESH_PARALLEL && queue.length > 0) {
                (function (sid) {
                    inFlight++;
                    refreshRow(sid, quarterParam, function () {
                        inFlight--;
                        if (inFlight === 0 && queue.length === 0) {
                            finish();
                        } else {
                            pump();
                        }
                    });
                })(queue.shift());
            }
        };

        pump();
    }

    // ---------------------------------------------------------------- send
    function send(updates, onSuccess) {
        $.ajax({
            url: 'bulk_update_marks.php',
            type: 'POST',
            dataType: 'json',
            data: { updates: JSON.stringify(updates) },
            success: function (resp) {
                if (resp && resp.status === 'success') {
                    onSuccess(resp);
                    return;
                }
                setBusy(false);
                var msg = (resp && (resp.message || resp.msg)) || 'Unable to save the range.';
                status(msg, 'danger');
                notify('danger', msg);
            },
            error: function () {
                setBusy(false);
                status('Network error while saving the range.', 'danger');
                notify('danger', 'Network error while saving the range.');
            }
        });
    }

    // ---------------------------------------------------------------- apply
    function applyRange() {
        if (rqBulk.busy) return;

        var assessmentId = String($('#rqBulkColumn').val() || '');
        if (!assessmentId) {
            status('Please choose a column first.', 'warning');
            return;
        }

        var fromRaw = $.trim($('#rqBulkFrom').val() || '');
        var toRaw = $.trim($('#rqBulkTo').val() || '');

        // Blank is valid on either side: blank To clears the matching scores,
        // blank From targets the blank ones. Only an entirely empty range is
        // rejected.
        if (fromRaw === '' && toRaw === '') {
            status('Enter a "From" value, a "To" value, or both.', 'warning');
            return;
        }
        if ((fromRaw !== '' && isNaN(parseFloat(fromRaw))) || (toRaw !== '' && isNaN(parseFloat(toRaw)))) {
            status('The range values must be numbers.', 'warning');
            return;
        }

        var col = null;
        var cols = columns();
        for (var i = 0; i < cols.length; i++) {
            if (cols[i].assessmentId === assessmentId) { col = cols[i]; break; }
        }
        if (!col) {
            status('That column is no longer available. Pick another one.', 'warning');
            return;
        }

        var fromMark = fromRaw === '' ? null : parseFloat(fromRaw);
        var toMark = toRaw === '' ? null : parseFloat(toRaw);
        var matched = collect(fromMark, toMark, assessmentId);

        if (!matched.updates.length) {
            status('No score in ' + col.label + ' currently holds ' + (fromMark === null ? 'a blank' : fromRaw) + '.', 'info');
            return;
        }

        setBusy(true);
        var fromLabel = fromRaw === '' ? 'blank' : fromRaw;
        var toLabel = toRaw === '' ? 'blank (cleared)' : toRaw;
        status('Applying ' + fromLabel + ' \u2192 ' + toLabel + ' to ' + matched.updates.length + ' score(s)...', 'info');

        send(matched.updates, function (resp) {
            // Snapshot the replaced values (plus the quarter, needed to
            // re-render the rows afterwards) for Undo.
            rqBulk.previous = matched.previous.map(function (entry) {
                return {
                    student_id: entry.student_id,
                    assessment_id: entry.assessment_id,
                    mark: entry.mark,
                    quarter: col.quarter
                };
            });

            refreshRows(rqBulk.previous, null, function () {
                setBusy(false);
                $('#rqBulkUndo').show();
                var total = (resp.updated || 0) + (resp.cleared || 0);
                var msg = 'Applied range: ' + fromLabel + ' \u2192 ' + toLabel + ' (' + total + ' score(s) updated)';
                status(msg, 'success');
                notify('success', msg);
            });
        });
    }

    // ----------------------------------------------------------------- undo
    function undoRange() {
        if (rqBulk.busy) return;
        if (!rqBulk.previous.length) {
            status('Nothing to undo.', 'info');
            return;
        }

        setBusy(true);
        status('Restoring the previous scores...', 'info');

        var restore = rqBulk.previous;
        rqBulk.previous = [];

        send(restore, function () {
            refreshRows(restore, null, function () {
                setBusy(false);
                $('#rqBulkUndo').hide();
                status('Bulk changes undone.', 'info');
                notify('info', 'Bulk changes undone.');
            });
        });
    }

    // ----------------------------------------------------------------- init
    $(function () {
        if (!$('#rqBulkColumn').length) return;

        syncColumns();

        $('#rqBulkColumn').on('change', function () {
            var id = String($(this).val() || '');
            if (!id) {
                status('Pick a column, then set the range.', 'muted');
                return;
            }
            var cols = columns();
            for (var i = 0; i < cols.length; i++) {
                if (cols[i].assessmentId === id) {
                    status('Target: ' + cols[i].label, 'muted');
                    return;
                }
            }
            status('That column is no longer available. Pick another one.', 'warning');
        });

        $('#rqBulkApply').on('click', applyRange);
        $('#rqBulkUndo').on('click', undoRange);

        // Enter in either field applies the range (same as marks.php).
        $('#rqBulkFrom, #rqBulkTo').on('keydown', function (e) {
            if (e.key !== 'Enter' && e.keyCode !== 13) return;
            e.preventDefault();
            applyRange();
        });
    });
})();
