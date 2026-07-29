<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        isset($_POST['exam_id'], $_FILES['submission_file'], $_SESSION['username']) &&
        !empty($_POST['exam_id'])
    ) {
        $exam_id = $_POST['exam_id'];
        $username = $_SESSION['username'];
        $file = $_FILES['submission_file'];

        // Get user_id from username
        $user = getUserInfo($username);
        if (!$user) {
            echo json_encode([
                'status' => 0,
                'message' => 'User not found'
            ]);
            exit;
        }
        $student_id = $user->user_id;

        // Check exam due date before upload
        $due_query = "SELECT due_date FROM exams WHERE exam_id = ? LIMIT 1";
        $due_stmt = db()->prepare($due_query);
        $due_stmt->bind_param("s", $exam_id);
        $due_stmt->execute();
        $due_result = $due_stmt->get_result();
        if ($due_result && $due_result->num_rows > 0) {
            $due_row = $due_result->fetch_assoc();
            $due_date = $due_row['due_date'];
            if (strtotime($due_date) < time()) {
                echo json_encode([
                    'status' => 0,
                    'message' => 'The submission deadline for this exam has passed and solutions can no longer be uploaded.'
                ]);
                exit;
            }
        } else {
            echo json_encode([
                'status' => 0,
                'message' => 'The requested exam was not found.'
            ]);
            exit;
        }

        // Manual file upload (PDF, DOC, DOCX)
        $allowed = ['pdf', 'doc', 'docx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            echo json_encode(['status' => 0, 'message' => 'Unsupported file format. Only PDF or Word files are allowed.']);
            exit;
        }
        $upload_dir = 'uploads/static/exams/exam_submissions/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                echo json_encode(['status' => 0, 'message' => 'Failed to create the upload directory. Please check directory permissions.']);
                exit;
            }
        }
        $new_name = 'submission_' . $exam_id . '_' . $student_id . '_' . time() . '.' . $ext;
        $target = $upload_dir . $new_name;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $error = error_get_last();
            echo json_encode([
                'status' => 0,
                'message' => 'File upload failed.',
                'php_error' => $error,
                'debug_tmp_name' => $file['tmp_name'],
                'debug_target' => $target
            ]);
            exit;
        }
        $file_path = $target;
        $submitted_at = date('Y-m-d H:i:s');

        // Check if a submission already exists for this exam and student
        $check_query = "SELECT id, file_path FROM exam_submissions WHERE exam_id = ? AND student_id = ? LIMIT 1";
        $check_stmt = db()->prepare($check_query);
        $check_stmt->bind_param("si", $exam_id, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result && $check_result->num_rows > 0) {
            // Update existing submission (and optionally delete old file)
            $row = $check_result->fetch_assoc();
            $old_file = $row['file_path'];
            $update_query = "UPDATE exam_submissions SET file_path = ?, submitted_at = ? WHERE id = ?";
            $update_stmt = db()->prepare($update_query);
            $update_stmt->bind_param("ssi", $file_path, $submitted_at, $row['id']);
            $success = $update_stmt->execute();
            if ($success) {
                // Optionally delete old file
                if ($old_file && file_exists($old_file)) { @unlink($old_file); }
                echo json_encode([
                    'status' => 1,
                    'message' => 'Exam submission updated successfully'
                ]);
            } else {
                echo json_encode([
                    'status' => 0,
                    'message' => 'An error occurred while updating the submission',
                    'reason' => db()->error
                ]);
            }
        } else {
            // Insert new submission
            $query = "INSERT INTO exam_submissions (exam_id, student_id, file_path, submitted_at) VALUES (?, ?, ?, ?)";
            $stmt = db()->prepare($query);
            $stmt->bind_param("siss", $exam_id, $student_id, $file_path, $submitted_at);

            if ($stmt->execute()) {
                echo json_encode([
                    'status' => 1,
                    'message' => 'Exam submission uploaded successfully'
                ]);
            } else {
                echo json_encode([
                    'status' => 0,
                    'message' => 'An error occurred while saving the data',
                    'reason' => db()->error
                ]);
            }
        }
    } else {
        echo json_encode([
            'status' => 0,
            'message' => 'The data is incomplete'
        ]);
    }
} else {
    echo json_encode([
        'status' => 0,
        'message' => 'Invalid request method'
    ]);
}
?>
