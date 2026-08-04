<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AssignmentProgress.php';
$username = $_SESSION['admin'];
$pageName = "assignment_submissions";
$subPageName = "assignment_submissions";

$conn = db();
mmh_ensure_learning_schema($conn);
// Get all assignments for filter dropdown
$assignments_result = mysqli_query($conn, "SELECT assignment_id, assignment_title FROM assignments");

// Get selected assignment_id from GET and use a prepared statement for the
// scoped submission query. Assignment IDs are strings in the current schema.
$selected_assignment_id = isset($_GET['assignment_id']) ? trim((string) $_GET['assignment_id']) : '';
$submission_query = "SELECT s.*, a.assignment_title, a.course_id, a.section_id, a.max_score, a.topic, a.subtopic, a.completion_requirement, a.completion_rule, a.minimum_score,
                            c.course_title, cs.title AS section_title,
                            primary_topic.title AS topic_title, subtopic.title AS subtopic_title,
                            u.full_name, importer.full_name AS imported_by_name
                     FROM assignment_submissions s
                     JOIN assignments a ON s.assignment_id = a.assignment_id
                     JOIN users u ON s.student_id = u.user_id
                     LEFT JOIN users importer ON s.imported_by = importer.user_id
                     LEFT JOIN courses c ON a.course_id = c.course_id
                     LEFT JOIN course_sections cs ON a.section_id = cs.section_id AND a.course_id = cs.course_id
                     LEFT JOIN course_topics primary_topic ON a.topic_id = primary_topic.id
                     LEFT JOIN course_topics subtopic ON a.subtopic_id = subtopic.id";
if ($selected_assignment_id !== '') {
  $submission_query .= ' WHERE s.assignment_id = ?';
}
$submission_query .= ' ORDER BY s.submitted_at DESC';
$submission_stmt = $conn->prepare($submission_query);
if (!$submission_stmt) {
  die('Unable to load assignment submissions: ' . htmlspecialchars($conn->error));
}
if ($selected_assignment_id !== '') {
  $submission_stmt->bind_param('s', $selected_assignment_id);
}
$submission_stmt->execute();
$result = $submission_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assignment Submissions | <?= $site_name; ?></title>
  <meta name="title" content="Assignment Submissions | <?= $site_name; ?>">
  <?php include "layouts/admin/header.php"; ?>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

</head>

<body class='dash ds-bg-primary'>
  <div class="col-12 d-flex">
    <?php include "layouts/admin/aside.php"; ?>
    <div class="main-content in-active" style="overflow: hidden">
      <?php include "layouts/admin/top-nav.php"; ?>
      <div class="col-12 px-0" style="margin-top: 55px; position: relative">
        <div class="col-12 p-3">
          <div class="col-12 col-lg-12 p-0 main-box">
            <div class="col-12 px-0">
              <div class="col-12 p-0 row">
                <div class="col-12 col-lg-6 py-3 px-3">
                  <span class="fas fa-file-upload"></span> Assignment Submissions
                </div>
                <div class="col-12 col-lg-6 p-2 text-lg-end">
                  <form method="get" class="d-inline-block">
                    <select name="assignment_id" class="form-control d-inline-block w-auto" onchange="this.form.submit()">
                      <option value="">All Assignments</option>
                      <?php
                      if ($assignments_result && mysqli_num_rows($assignments_result) > 0) {
                        mysqli_data_seek($assignments_result, 0);
                        while ($assignment = mysqli_fetch_assoc($assignments_result)) {
                          $selected = ($selected_assignment_id == $assignment['assignment_id']) ? 'selected' : '';
                          echo '<option value="' . htmlspecialchars($assignment['assignment_id']) . '" ' . $selected . '>' . htmlspecialchars($assignment['assignment_title']) . '</option>';
                        }
                      }
                      ?>
                    </select>
                  </form>
                </div>
              </div>
              <div class="col-12 divider" style="min-height: 2px"></div>
            </div>
            <div class="col-12 p-3" style="overflow: auto">
              <div class="col-12 p-0" style="min-width: 1100px">
                <table class="table table-bordered table-hover text-start" id='submissionsTable' dir="ltr">
                  <thead>
                    <tr class="text-start">
                      <th>#</th>
                      <th>Student Name</th>
                      <th>Assignment Title</th>
                      <th>Course</th>
                      <th>Section</th>
                      <th>Topic</th>
                      <th>Subtopic</th>
                      <th>Submitted At</th>
                      <th>Source</th>
                      <th>File</th>
                      <th>Feedback</th>
                      <th>Self Score</th>
                      <th>Final Score</th>
                      <th>Verification</th>
                      <th>Completion Requirement</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $count = 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                      $submission_files = mmh_assignment_submission_files($conn, $row);
                      $file_links = [];
                      foreach ($submission_files as $submission_file) {
                        $file_path = trim((string) ($submission_file['file_path'] ?? ''));
                        if ($file_path === '') { continue; }
                        $label = trim((string) ($submission_file['original_filename'] ?? '')) ?: basename($file_path);
                        $displayLabel = function_exists('mb_strimwidth')
                          ? mb_strimwidth($label, 0, 30, '…', 'UTF-8')
                          : (strlen($label) > 30 ? substr($label, 0, 27) . '...' : $label);
                        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                        $file_links[] = '<a href="../' . htmlspecialchars($file_path, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" class="btn btn-primary btn-sm mb-1 admin-file-link" title="' . $safeLabel . '" aria-label="Open ' . $safeLabel . '"><span class="fas fa-download" aria-hidden="true"></span><span class="admin-file-name">' . htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') . '</span></a>';
                      }
                      $file_link = $file_links ? implode('<br>', $file_links) : '-';
                      $is_imported = (($row['submission_source'] ?? '') === 'legacy_import');
                      $source_label = $is_imported ? 'Imported by Instructor' : 'LMS';
                      $source_detail = $is_imported && !empty($row['original_submitted_at']) ? '<small class="d-block ds-text-muted">Original: ' . htmlspecialchars((string) $row['original_submitted_at']) . '</small>' : '';
                      if ($is_imported && !empty($row['imported_by_name'])) { $source_detail .= '<small class="d-block ds-text-muted">Imported by: ' . htmlspecialchars((string) $row['imported_by_name']) . '</small>'; }
                      if ($is_imported && !empty($row['imported_at'])) { $source_detail .= '<small class="d-block ds-text-muted">Imported: ' . htmlspecialchars((string) $row['imported_at']) . '</small>'; }
                      $source_badge = '<span class="badge ' . ($is_imported ? 'bg-info' : 'bg-secondary') . '">' . htmlspecialchars($source_label) . '</span>';
                      $max_score = $row['max_score'] !== null && $row['max_score'] !== '' ? htmlspecialchars($row['max_score']) : '';
                      $self_score = $row['self_score'] !== null && $row['self_score'] !== '' ? htmlspecialchars($row['self_score']) . ($max_score !== '' ? ' / ' . $max_score : '') : 'Not required';
                      $final_score = $row['grade'] !== null && $row['grade'] !== '' ? htmlspecialchars($row['grade']) . ($max_score !== '' ? ' / ' . $max_score : '') : '-';
                      $self_score_status = trim((string) ($row['self_score_status'] ?? ''));
                      $verification_labels = [
                        'not_required' => 'Not required',
                        'pending_verification' => 'Pending',
                        'auto_accepted' => 'Accepted automatically',
                        'verified' => 'Verified',
                        'corrected_by_teacher' => 'Corrected by teacher',
                        'rejected' => 'Rejected',
                      ];
                      $verification_label = $verification_labels[$self_score_status] ?? ($self_score_status !== '' ? ucwords(str_replace('_', ' ', $self_score_status)) : 'Not required');
                      $verification_note = trim((string) ($row['verification_note'] ?? ''));
                      $status_badge = '<span class="badge bg-secondary">' . htmlspecialchars($verification_label) . '</span>';
                      $topic_label = $row['topic_title'] ?: ($row['topic'] ?? 'Not classified');
                      $subtopic_label = $row['subtopic_title'] ?: ($row['subtopic'] ?? 'Not classified');
                      $feedback_link = $row['feedback'] ? '<a href="../' . htmlspecialchars($row['feedback']) . '" target="_blank" class="btn btn-outline-primary btn-sm">Download Feedback</a>' : '-';
                      $completion_requirement = htmlspecialchars(mmh_assignment_progress_requirement_label($row['completion_requirement'] ?? 'optional'));
                      $completion_rule = htmlspecialchars(mmh_assignment_progress_rule_label($row['completion_rule'] ?? 'submission', $row['minimum_score'] ?? null));
                      echo "<tr>
                        <td>$count</td>
                        <td>" . htmlspecialchars($row['full_name']) . "</td>
                        <td>" . htmlspecialchars($row['assignment_title']) . "</td>
                        <td>" . htmlspecialchars($row['course_title'] ?? '-') . "</td>
                        <td>" . htmlspecialchars($row['section_title'] ?? 'General') . "</td>
                        <td>" . htmlspecialchars($topic_label) . "</td>
                        <td>" . htmlspecialchars($subtopic_label) . "</td>
                        <td>" . htmlspecialchars($row['submitted_at']) . "</td>
                        <td>$source_badge$source_detail</td>
                        <td>$file_link</td>
                        <td>$feedback_link</td>
                        <td>$self_score</td>
                        <td>$final_score</td>
                        <td>$status_badge</td>
                        <td><div>$completion_requirement</div><small class='ds-text-muted'>$completion_rule</small></td>
                        <td>
                          <div class='d-flex align-items-center'>
                            <span>
                              <button type='button' class='btn btn-primary btn-sm font-small mx-1' data-bs-toggle='modal' data-bs-target='#uploadFeedbackModal{$row['id']}'>Review / Feedback</button>
                            </span>
                            <form method='POST' action='' class='d-inline-block deleteSubmission'>
                              <input type='hidden' name='submission_id' value='" . $row['id'] . "'>
                              <button class='btn btn-outline-danger btn-sm font-small mx-1'>Delete</button>
                            </form>
                          </div>
                        </td>
                      </tr>";
                      // Feedback upload modal
                      echo "<div class='modal fade' id='uploadFeedbackModal{$row['id']}' tabindex='-1' aria-labelledby='uploadFeedbackModalLabel' aria-hidden='true'>
                              <div class='modal-dialog'>
                                <div class='modal-content'>
                                  <div class='modal-header'>
                                    <h5 class='modal-title' id='uploadFeedbackModalLabel'>Review Submission #{$row['id']}</h5>
                                    <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                                  </div>
                                  <div class='modal-body'>
                                    <form method='POST' enctype='multipart/form-data' class='addFeedback'>
                                      <input type='hidden' name='submission_id' value='" . $row['id'] . "'>
                                      <div class='mb-3'>
                                        <label for='feedbackFile' class='form-label'>PDF Feedback <small class='ds-text-muted'>(optional)</small></label>
                                        <input class='form-control' type='file' id='feedbackFile' name='feedback_file' accept='.pdf'>
                                      </div>
                                      <div class='mb-3'>
                                        <label class='form-label'>Verification Action</label>
                                        <select class='form-control' name='verification_action'>
                                          <option value='verify'>Verify as Submitted</option>
                                          <option value='correct'>Edit Score and Verify</option>
                                          <option value='reject'>Reject Score</option>
                                        </select>
                                      </div>
                                      <div class='mb-3'>
                                        <label class='form-label'>Final Verified Score <small class='ds-text-muted'>(used when correcting)</small></label>
                                        <input class='form-control' type='number' min='0' step='0.01' name='final_score' value='" . htmlspecialchars((string) ($row['grade'] ?? $row['self_score'] ?? ''), ENT_QUOTES, 'UTF-8') . "'" . ($max_score !== '' ? " max='{$max_score}'" : '') . ">
                                      </div>
                                      <div class='mb-3'>
                                        <label class='form-label'>Short Verification Note <small class='ds-text-muted'>(optional)</small></label>
                                        <textarea class='form-control' name='verification_note' rows='2'>" . htmlspecialchars($verification_note, ENT_QUOTES, 'UTF-8') . "</textarea>
                                      </div>
                                      <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px; display: none' id='progress-div{$row['id']}'>
                                        <div class='progress-bar bg-success' role='progressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='progress-bar{$row['id']}'>0%</div>
                                      </div>

                                      <div class='modal-footer'>
                                        <button type='button' class='btn btn-secondary submitBtn' data-bs-dismiss='modal'>Close</button>
                                        <button type='submit' class='btn btn-primary submitBtn'>Upload Feedback</button>
                                      </div>
                                    </form>
                                  </div>
                                </div>
                              </div>
                            </div>";
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
  <!-- Move JS includes here for proper order -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>


  <script>
    $(document).on("submit", ".addFeedback", function(e) {
      e.preventDefault();
      var form = this;
      var isUploading = false;
      var $modal = $(form).closest('.modal');
      var rowId = $(form).find('input[name="submission_id"]').val();
      var $progressDiv = $modal.find('#progress-div' + rowId);
      var $progressBar = $modal.find('#progress-bar' + rowId);
      var $submitBtns = $modal.find('.submitBtn');
      $progressDiv.show();
      $progressBar.width('0%').html('0%');
      $submitBtns.prop('disabled', true);
      $.ajax({
        xhr: function() {
          var xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener("progress", function(evt) {
            if (evt.lengthComputable) {
              var percentComplete = parseInt(((evt.loaded / evt.total) * 100));
              $progressBar.width(percentComplete + '%');
              $progressBar.html(percentComplete + '%');
              isUploading = percentComplete < 100;
            }
          }, false);
          return xhr;
        },
        type: "POST",
        url: "requests/assignment/feedback",
        data: new FormData(form),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function() {
          $submitBtns.prop('disabled', true);
          $(form).css("opacity", ".5");
          $progressBar.width('0%').html('0%');
          $progressDiv.show();
        },
        success: function(response) {
          if (response.status == 1) {
            Swal.fire({
              toast: true,
              position: 'top-end',
              icon: 'success',
              title: response.message || "Feedback file uploaded successfully",
              showConfirmButton: false,
              timer: 3000,
              timerProgressBar: true
            });
            setTimeout(function() {
              form.reset();
              location.reload();
            }, 2000);
          } else {
            Swal.fire({
              toast: true,
              position: 'top-end',
              icon: 'error',
              title: response.message || "An error occurred while uploading the file",
              showConfirmButton: false,
              timer: 4000,
              timerProgressBar: true
            });
          }
          $(form).css("opacity", "");
          $submitBtns.prop('disabled', false);
          $progressDiv.hide();
        },
        error: function() {
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: 'A server connection error occurred',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true
          });
          $(form).css("opacity", "");
          $submitBtns.prop('disabled', false);
          $progressDiv.hide();
        }
      });
    });
  </script>

  <script>
    $(document).ready(function() {
      // Delete Submission AJAX
      $(document).on("submit", ".deleteSubmission", function(e) {
        e.preventDefault();
        var form = this;
        if (confirm("Are you sure you want to delete the submission?")) {
          $.ajax({
            type: "POST",
            url: "requests/assignment/delete_submission.php",
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
    });
  </script>



  <script>
    // $("table").DataTable({
    //   // "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, 100, "All"] ],
    //       "responsive": true, "lengthChange": true, "autoWidth": true,
    //     //   "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
    //       "buttons": [
    //         { 
    //         extend: 'copy',
    //         text: 'Copy',
    //         exportOptions:{columns: ':visible'}
    //         },{ 
    //         extend: 'csv',
    //         text: 'Excel (CSV)',
    //         exportOptions:{columns: ':visible'}
    //         },{ 
    //         extend: 'excel',
    //         text: 'Excel',
    //         exportOptions:{columns: ':visible'}
    //         }
    //         // ,{ 
    //         // extend: 'pdf',
    //         // text: 'PDF',
    //         // exportOptions:{columns: ':visible'}
    //         // }
    //         ,{ 
    //         extend: 'print',
    //         text: 'Print',
    //         exportOptions:{columns: ':visible'}
    //         },{ 
    //         extend: 'colvis',
    //         text: 'View'
    //         },
    //       ],
    //       language: {
    //         paginate: {
    //           next: 'Next', // or '→'
    //           previous: 'Previous' // or '←' 
    //         },
    //         "search": "Search:"
    //        },
    //        oLanguage: {
    //                "sInfo" : "Showing _START_ to _END_ of _TOTAL_ entries",// text you want show for info section
    //                "sLengthMenu": "Show _MENU_ rows",

    //         },
    //     });



    $.noConflict();
    jQuery(document).ready(function($) {
      $('table').DataTable({
        // "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, 100, "All"] ],
        "responsive": true,
        "lengthChange": true,
        "autoWidth": true,
        //   "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
        "buttons": [{
            extend: 'copy',
            text: 'Copy',
            exportOptions: {
              columns: ':visible'
            }
          }, {
            extend: 'csv',
            text: 'Excel (CSV)',
            exportOptions: {
              columns: ':visible'
            }
          }, {
            extend: 'excel',
            text: 'Excel',
            exportOptions: {
              columns: ':visible'
            }
          }
          // ,{ 
          // extend: 'pdf',
          // text: 'PDF',
          // exportOptions:{columns: ':visible'}
          // }
          , {
            extend: 'print',
            text: 'Print',
            exportOptions: {
              columns: ':visible'
            }
          }, {
            extend: 'colvis',
            text: 'View'
          },
        ],
        language: {
          paginate: {
            next: 'Next', // or '→'
            previous: 'Previous' // or '←' 
          },
          "search": "Search:"
        },
        oLanguage: {
          "sInfo": "Showing _START_ to _END_ of _TOTAL_ entries", // text you want show for info section
          "sLengthMenu": "Show _MENU_ rows",

        },

      });

      document.addEventListener('focusin', (e) => {
        if (e.target.closest(".tox-tinymce-aux, .moxman-window, .tam-assetmanager-root") !== null) {
          e.stopImmediatePropagation();
        }
      });


    });

    // $('#coursesTable').dataTable( {
    //     "drawCallback": function( settings ) {
    //         alert( 'DataTables has redrawn the table' );
    //     }
    // } );
  </script>


</body>

</html>
