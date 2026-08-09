<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/PasswordReset.php';

$token = mmh_password_reset_token();
if (strlen($token) !== 64 || !mmh_password_reset_valid_token($token)) {
    throw new RuntimeException('Reset token is not 256-bit hex.');
}
if (hash_equals($token, mmh_password_reset_token())) {
    throw new RuntimeException('Reset token generation is not random.');
}
$hash = mmh_password_reset_token_hash($token);
if ($hash === $token || strlen($hash) !== 64 || !ctype_xdigit($hash)) {
    throw new RuntimeException('Raw token/hash contract failed.');
}
$migration = file_get_contents(__DIR__ . '/../database/migrations/20260812_create_password_reset_tokens.php');
foreach (['password_reset_tokens', 'token_hash', 'expires_at', 'used_at', 'requested_ip', 'requested_user_agent_hash', 'password_reset_rate_limits'] as $field) {
    if (!str_contains((string) $migration, $field)) {
        throw new RuntimeException("Migration is missing {$field}.");
    }
}
$routes = file_get_contents(__DIR__ . '/../index.php');
foreach (['forgot-password', 'reset-password', 'forgot-password_request.php', 'reset-password_request.php'] as $marker) {
    if (!str_contains((string) $routes, $marker)) {
        throw new RuntimeException("Auth route is missing {$marker}.");
    }
}
$login = file_get_contents(__DIR__ . '/../views/auth/login.php');
if (!str_contains((string) $login, 'forgot-password')) {
    throw new RuntimeException('Login page has no forgot-password link.');
}
$helper = file_get_contents(__DIR__ . '/../inc/PasswordReset.php');
if (preg_match('/error_log\([^\n]*(?:token|apiKey|resetUrl)/i', (string) $helper)) {
    throw new RuntimeException('Reset helper appears to log a secret.');
}
echo "Password reset security checks passed.\n";
