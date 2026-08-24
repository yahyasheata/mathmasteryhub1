<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This command can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/TimedExam.php';

$options = getopt('', ['apply', 'dry-run', 'exam-id:', 'include-finalized']);
$apply = array_key_exists('apply', $options);
$dryRun = !$apply;
$examId = isset($options['exam-id']) ? max(0, (int) $options['exam-id']) : 0;
$includeFinalized = array_key_exists('include-finalized', $options);
$conn = db();
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$sql = "SELECT * FROM timed_exams WHERE status = 'published' AND deleted_at IS NULL AND scheduled_start_at_utc IS NOT NULL";
$params = [];
$types = '';
if ($examId > 0) {
    $sql .= ' AND id = ?';
    $params[] = $examId;
    $types .= 'i';
}
$sql .= ' ORDER BY scheduled_start_at_utc ASC, id ASC';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "Unable to load Timed Exams for lifecycle finalization.\n");
    exit(2);
}
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = [
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'exams_scanned' => 0,
    'exams_due' => 0,
    'eligible' => 0,
    'submitted' => 0,
    'auto_submitted' => 0,
    'no_submission' => 0,
    'already_terminal' => 0,
    'changed' => 0,
    'results_released' => 0,
    'results_already_released' => 0,
    'premature_release_markers' => 0,
    'failed' => 0,
];

foreach ($exams as $exam) {
    $summary['exams_scanned']++;
    $deadline = mmh_timed_exam_effective_deadline($exam);
    $deadlinePassed = $deadline instanceof DateTimeImmutable && $now > $deadline;
    $alreadyMarked = mmh_timed_exam_roster_finalized_for_generation($exam);
    if ($deadlinePassed && ($includeFinalized || !$alreadyMarked)) {
        $summary['exams_due']++;
        $roster = mmh_timed_exam_finalize_exam_roster($conn, $exam, $dryRun, $now);
        foreach (['eligible', 'submitted', 'auto_submitted', 'no_submission', 'already_terminal', 'changed', 'failed'] as $key) {
            $summary[$key] += (int) ($roster[$key] ?? 0);
        }
    }

    $release = mmh_timed_exam_release_due_results($conn, $exam, $dryRun, $now);
    $summary['results_released'] += (int) ($release['released'] ?? 0);
    $summary['results_already_released'] += (int) ($release['already_released'] ?? 0);
    $summary['failed'] += (int) ($release['failed'] ?? 0);

    $releaseAt = mmh_timed_exam_utc_datetime((string) ($exam['results_release_at_utc'] ?? ''));
    if ($releaseAt !== null) {
        $audit = $conn->prepare('SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id = ? AND results_released_at_utc IS NOT NULL AND results_released_at_utc < ?');
        if (!$audit) {
            $summary['failed']++;
        } else {
            $examKey = (int) $exam['id'];
            $configuredRelease = $releaseAt->format('Y-m-d H:i:s');
            $audit->bind_param('is', $examKey, $configuredRelease);
            $audit->execute();
            $summary['premature_release_markers'] += (int) ($audit->get_result()->fetch_assoc()['total'] ?? 0);
            $audit->close();
        }
    }
}

foreach ($summary as $key => $value) {
    echo $key . '=' . $value . PHP_EOL;
}

if ($summary['failed'] > 0) exit(1);
exit(0);
