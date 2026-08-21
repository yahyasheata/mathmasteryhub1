<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/AssignmentProgress.php';
$admin = db();
$testDatabase = 'mmh_submission_files_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_submission_files_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) throw new RuntimeException('Unsafe test database name.');
$q = static function(mysqli $conn, string $sql): mysqli_result|bool { $r=$conn->query($sql); if ($r===false) throw new RuntimeException($conn->error); return $r; };
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    $q($admin, 'CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($testDatabase), 'Unable to select isolated submission database.');
    $q($admin, "CREATE TABLE assignments (assignment_id VARCHAR(40) PRIMARY KEY, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NULL)");
    $q($admin, "CREATE TABLE assignment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(40) NOT NULL, student_id INT NOT NULL, file_path VARCHAR(255) NULL, submitted_at DATETIME NULL)");
    $q($admin, "CREATE TABLE assignment_submission_files (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, file_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NULL, mime_type VARCHAR(127) NULL, file_size BIGINT UNSIGNED NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_submission_file(submission_id,id))");
    $q($admin, "INSERT INTO assignments VALUES ('a1','c1','i1')");
    $q($admin, "INSERT INTO assignment_submissions (assignment_id,student_id,file_path,submitted_at) VALUES ('a1',7,'uploads/static/assignments/assignment_submissions/primary.pdf',NOW())");
    $submissionId=(int)$admin->insert_id;
    $insert=$admin->prepare('INSERT INTO assignment_submission_files (submission_id,file_path,original_filename,mime_type,file_size,sort_order) VALUES (?,?,?,?,?,?)');
    foreach ([['one.pdf','one.pdf',1],['two.pdf','two.pdf',2],['three.pdf','three.pdf',3]] as $i=>$row) { $path='uploads/static/assignments/assignment_submissions/'.$row[0]; $mime='application/pdf'; $size=$row[2]; $order=$i; $insert->bind_param('isssii',$submissionId,$path,$row[1],$mime,$size,$order); $insert->execute(); }
    $insert->close();
    $submission=['id'=>$submissionId,'file_path'=>'uploads/static/assignments/assignment_submissions/primary.pdf'];
    $files=mmh_assignment_submission_files($admin,$submission);
    $assert(count($files)===3,'Multiple files did not remain under one submission row.');
    $assert((int)$admin->query('SELECT COUNT(*) total FROM assignment_submissions')->fetch_assoc()['total']===1,'Multi-file submission created duplicate submission rows.');
    $assert(($files[0]['original_filename']??'')==='one.pdf' && ($files[2]['original_filename']??'')==='three.pdf','Child file ordering/metadata is incorrect.');
    $legacy=['id'=>999,'file_path'=>'legacy.pdf'];
    $assert(count(mmh_assignment_submission_files($admin,$legacy))===1,'Legacy single-file fallback no longer works.');
    echo "Multi-file submission database checks passed.\n";
} finally {
    $cleanup=mysqli_connect((string)$host,(string)$user,(string)$pass);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_submission_files_test_[0-9]+_[a-f0-9]{8}\z/',$testDatabase)) { $cleanup->query('DROP DATABASE `'.$testDatabase.'`'); $cleanup->close(); }
}
