<?php 
require_once 'connection/config.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if(isset($_POST['_method']) && $_POST['_method'] == 'DELETE' ){

        if ( isset($_POST['item_id']) && !empty($_POST['item_id']) ) {
        
            $item_id = $_POST['item_id'];

    
            $query = "DELETE FROM course_items WHERE item_id='$item_id' ";
            
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'تم Delete Item بنجاح'
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

?>