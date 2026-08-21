<?php
require_once __DIR__ . '/../../../connection/config.php';
require_once __DIR__ . '/../../../__init.php';
require_once __DIR__ . '/../../../inc/AssignmentSubmissionFiles.php';
if (empty($_SESSION['admin'])) { http_response_code(403); exit('Administrator access is required.'); }
$fileId = filter_var($fileId ?? $_GET['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($fileId === false) { http_response_code(404); exit('File not found.'); }
mmh_assignment_submission_file_serve(db(), (int) $fileId, true);
