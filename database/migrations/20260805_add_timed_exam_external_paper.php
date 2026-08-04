<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$columns = [
    'paper_source' => "ALTER TABLE timed_exams ADD COLUMN paper_source VARCHAR(24) NOT NULL DEFAULT 'external_link' AFTER max_file_size_bytes",
    'paper_external_url' => 'ALTER TABLE timed_exams ADD COLUMN paper_external_url VARCHAR(1000) NULL AFTER paper_source',
    'paper_external_preview_url' => 'ALTER TABLE timed_exams ADD COLUMN paper_external_preview_url VARCHAR(1000) NULL AFTER paper_external_url',
    'paper_external_download_url' => 'ALTER TABLE timed_exams ADD COLUMN paper_external_download_url VARCHAR(1000) NULL AFTER paper_external_preview_url',
    'paper_fallback_instructions' => 'ALTER TABLE timed_exams ADD COLUMN paper_fallback_instructions TEXT NULL AFTER paper_external_download_url',
];

foreach ($columns as $name => $sql) {
    $check = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timed_exams' AND COLUMN_NAME = ?");
    if (!$check) throw new RuntimeException($conn->error);
    $check->bind_param('s', $name);
    $check->execute();
    $exists = (int) (($check->get_result()->fetch_row()[0] ?? 0)) > 0;
    $check->close();
    if (!$exists && !$conn->query($sql)) throw new RuntimeException($conn->error);
}

if (!$conn->query("UPDATE timed_exams SET paper_source = 'private_upload' WHERE paper_storage_key IS NOT NULL AND paper_storage_key <> '' AND (paper_external_url IS NULL OR paper_external_url = '') AND (paper_source IS NULL OR paper_source = '' OR paper_source = 'external_link')")) {
    throw new RuntimeException($conn->error);
}

echo "Timed Exam external paper migration complete.\n";
