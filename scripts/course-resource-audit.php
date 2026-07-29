#!/usr/bin/env php
<?php
/**
 * Read-only Course Resource Viewer audit.
 * Usage: php scripts/course-resource-audit.php [course_id]
 * Outputs CSV without external target URLs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../inc/CourseResourceResolver.php';

$conn = db();
$courseId = trim((string) ($argv[1] ?? ''));
$sql = "SELECT i.course_id, i.section_id, i.item_id, i.item_title, i.item_type, i.template_type, i.item_description, i.template_data,
        s.title AS section_title
    FROM course_items AS i
    LEFT JOIN course_sections AS s ON s.course_id = i.course_id AND s.section_id = i.section_id";
$params = [];
if ($courseId !== '') {
    $sql .= ' WHERE i.course_id = ?';
    $params[] = $courseId;
}
$sql .= ' ORDER BY i.course_id, CASE WHEN i.section_id IS NULL OR i.section_id = \'\' THEN 0 ELSE 1 END, s.sort_order, i.sort_order, i.page_order, i.id';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "Unable to prepare audit query.\n");
    exit(1);
}
if ($params) {
    $stmt->bind_param('s', $params[0]);
}
$stmt->execute();
$result = $stmt->get_result();
$output = fopen('php://output', 'w');
fputcsv($output, ['course_id', 'section', 'item_id', 'title', 'template', 'provider', 'behavior', 'reason', 'normalization', 'manual_review'], ',', chr(34), '');
$richTypes = ['classified_assignment', 'assignment', 'exam', 'custom_lesson', 'custom_html', 'embed', 'quiz'];
while ($item = $result->fetch_assoc()) {
    $resolution = mmh_course_resource_resolve($item);
    $type = strtolower(trim((string) ($item['template_type'] ?: $item['item_type'])));
    $behavior = (string) ($resolution['action'] ?? 'unavailable');
    $provider = (string) ($resolution['embed_kind'] ?? ($behavior === 'redirect' ? 'external' : 'lesson'));
    $hasTarget = preg_match('/https?:\/\//i', (string) ($item['item_description'] ?? '')) === 1 || preg_match('/https?:\/\//i', (string) ($item['template_data'] ?? '')) === 1;
    $manualReview = $behavior === 'render' && $hasTarget && !in_array($type, $richTypes, true);
    $reason = $behavior === 'embed' ? 'Safe single-resource preview.' : ($resolution['reason'] ?? ($behavior === 'redirect' ? 'External provider is not embeddable.' : 'Existing rich or ambiguous lesson is preserved.'));
    fputcsv($output, [
        $item['course_id'],
        trim((string) ($item['section_title'] ?? '')) ?: 'General',
        $item['item_id'],
        $item['item_title'],
        $item['template_type'] ?: 'legacy:' . $item['item_type'],
        $provider,
        $behavior,
        $reason,
        'Runtime only — no database rewrite',
        $manualReview ? 'yes' : 'no',
    ], ',', chr(34), '');
}
fclose($output);
$stmt->close();
