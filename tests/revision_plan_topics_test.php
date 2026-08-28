<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');
$service = file_get_contents($root . '/inc/RevisionPlan.php');

if (!str_contains($admin, '<span>Topics</span>')) throw new RuntimeException('Admin day editor does not expose Topics.');
if (!str_contains($admin, 'List the topics covered on this day.')) throw new RuntimeException('Topics helper text is missing.');
if (str_contains($admin, '<span>Day title</span>')) throw new RuntimeException('Legacy Day title label remains in the admin UI.');
if (!str_contains($admin, 'function topicSummary') || !str_contains($admin, "Day '+(di+1)+'")) {
    throw new RuntimeException('Generated Day identity is not kept separate from Topics.');
}
if (!str_contains($student, '$selectedDayTopics') || !str_contains($student, 'revision-selected-day-topics')) {
    throw new RuntimeException('Student selected-day Topics rendering is missing.');
}
if (!str_contains($service, 'revision_plan_template_days') || !str_contains($service, 'title')) {
    throw new RuntimeException('Existing day title storage could not be verified.');
}
if (str_contains($student, 'INSERT INTO revision_plan') || str_contains($student, 'UPDATE revision_plan')) {
    throw new RuntimeException('Student Topics rendering must not mutate Revision Plan data.');
}

echo "topics=existing_day_title admin_label=present student_render=present day_identity=preserved\n";
