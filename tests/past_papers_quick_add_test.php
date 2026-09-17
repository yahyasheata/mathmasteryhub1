<?php
require_once __DIR__ . '/../inc/PastPapers.php';

$cases = [
    ['May June V2 Paper 2', 'May/June', null, 'Paper 2', '2', '22'],
    ['May/June 2022 V1 P4', 'May/June', 2022, 'Paper 4', '1', '41'],
    ['June 2025 P23', 'May/June', 2025, 'Paper 2', '3', '23'],
    ['Oct Nov 2024 Paper 42', 'October/November', 2024, 'Paper 4', '2', '42'],
    ['October/November 2023 V3 Paper 4', 'October/November', 2023, 'Paper 4', '3', '43'],
    ['Feb March 2026 V2 Paper 2', 'February/March', 2026, 'Paper 2', '2', '22'],
];

foreach ($cases as [$input, $session, $year, $paper, $variant, $component]) {
    $parsed = mmh_past_parse_paper_name($input);
    if ($parsed['session'] !== $session || $parsed['year'] !== $year || $parsed['paper_number'] !== $paper || $parsed['variant'] !== $variant || $parsed['component'] !== $component) {
        fwrite(STDERR, "Parser mismatch for {$input}: " . json_encode($parsed) . PHP_EOL);
        exit(1);
    }
}

$missing = mmh_past_parse_paper_name('May June Paper 2');
if ($missing['session'] !== 'May/June' || $missing['paper_number'] !== 'Paper 2' || !in_array('year', $missing['missing'], true) || !in_array('variant', $missing['missing'], true)) {
    fwrite(STDERR, "Missing-field detection failed: " . json_encode($missing) . PHP_EOL);
    exit(1);
}

$custom = mmh_past_parse_paper_name('Spring 2024 Paper 2 V1');
if ($custom['session'] !== '' || $custom['year'] !== 2024 || $custom['paper_number'] !== 'Paper 2' || $custom['variant'] !== '1') {
    fwrite(STDERR, "Custom-session parsing failed: " . json_encode($custom) . PHP_EOL);
    exit(1);
}

echo "Past Paper quick-add parser tests passed." . PHP_EOL;
