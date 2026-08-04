<?php
require_once __DIR__ . '/EnrollmentService.php';

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
        $userId = (int) $user_id;
        $courseId = (string) $course_id;
        if ($userId <= 0 || $courseId === '') return ['status' => 0, 'message' => 'Invalid purchase.'];

        $this->conn->begin_transaction();
        try {
            $courseStmt = $this->conn->prepare('SELECT course_price, course_title FROM courses WHERE course_id = ? AND archived_at IS NULL LIMIT 1');
            $courseStmt->bind_param('s', $courseId);
            $courseStmt->execute();
            $course = $courseStmt->get_result()->fetch_assoc();
            $courseStmt->close();
            if (!$course) throw new RuntimeException('الكورس غير متاح.');

            $userStmt = $this->conn->prepare('SELECT balance FROM users WHERE user_id = ? AND archived_at IS NULL LIMIT 1 FOR UPDATE');
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if (!$user) throw new RuntimeException('المستخدم غير موجود');

            $check = $this->conn->prepare('SELECT id FROM transactions WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            $check->bind_param('is', $userId, $courseId);
            $check->execute();
            $alreadyPurchased = (bool) $check->get_result()->fetch_assoc();
            $check->close();
            if ($alreadyPurchased) throw new RuntimeException('لقد قمت بشراء هذا الكورس بنجاح من قبل !');

            $price = (float) $course['course_price'];
            if ((float) $user['balance'] < $price) {
                $this->conn->rollback();
                $settings = getSiteSettings();
                $whatsappLink = htmlspecialchars((string) ($settings['whatsapp_link'] ?? ''), ENT_QUOTES, 'UTF-8');
                return ['status' => 0, 'message' => '<h2 style="text-align:center;">تواصل معنا عبر واتس اب لشراء الكورس</h2><br/><a href="'.$whatsappLink.'" target="_blank" class="btn btn-outline-success">واتساب</a>'];
            }

            $update = $this->conn->prepare('UPDATE users SET balance = balance - ? WHERE user_id = ?');
            $update->bind_param('di', $price, $userId);
            if (!$update->execute()) throw new RuntimeException('Could not update balance.');
            $update->close();

            $insert = $this->conn->prepare('INSERT INTO transactions (user_id, course_id, course_title, amount, course_price) VALUES (?, ?, ?, ?, ?)');
            $title = (string) $course['course_title'];
            $insert->bind_param('issdd', $userId, $courseId, $title, $price, $price);
            if (!$insert->execute()) throw new RuntimeException('Could not record transaction.');
            $insert->close();
            $this->conn->commit();
            return ['status' => 1, 'course_title' => $title];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['status' => 0, 'message' => $e->getMessage()];
        }
    }

    public function saveCourseLog($user_id, $course_id)
    {
        // Check if the transaction was successful
        $purchaseResult = $this->purchaseCourse($user_id, $course_id);

        if ($purchaseResult['status']) {
            $course_title = $purchaseResult['course_title'];

            // Record course access through the canonical idempotent writer.
            $result = mmh_enrollment_ensure($this->conn, (int) $user_id, (string) $course_id, (string) $course_title);
            return $result
                ? ['status' => 1, 'message' => 'تم شراء الكورس بنجاح , يمكنك الان الاطلاع على محتوى الكورس ']
                : ['status' => 0, 'message' => 'Enrollment could not be saved.'];
        
        } else {
            // return "Transaction failed. Course log not saved.";
            return $purchaseResult;
        }
    }
}

?>
