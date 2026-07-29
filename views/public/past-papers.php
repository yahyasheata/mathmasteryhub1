<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/PastPapers.php';
require_once 'views/partials/past-papers-list.php';

$conn = db();
mmh_past_ensure_schema($conn);
$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
$pageName = 'past_papers';
$actionUrl = rtrim((string)$baseUrl, '/') . '/past-papers';
$filters = mmh_past_frontend_filters($_GET);
$navigation = mmh_past_archive_navigation($conn, $filters);
$filters['exam_board_id'] = $navigation['selected_board_id'];
$filters['syllabus_id'] = $navigation['selected_syllabus_id'];
$filters['paper_group'] = $navigation['selected_paper_group'];
$page = max(1, (int)($_GET['page'] ?? 1));
$options = mmh_past_filter_options($conn);
$listing = mmh_past_frontend_listing($conn, $filters, $page, 50);
$papers = $listing['rows'];
$resourcesByPaper = mmh_past_listing_resources_for_papers($conn, array_column($papers, 'paper_id'), mmh_past_current_student_id($conn) ?? 0);
$activeFilterKeys = ['exam_board_id', 'syllabus_id', 'paper_group', 'search', 'year', 'exam_session', 'resource_type'];
$activeFilterCount = count(array_filter($activeFilterKeys, fn($key) => ($filters[$key] ?? '') !== ''));
$selectedBoard = null;
foreach ($navigation['boards'] as $board) if ($board['board_id'] === $filters['exam_board_id']) { $selectedBoard = $board; break; }
$selectedSyllabus = null;
foreach ($navigation['syllabuses'] as $syllabus) if ($syllabus['syllabus_id'] === $filters['syllabus_id']) { $selectedSyllabus = $syllabus; break; }
$selectedPaper = null;
foreach ($navigation['paper_groups'] as $paperGroup) if ($paperGroup['key'] === $filters['paper_group']) { $selectedPaper = $paperGroup; break; }
$rangeStart = $listing['total'] ? $listing['offset'] + 1 : 0;
$rangeEnd = min($listing['total'], $listing['offset'] + count($papers));
$archiveUrl = function (array $changes = []) use ($actionUrl, $filters) {
    $query = array_merge($filters, $changes);
    $query['page'] = 1;
    $query = array_filter($query, fn($value) => $value !== '' && $value !== null);
    return rtrim((string)$actionUrl, '/') . ($query ? '?' . http_build_query($query) : '');
};
$paginationUrl = function ($targetPage) use ($actionUrl, $filters) { return pastpapers_listing_query_url($actionUrl, $filters, $targetPage); };
$cssVersion = @filemtime('resources/css/past-papers-frontend.css') ?: time();
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Past Papers | <?=pastpapers_html($site_name)?></title>
    <link rel="stylesheet" href="<?=pastpapers_html(rtrim((string)$baseUrl, '/'))?>/resources/css/design-system.css" data-design-system="mathhub">
    <link rel="stylesheet" href="<?=pastpapers_html(rtrim((string)$baseUrl, '/'))?>/resources/build/assets/app-38448552.css">
    <link rel="stylesheet" href="<?=pastpapers_html(rtrim((string)$baseUrl, '/'))?>/resources/css/fontawsome5.min.css">
    <link rel="stylesheet" href="<?=pastpapers_html(rtrim((string)$baseUrl, '/'))?>/resources/css/past-papers-frontend.css?v=<?=pastpapers_html($cssVersion)?>">
</head>
<body class="ds-bg-primary ds-text-primary past-papers-public-page">
<!-- MMH_NEW_PAST_PAPERS_ARCHIVE_V3 -->
<?php include 'views/public/layouts/aside.php'; ?>
<main class="past-papers-center">
    <div class="exam-archive-page">
        <header class="exam-archive-header">
            <div class="exam-archive-shell">
                <div>
                    <p class="exam-archive-eyebrow"><span class="fas fa-layer-group" aria-hidden="true"></span> Examination archive</p>
                    <h1>Past Papers</h1>
                    <p><?=pastpapers_html(($selectedBoard['name'] ?? 'Exam Board') . ' · ' . ($selectedSyllabus['public_title'] ?? 'Syllabus') . (!empty($selectedSyllabus['syllabus_code']) ? ' (' . $selectedSyllabus['syllabus_code'] . ')' : ''))?></p>
                </div>
                <p class="exam-archive-total"><strong><?=pastpapers_html($listing['total'])?></strong><span>visible sessions</span></p>
            </div>
        </header>

        <section class="exam-archive-shell exam-archive-results" id="papers" aria-label="Past paper archive">
            <nav class="archive-hierarchy-tabs archive-board-tabs" aria-label="Exam board">
                <?php foreach ($navigation['boards'] as $board): ?><a class="<?= $board['board_id'] === $filters['exam_board_id'] ? 'is-active' : ''; ?>" href="<?=pastpapers_html($archiveUrl(['exam_board_id' => $board['board_id'], 'syllabus_id' => '', 'paper_group' => '']))?>"><?=pastpapers_html($board['name'])?></a><?php endforeach; ?>
            </nav>
            <nav class="archive-hierarchy-tabs archive-syllabus-tabs" aria-label="Syllabus">
                <?php foreach ($navigation['syllabuses'] as $syllabus): ?><a class="<?= $syllabus['syllabus_id'] === $filters['syllabus_id'] ? 'is-active' : ''; ?>" href="<?=pastpapers_html($archiveUrl(['syllabus_id' => $syllabus['syllabus_id'], 'paper_group' => '']))?>"><span><?=pastpapers_html($syllabus['public_title'])?></span><small><?=pastpapers_html($syllabus['syllabus_code'])?></small></a><?php endforeach; ?>
            </nav>
            <nav class="archive-paper-tabs" aria-label="Paper">
                <?php foreach ($navigation['paper_groups'] as $paperGroup): ?><a class="<?= $paperGroup['key'] === $filters['paper_group'] ? 'is-active' : ''; ?>" href="<?=pastpapers_html($archiveUrl(['paper_group' => $paperGroup['key']]))?>"><?=pastpapers_html($paperGroup['label'])?><small><?=pastpapers_html($paperGroup['count'])?></small></a><?php endforeach; ?>
            </nav>

            <form method="GET" action="<?=pastpapers_html($actionUrl)?>" class="archive-command-bar archive-refine-bar" id="past-paper-filters">
                <input type="hidden" name="exam_board_id" value="<?=pastpapers_html($filters['exam_board_id'])?>">
                <input type="hidden" name="syllabus_id" value="<?=pastpapers_html($filters['syllabus_id'])?>">
                <input type="hidden" name="paper_group" value="<?=pastpapers_html($filters['paper_group'])?>">
                <label class="archive-filter-chip"><span>Session</span><select name="exam_session" aria-label="Session"><option value="">All sessions</option><?php foreach ($options['sessions'] ?? [] as $session): ?><option value="<?=pastpapers_html($session)?>" <?=pastpapers_selected($filters['exam_session'], $session)?>><?=pastpapers_html($session)?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Year</span><select name="year" aria-label="Year"><option value="">All years</option><?php foreach ($options['years'] ?? [] as $year): ?><option value="<?=pastpapers_html($year)?>" <?=pastpapers_selected($filters['year'], $year)?>><?=pastpapers_html($year)?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Resource</span><select name="resource_type" aria-label="Resource type"><option value="">QP / MS</option><option value="question_paper" <?=pastpapers_selected($filters['resource_type'], 'question_paper')?>>Question Paper</option><option value="mark_scheme" <?=pastpapers_selected($filters['resource_type'], 'mark_scheme')?>>Mark Scheme</option><option value="model_answer" <?=pastpapers_selected($filters['resource_type'], 'model_answer')?>>Has Model Answer</option><option value="video_solution" <?=pastpapers_selected($filters['resource_type'], 'solution_video')?>>Has Video Solution</option><option value="examiner_report" <?=pastpapers_selected($filters['resource_type'], 'examiner_report')?>>Examiner Report</option><option value="grade_boundaries" <?=pastpapers_selected($filters['resource_type'], 'grade_boundaries')?>>Grade Threshold</option></select></label>
                <label class="archive-search-control" for="past-paper-search"><span class="visually-hidden">Search papers</span><i class="fas fa-search" aria-hidden="true"></i><input id="past-paper-search" type="search" name="search" value="<?=pastpapers_html($filters['search'])?>" placeholder="Search this syllabus" autocomplete="off"><kbd>/</kbd></label>
                <div class="archive-command-actions"><button type="submit" class="archive-apply-button">Filter</button><a href="<?=pastpapers_html($archiveUrl(['search' => '', 'year' => '', 'exam_session' => '', 'resource_type' => '']))?>" class="archive-reset-button">Clear</a></div>
            </form>

            <div class="exam-archive-results-meta">
                <p><strong><?=pastpapers_html($selectedPaper['label'] ?? 'Paper')?></strong> · <?=pastpapers_html($rangeStart)?>–<?=pastpapers_html($rangeEnd)?> of <?=pastpapers_html($listing['total'])?> sessions</p>
                <p><span class="fas fa-keyboard" aria-hidden="true"></span> Press <kbd>/</kbd> to search</p>
            </div>

            <?php if ($papers): ?>
                <section class="exam-archive-paper-group archive-single-paper" aria-labelledby="selected-paper-heading">
                    <header class="exam-archive-paper-group-header"><div><h2 id="selected-paper-heading"><?=pastpapers_html($selectedPaper['label'] ?? 'Paper')?></h2><span><?=pastpapers_html($selectedSyllabus['public_title'] ?? '')?></span></div></header>
                    <div class="exam-archive-table-scroll" tabindex="0" aria-label="<?=pastpapers_html($selectedPaper['label'] ?? 'Paper')?> sessions">
                        <table class="exam-archive-table">
                            <caption class="visually-hidden"><?=pastpapers_html($selectedPaper['label'] ?? 'Paper')?> sessions and resources</caption>
                            <thead><tr><th scope="col">Session</th><th scope="col">Year</th><th scope="col">Variant</th><th scope="col"><span class="fas fa-file-alt" aria-hidden="true"></span><span>Question Paper</span></th><th scope="col"><span class="fas fa-check-circle" aria-hidden="true"></span><span>Mark Scheme</span></th><th scope="col"><span class="fas fa-lightbulb" aria-hidden="true"></span><span>Model Answer</span></th><th scope="col"><span class="fas fa-play-circle" aria-hidden="true"></span><span>Video Solution</span></th><th scope="col"><span class="fas fa-clipboard-check" aria-hidden="true"></span><span>Examiner Report</span></th><th scope="col"><span class="fas fa-chart-line" aria-hidden="true"></span><span>Grade Threshold</span></th><th scope="col" class="exam-archive-extra-column">Other</th></tr></thead>
                            <tbody>
                            <?php foreach ($papers as $paper):
                                $resources = pastpapers_listing_resource_groups($resourcesByPaper[$paper['paper_id']] ?? []);
                                $normalized = mmh_past_normalize_paper_label($paper);
                                $variantDisplay = $normalized['variant'] !== '' ? $normalized['variant'] : ($normalized['board_family'] === 'edexcel' ? 'H' : ($paper['variant'] ?: '—'));
                                if ($normalized['board_family'] === 'cambridge') $variantDisplay = preg_replace('/^Variant\s+/i', '', $variantDisplay);
                            ?>
                                <tr>
                                    <th scope="row"><?=pastpapers_html(pastpapers_session_label($paper))?></th>
                                    <td class="exam-archive-year"><?=pastpapers_html($paper['year'] ?: '—')?></td>
                                    <td><?=pastpapers_html($variantDisplay)?></td>
                                    <td><?=pastpapers_archive_resource_actions($conn, $paper, $resources['question_paper'] ?? [], 'question_paper', $baseUrl)?></td>
                                    <td><?=pastpapers_archive_resource_actions($conn, $paper, $resources['mark_scheme'] ?? [], 'mark_scheme', $baseUrl)?></td>
                                    <td><?=pastpapers_archive_resource_actions($conn, $paper, $resources['model_answer'] ?? [], 'model_answer', $baseUrl)?></td>
                                    <td><?=pastpapers_archive_resource_actions($conn, $paper, $resources['solution_video'] ?? [], 'solution_video', $baseUrl)?></td>
                                    <td><?=pastpapers_archive_resource_actions($conn, $paper, $resources['examiner_report'] ?? [], 'examiner_report', $baseUrl)?></td>
                                    <td><?=pastpapers_archive_resource_actions($conn, $paper, $resources['grade_boundaries'] ?? [], 'grade_boundaries', $baseUrl)?></td>
                                    <td class="exam-archive-extra-column"><div class="exam-archive-extra-actions"><?=pastpapers_archive_resource_actions($conn, $paper, $resources['insert'] ?? [], 'insert', $baseUrl)?><?=pastpapers_archive_resource_actions($conn, $paper, $resources['formula_sheet'] ?? [], 'formula_sheet', $baseUrl)?></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php if ($listing['pages'] > 1): ?><nav class="exam-archive-pagination" aria-label="Past Papers pages"><a class="<?= $listing['page'] <= 1 ? 'is-disabled' : ''; ?>" href="<?=pastpapers_html($paginationUrl(max(1, $listing['page'] - 1)))?>">Previous</a><span>Page <?=pastpapers_html($listing['page'])?> / <?=pastpapers_html($listing['pages'])?></span><a class="<?= $listing['page'] >= $listing['pages'] ? 'is-disabled' : ''; ?>" href="<?=pastpapers_html($paginationUrl(min($listing['pages'], $listing['page'] + 1)))?>">Next</a></nav><?php endif; ?>
            <?php else: ?>
                <section class="exam-archive-empty" aria-live="polite"><div class="exam-archive-empty-art" aria-hidden="true"><span></span><span></span><span></span><i class="fas fa-file-alt"></i></div><div><p class="exam-archive-eyebrow">Archive search</p><h2>No sessions match these filters.</h2><p>Try a different session, year, resource type, or choose another paper.</p><a class="archive-empty-reset" href="<?=pastpapers_html($archiveUrl(['search' => '', 'year' => '', 'exam_session' => '', 'resource_type' => '']))?>">Clear refinements</a></div></section>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php include 'views/public/layouts/footer.php'; ?>
<script>
(function () { var search = document.getElementById('past-paper-search'); document.addEventListener('keydown', function (event) { var target = event.target; var editable = target && (target.matches('input, textarea, select') || target.isContentEditable); if (event.key === '/' && !editable && search) { event.preventDefault(); search.focus(); } if (event.key === 'Escape' && document.activeElement === search) search.blur(); }); }());
</script>
</body>
</html>
