<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];
$pageName = "courses";
$subPageName = "exams";

$conn = db();
$query = "SELECT * FROM exams";
$result = mysqli_query($conn, $query);
// Query for all courses for the select dropdown
$courses_result = mysqli_query($conn, "SELECT course_id, course_title FROM courses");

function getExamSubmissionsCount($exam_id)
{
  $conn = db();
  $query = "SELECT COUNT(*) FROM exam_submissions WHERE exam_id = '$exam_id' ";
  $result = mysqli_query($conn, $query);
  $count = mysqli_fetch_assoc($result)['COUNT(*)'];
  return $count;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exams | <?= $site_name; ?></title>
  <meta name="title" content="Exams | <?= $site_name; ?>">
  <?php include "layouts/admin/header.php"; ?>
  <script src="<?= $baseUrl ?>/resources/js/jquery-ui.min.js"></script>
  <script src="<?= $baseUrl ?>/resources/js/jquery.ui.touch-punch.min.js"></script>
  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
</head>

<body class='dash ds-bg-primary'>
  <div class="col-12 justify-content-end d-flex"></div>
  <form method="POST" action="<?= $baseUrl ?>/resources/logout" id="logout-form" class="d-none"><input type="hidden" name="_token" value="XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH"></form>
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
                <div class="col-12 col-lg-4 py-3 px-3">
                  <span class="fas fa-tasks"></span> Exams
                </div>
                <div class="col-12 col-lg-4 p-0"></div>
                <div class="col-12 col-lg-4 p-2 text-lg-end">
                  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addExamModal">
                    <span class="fas fa-plus"></span> Add New
                  </button>
                  <div class="modal fade" id="addExamModal" aria-labelledby="addExamLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title" id="addExamLabel">Add New Exam</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <form action="requests/exam/add" method="POST" id="addExam" enctype="multipart/form-data">
                            <fieldset class="form-fieldset api-mode">
                              <label class='ds-text-secondary' style="display: flex; justify-content: center; font-size: 18px">Exam Details</label>
                              <div class="col-12 p-3 row">
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Title</div>
                                  <div class="col-12 pt-3">
                                    <input type="text" name="exam_title" required maxlength="190" class="form-control" placeholder="Enter the exam title">
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Description</div>
                                  <div class="col-12 pt-3">
                                    <textarea class="form-control" name="exam_description" rows="2" placeholder="Enter the exam description here" required></textarea>
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Due Date</div>
                                  <div class="col-12 pt-3">
                                    <input type="date" name="due_date" required class="form-control">
                                  </div>
                                </div>
                                <div class="col-12 col-lg-6 p-2">
                                  <div class="col-12">Course</div>
                                  <div class="col-12 pt-3">
                                    <select name="course_id" class="form-control" id="examCourseSelect" required>
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
                                  <div class="col-12">Attach File <small class='text-primary'>{Optional}</small></div>
                                  <div class="col-12 pt-3">
                                    <input type="file" name="exam_file" class="form-control" accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
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
                  </div>
                </div>
              </div>
              <div class="col-12 divider" style="min-height: 2px"></div>
            </div>
            <div class="col-12 p-3" style="overflow: auto">
              <div class="col-12 p-0" style="min-width: 1100px">
                <table class="table table-bordered table-hover text-start" id='examsTable' dir="ltr">
                  <thead>
                    <tr class="text-start">
                      <th class="text-start">#</th>
                      <th class="text-start">Title</th>
                      <th class="text-start">Description</th>
                      <th class="text-start">Due Date</th>
                      <th class="text-start">Submission Count</th>
                      <th class="text-start">Submissions</th>
                      <th class="text-start">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $count = 1;
                    while ($exam = mysqli_fetch_assoc($result)) {
                      $submissions = getExamSubmissionsCount($exam['exam_id']);
                      echo "<tr>
                                                <td>$count</td>
                                                <td>{$exam['exam_title']}</td>
                                                <td>{$exam['exam_description']}</td>
                                                <td>{$exam['due_date']}</td>
                                                <td>$submissions</td>
                                                <td>
                                                    <a href='exam-submissions?exam_id={$exam['exam_id']}' class='btn btn-outline-primary btn-sm font-small mx-1'>
                                                        <span class='fas fa-list'></span> Submissions
                                                    </a>
                                                </td>
                                                <td style='width: 250px'>

                                                    <button class='btn btn-outline-primary btn-sm font-small mx-1 embedBtn' data-exam-id='{$exam['exam_id']}' data-exam-title='{$exam['exam_title']}' data-due-date='{$exam['due_date']}' >
                                                          <span class='fas fa-code'></span> Exam Submission
                                                    </button>
                                                                                                        <form method='POST' action='' class='d-inline-block editexam'>
                                                        <input type='hidden' name='exam_id' value='{$exam['exam_id']}'>
                                                        <input type='hidden' name='_method' value='GET'>
                                                        <button class='btn btn-outline-success btn-sm font-small mx-1'>Edit</button>
                                                    </form>
                                                    <form method='POST' action='' class='d-inline-block deleteexam'>
                                                        <input type='hidden' name='exam_id' value='{$exam['exam_id']}'>
                                                        <input type='hidden' name='_method' value='DELETE'>
                                                        <button class='btn btn-outline-danger btn-sm font-small mx-1'>Delete</button>
                                                    </form>
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
  <div class="ajax-response"></div>
  <script>
    $(document).ready(function() {
      $(".embedBtn").click(function() {
        // Get the correct data attributes (no 'data-' prefix in .data() keys)
        var examTitle = $(this).data("exam-title");
        var examId = $(this).data("exam-id");
        var dueDate = $(this).data("due-date");

        // Generate the HTML code dynamically with the updated data attributes
        var videoButtonCode = '<button class="btn btn-sm show-exam" data-exam-id="' + examId + '" data-due-date="' + dueDate + '"><span class="fas fa-file-signature"></span> ' + examTitle + '</button>';

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
      // Add exam AJAX
      $(document).on("submit", "#addExam", function(e) {
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
          url: "requests/exam/add",
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

      // Edit exam AJAX (fetch modal)
      $(document).on("submit", ".editexam", function(e) {
        e.preventDefault();
        var form = this;
        $.ajax({
          type: "POST",
          url: "requests/exam/edit",
          data: new FormData(form),
          dataType: "json",
          contentType: false,
          cache: false,
          processData: false,
          success: function(response) {
            if (response.status == 1) {
              $(".modal-backdrop, #editExamModal").remove();
              $(".ajax-response").html(response.html);
              $("#editExamModal").modal("show");
            } else {
              alert(response.message);
            }
          }
        });
      });

      // Update exam AJAX (submit modal)
      $(document).on("submit", "#updateExam", function(e) {
        e.preventDefault();
        var form = this;
        $.ajax({
          type: "POST",
          url: "requests/exam/edit",
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
              $("#updateExam").modal("hide");
              location.reload();
            } else {
              alert(response.message);
            }
            $(form).find(".submitBtn").removeAttr("disabled");
          }
        });
      });

      // Delete exam AJAX
      $(document).on("submit", ".deleteexam", function(e) {
        e.preventDefault();
        var form = this;
        if (confirm("Are you sure you want to delete the exam?")) {
          $.ajax({
            type: "POST",
            url: "requests/exam/delete",
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
      $('#examsTable').DataTable({
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
  <link rel="modulepreload" href="<?= $baseUrl ?>/resources/build/assets/dashboard-d03a2b4e.js" />
  <link rel="modulepreload" href="<?= $baseUrl ?>/resources/build/assets/main-07febffb.js" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script type="module" src="<?= $baseUrl ?>/resources/build/assets/dashboard-d03a2b4e.js" data-navigate-track="reload"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
</body>

</html>