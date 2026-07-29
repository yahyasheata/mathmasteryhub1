<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    if ( isset($_POST['update-status']) && $_POST['update-status'] == 1 ) {
        $course_status = '';
        if(isset($_POST['course_status'])){
            $course_status = $_POST['course_status'];
        }
        if (isset($_POST['course_id']) && $course_status == 1 || $course_status == 2 ) {
            $course_status = 1;
            $course_id = $_POST['course_id'];
            $query = "UPDATE courses SET course_status = '$course_status' WHERE course_id = '$course_id' ";
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
            $course_status = 0;
            $course_id = $_POST['course_id'];
            $query = "UPDATE courses SET course_status = '$course_status' WHERE course_id = '$course_id' ";
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