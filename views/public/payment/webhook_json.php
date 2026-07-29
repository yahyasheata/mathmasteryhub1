<?php
// webhook_json.php -- Fawaterk paid/cancel webhook endpoint
header('Content-Type: application/json');
require_once 'connection/config.php';
require_once 'inc/TransactionLog.php';

// Your Fawaterk vendor key (keep this secret!)
$FAWATERAK_VENDOR_KEY = '9d6d010f953a86245b649c14ea4a1420dc01272f482a471e63'; // TODO: Replace with your real vendor key

// Read raw POST body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['hashKey'])) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Invalid data']);
    exit;
}

// Validate hashKey for paid invoice
if (isset($data['invoice_id'], $data['invoice_key'], $data['payment_method'])) {
    $queryParam = "InvoiceId={$data['invoice_id']}&InvoiceKey={$data['invoice_key']}&PaymentMethod={$data['payment_method']}";
    $expectedHash = hash_hmac('sha256', $queryParam, $FAWATERAK_VENDOR_KEY, false);
    if ($expectedHash !== $data['hashKey']) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Invalid hash']);
        exit;
    }
    // Only process if paid
    if (strtolower($data['invoice_status']) === 'paid') {
        $conn = db();
        // TODO: Lookup user_id and course_id by invoice_id or invoice_key in your DB
        // Example: $row = $conn->query("SELECT user_id, course_id FROM fawaterk_invoices WHERE invoice_id = '{$data['invoice_id']}'")->fetch_assoc();
        $user_id = 0; // <-- set this from your DB
        $course_id = 0; // <-- set this from your DB
        if ($user_id && $course_id) {
            $transactionLog = new TransactionLog($conn);
            // Save transaction (if not already exists)
            $courseResult = $conn->query("SELECT course_price, course_title FROM courses WHERE course_id = $course_id");
            if ($courseResult->num_rows > 0) {
                $course = $courseResult->fetch_assoc();
                $amount = $course['course_price'];
                $course_title = $course['course_title'];
                $check = $conn->query("SELECT id FROM transactions WHERE user_id = $user_id AND course_id = $course_id");
                if ($check->num_rows == 0) {
                    $conn->query("INSERT INTO transactions (user_id, course_id, course_title, amount, course_price) VALUES ($user_id, $course_id, '$course_title', $amount, $amount)");
                }
                $checkLog = $conn->query("SELECT id FROM course_logs WHERE user_id = $user_id AND course_id = $course_id");
                if ($checkLog->num_rows == 0) {
                    $conn->query("INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES ($user_id, $course_id, '$course_title', NOW())");
                }
                echo json_encode(['status' => 1, 'message' => 'Course activated']);
                exit;
            }
        }
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'User/course not found for invoice']);
        exit;
    }
    // Not paid, ignore
    echo json_encode(['status' => 1, 'message' => 'Not paid, ignored']);
    exit;
}

// Validate hashKey for cancel/expired
if (isset($data['referenceId'], $data['paymentMethod'])) {
    $queryParam = "referenceId={$data['referenceId']}&PaymentMethod={$data['paymentMethod']}";
    $expectedHash = hash_hmac('sha256', $queryParam, $FAWATERAK_VENDOR_KEY, false);
    if ($expectedHash !== $data['hashKey']) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Invalid hash']);
        exit;
    }
    // You can handle expired/cancelled logic here if needed
    echo json_encode(['status' => 1, 'message' => 'Expired/cancelled handled']);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 0, 'message' => 'Unknown webhook type']);
