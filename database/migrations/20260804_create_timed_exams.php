<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$statements = [
    "CREATE TABLE IF NOT EXISTS timed_exams (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        course_id VARCHAR(40) NOT NULL,
        item_id VARCHAR(40) NOT NULL,
        title VARCHAR(190) NOT NULL,
        instructions TEXT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        timing_mode VARCHAR(24) NOT NULL DEFAULT 'fixed_window',
        scheduled_start_at_utc DATETIME NULL,
        duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
        grace_minutes INT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 1,
        allowed_answer_types VARCHAR(255) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
        max_file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 10485760,
        paper_storage_key VARCHAR(255) NULL,
        paper_original_name VARCHAR(255) NULL,
        paper_mime VARCHAR(120) NULL,
        paper_size_bytes BIGINT UNSIGNED NULL,
        paper_view_allowed TINYINT(1) NOT NULL DEFAULT 1,
        paper_download_allowed TINYINT(1) NOT NULL DEFAULT 1,
        late_submission_allowed TINYINT(1) NOT NULL DEFAULT 1,
        expiry_policy VARCHAR(32) NOT NULL DEFAULT 'auto_submit_latest',
        max_marks DECIMAL(10,2) NULL,
        results_release_at_utc DATETIME NULL,
        recovery_window_start_at_utc DATETIME NULL,
        recovery_window_end_at_utc DATETIME NULL,
        recovery_allowed TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_timed_exam_item (course_id, item_id),
        KEY idx_timed_exam_course_status (course_id, status, deleted_at),
        KEY idx_timed_exam_schedule (status, scheduled_start_at_utc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS timed_exam_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        timed_exam_id BIGINT UNSIGNED NOT NULL,
        student_id INT NOT NULL,
        attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
        active_key VARCHAR(64) NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'not_started',
        opens_at_utc DATETIME NOT NULL,
        closes_at_utc DATETIME NOT NULL,
        grace_closes_at_utc DATETIME NOT NULL,
        started_at_utc DATETIME NULL,
        submitted_at_utc DATETIME NULL,
        expired_at_utc DATETIME NULL,
        latest_version_id BIGINT UNSIGNED NULL,
        is_late TINYINT(1) NOT NULL DEFAULT 0,
        grade DECIMAL(10,2) NULL,
        feedback TEXT NULL,
        results_released_at_utc DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_timed_exam_attempt_number (timed_exam_id, student_id, attempt_number),
        UNIQUE KEY uq_timed_exam_active (timed_exam_id, student_id, active_key),
        KEY idx_timed_exam_attempt_student (student_id, state),
        KEY idx_timed_exam_attempt_exam (timed_exam_id, state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS timed_exam_submission_versions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        attempt_id BIGINT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        storage_key VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size_bytes BIGINT UNSIGNED NOT NULL,
        sha256 CHAR(64) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'uploaded',
        is_late TINYINT(1) NOT NULL DEFAULT 0,
        uploaded_at_utc DATETIME NOT NULL,
        submitted_at_utc DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_timed_exam_version (attempt_id, version_number),
        KEY idx_timed_exam_version_status (attempt_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $sql) {
    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to create Timed Exam schema: ' . $conn->error);
    }
}

echo "Timed Exam schema is ready.\n";
