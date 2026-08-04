<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/TimedExam.php';

$accepted = [
    'https://drive.google.com/file/d/abc123/view',
    'https://drive.usercontent.google.com/download?id=abc123&export=download',
    'https://docs.google.com/document/d/abc123/edit',
    'https://docs.google.com/spreadsheets/d/abc123/edit',
    'https://docs.google.com/presentation/d/abc123/edit',
    'https://team.sharepoint.com/:b:/s/class/Eabc?e=x',
    'https://1drv.ms/u/s!abc',
    'https://cdn.mathmasteryhub.com/papers/sample.pdf',
];
foreach ($accepted as $url) {
    if (!mmh_timed_exam_normalize_external_paper_url($url)) {
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
if (!is_string($view) || !str_contains($view, 'Open Exam in New Tab') || !str_contains($view, 'paper_external_download_url')) {
    throw new RuntimeException('Timed Exam paper actions are incomplete.');
}

echo "Timed Exam external paper tests passed.\n";
