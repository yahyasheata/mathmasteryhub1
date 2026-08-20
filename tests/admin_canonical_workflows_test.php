<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$courseService = file_get_contents($root . '/inc/AdminCourseService.php');
$assessmentService = file_get_contents($root . '/inc/AdminAssessmentService.php');
$migration = file_get_contents($root . '/database/migrations/20260807_finalize_runtime_schema.php');
$learningSchema = file_get_contents($root . '/inc/learning_schema.php');
$live = file_get_contents($root . '/inc/LiveSessions.php');
$settingsHandler = file_get_contents($root . '/views/admin/requests/update-settings.php');
$duplicateItem = file_get_contents($root . '/views/admin/requests/duplicate-item.php');

foreach (['mmh_admin_course_archive', 'mmh_admin_course_set_status', 'mmh_admin_course_archive_item', 'mmh_admin_assignment_archive', 'mmh_admin_student_archive'] as $name) {
    if (!str_contains((string) $courseService, 'function ' . $name)) throw new RuntimeException("Canonical course service missing {$name}.");
}
if (!str_contains((string) $assessmentService, 'mmh_admin_assignment_submission_counts')) throw new RuntimeException('Canonical assessment reads are missing.');
if (!str_contains((string) $duplicateItem, 'CourseContentCopyService::copyItem')) throw new RuntimeException('Item duplication is not using the canonical independent-copy service.');
if (!str_contains((string) $migration, 'MMH_SCHEMA_MIGRATION_MODE')) throw new RuntimeException('Runtime schema migration mode is missing.');
if (!str_contains((string) $learningSchema, 'mmh_schema_mutations_allowed')) throw new RuntimeException('Learning schema is not migration-gated.');
if (!str_contains((string) $live, 'mmh_schema_mutations_allowed')) throw new RuntimeException('Live-session schema is not migration-gated.');
if (str_contains((string) $settingsHandler, '$baseUrl')) throw new RuntimeException('Settings redirect still relies on an undefined base URL.');

echo "Canonical admin workflow regression checks passed.\n";
