<?php
require_once 'connection/config.php';
require_once 'inc/LiveSessions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    mmh_live_response(false, 'Unauthorized request.', [], 403);
}

$conn = db();
try {
    [$ok, $message, $data] = array_pad(mmh_live_save_schedule($conn, $_POST), 3, []);
    if ($ok) {
        mmh_live_generate_occurrences($conn, -3, 45, trim((string) ($_POST['course_id'] ?? '')));
    }
    mmh_live_response($ok, $message, is_array($data) ? $data : [], $ok ? 200 : 422);
} catch (Throwable $exception) {
    $errorData = php_sapi_name() === 'cli-server' ? ['error' => $exception->getMessage()] : [];
    mmh_live_response(false, 'Unable to save schedule. Please check the schedule details and try again.', $errorData, 500);
}
?>
