<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if(isset($_POST['_method']) && $_POST['_method'] == 'DELETE' ){

        if ( isset($_POST['file_id']) && !empty($_POST['file_id']) ) {
        
            $conn = db();
            $file_id = $_POST['file_id'];

            $file_path = mysqli_fetch_assoc(mysqli_query($conn,"SELECT path FROM files WHERE id='$file_id' "))['path'];
            
            if(removeFile($file_path)){
    
                $query = "DELETE FROM files WHERE id='$file_id' ";
            
                $result = mysqli_query($conn,$query);
                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'تم Delete File بنجاح'
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
            }else{
                $query = "DELETE FROM files WHERE id='$file_id' ";
            
                $result = mysqli_query($conn,$query);
                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'تم Delete File بنجاح'
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
    }



}else{

}

?>