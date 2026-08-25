<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

/*
 * Read-only production-compatible integration check. Supply an existing safe
 * assignment and student fixture explicitly; the test never inserts, edits,
 * or deletes database rows.
 *
 * MMH_REVISION_ASSIGNMENT_ID=123 MMH_REVISION_STUDENT_ID=456 php tests/revision_plan_assignment_visibility_db_test.php
 */
$assignmentId = (int) getenv('MMH_REVISION_ASSIGNMENT_ID');
$studentId = (int) getenv('MMH_REVISION_STUDENT_ID');
if ($assignmentId <= 0 || $studentId <= 0) {
    echo "assignment_visibility_db=skipped (provide MMH_REVISION_ASSIGNMENT_ID and MMH_REVISION_STUDENT_ID)\n";
    exit(0);
}

require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';
$rows = mmh_revision_student_assignments(db(), $studentId);
$match = null;
foreach ($rows as $row) if ((int) ($row['id'] ?? 0) === $assignmentId) { $match = $row; break; }
if (!$match) throw new RuntimeException('The supplied assignment was not returned for the supplied canonical student ID.');
if ((string) ($match['status'] ?? '') !== 'active') throw new RuntimeException('The supplied assignment is not active.');
if (!in_array((string) ($match['schedule_state'] ?? ''), ['upcoming', 'active', 'past'], true)) throw new RuntimeException('The assignment schedule state is invalid.');
echo "assignment_visibility_db=pass assignment_id={$assignmentId} student_id={$studentId} schedule_state=" . $match['schedule_state'] . "\n";
