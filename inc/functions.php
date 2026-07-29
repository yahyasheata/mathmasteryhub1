<?php 
require_once __DIR__ . '/SiteSettings.php';



/**
 * Summary of getUserData
 * @param mixed $username
 * @return userdata or bolean
 */
function getUserData($username){
    $conn = db();
    $result = mysqli_query($conn,"SELECT * FROM users WHERE username = '$username' ");
    if($result){
        if( mysqli_num_rows($result) > 0 ){
            $userData = mysqli_fetch_assoc($result);
            return $userData;
        }else{
            return false;
        }
    }else{
        return false;
    }
}
function getUserInfo($username){
    $conn = db();
    $result = mysqli_query($conn,"SELECT * FROM users WHERE username = '$username' or user_id = '$username' ");
    if($result){
        if( mysqli_num_rows($result) > 0 ){
            $userData = mysqli_fetch_object($result);
            return $userData;
        }else{
            return false;
        }
    }else{
        return false;
    }
}

function getCourseInfo($course_id){
    $conn = db();
    $result = mysqli_query($conn,"SELECT * FROM courses WHERE id = '$course_id' OR course_id='$course_id' ");
    if($result){
        if( mysqli_num_rows($result) > 0 ){
            $userData = mysqli_fetch_object($result);
            return $userData;
        }else{
            return false;
        }
    }else{
        return false;
    }
}


function getSiteSettings() {
    return mmh_site_settings(db());
}



function pageName($pageName){
    return $pageName;
}


function uploadImage($file, $directory,$rand=null) {
    // function uploadImage($file, $directory,$rand='') {
    $targetDir = $directory . '/';
    $imageName = basename($file['name']);
    $imageFileType = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));


    // Check if the file is an image
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        $response = '{"status":0,"message":"الملف الذي قمت بتحميله ليس صورة"}';
        return $response;
    }else{
            
        // Generate a random number
        // $randomNumber = '';
        // if($rand == null){
        //     $randomNumber = '';
        // }else{
        //     $randomNumber = '_' . rand(0,100);
        // }
        $randomNumber = ($rand === null) ? '_' . rand(0, 100) : '';

        // Append the random number to the image name
        $imageNameWithRandomNumber = pathinfo($imageName, PATHINFO_FILENAME) . $randomNumber . '.' . $imageFileType;

        $targetFile = $targetDir . $imageNameWithRandomNumber;
        $uploadOk = true;

        // ... Rest of the code remains the same as before ...
        // ... Check if the file is an image, file exists, file size, file formats, etc. ...

        // If all checks pass, upload the file
            // Check file size
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxFileSize) {
            $response = '{"status":0,"message":"حجم الملف لا يجب ان يتخطي 10 ميجا"}';
        }else{
            if ($uploadOk) {
                if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                    // echo "File uploaded successfully.";
                    $response = '{"status":1,"message":"تم رفع الصورة بنجاح","file_path":"'.str_replace('../../','',$targetFile).'"}';
                } else {
                    $response = '{"status":0,"message":"هناك خطأ ما عند رفع الملف"}';
                }
            }
        }

        return $response;
    }


}




function removeFile($path) {
    if (file_exists($path)) {
        if (unlink($path)) {
            // Image removed successfully
            return true;
        } else {
            // Failed to remove the image
            return false;
        }
    } else {
        // Image file does not exist
        return false;
    }
}

function governoratesInfo($id){
    $governorates = [
        [
            'id' => 1,
            'governorate_name_ar' => 'القاهرة',
            'governorate_name_en' => 'Cairo'
        ],
        [
            'id' => 2,
            'governorate_name_ar' => 'الجيزة',
            'governorate_name_en' => 'Giza'
        ],
        [
            'id' => 3,
            'governorate_name_ar' => 'الأسكندرية',
            'governorate_name_en' => 'Alexandria'
        ],
        [
            'id' => 4,
            'governorate_name_ar' => 'الدقهلية',
            'governorate_name_en' => 'Dakahlia'
        ],
        [
            'id' => 5,
            'governorate_name_ar' => 'البحر الأحمر',
            'governorate_name_en' => 'Red Sea'
        ],
        [
            'id' => 6,
            'governorate_name_ar' => 'البحيرة',
            'governorate_name_en' => 'Beheira'
        ],
        [
            'id' => 7,
            'governorate_name_ar' => 'الفيوم',
            'governorate_name_en' => 'Fayoum'
        ],
        [
            'id' => 8,
            'governorate_name_ar' => 'الغربية',
            'governorate_name_en' => 'Gharbiya'
        ],
        [
            'id' => 9,
            'governorate_name_ar' => 'الإسماعلية',
            'governorate_name_en' => 'Ismailia'
        ],
        [
            'id' => 10,
            'governorate_name_ar' => 'المنوفية',
            'governorate_name_en' => 'Menofia'
        ],
        [
            'id' => 11,
            'governorate_name_ar' => 'المنيا',
            'governorate_name_en' => 'Minya'
        ],
        [
            'id' => 12,
            'governorate_name_ar' => 'القليوبية',
            'governorate_name_en' => 'Qaliubiya'
        ],
        [
            'id' => 13,
            'governorate_name_ar' => 'الوادي الجديد',
            'governorate_name_en' => 'New Valley'
        ],
        [
            'id' => 14,
            'governorate_name_ar' => 'السويس',
            'governorate_name_en' => 'Suez'
        ],
        [
            'id' => 15,
            'governorate_name_ar' => 'اسوان',
            'governorate_name_en' => 'Aswan'
        ],
        [
            'id' => 16,
            'governorate_name_ar' => 'اسيوط',
            'governorate_name_en' => 'Assiut'
        ],
        [
            'id' => 17,
            'governorate_name_ar' => 'بني سويف',
            'governorate_name_en' => 'Beni Suef'
        ],
        [
            'id' => 18,
            'governorate_name_ar' => 'بورسعيد',
            'governorate_name_en' => 'Port Said'
        ],
        [
            'id' => 19,
            'governorate_name_ar' => 'دمياط',
            'governorate_name_en' => 'Damietta'
        ],
        [
            'id' => 20,
            'governorate_name_ar' => 'الشرقية',
            'governorate_name_en' => 'Sharkia'
        ],
        [
            'id' => 21,
            'governorate_name_ar' => 'جنوب سيناء',
            'governorate_name_en' => 'South Sinai'
        ],
        [
            'id' => 22,
            'governorate_name_ar' => 'كفر الشيخ',
            'governorate_name_en' => 'Kafr Al sheikh'
        ],
        [
            'id' => 23,
            'governorate_name_ar' => 'مطروح',
            'governorate_name_en' => 'Matrouh'
        ],
        [
            'id' => 24,
            'governorate_name_ar' => 'الأقصر',
            'governorate_name_en' => 'Luxor'
        ],
        [
            'id' => 25,
            'governorate_name_ar' => 'قنا',
            'governorate_name_en' => 'Qena'
        ],
        [
            'id' => 26,
            'governorate_name_ar' => 'شمال سيناء',
            'governorate_name_en' => 'North Sinai'
        ],
        [
            'id' => 27,
            'governorate_name_ar' => 'سوهاج',
            'governorate_name_en' => 'Sohag'
        ]
    
        ];

    return $governorates[$id-1];

}


function updateBalance($userid,$amountToAdd){
    $conn = db();
    $query = "SELECT balance FROM users Where user_id='$userid' ";
    $result = mysqli_query($conn,$query);
    if (!$result) {
        return false;
    }else{
        $row = mysqli_fetch_assoc($result);
        $currentBalance = $row['balance'];
    
        $newBalance = $currentBalance + $amountToAdd;
        $updateQuery = "UPDATE users SET balance = $newBalance WHERE user_id = '$userid' ";
        $updateResult = mysqli_query($conn,$updateQuery);
        if(!$updateResult){
            return false;
        }else{
            $logQuery = "INSERT INTO balance_log (user_id,ammunt_added,previous_balance,new_balance) VALUES ('$userid','$amountToAdd','$currentBalance','$newBalance') ";
            $logResult = mysqli_query($conn,$logQuery);
            if($logResult){
                return true;
            }else{
                return false;
            }
        }
    }
    return mysqli_error($conn);
}

function isLocalAddress($ipAddress) {
    // Check if the IP address is a local or loopback address
    return filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}




function timeAgo($timestamp) {
    $currentTimestamp = time();
    $timeDifference = $currentTimestamp - strtotime($timestamp);

    $seconds = $timeDifference;
    $minutes = round($seconds / 60);
    $hours = round($minutes / 60);
    $days = round($hours / 24);
    $weeks = round($days / 7);
    $months = round($days / 30);
    $years = round($days / 365);

    if ($seconds <= 60) {
        return 'منذ ' . $seconds . ' ثانية';
    } elseif ($minutes <= 60) {
        return 'منذ ' . $minutes . ' دقيقة';
    } elseif ($hours <= 24) {
        return 'منذ ' . $hours . ' ساعة';
    } elseif ($days <= 7) {
        return 'منذ ' . $days . ' يوم';
    } elseif ($weeks <= 4.3) {
        return 'منذ ' . $weeks . ' أسبوع';
    } elseif ($months <= 12) {
        return 'منذ ' . $months . ' شهر';
    } else {
        return 'منذ ' . $years . ' سنة';
    }
}



function sendPostRequest($url, $data) {
    $ch = curl_init($url);

    // Set the necessary options for the POST request
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute the request and get the response
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
    }

    // Close the curl session
    curl_close($ch);

    return $response;
}





require 'vendor/autoload.php'; // Make sure to include the GeoIP2 autoload file

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;


function trackTraffic() {
    // Establish a database connection
    $conn = db();

    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Get the referring URL
    // $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "Direct";

    // Get the user's country based on IP address
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    // $reader = new Reader('vendor/geoip2/Database/GeoLite2-City.mmdb');
    // $record = $reader->city($ipAddress);
    // $country = $record->country->isoCode;

    // Skip geolocation for local or loopback addresses
    if (isLocalAddress($ipAddress)) {
        $country = 'Local';
    } else {
        try {
            // Create a GeoIP2 Reader
            $reader = new Reader('vendor/geoip2/Database/GeoLite2-City.mmdb');

            // Get city-level information
            $record = $reader->city($ipAddress);
            $country = $record->country->isoCode;
        } catch (AddressNotFoundException $e) {
            // Handle the case where the address is not in the database
            $country = 'Unknown';
        }
    }


    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "Direct";

    // Get today's date
    $todayDate = date('Y-m-d');

    // Check if there is already an entry for today and the same referer
    $sqlCheck = "SELECT * FROM `traffic_data` WHERE DATE(`timestamp`) = '$todayDate' AND `referer` = '$referer'";
    $resultCheck = $conn->query($sqlCheck);

    if ($resultCheck->num_rows > 0) {
        // If the entry exists, update the daily count
        $sqlUpdate = "UPDATE `traffic_data` SET `daily_count` = `daily_count` + 1 WHERE DATE(`timestamp`) = '$todayDate' AND `referer` = '$referer'";
        $conn->query($sqlUpdate);
    } else {
        // If the entry doesn't exist, insert a new record
        $sqlInsert = "INSERT INTO `traffic_data` (`referer`, `daily_count`) VALUES ('$referer', 1)";
        $conn->query($sqlInsert);
    }
    // Insert data into the database
    $sqlInsert = "INSERT INTO `traffic_sources` (`referer`) VALUES ('$referer')";
    $conn->query($sqlInsert);

    // Close the database connection
    // $conn->close();
}

function displayTrafficData() {
    // Establish a database connection
    $conn = db();

    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Retrieve traffic data from the database
    $sql = "SELECT * FROM `traffic_data` ORDER BY `timestamp` DESC";
    $result = $conn->query($sql);

    // Display the data
    while ($row = $result->fetch_assoc()) {
        echo "Referer: " . $row['referer'] . " - Country: " . $row['country'] . " - Daily Count: " . $row['daily_count'] . " - Timestamp: " . $row['timestamp'] . "<br>";
    }

    // Close the database connection
    // $conn->close();
}


// Usage
// trackTraffic();
// displayTrafficData();


function filterInput($input) {
    // Remove leading and trailing whitespaces
    $filteredInput = trim($input);

    // Remove backslashes
    $filteredInput = stripslashes($filteredInput);

    // Convert special characters to HTML entities
    $filteredInput = htmlspecialchars($filteredInput);

    return $filteredInput;
}


function getGovernorate($id) {
    $governorates = [
        [
            'id' => '1',
            'governorate_name_ar' => 'القاهرة',
            'governorate_name_en' => 'Cairo'
        ],
        [
            'id' => '2',
            'governorate_name_ar' => 'الجيزة',
            'governorate_name_en' => 'Giza'
        ],
        [
            'id' => '3',
            'governorate_name_ar' => 'الأسكندرية',
            'governorate_name_en' => 'Alexandria'
        ],
        [
            'id' => '4',
            'governorate_name_ar' => 'الدقهلية',
            'governorate_name_en' => 'Dakahlia'
        ],
        [
            'id' => '5',
            'governorate_name_ar' => 'البحر الأحمر',
            'governorate_name_en' => 'Red Sea'
        ],
        [
            'id' => '6',
            'governorate_name_ar' => 'البحيرة',
            'governorate_name_en' => 'Beheira'
        ],
        [
            'id' => '7',
            'governorate_name_ar' => 'الفيوم',
            'governorate_name_en' => 'Fayoum'
        ],
        [
            'id' => '8',
            'governorate_name_ar' => 'الغربية',
            'governorate_name_en' => 'Gharbiya'
        ],
        [
            'id' => '9',
            'governorate_name_ar' => 'الإسماعلية',
            'governorate_name_en' => 'Ismailia'
        ],
        [
            'id' => '10',
            'governorate_name_ar' => 'المنوفية',
            'governorate_name_en' => 'Menofia'
        ],
        [
            'id' => '11',
            'governorate_name_ar' => 'المنيا',
            'governorate_name_en' => 'Minya'
        ],
        [
            'id' => '12',
            'governorate_name_ar' => 'القليوبية',
            'governorate_name_en' => 'Qaliubiya'
        ],
        [
            'id' => '13',
            'governorate_name_ar' => 'الوادي الجديد',
            'governorate_name_en' => 'New Valley'
        ],
        [
            'id' => '14',
            'governorate_name_ar' => 'السويس',
            'governorate_name_en' => 'Suez'
        ],
        [
            'id' => '15',
            'governorate_name_ar' => 'اسوان',
            'governorate_name_en' => 'Aswan'
        ],
        [
            'id' => '16',
            'governorate_name_ar' => 'اسيوط',
            'governorate_name_en' => 'Assiut'
        ],
        [
            'id' => '17',
            'governorate_name_ar' => 'بني سويف',
            'governorate_name_en' => 'Beni Suef'
        ],
        [
            'id' => '18',
            'governorate_name_ar' => 'بورسعيد',
            'governorate_name_en' => 'Port Said'
        ],
        [
            'id' => '19',
            'governorate_name_ar' => 'دمياط',
            'governorate_name_en' => 'Damietta'
        ],
        [
            'id' => '20',
            'governorate_name_ar' => 'الشرقية',
            'governorate_name_en' => 'Sharkia'
        ],
        [
            'id' => '21',
            'governorate_name_ar' => 'جنوب سيناء',
            'governorate_name_en' => 'South Sinai'
        ],
        [
            'id' => '22',
            'governorate_name_ar' => 'كفر الشيخ',
            'governorate_name_en' => 'Kafr Al sheikh'
        ],
        [
            'id' => '23',
            'governorate_name_ar' => 'مطروح',
            'governorate_name_en' => 'Matrouh'
        ],
        [
            'id' => '24',
            'governorate_name_ar' => 'الأقصر',
            'governorate_name_en' => 'Luxor'
        ],
        [
            'id' => '25',
            'governorate_name_ar' => 'قنا',
            'governorate_name_en' => 'Qena'
        ],
        [
            'id' => '26',
            'governorate_name_ar' => 'شمال سيناء',
            'governorate_name_en' => 'North Sinai'
        ],
        [
            'id' => '27',
            'governorate_name_ar' => 'سوهاج',
            'governorate_name_en' => 'Sohag'
        ]
    
        ];
    // global $governorates; // Use the global array inside the function

    foreach ($governorates as $governorate) {
        if ($governorate['id'] == $id) {
            return $governorate;
        }
    }

    // Return null if the ID is not found
    return null;
}


// function getUserInfo ($username){
//     $conn = db();
//     $query = "SELECT * FROM users WHERE username='$username' ";
//     $result = $conn->query($query);
//     if ($result->num_rows > 0) {
//         $row = $result->fetch_assoc();
//         return $row;
//     }else{
//         return false;
//     }

// }









function getTransactionsStatistics() {
    $conn = db();

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Yearly transactions
    $sqlYearly = "SELECT
                    YEAR(purchase_date) AS year,
                    SUM(amount) AS total_amount
                FROM
                    transactions
                GROUP BY
                    year
                ORDER BY
                    year DESC";
    $resultYearly = $conn->query($sqlYearly);
    $data['yearly'] = [];
    while ($row = $resultYearly->fetch_assoc()) {
        $data['yearly'][$row['year']] = $row['total_amount'];
    }

    // Monthly transactions
    $sqlMonthly = "SELECT
                    DATE_FORMAT(purchase_date, '%Y-%m') AS month,
                    SUM(amount) AS total_amount
                FROM
                    transactions
                GROUP BY
                    month
                ORDER BY
                    month DESC";
    $resultMonthly = $conn->query($sqlMonthly);
    $data['monthly'] = [];
    while ($row = $resultMonthly->fetch_assoc()) {
        $data['monthly'][$row['month']] = $row['total_amount'];
    }

    // Daily transactions
    $sqlDaily = "SELECT
                    DATE_FORMAT(purchase_date, '%Y-%m-%d') AS day,
                    SUM(amount) AS total_amount
                FROM
                    transactions
                GROUP BY
                    day
                ORDER BY
                    day DESC";
    $resultDaily = $conn->query($sqlDaily);
    $data['daily'] = [];
    while ($row = $resultDaily->fetch_assoc()) {
        $data['daily'][$row['day']] = $row['total_amount'];
    }

    // Total transactions
    $sqlTotal = "SELECT SUM(amount) AS total_amount FROM transactions";
    $resultTotal = $conn->query($sqlTotal);
    $data['total'] = $resultTotal->fetch_assoc()['total_amount'];

  

    return $data;
}






// SEO 
require 'SEO.php';

// END SEO
?>