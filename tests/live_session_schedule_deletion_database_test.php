<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
require_once $root . '/connection/config.php';
require_once $root . '/inc/LiveSessions.php';

$admin = db();
$hostValue = (string) $host;
$userValue = (string) $user;
$passValue = (string) $pass;
$database = 'mmh_live_schedule_delete_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$query = static function (mysqli $conn, string $sql): mysqli_result|bool {
    $result = $conn->query($sql);
    if ($result === false) throw new RuntimeException($conn->error ?: 'Test database query failed.');
    return $result;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8 COLLATE utf8_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated Live Session test database.');
    $query($admin, "CREATE TABLE courses (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL UNIQUE, course_title VARCHAR(190) NOT NULL, course_state VARCHAR(20) NOT NULL DEFAULT 'public', archived_at DATETIME NULL, my_courses_group_key VARCHAR(32) NULL)");
    $query($admin, "INSERT INTO courses (course_id,course_title) VALUES ('course-a','Course A'),('course-b','Course B')");
    // Begin with the pre-release shape so the migration is exercised against an existing table.
    $query($admin, "CREATE TABLE course_live_schedules (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, schedule_id VARCHAR(40) NOT NULL UNIQUE, course_id VARCHAR(40) NOT NULL, title VARCHAR(190) NULL, day_of_week TINYINT UNSIGNED NOT NULL, start_time TIME NOT NULL, duration_minutes INT UNSIGNED NOT NULL DEFAULT 90, timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Riyadh', teams_url TEXT NOT NULL, teams_meeting_ref VARCHAR(190) NULL, academic_period VARCHAR(120) NULL, effective_start_date DATE NOT NULL, effective_end_date DATE NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, sort_order INT UNSIGNED NOT NULL DEFAULT 0, admin_notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    ob_start();
    require $root . '/database/migrations/20261018_add_live_schedule_deleted_at.php';
    require $root . '/database/migrations/20261018_add_live_schedule_deleted_at.php';
    ob_end_clean();
    $assert(mmh_column_exists($admin, 'course_live_schedules', 'deleted_at'), 'The additive schedule deletion migration did not add deleted_at.');

    $query($admin, "CREATE TABLE live_session_occurrences (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, occurrence_id VARCHAR(40) NOT NULL UNIQUE, schedule_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, scheduled_start_at DATETIME NOT NULL, scheduled_end_at DATETIME NOT NULL, timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Riyadh', status VARCHAR(20) NOT NULL DEFAULT 'scheduled', teams_url_snapshot TEXT NOT NULL, replacement_url TEXT NULL, change_note TEXT NULL, actual_start_at DATETIME NULL, actual_end_at DATETIME NULL, teams_attendance_report_id VARCHAR(190) NULL, teams_imported_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_schedule_start(schedule_id,scheduled_start_at)) ENGINE=InnoDB");
    $query($admin, "CREATE TABLE live_session_join_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, occurrence_id VARCHAR(40) NOT NULL, clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, minutes_from_start INT NULL, source VARCHAR(40) NOT NULL DEFAULT 'student_dashboard', user_agent_category VARCHAR(40) NULL)");
    $query($admin, "CREATE TABLE live_session_attendance (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, occurrence_id VARCHAR(40) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'unknown', first_join_clicked_at DATETIME NULL, last_join_clicked_at DATETIME NULL, confirmed_source VARCHAR(32) NOT NULL DEFAULT 'none', teacher_note TEXT NULL, external_attendee_ref VARCHAR(190) NULL, confirmed_by INT NULL, confirmed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_student_occurrence(user_id,occurrence_id))");

    $tomorrow = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));
    $day = (int) $tomorrow->format('w');
    $startDate = $tomorrow->format('Y-m-d');
    $insertSchedule = static function (mysqli $conn, string $id, string $course, int $enabled, string $date) use ($query, $day): void {
        $query($conn, "INSERT INTO course_live_schedules (schedule_id,course_id,title,day_of_week,start_time,duration_minutes,timezone,teams_url,effective_start_date,enabled) VALUES ('{$id}','{$course}','{$id}',{$day},'23:00:00',90,'UTC','https://teams.microsoft.com/l/meetup-join/test','{$date}',{$enabled})");
    };
    $insertSchedule($admin, 'to-delete', 'course-a', 1, $startDate);
    $insertSchedule($admin, 'to-disable', 'course-a', 0, $startDate);
    $insertSchedule($admin, 'other-active', 'course-b', 1, $startDate);

    $disabledRows = mmh_live_schedule_rows($admin, 'course-a');
    $assert(in_array('to-disable', array_column($disabledRows, 'schedule_id'), true), 'A disabled schedule disappeared from the normal schedule list.');
    mmh_live_generate_occurrences($admin, 0, 45, 'course-a');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM live_session_occurrences WHERE schedule_id='to-disable'")->fetch_assoc()['n'] === 0, 'A disabled schedule generated future occurrences.');
    $disabled = $admin->query("SELECT * FROM course_live_schedules WHERE schedule_id='to-disable'")->fetch_assoc();
    [$enabled, $enableMessage] = mmh_live_save_schedule($admin, array_merge($disabled, ['start_time' => substr((string) $disabled['start_time'], 0, 5), 'enabled' => 1]));
    $assert($enabled, 'A disabled schedule could not be enabled again: ' . $enableMessage);
    $assert(in_array('to-disable', array_column(mmh_live_schedule_rows($admin, 'course-a'), 'schedule_id'), true), 'Enabling a disabled schedule did not restore its visible row.');

    mmh_live_generate_occurrences($admin, 0, 45);
    $future = $admin->query("SELECT occurrence_id FROM live_session_occurrences WHERE schedule_id='to-delete' AND scheduled_start_at >= UTC_TIMESTAMP() AND status='scheduled' ORDER BY scheduled_start_at LIMIT 1")->fetch_assoc();
    $assert(is_array($future), 'The active recurring schedule did not generate its future occurrence.');

    $pastStart = gmdate('Y-m-d H:i:s', time() - 172800);
    $pastEnd = gmdate('Y-m-d H:i:s', time() - 167400);
    $query($admin, "INSERT INTO live_session_occurrences (occurrence_id,schedule_id,course_id,scheduled_start_at,scheduled_end_at,timezone,status,teams_url_snapshot,replacement_url,actual_end_at) VALUES ('past-completed','to-delete','course-a','{$pastStart}','{$pastEnd}','UTC','completed','https://teams.microsoft.com/l/meetup-join/test','https://recordings.example.test/past','{$pastEnd}')");
    $query($admin, "INSERT INTO live_session_attendance (user_id,course_id,occurrence_id,status) VALUES (21,'course-a','past-completed','present')");
    $query($admin, "INSERT INTO live_session_join_events (user_id,course_id,occurrence_id) VALUES (21,'course-a','past-completed')");

    [$deleted, $deleteMessage] = mmh_live_delete_schedule($admin, 'to-delete');
    $assert($deleted, 'Deleting the recurring schedule failed: ' . $deleteMessage);
    [$deletedAgain] = mmh_live_delete_schedule($admin, 'to-delete');
    $assert($deletedAgain, 'Repeated Delete was not idempotent.');

    $scheduleState = $admin->query("SELECT enabled,deleted_at FROM course_live_schedules WHERE schedule_id='to-delete'")->fetch_assoc();
    $assert((int) $scheduleState['enabled'] === 0 && !empty($scheduleState['deleted_at']), 'Delete did not disable and mark the series deleted.');
    $assert(!in_array('to-delete', array_column(mmh_live_schedule_rows($admin), 'schedule_id'), true), 'Deleted schedule still appears in the normal list.');

    $deletedSchedule = $admin->query("SELECT * FROM course_live_schedules WHERE schedule_id='to-delete'")->fetch_assoc();
    [$reenabled, $reenableMessage] = mmh_live_save_schedule($admin, array_merge($deletedSchedule, ['start_time' => substr((string) $deletedSchedule['start_time'], 0, 5), 'enabled' => 1]));
    $assert(!$reenabled, 'An ordinary Enable action restored a deleted schedule.');
    $assert((int) $admin->query("SELECT enabled FROM course_live_schedules WHERE schedule_id='to-delete'")->fetch_assoc()['enabled'] === 0, 'A deleted schedule was re-enabled despite rejection.');

    mmh_live_generate_occurrences($admin, 0, 45);
    $remaining = $admin->query("SELECT status FROM live_session_occurrences WHERE occurrence_id='" . $admin->real_escape_string((string) $future['occurrence_id']) . "'")->fetch_assoc();
    $assert(($remaining['status'] ?? '') === 'deleted', 'The future scheduled occurrence was not removed safely.');
    $deletedOccurrence = $admin->query("SELECT occurrence_id,scheduled_start_at,scheduled_end_at,status,teams_url_snapshot FROM live_session_occurrences WHERE occurrence_id='" . $admin->real_escape_string((string) $future['occurrence_id']) . "'")->fetch_assoc();
    $assert(empty(mmh_live_join_state($deletedOccurrence)['active']), 'A future occurrence from a deleted series remained joinable.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM live_session_occurrences WHERE schedule_id='to-delete' AND scheduled_start_at >= UTC_TIMESTAMP() AND status='scheduled'")->fetch_assoc()['n'] === 0, 'A deleted schedule generated a future session.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM live_session_occurrences WHERE schedule_id='other-active' AND scheduled_start_at >= UTC_TIMESTAMP() AND status='scheduled'")->fetch_assoc()['n'] > 0, 'Another active schedule stopped generating occurrences.');
    $history = $admin->query("SELECT o.replacement_url, a.status AS attendance_status, j.id AS join_event_id FROM live_session_occurrences o LEFT JOIN live_session_attendance a ON a.occurrence_id=o.occurrence_id LEFT JOIN live_session_join_events j ON j.occurrence_id=o.occurrence_id WHERE o.occurrence_id='past-completed'")->fetch_assoc();
    $assert(($history['replacement_url'] ?? '') === 'https://recordings.example.test/past' && ($history['attendance_status'] ?? '') === 'present' && !empty($history['join_event_id']), 'Historical occurrence, recording reference, attendance, or join history was lost.');
    $pastRows = mmh_live_occurrences($admin, 'course-a', -10, 45);
    $assert(in_array('past-completed', array_column($pastRows, 'occurrence_id'), true), 'Historical occurrence queries cannot reach the retained schedule/occurrence.');

    echo "Live Session schedule Delete/Disable separation and history-preservation checks passed.\n";
} finally {
    if (ob_get_level() > 0) ob_end_clean();
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli && preg_match('/\\Ammh_live_schedule_delete_test_[0-9]+_[a-f0-9]{8}\\z/', $database)) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`');
        $cleanup->close();
    }
}
