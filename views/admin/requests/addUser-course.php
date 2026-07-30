<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';


function add_user_course_save_learning_override(mysqli $conn, $course_id, $user_id)
{
    $allowed = ['inherit', 'on', 'off', 'unlock_all', 'unlock_selected'];
    $override = isset($_POST['sequential_override']) ? trim((string) $_POST['sequential_override']) : 'inherit';
    if (!in_array($override, $allowed, true)) {
        $override = 'inherit';
    }

    $sections = [];
    if (isset($_POST['unlocked_sections']) && is_array($_POST['unlocked_sections'])) {
        foreach ($_POST['unlocked_sections'] as $section_id) {
            $section_id = trim((string) $section_id);
            if ($section_id !== '') {
                $sections[] = $section_id;
            }
        }
    }
    $sections = array_values(array_unique($sections));
    $sections_json = json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare('INSERT INTO course_learning_overrides (course_id, user_id, sequential_override, unlocked_sections) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE sequential_override = VALUES(sequential_override), unlocked_sections = VALUES(unlocked_sections), updated_at = CURRENT_TIMESTAMP');
    if (!$stmt) {
        return ['status' => 0, 'reason' => $conn->error];
    }
    $stmt->bind_param('siss', $course_id, $user_id, $override, $sections_json);
    if (!$stmt->execute()) {
        $reason = $stmt->error ?: $conn->error;
        $stmt->close();
        return ['status' => 0, 'reason' => $reason];
    }
    $stmt->close();
    return ['status' => 1];
}

function add_user_course_section_options(mysqli $conn, $course_id)
{
    $html = '';
    $stmt = $conn->prepare('SELECT section_id, title FROM course_sections WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
    if (!$stmt) {
        return $html;
    }
    $stmt->bind_param('s', $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $section_id = htmlspecialchars((string) $row['section_id'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8');
        $html .= "<option value='{$section_id}'>{$title}</option>";
    }
    $stmt->close();
    return $html;
}

function add_user_course_student_options(mysqli_result $result)
{
    $html = '';
    while ($user_data = mysqli_fetch_assoc($result)) {
        $user_id = htmlspecialchars((string) $user_data["user_id"], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $user_data["full_name"], ENT_QUOTES, 'UTF-8');
        $user_phone = htmlspecialchars((string) $user_data["username"], ENT_QUOTES, 'UTF-8');
        $html .= "<option value='{$user_id}'>{$name} | {$user_phone}</option>";
    }
    return $html;
}

function add_user_course_fetch_course(mysqli $conn, $course_id)
{
    $stmt = $conn->prepare('SELECT course_id, course_title, course_price, course_status FROM courses WHERE course_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $course_id = (string) $course_id;
    $stmt->bind_param('s', $course_id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $course ?: null;
}

function add_user_course_fetch_student(mysqli $conn, $user_id)
{
    $stmt = $conn->prepare("SELECT user_id, full_name, username, status, role FROM users WHERE user_id = ? AND role = 'user' LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $user_id = (int) $user_id;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $student ?: null;
}

function add_user_course_log_exists(mysqli $conn, $course_id, $user_id)
{
    $stmt = $conn->prepare('SELECT id FROM course_logs WHERE course_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $course_id_int = (int) $course_id;
    $user_id_string = (string) $user_id;
    $stmt->bind_param('is', $course_id_int, $user_id_string);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function add_user_course_transaction_exists(mysqli $conn, $course_id, $user_id)
{
    $stmt = $conn->prepare('SELECT id FROM transactions WHERE course_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $course_id_int = (int) $course_id;
    $user_id_int = (int) $user_id;
    $stmt->bind_param('ii', $course_id_int, $user_id_int);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function add_user_course_create_course_log(mysqli $conn, array $course, $user_id)
{
    if (add_user_course_log_exists($conn, $course['course_id'], $user_id)) {
        return ['status' => 1, 'message' => 'Student is already enrolled.'];
    }

    $stmt = $conn->prepare('INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES (?, ?, ?, NOW())');
    if (!$stmt) {
        return ['status' => 0, 'message' => 'Error', 'reason' => $conn->error];
    }
    $user_id_string = (string) $user_id;
    $course_id_int = (int) $course['course_id'];
    $course_title = (string) $course['course_title'];
    $stmt->bind_param('sis', $user_id_string, $course_id_int, $course_title);
    $ok = $stmt->execute();
    $reason = $stmt->error ?: $conn->error;
    $stmt->close();

    return $ok
        ? ['status' => 1, 'message' => 'Student enrolled successfully.']
        : ['status' => 0, 'message' => 'Error', 'reason' => $reason];
}

function add_user_course_enroll_student(mysqli $conn, array $course, $user_id)
{
    if (add_user_course_log_exists($conn, $course['course_id'], $user_id)) {
        return ['status' => 1, 'message' => 'Student is already enrolled.'];
    }

    if (add_user_course_transaction_exists($conn, $course['course_id'], $user_id)) {
        return add_user_course_create_course_log($conn, $course, $user_id);
    }

    $amountToAdd = (int) ($course['course_price'] ?? 0);
    $updateBalance = updateBalance((int) $user_id, $amountToAdd);
    if (!$updateBalance) {
        return ['status' => 0, 'message' => 'Error', 'reason' => 'An error occurred while adding balance to the account.'];
    }

    require_once 'inc/TransactionLog.php';
    $transactionLogHandler = new TransactionLog($conn);
    $result = $transactionLogHandler->saveCourseLog((int) $user_id, (int) $course['course_id']);

    if (add_user_course_log_exists($conn, $course['course_id'], $user_id)) {
        return ['status' => 1, 'message' => $result['message'] ?? 'Student enrolled successfully.'];
    }

    return is_array($result) ? $result : ['status' => 0, 'message' => 'Error', 'reason' => 'Enrollment record was not created.'];
}

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){

        if ( isset($_POST['course_id']) && !empty($_POST['course_id']) ) {
            $conn = db();

            $course_id = $_POST['course_id'];
            $section_options = add_user_course_section_options(db(), $course_id);
    
            // $query = "SELECT * FROM courses WHERE course_id='$course_id' ";
            $query = "SELECT * FROM users WHERE role='user' ";
            
            $result = mysqli_query($conn,$query);
            if($result){

                $user_options = add_user_course_student_options($result);

                  

                $html_response = "
                
                <!-- Modal -->
                <div class='modal fade show' id='addUserToCourseResponse' tabindex='-1' aria-labelledby='exampleModalLabel' aria-hidden='true'>
                  <div class='modal-dialog modal-lg'>
                    <div class='modal-content'>
                      <div class='modal-header'>
                        <h5 class='modal-title' id='exampleModalLabel'>Add Student to Course</h5>
                        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                      </div>
                      <div class='modal-body'>
                            <form action='' method='POST' id='enrollUserToCourse' enctype='multipart/form-data'>
                                <fieldset class='form-fieldset api-mode'>
                
                                    <div class='col-12 p-3 row'>
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                                <div class='col-12'>
                                                Students
                                                </div>
                                                <div class='col-12 pt-3'>
                                                <select class='form-control select2' id='' name='user_id'  data-placeholder='Select student' style='width: 100%'  required=''>
                                                    <option disabled='' selected='' hidden=''>Select student</option>
                                                    $user_options
                                                </select>
                                                </div>
                                        </div>

                                        <div class='col-12 col-lg-6 p-2'>
                                                <div class='col-12'>
                                                Sequential Learning Override
                                                </div>
                                                <div class='col-12 pt-3'>
                                                <select class='form-control' name='sequential_override' data-student-learning-override>
                                                    <option value='inherit' selected>Use course setting</option>
                                                    <option value='on'>Force Sequential Learning ON</option>
                                                    <option value='off'>Force Sequential Learning OFF</option>
                                                    <option value='unlock_all'>Unlock Everything</option>
                                                    <option value='unlock_selected'>Unlock Selected Sections</option>
                                                </select>
                                                </div>
                                        </div>

                                        <div class='col-12 p-2 d-none' data-unlock-selected-sections>
                                                <div class='col-12'>
                                                Unlock Selected Sections
                                                </div>
                                                <div class='col-12 pt-3'>
                                                <select class='form-control select2' name='unlocked_sections[]' multiple data-placeholder='Select sections' style='width: 100%'>
                                                    $section_options
                                                </select>
                                                <small class='text-muted'>Only affects this student.</small>
                                                </div>
                                        </div>

                
                
                                    </div>
                            </fieldset>
                            
                            <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px' id='progress-div'><div class='progress-bar bg-success' role='progressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='progress-bar'></div></div>

                            <!-- </form> -->
                                                    
                            <input type='hidden' name='course_id' value='$course_id' />
                            <input type='hidden' name='_method' value='POST' />

                            <div class='modal-footer p-2'>
                            <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Close</button>
                            <button type='submit' class='btn btn-outline-primary submitBtn'>Add Student to Course</button>
                            </div>
                            </form>
                                  
                      </div>

                
                    </div>
                  </div>
                </div>
                
                ";
                
                $response = array(
                    'status' => 1,
                    'html' => $html_response
                );
                $response = json_encode($response);
                echo $response;

            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'There was a database connection error. Please try again.'
                );
                $response = json_encode($response);
                echo $response;
            }
        }
    }



}else{

}








if($_SERVER['REQUEST_METHOD'] == "POST" ){
  if(isset($_POST['_method']) && $_POST['_method'] == 'POST' ){



    if(isset($_POST['_method']) && $_POST['_method'] == 'POST'){

        if(isset($_POST['course_id'],$_POST['user_id']) && !empty($_POST['course_id']) && !empty($_POST['user_id']) ){

            $conn = db();
            $course_id = trim((string) $_POST['course_id']);
            $user_id = (int) $_POST['user_id'];

            $course = add_user_course_fetch_course($conn, $course_id);
            $student = add_user_course_fetch_student($conn, $user_id);
            if (!$course) {
                echo json_encode(['status' => 0, 'message' => 'Error', 'reason' => 'Selected course was not found.']);
            } elseif (!$student || (string) ($student['status'] ?? '') !== '1') {
                echo json_encode(['status' => 0, 'message' => 'Error', 'reason' => 'Selected student is not active.']);
            }else{
                $result = add_user_course_enroll_student($conn, $course, $user_id);
                if(!isset($result['status']) || $result['status'] != 1){
                    echo json_encode([
                        'status' => 0,
                        'message' => $result['message'] ?? 'Error',
                        'reason' => $result['reason'] ?? 'Enrollment could not be completed.'
                    ]);
                }else{
                    $overrideResult = add_user_course_save_learning_override($conn, $course_id, $user_id);
                    if(isset($overrideResult['status']) && $overrideResult['status'] == 1){
                        echo json_encode([
                            'status' => 1,
                            'message' => ($result['message'] ?? 'Student enrolled successfully.') . ' Learning override updated successfully.'
                        ]);
                    }else{
                        echo json_encode([
                            'status' => 0,
                            'message' => 'Student enrolled, but learning override could not be saved.',
                            'reason' => $overrideResult['reason'] ?? ''
                        ]);
                    }
                }
            }


            // echo $user_id;

                        

        }else{
            $response = array(
                'status' => 0,
                'message' => 'Error',
                'reason' => 'An unexpected error occurred'
            );
            echo json_encode($response);
        }


    
    }else{
        $response = array(
            'status' => 0,
            'message' => 'Error',
            'reason' => 'An unexpected error occurred'
        );
        echo json_encode($response);
    }
        



  }



}else{

}






?>
