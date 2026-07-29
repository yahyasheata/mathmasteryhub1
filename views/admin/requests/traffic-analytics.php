<?php
header('Content-Type: application/json');
require_once 'connection/config.php';
require_once 'inc/functions.php';

// ... (existing code)

function getDailyTrafficData() {
    $conn = db();

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT DATE(`timestamp`) AS `date`, SUM(`daily_count`) AS `total_visits` FROM `traffic_data` GROUP BY DATE(`timestamp`) ORDER BY `timestamp` DESC LIMIT 5";
    $result = $conn->query($sql);

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[$row['date']] = $row['total_visits'];
    }

    $conn->close();

    return $data;
}
$data = getDailyTrafficData();

echo json_encode(array_reverse($data));
// echo "s";