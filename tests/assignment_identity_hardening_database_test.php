<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/StudentCourseAccess.php';
require_once dirname(__DIR__) . '/inc/StudentCourseProgress.php';
require_once dirname(__DIR__) . '/inc/AdminCourseService.php';
require_once dirname(__DIR__) . '/inc/AdminAssessmentService.php';
require_once dirname(__DIR__) . '/inc/AssignmentIdentityReconciliation.php';

$admin = db();
$hostName = (string) ($host ?? ''); $userName = (string) ($user ?? ''); $password = (string) ($pass ?? '');
$database = 'mmh_assignment_identity_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql) use ($assert): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Database test query failed.'); };
try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Could not select isolated identity test database.');
    $query($admin, "CREATE TABLE course_sections (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40), section_id VARCHAR(40), title VARCHAR(180), sort_order INT, status VARCHAR(16))");
    // Deliberately model the compatible local schema without course_items.archived_at.
    $query($admin, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40), item_id VARCHAR(40), item_title VARCHAR(180), item_type VARCHAR(40), template_type VARCHAR(40), template_data LONGTEXT, metadata LONGTEXT, item_description LONGTEXT, assignment_id VARCHAR(40) NULL, section_id VARCHAR(40), status VARCHAR(16), due_date DATETIME NULL, page_order INT DEFAULT 0, sort_order INT DEFAULT 0)");
    $query($admin, "CREATE TABLE assignments (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(40), course_id VARCHAR(40), item_id VARCHAR(40) NULL, section_id VARCHAR(40) NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE assignment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(40), student_id INT)");
    $query($admin, "INSERT INTO assignments (assignment_id,course_id,item_id,section_id) VALUES ('A','course-a','reverse-stale','section-a'),('B','course-a','legacy','section-a'),('C','course-a','other','section-a'),('FOREIGN','course-b','foreign-item','section-b'),('HISTORY','course-a',NULL,'section-a'),('SAFE','course-a',NULL,'section-a'),('CLAIM_A','course-a',NULL,'section-a'),('CLAIM_B','course-a',NULL,'section-a'),('HTML_A','course-a',NULL,'section-a'),('ONLY_MISSING','course-a',NULL,'section-a')");
    $query($admin, "INSERT INTO course_items (course_id,item_id,item_title,item_type,template_type,template_data,metadata,item_description,assignment_id,section_id,status) VALUES
      ('course-a','canonical','Canonical Homework','quiz','classified_assignment','{\"assignment_id\":\"B\",\"homework_resource\":{\"assignment_id\":\"C\"}}','{\"nested\":{\"assignment_id\":\"FOREIGN\"}}','<button data-assignment-id=\"D\">Upload</button>','A','section-a','published'),
      ('course-a','orphan','Orphan Homework','quiz','classified_assignment','{\"assignment_id\":\"A\"}',NULL,'<button data-assignment-id=\"A\">Upload</button>','MISSING','section-a','published'),
      ('course-a','bad-canonical','Bad canonical','quiz','classified_assignment','{\"assignment_id\":\"A\"}',NULL,'','bad id','section-a','published'),
      ('course-a','legacy','Legacy Homework','quiz','classified_assignment','{\"assignment_id\":\"A\"}',NULL,'','', 'section-a','published'),
      ('course-a','history-item','History Homework','quiz','classified_assignment','{}',NULL,'','HISTORY','section-a','published'),
      ('course-a','safe-item','Safe legacy Homework','quiz','classified_assignment','{\"homework_resource\":{\"assignment_id\":\"SAFE\"}}',NULL,'','', 'section-a','published'),
      ('course-a','claims-missing','JSON A + nonexistent HTML','quiz','classified_assignment','{\"assignment_id\":\"CLAIM_A\"}',NULL,'<button data-assignment-id=\"MISSING\">Upload</button>','', 'section-a','published'),
      ('course-a','claims-same','JSON A + HTML A','quiz','classified_assignment','{\"assignment_id\":\"CLAIM_A\"}',NULL,'<button data-assignment-id=\"CLAIM_A\">Upload</button>','', 'section-a','published'),
      ('course-a','claims-conflict','JSON A + HTML B','quiz','classified_assignment','{\"assignment_id\":\"CLAIM_A\"}',NULL,'<button data-assignment-id=\"CLAIM_B\">Upload</button>','', 'section-a','published'),
      ('course-a','html-only','HTML A only','quiz','classified_assignment','{}',NULL,'<button data-assignment-id=\"HTML_A\">Upload</button>','', 'section-a','published'),
      ('course-a','html-missing-only','HTML missing only','quiz','classified_assignment','{}',NULL,'<button data-assignment-id=\"DOES_NOT_EXIST\">Upload</button>','', 'section-a','published')");
    $query($admin, "INSERT INTO assignment_submissions (assignment_id,student_id) VALUES ('HISTORY',77),('SAFE',77)");
    $query($admin, "INSERT INTO course_sections (course_id,section_id,title,sort_order,status) VALUES ('course-a','section-a','Section A',1,'published'),('course-b','section-b','Section B',1,'published')");

    $canonical = $admin->query("SELECT * FROM course_items WHERE item_id='canonical'")->fetch_assoc();
    $identity = mmh_assignment_identity_for_item($admin, $canonical, true);
    $assert(($identity['status'] ?? '') === 'CONFLICT' && ($identity['assignment_id'] ?? '') === 'A', 'Canonical A did not win over conflicting JSON, metadata, HTML and reverse identities: ' . json_encode($identity));
    $assert(student_course_progress_assignment_id($canonical, $admin) === 'A', 'Student progress did not resolve canonical Assignment A.');
    $adminMap = mmh_admin_assignment_item_map($admin);
    $assert(isset($adminMap['A']), 'Admin Assignment map failed to return rows when course_items.archived_at is absent.');
    $assignmentA = $admin->query("SELECT * FROM assignments WHERE assignment_id='A'")->fetch_assoc();
    $assert(student_course_access_assignment_matches_item($admin, $assignmentA, $canonical), 'Canonical Assignment A failed Course Item access match.');
    $assignmentB = $admin->query("SELECT * FROM assignments WHERE assignment_id='B'")->fetch_assoc();
    $assert(!student_course_access_assignment_matches_item($admin, $assignmentB, $canonical), 'Conflicting Assignment B was authorized for canonical Course Item A.');
    $assignmentForeign = $admin->query("SELECT * FROM assignments WHERE assignment_id='FOREIGN'")->fetch_assoc();
    $assert(!student_course_access_assignment_matches_item($admin, $assignmentForeign, $canonical), 'Cross-Course Assignment substitution was authorized for a Course A item.');
    $query($admin, "UPDATE assignments SET item_id='another-stale-value' WHERE assignment_id='A'");
    $canonical = $admin->query("SELECT * FROM course_items WHERE item_id='canonical'")->fetch_assoc();
    $assert((mmh_assignment_identity_for_item($admin, $canonical, true)['assignment_id'] ?? '') === 'A', 'Changing reverse item_id changed canonical Homework identity.');

    $orphan = $admin->query("SELECT * FROM course_items WHERE item_id='orphan'")->fetch_assoc();
    $orphanIdentity = mmh_assignment_identity_for_item($admin, $orphan, true, false);
    $assert(($orphanIdentity['status'] ?? '') === 'ORPHANED_CANONICAL' && ($orphanIdentity['assignment_id'] ?? '') === '', 'Orphaned canonical link fell back to legacy identity.');
    $badCanonical = $admin->query("SELECT * FROM course_items WHERE item_id='bad-canonical'")->fetch_assoc();
    $assert((mmh_assignment_identity_for_item($admin, $badCanonical, true)['status'] ?? '') === 'INVALID_CANONICAL', 'Malformed populated canonical link was treated as absent.');

    $legacy = $admin->query("SELECT * FROM course_items WHERE item_id='legacy'")->fetch_assoc();
    $assert((mmh_assignment_identity_for_item($admin, $legacy, true)['status'] ?? '') === 'AMBIGUOUS', 'Conflicting legacy candidates were not reported as ambiguous.');
    $query($admin, "INSERT INTO course_items (course_id,item_id,item_title,item_type,template_type,assignment_id,section_id,status) VALUES ('course-a','duplicate','Duplicate Homework','quiz','classified_assignment','A','section-a','published')");
    $canonical = $admin->query("SELECT * FROM course_items WHERE item_id='canonical'")->fetch_assoc();
    $assert((mmh_assignment_identity_for_item($admin, $canonical, true)['status'] ?? '') === 'DUPLICATE_CANONICAL_CLAIM', 'Duplicate normal Homework canonical claim was silently selected.');
    $sourceMap = mmh_assignment_progress_course_sources($admin, 'course-a');
    $assert(!isset($sourceMap['assignments']['A']) && count(array_filter($sourceMap['conflicts'], static fn($c) => ($c['assignment_id'] ?? '') === 'A')) === 1, 'Assignment progress silently selected one duplicate canonical claimant.');

    $assert(mmh_admin_course_item_has_activity($admin, 'course-a', 'history-item'), 'History detection missed submissions because the reverse item link is stale.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_submissions")->fetch_assoc()['n'] === 2, 'Identity checks changed historical submissions.');

    $legacy = $admin->query("SELECT * FROM course_items WHERE item_id='legacy'")->fetch_assoc();
    $legacyIdentity = mmh_assignment_identity_for_item($admin, $legacy, true);
    [$safe] = mmh_assignment_identity_reconciliation_safety($admin, $legacy, $legacyIdentity);
    $assert(!$safe, 'Ambiguous legacy candidate was marked safe to link.');

    $safeItem = $admin->query("SELECT * FROM course_items WHERE item_id='safe-item'")->fetch_assoc();
    $safeIdentity = mmh_assignment_identity_for_item($admin, $safeItem, true);
    [$safeToLink] = mmh_assignment_identity_reconciliation_safety($admin, $safeItem, $safeIdentity);
    $assert($safeToLink && ($safeIdentity['status'] ?? '') === 'LEGACY_FALLBACK', 'Unique compatible candidate with existing history was not safely classified.');

    $claimsMissing = $admin->query("SELECT * FROM course_items WHERE item_id='claims-missing'")->fetch_assoc();
    $claimsMissingIdentity = mmh_assignment_identity_for_item($admin, $claimsMissing, true);
    [$claimsMissingSafe] = mmh_assignment_identity_reconciliation_safety($admin, $claimsMissing, $claimsMissingIdentity);
    $assert(!$claimsMissingSafe && ($claimsMissingIdentity['status'] ?? '') === 'CONFLICTING_LEGACY', 'JSON A + nonexistent HTML MISSING was incorrectly SAFE TO LINK.');
    $claimsSame = $admin->query("SELECT * FROM course_items WHERE item_id='claims-same'")->fetch_assoc();
    $claimsSameIdentity = mmh_assignment_identity_for_item($admin, $claimsSame, true);
    [$claimsSameSafe] = mmh_assignment_identity_reconciliation_safety($admin, $claimsSame, $claimsSameIdentity);
    $assert($claimsSameSafe && ($claimsSameIdentity['status'] ?? '') === 'LEGACY_FALLBACK', 'Matching JSON A + HTML A was not safe.');
    $claimsConflict = $admin->query("SELECT * FROM course_items WHERE item_id='claims-conflict'")->fetch_assoc();
    $claimsConflictIdentity = mmh_assignment_identity_for_item($admin, $claimsConflict, true);
    [$claimsConflictSafe] = mmh_assignment_identity_reconciliation_safety($admin, $claimsConflict, $claimsConflictIdentity);
    $assert(!$claimsConflictSafe && ($claimsConflictIdentity['status'] ?? '') === 'CONFLICTING_LEGACY', 'JSON A + HTML B was not reported as a conflict.');
    $htmlOnly = $admin->query("SELECT * FROM course_items WHERE item_id='html-only'")->fetch_assoc();
    $htmlOnlyIdentity = mmh_assignment_identity_for_item($admin, $htmlOnly, true);
    [$htmlOnlySafe] = mmh_assignment_identity_reconciliation_safety($admin, $htmlOnly, $htmlOnlyIdentity);
    $assert($htmlOnlySafe && ($htmlOnlyIdentity['assignment_id'] ?? '') === 'HTML_A', 'A single valid HTML claim was not safe.');
    $htmlMissingOnly = $admin->query("SELECT * FROM course_items WHERE item_id='html-missing-only'")->fetch_assoc();
    $htmlMissingIdentity = mmh_assignment_identity_for_item($admin, $htmlMissingOnly, true);
    [$htmlMissingSafe] = mmh_assignment_identity_reconciliation_safety($admin, $htmlMissingOnly, $htmlMissingIdentity);
    $assert(!$htmlMissingSafe && in_array(($htmlMissingIdentity['status'] ?? ''), ['UNRESOLVED', 'INVALID_LEGACY'], true), 'A nonexistent HTML-only Assignment claim was not unresolved/invalid.');
    $query($admin, "CREATE TRIGGER mmh_force_reverse_failure BEFORE UPDATE ON assignments FOR EACH ROW BEGIN IF NEW.assignment_id = 'SAFE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced rollback verification'; END IF; END");
    $rollbackObserved = false;
    try { mmh_assignment_identity_reconciliation_apply($admin, 'course-a', 'safe-item', 'SAFE'); } catch (Throwable $expected) { $rollbackObserved = true; }
    $assert($rollbackObserved, 'Forced compatibility synchronization failure did not propagate.');
    $safeAfterRollback = $admin->query("SELECT assignment_id FROM course_items WHERE item_id='safe-item'")->fetch_assoc();
    $assert(empty($safeAfterRollback['assignment_id']), 'Failed reconciliation partially committed the canonical link.');
    $query($admin, 'DROP TRIGGER mmh_force_reverse_failure');
    mmh_assignment_identity_reconciliation_apply($admin, 'course-a', 'safe-item', 'SAFE');
    mmh_assignment_identity_reconciliation_apply($admin, 'course-a', 'safe-item', 'SAFE');
    $safePromoted = $admin->query("SELECT assignment_id FROM course_items WHERE item_id='safe-item'")->fetch_assoc();
    $safeReverse = $admin->query("SELECT item_id FROM assignments WHERE assignment_id='SAFE'")->fetch_assoc();
    $assert(($safePromoted['assignment_id'] ?? '') === 'SAFE' && ($safeReverse['item_id'] ?? '') === 'safe-item', 'Safe reconciliation did not update canonical and compatibility links together.');
    $safeAfterApply = $admin->query("SELECT * FROM course_items WHERE item_id='safe-item'")->fetch_assoc();
    $assert((mmh_assignment_identity_for_item($admin, $safeAfterApply, true)['status'] ?? '') === 'CLEAN', 'Reconciliation is not idempotent on the next read.');

    echo "assignment_identity_database=canonical_precedence=PASS reverse_non_authority=PASS orphan_fail_closed=PASS invalid_canonical=PASS ambiguous_legacy=PASS duplicate_claim=PASS canonical_history=PASS reconciliation_rollback=PASS reconciliation_idempotence=PASS contradictory_missing_legacy=PASS legacy_claim_matrix=PASS admin_without_archived_at=PASS\n";
} finally {
    $cleanup = mysqli_connect($hostName, $userName, $password);
    if ($cleanup instanceof mysqli && preg_match('/\\Ammh_assignment_identity_test_[0-9]+_[a-f0-9]{8}\\z/', $database)) { $cleanup->query('DROP DATABASE `' . $database . '`'); $cleanup->close(); }
}
