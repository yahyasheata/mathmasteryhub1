<?php
// views/public/requests/purchase-fawaterk-course.php
header('Content-Type: application/json');
session_start();

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/FawaterkPayment.php';
require_once 'inc/TransactionLog.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        'status' => 0,
        'message' => 'Invalid request method',
        'reason' => 'POST method required.'
    ]);
    exit;
}

if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    echo json_encode([
        'status' => 0,
        'message' => 'Error',
        'reason' => 'You must log in first to purchase the course'
    ]);
    exit;
}

if (!isset($_POST['course_id']) || empty($_POST['course_id'])) {
    echo json_encode([
        'status' => 0,
        'message' => 'Error',
        'reason' => 'Course ID is required'
    ]);
    exit;
}

$conn = db();
$username = $_SESSION['username'];
$user_id = getUserInfo($username)->user_id;
$course_id = intval($_POST['course_id']);

// Check course price
$courseStmt = $conn->prepare('SELECT course_price, course_title FROM courses WHERE course_id = ? AND archived_at IS NULL LIMIT 1');
$courseStmt->bind_param('i', $course_id);
$courseStmt->execute();
$course = $courseStmt->get_result()->fetch_assoc();
$courseStmt->close();
if ($course) {
    if (floatval($course['course_price']) == 0) {
        // Free course: register directly
        $transactionLog = new TransactionLog($conn);
        $result = $transactionLog->saveCourseLog($user_id, $course_id);
        echo json_encode($result);
        exit;
    }
}


$apiKey = trim((string) (getenv('FAWATERAK_API_KEY') ?: ''));
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['status' => 0, 'message' => 'Online payments are not configured.']);
    exit;
}
$fawaterk = new FawaterkPayment($conn, $apiKey);

$result = $fawaterk->payForCourse($user_id, $course_id);
echo json_encode($result);
