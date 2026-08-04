<?php
require_once 'connection/config.php';
require_once '__init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Method Not Allowed']));
}
$conn = db();

if ((string) ($_POST['_method'] ?? '') === 'GET') {
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) { exit(json_encode(['status' => 0, 'message' => 'Invalid student.'])); }
    $stmt = $conn->prepare("SELECT user_id, full_name, username, governorate, gender, status FROM users WHERE user_id = ? AND role = 'user' LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) { http_response_code(404); exit(json_encode(['status' => 0, 'message' => 'Student not found.'])); }
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '<div class="modal fade show" id="response-html-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><form action="requests/user/edit" method="POST" id="updateUser"><input type="hidden" name="user_id" value="'.$e($user['user_id']).'"><input type="hidden" name="_method" value="UPDATE"><input type="hidden" name="mmh_csrf_token" value="'.htmlspecialchars(mmh_admin_csrf_token(), ENT_QUOTES, 'UTF-8').'"><label>Name<input class="form-control" name="name" required value="'.$e($user['full_name']).'"></label><label>Phone / username<input class="form-control" name="phone_number" required value="'.$e($user['username']).'"></label><label>Governorate<input class="form-control" name="governorate" required value="'.$e($user['governorate']).'"></label><label>Gender<input class="form-control" name="gender" value="'.$e($user['gender']).'"></label><label>New password <small>(leave blank to keep the current password)</small><input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password"></label><div class="modal-footer"><button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save</button></div></form></div></div></div></div>';
    exit(json_encode(['status' => 1, 'html' => $html]));
}

if ((string) ($_POST['_method'] ?? '') !== 'UPDATE') { exit(json_encode(['status' => 0, 'message' => 'Invalid update request.'])); }
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone_number'] ?? ''));
$gender = trim((string) ($_POST['gender'] ?? ''));
$governorate = trim((string) ($_POST['governorate'] ?? ''));
$newPassword = (string) ($_POST['password'] ?? '');
if ($userId === false || $name === '' || $phone === '' || $governorate === '') { exit(json_encode(['status' => 0, 'message' => 'All required fields must be completed'])); }

if ($newPassword !== '') {
    if (strlen($newPassword) < 8) { exit(json_encode(['status' => 0, 'message' => 'Password must be at least 8 characters.'])); }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, password = ?, governorate = ?, gender = ? WHERE user_id = ? AND role = 'user'");
    $stmt->bind_param('sssssi', $name, $phone, $hash, $governorate, $gender, $userId);
} else {
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, governorate = ?, gender = ? WHERE user_id = ? AND role = 'user'");
    $stmt->bind_param('ssssi', $name, $phone, $governorate, $gender, $userId);
}
$ok = $stmt && $stmt->execute();
if ($stmt) { $stmt->close(); }
echo json_encode(['status' => $ok ? 1 : 0, 'message' => $ok ? 'Student updated successfully' : 'Database connection error']);
