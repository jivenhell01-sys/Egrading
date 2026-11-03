<?php
// Horizon student-portal assistant engine.
// Local, deterministic Q&A over the student's own records (no external API,
// no database access here — the caller supplies a compact context built from
// rows the student is already allowed to see). Pure functions: safe to test.
if (!function_exists('ai_assistant_reply')) {

    function ai_norm($s) {
        $s = strtolower((string)$s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    function ai_tokens($s) {
        $stop = ['the','a','an','my','me','i','in','of','for','to','is','are','was','my','and','or','on','at','it','this','that','with','do','does','did','what','whats','how','why','when','which','who','there','here','please','po','ko','ako','sa','ang','ng','mga','ba','ko'];
        $out = [];
        foreach (explode(' ', ai_norm($s)) as $t) {
            if ($t !== '' && !in_array($t, $stop, true)) $out[] = $t;
        }
        return $out;
    }

    function ai_has_any($norm, $words) {
        foreach ($words as $w) {
            if (strpos($norm, $w) !== false) return true;
        }
        return false;
    }

    function ai_esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    function ai_num($v, $dec = 1) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $f = floatval($v);
        $s = number_format($f, $dec, '.', '');
        if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    // Scale detection shared with the rest of the system: DepEd transmuted
    // grades live at 60-100, CHED equivalents at 1.0-5.5.
    function ai_is_deped_value($v) { return is_numeric($v) && floatval($v) > 5.5; }

    function ai_class_scale($cls) {
        foreach (($cls['published'] ?? []) as $p) {
            if (isset($p['transmuted']) && is_numeric($p['transmuted'])) {
                return ai_is_deped_value($p['transmuted']) ? 'DEPED' : 'CHED';
            }
        }
        return 'CHED';
    }

    function ai_pass_line($cls) { return ai_class_scale($cls) === 'DEPED' ? 75 : 3.0; }

    // Subject entity resolution against enrolled classes.
    function ai_match_subject($tokens, $classes) {
        $best = null; $bestScore = 0; $tied = [];
        foreach ($classes as $idx => $cls) {
            $hay = ai_tokens(($cls['subject'] ?? '') . ' ' . ($cls['class_name'] ?? ''));
            if (empty($hay) || empty($tokens)) continue;
            $score = 0;
            foreach ($tokens as $t) {
                if (strlen($t) < 3) continue;
                foreach ($hay as $h) {
                    if ($h === $t) { $score += 2; break; }
                    if (strlen($h) > 4 && strlen($t) > 4 && (strpos($h, $t) === 0 || strpos($t, $h) === 0)) { $score += 1; break; }
                }
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $idx; $tied = [$idx]; }
            elseif ($score > 0 && $score === $bestScore) { $tied[] = $idx; }
        }
        if ($best === null || $bestScore <= 0) return ['status' => 'none'];
        if (count($tied) > 1) return ['status' => 'ambiguous', 'options' => array_slice($tied, 0, 3)];
        return ['status' => 'found', 'index' => $best];
    }

    // Quarter entity: custom names, "midterm/final"-style words, or numbers.
    function ai_match_quarter($norm, $cls) {
        $names = [];
        foreach (($cls['published'] ?? []) as $p) {
            if (isset($p['q'])) $names[(int)$p['q']] = $p['name'] ?? ('Quarter ' . $p['q']);
        }
        foreach (($cls['assessments'] ?? []) as $a) {
            $qn = isset($a['quarter']) ? intval($a['quarter']) : 0;
            if ($qn > 0 && !isset($names[$qn])) $names[$qn] = $a['quarter_name'] ?? ('Quarter ' . $qn);
        }
        if (empty($names)) return null;
        foreach ($names as $qn => $nm) {
            $nn = ai_norm($nm);
            if ($nn !== '' && strpos($norm, $nn) !== false) return $qn;
        }
        if (preg_match('/\bquarter\s*(\d)\b/', $norm, $m) && isset($names[(int)$m[1]])) return (int)$m[1];
        if (preg_match('/\bq\s*(\d)\b/', $norm, $m) && isset($names[(int)$m[1]])) return (int)$m[1];
        $order = ['first' => 0, 'second' => 1, 'third' => 2, 'fourth' => 3, 'last' => -1, 'latest' => -1];
        $keys = array_keys($names); sort($keys);
        foreach ($order as $w => $pos) {
            if (strpos($norm, $w) !== false) {
                if ($pos === -1) return end($keys);
                if (isset($keys[$pos])) return $keys[$pos];
            }
        }
        return null;
    }

    function ai_component_stats($cls) {
        $comp = [];
        foreach (($cls['assessments'] ?? []) as $a) {
            $cn = trim($a['component'] ?? 'Component');
            if ($cn === '') $cn = 'Component';
            if (!isset($comp[$cn])) $comp[$cn] = ['score' => 0.0, 'hps' => 0.0, 'n' => 0, 'weight' => null, 'pending' => 0, 'failed' => []];
            if (isset($a['weight']) && is_numeric($a['weight'])) $comp[$cn]['weight'] = floatval($a['weight']);
            $hps = floatval($a['hps'] ?? 0);
            $mk = $a['mark'] ?? null;
            if ($mk === null || $mk === '' || !is_numeric($mk) || floatval($mk) <= 0) {
                if ($hps > 0) $comp[$cn]['pending']++;
                continue;
            }
            $comp[$cn]['score'] += floatval($mk);
            $comp[$cn]['hps'] += $hps;
            $comp[$cn]['n']++;
            if ($hps > 0 && (floatval($mk) / $hps) < 0.75) {
                $comp[$cn]['failed'][] = ['name' => $a['name'] ?? 'Assessment', 'mark' => floatval($mk), 'hps' => $hps];
            }
        }
        return $comp;
    }

    function ai_weakest($comp) {
        $best = null;
        foreach ($comp as $cn => $c) {
            if ($c['n'] > 0 && $c['hps'] > 0) {
                $avg = 100 * $c['score'] / $c['hps'];
                if ($best === null || $avg < $best[1]) $best = [$cn, $avg];
            }
        }
        return $best;
    }

    function ai_verdict_word($remarks) {
        $r = strtoupper(trim((string)$remarks));
        if (in_array($r, ['PASSED', 'PASSED WITH DISTINCTION', 'PASSED WITH HONORS'], true)) return 'passing';
        if ($r === 'CONDITIONAL') return 'conditional';
        if ($r === '' || $r === 'N/A') return 'not yet graded';
        return 'failing';
    }

    function ai_subject_line($cls) {
        return ($cls['subject'] ?? 'Subject') . ' (' . ($cls['class_name'] ?? '') . ')';
    }

    // What is still needed to pass: exact required average over remaining quarters.
    function ai_requirement($cls) {
        $pub = [];
        foreach (($cls['published'] ?? []) as $p) {
            if (!isset($p['q'])) continue;
            $qn = (int)$p['q'];
            $pub[$qn] = $p;
        }
        $qs = array_keys($pub);
        foreach (($cls['assessments'] ?? []) as $a) {
            $qn = isset($a['quarter']) ? intval($a['quarter']) : 0;
            if ($qn > 0 && !in_array($qn, $qs, true)) $qs[] = $qn;
        }
        if (empty($qs)) return ['state' => 'no-quarters'];
        $N = max($qs);
        $remaining = [];
        foreach ($qs as $qn) { if (!isset($pub[$qn])) $remaining[] = $qn; }
        sort($remaining);
        $deped = ai_class_scale($cls) === 'DEPED';
        $key = $deped ? 'transmuted' : 'initial';
        $sum = 0; $done = 0;
        foreach ($pub as $p) {
            if (isset($p[$key]) && is_numeric($p[$key])) { $sum += floatval($p[$key]); $done++; }
        }
        if ($done === 0) return ['state' => 'nothing-published', 'next' => $remaining, 'N' => $N, 'deped' => $deped];
        if (empty($remaining)) return ['state' => 'complete'];
        $need = (75 * $N - $sum) / count($remaining);
        return ['state' => 'need', 'need' => $need, 'remaining' => $remaining, 'N' => $N, 'done' => $done, 'deped' => $deped];
    }

    // Most urgent class when the student names no subject: failed > conditional > most pending > first.
    function ai_default_subject($classes) {
        $condIdx = null; $mostPending = 0; $mostPendingN = -1;
        foreach ($classes as $idx => $cls) {
            foreach (($cls['published'] ?? []) as $p) {
                $r = strtoupper(trim($p['remarks'] ?? ''));
                if ($r === 'FAILED' || $r === 'DROPPED') return $idx;
                if ($r === 'CONDITIONAL' && $condIdx === null) $condIdx = $idx;
            }
            $nPend = 0;
            foreach (($cls['assessments'] ?? []) as $a) {
                $mk = $a['mark'] ?? null;
                if (($mk === null || $mk === '' || (is_numeric($mk) && floatval($mk) <= 0)) && floatval($a['hps'] ?? 0) > 0) $nPend++;
            }
            if ($nPend > $mostPendingN) { $mostPendingN = $nPend; $mostPending = $idx; }
        }
        if ($condIdx !== null && $mostPendingN <= 0) return $condIdx;
        return $mostPending;
    }

    // A subject-like word the student named that matches nothing enrolled.
    function ai_unknown_entity($tokens, $classes) {
        $generic = ['grade','grades','mark','marks','score','scores','quarter','quarters','midterm','final','prelim','subject','subjects','class','published','publish','pending','conditional','passed','failed','pass','fail','needed','need','average','transmuted','initial','remarks','remark','breakdown','component','components','instructor','teacher','professor','overall','weakest','strongest','worst','best','requirement','requirements','required','catch','improve','chance','standing','performance','meaning','explain','reason','remediation','makeup','recovery','attendance','quizzes','quiz','exams','exam','laboratory','activities','written','task','tasks','average'];
        foreach ($tokens as $t) {
            if (strlen($t) < 5 || is_numeric($t) || in_array($t, $generic, true)) continue;
            $found = false;
            foreach ($classes as $cls) {
                if (strpos(ai_norm(($cls['subject'] ?? '') . ' ' . ($cls['class_name'] ?? '')), $t) !== false) { $found = true; break; }
            }
            if (!$found) return $t;
        }
        return null;
    }

    // Component named without a subject: resolve across classes when unique.
    function ai_match_component_class($tokens, $classes) {
        $hits = [];
        foreach ($classes as $idx => $cls) {
            foreach (($cls['assessments'] ?? []) as $a) {
                $nt = ai_tokens($a['component'] ?? '');
                foreach ($tokens as $t) {
                    if (strlen($t) > 3 && in_array($t, $nt, true)) { $hits[$idx] = true; break; }
                }
            }
        }
        $keys = array_keys($hits);
        return count($keys) === 1 ? $keys[0] : null;
    }

    function ai_suggest_subjects($classes, $prefix = 'My grade in ') {
        $out = [];
        foreach (array_slice($classes, 0, 3) as $cls) {
            $out[] = $prefix . ($cls['subject'] ?? 'this subject');
        }
        return $out;
    }

    function ai_reply($message, $context) {
        $classes = isset($context['classes']) && is_array($context['classes']) ? array_values($context['classes']) : [];
        $student = isset($context['student_name']) ? trim((string)$context['student_name']) : '';
        $first = $student !== '' ? trim(explode(' ', $student)[0]) : '';
        $norm = ai_norm($message);
        $tokens = ai_tokens($message);
        if ($norm === '') {
            return ['html' => 'Go ahead — ask me about your grades, what you need next quarter, or which assessments still need work.', 'suggestions' => ['How am I doing overall?', 'What can you answer?']];
        }
        if (empty($classes)) {
            return ['html' => 'I can\'t see any enrolled classes on this page. If you just logged in, refresh the dashboard and try again.', 'suggestions' => ['What can you answer?']];
        }

        // --- greeting ---
        if (preg_match('/^(hi|hello|hey|good\s*(morning|afternoon|evening)|kumusta|yo)\b/', $norm) && strlen($norm) < 30) {
            $who = $first !== '' ? ', ' . ai_esc($first) : '';
            return ['html' => 'Hello' . $who . '! I can read your published grades and assessment marks. Ask me things like <strong>“How am I doing overall?”</strong>, <strong>“Why did I fail Computer Programming 1?”</strong>, or <strong>“What do I need next quarter?”</strong>',
                'suggestions' => ['How am I doing overall?', 'Which is my weakest subject?', 'What is still pending?']];
        }
        // --- help ---
        if (ai_has_any($norm, ['what can you', 'help', 'options', 'commands', 'how to use', 'menu', 'what do you do'])) {
            return ['html' => 'Here is what I can answer from your records:<br>• Overall standing and per-subject grades<br>• Why a grade is passing, failing, or conditional<br>• Exact scores needed next quarter to pass<br>• Weakest/strongest subjects and components<br>• Pending (ungraded) assessments<br>• Instructor per subject and what remarks mean',
                'suggestions' => ['How am I doing overall?', 'What do I need next quarter?']];
        }
        // --- thanks / bye (short messages only) ---
        if (strlen($norm) < 25 && ai_has_any($norm, ['thank', 'salamat', 'bye', 'goodbye', 'good night'])) {
            return ['html' => 'You\'re welcome' . ($first !== '' ? ', ' . ai_esc($first) : '') . '! Good luck on your next assessments.', 'suggestions' => ['How am I doing overall?']];
        }

        $match = ai_match_subject($tokens, $classes);
        $wantsGrade = ai_has_any($norm, ['grade', 'mark', 'score', 'result', 'passed', 'failed', 'pass', 'fail', 'rating', 'standing']);
        $wantsWhy = ai_has_any($norm, ['why', 'reason', 'explain', 'how come', 'what happened', 'bakit']);
        $wantsNeed = ai_has_any($norm, ['need', 'required', 'requirement', 'next quarter', 'to pass', 'catch up', 'improve', 'chance', 'still pass', 'how many', 'points', 'habol']);
        $wantsWeak = ai_has_any($norm, ['weakest', 'weak', 'worst', 'lowest', 'poorest']);
        $wantsBest = ai_has_any($norm, ['strongest', 'strong', 'best', 'highest', 'top subject']);
        $wantsPending = ai_has_any($norm, ['pending', 'missing', 'incomplete', 'left', 'remaining', 'todo', 'to do', 'not taken', 'not graded', 'lacking', 'kulang']);
        $wantsPublished = ai_has_any($norm, ['publish', 'released', 'release', 'available', 'posted', 'when will']);
        $wantsInstructor = ai_has_any($norm, ['instructor', 'teacher', 'professor', 'prof ', 'faculty', 'who teach', 'adviser', 'mentor']);
        $wantsMeaning = (ai_has_any($norm, ['meaning', 'mean by', 'what is', 'what s ', 'define', 'translate', 'ibig sabihin', 'stand for', 'stands for']) || preg_match('/\bmeans?\b/', $norm)) && !$wantsPending;
        $wantsComponent = ai_has_any($norm, ['component', 'breakdown', 'quizzes', 'quiz', 'exam', 'attendance', 'performance', 'laboratory', 'activities', 'written', 'task']);
        $wantsOverall = ai_has_any($norm, ['overall', 'how am i', 'how m i', 'summary', 'all subjects', 'general', 'average', 'gpa', 'performance']) || ($wantsGrade && $match['status'] === 'none' && ai_has_any($norm, ['all', 'every', 'each']));

        // --- instructor ---
        if ($wantsInstructor) {
            if ($match['status'] === 'found') {
                $cls = $classes[$match['index']];
                return ['html' => 'Your instructor for <strong>' . ai_esc(ai_subject_line($cls)) . '</strong> is <strong>' . ai_esc($cls['faculty'] ?? '—') . '</strong>.',
                    'suggestions' => ['My grade in ' . ($cls['subject'] ?? 'this subject'), 'What do I need next quarter?']];
            }
            if ($match['status'] === 'ambiguous') {
                $opts = [];
                foreach ($match['options'] as $i) $opts[] = 'Instructor for ' . $classes[$i]['subject'];
                return ['html' => 'Which subject do you mean?', 'suggestions' => $opts];
            }
            $rows = [];
            foreach ($classes as $cls) $rows[] = ai_esc($cls['subject'] ?? '?') . ' — <strong>' . ai_esc($cls['faculty'] ?? '—') . '</strong>';
            return ['html' => 'Your instructors:<br>• ' . implode('<br>• ', $rows), 'suggestions' => ['How am I doing overall?']];
        }

        // --- meaning of remarks/terms ---
        if ($wantsMeaning) {
            if (ai_has_any($norm, ['conditional'])) return ['html' => '<strong>CONDITIONAL</strong> (tertiary/CHED) means the average landed between 3.1 and 3.5 — neither a clean pass nor a fail. Ask your instructor about removal or completion work.', 'suggestions' => ['Why is my grade conditional?']];
            if (ai_has_any($norm, ['inc', 'incomplete'])) return ['html' => '<strong>INC</strong> means Incomplete — usually a missing major requirement (like a zero exam). Complete it and the grade can still change.', 'suggestions' => ['What is still pending?']];
            if (ai_has_any($norm, ['drop'])) return ['html' => '<strong>DROPPED</strong> means the record was dropped (unofficially withdrawn or with an unrecoverable average). Confirm your enrollment status with the registrar.', 'suggestions' => ['How am I doing overall?']];
            if (ai_has_any($norm, ['pass'])) return ['html' => '<strong>PASSED</strong> means the average met the passing mark — 3.0 or better (CHED) or a transmuted 75+ (DepEd).', 'suggestions' => ['How am I doing overall?']];
            if (ai_has_any($norm, ['fail'])) return ['html' => '<strong>FAILED</strong> means the average fell below passing — below 3.6 equivalent (CHED) or below transmuted 75 (DepEd).', 'suggestions' => ['Why did I fail?']];
            if (ai_has_any($norm, ['transmut', 'equivalent'])) return ['html' => 'The <strong>transmuted grade</strong> converts the raw initial grade (0–100 from your assessment scores) through the official transmutation table — that converted number is what PASSED/FAILED is judged on.', 'suggestions' => ['How am I doing overall?']];
            if (ai_has_any($norm, ['initial'])) return ['html' => 'The <strong>initial grade</strong> (0–100) is your weighted assessment average before transmutation — it shows raw performance; the transmuted grade decides passing.', 'suggestions' => ['How am I doing overall?']];
            return ['html' => 'Quick glossary:<br>• <strong>PASSED</strong> — met the passing mark (3.0 or better / transmuted 75+).<br>• <strong>FAILED</strong> — below passing.<br>• <strong>CONDITIONAL</strong> — average between 3.1 and 3.5 (CHED); needs completion/removal work.<br>• <strong>INC</strong> — incomplete, usually a missing major requirement.<br>• <strong>Transmuted grade</strong> — your initial grade converted through the official table; this is what remarks are judged on.', 'suggestions' => ['How am I doing overall?', 'What can you answer?']];
        }

        // --- overall standing ---
        if ($wantsOverall && !$wantsWhy && !$wantsNeed) {
            return ai_overall_reply($classes);
        }

        // --- weakest / strongest subjects ---
        if ($wantsWeak || $wantsBest) {
            return ai_extremes_reply($classes, $wantsBest);
        }

        // --- pending across all ---
        if ($wantsPending && $match['status'] === 'none') {
            return ai_pending_reply($classes, null);
        }

        // Subject-scoped questions need a subject.
        if ($match['status'] === 'ambiguous') {
            $opts = [];
            foreach ($match['options'] as $i) $opts[] = 'My grade in ' . $classes[$i]['subject'];
            return ['html' => 'I found more than one matching subject — which one?', 'suggestions' => $opts];
        }
        // Component named without a subject: resolve across classes when unique.
        if ($match['status'] === 'none' && $wantsComponent && !$wantsGrade && !$wantsWhy && !$wantsNeed) {
            $ci = ai_match_component_class($tokens, $classes);
            if ($ci !== null) {
                $cls = $classes[$ci];
                $r = ai_component_reply($cls, $tokens);
                $r['html'] = '<em>Looking at <strong>' . ai_esc($cls['subject'] ?? '?') . '</strong> —</em><br>' . $r['html'];
                return $r;
            }
        }
        if ($match['status'] === 'none' && ($wantsGrade || $wantsWhy || $wantsNeed || $wantsComponent || $wantsPublished)) {
            // Maybe they named a quarter only, or no entity at all.
            if (count($classes) === 1) {
                $match = ['status' => 'found', 'index' => 0];
            } else {
                $unknown = ai_unknown_entity($tokens, $classes);
                if ($unknown !== null) {
                    return ['html' => 'I don\'t see a subject matching <strong>' . ai_esc($unknown) . '</strong> — your subjects are: <strong>' . ai_esc(implode(', ', array_map(function($c) { return $c['subject'] ?? '?'; }, $classes))) . '</strong>.',
                        'suggestions' => ai_suggest_subjects($classes)];
                }
                $di = ai_default_subject($classes);
                $match = ['status' => 'found', 'index' => $di, 'assumed' => true];
            }
        }
        if ($match['status'] === 'found') {
            $cls = $classes[$match['index']];
            $assumeNote = isset($match['assumed']) ? '<em>Assuming <strong>' . ai_esc($cls['subject'] ?? '?') . '</strong> (your most urgent) —</em><br>' : '';
            if ($wantsWhy) { $r = ai_why_reply($cls); $r['html'] = $assumeNote . $r['html']; return $r; }
            if ($wantsNeed) { $r = ai_need_reply($cls); $r['html'] = $assumeNote . $r['html']; return $r; }
            if ($wantsPending) { $r = ai_pending_reply($classes, $cls); $r['html'] = $assumeNote . $r['html']; return $r; }
            if ($wantsPublished) { $r = ai_published_reply($cls); $r['html'] = $assumeNote . $r['html']; return $r; }
            if ($wantsComponent) { $r = ai_component_reply($cls, $tokens); $r['html'] = $assumeNote . $r['html']; return $r; }
            $r = ai_grade_reply($cls, ai_match_quarter($norm, $cls));
            $r['html'] = $assumeNote . $r['html'];
            return $r;
        }

        // --- fallback ---
        $sugg = ai_suggest_subjects($classes);
        $sugg[] = 'What can you answer?';
        return ['html' => 'I didn\'t quite get that. Try asking about a subject’s grade, why it passed or failed, what you need next quarter, or what’s still pending — for example: <strong>“Why did I fail ' . ai_esc($classes[0]['subject'] ?? 'my subject') . '?”</strong>',
            'suggestions' => $sugg];
    }

    function ai_overall_reply($classes) {
        $pass = 0; $fail = 0; $cond = 0; $ungraded = 0; $rows = [];
        foreach ($classes as $cls) {
            $best = null;
            foreach (($cls['published'] ?? []) as $p) {
                $r = strtoupper(trim($p['remarks'] ?? ''));
                if ($r === '') continue;
                $best = $r;
            }
            if ($best === null) { $ungraded++; continue; }
            if (strpos($best, 'PASS') === 0) { $pass++; $cls2 = $best; }
            elseif ($best === 'CONDITIONAL') { $cond++; $cls2 = 'CONDITIONAL'; }
            else { $fail++; $cls2 = $best; }
            $rows[] = ai_esc($cls['subject'] ?? '?') . ': <strong>' . $cls2 . '</strong>';
        }
        $total = count($classes);
        $tone = $fail === 0 ? 'passing' : 'with trouble spots';
        $html = 'Across <strong>' . $total . '</strong> subject' . ($total != 1 ? 's' : '') . ' you are <strong>' . $tone . '</strong>: '
            . $pass . ' passed, ' . $cond . ' conditional, ' . $fail . ' failing'
            . ($ungraded ? ', ' . $ungraded . ' not yet graded' : '') . '.';
        if (!empty($rows)) $html .= '<br>• ' . implode('<br>• ', $rows);
        $sugg = ['What do I need next quarter?'];
        foreach ($classes as $cls) {
            foreach (($cls['published'] ?? []) as $p) {
                if (in_array(strtoupper(trim($p['remarks'] ?? '')), ['FAILED', 'CONDITIONAL', 'DROPPED'], true)) {
                    $sugg[] = 'Why did I fail ' . ($cls['subject'] ?? 'this subject');
                    break 2;
                }
            }
        }
        $sugg[] = 'Which is my weakest subject?';
        return ['html' => $html, 'suggestions' => array_slice(array_unique($sugg), 0, 3)];
    }

    function ai_extremes_reply($classes, $best) {
        $scored = [];
        foreach ($classes as $idx => $cls) {
            $vals = [];
            foreach (($cls['published'] ?? []) as $p) {
                if (isset($p['transmuted']) && is_numeric($p['transmuted'])) $vals[] = floatval($p['transmuted']);
            }
            if (!empty($vals)) {
                $deped = ai_class_scale($cls) === 'DEPED';
                $score = $deped ? array_sum($vals) / count($vals) : -1 * (array_sum($vals) / count($vals));
                $scored[] = ['idx' => $idx, 'score' => $score, 'avg' => array_sum($vals) / count($vals), 'deped' => $deped];
            }
        }
        if (empty($scored)) {
            return ['html' => 'Nothing published yet, so I can\'t rank subjects. Ask me again after grades come out — or ask what\'s pending.', 'suggestions' => ['What is still pending?', 'How am I doing overall?']];
        }
        usort($scored, function($a, $b) { return $b['score'] <=> $a['score']; });
        $pick = $best ? $scored[0] : $scored[count($scored) - 1];
        $cls = $classes[$pick['idx']];
        $label = $best ? 'strongest' : 'weakest';
        $avgTxt = $pick['deped'] ? ('transmuted average ' . ai_num($pick['avg'])) : ('equivalent average ' . ai_num($pick['avg']));
        return ['html' => 'Your ' . $label . ' subject is <strong>' . ai_esc(ai_subject_line($cls)) . '</strong> (' . $avgTxt . ').',
            'suggestions' => ['Why did I ' . ($best ? 'pass ' : 'fail ') . ($cls['subject'] ?? 'it'), 'What do I need next quarter?']];
    }

    function ai_grade_reply($cls, $quarter) {
        $pub = [];
        foreach (($cls['published'] ?? []) as $p) {
            if (isset($p['q'])) $pub[(int)$p['q']] = $p;
        }
        if ($quarter !== null && isset($pub[$quarter])) {
            $p = $pub[$quarter];
            $txt = ($p['name'] ?? ('Quarter ' . $quarter)) . ': initial ' . (ai_num($p['initial'] ?? null) ?? '—')
                . ', transmuted ' . (ai_num($p['transmuted'] ?? null) ?? '—')
                . ' → <strong>' . ai_esc($p['remarks'] !== '' ? $p['remarks'] : 'ungraded') . '</strong>';
            return ['html' => 'In <strong>' . ai_esc(ai_subject_line($cls)) . '</strong> — ' . $txt . '.',
                'suggestions' => ['Why is it ' . strtolower($p['remarks'] !== '' ? $p['remarks'] : 'ungraded') . '?', 'What do I need next quarter?']];
        }
        if (!empty($pub)) {
            ksort($pub);
            $bits = [];
            foreach ($pub as $qn => $p) {
                $bits[] = ai_esc($p['name'] ?? ('Q' . $qn)) . ': <strong>' . ai_esc(($p['transmuted'] !== null && $p['transmuted'] !== '' ? ai_num($p['transmuted']) : '—') . ' ' . ($p['remarks'] !== '' ? $p['remarks'] : '')) . '</strong>';
            }
            return ['html' => '<strong>' . ai_esc(ai_subject_line($cls)) . '</strong> by quarter:<br>• ' . implode('<br>• ', $bits),
                'suggestions' => ['Why did I ' . (strpos(implode(' ', $bits), 'FAILED') !== false ? 'fail ' : 'pass ') . ($cls['subject'] ?? 'it'), 'What do I need next quarter?']];
        }
        // Unpublished: standing from assessments.
        $comp = ai_component_stats($cls);
        $avgs = [];
        foreach ($comp as $cn => $c) { if ($c['n'] > 0 && $c['hps'] > 0) $avgs[$cn] = 100 * $c['score'] / $c['hps']; }
        if (empty($avgs)) {
            return ['html' => 'No published grade and no graded work yet in <strong>' . ai_esc(ai_subject_line($cls)) . '</strong>.', 'suggestions' => ['What is still pending?']];
        }
        $overall = array_sum($avgs) / count($avgs);
        return ['html' => 'Not published yet in <strong>' . ai_esc(ai_subject_line($cls)) . '</strong>, but across graded work you average about <strong>' . number_format($overall, 1) . '%</strong>.',
            'suggestions' => ['What do I need next quarter?', 'What is still pending?']];
    }

    function ai_why_reply($cls) {
        $pub = [];
        foreach (($cls['published'] ?? []) as $p) {
            if (isset($p['q'])) $pub[(int)$p['q']] = $p;
        }
        ksort($pub);
        $comp = ai_component_stats($cls);
        $weak = ai_weakest($comp);
        $parts = [];
        if (!empty($pub)) {
            end($pub); $last = current($pub); $lname = key($pub);
            $parts[] = 'Latest published quarter (' . ai_esc($last['name'] ?? ('Quarter ' . $lname)) . '): initial ' . (ai_num($last['initial'] ?? null) ?? '—') . ' → transmuted ' . (ai_num($last['transmuted'] ?? null) ?? '—') . ' = <strong>' . ai_esc($last['remarks'] !== '' ? $last['remarks'] : 'ungraded') . '</strong>.';
        } else {
            $parts[] = 'Nothing published yet, so this reads your raw assessment marks.';
        }
        if ($weak) {
            [$cn, $avg] = $weak;
            $w = 'The biggest drag is <strong>' . ai_esc($cn) . '</strong> at <strong>' . number_format($avg, 1) . '%</strong>';
            $all = [];
            foreach ($comp as $n => $c) { if ($c['weight'] !== null) $all[$n] = $c['weight']; }
            if (isset($all[$cn])) $w .= ' (worth ' . number_format($all[$cn], 0) . '% of the grade)';
            // Recoverable points estimate.
            $lift = ($all[$cn] ?? 0) * (100 - $avg) / 100;
            if ($lift > 0.05) $w .= ' — lifting it to 100% recovers about <strong>' . number_format($lift, 1) . ' initial-grade points</strong>';
            $w .= '.';
            $parts[] = $w;
        }
        $fails = [];
        foreach ($comp as $cn => $c) { foreach ($c['failed'] as $f) $fails[] = $f; }
        if (!empty($fails)) {
            usort($fails, function($a, $b) { return ($a['mark'] / max(1, $a['hps'])) <=> ($b['mark'] / max(1, $b['hps'])); });
            $bits = [];
            foreach (array_slice($fails, 0, 3) as $f) {
                $bits[] = ai_esc($f['name']) . ' (' . number_format($f['mark'], 0) . '/' . number_format($f['hps'], 0) . ')';
            }
            $parts[] = 'Lowest scores: ' . implode(', ', $bits) . '.';
        }
        $pend = 0; foreach ($comp as $c) $pend += $c['pending'];
        if ($pend > 0) $parts[] = '<strong>' . $pend . '</strong> assessment' . ($pend != 1 ? 's' : '') . ' still ungraded — completing them lifts every average they feed.';
        return ['html' => 'Why <strong>' . ai_esc(ai_subject_line($cls)) . '</strong> stands where it does:<br>• ' . implode('<br>• ', $parts),
            'suggestions' => ['What do I need next quarter?', 'What is still pending?']];
    }

    function ai_need_reply($cls) {
        $req = ai_requirement($cls);
        $subj = ai_esc(ai_subject_line($cls));
        if ($req['state'] === 'no-quarters') {
            return ['html' => 'I can\'t see any quarters for <strong>' . $subj . '</strong> yet.', 'suggestions' => ['What is still pending?']];
        }
        if ($req['state'] === 'nothing-published') {
            return ['html' => 'Nothing published yet in <strong>' . $subj . '</strong> — aim for at least <strong>75%</strong> on every assessment; that keeps every door open.', 'suggestions' => ['What is still pending?', 'Which is my weakest subject?']];
        }
        if ($req['state'] === 'complete') {
            return ['html' => 'All quarters in <strong>' . $subj . '</strong> are graded — check the Final Average above for where you landed.', 'suggestions' => ['How am I doing overall?']];
        }
        $names = [];
        foreach (($cls['published'] ?? []) as $p) { if (isset($p['q'])) $names[(int)$p['q']] = $p['name'] ?? ('Quarter ' . $p['q']); }
        foreach (($cls['assessments'] ?? []) as $a) {
            $qn = isset($a['quarter']) ? intval($a['quarter']) : 0;
            if ($qn > 0 && !isset($names[$qn])) $names[$qn] = $a['quarter_name'] ?? ('Quarter ' . $qn);
        }
        $nm = function($qn) use ($names) { return $names[$qn] ?? ('Quarter ' . $qn); };
        $nextNames = array_map(function($qn) use ($nm) { return '<strong>' . ai_esc($nm($qn)) . '</strong>'; }, $req['remaining']);
        if ($req['need'] <= 0) {
            $txt = 'Passing is effectively secured — just complete ' . implode(', ', $nextNames) . ' and avoid zeros.';
        } elseif ($req['need'] > 100) {
            $txt = 'Honestly, passing on grades alone is out of reach (you would need ' . number_format($req['need'], 1) . ' average). Talk to your instructor now about remediation or makeup work — that conversation matters more than the next quiz.';
        } else {
            $unit = $req['deped'] ? 'transmuted' : 'initial';
            $txt = 'Average at least <strong>' . number_format($req['need'], 1) . '</strong> ' . $unit . ' across ' . implode(', ', $nextNames) . ' to finish passing.';
        }
        $comp = ai_component_stats($cls);
        $weak = ai_weakest($comp);
        if ($weak) $txt .= ' Put extra hours into <strong>' . ai_esc($weak[0]) . '</strong> (currently ' . number_format($weak[1], 1) . '%).';
        return ['html' => 'To pass <strong>' . $subj . '</strong>: ' . $txt, 'suggestions' => ['Why is it failing?', 'What is still pending?']];
    }

    function ai_pending_reply($classes, $onlyCls) {
        $targets = $onlyCls ? [$onlyCls] : $classes;
        $blocks = [];
        $total = 0;
        foreach ($targets as $cls) {
            $pend = [];
            foreach (($cls['assessments'] ?? []) as $a) {
                $mk = $a['mark'] ?? null;
                if (($mk === null || $mk === '' || (is_numeric($mk) && floatval($mk) <= 0)) && floatval($a['hps'] ?? 0) > 0) {
                    $qn = $a['quarter_name'] ?? (isset($a['quarter']) ? 'Quarter ' . $a['quarter'] : '');
                    $pend[] = ai_esc($a['name'] ?? 'Assessment') . ' (' . ai_esc($qn) . ')';
                }
            }
            if (!empty($pend)) {
                $total += count($pend);
                $show = array_slice($pend, 0, 5);
                $more = count($pend) > 5 ? ' and ' . (count($pend) - 5) . ' more' : '';
                $blocks[] = '<strong>' . ai_esc($cls['subject'] ?? '?') . '</strong> (' . count($pend) . '): ' . implode(', ', $show) . $more;
            }
        }
        if ($total === 0) {
            return ['html' => 'Nothing pending — every assessment I can see already has a score. Nice.', 'suggestions' => ['How am I doing overall?', 'What do I need next quarter?']];
        }
        return ['html' => 'Still ungraded (<strong>' . $total . '</strong>):<br>• ' . implode('<br>• ', $blocks) . '<br>Finish these first — zeros and blanks drag every average down.',
            'suggestions' => ['What do I need next quarter?', 'Which is my weakest subject?']];
    }

    function ai_published_reply($cls) {
        $rows = [];
        $lastRem = '';
        foreach (($cls['published'] ?? []) as $p) {
            if (!isset($p['q'])) continue;
            $rows[] = ai_esc($p['name'] ?? ('Quarter ' . $p['q'])) . ': <strong>' . ai_esc(trim(($p['transmuted'] ?? '') . ' ' . ($p['remarks'] ?? ''))) . '</strong>';
            if (trim($p['remarks'] ?? '') !== '') $lastRem = strtolower(trim($p['remarks']));
        }
        if (empty($rows)) {
            return ['html' => 'Nothing published yet for <strong>' . ai_esc(ai_subject_line($cls)) . '</strong> — grades appear here after your instructor publishes them.', 'suggestions' => ['What is still pending?']];
        }
        $why = in_array($lastRem, ['failed', 'conditional', 'passed'], true) ? $lastRem : 'graded';
        return ['html' => 'Published for <strong>' . ai_esc(ai_subject_line($cls)) . '</strong>:<br>• ' . implode('<br>• ', $rows),
            'suggestions' => ['Why is it ' . $why . '?', 'What do I need next quarter?']];
    }

    function ai_component_reply($cls, $tokens) {
        $comp = ai_component_stats($cls);
        if (empty($comp)) {
            return ['html' => 'No component data for <strong>' . ai_esc(ai_subject_line($cls)) . '</strong>.', 'suggestions' => ['What is still pending?']];
        }
        // Try to focus one named component.
        $names = array_keys($comp);
        $focus = null;
        foreach ($names as $cn) {
            $nt = ai_tokens($cn);
            foreach ($tokens as $t) {
                if (strlen($t) > 3 && in_array($t, $nt, true)) { $focus = $cn; break 2; }
            }
        }
        $lines = [];
        $list = $focus ? [$focus] : $names;
        foreach ($list as $cn) {
            $c = $comp[$cn];
            $avg = ($c['n'] > 0 && $c['hps'] > 0) ? number_format(100 * $c['score'] / $c['hps'], 1) . '%' : 'no scores yet';
            $wt = $c['weight'] !== null ? ' (weight ' . number_format($c['weight'], 0) . '%)' : '';
            $lines[] = '<strong>' . ai_esc($cn) . '</strong>' . $wt . ': ' . $avg . ' across ' . $c['n'] . ' graded, ' . $c['pending'] . ' pending';
        }
        return ['html' => 'Component breakdown for <strong>' . ai_esc(ai_subject_line($cls)) . '</strong>:<br>• ' . implode('<br>• ', $lines),
            'suggestions' => ['What do I need next quarter?', 'What is still pending?']];
    }
}
