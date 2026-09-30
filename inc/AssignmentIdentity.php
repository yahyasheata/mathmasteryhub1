<?php
/**
 * Canonical Course Homework identity.
 *
 * A normal Course Item owns at most one Assignment through
 * course_items.assignment_id. Older records may have only assignments.item_id
 * or an encoded JSON/HTML reference; those representations are read through
 * this compatibility boundary only and are never treated as competing write
 * authorities.
 */
require_once __DIR__ . '/CourseResourceResolver.php';

if (!function_exists('mmh_course_assignment_links')) {
    /**
     * The one compatibility parser for historical Course Item identity.
     * Application features must consume mmh_assignment_identity_for_item()
     * rather than interpreting any of these representations themselves.
     */
    function mmh_course_assignment_links(array $item): array
    {
        $links = [];
        $scan = static function ($value, string $path) use (&$scan, &$links): void {
            if (!is_array($value)) return;
            foreach ($value as $key => $child) {
                $childPath = $path . '.' . (string) $key;
                if (strtolower((string) $key) === 'assignment_id') {
                    $candidate = mmh_assignment_identity_normalize_id($child);
                    if ($candidate !== '') $links[$candidate][] = $childPath;
                }
                if (is_array($child)) $scan($child, $childPath);
            }
        };
        foreach (['template_data' => ($item['template_data'] ?? ''), 'metadata' => ($item['metadata'] ?? '')] as $source => $json) {
            $decoded = mmh_course_resource_template_data($json);
            $scan($decoded, $source);
        }
        if (preg_match_all("~\\bdata-assignment-id\\s*=\\s*([\"'])\\s*([A-Za-z0-9_-]{1,40})\\s*\\1~i", (string) ($item['item_description'] ?? ''), $matches)) {
            foreach ($matches[2] as $candidate) $links[(string) $candidate][] = 'legacy_html.data-assignment-id';
        }
        return $links;
    }
}

if (!function_exists('mmh_assignment_identity_normalize_id')) {
    function mmh_assignment_identity_normalize_id($value): string
    {
        $value = trim((string) $value);
        return $value !== '' && strlen($value) <= 40 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value)
            ? $value
            : '';
    }
}

if (!function_exists('mmh_assignment_identity_column_exists')) {
    function mmh_assignment_identity_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        if (!$stmt) return $cache[$key] = false;
        $stmt->bind_param('ss', $table, $column);
        $found = $stmt->execute() && (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$key] = $found;
    }
}

if (!function_exists('mmh_assignment_identity_for_item')) {
    /**
     * Resolve one Course Item without mutating anything.
     *
     * Canonical existence/conflict verification always runs; the final flag
     * remains accepted for compatibility with existing callers but can no
     * longer disable validation. Legacy lookup is confined to this boundary
     * and reports ambiguity explicitly.
     */
    function mmh_assignment_identity_for_item(mysqli $conn, array $item, bool $allowLegacy = true, bool $verifyCanonical = true): array
    {
        $courseId = trim((string) ($item['course_id'] ?? ''));
        $itemId = mmh_assignment_identity_normalize_id($item['item_id'] ?? '');
        $rawCanonicalValue = (string) ($item['assignment_id'] ?? '');
        $canonicalPresent = $rawCanonicalValue !== '';
        $canonicalId = mmh_course_assignment_canonical_id($item);
        $legacyLinks = mmh_course_assignment_links($item);

        $result = [
            'assignment_id' => '',
            'canonical_assignment_id' => $canonicalId,
            'source' => $canonicalId !== '' ? 'course_items.assignment_id' : null,
            'status' => $canonicalPresent ? ($canonicalId !== '' ? 'ORPHANED_CANONICAL' : 'INVALID_CANONICAL') : 'UNRESOLVED',
            'legacy_ids' => array_keys($legacyLinks),
            'sources' => $legacyLinks,
        ];

        // A populated canonical column is authoritative even when malformed.
        // Never reinterpret malformed canonical data as permission to fall back.
        if ($canonicalPresent && $canonicalId === '') return $result;
        if ($canonicalId !== '') {
            $stmt = $conn->prepare('SELECT assignment_id, course_id, item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
            if (!$stmt) {
                $result['status'] = 'LOOKUP_ERROR';
                $result['lookup_reason'] = $conn->error;
                return $result;
            }
            $stmt->bind_param('ss', $canonicalId, $courseId);
            if (!$stmt->execute()) {
                $stmt->close();
                $result['status'] = 'LOOKUP_ERROR';
                $result['lookup_reason'] = $conn->error;
                return $result;
            }
            $assignment = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$assignment) return $result;

            // A duplicate active normal-Homework claim is unsafe: neither
            // progress nor authorization should silently choose a claimant.
            if (mmh_assignment_identity_is_homework_item($item) && $courseId !== '') {
                $claimSql = "SELECT item_id, item_type, template_type FROM course_items WHERE course_id = ? AND CAST(assignment_id AS CHAR) = ?";
                if (mmh_assignment_identity_column_exists($conn, 'course_items', 'archived_at')) $claimSql .= " AND COALESCE(CAST(archived_at AS CHAR), '') = ''";
                if (mmh_assignment_identity_column_exists($conn, 'course_items', 'status')) $claimSql .= " AND (status IS NULL OR status = '' OR status = 'published')";
                $claims = $conn->prepare($claimSql);
                if (!$claims) {
                    $result['status'] = 'LOOKUP_ERROR';
                    $result['lookup_reason'] = $conn->error;
                    return $result;
                }
                $claims->bind_param('ss', $courseId, $canonicalId);
                if (!$claims->execute()) {
                    $claims->close();
                    $result['status'] = 'LOOKUP_ERROR';
                    $result['lookup_reason'] = $conn->error;
                    return $result;
                }
                $homeworkClaimants = [];
                $claimRows = $claims->get_result();
                while ($claim = $claimRows->fetch_assoc()) {
                    if (mmh_assignment_identity_is_homework_item($claim)) $homeworkClaimants[] = (string) $claim['item_id'];
                }
                $claims->close();
                if (count(array_unique($homeworkClaimants)) > 1) {
                    $result['status'] = 'DUPLICATE_CANONICAL_CLAIM';
                    $result['claimant_item_ids'] = array_values(array_unique($homeworkClaimants));
                    return $result;
                }
            }

            $result['assignment_id'] = $canonicalId;
            $result['status'] = 'CLEAN';
            // Reverse and legacy disagreement is diagnostic only when a
            // valid canonical identity exists; it never changes the result.
            $conflicts = [];
            if (($linkedItem = mmh_assignment_identity_normalize_id($assignment['item_id'] ?? '')) !== '' && $itemId !== '' && $linkedItem !== $itemId) {
                $conflicts[] = ['source' => 'assignments.item_id', 'value' => $linkedItem];
            }
            foreach ($legacyLinks as $legacyId => $sources) {
                if ((string) $legacyId !== $canonicalId) $conflicts[] = ['source' => implode(',', $sources), 'value' => (string) $legacyId];
            }
            if ($conflicts) {
                $result['status'] = 'CONFLICT';
                $result['conflicts'] = $conflicts;
            }
            return $result;
        }

        if (!$allowLegacy || $courseId === '' || $itemId === '') {
            return $result;
        }

        $candidates = [];
        $byItem = $conn->prepare('SELECT assignment_id, item_id FROM assignments WHERE course_id = ? AND item_id = ? ORDER BY id ASC');
        if (!$byItem) { $result['status'] = 'LOOKUP_ERROR'; return $result; }
        $byItem->bind_param('ss', $courseId, $itemId);
        if (!$byItem->execute()) { $byItem->close(); $result['status'] = 'LOOKUP_ERROR'; return $result; }
        $rows = $byItem->get_result();
        while ($row = $rows->fetch_assoc()) {
            $id = mmh_assignment_identity_normalize_id($row['assignment_id'] ?? '');
            if ($id !== '') $candidates[$id]['sources'][] = 'assignments.item_id';
            if ($id !== '') $candidates[$id]['reverse_item_ids'][] = (string) ($row['item_id'] ?? '');
        }
        $byItem->close();

        foreach (array_keys($legacyLinks) as $legacyId) {
            $legacyId = mmh_assignment_identity_normalize_id($legacyId);
            if ($legacyId === '') continue;
            $exists = $conn->prepare('SELECT assignment_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
            if (!$exists) { $result['status'] = 'LOOKUP_ERROR'; return $result; }
            $exists->bind_param('ss', $legacyId, $courseId);
            if (!$exists->execute()) { $exists->close(); $result['status'] = 'LOOKUP_ERROR'; return $result; }
            if ($row = $exists->get_result()->fetch_assoc()) {
                $candidates[$legacyId]['sources'] = array_values(array_unique(array_merge($candidates[$legacyId]['sources'] ?? [], $legacyLinks[$legacyId] ?? [])));
            }
            $exists->close();
        }

        // Preserve every syntactically valid legacy claim, including IDs that
        // do not resolve to an Assignment row. A missing row is still
        // contradictory identity evidence and must not disappear from the
        // reconciliation safety decision.
        $legacyClaimIds = array_values(array_unique(array_filter(array_map(
            'mmh_assignment_identity_normalize_id',
            array_keys($legacyLinks)
        ))));
        if (count($legacyClaimIds) > 1) {
            $result['status'] = 'CONFLICTING_LEGACY';
            $result['candidate_ids'] = array_keys($candidates);
            $result['legacy_claim_ids'] = $legacyClaimIds;
            return $result;
        }

        if (count($candidates) === 1) {
            $assignmentId = (string) array_key_first($candidates);
            $candidate = $conn->prepare('SELECT assignment_id, course_id, item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
            if (!$candidate) { $result['status'] = 'LOOKUP_ERROR'; return $result; }
            $candidate->bind_param('ss', $assignmentId, $courseId);
            if (!$candidate->execute()) { $candidate->close(); $result['status'] = 'LOOKUP_ERROR'; return $result; }
            $assignment = $candidate->get_result()->fetch_assoc() ?: null;
            $candidate->close();
            if (!$assignment) return $result;

            $reverseItem = mmh_assignment_identity_normalize_id($assignment['item_id'] ?? '');
            if ($reverseItem !== '' && $reverseItem !== $itemId) {
                $result['status'] = 'CONFLICTING_LEGACY';
                $result['candidate_ids'] = [$assignmentId];
                return $result;
            }
            $claimSql = 'SELECT item_id FROM course_items WHERE course_id = ? AND CAST(assignment_id AS CHAR) = ?';
            if (mmh_assignment_identity_column_exists($conn, 'course_items', 'archived_at')) $claimSql .= " AND COALESCE(CAST(archived_at AS CHAR), '') = ''";
            if (mmh_assignment_identity_column_exists($conn, 'course_items', 'status')) $claimSql .= " AND (status IS NULL OR status = '' OR status = 'published')";
            $claim = $conn->prepare($claimSql);
            if (!$claim) { $result['status'] = 'LOOKUP_ERROR'; return $result; }
            $claim->bind_param('ss', $courseId, $assignmentId);
            if (!$claim->execute()) { $claim->close(); $result['status'] = 'LOOKUP_ERROR'; return $result; }
            $claimants = [];
            $claimRows = $claim->get_result();
            while ($row = $claimRows->fetch_assoc()) $claimants[] = (string) $row['item_id'];
            $claim->close();
            if (array_filter($claimants, static fn($claimant) => $claimant !== $itemId)) {
                $result['status'] = 'COMPETING_CANONICAL_CLAIM';
                $result['candidate_ids'] = [$assignmentId];
                $result['claimant_item_ids'] = array_values(array_unique($claimants));
                return $result;
            }
            return array_merge($result, [
                'assignment_id' => $assignmentId,
                'source' => implode(',', $candidates[$assignmentId]['sources'] ?? []),
                'status' => 'LEGACY_FALLBACK',
            ]);
        }
        if (count($candidates) > 1) {
            $result['status'] = 'AMBIGUOUS';
            $result['candidate_ids'] = array_keys($candidates);
        }
        return $result;
    }
}

if (!function_exists('mmh_assignment_identity_id')) {
    function mmh_assignment_identity_id(mysqli $conn, array $item, bool $allowLegacy = true): string
    {
        $resolved = mmh_assignment_identity_for_item($conn, $item, $allowLegacy);
        return in_array((string) ($resolved['status'] ?? ''), ['CLEAN', 'LEGACY_FALLBACK', 'CONFLICT'], true)
            ? (string) ($resolved['assignment_id'] ?? '')
            : '';
    }
}

if (!function_exists('mmh_assignment_identity_is_homework_item')) {
    function mmh_assignment_identity_is_homework_item(array $item): bool
    {
        $template = strtolower(trim((string) ($item['template_type'] ?? '')));
        $type = strtolower(trim((string) ($item['item_type'] ?? '')));
        if ($template === 'timed_exam') {
            return false;
        }
        return in_array($template, ['classified_assignment', 'assignment', 'homework'], true)
            || in_array($type, ['quiz', 'assignment', 'homework'], true);
    }
}

if (!function_exists('mmh_assignment_identity_item_for_assignment')) {
    /** Resolve a Course Item context for reports/files without consulting reverse ownership directly. */
    function mmh_assignment_identity_item_for_assignment(mysqli $conn, string $courseId, string $assignmentId, bool $activeOnly = true): array
    {
        $courseId = trim($courseId);
        $assignmentId = mmh_assignment_identity_normalize_id($assignmentId);
        if ($courseId === '' || $assignmentId === '') return ['status' => 'UNRESOLVED', 'item' => null];

        $sql = 'SELECT * FROM course_items WHERE course_id = ?';
        if ($activeOnly && mmh_assignment_identity_column_exists($conn, 'course_items', 'archived_at')) $sql .= " AND COALESCE(CAST(archived_at AS CHAR), '') = ''";
        if ($activeOnly && mmh_assignment_identity_column_exists($conn, 'course_items', 'status')) $sql .= " AND (status IS NULL OR status = '' OR status = 'published')";
        $sql .= ' ORDER BY id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return ['status' => 'LOOKUP_ERROR', 'item' => null];
        $stmt->bind_param('s', $courseId);
        if (!$stmt->execute()) { $stmt->close(); return ['status' => 'LOOKUP_ERROR', 'item' => null]; }
        $matches = [];
        $duplicateClaim = false;
        $rows = $stmt->get_result();
        while ($item = $rows->fetch_assoc()) {
            if (!mmh_assignment_identity_is_homework_item($item)) continue;
            $identity = mmh_assignment_identity_for_item($conn, $item, true);
            if (($identity['status'] ?? '') === 'DUPLICATE_CANONICAL_CLAIM'
                && (string) ($identity['canonical_assignment_id'] ?? '') === $assignmentId) {
                $duplicateClaim = true;
                continue;
            }
            if ((string) ($identity['assignment_id'] ?? '') === $assignmentId
                && in_array((string) ($identity['status'] ?? ''), ['CLEAN', 'CONFLICT', 'LEGACY_FALLBACK'], true)) {
                $matches[] = $item;
            }
        }
        $stmt->close();
        if ($duplicateClaim) return ['status' => 'DUPLICATE_CANONICAL_CLAIM', 'item' => null];
        if (count($matches) === 1) return ['status' => 'FOUND', 'item' => $matches[0]];
        if (count($matches) > 1) return ['status' => 'DUPLICATE_CANONICAL_CLAIM', 'item' => null, 'item_ids' => array_map(static fn($item) => (string) $item['item_id'], $matches)];
        return ['status' => 'UNRESOLVED', 'item' => null];
    }
}
