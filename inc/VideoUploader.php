<?php

class FileUploader {

  private $filePath;

  public function __construct() {
    // Set your upload destination folder
    $this->filePath = __DIR__ . DIRECTORY_SEPARATOR . "uploads";

    // Create folder if it doesn't exist
    if (!file_exists($this->filePath)) {
      if (!mkdir($this->filePath, 0777, true)) {
        $this->verbose(0, "Failed to create $this->filePath");
      }
    }
  }

  public function uploadFile($file, $name = null, $chunk = 0, $chunks = 0) {
    // Deal with chunks
    $fileName = $name ?? $file["name"];
    $this->filePath = $this->filePath . DIRECTORY_SEPARATOR . $fileName;
    $out = @fopen("{$this->filePath}.part", $chunk == 0 ? "wb" : "ab");

    if ($out) {
      $in = @fopen($file["tmp_name"], "rb");

      if ($in) {
        while ($buff = fread($in, 4096)) {
          fwrite($out, $buff);
        }
      } else {
        $this->verbose(0, "Failed to open input stream");
      }

      @fclose($in);
      @fclose($out);
      @unlink($file["tmp_name"]);
    } else {
      $this->verbose(0, "Failed to open output stream");
    }

    // Check if the file has been uploaded
    if (!$chunks || $chunk == $chunks - 1) {
      rename("{$this->filePath}.part", $this->filePath);
      $this->getUploadStatus();
    }
  }

  public function getUploadStatus() {
    $fileName = basename($this->filePath);
    $jsonResponse = [
      "status" => "success",
      "message" => "The video file $fileName has been uploaded.",
      "filePath" => $this->filePath
    ];

    header("Content-Type: application/json");
    echo json_encode($jsonResponse);
    exit();
  }

  private function verbose($ok = 1, $info = "") {
    if ($ok == 0) {
      http_response_code(400);
    }
    exit(json_encode(["ok" => $ok, "info" => $info]));
  }
}


?>