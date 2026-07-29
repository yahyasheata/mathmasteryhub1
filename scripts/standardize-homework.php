#!/usr/bin/env php
<?php
/**
 * Conservative, reversible Homework standardization.
 *
 * Default mode is dry-run and never creates tables or mutates data.
 * --apply additionally requires --confirm. Rollback restores only rows backed
 * up by a named migration and removes generated assignments only when no
 * student submission was ever attached.
 */
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../inc/CourseResourceResolver.php';
require_once __DIR__ . '/../inc/CourseHomeworkRenderer.php';

const MMH_HOMEWORK_MIGRATION_VERSION = 1;
const MMH_HOMEWORK_DEFAULT_FALLBACK_DUE = '2025-12-31 23:59:00';

function hmw_arg(array $args, string $name, $default = null) {
    foreach ($args as $arg) {
        if ($arg === $name) return true;
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return $default;
}
function hmw_json($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function hmw_safe_url($url): ?string { return mmh_course_resource_safe_url($url); }
function hmw_resource_slot($url, string $fallback = 'external_link', ?string $release = null): ?array {
    $url = hmw_safe_url($url);
    if ($url === null) return null;
    $type = mmh_course_resource_type_for_url($url, $fallback);
    $slot = ['url' => $url, 'provider' => $type, 'resource_type' => $type, 'embed' => !in_array($type, ['teams', 'google_drive_folder'], true)];
    if ($release !== null) $slot['release'] = $release;
    return $slot;
}
function hmw_fallback_due(): string {
    $configured = trim((string) getenv('MMH_HOMEWORK_FALLBACK_DUE_DATE'));
    $candidate = $configured !== '' ? $configured : MMH_HOMEWORK_DEFAULT_FALLBACK_DUE;
    try {
        $date = new DateTimeImmutable($candidate, new DateTimeZone('Asia/Riyadh'));
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Riyadh'));
        if ($date >= $now) throw new RuntimeException('Fallback due date must be in the past.');
        return $date->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new RuntimeException('Invalid MMH_HOMEWORK_FALLBACK_DUE_DATE: ' . $e->getMessage());
    }
}
function hmw_assignment(mysqli $conn, string $assignmentId, string $courseId): ?array {
    if ($assignmentId === '') return null;
    return mmh_homework_assignment($conn, $assignmentId, $courseId);
}
function hmw_submission_count(mysqli $conn, string $assignmentId): int {
    if ($assignmentId === '') return 0;
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM assignment_submissions WHERE assignment_id = ?');
    if (!$stmt) return 0;
    $stmt->bind_param('s', $assignmentId); $stmt->execute();
    $count = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)); $stmt->close();
    return $count;
}
function hmw_rows(mysqli $conn, ?string $courseFilter, ?string $itemFilter): array {
    $where = []; $types = ''; $params = [];
    if ($courseFilter) { $where[] = 'i.course_id = ?'; $types .= 's'; $params[] = $courseFilter; }
    if ($itemFilter) { $where[] = 'i.item_id = ?'; $types .= 's'; $params[] = $itemFilter; }
    $sql = 'SELECT i.*, c.course_title, COALESCE(s.title, "General") AS section_title FROM course_items i INNER JOIN courses c ON c.course_id = i.course_id LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY i.course_id, COALESCE(i.section_id, ""), i.page_order, i.item_id';
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare course-item inventory: ' . $conn->error);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute(); $result = $stmt->get_result(); $rows = [];
    while ($row = $result->fetch_assoc()) {
        $resource = mmh_course_resource_resolve($row);
        if (($resource['action'] ?? '') !== 'homework') continue;
        $assignmentId = (string) ($resource['assignment_id'] ?? '');
        $assignment = hmw_assignment($conn, $assignmentId, (string) $row['course_id']);
        $selection = ['item' => $row, 'section_id' => (string) ($row['section_id'] ?? '')];
        $model = mmh_homework_model_answer_resource($conn, ['course_id' => $row['course_id']], $selection, $resource);
        $data = mmh_course_resource_template_data($row['template_data'] ?? '');
        $homeworkSlot = is_array($resource['homework_resource'] ?? null) ? $resource['homework_resource'] : hmw_resource_slot($resource['homework_url'] ?? '');
        $hasNativeModel = is_array($resource['model_answer_resource'] ?? null);
        $deadline = trim((string) ($assignment['due_date'] ?? ''));
        $validDeadline = $deadline !== '' && strtotime($deadline) !== false;
        $needsAssignment = $assignment === null;
        $needsDeadline = $needsAssignment || !$validDeadline;
        $isStructured = strtolower((string) ($row['template_type'] ?? '')) === 'classified_assignment';
        $isCompliant = $isStructured && $homeworkSlot && $assignment && $validDeadline && array_key_exists('homework_resource', $data);
        $confidence = $homeworkSlot ? ($model && ($model['source'] ?? '') === 'legacy-adjacent' ? 'high' : 'medium') : 'unsafe';
        $action = !$homeworkSlot ? 'manual_review' : ($isCompliant && $hasNativeModel ? 'already_compliant' : 'standardize');
        $rows[] = [
            'row' => $row, 'resource' => $resource, 'assignment' => $assignment, 'model' => $model,
            'homework_slot' => $homeworkSlot, 'needs_assignment' => $needsAssignment,
            'needs_deadline' => $needsDeadline, 'is_compliant' => $isCompliant,
            'confidence' => $confidence, 'action' => $action,
            'submission_count' => $assignment ? hmw_submission_count($conn, (string) $assignment['assignment_id']) : 0,
        ];
    }
    $stmt->close(); return $rows;
}
function hmw_plan_row(array $record): array {
    $row = $record['row']; $assignment = $record['assignment']; $model = $record['model'];
    return [
        'course_id' => (string) $row['course_id'], 'course_title' => (string) $row['course_title'],
        'section_id' => (string) ($row['section_id'] ?? ''), 'section_title' => (string) $row['section_title'],
        'item_id' => (string) $row['item_id'], 'item_title' => (string) $row['item_title'], 'page_order' => (int) $row['page_order'],
        'template_type' => (string) ($row['template_type'] ?: 'legacy:' . $row['item_type']),
        'assignment_id' => (string) ($record['resource']['assignment_id'] ?? ''),
        'assignment_valid' => $assignment !== null, 'due_date' => $assignment['due_date'] ?? null,
        'submission_count' => $record['submission_count'], 'homework_provider' => $record['homework_slot']['resource_type'] ?? null,
        'model_answer_item_id' => $model['source_item_id'] ?? null, 'model_answer_provider' => $model['resource_type'] ?? null,
        'confidence' => $record['confidence'], 'proposed_action' => $record['action'],
        'create_assignment' => $record['needs_assignment'], 'generate_past_deadline' => $record['needs_deadline'],
    ];
}
function hmw_manual_model_answers(mysqli $conn, array $records): array {
    $linked = [];
    foreach ($records as $record) {
        if (!empty($record['model']['source_item_id'])) {
            $linked[(string) $record['row']['course_id'] . ':' . (string) $record['model']['source_item_id']] = true;
        }
    }
    $sql = 'SELECT i.*, c.course_title, COALESCE(s.title, "General") AS section_title FROM course_items i INNER JOIN courses c ON c.course_id=i.course_id LEFT JOIN course_sections s ON s.course_id=i.course_id AND s.section_id=i.section_id WHERE LOWER(i.item_title) REGEXP "model[[:space:]]+answer|homework[[:space:]]+answers?|mark[[:space:]]+scheme" ORDER BY i.course_id, COALESCE(i.section_id, ""), i.page_order, i.item_id';
    $result = $conn->query($sql); if (!$result) return [];
    $manual = [];
    while ($item = $result->fetch_assoc()) {
        $key = (string) $item['course_id'] . ':' . (string) $item['item_id'];
        if (isset($linked[$key])) continue;
        $resolved = mmh_course_resource_resolve($item);
        $manual[] = [
            'course_id' => (string) $item['course_id'], 'course_title' => (string) $item['course_title'],
            'section_id' => (string) ($item['section_id'] ?? ''), 'section_title' => (string) $item['section_title'],
            'item_id' => (string) $item['item_id'], 'item_title' => (string) $item['item_title'],
            'page_order' => (int) $item['page_order'],
            'template_type' => (string) ($item['template_type'] ?: 'legacy:' . $item['item_type']),
            'safe_resource_detected' => in_array((string) ($resolved['action'] ?? ''), ['embed', 'redirect'], true),
            'reason' => 'No single high-confidence adjacent Homework match; preserved as a separate lesson.',
        ];
    }
    return $manual;
}

function hmw_ensure_tables(mysqli $conn): void {
    $sql = [
        'CREATE TABLE IF NOT EXISTS homework_migration_runs (migration_id VARCHAR(80) NOT NULL PRIMARY KEY, migration_version INT NOT NULL, status VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL, applied_at DATETIME NULL, rolled_back_at DATETIME NULL, summary_json LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS homework_migration_backups (migration_id VARCHAR(80) NOT NULL, course_id VARCHAR(20) NOT NULL, item_id VARCHAR(20) NOT NULL, original_template_type VARCHAR(50) NULL, original_template_data LONGTEXT NULL, original_assignment_id INT NULL, original_due_date DATETIME NULL, original_item_description LONGTEXT NOT NULL, related_model_answer_item_id VARCHAR(20) NULL, created_assignment_id VARCHAR(20) NULL, assignment_snapshot LONGTEXT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (migration_id, course_id, item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS homework_migration_relations (migration_id VARCHAR(80) NOT NULL, course_id VARCHAR(20) NOT NULL, homework_item_id VARCHAR(20) NOT NULL, model_answer_item_id VARCHAR(20) NOT NULL, relation_status VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (migration_id, course_id, homework_item_id), KEY homework_migration_model_answer (course_id, model_answer_item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];
    foreach ($sql as $statement) if (!$conn->query($statement)) throw new RuntimeException('Unable to create migration storage: ' . $conn->error);
}
function hmw_new_assignment_id(mysqli $conn, string $itemId): string {
    $candidate = (string) (900000000 + ((int) $itemId % 99999999));
    for ($attempt = 0; $attempt < 1000; $attempt++, $candidate = (string) ((int) $candidate + 1)) {
        $stmt = $conn->prepare('SELECT 1 FROM assignments WHERE assignment_id = ? LIMIT 1'); $stmt->bind_param('s', $candidate); $stmt->execute(); $exists = $stmt->get_result()->num_rows > 0; $stmt->close();
        if (!$exists) return $candidate;
    }
    throw new RuntimeException('Unable to allocate a migration assignment ID.');
}
function hmw_backup(mysqli $conn, string $migrationId, array $record, ?string $createdAssignmentId): void {
    $row = $record['row']; $assignment = $record['assignment']; $model = $record['model'];
    $snapshot = $assignment ? hmw_json($assignment) : null;
    $stmt = $conn->prepare('INSERT INTO homework_migration_backups (migration_id,course_id,item_id,original_template_type,original_template_data,original_assignment_id,original_due_date,original_item_description,related_model_answer_item_id,created_assignment_id,assignment_snapshot,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())');
    if (!$stmt) throw new RuntimeException($conn->error);
    $templateType = $row['template_type'] ?: null; $templateData = $row['template_data'] ?: null; $oldAssignment = $row['assignment_id'] !== null ? (int) $row['assignment_id'] : null; $oldDue = $row['due_date'] ?: null; $description = (string) $row['item_description']; $modelId = $model['source_item_id'] ?? null;
    $stmt->bind_param('ssssisssssss', $migrationId, $row['course_id'], $row['item_id'], $templateType, $templateData, $oldAssignment, $oldDue, $description, $modelId, $createdAssignmentId, $snapshot);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error); $stmt->close();
}
function hmw_apply_record(mysqli $conn, string $migrationId, array $record, string $fallbackDue): array {
    $row = $record['row']; $resource = $record['resource']; $assignment = $record['assignment']; $model = $record['model'];
    $created = null;
    if ($record['needs_assignment']) {
        $created = hmw_new_assignment_id($conn, (string) $row['item_id']);
    }
    hmw_backup($conn, $migrationId, $record, $created);
    if ($created !== null) {
        $stmt = $conn->prepare('INSERT INTO assignments (assignment_id,assignment_title,assignment_description,due_date,course_id,section_id,item_id,homework_type,allow_self_score,require_teacher_verification) VALUES (?,?,?,?,?,?,?,?,0,1)');
        if (!$stmt) throw new RuntimeException($conn->error);
        $description = trim(strip_tags((string) ($resource['description'] ?? ''))); $section = $row['section_id'] ?: null; $type = 'classified_assignment';
        $stmt->bind_param('ssssssss', $created, $row['item_title'], $description, $fallbackDue, $row['course_id'], $section, $row['item_id'], $type);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error); $stmt->close();
        $assignment = hmw_assignment($conn, $created, (string) $row['course_id']);
    }
    if (!$assignment) throw new RuntimeException('No valid assignment after migration planning.');
    $due = trim((string) ($assignment['due_date'] ?? ''));
    if ($record['needs_deadline']) {
        $stmt = $conn->prepare('UPDATE assignments SET due_date = ? WHERE assignment_id = ? AND course_id = ?');
        $assignmentId = (string) $assignment['assignment_id']; $stmt->bind_param('sss', $fallbackDue, $assignmentId, $row['course_id']);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error); $stmt->close(); $due = $fallbackDue;
    }
    $data = mmh_course_resource_template_data($row['template_data'] ?? '');
    $homework = $record['homework_slot'];
    $data['template_type'] = 'classified_assignment';
    $data['assignment_id'] = (string) $assignment['assignment_id'];
    $data['url'] = $homework['url']; // existing reader compatibility
    $data['homework_resource'] = $homework;
    $data['due_date'] = $due;
    $data['instructions'] = $data['instructions'] ?? $data['description'] ?? (string) ($assignment['assignment_description'] ?? '');
    $data['visibility'] = $data['visibility'] ?? ['homework' => true, 'model_answer' => false];
    $compatibility = is_array($data['compatibility'] ?? null) ? $data['compatibility'] : [];
    $compatibility['legacy_item_id'] = (string) $row['item_id']; $compatibility['migration_version'] = MMH_HOMEWORK_MIGRATION_VERSION;
    if ($model && ($model['source'] ?? '') === 'legacy-adjacent') {
        $data['model_answer_resource'] = hmw_resource_slot($model['url'], $model['resource_type'] ?? 'external_link', 'immediate');
        $data['model_answer_release'] = 'immediate'; $data['visibility']['model_answer'] = true;
        $compatibility['model_answer_item_id'] = (string) $model['source_item_id'];
        $rel = $conn->prepare('INSERT INTO homework_migration_relations (migration_id,course_id,homework_item_id,model_answer_item_id,relation_status,created_at) VALUES (?,?,?,?,"linked",NOW())');
        $rel->bind_param('ssss', $migrationId, $row['course_id'], $row['item_id'], $model['source_item_id']); if (!$rel->execute()) throw new RuntimeException($rel->error); $rel->close();
    }
    $data['compatibility'] = $compatibility;
    $json = hmw_json($data); $assignmentIdInt = (int) $assignment['assignment_id'];
    $stmt = $conn->prepare('UPDATE course_items SET template_type = "classified_assignment", template_data = ?, assignment_id = ?, due_date = ? WHERE course_id = ? AND item_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('sisss', $json, $assignmentIdInt, $due, $row['course_id'], $row['item_id']);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error); $stmt->close();
    $ctx = $conn->prepare('UPDATE assignments SET section_id = ?, item_id = ? WHERE assignment_id = ? AND course_id = ?');
    $section = $row['section_id'] ?: null; $assignmentId = (string) $assignment['assignment_id']; $ctx->bind_param('ssss', $section, $row['item_id'], $assignmentId, $row['course_id']);
    if (!$ctx->execute()) throw new RuntimeException($ctx->error); $ctx->close();
    return ['created_assignment' => $created !== null, 'generated_deadline' => $record['needs_deadline'], 'attached_model' => $model && ($model['source'] ?? '') === 'legacy-adjacent'];
}
function hmw_rollback(mysqli $conn, string $migrationId): array {
    hmw_ensure_tables($conn); $stmt = $conn->prepare('SELECT * FROM homework_migration_backups WHERE migration_id = ? ORDER BY course_id,item_id'); $stmt->bind_param('s', $migrationId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    if (!$rows) throw new RuntimeException('No backup exists for migration ' . $migrationId);
    $conn->begin_transaction(); $restored = 0; $retained = 0;
    try {
        foreach ($rows as $backup) {
            $restore = $conn->prepare('UPDATE course_items SET template_type = ?, template_data = ?, assignment_id = ?, due_date = ?, item_description = ? WHERE course_id = ? AND item_id = ?');
            $oldAssignment = $backup['original_assignment_id'] !== null ? (int) $backup['original_assignment_id'] : null;
            $restore->bind_param('ssissss', $backup['original_template_type'], $backup['original_template_data'], $oldAssignment, $backup['original_due_date'], $backup['original_item_description'], $backup['course_id'], $backup['item_id']);
            if (!$restore->execute()) throw new RuntimeException($restore->error); $restore->close(); $restored++;
            $snapshot = json_decode((string) ($backup['assignment_snapshot'] ?? ''), true);
            if (is_array($snapshot) && !empty($snapshot['assignment_id'])) {
                // Restore the only existing-assignment fields this migration can
                // touch: due date plus its item/section context.
                $restoreAssignment = $conn->prepare('UPDATE assignments SET due_date = ?, section_id = ?, item_id = ? WHERE assignment_id = ? AND course_id = ?');
                $snapshotDue = $snapshot['due_date'] ?? null; $snapshotSection = $snapshot['section_id'] ?? null; $snapshotItem = $snapshot['item_id'] ?? null; $snapshotAssignment = (string) $snapshot['assignment_id'];
                $restoreAssignment->bind_param('sssss', $snapshotDue, $snapshotSection, $snapshotItem, $snapshotAssignment, $backup['course_id']);
                if (!$restoreAssignment->execute()) throw new RuntimeException($restoreAssignment->error); $restoreAssignment->close();
            }
            if (!empty($backup['created_assignment_id'])) {
                $count = hmw_submission_count($conn, (string) $backup['created_assignment_id']);
                if ($count === 0) { $del = $conn->prepare('DELETE FROM assignments WHERE assignment_id = ? AND course_id = ?'); $del->bind_param('ss', $backup['created_assignment_id'], $backup['course_id']); if (!$del->execute()) throw new RuntimeException($del->error); $del->close(); }
                else $retained++;
            }
        }
        $delRel = $conn->prepare('DELETE FROM homework_migration_relations WHERE migration_id = ?'); $delRel->bind_param('s', $migrationId); $delRel->execute(); $delRel->close();
        $run = $conn->prepare('UPDATE homework_migration_runs SET status = "rolled_back", rolled_back_at = NOW() WHERE migration_id = ?'); $run->bind_param('s', $migrationId); $run->execute(); $run->close();
        $conn->commit(); return ['restored' => $restored, 'generated_assignments_retained_due_to_submissions' => $retained];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

$args = array_slice($argv, 1); $apply = hmw_arg($args, '--apply', false) === true; $rollback = hmw_arg($args, '--rollback'); $dryRun = !$apply && !$rollback || hmw_arg($args, '--dry-run', false) === true;
$course = hmw_arg($args, '--course'); $item = hmw_arg($args, '--item'); $conn = db();
try {
    if ($rollback) { echo hmw_json(['mode' => 'rollback', 'migration_id' => $rollback, 'result' => hmw_rollback($conn, (string) $rollback)]) . PHP_EOL; exit(0); }
    $records = hmw_rows($conn, is_string($course) ? $course : null, is_string($item) ? $item : null);
    $plan = array_map('hmw_plan_row', $records);
    $manualModels = hmw_manual_model_answers($conn, $records);
    $summary = ['total' => count($plan), 'migrated' => 0, 'already_compliant' => 0, 'created_assignments' => 0, 'generated_deadlines' => 0, 'attached_model_answers' => 0, 'homework_without_high_confidence_model' => 0, 'manual_review' => count($manualModels), 'failed' => 0, 'skipped' => 0];
    foreach ($plan as $row) { if ($row['proposed_action'] === 'already_compliant') $summary['already_compliant']++; elseif ($row['proposed_action'] === 'manual_review') $summary['manual_review']++; else { $summary['migrated']++; if ($row['create_assignment']) $summary['created_assignments']++; if ($row['generate_past_deadline']) $summary['generated_deadlines']++; if ($row['model_answer_item_id']) $summary['attached_model_answers']++; else $summary['homework_without_high_confidence_model']++; } }
    if ($dryRun && !$apply) { echo hmw_json(['mode' => 'dry-run', 'migration_version' => MMH_HOMEWORK_MIGRATION_VERSION, 'fallback_due_date' => hmw_fallback_due(), 'summary' => $summary, 'rows' => $plan, 'manual_review_model_answers' => $manualModels]) . PHP_EOL; exit(0); }
    if (!$apply || hmw_arg($args, '--confirm', false) !== true) throw new RuntimeException('--apply requires --confirm after dry-run review.');
    hmw_ensure_tables($conn); $migrationId = (string) (hmw_arg($args, '--migration-id') ?: ('hmw-v' . MMH_HOMEWORK_MIGRATION_VERSION . '-' . gmdate('YmdHis'))); $fallbackDue = hmw_fallback_due();
    $insertRun = $conn->prepare('INSERT INTO homework_migration_runs (migration_id,migration_version,status,created_at,summary_json) VALUES (?, ?, "running", NOW(), ?)'); $summaryJson = hmw_json($summary); $version = MMH_HOMEWORK_MIGRATION_VERSION; $insertRun->bind_param('sis', $migrationId, $version, $summaryJson); if (!$insertRun->execute()) throw new RuntimeException($insertRun->error); $insertRun->close();
    $applied = ['migrated' => 0, 'created_assignments' => 0, 'generated_deadlines' => 0, 'attached_model_answers' => 0, 'already_compliant' => 0, 'manual_review' => 0, 'failed' => 0];
    foreach ($records as $record) {
        if ($record['action'] === 'already_compliant') { $applied['already_compliant']++; continue; }
        if ($record['action'] === 'manual_review') { $applied['manual_review']++; continue; }
        $conn->begin_transaction();
        try { $outcome = hmw_apply_record($conn, $migrationId, $record, $fallbackDue); $conn->commit(); $applied['migrated']++; foreach (['created_assignment' => 'created_assignments', 'generated_deadline' => 'generated_deadlines', 'attached_model' => 'attached_model_answers'] as $from => $to) if (!empty($outcome[$from])) $applied[$to]++; }
        catch (Throwable $e) { $conn->rollback(); $applied['failed']++; throw $e; }
    }
    $done = $conn->prepare('UPDATE homework_migration_runs SET status = "applied", applied_at = NOW(), summary_json = ? WHERE migration_id = ?'); $resultJson = hmw_json($applied); $done->bind_param('ss', $resultJson, $migrationId); $done->execute(); $done->close();
    echo hmw_json(['mode' => 'apply', 'migration_id' => $migrationId, 'fallback_due_date' => $fallbackDue, 'summary' => $applied]) . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'Homework migration failed: ' . $e->getMessage() . PHP_EOL); exit(1); }
