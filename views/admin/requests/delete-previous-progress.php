<?php
require_once '__init.php';
$base = rtrim((string) $baseUrl, '/');
$_SESSION['learning_journey_flash'] = ['ok' => false, 'message' => 'Summary records are retired. Clear an item from Manage Learning Journey instead.'];
header('Location: ' . $base . '/admin/previous-progress');
exit;
