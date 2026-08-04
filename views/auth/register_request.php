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

$fullName = trim((string) ($_POST['name'] ?? ''));
$username = mmh_auth_normalize_username((string) ($_POST['phone_number'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

if (mmh_auth_string_length($fullName) < 2 || mmh_auth_string_length($fullName) > 250) {
    mmh_auth_json(false, 'Enter your full name.', [], 422);
}

if (!mmh_auth_valid_username($username)) {
    mmh_auth_json(false, 'Enter a valid email address or phone number.', [], 422);
}

if (strlen($password) < 8) {
    mmh_auth_json(false, 'Choose a password with at least 8 characters.', [], 422);
}

if (!hash_equals($password, $passwordConfirmation)) {
    mmh_auth_json(false, 'Your passwords do not match.', [], 422);
}

$conn = db();
$duplicate = $conn->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
if (!$duplicate) {
    mmh_auth_json(false, 'We could not create your account right now. Please try again shortly.', [], 500);
}
$duplicate->bind_param('s', $username);
$duplicate->execute();
$existingAccount = $duplicate->get_result()->fetch_assoc();
$duplicate->close();

if ($existingAccount) {
    mmh_auth_json(false, 'An account already uses that email address or phone number. Please sign in instead.', [], 409);
}

$userId = 0;
$identifier = $conn->prepare('SELECT id FROM users WHERE user_id = ? LIMIT 1');
if (!$identifier) {
    mmh_auth_json(false, 'We could not create your account right now. Please try again shortly.', [], 500);
}
for ($attempt = 0; $attempt < 10; $attempt++) {
    $candidate = random_int(100000, 99999999);
    $identifier->bind_param('i', $candidate);
    $identifier->execute();
    if (!$identifier->get_result()->fetch_assoc()) {
        $userId = $candidate;
        break;
    }
}
$identifier->close();

if ($userId === 0) {
    mmh_auth_json(false, 'We could not create your account right now. Please try again shortly.', [], 500);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    mmh_auth_json(false, 'We could not secure your password. Please try again.', [], 500);
}

try {
    $conn->begin_transaction();

    $insert = $conn->prepare(
        "INSERT INTO users (user_id, full_name, username, guardian_number, password, governorate, gender, role, status)\n         VALUES (?, ?, ?, NULL, ?, NULL, NULL, 'user', '1')"
    );
    if (!$insert) {
        throw new RuntimeException('Account insert preparation failed.');
    }
    $insert->bind_param('isss', $userId, $fullName, $username, $passwordHash);
    if (!$insert->execute()) {
        $insert->close();
        throw new RuntimeException('Account insert failed.');
    }
    $insert->close();

    $notificationTitle = 'Welcome to Math Mastery Hub';
    $notificationMessage = 'Your account is ready. You can start learning now.';
    $notification = $conn->prepare(
        'INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)'
    );
    if ($notification) {
        $notification->bind_param('iss', $userId, $notificationTitle, $notificationMessage);
        if (!$notification->execute()) {
            $notification->close();
            throw new RuntimeException('Welcome notification failed.');
        }
        $notification->close();
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    mmh_auth_json(false, 'We could not create your account right now. Please try again shortly.', [], 500);
}

mmh_auth_regenerate_session();
unset($_SESSION['mmh_auth_csrf_token'], $_SESSION['admin']);
$_SESSION['username'] = $username;

mmh_log_event($conn, $userId, 'login', ['meta' => ['role' => 'user', 'first_login' => true]]);
mmh_track_daily_visit($conn, $userId);

mmh_auth_json(true, 'Your account is ready. Welcome to Math Mastery Hub.', [
    'redirect' => mmh_auth_destination($conn, $username, 'user', mmh_current_request_base_url()),
]);
