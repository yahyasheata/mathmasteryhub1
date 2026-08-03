<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/ParentWeeklyReport.php';
require_once 'inc/RecoveryPlanTemplates.php';

$conn = db();
$base = rtrim((string) $baseUrl, '/');
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$courseId = trim((string) ($_GET['course_id'] ?? ''));
$templateId = (int) ($_GET['template_id'] ?? 0);
$courses = mmh_parent_report_courses($conn);
if ($courseId === '') $courseId = (string) ($courses[0]['course_id'] ?? '');
$templates = mmh_recovery_template_list($conn, $courseId, true);
if (!$templateId && $templates) $templateId = (int) $templates[0]['id'];
$template = $templateId > 0 ? mmh_recovery_template_load($conn, $templateId) : null;
if ($template && (string) $template['course_id'] !== $courseId) {
    $courseId = (string) $template['course_id'];
    $templates = mmh_recovery_template_list($conn, $courseId, true);
}
$availableItems = $courseId !== '' ? mmh_learning_journey_visible_items($conn, $courseId) : [];
$availableSections = [];
foreach ($availableItems as $availableItem) {
    $sectionId = trim((string) ($availableItem['section_id'] ?? ''));
    if ($sectionId !== '' && !isset($availableSections[$sectionId])) $availableSections[$sectionId] = ['section_id' => $sectionId, 'title' => (string) ($availableItem['section_title'] ?? 'Section')];
}
$assignmentStudents = $template ? mmh_recovery_template_students($conn, (string) $template['course_id']) : [];
$flash = $_SESSION['recovery_template_flash'] ?? null;
unset($_SESSION['recovery_template_flash']);

$itemOptions = static function (array $items, callable $escape, string $selected = ''): string {
    $html = '<option value="">Choose a course item</option>';
    foreach ($items as $item) {
        $label = (string) ($item['item_title'] ?? 'Course item');
        $kind = mmh_recovery_plan_item_label($item);
        $value = (string) ($item['item_id'] ?? '');
        $html .= '<option value="' . $escape($value) . '"' . ($value !== '' && $value === $selected ? ' selected' : '') . '>' . $escape($label . ' · ' . $kind) . '</option>';
    }
    return $html;
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recovery Plan Templates | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <style>
        :root { --builder-gap: 16px; }
        .builder-page { max-width: 1500px; margin: 55px auto 0; padding: 24px; }
        .builder-shell { display: grid; grid-template-columns: 250px minmax(0, 1fr) 300px; gap: var(--builder-gap); align-items: start; }
        .builder-panel, .builder-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); }
        .builder-panel { padding: 16px; }
        .builder-sidebar { position: sticky; top: 70px; }
        .builder-preview { position: sticky; top: 70px; }
        .builder-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .builder-eyebrow { color: var(--primary); font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; margin: 0 0 4px; }
        .builder-muted { color: var(--text-muted); font-size: .82rem; line-height: 1.5; }
        .builder-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
        .builder-search { position: relative; margin: 12px 0; }
        .builder-search input { padding-left: 34px; }
        .builder-search .fa { position: absolute; top: 11px; left: 12px; color: var(--text-muted); }
        .builder-template-list { display: grid; gap: 8px; max-height: 62vh; overflow: auto; }
        .builder-template { display: block; padding: 11px; border: 1px solid var(--border); border-radius: var(--radius-md); color: var(--text-primary); text-decoration: none; transition: border-color .15s, background .15s; }
        .builder-template:hover, .builder-template:focus-visible, .builder-template.is-selected { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 8%, var(--surface)); }
        .builder-template-title { display: block; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .builder-template-meta { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px; color: var(--text-muted); font-size: .72rem; }
        .builder-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 7px; border-radius: 999px; background: var(--surface-hover); color: var(--text-secondary); font-size: .7rem; font-weight: 800; }
        .builder-badge.active { color: var(--success); }
        .builder-badge.archived { color: var(--text-muted); }
        .builder-card { padding: 18px; margin-bottom: 16px; }
        .builder-fields { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(170px, .8fr); gap: 12px; }
        .builder-field { display: grid; gap: 5px; color: var(--text-secondary); font-size: .76rem; font-weight: 750; }
        .builder-field.full { grid-column: 1 / -1; }
        .builder-field input, .builder-field textarea, .builder-field select { width: 100%; }
        .builder-readonly { display: flex; align-items: center; min-height: 38px; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-md); color: var(--text-primary); background: var(--surface-elevated); font-weight: 700; }
        .builder-savebar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
        .builder-savebar label { display: grid; gap: 5px; color: var(--text-muted); font-size: .76rem; font-weight: 700; }
        .builder-task-list { display: grid; gap: 10px; }
        .builder-task { border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface-elevated); overflow: hidden; }
        .builder-task.is-dragging { opacity: .55; border-color: var(--primary); }
        .builder-task-summary { display: grid; grid-template-columns: 28px minmax(0, 1fr) auto auto; gap: 10px; align-items: center; padding: 13px; cursor: grab; }
        .builder-drag { color: var(--text-muted); cursor: grab; text-align: center; }
        .builder-task-title { min-width: 0; }
        .builder-task-title strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .builder-task-title small { display: block; margin-top: 3px; color: var(--text-muted); font-size: .74rem; }
        .builder-task-time { color: var(--text-muted); font-size: .74rem; white-space: nowrap; }
        .builder-task-toggle { border: 0; background: transparent; color: var(--text-muted); padding: 4px 7px; }
        .builder-task-body { display: none; padding: 0 13px 14px 51px; border-top: 1px solid var(--border); }
        .builder-task.is-open .builder-task-body { display: block; }
        .builder-task-controls { display: grid; grid-template-columns: minmax(0, 1fr) 110px; gap: 10px; padding-top: 12px; }
        .builder-task-options { display: flex; gap: 14px; flex-wrap: wrap; margin: 11px 0; color: var(--text-secondary); font-size: .78rem; }
        .builder-task-options label { display: inline-flex; align-items: center; gap: 5px; }
        .builder-advanced { margin-top: 10px; border-top: 1px solid var(--border); }
        .builder-advanced summary, .builder-overrides summary { padding: 10px 0; color: var(--text-secondary); cursor: pointer; font-size: .78rem; font-weight: 800; }
        .builder-advanced-grid { display: grid; grid-template-columns: 110px 1fr; gap: 10px; }
        .builder-coverage { margin-top: 12px; }
        .builder-coverage-label { display: block; margin-bottom: 6px; color: var(--text-secondary); font-size: .76rem; font-weight: 800; }
        .builder-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .builder-chip { border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text-secondary); padding: 5px 9px; font-size: .72rem; cursor: pointer; }
        .builder-chip.is-selected { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 13%, var(--surface)); color: var(--text-primary); }
        .builder-source { position: absolute !important; width: 1px !important; height: 1px !important; opacity: 0 !important; pointer-events: none !important; }
        .builder-add { display: flex; justify-content: center; margin-top: 12px; }
        .builder-preview-phone { border: 1px solid var(--border); border-radius: 20px; padding: 14px; background: var(--surface-elevated); }
        .builder-preview-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .builder-progress { height: 8px; margin: 14px 0; border-radius: 999px; background: var(--surface-hover); overflow: hidden; }
        .builder-progress span { display: block; width: 0; height: 100%; border-radius: inherit; background: var(--primary); transition: width .2s; }
        .builder-preview-task { padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface); }
        .builder-preview-task + .builder-preview-task { margin-top: 8px; }
        .builder-preview-task.is-locked { opacity: .55; }
        .builder-preview-task small { display: block; margin-top: 4px; color: var(--text-muted); }
        .builder-preview-coverage { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 12px; }
        .builder-preview-coverage span { padding: 4px 7px; border-radius: 999px; background: var(--surface-hover); color: var(--text-secondary); font-size: .69rem; }
        .builder-preview .btn { width: 100%; margin-top: 12px; }
        .builder-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .builder-overrides { margin-top: 16px; padding: 0 13px; border: 1px solid var(--border); border-radius: var(--radius-md); }
        .builder-overrides p { color: var(--text-muted); font-size: .76rem; }
        .builder-wizard { margin-top: 16px; }
        .builder-wizard-steps { display: flex; gap: 8px; margin-bottom: 14px; }
        .builder-wizard-step { flex: 1; padding: 8px; border-radius: var(--radius-md); background: var(--surface-hover); color: var(--text-muted); font-size: .74rem; font-weight: 800; text-align: center; }
        .builder-wizard-step.is-active { background: color-mix(in srgb, var(--primary) 15%, var(--surface)); color: var(--text-primary); }
        .wizard-pane { display: none; }
        .wizard-pane.is-active { display: block; }
        .wizard-students { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; max-height: 210px; overflow: auto; padding: 2px; }
        .wizard-student { display: flex; align-items: center; gap: 7px; padding: 8px; border: 1px solid var(--border); border-radius: var(--radius-md); color: var(--text-secondary); font-size: .76rem; }
        .wizard-actions { display: flex; justify-content: space-between; gap: 8px; margin-top: 14px; }
        .builder-modal { position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center; padding: 18px; background: rgb(0 0 0 / .55); }
        .builder-modal.is-open { display: flex; }
        .builder-modal-card { width: min(680px, 100%); max-height: min(760px, 90vh); overflow: auto; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); box-shadow: var(--shadow-lg); }
        .builder-modal-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .modal-items { display: grid; gap: 6px; margin-top: 12px; }
        .modal-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-elevated); color: var(--text-primary); text-align: left; }
        .modal-item:hover, .modal-item:focus-visible { border-color: var(--primary); }
        .modal-item small { display: block; color: var(--text-muted); }
        @media (max-width: 1160px) { .builder-shell { grid-template-columns: 220px minmax(0, 1fr); } .builder-preview { position: static; grid-column: 2; } }
        @media (max-width: 760px) { .builder-page { margin-top: 45px; padding: 12px; } .builder-shell { display: block; } .builder-sidebar, .builder-preview { position: static; margin-bottom: 12px; } .builder-sidebar { display: none; } .builder-sidebar.is-open { display: block; } .builder-fields, .builder-task-controls, .builder-advanced-grid { grid-template-columns: 1fr; } .builder-task-summary { grid-template-columns: 24px minmax(0, 1fr) auto; } .builder-task-time { grid-column: 2; } .builder-task-body { padding-left: 13px; } .wizard-students { grid-template-columns: 1fr; } .builder-drawer-toggle { display: inline-flex !important; } }
        .builder-drawer-toggle { display: none; }
    </style>
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <main class="builder-page">
            <div class="builder-heading">
                <div>
                    <p class="builder-eyebrow">Student support</p>
                    <h1 class="h3 mb-1">Recovery Plan Templates</h1>
                    <p class="builder-muted mb-0">Build a focused sequence from the course items students already know.</p>
                </div>
                <button class="btn btn-outline-secondary btn-sm builder-drawer-toggle" type="button" data-toggle-sidebar><i class="fa fa-layer-group" aria-hidden="true"></i> Templates</button>
            </div>
            <?php if (is_array($flash)): ?><div class="alert alert-<?= !empty($flash['ok']) ? 'success' : 'danger' ?>"><?= $escape($flash['message'] ?? '') ?></div><?php endif; ?>
            <div class="builder-shell">
                <aside class="builder-sidebar" id="builder-sidebar">
                    <section class="builder-panel">
                        <div class="builder-toolbar"><strong>Recovery Plan Templates</strong><form method="post" action="<?= $escape($base . '/admin/requests/recovery-plan-template/save') ?>"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="create"><input type="hidden" name="course_id" value="<?= $escape($courseId) ?>"><button class="btn btn-primary btn-sm" type="submit" title="New template"><i class="fa fa-plus" aria-hidden="true"></i><span class="visually-hidden">New template</span></button></form></div>
                        <div class="builder-search"><i class="fa fa-search" aria-hidden="true"></i><input class="form-control form-control-sm" type="search" placeholder="Search templates" data-template-search></div>
                        <div class="builder-template-list" data-template-list>
                            <?php foreach ($templates as $candidate): ?>
                                <a class="builder-template <?= $templateId === (int) $candidate['id'] ? 'is-selected' : '' ?>" href="<?= $escape($base . '/admin/recovery-plan-templates?course_id=' . rawurlencode($courseId) . '&template_id=' . (int) $candidate['id']) ?>" data-template-card data-template-name="<?= $escape(strtolower($candidate['title'] . ' ' . ($candidate['course_title'] ?? ''))) ?>">
                                    <span class="builder-template-title"><?= $escape($candidate['title']) ?></span>
                                    <span class="builder-template-meta"><span><?= $escape($candidate['course_title'] ?? $courseId) ?></span><span>·</span><span><?= (int) ($candidate['task_count'] ?? 0) ?> tasks</span><span>·</span><span><?= (int) ($candidate['assigned_count'] ?? 0) ?> assigned</span></span>
                                    <span class="builder-template-meta"><span class="builder-badge <?= ($candidate['status'] ?? '') === 'archived' ? 'archived' : 'active' ?>"><i class="fa <?= ($candidate['status'] ?? '') === 'archived' ? 'fa-archive' : 'fa-circle-check' ?>" aria-hidden="true"></i><?= $escape(ucfirst($candidate['status'] ?? 'active')) ?></span></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$templates): ?><p class="builder-muted">No templates for this course yet.</p><?php endif; ?>
                        </div>
                    </section>
                </aside>
                <section>
                    <?php if (!$template): ?>
                        <section class="builder-card"><h2 class="h5">Start with a template</h2><p class="builder-muted mb-0">Choose a course, then create a reusable recovery sequence from published course items.</p></section>
                    <?php else: ?>
                        <form method="post" action="<?= $escape($base . '/admin/requests/recovery-plan-template/save') ?>" id="template-form">
                            <input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                            <section class="builder-card">
                                <div class="builder-heading"><div><p class="builder-eyebrow">Template details</p><h2 class="h4 mb-0"><?= $escape($template['title']) ?></h2></div><span class="builder-badge <?= ($template['status'] ?? '') === 'archived' ? 'archived' : 'active' ?>"><i class="fa <?= ($template['status'] ?? '') === 'archived' ? 'fa-archive' : 'fa-circle-check' ?>" aria-hidden="true"></i><?= $escape(ucfirst($template['status'] ?? 'active')) ?></span></div>
                                <div class="builder-fields">
                                    <label class="builder-field"><span>Template name</span><input class="form-control" name="title" value="<?= $escape($template['title']) ?>" maxlength="180" required></label>
                                    <label class="builder-field"><span>Course</span><div class="builder-readonly"><i class="fa fa-book-open me-2" aria-hidden="true"></i><?= $escape($template['course_title'] ?? $courseId) ?></div></label>
                                    <label class="builder-field full"><span>Description</span><textarea class="form-control" name="description" maxlength="1000" rows="2" placeholder="What is this plan helping the student catch up on?"><?= $escape($template['description'] ?? '') ?></textarea></label>
                                    <div class="builder-field"><span>Estimated completion time</span><div class="builder-readonly" data-total-time>0 min</div></div>
                                    <div class="builder-field"><span>Recommended duration</span><div class="builder-readonly" data-recommended-duration>Based on task time</div></div>
                                    <div class="builder-field"><span>Difficulty</span><div class="builder-readonly">Set by the teacher in the task sequence</div></div>
                                    <div class="builder-field"><span>Target students</span><div class="builder-readonly"><?= count($assignmentStudents) ?> enrolled students available</div></div>
                                </div>
                                <div class="builder-savebar"><label>When assigned students exist<select class="form-control form-control-sm" name="apply_mode"><option value="future">Apply only to future assignments</option><option value="not_started">Apply to students who have not started</option><option value="all">Apply to all assigned students</option></select></label><button class="btn btn-primary" type="submit"><i class="fa fa-check" aria-hidden="true"></i> Save template</button></div>
                            </section>
                            <section class="builder-card">
                                <div class="builder-heading"><div><p class="builder-eyebrow">Task builder</p><h2 class="h5 mb-1">Student sequence</h2><p class="builder-muted mb-0">Drag tasks into the order students should follow.</p></div><button class="btn btn-primary btn-sm" type="button" data-open-item-modal><i class="fa fa-plus" aria-hidden="true"></i> Add course item</button></div>
                                <div class="builder-task-list" id="builder-task-list">
                                    <?php foreach (($template['items'] ?? []) as $index => $item):
                                        $coverageItemIds = []; $coverageSectionIds = []; $coverageTypes = []; $coverageTopics = [];
                                        foreach (($item['coverage'] ?? []) as $coverage) { if (($coverage['covered_item_id'] ?? '') !== '') $coverageItemIds[] = (string) $coverage['covered_item_id']; if (($coverage['covered_section_id'] ?? '') !== '') $coverageSectionIds[] = (string) $coverage['covered_section_id']; if (($coverage['coverage_type'] ?? '') !== '' && !in_array($coverage['coverage_type'], ['item', 'section', 'topic'], true)) $coverageTypes[] = (string) $coverage['coverage_type']; if (($coverage['topic_label'] ?? '') !== '') $coverageTopics[] = (string) $coverage['topic_label']; }
                                        $itemTitle = (string) ($item['item_title'] ?? 'Choose a course item');
                                    ?>
                                        <article class="builder-task" data-task-card draggable="true">
                                            <div class="builder-task-summary" data-task-toggle><span class="builder-drag" title="Drag to reorder"><i class="fa fa-grip-vertical" aria-hidden="true"></i></span><div class="builder-task-title"><strong data-task-title><?= $escape($itemTitle) ?></strong><small data-task-kind><?= $escape($item['section_title'] ?: 'Course item') ?> · <?= $escape(mmh_recovery_plan_item_label($item)) ?></small></div><span class="builder-task-time" data-task-time><?= $item['estimated_duration'] === null ? 'No estimate' : (int) $item['estimated_duration'] . ' min' ?></span><button class="builder-task-toggle" type="button" aria-label="Expand task"><i class="fa fa-chevron-down" aria-hidden="true"></i></button></div>
                                            <div class="builder-task-body">
                                                <div class="builder-task-controls"><label class="builder-field"><span>Course item</span><select class="form-control form-control-sm task-item-select" name="items[<?= $index ?>][item_id]" data-task-item><?= $itemOptions($availableItems, $escape, (string) ($item['item_id'] ?? '')) ?></select></label><label class="builder-field"><span>Estimated time</span><input class="form-control form-control-sm task-duration" type="number" name="items[<?= $index ?>][estimated_duration]" min="0" max="1440" value="<?= $item['estimated_duration'] === null ? '' : (int) $item['estimated_duration'] ?>" placeholder="Minutes"></label></div>
                                                <div class="builder-task-options"><label><input type="hidden" name="items[<?= $index ?>][required]" value="0"><input type="checkbox" name="items[<?= $index ?>][required]" value="1" <?= !empty($item['is_required']) ? 'checked' : '' ?> data-task-required> Required</label><label><input type="hidden" name="items[<?= $index ?>][locked_until_previous]" value="0"><input type="checkbox" name="items[<?= $index ?>][locked_until_previous]" value="1" <?= !empty($item['locked_until_previous']) ? 'checked' : '' ?> data-task-locked> Lock previous</label></div>
                                                <label class="builder-field"><span>Teacher note</span><textarea class="form-control form-control-sm task-note" name="items[<?= $index ?>][teacher_note]" maxlength="1000" rows="2" placeholder="Give the student a helpful instruction"><?= $escape($item['teacher_note'] ?? '') ?></textarea></label>
                                                <details class="builder-advanced"><summary>Advanced settings</summary><div class="builder-advanced-grid"><label class="builder-field"><span>Weight</span><input class="form-control form-control-sm" type="number" name="items[<?= $index ?>][weight]" min="0" step="0.01" value="<?= $item['weight'] === null ? '' : $escape($item['weight']) ?>"></label><div class="builder-field"><span>Task order</span><div class="builder-readonly" data-task-position><?= $index + 1 ?></div></div></div><div class="builder-coverage"><span class="builder-coverage-label">Covered by this task</span><div class="builder-chips" data-chip-list="items"><?php foreach ($availableItems as $available): ?><button type="button" class="builder-chip <?= in_array((string) $available['item_id'], $coverageItemIds, true) ? 'is-selected' : '' ?>" data-chip-group="items" data-chip-value="<?= $escape($available['item_id']) ?>" aria-pressed="<?= in_array((string) $available['item_id'], $coverageItemIds, true) ? 'true' : 'false' ?>"><?= $escape($available['item_title']) ?></button><?php endforeach; ?></div><select class="builder-source" name="coverage[<?= $index ?>][items][]" multiple data-source-group="items"><?php foreach ($availableItems as $available): ?><option value="<?= $escape($available['item_id']) ?>" <?= in_array((string) $available['item_id'], $coverageItemIds, true) ? 'selected' : '' ?>><?= $escape($available['item_title']) ?></option><?php endforeach; ?></select><div class="builder-chips mt-2" data-chip-list="sections"><?php foreach ($availableSections as $availableSection): ?><button type="button" class="builder-chip <?= in_array((string) $availableSection['section_id'], $coverageSectionIds, true) ? 'is-selected' : '' ?>" data-chip-group="sections" data-chip-value="<?= $escape($availableSection['section_id']) ?>" aria-pressed="<?= in_array((string) $availableSection['section_id'], $coverageSectionIds, true) ? 'true' : 'false' ?>"><?= $escape($availableSection['title']) ?></button><?php endforeach; ?></div><select class="builder-source" name="coverage[<?= $index ?>][sections][]" multiple data-source-group="sections"><?php foreach ($availableSections as $availableSection): ?><option value="<?= $escape($availableSection['section_id']) ?>" <?= in_array((string) $availableSection['section_id'], $coverageSectionIds, true) ? 'selected' : '' ?>><?= $escape($availableSection['title']) ?></option><?php endforeach; ?></select><div class="builder-chips mt-2"><button type="button" class="builder-chip <?= in_array('homework_requirement', $coverageTypes, true) ? 'is-selected' : '' ?>" data-chip-group="types" data-chip-value="homework_requirement" aria-pressed="<?= in_array('homework_requirement', $coverageTypes, true) ? 'true' : 'false' ?>">Missing homework</button><button type="button" class="builder-chip <?= in_array('recording_requirement', $coverageTypes, true) ? 'is-selected' : '' ?>" data-chip-group="types" data-chip-value="recording_requirement" aria-pressed="<?= in_array('recording_requirement', $coverageTypes, true) ? 'true' : 'false' ?>">Missed recording</button></div><div class="builder-field mt-2"><span>Covered topic</span><input class="form-control form-control-sm" name="coverage[<?= $index ?>][topic]" value="<?= $escape(implode(', ', $coverageTopics)) ?>" maxlength="255" placeholder="e.g. Workshop preparation"></div></div></details>
                                                <button type="button" class="btn btn-outline-danger btn-sm mt-3" data-remove-task><i class="fa fa-trash" aria-hidden="true"></i> Remove task</button>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                                <div class="builder-add"><button class="btn btn-outline-secondary btn-sm" type="button" data-open-item-modal><i class="fa fa-plus" aria-hidden="true"></i> Add course item</button></div>
                            </section>
                            <details class="builder-overrides"><summary><i class="fa fa-sliders" aria-hidden="true"></i> Recovery Plan overrides</summary><p>These overrides apply only inside this Recovery Plan. The current model keeps the course’s original submission, grade, attendance, and completion rules unchanged.</p><label class="builder-task-options"><input type="checkbox" disabled> Allow submission after original homework closes</label><label class="builder-task-options"><input type="checkbox" disabled> Unlock a recording outside the normal sequence</label><label class="builder-task-options"><input type="checkbox" disabled> Add a recovery due date or teacher instruction</label></details>
                        </form>
                        <section class="builder-card builder-wizard" id="assignment-wizard">
                            <div class="builder-heading"><div><p class="builder-eyebrow">Assign template</p><h2 class="h5 mb-1">Send this plan to students</h2><p class="builder-muted mb-0">Each student receives an independent copy.</p></div><span class="builder-badge"><i class="fa fa-users" aria-hidden="true"></i> <?= count($assignmentStudents) ?> enrolled</span></div>
                            <div class="builder-wizard-steps"><span class="builder-wizard-step is-active" data-wizard-step-label="1">1 · Students</span><span class="builder-wizard-step" data-wizard-step-label="2">2 · Options</span><span class="builder-wizard-step" data-wizard-step-label="3">3 · Review</span></div>
                            <form method="post" action="<?= $escape($base . '/admin/requests/recovery-plan-template/assign') ?>" id="assignment-form"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                                <div class="wizard-pane is-active" data-wizard-pane="1"><div class="builder-search"><i class="fa fa-search" aria-hidden="true"></i><input class="form-control form-control-sm" type="search" placeholder="Search enrolled students" data-student-search></div><div class="builder-actions"><button class="btn btn-outline-secondary btn-sm" type="button" data-select-all>Select all</button><span class="builder-muted" data-selected-count>0 selected</span></div><div class="wizard-students" data-student-list><?php foreach ($assignmentStudents as $student): ?><label class="wizard-student" data-student-name="<?= $escape(strtolower(($student['full_name'] ?? '') . ' ' . ($student['username'] ?? ''))) ?>"><input type="checkbox" name="student_ids[]" value="<?= (int) $student['user_id'] ?>" data-student-checkbox><span><?= $escape($student['full_name'] ?: $student['username']) ?><small><?= $escape($student['username']) ?></small></span></label><?php endforeach; ?><?php if (!$assignmentStudents): ?><span class="builder-muted">No enrolled students found.</span><?php endif; ?></div><div class="wizard-actions"><span></span><button class="btn btn-primary btn-sm" type="button" data-wizard-next>Next: assignment options <i class="fa fa-arrow-right" aria-hidden="true"></i></button></div></div>
                                <div class="wizard-pane" data-wizard-pane="2"><div class="builder-fields"><div class="builder-field"><span>Assignment behavior</span><div class="builder-readonly"><i class="fa fa-shield-halved me-2" aria-hidden="true"></i>Keep existing progress</div></div><div class="builder-field"><span>Schedule</span><div class="builder-readonly"><i class="fa fa-bolt me-2" aria-hidden="true"></i>Assign immediately</div></div></div><p class="builder-muted mt-3 mb-0">Existing active plans are kept safe and are not replaced by this assignment.</p><div class="wizard-actions"><button class="btn btn-outline-secondary btn-sm" type="button" data-wizard-prev><i class="fa fa-arrow-left" aria-hidden="true"></i> Back</button><button class="btn btn-primary btn-sm" type="button" data-wizard-next>Next: review <i class="fa fa-arrow-right" aria-hidden="true"></i></button></div></div>
                                <div class="wizard-pane" data-wizard-pane="3"><div class="builder-fields"><div class="builder-field"><span>Students</span><div class="builder-readonly" data-review-students>0 selected</div></div><div class="builder-field"><span>Tasks</span><div class="builder-readonly"><?= (int) ($template['items'] ? count($template['items']) : 0) ?> course items</div></div><div class="builder-field full"><span>Coverage</span><div class="builder-readonly" data-review-coverage>Coverage follows each task’s selected course items and sections.</div></div></div><div class="wizard-actions"><button class="btn btn-outline-secondary btn-sm" type="button" data-wizard-prev><i class="fa fa-arrow-left" aria-hidden="true"></i> Back</button><button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-paper-plane" aria-hidden="true"></i> Assign template</button></div></div>
                            </form>
                        </section>
                        <div class="builder-actions"><form method="post" action="<?= $escape($base . '/admin/requests/recovery-plan-template/save') ?>"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>"><button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fa fa-copy" aria-hidden="true"></i> Duplicate</button></form><?php if (($template['status'] ?? '') !== 'archived'): ?><form method="post" action="<?= $escape($base . '/admin/requests/recovery-plan-template/save') ?>"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>"><button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fa fa-archive" aria-hidden="true"></i> Archive</button></form><?php endif; ?><?php if ((int) ($template['assigned_count'] ?? 0) === 0): ?><form method="post" action="<?= $escape($base . '/admin/requests/recovery-plan-template/save') ?>" onsubmit="return confirm('Delete this unused template?');"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa fa-trash" aria-hidden="true"></i> Delete</button></form><?php endif; ?></div>
                    <?php endif; ?>
                </section>
                <?php if ($template): ?><aside class="builder-preview"><section class="builder-panel"><div class="builder-heading"><div><p class="builder-eyebrow">Live preview</p><h2 class="h5 mb-1">Student view</h2></div><i class="fa fa-eye" aria-hidden="true"></i></div><div class="builder-preview-phone"><div class="builder-preview-header"><strong><?= $escape($template['title']) ?></strong><span class="builder-badge">0%</span></div><div class="builder-progress"><span data-preview-progress></span></div><div class="builder-muted" data-preview-current>Current task</div><div data-preview-tasks></div><div class="builder-preview-coverage" data-preview-coverage></div><button class="btn btn-primary btn-sm" type="button"><i class="fa fa-play" aria-hidden="true"></i> Continue</button></div><p class="builder-muted mt-3 mb-0">This preview uses the task order and lock rules above. Student completion is never fabricated here.</p></section></aside><?php endif; ?>
            </div>
        </main>
    </div>
</div>
<?php if ($template): ?>
<div class="builder-modal" data-item-modal role="dialog" aria-modal="true" aria-labelledby="item-modal-title"><div class="builder-modal-card"><div class="builder-modal-head"><div><h2 class="h5 mb-1" id="item-modal-title">Add course item</h2><p class="builder-muted mb-0">Choose a published item from <?= $escape($template['course_title'] ?? $courseId) ?>.</p></div><button type="button" class="btn btn-outline-secondary btn-sm" data-close-item-modal aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button></div><div class="builder-fields mt-3"><label class="builder-field"><span>Search by lecture, section, homework, recording, notes, video, workshop or file</span><input class="form-control" type="search" data-item-search placeholder="Search course items"></label><label class="builder-field"><span>Filter by type</span><select class="form-control" data-item-filter><option value="">All published items</option><?php $types = []; foreach ($availableItems as $available) { $type = mmh_recovery_plan_item_label($available); if (!in_array($type, $types, true)) $types[] = $type; } foreach ($types as $type): ?><option value="<?= $escape(strtolower($type)) ?>"><?= $escape($type) ?></option><?php endforeach; ?></select></label></div><div class="modal-items" data-modal-items><?php foreach ($availableItems as $available): ?><button type="button" class="modal-item" data-item-choice data-item-id="<?= $escape($available['item_id']) ?>" data-item-search-text="<?= $escape(strtolower(($available['item_title'] ?? '') . ' ' . ($available['section_title'] ?? '') . ' ' . mmh_recovery_plan_item_label($available))) ?>" data-item-type="<?= $escape(strtolower(mmh_recovery_plan_item_label($available))) ?>"><i class="fa fa-circle-play" aria-hidden="true"></i><span><strong><?= $escape($available['item_title']) ?></strong><small><?= $escape(($available['section_title'] ?? 'Course item') . ' · ' . mmh_recovery_plan_item_label($available)) ?></small></span></button><?php endforeach; ?></div></div></div>
<template id="task-template"><article class="builder-task" data-task-card draggable="true"><div class="builder-task-summary" data-task-toggle><span class="builder-drag"><i class="fa fa-grip-vertical" aria-hidden="true"></i></span><div class="builder-task-title"><strong data-task-title>Choose a course item</strong><small data-task-kind>Course item</small></div><span class="builder-task-time" data-task-time>No estimate</span><button class="builder-task-toggle" type="button" aria-label="Expand task"><i class="fa fa-chevron-down" aria-hidden="true"></i></button></div><div class="builder-task-body"><div class="builder-task-controls"><label class="builder-field"><span>Course item</span><select class="form-control form-control-sm task-item-select" name="items[__INDEX__][item_id]" data-task-item><?= $itemOptions($availableItems, $escape) ?></select></label><label class="builder-field"><span>Estimated time</span><input class="form-control form-control-sm task-duration" type="number" name="items[__INDEX__][estimated_duration]" min="0" max="1440" placeholder="Minutes"></label></div><div class="builder-task-options"><label><input type="hidden" name="items[__INDEX__][required]" value="0"><input type="checkbox" name="items[__INDEX__][required]" value="1" checked data-task-required> Required</label><label><input type="hidden" name="items[__INDEX__][locked_until_previous]" value="0"><input type="checkbox" name="items[__INDEX__][locked_until_previous]" value="1" data-task-locked> Lock previous</label></div><label class="builder-field"><span>Teacher note</span><textarea class="form-control form-control-sm task-note" name="items[__INDEX__][teacher_note]" maxlength="1000" rows="2"></textarea></label><details class="builder-advanced"><summary>Advanced settings</summary><div class="builder-advanced-grid"><label class="builder-field"><span>Weight</span><input class="form-control form-control-sm" type="number" name="items[__INDEX__][weight]" min="0" step="0.01"></label><div class="builder-field"><span>Task order</span><div class="builder-readonly" data-task-position></div></div></div><div class="builder-coverage"><span class="builder-coverage-label">Covered by this task</span><div class="builder-chips" data-chip-list="items"><?php foreach ($availableItems as $available): ?><button type="button" class="builder-chip" data-chip-group="items" data-chip-value="<?= $escape($available['item_id']) ?>" aria-pressed="false"><?= $escape($available['item_title']) ?></button><?php endforeach; ?></div><select class="builder-source" name="coverage[__INDEX__][items][]" multiple data-source-group="items"><?php foreach ($availableItems as $available): ?><option value="<?= $escape($available['item_id']) ?>"><?= $escape($available['item_title']) ?></option><?php endforeach; ?></select><div class="builder-chips mt-2" data-chip-list="sections"><?php foreach ($availableSections as $availableSection): ?><button type="button" class="builder-chip" data-chip-group="sections" data-chip-value="<?= $escape($availableSection['section_id']) ?>" aria-pressed="false"><?= $escape($availableSection['title']) ?></button><?php endforeach; ?></div><select class="builder-source" name="coverage[__INDEX__][sections][]" multiple data-source-group="sections"><?php foreach ($availableSections as $availableSection): ?><option value="<?= $escape($availableSection['section_id']) ?>"><?= $escape($availableSection['title']) ?></option><?php endforeach; ?></select><div class="builder-chips mt-2"><button type="button" class="builder-chip" data-chip-group="types" data-chip-value="homework_requirement" aria-pressed="false">Missing homework</button><button type="button" class="builder-chip" data-chip-group="types" data-chip-value="recording_requirement" aria-pressed="false">Missed recording</button></div><div class="builder-field mt-2"><span>Covered topic</span><input class="form-control form-control-sm" name="coverage[__INDEX__][topic]" maxlength="255"></div></div></details><button type="button" class="btn btn-outline-danger btn-sm mt-3" data-remove-task><i class="fa fa-trash" aria-hidden="true"></i> Remove task</button></div></article></template>
<script>
(function () {
    var list = document.getElementById('builder-task-list'), form = document.getElementById('template-form'), taskTemplate = document.getElementById('task-template'), modal = document.querySelector('[data-item-modal]');
    if (!list || !form) return;
    function tasks() { return Array.prototype.slice.call(list.querySelectorAll('[data-task-card]')); }
    function renumber() { tasks().forEach(function (card, index) { card.querySelector('[data-task-position]').textContent = index + 1; card.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace(/^(items|coverage)\[[^\]]+\]/, function (_, group) { return group + '[' + index + ']'; }); }); }); refreshPreview(); }
    function selectedOption(card) { var select = card.querySelector('[data-task-item]'); return select ? select.options[select.selectedIndex] : null; }
    function updateCard(card) { var option = selectedOption(card), title = card.querySelector('[data-task-title]'), kind = card.querySelector('[data-task-kind]'), duration = card.querySelector('[data-task-time]'); if (option && option.value) { var text = option.textContent.split(' · '); title.textContent = text[0]; kind.textContent = text.slice(1).join(' · ') || 'Course item'; } else { title.textContent = 'Choose a course item'; kind.textContent = 'Course item'; } duration.textContent = card.querySelector('.task-duration').value ? card.querySelector('.task-duration').value + ' min' : 'No estimate'; }
    function refreshPreview() { var cards = tasks(), preview = document.querySelector('[data-preview-tasks]'), current = document.querySelector('[data-preview-current]'), progress = document.querySelector('[data-preview-progress]'), total = 0, currentTask = null; if (!preview) return; preview.innerHTML = ''; cards.forEach(function (card, index) { updateCard(card); var option = selectedOption(card), title = option && option.value ? option.textContent.split(' · ')[0] : 'Choose a course item', locked = card.querySelector('[data-task-locked]').checked && index > 0; total += parseInt(card.querySelector('.task-duration').value || '0', 10); if (!currentTask && option && option.value) currentTask = title; var task = document.createElement('div'); task.className = 'builder-preview-task' + (locked ? ' is-locked' : ''); task.innerHTML = '<strong>' + title.replace(/[&<>]/g, '') + '</strong><small>' + (locked ? 'Locked until the previous task is complete' : (index === 0 ? 'Current task' : 'Upcoming task')) + '</small>'; preview.appendChild(task); }); if (current) current.textContent = currentTask ? 'Current task · ' + currentTask : 'Add a task to preview the student journey'; if (progress) progress.style.width = '0%'; var time = document.querySelector('[data-total-time]'); if (time) time.textContent = total + ' min'; }
    function openModal() { if (modal) { modal.classList.add('is-open'); var search = modal.querySelector('[data-item-search]'); if (search) { search.value = ''; search.focus(); } filterModal(); } }
    function closeModal() { if (modal) modal.classList.remove('is-open'); }
    function filterModal() { if (!modal) return; var q = (modal.querySelector('[data-item-search]').value || '').toLowerCase(), type = modal.querySelector('[data-item-filter]').value; modal.querySelectorAll('[data-item-choice]').forEach(function (item) { item.hidden = (q && item.getAttribute('data-item-search-text').indexOf(q) === -1) || (type && item.getAttribute('data-item-type') !== type); }); }
    document.addEventListener('click', function (event) { var toggle = event.target.closest('[data-task-toggle]'); if (toggle && list.contains(toggle)) { toggle.closest('[data-task-card]').classList.toggle('is-open'); return; } if (event.target.closest('[data-remove-task]')) { event.target.closest('[data-task-card]').remove(); renumber(); return; } if (event.target.closest('[data-open-item-modal]')) { openModal(); return; } if (event.target.closest('[data-close-item-modal]')) { closeModal(); return; } var choice = event.target.closest('[data-item-choice]'); if (choice) { var fragment = taskTemplate.content.cloneNode(true), card = fragment.querySelector('[data-task-card]'); card.querySelector('[data-task-item]').value = choice.getAttribute('data-item-id'); list.appendChild(fragment); closeModal(); renumber(); card.classList.add('is-open'); return; } var chip = event.target.closest('[data-chip-group]'); if (chip && list.contains(chip)) { var card = chip.closest('[data-task-card]'), group = chip.getAttribute('data-chip-group'), source = card.querySelector('[data-source-group="' + group + '"]'); if (source) { var option = Array.prototype.slice.call(source.options).find(function (candidate) { return candidate.value === chip.getAttribute('data-chip-value'); }); if (option) option.selected = !option.selected; chip.classList.toggle('is-selected', option && option.selected); chip.setAttribute('aria-pressed', option && option.selected ? 'true' : 'false'); } } });
    document.addEventListener('input', function (event) { if (event.target.matches('[data-item-search], [data-item-filter]')) filterModal(); if (event.target.matches('.task-duration, .task-note, [data-task-item]')) refreshPreview(); if (event.target.matches('[data-template-search]')) { var q = event.target.value.toLowerCase(); document.querySelectorAll('[data-template-card]').forEach(function (card) { card.hidden = card.getAttribute('data-template-name').indexOf(q) === -1; }); } if (event.target.matches('[data-student-search]')) { var q = event.target.value.toLowerCase(); document.querySelectorAll('[data-student-name]').forEach(function (student) { student.hidden = student.getAttribute('data-student-name').indexOf(q) === -1; }); } });
    document.addEventListener('change', function (event) { if (event.target.matches('.task-duration, [data-task-item], [data-task-required], [data-task-locked]')) refreshPreview(); if (event.target.matches('[data-student-checkbox]')) updateSelected(); });
    list.addEventListener('dragstart', function (event) { var card = event.target.closest('[data-task-card]'); if (card) { card.classList.add('is-dragging'); event.dataTransfer.setData('text/plain', 'reorder'); } });
    list.addEventListener('dragend', function (event) { var card = event.target.closest('[data-task-card]'); if (card) card.classList.remove('is-dragging'); });
    list.addEventListener('dragover', function (event) { event.preventDefault(); var moving = list.querySelector('.is-dragging'), target = event.target.closest('[data-task-card]'); if (!moving || !target || moving === target) return; var box = target.getBoundingClientRect(); list.insertBefore(moving, event.clientY < box.top + box.height / 2 ? target : target.nextSibling); renumber(); });
    document.querySelectorAll('[data-toggle-sidebar]').forEach(function (button) { button.addEventListener('click', function () { document.getElementById('builder-sidebar').classList.toggle('is-open'); }); });
    function updateSelected() { var selected = document.querySelectorAll('[data-student-checkbox]:checked').length; document.querySelectorAll('[data-selected-count], [data-review-students]').forEach(function (node) { node.textContent = selected + ' selected'; }); }
    document.querySelector('[data-select-all]')?.addEventListener('click', function () { document.querySelectorAll('[data-student-checkbox]:not(:disabled)').forEach(function (box) { box.checked = true; }); updateSelected(); });
    var wizardStep = 1; function showWizard(step) { wizardStep = step; document.querySelectorAll('[data-wizard-pane]').forEach(function (pane) { pane.classList.toggle('is-active', pane.getAttribute('data-wizard-pane') === String(step)); }); document.querySelectorAll('[data-wizard-step-label]').forEach(function (label) { label.classList.toggle('is-active', parseInt(label.getAttribute('data-wizard-step-label'), 10) === step); }); updateSelected(); }
    document.addEventListener('click', function (event) { if (event.target.closest('[data-wizard-next]')) { if (wizardStep === 1 && !document.querySelectorAll('[data-student-checkbox]:checked').length) { alert('Select at least one enrolled student.'); return; } showWizard(Math.min(3, wizardStep + 1)); } if (event.target.closest('[data-wizard-prev]')) showWizard(Math.max(1, wizardStep - 1)); });
    function syncTypeChip(chip) { var card = chip.closest('[data-task-card]'), itemInput = card && card.querySelector('[name^="items["]'); if (!card || !itemInput) return; var match = itemInput.name.match(/^items\[([^\]]+)\]/), index = match ? match[1] : '0', value = chip.getAttribute('data-chip-value'), existing = card.querySelectorAll('input[data-type-coverage="' + value + '"]'); if (chip.classList.contains('is-selected')) { if (!existing.length) { var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'coverage[' + index + '][types][]'; hidden.value = value; hidden.setAttribute('data-type-coverage', value); card.appendChild(hidden); } } else { existing.forEach(function (input) { input.remove(); }); } }
    document.addEventListener('click', function (event) { var chip = event.target.closest('[data-chip-group="types"]'); if (chip && list.contains(chip)) syncTypeChip(chip); });
    document.querySelectorAll('[data-chip-group="types"].is-selected').forEach(syncTypeChip);
    renumber(); updateSelected();
}());
</script>
<script>
(function () {
    function refreshCoveragePreview() { var output = document.querySelector('[data-preview-coverage]'), card = document.querySelector('[data-task-card]'); if (!output || !card) return; output.innerHTML = ''; card.querySelectorAll('.builder-chip.is-selected').forEach(function (chip) { var tag = document.createElement('span'); tag.textContent = chip.textContent; output.appendChild(tag); }); var topic = card.querySelector('input[name*="[topic]"]'); if (topic && topic.value.trim()) { var tag = document.createElement('span'); tag.textContent = topic.value.trim(); output.appendChild(tag); } }
    document.addEventListener('click', function (event) { if (event.target.closest('[data-chip-group]')) window.setTimeout(refreshCoveragePreview, 0); });
    document.addEventListener('input', function (event) { if (event.target.matches('input[name*="[topic]"]')) refreshCoveragePreview(); });
    refreshCoveragePreview();
}());
</script>
<?php endif; ?>
</body>
</html>
