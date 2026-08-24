<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$security = file_get_contents($root . '/inc/AdminSecurity.php');
$service = file_get_contents($root . '/inc/AdminAccountManagement.php');
$page = file_get_contents($root . '/views/admin/admin-management.php');
$handler = file_get_contents($root . '/views/admin/requests/save-admin-management.php');
$deploy = file_get_contents($root . '/.github/workflows/deploy.yml');
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert(str_contains((string) $security, 'mmh_admin_fresh_session_user') && str_contains((string) $security, "role, status"), 'Fresh database-backed admin authorization is missing.');
$assert(str_contains((string) $security, "'admin-management'"), 'Admin Management page is not allowlisted.');
$assert(str_contains((string) $security, "'save-admin-management.php'"), 'Admin Management mutation is not allowlisted.');
foreach (['begin_transaction', 'FOR UPDATE', 'last active administrator', 'ADMIN_PROMOTED', 'ADMIN_DEMOTED', 'password'] as $needle) $assert(str_contains((string) $service, $needle), 'Admin service contract missing: ' . $needle);
$assert(str_contains((string) $page, 'mmh_admin_csrf_token()') && str_contains((string) $handler, 'mmh_admin_require_mutation()'), 'Admin Management CSRF contract is missing.');
$assert(str_contains((string) $deploy, '20260823_create_admin_security_audit.php'), 'Audit migration is not in deployment.');
$assert(!str_contains((string) $service, '$_POST['), 'Admin service must not trust raw POST data.');
echo "Admin account management contract checks passed.\n";
