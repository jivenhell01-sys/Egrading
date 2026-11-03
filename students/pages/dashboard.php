<?php
// Dashboard GPA ring: overall average of transmuted grades across every scored
// quarter (average-of-transmuted, half-even to 1dp). Pure read of the
// same live computation the grades page uses.
$dashVals = [];
if (!empty($grades_data)) {
    foreach ($grades_data as $gdEntry) {
        $gl = isset($gdEntry['grades']) && is_array($gdEntry['grades']) ? $gdEntry['grades'] : [$gdEntry];
        foreach ($gl as $gg) {
            if (empty($gg['class_id'])) continue;
            $dGroups = [];
            foreach ((array)($gg['assessments'] ?? []) as $ax) {
                $dqn = $ax['quarter_name'] ?? ($ax['quarter'] ?? '');
                if ($dqn === '' || $dqn === null) $dqn = '—';
                $dGroups[$dqn][] = $ax;
            }
            foreach ($dGroups as $dList) {
                $dr = compute_live_quarter($dList);
                if (!empty($dr['scored']) && isset($dr['equiv']) && is_numeric($dr['equiv'])) $dashVals[] = floatval($dr['equiv']);
            }
        }
    }
}
$dashAvg = !empty($dashVals) ? round_half_even(array_sum($dashVals) / count($dashVals), 1) : null;
$dashDepEd = false;
foreach ($dashVals as $dv) { if ($dv > 5.5) { $dashDepEd = true; break; } }
$dashRemarks = $dashAvg === null ? '' : remarks_for_grade($dashAvg, $dashDepEd ? 'DEPED' : 'CHED');
$dashBadge = $dashRemarks === 'PASSED' ? 'badge-success' : (($dashRemarks === 'CONDITIONAL') ? 'badge-warning' : 'badge-danger');
if ($dashAvg !== null) {
    $dashPct = $dashDepEd ? max(0, min(100, $dashAvg)) : max(0, min(100, (5.0 - $dashAvg) / 4.0 * 100));
    $dashStroke = $dashDepEd ? ($dashAvg >= 75 ? '#22c55e' : '#ef4444') : ($dashAvg <= 3.0 ? '#22c55e' : ($dashAvg <= 3.5 ? '#f59e0b' : '#ef4444'));
    $dashC = 2 * M_PI * 34;
    $dashOff = $dashC * (1 - $dashPct / 100);
}
?>
<section class="horizon-card hero mb-3"><div class="horizon-card-body"><div class="d-flex gap-3 align-items-start flex-wrap"><div class="ini"><?php $ff=trim($student_info['name'] ?? 'Student'); $pp=preg_split('/\s+/',$ff); echo htmlspecialchars(strtoupper(($pp[0][0]??'S').($pp[1][0]??'T'))); ?></div><div class="flex-grow-1 min-w-0" style="min-width:260px"><div style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--horizon-text-muted)">Welcome back</div><h2 style="font-weight:800;font-size:clamp(1.2rem,2vw,1.55rem);margin:4px 0 6px;color:var(--horizon-text-title)" class="text-truncate"><?php echo $student_info ? htmlspecialchars($student_info['name']) : 'Welcome to HORIZON'; ?></h2><p style="color:var(--horizon-text-muted);font-size:13px;margin:0 0 10px;font-weight:500"><?php echo date('l, F j, Y'); ?> — Your HORIZON records are up to date.</p><?php if($student_info): ?><div class="d-flex flex-wrap gap-2"><span class="chip"><i class="fas fa-id-card" style="color:var(--horizon-brand-500)"></i> <?php echo htmlspecialchars($student_info['student_number']); ?></span><span class="chip"><i class="fas fa-layer-group" style="color:var(--horizon-brand-500)"></i> <?php echo htmlspecialchars($student_info['grade'].' — '.$student_info['section']); ?></span><?php if(!empty($student_info['subject_name'])): ?><span class="chip"><i class="fas fa-book-open" style="color:var(--horizon-brand-500)"></i> <?php echo htmlspecialchars($student_info['subject_name']); ?></span><?php endif; ?></div><?php else: ?><div class="horizon-flash-banner horizon-flash-warning"><i class="fas fa-exclamation-triangle"></i> Student information not found. Please contact the registrar.</div><?php endif; ?>
<?php if($dashAvg !== null): ?>
<div class="ms-auto gpa-ring">
  <svg width="104" height="104" viewBox="0 0 96 96" role="img" aria-label="Overall average <?php echo htmlspecialchars(number_format($dashAvg,1)); ?>">
    <circle cx="48" cy="48" r="34" fill="none" stroke="var(--horizon-card-border)" stroke-width="9"/>
    <circle cx="48" cy="48" r="34" fill="none" stroke="<?php echo $dashStroke; ?>" stroke-width="9" stroke-linecap="round" stroke-dasharray="<?php echo $dashC; ?>" stroke-dashoffset="<?php echo $dashOff; ?>" transform="rotate(-90 48 48)"/>
    <text x="48" y="47" text-anchor="middle" font-size="17" font-weight="800" fill="var(--horizon-text-title)"><?php echo htmlspecialchars(number_format($dashAvg,1)); ?></text>
    <text x="48" y="63" text-anchor="middle" font-size="8.5" font-weight="700" letter-spacing="1" fill="var(--horizon-text-muted)">AVERAGE</text>
  </svg>
  <div class="mt-1"><span class="badge <?php echo $dashBadge; ?>"><?php echo htmlspecialchars($dashRemarks); ?></span></div>
</div>
<?php endif; ?>
</div></div>
<?php $pubC=0;$assC=0;$clsC=0; if(!empty($grades_data)){ $tt=[]; $liveQKeys=[]; foreach($grades_data as $gdEntry){ $gl = isset($gdEntry['grades']) && is_array($gdEntry['grades']) ? $gdEntry['grades'] : [$gdEntry]; foreach($gl as $gg){ if(!empty($gg['class_id']) && !empty($gg['subject_id'])) $tt[$gg['class_id'].'_'.$gg['subject_id']]=true; foreach(($gg['assessments']??[]) as $sa){ $sm=$sa['mark']??null; if($sm!==null&&$sm!==''&&is_numeric($sm)&&floatval($sm)>0){ $liveQKeys[($gg['class_id']??'').'|'.($gg['subject_id']??'').'|'.($sa['quarter']??'')] = true; } } $assC+=count($gg['assessments']??[]); } } $clsC=count($tt); $pubC=count($liveQKeys); } ?>
<div class="row g-2 mt-3"><div class="col-6 col-lg-4"><div class="stat"><div class="lbl">Enrolled Classes</div><div class="num"><?php echo $clsC; ?></div></div></div><div class="col-6 col-lg-4"><div class="stat"><div class="lbl">Graded Quarters</div><div class="num"><?php echo $pubC; ?></div></div></div><div class="col-12 col-lg-4"><div class="stat"><div class="lbl">Assessments Tracked</div><div class="num"><?php echo $assC; ?></div></div></div></div>
</div></section>

<div class="row g-3 mb-3">
  <div class="col-12 col-md-6">
    <a href="index.php?page=grades" class="text-decoration-none">
      <div class="horizon-card h-100"><div class="horizon-card-body d-flex align-items-center gap-3">
        <span class="horizon-widget-icon-box brand" style="width:44px;height:44px;min-width:44px;font-size:17px"><i class="fas fa-chart-line"></i></span>
        <span><span class="d-block fw-bold" style="font-size:14px;color:var(--horizon-text-title)">My Grades</span><small class="text-muted">Live-computed per class and quarter</small></span>
        <i class="fas fa-chevron-right ms-auto" style="color:var(--horizon-text-muted)"></i>
      </div></div>
    </a>
  </div>
  <div class="col-12 col-md-6">
    <a href="index.php?page=quizzes" class="text-decoration-none">
      <div class="horizon-card h-100"><div class="horizon-card-body d-flex align-items-center gap-3">
        <span class="horizon-widget-icon-box amber" style="width:44px;height:44px;min-width:44px;font-size:17px"><i class="fas fa-pen-square"></i></span>
        <span><span class="d-block fw-bold" style="font-size:14px;color:var(--horizon-text-title)">Quizzes <span class="badge rounded-pill bg-danger ms-1" data-quiz-count style="font-size:9px">0 new</span></span><small class="text-muted">Activated quizzes for your classes</small></span>
        <i class="fas fa-chevron-right ms-auto" style="color:var(--horizon-text-muted)"></i>
      </div></div>
    </a>
  </div>
</div>

<section class="horizon-card mb-3">
  <div class="horizon-card-header">
    <div class="d-flex align-items-center gap-2"><span class="horizon-widget-icon-box brand" style="width:36px;height:36px;min-width:36px;font-size:14px"><i class="fas fa-bell"></i></span><strong>Pending/Submitted quizzes</strong></div>
    <a href="index.php?page=quizzes" class="btn btn-sm btn-secondary">View all</a>
  </div>
  <div class="horizon-card-body"><div data-quiz-list data-limit="3"><div class="text-center py-3" style="color:var(--horizon-text-muted);font-size:12px"><div class="spinner-border spinner-border-sm text-primary"></div><div class="mt-2">Checking for quizzes…</div></div></div></div>
</section>
