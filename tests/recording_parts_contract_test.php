<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/CourseResourceResolver.php';
require_once dirname(__DIR__) . '/inc/CourseContentCopyService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$part1 = 'https://drive.google.com/file/d/part-one/view?usp=sharing';
$part2 = 'https://drive.google.com/file/d/part-two/view?usp=sharing';
$part3 = 'https://drive.google.com/file/d/part-three/view?usp=sharing';
$microsoft = 'https://tenant.sharepoint.com/:v:/s/class/recording-part';
$partsItem = [
    'template_type' => 'recording',
    'item_type' => 'video',
    'item_title' => 'Lecture 12',
    'template_data' => json_encode([
        'url' => $part1,
        'external_url' => 'https://tenant.sharepoint.com/:v:/s/class/stale-alias',
        'additional_recording_urls' => [$part2, $part3],
        'teacher_note' => 'preserve',
    ], JSON_UNESCAPED_SLASHES),
    'item_description' => '<p>Teacher-authored content remains stored.</p>',
];

$assert(mmh_course_resource_recording_parts($partsItem) === [$part1, $part2, $part3], 'Recording URL reader did not preserve ordered primary and additional parts.');
$namedPartsItem = $partsItem;
$namedPartsItem['template_data'] = json_decode((string) $partsItem['template_data'], true);
$namedPartsItem['template_data']['recording_part_names'] = ['', 'Exam Questions', 'Practice'];
$assert(mmh_course_resource_recording_part_names($namedPartsItem, 3) === ['', 'Exam Questions', 'Practice'], 'Optional names were not read in recording URL order.');
$assert(mmh_course_resource_recording_part_names($partsItem, 3) === ['', '', ''], 'Legacy unnamed parts should use generated Part N labels.');
$resolved = mmh_course_resource_resolve_core($partsItem);
$assert(($resolved['action'] ?? '') === 'recording_parts', 'Multi-part Recording did not resolve through the shared viewer.');
$assert(array_column($resolved['recording_parts'] ?? [], 'url') === [$part1, $part2, $part3], 'Resolved parts are missing or out of order.');
$assert(array_column($resolved['recording_parts'] ?? [], 'label') === ['Part 1', 'Part 2', 'Part 3'], 'Default part labels changed for an existing multi-part Recording.');
$namedResolved = mmh_course_resource_resolve_core($namedPartsItem);
$assert(array_column($namedResolved['recording_parts'] ?? [], 'label') === ['Part 1', 'Exam Questions', 'Practice'], 'Custom labels did not remain aligned with their Recording URLs.');
$removedMiddlePart = $partsItem;
$removedMiddlePart['template_data'] = [
    'url' => $part1,
    'additional_recording_urls' => [$part3],
    'recording_part_names' => ['Introduction', 'Practice'],
];
$removedMiddleResolved = mmh_course_resource_resolve_core($removedMiddlePart);
$assert(array_map(static fn(array $part): array => [$part['url'], $part['label']], $removedMiddleResolved['recording_parts'] ?? []) === [[$part1, 'Introduction'], [$part3, 'Practice']], 'Removing a middle part attached the remaining custom name to the wrong URL.');
$assert(($resolved['recording_parts'][0]['embed_url'] ?? '') === 'https://drive.google.com/file/d/part-one/preview', 'Part 1 preview URL was not derived by the existing Drive resolver.');

$microsoftItem = [
    'template_type' => 'recording',
    'item_type' => 'video',
    'item_title' => 'Lecture 13',
    'template_data' => ['url' => $part1, 'additional_recording_urls' => [$microsoft]],
    'item_description' => '',
];
$mixed = mmh_course_resource_resolve_core($microsoftItem);
$assert(($mixed['action'] ?? '') === 'recording_parts' && ($mixed['recording_parts'][1]['mode'] ?? '') === 'external', 'A supported external Microsoft part was not represented as an external target.');
$assert(($mixed['recording_parts'][1]['open_url'] ?? '') === $microsoft, 'Microsoft part open URL changed.');

$structuredSingle = mmh_course_resource_resolve_core([
    'template_type' => 'resource', 'item_type' => 'video', 'item_title' => 'Structured Recording',
    'template_data' => ['resource_type' => 'recording', 'resource_url' => $part1, 'embed_enabled' => false], 'item_description' => '',
]);
$assert(($structuredSingle['action'] ?? '') === 'redirect', 'Legacy structured Resource single-recording behavior changed.');
$structuredMulti = mmh_course_resource_resolve_core([
    'template_type' => 'resource', 'item_type' => 'video', 'item_title' => 'Structured Recording',
    'template_data' => ['resource_type' => 'recording', 'resource_url' => $part1, 'embed_enabled' => false, 'additional_recording_urls' => [$part2]], 'item_description' => '',
]);
$assert(($structuredMulti['action'] ?? '') === 'recording_parts' && array_column($structuredMulti['recording_parts'] ?? [], 'url') === [$part1, $part2], 'Structured Resource recordings do not resolve in order with additional parts.');

$legacyCases = [
    ['template_type' => 'recording', 'template_data' => ['external_url' => $part1], 'item_description' => ''],
    ['template_type' => 'video', 'item_type' => 'video', 'template_data' => '{}', 'item_description' => '<div>Open the recording</div><a href="' . $part1 . '">Open</a>'],
    ['template_type' => 'video', 'item_type' => 'video', 'template_data' => '{}', 'item_description' => '<div>Open the recording</div><iframe src="' . str_replace('/view?usp=sharing', '/preview', $part1) . '"></iframe>'],
    ['template_type' => 'video', 'item_type' => 'video', 'template_data' => '{}', 'item_description' => '<button data-src="' . $part1 . '">Open the recording</button>'],
    ['template_type' => 'resource', 'template_data' => ['resource_type' => 'recording', 'resource_url' => $part1], 'item_description' => ''],
];
foreach ($legacyCases as $legacy) {
    $legacyItem = array_merge(['item_type' => 'video', 'item_title' => 'Legacy Recording'], $legacy);
    $urls = mmh_course_resource_recording_parts($legacyItem);
    $assert(isset($urls[0]) && $urls[0] !== '', 'Supported legacy Recording URL representation did not populate Part 1.');
}

$single = mmh_course_resource_resolve_core([
    'template_type' => 'recording', 'item_type' => 'video', 'item_title' => 'Single Recording',
    'template_data' => ['url' => $part1], 'item_description' => '',
]);
$assert(($single['action'] ?? '') === 'embed' && !isset($single['recording_parts']), 'Single-video Recording behavior changed.');

$root = dirname(__DIR__);
$form = file_get_contents($root . '/views/admin/requests/form-item.php');
$save = file_get_contents($root . '/views/admin/requests/add-item.php');
$admin = file_get_contents($root . '/views/admin/course-content.php');
$viewer = file_get_contents($root . '/views/user/requests/open-course-resource.php');
$viewerJs = file_get_contents($root . '/resources/js/course-resource-viewer.js');
$preview = file_get_contents($root . '/views/admin/course-content-preview.php');
$copy = file_get_contents($root . '/inc/CourseContentCopyService.php');
$assert(is_string($form) && str_contains($form, 'additional_recording_urls[]') && str_contains($form, 'recording_part_names[]') && str_contains($form, 'data-recording-add-part') && str_contains($form, 'data-recording-remove'), 'Admin form does not render names or the existing Add/Remove controls.');
$assert(is_string($save) && str_contains($save, 'item_recording_url($partUrl, $index + 2)') && str_contains($save, 'recording_part_names') && str_contains($save, 'must be 80 characters or fewer') && str_contains($save, 'unset($template_data[\'recording_part_names\'])') && str_contains($save, 'SELECT * FROM course_items WHERE item_id = ? AND course_id = ?') && str_contains($save, '$stored_template_type'), 'Recording save does not validate names and preserve authoritative existing data.');
$assert(is_string($admin) && str_contains($admin, 'additional_recording_urls[]') && str_contains($admin, 'recording_part_names[]') && str_contains($admin, 'data-recording-part-label'), 'Admin Add/Remove handlers do not create and renumber ordered parts and names.');
$assert(is_string($viewer) && str_contains($viewer, 'data-recording-part-button') && str_contains($viewer, 'data-resource-viewer-src'), 'Student viewer does not render the part selector and protected viewer target.');
$assert(is_string($viewerJs) && str_contains($viewerJs, "beginLoad('part')") && str_contains($viewerJs, 'data-resource-open-external') && str_contains($viewerJs, 'data-resource-download'), 'Part switching does not update viewer loading and external/download controls.');
$assert(is_string($preview) && str_contains($preview, 'data-preview-recording-part') && str_contains($preview, 'admin_content_preview_escape($part[\'label\']'), 'Admin preview does not offer safely escaped custom part labels.');
$assert(is_string($viewer) && str_contains($viewer, 'course_resource_escape($part[\'label\']'), 'Student viewer does not safely escape custom part labels.');
$assert(is_string($admin) && str_contains($admin, "$(this).find('[data-recording-part-name]').attr('placeholder', label)"), 'Removing a part does not update the default name placeholder to its new position.');
$assert(is_string($copy) && str_contains($copy, 'copyItem') && str_contains($copy, 'jsonRemap'), 'Existing duplicate/copy path is no longer available to preserve Recording JSON.');
$jsonRemap = new ReflectionMethod(CourseContentCopyService::class, 'jsonRemap');
$copiedRecordingData = json_decode((string) $jsonRemap->invoke(null, json_encode(['url' => $part1, 'additional_recording_urls' => [$part2, $part3], 'recording_part_names' => ['', 'Exam Questions', 'Practice'], 'custom_metadata' => ['teacher_note' => 'keep']]), []), true);
$assert(($copiedRecordingData['url'] ?? '') === $part1 && ($copiedRecordingData['additional_recording_urls'] ?? []) === [$part2, $part3] && ($copiedRecordingData['recording_part_names'] ?? []) === ['', 'Exam Questions', 'Practice'] && ($copiedRecordingData['custom_metadata']['teacher_note'] ?? '') === 'keep', 'Existing copy JSON transformation does not preserve ordered URLs, custom names and unrelated metadata.');
$assert(str_contains($viewer, 'data-item-id="<?= course_resource_escape($itemId); ?>"') && str_contains($viewer, 'data-resource-complete'), 'Recording completion is no longer tied to the single Course Item identity.');

echo "Recording Parts contract tests passed.\n";
