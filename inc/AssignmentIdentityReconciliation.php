<?php
/** Safe, transactional promotion of a proven legacy Homework link. */
require_once __DIR__ . '/AssignmentIdentity.php';

if (!function_exists('mmh_assignment_identity_reconciliation_safety')) {
    function mmh_assignment_identity_reconciliation_safety(mysqli $conn, array $item, array $identity): array
    {
        if (trim((string) ($item['assignment_id'] ?? '')) !== '') return [false, 'Canonical field is already populated.'];
        if (($identity['status'] ?? '') !== 'LEGACY_FALLBACK' || trim((string) ($identity['assignment_id'] ?? '')) === '') return [false, 'Identity is not one unambiguous legacy candidate.'];
        $assignmentId = (string) $identity['assignment_id'];
        $courseId = (string) ($item['course_id'] ?? '');
        $itemId = (string) ($item['item_id'] ?? '');
        $candidate = $conn->prepare('SELECT assignment_id, course_id, item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        if (!$candidate) return [false, 'Candidate Assignment could not be verified.'];
        $candidate->bind_param('ss', $assignmentId, $courseId);
        if (!$candidate->execute()) { $candidate->close(); return [false, 'Candidate Assignment lookup failed.']; }
        $row = $candidate->get_result()->fetch_assoc() ?: null;
        $candidate->close();
        if (!$row) return [false, 'Candidate Assignment does not exist in the same Course.'];
        $legacyClaims = array_values(array_unique(array_filter(array_map(
            'mmh_assignment_identity_normalize_id',
            array_keys((array) ($identity['sources'] ?? []))
        ))));
        if (count($legacyClaims) > 1 || ($legacyClaims && $legacyClaims[0] !== $assignmentId)) {
            return [false, 'Stored legacy Assignment references contain contradictory identity evidence.'];
        }
        $reverseItem = trim((string) ($row['item_id'] ?? ''));
        if ($reverseItem !== '' && $reverseItem !== $itemId) return [false, 'Reverse compatibility link points to a different Course Item.'];
        $claims = $conn->prepare('SELECT item_id FROM course_items WHERE course_id = ? AND CAST(assignment_id AS CHAR) = ? AND item_id <> ? LIMIT 1');
        if (!$claims) return [false, 'Competing canonical claims could not be checked.'];
        $claims->bind_param('sss', $courseId, $assignmentId, $itemId);
        if (!$claims->execute()) { $claims->close(); return [false, 'Competing canonical claim lookup failed.']; }
        $claim = $claims->get_result()->fetch_assoc() ?: null;
        $claims->close();
        if ($claim) return [false, 'Another Course Item already canonically claims this Assignment.'];
        return [true, 'One same-Course Assignment candidate; no contradictory canonical claim or reverse owner.'];
    }
}

if (!function_exists('mmh_assignment_identity_reconciliation_apply')) {
    /** Locks, revalidates, then updates canonical + derived reverse fields atomically. */
    function mmh_assignment_identity_reconciliation_apply(mysqli $conn, string $courseId, string $itemId, string $expectedAssignmentId): void
    {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin reconciliation transaction.');
        try {
            $itemStmt = $conn->prepare('SELECT * FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1 FOR UPDATE');
            if (!$itemStmt) throw new RuntimeException('Unable to lock the Course Item.');
            $itemStmt->bind_param('ss', $courseId, $itemId);
            if (!$itemStmt->execute()) throw new RuntimeException('Unable to lock the Course Item.');
            $item = $itemStmt->get_result()->fetch_assoc() ?: null;
            $itemStmt->close();
            if (!$item) throw new RuntimeException('Course Item no longer exists.');
            $currentCanonical = trim((string) ($item['assignment_id'] ?? ''));
            if ($currentCanonical !== '') {
                $currentIdentity = mmh_assignment_identity_for_item($conn, $item, true, true);
                if ($currentCanonical === $expectedAssignmentId && ($currentIdentity['status'] ?? '') === 'CLEAN' && ($currentIdentity['assignment_id'] ?? '') === $expectedAssignmentId) {
                    $conn->commit();
                    return;
                }
                throw new RuntimeException('Course Item canonical identity changed during reconciliation.');
            }

            $assignmentStmt = $conn->prepare('SELECT assignment_id, course_id, item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            if (!$assignmentStmt) throw new RuntimeException('Unable to lock the candidate Assignment.');
            $assignmentStmt->bind_param('ss', $expectedAssignmentId, $courseId);
            if (!$assignmentStmt->execute()) throw new RuntimeException('Unable to lock the candidate Assignment.');
            $assignment = $assignmentStmt->get_result()->fetch_assoc() ?: null;
            $assignmentStmt->close();
            if (!$assignment) throw new RuntimeException('Candidate Assignment no longer exists in this Course.');

            $identity = mmh_assignment_identity_for_item($conn, $item, true, true);
            [$safe, $reason] = mmh_assignment_identity_reconciliation_safety($conn, $item, $identity);
            if (!$safe || (string) ($identity['assignment_id'] ?? '') !== $expectedAssignmentId) throw new RuntimeException('Apply-time safety revalidation failed: ' . $reason);

            $link = $conn->prepare("UPDATE course_items SET assignment_id = ? WHERE course_id = ? AND item_id = ? AND (assignment_id IS NULL OR assignment_id = '')");
            if (!$link) throw new RuntimeException('Unable to prepare canonical identity update.');
            $link->bind_param('sss', $expectedAssignmentId, $courseId, $itemId);
            if (!$link->execute() || $link->affected_rows !== 1) { $error = $link->error; $link->close(); throw new RuntimeException($error ?: 'Canonical identity update did not affect one Course Item.'); }
            $link->close();

            $reverse = $conn->prepare("UPDATE assignments SET item_id = ? WHERE assignment_id = ? AND course_id = ? AND (item_id IS NULL OR item_id = '')");
            if (!$reverse) throw new RuntimeException('Unable to prepare reverse compatibility synchronization.');
            $reverse->bind_param('sss', $itemId, $expectedAssignmentId, $courseId);
            if (!$reverse->execute()) { $error = $reverse->error; $reverse->close(); throw new RuntimeException($error ?: 'Reverse compatibility synchronization failed.'); }
            $reverseChanged = $reverse->affected_rows;
            $reverse->close();
            if (trim((string) ($assignment['item_id'] ?? '')) === '' && $reverseChanged !== 1) throw new RuntimeException('Reverse compatibility synchronization did not affect the candidate Assignment.');
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }
}
