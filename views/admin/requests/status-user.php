<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    if ( isset($_POST['update-status']) && $_POST['update-status'] == 1 ) {
        $user_status = '';
        if(isset($_POST['user_status'])){
            $user_status = $_POST['user_status'];
        }
        if (isset($_POST['user_id']) && $user_status == 1 || $user_status == 2 ) {
            $user_status = 1;
            $user_id = $_POST['user_id'];
            $query = "UPDATE users SET status = '$user_status' WHERE user_id = '$user_id' ";
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Status updated successfully'
                );
                $response = json_encode($response);
                echo $response;
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'Database connection error'
                );
                $response = json_encode($response);
                echo $response;
            }


        }else{
            $user_status = 0;
            $user_id = $_POST['user_id'];
            $query = "UPDATE users SET status = '$user_status' WHERE user_id = '$user_id' ";
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Status updated successfully'
                );
                $response = json_encode($response);
                echo $response;
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'Database connection error'
                );
                $response = json_encode($response);
                echo $response;
            }
        }

    }


}else{
    header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed');
	exit;
}

?>