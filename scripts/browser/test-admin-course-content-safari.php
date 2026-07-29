<?php
declare(strict_types=1);

/**
 * Authenticated Safari regression guard for the shared Admin shell and Course
 * Content workspace. It deliberately accepts a caller-supplied PHP session
 * cookie; test credentials are never stored in source control.
 *
 * Required environment:
 *   MMH_ADMIN_SESSION_COOKIE=PHPSESSID=<an authenticated Admin session id>
 *   MMH_ADMIN_COURSE_ID=<existing course id with at least one section>
 * Optional:
 *   MMH_TEST_URL=http://127.0.0.1:8091
 *   SAFARI_WEBDRIVER_URL=http://127.0.0.1:4444
 */
$baseUrl = rtrim((string) (getenv('MMH_TEST_URL') ?: 'http://127.0.0.1:8091'), '/');
$driverUrl = rtrim((string) (getenv('SAFARI_WEBDRIVER_URL') ?: 'http://127.0.0.1:4444'), '/');
$cookieHeader = trim((string) getenv('MMH_ADMIN_SESSION_COOKIE'));
$courseId = trim((string) getenv('MMH_ADMIN_COURSE_ID'));

if (!preg_match('/^PHPSESSID=([^;\s]+)$/', $cookieHeader, $cookieMatch) || $courseId === '') {
    fwrite(STDERR, "SKIP: provide MMH_ADMIN_SESSION_COOKIE and MMH_ADMIN_COURSE_ID for authenticated Admin browser checks.\n");
    exit(77);
}

function adminCourseWebDriver(string $method, string $url, ?array $payload = null): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Unable to initialise Safari WebDriver request.');
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    unset($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException("Safari WebDriver HTTP {$status}: {$error}");
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (isset($decoded['value']['error'])) throw new RuntimeException((string) ($decoded['value']['message'] ?? $decoded['value']['error']));
    return $decoded;
}

function adminCourseNavigate(string $driver, string $sessionId, string $url): void
{
    adminCourseWebDriver('POST', $driver . '/session/' . rawurlencode($sessionId) . '/url', ['url' => $url]);
}

function adminCourseScript(string $driver, string $sessionId, string $script): mixed
{
    return adminCourseWebDriver('POST', $driver . '/session/' . rawurlencode($sessionId) . '/execute/async', ['script' => $script, 'args' => []])['value'];
}

$sessionId = null;
try {
    $session = adminCourseWebDriver('POST', $driverUrl . '/session', ['capabilities' => ['alwaysMatch' => ['browserName' => 'safari']]]);
    $sessionId = (string) ($session['value']['sessionId'] ?? '');
    if ($sessionId === '') throw new RuntimeException('Safari WebDriver did not return a session id.');

    adminCourseNavigate($driverUrl, $sessionId, $baseUrl . '/auth/login');
    adminCourseWebDriver('POST', $driverUrl . '/session/' . rawurlencode($sessionId) . '/cookie', [
        'cookie' => ['name' => 'PHPSESSID', 'value' => $cookieMatch[1], 'path' => '/'],
    ]);

    $sharedChecks = [];
    foreach (['dashboard', 'files', 'settings'] as $page) {
        adminCourseNavigate($driverUrl, $sessionId, $baseUrl . '/admin/' . $page . '?e2e=admin-shell');
        $result = adminCourseScript($driverUrl, $sessionId, <<<'JS'
const done = arguments[0];
const toggle = document.querySelector('[data-admin-submenu-toggle="admin-courses-submenu"]');
const submenu = document.getElementById('admin-courses-submenu');
const topNav = document.querySelector('.top-nav');
if (!toggle || !submenu || !topNav) return done({ ok:false, reason:'Shared Admin shell markup missing', url:location.href });
const initial = !submenu.hidden;
toggle.click();
requestAnimationFrame(() => {
  const expanded = !submenu.hidden && toggle.getAttribute('aria-expanded') === 'true';
  toggle.click();
  requestAnimationFrame(() => {
    const collapsed = submenu.hidden && toggle.getAttribute('aria-expanded') === 'false';
    window.scrollTo(0, Math.min(document.documentElement.scrollHeight, 800));
    requestAnimationFrame(() => {
      const top = topNav.getBoundingClientRect();
      done({ ok: expanded && collapsed && top.top >= -1 && top.bottom > 0, initial, expanded, collapsed, top, url:location.href });
    });
  });
});
JS);
        printf("%s sidebar/sticky: %s\n", $page, !empty($result['ok']) ? 'PASS' : 'FAIL');
        if (empty($result['ok'])) throw new RuntimeException('Admin shell regression on ' . $page . ': ' . json_encode($result, JSON_THROW_ON_ERROR));
        $sharedChecks[$page] = $result;
    }

    adminCourseNavigate($driverUrl, $sessionId, $baseUrl . '/admin/courses/' . rawurlencode($courseId) . '/content?e2e=course-content');
    $contentCheck = adminCourseScript($driverUrl, $sessionId, <<<'JS'
const done = arguments[0];
const waitForList = () => {
  const section = document.querySelector('.course-manager-section');
  if (!section) return setTimeout(waitForList, 80);
  const collapse = section.querySelector('[data-manager-action="toggle-section"]');
  const menuButton = section.querySelector('[data-bs-toggle="dropdown"]');
  if (!collapse || !menuButton) return done({ ok:false, reason:'Section controls missing' });
  if (!section.classList.contains('is-collapsed')) collapse.click();
  setTimeout(() => {
    menuButton.click();
    setTimeout(() => {
      const menu = section.querySelector('.dropdown-menu');
      const menuRect = menu && menu.getBoundingClientRect();
      const visible = !!menuRect && menuRect.width > 0 && menuRect.height > 0 && getComputedStyle(menu).visibility !== 'hidden';
      const unclipped = getComputedStyle(section).overflow === 'visible';
      document.dispatchEvent(new KeyboardEvent('keydown', { key:'Escape', bubbles:true }));
      setTimeout(() => done({ ok: visible && unclipped && !menu.classList.contains('show'), visible, unclipped, escaped: !menu.classList.contains('show'), menuRect }), 60);
    }, 120);
  }, 180);
};
waitForList();
JS);
    printf("course content menu: %s\n", !empty($contentCheck['ok']) ? 'PASS' : 'FAIL');
    if (empty($contentCheck['ok'])) throw new RuntimeException('Course Content action menu regression: ' . json_encode($contentCheck, JSON_THROW_ON_ERROR));

    $workflowCheck = adminCourseScript($driverUrl, $sessionId, <<<'JS'
const done = arguments[0];
const first = document.querySelector('.lesson-manager-row');
const add = document.querySelector('[data-manager-action="choose-content"]');
if (!first || !add) return done({ok:false, reason:'Course Content workflow controls missing'});
const itemId = first.dataset.itemId;
add.click();
setTimeout(() => {
  const picker = document.getElementById('course-manager-picker');
  const templates = [...document.querySelectorAll('.course-manager-template')];
  const labels = templates.map((button) => button.textContent.trim());
  const onlyTeacherTypes = templates.length === 5 && !labels.some((label) => /custom html/i.test(label));
  const firstTemplate = templates[0];
  if (!picker || picker.classList.contains('d-none') || !firstTemplate) return done({ok:false, reason:'Add Content picker did not open', labels});
  firstTemplate.click();
  setTimeout(() => {
    const form = document.querySelector('#course-manager-editor .courseBuilderItemForm');
    const consistentShell = !!form && !!form.querySelector('[name="item_title"]') && !!form.querySelector('[name="status"]') && !!form.querySelector('[name="template_type"]');
    const close = document.querySelector('#course-manager-editor [data-bs-dismiss="modal"]');
    if (close) close.click();
    fetch('/admin/courses/' + encodeURIComponent(document.querySelector('.course-manager-page').dataset.courseId) + '/content/' + encodeURIComponent(itemId) + '/preview', {credentials:'same-origin'})
      .then((response) => response.text().then((html) => ({status:response.status, html})))
      .then((result) => done({ok:onlyTeacherTypes && consistentShell && result.status === 200 && result.html.includes('course-content-preview-page'), onlyTeacherTypes, consistentShell, previewStatus:result.status, labels}))
      .catch((error) => done({ok:false, reason:String(error)}));
  }, 250);
}, 80);
JS);
    printf("course content unified shell/preview: %s\n", !empty($workflowCheck['ok']) ? 'PASS' : 'FAIL');
    if (empty($workflowCheck['ok'])) throw new RuntimeException('Course Content unified workflow regression: ' . json_encode($workflowCheck, JSON_THROW_ON_ERROR));
} finally {
    if ($sessionId !== null) {
        try { adminCourseWebDriver('DELETE', $driverUrl . '/session/' . rawurlencode($sessionId)); } catch (Throwable) {}
    }
}
