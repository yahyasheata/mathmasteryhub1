<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
$view = file_get_contents($root . '/views/admin/requests/course-copy-options.php');
$page = file_get_contents($root . '/views/admin/course-content.php');
$routes = file_get_contents($root . '/index.php');
if (!is_string($view) || !is_string($page) || !is_string($routes)) {
    throw new RuntimeException('Copy Section sources could not be read.');
}

if (!str_contains($routes, '$router->get(\'/requests/course-copy/options\'')) {
    throw new RuntimeException('Destination-course endpoint is not registered as an explicit GET route.');
}
$optionsRoutePosition = strpos($routes, '$router->get(\'/requests/course-copy/options\'');
$catchAllRoutePosition = strpos($routes, '$router->get(\'/{pageName}\'');
if ($optionsRoutePosition === false || $catchAllRoutePosition === false || $optionsRoutePosition > $catchAllRoutePosition) {
    throw new RuntimeException('Destination-course endpoint must be registered before the catch-all admin page route.');
}
if (!str_contains($routes, "mmh_admin_require_admin();")) {
    throw new RuntimeException('Copy Section endpoint is missing admin authorization.');
}
if (!str_contains($view, "SELECT course_id, course_title, course_state FROM courses ORDER BY course_title ASC, course_id ASC")) {
    throw new RuntimeException('Destination courses are not deterministically ordered by canonical course ID.');
}
if (!str_contains($view, "SELECT section_id, title, sort_order FROM course_sections WHERE course_id = ? ORDER BY sort_order ASC, section_id ASC")) {
    throw new RuntimeException('Destination sections are not scoped and deterministically ordered.');
}
if (!str_contains($view, "http_response_code(500)") || !str_contains($view, 'Destination course sections could not be loaded.')) {
    throw new RuntimeException('Destination-section query failures are not surfaced as JSON errors.');
}
if (!str_contains($page, "adminRequestUrl('requests/course-copy/options')")
    || !str_contains($page, "adminRequestUrl(endpoint)")
    || !str_contains($page, 'Couldn’t load courses.')
    || !str_contains($page, '#course-copy-retry')) {
    throw new RuntimeException('Copy Section UI does not use the canonical admin endpoint or retry state.');
}

echo "course_copy_options_contract=passed explicit_endpoint=passed deterministic_queries=passed retry_state=passed\n";
