<?php
require_once 'connection/config.php';
require_once 'inc/TimedExam.php';
$conn = db();
$attemptId = filter_var($attemptId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($attemptId === false) { http_response_code(404); exit('Marked paper not found.'); }
$paper = mmh_timed_exam_marked_paper_for_admin($conn, (int) $attemptId);
if (!$paper) { http_response_code(404); exit('Marked paper not found.'); }
mmh_timed_exam_marked_paper_serve($conn, $paper, (string) ($_GET['download'] ?? '') === '1');
