<?php
/**
 * Give every active visible assignment lesson its own canonical assignment.
 * Dry-run by default: php scripts/repair-visible-assignment-links.php --course=3078
 * Apply:              php scripts/repair-visible-assignment-links.php --course=3078 --apply
 */
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/CourseAssignmentLinks.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['course:', 'apply']);
$courseId = trim((string) ($options['course'] ?? ''));
$apply = array_key_exists('apply', $options);
if ($courseId === '') {
    fwrite(STDERR, "Missing --course\n");
    exit(2);
}

$conn = db();
$stmt = $conn->prepare(
    "SELECT i.* FROM course_items i
     LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id
     WHERE i.course_id = ?
       AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
       AND (i.section_id IS NULL OR i.section_id = '' OR s.status IS NULL OR s.status = '' OR s.status = 'published')
     ORDER BY COALESCE(i.page_order, i.sort_order, i.id), i.id"
);
$stmt->bind_param('s', $courseId);
$stmt->execute();
$items = [];
$result = $stmt->get_result();
while ($item = $result->fetch_assoc()) {
    $assignmentId = mmh_course_assignment_id($item);
    $type = strtolower(trim((string) ($item['template_type'] ?? '')));
    $legacyType = strtolower(trim((string) ($item['item_type'] ?? '')));
    if ($assignmentId !== '' && (in_array($type, ['classified_assignment', 'assignment', 'homework'], true) || in_array($legacyType, ['quiz', 'assignment', 'homework'], true))) {
        $item['_assignment_id'] = $assignmentId;
        $items[] = $item;
    }
}
$stmt->close();

$groups = [];
foreach ($items as $item) {
    $groups[$item['_assignment_id']][] = $item;
}
$repairs = [];
foreach ($groups as $assignmentId => $linkedItems) {
    if (count($linkedItems) < 2) {
        continue;
    }
    $owner = null;
    $ownerStmt = $conn->prepare('SELECT item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
    $ownerStmt->bind_param('ss', $assignmentId, $courseId);
    $ownerStmt->execute();
    $owner = trim((string) ($ownerStmt->get_result()->fetch_assoc()['item_id'] ?? ''));
    $ownerStmt->close();
    foreach ($linkedItems as $index => $item) {
        if ($owner !== '' && (string) $item['item_id'] === $owner) {
            continue;
        }
        if ($owner === '' && $index === 0) {
            continue;
        }
        $repairs[] = $item;
    }
}

echo 'visible_assignments=' . count($items) . PHP_EOL;
echo 'distinct_assignment_ids=' . count($groups) . PHP_EOL;
echo 'repairs=' . count($repairs) . PHP_EOL;
foreach ($repairs as $item) {
    echo ($apply ? 'repair' : 'would_repair') . ' item=' . $item['item_id'] . ' source_assignment=' . $item['_assignment_id'] . ' title=' . $item['item_title'] . PHP_EOL;
}
if (!$apply || !$repairs) {
    exit(0);
}

try {
    $conn->begin_transaction();
    foreach ($repairs as $item) {
        $oldId = (string) $item['_assignment_id'];
        $newId = mmh_course_assignment_clone_for_item($conn, $courseId, $oldId, (string) $item['item_id'], (string) ($item['section_id'] ?? ''));
        if ($newId === null) {
            throw new RuntimeException('Source assignment ' . $oldId . ' was not found.');
        }
        mmh_course_assignment_relink_item($conn, $courseId, (string) $item['item_id'], $oldId, $newId);
        echo 'relinked item=' . $item['item_id'] . ' assignment=' . $newId . PHP_EOL;
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
