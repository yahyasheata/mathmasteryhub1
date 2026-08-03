<?php
/**
 * Recovery Plans reference published course items and read completion only
 * through the canonical Learning Journey resolver. They never write LMS history.
 */
require_once __DIR__ . '/StudentLearningJourney.php';

if (!function_exists('mmh_recovery_plan_schema_available')) {
    function mmh_recovery_plan_schema_available(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) return $available;
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recovery_plans'");
        if (!$stmt) return $available = false;
        $stmt->execute();
        $available = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $available;
    }
}

if (!function_exists('mmh_recovery_plan_active_key')) {
    function mmh_recovery_plan_active_key(int $studentId, string $courseId): string
    {
        return $studentId . ':' . $courseId;
    }
}

if (!function_exists('mmh_recovery_plan_coverage_available')) {
    function mmh_recovery_plan_coverage_available(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) return $available;
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recovery_plan_item_coverage'");
        if (!$stmt) return $available = false;
        $stmt->execute();
        $available = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $available;
    }
}

if (!function_exists('mmh_recovery_plan_workspace_url')) {
    function mmh_recovery_plan_workspace_url(string $baseUrl, string $courseId, int $planId, ?int $taskId = null): string
    {
        $url = rtrim($baseUrl, '/') . '/user/course/' . rawurlencode($courseId) . '/recovery-plan/' . $planId;
        return $taskId !== null && $taskId > 0 ? $url . '?task=' . $taskId : $url;
    }
}

if (!function_exists('mmh_recovery_plan_resource_url')) {
    function mmh_recovery_plan_resource_url(string $baseUrl, string $courseId, string $itemId, int $planId, int $taskId): string
    {
        return rtrim($baseUrl, '/') . '/user/course/resource/' . rawurlencode($courseId) . '/' . rawurlencode($itemId)
            . '?recovery_plan=' . $planId . '&recovery_task=' . $taskId;
    }
}

if (!function_exists('mmh_recovery_plan_item_label')) {
    function mmh_recovery_plan_item_label(array $item): string
    {
        $kind = mmh_learning_journey_item_kind($item);
        if ($kind === 'homework') return 'Homework / Assignment';
        if ($kind === 'recording') return 'Recording / Video';
        if ($kind === 'timed_exam') return 'Timed Exam';
        $type = strtolower(trim((string) ($item['template_type'] ?? $item['item_type'] ?? '')));
        if (str_contains($type, 'pdf') || str_contains($type, 'note')) return 'Notes / PDF';
        if (str_contains($type, 'quiz')) return 'Quiz';
        return 'Lesson / Resource';
    }
}

if (!function_exists('mmh_recovery_plan_load')) {
    function mmh_recovery_plan_load(mysqli $conn, int $studentId, string $courseId, ?int $planId = null): ?array
    {
        if ($studentId <= 0 || $courseId === '' || !mmh_recovery_plan_schema_available($conn)) return null;
        if ($planId !== null && $planId > 0) {
            $stmt = $conn->prepare("SELECT id, user_id, course_id, title, status, created_by, template_id, assignment_id, template_version, created_at, updated_at, completed_at, completion_notified_at FROM recovery_plans WHERE id = ? AND user_id = ? AND course_id = ? LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('iis', $planId, $studentId, $courseId);
        } else {
            $stmt = $conn->prepare("SELECT id, user_id, course_id, title, status, created_by, template_id, assignment_id, template_version, created_at, updated_at, completed_at, completion_notified_at FROM recovery_plans WHERE user_id = ? AND course_id = ? AND status IN ('active','completed') ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, updated_at DESC, id DESC LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('is', $studentId, $courseId);
        }
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$plan) return null;
        $itemStmt = $conn->prepare("SELECT rpi.id, rpi.plan_id, rpi.course_id, rpi.item_id, rpi.assignment_id, rpi.sort_order, rpi.is_required, rpi.teacher_note, rpi.estimated_duration, rpi.weight, rpi.locked_until_previous, i.item_title, i.item_description, i.section_id, i.item_type, i.template_type, i.template_data, i.duration_minutes, s.title AS section_title, s.sort_order AS section_sort_order FROM recovery_plan_items rpi INNER JOIN course_items i ON i.course_id = rpi.course_id AND i.item_id = rpi.item_id AND (i.status IS NULL OR i.status = '' OR i.status = 'published') LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id WHERE rpi.plan_id = ? ORDER BY rpi.sort_order ASC, rpi.id ASC");
        if (!$itemStmt) return $plan + ['items' => []];
        $planIdValue = (int) $plan['id'];
        $itemStmt->bind_param('i', $planIdValue);
        $itemStmt->execute();
        $plan['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemStmt->close();
        foreach ($plan['items'] as &$planItem) {
            $planItem['coverage'] = [];
            if (mmh_recovery_plan_coverage_available($conn)) {
                $coverageStmt = $conn->prepare('SELECT id, coverage_type, covered_item_id, covered_section_id, topic_label FROM recovery_plan_item_coverage WHERE plan_item_id = ? ORDER BY id ASC');
                if ($coverageStmt) {
                    $planItemId = (int) $planItem['id'];
                    $coverageStmt->bind_param('i', $planItemId);
                    $coverageStmt->execute();
                    $planItem['coverage'] = $coverageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $coverageStmt->close();
                }
            }
        }
        unset($planItem);
        return $plan;
    }
}

if (!function_exists('mmh_recovery_plan_statuses')) {
    function mmh_recovery_plan_statuses(mysqli $conn, int $studentId, string $courseId): array
    {
        if (!mmh_recovery_plan_schema_available($conn)) return [];
        $stmt = $conn->prepare("SELECT id, title, status, updated_at, completed_at FROM recovery_plans WHERE user_id = ? AND course_id = ? ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END, updated_at DESC, id DESC");
        if (!$stmt) return [];
        $stmt->bind_param('is', $studentId, $courseId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_recovery_plan_sync')) {
    function mmh_recovery_plan_sync(mysqli $conn, array $plan, int $studentId, string $courseId): array
    {
        $journey = mmh_learning_journey_resolve($conn, $studentId, $courseId);
        $journeyByItem = [];
        foreach ($journey['items'] ?? [] as $item) $journeyByItem[(string) ($item['item_id'] ?? '')] = $item;
        $requiredTotal = 0; $requiredCompleted = 0; $total = count($plan['items'] ?? []); $completed = 0; $locked = false;
        $coveredItems = []; $coveredSections = []; $coveredTopics = [];
        foreach ($plan['items'] as &$task) {
            $item = $journeyByItem[(string) ($task['item_id'] ?? '')] ?? null;
            $task['journey_item'] = $item;
            $task['is_completed'] = $item && !empty($item['is_completed']);
            $task['completion_source'] = (string) ($item['evidence_source'] ?? '');
            $task['is_locked'] = !empty($task['locked_until_previous']) && $locked;
            if ($task['is_completed']) $completed++;
            $task['covered'] = [];
            if ($task['is_completed']) {
                foreach (($task['coverage'] ?? []) as $coverage) {
                    $task['covered'][] = $coverage;
                    if (($coverage['covered_item_id'] ?? '') !== '') $coveredItems[(string) $coverage['covered_item_id']] = true;
                    if (($coverage['covered_section_id'] ?? '') !== '') $coveredSections[(string) $coverage['covered_section_id']] = true;
                    if (($coverage['topic_label'] ?? '') !== '') $coveredTopics[(string) $coverage['topic_label']] = true;
                }
            }
            if (!empty($task['is_required'])) { $requiredTotal++; if ($task['is_completed']) $requiredCompleted++; }
            if (!$task['is_completed']) $locked = true;
        }
        unset($task);
        $plan['total'] = $total; $plan['completed'] = $completed; $plan['required_total'] = $requiredTotal; $plan['required_completed'] = $requiredCompleted;
        if (!empty($plan['assignment_id'])) {
            $assignmentStatus = ($plan['status'] === 'completed' || ($requiredTotal > 0 && $requiredCompleted === $requiredTotal)) ? 'completed' : ($completed > 0 ? 'in_progress' : 'assigned');
            $assignmentUpdate = $conn->prepare("UPDATE recovery_plan_assignments SET status = ?, started_at = CASE WHEN ? = 'in_progress' THEN COALESCE(started_at, CURRENT_TIMESTAMP) ELSE started_at END, completed_at = CASE WHEN ? = 'completed' THEN COALESCE(completed_at, CURRENT_TIMESTAMP) ELSE completed_at END, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($assignmentUpdate) { $assignmentId = (int) $plan['assignment_id']; $assignmentUpdate->bind_param('sssi', $assignmentStatus, $assignmentStatus, $assignmentStatus, $assignmentId); $assignmentUpdate->execute(); $assignmentUpdate->close(); }
        }
        $plan['covered_item_ids'] = array_keys($coveredItems); $plan['covered_section_ids'] = array_keys($coveredSections); $plan['covered_topics'] = array_keys($coveredTopics);
        $plan['coverage_total'] = count($coveredItems) + count($coveredSections) + count($coveredTopics);
        if ($plan['status'] === 'active' && $requiredTotal > 0 && $requiredCompleted === $requiredTotal) {
            $planId = (int) $plan['id'];
            $stmt = $conn->prepare("UPDATE recovery_plans SET status = 'completed', active_key = NULL, completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP), completion_notified_at = COALESCE(completion_notified_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'active' AND completion_notified_at IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $planId); $stmt->execute();
                $justCompleted = $stmt->affected_rows > 0; $stmt->close();
                if ($justCompleted) {
                    $title = 'Recovery Plan completed';
                    $message = 'Congratulations! You completed your Recovery Plan for ' . $courseId . '.';
                    $notification = $conn->prepare('INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)');
                    if ($notification) {
                        $notification->bind_param('iss', $studentId, $title, $message);
                        $notification->execute();
                        $notification->close();
                    } else {
                        $notification = $conn->prepare('INSERT INTO notifications (user_id, message, status) VALUES (?, ?, 0)');
                        if ($notification) { $notification->bind_param('is', $studentId, $message); $notification->execute(); $notification->close(); }
                    }
                }
            }
            $plan['status'] = 'completed';
        }
        return $plan;
    }
}

if (!function_exists('mmh_recovery_plan_resolve')) {
    function mmh_recovery_plan_resolve(mysqli $conn, int $studentId, string $courseId): ?array
    {
        $plan = mmh_recovery_plan_load($conn, $studentId, $courseId);
        return $plan ? mmh_recovery_plan_sync($conn, $plan, $studentId, $courseId) : null;
    }
}

if (!function_exists('mmh_recovery_plan_task_context')) {
    function mmh_recovery_plan_task_context(array $plan, int $taskId): array
    {
        $items = array_values($plan['items'] ?? []);
        $index = null;
        foreach ($items as $i => $item) {
            if ((int) ($item['id'] ?? 0) === $taskId) { $index = $i; break; }
        }
        if ($index === null) return ['current' => null, 'previous' => null, 'next' => null, 'position' => 0, 'total' => count($items), 'finish' => false];
        $current = $items[$index];
        $previous = $index > 0 ? $items[$index - 1] : null;
        $lastRequired = -1; foreach ($items as $i => $candidate) if (!empty($candidate['is_required'])) $lastRequired = $i;
        $next = ($lastRequired >= 0 && $index >= $lastRequired) ? null : ($index < count($items) - 1 ? $items[$index + 1] : null);
        return ['current' => $current, 'previous' => $previous, 'next' => $next, 'position' => $index + 1, 'total' => count($items), 'finish' => $next === null && !empty($current['is_required'])];
    }
}

if (!function_exists('mmh_recovery_plan_covers_item')) {
    function mmh_recovery_plan_covers_item(?array $plan, string $itemId, string $sectionId = ''): bool
    {
        if (!$plan) return false;
        if (in_array($itemId, array_map('strval', $plan['covered_item_ids'] ?? []), true)) return true;
        return $sectionId !== '' && in_array($sectionId, array_map('strval', $plan['covered_section_ids'] ?? []), true);
    }
}
