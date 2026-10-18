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
$database = 'mmh_live_reassign_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
    $query($admin, "INSERT INTO courses (course_id,course_title,course_state,my_courses_group_key) VALUES ('cam-p1','Cambridge Pure 1','public','cambridge_a_level'),('cam-m1','Cambridge Mechanics 1','public','cambridge_a_level'),('cam-p3','Cambridge Pure 3','public','cambridge_a_level'),('edx-p1','Edexcel Pure 1','public','edexcel_a_level'),('edx-p2','Edexcel Pure 2','public','edexcel_a_level'),('ordinary','Ordinary Course','public',NULL),('draft','Draft Paper','draft','cambridge_a_level')");
    $query($admin, "CREATE TABLE course_live_schedules (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, schedule_id VARCHAR(40) NOT NULL UNIQUE, course_id VARCHAR(40) NOT NULL, title VARCHAR(190) NULL, day_of_week TINYINT UNSIGNED NOT NULL, start_time TIME NOT NULL, duration_minutes INT UNSIGNED NOT NULL DEFAULT 90, timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Riyadh', teams_url TEXT NOT NULL, teams_meeting_ref VARCHAR(190) NULL, academic_period VARCHAR(120) NULL, effective_start_date DATE NOT NULL, effective_end_date DATE NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, deleted_at DATETIME NULL DEFAULT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, admin_notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $query($admin, "CREATE TABLE live_session_occurrences (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, occurrence_id VARCHAR(40) NOT NULL UNIQUE, schedule_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, scheduled_start_at DATETIME NOT NULL, scheduled_end_at DATETIME NOT NULL, timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Riyadh', status VARCHAR(20) NOT NULL DEFAULT 'scheduled', teams_url_snapshot TEXT NOT NULL, replacement_url TEXT NULL, change_note TEXT NULL, actual_start_at DATETIME NULL, actual_end_at DATETIME NULL, teams_attendance_report_id VARCHAR(190) NULL, teams_imported_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_schedule_start(schedule_id,scheduled_start_at))");
    $query($admin, "CREATE TABLE course_sections (section_id VARCHAR(40) PRIMARY KEY, release_occurrence_id VARCHAR(64) NULL)");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL)");
    $query($admin, "CREATE TABLE live_session_join_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, occurrence_id VARCHAR(40) NOT NULL, clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, minutes_from_start INT NULL, source VARCHAR(40) NOT NULL DEFAULT 'student_dashboard', user_agent_category VARCHAR(40) NULL)");
    $query($admin, "CREATE TABLE live_session_attendance (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, occurrence_id VARCHAR(40) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'unknown', first_join_clicked_at DATETIME NULL, last_join_clicked_at DATETIME NULL, confirmed_source VARCHAR(32) NOT NULL DEFAULT 'none')");
    mmh_live_ensure_schema($admin);

    $tomorrow = new DateTimeImmutable('tomorrow 23:00:00', new DateTimeZone('Asia/Riyadh'));
    $tomorrowSql = $tomorrow->format('Y-m-d H:i:s');
    $tomorrowDate = $tomorrow->format('Y-m-d');
    $day = (int) $tomorrow->format('w');
    $query($admin, "INSERT INTO course_live_schedules (schedule_id,course_id,title,day_of_week,start_time,duration_minutes,timezone,teams_url,effective_start_date) VALUES ('cam-slot','cam-p1','Weekly Cambridge Slot',{$day},'23:00:00',90,'Asia/Riyadh','https://teams.microsoft.com/l/meetup-join/test','{$tomorrowDate}')");
    $query($admin, "INSERT INTO course_live_schedules (schedule_id,course_id,title,day_of_week,start_time,duration_minutes,timezone,teams_url,effective_start_date) VALUES ('edx-slot','edx-p1','Weekly Edexcel Slot',{$day},'23:00:00',90,'Asia/Riyadh','https://teams.microsoft.com/l/meetup-join/test','{$tomorrowDate}')");

    $testOccurrenceOffset = 0;
    $insertOccurrence = static function (mysqli $conn, string $id, string $schedule, string $course, string $status = 'scheduled', ?string $actualStart = null) use ($query, $tomorrowSql, &$testOccurrenceOffset): void {
        $start = (new DateTimeImmutable($tomorrowSql, new DateTimeZone('UTC')))->modify('+' . (1000 + $testOccurrenceOffset++) . ' minutes')->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+90 minutes')->format('Y-m-d H:i:s');
        $actualSql = $actualStart === null ? 'NULL' : "'{$actualStart}'";
        $query($conn, "INSERT INTO live_session_occurrences (occurrence_id,schedule_id,course_id,scheduled_start_at,scheduled_end_at,timezone,status,teams_url_snapshot,actual_start_at) VALUES ('{$id}','{$schedule}','{$course}','{$start}','{$end}','UTC','{$status}','https://teams.microsoft.com/l/meetup-join/test',{$actualSql})");
    };

    $insertOccurrence($admin, 'allowed-m1', 'cam-slot', 'cam-p1');
    [$ok, $message] = mmh_live_reassign_occurrence_course($admin, 'allowed-m1', 'cam-m1');
    $assert($ok, 'Cambridge Paper reassignment failed: ' . $message);
    $assert(($admin->query("SELECT course_id FROM live_session_occurrences WHERE occurrence_id='allowed-m1'")->fetch_assoc()['course_id'] ?? '') === 'cam-m1', 'The occurrence did not retain its newly assigned Course.');
    $insertOccurrence($admin, 'allowed-edx', 'edx-slot', 'edx-p1');
    [$edexcelOk, $edexcelMessage] = mmh_live_reassign_occurrence_course($admin, 'allowed-edx', 'edx-p2');
    $assert($edexcelOk, 'Edexcel Paper reassignment failed: ' . $edexcelMessage);

    foreach ([
        ['cross-board', 'edx-p2', 'Cross-board reassignment was allowed.'],
        ['ungrouped', 'ordinary', 'Grouped-to-ungrouped reassignment was allowed.'],
        ['invalid-course', 'missing', 'An invalid target Course was allowed.'],
        ['draft-course', 'draft', 'An unavailable target Course was allowed.'],
    ] as [$id, $target, $message]) {
        $insertOccurrence($admin, $id, 'cam-slot', 'cam-p1');
        [$caseOk] = mmh_live_reassign_occurrence_course($admin, $id, $target);
        $assert(!$caseOk, $message);
    }
    $insertOccurrence($admin, 'completed', 'cam-slot', 'cam-p1', 'completed');
    [$caseOk] = mmh_live_reassign_occurrence_course($admin, 'completed', 'cam-m1');
    $assert(!$caseOk, 'Completed occurrence was reassigned.');
    $insertOccurrence($admin, 'started', 'cam-slot', 'cam-p1', 'scheduled', $tomorrowSql);
    [$caseOk] = mmh_live_reassign_occurrence_course($admin, 'started', 'cam-m1');
    $assert(!$caseOk, 'Started occurrence was reassigned.');
    [$caseOk] = mmh_live_reassign_occurrence_course($admin, 'not-present', 'cam-m1');
    $assert(!$caseOk, 'Missing occurrence was reassigned.');

    foreach (['join-history', 'attendance-history', 'section-reference'] as $id) $insertOccurrence($admin, $id, 'cam-slot', 'cam-p1');
    $query($admin, "INSERT INTO live_session_join_events (user_id,course_id,occurrence_id) VALUES (11,'cam-p1','join-history')");
    $query($admin, "INSERT INTO live_session_attendance (user_id,course_id,occurrence_id) VALUES (11,'cam-p1','attendance-history')");
    $query($admin, "INSERT INTO course_sections (section_id,release_occurrence_id) VALUES ('linked-section','section-reference')");
    foreach (['join-history', 'attendance-history', 'section-reference'] as $id) {
        [$caseOk] = mmh_live_reassign_occurrence_course($admin, $id, 'cam-m1');
        $assert(!$caseOk, "Occurrence with {$id} was reassigned.");
    }

    $joinStart = gmdate('Y-m-d H:i:s', time() + 600);
    $joinEnd = gmdate('Y-m-d H:i:s', time() + 600 + 5400);
    $staleStart = gmdate('Y-m-d H:i:s', time() + 720);
    $staleEnd = gmdate('Y-m-d H:i:s', time() + 720 + 5400);
    $unenrolledStart = gmdate('Y-m-d H:i:s', time() + 840);
    $unenrolledEnd = gmdate('Y-m-d H:i:s', time() + 840 + 5400);
    $query($admin, "INSERT INTO live_session_occurrences (occurrence_id,schedule_id,course_id,scheduled_start_at,scheduled_end_at,timezone,status,teams_url_snapshot) VALUES ('join-locked','cam-slot','cam-p1','{$joinStart}','{$joinEnd}','UTC','scheduled','https://teams.microsoft.com/l/meetup-join/test'),('stale-join','cam-slot','cam-p1','{$staleStart}','{$staleEnd}','UTC','scheduled','https://teams.microsoft.com/l/meetup-join/test'),('unenrolled-join','cam-slot','cam-p1','{$unenrolledStart}','{$unenrolledEnd}','UTC','scheduled','https://teams.microsoft.com/l/meetup-join/test')");
    $query($admin, "INSERT INTO course_logs (user_id,course_id) VALUES (31,'cam-p1')");
    $joinOccurrence = mmh_live_occurrence($admin, 'join-locked');
    $assert(is_array($joinOccurrence) && mmh_live_record_join($admin, 31, $joinOccurrence, 'test'), 'An eligible enrolled Student join could not be recorded.');
    [$joinReassignOk] = mmh_live_reassign_occurrence_course($admin, 'join-locked', 'cam-m1');
    $assert(!$joinReassignOk, 'A session with a newly recorded join was reassigned.');
    $staleOccurrence = mmh_live_occurrence($admin, 'stale-join');
    $query($admin, "UPDATE live_session_occurrences SET course_id='cam-m1' WHERE occurrence_id='stale-join'");
    $assert(!mmh_live_record_join($admin, 31, (array) $staleOccurrence, 'test'), 'A stale join request was recorded against the prior Course.');
    $unenrolledOccurrence = mmh_live_occurrence($admin, 'unenrolled-join');
    $assert(!mmh_live_record_join($admin, 99, (array) $unenrolledOccurrence, 'test'), 'An unenrolled Student join was recorded.');

    $inserted = mmh_live_generate_occurrences($admin, 0, 45, 'cam-p1');
    $assert($inserted > 0, 'Weekly occurrence generation did not create sessions.');
    $generated = $admin->query("SELECT occurrence_id,scheduled_start_at FROM live_session_occurrences WHERE schedule_id='cam-slot' AND scheduled_start_at > UTC_TIMESTAMP() AND status='scheduled' AND actual_start_at IS NULL ORDER BY scheduled_start_at LIMIT 1")->fetch_assoc();
    $assert(is_array($generated), 'Weekly occurrence generation did not create a future occurrence.');
    [$generatedOk, $generatedMessage] = mmh_live_reassign_occurrence_course($admin, (string) $generated['occurrence_id'], 'cam-p3');
    $assert($generatedOk, 'Generated occurrence could not be reassigned: ' . $generatedMessage);
    mmh_live_generate_occurrences($admin, 0, 45, 'cam-p1');
    $countAfterRepeat = (int) $admin->query("SELECT COUNT(*) AS n FROM live_session_occurrences WHERE schedule_id='cam-slot'")->fetch_assoc()['n'];
    mmh_live_generate_occurrences($admin, 0, 45, 'cam-p1');
    $countAfterSecondRepeat = (int) $admin->query("SELECT COUNT(*) AS n FROM live_session_occurrences WHERE schedule_id='cam-slot'")->fetch_assoc()['n'];
    $assert($countAfterRepeat === $countAfterSecondRepeat, 'Repeat generation created duplicate occurrences.');
    $afterGeneration = $admin->query("SELECT course_id FROM live_session_occurrences WHERE occurrence_id='" . $admin->real_escape_string((string) $generated['occurrence_id']) . "'")->fetch_assoc();
    $assert(($afterGeneration['course_id'] ?? '') === 'cam-p3', 'Occurrence generation overwrote a manual Course assignment.');
    $generatedOccurrence = mmh_live_occurrence($admin, (string) $generated['occurrence_id']);
    $assert(($generatedOccurrence['timezone'] ?? '') === 'Asia/Riyadh' && mmh_live_display_time($generatedOccurrence, 'g:i A') === '11:00 PM', 'Occurrence generation changed schedule timezone behavior.');

    $query($admin, "INSERT INTO course_logs (user_id,course_id) VALUES (21,'cam-p3'),(22,'cam-p1')");
    $targetVisible = mmh_live_occurrences($admin, 'cam-p3', 0, 45, 21);
    $originalVisible = mmh_live_occurrences($admin, 'cam-p1', 0, 45, 22);
    $targetHas = in_array((string) $generated['occurrence_id'], array_column($targetVisible, 'occurrence_id'), true);
    $originalHas = in_array((string) $generated['occurrence_id'], array_column($originalVisible, 'occurrence_id'), true);
    $assert($targetHas && !$originalHas, 'Student visibility did not follow enrollment in the occurrence actual Course (target=' . count($targetVisible) . ', original=' . count($originalVisible) . ', assigned=' . (string) ($afterGeneration['course_id'] ?? 'missing') . ').');

    $coursePage = (string) file_get_contents($root . '/views/user/course.php');
    $assert(str_contains($coursePage, 'Upcoming Live Session') && str_contains($coursePage, '/user/live-session/join/'), 'Course page is missing the protected Upcoming Live Session join link.');

    echo "Live Session occurrence reassignment, recurrence preservation, and actual-Course visibility checks passed.\n";
} finally {
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli && preg_match('/\\Ammh_live_reassign_test_[0-9]+_[a-f0-9]{8}\\z/', $database)) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`');
        $cleanup->close();
    }
}
