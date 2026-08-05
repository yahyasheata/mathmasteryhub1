<?php
// views/public/requests/purchase-fawaterk-course.php
header('Content-Type: application/json');
session_start();

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/FawaterkPayment.php';
require_once 'inc/TransactionLog.php';
require_once 'inc/PublicCourse.php';
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
$course = mmh_public_course_find($conn, $_POST['course_id']);
if (!$course || !mmh_course_is_public($course) || !is_numeric((string) ($course['course_id'] ?? ''))) {
    echo json_encode([
        'status' => 0,
        'message' => 'Course not found',
        'reason' => 'The selected course is no longer available.'
    ]);
    exit;
}
$course_id = (int) $course['course_id'];

if (mmh_public_course_enrolled($conn, (int) $user_id, (string) $course['course_id'])) {
    echo json_encode([
        'status' => 0,
        'message' => 'Already enrolled',
        'reason' => 'This course is already in your learning workspace.'
    ]);
    exit;
}

// Check course price
$courseStmt = $conn->prepare("SELECT course_price, course_title FROM courses
    WHERE course_id = ? AND archived_at IS NULL AND course_state = 'public' LIMIT 1");
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
