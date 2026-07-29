<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if( isset($_POST['name'],$_POST['phone_number'],$_POST['gender'],$_POST['governorate'],$_POST['password']) 
    && !empty($_POST['name']) && !empty($_POST['phone_number']) && !empty($_POST['gender']) && !empty($_POST['governorate']) && !empty($_POST['password']) ){

        $full_name = $_POST['name'];
        $username = $_POST['phone_number'];
        $gender = $_POST['gender'];
        $governorate = $_POST['governorate'];
        $password = $_POST['password'];
        $user_id = rand(99, 9999);

        $conn = db();

        // Check if the username already exists
        $checkQuery = "SELECT * FROM users WHERE username = '$username'";
        $checkResult = $conn->query($checkQuery);

        if ($checkResult->num_rows > 0) {
            $response = array(
                'status' => 0,
                'message' => 'Error',
                'reason' => 'Phone Number مرتبط بحساب اخر , يرجي اضافة رقم اخر'
            );
            echo json_encode($response);
        } else {
            // Insert the new record
            $query = "INSERT INTO users (user_id, full_name, username, password, governorate) 
            VALUES ('$user_id', '$full_name', '$username', '$password', '$governorate')";
            $result = $conn->query($query);

            if ($result) {
                $_SESSION['username'] = $username;
                $response = array(
                    'status' => 1,
                    'message' => 'Account created successfully'
                );
                echo json_encode($response);
            } else {
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'There was a database connection error. Please try again.'
                );
                echo json_encode($response);
            }
        }



    }


}else{

}

?>