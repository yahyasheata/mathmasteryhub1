<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/LegacyHomework.php';
require_once 'inc/StudentCourseAccess.php';

header('Content-Type: application/json; charset=utf-8');

function legacy_homework_import_response($success, $message, array $data = [], $statusCode = 200)
{
    http_response_code((int) $statusCode);
    echo json_encode(array_merge(['success' => (bool) $success, 'status' => $success ? 1 : 0, 'message' => $message], $data));
    exit;
}

function legacy_homework_import_identifier($value, $maxLength = 40)
{
    $value = trim((string) $value);
    return $value !== '' && strlen($value) <= $maxLength && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) ? $value : null;
}

function legacy_homework_import_date($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Riyadh')))->format('Y-m-d H:i:s');
    } catch (Throwable $ignored) {
        return null;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_SESSION['admin'])) {
    legacy_homework_import_response(false, 'Unauthorized request.', [], 403);
}
if (!mmh_legacy_homework_csrf_valid($_POST['csrf_token'] ?? null)) {
    legacy_homework_import_response(false, 'Your admin session has expired. Refresh the page and try again.', [], 403);
}

$courseId = legacy_homework_import_identifier($_POST['course_id'] ?? '');
$sectionId = trim((string) ($_POST['section_id'] ?? ''));
$assignmentId = legacy_homework_import_identifier($_POST['assignment_id'] ?? '');
$studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$originalSubmittedAt = legacy_homework_import_date($_POST['original_submitted_at'] ?? '');
$notes = trim((string) ($_POST['import_notes'] ?? ''));
if ($courseId === null || $assignmentId === null || $studentId === false || $originalSubmittedAt === null) {
    legacy_homework_import_response(false, 'Choose a course, section, homework, student, and valid original submission date.', [], 422);
}
if (!isset($_FILES['legacy_files']) || !is_array($_FILES['legacy_files']['name'] ?? null)) {
    legacy_homework_import_response(false, 'Choose at least one PDF or Word file to import.', [], 422);
}

try {
    $conn = db();
    mmh_ensure_learning_schema($conn);

    $assignmentStmt = $conn->prepare('SELECT assignment_id, course_id, section_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
    if (!$assignmentStmt) {
        throw new RuntimeException('Unable to validate homework.');
    }
    $assignmentStmt->bind_param('ss', $assignmentId, $courseId);
    $assignmentStmt->execute();
    $assignment = $assignmentStmt->get_result()->fetch_assoc() ?: null;
    $assignmentStmt->close();
    if (!$assignment) {
        legacy_homework_import_response(false, 'The selected homework does not belong to the selected course.', [], 422);
    }
    $storedSection = (string) ($assignment['section_id'] ?? '');
    $requestedSection = $sectionId === '__general__' ? '' : $sectionId;
    if ($storedSection !== $requestedSection) {
        legacy_homework_import_response(false, 'The selected homework does not belong to the selected section.', [], 422);
    }
    $studentStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'user' LIMIT 1");
    if (!$studentStmt) {
        throw new RuntimeException('Unable to validate student.');
    }
    $studentStmt->bind_param('i', $studentId);
    $studentStmt->execute();
    $student = $studentStmt->get_result()->fetch_assoc() ?: null;
    $studentStmt->close();
    if (!$student || !student_course_access_enrolled($conn, (int) $studentId, $courseId)) {
        legacy_homework_import_response(false, 'The selected student is not enrolled in this course.', [], 422);
    }

    $adminUsername = (string) $_SESSION['admin'];
    $adminStmt = $conn->prepare('SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    if (!$adminStmt) {
        throw new RuntimeException('Unable to validate instructor.');
    }
    $adminStmt->bind_param('s', $adminUsername);
    $adminStmt->execute();
    $admin = $adminStmt->get_result()->fetch_assoc() ?: null;
    $adminStmt->close();
    if (!$admin) {
        legacy_homework_import_response(false, 'The active instructor account is unavailable.', [], 403);
    }
    $adminId = (int) $admin['user_id'];

    $directory = 'uploads/static/assignments/assignment_submissions/legacy_imports/';
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare import storage.');
    }
    $allowed = ['pdf', 'doc', 'docx'];
    $files = [];
    foreach ($_FILES['legacy_files']['name'] as $index => $originalName) {
        $error = (int) ($_FILES['legacy_files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $temporary = (string) ($_FILES['legacy_files']['tmp_name'][$index] ?? '');
        $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_uploaded_file($temporary) || !in_array($extension, $allowed, true)) {
            legacy_homework_import_response(false, 'Every imported file must be a valid PDF, DOC, or DOCX upload.', [], 422);
        }
        $safeName = 'legacy_submission_' . $assignmentId . '_' . (int) $studentId . '_' . bin2hex(random_bytes(10)) . '.' . $extension;
        $path = $directory . $safeName;
        if (!move_uploaded_file($temporary, $path)) {
            foreach ($files as $file) { if (is_file($file['file_path'])) { @unlink($file['file_path']); } }
            throw new RuntimeException('Unable to store an imported file.');
        }
        $files[] = ['file_path' => $path, 'original_filename' => substr((string) $originalName, 0, 255)];
    }
    if (!$files) {
        legacy_homework_import_response(false, 'Choose at least one PDF or Word file to import.', [], 422);
    }

    $conn->begin_transaction();
    try {
        $existingStmt = $conn->prepare('SELECT id FROM assignment_submissions WHERE assignment_id = ? AND student_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE');
        if (!$existingStmt) {
            throw new RuntimeException('Unable to check existing submissions.');
        }
        $existingStmt->bind_param('si', $assignmentId, $studentId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($existing) {
            throw new DomainException('This student already has a homework submission. Existing work was not overwritten.');
        }

        $source = 'legacy_import';
        $status = 'not_required';
        $importedAt = date('Y-m-d H:i:s');
        $primaryPath = $files[0]['file_path'];
        $insert = $conn->prepare('INSERT INTO assignment_submissions (assignment_id, student_id, file_path, submitted_at, self_score_status, submission_source, imported_by, imported_at, original_submitted_at, import_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) {
            throw new RuntimeException('Unable to create imported submission.');
        }
        $insert->bind_param('sissssisss', $assignmentId, $studentId, $primaryPath, $originalSubmittedAt, $status, $source, $adminId, $importedAt, $originalSubmittedAt, $notes);
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new RuntimeException($error ?: 'Unable to create imported submission.');
        }
        $submissionId = (int) $conn->insert_id;
        $insert->close();

        $fileInsert = $conn->prepare('INSERT INTO assignment_submission_files (submission_id, file_path, original_filename) VALUES (?, ?, ?)');
        if (!$fileInsert) {
            throw new RuntimeException('Unable to save imported files.');
        }
        foreach ($files as $file) {
            $fileInsert->bind_param('iss', $submissionId, $file['file_path'], $file['original_filename']);
            if (!$fileInsert->execute()) {
                $error = $fileInsert->error;
                $fileInsert->close();
                throw new RuntimeException($error ?: 'Unable to save imported files.');
            }
        }
        $fileInsert->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        foreach ($files as $file) { if (is_file($file['file_path'])) { @unlink($file['file_path']); } }
        if ($e instanceof DomainException) {
            legacy_homework_import_response(false, $e->getMessage(), [], 409);
        }
        throw $e;
    }

    legacy_homework_import_response(true, 'Legacy homework submission imported successfully.', [
        'submission_id' => $submissionId,
        'file_count' => count($files),
        'submission_source' => $source,
    ]);
} catch (Throwable $e) {
    legacy_homework_import_response(false, 'Unable to import this legacy submission. Please try again.', [], 500);
}
