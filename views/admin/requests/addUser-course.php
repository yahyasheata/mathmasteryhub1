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

                $user_data = mysqli_fetch_assoc($result);
                $user_id = $user_data["user_id"];
                $name = $user_data["full_name"];

                $user_options = '';
                while ($user_data = mysqli_fetch_assoc($result)) {
                    $user_id = $user_data["user_id"];
                    $name = $user_data["full_name"];
                    $user_phone = $user_data["username"];
                    $user_options .= "<option value='$user_id'>$name | $user_phone</option>";
                }

                  

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
            $course_id = $_POST['course_id'];
            $user_id = (int) $_POST['user_id'];
            $course_price = getCourseInfo($course_id)->course_price;
            $amountToAdd = $course_price;
            $checkCourse = mysqli_query($conn,"SELECT id from transactions WHERE (course_id='$course_id' AND user_id ='$user_id' ) ");
            if ($checkCourse->num_rows == 0) {
                $updateBalance = updateBalance($user_id,$amountToAdd);
                if($updateBalance){

                    require_once 'inc/TransactionLog.php' ;   
    
                    $transactionLogHandler = new TransactionLog($conn);
                            
                    // Save course log
                    $result = $transactionLogHandler->saveCourseLog($user_id, $course_id);
                    if($result){
                        $overrideResult = add_user_course_save_learning_override($conn, $course_id, $user_id);
                        if(isset($overrideResult['status']) && $overrideResult['status'] == 0){
                            echo json_encode([
                                'status' => 0,
                                'message' => 'Student enrolled, but learning override could not be saved.',
                                'reason' => $overrideResult['reason'] ?? ''
                            ]);
                        }else{
                            echo json_encode($result);
                        }
                    }else{
                        echo json_encode($result);
                    }
    
                }else{
                    $response = array(
                        'status' => 0,
                        'message' => 'Error',
                        'reason' => 'An error occurred while adding balance to the account.'
                    );
                    echo json_encode($response);
                }
            }else{
                $overrideResult = add_user_course_save_learning_override($conn, $course_id, $user_id);
                if(isset($overrideResult['status']) && $overrideResult['status'] == 1){
                    echo json_encode([
                        'status' => 1,
                        'message' => 'Student is already enrolled. Learning override updated successfully.'
                    ]);
                }else{
                    $response = array(
                        'status' => 0,
                        'message' => 'Error',
                        'reason' => 'This course has already been purchased, and the override could not be saved.'
                    );
                    echo json_encode($response);
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