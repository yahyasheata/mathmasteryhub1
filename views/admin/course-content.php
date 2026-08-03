<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';

$username = $_SESSION['admin'] ?? '';
$pageName = 'courses';
$subPageName = 'courses';
$conn = db();
$course_id = trim((string) ($courseId ?? ''));

if ($course_id === '') {
    http_response_code(404);
    exit('Course not found.');
}

$course_stmt = $conn->prepare('SELECT course_id, course_title, course_status FROM courses WHERE course_id = ? LIMIT 1');
if (!$course_stmt) {
    http_response_code(500);
    exit('Unable to load the course.');
}
$course_stmt->bind_param('s', $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

if (!$course) {
    http_response_code(404);
    exit('Course not found.');
}

$count_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM course_items WHERE course_id = ?');
$count_stmt->bind_param('s', $course_id);
$count_stmt->execute();
$lesson_count = (int) (($count_stmt->get_result()->fetch_assoc()['total'] ?? 0));
$count_stmt->close();

$section_count = 0;
$section_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM course_sections WHERE course_id = ?');
if ($section_stmt) {
    $section_stmt->bind_param('s', $course_id);
    $section_stmt->execute();
    $section_count = (int) (($section_stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $section_stmt->close();
}

$safe_course_id = htmlspecialchars($course_id, ENT_QUOTES, 'UTF-8');
$safe_course_title = htmlspecialchars((string) $course['course_title'], ENT_QUOTES, 'UTF-8');
$admin_base = rtrim((string) $baseUrl, '/') . '/admin/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?=htmlspecialchars($admin_base, ENT_QUOTES, 'UTF-8');?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lesson Manager | <?=$site_name;?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <link rel="stylesheet" href="<?=$baseUrl?>/resources/css/course-manager.css">
    <script src="<?=$baseUrl?>/resources/js/jquery-ui.min.js"></script>
    <script src="<?=$baseUrl?>/resources/js/jquery.ui.touch-punch.min.js"></script>
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active course-manager-main">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <main class="course-manager-page" data-course-id="<?=$safe_course_id;?>">
            <div class="course-manager-page-header">
                <div>
                    <a class="course-manager-back-link" href="courses"><i class="fas fa-arrow-left ds-icon ds-icon-sm" aria-hidden="true"></i> Back to courses</a>
                    <div class="course-manager-eyebrow">Course content</div>
                    <h1><?=$safe_course_title;?></h1>
                    <p>Manage sections and lessons from one focused workspace.</p>
                </div>
                <div class="course-manager-summary" aria-label="Course content summary">
                    <span><strong><?=$lesson_count;?></strong> lessons</span>
                    <span><strong><?=$section_count;?></strong> sections</span>
                </div>
            </div>

            <section class="course-manager-toolbar" aria-label="Lesson management tools">
                <div class="course-manager-toolbar-primary">
                    <button type="button" class="btn btn-primary" data-manager-action="choose-content"><i class="fas fa-plus ds-icon ds-icon-sm" aria-hidden="true"></i> Add Content</button>
                    <button type="button" class="btn btn-outline-primary" data-manager-action="add-section"><i class="fas fa-folder-plus ds-icon ds-icon-sm" aria-hidden="true"></i> Add Section</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-manager-action="section-integrity"><i class="fas fa-link ds-icon ds-icon-sm" aria-hidden="true"></i> Section Integrity</button>
                    <div class="course-manager-toolbar-divider" aria-hidden="true"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-manager-action="expand-all">Expand all</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-manager-action="collapse-all">Collapse all</button>
                </div>
                <div class="course-manager-toolbar-search">
                    <label class="visually-hidden" for="lesson-manager-search">Search lessons</label>
                    <i class="fas fa-search ds-icon ds-icon-sm" aria-hidden="true"></i>
                    <input id="lesson-manager-search" type="search" class="form-control" placeholder="Search lesson titles">
                </div>
            </section>

            <section class="course-manager-filter-bar" aria-label="Lesson filters">
                <label>Status
                    <select id="lesson-manager-status" class="form-control form-control-sm">
                        <option value="">All statuses</option>
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                    </select>
                </label>
                <label>Lesson type
                    <select id="lesson-manager-type" class="form-control form-control-sm">
                        <option value="">All lesson types</option>
                    </select>
                </label>
                <label>Visibility
                    <select id="lesson-manager-visibility" class="form-control form-control-sm">
                        <option value="">All visibility</option>
                        <option value="visible">Visible</option>
                        <option value="not-visible">Not visible</option>
                    </select>
                </label>
                <label>Learning rule
                    <select id="lesson-manager-locked" class="form-control form-control-sm">
                        <option value="">All lessons</option>
                        <option value="1">In a locked section</option>
                        <option value="0">No section lock</option>
                    </select>
                </label>
                <button type="button" class="btn btn-link btn-sm" data-manager-action="select-visible">Select visible</button>
                <button type="button" class="btn btn-link btn-sm" data-manager-action="clear-filters">Clear filters</button>
                <span id="lesson-manager-results" class="course-manager-filter-result" aria-live="polite"></span>
            </section>

            <section class="course-manager-bulk-bar d-none" id="course-manager-bulk-bar" aria-live="polite">
                <div class="course-manager-bulk-copy"><strong id="course-manager-selected-count">0</strong> lessons selected</div>
                <label class="visually-hidden" for="course-manager-bulk-destination">Move selected lessons to</label>
                <select id="course-manager-bulk-destination" class="form-control form-control-sm"></select>
                <button type="button" class="btn btn-outline-primary btn-sm" data-manager-bulk="move"><i class="fas fa-arrow-right ds-icon ds-icon-sm" aria-hidden="true"></i> Move</button>
                <button type="button" class="btn btn-outline-success btn-sm" data-manager-bulk="publish"><i class="fas fa-eye ds-icon ds-icon-sm" aria-hidden="true"></i> Publish</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-manager-bulk="unpublish"><i class="fas fa-file ds-icon ds-icon-sm" aria-hidden="true"></i> Move to draft</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-manager-bulk="duplicate"><i class="fas fa-copy ds-icon ds-icon-sm" aria-hidden="true"></i> Duplicate</button>
                <button type="button" class="btn btn-outline-danger btn-sm" data-manager-bulk="delete"><i class="fas fa-trash ds-icon ds-icon-sm" aria-hidden="true"></i> Delete</button>
                <button type="button" class="btn btn-link btn-sm" data-manager-action="clear-selection">Clear</button>
            </section>

            <section id="course-manager-picker" class="course-manager-picker d-none" aria-label="Choose lesson type">
                <div class="course-manager-picker-heading">
                    <div>
                        <div class="course-manager-eyebrow">New content</div>
                        <h2>Choose a lesson type</h2>
                    </div>
                    <button type="button" class="btn-close" data-manager-action="close-picker" aria-label="Close"></button>
                </div>
                <div class="course-manager-template-grid">
                    <button type="button" class="course-manager-template" data-template="recording"><i class="fas fa-play-circle ds-icon" aria-hidden="true"></i><span>Recording</span><small>Paste a SharePoint recording link.</small></button>
                    <button type="button" class="course-manager-template" data-template="notes"><i class="far fa-file-alt ds-icon" aria-hidden="true"></i><span>Notes</span><small>Add a structured Notes resource for the LMS viewer.</small></button>
                    <button type="button" class="course-manager-template" data-template="classified_assignment"><i class="fas fa-clipboard-list ds-icon" aria-hidden="true"></i><span>Classified Assignment</span><small>Create the existing homework workflow.</small></button>
                    <button type="button" class="course-manager-template" data-template="custom_lesson"><i class="fas fa-puzzle-piece ds-icon" aria-hidden="true"></i><span>Custom Lesson</span><small>Create flexible teacher content.</small></button>
                </div>
            </section>

            <section id="course-manager-editor" class="course-manager-editor d-none" aria-live="polite"></section>
            <div id="course-manager-feedback" class="course-manager-feedback" aria-live="polite"></div>
            <section id="course-manager-list" class="course-manager-list" aria-label="Course lesson manager">
                <div class="course-manager-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading course content…</div>
            </section>
        </main>
    </div>
</div>

<script>
(function($) {
  'use strict';

  var courseId = $('.course-manager-page').data('course-id');
  var baseUrl = <?=json_encode(rtrim((string) $baseUrl, '/'));?>;
  var adminCsrfToken = <?=json_encode(mmh_auth_csrf_token());?>;
  var listRequest = null;
  var sortingTimer = null;
  var sortingInFlight = false;
  var sortingQueued = false;
  var editorDirty = false;
  var pickerSectionId = '';
  var listStateKey = 'mmh:course-manager:' + courseId + ':collapsed';
  var Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 4200 });

  function responseOk(response) {
    return response && (response.success === true || Number(response.status) === 1);
  }
  function responseMessage(response, fallback) {
    return (response && (response.message || response.reason)) || fallback || 'Unexpected server error.';
  }
  function notify(type, message) {
    Toast.fire({ icon: type, title: message });
  }
  function setButtonLoading($button, loading, label) {
    if (!$button || !$button.length) { return; }
    if (loading) {
      if (!$button.data('manager-original')) { $button.data('manager-original', $button.html()); }
      $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + (label || 'Saving…'));
    } else {
      $button.prop('disabled', false).html($button.data('manager-original') || $button.html()).removeData('manager-original');
    }
  }
  function selectedIds() {
    return $('#course-manager-list .course-manager-select:checked').map(function() { return String(this.value); }).get();
  }
  function updateBulkBar() {
    var count = selectedIds().length;
    $('#course-manager-selected-count').text(count);
    $('#course-manager-bulk-bar').toggleClass('d-none', count === 0);
    $('#course-manager-list .lesson-manager-row').toggleClass('is-selected', function() { return $(this).find('.course-manager-select').prop('checked'); });
  }
  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }
  function refreshDestinationOptions() {
    var selected = $('#course-manager-bulk-destination').val() || '';
    var html = '<option value="">Move to General</option>';
    $('#course-manager-list .course-manager-section[data-general="0"]').each(function() {
      var id = String($(this).data('section-id') || '');
      var title = String($(this).data('section-title') || 'Untitled Section');
      if (id) { html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(title) + '</option>'; }
    });
    $('#course-manager-bulk-destination').html(html).val(selected);
  }
  function savedCollapsed() {
    try { return JSON.parse(window.localStorage.getItem(listStateKey) || 'null'); } catch (error) { return null; }
  }
  function persistCollapsed() {
    var collapsed = [];
    $('#course-manager-list .course-manager-section.is-collapsed').each(function() { collapsed.push(String($(this).data('section-id'))); });
    try { window.localStorage.setItem(listStateKey, JSON.stringify(collapsed)); } catch (error) {}
  }
  function setSectionCollapsed($section, collapsed, persist) {
    if (!$section.length) { return; }
    $section.toggleClass('is-collapsed', collapsed);
    $section.find('> .course-manager-section-header [data-manager-action="toggle-section"]').attr('aria-expanded', collapsed ? 'false' : 'true');
    $section.find('> .course-manager-section-body').stop(true, true)[collapsed ? 'slideUp' : 'slideDown'](150);
    if (persist !== false) { persistCollapsed(); }
  }
  function restoreSectionState() {
    var stored = savedCollapsed();
    $('#course-manager-list .course-manager-section').each(function(index) {
      var $section = $(this);
      var id = String($section.data('section-id'));
      var collapsed = Array.isArray(stored) ? stored.indexOf(id) !== -1 : index > 0;
      setSectionCollapsed($section, collapsed, false);
    });
  }
  function populateTypeFilter() {
    var current = $('#lesson-manager-type').val() || '';
    var seen = {};
    $('#course-manager-list .lesson-manager-row').each(function() {
      var type = String($(this).data('template') || 'legacy');
      seen[type] = true;
    });
    var label = { recording: 'Recording', notes: 'Notes', classified_assignment: 'Classified Assignment', assignment_model_answer: 'Assignment Model Answer', custom_lesson: 'Custom Lesson', custom_html: 'Legacy Lesson', resource: 'Structured Resource', legacy: 'Legacy Lesson', video: 'Video', file: 'File', quiz: 'Quiz' };
    var html = '<option value="">All lesson types</option>';
    Object.keys(seen).sort().forEach(function(type) { html += '<option value="' + escapeHtml(type) + '">' + escapeHtml(label[type] || type.replace(/_/g, ' ')) + '</option>'; });
    $('#lesson-manager-type').html(html).val(current);
  }
  function applyFilters() {
    var query = $.trim($('#lesson-manager-search').val() || '').toLowerCase();
    var status = $('#lesson-manager-status').val() || '';
    var type = $('#lesson-manager-type').val() || '';
    var visibility = $('#lesson-manager-visibility').val() || '';
    var locked = $('#lesson-manager-locked').val() || '';
    var visibleCount = 0;
    $('#course-manager-list .lesson-manager-row').each(function() {
      var $row = $(this);
      var title = $.trim($row.find('.course-manager-edit-link').text()).toLowerCase();
      var matches = (!query || title.indexOf(query) !== -1) && (!status || String($row.data('status')) === status) && (!type || String($row.data('template')) === type) && (!visibility || String($row.data('visible')) === visibility) && (!locked || String($row.data('locked')) === locked);
      $row.toggleClass('d-none', !matches);
      if (matches) { visibleCount++; }
    });
    $('#course-manager-list .course-manager-section').each(function() {
      var $section = $(this);
      var hasRows = $section.find('.lesson-manager-row:not(.d-none)').length > 0;
      var hasNoFilters = !query && !status && !type && !visibility && !locked;
      $section.toggleClass('course-manager-filter-empty', !hasRows && !hasNoFilters);
    });
    $('#lesson-manager-results').text((query || status || type || visibility || locked) ? visibleCount + ' matching lesson' + (visibleCount === 1 ? '' : 's') : '');
    updateBulkBar();
  }
  function initSortable() {
    var $sectionList = $('#course-manager-real-sections');
    var $lessonLists = $('#course-manager-list .course-manager-lessons');
    if ($sectionList.data('ui-sortable')) { $sectionList.sortable('destroy'); }
    $lessonLists.each(function() { if ($(this).data('ui-sortable')) { $(this).sortable('destroy'); } });
    if ($sectionList.children('.course-manager-section').length > 1) {
      $sectionList.sortable({ items: '> .course-manager-section', handle: '.course-manager-section-drag', placeholder: 'course-manager-sort-placeholder', tolerance: 'pointer', update: queueSortingSave });
    }
    $lessonLists.sortable({
      items: '> .lesson-manager-row',
      connectWith: '#course-manager-list .course-manager-lessons',
      handle: '.course-manager-drag',
      placeholder: 'course-manager-row-placeholder',
      tolerance: 'pointer',
      update: function(event, ui) { if (this === ui.item.parent()[0]) { queueSortingSave(); } }
    });
  }
  function queueSortingSave() {
    clearTimeout(sortingTimer);
    sortingTimer = setTimeout(saveSorting, 260);
  }
  function saveSorting() {
    if (sortingInFlight) { sortingQueued = true; return; }
    var sections = [];
    $('#course-manager-real-sections > .course-manager-section').each(function() { sections.push(String($(this).data('section-id'))); });
    var lessons = [];
    $('#course-manager-list .course-manager-lessons').each(function() {
      var sectionId = String($(this).data('section-id') || '__general__');
      $(this).children('.lesson-manager-row').each(function(index) {
        lessons.push({ id: Number($(this).data('item-db-id')), section_id: sectionId === '__general__' ? '' : sectionId, page_order: index + 1 });
      });
    });
    sortingInFlight = true;
    $('#course-manager-list').addClass('is-saving-order');
    $.ajax({ url: 'requests/section/sorting', method: 'POST', dataType: 'json', data: { _method: 'update', course_id: courseId, sections: JSON.stringify(sections), lessons: JSON.stringify(lessons) } })
      .done(function(response) { if (!responseOk(response)) { notify('error', responseMessage(response, 'Order could not be saved.')); loadList(); } })
      .fail(function() { notify('error', 'Order could not be saved because the server could not be reached.'); loadList(); })
      .always(function() { sortingInFlight = false; $('#course-manager-list').removeClass('is-saving-order'); if (sortingQueued) { sortingQueued = false; queueSortingSave(); } });
  }
  function loadList() {
    if (listRequest && listRequest.readyState !== 4) { listRequest.abort(); }
    $('#course-manager-list').addClass('is-loading');
    listRequest = $.ajax({ type: 'POST', url: 'requests/item/items', dataType: 'json', data: { course_id: courseId, _method: 'GET', layout: 'manager' } });
    listRequest.done(function(response) {
      if (!responseOk(response)) { $('#course-manager-list').html('<div class="course-manager-error">' + escapeHtml(responseMessage(response, 'Course content could not be loaded.')) + '</div>'); return; }
      $('#course-manager-list').html(response.html);
      refreshDestinationOptions();
      populateTypeFilter();
      restoreSectionState();
      applyFilters();
      initSortable();
      var returnItem = (window.location.hash || '').replace(/^#course-item-/, '');
      if (returnItem) {
        var $returnItem = $('#course-manager-list [data-item-id="' + returnItem.replace(/"/g, '') + '"]');
        if ($returnItem.length) { $returnItem[0].scrollIntoView({ behavior: 'auto', block: 'center' }); }
      }
    }).fail(function(xhr, status) {
      if (status !== 'abort') { $('#course-manager-list').html('<div class="course-manager-error">Course content could not be loaded. Please refresh and try again.</div>'); }
    }).always(function() { $('#course-manager-list').removeClass('is-loading'); });
  }
  function closePicker() { pickerSectionId = ''; $('#course-manager-picker').addClass('d-none'); }
  function openPicker(sectionId) { pickerSectionId = sectionId || ''; closeEditor(true); $('#course-manager-picker').removeClass('d-none'); $('#course-manager-picker')[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  function destroyEditor() {
    if (typeof window.tinymce === 'undefined') { return; }
    $('#course-manager-editor .course-builder-editor').each(function() { var editor = this.id ? tinymce.get(this.id) : null; if (editor) { editor.remove(); } });
  }
  function closeEditor(force) {
    if (!force && editorDirty) {
      Swal.fire({ icon: 'warning', title: 'Discard unsaved changes?', text: 'Your lesson changes have not been saved.', showCancelButton: true, confirmButtonText: 'Discard', cancelButtonText: 'Keep editing' }).then(function(result) { if (result.isConfirmed) { closeEditor(true); } });
      return;
    }
    destroyEditor();
    editorDirty = false;
    $('#course-manager-editor').addClass('d-none').empty();
  }
  function markEditorDirty() { editorDirty = true; }
  function initEditorTinyMCE() {
    if (typeof window.tinymce === 'undefined') { return; }
    $('#course-manager-editor .template-pane:not(.d-none) .course-builder-editor').each(function() {
      if (this.id && tinymce.get(this.id)) { return; }
      tinymce.init({ target: this, plugins: 'advlist image media autolink code codesample directionality table wordcount quickbars link lists', images_upload_url: baseUrl + '/admin/requests/image/upload', automatic_uploads: true, file_picker_types: 'file image media', image_caption: true, image_dimensions: true, directionality: 'ltr', language: 'en', quickbars_selection_toolbar: 'bold italic | h1 h2 h3 | quicklink blockquote | numlist bullist', entity_encoding: 'raw', verify_html: false, object_resizing: 'img', height: 340, setup: function(editor) { editor.on('change keyup undo redo input SetContent', markEditorDirty); } });
    });
  }
  function itemTypeForTemplate(template, $form) {
    if (template === 'recording' || template === 'video') { return 'video'; }
    if (template === 'classified_assignment' || template === 'assignment' || template === 'exam') { return 'quiz'; }
    if (template === 'timed_exam') { return 'timed_exam'; }
    if ((template === 'custom_html' || template === 'resource') && $form.find('[name="_method"]').val() === 'UPDATE') { return $form.find('[name="item_type"]').data('originalItemType') || 'file'; }
    return 'file';
  }
  function updateCustomLabel($form) {
    var $selector = $form.find('[data-custom-label-selector]'); var $input = $form.find('[data-custom-label-input]');
    var show = $selector.length && !$selector.closest('.template-pane').hasClass('d-none') && $selector.val() === 'custom';
    $input.toggleClass('d-none', !show).prop('required', show);
  }
  function activateTemplate($form, template) {
    if (typeof window.tinymce !== 'undefined') { tinymce.triggerSave(); }
    template = template || 'recording';
    $form.find('[name="template_type"]').val(template); $form.find('[name="item_type"]').val(itemTypeForTemplate(template, $form));
    $form.find('.template-card').removeClass('border-primary bg-light'); $form.find('.template-card[data-template="' + template + '"]').addClass('border-primary bg-light');
    $form.find('[data-template-required]').prop('required', false); $form.find('[data-classified-required]').prop('required', template === 'classified_assignment');
    $form.find('.template-pane').addClass('d-none');
    var $pane = $form.find('.template-pane[data-template-pane="' + template + '"]');
    if (!$pane.length) { $pane = $form.find('.template-pane[data-template-pane="custom_html"]'); }
    $pane.removeClass('d-none'); $pane.find('[data-template-required]').prop('required', true); updateCustomLabel($form); initEditorTinyMCE();
  }
  function updateSectionFields($form) {
    var custom = $form.find('.sectionTypeSelector').val() === 'custom';
    $form.find('[data-custom-section-type]').toggleClass('d-none', !custom).find('input').prop('required', custom);
    var unlock = $form.find('.sectionUnlockModeSelector').val() || 'always'; var completion = $form.find('.sectionCompletionRuleSelector').val() || 'manual_completion';
    var needsDate = unlock === 'on_date'; var needsHomework = unlock === 'after_homework_submission' || unlock === 'after_homework_approval' || completion === 'homework_submitted' || completion === 'homework_approved';
    $form.find('[data-learning-unlock-date]').toggleClass('d-none', !needsDate).find('input,select').prop('required', needsDate);
    $form.find('[data-learning-homework]').toggleClass('d-none', !needsHomework).find('select').prop('required', needsHomework);
    $form.find('[data-learning-manual-unlock]').toggleClass('d-none', unlock !== 'manual_unlock');
    var release = $form.find('.sectionReleaseModeSelector').val() || 'inherit';
    var releaseScheduled = release === 'scheduled';
    var releaseLive = release === 'live_session' || release === 'live_session_delay';
    var releaseDelay = release === 'live_session_delay';
    $form.find('[data-release-schedule]').toggleClass('d-none', !releaseScheduled).find('input,select').prop('required', releaseScheduled);
    $form.find('[data-release-live]').toggleClass('d-none', !releaseLive).find('select').prop('required', releaseLive);
    $form.find('[data-release-delay]').toggleClass('d-none', !releaseDelay).find('input').prop('required', false);
  }
  function mountEditor(response) {
    if (!responseOk(response)) { notify('error', responseMessage(response, 'The editor could not be opened.')); return; }
    closePicker();
    var $content = $(response.html).find('.modal-content').first().clone();
    if (!$content.length) { notify('error', 'The editor response was incomplete.'); return; }
    $content.addClass('course-manager-editor-card');
    $content.find('.modal-footer').addClass('course-manager-editor-footer');
    $('#course-manager-editor').removeClass('d-none').html($content);
    editorDirty = false;
    var $form = $('#course-manager-editor form').first();
    $form.on('input.courseManager change.courseManager', 'input, select, textarea', markEditorDirty);
    if ($form.hasClass('courseBuilderItemForm')) { activateTemplate($form, $form.find('[name="template_type"]').val() || 'recording'); }
    if ($form.hasClass('courseBuilderSectionForm')) { updateSectionFields($form); }
    $('#course-manager-editor')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function openItem(data, $button) {
    setButtonLoading($button, true, 'Loading…');
    $.ajax({ type: 'POST', url: 'requests/item/form', data: data, dataType: 'json' }).done(mountEditor).fail(function() { notify('error', 'The lesson editor could not be reached.'); }).always(function() { setButtonLoading($button, false); });
  }
  function openSection(data, $button) {
    setButtonLoading($button, true, 'Loading…');
    $.ajax({ type: 'POST', url: 'requests/section/form', data: data, dataType: 'json' }).done(mountEditor).fail(function() { notify('error', 'The section editor could not be reached.'); }).always(function() { setButtonLoading($button, false); });
  }
  function openSectionIntegrity($button) {
    setButtonLoading($button, true, 'Loading…');
    $.ajax({ type: 'POST', url: 'requests/section/integrity', data: { course_id: courseId, _method: 'GET' }, dataType: 'json' }).done(mountEditor).fail(function() { notify('error', 'Section integrity could not be loaded.'); }).always(function() { setButtonLoading($button, false); });
  }
  function saveEditorForm(form) {
    if (typeof window.tinymce !== 'undefined') { tinymce.triggerSave(); }
    var $form = $(form); var isSection = $form.hasClass('courseBuilderSectionForm'); var endpoint = isSection ? 'requests/section/add' : 'requests/item/add'; var $button = $form.find('.submitBtn').first();
    var data = new FormData(form);
    setButtonLoading($button, true, 'Saving…');
    $.ajax({ type: 'POST', url: endpoint, data: data, dataType: 'json', contentType: false, processData: false }).done(function(response) {
      if (!responseOk(response)) { notify('error', responseMessage(response, 'Changes could not be saved.')); return; }
      notify('success', responseMessage(response, isSection ? 'Section saved.' : 'Lesson saved.')); closeEditor(true); loadList();
    }).fail(function() { notify('error', 'Changes could not be saved because the server could not be reached.'); }).always(function() { setButtonLoading($button, false); });
  }
  function bulkAction(action, ids, destination, $button) {
    if (!ids.length) { return; }
    var data = { _method: 'BULK', course_id: courseId, action: action, item_ids: ids };
    if (action === 'move') { data.destination_section_id = destination || ''; }
    setButtonLoading($button, true, 'Working…');
    $.ajax({ type: 'POST', url: 'requests/item/bulk', data: data, dataType: 'json' }).done(function(response) {
      if (responseOk(response)) { notify('success', responseMessage(response, 'Lessons updated.')); loadList(); } else { notify('error', responseMessage(response, 'Lessons could not be updated.')); }
    }).fail(function() { notify('error', 'The bulk action could not be reached.'); }).always(function() { setButtonLoading($button, false); });
  }
  function deleteSection(sectionId, $button, moveTo) {
    setButtonLoading($button, true, 'Deleting…');
    $.ajax({ type: 'POST', url: 'requests/section/delete', data: { _method: 'DELETE', course_id: courseId, section_id: sectionId, move_to: moveTo || '' }, dataType: 'json' }).done(function(response) {
      if (responseOk(response)) { notify('success', responseMessage(response, 'Section deleted.')); loadList(); return; }
      if (response && response.requires_move && response.options) {
        Swal.fire({ icon: 'warning', title: 'This section contains lessons', text: 'Choose where to move its lessons before deleting it.', input: 'select', inputOptions: response.options, inputPlaceholder: 'Move lessons to…', showCancelButton: true, confirmButtonText: 'Move and delete' }).then(function(result) { if (result.isConfirmed && result.value) { deleteSection(sectionId, $button, result.value); } });
      } else { notify('error', responseMessage(response, 'Section could not be deleted.')); }
    }).fail(function() { notify('error', 'The section could not be deleted because the server could not be reached.'); }).always(function() { setButtonLoading($button, false); });
  }
  function duplicateSection(sectionId, $button) {
    setButtonLoading($button, true, 'Duplicating…');
    $.ajax({ type: 'POST', url: 'requests/section/duplicate', data: { _method: 'DUPLICATE', course_id: courseId, section_id: sectionId }, dataType: 'json' }).done(function(response) { if (responseOk(response)) { notify('success', responseMessage(response, 'Section duplicated.')); loadList(); } else { notify('error', responseMessage(response, 'Section could not be duplicated.')); } }).fail(function() { notify('error', 'The section could not be duplicated.'); }).always(function() { setButtonLoading($button, false); });
  }

  $(document).on('click.courseManager', '[data-manager-action]', function(event) {
    var $button = $(this); var action = $button.data('manager-action');
    if (action === 'toggle-section') { event.preventDefault(); var $section = $button.closest('.course-manager-section'); setSectionCollapsed($section, !$section.hasClass('is-collapsed')); return; }
    if (action === 'expand-all' || action === 'collapse-all') { $('#course-manager-list .course-manager-section').each(function() { setSectionCollapsed($(this), action === 'collapse-all', false); }); persistCollapsed(); return; }
    if (action === 'clear-filters') { $('#lesson-manager-search').val(''); $('#lesson-manager-status, #lesson-manager-type, #lesson-manager-visibility, #lesson-manager-locked').val(''); applyFilters(); return; }
    if (action === 'select-visible') { $('#course-manager-list .lesson-manager-row:not(.d-none) .course-manager-select').prop('checked', true); updateBulkBar(); return; }
    if (action === 'clear-selection') { $('#course-manager-list .course-manager-select').prop('checked', false); updateBulkBar(); return; }
    if (action === 'choose-content') { openPicker($button.data('section-id') || ''); return; }
    if (action === 'close-picker') { closePicker(); return; }
    if (action === 'add-section') { closePicker(); openSection({ course_id: courseId, _method: 'GET' }, $button); return; }
    if (action === 'section-integrity') { closePicker(); openSectionIntegrity($button); return; }
    if (action === 'edit-item') { openItem({ course_id: courseId, item_id: $button.data('item-id'), _method: 'GET' }, $button); return; }
    if (action === 'preview-item') { event.preventDefault(); window.location.assign(baseUrl + '/admin/courses/' + encodeURIComponent(courseId) + '/content/' + encodeURIComponent(String($button.data('item-id'))) + '/preview'); return; }
    if (action === 'toggle-item-status') { bulkAction(String($button.data('status')) === 'published' ? 'unpublish' : 'publish', [String($button.data('item-id'))], '', $button); return; }
    if (action === 'duplicate-item') { bulkAction('duplicate', [String($button.data('item-id'))], '', $button); return; }
    if (action === 'delete-item') { Swal.fire({ icon: 'warning', title: 'Delete this lesson?', text: 'This cannot be undone.', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: 'var(--danger)' }).then(function(result) { if (result.isConfirmed) { bulkAction('delete', [String($button.data('item-id'))], '', $button); } }); return; }
    if (action === 'edit-section') { openSection({ course_id: courseId, section_id: $button.data('section-id'), _method: 'GET' }, $button); return; }
    if (action === 'duplicate-section') { duplicateSection(String($button.data('section-id')), $button); return; }
    if (action === 'delete-section') { Swal.fire({ icon: 'warning', title: 'Delete this section?', text: 'Lessons will never be orphaned.', showCancelButton: true, confirmButtonText: 'Continue' }).then(function(result) { if (result.isConfirmed) { deleteSection(String($button.data('section-id')), $button); } }); return; }
    if (action === 'move-section') { var $sectionToMove = $button.closest('.course-manager-section'); var direction = $button.data('direction'); var $target = direction === 'up' ? $sectionToMove.prev('.course-manager-section') : $sectionToMove.next('.course-manager-section'); if ($target.length) { direction === 'up' ? $sectionToMove.insertBefore($target) : $sectionToMove.insertAfter($target); queueSortingSave(); } return; }
  });
  $(document).on('click.courseManager', '.course-manager-template', function() { openItem({ course_id: courseId, section_id: pickerSectionId, template_type: $(this).data('template'), _method: 'GET' }, $(this)); });
  $(document).on('change.courseManager', '#course-manager-list .course-manager-select', updateBulkBar);
  $(document).on('input.courseManager change.courseManager', '#lesson-manager-search, #lesson-manager-status, #lesson-manager-type, #lesson-manager-visibility, #lesson-manager-locked', applyFilters);
  $(document).on('click.courseManager', '[data-manager-bulk]', function() {
    var $button = $(this); var action = String($button.data('manager-bulk')); var ids = selectedIds();
    if (action === 'delete') { Swal.fire({ icon: 'warning', title: 'Delete ' + ids.length + ' selected lessons?', text: 'This cannot be undone.', showCancelButton: true, confirmButtonText: 'Delete selected' }).then(function(result) { if (result.isConfirmed) { bulkAction(action, ids, '', $button); } }); return; }
    bulkAction(action, ids, $('#course-manager-bulk-destination').val(), $button);
  });
  $(document).on('click.courseManager', '#course-manager-editor [data-bs-dismiss="modal"], #course-manager-editor .btn-close', function(event) { event.preventDefault(); closeEditor(false); });
  $(document).on('show.bs.dropdown.courseManager', '#course-manager-list .dropdown', function() { $(this).closest('.course-manager-section').addClass('has-open-menu'); });
  $(document).on('hidden.bs.dropdown.courseManager', '#course-manager-list .dropdown', function() { $(this).closest('.course-manager-section').removeClass('has-open-menu'); });
  $(document).on('click.courseManager', '#course-manager-editor .template-card', function(event) { event.preventDefault(); activateTemplate($(this).closest('form'), $(this).data('template')); markEditorDirty(); });
  $(document).on('change.courseManager', '#course-manager-editor [data-custom-label-selector]', function() { updateCustomLabel($(this).closest('form')); markEditorDirty(); });
  $(document).on('change.courseManager', '#course-manager-editor .sectionTypeSelector, #course-manager-editor .sectionUnlockModeSelector, #course-manager-editor .sectionCompletionRuleSelector, #course-manager-editor .sectionReleaseModeSelector', function() { updateSectionFields($(this).closest('form')); markEditorDirty(); });
  $(document).on('submit.courseManager', '#course-manager-editor .courseBuilderItemForm, #course-manager-editor .courseBuilderSectionForm', function(event) { event.preventDefault(); saveEditorForm(this); });
  $(document).on('submit.courseManager', '#course-manager-editor [data-section-integrity-map]', function(event) {
    event.preventDefault();
    var $form = $(this); var $button = $form.find('button[type="submit"]');
    setButtonLoading($button, true, 'Saving…');
    var data = $form.serializeArray(); data.push({ name: 'course_id', value: courseId }, { name: '_token', value: adminCsrfToken });
    $.ajax({ type: 'POST', url: 'requests/section/integrity', data: $.param(data), dataType: 'json' }).done(function(response) {
      if (responseOk(response)) { notify('success', responseMessage(response, 'Mapping saved.')); openSectionIntegrity($('[data-manager-action="section-integrity"]')); loadList(); }
      else { notify('error', responseMessage(response, 'Mapping could not be saved.')); }
    }).fail(function() { notify('error', 'Mapping could not be saved.'); }).always(function() { setButtonLoading($button, false); });
  });
  $(window).on('beforeunload.courseManager', function(event) { if (editorDirty) { event.preventDefault(); event.returnValue = ''; } });

  loadList();
})(jQuery);
</script>
</body>
</html>
