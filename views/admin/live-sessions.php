<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LiveSessions.php';

$username = $_SESSION['admin'];
$pageName = 'courses';
$subPageName = 'live_sessions';
$conn = db();
mmh_live_ensure_schema($conn);

$selectedCourse = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
$attendanceOccurrenceId = isset($_GET['occurrence']) ? trim((string) $_GET['occurrence']) : '';
$courses = mmh_live_course_options($conn);
$schedules = mmh_live_schedule_rows($conn, $selectedCourse);
$occurrences = mmh_live_occurrences($conn, $selectedCourse, -3, 45);
$attendanceOccurrence = $attendanceOccurrenceId !== '' ? mmh_live_occurrence($conn, $attendanceOccurrenceId) : null;
$attendanceRows = $attendanceOccurrence ? mmh_live_students_for_occurrence($conn, $attendanceOccurrence) : [];
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$liveRequestBase = rtrim((string) $baseUrl, '/') . '/admin/requests/live-session';

function live_admin_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Sessions | <?=$site_name;?></title>
    <?php include "layouts/admin/header.php"; ?>
</head>
<body class='dash ds-bg-primary'>
<form method="POST" action="<?=$baseUrl?>/resources/logout" id="logout-form" class="d-none"></form>
<div class="col-12 d-flex">
    <?php include "layouts/admin/aside.php"; ?>
    <div class="main-content in-active" style="overflow: hidden">
        <?php include "layouts/admin/top-nav.php"; ?>
        <div class="col-12 px-0" style="margin-top: 55px; position: relative">
            <div class="col-12 p-3">
                <div class="main-box p-4">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-4">
                        <div>
                            <h1 class="h4 mb-1">Live Sessions</h1>
                            <p class="ds-text-secondary mb-0">Recurring weekly schedules, generated sessions, and manual attendance.</p>
                        </div>
                        <form method="GET" class="d-flex gap-2 align-items-center">
                            <select name="course" class="form-control" style="min-width: 260px">
                                <option value="">All courses</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?=live_admin_html($course['course_id'])?>" <?=$selectedCourse === (string) $course['course_id'] ? 'selected' : ''?>><?=live_admin_html($course['course_title'])?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary" type="submit">Filter</button>
                        </form>
                    </div>

                    <div id="live-admin-feedback" class="ds-card ds-surface-muted d-none mb-4" role="status" aria-live="polite"></div>

                    <div class="row g-4">
                        <div class="col-12 col-xl-4">
                            <section class="ds-card h-100">
                                <h2 class="h5 mb-3">Add weekly session</h2>
                                <form action="<?=live_admin_html($liveRequestBase)?>/save-schedule" method="POST" class="live-admin-ajax">
                                    <div class="mb-3">
                                        <label class="form-label">Course</label>
                                        <select name="course_id" class="form-control" required>
                                            <?php foreach ($courses as $course): ?>
                                                <option value="<?=live_admin_html($course['course_id'])?>" <?=$selectedCourse === (string) $course['course_id'] ? 'selected' : ''?>><?=live_admin_html($course['course_title'])?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3"><label class="form-label">Schedule title</label><input class="form-control" name="title" placeholder="Optional, e.g. Lecture 1"></div>
                                    <div class="row g-2">
                                        <div class="col-6"><label class="form-label">Day</label><select class="form-control" name="day_of_week" required><?php foreach ($days as $i => $day): ?><option value="<?=$i?>"><?=$day?></option><?php endforeach; ?></select></div>
                                        <div class="col-6"><label class="form-label">Start time</label><input class="form-control" type="time" name="start_time" required></div>
                                    </div>
                                    <div class="row g-2 mt-1">
                                        <div class="col-6"><label class="form-label">Duration</label><input class="form-control" type="number" min="1" name="duration_minutes" value="90" required></div>
                                        <div class="col-6"><label class="form-label">Timezone</label><input class="form-control" name="timezone" value="Asia/Riyadh" required></div>
                                    </div>
                                    <div class="mb-3 mt-2"><label class="form-label">Teams URL</label><input class="form-control" type="url" name="teams_url" placeholder="https://teams.microsoft.com/..." required></div>
                                    <div class="mb-3"><label class="form-label">Teams meeting reference</label><input class="form-control" name="teams_meeting_ref" placeholder="Optional future Graph reference"></div>
                                    <div class="row g-2">
                                        <div class="col-6"><label class="form-label">Academic period</label><input class="form-control" name="academic_period" placeholder="2026 Term 1"></div>
                                        <div class="col-6"><label class="form-label">Sort order</label><input class="form-control" type="number" min="0" name="sort_order" value="0"></div>
                                    </div>
                                    <div class="row g-2 mt-1">
                                        <div class="col-6"><label class="form-label">Effective start</label><input class="form-control" type="date" name="effective_start_date" value="<?=date('Y-m-d')?>" required></div>
                                        <div class="col-6"><label class="form-label">Effective end</label><input class="form-control" type="date" name="effective_end_date"></div>
                                    </div>
                                    <div class="mb-3 mt-2"><label class="form-label">Admin notes</label><textarea class="form-control" name="admin_notes" rows="2"></textarea></div>
                                    <div class="mb-3"><label class="form-label">Enabled</label><select class="form-control" name="enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                                    <button class="btn btn-primary w-100" type="submit" data-loading-text="Saving..."><span class="fas fa-save"></span> Save schedule</button>
                                </form>
                            </section>
                        </div>

                        <div class="col-12 col-xl-8">
                            <section class="ds-card mb-4">
                                <h2 class="h5 mb-3">Weekly schedules</h2>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead><tr><th>Course</th><th>When</th><th>Period</th><th>Status</th><th>Actions</th></tr></thead>
                                        <tbody>
                                        <?php if ($schedules): foreach ($schedules as $schedule): ?>
                                            <tr>
                                                <td><?=live_admin_html($schedule['course_title'])?><br><small class="ds-text-muted"><?=live_admin_html($schedule['title'] ?: 'Live session')?></small></td>
                                                <td><?=$days[(int)$schedule['day_of_week']]?> at <?=substr((string)$schedule['start_time'], 0, 5)?><br><small class="ds-text-muted"><?=live_admin_html($schedule['timezone'])?> · <?=live_admin_html($schedule['duration_minutes'])?> min</small></td>
                                                <td><?=live_admin_html($schedule['academic_period'] ?: 'Current')?><br><small class="ds-text-muted"><?=live_admin_html($schedule['effective_start_date'])?> → <?=live_admin_html($schedule['effective_end_date'] ?: 'Open')?></small></td>
                                                <td><span class="badge <?=((int)$schedule['enabled'] === 1 ? 'text-bg-success' : 'text-bg-secondary')?>"><?=((int)$schedule['enabled'] === 1 ? 'Enabled' : 'Disabled')?></span></td>
                                                <td>
                                                    <form action="<?=live_admin_html($liveRequestBase)?>/save-schedule" method="POST" class="live-admin-ajax d-inline-block">
                                                        <?php foreach (['schedule_id','course_id','title','day_of_week','start_time','duration_minutes','timezone','teams_url','teams_meeting_ref','academic_period','effective_start_date','effective_end_date','sort_order','admin_notes'] as $field): ?>
                                                            <input type="hidden" name="<?=$field?>" value="<?=live_admin_html($schedule[$field] ?? '')?>">
                                                        <?php endforeach; ?>
                                                        <input type="hidden" name="enabled" value="<?=((int)$schedule['enabled'] === 1 ? '0' : '1')?>">
                                                        <button class="btn btn-outline-secondary btn-sm" type="submit"><?=((int)$schedule['enabled'] === 1 ? 'Disable' : 'Enable')?></button>
                                                    </form>
                                                    <form action="<?=live_admin_html($liveRequestBase)?>/delete-schedule" method="POST" class="live-admin-ajax d-inline-block">
                                                        <input type="hidden" name="schedule_id" value="<?=live_admin_html($schedule['schedule_id'])?>">
                                                        <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Delete this recurring series? All future occurrences will disappear, while past sessions, attendance, and recordings remain.">Delete series</button>
                                                    </form>
                                                    <form action="<?=live_admin_html($liveRequestBase)?>/save-schedule" method="POST" class="live-admin-ajax d-inline-block">
                                                        <?php foreach (['course_id','title','day_of_week','start_time','duration_minutes','timezone','teams_url','teams_meeting_ref','sort_order','admin_notes'] as $field): ?>
                                                            <input type="hidden" name="<?=$field?>" value="<?=live_admin_html($schedule[$field] ?? '')?>">
                                                        <?php endforeach; ?>
                                                        <input type="hidden" name="academic_period" value="<?=live_admin_html(($schedule['academic_period'] ?: 'Copied') . ' copy')?>">
                                                        <input type="hidden" name="effective_start_date" value="<?=date('Y-m-d')?>">
                                                        <input type="hidden" name="effective_end_date" value="">
                                                        <input type="hidden" name="enabled" value="1">
                                                        <button class="btn btn-outline-primary btn-sm" type="submit">Duplicate</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="5" class="ds-surface-muted">
                                                    <details>
                                                        <summary class="fw-bold" style="cursor: pointer">Edit schedule details</summary>
                                                        <form action="<?=live_admin_html($liveRequestBase)?>/save-schedule" method="POST" class="live-admin-ajax row g-2 mt-2">
                                                            <input type="hidden" name="schedule_id" value="<?=live_admin_html($schedule['schedule_id'])?>">
                                                            <div class="col-12 col-lg-3"><label class="form-label">Course</label><select name="course_id" class="form-control"><?php foreach ($courses as $course): ?><option value="<?=live_admin_html($course['course_id'])?>" <?=$course['course_id'] === $schedule['course_id'] ? 'selected' : ''?>><?=live_admin_html($course['course_title'])?></option><?php endforeach; ?></select></div>
                                                            <div class="col-12 col-lg-3"><label class="form-label">Title</label><input class="form-control" name="title" value="<?=live_admin_html($schedule['title'])?>"></div>
                                                            <div class="col-6 col-lg-2"><label class="form-label">Day</label><select class="form-control" name="day_of_week"><?php foreach ($days as $i => $day): ?><option value="<?=$i?>" <?=$i === (int)$schedule['day_of_week'] ? 'selected' : ''?>><?=$day?></option><?php endforeach; ?></select></div>
                                                            <div class="col-6 col-lg-2"><label class="form-label">Time</label><input class="form-control" type="time" name="start_time" value="<?=live_admin_html(substr((string)$schedule['start_time'], 0, 5))?>"></div>
                                                            <div class="col-6 col-lg-2"><label class="form-label">Duration</label><input class="form-control" type="number" min="1" name="duration_minutes" value="<?=live_admin_html($schedule['duration_minutes'])?>"></div>
                                                            <div class="col-12 col-lg-3"><label class="form-label">Timezone</label><input class="form-control" name="timezone" value="<?=live_admin_html($schedule['timezone'])?>"></div>
                                                            <div class="col-12 col-lg-5"><label class="form-label">Teams URL</label><input class="form-control" type="url" name="teams_url" value="<?=live_admin_html($schedule['teams_url'])?>"></div>
                                                            <div class="col-12 col-lg-4"><label class="form-label">Teams reference</label><input class="form-control" name="teams_meeting_ref" value="<?=live_admin_html($schedule['teams_meeting_ref'])?>"></div>
                                                            <div class="col-12 col-lg-3"><label class="form-label">Academic period</label><input class="form-control" name="academic_period" value="<?=live_admin_html($schedule['academic_period'])?>"></div>
                                                            <div class="col-6 col-lg-3"><label class="form-label">Effective start</label><input class="form-control" type="date" name="effective_start_date" value="<?=live_admin_html($schedule['effective_start_date'])?>"></div>
                                                            <div class="col-6 col-lg-3"><label class="form-label">Effective end</label><input class="form-control" type="date" name="effective_end_date" value="<?=live_admin_html($schedule['effective_end_date'])?>"></div>
                                                            <div class="col-6 col-lg-2"><label class="form-label">Enabled</label><select class="form-control" name="enabled"><option value="1" <?=((int)$schedule['enabled'] === 1 ? 'selected' : '')?>>Enabled</option><option value="0" <?=((int)$schedule['enabled'] !== 1 ? 'selected' : '')?>>Disabled</option></select></div>
                                                            <div class="col-6 col-lg-2"><label class="form-label">Sort</label><input class="form-control" type="number" min="0" name="sort_order" value="<?=live_admin_html($schedule['sort_order'])?>"></div>
                                                            <div class="col-12"><label class="form-label">Admin notes</label><textarea class="form-control" name="admin_notes" rows="2"><?=live_admin_html($schedule['admin_notes'])?></textarea></div>
                                                            <div class="col-12"><button class="btn btn-primary btn-sm" type="submit" data-loading-text="Saving changes...">Save schedule changes</button></div>
                                                        </form>
                                                    </details>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="5" class="text-center ds-text-muted py-4">No weekly schedules yet.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section class="ds-card">
                                <h2 class="h5 mb-3">Upcoming generated sessions</h2>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead><tr><th>Session</th><th>Time</th><th>Status</th><th>Actions</th></tr></thead>
                                        <tbody>
                                        <?php if ($occurrences): foreach (array_slice($occurrences, 0, 25) as $occurrence): ?>
                                            <tr>
                                                <td><?=live_admin_html($occurrence['course_title'])?><br><small class="ds-text-muted"><?=live_admin_html($occurrence['schedule_title'] ?: 'Live session')?></small></td>
                                                <td><?=live_admin_html(mmh_live_display_time($occurrence))?><br><small class="ds-text-muted"><?=live_admin_html($occurrence['timezone'])?></small></td>
                                                <td><span class="badge text-bg-light"><?=live_admin_html(ucwords(str_replace('_', ' ', $occurrence['status'])))?></span></td>
                                                <td style="min-width: 330px">
                                                    <a class="btn btn-outline-primary btn-sm" href="live-sessions?occurrence=<?=live_admin_html($occurrence['occurrence_id'])?>&course=<?=live_admin_html($occurrence['course_id'])?>">Attendance</a>
                                                    <form action="<?=live_admin_html($liveRequestBase)?>/update-occurrence" method="POST" class="live-admin-ajax d-inline-flex gap-1 flex-wrap">
                                                        <input type="hidden" name="occurrence_id" value="<?=live_admin_html($occurrence['occurrence_id'])?>">
                                                        <select name="status" class="form-control form-control-sm" style="width: 125px">
                                                            <?php foreach (['scheduled','live','completed','cancelled','rescheduled'] as $status): ?><option value="<?=$status?>" <?=$status === $occurrence['status'] ? 'selected' : ''?>><?=ucwords($status)?></option><?php endforeach; ?>
                                                        </select>
                                                        <input class="form-control form-control-sm" type="datetime-local" name="scheduled_start_at" title="Optional reschedule start" style="width: 185px">
                                                        <input class="form-control form-control-sm" type="url" name="replacement_url" placeholder="Replacement Teams URL" style="width: 210px">
                                                        <input class="form-control form-control-sm" name="change_note" placeholder="Note" style="width: 130px">
                                                        <button class="btn btn-outline-secondary btn-sm" type="submit">Update</button>
                                                    </form>
                                                    <form action="<?=live_admin_html($liveRequestBase)?>/delete-occurrence" method="POST" class="live-admin-ajax d-inline-block">
                                                        <input type="hidden" name="occurrence_id" value="<?=live_admin_html($occurrence['occurrence_id'])?>">
                                                        <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Delete only this occurrence? Attendance and recording records will not be removed.">Delete occurrence</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="4" class="text-center ds-text-muted py-4">No sessions generated in the current window.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </div>

                    <?php if ($attendanceOccurrence): ?>
                        <section class="ds-card mt-4 live-attendance-panel" id="live-attendance-panel">
                            <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
                                <div>
                                    <h2 class="h5 mb-1">Attendance: <?=live_admin_html($attendanceOccurrence['course_title'])?></h2>
                                    <p class="ds-text-secondary mb-0"><?=live_admin_html(mmh_live_display_time($attendanceOccurrence))?> · Join click is evidence only, not automatic attendance.</p>
                                </div>
                                <span class="badge text-bg-light"><?=live_admin_html(ucwords(str_replace('_', ' ', $attendanceOccurrence['status'])))?></span>
                            </div>
                            <form action="<?=live_admin_html($liveRequestBase)?>/save-attendance" method="POST" class="live-admin-ajax" data-live-refresh="#live-attendance-panel">
                                <input type="hidden" name="occurrence_id" value="<?=live_admin_html($attendanceOccurrence['occurrence_id'])?>">
                                <div class="live-attendance-actions d-flex flex-wrap gap-2 mb-3">
                                    <button name="bulk_action" value="confirm_joined_present" class="btn btn-outline-success btn-sm" type="submit" data-confirm="Confirm Unknown students with Join evidence as Present?" data-loading-text="Confirming...">Confirm joined students as Present</button>
                                    <button name="bulk_action" value="mark_remaining_absent" class="btn btn-outline-warning btn-sm" type="submit" data-confirm="Mark remaining Unknown students as Absent?" data-loading-text="Marking...">Mark remaining Unknown as Absent</button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle live-attendance-table">
                                        <thead><tr><th>Student</th><th>Join evidence</th><th>Current attendance</th><th>Teacher action</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($attendanceRows as $row): ?>
                                            <?php
                                                $currentStatus = $row['status'] ?: 'unknown';
                                                $evidence = mmh_live_join_evidence($row, $attendanceOccurrence);
                                            ?>
                                            <tr>
                                                <td><strong><?=live_admin_html($row['full_name'] ?: $row['username'])?></strong><br><small class="ds-text-muted">ID: <?=live_admin_html($row['user_id'])?></small></td>
                                                <td><span class="live-evidence-badge <?=$evidence['has_join'] ? 'has-join' : 'no-join'?>"><?=live_admin_html($evidence['label'])?></span><br><small class="ds-text-muted"><?=live_admin_html($evidence['detail'])?></small></td>
                                                <td><span class="live-attendance-status status-<?=live_admin_html($currentStatus)?>"><?=live_admin_html(mmh_live_attendance_label($currentStatus))?></span><?php if (!empty($row['first_join_clicked_at']) && $currentStatus === 'unknown'): ?><br><small class="ds-text-muted">Clicked but awaiting teacher confirmation</small><?php endif; ?></td>
                                                <td>
                                                    <select name="attendance[<?=live_admin_html($row['user_id'])?>][status]" class="form-control form-control-sm mb-2" aria-label="Attendance status for <?=live_admin_html($row['full_name'] ?: $row['username'])?>">
                                                        <?php foreach (mmh_live_attendance_statuses() as $status => $statusLabel): ?>
                                                            <option value="<?=$status?>" <?=$status === $currentStatus ? 'selected' : ''?>><?=live_admin_html($statusLabel)?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input class="form-control form-control-sm" name="attendance[<?=live_admin_html($row['user_id'])?>][note]" value="<?=live_admin_html($row['teacher_note'] ?? '')?>" maxlength="500" placeholder="Private teacher note">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button class="btn btn-primary" type="submit" data-loading-text="Saving attendance...">Save attendance</button>
                            </form>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
  var feedback = document.getElementById('live-admin-feedback');
  function showFeedback(message, success) {
    if (!feedback) {
      return;
    }
    feedback.textContent = message;
    feedback.classList.remove('d-none');
    feedback.classList.toggle('border-success', !!success);
    feedback.classList.toggle('border-danger', !success);
  }

  document.addEventListener('submit', function(event) {
    var form = event.target.closest('.live-admin-ajax');
    if (!form) {
      return;
    }
    event.preventDefault();
    if (form.dataset.liveSubmitting === '1') {
      return;
    }
    var submitter = event.submitter;
    var message = submitter ? submitter.getAttribute('data-confirm') : '';
    if (message && !window.confirm(message)) {
      return;
    }
    var button = submitter || form.querySelector('button[type="submit"]');
    var originalButtonHtml = button ? button.innerHTML : '';
    form.dataset.liveSubmitting = '1';
    if (button) {
      button.disabled = true;
      button.innerHTML = '<span class="fas fa-spinner fa-spin"></span> ' + (button.getAttribute('data-loading-text') || 'Saving...');
    }

    fetch(new URL(form.getAttribute('action'), window.location.href).toString(), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function(response) {
      return response.text().then(function(text) {
        var data;
        try {
          data = text ? JSON.parse(text) : {};
        } catch (error) {
          throw new Error('The server returned an invalid response.');
        }
        if (!response.ok && !data.message) {
          data.message = 'Unable to save. Please try again.';
        }
        return data;
      });
    }).then(function(data) {
      var success = !!(data.success || data.status === 1);
      showFeedback(data.message || (success ? 'Saved.' : 'Unable to save.'), success);
      if (success) {
        var refreshSelector = form.getAttribute('data-live-refresh');
        if (refreshSelector) {
          fetch(window.location.href, { credentials: 'same-origin' })
            .then(function(response) { return response.text(); })
            .then(function(html) {
              var parser = new DOMParser();
              var nextDocument = parser.parseFromString(html, 'text/html');
              var nextPanel = nextDocument.querySelector(refreshSelector);
              var currentPanel = document.querySelector(refreshSelector);
              if (nextPanel && currentPanel) {
                currentPanel.replaceWith(nextPanel);
              }
            });
        } else {
          window.setTimeout(function() {
            window.location.reload();
          }, 350);
        }
      }
    }).catch(function(error) {
      showFeedback(error.message || 'Unexpected server error.', false);
    }).finally(function() {
      form.dataset.liveSubmitting = '0';
      if (button) {
        button.disabled = false;
        button.innerHTML = originalButtonHtml;
      }
    });
  });
})();
</script>
</body>
</html>
