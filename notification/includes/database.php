<?php
require_once 'connection/config.php';
require_once '__init.php';
// require_once 'inc/functions.php';

$con = db();
$con->set_charset('utf8mb4');

if(mysqli_connect_errno()){
    echo "MySql Connection Error<br>";
    die;
}