<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$fullName = trim((string) ($_POST['name'] ?? ''));
$username = trim((string) ($_POST['phone_number'] ?? ''));
$governorate = trim((string) ($_POST['governorate'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
if ($fullName === '' || $username === '' || $governorate === '' || $password === '') {
    exit(json_encode(['status' => 0, 'message' => 'All required fields must be completed']));
}
if (strlen($password) < 8) {
    exit(json_encode(['status' => 0, 'message' => 'Password must be at least 8 characters.']));
}

$conn = db();
$check = $conn->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
$check->bind_param('s', $username);
$check->execute();
$exists = (bool) $check->get_result()->fetch_assoc();
$check->close();
if ($exists) {
    exit(json_encode(['status' => 0, 'message' => 'Phone Number is already linked to another account.']));
}

$userId = random_int(99, 9999);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare('INSERT INTO users (user_id, full_name, username, password, governorate) VALUES (?, ?, ?, ?, ?)');
if (!$stmt) {
    exit(json_encode(['status' => 0, 'message' => 'User could not be prepared.']));
}
$stmt->bind_param('issss', $userId, $fullName, $username, $passwordHash, $governorate);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $_SESSION['username'] = $username;
}
echo json_encode($ok
    ? ['status' => 1, 'message' => 'Account created successfully']
    : ['status' => 0, 'message' => 'There was a database connection error. Please try again.']);
