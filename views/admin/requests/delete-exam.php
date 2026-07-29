<?php 
require_once 'connection/config.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'DELETE' ){
        if ( isset($_POST['exam_id']) && !empty($_POST['exam_id']) ) {
            $exam_id = $_POST['exam_id'];
            // Remove the exam file from the server if it exists
            $file_query = "SELECT file_path FROM exams WHERE exam_id='$exam_id' ";
            $file_result = mysqli_query(db(), $file_query);
            if ($file_result && $row = mysqli_fetch_assoc($file_result)) {
                $file_path = $row['file_path'];
                if ($file_path && file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            $query = "DELETE FROM exams WHERE exam_id='$exam_id' ";
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Exam deleted successfully'
                );
                $response = json_encode($response);
                echo $response;
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'There was a database connection error, please try again.'
                );
                $response = json_encode($response);
                echo $response;
            }
        }
    }
} else {
    // Optionally handle non-POST requests
}
?>
