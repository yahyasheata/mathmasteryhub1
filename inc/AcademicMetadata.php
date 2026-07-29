<?php
/**
 * Shared B2 academic metadata helpers.
 *
 * Element metadata remains in course_items.metadata. Homework fields remain
 * on assignments because they are the records future performance queries use.
 */

require_once __DIR__ . '/learning_schema.php';

if (!function_exists('mmh_academic_clean_text')) {
    function mmh_academic_clean_text($value, $maxLength = 0)
    {
        $value = trim((string) $value);
        if ($maxLength > 0 && function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return $maxLength > 0 ? substr($value, 0, $maxLength) : $value;
    }
}

if (!function_exists('mmh_academic_topic_list')) {
    function mmh_academic_topic_list(mysqli $conn, $courseId, $activeOnly = false)
    {
        $sql = 'SELECT id, course_id, parent_topic_id, title, sort_order, is_active FROM course_topics WHERE course_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY parent_topic_id ASC, sort_order ASC, title ASC, id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_academic_topic_by_id')) {
    function mmh_academic_topic_by_id(mysqli $conn, $courseId, $topicId)
    {
        if (!is_numeric($topicId) || (int) $topicId < 1) {
            return null;
        }
        $topicId = (int) $topicId;
        $stmt = $conn->prepare('SELECT id, course_id, parent_topic_id, title, is_active FROM course_topics WHERE id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $topicId, $courseId);
        $stmt->execute();
        $topic = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $topic ?: null;
    }
}

if (!function_exists('mmh_academic_create_topic')) {
    function mmh_academic_create_topic(mysqli $conn, $courseId, $title, $parentTopicId = 0)
    {
        $title = mmh_academic_clean_text($title, 120);
        $parentTopicId = is_numeric($parentTopicId) ? (int) $parentTopicId : 0;
        if ($title === '') {
            return 0;
        }
        if ($parentTopicId > 0 && !mmh_academic_topic_by_id($conn, $courseId, $parentTopicId)) {
            return 0;
        }

        $find = $conn->prepare('SELECT id FROM course_topics WHERE course_id = ? AND parent_topic_id = ? AND title = ? LIMIT 1');
        if (!$find) {
            return 0;
        }
        $find->bind_param('sis', $courseId, $parentTopicId, $title);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();
        if ($existing) {
            return (int) $existing['id'];
        }

        $nextOrder = 1;
        $order = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM course_topics WHERE course_id = ? AND parent_topic_id = ?');
        if ($order) {
            $order->bind_param('si', $courseId, $parentTopicId);
            $order->execute();
            $nextOrder = (int) (($order->get_result()->fetch_assoc())['next_order'] ?? 1);
            $order->close();
        }
        $insert = $conn->prepare('INSERT INTO course_topics (course_id, parent_topic_id, title, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
        if (!$insert) {
            return 0;
        }
        $insert->bind_param('sisi', $courseId, $parentTopicId, $title, $nextOrder);
        $ok = $insert->execute();
        $id = $ok ? (int) $conn->insert_id : 0;
        $insert->close();
        return $id;
    }
}

if (!function_exists('mmh_academic_rename_topic')) {
    function mmh_academic_rename_topic(mysqli $conn, $courseId, $topicId, $title)
    {
        $topic = mmh_academic_topic_by_id($conn, $courseId, $topicId);
        $title = mmh_academic_clean_text($title, 120);
        if (!$topic || $title === '' || $title === (string) $topic['title']) {
            return (bool) $topic;
        }
        $duplicate = $conn->prepare('SELECT id FROM course_topics WHERE course_id = ? AND parent_topic_id = ? AND title = ? AND id <> ? LIMIT 1');
        if (!$duplicate) {
            return false;
        }
        $parent = (int) $topic['parent_topic_id'];
        $id = (int) $topic['id'];
        $duplicate->bind_param('sisi', $courseId, $parent, $title, $id);
        $duplicate->execute();
        $exists = $duplicate->get_result()->num_rows > 0;
        $duplicate->close();
        if ($exists) {
            return false;
        }
        $update = $conn->prepare('UPDATE course_topics SET title = ? WHERE id = ? AND course_id = ? LIMIT 1');
        if (!$update) {
            return false;
        }
        $update->bind_param('sis', $title, $id, $courseId);
        $ok = $update->execute();
        $update->close();
        return $ok;
    }
}

if (!function_exists('mmh_academic_set_topic_state')) {
    function mmh_academic_set_topic_state(mysqli $conn, $courseId, $topicId, $state)
    {
        if (!in_array($state, ['active', 'inactive'], true) || !mmh_academic_topic_by_id($conn, $courseId, $topicId)) {
            return false;
        }
        $isActive = $state === 'active' ? 1 : 0;
        $topicId = (int) $topicId;
        $stmt = $conn->prepare('UPDATE course_topics SET is_active = ? WHERE id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iis', $isActive, $topicId, $courseId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_academic_parse_id_list')) {
    function mmh_academic_parse_id_list($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        foreach ($values as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $ids[(int) $candidate] = true;
            }
        }
        return array_keys($ids);
    }
}

if (!function_exists('mmh_academic_validate_topic_ids')) {
    function mmh_academic_validate_topic_ids(mysqli $conn, $courseId, array $ids)
    {
        $valid = [];
        foreach ($ids as $id) {
            if (mmh_academic_topic_by_id($conn, $courseId, $id)) {
                $valid[] = (int) $id;
            }
        }
        return $valid;
    }
}

if (!function_exists('mmh_academic_score_mode')) {
    function mmh_academic_score_mode($value, $fallback = 'disabled')
    {
        $value = trim((string) $value);
        $allowed = ['disabled', 'accept_automatically', 'require_teacher_verification'];
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return in_array($fallback, $allowed, true) ? $fallback : 'disabled';
    }
}

if (!function_exists('mmh_academic_score_mode_from_flags')) {
    function mmh_academic_score_mode_from_flags($allowSelfScore, $requireTeacherVerification)
    {
        if ((int) $allowSelfScore !== 1) {
            return 'disabled';
        }
        return (int) $requireTeacherVerification === 1 ? 'require_teacher_verification' : 'accept_automatically';
    }
}

if (!function_exists('mmh_academic_score_mode_flags')) {
    function mmh_academic_score_mode_flags($mode)
    {
        $mode = mmh_academic_score_mode($mode);
        return [
            'mode' => $mode,
            'allow_self_score' => $mode === 'disabled' ? 0 : 1,
            'require_teacher_verification' => $mode === 'require_teacher_verification' ? 1 : 0,
        ];
    }
}


/**
 * Hierarchical course metadata is intentionally stored in the existing JSON
 * metadata pattern: course_sections.metadata owns shared values and
 * course_items.metadata.section_overrides owns only explicit lesson values.
 */
if (!function_exists('mmh_hierarchical_metadata_fields')) {
    function mmh_hierarchical_metadata_fields(): array
    {
        return [
            'subject', 'domain', 'primary_topic_id', 'secondary_topic_id',
            'subtopic_id', 'learning_objective', 'difficulty',
            'estimated_hours', 'priority', 'recommended_order',
            'sequential_learning', 'exam_board', 'paper',
            'calculator_allowed', 'chapter', 'tags', 'skills', 'summary',
        ];
    }
}

if (!function_exists('mmh_hierarchical_metadata_list')) {
    function mmh_hierarchical_metadata_list($value, $maxItems = 40, $maxLength = 80): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,\n;]+/', $value);
        }
        $values = is_array($value) ? $value : [];
        $result = [];
        foreach ($values as $candidate) {
            $candidate = mmh_academic_clean_text($candidate, $maxLength);
            if ($candidate !== '') {
                $key = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
                $result[$key] = $candidate;
            }
            if (count($result) >= $maxItems) {
                break;
            }
        }
        return array_values($result);
    }
}

if (!function_exists('mmh_hierarchical_metadata_decode')) {
    function mmh_hierarchical_metadata_decode($value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach (mmh_hierarchical_metadata_fields() as $field) {
            if (!array_key_exists($field, $decoded)) {
                continue;
            }
            $value = $decoded[$field];
            if (in_array($field, ['tags', 'skills'], true)) {
                $value = mmh_hierarchical_metadata_list($value);
            } elseif (in_array($field, ['primary_topic_id', 'secondary_topic_id', 'subtopic_id', 'recommended_order'], true)) {
                $value = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
            } elseif ($field === 'estimated_hours') {
                $value = is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
            } elseif ($field === 'sequential_learning') {
                $value = is_bool($value) ? $value : (in_array((string) $value, ['1', 'yes', 'true'], true) ? true : (in_array((string) $value, ['0', 'no', 'false'], true) ? false : null));
            } elseif (in_array($field, ['difficulty', 'priority', 'calculator_allowed'], true)) {
                $allowed = [
                    'difficulty' => ['easy', 'medium', 'hard', 'mixed'],
                    'priority' => ['low', 'normal', 'high'],
                    'calculator_allowed' => ['yes', 'no', 'mixed', 'not_applicable'],
                ][$field];
                $value = in_array((string) $value, $allowed, true) ? (string) $value : null;
            } else {
                $value = mmh_academic_clean_text($value, $field === 'learning_objective' || $field === 'summary' ? 4000 : 120);
                $value = $value === '' ? null : $value;
            }
            if ($value !== null && $value !== [] && $value !== '') {
                $result[$field] = $value;
            }
        }
        return $result;
    }
}

if (!function_exists('mmh_hierarchical_metadata_from_input')) {
    function mmh_hierarchical_metadata_from_input(mysqli $conn, $courseId, array $input, $prefix = 'section_metadata_'): array
    {
        $raw = [];
        foreach (mmh_hierarchical_metadata_fields() as $field) {
            $raw[$field] = $input[$prefix . $field] ?? null;
        }
        foreach (['primary_topic_id', 'secondary_topic_id', 'subtopic_id'] as $field) {
            $id = $raw[$field];
            $raw[$field] = is_numeric($id) && mmh_academic_topic_by_id($conn, $courseId, (int) $id) ? (int) $id : null;
        }
        return mmh_hierarchical_metadata_decode($raw);
    }
}

if (!function_exists('mmh_hierarchical_metadata_item_overrides')) {
    function mmh_hierarchical_metadata_item_overrides($itemMetadata): array
    {
        if (!is_array($itemMetadata)) {
            $decoded = json_decode((string) $itemMetadata, true);
            $itemMetadata = is_array($decoded) ? $decoded : [];
        }
        if (is_array($itemMetadata['section_overrides'] ?? null)) {
            return mmh_hierarchical_metadata_decode($itemMetadata['section_overrides']);
        }

        // Existing lesson metadata predates section inheritance. Treat only
        // populated legacy fields as explicit values so old lessons keep their
        // established analytics meaning without any migration.
        $legacy = [
            'primary_topic_id' => $itemMetadata['primary_topic_id'] ?? null,
            'subtopic_id' => $itemMetadata['subtopic_id'] ?? null,
            'learning_objective' => $itemMetadata['learning_objectives'] ?? null,
            'difficulty' => $itemMetadata['difficulty'] ?? null,
            'priority' => $itemMetadata['importance'] ?? null,
            'exam_board' => $itemMetadata['exam_board'] ?? null,
            'paper' => $itemMetadata['paper'] ?? null,
            'tags' => $itemMetadata['keywords'] ?? [],
            'skills' => $itemMetadata['skills_tested'] ?? [],
        ];
        return mmh_hierarchical_metadata_decode($legacy);
    }
}

if (!function_exists('mmh_hierarchical_metadata_resolve')) {
    function mmh_hierarchical_metadata_resolve($sectionMetadata, $itemMetadata): array
    {
        $section = mmh_hierarchical_metadata_decode($sectionMetadata);
        $overrides = mmh_hierarchical_metadata_item_overrides($itemMetadata);
        $resolved = $section;
        $sources = [];
        foreach ($section as $field => $_) {
            $sources[$field] = 'section';
        }
        foreach ($overrides as $field => $value) {
            $resolved[$field] = $value;
            $sources[$field] = 'element';
        }
        return ['metadata' => $resolved, 'sources' => $sources, 'section' => $section, 'overrides' => $overrides];
    }
}
