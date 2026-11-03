<section id="grades">
<!-- Original welcome-card replaced by HORIZON hero above; grades follow -->
<!-- Legacy welcome removed — HORIZON hero above covers student info -->
        <!-- Grades Section (HORIZON) -->
        <div id="grades-section" style="display: block;">
            <?php if (!empty($grades_data)): ?>
                <?php
                // Group grades by class for display — handle wrappers (fetch returns all DBs) + keep faculty per subject
                $grouped_grades = [];
                foreach ($grades_data as $wrapper) {
                    $list = isset($wrapper['grades']) && is_array($wrapper['grades']) ? $wrapper['grades'] : [$wrapper];
                    foreach ($list as $grade) {
                        if (empty($grade['class_id']) || empty($grade['subject_id'])) continue;
                        $class_key = $grade['class_id'] . '_' . $grade['subject_id'] . '_' . md5($grade['faculty'] ?? $wrapper['database'] ?? '');
                        if (!isset($grouped_grades[$class_key])) {
                            $grouped_grades[$class_key] = [
                                'class_id' => $grade['class_id'],
                                'subject_name' => $grade['subject_name'],
                                'class_name' => $grade['class_name'],
                                'year' => $grade['year'],
                                'semester' => $grade['semester'],
                                'student_name' => $grade['student_name'],
                                'student_number' => $grade['student_number'],
                                'faculty' => $grade['faculty'] ?? $wrapper['database'] ?? '—',
                                'db_label' => $grade['db_label'] ?? $wrapper['database'] ?? '',
                                'grades' => []
                            ];
                        }
                        $grouped_grades[$class_key]['grades'][] = $grade;
                    // Update class info from first grade if not set
                    if (empty($grouped_grades[$class_key]['class_name'])) {
                        $grouped_grades[$class_key]['class_name'] = $grade['class_name'];
                        $grouped_grades[$class_key]['year'] = $grade['year'];
                        $grouped_grades[$class_key]['semester'] = $grade['semester'];
                        $grouped_grades[$class_key]['student_name'] = $grade['student_name'];
                        $grouped_grades[$class_key]['student_number'] = $grade['student_number'];
                    }
                    }
                }

                // Flatten the nested grade structure
                $flattened_grouped_grades = [];
                foreach ($grouped_grades as $classKey => $classData) {
                    $flattened_grouped_grades[$classKey] = $classData;
                    $actual_grades = [];

                    // Check if grades are nested
                    if (!empty($classData['grades'])) {
                        foreach ($classData['grades'] as $grade_record) {
                            if (isset($grade_record['grades']) && is_array($grade_record['grades'])) {
                                // This is a nested structure, flatten it
                                foreach ($grade_record['grades'] as $actual_grade) {
                                    $actual_grades[] = $actual_grade;
                                }
                                // Update class info from the nested record
                                if (!empty($actual_grade)) {
                                    $flattened_grouped_grades[$classKey]['class_name'] = $actual_grade['class_name'];
                                    $flattened_grouped_grades[$classKey]['year'] = $actual_grade['year'];
                                    $flattened_grouped_grades[$classKey]['semester'] = $actual_grade['semester'];
                                    $flattened_grouped_grades[$classKey]['student_name'] = $actual_grade['student_name'];
                                    $flattened_grouped_grades[$classKey]['student_number'] = $actual_grade['student_number'];
                                    $flattened_grouped_grades[$classKey]['subject_name'] = $actual_grade['subject_name'];
                                }
                            } else {
                                // This is a direct grade record
                                $actual_grades[] = $grade_record;
                            }
                        }
                    }
                    $flattened_grouped_grades[$classKey]['grades'] = $actual_grades;
                }

                // Extract unique school years and semesters for filtering
                $available_years = [];
                $available_semesters = [];
                foreach ($flattened_grouped_grades as $classData) {
                    if (!empty($classData['year'])) {
                        $available_years[] = $classData['year'];
                    }
                    if (!empty($classData['semester'])) {
                        $available_semesters[] = $classData['semester'];
                    }
                }
                $available_years = array_unique($available_years);
                $available_semesters = array_unique($available_semesters);
                sort($available_years);
                sort($available_semesters);

                // Get current filter values (default to all)
                $selected_year = $_GET['year'] ?? 'all';
                $selected_semester = $_GET['semester'] ?? 'all';
                ?>

                <!-- Filter Section (HORIZON) -->
                <div class="horizon-card mb-3">
                  <div class="horizon-card-header">
                    <div class="d-flex align-items-center gap-2"><span class="horizon-widget-icon-box brand" style="width:36px;height:36px;min-width:36px;font-size:14px"><i class="fas fa-filter"></i></span><strong>Filter Grades</strong><span class="d-none d-sm-inline" style="color:var(--horizon-text-muted);font-weight:600;font-size:12px">— by year &amp; semester</span></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="resetFilters()"><i class="fas fa-sync-alt me-1"></i>Reset</button>
                  </div>
                  <div class="horizon-card-body">
                    <div class="filter-bar">
                      <div class="seg-group" role="group" aria-label="Filter by school year">
                        <span class="seg-cap">Year</span>
                        <a class="seg-pill<?php echo $selected_year === 'all' ? ' active' : ''; ?>"<?php echo $selected_year === 'all' ? ' aria-current="true"' : ''; ?> href="index.php?page=grades<?php echo $selected_semester !== 'all' ? '&semester=' . urlencode($selected_semester) : ''; ?>">All</a>
                        <?php foreach ($available_years as $year): ?>
                        <a class="seg-pill<?php echo $selected_year === $year ? ' active' : ''; ?>"<?php echo $selected_year === $year ? ' aria-current="true"' : ''; ?> href="index.php?page=grades&year=<?php echo urlencode($year); ?><?php echo $selected_semester !== 'all' ? '&semester=' . urlencode($selected_semester) : ''; ?>"><?php echo htmlspecialchars($year); ?></a>
                        <?php endforeach; ?>
                      </div>
                      <div class="seg-group" role="group" aria-label="Filter by semester">
                        <span class="seg-cap">Semester</span>
                        <a class="seg-pill<?php echo $selected_semester === 'all' ? ' active' : ''; ?>"<?php echo $selected_semester === 'all' ? ' aria-current="true"' : ''; ?> href="index.php?page=grades<?php echo $selected_year !== 'all' ? '&year=' . urlencode($selected_year) : ''; ?>">All</a>
                        <?php foreach ($available_semesters as $semester): ?>
                        <a class="seg-pill<?php echo $selected_semester === $semester ? ' active' : ''; ?>"<?php echo $selected_semester === $semester ? ' aria-current="true"' : ''; ?> href="index.php?page=grades<?php echo $selected_year !== 'all' ? '&year=' . urlencode($selected_year) : ''; ?>&semester=<?php echo urlencode($semester); ?>"><?php echo htmlspecialchars($semester); ?></a>
                        <?php endforeach; ?>
                      </div>
                      <span class="ms-auto d-none d-md-inline-flex align-items-center gap-2" style="font-size:11px;color:var(--horizon-text-muted);font-weight:700"><i class="fas fa-info-circle"></i>Custom quarters from quarter_list</span>
                    </div>
                  </div>
                </div>
                <div class="grid">

                <script>
                function resetFilters() {
                    const url = new URL(window.location);
                    url.searchParams.delete('year');
                    url.searchParams.delete('semester');
                    window.location.href = url.toString();
                }
                </script>

                <?php
                // Display each class with its grades
                $aiContextClasses = [];
                foreach ($flattened_grouped_grades as $classKey => $classData): ?>
                    <?php
                    // Apply filters
                    $show_class = true;
                    if ($selected_year !== 'all' && $classData['year'] !== $selected_year) {
                        $show_class = false;
                    }
                    if ($selected_semester !== 'all' && $classData['semester'] !== $selected_semester) {
                        $show_class = false;
                    }

                    // Only show card if we have valid data and passes filters (live-computed grades OR assessments exist)
                    $classAssessments = $classData['grades'][0]['assessments'] ?? [];
                    // Group assessments by quarter once; compute live grades per group.
                    $liveGroups = [];
                    foreach ($classAssessments as $ax) {
                        $qn = $ax['quarter_name'] ?? ($ax['quarter'] ?? '');
                        if ($qn === '' || $qn === null) $qn = '—';
                        $liveGroups[$qn][] = $ax;
                    }
                    uksort($liveGroups, function($a,$b){ $na=intval(preg_replace('/\D/','',$a)); $nb=intval(preg_replace('/\D/','',$b)); if($na==$nb) return strcmp($a,$b); return $na<=>$nb; });
                    $liveQuarters = [];
                    $hasLive = false;
                    foreach ($liveGroups as $lqn => $llist) {
                        $liveQuarters[$lqn] = compute_live_quarter($llist);
                        if (!empty($liveQuarters[$lqn]['scored'])) $hasLive = true;
                    }
                    $hasAssessments = !empty($classAssessments);
                    $liveQuartersScored = [];
                    if (!empty($classData['subject_name']) && ($hasLive || $hasAssessments) && $show_class):
                    ?>
                    <article class="sub">
                      <div class="sub-hd collapsed" data-bs-toggle="collapse" data-bs-target="#acc-<?php echo $classKey; ?>" aria-expanded="false" role="button">
                        <div class="sub-title"><span class="icon"><i class="fas fa-book-open"></i></span><span class="text-truncate"><?php echo htmlspecialchars($classData['subject_name']); ?> <span style="color:var(--horizon-text-muted);font-weight:600">· <?php echo htmlspecialchars($classData['class_name']); ?></span></span></div>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                          <?php if(!empty($classData['year'])): ?><span class="badge badge-secondary"><i class="fas fa-calendar me-1"></i><?php echo $classData['year']; ?>—<?php echo $classData['year']+1; ?></span><?php endif; ?>
                          <?php if(!empty($classData['semester'])): ?><span class="badge badge-secondary"><?php echo htmlspecialchars($classData['semester']); ?></span><?php endif; ?>
                          <span class="badge <?php echo $hasLive?'badge-success':'badge-warning'; ?>"><?php echo $hasLive?'Live grades':'No grades yet'; ?></span>
                          <i class="fas fa-chevron-down" style="color:var(--horizon-text-muted);font-size:12px"></i>
                        </div>
                      </div>
                      <div id="acc-<?php echo $classKey; ?>" class="collapse flex-grow-1">
                        <div class="px-3 py-2 d-flex flex-wrap gap-2 align-items-center" style="font-size:12px;color:var(--horizon-text-muted);font-weight:600;border-bottom:1px solid var(--horizon-card-border);background:var(--horizon-card-bg)">
                          <span><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($classData['student_name']); ?></span><span style="opacity:.5">•</span><span><?php echo htmlspecialchars($classData['student_number']); ?></span>
                          <span style="opacity:.5">•</span><span style="color:var(--horizon-brand-500); font-weight:700">INSTRUCTOR: <i class="fas fa-chalkboard-teacher me-1"></i><?php echo htmlspecialchars($classData['faculty'] ?? '—'); ?></span>
                          <span class="ms-auto d-inline-flex align-items-center gap-1" style="font-size:11px"><i class="fas fa-layer-group"></i><?php echo htmlspecialchars($classData['class_name']); ?></span>
                        </div>
                        <?php
                        // Quarter summary only: component / marks / scores are
                        // used for live computation but never rendered.
                        // $liveGroups/$liveQuarters were built at the card top.
                        // Footer placeholders; computed from live quarters after the loop.
                        $totM=0; $vM=0; $avgGradeM=0; $avgStrM='N/A'; $avgClsM='text-muted'; $avgRemM='NO GRADE';
                        ?>
                        <div class="collage" style="display:block; padding:16px">
                          <div class="d-flex align-items-center justify-content-between mb-2"><div style="font-weight:800;color:var(--horizon-text-title);font-size:13px"><i class="fas fa-layer-group me-1" style="color:var(--horizon-brand-500)"></i>Live Grades <span class="text-muted fw-normal" style="font-size:11px">— computed live per quarter</span></div><span class="badge badge-secondary"><?php $liveScored = 0; foreach(($liveQuarters ?? []) as $lq0){ if(!empty($lq0['scored'])) $liveScored++; } echo $liveScored>0?$liveScored.' graded quarter'.($liveScored!=1?'s':''):'no graded quarters'; ?></span></div>
                          <div class="h-scroll">
                            <table class="table horizon-table mb-0">
                              <thead><tr><th class="text-center" style="width:160px">Quarter</th><th class="text-center" style="width:120px">Initial</th><th class="text-center" style="width:140px; background:var(--horizon-brand-50); color:var(--horizon-brand-500); border-left:2px solid var(--horizon-brand-200)">Transmuted</th><th class="text-center" style="width:160px; background:var(--horizon-brand-50); color:var(--horizon-brand-500); border-left:1px solid var(--horizon-card-border)">Remarks</th></tr></thead>
                              <tbody>
                                <?php $qcardsHtml=''; if(!empty($liveQuarters)): foreach($liveQuarters as $qn2=>$live):
                                $firstAQ = null; $firstAQN = $qn2;
                                if (isset($liveGroups[$qn2][0])) { $firstAQ = $liveGroups[$qn2][0]['quarter'] ?? null; $firstAQN = $liveGroups[$qn2][0]['quarter_name'] ?? $qn2; }
                                if (!empty($live['scored'])) {
                                    $liveQuartersScored[] = ['q' => $firstAQ, 'name' => ($firstAQN !== '' && $firstAQN !== null) ? $firstAQN : $qn2, 'initial' => $live['initial'], 'transmuted' => $live['equiv_num'], 'remarks' => $live['remarks']];
                                }
                                $li = (!empty($live['scored']) && $live['initial'] !== null) ? floatval($live['initial']) : null;
                                $lt = (!empty($live['scored'])) ? $live['equiv'] : null;
                                $lrem = $live['remarks'] ?? 'NOT GRADED';
                                $lcls = ($lrem==='PASSED') ? 'text-success' : (($lrem==='CONDITIONAL') ? 'text-warning' : ((in_array($lrem, ['NO GRADE','NOT GRADED'], true)) ? 'text-muted' : 'text-danger'));
                                $barPct = 0; $barCls = 'qbar-bad';
                                if ($li !== null) {
                                    $barPct = max(0, min(100, (int)round($li)));
                                    $barCls = ($lrem === 'PASSED') ? 'qbar-ok' : ((($lrem === 'CONDITIONAL') || ($lrem === 'INC')) ? 'qbar-warn' : 'qbar-bad');
                                }
                                // Mobile card twin of this row (table stays for desktop).
                                $qTone = ($lrem === 'PASSED') ? 'ok' : ((($lrem === 'CONDITIONAL') || ($lrem === 'INC')) ? 'warn' : ((!empty($live['scored'])) ? 'bad' : 'muted'));
                                $qcardsHtml .= '<div class="qcard ' . $qTone . '"><div class="qcard-top"><span class="qcard-q">' . htmlspecialchars($qn2) . '</span>';
                                if (!empty($live['scored'])) {
                                    $qcardsHtml .= '<span class="badge ' . ($lcls === 'text-success' ? 'badge-success' : ($lcls === 'text-warning' ? 'badge-warning' : 'badge-danger')) . '" style="font-size:10px">' . htmlspecialchars($lrem) . '</span>';
                                } else {
                                    $qcardsHtml .= '<span style="color:var(--horizon-text-muted);font-size:11px">Not graded</span>';
                                }
                                $qcardsHtml .= '</div><div class="qcard-mid"><span class="qcard-big ' . $lcls . '">' . ($lt !== null ? htmlspecialchars($lt) : '—') . '</span><span class="qcard-sub">Transmuted<br>Initial ' . ($li !== null ? htmlspecialchars(number_format($li, 2)) : '—') . '</span></div>';
                                if ($li !== null) {
                                    $qcardsHtml .= '<div class="qbar"><i class="' . $barCls . '" style="width:' . $barPct . '%"></i></div>';
                                }
                                $qcardsHtml .= '</div>';
                                ?>
                                <tr class="live-grade" style="font-weight:700">
                                  <td class="text-center" style="font-size:12px"><span class="badge badge-brand" style="font-size:10px"><?php echo htmlspecialchars($qn2); ?></span><?php if($li!==null): ?><div class="qbar" title="Initial <?php echo htmlspecialchars(number_format($li,2)); ?>"><i class="<?php echo $barCls; ?>" style="width:<?php echo $barPct; ?>%"></i></div><?php endif; ?></td>
                                  <td class="text-center fw-bold" style="font-family:monospace;font-size:12px"><?php echo $li!==null?htmlspecialchars(number_format($li,2)):'<span style="color:var(--horizon-text-muted)">—</span>'; ?></td>
                                  <td class="text-center fw-bold <?php echo $lcls; ?>" style="font-family:monospace;font-size:12px; border-left:2px solid var(--horizon-brand-200)"><?php echo $lt!==null?htmlspecialchars($lt):'<span style="color:var(--horizon-text-muted)">—</span>'; ?></td>
                                  <td class="text-center"><?php if(!empty($live['scored'])): ?><span class="badge <?php echo $lcls==='text-success'?'badge-success':($lcls==='text-warning'?'badge-warning':'badge-danger'); ?>" style="font-size:10px"><?php echo htmlspecialchars($lrem); ?></span><?php else: ?><span style="color:var(--horizon-text-muted);font-size:11px">Not graded</span><?php endif; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr><td colspan="4" class="text-center py-4" style="color:var(--horizon-text-muted)"><i class="fas fa-inbox mb-2 d-block"></i>No grades computed yet.</td></tr>
                                <?php endif; ?>
                              </tbody>
                            </table>
                          </div>
                          <div class="qcards">
                            <?php echo $qcardsHtml !== '' ? $qcardsHtml : '<div class="qcard muted qcard-empty"><i class="fas fa-inbox mb-2 d-block"></i>No grades computed yet.</div>'; ?>
                          </div>
                          <?php
                          // Average footer from live quarters:
                          // average of the quarters' TRANSMUTED grades (never
                          // transmute-of-average), half-even (banker's) to 1dp
                          // — e.g. (1.8 + 1.5)/2 = 1.65 -> 1.6; any quarter
                          // INC forces the average to INC (same rule as the
                          // class record and the Report of Grades).
                          $totM=0; $vM=0; $avgGradeM=0; $avgStrM='N/A'; $avgClsM='text-muted'; $avgRemM='NO GRADE';
                          $avgHasInc=false;
                          foreach($liveQuartersScored as $lq){ if(isset($lq['remarks']) && strtoupper(trim($lq['remarks']))==='INC'){ $avgHasInc=true; break; } }
                          $isDepEdLive=false;
                          foreach($liveQuartersScored as $lq){ if(isset($lq['transmuted'])&&is_numeric($lq['transmuted'])&&floatval($lq['transmuted'])>5.5){ $isDepEdLive=true; break; } }
                          if ($avgHasInc) {
                              foreach($liveQuartersScored as $lq){ $totM+=floatval($lq['initial']); }
                              $vM=count($liveQuartersScored);
                              $avgStrM='INC'; $avgRemM='INC'; $avgClsM='text-warning';
                          } elseif(!empty($liveQuartersScored)){
                              foreach($liveQuartersScored as $lq){ $totM+=floatval($lq['initial']); }
                              $vM=count($liveQuartersScored);
                              $avgVals=[];
                              foreach($liveQuartersScored as $lq){ if(isset($lq['transmuted'])&&is_numeric($lq['transmuted'])) $avgVals[]=floatval($lq['transmuted']); }
                              if(!empty($avgVals)){
                                  $avgT=round_half_even(array_sum($avgVals)/count($avgVals),1);
                                  $avgStrM=number_format($avgT,1); $avgGradeM=$avgT;
                                  $avgRemM=remarks_for_grade($avgT,$isDepEdLive?'DEPED':'CHED');
                              }
                              $avgClsM=$avgRemM==='PASSED'?'text-success':($avgRemM==='CONDITIONAL'?'text-warning':($avgRemM==='NO GRADE'?'text-muted':($avgRemM==='INC'?'text-warning':'text-danger')));
                          }
                          ?>
                          <?php if(!empty($liveQuartersScored)): ?>
                          <div class="mt-3 d-flex align-items-center justify-content-between flex-wrap gap-2 p-3" style="background:var(--horizon-table-hover); border:1px solid var(--horizon-card-border); border-radius:12px">
                            <span style="font-weight:800; font-size:13px; color:var(--horizon-text-title)"><i class="fas fa-award me-2" style="color:var(--horizon-brand-500)"></i> Average Grade <span class="badge badge-brand" style="font-size:9px">live</span></span>
                            <span class="d-flex align-items-center gap-2"><?php if($vM>0): ?><span style="font-size:11px;color:var(--horizon-text-muted);font-weight:700">Initial <?php echo number_format($totM/$vM,2); ?> ·</span><?php endif; ?><span class="fw-bold <?php echo $avgClsM; ?>" style="font-family:monospace; font-size:1.15rem"><?php echo $avgStrM; ?></span><span class="badge <?php echo $avgClsM==='text-success'?'badge-success':($avgClsM==='text-warning'?'badge-warning':'badge-danger'); ?>" style="font-size:11px"><?php echo htmlspecialchars($avgRemM); ?></span></span>
                            <?php $avgBarPct = $vM>0 ? max(0, min(100, (int)round($totM/$vM))) : 0; $avgBarCls = $avgClsM==='text-success'?'qbar-ok':($avgClsM==='text-warning'?'qbar-warn':'qbar-bad'); ?>
                            <div class="qbar qbar-lg" title="Average initial <?php echo $vM>0 ? htmlspecialchars(number_format($totM/$vM,2)) : '—'; ?>"><i class="<?php echo $avgBarCls; ?>" style="width:<?php echo $avgBarPct; ?>%"></i></div>
                          </div>
                          <?php endif; ?>
                          <?php
                          $aiContextClasses[] = [
                              'subject' => $classData['subject_name'] ?? '',
                              'class_name' => $classData['class_name'] ?? '',
                              'faculty' => $classData['faculty'] ?? '',
                              'published' => array_map(function($s) {
                                  return ['q' => $s['q'], 'name' => $s['name'], 'initial' => $s['initial'], 'transmuted' => $s['transmuted'], 'remarks' => $s['remarks']];
                              }, $liveQuartersScored),
                              'assessments' => array_map(function($a) {
                                  return ['component' => $a['component_name'] ?? '', 'weight' => $a['component_percentage'] ?? null, 'quarter' => $a['quarter'] ?? '', 'quarter_name' => $a['quarter_name'] ?? '', 'name' => $a['assessment_name'] ?? '', 'mark' => $a['mark'] ?? null, 'hps' => $a['hps'] ?? null, 'percentage' => $a['percentage'] ?? null];
                              }, $classAssessments),
                          ];
                          echo build_student_insight(array_map(function($s) {
                              return ['quarter' => $s['q'], 'quarter_name' => $s['name'], 'initial_grade' => $s['initial'], 'transmuted_grade' => $s['transmuted'], 'remarks' => $s['remarks']];
                          }, $liveQuartersScored), $classAssessments); ?>
                          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-2" style="font-size:11px;color:var(--horizon-text-muted);font-weight:600"><span><i class="fas fa-info-circle me-1"></i><strong style="color:var(--horizon-brand-500)">Live Grades</strong> (computed live, no publish needed)</span><span><i class="fas fa-info-circle me-1"></i><?php if(!empty($liveQuartersScored)): ?><?php echo count($liveQuartersScored); ?> quarter grade<?php echo count($liveQuartersScored)!=1?'s':'';?> computed live for this class<?php else: ?>No graded quarters yet<?php endif; ?></span></div>

                        </div><!-- /collage -->
                      </div><!-- /collapse -->
                    </article>
                    <?php endif; ?>
                <?php endforeach; ?>
                </div><!-- /grid whole-screen organized -->
            <?php
            // Check if any cards were displayed
            $cards_displayed = false;
            foreach ($flattened_grouped_grades as $classKey => $classData) {
                $show_class = true;
                if ($selected_year !== 'all' && $classData['year'] !== $selected_year) {
                    $show_class = false;
                }
                if ($selected_semester !== 'all' && $classData['semester'] !== $selected_semester) {
                    $show_class = false;
                }
                $has_any = !empty($classData['grades'][0]['assessments']);
                if ($show_class && !empty($classData['subject_name']) && $has_any) {
                    $cards_displayed = true;
                    break;
                }
            }

            if (!$cards_displayed): ?>
                <div class="horizon-card"><div class="horizon-card-body text-center" style="padding:28px;color:var(--horizon-text-muted)"><i class="fas fa-filter mb-2 d-block" style="font-size:22px"></i><strong style="color:var(--horizon-text-title)">No Grades Found</strong><p class="mb-0 small">No grades found for the selected filters. Try adjusting filters.</p></div></div>
            <?php endif; ?>
            <?php else: ?>
                <div class="horizon-card"><div class="horizon-card-body text-center" style="padding:36px;color:var(--horizon-text-muted)"><i class="fas fa-chart-line mb-3 d-block" style="font-size:26px"></i><strong style="color:var(--horizon-text-title)">No Grades Yet</strong><p class="mb-0 small">No assessments tracked yet. Your grades compute live here once coursework is recorded.</p></div></div>
            <?php endif; ?>
        </div><!-- /grades-section -->
        </section><!-- /grades HORIZON -->

    <script>
        function viewGrades() {
            const gradesSection = document.getElementById('grades-section');
            gradesSection.style.display = gradesSection.style.display === 'none' ? 'block' : 'none';
        }

        // Show grades section by default
        document.addEventListener('DOMContentLoaded', function() {
            const gradesSection = document.getElementById('grades-section');
            if (gradesSection) {
                gradesSection.style.display = 'block';
            }
        });
    </script>
