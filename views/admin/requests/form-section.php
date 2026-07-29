<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/CourseSectionAvailability.php';

header('Content-Type: application/json; charset=utf-8');

function form_section_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function form_section_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * The selector must include every occurrence for this course. Generate from
 * the first configured schedule date rather than the normal short planning
 * window, then omit occurrences already owned by another Section.
 */
function form_section_reporting_occurrences(mysqli $conn, $courseId, $currentSectionId = ''): array
{
    $occurrences = [];
    $fromDays = -14;
    $firstScheduleStmt = $conn->prepare("SELECT MIN(effective_start_date) AS first_start FROM course_live_schedules WHERE course_id = ? AND enabled = 1 AND effective_start_date IS NOT NULL");
    if ($firstScheduleStmt) {
        $firstScheduleStmt->bind_param('s', $courseId);
        $firstScheduleStmt->execute();
        $firstStart = (string) (($firstScheduleStmt->get_result()->fetch_assoc()['first_start'] ?? ''));
        $firstScheduleStmt->close();
        $firstTimestamp = strtotime($firstStart . ' 00:00:00 UTC');
        $todayTimestamp = strtotime('today UTC');
        if ($firstTimestamp !== false && $todayTimestamp !== false) {
            $fromDays = min($fromDays, (int) floor(($firstTimestamp - $todayTimestamp) / 86400));
        }
    }

    $linkedOccurrences = [];
    $linkedStmt = $conn->prepare("SELECT release_occurrence_id FROM course_sections WHERE course_id = ? AND release_occurrence_id IS NOT NULL AND release_occurrence_id <> '' AND section_id <> ?");
    if ($linkedStmt) {
        $linkedStmt->bind_param('ss', $courseId, $currentSectionId);
        $linkedStmt->execute();
        foreach ($linkedStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $linked) {
            $linkedOccurrences[(string) $linked['release_occurrence_id']] = true;
        }
        $linkedStmt->close();
    }

    foreach (mmh_live_occurrences($conn, $courseId, $fromDays, 180) as $occurrence) {
        $id = trim((string) ($occurrence['occurrence_id'] ?? ''));
        if ($id !== '' && !isset($linkedOccurrences[$id])) {
            $occurrences[$id] = $occurrence;
        }
    }

    $stmt = $conn->prepare('SELECT o.*, s.title AS schedule_title FROM live_session_occurrences o LEFT JOIN course_live_schedules s ON s.schedule_id = o.schedule_id WHERE o.course_id = ? ORDER BY o.scheduled_start_at ASC, s.sort_order ASC');
    if ($stmt) {
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $occurrence) {
            $id = trim((string) ($occurrence['occurrence_id'] ?? ''));
            if ($id !== '' && !isset($linkedOccurrences[$id])) {
                $occurrences[$id] = $occurrence;
            }
        }
        $stmt->close();
    }

    $occurrences = array_values($occurrences);
    usort($occurrences, static fn(array $left, array $right): int => strcmp((string) ($left['scheduled_start_at'] ?? ''), (string) ($right['scheduled_start_at'] ?? '')));
    return $occurrences;
}

function form_section_status($value)
{
    return strtolower(trim((string) $value)) === 'draft' ? 'draft' : 'published';
}

function form_section_type_options()
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

function form_section_icon_options()
{
    return [
        '' => 'Automatic by Type',
        'play' => 'Play',
        'calendar' => 'Calendar',
        'book' => 'Book',
        'layers' => 'Layers',
        'rotate' => 'Rotate',
        'clipboard' => 'Clipboard',
        'folder' => 'Folder',
        'video' => 'Video',
        'users' => 'Users',
        'gift' => 'Gift',
        'star' => 'Star',
    ];
}


function form_section_unlock_mode_options()
{
    return [
        'always' => 'Always Available',
        'after_previous_completed' => 'After Previous Section Completed',
        'on_date' => 'On Specific Date',
        'manual_unlock' => 'Manual Unlock',
        'after_homework_submission' => 'After Homework Submission',
        'after_homework_approval' => 'After Homework Approval (future ready)',
        'custom_rule' => 'Custom Rule (reserved)',
    ];
}

function form_section_completion_rule_options()
{
    return [
        'opening_section' => 'Opening the section only',
        'watching_recordings' => 'Watching every Recording',
        'viewing_notes' => 'Viewing every Note',
        'homework_submitted' => 'Homework submitted',
        'homework_approved' => 'Homework approved (future)',
        'manual_completion' => 'Manual completion button',
        'all_lessons_completed' => 'All lessons completed',
    ];
}

function form_section_timezone_options()
{
    return [
        'Africa/Cairo' => 'Africa/Cairo',
        'Asia/Riyadh' => 'Asia/Riyadh',
        'UTC' => 'UTC',
    ];
}

function form_section_normalize_option($value, array $options, $default)
{
    $value = trim((string) $value);
    return array_key_exists($value, $options) ? $value : $default;
}

function form_section_datetime_local($value)
{
    if (empty($value)) {
        return '';
    }
    $time = strtotime((string) $value);
    return $time ? date('Y-m-d\TH:i', $time) : '';
}

function form_section_homework_options(mysqli $conn, $course_id, $section_id, $selected)
{
    $html = "<option value=''>Select homework</option>";
    if (trim((string) $section_id) === '') {
        return $html;
    }

    $stmt = $conn->prepare("SELECT DISTINCT assignments.assignment_id, assignments.assignment_title
        FROM assignments
        INNER JOIN course_items ON course_items.course_id = assignments.course_id
          AND course_items.section_id = ?
          AND (
            CAST(course_items.assignment_id AS CHAR) = assignments.assignment_id
            OR JSON_UNQUOTE(JSON_EXTRACT(course_items.template_data, '$.assignment_id')) = assignments.assignment_id
          )
        WHERE assignments.course_id = ?
        ORDER BY assignments.created_at ASC, assignments.id ASC");
    if (!$stmt) {
        return $html;
    }
    $stmt->bind_param('ss', $section_id, $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $value = form_section_html($row['assignment_id']);
        $label = form_section_html($row['assignment_title'] . ' #' . $row['assignment_id']);
        $is_selected = (string) $row['assignment_id'] === (string) $selected ? 'selected' : '';
        $html .= "<option value='{$value}' {$is_selected}>{$label}</option>";
    }
    $stmt->close();
    return $html;
}

function form_section_normalize_type($value)
{
    $value = strtolower(trim((string) $value));
    return array_key_exists($value, form_section_type_options()) ? $value : 'lecture';
}

function form_section_select_options(array $options, $selected)
{
    $html = '';
    foreach ($options as $value => $label) {
        $safe_value = form_section_html($value);
        $safe_label = form_section_html($label);
        $is_selected = (string) $value === (string) $selected ? 'selected' : '';
        $html .= "<option value='{$safe_value}' {$is_selected}>{$safe_label}</option>";
    }
    return $html;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    form_section_response(false, 'Invalid request method.');
}

if (!isset($_POST['_method']) || $_POST['_method'] !== 'GET') {
    form_section_response(false, 'Invalid section form request.');
}

if (!isset($_POST['course_id']) || trim($_POST['course_id']) === '') {
    form_section_response(false, 'Validation failed. Course ID is missing.');
}

$conn = db();
mmh_ensure_learning_schema($conn);
$course_id = trim($_POST['course_id']);

$course_stmt = $conn->prepare('SELECT course_title FROM courses WHERE course_id = ? LIMIT 1');
$course_stmt->bind_param('s', $course_id);
$course_stmt->execute();
$course_data = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

if (!$course_data) {
    form_section_response(false, 'Course not found.');
}

$is_edit = isset($_POST['section_id']) && trim($_POST['section_id']) !== '' && trim($_POST['section_id']) !== '__general__';
$section = [
    'section_id' => '',
    'section_type' => 'lecture',
    'custom_type' => '',
    'icon' => '',
    'title' => '',
    'description' => '',
    'sort_order' => '',
    'status' => 'published',
    'unlock_mode' => 'always',
    'completion_rule' => 'manual_completion',
    'unlock_at' => '',
    'unlock_timezone' => 'Africa/Cairo',
    'unlock_homework_id' => '',
    'manual_unlocked' => 0,
    'release_mode' => 'inherit',
    'release_override' => 'inherit',
    'release_at' => '',
    'release_timezone' => 'Asia/Riyadh',
    'release_occurrence_id' => '',
    'release_delay_minutes' => 0,
];

if ($is_edit) {
    $section_id = trim($_POST['section_id']);
    $section_stmt = $conn->prepare('SELECT * FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
    $section_stmt->bind_param('ss', $section_id, $course_id);
    $section_stmt->execute();
    $section_data = $section_stmt->get_result()->fetch_assoc();
    $section_stmt->close();

    if (!$section_data) {
        form_section_response(false, 'Section not found.');
    }

    $section = array_merge($section, $section_data);
} else {
    $order_stmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM course_sections WHERE course_id = ?');
    $order_stmt->bind_param('s', $course_id);
    $order_stmt->execute();
    $order_row = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();
    $section['sort_order'] = (int) ($order_row['next_order'] ?? 1);
}

$safe_course_id = form_section_html($course_id);
$safe_section_id = form_section_html($section['section_id']);
$safe_title = form_section_html($section['title']);
$section_type = form_section_normalize_type($section['section_type'] ?? 'lecture');
$safe_custom_type = form_section_html($section['custom_type'] ?? '');
$icon = array_key_exists((string) ($section['icon'] ?? ''), form_section_icon_options()) ? (string) ($section['icon'] ?? '') : '';
$type_options_html = form_section_select_options(form_section_type_options(), $section_type);
$icon_options_html = form_section_select_options(form_section_icon_options(), $icon);
$custom_type_class = $section_type === 'custom' ? 'col-12 col-lg-6 p-2' : 'col-12 col-lg-6 p-2 d-none';
$safe_description = form_section_html($section['description']);
$section_metadata = mmh_hierarchical_metadata_decode($section['metadata'] ?? '');
$section_topic_options = ['' => 'Use no topic'];
foreach (mmh_academic_topic_list($conn, $course_id, false) as $topic) {
    $topic_id = (int) ($topic['id'] ?? 0);
    if ($topic_id < 1) { continue; }
    $prefix = (int) ($topic['parent_topic_id'] ?? 0) > 0 ? '↳ ' : '';
    $section_topic_options[$topic_id] = $prefix . (string) ($topic['title'] ?? 'Topic');
}
$section_metadata_topic_primary = form_section_select_options($section_topic_options, $section_metadata['primary_topic_id'] ?? '');
$section_metadata_topic_secondary = form_section_select_options($section_topic_options, $section_metadata['secondary_topic_id'] ?? '');
$section_metadata_topic_subtopic = form_section_select_options($section_topic_options, $section_metadata['subtopic_id'] ?? '');
$section_metadata_difficulty = form_section_select_options(['' => 'Not specified', 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'mixed' => 'Mixed'], $section_metadata['difficulty'] ?? '');
$section_metadata_priority = form_section_select_options(['' => 'Not specified', 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High'], $section_metadata['priority'] ?? '');
$section_metadata_sequential = form_section_select_options(['' => 'Use course setting', 'yes' => 'Yes', 'no' => 'No'], array_key_exists('sequential_learning', $section_metadata) ? ($section_metadata['sequential_learning'] ? 'yes' : 'no') : '');
$section_metadata_calculator = form_section_select_options(['' => 'Not specified', 'yes' => 'Allowed', 'no' => 'Not allowed', 'mixed' => 'Mixed', 'not_applicable' => 'Not applicable'], $section_metadata['calculator_allowed'] ?? '');
$section_metadata_html = "
            <div class='col-12 p-2 mt-2'>
              <details class='course-builder-learning-rules border rounded-3 p-3 ds-surface-muted'>
                <summary class='fw-bold mb-2'><span class='fas fa-tags me-1' aria-hidden='true'></span> Section Metadata <small class='text-muted fw-normal'>Inherited by lessons unless overridden</small></summary>
                <div class='row pt-2'>
                  <div class='col-12 col-lg-6 p-2'><label class='form-label'>Subject</label><input class='form-control' maxlength='120' name='section_metadata_subject' value='" . form_section_html($section_metadata['subject'] ?? '') . "'></div>
                  <div class='col-12 col-lg-6 p-2'><label class='form-label'>Domain</label><input class='form-control' maxlength='120' name='section_metadata_domain' value='" . form_section_html($section_metadata['domain'] ?? '') . "'></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Primary Topic</label><select class='form-control' name='section_metadata_primary_topic_id'>{$section_metadata_topic_primary}</select></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Secondary Topic</label><select class='form-control' name='section_metadata_secondary_topic_id'>{$section_metadata_topic_secondary}</select></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Subtopic</label><select class='form-control' name='section_metadata_subtopic_id'>{$section_metadata_topic_subtopic}</select></div>
                  <div class='col-12 p-2'><label class='form-label'>Learning Objective</label><textarea class='form-control' rows='2' name='section_metadata_learning_objective'>" . form_section_html($section_metadata['learning_objective'] ?? '') . "</textarea></div>
                  <div class='col-12 col-lg-3 p-2'><label class='form-label'>Difficulty</label><select class='form-control' name='section_metadata_difficulty'>{$section_metadata_difficulty}</select></div>
                  <div class='col-12 col-lg-3 p-2'><label class='form-label'>Estimated Total Hours</label><input type='number' min='0.1' max='10000' step='0.25' class='form-control' name='section_metadata_estimated_hours' value='" . form_section_html($section_metadata['estimated_hours'] ?? '') . "'></div>
                  <div class='col-12 col-lg-3 p-2'><label class='form-label'>Priority</label><select class='form-control' name='section_metadata_priority'>{$section_metadata_priority}</select></div>
                  <div class='col-12 col-lg-3 p-2'><label class='form-label'>Recommended Order</label><input type='number' min='1' class='form-control' name='section_metadata_recommended_order' value='" . form_section_html($section_metadata['recommended_order'] ?? '') . "'></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Sequential Learning</label><select class='form-control' name='section_metadata_sequential_learning'>{$section_metadata_sequential}</select></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Exam Board</label><input class='form-control' maxlength='120' name='section_metadata_exam_board' value='" . form_section_html($section_metadata['exam_board'] ?? '') . "'></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Paper</label><input class='form-control' maxlength='120' name='section_metadata_paper' value='" . form_section_html($section_metadata['paper'] ?? '') . "'></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Calculator</label><select class='form-control' name='section_metadata_calculator_allowed'>{$section_metadata_calculator}</select></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Chapter</label><input class='form-control' maxlength='120' name='section_metadata_chapter' value='" . form_section_html($section_metadata['chapter'] ?? '') . "'></div>
                  <div class='col-12 col-lg-4 p-2'><label class='form-label'>Tags <small class='text-muted'>(comma-separated)</small></label><input class='form-control' maxlength='1000' name='section_metadata_tags' value='" . form_section_html(implode(', ', $section_metadata['tags'] ?? [])) . "'></div>
                  <div class='col-12 p-2'><label class='form-label'>Skills <small class='text-muted'>(comma-separated)</small></label><input class='form-control' maxlength='1000' name='section_metadata_skills' value='" . form_section_html(implode(', ', $section_metadata['skills'] ?? [])) . "'></div>
                  <div class='col-12 p-2'><label class='form-label'>Section Summary</label><textarea class='form-control' rows='2' name='section_metadata_summary'>" . form_section_html($section_metadata['summary'] ?? '') . "</textarea></div>
                </div>
              </details>
            </div>";
$safe_sort_order = form_section_html($section['sort_order']);
$status = form_section_status($section['status']);
$published_selected = $status === 'published' ? 'selected' : '';
$draft_selected = $status === 'draft' ? 'selected' : '';
$unlock_mode = form_section_normalize_option($section['unlock_mode'] ?? 'always', form_section_unlock_mode_options(), 'always');
$completion_rule = form_section_normalize_option($section['completion_rule'] ?? 'manual_completion', form_section_completion_rule_options(), 'manual_completion');
$unlock_timezone = form_section_normalize_option($section['unlock_timezone'] ?? 'Africa/Cairo', form_section_timezone_options(), 'Africa/Cairo');
$safe_unlock_at = form_section_html(form_section_datetime_local($section['unlock_at'] ?? ''));
$unlock_mode_options_html = form_section_select_options(form_section_unlock_mode_options(), $unlock_mode);
$completion_rule_options_html = form_section_select_options(form_section_completion_rule_options(), $completion_rule);
$timezone_options_html = form_section_select_options(form_section_timezone_options(), $unlock_timezone);
$homework_options_html = form_section_homework_options($conn, $course_id, $safe_section_id, $section['unlock_homework_id'] ?? '');
$manual_unlocked_checked = !empty($section['manual_unlocked']) ? 'checked' : '';
$release_mode = mmh_section_release_normalize_mode($section['release_mode'] ?? 'inherit');
$release_override = mmh_section_release_normalize_override($section['release_override'] ?? 'inherit');
$release_timezone = mmh_section_release_timezone_name($section['release_timezone'] ?? 'Asia/Riyadh');
$release_at_local = form_section_html(mmh_section_release_local_input($section['release_at'] ?? '', $release_timezone));
$release_delay = max(0, min(10080, (int) ($section['release_delay_minutes'] ?? 0)));
$release_mode_options_html = form_section_select_options(mmh_section_release_modes(), $release_mode);
$release_override_options_html = form_section_select_options([
    'inherit' => 'Use selected release rule',
    'locked' => 'Keep locked',
    'unlocked' => 'Unlock now',
], $release_override);
$release_occurrence_id = trim((string) ($section['release_occurrence_id'] ?? ''));
$release_occurrence_options_html = "<option value=''>Select a live-session occurrence</option>";
$release_occurrence_found = false;
foreach (form_section_reporting_occurrences($conn, $course_id, $section_id) as $occurrence) {
    $occurrence_id = (string) ($occurrence['occurrence_id'] ?? '');
    if ($occurrence_id === '') { continue; }
    $occurrence_timezone = $occurrence['timezone'] ?? 'Asia/Riyadh';
    $start_label = mmh_section_release_format_timestamp(mmh_section_release_timestamp($occurrence['scheduled_start_at'] ?? ''), $occurrence_timezone);
    $end_label = mmh_section_release_format_timestamp(mmh_section_release_timestamp($occurrence['scheduled_end_at'] ?? ''), $occurrence_timezone);
    $status_label = ucfirst((string) ($occurrence['status'] ?? 'scheduled'));
    $label = trim((string) ($occurrence['schedule_title'] ?? 'Live session')) . ' — ' . $start_label . ($end_label !== '' ? ' to ' . $end_label : '') . ' (' . $status_label . ')';
    $selected = $occurrence_id === $release_occurrence_id ? 'selected' : '';
    $release_occurrence_options_html .= "<option value='" . form_section_html($occurrence_id) . "' {$selected}>" . form_section_html($label) . "</option>";
    $release_occurrence_found = $release_occurrence_found || $occurrence_id === $release_occurrence_id;
}
if ($release_occurrence_id !== '' && !$release_occurrence_found) {
    $release_occurrence_options_html .= "<option value='" . form_section_html($release_occurrence_id) . "' selected>Linked occurrence is unavailable (section remains locked)</option>";
}
$release_preview = mmh_section_release_state($conn, array_merge($section, ['course_id' => $course_id]));
$release_preview_text = $release_preview['locked'] ? ($release_preview['reason'] ?? 'Locked') : (($release_preview['release_label'] ?? '') !== '' ? 'Available since ' . $release_preview['release_label'] : 'Available now');
$release_preview_class = $release_preview['locked'] ? 'text-warning' : 'text-success';
$modal_title = $is_edit ? 'Edit Section' : 'Add Section';
$form_id = $is_edit ? 'updateSection' : 'addSection';
$form_method = $is_edit ? 'UPDATE' : 'POST';
$submit_label = $is_edit ? 'Save Section' : 'Create Section';

$html_response = "
<div class='modal fade show' id='response-html-modal' tabindex='-1' aria-labelledby='courseBuilderSectionModalLabel' aria-hidden='true'>
  <div class='modal-dialog modal-lg'>
    <div class='modal-content'>
      <div class='modal-header'>
        <h5 class='modal-title' id='courseBuilderSectionModalLabel'>{$modal_title}</h5>
        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
      </div>
      <div class='modal-body'>
        <form action='' method='POST' class='courseBuilderSectionForm' id='{$form_id}'>
          <div class='row'>
            <div class='col-12 col-lg-6 p-2'>
              <label class='form-label'>Section Type</label>
              <select name='section_type' class='form-control sectionTypeSelector'>
                {$type_options_html}
              </select>
            </div>
            <div class='{$custom_type_class}' data-custom-section-type>
              <label class='form-label'>Custom Type</label>
              <input type='text' name='custom_type' maxlength='80' class='form-control' value='{$safe_custom_type}' placeholder='Example: Workshop'>
            </div>
            <div class='col-12 p-2'>
              <label class='form-label'>Section Title</label>
              <input type='text' name='title' required maxlength='190' class='form-control' value='{$safe_title}' placeholder='Example: Lecture 1'>
            </div>
            <div class='col-12 col-lg-6 p-2'>
              <label class='form-label'>Section Icon</label>
              <select name='icon' class='form-control'>
                {$icon_options_html}
              </select>
            </div>
            <div class='col-12 p-2'>
              <label class='form-label'>Description <small class='text-muted'>(optional)</small></label>
              <textarea name='description' class='form-control' rows='3' placeholder='Optional teacher-facing description'>{$safe_description}</textarea>
            </div>
            <div class='col-12 col-lg-6 p-2'>
              <label class='form-label'>Visibility</label>
              <select name='status' class='form-control'>
                <option value='published' {$published_selected}>Published</option>
                <option value='draft' {$draft_selected}>Draft</option>
              </select>
            </div>
            <div class='col-12 col-lg-6 p-2'>
              <label class='form-label'>Sort Order</label>
              <input type='number' name='sort_order' min='1' class='form-control' value='{$safe_sort_order}'>
            </div>

            {$section_metadata_html}

            <div class='col-12 p-2 mt-2'>
              <details class='course-builder-learning-rules border rounded-3 p-3 ds-surface-muted' open>
                <summary class='fw-bold mb-2'><span class='fas fa-clock me-1'></span> Content Release</summary>
                <small class='text-muted d-block mb-2'>Controls when enrolled students can open this published section. General remains available.</small>
                <div class='row'>
                  <div class='col-12 col-lg-6 p-2'>
                    <label class='form-label'>Release Mode</label>
                    <select name='release_mode' class='form-control sectionReleaseModeSelector'>{$release_mode_options_html}</select>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-release-override>
                    <label class='form-label'>Teacher Override</label>
                    <select name='release_override' class='form-control'>{$release_override_options_html}</select>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-release-schedule>
                    <label class='form-label'>Release Date &amp; Time</label>
                    <input type='datetime-local' name='release_at' class='form-control' value='{$release_at_local}'>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-release-schedule>
                    <label class='form-label'>Timezone</label>
                    <select name='release_timezone' class='form-control'>
                      <option value='Asia/Riyadh' " . ($release_timezone === 'Asia/Riyadh' ? 'selected' : '') . ">Asia/Riyadh</option>
                      <option value='Africa/Cairo' " . ($release_timezone === 'Africa/Cairo' ? 'selected' : '') . ">Africa/Cairo</option>
                      <option value='UTC' " . ($release_timezone === 'UTC' ? 'selected' : '') . ">UTC</option>
                    </select>
                  </div>
                  <div class='col-12 p-2'>
                    <label class='form-label'>Linked Live-Session Occurrence <small class='text-muted'>(attendance and reporting)</small></label>
                    <select name='release_occurrence_id' class='form-control'>{$release_occurrence_options_html}</select>
                    <small class='text-muted'>Only occurrences for this course are available. This links attendance to the section. It affects availability only when the selected release mode is based on a live session.</small>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-release-delay>
                    <label class='form-label'>Delay After Session Ends</label>
                    <input type='number' name='release_delay_minutes' min='0' max='10080' step='1' class='form-control' value='{$release_delay}'>
                    <small class='text-muted'>Minutes after the scheduled end time.</small>
                  </div>
                  <div class='col-12 p-2' data-release-preview><small class='{$release_preview_class}'><span class='fas fa-info-circle me-1'></span>" . form_section_html($release_preview_text) . "</small></div>
                </div>
              </details>
            </div>

            <div class='col-12 p-2 mt-2'>
              <div class='course-builder-learning-rules border rounded-3 p-3 ds-surface-muted'>
                <div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2'>
                  <div>
                    <div class='fw-bold'><span class='fas fa-lock-open me-1'></span> Learning Rules</div>
                    <small class='text-muted'>Rules only apply when Sequential Learning is enabled for this course or student.</small>
                  </div>
                </div>
                <div class='row'>
                  <div class='col-12 col-lg-6 p-2'>
                    <label class='form-label'>Unlock Mode</label>
                    <select name='unlock_mode' class='form-control sectionUnlockModeSelector'>
                      {$unlock_mode_options_html}
                    </select>
                  </div>
                  <div class='col-12 col-lg-6 p-2'>
                    <label class='form-label'>Completion Requirement</label>
                    <select name='completion_rule' class='form-control sectionCompletionRuleSelector'>
                      {$completion_rule_options_html}
                    </select>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-learning-unlock-date>
                    <label class='form-label'>Unlock Date & Time</label>
                    <input type='datetime-local' name='unlock_at' class='form-control' value='{$safe_unlock_at}'>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-learning-unlock-date>
                    <label class='form-label'>Timezone</label>
                    <select name='unlock_timezone' class='form-control'>
                      {$timezone_options_html}
                    </select>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-learning-homework>
                    <label class='form-label'>Homework</label>
                    <select name='unlock_homework_id' class='form-control'>
                      {$homework_options_html}
                    </select>
                    <small class='text-muted'>Shows homework already created inside this section.</small>
                  </div>
                  <div class='col-12 col-lg-6 p-2' data-learning-manual-unlock>
                    <label class='form-label d-block'>Teacher Override</label>
                    <label class='form-check d-flex align-items-center gap-2 mb-0'>
                      <input type='checkbox' name='manual_unlocked' value='1' class='form-check-input' {$manual_unlocked_checked}>
                      <span>Manually unlock this section</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <input type='hidden' name='course_id' value='{$safe_course_id}'>
          <input type='hidden' name='section_id' value='{$safe_section_id}'>
          <input type='hidden' name='_method' value='{$form_method}'>
          <div class='modal-footer px-0 pb-0'>
            <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Cancel</button>
            <button type='submit' class='btn btn-outline-primary submitBtn'>{$submit_label}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>";

form_section_response(true, 'Section form loaded successfully.', [
    'html' => $html_response,
    'mode' => $is_edit ? 'edit' : 'add',
]);
?>
