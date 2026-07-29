<?php
// views/public/payment/success.php
// This file is set as the Fawaterk successUrl

require_once '../../connection/config.php';
require_once '../../inc/TransactionLog.php';

session_start();
$conn = db();

// ---
// 1. Get Fawaterk payment info from GET/POST (adjust as needed)
//    Example: ?user_id=...&course_id=...&status=paid
//    You may need to adjust this to match Fawaterk's actual callback params
// ---
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';

// If you store invoice info in DB, you can also look up by invoice_id/reference here

if ($user_id && $course_id && strtolower($status) === 'paid') {
    // Save transaction and course log
    $transactionLog = new TransactionLog($conn);
    // Save transaction (if not already exists)
    $courseResult = $conn->query("SELECT course_price, course_title FROM courses WHERE course_id = $course_id");
    if ($courseResult->num_rows > 0) {
        $course = $courseResult->fetch_assoc();
        $amount = $course['course_price'];
        $course_title = $course['course_title'];
        // Check if transaction already exists
        $check = $conn->query("SELECT id FROM transactions WHERE user_id = $user_id AND course_id = $course_id");
        if ($check->num_rows == 0) {
            $conn->query("INSERT INTO transactions (user_id, course_id, course_title, amount, course_price) VALUES ($user_id, $course_id, '$course_title', $amount, $amount)");
        }
        // Save course log (if not already exists)
        $checkLog = $conn->query("SELECT id FROM course_logs WHERE user_id = $user_id AND course_id = $course_id");
        if ($checkLog->num_rows == 0) {
            $conn->query("INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES ($user_id, $course_id, '$course_title', NOW())");
        }
        // Show success message
        echo "<h2 class='ds-text-success' style='text-align: center'>Payment completed successfully! The course has been activated and you can now access it from the dashboard.</h2>";
        echo "<div style='text-align: center'><a href='/user/my-courses' class='ds-text-secondary' style='font-size: 20px'>Go to My Courses</a></div>";
        exit;
    }
}
// If failed or missing info
http_response_code(400);
echo "<h2 class='ds-text-danger' style='text-align: center'>The course could not be activated. Please contact support.</h2>";
