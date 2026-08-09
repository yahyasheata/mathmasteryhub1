<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$targetUserId = (int) (getenv('RESET_DIAGNOSTIC_USER_ID') ?: 0);
if ($targetUserId <= 0) {
    fwrite(STDERR, "Missing diagnostic target.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
require_once dirname(__DIR__, 2) . '/inc/PasswordReset.php';

$conn = db();
$user = null;
$stmt = $conn->prepare('SELECT user_id, username, role, status, archived_at FROM users WHERE user_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $targetUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$username = (string) ($user['username'] ?? '');
$isEmail = filter_var($username, FILTER_VALIDATE_EMAIL) !== false;
$isNonAdmin = strtolower((string) ($user['role'] ?? '')) !== 'admin';
$isActive = (string) ($user['status'] ?? '') === '1';
$isUnarchived = empty($user['archived_at']);
$eligible = $user !== null && $isEmail && $isNonAdmin && $isActive && $isUnarchived;

$config = [];
foreach (['RESEND_API_KEY', 'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME', 'APP_PUBLIC_URL'] as $key) {
    $config[$key] = mmh_password_reset_env($key) !== '';
}

$result = [false, 'not_attempted'];
if ($eligible) {
    // This deliberately uses a non-reset URL: no reset token is created or sent.
    $result = mmh_password_reset_send_resend($username, 'https://mathmasteryhub.com/auth/forgot-password');
}

echo json_encode([
    'account_found' => $user !== null,
    'email_shaped' => $isEmail,
    'non_admin' => $isNonAdmin,
    'active' => $isActive,
    'unarchived' => $isUnarchived,
    'eligible' => $eligible,
    'php_config' => $config,
    'curl_available' => function_exists('curl_init'),
    'resend_result' => $result[0] ? 'accepted' : 'failed',
    'resend_code' => $result[0] ? 'accepted' : (string) ($result[1] ?? 'unknown'),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
