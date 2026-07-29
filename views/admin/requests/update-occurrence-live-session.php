<?php
require_once 'connection/config.php';
require_once 'inc/LiveSessions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    mmh_live_response(false, 'Unauthorized request.', [], 403);
}

$conn = db();
mmh_live_ensure_schema($conn);
$occurrenceId = trim((string) ($_POST['occurrence_id'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'scheduled'));
$allowed = ['scheduled', 'live', 'completed', 'cancelled', 'rescheduled'];
if ($occurrenceId === '' || !in_array($status, $allowed, true)) {
    mmh_live_response(false, 'Invalid occurrence update.', [], 422);
}

$replacementUrl = trim((string) ($_POST['replacement_url'] ?? ''));
if ($replacementUrl !== '') {
    $replacementUrl = mmh_live_sanitize_teams_url($replacementUrl);
    if ($replacementUrl === null) {
        mmh_live_response(false, 'Replacement URL must be a Microsoft Teams HTTPS link.', [], 422);
    }
} else {
    $replacementUrl = null;
}
$note = trim((string) ($_POST['change_note'] ?? ''));
$occurrence = mmh_live_occurrence($conn, $occurrenceId);
if (!$occurrence) {
    mmh_live_response(false, 'Occurrence not found.', [], 404);
}
$scheduledStart = trim((string) ($_POST['scheduled_start_at'] ?? ''));
$scheduledEnd = trim((string) ($_POST['scheduled_end_at'] ?? ''));
$startUtc = null;
$endUtc = null;
if ($scheduledStart !== '') {
    $tz = mmh_live_timezone($occurrence['timezone'] ?? 'Asia/Riyadh');
    try {
        $startDate = new DateTime($scheduledStart, $tz);
        $startDate->setTimezone(new DateTimeZone('UTC'));
        $startUtc = $startDate->format('Y-m-d H:i:s');
        if ($scheduledEnd !== '') {
            $endDate = new DateTime($scheduledEnd, $tz);
        } else {
            $endDate = new DateTime($scheduledStart, $tz);
            $endDate->modify('+60 minutes');
        }
        $endDate->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $endDate->format('Y-m-d H:i:s');
        $status = $status === 'scheduled' ? 'rescheduled' : $status;
    } catch (Throwable $e) {
        mmh_live_response(false, 'Reschedule datetime is invalid.', [], 422);
    }
}

$stmt = $conn->prepare('UPDATE live_session_occurrences SET status = ?, replacement_url = COALESCE(?, replacement_url), change_note = COALESCE(NULLIF(?, \'\'), change_note), scheduled_start_at = COALESCE(?, scheduled_start_at), scheduled_end_at = COALESCE(?, scheduled_end_at) WHERE occurrence_id = ?');
if (!$stmt) {
    mmh_live_response(false, 'Unable to prepare occurrence update.', [], 500);
}
$stmt->bind_param('ssssss', $status, $replacementUrl, $note, $startUtc, $endUtc, $occurrenceId);
$ok = $stmt->execute();
$stmt->close();
mmh_live_response($ok, $ok ? 'Occurrence updated successfully.' : 'Occurrence update failed.', [], $ok ? 200 : 500);
?>
