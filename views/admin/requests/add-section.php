<?php
require_once 'connection/config.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/CourseSectionAvailability.php';
require_once 'inc/EnrollmentService.php';

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

function add_section_activation_blocker(mysqli $conn, string $courseId, string $changedSectionId, string $targetUnit): string
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM course_logs WHERE course_id = ? AND (unit_access IS NULL OR unit_access NOT IN ('unit1', 'unit2', 'both'))");
    if (!$stmt) return 'Student Unit access could not be verified.';
    $stmt->bind_param('s', $courseId); $stmt->execute();
    $unclassified = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)); $stmt->close();
    if ($unclassified > 0) return $unclassified . ' enrolled student' . ($unclassified === 1 ? ' is' : 's are') . ' still unclassified. Assign Unit Access to every student before restricting a Section.';

    $items = $conn->prepare("SELECT i.item_id FROM course_items i LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id
        WHERE i.course_id = ? AND (i.archived_at IS NULL OR i.archived_at = '') AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
          AND (i.section_id IS NULL OR i.section_id = '' OR s.section_id IS NULL) LIMIT 1");
    if (!$items) return 'Course Item section integrity could not be verified.';
    $items->bind_param('s', $courseId); $items->execute();
    $ambiguousItem = (bool) $items->get_result()->fetch_assoc(); $items->close();
    if ($ambiguousItem) return 'This Course has a published item without a valid Section. Attach or review it before enabling Unit access.';

    $mismatch = $conn->prepare("SELECT i.item_id FROM course_items i INNER JOIN assignments a ON a.assignment_id = i.assignment_id AND a.course_id = i.course_id
        WHERE i.course_id = ? AND COALESCE(a.section_id, '') <> '' AND COALESCE(a.section_id, '') <> COALESCE(i.section_id, '') LIMIT 1");
    if (!$mismatch) return 'Homework Section links could not be verified.';
    $mismatch->bind_param('s', $courseId); $mismatch->execute();
    $hasMismatch = (bool) $mismatch->get_result()->fetch_assoc(); $mismatch->close();
    if ($hasMismatch) return 'A Homework Section does not match its canonical Course Item. Resolve the Section link before enabling Unit access.';

    $sections = $conn->prepare('SELECT section_id, access_unit, unlock_mode, completion_rule, unlock_homework_id FROM course_sections WHERE course_id = ?');
    if (!$sections) return 'Section dependencies could not be verified.';
    $sections->bind_param('s', $courseId); $sections->execute();
    $sectionRows = $sections->get_result()->fetch_all(MYSQLI_ASSOC); $sections->close();
    $sectionUnits = [];
    foreach ($sectionRows as $row) $sectionUnits[(string) $row['section_id']] = (string) $row['access_unit'];
    if ($changedSectionId !== '') $sectionUnits[$changedSectionId] = $targetUnit;

    foreach ($sectionRows as $row) {
        $mode = (string) ($row['unlock_mode'] ?? '');
        $rule = (string) ($row['completion_rule'] ?? '');
        $assignmentId = trim((string) ($row['unlock_homework_id'] ?? ''));
        if ($assignmentId === '' || !in_array($mode, ['after_homework_submission', 'after_homework_approval'], true) && !in_array($rule, ['homework_submitted', 'homework_approved'], true)) continue;
        $dependency = $conn->prepare('SELECT i.section_id, a.section_id AS assignment_section FROM course_items i INNER JOIN assignments a ON a.assignment_id = i.assignment_id AND a.course_id = i.course_id WHERE i.course_id = ? AND a.assignment_id = ? AND (i.archived_at IS NULL OR i.archived_at = \'\') AND (i.status IS NULL OR i.status = \'\' OR i.status = \'published\')');
        if (!$dependency) return 'Homework dependencies could not be verified.';
        $dependency->bind_param('ss', $courseId, $assignmentId); $dependency->execute();
        $matches = $dependency->get_result()->fetch_all(MYSQLI_ASSOC); $dependency->close();
        if (count($matches) !== 1) return 'A Section depends on Homework without one unambiguous canonical Course Item.';
        $itemSection = trim((string) ($matches[0]['section_id'] ?? ''));
        $assignmentSection = trim((string) ($matches[0]['assignment_section'] ?? ''));
        if ($itemSection === '' || !isset($sectionUnits[$itemSection]) || ($assignmentSection !== '' && $assignmentSection !== $itemSection)) return 'A Section depends on Homework with an ambiguous Section link.';
        $sourceUnit = $changedSectionId !== '' && (string) ($row['section_id'] ?? '') === $changedSectionId ? $targetUnit : (string) ($row['access_unit'] ?? 'shared');
        if ($sourceUnit === 'shared' && in_array($sectionUnits[$itemSection], ['unit1', 'unit2'], true)
            || $sourceUnit === 'unit1' && $sectionUnits[$itemSection] === 'unit2'
            || $sourceUnit === 'unit2' && $sectionUnits[$itemSection] === 'unit1') {
            return 'A Section depends on Homework assigned to a different Unit. Resolve that dependency before enabling Unit access.';
        }
    }

    $localFile = $conn->prepare("SELECT i.item_id FROM course_items i LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id
        LEFT JOIN assignments a ON a.course_id = i.course_id AND a.assignment_id = i.assignment_id
        WHERE i.course_id = ? AND ((s.access_unit IN ('unit1', 'unit2')) OR i.section_id = ?)
          AND (CONCAT(COALESCE(i.template_data,''), ' ', COALESCE(i.item_description,''), ' ', COALESCE(i.metadata,''), ' ', COALESCE(a.file_path,'')) LIKE '%uploads/%') LIMIT 1");
    if (!$localFile) return 'Local resource access could not be verified.';
    $localFile->bind_param('ss', $courseId, $changedSectionId); $localFile->execute();
    $hasLocalFile = (bool) $localFile->get_result()->fetch_assoc(); $localFile->close();
    if ($hasLocalFile) return 'A restricted Section contains a locally hosted file that can bypass the LMS authorization route. Protect or replace that resource before enabling Unit access.';

    return '';
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
$unitAccessSchema = mmh_enrollment_course_unit_state($conn, $course_id);
if ($unitAccessSchema === null) add_section_response(false, 'Section Access could not be verified.');
$unitAccessSupported = mmh_course_supports_unit_section_access($course_id, $conn);
$access_unit = isset($_POST['access_unit']) ? trim((string) $_POST['access_unit']) : null;
if ($access_unit !== null && !in_array($access_unit, ['shared', 'unit1', 'unit2'], true)) {
    add_section_response(false, 'Invalid Section Access. Choose Shared, Unit 1, or Unit 2.');
}
$section_id_for_access = add_section_post('section_id');
$storedAccessUnit = null;
if ($method === 'UPDATE' && !empty($unitAccessSchema['ready'])) {
    $accessLookup = $conn->prepare('SELECT access_unit FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
    if (!$accessLookup) add_section_response(false, 'Section Access could not be verified.');
    $accessLookup->bind_param('ss', $section_id_for_access, $course_id); $accessLookup->execute();
    $storedAccessUnit = $accessLookup->get_result()->fetch_assoc()['access_unit'] ?? null; $accessLookup->close();
    if ($storedAccessUnit === null && !empty($unitAccessSchema['ready'])) add_section_response(false, 'Section not found.');
}
if (empty($unitAccessSchema['ready'])) {
    if ($access_unit !== null && $access_unit !== 'shared') add_section_response(false, 'Unit access is not ready yet. Please try again shortly.');
    $access_unit = 'shared';
} elseif ($access_unit === null && $method === 'UPDATE') {
    $access_unit = $storedAccessUnit;
    if (!in_array($access_unit, ['shared', 'unit1', 'unit2'], true)) add_section_response(false, 'Invalid stored Section Access. Review the Section before saving.');
}
$access_unit ??= 'shared';
$preservingExistingUnsupportedRestriction = $method === 'UPDATE'
    && $storedAccessUnit !== null
    && $storedAccessUnit !== 'shared'
    && !$unitAccessSupported;
if ($preservingExistingUnsupportedRestriction && $access_unit !== $storedAccessUnit) {
    add_section_response(false, 'This existing Unit restriction cannot be changed while Unit Section Access is disabled for this Course.');
}
if ($access_unit !== 'shared' && !$unitAccessSupported && !$preservingExistingUnsupportedRestriction) {
    add_section_response(false, 'Unit-restricted Sections are available only in a Course with Unit Section Access enabled.');
}
$unitActivationRequired = $access_unit !== 'shared' && ($method !== 'UPDATE' || $access_unit !== $storedAccessUnit);
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
    $section_id = $method === 'UPDATE' ? add_section_post('section_id') : add_section_generate_id($conn, $course_id);
    $unitActivationTransaction = false;
    if ($unitActivationRequired) {
        if (empty($unitAccessSchema['ready'])) add_section_response(false, 'Unit access is not ready yet. Please try again shortly.');
        if (!$conn->begin_transaction()) add_section_response(false, 'Unit access could not be activated. Please try again.');
        $unitActivationTransaction = true;
        $courseLock = $conn->prepare('SELECT course_id FROM courses WHERE course_id = ? LIMIT 1 FOR UPDATE');
        if (!$courseLock) { $conn->rollback(); add_section_response(false, 'Course access could not be locked for safe Section activation.'); }
        $courseLock->bind_param('s', $course_id); $courseLock->execute();
        $lockedCourseExists = (bool) $courseLock->get_result()->fetch_assoc(); $courseLock->close();
        if (!$lockedCourseExists) { $conn->rollback(); add_section_response(false, 'Course not found.'); }
        if (!mmh_course_supports_unit_section_access($course_id, $conn)) { $conn->rollback(); add_section_response(false, 'Unit Section Access is not enabled for this Course.'); }
        $activationBlocker = add_section_activation_blocker($conn, $course_id, $section_id, $access_unit);
        if ($activationBlocker !== '') { $conn->rollback(); add_section_response(false, $activationBlocker); }
    }
    if ($method === 'UPDATE') {
        if ($section_id === '' || $section_id === '__general__') {
            if ($unitActivationTransaction) $conn->rollback();
            add_section_response(false, 'General cannot be edited as a database section.');
        }

        $updateSql = !empty($unitAccessSchema['ready'])
            ? 'UPDATE course_sections SET section_type = ?, custom_type = ?, icon = ?, title = ?, description = ?, metadata = ?, sort_order = ?, status = ?, access_unit = ?, unlock_mode = ?, completion_rule = ?, unlock_at = ?, unlock_timezone = ?, unlock_homework_id = ?, manual_unlocked = ?, release_mode = ?, release_override = ?, release_at = ?, release_timezone = ?, release_occurrence_id = ?, release_delay_minutes = ?, release_updated_at = NOW() WHERE section_id = ? AND course_id = ? LIMIT 1'
            : 'UPDATE course_sections SET section_type = ?, custom_type = ?, icon = ?, title = ?, description = ?, metadata = ?, sort_order = ?, status = ?, unlock_mode = ?, completion_rule = ?, unlock_at = ?, unlock_timezone = ?, unlock_homework_id = ?, manual_unlocked = ?, release_mode = ?, release_override = ?, release_at = ?, release_timezone = ?, release_occurrence_id = ?, release_delay_minutes = ?, release_updated_at = NOW() WHERE section_id = ? AND course_id = ? LIMIT 1';
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            if ($unitActivationTransaction) $conn->rollback();
            add_section_response(false, 'Unable to prepare section update.', ['reason' => $conn->error]);
        }
        if (!empty($unitAccessSchema['ready'])) {
            $stmt->bind_param('ssssssisssssssisssssiss', $section_type, $custom_type, $icon, $title, $description, $section_metadata_json, $sort_order, $status, $access_unit, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked, $release_mode, $release_override, $release_at, $release_timezone, $release_occurrence_id, $release_delay_minutes, $section_id, $course_id);
        } else {
            $stmt->bind_param('ssssssissssssisssssiss', $section_type, $custom_type, $icon, $title, $description, $section_metadata_json, $sort_order, $status, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked, $release_mode, $release_override, $release_at, $release_timezone, $release_occurrence_id, $release_delay_minutes, $section_id, $course_id);
        }
        if (!$stmt->execute()) {
            if ($unitActivationTransaction) $conn->rollback();
            add_section_response(false, 'Unexpected server error while updating the section.', ['reason' => $stmt->error ?: $conn->error]);
        }
        $stmt->close();
        if ($unitActivationTransaction && !$conn->commit()) add_section_response(false, 'Section access could not be saved. Please try again.');

        add_section_response(true, 'Section updated successfully.', [
            'course_id' => $course_id,
            'section_id' => $section_id,
        ]);
    }

    $insertSql = !empty($unitAccessSchema['ready'])
        ? 'INSERT INTO course_sections (section_id, course_id, section_type, custom_type, icon, title, description, metadata, sort_order, status, access_unit, unlock_mode, completion_rule, unlock_at, unlock_timezone, unlock_homework_id, manual_unlocked, release_mode, release_override, release_at, release_timezone, release_occurrence_id, release_delay_minutes, release_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        : 'INSERT INTO course_sections (section_id, course_id, section_type, custom_type, icon, title, description, metadata, sort_order, status, unlock_mode, completion_rule, unlock_at, unlock_timezone, unlock_homework_id, manual_unlocked, release_mode, release_override, release_at, release_timezone, release_occurrence_id, release_delay_minutes, release_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $stmt = $conn->prepare($insertSql);
    if (!$stmt) {
        if ($unitActivationTransaction) $conn->rollback();
        add_section_response(false, 'Unable to prepare section creation.', ['reason' => $conn->error]);
    }
    if (!empty($unitAccessSchema['ready'])) {
        $stmt->bind_param('ssssssssisssssssisssssi', $section_id, $course_id, $section_type, $custom_type, $icon, $title, $description, $section_metadata_json, $sort_order, $status, $access_unit, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked, $release_mode, $release_override, $release_at, $release_timezone, $release_occurrence_id, $release_delay_minutes);
    } else {
        $stmt->bind_param('ssssssssissssssisssssi', $section_id, $course_id, $section_type, $custom_type, $icon, $title, $description, $section_metadata_json, $sort_order, $status, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked, $release_mode, $release_override, $release_at, $release_timezone, $release_occurrence_id, $release_delay_minutes);
    }
    if (!$stmt->execute()) {
        if ($unitActivationTransaction) $conn->rollback();
        add_section_response(false, 'Unexpected server error while creating the section.', ['reason' => $stmt->error ?: $conn->error]);
    }
    $stmt->close();
    if ($unitActivationTransaction && !$conn->commit()) add_section_response(false, 'Section access could not be saved. Please try again.');

    add_section_response(true, 'Section created successfully.', [
        'course_id' => $course_id,
        'section_id' => $section_id,
    ]);
} catch (Throwable $e) {
    add_section_response(false, 'Unexpected server error while saving the section.', ['reason' => $e->getMessage()]);
}
?>
