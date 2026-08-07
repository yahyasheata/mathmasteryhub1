<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AssignmentProgress.php';
require_once 'inc/LegacyHomework.php';
require_once 'inc/AdminAssessmentService.php';
$username = $_SESSION['admin'];
$pageName = "courses";
$subPageName = "assignments";

$conn = db();
mmh_ensure_learning_schema($conn);
$assignment_rows = mmh_admin_assignment_rows($conn);
$submission_counts = mmh_admin_assignment_submission_counts($conn);
// Query for all courses for the select dropdown
$courses_result = mysqli_query($conn, "SELECT course_id, course_title FROM courses ORDER BY course_title");
$sections_result = mysqli_query($conn, "SELECT section_id, course_id, title FROM course_sections ORDER BY course_id, sort_order, title");
$items_result = mysqli_query($conn, "SELECT item_id, course_id, section_id, item_title FROM course_items ORDER BY course_id, page_order, item_title");
// Legacy import uses existing courses, sections, assignments and enrollments.
$legacy_courses = $conn->query("SELECT course_id, course_title FROM courses ORDER BY course_title");
$legacy_sections = $conn->query("SELECT section_id, course_id, title FROM course_sections ORDER BY course_id, sort_order, title");
$legacy_assignments = $conn->query("SELECT assignment_id, course_id, section_id, assignment_title FROM assignments WHERE archived_at IS NULL ORDER BY course_id, due_date, assignment_title");
$legacy_students = $conn->query("SELECT user_id, full_name, username FROM users WHERE role = 'user' ORDER BY full_name, username");
$canonical_item_map = [];
$canonical_item_result = $conn->query("SELECT a.assignment_id, a.course_id, a.item_id, i.id AS item_db_id, i.item_title, i.template_type, i.status AS item_status FROM assignments a INNER JOIN course_items i ON i.course_id = a.course_id AND i.item_id = a.item_id WHERE a.archived_at IS NULL AND i.template_type = 'classified_assignment'");
if ($canonical_item_result) {
  while ($canonical_item = $canonical_item_result->fetch_assoc()) {
    $canonical_item_map[(string) $canonical_item['assignment_id']] = $canonical_item;
  }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assignments | <?= $site_name; ?></title>
  <meta name="title" content="Assignments | <?= $site_name; ?>">
  <?php include "layouts/admin/header.php"; ?>
  <script src="<?= $baseUrl ?>/resources/js/jquery-ui.min.js"></script>
  <script src="<?= $baseUrl ?>/resources/js/jquery.ui.touch-punch.min.js"></script>
  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
</head>

<body class='dash ds-bg-primary'>
  <div class="col-12 justify-content-end d-flex"></div>
  <form method="POST" action="<?= $baseUrl ?>/admin/logout" id="logout-form" class="d-none"><input type="hidden" name="mmh_csrf_token" value="<?=htmlspecialchars(mmh_admin_csrf_token(), ENT_QUOTES, 'UTF-8')?>"></form>
  <div class="col-12 d-flex">
    <?php include "layouts/admin/aside.php"; ?>
    <div class="main-content in-active" style="overflow: hidden">
      <?php include "layouts/admin/top-nav.php"; ?>
      <div class="col-12 px-0" style="margin-top: 55px; position: relative">
        <div id="loading-image-container" class='ds-surface' style="position: fixed; display: flex; align-items: center; justify-content: center; height: 100vh; z-index: 10; margin-top: -15px">
          <img src="<?= $baseUrl ?>/resources/images/loading.gif" style="position:fixed;width: 120px;max-width: 80%;margin-top: -60px;" id="loading-image">
        </div>
        <div class="col-12 p-3">
          <div class="col-12 col-lg-12 p-0 main-box">
            <div class="col-12 px-0">
              <div class="col-12 p-0 row">
                <div class="col-12 col-lg-8 py-3 px-3">
                  <span class="fas fa-tasks"></span> Assignment overview <span class="badge bg-secondary ms-2">Compatibility view</span>
                  <div class="small ds-text-muted mt-1">Create and manage assignments from the Assignment element inside Course Content.</div>
                </div>
                <div class="col-12 col-lg-4 p-0"></div>
                <div class="col-12 col-lg-4 p-2 text-lg-end">
                  <a href="courses" class="btn btn-primary btn-sm"><span class="fas fa-book-open"></span> Open Course Content</a>
                  <?php if (false): ?><div class="modal fade" id="addAssignmentModal" aria-labelledby="addAssignmentLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title" id="addAssignmentLabel">Add New Assignment</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <form action="requests/assignment/add" method="POST" id="addAssignment" enctype="multipart/form-data">
                            <fieldset class="form-fieldset api-mode">
                              <label class='ds-text-secondary' style="display: flex; justify-content: center; font-size: 18px">Assignment Details</label>
                              <div class="col-12 p-3 row">
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Title</div>
                                  <div class="col-12 pt-3">
                                    <input type="text" name="assignment_title" required maxlength="190" class="form-control" placeholder="اكتب Assignment Title">
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Description</div>
                                  <div class="col-12 pt-3">
                                    <textarea class="form-control" name="assignment_description" rows="2" placeholder="اكتب وصف Assignment هنا" required></textarea>
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Due Date</div>
                                  <div class="col-12 pt-3">
                                    <input type="date" name="due_date" required class="form-control">
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="late_submission_enabled" value="1" data-late-submission-enabled><span class="form-check-label">Enable Legacy Late Submission</span></label>
                                  <div class="col-12 pt-2" data-late-submission-until hidden><label class="form-label">Late Submission Until</label><input type="datetime-local" name="late_submission_until" class="form-control"></div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Course</div>
                                  <div class="col-12 pt-3">
                                    <select name="course_id" class="form-control" id="assignmentCourseSelect" required>
                                      <option value="">Select Course</option>
                                      <?php
                                      // Reset pointer in case $courses_result was used before
                                      if (isset($courses_result) && mysqli_num_rows($courses_result) > 0) {
                                        mysqli_data_seek($courses_result, 0);
                                        while ($course = mysqli_fetch_assoc($courses_result)) {
                                          echo '<option value="' . htmlspecialchars($course['course_id']) . '">' . htmlspecialchars($course['course_title']) . '</option>';
                                        }
                                      } else {
                                        echo '<option value="">No courses available</option>';
                                      }
                                      ?>
                                    </select>
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Requirement</div>
                                  <div class="col-12 pt-3"><select name="completion_requirement" class="form-control" data-assignment-requirement><option value="optional">Optional</option><option value="lesson">Required for this lesson</option><option value="section">Required for this section</option></select></div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Completion Rule</div>
                                  <div class="col-12 pt-3"><select name="completion_rule" class="form-control" data-assignment-rule><option value="submission">Submission only</option><option value="teacher_approval">Teacher approval</option><option value="valid_score">Valid final score</option><option value="minimum_score">Minimum score</option></select></div>
                                </div>
                                <div class="col-12 col-lg-6 p-2" data-assignment-minimum>
                                  <div class="col-12">Minimum Score</div><div class="col-12 pt-3"><input type="number" min="0" step="0.01" name="minimum_score" class="form-control"></div>
                                </div>
                                <div class="col-12 col-lg-6 p-2" data-assignment-section-wrap>
                                  <div class="col-12">Section</div><div class="col-12 pt-3"><select name="section_id" class="form-control" data-assignment-section><option value="">General / no section</option><?php if ($sections_result) { while ($section = mysqli_fetch_assoc($sections_result)) { echo '<option value="' . htmlspecialchars($section['section_id']) . '" data-course-id="' . htmlspecialchars($section['course_id']) . '">' . htmlspecialchars($section['title']) . '</option>'; } } ?></select></div>
                                </div>
                                <div class="col-12 col-lg-6 p-2" data-assignment-item-wrap>
                                  <div class="col-12">Linked Lesson</div><div class="col-12 pt-3"><select name="item_id" class="form-control" data-assignment-item><option value="">No linked lesson</option><?php if ($items_result) { while ($item = mysqli_fetch_assoc($items_result)) { echo '<option value="' . htmlspecialchars($item['item_id']) . '" data-course-id="' . htmlspecialchars($item['course_id']) . '" data-section-id="' . htmlspecialchars($item['section_id']) . '">' . htmlspecialchars($item['item_title']) . '</option>'; } } ?></select></div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Attach File <small class='text-primary'>{Optional}</small></div>
                                  <div class="col-12 pt-3">
                                    <input type="file" name="assignment_file" class="form-control" accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                  </div>
                                </div>
                              </div>
                            </fieldset>
                            <div class='progress ds-text-inverse' style="margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px" id="progress-div">
                              <div class="progress-bar bg-success" role="progressbar" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100" id="progress-bar"></div>
                            </div>
                        </div>
                        <div class="modal-footer p-2">
                          <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Close</button>
                          <button type="submit" class="btn btn-outline-primary submitBtn">Save</button>
                        </div>
                        </form>
                      </div>
                    </div>
                  </div><?php endif; ?>
                </div>
              </div>
              <div class="col-12 divider" style="min-height: 2px"></div>
            </div>
            <div class="col-12 p-3" style="overflow: auto">
              <div class="col-12 p-0" style="min-width: 1100px">
                <table class="table table-bordered table-hover text-start" id='assignmentsTable' dir="ltr">
                  <thead>
                    <tr class="text-start">
                      <th class="text-start">#</th>
                      <th class="text-start">Title</th>
                      <th class="text-start">Description</th>
                      <th class="text-start">Due Date</th>
                      <th class="text-start">Requirement</th>
                      <th class="text-start">Completion Rule</th>
                      <th class="text-start">Submission Count</th>
                      <th class="text-start">Submissions</th>
                      <th class="text-start">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $count = 1;
                    foreach ($assignment_rows as $assignment) {
                      $submissions = $submission_counts[(string) $assignment['assignment_id']] ?? 0;
                      echo "<tr>
                                                <td>$count</td>
                                                <td>{$assignment['assignment_title']}</td>
                                                <td>{$assignment['assignment_description']}</td>
                                                <td>{$assignment['due_date']}</td>
                                                <td>" . htmlspecialchars(mmh_assignment_progress_requirement_label($assignment['completion_requirement'] ?? 'optional')) . "</td>
                                                <td>" . htmlspecialchars(mmh_assignment_progress_rule_label($assignment['completion_rule'] ?? 'submission', $assignment['minimum_score'] ?? null)) . "</td>
                                                <td>$submissions</td>
                                                <td>
                                                    <a href='assignment-submissions?assignment_id={$assignment['assignment_id']}' class='btn btn-outline-primary btn-sm font-small mx-1'>
                                                        <span class='fas fa-list'></span> Submissions
                                                    </a>
                                                </td>
                                                <td style='width: 250px'>
                                                    " . (isset($canonical_item_map[(string) $assignment['assignment_id']])
                                                        ? "<a class='btn btn-outline-primary btn-sm font-small mx-1' href='courses/" . rawurlencode((string) $assignment['course_id']) . "/content#course-item-" . (int) $canonical_item_map[(string) $assignment['assignment_id']]['item_db_id'] . "'><span class='fas fa-edit'></span> Open Assignment</a>"
                                                        : "<span class='badge bg-secondary'>Legacy / archived</span>") . "
                                                </td>
                                            </tr>";
                      $count++;
                    }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="col-12 p-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php if (false): ?><div class="modal fade" id="legacyHomeworkImportModal" tabindex="-1" aria-labelledby="legacyHomeworkImportTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="legacyHomeworkImportTitle">Import Legacy Submission</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form id="legacyHomeworkImportForm" action="requests/submission/import-legacy" method="post" enctype="multipart/form-data">
        <div class="modal-body"><p class="ds-text-muted small mb-3">This creates one normal LMS submission and preserves that it was imported by an instructor.</p>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mmh_legacy_homework_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="legacy-course">Course</label><select class="form-control" id="legacy-course" name="course_id" data-legacy-course required><option value="">Select course</option><?php while ($legacy_course = $legacy_courses->fetch_assoc()): ?><option value="<?= htmlspecialchars($legacy_course['course_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($legacy_course['course_title']) ?></option><?php endwhile; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="legacy-section">Section</label><select class="form-control" id="legacy-section" name="section_id" data-legacy-section required><option value="">Select section</option><option value="__general__" data-course-id="">General</option><?php while ($legacy_section = $legacy_sections->fetch_assoc()): ?><option value="<?= htmlspecialchars($legacy_section['section_id'], ENT_QUOTES, 'UTF-8') ?>" data-course-id="<?= htmlspecialchars($legacy_section['course_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($legacy_section['title']) ?></option><?php endwhile; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="legacy-assignment">Homework</label><select class="form-control" id="legacy-assignment" name="assignment_id" data-legacy-assignment required><option value="">Select homework</option><?php while ($legacy_assignment = $legacy_assignments->fetch_assoc()): ?><option value="<?= htmlspecialchars($legacy_assignment['assignment_id'], ENT_QUOTES, 'UTF-8') ?>" data-course-id="<?= htmlspecialchars($legacy_assignment['course_id'], ENT_QUOTES, 'UTF-8') ?>" data-section-id="<?= htmlspecialchars((string) ($legacy_assignment['section_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($legacy_assignment['assignment_title']) ?></option><?php endwhile; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="legacy-student-search">Student</label><input id="legacy-student-search" class="form-control mb-2" type="search" data-legacy-student-search placeholder="Search students"><select class="form-control" name="student_id" data-legacy-student required><option value="">Select student</option><?php while ($legacy_student = $legacy_students->fetch_assoc()): $legacy_student_label = trim((string) ($legacy_student['full_name'] ?? '')) ?: (string) $legacy_student['username']; ?><option value="<?= (int) $legacy_student['user_id'] ?>"><?= htmlspecialchars($legacy_student_label . ' (' . $legacy_student['username'] . ')') ?></option><?php endwhile; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="legacy-submitted-at">Original Submission Date</label><input class="form-control" id="legacy-submitted-at" type="datetime-local" name="original_submitted_at" required></div>
            <div class="col-md-6"><label class="form-label" for="legacy-files">Files</label><input class="form-control" id="legacy-files" type="file" name="legacy_files[]" accept=".pdf,.doc,.docx" multiple required><small class="ds-text-muted">PDF, DOC, or DOCX. You may attach more than one file.</small></div>
            <div class="col-12"><label class="form-label" for="legacy-notes">Instructor Notes <small class="ds-text-muted">(optional)</small></label><textarea class="form-control" id="legacy-notes" name="import_notes" rows="3" maxlength="4000"></textarea></div>
          </div><p class="mt-3 mb-0" data-legacy-import-message role="status" aria-live="polite"></p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-legacy-import-submit>Import Submission</button></div>
      </form>
    </div></div>
  </div><?php endif; ?>
  <div class="ajax-response"></div>
  <script>
    $(document).ready(function() {
      function syncLegacyHomeworkImport() {
        var course = String($('[data-legacy-course]').val() || ''), section = String($('[data-legacy-section]').val() || '');
        $('[data-legacy-section] option[data-course-id]').each(function(){ var sectionCourse = String($(this).data('course-id') || ''); var match = !course || sectionCourse === '' || sectionCourse === course; $(this).prop('hidden', !match).prop('disabled', !match); });
        $('[data-legacy-assignment] option[data-course-id]').each(function(){ var itemCourse = String($(this).data('course-id') || ''), itemSection = String($(this).data('section-id') || ''); var requestedSection = section === '__general__' ? '' : section; var match = !!course && itemCourse === course && itemSection === requestedSection; $(this).prop('hidden', !match).prop('disabled', !match); });
        if ($('[data-legacy-section] option:selected').prop('disabled')) $('[data-legacy-section]').val('');
        if ($('[data-legacy-assignment] option:selected').prop('disabled')) $('[data-legacy-assignment]').val('');
      }
      $(document).on('change', '[data-legacy-course], [data-legacy-section]', syncLegacyHomeworkImport);
      $(document).on('input', '[data-legacy-student-search]', function(){ var query = String(this.value || '').toLowerCase(); $('[data-legacy-student] option').each(function(){ if (!this.value) return; $(this).prop('hidden', query !== '' && $(this).text().toLowerCase().indexOf(query) === -1); }); });
      $('#legacyHomeworkImportModal').on('shown.bs.modal', syncLegacyHomeworkImport);
      $(document).on('submit', '#legacyHomeworkImportForm', function(e) { e.preventDefault(); var form = this, $message = $(form).find('[data-legacy-import-message]'), $submit = $(form).find('[data-legacy-import-submit]'); $message.removeClass('text-danger text-success').text('Importing…'); $submit.prop('disabled', true); $.ajax({ type: 'POST', url: form.action, data: new FormData(form), dataType: 'json', contentType: false, processData: false }).done(function(response){ if (response && response.success) { $message.addClass('text-success').text(response.message); setTimeout(function(){ window.location.reload(); }, 600); } else { $message.addClass('text-danger').text((response && response.message) || 'Unable to import this submission.'); $submit.prop('disabled', false); } }).fail(function(xhr){ var response = xhr.responseJSON || {}; $message.addClass('text-danger').text(response.message || 'Unable to import this submission.'); $submit.prop('disabled', false); }); });
      $(".embedBtn").click(function() {
        // Get the correct data attributes (no 'data-' prefix in .data() keys)
        var assignmentTitle = $(this).data("assignment-title");
        var assignmentId = $(this).data("assignment-id");
        var dueDate = $(this).data("due-date");

        // Generate the HTML code dynamically with the updated data attributes
        var videoButtonCode = '<button class="btn btn-sm show-assignment" data-assignment-id="' + assignmentId + '" data-due-date="' + dueDate + '"><span class="fas fa-lock"></span> ' + assignmentTitle + '</button>';

        // Copy the generated HTML code to clipboard
        copyToClipboard(videoButtonCode);

        // Optionally, you can provide some visual feedback to the user
        alert("Code copied successfully");
      });

    // Function to copy text to clipboard
    function copyToClipboard(text) {
        var tempInput = $("<input>");
        $("body").append(tempInput);
        tempInput.val(text).select();
        document.execCommand("copy");
        tempInput.remove();
    }
      function syncAssignmentRequirementForm(form) {
        var $form = $(form), course = $form.find('[data-assignment-course], #assignmentCourseSelect').val() || '';
        $form.find('[data-assignment-section] option[data-course-id], [data-assignment-item] option[data-course-id]').each(function(){
          var match = !course || String($(this).data('course-id')) === String(course);
          $(this).prop('hidden', !match).prop('disabled', !match);
        });
        var requirement = $form.find('[data-assignment-requirement]').val() || 'optional';
        var rule = $form.find('[data-assignment-rule]').val() || 'submission';
        $form.find('[data-assignment-section-wrap]').toggle(requirement === 'section');
        $form.find('[data-assignment-item-wrap]').toggle(requirement === 'lesson');
        $form.find('[data-assignment-minimum]').toggle(rule === 'minimum_score');
        $form.find('[data-assignment-section]').prop('required', requirement === 'section');
        $form.find('[data-assignment-item]').prop('required', requirement === 'lesson');
        $form.find('[name="minimum_score"]').prop('required', rule === 'minimum_score');
        var lateEnabled = $form.find('[data-late-submission-enabled]').is(':checked');
        $form.find('[data-late-submission-until]').prop('hidden', !lateEnabled).find('input').prop('required', lateEnabled);
      }
      $(document).on('change', '[data-assignment-course], #assignmentCourseSelect, [data-assignment-requirement], [data-assignment-rule], [data-late-submission-enabled]', function(){ syncAssignmentRequirementForm($(this).closest('form')); });
      $('#addAssignmentModal').on('shown.bs.modal', function(){ syncAssignmentRequirementForm($('#addAssignment')); });

      // Add Assignment AJAX
      $(document).on("submit", "#addAssignment", function(e) {
        e.preventDefault();
        var form = this;
        var isUploading = false;
        $.ajax({
          xhr: function() {
            var xhr = new window.XMLHttpRequest();
            xhr.upload.addEventListener("progress", function(evt) {
              if (evt.lengthComputable) {
                var percentComplete = parseInt(((evt.loaded / evt.total) * 100));
                $(form).find("#progress-bar").width(percentComplete + '%');
                $(form).find("#progress-bar").html(percentComplete + '%');
                isUploading = percentComplete < 100;
              }
            }, false);
            return xhr;
          },
          type: "POST",
          url: "requests/assignment/add",
          data: new FormData(form),
          dataType: "json",
          contentType: false,
          cache: false,
          processData: false,
          beforeSend: function() {
            $(form).find(".submitBtn").attr("disabled", "disabled");
            $(form).css("opacity", ".5");
            $(form).find("#progress-bar").width('0%');
          },
          success: function(response) {
            if (response.status == 1) {
              // Wait for upload to finish before reload
              setTimeout(function() {
                form.reset();
                location.reload();
              }, 3000); // Give a short delay to ensure upload is complete
            } else {
              alert(response.message);
            }
            $(form).css("opacity", "");
            $(form).find(".submitBtn").removeAttr("disabled");
          },
        });
      });

      // Edit Assignment AJAX (fetch modal)
      $(document).on("submit", ".editAssignment", function(e) {
        e.preventDefault();
        var form = this;
        $.ajax({
          type: "POST",
          url: "requests/assignment/edit",
          data: new FormData(form),
          dataType: "json",
          contentType: false,
          cache: false,
          processData: false,
          success: function(response) {
            if (response.status == 1) {
              $(".modal-backdrop, #editAssignmentModal").remove();
              $(".ajax-response").html(response.html);
              syncAssignmentRequirementForm($("#updateAssignment"));
              $("#editAssignmentModal").modal("show");
            } else {
              alert(response.message);
            }
          }
        });
      });

      // Update Assignment AJAX (submit modal)
      $(document).on("submit", "#updateAssignment", function(e) {
        e.preventDefault();
        var form = this;
        $.ajax({
          type: "POST",
          url: "requests/assignment/edit",
          data: new FormData(form),
          dataType: "json",
          contentType: false,
          cache: false,
          processData: false,
          beforeSend: function() {
            $(form).find(".submitBtn").attr("disabled", "disabled");
          },
          success: function(response) {
            if (response.status == 1) {
              $("#editAssignmentModal").modal("hide");
              location.reload();
            } else {
              alert(response.message);
            }
            $(form).find(".submitBtn").removeAttr("disabled");
          }
        });
      });

      // Delete Assignment AJAX
      $(document).on("submit", ".deleteAssignment", function(e) {
        e.preventDefault();
        var form = this;
        if (confirm("Are you sure you want to delete the assignment?")) {
          $.ajax({
            type: "POST",
            url: "requests/assignment/delete",
            data: new FormData(form),
            dataType: "json",
            contentType: false,
            cache: false,
            processData: false,
            success: function(response) {
              if (response.status == 1) {
                location.reload();
              } else {
                alert(response.message);
              }
            },
          });
        }
      });

      // DataTable initialization (like courses.php)
      $('#assignmentsTable').DataTable({
        responsive: true,
        lengthChange: true,
        autoWidth: true,
        buttons: [{
            extend: 'copy',
            text: 'Copy',
            exportOptions: {
              columns: ':visible'
            }
          },
          {
            extend: 'csv',
            text: 'Excel (CSV)',
            exportOptions: {
              columns: ':visible'
            }
          },
          {
            extend: 'excel',
            text: 'Excel',
            exportOptions: {
              columns: ':visible'
            }
          },
          {
            extend: 'print',
            text: 'Print',
            exportOptions: {
              columns: ':visible'
            }
          },
          {
            extend: 'colvis',
            text: 'View'
          }
        ],
        language: {
          paginate: {
            next: 'Next',
            previous: 'Previous'
          },
          search: "Search:"
        },
        oLanguage: {
          sInfo: "Showing _START_ to _END_ of _TOTAL_ entries",
          sLengthMenu: "Show _MENU_ rows",
        },
        dom: 'Bfrtip'
      });



    });
  </script>
</body>

</html>
