<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/RecoveryPlanTemplates.php';

$conn = db(); $base = rtrim((string) $baseUrl, '/'); $templateId = (int) ($_POST['template_id'] ?? 0);
$flash = static function (bool $ok, string $message, int $templateId) use ($base): void { $_SESSION['recovery_assignment_flash'] = ['ok' => $ok, 'message' => $message]; header('Location: ' . $base . '/admin/recovery-plan-assignments?template_id=' . $templateId); exit; };
if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) $flash(false, 'Your session has expired. Refresh and try again.', $templateId);
try {
    $template = mmh_recovery_template_load($conn, $templateId);
    if (!$template || $template['status'] === 'archived') throw new InvalidArgumentException('Choose an active template.');
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
    if (!empty($_POST['assign_course'])) {
        $students = mmh_recovery_template_students($conn, (string) $template['course_id']);
        $studentIds = array_map(static fn(array $student): int => (int) $student['user_id'], $students);
    }
    if (!$studentIds) throw new InvalidArgumentException('Select at least one enrolled student.');
    $assigned = 0; $skipped = 0; $errors = [];
    foreach ($studentIds as $studentId) {
        try { mmh_recovery_template_copy_to_student($conn, $template, $studentId, $adminId); $assigned++; }
        catch (Throwable $exception) { $skipped++; $errors[] = 'Student ' . $studentId . ': ' . $exception->getMessage(); }
    }
    $message = $assigned . ' independent plan(s) assigned';
    if ($skipped) $message .= '; ' . $skipped . ' skipped (existing active plans or invalid enrollment).';
    if ($errors) $message .= ' ' . implode(' ', array_slice($errors, 0, 2));
    $flash(true, $message, $templateId);
} catch (Throwable $exception) { $flash(false, $exception->getMessage(), $templateId); }
