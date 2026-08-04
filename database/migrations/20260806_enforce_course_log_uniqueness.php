<?php
/**
 * Audit and enforce one enrollment row per student/course.
 * Existing duplicate rows are reported and intentionally block the index so
 * they can be reviewed without deleting historical data.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once __DIR__ . '/../../connection/config.php';
$conn = db();

$duplicates = $conn->query('SELECT user_id, course_id, COUNT(*) AS total FROM course_logs GROUP BY user_id, course_id HAVING COUNT(*) > 1');
if (!$duplicates) throw new RuntimeException('Unable to audit course_logs: ' . $conn->error);
$rows = $duplicates->fetch_all(MYSQLI_ASSOC);
if ($rows) {
    echo "Duplicate course_logs rows found; no unique index was added.\n";
    foreach ($rows as $row) echo sprintf("user_id=%s course_id=%s rows=%s\n", $row['user_id'], $row['course_id'], $row['total']);
    // Do not delete or merge rows automatically. The enrollment service uses
    // a transaction plus a per-student/course advisory lock until a reviewed
    // repair makes the unique index safe to add.
    exit(0);
}

$indexStmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_logs' AND INDEX_NAME = 'uniq_course_logs_user_course' LIMIT 1");
$indexStmt->execute();
$hasIndex = (bool) $indexStmt->get_result()->fetch_assoc();
$indexStmt->close();
if (!$hasIndex && !$conn->query('ALTER TABLE course_logs ADD UNIQUE KEY uniq_course_logs_user_course (user_id, course_id)')) {
    throw new RuntimeException('Unable to add course_logs uniqueness rule: ' . $conn->error);
}
echo "course_logs audit passed; uniqueness rule is present.\n";
