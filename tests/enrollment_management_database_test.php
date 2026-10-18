<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/functions.php';
require_once dirname(__DIR__) . '/inc/EnrollmentService.php';
require_once dirname(__DIR__) . '/inc/StudentCourseAccess.php';

$admin = db();
$testDatabase = 'mmh_enrollment_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_enrollment_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) throw new RuntimeException('Unsafe test database name.');
$query = static function (mysqli $conn, string $sql): mysqli_result|bool { $result = $conn->query($sql); if ($result === false) throw new RuntimeException($conn->error ?: 'Database test query failed.'); return $result; };
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

try {
    $query($admin, 'CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($testDatabase), 'Unable to select isolated enrollment database.');
    $query($admin, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(190) NOT NULL, course_state VARCHAR(16) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_sections (id INT AUTO_INCREMENT PRIMARY KEY, section_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, title VARCHAR(190), section_type VARCHAR(30), access_unit VARCHAR(10) NOT NULL DEFAULT 'shared')");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, course_title VARCHAR(190) NULL, purchase_date DATETIME NULL, unit_access VARCHAR(10) NULL DEFAULT NULL, KEY idx_enrollment(course_id,user_id))");
    $query($admin, "CREATE TABLE learning_events (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL)");
    $query($admin, "INSERT INTO courses VALUES ('source','Source Course','public',NULL),('target','Target Course','public',NULL),('private','Private Course','private',NULL),('draft','Draft Course','draft',NULL),('archived','Archived Course','private',NOW()),('3078','Math OL Full Course','private',NULL),('bootstrap','Bootstrap Course','private',NULL)");
    $query($admin, "INSERT INTO course_sections (section_id,course_id,title,section_type,access_unit) VALUES ('u1','3078','Unit 1','unit','unit1'),('u2','3078','Unit 2','unit','unit2'),('shared','3078','Shared','lecture','shared')");

    $insert = $admin->prepare("INSERT INTO course_logs (user_id,course_id,course_title,purchase_date) VALUES (?, ?, ?, NOW())");
    foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $userId) { $course = 'Source Course'; $source = 'source'; $insert->bind_param('iss', $userId, $source, $course); $insert->execute(); }
    $target = 'target'; $targetTitle = 'Target Course'; $targetUser = 2; $insert->bind_param('iss', $targetUser, $target, $targetTitle); $insert->execute();
    $insert->bind_param('iss', $targetUser, $target, $targetTitle); $insert->execute();
    $target = 'private'; $targetTitle = 'Private Course'; $targetUser = 8; $insert->bind_param('iss', $targetUser, $target, $targetTitle); $insert->execute();
    $insert->close();
    $query($admin, "INSERT INTO course_logs (user_id,course_id,course_title,purchase_date,unit_access) VALUES (12,'3078','Math OL Full Course',NOW(),NULL)");
    $query($admin, "INSERT INTO course_sections (section_id,course_id,title,section_type,access_unit) VALUES ('intro','bootstrap','Introduction','lecture','shared')");
    $query($admin, "INSERT INTO course_logs (user_id,course_id,course_title,purchase_date,unit_access) VALUES (21,'bootstrap','Bootstrap Course',NOW(),NULL),(22,'bootstrap','Bootstrap Course',NOW(),NULL)");
    $query($admin, "INSERT INTO learning_events (user_id,course_id) VALUES (1,'source'),(4,'source')");

    $assert(!mmh_enrollment_ensure($admin, 13, '3078', 'Math OL Full Course'), 'Full Course enrollment accepted without an explicit entitlement after Unit Sections exist.');
    $assert(!mmh_enrollment_ensure($admin, 13, '3078', 'Math OL Full Course', null, true, 'all'), 'Invalid Full Course entitlement was accepted.');
    $assert(mmh_enrollment_ensure($admin, 13, '3078', 'Math OL Full Course', null, true, 'unit1'), 'Explicit Unit 1 enrollment failed.');
    $assert((string) $admin->query("SELECT unit_access FROM course_logs WHERE user_id=13 AND course_id='3078'")->fetch_assoc()['unit_access'] === 'unit1', 'Unit 1 entitlement was not stored.');
    $assert(mmh_enrollment_set_unit_access_batch($admin, [12], '3078', 'unit2'), 'Legacy unclassified enrollment could not be classified through the Admin service.');
    $assert((string) $admin->query("SELECT unit_access FROM course_logs WHERE user_id=12 AND course_id='3078'")->fetch_assoc()['unit_access'] === 'unit2', 'Existing Unit 2 entitlement was not saved.');
    $bootstrapState = mmh_enrollment_course_unit_state($admin, 'bootstrap');
    $assert(is_array($bootstrapState) && !empty($bootstrapState['ready']) && empty($bootstrapState['candidate']), 'Ordinary Course should remain unclassified before optional Unit setup.');
    $assert(!mmh_enrollment_set_unit_access_batch($admin, [21], 'bootstrap', 'unit1'), 'Unsupported Course accepted a Unit classification.');
    $assert($admin->query("SELECT unit_access FROM course_logs WHERE user_id=21 AND course_id='bootstrap'")->fetch_assoc()['unit_access'] === null, 'Unsupported Course enrollment was classified.');
    $assert(!mmh_enrollment_ensure($admin, 24, 'bootstrap', 'Bootstrap Course', null, true, 'unit1'), 'Canonical enrollment writer accepted an unsupported Course classification.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=24 AND course_id='bootstrap'")->fetch_assoc()['total'] === 0, 'Rejected unsupported Course enrollment wrote a row.');
    $assert($admin->query("SELECT unit_access FROM course_logs WHERE user_id=22 AND course_id='bootstrap'")->fetch_assoc()['unit_access'] === null, 'Other existing enrollments were classified without Admin selection.');
    $classifiedState = mmh_enrollment_course_unit_state($admin, 'bootstrap');
    $assert(is_array($classifiedState) && empty($classifiedState['candidate']) && !empty($classifiedState['supported']) === false, 'Unsupported Course should not become a Unit Access candidate.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE course_id='bootstrap' AND (unit_access IS NULL OR unit_access NOT IN ('unit1','unit2','both'))")->fetch_assoc()['total'] === 2, 'Unsupported Course enrollments should remain unclassified.');
    $assert(mmh_enrollment_ensure($admin, 23, 'source', 'Source Course'), 'Ordinary Course enrollment was incorrectly made Unit-required.');
    $assert($admin->query("SELECT unit_access FROM course_logs WHERE user_id=23 AND course_id='source'")->fetch_assoc()['unit_access'] === null, 'Ordinary Course enrollment should not receive an automatic Unit classification.');
    $assert(student_course_access_unit_allowed('shared', null), 'Unclassified enrollment should retain shared Section access.');
    $assert(!student_course_access_unit_allowed('unit1', null) && !student_course_access_unit_allowed('unit2', null), 'Unclassified enrollment accessed a restricted Unit.');
    $assert(student_course_access_unit_allowed('unit1', 'unit1') && !student_course_access_unit_allowed('unit2', 'unit1'), 'Unit 1 access matrix is incorrect.');
    $assert(student_course_access_unit_allowed('unit2', 'unit2') && !student_course_access_unit_allowed('unit1', 'unit2'), 'Unit 2 access matrix is incorrect.');
    $assert(student_course_access_unit_allowed('unit1', 'both') && student_course_access_unit_allowed('unit2', 'both'), 'Both Units entitlement is incorrect.');
    $assert(!student_course_access_unit_allowed('unit1', 'unlock_all') && !student_course_access_unit_allowed('unit2', 'unlock_all'), 'Unlock All bypassed Unit entitlement.');
    $assert(mmh_course_supports_unit_section_access('3078') && !mmh_course_supports_unit_section_access('4359') && !mmh_course_supports_unit_section_access('source'), 'Unit Section capability does not match the canonical Course scope.');

    $assert(mmh_enrollment_ensure($admin, 9, 'private', 'Private Course'), 'Direct enrollment into a private course failed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=9 AND course_id='private'")->fetch_assoc()['total'] === 1, 'Private enrollment row was not created.');
    $assert((string) $admin->query("SELECT course_state FROM courses WHERE course_id='private'")->fetch_assoc()['course_state'] === 'private', 'Private course state changed during enrollment.');
    $assert(!mmh_enrollment_ensure($admin, 10, 'draft', 'Draft Course'), 'Draft course accepted a student enrollment.');

    $assert(mmh_enrollment_remove($admin, 1, 'source'), 'Single enrollment removal failed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=1 AND course_id='source'")->fetch_assoc()['total'] === 0, 'Removed enrollment still exists.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM learning_events WHERE user_id=1 AND course_id='source'")->fetch_assoc()['total'] === 1, 'Historical learning event was deleted.');

    $assert(!mmh_enrollment_remove_batch($admin, [3, 999], 'source'), 'Partial removal batch should roll back.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=3 AND course_id='source'")->fetch_assoc()['total'] === 1, 'Failed removal batch changed data.');
    $assert(mmh_enrollment_remove_batch($admin, [3, 4], 'source'), 'Multi-student removal failed.');

    $assert(!mmh_enrollment_move($admin, 2, 'source', 'target'), 'Duplicate target enrollment was not rejected.');
    $assert(mmh_enrollment_move($admin, 5, 'source', 'private'), 'Move to private course failed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=5 AND course_id='source'")->fetch_assoc()['total'] === 0, 'Source enrollment remained after move.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=5 AND course_id='private'")->fetch_assoc()['total'] === 1, 'Target enrollment was not created.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'source'), 'Same-course move was not rejected.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'target', true, 'unit1'), 'Enrollment movement accepted Unit classification for an unsupported target Course.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'draft'), 'Draft target course was accepted.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'archived'), 'Archived target course was accepted.');

    $assert(!mmh_enrollment_move_batch($admin, [7, 8], 'source', 'private'), 'Failed move batch should roll back.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=7 AND course_id='source'")->fetch_assoc()['total'] === 1, 'Failed move batch changed the first student.');
    $assert(mmh_enrollment_move_batch($admin, [6, 7], 'source', 'private'), 'Successful move batch failed.');

    $handler = file_get_contents(dirname(__DIR__) . '/views/admin/requests/course-students.php');
    $index = file_get_contents(dirname(__DIR__) . '/index.php');
    $page = file_get_contents(dirname(__DIR__) . '/views/admin/course-students.php');
    $sectionForm = file_get_contents(dirname(__DIR__) . '/views/admin/requests/form-section.php');
    $sectionSave = file_get_contents(dirname(__DIR__) . '/views/admin/requests/add-section.php');
    $assert(is_string($handler) && str_contains($handler, 'mmh_enrollment_remove_batch') && str_contains($handler, 'mmh_enrollment_move_batch'), 'Admin handler does not use canonical enrollment services.');
    $addUser = file_get_contents(dirname(__DIR__) . '/views/admin/requests/addUser-course.php');
    $assert(is_string($addUser) && str_contains($addUser, "course_state IN ('public', 'private')") && str_contains($addUser, 'mmh_enrollment_ensure'), 'Direct admin enrollment does not accept Private courses through the canonical writer.');
    $assert(is_string($addUser) && str_contains($addUser, "name='unit_access'") && str_contains($addUser, 'mmh_course_supports_unit_section_access') && str_contains($addUser, "'unit1'") && str_contains($addUser, "'unit2'") && str_contains($addUser, "'both'"), 'Admin Add Student form is missing scoped explicit Unit entitlement choices.');
    $assert(is_string($page) && str_contains($page, 'Unclassified') && str_contains($page, 'Save Unit Access') && str_contains($page, '$unitAccessSupported'), 'Admin enrollment management does not scope Unit controls to the supported Course.');
    $assert(is_string($handler) && str_contains($handler, 'mmh_enrollment_set_unit_access_batch'), 'Admin enrollment edit does not use the canonical Unit Access service.');
    $assert(is_string($sectionForm) && str_contains($sectionForm, "name='access_unit'") && str_contains($sectionForm, 'mmh_course_supports_unit_section_access') && str_contains($sectionForm, 'unit1') && str_contains($sectionForm, 'unit2'), 'Admin Section form is missing scoped Unit Access choices.');
    $assert(is_string($sectionSave) && str_contains($sectionSave, 'add_section_activation_blocker') && str_contains($sectionSave, 'unclassified') && str_contains($sectionSave, 'Unit-restricted Sections are available only in Math OL Full Course.'), 'Restricted Section activation is not scoped or does not prevent unsafe unresolved enrollments.');
    $assert(is_string($index) && str_contains($index, "mmh_admin_require_mutation()"), 'Admin enrollment route is not mutation-protected.');
    $assert(is_string($page) && str_contains($page, 'mmh_admin_csrf_token()'), 'Enrollment management form is missing admin CSRF.');
    $assert(governoratesInfo(1)['governorate_name_en'] === 'Cairo' && governoratesInfo('27')['governorate_name_en'] === 'Sohag', 'Governorate lookup failed for integer/numeric-string IDs.');
    foreach ([null, '', 'not-a-number', 0, 28] as $invalidGovernorate) {
        $fallback = governoratesInfo($invalidGovernorate);
        $assert(is_array($fallback) && $fallback['governorate_name_en'] === 'Not specified' && $fallback['governorate_name_ar'] === 'غير محدد', 'Invalid governorate ID did not return the safe fallback.');
    }
    echo "Enrollment management database checks passed.\n";
} finally {
    $cleanup = mysqli_connect((string) $host, (string) $user, (string) $pass);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_enrollment_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) {
        $cleanup->query('DROP DATABASE `' . $testDatabase . '`');
        $cleanup->close();
    }
}
