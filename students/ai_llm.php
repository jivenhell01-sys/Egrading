<?php
// External-LLM layer for the student-portal study assistant.
// One OpenAI-compatible client covers OpenAI, Groq, OpenRouter, DeepSeek,
// Ollama and any custom endpoint. Hybrid design: ai_llm_chat() returns null
// whenever the provider is disabled, unconfigured, unreachable or misbehaving
// — the caller then falls back to the built-in local answer engine, so the
// chatbox keeps working offline and without an API key.
// Settings live in the MAIN database (ai_settings, id=1), never in faculty DBs.
if (!function_exists('ai_llm_main_db')) {
    function ai_llm_main_db() {
        $cands = [__DIR__ . '/../db/grading_db.db', __DIR__ . '/db/grading_db.db'];
        foreach ($cands as $p) {
            if (is_string($p) && file_exists($p)) return $p;
        }
        return null;
    }
}

if (!function_exists('ai_llm_ensure_table')) {
    function ai_llm_ensure_table($db) {
        $db->exec("CREATE TABLE IF NOT EXISTS `ai_settings` (
            `id` INTEGER NOT NULL PRIMARY KEY CHECK (id = 1),
            `enabled` INTEGER NOT NULL DEFAULT 0,
            `provider` TEXT NOT NULL DEFAULT 'openai',
            `base_url` TEXT NOT NULL DEFAULT 'https://api.openai.com/v1',
            `model` TEXT NOT NULL DEFAULT 'gpt-4o-mini',
            `api_key` TEXT NOT NULL DEFAULT '',
            `timeout_s` INTEGER NOT NULL DEFAULT 25,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("INSERT OR IGNORE INTO `ai_settings` (id) VALUES (1)");
    }
}

if (!function_exists('ai_llm_presets')) {
    // Provider presets for the admin dropdown. All speak OpenAI-compatible
    // POST {base_url}/chat/completions. Models are editable defaults.
    function ai_llm_presets() {
        return [
            'openai' => ['label' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'needs_key' => true],
            'groq' => ['label' => 'Groq (fast + free tier)', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'openai/gpt-oss-20b', 'needs_key' => true],
            'openrouter' => ['label' => 'OpenRouter', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'openai/gpt-4o-mini', 'needs_key' => true],
            'deepseek' => ['label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'needs_key' => true],
            'ollama' => ['label' => 'Ollama (this machine, free)', 'base_url' => 'http://localhost:11434/v1', 'model' => 'llama3.2:1b', 'needs_key' => false],
            'zen' => ['label' => 'OpenCode Zen (cloud, paid)', 'base_url' => 'https://opencode.ai/zen/v1', 'model' => 'kimi-k2.5', 'needs_key' => true],
            'custom' => ['label' => 'Custom OpenAI-compatible', 'base_url' => '', 'model' => '', 'needs_key' => true],
        ];
    }
}

if (!function_exists('ai_llm_settings')) {
    // Returns settings array or ['enabled' => false] on any problem.
    function ai_llm_settings() {
        $fallback = ['enabled' => false, 'provider' => 'openai', 'base_url' => '', 'model' => '', 'api_key' => '', 'timeout_s' => 25];
        try {
            $path = ai_llm_main_db();
            if ($path === null) return $fallback;
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            $row = $db->querySingle("SELECT enabled, provider, base_url, model, api_key, timeout_s FROM ai_settings WHERE id = 1", true);
            $db->close();
            if (!is_array($row)) return $fallback;
            $presets = ai_llm_presets();
            $provider = strtolower(trim((string)($row['provider'] ?? 'openai')));
            if (!isset($presets[$provider])) $provider = 'openai';
            return [
                'enabled' => ((int)($row['enabled'] ?? 0)) === 1,
                'provider' => $provider,
                'base_url' => rtrim(trim((string)($row['base_url'] ?? '')), '/'),
                'model' => trim((string)($row['model'] ?? '')),
                'api_key' => (string)($row['api_key'] ?? ''),
                'timeout_s' => max(5, min(60, (int)($row['timeout_s'] ?? 25))),
            ];
        } catch (Exception $e) {
            return $fallback;
        }
    }
}

if (!function_exists('ai_llm_summarize_context')) {
    // Compact, token-budgeted rendering of the student's records for the model.
    function ai_llm_summarize_context($context, $maxChars = 4000) {
        $classes = isset($context['classes']) && is_array($context['classes']) ? array_values($context['classes']) : [];
        $out = [];
        foreach (array_slice($classes, 0, 12) as $cls) {
            if (!is_array($cls)) continue;
            $lines = [];
            $lines[] = 'Subject: ' . substr((string)($cls['subject'] ?? '?'), 0, 80)
                . ' | Class: ' . substr((string)($cls['class_name'] ?? ''), 0, 40)
                . ' | Instructor: ' . substr((string)($cls['faculty'] ?? ''), 0, 60);
            $pubBits = [];
            foreach (array_slice((array)($cls['published'] ?? []), 0, 8) as $p) {
                if (!is_array($p)) continue;
                $pubBits[] = ($p['name'] ?? ('Q' . ($p['q'] ?? '?'))) . ': initial ' . ($p['initial'] ?? '?')
                    . ', transmuted ' . ($p['transmuted'] ?? '?') . ', ' . ($p['remarks'] ?? '?');
            }
            $lines[] = 'Published: ' . ($pubBits ? implode('; ', $pubBits) : 'none yet');
            $compAgg = [];
            $pend = [];
            foreach (array_slice((array)($cls['assessments'] ?? []), 0, 60) as $a) {
                if (!is_array($a)) continue;
                $cn = trim((string)($a['component'] ?? 'Component'));
                if (!isset($compAgg[$cn])) $compAgg[$cn] = ['s' => 0.0, 'h' => 0.0, 'n' => 0, 'w' => null];
                if (isset($a['weight']) && is_numeric($a['weight'])) $compAgg[$cn]['w'] = floatval($a['weight']);
                $mk = $a['mark'] ?? null;
                $hps = floatval($a['hps'] ?? 0);
                if ($mk === null || $mk === '' || !is_numeric($mk) || floatval($mk) <= 0) {
                    if ($hps > 0 && count($pend) < 12) $pend[] = ($a['name'] ?? '?') . ' (HPS ' . rtrim(rtrim(number_format($hps, 2, '.', ''), '0'), '.') . ')';
                    continue;
                }
                $compAgg[$cn]['s'] += floatval($mk);
                $compAgg[$cn]['h'] += $hps;
                $compAgg[$cn]['n']++;
            }
            $cbits = [];
            foreach ($compAgg as $cn => $c) {
                $t = $cn . ' ' . ($c['n'] > 0 && $c['h'] > 0 ? round(100 * $c['s'] / $c['h'], 1) . '%' : 'no scores');
                if ($c['w'] !== null) $t .= ' (weight ' . rtrim(rtrim(number_format($c['w'], 1, '.', ''), '0'), '.') . '%)';
                $cbits[] = $t;
            }
            $lines[] = 'Components: ' . ($cbits ? implode('; ', $cbits) : 'none');
            $lines[] = 'Pending: ' . ($pend ? implode(', ', $pend) : 'none');
            $out[] = implode("\n", $lines);
        }
        $text = implode("\n\n", $out);
        if (strlen($text) > $maxChars) $text = substr($text, 0, $maxChars) . "\n[truncated]";
        return $text;
    }
}

if (!function_exists('ai_llm_system_prompt')) {
    function ai_llm_system_prompt() {
        return 'You are Horizon Study Assistant, a friendly school helper for a Filipino college/high-school student. You have two knowledge sources. '
            . "A) STUDENT RECORDS below (subjects, grades, assessments, instructors) — authoritative for anything about the student's schooling. Never invent grades, names, dates, or requirements. "
            . "B) Your own general world knowledge (science, history, current affairs up to your training cutoff, study skills) — use this for general questions like 'who is the president of the Philippines'. "
            . "RULES:\n"
            . "1. School/grade questions: answer ONLY from the STUDENT RECORDS. If the records lack the answer, say so plainly and suggest asking the instructor.\n"
            . "2. General-knowledge questions: answer from your own knowledge. If time-sensitive (e.g. current office-holders), give your best answer, note it may have changed since your training, and suggest verifying from an official source.\n"
            . "3. Passing: CHED equivalent 3.0 or better; DepEd transmuted 75+. CONDITIONAL means 3.1-3.5 (CHED only).\n"
            . "4. Keep replies short (under 150 words), plain sentences, no markdown tables.";
    }
}

if (!function_exists('ai_llm_http_curl')) {
    // Default transport. Returns ['http' => int, 'body' => string] or null on network failure.
    function ai_llm_http_curl($url, $headers, $payloadJson, $timeoutS) {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeoutS));
        $body = curl_exec($ch);
        if ($body === false) {
            error_log('ai_llm_http_curl: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http' => $code, 'body' => (string)$body];
    }
}

if (!function_exists('ai_llm_try_once')) {
    // Single OpenAI-compatible POST. Returns reply text or null. No settings reads here.
    function ai_llm_try_once($baseUrl, $model, $apiKey, $timeoutS, $messages, $http = null) {
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 500,
        ]);
        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;
        try {
            $res = is_callable($http)
                ? $http(rtrim($baseUrl, '/') . '/chat/completions', $headers, $payload, $timeoutS)
                : ai_llm_http_curl(rtrim($baseUrl, '/') . '/chat/completions', $headers, $payload, $timeoutS);
        } catch (Exception $e) {
            error_log('ai_llm_chat transport: ' . $e->getMessage());
            return null;
        }
        if (!is_array($res) || ($res['http'] ?? 0) < 200 || ($res['http'] ?? 0) >= 300) {
            error_log('ai_llm_chat bad status: ' . json_encode($res['http'] ?? null));
            return null;
        }
        $data = json_decode($res['body'] ?? '', true);
        $text = is_array($data) ? trim((string)($data['choices'][0]['message']['content'] ?? '')) : '';
        if ($text === '') return null;
        if (strlen($text) > 1500) $text = substr($text, 0, 1500);
        return $text;
    }
}

if (!function_exists('ai_llm_chat')) {
    // Ask the configured LLM. $history = [['role'=>'user'|'assistant','text'=>...],...] (latest last).
    // Order: primary saved provider first, then automatic local-Ollama fallback
    // (no key needed), then null so the caller uses the built-in local engine.
    // $http override exists for tests: fn($url,$headers,$payloadJson,$timeoutS).
    function ai_llm_chat($message, $context, $history = [], $http = null) {
        $message = trim((string)$message);
        if ($message === '') return null;
        $cfg = ai_llm_settings();
        if (empty($cfg['enabled'])) return null;
        $presets = ai_llm_presets();
        $needsKey = $presets[$cfg['provider']]['needs_key'] ?? true;
        if ($cfg['base_url'] === '' || $cfg['model'] === '') return null;
        if ($needsKey && $cfg['api_key'] === '') return null;

        $records = ai_llm_summarize_context(is_array($context) ? $context : []);
        if (trim($records) === '') $records = '(no student records attached to this question — answer from general knowledge if applicable)';

        $messages = [['role' => 'system', 'content' => ai_llm_system_prompt() . "\n\nSTUDENT RECORDS:\n" . $records]];
        $hist = is_array($history) ? array_slice($history, -6) : [];
        foreach ($hist as $h) {
            if (!is_array($h)) continue;
            $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = trim(substr((string)($h['text'] ?? ''), 0, 800));
            if ($text === '') continue;
            $messages[] = ['role' => $role, 'content' => $text];
        }
        $messages[] = ['role' => 'user', 'content' => substr($message, 0, 1000)];

        // 1) Primary: whatever the admin saved in maintenance.php.
        $text = ai_llm_try_once($cfg['base_url'], $cfg['model'], $cfg['api_key'], $cfg['timeout_s'], $messages, $http);
        if ($text !== null) return $text;

        // 2) Automatic fallback: local Ollama (free, no key) — skipped when
        // primary already is Ollama, or when tests inject a fake transport.
        if ($cfg['provider'] !== 'ollama' && $http === null) {
            $ollama = $presets['ollama'] ?? null;
            if (is_array($ollama) && !empty($ollama['base_url']) && !empty($ollama['model'])) {
                $fb = ai_llm_try_once($ollama['base_url'], $ollama['model'], '', $cfg['timeout_s'], $messages, null);
                if ($fb !== null) {
                    error_log('ai_llm_chat: primary failed, served by local Ollama fallback');
                    return $fb;
                }
            }
        }
        return null;
    }
}
