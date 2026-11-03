<?php
// Build an ISOLATED sandbox copy of the login stack so the HTTP end-to-end
// test (_qa_login_http.php) can exercise success paths without touching a
// single real credential, PIN or binding.
//
// Creates a SIBLING folder: <repo>_qa/{DBConnection.php,students/*,db/*}
//   run:  php _qa_build_sandbox.php   then   php _qa_login_http.php
//   then: DELETE the sandbox folder — it holds a full copy of the student
//   roster and sits INSIDE the web root, so it must never be left behind.
//   Remove-Item -Recurse -Force "<repo>_qa"
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
$src = __DIR__;
$dst = rtrim($src, '\\') . '_qa';

@mkdir("$dst\\students", 0777, true);
@mkdir("$dst\\db", 0777, true);
copy("$src\\DBConnection.php", "$dst\\DBConnection.php") or exit("copy DBConnection failed\n");
copy("$src\\students\\login.php", "$dst\\students\\login.php") or exit("copy login failed\n");
copy("$src\\students\\device_keys.php", "$dst\\students\\device_keys.php") or exit("copy device_keys failed\n");

// Fresh DB from the live one (structure + roster), then sanitized for tests.
$live = "$src\\db\\grading_db.db";
$copy = "$dst\\db\\grading_db.db";
foreach ([$copy, $copy . '-wal', $copy . '-shm'] as $f) @unlink($f);
copy($live, $copy) or exit("copy db failed\n");

$db = new PDO('sqlite:' . $copy);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Retired tables may already be gone from the live DB — only clear what exists.
foreach (['student_device_logs', 'device_resets', 'student_device_keys', 'student_bind_pins',
          'student_device_bindings', 'student_device_browsers'] as $tbl) {
    try { $db->exec("DELETE FROM $tbl"); } catch (PDOException $e) { /* already retired */ }
}

// Two synthetic students: one never chose a PIN, one will be PIN-less but with
// a device key already enrolled (to test the strict branch).
// login.php resolves students via student_list JOIN class_list JOIN subjects,
// so the class_id must point at a REAL class (with a real subject_id).
$classId = (int)$db->query("SELECT c.class_id FROM class_list c JOIN subjects s ON s.subject_id = c.subject_id LIMIT 1")->fetchColumn();
if (!$classId) { echo "no usable class_id in the copied DB — aborting\n"; exit(3); }
$db->exec("INSERT OR REPLACE INTO student_list (student_number, name, class_id, email) VALUES ('26-60001','Alpha, Test',$classId,'alpha.qa@example.com')");
$db->exec("INSERT OR REPLACE INTO student_list (student_number, name, class_id, email) VALUES ('26-60002','Bravo, Test',$classId,'')");
$check = (int)$db->query("SELECT COUNT(*) FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = '26-60001'")->fetchColumn();
echo "sandbox ready at $dst (class_id=$classId, resolvable student rows=$check)\n";
echo "login URL: http://localhost/grading_system_qa/students/login.php\n";