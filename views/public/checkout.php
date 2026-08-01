<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/PublicCourse.php';

$conn = db();
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings['website_name'] ?? 'Math Mastery Hub');
$route_identifier = $courseId ?? null;
if (trim((string) $route_identifier) === '') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/course/([^/]+)/checkout/?$#', $path, $matches)) $route_identifier = rawurldecode($matches[1]);
}
$requested_identifier = mmh_public_course_identifier($route_identifier);
$course = mmh_public_course_find($conn, $requested_identifier);
if (!$course) {
    http_response_code(404);
    include 'views/404.php';
    return;
}

$username = trim((string) ($_SESSION['username'] ?? ''));
$user = $username !== '' ? getUserInfo($username) : null;
$userId = (int) ($user->user_id ?? 0);
$canonicalCourseId = (string) ($course['course_id'] ?? '');
if ($canonicalCourseId === '') {
    http_response_code(404);
    include 'views/404.php';
    return;
}
if (mmh_public_course_enrolled($conn, $userId, $canonicalCourseId)) {
    header('Location: ' . rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode($canonicalCourseId), true, 303);
    exit;
}

$itemCounts = ['lessons' => 0, 'recordings' => 0, 'homework' => 0, 'files' => 0];
$items = $conn->prepare("SELECT template_type, item_type FROM course_items WHERE course_id = ? AND (status IS NULL OR status = '' OR status = 'published')");
if ($items) {
    $items->bind_param('s', $canonicalCourseId);
    $items->execute();
    $itemResult = $items->get_result();
    while ($item = $itemResult->fetch_assoc()) {
        $itemCounts['lessons']++;
        $template = strtolower(trim((string) ($item['template_type'] ?? '')));
        $type = strtolower(trim((string) ($item['item_type'] ?? '')));
        if ($template === 'recording' || $type === 'video') $itemCounts['recordings']++;
        if ($template === 'classified_assignment' || $type === 'quiz') $itemCounts['homework']++;
        if ($type === 'file') $itemCounts['files']++;
    }
    $items->close();
}

function mmh_checkout_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function mmh_checkout_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    if (str_starts_with($digits, '0')) $digits = '20' . substr($digits, 1);
    return $digits;
}
function mmh_checkout_whatsapp_url(string $number, string $courseTitle, string $paymentMethod, string $name): string
{
    $phone = mmh_checkout_phone($number);
    if ($phone === '') return '';
    $message = "Hello,\nI have paid for:\n\nCourse: {$courseTitle}\nPayment Method: {$paymentMethod}\nMy Name: {$name}\n\nPlease find my payment receipt attached.";
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode($message);
}

$courseTitle = trim((string) ($course['course_title'] ?? ''));
$teacher = trim((string) ($course['course_teacher'] ?? $course['teacher_name'] ?? '')) ?: 'Math Mastery Hub Instructor';
$description = trim(strip_tags((string) ($course['course_description'] ?? '')));
$price = trim((string) ($course['course_price'] ?? ''));
$thumbnail = ltrim((string) ($course['course_image'] ?? ''), '/');
$thumbnailUrl = mmh_site_public_url($thumbnail !== '' ? $thumbnail : 'resources/images/default/cover.png');
$checkoutUrl = mmh_checkout_escape(mmh_public_course_url($baseUrl, $course, '/checkout'));
$courseUrl = mmh_checkout_escape(mmh_public_course_url($baseUrl, $course));
$paymentEndpoint = mmh_checkout_escape(rtrim((string) $baseUrl, '/') . '/requests/course/purchase');
$whatsappNumber = trim((string) ($site_settings['whatsapp_phone'] ?? ''));
$instapayNumber = trim((string) ($site_settings['instapay_number'] ?? ''));
$vodafoneNumber = trim((string) ($site_settings['vodafone_cash_number'] ?? ''));
$studentName = trim((string) ($user->full_name ?? $user->username ?? $username));
$studentName = $studentName !== '' ? $studentName : 'Guest';
$instapayWhatsApp = mmh_checkout_whatsapp_url($whatsappNumber, $courseTitle, 'Instapay', $studentName);
$vodafoneWhatsApp = mmh_checkout_whatsapp_url($whatsappNumber, $courseTitle, 'Vodafone Cash', $studentName);
$loginUrl = rtrim((string) $baseUrl, '/') . '/auth/login?return=' . rawurlencode(mmh_public_course_url($baseUrl, $course, '/checkout'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout · <?=mmh_checkout_escape($courseTitle)?> | <?=mmh_checkout_escape($site_name)?></title>
  <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>" data-design-system="mathhub">
  <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/fontawsome5.min.css')?>">
  <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/course-checkout.css')?>">
</head>
<body class="ds-bg-primary ds-text-primary course-checkout-page">
<?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/aside.php"; ?>
<main class="course-checkout-shell">
  <nav class="course-checkout-breadcrumb" aria-label="Breadcrumb"><a href="<?=mmh_checkout_escape(rtrim((string) $baseUrl, '/') . '/courses')?>">Courses</a><span aria-hidden="true">/</span><a href="<?=$courseUrl?>"><?=mmh_checkout_escape($courseTitle)?></a><span aria-hidden="true">/</span><strong>Checkout</strong></nav>
  <div class="course-checkout-grid">
    <section class="course-checkout-summary" aria-labelledby="checkout-title">
      <div class="course-checkout-thumbnail"><img src="<?=mmh_checkout_escape($thumbnailUrl)?>" alt="<?=mmh_checkout_escape($courseTitle)?>"></div>
      <div class="course-checkout-summary-body">
        <p class="course-checkout-eyebrow">Course checkout</p>
        <h1 id="checkout-title"><?=mmh_checkout_escape($courseTitle)?></h1>
        <p class="course-checkout-teacher"><span class="fas fa-chalkboard-teacher" aria-hidden="true"></span><?=mmh_checkout_escape($teacher)?></p>
        <?php if ($description !== ''): ?><p class="course-checkout-description"><?=mmh_checkout_escape($description)?></p><?php endif; ?>
        <div class="course-checkout-price"><span><?=mmh_checkout_escape($price !== '' ? $price : '0')?> <small>EGP</small></span><a href="<?=$courseUrl?>">Back to course</a></div>
      </div>
      <div class="course-checkout-included">
        <h2>Included with your enrollment</h2>
        <ul>
          <li><span class="fas fa-layer-group" aria-hidden="true"></span><?=number_format($itemCounts['lessons'])?> lessons</li>
          <li><span class="fas fa-play-circle" aria-hidden="true"></span><?=number_format($itemCounts['recordings'])?> recordings</li>
          <li><span class="fas fa-clipboard-check" aria-hidden="true"></span><?=number_format($itemCounts['homework'])?> homework activities</li>
          <li><span class="fas fa-file-alt" aria-hidden="true"></span><?=number_format($itemCounts['files'])?> learning files</li>
          <li><span class="fas fa-check-circle" aria-hidden="true"></span>Course workspace and feedback</li>
        </ul>
      </div>
    </section>

    <section class="course-checkout-payments" aria-labelledby="payment-methods-title">
      <div class="course-checkout-payment-heading"><p class="course-checkout-eyebrow">Choose how you would like to pay</p><h2 id="payment-methods-title">Payment methods</h2><p>After a cash transfer, send the receipt in WhatsApp so the team can confirm your enrollment.</p></div>
      <article class="course-payment-card">
        <div class="course-payment-card-heading"><span class="course-payment-icon instapay"><span class="fas fa-bolt" aria-hidden="true"></span></span><div><h3>Instapay</h3><p>Transfer securely from your bank app.</p></div></div>
        <div class="course-payment-number"><span><?=mmh_checkout_escape($instapayNumber !== '' ? $instapayNumber : 'Not configured yet')?></span><?php if ($instapayNumber !== ''): ?><button type="button" class="course-copy-number" data-copy-number="<?=mmh_checkout_escape($instapayNumber)?>">Copy Number</button><?php endif; ?></div>
        <?php if ($instapayWhatsApp !== ''): ?><a class="course-payment-whatsapp" href="<?=mmh_checkout_escape($instapayWhatsApp)?>" target="_blank" rel="noopener"><span class="fab fa-whatsapp" aria-hidden="true"></span>I've Sent the Payment</a><?php else: ?><p class="course-payment-unavailable">WhatsApp payment confirmation is not configured yet.</p><?php endif; ?>
      </article>
      <article class="course-payment-card">
        <div class="course-payment-card-heading"><span class="course-payment-icon vodafone"><span class="fas fa-mobile-alt" aria-hidden="true"></span></span><div><h3>Vodafone Cash</h3><p>Send the payment from your Vodafone Cash wallet.</p></div></div>
        <div class="course-payment-number"><span><?=mmh_checkout_escape($vodafoneNumber !== '' ? $vodafoneNumber : 'Not configured yet')?></span><?php if ($vodafoneNumber !== ''): ?><button type="button" class="course-copy-number" data-copy-number="<?=mmh_checkout_escape($vodafoneNumber)?>">Copy Number</button><?php endif; ?></div>
        <?php if ($vodafoneWhatsApp !== ''): ?><a class="course-payment-whatsapp" href="<?=mmh_checkout_escape($vodafoneWhatsApp)?>" target="_blank" rel="noopener"><span class="fab fa-whatsapp" aria-hidden="true"></span>I've Sent the Payment</a><?php else: ?><p class="course-payment-unavailable">WhatsApp payment confirmation is not configured yet.</p><?php endif; ?>
      </article>
      <article class="course-payment-card course-payment-card--online">
        <div class="course-payment-card-heading"><span class="course-payment-icon online"><span class="fas fa-credit-card" aria-hidden="true"></span></span><div><h3>Pay securely by</h3><p>Visa · Mastercard · Meeza</p></div></div>
        <?php if ($username !== ''): ?><button type="button" class="course-payment-online" data-fawaterak-endpoint="<?=$paymentEndpoint?>" data-course-id="<?=mmh_checkout_escape($canonicalCourseId)?>"><span class="fas fa-lock" aria-hidden="true"></span>Pay Online</button><p class="course-payment-status" role="status" aria-live="polite"></p><?php else: ?><a class="course-payment-online" href="<?=mmh_checkout_escape($loginUrl)?>"><span class="fas fa-sign-in-alt" aria-hidden="true"></span>Log in to Pay Online</a><?php endif; ?>
      </article>
      <?php if ($username === ''): ?><p class="course-checkout-login-note">Already have an account? <a href="<?=mmh_checkout_escape($loginUrl)?>">Log in</a> before paying online. Cash payment confirmation can still be sent after you sign in.</p><?php endif; ?>
    </section>
  </div>
</main>
<?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/footer.php"; ?>
<script>
(function () {
  document.querySelectorAll('[data-copy-number]').forEach(function (button) {
    button.addEventListener('click', function () {
      var number = button.getAttribute('data-copy-number') || '';
      var done = function () { var old = button.textContent; button.textContent = 'Copied'; setTimeout(function () { button.textContent = old; }, 1400); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(number).then(done).catch(function () {});
    });
  });
  var online = document.querySelector('[data-fawaterak-endpoint]');
  if (!online) return;
  online.addEventListener('click', function () {
    var status = document.querySelector('.course-payment-status');
    online.disabled = true;
    if (status) status.textContent = 'Opening secure payment…';
    var data = new FormData();
    data.append('course_id', online.getAttribute('data-course-id') || '');
    fetch(online.getAttribute('data-fawaterak-endpoint'), { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (payload.status == 1 && payload.payment_url) { window.location.href = payload.payment_url; return; }
        throw new Error(payload.reason || payload.message || 'Unable to open secure payment.');
      })
      .catch(function (error) { online.disabled = false; if (status) status.textContent = error.message; });
  });
})();
</script>
</body>
</html>
