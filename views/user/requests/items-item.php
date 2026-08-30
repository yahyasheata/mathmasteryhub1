<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/StudentCourseAccess.php';

header('Content-Type: application/json; charset=utf-8');

function user_items_response($success, $message, array $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function user_items_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'GET') {
    user_items_response(false, 'Invalid lesson request.');
}

$courseId = trim((string) ($_POST['course_id'] ?? ''));
$username = trim((string) ($_SESSION['username'] ?? ''));
if ($courseId === '' || $username === '') {
    user_items_response(false, 'Please sign in to view course content.');
}

$conn = db();
$course = student_course_access_course($conn, $courseId);
$userId = student_course_access_student_id($conn, $username);
if (!$course || !$userId || !student_course_access_enrolled($conn, $userId, $course['course_id'])) {
    user_items_response(false, 'You do not have access to this course.');
}

$stmt = $conn->prepare("SELECT id, item_id, item_title, item_description, item_type, section_id
    FROM course_items
    WHERE course_id = ? AND " . student_course_access_active_item_sql() . "
    ORDER BY page_order ASC, id ASC");
if (!$stmt) {
    user_items_response(false, 'Course content could not be loaded.');
}
$stmt->bind_param('s', $course['course_id']);
$stmt->execute();
$result = $stmt->get_result();
$html = '';

while ($item = $result->fetch_assoc()) {
    $sectionId = student_course_access_normalize_section_id($item['section_id'] ?? '');
    if ($sectionId !== '') {
        $sectionState = student_course_access_section_state($conn, $course, $sectionId, $userId);
        // A section that is unpublished, missing, or currently locked must
        // not expose raw lesson HTML through this legacy AJAX path.
        if (!$sectionState || !empty($sectionState['state']['locked'])) {
            continue;
        }
    }

    $icon = 'fas fa-play';
    if (($item['item_type'] ?? '') === 'file') {
        $icon = 'fas fa-file';
    } elseif (($item['item_type'] ?? '') === 'quiz') {
        $icon = 'fas fa-edit';
    }
    $id = (int) $item['id'];
    $title = user_items_html($item['item_title'] ?? 'Lesson');
    $html .= "<div class='d-flex' id='course-item-{$id}' style='margin-bottom:20px'>
        <div class='ds-text-secondary' style='line-height:56px;margin-left:7px;font-size:26px;font-weight:bold'><i class='fas fa-expand-arrows-alt' aria-hidden='true'></i></div>
        <div style='width:100%'>
          <button type='button' class='accordion' id='{$id}'><i class='{$icon}' aria-hidden='true'></i> {$title}</button>
          <div class='panel'>{$item['item_description']}</div>
        </div>
      </div>";
}
$stmt->close();

user_items_response(true, 'Course content loaded.', [
    'html' => "<ul class='list-unstyled' id='page_list'>{$html}</ul>",
]);
