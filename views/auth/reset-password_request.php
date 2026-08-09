<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/PasswordReset.php';
mmh_password_reset_no_store_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
if (!mmh_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your session has expired. Please refresh the page and try again.');
}
$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmation = (string) ($_POST['password_confirmation'] ?? '');
$base = rtrim(mmh_current_request_base_url(), '/');
if ($password !== $confirmation) {
    mmh_auth_flash('password_reset_error', 'The new password and confirmation do not match.');
    header('Location: ' . $base . '/auth/reset-password?token=' . rawurlencode($token), true, 303);
    exit;
}
if (strlen($password) < 8 || strlen($password) > 190) {
    mmh_auth_flash('password_reset_error', 'Choose a password between 8 and 190 characters.');
    header('Location: ' . $base . '/auth/reset-password?token=' . rawurlencode($token), true, 303);
    exit;
}
if (!mmh_password_reset_apply(db(), $token, $password)) {
    header('Location: ' . $base . '/auth/reset-password', true, 303);
    exit;
}
// The current application has no persistent session-version store. Clear the
// browser session if one exists, without pretending to revoke other sessions.
if (session_status() === PHP_SESSION_ACTIVE && (!empty($_SESSION['username']) || !empty($_SESSION['admin']))) {
    mmh_auth_logout();
    session_start();
}
mmh_auth_flash('password_reset_success', 'Your password has been changed. Please sign in with your new password.');
header('Location: ' . $base . '/auth/login', true, 303);
exit;
