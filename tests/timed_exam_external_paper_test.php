<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/TimedExam.php';

$accepted = [
    'https://drive.google.com/file/d/abc123/view',
    'https://drive.google.com/file/d/abc123/preview?usp=sharing',
    'https://drive.google.com/open?id=abc123',
    'https://drive.google.com/uc?id=abc123',
    'https://drive.google.com/uc?export=download&id=abc123',
];
foreach ($accepted as $url) {
    $resolved = mmh_timed_exam_normalize_external_paper_url($url);
    if (!$resolved || ($resolved['file_id'] ?? '') !== 'abc123'
        || ($resolved['preview_url'] ?? '') !== 'https://drive.google.com/file/d/abc123/preview'
        || ($resolved['open_url'] ?? '') !== 'https://drive.google.com/file/d/abc123/view'
        || ($resolved['download_url'] ?? '') !== 'https://drive.google.com/uc?export=download&id=abc123') {
        throw new RuntimeException('Expected supported paper URL: ' . $url);
    }
}

foreach ([
    'http://drive.google.com/file/d/abc123/view',
    'javascript:alert(1)',
    'data:text/plain,exam',
    'file:///tmp/exam.pdf',
    'https://evil.example/exam.pdf',
    'https://example.com/not-a-paper',
    'https://drive.google.com/drive/folders/abc123',
    'https://drive.google.com/file/d/abc123/edit',
    'https://drive.google.com/file/d/not valid/view',
    'https://docs.google.com/document/d/abc123/edit',
    'https://drive.usercontent.google.com/download?id=abc123&export=download',
] as $url) {
    if (mmh_timed_exam_normalize_external_paper_url($url) !== null) {
        throw new RuntimeException('Unsupported paper URL was accepted: ' . $url);
    }
}

$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260805_add_timed_exam_external_paper.php');
if (!is_string($migration) || !str_contains($migration, 'paper_source') || !str_contains($migration, 'paper_external_preview_url')) {
    throw new RuntimeException('External paper migration is incomplete.');
}

$view = file_get_contents(dirname(__DIR__) . '/views/user/timed-exam.php');
if (!is_string($view) || !str_contains($view, "paperUrl('preview')") || !str_contains($view, "paperUrl('open')") || !str_contains($view, "paperUrl('download')")) {
    throw new RuntimeException('Timed Exam paper actions are incomplete.');
}

$paperRoute = file_get_contents(dirname(__DIR__) . '/views/user/requests/open-timed-exam-paper.php');
if (!is_string($paperRoute) || !str_contains($paperRoute, "['preview_url']") || !str_contains($paperRoute, "\$action === 'open'") || !str_contains($paperRoute, "\$action === 'download'")) {
    throw new RuntimeException('Timed Exam paper redirect route is incomplete.');
}

echo "Timed Exam external paper tests passed.\n";
