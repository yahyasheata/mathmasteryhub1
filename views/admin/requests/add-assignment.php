<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AssignmentProgress.php';

header('Content-Type: application/json; charset=utf-8');

function add_assignment_response($success, $message, array $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => (bool) $success, 'status' => $success ? 1 : 0, 'message' => $message], $data));
    exit;
}

function add_assignment_new_id(mysqli $conn)
{
    do {
        $assignmentId = (string) random_int(10000, 99999);
        $stmt = $conn->prepare('SELECT id FROM assignments WHERE assignment_id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to create an assignment ID.');
        }
        $stmt->bind_param('s', $assignmentId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);
    return $assignmentId;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    add_assignment_response(false, 'Invalid request method.', [], 405);
}

$title = trim((string) ($_POST['assignment_title'] ?? ''));
$description = trim((string) ($_POST['assignment_description'] ?? ''));
$dueDate = trim((string) ($_POST['due_date'] ?? ''));
$courseId = mmh_assignment_progress_id($_POST['course_id'] ?? '');
if ($title === '' || $description === '' || $dueDate === '' || $courseId === null) {
    add_assignment_response(false, 'Please fill in all required assignment fields.', [], 422);
}
if (strtotime($dueDate) === false) {
    add_assignment_response(false, 'Choose a valid due date.', [], 422);
}
$lateSubmissionEnabled = !empty($_POST['late_submission_enabled']) ? 1 : 0;
$lateSubmissionUntil = trim((string) ($_POST['late_submission_until'] ?? ''));
if ($lateSubmissionEnabled && ($lateSubmissionUntil === '' || strtotime($lateSubmissionUntil) === false)) {
    add_assignment_response(false, 'Choose a valid late-submission deadline.', [], 422);
}
if (!$lateSubmissionEnabled) { $lateSubmissionUntil = null; }

try {
    $conn = db();
    mmh_ensure_learning_schema($conn);
    $scope = mmh_assignment_progress_requirement_scope($_POST['completion_requirement'] ?? 'optional');
    $rule = mmh_assignment_progress_completion_rule($_POST['completion_rule'] ?? 'submission');
    $minimumScore = null;
    if ($rule === 'minimum_score') {
        $rawMinimum = trim((string) ($_POST['minimum_score'] ?? ''));
        if ($rawMinimum === '' || !is_numeric($rawMinimum) || (float) $rawMinimum < 0) {
            add_assignment_response(false, 'Enter a valid minimum score.', [], 422);
        }
        $minimumScore = (float) $rawMinimum;
    }
    $context = mmh_assignment_progress_validate_context($conn, $courseId, $_POST['section_id'] ?? '', $_POST['item_id'] ?? '', $scope);
    if (empty($context['ok'])) {
        add_assignment_response(false, $context['message'] ?? 'Invalid course-content reference.', [], 422);
    }
    // Assignment definitions are owned by a Course Content assignment item.
    // Keep this legacy endpoint for old integrations, but never allow it to
    // create an unlinked standalone assignment.
    $itemIdRequest = trim((string) ($_POST['item_id'] ?? ''));
    if ($itemIdRequest === '') {
        add_assignment_response(false, 'Create assignments from the Assignment element inside Course Content.', [], 409);
    }
    $itemStmt = $conn->prepare("SELECT template_type, assignment_id FROM course_items WHERE course_id = ? AND item_id = ? AND (archived_at IS NULL OR archived_at = '') LIMIT 1");
    if (!$itemStmt) {
        add_assignment_response(false, 'Unable to validate the Course Content item.', [], 500);
    }
    $itemStmt->bind_param('ss', $courseId, $itemIdRequest);
    $itemStmt->execute();
    $itemRow = $itemStmt->get_result()->fetch_assoc();
    $itemStmt->close();
    if (!$itemRow || strtolower(trim((string) ($itemRow['template_type'] ?? ''))) !== 'classified_assignment') {
        add_assignment_response(false, 'Choose an Assignment element from Course Content.', [], 409);
    }
    if (trim((string) ($itemRow['assignment_id'] ?? '')) !== '') {
        add_assignment_response(false, 'This Assignment element already has an assignment. Edit it from Course Content.', [], 409);
    }

    $assignmentId = add_assignment_new_id($conn);
    $filePath = null;
    if (isset($_FILES['assignment_file']) && (int) ($_FILES['assignment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['assignment_file'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            add_assignment_response(false, 'The assignment file could not be uploaded.', [], 422);
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'doc', 'docx'], true)) {
            add_assignment_response(false, 'Unsupported file format. Only PDF or Word files are allowed.', [], 422);
        }
        $directory = 'uploads/static/assignments/';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            add_assignment_response(false, 'Unable to prepare the upload directory.', [], 500);
        }
        $filePath = $directory . 'assignment_' . $assignmentId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            add_assignment_response(false, 'The assignment file could not be uploaded.', [], 500);
        }
    }

    $sectionId = $context['section_id'] === '' ? null : $context['section_id'];
    $itemId = $context['item_id'] === '' ? null : $context['item_id'];
    $minimumScoreDb = $minimumScore === null ? null : number_format($minimumScore, 2, '.', '');
    $stmt = $conn->prepare('INSERT INTO assignments (assignment_id, assignment_title, assignment_description, due_date, late_submission_enabled, late_submission_until, file_path, course_id, section_id, item_id, completion_requirement, completion_rule, minimum_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the assignment save.');
    }
    $stmt->bind_param('ssssissssssss', $assignmentId, $title, $description, $dueDate, $lateSubmissionEnabled, $lateSubmissionUntil, $filePath, $courseId, $sectionId, $itemId, $scope, $rule, $minimumScoreDb);
    $saved = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    if (!$saved) {
        if ($filePath && is_file($filePath)) {
            @unlink($filePath);
        }
        throw new RuntimeException($error ?: 'Unable to save the assignment.');
    }
    add_assignment_response(true, 'Assignment added successfully.', ['assignment_id' => $assignmentId]);
} catch (Throwable $e) {
    add_assignment_response(false, 'Unable to save this assignment. Please try again.', [], 500);
}
