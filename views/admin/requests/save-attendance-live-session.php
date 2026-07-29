<?php
require_once 'connection/config.php';
require_once 'inc/LiveSessions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    mmh_live_response(false, 'Unauthorized request.', [], 403);
}

$conn = db();
$occurrenceId = trim((string) ($_POST['occurrence_id'] ?? ''));
$occurrence = $occurrenceId !== '' ? mmh_live_occurrence($conn, $occurrenceId) : null;
if (!$occurrence) {
    mmh_live_response(false, 'Live session occurrence not found.', [], 404);
}

$adminId = mmh_live_admin_id($conn, $_SESSION['admin'] ?? '');
$rows = mmh_live_students_for_occurrence($conn, $occurrence);
$bulk = trim((string) ($_POST['bulk_action'] ?? ''));
$saved = 0;

if ($bulk === 'confirm_joined_present' || $bulk === 'mark_remaining_absent') {
    foreach ($rows as $row) {
        $current = $row['status'] ?: 'unknown';
        $hasJoinEvidence = !empty($row['first_join_clicked_at']);
        if ($bulk === 'confirm_joined_present' && (!$hasJoinEvidence || $current !== 'unknown')) {
            continue;
        }
        if ($bulk === 'mark_remaining_absent' && $current !== 'unknown') {
            continue;
        }
        $status = $bulk === 'confirm_joined_present' ? 'present_live' : 'absent';
        $saved += mmh_live_save_attendance($conn, $occurrence, $row['user_id'], $status, $row['teacher_note'] ?? '', $adminId) ? 1 : 0;
    }
    $message = $bulk === 'confirm_joined_present'
        ? 'Students with Join evidence were confirmed as Present.'
        : 'Remaining Unknown students were marked Absent.';
    mmh_live_response(true, $message, ['updated' => $saved]);
}

foreach ($_POST['attendance'] ?? [] as $studentId => $entry) {
    $saved += mmh_live_save_attendance($conn, $occurrence, $studentId, $entry['status'] ?? 'unknown', $entry['note'] ?? '', $adminId) ? 1 : 0;
}

mmh_live_response(true, 'Attendance saved.', ['updated' => $saved]);
?>
