<?php
require_once 'connection/config.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/CourseSectionAvailability.php';

header('Content-Type: application/json; charset=utf-8');

function add_section_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function add_section_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function add_section_status($value)
{
    return strtolower(trim((string) $value)) === 'draft' ? 'draft' : 'published';
}

function add_section_type_options()
{
    return [
        'lecture' => 'Lecture',
        'week' => 'Week',
        'unit' => 'Unit',
        'chapter' => 'Chapter',
        'module' => 'Module',
        'revision' => 'Revision',
        'practice' => 'Practice',
        'resources' => 'Resources',
        'live_session' => 'Live Session',
        'office_hours' => 'Office Hours',
        'bonus' => 'Bonus',
        'custom' => 'Custom',
    ];
}

function add_section_icon_options()
{
    return ['', 'play', 'calendar', 'book', 'layers', 'rotate', 'clipboard', 'folder', 'video', 'users', 'gift', 'star'];
}

function add_section_normalize_type($value)
{
    $value = strtolower(trim((string) $value));
    return array_key_exists($value, add_section_type_options()) ? $value : 'lecture';
}

function add_section_normalize_icon($value)
{
    $value = strtolower(trim((string) $value));
    return in_array($value, add_section_icon_options(), true) ? $value : '';
}


function add_section_unlock_mode_options()
{
    return ['always', 'after_previous_completed', 'on_date', 'manual_unlock', 'after_homework_submission', 'after_homework_approval', 'custom_rule'];
}

function add_section_completion_rule_options()
{
    return ['opening_section', 'watching_recordings', 'viewing_notes', 'homework_submitted', 'homework_approved', 'manual_completion', 'all_lessons_completed'];
}

function add_section_timezone_options()
{
    return ['Africa/Cairo', 'Asia/Riyadh', 'UTC'];
}

function add_section_normalize_from_list($value, array $allowed, $default)
{
    $value = trim((string) $value);
    return in_array($value, $allowed, true) ? $value : $default;
}

function add_section_normalize_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    $time = strtotime($value);
    return $time ? date('Y-m-d H:i:s', $time) : null;
}

function add_section_generate_id(mysqli $conn, $course_id)
{
    do {
        $section_id = (string) random_int(10000, 999999);
        $stmt = $conn->prepare('SELECT id FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('ss', $section_id, $course_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $section_id;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    add_section_response(false, 'Invalid request method.');
}

$method = $_POST['_method'] ?? 'POST';
if (!in_array($method, ['POST', 'UPDATE'], true)) {
    add_section_response(false, 'Invalid section save request.');
}

if (add_section_post('course_id') === '' || add_section_post('title') === '') {
    add_section_response(false, 'Validation failed. Please enter a section title.');
}

$conn = db();
mmh_ensure_learning_schema($conn);
$course_id = add_section_post('course_id');
$title = add_section_post('title');
$section_type = add_section_normalize_type(add_section_post('section_type', 'lecture'));
$custom_type = $section_type === 'custom' ? add_section_post('custom_type') : '';
$icon = add_section_normalize_icon(add_section_post('icon'));
$description = add_section_post('description');
$status = add_section_status($_POST['status'] ?? 'published');
$sort_order = (isset($_POST['sort_order']) && is_numeric($_POST['sort_order'])) ? max(1, (int) $_POST['sort_order']) : 1;
$unlock_mode = add_section_normalize_from_list(add_section_post('unlock_mode', 'always'), add_section_unlock_mode_options(), 'always');
$completion_rule = add_section_normalize_from_list(add_section_post('completion_rule', 'manual_completion'), add_section_completion_rule_options(), 'manual_completion');
$unlock_at = $unlock_mode === 'on_date' ? add_section_normalize_datetime(add_section_post('unlock_at')) : null;
$unlock_timezone = add_section_normalize_from_list(add_section_post('unlock_timezone', 'Africa/Cairo'), add_section_timezone_options(), 'Africa/Cairo');
$unlock_homework_id = in_array($unlock_mode, ['after_homework_submission', 'after_homework_approval'], true) || in_array($completion_rule, ['homework_submitted', 'homework_approved'], true)
    ? add_section_post('unlock_homework_id')
    : '';
$manual_unlocked = isset($_POST['manual_unlocked']) && in_array((string) $_POST['manual_unlocked'], ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
$release_mode = mmh_section_release_normalize_mode(add_section_post('release_mode', 'inherit'));
$release_override = mmh_section_release_normalize_override(add_section_post('release_override', 'inherit'));
if ($release_mode === 'manual' && $release_override === 'inherit') {
    $release_override = 'locked';
}
$release_timezone = mmh_section_release_timezone_name(add_section_post('release_timezone', 'Asia/Riyadh'));
$release_at = $release_mode === 'scheduled' ? mmh_section_release_utc_from_local(add_section_post('release_at'), $release_timezone) : null;
// This is both the optional reporting link for the section and, when the
// release mode requires it, the existing release dependency. Availability
// continues to read it only for the live-session release modes.
$release_occurrence_id = add_section_post('release_occurrence_id');
$release_delay_minutes = $release_mode === 'live_session_delay' ? max(0, min(10080, (int) add_section_post('release_delay_minutes', 0))) : 0;
$section_metadata = mmh_hierarchical_metadata_from_input($conn, $course_id, $_POST, 'section_metadata_');
$section_metadata_json = json_encode($section_metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($unlock_mode === 'on_date' && $unlock_at === null) {
    add_section_response(false, 'Validation failed. Please choose a valid unlock date and time.');
}

if (in_array($unlock_mode, ['after_homework_submission', 'after_homework_approval'], true) && $unlock_homework_id === '') {
    add_section_response(false, 'Validation failed. Please choose the homework required to unlock this section.');
}

if (in_array($completion_rule, ['homework_submitted', 'homework_approved'], true) && $unlock_homework_id === '') {
    add_section_response(false, 'Validation failed. Please choose the homework used for section completion.');
}

if ($section_type === 'custom' && $custom_type === '') {
    add_section_response(false, 'Validation failed. Please enter a custom section type.');
}
if ($release_mode === 'scheduled' && $release_at === null) {
    add_section_response(false, 'Validation failed. Please choose a valid release date and time.');
}
if ($release_occurrence_id !== '') {
    if (!mmh_section_release_occurrence($conn, $course_id, $release_occurrence_id)) {
        add_section_response(false, 'Validation failed. The selected live-session occurrence is unavailable for this course.');
    }

    // One occurrence represents one taught session. Keep the reporting
    // relationship unambiguous without copying any attendance rows.
    $currentSectionId = $method === 'UPDATE' ? add_section_post('section_id') : '';
    $duplicateStmt = $conn->prepare('SELECT section_id FROM course_sections WHERE course_id = ? AND release_occurrence_id = ? AND section_id <> ? LIMIT 1');
    if (!$duplicateStmt) {
        add_section_response(false, 'Unable to validate the selected live-session occurrence.');
    }
    $duplicateStmt->bind_param('sss', $course_id, $release_occurrence_id, $currentSectionId);
    $duplicateStmt->execute();
    $alreadyLinked = $duplicateStmt->get_result()->fetch_assoc();
    $duplicateStmt->close();
    if ($alreadyLinked) {
        add_section_response(false, 'This live-session occurrence is already linked to another section in this course.');
    }
}

if (in_array($release_mode, ['live_session', 'live_session_delay'], true)) {
    if ($release_occurrence_id === '') {
        add_section_response(false, 'Validation failed. Please choose a live-session occurrence.');
    }
}

$course_stmt = $conn->prepare('SELECT course_id FROM courses WHERE course_id = ? LIMIT 1');
$course_stmt->bind_param('s', $course_id);
$course_stmt->execute();
$course_exists = $course_stmt->get_result()->num_rows > 0;
$course_stmt->close();

if (!$course_exists) {
    add_section_response(false, 'Course not found.');
}

try {
    if ($method === 'UPDATE') {
        $section_id = add_section_post('section_id');
        if ($section_id === '' || $section_id === '__general__') {
            add_section_response(false, 'General cannot be edited as a database section.');
        }

        $stmt = $conn->prepare('UPDATE course_sections SET section_type = ?, custom_type = ?, icon = ?, title = ?, description = ?, metadata = ?, sort_order = ?, status = ?, unlock_mode = ?, completion_rule = ?, unlock_at = ?, unlock_timezone = ?, unlock_homework_id = ?, manual_unlocked = ?, release_mode = ?, release_override = ?, release_at = ?, release_timezone = ?, release_occurrence_id = ?, release_delay_minutes = ?, release_updated_at = NOW() WHERE section_id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) {
            add_section_response(false, 'Unable to prepare section update.', ['reason' => $conn->error]);
        }
        $stmt->bind_param('ssssssissssssisssssiss', $section_type, $custom_type, $icon, $title, $description, $section_metadata_json, $sort_order, $status, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked, $release_mode, $release_override, $release_at, $release_timezone, $release_occurrence_id, $release_delay_minutes, $section_id, $course_id);
        if (!$stmt->execute()) {
            add_section_response(false, 'Unexpected server error while updating the section.', ['reason' => $stmt->error ?: $conn->error]);
        }
        $stmt->close();

        add_section_response(true, 'Section updated successfully.', [
            'course_id' => $course_id,
            'section_id' => $section_id,
        ]);
    }

    $section_id = add_section_generate_id($conn, $course_id);
    $stmt = $conn->prepare('INSERT INTO course_sections (section_id, course_id, section_type, custom_type, icon, title, description, metadata, sort_order, status, unlock_mode, completion_rule, unlock_at, unlock_timezone, unlock_homework_id, manual_unlocked, release_mode, release_override, release_at, release_timezone, release_occurrence_id, release_delay_minutes, release_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    if (!$stmt) {
        add_section_response(false, 'Unable to prepare section creation.', ['reason' => $conn->error]);
    }
    $stmt->bind_param('ssssssssissssssisssssi', $section_id, $course_id, $section_type, $custom_type, $icon, $title, $description, $section_metadata_json, $sort_order, $status, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked, $release_mode, $release_override, $release_at, $release_timezone, $release_occurrence_id, $release_delay_minutes);
    if (!$stmt->execute()) {
        add_section_response(false, 'Unexpected server error while creating the section.', ['reason' => $stmt->error ?: $conn->error]);
    }
    $stmt->close();

    add_section_response(true, 'Section created successfully.', [
        'course_id' => $course_id,
        'section_id' => $section_id,
    ]);
} catch (Throwable $e) {
    add_section_response(false, 'Unexpected server error while saving the section.', ['reason' => $e->getMessage()]);
}
?>
