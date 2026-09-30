<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/CourseContentCopyService.php';

$conn = db();
$sourceCourseId = trim((string) ($courseId ?? ''));
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) ($baseUrl ?? ''), '/');
$sourceStmt = $conn->prepare('SELECT course_id, course_title, course_description, course_image, course_price, preDiscount_course_price, course_category, sequential_learning, default_homework_score_mode, course_state, archived_at FROM courses WHERE course_id = ? LIMIT 1');
if (!$sourceStmt) { http_response_code(500); exit('Unable to load the source course.'); }
$sourceStmt->bind_param('s', $sourceCourseId);
$sourceStmt->execute();
$source = $sourceStmt->get_result()->fetch_assoc();
$sourceStmt->close();
if (!$source || !empty($source['archived_at'])) { http_response_code(404); exit('Course not found.'); }

$error = '';
$titleValue = trim((string) ($_POST['session_title'] ?? ''));
$priceValue = (string) ($_POST['course_price'] ?? $source['course_price'] ?? '0');
$stateValue = strtolower(trim((string) ($_POST['course_state'] ?? 'draft')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = CourseContentCopyService::createNextSession(
            $conn,
            $sourceCourseId,
            $titleValue,
            is_numeric($priceValue) ? (float) $priceValue : -1,
            $stateValue,
            (string) ($_SESSION['admin'] ?? '')
        );
        header('Location: ' . $base . '/admin/courses/' . rawurlencode($result['course_id']) . '/content');
        exit;
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Create Next Session failed: ' . $exception->getMessage());
        $error = 'The next session could not be created. No partial course was kept; please try again.';
    }
}
$pageName = 'courses';
$subPageName = 'courses';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Next Session | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <style>
        .next-session-page{max-width:900px;margin:70px auto 0;padding:24px}.next-session-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:24px}.next-session-heading{margin-bottom:22px}.next-session-heading h1{margin:.2rem 0 .45rem}.next-session-heading p,.next-session-note{color:var(--text-muted);margin:0}.next-session-form{display:grid;gap:18px}.next-session-form label{display:grid;gap:7px;font-weight:650}.next-session-form input,.next-session-form select{max-width:620px}.next-session-state-note{color:var(--text-muted);font-size:.84rem;font-weight:400}.next-session-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}.next-session-actions .btn{min-height:40px}.next-session-cover{display:flex;align-items:center;gap:14px;margin-top:6px}.next-session-cover img{width:72px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--border)}
        @media(max-width:700px){.next-session-page{padding:14px}.next-session-card{padding:16px}.next-session-form input,.next-session-form select{max-width:none;width:100%}.next-session-actions{display:grid}.next-session-actions .btn{width:100%}}
    </style>
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <main class="next-session-page">
            <section class="next-session-card">
                <div class="next-session-heading">
                    <a class="course-manager-back-link" href="<?= $escape($base . '/admin/courses/' . rawurlencode($sourceCourseId) . '/content') ?>">← Back to course</a>
                    <div class="course-manager-eyebrow">Starting from <?= $escape($source['course_title']) ?></div>
                    <h1>Create Next Session</h1>
                    <p>The new course is an independent copy. The source course and its students stay unchanged.</p>
                </div>
                <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
                <form class="next-session-form" method="post" action="<?= $escape($base . '/admin/courses/' . rawurlencode($sourceCourseId) . '/next-session') ?>">
                    <input type="hidden" name="mmh_csrf_token" value="<?= $escape(mmh_admin_csrf_token()) ?>">
                    <label>Session name
                        <input class="form-control" type="text" name="session_title" maxlength="190" required value="<?= $escape($titleValue) ?>" placeholder="Math OL May/June 2027">
                    </label>
                    <label>Price
                        <input class="form-control" type="number" name="course_price" min="0" max="99999999" step="1" required value="<?= $escape($priceValue) ?>">
                    </label>
                    <label>Course status
                        <select class="form-control" name="course_state" required>
                            <option value="draft" <?= $stateValue === 'draft' ? 'selected' : '' ?>>Draft — Admin only</option>
                            <option value="private" <?= $stateValue === 'private' ? 'selected' : '' ?>>Private — enrolled students only</option>
                            <option value="public" <?= $stateValue === 'public' ? 'selected' : '' ?>>Public — homepage and new enrollment</option>
                        </select>
                        <span class="next-session-state-note">This uses the current Course visibility model. Existing students keep access to a Private course; Public courses are listed and open to enrollment.</span>
                    </label>
                    <div>
                        <strong>Cover image</strong>
                        <div class="next-session-cover">
                            <img src="<?= $escape(rtrim((string) $baseUrl, '/') . '/' . ltrim((string) $source['course_image'], '/')) ?>" alt="Current course cover">
                            <span class="next-session-note">Keep the current cover for now. Replace it independently from Edit Course after creation.</span>
                        </div>
                    </div>
                    <p class="next-session-note">All copied Sections and teaching content — including recordings and Homework — start as Draft/Hidden. No enrollments, progress, submissions, grades, feedback, or live schedules are copied.</p>
                    <div class="next-session-actions">
                        <button class="btn btn-primary" type="submit">Create Session</button>
                        <a class="btn btn-outline-secondary" href="<?= $escape($base . '/admin/courses/' . rawurlencode($sourceCourseId) . '/content') ?>">Cancel</a>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>
</body>
</html>
