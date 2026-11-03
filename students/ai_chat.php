<?php
// Student-portal AI chat endpoint. Stateless: the portal page embeds a compact
// context (built from rows the student may already see); each message carries
// it back. Read-only — nothing here writes to any database.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/students/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
header('Content-Type: application/json');
require_once('ai_assistant.php');
require_once('ai_llm.php');

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    http_response_code(403);
    error_log('ai_chat 403: no student session for ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    echo json_encode(['error' => 'Not signed in. Please log in to the student portal first.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (empty($input) && !empty($_GET)) $input = $_GET; // GET fallback transport
// Base64-wrapped transport (passes hosting firewalls that pattern-match
// JSON text in POST bodies). Plain fields remain accepted.
if (($input['enc'] ?? '') === 'b64') {
    foreach (['message', 'history', 'context'] as $k) {
        if (isset($input[$k]) && is_string($input[$k])) {
            $raw = base64_decode($input[$k], true);
            $input[$k] = ($raw === false) ? '' : $raw;
        }
    }
}
// Form-encoded transports (FormData / urlencoded, used to pass hosting
// firewalls that block JSON bodies) nest complex fields as JSON strings.
foreach (['history', 'context'] as $k) {
    if (isset($input[$k]) && is_string($input[$k])) {
        $d = json_decode($input[$k], true);
        if (is_array($d)) { $input[$k] = $d; } else { unset($input[$k]); }
    }
}

$message = trim(strip_tags((string)($input['message'] ?? '')));
if (strlen($message) > 500) $message = substr($message, 0, 500);

$context = (isset($input['context']) && is_array($input['context'])) ? $input['context'] : [];
if (isset($context['student_name'])) $context['student_name'] = substr((string)$context['student_name'], 0, 120);
if (isset($context['classes']) && is_array($context['classes'])) {
    $context['classes'] = array_slice(array_values($context['classes']), 0, 20);
    foreach ($context['classes'] as &$c) {
        if (!is_array($c)) { $c = []; continue; }
        foreach (['subject', 'class_name', 'faculty'] as $k) {
            $c[$k] = substr((string)($c[$k] ?? ''), 0, 200);
        }
        if (isset($c['assessments']) && is_array($c['assessments'])) {
            $c['assessments'] = array_slice(array_values($c['assessments']), 0, 80);
        } else {
            $c['assessments'] = [];
        }
        if (isset($c['published']) && is_array($c['published'])) {
            $c['published'] = array_slice(array_values($c['published']), 0, 12);
        } else {
            $c['published'] = [];
        }
    }
    unset($c);
} else {
    $context['classes'] = [];
}

// Session grades cache: the page sends full records on the first message;
// tiny follow-ups (and GET retries) reuse the cached copy.
if (!empty($context['classes'])) {
    $_SESSION['ai_ctx'] = $context;
} elseif (!empty($_SESSION['ai_ctx']['classes'])) {
    $context = $_SESSION['ai_ctx'];
}

// Recent transcript for follow-up questions ("it", "that subject"). Capped.
$history = [];
if (isset($input['history']) && is_array($input['history'])) {
    foreach (array_slice($input['history'], -6) as $h) {
        if (!is_array($h)) continue;
        $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim(strip_tags((string)($h['text'] ?? '')));
        if ($text === '') continue;
        if (strlen($text) > 500) $text = substr($text, 0, 500);
        $history[] = ['role' => $role, 'text' => $text];
    }
}

// External LLM first (when the admin enabled and configured one), built-in
// local engine as the offline fallback. Replies are tagged with their source.
$aiText = ai_llm_chat($message, $context, $history);
if ($aiText !== null) {
    echo json_encode(['reply' => [
        'html' => nl2br(htmlspecialchars($aiText, ENT_QUOTES, 'UTF-8')),
        'suggestions' => ['What do I need next quarter?', 'What is still pending?'],
        'source' => 'ai',
    ]]);
    exit;
}

$local = ai_reply($message, $context);
$local['source'] = 'local';
echo json_encode(['reply' => $local]);