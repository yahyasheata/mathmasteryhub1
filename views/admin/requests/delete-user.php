<?php 
require_once 'connection/config.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if(isset($_POST['_method']) && $_POST['_method'] == 'DELETE' ){

        if ( isset($_POST['user_id']) && !empty($_POST['user_id']) ) {
        
            $user_id = $_POST['user_id'];

    
            $conn = db();
            $file_path = mysqli_fetch_assoc(mysqli_query($conn,"SELECT avatar FROM users WHERE user_id='$user_id' "))['avatar'];
            // removeFile($file_path);
            if($file_path != 'uploads/default/avatar.png'){
                removeFile($file_path);
            }

            $query = "DELETE FROM users WHERE user_id='$user_id' ";
            
            $result = mysqli_query($conn,$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'تم Delete الطالب بنجاح'
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
        }
    }



}else{

}

?>