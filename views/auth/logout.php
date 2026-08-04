<?php
require_once '__init.php';
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/LearningEvents.php';

$loggedOutUsername = $_SESSION['username'] ?? $_SESSION['admin'] ?? null;
if ($loggedOutUsername) {
    $loggedOutUser = getUserInfo($loggedOutUsername);
    if ($loggedOutUser) {
        mmh_log_event(db(), (int) $loggedOutUser->user_id, 'logout');
    }
}

mmh_auth_logout();
header('Location: ' . rtrim(mmh_current_request_base_url(), '/') . '/auth/login');
exit;
