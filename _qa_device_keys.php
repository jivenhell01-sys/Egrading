<?php
// Functional test for the strict 1-device-per-student subsystem
// (students/device_keys.php): hardware-key registration, assertion login,
// replay/foreign-key rejection, revocation, email-OTP reset lifecycle, and
// attendance step-up challenges. All writes run against a COPY.
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');
$root = __DIR__;
if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir() . '/qa_keys_' . getmypid());
    @mkdir(sys_get_temp_dir() . '/qa_keys_' . getmypid(), 0777, true);
    @session_start();
}
require_once($root . '/students/device_keys.php');

$live = $root . '/db/grading_db.db';
$copy = $root . '/db/_qa_keys_copy.db';
foreach ([$copy, $copy . '-wal', $copy . '-shm'] as $f) { if (file_exists($f)) unlink($f); }
copy($live, $copy);

$pass = 0; $fail = 0;
function t($l, $c, $x = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS  $l\n"; } else { $fail++; echo "FAIL  $l" . ($x !== '' ? "  << $x" : '') . "\n"; }
}
function opendb() { global $copy; $p = new PDO('sqlite:' . $copy); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }

// ---- CBOR writer (test-only mirror of the decoder's expectations) ----
function qa_cbor($v) {
    if (is_int($v)) {
        if ($v >= 0) {
            if ($v < 24) return chr($v);
            if ($v < 256) return chr(24) . chr($v);
            if ($v < 65536) return chr(25) . pack('n', $v);
            return chr(26) . pack('N', $v);
        }
        $u = -1 - $v;
        if ($u < 24) return chr(0x20 | $u);
        if ($u < 256) return chr(0x38) . chr($u);
        return chr(0x39) . pack('n', $u);
    }
    if (is_string($v)) {
        $n = strlen($v);
        $h = $n < 24 ? chr(0x40 | $n) : ($n < 256 ? chr(0x58) . chr($n) : chr(0x59) . pack('n', $n));
        return $h . $v;
    }
    if (is_array($v)) {
        $isMap = array_keys($v) !== range(0, count($v) - 1);
        $n = count($v);
        $h = $isMap ? (0xA0 | $n) : (0x80 | $n);
        $out = chr($h);
        foreach ($v as $k => $item) {
            if ($isMap) $out .= qa_cbor($k);
            $out .= qa_cbor($item);
        }
        return $out;
    }
    return chr(0xF6);
}
// Test-only P-256 keypairs. Embedded on purpose: openssl_pkey_new() fails in
// this XAMPP environment (no openssl.cnf reachable from PHP, so key
// GENERATION is broken), while the production path — loading a public PEM and
// openssl_verify() — works and is what these tests exercise. Produced once with
// "openssl ecparam -name prime256v1 -genkey". Three distinct pairs let the
// tests forge a foreign signature over a victim credential id.
static $QA_KEYS = [
    1 => ['priv' => '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEICohoSI4s30Vy7P8r6FgNos0x/hBqdi6uXwph3TGtFdDoAoGCCqGSM49
AwEHoUQDQgAEPLdX1+J3/5e969J0SAgldrVOlR14ynfSQjjMB5VPOm2bjOZVTJtM
406yE1WHJesrvdRA+Vrw1N+rkWkpLBqC9w==
-----END EC PRIVATE KEY-----', 'x' => 'PLdX1+J3/5e969J0SAgldrVOlR14ynfSQjjMB5VPOm0=', 'y' => 'm4zmVUybTONOshNVhyXrK73UQPla8NTfq5FpKSwagvc='],
    2 => ['priv' => '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIC6ShRs5cTWsGNUqZX3XGa/wnzib6BVNdlGzYHc709NQoAoGCCqGSM49
AwEHoUQDQgAEjkFM1lpeVyAONcxaZpHLGH9+j+o8Tx3DaJbunZiN9RxBpIhE5VJJ
bHL/UWgwr1PpdZC9UGwmTVmv1DAP5m4gpw==
-----END EC PRIVATE KEY-----', 'x' => 'jkFM1lpeVyAONcxaZpHLGH9+j+o8Tx3DaJbunZiN9Rw=', 'y' => 'QaSIROVSSWxy/1FoMK9T6XWQvVBsJk1Zr9QwD+ZuIKc='],
    3 => ['priv' => '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIFqSzvs1ghpmXHolGnElWCjJdbgj8IjvnfvV6nnYYxkDoAoGCCqGSM49
AwEHoUQDQgAEoA85rISm7Sr+Mm2wpPicJDJA0wzspuWQXmmVbzjAEoBh9gLQx/Iu
Gjfh2+0DpSIvpeVaEzcfFxjDqVGemLq6ew==
-----END EC PRIVATE KEY-----', 'x' => 'oA85rISm7Sr+Mm2wpPicJDJA0wzspuWQXmmVbzjAEoA=', 'y' => 'YfYC0MfyLho34dvtA6UiL6XlWhM3HxcYw6lRnpi6uns='],
];
function qa_keypair($slot = 1) {
    global $QA_KEYS;
    if (!isset($QA_KEYS[$slot])) { throw new Exception("no test key in slot $slot"); }
    $e = $QA_KEYS[$slot];
    $r = openssl_pkey_get_private($e['priv']);
    if ($r === false) throw new Exception("test key slot $slot unusable");
    return [$r, base64_decode($e['x']), base64_decode($e['y'])];
}
function qa_attestation($chRaw, $x, $y, $rp = 'localhost', $origin = 'http://localhost') {
    $credId = random_bytes(32);
    $auth = hash('sha256', $rp, true) . chr(0x41) . pack('N', 0) . str_repeat("\x00", 16) . pack('n', 32) . $credId
        . qa_cbor([1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y]);
    $attObj = qa_cbor(['fmt' => 'none', 'authData' => $auth, 'attStmt' => []]);
    $cli = json_encode(['type' => 'webauthn.create', 'challenge' => hgs_b64u_encode($chRaw), 'origin' => $origin]);
    return [['response' => ['attestationObject' => hgs_b64u_encode($attObj), 'clientDataJSON' => hgs_b64u_encode($cli)]], $credId];
}
function qa_assertion($priv, $credId, $chRaw, $count, $rp = 'localhost', $origin = 'http://localhost') {
    $auth = hash('sha256', $rp, true) . chr(0x01) . pack('N', $count);
    $cli = json_encode(['type' => 'webauthn.get', 'challenge' => hgs_b64u_encode($chRaw), 'origin' => $origin]);
    openssl_sign($auth . hash('sha256', $cli, true), $sig, $priv, OPENSSL_ALGO_SHA256);
    return ['mode' => 'authenticate', 'id' => hgs_b64u_encode($credId),
        'response' => ['authenticatorData' => hgs_b64u_encode($auth), 'clientDataJSON' => hgs_b64u_encode($cli), 'signature' => hgs_b64u_encode($sig)]];
}

echo "=== 1. schema: keys + resets exist, bindings retired ===\n";
$pdo = opendb();
hgs_ensure_key_tables($pdo);
$has = function ($t) use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn() > 0; };
t('keys table exists', $has('student_device_keys'));
t('resets table exists', $has('device_resets'));
t('pins table exists', $has('student_bind_pins'));
t('logs table kept (audit continuity)', $has('student_device_logs'));
t('bindings table dropped', !$has('student_device_bindings'));
t('one-active-key partial index exists', (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_sdk_active_number'") > 0);
t('global credential uniqueness exists', (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_sdk_credential'") > 0);

echo "\n=== 2. PIN preserved (self-service) ===\n";
t('set ok', hgs_set_setup_pin($pdo, '26-60001', '112233', '112233')[0] === 'ok');
t('check ok', hgs_check_setup_pin($pdo, '26-60001', '112233')[0] === 'ok');
t('check bad', hgs_check_setup_pin($pdo, '26-60001', '000000')[0] === 'bad');
t('mismatch rejected', hgs_set_setup_pin($pdo, '26-60002', '112233', '332211')[0] === 'mismatch');

echo "\n=== 3. phase 1.1: hardware registration ===\n";
list($privA, $xA, $yA) = qa_keypair(1);
$chReg = random_bytes(32);
list($attPayload, $credA) = qa_attestation($chReg, $xA, $yA);
list($newId, $newPem) = hgs_webauthn_register($attPayload, $chReg, 'localhost', 'http://localhost');
t('attestation verifies', $newId !== null, (string)$newPem);
list($regOk, $regMsg) = hgs_key_register($pdo, '26-60001', 'Keyed, Test', $newId, $newPem);
t('key row stored', $regOk === true, $regMsg);
t('DB maps number to this credential', $pdo->query("SELECT credential_id FROM student_device_keys WHERE student_number='26-60001' AND is_active=1")->fetchColumn() === $newId);
t('public key is a real P-256 PEM', @openssl_pkey_get_public($newPem) !== false);
list($dupOk, $dupMsg) = hgs_key_register($pdo, '26-60002', 'Other, Test', $newId, $newPem);
t('same credential for a second number rejected', $dupOk === false, $dupMsg);
list($privB, $xB, $yB) = qa_keypair(2);
$chReg2 = random_bytes(32);
list($attB, $credB) = qa_attestation($chReg2, $xB, $yB);
list($newIdB, $newPemB) = hgs_webauthn_register($attB, $chReg2, 'localhost', 'http://localhost');
list($regOkB) = hgs_key_register($pdo, '26-60001', 'Keyed, Test', $newIdB, $newPemB);
t('second ACTIVE key for same number rejected (partial index)', $regOkB === false);

echo "\n=== 4. phase 2.2: assertion-required login ===\n";
$rowA = hgs_key_get_active($pdo, '26-60001');
t('active key found', !empty($rowA) && $rowA['credential_id'] === $newId);
$chAuth = random_bytes(32);
$good = qa_assertion($privA, $credA, $chAuth, 1);
list($aOk) = hgs_webauthn_authenticate($pdo, $rowA, $good, $chAuth, 'localhost', 'http://localhost');
t('own signature verifies (2.2 allows owner)', $aOk === true);
t('sign count advanced', (int)$pdo->query("SELECT sign_count FROM student_device_keys WHERE student_number='26-60001'")->fetchColumn() === 1);
list($aReplay) = hgs_webauthn_authenticate($pdo, hgs_key_get_active($pdo, '26-60001'), $good, $chAuth, 'localhost', 'http://localhost');
t('replayed assertion rejected (clone detection)', $aReplay === false);
$evil = qa_assertion($privB, $credA, random_bytes(32), 5);
// ^ wrong private key over the victim credential id
list($aEvil, $mEvil) = hgs_webauthn_authenticate($pdo, hgs_key_get_active($pdo, '26-60001'), $evil, random_bytes(32), 'localhost', 'http://localhost');
t('foreign signature rejected', $aEvil === false, $mEvil);
list($aNoId) = hgs_webauthn_authenticate($pdo, hgs_key_get_active($pdo, '26-60001'), ['mode' => 'authenticate', 'id' => hgs_b64u_encode(random_bytes(32)), 'response' => []], random_bytes(32), 'localhost', 'http://localhost');
t('unknown credential id rejected', $aNoId === false);

echo "\n=== 5. phase 4.4: revoke + email-OTP reset lifecycle ===\n";
t('revoke deactivates', hgs_key_revoke($pdo, '26-60001', 'qa', 'test revoke') === true);
t('no active key after revoke', hgs_key_get_active($pdo, '26-60001') === null);
t('revoke audit written', (int)$pdo->query("SELECT COUNT(*) FROM student_device_logs WHERE student_number='26-60001' AND status='key_revoked'") === 1);
t('second revoke is a safe no-op', hgs_key_revoke($pdo, '26-60001', 'qa', 'test') === false);
// roster email for OTP delivery test
$pdo->exec("INSERT INTO student_list (class_id, student_number, name, email) VALUES (1,'26-60009','Reset, Test','reset-qa@example.com')");
list($rqOk, $rqMailed) = hgs_reset_request($pdo, '26-60009');
t('reset request accepted (generic)', $rqOk === true);
$rqDet = str_replace('26-60009', '', (string)$pdo->query("SELECT details FROM student_device_logs WHERE student_number='26-60009' AND status='reset_issued'")->fetchColumn());
t('reset audit written, code never logged in plaintext', (int)$pdo->query("SELECT COUNT(*) FROM student_device_logs WHERE student_number='26-60009' AND status='reset_issued'") === 1
    && preg_match('/\d{6}/', $rqDet) === 0);
echo "    (mail() in this environment: " . ($rqMailed ? 'sent' : 'not sent — fallback path') . ")\n";
// drive verify with a KNOWN code: seed one directly (request path returns no code by design)
$pdo->exec("DELETE FROM device_resets WHERE student_number='26-60009'");
$pdo->prepare("INSERT INTO device_resets (student_number, code_hash, expires_at, attempts, used_at, last_sent_at, created_at, created_by) VALUES ('26-60009', :h, :exp, 0, NULL, :s, CURRENT_TIMESTAMP, 'qa')")
    ->execute([':h' => hash('sha256', '26-60009:reset:123456'), ':exp' => time() + 900, ':s' => time()]);
list($vBad) = hgs_reset_verify($pdo, '26-60009', '000000');
t('wrong code rejected + attempt counted', $vBad === false && (int)$pdo->query("SELECT attempts FROM device_resets WHERE student_number='26-60009'")->fetchColumn() === 1);
// enroll a key first so revoke has something to kill
$chR = random_bytes(32);
list($attR, $credR) = qa_attestation($chR, $xB, $yB);
list($nidR, $pemR) = hgs_webauthn_register($attR, $chR, 'localhost', 'http://localhost');
hgs_key_register($pdo, '26-60009', 'Reset, Test', $nidR, $pemR);
list($vOk, $vMsg) = hgs_reset_verify($pdo, '26-60009', '123456');
t('correct code revokes + succeeds', $vOk === true && hgs_key_get_active($pdo, '26-60009') === null, $vMsg);
list($vReuse) = hgs_reset_verify($pdo, '26-60009', '123456');
t('code is single-use', $vReuse === false);
// expired code path
$pdo->prepare("INSERT OR REPLACE INTO device_resets (student_number, code_hash, expires_at, attempts, used_at, last_sent_at, created_at, created_by) VALUES ('26-60008', :h, :exp, 0, NULL, :s, CURRENT_TIMESTAMP, 'qa')")
    ->execute([':h' => hash('sha256', '26-60008:reset:123456'), ':exp' => time() - 1, ':s' => time()]);
list($vExp) = hgs_reset_verify($pdo, '26-60008', '123456');
t('expired code rejected', $vExp === false);
// re-enrollment after reset works (new keypair, not the revoked credential)
list($privC, $xC, $yC) = qa_keypair(3);
$chC = random_bytes(32);
list($attC, $credC) = qa_attestation($chC, $xC, $yC);
list($nidC, $pemC) = hgs_webauthn_register($attC, $chC, 'localhost', 'http://localhost');
list($reOk) = hgs_key_register($pdo, '26-60009', 'Reset, Test', $nidC, $pemC);
t('new device enrolls after reset', $reOk === true);

echo "\n=== 6. phase 3.3: attendance step-up challenge ===\n";
$opt = hgs_attend_options($pdo, '26-60009', 'sess1', 'req1');
t('options issued for enrolled number', ($opt['mode'] ?? '') === 'authenticate' && !empty($opt['challenge']) && !empty($opt['credentialId']));
$sesCh = $_SESSION['attend_challenge']['ch'] ?? '';
$chBytes = base64_decode($sesCh, true);
$att2 = qa_assertion($privC, $credC, $chBytes, 7);
list($sOk) = hgs_attend_verify($pdo, $att2, '26-60009', 'sess1', 'req1', 'localhost', 'http://localhost');
t('step-up signature verifies (PIN never sent)', $sOk === true);
list($sReplay) = hgs_attend_verify($pdo, $att2, '26-60009', 'sess1', 'req1', 'localhost', 'http://localhost');
t('challenge is single-use', $sReplay === false);
$opt2 = hgs_attend_options($pdo, '26-60009', 'sess1', 'req1');
$chB = base64_decode($_SESSION['attend_challenge']['ch'], true);
$att3 = qa_assertion($privC, $credC, $chB, 8);
list($sWrongScope) = hgs_attend_verify($pdo, $att3, '26-60009', 'sess1', 'OTHER', 'localhost', 'http://localhost');
t('challenge bound to session+request (mismatch rejected)', $sWrongScope === false);
$optNone = hgs_attend_options($pdo, '26-60001', 's', 'r');
t('keyless number gets legacy mode', ($optNone['mode'] ?? '') === 'none');

$pdo = null;
foreach ([$copy, $copy . '-wal', $copy . '-shm'] as $f) { if (file_exists($f)) unlink($f); }
echo "\n-----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
