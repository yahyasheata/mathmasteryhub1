<?php
declare(strict_types=1);

/**
 * Safari WebDriver geometry guard for the public Free Learning page.
 *
 * Prerequisites:
 *   safaridriver -p 4444
 *   php -S 127.0.0.1:8091 router.php
 *
 * Optional environment variables:
 *   MMH_TEST_URL=http://127.0.0.1:8091
 *   SAFARI_WEBDRIVER_URL=http://127.0.0.1:4444
 *   MMH_STRICT_VIEWPORTS=1  (fail if Safari clamps a requested mobile width)
 */

$baseUrl = rtrim((string) (getenv('MMH_TEST_URL') ?: 'http://127.0.0.1:8091'), '/');
$driverUrl = rtrim((string) (getenv('SAFARI_WEBDRIVER_URL') ?: 'http://127.0.0.1:4444'), '/');
$strictViewports = filter_var(getenv('MMH_STRICT_VIEWPORTS') ?: '0', FILTER_VALIDATE_BOOL);

function freeLearningWebDriverRequest(string $method, string $url, ?array $payload = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialise cURL for Safari WebDriver.');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    unset($curl);

    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Safari WebDriver request failed: HTTP ' . $status . ($error !== '' ? ' (' . $error . ')' : ''));
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (isset($decoded['value']['error'])) {
        throw new RuntimeException('Safari WebDriver error: ' . (string) ($decoded['value']['message'] ?? $decoded['value']['error']));
    }

    return $decoded;
}

$sessionId = null;
try {
    $session = freeLearningWebDriverRequest('POST', $driverUrl . '/session', [
        'capabilities' => ['alwaysMatch' => ['browserName' => 'safari']],
    ]);
    $sessionId = (string) ($session['value']['sessionId'] ?? '');
    if ($sessionId === '') {
        throw new RuntimeException('Safari WebDriver did not return a session ID.');
    }

    freeLearningWebDriverRequest('POST', $driverUrl . '/session/' . rawurlencode($sessionId) . '/url', [
        'url' => $baseUrl . '/free-learning?e2e=safari-footer-geometry',
    ]);

    $readyScript = <<<'JS'
const done = arguments[0];
const images = [...document.images];
const imageReady = Promise.all(images.map((image) => image.complete ? Promise.resolve() : new Promise((resolve) => {
  image.addEventListener('load', resolve, { once: true });
  image.addEventListener('error', resolve, { once: true });
})));
const fontsReady = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
Promise.all([imageReady, fontsReady]).then(() => requestAnimationFrame(() => requestAnimationFrame(() => done({
  devicePixelRatio: window.devicePixelRatio || 1,
  innerWidth: window.innerWidth,
}))));
JS;
    $initial = freeLearningWebDriverRequest('POST', $driverUrl . '/session/' . rawurlencode($sessionId) . '/execute/async', [
        'script' => $readyScript,
        'args' => [],
    ]);

    $measureScript = <<<'JS'
const main = document.querySelector('main.free-learning-main');
const footer = document.querySelector('footer.public-site-footer');
const hero = document.querySelector('.free-learning-hero');
if (!main || !footer || !hero) {
  throw new Error('Required Free Learning page elements are missing.');
}
const rect = (element) => {
  const value = element.getBoundingClientRect();
  return { top: value.top, bottom: value.bottom, height: value.height, offsetHeight: element.offsetHeight, scrollHeight: element.scrollHeight };
};
const contentNodes = [...main.querySelectorAll('section, .free-learning-empty, .free-learning-resource-card, .free-learning-collection-card, .free-learning-resource-grid, .free-learning-collection-grid')]
  .filter((element) => element.getBoundingClientRect().height > 0);
const contentRects = contentNodes.map(rect);
const maxContentBottom = Math.max(rect(main).bottom, ...contentRects.map((content) => content.bottom));
return {
  viewport: window.innerWidth,
  heroVisible: hero.getBoundingClientRect().height > 0,
  footerCount: document.querySelectorAll('footer.public-site-footer').length,
  contentCount: contentNodes.length,
  contentRects,
  main: rect(main),
  footer: rect(footer),
  maxContentBottom,
  overlap: maxContentBottom - footer.getBoundingClientRect().top,
  documentBottom: document.documentElement.scrollHeight,
};
JS;

    $targets = [1440, 1024, 768, 390];
    $failures = [];
    foreach ($targets as $target) {
        $windowWidth = $target;
        freeLearningWebDriverRequest('POST', $driverUrl . '/session/' . rawurlencode($sessionId) . '/window/rect', [
            'width' => $windowWidth,
            'height' => 900,
        ]);
        $result = freeLearningWebDriverRequest('POST', $driverUrl . '/session/' . rawurlencode($sessionId) . '/execute/sync', [
            'script' => $measureScript,
            'args' => [],
        ])['value'];

        $actual = (int) ($result['viewport'] ?? 0);
        $clamped = abs($actual - $target) > 1;
        $valid = ($result['heroVisible'] ?? false)
            && ((int) ($result['footerCount'] ?? 0) === 1)
            && ((int) ($result['contentCount'] ?? 0) > 0)
            && (float) ($result['main']['height'] ?? 0) > 0
            && (float) ($result['footer']['top'] ?? 0) >= (float) ($result['maxContentBottom'] ?? 0) - 1
            && (float) ($result['documentBottom'] ?? 0) >= (float) ($result['footer']['bottom'] ?? 0) - 1;

        printf("requested=%d actual=%d content=%d overlap=%.2f %s%s\n", $target, $actual, (int) $result['contentCount'], (float) $result['overlap'], $valid ? 'PASS' : 'FAIL', $clamped ? ' (Safari window minimum/clamp)' : '');
        if (!$valid || ($strictViewports && $clamped)) {
            $failures[] = ['requested' => $target, 'actual' => $actual, 'result' => $result];
        }
    }

    if ($failures !== []) {
        throw new RuntimeException('Free Learning footer geometry regression: ' . json_encode($failures, JSON_THROW_ON_ERROR));
    }
} finally {
    if ($sessionId !== null) {
        try {
            freeLearningWebDriverRequest('DELETE', $driverUrl . '/session/' . rawurlencode($sessionId));
        } catch (Throwable) {
            // The original failure is more useful than cleanup failure.
        }
    }
}
