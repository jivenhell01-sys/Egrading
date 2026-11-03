<?php
// End-to-end HTTP test of the real login.php, run against an ISOLATED sandbox
// copy of the app so no production credential, PIN or binding is ever touched.
//
//   php _qa_build_sandbox.php     (copy of this app + a sanitised DB, test rows)
//   php _qa_login_http.php        (this file — drives the real HTTP flow)
//   then DELETE the <repo>_qa folder (it is web-reachable and holds the roster)
//
// Covers: PIN self-service on first login, returning login, wrong PIN,
// mismatched confirmation, strict refusal for a capable device with no key,
// phase 2.2 refusal when a key IS enrolled, malformed/unknown numbers, and the
// rollback rule (a blocked login must not leave a PIN row behind).
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
date_default_timezone_set('Asia/Manila');
$SELF = __DIR__;
$SANDBOX = 'C:\\xampp\\htdocs\\grading_system_qa';
$URL = 'http://localhost/grading_system_qa/students/login.php';
$SANDBOX_DB = $SANDBOX . '\\db\\grading_db.db';
$PIN = '654321';
$A = '26-60001'; // no PIN yet
$B = '26-60002'; // gets a key enrolled below

$pass = 0; $fail = 0;
function t($l, $c, $x = '') { global $pass, $fail;
    if ($c) { $pass++; echo "PASS  $l\n"; } else { $fail++; echo "FAIL  $l" . ($x !== '' ? "  << $x" : '') . "\n"; } }

if (!file_exists($SANDBOX_DB)) { echo "sandbox DB missing — run build_sandbox.php first\n"; exit(2); }
if (!function_exists('curl_init')) { echo "curl extension missing\n"; exit(2); }
$db = new PDO('sqlite:' . $SANDBOX_DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// reset per-run state so the file is idempotent
foreach (['student_bind_pins', 'device_resets', 'student_device_keys'] as $t2) $db->exec("DELETE FROM $t2");
$db->exec("DELETE FROM student_device_logs");

function post($sn, $fields) {
    $jar = tempnam(sys_get_temp_dir(), 'hgs_e2e');
    $ch = curl_init('http://localhost/grading_system_qa/students/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($jar);
    return [$code, (string)$body];
}
function msg_of($html) {
    if (preg_match('/horizon-flash-danger.*?<span[^>]*>(.*?)<\/span>/s', $html, $m)) return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    return '';
}
function pin_row($sn) { global $db; return $db->query("SELECT pin_hash, fails, locked_until FROM student_bind_pins WHERE student_number = '$sn'")->fetch(PDO::FETCH_ASSOC); }
function statuses($sn) { global $db; $o = []; foreach ($db->query("SELECT status FROM student_device_logs WHERE student_number = '$sn' ORDER BY id") as $r) $o[] = $r['status']; return $o; }

echo "=== A. first login on plain HTTP: student chooses the PIN ===\n";
list($code, $html) = post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => $PIN, 'device_pin_confirm' => $PIN]);
$m = msg_of($html);
// A successful login redirects (302) to the portal; a rejection re-renders the
// form with an error banner. Both are fine here — only the banner matters.
t('login accepted (redirected, no error banner)', ($code === 302 || $code === 200) && $m === '', "HTTP $code / $m");
$row = pin_row($A);
t('PIN row created by the student', (bool)$row);
t('stored as sha256(number:pin)', $row && hash_equals($row['pin_hash'], hash('sha256', $A . ':' . $PIN)));
t('plaintext PIN never stored', $row && strpos(json_encode($row), $PIN) === false);
t('audited as legacy_pin_login (no key capability on HTTP)', in_array('legacy_pin_login', statuses($A), true), implode(',', statuses($A)));

echo "\n=== B. returning login with the same PIN ===\n";
list($code, $html) = post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => $PIN, 'device_pin_confirm' => '']);
t('accepted (verify path, no confirm needed)', msg_of($html) === '', msg_of($html));
t('fail counter still 0', (int)(pin_row($A)['fails'] ?? -1) === 0);

echo "\n=== C. wrong PIN rejected and counted ===\n";
list($code, $html) = post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => '111111', 'device_pin_confirm' => '']);
$m = msg_of($html);
t('rejected with Incorrect PIN', stripos($m, 'Incorrect PIN') !== false, $m);
t('fail counter = 1', (int)pin_row($A)['fails'] === 1);
t('audited as blocked_bad_pin', in_array('blocked_bad_pin', statuses($A), true));

echo "\n=== D. confirmation must match when setting a PIN ===\n";
$db->exec("DELETE FROM student_bind_pins WHERE student_number = '$B'");
list($code, $html) = post($B, ['action' => 'login', 'student_number' => $B, 'device_pin' => $PIN, 'device_pin_confirm' => '222222']);
$m = msg_of($html);
t('mismatched confirmation rejected', stripos($m, 'do not match') !== false, $m);
t('no PIN row created by a failed setup', pin_row($B) === false);

echo "\n=== E. capable device without a key is refused (strict) ===\n";
list($code, $html) = post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => $PIN, 'device_pin_confirm' => '', 'webauthn_capable' => '1']);
$m = msg_of($html);
t('refused: enrollment required', stripos($m, 'Security-key enrollment is required') !== false, $m);
t('audited as blocked_key_required', in_array('blocked_key_required', statuses($A), true));

echo "\n=== F. phase 2.2: enrolled key cannot be bypassed by PIN alone ===\n";
// Give B a PIN + an enrolled (active) hardware key, as a first-login ceremony would.
post($B, ['action' => 'login', 'student_number' => $B, 'device_pin' => $PIN, 'device_pin_confirm' => $PIN]);
t('B has a PIN now', (bool)pin_row($B));
$db->exec("INSERT INTO student_device_keys (student_number, student_name, credential_id, public_key, sign_count, is_active)
           VALUES ('$B', 'Bravo, Test', '" . hgs_e2e_cred() . "', '" . hgs_e2e_pem() . "', 0, 1)");
t('B has one active key', (int)$db->query("SELECT COUNT(*) FROM student_device_keys WHERE student_number='$B' AND is_active=1")->fetchColumn() === 1);
list($code, $html) = post($B, ['action' => 'login', 'student_number' => $B, 'device_pin' => $PIN, 'device_pin_confirm' => '']);
$m = msg_of($html);
t('correct PIN is NOT enough once a key is enrolled', stripos($m, 'locked to its registered security key') !== false, $m);
t('audited as blocked_key_required', in_array('blocked_key_required', statuses($B), true));
list($code, $html) = post($B, ['action' => 'login', 'student_number' => $B, 'device_pin' => $PIN, 'device_pin_confirm' => '', 'webauthn_capable' => '1', 'webauthn_result' => '{"mode":"authenticate","id":"forged","response":{}}']);
t('forged assertion refused', msg_of($html) !== '', msg_of($html));

echo "\n=== G. bad input ===\n";
list($code, $html) = post('26', ['action' => 'login', 'student_number' => '26', 'device_pin' => $PIN, 'device_pin_confirm' => $PIN]);
t('malformed number rejected', stripos(msg_of($html), 'Invalid student number') !== false, msg_of($html));
list($code, $html) = post('26-99999', ['action' => 'login', 'student_number' => '26-99999', 'device_pin' => $PIN, 'device_pin_confirm' => $PIN]);
$m = msg_of($html);
t('unknown number rejected', stripos($m, 'not found') !== false || stripos($m, 'Incorrect Student Number') !== false, $m);
t('no PIN row for an unknown number (no squatting)', pin_row('26-99999') === false);

echo "\n=== H. PIN lockout after 5 wrong tries ===\n";
for ($i = 0; $i < 4; $i++) post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => '111111', 'device_pin_confirm' => '']);
list($code, $html) = post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => '111111', 'device_pin_confirm' => '']);
$m = msg_of($html);
t('locked after repeated failures', stripos($m, 'locked for 15 minutes') !== false, $m);
t('even the CORRECT PIN is refused while locked',
    (function () use ($A, $PIN) { list($c, $h) = post($A, ['action' => 'login', 'student_number' => $A, 'device_pin' => $PIN, 'device_pin_confirm' => '']); return stripos(msg_of($h), 'locked for 15 minutes') !== false; })());

echo "\n-----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);

function hgs_e2e_cred() { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function hgs_e2e_pem()  { return '-----BEGIN PUBLIC KEY-----\nnotarealkey\n-----END PUBLIC KEY-----\n'; }