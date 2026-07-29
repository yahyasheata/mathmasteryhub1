<?php
header('Content-Type: application/json');
require_once 'connection/config.php';
require_once 'inc/functions.php';

// ... (existing code)
if (isset($_POST['type']) && $_POST['type'] == 'daily'){
    function getDailyTrafficData() {
        $conn = db();
    
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
    
        $sql = "SELECT DATE(purchase_date) AS purchase_date, SUM(amount) AS total_amount FROM transactions GROUP BY DATE(purchase_date) ORDER BY purchase_date LIMIT 5;";
    
        $result = $conn->query($sql);
    
        $data = [];
    
        while ($row = $result->fetch_assoc()) {
            $data[$row['purchase_date']] = $row['total_amount'];
        }
    
        $conn->close();
    
        return $data;
    }
    
    $data = getDailyTrafficData();
    
    echo json_encode(array_reverse($data));
    
    // echo "s";

}elseif(isset($_POST['type']) && $_POST['type'] == 'monthly'){
    function getMonthlyTrafficData() {
        $conn = db();
    
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
    
        $sql = "SELECT
                    DATE_FORMAT(purchase_date, '%Y-%m') AS purchase_month,
                    SUM(amount) AS total_amount
                FROM
                    transactions
                GROUP BY
                    purchase_month
                ORDER BY
                    purchase_month LIMIT 5";
    
        $result = $conn->query($sql);
    
        $data = [];
    
        while ($row = $result->fetch_assoc()) {
            $data[$row['purchase_month']] = $row['total_amount'];
        }
    
        $conn->close();
    
        return $data;
    }
    
    $data = getMonthlyTrafficData();
    
    echo json_encode(array_reverse($data));
    
    
    // echo "s";
}
