<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if( isset($_POST['item_title'],$_POST['item_description'],$_POST['item_type'],$_POST['course_id']) && !empty($_POST['course_id']) ){

        $item_title = $_POST['item_title'];
        $item_description = $_POST['item_description'];
        $item_type = $_POST['item_type'];
        $course_id = $_POST['course_id'];
        $item_id = rand(99,9999);

        $conn = db();
        $query = "INSERT INTO course_items (item_id,item_title,item_description,item_type,course_id) VALUES ('$item_id','$item_title','$item_description','$item_type','$course_id') ";
        $result = mysqli_query($conn,$query);
        if($result){
            $response = array(
                'status' => 1,
                'message' => 'Item added successfully'
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


}else{

}

?>