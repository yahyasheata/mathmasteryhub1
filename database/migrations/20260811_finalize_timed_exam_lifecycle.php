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
    if (!$stmt) throw new RuntimeException('Unable to inspect Timed Exam lifecycle columns.');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $exists;
};

$indexExists = static function (string $table, string $index) use ($conn): bool {
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect Timed Exam lifecycle indexes.');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $exists;
};

if (!$columnExists('timed_exam_attempts', 'attempt_scope')) {
    if (!$conn->query("ALTER TABLE timed_exam_attempts ADD COLUMN attempt_scope VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'primary' AFTER attempt_number")) {
        throw new RuntimeException('Unable to add Timed Exam attempt scope: ' . $conn->error);
    }
}

// Preserve the latest historical row as the primary attempt. Older rows are
// retained unchanged except for their new compatibility scope, allowing the
// uniqueness rule to be added without deleting or merging attempt history.
$normalize = "UPDATE timed_exam_attempts a
    INNER JOIN (
        SELECT timed_exam_id, student_id, MAX(id) AS primary_id
        FROM timed_exam_attempts
        WHERE attempt_scope = 'primary'
        GROUP BY timed_exam_id, student_id
        HAVING COUNT(*) > 1
    ) duplicates ON duplicates.timed_exam_id = a.timed_exam_id AND duplicates.student_id = a.student_id
    SET a.attempt_scope = CONCAT('legacy:', a.id)
    WHERE a.attempt_scope = 'primary' AND a.id <> duplicates.primary_id";
if (!$conn->query($normalize)) {
    throw new RuntimeException('Unable to preserve duplicate historical Timed Exam attempts: ' . $conn->error);
}

if (!$indexExists('timed_exam_attempts', 'uq_timed_exam_attempt_scope')) {
    if (!$conn->query('ALTER TABLE timed_exam_attempts ADD UNIQUE KEY uq_timed_exam_attempt_scope (timed_exam_id, student_id, attempt_scope)')) {
        throw new RuntimeException('Unable to add Timed Exam attempt-scope uniqueness: ' . $conn->error);
    }
}

if (!$columnExists('timed_exams', 'roster_finalized_at_utc')) {
    if (!$conn->query('ALTER TABLE timed_exams ADD COLUMN roster_finalized_at_utc DATETIME NULL AFTER recovery_allowed')) {
        throw new RuntimeException('Unable to add Timed Exam roster finalization marker: ' . $conn->error);
    }
}

if (!$indexExists('timed_exams', 'idx_timed_exam_roster_finalize')) {
    if (!$conn->query('ALTER TABLE timed_exams ADD KEY idx_timed_exam_roster_finalize (status, roster_finalized_at_utc, scheduled_start_at_utc)')) {
        throw new RuntimeException('Unable to add Timed Exam roster finalization index: ' . $conn->error);
    }
}

echo "Timed Exam deterministic lifecycle schema is ready. Historical attempts were preserved.\n";
