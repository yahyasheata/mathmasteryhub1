<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/AdminAssessmentService.php';

header('Content-Type: application/json; charset=utf-8');

function assignment_feedback_response($success, $message, array $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assignment_feedback_response(false, 'Invalid request method.');
}

if (empty($_POST['submission_id'])) {
    assignment_feedback_response(false, 'Submission ID is missing.');
}

$conn = db();
mmh_ensure_learning_schema($conn);
$submission_id = (int) $_POST['submission_id'];
$action = trim((string) ($_POST['verification_action'] ?? 'verify'));
if (!in_array($action, ['verify', 'correct', 'reject'], true)) {
    assignment_feedback_response(false, 'Choose a valid verification action.');
}

$submission = mmh_admin_assignment_submission_context($conn, $submission_id);
if (!$submission) {
    assignment_feedback_response(false, 'Submission not found.');
}

$final_score = null;
if ($action === 'verify') {
    $final_score = $submission['self_score'] !== null && $submission['self_score'] !== '' ? (float) $submission['self_score'] : null;
} elseif ($action === 'correct') {
    $posted_score = trim((string) ($_POST['final_score'] ?? ''));
    if ($posted_score === '' || !is_numeric($posted_score) || (float) $posted_score < 0) {
        assignment_feedback_response(false, 'Enter a valid corrected score.');
    }
    $final_score = (float) $posted_score;
}

$max_score = $submission['max_score'] !== null && $submission['max_score'] !== '' ? (float) $submission['max_score'] : null;
if ($final_score !== null && $max_score !== null && $final_score > $max_score) {
    assignment_feedback_response(false, 'Final verified score cannot exceed the maximum score of ' . rtrim(rtrim(number_format($max_score, 2, '.', ''), '0'), '.') . '.');
}

$verification_status = $action === 'reject' ? 'rejected' : ($action === 'correct' ? 'corrected_by_teacher' : 'verified');
$verification_note = trim((string) ($_POST['verification_note'] ?? ''));
$feedback_path = $submission['feedback'] ?? null;

if (isset($_FILES['feedback_file']) && (int) $_FILES['feedback_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ((int) $_FILES['feedback_file']['error'] !== UPLOAD_ERR_OK) {
        assignment_feedback_response(false, 'The feedback file could not be uploaded.');
    }
    $ext = strtolower(pathinfo($_FILES['feedback_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        assignment_feedback_response(false, 'Only PDF feedback files are allowed.');
    }
    $upload_dir = 'uploads/static/assignments/assignment_submissions/feedbacks/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) {
        assignment_feedback_response(false, 'Failed to create the feedback upload directory.');
    }
    $new_name = 'feedback_' . $submission_id . '_' . time() . '.pdf';
    $target = $upload_dir . $new_name;
    if (!move_uploaded_file($_FILES['feedback_file']['tmp_name'], $target)) {
        assignment_feedback_response(false, 'Feedback file upload failed.');
    }
    if (!empty($feedback_path) && file_exists($feedback_path) && is_file($feedback_path)) {
        @unlink($feedback_path);
    }
    $feedback_path = $target;
}

$verified_by = null;
if (!empty($_SESSION['admin'])) {
    $admin = getUserInfo($_SESSION['admin']);
    if ($admin && isset($admin->user_id)) {
        $verified_by = (int) $admin->user_id;
    }
}
$grade = $final_score === null ? null : number_format($final_score, 2, '.', '');
if (!mmh_admin_assignment_save_verification($conn, $submission_id, $feedback_path, $grade, $verification_status, $verification_note, $verified_by)) {
    assignment_feedback_response(false, 'Database update error.');
}

mmh_log_event($conn, (int) $submission['student_id'], $action === 'reject' ? 'homework_rejected' : 'homework_approved', [
    'course_id' => $submission['course_id'] ?? '',
    'section_id' => $submission['section_id'] ?? '',
    'item_id' => $submission['item_id'] ?? '',
    'assignment_id' => $submission['assignment_id'] ?? '',
    'meta' => [
        'verification_status' => $verification_status,
        'final_score' => $final_score,
    ],
]);

assignment_feedback_response(true, 'Assignment verification saved successfully.', [
    'verification_status' => $verification_status,
    'final_score' => $final_score,
    'file_path' => $feedback_path,
]);
