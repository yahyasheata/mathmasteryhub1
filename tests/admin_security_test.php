<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/AdminSecurity.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION = ['admin' => 'test-admin'];
$token = mmh_admin_csrf_token();
if ($token === '' || !mmh_admin_csrf_valid($token)) throw new RuntimeException('Valid admin CSRF token was rejected.');
if (mmh_admin_csrf_valid('invalid-token')) throw new RuntimeException('Invalid admin CSRF token was accepted.');
unset($_POST['mmh_csrf_token'], $_POST['csrf_token'], $_POST['_token']);
if (mmh_admin_csrf_valid()) throw new RuntimeException('Missing admin CSRF token was accepted.');
if (!mmh_admin_allowed_page('dashboard') || mmh_admin_allowed_page('../connection/config')) throw new RuntimeException('Admin page allowlist failed.');
if (mmh_admin_allowed_handler('course', 'add') !== 'add-course.php') throw new RuntimeException('Admin handler allowlist failed.');
if (mmh_admin_allowed_handler('course', 'not-real') !== null) throw new RuntimeException('Unknown admin handler was allowed.');

$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
if (!str_contains((string) $htaccess, 'views|connection|inc|database|scripts|vendor')) throw new RuntimeException('Internal PHP blocking rule is missing.');
$notificationCourse = file_get_contents(__DIR__ . '/../views/admin/requests/notification-course.php');
if (str_contains((string) $notificationCourse, 'course_id = 33')) throw new RuntimeException('Notification handler still contains the hardcoded course.');

$duplication = file_get_contents(__DIR__ . '/../views/admin/requests/bulk-items.php');
foreach (['paper_source', 'paper_external_url', 'paper_external_preview_url', 'paper_external_download_url', 'paper_fallback_instructions'] as $field) {
    if (!str_contains((string) $duplication, $field)) throw new RuntimeException("Timed Exam duplication is missing {$field}.");
}

echo "Admin security regression checks passed.\n";
