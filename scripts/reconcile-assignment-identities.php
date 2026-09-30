<?php
declare(strict_types=1);

/**
 * Dry-run Assignment identity reconciliation.
 *
 * Read-only by default:
 *   php scripts/reconcile-assignment-identities.php
 *   php scripts/reconcile-assignment-identities.php --course=3078
 *
 * Explicit application is opt-in and only promotes an unambiguous legacy
 * reference into course_items.assignment_id. It never clones assignments and
 * never changes submissions, grades, files, or other course content.
 */
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/AssignmentIdentityReconciliation.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['course:', 'apply']);
$courseFilter = trim((string) ($options['course'] ?? ''));
$apply = array_key_exists('apply', $options);
$conn = db();

$sql = "SELECT i.*
        FROM course_items AS i
        WHERE (i.status IS NULL OR i.status = '' OR i.status = 'published')";
$types = '';
$params = [];
if ($courseFilter !== '') {
    $sql .= ' AND i.course_id = ?';
    $types = 's';
    $params[] = $courseFilter;
}
$sql .= ' ORDER BY i.course_id ASC, i.id ASC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "Unable to prepare Course Item scan.\n");
    exit(1);
}
if ($types !== '') {
    $stmt->bind_param('s', $courseFilter);
}
if (!$stmt->execute()) {
    fwrite(STDERR, "Unable to scan Course Items.\n");
    exit(1);
}

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'scanned' => 0,
    'homework_items' => 0,
    'clean' => 0,
    'legacy_fallback' => 0,
    'orphaned_canonical' => 0,
    'conflict' => 0,
    'ambiguous' => 0,
    'unresolved' => 0,
    'duplicate_without_history' => 0,
    'conflict_with_history' => 0,
    'safe_to_link' => 0,
    'promoted' => 0,
    'manual_review' => 0,
];
$findings = [];
$assignmentGroups = [];
$historyCache = [];
$result = $stmt->get_result();
while ($item = $result->fetch_assoc()) {
    $summary['scanned']++;
    if (!mmh_assignment_identity_is_homework_item($item)) {
        continue;
    }
    $summary['homework_items']++;
    $identity = mmh_assignment_identity_for_item($conn, $item, true, true);
    $status = strtolower((string) ($identity['status'] ?? 'unresolved'));
    $assignmentId = (string) ($identity['assignment_id'] ?? '');
    [$safeToLink, $safetyReason] = mmh_assignment_identity_reconciliation_safety($conn, $item, $identity);
    if ($assignmentId !== '' && !isset($historyCache[$assignmentId])) {
        $historyCache[$assignmentId] = ['submissions' => 0, 'graded' => 0, 'feedback' => 0];
        $historyStmt = $conn->prepare("SELECT COUNT(*) AS submissions,
                SUM(CASE WHEN grade IS NOT NULL AND TRIM(grade) <> '' THEN 1 ELSE 0 END) AS graded,
                SUM(CASE WHEN feedback IS NOT NULL AND TRIM(feedback) <> '' THEN 1 ELSE 0 END) AS feedback
            FROM assignment_submissions WHERE assignment_id = ?");
        if ($historyStmt) {
            $historyStmt->bind_param('s', $assignmentId);
            $historyStmt->execute();
            $historyCache[$assignmentId] = array_map('intval', $historyStmt->get_result()->fetch_assoc() ?: $historyCache[$assignmentId]);
            $historyStmt->close();
        }
    }
    $finding = [
        'course_id' => (string) ($item['course_id'] ?? ''),
        'item_id' => (string) ($item['item_id'] ?? ''),
        'title' => (string) ($item['item_title'] ?? ''),
        'status' => strtoupper($status),
        'identity_status' => strtoupper($status),
        'assignment_id' => $assignmentId,
        'source' => (string) ($identity['source'] ?? ''),
        'submission_count' => (int) ($historyCache[$assignmentId]['submissions'] ?? 0),
        'graded_count' => (int) ($historyCache[$assignmentId]['graded'] ?? 0),
        'feedback_count' => (int) ($historyCache[$assignmentId]['feedback'] ?? 0),
        'proposed_action' => $safeToLink ? 'SAFE TO LINK (explicit apply only)' : 'NO AUTOMATIC ACTION',
        'safe_to_link' => $safeToLink,
        'safety_reason' => $safetyReason,
        'reverse_item_id' => '',
        'competing_canonical_item_ids' => [],
        'submission_file_count' => 0,
        'confidence' => $status === 'clean' ? 'high' : ($safeToLink ? 'high' : 'low'),
    ];

    if ($assignmentId !== '') {
        $candidateStmt = $conn->prepare('SELECT item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        if ($candidateStmt) {
            $course = (string) $item['course_id']; $candidateStmt->bind_param('ss', $assignmentId, $course); $candidateStmt->execute();
            $finding['reverse_item_id'] = trim((string) (($candidateStmt->get_result()->fetch_assoc()['item_id'] ?? ''))); $candidateStmt->close();
        }
        $claimStmt = $conn->prepare('SELECT item_id FROM course_items WHERE course_id = ? AND CAST(assignment_id AS CHAR) = ? AND item_id <> ? ORDER BY id ASC');
        if ($claimStmt) {
            $course = (string) $item['course_id']; $itemKey = (string) $item['item_id']; $claimStmt->bind_param('sss', $course, $assignmentId, $itemKey); $claimStmt->execute();
            $finding['competing_canonical_item_ids'] = array_map('strval', array_column($claimStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'item_id')); $claimStmt->close();
        }
        if (isset($conn->query("SHOW TABLES LIKE 'assignment_submission_files'")->fetch_row()[0])) {
            $fileStmt = $conn->prepare('SELECT COUNT(*) AS total FROM assignment_submission_files f INNER JOIN assignment_submissions s ON s.id = f.submission_id WHERE s.assignment_id = ?');
            if ($fileStmt) { $fileStmt->bind_param('s', $assignmentId); $fileStmt->execute(); $finding['submission_file_count'] = (int) ($fileStmt->get_result()->fetch_assoc()['total'] ?? 0); $fileStmt->close(); }
        }
    }

    if ($apply && $safeToLink && $assignmentId !== '') {
        $finding['apply_candidate'] = true;
    }
    if ($assignmentId !== '') {
        $assignmentGroups[$assignmentId][] = count($findings);
    }
    $findings[] = $finding;
}
$stmt->close();

// A single Assignment claimed by multiple Course Items is a duplicate
// relationship even when each individual row looks structurally clean. It is
// safe to report, but never safe to merge automatically when history exists.
foreach ($assignmentGroups as $assignmentId => $indexes) {
    if (count($indexes) < 2) continue;
    $hasHistory = (int) ($historyCache[$assignmentId]['submissions'] ?? 0) > 0
        || (int) ($historyCache[$assignmentId]['graded'] ?? 0) > 0
        || (int) ($historyCache[$assignmentId]['feedback'] ?? 0) > 0;
    $duplicateStatus = $hasHistory ? 'CONFLICT_WITH_HISTORY' : 'DUPLICATE_WITHOUT_HISTORY';
    $action = $hasHistory ? 'MANUAL REVIEW REQUIRED' : 'MANUAL REVIEW / SAFE TO SPLIT AFTER CONFIRMATION';
    foreach ($indexes as $index) {
        $findings[$index]['status'] = $duplicateStatus;
        $findings[$index]['proposed_action'] = $action;
        $findings[$index]['confidence'] = 'low';
        $findings[$index]['safe_to_link'] = false;
        $findings[$index]['safety_reason'] = 'Assignment is referenced by multiple Course Items; manual review required.';
        $findings[$index]['duplicate_claimants'] = count($indexes);
    }
}

if ($apply) {
    foreach ($findings as $index => $finding) {
        // Never promote a legacy link if the same Assignment is claimed by
        // another Course Item; classify that group before any write.
        if (empty($finding['apply_candidate']) || ($finding['status'] ?? '') !== 'LEGACY_FALLBACK' || empty($finding['safe_to_link'])) {
            continue;
        }
        try {
            mmh_assignment_identity_reconciliation_apply($conn, (string) $finding['course_id'], (string) $finding['item_id'], (string) $finding['assignment_id']);
            $findings[$index]['promoted'] = true;
            $summary['promoted']++;
        } catch (Throwable $exception) {
            $findings[$index]['error'] = $exception->getMessage();
            $summary['manual_review']++;
        }
    }
}

foreach (['clean', 'legacy_fallback', 'orphaned_canonical', 'invalid_canonical', 'conflict', 'ambiguous', 'unresolved', 'duplicate_without_history', 'conflict_with_history'] as $key) {
    $summary[$key] = 0;
}
$summary['manual_review'] = 0;
foreach ($findings as $finding) {
    $key = strtolower((string) ($finding['status'] ?? 'unresolved'));
    $summary[$key] = ($summary[$key] ?? 0) + 1;
    if (in_array($key, ['orphaned_canonical', 'invalid_canonical', 'conflict', 'ambiguous', 'unresolved', 'duplicate_without_history', 'conflict_with_history'], true)) {
        $summary['manual_review']++;
    }
    if (!empty($finding['safe_to_link']) && ($finding['status'] ?? '') === 'LEGACY_FALLBACK') $summary['safe_to_link']++;
}

echo json_encode(['summary' => $summary, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
