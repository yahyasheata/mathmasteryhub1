<?php
/**
 * Resolve Previous/Next for either an explicit Recovery Plan context or the
 * normal enrolled course sequence. Recovery navigation is deliberately kept
 * separate from the main-course fallback.
 */
if (!function_exists('course_resource_navigation')) {
    function course_resource_navigation(mysqli $conn, array $course, $userId, $itemId, array $planContext = []): array
    {
        if (!empty($planContext['valid']) && !empty($planContext['plan']['items'])) {
            $items = array_values($planContext['ordered_tasks'] ?? $planContext['plan']['items']);
            $current = null;
            foreach ($items as $index => $candidate) {
                if ((string) ($candidate['item_id'] ?? '') === (string) $itemId) {
                    $current = $index;
                    break;
                }
            }
            $previous = null;
            $next = null;
            if ($current !== null) {
                for ($index = $current - 1; $index >= 0; $index--) {
                    if (empty($items[$index]['is_locked']) || !empty($items[$index]['is_completed'])) {
                        $previous = $items[$index];
                        break;
                    }
                }
                for ($index = $current + 1; $index < count($items); $index++) {
                    if (empty($items[$index]['is_locked']) || !empty($items[$index]['is_completed'])) {
                        $next = $items[$index];
                        break;
                    }
                }
            }
            return [
                'previous' => $previous,
                'next' => $next,
                'current' => $current !== null ? $items[$current] : null,
                'position' => $current !== null ? $current + 1 : 0,
                'total' => count($items),
                'plan' => true,
                'mode' => 'recovery',
            ];
        }

        $items = student_course_access_ordered_items($conn, $course, $userId);
        $current = null;
        foreach ($items as $index => $candidate) {
            if ((string) ($candidate['item_id'] ?? '') === (string) $itemId) {
                $current = $index;
                break;
            }
        }
        return [
            'previous' => $current !== null && $current > 0 ? $items[$current - 1] : null,
            'next' => $current !== null && $current < count($items) - 1 ? $items[$current + 1] : null,
            'current' => $current !== null ? $items[$current] : null,
            'position' => $current !== null ? $current + 1 : 0,
            'total' => count($items),
            'mode' => 'course',
        ];
    }
}
