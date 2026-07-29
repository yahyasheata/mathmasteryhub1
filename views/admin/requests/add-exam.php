<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $required = isset($_POST['exam_title'], $_POST['exam_description'], $_POST['due_date'], $_POST['course_id'])
        && !empty($_POST['exam_title']) && !empty($_POST['exam_description']) && !empty($_POST['due_date']) && !empty($_POST['course_id']);

    if ($required) {
        $exam_title = $_POST['exam_title'];
        $exam_description = $_POST['exam_description'];
        $due_date = $_POST['due_date'];
        $course_id = $_POST['course_id'];
        $exam_id = rand(10000, 99999);
        $file_path = null;

        // Handle file upload
        if (isset($_FILES['exam_file']) && $_FILES['exam_file']['error'] == 0) {
            $allowed = ['pdf', 'doc', 'docx'];
            $ext = strtolower(pathinfo($_FILES['exam_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                echo json_encode(['status' => 0, 'message' => 'Unsupported file format. Only PDF or Word allowed.']);
                exit;
            }
            $upload_dir = 'uploads/static/exams/';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    echo json_encode(['status' => 0, 'message' => 'Failed to create upload directory. Check folder permissions.']);
                    exit;
                }
            }
            $new_name = 'exam_' . $exam_id . '_' . time() . '.' . $ext;
            $target = $upload_dir . $new_name;
            if (!move_uploaded_file($_FILES['exam_file']['tmp_name'], $target)) {
                $error = error_get_last();
                echo json_encode([
                    'status' => 0,
                    'message' => 'File upload failed.',
                    'php_error' => $error,
                    'debug_tmp_name' => $_FILES['exam_file']['tmp_name'],
                    'debug_target' => $target
                ]);
                exit;
            }
            $file_path = 'uploads/static/exams/' . $new_name;
        }

        // Insert into DB
        $query = "INSERT INTO exams (exam_id, exam_title, exam_description, due_date, file_path, course_id) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = db()->prepare($query);
        $stmt->bind_param('ssssss', $exam_id, $exam_title, $exam_description, $due_date, $file_path, $course_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 1, 'message' => 'Exam added successfully']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error saving data', 'reason' => db()->error]);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'Please fill in all required fields']);
    }
} else {
    echo json_encode(['status' => 0, 'message' => 'Invalid request method']);
}

// NOTE: If you still have issues, check php.ini for upload_max_filesize and post_max_size limits.
?>
