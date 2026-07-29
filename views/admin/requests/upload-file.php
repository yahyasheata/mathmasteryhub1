<?php

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

ini_set('upload_max_filesize', '5G');
ini_set('post_max_size', '5G');
ini_set('max_input_time', 10800); // 3 hours in seconds
ini_set('max_execution_time', 10800); // 3 hours in seconds

function verbose($ok = 1, $info = "")
{
    if ($ok == 0) {
        http_response_code(400);
    }
    exit(json_encode(["ok" => $ok, "info" => $info]));
}

if (empty($_FILES) || $_FILES["file"]["error"]) {
    verbose(0, "Failed to move uploaded file.");
}

$filePath = "uploads/course/videos/";

if (!file_exists($filePath)) {
    if (!mkdir($filePath, 0777, true)) {
        verbose(0, "Failed to create $filePath");
    }
}

$fileName = isset($_REQUEST["name"]) ? $_REQUEST["name"] : $_FILES["file"]["name"];

// Get the random ID from $_POST['randomId']
$randomId = isset($_POST['randomId']) ? $_POST['randomId'] : '';

// Sanitize the random ID before using it in the file name
$randomId = preg_replace('/[^a-zA-Z0-9]/', '', $randomId);

// Extract file extension
$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);

// Remove the file extension from the original file name
$fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

// Append the random ID before the file extension
$fileName = $fileNameWithoutExtension . '_' . $randomId . '.' . $fileExtension;

$filePath = $filePath . DIRECTORY_SEPARATOR . $fileName;

$chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
$chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;
$out = @fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");

if ($out) {
    $in = @fopen($_FILES["file"]["tmp_name"], "rb");
    if ($in) {
        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }
    } else {
        verbose(0, "Failed to open input stream");
    }
    @fclose($in);
    @fclose($out);
    @unlink($_FILES["file"]["tmp_name"]);
} else {
    verbose(0, "Failed to open output stream");
}

if (!$chunks || $chunk == $chunks - 1) {
    rename("{$filePath}.part", $filePath);

    $conn = db();

    if ($conn->connect_error) {
        verbose(0, "Database connection failed: " . $conn->connect_error);
    }

    $fileName = $conn->real_escape_string($fileName);
    $filePath = $conn->real_escape_string($filePath);

    $sql = "INSERT INTO files (title, path) VALUES ('$fileName', '$filePath')";

    if ($conn->query($sql) === TRUE) {
        verbose(1, "Upload OK");
    } else {
        verbose(0, "Error inserting data into database: " . $conn->error);
    }

    $conn->close();
}
?>
