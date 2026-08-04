<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Method Not Allowed']));
}

$conn = db();
$username = (string) ($_SESSION['admin'] ?? '');
$json = static function (bool $ok, string $message): void {
    echo json_encode(['status' => $ok ? 1 : 0, 'message' => $message]);
    exit;
};

if ((string) ($_POST['update_main_info'] ?? '') === '1') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    if ($fullName === '') { $json(false, 'Full name is required.'); }

    $avatarPath = null;
    if (isset($_FILES['avatar']) && (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $upload = json_decode(uploadImage($_FILES['avatar'], 'uploads/images/user/profile'), true);
        if (!is_array($upload) || (int) ($upload['status'] ?? 0) !== 1) { $json(false, 'There was an error uploading the profile image.'); }
        $avatarPath = (string) ($upload['file_path'] ?? '');
    }

    if ($avatarPath !== null) {
        $stmt = $conn->prepare('UPDATE users SET full_name = ?, avatar = ? WHERE username = ?');
        $stmt->bind_param('sss', $fullName, $avatarPath, $username);
    } else {
        $stmt = $conn->prepare('UPDATE users SET full_name = ? WHERE username = ?');
        $stmt->bind_param('ss', $fullName, $username);
    }
    $ok = $stmt && $stmt->execute();
    if ($stmt) { $stmt->close(); }
    $json($ok, $ok ? 'Personal information updated successfully' : 'There was a database connection error. Please try again.');
}

if ((string) ($_POST['update_password'] ?? '') === '1') {
    $oldPassword = (string) ($_POST['old_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if ($oldPassword === '' || $password === '' || $confirmation === '') { $json(false, 'All fields are required'); }
    if (strlen($password) < 8) { $json(false, 'Password must be at least 8 characters.'); }
    if ($password !== $confirmation) { $json(false, 'Passwords do not match'); }

    $lookup = $conn->prepare('SELECT password FROM users WHERE username = ? LIMIT 1');
    $lookup->bind_param('s', $username);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    $stored = (string) ($row['password'] ?? '');
    $validOld = password_get_info($stored)['algo'] !== 0 ? password_verify($oldPassword, $stored) : hash_equals($stored, $oldPassword);
    if (!$validOld) { $json(false, 'The current password is incorrect!'); }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE username = ?');
    $stmt->bind_param('ss', $hash, $username);
    $ok = $stmt->execute();
    $stmt->close();
    $json($ok, $ok ? 'Password updated successfully' : 'There was a database connection error. Please try again.');
}

$json(false, 'No supported settings action was supplied.');
