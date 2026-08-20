<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
$timedExam = file_get_contents($root . '/inc/TimedExam.php');
$studentView = file_get_contents($root . '/views/user/timed-exam.php');
$paperView = file_get_contents($root . '/views/user/requests/open-timed-exam-paper.php');
$router = file_get_contents($root . '/index.php');
$items = file_get_contents($root . '/views/admin/requests/items-item.php');
foreach (['timedExam' => $timedExam, 'studentView' => $studentView, 'paperView' => $paperView, 'router' => $router, 'items' => $items] as $name => $source) {
    if (!is_string($source)) throw new RuntimeException("Unable to inspect {$name} source.");
}

if (!str_contains($timedExam, 'function mmh_timed_exam_student_context(mysqli $conn, array $exam, int $studentId, bool $preview = false)')) {
    throw new RuntimeException('Timed Exam context does not expose an explicit preview mode.');
}
if (!str_contains($timedExam, "'preview' => true") || !str_contains($timedExam, "'attempt' => null")) {
    throw new RuntimeException('Preview context is not read-only and attempt-free.');
}
if (!str_contains($studentView, '$timedExamPreview = !empty($timedExamPreview);')
    || !str_contains($studentView, 'No answers, attempts, submissions, or grades will be saved.')
    || !str_contains($studentView, 'Preview mode — no submission was created.')) {
    throw new RuntimeException('Canonical student workspace is missing its preview safeguards.');
}
if (!str_contains($paperView, '$timedExamPreview = !empty($timedExamPreview);')
    || !str_contains($paperView, 'mmh_timed_exam_student_context($conn, $exam, (int) $studentId, $timedExamPreview)')) {
    throw new RuntimeException('Paper route does not carry the trusted preview context.');
}
if (!str_contains($router, "'/courses/{courseId}/timed-exam/item/{itemId}/preview'")
    || !str_contains($router, "'/courses/{courseId}/timed-exam/item/{itemId}/paper'")) {
    throw new RuntimeException('Admin preview routes are not registered.');
}
$previewRouteStart = strpos($router, "'/courses/{courseId}/timed-exam/item/{itemId}/preview'");
$paperRouteStart = strpos($router, "'/courses/{courseId}/timed-exam/item/{itemId}/paper'");
if ($previewRouteStart === false || $paperRouteStart === false) throw new RuntimeException('Unable to locate preview route bodies.');
$previewRoute = substr($router, $previewRouteStart, 1100);
$paperRoute = substr($router, $paperRouteStart, 1100);
foreach (['preview' => $previewRoute, 'paper' => $paperRoute] as $routeName => $routeSource) {
    $connectionInclude = strpos($routeSource, "require_once __DIR__ . '/connection/config.php';");
    $databaseUse = strpos($routeSource, '$previewConn = db();');
    if ($connectionInclude === false || $databaseUse === false || $connectionInclude > $databaseUse) {
        throw new RuntimeException("{$routeName} route does not load the canonical connection before db().");
    }
}
if (!str_contains($items, 'Preview as Student') || !str_contains($items, "\$template_type === 'timed_exam'")) {
    throw new RuntimeException('Timed Exam Course Content card has no dedicated preview action.');
}
if (preg_match('/\$timedExamPreview\s*=\s*\$_(?:GET|POST|REQUEST)/', $studentView . $paperView)) {
    throw new RuntimeException('Preview mode may not be activated from request input.');
}

echo "Timed Exam admin preview contract passed.\n";
