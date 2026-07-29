<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
// Assuming you want to send a JSON response
header('Content-Type: application/json');




// echo "good";

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get the values from the form
    if(isset($_SESSION['username']) && !empty($_SESSION['username']) ){
        $conn = db();
        $username = $_SESSION['username'];

        if(isset($_POST['_method']) && $_POST['_method'] == 'POST'){

            if(isset($_POST['course_id']) && !empty($_POST['course_id']) ){
    
                $course_id = $_POST['course_id'];
                $user_id = getUserInfo($username)->user_id;
                // echo $user_id;
    
                require_once 'inc/TransactionLog.php' ;   
    
                $transactionLogHandler = new TransactionLog($conn);
                        
                // Save course log
                $result = $transactionLogHandler->saveCourseLog($user_id, $course_id);
                if($result){
                    echo json_encode($result);
                }else{
                    echo json_encode($result);
                }
                            
    
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'خطأ',
                    'reason' => 'هناك خطاً غير متوقع'
                );
                echo json_encode($response);
            }
    
    
        
        }else{
            $response = array(
                'status' => 0,
                'message' => 'خطأ',
                'reason' => 'هناك خطاً غير متوقع'
            );
            echo json_encode($response);
        }
        
        

    }else{
        $response = array(
            'status' => 0,
            'message' => 'خطأ',
            'reason' => 'يجب تسجيل الدخول اولاً لشراء الكورس'
        );
        echo json_encode($response);
    }


    



}


?>
