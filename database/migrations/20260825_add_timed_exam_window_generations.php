<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();
$columnExists = static function (string $table, string $column) use ($conn): bool {
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect Timed Exam window schema.');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
};

if (!$columnExists('timed_exams', 'attempt_generation')) {
    if (!$conn->query("ALTER TABLE timed_exams ADD COLUMN attempt_generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER recovery_allowed")) {
        throw new RuntimeException('Unable to add Timed Exam attempt_generation: ' . $conn->error);
    }
}
$hasRosterMarker = $columnExists('timed_exams', 'roster_finalized_at_utc');
if (!$columnExists('timed_exams', 'roster_finalized_generation')) {
    $position = $hasRosterMarker ? ' AFTER roster_finalized_at_utc' : '';
    if (!$conn->query("ALTER TABLE timed_exams ADD COLUMN roster_finalized_generation INT UNSIGNED NULL{$position}")) {
        throw new RuntimeException('Unable to add Timed Exam roster_finalized_generation: ' . $conn->error);
    }
}

// Existing roster markers belong to the original generation. This is metadata
// only; no attempt, answer, grade, feedback, or timestamp is rewritten.
if ($hasRosterMarker && !$conn->query("UPDATE timed_exams SET roster_finalized_generation = 1 WHERE roster_finalized_at_utc IS NOT NULL AND roster_finalized_generation IS NULL")) {
    throw new RuntimeException('Unable to backfill Timed Exam roster generation metadata: ' . $conn->error);
}

echo "Timed Exam window-generation schema is ready.\n";
