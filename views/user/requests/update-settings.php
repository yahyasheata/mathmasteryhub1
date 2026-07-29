<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/StudentCourseCsrf.php';

header('Content-Type: application/json; charset=UTF-8');

function student_settings_response($status, $message, $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode(['status' => $status ? 1 : 0, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function student_settings_avatar_upload(array $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, 'The profile photo could not be uploaded.'];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? '')) || (int) ($file['size'] ?? 0) < 1 || (int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return [null, 'Choose an image smaller than 5 MB.'];
    }

    $imageInfo = @getimagesize((string) $file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    $imageType = is_array($imageInfo) ? (int) ($imageInfo[2] ?? 0) : 0;
    if (!isset($types[$imageType])) {
        return [null, 'Choose a JPG, PNG, WebP, or GIF image.'];
    }

    $directory = 'uploads/images/user/profile';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return [null, 'The profile photo storage is unavailable.'];
    }
    $relativePath = $directory . '/avatar_' . bin2hex(random_bytes(16)) . '.' . $types[$imageType];
    if (!move_uploaded_file((string) $file['tmp_name'], $relativePath)) {
        return [null, 'The profile photo could not be uploaded.'];
    }

    return [$relativePath, null];
}

function student_settings_remove_avatar($path)
{
    $path = (string) $path;
    if (str_starts_with($path, 'uploads/images/user/profile/') && !str_contains($path, '..') && is_file($path)) {
        @unlink($path);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    student_settings_response(false, 'This request is not supported.', 405);
}
if (empty($_SESSION['username'])) {
    student_settings_response(false, 'Your session has expired. Please sign in again.', 401);
}
if (!student_course_csrf_valid($_POST['csrf_token'] ?? null)) {
    student_settings_response(false, 'Your session could not be verified. Refresh the page and try again.', 419);
}

$conn = db();
$username = (string) $_SESSION['username'];
$userStatement = $conn->prepare('SELECT user_id, password, avatar FROM users WHERE username = ? LIMIT 1');
if (!$userStatement) {
    student_settings_response(false, 'We could not update your settings right now. Please try again shortly.', 500);
}
$userStatement->bind_param('s', $username);
$userStatement->execute();
$user = $userStatement->get_result()->fetch_assoc();
$userStatement->close();
if (!$user) {
    student_settings_response(false, 'Your account could not be found. Please sign in again.', 404);
}

if (isset($_POST['update_main_info']) && (string) $_POST['update_main_info'] === '1') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    if ($fullName === '' || strlen($fullName) < 3 || strlen($fullName) > 190) {
        student_settings_response(false, 'Enter a full name between 3 and 190 characters.', 422);
    }

    $newAvatar = null;
    if (isset($_FILES['avatar']) && (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$newAvatar, $uploadError] = student_settings_avatar_upload($_FILES['avatar']);
        if ($uploadError !== null) {
            student_settings_response(false, $uploadError, 422);
        }
    }

    if ($newAvatar !== null) {
        $update = $conn->prepare('UPDATE users SET full_name = ?, avatar = ? WHERE user_id = ? LIMIT 1');
        if ($update) {
            $update->bind_param('ssi', $fullName, $newAvatar, $user['user_id']);
        }
    } else {
        $update = $conn->prepare('UPDATE users SET full_name = ? WHERE user_id = ? LIMIT 1');
        if ($update) {
            $update->bind_param('si', $fullName, $user['user_id']);
        }
    }
    if (!$update || !$update->execute()) {
        if ($newAvatar !== null) student_settings_remove_avatar($newAvatar);
        if ($update) $update->close();
        student_settings_response(false, 'We could not update your profile right now. Please try again shortly.', 500);
    }
    $update->close();
    if ($newAvatar !== null) student_settings_remove_avatar($user['avatar'] ?? '');
    student_settings_response(true, 'Profile updated successfully.');
}

if (isset($_POST['update_password']) && (string) $_POST['update_password'] === '1') {
    $current = (string) ($_POST['old_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if ($current === '' || $password === '' || $confirmation === '') {
        student_settings_response(false, 'All password fields are required.', 422);
    }
    if (strlen($password) < 8 || strlen($password) > 190) {
        student_settings_response(false, 'Choose a new password between 8 and 190 characters.', 422);
    }
    if (!hash_equals($password, $confirmation)) {
        student_settings_response(false, 'The new password and confirmation do not match.', 422);
    }

    $storedPassword = (string) $user['password'];
    $passwordInfo = password_get_info($storedPassword);
    $currentMatches = (($passwordInfo['algoName'] ?? 'unknown') !== 'unknown')
        ? password_verify($current, $storedPassword)
        : hash_equals($storedPassword, $current);
    if (!$currentMatches) {
        student_settings_response(false, 'The current password is incorrect.', 422);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ? LIMIT 1');
    if (!$update) {
        student_settings_response(false, 'We could not update your password right now. Please try again shortly.', 500);
    }
    $update->bind_param('si', $hash, $user['user_id']);
    if (!$update->execute()) {
        $update->close();
        student_settings_response(false, 'We could not update your password right now. Please try again shortly.', 500);
    }
    $update->close();
    student_settings_response(true, 'Password updated successfully.');
}

student_settings_response(false, 'Choose an account update to continue.', 422);
