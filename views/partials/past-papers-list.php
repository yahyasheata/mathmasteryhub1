<?php
if (!function_exists('pastpapers_html')) {
    function pastpapers_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('pastpapers_url')) {
    function pastpapers_url($baseUrl, $path) { return rtrim((string)$baseUrl, '/') . '/' . ltrim((string)$path, '/'); }
}

if (!function_exists('pastpapers_selected')) {
    function pastpapers_selected($a, $b) { return (string)$a === (string)$b ? 'selected' : ''; }
}

if (!function_exists('pastpapers_session_label')) {
    function pastpapers_session_label(array $paper) {
        $session = trim((string)($paper['exam_session'] ?? ''));
        if ($session === 'Custom' && trim((string)($paper['custom_session'] ?? '')) !== '') {
            return trim((string)$paper['custom_session']);
        }
        return $session !== '' ? $session : 'Session';
    }
}

if (!function_exists('pastpapers_paper_title')) {
    function pastpapers_paper_title(array $paper) {
        $title = trim((string)($paper['short_title'] ?? ''));
        if ($title !== '') { return $title; }
        return trim((string)($paper['paper_number'] ?? 'Paper') . ' — ' . (string)($paper['variant'] ?? 'Variant'));
    }
}

if (!function_exists('pastpapers_filter_form')) {
    function pastpapers_filter_form(array $options, array $filters, $actionUrl) {
        ob_start(); ?>
        <details class="past-papers-filter-panel" open>
            <summary><span class="fas fa-sliders-h" aria-hidden="true"></span><strong>Find a paper</strong><small>Exam Board → Syllabus → Year → Session</small></summary>
            <form method="GET" action="<?=pastpapers_html($actionUrl)?>" class="past-papers-filters">
                <?php if (!empty($filters['course_id'])): ?><input type="hidden" name="course_id" value="<?=pastpapers_html($filters['course_id'])?>"><?php endif; ?>
                <label><span>Search</span><input type="search" name="search" value="<?=pastpapers_html($filters['search'] ?? '')?>" placeholder="Search syllabus, paper, topic or keyword"></label>
                <label><span>Exam Board</span><select name="exam_board_id"><option value="">All boards</option><?php foreach ($options['boards'] ?? [] as $board): ?><option value="<?=pastpapers_html($board['board_id'])?>" <?=pastpapers_selected($filters['exam_board_id'] ?? '', $board['board_id'])?>><?=pastpapers_html($board['name'])?></option><?php endforeach; ?></select></label>
                <label><span>Syllabus</span><select name="syllabus_id"><option value="">All syllabuses</option><?php foreach ($options['syllabuses'] ?? [] as $syllabus): ?><option value="<?=pastpapers_html($syllabus['syllabus_id'])?>" <?=pastpapers_selected($filters['syllabus_id'] ?? '', $syllabus['syllabus_id'])?>><?=pastpapers_html($syllabus['public_title'])?> · <?=pastpapers_html($syllabus['syllabus_code'])?></option><?php endforeach; ?></select></label>
                <label><span>Year</span><select name="year"><option value="">Any year</option><?php foreach ($options['years'] ?? [] as $year): ?><option value="<?=pastpapers_html($year)?>" <?=pastpapers_selected($filters['year'] ?? '', $year)?>><?=pastpapers_html($year)?></option><?php endforeach; ?></select></label>
                <label><span>Session</span><select name="exam_session"><option value="">Any session</option><?php foreach ($options['sessions'] ?? [] as $session): ?><option value="<?=pastpapers_html($session)?>" <?=pastpapers_selected($filters['exam_session'] ?? '', $session)?>><?=pastpapers_html($session)?></option><?php endforeach; ?></select></label>
                <label><span>Paper / Component</span><select name="paper_number"><option value="">Any paper</option><?php foreach ($options['papers'] ?? [] as $paper): ?><option value="<?=pastpapers_html($paper)?>" <?=pastpapers_selected($filters['paper_number'] ?? '', $paper)?>><?=pastpapers_html($paper)?></option><?php endforeach; ?></select></label>
                <label><span>Variant</span><select name="variant"><option value="">Any variant</option><?php foreach ($options['variants'] ?? [] as $variant): ?><option value="<?=pastpapers_html($variant)?>" <?=pastpapers_selected($filters['variant'] ?? '', $variant)?>><?=pastpapers_html($variant)?></option><?php endforeach; ?></select></label>
                <div class="past-papers-filter-actions"><button type="submit" class="past-papers-btn primary"><span class="fas fa-search" aria-hidden="true"></span> Apply filters</button><a href="<?=pastpapers_html($actionUrl)?>" class="past-papers-btn ghost">Reset</a></div>
            </form>
        </details>
        <?php return ob_get_clean();
    }
}

if (!function_exists('pastpapers_resource_row')) {
    function pastpapers_resource_row(mysqli $conn, array $resource, $baseUrl) {
        $state = pastpapers_archive_resource_state($conn, $resource);
        if (empty($state['visible'])) { return ''; }
        $label = mmh_past_resource_label($resource['resource_type'] ?? '', $resource['custom_type'] ?? '');
        $icon = mmh_past_resource_icon($resource['resource_type'] ?? 'custom');
        ob_start(); ?>
        <article class="past-paper-resource is-<?=pastpapers_html($state['class'])?>">
            <span class="past-paper-resource-icon fas <?=pastpapers_html($icon)?>" aria-hidden="true"></span>
            <div class="past-paper-resource-copy">
                <strong><?=pastpapers_html($label)?></strong>
                <small><?=pastpapers_html($state['message'])?></small>
            </div>
            <div class="past-paper-resource-state">
                <span class="past-paper-state-badge"><?=pastpapers_html($state['label'])?></span>
                <?php if (!empty($state['actions'])): ?>
                    <div class="past-paper-resource-actions">
                        <?php foreach ($state['actions'] as $action): ?>
                            <a class="past-papers-btn <?=!empty($action['primary']) ? 'primary' : 'secondary'?>" href="<?=pastpapers_html(pastpapers_url($baseUrl, $action['url']))?>" target="_blank" rel="noopener"><?=pastpapers_html($action['label'])?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php return ob_get_clean();
    }
}

if (!function_exists('pastpapers_paper_card')) {
    function pastpapers_paper_card(mysqli $conn, array $paper, array $resources, $baseUrl, $showCourseAction = true) {
        $duration = mmh_past_duration_label($paper['duration_minutes'] ?? 0);
        $marks = isset($paper['maximum_marks']) && $paper['maximum_marks'] !== null && $paper['maximum_marks'] !== '' ? (int)$paper['maximum_marks'] . ' marks' : '';
        $resourceHtml = '';
        foreach ($resources as $resource) { $resourceHtml .= pastpapers_resource_row($conn, $resource, $baseUrl); }
        if ($resourceHtml === '') {
            $resourceHtml = '<div class="past-papers-empty compact"><span class="fas fa-folder-open" aria-hidden="true"></span><p>No resources are available for this paper yet.</p></div>';
        }
        $courseUrl = !empty($paper['course_numeric_id']) ? pastpapers_url($baseUrl, 'course/' . rawurlencode((string)$paper['course_numeric_id'])) : '';
        ob_start(); ?>
        <article class="past-paper-card">
            <header class="past-paper-card-header">
                <div>
                    <span class="past-paper-board"><?=pastpapers_html($paper['board_name'] ?? 'Exam Board')?> · <?=pastpapers_html($paper['syllabus_code'] ?? '')?></span>
                    <h3><?=pastpapers_html($paper['syllabus_title'] ?? 'Syllabus')?></h3>
                    <p><?=pastpapers_html(pastpapers_session_label($paper))?> <?=pastpapers_html($paper['year'] ?? '')?> · <?=pastpapers_html(pastpapers_paper_title($paper))?></p>
                </div>
                <span class="past-paper-component"><?=pastpapers_html($paper['variant'] ?? '')?></span>
            </header>
            <div class="past-paper-meta">
                <?php if ($duration !== ''): ?><span><i class="far fa-clock" aria-hidden="true"></i><?=pastpapers_html($duration)?></span><?php endif; ?>
                <?php if ($marks !== ''): ?><span><i class="fas fa-list-ol" aria-hidden="true"></i><?=pastpapers_html($marks)?></span><?php endif; ?>
                <span><i class="fas fa-folder-open" aria-hidden="true"></i><?=count($resources)?> <?=count($resources) === 1 ? 'resource' : 'resources'?></span>
            </div>
            <?php if (!empty($paper['description'])): ?><p class="past-paper-description"><?=pastpapers_html($paper['description'])?></p><?php endif; ?>
            <div class="past-paper-resource-list"><?=$resourceHtml?></div>
            <?php if ($showCourseAction && $courseUrl !== '' && !empty($paper['course_title'])): ?>
                <footer class="past-paper-card-footer"><span>Linked course: <?=pastpapers_html($paper['course_title'])?></span><a href="<?=pastpapers_html($courseUrl)?>">Open course preview</a></footer>
            <?php endif; ?>
        </article>
        <?php return ob_get_clean();
    }
}

if (!function_exists('pastpapers_listing_session_year')) {
    function pastpapers_listing_session_year(array $paper) {
        $session = pastpapers_session_label($paper);
        $year = (int) ($paper['year'] ?? 0);
        return trim($session . ($year > 0 ? ' ' . $year : ''));
    }
}

if (!function_exists('pastpapers_listing_component')) {
    function pastpapers_listing_component(array $paper) {
        $paperNumber = trim((string) ($paper['paper_number'] ?? 'Paper'));
        $variant = trim((string) ($paper['variant'] ?? ''));
        if ($variant === '' || stripos($paperNumber, $variant) !== false) return $paperNumber;
        return $paperNumber . ' · ' . $variant;
    }
}

if (!function_exists('pastpapers_listing_title')) {
    function pastpapers_listing_title(array $paper) {
        return trim(pastpapers_listing_session_year($paper) . ' · ' . pastpapers_listing_component($paper));
    }
}

if (!function_exists('pastpapers_listing_resource_labels')) {
    function pastpapers_listing_resource_labels() {
        return [
            'question_paper' => ['short' => 'QP', 'label' => 'Question Paper'],
            'mark_scheme' => ['short' => 'MS', 'label' => 'Mark Scheme'],
            'model_answer' => ['short' => 'Model', 'label' => 'Model Answer'],
            'solution_video' => ['short' => 'Video', 'label' => 'Video Solution'],
            'examiner_report' => ['short' => 'ER', 'label' => 'Examiner Report'],
            'grade_boundaries' => ['short' => 'GT', 'label' => 'Grade Threshold'],
            'insert' => ['short' => 'Insert', 'label' => 'Insert'],
            'formula_sheet' => ['short' => 'Formula', 'label' => 'Formula Sheet'],
        ];
    }
}

if (!function_exists('pastpapers_listing_resource_index')) {
    function pastpapers_listing_resource_index(array $resources) {
        $indexed = [];
        foreach ($resources as $resource) {
            $type = (string) ($resource['resource_type'] ?? '');
            if (!isset($indexed[$type])) $indexed[$type] = $resource;
        }
        return $indexed;
    }
}

if (!function_exists('pastpapers_listing_resource_icon')) {
    function pastpapers_listing_resource_icon($type) {
        $icons = [
            'question_paper' => 'fa-file-alt',
            'mark_scheme' => 'fa-check-circle',
            'model_answer' => 'fa-lightbulb',
            'solution_video' => 'fa-play-circle',
            'examiner_report' => 'fa-clipboard-check',
            'grade_boundaries' => 'fa-chart-line',
            'insert' => 'fa-paperclip',
            'formula_sheet' => 'fa-square-root-alt',
        ];
        return $icons[(string) $type] ?? 'fa-file';
    }
}

if (!function_exists('pastpapers_listing_resource_action')) {
    function pastpapers_listing_resource_action(array $paper, $resource, $type, $baseUrl, $mode = 'desktop') {
        $labels = pastpapers_listing_resource_labels();
        $label = $labels[$type]['label'] ?? mmh_past_resource_label($type);
        $short = $labels[$type]['short'] ?? $label;
        $icon = pastpapers_listing_resource_icon($type);
        $text = $mode === 'mobile' ? $label : $short;
        $classes = 'exam-resource-button' . ($mode === 'mobile' ? ' exam-resource-button-mobile' : '');

        if (!$resource) {
            return '';
        }

        $state = mmh_past_listing_resource_state($resource);
        if (empty($state['available'])) {
            return '<span class="' . $classes . ' is-unavailable" title="' . pastpapers_html($state['reason']) . '" aria-label="' . pastpapers_html($label . ': ' . $state['reason']) . '"><i class="fas fa-lock" aria-hidden="true"></i><span>' . pastpapers_html($text) . '</span></span>';
        }

        $route = pastpapers_url($baseUrl, 'past-papers/resource/' . rawurlencode((string) $resource['resource_id']));
        $aria = 'Open ' . $label . ' for ' . pastpapers_listing_title($paper);
        return '<a class="' . $classes . '" href="' . pastpapers_html($route) . '" target="_blank" rel="noopener noreferrer" title="' . pastpapers_html($label) . '" aria-label="' . pastpapers_html($aria) . '"><i class="fas ' . pastpapers_html($icon) . '" aria-hidden="true"></i><span>' . pastpapers_html($text) . '</span></a>';
    }
}

if (!function_exists('pastpapers_listing_resource_groups')) {
    function pastpapers_listing_resource_groups(array $resources) {
        $grouped = [];
        foreach ($resources as $resource) {
            $type = mmh_past_resource_type($resource['resource_type'] ?? 'custom');
            $grouped[$type][] = $resource;
        }
        return $grouped;
    }
}

if (!function_exists('pastpapers_archive_resource_state')) {
    function pastpapers_archive_resource_state(mysqli $conn, array $resource) {
        // The archive query already returns enrolment/access facts for every
        // visible resource, avoiding an additional query for each table cell.
        if (array_key_exists('listing_linked_enrolled', $resource)) {
            $state = mmh_past_listing_resource_state($resource);
            return [
                'visible' => true,
                'available' => !empty($state['available']),
                'label' => !empty($state['available']) ? 'Available' : 'Locked',
                'message' => $state['reason'] ?? '',
                'actions' => !empty($state['available']) ? [['url' => 'past-papers/resource/' . rawurlencode((string) $resource['resource_id'])]] : [],
            ];
        }
        return mmh_past_resource_view_state($conn, $resource);
    }
}

if (!function_exists('pastpapers_archive_resource_actions')) {
    function pastpapers_archive_resource_actions(mysqli $conn, array $paper, array $resources, $type, $baseUrl) {
        $resources = array_values(array_filter($resources, fn($resource) => is_array($resource)));
        $labels = pastpapers_listing_resource_labels();
        $label = $labels[$type]['label'] ?? mmh_past_resource_label($type);
        if (!$resources) return '<span class="exam-archive-resource-empty" aria-label="' . pastpapers_html($label . ' not available') . '">—</span>';
        if (count($resources) === 1) return pastpapers_archive_resource_action($conn, $paper, $resources[0], $type, $baseUrl);
        $icon = pastpapers_listing_resource_icon($type);
        $items = '';
        foreach ($resources as $resource) {
            $state = pastpapers_archive_resource_state($conn, $resource);
            if (empty($state['visible'])) continue;
            $title = trim((string)($resource['display_title'] ?? '')) ?: $label;
            if (empty($state['available']) || empty($state['actions'][0]['url'])) {
                $items .= '<span class="exam-archive-resource-menu-item is-unavailable"><i class="fas fa-lock" aria-hidden="true"></i>' . pastpapers_html($title) . '</span>';
                continue;
            }
            $route = pastpapers_url($baseUrl, $state['actions'][0]['url']);
            $items .= '<a class="exam-archive-resource-menu-item" href="' . pastpapers_html($route) . '" target="_blank" rel="noopener noreferrer"><i class="fas ' . pastpapers_html($icon) . '" aria-hidden="true"></i>' . pastpapers_html($title) . '</a>';
        }
        if ($items === '') return '<span class="exam-archive-resource-empty" aria-label="' . pastpapers_html($label . ' unavailable') . '">—</span>';
        return '<details class="exam-archive-resource-menu"><summary class="exam-resource-button" aria-label="Open ' . pastpapers_html($label . ' options for ' . pastpapers_listing_title($paper)) . '"><i class="fas ' . pastpapers_html($icon) . '" aria-hidden="true"></i><span>' . pastpapers_html(($labels[$type]['short'] ?? $label) . ' ' . count($resources)) . '</span><i class="fas fa-chevron-down exam-archive-menu-caret" aria-hidden="true"></i></summary><div class="exam-archive-resource-menu-popover">' . $items . '</div></details>';
    }
}

if (!function_exists('pastpapers_archive_resource_action')) {
    function pastpapers_archive_resource_action(mysqli $conn, array $paper, $resource, $type, $baseUrl) {
        $labels = pastpapers_listing_resource_labels();
        $label = $labels[$type]['label'] ?? mmh_past_resource_label($type);
        $icon = pastpapers_listing_resource_icon($type);
        if (!$resource) {
            return '<span class="exam-archive-resource-empty" aria-label="' . pastpapers_html($label . ' not available') . '">—</span>';
        }
        $state = pastpapers_archive_resource_state($conn, $resource);
        if (empty($state['visible'])) return '';
        if (empty($state['available']) || empty($state['actions'][0]['url'])) {
            return '<span class="exam-resource-button is-unavailable" title="' . pastpapers_html($state['message'] ?? 'Unavailable') . '" aria-label="' . pastpapers_html($label . ': ' . ($state['label'] ?? 'Unavailable')) . '"><i class="fas fa-lock" aria-hidden="true"></i><span>Locked</span></span>';
        }
        $route = pastpapers_url($baseUrl, $state['actions'][0]['url']);
        $aria = 'Open ' . $label . ' for ' . pastpapers_listing_title($paper);
        return '<a class="exam-resource-button" href="' . pastpapers_html($route) . '" target="_blank" rel="noopener noreferrer" title="' . pastpapers_html($label) . '" aria-label="' . pastpapers_html($aria) . '"><i class="fas ' . pastpapers_html($icon) . '" aria-hidden="true"></i><span>View</span></a>';
    }
}

if (!function_exists('pastpapers_listing_query_url')) {
    function pastpapers_listing_query_url($actionUrl, array $filters, $page = null) {
        $query = array_filter($filters, function ($value, $key) { return $key !== 'course_id' ? $value !== '' : $value !== ''; }, ARRAY_FILTER_USE_BOTH);
        if ($page !== null) $query['page'] = max(1, (int) $page);
        return rtrim((string) $actionUrl, '/') . ($query ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('pastpapers_listing_filter_form')) {
    function pastpapers_listing_filter_form(array $options, array $filters, $actionUrl, $total, $activeCount) {
        $resourceOptions = pastpapers_listing_resource_labels();
        ob_start(); ?>
        <form method="GET" action="<?=pastpapers_html($actionUrl)?>" class="archive-command-bar" id="past-paper-filters">
            <?php if (!empty($filters['course_id'])): ?><input type="hidden" name="course_id" value="<?=pastpapers_html($filters['course_id'])?>"><?php endif; ?>
            <label class="archive-search-control" for="past-paper-search"><span class="visually-hidden">Search papers</span><i class="fas fa-search" aria-hidden="true"></i><input id="past-paper-search" type="search" name="search" value="<?=pastpapers_html($filters['search'] ?? '')?>" placeholder="Search papers, components or keywords" autocomplete="off"><kbd>/</kbd></label>
            <div class="archive-filter-rail" aria-label="Paper filters">
                <label class="archive-filter-chip"><span>Paper</span><select name="paper_number" aria-label="Paper"><option value="">Any paper</option><?php foreach ($options['papers'] ?? [] as $paper): ?><option value="<?=pastpapers_html($paper)?>" <?=pastpapers_selected($filters['paper_number'] ?? '', $paper)?>><?=pastpapers_html($paper)?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Session</span><select name="exam_session" aria-label="Session"><option value="">Any session</option><?php foreach ($options['sessions'] ?? [] as $session): ?><option value="<?=pastpapers_html($session)?>" <?=pastpapers_selected($filters['exam_session'] ?? '', $session)?>><?=pastpapers_html($session)?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Year</span><select name="year" aria-label="Year"><option value="">Any year</option><?php foreach ($options['years'] ?? [] as $year): ?><option value="<?=pastpapers_html($year)?>" <?=pastpapers_selected($filters['year'] ?? '', $year)?>><?=pastpapers_html($year)?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Resource</span><select name="resource_type" aria-label="Resource type"><option value="">Question Paper / Mark Scheme</option><?php foreach ($resourceOptions as $type => $resource): ?><option value="<?=pastpapers_html($type)?>" <?=pastpapers_selected($filters['resource_type'] ?? '', $type)?>><?=pastpapers_html($resource['label'])?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Board</span><select name="exam_board_id" aria-label="Exam board"><option value="">All boards</option><?php foreach ($options['boards'] ?? [] as $board): ?><option value="<?=pastpapers_html($board['board_id'])?>" <?=pastpapers_selected($filters['exam_board_id'] ?? '', $board['board_id'])?>><?=pastpapers_html($board['name'])?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip"><span>Subject</span><select name="syllabus_id" aria-label="Subject"><option value="">All subjects</option><?php foreach ($options['syllabuses'] ?? [] as $syllabus): ?><option value="<?=pastpapers_html($syllabus['syllabus_id'])?>" <?=pastpapers_selected($filters['syllabus_id'] ?? '', $syllabus['syllabus_id'])?>><?=pastpapers_html($syllabus['public_title'])?> · <?=pastpapers_html($syllabus['syllabus_code'])?></option><?php endforeach; ?></select></label>
                <label class="archive-filter-chip archive-filter-chip-optional"><span>Tier</span><select name="variant" aria-label="Tier or variant"><option value="">Any tier</option><?php foreach ($options['variants'] ?? [] as $variant): ?><option value="<?=pastpapers_html($variant)?>" <?=pastpapers_selected($filters['variant'] ?? '', $variant)?>><?=pastpapers_html($variant)?></option><?php endforeach; ?></select></label>
            </div>
            <div class="archive-command-actions"><button type="submit" class="archive-apply-button">Filter</button><a href="<?=pastpapers_html($actionUrl)?>" class="archive-reset-button">Clear<?= $activeCount ? ' ' . pastpapers_html($activeCount) : ''; ?></a></div>
        </form>
        <?php return ob_get_clean();
    }
}

?>
