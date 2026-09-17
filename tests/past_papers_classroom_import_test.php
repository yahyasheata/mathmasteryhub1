<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/PastPaperClassroomScanner.php';

function import_test(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$ready = ['status' => 'READY', 'resources' => [['resource_type' => 'question_paper', 'source' => ['source_url' => 'https://example.test/qp.pdf']]]];
$warning = ['status' => 'WARNING', 'resources' => [['resource_type' => 'video_solution', 'source' => ['source_url' => 'https://example.test/video']]], 'missing_resources' => ['question_paper', 'model_answer']];
$blocked = ['status' => 'NEEDS REVIEW', 'resources' => []];
$error = ['status' => 'ERROR', 'resources' => [['resource_type' => 'question_paper']]];
import_test(mmh_classroom_candidate_importable($ready), 'READY candidate should be importable.');
import_test(mmh_classroom_candidate_importable($warning), 'WARNING candidate with a usable resource should be importable.');
import_test(!mmh_classroom_candidate_importable($blocked), 'Zero-resource candidate should be blocked.');
import_test(!mmh_classroom_candidate_importable($error), 'ERROR candidate should be blocked.');

$scanner = file_get_contents(__DIR__ . '/../inc/PastPaperClassroomScanner.php');
$route = file_get_contents(__DIR__ . '/../index.php');
$request = file_get_contents(__DIR__ . '/../views/admin/requests/import-classroom-past-papers.php');
import_test(str_contains((string) $route, "post('/past-papers/classroom/import'"), 'Admin import route is missing.');
import_test(str_contains((string) $request, 'mmh_classroom_import_candidates(db(), $preview, $selected'), 'Import request does not use the session preview.');
import_test(str_contains((string) $scanner, '$conn->begin_transaction();') && str_contains((string) $scanner, '$conn->rollback();'), 'Candidate import is not transactional.');
import_test(!str_contains((string) $request, '$_POST[\'paper_number\']') && !str_contains((string) $request, '$_POST[\'external_url\']'), 'Import request trusts raw paper/resource POST data.');
import_test(str_contains((string) $scanner, "'resource_type' => \$type") && str_contains((string) $scanner, "'storage_type' => 'url'"), 'Mapped resources are not saved through the canonical helper.');

echo "Past Paper Classroom import contract checks passed.\n";
