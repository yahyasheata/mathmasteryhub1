<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/ParentWeeklyReport.php';
require_once 'inc/RecoveryPlan.php';

$conn = db();
$courseId = trim((string) ($_POST['course_id'] ?? ''));
$studentId = (int) ($_POST['student_id'] ?? 0);
$planId = (int) ($_POST['plan_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? 'save')));
$redirect = rtrim((string) $baseUrl, '/') . '/admin/recovery-plan?course_id=' . rawurlencode($courseId) . '&student_id=' . $studentId;
$flash = static function (bool $ok, string $message, int $newPlanId = 0) use (&$redirect): void {
    if ($newPlanId > 0) $redirect .= '&plan_id=' . $newPlanId;
    $_SESSION['recovery_plan_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: ' . $redirect);
    exit;
};
if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) $flash(false, 'Your session has expired. Refresh and try again.');

try {
    if ($courseId === '' || $studentId <= 0 || !student_course_access_enrolled($conn, $studentId, $courseId)) throw new InvalidArgumentException('Choose an enrolled student and course.');
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    $items = mmh_learning_journey_visible_items($conn, $courseId);
    $allowed = [];
    foreach ($items as $item) $allowed[(string) $item['item_id']] = $item;

    if ($action === 'create') {
        $activeKey = mmh_recovery_plan_active_key($studentId, $courseId);
        $stmt = $conn->prepare('SELECT id FROM recovery_plans WHERE active_key = ? LIMIT 1');
        if ($stmt) { $stmt->bind_param('s', $activeKey); $stmt->execute(); $existing = $stmt->get_result()->fetch_assoc(); $stmt->close(); if ($existing) throw new RuntimeException('An active Recovery Plan already exists. Edit it or archive it first.'); }
        $stmt = $conn->prepare("INSERT INTO recovery_plans (user_id, course_id, title, status, active_key, created_by) VALUES (?, ?, 'Recovery Plan', 'active', ?, ?)");
        if (!$stmt) throw new RuntimeException('Unable to create Recovery Plan.');
        $stmt->bind_param('issi', $studentId, $courseId, $activeKey, $adminId);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException('Unable to create Recovery Plan: ' . $error); }
        $newId = (int) $stmt->insert_id; $stmt->close(); $flash(true, 'Recovery Plan created. Add existing course items below.', $newId);
    }

    $plan = mmh_recovery_plan_load($conn, $studentId, $courseId, $planId);
    if (!$plan) throw new InvalidArgumentException('Recovery Plan not found.');
    if ($action === 'archive') {
        $stmt = $conn->prepare("UPDATE recovery_plans SET status = 'archived', active_key = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND course_id = ?");
        if (!$stmt) throw new RuntimeException('Unable to archive Recovery Plan.');
        $stmt->bind_param('iis', $planId, $studentId, $courseId); $stmt->execute(); $stmt->close(); $flash(true, 'Recovery Plan archived.', $planId);
    }
    if ($action === 'duplicate') {
        $conn->begin_transaction();
        $title = mb_substr(trim((string) $plan['title']) . ' (Copy)', 0, 180); $status = 'draft';
        $stmt = $conn->prepare('INSERT INTO recovery_plans (user_id, course_id, title, status, created_by) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Unable to duplicate Recovery Plan.');
        $stmt->bind_param('isssi', $studentId, $courseId, $title, $status, $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to duplicate Recovery Plan.');
        $newId = (int) $stmt->insert_id; $stmt->close();
        $insert = $conn->prepare('INSERT INTO recovery_plan_items (plan_id, course_id, item_id, assignment_id, sort_order, is_required, teacher_note, estimated_duration, weight, locked_until_previous) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) throw new RuntimeException('Unable to duplicate Recovery Plan items.');
        $coverageInsert = mmh_recovery_plan_coverage_available($conn) ? $conn->prepare('INSERT INTO recovery_plan_item_coverage (plan_item_id, course_id, coverage_type, covered_item_id, covered_section_id, topic_label) VALUES (?, ?, ?, ?, ?, ?)') : null;
        foreach ($plan['items'] as $item) {
            $itemId = (string) $item['item_id']; $assignmentId = (string) ($item['assignment_id'] ?? ''); $sort = (int) $item['sort_order']; $required = (int) $item['is_required']; $note = (string) ($item['teacher_note'] ?? ''); $duration = $item['estimated_duration'] === null ? null : (int) $item['estimated_duration']; $weight = $item['weight'] === null ? null : (float) $item['weight']; $locked = (int) $item['locked_until_previous'];
            $insert->bind_param('isssiisidi', $newId, $courseId, $itemId, $assignmentId, $sort, $required, $note, $duration, $weight, $locked);
            if (!$insert->execute()) throw new RuntimeException('Unable to duplicate Recovery Plan item.');
            $newItemId = (int) $conn->insert_id;
            if ($coverageInsert) foreach (($item['coverage'] ?? []) as $coverage) {
                $type = (string) ($coverage['coverage_type'] ?? 'item'); $coveredItem = ($coverage['covered_item_id'] ?? '') !== '' ? (string) $coverage['covered_item_id'] : null; $coveredSection = ($coverage['covered_section_id'] ?? '') !== '' ? (string) $coverage['covered_section_id'] : null; $topic = ($coverage['topic_label'] ?? '') !== '' ? (string) $coverage['topic_label'] : null;
                $coverageInsert->bind_param('isssss', $newItemId, $courseId, $type, $coveredItem, $coveredSection, $topic); if (!$coverageInsert->execute()) throw new RuntimeException('Unable to duplicate Recovery Plan coverage.');
            }
        }
        $insert->close(); if ($coverageInsert) $coverageInsert->close(); $conn->commit(); $flash(true, 'Recovery Plan duplicated as a draft.', $newId);
    }
    if ($action === 'activate' || $action === 'reopen') {
        $conn->begin_transaction(); $activeKey = mmh_recovery_plan_active_key($studentId, $courseId);
        $stmt = $conn->prepare("UPDATE recovery_plans SET status = 'archived', active_key = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND course_id = ? AND status = 'active' AND id <> ?");
        if (!$stmt) throw new RuntimeException('Unable to prepare activation.');
        $stmt->bind_param('isi', $studentId, $courseId, $planId); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("UPDATE recovery_plans SET status = 'active', active_key = ?, completed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND course_id = ?");
        if (!$stmt) throw new RuntimeException('Unable to activate Recovery Plan.');
        $stmt->bind_param('siis', $activeKey, $planId, $studentId, $courseId); if (!$stmt->execute()) throw new RuntimeException('Unable to activate Recovery Plan.');
        $stmt->close(); $conn->commit(); $flash(true, 'Recovery Plan is active.', $planId);
    }
    if ($action === 'save_coverage') {
        if (!mmh_recovery_plan_coverage_available($conn)) throw new RuntimeException('Coverage migration is not available yet.');
        $coverage = $_POST['coverage'] ?? []; if (!is_array($coverage)) $coverage = [];
        $allowedSections = []; foreach ($items as $item) { $sid = trim((string) ($item['section_id'] ?? '')); if ($sid !== '') $allowedSections[$sid] = true; }
        $conn->begin_transaction();
        $deleteCoverage = $conn->prepare('DELETE FROM recovery_plan_item_coverage WHERE plan_item_id IN (SELECT id FROM recovery_plan_items WHERE plan_id = ?)'); if (!$deleteCoverage) throw new RuntimeException('Unable to replace coverage mappings.'); $deleteCoverage->bind_param('i', $planId); $deleteCoverage->execute(); $deleteCoverage->close();
        $coverageInsert = $conn->prepare('INSERT INTO recovery_plan_item_coverage (plan_item_id, course_id, coverage_type, covered_item_id, covered_section_id, topic_label) VALUES (?, ?, ?, ?, ?, ?)'); if (!$coverageInsert) throw new RuntimeException('Unable to save coverage mappings.');
        $taskIds = []; foreach ($plan['items'] as $planItem) $taskIds[(int) $planItem['id']] = true;
        foreach ($coverage as $taskIdRaw => $mapping) {
            $taskId = (int) $taskIdRaw; if (!$taskId || !isset($taskIds[$taskId]) || !is_array($mapping)) continue;
            $insertCoverage = static function($type, $itemId, $sectionId, $topic) use ($coverageInsert, $taskId, $courseId): void { $type = (string) $type; $itemId = $itemId !== '' ? (string) $itemId : null; $sectionId = $sectionId !== '' ? (string) $sectionId : null; $topic = $topic !== '' ? mb_substr((string) $topic, 0, 255) : null; $coverageInsert->bind_param('isssss', $taskId, $courseId, $type, $itemId, $sectionId, $topic); if (!$coverageInsert->execute()) throw new RuntimeException('Unable to save coverage mapping.'); };
            foreach ((array) ($mapping['items'] ?? []) as $targetItem) { $targetItem = trim((string) $targetItem); if ($targetItem !== '' && isset($allowed[$targetItem])) $insertCoverage('item', $targetItem, '', ''); }
            foreach ((array) ($mapping['sections'] ?? []) as $targetSection) { $targetSection = trim((string) $targetSection); if ($targetSection !== '' && isset($allowedSections[$targetSection])) $insertCoverage('section', '', $targetSection, ''); }
            foreach ((array) ($mapping['types'] ?? []) as $type) if (in_array($type, ['homework_requirement','recording_requirement'], true)) $insertCoverage($type, '', '', '');
            $topic = trim((string) ($mapping['topic'] ?? '')); if ($topic !== '') $insertCoverage('topic', '', '', $topic);
        }
        $coverageInsert->close(); $conn->commit(); $flash(true, 'Learning coverage mappings saved.', $planId);
    }
    if ($action !== 'save') throw new InvalidArgumentException('Unsupported Recovery Plan action.');
    if ($plan['status'] === 'archived') throw new InvalidArgumentException('Archived plans cannot be edited. Duplicate or reopen the plan first.');
    $title = mb_substr(trim((string) ($_POST['title'] ?? 'Recovery Plan')), 0, 180); if ($title === '') $title = 'Recovery Plan';
    $postedItems = $_POST['items'] ?? []; if (!is_array($postedItems)) $postedItems = [];
    $normalized = []; $seen = [];
    foreach (array_values($postedItems) as $index => $row) {
        if (!is_array($row)) continue;
        $itemId = trim((string) ($row['item_id'] ?? '')); if ($itemId === '') continue;
        if (!isset($allowed[$itemId])) throw new InvalidArgumentException('Every Recovery Plan task must be a published course item.');
        if (isset($seen[$itemId])) throw new InvalidArgumentException('A Recovery Plan cannot contain the same course item twice.');
        $seen[$itemId] = true; $item = $allowed[$itemId]; $assignmentId = mmh_learning_journey_item_assignment_id($item);
        $duration = trim((string) ($row['estimated_duration'] ?? '')); $weight = trim((string) ($row['weight'] ?? ''));
        $normalized[] = ['item_id' => $itemId, 'assignment_id' => $assignmentId, 'sort_order' => $index, 'required' => (int) (($row['required'] ?? '0') === '1'), 'note' => mb_substr(trim((string) ($row['teacher_note'] ?? '')), 0, 1000), 'duration' => $duration === '' ? null : max(0, min(1440, (int) $duration)), 'weight' => $weight === '' ? null : max(0, min(999999, (float) $weight)), 'locked' => (int) (($row['locked_until_previous'] ?? '0') === '1')];
    }
    $conn->begin_transaction();
    $stmt = $conn->prepare('UPDATE recovery_plans SET title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND course_id = ?');
    if (!$stmt) throw new RuntimeException('Unable to save Recovery Plan.');
    $stmt->bind_param('siis', $title, $planId, $studentId, $courseId); $stmt->execute(); $stmt->close();
    $delete = $conn->prepare('DELETE FROM recovery_plan_items WHERE plan_id = ?'); if (!$delete) throw new RuntimeException('Unable to replace Recovery Plan items.'); $delete->bind_param('i', $planId); $delete->execute(); $delete->close();
    $insert = $conn->prepare('INSERT INTO recovery_plan_items (plan_id, course_id, item_id, assignment_id, sort_order, is_required, teacher_note, estimated_duration, weight, locked_until_previous) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'); if (!$insert) throw new RuntimeException('Unable to save Recovery Plan items.');
    foreach ($normalized as $row) { $insert->bind_param('isssiisidi', $planId, $courseId, $row['item_id'], $row['assignment_id'], $row['sort_order'], $row['required'], $row['note'], $row['duration'], $row['weight'], $row['locked']); if (!$insert->execute()) throw new RuntimeException('Unable to save Recovery Plan item.'); }
    $insert->close(); $conn->commit(); $flash(true, 'Recovery Plan saved.', $planId);
} catch (Throwable $exception) {
    @$conn->rollback();
    $flash(false, $exception->getMessage(), $planId);
}
