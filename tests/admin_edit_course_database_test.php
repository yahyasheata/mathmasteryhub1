<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/connection/config.php';

$host = (string) $host;
$user = (string) $user;
$pass = (string) $pass;
$database = 'mmh_edit_course_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$admin = db();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$query = static function (mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) throw new RuntimeException($conn->error);
};

try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    if (!$admin->select_db($database)) throw new RuntimeException('Unable to select the isolated Edit Course test database.');
    $query($admin, "CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(80) NOT NULL, role VARCHAR(20) NOT NULL, status CHAR(1) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "INSERT INTO users (user_id,username,role,status) VALUES (1,'test-admin','admin','1')");
    $query($admin, "CREATE TABLE courses (course_id INT PRIMARY KEY, course_title VARCHAR(190) NOT NULL, course_title_en VARCHAR(190) NULL, course_description TEXT NOT NULL, course_image VARCHAR(255) NULL, course_price DECIMAL(10,2) NOT NULL, preDiscount_course_price DECIMAL(10,2) NOT NULL DEFAULT 0, course_category INT NOT NULL, whatsapp_group TEXT NULL, sequential_learning TINYINT NOT NULL DEFAULT 0, default_homework_score_mode VARCHAR(32) NOT NULL DEFAULT 'disabled')");
    $query($admin, "INSERT INTO courses (course_id,course_title,course_title_en,course_description,course_image,course_price,preDiscount_course_price,course_category,whatsapp_group,sequential_learning,default_homework_score_mode) VALUES (7,'Old title','A distinct legacy title','Old description','uploads/keep.jpg',100,150,2,'https://chat.example/existing',0,'disabled')");

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION = ['admin' => 'test-admin'];
    $token = mmh_admin_csrf_token();
    putenv('DB_NAME=' . $database);
    $_ENV['DB_NAME'] = $database;
    $_SERVER['DB_NAME'] = $database;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST = [
        '_method' => 'UPDATE',
        'course_id' => '7',
        'course_category' => '4',
        'course_title' => 'Math OL May/June 2027',
        'course_description' => 'Updated description',
        'course_price' => '250',
        'preDiscount_course_price' => '',
        'default_homework_score_mode' => 'accept_automatically',
        'sequential_learning' => '1',
        'mmh_csrf_token' => $token,
    ];
    $_FILES = [];

    ob_start();
    include $root . '/views/admin/requests/edit-course.php';
    $handlerOutput = (string) ob_get_clean();
    $response = json_decode($handlerOutput, true);
    if (!is_array($response) && preg_match_all('/\{[^{}]*"status"\s*:\s*[01][^{}]*\}/', $handlerOutput, $matches)) {
        $response = json_decode((string) end($matches[0]), true);
    }
    $assert(is_array($response) && (int) ($response['status'] ?? 0) === 1, 'Edit Course handler did not save the simplified form: ' . substr($handlerOutput, -400));

    $saved = $GLOBALS['conn']->query('SELECT * FROM courses WHERE course_id = 7')->fetch_assoc();
    $assert(($saved['course_title'] ?? '') === 'Math OL May/June 2027', 'Course title was not saved.');
    $assert(($saved['course_title_en'] ?? '') === 'Math OL May/June 2027', 'Compatibility English title was not synchronized from Title.');
    $assert(($saved['whatsapp_group'] ?? '') === 'https://chat.example/existing', 'Omitting WhatsApp from the form erased the existing link.');
    $assert((float) ($saved['course_price'] ?? -1) === 250.0, 'Actual Course price changed unexpectedly.');
    $assert((float) ($saved['preDiscount_course_price'] ?? -1) === 0.0, 'Blank Previous Price did not save the no-discount sentinel.');
    $assert(($saved['course_image'] ?? '') === 'uploads/keep.jpg', 'Omitting a new image changed the current Course image.');
    $assert((int) ($saved['sequential_learning'] ?? 0) === 1, 'Sequential Learning value was not preserved.');
    $assert(($saved['default_homework_score_mode'] ?? '') === 'accept_automatically', 'Homework marking default was not preserved.');

    $form = (string) file_get_contents($root . '/views/admin/requests/edit-course.php');
    $router = (string) file_get_contents($root . '/index.php');
    $assert(!str_contains($form, "name='course_title_en'") && !str_contains($form, "\$_POST['course_title_en']"), 'English Title is still a separately submitted Edit Course field.');
    $assert(!str_contains($form, "name='whatsapp_group'") && !str_contains($form, "whatsapp_group = ?"), 'WhatsApp is still submitted or overwritten by Edit Course.');
    $assert(str_contains($form, "name='mmh_csrf_token'") && str_contains($router, 'mmh_admin_require_mutation();'), 'Edit Course form/route CSRF contract is missing.');
    $assert(str_contains($form, 'Previous Price (optional)') && str_contains($form, 'Default Homework Marking') && str_contains($form, 'When enabled, students follow the Section prerequisite/unlock rules.'), 'Simplified labels or help text are missing.');

    echo "Admin Edit Course isolated database test passed.\n";
} finally {
    if (($GLOBALS['conn'] ?? null) instanceof mysqli && $GLOBALS['conn'] !== $admin) $GLOBALS['conn']->close();
    $cleanup = mysqli_connect($host, $user, $pass);
    if ($cleanup instanceof mysqli) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`');
        $cleanup->close();
    }
    if (isset($_SESSION['admin'])) unset($_SESSION['admin'], $_SESSION['mmh_admin_csrf_token']);
}
