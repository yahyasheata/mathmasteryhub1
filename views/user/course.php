<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/CourseDuration.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/StudentCourseProgress.php';
require_once 'inc/AssignmentProgress.php';
require_once 'inc/CourseResourceResolver.php';
require_once 'inc/CourseHomeworkRenderer.php';
$pageName = "course";
$username = $_SESSION['username'] ?? '';
$conn = db();
mmh_ensure_learning_schema($conn);
$student_course_csrf_token = student_course_csrf_token();
$requested_course_id = isset($courseId) ? trim((string) $courseId) : '';
$course_access_allowed = false;
$course_access_message = 'This course is unavailable.';
$course_access_course = null;
$user_id = null;
$course_id = '';
$lesson_progress_available = false;
$lesson_progress_map = [];
$course_progress_summary = [
    'available' => false,
    'eligible_count' => 0,
    'completed_count' => 0,
    'incomplete_count' => 0,
    'percentage' => null,
    'remaining_minutes' => 0,
    'known_remaining_count' => 0,
    'unknown_remaining_count' => 0,
];
$lesson_query_value = isset($_GET['lesson']) && is_string($_GET['lesson']) ? $_GET['lesson'] : '';
$requested_lesson_id = student_course_access_identifier($lesson_query_value, 40);
$course_return_to_lesson = isset($_GET['return']) && (string) $_GET['return'] === '1';
$selected_lesson = null;
$selected_lesson_id = '';
$selected_lesson_needs_url_replace = false;
$previous_lesson = null;
$next_lesson = null;
$continue_lesson = null;
$course_completed = false;
$course_page_url = '#course-content';

$assignment_progress_map = [];
if ($username !== '' && $requested_course_id !== '') {
    $user_id = student_course_access_student_id($conn, $username);
    $course_access_course = student_course_access_course($conn, $requested_course_id);
    if ($user_id !== null && $course_access_course) {
        $course_id = (string) $course_access_course['course_id'];
        if (student_course_access_enrolled($conn, $user_id, $course_id)) {
            $course_access_allowed = true;
            $assignment_progress_map = mmh_assignment_progress_load_course($conn, $user_id, $course_id);
        } else {
            $course_access_message = 'You are not enrolled in this course.';
        }
    }
}

function student_course_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function student_course_slug($value)
{
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $value));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'general';
}

function student_course_template_data($json)
{
    if (empty($json)) {
        return [];
    }

    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function student_course_status_badge($status)
{
    $status = strtolower(trim((string) $status));
    if ($status === '' || $status === 'published') {
        return '';
    }

    $label = $status === 'draft' ? 'Draft' : ucfirst($status);
    return "<span class='course-lesson-badge visibility'>" . student_course_html($label) . "</span>";
}

function student_course_section_type_label($section_type, $custom_type = '')
{
    $section_type = strtolower(trim((string) $section_type));
    $labels = [
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
        'custom' => trim((string) $custom_type) !== '' ? trim((string) $custom_type) : 'Custom',
        'general' => 'General',
    ];
    return $labels[$section_type] ?? 'Lecture';
}

function student_course_section_icon_class($section_type, $icon = '')
{
    $icons = [
        'play' => 'fas fa-play',
        'calendar' => 'fas fa-calendar-alt',
        'book' => 'fas fa-book',
        'layers' => 'fas fa-layer-group',
        'rotate' => 'fas fa-sync-alt',
        'clipboard' => 'fas fa-clipboard-list',
        'folder' => 'fas fa-folder',
        'video' => 'fas fa-video',
        'users' => 'fas fa-users',
        'gift' => 'fas fa-gift',
        'star' => 'fas fa-star',
    ];
    $icon = strtolower(trim((string) $icon));
    if ($icon !== '' && isset($icons[$icon])) {
        return $icons[$icon];
    }

    $section_type = strtolower(trim((string) $section_type));
    $type_icons = [
        'lecture' => 'play',
        'week' => 'calendar',
        'unit' => 'book',
        'chapter' => 'book',
        'module' => 'layers',
        'revision' => 'rotate',
        'practice' => 'clipboard',
        'resources' => 'folder',
        'live_session' => 'video',
        'office_hours' => 'users',
        'bonus' => 'gift',
        'custom' => 'star',
        'general' => 'folder',
    ];
    return $icons[$type_icons[$section_type] ?? 'play'];
}

function student_course_section_type_class($section_type)
{
    $section_type = strtolower(trim((string) $section_type));
    $section_type = $section_type !== '' ? $section_type : 'lecture';
    return preg_replace('/[^a-z0-9_-]+/', '-', $section_type);
}

function student_course_duration_summary_label($total_minutes, $known_count, $lesson_count, $scope = 'Section')
{
    $formatted = mmh_format_duration_minutes($total_minutes);
    if ($formatted === '' || $known_count < 1) {
        return '';
    }

    $prefix = $known_count < $lesson_count ? 'Known duration: ' : $scope . ' duration: ';
    return $prefix . $formatted;
}


function student_course_section_can_show_complete_button($rule)
{
    return in_array($rule, ['manual_completion', 'watching_recordings', 'viewing_notes', 'all_lessons_completed'], true);
}

function student_course_render_section($section, $index)
{
    $is_locked = !empty($section['locked']);
    $is_completed = !empty($section['completed']);
    $section_key = (string) $section['key'];
    $has_selected_lesson = !empty($section['selection_resolved']);
    $is_open = !$is_locked && (!empty($section['selected']) || (!$has_selected_lesson && $index === 0));
    $section_anchor = student_course_slug($section_key);
    $body_id = 'student-section-body-' . $section_anchor;
    $safe_key = student_course_html($section_key);
    $safe_title = student_course_html($section['title']);
    $safe_description = student_course_html($section['description'] ?? '');
    $safe_reason = student_course_html($section['lock_reason'] ?? '');
    $safe_unlock_badge = student_course_html($section['unlock_badge'] ?? 'Available');
    $section_type = (string) ($section['section_type'] ?? 'lecture');
    $section_type_label = student_course_html(student_course_section_type_label($section_type, $section['custom_type'] ?? ''));
    $section_type_class = student_course_html(student_course_section_type_class($section_type));
    $section_icon_class = student_course_html(student_course_section_icon_class($section_type, $section['icon'] ?? ''));
    $lesson_count = (int) $section['count'];
    $lesson_label = $lesson_count === 1 ? '1 Lesson' : $lesson_count . ' Lessons';
    $duration_label = student_course_duration_summary_label(
        $section['duration_minutes'] ?? null,
        (int) ($section['known_duration_count'] ?? 0),
        $lesson_count
    );
    $duration_html = $duration_label !== ''
        ? "<span class='student-course-section-count'><span class='fas fa-clock ds-icon ds-icon-xs' aria-hidden='true'></span> " . student_course_html($duration_label) . "</span>"
        : '';
    $open_class = $is_open ? ' is-open' : '';
    $locked_class = $is_locked ? ' is-locked' : '';
    $completed_class = $is_completed ? ' is-completed' : '';
    $body_style = $is_open ? '' : " style='display:none;'";
    $aria_expanded = $is_open ? 'true' : 'false';
    $aria_disabled = $is_locked ? 'true' : 'false';
    $description_html = $safe_description !== '' ? "<p class='student-course-section-description'>{$safe_description}</p>" : '';
    $lock_badge = $is_locked ? "<span class='student-course-lock-badge'><span class='fas fa-lock ds-icon ds-icon-xs' aria-hidden='true'></span> {$safe_unlock_badge}</span>" : '';
    $complete_badge = $is_completed ? "<span class='student-course-complete-badge'><span class='fas fa-check-circle ds-icon ds-icon-xs' aria-hidden='true'></span> Complete</span>" : '';
    $requirements = is_array($section['assignment_requirements'] ?? null) ? $section['assignment_requirements'] : [];
    $requirement_html = '';
    if (!empty($requirements['has_requirements'])) {
        $requirement_summary = (int) ($requirements['completed_count'] ?? 0) . ' of ' . (int) ($requirements['required_count'] ?? 0) . ' required tasks complete';
        $requirement_reason = student_course_html($requirements['blocking_reason'] ?? '');
        $requirement_html = "<span class='student-course-section-requirement'><span class='fas fa-clipboard-check ds-icon ds-icon-xs' aria-hidden='true'></span> " . student_course_html($requirement_summary) . ($requirement_reason !== '' ? "<small>{$requirement_reason}</small>" : '') . "</span>";
    }
    $reason_html = $is_locked && $safe_reason !== '' ? "<span class='student-course-lock-reason'>{$safe_reason}</span>" : '';
    $completion_rule = student_course_html($section['completion_rule'] ?? 'manual_completion');
    $complete_button = '';
    if (!$is_locked && !$is_completed && !empty($section['show_complete_button'])) {
        $complete_button = "
          <div class='student-course-section-complete-row'>
            <button type='button' class='course-btn course-btn-secondary student-course-complete-btn' data-section-complete='{$safe_key}' data-course-id='" . student_course_html($section['course_id'] ?? '') . "'>
              <span class='fas fa-check ds-icon ds-icon-sm' aria-hidden='true'></span>
              <span>Mark Section Complete</span>
            </button>
          </div>";
    }
    $locked_inner = "
      <div class='student-course-locked-panel'>
        <span class='fas fa-lock ds-icon ds-icon-lg' aria-hidden='true'></span>
        <strong>This section is locked</strong>
        <span>{$safe_reason}</span>
      </div>";
    $inner_content = $is_locked ? $locked_inner : $section['lessons'] . $complete_button;

    return "
      <section class='student-course-section section-type-{$section_type_class}{$open_class}{$locked_class}{$completed_class}' id='course-section-{$section_anchor}' data-section-key='{$safe_key}' data-section-locked='" . ($is_locked ? '1' : '0') . "' data-completion-rule='{$completion_rule}' data-completed='" . ($is_completed ? '1' : '0') . "'>
        <button class='student-course-section-toggle' type='button' aria-expanded='{$aria_expanded}' aria-disabled='{$aria_disabled}' aria-controls='{$body_id}'>
          <span class='student-course-section-main'>
            <span class='student-course-section-icon'><span class='" . ($is_locked ?'fas fa-lock' : $section_icon_class) . " ds-icon ds-icon-md' aria-hidden='true'></span></span>
            <span class='student-course-section-copy'>
              <span class='student-course-section-title'>{$safe_title}</span>
              <span class='student-course-section-type'>{$section_type_label}</span>
              {$description_html}
              {$requirement_html}
              {$reason_html}
            </span>
          </span>
          <span class='student-course-section-side'>
            {$complete_badge}
            {$lock_badge}
            <span class='student-course-section-count'>{$lesson_label}</span>
            {$duration_html}
            <i class='student-course-section-chevron student-fa fas fa-chevron-down' aria-hidden='true'></i>
          </span>
        </button>
        <div class='student-course-section-body' id='{$body_id}'{$body_style}>
          <div class='student-course-section-inner'>
            {$inner_content}
          </div>
        </div>
      </section>";
}

// Resolve course, enrollment, items, and visible sections through prepared
// statements. Raw item_description remains untouched below for legacy lesson
// rendering compatibility.
$coures_result = null;
if ($course_access_allowed) {
  $lesson_progress_available = student_course_progress_available($conn);
  $lesson_progress_map = $lesson_progress_available
    ? student_course_progress_load($conn, $user_id, $course_id)
    : [];
  mmh_track_daily_visit($conn, $user_id);
  mmh_log_event($conn, $user_id, 'course_opened', ['course_id' => $course_id]);

  $courses_stmt = $conn->prepare("SELECT courses.*, course_items.*, course_items.id AS iid, course_logs.*, course_sections.section_id AS section_sid, course_sections.title AS section_title, course_sections.description AS section_description, course_sections.sort_order AS section_sort_order, course_sections.status AS section_status, course_sections.section_type AS section_type, course_sections.custom_type AS section_custom_type, course_sections.icon AS section_icon, course_sections.unlock_mode AS section_unlock_mode, course_sections.completion_rule AS section_completion_rule, course_sections.unlock_at AS section_unlock_at, course_sections.unlock_timezone AS section_unlock_timezone, course_sections.unlock_homework_id AS section_unlock_homework_id, course_sections.manual_unlocked AS section_manual_unlocked, course_sections.release_mode AS section_release_mode, course_sections.release_override AS section_release_override, course_sections.release_at AS section_release_at, course_sections.release_timezone AS section_release_timezone, course_sections.release_occurrence_id AS section_release_occurrence_id, course_sections.release_delay_minutes AS section_release_delay_minutes, course_sections.metadata AS section_metadata
      FROM courses
      INNER JOIN course_logs ON courses.course_id = course_logs.course_id
      LEFT JOIN course_items ON courses.course_id = course_items.course_id
        AND (course_items.status IS NULL OR course_items.status = '' OR course_items.status = 'published')
        AND (
          course_items.section_id IS NULL OR course_items.section_id = '' OR EXISTS (
            SELECT 1 FROM course_sections AS visible_sections
            WHERE visible_sections.course_id = course_items.course_id
              AND visible_sections.section_id = course_items.section_id
              AND (visible_sections.status IS NULL OR visible_sections.status = '' OR visible_sections.status = 'published')
          )
        )
      LEFT JOIN course_sections ON course_items.section_id = course_sections.section_id
        AND course_items.course_id = course_sections.course_id
        AND (course_sections.status IS NULL OR course_sections.status = '' OR course_sections.status = 'published')
      WHERE course_logs.user_id = ? AND courses.course_id = ?
      ORDER BY CASE WHEN course_items.section_id IS NULL OR course_items.section_id = '' THEN 0 ELSE 1 END ASC, course_sections.sort_order ASC, course_sections.id ASC, course_items.sort_order ASC, course_items.page_order ASC, course_items.item_id ASC, course_items.id ASC");
  if ($courses_stmt) {
    $courses_stmt->bind_param('is', $user_id, $course_id);
    $courses_stmt->execute();
    $coures_result = $courses_stmt->get_result();
  }
}

if ($coures_result && mysqli_num_rows($coures_result) > 0) {
  $course = '';
  $course_sections = [];
  $lesson_inventory_by_section = [];
  // Rich lesson HTML is only emitted for the selected lesson. This keeps the
  // outer course list a protected navigation surface without exposing legacy
  // provider links from collapsed panels.
  $lesson_panels = [];
  $course_item_count = 0;
  // The result contains one row for each visible course item (or one empty
  // course row), so this gives the list a stable, plain-text position label.
  $course_total_visible_items = max(0, (int) mysqli_num_rows($coures_result));
  $course_duration_minutes = 0;
  $course_known_duration_count = 0;
  $course_sequential_learning = 0;
  $course_title = "";
  $course_description = "";
  $course_teacher = "";
  $course_image = "";
  $categorie_title = "";
  $category_description = "";
  $first_lesson_anchor = '#course-content';
  $previous_homework_item = null;
  while( $courses_data = mysqli_fetch_assoc($coures_result) ){
    $course_title = $courses_data['course_title'];
    $course_description = $courses_data['course_description'];
    $course_teacher = $courses_data['username'];
    $course_image = $courses_data['course_image'];
    $course_sequential_learning = isset($courses_data['sequential_learning']) ? (int) $courses_data['sequential_learning'] : 0;
    $has_visible_item = !empty($courses_data['iid']);
    if ($has_visible_item) {
      $course_item_count++;
    }
    $categorie_title = $courses_data['course_category'] ?? '';
    if (!$has_visible_item) {
        continue;
    }
    if ($first_lesson_anchor === '#course-content') {
        $first_lesson_anchor = '#lesson-' . $courses_data['iid'];
    }
    $template_type = $courses_data['template_type'] ?? '';
    $template_data = student_course_template_data($courses_data['template_data'] ?? '');
    $lesson_section_id = !empty($courses_data['section_sid']) ? (string) $courses_data['section_sid'] : '';
    $section_key = $lesson_section_id !== '' ? $lesson_section_id : '__general__';
    $raw_lesson_assignment_id = trim((string) ($courses_data['assignment_id'] ?? ($template_data['assignment_id'] ?? '')));
    $lesson_assignment_progress = $raw_lesson_assignment_id !== '' ? ($assignment_progress_map[$raw_lesson_assignment_id] ?? null) : null;
    $lesson_requirement_state = mmh_assignment_progress_item_state($assignment_progress_map, (string) ($courses_data['item_id'] ?? ''), $lesson_section_id);
    $resource_resolution = mmh_course_resource_resolve([
      'item_type' => $courses_data['item_type'] ?? '',
      'template_type' => $template_type,
      'template_data' => $courses_data['template_data'] ?? '',
      'item_description' => $courses_data['item_description'] ?? '',
      'item_title' => $courses_data['item_title'] ?? '',
      'metadata' => $courses_data['metadata'] ?? '',
      'section_metadata' => $courses_data['section_metadata'] ?? '',
    ]);
    $resource_action = $resource_resolution['action'] ?? 'render';
    // A legacy Model Answer is folded into the immediately preceding Homework
    // only when title, section, and safe resource resolution all agree. The
    // original record is retained for admin/rollback compatibility.
    $is_related_model_answer = false;
    if ($previous_homework_item !== null
      && ($previous_homework_item['section_id'] ?? '') === $lesson_section_id
      && ($previous_homework_item['action'] ?? '') === 'homework'
      && in_array($resource_action, ['embed', 'redirect'], true)
      && preg_match('/\bmodel\s+answer|homework\s+answers?\b/i', (string) ($courses_data['item_title'] ?? ''))
      && mmh_homework_relation_key($previous_homework_item['title'] ?? '') !== ''
      && mmh_homework_relation_key($previous_homework_item['title'] ?? '') === mmh_homework_relation_key($courses_data['item_title'] ?? '')) {
        $is_related_model_answer = true;
    }
    if ($is_related_model_answer) {
      continue;
    }
    $resource_direct = in_array($resource_action, ['embed', 'redirect', 'unavailable', 'homework'], true);
    $resource_external = $resource_action === 'redirect' && !empty($resource_resolution['open_in_new_tab']);
    $resource_open_url = rtrim((string) $baseUrl, '/') . '/user/course/resource/' . rawurlencode($course_id) . '/' . rawurlencode((string) ($courses_data['item_id'] ?? ''));
    $lesson_type = trim((string) ($resource_resolution['label'] ?? ''));
    if ($lesson_type === '') {
      [$lesson_type] = mmh_course_resource_meta($template_type ?: ($courses_data['item_type'] ?? ''));
    }
    $lesson_icon_class = trim((string) ($resource_resolution['icon'] ?? ''));
    if ($lesson_icon_class === '') {
      [, $lesson_icon_class] = mmh_course_resource_meta($template_type ?: ($courses_data['item_type'] ?? ''));
    }
    $lesson_kind_class = strtolower((string) preg_replace('/[^a-z0-9_-]+/', '-', $template_type ?: ($courses_data['item_type'] ?? 'resource')));
    $safe_lesson_title = student_course_html($courses_data['item_title']);
    $lesson_event_type = student_course_html(mmh_lesson_open_event($template_type ?: ($courses_data['item_type'] ?? '')));
    $lesson_assignment_id = student_course_html($raw_lesson_assignment_id);
    $lesson_exam_id = student_course_html($template_data['exam_id'] ?? '');
    $lesson_item_id = student_course_html($courses_data['item_id'] ?? '');
    $lesson_duration = mmh_format_duration_minutes($courses_data['duration_minutes'] ?? null);
    $lesson_meta_parts = [student_course_html($lesson_type), 'Lesson ' . $course_item_count . ' of ' . $course_total_visible_items];
    if ($lesson_duration !== '') {
      $lesson_meta_parts[] = student_course_html($lesson_duration);
    }
    $lesson_due_at = trim((string) ($courses_data['due_date'] ?? ($template_data['due_date'] ?? '')));
    if ($raw_lesson_assignment_id !== '' && $lesson_due_at !== '') {
      $lesson_due_timestamp = strtotime($lesson_due_at);
      $lesson_meta_parts[] = 'Due ' . student_course_html($lesson_due_timestamp !== false ? date('j M', $lesson_due_timestamp) : $lesson_due_at);
    }
    $lesson_meta_html = '';
    foreach ($lesson_meta_parts as $lesson_meta_index => $lesson_meta_part) {
      if ($lesson_meta_index > 0) {
        $lesson_meta_html .= "<span class='course-lesson-meta-separator' aria-hidden='true'>•</span>";
      }
      $lesson_meta_html .= "<span>{$lesson_meta_part}</span>";
    }
    $lesson_progress_item = [
      'item_id' => (string) ($courses_data['item_id'] ?? ''),
      'item_type' => $courses_data['item_type'] ?? '',
      'template_type' => $template_type,
      'template_data' => $courses_data['template_data'] ?? '',
      'assignment_id' => $courses_data['assignment_id'] ?? '',
      'duration_minutes' => $courses_data['duration_minutes'] ?? null,
    ];
    $lesson_manual_eligible = $lesson_progress_available && student_course_progress_manual_completion_eligible($lesson_progress_item);
    $lesson_assignment_complete = !empty($lesson_requirement_state['has_requirements']) && !empty($lesson_requirement_state['complete']);
    $lesson_completed = ($lesson_progress_available && student_course_progress_is_completed($lesson_progress_map, $lesson_progress_item['item_id'])) || $lesson_assignment_complete;
    $lesson_completion_action = '';
    if ($lesson_manual_eligible && !$lesson_completed) {
      $lesson_completion_action = "
        <div class='course-lesson-complete-row'>
          <button type='button' class='course-btn course-btn-secondary course-lesson-complete-btn' data-lesson-complete>
            <span class='fas fa-check ds-icon ds-icon-sm' aria-hidden='true'></span>
            <span>Mark Lesson Complete</span>
          </button>
        </div>";
    }
    $lesson_panel_id = 'lesson-panel-' . student_course_html($courses_data['iid']);
    $lesson_trigger_attributes = "data-lesson-trigger-item-id='{$lesson_item_id}' aria-label='Open " . student_course_html($lesson_type) . ": {$safe_lesson_title}'";
    if ($resource_direct) {
      $lesson_trigger_attributes .= " data-direct-resource='1' data-resource-behavior='" . student_course_html($resource_action) . "'";
    } else {
      $lesson_trigger_attributes .= " data-rich-resource='1' aria-expanded='false' aria-controls='{$lesson_panel_id}'";
    }
    $lesson_trigger_target = $resource_external ? " target='_blank' rel='noopener noreferrer'" : '';
    $lesson_panel = $resource_direct ? '' : "
    <div class='panel course-lesson-panel' id='{$lesson_panel_id}'>
      <div class='course-lesson-content'>
        {$courses_data['item_description']}
        {$lesson_completion_action}
      </div>
    </div>";
    $lesson_panel_marker = '<!-- course-lesson-panel:' . $lesson_item_id . ' -->';
    if ($lesson_panel !== '') {
      $lesson_panels[(string) ($courses_data['item_id'] ?? '')] = $lesson_panel;
    }
    if ($resource_action === 'homework') {
      $previous_homework_item = ['section_id' => $lesson_section_id, 'action' => 'homework', 'title' => (string) ($courses_data['item_title'] ?? '')];
    } else {
      $previous_homework_item = null;
    }
    $lesson_html = "
  <article class='course-lesson-card course-lesson-card--{$lesson_kind_class}' id='lesson-{$courses_data['iid']}' data-course-item-id='{$lesson_item_id}' data-learning-event='{$lesson_event_type}' data-learning-item-id='{$lesson_item_id}' data-learning-assignment-id='{$lesson_assignment_id}' data-learning-exam-id='{$lesson_exam_id}' data-progress-item-id='{$lesson_item_id}' data-progress-section-id='" . student_course_html($section_key) . "' data-progress-completed='" . ($lesson_completed ? '1' : '0') . "'>
    <a class='course-lesson-trigger course-lesson-row' href='" . student_course_html($resource_open_url) . "' {$lesson_trigger_attributes}{$lesson_trigger_target}>
      <span class='course-lesson-leading'>
        <span class='course-lesson-icon course-lesson-icon--{$lesson_kind_class}'><i class='" . student_course_html($lesson_icon_class) . " ds-icon ds-icon-md' aria-hidden='true'></i></span>
        <span class='course-lesson-heading'>
          <span class='course-lesson-title'>{$safe_lesson_title}</span>
          <span class='course-lesson-meta'>{$lesson_meta_html}</span>
        </span>
      </span>
      <i class='course-lesson-chevron student-fa fas fa-chevron-right' aria-hidden='true'></i>
    </a>
    {$lesson_panel_marker}
  </article>
    ";
    if (!isset($course_sections[$section_key])) {
        $course_sections[$section_key] = [
          'key' => $section_key,
          'title' => $section_key === '__general__' ? 'General' : ($courses_data['section_title'] ?: 'Section'),
          'description' => $section_key === '__general__' ? 'Lessons that are not assigned to a specific section yet.' : ($courses_data['section_description'] ?? ''),
          'section_type' => $section_key === '__general__' ? 'general' : ($courses_data['section_type'] ?: 'lecture'),
          'custom_type' => $section_key === '__general__' ? '' : ($courses_data['section_custom_type'] ?? ''),
          'icon' => $section_key === '__general__' ? 'folder' : ($courses_data['section_icon'] ?? ''),
          'unlock_mode' => $section_key === '__general__' ? 'always' : ($courses_data['section_unlock_mode'] ?: 'always'),
          'completion_rule' => $section_key === '__general__' ? 'manual_completion' : ($courses_data['section_completion_rule'] ?: 'manual_completion'),
          'unlock_at' => $section_key === '__general__' ? '' : ($courses_data['section_unlock_at'] ?? ''),
          'unlock_timezone' => $section_key === '__general__' ? 'Africa/Cairo' : ($courses_data['section_unlock_timezone'] ?: 'Africa/Cairo'),
          'unlock_homework_id' => $section_key === '__general__' ? '' : ($courses_data['section_unlock_homework_id'] ?? ''),
          'manual_unlocked' => $section_key === '__general__' ? 0 : (int) ($courses_data['section_manual_unlocked'] ?? 0),
          'release_mode' => $section_key === '__general__' ? 'inherit' : ($courses_data['section_release_mode'] ?? 'inherit'),
          'release_override' => $section_key === '__general__' ? 'inherit' : ($courses_data['section_release_override'] ?? 'inherit'),
          'release_at' => $section_key === '__general__' ? '' : ($courses_data['section_release_at'] ?? ''),
          'release_timezone' => $section_key === '__general__' ? 'Asia/Riyadh' : ($courses_data['section_release_timezone'] ?? 'Asia/Riyadh'),
          'release_occurrence_id' => $section_key === '__general__' ? '' : ($courses_data['section_release_occurrence_id'] ?? ''),
          'release_delay_minutes' => $section_key === '__general__' ? 0 : (int) ($courses_data['section_release_delay_minutes'] ?? 0),
          'course_id' => $course_id,
          'first_anchor' => '#lesson-' . $courses_data['iid'],
          'count' => 0,
          'duration_minutes' => 0,
          'known_duration_count' => 0,
          'lessons' => '',
        ];
    }
    if (!isset($lesson_inventory_by_section[$section_key])) {
      $lesson_inventory_by_section[$section_key] = [];
    }
    $lesson_inventory_by_section[$section_key][] = [
      'item_id' => $lesson_progress_item['item_id'],
      'anchor' => '#lesson-' . $courses_data['iid'],
      'section_key' => $section_key,
      'section_id' => $lesson_section_id,
      'assignment_id' => $raw_lesson_assignment_id,
      'manual_eligible' => $lesson_manual_eligible,
      'duration_minutes' => $lesson_progress_item['duration_minutes'],
      'resource_url' => $resource_direct ? $resource_open_url : '',
    ];
    $course_sections[$section_key]['count']++;
    if ($lesson_duration !== '') {
      $lesson_duration_minutes = (int) $courses_data['duration_minutes'];
      $course_sections[$section_key]['duration_minutes'] += $lesson_duration_minutes;
      $course_sections[$section_key]['known_duration_count']++;
      $course_duration_minutes += $lesson_duration_minutes;
      $course_known_duration_count++;
    }
    $course_sections[$section_key]['lessons'] .= $lesson_html;
  }
  $ordered_sections = array_values($course_sections);
  $learning_override = student_course_access_learning_override($conn, $course_id, $user_id);
  $learning_enabled = student_course_access_learning_enabled($course_sequential_learning, $learning_override);
  $progress_map = student_course_access_progress_map($conn, $course_id, $user_id);
  $completed_map = [];
  $section_completion_states = [];

  foreach ($ordered_sections as $section_index => $section) {
    $section_completion_state = student_course_access_section_completion_state($conn, $section, $progress_map, $user_id, $assignment_progress_map);
    $section_completion_states[(string) $section['key']] = $section_completion_state;
    $completed_map[(string) $section['key']] = !empty($section_completion_state['complete']);
  }

  $first_lesson_anchor = '#course-content';
  $accessible_lesson_inventory = [];
  $renderable_sections = [];
  foreach ($ordered_sections as $section_index => $section) {
    if ((int) $section['count'] <= 0) {
      continue;
    }
    $section['completed'] = !empty($completed_map[(string) $section['key']]);
    $unlock_state = student_course_access_section_unlock_state($conn, $section, $section_index, $ordered_sections, $completed_map, $learning_override, $learning_enabled, $user_id);
    $section['locked'] = $unlock_state['locked'];
    $section['lock_reason'] = $unlock_state['reason'];
    $section['unlock_badge'] = $unlock_state['badge'];
    $section['assignment_requirements'] = $section_completion_states[(string) $section['key']]['requirements'] ?? [];
    $section['show_complete_button'] = !$section['completed']
      && empty($section['assignment_requirements']['has_requirements'])
      && student_course_section_can_show_complete_button($section['completion_rule'] ?? 'manual_completion');
    if (!$section['locked'] && $first_lesson_anchor === '#course-content' && !empty($section['first_anchor'])) {
      $first_lesson_anchor = $section['first_anchor'];
    }
    if (!$section['locked'] && !empty($lesson_inventory_by_section[(string) $section['key']])) {
      foreach ($lesson_inventory_by_section[(string) $section['key']] as $lesson_inventory_item) {
        $accessible_lesson_inventory[] = $lesson_inventory_item;
      }
    }
    $renderable_sections[] = $section;
  }
  if ($lesson_progress_available) {
    $course_progress_summary = student_course_progress_calculate($accessible_lesson_inventory, $lesson_progress_map, $assignment_progress_map);
    $resume_lesson = student_course_progress_resolve_resume($accessible_lesson_inventory, $lesson_progress_map);
  }
  if (!isset($resume_lesson)) {
    $resume_lesson = student_course_progress_resolve_resume($accessible_lesson_inventory, $lesson_progress_map);
  }

  foreach ($accessible_lesson_inventory as $lesson_inventory_item) {
    if ($requested_lesson_id !== null && (string) $lesson_inventory_item['item_id'] === $requested_lesson_id) {
      $selected_lesson = $lesson_inventory_item;
      break;
    }
  }
  if ($selected_lesson === null) {
    $selected_lesson = $resume_lesson;
  }
  if ($selected_lesson !== null) {
    $selected_lesson_id = (string) ($selected_lesson['item_id'] ?? '');
    // A resume item may be highlighted, but its rich HTML remains closed until
    // the student explicitly opens a lesson. This keeps the initial page a
    // navigation list and prevents a resume URL from being pushed implicitly.
    $selected_lesson_needs_url_replace = $requested_lesson_id !== null
      && $selected_lesson_id !== ''
      && $requested_lesson_id !== $selected_lesson_id;
  }

  foreach ($accessible_lesson_inventory as $lesson_index => $lesson_inventory_item) {
    if ($selected_lesson_id === '' || (string) $lesson_inventory_item['item_id'] !== $selected_lesson_id) {
      continue;
    }
    $previous_lesson = $lesson_index > 0 ? $accessible_lesson_inventory[$lesson_index - 1] : null;
    $next_lesson = $lesson_index < count($accessible_lesson_inventory) - 1 ? $accessible_lesson_inventory[$lesson_index + 1] : null;
    break;
  }

  foreach (mmh_assignment_progress_attention($assignment_progress_map) as $attention_assignment) {
    $attention_item_id = (string) ($attention_assignment['item_id'] ?? '');
    if ($attention_item_id === '') {
      continue;
    }
    foreach ($accessible_lesson_inventory as $lesson_inventory_item) {
      if ((string) ($lesson_inventory_item['item_id'] ?? '') === $attention_item_id) {
        $continue_lesson = $lesson_inventory_item;
        break 2;
      }
    }
  }
  if ($continue_lesson === null) {
    foreach ($accessible_lesson_inventory as $lesson_inventory_item) {
      if (!empty($lesson_inventory_item['manual_eligible']) && !student_course_progress_is_completed($lesson_progress_map, $lesson_inventory_item['item_id'])) {
        $continue_lesson = $lesson_inventory_item;
        break;
      }
    }
  }
  if ($continue_lesson === null) {
    if (!empty($course_progress_summary['available']) && (int) $course_progress_summary['incomplete_count'] === 0 && $resume_lesson !== null) {
      $continue_lesson = $resume_lesson;
    } else {
      $continue_lesson = $accessible_lesson_inventory[0] ?? null;
    }
  }
  $all_sections_complete = !empty($renderable_sections);
  foreach ($renderable_sections as $renderable_section) {
    if (empty($renderable_section['completed'])) {
      $all_sections_complete = false;
      break;
    }
  }
  $course_completed = $lesson_progress_available
    && !empty($course_progress_summary['available'])
    && (int) $course_progress_summary['incomplete_count'] === 0
    && $all_sections_complete;

  if (!$course_return_to_lesson && $requested_lesson_id !== null && $selected_lesson !== null && !empty($selected_lesson['resource_url'])) {
    header('Location: ' . $selected_lesson['resource_url'], true, 302);
    exit;
  }
  if ($selected_lesson_id !== '') {
    $selected_lesson_html = student_course_html($selected_lesson_id);
    $selected_lesson_panel = $lesson_panels[$selected_lesson_id] ?? '';
    if ($requested_lesson_id !== null && $selected_lesson_panel !== '') {
      $selected_lesson_marker = '<!-- course-lesson-panel:' . $selected_lesson_html . ' -->';
      foreach ($ordered_sections as &$ordered_section) {
        $ordered_section['lessons'] = str_replace($selected_lesson_marker, $selected_lesson_panel, $ordered_section['lessons']);
      }
      unset($ordered_section);
      // Renderable sections are assembled below from the same ordered data.
      foreach ($renderable_sections as &$renderable_section) {
        $renderable_section['lessons'] = str_replace($selected_lesson_marker, $selected_lesson_panel, $renderable_section['lessons']);
      }
      unset($renderable_section);
    }
    foreach ($renderable_sections as &$renderable_section) {
      $renderable_section['selection_resolved'] = true;
      $renderable_section['selected'] = (string) $renderable_section['key'] === (string) ($selected_lesson['section_key'] ?? '');
      if (!$renderable_section['selected']) {
        continue;
      }
      $active_card_marker = "data-course-item-id='{$selected_lesson_html}'";
      $active_trigger_marker = "data-lesson-trigger-item-id='{$selected_lesson_html}'";
      $renderable_section['lessons'] = str_replace($active_card_marker, $active_card_marker . " data-active-lesson='1'", $renderable_section['lessons']);
      $renderable_section['lessons'] = str_replace($active_trigger_marker, $active_trigger_marker . " aria-current='step'", $renderable_section['lessons']);
    }
    unset($renderable_section);
  }
  foreach ($renderable_sections as $section_index => $renderable_section) {
    $course .= student_course_render_section($renderable_section, $section_index);
  }
  if (isset($courses_stmt)) {
    $courses_stmt->close();
  }
  if (empty($course_image)) {
    $course_image = "resources/images/default/cover.png";
  }
  if ($course === '') {
    $course = "
    <div class='course-empty-state'>
      <span class='fas fa-info-circle course-empty-icon'></span>
      <div class='course-empty-title'>
        No published lessons available now
      </div>
    </div>
    ";
  }
}else{
    $course_title = "Course unavailable";
    $course_description = "";
    $course_teacher = "";
    $course_image = "resources/images/default/cover.png";
    $categorie_title = "";
    $category_description = "";
    $course_item_count = 0;
    $course_duration_minutes = 0;
    $course_known_duration_count = 0;
    $course_sequential_learning = 0;
    $first_lesson_anchor = '#course-content';
    $course = "
    <div class='course-empty-state'>
      <span class='fas fa-info-circle course-empty-icon'></span>
      <div class='course-empty-title'>
        " . student_course_html($course_access_message) . "
      </div>
      <div class='mt-3'>
        <a href='/' class='course-btn course-btn-secondary'><span class='fas fa-home'></span> <span>Go to Home</span> </a>
      </div>
    </div>
    ";
}

$course_title_html = student_course_html($course_title);
$course_description_html = student_course_html($course_description);
$course_teacher_html = student_course_html($course_teacher);
$course_image_html = student_course_html($course_image);
$category_title_html = student_course_html($categorie_title);
$course_page_url = $course_id !== ''
  ? rtrim($baseUrl, '/') . '/user/course/' . rawurlencode($course_id)
  : '#course-content';
$continue_lesson_url = '#course-content';
$previous_lesson_url = '';
$next_lesson_url = '';
if ($course_page_url !== '#course-content') {
  if ($continue_lesson !== null && !empty($continue_lesson['item_id'])) {
    $continue_lesson_url = !empty($continue_lesson['resource_url']) ? $continue_lesson['resource_url'] : $course_page_url . '?lesson=' . rawurlencode((string) $continue_lesson['item_id']);
  }
  if ($previous_lesson !== null && !empty($previous_lesson['item_id'])) {
    $previous_lesson_url = !empty($previous_lesson['resource_url']) ? $previous_lesson['resource_url'] : $course_page_url . '?lesson=' . rawurlencode((string) $previous_lesson['item_id']);
  }
  if ($next_lesson !== null && !empty($next_lesson['item_id'])) {
    $next_lesson_url = !empty($next_lesson['resource_url']) ? $next_lesson['resource_url'] : $course_page_url . '?lesson=' . rawurlencode((string) $next_lesson['item_id']);
  }
}
$continue_lesson_url_html = student_course_html($continue_lesson_url);
$previous_lesson_url_html = student_course_html($previous_lesson_url);
$next_lesson_url_html = student_course_html($next_lesson_url);
$selected_lesson_id_html = student_course_html($selected_lesson_id);
$course_progress_percentage = (int) ($course_progress_summary['percentage'] ?? 0);
$course_progress_status = 'Progress tracking unavailable';
$course_remaining_duration_label = 'Remaining duration not available';
if ($lesson_progress_available) {
  if (!empty($course_progress_summary['available'])) {
    $course_progress_status = (int) $course_progress_summary['completed_count'] . ' of ' . (int) $course_progress_summary['eligible_count'] . ' eligible lessons complete';
    if ((int) $course_progress_summary['incomplete_count'] === 0) {
      $course_remaining_duration_label = 'No remaining eligible lesson duration';
    } elseif ((int) $course_progress_summary['known_remaining_count'] > 0) {
      $remaining_duration = mmh_format_duration_minutes($course_progress_summary['remaining_minutes']);
      $course_remaining_duration_label = ((int) $course_progress_summary['unknown_remaining_count'] > 0 ? 'Known remaining duration: ' : 'Remaining duration: ') . $remaining_duration;
    }
  } else {
    $course_progress_status = 'Progress not available';
  }
}


?>
<!doctype html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?=student_course_html($student_course_csrf_token);?>">
    <title><?=$course_title_html;?> | <?=student_course_html($site_name);?></title>
    <meta name="title" content="<?=$category_title_html;?> | <?=student_course_html($site_name);?>">
    <!---
وَما نَيلُ المَطالِبِ بِالتَمَنّي وَلَكِن تُؤخَذُ الدُنيا غِلاباوَ
ما اِستَعصى عَلى قَومٍ مَن الٌإِذا الإِقدامُ كانَ لَهُم رِكابا
أحمد شوقي
--->

<?php include "layouts/user/header.php"; ?>

<link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
<link href="<?=$baseUrl?>/resources/css/course-learning.css?v=<?=@filemtime('resources/css/course-learning.css') ?: 1?>" rel="stylesheet" />

<!-- If you'd like to support IE8 (for Video.js versions prior to v7) -->
<!-- <script src="https://vjs.zencdn.net/ie8/1.1.2/videojs-ie8.min.js"></script> -->


</head>

<body style="margin-top: 58px" class="body course-learning-page">
    <style type="text/css">
        #toast-container>div {
            opacity: 1;
        }
    </style>
    <div id="app">

        <div id="body-overlay"
            onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');">
        </div>
        <form id="logout-form" action="<?=$baseUrl?>/resources/logout" method="POST" class="d-none">
            <input type="hidden" name="_token" value="ZzircDW0BT50VoOEQy1f32zpl7N5E5SLXDONIvh1">
        </form>

        <?php include "layouts/user/aside.php"; ?>


        <main class="course-learning-main font-2">
            <div class="course-learning-shell">
                <aside class="course-learning-sidebar" aria-label="Course overview">
                    <div class="course-overview-card">
                        <div class="course-cover-wrap">
                            <img class="course-cover-image" src="<?=$baseUrl?>/<?=$course_image_html;?>" alt="<?=$course_title_html;?>">
                        </div>
                        <div class="course-overview-body">
                            <span class="course-eyebrow">Mathematics Course</span>
                            <h1 class="course-sidebar-title"><?=$course_title_html;?></h1>
                            <div class="course-teacher-line">
                                <span class="fas fa-user-tie" aria-hidden="true"></span>
                                <span><?=!empty($course_teacher) ? $course_teacher_html : '—';?></span>
                            </div>
                            <p class="course-sidebar-description"><?=$course_description_html;?></p>

                            <div class="course-stat-grid" aria-label="Course statistics">
                                <div class="course-stat-item">
                                    <span class="fas fa-layer-group" aria-hidden="true"></span>
                                    <div>
                                        <strong><?=$course_item_count;?></strong>
                                        <small>Lessons</small>
                                    </div>
                                </div>
                                <div class="course-stat-item">
                                    <span class="fas fa-chart-line" aria-hidden="true"></span>
                                    <div>
                                        <strong>—</strong>
                                        <small>Course Level</small>
                                    </div>
                                </div>
                                <div class="course-stat-item">
                                    <span class="fas fa-calendar-alt" aria-hidden="true"></span>
                                    <div>
                                        <strong>—</strong>
                                        <small>Last Updated</small>
                                    </div>
                                </div>
                                <div class="course-stat-item">
                                    <span class="fas fa-clock" aria-hidden="true"></span>
                                    <div>
                                        <strong><?=mmh_format_duration_minutes($course_duration_minutes) !== '' ? mmh_format_duration_minutes($course_duration_minutes) : '—';?></strong>
                                        <small><?=$course_known_duration_count > 0 ? ($course_known_duration_count < $course_item_count ? 'Known duration' : 'Total duration') : 'Duration not available';?></small>
                                    </div>
                                </div>
                            </div>

                            <div class="course-sidebar-actions">
                                <a href="<?=$continue_lesson_url_html;?>" class="course-btn course-btn-primary" aria-label="Continue learning">
                                    <span class="fas fa-play-circle" aria-hidden="true"></span>
                                    <span>Continue Learning</span>
                                </a>
                                <a href="#course-content" class="course-btn course-btn-secondary">
                                    <span class="fas fa-list-ul" aria-hidden="true"></span>
                                    <span>Browse Course Content</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </aside>

                <section class="course-learning-content" id="course-content">
                    <div class="course-header-card">
                        <nav class="course-breadcrumb" aria-label="Breadcrumb">
                            <a href="<?=$baseUrl;?>/user/my-courses">My Courses</a>
                            <span class="fas fa-chevron-right" aria-hidden="true"></span>
                            <span><?=$course_title_html;?></span>
                        </nav>
                        <div class="course-header-content">
                            <div>
                                <span class="course-eyebrow">Course Workspace</span>
                                <h2><?=$course_title_html;?></h2>
                                <p><?=$course_description_html;?></p>
                            </div>
                            <nav class="course-header-actions course-lesson-navigation" aria-label="Lesson navigation">
                                <a href="<?=$continue_lesson_url_html;?>" class="course-btn course-btn-primary" aria-label="Continue learning">
                                    <span class="fas fa-play" aria-hidden="true"></span>
                                    <span>Continue Learning</span>
                                </a>
                                <?php if ($previous_lesson_url !== ''): ?>
                                <a href="<?=$previous_lesson_url_html;?>" class="course-btn course-btn-secondary" aria-label="Open the previous accessible lesson">
                                    <span class="fas fa-backward" aria-hidden="true"></span>
                                    <span>Previous Lesson</span>
                                </a>
                                <?php else: ?>
                                <span class="course-btn course-btn-secondary course-navigation-disabled" aria-disabled="true">
                                    <span class="fas fa-backward" aria-hidden="true"></span>
                                    <span>Previous Lesson</span>
                                </span>
                                <?php endif; ?>
                                <?php if ($next_lesson_url !== ''): ?>
                                <a href="<?=$next_lesson_url_html;?>" class="course-btn course-btn-secondary" aria-label="Open the next accessible lesson">
                                    <span class="fas fa-forward" aria-hidden="true"></span>
                                    <span>Next Lesson</span>
                                </a>
                                <?php else: ?>
                                <span class="course-btn course-btn-secondary course-navigation-disabled" aria-disabled="true">
                                    <span class="fas fa-forward" aria-hidden="true"></span>
                                    <span>Next Lesson</span>
                                </span>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>

                    <section class="course-progress-summary" aria-label="Course progress">
                        <div class="course-progress-summary-heading">
                            <div>
                                <span class="course-eyebrow">Learning Progress</span>
                                <strong><?= $lesson_progress_available && !empty($course_progress_summary['available']) ? $course_progress_percentage . '%' : '—'; ?></strong>
                            </div>
                            <span class="course-progress-status"><?=student_course_html($course_progress_status);?></span>
                        </div>
                        <div class="progress course-progress-track" role="progressbar" aria-label="Eligible lesson progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $lesson_progress_available && !empty($course_progress_summary['available']) ? $course_progress_percentage : 0; ?>">
                            <div class="progress-bar course-progress-bar" style="width: <?= $lesson_progress_available && !empty($course_progress_summary['available']) ? $course_progress_percentage : 0; ?>%"></div>
                        </div>
                        <div class="course-progress-summary-footer">
                            <span class="fas fa-clock" aria-hidden="true"></span>
                            <span><?=student_course_html($course_remaining_duration_label);?></span>
                        </div>
                    </section>

                    <?php if ($course_completed): ?>
                    <section class="course-completed-state" role="status" aria-live="polite">
                        <span class="fas fa-check-circle" aria-hidden="true"></span>
                        <div>
                            <strong>Course completed</strong>
                            <span>You have completed all available lessons.</span>
                        </div>
                    </section>
                    <?php endif; ?>

                    <div id="video-container"></div>

                    <div class="course-lessons-section">
                        <div class="course-section-heading">
                            <div>
                                <span class="course-eyebrow">Course Content</span>
                                <h3>Lessons</h3>
                            </div>
                            <span class="course-section-count"><?=$course_item_count;?> lessons</span>
                        </div>
                        <div class="course-learning-lesson-list">
                            <?=$course?>
                        </div>
                    </div>
                </section>
            </div>
        </main>
        <?php include "layouts/user/footer.php"; ?>


        <div class='col-12 ds-border' style="background-image: linear-gradient(to right, var(--surface), var(--surface)); display: flex; align-items: center; justify-content: center; direction: ltr"
           >
            <div class="container">
                <div class="col-12 row d-flex justify-content-between p-0">
                    <div class="col-12 text-center mt-1 mb-2 pt-3 pb-2">
                        <p style="font-size: 14px; line-height: 1.8; margin: 0px" class="my-0 kufi text-center"><span
                                class="d-inline-block kufi"> All rights reserved © <?=$site_name;?> 2023 </span> <span
                                class="d-inline-block kufi"> All rights reserved</span></p>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" />
    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" />
    <script type="module" src="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js"
        data-navigate-track="reload"></script> <!-- Livewire Scripts -->
 
        <script src="../notification/main.js"></script>






<!-- Assignment Submission Modal -->
<div class="modal fade" id="assignmentModal" tabindex="-1" aria-labelledby="assignmentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="assignmentModalLabel">Assignment Submission</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="assignmentSubmissionForm" action="" enctype="multipart/form-data">
          <input type="hidden" name="assignment_id" id="modalAssignmentId">
          <input type="hidden" name="csrf_token" value="<?=student_course_html($student_course_csrf_token);?>">
          <div class="mb-3 d-none" data-self-score-wrap>
            <label for="assignmentSelfScore" class="form-label" id="assignmentSelfScoreLabel">My score</label>
            <input class="form-control" type="number" id="assignmentSelfScore" name="self_score" min="0" step="0.5" placeholder="Optional score">
            <small class="ds-text-muted" id="assignmentSelfScoreHelp">Your teacher can verify this score after reviewing your work.</small>
            <div class="text-danger small mt-1 d-none" id="assignmentSelfScoreError" role="alert"></div>
          </div>
          <div class="mb-3">
            <label for="assignmentFile" class="form-label">Attach Solution File (PDF, DOC, DOCX)</label>
            <input class="form-control" type="file" id="assignmentFile" name="submission_file" accept=".pdf,.doc,.docx" required>
          </div>
          <button type="submit" class="btn btn-primary">Send Solution</button>
        </form>
        <div id="assignmentSubmissionMsg" class="mt-2"></div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Assignment modal logic
  var assignmentModal = new bootstrap.Modal(document.getElementById('assignmentModal'));
  document.querySelectorAll('.show-assignment').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var assignmentId = this.getAttribute('data-assignment-id');
      var allowSelfScore = this.getAttribute('data-allow-self-score') === '1';
      var maxScore = this.getAttribute('data-max-score');
      var scoreMode = this.getAttribute('data-score-mode') || (allowSelfScore ? 'require_teacher_verification' : 'disabled');
      var selfScoreWrap = document.querySelector('[data-self-score-wrap]');
      var selfScoreInput = document.getElementById('assignmentSelfScore');
      var selfScoreLabel = document.getElementById('assignmentSelfScoreLabel');
      var selfScoreHelp = document.getElementById('assignmentSelfScoreHelp');
      var selfScoreError = document.getElementById('assignmentSelfScoreError');
      document.getElementById('assignmentSubmissionForm').reset();
      document.getElementById('modalAssignmentId').value = assignmentId;
      document.getElementById('assignmentSubmissionMsg').innerHTML = '';
      if (selfScoreError) {
        selfScoreError.textContent = '';
        selfScoreError.classList.add('d-none');
      }
      if (selfScoreWrap) {
        selfScoreWrap.classList.toggle('d-none', !allowSelfScore);
      }
      if (selfScoreInput) {
        selfScoreInput.required = allowSelfScore;
        selfScoreInput.removeAttribute('max');
        if (maxScore !== null && maxScore !== '' && !isNaN(Number(maxScore))) {
          selfScoreInput.max = String(maxScore);
        }
      }
      if (selfScoreLabel) {
        selfScoreLabel.textContent = maxScore ? 'My score out of ' + maxScore : 'My score';
      }
      if (selfScoreHelp) {
        selfScoreHelp.textContent = scoreMode === 'accept_automatically'
          ? 'This score is accepted automatically after you submit.'
          : 'Your teacher will verify this score after reviewing your work.';
      }
      assignmentModal.show();
    });
  });

  // Handle form submission
  document.getElementById('assignmentSubmissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = e.target;
    var formData = new FormData(form);
    var msgDiv = document.getElementById('assignmentSubmissionMsg');
    var selfScoreInput = document.getElementById('assignmentSelfScore');
    var selfScoreWrap = document.querySelector('[data-self-score-wrap]');
    var selfScoreError = document.getElementById('assignmentSelfScoreError');
    if (selfScoreWrap && !selfScoreWrap.classList.contains('d-none') && selfScoreInput) {
      var score = Number(selfScoreInput.value);
      var max = selfScoreInput.max === '' ? null : Number(selfScoreInput.max);
      if (selfScoreInput.value === '' || isNaN(score) || score < 0 || (max !== null && score > max)) {
        if (selfScoreError) {
          selfScoreError.textContent = max !== null ? 'Enter a score from 0 to ' + max + '.' : 'Enter a non-negative numeric score.';
          selfScoreError.classList.remove('d-none');
        }
        return;
      }
    }
    msgDiv.innerHTML = 'Uploading...';
    fetch('../requests/assignment/submission', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.status == 1) {
        var submission = data.submission || {};
        var details = '<span class="text-success">' + data.message + '</span>';
        if (submission.submitted_at) {
          details += '<div class="small mt-2">Submitted at: ' + submission.submitted_at + '</div>';
          details += '<div class="small">Self-reported score: ' + (submission.self_score === null ? 'Not required' : submission.self_score) + (submission.max_score !== null ? ' / ' + submission.max_score : '') + '</div>';
          var statusLabel = String(submission.verification_status || 'not_required').replace(/_/g, ' ');
          if (submission.verification_status === 'auto_accepted') {
            statusLabel = 'Accepted automatically';
          }
          details += '<div class="small">Verification status: ' + statusLabel + '</div>';
          if (data.lifecycle && data.lifecycle.label) {
            details += '<div class="small">Assignment progress: ' + data.lifecycle.label + (data.lifecycle.reason ? ' — ' + data.lifecycle.reason : '') + '</div>';
          }
          if (submission.final_score !== null && submission.final_score !== '') {
            details += '<div class="small">Final verified score: ' + submission.final_score + '</div>';
          }
        }
        msgDiv.innerHTML = details;
        form.reset();
      } else {
        msgDiv.innerHTML = '<span class="text-danger">' + (data.message || 'An error occurred!') + '</span>';
      }
    })
    .catch(() => {
      msgDiv.innerHTML = '<span class="text-danger">A server connection error occurred</span>';
    });
  });
});
</script>



<!-- Exam Submission Modal -->
<div class="modal fade" id="examModal" tabindex="-1" aria-labelledby="examModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="examModalLabel">Exam Submission</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="examSubmissionForm" action="" enctype="multipart/form-data">
          <input type="hidden" name="exam_id" id="modalExamId">
          <div class="mb-3">
            <label for="examFile" class="form-label">Attach Solution File (PDF, DOC, DOCX)</label>
            <input class="form-control" type="file" id="examFile" name="submission_file" accept=".pdf,.doc,.docx" required>
          </div>
          <button type="submit" class="btn btn-primary">Send Solution</button>
        </form>
        <div id="examSubmissionMsg" class="mt-2"></div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Exam modal logic
  var examModal = new bootstrap.Modal(document.getElementById('examModal'));
  document.querySelectorAll('.show-exam').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var examId = this.getAttribute('data-exam-id');
      document.getElementById('modalExamId').value = examId;
      document.getElementById('examSubmissionForm').reset();
      document.getElementById('examSubmissionMsg').innerHTML = '';
      examModal.show();
    });
  });

  // Handle form submission
  document.getElementById('examSubmissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = e.target;
    var formData = new FormData(form);
    var msgDiv = document.getElementById('examSubmissionMsg');
    msgDiv.innerHTML = 'Uploading...';
    fetch('../requests/exam/submission', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.status == 1) {
        msgDiv.innerHTML = '<span class="text-success">' + data.message + '</span>';
        form.reset();
      } else {
        msgDiv.innerHTML = '<span class="text-danger">' + (data.message || 'An error occurred!') + '</span>';
      }
    })
    .catch(() => {
      msgDiv.innerHTML = '<span class="text-danger">A server connection error occurred</span>';
    });
  });
});
</script>

<script>
var initialActiveLessonCard = document.querySelector('.course-lesson-card[data-active-lesson="1"]');
if (initialActiveLessonCard) {
  var initialActiveLessonTrigger = initialActiveLessonCard.querySelector('.course-lesson-trigger');
  var initialActiveLessonPanel = initialActiveLessonTrigger ? initialActiveLessonTrigger.nextElementSibling : null;
  if (initialActiveLessonTrigger) {
    initialActiveLessonTrigger.classList.add('activeAccordion');
    initialActiveLessonTrigger.setAttribute('aria-expanded', 'true');
  }
  if (initialActiveLessonPanel) {
    initialActiveLessonPanel.style.maxHeight = initialActiveLessonPanel.scrollHeight + 'px';
  }
}

var acc = document.querySelectorAll("button.accordion");
var i;

for (i = 0; i < acc.length; i++) {
  if (acc[i].dataset.accordionReady === "1") {
    continue;
  }
  acc[i].dataset.accordionReady = "1";
  acc[i].addEventListener("click", function() {
    var isOpening = !this.classList.contains("activeAccordion");
    this.classList.toggle("activeAccordion");
    this.setAttribute("aria-expanded", isOpening ? "true" : "false");
    var panel = this.nextElementSibling;
    if (panel.style.maxHeight) {
      panel.style.maxHeight = null;
    } else {
      panel.style.maxHeight = panel.scrollHeight + "px";
    } 
  });
}

(function () {
  var sectionList = document.querySelector('[data-course-section-list]') || document.querySelector('.course-learning-lesson-list');
  if (!sectionList) {
    return;
  }

  var sections = Array.prototype.slice.call(sectionList.querySelectorAll('.student-course-section'));
  if (!sections.length) {
    return;
  }

  var storageKey = 'math-mastery-course-sections:' + window.location.pathname;
  var learningEndpoint = '<?=$baseUrl?>/user/requests/learning/event';
  var lessonProgressEndpoint = '<?=$baseUrl?>/user/requests/progress/item';
  var learningCourseId = '<?=student_course_html($course_id ?? "");?>';
  var studentCourseCsrfToken = '<?=student_course_html($student_course_csrf_token);?>';
  var savedState = null;
  var selectedLessonId = '<?=$selected_lesson_id_html;?>';
  var selectedLessonNeedsUrlReplace = <?=$selected_lesson_needs_url_replace ? 'true' : 'false';?>;
  var viewedLessonStorageKey = 'math-mastery-course-viewed:' + learningCourseId;
  var viewedLessonIds = {};

  try {
    viewedLessonIds = JSON.parse(sessionStorage.getItem(viewedLessonStorageKey) || '{}') || {};
  } catch (error) {
    viewedLessonIds = {};
  }

  function lessonCardForItem(itemId) {
    var cards = sectionList.querySelectorAll('.course-lesson-card[data-course-item-id]');
    for (var index = 0; index < cards.length; index++) {
      if (cards[index].getAttribute('data-course-item-id') === itemId) {
        return cards[index];
      }
    }
    return null;
  }

  function updateLessonUrl(itemId, mode) {
    if (!itemId || !window.history || typeof window.history.pushState !== 'function') {
      return;
    }
    try {
      var url = new URL(window.location.href);
      if (url.searchParams.get('lesson') === itemId) {
        return;
      }
      url.searchParams.set('lesson', itemId);
      window.history[mode === 'push' ? 'pushState' : 'replaceState']({ lesson: itemId }, '', url.pathname + url.search + url.hash);
    } catch (error) {}
  }

  function setActiveLesson(card, historyMode) {
    if (!card) {
      return;
    }
    var itemId = card.getAttribute('data-course-item-id') || '';
    if (!itemId) {
      return;
    }
    sectionList.querySelectorAll('.course-lesson-card[data-active-lesson="1"]').forEach(function (activeCard) {
      activeCard.removeAttribute('data-active-lesson');
      var activeTrigger = activeCard.querySelector('.course-lesson-trigger');
      if (activeTrigger) {
        activeTrigger.removeAttribute('aria-current');
      }
    });
    card.setAttribute('data-active-lesson', '1');
    var trigger = card.querySelector('.course-lesson-trigger');
    if (trigger) {
      trigger.setAttribute('aria-current', 'step');
    }
    selectedLessonId = itemId;
    if (historyMode) {
      updateLessonUrl(itemId, historyMode);
    }
  }

  function sendLearningEvent(eventType, payload) {
    if (!eventType) {
      return;
    }
    var formData = new FormData();
    formData.append('event_type', eventType);
    formData.append('course_id', learningCourseId);
    formData.append('csrf_token', studentCourseCsrfToken);
    Object.keys(payload || {}).forEach(function (key) {
      if (payload[key] !== undefined && payload[key] !== null && payload[key] !== '') {
        formData.append(key, payload[key]);
      }
    });

    if (navigator.sendBeacon) {
      navigator.sendBeacon(learningEndpoint, formData);
      return;
    }

    fetch(learningEndpoint, {
      method: 'POST',
      body: formData,
      keepalive: true
    }).catch(function () {});
  }

  function sectionPayload(section) {
    return {
      section_id: section ? (section.getAttribute('data-section-key') || '') : ''
    };
  }

  function lessonPayload(card) {
    var section = card ? card.closest('.student-course-section') : null;
    return {
      section_id: section ? (section.getAttribute('data-section-key') || '') : '',
      item_id: card ? (card.getAttribute('data-learning-item-id') || '') : '',
      assignment_id: card ? (card.getAttribute('data-learning-assignment-id') || '') : '',
      exam_id: card ? (card.getAttribute('data-learning-exam-id') || '') : ''
    };
  }

  function lessonProgressPayload(card) {
    var section = card ? card.closest('.student-course-section') : null;
    return {
      section_id: section ? (section.getAttribute('data-section-key') || '') : '',
      item_id: card ? (card.getAttribute('data-progress-item-id') || '') : ''
    };
  }

  function saveLessonProgress(action, card, waitForResponse) {
    var payload = lessonProgressPayload(card);
    if (!payload.item_id || !payload.section_id) {
      return waitForResponse ? Promise.resolve(null) : null;
    }

    var formData = new FormData();
    formData.append('action', action);
    formData.append('course_id', learningCourseId);
    formData.append('section_id', payload.section_id);
    formData.append('item_id', payload.item_id);
    formData.append('csrf_token', studentCourseCsrfToken);

    if (!waitForResponse && navigator.sendBeacon && navigator.sendBeacon(lessonProgressEndpoint, formData)) {
      return null;
    }

    return fetch(lessonProgressEndpoint, {
      method: 'POST',
      body: formData,
      keepalive: !waitForResponse
    }).then(function (response) {
      return waitForResponse ? response.json() : null;
    }).catch(function () {
      return null;
    });
  }

  function saveLessonViewed(card) {
    var payload = lessonProgressPayload(card);
    if (!payload.item_id || viewedLessonIds[payload.item_id]) {
      return;
    }

    viewedLessonIds[payload.item_id] = true;
    try {
      sessionStorage.setItem(viewedLessonStorageKey, JSON.stringify(viewedLessonIds));
    } catch (error) {}
    saveLessonProgress('viewed', card, false);
  }

  try {
    savedState = JSON.parse(localStorage.getItem(storageKey) || 'null');
  } catch (error) {
    savedState = null;
  }

  function sectionBody(section) {
    return section.querySelector('.student-course-section-body');
  }

  function sectionToggle(section) {
    return section.querySelector('.student-course-section-toggle');
  }

  function setSectionOpen(section, isOpen, animate) {
    var body = sectionBody(section);
    var toggle = sectionToggle(section);
    if (!body || !toggle) {
      return;
    }

    section.classList.toggle('is-open', isOpen);
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    if (isOpen && section.getAttribute('data-section-locked') !== '1') {
      sendLearningEvent('section_opened', sectionPayload(section));
    }

    if (!animate) {
      body.style.display = isOpen ? 'block' : 'none';
      body.style.height = '';
      return;
    }

    if (isOpen) {
      body.style.display = 'block';
      body.style.height = '0px';
      body.offsetHeight;
      body.style.height = body.scrollHeight + 'px';
      window.setTimeout(function () {
        if (section.classList.contains('is-open')) {
          body.style.height = '';
        }
      }, 330);
    } else {
      body.style.height = body.scrollHeight + 'px';
      body.offsetHeight;
      body.style.height = '0px';
      window.setTimeout(function () {
        if (!section.classList.contains('is-open')) {
          body.style.display = 'none';
          body.style.height = '';
        }
      }, 330);
    }
  }

  function openSelectedLesson(card, shouldScrollAndFocus) {
    if (!card) {
      return;
    }
    var section = card.closest('.student-course-section');
    if (section && !section.classList.contains('is-open')) {
      setSectionOpen(section, true, false);
    }
    var trigger = card.querySelector('.course-lesson-trigger');
    var panel = trigger ? trigger.nextElementSibling : null;
    if (!trigger || !panel) {
      return;
    }
    trigger.classList.add('activeAccordion');
    trigger.setAttribute('aria-expanded', 'true');
    window.requestAnimationFrame(function () {
      panel.style.maxHeight = panel.scrollHeight + 'px';
      if (!shouldScrollAndFocus) {
        return;
      }
      window.requestAnimationFrame(function () {
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        card.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
        if (typeof trigger.focus === 'function') {
          trigger.focus({ preventScroll: true });
        }
      });
    });
  }

  function persistState() {
    var state = {};
    sections.forEach(function (section) {
      state[section.getAttribute('data-section-key') || section.id] = section.classList.contains('is-open');
    });

    try {
      localStorage.setItem(storageKey, JSON.stringify(state));
    } catch (error) {}
  }

  function expandHashSection() {
    if (!window.location.hash || window.location.hash.length < 2) {
      return false;
    }

    var target = document.getElementById(window.location.hash.substring(1));
    if (!target) {
      return false;
    }

    var section = target.closest('.student-course-section');
    if (section) {
      setSectionOpen(section, true, false);
      return true;
    }

    return false;
  }

  var hasSavedState = savedState && typeof savedState === 'object';
  sections.forEach(function (section, index) {
    var key = section.getAttribute('data-section-key') || section.id;
    var shouldOpen = hasSavedState ? savedState[key] === true : index === 0;
    setSectionOpen(section, shouldOpen, false);
  });

  expandHashSection();

  var initialSelectedCard = lessonCardForItem(selectedLessonId);
  if (initialSelectedCard) {
    setActiveLesson(initialSelectedCard);
    openSelectedLesson(initialSelectedCard, true);
    saveLessonViewed(initialSelectedCard);
    if (selectedLessonNeedsUrlReplace) {
      updateLessonUrl(selectedLessonId, 'replace');
    }
  }

  sections.forEach(function (section) {
    var toggle = sectionToggle(section);
    if (!toggle || toggle.dataset.sectionReady === '1') {
      return;
    }
    toggle.dataset.sectionReady = '1';
    toggle.addEventListener('click', function () {
      if (section.getAttribute('data-section-locked') === '1') {
        section.classList.add('student-course-lock-pulse');
        window.setTimeout(function () {
          section.classList.remove('student-course-lock-pulse');
        }, 650);
        return;
      }
      var willOpen = !section.classList.contains('is-open');
      setSectionOpen(section, willOpen, true);
      persistState();
      if (willOpen && section.getAttribute('data-completion-rule') === 'opening_section' && section.getAttribute('data-completed') !== '1') {
        markSectionComplete(section, 'opening_section');
      }
    });
  });

  function markSectionComplete(section, source) {
    if (!section || section.getAttribute('data-completed') === '1') {
      return;
    }
    var formData = new FormData();
    formData.append('course_id', '<?=student_course_html($course_id ?? "");?>');
    formData.append('section_id', section.getAttribute('data-section-key') || '');
    formData.append('source', source || 'manual_completion');
    formData.append('csrf_token', studentCourseCsrfToken);
    fetch('../requests/section/complete', {
      method: 'POST',
      body: formData
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (data && (data.status == 1 || data.success === true)) {
        section.setAttribute('data-completed', '1');
        section.classList.add('is-completed');
        if (source !== 'opening_section') {
          window.location.reload();
        }
      }
    })
    .catch(function () {});
  }

  document.querySelectorAll('[data-section-complete]').forEach(function (button) {
    button.addEventListener('click', function () {
      var section = button.closest('.student-course-section');
      if (!section) {
        return;
      }
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span> Saving...</span>';
      markSectionComplete(section, 'manual_completion');
    });
  });

  document.querySelectorAll('[data-lesson-complete]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('.course-lesson-card');
      if (!card || card.getAttribute('data-progress-completed') === '1') {
        return;
      }
      var originalHtml = button.innerHTML;
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span> Saving...</span>';
      saveLessonProgress('complete', card, true).then(function (data) {
        if (data && (data.success === true || data.status == 1)) {
          window.location.reload();
          return;
        }
        button.disabled = false;
        button.innerHTML = originalHtml;
      });
    });
  });

  document.querySelectorAll('.course-lesson-trigger').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      if (trigger.getAttribute('data-direct-resource') === '1') {
        return;
      }
      var card = trigger.closest('.course-lesson-card');
      if (card) {
        sendLearningEvent(card.getAttribute('data-learning-event'), lessonPayload(card));
        if (trigger.classList.contains('activeAccordion')) {
          setActiveLesson(card, 'push');
          saveLessonViewed(card);
        }
      }
      var section = trigger.closest('.student-course-section');
      if (section && !section.classList.contains('is-open')) {
        setSectionOpen(section, true, true);
        persistState();
      }
      if (card && trigger.classList.contains('activeAccordion')) {
        openSelectedLesson(card, false);
      }
    });
  });

  window.addEventListener('hashchange', expandHashSection);
  window.addEventListener('popstate', function () {
    try {
      var itemId = new URL(window.location.href).searchParams.get('lesson') || '';
      var card = lessonCardForItem(itemId);
      if (!card) {
        return;
      }
      setActiveLesson(card);
      openSelectedLesson(card, true);
      saveLessonViewed(card);
    } catch (error) {}
  });

  document.addEventListener('click', function (event) {
    var downloadLink = event.target.closest('.course-lesson-content a[download]');
    if (!downloadLink) {
      return;
    }
    var card = downloadLink.closest('.course-lesson-card');
    sendLearningEvent('notes_downloaded', lessonPayload(card));
  });
})();
</script>






<script>
    // Event listener for lesson links within .panel h6 a selector
    document.querySelectorAll('.panel h6 a').forEach(function(link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var lessonSrc = this.getAttribute('href');
            openFullscreenIframe(lessonSrc);
        });
    });

    // Event listener for the "Escape" key
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeFullscreenIframe();
        }
    });

    // Event listener for fullscreen changes
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    document.addEventListener('mozfullscreenchange', handleFullscreenChange);
    document.addEventListener('MSFullscreenChange', handleFullscreenChange);

    function handleFullscreenChange() {
        var iframeContainer = document.getElementById('iframeContainer');

        // If not in fullscreen, remove the container
        if (!isInFullscreen()) {
            if (iframeContainer) {
                document.body.removeChild(iframeContainer);
            }
        }
    }

    function isInFullscreen() {
        return (
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement
        );
    }

    // Rest of your code...

    function openFullscreenIframe(iframeSrc) {
        // Create a new iframe element
        var lessonIframe = document.createElement('iframe');
        lessonIframe.src = iframeSrc;
        lessonIframe.allowFullscreen = true;

        // Set custom width and height for the iframe
        lessonIframe.style.width = '100%';
        lessonIframe.style.height = '100%';

        // Create a new container div for the iframe
        var iframeContainer = document.createElement('div');
        iframeContainer.id = 'iframeContainer';
        iframeContainer.style.position = 'fixed';
        iframeContainer.style.top = '0';
        iframeContainer.style.left = '0';
        iframeContainer.style.width = '100%';
        iframeContainer.style.height = '100%';
        iframeContainer.style.backgroundColor = 'var(--surface-inset)';
        iframeContainer.style.zIndex = '1000';

        // Create a close button
        var closeButton = document.createElement('div');
        closeButton.id = 'closeButton';
        closeButton.innerHTML = '✕';
        closeButton.onclick = closeFullscreenIframe;

        // Append the iframe and close button to the container
        iframeContainer.appendChild(lessonIframe);
        iframeContainer.appendChild(closeButton);

        // Append the container to the body
        document.body.appendChild(iframeContainer);

        // Request fullscreen for the container
        if (iframeContainer.requestFullscreen) {
            iframeContainer.requestFullscreen();
        } else if (iframeContainer.mozRequestFullScreen) {
            iframeContainer.mozRequestFullScreen();
        } else if (iframeContainer.webkitRequestFullscreen) {
            iframeContainer.webkitRequestFullscreen();
        } else if (iframeContainer.msRequestFullscreen) {
            iframeContainer.msRequestFullscreen();
        }
    }

    function closeFullscreenIframe() {
        // Exit fullscreen if necessary
        if (isInFullscreen()) {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        } else {
            // If not in fullscreen, trigger handleFullscreenChange directly
            handleFullscreenChange();
        }
    }
</script>


<!-- 
<script>
  document.addEventListener('DOMContentLoaded', function () {
    // Initialize Video.js
    var player;

    // Add click event listener to each button
    var buttons = document.querySelectorAll('.show-video');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        // Get the video source from the data-src attribute
        var videoSrc = this.getAttribute('data-src');

        // Create a new video element
        var video = document.createElement('video');
        video.id = 'fullscreenVideo';
        video.className = 'video-js vjs-default-skin fullscreen-video';
        video.controls = true;
        document.body.appendChild(video);

        // Initialize Video.js for the dynamically created video element
        player = videojs('fullscreenVideo');
        
        // Set video source
        player.src({
          src: videoSrc,
          type: 'video/mp4' // Update with the appropriate video type
        });

        // Play the video
        player.play();

        // Enter fullscreen mode
        if (player.requestFullscreen) {
          player.requestFullscreen();
        } else if (player.mozRequestFullScreen) {
          player.mozRequestFullScreen();
        } else if (player.webkitRequestFullscreen) {
          player.webkitRequestFullscreen();
        } else if (player.msRequestFullscreen) {
          player.msRequestFullscreen();
        }
      });
    });

    // Listen for fullscreenchange event to detect when the user exits fullscreen
    document.addEventListener('fullscreenchange', exitFullscreenHandler);
    document.addEventListener('webkitfullscreenchange', exitFullscreenHandler);
    document.addEventListener('mozfullscreenchange', exitFullscreenHandler);
    document.addEventListener('MSFullscreenChange', exitFullscreenHandler);

    function exitFullscreenHandler() {
      // Check if the document is not in fullscreen
      if (!document.fullscreenElement && !document.webkitFullscreenElement &&
          !document.mozFullScreenElement && !document.msFullscreenElement) {
        // Pause and dispose of the video when exiting fullscreen
        if (player) {
          player.dispose();
          // Remove the dynamically created video element from the DOM
          var fullscreenVideo = document.getElementById('fullscreenVideo');
          if (fullscreenVideo) {
            fullscreenVideo.parentNode.removeChild(fullscreenVideo);
          }
        }
      }
    }
  });
</script> -->


<script>
document.addEventListener('DOMContentLoaded', function () {
  // Initialize Video.js
  var player;

  // Add click event listener to each button
  var buttons = document.querySelectorAll('.show-video');
  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      // Get the video source from the data-src attribute
      var videoSrc = this.getAttribute('data-src');

      // Create a new video element
      var video = document.createElement('video');
      video.id = 'fullscreenVideo';
      video.className = 'video-js vjs-default-skin fullscreen-video';

      // Add desired attributes
      video.preload = 'true';
      video.controls = 'true';
      video.autoplay = 'false';
      // video.muted = 'false';
      video.poster = 'assets/sample.png';
      video.fluid = 'true';
      video.setAttribute('data-setup', '{"customControlsOnMobile": true, "nativeControlsForTouch": false, "playbackRates": [0.25, 0.5, 1, 1.5, 2]}');

      document.body.appendChild(video);

      // Initialize Video.js for the dynamically created video element
      player = videojs('fullscreenVideo');

      // Set video source
      player.src({
        src: videoSrc,
        type: 'video/mp4' // Update with the appropriate video type
      });

      // Play the video
      player.play();

      // Create seek buttons and add them to the control bar
      player.ready(function () {
        var jumpAmount = 5;

        // Create back button
        var backButton = document.createElement('div');
        backButton.id = 'backButton';
        backButton.className = 'vjs-control vjs-button';
        backButton.innerHTML = '<button class="vjs-control vjs-button" title="Backward 5 seconds"><span class="vjs-icon-placeholder"></span><i class="fas fa-undo"></i></button>';
        player.controlBar.el().appendChild(backButton);

        // Create forward button
        var forwardButton = document.createElement('div');
        forwardButton.id = 'forwardButton';
        forwardButton.className = 'vjs-control vjs-button';
        forwardButton.innerHTML = '<button class="vjs-control vjs-button" title="Forward 5 seconds"><span class="vjs-icon-placeholder"></span><i class="fas fa-redo"></i></button>';
        player.controlBar.el().appendChild(forwardButton);

        // Add event handlers to seek buttons
        backButton.addEventListener('click', function () {
          var newTime = player.currentTime() - jumpAmount;
          player.currentTime(newTime >= 0 ? newTime : 0);
        });

        forwardButton.addEventListener('click', function () {
          var newTime = player.currentTime() + jumpAmount;
          player.currentTime(newTime <= player.duration() ? newTime : player.duration());
        });
      });

      // Enter fullscreen mode
      if (player.requestFullscreen) {
        player.requestFullscreen();
      } else if (player.mozRequestFullScreen) {
        player.mozRequestFullScreen();
      } else if (player.webkitRequestFullscreen) {
        player.webkitRequestFullscreen();
      } else if (player.msRequestFullscreen) {
        player.msRequestFullscreen();
      }
    });
  });

  // Listen for fullscreenchange event to detect when the user exits fullscreen
  document.addEventListener('fullscreenchange', exitFullscreenHandler);
  document.addEventListener('webkitfullscreenchange', exitFullscreenHandler);
  document.addEventListener('mozfullscreenchange', exitFullscreenHandler);
  document.addEventListener('MSFullscreenChange', exitFullscreenHandler);

  function exitFullscreenHandler() {
    // Check if the document is not in fullscreen
    if (!document.fullscreenElement && !document.webkitFullscreenElement &&
      !document.mozFullScreenElement && !document.msFullscreenElement) {
      // Pause and dispose of the video when exiting fullscreen
      if (player) {
        player.dispose();
        // Remove the dynamically created video element from the DOM
        var fullscreenVideo = document.getElementById('fullscreenVideo');
        if (fullscreenVideo) {
          fullscreenVideo.parentNode.removeChild(fullscreenVideo);
        }
      }
    }
  }
});

</script>

<script src="https://players.brightcove.net/1752604059001/HCDs9Wxen_default/index.min.js"></script>
<!--<script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>-->
<!-- <script src="https://unpkg.com/videojs-contrib-hls/dist/videojs-contrib-hls.js"></script> -->
</body>

</html>
