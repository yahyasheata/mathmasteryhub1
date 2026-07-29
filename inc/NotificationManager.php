<?php

class NotificationManager {
    private $conn;

    // Constructor
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    // Insert a new notification
    public function insertNotification($userId, $message) {
        $query = "INSERT INTO notifications (user_id, message, status) VALUES (?, ?, 0)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("is", $userId, $message);

        if ($stmt->execute()) {
            return true;
        } else {
            return false;
        }
    }

    // Get unread notifications for a user
    public function getUnreadNotifications($userId) {
        $query = "SELECT * FROM notifications WHERE user_id = ? AND status = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);

        return $notifications;
    }

    // Mark a notification as read
    public function markNotificationAsRead($notificationId) {
        $query = "UPDATE notifications SET status = 1 WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $notificationId);

        if ($stmt->execute()) {
            return true;
        } else {
            return false;
        }
    }
}

// Example usage:
// $dbConnection should be an instance of your database connection class
// Replace it with your actual database connection logic
// $notificationManager = new NotificationManager($dbConnection);

// Trigger a new notification
// $notificationManager->insertNotification($userId, "New message!");

// Get unread notifications for a user
// $unreadNotifications = $notificationManager->getUnreadNotifications($userId);

// Mark a notification as read
// $notificationId = 1; // Replace with the actual notification ID
// $notificationManager->markNotificationAsRead($notificationId);

?>
