<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/PastPapers.php';
require_once 'inc/PastPaperDriveImport.php';
require_once 'inc/AcademicMetadata.php';

$username = $_SESSION['admin'];
$pageName = 'past_papers';
$subPageName = 'past_papers';
$conn = db();
mmh_past_ensure_schema($conn);
mmh_past_drive_ensure_schema($conn);

$boards = mmh_past_exam_boards($conn, false);
$syllabuses = mmh_past_syllabuses($conn, '', false);
$courses = mmh_past_courses($conn);
$students = mmh_past_students($conn, 400);
$filters = [
    'search' => $_GET['search'] ?? '',
    'exam_board_id' => $_GET['exam_board_id'] ?? '',
    'syllabus_id' => $_GET['syllabus_id'] ?? '',
    'year' => $_GET['year'] ?? '',
    'exam_session' => $_GET['exam_session'] ?? '',
    'paper_number' => $_GET['paper_number'] ?? '',
    'variant' => $_GET['variant'] ?? '',
    'status' => $_GET['status'] ?? '',
];
$papers = mmh_past_papers($conn, $filters, 50, 0);
$selectedPaperId = mmh_past_identifier($_GET['paper'] ?? '', 40);
$selectedPaper = $selectedPaperId ? mmh_past_paper($conn, $selectedPaperId) : null;
$selectedResources = $selectedPaper ? mmh_past_resources($conn, $selectedPaper['paper_id'], false) : [];
$editingResourceId = mmh_past_identifier($_GET['resource'] ?? '', 40);
$editingResource = $editingResourceId ? mmh_past_resource($conn, $editingResourceId) : null;
if (!$editingResource || !$selectedPaper || (string) $editingResource['paper_id'] !== (string) $selectedPaper['paper_id']) $editingResource = null;
$editingScope = $editingResource ? mmh_past_resource_scope_ids($conn, $editingResource['resource_id']) : ['course_ids' => [], 'student_ids' => []];
$resourceTypePreset = $editingResource['resource_type'] ?? ($_GET['resource_type'] ?? 'question_paper');
$resourceTypePreset = mmh_past_resource_type($resourceTypePreset);
$bulkCsrf = mmh_past_bulk_csrf_token();
$bulkPreview = mmh_past_bulk_session();
$bulkRows = is_array($bulkPreview['rows'] ?? null) ? $bulkPreview['rows'] : [];
$selectedCourseId = $selectedPaper['course_id'] ?? '';
$topics = $selectedCourseId ? mmh_academic_topic_list($conn, $selectedCourseId, false) : [];
$flash = mmh_past_take_flash();
$driveSources = mmh_past_drive_sources($conn);
$driveJobId = mmh_past_identifier($_GET['drive_job'] ?? '', 40);
$driveJob = $driveJobId ? mmh_past_drive_job($conn, $driveJobId) : null;
if ($driveJob) { mmh_past_drive_backfill_failure_details($conn, $driveJob['job_id']); }
$driveFilter = in_array($_GET['drive_filter'] ?? '', ['', 'create', 'update', 'skip_duplicate', 'manual_review', 'unsupported', 'error', 'created', 'failed', 'skipped', 'mapping_required', 'pending'], true) ? $_GET['drive_filter'] : '';
$drivePage = max(1, (int) ($_GET['drive_page'] ?? 1));
$driveCandidatePage = $driveJob ? mmh_past_drive_candidates_page($conn, $driveJob['job_id'], $driveFilter, $drivePage, 50) : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'pages' => 0];
$driveCandidates = $driveCandidatePage['rows'];
$driveCandidateId = mmh_past_identifier($_GET['drive_candidate'] ?? '', 40);
$driveCandidateReview = ($driveJob && $driveCandidateId) ? mmh_past_drive_candidate($conn, $driveJob['job_id'], $driveCandidateId) : null;
$driveSummary = $driveJob ? (json_decode((string) ($driveJob['summary_json'] ?? ''), true) ?: []) : [];
$driveImportState = is_array($driveSummary['import_state'] ?? null) ? $driveSummary['import_state'] : [];
$driveCandidateStats = $driveJob ? mmh_past_drive_candidate_summary($conn, $driveJob['job_id']) : ['total' => 0, 'mapped' => 0, 'eligible' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'mapping_required' => 0, 'pending' => 0];
$driveProcessedAutomatic = $driveCandidateStats['created'] + $driveCandidateStats['updated'] + $driveCandidateStats['failed'];
$driveImportProgress = [
    'eligible' => $driveCandidateStats['eligible'],
    'completed' => $driveCandidateStats['created'] + $driveCandidateStats['updated'],
    'failed' => $driveCandidateStats['failed'],
    'pending' => $driveCandidateStats['pending'],
    'manual_review' => $driveCandidateStats['mapping_required'],
    'percent' => $driveCandidateStats['eligible'] > 0 ? min(100, (int) floor(($driveProcessedAutomatic / $driveCandidateStats['eligible']) * 100)) : 100,
];
$driveReanalyzeState = is_array($driveSummary['reanalyze_state'] ?? null) ? $driveSummary['reanalyze_state'] : [];
$driveConnection = mmh_past_drive_connection();
$driveCsrf = mmh_past_drive_csrf_token();
$requestBase = rtrim((string) $baseUrl, '/') . '/admin/requests/past-papers';
$resourceBase = rtrim((string) $baseUrl, '/') . '/past-papers/resource/';

function past_admin_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function past_admin_selected($a, $b) { return (string) $a === (string) $b ? 'selected' : ''; }
function past_admin_checked($value) { return !empty($value) ? 'checked' : ''; }
function past_admin_status_badge($status) {
    $status = (string) $status;
    $class = $status === 'published' ? 'text-bg-success' : ($status === 'draft' ? 'text-bg-secondary' : 'text-bg-warning');
    return '<span class="badge ' . $class . '">' . past_admin_html(ucfirst($status ?: 'draft')) . '</span>';
}
function past_admin_drive_action_label($action) {
    return [
        'create' => 'Create', 'update' => 'Update', 'skip_duplicate' => 'Skip duplicate',
        'manual_review' => 'Manual review', 'unsupported' => 'Unsupported', 'error' => 'Error',
    ][$action] ?? 'Manual review';
}
$sessions = ['January', 'February/March', 'May/June', 'October/November', 'Custom'];
$resourceTypes = mmh_past_resource_types();
$accessLevels = [
    'public' => 'Public',
    'logged_in' => 'Logged-in Users',
    'enrolled_course' => 'Enrolled Course Students',
    'selected_courses' => 'Selected Courses',
    'selected_students' => 'Selected Students',
    'admin_only' => 'Admin Only / Hidden',
];
$unlockRules = [
    'immediate' => 'Immediately',
    'specific_datetime' => 'Specific date/time',
    'manual' => 'Manual unlock',
    'after_question_opened' => 'Future: after Question Paper is opened',
    'after_homework_submission' => 'Future: after Homework submission',
    'after_teacher_approval' => 'Future: after teacher approval',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Past Papers | <?=$site_name;?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/past-papers-admin.css">
</head>
<body class="dash ds-bg-primary">
<form method="POST" action="<?=$baseUrl?>/resources/logout" id="logout-form" class="d-none"></form>
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active" style="overflow: hidden">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <div class="col-12 px-0" style="margin-top: 55px; position: relative">
            <main class="col-12 p-3 past-papers-admin">
                <section class="past-papers-hero">
                    <div>
                        <span class="ds-caption">Past Papers Center</span>
                        <h1 class="h3 mb-1">Past Papers</h1>
                        <p class="mb-0">Organize exam-board papers, resources, and access rules without touching Course Builder lessons.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-secondary" href="#drive-import"><span class="fab fa-google-drive" aria-hidden="true"></span> Import from Google Drive</a>
                        <a class="btn btn-primary" href="#paper-form"><span class="fas fa-plus" aria-hidden="true"></span> Add paper</a>
                    </div>
                </section>

                <?php if ($flash): ?>
                    <div class="alert <?=$flash['type'] === 'success' ? 'alert-success' : 'alert-danger';?> mb-0" role="status"><?=past_admin_html($flash['message']);?></div>
                <?php endif; ?>

                <section class="past-papers-card past-papers-drive-import" id="drive-import">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
                        <div>
                            <div class="past-papers-step"><span>Drive</span><strong>Google Drive Import</strong></div>
                            <p class="mb-0">Scan a shared folder first, review detected metadata, then confirm only the files you want to import. Scanning never creates Past Paper records.</p>
                        </div>
                        <span class="past-papers-drive-connection <?= $driveConnection['available'] ? 'is-ready' : 'is-missing'; ?>">Authentication: <?=past_admin_html($driveConnection['label'] ?? 'Not configured');?></span>
                    </div>
                    <?php if (!$driveConnection['available']): ?>
                        <div class="past-papers-drive-notice" role="status"><?=past_admin_html($driveConnection['message']);?></div>
                    <?php endif; ?>
                    <form method="POST" action="<?=past_admin_html($requestBase);?>/scan-drive" class="past-papers-grid mb-4">
                        <input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>">
                        <div class="past-papers-span-6"><label class="form-label">Google Drive Folder URL</label><input class="form-control" type="url" name="folder_url" placeholder="https://drive.google.com/drive/folders/..." <?= $driveConnection['available'] ? '' : 'disabled'; ?>></div>
                        <div class="past-papers-span-4"><label class="form-label">Or configured folder</label><select class="form-control" name="source_id" <?= $driveConnection['available'] ? '' : 'disabled'; ?>><option value="">Choose a saved folder</option><?php foreach ($driveSources as $source): ?><option value="<?=past_admin_html($source['source_id']);?>"><?=past_admin_html($source['display_name'] ?: 'Drive folder');?> · <?=past_admin_html($source['last_sync_at'] ? 'last sync ' . $source['last_sync_at'] : 'not synced');?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit" <?= $driveConnection['available'] ? '' : 'disabled'; ?>>Scan folder</button></div>
                    </form>

                    <?php if ($driveSources): ?>
                        <div class="past-papers-drive-sources mb-4" aria-label="Configured Google Drive folders">
                            <?php foreach ($driveSources as $source): ?>
                                <div class="past-papers-drive-source">
                                    <div><strong><?=past_admin_html($source['display_name'] ?: 'Google Drive folder');?></strong><small>Last scan: <?=past_admin_html($source['last_scan_at'] ?: 'Never');?><?= $source['last_error'] ? ' · Needs attention' : ''; ?></small></div>
                                    <form method="POST" action="<?=past_admin_html($requestBase);?>/sync-drive"><input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>"><input type="hidden" name="source_id" value="<?=past_admin_html($source['source_id']);?>"><button class="btn btn-outline-primary btn-sm" type="submit" <?= $driveConnection['available'] ? '' : 'disabled'; ?>>Sync</button></form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($driveJob): ?>
                        <div class="past-papers-drive-summary">
                            <div><strong>Dry-run: <?=past_admin_html($driveJob['source_name'] ?: 'Google Drive folder');?></strong><small><?=past_admin_html($driveJob['job_type']);?> <?= $driveJob['status'] === 'paused' ? 'paused safely' : ($driveJob['status'] === 'completed' ? 'completed ' . past_admin_html($driveJob['completed_at'] ?: '') : past_admin_html($driveJob['status']));?></small></div>
                            <div class="past-papers-drive-counts">
                                <?php foreach (['create', 'update', 'skip_duplicate', 'manual_review', 'unsupported', 'error'] as $action): ?><a href="past-papers?drive_job=<?=past_admin_html($driveJob['job_id']);?>&drive_filter=<?=past_admin_html($action);?>#drive-import" class="past-papers-drive-count <?= $driveFilter === $action ? 'active' : ''; ?>"><strong><?=past_admin_html($driveSummary[$action] ?? 0);?></strong><span><?=past_admin_html(past_admin_drive_action_label($action));?></span></a><?php endforeach; ?>
                                <a href="past-papers?drive_job=<?=past_admin_html($driveJob['job_id']);?>#drive-import" class="past-papers-drive-count"><strong><?=past_admin_html($driveSummary['files_scanned'] ?? 0);?></strong><span>Files scanned</span></a>
                            </div>
                        </div>
                        <?php if (in_array($driveJob['status'], ['completed', 'paused'], true)): $reanalyzeTotal = (int) ($driveReanalyzeState['total'] ?? 0); $reanalyzeProcessed = (int) ($driveReanalyzeState['processed'] ?? 0); $reanalyzeComplete = !empty($driveReanalyzeState['completed']); ?>
                            <form method="POST" action="<?=past_admin_html($requestBase);?>/reanalyze-drive" class="mb-3 d-flex flex-wrap align-items-center gap-2"><input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>"><input type="hidden" name="job_id" value="<?=past_admin_html($driveJob['job_id']);?>"><input type="hidden" name="restart" value="<?= $reanalyzeComplete ? '1' : '0'; ?>"><button class="btn btn-outline-primary btn-sm" type="submit"><?= $reanalyzeComplete ? 'Re-analyze again (50 per batch)' : 'Re-analyze next 50'; ?></button><small class="past-papers-muted">Uses stored filenames and folder paths only; it does not call Google Drive or import papers.</small><?php if ($reanalyzeTotal): ?><small class="past-papers-muted">Progress: <?=past_admin_html(min($reanalyzeProcessed, $reanalyzeTotal));?> / <?=past_admin_html($reanalyzeTotal);?></small><?php endif; ?></form>
                        <?php endif; ?>
                        <?php if ($driveJob['status'] === 'paused'): ?>
                            <div class="past-papers-drive-notice d-flex flex-wrap justify-content-between align-items-center gap-2" role="status"><span>Scanned <?=past_admin_html($driveSummary['files_scanned'] ?? 0);?> files so far. Continue to finish this dry run; no Past Paper records have been created.</span><form method="POST" action="<?=past_admin_html($requestBase);?>/scan-drive"><input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>"><input type="hidden" name="resume_job_id" value="<?=past_admin_html($driveJob['job_id']);?>"><button class="btn btn-primary btn-sm" type="submit">Continue scan</button></form></div>
                        <?php endif; ?>
                        <?php if (in_array($driveJob['status'], ['completed', 'importing', 'imported'], true)): ?>
                            <section class="past-papers-drive-progress" aria-live="polite">
                                <div class="past-papers-drive-progress-heading"><div><strong>Import progress</strong><small><?= $driveImportProgress['failed'] ? 'Automatic processing finished with failures.' : ($driveImportProgress['pending'] ? 'Automatic processing in progress.' : 'Automatic processing complete.'); ?></small></div><span><?=past_admin_html($driveProcessedAutomatic);?> / <?=past_admin_html($driveImportProgress['eligible']);?> processed</span></div>
                                <div class="past-papers-drive-compact-counts">
                                    <span><strong><?=past_admin_html($driveCandidateStats['total']);?></strong>Total</span><span><strong><?=past_admin_html($driveCandidateStats['mapped']);?></strong>Mapped</span><span><strong><?=past_admin_html($driveCandidateStats['created']);?></strong>Created</span><span><strong><?=past_admin_html($driveCandidateStats['updated']);?></strong>Updated</span><span><strong><?=past_admin_html($driveCandidateStats['skipped']);?></strong>Skipped</span><span class="is-failed"><strong><?=past_admin_html($driveCandidateStats['failed']);?></strong>Failed</span><span><strong><?=past_admin_html($driveCandidateStats['mapping_required']);?></strong>Mapping required</span><span><strong><?=past_admin_html($driveCandidateStats['pending']);?></strong>Remaining</span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="Drive import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?=past_admin_html($driveImportProgress['percent']);?>"><div class="progress-bar" style="width: <?=past_admin_html($driveImportProgress['percent']);?>%"></div></div>
                                <div class="past-papers-drive-progress-actions">
                                    <form method="POST" action="<?=past_admin_html($requestBase);?>/import-drive"><input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>"><input type="hidden" name="job_id" value="<?=past_admin_html($driveJob['job_id']);?>"><input type="hidden" name="action" value="process_batch"><button class="btn btn-primary btn-sm" type="submit" <?= $driveImportProgress['pending'] > 0 ? '' : 'disabled'; ?>><?= $driveImportProgress['pending'] > 0 ? 'Continue import' : ($driveImportProgress['failed'] ? 'Import finished with failures' : 'Import complete'); ?></button></form>
                                    <?php if ($driveCandidateStats['created']): ?><form method="POST" action="<?=past_admin_html($requestBase);?>/import-drive"><input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>"><input type="hidden" name="job_id" value="<?=past_admin_html($driveJob['job_id']);?>"><input type="hidden" name="action" value="publish_created"><button class="btn btn-outline-primary btn-sm" type="submit">Publish successful imports</button></form><?php endif; ?>
                                    <?php if ($driveCandidateStats['failed']): ?><a class="btn btn-outline-secondary btn-sm" href="past-papers?drive_job=<?=past_admin_html($driveJob['job_id']);?>&drive_filter=failed#drive-import">View failed</a><?php endif; ?>
                                    <details class="past-papers-drive-details"><summary>Import details</summary><p>Each request processes at most 50 stored candidates. Failed candidates are not retried automatically: correct the individual candidate, then queue it again.</p></details>
                                </div>
                            </section>
                        <?php endif; ?>
                        <nav class="past-papers-drive-filters" aria-label="Drive candidate filters">
                            <?php foreach (['' => 'All', 'created' => 'Created', 'failed' => 'Failed', 'skipped' => 'Skipped', 'mapping_required' => 'Mapping required', 'pending' => 'Pending'] as $filterKey => $filterLabel): ?><a class="<?= $driveFilter === $filterKey ? 'active' : ''; ?>" href="past-papers?drive_job=<?=past_admin_html($driveJob['job_id']);?><?= $filterKey !== '' ? '&drive_filter=' . past_admin_html($filterKey) : ''; ?>#drive-import"><?=past_admin_html($filterLabel);?></a><?php endforeach; ?>
                        </nav>
                        <?php if ($driveCandidates): ?>
                            <div class="past-papers-drive-table-scroll"><div class="table-responsive"><table class="table table-hover align-middle past-papers-table past-papers-drive-table"><thead><tr><th>File &amp; folder path</th><th>Syllabus folder</th><th>Subject</th><th>Specification year</th><th>Exam year</th><th>Session</th><th>Document type</th><th>Component</th><th>Paper</th><th>Variant</th><th>LMS syllabus mapping</th><th>Confidence</th><th>Status</th></tr></thead><tbody>
                            <?php foreach ($driveCandidates as $candidate): $meta = mmh_past_drive_read_metadata($candidate); $recognitionSources = is_array($meta['recognition_sources'] ?? null) ? $meta['recognition_sources'] : []; $sourceSummary = []; foreach ($recognitionSources as $field => $source) { $sourceSummary[] = str_replace('_', ' ', $field) . ': ' . $source; } $confidence = in_array($candidate['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $candidate['confidence'] : 'low'; $confidenceClass = $confidence === 'high' ? 'text-bg-success' : ($confidence === 'medium' ? 'text-bg-warning' : 'text-bg-secondary'); $mappingLabel = ''; foreach ($syllabuses as $mappingSyllabus) { if (($meta['syllabus_id'] ?? '') === $mappingSyllabus['syllabus_id']) { $mappingLabel = $mappingSyllabus['public_title'] . ' · ' . $mappingSyllabus['syllabus_code']; break; } } ?>
                                <tr>
                                    <td><strong><?=past_admin_html($candidate['file_name']);?></strong><br><small class="past-papers-muted"><?=past_admin_html($candidate['relative_folder_path'] ?: $candidate['source_path']);?></small></td>
                                    <td><?=past_admin_html($meta['syllabus_folder'] ?? 'Not detected');?></td>
                                    <td><?=past_admin_html($meta['subject_name'] ?: ($meta['subject_code'] ?? 'Not detected'));?></td>
                                    <td><?=past_admin_html($meta['specification_year'] ?: '—');?></td>
                                    <td><?=past_admin_html($meta['exam_year'] ?: ($meta['year'] ?? '—'));?></td>
                                    <td><?=past_admin_html($meta['session'] ?: ($meta['exam_session'] ?? '—'));?></td>
                                    <td><?=past_admin_html(mmh_past_resource_label($meta['resource_type'] ?? 'custom', $meta['custom_type'] ?? ''));?></td>
                                    <td><?=past_admin_html($meta['component_code'] ?? '—');?></td>
                                    <td><?=past_admin_html($meta['paper_number'] ?? '—');?></td>
                                    <td><?=past_admin_html($meta['variant'] ?? '—');?></td>
                                    <td><?=past_admin_html($mappingLabel ?: ($meta['mapping_notice'] ?? 'Metadata recognized; LMS syllabus mapping required.'));?></td>
                                    <td><span class="badge <?=past_admin_html($confidenceClass);?>"><?=past_admin_html(ucfirst($confidence));?></span><br><small class="past-papers-muted"><?=past_admin_html(implode(' · ', $sourceSummary) ?: 'No recognition source recorded.');?></small></td>
                                    <td><span class="past-papers-drive-action action-<?=past_admin_html($candidate['result_status'] ?: $candidate['proposed_action']);?>"><?=past_admin_html($candidate['result_status'] ? ucfirst($candidate['result_status']) : past_admin_drive_action_label($candidate['proposed_action']));?></span><?php if (!empty($candidate['failure_code'])): ?><br><small class="past-papers-drive-failure-code"><?=past_admin_html(str_replace('_', ' ', $candidate['failure_code']));?></small><?php endif; ?><?php $candidateReason = $candidate['result_message'] ?: $candidate['warning_message']; if ($candidateReason): ?><br><small class="past-papers-muted"><?=past_admin_html($candidateReason);?></small><?php endif; ?><?php if (($candidate['proposed_action'] === 'manual_review' && empty($candidate['result_status'])) || ($candidate['result_status'] ?? '') === 'failed'): ?><br><a class="btn btn-outline-primary btn-sm mt-2" href="past-papers?drive_job=<?=past_admin_html($driveJob['job_id']);?>&amp;drive_page=<?=past_admin_html($driveCandidatePage['page']);?>&amp;drive_candidate=<?=past_admin_html($candidate['candidate_id']);?>#drive-review"><?= ($candidate['result_status'] ?? '') === 'failed' ? 'Correct and retry' : 'Review mapping'; ?></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table></div></div>
                            <?php if (($driveCandidatePage['pages'] ?? 0) > 1): $pageBase = 'past-papers?drive_job=' . rawurlencode((string) $driveJob['job_id']) . ($driveFilter ? '&drive_filter=' . rawurlencode($driveFilter) : '') . '&drive_page='; ?>
                                <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Drive candidate pages"><small class="past-papers-muted">Showing <?=past_admin_html((($driveCandidatePage['page'] - 1) * $driveCandidatePage['per_page']) + 1);?>–<?=past_admin_html(min($driveCandidatePage['total'], $driveCandidatePage['page'] * $driveCandidatePage['per_page']));?> of <?=past_admin_html($driveCandidatePage['total']);?> candidates.</small><div class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm <?= $driveCandidatePage['page'] <= 1 ? 'disabled' : ''; ?>" href="<?=past_admin_html($pageBase . max(1, $driveCandidatePage['page'] - 1));?>#drive-import">Previous</a><a class="btn btn-outline-secondary btn-sm <?= $driveCandidatePage['page'] >= $driveCandidatePage['pages'] ? 'disabled' : ''; ?>" href="<?=past_admin_html($pageBase . min($driveCandidatePage['pages'], $driveCandidatePage['page'] + 1));?>#drive-import">Next</a></div></nav>
                            <?php endif; ?>
                        <?php else: ?><div class="past-papers-empty">No candidates match this dry-run filter.</div><?php endif; ?>

                        <?php if ($driveCandidateReview): $reviewMeta = array_merge(mmh_past_drive_read_metadata($driveCandidateReview), json_decode((string) ($driveCandidateReview['correction_json'] ?? ''), true) ?: []); ?>
                            <section class="past-papers-card mt-3" id="drive-review">
                                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-3"><div><h3 class="h6 mb-1">Review one candidate</h3><p class="mb-0 past-papers-muted">This individual correction is saved server-side, then the candidate joins the next 50-item batch.</p></div><strong><?=past_admin_html($driveCandidateReview['file_name']);?></strong></div>
                                <form method="POST" action="<?=past_admin_html($requestBase);?>/import-drive" class="past-papers-grid">
                                    <input type="hidden" name="csrf_token" value="<?=past_admin_html($driveCsrf);?>"><input type="hidden" name="job_id" value="<?=past_admin_html($driveJob['job_id']);?>"><input type="hidden" name="candidate_id" value="<?=past_admin_html($driveCandidateReview['candidate_id']);?>"><input type="hidden" name="action" value="save_correction">
                                    <div class="past-papers-span-3"><label class="form-label">Exam Board</label><select class="form-control" name="exam_board_id" required><option value="">Choose board</option><?php foreach ($boards as $board): ?><option value="<?=past_admin_html($board['board_id']);?>" <?=past_admin_selected($reviewMeta['exam_board_id'] ?? '', $board['board_id']);?>><?=past_admin_html($board['name']);?></option><?php endforeach; ?></select></div>
                                    <div class="past-papers-span-3"><label class="form-label">Syllabus</label><select class="form-control" name="syllabus_id" required><option value="">Choose syllabus</option><?php foreach ($syllabuses as $syllabus): ?><option value="<?=past_admin_html($syllabus['syllabus_id']);?>" <?=past_admin_selected($reviewMeta['syllabus_id'] ?? '', $syllabus['syllabus_id']);?>><?=past_admin_html($syllabus['public_title'] . ' · ' . $syllabus['syllabus_code']);?></option><?php endforeach; ?></select></div>
                                    <div class="past-papers-span-2"><label class="form-label">Exam year</label><input class="form-control" type="number" min="1900" max="2100" name="year" value="<?=past_admin_html($reviewMeta['year'] ?? '');?>" required></div>
                                    <div class="past-papers-span-2"><label class="form-label">Session</label><select class="form-control" name="exam_session"><?php foreach ($sessions as $session): ?><option value="<?=past_admin_html($session);?>" <?=past_admin_selected($reviewMeta['exam_session'] ?? '', $session);?>><?=past_admin_html($session);?></option><?php endforeach; ?></select></div>
                                    <div class="past-papers-span-2"><label class="form-label">Status</label><select class="form-control" name="publish_state"><option value="published" <?=past_admin_selected($reviewMeta['publish_state'] ?? 'published', 'published');?>>Published</option><option value="draft">Draft</option></select></div>
                                    <div class="past-papers-span-3"><label class="form-label">Paper</label><input class="form-control" name="paper_number" value="<?=past_admin_html($reviewMeta['paper_number'] ?? '');?>" required></div>
                                    <div class="past-papers-span-3"><label class="form-label">Variant / component</label><input class="form-control" name="variant" value="<?=past_admin_html($reviewMeta['variant'] ?? '');?>" required></div>
                                    <div class="past-papers-span-3"><label class="form-label">Document type</label><select class="form-control" name="resource_type"><?php foreach ($resourceTypes as $type): ?><option value="<?=past_admin_html($type);?>" <?=past_admin_selected($reviewMeta['resource_type'] ?? '', $type);?>><?=past_admin_html(mmh_past_resource_label($type));?></option><?php endforeach; ?></select></div>
                                    <div class="past-papers-span-3"><label class="form-label">Tier</label><input class="form-control" name="tier" value="<?=past_admin_html($reviewMeta['tier'] ?? '');?>"></div>
                                    <div class="past-papers-span-12"><button class="btn btn-primary" type="submit">Save mapping and queue candidate</button></div>
                                </form>
                            </section>
                        <?php endif; ?>

                    <?php endif; ?>
                </section>

                <section class="past-papers-card">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-end mb-3">
                        <div>
                            <h2 class="h5 mb-1">Search and filters</h2>
                            <p class="mb-0">Find papers by board, syllabus, year, session, component, and publication state.</p>
                        </div>
                    </div>
                    <form method="GET" class="past-papers-filter-bar">
                        <input class="form-control" name="search" value="<?=past_admin_html($filters['search']);?>" placeholder="Search papers">
                        <select class="form-control" name="exam_board_id"><option value="">All boards</option><?php foreach ($boards as $board): ?><option value="<?=past_admin_html($board['board_id']);?>" <?=past_admin_selected($filters['exam_board_id'], $board['board_id']);?>><?=past_admin_html($board['name']);?></option><?php endforeach; ?></select>
                        <select class="form-control" name="syllabus_id"><option value="">All syllabuses</option><?php foreach ($syllabuses as $syllabus): ?><option value="<?=past_admin_html($syllabus['syllabus_id']);?>" <?=past_admin_selected($filters['syllabus_id'], $syllabus['syllabus_id']);?>><?=past_admin_html($syllabus['public_title']);?> · <?=past_admin_html($syllabus['syllabus_code']);?></option><?php endforeach; ?></select>
                        <input class="form-control" type="number" name="year" value="<?=past_admin_html($filters['year']);?>" placeholder="Year">
                        <select class="form-control" name="exam_session"><option value="">All sessions</option><?php foreach ($sessions as $session): ?><option value="<?=past_admin_html($session);?>" <?=past_admin_selected($filters['exam_session'], $session);?>><?=past_admin_html($session);?></option><?php endforeach; ?></select>
                        <select class="form-control" name="status"><option value="">Any status</option><option value="published" <?=past_admin_selected($filters['status'], 'published');?>>Published</option><option value="draft" <?=past_admin_selected($filters['status'], 'draft');?>>Draft</option></select>
                        <input class="form-control" name="paper_number" value="<?=past_admin_html($filters['paper_number']);?>" placeholder="Paper / component">
                        <input class="form-control" name="variant" value="<?=past_admin_html($filters['variant']);?>" placeholder="Variant">
                        <button class="btn btn-outline-primary" type="submit">Apply filters</button>
                        <a class="btn btn-outline-secondary" href="past-papers">Reset</a>
                    </form>
                </section>

                <div class="past-papers-grid">
                    <section class="past-papers-card past-papers-span-6">
                        <div class="past-papers-step"><span>1</span><strong>Exam Board</strong></div>
                        <form method="POST" action="<?=past_admin_html($requestBase);?>/save-board" class="row g-3">
                            <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" placeholder="Cambridge" required></div>
                            <div class="col-md-3"><label class="form-label">Code</label><input class="form-control" name="code" placeholder="CIE"></div>
                            <div class="col-md-3"><label class="form-label">Sort</label><input class="form-control" type="number" min="0" name="sort_order" value="0"></div>
                            <div class="col-md-8"><label class="form-label">Description</label><input class="form-control" name="description"></div>
                            <div class="col-md-4"><label class="form-label">Status</label><select class="form-control" name="status"><option value="published">Active</option><option value="inactive">Inactive</option></select></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">Save Exam Board</button></div>
                        </form>
                    </section>

                    <section class="past-papers-card past-papers-span-6">
                        <div class="past-papers-step"><span>2</span><strong>Syllabus / Subject</strong></div>
                        <form method="POST" action="<?=past_admin_html($requestBase);?>/save-syllabus" class="row g-3">
                            <div class="col-md-6"><label class="form-label">Exam Board</label><select class="form-control" name="exam_board_id" required><option value="">Choose board</option><?php foreach ($boards as $board): ?><option value="<?=past_admin_html($board['board_id']);?>"><?=past_admin_html($board['name']);?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Syllabus Code</label><input class="form-control" name="syllabus_code" placeholder="0580" required></div>
                            <div class="col-md-6"><label class="form-label">Public Title</label><input class="form-control" name="public_title" placeholder="Mathematics 0580" required></div>
                            <div class="col-md-6"><label class="form-label">Internal Title</label><input class="form-control" name="internal_title"></div>
                            <div class="col-md-6"><label class="form-label">Linked LMS Course</label><select class="form-control" name="course_id"><option value="">No linked course</option><?php foreach ($courses as $course): ?><option value="<?=past_admin_html($course['course_id']);?>"><?=past_admin_html($course['course_title']);?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Thumbnail / Cover Path</label><input class="form-control" name="thumbnail_path" placeholder="uploads/... or existing asset path"></div>
                            <div class="col-md-3"><label class="form-label">Sort</label><input class="form-control" type="number" min="0" name="sort_order" value="0"></div>
                            <div class="col-md-3"><label class="form-label">Status</label><select class="form-control" name="status"><option value="published">Active</option><option value="inactive">Inactive</option></select></div>
                            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">Save Syllabus</button></div>
                        </form>
                    </section>
                </div>

                <section class="past-papers-card" id="paper-form">
                    <div class="past-papers-step"><span>3</span><strong><?= $selectedPaper ? 'Edit Paper Information' : 'Add Paper Information'; ?></strong></div>
                    <form method="POST" action="<?=past_admin_html($requestBase);?>/save-paper" class="past-papers-grid">
                        <input type="hidden" name="paper_id" value="<?=past_admin_html($selectedPaper['paper_id'] ?? '');?>">
                        <div class="past-papers-span-3"><label class="form-label">Exam Board</label><select class="form-control" name="exam_board_id" required><option value="">Choose board</option><?php foreach ($boards as $board): ?><option value="<?=past_admin_html($board['board_id']);?>" <?=past_admin_selected($selectedPaper['exam_board_id'] ?? '', $board['board_id']);?>><?=past_admin_html($board['name']);?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-3"><label class="form-label">Syllabus</label><select class="form-control" name="syllabus_id" required><option value="">Choose syllabus</option><?php foreach ($syllabuses as $syllabus): ?><option value="<?=past_admin_html($syllabus['syllabus_id']);?>" <?=past_admin_selected($selectedPaper['syllabus_id'] ?? '', $syllabus['syllabus_id']);?>><?=past_admin_html($syllabus['public_title']);?> · <?=past_admin_html($syllabus['syllabus_code']);?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-3"><label class="form-label">Linked Course Override</label><select class="form-control" name="course_id"><option value="">Use syllabus linked course</option><?php foreach ($courses as $course): ?><option value="<?=past_admin_html($course['course_id']);?>" <?=past_admin_selected($selectedPaper['course_id'] ?? '', $course['course_id']);?>><?=past_admin_html($course['course_title']);?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-3"><label class="form-label">Status</label><select class="form-control" name="status"><option value="draft" <?=past_admin_selected($selectedPaper['status'] ?? 'draft', 'draft');?>>Draft</option><option value="published" <?=past_admin_selected($selectedPaper['status'] ?? '', 'published');?>>Published</option></select></div>
                        <div class="past-papers-span-3"><label class="form-label">Year</label><input class="form-control" type="number" min="1900" max="2100" name="year" value="<?=past_admin_html($selectedPaper['year'] ?? date('Y'));?>" required></div>
                        <div class="past-papers-span-3"><label class="form-label">Session</label><select class="form-control" name="exam_session"><?php foreach ($sessions as $session): ?><option value="<?=past_admin_html($session);?>" <?=past_admin_selected($selectedPaper['exam_session'] ?? 'May/June', $session);?>><?=past_admin_html($session);?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-3"><label class="form-label">Custom Session</label><input class="form-control" name="custom_session" value="<?=past_admin_html($selectedPaper['custom_session'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Paper / Component</label><input class="form-control" name="paper_number" placeholder="Paper 2 or WMA11/01" value="<?=past_admin_html($selectedPaper['paper_number'] ?? '');?>" required></div>
                        <div class="past-papers-span-3"><label class="form-label">Variant / Component Code</label><input class="form-control" name="variant" placeholder="22, 1H, or Custom" value="<?=past_admin_html($selectedPaper['variant'] ?? '');?>" required></div>
                        <div class="past-papers-span-3"><label class="form-label">Qualification Level</label><input class="form-control" name="qualification_level" value="<?=past_admin_html($selectedPaper['qualification_level'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Tier</label><input class="form-control" name="tier" placeholder="Foundation / Higher" value="<?=past_admin_html($selectedPaper['tier'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Calculator Mode</label><input class="form-control" name="calculator_mode" value="<?=past_admin_html($selectedPaper['calculator_mode'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Maximum Marks</label><input class="form-control" type="number" min="0" name="maximum_marks" value="<?=past_admin_html($selectedPaper['maximum_marks'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Duration Minutes</label><input class="form-control" type="number" min="0" name="duration_minutes" value="<?=past_admin_html($selectedPaper['duration_minutes'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Paper Date</label><input class="form-control" type="date" name="paper_date" value="<?=past_admin_html($selectedPaper['paper_date'] ?? '');?>"></div>
                        <div class="past-papers-span-6"><label class="form-label">Short Title</label><input class="form-control" name="short_title" value="<?=past_admin_html($selectedPaper['short_title'] ?? '');?>"></div>
                        <div class="past-papers-span-3"><label class="form-label">Primary Topic</label><select class="form-control" name="primary_topic_id"><option value="">None</option><?php foreach ($topics as $topic): ?><option value="<?=past_admin_html($topic['id']);?>" <?=past_admin_selected($selectedPaper['primary_topic_id'] ?? '', $topic['id']);?>><?=past_admin_html($topic['title']);?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-6"><label class="form-label">Additional Topics</label><select class="form-control" name="additional_topic_ids[]" multiple size="4"><?php $selectedAdditionalTopics = json_decode((string)($selectedPaper['additional_topic_ids'] ?? '[]'), true) ?: []; foreach ($topics as $topic): ?><option value="<?=past_admin_html($topic['id']);?>" <?=in_array((int)$topic['id'], array_map('intval', $selectedAdditionalTopics), true) ? 'selected' : '';?>><?=past_admin_html($topic['title']);?></option><?php endforeach; ?></select></div>
                        <div class="past-papers-span-3"><label class="form-label">Sort</label><input class="form-control" type="number" min="0" name="sort_order" value="<?=past_admin_html($selectedPaper['sort_order'] ?? 0);?>"></div>
                        <div class="past-papers-span-12"><label class="form-label">Keywords</label><input class="form-control" name="keywords" value="<?=past_admin_html($selectedPaper['keywords'] ?? '');?>"></div>
                        <div class="past-papers-span-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?=past_admin_html($selectedPaper['description'] ?? '');?></textarea></div>
                        <div class="past-papers-span-12 d-flex gap-2 flex-wrap"><button class="btn btn-primary" type="submit" name="status" value="published">Save and Publish</button><button class="btn btn-outline-secondary" type="submit">Save Draft</button></div>
                    </form>
                </section>

                <section class="past-papers-card" id="resources">
                    <div class="past-papers-step"><span>4</span><strong>Resources and Access</strong></div>
                    <?php if (!$selectedPaper): ?>
                        <div class="past-papers-empty">Save or select a Past Paper before attaching resources.</div>
                    <?php else: ?>
                        <div class="mb-4">
                            <h3 class="h5 mb-1"><?=past_admin_html($selectedPaper['short_title'] ?: ($selectedPaper['year'] . ' ' . $selectedPaper['exam_session'] . ' ' . $selectedPaper['paper_number'] . ' ' . $selectedPaper['variant']));?></h3>
                            <p class="mb-0">Attach resources once; set access individually for each resource.</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mb-3"><a class="btn btn-outline-primary btn-sm" href="past-papers?paper=<?=past_admin_html($selectedPaper['paper_id']);?>&resource_type=model_answer#resources">Add Model Answer</a><a class="btn btn-outline-primary btn-sm" href="past-papers?paper=<?=past_admin_html($selectedPaper['paper_id']);?>&resource_type=video_solution#resources">Add Video Solution</a><?php if ($editingResource): ?><a class="btn btn-outline-secondary btn-sm" href="past-papers?paper=<?=past_admin_html($selectedPaper['paper_id']);?>#resources">Cancel editing</a><?php endif; ?></div>
                        <form method="POST" action="<?=past_admin_html($requestBase);?>/save-resource" enctype="multipart/form-data" class="past-papers-grid mb-4">
                            <input type="hidden" name="paper_id" value="<?=past_admin_html($selectedPaper['paper_id']);?>"><input type="hidden" name="resource_id" value="<?=past_admin_html($editingResource['resource_id'] ?? '');?>">
                            <div class="past-papers-span-3"><label class="form-label">Resource Type</label><select class="form-control" name="resource_type"><?php foreach ($resourceTypes as $type): ?><option value="<?=past_admin_html($type);?>" <?=past_admin_selected($resourceTypePreset, $type);?>><?=past_admin_html(mmh_past_resource_label($type));?></option><?php endforeach; ?></select></div>
                            <div class="past-papers-span-3"><label class="form-label">Custom Type</label><input class="form-control" name="custom_type" value="<?=past_admin_html($editingResource['custom_type'] ?? '');?>" placeholder="For custom resources"></div>
                            <div class="past-papers-span-6"><label class="form-label">Display Title</label><input class="form-control" name="display_title" value="<?=past_admin_html($editingResource['display_title'] ?? '');?>" placeholder="Question Paper"></div>
                            <div class="past-papers-span-3"><label class="form-label">Storage</label><select class="form-control" name="storage_type"><option value="file" <?=past_admin_selected($editingResource['storage_type'] ?? 'file', 'file');?>>File upload</option><option value="url" <?=past_admin_selected($editingResource['storage_type'] ?? '', 'url');?>>External URL / Drive / video</option></select></div>
                            <div class="past-papers-span-4"><label class="form-label">Upload PDF/Image</label><input class="form-control" type="file" name="resource_file" accept=".pdf,.jpg,.jpeg,.png,.webp"><small class="past-papers-muted">Leave empty to keep the current file.</small></div>
                            <div class="past-papers-span-5"><label class="form-label">External URL</label><input class="form-control" type="url" name="external_url" value="<?=past_admin_html($editingResource['external_url'] ?? '');?>" placeholder="https:// YouTube, Vimeo, Drive, or approved provider"></div>
                            <div class="past-papers-span-3"><label class="form-label">Google Drive File ID <small>(optional)</small></label><input class="form-control" name="drive_file_id" value="<?=past_admin_html($editingResource['drive_file_id'] ?? '');?>" placeholder="Drive file ID"></div>
                            <div class="past-papers-span-3"><label class="form-label">Access Level</label><select class="form-control" name="access_level"><?php foreach ($accessLevels as $value => $label): ?><option value="<?=past_admin_html($value);?>" <?=past_admin_selected($editingResource['access_level'] ?? mmh_past_default_access($resourceTypePreset), $value);?>><?=past_admin_html($label);?></option><?php endforeach; ?></select></div>
                            <div class="past-papers-span-3"><label class="form-label">Unlock Rule</label><select class="form-control" name="unlock_rule"><?php foreach ($unlockRules as $value => $label): ?><option value="<?=past_admin_html($value);?>" <?=str_starts_with($value, 'after_') ? 'disabled' : '';?> <?=past_admin_selected($editingResource['unlock_rule'] ?? 'immediate', $value);?>><?=past_admin_html($label);?></option><?php endforeach; ?></select></div>
                            <div class="past-papers-span-3"><label class="form-label">Unlock At</label><input class="form-control" type="datetime-local" name="unlock_at" value="<?=past_admin_html(!empty($editingResource['unlock_at']) ? date('Y-m-d\TH:i', strtotime($editingResource['unlock_at'])) : '');?>"></div>
                            <div class="past-papers-span-3"><label class="form-label">Status</label><select class="form-control" name="status"><option value="draft" <?=past_admin_selected($editingResource['status'] ?? 'draft', 'draft');?>>Draft</option><option value="published" <?=past_admin_selected($editingResource['status'] ?? '', 'published');?>>Published</option></select></div>
                            <div class="past-papers-span-6"><label class="form-label">Selected Courses</label><select class="form-control" name="selected_course_ids[]" multiple size="4"><?php foreach ($courses as $course): ?><option value="<?=past_admin_html($course['course_id']);?>" <?=in_array((string)$course['course_id'], $editingScope['course_ids'], true) ? 'selected' : '';?>><?=past_admin_html($course['course_title']);?></option><?php endforeach; ?></select></div>
                            <div class="past-papers-span-6"><label class="form-label">Selected Students</label><select class="form-control" name="selected_student_ids[]" multiple size="4"><?php foreach ($students as $student): ?><option value="<?=past_admin_html($student['user_id']);?>" <?=in_array((string)$student['user_id'], $editingScope['student_ids'], true) ? 'selected' : '';?>><?=past_admin_html($student['full_name'] ?: $student['username']);?> · <?=past_admin_html($student['username']);?></option><?php endforeach; ?></select></div>
                            <div class="past-papers-span-3"><label class="form-label">Sort</label><input class="form-control" type="number" name="sort_order" min="0" value="<?=past_admin_html($editingResource['sort_order'] ?? 0);?>"></div>
                            <div class="past-papers-span-3"><label class="form-label">Manual Unlock</label><select class="form-control" name="manual_unlocked"><option value="1" <?=past_admin_selected((string)($editingResource['manual_unlocked'] ?? 1), '1');?>>Unlocked</option><option value="0" <?=past_admin_selected((string)($editingResource['manual_unlocked'] ?? 1), '0');?>>Locked</option></select></div>
                            <div class="past-papers-span-3 pt-4"><label class="me-3"><input type="checkbox" name="download_allowed" value="1" <?=past_admin_checked($editingResource['download_allowed'] ?? 1);?>> Download</label><label><input type="checkbox" name="preview_allowed" value="1" <?=past_admin_checked($editingResource['preview_allowed'] ?? 1);?>> Preview</label></div>
                            <div class="past-papers-span-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"><?=past_admin_html($editingResource['description'] ?? '');?></textarea></div>
                            <div class="past-papers-span-12"><button class="btn btn-primary" type="submit"><?= $editingResource ? 'Update Resource' : 'Save Resource'; ?></button></div>
                        </form>
                        <?php if (!$selectedResources): ?>
                            <div class="past-papers-empty">No resources attached yet.</div>
                        <?php else: foreach ($selectedResources as $resource): ?>
                            <article class="past-papers-resource-row">
                                <div>
                                    <strong><?=past_admin_html($resource['display_title']);?></strong>
                                    <div class="past-papers-muted small"><?=past_admin_html(mmh_past_resource_label($resource['resource_type'], $resource['custom_type']));?> · <?=past_admin_status_badge($resource['status']);?> <span class="past-papers-access-badge"><?=past_admin_html($accessLevels[$resource['access_level']] ?? $resource['access_level']);?></span><?php if (($resource['drive_source_status'] ?? 'available') === 'missing'): ?> <span class="past-papers-drive-action action-manual_review">Source missing</span><?php endif; ?></div>
                                </div>
                                <div class="d-flex gap-2 flex-wrap justify-content-end">
                                    <a class="btn btn-outline-primary btn-sm" href="<?=past_admin_html($resourceBase . rawurlencode($resource['resource_id']));?>" target="_blank" rel="noopener">Preview access</a>
                                    <a class="btn btn-outline-secondary btn-sm" href="past-papers?paper=<?=past_admin_html($selectedPaper['paper_id']);?>&resource=<?=past_admin_html($resource['resource_id']);?>#resources">Edit</a>
                                    <form method="POST" action="<?=past_admin_html($requestBase);?>/delete-resource" onsubmit="return confirm('Remove this resource link? Uploaded files are left on disk for safety.');"><input type="hidden" name="paper_id" value="<?=past_admin_html($selectedPaper['paper_id']);?>"><input type="hidden" name="resource_id" value="<?=past_admin_html($resource['resource_id']);?>"><button class="btn btn-outline-danger btn-sm" type="submit">Remove / unlink</button></form>
                                </div>
                            </article>
                        <?php endforeach; endif; ?>
                    <?php endif; ?>
                </section>

                <section class="past-papers-card past-papers-bulk-links" id="bulk-links">
                    <div class="past-papers-step"><span>5</span><strong>Bulk Model Answers &amp; Video Solutions</strong></div>
                    <p class="past-papers-muted">Paste up to 200 CSV rows for preview. Exact Board, syllabus code, year, session, paper/component, and variant matching prevents cross-paper attachment. Imports run in batches of 50.</p>
                    <form method="POST" action="<?=past_admin_html($requestBase);?>/bulk-preview" class="past-papers-grid"><input type="hidden" name="csrf_token" value="<?=past_admin_html($bulkCsrf);?>"><div class="past-papers-span-12"><label class="form-label">CSV</label><textarea class="form-control" name="bulk_csv" rows="6" placeholder="board,syllabus_code,exam_year,session,component_code,paper_label,variant,resource_type,title,url,access_level,status&#10;Edexcel,4MA1,2025,May/June,Paper 1H,,1H,video_solution,Full walkthrough,https://www.youtube.com/watch?v=...,enrolled_course,published"></textarea><small class="past-papers-muted">Supported resource types: <code>model_answer</code>, <code>video_solution</code>. URLs must be HTTPS.</small></div><div class="past-papers-span-12"><button class="btn btn-outline-primary" type="submit">Preview CSV matches</button></div></form>
                    <?php if ($bulkRows): $readyIndexes = []; foreach ($bulkRows as $index => $row) if (($row['status'] ?? '') === 'ready') $readyIndexes[] = $index; ?>
                        <div class="past-papers-drive-table-scroll mt-3"><table class="table table-sm past-papers-drive-table"><thead><tr><th>Line</th><th>Target paper</th><th>Type</th><th>Title</th><th>Result</th></tr></thead><tbody><?php foreach ($bulkRows as $row): ?><tr><td><?=past_admin_html($row['line'] ?? '');?></td><td><?=past_admin_html($row['paper_label'] ?: '—');?></td><td><?=past_admin_html(mmh_past_resource_label($row['type'] ?? 'custom'));?></td><td><?=past_admin_html($row['data']['title'] ?? '');?></td><td><?=past_admin_html($row['message'] ?? '');?></td></tr><?php endforeach; ?></tbody></table></div>
                        <?php if ($readyIndexes): ?><form method="POST" action="<?=past_admin_html($requestBase);?>/bulk-import" class="d-flex gap-2 flex-wrap mt-3"><input type="hidden" name="csrf_token" value="<?=past_admin_html($bulkCsrf);?>"><?php foreach (array_slice($readyIndexes, 0, 50) as $index): ?><input type="hidden" name="row_indexes[]" value="<?=past_admin_html($index);?>"><?php endforeach; ?><button class="btn btn-primary" type="submit">Import first <?=count(array_slice($readyIndexes, 0, 50));?> ready rows</button><?php if (count($readyIndexes) > 50): ?><span class="past-papers-muted align-self-center">More than 50 rows are ready; import this batch, then submit the next preview batch.</span><?php endif; ?></form><?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="past-papers-card">
                    <div class="past-papers-step"><span>6</span><strong>Review Papers</strong></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle past-papers-table">
                            <thead><tr><th>Paper</th><th>Classification</th><th>Resources</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if (!$papers): ?>
                                <tr><td colspan="5"><div class="past-papers-empty">No Past Papers match the current filters.</div></td></tr>
                            <?php else: foreach ($papers as $paper): ?>
                                <tr>
                                    <td><strong><?=past_admin_html($paper['short_title'] ?: ($paper['year'] . ' ' . $paper['paper_number'] . ' ' . $paper['variant']));?></strong><br><small class="past-papers-muted"><?=past_admin_html($paper['board_name']);?> · <?=past_admin_html($paper['syllabus_title']);?></small></td>
                                    <td><?=past_admin_html($paper['year']);?> · <?=past_admin_html($paper['exam_session']);?><br><small class="past-papers-muted"><?=past_admin_html($paper['paper_number']);?> / <?=past_admin_html($paper['variant']);?></small></td>
                                    <td><?=past_admin_html($paper['resource_count']);?></td>
                                    <td><?=past_admin_status_badge($paper['status']);?></td>
                                    <td class="d-flex gap-2 flex-wrap">
                                        <a class="btn btn-outline-primary btn-sm" href="past-papers?paper=<?=past_admin_html($paper['paper_id']);?>#paper-form">Edit</a>
                                        <form method="POST" action="<?=past_admin_html($requestBase);?>/duplicate-paper"><input type="hidden" name="paper_id" value="<?=past_admin_html($paper['paper_id']);?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Duplicate</button></form>
                                        <form method="POST" action="<?=past_admin_html($requestBase);?>/status-paper"><input type="hidden" name="paper_id" value="<?=past_admin_html($paper['paper_id']);?>"><input type="hidden" name="status" value="<?=$paper['status'] === 'published' ? 'draft' : 'published';?>"><button class="btn btn-outline-secondary btn-sm" type="submit"><?=$paper['status'] === 'published' ? 'Unpublish' : 'Publish';?></button></form>
                                        <form method="POST" action="<?=past_admin_html($requestBase);?>/delete-paper" onsubmit="return confirm('Delete this Past Paper and its resource records? Uploaded files are left on disk for safety.');"><input type="hidden" name="paper_id" value="<?=past_admin_html($paper['paper_id']);?>"><button class="btn btn-outline-danger btn-sm" type="submit">Delete</button></form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    </div>
</div>
</body>
</html>
