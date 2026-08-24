<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/LearningEvents.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mmh_auth_json(false, 'This request is not available.', [], 405);
}

if (!mmh_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
    mmh_auth_json(false, 'Your session has expired. Please refresh the page and try again.', [], 419);
}

$username = mmh_auth_normalize_username((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    mmh_auth_json(false, 'Enter your email or phone number and password.', [], 422);
}

$conn = db();
$statement = $conn->prepare(
    'SELECT username, password, role, status FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1'
);

if (!$statement) {
    mmh_auth_json(false, 'We could not sign you in right now. Please try again shortly.', [], 500);
}

$statement->bind_param('s', $username);
$statement->execute();
$userData = $statement->get_result()->fetch_assoc();
$statement->close();

$passwordMatches = false;
$legacyPassword = false;
if ($userData) {
    $storedPassword = (string) $userData['password'];
    // Existing accounts remain usable and are upgraded after a successful login.
    $passwordInfo = password_get_info($storedPassword);
    $legacyPassword = (($passwordInfo['algoName'] ?? 'unknown') === 'unknown');
    $passwordMatches = mmh_auth_password_matches($password, $storedPassword);
}

if (!$userData || !$passwordMatches || (string) $userData['status'] !== '1') {
    mmh_auth_json(false, 'We could not sign you in with those details. Please try again.', [], 401);
}

$sessionUsername = (string) $userData['username'];

if ($legacyPassword) {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $upgrade = $conn->prepare('UPDATE users SET password = ? WHERE username = ? LIMIT 1');
    if ($upgrade) {
        $upgrade->bind_param('ss', $hashedPassword, $sessionUsername);
        $upgrade->execute();
        $upgrade->close();
    }
}

mmh_auth_regenerate_session();
unset($_SESSION['mmh_auth_csrf_token']);
$appBase = rtrim(mmh_current_request_base_url(), '/');

if ($userData['role'] === 'admin') {
    unset($_SESSION['username']);
    $_SESSION['admin'] = $sessionUsername;

    $adminInfo = getUserInfo($sessionUsername);
    if ($adminInfo) {
        mmh_log_event($conn, (int) $adminInfo->user_id, 'login', ['meta' => ['role' => 'admin']]);
    }

    mmh_auth_json(true, 'Welcome back.', ['redirect' => mmh_auth_destination($conn, $sessionUsername, 'admin', mmh_current_request_base_url())]);
}

if ($userData['role'] === 'user') {
    unset($_SESSION['admin']);
    $_SESSION['username'] = $sessionUsername;

    $studentInfo = getUserInfo($sessionUsername);
    if ($studentInfo) {
        $studentUserId = (int) $studentInfo->user_id;
        mmh_log_event($conn, $studentUserId, 'login', ['meta' => ['role' => 'user']]);
        mmh_track_daily_visit($conn, $studentUserId);
    }

    mmh_auth_json(true, 'Welcome back.', ['redirect' => mmh_auth_destination($conn, $sessionUsername, 'user', mmh_current_request_base_url())]);
}

mmh_auth_json(false, 'We could not sign you in with those details. Please try again.', [], 401);
