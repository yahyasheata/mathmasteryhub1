<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$aside = file_get_contents($root . '/views/admin/layouts/admin/aside.php');
$topNav = file_get_contents($root . '/views/admin/layouts/admin/top-nav.php');
$header = file_get_contents($root . '/views/admin/layouts/admin/header.php');
$shellCss = file_get_contents($root . '/resources/css/admin-shell.css');
$dashboard = file_get_contents($root . '/views/admin/dashboard.php');

foreach (['Course Management', 'Assessments', 'Students', 'Student Support', 'Resources', 'Communication', 'Site Settings'] as $label) {
    if (!str_contains((string) $aside, $label)) throw new RuntimeException("Sidebar group missing {$label}.");
}
if (str_contains((string) $topNav, 'notificationDropdown') || str_contains((string) $topNav, 'resources//admin/notifications')) throw new RuntimeException('Dead notification markup remains in the admin shell.');
if (substr_count((string) $aside, '/admin/logout') !== 2) throw new RuntimeException('Canonical sidebar logout form/link is missing.');
if (!str_contains((string) $header, 'resources/css/admin-shell.css')) throw new RuntimeException('Shared admin shell stylesheet is not loaded.');
foreach (['@media (max-width:700px)', 'focus-visible', 'admin-sidebar-mobile-open', 'admin-nav-submenu'] as $marker) {
    if (!str_contains((string) $shellCss, $marker)) throw new RuntimeException("Admin shell accessibility/responsive marker missing: {$marker}.");
}
foreach (['Add Course', 'Enroll Student', 'Schedule Live Session', 'Create Recovery Plan'] as $label) {
    if (!str_contains((string) $dashboard, $label)) throw new RuntimeException("Dashboard quick action missing {$label}.");
}
if (file_exists($root . '/views/admin/thtml.html') || file_exists($root . '/views/admin/requests/update-settings copy 2.php')) throw new RuntimeException('Verified dead admin files were not removed.');

echo "Admin Stage 3 UI regression checks passed.\n";
