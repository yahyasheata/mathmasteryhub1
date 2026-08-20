<?php
declare(strict_types=1);

/**
 * Transactional, configuration-only copying for Course Content.
 *
 * This service deliberately never reads or writes student attempts,
 * submissions, grades, attendance, progress, notifications, or journey data.
 */
final class CourseContentCopyService
{
    /** @return array{course_id:string,section_id:?string,item_id:string,warnings:array<int,string>} */
    public static function copyItem(mysqli $conn, string $sourceCourseId, string $sourceItemId, string $destinationCourseId, ?string $destinationSectionId = null): array
    {
        self::assertCourse($conn, $sourceCourseId);
        self::assertCourse($conn, $destinationCourseId);
        $source = self::fetchOne($conn, 'SELECT * FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1', 'ss', [$sourceCourseId, $sourceItemId]);
        if (!$source) {
            throw new RuntimeException('The source course item was not found.');
        }
        $destinationSectionId = self::normalizeDestinationSection($conn, $destinationCourseId, $destinationSectionId);

        $conn->begin_transaction();
        try {
            $maps = ['sections' => [], 'items' => [], 'assignments' => [], 'assignment_refs' => []];
            $copy = self::copyItemRow($conn, $source, $destinationCourseId, $destinationSectionId, $maps);
            self::finalizeItemReferences($conn, $copy['item_id'], $maps);
            self::finalizeAssignmentReferences($conn, $maps);
            $conn->commit();
            return [
                'course_id' => $destinationCourseId,
                'section_id' => $destinationSectionId,
                'item_id' => $copy['item_id'],
                'warnings' => $copy['warnings'],
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /** @return array{course_id:string,section_id:string,item_ids:array<int,string>,warnings:array<int,string>} */
    public static function copySection(mysqli $conn, string $sourceCourseId, string $sourceSectionId, string $destinationCourseId): array
    {
        self::assertCourse($conn, $sourceCourseId);
        self::assertCourse($conn, $destinationCourseId);
        $source = self::fetchOne($conn, 'SELECT * FROM course_sections WHERE course_id = ? AND section_id = ? LIMIT 1', 'ss', [$sourceCourseId, $sourceSectionId]);
        if (!$source) {
            throw new RuntimeException('The source section was not found.');
        }
        $items = self::fetchAll($conn, 'SELECT * FROM course_items WHERE course_id = ? AND section_id = ? ORDER BY page_order ASC, sort_order ASC, id ASC', 'ss', [$sourceCourseId, $sourceSectionId]);

        $conn->begin_transaction();
        try {
            $newSectionId = self::uniqueId($conn, 'course_sections', 'section_id', $destinationCourseId, 10000, 999999);
            $newSort = self::nextOrder($conn, 'course_sections', 'sort_order', 'course_id', $destinationCourseId);
            $warnings = [];
            $sectionValues = self::sectionValues($source, $destinationCourseId, $newSectionId, $newSort, $warnings);
            self::insertRow($conn, 'course_sections', $sectionValues);

            $maps = ['sections' => [(string) $sourceSectionId => $newSectionId], 'items' => [], 'assignments' => [], 'assignment_refs' => []];
            $itemIds = [];
            $order = 1;
            foreach ($items as $item) {
                $copy = self::copyItemRow($conn, $item, $destinationCourseId, $newSectionId, $maps, $order);
                $itemIds[] = $copy['item_id'];
                $warnings = array_merge($warnings, $copy['warnings']);
                $order++;
            }

            self::remapSectionReferences($conn, $newSectionId, $source, $maps, $warnings);
            foreach ($itemIds as $newItemId) {
                self::finalizeItemReferences($conn, $newItemId, $maps);
            }
            self::finalizeAssignmentReferences($conn, $maps);
            $conn->commit();
            return ['course_id' => $destinationCourseId, 'section_id' => $newSectionId, 'item_ids' => $itemIds, 'warnings' => array_values(array_unique($warnings))];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    private static function assertCourse(mysqli $conn, string $courseId): void
    {
        if ($courseId === '' || !self::fetchOne($conn, 'SELECT course_id FROM courses WHERE course_id = ? LIMIT 1', 's', [$courseId])) {
            throw new RuntimeException('The selected course is not available.');
        }
    }

    private static function normalizeDestinationSection(mysqli $conn, string $courseId, ?string $sectionId): ?string
    {
        $sectionId = trim((string) $sectionId);
        if ($sectionId === '' || $sectionId === '__general__') {
            return null;
        }
        if (!self::fetchOne($conn, 'SELECT section_id FROM course_sections WHERE course_id = ? AND section_id = ? LIMIT 1', 'ss', [$courseId, $sectionId])) {
            throw new RuntimeException('The selected destination section is not available in that course.');
        }
        return $sectionId;
    }

    private static function copyItemRow(mysqli $conn, array $source, string $destinationCourseId, ?string $destinationSectionId, array &$maps, ?int $forcedOrder = null): array
    {
        $sourceItemId = (string) ($source['item_id'] ?? '');
        $newItemId = self::uniqueId($conn, 'course_items', 'item_id', $destinationCourseId, 100, 999999);
        $order = $forcedOrder ?? self::nextItemOrder($conn, $destinationCourseId, $destinationSectionId);
        $maps['items'][$sourceItemId] = $newItemId;
        $warnings = [];
        $assignmentId = trim((string) ($source['assignment_id'] ?? ''));
        if ($assignmentId === '') {
            $rawTemplate = is_string($source['template_data'] ?? null) ? json_decode((string) $source['template_data'], true) : null;
            if (is_array($rawTemplate)) {
                $assignmentId = trim((string) ($rawTemplate['assignment_id'] ?? ($rawTemplate['homework']['assignment_id'] ?? '')));
            }
        }
        $newAssignmentId = null;
        if ($assignmentId !== '') {
            $newAssignmentId = self::copyAssignment($conn, $source, $assignmentId, $destinationCourseId, $destinationSectionId, $newItemId, $maps, $warnings);
            if ($newAssignmentId !== null) {
                $maps['assignments'][$assignmentId] = $newAssignmentId;
            }
        }

        $template = strtolower(trim((string) ($source['template_type'] ?? $source['item_type'] ?? '')));
        $templateData = self::jsonRemap($source['template_data'] ?? null, $maps);
        $metadata = self::jsonSanitize($source['metadata'] ?? null, $warnings);
        $values = [
            'item_id' => $newItemId,
            'item_title' => rtrim((string) ($source['item_title'] ?? '')) . ' (Copy)',
            'item_description' => (string) ($source['item_description'] ?? ''),
            'item_type' => (string) ($source['item_type'] ?? 'file'),
            'section_id' => $destinationSectionId,
            'template_type' => $source['template_type'] ?? null,
            'template_data' => $templateData,
            'metadata' => $metadata,
            'duration_minutes' => $source['duration_minutes'] ?? null,
            'assignment_id' => $newAssignmentId !== null && ctype_digit((string) $newAssignmentId) ? (int) $newAssignmentId : null,
            'due_date' => $source['due_date'] ?? null,
            'status' => (string) ($source['status'] ?? 'draft'),
            'sort_order' => $order,
            'course_id' => $destinationCourseId,
            'page_order' => $order,
        ];
        self::insertKnownColumns($conn, 'course_items', $values);

        if ($template === 'timed_exam') {
            self::copyTimedExam($conn, (string) ($source['course_id'] ?? ''), $sourceItemId, $destinationCourseId, $newItemId, $warnings);
        }
        return ['item_id' => $newItemId, 'warnings' => $warnings];
    }

    private static function copyAssignment(mysqli $conn, array $sourceItem, string $oldId, string $destinationCourseId, ?string $destinationSectionId, string $newItemId, array $maps, array &$warnings): ?string
    {
        $oldId = trim($oldId);
        $assignment = self::fetchOne($conn, 'SELECT * FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1', 'ss', [$oldId, (string) ($sourceItem['course_id'] ?? '')]);
        if (!$assignment) {
            $warnings[] = 'The assignment definition was not found, so the copied item has no assignment record.';
            return null;
        }
        $newId = self::uniqueAssignmentId($conn);
        $referenceSnapshot = [];
        foreach (['recommended_recording_item_id', 'recommended_notes_item_id', 'recommended_revision_item_id'] as $column) {
            if (array_key_exists($column, $assignment)) $referenceSnapshot[$column] = $assignment[$column];
        }
        $maps['assignment_refs'][$newId] = $referenceSnapshot;
        unset($assignment['id'], $assignment['created_at']);
        $assignment['assignment_id'] = $newId;
        $assignment['course_id'] = $destinationCourseId;
        $assignment['item_id'] = $newItemId;
        $assignment['section_id'] = $destinationSectionId;
        foreach (['archived_at', 'deleted_at'] as $column) {
            if (array_key_exists($column, $assignment)) $assignment[$column] = null;
        }
        foreach (['recommended_recording_item_id', 'recommended_notes_item_id', 'recommended_revision_item_id'] as $column) {
            if (array_key_exists($column, $assignment)) $assignment[$column] = $maps['items'][(string) $assignment[$column]] ?? null;
        }
        self::insertRow($conn, 'assignments', $assignment);
        // Model-answer access rows are intentionally not copied: they are
        // student-specific permissions, never course content configuration.
        return $newId;
    }

    private static function copyTimedExam(mysqli $conn, string $sourceCourseId, string $sourceItemId, string $destinationCourseId, string $newItemId, array &$warnings): void
    {
        $exam = self::fetchOne($conn, 'SELECT * FROM timed_exams WHERE course_id = ? AND item_id = ? AND deleted_at IS NULL LIMIT 1', 'ss', [$sourceCourseId, $sourceItemId]);
        if (!$exam) return;
        // Keep paper configuration (including external-link variants) while
        // excluding attempts, submissions, grades, and roster lifecycle data.
        $paper_source = $exam['paper_source'] ?? null;
        $paper_external_url = $exam['paper_external_url'] ?? null;
        $paper_external_preview_url = $exam['paper_external_preview_url'] ?? null;
        $paper_external_download_url = $exam['paper_external_download_url'] ?? null;
        $paper_fallback_instructions = $exam['paper_fallback_instructions'] ?? null;
        unset($exam['id'], $exam['created_at'], $exam['updated_at'], $exam['deleted_at'], $exam['roster_finalized_at_utc']);
        $exam['course_id'] = $destinationCourseId;
        $exam['item_id'] = $newItemId;
        $exam['title'] = rtrim((string) ($exam['title'] ?? 'Timed Exam')) . ' (Copy)';
        $exam['status'] = 'draft';
        $exam['scheduled_start_at_utc'] = null;
        $exam['results_release_at_utc'] = null;
        $exam['recovery_window_start_at_utc'] = null;
        $exam['recovery_window_end_at_utc'] = null;
        $exam['roster_finalized_at_utc'] = null;
        $exam['paper_source'] = $paper_source;
        $exam['paper_external_url'] = $paper_external_url;
        $exam['paper_external_preview_url'] = $paper_external_preview_url;
        $exam['paper_external_download_url'] = $paper_external_download_url;
        $exam['paper_fallback_instructions'] = $paper_fallback_instructions;
        self::insertRow($conn, 'timed_exams', $exam);
        $warnings[] = 'Timed Exam copied as Draft with its schedule cleared for review.';
    }

    private static function sectionValues(array $source, string $destinationCourseId, string $newSectionId, int $sort, array &$warnings): array
    {
        $sourceUnlock = strtolower(trim((string) ($source['unlock_mode'] ?? 'always')));
        if ($sourceUnlock !== '' && $sourceUnlock !== 'always') {
            $warnings[] = 'The copied section learning rule was reset to Always Available and requires destination-course review.';
        }
        $sourceRelease = strtolower(trim((string) ($source['release_mode'] ?? 'inherit')));
        if ($sourceRelease !== '' && $sourceRelease !== 'inherit') {
            $warnings[] = 'The copied section release rule was reset and requires destination-course review.';
        }
        return [
            'section_id' => $newSectionId,
            'course_id' => $destinationCourseId,
            'title' => rtrim((string) ($source['title'] ?? 'Section')) . ' (Copy)',
            'section_type' => $source['section_type'] ?? null,
            'custom_type' => $source['custom_type'] ?? null,
            'icon' => $source['icon'] ?? null,
            'description' => $source['description'] ?? null,
            'metadata' => self::jsonSanitize($source['metadata'] ?? null, $warnings),
            'sort_order' => $sort,
            'status' => (string) ($source['status'] ?? 'draft'),
            'unlock_mode' => 'always',
            'completion_rule' => (string) ($source['completion_rule'] ?? 'manual_completion'),
            'unlock_at' => null,
            'unlock_timezone' => $source['unlock_timezone'] ?? null,
            'unlock_homework_id' => null,
            'manual_unlocked' => 0,
            'release_mode' => 'inherit',
            'release_override' => 'inherit',
            'release_at' => null,
            'release_timezone' => $source['release_timezone'] ?? null,
            'release_occurrence_id' => null,
            'release_delay_minutes' => 0,
            'release_updated_at' => null,
        ];
    }

    private static function remapSectionReferences(mysqli $conn, string $newSectionId, array $source, array $maps, array &$warnings): void
    {
        $oldUnlock = trim((string) ($source['unlock_homework_id'] ?? ''));
        if ($oldUnlock !== '' && isset($maps['assignments'][$oldUnlock])) {
            $value = $maps['assignments'][$oldUnlock];
            $stmt = $conn->prepare('UPDATE course_sections SET unlock_mode = ?, unlock_homework_id = ? WHERE section_id = ? LIMIT 1');
            $mode = (string) ($source['unlock_mode'] ?? 'always');
            $stmt->bind_param('sss', $mode, $value, $newSectionId);
            $stmt->execute();
            $stmt->close();
        } elseif ($oldUnlock !== '') {
            $warnings[] = 'The copied section rule was reset because its homework dependency was not copied.';
        }
    }

    private static function finalizeItemReferences(mysqli $conn, string $newItemId, array $maps): void
    {
        $item = self::fetchOne($conn, 'SELECT template_data, metadata FROM course_items WHERE item_id = ? LIMIT 1', 's', [$newItemId]);
        if (!$item) return;
        $data = self::jsonRemap($item['template_data'] ?? null, $maps);
        $metadata = self::jsonRemap($item['metadata'] ?? null, $maps);
        $stmt = $conn->prepare('UPDATE course_items SET template_data = ?, metadata = ? WHERE item_id = ? LIMIT 1');
        $dataJson = $data;
        $metaJson = $metadata;
        $stmt->bind_param('sss', $dataJson, $metaJson, $newItemId);
        $stmt->execute();
        $stmt->close();
    }

    private static function finalizeAssignmentReferences(mysqli $conn, array $maps): void
    {
        foreach (($maps['assignment_refs'] ?? []) as $newAssignmentId => $references) {
            $values = [];
            foreach (['recommended_recording_item_id', 'recommended_notes_item_id', 'recommended_revision_item_id'] as $column) {
                if (!array_key_exists($column, $references)) continue;
                $oldItemId = trim((string) $references[$column]);
                $values[$column] = $oldItemId !== '' ? ($maps['items'][$oldItemId] ?? null) : null;
            }
            if (!$values) continue;
            $set = implode(', ', array_map(static fn($column) => "`{$column}` = ?", array_keys($values)));
            $sql = 'UPDATE assignments SET ' . $set . ' WHERE assignment_id = ? LIMIT 1';
            $stmt = $conn->prepare($sql);
            $params = array_values($values);
            $params[] = (string) $newAssignmentId;
            self::bind($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
        }
    }

    private static function jsonRemap($value, array $maps): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) return is_string($value) && trim($value) !== '' ? (string) $value : null;
        $walk = static function ($node) use (&$walk, $maps) {
            if (!is_array($node)) return $node;
            foreach ($node as $key => $child) {
                if ($key === 'assignment_id' && isset($maps['assignments'][(string) $child])) $node[$key] = $maps['assignments'][(string) $child];
                elseif (in_array($key, ['item_id', 'recommended_item_id', 'recording_item_id', 'notes_item_id', 'revision_item_id'], true) && isset($maps['items'][(string) $child])) $node[$key] = $maps['items'][(string) $child];
                elseif ($key === 'section_id' && isset($maps['sections'][(string) $child])) $node[$key] = $maps['sections'][(string) $child];
                else $node[$key] = $walk($child);
            }
            return $node;
        };
        $result = $walk($decoded);
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function jsonSanitize($value, array &$warnings): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) return is_string($value) && trim($value) !== '' ? (string) $value : null;
        $removed = false;
        $walk = static function ($node) use (&$walk, &$removed) {
            if (!is_array($node)) return $node;
            foreach ($node as $key => $child) {
                if (in_array((string) $key, ['occurrence_id', 'schedule_id', 'live_session_occurrence_id', 'release_occurrence_id'], true)) {
                    unset($node[$key]); $removed = true; continue;
                }
                $node[$key] = $walk($child);
            }
            return $node;
        };
        $result = $walk($decoded);
        if ($removed) $warnings[] = 'Live-session references were omitted from the copy and require destination-course review.';
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function fetchOne(mysqli $conn, string $sql, string $types, array $values): ?array
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException($conn->error ?: 'Unable to prepare copy query.');
        self::bind($stmt, $types, $values);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private static function fetchAll(mysqli $conn, string $sql, string $types, array $values): array
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException($conn->error ?: 'Unable to prepare copy query.');
        self::bind($stmt, $types, $values);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private static function insertKnownColumns(mysqli $conn, string $table, array $values): void
    {
        $columns = array_keys($values);
        $existing = self::tableColumns($conn, $table);
        $values = array_intersect_key($values, array_flip($existing));
        self::insertRow($conn, $table, $values);
    }

    private static function insertRow(mysqli $conn, string $table, array $values): void
    {
        if (!$values) throw new RuntimeException('No copyable fields were found.');
        $columns = array_keys($values);
        $quoted = implode(',', array_map(static fn($column) => '`' . str_replace('`', '', (string) $column) . '`', $columns));
        $sql = 'INSERT INTO `' . str_replace('`', '', $table) . '` (' . $quoted . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException($conn->error ?: 'Unable to prepare copy insert.');
        $values = array_values($values);
        self::bind($stmt, str_repeat('s', count($values)), $values);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
        $stmt->close();
    }

    private static function bind(mysqli_stmt $stmt, string $types, array &$values): void
    {
        $refs = [$types];
        foreach ($values as $key => &$value) $refs[] = &$value;
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    private static function tableColumns(mysqli $conn, string $table): array
    {
        $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table); $stmt->execute();
        $columns = [];
        foreach ($stmt->get_result() as $row) $columns[] = (string) $row['COLUMN_NAME'];
        $stmt->close();
        return $columns;
    }

    private static function uniqueId(mysqli $conn, string $table, string $column, string $courseId, int $min, int $max): string
    {
        do {
            $candidate = (string) random_int($min, $max);
            $row = self::fetchOne($conn, "SELECT 1 FROM `{$table}` WHERE `{$column}` = ? AND course_id = ? LIMIT 1", 'ss', [$candidate, $courseId]);
        } while ($row);
        return $candidate;
    }

    private static function uniqueAssignmentId(mysqli $conn): string
    {
        do {
            $candidate = (string) random_int(10000, 99999);
            $row = self::fetchOne($conn, 'SELECT 1 FROM assignments WHERE assignment_id = ? LIMIT 1', 's', [$candidate]);
        } while ($row);
        return $candidate;
    }

    private static function nextOrder(mysqli $conn, string $table, string $column, string $courseColumn, string $courseId): int
    {
        $row = self::fetchOne($conn, "SELECT COALESCE(MAX(`{$column}`), 0) + 1 AS next_order FROM `{$table}` WHERE `{$courseColumn}` = ?", 's', [$courseId]);
        return max(1, (int) ($row['next_order'] ?? 1));
    }

    private static function nextItemOrder(mysqli $conn, string $courseId, ?string $sectionId): int
    {
        if ($sectionId === null) {
            $row = self::fetchOne($conn, "SELECT COALESCE(MAX(page_order), 0) + 1 AS next_order FROM course_items WHERE course_id = ? AND (section_id IS NULL OR section_id = '')", 's', [$courseId]);
        } else {
            $row = self::fetchOne($conn, 'SELECT COALESCE(MAX(page_order), 0) + 1 AS next_order FROM course_items WHERE course_id = ? AND section_id = ?', 'ss', [$courseId, $sectionId]);
        }
        return max(1, (int) ($row['next_order'] ?? 1));
    }
}
