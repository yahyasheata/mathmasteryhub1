<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tokens = file_get_contents($root . '/resources/css/design-system.css');
$adminHeader = file_get_contents($root . '/views/admin/layouts/admin/header.php');
$publicHeader = file_get_contents($root . '/views/layouts/header.php');
$publicShellHeader = file_get_contents($root . '/views/public/layouts/header.php');
$studentHeader = file_get_contents($root . '/views/user/layouts/user/header.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    '--bg-primary:', '--surface:', '--surface-elevated:', '--surface-muted:', '--surface-inset:',
    '--text-primary:', '--text-secondary:', '--text-muted:', '--border:', '--border-strong:',
    '--primary-action:', '--primary-foreground:', '--secondary-action:', '--secondary-foreground:',
    '--disabled-bg:', '--disabled-text:', '--success-soft:', '--warning-soft:', '--danger-soft:', '--info-soft:',
] as $token) {
    $assert(str_contains((string) $tokens, $token), 'Shared theme token is missing: ' . $token);
}

$assert(preg_match('/html\.dark,[\s\S]*?--bg-primary:\s*#111A1C;[\s\S]*?--surface:\s*#1B2A2C;[\s\S]*?--text-primary:\s*#F6F3EB;/i', (string) $tokens) === 1, 'Dark theme does not define the intended teal-neutral surface and warm text hierarchy.');
$assert(preg_match('/\.btn-primary,[\s\S]*?background:\s*var\(--primary-action\)[\s\S]*?color:\s*var\(--primary-foreground\)/', (string) $tokens) === 1, 'Primary buttons must pair the orange action fill with its readable foreground.');
$assert(str_contains((string) $tokens, '.alert-success { background: var(--success-soft); color: var(--success);') && str_contains((string) $tokens, '.modal-content {'), 'Shared alerts or modal surface styles are missing.');
$assert(!str_contains((string) $adminHeader, '--bg-primary: #0f1718'), 'Admin shell still overrides the canonical dark surface palette.');
$assert(substr_count((string) $publicHeader, '--main-color-rgb: var(--primary-rgb);') === 2, 'Public compatibility colors do not follow the theme token.');
$assert(substr_count((string) $publicShellHeader, '--main-color-rgb: var(--primary-rgb);') === 2, 'Public shell compatibility colors do not follow the theme token.');
$assert(substr_count((string) $studentHeader, '--main-color-rgb: var(--primary-rgb);') === 2, 'Student compatibility colors do not follow the theme token.');

echo "Shared theme color contract checks passed.\n";
