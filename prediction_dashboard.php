<?php
require_once('DBConnection.php');
require_once('classes/PredictionService.php');

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0) {
    header("Location: login.php");
    exit;
}

$predictionService = new PredictionService();
$classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

$conn = new DBConnection();
$classesQuery = "SELECT cl.*, s.name as subject_name FROM class_list cl JOIN subjects s ON cl.subject_id = s.subject_id ORDER BY cl.year DESC, cl.semester DESC";
$classesResult = $conn->query($classesQuery);
$classes = [];
while ($row = $classesResult->fetchArray(SQLITE3_ASSOC)) $classes[] = $row;

$classStats = null; $predictions = null; $selectedClass = null; $componentRequirements = null; $quarterNames = [];
if ($classId > 0) {
    $predictions = $predictionService->predictStudentGrade($classId);
    $classStats = $predictionService->getClassPredictionStats($classId);
    $componentRequirements = $predictionService->calculateComponentRequirements($classId);
    $quarterNames = $predictionService->getQuarterNames($classId);
    $classStmt = $conn->prepare("SELECT cl.*, s.name as subject_name FROM class_list cl JOIN subjects s ON cl.subject_id = s.subject_id WHERE cl.class_id = ?");
    $classStmt->bindValue(1, $classId, SQLITE3_INTEGER);
    $selectedClass = $classStmt->execute()->fetchArray(SQLITE3_ASSOC);
}
?>
<div class="horizon-card">
  <div class="horizon-card-header">
    <div class="d-flex align-items-center gap-2">
      <div class="horizon-widget-icon-box brand"><i class="fas fa-chart-line"></i></div>
      <div>
        <h4 class="mb-0 fw-bold">Student Performance Prediction</h4>
        <small class="text-muted">AI linear-regression forecast + per-student improvement plans<?= $selectedClass ? ' · '.htmlspecialchars($selectedClass['subject_name'].' — '.$selectedClass['grade'].' '.$selectedClass['section']) : '' ?></small>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button onclick="exportPredictions()" class="btn btn-secondary btn-sm" <?= $classId>0?'':'disabled' ?>><i class="fas fa-download me-1"></i>Export</button>
    </div>
  </div>
  <div class="horizon-card-body">
    <div class="row g-2 align-items-end mb-3">
      <div class="col-md-6">
        <label class="form-label" for="classSelect"><i class="fas fa-graduation-cap me-1"></i>Select Class</label>
        <select class="form-select" id="classSelect" onchange="loadClassPredictions()">
          <option value="">Choose a class...</option>
          <?php foreach ($classes as $class): ?>
            <option value="<?= $class['class_id'] ?>" <?= $classId==$class['class_id']?'selected':'' ?>><?= htmlspecialchars($class['subject_name'].' - '.$class['course_no'].' - '.$class['section'].' ('.$class['year'].')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><button onclick="refreshPredictions()" class="btn btn-primary w-100"><i class="fas fa-sync-alt me-1"></i>Refresh</button></div>
      <div class="col-md-3"><button onclick="compareStudents()" class="btn btn-secondary w-100"><i class="fas fa-users me-1"></i>Compare</button></div>
    </div>

    <?php if ($predictions && $classStats): ?>
      <?php $cr = $componentRequirements['summary'] ?? ['on_track'=>0,'needs_improvement'=>0,'cannot_reach_target'=>0,'average_current_score'=>0]; ?>
      <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3"><div class="horizon-widget"><div class="horizon-widget-icon-box green"><i class="fas fa-check"></i></div><div class="horizon-widget-content"><p class="horizon-widget-title">On Track (75%)</p><h3 class="horizon-widget-value"><?= $cr['on_track'] ?></h3></div></div></div>
        <div class="col-6 col-lg-3"><div class="horizon-widget"><div class="horizon-widget-icon-box amber"><i class="fas fa-exclamation-triangle"></i></div><div class="horizon-widget-content"><p class="horizon-widget-title">Needs Work</p><h3 class="horizon-widget-value"><?= $cr['needs_improvement'] ?></h3></div></div></div>
        <div class="col-6 col-lg-3"><div class="horizon-widget"><div class="horizon-widget-icon-box red"><i class="fas fa-times"></i></div><div class="horizon-widget-content"><p class="horizon-widget-title">Cannot Reach</p><h3 class="horizon-widget-value"><?= $cr['cannot_reach_target'] ?></h3></div></div></div>
        <div class="col-6 col-lg-3"><div class="horizon-widget"><div class="horizon-widget-icon-box brand"><i class="fas fa-users"></i></div><div class="horizon-widget-content"><p class="horizon-widget-title">Pass Rate</p><h3 class="horizon-widget-value"><?= $classStats['pass_rate'] ?>%</h3><span class="horizon-widget-trend <?= $classStats['pass_rate']>=75?'positive':'negative' ?>"><?= $classStats['passing_students'] ?>/<?= $classStats['total_students'] ?> passing</span></div></div></div>
      </div>

      <div class="horizon-card mb-3" style="box-shadow:none;">
        <div class="horizon-card-header">
          <strong><i class="fas fa-brain me-2"></i>Model: Final = <?= number_format($predictions['model_info']['slope'],3) ?> × Current + <?= number_format($predictions['model_info']['intercept'],2) ?></strong>
          <span class="badge <?= $predictions['model_info']['r2']>0.7?'badge-success':($predictions['model_info']['r2']>0.4?'badge-warning':'badge-danger') ?>">R² <?= number_format($predictions['model_info']['r2'],3) ?> · n=<?= $predictions['model_info']['training_data_size'] ?></span>
        </div>
        <div class="horizon-card-body py-2">
          <small class="text-muted">Trained on this class's custom quarters (<?= htmlspecialchars(!empty($quarterNames) ? implode(', ', array_values($quarterNames)) : 'none created yet') ?>). Low R² blends toward current average to avoid overconfident forecasts. Avg predicted <?= $classStats['average_predicted'] ?>% · At-risk <?= $classStats['at_risk_students'] ?> (<?= $classStats['risk_rate'] ?>%).</small>
        </div>
      </div>

      <div class="horizon-card mb-3">
        <div class="horizon-card-header">
          <strong><i class="fas fa-calculator me-2"></i>What Should They Do Next?</strong>
          <small class="text-muted">Student already has an initial grade — see the next-quarter target to pass</small>
        </div>
        <div class="horizon-card-body">
          <div class="row g-2 align-items-end mb-2">
            <div class="col-12 col-md-7">
              <label class="form-label" for="whatifStudent">Student (uses their initial grade)</label>
              <select class="form-select" id="whatifStudent">
                <option value="">Choose a student...</option>
                <?php foreach ($predictions['predictions'] as $p): ?>
                  <option value="<?= $p['student_id'] ?>"><?= htmlspecialchars($p['name'].' ('.$p['student_number'].') — current '.$p['current_performance'].'%') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-5">
              <label class="form-label" for="whatifTarget">Target overall (%)</label>
              <input type="number" class="form-control" id="whatifTarget" min="1" max="100" value="75">
            </div>
          </div>
          <div id="whatifResult"></div>
        </div>
      </div>

      <div class="mb-3">
        <input type="text" class="form-control" id="studentSearch" placeholder="Search students by name or number...">
      </div>
      <div class="row g-2" id="predictionsList">
        <?php foreach ($predictions['predictions'] as $index => $p):
          $tierCls = ($p['risk_tier']??'On Track')==='On Track' ? 'badge-success' : ((($p['risk_tier']??'')==='Critical'||($p['risk_tier']??'')==='At Risk') ? 'badge-danger' : 'badge-warning');
          $trend = $p['trend'] ?? ['direction'=>'insufficient','label'=>'Not enough data'];
        ?>
        <div class="col-12 col-xl-6 prediction-card-wrap" data-student-name="<?= strtolower($p['name'].' '.$p['student_number']) ?>">
          <div class="horizon-card h-100 mb-0">
            <div class="horizon-card-body">
              <div class="d-flex align-items-center gap-2 mb-2">
                <div class="horizon-widget-icon-box brand" style="width:40px;height:40px;min-width:40px;"><?= strtoupper(substr($p['name'],0,2)) ?></div>
                <div class="flex-grow-1 min-w-0">
                  <div class="fw-bold text-truncate"><?= htmlspecialchars($p['name']) ?></div>
                  <small class="text-muted"><?= htmlspecialchars($p['student_number']) ?> · <?= htmlspecialchars($trend['label']) ?></small>
                </div>
                <span class="badge <?= $tierCls ?>"><?= htmlspecialchars($p['risk_tier'] ?? ($p['will_pass']?'On Track':'At Risk')) ?></span>
              </div>
              <div class="row g-2 text-center mb-2">
                <div class="col-4"><small class="text-muted d-block">Current</small><strong class="text-primary"><?= $p['current_performance'] ?>%</strong></div>
                <div class="col-4"><small class="text-muted d-block">Predicted</small><strong class="text-info"><?= $p['predicted_grade'] ?>%</strong></div>
                <div class="col-4"><small class="text-muted d-block">Pass Prob.</small><strong class="text-success"><?= $p['passing_probability'] ?>%</strong></div>
              </div>
              <div class="progress mb-1" style="height:8px;"><div class="progress-bar bg-primary" style="width:<?php echo min(100,$p['current_performance']); ?>%"></div></div>
              <div class="progress mb-2" style="height:8px;"><div class="progress-bar <?php echo $p['predicted_grade']>=75?'bg-success':'bg-danger'; ?>" style="width:<?php echo min(100,$p['predicted_grade']); ?>%"></div></div>
              <?php if (!empty($p['improvement_plan'])): ?>
              <div class="p-2 mb-2" style="background:var(--horizon-table-hover);border-radius:10px;">
                <small class="fw-bold"><i class="fas fa-list-check me-1"></i>Improvement plan</small>
                <ul class="mb-0 mt-1 ps-3 small">
                  <?php foreach (array_slice($p['improvement_plan'],0,3) as $step): ?><li><?= htmlspecialchars($step['action']) ?></li><?php endforeach; ?>
                </ul>
              </div>
              <?php endif; ?>
              <?php if (!empty($p['suggestions'])): ?>
                <button class="btn btn-sm btn-secondary" onclick="document.getElementById('sug-<?= $p['student_id'] ?>').classList.toggle('d-none')"><i class="fas fa-lightbulb me-1"></i>Tips (<?= count($p['suggestions']) ?>)</button>
                <ul id="sug-<?= $p['student_id'] ?>" class="small mt-2 d-none ps-3">
                  <?php foreach ($p['suggestions'] as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="mt-2"><small class="text-muted">Completeness <?= $p['data_completeness'] ?>% · Model R² <?= $p['model_accuracy'] ?></small></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="text-center py-5">
        <i class="fas fa-chart-line fa-3x mb-3" style="color:var(--horizon-text-muted)"></i>
        <h5>Select a Class to View Predictions</h5>
        <p class="text-muted">AI forecast uses this class's custom quarters from Marks and component weights.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
function loadClassPredictions(){ const id=document.getElementById('classSelect').value; if(id) window.location.href='?page=prediction_dashboard&class_id='+id; }
function refreshPredictions(){ const id=document.getElementById('classSelect').value; if(id) window.location.reload(); }
function exportPredictions(){ const id=document.getElementById('classSelect').value; if(id) window.open('export_predictions.php?class_id='+id,'_blank'); }
function compareStudents(){ const id=document.getElementById('classSelect').value; if(id) window.location.href='?page=student_comparison&class_id='+id; }
const WHATIF_STUDENTS = <?= json_encode(!empty($predictions['predictions']) ? array_reduce($predictions['predictions'], function($c,$p){ $c[$p['student_id']] = ['name'=>$p['name'],'current'=>$p['current_performance'],'quarters'=>$p['quarters'] ?? []]; return $c; }, []) : [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
function whatifCalc(){
  const box=document.getElementById('whatifResult');
  if(!box) return;
  const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const sel=document.getElementById('whatifStudent');
  const target=parseFloat(document.getElementById('whatifTarget').value)||75;
  const st=sel&&sel.value?WHATIF_STUDENTS[sel.value]:null;
  if(!st){
    box.innerHTML='<div class="alert alert-info py-2 mb-0 small">Choose a student above — their initial grade fills in automatically.</div>';
    return;
  }
  const qs=st.quarters||[];
  const done=qs.filter(q=>q.avg!==null&&q.avg!==undefined);
  const missing=qs.filter(q=>q.avg===null||q.avg===undefined);
  const n=qs.length;
  if(n===0){
    box.innerHTML='<div class="alert alert-info py-2 mb-0 small">No quarters found for this class yet.</div>';
    return;
  }
  const sum=done.reduce((a,q)=>a+Math.min(100,Math.max(0,q.avg)),0);
  if(missing.length===0){
    const avg=sum/n, pass=avg>=target;
    let html='<div class="table-responsive"><table class="table horizon-table mb-0"><thead><tr><th>Quarter</th><th class="text-center">Score</th><th class="text-center">Needed</th></tr></thead><tbody>';
    qs.forEach(q=>{ html+='<tr><td>'+esc(q.name)+'</td><td class="text-center fw-bold">'+q.avg+'%</td><td class="text-center text-muted">—</td></tr>'; });
    html+='</tbody></table></div>';
    box.innerHTML=html+'<div class="alert '+(pass?'alert-success':'alert-danger')+' py-2 mb-0 mt-2">Overall average <strong>'+avg.toFixed(1)+'%</strong> — '+(pass?'PASSING (≥ '+target+'%).':'BELOW '+target+'% (needs +'+(target-avg).toFixed(1)+'%).')+'</div>';
    return;
  }
  const required=(target*n-sum)/missing.length;
  const prob=1/(1+Math.exp((required-target)/10));
  const pct=Math.round(Math.max(0.02,Math.min(0.98,prob))*1000)/10;
  const needCell=required>100?'100%+':(required<=0?'0%':required.toFixed(1)+'%');
  let html='<div class="table-responsive"><table class="table horizon-table mb-0"><thead><tr><th>Quarter</th><th class="text-center">Current Score</th><th class="text-center">Needed</th></tr></thead><tbody>';
  qs.forEach(q=>{
    if(q.avg!==null&&q.avg!==undefined){
      html+='<tr><td>'+esc(q.name)+'</td><td class="text-center fw-bold">'+q.avg+'%</td><td class="text-center text-muted">—</td></tr>';
    } else {
      html+='<tr style="background:var(--horizon-table-hover);"><td><span class="badge badge-warning">'+esc(q.name)+'</span></td><td class="text-center text-muted">—</td><td class="text-center fw-bold">'+esc(needCell)+'</td></tr>';
    }
  });
  html+='</tbody></table></div>';
  box.innerHTML=html
    +'<div class="alert '+(required>100?'alert-danger':(required<=0?'alert-success':'alert-warning'))+' py-2 mb-0 mt-2">'
    +'<strong>'+esc(st.name)+'</strong> (initial '+st.current+'%) → to finish at <strong>'+target+'%</strong>, average <strong>'+(required>100?required.toFixed(1)+'% — <strong>not possible</strong>, consider recovery':(required<=0?'0% — <strong>already passing</strong>':required.toFixed(1)+'%'))+'</strong> in: <strong>'+missing.map(q=>esc(q.name)).join(', ')+'</strong>'
    +' — e.g. score <strong>'+(required<=0?'0':required.toFixed(1))+'%</strong> in <strong>'+esc(missing[missing.length-1].name)+'</strong>. Passing probability ≈ <strong>'+pct+'%</strong>.</div>';
}
document.getElementById('whatifStudent')?.addEventListener('change', whatifCalc);
document.getElementById('whatifTarget')?.addEventListener('input', whatifCalc);
whatifCalc();
document.getElementById('studentSearch')?.addEventListener('input', e=>{
  const t=e.target.value.toLowerCase();
  document.querySelectorAll('#predictionsList .prediction-card-wrap').forEach(c=>{ c.style.display=(c.dataset.studentName||'').includes(t)?'':'none'; });
});
</script>
