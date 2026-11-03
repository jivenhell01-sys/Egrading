<?php
// Configure session for student system to avoid conflicts with admin system
if (session_status() === PHP_SESSION_NONE) {
    // Compute cookie path dynamically so it works both at /students/ (root deploy)
    // and at /grading_system/students/ (XAMPP subfolder). Must be a prefix of
    // the request URI or the browser will not send the cookie to index.php.
    $studentCookiePath = '/students/';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($d !== '' && $d !== '.' && $d !== '/') {
            $studentCookiePath = $d . '/';
        }
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $studentCookiePath,
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Ensure a persistent device token exists for binding PINs
if (empty($_COOKIE['device_token'])) {
    $token = bin2hex(random_bytes(16));
    setcookie('device_token', $token, 0, $studentCookiePath, '', false, true);
}

require_once('../DBConnection.php');
require_once(__DIR__ . '/device_keys.php'); // strict 1-device-per-student subsystem (keys, PIN, challenges, resets)

// Check if student is already logged in
if (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true) {
    header('Location: index.php');
    exit();
}

$error_message = '';
// ($blocked_details request-approval banner retired with the old device model.)

// ------------------------------------------------------------------
// Device helpers live in device_keys.php (required above). This page keeps
// only the login/registration/reset flows and its own view.
// HGS_MAIN_DB is already defined there (it owns the device-key tables); the
// guard keeps this page usable if the include is ever changed, and avoids an
// "already defined" warning on every login page load.
// ------------------------------------------------------------------
if (!defined('HGS_MAIN_DB')) define('HGS_MAIN_DB', __DIR__ . '/../db/grading_db.db');

// (Device helpers moved to device_keys.php — fingerprint/token/family
// recognition removed with the old schema. Login keeps: PIN + hardware key.)

/*__HGS_DEL_C2__*/

// (Device evidence helpers removed with the old schema.)

// (Bind-request approval flow removed — device resets now go through the
// email-OTP flow below. The offline outbox type is left untouched.)

// (PIN helpers moved to device_keys.php — unchanged semantics.)

// (WebAuthn core moved to device_keys.php — hardware keys are now the
// device identity. Strictness is graded: an enrolled key is always
// required; never-enrolled numbers (plain HTTP) stay PIN-only.)

// Uncompressed P-256 point (X/Y, 32 bytes each) → PEM public key.
// (Remaining WebAuthn verifiers moved to device_keys.php.)

// WebAuthn ceremony options (pre-login AJAX): tells the login page whether
// this number needs key registration, key authentication, or nothing, and
// issues the single-use challenge. No auth required (same exposure as login).
if (isset($_REQUEST['webauthn']) && $_REQUEST['webauthn'] === 'options') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    $sn = trim((string)($_REQUEST['student_number'] ?? ''));
    if (!preg_match('/^\d{2}-\d{5}$/', $sn)) { echo json_encode(['status' => 'error', 'msg' => 'Invalid number']); exit; }
    try {
        $pdoO = new PDO('sqlite:' . HGS_MAIN_DB);
        $pdoO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        hgs_ensure_key_tables($pdoO);
        $keyRowO = hgs_key_get_active($pdoO, $sn);
        $hasKey = !empty($keyRowO) && trim((string)($keyRowO['credential_id'] ?? '')) !== '';
        $chRaw = random_bytes(32);
        $rpId = hgs_webauthn_rp();
        $_SESSION['webauthn_challenge'] = ['ch' => base64_encode($chRaw), 'exp' => time() + 300, 'mode' => $hasKey ? 'authenticate' : 'register', 'sn' => $sn];
        $out = ['status' => 'success', 'mode' => $hasKey ? 'authenticate' : 'register', 'challenge' => hgs_b64u_encode($chRaw), 'rpId' => $rpId, 'userName' => $sn, 'userHandle' => hgs_b64u_encode($sn)];
        if ($hasKey) $out['credentialId'] = trim((string)$keyRowO['credential_id']);
        echo json_encode($out);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => 'Could not start device key ceremony.']); }
    exit;
}

// Does this student number already carry a login PIN? Drives the client-side
// Confirm-PIN field: confirmation is only meaningful while a PIN is being
// CREATED, so a returning student has no use for a second box.
//
// This is UI ONLY. The server never trusts it — hgs_check_setup_pin() returning
// 'unissued' remains the single source of truth, and a hidden field simply
// posts an empty value that the set-mode path ignores. Failures therefore
// resolve to "show the field" (see the JS), which can never lock a first-timer
// out of setting their PIN.
if (isset($_REQUEST['pin']) && $_REQUEST['pin'] === 'status') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    $snP = trim((string)($_REQUEST['student_number'] ?? ''));
    if (!preg_match('/^\d{2}-\d{5}$/', $snP)) { echo json_encode(['status' => 'error', 'msg' => 'Invalid number']); exit; }
    try {
        $pdoP = new PDO('sqlite:' . HGS_MAIN_DB);
        $pdoP->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoP->exec("PRAGMA busy_timeout = 5000");
        hgs_ensure_key_tables($pdoP);
        $stP = $pdoP->prepare("SELECT 1 FROM student_bind_pins WHERE student_number = :sn LIMIT 1");
        $stP->execute([':sn' => $snP]);
        echo json_encode(['status' => 'success', 'has_pin' => (bool)$stP->fetchColumn()]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Could not check PIN status.']);
    }
    exit;
}

// Auto-bind completion (portal side): finishes the hardware-key enrollment the
// login page deferred — the first login that passed on the PIN alone because
// the passkey prompt was skipped or failed. Gated on the student's OWN logged
// in session plus the pending flag login.php set for that same number, so an
// attacker cannot enroll anything on someone else's behalf. JSON in/out.
if (isset($_REQUEST['bind']) && $_REQUEST['bind'] === 'complete') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    if (empty($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Session expired — please log in again.']);
        exit;
    }
    $snB = trim((string)$_SESSION['student_number']);
    $pendingB = trim((string)($_SESSION['pending_key_enroll'] ?? ''));
    if ($pendingB === '' || !hash_equals($snB, $pendingB)) {
        echo json_encode(['status' => 'error', 'msg' => 'This device does not need binding — your number is already bound.']);
        exit;
    }
    try {
        $pdoB = new PDO('sqlite:' . HGS_MAIN_DB);
        $pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoB->exec("PRAGMA busy_timeout = 5000");
        hgs_ensure_key_tables($pdoB);
        $keyB = hgs_key_get_active($pdoB, $snB);
        if (!empty($keyB)) {
            // Bound meanwhile (other tab / earlier attempt): just clear up.
            unset($_SESSION['pending_key_enroll'], $_SESSION['webauthn_challenge']);
            $_SESSION['key_enrolled'] = true;
            echo json_encode(['status' => 'success', 'msg' => 'This device is already bound.']);
            exit;
        }
        $payloadB = null;
        if (isset($_POST['webauthn_result']) && trim((string)$_POST['webauthn_result']) !== '') {
            $kdB = json_decode((string)$_POST['webauthn_result'], true);
            if (is_array($kdB)) $payloadB = $kdB;
        }
        $sesB = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);
        $chB = null;
        if (is_array($sesB) && ($sesB['mode'] ?? '') === 'register' && ($sesB['sn'] ?? '') === $snB && ($sesB['exp'] ?? 0) >= time()) {
            $chB = base64_decode((string)($sesB['ch'] ?? ''), true);
        }
        if (!is_array($payloadB) || ($payloadB['mode'] ?? '') !== 'register' || !is_string($chB) || $chB === '') {
            echo json_encode(['status' => 'error', 'msg' => 'Security-key step missing or expired — tap Bind now to try again.']);
            exit;
        }
        list($newIdB, $newPemB) = hgs_webauthn_register($payloadB, $chB, hgs_webauthn_rp(), hgs_webauthn_origin());
        if ($newIdB === null) {
            echo json_encode(['status' => 'error', 'msg' => 'Security-key enrollment failed' . ($newPemB !== '' ? ' (' . htmlspecialchars($newPemB) . ')' : '') . '. Turn on your screen lock and use an updated browser, then retry.']);
            exit;
        }
        $uaB = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ipB = hgs_get_client_ip();
        $modelB = hgs_parse_device_build_fallback($uaB);
        list($okB, $msgB) = hgs_key_register($pdoB, $snB, (string)($_SESSION['student_name'] ?? ''), $newIdB, $newPemB);
        if (!$okB) {
            hgs_log_device($pdoB, $snB, '', $modelB, $ipB, $uaB, 'blocked_race', 'Auto-bind conflict: ' . $msgB);
            unset($_SESSION['pending_key_enroll']);
            echo json_encode(['status' => 'error', 'msg' => htmlspecialchars($msgB)]);
            exit;
        }
        unset($_SESSION['pending_key_enroll']);
        $_SESSION['key_enrolled'] = true;
        hgs_log_device($pdoB, $snB, '', $modelB, $ipB, $uaB, 'key_registered', 'Hardware security key enrolled for ' . $snB . ' — auto-bound after login.');
        echo json_encode(['status' => 'success', 'msg' => 'Device bound — your number now works only on this device.']);
        exit;
    } catch (Exception $eB) {
        error_log('bind complete: ' . $eB->getMessage());
        echo json_encode(['status' => 'error', 'msg' => 'Could not bind the device. Please try again.']);
        exit;
    }
}

// Lost-device reset via email OTP (phase 4.4, student self-service).
// Two calls, both unauthenticated (same exposure as the login POST itself):
//   ?reset=request  {student_number} -> generic reply always (no account or
//                    email enumeration); a 6-digit code is emailed to the
//                    registered address when one exists (15-min, single-use).
//   ?reset=verify   {student_number, code} -> on success the old hardware
//                    key is revoked immediately; the student then logs in
//                    normally (PIN + fresh key enrollment on the new device).
if (isset($_REQUEST['reset']) && $_REQUEST['reset'] === 'request') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    try {
        $pdoR = new PDO('sqlite:' . HGS_MAIN_DB);
        $pdoR->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $snR = trim((string)($_POST['student_number'] ?? ''));
        if (preg_match('/^\d{2}-\d{5}$/', $snR)) {
            hgs_reset_request($pdoR, $snR);
        }
    } catch (Exception $e) { error_log('reset request: ' . $e->getMessage()); }
    echo json_encode(['status' => 'success', 'msg' => 'If that number has a registered email address, a reset code was sent. It expires in 15 minutes.']);
    exit;
}
if (isset($_REQUEST['reset']) && $_REQUEST['reset'] === 'verify') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    $snV = trim((string)($_POST['student_number'] ?? ''));
    $codeV = trim((string)($_POST['code'] ?? ''));
    if (!preg_match('/^\d{2}-\d{5}$/', $snV)) {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid student number format. Use XX-XXXXX.']);
        exit;
    }
    try {
        $pdoV = new PDO('sqlite:' . HGS_MAIN_DB);
        $pdoV->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        list($okV, $msgV) = hgs_reset_verify($pdoV, $snV, $codeV);
        echo json_encode(['status' => $okV ? 'success' : 'error', 'msg' => $msgV]);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => 'Could not verify the code. Please try again.']); }
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_number = isset($_POST['student_number']) ? trim($_POST['student_number']) : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'login' && !empty($student_number)) {
        if (!preg_match('/^\d{2}-\d{5}$/', $student_number)) {
            $error_message = 'Invalid student number format. Please use XX-XXXXX format.';
        } else {
            // Login PIN (6 digits, digits only). First-timers choose their own
            // PIN (confirmed below); returners enter the PIN they chose.
            // Required on every login — see hgs_check_setup_pin().
            $device_pin = isset($_POST['device_pin']) ? preg_replace('/\D/', '', (string)$_POST['device_pin']) : '';
            $device_pin = substr($device_pin, 0, 6);
            $device_pin_confirm = isset($_POST['device_pin_confirm']) ? preg_replace('/\D/', '', (string)$_POST['device_pin_confirm']) : '';
            $device_pin_confirm = substr($device_pin_confirm, 0, 6);
            // Hardware capability flag: page JS sends '1' only when running
            // in a secure context WITH WebAuthn available (HTTPS/localhost +
            // modern browser). Advisory only — verdicts rest on the PIN plus
            // a verified ceremony, never on this flag alone.
            $capable = (isset($_POST['webauthn_capable']) && $_POST['webauthn_capable'] === '1');
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = hgs_get_client_ip();
            // Display label for audit rows only — never identity. Fingerprint
            // / family / token recognition is gone by design.
            $device_model = hgs_parse_device_build_fallback($ua);

                $student = null;
                $found_in_db = '';
                try {
                    $conn = new DBConnection();
                    $stmt = $conn->query("SELECT s.*, c.grade, c.section, sub.name as subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = '$student_number' LIMIT 1");
                    $student = $stmt->fetchArray(SQLITE3_ASSOC);
                    if ($student) { $found_in_db = 'Main Database'; }
                } catch (Exception $e) {}
                if (!$student) {
                    // ---- Landing-page self-registrations (db/landing_db.db) ----
                    // "Enroll Now" students live in landing_registrations — a
                    // DIFFERENT schema with no student_list/class_list/subjects
                    // join, so the generic faculty-DB loop below cannot serve
                    // it (the join would only throw and be skipped). Availability
                    // is therefore checked here explicitly: the number existing
                    // in landing_db.db is enough to log in (both 'pending' and
                    // 'enrolled' rows). The student's own login PIN + hardware-
                    // key gate below applies identically afterwards, and the
                    // portal loads classes/grades by student number.
                    try {
                        $landing_path = __DIR__ . '/../db/landing_db.db';
                        if (file_exists($landing_path)) {
                            $landing_conn = new PDO('sqlite:' . $landing_path, null, null, [PDO::ATTR_TIMEOUT => 5]);
                            $landing_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            $stmt = $landing_conn->prepare("SELECT id, student_number, name, firstname, lastname, year, section, course, status FROM landing_registrations WHERE student_number = :sn LIMIT 1");
                            $stmt->execute([':sn' => $student_number]);
                            $rowL = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($rowL && trim((string)$rowL['student_number']) !== '') {
                                $nameL = trim((string)($rowL['name'] ?? ''));
                                if ($nameL === '') {
                                    $nameL = trim((string)($rowL['firstname'] ?? '') . ' ' . (string)($rowL['lastname'] ?? ''));
                                }
                                $student = [
                                    'student_id' => $rowL['id'],
                                    'student_number' => $rowL['student_number'],
                                    'name' => $nameL !== '' ? $nameL : (string)$rowL['student_number'],
                                    'grade' => '', // no class placed yet in the grading DB
                                    'section' => (string)($rowL['section'] ?? ''),
                                    'subject_name' => '',
                                    'landing_status' => (string)($rowL['status'] ?? ''),
                                ];
                                $found_in_db = 'landing_db.db';
                            }
                            $landing_conn = null;
                        }
                    } catch (Exception $e) {}
                }
                if (!$student) {
                    $faculty_dbs = glob(__DIR__ . '/../db/*.db');
                    foreach ($faculty_dbs as $db_file) {
                        // grading_db handled above; landing_db handled above
                        // (own schema — the join below would only throw for it).
                        if (basename($db_file) !== 'grading_db.db' && basename($db_file) !== 'landing_db.db') {
                            try {
                                $faculty_conn = new PDO('sqlite:' . $db_file);
                                $faculty_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $stmt = $faculty_conn->query("SELECT s.*, c.grade, c.section, sub.name as subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = '$student_number' LIMIT 1");
                                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($student) { $found_in_db = basename($db_file); break; }
                            } catch (Exception $e) {}
                        }
                    }
                }
                if (!$student) {
                    $admin_backup_path = __DIR__ . '/../db/backups/Administrator/';
                    if (is_dir($admin_backup_path)) {
                        $admin_dbs = glob($admin_backup_path . '*.db');
                        foreach ($admin_dbs as $db_file) {
                            try {
                                $admin_conn = new PDO('sqlite:' . $db_file);
                                $admin_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $stmt = $admin_conn->query("SELECT s.*, c.grade, c.section, sub.name as subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = '$student_number' LIMIT 1");
                                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($student) { $found_in_db = 'Administrator/' . basename($db_file); break; }
                            } catch (Exception $e) {}
                        }
                    }
                }
                if (!$student) {
                    $faculty_backup_path = __DIR__ . '/../db/backups/faculty_backups/';
                    if (is_dir($faculty_backup_path)) {
                        $backup_dbs = glob($faculty_backup_path . '*.db');
                        foreach ($backup_dbs as $db_file) {
                            try {
                                $backup_conn = new PDO('sqlite:' . $db_file);
                                $backup_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $stmt = $backup_conn->query("SELECT s.*, c.grade, c.section, sub.name as subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = '$student_number' LIMIT 1");
                                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($student) { $found_in_db = 'faculty_backups/' . basename($db_file); break; }
                            } catch (Exception $e) {}
                        }
                    }
                }
                if ($student) {
                    // -----------------------------
                    // Strict 1-device-per-student gate: login PIN + hardware key
                    // -----------------------------
                    // 1) PIN first (unchanged semantics): check the hash for
                    //    this number; unissued numbers enter set mode (choose
                    //    + confirm) instead of being blocked.
                    // 2) Hardware key: an enrolled number MUST present a valid
                    //    assertion from its own private key — no fallback. A
                    //    different account on the same phone cannot produce
                    //    that signature, so it is refused outright (phase 2.2).
                    //    Never-enrolled numbers on WebAuthn-incapable platforms
                    //    (plain HTTP) stay PIN-only, audit-logged.
                    $deviceBlocked = false;
                    $deviceBindMsg = '';
                    // Auto-bind after login: set only when a first login is
                    // allowed through WITHOUT a completed key ceremony (the
                    // passkey prompt was skipped or failed). The portal then
                    // enrolls this device via login.php?bind=complete.
                    $pendingAutoBind = false;
                    // Non-empty => the page opens a popup (Bootstrap modal)
                    // carrying this title: the login was refused because the
                    // number or the device is ALREADY BOUND to a security key.
                    // Empty keeps the plain inline banner (transient errors
                    // like an expired ceremony are not "already bound").
                    $deviceBlockTitle = '';
                    $pdo = null;
                    try {
                        $pdo = new PDO('sqlite:' . HGS_MAIN_DB);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $pdo->exec("PRAGMA busy_timeout = 5000");
                        hgs_ensure_key_tables($pdo);

                        $pinGate = hgs_check_setup_pin($pdo, $student_number, $device_pin);
                        $pinSet = ($pinGate[0] === 'unissued')
                            ? hgs_set_setup_pin($pdo, $student_number, $device_pin, $device_pin_confirm)
                            : ['skip', ''];
                        $pinPassed = ($pinGate[0] === 'ok' || $pinGate[0] === 'none' || $pinSet[0] === 'ok');
                        // True only when THIS request's set-mode INSERT won —
                        // used below to roll the row back if the login itself
                        // ends up blocked.
                        $pinRowCreatedHere = ($pinGate[0] === 'unissued' && $pinSet[0] === 'ok');
// Verify device token binding for PIN
$deviceTokenCookie = $_COOKIE['device_token'] ?? '';
if ($pinPassed) {
    $stTok = $pdo->prepare("SELECT device_token FROM student_bind_pins WHERE student_number = :sn LIMIT 1");
    $stTok->execute([':sn' => $student_number]);
    $tokRow = $stTok->fetch(PDO::FETCH_ASSOC);
    if ($tokRow && $tokRow['device_token'] && $deviceTokenCookie && $tokRow['device_token'] !== $deviceTokenCookie) {
        $deviceBlocked = true;
        $deviceBindMsg = 'This student number is bound to a different device. Use the original device or reset.';
    }
}

                        // Attached WebAuthn ceremony result (the page runs
                        // register on keyless numbers, authenticate otherwise).
                        $keyPayload = null;
                        if (isset($_POST['webauthn_result']) && trim((string)$_POST['webauthn_result']) !== '') {
                            $kd = json_decode((string)$_POST['webauthn_result'], true);
                            if (is_array($kd)) $keyPayload = $kd;
                        }

                        $keyRow = hgs_key_get_active($pdo, $student_number);

                        if ($pinPassed && !$keyRow && !$deviceBlocked) {
                            // No enrolled key. Auto-bind policy: the PIN decides
                            // this first login; a capable device finishes binding
                            // right AFTER it (students/index.php re-runs the
                            // ceremony, login.php?bind=complete enrolls), so a
                            // dismissed or failed passkey prompt no longer blocks
                            // — it only defers. Cases:
                            // (a) the page ran a registration ceremony: verify
                            //     the attestation and bind immediately when it
                            //     succeeds;
                            // (b) incapable platform (plain HTTP): PIN-only
                            //     legacy login, audit-logged.
                            // The only refusal left is a RACE — the number was
                            // bound to another device mid-attempt (strict
                            // 1-device: that key must authenticate instead).
                            $bindDeferred = false;
                            if (is_array($keyPayload) && ($keyPayload['mode'] ?? '') === 'register') {
                                $sesReg = $_SESSION['webauthn_challenge'] ?? null;
                                unset($_SESSION['webauthn_challenge']);
                                $chReg = null;
                                if (is_array($sesReg) && ($sesReg['mode'] ?? '') === 'register' && ($sesReg['sn'] ?? '') === $student_number && ($sesReg['exp'] ?? 0) >= time()) {
                                    $chReg = base64_decode((string)($sesReg['ch'] ?? ''), true);
                                }
                                if (!is_string($chReg) || $chReg === '') {
                                    // Expired ceremony: allow the login and let the
                                    // portal re-run enrollment with a fresh
                                    // challenge instead of refusing it.
                                    $bindDeferred = true;
                                    hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'bind_deferred', 'Registration challenge missing or expired — login allowed, device bind deferred to portal');
                                } else {
                                    list($newId, $newPem) = hgs_webauthn_register($keyPayload, $chReg, hgs_webauthn_rp(), hgs_webauthn_origin());
                                    if ($newId === null) {
                                        // The ceremony itself failed (screen lock
                                        // off, platform refused): allow the login
                                        // and retry the bind from the portal.
                                        $bindDeferred = true;
                                        hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'bind_deferred', 'Registration failed (' . (string)$newPem . ') — login allowed, device bind deferred to portal');
                                    } else {
                                        list($regOk, $regMsg) = hgs_key_register($pdo, $student_number, $student['name'] ?? '', $newId, $newPem);
                                        if (!$regOk) {
                                            $deviceBlocked = true;
                                            $deviceBlockTitle = 'This Device Is Already Bound';
                                            $deviceBindMsg = htmlspecialchars($regMsg) . ' If this is your own phone and key, contact the administrator.';
                                            hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'blocked_race', 'Key registration conflict: ' . $regMsg);
                                        } else {
                                            hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'key_registered', 'Hardware security key enrolled for ' . $student_number . ' — device bound 1:1.');
                                        }
                                    }
                                }
                            } elseif ($capable) {
                                // Prompt dismissed or ceremony fetch failed on a
                                // capable device: auto-bind policy lets the PIN
                                // login through and finishes the enrollment right
                                // after it in the portal (one-tap fallback there).
                                $bindDeferred = true;
                                hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'bind_deferred', 'Capable platform logged in without a ceremony — device bind deferred to portal');
                            } else {
                                // Plain HTTP / old browser: PIN-only legacy
                                // login. No device lock is technically possible
                                // here — moving this deployment to HTTPS makes
                                // the 1-device rule universal.
                                hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'legacy_pin_login', 'PIN-only login (platform cannot do security keys)');
                            }
                            if ($bindDeferred && !$deviceBlocked) $pendingAutoBind = true;
                        } elseif (!$pinPassed) {
                            // PIN setup / verification failed — nothing else
                            // ran, so no key was enrolled and no state changed
                            // (except fail counters and the just-created row,
                            // rolled back below).
                            // 'missing' is a prompt, not an attack: count no
                            // fail and write no log row for it.
                            $deviceBlocked = true;
                            if ($pinGate[0] === 'unissued') {
                                // Self-service setup failed (no row existed).
                                if ($pinSet[0] === 'taken') {
                                    $deviceBindMsg = $pinSet[1];
                                    hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'blocked_race', 'PIN row just taken by another session on first login');
                                } else {
                                    $deviceBindMsg = ($pinSet[1] !== '') ? $pinSet[1] : 'Choose a 6-digit login PIN and type it in both PIN boxes — it becomes yours on first login.';
                                }
                            } else {
                                $deviceBindMsg = ($pinGate[1] !== '') ? $pinGate[1] : 'A login PIN is required for this student number. Enter the 6-digit PIN you chose.';
                                if ($pinGate[0] === 'bad' || $pinGate[0] === 'locked') {
                                    hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'blocked_bad_pin', json_encode([
                                        'reason' => $pinGate[0] === 'locked' ? 'PIN locked after repeated failures' : 'wrong PIN',
                                    ], JSON_UNESCAPED_SLASHES));
                                }
                            }
                        } elseif ($keyRow) {
                            // Enrolled number: a hardware assertion from THIS
                            // number's own private key is MANDATORY — there is
                            // deliberately no fallback. Any browser that
                            // cannot produce that signature is refused here,
                            // which is exactly what locks one number to one
                            // device: a second account on the same phone has
                            // no access to this private key (phase 2.2).
                            if (!is_array($keyPayload) || ($keyPayload['mode'] ?? '') !== 'authenticate') {
                                $deviceBlocked = true;
                                $deviceBlockTitle = 'Student Number Already Bound';
                                $deviceBindMsg = 'This student number is locked to its registered security key. Open this page on the phone or tablet where you enrolled, complete the security-key check, and try again. Each number works only on its own bound device — a different account on this phone cannot use it.';
                                hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'blocked_key_required', 'Enrolled number attempted login without a hardware assertion');
                            } else {
                                $sesKey = $_SESSION['webauthn_challenge'] ?? null;
                                unset($_SESSION['webauthn_challenge']);
                                $chRaw = null;
                                if (is_array($sesKey) && ($sesKey['mode'] ?? '') === 'authenticate' && ($sesKey['sn'] ?? '') === $student_number && ($sesKey['exp'] ?? 0) >= time()) {
                                    $chRaw = base64_decode((string)($sesKey['ch'] ?? ''), true);
                                }
                                if (!is_string($chRaw) || $chRaw === '') {
                                    $deviceBlocked = true;
                                    $deviceBindMsg = 'Security-key session expired. Reload the login page and try again.';
                                    hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'blocked_key_mismatch', 'Key challenge missing or expired');
                                } else {
                                    list($kOk, $kMsg) = hgs_webauthn_authenticate($pdo, $keyRow, $keyPayload, $chRaw, hgs_webauthn_rp(), hgs_webauthn_origin());
                                    if ($kOk) {
                                        hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'success', 'Hardware-key login — signature verified');
                                    } else {
                                        $deviceBlocked = true;
                                        $deviceBlockTitle = 'Student Number Already Bound';
                                        $deviceBindMsg = 'Device key mismatch. This number is locked to its registered security key — use the enrolled phone' . ($kMsg !== '' ? ' (' . htmlspecialchars($kMsg) . ')' : '') . ' or reset the device (Lost device? / administrator).';
                                        hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'blocked_key_mismatch', $kMsg);
                                    }
                                }
                            }
                        }
                        // Roll back a PIN row THIS attempt just created when the
                        // login itself ended blocked: PIN setup completes only
                        // together with a successful login. Without this, a
                        // failed attempt would leave an orphan row behind —
                        // and a stranger's failing attempt could squat the
                        // number into verify-mode with a PIN only they know.
                        // Never touches a row won by a concurrent session
                        // ($pinRowCreatedHere requires OUR insert).
                        if ($deviceBlocked && $pinRowCreatedHere) {
                            try { $pdo->prepare("DELETE FROM student_bind_pins WHERE student_number = :sn")->execute([':sn' => $student_number]); } catch (Exception $e2) {}
                        }
                    } catch (Exception $e) {
                        error_log('Device binding error: ' . $e->getMessage());
                        // Fail open? For strong security we fail closed but log.
                        // If DB error, allow login but log error so admin can audit — do not block student on infra failure.
                        if ($pdo) {
                            try { hgs_log_device($pdo, $student_number, '', $device_model, $ip, $ua, 'error', $e->getMessage()); } catch (Exception $ee) {}
                        }
                    }

                    if ($deviceBlocked) {
                        $error_message = $deviceBindMsg ?: 'Device not recognized. This student number is locked to another device. Please use the original device or contact the administrator.';
                    } else {
                        $_SESSION['student_logged_in'] = true;
                        $_SESSION['student_number'] = $student['student_number'];
                        $_SESSION['student_id'] = $student['student_id'];
                        $_SESSION['student_name'] = $student['name'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['found_in_db'] = $found_in_db;
                        $_SESSION['device_model'] = $device_model;
                        $_SESSION['key_enrolled'] = !empty($keyRow);
                        // Auto-bind after login: this first login went through
                        // without a completed key ceremony (prompt skipped or
                        // failed). The portal enrolls this device via
                        // login.php?bind=complete; a login that binds for real
                        // (or a number already bound) clears the flag.
                        if (!empty($pendingAutoBind) && empty($keyRow)) {
                            $_SESSION['pending_key_enroll'] = $student_number;
                        } else {
                            unset($_SESSION['pending_key_enroll']);
                        }
                        $_SESSION['success_message'] = 'Login successful! Welcome, ' . htmlspecialchars($student['name']) . '!';
                        // Persist bound student number on this device for future logins
                        setcookie('device_bound_student', $student['student_number'], 0, $studentCookiePath, '', false, true);
                        // Native app (Flutter APK, UA "HORIZON-Student-App"): it
                        // keeps cookies itself but cannot carry them across a
                        // redirect, so the 302 below would strand its session
                        // and every app login would fail. Answer the app's POST
                        // directly with JSON — the session cookie rides this
                        // 200 response and the app's session probe then passes.
                        // Browsers are unaffected (they never send that UA).
                        if (stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'HORIZON-Student-App') !== false) {
                            while (ob_get_level() > 0) { @ob_end_clean(); }
                            header('Content-Type: application/json');
                            echo json_encode([
                                'status' => 'success',
                                'student_number' => $student['student_number'],
                                'name' => $student['name'] ?? '',
                            ]);
                            exit();
                        }
                        header('Location: index.php');
                        exit();
                    }
                } else {
                    // Student not found — log it
                    try {
                        $pdoNF = new PDO('sqlite:' . HGS_MAIN_DB);
                        $pdoNF->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        hgs_log_device($pdoNF, $student_number, '', $device_model, $ip, $ua, 'not_found', 'Student number not in any database');
                    } catch (Exception $e) {}
                    $error_message = 'Student not found or Incorrect Student Number!. Please check your student number and try again.';
                }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login — HORIZON | ESSUC</title>
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <meta name="theme-color" content="#4318FF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="HORIZON">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
<link href="../css/horizon-theme.css" rel="stylesheet">
<link href="../Font-Awesome-master/css/all.min.css" rel="stylesheet">
<style>
/* ESSU brand mark: the square university seal icon (the only logo used on this
   page — the wide JPG wordmark lives in the portal navbar instead). */
.hz-auth-brand-logo{width:46px;height:46px;display:block;background:#fff;border:1px solid var(--horizon-card-border);border-radius:13px;padding:5px;box-shadow:0 4px 14px rgba(15,23,42,.08);flex-shrink:0;object-fit:cover}
@media (max-width:480px){.hz-auth-brand-logo{width:40px;height:40px;padding:4px}}
.horizon-login-extra .horizon-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--horizon-brand-50);color:var(--horizon-brand-500);border:1px solid #E9E3FF;font-size:.9rem;pointer-events:none}
.horizon-input-wrap{position:relative}
.horizon-input-wrap .form-control{padding-left:52px !important;height:48px}
@media (max-width:767.98px){.horizon-login-extra{min-height:100vh}}
.device-badge{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:6px 10px;border-radius:999px;display:inline-flex;align-items:center;gap:6px}
.device-badge.ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46}
.device-badge.wait{background:#FEF3C7;border:1px solid #FDE68A;color:#92400E}
.device-badge.fail{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B}
</style>
</head>
<body>
<div class="horizon-auth-container horizon-login-extra">
  <div class="horizon-auth-form-side">
    <div style="width:100%;max-width:420px;margin:0 auto">
      <div class="d-flex align-items-center gap-2 mb-3">
        <img src="../uploads/Eastern_Samar_State_University_logo.ico" alt="Eastern Samar State University" class="hz-auth-brand-logo" onerror="this.style.display='none'">
        <div style="min-width:0"><div style="font-weight:800;color:var(--horizon-text-title);line-height:1.1">ESSUC Grading System</div><small style="color:var(--horizon-text-muted);font-weight:700;letter-spacing:.08em;text-transform:uppercase;font-size:10px">Can-Avid Campus</small></div>
      </div>
      <h2 style="font-weight:800;color:var(--horizon-text-title);margin:0 0 6px;font-size:22px">Welcome back, Student</h2>
      <p style="color:var(--horizon-text-muted);font-size:13px;margin:0 0 18px;font-weight:500">Enter your student number to access your HORIZON portal.</p>
      <?php if (!empty($error_message)): ?>
        <div id="loginFlashBanner" class="horizon-flash-banner horizon-flash-danger" role="alert" style="margin-bottom:16px"><i class="fas fa-exclamation-circle"></i><span style="font-weight:700;font-size:13px"><?php echo $error_message; // contains <strong> on device blocks, intentionally not escaped ?></span></div>
      <?php endif; ?>
      <div id="deviceStatus" class="device-badge ok mb-3" style="width:100%;justify-content:center"><i class="fas fa-shield-alt"></i><span id="deviceStatusText">PIN + security-key protected</span><span id="deviceModelHint" style="opacity:.7;font-weight:600"></span></div>
      <form method="POST" action="login.php" novalidate id="studentLoginForm">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="webauthn_result" id="webauthn_result" value="">
        <input type="hidden" name="webauthn_capable" id="webauthn_capable" value="">
        <div class="mb-3">
          <label for="student_number" class="form-label">Student Number</label>
          <div class="horizon-input-wrap">
            <span class="horizon-input-icon"><i class="fas fa-id-card"></i></span>
            <input type="text" class="form-control" id="student_number" name="student_number" placeholder="XX-XXXXX" maxlength="8" pattern="^\d{2}-\d{5}$" inputmode="numeric" required autocomplete="off" value="<?php echo isset($_POST['student_number']) ? htmlspecialchars($_POST['student_number']) : ''; ?>">
          </div>
          <div class="form-text" style="color:var(--horizon-text-muted);font-size:11px;font-weight:600"><i class="fas fa-circle-info me-1"></i>Format <strong>XX-XXXXX</strong> (e.g., 25-12345) — dash auto-added</div>
        </div>
        <div class="mb-3">
          <label for="device_pin" class="form-label">Login PIN <small style="color:var(--horizon-text-muted);font-weight:600">(required)</small></label>
          <div class="horizon-input-wrap">
            <span class="horizon-input-icon"><i class="fas fa-key"></i></span>
            <input type="text" class="form-control" id="device_pin" name="device_pin" placeholder="6-digit PIN" maxlength="6" inputmode="numeric" pattern="^\d{6}$" autocomplete="off" required>
          </div>
          <div class="form-text" id="pinHelp" style="color:var(--horizon-text-muted);font-size:11px;font-weight:600"><i class="fas fa-circle-info me-1"></i><span id="pinHelpText">Required on every login. First time? Make up your own 6-digit PIN and type it in both boxes &mdash; it becomes yours.</span></div>
        </div>
        <div class="mb-3" id="pinConfirmBlock">
          <label for="device_pin_confirm" class="form-label">Confirm PIN <small style="color:var(--horizon-text-muted);font-weight:600">(first binding / after reset only)</small></label>
          <div class="horizon-input-wrap">
            <span class="horizon-input-icon"><i class="fas fa-key"></i></span>
            <input type="text" class="form-control" id="device_pin_confirm" name="device_pin_confirm" placeholder="Repeat the new 6-digit PIN" maxlength="6" inputmode="numeric" pattern="^\d{6}$" autocomplete="off">
          </div>
          <div class="form-text" id="pinConfirmHelp" style="color:var(--horizon-text-muted);font-size:11px;font-weight:600"><i class="fas fa-circle-info me-1"></i>Only needed when creating your PIN. Returning students leave this blank.</div>
        </div>
        <button type="submit" class="btn btn-gradient w-100" id="loginBtn" style="height:46px;border-radius:var(--horizon-radius-md);font-weight:800"><i class="fas fa-right-to-bracket me-2" id="loginBtnSpin"></i><span id="loginBtnLabel">Login to HORIZON Portal</span></button>
        <div id="deviceHelp" class="form-text mt-2" style="font-size:11px;color:var(--horizon-text-muted);font-weight:600"><i class="fas fa-shield-alt me-1"></i>First login on this phone enrolls its built-in security key for your number (strict 1 device per student). Enter your 6-digit login PIN above on every login — first-timers pick their own.</div>
        <details class="mt-3" style="font-size:12px">
          <summary style="cursor:pointer;font-weight:700;color:var(--horizon-brand-500)">Lost your device?</summary>
          <div class="mt-2 d-flex flex-column gap-2">
            <small class="text-muted" style="font-weight:600">Step 1 — email yourself a one-time reset code (check your registered email, spam folder included).</small>
            <div class="d-flex gap-2">
              <input type="text" id="resetSn" class="form-control form-control-sm" placeholder="XX-XXXXX" maxlength="8" autocomplete="off" style="max-width:140px">
              <button type="button" id="resetReqBtn" class="btn btn-sm btn-outline-primary rounded-pill px-3">Email code</button>
            </div>
            <small class="text-muted" style="font-weight:600">Step 2 — enter the code to revoke the old device, then log in above to enroll this one.</small>
            <div class="d-flex gap-2">
              <input type="text" id="resetCode" class="form-control form-control-sm" placeholder="6-digit code" maxlength="6" inputmode="numeric" autocomplete="off" style="max-width:140px">
              <button type="button" id="resetVerifyBtn" class="btn btn-sm btn-primary rounded-pill px-3">Revoke old device</button>
            </div>
            <div id="resetMsg" style="font-size:12px;font-weight:600"></div>
          </div>
        </details>
        <div class="d-flex align-items-center gap-2 my-3"><div style="height:1px;flex:1;background:var(--horizon-divider)"></div><span style="font-size:11px;font-weight:700;color:var(--horizon-text-muted)">or</span><div style="height:1px;flex:1;background:var(--horizon-divider)"></div></div>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <a href="../landingpage.php" class="btn btn-secondary btn-sm"><i class="fas fa-user-plus me-1"></i>Enroll Now</a>
          <span style="font-size:11px;color:var(--horizon-text-muted);font-weight:600"><i class="fas fa-lock me-1"></i>Private &amp; secure</span>
        </div>
      </form>
      <div class="text-center mt-4" style="color:var(--horizon-text-muted);font-size:11px;font-weight:600">Need help? Contact your adviser or registrar.<br>Only <strong style="color:var(--horizon-text-title)">published</strong> grades are shown.<div class="mt-2" style="font-size:10px;opacity:.8">© <?php echo date('Y'); ?> ESSUC • HORIZON UI</div></div>
    </div>
  </div>
  <div class="horizon-auth-hero-side" style="flex-direction:column;text-align:left;align-items:flex-start">
    <div style="max-width:420px;width:100%">
      <div style="font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.82)"><i class="fas fa-sparkles me-1"></i>HORIZON Student Experience</div>
      <h1 style="font-size:28px;font-weight:800;line-height:1.15;color:#fff;margin:8px 0 10px">Your grades,<br>in beautiful focus.</h1>
      <p style="color:rgba(255,255,255,.88);font-size:13px;line-height:1.6">HORIZON brings clarity — published grades, assessments, and class insights in a clean, responsive workspace built for ESSUC.</p>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <span class="badge" style="background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);color:#fff"><i class="fas fa-shield-alt me-1"></i>Device-locked login</span>
        <span class="badge" style="background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);color:#fff"><i class="fas fa-chart-simple me-1"></i>Real-time results</span>
        <span class="badge" style="background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);color:#fff"><i class="fas fa-mobile-screen me-1"></i>One device per student</span>
      </div>
      <div class="horizon-card mt-4" style="border-radius:16px;padding:14px;background:rgba(255,255,255,.94);border:1px solid rgba(255,255,255,.6)">
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="horizon-widget-icon-box brand" style="width:42px;height:42px;min-width:42px;font-size:15px"><i class="fas fa-fingerprint"></i></span>
          <div><div style="font-weight:800;color:var(--horizon-text-title);font-size:13px;line-height:1">Strong Device Recognition</div><small style="color:var(--horizon-text-muted);font-weight:700;font-size:11px">First login binds • <span class="badge badge-success">fingerprint</span> <span class="badge badge-brand">build</span></small></div>
          <span class="ms-auto badge badge-brand">HORIZON</span>
        </div>
        <div class="d-flex gap-2 flex-wrap"><span class="badge badge-success">Canvas + WebGL</span><span class="badge badge-secondary">Screen & TZ</span><span class="badge badge-warning">Locked to device</span></div>
        <div style="font-size:11px;color:var(--horizon-text-muted);font-weight:600;margin-top:8px;line-height:1.4"><i class="fas fa-circle-info me-1"></i>Your student number will be permanently linked to this device on first login — any browser on this same device works. Only this device can then use that number. Contact your administrator to reset.</div>
      </div>
      <div class="mt-3 d-flex align-items-center gap-2" style="color:rgba(255,255,255,.88);font-size:11px;font-weight:700"><span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:28px;height:28px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.2)"><i class="fas fa-building-columns"></i></span>Eastern Samar State University — Can-Avid Campus</div>
    </div>
  </div>
</div>
<script src="../js/bootstrap.bundle.min.js"></script>
<?php if (!empty($deviceBlockTitle)): ?>
<!-- "Device is already bound" popup: opened automatically when the login was
     refused because the number or this device is already bound to a security
     key. The inline banner above carries the same server-rendered text and
     stays as the no-JS fallback; while the popup is open it is hidden so the
     message is never shown twice. -->
<div class="modal fade" id="deviceBindModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;border:0;box-shadow:0 18px 50px rgba(15,23,42,.25)">
      <div class="modal-header" style="background:#FEF2F2;border-bottom:1px solid #FECACA">
        <h6 class="modal-title fw-bold" style="color:#991B1B;font-size:15px"><i class="fas fa-mobile-alt me-2"></i><?php echo htmlspecialchars($deviceBlockTitle); ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger mb-3" style="width:56px;height:56px"><i class="fas fa-ban fa-lg"></i></div>
        <p style="font-size:13.5px;font-weight:600;color:var(--horizon-text-title);margin:0 0 10px"><?php echo $deviceBindMsg; // same intentionally-unescaped text as the banner above ?></p>
        <small class="text-muted" style="font-weight:600">Open this page on the phone or tablet where you enrolled — or use the <strong>Lost your device?</strong> reset below to free your number by email.</small>
      </div>
      <div class="modal-footer border-0 justify-content-center">
        <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
// Auto-open the binding popup; hide the duplicate inline banner while it is
// visible (restored on close, and shown immediately if Bootstrap is missing).
(function(){
  var modalEl = document.getElementById('deviceBindModal');
  if (!modalEl || !window.bootstrap) return;
  var banner = document.getElementById('loginFlashBanner');
  if (banner) banner.style.display = 'none';
  modalEl.addEventListener('hidden.bs.modal', function(){ if (banner) banner.style.display = ''; });
  try { bootstrap.Modal.getOrCreateInstance(modalEl).show(); } catch(e){ if (banner) banner.style.display = ''; }
})();
</script>
<?php endif; ?>
<script>
const el=document.getElementById('student_number');
if(el){
  el.addEventListener('input',function(e){
    let v=e.target.value,pos=e.target.selectionStart,prev=e.target.getAttribute('data-prev')||'';
    let isDeleting=v.length<prev.length;
    let clean=v.replace(/[^\d-]/g,'');
    if(!isDeleting){let d=clean.replace(/-/g,'');clean=d.length>=2?d.substring(0,2)+'-'+d.substring(2,7):d;}
    if(e.target.value!==clean){e.target.value=clean;requestAnimationFrame(()=>{let p=isDeleting?Math.min(pos,clean.length):clean.length;try{e.target.setSelectionRange(p,p);}catch(_){}});}
    e.target.setAttribute('data-prev',clean);
  });
  el.addEventListener('paste',function(e){e.preventDefault();let t=(e.clipboardData||window.clipboardData).getData('text').replace(/[^\d]/g,'');if(t.length>=2)t=t.substring(0,2)+'-'+t.substring(2,7);e.target.value=t;requestAnimationFrame(()=>{try{e.target.setSelectionRange(t.length,t.length);}catch(_){}});e.target.setAttribute('data-prev',t);});
  el.addEventListener('focus',function(){const a=document.querySelector('.horizon-flash-danger');if(a)a.style.display='none';});
}

// ===== Confirm-PIN box appears only when the number has NO PIN yet =====
// The box exists solely to confirm a PIN that is being CREATED. Once a number
// carries a PIN, the student is returning and must not be asked to confirm
// anything — so the whole block is hidden and the wording above it switches to
// "enter the PIN you chose".
//
// Deliberately cosmetic: the POST still carries device_pin_confirm and the
// server decides on its own (hgs_check_setup_pin -> 'unissued'). A stale or
// missing answer can never weaken that, so every failure path here falls back
// to SHOWING the box — the state that still works for a first-time student.
(function(){
  var snEl = document.getElementById('student_number');
  var block = document.getElementById('pinConfirmBlock');
  var confirmEl = document.getElementById('device_pin_confirm');
  var helpEl = document.getElementById('pinHelpText');
  var confirmHelpEl = document.getElementById('pinConfirmHelp');
  if (!snEl || !block || !confirmEl) return;

  var NEW_HELP = 'Required on every login. First time? Make up your own 6-digit PIN and type it in both boxes — it becomes yours.';
  var RETURNING_HELP = 'Enter the 6-digit PIN you chose on first login. This number already has a PIN set, so there is nothing to confirm.';

  // Guard against out-of-order responses when the number is edited quickly.
  var seq = 0, last = '', timer = null;

  function showConfirm() {
    block.hidden = false;
    block.style.display = '';
    if (helpEl) helpEl.textContent = NEW_HELP;
    if (confirmHelpEl) confirmHelpEl.innerHTML = '<i class="fas fa-circle-info me-1"></i>Only needed when creating your PIN. Type the same 6 digits again.';
  }
  function hideConfirm() {
    block.hidden = true;
    block.style.display = 'none';
    if (helpEl) helpEl.textContent = RETURNING_HELP;
    // Drop any half-typed confirmation: a stale value can only confuse.
    if (confirmEl) confirmEl.value = '';
  }
  // Unknown / not yet a full number / request failed -> leave the field alone.
  function neutral() {
    block.hidden = false;
    block.style.display = '';
    if (helpEl) helpEl.textContent = NEW_HELP;
  }

  function check() {
    var sn = String(snEl.value || '').trim();
    if (!/^\d{2}-\d{5}$/.test(sn)) { neutral(); return; }
    if (sn === last) return;
    last = sn;
    var mine = ++seq;
    fetch('login.php?pin=status&student_number=' + encodeURIComponent(sn), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (mine !== seq) return;                 // a newer keystroke won
        if (j && j.status === 'success' && j.has_pin) hideConfirm();
        else showConfirm();
      })
      .catch(function() { if (mine === seq) showConfirm(); });  // fail open to setup mode
  }

  function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(check, 220);
  }
  snEl.addEventListener('input', schedule);
  snEl.addEventListener('change', schedule);
  snEl.addEventListener('blur', check);
  if (/^\d{2}-\d{5}$/.test(String(snEl.value || '').trim())) check();  // re-render after a failed POST
})();
</script>
<script>
// ===== Hardware security key (WebAuthn) — register on keyless numbers,
// authenticate on enrolled ones, before the normal POST. Needs a secure
// context (HTTPS/localhost); on plain HTTP the ceremony is skipped and the
// server falls back to PIN-only (no device lock possible there). The server
// decides what a missing ceremony means.
(function(){
  var form = document.getElementById('studentLoginForm');
  var res = document.getElementById('webauthn_result');
  if (!form || !res) return;
  var capable = false;
  try { capable = !!window.isSecureContext && !!window.PublicKeyCredential && !!navigator.credentials; } catch (_) { capable = false; }
  var capEl = document.getElementById('webauthn_capable');
  if (capEl) capEl.value = capable ? '1' : '';
  function b64uDec(s){
    s = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var b = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
    return b;
  }
  function b64uEnc(buf){
    var b = new Uint8Array(buf);
    var s = '';
    for (var i = 0; i < b.length; i += 8192) s += String.fromCharCode.apply(null, b.subarray(i, i + 8192));
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function te(s){
    if (window.TextEncoder) return new TextEncoder().encode(s);
    var b = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i) & 0xff;
    return b;
  }
  async function doRegister(opt){
    var cred = await navigator.credentials.create({ publicKey: {
      challenge: b64uDec(opt.challenge),
      rp: { name: 'HORIZON ESSUC', id: opt.rpId },
      user: { id: te(opt.userHandle || opt.userName || 'student'), name: opt.userName || 'student', displayName: opt.userName || 'student' },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
      timeout: 60000, attestation: 'none',
      authenticatorSelection: { userVerification: 'preferred' }
    }});
    if (!cred) return null;
    return { mode: 'register', id: cred.id, rawId: b64uEnc(cred.rawId),
      response: { attestationObject: b64uEnc(cred.response.attestationObject), clientDataJSON: b64uEnc(cred.response.clientDataJSON) } };
  }
  async function doAuthenticate(opt){
    var cred = await navigator.credentials.get({ publicKey: {
      challenge: b64uDec(opt.challenge),
      rpId: opt.rpId,
      allowCredentials: [{ type: 'public-key', id: b64uDec(opt.credentialId) }],
      timeout: 60000, userVerification: 'preferred'
    }});
    if (!cred) return null;
    return { mode: 'authenticate', id: cred.id, rawId: b64uEnc(cred.rawId),
      response: { authenticatorData: b64uEnc(cred.response.authenticatorData), clientDataJSON: b64uEnc(cred.response.clientDataJSON), signature: b64uEnc(cred.response.signature) } };
  }
  form.addEventListener('submit', function(e){
    if (form.dataset.hgsKeyDone === '1') return; // second pass: proceed
    if (!capable) return; // plain HTTP / old browser: PIN-only submit, server decides
    e.preventDefault();
    (async function(){
      try {
        var snEl = document.getElementById('student_number');
        var sn = snEl ? snEl.value.trim() : '';
        var r = await fetch('login.php?webauthn=options&student_number=' + encodeURIComponent(sn), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        var opt = null;
        try { opt = await r.json(); } catch (_) { opt = null; }
        if (opt && opt.status === 'success' && opt.challenge && (opt.mode === 'register' || opt.mode === 'authenticate')){
          var out = (opt.mode === 'register') ? await doRegister(opt) : await doAuthenticate(opt);
          if (out) res.value = JSON.stringify(out);
        }
      } catch (err) { try { console.warn('device key skipped:', err); } catch (_) {} }
      form.dataset.hgsKeyDone = '1';
      form.submit(); // programmatic: bypasses listeners, no loop
    })();
  });
})(); // end ceremony IIFE
</script>
<script>
// ===== Lost-device email-OTP reset (phase 4.4, student self-service) =====
// Step 1 emails a one-time code to the registered address; step 2 consumes
// it and revokes the old hardware key. Only signatures ever cross the wire
// afterwards — the code itself is single-use and never logged.
(function(){
  function resetMsg(t, isErr){
    var box = document.getElementById('resetMsg');
    if (!box) return;
    box.textContent = t;
    box.style.color = isErr ? '#991b1b' : '#065f46';
  }
  function resetSn(){
    var el = document.getElementById('student_number');
    var alt = document.getElementById('resetSn');
    var v = el ? String(el.value || '').trim() : '';
    if (!/^\d{2}-\d{5}$/.test(v) && alt) v = String(alt.value || '').trim();
    return v;
  }
  var reqBtn = document.getElementById('resetReqBtn');
  if (reqBtn) reqBtn.addEventListener('click', function(){
    var sn = resetSn();
    if (!/^\d{2}-\d{5}$/.test(sn)) { resetMsg('Type your student number first (XX-XXXXX).', true); return; }
    reqBtn.disabled = true;
    fetch('login.php?reset=request', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: 'student_number=' + encodeURIComponent(sn) })
      .then(function(r){ return r.json(); })
      .then(function(j){ resetMsg((j && j.msg) || 'Request sent.', !(j && j.status === 'success')); reqBtn.disabled = false; })
      .catch(function(){ resetMsg('Network error — please try again.', true); reqBtn.disabled = false; });
  });
  var verBtn = document.getElementById('resetVerifyBtn');
  if (verBtn) verBtn.addEventListener('click', function(){
    var sn = resetSn();
    var codeEl = document.getElementById('resetCode');
    var code = codeEl ? String(codeEl.value || '').trim() : '';
    if (!/^\d{2}-\d{5}$/.test(sn)) { resetMsg('Type your student number first (XX-XXXXX).', true); return; }
    if (!/^\d{6}$/.test(code)) { resetMsg('Type the 6-digit code from the email.', true); return; }
    verBtn.disabled = true;
    fetch('login.php?reset=verify', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: 'student_number=' + encodeURIComponent(sn) + '&code=' + encodeURIComponent(code) })
      .then(function(r){ return r.json(); })
      .then(function(j){
        resetMsg((j && j.msg) || 'Done.', !(j && j.status === 'success'));
        verBtn.disabled = false;
        if (j && j.status === 'success' && codeEl) codeEl.value = '';
      })
      .catch(function(){ resetMsg('Network error — please try again.', true); verBtn.disabled = false; });
  });
})();
</script>
<script>
// One-time cleanup: the offline service worker (sw.js) was retired and deleted
// from the server, but browsers that installed it keep running the stale copy,
// which rejects every fetch (e.g. favicon.ico) with "Failed to convert value
// to 'Response'". Force-unregister it and drop its caches. Safe to delete this
// block once every device has loaded any page at least once.
(function(){
  if (!('serviceWorker' in navigator)) return;
  try {
    navigator.serviceWorker.getRegistrations().then(function(regs){
      regs.forEach(function(r){ try { r.unregister(); } catch(e){} });
    }).catch(function(){});
    if (window.caches && caches.keys) {
      caches.keys().then(function(keys){
        keys.forEach(function(k){
          if (/horizon/i.test(k)) { try { caches.delete(k); } catch(e){} }
        });
      }).catch(function(){});
    }
  } catch(e){}
})();
</script>
<script>
// Device binding enforcement: ensure the same device is used only for the bound student number
(function(){
  var boundMatch = document.cookie.match(/(?:^|; )device_bound_student=([^;]*)/);
  var bound = boundMatch ? decodeURIComponent(boundMatch[1]) : null;
  var form = document.getElementById('studentLoginForm');
  var snInput = document.getElementById('student_number');
  if (form && bound) {
    form.addEventListener('submit', function(e){
      var entered = snInput ? snInput.value.trim() : '';
      if (bound && entered && entered !== bound) {
        e.preventDefault();
        alert('This device is bound to student number '+bound+'. Use that number or log out first.');
        return false;
      }
    });
  }
})();
</script>
</body>
</html>
