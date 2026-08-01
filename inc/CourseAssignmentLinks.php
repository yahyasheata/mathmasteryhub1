<?php
require_once __DIR__ . '/CourseResourceResolver.php';

if (!function_exists('mmh_course_assignment_generate_id')) {
    function mmh_course_assignment_generate_id(mysqli $conn): string
    {
        do {
            $id = (string) random_int(10000, 99999);
            $stmt = $conn->prepare('SELECT 1 FROM assignments WHERE assignment_id = ? LIMIT 1');
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } while ($exists);
        return $id;
    }
}

if (!function_exists('mmh_course_assignment_replace_id')) {
    function mmh_course_assignment_replace_id($value, string $oldId, string $newId)
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ($key === 'assignment_id' && trim((string) $child) === $oldId) {
                    $value[$key] = $newId;
                } else {
                    $value[$key] = mmh_course_assignment_replace_id($child, $oldId, $newId);
                }
            }
        }
        return $value;
    }
}

if (!function_exists('mmh_course_assignment_clone_for_item')) {
    /** Clone the current assignment policy, then give the copied visible item its own identity. */
    function mmh_course_assignment_clone_for_item(mysqli $conn, string $courseId, string $sourceAssignmentId, string $itemId, string $sectionId): ?string
    {
        $stmt = $conn->prepare('SELECT * FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('ss', $sourceAssignmentId, $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        unset($row['id'], $row['created_at']);
        $newId = mmh_course_assignment_generate_id($conn);
        $row['assignment_id'] = $newId;
        $row['item_id'] = $itemId;
        $row['section_id'] = $sectionId;
        $columns = array_keys($row);
        $sql = 'INSERT INTO assignments (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $insert = $conn->prepare($sql);
        $values = array_values($row);
        $refs = [str_repeat('s', count($values))];
        foreach ($values as $index => $value) {
            $refs[] = &$values[$index];
        }
        $insert->bind_param(...$refs);
        if (!$insert->execute()) {
            throw new RuntimeException($insert->error ?: $conn->error);
        }
        $insert->close();
        return $newId;
    }
}

if (!function_exists('mmh_course_assignment_relink_item')) {
    function mmh_course_assignment_relink_item(mysqli $conn, string $courseId, string $itemId, string $oldId, string $newId): void
    {
        $stmt = $conn->prepare('SELECT item_title, section_id, due_date, template_data, item_description FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1');
        $stmt->bind_param('ss', $courseId, $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) {
            throw new RuntimeException('Course item not found while relinking assignment.');
        }
        $data = mmh_course_resource_template_data($item['template_data'] ?? '');
        $resource = is_array($data['homework_resource'] ?? null) ? $data['homework_resource'] : [];
        $title = trim((string) ($item['item_title'] ?? ''));
        $assignmentDescription = (string) ($data['instructions'] ?? $data['description'] ?? '');
        $dueDate = trim((string) ($item['due_date'] ?? ($data['due_date'] ?? '')));
        $filePath = trim((string) ($resource['url'] ?? $data['url'] ?? $data['assignment_drive_url'] ?? ''));
        $maxScore = is_numeric($data['max_score'] ?? null) ? (string) $data['max_score'] : null;
        $data = mmh_course_assignment_replace_id($data, $oldId, $newId);
        $templateData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $description = preg_replace_callback('/(\bdata-assignment-id\s*=\s*(["\']))\s*' . preg_quote($oldId, '/') . '\s*\2/i', static fn($m) => $m[1] . $newId . $m[2], (string) ($item['item_description'] ?? ''));
        $numericId = ctype_digit($newId) ? (int) $newId : null;
        $update = $conn->prepare('UPDATE course_items SET assignment_id = ?, template_data = ?, item_description = ? WHERE course_id = ? AND item_id = ? LIMIT 1');
        $update->bind_param('issss', $numericId, $templateData, $description, $courseId, $itemId);
        if (!$update->execute()) {
            throw new RuntimeException($update->error ?: $conn->error);
        }
        $update->close();
        $assignment = $conn->prepare('UPDATE assignments SET assignment_title = ?, assignment_description = ?, due_date = ?, file_path = ?, section_id = ?, item_id = ?, max_score = COALESCE(?, max_score) WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        $sectionId = (string) ($item['section_id'] ?? '');
        $assignment->bind_param('sssssssss', $title, $assignmentDescription, $dueDate, $filePath, $sectionId, $itemId, $maxScore, $newId, $courseId);
        if (!$assignment->execute()) {
            throw new RuntimeException($assignment->error ?: $conn->error);
        }
        $assignment->close();
    }
}
