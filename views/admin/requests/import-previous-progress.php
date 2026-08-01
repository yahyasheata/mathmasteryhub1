<?php
require_once '__init.php';
$base = rtrim((string) $baseUrl, '/');
$_SESSION['learning_journey_flash'] = ['ok' => false, 'message' => 'Summary imports are no longer supported. Select the real course items in Manage Learning Journey.'];
header('Location: ' . $base . '/admin/previous-progress');
exit;
