<?php
require_once 'connection/config.php';
require_once 'inc/LiveSessions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    mmh_live_response(false, 'Unauthorized request.', [], 403);
}

$conn = db();
mmh_live_ensure_schema($conn);
$occurrenceId = trim((string) ($_POST['occurrence_id'] ?? ''));
if (array_key_exists('target_course_id', $_POST)) {
    [$reassigned, $reassignmentMessage] = mmh_live_reassign_occurrence_course(
        $conn,
        $occurrenceId,
        trim((string) ($_POST['target_course_id'] ?? ''))
    );
    mmh_live_response($reassigned, $reassignmentMessage, [], $reassigned ? 200 : 422);
}
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
if (!mmh_live_occurrence_is_editable($occurrence)) {
    mmh_live_response(false, 'Deleted occurrences or recurring schedules cannot be updated.', [], 409);
}
if (empty($occurrence['course_id']) && in_array($status, ['live', 'completed'], true)) {
    mmh_live_response(false, 'Assign a Paper before marking this session Live or Completed.', [], 422);
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

$stmt = $conn->prepare('UPDATE live_session_occurrences AS o INNER JOIN course_live_schedules AS s ON s.schedule_id = o.schedule_id SET o.status = ?, o.replacement_url = COALESCE(?, o.replacement_url), o.change_note = COALESCE(NULLIF(?, \'\'), o.change_note), o.scheduled_start_at = COALESCE(?, o.scheduled_start_at), o.scheduled_end_at = COALESCE(?, o.scheduled_end_at) WHERE o.occurrence_id = ? AND o.status <> \'deleted\' AND s.deleted_at IS NULL');
if (!$stmt) {
    mmh_live_response(false, 'Unable to prepare occurrence update.', [], 500);
}
$stmt->bind_param('ssssss', $status, $replacementUrl, $note, $startUtc, $endUtc, $occurrenceId);
$ok = $stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();
if (!$ok) {
    mmh_live_response(false, 'Occurrence update failed.', [], 500);
}
if ($changed < 1) {
    $currentOccurrence = mmh_live_occurrence($conn, $occurrenceId);
    if (!$currentOccurrence || !mmh_live_occurrence_is_editable($currentOccurrence)) {
        mmh_live_response(false, 'Deleted occurrences or recurring schedules cannot be updated.', [], 409);
    }
}
mmh_live_response(true, 'Occurrence updated successfully.', [], 200);
?>
