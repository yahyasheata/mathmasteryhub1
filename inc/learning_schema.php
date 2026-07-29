<?php
/**
 * Learning Intelligence Foundation — schema bootstrap.
 *
 * This file safely creates the new tables/columns used by the Learning Event
 * system (see inc/LearningEvents.php) without touching or redefining any
 * existing table. It follows the same defensive, idempotent pattern already
 * used across the Course Builder (see item_column_exists()/item_table_exists()
 * in views/admin/requests/add-item.php): every change is guarded by an
 * INFORMATION_SCHEMA check, so running this file multiple times (or against a
 * database that already has some of these columns) is always safe.
 *
 * Nothing in this file modifies existing columns, drops data, or changes the
 * behavior of the Course Builder, Learning Rules, or lesson rendering.
 */

if (!function_exists('mmh_table_exists')) {
    function mmh_table_exists(mysqli $conn, $table)
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
}

if (!function_exists('mmh_column_exists')) {
    function mmh_column_exists(mysqli $conn, $table, $column)
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('mmh_add_column_if_missing')) {
    function mmh_add_column_if_missing(mysqli $conn, $table, $column, $definitionSql)
    {
        if (!mmh_table_exists($conn, $table)) {
            return true; // Nothing to add to; table is created elsewhere or does not apply.
        }
        if (mmh_column_exists($conn, $table, $column)) {
            return true;
        }
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definitionSql}";
        return (bool) $conn->query($sql);
    }
}

if (!function_exists('mmh_index_exists')) {
    function mmh_index_exists(mysqli $conn, $table, $index)
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('mmh_add_index_if_missing')) {
    function mmh_add_index_if_missing(mysqli $conn, $table, $index, $columns, $unique = false)
    {
        if (!mmh_table_exists($conn, $table) || mmh_index_exists($conn, $table, $index)) {
            return true;
        }
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        return (bool) $conn->query("ALTER TABLE `{$table}` ADD {$kind} `{$index}` ({$columns})");
    }
}

/**
 * Creates every table/column the Learning Intelligence Foundation needs.
 * Safe to call on every request (each check is a fast primary/unique-key
 * lookup against INFORMATION_SCHEMA); intended to be called once via
 * mmh_ensure_learning_schema() which memoizes per-request so the checks only
 * run a single time even if included from multiple places.
 */
function mmh_ensure_learning_schema(mysqli $conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // --- Core append-only Learning Event log (Part 1) ---------------------
    if (!mmh_table_exists($conn, 'learning_events')) {
        $conn->query("CREATE TABLE IF NOT EXISTS `learning_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `event_type` VARCHAR(40) NOT NULL,
            `course_id` VARCHAR(40) NULL,
            `section_id` VARCHAR(40) NULL,
            `item_id` VARCHAR(40) NULL,
            `assignment_id` VARCHAR(40) NULL,
            `exam_id` VARCHAR(40) NULL,
            `meta` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_event` (`user_id`, `event_type`, `created_at`),
            KEY `idx_course` (`course_id`, `created_at`),
            KEY `idx_item` (`item_id`),
            KEY `idx_assignment` (`assignment_id`),
            KEY `idx_event_type` (`event_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    // --- Per-user per-day rollup for streaks / attendance / daily visit ---
    if (!mmh_table_exists($conn, 'learning_daily_activity')) {
        $conn->query("CREATE TABLE IF NOT EXISTS `learning_daily_activity` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `activity_date` DATE NOT NULL,
            `events_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `first_event_at` DATETIME NULL,
            `last_event_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_date` (`user_id`, `activity_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    // --- Lesson metadata (Part 4): one flexible JSON column, mirroring the
    //     existing course_items.template_data convention already used by
    //     the Course Builder, so no new per-field columns are needed and the
    //     lesson renderer keeps working exactly as before. ------------------
    mmh_add_column_if_missing($conn, 'course_items', 'metadata', "TEXT NULL AFTER `template_data`");

    // --- Section release rules --------------------------------------------
    // Defaults deliberately preserve established course availability and the
    // existing Sequential Learning behavior for every legacy section.
    if (mmh_table_exists($conn, 'course_sections')) {
        // Section metadata mirrors course_items.metadata. It contains only
        // section-owned values; lesson overrides remain on course_items.
        mmh_add_column_if_missing($conn, 'course_sections', 'metadata', "TEXT NULL AFTER `description`");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_mode', "VARCHAR(32) NOT NULL DEFAULT 'inherit'");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_override', "VARCHAR(16) NOT NULL DEFAULT 'inherit'");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_at', "DATETIME NULL");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_timezone', "VARCHAR(80) NULL");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_occurrence_id', "VARCHAR(64) NULL");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_delay_minutes', "INT UNSIGNED NOT NULL DEFAULT 0");
        mmh_add_column_if_missing($conn, 'course_sections', 'release_updated_at', "DATETIME NULL");
        mmh_add_index_if_missing($conn, 'course_sections', 'idx_course_release_mode', '`course_id`, `release_mode`');
        mmh_add_index_if_missing($conn, 'course_sections', 'idx_release_occurrence', '`release_occurrence_id`');
    }

    // --- Reusable, course-scoped academic topic taxonomy (B2) ------------
    // parent_topic_id = 0 denotes a root topic. This makes the unique key
    // reliable on MySQL, where a nullable column otherwise allows duplicates.
    if (!mmh_table_exists($conn, 'course_topics')) {
        $conn->query("CREATE TABLE IF NOT EXISTS `course_topics` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` VARCHAR(20) NOT NULL,
            `parent_topic_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `title` VARCHAR(120) NOT NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_course_parent_title` (`course_id`, `parent_topic_id`, `title`),
            KEY `idx_course_parent_sort` (`course_id`, `parent_topic_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }
    mmh_add_index_if_missing($conn, 'course_topics', 'idx_course_parent_sort', '`course_id`, `parent_topic_id`, `sort_order`');

    // A disabled default preserves the behavior of every existing course.
    mmh_add_column_if_missing($conn, 'courses', 'default_homework_score_mode', "VARCHAR(32) NOT NULL DEFAULT 'disabled'");

    // --- Homework metadata (Part 2), all nullable/defaulted so legacy
    //     homeworks (created before this change) keep working unmodified. --
    mmh_add_column_if_missing($conn, 'assignments', 'section_id', "VARCHAR(40) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'item_id', "VARCHAR(40) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'topic', "VARCHAR(120) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'subtopic', "VARCHAR(120) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'difficulty', "VARCHAR(20) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'estimated_time', "INT UNSIGNED NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'max_score', "DECIMAL(6,2) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'passing_score', "DECIMAL(6,2) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'weight', "DECIMAL(6,2) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'skills_tested', "TEXT NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'calculator_allowed', "TINYINT(1) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'exam_board', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'paper', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'teacher_notes', "TEXT NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'recommended_revision_item_id', "VARCHAR(40) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'allow_self_score', "TINYINT(1) NOT NULL DEFAULT 0");
    mmh_add_column_if_missing($conn, 'assignments', 'require_teacher_verification', "TINYINT(1) NOT NULL DEFAULT 1");
    mmh_add_column_if_missing($conn, 'assignments', 'importance', "VARCHAR(20) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'category', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'homework_type', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'topic_id', "INT UNSIGNED NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'subtopic_id', "INT UNSIGNED NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'additional_topic_ids', "TEXT NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'learning_objectives', "TEXT NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'keywords', "TEXT NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'week', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'unit', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'term', "VARCHAR(60) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'syllabus_code', "VARCHAR(80) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'calculator_mode', "VARCHAR(32) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'recommended_recording_item_id', "VARCHAR(40) NULL");
    mmh_add_column_if_missing($conn, 'assignments', 'recommended_notes_item_id', "VARCHAR(40) NULL");

    // Assignment-to-progress requirements. Defaults keep every legacy
    // assignment optional until a teacher explicitly configures a blocker.
    mmh_add_column_if_missing($conn, 'assignments', 'completion_requirement', "VARCHAR(20) NOT NULL DEFAULT 'optional'");
    mmh_add_column_if_missing($conn, 'assignments', 'completion_rule', "VARCHAR(32) NOT NULL DEFAULT 'submission'");
    mmh_add_column_if_missing($conn, 'assignments', 'minimum_score', "DECIMAL(6,2) NULL");
    // Legacy transition: explicitly re-open an otherwise closed assignment
    // without changing legacy due-date semantics for any other assignment.
    mmh_add_column_if_missing($conn, 'assignments', 'late_submission_enabled', "TINYINT(1) NOT NULL DEFAULT 0");
    mmh_add_column_if_missing($conn, 'assignments', 'late_submission_until', "DATETIME NULL");

    mmh_add_index_if_missing($conn, 'assignments', 'idx_course_primary_topic', '`course_id`, `topic_id`');
    mmh_add_index_if_missing($conn, 'assignments', 'idx_course_assignment_requirement', '`course_id`, `completion_requirement`, `section_id`, `item_id`');
    mmh_add_index_if_missing($conn, 'assignments', 'idx_course_assignment_item', '`course_id`, `item_id`');
    // B3A analytics reads course homework in due-date order. This keeps the
    // aggregate query bounded without changing homework data or behavior.
    mmh_add_index_if_missing($conn, 'assignments', 'idx_course_due_date', '`course_id`, `due_date`');
    mmh_add_index_if_missing($conn, 'assignments', 'idx_assignment_late_window', '`late_submission_enabled`, `late_submission_until`');

    // --- Student self-score workflow (Part 3) ------------------------------
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'self_score', "DECIMAL(6,2) NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'self_score_status', "VARCHAR(20) NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'verification_note', "TEXT NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'verified_at', "DATETIME NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'verified_by', "INT NULL");
    // Imported work is still one normal submission. These fields preserve its
    // provenance without duplicating homework, grades, or progress records.
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'submission_source', "VARCHAR(20) NOT NULL DEFAULT 'lms'");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'imported_by', "INT NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'imported_at', "DATETIME NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'original_submitted_at', "DATETIME NULL");
    mmh_add_column_if_missing($conn, 'assignment_submissions', 'import_notes', "TEXT NULL");
    mmh_add_index_if_missing($conn, 'assignment_submissions', 'idx_submission_source', '`submission_source`');
    mmh_add_index_if_missing($conn, 'assignment_submissions', 'idx_assignment_student', '`assignment_id`, `student_id`');
    // The analytics service filters a student's latest submissions by course.
    mmh_add_index_if_missing($conn, 'assignment_submissions', 'idx_student_assignment', '`student_id`, `assignment_id`');

    // Multiple imported files belong to the existing submission rather than
    // creating duplicate submission rows. Normal LMS uploads continue using
    // assignment_submissions.file_path unchanged.
    $conn->query("CREATE TABLE IF NOT EXISTS assignment_submission_files (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        submission_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_submission_file (submission_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // B3A course activity summaries filter all three values together.
    mmh_add_index_if_missing($conn, 'learning_events', 'idx_user_course_event_created', '`user_id`, `course_id`, `event_type`, `created_at`');
}
