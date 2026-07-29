<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if( isset($_POST['user_id'],$_POST['amountToAdd']) && !empty($_POST['user_id']) && !empty($_POST['amountToAdd']) ){

        $userid = $_POST['user_id'];
        $amountToAdd = $_POST['amountToAdd'];
        $updateBalance = updateBalance($userid,$amountToAdd);
        if($updateBalance){
            $response = array(
                'status' => 1,
                'message' => 'Balance added successfully'
            );
            echo json_encode($response);
        }else{
            $response = array(
                'status' => 0,
                'message' => 'Error',
                'reason' => 'حدث Error عند محاولة اضافة Balance , حاول مرة Other'
            );
            echo json_encode($response);
        }

    }else{
        $response = array(
            'status' => 0,
            'message' => 'Error',
            'reason' => 'All required fields must be completed'
        );
        echo json_encode($response);
    }


}else{

}

?>