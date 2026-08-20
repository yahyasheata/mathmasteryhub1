<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/CourseDuration.php';
require_once 'inc/learning_schema.php';
require_once 'inc/CourseSectionAvailability.php';
require_once 'inc/CourseResourceResolver.php';
require_once 'inc/AdminAssessmentService.php';

header('Content-Type: application/json; charset=utf-8');

function items_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function items_item_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function items_item_column_exists(mysqli $conn, $column)
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

function items_item_table_exists(mysqli $conn, $table)
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

function items_item_status_meta($value)
{
    $status = strtolower(trim((string) $value));
    if ($status === 'hidden' || $status === '0') {
        return ['hidden', 'Hidden', 'bg-secondary'];
    }
    if ($status === 'draft') {
        return ['draft', 'Draft', 'bg-warning ds-text-primary'];
    }
    return ['published', 'Published', 'bg-success'];
}

function items_item_status_icon($status)
{
    $icons = [
        'published' => 'fa-eye',
        'draft' => 'fa-edit',
        'hidden' => 'fa-eye-slash',
    ];

    return $icons[$status] ?? 'fa-eye';
}

function items_item_section_type_label($section_type, $custom_type = '')
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

function items_item_section_icon_class($section_type, $icon = '')
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

function items_item_section_type_class($section_type)
{
    $section_type = strtolower(trim((string) $section_type));
    $section_type = $section_type !== '' ? $section_type : 'lecture';
    return preg_replace('/[^a-z0-9_-]+/', '-', $section_type);
}

function items_item_icon($row)
{
    $template_type = $row['template_type'] ?? '';

    // Structured resources retain their semantic resource icon instead of
    // falling back to the generic file icon in Course Content.
    if ($template_type === 'resource') {
        $data = mmh_course_resource_template_data($row['template_data'] ?? '');
        $resource_type = $data['resource_type'] ?? ($data['resource']['type'] ?? 'external_link');
        $resource_provider = $data['resource_provider'] ?? ($data['resource']['provider'] ?? '');
        $resource_url = $data['resource_url'] ?? ($data['resource']['url'] ?? '');
        [, $icon] = mmh_course_resource_display_meta($resource_type, $resource_provider, $resource_url);
        return "<i class='" . items_item_html($icon) . " ds-icon ds-icon-md' aria-hidden='true'></i>";
    }

    if (mmh_course_item_is_notes($row)) {
        return "<i class='fas fa-file-alt ds-icon ds-icon-md' aria-hidden='true'></i>";
    }

    if ($template_type === 'recording') {
        return "<i class='fas fa-play-circle ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    if ($template_type === 'classified_assignment') {
        return "<i class='fas fa-clipboard-list ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    if ($template_type === 'assignment_model_answer') {
        return "<i class='fas fa-check-circle ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    if ($template_type === 'custom_lesson') {
        return "<i class='fas fa-puzzle-piece ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    if ($template_type === 'timed_exam') {
        return "<i class='fas fa-stopwatch ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    if (($row['item_type'] ?? '') === 'quiz') {
        return "<i class='fas fa-edit ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    if (($row['item_type'] ?? '') === 'file') {
        return "<i class='fas fa-file ds-icon ds-icon-md' aria-hidden='true'></i>";
    }
    return "<i class='fas fa-play ds-icon ds-icon-md' aria-hidden='true'></i>";
}

function items_item_type_label($row)
{
    if (mmh_course_item_is_notes($row)) {
        return 'Notes';
    }

    if (($row['template_type'] ?? '') === 'resource') {
        $data = mmh_course_resource_template_data($row['template_data'] ?? '');
        $resource_type = $data['resource_type'] ?? ($data['resource']['type'] ?? 'external_link');
        $resource_provider = $data['resource_provider'] ?? ($data['resource']['provider'] ?? '');
        $resource_url = $data['resource_url'] ?? ($data['resource']['url'] ?? '');
        [$label] = mmh_course_resource_display_meta($resource_type, $resource_provider, $resource_url);
        return $label;
    }

    $type = $row['template_type'] ?: ($row['item_type'] ?? 'Lesson');
    if (strtolower((string) $type) === 'custom_html') {
        return 'Legacy Lesson';
    }
    if (strtolower((string) $type) === 'classified_assignment') {
        return 'Assignment';
    }
    return ucwords(str_replace('_', ' ', (string) $type));
}


function items_item_quick_add_buttons($course_id, $section_id, $compact = false)
{
    $safe_course_id = items_item_html($course_id);
    $safe_section_id = $section_id === '__general__' ? '' : items_item_html($section_id);
    $templates = [
        'recording' => ['fas fa-play-circle', 'Recording'],
        'notes' => ['fas fa-file-alt', 'Notes'],
        'classified_assignment' => ['fas fa-clipboard-list', 'Assignment'],
        'custom_lesson' => ['fas fa-puzzle-piece', 'Custom Lesson'],
    ];

    $html = "<div class='course-builder-quick-actions " . ($compact ?'course-builder-quick-actions-empty' : '') . "'>";
    foreach ($templates as $template => $meta) {
        $safe_template = items_item_html($template);
        $icon = items_item_html($meta[0]);
        $label = items_item_html($meta[1]);
        $html .= "
          <form method='POST' action='' class='d-inline-block itemForm quickItemForm'>
            <input type='hidden' name='course_id' value='{$safe_course_id}'>
            <input type='hidden' name='section_id' value='{$safe_section_id}'>
            <input type='hidden' name='template_type' value='{$safe_template}'>
            <input type='hidden' name='_method' value='GET'>
            <button type='submit' class='btn btn-outline-primary btn-sm course-builder-quick-add'>
              <span class='{$icon} ds-icon ds-icon-sm' aria-hidden='true'></span> {$label}
            </button>
          </form>";
    }
    $html .= '</div>';
    return $html;
}

function items_item_render_lesson($row)
{
    $item_icon = items_item_icon($row);
    $db_id = (int) $row['id'];
    $item_id = items_item_html($row['item_id']);
    $safe_course_id = items_item_html($row['course_id']);
    $title = items_item_html($row['item_title']);
    $type = items_item_html(items_item_type_label($row));
    $order = isset($row['page_order']) ? (int) $row['page_order'] : 0;
    $duration = mmh_format_duration_minutes($row['duration_minutes'] ?? null);
    $duration_html = $duration !== ''
        ? "<span class='mx-1'><span class='far fa-clock ds-icon ds-icon-xs' aria-hidden='true'></span> " . items_item_html($duration) . "</span>"
        : "<span class='mx-1 ds-text-muted'>Duration: Not set</span>";

    [$current_status, $status_label, $status_badge_class] = items_item_status_meta($row['status'] ?? 'published');
    $status_icon = items_item_status_icon($current_status);
    $is_published = $current_status === 'published';
    $toggle_label = $is_published ? 'Hide' : 'Publish';
    $toggle_icon = $is_published ? 'fa-eye-slash' : 'fa-eye';

    $status_class = items_item_html($current_status);

    return "
      <li class='course-builder-item d-flex align-items-start' id='course-item-{$db_id}' data-item-db-id='{$db_id}' data-item-id='{$item_id}' data-course-id='{$safe_course_id}' tabindex='0' style='margin-bottom: 18px'>
        <div class='course-builder-sort-handle ds-text-secondary' style='line-height: 56px; margin-right: 7px; font-size: 24px; font-weight: bold; cursor: grab'>
          <i class='fas fa-expand-arrows-alt ds-icon ds-icon-lg' aria-hidden='true'></i>
        </div>
        <div style='width: 100%'>
          <button type='button' class='accordion' data-item-db-id='{$db_id}'>{$item_icon} <span class='course-builder-inline-title course-builder-inline-lesson-title' data-title-kind='lesson' data-course-id='{$safe_course_id}' data-item-id='{$item_id}' data-original-title='{$title}' tabindex='0' title='Click to rename'>{$title}</span></button>
          <div class='panel'>
            <div class='mb-2 small text-muted'>
              <span class='badge bg-primary mx-1'>{$type}</span>
              <span class='badge {$status_badge_class} course-builder-status-badge course-builder-status-{$status_class} mx-1'><span class='fas {$status_icon} ds-icon ds-icon-xs' aria-hidden='true'></span>{$status_label}</span>
              <span class='mx-1'>Order: {$order}</span>
              {$duration_html}
            </div>
            <div class='course-builder-preview mb-3'>{$row['item_description']}</div>
            <div class='d-flex flex-wrap gap-2 mt-2 course-builder-lesson-actions'>
              <form method='POST' action='' class='d-inline-block toggleItemVisibility'>
                <input type='hidden' name='item_id' value='{$item_id}'>
                <input type='hidden' name='course_id' value='{$safe_course_id}'>
                <input type='hidden' name='current_status' value='{$current_status}'>
                <button type='submit' class='btn btn-outline-secondary btn-sm font-small mx-1'>
                  <span class='fas {$toggle_icon} ds-icon ds-icon-sm' aria-hidden='true'></span> {$toggle_label}
                </button>
              </form>
              <form method='POST' action='' class='d-inline-block editItem'>
                <input type='hidden' name='item_id' value='{$item_id}'>
                <input type='hidden' name='course_id' value='{$safe_course_id}'>
                <input type='hidden' name='_method' value='GET'>
                <button type='submit' class='btn btn-outline-success btn-sm font-small mx-1'>
                  <span class='fas fa-edit ds-icon ds-icon-sm' aria-hidden='true'></span> Edit
                </button>
              </form>
              <form method='POST' action='' class='d-inline-block duplicateItem'>
                <input type='hidden' name='item_id' value='{$item_id}'>
                <input type='hidden' name='course_id' value='{$safe_course_id}'>
                <input type='hidden' name='_method' value='DUPLICATE'>
                <button type='submit' class='btn btn-outline-primary btn-sm font-small mx-1'>
                  <span class='fas fa-copy ds-icon ds-icon-sm' aria-hidden='true'></span> Duplicate
                </button>
              </form>
              <button type='button' class='btn btn-outline-secondary btn-sm font-small mx-1 moveItem' data-direction='up'><span class='fas fa-arrow-up ds-icon ds-icon-sm' aria-hidden='true'></span> Move Up</button>
              <button type='button' class='btn btn-outline-secondary btn-sm font-small mx-1 moveItem' data-direction='down'><span class='fas fa-arrow-down ds-icon ds-icon-sm' aria-hidden='true'></span> Move Down</button>
              <form method='POST' action='' class='d-inline-block deleteItem'>
                <input type='hidden' name='item_id' value='{$item_id}'>
                <input type='hidden' name='course_id' value='{$safe_course_id}'>
                <input type='hidden' name='_method' value='DELETE'>
                <button type='submit' class='btn btn-outline-danger btn-sm font-small mx-1'>
                  <span class='fas fa-trash ds-icon ds-icon-sm' aria-hidden='true'></span> Delete
                </button>
              </form>
            </div>
          </div>
        </div>
      </li>";
}


function items_item_manager_render_lesson($row, $section_locked = false, array $assignment_stats = [])
{
    $db_id = (int) $row['id'];
    $item_id = items_item_html($row['item_id']);
    $course_id = items_item_html($row['course_id']);
    $title = items_item_html($row['item_title']);
    $type_label = items_item_html(items_item_type_label($row));
    $template_type = strtolower(trim((string) ($row['template_type'] ?? '')));
    $template_type = $template_type !== '' ? $template_type : strtolower(trim((string) ($row['item_type'] ?? 'legacy')));
    $template_class = items_item_html(preg_replace('/[^a-z0-9_-]+/', '-', $template_type));
    [$status_key, $status_label] = items_item_status_meta($row['status'] ?? 'published');
    $status_icon = items_item_status_icon($status_key);
    $status_key = items_item_html($status_key);
    $visibility = $status_key === 'published' ? 'visible' : 'not-visible';
    $icon = items_item_icon($row);
    $locked_badge = $section_locked ? "<span class='course-manager-row-badge course-manager-row-badge-locked'><i class='fas fa-lock ds-icon ds-icon-xs' aria-hidden='true'></i> Section rule</span>" : '';
    $publish_label = $status_key === 'published' ? 'Unpublish' : 'Publish';
    $publish_icon = $status_key === 'published' ? 'fa-eye-slash' : 'fa-eye';
    $assignment_panel = '';
    $is_assignment = $template_type === 'classified_assignment';
    if ($is_assignment) {
        $assignment_id = trim((string) ($row['assignment_id'] ?? ''));
        if ($assignment_id === '' && function_exists('mmh_course_assignment_id')) {
            $assignment_id = mmh_course_assignment_id($row);
        }
        $meta = $assignment_stats[$assignment_id] ?? null;
        if ($meta) {
            $due = trim((string) ($meta['due_date'] ?? ''));
            $due_label = $due !== '' ? date('j M Y, g:i A', strtotime($due)) : 'No due date';
            $submitted = (int) ($meta['submission_count'] ?? 0);
            $enrolled = (int) ($meta['enrolled_count'] ?? 0);
            $submission_label = $enrolled > 0 ? $submitted . ' of ' . $enrolled . ' submitted' : $submitted . ' submitted';
            $needs_review = (int) ($meta['needs_review'] ?? 0);
            $needs_label = $needs_review > 0 ? "<span class='course-manager-row-badge course-manager-row-badge-warning'><i class='fas fa-flag ds-icon ds-icon-xs' aria-hidden='true'></i> Needs review {$needs_review}</span>" : '';
            $admin_base = rtrim((string) ($GLOBALS['baseUrl'] ?? ''), '/') . '/admin';
            if ($admin_base === '/admin') {
                $admin_base = '/admin';
            }
            $submission_url = $admin_base . '/assignment-submissions?assignment_id=' . rawurlencode($assignment_id) . '&course_id=' . rawurlencode((string) ($row['course_id'] ?? '')) . '&item_id=' . rawurlencode((string) ($row['item_id'] ?? '')) . '&from_course_content=1';
            $assignment_panel = "<div class='course-manager-assignment-detail-row'><div class='course-manager-assignment-meta'><span class='course-manager-assignment-stat course-manager-assignment-due'><i class='far fa-calendar-alt ds-icon ds-icon-md' aria-hidden='true'></i><span class='course-manager-assignment-stat-copy'><span>Due</span><strong>" . items_item_html($due_label) . "</strong></span></span><span class='course-manager-assignment-stat course-manager-assignment-submissions'><i class='fas fa-users ds-icon ds-icon-md' aria-hidden='true'></i><span class='course-manager-assignment-stat-copy'><span>Submissions</span><strong>" . items_item_html($submission_label) . "</strong></span></span>{$needs_label}</div><div class='course-manager-assignment-actions'><a class='course-manager-assignment-link' href='" . items_item_html($submission_url) . "'><i class='fas fa-list ds-icon ds-icon-sm' aria-hidden='true'></i> View submissions <span aria-hidden='true'>→</span></a></div></div>";
        }
    }

    $row_actions = "<div class='course-manager-row-actions'>
          <button type='button' class='btn btn-sm btn-outline-secondary" . ($is_assignment ? " course-manager-assignment-edit" : '') . "' data-manager-action='edit-item' data-item-id='{$item_id}' title='Edit " . ($is_assignment ? 'assignment' : 'lesson') . "' aria-label='Edit " . ($is_assignment ? 'assignment' : 'lesson') . "'><i class='fas fa-pen ds-icon' aria-hidden='true'></i>" . ($is_assignment ? "<span class='course-manager-action-label'>Edit</span>" : '') . "</button>
          <div class='dropdown'>
            <button type='button' class='btn btn-sm btn-outline-secondary' data-bs-toggle='dropdown' aria-expanded='false' aria-label='Lesson actions'><i class='fas fa-ellipsis-v ds-icon' aria-hidden='true'></i></button>
            <ul class='dropdown-menu dropdown-menu-end'>
              <li><button type='button' class='dropdown-item' data-manager-action='preview-item' data-item-id='{$item_id}'><i class='fas fa-eye ds-icon ds-icon-sm' aria-hidden='true'></i> Preview in site</button></li>
              " . ($template_type === 'timed_exam' ? "<li><a class='dropdown-item' href='" . items_item_html((function_exists('mmh_current_request_base_url') ? rtrim(mmh_current_request_base_url(), '/') : '') . '/admin/courses/' . rawurlencode((string) ($row['course_id'] ?? '')) . '/timed-exam/item/' . rawurlencode((string) ($row['item_id'] ?? '')) . '/preview') . "' target='_blank' rel='noopener noreferrer'><i class='fas fa-user-check ds-icon ds-icon-sm' aria-hidden='true'></i> Preview as Student</a></li>" : '') . "
              <li><hr class='dropdown-divider'></li>
              <li><button type='button' class='dropdown-item' data-manager-action='toggle-item-status' data-item-id='{$item_id}' data-status='{$status_key}'><i class='fas {$publish_icon} ds-icon ds-icon-sm' aria-hidden='true'></i> {$publish_label}</button></li>
              <li><button type='button' class='dropdown-item' data-manager-action='duplicate-item' data-item-id='{$item_id}'><i class='fas fa-copy ds-icon ds-icon-sm' aria-hidden='true'></i> Duplicate</button></li>
              <li><hr class='dropdown-divider'></li>
              <li><button type='button' class='dropdown-item text-danger' data-manager-action='delete-item' data-item-id='{$item_id}'><i class='fas fa-archive ds-icon ds-icon-sm' aria-hidden='true'></i> Archive</button></li>
            </ul>
          </div>
        </div>";
    $title_markup = $is_assignment
        ? "<div class='course-manager-assignment-title-row'><button type='button' class='course-manager-edit-link' data-manager-action='edit-item' data-item-id='{$item_id}'>{$title}</button>{$row_actions}</div>"
        : "<button type='button' class='course-manager-edit-link' data-manager-action='edit-item' data-item-id='{$item_id}'>{$title}</button>";
    $assignment_badges = "<span class='course-manager-row-badge course-manager-type-badge'><i class='fas fa-clipboard-list ds-icon ds-icon-xs' aria-hidden='true'></i>{$type_label}</span><span class='course-manager-row-badge course-manager-status-{$status_key}'><i class='fas {$status_icon} ds-icon ds-icon-xs' aria-hidden='true'></i>{$status_label}</span>{$locked_badge}";
    $assignment_content = $is_assignment
        ? "<div class='course-manager-assignment-surface'><span class='course-manager-row-icon course-manager-assignment-icon' aria-hidden='true'>{$icon}</span><div class='course-manager-row-main'>{$title_markup}<div class='course-manager-row-meta'>{$assignment_badges}</div>{$assignment_panel}</div></div>"
        : "<span class='course-manager-row-icon' aria-hidden='true'>{$icon}</span><div class='course-manager-row-main'>{$title_markup}<div class='course-manager-row-meta'><span class='course-manager-row-badge course-manager-type-badge'>{$type_label}</span><span class='course-manager-row-badge course-manager-status-{$status_key}'>{$status_label}</span>{$locked_badge}</div>{$assignment_panel}</div>";

    return "
      <li class='lesson-manager-row course-builder-item" . ($is_assignment ? " course-manager-assignment-row" : '') . "'
          id='course-item-{$db_id}'
          data-item-db-id='{$db_id}'
          data-item-id='{$item_id}'
          data-course-id='{$course_id}'
          data-status='{$status_key}'
          data-template='{$template_class}'
        data-visible='{$visibility}'
          data-locked='" . ($section_locked ? '1' : '0') . "'>
        <label class='course-manager-select-wrap' title='Select lesson'>
          <input type='checkbox' class='course-manager-select' value='{$item_id}' aria-label='Select {$title}'>
        </label>
        <span class='course-manager-drag course-builder-sort-handle' title='Drag to reorder' aria-label='Drag to reorder'><i class='fas fa-grip-vertical ds-icon' aria-hidden='true'></i></span>
        {$assignment_content}
        " . ($is_assignment ? '' : $row_actions) . "
      </li>";
}

function items_item_manager_render_section(mysqli $conn, $course_id, array $section, $lessons_html, $lesson_count, $is_general = false)
{
    $section_id = $is_general ? '__general__' : (string) ($section['section_id'] ?? '');
    $title = $is_general ? 'General' : (string) ($section['title'] ?? 'Untitled Section');
    $description = $is_general ? 'Automatic inbox for lessons that are not assigned to a section.' : (string) ($section['description'] ?? '');
    $section_type = $is_general ? 'general' : (string) ($section['section_type'] ?? 'lecture');
    $custom_type = (string) ($section['custom_type'] ?? '');
    $section_type_label = items_item_html(items_item_section_type_label($section_type, $custom_type));
    $section_icon = items_item_html(items_item_section_icon_class($section_type, (string) ($section['icon'] ?? '')));
    [$status_key, $status_label] = items_item_status_meta($is_general ? 'published' : ($section['status'] ?? 'published'));
    $release_state = $is_general ? ['locked' => false, 'badge' => 'Available', 'release_label' => '', 'warning' => ''] : mmh_section_release_state($conn, $section);
    $legacy_rule_locked = !$is_general && strtolower(trim((string) ($section['unlock_mode'] ?? 'always'))) !== 'always';
    $locked = !empty($release_state['locked']) || $legacy_rule_locked;
    $safe_course_id = items_item_html($course_id);
    $safe_section_id = items_item_html($section_id);
    $safe_title = items_item_html($title);
    $safe_description = items_item_html($description);
    $empty_state = $lessons_html === ''
        ? "<li class='course-manager-empty'><span>No lessons in this section.</span><button type='button' class='btn btn-sm btn-outline-primary' data-manager-action='choose-content' data-section-id='" . ($is_general ? '' : $safe_section_id) . "'><i class='fas fa-plus ds-icon ds-icon-sm' aria-hidden='true'></i> Add Content</button></li>"
        : $lessons_html;
    $general_badge = $is_general ? "<span class='course-manager-section-badge course-manager-section-automatic'>Automatic inbox</span>" : "<span class='course-manager-section-badge course-manager-status-{$status_key}'>{$status_label}</span>";
    $release_badge = $is_general ? '' : "<span class='course-manager-section-badge " . ($locked ? 'course-manager-row-badge-locked' : 'course-manager-status-published') . "'><i class='fas " . ($locked ? 'fa-lock' : 'fa-unlock') . " ds-icon ds-icon-xs' aria-hidden='true'></i> " . items_item_html((string) ($release_state['badge'] ?? 'Available')) . "</span>";
    $release_detail = !$is_general && !empty($release_state['release_label']) ? "<span class='course-manager-section-count'>" . items_item_html((string) $release_state['release_label']) . "</span>" : '';
    $release_warning = !$is_general && !empty($release_state['warning']) ? "<p class='course-manager-section-description text-warning mb-0'>" . items_item_html((string) $release_state['warning']) . "</p>" : '';
    $lock_badge = $legacy_rule_locked ? "<span class='course-manager-section-badge course-manager-row-badge-locked'><i class='fas fa-lock ds-icon ds-icon-xs' aria-hidden='true'></i> Learning rule</span>" : '';
    $drag_handle = $is_general ? '' : "<span class='course-manager-section-drag course-builder-section-handle' title='Drag to reorder'><i class='fas fa-grip-vertical ds-icon' aria-hidden='true'></i></span>";
    $actions = "<button type='button' class='btn btn-sm btn-outline-primary course-manager-add-to-section' data-manager-action='choose-content' data-section-id='" . ($is_general ? '' : $safe_section_id) . "'><i class='fas fa-plus ds-icon ds-icon-sm' aria-hidden='true'></i> Add Content</button>";
    if (!$is_general) {
        $actions .= "
          <div class='dropdown'>
            <button type='button' class='btn btn-sm btn-outline-secondary' data-bs-toggle='dropdown' aria-expanded='false' aria-label='Section actions'><i class='fas fa-ellipsis-v ds-icon' aria-hidden='true'></i></button>
            <ul class='dropdown-menu dropdown-menu-end'>
              <li><button type='button' class='dropdown-item' data-manager-action='edit-section' data-section-id='{$safe_section_id}'><i class='fas fa-pen ds-icon ds-icon-sm' aria-hidden='true'></i> Edit section</button></li>
              <li><button type='button' class='dropdown-item' data-manager-action='duplicate-section' data-section-id='{$safe_section_id}'><i class='fas fa-copy ds-icon ds-icon-sm' aria-hidden='true'></i> Duplicate section</button></li>
              <li><button type='button' class='dropdown-item' data-manager-action='move-section' data-section-id='{$safe_section_id}' data-direction='up'><i class='fas fa-arrow-up ds-icon ds-icon-sm' aria-hidden='true'></i> Move up</button></li>
              <li><button type='button' class='dropdown-item' data-manager-action='move-section' data-section-id='{$safe_section_id}' data-direction='down'><i class='fas fa-arrow-down ds-icon ds-icon-sm' aria-hidden='true'></i> Move down</button></li>
              <li><hr class='dropdown-divider'></li>
              <li><button type='button' class='dropdown-item text-danger' data-manager-action='delete-section' data-section-id='{$safe_section_id}'><i class='fas fa-trash ds-icon ds-icon-sm' aria-hidden='true'></i> Delete section</button></li>
            </ul>
          </div>";
    }

    return "
      <section class='course-manager-section' data-section-id='{$safe_section_id}' data-section-title='{$safe_title}' data-general='" . ($is_general ? '1' : '0') . "' data-locked='" . ($locked ? '1' : '0') . "'>
        <header class='course-manager-section-header'>
          <div class='course-manager-section-leading'>
            {$drag_handle}
            <button type='button' class='course-manager-section-toggle' data-manager-action='toggle-section' aria-expanded='true'>
              <i class='fas fa-chevron-down course-manager-section-chevron ds-icon ds-icon-sm' aria-hidden='true'></i>
              <span class='course-manager-section-icon'><i class='{$section_icon} ds-icon' aria-hidden='true'></i></span>
              <span class='course-manager-section-title'>{$safe_title}</span>
            </button>
            <div class='course-manager-section-meta'>
              <span class='course-manager-section-badge course-manager-type-badge'>{$section_type_label}</span>
              {$general_badge}
              {$release_badge}
              {$lock_badge}
              {$release_detail}
              <span class='course-manager-section-count'>{$lesson_count} lesson" . ($lesson_count === 1 ? '' : 's') . "</span>
            </div>
            " . ($safe_description !== '' ? "<p class='course-manager-section-description'>{$safe_description}</p>" : '') . "
            {$release_warning}
          </div>
          <div class='course-manager-section-actions'>{$actions}</div>
        </header>
        <div class='course-manager-section-body'>
          <ul class='course-manager-lessons' data-section-id='" . ($is_general ? '__general__' : $safe_section_id) . "'>{$empty_state}</ul>
        </div>
      </section>";
}

function items_item_render_section($course_id, $section_id, $title, $description, $status, $lessons_html, $lesson_count, $is_general = false, $section_type = 'lecture', $custom_type = '', $icon = '')
{
    $safe_course_id = items_item_html($course_id);
    $safe_section_id = items_item_html($section_id);
    $safe_title = items_item_html($title);
    $safe_description = items_item_html($description);
    $section_type = $is_general ? 'general' : ($section_type ?: 'lecture');
    $section_type_label = items_item_html(items_item_section_type_label($section_type, $custom_type));
    $section_icon_class = items_item_html(items_item_section_icon_class($section_type, $icon));
    $section_type_class = items_item_html(items_item_section_type_class($section_type));
    [$status_key, $status_label, $status_badge_class] = items_item_status_meta($status);
    $quick_actions = items_item_quick_add_buttons($course_id, $is_general ? '__general__' : $section_id);
    $section_actions = "
      {$quick_actions}
      <form method='POST' action='' class='d-inline-block itemForm'>
        <input type='hidden' name='course_id' value='{$safe_course_id}'>
        <input type='hidden' name='section_id' value='" . ($is_general ? '' : $safe_section_id) . "'>
        <input type='hidden' name='_method' value='GET'>
        <button type='submit' class='btn btn-outline-secondary btn-sm'><span class='fas fa-plus ds-icon ds-icon-sm' aria-hidden='true'></span> More Options</button>
      </form>";

    $handle = "";
    if (!$is_general) {
        $handle = "<div class='course-builder-section-handle me-2 ds-text-secondary' style='cursor: grab; font-size: 18px'><span class='fas fa-grip-vertical ds-icon ds-icon-lg' aria-hidden='true'></span></div>";
        $section_actions .= "
          <form method='POST' action='' class='d-inline-block editSection'>
            <input type='hidden' name='course_id' value='{$safe_course_id}'>
            <input type='hidden' name='section_id' value='{$safe_section_id}'>
            <input type='hidden' name='_method' value='GET'>
            <button type='submit' class='btn btn-outline-success btn-sm'><span class='fas fa-edit ds-icon ds-icon-sm' aria-hidden='true'></span> Edit Section</button>
          </form>
          <form method='POST' action='' class='d-inline-block duplicateSection'>
            <input type='hidden' name='course_id' value='{$safe_course_id}'>
            <input type='hidden' name='section_id' value='{$safe_section_id}'>
            <input type='hidden' name='_method' value='DUPLICATE'>
            <button type='submit' class='btn btn-outline-primary btn-sm'><span class='fas fa-copy ds-icon ds-icon-sm' aria-hidden='true'></span> Duplicate Section</button>
          </form>
          <button type='button' class='btn btn-outline-secondary btn-sm moveSection' data-direction='up'><span class='fas fa-arrow-up ds-icon ds-icon-sm' aria-hidden='true'></span> Move Up</button>
          <button type='button' class='btn btn-outline-secondary btn-sm moveSection' data-direction='down'><span class='fas fa-arrow-down ds-icon ds-icon-sm' aria-hidden='true'></span> Move Down</button>
          <form method='POST' action='' class='d-inline-block deleteSection'>
            <input type='hidden' name='course_id' value='{$safe_course_id}'>
            <input type='hidden' name='section_id' value='{$safe_section_id}'>
            <input type='hidden' name='_method' value='DELETE'>
            <button type='submit' class='btn btn-outline-danger btn-sm'><span class='fas fa-trash ds-icon ds-icon-sm' aria-hidden='true'></span> Delete Section</button>
          </form>";
    }

    if ($lessons_html === '') {
        $empty_quick_actions = items_item_quick_add_buttons($course_id, $is_general ? '__general__' : $section_id, true);
        $lessons_html = "<li class='course-builder-empty list-unstyled text-center p-4 text-muted'>
          <div class='course-builder-empty-title'>No lessons yet.</div>
          <div class='course-builder-empty-subtitle'>Create your first lesson in this section.</div>
          {$empty_quick_actions}
        </li>";
    }

    $description_html = $safe_description !== '' ? "<div class='small text-muted mt-1'>{$safe_description}</div>" : '';
    $general_badge = $is_general ? "<span class='badge ds-surface-muted ds-text-primary border ms-2'>Automatic</span>" : "<span class='badge {$status_badge_class} ms-2'>{$status_label}</span>";
    $type_badge = "<span class='badge ds-surface-muted ds-text-primary border ms-2'><span class='{$section_icon_class} me-1'></span>{$section_type_label}</span>";

    return "
      <div class='course-builder-section course-builder-section-type-{$section_type_class} mb-3 ds-border ds-surface ds-shadow-sm' style='border-radius: 18px; overflow: hidden' data-section-id='{$safe_section_id}'>
        <div class='course-builder-section-header d-flex align-items-center justify-content-between flex-wrap gap-3 p-3 ds-surface ds-border'>
          <div class='d-flex align-items-start'>
            {$handle}
            <button type='button' class='course-builder-section-toggle btn btn-link text-start p-0 text-decoration-none ds-text-secondary' data-section-id='{$safe_section_id}'>
              <div class='fw-bold'><span class='fas fa-chevron-down ds-icon ds-icon-sm me-2 section-chevron' aria-hidden='true'></span><span class='{$section_icon_class} ds-icon ds-icon-md me-2 text-primary' aria-hidden='true'></span>" . ($is_general ? $safe_title : "<span class='course-builder-inline-title course-builder-inline-section-title' data-title-kind='section' data-course-id='{$safe_course_id}' data-section-id='{$safe_section_id}' data-original-title='{$safe_title}' tabindex='0' title='Click to rename'>{$safe_title}</span>") . " {$type_badge} {$general_badge}</div>
              {$description_html}
              <div class='small text-muted mt-1'>{$lesson_count} lesson" . ($lesson_count === 1 ? '' : 's') . "</div>
            </button>
          </div>
          <div class='d-flex flex-wrap gap-2'>{$section_actions}</div>
        </div>
        <div class='course-builder-section-body p-3'>
          <ul class='list-unstyled course-builder-section-lessons mb-0' data-section-id='{$safe_section_id}'>{$lessons_html}</ul>
        </div>
      </div>";
}

// The manager list is read-only. Current pages use GET; accept the former
// POST + _method=GET shape only for compatibility with already-open tabs.
$items_request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$items_request_is_legacy_read = $items_request_method === 'POST' && (string) ($_POST['_method'] ?? '') === 'GET';
if ($items_request_method !== 'GET' && !$items_request_is_legacy_read) {
    items_item_response(false, 'Invalid request method.');
}

$items_request_data = $items_request_method === 'GET' ? $_GET : $_POST;
if (!isset($items_request_data['course_id']) || trim((string) $items_request_data['course_id']) === '') {
    items_item_response(false, 'Validation failed. Course ID is missing.');
}

$conn = db();
mmh_ensure_learning_schema($conn);
$course_id = trim((string) $items_request_data['course_id']);
$safe_course_id = items_item_html($course_id);
$has_sections = items_item_column_exists($conn, 'section_id') && items_item_table_exists($conn, 'course_sections');

$sections = [];
$section_lookup = [];
if ($has_sections) {
    $section_stmt = $conn->prepare('SELECT section_id, course_id, title, description, sort_order, status, section_type, custom_type, icon, unlock_mode, release_mode, release_override, release_at, release_timezone, release_occurrence_id, release_delay_minutes FROM course_sections WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
    $section_stmt->bind_param('s', $course_id);
    $section_stmt->execute();
    $section_result = $section_stmt->get_result();
    while ($section = $section_result->fetch_assoc()) {
        $sections[] = $section;
        $section_lookup[(string) $section['section_id']] = true;
    }
    $section_stmt->close();
}

$stmt = $conn->prepare('SELECT * FROM course_items WHERE course_id = ? ORDER BY page_order ASC, id ASC');
$stmt->bind_param('s', $course_id);
$stmt->execute();
$items_result = $stmt->get_result();

$lessons_by_section = ['__general__' => ''];
$manager_rows_by_section = ['__general__' => []];
$counts_by_section = ['__general__' => 0];
$assignment_ids = [];
while ($row = $items_result->fetch_assoc()) {
    $section_id = $has_sections ? (string) ($row['section_id'] ?? '') : '';
    if ($section_id === '' || !isset($section_lookup[$section_id])) {
        $section_id = '__general__';
    }
    if (!isset($lessons_by_section[$section_id])) {
        $lessons_by_section[$section_id] = '';
        $manager_rows_by_section[$section_id] = [];
        $counts_by_section[$section_id] = 0;
    }
    $lessons_by_section[$section_id] .= items_item_render_lesson($row);
    $manager_rows_by_section[$section_id][] = $row;
    $counts_by_section[$section_id]++;
    if (strtolower(trim((string) ($row['template_type'] ?? ''))) === 'classified_assignment') {
        $assignment_id = trim((string) ($row['assignment_id'] ?? ''));
        if ($assignment_id === '' && function_exists('mmh_course_assignment_id')) {
            $assignment_id = mmh_course_assignment_id($row);
        }
        if ($assignment_id !== '') {
            $assignment_ids[] = $assignment_id;
        }
    }
}
$stmt->close();
$assignment_stats = mmh_admin_assignment_operational_stats($conn, $course_id, $assignment_ids);

$general_section = items_item_render_section(
    $course_id,
    '__general__',
    'General',
    'Automatic section for lessons that have not been assigned to a section.',
    'published',
    $lessons_by_section['__general__'] ?? '',
    $counts_by_section['__general__'] ?? 0,
    true,
    'general',
    '',
    'folder'
);

$real_sections_html = '';
foreach ($sections as $section) {
    $section_id = (string) $section['section_id'];
    $real_sections_html .= items_item_render_section(
        $course_id,
        $section_id,
        $section['title'],
        $section['description'] ?? '',
        $section['status'] ?? 'published',
        $lessons_by_section[$section_id] ?? '',
        $counts_by_section[$section_id] ?? 0,
        false,
        $section['section_type'] ?? 'lecture',
        $section['custom_type'] ?? '',
        $section['icon'] ?? ''
    );
}

if (($items_request_data['layout'] ?? '') === 'manager') {
    $manager_real_sections_html = '';
    foreach ($sections as $section) {
        $section_id = (string) $section['section_id'];
        $section_lessons_html = '';
        $section_locked = strtolower(trim((string) ($section['unlock_mode'] ?? 'always'))) !== 'always';
        foreach ($manager_rows_by_section[$section_id] ?? [] as $section_item) {
            $section_lessons_html .= items_item_manager_render_lesson($section_item, $section_locked, $assignment_stats);
        }
        $manager_real_sections_html .= items_item_manager_render_section(
            $conn,
            $course_id,
            $section,
            $section_lessons_html,
            $counts_by_section[$section_id] ?? 0,
            false
        );
    }

    $general_lessons_html = '';
    foreach ($manager_rows_by_section['__general__'] ?? [] as $general_item) {
        $general_lessons_html .= items_item_manager_render_lesson($general_item, false, $assignment_stats);
    }

    $general_manager_section = items_item_manager_render_section(
        $conn,
        $course_id,
        [],
        $general_lessons_html,
        $counts_by_section['__general__'] ?? 0,
        true
    );

    $manager_html = "
      <div class='course-manager-list-wrapper' data-course-id='{$safe_course_id}'>
        <div id='course-manager-real-sections'>{$manager_real_sections_html}</div>
        <div id='course-manager-general-section'>{$general_manager_section}</div>
      </div>";

    items_item_response(true, 'Lessons loaded successfully.', ['html' => $manager_html]);
}

$html_response = "
  <div class='course-builder-list-wrapper' data-course-id='{$safe_course_id}'>
    <div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3'>
      <div>
        <div class='fw-bold'>Course Sections</div>
        <div class='small text-muted'>Organize lessons by lecture, week, revision, or any teacher-friendly group.</div>
      </div>
      <form method='POST' action='' class='addSectionForm'>
        <input type='hidden' name='course_id' value='{$safe_course_id}'>
        <input type='hidden' name='_method' value='GET'>
        <button type='submit' class='btn btn-outline-primary btn-sm'><span class='fas fa-plus ds-icon ds-icon-sm' aria-hidden='true'></span> Add Section</button>
      </form>
    </div>
    <div id='course_sections_list'>
      {$general_section}
      <div id='course_real_sections_list'>{$real_sections_html}</div>
    </div>
  </div>";

items_item_response(true, 'Lessons loaded successfully.', ['html' => $html_response]);
?>
