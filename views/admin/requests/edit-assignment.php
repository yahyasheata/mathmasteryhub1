<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/AssignmentProgress.php';

header('Content-Type: application/json; charset=utf-8');

function edit_assignment_response($success, $message, array $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => (bool) $success, 'status' => $success ? 1 : 0, 'message' => $message], $data));
    exit;
}
function edit_assignment_e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function edit_assignment_options(mysqli $conn, $assignment)
{
    $courses = $conn->query('SELECT course_id, course_title FROM courses ORDER BY course_title');
    $sections = $conn->query('SELECT section_id, course_id, title FROM course_sections ORDER BY course_id, sort_order, title');
    $items = $conn->query('SELECT item_id, course_id, section_id, item_title FROM course_items ORDER BY course_id, page_order, item_title');
    $courseHtml = '<option value="">Select course</option>';
    while ($row = $courses->fetch_assoc()) {
        $selected = (string) $row['course_id'] === (string) $assignment['course_id'] ? ' selected' : '';
        $courseHtml .= '<option value="' . edit_assignment_e($row['course_id']) . '"' . $selected . '>' . edit_assignment_e($row['course_title']) . '</option>';
    }
    $sectionHtml = '<option value="">General / no section</option>';
    while ($row = $sections->fetch_assoc()) {
        $selected = (string) $row['section_id'] === (string) ($assignment['section_id'] ?? '') ? ' selected' : '';
        $sectionHtml .= '<option value="' . edit_assignment_e($row['section_id']) . '" data-course-id="' . edit_assignment_e($row['course_id']) . '"' . $selected . '>' . edit_assignment_e($row['title']) . '</option>';
    }
    $itemHtml = '<option value="">No linked lesson</option>';
    while ($row = $items->fetch_assoc()) {
        $selected = (string) $row['item_id'] === (string) ($assignment['item_id'] ?? '') ? ' selected' : '';
        $itemHtml .= '<option value="' . edit_assignment_e($row['item_id']) . '" data-course-id="' . edit_assignment_e($row['course_id']) . '" data-section-id="' . edit_assignment_e($row['section_id']) . '"' . $selected . '>' . edit_assignment_e($row['item_title']) . '</option>';
    }
    return [$courseHtml, $sectionHtml, $itemHtml];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') edit_assignment_response(false, 'Invalid request method.', [], 405);
$conn = db();
mmh_ensure_learning_schema($conn);
$method = (string) ($_POST['_method'] ?? '');
$assignmentId = trim((string) ($_POST['assignment_id'] ?? ''));
if ($assignmentId === '') edit_assignment_response(false, 'Assignment not found.', [], 422);

if ($method === 'GET') {
    $stmt = $conn->prepare('SELECT * FROM assignments WHERE assignment_id = ? LIMIT 1');
    $stmt->bind_param('s', $assignmentId); $stmt->execute(); $assignment = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$assignment) edit_assignment_response(false, 'Assignment not found.', [], 404);
    [$courses, $sections, $items] = edit_assignment_options($conn, $assignment);
    $scope = mmh_assignment_progress_requirement_scope($assignment['completion_requirement'] ?? 'optional');
    $rule = mmh_assignment_progress_completion_rule($assignment['completion_rule'] ?? 'submission');
    $file = empty($assignment['file_path']) ? '<span class="ds-text-muted">No file attached</span>' : '<a href="../../' . edit_assignment_e($assignment['file_path']) . '" target="_blank" rel="noopener">Current file</a>';
    $lateEnabled = !empty($assignment['late_submission_enabled']);
    $lateUntil = !empty($assignment['late_submission_until']) ? date('Y-m-d\TH:i', strtotime((string) $assignment['late_submission_until'])) : '';
    $html = '<div class="modal fade show" id="editAssignmentModal" tabindex="-1" aria-modal="true" style="display:block"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit Assignment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><form id="updateAssignment" enctype="multipart/form-data"><div class="row g-3"><div class="col-md-6"><label class="form-label">Title</label><input class="form-control" name="assignment_title" required maxlength="190" value="' . edit_assignment_e($assignment['assignment_title']) . '"></div><div class="col-md-6"><label class="form-label">Due date</label><input class="form-control" type="date" name="due_date" required value="' . edit_assignment_e($assignment['due_date']) . '"></div><div class="col-md-6"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="late_submission_enabled" value="1" data-late-submission-enabled' . ($lateEnabled ? ' checked' : '') . '><span class="form-check-label">Enable Legacy Late Submission</span></label></div><div class="col-md-6" data-late-submission-until' . ($lateEnabled ? '' : ' hidden') . '><label class="form-label">Late Submission Until</label><input class="form-control" type="datetime-local" name="late_submission_until" value="' . edit_assignment_e($lateUntil) . '"></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="assignment_description" rows="3" required>' . edit_assignment_e($assignment['assignment_description']) . '</textarea></div><div class="col-md-6"><label class="form-label">Course</label><select class="form-control" name="course_id" data-assignment-course required>' . $courses . '</select></div><div class="col-md-6"><label class="form-label">Requirement</label><select class="form-control" name="completion_requirement" data-assignment-requirement><option value="optional"' . ($scope === 'optional' ? ' selected' : '') . '>Optional</option><option value="lesson"' . ($scope === 'lesson' ? ' selected' : '') . '>Required for this lesson</option><option value="section"' . ($scope === 'section' ? ' selected' : '') . '>Required for this section</option></select></div><div class="col-md-6"><label class="form-label">Completion rule</label><select class="form-control" name="completion_rule" data-assignment-rule><option value="submission"' . ($rule === 'submission' ? ' selected' : '') . '>Submission only</option><option value="teacher_approval"' . ($rule === 'teacher_approval' ? ' selected' : '') . '>Teacher approval</option><option value="valid_score"' . ($rule === 'valid_score' ? ' selected' : '') . '>Valid final score</option><option value="minimum_score"' . ($rule === 'minimum_score' ? ' selected' : '') . '>Minimum score</option></select></div><div class="col-md-6" data-assignment-minimum><label class="form-label">Minimum score</label><input class="form-control" type="number" min="0" step="0.01" name="minimum_score" value="' . edit_assignment_e($assignment['minimum_score'] ?? '') . '"></div><div class="col-md-6" data-assignment-section-wrap><label class="form-label">Section</label><select class="form-control" name="section_id" data-assignment-section>' . $sections . '</select></div><div class="col-md-6" data-assignment-item-wrap><label class="form-label">Linked lesson</label><select class="form-control" name="item_id" data-assignment-item>' . $items . '</select></div><div class="col-md-6"><label class="form-label">Current file</label><div>' . $file . '</div><label class="form-label mt-2">Replace file <small class="ds-text-muted">(optional)</small></label><input class="form-control" type="file" name="assignment_file" accept=".pdf,.doc,.docx"></div></div><input type="hidden" name="assignment_id" value="' . edit_assignment_e($assignmentId) . '"><input type="hidden" name="_method" value="UPDATE"><div class="modal-footer px-0 pb-0"><button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary submitBtn">Save changes</button></div></form></div></div></div></div>';
    edit_assignment_response(true, 'Assignment loaded.', ['html' => $html]);
}

if ($method !== 'UPDATE') edit_assignment_response(false, 'Invalid request.', [], 422);
$title = trim((string) ($_POST['assignment_title'] ?? '')); $description = trim((string) ($_POST['assignment_description'] ?? '')); $dueDate = trim((string) ($_POST['due_date'] ?? '')); $courseId = mmh_assignment_progress_id($_POST['course_id'] ?? '');
if ($title === '' || $description === '' || $dueDate === '' || $courseId === null || strtotime($dueDate) === false) edit_assignment_response(false, 'Please enter valid assignment details.', [], 422);
$lateSubmissionEnabled = !empty($_POST['late_submission_enabled']) ? 1 : 0;
$lateSubmissionUntil = trim((string) ($_POST['late_submission_until'] ?? ''));
if ($lateSubmissionEnabled && ($lateSubmissionUntil === '' || strtotime($lateSubmissionUntil) === false)) edit_assignment_response(false, 'Choose a valid late-submission deadline.', [], 422);
if (!$lateSubmissionEnabled) { $lateSubmissionUntil = null; }
$stmt = $conn->prepare('SELECT file_path FROM assignments WHERE assignment_id = ? LIMIT 1'); $stmt->bind_param('s', $assignmentId); $stmt->execute(); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$old) edit_assignment_response(false, 'Assignment not found.', [], 404);
$scope = mmh_assignment_progress_requirement_scope($_POST['completion_requirement'] ?? 'optional'); $rule = mmh_assignment_progress_completion_rule($_POST['completion_rule'] ?? 'submission');
$minimumScore = null;
if ($rule === 'minimum_score') { $raw = trim((string) ($_POST['minimum_score'] ?? '')); if ($raw === '' || !is_numeric($raw) || (float)$raw < 0) edit_assignment_response(false, 'Enter a valid minimum score.', [], 422); $minimumScore = (float)$raw; }
$context = mmh_assignment_progress_validate_context($conn, $courseId, $_POST['section_id'] ?? '', $_POST['item_id'] ?? '', $scope);
if (empty($context['ok'])) edit_assignment_response(false, $context['message'] ?? 'Invalid course-content reference.', [], 422);
$filePath = $old['file_path']; $newPath = null;
if (isset($_FILES['assignment_file']) && (int)($_FILES['assignment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['assignment_file']; $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ((int)$file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || !in_array($extension, ['pdf','doc','docx'], true)) edit_assignment_response(false, 'Upload a valid PDF or Word file.', [], 422);
    $dir='uploads/static/assignments/'; if (!is_dir($dir) && !mkdir($dir,0777,true) && !is_dir($dir)) edit_assignment_response(false, 'Unable to prepare the upload directory.', [],500);
    $newPath = $dir . 'assignment_' . preg_replace('/[^A-Za-z0-9_-]/','', $assignmentId) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $newPath)) edit_assignment_response(false, 'The assignment file could not be uploaded.', [],500);
    $filePath=$newPath;
}
$sectionId = $context['section_id'] === '' ? null : $context['section_id']; $itemId = $context['item_id'] === '' ? null : $context['item_id']; $minimumDb = $minimumScore === null ? null : number_format($minimumScore,2,'.','');
$stmt=$conn->prepare('UPDATE assignments SET assignment_title=?, assignment_description=?, due_date=?, late_submission_enabled=?, late_submission_until=?, course_id=?, file_path=?, section_id=?, item_id=?, completion_requirement=?, completion_rule=?, minimum_score=? WHERE assignment_id=?');
$stmt->bind_param('sssisssssssss',$title,$description,$dueDate,$lateSubmissionEnabled,$lateSubmissionUntil,$courseId,$filePath,$sectionId,$itemId,$scope,$rule,$minimumDb,$assignmentId); $saved=$stmt->execute(); $error=$stmt->error; $stmt->close();
if (!$saved) { if ($newPath && is_file($newPath)) @unlink($newPath); edit_assignment_response(false, 'Unable to update assignment: '.$error, [],500); }
if ($newPath && !empty($old['file_path']) && is_file($old['file_path'])) @unlink($old['file_path']);
edit_assignment_response(true, 'Assignment updated successfully.');
