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
$courseResult = $conn->query("SELECT course_price, course_title FROM courses WHERE course_id = $course_id");
if ($courseResult && $courseResult->num_rows > 0) {
    $course = $courseResult->fetch_assoc();
    if (floatval($course['course_price']) == 0) {
        // Free course: register directly
        $transactionLog = new TransactionLog($conn);
        $result = $transactionLog->saveCourseLog($user_id, $course_id);
        echo json_encode($result);
        exit;
    }
}


// $apiKey = '9d6d010f953a86245b649c14ea4a1420dc01272f482a471e63'; // TODO: Replace with your real API key or load from config
$apiKey = 'e5a4ac54665ac8be9b10ae70419f39e72e9887ce27c70be995'; // TODO: Replace with your real API key or load from config

$fawaterk = new FawaterkPayment($conn, $apiKey);

$result = $fawaterk->payForCourse($user_id, $course_id);
echo json_encode($result);
