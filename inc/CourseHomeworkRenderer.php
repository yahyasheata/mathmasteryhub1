<?php
/** Shared protected student Homework presentation. */
require_once __DIR__ . '/AssignmentProgress.php';
require_once __DIR__ . '/StudentCourseCsrf.php';

if (!function_exists('mmh_homework_escape')) {
    function mmh_homework_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('mmh_homework_submission_endpoint')) {
    /**
     * The user request router resolves /requests/{page}/{action} to
     * {action}-{page}.php. Keep this route aligned with
     * views/user/requests/submission-assignment.php.
     */
    function mmh_homework_submission_endpoint($baseUrl)
    {
        return rtrim((string) $baseUrl, '/') . '/user/requests/assignment/submission';
    }
}

if (!function_exists('mmh_homework_assignment')) {
    function mmh_homework_assignment(mysqli $conn, $assignmentId, $courseId)
    {
        $stmt = $conn->prepare('SELECT assignment_id, assignment_title, assignment_description, due_date, late_submission_enabled, late_submission_until, file_path, course_id, section_id, item_id, max_score, allow_self_score, require_teacher_verification, completion_requirement, completion_rule, minimum_score FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ss', $assignmentId, $courseId);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $assignment;
    }
}

if (!function_exists('mmh_homework_due')) {
    function mmh_homework_due($value)
    {
        $value = trim((string) $value);
        if ($value === '') return ['label' => 'No deadline', 'machine' => ''];
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Riyadh'));
            return ['label' => $date->format('j F Y \\a\\t g:i A'), 'machine' => $date->format(DATE_ATOM)];
        } catch (Throwable $ignored) { return ['label' => 'No deadline', 'machine' => '']; }
    }
}

if (!function_exists('mmh_homework_status')) {
    function mmh_homework_status(array $assignment, ?array $submission)
    {
        $state = mmh_assignment_progress_evaluate($assignment, $submission);
        if ($submission && is_numeric($submission['grade'] ?? null) && strtolower((string) ($submission['self_score_status'] ?? '')) !== 'rejected') return ['label' => 'Graded', 'class' => 'is-graded', 'detail' => $state['reason']];
        if ($submission) {
            $submitted = strtotime((string) ($submission['submitted_at'] ?? '')); $due = strtotime((string) ($assignment['due_date'] ?? ''));
            if ($submitted !== false && $due !== false && $submitted > $due) return ['label' => 'Submitted late', 'class' => 'is-late', 'detail' => 'Submission received after the deadline.'];
            return ['label' => $state['label'] === 'Not started' ? 'Submitted' : $state['label'], 'class' => 'is-submitted', 'detail' => $state['reason']];
        }
        if (!empty($state['late_submission_open'])) {
            $until = mmh_homework_due($assignment['late_submission_until'] ?? '');
            return ['label' => 'Legacy Late Submission', 'class' => 'is-legacy-late', 'detail' => $until['machine'] ? 'Late submission is available until ' . $until['label'] . '.' : 'Late submission is temporarily available.'];
        }
        if ($state['state'] === 'overdue') return ['label' => 'Overdue', 'class' => 'is-overdue', 'detail' => $state['reason']];
        return ['label' => 'Not submitted', 'class' => 'is-pending', 'detail' => $state['reason']];
    }
}

if (!function_exists('mmh_homework_relation_key')) {
    function mmh_homework_relation_key($title)
    {
        $title = strtolower((string) $title);
        $title = preg_replace('/\\b(?:model|answer|answers|homework|assignment|upload|solution|and)\\b/u', ' ', $title);
        $title = preg_replace('/[^a-z0-9]+/u', ' ', $title);
        return trim(preg_replace('/\\s+/u', ' ', $title));
    }
}

if (!function_exists('mmh_homework_model_answer_resource')) {
    /**
     * Uses the native structured slot first. Older separate Model Answer items
     * are only related when the next item is in the same section and has the
     * exact normalized Homework title, so ambiguous teacher content stays
     * untouched and visible as its own lesson.
     */
    function mmh_homework_model_answer_resource(mysqli $conn, array $course, array $selection, array $resource)
    {
        $native = is_array($resource['model_answer_resource'] ?? null) ? $resource['model_answer_resource'] : null;
        if ($native && mmh_course_resource_safe_url($native['url'] ?? '') !== null) {
            $native['title'] = 'Model Answer';
            $native['release'] = $native['release'] ?? 'hidden';
            $native['source'] = 'structured';
            return $native;
        }

        $item = $selection['item'] ?? [];
        $courseId = (string) ($course['course_id'] ?? '');
        $sectionId = (string) ($selection['section_id'] ?? '');
        $order = (int) ($item['page_order'] ?? 0);
        if ($courseId === '' || $order < 0) return null;
        $stmt = $conn->prepare('SELECT * FROM course_items WHERE course_id = ? AND section_id <=> ? AND page_order > ? ORDER BY page_order ASC, item_id ASC LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ssi', $courseId, $sectionId, $order);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$candidate || strtolower(trim((string) ($candidate['status'] ?? 'published'))) === 'draft') return null;
        $candidateTitle = (string) ($candidate['item_title'] ?? '');
        if (!preg_match('/\\bmodel\\s+answer|homework\\s+answers?\\b/i', $candidateTitle)) return null;
        $sourceKey = mmh_homework_relation_key($item['item_title'] ?? '');
        if ($sourceKey === '' || $sourceKey !== mmh_homework_relation_key($candidateTitle)) return null;
        $resolved = mmh_course_resource_resolve($candidate);
        if (!in_array((string) ($resolved['action'] ?? ''), ['embed', 'redirect'], true)) return null;
        $url = mmh_course_resource_safe_url($resolved['url'] ?? '');
        if ($url === null) return null;
        return [
            'url' => $url,
            'provider' => mmh_course_resource_type_for_url($url, 'external_link'),
            'resource_type' => mmh_course_resource_type_for_url($url, 'external_link'),
            'embed' => ($resolved['action'] ?? '') === 'embed',
            'release' => 'immediate',
            'title' => 'Model Answer',
            'source' => 'legacy-adjacent',
            'source_item_id' => (string) ($candidate['item_id'] ?? ''),
        ];
    }
}

if (!function_exists('mmh_homework_release_state')) {
    function mmh_homework_release_state(?array $resource, array $assignment, ?array $submission)
    {
        if (!$resource || mmh_course_resource_safe_url($resource['url'] ?? '') === null) {
            return ['available' => false, 'label' => 'Model answer not available'];
        }
        $release = strtolower(trim((string) ($resource['release'] ?? 'hidden')));
        if ($release === 'immediate') return ['available' => true, 'label' => 'Available now'];
        if ($release === 'after_submission') {
            return $submission ? ['available' => true, 'label' => 'Available after submission'] : ['available' => false, 'label' => 'Available after your submission'];
        }
        if ($release === 'after_due') {
            try {
                $due = new DateTimeImmutable((string) ($assignment['due_date'] ?? ''), new DateTimeZone('Asia/Riyadh'));
                return $due <= new DateTimeImmutable('now', new DateTimeZone('Asia/Riyadh'))
                    ? ['available' => true, 'label' => 'Available after the deadline']
                    : ['available' => false, 'label' => 'Available after the deadline'];
            } catch (Throwable $ignored) {
                return ['available' => false, 'label' => 'Available after the deadline'];
            }
        }
        return ['available' => false, 'label' => 'Hidden by your teacher'];
    }
}

if (!function_exists('mmh_homework_part')) {
    function mmh_homework_part(mysqli $conn, array $course, array $selection, array $resource, ?array $assignment, ?array $submission, $part)
    {
        $part = strtolower(trim((string) $part));
        if ($part === 'homework') {
            $slot = is_array($resource['homework_resource'] ?? null) ? $resource['homework_resource'] : ['url' => $resource['homework_url'] ?? ''];
            $url = mmh_course_resource_safe_url($slot['url'] ?? '');
            if ($url === null) return null;
            return ['url' => $url, 'resource_type' => $slot['resource_type'] ?? mmh_course_resource_type_for_url($url, 'external_link'), 'label' => 'Homework', 'icon' => 'fas fa-file-alt'];
        }
        if ($part !== 'model-answer' || !$assignment) return null;
        $slot = mmh_homework_model_answer_resource($conn, $course, $selection, $resource);
        $release = mmh_homework_release_state($slot, $assignment, $submission);
        if (!$release['available']) return ['locked' => true, 'message' => $release['label']];
        $url = mmh_course_resource_safe_url($slot['url'] ?? '');
        if ($url === null) return null;
        return ['url' => $url, 'resource_type' => $slot['resource_type'] ?? mmh_course_resource_type_for_url($url, 'external_link'), 'label' => 'Model Answer', 'icon' => 'fas fa-graduation-cap'];
    }
}

if (!function_exists('mmh_homework_render')) {
    function mmh_homework_render(mysqli $conn, $baseUrl, $userId, array $course, array $selection, $itemId, array $resource)
    {
        $item = $selection['item'];
        $assignmentId = trim((string) ($resource['assignment_id'] ?? ''));
        $assignment = mmh_homework_assignment($conn, $assignmentId, (string) $course['course_id']);
        if (!$assignment || !student_course_access_assignment_matches_item($assignment, $item)) course_resource_notice(404, 'Homework unavailable', 'This homework is no longer linked to this lesson.', $course['course_id']);
        $submissions = mmh_assignment_progress_latest_submissions($conn, (int) $userId, (string) $course['course_id']);
        $submission = $submissions[$assignmentId] ?? null;
        $status = mmh_homework_status($assignment, $submission);
        $due = mmh_homework_due($assignment['due_date'] ?? '');
        $navigation = course_resource_navigation($conn, $course, $userId, $itemId);
        $section = $selection['section_state']['section'] ?? [];
        $sectionTitle = trim((string) ($section['title'] ?? 'General')) ?: 'General';
        $title = trim((string) ($item['item_title'] ?? $assignment['assignment_title'] ?? 'Homework')) ?: 'Homework';
        $courseUrl = student_course_access_course_url($baseUrl, $course['course_id']);
        $returnUrl = student_course_access_course_url($baseUrl, $course['course_id'], $itemId, true);
        $previousUrl = !empty($navigation['previous']) ? course_resource_url($baseUrl, $course['course_id'], $navigation['previous']['item_id']) : '';
        $nextUrl = !empty($navigation['next']) ? course_resource_url($baseUrl, $course['course_id'], $navigation['next']['item_id']) : '';
        $description = trim(strip_tags((string) ($resource['description'] ?? $assignment['assignment_description'] ?? '')));
        if ($description === '' || strtolower($description) === strtolower($title)) $description = 'This homework has been prepared to help you review the lecture.';
        $homework = mmh_homework_part($conn, $course, $selection, $resource, $assignment, $submission, 'homework');
        $model = mmh_homework_model_answer_resource($conn, $course, $selection, $resource);
        $modelRelease = mmh_homework_release_state($model, $assignment, $submission);
        $modelUrl = $modelRelease['available'] ? course_resource_url($baseUrl, $course['course_id'], $itemId) . '?part=model-answer' : '';
        $homeworkUrl = $homework ? course_resource_url($baseUrl, $course['course_id'], $itemId) . '?part=homework' : '';
        $uploadAllowed = $status['class'] !== 'is-overdue';
        $submittedAt = $submission && !empty($submission['submitted_at']) ? strtotime((string) $submission['submitted_at']) : false;
        course_resource_record_open($conn, $userId, $course, (string) ($selection['section_id'] ?? ''), $itemId, $resource);
        ?>
<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= mmh_homework_escape($title) ?> | <?= mmh_homework_escape($course['course_title'] ?? 'Course') ?></title>
<script>(function(){var t='dark';try{t=localStorage.getItem('math-mastery-student-theme')||t;}catch(e){}document.documentElement.dataset.studentTheme=t==='light'?'light':'dark';document.documentElement.style.colorScheme=document.documentElement.dataset.studentTheme;}());</script>
<link rel="stylesheet" href="<?= mmh_homework_escape(rtrim((string)$baseUrl,'/').'/resources/css/fontawsome5.min.css') ?>"><link rel="stylesheet" href="<?= mmh_homework_escape(rtrim((string)$baseUrl,'/').'/resources/css/design-system.css') ?>"><link rel="stylesheet" href="<?= mmh_homework_escape(rtrim((string)$baseUrl,'/').'/resources/css/course-learning.css') ?>"></head>
<body class="course-learning-page course-homework-page"><main class="course-homework">
<header class="course-homework-header"><nav class="course-resource-viewer-breadcrumb" aria-label="Breadcrumb"><a href="<?= mmh_homework_escape($courseUrl) ?>">Course</a><span>/</span><span><?= mmh_homework_escape($sectionTitle) ?></span><span>/</span><span aria-current="page"><?= mmh_homework_escape($title) ?></span></nav><div class="course-homework-heading"><span class="course-homework-icon fas fa-clipboard-list" aria-hidden="true"></span><div><p class="course-resource-viewer-meta">Homework<?php if ($navigation['position'] && $navigation['total']): ?> <span>•</span> Lesson <?= (int)$navigation['position'] ?> of <?= (int)$navigation['total'] ?><?php endif ?></p><h1><?= mmh_homework_escape($title) ?></h1><div class="course-homework-header-meta"><span class="course-homework-status <?= mmh_homework_escape($status['class']) ?>"><span class="fas fa-circle" aria-hidden="true"></span><?= mmh_homework_escape($status['label']) ?></span><span class="course-homework-due"><span class="far fa-calendar-alt" aria-hidden="true"></span><?php if ($due['machine']): ?><time datetime="<?= mmh_homework_escape($due['machine']) ?>">Due <?= mmh_homework_escape($due['label']) ?></time><?php else: ?>No deadline<?php endif ?></span></div></div><a class="course-resource-viewer-return" href="<?= mmh_homework_escape($returnUrl) ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Return to course</a></div></header>
<section class="course-homework-panel course-homework-details"><h2>Assignment details</h2><p><?= mmh_homework_escape($description) ?></p><dl><div><dt>Submission status</dt><dd><?= mmh_homework_escape($status['detail']) ?></dd></div><div><dt>Accepted files</dt><dd>PDF, DOC, or DOCX</dd></div><?php if (is_numeric($assignment['max_score'] ?? null)): ?><div><dt>Maximum score</dt><dd><?= mmh_homework_escape(rtrim(rtrim(number_format((float)$assignment['max_score'],2,'.',''),'0'),'.')) ?></dd></div><?php endif ?></dl></section>
<section class="course-homework-resource-grid" aria-label="Homework resources"><article class="course-homework-panel course-homework-resource-card"><div class="course-homework-resource-heading"><span class="fas fa-file-alt" aria-hidden="true"></span><div><p class="course-resource-viewer-meta">Homework resource</p><h2>Homework</h2></div></div><p>Open the homework file inside the LMS whenever the provider supports it.</p><?php if ($homeworkUrl): ?><a class="course-btn course-btn-primary" href="<?= mmh_homework_escape($homeworkUrl) ?>"><span class="fas fa-eye" aria-hidden="true"></span> View / Download Homework</a><?php else: ?><p class="course-homework-resource-state"><span class="fas fa-info-circle" aria-hidden="true"></span> Homework file not available</p><?php endif ?></article><article class="course-homework-panel course-homework-resource-card<?= $modelRelease['available'] ? '' : ' is-locked' ?>"><div class="course-homework-resource-heading"><span class="fas fa-graduation-cap" aria-hidden="true"></span><div><p class="course-resource-viewer-meta">Model answer</p><h2>Model Answer</h2></div></div><?php if ($modelRelease['available'] && $modelUrl): ?><p>Use the model answer to review your method and final solution.</p><a class="course-btn course-btn-secondary" href="<?= mmh_homework_escape($modelUrl) ?>"><span class="fas fa-eye" aria-hidden="true"></span> View / Download Model Answer</a><?php else: ?><p class="course-homework-resource-state"><span class="fas fa-lock" aria-hidden="true"></span> <?= mmh_homework_escape($modelRelease['label']) ?></p><?php endif ?></article></section>
<section class="course-homework-panel course-homework-submission"><p class="course-resource-viewer-meta">Submission</p><h2><?= mmh_homework_escape($status['label']) ?></h2><?php if ($submission): ?><?php $submissionFiles = mmh_assignment_submission_files($conn, $submission); ?><p><?php foreach ($submissionFiles as $fileIndex => $submissionFile): ?><?php if ($fileIndex): ?> · <?php endif ?><a href="<?= mmh_homework_escape(rtrim((string)$baseUrl, '/') . '/' . ltrim((string) ($submissionFile['file_path'] ?? ''), '/')) ?>" target="_blank" rel="noopener"><?= mmh_homework_escape((string) ($submissionFile['original_filename'] ?? basename((string) ($submissionFile['file_path'] ?? 'Submission')))) ?></a><?php endforeach ?><?php if ($submittedAt): ?> · Submitted <?= mmh_homework_escape(date('j F Y \a\t g:i A',$submittedAt)) ?><?php endif ?><?php if (($submission['submission_source'] ?? '') === 'legacy_import'): ?> <span class="course-homework-imported-badge">Imported by Instructor</span><?php endif ?></p><?php if (trim((string)($submission['feedback'] ?? '')) !== ''): ?><p class="course-homework-feedback"><strong>Teacher feedback</strong><br><?= nl2br(mmh_homework_escape($submission['feedback'])) ?></p><?php endif ?><?php if (is_numeric($submission['grade'] ?? null)): ?><p class="course-homework-grade"><strong>Grade:</strong> <?= mmh_homework_escape($submission['grade']) ?></p><?php endif ?><?php else: ?><p>Upload your completed work before the deadline. You can replace it while submissions remain open.</p><?php endif ?><?php if ($uploadAllowed): ?><button class="course-btn course-btn-secondary" type="button" data-homework-upload><span class="fas fa-upload" aria-hidden="true"></span><?= $submission ? 'Replace submission' : 'Upload Homework' ?></button><?php endif ?></section>
<?php if ($uploadAllowed): ?><section class="course-homework-panel course-homework-upload" data-homework-upload-panel hidden><h2><?= $submission ? 'Replace submission' : 'Upload Homework' ?></h2><form data-homework-submission method="post" enctype="multipart/form-data" action="<?= mmh_homework_escape(mmh_homework_submission_endpoint($baseUrl)) ?>"><input type="hidden" name="assignment_id" value="<?= mmh_homework_escape($assignmentId) ?>"><input type="hidden" name="csrf_token" value="<?= mmh_homework_escape(student_course_csrf_token()) ?>"><label for="homework-file">Solution file <span>PDF, DOC, or DOCX</span></label><input id="homework-file" name="submission_file" type="file" accept=".pdf,.doc,.docx" required><?php if (!empty($assignment['allow_self_score'])): ?><label for="homework-score">My score<?php if (is_numeric($assignment['max_score'] ?? null)): ?> out of <?= mmh_homework_escape($assignment['max_score']) ?><?php endif ?></label><input id="homework-score" name="self_score" type="number" min="0" step="0.5"<?php if (is_numeric($assignment['max_score'] ?? null)): ?> max="<?= mmh_homework_escape($assignment['max_score']) ?>"<?php endif ?> required><?php endif ?><div class="course-homework-upload-actions"><button class="course-btn course-btn-primary" type="submit">Submit Homework</button><button class="course-btn course-btn-ghost" type="button" data-homework-upload-cancel>Cancel</button></div><p data-homework-upload-message role="status" aria-live="polite"></p></form></section><?php endif ?>
<nav class="course-resource-viewer-navigation" aria-label="Lesson navigation"><div><?php if ($previousUrl): ?><a class="course-resource-viewer-nav-link" href="<?= mmh_homework_escape($previousUrl) ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Previous resource</a><?php endif ?></div><div><?php if ($nextUrl): ?><a class="course-resource-viewer-nav-link" href="<?= mmh_homework_escape($nextUrl) ?>">Next resource <span class="fas fa-arrow-right" aria-hidden="true"></span></a><?php else: ?><a class="course-resource-viewer-nav-link" href="<?= mmh_homework_escape($courseUrl) ?>">Continue learning <span class="fas fa-arrow-right" aria-hidden="true"></span></a><?php endif ?></div></nav></main><script src="<?= mmh_homework_escape(rtrim((string)$baseUrl,'/').'/resources/js/course-homework.js') ?>" defer></script></body></html>
<?php exit;
    }
}
