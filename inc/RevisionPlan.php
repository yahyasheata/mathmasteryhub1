<?php
declare(strict_types=1);

/**
 * Definition-only Revision Plan domain.
 *
 * This service deliberately does not assign plans to students, write Learning
 * Journey state, or handle student submissions. Those concerns belong to the
 * later Revision Plan phases. Recovery Plan tables and services are separate.
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
            $cleanBatch = ['title' => $batchTitle, 'description' => mb_substr(trim((string) ($batch['description'] ?? '')), 0, 1000), 'suggested_days' => max(0, min(365, (int) ($batch['suggested_days'] ?? 0))), 'sort_order' => count($clean['batches']), 'days' => []];
            foreach ((array) ($batch['days'] ?? []) as $dayIndex => $day) {
                if (!is_array($day)) continue;
                $dayTitle = mb_substr(trim((string) ($day['title'] ?? '')), 0, 180);
                if ($dayTitle === '') throw new InvalidArgumentException('Every day needs a title.');
                // Day numbers are presentation sequence, never a client-controlled ID.
                $cleanDay = ['day_number' => count($cleanBatch['days']) + 1, 'title' => $dayTitle, 'description' => mb_substr(trim((string) ($day['description'] ?? '')), 0, 1000), 'sort_order' => count($cleanBatch['days']), 'requirements' => [], 'activity_groups' => []];
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
            foreach ($clean['batches'] as $batch) {
                $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('issii', $versionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order']); if (!$stmt->execute()) throw new RuntimeException('Unable to save a batch.'); $batchId = (int) $stmt->insert_id; $stmt->close();
                foreach ($batch['days'] as $day) {
                    $stmt = $conn->prepare('INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('iiissi', $batchId, $versionId, $day['day_number'], $day['title'], $day['description'], $day['sort_order']); if (!$stmt->execute()) throw new RuntimeException('Unable to save a day.'); $dayId = (int) $stmt->insert_id; $stmt->close();
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
    function mmh_revision_publish_version(mysqli $conn, int $versionId, int $adminId): void
    {
        $version = mmh_revision_version($conn, $versionId);
        if (!$version) throw new InvalidArgumentException('Version not found.');
        if ((string) $version['status'] !== 'draft') throw new InvalidArgumentException('Only a Draft Version can be finalized.');
        $stmt = $conn->prepare("UPDATE revision_plan_template_versions SET status = 'published', published_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'draft'");
        if (!$stmt) throw new RuntimeException('Unable to finalize the Version.');
        $stmt->bind_param('i', $versionId); if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('The Version could not be finalized.'); $stmt->close();
        $touch = $conn->prepare('UPDATE revision_plan_templates SET updated_at = CURRENT_TIMESTAMP WHERE id = ?'); if ($touch) { $touch->bind_param('i', $version['template_id']); $touch->execute(); $touch->close(); }
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
            foreach ((array) ($source['resources'] ?? []) as $resource) {
                $stmt = $conn->prepare('INSERT INTO revision_plan_template_resources (version_id, batch_id, resource_type, display_name, external_url, storage_key, original_filename, mime_type, file_size_bytes, linked_course_item_id, sort_order, created_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issssssisii', $newVersionId, $resource['resource_type'], $resource['display_name'], $resource['external_url'], $resource['storage_key'], $resource['original_filename'], $resource['mime_type'], $resource['file_size_bytes'], $resource['linked_course_item_id'], $resource['sort_order'], $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to clone a shared resource.'); $resourceMap[(int) $resource['id']] = (int) $stmt->insert_id; $stmt->close();
            }
            foreach ((array) ($source['batches'] ?? []) as $batch) {
                $stmt = $conn->prepare('INSERT INTO revision_plan_template_batches (version_id, title, description, suggested_days, sort_order) VALUES (?, ?, ?, ?, ?)'); $stmt->bind_param('issii', $newVersionId, $batch['title'], $batch['description'], $batch['suggested_days'], $batch['sort_order']); if (!$stmt->execute()) throw new RuntimeException('Unable to clone a batch.'); $batchId = (int) $stmt->insert_id; $stmt->close(); $batchMap[(int) $batch['id']] = $batchId;
                foreach ((array) ($batch['days'] ?? []) as $day) {
                    $stmt = $conn->prepare('INSERT INTO revision_plan_template_days (batch_id, version_id, day_number, title, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)'); $stmt->bind_param('iiissi', $batchId, $newVersionId, $day['day_number'], $day['title'], $day['description'], $day['sort_order']); if (!$stmt->execute()) throw new RuntimeException('Unable to clone a day.'); $dayId = (int) $stmt->insert_id; $stmt->close();
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
        $external = ''; $storage = ''; $original = ''; $mime = ''; $size = 0; $itemId = trim((string) ($input['linked_course_item_id'] ?? ''));
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
        $stmt = $conn->prepare('INSERT INTO revision_plan_template_resources (version_id, resource_type, display_name, external_url, storage_key, original_filename, mime_type, file_size_bytes, linked_course_item_id, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?)'); if (!$stmt) throw new RuntimeException('Unable to save the resource.'); $sort = 0; $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM revision_plan_template_resources WHERE version_id = ?'); if ($max) { $max->bind_param('i', $versionId); $max->execute(); $sort = (int) (($max->get_result()->fetch_assoc()['next_order'] ?? 0)); $max->close(); } $stmt->bind_param('issssssisii', $versionId, $type, $name, $external, $storage, $original, $mime, $size, $itemId, $sort, $adminId); if (!$stmt->execute()) throw new RuntimeException('Unable to save the resource.'); $id = (int) $stmt->insert_id; $stmt->close(); return $id;
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
            foreach ((array) ($batch['days'] ?? []) as $day) {
                $scheduled = $start->modify('+' . $offset . ' days');
                $day['batch_title'] = (string) ($batch['title'] ?? 'Batch');
                $day['absolute_day_number'] = $offset + 1;
                $day['scheduled_date'] = $scheduled->format('Y-m-d');
                $day['availability'] = $scheduled < $today ? 'previous' : ($scheduled == $today ? 'today' : ($allowAhead ? 'upcoming' : 'locked'));
                $day['accessible'] = $allowAhead || $scheduled <= $today;
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
        if ($resourceId !== null && (!$resourceRequirement || empty($resources[$resourceId]))) return null;
        if ($requirementId !== null && $resourceId !== null && (int) ($foundRequirement['id'] ?? 0) !== (int) ($resourceRequirement['id'] ?? 0)) return null;
        if ($requirementId === null && $resourceId !== null) { $foundRequirement = $resourceRequirement; $foundDay = $resourceDay; }
        if ($foundDay && empty($foundDay['accessible'])) return null;
        return ['assignment' => $assignment, 'version' => $assignment['version'], 'days' => $days, 'day' => $foundDay, 'requirement' => $foundRequirement, 'resource' => $resourceId !== null ? ($resources[$resourceId] ?? null) : null];
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
            $insert = $conn->prepare('INSERT INTO revision_plan_assignments (template_id, template_version_id, course_id, user_id, start_date, assigned_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
            if (!$check || !$insert) throw new RuntimeException('Unable to prepare Revision Plan assignment.');
            foreach ($ids as $studentId) { $check->bind_param('si', $courseId, $studentId); $check->execute(); if (!$check->get_result()->fetch_assoc()) throw new InvalidArgumentException('Every selected student must be an active enrollee in this course.'); }
            foreach ($ids as $studentId) { $insert->bind_param('iisisi', $templateId, $versionId, $courseId, $studentId, $startDate, $adminId); if (!$insert->execute()) throw new RuntimeException('Unable to assign the Revision Plan.'); $count += $insert->affected_rows === 1 ? 1 : 0; }
            $check->close();
            $insert->close();
            $conn->commit();
            return $count;
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('mmh_revision_student_assignments')) {
    function mmh_revision_student_assignments(mysqli $conn, int $studentId): array
    {
        if ($studentId <= 0 || !mmh_revision_assignment_schema_available($conn)) return [];
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
        if (!$stmt) { error_log('Revision Plan student list query could not be prepared: ' . $conn->error); return []; }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
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
