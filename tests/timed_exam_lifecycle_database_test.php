<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/connection/config.php';

$dbHost = (string) $host;
$dbUser = (string) $user;
$dbPass = (string) $pass;
$admin = db();
$testDatabase = 'mmh_timed_exam_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_timed_exam_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) {
    throw new RuntimeException('Unsafe test database name.');
}

$answerFiles = [];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$query = static function (mysqli $conn, string $sql): mysqli_result|bool {
    $result = $conn->query($sql);
    if ($result === false) throw new RuntimeException($conn->error ?: 'Database test query failed.');
    return $result;
};

try {
    $query($admin, 'CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    if (!$admin->select_db($testDatabase)) throw new RuntimeException('Unable to select isolated Timed Exam test database.');
    $GLOBALS['conn'] = $admin;

    $schema = [
        "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, archived_at DATETIME NULL, course_state VARCHAR(16) NOT NULL)",
        "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NOT NULL, archived_at DATETIME NULL, status VARCHAR(16) NULL, UNIQUE KEY uq_course_item(course_id,item_id))",
        "CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(120) NOT NULL, full_name VARCHAR(190) NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, archived_at DATETIME NULL)",
        "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, purchase_date DATETIME NULL, KEY idx_enrollment(course_id,user_id))",
        "CREATE TABLE timed_exams (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NOT NULL, title VARCHAR(190) NOT NULL, status VARCHAR(16) NOT NULL, scheduled_start_at_utc DATETIME NULL, duration_minutes INT UNSIGNED NOT NULL, grace_minutes INT UNSIGNED NOT NULL, max_attempts INT UNSIGNED NOT NULL, late_submission_allowed TINYINT(1) NOT NULL, expiry_policy VARCHAR(32) NOT NULL, max_marks DECIMAL(10,2) NULL, results_release_at_utc DATETIME NULL, recovery_allowed TINYINT(1) NOT NULL DEFAULT 0, deleted_at DATETIME NULL, roster_finalized_at_utc DATETIME NULL)",
        "CREATE TABLE timed_exam_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, timed_exam_id BIGINT UNSIGNED NOT NULL, student_id INT NOT NULL, attempt_number INT UNSIGNED NOT NULL DEFAULT 1, attempt_scope VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'primary', active_key VARCHAR(64) NULL, state VARCHAR(24) NOT NULL DEFAULT 'not_started', opens_at_utc DATETIME NOT NULL, closes_at_utc DATETIME NOT NULL, grace_closes_at_utc DATETIME NOT NULL, started_at_utc DATETIME NULL, submitted_at_utc DATETIME NULL, expired_at_utc DATETIME NULL, latest_version_id BIGINT UNSIGNED NULL, is_late TINYINT(1) NOT NULL DEFAULT 0, grade DECIMAL(10,2) NULL, feedback TEXT NULL, results_released_at_utc DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_scope(timed_exam_id,student_id,attempt_scope), UNIQUE KEY uq_number(timed_exam_id,student_id,attempt_number))",
        "CREATE TABLE timed_exam_submission_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_id BIGINT UNSIGNED NOT NULL, version_number INT UNSIGNED NOT NULL, original_filename VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size_bytes BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NULL, status VARCHAR(24) NOT NULL DEFAULT 'uploaded', is_late TINYINT(1) NOT NULL DEFAULT 0, uploaded_at_utc DATETIME NOT NULL, submitted_at_utc DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_version(attempt_id,version_number))",
        "CREATE TABLE notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(190) NOT NULL, message TEXT NOT NULL, status TINYINT(1) NOT NULL DEFAULT 0)",
    ];
    foreach ($schema as $statement) $query($admin, $statement);

    require_once dirname(__DIR__) . '/inc/TimedExam.php';

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $openStart = $now->modify('-5 minutes')->format('Y-m-d H:i:s');
    $releaseAt = $now->modify('+2 hours')->format('Y-m-d H:i:s');
    $query($admin, "INSERT INTO courses VALUES ('course-one', NULL, 'private'), ('course-two', NULL, 'public')");
    $query($admin, "INSERT INTO course_items (course_id,item_id,archived_at,status) VALUES ('course-one','exam-one',NULL,'published'),('course-two','exam-two',NULL,'published')");
    for ($id = 1; $id <= 10; $id++) {
        $stmt = $admin->prepare("INSERT INTO users (user_id,username,full_name,role,status,archived_at) VALUES (?, ?, ?, 'user', '1', NULL)");
        $username = 'student' . $id;
        $fullName = 'Student ' . $id;
        $stmt->bind_param('iss', $id, $username, $fullName);
        $stmt->execute();
        $stmt->close();
    }
    foreach (range(1, 8) as $id) $query($admin, "INSERT INTO course_logs (user_id,course_id,purchase_date) VALUES ({$id},'course-one',NULL)");
    foreach ([9, 10] as $id) $query($admin, "INSERT INTO course_logs (user_id,course_id,purchase_date) VALUES ({$id},'course-two',NULL)");

    $examInsert = $admin->prepare("INSERT INTO timed_exams (course_id,item_id,title,status,scheduled_start_at_utc,duration_minutes,grace_minutes,max_attempts,late_submission_allowed,expiry_policy,max_marks,results_release_at_utc) VALUES ('course-one','exam-one','Lifecycle Exam','published',?,60,10,2,1,'auto_submit_latest',100,?)");
    $examInsert->bind_param('ss', $openStart, $releaseAt);
    $examInsert->execute();
    $examOneId = (int) $examInsert->insert_id;
    $examInsert->close();
    $lateEnrollment = $now->modify('+1 day')->format('Y-m-d H:i:s');
    $lateEnrollmentStmt = $admin->prepare("INSERT INTO course_logs (user_id,course_id,purchase_date) VALUES (8,'course-two',?)");
    $lateEnrollmentStmt->bind_param('s', $lateEnrollment);
    $lateEnrollmentStmt->execute();
    $lateEnrollmentStmt->close();
    $expiredStart = $now->modify('-2 hours')->format('Y-m-d H:i:s');
    $examInsert = $admin->prepare("INSERT INTO timed_exams (course_id,item_id,title,status,scheduled_start_at_utc,duration_minutes,grace_minutes,max_attempts,late_submission_allowed,expiry_policy,max_marks,results_release_at_utc) VALUES ('course-two','exam-two','Roster Exam','published',?,20,0,3,0,'auto_submit_latest',100,NULL)");
    $examInsert->bind_param('s', $expiredStart);
    $examInsert->execute();
    $examTwoId = (int) $examInsert->insert_id;
    $examInsert->close();

    $loadExam = static function (mysqli $conn, int $id): array {
        $stmt = $conn->prepare('SELECT * FROM timed_exams WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    };
    $examOne = $loadExam($admin, $examOneId);
    $examTwo = $loadExam($admin, $examTwoId);

    $createAttempt = static function (mysqli $conn, array $exam, int $studentId, string $scope = 'primary'): array {
        $copy = $exam;
        $copy['_attempt_scope'] = $scope;
        $attempt = mmh_timed_exam_create_attempt($conn, $copy, $studentId);
        if (!$attempt) throw new RuntimeException('Unable to create test attempt for student ' . $studentId);
        return $attempt;
    };
    $createExpiredAttempt = static function (mysqli $conn, array $exam, int $studentId): array {
        $window = mmh_timed_exam_window($exam);
        $opens = $window['opens_at']->format('Y-m-d H:i:s');
        $closes = $window['closes_at']->format('Y-m-d H:i:s');
        $grace = $window['grace_closes_at']->format('Y-m-d H:i:s');
        $key = bin2hex(random_bytes(8));
        $stmt = $conn->prepare("INSERT INTO timed_exam_attempts (timed_exam_id,student_id,attempt_number,attempt_scope,active_key,state,opens_at_utc,closes_at_utc,grace_closes_at_utc,started_at_utc) VALUES (?,?,1,'primary',?,'in_progress',?,?,?,?)");
        $examId = (int) $exam['id'];
        $stmt->bind_param('iisssss', $examId, $studentId, $key, $opens, $closes, $grace, $opens);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        $attempt = mmh_timed_exam_student_attempt($conn, $studentId, $examId, false, 'primary');
        if (!$attempt || (int) $attempt['id'] !== $id) throw new RuntimeException('Unable to create expired test attempt.');
        return $attempt;
    };
    $addVersion = static function (mysqli $conn, int $attemptId, int $number, string $fileTag, string $status = 'uploaded') use (&$answerFiles): int {
        $relative = 'storage/private/timed-exams/answers/lifecycle-' . $fileTag . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $absolute = dirname(__DIR__) . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0700, true) && !is_dir(dirname($absolute))) throw new RuntimeException('Unable to create test answer directory.');
        if (file_put_contents($absolute, "%PDF-1.4\nTimed Exam lifecycle fixture\n") === false) throw new RuntimeException('Unable to create test answer.');
        $answerFiles[] = $absolute;
        $uploaded = gmdate('Y-m-d H:i:s');
        $name = $fileTag . '.pdf';
        $mime = 'application/pdf';
        $size = (int) filesize($absolute);
        $sha = hash_file('sha256', $absolute);
        $stmt = $conn->prepare('INSERT INTO timed_exam_submission_versions (attempt_id,version_number,original_filename,storage_key,mime_type,file_size_bytes,sha256,status,is_late,uploaded_at_utc) VALUES (?,?,?,?,?,?,?, ?,0,?)');
        $stmt->bind_param('iisssisss', $attemptId, $number, $name, $relative, $mime, $size, $sha, $status, $uploaded);
        $stmt->execute();
        $versionId = (int) $stmt->insert_id;
        $stmt->close();
        $update = $conn->prepare("UPDATE timed_exam_attempts SET latest_version_id=?, state='uploaded' WHERE id=?");
        $update->bind_param('ii', $versionId, $attemptId);
        $update->execute();
        $update->close();
        return $versionId;
    };
    $attemptRow = static function (mysqli $conn, int $id): array {
        $result = $conn->query('SELECT * FROM timed_exam_attempts WHERE id=' . $id);
        return $result ? ($result->fetch_assoc() ?: []) : [];
    };
    $notificationCount = static function (mysqli $conn, int $studentId, string $title): int {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id=? AND title=?');
        $stmt->bind_param('is', $studentId, $title);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $count;
    };

    // 1. Explicit submission remains explicit and terminal.
    $attempt1 = $createAttempt($admin, $examOne, 1);
    $version1 = $addVersion($admin, (int) $attempt1['id'], 1, 'explicit');
    [$submitted] = mmh_timed_exam_submit($admin, $examOne, 1);
    $assert($submitted && ($attemptRow($admin, (int) $attempt1['id'])['state'] ?? '') === 'submitted', 'Explicit submission did not persist submitted.');
    $assert((string) ($admin->query('SELECT status FROM timed_exam_submission_versions WHERE id=' . $version1)->fetch_assoc()['status'] ?? '') === 'final', 'Explicit submission did not finalize exactly its uploaded version.');

    // 2-4. Expiry chooses the latest valid upload, or persists no_submission.
    $future = $now->modify('+2 hours');
    $attempt2 = $createAttempt($admin, $examOne, 2);
    $version2 = $addVersion($admin, (int) $attempt2['id'], 1, 'auto');
    $auto = mmh_timed_exam_finalize_attempt($admin, $examOne, 2, (int) $attempt2['id'], $future);
    $assert(($auto['state'] ?? '') === 'auto_submitted', 'Expired uploaded answer was not auto-submitted.');
    $assert((string) ($admin->query('SELECT status FROM timed_exam_submission_versions WHERE id=' . $version2)->fetch_assoc()['status'] ?? '') === 'auto_submitted', 'Auto-submitted version was not persisted.');
    $attempt3 = $createAttempt($admin, $examOne, 3);
    $oldVersion = $addVersion($admin, (int) $attempt3['id'], 1, 'old');
    $latestVersion = $addVersion($admin, (int) $attempt3['id'], 2, 'latest');
    $multi = mmh_timed_exam_finalize_attempt($admin, $examOne, 3, (int) $attempt3['id'], $future);
    $assert(($multi['state'] ?? '') === 'auto_submitted', 'Multiple-version attempt was not finalized.');
    $statuses = $admin->query('SELECT id,status FROM timed_exam_submission_versions WHERE id IN (' . $oldVersion . ',' . $latestVersion . ') ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $assert(($statuses[0]['status'] ?? '') === 'uploaded' && ($statuses[1]['status'] ?? '') === 'auto_submitted', 'Finalizer did not select only the latest uploaded version.');
    $attempt4 = $createAttempt($admin, $examOne, 4);
    $none = mmh_timed_exam_finalize_attempt($admin, $examOne, 4, (int) $attempt4['id'], $future);
    $assert(($none['state'] ?? '') === 'no_submission', 'Expired attempt without a file was not no_submission.');
    $attempt8 = $createAttempt($admin, $examOne, 8);
    $missingVersion = $addVersion($admin, (int) $attempt8['id'], 1, 'missing');
    $missingRow = $admin->query('SELECT storage_key FROM timed_exam_submission_versions WHERE id=' . $missingVersion)->fetch_assoc();
    $missingPath = dirname(__DIR__) . '/' . (string) ($missingRow['storage_key'] ?? '');
    if (is_file($missingPath)) unlink($missingPath);
    $invalidFile = mmh_timed_exam_finalize_attempt($admin, $examOne, 8, (int) $attempt8['id'], $future);
    $assert(($invalidFile['state'] ?? '') === 'no_submission', 'Expired attempt with no valid stored file was not no_submission.');

    // 6 and 13. Reruns and pre-existing terminal attempts are immutable.
    $snapshot = $attemptRow($admin, (int) $attempt2['id']);
    $rerun = mmh_timed_exam_finalize_attempt($admin, $examOne, 2, (int) $attempt2['id'], $future);
    $assert(!empty($rerun['already_terminal']) && $attemptRow($admin, (int) $attempt2['id']) === $snapshot, 'Finalizer rerun mutated a terminal attempt.');
    $assert($notificationCount($admin, 2, 'Timed Exam submitted automatically') === 1, 'Auto-submission notification was duplicated.');

    // 8. max_attempts means successful upload/replacement versions, including removed ones.
    $attempt7 = $createAttempt($admin, $examOne, 7);
    $removedVersion = $addVersion($admin, (int) $attempt7['id'], 1, 'removed');
    $addVersion($admin, (int) $attempt7['id'], 2, 'limit');
    $admin->query("UPDATE timed_exam_submission_versions SET status='removed' WHERE id=" . $removedVersion);
    $capacity = mmh_timed_exam_upload_capacity($admin, $examOne, (int) $attempt7['id'], true);
    $assert(($capacity['used'] ?? 0) === 2 && empty($capacity['allowed']), 'Successful upload/replacement limit did not include a removed upload.');

    // 9-10. Grading is private until an explicit or scheduled release, once.
    $saved = mmh_timed_exam_save_grade($admin, $examOne, (int) $attempt1['id'], 84.5, 'Careful working.');
    $assert(!empty($saved['success']), 'Grade save failed.');
    $graded = $attemptRow($admin, (int) $attempt1['id']);
    $assert(($graded['state'] ?? '') === 'graded' && empty($graded['results_released_at_utc']), 'Saving a grade released it early.');
    $assert($notificationCount($admin, 1, 'Timed Exam result available') === 0, 'Saving a grade sent a false release notification.');
    $notDue = mmh_timed_exam_release_result($admin, $examOne, (int) $attempt1['id'], false, false, $now);
    $assert(!empty($notDue['not_due']), 'Result was released before its configured UTC time.');
    $released = mmh_timed_exam_release_result($admin, $examOne, (int) $attempt1['id'], false, false, $now->modify('+3 hours'));
    $releasedAgain = mmh_timed_exam_release_result($admin, $examOne, (int) $attempt1['id'], false, false, $now->modify('+3 hours'));
    $assert(!empty($released['released']) && !empty($releasedAgain['already_released']), 'Scheduled result release was not idempotent.');
    $assert($notificationCount($admin, 1, 'Timed Exam result available') === 1, 'Result release notification did not fire exactly once.');

    // 7. Real concurrent explicit-submit/finalizer race: the row lock permits one winner.
    $attempt6 = $createAttempt($admin, $examOne, 6);
    $addVersion($admin, (int) $attempt6['id'], 1, 'race');
    $admin->close();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) throw new RuntimeException('Unable to create concurrency test barrier.');
    $raceResultFile = sys_get_temp_dir() . '/mmh-timed-exam-race-' . getmypid() . '.json';
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('Unable to fork concurrency test.');
    if ($pid === 0) {
        fclose($pair[0]);
        $child = mysqli_connect($dbHost, $dbUser, $dbPass, $testDatabase);
        fread($pair[1], 1);
        $result = mmh_timed_exam_finalize_attempt($child, $examOne, 6, (int) $attempt6['id'], $future);
        file_put_contents($raceResultFile, json_encode($result));
        fclose($pair[1]);
        $child->close();
        exit(empty($result['success']) ? 1 : 0);
    }
    fclose($pair[1]);
    $admin = mysqli_connect($dbHost, $dbUser, $dbPass, $testDatabase);
    $GLOBALS['conn'] = $admin;
    fwrite($pair[0], '1');
    $explicitRace = mmh_timed_exam_submit($admin, $examOne, 6);
    fclose($pair[0]);
    pcntl_waitpid($pid, $raceStatus);
    $childRace = json_decode((string) file_get_contents($raceResultFile), true);
    unlink($raceResultFile);
    $raceAttempt = $attemptRow($admin, (int) $attempt6['id']);
    $terminalVersions = (int) ($admin->query("SELECT COUNT(*) AS total FROM timed_exam_submission_versions WHERE attempt_id=" . (int) $attempt6['id'] . " AND status IN ('final','auto_submitted')")->fetch_assoc()['total'] ?? 0);
    $assert(pcntl_wexitstatus($raceStatus) === 0 && !empty($childRace['success']) && !empty($explicitRace[0]), 'Concurrent lifecycle operation failed.');
    $assert(in_array((string) ($raceAttempt['state'] ?? ''), ['submitted', 'auto_submitted'], true) && $terminalVersions === 1, 'Explicit submit/finalizer race created an invalid or duplicate terminal state.');

    // 5 and 11. A dry run is non-mutating; apply creates one outcome for every eligible student.
    $attempt10 = $createExpiredAttempt($admin, $examTwo, 10);
    $version10 = $addVersion($admin, (int) $attempt10['id'], 1, 'roster');
    $dryRoster = mmh_timed_exam_finalize_exam_roster($admin, $examTwo, true, $now);
    $assert(($dryRoster['eligible'] ?? 0) === 2 && ($dryRoster['changed'] ?? 0) === 2, 'Roster dry-run did not report the scoped changes.');
    $assert((int) ($admin->query('SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id=' . $examTwoId)->fetch_assoc()['total'] ?? 0) === 1, 'Roster dry-run wrote data.');
    $roster = mmh_timed_exam_finalize_exam_roster($admin, $examTwo, false, $now);
    $assert(($roster['eligible'] ?? 0) === 2 && ($roster['failed'] ?? 0) === 0, 'Roster finalization failed.');
    $student9 = mmh_timed_exam_student_attempt($admin, 9, $examTwoId, false, 'primary');
    $student10 = mmh_timed_exam_student_attempt($admin, 10, $examTwoId, false, 'primary');
    $assert(($student9['state'] ?? '') === 'no_submission' && ($student10['state'] ?? '') === 'auto_submitted', 'Roster did not persist never-opened and uploaded outcomes.');
    $assert((string) ($admin->query('SELECT status FROM timed_exam_submission_versions WHERE id=' . $version10)->fetch_assoc()['status'] ?? '') === 'auto_submitted', 'Roster did not finalize the uploaded answer.');
    $rosterAgain = mmh_timed_exam_finalize_exam_roster($admin, $examTwo, false, $now);
    $assert(($rosterAgain['changed'] ?? -1) === 0 && ($rosterAgain['already_terminal'] ?? 0) === 2, 'Roster rerun was not idempotent.');
    $adminRows = mmh_timed_exam_admin_attempts($admin, $examTwoId);
    $assert(count($adminRows) === 2 && count(array_filter($adminRows, static fn(array $row): bool => in_array($row['state'], ['no_submission', 'auto_submitted'], true))) === 2, 'Admin attempt list did not consume persisted expiry outcomes.');

    // 12. Learning Journey recognizes only real terminal completion, including a Recovery submission.
    $recoveryAttempt = $createAttempt($admin, $examOne, 4, 'recovery:77:88');
    $recoveryVersion = $addVersion($admin, (int) $recoveryAttempt['id'], 1, 'recovery', 'final');
    $admin->query("UPDATE timed_exam_attempts SET state='submitted', submitted_at_utc=UTC_TIMESTAMP(), latest_version_id={$recoveryVersion}, active_key=NULL WHERE id=" . (int) $recoveryAttempt['id']);
    $primaryState = mmh_timed_exam_course_states($admin, 4, 'course-one', false)['exam-one']['state_key'] ?? '';
    $learningState = mmh_timed_exam_course_states($admin, 4, 'course-one', true)['exam-one']['state_key'] ?? '';
    $assert($primaryState === 'no_submission' && $learningState === 'submitted', 'Recovery completion was not isolated from primary state or included in Learning Journey state.');
    $assert(mmh_timed_exam_state_completes_learning('submitted') && mmh_timed_exam_state_completes_learning('auto_submitted') && mmh_timed_exam_state_completes_learning('graded') && !mmh_timed_exam_state_completes_learning('no_submission'), 'Learning Journey Timed Exam completion rules are incorrect.');

    // Authorization negatives: no outcome for non-enrolled or mismatched student/attempt scope.
    $notEnrolled = mmh_timed_exam_finalize_attempt($admin, $examOne, 9, null, $future);
    $notEnrolledSubmit = mmh_timed_exam_submit($admin, $examOne, 9);
    $wrongOwner = mmh_timed_exam_finalize_attempt($admin, $examOne, 2, (int) $attempt1['id'], $future);
    $assert(empty($notEnrolled['success']) && empty($notEnrolledSubmit[0]) && empty($wrongOwner['success']), 'Timed Exam lifecycle accepted an unauthorized student/attempt combination.');
    $assert((int) ($admin->query('SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id=' . $examOneId . ' AND student_id=9')->fetch_assoc()['total'] ?? 0) === 0, 'Unauthorized finalization created an attempt.');

    $source = file_get_contents(dirname(__DIR__) . '/inc/StudentLearningJourney.php');
    $uploadSource = file_get_contents(dirname(__DIR__) . '/inc/TimedExam.php');
    $submitHandler = file_get_contents(dirname(__DIR__) . '/views/user/requests/submit-timed-exam.php');
    $gradeHandler = file_get_contents(dirname(__DIR__) . '/views/admin/requests/grade-timed-exam.php');
    $cliSource = file_get_contents(dirname(__DIR__) . '/scripts/finalize-timed-exams.php');
    $htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
    $assert(is_string($source) && str_contains($source, 'mmh_timed_exam_state_completes_learning'), 'Learning Journey is not using the canonical Timed Exam completion rule.');
    $assert(is_string($uploadSource) && str_contains($uploadSource, 'mmh_timed_exam_upload_capacity($conn, $exam, $attemptId, true)'), 'Upload handler does not enforce the locked database-backed limit.');
    $assert(is_string($submitHandler) && str_contains($submitHandler, 'student_course_csrf_valid') && str_contains($submitHandler, 'student_course_access_enrolled'), 'Student submit authorization/CSRF regression.');
    $assert(is_string($gradeHandler) && str_contains($gradeHandler, 'mmh_auth_csrf_valid') && str_contains($gradeHandler, 'mmh_timed_exam_save_grade'), 'Admin grading authorization/service regression.');
    $assert(is_string($cliSource) && str_contains($cliSource, "PHP_SAPI !== 'cli'"), 'Lifecycle finalizer is not CLI-only.');
    $assert(is_string($htaccess) && str_contains($htaccess, 'database|scripts|vendor'), 'Web-server rules do not block direct lifecycle script access.');

    echo "Timed Exam database lifecycle tests passed (including real concurrent submit/finalizer race).\n";
} finally {
    foreach ($answerFiles as $path) {
        if (is_file($path)) unlink($path);
    }
    $cleanup = mysqli_connect($dbHost, $dbUser, $dbPass);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_timed_exam_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $testDatabase . '`');
        $cleanup->close();
    }
}
