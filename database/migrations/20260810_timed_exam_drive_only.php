<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

// Exams that already have an external paper are normalized to the canonical
// source value. Older uploaded-paper rows are deliberately left untouched so
// their metadata and files remain preserved; the application now asks an
// administrator to add a Drive link before students can open those papers.
$sql = "UPDATE timed_exams
        SET paper_source = 'external_link'
        WHERE paper_external_url IS NOT NULL
          AND paper_external_url <> ''
          AND (paper_source IS NULL OR paper_source <> 'external_link')";
if (!$conn->query($sql)) {
    throw new RuntimeException('Unable to normalize Timed Exam paper sources: ' . $conn->error);
}

echo "Timed Exam Drive-only paper migration complete. Existing uploaded files were preserved.\n";
