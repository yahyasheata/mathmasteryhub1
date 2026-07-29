<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$conn = db();



// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    foreach ($_POST['settings'] as $key => $value) {
        // Escape user inputs for security
        $key = mysqli_real_escape_string($conn, $key);

        // Handle file uploads using your custom function
        if (!empty($_FILES['settings']['name'][$key])) {
            $file = $_FILES['settings'];
            $uploadResult = uploadImage($file, "path_to_upload_directory");
            $decodedResult = json_decode($uploadResult, true);

            if ($decodedResult['status'] == 1) {
                // File uploaded successfully, update the database value
                $value = $decodedResult['file_path'];
            } else {
                // Handle the error if file upload fails
                echo "Error uploading file: " . $decodedResult['message'];
                break;
            }
        }

        // Update the corresponding row in the settings table
        $sql = "UPDATE settings SET value = '$value' WHERE `key` = '$key'";

        // Execute the query
        if ($conn->query($sql) !== TRUE) {
            echo "Error updating record: " . $conn->error;
            break;
        }
    }

    // echo "Records updated successfully";
    header("Location: $baseUrl/admin/settings");
}

?>
