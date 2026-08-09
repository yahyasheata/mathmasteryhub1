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
$email = trim((string) ($_POST['email'] ?? ''));
mmh_password_reset_issue(db(), $email, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
mmh_auth_flash('password_reset', mmh_password_reset_generic_message());
header('Location: ' . rtrim(mmh_current_request_base_url(), '/') . '/auth/forgot-password', true, 303);
exit;
