<?php
session_start();
$_SESSION['admin_id'] = 1; // mock admin login
$_GET['action'] = 'list';
$_GET['class_id'] = '64';
$_REQUEST = $_GET;
require __DIR__ . '/quiz_actions.php';
?>