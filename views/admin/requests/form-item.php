<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/CourseResourceResolver.php';
require_once 'inc/TimedExam.php';
require_once 'inc/AssignmentModelAnswerAccess.php';

header('Content-Type: application/json; charset=utf-8');

function form_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function form_item_column_exists(mysqli $conn, $column)
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_items' AND COLUMN_NAME = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function form_item_table_exists(mysqli $conn, $table)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function form_item_status_supports_hidden(mysqli $conn)
{
    $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_items' AND COLUMN_NAME = 'status' LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return isset($row['COLUMN_TYPE']) && strpos((string) $row['COLUMN_TYPE'], "'hidden'") !== false;
}

function form_item_json_value($data, $key, $default = '')
{
    return htmlspecialchars((string) ($data[$key] ?? $default), ENT_QUOTES, 'UTF-8');
}

function form_item_textarea_value($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function form_item_select_options(array $options, $selected)
{
    $html = '';
    foreach ($options as $value => $label) {
        $safe_value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $safe_label = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        $is_selected = ((string) $value === (string) $selected) ? 'selected' : '';
        $html .= "<option value='{$safe_value}' {$is_selected}>{$safe_label}</option>";
    }
    return $html;
}

function form_item_multi_select_options(array $options, array $selected)
{
    $selected = array_map('strval', $selected);
    $html = '';
    foreach ($options as $value => $label) {
        $safe_value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $safe_label = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        $is_selected = in_array((string) $value, $selected, true) ? 'selected' : '';
        $html .= "<option value='{$safe_value}' {$is_selected}>{$safe_label}</option>";
    }
    return $html;
}

function form_item_first_href($html)
{
    if (preg_match('/href=["\']([^"\']+)["\']/i', (string) $html, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

/**
 * Presents a single safe legacy target through the same structured-resource
 * editor. The original item_description is never changed until the admin
 * explicitly saves; add-item.php retains it as the rollback source.
 */
function form_item_resource_adapter(array $item, array $resolved): array
{
    $url = (string) ($resolved['url'] ?? '');
    $template = strtolower(trim((string) ($item['template_type'] ?? $item['item_type'] ?? '')));
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
    $type = match ($template) {
        'recording', 'video' => 'recording',
        'pdf' => 'pdf',
        'download' => 'download',
        'teams', 'live_session' => 'teams',
        'youtube', 'youtube_video' => 'youtube',
        'google_drive' => str_contains($path, '/folders/') ? 'google_drive_folder' : 'google_drive',
        'assignment_model_answer' => 'model_answer',
        'notes' => 'notes',
        default => 'external_link',
    };
    if (str_contains($host, 'drive.google.com') || str_contains($host, 'docs.google.com')) {
        $type = str_contains($path, '/folders/') ? 'google_drive_folder' : 'google_drive';
    } elseif (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
        $type = 'youtube';
    } elseif (mmh_course_resource_is_microsoft_stream_embed_url($url)) {
        $type = 'video';
    } elseif (str_contains($host, 'teams.microsoft.com')) {
        $type = 'teams';
    } elseif (preg_match('/\.pdf(?:$|[?#])/', $url)) {
        $type = 'pdf';
    }
    $provider = match ($type) {
        'google_drive', 'google_drive_folder' => 'google_drive',
        'youtube' => 'youtube',
        'recording' => 'sharepoint',
        'video' => mmh_course_resource_is_microsoft_stream_embed_url($url) ? 'microsoft_stream' : 'external',
        'teams' => 'teams',
        'pdf' => 'pdf',
        default => 'external',
    };
    return ['type' => $type, 'provider' => $provider, 'url' => $url, 'embed' => ($resolved['action'] ?? '') === 'embed'];
}

function form_item_datetime_local($value)
{
    if (empty($value)) {
        return '';
    }
    $time = strtotime((string) $value);
    return $time ? date('Y-m-d\TH:i', $time) : '';
}

function form_item_status_value($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === 'hidden' || $value === '0') {
        return 'hidden';
    }
    if ($value === 'draft') {
        return 'draft';
    }
    return 'published';
}

// Form rendering is read-only. The current route uses GET; accept the
// previous POST + _method=GET shape only for compatibility with open tabs.
$form_item_request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$form_item_legacy_read = $form_item_request_method === 'POST' && (string) ($_POST['_method'] ?? '') === 'GET';
if ($form_item_request_method !== 'GET' && !$form_item_legacy_read) {
    form_item_response(false, 'Invalid lesson form request.');
}

$form_item_request_data = $form_item_request_method === 'GET' ? $_GET : $_POST;

if (!isset($form_item_request_data['course_id']) || trim((string) $form_item_request_data['course_id']) === '') {
    form_item_response(false, 'Validation failed. Course ID is missing.');
}

$conn = db();
$schema_ready = mmh_ensure_learning_schema($conn);
$course_id = trim((string) $form_item_request_data['course_id']);
$course_stmt = $conn->prepare('SELECT course_title, default_homework_score_mode FROM courses WHERE course_id = ? LIMIT 1');
$course_stmt->bind_param('s', $course_id);
$course_stmt->execute();
$course_data = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

if (!$course_data) {
    form_item_response(false, 'Course not found.');
}

$is_edit = isset($form_item_request_data['item_id']) && trim((string) $form_item_request_data['item_id']) !== '';
$item = [
    'item_id' => '',
    'item_title' => '',
    'item_type' => 'file',
    'item_description' => '',
    'section_id' => '',
    'page_order' => '',
    'status' => 'published',
    'template_type' => '',
    'template_data' => '',
    'assignment_id' => '',
    'due_date' => '',
    'duration_minutes' => null,
    'metadata' => '',
];

if ($is_edit) {
    $item_id = trim((string) $form_item_request_data['item_id']);
    $item_stmt = $conn->prepare('SELECT * FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
    $item_stmt->bind_param('ss', $item_id, $course_id);
    $item_stmt->execute();
    $item_data = $item_stmt->get_result()->fetch_assoc();
    $item_stmt->close();

    if (!$item_data) {
        form_item_response(false, 'Lesson not found.');
    }

    $item = array_merge($item, $item_data);
}

$template_data = [];
if (!empty($item['template_data'])) {
    $decoded = json_decode($item['template_data'], true);
    if (is_array($decoded)) {
        $template_data = $decoded;
    }
}

$item_metadata = [];
if (!empty($item['metadata'])) {
    $decoded_metadata = json_decode($item['metadata'], true);
    if (is_array($decoded_metadata)) {
        $item_metadata = $decoded_metadata;
    }
}

$teacher_templates = ['recording', 'notes', 'classified_assignment', 'custom_lesson', 'timed_exam'];
// Structured Resource remains an internal editor type and supports the new
// Notes translation. Legacy Assignment Model Answer records remain editable.
$creation_templates = array_merge($teacher_templates, ['resource']);
$editor_templates = array_merge($teacher_templates, ['assignment_model_answer', 'resource']);
$raw_template_type = $item['template_type'] ?: '';
$requested_template_type = isset($form_item_request_data['template_type']) ? trim((string) $form_item_request_data['template_type']) : '';
$template_type = $is_edit ? ($raw_template_type ?: 'custom_html') : ($requested_template_type ?: 'recording');
$legacy_resource_adapter = null;

// Translate new Notes requests into structured Resources. This preserves Notes
// as a first-class teacher concept while using the existing structured Resource
// implementation internally. Migrated Notes already use template_type=resource
// with resource_type=notes; new Notes now use the same architecture.
$is_structured_notes = false;
if (!$is_edit && $template_type === 'notes') {
    $template_type = 'resource';
    $is_structured_notes = true;
}

// Detect existing structured Notes (migrated or previously created structured Notes).
if ($is_edit && mmh_course_item_is_notes($item)) {
    $is_structured_notes = true;
}

if ($is_edit && !in_array($template_type, $editor_templates, true)) {
    $resolved_legacy_resource = mmh_course_resource_resolve($item);
    if (in_array((string) ($resolved_legacy_resource['action'] ?? ''), ['embed', 'redirect'], true) && !empty($resolved_legacy_resource['url'])) {
        $template_type = 'resource';
        $legacy_resource_adapter = form_item_resource_adapter($item, $resolved_legacy_resource);
    } else {
        $template_type = 'custom_html';
    }
}
if (!$is_edit && !in_array($template_type, $creation_templates, true)) {
    $template_type = 'recording';
}

$has_status = form_item_column_exists($conn, 'status');
$has_duration_minutes = form_item_column_exists($conn, 'duration_minutes');
$course_default_score_mode = mmh_academic_score_mode($course_data['default_homework_score_mode'] ?? 'disabled');
$course_title = htmlspecialchars($course_data['course_title'], ENT_QUOTES, 'UTF-8');
$safe_course_id = htmlspecialchars($course_id, ENT_QUOTES, 'UTF-8');
$safe_item_id = htmlspecialchars($item['item_id'], ENT_QUOTES, 'UTF-8');
$safe_title = htmlspecialchars($item['item_title'], ENT_QUOTES, 'UTF-8');
$safe_order = htmlspecialchars((string) $item['page_order'], ENT_QUOTES, 'UTF-8');
$safe_item_type = htmlspecialchars((string) $item['item_type'], ENT_QUOTES, 'UTF-8');
$default_duration_minutes = (!$is_edit && $is_structured_notes) ? 30 : ($item['duration_minutes'] ?? '');
$safe_duration_minutes = htmlspecialchars((string) $default_duration_minutes, ENT_QUOTES, 'UTF-8');
$editor_id = ($is_edit ? 'course_builder_edit_editor_' : 'course_builder_editor_') . mt_rand(1000, 999999);
$notes_editor_id = 'notes_' . $editor_id;
$assignment_editor_id = 'assignment_' . $editor_id;
$custom_lesson_editor_id = 'custom_lesson_' . $editor_id;
$legacy_editor_id = 'legacy_' . $editor_id;
$modal_title = $is_edit ? 'Edit Lesson' : "Add Lesson to {$course_title}";
$form_id = $is_edit ? 'updateItem' : 'addNewItem';
$form_method = $is_edit ? 'UPDATE' : 'POST';
$submit_label = $is_edit ? 'Save Changes' : 'Save Lesson';

$status_field = '';
if ($has_status) {
    $supports_hidden_status = form_item_status_supports_hidden($conn);
    $status = form_item_status_value($item['status'] ?? 'published');
    $published_selected = ($status === 'published') ? 'selected' : '';
    $hidden_selected = ($status === 'hidden') ? 'selected' : '';
    $draft_selected = ($status === 'draft') ? 'selected' : '';
    $hidden_option = $supports_hidden_status ? "<option value='hidden' {$hidden_selected}>Hidden</option>" : '';
    $status_field = "
      <div class='col-12 col-lg-6 p-2'>
        <div class='col-12'>Visibility</div>
        <div class='col-12 pt-3'>
          <select class='form-control' name='status'>
            <option value='published' {$published_selected}>Published</option>
            {$hidden_option}
            <option value='draft' {$draft_selected}>Draft</option>
          </select>
        </div>
      </div>";
}

$order_field = '';
if ($is_edit) {
    $order_field = "
      <div class='col-12 col-lg-6 p-2'>
        <div class='col-12'>Order</div>
        <div class='col-12 pt-3'>
          <input type='number' name='page_order' min='1' class='form-control' value='{$safe_order}'>
        </div>
      </div>";
}

$duration_field = '';
if ($has_duration_minutes) {
    $duration_field = "
      <div class='col-12 col-lg-6 p-2'>
        <label class='form-label' for='duration_minutes'>Estimated duration <small class='ds-text-muted'>(optional)</small></label>
        <div class='input-group pt-3'>
          <input id='duration_minutes' type='number' name='duration_minutes' min='1' max='1440' step='1' inputmode='numeric' class='form-control' value='{$safe_duration_minutes}' placeholder='30' aria-describedby='duration_minutes_help'>
          <span class='input-group-text'>minutes</span>
        </div>
        <small id='duration_minutes_help' class='ds-text-muted'>Estimated time a student needs to complete this lesson.</small>
      </div>";
}

$section_field = '';
$has_sections = form_item_column_exists($conn, 'section_id') && form_item_table_exists($conn, 'course_sections');
if ($has_sections) {
    $current_section_id = $is_edit ? (string) ($item['section_id'] ?? '') : (string) ($form_item_request_data['section_id'] ?? '');
    if ($current_section_id === '__general__') {
        $current_section_id = '';
    }

    $require_explicit_section = !$is_edit && $template_type === 'classified_assignment';
    $section_options = '';
    if ($current_section_id === '' && $require_explicit_section) {
        $section_options .= "<option value='' selected disabled>Choose a section or General</option>";
        $section_options .= "<option value='__general__'>General / explicitly unsectioned</option>";
    } else {
        $section_options .= "<option value='' " . ($current_section_id === '' ? 'selected' : '') . ">General / explicitly unsectioned</option>";
    }
    $section_stmt = $conn->prepare('SELECT section_id, title, status FROM course_sections WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
    if ($section_stmt) {
        $section_stmt->bind_param('s', $course_id);
        $section_stmt->execute();
        $section_result = $section_stmt->get_result();
        while ($section = $section_result->fetch_assoc()) {
            $section_id = (string) $section['section_id'];
            $section_label = (string) $section['title'];
            if (($section['status'] ?? 'published') === 'draft') {
                $section_label .= ' (Draft)';
            }
            $safe_section_id = htmlspecialchars($section_id, ENT_QUOTES, 'UTF-8');
            $safe_section_label = htmlspecialchars($section_label, ENT_QUOTES, 'UTF-8');
            $selected = $section_id === $current_section_id ? 'selected' : '';
            $section_options .= "<option value='{$safe_section_id}' {$selected}>{$safe_section_label}</option>";
        }
        $section_stmt->close();
    }

    $section_field = "
      <div class='col-12 col-lg-6 p-2'>
        <div class='col-12'>Section <small class='text-muted'>(choose a named section or General)</small></div>
        <div class='col-12 pt-3'>
          <select class='form-control' name='section_id'" . ($require_explicit_section ? ' required' : '') . ">
            {$section_options}
          </select>
        </div>
      </div>";
}

$template_cards = [
    'recording' => ['fas fa-play-circle', 'Recording', 'Paste a SharePoint / Microsoft Stream sharing link.'],
    'notes' => ['far fa-file-alt', 'Notes', 'Add a structured Notes resource for the LMS viewer.'],
    'classified_assignment' => ['fas fa-clipboard-list', 'Classified Assignment', 'Create one Homework lesson with resources and upload workflow.'],
    'custom_lesson' => ['fas fa-puzzle-piece', 'Custom Lesson', 'Build any flexible lesson with a label, icon, and content.'],
    'timed_exam' => ['fas fa-stopwatch', 'Timed Exam', 'Fixed Window exam with protected paper, secure answers, and grading.'],
];

$cards_html = '';
foreach ($template_cards as $key => $card) {
    $active = $template_type === $key ? 'border-primary bg-light' : '';
    $cards_html .= "
      <div class='col-12 col-md-6 p-2'>
        <button type='button' class='template-card btn text-start border w-100 p-3 {$active}' data-template='{$key}' style='min-height: 115px'>
          <div class='font-3'><span class='{$card[0]} ds-icon ds-icon-xl ds-icon-secondary' aria-hidden='true'></span></div>
          <div class='fw-bold'>{$card[1]}</div>
          <div class='text-muted small'>{$card[2]}</div>
        </button>
      </div>";
}

$pane_templates = array_merge($teacher_templates, ['assignment_model_answer', 'custom_html', 'resource']);
$pane_class = [];
foreach ($pane_templates as $pane_template) {
    $pane_class[$pane_template] = $template_type === $pane_template ? 'template-pane p-3' : 'template-pane p-3 d-none';
}

$item_hidden = $is_edit ? "<input type='hidden' name='item_id' value='{$safe_item_id}' />" : '';
$existing_assignment_id_raw = (string) ($template_data['assignment_id'] ?? $item['assignment_id'] ?? '');
$existing_assignment_id = htmlspecialchars($existing_assignment_id_raw, ENT_QUOTES, 'UTF-8');
$assignment_record = [];
if ($template_type === 'classified_assignment' && $existing_assignment_id_raw !== '') {
    $assignment_stmt = $conn->prepare('SELECT * FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
    if ($assignment_stmt) {
        $assignment_stmt->bind_param('ss', $existing_assignment_id_raw, $course_id);
        $assignment_stmt->execute();
        $assignment_record = $assignment_stmt->get_result()->fetch_assoc() ?: [];
        $assignment_stmt->close();
    }
}
$model_answer_access_mode = $template_type === 'classified_assignment' && $existing_assignment_id_raw !== ''
    ? mmh_assignment_model_answer_access_mode($conn, $existing_assignment_id_raw)
    : 'all';
$model_answer_access_selected_ids = $template_type === 'classified_assignment' && $existing_assignment_id_raw !== ''
    ? mmh_assignment_model_answer_access_selected_ids($conn, $existing_assignment_id_raw)
    : [];
$model_answer_access_students_html = '';
if ($template_type === 'classified_assignment') {
    foreach (mmh_assignment_model_answer_access_enrolled_students($conn, $course_id) as $student) {
        $studentId = (int) ($student['user_id'] ?? 0);
        if ($studentId <= 0) continue;
        $studentName = trim((string) ($student['full_name'] ?? '')) ?: (string) ($student['username'] ?? 'Student');
        $searchText = strtolower($studentName . ' ' . (string) ($student['username'] ?? ''));
        $checked = in_array($studentId, $model_answer_access_selected_ids, true) ? ' checked' : '';
        $model_answer_access_students_html .= "<label class='model-answer-access-student' data-student-search='" . htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') . "'><input type='checkbox' name='model_answer_access_student_ids[]' value='{$studentId}'{$checked}><span>" . htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') . "</span><small>" . htmlspecialchars((string) ($student['username'] ?? ''), ENT_QUOTES, 'UTF-8') . "</small></label>";
    }
    if ($model_answer_access_students_html === '') $model_answer_access_students_html = "<div class='text-muted small'>No active students are enrolled in this course yet.</div>";
}
$legacy_content = $item['item_description'];
$notes_content = $template_type === 'notes' ? ($template_data['content'] ?? $item['item_description']) : '';
$assignment_instructions = $template_type === 'classified_assignment' ? ($template_data['instructions'] ?? $template_data['description'] ?? $assignment_record['assignment_description'] ?? '') : '';
$recording_url = $template_type === 'recording' ? ($template_data['url'] ?? form_item_first_href($item['item_description'])) : '';
$recording_link_status = $template_type === 'recording' ? mmh_course_resource_microsoft_recording_status($recording_url) : ['state' => ''];
$recording_warning = ($recording_link_status['state'] ?? '') === 'legacy_embed'
    ? "<div class='alert alert-warning mt-2 mb-0'>This is a legacy Microsoft embed link. Replace it with a normal SharePoint / Microsoft Stream sharing link before saving.</div>"
    : '';
$assignment_url = $template_type === 'classified_assignment' ? ($template_data['homework_resource']['url'] ?? $template_data['url'] ?? form_item_first_href($item['item_description'])) : '';
$assignment_url_2 = $template_type === 'classified_assignment' ? ($template_data['homework_resource_2']['url'] ?? '') : '';
$model_answer_url = $template_type === 'classified_assignment'
    ? ($template_data['model_answer_resource']['url'] ?? '')
    : ($template_type === 'assignment_model_answer' ? ($template_data['url'] ?? form_item_first_href($item['item_description'])) : '');
$model_answer_release = $template_type === 'classified_assignment'
    ? (string) ($template_data['model_answer_resource']['release'] ?? $template_data['model_answer_release'] ?? 'hidden')
    : 'hidden';
if (!in_array($model_answer_release, ['hidden', 'immediate', 'after_due', 'after_submission'], true)) $model_answer_release = 'hidden';
$recording_lesson_number = $template_type === 'recording' ? ($template_data['lesson_number'] ?? '') : '';
$assignment_deadline = form_item_datetime_local($template_data['due_date'] ?? $item['due_date'] ?? $assignment_record['due_date'] ?? '');
$assignment_late_submission_enabled = (int) ($template_data['late_submission_enabled'] ?? $assignment_record['late_submission_enabled'] ?? 0);
$assignment_late_submission_until = form_item_datetime_local($template_data['late_submission_until'] ?? $assignment_record['late_submission_until'] ?? '');
$assignment_allow_self_score = array_key_exists('allow_self_score', $template_data) ? (int) !empty($template_data['allow_self_score']) : (int) ($assignment_record['allow_self_score'] ?? 0);
$assignment_require_teacher_verification = array_key_exists('require_teacher_verification', $template_data) ? (int) !empty($template_data['require_teacher_verification']) : (int) ($assignment_record['require_teacher_verification'] ?? 1);
$assignment_max_score = $template_type === 'classified_assignment' ? (string) ($template_data['max_score'] ?? $assignment_record['max_score'] ?? '') : '';
$assignment_homework_type = $template_type === 'classified_assignment' ? (string) ($template_data['homework_type'] ?? 'classified_assignment') : 'classified_assignment';
$assignment_score_mode = $template_type === 'classified_assignment' && $is_edit
    ? mmh_academic_score_mode_from_flags($assignment_allow_self_score, $assignment_require_teacher_verification)
    : 'inherit';

$timed_exam_config = $template_type === 'timed_exam' ? mmh_timed_exam_load_for_item($conn, $course_id, (string) ($item['item_id'] ?? ''), true) : null;
$timed_exam_start = mmh_timed_exam_datetime_for_input($timed_exam_config['scheduled_start_at_utc'] ?? '');
$timed_exam_duration = (string) ($timed_exam_config['duration_minutes'] ?? 60);
$timed_exam_grace = (string) ($timed_exam_config['grace_minutes'] ?? 0);
$timed_exam_max_attempts = (string) ($timed_exam_config['max_attempts'] ?? 1);
$timed_exam_allowed_types = (string) ($timed_exam_config['allowed_answer_types'] ?? 'pdf,jpg,jpeg,png');
$timed_exam_max_size_mb = (string) max(1, (int) round(((int) ($timed_exam_config['max_file_size_bytes'] ?? 10485760)) / 1048576));
$timed_exam_paper_url = (string) ($timed_exam_config['paper_external_url'] ?? '');
$timed_exam_paper_fallback = (string) ($timed_exam_config['paper_fallback_instructions'] ?? '');
$timed_exam_paper_source = (string) ($timed_exam_config['paper_source'] ?? 'external_link');
$timed_exam_has_legacy_upload = !empty($timed_exam_config['paper_storage_key']) && $timed_exam_paper_url === '';
$timed_exam_view_allowed = !array_key_exists('paper_view_allowed', (array) $timed_exam_config) || !empty($timed_exam_config['paper_view_allowed']);
$timed_exam_download_allowed = !array_key_exists('paper_download_allowed', (array) $timed_exam_config) || !empty($timed_exam_config['paper_download_allowed']);
$timed_exam_late_allowed = !array_key_exists('late_submission_allowed', (array) $timed_exam_config) || !empty($timed_exam_config['late_submission_allowed']);
$timed_exam_max_marks = (string) ($timed_exam_config['max_marks'] ?? '');
$timed_exam_results_release = mmh_timed_exam_datetime_for_input($timed_exam_config['results_release_at_utc'] ?? '');
$timed_exam_recovery_start = mmh_timed_exam_datetime_for_input($timed_exam_config['recovery_window_start_at_utc'] ?? '');
$timed_exam_recovery_end = mmh_timed_exam_datetime_for_input($timed_exam_config['recovery_window_end_at_utc'] ?? '');
$timed_exam_recovery_allowed = !empty($timed_exam_config['recovery_allowed']);
$timed_exam_admin_link = $timed_exam_config ? rtrim((string) ($baseUrl ?? ''), '/') . '/admin/timed-exam-submissions/' . rawurlencode($course_id) . '/' . (int) $timed_exam_config['id'] : '';

$academic_source = $template_type === 'classified_assignment' ? array_merge($item_metadata, $assignment_record) : $item_metadata;
$academic_primary_topic_id = (int) ($academic_source['topic_id'] ?? $academic_source['primary_topic_id'] ?? 0);
$academic_subtopic_id = (int) ($academic_source['subtopic_id'] ?? 0);
$academic_additional_topic_ids = mmh_academic_parse_id_list($academic_source['additional_topic_ids'] ?? []);
$academic_difficulty = (string) ($academic_source['difficulty'] ?? '');
$academic_estimated_time = (string) ($academic_source['estimated_time'] ?? '');
$academic_objectives = (string) ($academic_source['learning_objectives'] ?? '');
$academic_keywords = (string) ($academic_source['keywords'] ?? '');
$academic_week = (string) ($academic_source['week'] ?? '');
$academic_unit = (string) ($academic_source['unit'] ?? '');
$academic_term = (string) ($academic_source['term'] ?? '');
$academic_exam_board = (string) ($academic_source['exam_board'] ?? '');
$academic_syllabus_code = (string) ($academic_source['syllabus_code'] ?? '');
$academic_paper = (string) ($academic_source['paper'] ?? '');
$academic_calculator_mode = (string) ($academic_source['calculator_mode'] ?? '');
$academic_revision_priority = (string) ($academic_source['importance'] ?? '');
$academic_teacher_notes = (string) ($academic_source['teacher_notes'] ?? '');
$assignment_passing_score = (string) ($assignment_record['passing_score'] ?? '');
$assignment_weight = (string) ($assignment_record['weight'] ?? '');
$assignment_category = (string) ($assignment_record['category'] ?? 'classified');
$assignment_recommended_recording = (string) ($assignment_record['recommended_recording_item_id'] ?? '');
$assignment_recommended_notes = (string) ($assignment_record['recommended_notes_item_id'] ?? '');
$assignment_recommended_revision = (string) ($assignment_record['recommended_revision_item_id'] ?? '');

$topic_rows = mmh_academic_topic_list($conn, $course_id, false);
$root_topic_options = ['' => 'Select a topic'];
$subtopic_options = ['' => 'No subtopic'];
$additional_topic_options = [];
$topic_titles = [];
$topic_states = [];
foreach ($topic_rows as $topic_row) {
    $topic_id_value = (int) $topic_row['id'];
    $topic_titles[$topic_id_value] = (string) $topic_row['title'];
    $topic_states[$topic_id_value] = (int) $topic_row['is_active'] === 1 ? 'active' : 'inactive';
    if ((int) $topic_row['parent_topic_id'] === 0) {
        $root_topic_options[$topic_id_value] = (string) $topic_row['title'] . ((int) $topic_row['is_active'] === 1 ? '' : ' (Inactive)');
    }
}
foreach ($topic_rows as $topic_row) {
    $topic_id_value = (int) $topic_row['id'];
    $parent_id_value = (int) $topic_row['parent_topic_id'];
    $prefix = $parent_id_value > 0 ? (($topic_titles[$parent_id_value] ?? 'Topic') . ' → ') : '';
    $label = $prefix . (string) $topic_row['title'] . ((int) $topic_row['is_active'] === 1 ? '' : ' (Inactive)');
    $additional_topic_options[$topic_id_value] = $label;
    if ($parent_id_value > 0) {
        $subtopic_options[$topic_id_value] = $label;
    }
}
$root_topic_options_html = form_item_select_options($root_topic_options, $academic_primary_topic_id);
$subtopic_options_html = form_item_select_options($subtopic_options, $academic_subtopic_id);
$additional_topic_options_html = form_item_multi_select_options($additional_topic_options, $academic_additional_topic_ids);
$difficulty_options_html = form_item_select_options(['' => 'Not specified', 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'mixed' => 'Mixed'], $academic_difficulty);
$calculator_options_html = form_item_select_options(['' => 'Not specified', 'calculator' => 'Calculator', 'non_calculator' => 'Non-calculator', 'mixed' => 'Mixed', 'not_applicable' => 'Not applicable'], $academic_calculator_mode);
$priority_options_html = form_item_select_options(['' => 'Not specified', 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High'], $academic_revision_priority);
$primary_topic_state_options_html = form_item_select_options(['keep' => 'Keep current state', 'active' => 'Active', 'inactive' => 'Inactive'], 'keep');
$subtopic_state_options_html = form_item_select_options(['keep' => 'Keep current state', 'active' => 'Active', 'inactive' => 'Inactive'], 'keep');

$section_overrides = mmh_hierarchical_metadata_item_overrides($item_metadata);
$section_override_modes = [];
foreach (mmh_hierarchical_metadata_fields() as $field) {
    $section_override_modes[$field] = form_item_select_options([
        'inherit' => 'Use Section Value',
        'override' => 'Override Value',
    ], array_key_exists($field, $section_overrides) ? 'override' : 'inherit');
}
$section_override_topics = ['' => 'No override'];
foreach ($additional_topic_options as $topic_id => $topic_label) {
    $section_override_topics[$topic_id] = $topic_label;
}
$section_override_primary_html = form_item_select_options($section_override_topics, $section_overrides['primary_topic_id'] ?? '');
$section_override_secondary_html = form_item_select_options($section_override_topics, $section_overrides['secondary_topic_id'] ?? '');
$section_override_subtopic_html = form_item_select_options($section_override_topics, $section_overrides['subtopic_id'] ?? '');
$section_metadata_overrides_html = "
            <details class='col-12 mx-0 mt-2 p-3 ds-surface-muted ds-border rounded-3' data-section-metadata-overrides>
              <summary class='fw-bold'>Metadata Overrides <small class='ds-text-muted fw-normal'>Optional — values otherwise inherit from the selected section</small></summary>
              <p class='small ds-text-muted mt-2 mb-1'>Only fields set to Override Value are stored on this lesson. Legacy lesson metadata remains compatible.</p>
              <div class='row pt-2'>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Subject</label><select class='form-control mb-1' name='section_override_mode_subject'>{$section_override_modes['subject']}</select><input class='form-control' maxlength='120' name='section_override_subject' value='" . form_item_json_value(['value' => $section_overrides['subject'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Domain</label><select class='form-control mb-1' name='section_override_mode_domain'>{$section_override_modes['domain']}</select><input class='form-control' maxlength='120' name='section_override_domain' value='" . form_item_json_value(['value' => $section_overrides['domain'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Primary Topic</label><select class='form-control mb-1' name='section_override_mode_primary_topic_id'>{$section_override_modes['primary_topic_id']}</select><select class='form-control' name='section_override_primary_topic_id'>{$section_override_primary_html}</select></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Secondary Topic</label><select class='form-control mb-1' name='section_override_mode_secondary_topic_id'>{$section_override_modes['secondary_topic_id']}</select><select class='form-control' name='section_override_secondary_topic_id'>{$section_override_secondary_html}</select></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Subtopic</label><select class='form-control mb-1' name='section_override_mode_subtopic_id'>{$section_override_modes['subtopic_id']}</select><select class='form-control' name='section_override_subtopic_id'>{$section_override_subtopic_html}</select></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Difficulty</label><select class='form-control mb-1' name='section_override_mode_difficulty'>{$section_override_modes['difficulty']}</select><select class='form-control' name='section_override_difficulty'>" . form_item_select_options(['' => 'Not specified', 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'mixed' => 'Mixed'], $section_overrides['difficulty'] ?? '') . "</select></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Estimated Hours</label><select class='form-control mb-1' name='section_override_mode_estimated_hours'>{$section_override_modes['estimated_hours']}</select><input type='number' min='0.1' step='0.25' class='form-control' name='section_override_estimated_hours' value='" . form_item_json_value(['value' => $section_overrides['estimated_hours'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Priority</label><select class='form-control mb-1' name='section_override_mode_priority'>{$section_override_modes['priority']}</select><select class='form-control' name='section_override_priority'>" . form_item_select_options(['' => 'Not specified', 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High'], $section_overrides['priority'] ?? '') . "</select></div>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Recommended Order</label><select class='form-control mb-1' name='section_override_mode_recommended_order'>{$section_override_modes['recommended_order']}</select><input type='number' min='1' class='form-control' name='section_override_recommended_order' value='" . form_item_json_value(['value' => $section_overrides['recommended_order'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Sequential Learning</label><select class='form-control mb-1' name='section_override_mode_sequential_learning'>{$section_override_modes['sequential_learning']}</select><select class='form-control' name='section_override_sequential_learning'>" . form_item_select_options(['' => 'Not specified', 'yes' => 'Yes', 'no' => 'No'], array_key_exists('sequential_learning', $section_overrides) ? ($section_overrides['sequential_learning'] ? 'yes' : 'no') : '') . "</select></div>
                <div class='col-12 p-2'><label class='form-label'>Learning Objective</label><select class='form-control mb-1' name='section_override_mode_learning_objective'>{$section_override_modes['learning_objective']}</select><textarea class='form-control' rows='2' name='section_override_learning_objective'>" . form_item_textarea_value($section_overrides['learning_objective'] ?? '') . "</textarea></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Exam Board</label><select class='form-control mb-1' name='section_override_mode_exam_board'>{$section_override_modes['exam_board']}</select><input class='form-control' maxlength='120' name='section_override_exam_board' value='" . form_item_json_value(['value' => $section_overrides['exam_board'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Paper</label><select class='form-control mb-1' name='section_override_mode_paper'>{$section_override_modes['paper']}</select><input class='form-control' maxlength='120' name='section_override_paper' value='" . form_item_json_value(['value' => $section_overrides['paper'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Calculator</label><select class='form-control mb-1' name='section_override_mode_calculator_allowed'>{$section_override_modes['calculator_allowed']}</select><select class='form-control' name='section_override_calculator_allowed'>" . form_item_select_options(['' => 'Not specified', 'yes' => 'Allowed', 'no' => 'Not allowed', 'mixed' => 'Mixed', 'not_applicable' => 'Not applicable'], $section_overrides['calculator_allowed'] ?? '') . "</select></div>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Chapter</label><select class='form-control mb-1' name='section_override_mode_chapter'>{$section_override_modes['chapter']}</select><input class='form-control' maxlength='120' name='section_override_chapter' value='" . form_item_json_value(['value' => $section_overrides['chapter'] ?? ''], 'value') . "'></div>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Tags</label><select class='form-control mb-1' name='section_override_mode_tags'>{$section_override_modes['tags']}</select><input class='form-control' maxlength='1000' name='section_override_tags' value='" . form_item_json_value(['value' => implode(', ', $section_overrides['tags'] ?? [])], 'value') . "' placeholder='Comma-separated'></div>
                <div class='col-12 p-2'><label class='form-label'>Skills</label><select class='form-control mb-1' name='section_override_mode_skills'>{$section_override_modes['skills']}</select><input class='form-control' maxlength='1000' name='section_override_skills' value='" . form_item_json_value(['value' => implode(', ', $section_overrides['skills'] ?? [])], 'value') . "' placeholder='Comma-separated'></div>
                <div class='col-12 p-2'><label class='form-label'>Summary</label><select class='form-control mb-1' name='section_override_mode_summary'>{$section_override_modes['summary']}</select><textarea class='form-control' rows='2' name='section_override_summary'>" . form_item_textarea_value($section_overrides['summary'] ?? '') . "</textarea></div>
              </div>
            </details>";
$score_mode_options_html = form_item_select_options([
    'inherit' => 'Use course default (' . ucwords(str_replace('_', ' ', $course_default_score_mode)) . ')',
    'disabled' => 'Disabled',
    'accept_automatically' => 'Accept Automatically',
    'require_teacher_verification' => 'Require Teacher Verification',
], $assignment_score_mode);
$assignment_category_options_html = form_item_select_options([
    'classified' => 'Classified',
    'worksheet' => 'Worksheet',
    'revision' => 'Revision',
    'past_paper_practice' => 'Past-paper practice',
    'quiz' => 'Quiz',
    'challenge' => 'Challenge',
    'other' => 'Other',
], $assignment_category);

$recommendation_options = ['' => 'None'];
$recommendation_recording_options = ['' => 'None'];
$recommendation_notes_options = ['' => 'None'];
$recommendation_stmt = $conn->prepare('SELECT item_id, item_title, template_type, template_data FROM course_items WHERE course_id = ? ORDER BY page_order ASC, id ASC');
if ($recommendation_stmt) {
    $recommendation_stmt->bind_param('s', $course_id);
    $recommendation_stmt->execute();
    $recommendation_result = $recommendation_stmt->get_result();
    while ($recommendation_item = $recommendation_result->fetch_assoc()) {
        $label = (string) $recommendation_item['item_title'];
        $recommendation_options[$recommendation_item['item_id']] = $label;
        if (($recommendation_item['template_type'] ?? '') === 'recording') {
            $recommendation_recording_options[$recommendation_item['item_id']] = $label;
        }
        if (mmh_course_item_is_notes($recommendation_item)) {
            $recommendation_notes_options[$recommendation_item['item_id']] = $label;
        }
    }
    $recommendation_stmt->close();
}
$recommendation_recording_options_html = form_item_select_options($recommendation_recording_options, $assignment_recommended_recording);
$recommendation_notes_options_html = form_item_select_options($recommendation_notes_options, $assignment_recommended_notes);
$recommendation_options_html = form_item_select_options($recommendation_options, $assignment_recommended_revision);

$custom_label_options = [
    'lesson' => 'Lesson',
    'lecture' => 'Lecture',
    'week' => 'Week',
    'unit' => 'Unit',
    'chapter' => 'Chapter',
    'revision' => 'Revision',
    'practice' => 'Practice',
    'bonus' => 'Bonus',
    'custom' => 'Custom',
];
$custom_icon_options = [
    'recording' => 'Recording',
    'notes' => 'Notes',
    'pdf' => 'PDF',
    'homework' => 'Homework',
    'model_answer' => 'Model Answer',
    'video' => 'Video',
    'revision' => 'Revision',
    'practice' => 'Practice',
    'download' => 'Download',
    'link' => 'Link',
    'star' => 'Star',
    'book' => 'Book',
    'graduation_cap' => 'Graduation Cap',
    'clipboard' => 'Clipboard',
    'folder' => 'Folder',
    'none' => 'None',
];
$custom_label_type = $template_type === 'custom_lesson' ? (string) ($template_data['label_type'] ?? 'lesson') : 'lesson';
if (!array_key_exists($custom_label_type, $custom_label_options)) {
    $custom_label_type = 'custom';
}
$custom_label_text = $template_type === 'custom_lesson' ? (string) ($template_data['custom_label'] ?? $template_data['label'] ?? '') : '';
$custom_icon = $template_type === 'custom_lesson' ? (string) ($template_data['icon'] ?? 'none') : 'none';
if (!array_key_exists($custom_icon, $custom_icon_options)) {
    $custom_icon = 'none';
}
$custom_content = $template_type === 'custom_lesson' ? ($template_data['content'] ?? $item['item_description']) : '';
$custom_label_options_html = form_item_select_options($custom_label_options, $custom_label_type);
$custom_icon_options_html = form_item_select_options($custom_icon_options, $custom_icon);
$custom_label_input_class = $custom_label_type === 'custom' ? 'form-control' : 'form-control d-none';

$structured_resource_type_options = [
    'google_drive' => 'Google Drive File', 'google_drive_folder' => 'Google Drive Folder', 'youtube' => 'YouTube Video',
    'recording' => 'Recording', 'video' => 'Video', 'pdf' => 'PDF', 'teams' => 'Teams Session', 'notes' => 'Notes',
    'model_answer' => 'Model Answer', 'worksheet' => 'Worksheet', 'revision_sheet' => 'Revision Sheet',
    'booklet' => 'Booklet', 'download' => 'Download', 'external_link' => 'External Link',
];
$structured_resource_provider_options = [
    'google_drive' => 'Google Drive', 'youtube' => 'YouTube', 'sharepoint' => 'SharePoint',
    'teams' => 'Microsoft Teams', 'microsoft_stream' => 'Microsoft Stream', 'pdf' => 'PDF / Direct File', 'external' => 'External Website',
];
$structured_resource_type = $template_type === 'resource' ? (string) ($template_data['resource_type'] ?? $template_data['resource']['type'] ?? $legacy_resource_adapter['type'] ?? 'external_link') : 'external_link';
$structured_resource_provider = $template_type === 'resource' ? (string) ($template_data['resource_provider'] ?? $template_data['resource']['provider'] ?? $legacy_resource_adapter['provider'] ?? 'external') : 'external';
$structured_resource_url = $template_type === 'resource' ? (string) ($template_data['resource_url'] ?? $template_data['resource']['url'] ?? $template_data['url'] ?? $legacy_resource_adapter['url'] ?? '') : '';
$structured_resource_description = $template_type === 'resource' ? (string) ($template_data['description'] ?? '') : '';
$structured_resource_embed = $template_type === 'resource' ? (isset($template_data['embed_enabled']) ? !empty($template_data['embed_enabled']) : !empty($legacy_resource_adapter['embed'])) : false;

// Pre-populate Notes-appropriate defaults when creating a new structured Notes.
if ($is_structured_notes && !$is_edit) {
    $structured_resource_type = 'notes';
    $structured_resource_provider = 'google_drive';
    $structured_resource_embed = true;
}

if (!array_key_exists($structured_resource_type, $structured_resource_type_options)) { $structured_resource_type = 'external_link'; }
if (!array_key_exists($structured_resource_provider, $structured_resource_provider_options)) { $structured_resource_provider = 'external'; }
$structured_resource_type_options_html = form_item_select_options($structured_resource_type_options, $structured_resource_type);
$structured_resource_provider_options_html = form_item_select_options($structured_resource_provider_options, $structured_resource_provider);

$recording_lesson_number_field = '';
if (!$is_edit) {
    $recording_lesson_number_field = "
                <div class='col-12 col-lg-4 p-2'>
                  <div>Lesson Number <small class='text-muted'>(optional)</small></div>
                  <input type='number' min='1' class='form-control' name='page_order' value='" . form_item_json_value(['lesson_number' => $recording_lesson_number], 'lesson_number') . "' placeholder='Example: 1'>
                </div>";
}

$legacy_notice = '';
if ($is_edit && $template_type === 'custom_html') {
    $legacy_notice = "
      <div class='col-12 p-2'>
        <div class='alert alert-warning mb-0'>
          Advanced legacy content mode is shown because this existing lesson was created before the simplified template workflow. Editing here preserves the original HTML exactly.
        </div>
      </div>";
}

$resource_notice = '';
if ($is_edit && $template_type === 'resource') {
    if ($is_structured_notes) {
        $resource_notice = "<div class='col-12 p-2'><div class='alert alert-info mb-0'>This Notes lesson uses structured resource fields. Its original HTML is retained as a rollback backup and is not shown in the editor.</div></div>";
    } elseif ($legacy_resource_adapter) {
        $resource_notice = "<div class='col-12 p-2'><div class='alert alert-info mb-0'>This single-resource legacy lesson is now edited through the same structured resource fields as new lessons. Its original lesson body is retained until you save.</div></div>";
    } else {
        $resource_notice = "<div class='col-12 p-2'><div class='alert alert-info mb-0'>This lesson uses structured resource fields. Its original HTML is retained as a rollback backup and is not shown in the normal editor.</div></div>";
    }
}
$template_selector_html = $template_type === 'resource'
    ? ($is_structured_notes 
        ? "<div class='col-12 p-2'><div class='ds-text-secondary small'>Notes</div></div>" 
        : "<div class='col-12 p-2'><div class='ds-text-secondary small'>Structured Resource</div></div>")
    : $cards_html;

$existing_item_metadata_json = htmlspecialchars(json_encode($item_metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$academic_metadata_html = "
            <details class='col-12 mx-0 mt-2 p-3 ds-surface-muted ds-border rounded-3' data-academic-metadata>
              <summary class='fw-bold' style='cursor: pointer'>Academic Metadata <small class='ds-text-muted fw-normal'>(optional)</small></summary>
              <div class='row pt-3'>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Primary Topic</label>
                  <select class='form-control' name='metadata_primary_topic_id'>
                    {$root_topic_options_html}
                  </select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Subtopic <small class='ds-text-muted'>(optional)</small></label>
                  <select class='form-control' name='metadata_subtopic_id'>
                    {$subtopic_options_html}
                  </select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>New Topic <small class='ds-text-muted'>(create on save)</small></label>
                  <input type='text' class='form-control' maxlength='120' name='metadata_new_topic' placeholder='Example: Algebra'>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>New Subtopic <small class='ds-text-muted'>(under the primary topic)</small></label>
                  <input type='text' class='form-control' maxlength='120' name='metadata_new_subtopic' placeholder='Example: Algebraic Fractions'>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Rename Selected Primary Topic <small class='ds-text-muted'>(optional)</small></label>
                  <input type='text' class='form-control' maxlength='120' name='metadata_rename_primary_topic' placeholder='Only changes the selected reusable topic'>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Rename Selected Subtopic <small class='ds-text-muted'>(optional)</small></label>
                  <input type='text' class='form-control' maxlength='120' name='metadata_rename_subtopic' placeholder='Only changes the selected reusable subtopic'>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Primary Topic State</label>
                  <select class='form-control' name='metadata_primary_topic_state'>{$primary_topic_state_options_html}</select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Subtopic State</label>
                  <select class='form-control' name='metadata_subtopic_state'>{$subtopic_state_options_html}</select>
                </div>
                <div class='col-12 p-2'>
                  <label class='form-label'>Additional Topics <small class='ds-text-muted'>(optional)</small></label>
                  <select class='form-control' name='metadata_additional_topic_ids[]' multiple size='4'>
                    {$additional_topic_options_html}
                  </select>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Difficulty</label>
                  <select class='form-control' name='metadata_difficulty'>{$difficulty_options_html}</select>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Estimated Time (minutes)</label>
                  <input type='number' min='1' class='form-control' name='metadata_estimated_time' value='" . form_item_json_value(['value' => $academic_estimated_time], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Revision Priority</label>
                  <select class='form-control' name='metadata_revision_priority'>{$priority_options_html}</select>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Week</label>
                  <input type='text' maxlength='60' class='form-control' name='metadata_week' value='" . form_item_json_value(['value' => $academic_week], 'value') . "' placeholder='Example: Week 3'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Unit</label>
                  <input type='text' maxlength='60' class='form-control' name='metadata_unit' value='" . form_item_json_value(['value' => $academic_unit], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Term</label>
                  <input type='text' maxlength='60' class='form-control' name='metadata_term' value='" . form_item_json_value(['value' => $academic_term], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Exam Board</label>
                  <input type='text' maxlength='60' class='form-control' name='metadata_exam_board' value='" . form_item_json_value(['value' => $academic_exam_board], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Syllabus / Specification Code</label>
                  <input type='text' maxlength='80' class='form-control' name='metadata_syllabus_code' value='" . form_item_json_value(['value' => $academic_syllabus_code], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-label'>Paper</label>
                  <input type='text' maxlength='60' class='form-control' name='metadata_paper' value='" . form_item_json_value(['value' => $academic_paper], 'value') . "' placeholder='Paper 2, Paper 4, or custom'>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Calculator Mode</label>
                  <select class='form-control' name='metadata_calculator_mode'>{$calculator_options_html}</select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <label class='form-label'>Keywords</label>
                  <input type='text' class='form-control' name='metadata_keywords' value='" . form_item_json_value(['value' => $academic_keywords], 'value') . "' placeholder='Comma-separated keywords'>
                </div>
                <div class='col-12 p-2'>
                  <label class='form-label'>Skills Tested</label>
                  <input type='text' class='form-control' name='metadata_skills_tested' value='" . form_item_json_value(['value' => (string) ($academic_source['skills_tested'] ?? '')], 'value') . "' placeholder='Example: Factorising, graph interpretation'>
                </div>
                <div class='col-12 p-2'>
                  <label class='form-label'>Learning Objectives</label>
                  <textarea class='form-control' name='metadata_learning_objectives' rows='3' placeholder='What should students understand or be able to do?'>" . form_item_textarea_value($academic_objectives) . "</textarea>
                </div>
                <div class='col-12 p-2'>
                  <label class='form-label'>Teacher Notes <small class='ds-text-muted'>(admin only)</small></label>
                  <textarea class='form-control' name='metadata_teacher_notes' rows='2' placeholder='Private teacher notes; never rendered to students'>" . form_item_textarea_value($academic_teacher_notes) . "</textarea>
                </div>
              </div>
            </details>";

$html_response = "
<div class='modal fade show' id='response-html-modal' tabindex='-1' aria-labelledby='courseBuilderItemModalLabel' aria-hidden='true'>
  <div class='modal-dialog modal-xl'>
    <div class='modal-content'>
      <div class='modal-header'>
        <h5 class='modal-title' id='courseBuilderItemModalLabel'>{$modal_title}</h5>
        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
      </div>
      <div class='modal-body'>
        <form action='' method='POST' class='courseBuilderItemForm' id='{$form_id}' enctype='multipart/form-data'>
          <input type='hidden' name='template_type' value='{$template_type}'>
          <input type='hidden' name='item_type' value='{$safe_item_type}' data-original-item-type='{$safe_item_type}'>
          <input type='hidden' name='existing_assignment_id' value='{$existing_assignment_id}'>
          <input type='hidden' name='existing_item_metadata' value='{$existing_item_metadata_json}'>
          <fieldset class='form-fieldset api-mode'>
            <label class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Choose Lesson Type</label>
            <div class='col-12 p-2 row template-selector'>{$template_selector_html}</div>
            {$legacy_notice}
            {$resource_notice}
            <div class='col-12 p-3 row'>
              <div class='col-12 col-lg-6 p-2'>
                <div class='col-12'>Lesson Title</div>
                <div class='col-12 pt-3'>
                  <input type='text' name='item_title' required maxlength='190' class='form-control' value='{$safe_title}' placeholder='Example: Coordinate Geometry Homework'>
                </div>
              </div>
              {$section_field}
              {$order_field}
              {$status_field}
              {$duration_field}
            </div>

            <div class='{$pane_class['recording']}' data-template-pane='recording'>
              <div class='row'>
                <div class='col-12 col-lg-8 p-2'>
                  <label for='{$form_id}-recording-url'>Microsoft Recording Link</label>
                  <input id='{$form_id}-recording-url' type='url' class='form-control' name='recording_url' data-template-required='recording' value='" . form_item_json_value(['url' => $recording_url], 'url') . "' placeholder='https://...sharepoint.com/...'>
                  <small class='text-muted d-block mt-2'>Paste the SharePoint / Microsoft Stream sharing link students can open. Do not paste iframe HTML or an embed.aspx URL.</small>
                  {$recording_warning}
                </div>
                {$recording_lesson_number_field}
              </div>
            </div>

            <div class='{$pane_class['resource']}' data-template-pane='resource'>
              <div class='row'>
                <div class='col-12 col-lg-4 p-2'><div>Resource Type</div><select class='form-control' name='structured_resource_type' data-template-required='resource'>{$structured_resource_type_options_html}</select></div>
                <div class='col-12 col-lg-4 p-2'><div>Provider</div><select class='form-control' name='structured_resource_provider' data-template-required='resource'>{$structured_resource_provider_options_html}</select></div>
                <div class='col-12 col-lg-4 p-2 d-flex align-items-end'><label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='structured_embed_enabled' value='1'" . ($structured_resource_embed ? " checked" : "") . "><span class='form-check-label'>Use LMS preview when supported</span></label></div>
                <div class='col-12 p-2'><div>Resource URL <small class='text-muted'>(or official Microsoft Stream embed code)</small></div><input type='text' inputmode='url' class='form-control' name='structured_resource_url' data-template-required='resource' value='" . form_item_json_value(['url' => $structured_resource_url], 'url') . "' placeholder='https://...'></div>
                <div class='col-12 p-2'><div>Description <small class='text-muted'>(optional)</small></div><textarea class='form-control' name='structured_resource_description' rows='3' placeholder='Optional teacher guidance shown in the resource viewer'>" . form_item_textarea_value($structured_resource_description) . "</textarea></div>
              </div>
            </div>

            <div class='{$pane_class['notes']}' data-template-pane='notes'>
              <div class='row'>
                <div class='col-12 p-2'>
                  <div>Notes</div>
                  <textarea id='{$notes_editor_id}' class='form-control course-builder-editor' name='notes_content' rows='10' placeholder='Write lesson notes here'>" . form_item_textarea_value($notes_content) . "</textarea>
                </div>
              </div>
            </div>

            <div class='{$pane_class['classified_assignment']}' data-template-pane='classified_assignment'>
              <div class='row'>
                <div class='col-12 col-lg-8 p-2'>
                  <div>Homework PDF 1</div>
                  <input type='url' class='form-control' name='assignment_drive_url' data-template-required='classified_assignment' value='" . form_item_json_value(['url' => $assignment_url], 'url') . "' placeholder='https://drive.google.com/...'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Homework PDF 2 <small class='text-muted'>(optional)</small></div>
                  <input type='url' class='form-control' name='assignment_drive_url_2' value='" . form_item_json_value(['url' => $assignment_url_2], 'url') . "' placeholder='https://drive.google.com/...'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Assignment Deadline</div>
                  <input type='datetime-local' class='form-control' name='assignment_deadline' data-template-required='classified_assignment' value='" . htmlspecialchars($assignment_deadline, ENT_QUOTES, 'UTF-8') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <label class='form-check mt-4'><input class='form-check-input' type='checkbox' name='assignment_late_submission_enabled' value='1'" . ($assignment_late_submission_enabled ? " checked" : "") . "><span class='form-check-label'>Enable Legacy Late Submission</span></label>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Late Submission Until</div>
                  <input type='datetime-local' class='form-control' name='assignment_late_submission_until' value='" . htmlspecialchars($assignment_late_submission_until, ENT_QUOTES, 'UTF-8') . "'>
                </div>
                <div class='col-12 col-lg-8 p-2'>
                  <div>Model Answer URL <small class='text-muted'>(optional)</small></div>
                  <input type='url' class='form-control' name='model_answer_url' value='" . form_item_json_value(['url' => $model_answer_url], 'url') . "' placeholder='https://drive.google.com/...'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Model Answer release</div>
                  <select class='form-control' name='model_answer_release'>
                    <option value='hidden'" . ($model_answer_release === 'hidden' ? ' selected' : '') . ">Hidden</option>
                    <option value='immediate'" . ($model_answer_release === 'immediate' ? ' selected' : '') . ">Visible immediately</option>
                    <option value='after_due'" . ($model_answer_release === 'after_due' ? ' selected' : '') . ">Visible after due date</option>
                    <option value='after_submission'" . ($model_answer_release === 'after_submission' ? ' selected' : '') . ">Visible after student submission</option>
                  </select>
                </div>
                <div class='col-12 p-2'>
                  <fieldset class='model-answer-access-card'>
                    <legend class='h6 mb-1'>Model Answer access</legend>
                    <p class='text-muted small mb-2'>Control which enrolled students can open this Model Answer. Homework access and submissions are unchanged.</p>
                    <label class='form-check'><input class='form-check-input' type='radio' name='model_answer_access_mode' value='all'" . ($model_answer_access_mode === 'all' ? ' checked' : '') . "><span class='form-check-label'>All enrolled students</span></label>
                    <label class='form-check'><input class='form-check-input' type='radio' name='model_answer_access_mode' value='selected'" . ($model_answer_access_mode === 'selected' ? ' checked' : '') . "><span class='form-check-label'>Selected students</span></label>
                    <label class='form-check'><input class='form-check-input' type='radio' name='model_answer_access_mode' value='none'" . ($model_answer_access_mode === 'none' ? ' checked' : '') . "><span class='form-check-label'>No students</span></label>
                    <div class='model-answer-access-selected mt-2' data-model-answer-selected>
                      <label class='form-label small mb-1' for='model-answer-access-search'>Search enrolled students</label>
                      <input id='model-answer-access-search' type='search' class='form-control form-control-sm mb-2' placeholder='Name or email' data-model-answer-access-search>
                      <div class='small text-muted mb-1' data-model-answer-access-count>0 selected</div>
                      <div class='model-answer-access-students' data-model-answer-access-list>{$model_answer_access_students_html}</div>
                    </div>
                  </fieldset>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Maximum Score</div>
                  <input type='number' min='0.01' step='0.01' class='form-control' name='assignment_max_score' data-template-required='classified_assignment' value='" . form_item_json_value(['max_score' => $assignment_max_score], 'max_score') . "' placeholder='Example: 20'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Self-score Mode</div>
                  <select class='form-control' name='assignment_score_mode'>
                    {$score_mode_options_html}
                  </select>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Homework Category</div>
                  <select class='form-control' name='assignment_category'>
                    {$assignment_category_options_html}
                  </select>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Passing Score <small class='text-muted'>(optional)</small></div>
                  <input type='number' min='0' step='0.01' class='form-control' name='assignment_passing_score' value='" . form_item_json_value(['value' => $assignment_passing_score], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Weight / Importance <small class='text-muted'>(optional)</small></div>
                  <input type='number' min='0' step='0.01' class='form-control' name='assignment_weight' value='" . form_item_json_value(['value' => $assignment_weight], 'value') . "'>
                </div>
                <div class='col-12 col-lg-4 p-2'>
                  <div>Recommended Recording</div>
                  <select class='form-control' name='assignment_recommended_recording_item_id'>{$recommendation_recording_options_html}</select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <div>Recommended Notes</div>
                  <select class='form-control' name='assignment_recommended_notes_item_id'>{$recommendation_notes_options_html}</select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <div>Recommended Revision Element</div>
                  <select class='form-control' name='assignment_recommended_revision_item_id'>{$recommendation_options_html}</select>
                </div>
                <input type='hidden' name='assignment_homework_type' value='" . form_item_json_value(['homework_type' => $assignment_homework_type], 'homework_type') . "'>
                <div class='col-12 p-2'>
                  <div>Instructions / Description</div>
                  <textarea id='{$assignment_editor_id}' class='form-control course-builder-editor' name='assignment_instructions' rows='7' placeholder='Write assignment instructions here'>" . form_item_textarea_value($assignment_instructions) . "</textarea>
                </div>
              </div>
            </div>

            <div class='{$pane_class['timed_exam']}' data-template-pane='timed_exam'>
              <div class='alert alert-info'>Timing mode: <strong>Fixed Window</strong>. All enrolled students share the same opening and closing times.</div>
              <div class='row'>
                <div class='col-12 p-2'><label class='form-label'>Instructions</label><textarea class='form-control' name='timed_exam_instructions' rows='5' placeholder='Explain the exam clearly'>" . form_item_textarea_value($template_type === 'timed_exam' ? ($timed_exam_config['instructions'] ?? '') : '') . "</textarea></div>
                <div class='col-12 col-lg-6 p-2'><label class='form-label'>Scheduled start</label><input type='datetime-local' class='form-control' name='timed_exam_scheduled_start' value='" . htmlspecialchars($timed_exam_start, ENT_QUOTES, 'UTF-8') . "'></div>
                <div class='col-12 col-lg-3 p-2'><label class='form-label'>Duration (minutes)</label><input type='number' class='form-control' name='timed_exam_duration' min='1' max='1440' value='" . htmlspecialchars($timed_exam_duration, ENT_QUOTES, 'UTF-8') . "'></div>
                <div class='col-12 col-lg-3 p-2'><label class='form-label'>Grace period (minutes)</label><input type='number' class='form-control' name='timed_exam_grace' min='0' max='1440' value='" . htmlspecialchars($timed_exam_grace, ENT_QUOTES, 'UTF-8') . "'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Maximum answer uploads</label><input type='number' class='form-control' name='timed_exam_max_attempts' min='1' max='20' value='" . htmlspecialchars($timed_exam_max_attempts, ENT_QUOTES, 'UTF-8') . "'><small class='text-muted'>Successful uploads and replacements count toward this limit, even if removed.</small></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Allowed answer types</label><input type='text' class='form-control' name='timed_exam_allowed_types' value='" . htmlspecialchars($timed_exam_allowed_types, ENT_QUOTES, 'UTF-8') . "' placeholder='PDF, JPG, PNG'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Maximum file size (MB)</label><input type='number' class='form-control' name='timed_exam_max_size_mb' min='1' max='500' value='" . htmlspecialchars($timed_exam_max_size_mb, ENT_QUOTES, 'UTF-8') . "'></div>
                <div class='col-12 p-2'><label class='form-label' for='timed-exam-paper-url'>Exam Paper Link</label><input id='timed-exam-paper-url' type='url' class='form-control' name='timed_exam_paper_url' value=\"" . htmlspecialchars($timed_exam_paper_url, ENT_QUOTES, 'UTF-8') . "\" placeholder='https://drive.google.com/...'><small class='text-muted'>Paste one Google Drive file link (HTTPS only).</small><div class='alert alert-warning mt-2 mb-0'>The exam file must be shared as ‘Anyone with the link — Viewer’. Download must also be allowed in Google Drive.</div>" . ($timed_exam_has_legacy_upload ? "<small class='d-block text-muted mt-2'>This exam still has an older uploaded paper" . (!empty($timed_exam_config['paper_original_name']) ? " (" . htmlspecialchars((string) $timed_exam_config['paper_original_name'], ENT_QUOTES, 'UTF-8') . ")" : '') . ". It is preserved, but a Google Drive link is required before students can open the paper.</small>" : '') . "</div>
                <div class='col-12 p-2'><label class='form-label' for='timed-exam-paper-fallback'>Optional fallback instructions</label><textarea id='timed-exam-paper-fallback' class='form-control' name='timed_exam_paper_fallback' rows='2' placeholder='Tell students what to do if the provider asks them to sign in...'>" . htmlspecialchars($timed_exam_paper_fallback, ENT_QUOTES, 'UTF-8') . "</textarea></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-check'><input class='form-check-input' type='checkbox' name='timed_exam_view_allowed' value='1'" . ($timed_exam_view_allowed ? ' checked' : '') . "><span class='form-check-label'>Allow in-browser viewing</span></label></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-check'><input class='form-check-input' type='checkbox' name='timed_exam_download_allowed' value='1'" . ($timed_exam_download_allowed ? ' checked' : '') . "><span class='form-check-label'>Allow paper download</span></label></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-check'><input class='form-check-input' type='checkbox' name='timed_exam_late_allowed' value='1'" . ($timed_exam_late_allowed ? ' checked' : '') . "><span class='form-check-label'>Allow grace-period submissions</span></label></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Total marks <small class='text-muted'>(optional)</small></label><input type='number' min='0' step='0.01' class='form-control' name='timed_exam_max_marks' value='" . htmlspecialchars($timed_exam_max_marks, ENT_QUOTES, 'UTF-8') . "'></div>
                <div class='col-12 col-lg-4 p-2'><label class='form-label'>Release results at <small class='text-muted'>(optional)</small></label><input type='datetime-local' class='form-control' name='timed_exam_results_release' value='" . htmlspecialchars($timed_exam_results_release, ENT_QUOTES, 'UTF-8') . "'></div>
                <div class='col-12 p-2'><div class='border rounded p-3'><strong>Recovery Plan eligibility</strong><p class='small text-muted mb-2'>A Timed Exam is never reopened by Recovery Plan unless a separate recovery window is configured.</p><label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='timed_exam_recovery_allowed' value='1'" . ($timed_exam_recovery_allowed ? ' checked' : '') . "><span class='form-check-label'>Allow this exam as a Recovery Plan task</span></label><div class='row'><div class='col-12 col-lg-6'><label class='form-label'>Recovery window starts</label><input type='datetime-local' class='form-control' name='timed_exam_recovery_start' value='" . htmlspecialchars($timed_exam_recovery_start, ENT_QUOTES, 'UTF-8') . "'></div><div class='col-12 col-lg-6'><label class='form-label'>Recovery window ends</label><input type='datetime-local' class='form-control' name='timed_exam_recovery_end' value='" . htmlspecialchars($timed_exam_recovery_end, ENT_QUOTES, 'UTF-8') . "'></div></div></div></div>
              </div>
            </div>

            <div class='{$pane_class['assignment_model_answer']}' data-template-pane='assignment_model_answer'>
              <div class='row'>
                <div class='col-12 p-2'>
                  <div>Google Drive URL</div>
                  <input type='url' class='form-control' name='legacy_model_answer_url' data-template-required='assignment_model_answer' value='" . form_item_json_value(['url' => $model_answer_url], 'url') . "' placeholder='https://drive.google.com/...'>
                </div>
              </div>
            </div>

            <div class='{$pane_class['custom_lesson']}' data-template-pane='custom_lesson'>
              <div class='row'>
                <div class='col-12 col-lg-6 p-2'>
                  <div>Lesson Label</div>
                  <select class='form-control' name='custom_label_type' data-template-required='custom_lesson' data-custom-label-selector>
                    {$custom_label_options_html}
                  </select>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <div>Custom Label</div>
                  <input type='text' maxlength='80' class='{$custom_label_input_class}' name='custom_label_text' value='" . form_item_json_value(['custom_label' => $custom_label_text], 'custom_label') . "' data-custom-label-input placeholder='Example: Workshop'>
                </div>
                <div class='col-12 col-lg-6 p-2'>
                  <div>Lesson Icon</div>
                  <select class='form-control' name='custom_icon'>
                    {$custom_icon_options_html}
                  </select>
                </div>
                <div class='col-12 p-2'>
                  <div>Content</div>
                  <textarea id='{$custom_lesson_editor_id}' class='form-control course-builder-editor' name='custom_lesson_content' rows='10' data-template-required='custom_lesson' placeholder='Write lesson content here'>" . form_item_textarea_value($custom_content) . "</textarea>
                </div>
              </div>
            </div>

            <div class='{$pane_class['custom_html']}' data-template-pane='custom_html'>
              <div class='row'>
                <div class='col-12 p-2'>
                  <div>Advanced Legacy Content</div>
                  <textarea id='{$legacy_editor_id}' class='form-control course-builder-editor' name='custom_html_content' rows='10' placeholder='Existing legacy HTML'>" . form_item_textarea_value($legacy_content) . "</textarea>
                </div>
              </div>
            </div>
            {$section_metadata_overrides_html}
            {$academic_metadata_html}
          </fieldset>
          {$item_hidden}
          <input type='hidden' name='course_id' value='{$safe_course_id}' />
          <input type='hidden' name='_method' value='{$form_method}' />
          <div class='modal-footer p-2'>
            <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Cancel</button>
            " . ($timed_exam_admin_link !== '' ? "<a class='btn btn-outline-secondary' href='" . htmlspecialchars($timed_exam_admin_link, ENT_QUOTES, 'UTF-8') . "' target='_blank' rel='noopener'>View submissions</a>" : '') . "
            <button type='submit' class='btn btn-outline-primary submitBtn'>{$submit_label}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>";

form_item_response(true, 'Lesson form loaded successfully.', [
    'html' => $html_response,
    'mode' => $is_edit ? 'edit' : 'add',
    'template_type' => $template_type,
]);
?>
