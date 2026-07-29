<?php
/**
 * Learning Intelligence Foundation — centralized Learning Event system.
 *
 * This is the single place that knows how to record a learning event. Every
 * feature (Course Builder, lesson player, submissions, auth) should call
 * mmh_log_event() instead of writing its own INSERT, so the event vocabulary
 * stays consistent and reusable for future Analytics / AI Recommendations /
 * Weekly Reports / Teacher Dashboards.
 *
 * Design goals (see Prompt B1):
 *  - Never fake analytics: every event written here corresponds to a real,
 *    just-happened action. Nothing here invents or estimates data.
 *  - Lightweight: one prepared INSERT per event, no joins, no heavy reads.
 *  - Privacy-safe: only educational context is stored (ids + small optional
 *    meta). No IP addresses, user agents, or other personal data.
 *  - Never breaks the page: every write is best-effort. If the table is
 *    missing or a write fails, the calling feature continues to work exactly
 *    as before.
 */

require_once __DIR__ . '/learning_schema.php';

/**
 * The closed vocabulary of Learning Events this platform records today.
 * Keeping this list closed (rather than accepting any string) keeps the
 * event log clean and directly usable by future analytics without any
 * clean-up pass.
 *
 * Note on "Past Paper" events: the LMS does not have a dedicated Past Papers
 * feature yet. The existing "Exam" content type (views/admin/exams.php,
 * exam_submissions) is the closest current equivalent — a downloadable paper
 * a student submits answers for — so it is mapped to past_paper_opened /
 * past_paper_completed today. When a real Past Papers feature is built, it
 * can keep using these same event names with no schema change.
 */
const MMH_LEARNING_EVENT_TYPES = [
    'course_opened',
    'section_opened',
    'section_completed',
    'recording_started',
    'recording_completed',
    'notes_opened',
    'notes_downloaded',
    'homework_opened',
    'homework_submitted',
    'homework_resubmitted',
    'homework_approved',
    'homework_rejected',
    'model_answer_viewed',
    'custom_lesson_opened',
    'past_paper_opened',
    'past_paper_viewed',
    'question_paper_opened',
    'mark_scheme_opened',
    'model_answer_opened',
    'solution_video_opened',
    'past_paper_downloaded',
    'past_paper_completed',
    'free_resource_opened',
    'free_resource_downloaded',
    'free_video_opened',
    'free_notes_opened',
    'free_worksheet_opened',
    'login',
    'logout',
    'daily_visit',
    'revision_session',
    'live_session_viewed',
    'live_session_join_clicked',
];

const MMH_SESSION_DEDUPE_EVENT_TYPES = [
    'course_opened',
    'section_opened',
    'recording_started',
    'notes_opened',
    'notes_downloaded',
    'homework_opened',
    'model_answer_viewed',
    'custom_lesson_opened',
    'past_paper_opened',
    'past_paper_viewed',
    'question_paper_opened',
    'mark_scheme_opened',
    'model_answer_opened',
    'solution_video_opened',
    'past_paper_downloaded',
    'free_resource_opened',
    'free_resource_downloaded',
    'free_video_opened',
    'free_notes_opened',
    'free_worksheet_opened',
    'revision_session',
    'live_session_viewed',
    'live_session_join_clicked',
];

function mmh_learning_event_session_key($userId, $eventType, array $context)
{
    $parts = [
        (int) $userId,
        (string) $eventType,
        (string) ($context['course_id'] ?? ''),
        (string) ($context['section_id'] ?? ''),
        (string) ($context['item_id'] ?? ''),
        (string) ($context['assignment_id'] ?? ''),
        (string) ($context['exam_id'] ?? ''),
    ];

    return sha1(implode('|', $parts));
}

/**
 * Maps a lesson's template_type (or legacy item_type) to the Learning Event
 * that should be fired when a student opens that lesson. Centralized here so
 * both the lesson renderer and any future caller stay consistent.
 */
function mmh_lesson_open_event($lessonType)
{
    $lessonType = strtolower(trim((string) $lessonType));
    $map = [
        'recording' => 'recording_started',
        'video' => 'recording_started',
        'notes' => 'notes_opened',
        'pdf' => 'notes_opened',
        'download' => 'notes_opened',
        'embed' => 'notes_opened',
        'external_link' => 'notes_opened',
        'google_drive' => 'notes_opened',
        'onedrive' => 'notes_opened',
        'classified_assignment' => 'homework_opened',
        'assignment' => 'homework_opened',
        'exam' => 'past_paper_opened',
        'assignment_model_answer' => 'model_answer_viewed',
        'custom_lesson' => 'custom_lesson_opened',
        'custom_html' => 'custom_lesson_opened',
        // Legacy pre-template items (see architecture report): fall back to
        // the coarse item_type classification.
        'quiz' => 'homework_opened',
        'file' => 'notes_opened',
    ];

    return $map[$lessonType] ?? 'custom_lesson_opened';
}

/**
 * Records one Learning Event. Returns true/false but a caller can safely
 * ignore the result — tracking must never break the feature that triggered
 * it.
 *
 * @param mysqli $conn
 * @param int    $userId
 * @param string $eventType One of MMH_LEARNING_EVENT_TYPES.
 * @param array  $context   Optional keys: course_id, section_id, item_id,
 *                          assignment_id, exam_id, meta (array of small,
 *                          strictly educational values such as
 *                          duration_seconds or self_score).
 */
function mmh_log_event(mysqli $conn, $userId, $eventType, array $context = [])
{
    $userId = (int) $userId;
    if ($userId <= 0 || !in_array($eventType, MMH_LEARNING_EVENT_TYPES, true)) {
        return false;
    }

    $dedupeKey = null;
    if (in_array($eventType, MMH_SESSION_DEDUPE_EVENT_TYPES, true)) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $dedupeKey = mmh_learning_event_session_key($userId, $eventType, $context);
        if (!empty($_SESSION['mmh_learning_event_seen'][$dedupeKey])) {
            return true;
        }
    }

    try {
        mmh_ensure_learning_schema($conn);

        $courseId = isset($context['course_id']) && $context['course_id'] !== '' ? (string) $context['course_id'] : null;
        $sectionId = isset($context['section_id']) && $context['section_id'] !== '' ? (string) $context['section_id'] : null;
        $itemId = isset($context['item_id']) && $context['item_id'] !== '' ? (string) $context['item_id'] : null;
        $assignmentId = isset($context['assignment_id']) && $context['assignment_id'] !== '' ? (string) $context['assignment_id'] : null;
        $examId = isset($context['exam_id']) && $context['exam_id'] !== '' ? (string) $context['exam_id'] : null;
        $meta = null;
        if (!empty($context['meta']) && is_array($context['meta'])) {
            $meta = json_encode($context['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $stmt = $conn->prepare('INSERT INTO learning_events (user_id, event_type, course_id, section_id, item_id, assignment_id, exam_id, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isssssss', $userId, $eventType, $courseId, $sectionId, $itemId, $assignmentId, $examId, $meta);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $dedupeKey !== null) {
            $_SESSION['mmh_learning_event_seen'][$dedupeKey] = 1;
        }

        return (bool) $ok;
    } catch (Throwable $e) {
        // Tracking must never break the page that triggered it.
        return false;
    }
}

/**
 * Marks that a user was learning-active today. Cheap: at most one UPSERT per
 * user per calendar day, guarded by the session so repeat page loads on the
 * same day never touch the database again. Feeds future Daily Visit,
 * Learning Streaks, and Attendance features.
 */
function mmh_track_daily_visit(mysqli $conn, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    $today = date('Y-m-d');
    if (isset($_SESSION['mmh_daily_visit_date']) && $_SESSION['mmh_daily_visit_date'] === $today) {
        return; // Already recorded for today in this session.
    }

    try {
        mmh_ensure_learning_schema($conn);

        $stmt = $conn->prepare('INSERT INTO learning_daily_activity (user_id, activity_date, events_count, first_event_at, last_event_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE events_count = events_count + 1, last_event_at = NOW()');
        if ($stmt) {
            $stmt->bind_param('is', $userId, $today);
            $stmt->execute();
            $stmt->close();
        }

        $_SESSION['mmh_daily_visit_date'] = $today;
    } catch (Throwable $e) {
        // Never break the page over a tracking write.
    }
}
