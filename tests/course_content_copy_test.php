<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/CourseContentCopyService.php';
require_once dirname(__DIR__) . '/inc/EnrollmentService.php';

$dbHost = (string) $host; $dbUser = (string) $user; $dbPass = (string) $pass;
$database = 'mmh_course_copy_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$admin = db();
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql) use ($assert): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error); };

try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated copy database.');
    $query($admin, "CREATE TABLE courses (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, course_title VARCHAR(190) NOT NULL, course_title_en VARCHAR(190), course_description TEXT, course_image VARCHAR(255), course_price INT, preDiscount_course_price INT, course_category INT, whatsapp_group TEXT NULL, sequential_learning TINYINT DEFAULT 0, default_homework_score_mode VARCHAR(32) DEFAULT 'disabled', username VARCHAR(50), course_status CHAR(1) DEFAULT '1', course_visibility VARCHAR(20) DEFAULT 'public', course_state VARCHAR(16) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_sections (id INT AUTO_INCREMENT PRIMARY KEY, section_id VARCHAR(20) NOT NULL, course_id VARCHAR(20) NOT NULL, title VARCHAR(190) NOT NULL, section_type VARCHAR(50), custom_type VARCHAR(80), icon VARCHAR(50), description TEXT, metadata TEXT, sort_order INT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'published', unlock_mode VARCHAR(50), completion_rule VARCHAR(50), unlock_at DATETIME NULL, unlock_timezone VARCHAR(64), unlock_homework_id VARCHAR(20), manual_unlocked TINYINT NOT NULL DEFAULT 0, release_mode VARCHAR(32) NOT NULL DEFAULT 'inherit', release_override VARCHAR(16) NOT NULL DEFAULT 'inherit', release_at DATETIME NULL, release_timezone VARCHAR(80), release_occurrence_id VARCHAR(64), release_delay_minutes INT NOT NULL DEFAULT 0, release_updated_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(20) NOT NULL, item_title VARCHAR(255) NOT NULL, item_description TEXT, item_type VARCHAR(20) NOT NULL, section_id VARCHAR(20), template_type VARCHAR(50), template_data TEXT, metadata TEXT, duration_minutes INT NULL, assignment_id INT NULL, due_date DATETIME NULL, status VARCHAR(16) NOT NULL DEFAULT 'published', sort_order INT NOT NULL DEFAULT 0, course_id VARCHAR(20) NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $query($admin, "CREATE TABLE assignments (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(20) NOT NULL, assignment_title VARCHAR(255) NOT NULL, assignment_description TEXT, due_date DATETIME NOT NULL, file_path VARCHAR(255), course_id VARCHAR(20) NOT NULL, section_id VARCHAR(20), item_id VARCHAR(20), max_score DECIMAL(6,2) NULL, recommended_recording_item_id VARCHAR(40), recommended_notes_item_id VARCHAR(40), recommended_revision_item_id VARCHAR(40), archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE assignment_model_answer_access (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(20) NOT NULL, user_id INT NOT NULL)");
    $query($admin, "CREATE TABLE assignment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(20) NOT NULL, student_id INT NOT NULL)");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, user_id INT NOT NULL, course_title VARCHAR(190) NULL, purchase_date DATETIME NULL, KEY idx_enrollment(course_id,user_id))");
    $query($admin, "CREATE TABLE course_live_schedules (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, scheduled_start_at DATETIME NULL)");
    $query($admin, "CREATE TABLE timed_exams (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, item_id VARCHAR(20) NOT NULL, title VARCHAR(190) NOT NULL, instructions TEXT, status VARCHAR(16) NOT NULL DEFAULT 'draft', timing_mode VARCHAR(24) NOT NULL DEFAULT 'fixed_window', scheduled_start_at_utc DATETIME NULL, duration_minutes INT NOT NULL DEFAULT 60, grace_minutes INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 1, allowed_answer_types VARCHAR(255) NOT NULL DEFAULT 'pdf', max_file_size_bytes BIGINT NOT NULL DEFAULT 10485760, paper_source VARCHAR(24) NOT NULL DEFAULT 'external_link', paper_external_url VARCHAR(1000), paper_external_preview_url VARCHAR(1000), paper_external_download_url VARCHAR(1000), paper_fallback_instructions TEXT, paper_storage_key VARCHAR(255), paper_original_name VARCHAR(255), paper_mime VARCHAR(120), paper_size_bytes BIGINT NULL, paper_view_allowed TINYINT NOT NULL DEFAULT 1, paper_download_allowed TINYINT NOT NULL DEFAULT 1, late_submission_allowed TINYINT NOT NULL DEFAULT 1, expiry_policy VARCHAR(32) NOT NULL DEFAULT 'auto_submit_latest', max_marks DECIMAL(10,2), results_release_at_utc DATETIME NULL, recovery_window_start_at_utc DATETIME NULL, recovery_window_end_at_utc DATETIME NULL, recovery_allowed TINYINT NOT NULL DEFAULT 0, attempt_generation INT UNSIGNED NOT NULL DEFAULT 1, deleted_at DATETIME NULL, roster_finalized_at_utc DATETIME NULL, roster_finalized_generation INT UNSIGNED NULL, created_by INT NULL, updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $query($admin, "INSERT INTO courses (course_id,course_title,course_state) VALUES ('source','Source','public'),('destination','Destination','draft')");
    $query($admin, "UPDATE courses SET course_title_en='Source',course_description='Source description',course_image='uploads/source-cover.jpg',course_price=125,preDiscount_course_price=150,course_category=2,sequential_learning=1,default_homework_score_mode='disabled',username='teacher' WHERE course_id='source'");
    $query($admin, "INSERT INTO course_logs (course_id,user_id) VALUES ('source',7)");
    $query($admin, "INSERT INTO course_live_schedules (course_id,scheduled_start_at) VALUES ('source','2030-01-01 10:00:00')");
    $query($admin, "INSERT INTO course_sections (section_id,course_id,title,section_type,description,metadata,sort_order,status,unlock_mode,completion_rule,release_mode,release_override,release_delay_minutes) VALUES ('s1','source','Week 1','lecture','desc','{\"release_occurrence_id\":\"old-occurrence\"}',1,'published','always','manual_completion','inherit','inherit',0)");
    $query($admin, "INSERT INTO course_items (item_id,item_title,item_description,item_type,section_id,template_type,template_data,metadata,assignment_id,due_date,status,sort_order,course_id,page_order) VALUES ('lesson1','Lesson 1','body','file','s1','custom_lesson','{\"section_id\":\"s1\"}',NULL,NULL,NULL,'published',1,'source',1),('homework1','Homework 1','<button class=\"show-assignment\" data-assignment-id=\"777\"></button><input type=\"hidden\" name=\"assignment_id\" value=\"888\">','quiz','s1','classified_assignment','{\"assignment_id\":\"42\",\"homework_resource\":{\"assignment_id\":\"42\"},\"resource\":{\"assignment_id\":\"999\"}}','{\"answer\":{\"assignment_id\":\"999\"}}',42,'2025-10-01 12:00:00','published',2,'source',2),('exam1','Exam 1','body','quiz','s1','timed_exam','{}',NULL,NULL,NULL,'published',3,'source',3)");
    $query($admin, "INSERT INTO assignments (assignment_id,assignment_title,assignment_description,due_date,course_id,section_id,item_id,max_score) VALUES ('42','Homework 1','Instructions','2025-10-01 12:00:00','source','s1','homework1',100)");
    $query($admin, "UPDATE assignments SET recommended_recording_item_id='lesson1',due_date='2025-10-01 12:00:00' WHERE assignment_id='42'");
    $query($admin, "INSERT INTO assignment_model_answer_access (assignment_id,user_id) VALUES ('42',7)");
    $query($admin, "INSERT INTO assignment_submissions (assignment_id,student_id) VALUES ('42',7)");
    $query($admin, "INSERT INTO timed_exams (course_id,item_id,title,instructions,status,scheduled_start_at_utc,duration_minutes,grace_minutes,paper_external_url) VALUES ('source','exam1','Exam 1','Instructions','published','2030-01-01 10:00:00',60,5,'https://drive.google.com/file/d/example/view')");

    $item = CourseContentCopyService::copyItem($admin, 'source', 'homework1', 'destination', null);
    $assert($item['item_id'] !== 'homework1', 'Copied item reused the source item ID.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='destination'")->fetch_assoc()['n'] === 1, 'Single item was not copied.');
    $copiedAssignment = $admin->query("SELECT assignment_id,item_id,course_id FROM assignments WHERE course_id='destination'")->fetch_assoc();
    $assert($copiedAssignment && $copiedAssignment['assignment_id'] !== '42' && $copiedAssignment['item_id'] === $item['item_id'], 'Assignment definition was not independently copied.');
    $copiedItem = $admin->query("SELECT item_description,template_data,metadata,assignment_id FROM course_items WHERE course_id='destination' AND item_id='" . $admin->real_escape_string($item['item_id']) . "'")->fetch_assoc();
    $newAssignmentId = (string) $copiedAssignment['assignment_id'];
    $assert((string) $copiedItem['assignment_id'] === $newAssignmentId, 'Copied Course Item does not point to its new canonical Assignment.');
    $expectedAssignmentAttr = 'data-assignment-id="' . $newAssignmentId . '"';
    $assert(str_contains((string) $copiedItem['item_description'], $expectedAssignmentAttr), 'Copied legacy HTML did not remap the Assignment hint to the new Assignment.');
    $assert(!str_contains((string) $copiedItem['item_description'], '777') && !str_contains((string) $copiedItem['item_description'], '888'), 'Copied legacy HTML retained stale source/contradictory Assignment IDs.');
    $copiedTemplate = json_decode((string) $copiedItem['template_data'], true);
    $copiedMetadata = json_decode((string) $copiedItem['metadata'], true);
    $assert(($copiedTemplate['assignment_id'] ?? '') === $newAssignmentId && ($copiedTemplate['homework_resource']['assignment_id'] ?? '') === $newAssignmentId, 'Copied nested template Assignment identity was not remapped: ' . json_encode($copiedTemplate) . ' expected ' . $newAssignmentId);
    $assert(!isset($copiedTemplate['resource']['assignment_id']) && !isset($copiedMetadata['answer']['assignment_id']), 'Copied item retained unmapped Assignment identity in nested metadata.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_submissions")->fetch_assoc()['n'] === 1, 'Submission data changed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_model_answer_access")->fetch_assoc()['n'] === 1, 'Student model-answer access was copied or changed.');

    $section = CourseContentCopyService::copySection($admin, 'source', 's1', 'destination');
    $assert($section['section_id'] !== 's1', 'Copied section reused the source section ID.');
    $assert(count($section['item_ids']) === 3, 'Section copy did not preserve all items.');
    $orders = $admin->query("SELECT page_order FROM course_items WHERE course_id='destination' AND section_id='" . $admin->real_escape_string($section['section_id']) . "' ORDER BY page_order ASC")->fetch_all(MYSQLI_ASSOC);
    $assert(array_column($orders, 'page_order') === ['1','2','3'], 'Section item order was not preserved.');
    $exam = $admin->query("SELECT status,scheduled_start_at_utc,attempt_generation,roster_finalized_at_utc FROM timed_exams WHERE course_id='destination' AND item_id='" . $admin->real_escape_string($section['item_ids'][2]) . "'")->fetch_assoc();
    $copiedExamItem = $admin->query("SELECT status FROM course_items WHERE course_id='destination' AND item_id='" . $admin->real_escape_string($section['item_ids'][2]) . "'")->fetch_assoc();
    $assert(($exam['status'] ?? '') === 'draft' && ($copiedExamItem['status'] ?? '') === 'draft' && ($exam['scheduled_start_at_utc'] ?? null) === null, 'Copied Timed Exam and its course item were not safely drafted.');
    $assert((int) ($exam['attempt_generation'] ?? 1) === 1 && ($exam['roster_finalized_at_utc'] ?? null) === null, 'Copied Timed Exam retained historical lifecycle state.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='source'")->fetch_assoc()['n'] === 3, 'Source items changed.');
    $sourceHomework = $admin->query("SELECT item_description,template_data,assignment_id FROM course_items WHERE course_id='source' AND item_id='homework1'")->fetch_assoc();
    $assert(str_contains((string) $sourceHomework['item_description'], 'data-assignment-id="777"') && str_contains((string) $sourceHomework['item_description'], 'value="888"') && (int) $sourceHomework['assignment_id'] === 42, 'Source Homework HTML or canonical identity was modified during copy.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_sections WHERE course_id='source'")->fetch_assoc()['n'] === 1, 'Source section changed.');

    try { CourseContentCopyService::copyItem($admin, 'source', 'lesson1', 'destination', 'missing'); $assert(false, 'Invalid destination section was accepted.'); } catch (Throwable $expected) {}
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='destination'")->fetch_assoc()['n'] === 4, 'Failed copy left partial content behind.');
    $query($admin, "INSERT INTO course_sections (section_id,course_id,title,section_type,sort_order,status,unlock_mode,completion_rule) VALUES ('s2','source','Week 2','lecture',2,'published','always','manual_completion')");
    $query($admin, "INSERT INTO course_items (item_id,item_title,item_description,item_type,section_id,template_type,template_data,status,sort_order,course_id,page_order) VALUES ('lesson2','Lesson 2','body','file','s2','custom_lesson','{}','published',1,'source',1)");

    $next = CourseContentCopyService::createNextSession($admin, 'source', 'Source May/June 2027', 200, 'public', 'admin-user');
    $nextCourseId = $admin->real_escape_string($next['course_id']);
    $assert($next['course_id'] !== 'source', 'Next Session reused the source Course ID.');
    $nextCourse = $admin->query("SELECT * FROM courses WHERE course_id='{$nextCourseId}'")->fetch_assoc();
    $assert(($nextCourse['course_title'] ?? '') === 'Source May/June 2027' && (int) $nextCourse['course_price'] === 200, 'Next Session title or chosen price was not saved.');
    $assert(($nextCourse['course_image'] ?? '') === 'uploads/source-cover.jpg' && ($nextCourse['course_description'] ?? '') === 'Source description', 'Useful Course metadata was not copied.');
    $assert(($nextCourse['course_state'] ?? '') === 'public' && ($nextCourse['course_status'] ?? '') === '1', 'New Course availability state was not saved coherently.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_sections WHERE course_id='{$nextCourseId}'")->fetch_assoc()['n'] === 2, 'Next Session did not copy all Sections.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='{$nextCourseId}'")->fetch_assoc()['n'] === 4, 'Next Session did not copy all Section items.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_sections WHERE course_id='{$nextCourseId}' AND status='draft'")->fetch_assoc()['n'] === 2, 'Copied Sections are not Draft.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='{$nextCourseId}' AND status='draft'")->fetch_assoc()['n'] === 4, 'Copied teaching items are not Draft/hidden.');
    $nextSections = $admin->query("SELECT title,sort_order,status FROM course_sections WHERE course_id='{$nextCourseId}' ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
    $assert(array_column($nextSections, 'title') === ['Week 1','Week 2'] && array_column($nextSections, 'sort_order') === ['1','2'], 'Next Session did not preserve Section names/order.');
    $nextOrders = $admin->query("SELECT ci.item_title,ci.page_order,s.title AS section_title FROM course_items ci LEFT JOIN course_sections s ON s.course_id=ci.course_id AND s.section_id=ci.section_id WHERE ci.course_id='{$nextCourseId}' ORDER BY s.sort_order ASC,ci.page_order ASC")->fetch_all(MYSQLI_ASSOC);
    $assert(array_column($nextOrders, 'item_title') === ['Lesson 1', 'Homework 1', 'Exam 1', 'Lesson 2'] && array_column($nextOrders, 'page_order') === ['1','2','3','1'] && array_column($nextOrders, 'section_title') === ['Week 1','Week 1','Week 1','Week 2'], 'Next Session did not preserve Course Item section/order.');
    $newHomework = $admin->query("SELECT ci.item_id,ci.assignment_id,ci.item_description,ci.template_data,ci.metadata,ci.due_date FROM course_items ci WHERE ci.course_id='{$nextCourseId}' AND ci.template_type='classified_assignment'")->fetch_assoc();
    $nextAssignment = $admin->query("SELECT assignment_id,item_id,course_id,recommended_recording_item_id,due_date FROM assignments WHERE course_id='{$nextCourseId}'")->fetch_assoc();
    $assert($newHomework && $nextAssignment && (string) $newHomework['assignment_id'] === (string) $nextAssignment['assignment_id'] && $nextAssignment['assignment_id'] !== '42', 'Next Session Homework does not have its own canonical Assignment.');
    $assert(($nextAssignment['recommended_recording_item_id'] ?? '') === $next['item_ids']['lesson1'], 'Copied Homework recommendation was not remapped to the copied Course Item.');
    $copiedDeadline = trim((string) ($nextAssignment['due_date'] ?? ''));
    $copiedDeadlineTimestamp = strtotime($copiedDeadline);
    $assert($copiedDeadlineTimestamp !== false && $copiedDeadlineTimestamp > time() && $copiedDeadline !== '2025-10-01 12:00:00', 'The copied Assignment does not have a valid future placeholder deadline.');
    $assert(($newHomework['due_date'] ?? null) === null, 'The Course Item-level session date was not cleared.');
    $sourceDeadline = $admin->query("SELECT due_date FROM assignments WHERE assignment_id='42' AND course_id='source'")->fetch_assoc();
    $assert(($sourceDeadline['due_date'] ?? '') === '2025-10-01 12:00:00', 'The source Assignment deadline was changed.');
    $newTemplate = json_decode((string) $newHomework['template_data'], true);
    $assert(($newTemplate['assignment_id'] ?? '') === (string) $nextAssignment['assignment_id'], 'Copied Homework JSON retains a source Assignment identity.');
    $assert(!str_contains((string) $newHomework['item_description'], '777') && !str_contains((string) $newHomework['item_description'], '888'), 'Copied Homework HTML retains stale Assignment IDs.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignments WHERE course_id='source'")->fetch_assoc()['n'] === 1, 'Source Assignment was changed or duplicated in place.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_submissions")->fetch_assoc()['n'] === 1, 'Student submission state changed during session creation.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_logs WHERE course_id='{$nextCourseId}'")->fetch_assoc()['n'] === 0, 'Enrollment was copied to the new Course.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_live_schedules WHERE course_id='{$nextCourseId}'")->fetch_assoc()['n'] === 0, 'Old live schedules were copied to the new Course.');
    $admin->query("UPDATE courses SET course_title='Edited new session',course_image='uploads/new-cover.jpg',course_price=300,course_state='private' WHERE course_id='{$nextCourseId}'");
    $sourceAfter = $admin->query("SELECT course_title,course_image,course_price,course_state FROM courses WHERE course_id='source'")->fetch_assoc();
    $assert(($sourceAfter['course_title'] ?? '') === 'Source' && ($sourceAfter['course_image'] ?? '') === 'uploads/source-cover.jpg' && (int) $sourceAfter['course_price'] === 125 && ($sourceAfter['course_state'] ?? '') === 'public', 'Editing the new session changed the source Course.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_sections WHERE course_id='{$nextCourseId}' AND status='published'")->fetch_assoc()['n'] === 0, 'Copied content became student-visible before publishing.');
    $coursesBeforeFailedClone = (int) $admin->query('SELECT COUNT(*) AS n FROM courses')->fetch_assoc()['n'];
    $query($admin, "CREATE TRIGGER mmh_fail_next_session_item BEFORE INSERT ON course_items FOR EACH ROW BEGIN IF NEW.course_id NOT IN ('source','destination','{$nextCourseId}') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced next-session rollback test'; END IF; END");
    $rollbackObserved = false;
    try { CourseContentCopyService::createNextSession($admin, 'source', 'Should roll back', 100, 'draft', 'admin-user'); } catch (Throwable $expected) { $rollbackObserved = true; }
    $query($admin, 'DROP TRIGGER mmh_fail_next_session_item');
    $assert($rollbackObserved, 'Forced Course Item copy failure did not propagate.');
    $assert((int) $admin->query('SELECT COUNT(*) AS n FROM courses')->fetch_assoc()['n'] === $coursesBeforeFailedClone, 'Failed Next Session left a partial Course row.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_sections WHERE course_id NOT IN ('source','destination','{$nextCourseId}')")->fetch_assoc()['n'] === 0, 'Failed Next Session left partial Sections.');

    // The Admin's Add Student flow intentionally excludes Draft Courses.
    // A newly-created session should therefore default to Private while its
    // copied teaching content remains Draft/hidden.
    $enrollableNext = CourseContentCopyService::createNextSession($admin, 'source', 'Source Private Session', 125, 'private', 'admin-user');
    $enrollableCourseId = $admin->real_escape_string($enrollableNext['course_id']);
    $enrollableCourse = $admin->query("SELECT course_id,course_title,course_state,archived_at FROM courses WHERE course_id='{$enrollableCourseId}'")->fetch_assoc();
    $assert(($enrollableCourse['course_state'] ?? '') === 'private', 'Private Next Session did not retain its selected state.');
    $courseLookup = $admin->prepare("SELECT course_id,course_title,course_state FROM courses WHERE course_id = ? AND archived_at IS NULL AND course_state IN ('public', 'private') LIMIT 1");
    $courseLookup->bind_param('s', $enrollableNext['course_id']);
    $courseLookup->execute();
    $adminEnrollmentCourse = $courseLookup->get_result()->fetch_assoc();
    $courseLookup->close();
    $assert((bool) $adminEnrollmentCourse, 'Admin Add Student course lookup rejected a Private Next Session.');
    $assert(mmh_enrollment_ensure($admin, 9, $enrollableNext['course_id'], $enrollableCourse['course_title']), 'Admin enrollment service rejected a Private Next Session.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_logs WHERE course_id='{$enrollableCourseId}' AND user_id=9")->fetch_assoc()['n'] === 1, 'Student was not enrolled in the new session.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_logs WHERE course_id='source' AND user_id=9")->fetch_assoc()['n'] === 0, 'New-session enrollment changed source-course enrollment.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='{$enrollableCourseId}' AND status <> 'draft'")->fetch_assoc()['n'] === 0, 'Private Next Session copied visible Course Items instead of Draft content.');
    echo "Course Content copy tests passed.\n";
} finally {
    $cleanup = mysqli_connect($dbHost, $dbUser, $dbPass);
    if ($cleanup instanceof mysqli) { $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`'); $cleanup->close(); }
}
