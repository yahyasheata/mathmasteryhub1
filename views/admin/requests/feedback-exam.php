<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['submission_id']) || empty($_POST['submission_id'])) {
        echo json_encode(['status' => 0, 'message' => 'Submission ID is missing.']);
        exit;
    }
    $submission_id = intval($_POST['submission_id']);
    $grade = isset($_POST['grade']) ? trim($_POST['grade']) : null;
    if (!isset($_FILES['feedback_file']) || $_FILES['feedback_file']['error'] !== 0) {
        echo json_encode(['status' => 0, 'message' => 'Please choose a valid PDF file.']);
        exit;
    }
    $ext = strtolower(pathinfo($_FILES['feedback_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        echo json_encode(['status' => 0, 'message' => 'Only PDF files are allowed.']);
        exit;
    }
    $upload_dir = 'uploads/static/exams/exam_submissions/feedbacks/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            echo json_encode(['status' => 0, 'message' => 'Failed to create the upload directory.']);
            exit;
        }
    }
    // Fetch old feedback file path
    $old_file_path = null;
    $query = "SELECT feedback FROM exam_submissions WHERE id = ?";
    $stmt = db()->prepare($query);
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $stmt->bind_result($old_file_path);
    $stmt->fetch();
    $stmt->close();
    // Remove old file if exists and is a file
    if ($old_file_path && file_exists($old_file_path) && is_file($old_file_path)) {
        @unlink($old_file_path);
    }
    $new_name = 'feedback_' . $submission_id . '_' . time() . '.pdf';
    $target = $upload_dir . $new_name;
    if (!move_uploaded_file($_FILES['feedback_file']['tmp_name'], $target)) {
        echo json_encode(['status' => 0, 'message' => 'File upload failed.']);
        exit;
    }
    $file_path = 'uploads/static/exams/exam_submissions/feedbacks/' . $new_name;
    // Update DB: save feedback file path and grade in feedback and grade columns
    $query = "UPDATE exam_submissions SET feedback = ?, grade = ? WHERE id = ?";
    $stmt = db()->prepare($query);
    $stmt->bind_param('ssi', $file_path, $grade, $submission_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 1, 'message' => 'Feedback file and grade updated successfully', 'file_path' => $file_path]);
    } else {
        echo json_encode(['status' => 0, 'message' => 'Database update error.']);
    }
} else {
    echo json_encode(['status' => 0, 'message' => 'Invalid request method']);
}
?>
