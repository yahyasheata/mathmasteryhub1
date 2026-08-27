<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }
$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$migration = file_get_contents($root . '/database/migrations/20260828_create_revision_plan_requirement_submissions.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$route = file_get_contents($root . '/index.php');
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');
$resourceRoute = file_get_contents($root . '/views/user/requests/open-revision-resource.php');
foreach (['revision_plan_requirement_submissions', 'revision_plan_submission_files', 'uq_revision_submission_assignment_requirement', 'fk_revision_submission_assignment'] as $marker) if (!str_contains($migration, $marker)) throw new RuntimeException('Revision upload migration contract is missing: ' . $marker);
foreach (['mmh_revision_upload_requirement', 'mmh_revision_requirement_submission', 'is_uploaded_file', 'application/pdf', 'revision_plan_requirement_progress', 'assignment_context'] as $marker) if (!str_contains($service, $marker)) throw new RuntimeException('Revision upload service contract is missing: ' . $marker);
foreach (['revision-plan/{assignmentId}/requirement/{requirementId}/upload', 'revision-plan-upload.php'] as $marker) if (!str_contains($route, $marker)) throw new RuntimeException('Revision upload route is missing: ' . $marker);
foreach (['Upload answer PDF', 'Replace answer PDF', 'revision_files[]', 'Upload PDF files', 'Choose PDF files', 'Submitted files', 'revision-batch-navigation', 'Batch materials', 'revision-batch-strip', 'Coming soon'] as $marker) if (!str_contains($student, $marker)) throw new RuntimeException('Revision student upload/batch UI is missing: ' . $marker);
foreach (['batch_id', 'resourceBatchPicker', 'Optional shared-material grouping'] as $marker) if (!str_contains($admin, $marker)) throw new RuntimeException('Admin Batch material picker is missing: ' . $marker);
foreach (['requirement'] as $marker) if (!str_contains($resourceRoute, $marker)) throw new RuntimeException('Protected Revision resource route contract is missing: ' . $marker);
if (!str_contains($workflow, '20260828_create_revision_plan_requirement_submissions.php')) throw new RuntimeException('Deployment workflow does not run the Revision upload migration.');
if (str_contains($student, 'Submission will be available here in a later phase.')) throw new RuntimeException('The Phase 3A upload placeholder is still rendered.');
echo "revision_plan_upload=migration=present ownership=present pdf_validation=present replacement=present progress_completion=present batch_materials=present batch_visibility=present route=present placeholder_removed=present\n";
