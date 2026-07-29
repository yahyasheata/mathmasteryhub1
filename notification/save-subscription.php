<?php
require "includes/database.php";
header("Content-type: application/json");

$data = json_decode(file_get_contents('php://input'), true);
// echo "good";
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$username = $_SESSION['username'];
$username = $_SESSION['username'];
$user_id = getUserInfo($username)->user_id;
// echo $username;
// echo "gooood";
if(is_array($data) && isset($data['endpoint'])){
  $selectId = $con->query("SELECT `id` FROM `push_subscribers` WHERE `endpoint` = '{$data['endpoint']}'");
  
  if($selectId->num_rows == 0 && isset($_GET['subscribe'])){
    //subscribe
    $data['expirationTime'] = floor($data['expirationTime'] / 1000); // Miliseconds to seconds
    $query = $con->query("INSERT INTO `push_subscribers` (`user_id`,`endpoint`, `expirationTime`, `p256dh`, `authKey`) VALUES ('$user_id','{$data['endpoint']}', '{$data['expirationTime']}', '{$data['keys']['p256dh']}', '{$data['keys']['auth']}')");

    if($query){
      echo json_encode(['status'=>'ok', 'message'=>'Subscribed']);
    }
    else{
      echo json_encode(['status'=>'error', 'message'=>'Try Again']);
    }
  }
  elseif(isset($_GET['unsubscribe'])){
    //unsubscribe
    $con->query("DELETE FROM `push_subscribers` WHERE `endpoint` = '{$data['endpoint']}'");
    echo json_encode(['status'=>'ok', 'message'=>'Unsubscribed']);
  }
}
// echo mysqli_error($con);