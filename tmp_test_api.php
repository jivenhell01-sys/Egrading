<?php
// Minimal test harness for quiz_actions list API
$_GET['action'] = 'list';
$_GET['class_id'] = '64';
$_REQUEST = $_GET;
require __DIR__ . '/quiz_actions.php';
?>