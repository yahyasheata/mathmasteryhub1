<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){

        if ( isset($_POST['user_id']) && !empty($_POST['user_id']) ) {
            
            $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                http_response_code(422);
                exit(json_encode(['status' => 0, 'message' => 'Invalid user.']));
            }
            $userStmt = db()->prepare('SELECT full_name FROM users WHERE user_id = ? LIMIT 1');
            $userStmt->bind_param('i', $user_id);
            $userStmt->execute();
            $userRow = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if (!$userRow) {
                http_response_code(404);
                exit(json_encode(['status' => 0, 'message' => 'User not found.']));
            }
            $full_name = (string) $userRow['full_name'];
                $html_response = "
                
                <!-- Modal -->
                <div class='modal fade show' id='response-html-modal' tabindex='-1' aria-labelledby='exampleModalLabel' aria-hidden='true'>
                  <div class='modal-dialog modal-lg'>
                    <div class='modal-content'>
                      <div class='modal-header'>
                        <h5 class='modal-title' id='exampleModalLabel'>أرسال أشعار</h5>
                        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                      </div>
                      <div class='modal-body'>
                        <form action='' method='POST' id='sendNotification' enctype='multipart/form-data'>
                            <label class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>أرسال اشعار الى المستخدم : <strong>$full_name</strong></label>

                                <fieldset class='form-fieldset api-mode'>
                
                                    <div class='col-12 p-3 row'>
                
                                        <div class='col-12 col-lg-12 p-2'>
                                            <div class='col-12'>
                                            عنوان الاشعار
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <input type='text' class='form-control' name='notification_title'  placeholder='اكتب عنان الأشعار هنا' required>
                                            </div>
                                        </div>
                                
                                        <div class='col-12 col-lg-12 p-2'>
                                            <div class='col-12'>
                                            محتوى الأشعار
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <textarea class='form-control' name='notification_message' rows='2' placeholder='اكتب محتوى الأشعار هنا' required></textarea>
                                            </div>
                                        </div>
                
                
                                    </div>
                                 </fieldset>
                            

                            <!-- </form> -->
                                                    
                            <input type='hidden' name='user_id' value='$user_id' />
                            <input type='hidden' name='_method' value='POST' />

                            <div class='modal-footer p-2'>
                                <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Close</button>
                                <button type='submit' class='btn btn-outline-primary submitBtn'>أرسال الأشعار</button>
                            </div>
                        </form>
                                  
                      </div>

                
                    </div>
                  </div>
                </div>
                
                ";
                $response = array(
                    'status' => 1,
                    'html' => $html_response,
                );
                echo json_encode($response);
        }
    }



}else{

}








if($_SERVER['REQUEST_METHOD'] == "POST" ){
  if(isset($_POST['_method']) && $_POST['_method'] == 'POST' ){


    if( isset($_POST['user_id'],$_POST['notification_message'],$_POST['notification_title']) && !empty($_POST['user_id']) && !empty($_POST['notification_message']) && !empty($_POST['notification_title']) ){

        $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $notification_message = trim((string) $_POST['notification_message']);
        $notification_title = trim((string) $_POST['notification_title']);
        if ($user_id === false) {
            exit(json_encode(['status' => 0, 'message' => 'Invalid user.']));
        }

        $conn = db();

        $userCheck = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1');
        $userCheck->bind_param('i', $user_id);
        $userCheck->execute();
        $userExists = (bool) $userCheck->get_result()->fetch_assoc();
        $userCheck->close();
        if (!$userExists) {
            exit(json_encode(['status' => 0, 'message' => 'User not found.']));
        }

function sendPostRequest2($url, $data) {
    $ch = curl_init($url);

    // Set the necessary options for the POST request
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute the request and get the response
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
    }

    // Close the curl session
    curl_close($ch);

    return $response;
}


            $insertStmt = $conn->prepare('INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)');
            if (!$insertStmt) {
                exit(json_encode(['status' => 0, 'message' => 'Notification could not be prepared.']));
            }
            $insertStmt->bind_param('iss', $user_id, $notification_title, $notification_message);
            $result = $insertStmt->execute();
            $insertStmt->close();
            $end_point = "$baseUrl/notification/push.php";
            $post_data = array(
                'user_id' => $user_id,
                'title' => $notification_title,
                'body' => $notification_message

            );
            $response = sendPostRequest2($end_point, $post_data);
    
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Notification sent successfully'
                );
                $response = json_encode($response);
                echo $response;
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'هناك خطاً عن الاتصال بقاعدة الDetails , حاول مرة Other'
                );
                $response = json_encode($response);
                echo $response;
            }
    
    

        


        

    }else {
        $response = array(
            'status' => 0,
            'message' => 'Error',
            'reason' => 'All required fields must be completed'
        );
        $response = json_encode($response);
        echo $response;
    }

  }



}else{

}






?>
