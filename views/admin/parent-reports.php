<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/ParentWeeklyReport.php';

$username = (string) ($_SESSION['admin'] ?? '');
$pageName = 'parent_reports';
$subPageName = 'parent_reports';
$conn = db();
mmh_parent_report_ensure_schema($conn);
$courses = mmh_parent_report_courses($conn);
$selectedCourseId = trim((string) ($_POST['course_id'] ?? $_GET['course_id'] ?? ($courses[0]['course_id'] ?? '')));
$course = mmh_parent_report_course($conn, $selectedCourseId);
$students = $course ? mmh_parent_report_students($conn, $selectedCourseId) : [];
$week = mmh_parent_report_current_week();
$selectedStudentId = (int) ($_POST['student_id'] ?? $_GET['student_id'] ?? ($students[0]['user_id'] ?? 0));
$periodKey = strtolower(trim((string) ($_POST['period'] ?? $_GET['period'] ?? 'current_week')));
$periodOptions = mmh_report_period_options(true);
$periodResolutionError = '';
try {
    $period = mmh_report_period($conn, $selectedCourseId, $selectedStudentId, $periodKey, $_POST['week_start'] ?? $_GET['week_start'] ?? null, $_POST['week_end'] ?? $_GET['week_end'] ?? null, true);
} catch (Throwable $periodException) {
    $periodResolutionError = $periodException->getMessage();
    $period = ['key' => 'current_week', 'label' => $periodOptions['current_week'], 'start' => $week['start'], 'end' => $week['end']];
}
$periodKey = $period['key']; $start = $period['start']; $end = $period['end'];
$comment = trim((string) ($_POST['teacher_comment'] ?? ''));
$commentWasSubmitted = array_key_exists('teacher_comment', $_POST);
$report = null; $error = $periodResolutionError; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) {
        $error = 'Your session has expired. Refresh and try again.';
    } elseif (!$course) {
        $error = 'Select a valid course.';
    } elseif ($selectedStudentId <= 0) {
        $error = 'Select an enrolled student.';
    } else {
        try {
            $action = (string) ($_POST['report_action'] ?? 'preview');
            $adminId = mmh_auth_user_id($conn, $username);
            $period = mmh_report_period($conn, $selectedCourseId, $selectedStudentId, $periodKey, $_POST['week_start'] ?? null, $_POST['week_end'] ?? null, true);
            $periodKey = $period['key']; $start = $period['start']; $end = $period['end'];
            $report = mmh_report_resolve($conn, $selectedCourseId, $selectedStudentId, $start, $end, $commentWasSubmitted ? $comment : null);
            if ($action === 'save_comment') {
                if (!mmh_parent_report_save_comment($conn, $selectedCourseId, $selectedStudentId, $start, $end, $comment, $adminId)) {
                    throw new RuntimeException('The teacher comment could not be saved.');
                }
                $success = 'Teacher comment saved for this student and week.';
            }
            $postedOverrides = mmh_parent_report_override_input($_POST['overrides'] ?? [], $report['sections']);
            $overrideFormSubmitted = ($_POST['override_form'] ?? '') === '1';
            if ($action === 'save_draft') {
                mmh_parent_report_save_overrides($conn, $selectedCourseId, $selectedStudentId, $start, $end, $postedOverrides, $adminId);
                $success = 'Report draft saved. Original LMS records were not changed.';
            } elseif ($action === 'reset_section') {
                $sectionId = trim((string) ($_POST['reset_section_id'] ?? ''));
                if ($sectionId === '' || !in_array($sectionId, array_column($report['sections'], 'section_id'), true)) { throw new InvalidArgumentException('Select a valid report section to reset.'); }
                mmh_parent_report_reset_override($conn, $selectedCourseId, $selectedStudentId, $start, $end, $sectionId);
                unset($postedOverrides[$sectionId]);
                $success = 'This section was reset to LMS data.';
            } elseif ($action === 'reset_report') {
                if (($_POST['confirm_reset_entire'] ?? '') !== '1') { throw new InvalidArgumentException('Confirm before resetting the entire report.'); }
                mmh_parent_report_reset_override($conn, $selectedCourseId, $selectedStudentId, $start, $end);
                $postedOverrides = [];
                $success = 'All report overrides were reset to LMS data.';
            }
            $savedOverrides = mmh_parent_report_overrides($conn, $selectedCourseId, $selectedStudentId, $start, $end);
            $effectiveOverrides = ($overrideFormSubmitted && in_array($action, ['preview', 'download'], true)) ? $postedOverrides : $savedOverrides;
            $report = mmh_parent_report_apply_overrides($report, $effectiveOverrides);
            if ($action === 'download') {
                $pdf = mmh_parent_report_pdf($report);
                $filename = 'parent-weekly-report-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $report['student']['full_name'])) . '-' . $start . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdf));
                echo $pdf;
                exit;
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) $baseUrl, '/');
$reportSelect = static function (string $name, array $options, array $value) use ($escape): string {
    $selected = ($value['source'] ?? 'lms') === 'override' ? (string) ($value['key'] ?? '') : '';
    $html = '<select class="form-control form-control-sm" name="' . $escape($name) . '"><option value="">Use LMS value: ' . $escape($value['label'] ?? 'Not Available') . '</option>';
    foreach ($options as $key => $label) { $html .= '<option value="' . $escape($key) . '"' . ($selected === $key ? ' selected' : '') . '>' . $escape($label) . '</option>'; }
    return $html . '</select>';
};
$reportScore = static function (string $name, array $grade, string $part) use ($escape): string {
    $value = ($grade['source'] ?? 'lms') === 'override' ? (string) ($grade[$part] ?? '') : '';
    $placeholder = ($grade['source'] ?? 'lms') === 'override' ? '' : (string) ($grade[$part] ?? '');
    return '<input class="form-control form-control-sm" type="number" min="0" step="0.01" inputmode="decimal" name="' . $escape($name) . '" value="' . $escape($value) . '" placeholder="' . $escape($placeholder) . '">';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parent Reports | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <style>
      .parent-report-admin{margin-top:55px;padding:24px;max-width:1280px}.parent-report-admin .report-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:20px;margin-bottom:18px}.parent-report-admin .report-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.parent-report-admin .report-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.parent-report-admin .report-unresolved{border-left:4px solid var(--warning);background:var(--surface-hover);padding:12px;border-radius:var(--radius-sm);margin-bottom:16px}.parent-report-preview{background:var(--bg-secondary);padding:18px;border-radius:var(--radius-md);overflow:auto}.parent-report-sheet{background:var(--surface);color:var(--text-primary);max-width:900px;margin:auto;padding:28px;border-radius:var(--radius-md)}.parent-report-brand{font-weight:700;color:var(--secondary)}.parent-report-sheet h1{font-size:1.75rem;margin:4px 0 14px}.parent-report-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.92rem}.parent-report-section,.parent-report-outstanding,.parent-report-comment{border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-top:12px}.parent-report-section h2,.parent-report-outstanding h2,.parent-report-comment h2{font-size:1.08rem;margin:0}.parent-report-date{color:var(--text-muted);margin:4px 0 10px}.parent-report-sheet dl{display:grid;grid-template-columns:120px 1fr;gap:8px;margin:0}.parent-report-sheet dt{font-weight:650}.parent-report-sheet dd{margin:0}.parent-report-homework{margin-bottom:8px}.parent-report-homework small{display:block;color:var(--text-muted);margin-top:3px}.status{display:inline-block;padding:2px 7px;border-radius:999px;font-size:.8rem;font-weight:650}.status.success{background:color-mix(in srgb,var(--success) 16%,transparent);color:var(--success)}.status.warning{background:color-mix(in srgb,var(--warning) 18%,transparent);color:var(--warning)}.status.danger{background:color-mix(in srgb,var(--danger) 14%,transparent);color:var(--danger)}.status.info{background:color-mix(in srgb,var(--secondary) 16%,transparent);color:var(--secondary)}.status.muted{background:var(--surface-hover);color:var(--text-muted)}.parent-report-empty{padding:12px;background:var(--surface-hover);border-radius:var(--radius-sm);margin-top:12px}@media(max-width:900px){.parent-report-admin .report-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:768px){.parent-report-admin{padding:14px}.parent-report-admin .report-grid,.parent-report-meta{grid-template-columns:1fr}.parent-report-sheet{padding:16px}.parent-report-sheet dl{grid-template-columns:1fr;gap:3px}.parent-report-sheet dd{margin-bottom:8px}}
    </style>
    <style>
      .parent-report-preview .parent-report-sheet{padding:0;overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow-sm)}.parent-report-preview .parent-report-header{padding:22px 24px;background:var(--secondary);color:#fff}.parent-report-preview .parent-report-header-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.parent-report-preview .parent-report-logo{width:auto;height:34px;max-width:150px;object-fit:contain}.parent-report-preview .parent-report-brand-text{font-size:1.05rem;font-weight:750;letter-spacing:.01em}.parent-report-preview .parent-report-header-title{text-align:right}.parent-report-preview .parent-report-header h1{margin:0 0 8px;color:#fff;font-size:1.45rem}.parent-report-preview .parent-report-meta{margin-top:16px;padding:14px 0 0;border:0;border-top:1px solid color-mix(in srgb,#fff 28%,transparent);border-radius:0;color:#fff}.parent-report-preview .parent-report-meta div{display:grid;gap:2px}.parent-report-preview .parent-report-meta span{font-size:.72rem;letter-spacing:.07em;text-transform:uppercase;opacity:.76}.parent-report-preview .parent-report-meta b{font-size:.9rem;font-weight:600}.parent-report-preview .parent-report-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:16px 24px;background:var(--surface-hover)}.parent-report-preview .summary-card{padding:12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface)}.parent-report-preview .summary-card span{display:block;font-size:.76rem;color:var(--text-muted)}.parent-report-preview .summary-card b{display:block;margin-top:4px;color:var(--text-primary);font-size:1.12rem}.parent-report-preview .parent-report-section,.parent-report-preview .parent-report-outstanding,.parent-report-preview .parent-report-comment,.parent-report-preview .parent-report-empty{margin:0 24px 14px}.parent-report-preview .parent-report-section{padding:18px;border-radius:var(--radius-md);box-shadow:var(--shadow-sm)}.parent-report-preview .parent-report-section:first-of-type{margin-top:8px}.parent-report-preview .parent-report-section>header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}.parent-report-preview .parent-report-section h2{font-size:1.08rem}.parent-report-preview .parent-report-row{display:grid;grid-template-columns:132px minmax(0,1fr);gap:14px;padding:11px 0;border-top:1px solid var(--border)}.parent-report-preview .parent-report-label{font-size:.82rem;font-weight:700;color:var(--text-muted)}.parent-report-preview .parent-report-resource{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin:0 0 7px}.parent-report-preview .parent-report-homework{padding:10px 0;border-top:1px dashed var(--border)}.parent-report-preview .parent-report-homework:first-child{padding-top:0;border-top:0}.parent-report-preview .parent-report-homework small{font-size:.8rem}.parent-report-preview .status{display:inline-flex;align-items:center;gap:4px;padding:3px 8px}.parent-report-preview .status-icon{font-weight:800;line-height:1}.parent-report-preview .grade-icon{color:var(--accent)}.parent-report-preview .parent-report-outstanding.is-clear{background:color-mix(in srgb,var(--success) 8%,var(--surface));border-color:color-mix(in srgb,var(--success) 28%,var(--border))}.parent-report-preview .parent-report-comment{background:color-mix(in srgb,var(--accent) 10%,var(--surface));border-left:4px solid var(--accent)}.parent-report-preview .parent-report-comment p{margin:8px 0 0}@media(max-width:768px){.parent-report-preview{padding:10px}.parent-report-preview .parent-report-header{padding:18px}.parent-report-preview .parent-report-header-top{display:block}.parent-report-preview .parent-report-header-title{text-align:left;margin-top:14px}.parent-report-preview .parent-report-meta,.parent-report-preview .parent-report-summary{grid-template-columns:1fr 1fr}.parent-report-preview .parent-report-summary{padding:12px}.parent-report-preview .parent-report-section,.parent-report-preview .parent-report-outstanding,.parent-report-preview .parent-report-comment,.parent-report-preview .parent-report-empty{margin-left:12px;margin-right:12px}.parent-report-preview .parent-report-section{padding:14px}.parent-report-preview .parent-report-row{grid-template-columns:1fr;gap:5px}.parent-report-preview .parent-report-label{font-size:.76rem}}
    </style>
    <style>
      .parent-report-preview .parent-report-workshop{border-left:4px solid var(--accent);background:color-mix(in srgb,var(--accent) 5%,var(--surface))}
      .parent-report-preview .parent-report-workshop-label{display:inline-block;margin:0 0 4px;font-size:.7rem;font-weight:750;letter-spacing:.08em;text-transform:uppercase;color:var(--accent)}
    </style>
    <style>
      .parent-report-source{display:inline-flex;align-items:center;border:1px solid var(--border);border-radius:999px;padding:3px 8px;color:var(--text-muted);font-size:.72rem;white-space:nowrap}.parent-report-source.is-manual{border-color:color-mix(in srgb,var(--accent) 45%,var(--border));color:var(--accent);background:color-mix(in srgb,var(--accent) 8%,var(--surface))}.parent-report-grade{font-weight:750;color:var(--text-primary)}.report-editor-heading{display:flex;justify-content:space-between;gap:12px;align-items:baseline;margin:0 0 14px}.report-editor-heading span{font-size:.85rem;color:var(--text-muted)}.report-editor-section{border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;margin:0 0 12px;background:var(--surface-hover)}.report-editor-section legend{float:none;width:auto;padding:0 6px;margin:0;font-size:.92rem;font-weight:700;color:var(--text-primary)}.report-editor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.report-editor-grid label{display:grid;gap:5px;font-size:.78rem;font-weight:700;color:var(--text-muted)}.report-reset-section{margin-top:10px;color:var(--danger)!important}.report-review-card [data-report-editor]{margin:0 0 18px;padding:16px;border:1px solid color-mix(in srgb,var(--secondary) 35%,var(--border));border-radius:var(--radius-md);background:var(--surface)}@media(max-width:768px){.report-editor-heading{display:block}.report-editor-heading span{display:block;margin-top:3px}.report-editor-grid{grid-template-columns:1fr}.parent-report-source{margin-top:8px}}
    </style>
</head>
<body class="dash ds-bg-primary"><div class="col-12 d-flex"><?php include 'layouts/admin/aside.php'; ?><div class="main-content in-active"><?php include 'layouts/admin/top-nav.php'; ?>
<main class="parent-report-admin">
  <div class="report-card"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3"><div><div class="course-manager-eyebrow">Course reporting</div><h1 class="h3 mb-1">Parent Weekly Reports</h1><p class="ds-text-muted mb-0">Select a course, enrolled student, and reporting period. Reports use confirmed attendance and real LMS records only.</p></div><?php if ($course): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $escape($base . '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/content') ?>">Section Integrity</a><?php endif ?></div></div>
  <?php if ($error): ?><div class="alert alert-danger"><?= $escape($error) ?></div><?php endif; ?><?php if ($success): ?><div class="alert alert-success"><?= $escape($success) ?></div><?php endif; ?>
  <form method="post" action="<?= $escape($base . '/admin/parent-reports') ?>" class="report-card" id="parent-report-form"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><div class="report-grid"><label>Course<select class="form-control" name="course_id" required data-course-select><option value="">Select course</option><?php foreach ($courses as $availableCourse): ?><option value="<?= $escape($availableCourse['course_id']) ?>" <?= $selectedCourseId === (string) $availableCourse['course_id'] ? 'selected' : '' ?>><?= $escape($availableCourse['course_title']) ?></option><?php endforeach ?></select></label><label>Student<select class="form-control" name="student_id" required <?= $course ? '' : 'disabled' ?>><option value="">Select student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['user_id'] ?>" <?= $selectedStudentId === (int) $student['user_id'] ? 'selected' : '' ?>><?= $escape($student['full_name'] ?: $student['username']) ?></option><?php endforeach ?></select></label><label>Reporting period<select class="form-control" name="period" data-period-select><?php foreach ($periodOptions as $key => $label): ?><option value="<?= $escape($key) ?>" <?= $periodKey === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach ?></select></label><label>Start date<input class="form-control" type="date" name="week_start" value="<?= $escape($start) ?>" required></label><label>End date<input class="form-control" type="date" name="week_end" value="<?= $escape($end) ?>" required></label></div><div class="report-actions"><button class="btn btn-outline-secondary btn-sm" type="button" data-current-week data-start="<?= $escape($week['start']) ?>" data-end="<?= $escape($week['end']) ?>">Use current teaching week</button><button class="btn btn-primary" type="submit" name="report_action" value="preview">Preview report</button></div>
    <label class="d-block mt-3">Teacher Comment <small class="ds-text-muted">Optional, factual, and reviewed by the teacher before inclusion.</small><textarea class="form-control mt-2" rows="3" maxlength="1000" name="teacher_comment" placeholder="Optional short comment"><?= $escape($report['comment'] ?? $comment) ?></textarea></label><div class="report-actions"><button class="btn btn-outline-secondary btn-sm" type="submit" name="report_action" value="save_comment">Save comment</button><?php if ($report && $report['suggested_comment']): ?><button class="btn btn-link btn-sm p-0" type="button" data-suggested-comment="<?= $escape($report['suggested_comment']) ?>">Use factual suggestion</button><?php endif ?></div>
  </form>
  <?php if ($report): ?>
    <section class="report-card report-review-card">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h2 class="h5 mb-1">Preview</h2><p class="ds-text-muted mb-0">Review the final parent-facing values before saving or generating the PDF.</p></div><div class="report-actions mt-0"><button type="button" class="btn btn-outline-secondary btn-sm" data-copy-summary>Copy WhatsApp summary</button><button type="button" class="btn btn-primary btn-sm" data-toggle-report-editor>Edit Report</button></div></div>
      <form method="post" action="<?= $escape($base . '/admin/parent-reports') ?>" data-report-editor hidden>
        <input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="override_form" value="1"><input type="hidden" name="course_id" value="<?= $escape($selectedCourseId) ?>"><input type="hidden" name="student_id" value="<?= (int) $selectedStudentId ?>"><input type="hidden" name="period" value="<?= $escape($periodKey) ?>"><input type="hidden" name="week_start" value="<?= $escape($start) ?>"><input type="hidden" name="week_end" value="<?= $escape($end) ?>"><input type="hidden" name="confirm_reset_entire" value="0" data-reset-confirm>
        <div class="report-editor-heading"><strong>Edit report details</strong><span>These adjustments apply only to this Parent Report draft.</span></div>
        <?php foreach ($report['sections'] as $section): $final = $section['final']; $sectionId = (string) $section['section_id']; $isWorkshop = !empty($section['is_workshop']); ?>
          <fieldset class="report-editor-section" data-section-editor><legend><?= $escape($section['title']) ?><?= $isWorkshop ? ' · Workshop' : '' ?></legend>
            <div class="report-editor-grid">
              <?php if (!$isWorkshop): ?><label>Live Session<?= $reportSelect('overrides[' . $sectionId . '][attendance]', ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused', 'not_recorded' => 'Not Recorded'], $final['attendance']) ?></label><label>Recording<?= $reportSelect('overrides[' . $sectionId . '][recording]', ['viewed' => 'Viewed', 'not_viewed' => 'Not Viewed', 'not_required' => 'Not Required', 'no_recording' => 'No Recording'], $final['recording']) ?></label><?php else: ?><label>Revision Video<?= $reportSelect('overrides[' . $sectionId . '][revision]', ['viewed' => 'Viewed', 'not_viewed' => 'Not Viewed', 'no_video' => 'No Video'], $final['revision']) ?></label><?php endif; ?>
              <label>Homework<?= $reportSelect('overrides[' . $sectionId . '][homework]', ['submitted' => 'Submitted', 'missing' => 'Missing', 'waiting_for_grade' => 'Waiting for Grade', 'no_homework' => 'No Homework'], $final['homework']) ?></label><label>Homework Score<?= $reportScore('overrides[' . $sectionId . '][homework_score]', $final['homework_grade'], 'score') ?></label><label>Homework Maximum<?= $reportScore('overrides[' . $sectionId . '][homework_max_score]', $final['homework_grade'], 'max') ?></label>
              <?php if ($isWorkshop): ?><label>Exam<?= $reportSelect('overrides[' . $sectionId . '][exam]', ['completed' => 'Completed', 'not_completed' => 'Not Completed', 'waiting_for_grade' => 'Waiting for Grade', 'no_exam' => 'No Exam'], $final['exam']) ?></label><label>Exam Score<?= $reportScore('overrides[' . $sectionId . '][exam_score]', $final['exam_grade'], 'score') ?></label><label>Exam Maximum<?= $reportScore('overrides[' . $sectionId . '][exam_max_score]', $final['exam_grade'], 'max') ?></label><?php endif; ?>
            </div><button class="btn btn-link btn-sm p-0 report-reset-section" type="submit" name="report_action" value="reset_section" onclick="this.form.querySelector('[name=reset_section_id]').value='<?= $escape($sectionId) ?>'">Reset to LMS Data</button>
          </fieldset>
        <?php endforeach; ?>
        <input type="hidden" name="reset_section_id" value=""><div class="report-actions"><button class="btn btn-outline-danger btn-sm" type="button" data-reset-entire>Reset Entire Report</button><span class="flex-grow-1"></span><button class="btn btn-outline-secondary" type="submit" name="report_action" value="save_draft">Save Report Draft</button><button class="btn btn-primary" type="submit" name="report_action" value="download">Generate Final PDF</button></div>
      </form>
      <textarea class="form-control mb-3" rows="10" readonly data-report-summary><?= $escape(mmh_parent_report_text($report)) ?></textarea><div class="parent-report-preview"><?= mmh_parent_report_html($report, true) ?></div>
    </section>
  <?php endif; ?>
</main></div></div><script>document.querySelector('[data-course-select]')?.addEventListener('change',function(){const url=new URL(window.location.href);url.searchParams.set('course_id',this.value);url.searchParams.delete('student_id');window.location.assign(url.toString());});document.querySelector('[data-period-select]')?.addEventListener('change',function(){const form=document.getElementById('parent-report-form');if(this.value!=='custom'){form.week_start.readOnly=true;form.week_end.readOnly=true;}else{form.week_start.readOnly=false;form.week_end.readOnly=false;}});document.querySelector('[data-current-week]')?.addEventListener('click',function(){const f=document.getElementById('parent-report-form');f.period.value='current_week';f.week_start.value=this.dataset.start;f.week_end.value=this.dataset.end;f.week_start.readOnly=true;f.week_end.readOnly=true;});document.querySelector('[data-suggested-comment]')?.addEventListener('click',function(){document.querySelector('[name="teacher_comment"]').value=this.dataset.suggestedComment;});document.querySelector('[data-copy-summary]')?.addEventListener('click',function(){const field=document.querySelector('[data-report-summary]');field?.select();navigator.clipboard?.writeText(field.value);this.textContent='Copied';setTimeout(()=>this.textContent='Copy WhatsApp summary',1200);});document.querySelector('[data-toggle-report-editor]')?.addEventListener('click',function(){const editor=document.querySelector('[data-report-editor]');if(!editor)return;editor.hidden=!editor.hidden;this.textContent=editor.hidden?'Edit Report':'Hide Editor';if(!editor.hidden)editor.scrollIntoView({behavior:'smooth',block:'start'});});document.querySelectorAll('[data-section-editor]').forEach(function(section){const attendance=section.querySelector('select[name$="[attendance]"]');const recording=section.querySelector('select[name$="[recording]"]');attendance?.addEventListener('change',function(){if(recording&&(this.value==='present'||this.value==='late'))recording.value='not_required';});});document.querySelector('[data-reset-entire]')?.addEventListener('click',function(){if(!window.confirm('Reset all report adjustments and return to LMS data?'))return;const form=this.closest('form');form.querySelector('[data-reset-confirm]').value='1';const action=document.createElement('input');action.type='hidden';action.name='report_action';action.value='reset_report';form.appendChild(action);form.submit();});</script></body></html>
