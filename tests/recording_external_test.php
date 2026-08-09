<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/CourseResourceResolver.php';

$share = 'https://alexuuni-my.sharepoint.com/:v:/g/personal/student/ABC123';
$stream = 'https://tenant.sharepoint.com/personal/student/_layouts/15/stream.aspx?id=%2Fpersonal%2Fstudent%2FDocuments%2Frecording.mp4';
$embed = 'https://tenant.sharepoint.com/personal/student/_layouts/15/embed.aspx?UniqueId=%7B12345678-1234-1234-1234-123456789012%7D';

if (mmh_course_resource_microsoft_recording_status($share)['state'] !== 'external'
    || mmh_course_resource_microsoft_recording_status($stream)['state'] !== 'external') {
    throw new RuntimeException('Supported external Microsoft recording links were rejected.');
}
if (mmh_course_resource_microsoft_recording_status($embed)['state'] !== 'legacy_embed') {
    throw new RuntimeException('Legacy embed.aspx was not classified for manual replacement.');
}
foreach (['', 'http://tenant.sharepoint.com/video', 'javascript:alert(1)', 'https://example.com/video', 'https://user:password@tenant.sharepoint.com/video'] as $invalid) {
    $state = mmh_course_resource_microsoft_recording_status($invalid);
    if ($invalid === '' || str_starts_with($invalid, 'http') || str_starts_with($invalid, 'javascript')) {
        if (($state['state'] ?? '') === 'external') throw new RuntimeException('Unsafe recording URL was accepted.');
    }
}

$external = mmh_course_resource_resolve_core([
    'template_type' => 'recording', 'item_type' => 'video', 'item_title' => 'Lecture recording',
    'template_data' => json_encode(['url' => $share]), 'item_description' => '',
]);
if (($external['action'] ?? '') !== 'recording_external'
    || ($external['open_url'] ?? '') !== $share
    || !empty($external['embed_url'])) {
    throw new RuntimeException('External recording did not resolve to the card flow.');
}

$unresolved = mmh_course_resource_resolve_core([
    'template_type' => 'recording', 'item_type' => 'video', 'item_title' => 'Legacy recording',
    'template_data' => json_encode(['url' => $embed]), 'item_description' => '',
]);
if (($unresolved['action'] ?? '') !== 'recording_unavailable' || stripos((string) ($unresolved['reason'] ?? ''), 'update') === false) {
    throw new RuntimeException('Legacy embed did not fail safely with a replacement message.');
}

$youtube = mmh_course_resource_resolve_core([
    'template_type' => 'video', 'item_type' => 'video', 'item_title' => 'YouTube lesson',
    'template_data' => json_encode(['url' => 'https://www.youtube.com/watch?v=abcdefghijk']), 'item_description' => '',
]);
if (($youtube['action'] ?? '') !== 'embed' || ($youtube['embed_kind'] ?? '') !== 'youtube') {
    throw new RuntimeException('Non-Microsoft video embedding regressed.');
}

$route = file_get_contents(dirname(__DIR__) . '/views/user/requests/open-course-resource.php');
$migration = file_get_contents(dirname(__DIR__) . '/scripts/reconcile-recordings.php');
$legacyMigration = file_get_contents(dirname(__DIR__) . '/scripts/migrate-course-resources.php');
if (!is_string($route) || !str_contains($route, "'recording_external'") || !str_contains($route, 'data-recording-open')) {
    throw new RuntimeException('Protected Recording card route is not wired.');
}
$css = file_get_contents(dirname(__DIR__) . '/resources/css/course-learning.css');
if (!is_string($route) || !str_contains($route, 'Microsoft Recording') || !str_contains($route, 'Watch the lesson recording')
    || !str_contains($route, 'recording-card-20260809')
    || !is_string($css) || !str_contains($css, "data-resource-viewer-kind='recording_external'") || !str_contains($css, 'course-resource-recording-card')) {
    throw new RuntimeException('Recording Launch Card presentation is not wired.');
}
$course = file_get_contents(dirname(__DIR__) . '/views/user/course.php');
if (!is_string($course) || !str_contains($course, "'recording_external'") || !str_contains($course, "'recording_unavailable'")) {
    throw new RuntimeException('Course navigation does not treat Recording cards as direct resources.');
}
$eventEndpoint = file_get_contents(dirname(__DIR__) . '/views/user/requests/event-learning.php');
if (!is_string($eventEndpoint) || !str_contains($eventEndpoint, "eventType === 'recording_started'") || !str_contains($eventEndpoint, 'student_course_progress_record_viewed')) {
    throw new RuntimeException('External Recording click does not preserve viewed-progress tracking.');
}
if (!is_string($migration) || !str_contains($migration, 'MMH_RECORDING_MIGRATION') || !str_contains($migration, '--confirm=RECONCILE_RECORDINGS')) {
    throw new RuntimeException('Auditable reconciliation script is incomplete.');
}
if (!is_string($legacyMigration) || !str_contains($legacyMigration, 'Microsoft embed or unsupported link requires a real external sharing URL')) {
    throw new RuntimeException('Legacy migration still permits guessed Microsoft recording conversions.');
}

echo "external_links=passed legacy_embed=guarded youtube_regression=passed card_flow=passed migration_contract=present\n";
