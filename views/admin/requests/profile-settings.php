<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$conn = db();
$username = $_SESSION['admin'];

// echo "good";

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the values from the form

    if(isset($_POST['update_main_info']) && $_POST['update_main_info'] == 1){

        $full_name = filterInput($_POST["full_name"]);
        $phone_number = filterInput($_POST["phone_number"]);
    
        // Check if a new image is uploaded
        if (isset($_FILES['avatar']) && !$_FILES['avatar']['error']) {
            $avatar = $_FILES["avatar"];
            $old_avatar_image = mysqli_fetch_assoc(mysqli_query($conn,"SELECT avatar FROM users WHERE username='$username' "))['avatar'];
            
            $uploadImgResponse = json_decode(uploadImage($avatar,'uploads/images/user/profile'));
            // echo $uploadImgResponse->status;
            if($uploadImgResponse->status === 1){
                removeFile($old_avatar_image);

                $avatar_image_path = $uploadImgResponse->file_path;
                // echo $avatar_image_path;
                $update_query = "UPDATE users SET
                full_name = '$full_name',
                avatar	 = '$avatar_image_path'
                WHERE username = '$username';";            


                $result = mysqli_query($conn,$update_query);

                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'Personal information updated successfully'
                    );
                    echo json_encode($response);
                    // $msgSuccess = "Personal information updated successfully";
                }else{
                    $response = array(
                        'status' => 0,
                        'message' => 'Error',
                        'reason' => 'There was a database connection error. Please try again.'
                    );
                    echo json_encode($response);
                }
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'There was an error uploading the profile image. Please try again.'
                );
                echo json_encode($response);
                // $msgError = "There was an error uploading the profile image. Please try again.";
            }

        }else{
            
            $update_query = "UPDATE users SET
            full_name = '$full_name'
            WHERE username = '$username';";


            $result = mysqli_query($conn,$update_query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Personal information updated successfully'
                );
                echo json_encode($response);
                // $msgSuccess = "Personal information updated successfully";
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'There was a database connection error. Please try again.'
                );
                echo json_encode($response);
                // $msgError = "هناك Error عن الاتصال بقاعدة الDetails , حاول مرة Other";
            }

        }
    
    
    }elseif(isset($_POST['update_password']) && $_POST['update_password'] == 1){
        
        if( isset($_POST['old_password'],$_POST['password'],$_POST['password_confirmation']) 
        && !empty($_POST['old_password']) && !empty($_POST['password']) && !empty($_POST['password_confirmation']) ){
            // echo "good";
            $old_password = $_POST['old_password'];
            $password = $_POST['password'];
            $password_confirmation = $_POST['password_confirmation'];
            $user_old_password = mysqli_fetch_assoc(mysqli_query($conn,"SELECT password FROM users WHERE username='$username' "))['password'];
            if($user_old_password == $old_password){

                if($password == $password_confirmation){

                    $update_query = "UPDATE users set password='$password' WHERE username='$username' ";
                    $result = mysqli_query($conn,$update_query);
                    if($result){
                        $response = array(
                            'status' => 1,
                            'message' => 'Password updated successfully'
                        );
                        echo json_encode($response);
                    }else{
                        $response = array(
                            'status' => 0,
                            'message' => 'Error',
                            'reason' => 'There was a database connection error. Please try again.'
                        );
                        echo json_encode($response);
        
                    }

                }else{
                    $response = array(
                        'status'=> 0,
                        'message'=> 'Passwords do not match'
                    );
                    echo json_encode($response);

                }

            }else{
                $response = array(
                    'status'=> 0,
                    'message'=> 'The current password is incorrect!'
                );
                echo json_encode($response);
            }

        }else{
            $response = array(
                "status" => 0,
                "message"=> "All fields are required"
            );
            echo json_encode($response);
        }



    }
        
    



}


?>
