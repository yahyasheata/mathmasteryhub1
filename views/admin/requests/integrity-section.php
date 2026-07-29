<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/LiveSessions.php';
require_once 'inc/CourseResourceResolver.php';

header('Content-Type: application/json; charset=utf-8');

function section_integrity_response($success, $message, array $data = [], $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => (bool) $success, 'status' => $success ? 1 : 0, 'message' => $message], $data));
    exit;
}

function section_integrity_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function section_integrity_id($value)
{
    $value = trim((string) $value);
    return $value !== '' && strlen($value) <= 64 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) ? $value : null;
}

function section_integrity_recording(array $item)
{
    $type = strtolower(trim((string) ($item['template_type'] ?? '')));
    if ($type === '') {
        $type = strtolower(trim((string) ($item['item_type'] ?? '')));
    }
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    $resourceType = strtolower(trim((string) ($data['resource_type'] ?? $data['resource']['type'] ?? '')));
    $provider = strtolower(trim((string) ($data['resource_provider'] ?? $data['resource']['provider'] ?? '')));
    $url = mmh_course_resource_safe_url($data['resource_url'] ?? $data['resource']['url'] ?? $data['url'] ?? '');
    $isRecording = in_array($type, ['recording', 'video'], true)
        || in_array($resourceType, ['recording', 'video'], true)
        || in_array($provider, ['microsoft_stream', 'sharepoint'], true)
        || ($url !== null && mmh_course_resource_is_microsoft_stream_embed_url($url));
    if (!$isRecording) {
        return null;
    }
    if ($provider === '' && $url !== null) {
        $provider = mmh_course_resource_is_microsoft_stream_embed_url($url) ? 'microsoft_stream' : 'sharepoint';
    }
    [, $icon] = mmh_course_resource_display_meta($resourceType ?: 'recording', $provider, $url ?: '');
    return ['provider' => $provider ?: 'recording', 'url' => $url ?: '', 'icon' => $icon];
}

function section_integrity_sections(mysqli $conn, $courseId)
{
    $stmt = $conn->prepare("SELECT s.section_id, s.title, s.sort_order, s.release_occurrence_id, o.scheduled_start_at, o.scheduled_end_at, o.timezone, o.status AS occurrence_status, COUNT(DISTINCT i.id) AS resource_count, COUNT(DISTINCT a.assignment_id) AS homework_count, COUNT(DISTINCT la.id) AS attendance_count FROM course_sections s LEFT JOIN live_session_occurrences o ON o.occurrence_id = s.release_occurrence_id AND o.course_id = s.course_id LEFT JOIN course_items i ON i.course_id = s.course_id AND i.section_id = s.section_id LEFT JOIN assignments a ON a.course_id = s.course_id AND a.section_id = s.section_id LEFT JOIN live_session_attendance la ON la.occurrence_id = s.release_occurrence_id AND la.course_id = s.course_id WHERE s.course_id = ? GROUP BY s.id, s.section_id, s.title, s.sort_order, s.release_occurrence_id, o.scheduled_start_at, o.scheduled_end_at, o.timezone, o.status ORDER BY s.sort_order ASC, s.id ASC");
    if (!$stmt) { return []; }
    $stmt->bind_param('s', $courseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $recordingStmt = $conn->prepare('SELECT item_id, section_id, item_type, template_type, template_data FROM course_items WHERE course_id = ? AND section_id IS NOT NULL AND section_id <> ?');
    if (!$recordingStmt) {
        return $rows;
    }
    $empty = '';
    $recordingStmt->bind_param('ss', $courseId, $empty);
    $recordingStmt->execute();
    $recordingRows = $recordingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recordingStmt->close();
    $recordingCounts = [];
    foreach ($recordingRows as $recordingItem) {
        if (section_integrity_recording($recordingItem) !== null) {
            $sectionId = (string) $recordingItem['section_id'];
            $recordingCounts[$sectionId] = ($recordingCounts[$sectionId] ?? 0) + 1;
        }
    }
    foreach ($rows as &$row) {
        $row['recording_count'] = $recordingCounts[(string) $row['section_id']] ?? 0;
    }
    unset($row);
    return $rows;
}

function section_integrity_section_options(array $sections, $selected = '')
{
    $html = "<option value='__general__'" . ($selected === '' ? ' selected' : '') . ">General / Unsectioned</option>";
    foreach ($sections as $section) {
        $id = (string) $section['section_id'];
        $html .= "<option value='" . section_integrity_escape($id) . "'" . ($id === $selected ? ' selected' : '') . ">" . section_integrity_escape($section['title']) . "</option>";
    }
    return $html;
}

if (empty($_SESSION['admin'])) {
    section_integrity_response(false, 'Administrator access is required.', [], 403);
}

$courseId = section_integrity_id($_POST['course_id'] ?? '');
if ($courseId === null) {
    section_integrity_response(false, 'A valid course is required.', [], 422);
}
$conn = db();
mmh_ensure_learning_schema($conn);
mmh_live_ensure_schema($conn);

$courseStmt = $conn->prepare('SELECT course_id, course_title FROM courses WHERE course_id = ? LIMIT 1');
$courseStmt->bind_param('s', $courseId);
$courseStmt->execute();
$course = $courseStmt->get_result()->fetch_assoc();
$courseStmt->close();
if (!$course) {
    section_integrity_response(false, 'Course not found.', [], 404);
}

$operation = trim((string) ($_POST['operation'] ?? ''));
if ($operation !== '') {
    if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) {
        section_integrity_response(false, 'Your session has expired. Refresh and try again.', [], 403);
    }
    $sectionId = trim((string) ($_POST['section_id'] ?? ''));
    $sectionId = $sectionId === '__general__' ? '' : section_integrity_id($sectionId);
    if ($sectionId === null) {
        section_integrity_response(false, 'Choose a valid section or General.', [], 422);
    }
    if ($sectionId !== '') {
        $sectionStmt = $conn->prepare('SELECT section_id FROM course_sections WHERE course_id = ? AND section_id = ? LIMIT 1');
        $sectionStmt->bind_param('ss', $courseId, $sectionId);
        $sectionStmt->execute();
        $validSection = (bool) $sectionStmt->get_result()->fetch_assoc();
        $sectionStmt->close();
        if (!$validSection) { section_integrity_response(false, 'The selected section does not belong to this course.', [], 422); }
    }

    if ($operation === 'map_homework') {
        $assignmentId = section_integrity_id($_POST['assignment_id'] ?? '');
        if ($assignmentId === null) { section_integrity_response(false, 'Invalid homework reference.', [], 422); }
        $stmt = $conn->prepare('SELECT assignment_id, item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('ss', $assignmentId, $courseId); $stmt->execute(); $assignment = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$assignment) { section_integrity_response(false, 'Homework not found for this course.', [], 404); }
        $itemId = trim((string) ($assignment['item_id'] ?? ''));
        if ($itemId !== '') {
            $itemStmt = $conn->prepare('SELECT section_id FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
            $itemStmt->bind_param('ss', $itemId, $courseId); $itemStmt->execute(); $item = $itemStmt->get_result()->fetch_assoc(); $itemStmt->close();
            $itemSection = $item ? trim((string) ($item['section_id'] ?? '')) : '';
            if ($item && $itemSection !== $sectionId) { section_integrity_response(false, 'The linked lesson belongs to a different section. Move the lesson first or select its existing section.', [], 422); }
        }
        $update = $conn->prepare('UPDATE assignments SET section_id = ? WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        $update->bind_param('sss', $sectionId, $assignmentId, $courseId);
        $saved = $update->execute(); $update->close();
        section_integrity_response($saved, $saved ? 'Homework section mapping saved.' : 'Homework section mapping could not be saved.');
    }

    if ($operation === 'map_recording') {
        $itemId = section_integrity_id($_POST['item_id'] ?? '');
        if ($itemId === null) { section_integrity_response(false, 'Invalid recording reference.', [], 422); }
        $stmt = $conn->prepare('SELECT * FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('ss', $itemId, $courseId); $stmt->execute(); $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$item || section_integrity_recording($item) === null) { section_integrity_response(false, 'This item is not a recording candidate for this course.', [], 422); }
        $update = $conn->prepare('UPDATE course_items SET section_id = ? WHERE item_id = ? AND course_id = ? LIMIT 1');
        $update->bind_param('sss', $sectionId, $itemId, $courseId);
        $saved = $update->execute(); $update->close();
        section_integrity_response($saved, $saved ? 'Recording section mapping saved.' : 'Recording section mapping could not be saved.');
    }
    section_integrity_response(false, 'Unsupported integrity action.', [], 422);
}

$sections = section_integrity_sections($conn, $courseId);
$sectionOptions = section_integrity_section_options($sections);
$assignmentStmt = $conn->prepare("SELECT a.assignment_id, a.assignment_title, a.item_id, a.section_id, i.section_id AS item_section_id, i.item_title FROM assignments a LEFT JOIN course_items i ON i.course_id = a.course_id AND i.item_id = a.item_id WHERE a.course_id = ? AND (a.section_id IS NULL OR a.section_id = '') ORDER BY a.due_date ASC, a.id ASC");
$assignmentStmt->bind_param('s', $courseId); $assignmentStmt->execute(); $unresolvedHomework = $assignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC); $assignmentStmt->close();
$itemStmt = $conn->prepare("SELECT * FROM course_items WHERE course_id = ? AND (section_id IS NULL OR section_id = '') ORDER BY page_order ASC, id ASC");
$itemStmt->bind_param('s', $courseId); $itemStmt->execute(); $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC); $itemStmt->close();
$unresolvedRecordings = [];
foreach ($itemRows as $item) { if (($recording = section_integrity_recording($item)) !== null) { $item['_recording'] = $recording; $unresolvedRecordings[] = $item; } }

$readinessRows = '';
foreach ($sections as $section) {
    $occurrence = trim((string) ($section['release_occurrence_id'] ?? '')) !== '';
    $recordings = (int) ($section['recording_count'] ?? 0);
    $homework = (int) ($section['homework_count'] ?? 0);
    $resources = (int) ($section['resource_count'] ?? 0);
    $attendance = (int) ($section['attendance_count'] ?? 0);
    $facts = [];
    $facts[] = $occurrence ? 'Live occurrence linked' : 'No live occurrence linked';
    $facts[] = $recordings ? $recordings . ' recording' . ($recordings === 1 ? '' : 's') : 'No recording';
    $facts[] = $homework ? $homework . ' homework item' . ($homework === 1 ? '' : 's') : 'No homework';
    $facts[] = $resources . ' resource' . ($resources === 1 ? '' : 's');
    $facts[] = $occurrence ? ($attendance ? 'Attendance available' : 'No attendance recorded') : 'Attendance unavailable';
    $readinessRows .= '<tr><td><strong>' . section_integrity_escape($section['title']) . '</strong></td><td>' . section_integrity_escape(implode(' · ', $facts)) . '</td></tr>';
}

$homeworkRows = '';
foreach ($unresolvedHomework as $assignment) {
    $linked = trim((string) ($assignment['item_title'] ?? ''));
    $homeworkRows .= "<tr><td><strong>" . section_integrity_escape($assignment['assignment_title']) . "</strong>" . ($linked !== '' ? '<br><small class="text-muted">Linked lesson: ' . section_integrity_escape($linked) . '</small>' : '') . "</td><td><form data-section-integrity-map><input type='hidden' name='operation' value='map_homework'><input type='hidden' name='assignment_id' value='" . section_integrity_escape($assignment['assignment_id']) . "'><select class='form-control form-control-sm' name='section_id'>{$sectionOptions}</select><button class='btn btn-outline-primary btn-sm mt-2' type='submit'>Save mapping</button></form></td></tr>";
}
if ($homeworkRows === '') { $homeworkRows = '<tr><td colspan="2" class="text-muted">All homework currently has a section mapping.</td></tr>'; }

$recordingRows = '';
foreach ($unresolvedRecordings as $item) {
    $recording = $item['_recording'];
    $target = $recording['url'] !== '' ? $recording['url'] : 'No structured URL';
    $recordingRows .= "<tr><td><strong>" . section_integrity_escape($item['item_title']) . "</strong><br><small class='text-muted'>" . section_integrity_escape(ucwords(str_replace('_', ' ', $recording['provider']))) . ' · ' . section_integrity_escape($target) . "</small></td><td><form data-section-integrity-map><input type='hidden' name='operation' value='map_recording'><input type='hidden' name='item_id' value='" . section_integrity_escape($item['item_id']) . "'><select class='form-control form-control-sm' name='section_id'>{$sectionOptions}</select><button class='btn btn-outline-primary btn-sm mt-2' type='submit'>Save mapping</button></form></td></tr>";
}
if ($recordingRows === '') { $recordingRows = '<tr><td colspan="2" class="text-muted">All recording candidates currently have a section mapping.</td></tr>'; }

$html = "<div class='modal fade show' id='section-integrity-modal' tabindex='-1' aria-hidden='true'><div class='modal-dialog modal-lg modal-dialog-scrollable'><div class='modal-content course-manager-editor-card'><div class='modal-header'><div><h5 class='modal-title'>Section Integrity</h5><small class='text-muted'>" . section_integrity_escape($course['course_title']) . "</small></div><button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button></div><div class='modal-body'><p class='text-muted'>These mappings preserve existing IDs, attendance, submissions, grades, and resources. Only explicit selections are saved.</p><input type='hidden' data-section-integrity-token value='" . section_integrity_escape(mmh_auth_csrf_token()) . "'><details open class='mb-3'><summary class='fw-bold'>Section readiness</summary><div class='table-responsive mt-2'><table class='table table-sm'><thead><tr><th>Section</th><th>Current facts</th></tr></thead><tbody>{$readinessRows}</tbody></table></div></details><details open class='mb-3'><summary class='fw-bold'>Unresolved homework</summary><div class='table-responsive mt-2'><table class='table table-sm'><thead><tr><th>Homework</th><th>Map to section</th></tr></thead><tbody>{$homeworkRows}</tbody></table></div></details><details open><summary class='fw-bold'>Unsectioned recording candidates</summary><div class='table-responsive mt-2'><table class='table table-sm'><thead><tr><th>Recording</th><th>Map to section</th></tr></thead><tbody>{$recordingRows}</tbody></table></div></details></div></div></div></div>";
section_integrity_response(true, 'Section integrity loaded.', ['html' => $html]);
