<?php
declare(strict_types=1);

/**
 * Isolated admin diagnostic only. Keep this URL literal: this page compares
 * anonymous top-level access with anonymous iframe framing.
 */
$anonymousShareUrl = 'https://alexuuni-my.sharepoint.com/:v:/g/personal/es_yehia_shehata2024_alexu_edu_eg/IQAiQHosEWtMT53dhX-s-ysCARMXHL-GKGNtrptp1_qQAKU';
$escapedUrl = htmlspecialchars($anonymousShareUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SharePoint Anonymous Video Diagnostic</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background: #f5f7f8; color: #172426; }
        main { width: min(1120px, calc(100% - 32px)); margin: 32px auto 48px; }
        .card { background: #fff; border: 1px solid #dce4e5; border-radius: 12px; padding: 20px; box-shadow: 0 8px 24px rgba(23,36,38,.08); }
        h1 { margin: 0 0 8px; font-size: 1.45rem; }
        h2 { margin: 0 0 8px; font-size: 1.1rem; }
        p { line-height: 1.55; }
        .test { margin-top: 22px; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0; }
        .button { display: inline-flex; align-items: center; min-height: 42px; padding: 0 16px; border-radius: 8px; background: #f15a22; color: #fff; font-weight: 600; text-decoration: none; }
        .button.secondary { background: transparent; color: inherit; border: 1px solid #aab9bb; }
        .frame-wrap { overflow: hidden; border: 1px solid #dce4e5; border-radius: 10px; background: #101718; min-height: 420px; }
        iframe { display: block; width: 100%; height: 700px; border: 0; }
        code { overflow-wrap: anywhere; }
        @media (max-width: 700px) { iframe { height: 520px; } }
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
        <h1 id="diagnostic-title">SharePoint anonymous video diagnostic</h1>
        <p>This temporary page is restricted to administrators and compares the exact anonymous SharePoint URL inside an iframe and as a top-level page.</p>
        <div class="actions">
            <a class="button secondary" href="/admin/dashboard">Back to Admin</a>
        </div>

        <section class="test" aria-labelledby="embedded-title">
            <h2 id="embedded-title">Test A — Anonymous Share Link Embedded</h2>
            <p><strong>Exact URL under test:</strong><br><code><?=$escapedUrl?></code></p>
            <div class="frame-wrap">
                <iframe src="<?=$escapedUrl?>" title="Anonymous SharePoint video embedded diagnostic" allow="autoplay; fullscreen; encrypted-media" allowfullscreen referrerpolicy="no-referrer"></iframe>
            </div>
        </section>

        <section class="test" aria-labelledby="external-title">
            <h2 id="external-title">Test B — Anonymous Share Link External</h2>
            <p>Open the same URL as a top-level page for comparison.</p>
            <a class="button" href="<?=$escapedUrl?>" target="_blank" rel="noopener noreferrer">Open exact anonymous URL in new tab</a>
        </section>
    </section>
</main>
</body>
</html>
