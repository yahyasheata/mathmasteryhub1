<?php
/**
 * Centralized Student Analytics calculation layer (Phase B3A).
 *
 * This service deliberately contains data access and transparent calculations
 * only. It does not render UI, mutate learning data, or change any existing
 * Course Builder / student-renderer behavior.
 */

require_once __DIR__ . '/AcademicMetadata.php';
require_once __DIR__ . '/LearningEvents.php';
require_once __DIR__ . '/AssignmentProgress.php';

if (!function_exists('mmh_analytics_config')) {
    function mmh_analytics_config(array $overrides = [])
    {
        $defaults = [
            // Academic classification and evidence thresholds.
            'strong_percent' => 80.0,
            'developing_percent' => 60.0,
            'minimum_valid_scores' => 3,
            'confidence_high_min' => 5,
            'confidence_medium_min' => 3,
            'confidence_low_min' => 1,
            'trend_change_percent' => 5.0,
            // Dashboard consumers that do not render recommendations can
            // explicitly avoid their additional candidate queries.
            'include_recommendation_candidates' => true,

            // A missing/invalid weight is 1. Very large values are capped so
            // one assignment cannot dominate the weighted course result.
            'default_weight' => 1.0,
            'maximum_effective_weight' => 3.0,
        ];

        return array_merge($defaults, $overrides);
    }
}

if (!function_exists('mmh_analytics_safe_ratio')) {
    function mmh_analytics_safe_ratio($numerator, $denominator)
    {
        $denominator = (float) $denominator;
        if ($denominator <= 0) {
            return null;
        }
        return ((float) $numerator / $denominator) * 100;
    }
}

if (!function_exists('mmh_analytics_round')) {
    function mmh_analytics_round($value)
    {
        return $value === null ? null : round((float) $value, 2);
    }
}

if (!function_exists('mmh_analytics_score_mode_from_assignment')) {
    function mmh_analytics_score_mode_from_assignment(array $assignment)
    {
        return mmh_academic_score_mode_from_flags(
            $assignment['allow_self_score'] ?? 0,
            $assignment['require_teacher_verification'] ?? 1
        );
    }
}

/**
 * Selects the only score eligible for performance calculations. A submitted
 * homework can still be counted even when this returns valid=false.
 */
if (!function_exists('mmh_analytics_valid_score')) {
    function mmh_analytics_valid_score(array $assignment, ?array $submission = null)
    {
        $base = [
            'valid' => false,
            'raw_score' => null,
            'maximum_score' => null,
            'percent' => null,
            'source' => null,
            'reason' => 'No submission is available.',
        ];

        if (!$submission) {
            return $base;
        }

        $maximum = $assignment['max_score'] ?? null;
        if (!is_numeric($maximum) || (float) $maximum <= 0) {
            $base['reason'] = 'This homework has no valid maximum score.';
            return $base;
        }

        $status = strtolower(trim((string) ($submission['self_score_status'] ?? '')));
        if (in_array($status, ['pending_verification', 'pending'], true)) {
            $base['reason'] = 'The self-score is pending teacher verification.';
            return $base;
        }
        if ($status === 'rejected') {
            $base['reason'] = 'The self-score was rejected.';
            return $base;
        }

        $mode = mmh_analytics_score_mode_from_assignment($assignment);
        $raw = null;
        $source = null;

        // These statuses are set by the existing teacher verification flow.
        if (in_array($status, ['verified', 'corrected_by_teacher'], true) && is_numeric($submission['grade'] ?? null)) {
            $raw = (float) $submission['grade'];
            $source = 'teacher_verified_final_score';
        } elseif ($status === 'auto_accepted' && $mode === 'accept_automatically') {
            if (is_numeric($submission['grade'] ?? null)) {
                $raw = (float) $submission['grade'];
            } elseif (is_numeric($submission['self_score'] ?? null)) {
                $raw = (float) $submission['self_score'];
            }
            $source = $raw === null ? null : 'automatically_accepted_self_score';
        // Legacy submissions predate self_score_status. A numeric grade is
        // the existing teacher-entered final grade and remains valid.
        } elseif (($status === '' || $status === 'not_required') && is_numeric($submission['grade'] ?? null)) {
            $raw = (float) $submission['grade'];
            $source = 'legacy_teacher_final_score';
        }

        if ($raw === null) {
            $base['reason'] = 'No verified or automatically accepted score is available.';
            return $base;
        }

        $maximum = (float) $maximum;
        if ($raw < 0 || $raw > $maximum) {
            $base['reason'] = 'The final score is outside the valid score range.';
            return $base;
        }

        return [
            'valid' => true,
            'raw_score' => mmh_analytics_round($raw),
            'maximum_score' => mmh_analytics_round($maximum),
            'percent' => mmh_analytics_round(($raw / $maximum) * 100),
            'source' => $source,
            'reason' => null,
        ];
    }
}

if (!function_exists('mmh_analytics_effective_weight')) {
    function mmh_analytics_effective_weight(array $assignment, array $config = [])
    {
        $config = mmh_analytics_config($config);
        $weight = is_numeric($assignment['weight'] ?? null) ? (float) $assignment['weight'] : 0.0;
        if ($weight <= 0) {
            $weight = (float) $config['default_weight'];
        }
        return min($weight, (float) $config['maximum_effective_weight']);
    }
}

if (!function_exists('mmh_analytics_confidence')) {
    function mmh_analytics_confidence($validScoreCount, array $config = [])
    {
        $config = mmh_analytics_config($config);
        $count = (int) $validScoreCount;
        if ($count >= (int) $config['confidence_high_min']) {
            return ['level' => 'high', 'reason' => $count . ' valid scored homeworks'];
        }
        if ($count >= (int) $config['confidence_medium_min']) {
            return ['level' => 'medium', 'reason' => $count . ' valid scored homeworks'];
        }
        if ($count >= (int) $config['confidence_low_min']) {
            return ['level' => 'low', 'reason' => $count . ' valid scored homework' . ($count === 1 ? '' : 's')];
        }
        return ['level' => 'none', 'reason' => 'No valid scored homework'];
    }
}

if (!function_exists('mmh_analytics_topic_classification')) {
    function mmh_analytics_topic_classification($average, $validScoreCount, array $config = [])
    {
        $config = mmh_analytics_config($config);
        $count = (int) $validScoreCount;
        if ($count < (int) $config['minimum_valid_scores'] || $average === null) {
            return [
                'classification' => 'insufficient_data',
                'reason' => 'Needs at least ' . (int) $config['minimum_valid_scores'] . ' valid scored homeworks; ' . $count . ' available.',
            ];
        }
        if ((float) $average >= (float) $config['strong_percent']) {
            return ['classification' => 'strong', 'reason' => 'Average is at least ' . $config['strong_percent'] . '%.'];
        }
        if ((float) $average >= (float) $config['developing_percent']) {
            return ['classification' => 'developing', 'reason' => 'Average is between ' . $config['developing_percent'] . '% and ' . $config['strong_percent'] . '%.'];
        }
        return ['classification' => 'weak', 'reason' => 'Average is below ' . $config['developing_percent'] . '%.'];
    }
}

if (!function_exists('mmh_analytics_fetch_rows')) {
    function mmh_analytics_fetch_rows(mysqli $conn, $sql, $types = '', array $params = [])
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            // mysqli::bind_param requires references. Building this array
            // explicitly keeps the small shared query helper PHP 8.4-safe.
            $bindings = [$types];
            foreach ($params as $index => &$value) {
                $bindings[] = &$value;
            }
            unset($value);
            call_user_func_array([$stmt, 'bind_param'], $bindings);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

/**
 * Loads assignment, latest-submission, and topic data in bounded queries.
 * No query is issued per assignment or per topic.
 */
if (!function_exists('mmh_analytics_course_dataset')) {
    function mmh_analytics_course_dataset(mysqli $conn, $studentId, $courseId)
    {
        mmh_ensure_learning_schema($conn);
        $studentId = (int) $studentId;
        $courseId = (string) $courseId;
        $assignments = mmh_analytics_fetch_rows(
            $conn,
            'SELECT assignment_id, assignment_title, due_date, course_id, section_id, item_id, topic, subtopic, topic_id, subtopic_id, additional_topic_ids, max_score, passing_score, weight, difficulty, recommended_recording_item_id, recommended_notes_item_id, recommended_revision_item_id, allow_self_score, require_teacher_verification, completion_requirement, completion_rule, minimum_score FROM assignments WHERE course_id = ? AND archived_at IS NULL ORDER BY due_date ASC, id ASC',
            's',
            [$courseId]
        );
        $submissions = mmh_analytics_fetch_rows(
            $conn,
            'SELECT s.id, s.assignment_id, s.student_id, s.submitted_at, s.grade, s.self_score, s.self_score_status, s.feedback, s.verified_at, s.verified_by FROM assignment_submissions AS s INNER JOIN assignments AS a ON a.assignment_id = s.assignment_id WHERE s.student_id = ? AND a.course_id = ? ORDER BY s.assignment_id ASC, s.submitted_at DESC, s.id DESC',
            'is',
            [$studentId, $courseId]
        );
        $submissionByAssignment = [];
        foreach ($submissions as $submission) {
            $key = (string) $submission['assignment_id'];
            if (!isset($submissionByAssignment[$key])) {
                $submissionByAssignment[$key] = $submission;
            }
        }

        $topics = mmh_academic_topic_list($conn, $courseId, false);
        $topicById = [];
        foreach ($topics as $topic) {
            $topicById[(int) $topic['id']] = $topic;
        }

        foreach ($assignments as &$assignment) {
            $assignment['_submission'] = $submissionByAssignment[(string) $assignment['assignment_id']] ?? null;
            $assignment['_state'] = mmh_assignment_progress_evaluate($assignment, $assignment['_submission']);
            $assignment['_score'] = mmh_analytics_valid_score($assignment, $assignment['_submission']);
        }
        unset($assignment);

        return [
            'student_id' => $studentId,
            'course_id' => $courseId,
            'assignments' => $assignments,
            'topics' => $topics,
            'topic_by_id' => $topicById,
        ];
    }
}

if (!function_exists('mmh_analytics_submission_timing')) {
    function mmh_analytics_submission_timing(array $assignment, ?array $submission = null, $now = null)
    {
        $now = $now ?: time();
        if (!$submission) {
            $dueAt = !empty($assignment['due_date']) ? strtotime((string) $assignment['due_date']) : false;
            return [
                'submitted' => false,
                'late' => false,
                'on_time' => false,
                'timing_known' => false,
                'overdue' => $dueAt !== false && $dueAt < $now,
                'upcoming' => $dueAt !== false && $dueAt >= $now,
            ];
        }

        $dueAt = !empty($assignment['due_date']) ? strtotime((string) $assignment['due_date']) : false;
        $submittedAt = !empty($submission['submitted_at']) ? strtotime((string) $submission['submitted_at']) : false;
        $timingKnown = $dueAt !== false && $submittedAt !== false;
        return [
            'submitted' => true,
            'late' => $timingKnown && $submittedAt > $dueAt,
            'on_time' => $timingKnown && $submittedAt <= $dueAt,
            'timing_known' => $timingKnown,
            'overdue' => false,
            'upcoming' => false,
        ];
    }
}

if (!function_exists('mmh_analytics_homework_from_dataset')) {
    function mmh_analytics_homework_from_dataset(array $dataset, array $config = [])
    {
        $config = mmh_analytics_config($config);
        $metrics = [
            'total_assigned' => 0,
            'total_submitted' => 0,
            'total_missing' => 0,
            'total_late' => 0,
            'total_on_time' => 0,
            'timed_submission_count' => 0,
            'submission_rate' => null,
            'on_time_rate' => null,
            'valid_scored_homework_count' => 0,
            'average_normalized_score' => null,
            'weighted_average_score' => null,
            'weighting_formula' => 'Each valid normalized score is multiplied by max(valid assignment weight, 1) capped at ' . $config['maximum_effective_weight'] . '; weighted average is the sum of weighted scores divided by the sum of effective weights.',
            'passing_eligible_count' => 0,
            'passing_count' => 0,
            'passing_rate' => null,
            'pending_verification_count' => 0,
            'rejected_self_score_count' => 0,
            'overdue_homework_count' => 0,
            'upcoming_homework_count' => 0,
            'score_exclusion_reasons' => [],
        ];
        $scoreSum = 0.0;
        $weightedScoreSum = 0.0;
        $weightSum = 0.0;

        foreach ($dataset['assignments'] as $assignment) {
            $metrics['total_assigned']++;
            $submission = $assignment['_submission'];
            $timing = mmh_analytics_submission_timing($assignment, $submission);
            if (!$submission) {
                $metrics['total_missing']++;
                $metrics['overdue_homework_count'] += $timing['overdue'] ? 1 : 0;
                $metrics['upcoming_homework_count'] += $timing['upcoming'] ? 1 : 0;
                continue;
            }

            $metrics['total_submitted']++;
            $metrics['total_late'] += $timing['late'] ? 1 : 0;
            $metrics['total_on_time'] += $timing['on_time'] ? 1 : 0;
            $metrics['timed_submission_count'] += $timing['timing_known'] ? 1 : 0;
            $status = strtolower(trim((string) ($submission['self_score_status'] ?? '')));
            $metrics['pending_verification_count'] += in_array($status, ['pending_verification', 'pending'], true) ? 1 : 0;
            $metrics['rejected_self_score_count'] += $status === 'rejected' ? 1 : 0;

            $score = $assignment['_score'];
            if (!$score['valid']) {
                $reason = (string) $score['reason'];
                $metrics['score_exclusion_reasons'][$reason] = ($metrics['score_exclusion_reasons'][$reason] ?? 0) + 1;
                continue;
            }

            $percent = (float) $score['percent'];
            $metrics['valid_scored_homework_count']++;
            $scoreSum += $percent;
            $weight = mmh_analytics_effective_weight($assignment, $config);
            $weightedScoreSum += $percent * $weight;
            $weightSum += $weight;
            if (is_numeric($assignment['passing_score'] ?? null) && (float) $assignment['passing_score'] > 0) {
                $metrics['passing_eligible_count']++;
                if ((float) $score['raw_score'] >= (float) $assignment['passing_score']) {
                    $metrics['passing_count']++;
                }
            }
        }

        $metrics['submission_rate'] = mmh_analytics_round(mmh_analytics_safe_ratio($metrics['total_submitted'], $metrics['total_assigned']));
        $metrics['on_time_rate'] = mmh_analytics_round(mmh_analytics_safe_ratio($metrics['total_on_time'], $metrics['timed_submission_count']));
        $metrics['average_normalized_score'] = $metrics['valid_scored_homework_count'] > 0 ? mmh_analytics_round($scoreSum / $metrics['valid_scored_homework_count']) : null;
        $metrics['weighted_average_score'] = $weightSum > 0 ? mmh_analytics_round($weightedScoreSum / $weightSum) : null;
        $metrics['passing_rate'] = mmh_analytics_round(mmh_analytics_safe_ratio($metrics['passing_count'], $metrics['passing_eligible_count']));
        $metrics['confidence'] = mmh_analytics_confidence($metrics['valid_scored_homework_count'], $config);
        return $metrics;
    }
}

if (!function_exists('getStudentHomeworkAnalytics')) {
    function getStudentHomeworkAnalytics(mysqli $conn, $studentId, $courseId, array $config = [])
    {
        return mmh_analytics_homework_from_dataset(mmh_analytics_course_dataset($conn, $studentId, $courseId), $config);
    }
}

if (!function_exists('mmh_analytics_topic_key')) {
    function mmh_analytics_topic_key($topicId)
    {
        return is_numeric($topicId) && (int) $topicId > 0 ? (string) (int) $topicId : '__unclassified__';
    }
}

if (!function_exists('mmh_analytics_topic_seed')) {
    function mmh_analytics_topic_seed($topicKey, array $topicById)
    {
        $topic = $topicKey !== '__unclassified__' ? ($topicById[(int) $topicKey] ?? null) : null;
        return [
            'topic_id' => $topic ? (int) $topic['id'] : null,
            'title' => $topic['title'] ?? 'Unclassified',
            'parent_topic_id' => $topic ? (int) $topic['parent_topic_id'] : null,
            'assigned_count' => 0,
            'submitted_count' => 0,
            'timed_submission_count' => 0,
            'on_time_count' => 0,
            'valid_scored_count' => 0,
            'score_sum' => 0.0,
            'weighted_score_sum' => 0.0,
            'weight_sum' => 0.0,
            'best_score' => null,
            'lowest_score' => null,
            'scored_history' => [],
        ];
    }
}

if (!function_exists('mmh_analytics_add_topic_assignment')) {
    function mmh_analytics_add_topic_assignment(array &$group, array $assignment, array $config = [])
    {
        $group['assigned_count']++;
        $submission = $assignment['_submission'];
        $timing = mmh_analytics_submission_timing($assignment, $submission);
        if ($submission) {
            $group['submitted_count']++;
            $group['timed_submission_count'] += $timing['timing_known'] ? 1 : 0;
            $group['on_time_count'] += $timing['on_time'] ? 1 : 0;
        }
        $score = $assignment['_score'];
        if (!$score['valid']) {
            return;
        }
        $percent = (float) $score['percent'];
        $group['valid_scored_count']++;
        $group['score_sum'] += $percent;
        $weight = mmh_analytics_effective_weight($assignment, $config);
        $group['weighted_score_sum'] += $percent * $weight;
        $group['weight_sum'] += $weight;
        $group['best_score'] = $group['best_score'] === null ? $percent : max($group['best_score'], $percent);
        $group['lowest_score'] = $group['lowest_score'] === null ? $percent : min($group['lowest_score'], $percent);
        $group['scored_history'][] = [
            'percent' => $percent,
            'submitted_at' => $submission['submitted_at'] ?? null,
            'assignment_id' => $assignment['assignment_id'],
        ];
    }
}

if (!function_exists('mmh_analytics_finish_topic_group')) {
    function mmh_analytics_finish_topic_group(array $group, array $config = [])
    {
        $average = $group['valid_scored_count'] > 0 ? $group['score_sum'] / $group['valid_scored_count'] : null;
        $weighted = $group['weight_sum'] > 0 ? $group['weighted_score_sum'] / $group['weight_sum'] : null;
        usort($group['scored_history'], function ($left, $right) {
            return strcmp((string) ($left['submitted_at'] ?? ''), (string) ($right['submitted_at'] ?? ''));
        });
        $latest = $group['scored_history'] ? end($group['scored_history']) : null;
        $previous = count($group['scored_history']) > 1 ? $group['scored_history'][count($group['scored_history']) - 2] : null;
        $change = $latest && $previous ? (float) $latest['percent'] - (float) $previous['percent'] : null;
        $trendThreshold = (float) mmh_analytics_config($config)['trend_change_percent'];
        $trend = 'insufficient_data';
        if ($change !== null) {
            $trend = $change >= $trendThreshold ? 'improving' : ($change <= -$trendThreshold ? 'declining' : 'stable');
        }
        $classification = mmh_analytics_topic_classification($average, $group['valid_scored_count'], $config);
        return [
            'topic_id' => $group['topic_id'],
            'title' => $group['title'],
            'parent_topic_id' => $group['parent_topic_id'],
            'assigned_count' => $group['assigned_count'],
            'submitted_count' => $group['submitted_count'],
            'valid_scored_count' => $group['valid_scored_count'],
            'average_normalized_score' => mmh_analytics_round($average),
            'weighted_average_score' => mmh_analytics_round($weighted),
            'best_score' => mmh_analytics_round($group['best_score']),
            'lowest_score' => mmh_analytics_round($group['lowest_score']),
            'most_recent_score' => $latest ? mmh_analytics_round($latest['percent']) : null,
            'most_recent_score_at' => $latest['submitted_at'] ?? null,
            'trend' => $trend,
            'trend_change' => mmh_analytics_round($change),
            'on_time_submission_rate' => mmh_analytics_round(mmh_analytics_safe_ratio($group['on_time_count'], $group['timed_submission_count'])),
            'confidence' => mmh_analytics_confidence($group['valid_scored_count'], $config),
            'classification' => $classification['classification'],
            'classification_reason' => $classification['reason'],
        ];
    }
}

if (!function_exists('mmh_analytics_topic_performance_from_dataset')) {
    function mmh_analytics_topic_performance_from_dataset(array $dataset, array $config = [])
    {
        $primary = [];
        $subtopics = [];
        $secondary = [];
        foreach ($dataset['assignments'] as $assignment) {
            $primaryKey = mmh_analytics_topic_key($assignment['topic_id'] ?? null);
            if (!isset($primary[$primaryKey])) {
                $primary[$primaryKey] = mmh_analytics_topic_seed($primaryKey, $dataset['topic_by_id']);
            }
            mmh_analytics_add_topic_assignment($primary[$primaryKey], $assignment, $config);

            $subtopicKey = mmh_analytics_topic_key($assignment['subtopic_id'] ?? null);
            if ($subtopicKey !== '__unclassified__') {
                if (!isset($subtopics[$subtopicKey])) {
                    $subtopics[$subtopicKey] = mmh_analytics_topic_seed($subtopicKey, $dataset['topic_by_id']);
                }
                mmh_analytics_add_topic_assignment($subtopics[$subtopicKey], $assignment, $config);
            }

            // Secondary attribution is intentionally a separate evidence set.
            // It never contributes to the primary topic aggregate above.
            $secondaryIds = mmh_academic_parse_id_list($assignment['additional_topic_ids'] ?? '');
            foreach ($secondaryIds as $secondaryId) {
                if ((int) $secondaryId === (int) ($assignment['topic_id'] ?? 0) || (int) $secondaryId === (int) ($assignment['subtopic_id'] ?? 0)) {
                    continue;
                }
                $key = (string) $secondaryId;
                if (!isset($dataset['topic_by_id'][(int) $secondaryId])) {
                    continue;
                }
                if (!isset($secondary[$key])) {
                    $secondary[$key] = mmh_analytics_topic_seed($key, $dataset['topic_by_id']);
                }
                mmh_analytics_add_topic_assignment($secondary[$key], $assignment, $config);
            }
        }
        $finish = function (array $groups) use ($config) {
            $output = [];
            foreach ($groups as $group) {
                $output[] = mmh_analytics_finish_topic_group($group, $config);
            }
            usort($output, function ($left, $right) {
                return strcmp($left['title'], $right['title']);
            });
            return $output;
        };
        return [
            'primary_topics' => $finish($primary),
            'subtopics' => $finish($subtopics),
            'secondary_topic_evidence' => $finish($secondary),
            'attribution_rule' => 'Each assignment contributes once to primary topic performance. Additional topics are returned separately and do not affect primary aggregates.',
        ];
    }
}

if (!function_exists('getStudentTopicPerformance')) {
    function getStudentTopicPerformance(mysqli $conn, $studentId, $courseId, array $config = [])
    {
        return mmh_analytics_topic_performance_from_dataset(mmh_analytics_course_dataset($conn, $studentId, $courseId), $config);
    }
}

if (!function_exists('mmh_analytics_streaks')) {
    function mmh_analytics_streaks(array $dates)
    {
        $dates = array_values(array_unique(array_filter($dates)));
        sort($dates);
        if (!$dates) {
            return ['current_days' => 0, 'longest_days' => 0, 'scope' => 'platform_daily_activity'];
        }
        $longest = 1;
        $run = 1;
        for ($i = 1, $count = count($dates); $i < $count; $i++) {
            $previous = strtotime($dates[$i - 1]);
            $current = strtotime($dates[$i]);
            if ($previous !== false && $current === $previous + 86400) {
                $run++;
            } else {
                $run = 1;
            }
            $longest = max($longest, $run);
        }
        $current = 0;
        $expected = date('Y-m-d');
        $latest = end($dates);
        if ($latest !== $expected) {
            $expected = date('Y-m-d', strtotime('-1 day'));
        }
        for ($i = count($dates) - 1; $i >= 0; $i--) {
            if ($dates[$i] !== $expected) {
                break;
            }
            $current++;
            $expected = date('Y-m-d', strtotime($expected . ' -1 day'));
        }
        return ['current_days' => $current, 'longest_days' => $longest, 'scope' => 'platform_daily_activity'];
    }
}

if (!function_exists('getStudentActivitySummary')) {
    function getStudentActivitySummary(mysqli $conn, $studentId, $courseId)
    {
        mmh_ensure_learning_schema($conn);
        $studentId = (int) $studentId;
        $courseId = (string) $courseId;
        $counts = mmh_analytics_fetch_rows(
            $conn,
            'SELECT event_type, COUNT(*) AS event_count, MAX(created_at) AS last_event_at FROM learning_events WHERE user_id = ? AND course_id = ? GROUP BY event_type',
            'is',
            [$studentId, $courseId]
        );
        $eventCounts = [];
        $lastActivity = null;
        foreach ($counts as $row) {
            $eventCounts[$row['event_type']] = (int) $row['event_count'];
            if ($lastActivity === null || strcmp((string) $row['last_event_at'], $lastActivity) > 0) {
                $lastActivity = $row['last_event_at'];
            }
        }
        $dateRows = mmh_analytics_fetch_rows(
            $conn,
            'SELECT DATE(created_at) AS activity_date, MIN(created_at) AS first_event_at, MAX(created_at) AS last_event_at FROM learning_events WHERE user_id = ? AND course_id = ? GROUP BY DATE(created_at) ORDER BY activity_date ASC',
            'is',
            [$studentId, $courseId]
        );
        $dailyRows = mmh_analytics_fetch_rows(
            $conn,
            'SELECT activity_date FROM learning_daily_activity WHERE user_id = ? ORDER BY activity_date ASC',
            'i',
            [$studentId]
        );
        $streaks = mmh_analytics_streaks(array_column($dailyRows, 'activity_date'));
        $metricEvents = [
            'course_opened', 'section_opened', 'section_completed', 'recording_started', 'recording_completed',
            'notes_opened', 'notes_downloaded', 'homework_opened', 'homework_submitted',
            'homework_resubmitted', 'homework_approved', 'homework_rejected', 'model_answer_viewed',
            'revision_session', 'daily_visit',
        ];
        $metrics = [];
        foreach ($metricEvents as $eventType) {
            $metrics[$eventType] = $eventCounts[$eventType] ?? 0;
        }
        $firstActivity = $dateRows[0]['first_event_at'] ?? null;
        $activeDays = count($dateRows);
        $observedDays = null;
        if ($firstActivity) {
            $firstDay = strtotime(date('Y-m-d', strtotime($firstActivity)));
            $today = strtotime(date('Y-m-d'));
            $observedDays = $firstDay !== false && $today !== false ? max(1, (int) (($today - $firstDay) / 86400) + 1) : null;
        }
        return [
            'last_activity_at' => $lastActivity,
            'active_course_days' => $activeDays,
            'event_counts' => $metrics,
            'current_activity_streak_days' => $streaks['current_days'],
            'longest_activity_streak_days' => $streaks['longest_days'],
            'streak_scope' => $streaks['scope'],
            'study_time_status' => 'unavailable',
            'activity_coverage' => [
                'percent' => mmh_analytics_round(mmh_analytics_safe_ratio($activeDays, $observedDays)),
                'active_days' => $activeDays,
                'observed_days' => $observedDays,
                'scope' => 'course activity days from first recorded course event through today',
            ],
        ];
    }
}

if (!function_exists('mmh_analytics_course_learning_state')) {
    function mmh_analytics_course_learning_state(mysqli $conn, $courseId, $studentId)
    {
        $course = mmh_analytics_fetch_rows($conn, 'SELECT sequential_learning FROM courses WHERE course_id = ? LIMIT 1', 's', [$courseId]);
        $override = ['sequential_override' => 'inherit', 'unlocked_sections' => []];
        if (mmh_table_exists($conn, 'course_learning_overrides')) {
            $rows = mmh_analytics_fetch_rows($conn, 'SELECT sequential_override, unlocked_sections FROM course_learning_overrides WHERE course_id = ? AND user_id = ? LIMIT 1', 'si', [$courseId, $studentId]);
            if ($rows) {
                $decoded = json_decode((string) ($rows[0]['unlocked_sections'] ?? '[]'), true);
                $override = [
                    'sequential_override' => $rows[0]['sequential_override'] ?: 'inherit',
                    'unlocked_sections' => is_array($decoded) ? array_map('strval', $decoded) : [],
                ];
            }
        }
        $mode = $override['sequential_override'];
        $enabled = $mode === 'on' || $mode === 'unlock_selected' || ($mode !== 'off' && $mode !== 'unlock_all' && (int) ($course[0]['sequential_learning'] ?? 0) === 1);
        return ['learning_enabled' => $enabled, 'override' => $override];
    }
}

if (!function_exists('mmh_analytics_assignment_done_for_rule')) {
    function mmh_analytics_assignment_done_for_rule(array $assignmentById, $assignmentId, $approvalRequired)
    {
        $assignment = $assignmentById[(string) $assignmentId] ?? null;
        if (!$assignment) {
            return false;
        }
        $rule = $approvalRequired ? 'teacher_approval' : 'submission';
        return !empty(mmh_assignment_progress_evaluate($assignment, $assignment['_submission'] ?? null, $rule)['complete']);
    }
}

if (!function_exists('getStudentSectionProgress')) {
    function getStudentSectionProgress(mysqli $conn, $studentId, $courseId, ?array $dataset = null)
    {
        mmh_ensure_learning_schema($conn);
        $studentId = (int) $studentId;
        $courseId = (string) $courseId;
        $dataset = $dataset ?: mmh_analytics_course_dataset($conn, $studentId, $courseId);
        $sections = mmh_analytics_fetch_rows(
            $conn,
            "SELECT s.section_id, s.title, s.section_type, s.sort_order, s.unlock_mode, s.completion_rule, s.unlock_at, s.unlock_timezone, s.unlock_homework_id, s.manual_unlocked, COUNT(ci.id) AS lesson_count FROM course_sections AS s INNER JOIN course_items AS ci ON ci.course_id = s.course_id AND ci.section_id = s.section_id AND (ci.status IS NULL OR ci.status = '' OR ci.status = 'published') WHERE s.course_id = ? AND s.status = 'published' GROUP BY s.id, s.section_id, s.title, s.section_type, s.sort_order, s.unlock_mode, s.completion_rule, s.unlock_at, s.unlock_timezone, s.unlock_homework_id, s.manual_unlocked ORDER BY s.sort_order ASC, s.id ASC",
            's',
            [$courseId]
        );
        $progressRows = mmh_analytics_fetch_rows($conn, 'SELECT section_id, completed_at, source FROM course_section_progress WHERE course_id = ? AND user_id = ?', 'si', [$courseId, $studentId]);
        $completedBySection = [];
        foreach ($progressRows as $row) {
            $completedBySection[(string) $row['section_id']] = $row;
        }
        $generalRows = mmh_analytics_fetch_rows($conn, "SELECT COUNT(*) AS lesson_count FROM course_items WHERE course_id = ? AND (section_id IS NULL OR section_id = '') AND (status IS NULL OR status = '' OR status = 'published')", 's', [$courseId]);
        $state = mmh_analytics_course_learning_state($conn, $courseId, $studentId);
        $assignmentById = [];
        foreach ($dataset['assignments'] as $assignment) {
            $assignmentById[(string) $assignment['assignment_id']] = $assignment;
        }
        foreach ($sections as $index => &$section) {
            $sectionId = (string) $section['section_id'];
            $requirementState = mmh_assignment_progress_section_state($assignmentById, $sectionId);
            $completed = isset($completedBySection[$sectionId]);
            if (!empty($requirementState['has_requirements'])) {
                $completed = !empty($requirementState['complete']);
            } elseif (!$completed && ($section['completion_rule'] ?? '') === 'homework_submitted') {
                $completed = mmh_analytics_assignment_done_for_rule($assignmentById, $section['unlock_homework_id'] ?? '', false);
            } elseif (!$completed && ($section['completion_rule'] ?? '') === 'homework_approved') {
                $completed = mmh_analytics_assignment_done_for_rule($assignmentById, $section['unlock_homework_id'] ?? '', true);
            }
            $section['assignment_requirements'] = $requirementState;
            $section['completed'] = $completed;
            $section['locked'] = false;
            $section['lock_reason'] = '';
            if ($state['learning_enabled'] && !in_array($sectionId, $state['override']['unlocked_sections'], true) && $state['override']['sequential_override'] !== 'unlock_all') {
                $unlockMode = $section['unlock_mode'] ?: 'always';
                if ($unlockMode === 'after_previous_completed' && $index > 0 && empty($sections[$index - 1]['completed'])) {
                    $section['locked'] = true;
                    $section['lock_reason'] = 'Complete ' . ($sections[$index - 1]['title'] ?: 'the previous section') . ' first.';
                } elseif ($unlockMode === 'on_date' && !empty($section['unlock_at']) && strtotime($section['unlock_at']) > time()) {
                    $section['locked'] = true;
                    $section['lock_reason'] = 'This section is not available yet.';
                } elseif ($unlockMode === 'manual_unlock' && empty($section['manual_unlocked'])) {
                    $section['locked'] = true;
                    $section['lock_reason'] = 'This section is locked by the teacher.';
                } elseif ($unlockMode === 'after_homework_submission' && !mmh_analytics_assignment_done_for_rule($assignmentById, $section['unlock_homework_id'] ?? '', false)) {
                    $section['locked'] = true;
                    $section['lock_reason'] = 'Homework submission is required.';
                } elseif ($unlockMode === 'after_homework_approval' && !mmh_analytics_assignment_done_for_rule($assignmentById, $section['unlock_homework_id'] ?? '', true)) {
                    $section['locked'] = true;
                    $section['lock_reason'] = 'Homework approval is required.';
                } elseif ($unlockMode === 'custom_rule') {
                    $section['locked'] = true;
                    $section['lock_reason'] = 'This section has a custom learning rule.';
                }
            }
        }
        unset($section);
        $completedCount = count(array_filter($sections, function ($section) {
            return !empty($section['completed']);
        }));
        $generalCount = (int) ($generalRows[0]['lesson_count'] ?? 0);
        return [
            'sections' => $sections,
            'trackable_section_count' => count($sections),
            'completed_section_count' => $completedCount,
            'completion_percent' => mmh_analytics_round(mmh_analytics_safe_ratio($completedCount, count($sections))),
            'general_section' => [
                'title' => 'General',
                'lesson_count' => $generalCount,
                'legacy_unsectioned_lessons' => $generalCount > 0,
            ],
            'learning_rules_enabled' => $state['learning_enabled'],
        ];
    }
}

if (!function_exists('mmh_analytics_model_answer_candidates')) {
    function mmh_analytics_model_answer_candidates(mysqli $conn, $studentId, $courseId)
    {
        $items = mmh_analytics_fetch_rows(
            $conn,
            "SELECT item_id, item_title, section_id FROM course_items WHERE course_id = ? AND template_type = 'assignment_model_answer' AND (status IS NULL OR status = '' OR status = 'published') ORDER BY page_order ASC, id ASC",
            's',
            [$courseId]
        );
        $viewed = mmh_analytics_fetch_rows(
            $conn,
            "SELECT item_id FROM learning_events WHERE user_id = ? AND course_id = ? AND event_type = 'model_answer_viewed' AND item_id IS NOT NULL GROUP BY item_id",
            'is',
            [$studentId, $courseId]
        );
        $viewedIds = array_flip(array_map('strval', array_column($viewed, 'item_id')));
        return array_values(array_filter($items, function ($item) use ($viewedIds) {
            return !isset($viewedIds[(string) $item['item_id']]);
        }));
    }
}

if (!function_exists('mmh_analytics_candidate')) {
    function mmh_analytics_candidate($type, $reason, $priority, $courseId, $sectionId = null, ?array $topic = null, $elementId = null, $assignmentId = null, $explanation = '')
    {
        return [
            'type' => $type,
            'reason' => $reason,
            'priority' => $priority,
            'related_course_id' => (string) $courseId,
            'related_section_id' => $sectionId !== '' ? $sectionId : null,
            'related_topic' => $topic,
            'related_element_id' => $elementId !== '' ? $elementId : null,
            'related_assignment_id' => $assignmentId !== '' ? $assignmentId : null,
            'explanation' => $explanation,
        ];
    }
}

if (!function_exists('getStudentRecommendationCandidates')) {
    function getStudentRecommendationCandidates(mysqli $conn, $studentId, $courseId, array $config = [], ?array $dataset = null, ?array $topicPerformance = null, ?array $sectionProgress = null)
    {
        $dataset = $dataset ?: mmh_analytics_course_dataset($conn, $studentId, $courseId);
        $topicPerformance = $topicPerformance ?: mmh_analytics_topic_performance_from_dataset($dataset, $config);
        $sectionProgress = $sectionProgress ?: getStudentSectionProgress($conn, $studentId, $courseId, $dataset);
        $candidates = [];
        $elementKeys = [];
        foreach ($topicPerformance['primary_topics'] as $topic) {
            if ($topic['classification'] !== 'weak') {
                continue;
            }
            $topicRef = ['id' => $topic['topic_id'], 'title' => $topic['title']];
            $candidates[] = mmh_analytics_candidate('weak_topic', 'Weak topic', 'high', $courseId, null, $topicRef, null, null, $topic['title'] . ' averages ' . $topic['average_normalized_score'] . '% across ' . $topic['valid_scored_count'] . ' valid scored homeworks.');
            foreach ($dataset['assignments'] as $assignment) {
                if ((int) ($assignment['topic_id'] ?? 0) !== (int) $topic['topic_id']) {
                    continue;
                }
                $recommendations = [
                    'recommended_recording_item_id' => ['related_recording', 'Review the recommended recording'],
                    'recommended_notes_item_id' => ['related_notes', 'Review the recommended notes'],
                    'recommended_revision_item_id' => ['related_revision_element', 'Complete the recommended revision element'],
                ];
                foreach ($recommendations as $column => $definition) {
                    $itemId = trim((string) ($assignment[$column] ?? ''));
                    $dedupe = $definition[0] . ':' . $itemId;
                    if ($itemId === '' || isset($elementKeys[$dedupe])) {
                        continue;
                    }
                    $elementKeys[$dedupe] = true;
                    $candidates[] = mmh_analytics_candidate($definition[0], $definition[1], 'medium', $courseId, $assignment['section_id'] ?? null, $topicRef, $itemId, $assignment['assignment_id'], $definition[1] . ' for ' . $topic['title'] . '.');
                }
            }
        }
        foreach ($dataset['assignments'] as $assignment) {
            $submission = $assignment['_submission'];
            if (!$submission) {
                $timing = mmh_analytics_submission_timing($assignment, null);
                $candidates[] = mmh_analytics_candidate(
                    $timing['overdue'] ? 'missing_homework' : 'pending_homework',
                    $timing['overdue'] ? 'Homework is overdue' : 'Homework has not been submitted',
                    $timing['overdue'] ? 'high' : 'medium',
                    $courseId,
                    $assignment['section_id'] ?? null,
                    null,
                    $assignment['item_id'] ?? null,
                    $assignment['assignment_id'],
                    $assignment['assignment_title'] . ' has not been submitted.'
                );
                continue;
            }
            if (in_array(strtolower(trim((string) ($submission['self_score_status'] ?? ''))), ['pending_verification', 'pending'], true)) {
                $candidates[] = mmh_analytics_candidate('pending_homework', 'Homework is pending verification', 'medium', $courseId, $assignment['section_id'] ?? null, null, $assignment['item_id'] ?? null, $assignment['assignment_id'], $assignment['assignment_title'] . ' is awaiting teacher verification.');
            }
        }
        foreach (mmh_analytics_model_answer_candidates($conn, $studentId, $courseId) as $item) {
            $candidates[] = mmh_analytics_candidate('unviewed_model_answer', 'Model answer has not been viewed', 'low', $courseId, $item['section_id'] ?? null, null, $item['item_id'], null, $item['item_title'] . ' is available to review.');
        }
        foreach ($sectionProgress['sections'] as $section) {
            if (!empty($section['locked'])) {
                $candidates[] = mmh_analytics_candidate('blocked_section', 'Section blocked by learning rule', 'medium', $courseId, $section['section_id'], null, null, null, $section['title'] . ': ' . $section['lock_reason']);
            }
        }
        return $candidates;
    }
}

if (!function_exists('getStudentCourseOverview')) {
    function getStudentCourseOverview(mysqli $conn, $studentId, $courseId, array $config = [])
    {
        $dataset = mmh_analytics_course_dataset($conn, $studentId, $courseId);
        $homework = mmh_analytics_homework_from_dataset($dataset, $config);
        $topics = mmh_analytics_topic_performance_from_dataset($dataset, $config);
        $activity = getStudentActivitySummary($conn, $studentId, $courseId);
        $sections = getStudentSectionProgress($conn, $studentId, $courseId, $dataset);
        $recommendations = !array_key_exists('include_recommendation_candidates', $config) || !empty($config['include_recommendation_candidates'])
            ? getStudentRecommendationCandidates($conn, $studentId, $courseId, $config, $dataset, $topics, $sections)
            : [];
        return [
            'course_id' => (string) $courseId,
            'student_id' => (int) $studentId,
            'homework' => $homework,
            'topics' => $topics,
            'activity' => $activity,
            'section_progress' => $sections,
            // These are intentionally separate dimensions, not an invented
            // blended "course completion" percentage.
            'progress_metrics' => [
                'section_completion' => [
                    'percent' => $sections['completion_percent'],
                    'completed' => $sections['completed_section_count'],
                    'total' => $sections['trackable_section_count'],
                ],
                'homework_submission' => [
                    'percent' => $homework['submission_rate'],
                    'submitted' => $homework['total_submitted'],
                    'total' => $homework['total_assigned'],
                ],
                'valid_score_coverage' => [
                    'percent' => mmh_analytics_round(mmh_analytics_safe_ratio($homework['valid_scored_homework_count'], $homework['total_assigned'])),
                    'valid_scored' => $homework['valid_scored_homework_count'],
                    'total_assigned' => $homework['total_assigned'],
                ],
                'activity_coverage' => $activity['activity_coverage'],
            ],
            'recommendation_candidates' => $recommendations,
        ];
    }
}
