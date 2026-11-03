
<div class="container-fluid py-4">
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><i class="fas fa-robot me-2"></i>AI Study Assistant (External LLM)</h4>
                    <span class="badge bg-light text-dark" id="aiProvBadge">Checking…</span>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small">Optional upgrade for the student-portal chatbox. When enabled, student questions are answered by an external AI provider (OpenAI-compatible: OpenAI, Groq, OpenRouter, DeepSeek, local Ollama…); otherwise the built-in offline engine answers. Replies are always grounded in the student's own records, and the chatbox falls back automatically if the provider fails. Note: enabling sends student grade data to the chosen provider.</p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="aiEnabled" style="width:3.5em;height:1.75em;">
                                <label class="form-check-label fw-bold ms-2" for="aiEnabled">Enabled</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold" for="aiProvider">Provider</label>
                            <select id="aiProvider" class="form-select"></select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold" for="aiModel">Model</label>
                            <input id="aiModel" class="form-control" placeholder="e.g. gpt-4o-mini">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-bold" for="aiBaseUrl">Base URL (OpenAI-compatible)</label>
                            <input id="aiBaseUrl" class="form-control" placeholder="https://api.openai.com/v1">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold" for="aiKey">API Key <small class="text-muted" id="aiKeyHint"></small></label>
                            <input id="aiKey" type="password" class="form-control" placeholder="Leave blank to keep the saved key" autocomplete="off">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button id="aiSaveBtn" class="btn btn-sm btn-primary"><i class="fas fa-save me-1"></i>Save Settings</button>
                        <button id="aiTestBtn" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plug me-1"></i>Test Connection</button>
                        <span id="aiStatus" class="small align-self-center text-muted"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Grading Components Section -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="text-primary mb-0">
                        <i class="fas fa-cubes me-2"></i>Grading Components
                    </h5>
                    <button id="new_component" class="btn btn-sm btn-primary" title="Add New Component">
                        <i class="fas fa-plus me-1"></i> Add Component
                    </button>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="componentsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Component Name</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $dept_qry = $conn->query("SELECT * FROM `grading_components` order by `name` asc");
                                $component_count = 0;
                                while($row = $dept_qry->fetchArray()):
                                    $component_count++;
                                ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($row['name']) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="edit_component btn btn-outline-primary"
                                                    data-id="<?php echo $row['component_id'] ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Edit Component">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="delete_component btn btn-outline-danger"
                                                    data-id="<?php echo $row['component_id'] ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Delete Component">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light d-flex justify-content-end align-items-center border-top">
                    <span class="badge bg-light text-dark border" id="componentCountBadge">Total Components: <?php echo $component_count; ?></span>
                </div>
            </div>
        </div>

        <!-- Subjects Section -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="text-primary mb-0">
                        <i class="fas fa-book me-2"></i>Subject Management
                    </h5>
                    <button id="new_subject" class="btn btn-sm btn-primary" title="Add New Subject">
                        <i class="fas fa-plus me-1"></i> Add Subject
                    </button>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="subjectsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Subject Name</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $subj_qry = $conn->query("SELECT * FROM `subjects` order by `name` asc");
                                $subject_count = 0;
                                while($row = $subj_qry->fetchArray()):
                                    $subject_count++;
                                ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($row['name']) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="manage_percentage btn btn-outline-info"
                                                    data-id="<?php echo $row['subject_id'] ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Manage Components">
                                                <i class="fas fa-percentage"></i> Percentage
                                            </button>
                                            <button type="button" class="edit_subject btn btn-outline-primary"
                                                    data-id="<?php echo $row['subject_id'] ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Edit Subject">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="delete_subject btn btn-outline-danger"
                                                    data-id="<?php echo $row['subject_id'] ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']) ?>"
                                                    title="Delete Subject">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light d-flex justify-content-end align-items-center border-top">
                    <span class="badge bg-light text-dark border" id="subjectCountBadge">Total Subjects: <?php echo $subject_count; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add custom styles -->
<style>
    .card {
        border: none;
        border-radius: 10px;
        overflow: hidden;
        transition: transform 0.2s ease-in-out;
    }
    
    .card:hover {
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
    }
    
    .card-header {
        border-bottom: none;
        padding: 1.25rem 1.5rem;
    }
    
    .table th {
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
    }
    
    .btn {
        border-radius: 6px;
        font-weight: 500;
        padding: 0.35rem 0.75rem;
    }
    
    .btn-group .btn {
        padding: 0.25rem 0.5rem;
    }
    
    .btn-outline-primary, .btn-outline-info, .btn-outline-danger {
        border-width: 1px;
    }
    
    .table > :not(caption) > * > * {
        padding: 0.75rem 1rem;
    }
    
    .badge {
        font-weight: 500;
        padding: 0.35em 0.65em;
    }
    
    @media (max-width: 991.98px) {
        .border-lg-end {
            border-right: none !important;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 1.5rem;
            margin-bottom: 1.5rem;
        }
    }
</style>
<script>
    // Shared helpers (global so modal callbacks & delete handlers can use them)
    window.maintToast = function(type, msg){
        if(window.showAppToast){ window.showAppToast(type, msg); }
        else { alert($('<div>').html(msg).text()); }
    };
    window.maintEscape = function(s){
        return $('<div>').text(s == null ? '' : String(s)).html().replace(/"/g, '&quot;');
    };
    window.maintEmptyMsg = '<div class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No records found</div>';
    window.maintInitTable = function(selector){
        if(!$(selector).length) return null;
        if($.fn.DataTable.isDataTable(selector)){
            return $(selector).DataTable();
        }
        return $(selector).DataTable({
            columnDefs: [
                { orderable: false, targets: 1, className: "text-end" }
            ],
            language: { emptyTable: window.maintEmptyMsg }
        });
    };
    window.maintRefreshCount = function(tableSelector, badgeSelector, label){
        var n = null;
        try {
            if($.fn.DataTable.isDataTable(tableSelector)){
                n = $(tableSelector).DataTable().rows().count();
            } else {
                n = $(tableSelector + ' tbody tr').length;
            }
        } catch(e){ n = null; }
        if(n !== null) $(badgeSelector).text(label + n);
    };
    $(function(){
        // Delegated handlers (survive DataTable redraws — no re-binding needed)
        $(document).off('click.maintNewComp').on('click.maintNewComp', '#new_component', function(){
            uni_modal('Add New Component',"manage_component.php")
        });
        $(document).off('click.maintEditComp').on('click.maintEditComp', '.edit_component', function(){
            uni_modal('Edit Component Details',"manage_component.php?id="+$(this).attr('data-id'))
        });
        $(document).off('click.maintPct').on('click.maintPct', '.manage_percentage', function(){
            uni_modal('Manage Subject\'s Components Percentage',"manage_percentage.php?id="+$(this).attr('data-id'))
        });
        $(document).off('click.maintDelComp').on('click.maintDelComp', '.delete_component', function(){
            _conf("Are you sure to delete <b>"+window.maintEscape($(this).attr('data-name'))+"</b> from Component List?",'delete_component',[$(this).attr('data-id')])
        });

        // Subjects (delegated)
        $(document).off('click.maintNewSubj').on('click.maintNewSubj', '#new_subject', function(){
            uni_modal('Add New subject',"manage_subject.php")
        });
        $(document).off('click.maintEditSubj').on('click.maintEditSubj', '.edit_subject', function(){
            uni_modal('Edit subject Details',"manage_subject.php?id="+$(this).attr('data-id'))
        });
        $(document).off('click.maintDelSubj').on('click.maintDelSubj', '.delete_subject', function(){
            _conf("Are you sure to delete <b>"+window.maintEscape($(this).attr('data-name'))+"</b> from Subject List?",'delete_subject',[$(this).attr('data-id')])
        });

        // Initialize DataTables only for tables present on this page
        window.maintInitTable('#componentsTable');
        window.maintInitTable('#subjectsTable');

        // Safety net: never leave the confirm modal's buttons disabled,
        // otherwise the next delete confirmation opens unclickable.
        $('#confirm_modal').off('hidden.bs.modal.maint').on('hidden.bs.modal.maint', function(){
            $('#confirm_modal button').attr('disabled', false);
        });

        // Dynamic update functions for modal forms (DataTables-aware, empty-safe)
        window.updateComponentData = function() {
            $.ajax({
                url: './Actions.php?a=get_components',
                method: 'GET',
                dataType: 'JSON',
                success: function(resp) {
                    if(resp.status === 'success') {
                        var table = window.maintInitTable('#componentsTable');
                        if(!table) return;
                        table.clear();
                        (resp.data || []).forEach(function(comp) {
                            var safeName = window.maintEscape(comp.name);
                            var actions = '<div class="btn-group btn-group-sm" role="group">' +
                                '<button type="button" class="edit_component btn btn-outline-primary" data-id="' + comp.component_id + '" data-name="' + safeName + '" title="Edit Component">' +
                                    '<i class="fas fa-edit"></i>' +
                                '</button>' +
                                '<button type="button" class="delete_component btn btn-outline-danger" data-id="' + comp.component_id + '" data-name="' + safeName + '" title="Delete Component">' +
                                    '<i class="fas fa-trash"></i>' +
                                '</button>' +
                            '</div>';

                            table.row.add([window.maintEscape(comp.name), actions]);
                        });
                        // Single draw; DataTables shows language.emptyTable when 0 rows —
                        // no fake "No components found" row needed (delegated handlers stay live)
                        table.draw();

                        // Update component count from server data (authoritative)
                        $('#componentCountBadge').text('Total Components: ' + (resp.data || []).length);
                    } else {
                        window.maintToast('danger', (resp && resp.msg) || 'Failed to refresh components.');
                    }
                },
                error: function(err){
                    console.log(err);
                    window.maintToast('danger', 'Failed to refresh components. Connection error.');
                }
            });
        }

        window.updateSubjectData = function() {
            $.ajax({
                url: './Actions.php?a=get_subjects',
                method: 'GET',
                dataType: 'JSON',
                success: function(resp) {
                    if(resp.status === 'success') {
                        var table = window.maintInitTable('#subjectsTable');
                        if(!table) return;
                        table.clear();
                        (resp.data || []).forEach(function(subj) {
                            var safeName = window.maintEscape(subj.name);
                            var actions = '<div class="btn-group btn-group-sm" role="group">' +
                                '<button type="button" class="manage_percentage btn btn-outline-info" data-id="' + subj.subject_id + '" data-name="' + safeName + '" title="Manage Components">' +
                                    '<i class="fas fa-percentage"></i> Percentage' +
                                '</button>' +
                                '<button type="button" class="edit_subject btn btn-outline-primary" data-id="' + subj.subject_id + '" data-name="' + safeName + '" title="Edit Subject">' +
                                    '<i class="fas fa-edit"></i>' +
                                '</button>' +
                                '<button type="button" class="delete_subject btn btn-outline-danger" data-id="' + subj.subject_id + '" data-name="' + safeName + '" title="Delete Subject">' +
                                    '<i class="fas fa-trash"></i>' +
                                '</button>' +
                            '</div>';

                            table.row.add([window.maintEscape(subj.name), actions]);
                        });
                        table.draw();

                        // Update subject count from server data (authoritative)
                        $('#subjectCountBadge').text('Total Subjects: ' + (resp.data || []).length);
                    } else {
                        window.maintToast('danger', (resp && resp.msg) || 'Failed to refresh subjects.');
                    }
                },
                error: function(err){
                    console.log(err);
                    window.maintToast('danger', 'Failed to refresh subjects. Connection error.');
                }
            });
        }
    })
    function delete_subject($id){
        var $btns = $('#confirm_modal button');
        $btns.attr('disabled', true);
        $.ajax({
            url:'./Actions.php?a=delete_subject',
            method:'POST',
            data:{id:$id},
            dataType:'JSON',
            error:err=>{
                console.log(err)
                window.maintToast('danger', 'An error occurred while deleting the subject.');
                $btns.attr('disabled', false);
            },
            success:function(resp){
                if(resp.status == 'success'){
                    // Re-enable buttons BEFORE hiding: otherwise the next
                    // _conf open finds Confirm/Cancel still disabled.
                    $btns.attr('disabled', false);
                    $('#confirm_modal').modal('hide');
                    // Remove via DataTables API so paging/search stay consistent;
                    // emptyTable message appears automatically when 0 rows remain
                    try {
                        if($.fn.DataTable.isDataTable('#subjectsTable')){
                            var dt = $('#subjectsTable').DataTable();
                            var $row = $('#subjectsTable button.delete_subject[data-id="'+$id+'"]').closest('tr');
                            if($row.length){ dt.row($row).remove().draw(); }
                        } else {
                            $('#subjectsTable button.delete_subject[data-id="'+$id+'"]').closest('tr').remove();
                        }
                    } catch(e){ console.log(e); }
                    window.maintRefreshCount('#subjectsTable', '#subjectCountBadge', 'Total Subjects: ');
                    window.maintToast('success', (resp && resp.msg) || 'Subject successfully deleted.');
                }else{
                    window.maintToast('danger', (resp && (resp.msg || resp.error)) || 'Failed to delete the subject.');
                    $btns.attr('disabled', false);
                }
            }
        })
    }
    function delete_component($id){
        var $btns = $('#confirm_modal button');
        $btns.attr('disabled', true);
        $.ajax({
            url:'./Actions.php?a=delete_component',
            method:'POST',
            data:{id:$id},
            dataType:'JSON',
            error:err=>{
                console.log(err)
                window.maintToast('danger', 'An error occurred while deleting the component.');
                $btns.attr('disabled', false);
            },
            success:function(resp){
                if(resp.status == 'success'){
                    $btns.attr('disabled', false);
                    $('#confirm_modal').modal('hide');
                    try {
                        if($.fn.DataTable.isDataTable('#componentsTable')){
                            var dt = $('#componentsTable').DataTable();
                            var $row = $('#componentsTable button.delete_component[data-id="'+$id+'"]').closest('tr');
                            if($row.length){ dt.row($row).remove().draw(); }
                        } else {
                            $('#componentsTable button.delete_component[data-id="'+$id+'"]').closest('tr').remove();
                        }
                    } catch(e){ console.log(e); }
                    window.maintRefreshCount('#componentsTable', '#componentCountBadge', 'Total Components: ');
                    window.maintToast('success', (resp && resp.msg) || 'Component successfully deleted.');
                }else{
                    window.maintToast('danger', (resp && (resp.msg || resp.error)) || 'Failed to delete the component.');
                    $btns.attr('disabled', false);
                }
            }
        })
    }
    
    // Refresh helpers (kept for compatibility — delegate to the DataTables-aware updaters above)
    function refreshComponentsTable() {
        if(window.updateComponentData) window.updateComponentData();
    }

    // Refresh helpers (kept for compatibility — delegate to the DataTables-aware updaters above)
    function refreshSubjectsTable() {
        if(window.updateSubjectData) window.updateSubjectData();
    }

    // Re-attach helpers (kept for compatibility — handlers are delegated, nothing to rebind)
    function attachComponentEventListeners() { /* delegated via $(document).on — no-op */ }

    // Re-attach helpers (kept for compatibility — handlers are delegated, nothing to rebind)
    function attachSubjectEventListeners() { /* delegated via $(document).on — no-op */ }

    // ---- AI provider settings (must run on page load, not only after table refresh) ----
    var aiPresets = {};
        function aiStatus(msg, ok) {
            var el = $('#aiStatus');
            el.text(msg || '');
            el.removeClass('text-success text-danger text-muted');
            el.addClass(ok === true ? 'text-success' : (ok === false ? 'text-danger' : 'text-muted'));
        }
        function aiRefreshBadge(enabled, provider) {
            var b = $('#aiProvBadge');
            b.text(enabled ? ('ON · ' + provider) : 'OFF · built-in engine');
            b.removeClass('bg-light bg-success').addClass(enabled ? 'bg-success text-white' : 'bg-light text-dark');
        }
        function aiPost(action, extra, onOk) {
            var payload = Object.assign({ action: action }, extra || {});
            aiStatus('Working…');
            $.ajax({
                url: 'save_ai_settings.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json'
            }).done(function(res) {
                if (res && res.status === 'success') { onOk(res); }
                else { aiStatus((res && res.message) || 'Failed.', false); $('#aiSaveBtn, #aiTestBtn').prop('disabled', false); }
            }).fail(function(xhr) {
                var msg = 'Network error.';
                try { msg = (xhr.responseJSON && xhr.responseJSON.message) || msg; } catch (e) {}
                aiStatus(msg, false);
                $('#aiSaveBtn, #aiTestBtn').prop('disabled', false);
            });
        }
        function aiLoad() {
            aiPost('get', {}, function(res) {
                var s = res.settings || {};
                aiPresets = s.presets || {};
                var sel = $('#aiProvider');
                sel.empty();
                Object.keys(aiPresets).forEach(function(k) {
                    sel.append($('<option>').val(k).text(aiPresets[k].label));
                });
                sel.val(s.provider || 'openai');
                $('#aiModel').val(s.model || '');
                $('#aiBaseUrl').val(s.base_url || '');
                $('#aiEnabled').prop('checked', !!s.enabled);
                $('#aiKeyHint').text(s.has_key ? ('saved ' + (s.key_hint || '')) : 'none saved');
                aiRefreshBadge(!!s.enabled, s.provider || 'openai');
                aiStatus(s.enabled ? 'External AI answers student chats.' : 'Built-in engine answers student chats.', null);
            });
        }
        $('#aiProvider').off('change').on('change', function() {
            var p = aiPresets[$(this).val()] || {};
            if (p.base_url) $('#aiBaseUrl').val(p.base_url);
            if (p.model) $('#aiModel').val(p.model);
        });
        $('#aiSaveBtn').off('click').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true);
            aiPost('save', {
                enabled: $('#aiEnabled').is(':checked') ? 1 : 0,
                provider: $('#aiProvider').val(),
                base_url: $('#aiBaseUrl').val(),
                model: $('#aiModel').val(),
                api_key: $('#aiKey').val()
            }, function(res) {
                btn.prop('disabled', false);
                $('#aiKey').val('');
                aiStatus(res.message, true);
                aiLoad();
            });
        });
        $('#aiTestBtn').off('click').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true);
            aiPost('test', {}, function(res) {
                btn.prop('disabled', false);
                aiStatus(res.message, true);
            });
        });
        $(function(){ if ($('#aiProvider').length) aiLoad(); });
</script>