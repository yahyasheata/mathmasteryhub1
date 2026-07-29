<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/PastPapers.php';
require_once 'views/partials/past-papers-list.php';

$pageName = 'past_papers';
$username = $_SESSION['username'];
$conn = db();
mmh_past_ensure_schema($conn);
$userId = (int) getUserInfo($username)->user_id;
$siteSettings = getSiteSettings();
$siteName = $siteSettings['website_name'] ?? 'Math Mastery Hub';
$actionUrl = rtrim((string) $baseUrl, '/') . '/user/past-papers';
$filters = mmh_past_frontend_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$options = mmh_past_filter_options($conn);
$listing = mmh_past_frontend_listing($conn, $filters, $page, 25);
$papers = $listing['rows'];
$resourcesByPaper = mmh_past_listing_resources_for_papers($conn, array_column($papers, 'paper_id'), $userId);
$activeFilterKeys = ['search', 'exam_board_id', 'syllabus_id', 'year', 'exam_session', 'paper_number', 'variant', 'resource_type'];
$activeFilterCount = count(array_filter($activeFilterKeys, fn($key) => ($filters[$key] ?? '') !== ''));
$selectedSubject = '';
foreach ($options['syllabuses'] as $syllabus) {
    if (($filters['syllabus_id'] ?? '') === $syllabus['syllabus_id']) {
        $selectedSubject = $syllabus['board_name'] . ' · ' . $syllabus['public_title'] . ' (' . $syllabus['syllabus_code'] . ')';
        break;
    }
}
if ($selectedSubject === '' && !empty($filters['exam_board_id'])) {
    foreach ($options['boards'] as $board) {
        if ($filters['exam_board_id'] === $board['board_id']) { $selectedSubject = $board['name']; break; }
    }
}
if ($selectedSubject === '') $selectedSubject = 'Search by paper, session, year or resource';
$labels = pastpapers_listing_resource_labels();
$rangeStart = $listing['total'] ? $listing['offset'] + 1 : 0;
$rangeEnd = min($listing['total'], $listing['offset'] + count($papers));
$paginationUrl = function ($targetPage) use ($actionUrl, $filters) { return pastpapers_listing_query_url($actionUrl, $filters, $targetPage); };
$paperGroups = [];
foreach ($papers as $paper) {
    $paperKey = trim((string)($paper['paper_number'] ?? ''));
    if ($paperKey === '') $paperKey = 'Other papers';
    $groupTitle = $paperKey;
    if ($groupTitle !== 'Other papers' && !preg_match('/^(paper|component)\b/i', $groupTitle)) $groupTitle = 'Paper ' . $groupTitle;
    $paperGroups[$groupTitle][] = $paper;
}
uksort($paperGroups, 'strnatcasecmp');
$resourceCell = function (array $paper, array $resources, $type) use ($baseUrl) {
    $action = pastpapers_listing_resource_action($paper, $resources[$type] ?? null, $type, $baseUrl);
    return $action !== '' ? $action : '<span class="exam-archive-resource-empty" aria-label="Resource not available">—</span>';
};
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Past Papers | <?=pastpapers_html($siteName)?></title>
    <?php include 'layouts/user/header.php'; ?>
    <link rel="stylesheet" href="<?=pastpapers_html(rtrim((string) $baseUrl, '/'))?>/resources/css/past-papers-frontend.css">
</head>
<body class="body ds-bg-primary student-dashboard-page past-papers-student-page" style="margin-top: 65px">
<div id="app">
    <div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
    <form id="logout-form" action="<?=$baseUrl?>/resources/logout" method="POST" class="d-none"></form>
    <?php include 'layouts/user/aside.php'; ?>
    <main class="p-0 font-2">
        <div class="past-papers-center student-surface exam-archive-page">
            <header class="exam-archive-header">
                <div class="exam-archive-shell">
                    <div>
                        <p class="exam-archive-eyebrow"><span class="fas fa-layer-group" aria-hidden="true"></span> Examination archive</p>
                        <h1>Past Papers</h1>
                        <p><?=pastpapers_html($selectedSubject)?></p>
                    </div>
                    <p class="exam-archive-total"><strong><?=pastpapers_html($listing['total'])?></strong><span>paper sets</span></p>
                </div>
            </header>

            <section class="exam-archive-shell exam-archive-results" id="papers" aria-label="Past paper archive">
                <?=pastpapers_listing_filter_form($options, $filters, $actionUrl, $listing['total'], $activeFilterCount)?>
                <div class="exam-archive-results-meta">
                    <p>Showing <strong><?=pastpapers_html($rangeStart)?>–<?=pastpapers_html($rangeEnd)?></strong> of <?=pastpapers_html($listing['total'])?> papers</p>
                    <p><span class="fas fa-keyboard" aria-hidden="true"></span> Press <kbd>/</kbd> to search</p>
                </div>

                <?php if ($paperGroups): ?>
                    <div class="exam-archive-paper-groups">
                        <?php foreach ($paperGroups as $paperTitle => $paperRows): ?>
                            <section class="exam-archive-paper-group" aria-labelledby="<?=pastpapers_html('group-' . md5($paperTitle))?>">
                                <header class="exam-archive-paper-group-header">
                                    <div><h2 id="<?=pastpapers_html('group-' . md5($paperTitle))?>"><?=pastpapers_html($paperTitle)?></h2><span><?=count($paperRows)?> session<?= count($paperRows) === 1 ? '' : 's'?></span></div>
                                    <span class="exam-archive-paper-group-hint">Select a resource to open securely</span>
                                </header>
                                <div class="exam-archive-table-scroll" tabindex="0" aria-label="<?=pastpapers_html($paperTitle)?> sessions">
                                    <table class="exam-archive-table">
                                        <caption class="visually-hidden"><?=pastpapers_html($paperTitle)?> sessions and resources</caption>
                                        <thead><tr><th scope="col">Session</th><th scope="col">Year</th><th scope="col">Variant</th><th scope="col"><span class="fas fa-file-alt" aria-hidden="true"></span><span>Question Paper</span></th><th scope="col"><span class="fas fa-check-circle" aria-hidden="true"></span><span>Mark Scheme</span></th><th scope="col"><span class="fas fa-clipboard-check" aria-hidden="true"></span><span>Examiner Report</span></th><th scope="col"><span class="fas fa-chart-line" aria-hidden="true"></span><span>Grade Threshold</span></th><th scope="col" class="exam-archive-extra-column">Other</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($paperRows as $paper): $resources = pastpapers_listing_resource_index($resourcesByPaper[$paper['paper_id']] ?? []); ?>
                                            <tr>
                                                <th scope="row"><?=pastpapers_html(pastpapers_session_label($paper))?></th>
                                                <td class="exam-archive-year"><?=pastpapers_html($paper['year'] ?: '—')?></td>
                                                <td><?=pastpapers_html($paper['variant'] ?: ($paper['tier'] ?: '—'))?></td>
                                                <td><?=$resourceCell($paper, $resources, 'question_paper')?></td>
                                                <td><?=$resourceCell($paper, $resources, 'mark_scheme')?></td>
                                                <td><?=$resourceCell($paper, $resources, 'examiner_report')?></td>
                                                <td><?=$resourceCell($paper, $resources, 'grade_boundaries')?></td>
                                                <td class="exam-archive-extra-column"><div class="exam-archive-extra-actions"><?=$resourceCell($paper, $resources, 'insert')?><?=$resourceCell($paper, $resources, 'formula_sheet')?></div></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($listing['pages'] > 1): ?>
                        <nav class="exam-archive-pagination" aria-label="Past Papers pages">
                            <a class="<?= $listing['page'] <= 1 ? 'is-disabled' : ''; ?>" href="<?=pastpapers_html($paginationUrl(max(1, $listing['page'] - 1)))?>" aria-disabled="<?= $listing['page'] <= 1 ? 'true' : 'false'; ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Previous</a>
                            <span>Page <?=pastpapers_html($listing['page'])?> / <?=pastpapers_html($listing['pages'])?></span>
                            <a class="<?= $listing['page'] >= $listing['pages'] ? 'is-disabled' : ''; ?>" href="<?=pastpapers_html($paginationUrl(min($listing['pages'], $listing['page'] + 1)))?>">Next <span class="fas fa-arrow-right" aria-hidden="true"></span></a>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <section class="exam-archive-empty" aria-live="polite">
                        <div class="exam-archive-empty-art" aria-hidden="true"><span></span><span></span><span></span><i class="fas fa-file-alt"></i></div>
                        <div><p class="exam-archive-eyebrow">Archive search</p><h2><?= $activeFilterCount ? 'No papers match these filters.' : 'The archive is getting ready.'; ?></h2><p><?= $activeFilterCount ? 'Try a broader year, session or paper filter.' : 'Published papers will appear here as soon as they are available.'; ?></p><?php if ($activeFilterCount): ?><a class="archive-empty-reset" href="<?=pastpapers_html($actionUrl)?>">Clear all filters</a><?php endif; ?></div>
                    </section>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <?php include 'layouts/user/footer.php'; ?>
</div>
<script>
(function () {
    var search = document.getElementById('past-paper-search');
    document.addEventListener('keydown', function (event) {
        var target = event.target;
        var editable = target && (target.matches('input, textarea, select') || target.isContentEditable);
        if (event.key === '/' && !editable && search) { event.preventDefault(); search.focus(); }
        if (event.key === 'Escape' && document.activeElement === search) { search.blur(); }
    });
}());
</script>
</body>
</html>
