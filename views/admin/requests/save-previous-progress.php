<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/StudentLearningJourney.php';

$conn = db();
$courseId = trim((string) ($_POST['course_id'] ?? ''));
$studentId = (int) ($_POST['student_id'] ?? 0);
$redirect = rtrim((string) $baseUrl, '/') . '/admin/previous-progress?course_id=' . rawurlencode($courseId) . '&student_id=' . $studentId;
$flash = static function (bool $ok, string $message) use ($redirect): void {
    $_SESSION['learning_journey_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: ' . $redirect);
    exit;
};
if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) { $flash(false, 'Your session has expired. Refresh and try again.'); }
try {
    if ($courseId === '' || $studentId <= 0 || !student_course_access_enrolled($conn, $studentId, $courseId)) { throw new InvalidArgumentException('Choose an enrolled student and course.'); }
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    $source = strtolower(trim((string) ($_POST['source'] ?? 'manual')));
    if (!in_array($source, ['whatsapp', 'manual'], true)) { throw new InvalidArgumentException('Source must be WhatsApp or Manual.'); }
    $journey = $_POST['journey'] ?? [];
    if (!is_array($journey)) { throw new InvalidArgumentException('No Learning Journey items were submitted.'); }
    $resolved = mmh_learning_journey_resolve($conn, $studentId, $courseId);
    $allowed = [];
    foreach ($resolved['items'] as $item) { $allowed[mmh_learning_journey_entity_key($item['item_kind'], (string) $item['item_id'], (string) $item['assignment_id'])] = $item; }
    foreach ($resolved['sections'] as $section) foreach ($section['live_sessions'] as $session) { $allowed[mmh_learning_journey_entity_key('live_session', '', '', (string) $session['occurrence_id'])] = $session; }
    $saved = 0;
    foreach ($journey as $row) {
        if (!is_array($row)) { continue; }
        $kind = trim((string) ($row['item_kind'] ?? 'lesson')); $itemId = trim((string) ($row['item_id'] ?? '')); $assignmentId = trim((string) ($row['assignment_id'] ?? '')); $occurrenceId = trim((string) ($row['occurrence_id'] ?? ''));
        $key = mmh_learning_journey_entity_key($kind, $itemId, $assignmentId, $occurrenceId);
        if (!isset($allowed[$key])) { throw new InvalidArgumentException('A submitted item is not part of the published course.'); }
        $entity = ['item_kind' => $kind, 'section_id' => (string) ($row['section_id'] ?? ''), 'item_id' => $itemId, 'assignment_id' => $assignmentId, 'occurrence_id' => $occurrenceId];
        if ((string) ($row['completed'] ?? '0') === '1') {
            mmh_learning_journey_save_evidence($conn, $studentId, $courseId, $entity, $kind === 'live_session' ? 'present' : ($kind === 'recording' ? 'watched' : 'completed'), $source, $adminId);
        } else {
            mmh_learning_journey_delete_evidence($conn, $studentId, $courseId, $entity);
        }
        $saved++;
    }
    $flash(true, 'Learning Journey saved for ' . $saved . ' course items.');
} catch (Throwable $exception) { $flash(false, $exception->getMessage()); }
