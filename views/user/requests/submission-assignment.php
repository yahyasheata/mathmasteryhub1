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
if (empty($_POST['assignment_id']) || empty($_FILES['submission_file'])) {
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

    $file = $_FILES['submission_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        assignment_submission_response(false, 'File upload failed. Please choose the file again and retry.', [], 422);
    }
    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        assignment_submission_response(false, 'Unsupported file format. Only PDF or Word files are allowed.', [], 422);
    }

    $uploadDirectory = 'uploads/static/assignments/assignment_submissions/';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
        assignment_submission_response(false, 'Failed to prepare the upload directory. Please contact your teacher.', [], 500);
    }
    $fileName = 'submission_' . $assignmentId . '_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $filePath = $uploadDirectory . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        assignment_submission_response(false, 'File upload failed. Please try again.', [], 500);
    }

    $submittedAt = date('Y-m-d H:i:s');
    $oldFile = null;
    $legacyAttachmentFiles = [];
    $isResubmission = false;
    try {
        $conn->begin_transaction();

        // The existing non-unique assignment/student index lets InnoDB lock
        // this lookup range before deciding whether to update or insert.
        $existingStmt = $conn->prepare('SELECT id, file_path, submission_source FROM assignment_submissions WHERE assignment_id = ? AND student_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE');
        if (!$existingStmt) {
            throw new RuntimeException('Unable to prepare submission lookup.');
        }
        $existingStmt->bind_param('si', $assignmentId, $studentId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if ($existing) {
            $isResubmission = true;
            $oldFile = $existing['file_path'] ?? null;
            $submissionId = (int) $existing['id'];
            // A later student upload supersedes an instructor-imported packet.
            // Clear only its child attachments so the current LMS file remains
            // the visible submission; ordinary LMS resubmissions are unchanged.
            if (($existing['submission_source'] ?? '') === 'legacy_import') {
                $legacyFilesStmt = $conn->prepare('SELECT file_path FROM assignment_submission_files WHERE submission_id = ?');
                if ($legacyFilesStmt) {
                    $legacyFilesStmt->bind_param('i', $submissionId);
                    if ($legacyFilesStmt->execute()) {
                        $legacyResult = $legacyFilesStmt->get_result();
                        while ($legacyFile = $legacyResult->fetch_assoc()) { $legacyAttachmentFiles[] = (string) ($legacyFile['file_path'] ?? ''); }
                    }
                    $legacyFilesStmt->close();
                }
                $deleteLegacyFilesStmt = $conn->prepare('DELETE FROM assignment_submission_files WHERE submission_id = ?');
                if (!$deleteLegacyFilesStmt) { throw new RuntimeException('Unable to update imported attachments.'); }
                $deleteLegacyFilesStmt->bind_param('i', $submissionId);
                $deleteLegacyFilesStmt->execute();
                $deleteLegacyFilesStmt->close();
            }
            $updateStmt = $conn->prepare('UPDATE assignment_submissions SET file_path = ?, submitted_at = ?, self_score = ?, self_score_status = ?, grade = ?, verification_note = NULL, verified_at = NULL, verified_by = NULL, submission_source = ?, imported_by = NULL, imported_at = NULL, original_submitted_at = NULL, import_notes = NULL WHERE id = ?');
            if (!$updateStmt) {
                throw new RuntimeException('Unable to prepare submission update.');
            }
            $submissionSource = 'lms';
            $updateStmt->bind_param('sssdssi', $filePath, $submittedAt, $selfScore, $selfScoreStatus, $finalScore, $submissionSource, $submissionId);
            $saved = $updateStmt->execute();
            $updateStmt->close();
        } else {
            $submissionSource = 'lms';
            $insertStmt = $conn->prepare('INSERT INTO assignment_submissions (assignment_id, student_id, file_path, submitted_at, self_score, self_score_status, grade, submission_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$insertStmt) {
                throw new RuntimeException('Unable to prepare submission insert.');
            }
            $insertStmt->bind_param('sissdsss', $assignmentId, $studentId, $filePath, $submittedAt, $selfScore, $selfScoreStatus, $finalScore, $submissionSource);
            $saved = $insertStmt->execute();
            $insertStmt->close();
        }
        if (!$saved) {
            throw new RuntimeException('Unable to save submission.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        if (is_file($filePath)) {
            @unlink($filePath);
        }
        assignment_submission_response(false, 'Unable to save your submission. Please try again.', [], 500);
    }

    if ($oldFile && $oldFile !== $filePath && is_file($oldFile)) {
        @unlink($oldFile);
    }
    foreach (array_unique($legacyAttachmentFiles) as $legacyAttachmentFile) {
        if ($legacyAttachmentFile !== '' && $legacyAttachmentFile !== $filePath && is_file($legacyAttachmentFile)) {
            @unlink($legacyAttachmentFile);
        }
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
        ],
    ]);
} catch (Throwable $e) {
    assignment_submission_response(false, 'Unable to process this assignment submission.', [], 500);
}
