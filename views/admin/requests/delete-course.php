<?php
require_once 'connection/config.php';

// ✅ Function to remove a file from the server
function removeFile($file_path) {
    if (file_exists($file_path)) {
        unlink($file_path); // Deletes the file
    }
}

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if(isset($_POST['_method']) && $_POST['_method'] == 'DELETE' ){

        if ( isset($_POST['course_id']) && !empty($_POST['course_id']) ) {
        
            $course_id = $_POST['course_id'];

            $conn = db();
            $file_path = mysqli_fetch_assoc(mysqli_query($conn,"SELECT course_image FROM courses WHERE course_id='$course_id' "))['course_image'];
            removeFile($file_path);

            $query = "DELETE FROM courses WHERE course_id='$course_id' ";
            
            $result = mysqli_query($conn,$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Course deleted successfully'
                );
                echo json_encode($response);
            } else {
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'Database connection error, please try again later'
                );
                echo json_encode($response);
            }
        }
    }

} else {
    // If someone tries to access this file directly from the browser
    echo "Invalid request.";
}
?>
