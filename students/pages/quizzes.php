<section class="horizon-card mb-3">
  <div class="horizon-card-header">
    <div class="d-flex align-items-center gap-2"><span class="horizon-widget-icon-box brand" style="width:36px;height:36px;min-width:36px;font-size:14px"><i class="fas fa-pen-square"></i></span><strong>Active quizzes</strong><span class="d-none d-sm-inline" style="color:var(--horizon-text-muted);font-weight:600;font-size:12px">— activated quizzes for your classes only</span></div>
    <span class="badge badge-secondary" data-quiz-count>0 new</span>
  </div>
  <div class="horizon-card-body"><div data-quiz-list><div class="text-center py-3" style="color:var(--horizon-text-muted);font-size:12px"><div class="spinner-border spinner-border-sm text-primary"></div><div class="mt-2">Checking for quizzes…</div></div></div></div>
</section>
<section class="horizon-card mb-3">
  <div class="horizon-card-header">
    <div class="d-flex align-items-center gap-2"><span class="horizon-widget-icon-box green" style="width:36px;height:36px;min-width:36px;font-size:14px"><i class="fas fa-check-circle"></i></span><strong>Submitted quizzes</strong><span class="d-none d-sm-inline" style="color:var(--horizon-text-muted);font-weight:600;font-size:12px">— your quiz history</span></div>
    <span class="badge badge-secondary" id="quizHistoryCount">0 submitted</span>
  </div>
  <div class="horizon-card-body">
    <div class="filter-bar mb-3">
      <div class="seg-group" id="quizHistYearPills" role="group" aria-label="Filter history by school year"><span class="seg-cap">Year</span></div>
      <div class="seg-group" id="quizHistSemPills" role="group" aria-label="Filter history by semester"><span class="seg-cap">Semester</span></div>
      <div class="hz-select-wrap"><span class="hz-ico"><i class="fas fa-book-open"></i></span><select class="form-select" id="quizHistClass" aria-label="Filter history by class"><option value="all">All classes</option></select><i class="fas fa-chevron-down hz-chev"></i></div>
      <button type="button" class="btn btn-secondary btn-sm" id="quizHistReset"><i class="fas fa-sync-alt me-1"></i>Reset</button>
    </div>
    <div id="quizHistoryBody"><div class="text-center py-3" style="color:var(--horizon-text-muted);font-size:12px"><div class="spinner-border spinner-border-sm text-primary"></div><div class="mt-2">Loading your submitted quizzes…</div></div></div>
  </div>
</section>
<section class="horizon-card">
  <div class="horizon-card-body d-flex gap-3 align-items-start">
    <span class="horizon-widget-icon-box amber" style="width:40px;height:40px;min-width:40px;font-size:15px"><i class="fas fa-shield-alt"></i></span>
    <div style="font-size:12px;color:var(--horizon-text-muted);font-weight:500;line-height:1.6">
      <strong style="color:var(--horizon-text-title)">Secure quiz mode.</strong>
      Quizzes open in a protected screen: stay on the tab, don't minimize or background the browser, and don't copy questions out or paste text in. Violations are logged and 3 violations auto-submit your answers. Submitted quizzes show your score here.
    </div>
  </div>
</section>
<script>
(function(){
    var historyRows = [];
    var body = document.getElementById('quizHistoryBody');
    var countEl = document.getElementById('quizHistoryCount');
    var fYearBox = document.getElementById('quizHistYearPills');
    var fSemBox = document.getElementById('quizHistSemPills');
    var fClass = document.getElementById('quizHistClass');
    if (!body) return;
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    // DB stores UTC — show Philippine 12-hour time like the faculty side.
    function phTime(utc){
        if (!utc) return '—';
        var m = String(utc).trim().match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (!m) return String(utc);
        var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)));
        try { return d.toLocaleString('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }); }
        catch (e) { return String(utc); }
    }
    function yearLabel(y){
        y = String(y || '');
        if (/^\d{4}$/.test(y)) return y + '—' + (parseInt(y, 10) + 1);
        return y === '' ? '—' : y;
    }
    function pillValue(box){
        if (!box) return 'all';
        var b = box.querySelector('.seg-pill.active');
        return b ? (b.getAttribute('data-value') || 'all') : 'all';
    }
    function fillPills(box, keys, fmt, allLabel){
        if (!box) return;
        var cap = box.querySelector('.seg-cap');
        var cur = pillValue(box);
        var h = '<button type="button" class="seg-pill' + (cur === 'all' ? ' active' : '') + '" data-value="all"' + (cur === 'all' ? ' aria-current="true"' : '') + '>' + esc(allLabel) + '</button>';
        keys.slice().sort().forEach(function(k){
            var on = (k === cur);
            h += '<button type="button" class="seg-pill' + (on ? ' active' : '') + '" data-value="' + esc(k) + '"' + (on ? ' aria-current="true"' : '') + '>' + esc(fmt ? fmt(k) : k) + '</button>';
        });
        box.innerHTML = '';
        if (cap) box.appendChild(cap);
        box.insertAdjacentHTML('beforeend', h);
    }
    function fillOptions(){
        var years = {}, sems = {}, classes = {};
        historyRows.forEach(function(r){
            if (r.year) years[r.year] = true;
            if (r.semester) sems[r.semester] = true;
            classes[r.class_key] = (r.subject_name ? r.subject_name + ' · ' : '') + (r.class_label || ('Class #' + r.class_id));
        });
        fillPills(fYearBox, Object.keys(years), yearLabel, 'All');
        fillPills(fSemBox, Object.keys(sems), null, 'All');
        function fill(sel, map, fmt){
            var cur = sel.value || 'all';
            sel.innerHTML = sel.querySelector('option').outerHTML;
            Object.keys(map).sort().forEach(function(k){
                var o = document.createElement('option');
                o.value = k;
                o.textContent = fmt ? fmt(k) : (map[k] !== undefined ? map[k] : k);
                sel.appendChild(o);
            });
            sel.value = map[cur] ? cur : 'all';
        }
        fill(fClass, classes, null);
    }
    function render(){
        var y = pillValue(fYearBox), s = pillValue(fSemBox), c = fClass.value;
        var list = historyRows.filter(function(r){
            if (y !== 'all' && String(r.year || '') !== y) return false;
            if (s !== 'all' && String(r.semester || '') !== s) return false;
            if (c !== 'all' && String(r.class_key || '') !== c) return false;
            return true;
        });
        countEl.textContent = list.length + ' submitted';
        if (!historyRows.length){
            body.innerHTML = '<div class="text-center py-3" style="color:var(--horizon-text-muted);font-size:12px"><i class="fas fa-inbox mb-2 d-block" style="font-size:20px"></i>No submitted quizzes yet. Scores appear here after you submit.</div>';
            return;
        }
        if (!list.length){
            body.innerHTML = '<div class="text-center py-3" style="color:var(--horizon-text-muted);font-size:12px"><i class="fas fa-filter mb-2 d-block" style="font-size:20px"></i>No submitted quizzes match these filters.</div>';
            return;
        }
        var h = '<div class="d-flex flex-column gap-2">';
        list.forEach(function(r){
            var auto = (r.status === 'auto_submitted');
            h += '<div class="d-flex align-items-center gap-3 flex-wrap p-3" style="border:1px solid var(--horizon-card-border);border-radius:12px;background:var(--horizon-card-bg)">';
            h += '<span class="horizon-widget-icon-box green" style="width:42px;height:42px;min-width:42px;font-size:16px"><i class="fas fa-check-circle"></i></span>';
            h += '<div class="flex-grow-1" style="min-width:220px"><div class="fw-bold" style="font-size:13px;color:var(--horizon-text-title)">' + esc(r.title) + (auto ? ' <span class="badge badge-warning" style="font-size:9px" title="Auto-submitted (violations / time limit)">auto</span>' : '') + '</div>';
            h += '<div style="font-size:11px;color:var(--horizon-text-muted);font-weight:600">' + esc(r.subject_name || '') + ' · ' + esc(r.class_label || '') + ' · ' + esc(yearLabel(r.year)) + ' · ' + esc(r.semester || '') + ' · ' + esc(r.quiz_type_label || '') + '</div>';
            h += '<div style="font-size:11px;color:var(--horizon-text-muted);font-weight:600"><i class="fas fa-clock me-1"></i>Submitted ' + esc(phTime(r.submitted_at)) + (parseInt(r.violations || 0, 10) > 0 ? ' · <span class="badge badge-warning" style="font-size:9px">' + esc(r.violations) + ' violation' + (parseInt(r.violations, 10) != 1 ? 's' : '') + '</span>' : '') + '</div></div>';
            h += '<span class="text-center"><span class="d-block fw-bold" style="font-family:monospace;font-size:1.05rem;color:var(--horizon-text-title)">' + esc(r.score) + ' / ' + esc(r.total) + '</span><small class="text-muted">score</small></span>';
            h += '</div>';
        });
        body.innerHTML = h + '</div>';
    }
    function load(){
        fetch('quiz_api.php?action=history', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function(r){ return r.text(); })
            .then(function(t){
                var j = null;
                try { j = JSON.parse(t); } catch (e) { return; }
                if (!j || j.status !== 'success') return;
                historyRows = j.history || [];
                fillOptions();
                render();
            })
            .catch(function(){});
    }
    [fYearBox, fSemBox].forEach(function(box){
        if (!box) return;
        box.addEventListener('click', function(e){
            var b = e.target.closest ? e.target.closest('.seg-pill') : null;
            if (!b || !box.contains(b)) return;
            box.querySelectorAll('.seg-pill').forEach(function(p){ p.classList.remove('active'); p.removeAttribute('aria-current'); });
            b.classList.add('active');
            b.setAttribute('aria-current', 'true');
            render();
        });
    });
    fClass.addEventListener('change', render);
    document.getElementById('quizHistReset').addEventListener('click', function(){
        [fYearBox, fSemBox].forEach(function(box){
            if (!box) return;
            var all = box.querySelector('.seg-pill[data-value="all"]');
            box.querySelectorAll('.seg-pill').forEach(function(p){ p.classList.remove('active'); p.removeAttribute('aria-current'); });
            if (all) { all.classList.add('active'); all.setAttribute('aria-current', 'true'); }
        });
        fClass.value = 'all';
        render();
    });
    load();
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) load(); });
})();
</script>
