<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/RecoveryPlanTemplates.php';

$conn = db();
$templateId = (int) ($_POST['template_id'] ?? 0);
$courseId = trim((string) ($_POST['course_id'] ?? ''));
$action = strtolower(trim((string) ($_POST['action'] ?? 'save')));
$base = rtrim((string) $baseUrl, '/');
$redirect = $base . '/admin/recovery-plan-templates' . ($templateId > 0 ? '?template_id=' . $templateId : '');
$flash = static function (bool $ok, string $message, int $newId = 0) use ($base): void {
    $_SESSION['recovery_template_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: ' . $base . '/admin/recovery-plan-templates' . ($newId > 0 ? '?template_id=' . $newId : ''));
    exit;
};
if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) $flash(false, 'Your session has expired. Refresh and try again.');

try {
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    if ($action === 'create') {
        if ($courseId === '') throw new InvalidArgumentException('Choose a course for the template.');
        $title = 'Recovery Plan Template'; $status = 'active';
        $stmt = $conn->prepare('INSERT INTO recovery_plan_templates (course_id, title, status, created_by) VALUES (?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Unable to create template.');
        $stmt->bind_param('sssi', $courseId, $title, $status, $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to create template.');
        $newId = (int) $stmt->insert_id; $stmt->close(); $flash(true, 'Template created.', $newId);
    }
    $template = mmh_recovery_template_load($conn, $templateId);
    if (!$template) throw new InvalidArgumentException('Template not found.');
    if ($action === 'delete') {
        $check = $conn->prepare('SELECT COUNT(*) AS total FROM recovery_plan_assignments WHERE template_id = ?');
        if (!$check) throw new RuntimeException('Unable to check template usage.');
        $check->bind_param('i', $templateId); $check->execute(); $assignedCount = (int) (($check->get_result()->fetch_assoc()['total'] ?? 0)); $check->close();
        if ($assignedCount > 0) throw new InvalidArgumentException('Templates with assigned students can only be archived.');
        $conn->begin_transaction();
        $delete = $conn->prepare('DELETE FROM recovery_plan_template_coverage WHERE template_item_id IN (SELECT id FROM recovery_plan_template_items WHERE template_id = ?)');
        if ($delete) { $delete->bind_param('i', $templateId); $delete->execute(); $delete->close(); }
        $delete = $conn->prepare('DELETE FROM recovery_plan_template_items WHERE template_id = ?');
        if ($delete) { $delete->bind_param('i', $templateId); $delete->execute(); $delete->close(); }
        $delete = $conn->prepare('DELETE FROM recovery_plan_templates WHERE id = ?');
        if (!$delete) throw new RuntimeException('Unable to delete template.');
        $delete->bind_param('i', $templateId); if (!$delete->execute()) throw new RuntimeException('Unable to delete template.'); $delete->close();
        $conn->commit(); $flash(true, 'Unused template deleted.');
    }
    if ($action === 'archive') {
        $stmt = $conn->prepare("UPDATE recovery_plan_templates SET status = 'archived', archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?"); $stmt->bind_param('i', $templateId); $stmt->execute(); $stmt->close(); $flash(true, 'Template archived. Existing student plans were preserved.');
    }
    if ($action === 'duplicate') {
        $conn->begin_transaction(); $title = mb_substr((string) $template['title'] . ' (Copy)', 0, 180); $status = 'active';
        $stmt = $conn->prepare('INSERT INTO recovery_plan_templates (course_id, title, description, status, created_by) VALUES (?, ?, ?, ?, ?)'); $stmt->bind_param('ssssi', $template['course_id'], $title, $template['description'], $status, $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to duplicate template.'); $newId = (int) $stmt->insert_id; $stmt->close();
        mmh_recovery_template_insert_items($conn, $newId, (string) $template['course_id'], array_map(static function (array $item): array { return ['item_id' => $item['item_id'], 'assignment_id' => $item['assignment_id'] ?? '', 'sort_order' => (int) $item['sort_order'], 'required' => (int) $item['is_required'], 'note' => (string) ($item['teacher_note'] ?? ''), 'duration' => $item['estimated_duration'] === null ? null : (int) $item['estimated_duration'], 'weight' => $item['weight'] === null ? null : (float) $item['weight'], 'locked' => (int) $item['locked_until_previous'], 'coverage' => $item['coverage'] ?? []]; }, $template['items'] ?? []));
        $conn->commit(); $flash(true, 'Template duplicated.', $newId);
    }
    if ($action !== 'save') throw new InvalidArgumentException('Unsupported template action.');
    if ($template['status'] === 'archived') throw new InvalidArgumentException('Archived templates cannot be edited. Duplicate them first.');
    $normalized = mmh_recovery_template_normalize_items($conn, (string) $template['course_id'], is_array($_POST['items'] ?? null) ? $_POST['items'] : []);
    $title = mb_substr(trim((string) ($_POST['title'] ?? 'Recovery Plan Template')), 0, 180) ?: 'Recovery Plan Template';
    $description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1000);
    $coverageByIndex = [];
    foreach ((array) ($_POST['coverage'] ?? []) as $index => $mapping) {
        if (!is_array($mapping)) continue;
        foreach ((array) ($mapping['items'] ?? []) as $coveredItem) $coverageByIndex[(int) $index][] = ['coverage_type' => 'item', 'covered_item_id' => trim((string) $coveredItem)];
        foreach ((array) ($mapping['sections'] ?? []) as $coveredSection) $coverageByIndex[(int) $index][] = ['coverage_type' => 'section', 'covered_section_id' => trim((string) $coveredSection)];
        foreach ((array) ($mapping['types'] ?? []) as $type) if (in_array($type, ['homework_requirement', 'recording_requirement'], true)) $coverageByIndex[(int) $index][] = ['coverage_type' => $type];
        $topic = trim((string) ($mapping['topic'] ?? '')); if ($topic !== '') $coverageByIndex[(int) $index][] = ['coverage_type' => 'topic', 'topic_label' => mb_substr($topic, 0, 255)];
    }
    $conn->begin_transaction();
    $stmt = $conn->prepare('UPDATE recovery_plan_templates SET title = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'); $stmt->bind_param('ssi', $title, $description, $templateId); $stmt->execute(); $stmt->close();
    $delete = $conn->prepare('DELETE FROM recovery_plan_template_coverage WHERE template_item_id IN (SELECT id FROM recovery_plan_template_items WHERE template_id = ?)'); $delete->bind_param('i', $templateId); $delete->execute(); $delete->close();
    $delete = $conn->prepare('DELETE FROM recovery_plan_template_items WHERE template_id = ?'); $delete->bind_param('i', $templateId); $delete->execute(); $delete->close();
    mmh_recovery_template_insert_items($conn, $templateId, (string) $template['course_id'], $normalized, $coverageByIndex);
    $conn->commit();

    $applyMode = strtolower(trim((string) ($_POST['apply_mode'] ?? 'future')));
    if (in_array($applyMode, ['not_started', 'all'], true)) {
        $updated = 0; $assigned = $conn->prepare('SELECT plan_id, student_id FROM recovery_plan_assignments WHERE template_id = ? AND status IN (\'assigned\', \'in_progress\', \'completed\')'); $assigned->bind_param('i', $templateId); $assigned->execute(); $assignmentRows = $assigned->get_result()->fetch_all(MYSQLI_ASSOC); $assigned->close();
        $updatedTemplate = mmh_recovery_template_load($conn, $templateId);
        foreach ($assignmentRows as $assignmentRow) {
            $updatedTemplate['student_id'] = (int) $assignmentRow['student_id'];
            $plan = mmh_recovery_plan_load($conn, (int) $assignmentRow['student_id'], (string) $updatedTemplate['course_id'], (int) $assignmentRow['plan_id']);
            if (!$plan) continue;
            $synced = mmh_recovery_plan_sync($conn, $plan, (int) $assignmentRow['student_id'], (string) $updatedTemplate['course_id']);
            if ($applyMode === 'not_started' && (int) ($synced['completed'] ?? 0) > 0) continue;
            mmh_recovery_template_sync_assigned_plan($conn, $updatedTemplate, (int) $assignmentRow['plan_id']); $updated++;
        }
        $flash(true, 'Template saved and applied to ' . $updated . ' eligible assigned plan(s).');
    }
    $flash(true, 'Template saved. Future assignments will use the new version.');
} catch (Throwable $exception) {
    @$conn->rollback(); $flash(false, $exception->getMessage(), $templateId);
}
