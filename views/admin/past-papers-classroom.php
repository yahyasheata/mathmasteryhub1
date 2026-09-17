<?php
declare(strict_types=1);

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/PastPapers.php';
require_once 'inc/PastPaperClassroomScanner.php';

mmh_admin_require_admin();
$conn = db();
$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$syllabuses = array_values(array_filter(mmh_past_syllabuses($conn, '', true), 'mmh_classroom_is_target_syllabus'));
$status = mmh_classroom_oauth_status($base);
$token = mmh_classroom_access_token($base);
[$courses, $courseError] = $token ? mmh_classroom_list_courses($base) : [[], ''];
$preview = is_array($_SESSION['mmh_classroom_scan_preview'] ?? null) ? $_SESSION['mmh_classroom_scan_preview'] : null;
$flash = $_SESSION['mmh_classroom_flash'] ?? null;
unset($_SESSION['mmh_classroom_flash']);
$csrf = mmh_admin_csrf_token();
$selectedCourse = (string) ($_GET['course_id'] ?? ($preview['course']['id'] ?? ''));
$selectedSyllabus = (string) ($_GET['syllabus_id'] ?? '');
$cssPath = dirname(__DIR__, 2) . '/resources/css/past-papers-classroom.css';
$cssVersion = is_file($cssPath) ? (string) (filemtime($cssPath) ?: 1) : '1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Google Classroom Scanner | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
<?php include 'layouts/admin/header.php'; ?>
<link rel="stylesheet" href="<?= $escape($base . '/resources/css/past-papers-classroom.css?v=' . rawurlencode($cssVersion)) ?>">
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex"><?php include 'layouts/admin/aside.php'; ?><div class="main-content in-active"><?php include 'layouts/admin/top-nav.php'; ?>
<main class="classroom-scanner-page">
    <header class="classroom-scanner-header">
        <div><div class="classroom-scanner-eyebrow">Past Papers · Migration preview</div><h1 class="h3 mb-1">Google Classroom Scanner</h1><p class="classroom-scanner-muted mb-0">Review the approved Classroom topics, then explicitly import selected papers.</p></div>
        <a class="btn btn-outline-secondary" href="<?= $escape($base . '/admin/past-papers#drive-import') ?>">Back to Past Papers</a>
    </header>

    <?php if (is_array($flash)): ?><div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger' ?>" role="status"><?= $escape($flash['message'] ?? '') ?></div><?php endif; ?>

    <section class="classroom-scanner-card">
        <div class="d-flex justify-content-between gap-3 align-items-start flex-wrap mb-3"><div><div class="classroom-scanner-label">1 · Connect and choose</div><h2 class="h5 mb-1">Select a Classroom course</h2><p class="classroom-scanner-muted mb-0">Only the approved eight topic names will be processed.</p></div><span class="classroom-status <?= $status['available'] ? 'is-ready' : '' ?>"><?= $escape($status['label']) ?></span></div>
        <?php if (!$status['available']): ?><div class="classroom-scanner-notice mb-3"><?= $escape($status['message']) ?> <strong>BLOCKED ON GOOGLE CONFIGURATION</strong></div><?php endif; ?>
        <div class="classroom-scanner-toolbar">
            <div><label class="form-label" for="classroom-course">Classroom course</label><select id="classroom-course" class="form-control classroom-scanner-select" name="course_id" form="classroom-scan-form" <?= $token ? '' : 'disabled' ?>><option value="">Choose a Classroom course</option><?php foreach ($courses as $course): ?><option value="<?= $escape($course['id']) ?>" <?= $selectedCourse === $course['id'] ? 'selected' : '' ?>><?= $escape($course['name'] . ($course['section'] !== '' ? ' · ' . $course['section'] : '')) ?></option><?php endforeach; ?></select><?php if ($courseError): ?><small class="text-danger"><?= $escape($courseError) ?></small><?php elseif ($token && !$courses): ?><small class="classroom-scanner-muted">No Classroom courses are available to this account.</small><?php endif; ?></div>
            <div><label class="form-label" for="classroom-syllabus">Target syllabus</label><select id="classroom-syllabus" class="form-control classroom-scanner-select" name="syllabus_id" form="classroom-scan-form" required <?= $syllabuses ? '' : 'disabled' ?>><option value="">Choose a target syllabus</option><?php foreach ($syllabuses as $syllabus): ?><option value="<?= $escape($syllabus['syllabus_id']) ?>" <?= $selectedSyllabus === $syllabus['syllabus_id'] ? 'selected' : '' ?>><?= $escape($syllabus['public_title'] . ' · ' . $syllabus['syllabus_code']) ?></option><?php endforeach; ?></select><?php if (!$syllabuses): ?><small class="text-warning">Cambridge Mathematics 0580 is not configured in Past Papers settings.</small><?php endif; ?></div>
            <?php if (!$token && $status['available']): ?><a class="btn btn-primary" href="<?= $escape($base . '/admin/past-papers/classroom/connect') ?>">Connect Google account</a><?php else: ?><form id="classroom-scan-form" method="post" action="<?= $escape($base . '/admin/past-papers/classroom/scan') ?>"><input type="hidden" name="mmh_csrf_token" value="<?= $escape($csrf) ?>"><button class="btn btn-primary" type="submit" <?= $token ? '' : 'disabled' ?>>Scan approved topics</button></form><?php endif; ?>
        </div>
        <?php if ($token): ?><p class="classroom-scanner-muted mt-3 mb-0">Connected for this session only. Scanning uses read-only Classroom scopes and does not modify Classroom or Math Mastery Hub content.</p><?php endif; ?>
    </section>

    <?php if ($preview): $summary = $preview['summary'] ?? []; $statuses = $summary['candidate_statuses'] ?? ($summary['statuses'] ?? []); $importableCount = 0; foreach (($preview['candidates'] ?? []) as $candidate) if (mmh_classroom_candidate_importable($candidate) && empty($candidate['existing_paper'])) $importableCount++; ?>
    <section class="classroom-scanner-card">
        <div class="d-flex justify-content-between gap-3 align-items-start flex-wrap mb-3"><div><div class="classroom-scanner-label">2 · Preview</div><h2 class="h5 mb-1">Read-only mapping preview</h2><p class="classroom-scanner-muted mb-0">Review the proposed papers and source attachments before importing selected candidates.</p></div><span class="classroom-status is-ready">Scan is read-only</span></div>
        <div class="classroom-summary-grid mb-4"><div><strong><?= (int) ($summary['approved_topics'] ?? 0) ?> / 8</strong>Approved topics</div><div><strong><?= (int) ($summary['candidates'] ?? 0) ?> / 16</strong>Paper candidates</div><div><strong><?= (int) ($summary['resources']['question_paper'] ?? 0) ?></strong>Question papers</div><div><strong><?= (int) ($summary['resources']['model_answer'] ?? 0) ?></strong>Model answers</div><div><strong><?= (int) ($summary['resources']['video_solution'] ?? 0) ?></strong>Video solutions</div><div><strong><?= (int) ($statuses['READY'] ?? 0) ?></strong>Ready candidates</div><div><strong><?= (int) ($statuses['WARNING'] ?? 0) ?></strong>Warning candidates</div><div><strong><?= (int) ($statuses['NEEDS REVIEW'] ?? 0) ?></strong>Needs review candidates</div><div><strong><?= (int) (($summary['warning_statuses']['NEEDS REVIEW'] ?? 0) + ($summary['warning_statuses']['WARNING'] ?? 0)) ?></strong>Mapping warnings</div><div><strong><?= (int) (($preview['source_counts']['coursework_material'] ?? 0)) ?></strong>CourseWorkMaterials processed</div><div><strong><?= (int) (($preview['source_counts']['coursework'] ?? 0)) ?></strong>CourseWork processed</div><div><strong><?= (int) ($summary['ignored_topics'] ?? 0) ?></strong>Ignored topics</div></div>
        <h3 class="h6">Approved topics found</h3>
        <?php foreach (($preview['approved_topics'] ?? []) as $topic): ?><div class="classroom-topic-summary"><div><strong><?= $escape($topic['original_title'] ?? '') ?></strong><div class="classroom-scanner-muted"><?= $escape($topic['session'] . ' ' . $topic['year'] . ' · ' . $topic['variant']) ?></div></div><span class="classroom-status is-ready">Approved</span></div><?php endforeach; ?>
        <?php if (!empty($preview['ignored_topics'])): ?><details class="mt-3"><summary>Ignored Classroom topics (<?= count($preview['ignored_topics']) ?>)</summary><div class="mt-2"><?php foreach ($preview['ignored_topics'] as $topic): ?><div class="classroom-topic-summary"><span><?= $escape($topic['title']) ?></span><span class="classroom-status is-ignored">Ignored — outside approved migration scope</span></div><?php endforeach; ?></div></details><?php endif; ?>
    </section>
    <section class="classroom-scanner-card" id="import"><div class="d-flex justify-content-between gap-3 align-items-start flex-wrap mb-3"><div><h2 class="h5 mb-1">Import selected papers</h2><p class="classroom-scanner-muted mb-0">Importable candidates are preselected. Existing and blocked candidates cannot be imported.</p></div><span class="classroom-status <?= $importableCount ? 'is-ready' : '' ?>"><?= (int) $importableCount ?> importable</span></div><form method="post" action="<?= $escape($base . '/admin/past-papers/classroom/import') ?>" onsubmit="return confirm('Import the selected new Past Papers? Existing papers will not be changed.');"><input type="hidden" name="mmh_csrf_token" value="<?= $escape($csrf) ?>"><div class="classroom-preview-grid"><?php foreach (($preview['candidates'] ?? []) as $candidate): $canImport = mmh_classroom_candidate_importable($candidate) && empty($candidate['existing_paper']); ?><article class="classroom-candidate"><header><div><h3><?= $escape($candidate['topic']['session'] . ' ' . $candidate['topic']['year'] . ' · ' . $candidate['topic']['variant']) ?></h3><div class="classroom-component"><?= $escape($candidate['paper_number']) ?> · Component <?= $escape($candidate['component']) ?></div></div><div class="d-flex gap-2 align-items-center"><?php if ($canImport): ?><label class="classroom-import-check"><input type="checkbox" name="candidate_keys[]" value="<?= $escape($candidate['candidate_key'] ?? '') ?>" checked><span>Select</span></label><?php endif; ?><span class="classroom-status <?= $candidate['status'] === 'READY' ? 'is-ready' : '' ?>"><?= $escape($candidate['status']) ?></span></div></header><?php if (!empty($candidate['existing_paper'])): ?><div class="alert alert-warning py-2 px-3 mb-2">Existing paper — no changes will be made.</div><?php endif; ?><?php foreach (($candidate['resources'] ?? []) as $resource): $source = $resource['source'] ?? []; ?><div class="classroom-resource"><strong><?= $escape(ucwords(str_replace('_', ' ', (string) $resource['resource_type']))) ?></strong><small><?= $escape($source['item_title'] ?? 'Classroom item') ?><?= !empty($source['attachment_title']) ? ' · ' . $escape($source['attachment_title']) : '' ?></small></div><?php endforeach; ?><?php if (!empty($candidate['missing_resources'])): ?><div class="classroom-scanner-muted mt-2">Missing: <?= $escape(implode(', ', $candidate['missing_resources'])) ?></div><?php endif; ?><details class="mt-3"><summary>Source trace</summary><div class="classroom-scanner-muted mt-2">Topic: <?= $escape($candidate['topic']['title'] ?? '') ?><br><?php foreach (($candidate['resources'] ?? []) as $resource): $source = $resource['source'] ?? []; ?><div class="mt-2"><strong><?= $escape(ucwords(str_replace('_', ' ', (string) $resource['resource_type']))) ?></strong><br>Item: <?= $escape($source['item_title'] ?? 'Classroom item') ?><br>Type: <?= $escape($source['coursework_type'] === 'coursework_material' ? 'CourseWorkMaterial' : 'CourseWork') ?><br>Item ID: <?= $escape($source['coursework_id'] ?? '') ?><br>Attachment <?= (int) ($source['attachment_index'] ?? 0) ?>: <?= $escape($source['attachment_type'] ?? 'unknown') ?><br>Attachment title: <?= $escape($source['attachment_title'] ?? '') ?><br>Detected paper: <?= $escape(implode(', ', array_map(static fn($paper) => 'Paper ' . $paper, (array) ($source['detected_papers'] ?? [])))) ?><?php if (!empty($source['source_url'])): ?><br>Source URL: <?= $escape($source['source_url']) ?><?php endif; ?><?php if (!empty($source['drive_file_id'])): ?><br>Drive file ID: <?= $escape($source['drive_file_id']) ?><?php endif; ?><?php if (!empty($source['youtube_id'])): ?><br>YouTube ID: <?= $escape($source['youtube_id']) ?><?php endif; ?></div><?php endforeach; ?></div></details></article><?php endforeach; ?></div><button class="btn btn-primary mt-3" type="submit" <?= $importableCount ? '' : 'disabled' ?>>Import selected papers</button></form></section>
    <?php endif; ?>
</main>
</div></div>
</body></html>
