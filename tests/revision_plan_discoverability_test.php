<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$publicShell = file_get_contents($root . '/views/public/layouts/aside.php');
$studentNav = file_get_contents($root . '/views/user/layouts/user/main-nav.php');
$myCourses = file_get_contents($root . '/views/user/my-courses.php');
$list = file_get_contents($root . '/views/user/revision-plans.php');

if (!str_contains($publicShell, "['label' => 'Revision Plans', 'href' => \$publicBaseUrl . '/user/revision-plans']")) {
    throw new RuntimeException('Signed-in public account navigation does not expose Revision Plans.');
}
if (!str_contains($studentNav, '/user/revision-plans') || !str_contains($studentNav, 'Your Plans')) {
    throw new RuntimeException('Student navigation does not expose Your Plans.');
}
foreach (['mmh_revision_student_assignments', 'student-revision-plans-strip', 'View all plans', 'schedule_state'] as $marker) {
    if (!str_contains($myCourses, $marker)) throw new RuntimeException('My Courses Revision Plan discovery is missing: ' . $marker);
}
if (!str_contains($list, 'Your revision plans will appear here') || !str_contains($list, 'View Plan')) {
    throw new RuntimeException('Revision Plan list lacks explicit empty/upcoming states.');
}
echo "revision_plan_discoverability=public_account=student_nav=my_courses_strip=present\n";
