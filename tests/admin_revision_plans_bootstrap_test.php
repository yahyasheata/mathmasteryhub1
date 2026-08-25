<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
$header = file_get_contents($root . '/views/admin/layouts/admin/header.php');
$revisionView = file_get_contents($root . '/views/admin/revision-plans.php');
$functions = file_get_contents($root . '/inc/functions.php');
$dashboard = file_get_contents($root . '/views/admin/dashboard.php');

if (!str_contains($functions, 'function getSiteSettings')) {
    throw new RuntimeException('The canonical getSiteSettings implementation is missing.');
}
if (!str_contains($header, "require_once __DIR__ . '/../../../../inc/functions.php';")) {
    throw new RuntimeException('The shared Admin shell does not load the canonical helper bootstrap.');
}
if (!str_contains($header, 'getSiteSettings()')) {
    throw new RuntimeException('The shared Admin shell no longer consumes canonical site settings.');
}
if (!str_contains($revisionView, "include 'layouts/admin/header.php';")) {
    throw new RuntimeException('Revision Plans does not use the canonical Admin shell.');
}
if (preg_match('/function\s+getSiteSettings\s*\(/', $revisionView)) {
    throw new RuntimeException('Revision Plans must not define a page-specific Site Settings helper.');
}
if (!str_contains($dashboard, "include 'layouts/admin/header.php';")) {
    throw new RuntimeException('The known working Admin page no longer uses the shared shell.');
}
$canonicalHelper = realpath($root . '/inc/functions.php');
$headerHelper = realpath($root . '/views/admin/layouts/admin/../../../../inc/functions.php');
if ($canonicalHelper === false || $headerHelper === false || $canonicalHelper !== $headerHelper) {
    throw new RuntimeException('The Admin shell helper path does not resolve to inc/functions.php.');
}

echo "Admin Revision Plans bootstrap regression checks passed.\n";
