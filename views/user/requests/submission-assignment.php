<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/AssignmentProgress.php';
require_once 'inc/StudentCourseCsrf.php';

header('Content-Type: application/json; charset=utf-8');

function assignment_submission_response($success, $message, array $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assignment_submission_response(false, 'Invalid request method.', [], 405);
}
if (empty($_SESSION['username'])) {
    assignment_submission_response(false, 'Please login first.', [], 401);
}
if (!student_course_csrf_valid($_POST['csrf_token'] ?? null)) {
    assignment_submission_response(false, 'Your session has expired. Please refresh the course and try again.', [], 403);
}
if (empty($_POST['assignment_id']) || (empty($_FILES['submission_files']) && empty($_FILES['submission_file']))) {
    assignment_submission_response(false, 'The data is incomplete.', [], 422);
}

$assignmentId = student_course_access_identifier($_POST['assignment_id'], 40);
if ($assignmentId === null) {
    assignment_submission_response(false, 'Invalid assignment reference.', [], 422);
}

try {
    $conn = db();
    mmh_ensure_learning_schema($conn);

    $studentId = student_course_access_student_id($conn, $_SESSION['username']);
    if ($studentId === null) {
        assignment_submission_response(false, 'Your account is unavailable.', [], 403);
    }

    $assignment = student_course_access_assignment($conn, $assignmentId);
    if (!$assignment) {
        assignment_submission_response(false, 'The requested assignment was not found.', [], 404);
    }
    $course = student_course_access_course($conn, $assignment['course_id'] ?? '');
    if (!$course || (string) $course['course_id'] !== (string) $assignment['course_id']) {
        assignment_submission_response(false, 'This assignment is unavailable.', [], 403);
    }
    $courseId = (string) $course['course_id'];
    if (!student_course_access_enrolled($conn, $studentId, $courseId)) {
        assignment_submission_response(false, 'You are not enrolled in this course.', [], 403);
    }

    $assignmentSectionId = student_course_access_normalize_section_id($assignment['section_id'] ?? '');
    if ($assignmentSectionId === null) {
        assignment_submission_response(false, 'This assignment has an invalid section.', [], 403);
    }
    if ($assignmentSectionId !== '') {
        $sectionState = student_course_access_section_state($conn, $course, $assignmentSectionId, $studentId);
        if (!$sectionState) {
            assignment_submission_response(false, 'This assignment section is unavailable.', [], 403);
        }
        if (!empty($sectionState['state']['locked'])) {
            assignment_submission_response(false, 'This assignment section is not available yet.', [], 403);
        }
    }

    $assignmentItemId = trim((string) ($assignment['item_id'] ?? ''));
    if ($assignmentItemId !== '') {
        $assignmentItem = student_course_access_item($conn, $courseId, $assignmentItemId);
        if (!$assignmentItem || !student_course_access_assignment_matches_item($assignment, $assignmentItem)) {
            assignment_submission_response(false, 'This assignment lesson is unavailable.', [], 403);
        }
    }

    if (!mmh_assignment_submission_open($assignment)) {
        assignment_submission_response(false, 'The submission deadline for this assignment has passed and solutions can no longer be uploaded.', [], 422);
    }

    $maxScore = $assignment['max_score'] !== null && $assignment['max_score'] !== '' ? (float) $assignment['max_score'] : null;
    $scoreMode = mmh_academic_score_mode_from_flags($assignment['allow_self_score'] ?? 0, $assignment['require_teacher_verification'] ?? 0);
    $selfScore = null;
    $selfScoreStatus = 'not_required';
    $finalScore = null;
    if ($scoreMode !== 'disabled') {
        $rawSelfScore = isset($_POST['self_score']) ? trim((string) $_POST['self_score']) : '';
        if ($rawSelfScore === '' || !is_numeric($rawSelfScore)) {
            assignment_submission_response(false, 'Please enter your numeric self-reported score.', [], 422);
        }
        $selfScore = (float) $rawSelfScore;
        if ($selfScore < 0) {
            assignment_submission_response(false, 'Self score cannot be below zero.', [], 422);
        }
        if ($maxScore !== null && $selfScore > $maxScore) {
            assignment_submission_response(false, 'Self score cannot exceed the maximum score of ' . rtrim(rtrim(number_format($maxScore, 2, '.', ''), '0'), '.') . '.', [], 422);
        }
        if ($scoreMode === 'accept_automatically') {
            $selfScoreStatus = 'auto_accepted';
            $finalScore = number_format($selfScore, 2, '.', '');
        } else {
            $selfScoreStatus = 'pending_verification';
        }
    }

    // Accept the new normalized multi-file field while preserving the legacy
    // single-file field for already-open pages and older clients.
    $rawFiles = $_FILES['submission_files'] ?? $_FILES['submission_file'] ?? null;
    if (!is_array($rawFiles) || !isset($rawFiles['name'])) {
        assignment_submission_response(false, 'Choose at least one answer file.', [], 422);
    }
    $fileEntries = [];
    $names = is_array($rawFiles['name']) ? $rawFiles['name'] : [$rawFiles['name']];
    $tmpNames = is_array($rawFiles['tmp_name'] ?? null) ? $rawFiles['tmp_name'] : [$rawFiles['tmp_name'] ?? ''];
    $errors = is_array($rawFiles['error'] ?? null) ? $rawFiles['error'] : [$rawFiles['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($rawFiles['size'] ?? null) ? $rawFiles['size'] : [$rawFiles['size'] ?? 0];
    $maxFiles = mmh_assignment_submission_max_files();
    $maxBytes = mmh_assignment_submission_max_file_bytes();
    $allowedMimes = mmh_assignment_submission_allowed_mimes();
    if (count($names) < 1 || count($names) > $maxFiles) {
        assignment_submission_response(false, 'Choose between 1 and ' . $maxFiles . ' answer files.', [], 422);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($names as $index => $originalName) {
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        $temporary = (string) ($tmpNames[$index] ?? '');
        $size = (int) ($sizes[$index] ?? 0);
        $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_uploaded_file($temporary) || $size <= 0 || $size > $maxBytes || !isset($allowedMimes[$extension])) {
            assignment_submission_response(false, 'Every answer file must be a valid PDF or Word file within the upload limits.', [], 422);
        }
        $detectedMime = strtolower((string) $finfo->file($temporary));
        if (!in_array($detectedMime, $allowedMimes[$extension], true)) {
            assignment_submission_response(false, 'The uploaded file type could not be verified. Please choose a valid PDF or Word file.', [], 422);
        }
        $fileEntries[] = [
            'temporary' => $temporary,
            'original_filename' => substr(trim((string) $originalName), 0, 255),
            'extension' => $extension,
            'mime_type' => $detectedMime,
            'file_size' => $size,
            'sort_order' => count($fileEntries),
        ];
    }

    $uploadDirectory = 'uploads/static/assignments/assignment_submissions/';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
        assignment_submission_response(false, 'Failed to prepare the upload directory. Please contact your teacher.', [], 500);
    }
    $newPaths = [];
    foreach ($fileEntries as $index => &$entry) {
        $fileName = 'submission_' . $assignmentId . '_' . $studentId . '_' . time() . '_' . $index . '_' . bin2hex(random_bytes(6)) . '.' . $entry['extension'];
        $entry['file_path'] = $uploadDirectory . $fileName;
        if (!move_uploaded_file($entry['temporary'], $entry['file_path'])) {
            foreach ($newPaths as $path) { if (is_file($path)) unlink($path); }
            assignment_submission_response(false, 'File upload failed. Please try again.', [], 500);
        }
        $newPaths[] = $entry['file_path'];
    }
    unset($entry);

    $submittedAt = date('Y-m-d H:i:s');
    $oldPaths = [];
    $isResubmission = false;
    try {
        $conn->begin_transaction();
        $existingStmt = $conn->prepare('SELECT id, file_path FROM assignment_submissions WHERE assignment_id = ? AND student_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE');
        if (!$existingStmt) throw new RuntimeException('Unable to prepare submission lookup.');
        $existingStmt->bind_param('si', $assignmentId, $studentId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($existing) {
            $isResubmission = true;
            if (!empty($existing['file_path'])) $oldPaths[] = (string) $existing['file_path'];
            $submissionId = (int) $existing['id'];
            $oldFilesStmt = $conn->prepare('SELECT file_path FROM assignment_submission_files WHERE submission_id = ?');
            if ($oldFilesStmt) {
                $oldFilesStmt->bind_param('i', $submissionId);
                $oldFilesStmt->execute();
                $oldResult = $oldFilesStmt->get_result();
                while ($oldFile = $oldResult->fetch_assoc()) if (!empty($oldFile['file_path'])) $oldPaths[] = (string) $oldFile['file_path'];
                $oldFilesStmt->close();
            }
            $deleteFilesStmt = $conn->prepare('DELETE FROM assignment_submission_files WHERE submission_id = ?');
            if (!$deleteFilesStmt) throw new RuntimeException('Unable to replace submission files.');
            $deleteFilesStmt->bind_param('i', $submissionId);
            if (!$deleteFilesStmt->execute()) throw new RuntimeException('Unable to replace submission files.');
            $deleteFilesStmt->close();
            $primaryPath = $newPaths[0];
        } else {
            $submissionSource = 'lms';
            $primaryPath = $newPaths[0];
            $insertStmt = $conn->prepare('INSERT INTO assignment_submissions (assignment_id, student_id, file_path, submitted_at, self_score, self_score_status, grade, submission_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$insertStmt) throw new RuntimeException('Unable to prepare submission insert.');
            $insertStmt->bind_param('sissdsss', $assignmentId, $studentId, $primaryPath, $submittedAt, $selfScore, $selfScoreStatus, $finalScore, $submissionSource);
            if (!$insertStmt->execute()) throw new RuntimeException('Unable to save submission.');
            $submissionId = (int) $conn->insert_id;
            $insertStmt->close();
        }
        if (!isset($submissionId) || $submissionId <= 0) throw new RuntimeException('Unable to save submission.');
        if ($existing) {
            // Execute the replacement update after the statement shape is finalized.
            $updateStmt = $conn->prepare("UPDATE assignment_submissions SET file_path = ?, submitted_at = ?, self_score = ?, self_score_status = ?, grade = ?, verification_note = NULL, verified_at = NULL, verified_by = NULL, submission_source = 'lms', imported_by = NULL, imported_at = NULL, original_submitted_at = NULL, import_notes = NULL WHERE id = ?");
            if (!$updateStmt) throw new RuntimeException('Unable to prepare submission update.');
            $primaryPath = $newPaths[0];
            $updateStmt->bind_param('ssdssi', $primaryPath, $submittedAt, $selfScore, $selfScoreStatus, $finalScore, $submissionId);
            if (!$updateStmt->execute()) throw new RuntimeException('Unable to update submission.');
            $updateStmt->close();
        }
        $fileInsert = $conn->prepare('INSERT INTO assignment_submission_files (submission_id, file_path, original_filename, mime_type, file_size, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$fileInsert) throw new RuntimeException('Unable to save submission files.');
        foreach ($fileEntries as $entry) {
            $fileInsert->bind_param('isssii', $submissionId, $entry['file_path'], $entry['original_filename'], $entry['mime_type'], $entry['file_size'], $entry['sort_order']);
            if (!$fileInsert->execute()) throw new RuntimeException('Unable to save submission files.');
        }
        $fileInsert->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        foreach ($newPaths as $path) { if (is_file($path)) unlink($path); }
        assignment_submission_response(false, 'Unable to save your submission. Please try again.', [], 500);
    }
    foreach (array_unique($oldPaths) as $oldPath) {
        if ($oldPath !== '' && !in_array($oldPath, $newPaths, true) && is_file($oldPath)) unlink($oldPath);
    }


    mmh_log_event($conn, $studentId, $isResubmission ? 'homework_resubmitted' : 'homework_submitted', [
        'course_id' => $courseId,
        'section_id' => $assignmentSectionId,
        'item_id' => $assignmentItemId,
        'assignment_id' => $assignmentId,
        'meta' => [
            'self_score' => $selfScore,
            'self_score_status' => $selfScoreStatus,
            'final_score' => $finalScore,
            'file_count' => count($fileEntries),
        ],
    ]);

    $lifecycle = mmh_assignment_progress_evaluate($assignment, [
        'submitted_at' => $submittedAt,
        'grade' => $finalScore,
        'self_score' => $selfScore,
        'self_score_status' => $selfScoreStatus,
    ]);
    assignment_submission_response(true, $isResubmission ? 'Assignment submission updated successfully' : 'Assignment submission uploaded successfully', [
        'lifecycle' => [
            'state' => $lifecycle['state'],
            'label' => $lifecycle['label'],
            'reason' => $lifecycle['reason'],
            'complete' => $lifecycle['complete'],
        ],
        'submission' => [
            'submitted_at' => $submittedAt,
            'self_score' => $selfScore,
            'max_score' => $maxScore,
            'verification_status' => $selfScoreStatus,
            'final_score' => $finalScore,
            'file_count' => count($fileEntries),
        ],
    ]);
} catch (Throwable $e) {
    assignment_submission_response(false, 'Unable to process this assignment submission.', [], 500);
}
