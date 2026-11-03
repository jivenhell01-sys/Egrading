<?php
// students/device_keys.php — strict 1-device-per-student subsystem.
//
// Model: one ACTIVE hardware keypair per student number. The private key
// never leaves the phone (Android Keystore / iOS Secure Enclave / platform
// authenticator via WebAuthn); the server keeps (credential_id, public_key)
// and verifies signatures over single-use challenges. A second account on
// the same phone cannot log in: without THAT number's private key no valid
// assertion can be produced, so the login is refused outright.
//
// Two PINs exist — do not confuse them:
//   * 6-digit login PIN (server hash, every login): knowledge factor, owned
//     by hgs_check_setup_pin() / hgs_set_setup_pin(). Required always.
//   * Device PIN / biometrics (never touches the server): protects the
//     hardware key locally, enforced by WebAuthn user-verification inside
//     the ceremony itself.
//
// HTTPS reality: WebAuthn needs a secure context, so plain-HTTP (LAN-IP)
// phones cannot enroll or assert. Capability-graded strictness applies:
//   * key enrolled  -> hardware signature MANDATORY, no fallback (strict);
//   * never enrolled -> PIN-only legacy login, audit-logged (HTTP phones).
// Deploying HTTPS is what makes the strict rule universal.
//
// Tables (MAIN database only):
//   student_device_keys  one row per number per key; partial UNIQUE index
//                        keeps at most ONE ACTIVE key per number, and a
//                        global UNIQUE on credential_id keeps one key bound
//                        to one number. Revoked rows stay for audit.
//   device_resets        single-use email-OTP rows authorizing a revocation.
//   student_device_logs  append-only audit trail (kept from the old system;
//                        old fingerprint-era statuses stay readable).
//   student_bind_pins    6-digit login PIN hashes (unchanged semantics).
// The retired fingerprint/token schema (student_device_bindings) is dropped
// here — its columns could not tell identical phones apart, which is why it
// is being replaced instead of extended.

if (!defined('HGS_MAIN_DB')) define('HGS_MAIN_DB', __DIR__ . '/../db/grading_db.db');

function hgs_get_client_ip() {
    $keys = ['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
            if ($v !== '') return $v;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function hgs_parse_device_build_fallback($ua) {
    $ua = (string)$ua;
    // Display label ONLY (audit readability) — never identity. The token
    // after "Build/"; generic fallbacks below it.
    if (preg_match('/Build\/([^\s;\)]+)/i', $ua, $m)) {
        $cand = trim($m[1]);
        if (preg_match('/[A-Za-z0-9]/', $cand) && strlen($cand) >= 2 && strlen($cand) <= 40) {
            return $cand;
        }
    }
    if (preg_match('/iPhone/i', $ua)) return 'iPhone';
    if (preg_match('/iPad/i', $ua)) return 'iPad';
    if (preg_match('/Android/i', $ua)) return 'Android Device';
    if (preg_match('/Windows NT/i', $ua)) return 'Windows PC';
    if (preg_match('/Macintosh|Mac OS X/i', $ua)) return 'Mac';
    if (preg_match('/CrOS/i', $ua)) return 'Chromebook';
    if (preg_match('/Linux/i', $ua)) return 'Linux PC';
    return 'Unknown Device';
}

function hgs_ensure_key_tables(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `student_device_keys` (
        `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        `student_number` TEXT NOT NULL,
        `student_name` TEXT,
        `credential_id` TEXT NOT NULL,
        `public_key` TEXT NOT NULL,
        `sign_count` INTEGER NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `is_active` INTEGER NOT NULL DEFAULT 1,
        `device_token` TEXT
    )");
    // One ACTIVE key per number (revoked rows are exempt, so re-enrollment
    // works), and one number per credential globally (a key cannot be shared
    // across accounts). Both are hard guarantees, not application checks.

    // Ensure the `device_token` column exists for legacy databases. SQLite does not support IF NOT EXISTS for ALTER, so we inspect the schema first.
    $cols = $pdo->query("PRAGMA table_info(`student_device_keys`)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('device_token', $cols)) {
        $pdo->exec("ALTER TABLE `student_device_keys` ADD COLUMN `device_token` TEXT");
    }

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sdk_credential ON `student_device_keys`(`credential_id`)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sdk_active_number ON `student_device_keys`(`student_number`) WHERE is_active = 1");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `device_resets` (
        `student_number` TEXT NOT NULL UNIQUE,
        `code_hash` TEXT NOT NULL,
        `expires_at` INTEGER NOT NULL,
        `attempts` INTEGER NOT NULL DEFAULT 0,
        `used_at` TIMESTAMP,
        `last_sent_at` INTEGER NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `created_by` TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `student_device_logs` (
        `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        `student_number` TEXT NOT NULL,
        `device_fingerprint` TEXT,
        `device_model` TEXT,
        `ip_address` TEXT,
        `user_agent` TEXT,
        `status` TEXT NOT NULL,
        `details` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sdl_student_number ON `student_device_logs`(`student_number`)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sdl_status ON `student_device_logs`(`status`)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sdl_created_at ON `student_device_logs`(`created_at`)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `student_bind_pins` (
        `student_number` TEXT NOT NULL UNIQUE,
        `pin_hash` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_issued_at` TIMESTAMP,
        `fails` INTEGER NOT NULL DEFAULT 0,
        `locked_until` INTEGER NOT NULL DEFAULT 0,
        `device_token` TEXT
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sbp_student_number ON `student_bind_pins`(`student_number`)");
    // Retired fingerprint/token schema: superseded by cryptographic keys.
    // Its signals could not distinguish identical phones, so keeping the
    // table would only invite dead reads. Audit history lives on in
    // student_device_logs, which is deliberately preserved.
    try { @$pdo->exec("DROP TABLE IF EXISTS `student_device_bindings`"); } catch (Exception $e) {}
}

function hgs_log_device(PDO $pdo, $student_number, $fp, $model, $ip, $ua, $status, $details = '') {
    try {
        hgs_ensure_key_tables($pdo);
        $stmt = $pdo->prepare("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES (:sn, :fp, :model, :ip, :ua, :status, :details)");
        $stmt->execute([
            ':sn' => (string)$student_number,
            ':fp' => substr((string)$fp, 0, 128),
            ':model' => substr((string)$model, 0, 120),
            ':ip' => substr((string)$ip, 0, 64),
            ':ua' => substr((string)$ua, 0, 500),
            ':status' => (string)$status,
            ':details' => substr((string)$details, 0, 2000),
        ]);
    } catch (Exception $e) {
        error_log('hgs_log_device: ' . $e->getMessage());
    }
}

// Setup PIN gate: proves the presenter owns the student number on EVERY
// login. The STUDENT chooses their own 6-digit PIN on first binding
// (confirmed in a second box); only its hash is stored, via
// hgs_set_setup_pin(). After that — and after every admin reset that
// cleared the row — the number cannot log in without it.
// Returns [code, message]; codes:
//   none     → infra failure only (fail open; never a real state)
//   ok       → correct PIN (fail counter reset)
//   missing  → PIN required but the field was left empty (no fail counted)
//   locked   → 5 wrong attempts: 15-minute lock (fail logged by caller)
//   bad      → wrong PIN, fail counted (fail logged by caller)
//   unissued → no PIN row for this number: the caller must offer PIN
//              creation (hgs_set_setup_pin), never a pass
function hgs_check_setup_pin(PDO $pdo, $sn, $pin) {
    $sn = trim((string)$sn);
    $pin = preg_replace('/\D/', '', (string)$pin);
    $pin = substr($pin, 0, 6);
    try {
        hgs_ensure_key_tables($pdo);
        $st = $pdo->prepare("SELECT pin_hash, fails, locked_until, device_token FROM student_bind_pins WHERE student_number = :sn LIMIT 1");
        $st->execute([':sn' => $sn]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['unissued', ''];
        $now = time();
        if ((int)($row['locked_until'] ?? 0) > $now) {
            return ['locked', 'Too many wrong PIN attempts — this student number is locked for 15 minutes. Ask the administrator to reset it, then try again.'];
        }
        if ($pin === '') {
            return ['missing', 'A 6-digit login PIN is required for this student number. First time? Type your chosen PIN in both PIN boxes.'];
        }
        $calc = hash('sha256', $sn . ':' . $pin);
        if (hash_equals((string)$row['pin_hash'], $calc)) {
            $pdo->prepare("UPDATE student_bind_pins SET fails = 0, locked_until = 0 WHERE student_number = :sn")->execute([':sn' => $sn]);
            return ['ok', ''];
        }
        $fails = (int)($row['fails'] ?? 0) + 1;
        if ($fails >= 5) {
            $pdo->prepare("UPDATE student_bind_pins SET fails = :f, locked_until = :l WHERE student_number = :sn")->execute([':f' => $fails, ':l' => ($now + 900), ':sn' => $sn]);
            return ['locked', 'Too many wrong PIN attempts — this student number is locked for 15 minutes. Ask the administrator to reset it, then try again.'];
        }
        $pdo->prepare("UPDATE student_bind_pins SET fails = :f WHERE student_number = :sn")->execute([':f' => $fails, ':sn' => $sn]);
        return ['bad', 'Incorrect PIN (' . $fails . ' of 5 attempts). Try again, or ask your teacher to reset it.'];
    } catch (Exception $e) { return ['none', '']; } // fail open on infra failure: never block a student on a DB error
}

// Student-chosen PIN creation (first binding, or after an admin reset
// cleared the row). The student picks the 6-digit PIN themselves — the
// teacher never issues one. Stores hash("number:pin") only.
// Returns [code, message]; codes:
//   ok       → row created, PIN is now set
//   invalid  → empty / non-6-digit PIN (no write)
//   mismatch → PIN and confirmation differ (no write)
//   taken    → another session created the row first (UNIQUE race); the
//              caller must treat this like a registration race, NOT as a
//              pass — the winner's (unknown) PIN now guards the number.
function hgs_set_setup_pin(PDO $pdo, $sn, $pin, $confirm) {
    $sn = trim((string)$sn);
    $pin = substr(preg_replace('/\D/', '', (string)$pin), 0, 6);
    $confirm = substr(preg_replace('/\D/', '', (string)$confirm), 0, 6);
    if ($pin === '' || !preg_match('/^\d{6}$/', $pin)) {
        return ['invalid', 'Choose a 6-digit login PIN (numbers only) and confirm it.'];
    }
    if ($pin !== $confirm) {
        return ['mismatch', 'The two PINs do not match. Type your chosen 6-digit PIN in both boxes.'];
    }
    try {
        hgs_ensure_key_tables($pdo);
        $token = $_COOKIE['device_token'] ?? bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO student_bind_pins (student_number, pin_hash, created_at, last_issued_at, fails, locked_until, device_token) VALUES (:sn, :h, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, 0, :tok)")
            ->execute([':sn' => $sn, ':h' => hash('sha256', $sn . ':' . $pin), ':tok' => $token]);
        return ['ok', ''];
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'UNIQUE') !== false) {
            return ['taken', 'This student number was just registered by another session. Please reload and try again, or contact the administrator.'];
        }
        return ['invalid', 'Could not save the PIN. Please reload and try again.'];
    } catch (Exception $e) {
        return ['invalid', 'Could not save the PIN. Please reload and try again.'];
    }
}

function hgs_b64u_encode($bin) { return rtrim(strtr(base64_encode((string)$bin), '+/', '-_'), '='); }
function hgs_b64u_decode($s) {
    $s = (string)$s;
    if (!preg_match('/^[A-Za-z0-9\-_]*$/', $s)) return null;
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    return ($out === false) ? null : $out;
}

// Minimal CBOR decoder — just enough for WebAuthn (uint/nint/bytes/text/
// array/map/tag-skip). Returns [value, nextOffset]; throws on truncation or
// unsupported items (indefinite lengths, floats, simple values).
function hgs_cbor_item($bin, $pos) {
    $n = strlen($bin);
    if ($pos >= $n) throw new Exception('cbor truncated');
    $ib = ord($bin[$pos]); $pos++;
    $major = $ib >> 5; $ai = $ib & 0x1F;
    if ($ai < 24) $arg = $ai;
    elseif ($ai === 24) { if ($pos + 1 > $n) throw new Exception('cbor truncated'); $arg = ord($bin[$pos]); $pos += 1; }
    elseif ($ai === 25) { if ($pos + 2 > $n) throw new Exception('cbor truncated'); $arg = (ord($bin[$pos]) << 8) | ord($bin[$pos + 1]); $pos += 2; }
    elseif ($ai === 26) { if ($pos + 4 > $n) throw new Exception('cbor truncated'); $u = unpack('N', substr($bin, $pos, 4)); $arg = $u[1]; $pos += 4; }
    elseif ($ai === 27) {
        if ($pos + 8 > $n) throw new Exception('cbor truncated');
        $hi = unpack('N', substr($bin, $pos, 4)); $lo = unpack('N', substr($bin, $pos + 4, 4));
        if ($hi[1] !== 0) throw new Exception('cbor length too large');
        $arg = $lo[1]; $pos += 8;
    } else throw new Exception('cbor indefinite unsupported');
    switch ($major) {
        case 0: return [$arg, $pos];
        case 1: return [-1 - $arg, $pos];
        case 2:
            if ($pos + $arg > $n) throw new Exception('cbor truncated');
            return [substr($bin, $pos, $arg), $pos + $arg];
        case 3:
            if ($pos + $arg > $n) throw new Exception('cbor truncated');
            return [substr($bin, $pos, $arg), $pos + $arg];
        case 4:
            $arr = [];
            for ($i = 0; $i < $arg; $i++) { list($v, $pos) = hgs_cbor_item($bin, $pos); $arr[] = $v; }
            return [$arr, $pos];
        case 5:
            $map = [];
            for ($i = 0; $i < $arg; $i++) { list($k, $pos) = hgs_cbor_item($bin, $pos); list($v, $pos) = hgs_cbor_item($bin, $pos); $map[$k] = $v; }
            return [$map, $pos];
        case 6: return hgs_cbor_item($bin, $pos); // tag: use enclosed item
        default: throw new Exception('cbor simple/float unsupported');
    }
}

// Uncompressed P-256 point (X/Y, 32 bytes each) → PEM public key.
function hgs_p256_pem($x, $y) {
    if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) return null;
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function hgs_webauthn_rp() {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '') $host = 'localhost';
    return $host;
}
function hgs_webauthn_origin() {
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $https = (strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

// Verify a WebAuthn registration ceremony. $challengeRaw = raw bytes issued
// via the options endpoint. Returns [credIdB64u, pem] or [null, errorMsg].
function hgs_webauthn_register($payload, $challengeRaw, $rpId, $origin) {
    try {
        if (!is_array($payload)) return [null, 'Bad key data.'];
        $resp = (isset($payload['response']) && is_array($payload['response'])) ? $payload['response'] : [];
        $attObj = hgs_b64u_decode($resp['attestationObject'] ?? '');
        $cliJson = hgs_b64u_decode($resp['clientDataJSON'] ?? '');
        if ($attObj === null || $cliJson === null) return [null, 'Bad key encoding.'];
        $cli = json_decode($cliJson, true);
        if (!is_array($cli) || ($cli['type'] ?? '') !== 'webauthn.create') return [null, 'Wrong ceremony.'];
        if (($cli['origin'] ?? '') !== $origin) return [null, 'Origin mismatch.'];
        $cch = hgs_b64u_decode($cli['challenge'] ?? '');
        if ($cch === null || !hash_equals((string)$challengeRaw, $cch)) return [null, 'Challenge mismatch.'];
        list($att, $off) = hgs_cbor_item($attObj, 0);
        if (!is_array($att) || ($att['fmt'] ?? '') !== 'none') return [null, 'Attestation not supported.'];
        if (!empty($att['attStmt'])) return [null, 'Attestation not supported.'];
        $auth = $att['authData'] ?? null;
        if (!is_string($auth) || strlen($auth) < 37 + 18) return [null, 'Bad authenticator data.'];
        if (substr($auth, 0, 32) !== hash('sha256', $rpId, true)) return [null, 'RP mismatch.'];
        $flags = ord($auth[32]);
        if (!($flags & 0x01)) return [null, 'User not present.'];
        if (!($flags & 0x40)) return [null, 'No key data.'];
        $credLen = (ord($auth[53]) << 8) | ord($auth[54]);
        if ($credLen <= 0 || $credLen > 1024 || strlen($auth) < 55 + $credLen) return [null, 'Bad credential id.'];
        $credId = substr($auth, 55, $credLen);
        list($cose, $off2) = hgs_cbor_item($auth, 55 + $credLen);
        if (!is_array($cose) || ($cose[1] ?? null) !== 2 || ($cose[3] ?? null) !== -7 || ($cose[-1] ?? null) !== 1) return [null, 'Only ES256 keys are supported.'];
        $pem = hgs_p256_pem($cose[-2] ?? null, $cose[-3] ?? null);
        if ($pem === null) return [null, 'Bad key coordinates.'];
        if (@openssl_pkey_get_public($pem) === false) return [null, 'Bad public key.'];
        return [hgs_b64u_encode($credId), $pem];
    } catch (Exception $e) { return [null, 'Key verification failed.']; }
}

// Verify a WebAuthn assertion against a key row (credential_id + PEM +
// sign_count). Returns [true, ''] or [false, errorMsg]; advances the sign
// count on success (equal-or-lower count with a nonzero stored count =
// cloned key). $keyRow must carry student_number for the counter write.
// The caller supplies the single-use $challengeRaw it issued for THIS
// purpose (login vs attendance challenges never mix).
function hgs_webauthn_authenticate(PDO $pdo, $keyRow, $payload, $challengeRaw, $rpId, $origin) {
    try {
        if (!is_array($payload)) return [false, 'Bad key data.'];
        $storedId = (string)($keyRow['credential_id'] ?? '');
        $pem = (string)($keyRow['public_key'] ?? '');
        if ($storedId === '' || $pem === '') return [false, 'No device key.'];
        $givenId = (string)($payload['id'] ?? '');
        if ($givenId === '' || !hash_equals($storedId, $givenId)) return [false, 'Unknown device key.'];
        $resp = (isset($payload['response']) && is_array($payload['response'])) ? $payload['response'] : [];
        $auth = hgs_b64u_decode($resp['authenticatorData'] ?? '');
        $cliJson = hgs_b64u_decode($resp['clientDataJSON'] ?? '');
        $sig = hgs_b64u_decode($resp['signature'] ?? '');
        if ($auth === null || $cliJson === null || $sig === null) return [false, 'Bad key encoding.'];
        $cli = json_decode($cliJson, true);
        if (!is_array($cli) || ($cli['type'] ?? '') !== 'webauthn.get') return [false, 'Wrong ceremony.'];
        if (($cli['origin'] ?? '') !== $origin) return [false, 'Origin mismatch.'];
        $cch = hgs_b64u_decode($cli['challenge'] ?? '');
        if ($cch === null || !hash_equals((string)$challengeRaw, $cch)) return [false, 'Challenge mismatch.'];
        if (strlen($auth) < 37) return [false, 'Bad authenticator data.'];
        if (substr($auth, 0, 32) !== hash('sha256', $rpId, true)) return [false, 'RP mismatch.'];
        $flags = ord($auth[32]);
        if (!($flags & 0x01)) return [false, 'User not present.'];
        $count = (ord($auth[33]) << 24) | (ord($auth[34]) << 16) | (ord($auth[35]) << 8) | ord($auth[36]);
        $stored = (int)($keyRow['sign_count'] ?? 0);
        if (!($count === 0 && $stored === 0) && $count <= $stored) return [false, 'Key replay detected.'];
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) return [false, 'Bad stored key.'];
        $vr = @openssl_verify($auth . hash('sha256', $cliJson, true), $sig, $key, OPENSSL_ALGO_SHA256);
        if ($vr !== 1) return [false, 'Signature mismatch.'];
        $sn = (string)($keyRow['student_number'] ?? '');
        if ($sn !== '') {
            $up = $pdo->prepare("UPDATE student_device_keys SET sign_count = :c, last_seen = CURRENT_TIMESTAMP WHERE student_number = :sn AND is_active = 1");
            $up->execute([':c' => $count, ':sn' => $sn]);
        }
        return [true, ''];
    } catch (Exception $e) { return [false, 'Key verification failed.']; }
}

// Active hardware key for a number, or null. At most one row can be active
// (partial UNIQUE index) — the strict 1:1 invariant lives in the schema.
function hgs_key_get_active(PDO $pdo, $sn) {
    try {
        hgs_ensure_key_tables($pdo);
        $st = $pdo->prepare("SELECT * FROM student_device_keys WHERE student_number = :sn AND is_active = 1 LIMIT 1");
        $st->execute([':sn' => trim((string)$sn)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) { return null; }
}

// Store a verified registration: one ACTIVE key per number (partial index
// turns a race into a UNIQUE error), one number per credential globally.
// Returns [true, ''] or [false, errorMsg].
function hgs_key_register(PDO $pdo, $sn, $name, $credId, $pem) {
    try {
        hgs_ensure_key_tables($pdo);
        $token = $_COOKIE['device_token'] ?? bin2hex(random_bytes(16));
        $st = $pdo->prepare("INSERT INTO student_device_keys (student_number, student_name, credential_id, public_key, sign_count, created_at, last_seen, is_active, device_token) VALUES (:sn, :name, :cid, :pem, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1, :tok)");
        $st->execute([':sn' => trim((string)$sn), ':name' => substr(trim((string)$name), 0, 120), ':cid' => $credId, ':pem' => $pem, ':tok' => $token]);
        return [true, ''];
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'UNIQUE') !== false) {
            return [false, 'This device is already bound to another student number. Each security key can be registered to exactly one number — sign in with the number that owns this device, or ask the administrator to release it.'];
        }
        return [false, 'Could not save the device key. Please try again.'];
    } catch (Exception $e) {
        return [false, 'Could not save the device key. Please try again.'];
    }
}

// Deactivate the active key (admin reset / OTP-verified self reset). The row
// stays for audit; only is_active flips. Returns true when a key was open.
function hgs_key_revoke(PDO $pdo, $sn, $by, $reason) {
    try {
        hgs_ensure_key_tables($pdo);
        $st = $pdo->prepare("UPDATE student_device_keys SET is_active = 0 WHERE student_number = :sn AND is_active = 1");
        $st->execute([':sn' => trim((string)$sn)]);
        if ($st->rowCount() < 1) return false;
        hgs_log_device($pdo, $sn, '', '', hgs_get_client_ip(), '', 'key_revoked',
            $reason . ' by ' . $by);
        return true;
    } catch (Exception $e) { return false; }
}

function hgs_key_touch(PDO $pdo, $sn) {
    try {
        $st = $pdo->prepare("UPDATE student_device_keys SET last_seen = CURRENT_TIMESTAMP WHERE student_number = :sn AND is_active = 1");
        $st->execute([':sn' => trim((string)$sn)]);
    } catch (Exception $e) {}
}

// Short public-key fingerprint for admin display (proves the mapping without
// pasting a full PEM into the UI).
function hgs_key_fingerprint($pem) {
    $pem = trim((string)$pem);
    if ($pem === '') return '';
    return strtoupper(substr(hash('sha256', preg_replace('/\s+/', '', $pem)), 0, 16));
}

// ---- Attendance step-up challenges (phase 3.3) ----
// Issues a single-use challenge bound to one attendance confirmation
// (student + session + request). Stored server-side in the session — never
// trusted from the client. Returns [challengeB64u, rpId, credentialId] or
// [null, ...] with $mode 'authenticate'|'none' decided by key presence.
function hgs_attend_options(PDO $pdo, $sn, $sessionId, $requestId) {
    try {
        hgs_ensure_key_tables($pdo);
        $keyRow = hgs_key_get_active($pdo, $sn);
        if (empty($keyRow) || trim((string)($keyRow['credential_id'] ?? '')) === '') {
            return ['mode' => 'none'];
        }
        $chRaw = random_bytes(32);
        $_SESSION['attend_challenge'] = [
            'ch' => base64_encode($chRaw), 'exp' => time() + 300,
            'sn' => trim((string)$sn),
            'session_id' => (string)$sessionId, 'request_id' => (string)$requestId,
        ];
        return ['mode' => 'authenticate', 'challenge' => hgs_b64u_encode($chRaw),
            'rpId' => hgs_webauthn_rp(), 'credentialId' => trim((string)$keyRow['credential_id'])];
    } catch (Exception $e) { return ['mode' => 'none']; }
}

// Verifies an attendance assertion against the session-bound challenge and
// the number's ACTIVE key. Consumes the challenge either way (single use).
// Only the signature crosses the network — the PIN never leaves the device
// (it only unlocks the enclave locally via user verification).
// Returns [true, ''] or [false, errorMsg].
function hgs_attend_verify(PDO $pdo, $payload, $sn, $sessionId, $requestId, $rpId, $origin) {
    $ses = $_SESSION['attend_challenge'] ?? null;
    unset($_SESSION['attend_challenge']);
    try {
        hgs_ensure_key_tables($pdo);
        $ok = is_array($ses)
            && ($ses['sn'] ?? '') === trim((string)$sn)
            && (string)($ses['session_id'] ?? '') === (string)$sessionId
            && (string)($ses['request_id'] ?? '') === (string)$requestId
            && ($ses['exp'] ?? 0) >= time();
        $chRaw = $ok ? base64_decode((string)($ses['ch'] ?? ''), true) : false;
        if (!$ok || !is_string($chRaw) || $chRaw === '') {
            return [false, 'Security check expired. Tap Confirm again to get a fresh check.'];
        }
        $keyRow = hgs_key_get_active($pdo, $sn);
        if (!$keyRow) return [false, 'No active security key for this number.'];
        list($valid, $msg) = hgs_webauthn_authenticate($pdo, $keyRow, $payload, $chRaw, $rpId, $origin);
        if (!$valid) return [false, $msg];
        hgs_key_touch($pdo, $sn);
        return [true, ''];
    } catch (Exception $e) { return [false, 'Security check failed. Please try again.']; }
}

// ---- Email-OTP device reset (phase 4.4) ----
define('HGS_RESET_CODE_TTL', 900);      // 15 minutes
define('HGS_RESET_MAX_ATTEMPTS', 5);    // guesses per code
define('HGS_RESET_RESEND_SECS', 300);   // min gap between emails

function hgs_roster_email(PDO $pdo, $sn) {
    // Main roster first.
    try {
        $st = $pdo->prepare("SELECT email FROM student_list WHERE student_number = :sn AND email IS NOT NULL AND TRIM(email) != '' LIMIT 1");
        $st->execute([':sn' => trim((string)$sn)]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $em = trim((string)($row['email'] ?? ''));
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) return $em;
        }
    } catch (Exception $e) {}
    return '';
}

function hgs_send_otp_mail($to, $sn, $code) {
    $to = trim((string)$to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subject = 'HORIZON device reset code for ' . $sn;
    $body = "Your HORIZON device reset code is: {$code}\n\n"
          . "It expires in 15 minutes and works once. Enter it on the student login page under \"Lost your device?\".\n"
          . "If you did not request this, ignore this email — your bound device is unchanged.\n";
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '') $host = 'localhost';
    $headers = "From: HORIZON Portal <noreply@{$host}>\r\nContent-Type: text/plain; charset=UTF-8";
    try {
        return @mail($to, $subject, $body, $headers);
    } catch (Throwable $e) { return false; }
}

function hgs_mask_email($email) {
    $email = trim((string)$email);
    $at = strrpos($email, '@');
    if ($at === false) return 'your registered email';
    $user = substr($email, 0, $at);
    $dom = substr($email, $at + 1);
    $shown = strlen($user) <= 2 ? str_repeat('*', strlen($user)) : ($user[0] . str_repeat('*', max(1, strlen($user) - 2)) . $user[strlen($user) - 1]);
    return $shown . '@' . $dom;
}

// Student-driven reset request. ALWAYS returns generic success text (no
// account/email enumeration); the bool reports whether mail went out.
// Resends are throttled: a live code younger than the resend gap is reused.
function hgs_reset_request(PDO $pdo, $sn) {
    $sn = trim((string)$sn);
    try {
        hgs_ensure_key_tables($pdo);
        $email = hgs_roster_email($pdo, $sn);
        $now = time();
        $row = null;
        try {
            $st = $pdo->prepare("SELECT * FROM device_resets WHERE student_number = :sn LIMIT 1");
            $st->execute([':sn' => $sn]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        if ($email === '') {
            hgs_log_device($pdo, $sn, '', '', hgs_get_client_ip(), '', 'reset_requested', 'Reset requested but no registered email on file — student must see an admin.');
            return [true, false, $email];
        }
        if ($row && empty($row['used_at']) && (int)($row['expires_at'] ?? 0) > $now && (int)($row['last_sent_at'] ?? 0) > $now - HGS_RESET_RESEND_SECS) {
            return [true, true, $email]; // live code already sent recently: do not spam
        }
        try { $code = sprintf('%06d', random_int(0, 999999)); }
        catch (Throwable $e) { $code = sprintf('%06d', mt_rand(0, 999999)); }
        $hash = hash('sha256', $sn . ':reset:' . $code);
        $pdo->prepare("INSERT OR REPLACE INTO device_resets (student_number, code_hash, expires_at, attempts, used_at, last_sent_at, created_at, created_by) VALUES (:sn, :h, :exp, 0, NULL, :sent, CURRENT_TIMESTAMP, 'student')")
            ->execute([':sn' => $sn, ':h' => $hash, ':exp' => ($now + HGS_RESET_CODE_TTL), ':sent' => $now]);
        $mailed = hgs_send_otp_mail($email, $sn, $code);
        hgs_log_device($pdo, $sn, '', '', hgs_get_client_ip(), '', 'reset_issued',
            'Reset code issued for ' . $sn . ' (' . ($mailed ? 'emailed to ' . hgs_mask_email($email) : 'MAIL FAILED to ' . hgs_mask_email($email) . ' — relay manually after ID check') . ')');
        return [true, $mailed, $email];
    } catch (Exception $e) {
        error_log('hgs_reset_request: ' . $e->getMessage());
        return [true, false, ''];
    }
}

// Verifies a reset code; on success REVOKES the active key immediately
// (spec order: old key dies before any new binding) and consumes the code.
// Returns [true, msg] or [false, msg]. Wrong guesses count toward the cap.
function hgs_reset_verify(PDO $pdo, $sn, $code) {
    $sn = trim((string)$sn);
    $code = preg_replace('/\D/', '', (string)$code);
    try {
        hgs_ensure_key_tables($pdo);
        $st = $pdo->prepare("SELECT * FROM device_resets WHERE student_number = :sn LIMIT 1");
        $st->execute([':sn' => $sn]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $now = time();
        $bad = function ($why) use ($pdo, $sn, $row, $now) {
            if ($row) {
                $att = (int)($row['attempts'] ?? 0) + 1;
                if ($att >= HGS_RESET_MAX_ATTEMPTS) {
                    $pdo->prepare("UPDATE device_resets SET attempts = :a, used_at = CURRENT_TIMESTAMP WHERE student_number = :sn")->execute([':a' => $att, ':sn' => $sn]);
                } else {
                    $pdo->prepare("UPDATE device_resets SET attempts = :a WHERE student_number = :sn")->execute([':a' => $att, ':sn' => $sn]);
                }
            }
            return [false, $why];
        };
        if (!$row || !empty($row['used_at'])) return $bad('Invalid or expired reset code. Request a fresh one.');
        if ((int)($row['expires_at'] ?? 0) <= $now) return $bad('Reset code expired. Request a fresh one.');
        if ((int)($row['attempts'] ?? 0) >= HGS_RESET_MAX_ATTEMPTS) return $bad('Too many wrong attempts. Request a fresh code.');
        if ($code === '' || !hash_equals((string)$row['code_hash'], hash('sha256', $sn . ':reset:' . $code))) {
            return $bad('Incorrect reset code. Check the email and try again.');
        }
        $pdo->prepare("UPDATE device_resets SET used_at = CURRENT_TIMESTAMP WHERE student_number = :sn")->execute([':sn' => $sn]);
        hgs_key_revoke($pdo, $sn, 'reset-code', 'Old device revoked via email OTP for ' . $sn);
        hgs_log_device($pdo, $sn, '', '', hgs_get_client_ip(), '', 'reset_used', 'Reset code accepted for ' . $sn . ' — old key revoked, new enrollment allowed.');
        return [true, 'Old device revoked. Log in now (number + PIN) to enroll this device as your new one.'];
    } catch (Exception $e) {
        error_log('hgs_reset_verify: ' . $e->getMessage());
        return [false, 'Could not verify the code. Please try again.'];
    }
}
