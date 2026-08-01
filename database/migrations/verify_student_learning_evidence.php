<?php
declare(strict_types=1);

/**
 * Verify the deployed Learning Journey schema and exercise its write path
 * without leaving any row behind. This is intended for the production deploy.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This verification can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$databaseResult = $conn->query('SELECT DATABASE() AS database_name');
$databaseName = $databaseResult ? (string) (($databaseResult->fetch_assoc()['database_name'] ?? '')) : '';
$expectedColumns = [
    'id', 'user_id', 'course_id', 'section_id', 'item_id', 'assignment_id',
    'occurrence_id', 'entity_key', 'item_kind', 'state', 'source', 'recorded_by',
    'recorded_at', 'activity_at', 'note', 'created_at', 'updated_at',
];

$columnResult = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_learning_evidence'
    ORDER BY ORDINAL_POSITION");
$actualColumns = [];
if ($columnResult) {
    while ($column = $columnResult->fetch_assoc()) {
        $actualColumns[] = (string) $column['COLUMN_NAME'];
    }
}
if ($actualColumns !== $expectedColumns) {
    throw new RuntimeException('student_learning_evidence schema verification failed.');
}

$conn->begin_transaction();
$entityKey = 'deploy-probe:' . bin2hex(random_bytes(8));
$userId = 0;
$courseId = '__deploy_probe__';
$sectionId = '';
$itemId = '__deploy_probe__';
$assignmentId = '';
$occurrenceId = '';
$itemKind = 'lesson';
$state = 'completed';
$source = 'manual';
$recordedBy = 0;
$note = '';
$insert = $conn->prepare("INSERT INTO student_learning_evidence
    (user_id, course_id, section_id, item_id, assignment_id, occurrence_id, entity_key,
     item_kind, state, source, recorded_by, note)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$insert) {
    throw new RuntimeException('Learning Journey transaction probe could not prepare insert.');
}
$insert->bind_param(
    'isssssssssis',
    $userId, $courseId, $sectionId, $itemId, $assignmentId, $occurrenceId,
    $entityKey, $itemKind, $state, $source, $recordedBy, $note
);
if (!$insert->execute()) {
    throw new RuntimeException('Learning Journey transaction probe insert failed: ' . $insert->error);
}
$probeId = (int) $conn->insert_id;
$insert->close();

$delete = $conn->prepare('DELETE FROM student_learning_evidence WHERE id = ? LIMIT 1');
if (!$delete) {
    throw new RuntimeException('Learning Journey transaction probe could not prepare delete.');
}
$delete->bind_param('i', $probeId);
if (!$delete->execute() || $delete->affected_rows !== 1) {
    throw new RuntimeException('Learning Journey transaction probe delete failed.');
}
$delete->close();
$conn->rollback();

echo 'database=' . $databaseName
    . ' table=student_learning_evidence columns=' . count($actualColumns)
    . " transaction_probe=passed\n";
