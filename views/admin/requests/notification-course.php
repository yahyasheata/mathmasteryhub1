<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){
        if ( isset($_POST['course_id']) && !empty($_POST['course_id']) ) {
            
            $course_id = $_POST['course_id'];
                
            $course_title = getCourseInfo($course_id)->course_title;
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
                            <label class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>أرسال اشعار الى جميع مستخدمين كورس  : <strong>$course_title</strong></label>

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
                                                    
                            <input type='hidden' name='course_id' value='$course_id' />
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


    if( isset($_POST['course_id'],$_POST['notification_message'],$_POST['notification_title']) && !empty($_POST['course_id']) && !empty($_POST['notification_message']) && !empty($_POST['notification_title']) ){

        $course_id = $_POST['course_id'];
        $notification_message = $_POST['notification_message'];
        $notification_title = $_POST['notification_title'];

        $conn = db();


            $query = "INSERT INTO notifications (user_id, title, message)
            SELECT DISTINCT user_id, '$notification_title', '$notification_message'
            FROM course_logs WHERE course_id = 33;";
            $end_point = "$baseUrl/notification/push.php";
            $post_data = array(
                'course_id' => $course_id,
                'title' => $notification_title,
                'body' => $notification_message

            );
            $response = sendPostRequest($end_point, $post_data);
    
            $result = mysqli_query($conn,$query);
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