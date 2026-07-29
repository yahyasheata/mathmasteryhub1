<?php

class TransactionLog
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }


    public function displayCourses()
    {
        $result = $this->conn->query("SELECT * FROM courses");

        echo "<h1>Available Courses</h1>";

        while ($row = $result->fetch_assoc()) {
            echo "<p>{$row['course_name']} - {$row['course_price']} USD</p>";
        }
    }
    
function getSiteSettings() {

    $conn = db();

    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Query to select all rows from the settings table
    $sql = "SELECT * FROM settings";

    // Execute the query
    $result = $conn->query($sql);

    // Check if there are rows in the result
    if ($result->num_rows > 0) {
        // Initialize an associative array to store settings
        $settings = array();

        // Fetch each row and add it to the settings array
        while ($row = $result->fetch_assoc()) {
            $settings[$row['key']] = $row['value'];
        }

        
        // Return the settings array
        return $settings;
    } else {
        // If no rows are found, return an empty array
        return array();
    }
}

    public function purchaseCourse($user_id, $course_id)
    {
        // Fetch course price and title
        $courseResult = $this->conn->query("SELECT course_price,course_title FROM courses WHERE course_id = $course_id");

        if ($courseResult->num_rows > 0) {
            $course = $courseResult->fetch_assoc();
            $course_price = $course['course_price'];
            $course_title = $course['course_title'];

            // Fetch user balance
            $userResult = $this->conn->query("SELECT balance FROM users WHERE user_id = $user_id");

            if ($userResult->num_rows > 0) {
                $user = $userResult->fetch_assoc();
                // echo $user['balance'];
                // Check if user has sufficient balance
                $checkCourse = $this->conn->query("SELECT id from transactions WHERE (course_id='$course_id' AND user_id ='$user_id' ) ");
                if ($checkCourse->num_rows == 0) {

                    if ($user['balance'] >= $course_price) {
                        $amount = $course_price;

                        // Deduct amount from user balance
                        $checkCourse = $this->conn->query("SELECT id from transactions WHERE (course_id='$course_id' AND user_id ='$user_id' ) ");
                        if ($checkCourse->num_rows == 0) {
                            $this->conn->query("UPDATE users SET balance = balance - $amount WHERE user_id = $user_id");

                            // Record the transaction
                            $result = $this->conn->query("INSERT INTO transactions (user_id, course_id, course_title, amount, course_price) VALUES ($user_id, $course_id, '$course_title', $amount, $course_price)");
        
                            // Return the result of the transaction along with the course title
                            return ['status' => $result, 'course_title' => $course_title];
        
                        }else{
                            $msgError = "لقد قمت بشراء هذا الكورس بنجاح من قبل !";
                        }

                    } else {
                        $site_settings = getSiteSettings();
                        $whatsapp_link = $site_settings['whatsapp_link'];
                        $msgError = "
                        <h2 style='text-align:center;'>تواصل معنا عبر واتس اب لشراء الكورس</h2><br/>
                        <a href='$whatsapp_link' target='_blank' class='btn btn-outline-success ' style='width:170px;height:66px;font-size:20px;font-weight:bold;'>واتساب<i class='fab fa-whatsapp' style='margin-right:5px;font-size:22px;'></i> </a>
                        ";
                    }
                }else{
                    $msgError = "لقد قمت بشراء هذا الكورس بنجاح من قبل !";
                }
            } else {
                $msgError = "المستخدم غير موجود";
            }
        } else {
            $msgError = "الكورس غير متاح.";
        }

        // Return failure status along with an empty course title
        return ['status' => 0, 'message' => $msgError];
    }

    public function saveCourseLog($user_id, $course_id)
    {
        // Check if the transaction was successful
        $purchaseResult = $this->purchaseCourse($user_id, $course_id);

        if ($purchaseResult['status']) {
            $course_title = $purchaseResult['course_title'];

            // Record course access in the course logs table
           $result = $this->conn->query("INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES ($user_id, $course_id, '$course_title', NOW())");
           return ['status' => 1, 'message' => 'تم شراء الكورس بنجاح , يمكنك الان الاطلاع على محتوى الكورس '];
        
        } else {
            // return "Transaction failed. Course log not saved.";
            return $purchaseResult;
        }
    }
}

?>
