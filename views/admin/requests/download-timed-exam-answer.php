<?php
require_once 'connection/config.php';
require_once 'inc/TimedExam.php';
$conn = db();
$versionId = (int) ($versionId ?? 0);
$stmt = $conn->prepare('SELECT * FROM timed_exam_submission_versions WHERE id = ? LIMIT 1');
if (!$stmt) { http_response_code(500); exit('Unable to load answer.'); }
$stmt->bind_param('i', $versionId); $stmt->execute(); $version = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
if (!$version) { http_response_code(404); exit('Answer not found.'); }
mmh_timed_exam_answer_download($conn, $version);
