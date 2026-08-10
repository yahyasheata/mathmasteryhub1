<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/AssignmentModelAnswerAccess.php';

foreach (['all', 'selected', 'none'] as $mode) {
    if (mmh_assignment_model_answer_access_normalize_mode($mode) !== $mode) {
        throw new RuntimeException('Supported Model Answer access mode was not preserved.');
    }
}
foreach (['', 'invalid', 'ALL ', null] as $mode) {
    if (mmh_assignment_model_answer_access_normalize_mode($mode) !== 'all') {
        throw new RuntimeException('Legacy/invalid Model Answer access must preserve the all-students default.');
    }
}

$gateway = file_get_contents(dirname(__DIR__) . '/inc/StudentResourceGateway.php');
$renderer = file_get_contents(dirname(__DIR__) . '/inc/CourseHomeworkRenderer.php');
$form = file_get_contents(dirname(__DIR__) . '/views/admin/requests/form-item.php');
$save = file_get_contents(dirname(__DIR__) . '/views/admin/requests/add-item.php');
$duplicate = file_get_contents(dirname(__DIR__) . '/inc/CourseAssignmentLinks.php');
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260813_create_assignment_model_answer_access.php');
foreach ([$gateway, $renderer, $form, $save, $duplicate, $migration] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to inspect Model Answer access source.');
}
if (!str_contains($gateway, 'mmh_assignment_model_answer_access_can') || !str_contains($gateway, "'model-answer'")) {
    throw new RuntimeException('The protected gateway does not enforce Model Answer access.');
}
if (!str_contains($renderer, '$modelPolicyAllowed') || !str_contains($renderer, 'if ($modelPolicyAllowed)')) {
    throw new RuntimeException('Unauthorized students could still receive a Model Answer action.');
}
if (!str_contains($form, 'model_answer_access_mode') || !str_contains($form, 'model_answer_access_student_ids[]')) {
    throw new RuntimeException('The canonical Assignment editor is missing Model Answer access controls.');
}
if (substr_count($save, 'mmh_assignment_model_answer_access_save(') < 2) {
    throw new RuntimeException('Create and update saves do not persist Model Answer access transactionally.');
}
if (!str_contains($duplicate, 'mmh_assignment_model_answer_access_clone')) {
    throw new RuntimeException('Assignment duplication does not copy independent Model Answer access policy.');
}
if (!str_contains($migration, 'model_answer_access_mode') || !str_contains($migration, 'uq_assignment_model_answer_access')) {
    throw new RuntimeException('Model Answer access migration is incomplete.');
}

echo "Model Answer access contract, policy defaults, editor, gateway, duplication, and migration checks passed.\n";
