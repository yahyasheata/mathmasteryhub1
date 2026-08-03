<?php
/** Reusable Recovery Plan templates and isolated student assignments. */
require_once __DIR__ . '/RecoveryPlan.php';
require_once __DIR__ . '/StudentCourseAccess.php';

if (!function_exists('mmh_recovery_template_schema_available')) {
    function mmh_recovery_template_schema_available(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) return $available;
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recovery_plan_templates'");
        if (!$stmt) return $available = false;
        $stmt->execute();
        $available = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $available;
    }
}

if (!function_exists('mmh_recovery_template_list')) {
    function mmh_recovery_template_list(mysqli $conn, string $courseId = '', bool $includeArchived = false): array
    {
        if (!mmh_recovery_template_schema_available($conn)) return [];
        $sql = 'SELECT t.id, t.course_id, t.title, t.description, t.status, t.created_by, t.created_at, t.updated_at, c.course_title, COUNT(DISTINCT ti.id) AS task_count, COUNT(DISTINCT a.id) AS assigned_count, SUM(a.status = \'assigned\') AS not_started_count, SUM(a.status = \'in_progress\') AS in_progress_count, SUM(a.status = \'completed\') AS completed_count, AVG(CASE WHEN a.completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, a.assigned_at, a.completed_at) END) AS average_completion_minutes FROM recovery_plan_templates t LEFT JOIN courses c ON c.course_id = t.course_id LEFT JOIN recovery_plan_template_items ti ON ti.template_id = t.id LEFT JOIN recovery_plan_assignments a ON a.template_id = t.id';
        $params = [];
        $types = '';
        $where = [];
        if ($courseId !== '') { $where[] = 't.course_id = ?'; $params[] = $courseId; $types .= 's'; }
        if (!$includeArchived) $where[] = "t.status <> 'archived'";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY t.id ORDER BY t.updated_at DESC, t.id DESC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($courseId !== '') {
            $stmt->bind_param('s', $courseId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_recovery_template_load')) {
    function mmh_recovery_template_load(mysqli $conn, int $templateId): ?array
    {
        if ($templateId <= 0 || !mmh_recovery_template_schema_available($conn)) return null;
        $stmt = $conn->prepare('SELECT id, course_id, title, description, status, created_by, created_at, updated_at FROM recovery_plan_templates WHERE id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $templateId); $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
        if (!$template) return null;
        $items = $conn->prepare('SELECT i.id, i.template_id, i.course_id, i.item_id, i.assignment_id, i.sort_order, i.is_required, i.teacher_note, i.estimated_duration, i.weight, i.locked_until_previous, ci.item_title, ci.item_description, ci.section_id, ci.item_type, ci.template_type, s.title AS section_title FROM recovery_plan_template_items i INNER JOIN course_items ci ON ci.course_id = i.course_id AND ci.item_id = i.item_id AND (ci.status IS NULL OR ci.status = \'\' OR ci.status = \'published\') LEFT JOIN course_sections s ON s.course_id = ci.course_id AND s.section_id = ci.section_id WHERE i.template_id = ? ORDER BY i.sort_order ASC, i.id ASC');
        $template['items'] = [];
        if ($items) { $items->bind_param('i', $templateId); $items->execute(); $template['items'] = $items->get_result()->fetch_all(MYSQLI_ASSOC); $items->close(); }
        foreach ($template['items'] as &$item) {
            $item['coverage'] = [];
            $coverage = $conn->prepare('SELECT coverage_type, covered_item_id, covered_section_id, topic_label FROM recovery_plan_template_coverage WHERE template_item_id = ? ORDER BY id ASC');
            if ($coverage) { $itemId = (int) $item['id']; $coverage->bind_param('i', $itemId); $coverage->execute(); $item['coverage'] = $coverage->get_result()->fetch_all(MYSQLI_ASSOC); $coverage->close(); }
        }
        unset($item);
        return $template;
    }
}

if (!function_exists('mmh_recovery_template_students')) {
    function mmh_recovery_template_students(mysqli $conn, string $courseId, string $search = ''): array
    {
        $sql = 'SELECT DISTINCT u.user_id, u.full_name, u.username FROM users u INNER JOIN course_logs l ON l.user_id = u.user_id AND l.course_id = ? WHERE u.role = \'user\'';
        $params = [$courseId]; $types = 's';
        if ($search !== '') { $sql .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)'; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; $types .= 'ss'; }
        $sql .= ' ORDER BY u.full_name ASC, u.username ASC';
        $stmt = $conn->prepare($sql); if (!$stmt) return [];
        if ($search !== '') {
            $stmt->bind_param('sss', $courseId, $like, $like);
        } else {
            $stmt->bind_param('s', $courseId);
        }
        $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }
}

if (!function_exists('mmh_recovery_template_normalize_items')) {
    function mmh_recovery_template_normalize_items(mysqli $conn, string $courseId, array $postedItems): array
    {
        $allowed = [];
        foreach (mmh_learning_journey_visible_items($conn, $courseId) as $item) $allowed[(string) $item['item_id']] = $item;
        $normalized = []; $seen = [];
        foreach (array_values($postedItems) as $index => $row) {
            if (!is_array($row)) continue;
            $itemId = trim((string) ($row['item_id'] ?? ''));
            if ($itemId === '') continue;
            if (!isset($allowed[$itemId])) throw new InvalidArgumentException('Every template task must be a published course item.');
            if (isset($seen[$itemId])) throw new InvalidArgumentException('A template cannot contain the same course item twice.');
            $seen[$itemId] = true;
            $duration = trim((string) ($row['estimated_duration'] ?? '')); $weight = trim((string) ($row['weight'] ?? ''));
            $normalized[] = ['item_id' => $itemId, 'assignment_id' => mmh_learning_journey_item_assignment_id($allowed[$itemId]), 'sort_order' => $index, 'required' => (int) (($row['required'] ?? '0') === '1'), 'note' => mb_substr(trim((string) ($row['teacher_note'] ?? '')), 0, 1000), 'duration' => $duration === '' ? null : max(0, min(1440, (int) $duration)), 'weight' => $weight === '' ? null : max(0, min(999999, (float) $weight)), 'locked' => (int) (($row['locked_until_previous'] ?? '0') === '1')];
        }
        return $normalized;
    }
}

if (!function_exists('mmh_recovery_template_insert_items')) {
    function mmh_recovery_template_insert_items(mysqli $conn, int $templateId, string $courseId, array $items, array $coverageByIndex = []): void
    {
        $insert = $conn->prepare('INSERT INTO recovery_plan_template_items (template_id, course_id, item_id, assignment_id, sort_order, is_required, teacher_note, estimated_duration, weight, locked_until_previous) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) throw new RuntimeException('Unable to save template tasks.');
        $coverageInsert = $conn->prepare('INSERT INTO recovery_plan_template_coverage (template_item_id, course_id, coverage_type, covered_item_id, covered_section_id, topic_label) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($items as $index => $row) {
            $insert->bind_param('isssiisidi', $templateId, $courseId, $row['item_id'], $row['assignment_id'], $row['sort_order'], $row['required'], $row['note'], $row['duration'], $row['weight'], $row['locked']);
            if (!$insert->execute()) throw new RuntimeException('Unable to save template task.');
            $templateItemId = (int) $conn->insert_id;
            $rowCoverage = $coverageByIndex[$index] ?? ($row['coverage'] ?? []);
            foreach ((array) $rowCoverage as $coverage) {
                if (!$coverageInsert) continue;
                $type = (string) ($coverage['coverage_type'] ?? 'item'); $coveredItem = ($coverage['covered_item_id'] ?? '') !== '' ? (string) $coverage['covered_item_id'] : null; $coveredSection = ($coverage['covered_section_id'] ?? '') !== '' ? (string) $coverage['covered_section_id'] : null; $topic = ($coverage['topic_label'] ?? '') !== '' ? (string) $coverage['topic_label'] : null;
                $coverageInsert->bind_param('isssss', $templateItemId, $courseId, $type, $coveredItem, $coveredSection, $topic); $coverageInsert->execute();
            }
        }
        $insert->close(); if ($coverageInsert) $coverageInsert->close();
    }
}

if (!function_exists('mmh_recovery_template_copy_to_student')) {
    function mmh_recovery_template_copy_to_student(mysqli $conn, array $template, int $studentId, int $adminId): int
    {
        $courseId = (string) $template['course_id'];
        if (empty($template['items'])) throw new InvalidArgumentException('Add at least one course item before assigning this template.');
        if (!student_course_access_enrolled($conn, $studentId, $courseId)) throw new InvalidArgumentException('Student is not enrolled in this course.');
        $activeKey = mmh_recovery_plan_active_key($studentId, $courseId);
        $check = $conn->prepare('SELECT id FROM recovery_plans WHERE active_key = ? LIMIT 1');
        if ($check) { $check->bind_param('s', $activeKey); $check->execute(); $existing = $check->get_result()->fetch_assoc(); $check->close(); if ($existing) throw new RuntimeException('Student already has an active Recovery Plan.'); }
        $conn->begin_transaction();
        $status = 'active'; $title = mb_substr((string) $template['title'], 0, 180); $templateId = (int) $template['id'];
        $plan = $conn->prepare('INSERT INTO recovery_plans (user_id, course_id, title, status, active_key, created_by, template_id, template_version) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        if (!$plan) throw new RuntimeException('Unable to create assigned Recovery Plan.');
        $plan->bind_param('issssii', $studentId, $courseId, $title, $status, $activeKey, $adminId, $templateId); if (!$plan->execute()) throw new RuntimeException('Unable to create assigned Recovery Plan.');
        $planId = (int) $plan->insert_id; $plan->close();
        mmh_recovery_template_insert_plan_items($conn, $planId, $courseId, $template['items'] ?? []);
        $assignmentStatus = 'assigned';
        $assignment = $conn->prepare('INSERT INTO recovery_plan_assignments (template_id, plan_id, student_id, course_id, status, assigned_by) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$assignment) throw new RuntimeException('Unable to create Recovery Plan assignment.');
        $assignment->bind_param('iiissi', $templateId, $planId, $studentId, $courseId, $assignmentStatus, $adminId); if (!$assignment->execute()) throw new RuntimeException('Unable to create Recovery Plan assignment.');
        $assignmentId = (int) $assignment->insert_id; $assignment->close();
        $update = $conn->prepare('UPDATE recovery_plans SET assignment_id = ? WHERE id = ?'); $update->bind_param('ii', $assignmentId, $planId); $update->execute(); $update->close();
        $conn->commit();
        return $planId;
    }
}

if (!function_exists('mmh_recovery_template_insert_plan_items')) {
    function mmh_recovery_template_insert_plan_items(mysqli $conn, int $planId, string $courseId, array $items): void
    {
        $insert = $conn->prepare('INSERT INTO recovery_plan_items (plan_id, course_id, item_id, assignment_id, sort_order, is_required, teacher_note, estimated_duration, weight, locked_until_previous) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) throw new RuntimeException('Unable to save assigned Recovery Plan tasks.');
        $coverageInsert = mmh_recovery_plan_coverage_available($conn) ? $conn->prepare('INSERT INTO recovery_plan_item_coverage (plan_item_id, course_id, coverage_type, covered_item_id, covered_section_id, topic_label) VALUES (?, ?, ?, ?, ?, ?)') : null;
        foreach ($items as $row) {
            $insert->bind_param('isssiisidi', $planId, $courseId, $row['item_id'], $row['assignment_id'], $row['sort_order'], $row['required'], $row['note'], $row['duration'], $row['weight'], $row['locked']); if (!$insert->execute()) throw new RuntimeException('Unable to save assigned Recovery Plan task.');
            $planItemId = (int) $conn->insert_id;
            foreach (($row['coverage'] ?? []) as $coverage) {
                if (!$coverageInsert) continue;
                $type = (string) ($coverage['coverage_type'] ?? 'item'); $coveredItem = ($coverage['covered_item_id'] ?? '') !== '' ? (string) $coverage['covered_item_id'] : null; $coveredSection = ($coverage['covered_section_id'] ?? '') !== '' ? (string) $coverage['covered_section_id'] : null; $topic = ($coverage['topic_label'] ?? '') !== '' ? (string) $coverage['topic_label'] : null;
                $coverageInsert->bind_param('isssss', $planItemId, $courseId, $type, $coveredItem, $coveredSection, $topic); $coverageInsert->execute();
            }
        }
        $insert->close(); if ($coverageInsert) $coverageInsert->close();
    }
}

if (!function_exists('mmh_recovery_template_sync_assigned_plan')) {
    /** Apply template metadata to an existing plan without deleting completed work. */
    function mmh_recovery_template_sync_assigned_plan(mysqli $conn, array $template, int $planId): void
    {
        $plan = mmh_recovery_plan_load($conn, (int) ($template['student_id'] ?? 0), (string) $template['course_id'], $planId);
        if (!$plan) return;
        $existing = [];
        foreach (($plan['items'] ?? []) as $item) $existing[(string) $item['item_id']] = $item;
        $templateItemIds = [];
        foreach (($template['items'] ?? []) as $row) $templateItemIds[(string) $row['item_id']] = true;
        $removeCoverage = mmh_recovery_plan_coverage_available($conn) ? $conn->prepare('DELETE FROM recovery_plan_item_coverage WHERE plan_item_id = ?') : null;
        $removeItem = $conn->prepare('DELETE FROM recovery_plan_items WHERE id = ? AND plan_id = ?');
        if (!$removeItem) throw new RuntimeException('Unable to update assigned Recovery Plan.');
        foreach (($plan['items'] ?? []) as $oldItem) {
            $oldItemId = (string) ($oldItem['item_id'] ?? '');
            if ($oldItemId !== '' && !isset($templateItemIds[$oldItemId]) && empty($oldItem['is_completed'])) {
                $oldPlanItemId = (int) ($oldItem['id'] ?? 0);
                if ($removeCoverage) { $removeCoverage->bind_param('i', $oldPlanItemId); $removeCoverage->execute(); }
                $removeItem->bind_param('ii', $oldPlanItemId, $planId); $removeItem->execute();
            }
        }
        if ($removeCoverage) $removeCoverage->close();
        $removeItem->close();
        $update = $conn->prepare('UPDATE recovery_plan_items SET assignment_id = ?, sort_order = ?, is_required = ?, teacher_note = ?, estimated_duration = ?, weight = ?, locked_until_previous = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $insert = $conn->prepare('INSERT INTO recovery_plan_items (plan_id, course_id, item_id, assignment_id, sort_order, is_required, teacher_note, estimated_duration, weight, locked_until_previous) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$update || !$insert) throw new RuntimeException('Unable to update assigned Recovery Plan.');
        foreach (($template['items'] ?? []) as $row) {
            $itemId = (string) $row['item_id'];
            if (isset($existing[$itemId])) {
                $planItemId = (int) $existing[$itemId]['id'];
                $update->bind_param('siisidii', $row['assignment_id'], $row['sort_order'], $row['required'], $row['note'], $row['duration'], $row['weight'], $row['locked'], $planItemId);
                $update->execute();
                if (mmh_recovery_plan_coverage_available($conn)) {
                    $removeCoverage = $conn->prepare('DELETE FROM recovery_plan_item_coverage WHERE plan_item_id = ?');
                    if ($removeCoverage) { $removeCoverage->bind_param('i', $planItemId); $removeCoverage->execute(); $removeCoverage->close(); }
                    $coverageInsert = $conn->prepare('INSERT INTO recovery_plan_item_coverage (plan_item_id, course_id, coverage_type, covered_item_id, covered_section_id, topic_label) VALUES (?, ?, ?, ?, ?, ?)');
                    foreach (($row['coverage'] ?? []) as $coverage) {
                        if (!$coverageInsert) continue;
                        $type = (string) ($coverage['coverage_type'] ?? 'item'); $coveredItem = ($coverage['covered_item_id'] ?? '') !== '' ? (string) $coverage['covered_item_id'] : null; $coveredSection = ($coverage['covered_section_id'] ?? '') !== '' ? (string) $coverage['covered_section_id'] : null; $topic = ($coverage['topic_label'] ?? '') !== '' ? (string) $coverage['topic_label'] : null;
                        $coverageInsert->bind_param('isssss', $planItemId, $template['course_id'], $type, $coveredItem, $coveredSection, $topic); $coverageInsert->execute();
                    }
                    if ($coverageInsert) $coverageInsert->close();
                }
                continue;
            }
            $updatePlanId = $planId;
            $insert->bind_param('isssiisidi', $updatePlanId, $template['course_id'], $row['item_id'], $row['assignment_id'], $row['sort_order'], $row['required'], $row['note'], $row['duration'], $row['weight'], $row['locked']);
            $insert->execute();
            $planItemId = (int) $conn->insert_id;
            if (mmh_recovery_plan_coverage_available($conn)) {
                $coverageInsert = $conn->prepare('INSERT INTO recovery_plan_item_coverage (plan_item_id, course_id, coverage_type, covered_item_id, covered_section_id, topic_label) VALUES (?, ?, ?, ?, ?, ?)');
                foreach (($row['coverage'] ?? []) as $coverage) {
                    if (!$coverageInsert) continue;
                    $type = (string) ($coverage['coverage_type'] ?? 'item'); $coveredItem = ($coverage['covered_item_id'] ?? '') !== '' ? (string) $coverage['covered_item_id'] : null; $coveredSection = ($coverage['covered_section_id'] ?? '') !== '' ? (string) $coverage['covered_section_id'] : null; $topic = ($coverage['topic_label'] ?? '') !== '' ? (string) $coverage['topic_label'] : null;
                    $coverageInsert->bind_param('isssss', $planItemId, $template['course_id'], $type, $coveredItem, $coveredSection, $topic); $coverageInsert->execute();
                }
                if ($coverageInsert) $coverageInsert->close();
            }
        }
        $update->close(); $insert->close();
    }
}
