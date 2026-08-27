<?php
require_once 'connection/config.php';

header('Content-Type: application/json; charset=utf-8');

$conn = db();
$courses = [];
$courseResult = $conn->query('SELECT course_id, course_title, course_state FROM courses ORDER BY course_title ASC, course_id ASC');
if (!$courseResult) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Destination courses could not be loaded.']);
    exit;
}

$sectionStmt = $conn->prepare('SELECT section_id, title, sort_order FROM course_sections WHERE course_id = ? ORDER BY sort_order ASC, section_id ASC');
if (!$sectionStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Destination course sections could not be loaded.']);
    exit;
}
while ($course = $courseResult->fetch_assoc()) {
    $sections = [];
    $courseId = (string) $course['course_id'];
    $sectionStmt->bind_param('s', $courseId);
    if (!$sectionStmt->execute()) {
        $sectionStmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Destination course sections could not be loaded.']);
        exit;
    }
    foreach ($sectionStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $section) {
        $sections[] = ['id' => (string) $section['section_id'], 'title' => (string) $section['title']];
    }
    $courses[] = [
        'id' => (string) $course['course_id'],
        'title' => (string) $course['course_title'],
        'state' => (string) ($course['course_state'] ?? ''),
        'sections' => $sections,
    ];
}
$sectionStmt->close();

echo json_encode(['success' => true, 'courses' => $courses], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
