<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/AdminAccountManagement.php';

mmh_admin_require_mutation();
$conn = db();
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$currentPassword = (string) ($_POST['current_password'] ?? '');
$role = $action === 'promote' ? 'admin' : ($action === 'demote' ? 'user' : '');
$actorUsername = trim((string) ($_SESSION['admin'] ?? ''));
$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');

$result = $role !== '' && $targetUserId !== false
    ? mmh_admin_management_change_role($conn, $actorUsername, (int) $targetUserId, $role, $currentPassword)
    : ['ok' => false, 'message' => 'The administrator change request is invalid.', 'self_demotion' => false];

if (!empty($result['ok']) && !empty($result['self_demotion'])) {
    mmh_admin_management_revoke_self_session($actorUsername);
    header('Location: ' . ($base !== '' ? $base : '') . '/');
    exit;
}

$_SESSION['mmh_admin_management_flash'] = [
    'ok' => !empty($result['ok']),
    'message' => (string) ($result['message'] ?? 'The administrator change could not be completed.'),
];
header('Location: ' . ($base !== '' ? $base : '') . '/admin/admin-management');
exit;
