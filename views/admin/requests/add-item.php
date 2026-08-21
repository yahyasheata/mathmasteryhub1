<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AcademicMetadata.php';
require_once 'inc/CourseResourceResolver.php';
require_once 'inc/TimedExam.php';
require_once 'inc/AdminAssessmentService.php';
require_once 'inc/AssignmentModelAnswerAccess.php';

header('Content-Type: application/json; charset=utf-8');

function item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function item_column_exists(mysqli $conn, $column)
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

function item_table_exists(mysqli $conn, $table)
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

function item_status_supports_hidden(mysqli $conn)
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

function item_bind_and_execute(mysqli $conn, $sql, $types, array $params)
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        item_response(false, 'Unable to prepare lesson save.', ['reason' => $conn->error]);
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    if (!$stmt->execute()) {
        item_response(false, 'Unexpected server error while saving the lesson.', ['reason' => $stmt->error ?: $conn->error]);
    }

    return $stmt;
}

function item_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function item_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function item_post_raw($key, $default = '')
{
    return isset($_POST[$key]) ? (string) $_POST[$key] : $default;
}

function item_homework_resource_slot($url, $release = null)
{
    $url = trim((string) $url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    $type = 'external_link';
    if ($host === 'drive.google.com' || str_ends_with($host, '.drive.google.com') || $host === 'docs.google.com' || str_ends_with($host, '.docs.google.com')) {
        $type = preg_match('~/(?:drive/)?folders/~', $path) ? 'google_drive_folder' : 'google_drive';
    } elseif ($host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtu.be') {
        $type = 'youtube';
    } elseif ($host === 'teams.microsoft.com' || str_ends_with($host, '.teams.microsoft.com')) {
        $type = 'teams';
    } elseif (str_ends_with($path, '.pdf')) {
        $type = 'pdf';
    } elseif ($host === 'sharepoint.com' || str_ends_with($host, '.sharepoint.com')) {
        $type = 'recording';
    }
    $slot = ['url' => $url, 'provider' => $type, 'resource_type' => $type, 'embed' => $type !== 'teams' && $type !== 'google_drive_folder'];
    if ($release !== null) {
        $slot['release'] = $release;
    }
    return $slot;
}

function item_is_checked($key)
{
    return isset($_POST[$key]) && in_array((string) $_POST[$key], ['1', 'on', 'yes', 'true'], true);
}

function item_existing_metadata()
{
    $decoded = json_decode(item_post_raw('existing_item_metadata', '{}'), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Stores only explicit lesson values in section_overrides. Existing metadata
 * remains untouched unless the compact override panel was submitted.
 */
function item_apply_section_metadata_overrides(mysqli $conn, $course_id, array $metadata)
{
    $modePresent = false;
    $overrideInput = [];
    foreach (mmh_hierarchical_metadata_fields() as $field) {
        $modeKey = 'section_override_mode_' . $field;
        if (!array_key_exists($modeKey, $_POST)) {
            continue;
        }
        $modePresent = true;
        if ((string) $_POST[$modeKey] === 'override') {
            $overrideInput['section_override_' . $field] = $_POST['section_override_' . $field] ?? '';
        }
    }
    if (!$modePresent) {
        return $metadata;
    }

    $overrides = mmh_hierarchical_metadata_from_input($conn, $course_id, $overrideInput, 'section_override_');
    if ($overrides) {
        $metadata['section_overrides'] = $overrides;
    } else {
        unset($metadata['section_overrides']);
    }
    return $metadata;
}

function item_optional_decimal($key)
{
    $value = item_post($key);
    if ($value === '') {
        return null;
    }
    if (!is_numeric($value) || (float) $value < 0) {
        item_response(false, 'Validation failed. Please enter a valid non-negative value for ' . str_replace('_', ' ', $key) . '.');
    }
    return round((float) $value, 2);
}

function item_optional_positive_integer($key)
{
    $value = item_post($key);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value) || (int) $value < 1) {
        item_response(false, 'Validation failed. Please enter a whole number of minutes.');
    }
    return (int) $value;
}

function item_optional_duration_minutes()
{
    $value = item_post('duration_minutes');
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        item_response(false, 'Validation failed. Estimated duration must be a whole number from 1 to 1440 minutes.');
    }

    $minutes = (int) $value;
    if ($minutes < 1 || $minutes > 1440) {
        item_response(false, 'Validation failed. Estimated duration must be between 1 and 1440 minutes.');
    }

    return $minutes;
}

function item_course_default_score_mode(mysqli $conn, $course_id)
{
    $stmt = $conn->prepare('SELECT default_homework_score_mode FROM courses WHERE course_id = ? LIMIT 1');
    if (!$stmt) {
        return 'disabled';
    }
    $stmt->bind_param('s', $course_id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return mmh_academic_score_mode($course['default_homework_score_mode'] ?? 'disabled');
}

function item_collect_academic_metadata(mysqli $conn, $course_id, $require_primary_topic = false)
{
    $existing = item_existing_metadata();
    $primary_topic_id = item_post('metadata_primary_topic_id');
    $subtopic_id = item_post('metadata_subtopic_id');
    $new_topic = item_post('metadata_new_topic');
    $new_subtopic = item_post('metadata_new_subtopic');

    if ($new_topic !== '') {
        $primary_topic_id = mmh_academic_create_topic($conn, $course_id, $new_topic, 0);
        if (!$primary_topic_id) {
            item_response(false, 'Unable to create the new topic. A topic with this name may already exist.');
        }
    }

    $primary_topic = $primary_topic_id !== '' ? mmh_academic_topic_by_id($conn, $course_id, $primary_topic_id) : null;
    if ($primary_topic && (int) $primary_topic['parent_topic_id'] !== 0) {
        item_response(false, 'Validation failed. Please choose a top-level topic as the Primary Topic.');
    }
    if ($require_primary_topic && !$primary_topic) {
        item_response(false, 'Validation failed. A Primary Topic is required for a Classified Assignment.');
    }

    $rename_primary = item_post('metadata_rename_primary_topic');
    if ($rename_primary !== '' && $primary_topic && !mmh_academic_rename_topic($conn, $course_id, $primary_topic['id'], $rename_primary)) {
        item_response(false, 'Unable to rename the selected primary topic. Please use a unique topic name.');
    }
    $primary_topic_state = item_post('metadata_primary_topic_state', 'keep');
    if (in_array($primary_topic_state, ['active', 'inactive'], true) && $primary_topic && !mmh_academic_set_topic_state($conn, $course_id, $primary_topic['id'], $primary_topic_state)) {
        item_response(false, 'Unable to update the selected primary topic state.');
    }

    if ($new_subtopic !== '') {
        if (!$primary_topic) {
            item_response(false, 'Validation failed. Select or create a Primary Topic before creating a Subtopic.');
        }
        $subtopic_id = mmh_academic_create_topic($conn, $course_id, $new_subtopic, $primary_topic['id']);
        if (!$subtopic_id) {
            item_response(false, 'Unable to create the new subtopic. A subtopic with this name may already exist.');
        }
    }

    $subtopic = $subtopic_id !== '' ? mmh_academic_topic_by_id($conn, $course_id, $subtopic_id) : null;
    if ($subtopic && (!$primary_topic || (int) $subtopic['parent_topic_id'] !== (int) $primary_topic['id'])) {
        item_response(false, 'Validation failed. The selected subtopic does not belong to the selected Primary Topic.');
    }
    $rename_subtopic = item_post('metadata_rename_subtopic');
    if ($rename_subtopic !== '' && $subtopic && !mmh_academic_rename_topic($conn, $course_id, $subtopic['id'], $rename_subtopic)) {
        item_response(false, 'Unable to rename the selected subtopic. Please use a unique subtopic name.');
    }
    $subtopic_state = item_post('metadata_subtopic_state', 'keep');
    if (in_array($subtopic_state, ['active', 'inactive'], true) && $subtopic && !mmh_academic_set_topic_state($conn, $course_id, $subtopic['id'], $subtopic_state)) {
        item_response(false, 'Unable to update the selected subtopic state.');
    }

    $additional_topic_ids = mmh_academic_validate_topic_ids($conn, $course_id, mmh_academic_parse_id_list($_POST['metadata_additional_topic_ids'] ?? []));
    $primary_id = $primary_topic ? (int) $primary_topic['id'] : null;
    $subtopic_id = $subtopic ? (int) $subtopic['id'] : null;
    $additional_topic_ids = array_values(array_diff($additional_topic_ids, array_filter([$primary_id, $subtopic_id])));

    $difficulty = item_post('metadata_difficulty');
    if (!in_array($difficulty, ['', 'easy', 'medium', 'hard', 'mixed'], true)) {
        $difficulty = '';
    }
    $calculator_mode = item_post('metadata_calculator_mode');
    if (!in_array($calculator_mode, ['', 'calculator', 'non_calculator', 'mixed', 'not_applicable'], true)) {
        $calculator_mode = '';
    }
    $priority = item_post('metadata_revision_priority');
    if (!in_array($priority, ['', 'low', 'normal', 'high'], true)) {
        $priority = '';
    }

    return [
        'primary_topic_id' => $primary_id,
        'subtopic_id' => $subtopic_id,
        'additional_topic_ids' => $additional_topic_ids,
        'skills_tested' => mmh_academic_clean_text(item_post('metadata_skills_tested'), 1000),
        'difficulty' => $difficulty,
        'estimated_time' => item_optional_positive_integer('metadata_estimated_time'),
        'learning_objectives' => mmh_academic_clean_text(item_post_raw('metadata_learning_objectives'), 4000),
        'keywords' => mmh_academic_clean_text(item_post('metadata_keywords'), 1000),
        'week' => mmh_academic_clean_text(item_post('metadata_week'), 60),
        'unit' => mmh_academic_clean_text(item_post('metadata_unit'), 60),
        'term' => mmh_academic_clean_text(item_post('metadata_term'), 60),
        'exam_board' => mmh_academic_clean_text(item_post('metadata_exam_board'), 60),
        'syllabus_code' => mmh_academic_clean_text(item_post('metadata_syllabus_code'), 80),
        'paper' => mmh_academic_clean_text(item_post('metadata_paper'), 60),
        'calculator_mode' => $calculator_mode,
        'importance' => $priority,
        'teacher_notes' => mmh_academic_clean_text(item_post_raw('metadata_teacher_notes'), 4000),
    ];
}

function item_normalize_status($value)
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

function item_base_url()
{
    global $baseUrl;
    if (!empty($baseUrl)) {
        return rtrim($baseUrl, '/');
    }

    $https_enabled = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $https_enabled ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_directory = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    return rtrim($scheme . '://' . $host . $script_directory, '/');
}

function item_asset_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }
    return item_base_url() . '/' . ltrim($path, '/');
}

function item_description_html($description)
{
    $description = trim((string) $description);
    if ($description === '') {
        return '';
    }
    return '<p>' . nl2br(item_html($description)) . '</p>';
}

function item_file_by_id(mysqli $conn, $file_id)
{
    if (!is_numeric($file_id) || (int) $file_id <= 0) {
        return null;
    }

    $id = (int) $file_id;
    $stmt = $conn->prepare('SELECT id, title, path FROM files WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $file ?: null;
}

function item_assignment_by_id(mysqli $conn, $course_id, $assignment_id)
{
    if (trim((string) $assignment_id) === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT assignment_id, assignment_title, due_date FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
    $stmt->bind_param('ss', $assignment_id, $course_id);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $assignment ?: null;
}

function item_exam_by_id(mysqli $conn, $course_id, $exam_id)
{
    if (trim((string) $exam_id) === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT exam_id, exam_title, due_date FROM exams WHERE exam_id = ? AND course_id = ? LIMIT 1');
    $stmt->bind_param('ss', $exam_id, $course_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exam ?: null;
}

function item_normalize_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    $time = strtotime($value);
    if (!$time) {
        item_response(false, 'Validation failed. Please enter a valid assignment deadline.');
    }

    return date('Y-m-d H:i:s', $time);
}

function item_due_date_label($value)
{
    $time = strtotime((string) $value);
    return $time ? date('j F Y', $time) : item_html($value);
}

function item_save_classified_assignment(mysqli $conn, $course_id, $title, $description, $due_date, $file_path, array $academic_metadata, array $score_mode)
{
    $assignment_id = item_post('existing_assignment_id');
    $edited_item_id = item_post('item_id');
    $section_id = item_post('section_id');
    $section_id = $section_id === '__general__' ? '' : $section_id;
    $homework_type = item_post('assignment_homework_type', 'classified_assignment');
    $max_score = item_optional_decimal('assignment_max_score');
    if ($max_score === null || $max_score <= 0) {
        item_response(false, 'Validation failed. Maximum Score is required for a Classified Assignment.');
    }
    $passing_score = item_optional_decimal('assignment_passing_score');
    if ($passing_score !== null && $passing_score > $max_score) {
        item_response(false, 'Validation failed. Passing Score cannot exceed Maximum Score.');
    }
    $weight = item_optional_decimal('assignment_weight');
    $category = item_post('assignment_category', 'classified');
    $allowed_categories = ['classified', 'worksheet', 'revision', 'past_paper_practice', 'quiz', 'challenge', 'other'];
    if (!in_array($category, $allowed_categories, true)) {
        $category = 'classified';
    }

    $topic_id = $academic_metadata['primary_topic_id'] ?? null;
    $subtopic_id = $academic_metadata['subtopic_id'] ?? null;
    $additional_topic_ids = json_encode($academic_metadata['additional_topic_ids'] ?? [], JSON_UNESCAPED_SLASHES);
    $difficulty = $academic_metadata['difficulty'] ?? null;
    $estimated_time = $academic_metadata['estimated_time'] ?? null;
    $skills_tested = $academic_metadata['skills_tested'] ?? null;
    $calculator_mode = $academic_metadata['calculator_mode'] ?? null;
    $exam_board = $academic_metadata['exam_board'] ?? null;
    $paper = $academic_metadata['paper'] ?? null;
    $teacher_notes = $academic_metadata['teacher_notes'] ?? null;
    $importance = $academic_metadata['importance'] ?? null;
    $learning_objectives = $academic_metadata['learning_objectives'] ?? null;
    $keywords = $academic_metadata['keywords'] ?? null;
    $week = $academic_metadata['week'] ?? null;
    $unit = $academic_metadata['unit'] ?? null;
    $term = $academic_metadata['term'] ?? null;
    $syllabus_code = $academic_metadata['syllabus_code'] ?? null;
    $recommended_recording = item_post('assignment_recommended_recording_item_id') ?: null;
    $recommended_notes = item_post('assignment_recommended_notes_item_id') ?: null;
    $recommended_revision = item_post('assignment_recommended_revision_item_id') ?: null;
    $allow_self_score = (int) ($score_mode['allow_self_score'] ?? 0);
    $require_teacher_verification = (int) ($score_mode['require_teacher_verification'] ?? 0);
    $late_submission_enabled = item_post('assignment_late_submission_enabled') === '1' ? 1 : 0;
    $late_submission_until = $late_submission_enabled ? item_normalize_datetime(item_post('assignment_late_submission_until')) : null;
    if ($late_submission_enabled && $late_submission_until === null) {
        item_response(false, 'Validation failed. Choose a late-submission deadline.');
    }

    $assignment_values = [
        'assignment_title' => $title, 'assignment_description' => $description, 'due_date' => $due_date, 'late_submission_enabled' => $late_submission_enabled, 'late_submission_until' => $late_submission_until, 'file_path' => $file_path,
        'course_id' => $course_id, 'section_id' => $section_id, 'homework_type' => $homework_type,
        'allow_self_score' => $allow_self_score, 'require_teacher_verification' => $require_teacher_verification, 'max_score' => $max_score, 'topic_id' => $topic_id, 'subtopic_id' => $subtopic_id,
        'additional_topic_ids' => $additional_topic_ids, 'difficulty' => $difficulty, 'estimated_time' => $estimated_time, 'passing_score' => $passing_score,
        'weight' => $weight, 'skills_tested' => $skills_tested, 'calculator_mode' => $calculator_mode, 'exam_board' => $exam_board, 'paper' => $paper,
        'teacher_notes' => $teacher_notes, 'importance' => $importance, 'category' => $category, 'learning_objectives' => $learning_objectives,
        'keywords' => $keywords, 'week' => $week, 'unit' => $unit, 'term' => $term, 'syllabus_code' => $syllabus_code,
        'recommended_recording_item_id' => $recommended_recording, 'recommended_notes_item_id' => $recommended_notes,
        'recommended_revision_item_id' => $recommended_revision,
    ];
    return mmh_admin_assignment_upsert_definition($conn, $assignment_values, $assignment_id, $edited_item_id);
}

function item_update_assignment_context(mysqli $conn, $course_id, $assignment_id, $item_id, $section_id)
{
    if (trim((string) $assignment_id) === '') {
        return;
    }

    mmh_admin_assignment_link_item($conn, (string) $course_id, (string) $assignment_id, (string) $item_id, (string) $section_id);
}

function item_recording_html($title, $url)
{
    return '<div class="ds-surface ds-shadow-sm" style="max-width: 700px; margin: 40px auto; border-radius: 20px; overflow: hidden; font-family: Arial, sans-serif"><!-- Header -->' . "\r\n"
        . '<div class="ds-surface ds-text-inverse" style="padding: 20px; font-size: 22px; font-weight: bold; text-align: center">' . item_html($title) . '</div>' . "\r\n"
        . '<!-- Body -->' . "\r\n"
        . '<div class="ds-text-primary" style="padding: 25px; text-align: center; font-size: 16px; line-height: 1.6">This recording has been prepared to help you review the lecture. <br><br>Click below to open it directly in a new tab.</div>' . "\r\n"
        . '<!-- Button -->' . "\r\n"
        . '<div style="text-align: center; padding: 25px"><a href="' . item_html($url) . '" target="_blank" rel="noopener" class="ds-surface ds-text-inverse ds-shadow-sm" style="padding: 16px 36px; border-radius: 50px; font-size: 18px; font-weight: bold; text-decoration: none; display: inline-block"> Open the Recording </a></div>' . "\r\n"
        . '<!-- Footer bar -->' . "\r\n"
        . '<div class="ds-surface" style="height: 8px"></div>' . "\r\n"
        . '</div>';
}

function item_classified_assignment_html($title, $url, $assignment_id, $due_date, $instructions, $allow_self_score = 0, $max_score = null, $score_mode = 'disabled')
{
    $body = trim((string) $instructions);
    if ($body === '') {
        $body = 'This Homework have been prepared to help you review the lecture efficiently. <br><br>You can view or download it directly by clicking the button below, then upload your related assignment before the deadline.';
    }

    return '<div class="ds-surface ds-shadow-sm" style="max-width: 700px; margin: 40px auto; border-radius: 20px; overflow: hidden; font-family: Arial, sans-serif"><!-- Header -->' . "\r\n"
        . '<div class="ds-surface ds-text-inverse" style="padding: 20px; font-size: 22px; font-weight: bold; text-align: center">' . item_html($title) . '</div>' . "\r\n"
        . '<!-- Body -->' . "\r\n"
        . '<div class="ds-text-primary" style="padding: 25px; text-align: center; font-size: 16px; line-height: 1.6">' . $body . '</div>' . "\r\n"
        . '<!-- Buttons side by side (Upload left, Download right) -->' . "\r\n"
        . '<div style="display: flex; justify-content: space-between; align-items: center; padding: 25px"><button class="show-assignment ds-surface ds-text-inverse ds-border ds-shadow-sm" style="padding: 16px 30px; border-radius: 50px; font-size: 18px; font-weight: bold; cursor: pointer; display: inline-block" data-assignment-id="' . item_html($assignment_id) . '" data-due-date="' . item_html($due_date) . '" data-allow-self-score="' . ((int) $allow_self_score === 1 ? '1' : '0') . '" data-max-score="' . item_html($max_score) . '" data-score-mode="' . item_html($score_mode) . '"> Upload Homework </button> <a href="' . item_html($url) . '" target="_blank" rel="noopener" class="ds-surface ds-text-inverse ds-shadow-sm" style="padding: 16px 30px; border-radius: 50px; font-size: 18px; font-weight: bold; text-decoration: none; display: inline-block"> Open / Download Homework </a></div>' . "\r\n"
        . '<!-- Due Date -->' . "\r\n"
        . '<div class="ds-text-secondary" style="text-align: center; padding: 15px; font-size: 16px; font-weight: bold">Due Date: <span class="ds-text-secondary">' . item_html(item_due_date_label($due_date)) . '</span></div>' . "\r\n"
        . '<!-- Footer bar -->' . "\r\n"
        . '<div class="ds-surface" style="height: 8px"></div>' . "\r\n"
        . '</div>';
}

function item_model_answer_html($title, $url)
{
    return '<div class="ds-surface ds-shadow-sm" style="max-width: 700px; margin: 40px auto; border-radius: 20px; overflow: hidden; font-family: Arial, sans-serif"><!-- Header -->' . "\r\n"
        . '<div class="ds-surface ds-text-inverse" style="padding: 20px; font-size: 22px; font-weight: bold; text-align: center">' . item_html($title) . '</div>' . "\r\n"
        . '<!-- Body -->' . "\r\n"
        . '<div class="ds-text-primary" style="padding: 25px; text-align: center; font-size: 16px; line-height: 1.6">This Model Answer have been prepared to help you check the homework efficiently. <br><br>You can view or download it directly by clicking the button below.</div>' . "\r\n"
        . '<!-- Button -->' . "\r\n"
        . '<div style="text-align: center; padding: 25px"><a href="' . item_html($url) . '" target="_blank" rel="noopener" class="ds-surface ds-text-inverse ds-shadow-sm" style="padding: 16px 36px; border-radius: 50px; font-size: 18px; font-weight: bold; text-decoration: none; display: inline-block"> Open / Download Homework Model Answer&nbsp;</a></div>' . "\r\n"
        . '<!-- Footer bar -->' . "\r\n"
        . '<div class="ds-surface" style="height: 8px"></div>' . "\r\n"
        . '</div>';
}

function item_custom_label_options()
{
    return [
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
}

function item_custom_icon_classes()
{
    return [
        'recording' => 'fas fa-play',
        'notes' => 'fas fa-file-alt',
        'pdf' => 'fas fa-file-pdf',
        'homework' => 'fas fa-edit',
        'model_answer' => 'fas fa-check-circle',
        'video' => 'fas fa-video',
        'revision' => 'fas fa-sync-alt',
        'practice' => 'fas fa-pencil-alt',
        'download' => 'fas fa-download',
        'link' => 'fas fa-link',
        'star' => 'fas fa-star',
        'book' => 'fas fa-book',
        'graduation_cap' => 'fas fa-graduation-cap',
        'clipboard' => 'fas fa-clipboard-list',
        'folder' => 'fas fa-folder',
        'none' => '',
    ];
}

function item_custom_lesson_html($title, $label, $icon_class, $content)
{
    $icon_html = '';
    if (trim((string) $icon_class) !== '') {
        $icon_html = '<div style="text-align: center; padding-top: 24px"><span class="' . item_html($icon_class) . ' ds-surface ds-text-inverse ds-shadow-sm" style="width: 58px; height: 58px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 24px" aria-hidden="true"></span></div>' . "\r\n";
    }

    return '<div class="ds-surface ds-shadow-sm" style="max-width: 700px; margin: 40px auto; border-radius: 20px; overflow: hidden; font-family: Arial, sans-serif"><!-- Header -->' . "\r\n"
        . '<div class="ds-surface ds-text-inverse" style="padding: 20px; text-align: center">' . "\r\n"
        . '<div style="font-size: 14px; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; opacity: .92">' . item_html($label) . '</div>' . "\r\n"
        . '<div style="font-size: 22px; font-weight: bold; margin-top: 6px">' . item_html($title) . '</div>' . "\r\n"
        . '</div>' . "\r\n"
        . $icon_html
        . '<!-- Body -->' . "\r\n"
        . '<div class="ds-text-primary" style="padding: 25px; font-size: 16px; line-height: 1.7">' . $content . '</div>' . "\r\n"
        . '<!-- Footer bar -->' . "\r\n"
        . '<div class="ds-surface" style="height: 8px"></div>' . "\r\n"
        . '</div>';
}

function item_drive_preview_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#drive\.google\.com/file/d/([^/]+)#', $url, $matches)) {
        return 'https://drive.google.com/file/d/' . rawurlencode($matches[1]) . '/preview';
    }

    $parts = parse_url($url);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['id'])) {
            return 'https://drive.google.com/file/d/' . rawurlencode($query['id']) . '/preview';
        }
    }

    return $url;
}

function item_type_for_template($template_type)
{
    if (in_array($template_type, ['recording', 'video'], true)) {
        return 'video';
    }
    if (in_array($template_type, ['classified_assignment', 'assignment', 'exam'], true)) {
        return 'quiz';
    }
    if ($template_type === 'timed_exam') {
        return 'timed_exam';
    }
    return 'file';
}

function item_json(array $data)
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function item_build_template(mysqli $conn, $template_type, $course_id, $item_title, array $existingResourceItem = [])
{
    global $item_homework_transaction;
    $template_data = ['template_type' => $template_type];
    $assignment_column_value = null;
    $due_date_value = null;
    $academic_metadata = item_existing_metadata();
    $html = '';

    switch ($template_type) {
        case 'recording':
            $url = item_post('recording_url');
            $recordingStatus = mmh_course_resource_microsoft_recording_status($url);
            if (($recordingStatus['state'] ?? '') === 'legacy_embed') {
                item_response(false, 'This is a legacy embed.aspx URL. Paste the normal SharePoint / Microsoft Stream sharing link students can open externally.');
            }
            if (($recordingStatus['state'] ?? '') !== 'external') {
                item_response(false, 'Validation failed. Paste a valid HTTPS SharePoint or Microsoft Teams recording sharing link.');
            }
            $url = (string) $recordingStatus['url'];
            $lesson_number = item_post('page_order');
            $template_data = array_merge($template_data, [
                'url' => $url,
                'lesson_number' => is_numeric($lesson_number) ? (int) $lesson_number : '',
            ]);
            $html = item_recording_html($item_title, $url);
            break;

        case 'classified_assignment':
            $url = item_post('assignment_drive_url');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid Google Drive URL.');
            }
            $url2 = item_post('assignment_drive_url_2');
            if ($url2 !== '' && !filter_var($url2, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid Homework PDF 2 URL.');
            }
            $due_date_value = item_normalize_datetime(item_post('assignment_deadline'));
            if ($due_date_value === null) {
                item_response(false, 'Validation failed. Please choose an assignment deadline.');
            }
            $instructions = item_post_raw('assignment_instructions');
            $model_answer_url = item_post('model_answer_url');
            if ($model_answer_url !== '' && !filter_var($model_answer_url, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid Model Answer URL.');
            }
            $model_answer_release = item_post('model_answer_release', 'hidden');
            if (!in_array($model_answer_release, ['hidden', 'immediate', 'after_due', 'after_submission'], true)) {
                item_response(false, 'Validation failed. Please choose a valid Model Answer release rule.');
            }
            $max_score = item_optional_decimal('assignment_max_score');
            if ($max_score === null || $max_score <= 0) {
                item_response(false, 'Validation failed. Maximum Score is required for a Classified Assignment.');
            }
            // Topics remain available as optional metadata; they must not block a Homework save when the course has no topic taxonomy yet.
            if (!$item_homework_transaction) {
                if (!$conn->begin_transaction()) {
                    item_response(false, 'Unable to begin the Homework save. Please try again.');
                }
                $item_homework_transaction = true;
            }
            $academic_metadata = item_collect_academic_metadata($conn, $course_id, false);
            $course_default_score_mode = item_course_default_score_mode($conn, $course_id);
            $requested_score_mode = item_post('assignment_score_mode', 'inherit');
            $score_mode = mmh_academic_score_mode($requested_score_mode === 'inherit' ? $course_default_score_mode : $requested_score_mode);
            $score_mode_flags = mmh_academic_score_mode_flags($score_mode);
            $allow_self_score = $score_mode_flags['allow_self_score'];
            $require_teacher_verification = $score_mode_flags['require_teacher_verification'];
            $homework_type = item_post('assignment_homework_type', 'classified_assignment');
            $assignment_id = item_save_classified_assignment($conn, $course_id, $item_title, $instructions, $due_date_value, $url, $academic_metadata, $score_mode_flags);
            $assignment_column_value = is_numeric($assignment_id) ? (int) $assignment_id : null;
            $template_data = array_merge($template_data, [
                'assignment_id' => $assignment_id,
                // Flat url remains for older readers; structured slots are the
                // authoritative format for the shared Homework surface.
                'url' => $url,
                'homework_resource' => item_homework_resource_slot($url),
                'homework_resource_2' => $url2 === '' ? null : item_homework_resource_slot($url2),
                'model_answer_resource' => $model_answer_url === '' ? null : item_homework_resource_slot($model_answer_url, $model_answer_release),
                'model_answer_release' => $model_answer_release,
                'visibility' => ['homework' => true, 'model_answer' => $model_answer_release !== 'hidden'],
                'due_date' => $due_date_value,
                'late_submission_enabled' => item_post('assignment_late_submission_enabled') === '1',
                'late_submission_until' => item_post('assignment_late_submission_enabled') === '1' ? item_normalize_datetime(item_post('assignment_late_submission_until')) : null,
                'instructions' => $instructions,
                'allow_self_score' => $allow_self_score,
                'require_teacher_verification' => $require_teacher_verification,
                'score_mode' => $score_mode,
                'max_score' => $max_score,
                'homework_type' => $homework_type,
            ]);
            // Homework is rendered from structured assignment/template data by the shared student Homework renderer.
            // Keep item_description empty for new records instead of creating a legacy inline card.
            $html = '';
            break;

        case 'assignment_model_answer':
            $url = item_post('legacy_model_answer_url', item_post('model_answer_url'));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid Google Drive URL.');
            }
            $template_data = array_merge($template_data, [
                'url' => $url,
            ]);
            $html = item_model_answer_html($item_title, $url);
            break;

        case 'resource':
            $resourceType = item_post('structured_resource_type', 'external_link');
            $resourceProvider = item_post('structured_resource_provider', 'external');
            $allowedResourceTypes = ['google_drive', 'google_drive_folder', 'youtube', 'recording', 'video', 'pdf', 'teams', 'notes', 'model_answer', 'worksheet', 'revision_sheet', 'booklet', 'download', 'external_link'];
            $allowedResourceProviders = ['google_drive', 'youtube', 'sharepoint', 'teams', 'microsoft_stream', 'pdf', 'external'];
            if (!in_array($resourceType, $allowedResourceTypes, true) || !in_array($resourceProvider, $allowedResourceProviders, true)) {
                item_response(false, 'Validation failed. Please choose a supported resource type and provider.');
            }
            $rawResourceUrl = item_post_raw('structured_resource_url');
            $resourceUrl = trim($rawResourceUrl);
            $streamEmbedUrl = mmh_course_resource_extract_microsoft_stream_embed($rawResourceUrl);
            $embedEnabled = item_is_checked('structured_embed_enabled');
            if (str_contains($rawResourceUrl, '<') || str_contains($rawResourceUrl, '>')) {
                if ($streamEmbedUrl === null) {
                    item_response(false, 'Validation failed. Paste only an official Microsoft Stream iframe embed code with a valid SharePoint UniqueId.');
                }
                $resourceUrl = $streamEmbedUrl;
                $resourceType = 'video';
                $resourceProvider = 'microsoft_stream';
                $embedEnabled = true;
            } elseif ($streamEmbedUrl !== null) {
                $resourceUrl = $streamEmbedUrl;
                $resourceType = 'video';
                $resourceProvider = 'microsoft_stream';
                $embedEnabled = true;
            }
            if ($resourceUrl === '' || mmh_course_resource_safe_url($resourceUrl) === null) {
                item_response(false, 'Validation failed. Please enter a valid resource URL.');
            }
            $existingData = json_decode((string) ($existingResourceItem['template_data'] ?? ''), true);
            $existingData = is_array($existingData) ? $existingData : [];
            $description = item_post('structured_resource_description');
            $template_data = array_merge($existingData, $template_data, [
                'resource_type' => $resourceType,
                'resource_provider' => $resourceProvider,
                'resource_url' => $resourceUrl,
                'url' => $resourceUrl,
                'embed_enabled' => $embedEnabled,
                'resource_behavior' => $embedEnabled ? 'embed' : 'redirect',
                'description' => $description,
                'resource' => ['type' => $resourceType, 'provider' => $resourceProvider, 'url' => $resourceUrl, 'embed' => $embedEnabled],
            ]);
            // The original HTML remains untouched. It is both a rollback backup
            // and the compatibility source for any future manual restoration.
            $html = (string) ($existingResourceItem['item_description'] ?? '');
            if (trim($html) === '') $html = (string) ($existingData['resource_migration']['legacy_html_backup'] ?? '');
            break;

        case 'custom_lesson':
            $label_options = item_custom_label_options();
            $icon_classes = item_custom_icon_classes();
            $label_type = item_post('custom_label_type', 'lesson');
            if (!array_key_exists($label_type, $label_options)) {
                $label_type = 'lesson';
            }
            $custom_label = item_post('custom_label_text');
            $label = $label_type === 'custom' ? $custom_label : $label_options[$label_type];
            if (trim($label) === '') {
                item_response(false, 'Validation failed. Please enter a custom lesson label.');
            }
            $icon = item_post('custom_icon', 'none');
            if (!array_key_exists($icon, $icon_classes)) {
                $icon = 'none';
            }
            $content = item_post_raw('custom_lesson_content');
            if (trim(strip_tags($content)) === '' && trim($content) === '') {
                item_response(false, 'Validation failed. Please add lesson content.');
            }
            $template_data = array_merge($template_data, [
                'label_type' => $label_type,
                'label' => $label,
                'custom_label' => $custom_label,
                'icon' => $icon,
                'icon_class' => $icon_classes[$icon],
                'content' => $content,
            ]);
            $html = item_custom_lesson_html($item_title, $label, $icon_classes[$icon], $content);
            break;

        case 'video':
            $file = item_file_by_id($conn, item_post('video_file_id'));
            if (!$file) {
                item_response(false, 'Validation failed. Please choose a Media Library video.');
            }
            $url = item_asset_url($file['path']);
            $description = item_post('video_description');
            $duration = item_post('video_duration');
            $preview_image = item_post('video_preview_image');
            $template_data = array_merge($template_data, [
                'file_id' => (int) $file['id'],
                'file_title' => $file['title'],
                'path' => $file['path'],
                'url' => $url,
                'description' => $description,
                'duration' => $duration,
                'preview_image' => $preview_image,
            ]);
            $html .= item_description_html($description);
            if ($preview_image !== '') {
                $html .= '<p><img src="' . item_html(item_asset_url($preview_image)) . '" alt="' . item_html($item_title) . '" style="max-width: 100%; height: auto"></p>';
            }
            $html .= '<button class="btn btn-sm show-video" data-src="' . item_html($url) . '"><span class="fas fa-play"></span> ' . item_html($item_title) . '</button>';
            if ($duration !== '') {
                $html .= '<div><small class="text-muted">Duration: ' . item_html($duration) . '</small></div>';
            }
            break;

        case 'pdf':
            $file = item_file_by_id($conn, item_post('pdf_file_id'));
            if (!$file) {
                item_response(false, 'Validation failed. Please choose an uploaded PDF.');
            }
            $url = item_asset_url($file['path']);
            $description = item_post('pdf_description');
            $download_allowed = item_is_checked('pdf_download_allowed');
            $preview_enabled = item_is_checked('pdf_preview_enabled');
            $template_data = array_merge($template_data, [
                'file_id' => (int) $file['id'],
                'file_title' => $file['title'],
                'path' => $file['path'],
                'url' => $url,
                'description' => $description,
                'download_allowed' => $download_allowed,
                'preview_enabled' => $preview_enabled,
            ]);
            $html .= item_description_html($description);
            if ($preview_enabled) {
                $html .= '<h6><a href="' . item_html($url) . '" target="_blank">' . item_html($item_title) . '</a></h6>';
            }
            if ($download_allowed) {
                $html .= '<p><a class="btn btn-sm btn-outline-primary" href="' . item_html($url) . '" target="_blank" download><span class="fas fa-download"></span> Download PDF</a></p>';
            }
            break;

        case 'notes':
            $description = item_post('notes_description');
            $content = item_post_raw('notes_content');
            $template_data = array_merge($template_data, [
                'description' => $description,
                'content' => $content,
            ]);
            $html = item_description_html($description) . $content;
            break;

        case 'assignment':
            $assignment = item_assignment_by_id($conn, $course_id, item_post('assignment_id'));
            if (!$assignment) {
                item_response(false, 'Validation failed. Please choose an existing assignment.');
            }
            $description = item_post('assignment_description');
            $assignment_id = (string) $assignment['assignment_id'];
            $due_date_value = $assignment['due_date'] ?: null;
            $assignment_column_value = is_numeric($assignment_id) ? (int) $assignment_id : null;
            $template_data = array_merge($template_data, [
                'assignment_id' => $assignment_id,
                'assignment_title' => $assignment['assignment_title'],
                'due_date' => $due_date_value,
                'description' => $description,
            ]);
            $html .= item_description_html($description);
            $html .= '<button class="btn btn-sm show-assignment" data-assignment-id="' . item_html($assignment_id) . '" data-due-date="' . item_html($due_date_value) . '"><span class="fas fa-lock"></span> ' . item_html($assignment['assignment_title']) . '</button>';
            break;

        case 'exam':
            $exam = item_exam_by_id($conn, $course_id, item_post('exam_id'));
            if (!$exam) {
                item_response(false, 'Validation failed. Please choose an existing exam.');
            }
            $description = item_post('exam_description');
            $exam_id = (string) $exam['exam_id'];
            $due_date_value = $exam['due_date'] ?: null;
            $template_data = array_merge($template_data, [
                'exam_id' => $exam_id,
                'exam_title' => $exam['exam_title'],
                'due_date' => $due_date_value,
                'description' => $description,
            ]);
            $html .= item_description_html($description);
            $html .= '<button class="btn btn-sm show-exam" data-exam-id="' . item_html($exam_id) . '" data-due-date="' . item_html($due_date_value) . '"><span class="fas fa-file-signature"></span> ' . item_html($exam['exam_title']) . '</button>';
            break;

        case 'timed_exam':
            $template_data = array_merge($template_data, [
                'timing_mode' => 'fixed_window',
                'instructions' => item_post_raw('timed_exam_instructions'),
            ]);
            $html = '';
            break;

        case 'external_link':
            $url = item_post('external_url');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid external URL.');
            }
            $description = item_post('external_description');
            $new_tab = item_is_checked('external_new_tab');
            $template_data = array_merge($template_data, [
                'url' => $url,
                'new_tab' => $new_tab,
                'description' => $description,
            ]);
            $target = $new_tab ? '_blank' : '_self';
            $html .= item_description_html($description);
            $html .= '<p><a class="btn btn-sm btn-outline-primary" href="' . item_html($url) . '" target="' . $target . '"><span class="fas fa-external-link-alt"></span> ' . item_html($item_title) . '</a></p>';
            break;

        case 'download':
            $file = item_file_by_id($conn, item_post('download_file_id'));
            if (!$file) {
                item_response(false, 'Validation failed. Please choose an uploaded file.');
            }
            $url = item_asset_url($file['path']);
            $description = item_post('download_description');
            $template_data = array_merge($template_data, [
                'file_id' => (int) $file['id'],
                'file_title' => $file['title'],
                'path' => $file['path'],
                'url' => $url,
                'description' => $description,
            ]);
            $html .= item_description_html($description);
            $html .= '<p><a class="btn btn-sm btn-outline-primary" href="' . item_html($url) . '" target="_blank" download><span class="fas fa-download"></span> ' . item_html($item_title) . '</a></p>';
            break;

        case 'embed':
            $embed_code = item_post_raw('embed_code');
            if (trim($embed_code) === '') {
                item_response(false, 'Validation failed. Please paste embed HTML.');
            }
            $description = item_post('embed_description');
            $template_data = array_merge($template_data, [
                'embed_code' => $embed_code,
                'description' => $description,
            ]);
            $html = item_description_html($description) . $embed_code;
            break;

        case 'google_drive':
            $url = item_post('google_drive_url');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid Google Drive URL.');
            }
            $preview_url = item_drive_preview_url($url);
            $description = item_post('google_drive_description');
            $template_data = array_merge($template_data, [
                'url' => $url,
                'preview_url' => $preview_url,
                'description' => $description,
            ]);
            $html .= item_description_html($description);
            $html .= '<iframe src="' . item_html($preview_url) . '" allowfullscreen class="ds-border" style="width: 100%; min-height: 480px"></iframe>';
            $html .= '<p><a class="btn btn-sm btn-outline-primary" href="' . item_html($url) . '" target="_blank"><span class="fas fa-external-link-alt"></span> Open in Google Drive</a></p>';
            break;

        case 'onedrive':
            $url = item_post('onedrive_url');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                item_response(false, 'Validation failed. Please enter a valid OneDrive URL.');
            }
            $description = item_post('onedrive_description');
            $template_data = array_merge($template_data, [
                'url' => $url,
                'description' => $description,
            ]);
            $html .= item_description_html($description);
            $html .= '<p><a class="btn btn-sm btn-outline-primary" href="' . item_html($url) . '" target="_blank"><span class="fas fa-external-link-alt"></span> ' . item_html($item_title) . '</a></p>';
            break;

        case 'custom_html':
        default:
            $content = item_post_raw('custom_html_content', item_post_raw('item_description'));
            $template_data = array_merge($template_data, [
                'content' => $content,
            ]);
            $html = $content;
            break;
    }

    $is_structured_note = $template_type === 'resource' && item_post('structured_resource_type') === 'notes';
    if (in_array($template_type, ['recording', 'notes', 'assignment_model_answer', 'custom_lesson'], true) || $is_structured_note) {
        $academic_metadata = item_collect_academic_metadata($conn, $course_id, false);
    }

    $itemMetadata = $template_type === 'classified_assignment' ? item_existing_metadata() : $academic_metadata;
    $itemMetadata = item_apply_section_metadata_overrides($conn, $course_id, $itemMetadata);

    return [
        'html' => $html,
        'data' => $template_data,
        // Classified Assignment metadata is canonical on assignments (linked
        // back through item_id), so it is not duplicated in course_items.
        'metadata' => $itemMetadata,
        'assignment_id' => $assignment_column_value,
        'due_date' => $due_date_value,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    item_response(false, 'Invalid request method.');
}

$conn = db();
$item_homework_transaction = false;
mmh_ensure_learning_schema($conn);
$method = $_POST['_method'] ?? 'POST';
$allowed_templates = ['recording', 'notes', 'classified_assignment', 'assignment_model_answer', 'custom_lesson', 'timed_exam', 'resource', 'video', 'pdf', 'assignment', 'exam', 'external_link', 'download', 'embed', 'google_drive', 'onedrive', 'custom_html'];

if (!isset($_POST['item_title'], $_POST['course_id']) || trim($_POST['item_title']) === '' || trim($_POST['course_id']) === '') {
    item_response(false, 'Validation failed. Please fill in all required lesson fields.');
}

$course_id = item_post('course_id');
$item_title = item_post('item_title');
$template_type = item_post('template_type', 'custom_html');
if (!in_array($template_type, $allowed_templates, true)) {
    $template_type = 'custom_html';
}

$allowed_item_types = ['video', 'file', 'quiz', 'timed_exam'];
$posted_item_type = isset($_POST['item_type']) && in_array($_POST['item_type'], $allowed_item_types, true) ? $_POST['item_type'] : '';
$item_type = (in_array($template_type, ['custom_html', 'resource'], true) && $posted_item_type !== '') ? $posted_item_type : item_type_for_template($template_type);
$existing_resource_item = [];
if ($template_type === 'resource' && $method === 'UPDATE' && item_post('item_id') !== '') {
    $resource_stmt = $conn->prepare('SELECT item_description, template_data FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
    if (!$resource_stmt) {
        item_response(false, 'Unable to load the existing resource.');
    }
    $resource_item_id = item_post('item_id');
    $resource_stmt->bind_param('ss', $resource_item_id, $course_id);
    $resource_stmt->execute();
    $existing_resource_item = $resource_stmt->get_result()->fetch_assoc() ?: [];
    $resource_stmt->close();
    if (!$existing_resource_item) {
        item_response(false, 'Lesson not found.');
    }
}
$duration_minutes = item_optional_duration_minutes();
$built = item_build_template($conn, $template_type, $course_id, $item_title, $existing_resource_item);
$item_description = $built['html'];
$template_data_json = item_json($built['data']);
$item_metadata_json = item_json($built['metadata'] ?? []);
$status = item_normalize_status($_POST['status'] ?? 'published');
$page_order = (isset($_POST['page_order']) && is_numeric($_POST['page_order'])) ? max(1, (int) $_POST['page_order']) : null;

if ($template_type === 'timed_exam' && !$item_homework_transaction) {
    if (!$conn->begin_transaction()) item_response(false, 'Unable to begin the Timed Exam save. Please try again.');
    $item_homework_transaction = true;
}

$has_template_type = item_column_exists($conn, 'template_type');
$has_template_data = item_column_exists($conn, 'template_data');
$has_metadata = item_column_exists($conn, 'metadata');
$has_assignment_id = item_column_exists($conn, 'assignment_id');
$has_due_date = item_column_exists($conn, 'due_date');
$has_status = item_column_exists($conn, 'status');
$has_duration_minutes = item_column_exists($conn, 'duration_minutes');
$supports_hidden_status = $has_status && item_status_supports_hidden($conn);
$has_sort_order = item_column_exists($conn, 'sort_order');
$has_section_id = item_column_exists($conn, 'section_id') && item_table_exists($conn, 'course_sections');

$section_id = null;
if ($has_section_id) {
    $posted_section_id = item_post('section_id');
    $explicit_general_section = $posted_section_id === '__general__';
    if ($posted_section_id === '__general__') {
        $posted_section_id = '';
    }

    if ($posted_section_id !== '') {
        $section_stmt = $conn->prepare('SELECT section_id FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
        if (!$section_stmt) {
            item_response(false, 'Unable to validate selected section.', ['reason' => $conn->error]);
        }
        $section_stmt->bind_param('ss', $posted_section_id, $course_id);
        $section_stmt->execute();
        $section_exists = $section_stmt->get_result()->num_rows > 0;
        $section_stmt->close();

        if (!$section_exists) {
            item_response(false, 'Validation failed. Please choose a valid section.');
        }
        $section_id = $posted_section_id;
    }

    // Homework must be deliberately placed in either a named section or the
    // explicit General option. This prevents new assignments being silently
    // created without the section context used by reporting.
    if ($template_type === 'classified_assignment' && $method !== 'UPDATE' && $posted_section_id === '' && !$explicit_general_section) {
        item_response(false, 'Choose a named section or the explicit General option for this homework.');
    }
}

if ($status === 'hidden' && !$supports_hidden_status) {
    item_response(false, 'Hidden lessons require the status column to support a hidden value. No lesson was saved.');
}

try {
    if ($method === 'UPDATE') {
        if (!isset($_POST['item_id']) || trim($_POST['item_id']) === '') {
            item_response(false, 'Validation failed. Lesson ID is missing.');
        }

        $item_id = item_post('item_id');
        $check_stmt = $conn->prepare('SELECT id FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
        $check_stmt->bind_param('ss', $item_id, $course_id);
        $check_stmt->execute();
        $existing_item = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if (!$existing_item) {
            item_response(false, 'Lesson not found.');
        }

        $sets = ['item_title = ?', 'item_description = ?', 'item_type = ?'];
        $types = 'sss';
        $params = [$item_title, $item_description, $item_type];

        if ($page_order !== null) {
            $sets[] = 'page_order = ?';
            $types .= 'i';
            $params[] = $page_order;
            if ($has_sort_order) {
                $sets[] = 'sort_order = ?';
                $types .= 'i';
                $params[] = $page_order;
            }
        }

        if ($has_status) {
            $sets[] = 'status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if ($has_duration_minutes) {
            $sets[] = 'duration_minutes = ?';
            $types .= 'i';
            $params[] = $duration_minutes;
        }
        if ($has_section_id) {
            $sets[] = 'section_id = ?';
            $types .= 's';
            $params[] = $section_id;
        }
        if ($has_template_type) {
            $sets[] = 'template_type = ?';
            $types .= 's';
            $params[] = $template_type;
        }
        if ($has_template_data) {
            $sets[] = 'template_data = ?';
            $types .= 's';
            $params[] = $template_data_json;
        }
        if ($has_metadata) {
            $sets[] = 'metadata = ?';
            $types .= 's';
            $params[] = $item_metadata_json;
        }
        if ($has_assignment_id) {
            $sets[] = 'assignment_id = ?';
            $types .= 'i';
            $params[] = $built['assignment_id'];
        }
        if ($has_due_date) {
            $sets[] = 'due_date = ?';
            $types .= 's';
            $params[] = $built['due_date'];
        }

        $types .= 'ss';
        $params[] = $item_id;
        $params[] = $course_id;

        $sql = 'UPDATE course_items SET ' . implode(', ', $sets) . ' WHERE item_id = ? AND course_id = ? LIMIT 1';
        item_bind_and_execute($conn, $sql, $types, $params);
        if ($template_type === 'timed_exam') {
            mmh_timed_exam_save_config($conn, $course_id, $item_id, [
                'title' => $item_title,
                'instructions' => item_post_raw('timed_exam_instructions'),
                'status' => $status,
                'scheduled_start_at' => item_post('timed_exam_scheduled_start'),
                'duration_minutes' => item_post('timed_exam_duration', '60'),
                'grace_minutes' => item_post('timed_exam_grace', '0'),
                'max_attempts' => item_post('timed_exam_max_attempts', '1'),
                'allowed_answer_types' => item_post('timed_exam_allowed_types', 'pdf,jpg,jpeg,png'),
                'max_file_size_bytes' => max(1, (int) item_post('timed_exam_max_size_mb', '10')) * 1048576,
                'paper_external_url' => item_post('timed_exam_paper_url'),
                'paper_fallback_instructions' => item_post_raw('timed_exam_paper_fallback'),
                'paper_view_allowed' => item_is_checked('timed_exam_view_allowed'),
                'paper_download_allowed' => item_is_checked('timed_exam_download_allowed'),
                'late_submission_allowed' => item_is_checked('timed_exam_late_allowed'),
                'max_marks' => item_post('timed_exam_max_marks'),
                'results_release_at' => item_post('timed_exam_results_release'),
                'recovery_allowed' => item_is_checked('timed_exam_recovery_allowed'),
                'recovery_window_start_at' => item_post('timed_exam_recovery_start'),
                'recovery_window_end_at' => item_post('timed_exam_recovery_end'),
            ], null, null);
        }
        if ($template_type === 'classified_assignment') {
            item_update_assignment_context($conn, $course_id, (string) ($built['data']['assignment_id'] ?? ''), $item_id, $section_id);
            mmh_assignment_model_answer_access_save(
                $conn,
                (string) ($built['data']['assignment_id'] ?? ''),
                (string) $course_id,
                $_POST['model_answer_access_mode'] ?? 'all',
                is_array($_POST['model_answer_access_student_ids'] ?? null) ? $_POST['model_answer_access_student_ids'] : []
            );
        }
        if ($template_type === 'classified_assignment') {
            if (!$conn->commit()) { throw new RuntimeException('Unable to commit the Homework update.'); }
            $item_homework_transaction = false;
        }
        if ($template_type === 'timed_exam') {
            if (!$conn->commit()) { throw new RuntimeException('Unable to commit the Timed Exam update.'); }
            $item_homework_transaction = false;
        }

        item_response(true, 'Lesson updated successfully.', [
            'course_id' => $course_id,
            'item_id' => $item_id,
            'template_type' => $template_type,
        ]);
    }

    do {
        $item_id = (string) random_int(99, 999999);
        $check_stmt = $conn->prepare('SELECT id FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
        $check_stmt->bind_param('ss', $item_id, $course_id);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
    } while ($exists);

    $order_stmt = $conn->prepare('SELECT COALESCE(MAX(page_order), 0) + 1 AS next_order FROM course_items WHERE course_id = ?');
    $order_stmt->bind_param('s', $course_id);
    $order_stmt->execute();
    $order_row = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();
    $next_order = $page_order ?? (int) ($order_row['next_order'] ?? 1);

    $columns = ['item_id', 'item_title', 'item_description', 'item_type', 'course_id', 'page_order'];
    $placeholders = ['?', '?', '?', '?', '?', '?'];
    $types = 'sssssi';
    $params = [$item_id, $item_title, $item_description, $item_type, $course_id, $next_order];

    if ($has_sort_order) {
        $columns[] = 'sort_order';
        $placeholders[] = '?';
        $types .= 'i';
        $params[] = $next_order;
    }
    if ($has_status) {
        $columns[] = 'status';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $status;
    }
    if ($has_duration_minutes) {
        $columns[] = 'duration_minutes';
        $placeholders[] = '?';
        $types .= 'i';
        $params[] = $duration_minutes;
    }
    if ($has_section_id) {
        $columns[] = 'section_id';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $section_id;
    }
    if ($has_template_type) {
        $columns[] = 'template_type';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $template_type;
    }
    if ($has_template_data) {
        $columns[] = 'template_data';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $template_data_json;
    }
    if ($has_metadata) {
        $columns[] = 'metadata';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $item_metadata_json;
    }
    if ($has_assignment_id) {
        $columns[] = 'assignment_id';
        $placeholders[] = '?';
        $types .= 'i';
        $params[] = $built['assignment_id'];
    }
    if ($has_due_date) {
        $columns[] = 'due_date';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $built['due_date'];
    }

    $sql = 'INSERT INTO course_items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    item_bind_and_execute($conn, $sql, $types, $params);
    if ($template_type === 'timed_exam') {
        mmh_timed_exam_save_config($conn, $course_id, $item_id, [
            'title' => $item_title,
            'instructions' => item_post_raw('timed_exam_instructions'),
            'status' => $status,
            'scheduled_start_at' => item_post('timed_exam_scheduled_start'),
            'duration_minutes' => item_post('timed_exam_duration', '60'),
            'grace_minutes' => item_post('timed_exam_grace', '0'),
            'max_attempts' => item_post('timed_exam_max_attempts', '1'),
            'allowed_answer_types' => item_post('timed_exam_allowed_types', 'pdf,jpg,jpeg,png'),
            'max_file_size_bytes' => max(1, (int) item_post('timed_exam_max_size_mb', '10')) * 1048576,
            'paper_external_url' => item_post('timed_exam_paper_url'),
            'paper_fallback_instructions' => item_post_raw('timed_exam_paper_fallback'),
            'paper_view_allowed' => item_is_checked('timed_exam_view_allowed'),
            'paper_download_allowed' => item_is_checked('timed_exam_download_allowed'),
            'late_submission_allowed' => item_is_checked('timed_exam_late_allowed'),
            'max_marks' => item_post('timed_exam_max_marks'),
            'results_release_at' => item_post('timed_exam_results_release'),
            'recovery_allowed' => item_is_checked('timed_exam_recovery_allowed'),
            'recovery_window_start_at' => item_post('timed_exam_recovery_start'),
            'recovery_window_end_at' => item_post('timed_exam_recovery_end'),
        ], null, null);
    }
    if ($template_type === 'classified_assignment') {
        item_update_assignment_context($conn, $course_id, (string) ($built['data']['assignment_id'] ?? ''), $item_id, $section_id);
        mmh_assignment_model_answer_access_save(
            $conn,
            (string) ($built['data']['assignment_id'] ?? ''),
            (string) $course_id,
            $_POST['model_answer_access_mode'] ?? 'all',
            is_array($_POST['model_answer_access_student_ids'] ?? null) ? $_POST['model_answer_access_student_ids'] : []
        );
    }
    if ($template_type === 'classified_assignment' || $template_type === 'timed_exam') {
        if (!$conn->commit()) { throw new RuntimeException('Unable to commit the Homework save.'); }
        $item_homework_transaction = false;
    }

    item_response(true, 'Lesson saved successfully.', [
        'item_id' => $item_id,
        'course_id' => $course_id,
        'template_type' => $template_type,
    ]);
} catch (Throwable $e) {
    if ($item_homework_transaction || $template_type === 'classified_assignment' || $template_type === 'timed_exam') { $conn->rollback(); $item_homework_transaction = false; }
    item_response(false, 'Unexpected server error while saving the lesson.', ['reason' => $e->getMessage()]);
}
?>
