<?php
// Admin-only AI provider settings (get/save/test). API keys are never sent
// back to the browser in full — only a masked hint.
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
require_once('students/ai_llm.php');

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

function ai_settings_db() {
    $path = ai_llm_main_db();
    if ($path === null) throw new Exception('Main database not found');
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    ai_llm_ensure_table($db);
    return $db;
}

function ai_mask_key($key) {
    $key = (string)$key;
    if ($key === '') return '';
    if (strlen($key) <= 8) return '••••••••';
    return substr($key, 0, 3) . '••••••••' . substr($key, -4);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input)) { $input = $_POST; }
$action = trim($input['action'] ?? ($_GET['action'] ?? ''));

try {
    if ($action === 'get') {
        $db = ai_settings_db();
        $row = $db->querySingle("SELECT enabled, provider, base_url, model, api_key, timeout_s FROM ai_settings WHERE id = 1", true);
        $db->close();
        $presets = ai_llm_presets();
        $provider = isset($presets[$row['provider'] ?? '']) ? $row['provider'] : 'openai';
        echo json_encode(['status' => 'success', 'settings' => [
            'enabled' => ((int)($row['enabled'] ?? 0)) === 1,
            'provider' => $provider,
            'base_url' => $row['base_url'] ?? $presets[$provider]['base_url'],
            'model' => $row['model'] ?? $presets[$provider]['model'],
            'has_key' => !empty($row['api_key']),
            'key_hint' => ai_mask_key($row['api_key'] ?? ''),
            'timeout_s' => (int)($row['timeout_s'] ?? 25),
            'presets' => $presets,
        ]]);
        exit;
    }

    if ($action === 'save') {
        $presets = ai_llm_presets();
        $provider = strtolower(trim($input['provider'] ?? 'openai'));
        if (!isset($presets[$provider])) throw new Exception('Unknown provider');
        $baseUrl = rtrim(trim($input['base_url'] ?? $presets[$provider]['base_url']), '/');
        $model = trim($input['model'] ?? $presets[$provider]['model']);
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) throw new Exception('Base URL must start with http:// or https://');
        if ($model === '') throw new Exception('Model is required');
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $newKey = (string)($input['api_key'] ?? '');

        $db = ai_settings_db();
        if ($newKey !== '') {
            $stmt = $db->prepare("UPDATE ai_settings SET enabled=:e, provider=:p, base_url=:b, model=:m, api_key=:k, updated_at=CURRENT_TIMESTAMP WHERE id=1");
            $stmt->bindValue(':k', $newKey, SQLITE3_TEXT);
        } else {
            $stmt = $db->prepare("UPDATE ai_settings SET enabled=:e, provider=:p, base_url=:b, model=:m, updated_at=CURRENT_TIMESTAMP WHERE id=1");
        }
        $stmt->bindValue(':e', $enabled, SQLITE3_INTEGER);
        $stmt->bindValue(':p', $provider, SQLITE3_TEXT);
        $stmt->bindValue(':b', $baseUrl, SQLITE3_TEXT);
        $stmt->bindValue(':m', $model, SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
        echo json_encode(['status' => 'success', 'message' => 'AI provider settings saved.']);
        exit;
    }

    if ($action === 'test') {
        // Live probe with the SAVED settings (key stays server-side).
        // Reports the real HTTP reason instead of a generic "did not answer".
        $probeCtx = ['classes' => [[
            'subject' => 'Sample Subject', 'class_name' => 'BSIT - 1A', 'faculty' => 'Test Instructor',
            'published' => [['q' => 2, 'name' => 'Midterm', 'initial' => 80, 'transmuted' => 2.5, 'remarks' => 'PASSED']],
            'assessments' => [],
        ]]];
        $cfg = ai_llm_settings();
        if (empty($cfg['enabled'])) throw new Exception('The provider is disabled — enable it first, Save, then Test.');
        if ($cfg['base_url'] === '' || $cfg['model'] === '') throw new Exception('Base URL and model are required.');
        $presets = ai_llm_presets();
        if (($presets[$cfg['provider']]['needs_key'] ?? true) && $cfg['api_key'] === '') throw new Exception('No API key saved for this provider — paste the key and Save first.');
        $records = ai_llm_summarize_context($probeCtx);
        $payload = json_encode([
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => ai_llm_system_prompt() . "\n\nSTUDENT RECORDS:\n" . $records],
                ['role' => 'user', 'content' => 'Reply with exactly: OK'],
            ],
            'temperature' => 0.3,
            'max_tokens' => 500,
        ]);
        $headers = ['Content-Type: application/json'];
        if ($cfg['api_key'] !== '') $headers[] = 'Authorization: Bearer ' . $cfg['api_key'];
        $res = ai_llm_http_curl(rtrim($cfg['base_url'], '/') . '/chat/completions', $headers, $payload, $cfg['timeout_s']);
        if (!is_array($res)) throw new Exception('Could not reach ' . $cfg['base_url'] . ' (network/timeout — is the server/URL correct?). The chatbox will keep using the built-in engine.');
        $http = (int)($res['http'] ?? 0);
        if ($http < 200 || $http >= 300) {
            $snippet = trim((string)($res['body'] ?? ''));
            if (strlen($snippet) > 160) $snippet = substr($snippet, 0, 160) . '…';
            if ($http === 401) throw new Exception('401 Unauthorized — the saved API key is invalid/wrong provider (starts with "' . substr($cfg['api_key'], 0, 3) . '…", length ' . strlen($cfg['api_key']) . '). Re-paste the key and Save.');
            if ($http === 403) throw new Exception('403 Forbidden — key valid but no access to model "' . $cfg['model'] . '". ' . $snippet);
            if ($http === 404) throw new Exception('404 Not found — model "' . $cfg['model'] . '" not found at ' . $cfg['base_url'] . ' (wrong model name or Base URL). ' . $snippet);
            if ($http === 429) throw new Exception('429 Rate limited / no credits — ' . $snippet);
            if ($http === 0) throw new Exception('No HTTP response from provider (DNS/firewall?).');
            throw new Exception('Provider HTTP ' . $http . ' — ' . $snippet);
        }
        $data = json_decode($res['body'] ?? '', true);
        $text = is_array($data) ? trim((string)($data['choices'][0]['message']['content'] ?? '')) : '';
        if ($text === '') throw new Exception('Provider answered HTTP 200 but with an empty/unexpected body.');
        echo json_encode(['status' => 'success', 'message' => 'Provider answered: ' . mb_substr($text, 0, 120)]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
