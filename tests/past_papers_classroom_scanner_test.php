<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/PastPaperClassroomScanner.php';

function classroom_test(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$approved = mmh_classroom_parse_topic('May June 2022 V1 (New Syllabus)');
classroom_test(is_array($approved) && $approved['session'] === 'May/June' && $approved['year'] === 2022 && $approved['variant'] === 'V1', 'May June topic parsing failed.');
classroom_test(mmh_classroom_is_target_syllabus(['syllabus_code' => '0580', 'board_name' => 'Cambridge']) === true, 'Cambridge 0580 target syllabus was rejected.');
classroom_test(mmh_classroom_is_target_syllabus(['syllabus_id' => 'cambridge-0580', 'syllabus_code' => '0580', 'exam_board_id' => 'cambridge', 'board_name' => 'Cambridge']) === true, 'Rendered Cambridge 0580 syllabus was rejected after POST lookup.');
classroom_test(mmh_classroom_is_target_syllabus(['syllabus_code' => '4MA1', 'board_name' => 'Edexcel']) === false, 'Edexcel 4MA1 was accepted as a Classroom target.');
classroom_test(mmh_classroom_is_target_syllabus(['syllabus_code' => '0580', 'board_name' => 'Edexcel']) === false, 'Non-Cambridge 0580 was accepted as a Classroom target.');
classroom_test(mmh_classroom_parse_topic('May/June 2022 V3') !== null, 'May/June alias parsing failed.');
classroom_test(mmh_classroom_parse_topic('Oct Nov 2022 V2 (New Syllabus)')['session'] === 'October/November', 'Oct Nov parsing failed.');
classroom_test(mmh_classroom_parse_topic('May June 2023 V3') === null, 'Out-of-scope May June topic was accepted.');
classroom_test(mmh_classroom_parse_topic('Oct Nov 2023 V1') === null, 'Out-of-scope Oct Nov topic was accepted.');
classroom_test(mmh_classroom_component(2, 1) === '21' && mmh_classroom_component(4, 3) === '43', 'Component mapping failed.');

foreach ([
    ['Paper 2 and Paper 4 Solution Video', 'video_solution', [2, 4]],
    ['Paper 2 and Paper 4 MA', 'model_answer', [2, 4]],
    ['Paper 2 and Paper 4 QP', 'question_paper', [2, 4]],
    ['Paper 2 QP', 'question_paper', [2]],
    ['Paper 4 QP', 'question_paper', [4]],
] as [$title, $type, $papers]) {
    $classified = mmh_classroom_classify_title($title);
    classroom_test(is_array($classified) && $classified['resource_type'] === $type && $classified['papers'] === $papers, 'Resource classification failed for ' . $title . '.');
}

$topic = ['id' => 'topic-1', 'title' => 'May June 2022 V2 (New Syllabus)'];
$items = [
    ['item_type' => 'coursework_material', 'id' => 'material-qp', 'title' => 'Paper 2 and Paper 4 QP', 'topic_id' => 'topic-1', 'attachments' => [['attachment_type' => 'google_drive', 'title' => 'Combined QP.pdf', 'url' => 'https://drive.google.com/file/d/qp/view', 'drive_file_id' => 'qp']]],
    ['item_type' => 'coursework', 'id' => 'work-ma', 'title' => 'Paper 2 and Paper 4 MA', 'topic_id' => 'topic-1', 'attachments' => [['attachment_type' => 'google_drive', 'title' => 'Combined MA.pdf', 'url' => 'https://drive.google.com/file/d/ma/view', 'drive_file_id' => 'ma']]],
    ['item_type' => 'coursework_material', 'id' => 'material-video', 'title' => 'Paper 2 and Paper 4 Solution Video', 'topic_id' => 'topic-1', 'attachments' => [['attachment_type' => 'youtube', 'title' => 'Solution', 'url' => 'https://www.youtube.com/watch?v=video', 'youtube_id' => 'video']]],
];
$preview = mmh_classroom_build_preview([$topic], $items, ['syllabus_id' => 'syllabus-0580', 'public_title' => 'Cambridge Mathematics', 'syllabus_code' => '0580', 'board_name' => 'Cambridge'], static fn(array $candidate): ?array => $candidate['paper'] === 2 ? ['paper_id' => 'existing-paper'] : null);
classroom_test($preview['summary']['approved_topics'] === 1 && $preview['summary']['candidates'] === 2, 'Candidate count failed.');
classroom_test($preview['candidates'][0]['component'] === '22' && $preview['candidates'][1]['component'] === '42', 'V2 component mapping failed.');
classroom_test($preview['candidates'][0]['status'] === 'READY' && $preview['candidates'][1]['status'] === 'READY', 'Complete candidate was not ready.');
classroom_test(!empty($preview['candidates'][0]['existing_paper']) && empty($preview['candidates'][1]['existing_paper']), 'Duplicate lookup result was not preserved.');

$splitItems = [
    ['item_type' => 'coursework_material', 'id' => 'split-qp', 'title' => 'Paper 2 and Paper 4 QP', 'topic_id' => 'topic-1', 'attachments' => [
        ['attachment_type' => 'google_drive', 'title' => 'Nov 22 V2 P4 copy.pdf', 'drive_file_id' => 'split-p4'],
        ['attachment_type' => 'google_drive', 'title' => 'Nov 22 V2 P2 copy.pdf', 'drive_file_id' => 'split-p2'],
    ]],
    ['item_type' => 'coursework', 'id' => 'split-ma', 'title' => 'Paper 2 and Paper 4 MA', 'topic_id' => 'topic-1', 'attachments' => [
        ['attachment_type' => 'google_drive', 'title' => 'Nov 22 V2 P4 MA.pdf', 'drive_file_id' => 'split-ma4'],
        ['attachment_type' => 'google_drive', 'title' => 'Nov 22 V2 P2 MA.pdf', 'drive_file_id' => 'split-ma2'],
    ]],
];
$splitPreview = mmh_classroom_build_preview([$topic], $splitItems, ['syllabus_id' => 'syllabus-0580', 'public_title' => 'Cambridge Mathematics', 'syllabus_code' => '0580', 'board_name' => 'Cambridge']);
classroom_test(count($splitPreview['candidates'][0]['resources']) === 2 && count($splitPreview['candidates'][1]['resources']) === 2, 'P2/P4-labelled attachments were not split between candidates.');
classroom_test($splitPreview['candidates'][0]['resources'][0]['paper_number'] === 2 && $splitPreview['candidates'][1]['resources'][0]['paper_number'] === 4, 'Attachment paper routing did not preserve explicit P2/P4 labels.');
classroom_test(mmh_classroom_attachment_papers('0580/22 QP.pdf', ['papers' => [2]], 2) === [2] && mmh_classroom_attachment_papers('0580/42 QP.pdf', ['papers' => [4]], 2) === [4], 'Component attachment mapping failed.');
classroom_test(mmh_classroom_attachment_papers('May June 2023 Paper 2 and 4 Walkthrough', ['papers' => [2, 4]], 2) === [2, 4], 'Combined Paper 2 and 4 attachment mapping failed.');
$componentConflict = mmh_classroom_build_preview([$topic], [['item_type' => 'coursework', 'id' => 'conflict-component', 'title' => 'Paper 2 QP', 'topic_id' => 'topic-1', 'attachments' => [['attachment_type' => 'google_drive', 'title' => '0580/23 QP.pdf', 'drive_file_id' => 'conflict']]]], ['syllabus_id' => 'syllabus-0580', 'public_title' => 'Cambridge Mathematics', 'syllabus_code' => '0580', 'board_name' => 'Cambridge']);
classroom_test(count($componentConflict['warnings']) === 1 && $componentConflict['warnings'][0]['status'] === 'NEEDS REVIEW' && empty($componentConflict['candidates'][0]['resources']), 'Conflicting component was not held for review.');

$conflict = mmh_classroom_build_preview([$topic], [['item_type' => 'coursework', 'id' => 'work', 'title' => 'Paper 2 and Paper 4 QP', 'topic_id' => 'topic-1', 'attachments' => [['attachment_type' => 'google_drive', 'title' => 'file-a.pdf', 'url' => 'https://drive.google.com/a'], ['attachment_type' => 'google_drive', 'title' => 'file-b.pdf', 'url' => 'https://drive.google.com/b']]]], ['syllabus_id' => 'syllabus-0580', 'public_title' => 'Cambridge Mathematics', 'syllabus_code' => '0580', 'board_name' => 'Cambridge']);
classroom_test(count($conflict['warnings']) === 1 && $conflict['warnings'][0]['status'] === 'NEEDS REVIEW', 'Ambiguous combined attachments were not flagged.');

$warningPreview = mmh_classroom_build_preview([$topic], [['item_type' => 'coursework_material', 'id' => 'video-only', 'title' => 'Paper 2 Solution Video', 'topic_id' => 'topic-1', 'attachments' => [['attachment_type' => 'youtube', 'title' => 'Paper 2 walkthrough', 'url' => 'https://www.youtube.com/watch?v=video']]]], ['syllabus_id' => 'syllabus-0580', 'public_title' => 'Cambridge Mathematics', 'syllabus_code' => '0580', 'board_name' => 'Cambridge', 'exam_board_id' => 'board-cambridge']);
classroom_test($warningPreview['candidates'][0]['status'] === 'WARNING' && mmh_classroom_candidate_importable($warningPreview['candidates'][0]), 'Incomplete but usable candidate was not importable as WARNING.');
classroom_test($warningPreview['candidates'][1]['status'] === 'NEEDS REVIEW' && !mmh_classroom_candidate_importable($warningPreview['candidates'][1]), 'Candidate with zero resources was not blocked.');

$scannerSource = file_get_contents(__DIR__ . '/../inc/PastPaperClassroomScanner.php');
classroom_test(str_contains((string) $scannerSource, "fields' => 'topic(topicId,name),nextPageToken'") && str_contains((string) $scannerSource, 'getTopicId()'), 'Topics list used an invalid field mask or identifier accessor.');
classroom_test(!str_contains((string) $scannerSource, 'INSERT INTO past_papers') && !str_contains((string) $scannerSource, 'INSERT INTO past_paper_resources'), 'Scanner contains Past Paper write SQL.');

echo "Past Paper Classroom scanner checks passed.\n";
