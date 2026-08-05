<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];
$pageName = "courses";
$subPageName = "exam_submissions";

$conn = db();
// Get all exams for filter dropdown
$exams_result = mysqli_query($conn, "SELECT exam_id, exam_title FROM exams");

// Get selected exam_id from GET
$selected_exam_id = isset($_GET['exam_id']) ? mysqli_real_escape_string($conn, $_GET['exam_id']) : '';

// Build query for submissions
$query = "SELECT s.*, e.exam_title, u.full_name FROM exam_submissions s
          JOIN exams e ON s.exam_id = e.exam_id
          JOIN users u ON s.student_id = u.user_id";
if ($selected_exam_id) {
  $query .= " WHERE s.exam_id = '$selected_exam_id'";
}
$query .= " ORDER BY s.submitted_at DESC";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exam Submissions | <?= $site_name; ?></title>
  <meta name="title" content="Exam Submissions | <?= $site_name; ?>">
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
                  <span class="fas fa-file-upload"></span> Exam Submissions
                </div>
                <div class="col-12 col-lg-6 p-2 text-lg-end">
                  <form method="get" class="d-inline-block">
                    <select name="exam_id" class="form-control d-inline-block w-auto" onchange="this.form.submit()">
                      <option value="">All Exams</option>
                      <?php
                      if ($exams_result && mysqli_num_rows($exams_result) > 0) {
                        mysqli_data_seek($exams_result, 0);
                        while ($exam = mysqli_fetch_assoc($exams_result)) {
                          $selected = ($selected_exam_id == $exam['exam_id']) ? 'selected' : '';
                          echo '<option value="' . htmlspecialchars($exam['exam_id']) . '" ' . $selected . '>' . htmlspecialchars($exam['exam_title']) . '</option>';
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
                      <th>Exam Title</th>
                      <th>Due Date</th>
                      <th>File</th>
                      <th>Feedback</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $count = 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                      $file_link = $row['file_path'] ? '<a href="../' . htmlspecialchars($row['file_path']) . '" target="_blank" class="btn btn-primary btn-sm">Download</a>' : '-';
                      $feedback_link = $row['feedback'] ? '<a href="../' . htmlspecialchars($row['feedback']) . '" target="_blank" class="btn btn-outline-primary btn-sm">Download Feedback - <span class="badge bg-success">'.$row['grade'].'</span></a>' : '-';
                      $status_badge = $row['feedback'] ? '<span class="badge bg-success">Feedback Uploaded</span>' : '<span class="badge bg-danger">No Feedback</span>';
                      echo "<tr>
                        <td>$count</td>
                        <td>" . htmlspecialchars($row['full_name']) . "</td>
                        <td>" . htmlspecialchars($row['exam_title']) . "</td>
                        <td>" . htmlspecialchars($row['submitted_at']) . "</td>
                        <td>$file_link</td>
                        <td>$feedback_link</td>
                        <td>$status_badge</td>
                        <td>
                          <div class='d-flex align-items-center'>
                            <span>
                              <button type='button' class='btn btn-primary btn-sm font-small mx-1' data-bs-toggle='modal' data-bs-target='#uploadFeedbackModal{$row['id']}'>Upload PDF Feedback</button>
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
                                    <h5 class='modal-title' id='uploadFeedbackModalLabel'>Upload PDF Feedback for Submission #{$row['id']}</h5>
                                    <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                                  </div>
                                  <div class='modal-body'>
                                    <form method='POST' enctype='multipart/form-data' class='addFeedback'>
                                      <input type='hidden' name='submission_id' value='" . $row['id'] . "'>
                                      <div class='mb-3'>
                                        <label for='feedbackFile' class='form-label'>Select PDF File</label>
                                        <input class='form-control' type='file' id='feedbackFile' name='feedback_file' accept='.pdf' required>
                                      </div>
                                      <div class='mb-3'>
                                        <label for='feedbackDegree{$row['id']}' class='form-label'>Exam Grade</label>
                                        <input class='form-control' type='text' id='feedbackDegree{$row['id']}' name='grade' placeholder='Enter grade' required>
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
        url: "requests/exam/feedback",
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
            url: "requests/exam/delete_submission.php",
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
    $.noConflict();
    jQuery(document).ready(function($) {
      $('table').DataTable({
        "responsive": true,
        "lengthChange": true,
        "autoWidth": true,
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
          }, {
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
            next: 'Next',
            previous: 'Previous'
          },
          "search": "Search:"
        },
        oLanguage: {
          "sInfo": "Showing _START_ to _END_ of _TOTAL_ entries",
          "sLengthMenu": "Show _MENU_ rows",
        },
      });
      document.addEventListener('focusin', (e) => {
        if (e.target.closest(".tox-tinymce-aux, .moxman-window, .tam-assetmanager-root") !== null) {
          e.stopImmediatePropagation();
        }
      });
    });
  </script>

</body>

</html>
