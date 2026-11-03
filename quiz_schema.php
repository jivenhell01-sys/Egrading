<?php
// Quiz Generator — shared schema + helpers.
// Works with both SQLite3 (faculty/admin $conn) and PDO (student portal).
// Tables:
//   quiz_list      — one row per generated quiz (class-scoped, activatable)
//   quiz_questions — generated items (MC / identification / true-false)
//   quiz_attempts  — one row per student per quiz (score + anti-cheat log)
if (!function_exists('quiz_ensure_schema')) {
    function quiz_ensure_schema($db) {
        $exec = function ($sql) use ($db) {
            try { return @$db->exec($sql); } catch (Exception $e) { return false; }
        };
        $exec("CREATE TABLE IF NOT EXISTS `quiz_list` (
            `quiz_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `class_id` INTEGER NOT NULL,
            `semester_id` TEXT NOT NULL DEFAULT '',
            `title` TEXT NOT NULL,
            `source_text` TEXT NOT NULL DEFAULT '',
            `quiz_type` TEXT NOT NULL DEFAULT 'multiple_choice',
            `num_items` INTEGER NOT NULL DEFAULT 10,
            `num_choices` INTEGER NOT NULL DEFAULT 4,
            `time_limit_minutes` INTEGER NOT NULL DEFAULT 0,
            `is_active` INTEGER NOT NULL DEFAULT 0,
            `assigned_student_ids` TEXT NOT NULL DEFAULT '',
            `created_by` INTEGER,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `activated_at` TIMESTAMP,
            FOREIGN KEY(`class_id`) REFERENCES `class_list`(`class_id`) ON DELETE CASCADE
        )");
        $exec("CREATE TABLE IF NOT EXISTS `quiz_questions` (
            `question_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `quiz_id` INTEGER NOT NULL,
            `qtype` TEXT NOT NULL DEFAULT 'multiple_choice',
            `question` TEXT NOT NULL,
            `choices` TEXT,
            `correct_answer` TEXT NOT NULL DEFAULT '',
            `points` REAL NOT NULL DEFAULT 1,
            `time_limit_seconds` INTEGER NOT NULL DEFAULT 0,
            `sort_order` INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(`quiz_id`) REFERENCES `quiz_list`(`quiz_id`) ON DELETE CASCADE
        )");
        $exec("CREATE TABLE IF NOT EXISTS `quiz_attempts` (
            `attempt_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `quiz_id` INTEGER NOT NULL,
            `student_id` INTEGER NOT NULL,
            `student_number` TEXT NOT NULL,
            `class_id` INTEGER NOT NULL,
            `answers` TEXT,
            `score` REAL NOT NULL DEFAULT 0,
            `total` INTEGER NOT NULL DEFAULT 0,
            `violations` INTEGER NOT NULL DEFAULT 0,
            `violation_log` TEXT,
            `status` TEXT NOT NULL DEFAULT 'in_progress',
            `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `submitted_at` TIMESTAMP,
            FOREIGN KEY(`quiz_id`) REFERENCES `quiz_list`(`quiz_id`) ON DELETE CASCADE,
            FOREIGN KEY(`student_id`) REFERENCES `student_list`(`student_id`) ON DELETE CASCADE,
            UNIQUE(`quiz_id`, `student_id`)
        )");
        $exec("CREATE INDEX IF NOT EXISTS idx_quiz_list_class ON `quiz_list`(`class_id`, `is_active`)");
        $exec("CREATE INDEX IF NOT EXISTS idx_quiz_q_quiz ON `quiz_questions`(`quiz_id`, `sort_order`)");
        $exec("CREATE INDEX IF NOT EXISTS idx_quiz_att_quiz ON `quiz_attempts`(`quiz_id`, `student_number`)");
        // Lightweight migrations for older installs
        $cols = [];
        try {
            if ($db instanceof PDO) {
                $r = $db->query("PRAGMA table_info('quiz_list')");
                if ($r) foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) { if (isset($row['name'])) $cols[] = $row['name']; }
            } else {
                $res = $db->query("PRAGMA table_info('quiz_list')");
                if ($res) while ($row = $res->fetchArray(SQLITE3_ASSOC)) { if (isset($row['name'])) $cols[] = $row['name']; }
            }
        } catch (Exception $e) {}
        if (!empty($cols)) {
            if (!in_array('time_limit_minutes', $cols)) $exec("ALTER TABLE `quiz_list` ADD COLUMN `time_limit_minutes` INTEGER NOT NULL DEFAULT 0");
            if (!in_array('activated_at', $cols)) $exec("ALTER TABLE `quiz_list` ADD COLUMN `activated_at` TIMESTAMP");
            // Per-student assignment: JSON array of student_ids. Empty = whole class.
            if (!in_array('assigned_student_ids', $cols)) $exec("ALTER TABLE `quiz_list` ADD COLUMN `assigned_student_ids` TEXT NOT NULL DEFAULT ''");
        }
        // Per-question time limit (seconds). 0 = no limit.
        $qcols = [];
        try {
            if ($db instanceof PDO) {
                $r = $db->query("PRAGMA table_info('quiz_questions')");
                if ($r) foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) { if (isset($row['name'])) $qcols[] = $row['name']; }
            } else {
                $res = $db->query("PRAGMA table_info('quiz_questions')");
                if ($res) while ($row = $res->fetchArray(SQLITE3_ASSOC)) { if (isset($row['name'])) $qcols[] = $row['name']; }
            }
        } catch (Exception $e) {}
        if (!empty($qcols) && !in_array('time_limit_seconds', $qcols)) {
            $exec("ALTER TABLE `quiz_questions` ADD COLUMN `time_limit_seconds` INTEGER NOT NULL DEFAULT 0");
        }
    }
}

if (!function_exists('quiz_allowed_types')) {
    function quiz_allowed_types() {
        return ['multiple_choice' => 'Multiple Choice', 'identification' => 'Identification', 'true_false' => 'True or False', 'mixed' => 'Mixed (all types)'];
    }
}

if (!function_exists('quiz_type_label')) {
    function quiz_type_label($t) {
        $m = quiz_allowed_types();
        return $m[$t] ?? ucwords(str_replace('_', ' ', (string)$t));
    }
}

if (!function_exists('quiz_stopwords')) {
    function quiz_stopwords() {
        return ['the','a','an','and','or','but','of','to','in','on','at','for','with','by','from','as','is','are','was','were','be','been','being','has','have','had','having','do','does','did','doing','will','would','should','could','may','might','must','shall','can','this','that','these','those','it','its','they','them','their','there','here','which','who','whom','what','when','where','why','how','not','no','yes','if','then','than','so','such','into','over','under','between','through','during','each','other','some','any','all','both','few','more','most','many','much','own','same','only','very','just','also','about','up','out','off','after','before','because','while','until','against','among','within','without','along','across','behind','beyond','plus','except','toward','towards','upon','towards','per','via','one','two','three','first','second','new','used','using','use','often','well','even','ever','never','always','usually','generally','particularly','especially','however','therefore','thus','hence','although','though','since','despite','toward','using'];
    }
}

if (!function_exists('quiz_split_sentences')) {
    // Split pasted discussion into usable sentences. Min length filters fragments.
    function quiz_split_sentences($text) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$text));
        if ($text === '') return [];
        $parts = preg_split('/(?<=[.!?])\s+|\n+/', $text);
        $out = [];
        foreach ((array)$parts as $p) {
            $p = trim($p);
            if (strlen($p) < 25) continue;
            if (str_word_count($p) < 5) continue;
            if (strlen($p) > 400) $p = substr($p, 0, 400);
            $out[] = $p;
        }
        return array_values($out);
    }
}

if (!function_exists('quiz_extract_keywords')) {
    // Candidate answer terms: longest meaningful words across the discussion.
    function quiz_extract_keywords($sentences, $limit = 60) {
        $stop = array_flip(quiz_stopwords());
        $freq = [];
        foreach ($sentences as $s) {
            $words = preg_split('/[^A-Za-z0-9\-]+/', $s);
            foreach ($words as $w) {
                $w = trim($w, "- \t");
                if (strlen($w) < 4) continue;
                $lw = strtolower($w);
                if (isset($stop[$lw])) continue;
                if (preg_match('/^\d+$/', $w)) continue;
                if (!isset($freq[$lw])) $freq[$lw] = ['word' => $w, 'n' => 0];
                $freq[$lw]['n']++;
                if (strlen($w) > strlen($freq[$lw]['word'])) $freq[$lw]['word'] = $w;
            }
        }
        usort($freq, function ($a, $b) {
            if ($a['n'] !== $b['n']) return $b['n'] <=> $a['n'];
            return strlen($b['word']) <=> strlen($a['word']);
        });
        $out = [];
        foreach ($freq as $f) { $out[] = $f['word']; if (count($out) >= $limit) break; }
        return $out;
    }
}

if (!function_exists('quiz_blank_sentence')) {
    // Replace first occurrence of $keyword with a blank. Returns null if absent.
    function quiz_blank_sentence($sentence, $keyword) {
        $pos = stripos($sentence, $keyword);
        if ($pos === false) return null;
        return substr($sentence, 0, $pos) . '__________' . substr($sentence, $pos + strlen($keyword));
    }
}

if (!function_exists('quiz_swap_term')) {
    // Replace whole-word occurrences of the answer term. Substring-only
    // matches (e.g. "number" inside "numbers") are left intact — swapping
    // there manufactures frankenwords like "numberss". Callers already fall
    // back when the text comes back unchanged.
    function quiz_swap_term($sentence, $keyword, $rep) {
        $rx = '/\b' . preg_quote((string)$keyword, '/') . '\b/i';
        $s = (string)$sentence;
        $probe = @preg_match($rx, $s);
        if ($probe !== 1) {
            if ($probe === false) {
                if (stripos($s, (string)$keyword) === false) return $s;
                return str_ireplace((string)$keyword, (string)$rep, $s);
            }
            return $s;
        }
        $out = @preg_replace_callback($rx, function () use ($rep) { return $rep; }, $s);
        return $out === null ? $s : $out;
    }
}

if (!function_exists('quiz_terms_overlap')) {
    // "table"/"tables", "cell"/"cellar": too similar to sit side by side.
    function quiz_terms_overlap($a, $b) {
        $la = strtolower((string)$a);
        $lb = strtolower((string)$b);
        if ($la === $lb) return true;
        return strpos($la, $lb) !== false || strpos($lb, $la) !== false;
    }
}

if (!function_exists('quiz_clean_choices')) {
    // Trim + drop empties + drop case-insensitive duplicates (keep first).
    function quiz_clean_choices($arr) {
        $out = [];
        $seen = [];
        foreach ((array)$arr as $c) {
            $v = trim(preg_replace('/\s+/', ' ', (string)($c === null ? '' : $c)));
            if ($v === '') continue;
            $lk = strtolower($v);
            if (isset($seen[$lk])) continue;
            $seen[$lk] = 1;
            $out[] = $v;
        }
        return $out;
    }
}

if (!function_exists('quiz_wrong_terms')) {
    // Distractor terms: from elsewhere in the discussion, never the answer
    // (nor a look-alike of it) and never a word already in the sentence
    // (else it may stay true).
    function quiz_wrong_terms($pool, $sentence, $keyword, $maxN) {
        $wrongs = [];
        foreach ((array)$pool as $cand) {
            if (count($wrongs) >= $maxN) break;
            if (quiz_terms_overlap($cand, $keyword)) continue;
            if (stripos($sentence, $cand) !== false) continue;
            $wrongs[] = $cand;
        }
        return $wrongs;
    }
}

if (!function_exists('quiz_wh_question')) {
    // Definitional sentences ("X is ...") become proper "What is ...?" questions.
    // Returns null when the sentence shape does not allow it.
    function quiz_wh_question($sentence, $keyword) {
        $s = trim((string)$sentence);
        $ekw = preg_quote((string)$keyword, '/');
        if (!preg_match('/^(?:(?:the|a|an)\s+)?' . $ekw . '\s+(is|are|was|were)\s+(.+?)\s*[.!?]*$/i', $s, $m)) return null;
        $rest = trim($m[2]);
        $rest = preg_replace_callback('/^([A-Z])(?=[a-z])/', function ($x) { return strtolower($x[1]); }, $rest);
        if (strlen($rest) < 3) return null;
        $rw = strtolower(trim(preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', strtok($rest, " \t"))));
        if (isset(quiz_wh_bad()[$rw])) return null;
        if (stripos($rest, (string)$keyword) !== false) return null;
        return 'What ' . strtolower($m[1]) . ' ' . $rest . '?';
    }
}

if (!function_exists('quiz_roles')) {
    function quiz_roles() {
        return ['tag','element','attribute','property','process','organelle','theory','vessel','tissue','cell','organ','system','list','table','law','principle','method','technique','device','tool','instrument','part','section','chapter','type','kind','form','stage','phase','step','component','structure','unit','reaction','cycle','layer','region','muscle','bone','gland','hormone','planet','star','country','city','river','mountain','ocean','continent','language','currency','sport','game','dance','song','festival','drug','disease','symptom','profession','occupation'];
    }
}

if (!function_exists('quiz_wh_bad')) {
    function quiz_wh_bad() {
        return array_flip(['that','which','who','whom','whose','where','when','how','why','what','whether','if','to','and','but','or','not']);
    }
}

if (!function_exists('quiz_head_bad')) {
    // Words a question subject must not start with (prepositions, pronouns, ...).
    function quiz_head_bad() {
        return array_flip(['in','on','at','for','with','by','from','through','during','after','before','because','when','while','where','which','who','whom','what','how','why','if','although','though','despite','between','among','against','under','over','into','without','within','along','across','behind','beyond','via','per','plus','except','until','toward','towards','upon','as','of','to','and','but','or','nor','so','yet','then','than','that','this','these','those','it','its','they','them','their','he','she','we','you','there','here','his','her','our','your','my','not']);
    }
}

if (!function_exists('quiz_aux_verbs')) {
    // Auxiliary / modal verbs: a main verb may legitimately follow these
    // ("What can go …?"), unlike content verbs ("plant produces …").
    function quiz_aux_verbs() {
        return array_flip(['is','are','was','were','am','been','being','has','have','had','does','did','do','will','would','can','could','shall','should','may','might','must','go','goes','say','says']);
    }
}

if (!function_exists('quiz_rem_bad')) {
    // Words the remainder (verb phrase) of a direct question must not start with.
    function quiz_rem_bad() {
        return array_flip(['which','that','who','whom','whose','where','when','and','but','or','nor','for','so','yet','because','if','as','since','although','though','unless','until','whereas','to','of','in','on','at','by','with','from','into','is','are','was','were','be','been','being','am','a','an','the','this','these','those','it','they','he','she','we','you','there','here','not','very','really','quite','rather','almost','nearly','hardly','barely','merely','quickly','slowly','carefully','easily','directly','indirectly','mainly','mostly','usually','often','always','never','sometimes','generally','typically','normally','rarely','simply','also','just','even','still','already','effectively','efficiently','primarily','largely','highly','widely','commonly','regularly','constantly','recently','currently','previously','finally','therefore','however','moreover','furthermore','instead','indeed']);
    }
}

if (!function_exists('quiz_verbs')) {
    // Recognizable verbs: the remainder of a direct question must contain one.
    function quiz_verbs() {
        return array_flip(['is','are','was','were','has','have','had','does','did','will','would','can','could','shall','should','may','might','must','creates','create','contains','contain','defines','define','provides','provide','uses','use','indicates','indicate','inserts','insert','makes','make','absorbs','absorb','speeds','speed','proposes','propose','proposed','includes','include','means','mean','shows','show','displays','display','forms','form','produces','produce','releases','release','converts','convert','moves','move','holds','hold','stores','store','carries','carry','supports','support','allows','allow','helps','help','causes','cause','occurs','occur','stands','stand','gives','give','takes','take','needs','need','requires','require','called','known','come','came','see','saw','seen','get','got','gotten','find','found','know','knew','think','thought','bring','brought','write','wrote','written','speak','spoke','spoken','choose','chose','chosen','draw','drew','drawn','grow','grew','grown','throw','threw','thrown','begin','began','begun','drink','drank','drunk','ring','rang','rung','sing','sang','sung','swim','swam','swum','eat','ate','eaten','fall','fell','fallen','forget','forgot','forgotten','freeze','froze','frozen','hide','hid','hidden','rise','rose','risen','shake','shook','shaken','steal','stole','stolen','tear','tore','torn','wear','wore','worn','drive','drove','driven','ride','rode','ridden','ran','run','went','built','sent','spent','taught','caught','fought','bought','sold','told','heard','held','kept','slept','swept','left','met','paid','laid','flow','flows','flowed','walk','walks','walked','talk','talks','talked','live','lives','lived','love','loves','loved','work','works','worked','play','plays','played','study','studies','studied','learn','learns','learned','teach','teaches','listen','listens','listened','watch','watches','watched','open','opens','opened','close','closes','closed','start','starts','started','stop','stops','stopped','end','ends','ended','join','joins','joined','separate','separates','separated','divide','divides','divided','multiply','multiplies','multiplied','add','adds','added','subtract','subtracts','subtracted','share','shares','shared','serve','serves','served','breathe','breathes','breathed','pump','pumps','pumped','filter','filters','filtered','transport','transports','transported','protect','protects','protected','float','floats','floated','sink','sinks','sank','sunk','pull','pulls','pulled','push','pushes','pushed','turn','turns','turned','spin','spins','spun','rotate','rotates','rotated','orbit','orbits','orbited','reflect','reflects','reflected','attract','attracts','attracted','repel','repels','repelled','expand','expands','expanded','contract','contracts','contracted','stretch','stretches','stretched','shrink','shrinks','shrank','shrunk','break','breaks','broke','broken','melt','melts','melted','boil','boils','boiled','evaporate','evaporates','evaporated','condense','condenses','condensed','dissolve','dissolves','dissolved','mix','mixes','mixed','combine','combines','combined','split','splits','cut','cuts','dig','digs','dug','planted','hunt','hunts','hunted','gather','gathers','gathered','migrate','migrates','migrated','shed','sheds','hatch','hatches','hatched','digest','digests','digested','reproduce','reproduces','reproduced','evolve','evolves','evolved','adapt','adapts','adapted','survive','survives','survived','compete','competes','competed','communicate','communicates','communicated','respond','responds','responded','react','reacts','reacted','detect','detects','detected','regulate','regulates','regulated','maintain','maintains','maintained','generate','generates','generated','deliver','delivers','delivered','collect','collects','collected','describe','describes','described','explain','explains','explained','discuss','discusses','discussed','mentioned','stated','noted','refer','refers','referred','relate','relates','related','compare','compares','compared','contrast','contrasts','contrasted','match','matches','matched','measure','measures','measured','weigh','weighs','weighed','cost','costs','last','lasts','lasted','consist','consists','consisted','rely','relies','relied','belong','belongs','belonged','spell','spells','spelled','label','labels','labeled','listed','call','calls','ask','asks','asked','answered','reply','replies','replied','continue','continues','continued','happen','happens','happened','exist','exists','existed','remain','remains','remained','sit','sits','sat','hang','hangs','hung','wake','wakes','woke','woken','die','dies','died','kill','kills','killed','heal','heals','healed','cure','cures','cured','prevent','prevents','prevented','avoid','avoids','avoided','escape','escapes','escaped','seek','seeks','sought','search','searches','searched','explore','explores','explored','discover','discovers','discovered','invent','invents','invented','create','creates','created','design','designs','designed','compose','composes','composed','construct','constructs','constructed','destroy','destroys','destroyed','damage','damages','damaged','repair','repairs','repaired','fix','fixes','fixed','clean','cleans','cleaned','wash','washes','washed','cook','cooks','cooked','feed','feeds','fed','lie','lies','lay','lain','arise','arises','arose','arisen','runs','comes','sees','gets','finds','knows','thinks','brings','writes','speaks','chooses','draws','grows','throws','begins','drinks','rings','sings','swims','eats','falls','forgets','freezes','hides','rises','shakes','steals','tears','wears','drives','rides','builds','sends','spends','catches','fights','buys','sells','tells','hears','keeps','sleeps','sweeps','leaves','meets','pays','lays','become','becomes','became','put','puts','set','sets','hit','hits','lead','leads','led','read','reads','said','did','cover','covers','covered','bear','bears','bore','born','face','faces','faced','cross','crosses','crossed','enter','enters','entered','reach','reaches','reached','touch','touches','touched','affect','affects','affected','effect','effects','effected','reduce','reduces','reduced','increase','increases','increased','decrease','decreases','decreased','improve','improves','improved','develop','develops','developed','vary','varies','varied','range','ranges','ranged','tend','tends','tended','seem','seems','seemed','appear','appears','appeared','stay','stays','stayed','own','owns','owned','involve','involves','involved','represent','represents','represented','demonstrate','demonstrates','demonstrated','suggest','suggests','suggested','imply','implies','implied','conclude','concludes','concluded','decide','decides','decided','determine','determines','determined','control','controls','controlled','pass','passes','passed','fail','fails','failed','marked','graded','tested','reviewed','quiz','quizzes','quizzed','copy','click','clicks','clicked','press','presses','pressed','typed','select','selects','selected','submit','submits','submitted','upload','uploads','uploaded','print','prints','printed','save','saves','saved','delete','deletes','deleted','edit','edits','edited']);
    }
}

if (!function_exists('quiz_third_singular')) {
    // "speed" -> "speeds" so "What speeds ...?" reads professionally.
    function quiz_third_singular($verb) {
        $v = (string)$verb;
        if (!preg_match('/^[A-Za-z][A-Za-z\-]*$/', $v) || strlen($v) < 2) return $v;
        $L = strtolower($v);
        static $keep = null;
        if ($keep === null) $keep = array_flip(['is','are','was','were','am','been','being','has','does','goes','says','can','could','will','would','shall','should','may','might','must','ought','did','had','said','read']);
        if (isset($keep[$L])) return $v;
        if (preg_match('/(ing|ied)$/', $L)) return $v;
        if (preg_match('/ed$/', $L) && !preg_match('/[^e]eed$/', $L)) return $v;
        if (preg_match('/(ses|xes|zes|ches|shes|oes)$/', $L)) return $v;
        if (preg_match('/([^aeiou])ies$/', $L)) return $v;
        if (preg_match('/[^s]s$/', $L)) return $v;
        $irr = ['have' => 'has', 'do' => 'does', 'go' => 'goes', 'say' => 'says'];
        if (isset($irr[$L])) $out = $irr[$L];
        elseif (preg_match('/[^aeiou]y$/', $L)) $out = substr($L, 0, -1) . 'ies';
        elseif (preg_match('/(s|x|z|ch|sh|o)$/', $L)) $out = $L . 'es';
        else $out = $L . 's';
        if (isset($v[0]) && ctype_upper($v[0])) $out = strtoupper($out[0]) . substr($out, 1);
        return $out;
    }
}

if (!function_exists('quiz_pick_direct')) {
    // First keyword (from offset) in the sentence that builds a proper
    // direct question — skipping already-used pairs so twins never repeat.
    function quiz_pick_direct($pool, $sentence, $startKi, $used = []) {
        $n = count($pool);
        for ($t = 0; $t < $n; $t++) {
            $idx = ($startKi + $t) % $n;
            $kw = $pool[$idx];
            if (stripos($sentence, $kw) === false) continue;
            if (isset($used[$sentence . '||' . $kw])) continue;
            if (quiz_direct_question($sentence, $kw) !== null) return ['kw' => $kw, 'idx' => $idx];
        }
        return null;
    }
}

if (!function_exists('quiz_pick_any')) {
    function quiz_pick_any($pool, $sentence, $startKi, $used = []) {
        $n = count($pool);
        for ($t = 0; $t < $n; $t++) {
            $idx = ($startKi + $t) % $n;
            $kw = $pool[$idx];
            if (stripos($sentence, $kw) === false) continue;
            if (isset($used[$sentence . '||' . $kw])) continue;
            return ['kw' => $kw, 'idx' => $idx];
        }
        return null;
    }
}

if (!function_exists('quiz_first_pair')) {
    // First (sentence, keyword-in-it) pair scanning sentences from an offset.
    function quiz_first_pair($pool, $sentences, $startSi, $used = []) {
        $ns = count($sentences);
        for ($a = 0; $a < $ns; $a++) {
            $s = $sentences[($startSi + $a) % $ns];
            foreach ($pool as $kw) {
                if (stripos($s, $kw) === false) continue;
                if (isset($used[$s . '||' . $kw])) continue;
                return ['s' => $s, 'kw' => $kw];
            }
        }
        return null;
    }
}

if (!function_exists('quiz_shuffle_choices')) {
    function quiz_shuffle_choices($choices, $seedNum, $salt) {
        $out = array_values($choices);
        for ($k = count($out) - 1; $k > 0; $k--) {
            $r = ($seedNum * 7 + $k * 13 + $salt) % ($k + 1);
            $tmp = $out[$k]; $out[$k] = $out[$r]; $out[$r] = $tmp;
        }
        return $out;
    }
}

if (!function_exists('quiz_error_spot_item')) {
    // "Identify the incorrect word" fallback: valid, professional, and needs
    // no placeholder words and no blanks.
    function quiz_error_spot_item($pool, $s, $kw, $qtype, $num_choices, $seedNum) {
        $wrongs = quiz_wrong_terms($pool, $s, $kw, 1);
        if (empty($wrongs)) {
            // Last resort: allow a term from the same sentence (still never
            // a look-alike of the answer) so the item keeps its requested type.
            foreach ((array)$pool as $cand) {
                if (!quiz_terms_overlap($cand, $kw)) { $wrongs = [$cand]; break; }
            }
        }
        $wrong = count($wrongs) ? $wrongs[0] : null;
        if ($wrong === null) return null;
        $falseS = quiz_swap_term($s, $kw, $wrong);
        if ($falseS === $s) return null;
        $stem = 'Which word in the following statement is incorrect according to the discussion?' . "\n\n\"" . $falseS . '"';
        if ($qtype === 'identification') return ['qtype' => 'identification', 'question' => $stem, 'choices' => null, 'correct_answer' => $wrong];
        $choices = [$wrong];
        $seen = [strtolower($wrong) => 1];
        $tryAdd = function ($c) use (&$choices, &$seen) {
            $lc = strtolower($c);
            if (isset($seen[$lc])) return false;
            $seen[$lc] = 1; $choices[] = $c; return true;
        };
        foreach ($pool as $cand) {
            if (count($choices) >= $num_choices) break;
            if (quiz_terms_overlap($cand, $wrong)) continue;
            if (stripos($falseS, $cand) === false) continue;
            $tryAdd($cand);
        }
        foreach ($pool as $cand) {
            if (count($choices) >= $num_choices) break;
            if (quiz_terms_overlap($cand, $wrong)) continue;
            $tryAdd($cand);
        }
        $padN = 0;
        while (count($choices) < $num_choices && $padN <= 10) {
            $pad = $padN === 0 ? 'None of the above' : 'None of the above (' . ($padN + 1) . ')';
            $padN++;
            $tryAdd($pad);
        }
        return ['qtype' => 'multiple_choice', 'question' => "Choose the correct answer:\n\n" . $stem, 'choices' => quiz_shuffle_choices(quiz_clean_choices($choices), $seedNum, strlen((string)$wrong)), 'correct_answer' => $wrong];
    }
}

if (!function_exists('quiz_direct_question')) {
    // Build a proper "Which tag creates ...?", "What absorbs ...?",
    // "Who proposed ...?" style question from a source sentence.
    // Returns null when the sentence shape does not allow it.
    function quiz_direct_question($sentence, $keyword) {
        $s = trim((string)$sentence);
        $k = (string)$keyword;
        if ($s === '' || $k === '') return null;
        $wh = quiz_wh_question($s, $k);
        if ($wh !== null) return $wh;
        $ekw = preg_quote($k, '/');
        $pos = false;
        if (preg_match('/\b' . $ekw . '\b/i', $s, $m, PREG_OFFSET_CAPTURE)) $pos = $m[0][1];
        else { $p = stripos($s, $k); if ($p !== false) $pos = $p; }
        if ($pos === false) return null;
        $after = substr($s, $pos + strlen($k));
        $role = null;
        $subjEnd = $pos + strlen($k);
        $alt = implode('s?|', array_map(function ($r) { return preg_quote($r, '/'); }, quiz_roles())) . 's?';
        $roleSet = array_flip(quiz_roles());
        if (preg_match('/^\s+(' . $alt . ')\b/i', $after, $rm)) {
            $raw = strtolower($rm[1]);
            $nos = preg_replace('/s$/', '', $raw);
            $role = isset($roleSet[$raw]) ? $raw : ((isset($roleSet[$nos]) && $nos !== '') ? $nos : $raw);
            $subjEnd = $pos + strlen($k) + strlen($rm[0]);
        }
        $head = substr($s, 0, $subjEnd);
        if (strlen($head) > 48 || strpbrk($head, ',;:') !== false) return null;
        $hwords = preg_split('/\s+/', trim($head));
        if (!$hwords) return null;
        $hcount = count($hwords) - (preg_match('/^(the|a|an)$/i', $hwords[0]) ? 1 : 0);
        if ($hcount < 1 || $hcount > 3) return null;
        if (isset(quiz_head_bad()[strtolower($hwords[0])])) return null;
        $remainder = trim(preg_replace('/[\s.,;:!?…\x{2026}]+$/u', '', substr($s, $subjEnd)));
        if (strlen($remainder) < 3) return null;
        $rwords = preg_split('/\s+/', $remainder);
        $rw = strtolower(trim(preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $rwords[0])));
        if (isset(quiz_rem_bad()[$rw])) return null;
        $verbs = quiz_verbs();
        $hasVerb = false;
        foreach ($rwords as $w) {
            if (isset($verbs[strtolower(trim(preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $w)))])) { $hasVerb = true; break; }
        }
        if (!$hasVerb) return null;
        if ($role !== null) {
            $qw = 'Which ' . $role;
        } else {
            // "What" is never grammatically wrong; "Who" misfires on
            // Title-Case common nouns, so it is intentionally not used.
            $qw = 'What';
        }
        $vclean = trim(preg_replace('/^[^A-Za-z]+|[^A-Za-z]+$/', '', $rwords[0]));
        if ($vclean === '' || strlen($vclean) < 2) return null;
        $vl = strtolower($vclean);
        // The first word must be a known verb. Guessing from word endings
        // produced garbage like "What ses …?" (single letters), "What ups …?"
        // or "What informations …?" (nouns) — those now fall back cleanly.
        if (!isset($verbs[$vl])) return null;
        if (count($rwords) > 1) {
            // The word after the verb must be ordinary prose. Code fragments
            // ("names = {", "types arrayName[size]") or a second verb ("plant
            // produces", "form requires") mean vclean is a noun sharing
            // spelling with a verb — bail out instead of printing nonsense.
            $w2 = trim(preg_replace('/^[^A-Za-z]+|[^A-Za-z]+$/', '', $rwords[1]));
            if ($w2 === '' || preg_match('/[a-z].*[A-Z]/', $w2)) return null;
            if (!preg_match('/^[A-Za-z]+$/', $w2) && !preg_match('/^\d+$/', $w2)) return null;
            $w2v = strtolower($w2);
            $aux = quiz_aux_verbs();
            if (!isset($aux[$vl]) && isset($verbs[$w2v])) return null;
        }
        $conj = quiz_third_singular($vclean);
        $conj = strtolower($conj[0]) . substr($conj, 1);
        $q = $qw . ' ' . $conj . (count($rwords) > 1 ? ' ' . implode(' ', array_slice($rwords, 1)) : '') . '?';
        return trim(preg_replace('/\s+/', ' ', $q));
    }
}

if (!function_exists('quiz_build_one_item')) {
    // Build a single item, advancing the $si / $ki cursors (by reference)
    // and marking used (sentence, keyword) pairs (in $used, by reference).
    function quiz_build_one_item($sentences, $pool, $num_choices, $quiz_type, $typeSeq, $i, &$si, &$ki, &$used) {
        if ($quiz_type === 'mixed') $t = $typeSeq[$i % 3];
        else $t = in_array($quiz_type, $typeSeq, true) ? $quiz_type : 'multiple_choice';
        if ($t === 'true_false') {
            $sTF = null; $kwTF = null; $gTF = 0;
            while ($kwTF === null && $gTF < count($sentences) + 1) {
                $candTF = $sentences[$si % count($sentences)];
                $pickTF = quiz_pick_any($pool, $candTF, $ki, $used);
                if ($pickTF !== null) { $sTF = $candTF; $kwTF = $pickTF['kw']; $ki = $pickTF['idx'] + 1; $used[$sTF . '||' . $kwTF] = 1; }
                else { $si++; $gTF++; }
            }
            if ($kwTF === null) { $sTF = $sentences[$si % count($sentences)]; $kwTF = $pool[$ki % count($pool)]; $ki++; $used[$sTF . '||' . $kwTF] = 1; }
            $isTrue = ($i % 2 === 0);
            if ($isTrue) {
                $item = ['qtype' => 'true_false', 'question' => "True or False:\n\n" . $sTF, 'choices' => ['True', 'False'], 'correct_answer' => 'True'];
            } else {
                // False statement: swap in a term from elsewhere (never a
                // word already in the sentence, else it may stay true).
                $wrongs = quiz_wrong_terms($pool, $sTF, $kwTF, 1);
                $wrong = count($wrongs) ? $wrongs[0] : null;
                if ($wrong === null) {
                    $item = ['qtype' => 'true_false', 'question' => "True or False:\n\nIt is not true that " . lcfirst($sTF), 'choices' => ['True', 'False'], 'correct_answer' => 'False'];
                } else {
                    $falseS = quiz_swap_term($sTF, $kwTF, $wrong);
                    if ($falseS === $sTF) $falseS = 'It is not true that ' . lcfirst($sTF);
                    $item = ['qtype' => 'true_false', 'question' => "True or False:\n\n" . $falseS, 'choices' => ['True', 'False'], 'correct_answer' => 'False'];
                }
            }
            $si++; $ki++;
            return $item;
        }
        // MC/ID: seek a sentence with a direct-capable keyword so the
        // stem is always a proper question — no placeholders, no blanks.
        $sDQ = null; $kwDQ = null; $gDQ = 0;
        while ($kwDQ === null && $gDQ < count($sentences) + 1) {
            $candDQ = $sentences[$si % count($sentences)];
            $pickDQ = quiz_pick_direct($pool, $candDQ, $ki, $used);
            if ($pickDQ !== null) { $sDQ = $candDQ; $kwDQ = $pickDQ['kw']; $ki = $pickDQ['idx'] + 1; $used[$sDQ . '||' . $kwDQ] = 1; }
            else { $si++; $gDQ++; }
        }
        if ($kwDQ !== null) {
            $dq = quiz_direct_question($sDQ, $kwDQ);
            if ($t === 'identification') {
                $item = ['qtype' => 'identification', 'question' => $dq, 'choices' => null, 'correct_answer' => $kwDQ];
            } else {
                $choices = quiz_clean_choices([$kwDQ]);
                foreach ($pool as $cand) {
                    if (count($choices) >= $num_choices) break;
                    $dup = false;
                    foreach ($choices as $c) { if (quiz_terms_overlap($c, $cand)) { $dup = true; break; } }
                    if ($dup) continue;
                    if (stripos($sDQ, $cand) !== false) continue;
                    $v = trim(preg_replace('/\s+/', ' ', (string)$cand));
                    if ($v !== '') $choices[] = $v;
                }
                $choices = quiz_clean_choices($choices);
                $padN = 0;
                while (count($choices) < $num_choices && $padN <= 10) {
                    $pad = $padN === 0 ? 'None of the above' : 'None of the above (' . ($padN + 1) . ')';
                    $padN++;
                    $pexists = false;
                    foreach ($choices as $c) { if (strcasecmp($c, $pad) === 0) { $pexists = true; break; } }
                    if (!$pexists) $choices[] = $pad;
                }
                $item = ['qtype' => 'multiple_choice', 'question' => "Choose the correct answer:\n\n" . $dq, 'choices' => quiz_shuffle_choices($choices, $i, strlen($kwDQ)), 'correct_answer' => $kwDQ];
            }
        } else {
            // No sentence yields a direct question: spot-the-error item.
            $fp = quiz_first_pair($pool, $sentences, $si, $used);
            if ($fp === null) $fp = quiz_first_pair($pool, $sentences, $si, []);
            if ($fp === null) $fp = ['s' => $sentences[$si % count($sentences)], 'kw' => $pool[$ki % count($pool)]];
            $used[$fp['s'] . '||' . $fp['kw']] = 1;
            $fb = quiz_error_spot_item($pool, $fp['s'], $fp['kw'], $t, $num_choices, $i);
            if ($fb !== null) $item = $fb;
            else $item = ['qtype' => 'true_false', 'question' => "True or False:\n\n" . $fp['s'], 'choices' => ['True', 'False'], 'correct_answer' => 'True'];
            $ki++;
        }
        $si++; $ki++;
        return $item;
    }
}

if (!function_exists('quiz_server_generate')) {
    // Deterministic offline generator (mirrors quiz_generator.php JS).
    // Returns array of ['qtype','question','choices'(array|null),'correct_answer'].
    function quiz_server_generate($source, $num_items, $num_choices, $quiz_type) {
        $num_items = max(1, min(100, (int)$num_items));
        $num_choices = max(2, min(5, (int)$num_choices));
        $sentences = quiz_split_sentences($source);
        if (empty($sentences)) return [];
        $pool = quiz_extract_keywords($sentences, 80);
        if (empty($pool)) return [];
        $typeSeq = ['multiple_choice', 'identification', 'true_false'];
        $items = [];
        $si = 0; $ki = 0;
        $seen = [];
        $used = [];
        for ($i = 0; $i < $num_items; $i++) {
            // Retry with fresh cursors so twin questions never repeat.
            $made = null;
            for ($att = 0; $att < 10; $att++) {
                $it = quiz_build_one_item($sentences, $pool, $num_choices, $quiz_type, $typeSeq, $i, $si, $ki, $used);
                $key = $it['qtype'] . '|' . $it['question'] . '|' . $it['correct_answer'];
                if (!isset($seen[$key]) || $att === 9) { $seen[$key] = 1; $made = $it; break; }
            }
            $items[] = $made;
        }
        return $items;
    }
}

if (!function_exists('quiz_class_label')) {
    function quiz_class_label($db, $class_id) {
        $class_id = (int)$class_id;
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare("SELECT (s.name || ' ' || c.grade || ' - ' || c.section) AS lbl FROM class_list c LEFT JOIN subjects s ON c.subject_id = s.subject_id WHERE c.class_id = :c LIMIT 1");
                $st->execute([':c' => $class_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                return $row['lbl'] ?? ('Class #' . $class_id);
            }
            $row = $db->querySingle("SELECT (s.name || ' ' || c.grade || ' - ' || c.section) AS lbl FROM class_list c LEFT JOIN subjects s ON c.subject_id = s.subject_id WHERE c.class_id = " . $class_id . " LIMIT 1", true);
            return ($row && isset($row['lbl'])) ? $row['lbl'] : ('Class #' . $class_id);
        } catch (Exception $e) { return 'Class #' . $class_id; }
    }
}

if (!function_exists('quiz_candidate_db_files')) {
    // Every SQLite file that may hold a student's enrollment or a quiz.
    function quiz_candidate_db_files() {
        $base = __DIR__ . '/db';
        $files = [];
        foreach ((array)glob($base . '/*.db') as $f) $files[$f] = true;
        foreach ((array)glob($base . '/*/*.db') as $f) $files[$f] = true;
        foreach (['db/backups/Administrator', 'db/backups/faculty_backups', 'db/alex'] as $sub) {
            foreach ((array)glob(__DIR__ . '/' . $sub . '/*.db') as $f) $files[$f] = true;
        }
        unset($files[$base . '/grading_db.db'], $files[$base . '/landing_db.db']);
        $ordered = [];
        if (file_exists($base . '/grading_db.db')) $ordered[] = $base . '/grading_db.db';
        foreach (array_keys($files) as $f) {
            if (basename($f) === 'landing_db.db') continue;
            $ordered[] = $f;
        }
        return array_values(array_unique($ordered));
    }
}

if (!function_exists('quiz_db_key')) {
    // Opaque id for a db file (never expose real paths to students).
    function quiz_db_key($db_file) { return substr(md5((string)$db_file), 0, 12); }
}
