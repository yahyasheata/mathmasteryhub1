<?php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
// use Minishlink\WebPush\VAPID;

// require "includes/database.php";
require_once '../connection/config.php';
require_once '../__init.php';
// require_once 'inc/functions.php';

$con = db();
$con->set_charset('utf8mb4');

if(mysqli_connect_errno()){
    echo "MySql Connection Error<br>";
    die;
}
require 'web-push/vendor/autoload.php';

// var_dump(VAPID::createVapidKeys());
// die;

$publicKey = "BDHpi7gABWvOmNiyzuhET2-C6HBasei0BAzcxpQGbbpr2rqH7q758MqkX6Jq9nBwEELC27pe-j7sOPTkz4gAKaI";
$privateKey = "HB0FaP2rbzQFHq9NT2cM80rvfVXfpY_4HsQ54wThC-k";

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    // echo "hii";
    if(isset($_POST['title'],$_POST['body'],$_POST['user_id'])){
        $title = $_POST['title'];
        $body = $_POST['body'];
        $user_id = '';
        $course_id = '';
        $query = '';
        $time = time();

        if (isset($_POST['user_id']) && !empty($_POST['user_id'])){
            $user_id = $_POST['user_id'];
            $query = $con->query("SELECT * FROM `push_subscribers` WHERE (`expirationTime` = 0 OR `expirationTime` > '{$time}' ) AND user_id='$user_id' ");
            
        }elseif(isset($_POST['course_id'])){
            $query = $con->query("SELECT * FROM `course_logs` INNER JOIN push_subscribers ON course_logs.user_id = push_subscribers.user_id WHERE  (`expirationTime` = 0 OR `expirationTime` > '{$time}' ) AND course_id='$course_id';");
    
        }
    
        $message = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => 'https://ahmed-makram.com//uploads/static/site/fav.png',
            'badge' => 'https://ahmed-makram.com//uploads/static/site/fav.png',
            // 'extraData' => 'https://thintake.in?ref=push-message'
            'extraData' => 'https://ahmed-makram.com/auth/login'
        ]);
    
    
            
        // $query = $con->query("SELECT * FROM `push_subscribers` WHERE (`expirationTime` = 0 OR `expirationTime` > '{$time}' ) AND user_id='$user_id' ");
        if($query->num_rows > 0){
            $auth = [
                'VAPID' => [
                    'subject' => 'https://ahmed-makram.com', // can be a mailto: or your website address
                    'publicKey' => $publicKey, // (recommended) uncompressed public key P-256 encoded in Base64-URL
                    'privateKey' => $privateKey, // (recommended) in fact the secret multiplier of the private key encoded in Base64-URL
                ],
            ];
            $webPush = new WebPush($auth);
        
            while ($subscriber = $query->fetch_assoc()) {
                $subscription = Subscription::create([
                        "endpoint" => $subscriber['endpoint'],
                        "keys" => [
                            'p256dh' => $subscriber['p256dh'],
                            'auth' => $subscriber['authKey']
                        ]
                    ]);
                $webPush->queueNotification($subscription, $message);
            }
        
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
            
                if ($report->isSuccess()) {
                    // echo "Message sent successfully for {$endpoint}.<br>",
                    $response = array(
                        'status' => 1,
                        'message' => "تم أرسال الاشعار الى المستخدم بنجاح"
                    );
                    echo json_encode($response);
                    
                } else {
                    // echo "Message failed to sent for {$endpoint}: {$report->getReason()}.<br>";
                    $response = array(
                        'status' => 0,
                        'message' => "لم يتم ارسال الاشعار , {$report->getReason()} "
                    );
                    echo json_encode($response);
                }
            }
        }
        else{
            // echo "No Subscribers";
            $response = array(
                'status' => 0,
                'message' => "لي هناك مشتركين في الاشعارات المنبثقة"
            );
            echo json_encode($response);
        }
    
    
    }else{
        $response = array(
            'status' => 0,
            'message' => "يجب ملئ جميع الحقول"
        );
        echo json_encode($response);
    }


}
