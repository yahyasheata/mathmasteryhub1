<?php
/**
 * Report duplicate canonical Homework claims for manual review.
 * Automatic splitting is disabled because a reverse Assignment.item_id value
 * cannot safely choose which Course Item owns existing student history.
 */
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/AssignmentIdentity.php';

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
$conflicts = [];
$result = $stmt->get_result();
while ($item = $result->fetch_assoc()) {
    if (!mmh_assignment_identity_is_homework_item($item)) continue;
    $identity = mmh_assignment_identity_for_item($conn, $item, true);
    if (($identity['status'] ?? '') === 'DUPLICATE_CANONICAL_CLAIM') {
        $conflicts[] = ['assignment_id' => (string) ($identity['canonical_assignment_id'] ?? ''), 'item_id' => (string) $item['item_id'], 'claimant_item_ids' => (array) ($identity['claimant_item_ids'] ?? [])];
        continue;
    }
    $assignmentId = (string) ($identity['assignment_id'] ?? '');
    if ($assignmentId !== '' && in_array((string) ($identity['status'] ?? ''), ['CLEAN', 'CONFLICT', 'LEGACY_FALLBACK'], true)) {
        $item['_assignment_id'] = $assignmentId;
        $items[] = $item;
    }
}
$stmt->close();

$groups = [];
foreach ($items as $item) {
    $groups[$item['_assignment_id']][] = $item;
}
$duplicateGroups = [];
foreach ($groups as $assignmentId => $linkedItems) {
    if (count($linkedItems) > 1) $duplicateGroups[$assignmentId] = array_map(static fn($item) => (string) $item['item_id'], $linkedItems);
}

echo 'visible_assignments=' . count($items) . PHP_EOL;
echo 'distinct_assignment_ids=' . count($groups) . PHP_EOL;
echo 'manual_review_conflicts=' . (count($conflicts) + count($duplicateGroups)) . PHP_EOL;
foreach ($duplicateGroups as $assignmentId => $itemIds) echo 'duplicate_canonical_claim assignment=' . $assignmentId . ' items=' . implode(',', $itemIds) . PHP_EOL;
foreach ($conflicts as $conflict) echo 'duplicate_canonical_claim assignment=' . $conflict['assignment_id'] . ' items=' . implode(',', $conflict['claimant_item_ids']) . PHP_EOL;
if ($apply) {
    fwrite(STDERR, "Automatic duplicate splitting is disabled; reverse item_id cannot select an owner. No writes were made.\n");
    exit(3);
}
