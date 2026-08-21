<?php
require_once __DIR__ . '/../../../connection/config.php';
require_once __DIR__ . '/../../../__init.php';
require_once __DIR__ . '/../../../inc/AssignmentSubmissionFiles.php';
if (empty($_SESSION['username'])) { http_response_code(401); exit('Sign in required.'); }
$fileId = filter_var($fileId ?? $_GET['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($fileId === false) { http_response_code(404); exit('File not found.'); }
mmh_assignment_submission_file_serve(db(), (int) $fileId, false);
