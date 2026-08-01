<?php
/**
 * Repairs copied visible homework links before the student assignment list is
 * read. This is intentionally idempotent: once each visible item owns a
 * distinct assignment row, subsequent requests do nothing.
 */
if (!function_exists('mmh_live_assignment_repair')) {
    function mmh_live_assignment_repair(mysqli $conn, string $courseId): void
    {
        $stmt = $conn->prepare("SELECT i.item_id, i.item_title, i.item_description, i.section_id, i.due_date, i.template_data, i.assignment_id
             FROM course_items i LEFT JOIN course_sections s ON s.course_id=i.course_id AND s.section_id=i.section_id
             WHERE i.course_id=? AND (i.status IS NULL OR i.status='' OR i.status='published')
               AND (i.section_id IS NULL OR i.section_id='' OR s.status IS NULL OR s.status='' OR s.status='published')
               AND (i.template_type IN ('classified_assignment','assignment','homework') OR i.item_type IN ('quiz','assignment','homework'))
               AND i.assignment_id IS NOT NULL AND i.assignment_id <> ''
             ORDER BY COALESCE(s.sort_order,2147483647), COALESCE(i.sort_order,i.page_order,i.id), i.id");
        if (!$stmt) return;
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = [];
        while ($row = $result->fetch_assoc()) $groups[(string) $row['assignment_id']][] = $row;
        $stmt->close();
        $groups = array_filter($groups, static fn(array $rows): bool => count($rows) > 1);
        if (!$groups) return;

        $conn->begin_transaction();
        try {
            foreach ($groups as $sourceId => $rows) {
                $ownerStmt = $conn->prepare('SELECT item_id FROM assignments WHERE assignment_id=? AND course_id=? LIMIT 1');
                $ownerStmt->bind_param('ss', $sourceId, $courseId);
                $ownerStmt->execute();
                $owner = trim((string) ($ownerStmt->get_result()->fetch_assoc()['item_id'] ?? ''));
                $ownerStmt->close();
                $kept = false;
                foreach ($rows as $row) {
                    $itemId = (string) $row['item_id'];
                    if (($owner !== '' && $itemId === $owner) || ($owner === '' && $kept === false)) {
                        $kept = true;
                        continue;
                    }
                    $newId = mmh_live_assignment_new_id($conn);
                    $sourceStmt = $conn->prepare('SELECT * FROM assignments WHERE assignment_id=? AND course_id=? LIMIT 1');
                    $sourceStmt->bind_param('ss', $sourceId, $courseId);
                    $sourceStmt->execute();
                    $assignment = $sourceStmt->get_result()->fetch_assoc();
                    $sourceStmt->close();
                    if (!$assignment) continue;
                    unset($assignment['id'], $assignment['created_at']);
                    $assignment['assignment_id'] = $newId;
                    $assignment['item_id'] = $itemId;
                    $assignment['section_id'] = (string) ($row['section_id'] ?? '');
                    $columns = array_keys($assignment);
                    $sql = 'INSERT INTO assignments (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                    $insert = $conn->prepare($sql);
                    $values = array_values($assignment);
                    $bind = [str_repeat('s', count($values))];
                    foreach ($values as $index => &$value) $bind[] = &$value;
                    $insert->bind_param(...$bind);
                    if (!$insert->execute()) throw new RuntimeException($insert->error ?: $conn->error);
                    $insert->close();

                    $data = json_decode((string) ($row['template_data'] ?? ''), true);
                    if (is_array($data)) mmh_live_assignment_replace_json_id($data, $sourceId, $newId);
                    $templateData = is_array($data) ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) ($row['template_data'] ?? '');
                    $update = $conn->prepare('UPDATE course_items SET assignment_id=?, template_data=? WHERE course_id=? AND item_id=? LIMIT 1');
                    $numericId = ctype_digit($newId) ? (int) $newId : null;
                    $update->bind_param('isss', $numericId, $templateData, $courseId, $itemId);
                    if (!$update->execute()) throw new RuntimeException($update->error ?: $conn->error);
                    $update->close();
                    $dataArray = is_array($data) ? $data : [];
                    $resource = is_array($dataArray['homework_resource'] ?? null) ? $dataArray['homework_resource'] : [];
                    $url = (string) ($resource['url'] ?? $dataArray['url'] ?? $dataArray['assignment_drive_url'] ?? '');
                    $instructions = (string) ($dataArray['instructions'] ?? $dataArray['description'] ?? '');
                    $title = (string) ($row['item_title'] ?? '');
                    $dueDate = (string) ($row['due_date'] ?? ($dataArray['due_date'] ?? ''));
                    $context = $conn->prepare('UPDATE assignments SET assignment_title=?, assignment_description=?, due_date=?, file_path=?, section_id=?, item_id=? WHERE assignment_id=? AND course_id=? LIMIT 1');
                    $sectionId = (string) ($row['section_id'] ?? '');
                    $context->bind_param('ssssssss', $title, $instructions, $dueDate, $url, $sectionId, $itemId, $newId, $courseId);
                    if (!$context->execute()) throw new RuntimeException($context->error ?: $conn->error);
                    $context->close();
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    function mmh_live_assignment_new_id(mysqli $conn): string
    {
        do {
            $id = (string) random_int(10000, 99999);
            $stmt = $conn->prepare('SELECT 1 FROM assignments WHERE assignment_id=? LIMIT 1');
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } while ($exists);
        return $id;
    }

    function mmh_live_assignment_replace_json_id(&$value, string $oldId, string $newId): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => &$child) {
            if ($key === 'assignment_id' && trim((string) $child) === $oldId) $child = $newId;
            else mmh_live_assignment_replace_json_id($child, $oldId, $newId);
        }
    }
}
