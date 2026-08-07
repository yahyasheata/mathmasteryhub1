<?php
/**
 * Canonical authenticated course-item boundary.
 *
 * This gateway owns the common identity, publication, enrollment, section,
 * Recovery Plan, resolver, and navigation context needed by every student
 * course-item viewer. The specialized viewers remain adapters: they receive
 * this context and keep their existing submission/timer/rendering behavior.
 */
require_once __DIR__ . '/StudentCourseAccess.php';
require_once __DIR__ . '/CourseResourceResolver.php';
require_once __DIR__ . '/CourseResourceNavigation.php';
require_once __DIR__ . '/RecoveryPlan.php';
require_once __DIR__ . '/TimedExam.php';

if (!function_exists('mmh_student_resource_url')) {
    /** Build the one canonical authenticated course-item URL. */
    function mmh_student_resource_url($baseUrl, $courseId, $itemId, array $context = []): string
    {
        $base = rtrim((string) $baseUrl, '/');
        $course = student_course_access_identifier($courseId, 40);
        $item = student_course_access_identifier($itemId, 40);
        if ($course === null || $item === null) {
            return $base . '/user/my-courses';
        }

        $query = [];
        $planId = (int) ($context['recovery_plan_id'] ?? $context['plan_id'] ?? 0);
        $taskId = (int) ($context['recovery_task_id'] ?? $context['task_id'] ?? 0);
        if ($planId > 0 && $taskId > 0) {
            $query['recovery_plan'] = $planId;
            $query['recovery_task'] = $taskId;
        }
        $part = strtolower(trim((string) ($context['homework_part'] ?? $context['part'] ?? '')));
        if (in_array($part, ['homework', 'model-answer'], true)) {
            $query['part'] = $part;
        }

        $url = $base . '/user/course/resource/' . rawurlencode($course) . '/' . rawurlencode($item);
        return $query ? $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
    }
}

if (!function_exists('mmh_student_resource_denial')) {
    function mmh_student_resource_denial(int $status, string $reason, ?int $studentId = null, ?array $course = null): array
    {
        return [
            'authorized' => false,
            'status' => $status,
            'reason' => $reason,
            'student_id' => $studentId,
            'course' => $course,
        ];
    }
}

if (!function_exists('mmh_student_resource_adapter')) {
    /** Resolve the viewer adapter without allowing views to infer it again. */
    function mmh_student_resource_adapter(array $resource): string
    {
        return match ((string) ($resource['action'] ?? 'unavailable')) {
            'embed' => 'course_resource_viewer',
            'homework' => 'homework',
            'timed_exam' => 'timed_exam',
            'render' => 'course_content',
            'redirect' => 'external_redirect',
            default => 'unavailable',
        };
    }
}

if (!function_exists('mmh_student_resource_active_student')) {
    function mmh_student_resource_active_student(mysqli $conn, int $studentId): bool
    {
        if ($studentId <= 0) return false;
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'user' AND status = '1' AND archived_at IS NULL LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_student_resource_recovery_context')) {
    /** Build the complete, validated Recovery Plan context once at the boundary. */
    function mmh_student_resource_recovery_context(mysqli $conn, int $studentId, array $course, int $planId, int $taskId, string $itemId): array
    {
        if ($planId <= 0 || $taskId <= 0 || $itemId === '') return ['valid' => false];
        $plan = mmh_recovery_plan_load($conn, $studentId, (string) $course['course_id'], $planId);
        if (!$plan || !in_array((string) ($plan['status'] ?? ''), ['active', 'completed'], true)) return ['valid' => false];
        $plan = mmh_recovery_plan_sync($conn, $plan, $studentId, (string) $course['course_id']);
        $task = null;
        foreach (($plan['items'] ?? []) as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $taskId && (string) ($candidate['item_id'] ?? '') === $itemId) {
                $task = $candidate;
                break;
            }
        }
        if (!$task || (!empty($task['is_locked']) && empty($task['is_completed']))) return ['valid' => false];
        return [
            'valid' => true,
            'plan' => $plan,
            'task' => $task,
            'ordered_tasks' => array_values($plan['items'] ?? []),
            'navigation' => mmh_recovery_plan_task_context($plan, $taskId),
        ];
    }
}

if (!function_exists('mmh_student_resource_gateway')) {
    /**
     * Resolve an authenticated student course item into one typed context.
     * No viewer should repeat this authorization or navigation work.
     */
    function mmh_student_resource_gateway(mysqli $conn, int $studentId, $courseId, $itemId, array $options = []): array
    {
        if (!mmh_student_resource_active_student($conn, $studentId)) {
            return mmh_student_resource_denial(403, 'This student account is unavailable.', $studentId);
        }

        $courseKey = student_course_access_identifier($courseId, 40);
        $itemKey = student_course_access_identifier($itemId, 40);
        if ($courseKey === null || $itemKey === null) {
            return mmh_student_resource_denial(404, 'This learning resource could not be found.', $studentId);
        }

        $course = student_course_access_course($conn, $courseKey);
        if (!$course) {
            return mmh_student_resource_denial(404, 'This course is unavailable.', $studentId);
        }
        $canonicalCourseId = (string) ($course['course_id'] ?? '');
        if (!student_course_access_enrolled($conn, $studentId, $canonicalCourseId)) {
            return mmh_student_resource_denial(403, 'You are not enrolled in this course.', $studentId, $course);
        }

        $item = student_course_access_item($conn, $canonicalCourseId, $itemKey);
        if (!$item) {
            return mmh_student_resource_denial(404, 'This learning resource is no longer available.', $studentId, $course);
        }
        $sectionId = student_course_access_normalize_section_id($item['section_id'] ?? '');
        if ($sectionId === null) {
            return mmh_student_resource_denial(404, 'This learning resource is unavailable.', $studentId, $course);
        }
        $selection = student_course_access_selected_item($conn, $course, $itemKey, $sectionId, $studentId);
        if (!$selection) {
            return mmh_student_resource_denial(403, 'This resource is not available yet.', $studentId, $course);
        }

        $requestedPlanId = (int) ($options['recovery_plan_id'] ?? $options['plan_id'] ?? 0);
        $requestedTaskId = (int) ($options['recovery_task_id'] ?? $options['task_id'] ?? 0);
        if (($requestedPlanId > 0) !== ($requestedTaskId > 0)) {
            return mmh_student_resource_denial(403, 'The Recovery Plan context is incomplete.', $studentId, $course);
        }
        $recoveryContext = [];
        if ($requestedPlanId > 0 && $requestedTaskId > 0) {
            $recoveryContext = mmh_student_resource_recovery_context($conn, $studentId, $course, $requestedPlanId, $requestedTaskId, $itemKey);
            if (empty($recoveryContext['valid'])) {
                return mmh_student_resource_denial(403, 'This Recovery Plan task is not available for your account.', $studentId, $course);
            }
        }

        $resource = mmh_course_resource_resolve($selection['item']);
        $navigation = course_resource_navigation($conn, $course, $studentId, $itemKey, $recoveryContext);
        $navigation['mode'] = !empty($recoveryContext['valid']) ? 'recovery' : 'course';
        $navigation['current'] = $item;
        $navigation['context'] = !empty($recoveryContext['valid']) ? 'recovery_plan' : 'normal_course';
        $courseState = mmh_course_state($course);

        $assignmentId = mmh_course_assignment_id($item);
        $journeyKind = mmh_learning_journey_item_kind($item);
        $completion = [
            'kind' => $journeyKind,
            'item_id' => $itemKey,
            'assignment_id' => $assignmentId,
            'entity_key' => mmh_learning_journey_entity_key($journeyKind, $itemKey, $assignmentId),
        ];
        $baseUrl = (string) ($options['base_url'] ?? '');
        $returnUrl = $baseUrl !== '' && !empty($recoveryContext['valid'])
            ? mmh_recovery_plan_workspace_url($baseUrl, $canonicalCourseId, (int) $recoveryContext['plan']['id'], (int) ($recoveryContext['task']['id'] ?? 0))
            : ($baseUrl !== '' ? student_course_access_course_url($baseUrl, $canonicalCourseId, $itemKey, true) : '');
        $homeworkPart = strtolower(trim((string) ($options['homework_part'] ?? $options['part'] ?? '')));
        if ($homeworkPart !== '' && !in_array($homeworkPart, ['homework', 'model-answer'], true)) {
            return mmh_student_resource_denial(404, 'This Homework resource could not be found.', $studentId, $course);
        }

        $timedExam = null;
        if (($resource['action'] ?? '') === 'timed_exam' || strtolower((string) ($item['template_type'] ?? '')) === 'timed_exam') {
            $timedExam = mmh_timed_exam_load_for_item($conn, $canonicalCourseId, $itemKey, false);
        }

        return [
            'authorized' => true,
            'status' => 200,
            'reason' => '',
            'student_id' => $studentId,
            'course' => $course,
            'course_state' => $courseState,
            'access' => [
                'authenticated_student' => true,
                'enrolled' => true,
                'course_state' => $courseState,
                'section_available' => true,
                'item_available' => true,
            ],
            'item' => $item,
            'section' => $selection['section_state']['section'] ?? null,
            'selection' => $selection,
            'resource' => $resource,
            'provider' => (string) ($resource['embed_kind'] ?? $resource['type'] ?? $resource['action'] ?? ''),
            'resource_kind' => mmh_student_resource_adapter($resource),
            'viewer_adapter' => mmh_student_resource_adapter($resource),
            'state' => [
                'course' => $courseState,
                'section' => 'available',
                'item' => 'published',
                'locked' => false,
            ],
            'navigation' => $navigation,
            'previous' => $navigation['previous'] ?? null,
            'next' => $navigation['next'] ?? null,
            'return_url' => $returnUrl,
            'completion' => $completion,
            'learning_journey' => ['entity_key' => $completion['entity_key'], 'kind' => $journeyKind],
            'recovery_context' => $recoveryContext,
            'homework_part' => $homeworkPart !== '' ? $homeworkPart : null,
            'timed_exam' => $timedExam,
        ];
    }
}
