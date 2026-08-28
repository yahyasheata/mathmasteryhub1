<?php
declare(strict_types=1);

/**
 * Revision Plan domain services.
 *
 * This service deliberately does not assign plans to students, write Learning
 * Journey state. Revision uploads are kept in their own evidence tables;
 * Homework/Assignment submissions and Recovery Plan tables remain separate.
 */

if (!function_exists('mmh_revision_schema_available')) {
    function mmh_revision_schema_available(mysqli $conn): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_templates'");
        if (!$stmt) return false;
        $stmt->execute();
        $ok = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_revision_batch_release_schema_available')) {
    function mmh_revision_batch_release_schema_available(mysqli $conn): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_batch_releases'");
        if (!$stmt) return false;
        $stmt->execute();
        $available = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $available;
    }
}

if (!function_exists('mmh_revision_batch_controls_schema_available')) {
    function mmh_revision_batch_controls_schema_available(mysqli $conn): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_template_batches' AND COLUMN_NAME = 'day_access_mode'");
        if (!$stmt) return false;
        $stmt->execute(); $ok = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0; $stmt->close(); return $ok;
    }
}

if (!function_exists('mmh_revision_manual_dates_schema_available')) {
    function mmh_revision_manual_dates_schema_available(mysqli $conn): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_template_batches' AND COLUMN_NAME = 'schedule_mode'");
        if (!$stmt) return false;
        $stmt->execute(); $batchMode = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)); $stmt->close();
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_template_days' AND COLUMN_NAME = 'scheduled_date'");
        if (!$stmt) return false;
        $stmt->execute(); $dayDate = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)); $stmt->close();
        return $batchMode > 0 && $dayDate > 0;
    }
}

if (!function_exists('mmh_revision_normalize_study_date')) {
    function mmh_revision_normalize_study_date($value, bool $required = false): ?string
    {
        $date = trim((string) $value);
        if ($date === '') {
            if ($required) throw new InvalidArgumentException('Enter a Study date for every Day when Manual dates is selected.');
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] ?? 0) > 0) || ($errors !== false && ($errors['error_count'] ?? 0) > 0) || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Study dates must use a valid calendar date.');
        }
        return $date;
    }
}

if (!function_exists('mmh_revision_batch_has_content')) {
    /** A batch may remain an empty shell until it is ready for release. */
    function mmh_revision_batch_has_content(array $batch): bool
    {
        foreach ((array) ($batch['days'] ?? []) as $day) {
            if (!is_array($day)) continue;
            if (!empty($day['requirements'])) return true;
            foreach ((array) ($day['activity_groups'] ?? []) as $group) {
                if (is_array($group) && !empty($group['requirements'])) return true;
            }
        }
        return false;
    }
}

if (!function_exists('mmh_revision_course')) {
    function mmh_revision_course(mysqli $conn, string $courseId): ?array
    {
        $stmt = $conn->prepare('SELECT course_id, course_title, course_state FROM courses WHERE course_id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('mmh_revision_courses')) {
    function mmh_revision_courses(mysqli $conn): array
    {
        $result = $conn->query("SELECT course_id, course_title FROM courses ORDER BY course_title ASC, course_id ASC");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('mmh_revision_course_items')) {
    function mmh_revision_course_items(mysqli $conn, string $courseId): array
    {
        $columnExists = static function (string $table, string $column) use ($conn): bool {
            $check = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            if (!$check) return false;
            $check->bind_param('ss', $table, $column);
            $check->execute();
            $exists = (bool) $check->get_result()->fetch_assoc();
            $check->close();
            return $exists;
        };
        $itemFilters = ['i.course_id = ?'];
        $sectionFilters = [];
        foreach (['archived_at', 'deleted_at'] as $column) {
            if ($columnExists('course_items', $column)) $itemFilters[] = "(i.{$column} IS NULL OR i.{$column} = '')";
            if ($columnExists('course_sections', $column)) $sectionFilters[] = "(s.{$column} IS NULL OR s.{$column} = '')";
        }
        // Admin authoring intentionally ignores publication/release state. It
        // only excludes archived/deleted rows; student access is enforced later.
        $where = implode(' AND ', $itemFilters);
        if ($sectionFilters) $where .= ' AND (' . implode(' AND ', $sectionFilters) . ')';
        // A non-empty section reference must resolve to a real section. Items
        // without a section remain valid general course content.
        $where .= " AND (i.section_id IS NULL OR i.section_id = '' OR s.section_id IS NOT NULL)";
        $stmt = $conn->prepare("SELECT i.item_id, i.item_title, i.item_description, i.section_id, i.item_type, i.template_type, i.template_data, i.assignment_id, i.duration_minutes, i.sort_order, i.page_order, s.title AS section_title, s.sort_order AS section_sort_order FROM course_items i LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id WHERE {$where} ORDER BY COALESCE(s.sort_order, 2147483647), COALESCE(i.sort_order, 2147483647), COALESCE(i.page_order, 2147483647), i.item_id ASC, i.id ASC");
        if (!$stmt) return [];
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_revision_templates')) {
    function mmh_revision_templates(mysqli $conn, bool $includeArchived = true): array
    {
        $where = $includeArchived ? '' : " WHERE t.status <> 'archived'";
        $sql = "SELECT t.id, t.course_id, t.title, t.description, t.status, t.created_at, t.updated_at, t.archived_at,
                    c.course_title,
                    v.id AS latest_version_id, v.version_number AS latest_version_number,
                    v.status AS latest_version_status, v.updated_at AS latest_version_updated_at,
                    (SELECT COUNT(*) FROM revision_plan_template_batches b WHERE b.version_id = v.id) AS batch_count,
                    (SELECT COUNT(*) FROM revision_plan_template_requirements r WHERE r.version_id = v.id) AS requirement_count
                FROM revision_plan_templates t
                LEFT JOIN courses c ON c.course_id = t.course_id
                LEFT JOIN revision_plan_template_versions v ON v.template_id = t.id
                    AND v.version_number = (SELECT MAX(v2.version_number) FROM revision_plan_template_versions v2 WHERE v2.template_id = t.id)
                {$where}
                ORDER BY t.updated_at DESC, t.id DESC";
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('mmh_revision_template')) {
    function mmh_revision_template(mysqli $conn, int $templateId): ?array
    {
        $stmt = $conn->prepare('SELECT t.*, c.course_title FROM revision_plan_templates t LEFT JOIN courses c ON c.course_id = t.course_id WHERE t.id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('mmh_revision_version')) {
    function mmh_revision_version(mysqli $conn, int $versionId): ?array
    {
        $stmt = $conn->prepare('SELECT v.*, t.title AS template_title, t.course_id, t.status AS template_status, c.course_title FROM revision_plan_template_versions v INNER JOIN revision_plan_templates t ON t.id = v.template_id LEFT JOIN courses c ON c.course_id = t.course_id WHERE v.id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $version = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($version)) return null;

        $version['batches'] = [];
        $batchStmt = $conn->prepare('SELECT * FROM revision_plan_template_batches WHERE version_id = ? ORDER BY sort_order ASC, id ASC');
        if ($batchStmt) {
            $batchStmt->bind_param('i', $versionId);
            $batchStmt->execute();
            $batches = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $batchStmt->close();
            foreach ($batches as $batch) {
                $batch['days'] = [];
                $dayStmt = $conn->prepare('SELECT * FROM revision_plan_template_days WHERE batch_id = ? ORDER BY sort_order ASC, id ASC');
                if ($dayStmt) {
                    $batchId = (int) $batch['id'];
                    $dayStmt->bind_param('i', $batchId);
                    $dayStmt->execute();
                    $days = $dayStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $dayStmt->close();
                    foreach ($days as $day) {
                        $day['activity_groups'] = [];
                        $day['requirements'] = [];
                        $dayId = (int) $day['id'];
                        $groupStmt = $conn->prepare('SELECT * FROM revision_plan_template_activities WHERE day_id = ? ORDER BY sort_order ASC, id ASC');
                        if ($groupStmt) {
                            $groupStmt->bind_param('i', $dayId);
                            $groupStmt->execute();
                            $groups = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $groupStmt->close();
                            foreach ($groups as $group) {
                                $group['requirements'] = [];
                                $groupId = (int) $group['id'];
                                $reqStmt = $conn->prepare('SELECT * FROM revision_plan_template_requirements WHERE activity_id = ? ORDER BY sort_order ASC, id ASC');
                                if ($reqStmt) {
                                    $reqStmt->bind_param('i', $groupId);
                                    $reqStmt->execute();
                                    $group['requirements'] = $reqStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $reqStmt->close();
                                }
                                foreach ($group['requirements'] as &$requirement) {
                                    $requirement['resource_ids'] = mmh_revision_requirement_resource_ids($conn, (int) $requirement['id']);
                                }
                                unset($requirement);
                                $day['activity_groups'][] = $group;
                            }
                        }
                        $directStmt = $conn->prepare('SELECT * FROM revision_plan_template_requirements WHERE version_id = ? AND day_id = ? AND activity_id IS NULL ORDER BY sort_order ASC, id ASC');
                        if ($directStmt) {
                            $directStmt->bind_param('ii', $versionId, $dayId);
                            $directStmt->execute();
                            $day['requirements'] = $directStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $directStmt->close();
                            foreach ($day['requirements'] as &$requirement) {
                                $requirement['resource_ids'] = mmh_revision_requirement_resource_ids($conn, (int) $requirement['id']);
                            }
                            unset($requirement);
                        }
                        $batch['days'][] = $day;
                    }
                }
                $version['batches'][] = $batch;
            }
        }
        $resourceStmt = $conn->prepare('SELECT * FROM revision_plan_template_resources WHERE version_id = ? ORDER BY sort_order ASC, id ASC');
        $version['resources'] = [];
        if ($resourceStmt) {
            $resourceStmt->bind_param('i', $versionId);
            $resourceStmt->execute();
            $version['resources'] = $resourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $resourceStmt->close();
        }
        return $version;
    }
}

if (!function_exists('mmh_revision_requirement_resource_ids')) {
    function mmh_revision_requirement_resource_ids(mysqli $conn, int $requirementId): array
    {
        $stmt = $conn->prepare('SELECT resource_id FROM revision_plan_requirement_resources WHERE requirement_id = ? ORDER BY sort_order ASC, id ASC');
        if (!$stmt) return [];
        $stmt->bind_param('i', $requirementId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_values(array_map(static fn(array $row): int => (int) $row['resource_id'], $rows));
    }
}

if (!function_exists('mmh_revision_latest_version_id')) {
    function mmh_revision_latest_version_id(mysqli $conn, int $templateId): int
    {
        $stmt = $conn->prepare('SELECT id FROM revision_plan_template_versions WHERE template_id = ? ORDER BY version_number DESC, id DESC LIMIT 1');
        if (!$stmt) return 0;
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $id = (int) (($stmt->get_result()->fetch_assoc()['id'] ?? 0));
        $stmt->close();
        return $id;
    }
}

if (!function_exists('mmh_revision_create_template')) {
    function mmh_revision_create_template(mysqli $conn, string $courseId, string $title, string $description, int $adminId): int
    {
        if (!mmh_revision_course($conn, $courseId)) throw new InvalidArgumentException('Choose a valid course.');
        $title = trim($title);
        if ($title === '') throw new InvalidArgumentException('Enter a template title.');
        $conn->begin_transaction();
        try {
            $status = 'active';
            $stmt = $conn->prepare('INSERT INTO revision_plan_templates (course_id, title, description, status, created_by) VALUES (?, ?, ?, ?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to create the Revision Plan template.');
            $stmt->bind_param('ssssi', $courseId, $title, $description, $status, $adminId);
            if (!$stmt->execute()) throw new RuntimeException('Unable to create the Revision Plan template.');
            $templateId = (int) $stmt->insert_id;
            $stmt->close();
            $versionStatus = 'draft';
            $versionNumber = 1;
            $version = $conn->prepare('INSERT INTO revision_plan_template_versions (template_id, version_number, status, created_by) VALUES (?, ?, ?, ?)');
            if (!$version) throw new RuntimeException('Unable to create the draft version.');
            $version->bind_param('iisi', $templateId, $versionNumber, $versionStatus, $adminId);
            if (!$version->execute()) throw new RuntimeException('Unable to create the draft version.');
            $versionId = (int) $version->insert_id;
            $version->close();
            $batch = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order) VALUES (?, ?, ?, ?, ?)');
            if (!$batch) throw new RuntimeException('Unable to create the first plan batch.');
            $batchTitle = 'Week 1'; $batchDescription = ''; $suggestedDays = 0; $batchOrder = 0;
            $batch->bind_param('issii', $versionId, $batchTitle, $batchDescription, $suggestedDays, $batchOrder);
            if (!$batch->execute()) throw new RuntimeException('Unable to create the first plan batch.');
            $batchId = (int) $batch->insert_id;
            $batch->close();
            $day = $conn->prepare('INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
            if (!$day) throw new RuntimeException('Unable to create the first plan day.');
            $dayNumber = 1; $dayTitle = 'Day 1'; $dayDescription = ''; $dayOrder = 0;
            $day->bind_param('iiissi', $batchId, $versionId, $dayNumber, $dayTitle, $dayDescription, $dayOrder);
            if (!$day->execute()) throw new RuntimeException('Unable to create the first plan day.');
            $day->close();
            $conn->commit();
            return $templateId;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('mmh_revision_structure')) {
    function mmh_revision_structure(mysqli $conn, int $versionId): array
    {
        $version = mmh_revision_version($conn, $versionId);
        return is_array($version) ? ['batches' => $version['batches'] ?? []] : ['batches' => []];
    }
}

if (!function_exists('mmh_revision_validate_structure')) {
    function mmh_revision_validate_structure(mysqli $conn, string $courseId, int $versionId, array $structure): array
    {
        $batches = is_array($structure['batches'] ?? null) ? $structure['batches'] : [];
        $resourceIds = [];
        $resources = $conn->prepare('SELECT id FROM revision_plan_template_resources WHERE version_id = ?');
        if ($resources) {
            $resources->bind_param('i', $versionId);
            $resources->execute();
            foreach ($resources->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $resourceIds[(int) $row['id']] = true;
            $resources->close();
        }
        $itemMap = [];
        foreach (mmh_revision_course_items($conn, $courseId) as $row) $itemMap[(string) $row['item_id']] = true;
        $clean = ['batches' => []];
        foreach ($batches as $batchIndex => $batch) {
            if (!is_array($batch)) continue;
            $batchTitle = mb_substr(trim((string) ($batch['title'] ?? '')), 0, 180);
            if ($batchTitle === '') throw new InvalidArgumentException('Every batch needs a title.');
            $dayAccess = strtolower(trim((string) ($batch['day_access_mode'] ?? 'follow_schedule')));
            if (!in_array($dayAccess, ['follow_schedule', 'open_all'], true)) $dayAccess = 'follow_schedule';
            $scheduleMode = strtolower(trim((string) ($batch['schedule_mode'] ?? 'automatic')));
            if (!in_array($scheduleMode, ['automatic', 'manual'], true)) $scheduleMode = 'automatic';
            if ($scheduleMode === 'manual' && !mmh_revision_manual_dates_schema_available($conn)) throw new RuntimeException('Manual dates are unavailable until the Revision Plan date migration is applied.');
            $cleanBatch = ['title' => $batchTitle, 'description' => mb_substr(trim((string) ($batch['description'] ?? '')), 0, 1000), 'suggested_days' => max(0, min(365, (int) ($batch['suggested_days'] ?? 0))), 'day_access_mode' => $dayAccess, 'schedule_mode' => $scheduleMode, 'sort_order' => count($clean['batches']), 'days' => []];
            foreach ((array) ($batch['days'] ?? []) as $dayIndex => $day) {
                if (!is_array($day)) continue;
                $dayTitle = mb_substr(trim((string) ($day['title'] ?? '')), 0, 180);
                if ($dayTitle === '') throw new InvalidArgumentException('Every day needs a title.');
                // Day numbers are presentation sequence, never a client-controlled ID.
                $scheduledDate = mmh_revision_normalize_study_date($day['scheduled_date'] ?? '', $scheduleMode === 'manual');
                $cleanDay = ['day_number' => count($cleanBatch['days']) + 1, 'title' => $dayTitle, 'description' => mb_substr(trim((string) ($day['description'] ?? '')), 0, 1000), 'scheduled_date' => $scheduledDate, 'sort_order' => count($cleanBatch['days']), 'requirements' => [], 'activity_groups' => []];
                $normalizeRequirement = static function ($requirement, int $sort, ?int $activityId, array &$cleanDay) use ($itemMap, $resourceIds): array {
                    if (!is_array($requirement)) return [];
                    $title = mb_substr(trim((string) ($requirement['title'] ?? '')), 0, 180);
                    if ($title === '') throw new InvalidArgumentException('Every requirement needs a title.');
                    $type = strtolower(trim((string) ($requirement['requirement_type'] ?? 'checklist')));
                    if (!in_array($type, ['checklist', 'resource', 'course_item', 'upload'], true)) throw new InvalidArgumentException('Unsupported requirement type.');
                    $itemId = trim((string) ($requirement['linked_course_item_id'] ?? ''));
                    if ($type === 'course_item' && ($itemId === '' || !isset($itemMap[$itemId]))) throw new InvalidArgumentException('Course Item requirements must reference an active item in the selected course.');
                    if ($itemId !== '' && !isset($itemMap[$itemId])) throw new InvalidArgumentException('A linked Course Item does not belong to the selected course.');
                    $selectedResources = [];
                    foreach ((array) ($requirement['resource_ids'] ?? []) as $resourceId) {
                        $resourceId = (int) $resourceId;
                        if ($resourceId > 0 && isset($resourceIds[$resourceId]) && !in_array($resourceId, $selectedResources, true)) $selectedResources[] = $resourceId;
                    }
                    if ($type === 'resource' && !$selectedResources) throw new InvalidArgumentException('Resource requirements must reference at least one shared material.');
                    return ['title' => $title, 'description' => mb_substr(trim((string) ($requirement['description'] ?? '')), 0, 2000), 'requirement_type' => $type, 'is_required' => !empty($requirement['is_required']) ? 1 : 0, 'sort_order' => $sort, 'activity_key' => $activityId, 'linked_course_item_id' => $itemId, 'allow_multiple_files' => !empty($requirement['allow_multiple_files']) ? 1 : 0, 'accepted_file_policy' => mb_substr(trim((string) ($requirement['accepted_file_policy'] ?? 'pdf')), 0, 80), 'resource_ids' => $selectedResources];
                };
                foreach ((array) ($day['requirements'] ?? []) as $reqIndex => $requirement) $cleanDay['requirements'][] = $normalizeRequirement($requirement, count($cleanDay['requirements']), null, $cleanDay);
                foreach ((array) ($day['activity_groups'] ?? []) as $groupIndex => $group) {
                    if (!is_array($group)) continue;
                    $groupTitle = mb_substr(trim((string) ($group['title'] ?? '')), 0, 180);
                    if ($groupTitle === '') throw new InvalidArgumentException('Every Activity Group needs a title.');
                    $cleanGroup = ['title' => $groupTitle, 'description' => mb_substr(trim((string) ($group['description'] ?? '')), 0, 1000), 'sort_order' => count($cleanDay['activity_groups']), 'requirements' => []];
                    foreach ((array) ($group['requirements'] ?? []) as $reqIndex => $requirement) $cleanGroup['requirements'][] = $normalizeRequirement($requirement, count($cleanGroup['requirements']), count($cleanDay['activity_groups']), $cleanDay);
                    $cleanDay['activity_groups'][] = $cleanGroup;
                }
                $cleanBatch['days'][] = $cleanDay;
            }
            $clean['batches'][] = $cleanBatch;
        }
        return $clean;
    }
}

if (!function_exists('mmh_revision_save_draft')) {
    function mmh_revision_save_draft(mysqli $conn, int $versionId, array $structure, string $title, string $description, bool $allowWorkAhead = false): void
    {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version || (string) $version['status'] !== 'draft') throw new InvalidArgumentException('Only a Draft Version can be edited. Create a new version to change finalized content.');
        $title = trim($title);
        if ($title === '') throw new InvalidArgumentException('Enter a template title.');
        $clean = mmh_revision_validate_structure($conn, (string) $version['course_id'], $versionId, $structure);
        /* Saving a Draft replaces its structural rows so IDs are intentionally
         * not stable. Preserve Batch-level material ownership by recording the
         * old Batch position before the replacement and restoring it onto the
         * newly-created Batch at the same position. */
        $resourceBatchPositions = [];
        $batchPositions = [];
        foreach ((array) ($version['batches'] ?? []) as $position => $existingBatch) $batchPositions[(int) ($existingBatch['id'] ?? 0)] = (int) $position;
        foreach ((array) ($version['resources'] ?? []) as $resource) {
            $oldBatchId = (int) ($resource['batch_id'] ?? 0);
            if ($oldBatchId > 0 && isset($batchPositions[$oldBatchId])) $resourceBatchPositions[(int) ($resource['id'] ?? 0)] = $batchPositions[$oldBatchId];
        }
        $conn->begin_transaction();
        try {
            $template = $conn->prepare('UPDATE revision_plan_templates SET title = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status <> \'archived\'');
            if (!$template) throw new RuntimeException('Unable to save the template details.');
            $template->bind_param('ssi', $title, $description, $version['template_id']);
            if (!$template->execute()) throw new RuntimeException('Unable to save the template details.');
            $template->close();
            $workAhead = $allowWorkAhead ? 1 : 0;
            $workAheadStmt = $conn->prepare('UPDATE revision_plan_template_versions SET allow_work_ahead = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = \'draft\'');
            if (!$workAheadStmt) throw new RuntimeException('Unable to save the work-ahead setting.');
            $workAheadStmt->bind_param('ii', $workAhead, $versionId);
            if (!$workAheadStmt->execute()) throw new RuntimeException('Unable to save the work-ahead setting.');
            $workAheadStmt->close();
            $clear = $conn->prepare('DELETE FROM revision_plan_template_batches WHERE version_id = ?');
            if (!$clear) throw new RuntimeException('Unable to replace the Draft Version structure.');
            $clear->bind_param('i', $versionId); if (!$clear->execute()) throw new RuntimeException('Unable to replace the Draft Version structure.'); $clear->close();
            $hasBatchAccess = mmh_revision_batch_controls_schema_available($conn);
            $hasManualDates = mmh_revision_manual_dates_schema_available($conn);
            foreach ($clean['batches'] as $batchPosition => $batch) {
                if ($hasManualDates) {
                    $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order, day_access_mode, schedule_mode) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    if (!$stmt) throw new RuntimeException('Unable to save a batch.');
                    $stmt->bind_param('issiiss', $versionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order'], $batch['day_access_mode'], $batch['schedule_mode']);
                } elseif ($hasBatchAccess) {
                    $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order, day_access_mode) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('issiis', $versionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order'], $batch['day_access_mode']);
                } else {
                    $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order) VALUES (?, ?, ?, ?, ?)');
                    $stmt->bind_param('issii', $versionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order']);
                }
                if (!$stmt || !$stmt->execute()) throw new RuntimeException('Unable to save a batch.'); $batchId = (int) $stmt->insert_id; $stmt->close();
                foreach ($resourceBatchPositions as $resourceId => $resourcePosition) {
                    if ($resourcePosition !== (int) $batchPosition) continue;
                    $resourceUpdate = $conn->prepare('UPDATE revision_plan_template_resources SET batch_id = ? WHERE id = ? AND version_id = ?');
                    if (!$resourceUpdate) throw new RuntimeException('Unable to preserve a Batch material association.');
                    $resourceUpdate->bind_param('iii', $batchId, $resourceId, $versionId);
                    if (!$resourceUpdate->execute()) throw new RuntimeException('Unable to preserve a Batch material association.');
                    $resourceUpdate->close();
                }
                foreach ($batch['days'] as $day) {
                    if ($hasManualDates) {
                        $stmt = $conn->prepare("INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, scheduled_date, sort_order) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?)");
                        if (!$stmt) throw new RuntimeException('Unable to save a day.');
                        $scheduledDate = (string) ($day['scheduled_date'] ?? '');
                        $stmt->bind_param('iiisssi', $batchId, $versionId, $day['day_number'], $day['title'], $day['description'], $scheduledDate, $day['sort_order']);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('iiissi', $batchId, $versionId, $day['day_number'], $day['title'], $day['description'], $day['sort_order']);
                    }
                    if (!$stmt || !$stmt->execute()) throw new RuntimeException('Unable to save a day.'); $dayId = (int) $stmt->insert_id; $stmt->close();
                    foreach ($day['requirements'] as $requirement) mmh_revision_insert_requirement($conn, $versionId, $dayId, null, $requirement);
                    foreach ($day['activity_groups'] as $group) {
                        $stmt = $conn->prepare('INSERT INTO revision_plan_template_activities (day_id, version_id, title, description, sort_order) VALUES (?, ?, ?, ?, ?)');
                        $stmt->bind_param('iissi', $dayId, $versionId, $group['title'], $group['description'], $group['sort_order']); if (!$stmt->execute()) throw new RuntimeException('Unable to save an Activity Group.'); $activityId = (int) $stmt->insert_id; $stmt->close();
                        foreach ($group['requirements'] as $requirement) mmh_revision_insert_requirement($conn, $versionId, $dayId, $activityId, $requirement);
                    }
                }
            }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('mmh_revision_insert_requirement')) {
    function mmh_revision_insert_requirement(mysqli $conn, int $versionId, int $dayId, ?int $activityId, array $requirement): void
    {
        $itemId = (string) ($requirement['linked_course_item_id'] ?? '');
        $stmt = $conn->prepare("INSERT INTO revision_plan_template_requirements (version_id, day_id, activity_id, title, description, requirement_type, is_required, sort_order, linked_course_item_id, allow_multiple_files, accepted_file_policy) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?)");
        if (!$stmt) throw new RuntimeException('Unable to save a requirement.');
        $activityValue = (int) ($activityId ?? 0); $type = (string) $requirement['requirement_type']; $required = (int) $requirement['is_required']; $sort = (int) $requirement['sort_order']; $multiple = (int) $requirement['allow_multiple_files']; $policy = (string) $requirement['accepted_file_policy'];
        $stmt->bind_param('iiisssiisis', $versionId, $dayId, $activityValue, $requirement['title'], $requirement['description'], $type, $required, $sort, $itemId, $multiple, $policy);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save a requirement.');
        $requirementId = (int) $stmt->insert_id; $stmt->close();
        foreach ((array) ($requirement['resource_ids'] ?? []) as $sortOrder => $resourceId) {
            $link = $conn->prepare('INSERT INTO revision_plan_requirement_resources (requirement_id, resource_id, sort_order) VALUES (?, ?, ?)');
            if (!$link) throw new RuntimeException('Unable to link a resource.');
            $link->bind_param('iii', $requirementId, $resourceId, $sortOrder); if (!$link->execute()) throw new RuntimeException('Unable to link a resource.'); $link->close();
        }
    }
}

if (!function_exists('mmh_revision_publish_version')) {
    /**
     * Publish an immutable Version and release each newly prepared contiguous
     * batch. Existing release rows are never replaced, so later Versions can
     * add Batch 2 without changing a student's released Batch 1 snapshot.
     */
    function mmh_revision_publish_version(mysqli $conn, int $versionId, int $adminId): void
    {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version) throw new InvalidArgumentException('Version not found.');
        if ((string) $version['status'] !== 'draft') throw new InvalidArgumentException('Only a Draft Version can be finalized.');
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT id, status FROM revision_plan_template_versions WHERE id = ? AND template_id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock the Version.');
            $lock->bind_param('ii', $versionId, $version['template_id']);
            $lock->execute();
            $locked = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if (!is_array($locked) || (string) $locked['status'] !== 'draft') throw new InvalidArgumentException('Only a Draft Version can be finalized.');
            $stmt = $conn->prepare("UPDATE revision_plan_template_versions SET status = 'published', published_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'draft'");
            if (!$stmt) throw new RuntimeException('Unable to finalize the Version.');
            $stmt->bind_param('i', $versionId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('The Version could not be finalized.');
            $stmt->close();
            if (mmh_revision_batch_release_schema_available($conn)) {
                $existing = [];
                $released = $conn->prepare("SELECT batch_position FROM revision_plan_batch_releases WHERE template_id = ? AND status = 'released' FOR UPDATE");
                if (!$released) throw new RuntimeException('Unable to inspect released Batches.');
                $released->bind_param('i', $version['template_id']);
                $released->execute();
                foreach ($released->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $existing[(int) $row['batch_position']] = true;
                $released->close();
                $insert = $conn->prepare("INSERT INTO revision_plan_batch_releases (template_id, source_version_id, source_batch_id, batch_position, status, visibility, day_access_mode, display_title, released_by) VALUES (?, ?, ?, ?, 'released', 'released', ?, NULLIF(?, ''), ?)");
                if (!$insert) throw new RuntimeException('Unable to prepare Batch release.');
                $inserted = 0;
                foreach ((array) ($version['batches'] ?? []) as $position => $batch) {
                    if (isset($existing[(int) $position])) continue;
                    if (!mmh_revision_batch_has_content((array) $batch)) break;
                    $batchId = (int) ($batch['id'] ?? 0);
                    if ($batchId <= 0) throw new RuntimeException('A Batch could not be released safely.');
                    $position = (int) $position;
                    $batchDayAccess = (string) ($batch['day_access_mode'] ?? 'follow_schedule');
                    $dayAccess = in_array($batchDayAccess, ['follow_schedule', 'open_all'], true) ? $batchDayAccess : 'follow_schedule';
                    $displayTitle = trim((string) ($batch['title'] ?? ''));
                    $insert->bind_param('iiiissi', $version['template_id'], $versionId, $batchId, $position, $dayAccess, $displayTitle, $adminId);
                    if (!$insert->execute()) throw new RuntimeException('Unable to release a Batch.');
                    $existing[$position] = true;
                    $inserted++;
                }
                $insert->close();
                if (!$existing && $inserted === 0) throw new InvalidArgumentException('Prepare the first Batch before publishing.');
            } elseif (!mmh_revision_batch_has_content((array) (($version['batches'] ?? [])[0] ?? []))) {
                throw new InvalidArgumentException('Prepare the first Batch before publishing.');
            }
            $touch = $conn->prepare('UPDATE revision_plan_templates SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            if ($touch) { $touch->bind_param('i', $version['template_id']); $touch->execute(); $touch->close(); }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('mmh_revision_batch_release_statuses')) {
    /** Return released metadata keyed by logical batch position for admin UI. */
    function mmh_revision_batch_release_statuses(mysqli $conn, int $templateId): array
    {
        if ($templateId <= 0 || !mmh_revision_batch_release_schema_available($conn)) return [];
        $stmt = $conn->prepare("SELECT batch_position, source_version_id, source_batch_id, released_at, visibility, day_access_mode, display_title FROM revision_plan_batch_releases WHERE template_id = ? AND status = 'released' ORDER BY batch_position ASC");
        if (!$stmt) return [];
        $stmt->bind_param('i', $templateId); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $result = [];
        foreach ($rows as $row) $result[(int) $row['batch_position']] = $row;
        return $result;
    }
}

if (!function_exists('mmh_revision_update_batch_controls')) {
    /** Update release metadata without mutating the immutable Batch content. */
    function mmh_revision_update_batch_controls(mysqli $conn, int $templateId, int $batchPosition, string $title, string $visibility, string $dayAccess, int $versionId = 0): void
    {
        if ($templateId <= 0 || $batchPosition < 0 || !mmh_revision_batch_release_schema_available($conn)) throw new InvalidArgumentException('Batch release not found.');
        $visibility = strtolower(trim($visibility));
        $dayAccess = strtolower(trim($dayAccess));
        if (!in_array($visibility, ['released', 'coming_soon'], true)) throw new InvalidArgumentException('Choose a valid Batch visibility.');
        if (!in_array($dayAccess, ['follow_schedule', 'open_all'], true)) throw new InvalidArgumentException('Choose a valid Day access mode.');
        $title = mb_substr(trim($title), 0, 180);
        if ($title === '') throw new InvalidArgumentException('Enter a Batch name.');
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT id, source_version_id FROM revision_plan_batch_releases WHERE template_id = ? AND batch_position = ? AND status = \'released\' FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to inspect the Batch release.');
            $lock->bind_param('ii', $templateId, $batchPosition); $lock->execute();
            $row = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$row) throw new InvalidArgumentException('Batch release not found.');
            if ($versionId > 0) {
                $versionCheck = $conn->prepare('SELECT template_id FROM revision_plan_template_versions WHERE id = ? LIMIT 1');
                if (!$versionCheck) throw new RuntimeException('Unable to verify the selected Version.');
                $versionCheck->bind_param('i', $versionId); $versionCheck->execute();
                $versionTemplate = (int) (($versionCheck->get_result()->fetch_assoc()['template_id'] ?? 0)); $versionCheck->close();
                if ($versionTemplate !== $templateId) throw new InvalidArgumentException('Batch does not belong to the selected Version.');
            }
            $update = $conn->prepare('UPDATE revision_plan_batch_releases SET visibility = ?, day_access_mode = ?, display_title = ?, released_at = released_at WHERE id = ?');
            if (!$update) throw new RuntimeException('Unable to save Batch controls.');
            $id = (int) $row['id']; $update->bind_param('sssi', $visibility, $dayAccess, $title, $id);
            if (!$update->execute()) throw new RuntimeException('Unable to save Batch controls.');
            $update->close(); $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('mmh_revision_prepare_editable_version')) {
    /** Return an editable Version, cloning a published Version when necessary. */
    function mmh_revision_prepare_editable_version(mysqli $conn, int $templateId, int $versionId, int $adminId): int
    {
        if ($templateId <= 0 || $versionId <= 0 || $adminId <= 0) throw new InvalidArgumentException('Revision Plan version not found.');
        $version = mmh_revision_version($conn, $versionId);
        if (!$version || (int) ($version['template_id'] ?? 0) !== $templateId) throw new InvalidArgumentException('Revision Plan version not found.');
        if ((string) ($version['status'] ?? '') === 'draft') return $versionId;
        return mmh_revision_clone_version($conn, $versionId, $adminId);
    }
}

if (!function_exists('mmh_revision_clone_version')) {
    function mmh_revision_clone_version(mysqli $conn, int $sourceVersionId, int $adminId): int
    {
        $source = mmh_revision_version($conn, $sourceVersionId);
        if (!$source) throw new InvalidArgumentException('Source Version not found.');
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT id FROM revision_plan_templates WHERE id = ? FOR UPDATE'); $lock->bind_param('i', $source['template_id']); $lock->execute(); $lock->get_result()->free(); $lock->close();
            $next = $conn->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 AS next_number FROM revision_plan_template_versions WHERE template_id = ?'); $next->bind_param('i', $source['template_id']); $next->execute(); $number = (int) (($next->get_result()->fetch_assoc()['next_number'] ?? 1)); $next->close();
            $status = 'draft'; $version = $conn->prepare('INSERT INTO revision_plan_template_versions (template_id, version_number, status, created_by) VALUES (?, ?, ?, ?)'); $version->bind_param('iisi', $source['template_id'], $number, $status, $adminId); if (!$version->execute()) throw new RuntimeException('Unable to create the new Draft Version.'); $newVersionId = (int) $version->insert_id; $version->close();
            $resourceMap = [];
            $batchMap = [];
            $hasBatchAccess = mmh_revision_batch_controls_schema_available($conn);
            $hasManualDates = mmh_revision_manual_dates_schema_available($conn);
            foreach ((array) ($source['resources'] ?? []) as $resource) {
                $stmt = $conn->prepare('INSERT INTO revision_plan_template_resources (version_id, batch_id, resource_type, display_name, external_url, storage_key, original_filename, mime_type, file_size_bytes, linked_course_item_id, sort_order, created_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issssssisii', $newVersionId, $resource['resource_type'], $resource['display_name'], $resource['external_url'], $resource['storage_key'], $resource['original_filename'], $resource['mime_type'], $resource['file_size_bytes'], $resource['linked_course_item_id'], $resource['sort_order'], $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to clone a shared resource.'); $resourceMap[(int) $resource['id']] = (int) $stmt->insert_id; $stmt->close();
            }
            foreach ((array) ($source['batches'] ?? []) as $batch) {
                $batchDayAccess = (string) ($batch['day_access_mode'] ?? 'follow_schedule');
                $dayAccess = in_array($batchDayAccess, ['follow_schedule', 'open_all'], true) ? $batchDayAccess : 'follow_schedule';
                $scheduleMode = in_array((string) ($batch['schedule_mode'] ?? 'automatic'), ['automatic', 'manual'], true) ? (string) ($batch['schedule_mode'] ?? 'automatic') : 'automatic';
                if ($hasManualDates) { $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order, day_access_mode, schedule_mode) VALUES (?, ?, ?, ?, ?, ?, ?)'); if (!$stmt) throw new RuntimeException('Unable to clone a batch.'); $stmt->bind_param('issiiss', $newVersionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order'], $dayAccess, $scheduleMode); }
                elseif ($hasBatchAccess) { $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order, day_access_mode) VALUES (?, ?, ?, ?, ?, ?)'); $stmt->bind_param('issiis', $newVersionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order'], $dayAccess); }
                else { $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order) VALUES (?, ?, ?, ?, ?)'); $stmt->bind_param('issii', $newVersionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order']); }
                if (!$stmt || !$stmt->execute()) throw new RuntimeException('Unable to clone a batch.'); $batchId = (int) $stmt->insert_id; $stmt->close(); $batchMap[(int) $batch['id']] = $batchId;
                foreach ((array) ($batch['days'] ?? []) as $day) {
                    if ($hasManualDates) { $stmt = $conn->prepare("INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, scheduled_date, sort_order) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?)"); if (!$stmt) throw new RuntimeException('Unable to clone a day.'); $scheduledDate = (string) ($day['scheduled_date'] ?? ''); $stmt->bind_param('iiisssi', $batchId, $newVersionId, $day['day_number'], $day['title'], $day['description'], $scheduledDate, $day['sort_order']); }
                    else { $stmt = $conn->prepare('INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)'); $stmt->bind_param('iiissi', $batchId, $newVersionId, $day['day_number'], $day['title'], $day['description'], $day['sort_order']); }
                    if (!$stmt || !$stmt->execute()) throw new RuntimeException('Unable to clone a day.'); $dayId = (int) $stmt->insert_id; $stmt->close();
                    foreach ((array) ($day['requirements'] ?? []) as $requirement) mmh_revision_clone_requirement($conn, $newVersionId, $dayId, null, $requirement, $resourceMap);
                    foreach ((array) ($day['activity_groups'] ?? []) as $group) {
                        $stmt = $conn->prepare('INSERT INTO revision_plan_template_activities (day_id, version_id, title, description, sort_order) VALUES (?, ?, ?, ?, ?)'); $stmt->bind_param('iissi', $dayId, $newVersionId, $group['title'], $group['description'], $group['sort_order']); if (!$stmt->execute()) throw new RuntimeException('Unable to clone an Activity Group.'); $activityId = (int) $stmt->insert_id; $stmt->close();
                        foreach ((array) ($group['requirements'] ?? []) as $requirement) mmh_revision_clone_requirement($conn, $newVersionId, $dayId, $activityId, $requirement, $resourceMap);
                    }
                }
            }
            foreach ((array) ($source['resources'] ?? []) as $resource) {
                $newResourceId = $resourceMap[(int) $resource['id']] ?? 0;
                $newBatchId = $batchMap[(int) ($resource['batch_id'] ?? 0)] ?? 0;
                if ($newResourceId > 0 && $newBatchId > 0) {
                    $updateResource = $conn->prepare('UPDATE revision_plan_template_resources SET batch_id = ? WHERE id = ? AND version_id = ?');
                    if ($updateResource) { $updateResource->bind_param('iii', $newBatchId, $newResourceId, $newVersionId); if (!$updateResource->execute()) throw new RuntimeException('Unable to preserve a shared material batch association.'); $updateResource->close(); }
                }
            }
            $conn->commit(); return $newVersionId;
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('mmh_revision_clone_requirement')) {
    function mmh_revision_clone_requirement(mysqli $conn, int $versionId, int $dayId, ?int $activityId, array $requirement, array $resourceMap): void
    {
        $itemId = (string) ($requirement['linked_course_item_id'] ?? ''); $activityValue = (int) ($activityId ?? 0); $type = (string) $requirement['requirement_type']; $required = (int) $requirement['is_required']; $sort = (int) $requirement['sort_order']; $multiple = (int) $requirement['allow_multiple_files']; $policy = (string) $requirement['accepted_file_policy'];
        $stmt = $conn->prepare("INSERT INTO revision_plan_template_requirements (version_id, day_id, activity_id, title, description, requirement_type, is_required, sort_order, linked_course_item_id, allow_multiple_files, accepted_file_policy) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?)"); $stmt->bind_param('iiisssiisis', $versionId, $dayId, $activityValue, $requirement['title'], $requirement['description'], $type, $required, $sort, $itemId, $multiple, $policy); if (!$stmt->execute()) throw new RuntimeException('Unable to clone a requirement.'); $newId = (int) $stmt->insert_id; $stmt->close();
        foreach ((array) ($requirement['resource_ids'] ?? []) as $sortOrder => $resourceId) { $mapped = $resourceMap[(int) $resourceId] ?? 0; if ($mapped <= 0) continue; $link = $conn->prepare('INSERT INTO revision_plan_requirement_resources (requirement_id, resource_id, sort_order) VALUES (?, ?, ?)'); $link->bind_param('iii', $newId, $mapped, $sortOrder); if (!$link->execute()) throw new RuntimeException('Unable to clone a resource link.'); $link->close(); }
    }
}

if (!function_exists('mmh_revision_archive_template')) {
    function mmh_revision_archive_template(mysqli $conn, int $templateId): void
    {
        $stmt = $conn->prepare("UPDATE revision_plan_templates SET status = 'archived', archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status <> 'archived'"); if (!$stmt) throw new RuntimeException('Unable to archive the template.'); $stmt->bind_param('i', $templateId); if (!$stmt->execute()) throw new RuntimeException('Unable to archive the template.'); $stmt->close();
    }
}

if (!function_exists('mmh_revision_template_has_student_activity')) {
    /**
     * Return whether a template has student-facing history that warrants the
     * destructive confirmation.  This is deliberately a read-only check; the
     * delete service repeats it while holding the template lock.
     */
    function mmh_revision_template_has_student_activity(mysqli $conn, int $templateId): bool
    {
        if ($templateId <= 0 || !mmh_revision_assignment_schema_available($conn)) return false;
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM revision_plan_assignments WHERE template_id = ?');
        if (!$stmt) throw new RuntimeException('Unable to inspect Revision Plan activity.');
        $stmt->bind_param('i', $templateId);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to inspect Revision Plan activity.'); }
        $count = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
        $stmt->close();
        return $count > 0;
    }
}

if (!function_exists('mmh_revision_delete_template')) {
    /**
     * Permanently remove one Revision Plan and only its owned domain rows.
     * Source course/content rows are never touched.  The database mutation is
     * one transaction; private uploaded files are cleaned up only after the
     * transaction commits so a rollback cannot destroy a usable resource.
     */
    function mmh_revision_delete_template(mysqli $conn, int $templateId, bool $confirmed = false): void
    {
        if ($templateId <= 0) throw new InvalidArgumentException('Revision Plan not found.');
        if (!mmh_revision_schema_available($conn)) throw new RuntimeException('Revision Plan schema is unavailable.');

        $conn->begin_transaction();
        $storageKeys = [];
        try {
            $lock = $conn->prepare('SELECT id FROM revision_plan_templates WHERE id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock the Revision Plan.');
            $lock->bind_param('i', $templateId);
            if (!$lock->execute()) { $lock->close(); throw new RuntimeException('Unable to lock the Revision Plan.'); }
            $exists = (bool) $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$exists) throw new InvalidArgumentException('Revision Plan not found.');

            $activity = false;
            if (mmh_revision_assignment_schema_available($conn)) {
                $check = $conn->prepare('SELECT COUNT(*) AS total FROM revision_plan_assignments WHERE template_id = ?');
                if (!$check) throw new RuntimeException('Unable to inspect Revision Plan activity.');
                $check->bind_param('i', $templateId);
                if (!$check->execute()) { $check->close(); throw new RuntimeException('Unable to inspect Revision Plan activity.'); }
                $activity = ((int) (($check->get_result()->fetch_assoc()['total'] ?? 0))) > 0;
                $check->close();
            }
            if ($activity && !$confirmed) throw new InvalidArgumentException('Type DELETE to permanently remove a Revision Plan with student activity.');

            // Capture private uploaded resources before deleting their rows.
            $resources = $conn->prepare('SELECT r.storage_key FROM revision_plan_template_resources r INNER JOIN revision_plan_template_versions v ON v.id = r.version_id WHERE v.template_id = ? AND r.storage_key IS NOT NULL AND r.storage_key <> \'\'');
            if (!$resources) throw new RuntimeException('Unable to inspect Revision Plan resources.');
            $resources->bind_param('i', $templateId);
            if (!$resources->execute()) { $resources->close(); throw new RuntimeException('Unable to inspect Revision Plan resources.'); }
            $resourceResult = $resources->get_result();
            while ($row = $resourceResult->fetch_assoc()) { $key = trim((string) ($row['storage_key'] ?? '')); if ($key !== '') $storageKeys[] = $key; }
            $resources->close();

            $delete = static function (mysqli $db, string $sql, int $id): void {
                $stmt = $db->prepare($sql);
                if (!$stmt) throw new RuntimeException('Unable to delete Revision Plan data.');
                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to delete Revision Plan data.'); }
                $stmt->close();
            };

            // Explicit child-first ordering keeps this safe even where FK
            // cascades differ between older production schemas.
            if (mmh_revision_progress_schema_available($conn) && mmh_revision_assignment_schema_available($conn)) {
                $delete($conn, 'DELETE p FROM revision_plan_requirement_progress p INNER JOIN revision_plan_assignments a ON a.id = p.assignment_id WHERE a.template_id = ?', $templateId);
            }
            $delete($conn, 'DELETE rr FROM revision_plan_requirement_resources rr INNER JOIN revision_plan_template_requirements r ON r.id = rr.requirement_id INNER JOIN revision_plan_template_versions v ON v.id = r.version_id WHERE v.template_id = ?', $templateId);
            $delete($conn, 'DELETE r FROM revision_plan_template_requirements r INNER JOIN revision_plan_template_versions v ON v.id = r.version_id WHERE v.template_id = ?', $templateId);
            $delete($conn, 'DELETE g FROM revision_plan_template_activities g INNER JOIN revision_plan_template_versions v ON v.id = g.version_id WHERE v.template_id = ?', $templateId);
            $delete($conn, 'DELETE d FROM revision_plan_template_days d INNER JOIN revision_plan_template_versions v ON v.id = d.version_id WHERE v.template_id = ?', $templateId);
            $delete($conn, 'DELETE r FROM revision_plan_template_resources r INNER JOIN revision_plan_template_versions v ON v.id = r.version_id WHERE v.template_id = ?', $templateId);
            if (mmh_revision_batch_release_schema_available($conn)) $delete($conn, 'DELETE FROM revision_plan_batch_releases WHERE template_id = ?', $templateId);
            if (mmh_revision_assignment_schema_available($conn)) $delete($conn, 'DELETE FROM revision_plan_assignments WHERE template_id = ?', $templateId);
            $delete($conn, 'DELETE b FROM revision_plan_template_batches b INNER JOIN revision_plan_template_versions v ON v.id = b.version_id WHERE v.template_id = ?', $templateId);
            $delete($conn, 'DELETE FROM revision_plan_template_versions WHERE template_id = ?', $templateId);
            $delete($conn, 'DELETE FROM revision_plan_templates WHERE id = ?', $templateId);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        // Files are private Revision resources, never shared Course files.
        $root = realpath(dirname(__DIR__) . '/storage/private/revision-plans');
        if ($root) {
            $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
            foreach (array_unique($storageKeys) as $key) {
                // A cloned Version may intentionally reference the same
                // private file.  Never remove a file still referenced by a
                // surviving Revision resource row.
                $stillReferenced = $conn->prepare('SELECT COUNT(*) AS total FROM revision_plan_template_resources WHERE storage_key = ?');
                if (!$stillReferenced) continue;
                $stillReferenced->bind_param('s', $key);
                if (!$stillReferenced->execute()) { $stillReferenced->close(); continue; }
                $inUse = (int) (($stillReferenced->get_result()->fetch_assoc()['total'] ?? 0));
                $stillReferenced->close();
                if ($inUse > 0) continue;
                $path = realpath(dirname(__DIR__) . '/' . ltrim($key, '/'));
                if ($path && is_file($path) && str_starts_with(str_replace('\\', '/', $path), $root)) @unlink($path);
            }
        }
    }
}

if (!function_exists('mmh_revision_resource')) {
    function mmh_revision_resource(mysqli $conn, int $resourceId): ?array
    {
        $stmt = $conn->prepare('SELECT r.*, v.status AS version_status, v.template_id, t.course_id FROM revision_plan_template_resources r INNER JOIN revision_plan_template_versions v ON v.id = r.version_id INNER JOIN revision_plan_templates t ON t.id = v.template_id WHERE r.id = ? LIMIT 1'); if (!$stmt) return null; $stmt->bind_param('i', $resourceId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close(); return is_array($row) ? $row : null;
    }
}

if (!function_exists('mmh_revision_save_resource')) {
    function mmh_revision_save_resource(mysqli $conn, int $versionId, int $adminId, array $input, array $file = []): int
    {
        $version = mmh_revision_version($conn, $versionId); if (!$version || $version['status'] !== 'draft') throw new InvalidArgumentException('Resources can only be changed on a Draft Version.');
        $type = strtolower(trim((string) ($input['resource_type'] ?? ''))); if (!in_array($type, ['uploaded_pdf', 'external_link', 'course_item'], true)) throw new InvalidArgumentException('Choose a supported resource type.');
        $name = mb_substr(trim((string) ($input['display_name'] ?? '')), 0, 180); if ($name === '') throw new InvalidArgumentException('Enter a resource name.');
        $external = ''; $storage = ''; $original = ''; $mime = ''; $size = 0; $itemId = trim((string) ($input['linked_course_item_id'] ?? '')); $batchId = (int) ($input['batch_id'] ?? 0);
        if ($batchId > 0) { $validBatch = false; foreach ((array) ($version['batches'] ?? []) as $batch) if ((int) ($batch['id'] ?? 0) === $batchId) { $validBatch = true; break; } if (!$validBatch) throw new InvalidArgumentException('Choose a Batch from this Revision Plan.'); }
        if ($type === 'external_link') { $external = trim((string) ($input['external_url'] ?? '')); $parts = parse_url($external); if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || preg_match('/[\r\n]/', $external)) throw new InvalidArgumentException('External resources must use a valid HTTPS URL.'); }
        if ($type === 'course_item') {
            $validItem = false;
            foreach (mmh_revision_course_items($conn, (string) $version['course_id']) as $courseItem) {
                if ((string) ($courseItem['item_id'] ?? '') === $itemId) { $validItem = true; break; }
            }
            if (!$validItem) throw new InvalidArgumentException('Choose a Course Item from this course that has not been archived.');
        }
        if ($type === 'uploaded_pdf') {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Choose a PDF file to upload.');
            if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) throw new InvalidArgumentException('PDF resources must be 50 MB or smaller.');
            $tmp = (string) ($file['tmp_name'] ?? ''); $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false; $detected = $finfo ? (string) finfo_file($finfo, $tmp) : ''; if ($finfo) finfo_close($finfo); if ($detected !== 'application/pdf' || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf') throw new InvalidArgumentException('Only valid PDF files are accepted.');
            $relativeDir = 'storage/private/revision-plans/' . $versionId; $absoluteDir = dirname(__DIR__) . '/' . $relativeDir; if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) throw new RuntimeException('The private resource directory could not be created.');
            $stored = bin2hex(random_bytes(20)) . '.pdf'; if (!move_uploaded_file($tmp, $absoluteDir . '/' . $stored)) throw new RuntimeException('The PDF could not be saved securely.'); $storage = $relativeDir . '/' . $stored; $original = mb_substr((string) ($file['name'] ?? 'resource.pdf'), 0, 255); $mime = 'application/pdf'; $size = (int) $file['size'];
        }
        $stmt = $conn->prepare('INSERT INTO revision_plan_template_resources (version_id, batch_id, resource_type, display_name, external_url, storage_key, original_filename, mime_type, file_size_bytes, linked_course_item_id, sort_order, created_by) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?)'); if (!$stmt) throw new RuntimeException('Unable to save the resource.'); $sort = 0; $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM revision_plan_template_resources WHERE version_id = ?'); if ($max) { $max->bind_param('i', $versionId); $max->execute(); $sort = (int) (($max->get_result()->fetch_assoc()['next_order'] ?? 0)); $max->close(); } $stmt->bind_param('iissssssisii', $versionId, $batchId, $type, $name, $external, $storage, $original, $mime, $size, $itemId, $sort, $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to save the resource.'); $id = (int) $stmt->insert_id; $stmt->close(); return $id;
    }
}

if (!function_exists('mmh_revision_delete_resource')) {
    function mmh_revision_delete_resource(mysqli $conn, int $resourceId): void
    {
        $resource = mmh_revision_resource($conn, $resourceId); if (!$resource || $resource['version_status'] !== 'draft') throw new InvalidArgumentException('Only resources on a Draft Version can be removed.');
        $check = $conn->prepare('SELECT COUNT(*) AS total FROM revision_plan_requirement_resources WHERE resource_id = ?'); $check->bind_param('i', $resourceId); $check->execute(); $used = (int) (($check->get_result()->fetch_assoc()['total'] ?? 0)); $check->close(); if ($used > 0) throw new InvalidArgumentException('Remove this resource from its requirements before deleting it.');
        $stmt = $conn->prepare('DELETE FROM revision_plan_template_resources WHERE id = ?'); $stmt->bind_param('i', $resourceId); if (!$stmt->execute()) throw new RuntimeException('Unable to remove the resource.'); $stmt->close();
        if ((string) ($resource['storage_key'] ?? '') !== '') { $path = realpath(dirname(__DIR__) . '/' . $resource['storage_key']); $root = realpath(dirname(__DIR__) . '/storage/private/revision-plans'); if ($path && $root && str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/') . '/') && is_file($path) && !unlink($path)) { /* Keep the database reference recoverable if filesystem cleanup is unavailable. */ } }
    }
}

if (!function_exists('mmh_revision_assignment_schema_available')) {
    function mmh_revision_assignment_schema_available(mysqli $conn): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_assignments'");
        if (!$stmt) return false;
        $stmt->execute();
        $ok = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_revision_eligible_students')) {
    function mmh_revision_eligible_students(mysqli $conn, string $courseId, string $search = ''): array
    {
        $search = trim($search);
        $sql = "SELECT DISTINCT u.user_id, u.full_name, u.username
                FROM users u INNER JOIN course_logs cl ON cl.user_id = u.user_id AND cl.course_id = ?
                WHERE u.role = 'user' AND u.status = '1' AND u.archived_at IS NULL";
        if ($search !== '') $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ?)";
        $sql .= ' ORDER BY u.full_name ASC, u.username ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($search !== '') { $like = '%' . $search . '%'; $stmt->bind_param('sss', $courseId, $like, $like); }
        else $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_revision_assignment')) {
    /** Replace a student's visible Version batches with immutable releases. */
    function mmh_revision_apply_batch_releases(mysqli $conn, array $assignment, array $version): array
    {
        if (!mmh_revision_batch_release_schema_available($conn)) return $version;
        $templateId = (int) ($assignment['template_id'] ?? $version['template_id'] ?? 0);
        if ($templateId <= 0) return $version;
        $stmt = $conn->prepare("SELECT batch_position, source_version_id, source_batch_id, released_at, visibility, day_access_mode, display_title FROM revision_plan_batch_releases WHERE template_id = ? AND status = 'released' AND released_at <= UTC_TIMESTAMP() ORDER BY batch_position ASC");
        if (!$stmt) return $version;
        $stmt->bind_param('i', $templateId); $stmt->execute();
        $releaseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        // No rows means this is a pre-release plan. Treat its published Version
        // as fully released so existing students do not lose access.
        if (!$releaseRows) return $version;
        $releasedBatches = [];
        $releasedPositions = [];
        $resources = [];
        $baseBatches = [];
        $batchReleaseMeta = [];
        foreach ((array) ($version['batches'] ?? []) as $position => $batch) $baseBatches[(int) $position] = $batch;
        $firstReleaseAt = (string) (($releaseRows[0]['released_at'] ?? ''));
        $publishedAt = (string) ($version['published_at'] ?? '');
        $legacyMode = $publishedAt !== '' && $firstReleaseAt !== '' && $publishedAt < $firstReleaseAt;
        if ($legacyMode) foreach ((array) ($version['resources'] ?? []) as $resource) $resources[(int) ($resource['id'] ?? 0)] = $resource;
        foreach ($releaseRows as $release) {
            $position = (int) $release['batch_position'];
            $batchReleaseMeta[$position] = $release;
            if ((string) ($release['visibility'] ?? 'released') !== 'released') continue;
            if ($legacyMode && isset($baseBatches[$position]) && mmh_revision_batch_has_content((array) $baseBatches[$position])) {
                $baseBatch = $baseBatches[$position];
                $baseBatch['_release_position'] = $position;
                $releasedBatches[] = $baseBatch;
                $releasedPositions[$position] = true;
                continue;
            }
            $source = mmh_revision_version($conn, (int) $release['source_version_id']);
            if (!is_array($source) || (string) ($source['status'] ?? '') !== 'published' || (int) ($source['template_id'] ?? 0) !== $templateId) continue;
            foreach ((array) ($source['resources'] ?? []) as $resource) $resources[(int) ($resource['id'] ?? 0)] = $resource;
            foreach ((array) ($source['batches'] ?? []) as $batch) {
                if ((int) ($batch['id'] ?? 0) !== (int) $release['source_batch_id']) continue;
                if (trim((string) ($release['display_title'] ?? '')) !== '') $batch['title'] = trim((string) $release['display_title']);
                $batch['day_access_mode'] = in_array((string) ($release['day_access_mode'] ?? 'follow_schedule'), ['follow_schedule', 'open_all'], true) ? (string) $release['day_access_mode'] : 'follow_schedule';
                $batch['_release_position'] = $position;
                $releasedBatches[] = $batch;
                $releasedPositions[$position] = true;
                break;
            }
        }
        if ($legacyMode) {
            foreach ($baseBatches as $position => $baseBatch) {
                if (isset($releasedPositions[$position]) || !mmh_revision_batch_has_content((array) $baseBatch)) continue;
                $baseBatch['_release_position'] = $position;
                $releasedBatches[] = $baseBatch;
                $releasedPositions[$position] = true;
            }
        }
        if (!$releasedBatches) return $version;
        usort($releasedBatches, static fn(array $a, array $b): int => ((int) ($a['_release_position'] ?? 0)) <=> ((int) ($b['_release_position'] ?? 0)));
        $latestPublished = null;
        $latest = $conn->prepare("SELECT id FROM revision_plan_template_versions WHERE template_id = ? AND status = 'published' ORDER BY version_number DESC, id DESC LIMIT 1");
        if ($latest) { $latest->bind_param('i', $templateId); $latest->execute(); $latestId = (int) (($latest->get_result()->fetch_assoc()['id'] ?? 0)); $latest->close(); if ($latestId > 0) $latestPublished = mmh_revision_version($conn, $latestId); }
        $shellSource = is_array($latestPublished) ? $latestPublished : $version;
        $unreleased = [];
        foreach ((array) ($shellSource['batches'] ?? []) as $position => $batch) {
            if (!isset($releasedPositions[(int) $position])) {
                $meta = $batchReleaseMeta[(int) $position] ?? null;
                if (is_array($meta) && trim((string) ($meta['display_title'] ?? '')) !== '') $batch['title'] = trim((string) $meta['display_title']);
                $batch['_release_position'] = (int) $position;
                $unreleased[] = $batch;
            }
        }
        $version['batches'] = $releasedBatches;
        $version['unreleased_batches'] = $unreleased;
        $version['resources'] = array_values($resources);
        return $version;
    }

    function mmh_revision_assignment(mysqli $conn, int $assignmentId, int $studentId = 0): ?array
    {
        if ($assignmentId <= 0 || !mmh_revision_assignment_schema_available($conn)) return null;
        $sql = "SELECT a.*, t.title, t.description, t.status AS template_status,
                       v.status AS version_status, v.version_number, v.allow_work_ahead,
                       c.course_title, c.course_state, u.full_name, u.username
                FROM revision_plan_assignments a
                INNER JOIN revision_plan_templates t ON t.id = a.template_id
                INNER JOIN revision_plan_template_versions v ON v.id = a.template_version_id AND v.template_id = a.template_id
                INNER JOIN courses c ON c.course_id = a.course_id
                INNER JOIN users u ON u.user_id = a.user_id
                WHERE a.id = ? AND a.archived_at IS NULL AND (a.ended_at IS NULL OR a.ended_at > UTC_TIMESTAMP()) AND c.archived_at IS NULL";
        if ($studentId > 0) $sql .= ' AND a.user_id = ?';
        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        if ($studentId > 0) $stmt->bind_param('ii', $assignmentId, $studentId); else $stmt->bind_param('i', $assignmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) return null;
        $row['version'] = mmh_revision_version($conn, (int) $row['template_version_id']);
        if (is_array($row['version'])) $row['version'] = mmh_revision_apply_batch_releases($conn, $row, $row['version']);
        return $row;
    }
}

if (!function_exists('mmh_revision_assignment_enrolled')) {
    function mmh_revision_assignment_enrolled(mysqli $conn, int $studentId, string $courseId): bool
    {
        $stmt = $conn->prepare("SELECT cl.id FROM course_logs cl INNER JOIN users u ON u.user_id = cl.user_id WHERE cl.user_id = ? AND cl.course_id = ? AND u.role = 'user' AND u.status = '1' AND u.archived_at IS NULL LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('is', $studentId, $courseId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_revision_assignment_days')) {
    /** Flatten immutable Version days into one deterministic sequential timeline. */
    function mmh_revision_assignment_days(array $assignment): array
    {
        $version = is_array($assignment['version'] ?? null) ? $assignment['version'] : [];
        $timezone = new DateTimeZone(date_default_timezone_get());
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($assignment['start_date'] ?? ''), $timezone);
        if (!$start) $start = new DateTimeImmutable('today', $timezone);
        $today = new DateTimeImmutable('today', $timezone);
        $allowAhead = !empty($assignment['allow_work_ahead']) || !empty($version['allow_work_ahead']);
        $days = [];
        $offset = 0;
        foreach ((array) ($version['batches'] ?? []) as $batch) {
            $batchMode = strtolower(trim((string) ($batch['day_access_mode'] ?? '')));
            $batchAllowAhead = $batchMode === 'open_all' ? true : ($batchMode === 'follow_schedule' ? false : $allowAhead);
            $scheduleMode = strtolower(trim((string) ($batch['schedule_mode'] ?? 'automatic')));
            if (!in_array($scheduleMode, ['automatic', 'manual'], true)) $scheduleMode = 'automatic';
            foreach ((array) ($batch['days'] ?? []) as $day) {
                $authoredDate = mmh_revision_normalize_study_date($day['scheduled_date'] ?? '', false);
                $scheduled = $scheduleMode === 'manual' && $authoredDate !== null
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $authoredDate, $timezone)
                    : $start->modify('+' . $offset . ' days');
                if (!$scheduled) $scheduled = $start->modify('+' . $offset . ' days');
                $day['batch_title'] = (string) ($batch['title'] ?? 'Batch');
                $day['schedule_mode'] = $scheduleMode;
                $day['absolute_day_number'] = $offset + 1;
                $day['scheduled_date'] = $scheduled->format('Y-m-d');
                $day['availability'] = $scheduled < $today ? 'previous' : ($scheduled == $today ? 'today' : ($batchAllowAhead ? 'upcoming' : 'locked'));
                $day['accessible'] = $batchAllowAhead || $scheduled <= $today;
                $days[] = $day;
                $offset++;
            }
        }
        return $days;
    }
}

if (!function_exists('mmh_revision_assignment_context')) {
    /** Validate student ownership, enrollment, published Version, and day availability. */
    function mmh_revision_assignment_context(mysqli $conn, int $assignmentId, int $studentId, ?int $requirementId = null, ?int $resourceId = null): ?array
    {
        $assignment = mmh_revision_assignment($conn, $assignmentId, $studentId);
        if (!$assignment || (string) $assignment['status'] !== 'active' || (string) $assignment['template_status'] === 'archived' || (string) $assignment['version_status'] !== 'published' || !in_array((string) ($assignment['course_state'] ?? ''), ['public', 'private'], true)) return null;
        if (!mmh_revision_assignment_enrolled($conn, $studentId, (string) $assignment['course_id'])) return null;
        $days = mmh_revision_assignment_days($assignment);
        $resources = [];
        foreach ((array) ($assignment['version']['resources'] ?? []) as $resource) $resources[(int) $resource['id']] = $resource;
        $foundRequirement = null;
        $foundDay = null;
        $resourceRequirement = null;
        $resourceDay = null;
        foreach ($days as $day) {
            $requirements = (array) ($day['requirements'] ?? []);
            foreach ((array) ($day['activity_groups'] ?? []) as $group) $requirements = array_merge($requirements, (array) ($group['requirements'] ?? []));
            foreach ($requirements as $requirement) {
                if ($requirementId !== null && (int) ($requirement['id'] ?? 0) === $requirementId) { $foundRequirement = $requirement; $foundDay = $day; }
                if ($resourceId !== null && in_array($resourceId, array_map('intval', (array) ($requirement['resource_ids'] ?? [])), true)) { $resourceRequirement = $requirement; $resourceDay = $day; }
            }
        }
        if ($requirementId !== null && !$foundRequirement) return null;
        if ($resourceId !== null && empty($resources[$resourceId])) return null;
        /* A shared Batch material may be intentionally unlinked from a
         * requirement. It is still protected by the released Batch and the
         * normal day schedule; it must not require a fabricated requirement
         * id in the URL. Requirement-linked resources continue through the
         * stricter relationship check below. */
        if ($resourceId !== null && !$resourceRequirement) {
            $resourceBatchId = (int) ($resources[$resourceId]['batch_id'] ?? 0);
            if ($resourceBatchId <= 0) {
                // Legacy published Versions stored shared materials at
                // Version scope (batch_id NULL). They remain safe to open
                // only when the plan has no unreleased Batches, or all
                // released Batches are currently schedule-accessible.
                $unreleased = (array) ($assignment['version']['unreleased_batches'] ?? []);
                if (array_key_exists('unreleased_batches', (array) ($assignment['version'] ?? [])) && $unreleased) return null;
                foreach ($days as $day) {
                    if (!empty($day['accessible'])) { $resourceDay = $day; break; }
                }
            } else {
                foreach ($days as $day) {
                    if ((int) ($day['batch_id'] ?? 0) === $resourceBatchId) { $resourceDay = $day; break; }
                }
            }
            if (!$resourceDay) return null;
        }
        if ($requirementId !== null && $resourceId !== null && (int) ($foundRequirement['id'] ?? 0) !== (int) ($resourceRequirement['id'] ?? 0)) return null;
        if ($requirementId === null && $resourceId !== null) { $foundRequirement = $resourceRequirement; $foundDay = $resourceDay; }
        if ($foundDay && empty($foundDay['accessible'])) return null;
        return ['assignment' => $assignment, 'version' => $assignment['version'], 'days' => $days, 'day' => $foundDay, 'requirement' => $foundRequirement, 'resource' => $resourceId !== null ? ($resources[$resourceId] ?? null) : null];
    }
}

if (!function_exists('mmh_revision_progress_schema_available')) {
    function mmh_revision_progress_schema_available(mysqli $conn): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_requirement_progress'");
        if (!$stmt) return false;
        $stmt->execute();
        $available = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $available;
    }
}

if (!function_exists('mmh_revision_submission_schema_available')) {
    function mmh_revision_submission_schema_available(mysqli $conn): bool
    {
        foreach (['revision_plan_requirement_submissions', 'revision_plan_submission_files'] as $table) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            if (!$stmt) return false;
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $exists = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
            $stmt->close();
            if (!$exists) return false;
        }
        return true;
    }
}

if (!function_exists('mmh_revision_requirement_submission')) {
    /** Return a student's owned Revision upload and its files, if present. */
    function mmh_revision_requirement_submission(mysqli $conn, int $assignmentId, int $studentId, int $requirementId): ?array
    {
        if ($assignmentId <= 0 || $studentId <= 0 || $requirementId <= 0 || !mmh_revision_submission_schema_available($conn)) return null;
        $stmt = $conn->prepare('SELECT s.id, s.assignment_id, s.requirement_id, s.submitted_at, s.updated_at FROM revision_plan_requirement_submissions s INNER JOIN revision_plan_assignments a ON a.id = s.assignment_id AND a.user_id = ? WHERE s.assignment_id = ? AND s.requirement_id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('iii', $studentId, $assignmentId, $requirementId);
        $stmt->execute();
        $submission = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($submission)) return null;
        $files = $conn->prepare('SELECT id, original_filename, mime_type, file_size_bytes, sort_order, uploaded_at FROM revision_plan_submission_files WHERE submission_id = ? ORDER BY sort_order ASC, id ASC');
        if ($files) {
            $submissionId = (int) $submission['id'];
            $files->bind_param('i', $submissionId);
            $files->execute();
            $submission['files'] = $files->get_result()->fetch_all(MYSQLI_ASSOC);
            $files->close();
        } else $submission['files'] = [];
        return $submission;
    }
}

if (!function_exists('mmh_revision_upload_requirement')) {
    /**
     * Store one Revision upload (one logical submission with child files) and
     * satisfy the requirement's existing progress identity.
     */
    function mmh_revision_upload_requirement(mysqli $conn, int $assignmentId, int $studentId, int $requirementId, array $rawFiles): array
    {
        $context = mmh_revision_assignment_context($conn, $assignmentId, $studentId, $requirementId, null);
        $requirement = $context['requirement'] ?? null;
        if (!$context || !is_array($requirement) || strtolower(trim((string) ($requirement['requirement_type'] ?? ''))) !== 'upload') throw new InvalidArgumentException('This upload requirement is not available.');
        if (!mmh_revision_submission_schema_available($conn) || !mmh_revision_progress_schema_available($conn)) throw new RuntimeException('Revision uploads are temporarily unavailable.');

        $names = is_array($rawFiles['name'] ?? null) ? $rawFiles['name'] : [$rawFiles['name'] ?? ''];
        $tmpNames = is_array($rawFiles['tmp_name'] ?? null) ? $rawFiles['tmp_name'] : [$rawFiles['tmp_name'] ?? ''];
        $errors = is_array($rawFiles['error'] ?? null) ? $rawFiles['error'] : [$rawFiles['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = is_array($rawFiles['size'] ?? null) ? $rawFiles['size'] : [$rawFiles['size'] ?? 0];
        $maxFiles = !empty($requirement['allow_multiple_files']) ? 10 : 1;
        $maxBytes = 20 * 1024 * 1024;
        if (count($names) < 1 || count($names) > $maxFiles) throw new InvalidArgumentException('Choose between 1 and ' . $maxFiles . ' PDF files.');
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        if (!$finfo) throw new RuntimeException('PDF validation is unavailable on this server.');
        $entries = [];
        foreach ($names as $index => $name) {
            $tmp = (string) ($tmpNames[$index] ?? '');
            $size = (int) ($sizes[$index] ?? 0);
            $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > $maxBytes || $extension !== 'pdf') { finfo_close($finfo); throw new InvalidArgumentException('Every answer file must be a valid PDF within the upload limit.'); }
            $mime = strtolower((string) finfo_file($finfo, $tmp));
            if ($mime !== 'application/pdf') { finfo_close($finfo); throw new InvalidArgumentException('Every answer file must be a valid PDF.'); }
            $entries[] = ['temporary' => $tmp, 'name' => mb_substr(trim((string) $name), 0, 255), 'mime' => $mime, 'size' => $size, 'sort' => $index];
        }
        finfo_close($finfo);
        $relativeDir = 'storage/private/revision-plan-submissions/' . $assignmentId . '/' . $requirementId;
        $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) throw new RuntimeException('The private upload directory could not be created.');
        $newPaths = [];
        foreach ($entries as &$entry) {
            $relative = $relativeDir . '/' . bin2hex(random_bytes(20)) . '.pdf';
            $absolute = dirname(__DIR__) . '/' . $relative;
            if (!move_uploaded_file($entry['temporary'], $absolute)) { foreach ($newPaths as $path) if (is_file($path)) @unlink($path); throw new RuntimeException('The PDF could not be saved securely.'); }
            $entry['path'] = $relative;
            $newPaths[] = $absolute;
        }
        unset($entry);
        $oldPaths = [];
        $conn->begin_transaction();
        try {
            $existingStmt = $conn->prepare('SELECT id FROM revision_plan_requirement_submissions WHERE assignment_id = ? AND requirement_id = ? FOR UPDATE');
            if (!$existingStmt) throw new RuntimeException('Unable to prepare Revision upload replacement.');
            $existingStmt->bind_param('ii', $assignmentId, $requirementId);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()->fetch_assoc() ?: null;
            $existingStmt->close();
            if ($existing) {
                $submissionId = (int) $existing['id'];
                $oldStmt = $conn->prepare('SELECT file_path FROM revision_plan_submission_files WHERE submission_id = ?');
                if ($oldStmt) { $oldStmt->bind_param('i', $submissionId); $oldStmt->execute(); $oldResult = $oldStmt->get_result(); while ($row = $oldResult->fetch_assoc()) if (!empty($row['file_path'])) $oldPaths[] = (string) $row['file_path']; $oldStmt->close(); }
                $deleteFiles = $conn->prepare('DELETE FROM revision_plan_submission_files WHERE submission_id = ?');
                if (!$deleteFiles) throw new RuntimeException('Unable to replace Revision upload files.');
                $deleteFiles->bind_param('i', $submissionId);
                if (!$deleteFiles->execute()) throw new RuntimeException('Unable to replace Revision upload files.');
                $deleteFiles->close();
                $touch = $conn->prepare('UPDATE revision_plan_requirement_submissions SET submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                if (!$touch) throw new RuntimeException('Unable to update Revision upload.'); $touch->bind_param('i', $submissionId); if (!$touch->execute()) throw new RuntimeException('Unable to update Revision upload.'); $touch->close();
            } else {
                $insert = $conn->prepare('INSERT INTO revision_plan_requirement_submissions (assignment_id, requirement_id) VALUES (?, ?)');
                if (!$insert) throw new RuntimeException('Unable to save Revision upload.');
                $insert->bind_param('ii', $assignmentId, $requirementId);
                if (!$insert->execute()) throw new RuntimeException('Unable to save Revision upload.');
                $submissionId = (int) $insert->insert_id;
                $insert->close();
            }
            $fileInsert = $conn->prepare('INSERT INTO revision_plan_submission_files (submission_id, file_path, original_filename, mime_type, file_size_bytes, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
            if (!$fileInsert) throw new RuntimeException('Unable to save Revision upload files.');
            foreach ($entries as $entry) { $fileInsert->bind_param('isssii', $submissionId, $entry['path'], $entry['name'], $entry['mime'], $entry['size'], $entry['sort']); if (!$fileInsert->execute()) throw new RuntimeException('Unable to save Revision upload files.'); }
            $fileInsert->close();
            $progress = $conn->prepare('INSERT INTO revision_plan_requirement_progress (assignment_id, requirement_id, completed_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP');
            if (!$progress) throw new RuntimeException('Unable to save Revision upload progress.');
            $progress->bind_param('ii', $assignmentId, $requirementId);
            if (!$progress->execute()) throw new RuntimeException('Unable to save Revision upload progress.');
            $progress->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            foreach ($newPaths as $path) if (is_file($path)) @unlink($path);
            throw $e;
        }
        foreach (array_unique($oldPaths) as $old) {
            $path = realpath(dirname(__DIR__) . '/' . ltrim($old, '/'));
            $root = realpath(dirname(__DIR__) . '/storage/private/revision-plan-submissions');
            if ($path && $root && is_file($path) && str_starts_with(str_replace('\\\\', '/', $path), rtrim(str_replace('\\\\', '/', $root), '/') . '/')) @unlink($path);
        }
        return ['submission_id' => $submissionId, 'file_count' => count($entries)];
    }
}

if (!function_exists('mmh_revision_requirement_is_actionable')) {
    /** Phase 3B-A supports manual completion for usable checklist/resource/content requirements. */
    function mmh_revision_requirement_is_actionable(array $requirement): bool
    {
        return in_array(strtolower(trim((string) ($requirement['requirement_type'] ?? ''))), ['checklist', 'resource', 'course_item', 'upload'], true);
    }
}

if (!function_exists('mmh_revision_assignment_progress')) {
    /** Return only completion rows belonging to this student-owned assignment. */
    function mmh_revision_assignment_progress(mysqli $conn, int $assignmentId, int $studentId): array
    {
        if ($assignmentId <= 0 || $studentId <= 0 || !mmh_revision_progress_schema_available($conn)) return [];
        // Requirements may come from different immutable source Versions as
        // Batches are released over time; the assignment/context checks below
        // remain the authorization boundary.
        $stmt = $conn->prepare('SELECT p.requirement_id, p.completed_at FROM revision_plan_requirement_progress p INNER JOIN revision_plan_assignments a ON a.id = p.assignment_id AND a.user_id = ? WHERE p.assignment_id = ? ORDER BY p.requirement_id ASC');
        if (!$stmt) return [];
        $stmt->bind_param('ii', $studentId, $assignmentId);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $progress = [];
        foreach ($rows as $row) $progress[(int) $row['requirement_id']] = (string) $row['completed_at'];
        return $progress;
    }
}

if (!function_exists('mmh_revision_progress_summary')) {
    /** Count actionable requirements without creating completion for other LMS domains. */
    function mmh_revision_progress_summary(array $days, array $progress): array
    {
        $total = 0; $completed = 0; $daySummary = [];
        foreach ($days as $day) {
            $requirements = (array) ($day['requirements'] ?? []);
            foreach ((array) ($day['activity_groups'] ?? []) as $group) $requirements = array_merge($requirements, (array) ($group['requirements'] ?? []));
            $dayTotal = 0; $dayCompleted = 0;
            foreach ($requirements as $requirement) {
                if (!mmh_revision_requirement_is_actionable($requirement)) continue;
                $requirementId = (int) ($requirement['id'] ?? 0);
                if ($requirementId <= 0) continue;
                $dayTotal++; $total++;
                if (isset($progress[$requirementId])) { $dayCompleted++; $completed++; }
            }
            $daySummary[(int) ($day['absolute_day_number'] ?? 0)] = ['total' => $dayTotal, 'completed' => $dayCompleted];
        }
        return ['total' => $total, 'completed' => $completed, 'percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 0, 'days' => $daySummary];
    }
}

if (!function_exists('mmh_revision_set_requirement_complete')) {
    /** Toggle manual completion after validating assignment ownership and day availability. */
    function mmh_revision_set_requirement_complete(mysqli $conn, int $assignmentId, int $studentId, int $requirementId, bool $complete): void
    {
        $context = mmh_revision_assignment_context($conn, $assignmentId, $studentId, $requirementId, null);
        $requirement = $context['requirement'] ?? null;
        if (!$context || !is_array($requirement) || !mmh_revision_requirement_is_actionable($requirement)) throw new InvalidArgumentException('This Revision Plan requirement is not available.');
        if (strtolower(trim((string) ($requirement['requirement_type'] ?? ''))) === 'upload') throw new InvalidArgumentException('Upload requirements are completed by submitting a PDF.');
        if (!mmh_revision_progress_schema_available($conn)) throw new RuntimeException('Revision Plan progress is temporarily unavailable.');
        $conn->begin_transaction();
        try {
            if ($complete) {
                $stmt = $conn->prepare('INSERT INTO revision_plan_requirement_progress (assignment_id, requirement_id, completed_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP');
                if (!$stmt) throw new RuntimeException('Unable to save Revision Plan progress.');
                $stmt->bind_param('ii', $assignmentId, $requirementId);
            } else {
                $stmt = $conn->prepare('DELETE p FROM revision_plan_requirement_progress p INNER JOIN revision_plan_assignments a ON a.id = p.assignment_id AND a.user_id = ? WHERE p.assignment_id = ? AND p.requirement_id = ?');
                if (!$stmt) throw new RuntimeException('Unable to update Revision Plan progress.');
                $stmt->bind_param('iii', $studentId, $assignmentId, $requirementId);
            }
            if (!$stmt->execute()) throw new RuntimeException('Unable to save Revision Plan progress.');
            $stmt->close();
            $conn->commit();
        } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
    }
}

if (!function_exists('mmh_revision_assign_students')) {
    function mmh_revision_assign_students(mysqli $conn, int $versionId, array $studentIds, string $startDate, int $adminId): int
    {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version || (string) ($version['status'] ?? '') !== 'published') throw new InvalidArgumentException('Only a published Revision Plan Version can be assigned.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($startDate));
        if (!$date || $date->format('Y-m-d') !== trim($startDate)) throw new InvalidArgumentException('Choose a valid start date.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) throw new InvalidArgumentException('Select at least one enrolled student.');
        $conn->begin_transaction();
        try {
            $courseId = (string) $version['course_id'];
            $templateId = (int) $version['template_id'];
            $count = 0;
            $check = $conn->prepare("SELECT u.user_id FROM users u INNER JOIN course_logs cl ON cl.user_id = u.user_id AND cl.course_id = ? WHERE u.user_id = ? AND u.role = 'user' AND u.status = '1' AND u.archived_at IS NULL LIMIT 1");
            /*
             * The unique key prevents duplicate student/version assignments.  A
             * re-assignment must also make an older archived/ended row active
             * again; leaving that row untouched makes the admin confirmation say
             * "assigned" while the student list correctly hides it.
             */
            $insert = $conn->prepare("INSERT INTO revision_plan_assignments (template_id, template_version_id, course_id, user_id, start_date, assigned_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE course_id = VALUES(course_id), start_date = VALUES(start_date), status = 'active', assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP, archived_at = NULL, ended_at = NULL");
            if (!$check || !$insert) throw new RuntimeException('Unable to prepare Revision Plan assignment.');
            foreach ($ids as $studentId) { $check->bind_param('si', $courseId, $studentId); $check->execute(); if (!$check->get_result()->fetch_assoc()) throw new InvalidArgumentException('Every selected student must be an active enrollee in this course.'); }
            foreach ($ids as $studentId) { $insert->bind_param('iisisi', $templateId, $versionId, $courseId, $studentId, $startDate, $adminId); if (!$insert->execute()) throw new RuntimeException('Unable to assign the Revision Plan.'); $count++; }
            $check->close();
            $insert->close();
            $conn->commit();
            return $count;
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('mmh_revision_student_assignments')) {
    /**
     * Return the assignments visible to a student.  The optional error output
     * lets callers distinguish a real empty result from an unavailable schema
     * or failed query, so a database problem is never presented as "no plans".
     */
    function mmh_revision_student_assignments(mysqli $conn, int $studentId, ?string &$error = null): array
    {
        $error = null;
        if ($studentId <= 0) return [];
        if (!mmh_revision_assignment_schema_available($conn)) {
            $error = 'Revision Plan assignments are temporarily unavailable.';
            error_log('Revision Plan assignment schema is unavailable while loading student assignments.');
            return [];
        }
        $stmt = $conn->prepare("SELECT DISTINCT a.id, a.course_id, a.start_date, a.status, a.assigned_at, t.title, c.course_title, v.version_number,
                       CASE WHEN a.start_date > CURRENT_DATE THEN 'upcoming' WHEN a.start_date = CURRENT_DATE THEN 'active' ELSE 'past' END AS schedule_state
                FROM revision_plan_assignments a
                INNER JOIN users u ON u.user_id = a.user_id AND u.role = 'user' AND u.status = '1' AND u.archived_at IS NULL
                INNER JOIN course_logs cl ON cl.user_id = a.user_id AND cl.course_id = a.course_id
                INNER JOIN revision_plan_templates t ON t.id = a.template_id AND t.course_id = a.course_id
                INNER JOIN revision_plan_template_versions v ON v.id = a.template_version_id AND v.template_id = t.id
                INNER JOIN courses c ON c.course_id = a.course_id AND c.archived_at IS NULL
                WHERE a.user_id = ?
                  AND LOWER(TRIM(COALESCE(a.status, ''))) = 'active'
                  AND a.archived_at IS NULL
                  AND (a.ended_at IS NULL OR a.ended_at > UTC_TIMESTAMP())
                  AND LOWER(TRIM(COALESCE(t.status, ''))) <> 'archived'
                  AND LOWER(TRIM(COALESCE(v.status, ''))) = 'published'
                  AND (LOWER(TRIM(COALESCE(c.course_state, ''))) IN ('public', 'private')
                       OR (COALESCE(c.course_state, '') = '' AND c.course_status = '1' AND LOWER(TRIM(COALESCE(c.course_visibility, 'public'))) IN ('public', 'private')))
                ORDER BY a.start_date ASC, a.id ASC");
        if (!$stmt) {
            $error = 'Revision Plan assignments are temporarily unavailable.';
            error_log('Revision Plan student list query could not be prepared: ' . $conn->error);
            return [];
        }
        $stmt->bind_param('i', $studentId);
        if (!$stmt->execute()) {
            $error = 'Revision Plan assignments are temporarily unavailable.';
            error_log('Revision Plan student list query failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_revision_assignments_for_version')) {
    function mmh_revision_assignments_for_version(mysqli $conn, int $versionId): array
    {
        if ($versionId <= 0 || !mmh_revision_assignment_schema_available($conn)) return [];
        $stmt = $conn->prepare("SELECT a.id, a.user_id, a.start_date, a.status, u.full_name, u.username FROM revision_plan_assignments a INNER JOIN users u ON u.user_id = a.user_id WHERE a.template_version_id = ? ORDER BY u.full_name ASC, u.username ASC");
        if (!$stmt) return [];
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
