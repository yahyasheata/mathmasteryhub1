<?php
declare(strict_types=1);

/**
 * Isolated admin diagnostic only. Keep this URL literal: the purpose of this
 * page is to compare the exact Stream URL with the student's existing embed.
 */
$sharepointStreamUrl = 'https://alexuuni-my.sharepoint.com/personal/es_yehia_shehata2024_alexu_edu_eg/_layouts/15/stream.aspx?id=%2Fpersonal%2Fes%5Fyehia%5Fshehata2024%5Falexu_edu_eg%2FDocuments%2FRecordings%2FMath%20OL%20Cambridge%20Lecture%2D20260711%5F190016%2DMeeting%20Recording%2Emp4&nav=eyJwbGF5YmFja09wdGlvbnMiOnsic3RhcnRUaW1lSW5TZWNvbmRzIjoxNTQ3LjE1MjMyNzQ3NTk4Mzh9fQ%3D%3D&referrer=StreamWebApp%2EWeb&referrerScenario=AddressBarCopied%2Eview%2E0cde6d98%2D366f%2D4685%2D9d98%2Dce1fb8f18c4a';
$escapedUrl = htmlspecialchars($sharepointStreamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SharePoint Stream Diagnostic</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background: #f5f7f8; color: #172426; }
        main { width: min(1120px, calc(100% - 32px)); margin: 32px auto 48px; }
        .card { background: #fff; border: 1px solid #dce4e5; border-radius: 12px; padding: 20px; box-shadow: 0 8px 24px rgba(23,36,38,.08); }
        h1 { margin: 0 0 8px; font-size: 1.45rem; }
        p { line-height: 1.55; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0; }
        .button { display: inline-flex; align-items: center; min-height: 42px; padding: 0 16px; border-radius: 8px; background: #f15a22; color: #fff; font-weight: 600; text-decoration: none; }
        .button.secondary { background: transparent; color: inherit; border: 1px solid #aab9bb; }
        .frame-wrap { overflow: hidden; border: 1px solid #dce4e5; border-radius: 10px; background: #101718; aspect-ratio: 16 / 9; min-height: 320px; }
        iframe { display: block; width: 100%; height: 100%; min-height: 320px; border: 0; }
        code { overflow-wrap: anywhere; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f1718; color: #f8f5ef; }
            .card { background: #172426; border-color: rgba(216,222,220,.2); }
            .button.secondary { border-color: rgba(216,222,220,.35); }
            .frame-wrap { border-color: rgba(216,222,220,.2); }
        }
    </style>
</head>
<body>
<main>
    <section class="card" aria-labelledby="diagnostic-title">
        <h1 id="diagnostic-title">SharePoint Stream diagnostic</h1>
        <p>This temporary page is restricted to administrators and tests the exact Microsoft Stream URL without passing it through the LMS resource resolver.</p>
        <div class="actions">
            <a class="button" href="<?=$escapedUrl?>" target="_blank" rel="noopener noreferrer">Open exact URL in new tab</a>
            <a class="button secondary" href="/admin/dashboard">Back to Admin</a>
        </div>
        <p><strong>Exact URL under test:</strong><br><code><?=$escapedUrl?></code></p>
        <div class="frame-wrap">
            <iframe src="<?=$escapedUrl?>" title="Microsoft Stream SharePoint diagnostic" allow="autoplay; fullscreen; encrypted-media" allowfullscreen referrerpolicy="no-referrer"></iframe>
        </div>
    </section>
</main>
</body>
</html>
