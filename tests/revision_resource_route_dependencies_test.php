<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$route = file_get_contents($root . '/views/user/requests/open-revision-resource.php');
$gateway = file_get_contents($root . '/inc/StudentResourceGateway.php');
if (!str_contains($route, "require_once dirname(__DIR__, 3) . '/inc/StudentResourceGateway.php';")) {
    throw new RuntimeException('Revision resource route does not load the canonical Student Resource Gateway.');
}
if (!str_contains($route, 'mmh_student_resource_url(') || !str_contains($gateway, 'function mmh_student_resource_url')) {
    throw new RuntimeException('Revision route/gateway URL helper contract is missing.');
}
if (substr_count($route, 'function mmh_student_resource_url') !== 0) {
    throw new RuntimeException('Revision route must not define a duplicate URL helper.');
}

require_once $root . '/inc/StudentResourceGateway.php';
if (!function_exists('mmh_student_resource_url')) throw new RuntimeException('Canonical URL helper did not load.');
$url = mmh_student_resource_url('/app', 'course-1', 'item-1', ['revision_assignment_id' => 7, 'revision_requirement_id' => 9]);
if (!str_contains($url, '/user/course/resource/course-1/item-1') || !str_contains($url, 'revision_assignment=7') || !str_contains($url, 'revision_requirement=9')) {
    throw new RuntimeException('Canonical Revision Course Item URL was not generated.');
}
foreach (['external_link', 'course_item', 'storage/private/revision-plans'] as $marker) {
    if (!str_contains($route, $marker)) throw new RuntimeException('Revision resource type branch is missing: ' . $marker);
}
echo "revision_resource_route_dependencies=canonical_helper=present=executed route_types=present duplicate_helper=absent\n";
