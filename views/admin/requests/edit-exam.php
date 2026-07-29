<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$conn = db();

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    // Fetch exam data and return modal form
    if (isset($_POST['_method']) && $_POST['_method'] == 'GET') {
        if (isset($_POST['exam_id']) && !empty($_POST['exam_id'])) {
            $exam_id = mysqli_real_escape_string($conn, $_POST['exam_id']);
            $query = "SELECT * FROM exams WHERE exam_id='$exam_id' ";
            $result = mysqli_query($conn, $query);
            if ($result && $exam = mysqli_fetch_assoc($result)) {
                // Fetch courses for dropdown
                $courses_result = mysqli_query($conn, "SELECT course_id, course_title FROM courses");
                $course_options = "";
                while ($course = mysqli_fetch_assoc($courses_result)) {
                    $selected = ($course['course_id'] == $exam['course_id']) ? "selected" : "";
                    $course_options .= "<option value='{$course['course_id']}' $selected>{$course['course_title']}</option>";
                }
                $file_link = $exam['file_path'] ? "<a href='../../{$exam['file_path']}' target='_blank'>Current Exam File</a>" : "<span class='text-muted'>No file</span>";
                $html_response = "
                <div class='modal fade show' id='editExamModal' tabindex='-1' aria-labelledby='editExamLabel' aria-modal='true' style='display: block'>
                  <div class='modal-dialog modal-lg'>
                    <div class='modal-content'>
                      <div class='modal-header'>
                        <h5 class='modal-title' id='editExamLabel'>Edit Exam</h5>
                        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                      </div>
                      <div class='modal-body'>
                        <form id='updateExam' enctype='multipart/form-data'>
                          <fieldset class='form-fieldset api-mode'>
                            <label class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Exam Details</label>
                            <div class='col-12 p-3 row'>
                              <div class='col-12 col-lg-6 p-2'>
                                <div class='col-12'>Title</div>
                                <div class='col-12 pt-3'>
                                  <input type='text' name='exam_title' required maxlength='190' class='form-control' value='".htmlspecialchars($exam['exam_title'], ENT_QUOTES)."' placeholder='Enter exam title'>
                                </div>
                              </div>
                              <div class='col-12 col-lg-6 p-2'>
                                <div class='col-12'>Description</div>
                                <div class='col-12 pt-3'>
                                  <textarea class='form-control' name='exam_description' rows='2' required>".htmlspecialchars($exam['exam_description'], ENT_QUOTES)."</textarea>
                                </div>
                              </div>
                              <div class='col-12 col-lg-6 p-2'>
                                <div class='col-12'>Due Date</div>
                                <div class='col-12 pt-3'>
                                  <input type='date' name='due_date' required class='form-control' value='".substr($exam['due_date'],0,10)."'>
                                </div>
                              </div>
                              <div class='col-12 col-lg-6 p-2'>
                                <div class='col-12'>Course</div>
                                <div class='col-12 pt-3'>
                                  <select class='form-control' name='course_id' required>$course_options</select>
                                </div>
                              </div>
                              <div class='col-12 col-lg-6 p-2'>
                                <div class='col-12'>Current Exam File</div>
                                <div class='col-12 pt-3'>$file_link</div>
                              </div>
                              <div class='col-12 col-lg-6 p-2'>
                                <div class='col-12'>Change File <small class='text-primary'>{optional}</small></div>
                                <div class='col-12 pt-3'>
                                  <input type='file' name='exam_file' class='form-control' accept='application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'>
                                </div>
                              </div>
                            </div>
                          </fieldset>
                          <input type='hidden' name='exam_id' value='{$exam['exam_id']}' />
                          <input type='hidden' name='_method' value='UPDATE' />
                          <div class='modal-footer p-2'>
                            <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Close</button>
                            <button type='submit' class='btn btn-outline-primary submitBtn'>Save</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>";
                $response = array('status' => 1, 'html' => $html_response);
                echo json_encode($response);
            } else {
                echo json_encode(['status' => 0, 'message' => 'Exam not found']);
            }
        }
        exit;
    }
    // Update exam
    if (isset($_POST['_method']) && $_POST['_method'] == 'UPDATE') {
        $exam_id = mysqli_real_escape_string($conn, $_POST['exam_id']);
        $exam_title = mysqli_real_escape_string($conn, $_POST['exam_title']);
        $exam_description = mysqli_real_escape_string($conn, $_POST['exam_description']);
        $due_date = mysqli_real_escape_string($conn, $_POST['due_date']);
        $course_id = mysqli_real_escape_string($conn, $_POST['course_id']);
        // Get old file path
        $old_file = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_path FROM exams WHERE exam_id='$exam_id' "))['file_path'];
        $exam_file = $old_file;
        $upload_error = false;
        if (isset($_FILES['exam_file']) && $_FILES['exam_file']['error'] == 0) {
            $file = $_FILES['exam_file'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed = ['pdf', 'doc', 'docx'];
            if (in_array(strtolower($ext), $allowed)) {
                $upload_dir = 'uploads/static/exams/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_name = uniqid('exam_').'.'.$ext;
                $target = $upload_dir.$new_name;
                if (move_uploaded_file($file['tmp_name'], $target)) {
                    if ($old_file && file_exists('../../'.$old_file)) @unlink('../../'.$old_file);
                    $exam_file = 'uploads/static/exams/'.$new_name;
                } else {
                    $upload_error = true;
                }
            } else {
                $upload_error = true;
            }
        }
        if ($upload_error) {
            echo json_encode(['status' => 0, 'message' => 'File upload failed. Check file type or folder permissions.']);
            exit;
        }
        $query = "UPDATE exams SET exam_title='$exam_title', exam_description='$exam_description', due_date='$due_date', course_id='$course_id', file_path='$exam_file' WHERE exam_id='$exam_id' ";
        $result = mysqli_query($conn, $query);
        if ($result) {
            echo json_encode(['status' => 1, 'message' => 'Exam updated successfully']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'An error occurred during update']);
        }
        exit;
    }
}
echo json_encode(['status' => 0, 'message' => 'Invalid request']);
?>
